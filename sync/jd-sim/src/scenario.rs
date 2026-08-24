//! Running a whole situation, and checking the two things that matter.
//!
//! A scenario is: some computers, some users doing things to files, a network
//! misbehaving, and processes dying at inconvenient moments. It ends by letting
//! everything settle and then asking two questions.
//!
//! **1. Did they converge?** Once the dust has settled, every device's disk and
//! the server agree about what exists and what is in it.
//!
//! **2. Was anything lost?** Every version that was ever committed is still
//! reachable — as the live file, as a version in the server's history, in a
//! trash, or as a conflict copy beside its winner.
//!
//! The second question is the one that actually matters. A sync engine that
//! fails to converge is annoying and visible; someone notices and complains. A
//! sync engine that loses a file is unforgivable and silent, and the person it
//! happens to finds out months later. So the mock server keeps every version it
//! has ever been given — it is the oracle, and it is why "was anything lost" is
//! a question with a checkable answer rather than a hope.
//!
//! ## Settling
//!
//! There is no "the sync is finished" event, so settling is a fixed point: run
//! passes until nothing changes for a whole round of every device, or give up
//! after a bound. Giving up is itself a finding — a client that never settles
//! is a client that never stops using the network.

use std::collections::BTreeMap;

use jd_core::execute::ExecEnv;
use jd_core::model::EntityId;
use jd_core::pass::run_pass;
use jd_core::reconcile::Context;
use jd_core::round::DeletePolicy;

use crate::clock::SimClock;
use crate::engine::{env, Device, SimVault};
use crate::rng::SimRng;
use crate::server::MockServer;
use crate::vfs::MemFs;

/// How many passes a device gets before a scenario calls it stuck. Generous:
/// a pass moves one round's worth of work, and a chaotic run legitimately needs
/// many of them because most attempts fail.
pub const MAX_PASSES: usize = 400;

/// What an ENCRYPTED world gets instead.
///
/// Every attempt at an encrypted upload re-encrypts the whole file and runs
/// init, chunks and completion again, so under identical faults it needs
/// materially more passes than the plaintext workload the budget above was
/// tuned for. Four hostile vault seeds out of four hundred were reported as
/// "never settled" on the smaller budget; all four settled on this one, with
/// every tree already in agreement and a single upload still retrying against
/// a transient network error.
///
/// That is the trap this constant exists to close: **running out of passes and
/// being wedged arrive as the same failure**, and telling them apart is the
/// first question worth asking about any of them. A seed that settles at a
/// larger budget was never stuck.
pub const MAX_PASSES_ENCRYPTED: usize = 2_000;

/// A situation: a server, a clock, and the computers attached to it.
pub struct World {
    /// How many passes [`World::settle`] gives the fleet before calling it
    /// stuck. `MAX_PASSES` by default; a vault world raises it to
    /// [`MAX_PASSES_ENCRYPTED`]. `SETTLE_PASSES` overrides either, which is how
    /// a "never settled" failure is told apart from a wedge in one command.
    pub settle_passes: usize,
    pub clock: SimClock,
    pub server: MockServer,
    pub devices: Vec<Device>,
    pub rng: SimRng,
    /// Every vault handed to a device in this world.
    ///
    /// Kept because the no-loss oracle needs it, not because any device does.
    /// The server stores ciphertext and the oracle counts plaintext hashes, so
    /// without a key the harness cannot tell "this version is safe on the
    /// server" from "this version is gone" — and it would answer "safe" to
    /// both, which is the failure mode that matters.
    pub vaults: Vec<SimVault>,
    /// Content the **user** destroyed while a pass was running.
    ///
    /// The custody check attributes everything that leaves a disk during a pass
    /// to the engine, and that is right exactly as long as the user only acts
    /// between passes. `user_saves_during_uploads` breaks that assumption on
    /// purpose — it is the whole point of it — so what it overwrites is
    /// recorded here and excluded from the check.
    ///
    /// This narrows the oracle by precisely one thing and it stays honest: an
    /// entry only lands here because the harness itself performed the write, so
    /// no removal the engine makes can ever reach it. A user who saves over
    /// their own unsynced draft has lost it in a real client too, and the
    /// engine did not do it.
    ///
    /// Cleared at the start of every pass, which is what keeps the exception
    /// the size of the problem. Left to accumulate it would go on excusing that
    /// hash for the rest of the run — including on another device, where the
    /// same content may still be sitting and the engine may yet lose it.
    destroyed_by_the_user: std::sync::Arc<std::sync::Mutex<std::collections::BTreeSet<String>>>,
    /// Content the user wrote **inside** a pass, in the window a download was
    /// landing in.
    ///
    /// The custody check cannot see these. It compares the disk before a pass
    /// with the disk after, and a file that is both written and built over
    /// within one pass was never in either snapshot -- so the one window where
    /// the engine can destroy the only copy of something is the one window the
    /// check is blind to. Recorded here so a scenario can demand them back.
    landing_saves: std::sync::Arc<std::sync::Mutex<std::collections::BTreeSet<String>>>,
    /// How many such saves have been made in total, across every pass.
    ///
    /// The set above is cleared each pass, so a scenario cannot use it to ask
    /// the question that keeps it honest: did the window ever actually open?
    landing_seen: std::sync::Arc<std::sync::Mutex<usize>>,
    /// How many name swaps actually fired mid-upload. A hunt that finds
    /// nothing proves nothing unless the disk really did move under the
    /// engine, so the count is readable rather than merely hoped for.
    swaps_seen: std::sync::Arc<std::sync::Mutex<usize>>,
    folder_renames_seen: std::sync::Arc<std::sync::Mutex<usize>>,
    /// How many times a device was power-cycled in this world.
    ///
    /// Counted so a sweep arm that kills can prove it killed. A knob that
    /// silently never fires makes the arm green for the wrong reason, and a
    /// suite that reports coverage it does not have is worse than one that
    /// reports none.
    power_cycles: std::sync::Arc<std::sync::Mutex<usize>>,
}

/// Which operating system's filesystem a device has.
///
/// The point of running these on Linux is that the rules are data, not
/// `#[cfg]`: a Windows-only bug is findable on the dev box, and a scenario can
/// put a Mac and a PC in one world and watch them disagree about whether two
/// files exist.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Platform {
    Linux,
    /// A modern Mac: case-insensitive, normalization-preserving.
    MacOs,
    /// A volume that decomposes — HFS+, or a network share. Kept as its own
    /// platform rather than folded into `MacOs`, because APFS has not
    /// decomposed since 2017 and pretending otherwise tests a filesystem
    /// nobody has.
    Decomposing,
    Windows,
}

