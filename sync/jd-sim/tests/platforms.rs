//! The same engine, on filesystems that disagree about what a name is.
//!
//! These are the scenarios that only happen on somebody else's computer: a Mac
//! that hands back a different spelling than it was given, a PC that refuses a
//! colon and reserves `CON`, a volume that cannot tell `Report.txt` from
//! `report.txt`. Every one of them has the same failure signature when it is
//! handled badly — the client works, quietly renames the user's files, and the
//! renames bounce between devices forever.
//!
//! They run here, on Linux, because the filesystem's rules are *data* rather
//! than `#[cfg]` branches. A Windows-only bug is a bug findable on the dev box,
//! and a world can hold a Mac and a PC at once and watch them disagree about how
//! many files exist.

use jd_sim::scenario::{assert_converged, assert_nothing_lost, disk_tree, Committed, Platform};
use jd_sim::World;

/// Paths on a device's disk, for readable assertions.
fn paths(world: &World, device: &str) -> Vec<String> {
    disk_tree(world.device(device)).into_keys().collect()
}

/// Names the server holds, whatever any device could do with them.
fn server_names(world: &World) -> Vec<String> {
    let mut names: Vec<String> = world.server.tree().into_keys().collect();
    names.sort();
    names
}

// ---------------------------------------------------------------------------
// Volumes that hand names back in a different spelling than they went in
//
// Not macOS, which is the correction a real Mac forced: HFS+ normalized every
// name to NFD on write, and "macOS decomposes your filenames" outlived the
// filesystem by eight years. APFS preserves what it is given. The behaviour is
// still worth testing — HFS+ disks are still mounted, and network shares
// normalize on their own terms — it simply is not what a Mac does.
// ---------------------------------------------------------------------------

#[test]
fn an_accented_name_on_a_decomposing_volume_settles_instead_of_renaming_itself_forever() {
    // The volume stores `café.txt` decomposed and reports it that way. Compare
    // raw bytes and every pass sees a rename: upload the "new" name, hear it
    // back, rename again. The file is never lost and the client never stops.
    let world = World::of(1, &[("disk", Platform::Decomposing)]);
    let mut committed = Committed::default();

    let body = b"an espresso, downstairs";
    world.device("disk").fs.user_write("caf\u{e9}.txt", body);
    committed.note("caf\u{e9}.txt", body);

    assert!(world.settle().is_some(), "it must settle, not oscillate");
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        world.server.live_counts(),
        (0, 1),
        "one file, not one per pass"
    );
    assert_eq!(world.server.all_versions().len(), 1);
}

#[test]
fn a_composed_name_from_the_server_is_not_renamed_back_by_a_decomposing_volume() {
    // The other direction: Linux uploads the composed spelling, the other volume
    // stores it decomposed, and must not report that as the user renaming it.
    let world = World::of(
        2,
        &[("pc", Platform::Linux), ("disk", Platform::Decomposing)],
    );
    let mut committed = Committed::default();

    let body = b"resume, with accents";
    world
        .device("pc")
        .fs
        .user_write("r\u{e9}sum\u{e9}.txt", body);
    committed.note("r\u{e9}sum\u{e9}.txt", body);

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["r\u{e9}sum\u{e9}.txt".to_string()],
        "the server keeps the spelling it was given"
    );
    assert_converged(&world);
}

// ---------------------------------------------------------------------------
// macOS and Windows: two names the volume cannot tell apart
// ---------------------------------------------------------------------------

#[test]
fn an_accented_name_on_a_modern_mac_is_stored_exactly_as_written() {
    // APFS preserves normalization, so nothing should be adjusted at all — no
    // mapping, no rename, no second version. The regression this guards is
    // re-introducing the HFS+ assumption and "helpfully" rewriting names on
    // every Mac in the fleet.
    let world = World::of(10, &[("mac", Platform::MacOs)]);
    let mut committed = Committed::default();

    let body = b"an espresso, upstairs";
    world.device("mac").fs.user_write("caf\u{e9}.txt", body);
    committed.note("caf\u{e9}.txt", body);

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(server_names(&world), vec!["caf\u{e9}.txt".to_string()]);
    assert_eq!(paths(&world, "mac"), vec!["caf\u{e9}.txt".to_string()]);
    assert_eq!(world.server.all_versions().len(), 1);
    assert_converged(&world);
}

