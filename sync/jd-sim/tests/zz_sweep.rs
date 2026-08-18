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
//! And the tools for the seed a sweep hands back. All take `SEED`, `STEPS`,
//! `DEVS`, `CHAOS`, and — for a platform failure — `PLATFORMS` and `NAMES`:
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
fn workload_on(seed: u64, steps: usize, devices: &[(&str, Platform)], chaos: bool) {
    let mut world = World::of(seed, devices);
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
        world.user_saves_while_downloads_land(8, (steps / 4).max(2) as u64);
    }
    let world = world;
    let committed = Committed::default();
    drive(&world, seed, steps, chaos);

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

fn drive(world: &World, seed: u64, steps: usize, chaos: bool) {
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
    let mut dirs: Vec<String> = vec![String::new()];
    // What was last written where, so a "copy" arm can produce a second file
    // with byte-identical content. Duplicate content is its own shape: pairing
    // hunts by hash, and two files that hash the same are exactly where that
    // goes wrong.
    let mut bodies: std::collections::HashMap<String, Vec<u8>> =
        std::collections::HashMap::new();

    for step in 0..steps {
        let device = &world.devices[rng.below(world.devices.len() as u64) as usize];
        let join = |d: &str, n: &str| -> String {
            if d.is_empty() {
                n.to_string()
            } else {
                format!("{d}/{n}")
            }
        };

        match rng.below(18) {
            // Create a file, sometimes deep.
            0..=2 => {
                let dir = rng.pick(&dirs).cloned().unwrap_or_default();
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
                let parent = rng.pick(&dirs).cloned().unwrap_or_default();
                let path = join(&parent, &format!("Sub {step}"));
                device.fs.user_mkdir(&path);
                dirs.push(path);
            }
            // Rename a folder — with whatever is inside it.
            11 => {
                let candidates: Vec<String> =
                    dirs.iter().filter(|d| !d.is_empty()).cloned().collect();
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
                let slots = ["Shared/slot-1.dat", "Shared/slot-2.dat", "Shared/slot-3.dat"];
                if !device.fs.exists(slots[0]) {
                    device.fs.user_mkdir("Shared");
                    for (n, s) in slots.iter().enumerate() {
                        device
                            .fs
                            .user_write(s, format!("slot {n} from {}", device.name).as_bytes());
                        files.push(s.to_string());
                    }
                } else if rng.below(3) == 0 {
                    // Three-way rotation: a→tmp, b→a, c→b, tmp→c. The same trap
                    // with a longer cycle, which is where an implementation that
                    // special-cased pairs falls over.
                    let via = format!("Shared/.rotate-{step}.tmp");
                    device.fs.user_rename(slots[0], &via);
                    device.fs.user_rename(slots[1], slots[0]);
                    device.fs.user_rename(slots[2], slots[1]);
                    device.fs.user_rename(&via, slots[2]);
                } else {
                    let i = rng.below(3) as usize;
                    let j = (i + 1 + rng.below(2) as usize) % 3;
                    let via = format!("Shared/.swap-{step}.tmp");
                    device.fs.user_rename(slots[i], &via);
                    device.fs.user_rename(slots[j], slots[i]);
                    device.fs.user_rename(&via, slots[j]);
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
                    dirs.iter().filter(|d| !d.is_empty()).cloned().collect();
                if let (Some(d), Some(into)) =
                    (rng.pick(&movable).cloned(), rng.pick(&dirs).cloned())
                {
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
                    dirs.iter().filter(|d| !d.is_empty()).cloned().collect();
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
            // Let it sync.
            _ => {
                world.clock.advance_secs(20 * 60);
                world.pass(device);
                trace(world, &format!("step {step} pass on {}", device.name));
            }
        }
    }
}

fn sweep(label: &str, seeds: std::ops::Range<u64>, steps: usize, devices: &[&str], chaos: bool) {
    let mixed: Vec<(&str, Platform)> = devices.iter().map(|n| (*n, Platform::Linux)).collect();
    sweep_on(label, seeds, steps, &mixed, chaos)
}

fn sweep_on(
    label: &str,
    seeds: std::ops::Range<u64>,
    steps: usize,
    devices: &[(&str, Platform)],
    chaos: bool,
) {
    let total = (seeds.end - seeds.start) as usize;
    let mut failures = Vec::new();
    for seed in seeds {
        let owned: Vec<(String, Platform)> =
            devices.iter().map(|(n, p)| (n.to_string(), *p)).collect();
        let r = std::panic::catch_unwind(|| {
            let refs: Vec<(&str, Platform)> =
                owned.iter().map(|(n, p)| (n.as_str(), *p)).collect();
            workload_on(seed, steps, &refs, chaos);
        });
        if r.is_err() {
            failures.push(seed);
        }
    }
    println!(
        "SWEEP {label}: {} of {total} failed: {:?}",
        failures.len(),
        failures
    );
}

#[test]
#[ignore] // 3000+ seeds; run it by name, not as part of the workspace suite
fn scratch_rich_sweep() {
    std::panic::set_hook(Box::new(|_| {}));
    sweep("orig-clean-2dev", 1000..1200, 40, &["laptop", "desktop"], false);
    sweep("orig-hostile-2dev", 1200..1400, 30, &["laptop", "desktop"], true);
    sweep("orig-clean-3dev", 1400..1520, 40, &["a", "b", "c"], false);
    sweep("wide-clean-2dev", 20000..20600, 40, &["laptop", "desktop"], false);
    sweep("wide-hostile-2dev", 21000..21600, 30, &["laptop", "desktop"], true);
    sweep("wide-clean-3dev", 22000..22400, 40, &["a", "b", "c"], false);
    sweep("wide-hostile-3dev", 23000..23400, 30, &["a", "b", "c"], true);
    sweep("wide-long-3dev", 24000..24200, 90, &["a", "b", "c"], false);
    sweep("fresh-long-2dev", 9000..9150, 80, &["laptop", "desktop"], false);
    let _ = std::panic::take_hook();
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

fn platform_world(seed: u64, names: &[&str]) -> World {
    let spec = platform_spec(names);
    let devices: Vec<(&str, Platform)> = spec.iter().map(|(n, p)| (n.as_str(), *p)).collect();
    World::of(seed, &devices)
}

/// The same workload where the computers disagree about what a name is.
#[test]
#[ignore]
fn scratch_platform_sweep() {
    std::panic::set_hook(Box::new(|_| {}));
    sweep_on(
        "plat-mac-pc",
        40000..40800,
        40,
        &[("mac", Platform::MacOs), ("pc", Platform::Windows)],
        false,
    );
    sweep_on(
        "plat-linux-mac",
        40800..41600,
        40,
        &[("linux", Platform::Linux), ("mac", Platform::MacOs)],
        false,
    );
    sweep_on(
        "plat-mac-hfs",
        41600..42400,
        40,
        &[("mac", Platform::MacOs), ("disk", Platform::Decomposing)],
        false,
    );
    sweep_on(
        "plat-three-hostile",
        42400..43200,
        30,
        &[
            ("linux", Platform::Linux),
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
        ],
        true,
    );
    sweep_on(
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
    );
    let _ = std::panic::take_hook();
}

/// Another block nothing has run, Linux and mixed-platform together. New seeds
/// are the only thing that finds anything once a range has been fixed green.
#[test]
#[ignore]
fn scratch_dawn_sweep() {
    std::panic::set_hook(Box::new(|_| {}));
    sweep("dawn-clean-2dev", 50000..51200, 40, &["laptop", "desktop"], false);
    sweep("dawn-hostile-2dev", 51200..52400, 30, &["laptop", "desktop"], true);
    sweep("dawn-clean-3dev", 52400..53200, 40, &["a", "b", "c"], false);
    sweep("dawn-hostile-3dev", 53200..54000, 30, &["a", "b", "c"], true);
    sweep("dawn-long-3dev", 54000..54400, 90, &["a", "b", "c"], false);
    sweep_on(
        "dawn-mac-pc",
        55000..55800,
        40,
        &[("mac", Platform::MacOs), ("pc", Platform::Windows)],
        false,
    );
    sweep_on(
        "dawn-mac-hfs",
        55800..56600,
        40,
        &[("mac", Platform::MacOs), ("disk", Platform::Decomposing)],
        false,
    );
    sweep_on(
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
    );
    let _ = std::panic::take_hook();
}

/// Seeds nothing has ever run. The sweep above is a regression suite now — it
/// is green by construction, because every seed in it was fixed. New defects
/// only come from new seeds.
#[test]
#[ignore]
fn scratch_night_sweep() {
    std::panic::set_hook(Box::new(|_| {}));
    sweep("night-clean-2dev", 30000..31200, 40, &["laptop", "desktop"], false);
    sweep("night-hostile-2dev", 31200..32400, 30, &["laptop", "desktop"], true);
    sweep("night-clean-3dev", 32400..33200, 40, &["a", "b", "c"], false);
    sweep("night-hostile-3dev", 33200..34000, 30, &["a", "b", "c"], true);
    sweep("night-long-3dev", 34000..34400, 90, &["a", "b", "c"], false);
    sweep("night-long-2dev", 35000..35400, 80, &["laptop", "desktop"], false);
    let _ = std::panic::take_hook();
}

#[test]
#[ignore]
fn scratch_dump() {
    let seed: u64 = std::env::var("SEED").unwrap().parse().unwrap();
    let steps: usize = std::env::var("STEPS").unwrap_or("40".into()).parse().unwrap();
    let n: usize = std::env::var("DEVS").unwrap_or("2".into()).parse().unwrap();
    let chaos: bool = std::env::var("CHAOS").unwrap_or("0".into()) == "1";
    let names: Vec<&str> = if n == 3 { vec!["a", "b", "c"] } else { vec!["laptop", "desktop"] };

    let world = platform_world(seed, &names);
    let _ = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
        drive(&world, seed, steps, chaos);
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
    println!("=== server");
    for (p, h) in jd_sim::scenario::server_tree(&world.server) {
        println!("  {p} {h:?}");
    }
    for d in &world.devices {
        println!("=== {} disk", d.name);
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
        }
        println!("=== {} queued ops", d.name);
        for op in d.store.queued_ops().unwrap() {
            println!(
                "  op {} {:?} {} {} attempts={} err={:?}",
                op.op_id, op.kind, op.entity.server_id, op.params, op.attempts, op.last_error
            );
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
    let names: Vec<&str> = if n == 3 { vec!["a", "b", "c"] } else { vec!["laptop", "desktop"] };

    let world = platform_world(seed, &names);
    drive(&world, seed, steps, chaos);
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
    let names: Vec<&str> = if n == 3 { vec!["a", "b", "c"] } else { vec!["laptop", "desktop"] };
    let spec = platform_spec(&names);
    let refs: Vec<(&str, Platform)> = spec.iter().map(|(n, p)| (n.as_str(), *p)).collect();
    workload_on(seed, steps, &refs, chaos);
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