impl World {
    pub fn new(seed: u64, device_names: &[&str]) -> World {
        let all: Vec<(&str, Platform)> =
            device_names.iter().map(|n| (*n, Platform::Linux)).collect();
        World::of(seed, &all)
    }

    /// A world whose devices run different operating systems.
    pub fn of(seed: u64, devices: &[(&str, Platform)]) -> World {
        let clock = SimClock::new();
        let server = MockServer::new(clock.clone());
        let devices = devices
            .iter()
            .enumerate()
            .map(|(i, (name, platform))| {
                let seed = seed ^ ((i as u64 + 1) << 8);
                let fs = match platform {
                    Platform::Linux => MemFs::linux(clock.clone()),
                    Platform::MacOs => MemFs::macos(clock.clone()),
                    Platform::Decomposing => MemFs::hfs_plus(clock.clone()),
                    Platform::Windows => MemFs::windows(clock.clone()),
                };
                Device::new(name, &server, clock.clone(), seed).with_fs(fs)
            })
            .collect();
        World {
            settle_passes: std::env::var("SETTLE_PASSES")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(MAX_PASSES),
            clock,
            server,
            devices,
            rng: SimRng::new(seed),
            vaults: Vec::new(),
            destroyed_by_the_user: Default::default(),
            landing_saves: Default::default(),
            landing_seen: Default::default(),
            swaps_seen: Default::default(),
            folder_renames_seen: Default::default(),
            power_cycles: Default::default(),
        }
    }

    /// Link one device with encrypted folders enabled.
    ///
    /// Per device, not per world, because that asymmetry is the interesting
    /// case: one laptop can read the encrypted folder and another cannot, and
    /// they have to sync everything else without either of them getting it
    /// wrong.
    pub fn give_vault(&mut self, device_name: &str, vault: &SimVault) {
        self.devices
            .iter_mut()
            .find(|d| d.name == device_name)
            .unwrap_or_else(|| panic!("no device called {device_name}"))
            .set_vault(vault);
        if !self
            .vaults
            .iter()
            .any(|v| v.public_key_b64 == vault.public_key_b64)
        {
            self.vaults.push(vault.clone());
        }
    }

    pub fn device(&self, name: &str) -> &Device {
        self.devices
            .iter()
            .find(|d| d.name == name)
            .unwrap_or_else(|| panic!("no device called {name}"))
    }

    /// Let the user carry on working while the fleet syncs.
    ///
    /// Every scenario until now moved the disk only *between* passes, which
    /// quietly assumed the one thing no client may assume: that a file holds
    /// still while it is being uploaded. It does not. Applications save by
    /// writing a temp file and renaming it over the original, so the path a
    /// client is streaming from can acquire new bytes and a new inode partway
    /// through — and an autosave on a timer makes that likely rather than rare.
    ///
    /// Arming this rewrites one file, on one device, each time an upload
    /// reaches completion: the instant the bytes belong to the server and the
    /// answer has not come back. Which file is chosen from everything on that
    /// disk, so most of the time it is a bystander and some of the time it is
    /// the file going up, which is the case worth reaching.
    ///
    /// `one_in` sets the rate — 1 fires on every completion, 4 on roughly a
    /// quarter of them. Deterministic from the world's seed like everything
    /// else, so a run that finds something replays.
    ///
    /// `budget` is how many saves the user has in them before they go to lunch,
    /// and it is not a convenience. Settling is a fixed point, and a user who
    /// never stops typing has not found a bug by preventing one — every upload
    /// would create the next edit, forever, which is a livelock the harness
    /// built rather than one the client has. The budget is what makes "and then
    /// it went quiet" a question with an answer.
    pub fn user_saves_during_uploads(&mut self, one_in: u64, budget: u64) {
        let disks: Vec<MemFs> = self.devices.iter().map(|d| d.fs.clone()).collect();
        let rng = std::sync::Arc::new(std::sync::Mutex::new(SimRng::new(
            self.rng.next_u64() ^ 0x5a5e_d17e,
        )));
        let destroyed = self.destroyed_by_the_user.clone();
        let mut round: u64 = 0;
        self.server.while_completing_an_upload(move || {
            if round >= budget {
                return;
            }
            let mut rng = rng.lock().unwrap();
            if one_in > 1 && rng.below(one_in) != 0 {
                return;
            }
            let disk = &disks[rng.below(disks.len() as u64) as usize];
            // Files only. `all_paths` lists directories too, and writing bytes
            // over one turns a folder into a file — something no user can do
            // and no filesystem will allow, so the engine below is entitled to
            // assume it never happens. Left in, it is the harness inventing a
            // world rather than simulating one.
            let files: Vec<(String, Vec<u8>)> = disk
                .all_paths()
                .into_iter()
                .filter_map(|p| disk.peek(&p).map(|bytes| (p, bytes)))
                .collect();
            if files.is_empty() {
                return;
            }
            round += 1;
            let (path, gone) = &files[rng.below(files.len() as u64) as usize];
            // Declared before it happens, because the custody check reads
            // everything that leaves a disk mid-pass as the engine's doing.
            // This is the user's doing, and saying so is what keeps the check
            // strict about the removals it is actually there to catch.
            destroyed.lock().unwrap().insert(crate::sha256_hex(gone));
            disk.user_write(path, format!("saved again while syncing, {round}").as_bytes());
        });
    }

