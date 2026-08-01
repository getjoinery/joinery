//! What a real macOS volume actually does.
//!
//! Everything the simulator can decide about macOS it decides on Linux, because
//! the filesystem's rules are data rather than `#[cfg]`. What it cannot do is
//! find out whether an actual APFS volume behaves the way we told the simulator
//! it does — and if it does not, every scenario that passes is passing about a
//! machine that does not exist.
//!
//! So these run only on macOS, and they are the bridge between the two: they
//! check that this volume matches the personality the engine assumes, and that
//! the one boundary where the operating system's spelling enters the program
//! behaves as the `Vfs` contract promises.
//!
//! A no-op everywhere else, which is why they live here rather than in a program
//! the macOS gate generates: assertions worth making are worth being able to
//! read.

#![cfg(target_os = "macos")]

use jd_vfs::{OsVfs, Personality, Vfs};

/// A scratch area, and a sync root inside it.
///
/// The two are separate because the spool directory has to live **outside** the
/// synced tree — it is where downloads are assembled before they become visible,
/// and a spool inside the root is a directory the scanner would find and try to
/// sync. Returned as a pair so no test here can accidentally nest them, which is
/// a mistake this file has already made once.
fn scratch(tag: &str) -> (std::path::PathBuf, std::path::PathBuf) {
    let base = std::env::temp_dir().join(format!(
        "jd-macos-{}-{}-{:?}",
        tag,
        std::process::id(),
        std::thread::current().id()
    ));
    let _ = std::fs::remove_dir_all(&base);
    let root = base.join("root");
    std::fs::create_dir_all(&root).unwrap();
    (base, root)
}

/// Open a sync root with its spool kept out of the tree, as the client does.
fn open(base: &std::path::Path, root: &std::path::Path) -> OsVfs {
    OsVfs::new(root.to_path_buf(), base.join("spool")).unwrap()
}

#[test]
fn the_assumed_macos_personality_matches_a_stock_mac() {
    // The compile-time default only matters where the probe cannot run, but it
    // is also what the simulator models every macOS scenario against — so a
    // default that describes no real machine means a suite passing about a
    // computer that does not exist. That is not hypothetical: this assertion
    // caught exactly that, when the default still described HFS+.
    //
    // A developer's case-sensitive volume will fail this legitimately. That is a
    // finding worth seeing rather than hiding: the shipped client reads these
    // from the volume, and this says the *assumption* has drifted.
    let (base, dir) = scratch("probe");
    let probed = Personality::probe(&dir);
    let assumed = Personality::macos();

    assert_eq!(
        probed.case_insensitive, assumed.case_insensitive,
        "this volume disagrees with Personality::macos() about case"
    );
    assert_eq!(
        probed.decomposes_unicode, assumed.decomposes_unicode,
        "this volume disagrees with Personality::macos() about normalization \
         — APFS preserves what it is given; HFS+ decomposed"
    );

    let _ = std::fs::remove_dir_all(&base);
}

#[test]
fn whatever_this_volume_stores_the_engine_is_handed_the_composed_name() {
    // The contract the whole accented-filename story rests on, asserted without
    // assuming which kind of volume this is.
    //
    // Worth stating why that matters: this test used to assert macOS stores
    // names decomposed, because HFS+ did and "macOS decomposes your filenames"
    // became folklore. APFS replaced HFS+ in 2017 and preserves exactly what it
    // was given. Running on a real Mac is what found that — against a simulator
    // faithfully reproducing a filesystem nobody has used for years.
    //
    // So the assertion is the invariant rather than the mechanism: however this
    // volume chose to store it, `read_dir` hands back NFC.
    let (base, dir) = scratch("nfd");
    let vfs = open(&base, &dir);
    let root = vfs.root().unwrap();

    let composed = "caf\u{e9}.txt";
    std::fs::write(root.join(composed), b"an espresso").unwrap();

    let seen: Vec<String> = vfs
        .read_dir(&root)
        .unwrap()
        .into_iter()
        .map(|e| e.name)
        .collect();
    assert_eq!(
        seen,
        vec![composed.to_string()],
        "the Vfs contract is that the engine only ever sees NFC"
    );

    // And the probe has to agree with what the volume actually did, since that
    // answer is what the whole engine is configured from.
    let raw: Vec<String> = std::fs::read_dir(&root)
        .unwrap()
        .flatten()
        .map(|e| e.file_name().to_string_lossy().to_string())
        .filter(|n| !n.starts_with('.'))
        .collect();
    let stored_decomposed = raw.iter().any(|n| n.contains('\u{301}'));
    assert_eq!(
        Personality::probe(&dir).decomposes_unicode,
        stored_decomposed,
        "the probe disagrees with what this volume did to {raw:?}"
    );

    let _ = std::fs::remove_dir_all(&base);
}

#[test]
fn either_spelling_opens_the_same_file() {
    // Normalization-*insensitive* lookup, which APFS is even though it does not
    // normalize on write. This is what makes composing on the way out safe: the
    // composed name handed to the engine still opens the file however the volume
    // spelled it.
    let (base, dir) = scratch("lookup");
    let vfs = open(&base, &dir);
    let root = vfs.root().unwrap();

    std::fs::write(root.join("caf\u{e9}.txt"), b"x").unwrap();

    assert!(vfs
        .fingerprint(&root.join("caf\u{e9}.txt"))
        .unwrap()
        .is_some());
    assert!(vfs
        .fingerprint(&root.join("cafe\u{301}.txt"))
        .unwrap()
        .is_some());

    let _ = std::fs::remove_dir_all(&base);
}

#[test]
fn a_root_reached_through_a_symlink_resolves_to_the_place_events_will_name() {
    // The `/var` → `/private/var` case, which is not hypothetical on macOS:
    // FSEvents reports resolved paths, so a watcher started on the unresolved
    // spelling discards every event it receives and looks perfectly healthy.
    let (base, _) = scratch("symlink");
    let real = base.join("real");
    std::fs::create_dir_all(&real).unwrap();
    let link = base.join("link");
    std::os::unix::fs::symlink(&real, &link).unwrap();

    let vfs = open(&base, &link);
    assert_eq!(
        vfs.root().unwrap(),
        std::fs::canonicalize(&real).unwrap(),
        "the root must be the spelling the operating system will use"
    );

    let _ = std::fs::remove_dir_all(&base);
}

#[test]
fn two_names_differing_only_by_case_cannot_both_exist_here() {
    // The premise behind refusing the second of a case-clashing pair. If this
    // volume could hold both, refusing would be needless.
    let (base, dir) = scratch("caseclash");
    std::fs::write(dir.join("Report.txt"), b"first").unwrap();
    std::fs::write(dir.join("report.txt"), b"second").unwrap();

    let count = std::fs::read_dir(&dir).unwrap().count();
    assert_eq!(
        count, 1,
        "a case-insensitive volume holds one file, and the second write replaced the first"
    );
    assert_eq!(std::fs::read(dir.join("Report.txt")).unwrap(), b"second");

    let _ = std::fs::remove_dir_all(&base);
}
