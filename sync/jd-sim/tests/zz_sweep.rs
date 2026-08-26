//! A random workload, swept over thousands of seeds.
//!
//! `scenarios.rs` holds stories somebody wrote down. This holds the search that
//! finds the ones nobody thought of: a generator that does what the soak rig's
//! personas do — nested folders renamed while work lands inside them,
//! safe-save, folder moves and deletes, duplicated files, two devices reaching
//! for one name, composed and decomposed spellings, case twins — driven over a
//! seed range until something fails.
//!
//! **Every test here is `#[ignore]`d and none runs in the ordinary suite.** A
//! sweep is minutes to hours, and its value is not regression cover: a range
//! that has been swept and fixed is green by construction, so only seeds
//! nothing has run before find anything. Run one by name:
//!
//! ```text
//! cargo test -p jd-sim --test zz_sweep scratch_rich_sweep -- --ignored --nocapture
//! ```
//!
//! The sweeps, by the surface they cover:
//!
//! - `scratch_rich_sweep`, `scratch_night_sweep`, `scratch_dawn_sweep` — Linux,
//!   two and three devices, clean and hostile networks, short and long runs.
//! - `scratch_platform_sweep` — the same workload where the computers disagree
//!   about what a name is: mac+pc, linux+mac, mac+HFS+, and a four-way run.
//!   Case twins and accented spellings only actually collide here; on Linux the
//!   generator has always produced them and they have always been distinct
//!   files.
//!
//! - `scratch_vault_sweep` — the same workload run entirely inside an encrypted
//!   folder every device can open, so every name is a secret, every byte that
//!   leaves is ciphertext, and the hash the server answers with is in a
//!   different domain from the one on disk.
//! - `scratch_vault_platform_sweep` — a vault where the computers disagree
//!   about what a name is. Naming inside a vault is decided entirely on the
//!   client, because the server is holding an opaque id and cannot be a second
//!   opinion about a name it has never seen.
//! - `scratch_vault_one_key_sweep` — a vault only ONE device can open, with the
//!   others working around it. `VAULT=2` on the diagnostics reproduces it;
//!   `VAULT=1` is the everyone-has-the-key shape.
//! - `scratch_vault_fresh_sweep` — vault seeds nothing has run.
//!
//! - `scratch_kill_sweep` — machines that die with work still on their
//!   lists, plaintext and vault, clean and hostile. `KILLS=1` on the
//!   diagnostics reproduces one.
//! - `scratch_kill_platform_sweep` — the same, on disks that disagree about
//!   what a name is, because re-running an op after a kill is where the two
//!   dimensions meet.
//!
//! And the tools for the seed a sweep hands back. All take `SEED`, `STEPS`,
//! `DEVS`, `CHAOS`, `VAULT`, and — for a platform failure — `PLATFORMS` and
//! `NAMES`:
//!
//! - `scratch_one` — run that seed alone and let it panic.
//! - `scratch_dump` — the end state: server tree, every disk, every entry,
//!   every queued op.
//! - `scratch_trace` — `WATCH=<server id>` prints one entry's agreement on
//!   every device after each pass, and `OPS=1` adds each device's plan and exec
//!   report. A dump cannot explain a bug that is about a *transition*; this can.
//!
//! **`NAMES` matters as much as `PLATFORMS`.** A device's name goes into the
//! conflict-copy names it writes, so reproducing a sweep failure under
//! different names reproduces a different run and a different answer.

use jd_sim::net::NetFaults;
use jd_sim::rng::SimRng;
use jd_sim::scenario::{
    assert_converged, assert_no_entry_is_stranded, assert_no_live_orphan_on_the_server,
    assert_nothing_lost, Committed, Platform, World,
};

fn workload(seed: u64, steps: usize, devices: &[&str], chaos: bool) {
    let named: Vec<(&str, Platform)> = devices.iter().map(|n| (*n, Platform::Linux)).collect();
    workload_on(seed, steps, &named, chaos)
}

/// The same workload, on computers that need not all be Linux.
///
/// The generator has always produced composed and decomposed spellings of one
/// word and case twins of one name, because those are what break naming — and
/// on Linux every one of them is simply a different file, so the shapes were
/// generated and never actually collided. A Mac folds the case pair into one
/// slot and an HFS+ volume rewrites the spelling on the way to disk, which is
/// where those names cost something.
/// The world a sweep seed runs in.
///
/// Every diagnostic here builds its world through this, and that is the whole
/// point of it existing: a dump that skipped the chaos hooks reproduced a
/// DIFFERENT run under the same seed, showed a converged tree, and sent an
/// afternoon looking for a defect in the wrong place. A tool that disagrees
/// with the sweep is worse than no tool.
/// The vault folder a vault sweep runs its workload in.
const VAULT_ROOT: &str = "Private";

/// How a sweep's world relates to encryption.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Vault {
    /// No vault at all: the plaintext workload the sweeps have always run.
    None,
    /// One vault, every device holding its key, and the WHOLE workload inside
    /// it -- so every name is a secret and every byte that leaves is ciphertext.
    Shared,
    /// One vault, only the first device holding its key. The others work around
    /// it, and must sync everything outside it perfectly while materializing
    /// nothing inside it.
    ///
    /// This is the asymmetry the soak rig's `no-ciphertext` invariant was
    /// written for and has never once been able to check, because the rig has
    /// no encrypted lane. It is also the only shape that exercises what a
    /// device does with an entry it can see, cannot open, and must not guess
    /// about -- while the tree around it is being renamed, moved and deleted.
    OneKeyHolder,
}

impl Vault {
    fn any(self) -> bool {
        self != Vault::None
    }
}

/// Where a sweep's workload hangs off.
///
/// A shared vault puts everything inside it. A mixed world cannot: the devices
/// with no key have to be able to work, so the workload runs at the root and
/// the key holder reaches into the vault on its own.
fn sweep_root(vault: Vault) -> &'static str {
    if vault == Vault::Shared {
        VAULT_ROOT
    } else {
        ""
    }
}

fn sweep_world(
    seed: u64,
    devices: &[(&str, Platform)],
    steps: usize,
    chaos: bool,
    vault: Vault,
) -> World {
    let mut world = World::of(seed, devices);
    // One vault, every device holding its key, and the whole workload run
    // inside it. Encryption had only ever been tested by hand-written stories
    // that seed, settle, write, settle -- so every encrypted file the engine
    // had ever handled arrived in a calm world and stayed where it was put.
    // None of them was ever renamed while an upload was retrying, moved into a
    // folder another device was deleting, or reached for by two computers at
    // once. That is the whole of what the rig does, and `no-ciphertext` came
    // back `vacuous` on every segment of it because no encrypted entity existed
    // to check.
    //
    // Every device gets the key rather than some, because the asymmetry
    // (one laptop can read the vault, another cannot) already has scenarios of
    // its own; what has never been swept is the ordinary case where they all
    // can and the world is hostile.
    if vault.any() {
        // An encrypted attempt re-encrypts the whole file and runs the upload
        // again, so the fleet needs more passes to get through the same faults.
        // An explicit SETTLE_PASSES still wins, so a failure here can be told
        // apart from a wedge without editing anything.
        if std::env::var("SETTLE_PASSES").is_err() {
            world.settle_passes = jd_sim::scenario::MAX_PASSES_ENCRYPTED;
        }
        let key = jd_sim::SimVault::new(seed);
        let names: Vec<String> = world.devices.iter().map(|d| d.name.clone()).collect();
        // Every device, or only the first. The others are linked accounts that
        // never went through the ceremony -- an ordinary state, not a fault.
        let holders = match vault {
            Vault::OneKeyHolder => &names[..1],
            _ => &names[..],
        };
        for name in holders {
            world.give_vault(name, &key);
        }
        world
            .server
            .set_vault_public_key(jd_sim::server::OWNER_USER_ID, &key.public_key_b64);
        world.server.seed_encrypted_folder(None, VAULT_ROOT);
        // Materialized before the workload starts, on a network that still
        // works. The generator writes straight to paths inside it, and a device
        // that had not yet learned the folder was a vault would make an
        // ordinary directory of that name and send its contents up in the
        // clear -- testing the harness's mistake instead of the engine.
        assert!(
            world.settle().is_some(),
            "seed {seed}: the vault folder should arrive before the workload starts"
        );
    }
    // A chaotic world is one where the user is still working, not just one
    // where the network is bad. Uploads finishing against a file that has moved
    // on is the ordinary consequence of an autosave, and it reaches a branch of
    // the client no network fault can. The budget lets the user stop, so the
    // run still has a fixed point to settle to.
    if chaos {
        world.user_saves_during_uploads(8, (steps / 4).max(2) as u64);
        // The other half of "the user is still working": saving the very file a
        // download is putting down, in the window between the engine clearing
        // the path and the file arriving on it. Those bytes are the only copy
        // of themselves and the custody check cannot see them -- written and
        // built over inside one pass, they are in neither of its snapshots --
        // so they are demanded back by hash below.
        if std::env::var("NOLAND").is_err() {
            world.user_saves_while_downloads_land(1, (steps / 4).max(2) as u64);
        }
        // The third way a user moves the disk under a pass, and the one the
        // soak rig does constantly: not writing bytes at all, just exchanging
        // two names. A swap hands each file the other one's inode and the other
        // one's mtime, so it reaches the engine as a rename cycle rather than
        // an edit, with every fingerprint it cached now pointing at the wrong
        // content.
        // NOSWAP=1 turns just this dial off, to test whether a swap CAUSED a
        // wedge rather than merely coinciding with one.
        if std::env::var("NOSWAP").is_err() {
            world.user_rearranges_names_during_uploads(1, (steps / 4).max(2) as u64);
        }
        // One machine renaming a folder while another is still building it. The
        // rig does this constantly -- its folder names accumulate suffixes all
        // run -- and until now the simulator never had: every sweep materialised
        // folders whose names stood still while it worked.
        if std::env::var("NOFOLDER").is_err() {
            world.a_folder_is_renamed_while_a_device_creates_it(1, (steps / 4).max(2) as u64);
        }
        // Some of these worlds are served by a box whose refusals say only what
        // went wrong in English, with no marker naming the KIND of refusal.
        // Every refusal the mock sends carries one and so does every refusal the
        // current platform sends, which quietly made "the client can always tell
        // a refused name from a refusal it can do nothing about" an assumption
        // no seed could break. It is not true of a server a version behind, of
        // one endpoint that was missed, or of any refusal invented after this
        // client shipped -- and the soak rig runs against exactly such a box.
        // NOPLAIN=1 turns just this dial off.
        if std::env::var("NOPLAIN").is_err() && seed % 4 == 0 {
            world.server.refuses_without_saying_why(true);
        }
        // And a real disk hands a deleted file's inode straight back to the
        // next file that wants one. Until now every sweep ran on a world where
        // an inode, once used, was never seen again -- which quietly excused
        // the engine from ever being wrong about a recycled one. The soak rig
        // has no such manners.
        for device in &world.devices {
            device.fs.reuse_file_ids(true);
        }
    }
    world
}