    /// The user rearranges *names* mid-upload, rather than rewriting bytes.
    ///
    /// `user_saves_during_uploads` above covers the application that saves over
    /// a file while it is going up. This is the other half, and the soak rig
    /// reaches it constantly: a rotation, a slot swap, a Save As that shuffles
    /// two names — nothing is written and nothing is destroyed, two files
    /// simply exchange places while the engine is mid-pass.
    ///
    /// It is a harder input than a save, because a swap hands each file the
    /// other one's inode and the other one's mtime. The engine sees neither an
    /// edit nor a fresh file: it sees a rename cycle, arriving half-observed,
    /// with every fingerprint it had cached now pointing at the wrong content.
    ///
    /// Nothing may go missing, and that is a sharper claim here than anywhere
    /// else in the harness. A save legitimately destroys what it overwrote, so
    /// the content it replaced has to be excused. A swap destroys nothing —
    /// both bodies are still on the disk when it returns — so every content
    /// that was committed before it is still owed afterwards, with no excuses
    /// to make. Anything the engine drops is a loss with nowhere to hide.
    pub fn user_rearranges_names_during_uploads(&mut self, one_in: u64, budget: u64) {
        let disks: Vec<MemFs> = self.devices.iter().map(|d| d.fs.clone()).collect();
        let rng = std::sync::Arc::new(std::sync::Mutex::new(SimRng::new(
            self.rng.next_u64() ^ 0x5a1d_5eed,
        )));
        let seen = self.swaps_seen.clone();
        let mut round: u64 = 0;
        self.server.while_completing_an_upload(move || {
            if round >= budget {
                return;
            }
            let mut rng = rng.lock().unwrap();
            if one_in > 1 && rng.below(one_in) != 0 {
                return;
            }
            let disk = &disks[rng.below(disks.len() as u64) as usize];
            // Files only, for the reason the save hook gives: renaming a
            // directory over a file is not something a user can do, and the
            // engine is entitled to assume the harness will not invent it.
            let files: Vec<String> = disk
                .all_paths()
                .into_iter()
                .filter(|p| disk.peek(p).is_some())
                .collect();
            if files.len() < 2 {
                return;
            }
            let a = &files[rng.below(files.len() as u64) as usize];
            let b = &files[rng.below(files.len() as u64) as usize];
            if a == b {
                return;
            }
            round += 1;
            // Through a temp name, the way a filesystem without an atomic swap
            // forces every application to do it. The window where the first
            // name holds nothing is the whole point.
            let (a, b) = (a.clone(), b.clone());
            let parked = format!(".swap-{round}.tmp");
            disk.user_rename(&a, &parked);
            disk.user_rename(&b, &a);
            disk.user_rename(&parked, &b);
            *seen.lock().unwrap() += 1;
        });
    }

    /// The user saves the very file a download is landing on, in the window
    /// between the engine clearing the path and the file arriving there.
    ///
    /// `OsVfs` names this window in the comment on its own refusal -- "between
    /// that check and this rename the user can save a file, and under a storm
    /// they do" -- and until now nothing could reach it. The guard that closes
    /// it is the reason a download does not land on a file nobody has seen, so
    /// the guard needs a scenario that actually tests it.
    ///
    /// What the custody check then enforces is the half that matters: the bytes
    /// the user just wrote are NOT ledgered, so if the engine puts the download
    /// on top of them they vanish during the pass and the check says so. The
    /// content they replaced IS ledgered, because destroying that was the
    /// user's own doing.
    /// Move a folder's name on the SERVER in the instant a device is creating
    /// that folder on its disk.
    ///
    /// The soak rig does this constantly and the simulator never has: one
    /// machine renames a folder while another is still materialising it, so the
    /// name the second one is building arrives already superseded. Run 209 ended
    /// with two folders recorded `synced` under the server's exact name with no
    /// directory on disk, and every server-side file beneath them unreachable,
    /// while convergence passed and both devices reported themselves quiet.
    ///
    /// The rename goes through the ordinary `drive_rename` action, so the server
    /// answers exactly as it would for any other client.
    pub fn a_folder_is_renamed_while_a_device_creates_it(&mut self, one_in: u64, budget: u64) {
        let seed = self.rng.next_u64() ^ 0xf01d_5eed;
        let seen = self.folder_renames_seen.clone();
        for (i, device) in self.devices.iter().enumerate() {
            let server = self.server.clone();
            let seen = seen.clone();
            let rng = std::sync::Arc::new(std::sync::Mutex::new(SimRng::new(
                seed ^ (i as u64).wrapping_mul(0x9e37_79b9),
            )));
            let mut round: u64 = 0;
            device.fs.while_creating_a_dir(move |target| {
                if round >= budget {
                    return;
                }
                let mut rng = rng.lock().unwrap();
                if one_in > 1 && rng.below(one_in) != 0 {
                    return;
                }
                let Ok(rel) = target.strip_prefix(std::path::Path::new("/sync")) else {
                    return;
                };
                let rel = rel.to_string_lossy().to_string();
                // The folder this disk is building, as the server currently
                // names it. Absent means the server has already moved on, which
                // is its own interesting state and not one to force.
                let Some(id) = server.folder_id_at(&rel) else {
                    return;
                };
                round += 1;
                let leaf = rel.rsplit('/').next().unwrap_or("folder").to_string();
                let renamed = format!("{leaf} ({round})");
                let _ = server.action(
                    "drive_rename",
                    &serde_json::json!({ "folder_id": id, "name": renamed }),
                );
                *seen.lock().unwrap() += 1;
            });
        }
    }

    pub fn user_saves_while_downloads_land(&mut self, one_in: u64, budget: u64) {
        let destroyed = self.destroyed_by_the_user.clone();
        let landing = self.landing_saves.clone();
        let seen = self.landing_seen.clone();
        let seed = self.rng.next_u64() ^ 0x100d_5a7e;
        for (i, device) in self.devices.iter().enumerate() {
            let disk = device.fs.clone();
            let destroyed = destroyed.clone();
            let landed = landing.clone();
            let seen = seen.clone();
            let rng = std::sync::Arc::new(std::sync::Mutex::new(SimRng::new(
                seed ^ (i as u64).wrapping_mul(0x9e37_79b9),
            )));
            let mut round: u64 = 0;
            device.fs.while_a_download_lands(move |target| {
                if round >= budget {
                    return;
                }
                let mut rng = rng.lock().unwrap();
                if one_in > 1 && rng.below(one_in) != 0 {
                    return;
                }
                // The path the engine is landing on, as this disk names it.
                let Ok(rel) = target.strip_prefix(std::path::Path::new("/sync")) else {
                    return;
                };
                let rel = rel.to_string_lossy().to_string();
                // Deliberately NOT restricted to paths that still hold a file.
                // The engine has usually just moved the old one out of the way,
                // so by the time it commits the path is empty -- and that is
                // precisely the case worth testing, because a commit with no
                // agreement onto an occupied path is the one the guard refuses.
                // Requiring a file here fired the knob only on the branch that
                // was already safe, and the test passed with the guard removed.
                round += 1;
                if let Some(gone) = disk.peek(&rel) {
                    destroyed.lock().unwrap().insert(crate::sha256_hex(&gone));
                }
                let saved =
                    format!("saved while a download was landing, {round}").into_bytes();
                landed.lock().unwrap().insert(crate::sha256_hex(&saved));
                *seen.lock().unwrap() += 1;
                disk.user_write(&rel, &saved);
            });
        }
    }

    /// How many times the user has saved into a download's landing window.
    ///
    /// Whether each one survived is settled per pass, in `attempt_pass`, where
    /// the engine's removals can still be told apart from the user's. This
    /// exists so a scenario can assert the window opened at all -- a test that
    /// never enters it would otherwise pass for the wrong reason.
    pub fn saves_made_while_downloads_landed(&self) -> usize {
        *self.landing_seen.lock().unwrap()
    }