#[test]
fn a_mac_and_a_decomposing_disk_agree_about_an_accented_file() {
    // The two spellings meeting. Whatever each volume stores, both must end up
    // holding the same file and neither may push a rename at the other.
    let world = World::of(
        11,
        &[("mac", Platform::MacOs), ("disk", Platform::Decomposing)],
    );
    let mut committed = Committed::default();

    let body = b"shared between two spellings";
    world
        .device("mac")
        .fs
        .user_write("r\u{e9}sum\u{e9}.txt", body);
    committed.note("r\u{e9}sum\u{e9}.txt", body);

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["r\u{e9}sum\u{e9}.txt".to_string()]
    );
    assert_eq!(
        world.server.all_versions().len(),
        1,
        "one file, however each disk chose to spell it"
    );
    assert_converged(&world);
}

#[test]
fn a_mac_keeps_one_of_two_case_clashing_siblings_and_says_so() {
    // Both files exist on the server and neither is touched. What the Mac
    // cannot do is hold both, so it holds one and reports the other — rather
    // than materializing a mangled second name that would sync back as a rename
    // of the user's file on every device they own.
    let world = World::of(3, &[("pc", Platform::Linux), ("mac", Platform::MacOs)]);
    let mut committed = Committed::default();

    let pc = &world.device("pc").fs;
    pc.user_write("Report.txt", b"the capitalized one");
    pc.user_write("report.txt", b"the other one");
    committed.note("Report.txt", b"the capitalized one");
    committed.note("report.txt", b"the other one");

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);

    assert_eq!(
        world.server.live_counts(),
        (0, 2),
        "the server holds both; only the Mac cannot"
    );
    assert_eq!(paths(&world, "pc").len(), 2, "Linux tells them apart");
    assert_eq!(
        paths(&world, "mac").len(),
        1,
        "the Mac materializes exactly one"
    );

    let issues = world.device("mac").store.open_issues().unwrap();
    assert!(
        issues.iter().any(|i| i.kind == "unsyncable"),
        "and the user is told, rather than left to notice: {issues:?}"
    );
}

#[test]
fn the_refused_sibling_appears_by_itself_once_the_clash_is_resolved() {
    // A user who renames the offending file and sees nothing happen has no
    // reason to believe the client works.
    let world = World::of(4, &[("pc", Platform::Linux), ("mac", Platform::MacOs)]);
    let pc = &world.device("pc").fs;
    pc.user_write("Report.txt", b"the capitalized one");
    pc.user_write("report.txt", b"the other one");
    assert!(world.settle().is_some());
    assert_eq!(paths(&world, "mac").len(), 1);

    // The user renames one of them on the machine that can see both.
    pc.user_rename("report.txt", "Quarterly.txt");

    assert!(world.settle().is_some());
    assert_eq!(
        paths(&world, "mac").len(),
        2,
        "the file that was waiting materializes without anyone asking"
    );

    // And the warning goes with it. An issue about a name this disk cannot hold
    // describes a state, so when the state ends the sentence is false; leaving
    // it standing means a permanent warning about a file that is now perfectly
    // fine, clearable only by hand.
    let stale: Vec<_> = world
        .device("mac")
        .store
        .open_issues()
        .unwrap()
        .into_iter()
        .filter(|i| i.kind == "unsyncable")
        .map(|i| i.detail)
        .collect();
    assert!(
        stale.is_empty(),
        "the clash cleared but the mac still warns about it: {stale:?}"
    );

    assert_converged(&world);
}

// ---------------------------------------------------------------------------
// Windows: names the API refuses outright
// ---------------------------------------------------------------------------