fn workload_on(seed: u64, steps: usize, devices: &[(&str, Platform)], chaos: bool) {
    let _ = workload_core(seed, steps, devices, chaos, Vault::None, false);
}

fn workload_core(
    seed: u64,
    steps: usize,
    devices: &[(&str, Platform)],
    chaos: bool,
    vault: Vault,
    kills: bool,
) -> usize {
    let root = sweep_root(vault);
    let world = sweep_world(seed, devices, steps, chaos, vault);
    let committed = Committed::default();
    drive(&world, seed, steps, chaos, root, vault, kills);
    // Counted before settling, because settling is where a seed panics and a
    // panicking seed never reports anything.
    let kills_made = world.power_cycles();
    if std::env::var("DIALS").is_ok() {
        eprintln!(
            "DIALS seed {seed}: swaps={} landing_saves={} folder_renames={} kills={}",
            world.swaps_made_during_uploads(),
            world.saves_made_while_downloads_landed(),
            world.folder_renames_during_creation(),
            kills_made
        );
    }
    if std::env::var("OPS").is_ok() {
        println!("  KILLS {kills_made}");
    }
    if world.settle().is_none() {
        let mut lines = Vec::new();
        for d in &world.devices {
            for op in d.store.queued_ops().unwrap() {
                lines.push(format!(
                    "{} op {} {:?} {} {} attempts={} err={:?}",
                    d.name,
                    op.op_id,
                    op.kind,
                    op.entity.server_id,
                    op.params,
                    op.attempts,
                    op.last_error
                ));
            }
        }
        panic!("seed {seed} never settled\n{}", lines.join("\n"));
    }
    assert_nothing_lost(&world, &committed);
    assert_converged(&world);
    // The other two invariants the scenario suite checks. The sweep ran without
    // them for its whole life, so the two shapes they catch -- an entry whose
    // parent chain no longer reaches the root, and a live item on the server
    // under a trashed folder -- had thousands of seeds to hide in and never had
    // to survive one.
    assert_no_entry_is_stranded(&world);
    assert_no_live_orphan_on_the_server(&world);
    if vault.any() {
        assert_nothing_in_the_vault_is_readable(&world, seed);
        jd_sim::scenario::assert_the_vault_opens(&world);
        jd_sim::scenario::assert_no_ciphertext_on_a_keyless_disk(&world);
    }
    if vault == Vault::Shared {
        // Only meaningful when everything is inside the vault. In a mixed world
        // most names are plaintext by design, and checking them against the
        // server would flag the workload's own ordinary files.
        assert_the_server_was_never_told_a_real_name(&world, root, seed);
    }
    kills_made
}

/// Nothing under the vault is stored where the server can read it.
///
/// A file is flagged encrypted from the folder it is uploaded into, so this
/// failing means something reached a vault by a route that did not ask: a
/// client that decided a folder was ordinary, or a server path that carried an
/// item across the boundary without converting it. Neither shows up in a tree.
fn assert_nothing_in_the_vault_is_readable(world: &World, seed: u64) {
    let readable = world.server.plaintext_inside_a_vault();
    assert!(
        readable.is_empty(),
        "seed {seed}: the server holds these in the clear inside a vault: {readable:?}"
    );
}

/// The server never learned what anything in the vault is called.
///
/// An encrypted file's real name lives inside its metadata blob and its stored
/// title is an opaque placeholder forever. Folder names inside a vault are
/// plaintext by design -- only files are checked here. The names come off the
/// disks rather than a list the generator kept, so conflict copies and every
/// name a device invented for itself are covered too.
fn assert_the_server_was_never_told_a_real_name(world: &World, root: &str, seed: u64) {
    let mut secret: std::collections::BTreeSet<String> = Default::default();
    for d in &world.devices {
        for (path, hash) in jd_sim::scenario::disk_tree(d) {
            if hash.is_none() || !path.starts_with(root) {
                continue;
            }
            secret.insert(path.rsplit('/').next().unwrap_or_default().to_string());
        }
    }
    let told: Vec<String> = jd_sim::scenario::server_tree(&world.server)
        .into_iter()
        .filter(|(_, hash)| hash.is_some())
        .map(|(path, _)| path)
        .filter(|path| secret.contains(path.rsplit('/').next().unwrap_or_default()))
        .collect();
    assert!(
        told.is_empty(),
        "seed {seed}: the server was told the real name of {told:?}"
    );
}

/// Print one entry's agreement on every device, plus the disk paths that mention
/// it, whenever TRACE is set. WATCH is a server id.
fn trace(world: &World, tag: &str) {
    let Ok(watch) = std::env::var("WATCH") else {
        return;
    };
    let watch: i64 = watch.parse().unwrap();
    let needle = std::env::var("NEEDLE").unwrap_or_else(|_| "contested".into());
    println!("--- {tag}");
    for d in &world.devices {
        for e in d.store.every_entry().unwrap() {
            if e.id.server_id != watch {
                continue;
            }
            println!(
                "  {} entry {watch}: remote={:?}/{:?} synced={:?}/{:?} status={:?} deleted={}",
                d.name,
                e.remote.parent,
                e.remote.name,
                e.synced_placement.as_ref().map(|p| p.parent),
                e.synced_placement.as_ref().map(|p| p.name.clone()),
                e.status,
                e.remote_deleted,
            );
        }
        let paths: Vec<String> = jd_sim::scenario::disk_tree(d)
            .into_iter()
            .map(|(p, _)| p)
            .filter(|p| p.contains(&needle))
            .collect();
        println!("  {} disk: {:?}", d.name, paths);
    }
    let server: Vec<String> = jd_sim::scenario::server_tree(&world.server)
        .into_iter()
        .map(|(p, _)| p)
        .filter(|p| p.contains(&needle))
        .collect();
    println!("  server: {server:?}");
}