    /// How many mid-upload name swaps `user_rearranges_names_during_uploads`
    /// actually performed.
    pub fn swaps_made_during_uploads(&self) -> usize {
        *self.swaps_seen.lock().unwrap()
    }

    /// How many folders `a_folder_is_renamed_while_a_device_creates_it`
    /// actually moved. A dial that never fired makes every green meaningless.
    pub fn folder_renames_during_creation(&self) -> usize {
        *self.folder_renames_seen.lock().unwrap()
    }

    /// One pass on one device, with custody checked around it.
    ///
    /// This is where the no-loss invariant is actually enforced, and it is
    /// enforced continuously rather than at the end. Anything that was on this
    /// disk when the pass began and is not on it when the pass ends was removed
    /// **by the engine**, and must therefore still be reachable somewhere: the
    /// server, another device, a trash, a conflict copy.
    ///
    /// Checking it here rather than at the end of a scenario is what makes it
    /// mean something. A user overwriting their own file between passes is not
    /// a loss and never registers as one, because the snapshot is taken after
    /// they did it. What is left is exactly the engine's own removals.
    pub fn pass(&self, device: &Device) -> jd_core::pass::PassOutcome {
        self.attempt_pass(device).unwrap_or_default()
    }

    /// The same pass, with the failure still attached.
    ///
    /// Settling needs the difference and nothing else does. A pass that could
    /// not reach the server produces the same empty outcome as a pass that
    /// found nothing to do, and an empty outcome reports itself quiet — so two
    /// unlucky rounds in a row on a hostile network made the harness announce
    /// that everything had converged while a device was still a change behind.
    /// Three seeds sat in the ignored list for that, described as a naming race
    /// they had nothing to do with.
    ///
    /// The real client does not make this mistake — it records the error as a
    /// blocker — which is the argument for the harness not making it either.
    fn attempt_pass(
        &self,
        device: &Device,
    ) -> Result<jd_core::pass::PassOutcome, jd_core::execute::ExecError> {
        let before = held_by(device);
        self.destroyed_by_the_user.lock().unwrap().clear();
        self.landing_saves.lock().unwrap().clear();
        let outcome = self.run_pass_on(device);
        let after = held_by(device);
        let by_the_user = self.destroyed_by_the_user.lock().unwrap().clone();
        for hash in &before {
            if after.contains(hash) || by_the_user.contains(hash) {
                continue;
            }
            assert!(
                locate(self, hash).is_some(),
                "{} removed content during a pass and it is now nowhere a person could look ({})",
                device.name,
                &hash[..12]
            );
        }

        // What the user saved into a landing window during THIS pass. The two
        // snapshots above cannot see these at all: written and built over
        // inside one pass, they are in neither, which leaves the one window
        // where the engine can destroy the only copy of something as the one
        // window the custody check was blind to.
        //
        // Checked here for the same reason everything else is: inside a single
        // pass, the user wrote it and only the engine can have taken it away.
        let landed = self.landing_saves.lock().unwrap().clone();
        for hash in &landed {
            // Still here, or the user themselves wrote over it -- the other
            // chaos knob picks paths at random and can land on this one, and
            // what it overwrites is the user's to overwrite. Reading that as
            // the engine's doing blamed the client for the harness's own
            // second actor, on six seeds out of six thousand.
            if after.contains(hash) || by_the_user.contains(hash) {
                continue;
            }
            assert!(
                locate(self, hash).is_some(),
                "{} built over a file the user saved while a download was landing, \
                 and it is now nowhere a person could look ({})",
                device.name,
                &hash[..12]
            );
        }
        outcome
    }

    fn run_pass_on(
        &self,
        device: &Device,
    ) -> Result<jd_core::pass::PassOutcome, jd_core::execute::ExecError> {
        let ctx = Context {
            date: "2026-07-31".into(),
            device_name: device.name.clone(),
            conflict_suffix: 1,
            // From the device's own disk, not a constant. A scenario that gives
            // one device a Windows filesystem and another a Linux one is
            // testing exactly the disagreement this field exists for, and a
            // hardcoded personality here would quietly erase it.
            personality: jd_vfs::Vfs::personality(&device.fs),
        };
        let now = device.now();
        let e: ExecEnv = env(device, &now);
        let mut keys = device.key_source();
        let mut tokens = |id: EntityId| format!("{}-{}", device.name, id.server_id.abs());
        // The pass can be stopped dead part-way through, which is what a real
        // kill does and what nothing here could stage before. Only the death is
        // caught: an assertion that fires inside a pass is a finding, and
        // swallowing it would be the harness hiding the thing it exists to
        // show, so anything else is re-raised exactly as it was.
        let outcome = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
            run_pass(&e, &ctx, DeletePolicy::Guard, &mut keys, &mut tokens)
        }));
        match outcome {
            Ok(o) => o,
            Err(payload) => {
                let died = payload
                    .downcast_ref::<String>()
                    .map(|m| m.as_str() == crate::net::DIED)
                    .unwrap_or(false);
                if !died {
                    std::panic::resume_unwind(payload);
                }
                // The machine comes back up. Nothing is marked in flight here
                // and that is the point: the executor already recorded the one
                // op it was really running, which is exactly what a kill leaves
                // and what the between-passes kill has to approximate.
                *self.power_cycles.lock().unwrap() += 1;
                let now = device.now();
                let e = env(device, &now);
                let _ = jd_core::execute::recover(&e);
                // A pass that died is NOT a quiet pass, and the default outcome
                // is quiet -- empty plan, nothing attempted, nothing deferred.
                // Reported that way, a device could die during the very round
                // that settling was waiting on and the fleet would be declared
                // finished on the strength of it. The op it was part-way
                // through is going back on the queue, so say so.
                let mut cut_short = jd_core::pass::PassOutcome::default();
                cut_short.exec.retrying = 1;
                Ok(cut_short)
            }
        }
    }

    /// Cut the power on a device, then bring it back.
    ///
    /// A kill is not a network fault and does not behave like one. The disk and
    /// the journal survive it; everything the process was holding does not, and
    /// the work it was part-way through is left recorded as in flight with no
    /// way to know how far it got. Coming back means asking the server, which
    /// is what `recover` is for.
    ///
    /// Every op the device holds is marked in flight rather than the one that
    /// was really running, because the harness cannot know which that was and
    /// the conservative direction is the safe one: an op that had not started
    /// is asked about and found not done, which is where it already was.
    ///
    /// Worth having as something a scenario can do at any moment rather than a
    /// story one test tells. The soak rig kills a device twice a campaign, and
    /// every unexplained loss it has found landed on the device it killed --
    /// while nothing in the sweeps ever died at all.
    ///
    /// What this does not reach: the pass itself always finishes first, so the
    /// death lands between passes and no reconcile decision is ever lost
    /// half-written. A real kill can arrive inside one. Killing mid-pass needs
    /// a hook inside `run_pass`, and until there is one, a seed that survives
    /// this has been asked the easier of the two questions.
    pub fn power_cycle(&self, device: &Device) {
        for op in device.store.queued_ops().unwrap() {
            device
                .store
                .set_op_state(op.op_id, jd_core::store::OpState::InFlight)
                .unwrap();
        }
        let now = device.now();
        let e = env(device, &now);
        // A recovery that cannot reach the server is not a harness failure, and
        // unwrapping here made it one. The daemon records a blocker and carries
        // on, so this does the same -- a machine that comes back up while the
        // network is still down is an ordinary morning, not an impossible one.
        let _ = jd_core::execute::recover(&e);
        *self.power_cycles.lock().unwrap() += 1;
    }

    /// How many times a device died and came back here.
    pub fn power_cycles(&self) -> usize {
        *self.power_cycles.lock().unwrap()
    }

    /// Run passes on every device until nothing changes any more.
    ///
    /// Returns how many rounds it took, or `None` if it never settled — which
    /// is a failure worth reporting rather than a timeout to shrug at.
    pub fn settle(&self) -> Option<usize> {
        let mut quiet_rounds = 0;
        for round in 1..=self.settle_passes {
            let mut any_work = false;
            for device in &self.devices {
                // Time moves between passes so backoffs elapse. Without this a
                // scenario with any retry in it would deadlock against its own
                // exponential backoff and look like a convergence failure.
                self.clock.advance_secs(20 * 60);
                // A pass that failed is not a quiet one. It is a device that
                // has not been able to look, and calling that settled is how a
                // world converges on paper while a file is still missing.
                match self.attempt_pass(device) {
                    Ok(outcome) if outcome.quiet() => {}
                    _ => any_work = true,
                }
            }
            if any_work {
                quiet_rounds = 0;
            } else {
                quiet_rounds += 1;
                // Two quiet rounds, because one device can go quiet while
                // another is still pushing work that will wake it again.
                if quiet_rounds >= 2 {
                    return Some(round);
                }
            }
        }
        None
    }
}

