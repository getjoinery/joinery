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

/// A situation: a server, a clock, and the computers attached to it.
pub struct World {
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
            clock,
            server,
            devices,
            rng: SimRng::new(seed),
            vaults: Vec::new(),
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
        let outcome = self.run_pass_on(device);
        let after = held_by(device);
        for hash in &before {
            if after.contains(hash) {
                continue;
            }
            assert!(
                locate(self, hash).is_some(),
                "{} removed content during a pass and it is now nowhere a person could look ({})",
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
        run_pass(&e, &ctx, DeletePolicy::Guard, &mut keys, &mut tokens)
    }

    /// Run passes on every device until nothing changes any more.
    ///
    /// Returns how many rounds it took, or `None` if it never settled — which
    /// is a failure worth reporting rather than a timeout to shrug at.
    pub fn settle(&self) -> Option<usize> {
        let mut quiet_rounds = 0;
        for round in 1..=MAX_PASSES {
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
pub fn server_tree(server: &MockServer) -> BTreeMap<String, Option<String>> {
    server.tree()
}

/// Every device agrees with the server, and with each other.
///
/// Panics with the difference rather than a bare false, because "they did not
/// converge" is not a useful thing to read at three in the morning.
pub fn assert_converged(world: &World) {
    let server = server_tree(&world.server);
    for device in &world.devices {
        let disk = disk_tree(device);
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