#[test]
fn a_colon_in_a_server_name_is_escaped_locally_and_not_pushed_back() {
    // The escape is the easy half. The hard half is that the escaped name must
    // not read as a rename on the next scan — otherwise the PC renames the
    // user's file on the server, and the Mac dutifully renames its copy to
    // match.
    let world = World::of(5, &[("mac", Platform::MacOs), ("pc", Platform::Windows)]);
    let mut committed = Committed::default();

    let body = b"the numbers";
    world.device("mac").fs.user_write("Q3: final.xlsx", body);
    committed.note("Q3: final.xlsx", body);

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);

    assert_eq!(
        server_names(&world),
        vec!["Q3: final.xlsx".to_string()],
        "the server's name is the user's name and nothing renamed it"
    );
    assert_eq!(
        paths(&world, "pc"),
        vec!["Q3%3A final.xlsx".to_string()],
        "the PC holds a name Windows will actually accept"
    );
    assert_eq!(paths(&world, "mac"), vec!["Q3: final.xlsx".to_string()]);
}

#[test]
fn a_reserved_dos_name_is_escaped_and_stays_escaped() {
    let world = World::of(6, &[("mac", Platform::MacOs), ("pc", Platform::Windows)]);
    let mut committed = Committed::default();

    for (name, body) in [
        ("CON.txt", &b"the console, apparently"[..]),
        ("report.", &b"a trailing dot"[..]),
    ] {
        world.device("mac").fs.user_write(name, body);
        committed.note(name, body);
    }

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["CON.txt".to_string(), "report.".to_string()],
        "nothing renamed the user's files on the server"
    );

    let on_pc = paths(&world, "pc");
    assert!(
        on_pc.iter().all(|p| p.contains('%')),
        "both needed escaping to exist on Windows at all: {on_pc:?}"
    );
    assert!(
        !on_pc.iter().any(|p| p.eq_ignore_ascii_case("con.txt")),
        "an unescaped CON.txt is a device, not a file"
    );
}

#[test]
fn a_second_pass_over_escaped_names_finds_nothing_to_do() {
    // The regression this file exists to prevent: escaping that is recomputed
    // rather than remembered looks like a rename every single pass.
    let world = World::of(7, &[("pc", Platform::Windows)]);
    world.device("pc").fs.user_write("plain.txt", b"x");
    assert!(world.settle().is_some());

    let versions_before = world.server.all_versions().len();
    let mac = World::of(7, &[("mac", Platform::MacOs)]);
    drop(mac);

    for _ in 0..3 {
        let outcome = world.pass(world.device("pc"));
        assert!(
            outcome.quiet(),
            "a settled client must have nothing to say: {outcome:?}"
        );
    }
    assert_eq!(world.server.all_versions().len(), versions_before);
}

// ---------------------------------------------------------------------------
// Three operating systems, one folder
// ---------------------------------------------------------------------------

#[test]
fn a_mac_a_pc_and_a_linux_box_agree_on_everything_they_can_all_hold() {
    let world = World::of(
        8,
        &[
            ("linux", Platform::Linux),
            ("mac", Platform::MacOs),
            ("pc", Platform::Windows),
            ("disk", Platform::Decomposing),
        ],
    );
    let mut committed = Committed::default();

    let fs = &world.device("linux").fs;
    fs.user_mkdir("Projects");
    for (path, body) in [
        ("Projects/plan.txt", &b"the plan"[..]),
        ("Projects/caf\u{e9}.md", &b"notes over coffee"[..]),
        ("budget.csv", &b"1,2,3"[..]),
    ] {
        fs.user_write(path, body);
        committed.note(path, body);
    }

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_converged(&world);
}

#[test]
fn a_folder_renamed_on_a_mac_moves_its_children_on_a_pc_without_reuploading_them() {
    // Identity is the server id, not the path, on every platform. A rename that
    // degraded into delete-plus-upload would be visible here as extra versions.
    let world = World::of(9, &[("mac", Platform::MacOs), ("pc", Platform::Windows)]);
    let mut committed = Committed::default();

    let mac = &world.device("mac").fs;
    mac.user_mkdir("Old");
    for (path, body) in [("Old/a.txt", &b"first"[..]), ("Old/b.txt", &b"second"[..])] {
        mac.user_write(path, body);
        committed.note(path, body);
    }
    assert!(world.settle().is_some());
    let versions = world.server.all_versions().len();

    mac.user_rename("Old", "New");

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_converged(&world);
    assert_eq!(
        world.server.all_versions().len(),
        versions,
        "renaming a folder moves nothing"
    );
    assert!(paths(&world, "pc").iter().any(|p| p.starts_with("New/")));
}