// ---------------------------------------------------------------------------
// Invariant 1: convergence
// ---------------------------------------------------------------------------

/// Every content hash currently on a device's disk.
fn held_by(device: &Device) -> Vec<String> {
    device
        .fs
        .all_paths()
        .iter()
        .filter_map(|p| device.fs.peek(p))
        .map(|b| crate::sha256_hex(&b))
        .collect()
}

/// What is actually on a device's disk: path → content hash (`None` for a
/// folder). Conflict copies are included — they are real files a user can see.
pub fn disk_tree(device: &Device) -> BTreeMap<String, Option<String>> {
    let mut out = BTreeMap::new();
    for path in device.fs.all_paths() {
        if path.is_empty() || jd_vfs::is_internal(&path) {
            continue;
        }
        let hash = device.fs.peek(&path).map(|b| crate::sha256_hex(&b));
        // Composed only where the volume decomposed it in the first place, and
        // therefore only where the engine itself works in the composed spelling.
        // Reading the raw stored keys on such a volume would report it as
        // diverging from the server over a file both of them hold correctly.
        //
        // Composing everywhere is the opposite error and the more expensive one:
        // it makes a device holding a decomposed name look like it holds the
        // composed one, which is precisely the disagreement between the disk and
        // the engine's record that wedges a real client. The harness would
        // report convergence while the client renamed the file at the server
        // forever.
        let path = if jd_vfs::Vfs::personality(&device.fs).decomposes_unicode {
            jd_vfs::nfc(&path)
        } else {
            path
        };
        out.insert(path, hash);
    }
    out
}

/// The server's live tree in the same shape.
///
/// The SERVER's own view: an encrypted file appears here under the opaque title
/// it stores, with the hash of its ciphertext. That is the right answer for
/// anything asking what the server knows -- and the wrong one for anything
/// comparing it with a disk, which is what [`owner_view_of_the_server`] is for.
pub fn server_tree(server: &MockServer) -> BTreeMap<String, Option<String>> {
    server.tree()
}

/// The server's live tree as the holder of the vault key sees it.
///
/// Inside a vault the two sides of every comparison are in different languages.
/// The server stores `enc-<content id>` where the user sees `budget.xlsx`, and
/// the hash it answers with is of ciphertext that changes on every encryption
/// while the plaintext underneath does not. Comparing those directly reports
/// every encrypted file as missing from the disk AND present on the server, so
/// a convergence check run over a vault fails identically whether the engine is
/// perfect or broken -- which is to say it checks nothing.
///
/// So this translates: for each encrypted file, open its grant with a key this
/// world holds, read the real name out of the metadata blob, and decrypt the
/// current version to get a hash in the same domain as the disk's.
///
/// A file no key here opens is deliberately left under its stored name, so it
/// shows up as an unexplained `enc-...` entry the disks do not have rather than
/// quietly disappearing from the comparison. A vault whose key is gone is a
/// loss, and the check should say so rather than excuse it.
pub fn owner_view_of_the_server(world: &World) -> BTreeMap<String, Option<String>> {
    let mut out = server_tree(&world.server);
    for f in world.server.vault_files() {
        let Some((name, hash)) = open_as_the_owner(world, &f) else {
            continue;
        };
        let stored = join_path(&f.folder_path, &f.placeholder);
        out.remove(&stored);
        // Two vault files CAN decrypt to one path -- see
        // `vault_names_the_server_cannot_tell_apart` -- and this map cannot
        // hold both. Whichever lands last wins, so the view alone must never be
        // used to decide anything; the collision is reported separately, first,
        // and by name.
        out.insert(join_path(&f.folder_path, &name), hash);
    }
    out
}