/// `root` is the folder the whole workload hangs off -- empty for the drive
/// root, or a vault folder, in which case every path the generator invents is
/// inside it and every byte that leaves a device has to be encrypted first.
fn drive(
    world: &World,
    seed: u64,
    steps: usize,
    chaos: bool,
    root: &str,
    vault: Vault,
    kills: bool,
) {
    let mut rng = SimRng::new(seed ^ 0x5EED_1234);
    if chaos {
        for d in &world.devices {
            d.net.set_faults(NetFaults::chaos());
        }
    }

    // Names that stress the parts the engine finds hard.
    let leaf = |i: usize| -> String {
        match i % 5 {
            0 => format!("doc-{i}.txt"),
            1 => format!("caf\u{e9}-{i}.txt"),          // composed
            2 => format!("cafe\u{301}-{i}.txt"),        // decomposed, same word
            3 => format!("Report {i}.docx"),
            _ => format!("DOC-{i}.TXT"),                // case twin of arm 0
        }
    };

    let mut files: Vec<String> = Vec::new();
    let mut dirs: Vec<String> = vec![root.to_string()];
    // What was last written where, so a "copy" arm can produce a second file
    // with byte-identical content. Duplicate content is its own shape: pairing
    // hunts by hash, and two files that hash the same are exactly where that
    // goes wrong.
    let mut bodies: std::collections::HashMap<String, Vec<u8>> =
        std::collections::HashMap::new();

    for step in 0..steps {
        let device = &world.devices[rng.below(world.devices.len() as u64) as usize];
        // In a mixed world the key holder reaches into the vault now and then,
        // and everyone else works around it. A device with no key never writes
        // there -- it cannot even see the folder, so a workload that made it
        // try would be testing the harness's mistake rather than the engine.
        // Half the key holder's steps, which is what it takes for the vault to
        // hold enough to be interesting: at a third it produced about one
        // encrypted file per run, and a workload that barely reaches the thing
        // it is testing is a green run that proves nothing.
        let into_the_vault = vault == Vault::OneKeyHolder
            && device.vault().is_some()
            && rng.below(2) == 0;
        let join = |d: &str, n: &str| -> String {
            if d.is_empty() {
                n.to_string()
            } else {
                format!("{d}/{n}")
            }
        };

        // Sometimes the machine dies with work still on its list.
        //
        // A kill is not a network fault and does not behave like one: the disk
        // and the journal survive, and every op the device was part-way through
        // comes back recorded as in flight with nobody left who knows how far
        // it got. Whether repeating one is safe is a question only the server
        // can answer, and asking it is a different code path from anything a
        // dropped packet reaches.
        //
        // A roll of its own rather than a rider on the sync arm. Hung off that
        // arm it inherited the arm's one-in-twenty and fired on barely a third
        // of seeds -- the arm was green because most of its seeds never died at
        // all, which is exactly what the counter below is here to catch.
        //
        // Guarded on `kills` first, and that ordering is the point: the draw is
        // never made in an arm that does not kill, so every existing seed keeps
        // the run it always had.
        if kills && rng.below(8) == 0 {
            world.clock.advance_secs(20 * 60);
            if rng.below(2) == 0 {
                // Dead in the middle of the pass, at a call the server has
                // already acted on. The executor has recorded that one op as in
                // flight and nothing else; whether repeating it is safe is a
                // question only the server can answer.
                device.net.arm_death(rng.below(6));
                world.pass(device);
            } else {
                // Dead between passes, with a queue still on the list.
                world.pass(device);
                world.power_cycle(device);
            }
            trace(world, &format!("step {step} kill on {}", device.name));
        }

        match rng.below(20) {
            // Create a file, sometimes deep.
            0..=2 => {
                let dir = if into_the_vault {
                    VAULT_ROOT.to_string()
                } else {
                    rng.pick(&dirs).cloned().unwrap_or_default()
                };
                let path = join(&dir, &leaf(step));
                let body = format!("body {step} {}", device.name).into_bytes();
                device.fs.user_write(&path, &body);
                bodies.insert(path.clone(), body);
                files.push(path);
            }
            // Edit.
            3..=4 => {
                if let Some(p) = rng.pick(&files).cloned() {
                    if device.fs.exists(&p) {
                        let body = format!("edit {step} {}", device.name).into_bytes();
                        device.fs.user_write(&p, &body);
                        bodies.insert(p, body);
                    }
                }
            }
            // Safe-save: write a temporary beside it, then rename it over.
            5 => {
                if let Some(p) = rng.pick(&files).cloned() {
                    if device.fs.exists(&p) {
                        let tmp = format!("{p}.tmp{step}");
                        device
                            .fs
                            .user_write(&tmp, format!("saved {step}").as_bytes());
                        device.fs.user_rename(&tmp, &p);
                    }
                }
            }
            // Rename a file, keeping it in place.
            6 => {
                if let Some(p) = rng.pick(&files).cloned() {
                    if device.fs.exists(&p) {
                        let dir = p.rsplit_once('/').map(|(d, _)| d).unwrap_or("");
                        let to = join(dir, &leaf(step + 1));
                        device.fs.user_rename(&p, &to);
                        files.push(to);
                    }
                }
            }
            // Move a file into another folder.
            7 => {
                if let (Some(p), Some(dir)) = (rng.pick(&files).cloned(), rng.pick(&dirs).cloned()) {
                    // Never across the edge of the vault. The server refuses an
                    // in-place crossing and the client cannot yet make one for
                    // itself (docs/drive_sync.md), so generating one would test
                    // a feature nobody has written rather than this one.
                    if !same_side_of_the_vault(&p, &dir) {
                        continue;
                    }
                    if device.fs.exists(&p) {
                        let name = p.rsplit('/').next().unwrap_or("x").to_string();
                        let to = join(&dir, &name);
                        if to != p && !device.fs.exists(&to) {
                            device.fs.user_rename(&p, &to);
                            files.push(to);
                        }
                    }
                }
            }
            // Delete a file.
            8 => {
                if let Some(p) = rng.pick(&files).cloned() {
                    if device.fs.exists(&p) {
                        device.fs.user_remove(&p);
                    }
                }
            }
            // Make a folder, sometimes nested inside an existing one.
            9..=10 => {
                let parent = if into_the_vault {
                    VAULT_ROOT.to_string()
                } else {
                    rng.pick(&dirs).cloned().unwrap_or_default()
                };
                let path = join(&parent, &format!("Sub {step}"));
                device.fs.user_mkdir(&path);
                dirs.push(path);
            }
            // Rename a folder — with whatever is inside it.
            11 => {
                let candidates: Vec<String> =
                    dirs.iter().filter(|d| is_movable(d, root)).cloned().collect();
                if let Some(d) = rng.pick(&candidates).cloned() {
                    if device.fs.exists(&d) {
                        let parent = d.rsplit_once('/').map(|(p, _)| p).unwrap_or("");
                        let to = join(parent, &format!("Sub {step} renamed"));
                        device.fs.user_rename(&d, &to);
                        dirs.push(to);
                    }
                }
            }
            // Exchange two names, or rotate three, over a small fixed set that
            // BOTH devices work on. Copied from the rig's name-swapper persona,
            // which the generator had no equivalent of at all.
            //
            // The shared set is the point, not the swap. A→B and B→A cannot both
            // be applied in either order without a temporary name, and two
            // devices doing that to the same three names at once is what
            // actually produces the interesting states — conflict copies landing
            // on top of a cycle that is still half-applied. Swapping two of a
            // device's own random files, which is what this did first, almost
            // never has the other device touching the same pair, and fifteen
            // hundred seeds of it found nothing.
            13 => {
                let base = join(root, "Shared");
                let slots = [
                    join(&base, "slot-1.dat"),
                    join(&base, "slot-2.dat"),
                    join(&base, "slot-3.dat"),
                ];
                if !device.fs.exists(&slots[0]) {
                    device.fs.user_mkdir(&base);
                    for (n, s) in slots.iter().enumerate() {
                        device
                            .fs
                            .user_write(s, format!("slot {n} from {}", device.name).as_bytes());
                        files.push(s.clone());
                    }
                } else if rng.below(3) == 0 {
                    // Three-way rotation: a→tmp, b→a, c→b, tmp→c. The same trap
                    // with a longer cycle, which is where an implementation that
                    // special-cased pairs falls over.
                    let via = join(&base, &format!(".rotate-{step}.tmp"));
                    device.fs.user_rename(&slots[0], &via);
                    device.fs.user_rename(&slots[1], &slots[0]);
                    device.fs.user_rename(&slots[2], &slots[1]);
                    device.fs.user_rename(&via, &slots[2]);
                } else {
                    let i = rng.below(3) as usize;
                    let j = (i + 1 + rng.below(2) as usize) % 3;
                    let via = join(&base, &format!(".swap-{step}.tmp"));
                    device.fs.user_rename(&slots[i], &via);
                    device.fs.user_rename(&slots[j], &slots[i]);
                    device.fs.user_rename(&via, &slots[j]);
                }
            }
            // Both devices reach for the same name at once.
            12 => {
                let dir = rng.pick(&dirs).cloned().unwrap_or_default();
                let path = join(&dir, "contested.txt");
                for d in &world.devices {
                    d.fs.user_write(&path, format!("{} at {step}", d.name).as_bytes());
                }
                files.push(path);
            }
            // Move a whole folder into another folder, with everything in it.
            //
            // The generator only ever RENAMED a folder in place, and a subtree
            // arriving somewhere else is a different shape: every path below it
            // changes at once, on one side only, while work may still be
            // landing inside it. The rig produces this constantly -- its worst
            // cycle had one device holding a moved folder's contents while the
            // other held the same names where the folder used to be.
            14 => {
                let movable: Vec<String> =
                    dirs.iter().filter(|d| is_movable(d, root)).cloned().collect();
                if let (Some(d), Some(into)) =
                    (rng.pick(&movable).cloned(), rng.pick(&dirs).cloned())
                {
                    if !same_side_of_the_vault(&d, &into) {
                        continue;
                    }
                    let name = d.rsplit('/').next().unwrap_or("x").to_string();
                    let to = join(&into, &name);
                    // Not into itself and not into its own descendant, which no
                    // filesystem allows and which would only test the mock.
                    let inside = into == d || into.starts_with(&format!("{d}/"));
                    if !inside && to != d && device.fs.exists(&d) && !device.fs.exists(&to) {
                        device.fs.user_rename(&d, &to);
                        dirs.push(to);
                    }
                }
            }
            // Delete a folder with whatever is inside it.
            15 => {
                let removable: Vec<String> =
                    dirs.iter().filter(|d| is_movable(d, root)).cloned().collect();
                if let Some(d) = rng.pick(&removable).cloned() {
                    if device.fs.exists(&d) {
                        device.fs.user_remove(&d);
                    }
                }
            }
            // Duplicate a file: same bytes, different name. What a person does
            // before editing something they might want back.
            16 => {
                if let Some(p) = rng.pick(&files).cloned() {
                    if let Some(body) = bodies.get(&p).cloned() {
                        if device.fs.exists(&p) {
                            let dir = rng.pick(&dirs).cloned().unwrap_or_default();
                            let name = p.rsplit('/').next().unwrap_or("x");
                            let to = join(&dir, &format!("Copy of {name}"));
                            if !device.fs.exists(&to) {
                                device.fs.user_write(&to, &body);
                                bodies.insert(to.clone(), body);
                                files.push(to);
                            }
                        }
                    }
                }
            }
            // Rename a folder twice running, then write through the name it
            // started with. An application that kept a path rather than a
            // handle -- an editor with a document open, a build tool with a
            // configured output directory -- notices neither rename, and saving
            // rebuilds every directory on the way. So the original name is
            // standing again, as an unrelated new folder, moments after the
            // engine agreed the folder had moved off it.
            //
            // Straight from the rig, which produced this on its own and then
            // held the resulting disagreement for the rest of the campaign: the
            // device with the subtree under the new name, the server still
            // calling it by the old one, and both sides reporting themselves
            // settled. The generator could reach the shape only by accident --
            // it renames a random folder once -- so it was worth naming.
            17 => {
                let candidates: Vec<String> =
                    dirs.iter().filter(|d| is_movable(d, root)).cloned().collect();
                if let Some(original) = rng.pick(&candidates).cloned() {
                    if device.fs.exists(&original) {
                        let parent = original.rsplit_once('/').map(|(p, _)| p).unwrap_or("");
                        let name = original.rsplit('/').next().unwrap_or("x").to_string();
                        let once = join(parent, &format!("{name} ({step})"));
                        let twice = join(parent, &format!("{name} ({step}) ({step}b)"));
                        device.fs.user_rename(&original, &once);
                        device.fs.user_rename(&once, &twice);
                        dirs.push(once);
                        dirs.push(twice);
                        // The stale save. Deliberately not guarded on the path
                        // being absent -- the whole point is that the writer
                        // does not look.
                        let stale = join(&original, &format!("stale-{step}.txt"));
                        let body = format!("written through a path that moved {step}").into_bytes();
                        device.fs.user_write(&stale, &body);
                        bodies.insert(stale.clone(), body);
                        files.push(stale);
                        dirs.push(original);
                    }
                }
            }
            // One folder BOTH devices put things in, which either of them may
            // delete out from under the other.
            //
            // Arm 15 deletes a folder too, but it picks from this device's own
            // list, so the other device is usually nowhere near it. A shared
            // name is what makes the delete land while somebody else's write is
            // in flight — and that is the shape the rig produced twice, three
            // days apart: a file created inside a folder the server had trashed
            // a hundred milliseconds earlier, live and reachable by nothing.
            // It is also the only workload that makes a device meet a
            // `parent_trashed` refusal at all, which is the answer a device
            // once blamed on the wrong folder entirely.
            18 => {
                let shared = join(root, "Contested Folder");
                if !device.fs.exists(&shared) {
                    device.fs.user_mkdir(&shared);
                    dirs.push(shared.clone());
                }
                if rng.below(4) == 0 {
                    device.fs.user_remove(&shared);
                } else {
                    let path = join(&shared, &format!("in-{step}-{}.txt", device.name));
                    let body = format!("into a folder that may be going {step}").into_bytes();
                    device.fs.user_write(&path, &body);
                    bodies.insert(path.clone(), body);
                    files.push(path);
                }
            }
            // Let it sync.
            _ => {
                world.clock.advance_secs(20 * 60);
                world.pass(device);
                trace(world, &format!("step {step} pass on {}", device.name));
            }
        }
    }
}