/// Real names the server is holding twice in one folder.
///
/// Outside a vault this cannot happen: the server refuses a name a live sibling
/// already holds. Inside one it cannot even be asked -- uniqueness is enforced
/// on the stored title, and an encrypted file's title is an opaque per-file id,
/// unique by construction. So two files whose real names are both `notes.txt`
/// sit in one folder and the server sees nothing wrong.
///
/// Reported before the trees, because it is the CAUSE and the trees are only
/// where it surfaces. A device can put one file at one path, so it materializes
/// one and parks the other; the comparison then finds the disk holding one
/// file's bytes where the view happens to show the other's, which reads as an
/// unexplained content mismatch and sends you looking at transfers.
pub fn vault_names_the_server_cannot_tell_apart(world: &World) -> Vec<String> {
    let mut seen: BTreeMap<String, i64> = BTreeMap::new();
    let mut clashes = Vec::new();
    for f in world.server.vault_files() {
        let Some((name, _)) = open_as_the_owner(world, &f) else {
            continue;
        };
        let path = join_path(&f.folder_path, &name);
        match seen.get(&path) {
            Some(first) => clashes.push(format!("{path} is held by both file {first} and file {}", f.id)),
            None => {
                seen.insert(path, f.id);
            }
        }
    }
    clashes
}

fn join_path(folder: &str, leaf: &str) -> String {
    if folder.is_empty() {
        leaf.to_string()
    } else {
        format!("{folder}/{leaf}")
    }
}

/// One encrypted file's real name and plaintext hash, if any key in this world
/// opens it.
///
/// The hash is `None` when the grant and the metadata open but the bytes do not
/// -- a file whose key is right and whose content is not, which is a different
/// failure from an unreadable file and deserves to read as one.
/// What one encrypted file really is, for a diagnostic: the name and the
/// content id its metadata blob carries.
///
/// The content id is the one that matters and the one nothing else shows. It is
/// bound into every chunk as authenticated data, so a device holding a
/// different one from the blob's decrypts nothing and says only "decryption
/// failed" -- which reads as corruption and is not.
pub fn what_the_vault_really_holds(
    world: &World,
    f: &crate::server::VaultFile,
) -> Option<(String, String)> {
    let wrapped = f.wrapped_file_key.as_deref()?;
    let blob = f.encrypted_metadata.as_deref()?;
    for vault in &world.vaults {
        let Ok(file_key) = jd_crypto::drive::open_wrapped_file_key(
            wrapped,
            &vault.secret_key_pkcs8,
            &vault.public_key_b64,
        ) else {
            continue;
        };
        if let Ok(meta) = jd_crypto::drive::decrypt_metadata(blob, &file_key) {
            return Some((meta.name, meta.cid));
        }
    }
    None
}

/// Every encrypted file the server is holding that its owner cannot read.
///
/// The encrypted counterpart of the no-loss oracle, and it asks the only
/// question that matters about a vault: is what came back the file? A grant
/// that opens and metadata that decrypts prove nothing about the CONTENT --
/// those travel in the completion body while the bytes go up in chunks, so a
/// chunk corrupted on the way leaves a file with a perfect name, a perfect key,
/// and ciphertext that authenticates against nothing.
///
/// Such a file is lost in the only sense that counts. It occupies a name, it
/// lists, every device agrees it is there, and no device will ever open it --
/// each one reporting, for ever, that decryption failed.
pub fn vault_content_that_will_not_open(world: &World) -> Vec<String> {
    let mut bad = Vec::new();
    for f in world.server.vault_files() {
        let Some((name, cid)) = what_the_vault_really_holds(world, &f) else {
            // No key in this world opens the grant at all. A different problem
            // -- and an ordinary state for a device that was never granted one
            // -- so it is not this check's business.
            continue;
        };
        let opened = f.wrapped_file_key.as_deref().and_then(|wrapped| {
            world.vaults.iter().find_map(|vault| {
                let key = jd_crypto::drive::open_wrapped_file_key(
                    wrapped,
                    &vault.secret_key_pkcs8,
                    &vault.public_key_b64,
                )
                .ok()?;
                let bytes = f.ciphertext.as_ref()?;
                jd_crypto::drive::decrypt_content(bytes, &key, &cid).ok()
            })
        });
        if opened.is_none() {
            bad.push(format!("file {} ({name:?}) does not decrypt", f.id));
        }
    }
    bad
}

/// No device without a key is holding ciphertext on its disk.
///
/// The one thing a keyless device must never do. Ciphertext written to disk is
/// worse than nothing arriving: it lands under the server's placeholder name,
/// nothing on that machine can open it, backup software copies it, and the user
/// has a file they cannot read and cannot explain. Absence is the correct
/// behaviour and the engine says so by name, per file.
pub fn assert_no_ciphertext_on_a_keyless_disk(world: &World) {
    let stored: std::collections::BTreeSet<String> = world
        .server
        .vault_files()
        .iter()
        .filter_map(|f| f.ciphertext.as_ref().map(|c| crate::sha256_hex(c)))
        .collect();
    let mut problems = Vec::new();
    for device in &world.devices {
        if device.vault().is_some() {
            continue;
        }
        for path in device.fs.all_paths() {
            let Some(bytes) = device.fs.peek(&path) else {
                continue;
            };
            if stored.contains(&crate::sha256_hex(&bytes)) {
                problems.push(format!("{} holds ciphertext at {path}", device.name));
            }
        }
    }
    assert!(
        problems.is_empty(),
        "bytes only the server was ever meant to see reached a disk that cannot open them: {problems:?}"
    );
}

/// The vault holds nothing its owner cannot read.
pub fn assert_the_vault_opens(world: &World) {
    let bad = vault_content_that_will_not_open(world);
    assert!(
        bad.is_empty(),
        "the server is holding encrypted content no key in this world can open: {bad:?}"
    );
}

fn open_as_the_owner(
    world: &World,
    f: &crate::server::VaultFile,
) -> Option<(String, Option<String>)> {
    let wrapped = f.wrapped_file_key.as_deref()?;
    let blob = f.encrypted_metadata.as_deref()?;
    for vault in &world.vaults {
        let Ok(file_key) = jd_crypto::drive::open_wrapped_file_key(
            wrapped,
            &vault.secret_key_pkcs8,
            &vault.public_key_b64,
        ) else {
            continue;
        };
        let Ok(meta) = jd_crypto::drive::decrypt_metadata(blob, &file_key) else {
            continue;
        };
        let hash = f
            .ciphertext
            .as_ref()
            .and_then(|c| jd_crypto::drive::decrypt_content(c, &file_key, &meta.cid).ok())
            .map(|plain| crate::sha256_hex(&plain));
        return Some((meta.name, hash));
    }
    None
}