/// Are these two paths on the same side of the vault's edge?
///
/// Encryption is a property of where a thing lives, and the server cannot
/// change it -- it holds no key, so it cannot convert bytes. A move across that
/// edge is therefore refused, and the crossing is done by re-uploading at the
/// destination and trashing the source, which the client does not do yet. Until
/// it does, a workload that generates one is testing the absence of a feature.
fn same_side_of_the_vault(a: &str, b: &str) -> bool {
    let inside = |p: &str| p == VAULT_ROOT || p.starts_with(&format!("{VAULT_ROOT}/"));
    inside(a) == inside(b)
}

/// A folder the workload may move or delete. The root it all hangs off is not
/// one: deleting it takes the whole world with it, and for a vault sweep it
/// would leave the rest of the run with no vault to test.
fn is_movable(dir: &str, root: &str) -> bool {
    !dir.is_empty() && dir != root
}

fn sweep(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[&str],
    chaos: bool,
) -> Vec<(String, u64)> {
    let mixed: Vec<(&str, Platform)> = devices.iter().map(|n| (*n, Platform::Linux)).collect();
    sweep_on(label, seeds, steps, &mixed, chaos)
}

/// Run every seed in the range and report which ones failed.
///
/// Returns them rather than only printing them. Printing alone made the test
/// itself always pass — a whole estate could be failing and `cargo test` would
/// say `ok`, with the real answer discarded unless somebody remembered
/// `--nocapture`. Each sweep test collects these and asserts the list is empty,
/// so the verdict is the verdict.
#[must_use]
fn sweep_on(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[(&str, Platform)],
    chaos: bool,
) -> Vec<(String, u64)> {
    sweep_core(label, seeds, steps, devices, chaos, Vault::None, false)
}

/// The same sweep with the whole workload inside a vault every device can open.
#[must_use]
fn sweep_vault(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[&str],
    chaos: bool,
) -> Vec<(String, u64)> {
    let named: Vec<(&str, Platform)> = devices.iter().map(|n| (*n, Platform::Linux)).collect();
    sweep_core(label, seeds, steps, &named, chaos, Vault::Shared, false)
}

/// A vault only the first device can open, with the rest working around it.
#[must_use]
fn sweep_vault_one_key(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[&str],
    chaos: bool,
) -> Vec<(String, u64)> {
    let named: Vec<(&str, Platform)> = devices.iter().map(|n| (*n, Platform::Linux)).collect();
    sweep_core(label, seeds, steps, &named, chaos, Vault::OneKeyHolder, false)
}

/// A vault workload on computers that disagree about what a name is.
#[must_use]
fn sweep_vault_on(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[(&str, Platform)],
    chaos: bool,
) -> Vec<(String, u64)> {
    sweep_core(label, seeds, steps, devices, chaos, Vault::Shared, false)
}

/// The same sweep on machines that die with work still on their lists.
///
/// Its own dimension rather than part of `chaos`, for two reasons. A kill
/// reaches recovery, which no network fault does -- an op comes back recorded
/// as in flight with nobody left who knows how far it got, and only the server
/// can say whether repeating it is safe. And folding it into the existing arms
/// would change what every seed in them means, so a suite that is a regression
/// suite by construction would stop being one.
#[must_use]
fn sweep_killing(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[&str],
    chaos: bool,
    vault: Vault,
) -> Vec<(String, u64)> {
    let named: Vec<(&str, Platform)> = devices.iter().map(|n| (*n, Platform::Linux)).collect();
    sweep_core(label, seeds, steps, &named, chaos, vault, true)
}

/// The same, on computers that disagree about what a name is.
///
/// A kill is answered by re-running the op, and what an op does with a name is
/// decided by the disk it lands on: one machine folds case, another decomposes
/// the accent, and neither agrees with what the plan was written against. Every
/// killing arm above runs on one kind of disk, where a re-run always lands the
/// same way it would have the first time.
#[must_use]
fn sweep_killing_on(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[(&str, Platform)],
    chaos: bool,
    vault: Vault,
) -> Vec<(String, u64)> {
    sweep_core(label, seeds, steps, devices, chaos, vault, true)
}

#[must_use]
fn sweep_core(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[(&str, Platform)],
    chaos: bool,
    vault: Vault,
    kills: bool,
) -> Vec<(String, u64)> {
    let total = (seeds.end - seeds.start) as usize;
    let mut failures = Vec::new();
    let mut kills_made = 0usize;
    for seed in seeds {
        let owned: Vec<(String, Platform)> =
            devices.iter().map(|(n, p)| (n.to_string(), *p)).collect();
        let r = std::panic::catch_unwind(|| {
            let refs: Vec<(&str, Platform)> =
                owned.iter().map(|(n, p)| (n.as_str(), *p)).collect();
            workload_core(seed, steps, &refs, chaos, vault, kills)
        });
        match r {
            Ok(made) => kills_made += made,
            Err(_) => failures.push(seed),
        }
    }
    // An arm that kills has to be able to say it killed. Asserted here rather
    // than per seed: whether any one seed draws a kill is the seed's business,
    // and demanding it of every one turned a fifty-to-one draw into a failed
    // seed and buried the real findings among them.
    assert!(
        !kills || kills_made > 0,
        "SWEEP {label}: the killing arm never killed anything"
    );
    let killed = if kills {
        format!(" ({kills_made} kills)")
    } else {
        String::new()
    };
    println!(
        "SWEEP {label}: {} of {total} failed: {:?}{killed}",
        failures.len(),
        failures
    );
    failures.into_iter().map(|s| (label.to_string(), s)).collect()
}

/// The reporting guard itself, because getting this wrong is silent.
///
/// A sweep collects failures instead of panicking on the first one, so that a
/// run reports every bad seed rather than the earliest. For its whole life that
/// meant the sweep only PRINTED its verdict: `cargo test` said `ok` with seeds
/// failing, and the real answer was thrown away unless somebody remembered
/// `--nocapture`. A fifty-minute run was spent learning that.
#[test]
fn a_sweep_with_a_failing_seed_fails_the_test() {
    assert!(std::panic::catch_unwind(|| no_seed_failed(vec![vec![], vec![]])).is_ok());
    let boom = std::panic::catch_unwind(|| {
        no_seed_failed(vec![vec![("longhostile-3dev".to_string(), 61140)]])
    });
    assert!(boom.is_err(), "a failing seed has to fail the test");
}

/// Fail the test if any seed in any arm failed, naming them all.
fn no_seed_failed(arms: Vec<Vec<(String, u64)>>) {
    let failed: Vec<(String, u64)> = arms.into_iter().flatten().collect();
    assert!(
        failed.is_empty(),
        "{} seed(s) failed: {}",
        failed.len(),
        failed
            .iter()
            .map(|(l, s)| format!("{l}/{s}"))
            .collect::<Vec<_>>()
            .join(" ")
    );
}

#[test]
#[ignore] // 3000+ seeds; run it by name, not as part of the workspace suite
fn scratch_rich_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep("orig-clean-2dev", 1000..1200, 40, &["laptop", "desktop"], false));
    arms.push(sweep("orig-hostile-2dev", 1200..1400, 30, &["laptop", "desktop"], true));
    arms.push(sweep("orig-clean-3dev", 1400..1520, 40, &["a", "b", "c"], false));
    arms.push(sweep("wide-clean-2dev", 20000..20600, 40, &["laptop", "desktop"], false));
    arms.push(sweep("wide-hostile-2dev", 21000..21600, 30, &["laptop", "desktop"], true));
    arms.push(sweep("wide-clean-3dev", 22000..22400, 40, &["a", "b", "c"], false));
    arms.push(sweep("wide-hostile-3dev", 23000..23400, 30, &["a", "b", "c"], true));
    arms.push(sweep("wide-long-3dev", 24000..24200, 90, &["a", "b", "c"], false));
    arms.push(sweep("fresh-long-2dev", 9000..9150, 80, &["laptop", "desktop"], false));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// A world for the diagnostic tools, honouring PLATFORMS when it is set so a
/// mixed-platform failure can be reproduced by the same commands as any other.
/// The devices a diagnostic run should build, from PLATFORMS and NAMES.
///
/// The names matter as much as the platforms: a device's name goes into the
/// conflict-copy names it writes, so reproducing a sweep failure under
/// different names reproduces a different run.
fn platform_spec(names: &[&str]) -> Vec<(String, Platform)> {
    let spec = std::env::var("PLATFORMS").unwrap_or_default();
    let chosen = std::env::var("NAMES").unwrap_or_default();
    let owned: Vec<String> = if chosen.is_empty() {
        names.iter().map(|n| n.to_string()).collect()
    } else {
        chosen.split(',').map(|n| n.trim().to_string()).collect()
    };
    let mut platforms = spec.split(',');
    owned
        .into_iter()
        .map(|n| {
            let p = match platforms.next().unwrap_or("").trim() {
                "mac" => Platform::MacOs,
                "windows" => Platform::Windows,
                "hfs" => Platform::Decomposing,
                _ => Platform::Linux,
            };
            (n, p)
        })
        .collect()
}

/// The same workload where the computers disagree about what a name is.
#[test]
#[ignore]
fn scratch_platform_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep_on(
        "plat-mac-pc",
        40000..40800,
        40,
        &[("mac", Platform::MacOs), ("pc", Platform::Windows)],
        false,
    ));
    arms.push(sweep_on(
        "plat-linux-mac",
        40800..41600,
        40,
        &[("linux", Platform::Linux), ("mac", Platform::MacOs)],
        false,
    ));
    arms.push(sweep_on(
        "plat-mac-hfs",
        41600..42400,
        40,
        &[("mac", Platform::MacOs), ("disk", Platform::Decomposing)],
        false,
    ));
    arms.push(sweep_on(
        "plat-three-hostile",
        42400..43200,
        30,
        &[
            ("linux", Platform::Linux),
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
        ],
        true,
    ));
    arms.push(sweep_on(
        "plat-four-long",
        43200..43600,
        80,
        &[
            ("linux", Platform::Linux),
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
            ("disk", Platform::Decomposing),
        ],
        false,
    ));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// Another block nothing has run, Linux and mixed-platform together. New seeds
/// are the only thing that finds anything once a range has been fixed green.
#[test]
#[ignore]
fn scratch_dawn_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep("dawn-clean-2dev", 50000..51200, 40, &["laptop", "desktop"], false));
    arms.push(sweep("dawn-hostile-2dev", 51200..52400, 30, &["laptop", "desktop"], true));
    arms.push(sweep("dawn-clean-3dev", 52400..53200, 40, &["a", "b", "c"], false));
    arms.push(sweep("dawn-hostile-3dev", 53200..54000, 30, &["a", "b", "c"], true));
    arms.push(sweep("dawn-long-3dev", 54000..54400, 90, &["a", "b", "c"], false));
    arms.push(sweep_on(
        "dawn-mac-pc",
        55000..55800,
        40,
        &[("mac", Platform::MacOs), ("pc", Platform::Windows)],
        false,
    ));
    arms.push(sweep_on(
        "dawn-mac-hfs",
        55800..56600,
        40,
        &[("mac", Platform::MacOs), ("disk", Platform::Decomposing)],
        false,
    ));
    arms.push(sweep_on(
        "dawn-four-hostile",
        56600..57000,
        40,
        &[
            ("linux", Platform::Linux),
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
            ("disk", Platform::Decomposing),
        ],
        true,
    ));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// Seeds nothing has ever run. The sweep above is a regression suite now — it
/// is green by construction, because every seed in it was fixed. New defects
/// only come from new seeds.
#[test]
#[ignore]
fn scratch_night_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep("night-clean-2dev", 30000..31200, 40, &["laptop", "desktop"], false));
    arms.push(sweep("night-hostile-2dev", 31200..32400, 30, &["laptop", "desktop"], true));
    arms.push(sweep("night-clean-3dev", 32400..33200, 40, &["a", "b", "c"], false));
    arms.push(sweep("night-hostile-3dev", 33200..34000, 30, &["a", "b", "c"], true));
    arms.push(sweep("night-long-3dev", 34000..34400, 90, &["a", "b", "c"], false));
    arms.push(sweep("night-long-2dev", 35000..35400, 80, &["laptop", "desktop"], false));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// Long AND hostile, which nothing else here is.
///
/// Every `*-long-*` sweep above runs clean, and every `*-hostile-*` one stops at
/// thirty steps. So a campaign has never had to survive network chaos for its
/// whole length — which is exactly what the rig does, for an hour and three
/// quarters, with faults throughout, and where most of what it finds comes
/// from. A short hostile run reaches a handful of retries; a long one reaches
/// the state a device gets into after retrying through a hundred of them while
/// the tree underneath it keeps moving.
///
/// Slower per seed than anything else here — budget for it accordingly.
#[test]
#[ignore]
fn scratch_long_hostile_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep("longhostile-2dev", 60000..60400, 80, &["laptop", "desktop"], true));
    arms.push(sweep("longhostile-3dev", 61000..61300, 90, &["a", "b", "c"], true));
    arms.push(sweep_on(
        "longhostile-mixed",
        62000..62200,
        80,
        &[
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
            ("disk", Platform::Decomposing),
        ],
        true,
    ));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// Everything the other sweeps do, inside a vault.