/// Every device agrees with the server, and with each other.
///
/// Panics with the difference rather than a bare false, because "they did not
/// converge" is not a useful thing to read at three in the morning.
pub fn assert_converged(world: &World) {
    // Before the trees, because a stale record is usually the CAUSE of a tree
    // that does not match and the tree is only where it surfaces. Reported the
    // other way round, every one of these reads as an unexplained missing file
    // and sends you looking at transfers.
    assert_records_agree_with_the_server(world);
    let clashes = vault_names_the_server_cannot_tell_apart(world);
    assert!(
        clashes.is_empty(),
        "the server is holding one real name twice inside a vault, which no device can \
         put at one path: {clashes:?}"
    );
    let server = owner_view_of_the_server(world);
    // What each encrypted file really contains, by server id. The exemption
    // below matches a parked entry by CONTENT, and an encrypted entry's own
    // recorded hash is of the ciphertext while the view it is held against is
    // in the plaintext domain. Comparing across the two never matches, so every
    // legitimately parked encrypted file read as one the device simply did not
    // have -- a convergence failure whose stated cause (a missing file) had
    // nothing to do with its real one (a name this disk cannot hold twice).
    let plain_by_id: BTreeMap<i64, String> = world
        .server
        .vault_files()
        .iter()
        .filter_map(|f| {
            open_as_the_owner(world, f)
                .and_then(|(_, hash)| hash)
                .map(|hash| (f.id, hash))
        })
        .collect();
    for device in &world.devices {
        let disk = disk_tree(device);
        // A volume that rewrites the spelling of a name holds one file where
        // two spellings exist, and `disk_tree` has already folded its side to
        // the composed form to say so. The server's side has to be folded the
        // same way, or every decomposed name the server was legitimately given
        // reads as a file the device is missing -- while the device is in fact
        // holding it, under the only spelling that volume has.
        //
        // What this cannot settle is two names on the server that one volume
        // genuinely cannot tell apart. That is the naming layer's decision, it
        // is made by parking one of them, and it is what `platforms.rs` is for;
        // the no-loss oracle still covers the content either way.
        // Content this device has told the user it will not be holding. An
        // entry parked `Unsyncable` is a resting place, not a failure to
        // converge -- the name cannot exist on this filesystem, the device says
        // so by name, and expecting the tree to contain it anyway is asking the
        // device to do the impossible. `PendingKey` is the same bargain for a
        // different reason.
        //
        // Matched on content rather than on path, because the two sides of the
        // difference are spelt differently by construction: the server holds the
        // name the device refused, and the device may hold the same bytes under
        // a conflict-copy name it chose when the slot was taken.
        let declined: std::collections::HashSet<String> = device
            .store
            .every_entry()
            .unwrap()
            .iter()
            .filter(|e| {
                matches!(
                    e.status,
                    jd_core::model::LocalStatus::Unsyncable(_)
                        | jd_core::model::LocalStatus::PendingKey
                )
            })
            .filter_map(|e| {
                if e.is_encrypted {
                    plain_by_id.get(&e.id.server_id).cloned()
                } else {
                    e.remote_content.as_ref().map(|c| c.sha256.clone())
                }
            })
            .collect();
        let held_back = |h: &Option<String>| h.as_ref().is_some_and(|h| declined.contains(h));
        let disk: BTreeMap<String, Option<String>> =
            disk.into_iter().filter(|(_, h)| !held_back(h)).collect();

        // A device with no key materializes no vault folder and nothing under
        // one, on purpose -- see `MockServer::vault_folder_paths`. Holding it to
        // a tree that contains them asks it to produce files it cannot read and
        // a folder it must not create. The content-based exemption above cannot
        // cover this on its own: a FOLDER has no content to match on.
        //
        // Dropped from BOTH sides, because the traffic runs both ways. The vault
        // is invisible on that machine, so nothing stops the user making a
        // folder of the same name and saving into it — and those files are
        // local-only for ever, since the device can neither encrypt them nor
        // send them in the clear into a folder the user believes is private. It
        // says so per file (`PendingKey`); expecting the server to have them is
        // asking it to commit the leak instead.
        //
        // What this must NOT excuse is ciphertext reaching that disk, which is
        // the one thing that would be seriously wrong — and
        // `assert_no_ciphertext_on_a_keyless_disk` checks it separately, by
        // content, so nothing here can hide it.
        let (disk, server): (BTreeMap<String, Option<String>>, BTreeMap<String, Option<String>>) =
            if device.vault().is_none() {
                let vaults = world.server.vault_folder_paths();
                let outside = |path: &String| {
                    !vaults
                        .iter()
                        .any(|v| path == v || path.starts_with(&format!("{v}/")))
                };
                (
                    disk.into_iter().filter(|(p, _)| outside(p)).collect(),
                    server
                        .iter()
                        .filter(|(p, _)| outside(p))
                        .map(|(p, h)| (p.clone(), h.clone()))
                        .collect(),
                )
            } else {
                (disk, server.clone())
            };

        let server = if jd_vfs::Vfs::personality(&device.fs).decomposes_unicode {
            server
                .iter()
                .filter(|(_, h)| !held_back(h))
                .map(|(p, h)| (jd_vfs::nfc(p), h.clone()))
                .collect()
        } else {
            server
                .iter()
                .filter(|(_, h)| !held_back(h))
                .map(|(p, h)| (p.clone(), h.clone()))
                .collect()
        };
        if disk != server {
            let only_disk: Vec<_> = disk.keys().filter(|k| !server.contains_key(*k)).collect();
            let only_server: Vec<_> = server.keys().filter(|k| !disk.contains_key(*k)).collect();
            let differing: Vec<_> = disk
                .iter()
                .filter(|(k, v)| server.get(*k).map(|s| s != *v).unwrap_or(false))
                .map(|(k, _)| k)
                .collect();
            panic!(
                "{} did not converge with the server\n  only on the disk: {only_disk:?}\n  only on the server: {only_server:?}\n  same path, different content: {differing:?}",
                device.name
            );
        }
    }
}