///
/// Encryption has only ever been tested by stories somebody wrote down, and
/// every one of them is calm: seed, settle, write, settle. So no encrypted file
/// had ever been renamed while its upload was retrying, moved into a folder
/// another device was deleting, reached for by two computers at once, or
/// carried through a conflict copy -- and the soak rig, which does all of that
/// for an hour and three quarters, reported `no-ciphertext: vacuous` on every
/// segment it ever ran, because no encrypted entity existed for it to check.
///
/// What makes this arm different from the ones above, rather than the same
/// workload in a subfolder: an encrypted file's name is not on the server, its
/// hash there is of ciphertext while the disk's is of plaintext, and its
/// content key has to survive every retry -- three places where the two sides
/// speak different languages and an engine can quietly compare across them.
#[test]
#[ignore]
fn scratch_vault_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep_vault("vault-clean-2dev", 70000..70400, 40, &["laptop", "desktop"], false));
    arms.push(sweep_vault("vault-hostile-2dev", 70400..70800, 30, &["laptop", "desktop"], true));
    arms.push(sweep_vault("vault-clean-3dev", 70800..71100, 40, &["a", "b", "c"], false));
    arms.push(sweep_vault("vault-hostile-3dev", 71100..71400, 30, &["a", "b", "c"], true));
    arms.push(sweep_vault("vault-longhostile-2dev", 71400..71600, 80, &["laptop", "desktop"], true));
    arms.push(sweep_vault("vault-longhostile-3dev", 71600..71750, 90, &["a", "b", "c"], true));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// A vault on computers that disagree about what a name is.
///
/// The corner nothing had touched. Naming is decided in the PLAINTEXT domain --
/// a vault file's real name comes out of its metadata blob, and every question
/// about it (is this a sibling of that, do these two want one slot, what shall
/// the conflict copy be called) is asked about a name the server has never
/// seen. Outside a vault the server is a second opinion: it refuses a name a
/// live sibling already holds, and the client meets that refusal. Inside one it
/// cannot be -- it is holding `enc-<content id>`, unique by construction, and
/// two files it considers perfectly distinct are one slot on a Mac.
///
/// So case twins, composed and decomposed spellings, and conflict-copy naming
/// are resolved here with no second opinion at all, on volumes that fold or
/// rewrite the names as they land. `scratch_platform_sweep` covers the folding;
/// `scratch_vault_sweep` covers the encryption; neither covers both.
#[test]
#[ignore]
fn scratch_vault_platform_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep_vault_on(
        "vaultplat-mac-pc",
        72000..72400,
        40,
        &[("mac", Platform::MacOs), ("pc", Platform::Windows)],
        false,
    ));
    arms.push(sweep_vault_on(
        "vaultplat-mac-hfs",
        72400..72800,
        40,
        &[("mac", Platform::MacOs), ("disk", Platform::Decomposing)],
        false,
    ));
    arms.push(sweep_vault_on(
        "vaultplat-linux-mac",
        72800..73200,
        40,
        &[("linux", Platform::Linux), ("mac", Platform::MacOs)],
        false,
    ));
    arms.push(sweep_vault_on(
        "vaultplat-hostile-mixed",
        73200..73450,
        40,
        &[
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
            ("disk", Platform::Decomposing),
        ],
        true,
    ));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// One device holds the key; the others do not.
///
/// The asymmetry the soak rig's `no-ciphertext` invariant was written for, and
/// which the rig has never once been able to check because it has no encrypted
/// lane at all. Three hand-written scenarios cover it in a calm world; nothing
/// covers it while the tree is being renamed, moved and deleted underneath.
///
/// What a keyless device has to get right is unusually specific. It can SEE the
/// encrypted entries -- they are in its change feed like anything else -- and it
/// must not materialize them, must not create the vault folder as an ordinary
/// directory (that would be a plaintext drop box inside the folder the user
/// believes is private), must say per file that it is waiting for a key, and
/// must go on syncing everything outside the vault perfectly. Every one of
/// those is a chance to do something silently wrong.
///
/// It is also the only arm that exercises a parked entry belonging to a file
/// this device will never hold, which is the case a name clash resolves
/// differently from every other.
#[test]
#[ignore]
fn scratch_vault_one_key_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep_vault_one_key("onekey-clean-2dev", 77000..77400, 40, &["holder", "guest"], false));
    arms.push(sweep_vault_one_key("onekey-hostile-2dev", 77400..77800, 30, &["holder", "guest"], true));
    arms.push(sweep_vault_one_key("onekey-clean-3dev", 77800..78100, 40, &["holder", "b", "c"], false));
    arms.push(sweep_vault_one_key("onekey-hostile-3dev", 78100..78400, 30, &["holder", "b", "c"], true));
    arms.push(sweep_vault_one_key("onekey-longhostile", 78400..78600, 80, &["holder", "guest"], true));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// Vault seeds nothing has run. The arms above are a regression suite now --
/// every seed in them was fixed, so they are green by construction.
#[test]
#[ignore]
fn scratch_vault_fresh_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep_vault("vaultfresh-clean-2dev", 74000..74500, 40, &["laptop", "desktop"], false));
    arms.push(sweep_vault("vaultfresh-hostile-2dev", 74500..75000, 30, &["laptop", "desktop"], true));
    arms.push(sweep_vault("vaultfresh-clean-3dev", 75000..75400, 40, &["a", "b", "c"], false));
    arms.push(sweep_vault("vaultfresh-hostile-3dev", 75400..75800, 30, &["a", "b", "c"], true));
    arms.push(sweep_vault("vaultfresh-longhostile", 75800..76000, 80, &["laptop", "desktop"], true));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

#[test]
#[ignore]
fn scratch_dump() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("40".into()).parse().unwrap();
    let n: usize = std::env::var("DEVS").unwrap_or("2".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("0".into()) == "1";
    let kills: bool = std::env::var("KILLS").unwrap_or("0".into()) == "1";
    let vault = match std::env::var("VAULT").unwrap_or("0".into()).as_str() {
        "1" => Vault::Shared,
        "2" => Vault::OneKeyHolder,
        _ => Vault::None,
    };
    let names: Vec<&str> = if n == 3 { vec!["a", "b", "c"] } else { vec!["laptop", "desktop"] };

    let spec = platform_spec(&names);
    let refs: Vec<(&str, Platform)> = spec.iter().map(|(n, p)| (n.as_str(), *p)).collect();
    let world = sweep_world(seed, &refs, steps, chaos, vault);
    let _ = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        drive(&world, seed, steps, chaos, sweep_root(vault), vault, kills);
        world.settle();
    }));

    for d in &world.devices {
        let out = world.pass(d);
        println!(
            "=== {} one more pass: quiet={} plan={:?} exec={:?}",
            d.name,
            out.quiet(),
            out.round.plan,
            out.exec
        );
    }
    println!("=== server (latest change {})", world.server.latest_change_id());
    for (p, h) in jd_sim::scenario::server_tree(&world.server) {
        println!("  {p} {h:?}");
    }
    // Encrypted rows BY ID, which the tree cannot show. An entry pointing at a
    // file the tree does not seem to contain is the ordinary case inside a
    // vault -- the stored title is the content id of the FIRST version and does
    // not follow later ones -- so "it is not in the tree" proves nothing, and
    // an investigation that starts there starts wrong.
    let vault_files = world.server.vault_files();
    if !vault_files.is_empty() {
        println!("=== server encrypted rows");
        for f in &vault_files {
            println!(
                "  id={} in {:?} stored={} key={} meta={} cipher={}",
                f.id,
                f.folder_path,
                f.placeholder,
                if f.wrapped_file_key.is_some() { "yes" } else { "NONE" },
                if f.encrypted_metadata.is_some() { "yes" } else { "NONE" },
                f.ciphertext.as_ref().map(|c| c.len()).unwrap_or(0),
            );
            match jd_sim::scenario::what_the_vault_really_holds(&world, f) {
                Some((name, cid)) => println!("        really {name:?} cid={cid}"),
                None => println!("        no key here opens it"),
            }
        }
    }
    for d in &world.devices {
        println!(
            "=== {} disk (cursor {})",
            d.name,
            d.store.cursor().unwrap()
        );
        for (p, h) in jd_sim::scenario::disk_tree(d) {
            println!("  {p} {h:?}");
        }
        println!("=== {} entries", d.name);
        for e in d.store.every_entry().unwrap() {
            println!(
                "  {:?} id={} parent={:?} remote_name={:?} local_name={:?} deleted={} status={:?} synced_name={:?} synced_parent={:?}",
                e.id.entity_type,
                e.id.server_id,
                e.remote.parent,
                e.remote.name,
                e.local_name,
                e.remote_deleted,
                e.status,
                e.synced_placement.as_ref().map(|p| p.name.clone()),
                e.synced_placement.as_ref().map(|p| p.parent),
            );
            // The crypto identity, without which an encrypted failure is
            // unreadable: a file key that opens but decrypts to nothing and a
            // content id that no longer matches the AAD look identical from the
            // outside, and both read as "decryption failed".
            if e.is_encrypted {
                println!(
                    "      encrypted cid={:?} key={} remote_sha={:?} synced_remote_sha={:?} synced_sha={:?}",
                    e.content_id,
                    match &e.wrapped_file_key {
                        Some(k) => format!("{}…", &k[..k.len().min(12)]),
                        None => "none".to_string(),
                    },
                    e.remote_content.as_ref().map(|c| c.sha256[..12].to_string()),
                    e.synced_remote_content.as_ref().map(|c| c.sha256[..12].to_string()),
                    e.synced_content.as_ref().map(|c| c.sha256[..12].to_string()),
                );
            }
        }
        println!("=== {} queued ops", d.name);
        for op in d.store.queued_ops().unwrap() {
            println!(
                "  op {} {:?} {} {} attempts={} err={:?}",
                op.op_id, op.kind, op.entity.server_id, op.params, op.attempts, op.last_error
            );
        }
        // What the engine decided it could not do, and told the user about. A
        // withdrawn op explains itself in exactly one place and this dump never
        // showed it, so an op that gave up looked identical to one that never
        // ran.
        let issues = d.store.open_issues().unwrap();
        if !issues.is_empty() {
            println!("=== {} issues", d.name);
            for i in issues {
                println!("  {:?} {:?}: {}", i.entity, i.kind, i.detail);
            }
        }
        // What a kill left behind. These are invisible in every other view:
        // nothing runs them, because only queued ops run; nothing re-plans
        // their entities, because a round leaves anything with an open op
        // alone; and nothing shows them, because this dump only ever listed
        // the queue. A device carrying one looks idle and correct.
        let interrupted = d.store.interrupted_ops().unwrap();
        if !interrupted.is_empty() {
            println!("=== {} INTERRUPTED ops", d.name);
            for op in interrupted {
                println!(
                    "  op {} {:?} {} {} attempts={} err={:?}",
                    op.op_id, op.kind, op.entity.server_id, op.params, op.attempts, op.last_error
                );
            }
        }
    }
}

/// Seeds that were each a real defect once, re-checked in one run.
///
/// Every one of these settles today, and that is the point: it is the cheap
/// answer to `did I just break something a sweep already paid for`, without
/// waiting on a full range. It prints rather than asserts, so a regression
/// shows up as a named seed and a message you can hand straight to
/// `scratch_one`.
///
/// Add a seed here when a sweep hands you one and you fix it. A seed that has
/// no entry here is only covered by the range it came from.
#[test]
#[ignore]
fn scratch_known_failures() {
    for (seed, steps, devs, chaos) in [
        (1149u64, 40usize, 2usize, false),
        (1471, 40, 3, false),
        (20007, 40, 2, false),
        (20020, 40, 2, false),
        (21396, 30, 2, true),
        (22014, 40, 3, false),
        (23020, 30, 3, true),
        (23277, 30, 3, true),
        (24013, 90, 3, false),
        (9007, 80, 2, false),
        (9043, 80, 2, false),
    ] {
        let names: Vec<&str> = if devs == 3 {
            vec!["a", "b", "c"]
        } else {
            vec!["laptop", "desktop"]
        };
        let r = std::panic::catch_unwind(|| workload(seed, steps, &names, chaos));
        match r {
            Ok(()) => println!("SEED {seed}: settled"),
            Err(e) => {
                let msg = e
                    .downcast_ref::<String>()
                    .cloned()
                    .or_else(|| e.downcast_ref::<&str>().map(|s| s.to_string()))
                    .unwrap_or_default();
                println!("SEED {seed}: {msg}");
            }
        }
    }
}

#[test]
#[ignore]
fn scratch_trace() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("40".into()).parse().unwrap();
    let n: usize = std::env::var("DEVS").unwrap_or("2".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("0".into()) == "1";
    let kills: bool = std::env::var("KILLS").unwrap_or("0".into()) == "1";
    let vault = match std::env::var("VAULT").unwrap_or("0".into()).as_str() {
        "1" => Vault::Shared,
        "2" => Vault::OneKeyHolder,
        _ => Vault::None,
    };
    let names: Vec<&str> = if n == 3 { vec!["a", "b", "c"] } else { vec!["laptop", "desktop"] };

    let spec = platform_spec(&names);
    let refs: Vec<(&str, Platform)> = spec.iter().map(|(n, p)| (n.as_str(), *p)).collect();
    let world = sweep_world(seed, &refs, steps, chaos, vault);
    drive(&world, seed, steps, chaos, sweep_root(vault), vault, kills);
    for round in 0..12 {
        for d in &world.devices {
            world.clock.advance_secs(20 * 60);
            let out = world.pass(d);
            if std::env::var("OPS").is_ok() {
                println!(
                    "  ROUND {round} {} plan={:?} exec={:?}",
                    d.name, out.round.plan, out.exec
                );
            }
        }
        trace(&world, &format!("settle round {round}"));
    }
}

#[test]
#[ignore]
fn scratch_one() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("40".into()).parse().unwrap();
    let n: usize = std::env::var("DEVS").unwrap_or("2".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("0".into()) == "1";
    let kills: bool = std::env::var("KILLS").unwrap_or("0".into()) == "1";
    let vault = match std::env::var("VAULT").unwrap_or("0".into()).as_str() {
        "1" => Vault::Shared,
        "2" => Vault::OneKeyHolder,
        _ => Vault::None,
    };
    let names: Vec<&str> = if n == 3 { vec!["a", "b", "c"] } else { vec!["laptop", "desktop"] };
    let spec = platform_spec(&names);
    let refs: Vec<(&str, Platform)> = spec.iter().map(|(n, p)| (n.as_str(), *p)).collect();
    let _ = workload_core(seed, steps, &refs, chaos, vault, kills);
}

/// Scratch: watch what happens to an entry the server has told us about, whose
/// file is already on the disk, with no agreement recorded between them. The
/// rig loops on this forever; the sim resolves it, and the question is how.
#[test]
#[ignore]
fn scratch_already_here() {
    let world = World::new(202, &["laptop", "desktop"]);
    let body = b"the file that is already here";
    world.device("laptop").fs.user_write("report.txt", body);
    assert!(world.settle().is_some());

    let desktop = world.device("desktop");
    let entry = desktop
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.remote.name == "report.txt")
        .unwrap();
    let id = entry.id;
    desktop
        .store
        .put_entry(&jd_core::model::Entry {
            synced_placement: None,
            synced_content: None,
            synced_fingerprint: None,
            synced_remote_content: None,
            status: jd_core::model::LocalStatus::PendingUpload,
            ..entry
        })
        .unwrap();
    desktop.fs.set_mtime_ns("report.txt", 99_000_000_000);
    // The state the upload path leaves when the file was edited while it was
    // going up: the server holds a real version, the disk holds a newer one,
    // and nothing is agreed.
    if std::env::var("EDITED").is_ok() {
        desktop.fs.user_write("report.txt", b"edited while it was going up");
    }

    for round in 0..6 {
        world.clock.advance_secs(20 * 60);
        let out = world.pass(desktop);
        let ids: Vec<i64> = desktop
            .store
            .every_entry()
            .unwrap()
            .iter()
            .filter(|e| e.remote.name.contains("report"))
            .map(|e| e.id.server_id)
            .collect();
        let st = desktop
            .store
            .get_entry(id)
            .unwrap()
            .map(|e| format!("{:?}", e.status));
        println!(
            "ROUND {round} quiet={} plan={:?} exec={:?}\n   entries={ids:?} real_status={st:?} next_prov={}",
            out.quiet(),
            out.round.plan.ops.iter().map(|o| format!("{:?}/{:?}", o.entity.server_id, o.action)).collect::<Vec<_>>(),
            out.exec,
            desktop.store.next_provisional_id().unwrap(),
        );
    }
}

// ---------------------------------------------------------------------------
// Two people editing the same handful of files, without pausing
// ---------------------------------------------------------------------------

/// The rig's shape, which the generator above has never had.
///
/// Two differences, and both of them are the point.
///
/// **Density.** `drive` syncs on one step in twenty and advances the clock
/// twenty minutes when it does, so every pass begins long after the last thing
/// anybody typed and the world is quiet by construction. The rig interleaves
/// saves and syncs three seconds apart, all day. A save landing while the last
/// one is still in flight is the ordinary case there and unreachable here.
///
/// **Collision.** Both computers work on the SAME small set of names, and an
/// editor touches three paths per save -- the document, its backup, and a swap
/// file it writes and deletes. The rig's only two confirmed losses were both of
/// a file two computers wrote within a tenth of a second of each other, one of
/// them a `~` backup, and neither left a conflict copy behind.
///
/// The per-pass custody check is what is really being asked here: anything on a
/// disk when a pass begins and gone when it ends has to be findable somewhere.
fn hammer(seed: u64, steps: usize, devices: &[(&str, Platform)], chaos: bool) {
    let world = World::of(seed, devices);
    let mut rng = SimRng::new(seed ^ 0xED17_0BE5);
    if chaos {
        for d in &world.devices {
            d.net.set_faults(NetFaults::chaos());
        }
    }

    for step in 0..steps {
        let device = &world.devices[rng.below(world.devices.len() as u64) as usize];
        let doc = format!("notes-{}.txt", rng.below(3));
        match rng.below(10) {
            // An editor saving: the backup first, then the document itself
            // through a temporary, then the swap file it keeps beside them.
            0..=5 => {
                let salt = rng.below(1_000_000);
                let body = |what: &str| {
                    format!("{what} {step} {} {salt}", device.name).into_bytes()
                };
                device.fs.user_write(&format!("{doc}~"), &body("backup"));
                let tmp = format!(".{doc}.new");
                device.fs.user_write(&tmp, &body("document"));
                device.fs.user_rename(&tmp, &doc);
                device.fs.user_write(&format!(".{doc}.swp"), &body("swap"));
                device.fs.user_remove(&format!(".{doc}.swp"));
            }
            // Both of them at once, a tenth of a second apart. Written as one
            // step because that is what it is: neither computer has had the
            // chance to hear about the other.
            6 => {
                for d in &world.devices {
                    d.fs.user_write(
                        &format!("{doc}~"),
                        format!("racing {step} from {}", d.name).as_bytes(),
                    );
                    world.clock.advance_secs(0);
                }
            }
            // Sync -- often, and without leaving the moment. Twenty minutes of
            // quiet between passes is what makes the ordinary generator a calm
            // world; three seconds is what the rig actually does.
            _ => {
                world.clock.advance_secs(3);
                world.pass(device);
            }
        }
    }

    assert!(
        world.settle().is_some(),
        "seed {seed}: the world never went quiet"
    );
    assert_converged(&world);
    assert_no_entry_is_stranded(&world);
    assert_no_live_orphan_on_the_server(&world);

    // Did it reach the shape it exists for? An arm that never produces a
    // conflict is two computers taking turns, not two computers colliding, and
    // it would pass forever without testing anything. Counted rather than
    // asserted per seed -- a single quiet seed is fine, a quiet ARM is not.
    let copies = jd_sim::scenario::server_tree(&world.server)
        .into_iter()
        .filter(|(p, h)| h.is_some() && p.contains("(conflicted copy "))
        .count();
    CONFLICTS.fetch_add(copies, std::sync::atomic::Ordering::Relaxed);
}