/// Every settled record says where its file actually is.
///
/// The trees can match while the bookkeeping behind them does not, and that is
/// not a cosmetic difference. The last-agreed placement is what the next scan
/// searches and what naming counts as this entry's claim on a name, so a record
/// left pointing at a folder its file has left holds a name nothing is using.
/// The file that legitimately wants that name is then treated as a duplicate of
/// something that is not there and parked for good — and because both trees
/// still agree at the moment it happens, every other invariant here passes.
///
/// Checked only once a device has settled, where a difference can no longer be
/// work in progress.
pub fn assert_records_agree_with_the_server(world: &World) {
    for device in &world.devices {
        let mut stale = Vec::new();
        for e in device.store.every_entry().unwrap() {
            if e.remote_deleted || e.id.is_provisional() {
                continue;
            }
            // Resting places, not agreements: nothing on this machine is going
            // to move these, and both are reported to the user by name.
            if matches!(
                e.status,
                jd_core::model::LocalStatus::Unsyncable(_)
                    | jd_core::model::LocalStatus::PendingKey
                    | jd_core::model::LocalStatus::OutOfScope
            ) {
                continue;
            }
            if let Some(agreed) = &e.synced_placement {
                if *agreed != e.remote {
                    stale.push(format!(
                        "{:?} {} is recorded at {:?}/{:?} but the server has it at {:?}/{:?}",
                        e.id.entity_type,
                        e.id.server_id,
                        agreed.parent,
                        agreed.name,
                        e.remote.parent,
                        e.remote.name
                    ));
                }
            }
        }
        assert!(
            stale.is_empty(),
            "{} agrees with the server about the tree but not about where things are, \
             so it is holding names it is not using: {stale:?}",
            device.name
        );
    }
}

// ---------------------------------------------------------------------------
// Invariant 2: nothing committed is ever lost
// ---------------------------------------------------------------------------

/// Every content hash a scenario ever deliberately created.
///
/// A scenario records what it wrote here. Anything in this set has to still be
/// findable somewhere at the end — that is the whole promise.
#[derive(Debug, Default, Clone)]
pub struct Committed {
    pub hashes: Vec<(String, String)>,
}

impl Committed {
    /// Note that a user deliberately created this content at this path.
    pub fn note(&mut self, path: &str, bytes: &[u8]) {
        let hash = crate::sha256_hex(bytes);
        if !self.hashes.iter().any(|(h, _)| *h == hash) {
            self.hashes.push((hash, path.to_string()));
        }
    }
}

/// Where a piece of content was found. Any of these counts as not lost — a user
/// can reach all of them.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FoundIn {
    LiveOnServer,
    ServerHistory,
    OnADisk,
    InATrash,
}

/// Look for one hash everywhere it could legitimately be.
pub fn locate(world: &World, hash: &str) -> Option<FoundIn> {
    // The server's version history is the oracle: it is never pruned, so
    // anything that was ever committed is in it.
    if world.server.all_versions().iter().any(|v| v.sha256 == hash) {
        return Some(FoundIn::ServerHistory);
    }
    if world.server.blob(hash).is_some() {
        return Some(FoundIn::LiveOnServer);
    }
    // The same two places again, for content the server cannot read. Its
    // history is kept in ciphertext, so a plaintext hash is only findable in it
    // by actually opening every version with a key from this world.
    for vault in &world.vaults {
        for held in world.server.encrypted_contents() {
            let Ok(file_key) = jd_crypto::drive::open_wrapped_file_key(
                &held.wrapped_file_key,
                &vault.secret_key_pkcs8,
                &vault.public_key_b64,
            ) else {
                continue;
            };
            for ciphertext in &held.ciphertexts {
                if let Ok(plain) =
                    jd_crypto::drive::decrypt_content(ciphertext, &file_key, &held.content_id)
                {
                    if crate::sha256_hex(&plain) == hash {
                        return Some(FoundIn::ServerHistory);
                    }
                }
            }
        }
    }
    for device in &world.devices {
        for path in device.fs.all_paths() {
            if device
                .fs
                .peek(&path)
                .map(|b| crate::sha256_hex(&b))
                .as_deref()
                == Some(hash)
            {
                return Some(FoundIn::OnADisk);
            }
        }
        for (_, bytes) in device.fs.trashed() {
            if bytes.map(|b| crate::sha256_hex(&b)).as_deref() == Some(hash) {
                return Some(FoundIn::InATrash);
            }
        }
    }
    None
}

/// Nothing a user committed has disappeared.
pub fn assert_nothing_lost(world: &World, committed: &Committed) {
    let mut lost = Vec::new();
    for (hash, path) in &committed.hashes {
        if locate(world, hash).is_none() {
            lost.push(format!("{path} ({})", &hash[..12]));
        }
    }
    assert!(
        lost.is_empty(),
        "content that a user committed is gone from everywhere a person could look: {lost:?}"
    );
}

/// Both invariants, which is what every scenario ends with.
pub fn assert_invariants(world: &World, committed: &Committed) {
    // Order matters for readability: a loss is the more serious finding, so it
    // is the one reported first when both fail.
    assert_nothing_lost(world, committed);
    assert_converged(world);
    assert_no_entry_is_stranded(world);
    assert_no_live_orphan_on_the_server(world);
}

/// The server may not hold a live item under a folder that is in its trash.
///
/// The counterpart to [`assert_no_entry_is_stranded`], one level up: that one
/// catches a client losing track of a parent, this one catches the server
/// accepting a placement into a folder that is no longer a place. It is the
/// same race either way — one device puts something into a folder while another
/// deletes it — and the server taking the write is what makes it permanent.
///
/// Checked after every scenario for the same reason as its sibling: nothing
/// visible goes wrong. Every device reports itself busy, the item is never
/// listed, and only counting rows finds it.
pub fn assert_no_live_orphan_on_the_server(world: &World) {
    let orphans = world.server.live_orphans();
    assert!(
        orphans.is_empty(),
        "the server is holding live items under trashed folders, where no \
         listing will ever show them and no client can place them: {orphans:?}"
    );
}

/// No entry may name a parent that is not in the store.
///
/// A pass finds work by resolving each entry to a path, and a path is built by
/// following parents up to the root. An entry whose parent has gone has no
/// path, so nothing is ever planned for it and nothing is ever raised about it:
/// it sits in `pending_upload` for as long as the client runs. A soak run ended
/// with thirty-two files exactly there, and every device reported itself busy
/// rather than broken.
///
/// Checked after every scenario because the state is cheap to detect and
/// impossible to notice from the outside — which is the combination that makes
/// an invariant worth having.
pub fn assert_no_entry_is_stranded(world: &World) {
    for device in &world.devices {
        let entries = device.store.every_entry().unwrap();
        let known: std::collections::HashSet<i64> = entries
            .iter()
            .filter(|e| e.id.entity_type == jd_core::EntityType::Folder)
            .map(|e| e.id.server_id)
            .collect();
        let stranded: Vec<_> = entries
            .iter()
            .filter_map(|e| e.local_placement().parent.map(|p| (e, p)))
            .filter(|(_, p)| !known.contains(p))
            .map(|(e, p)| format!("{} (parent {p} is not in the store)", e.remote.name))
            .collect();
        assert!(
            stranded.is_empty(),
            "{} has entries with no way back to the root, so no pass will ever \
             consider them: {stranded:?}",
            device.name
        );
    }
}