static CONFLICTS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);

fn hammer_sweep(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[&str],
    chaos: bool,
) -> Vec<(String, u64)> {
    let spec: Vec<(&str, Platform)> = devices.iter().map(|n| (*n, Platform::Linux)).collect();
    let total = (seeds.end - seeds.start) as usize;
    let mut failures = Vec::new();
    for seed in seeds {
        let owned: Vec<(String, Platform)> =
            spec.iter().map(|(n, p)| (n.to_string(), *p)).collect();
        let r = std::panic::catch_unwind(|| {
            let refs: Vec<(&str, Platform)> =
                owned.iter().map(|(n, p)| (n.as_str(), *p)).collect();
            hammer(seed, steps, &refs, chaos);
        });
        if r.is_err() {
            failures.push(seed);
        }
    }
    let collided = CONFLICTS.swap(0, std::sync::atomic::Ordering::Relaxed);
    println!(
        "SWEEP {label}: {} of {total} failed: {:?} ({collided} conflict copies)",
        failures.len(),
        failures
    );
    assert!(
        collided > 0,
        "{label}: not one conflict copy in {total} seeds -- the arm never \
         reached the collision it exists for"
    );
    failures.into_iter().map(|s| (label.to_string(), s)).collect()
}

#[test]
#[ignore]
fn scratch_hammer_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(hammer_sweep("hammer-clean-2dev", 80000..80300, 120, &["laptop", "desktop"], false));
    arms.push(hammer_sweep("hammer-hostile-2dev", 80300..80600, 120, &["laptop", "desktop"], true));
    arms.push(hammer_sweep("hammer-hostile-3dev", 80600..80800, 120, &["a", "b", "c"], true));
    no_seed_failed(arms);
}

/// One hammer seed, for tracing.
#[test]
#[ignore]
fn scratch_hammer_one() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("120".into()).parse().unwrap();
    let n: usize = std::env::var("DEVS").unwrap_or("2".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("0".into()) == "1";
    let names: Vec<&str> = if n == 3 { vec!["a", "b", "c"] } else { vec!["laptop", "desktop"] };
    let spec: Vec<(&str, Platform)> = names.iter().map(|n| (*n, Platform::Linux)).collect();
    hammer(seed, steps, &spec, chaos);
}

/// One vault-on-mixed-platforms seed, for tracing.
#[test]
#[ignore]
fn scratch_vaultplat_one() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("40".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("1".into()) == "1";
    let kills: bool = std::env::var("KILLS").unwrap_or("0".into()) == "1";
    workload_core(
        seed,
        steps,
        &[
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
            ("disk", Platform::Decomposing),
        ],
        chaos,
        Vault::Shared,
        kills,
    );
}

/// Machines that die with work still on their lists.
///
/// The one fault the rig has that these sweeps did not. Its three unexplained
/// losses across a hundred and fifty-nine campaigns all landed on the device it
/// kills, and each was the last thing written there before the kill -- a shape
/// no number of dropped packets reaches, because recovery is a different code
/// path from a retry. Plaintext and vault, clean and hostile, because a kill is
/// orthogonal to both and the interesting seeds are where they combine.
#[test]
#[ignore]
fn scratch_kill_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep_killing("kill-clean-2dev", 79000..79400, 40, &["laptop", "desktop"], false, Vault::None));
    arms.push(sweep_killing("kill-hostile-2dev", 79400..79800, 30, &["laptop", "desktop"], true, Vault::None));
    arms.push(sweep_killing("kill-hostile-3dev", 79800..80100, 30, &["a", "b", "c"], true, Vault::None));
    arms.push(sweep_killing("kill-vault-2dev", 80100..80500, 40, &["laptop", "desktop"], false, Vault::Shared));
    arms.push(sweep_killing("kill-vault-hostile", 80500..80800, 30, &["laptop", "desktop"], true, Vault::Shared));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// Machines that die, on disks that disagree about names.
///
/// The two dimensions the estate had covered only apart. Recovery re-runs an op
/// whose plan was written before the kill, and a mixed fleet is where re-running
/// it is not the same as running it: the name it asks for may already be taken
/// by its own earlier attempt under a spelling this disk calls identical and the
/// server calls different. The last arm adds the vault only one device can open,
/// where no server is available as a second opinion about any of it.
#[test]
#[ignore]
fn scratch_kill_platform_sweep() {
    let mut arms: Vec<Vec<(String, u64)>> = Vec::new();
    std::panic::set_hook(Box::new(|_| {}));
    arms.push(sweep_killing_on(
        "killplat-mac-pc",
        81000..81400,
        40,
        &[("mac", Platform::MacOs), ("pc", Platform::Windows)],
        false,
        Vault::None,
    ));
    arms.push(sweep_killing_on(
        "killplat-mac-hfs",
        81400..81800,
        40,
        &[("mac", Platform::MacOs), ("disk", Platform::Decomposing)],
        false,
        Vault::None,
    ));
    arms.push(sweep_killing_on(
        "killplat-hostile-mixed",
        81800..82100,
        30,
        &[
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
            ("box", Platform::Linux),
        ],
        true,
        Vault::None,
    ));
    arms.push(sweep_killing_on(
        "killplat-vault-onekey",
        82100..82400,
        30,
        &[("holder", Platform::MacOs), ("guest", Platform::Windows)],
        true,
        Vault::OneKeyHolder,
    ));
    let _ = std::panic::take_hook();
    no_seed_failed(arms);
}

/// Scratch: one seed from the one-key-holder arms, faithfully.
///
/// `scratch_one` cannot stand in for these — it names three devices a/b/c and
/// asks `platform_spec` what they run on, while the one-key arms name them
/// holder/b/c and put every one on Linux. Device names and platforms both feed
/// the workload, so the wrong ones reproduce a different world and the seed
/// looks innocent.
#[test]
#[ignore]
fn scratch_onekey_one() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("30".into()).parse().unwrap();
    let n: usize = std::env::var("DEVS").unwrap_or("3".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("1".into()) == "1";
    let names: Vec<&str> = if n == 3 {
        vec!["holder", "b", "c"]
    } else {
        vec!["holder", "guest"]
    };
    let refs: Vec<(&str, Platform)> = names.iter().map(|n| (*n, Platform::Linux)).collect();
    let _ = workload_core(seed, steps, &refs, chaos, Vault::OneKeyHolder, false);
}

/// Scratch: trace one seed from the one-key-holder arms.
///
/// Same world as `scratch_onekey_one` — holder/b/c, all Linux — so the seed
/// reproduces. `OPS=1` prints each device's plan per round.
#[test]
#[ignore]
fn scratch_onekey_trace() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("30".into()).parse().unwrap();
    let n: usize = std::env::var("DEVS").unwrap_or("3".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("1".into()) == "1";
    let names: Vec<&str> = if n == 3 { vec!["holder", "b", "c"] } else { vec!["holder", "guest"] };
    let refs: Vec<(&str, Platform)> = names.iter().map(|n| (*n, Platform::Linux)).collect();
    let vault = Vault::OneKeyHolder;
    let world = sweep_world(seed, &refs, steps, chaos, vault);
    drive(&world, seed, steps, chaos, sweep_root(vault), vault, false);
    for round in 0..8 {
        for d in &world.devices {
            world.clock.advance_secs(20 * 60);
            let out = world.pass(d);
            if std::env::var("OPS").is_ok() {
                println!("  ROUND {round} {} plan={:?} exec={:?}", d.name, out.round.plan, out.exec);
            }
        }
    }
}
