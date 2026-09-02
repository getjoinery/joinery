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

#[test]
fn a_decomposed_name_typed_on_a_preserving_volume_is_left_the_way_it_was_typed() {
    // The case the soak rig wedged on, and the one no scenario here covered:
    // the *user* types the decomposed spelling, on a volume that keeps whatever
    // it is given. Every test above starts from the composed form, so the only
    // decomposed names in the estate were ones a volume produced.
    //
    // Recording the composed spelling as the local name is what breaks it. The
    // file on disk keeps the spelling the user typed, nothing renames it, and
    // every pass afterwards finds the file missing from where it was recorded,
    // pairs it by content at the spelling that is really there, and calls that
    // a move — asking the server to rename the file to the name it already has.
    // The server refuses over the name, the client waits for the sibling that
    // supposedly holds it, and the pass never goes quiet again.
    let world = World::of(12, &[("pc", Platform::Linux)]);
    let mut committed = Committed::default();

    let body = b"an espresso, spelled the long way";
    world.device("pc").fs.user_write("cafe\u{301}.txt", body);
    committed.note("cafe\u{301}.txt", body);

    assert!(world.settle().is_some(), "it must settle, not oscillate");
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["cafe\u{301}.txt".to_string()],
        "the server keeps the spelling the user typed"
    );
    assert_eq!(
        paths(&world, "pc"),
        vec!["cafe\u{301}.txt".to_string()],
        "and so does the disk — the engine renames nothing"
    );
    assert_eq!(
        world.server.all_versions().len(),
        1,
        "one version, not one per pass"
    );
    assert_converged(&world);
}

#[test]
fn two_preserving_volumes_do_not_respell_a_decomposed_name_at_each_other() {
    // The same name, now with somewhere to bounce to. A respelling that only
    // one device believes in is a rename each device keeps undoing.
    let world = World::of(
        13,
        &[("pc", Platform::Linux), ("laptop", Platform::MacOs)],
    );
    let mut committed = Committed::default();

    let body = b"resume, typed the long way";
    world
        .device("pc")
        .fs
        .user_write("re\u{301}sume\u{301}.txt", body);
    committed.note("re\u{301}sume\u{301}.txt", body);

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["re\u{301}sume\u{301}.txt".to_string()]
    );
    assert_eq!(paths(&world, "laptop"), paths(&world, "pc"));
    assert_eq!(world.server.all_versions().len(), 1);
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
fn renaming_a_folder_on_a_decomposing_volume_does_not_respell_the_files_inside() {
    // A folder rename displaces every file inside it: each is at a new path
    // while its parent and name have not changed. The engine knows that a
    // move which lands where the agreement already is, is not a move -- but on
    // this volume the file's name is held under a MAPPING (the engine sees the
    // composed spelling of a name the server holds decomposed), and a rule that
    // compares against the server's spelling never matches. Every file inside
    // the renamed folder then goes up as a rename to the composed spelling,
    // which the server grants, and every other device sees the user's file
    // renamed.
    let world = World::of(
        15,
        &[("pc", Platform::Linux), ("disk", Platform::Decomposing)],
    );
    let mut committed = Committed::default();

    let body = b"typed decomposed, in a folder";
    world.device("pc").fs.user_mkdir("Sub");
    world
        .device("pc")
        .fs
        .user_write("Sub/cafe\u{301}.txt", body);
    committed.note("Sub/cafe\u{301}.txt", body);
    assert!(world.settle().is_some());
    let versions_before = world.server.all_versions().len();

    world.device("disk").fs.user_rename("Sub", "Moved");

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["Moved".to_string(), "Moved/cafe\u{301}.txt".to_string()],
        "the folder moved; the file's spelling is the server's own, untouched"
    );
    assert_eq!(
        world.server.all_versions().len(),
        versions_before,
        "no version minted by a folder rename"
    );
    assert_converged(&world);
}

#[test]
fn renaming_a_folder_on_windows_does_not_push_the_escaped_name_to_the_server() {
    // The same displacement with the other kind of mapping. A colon in a name
    // the server holds is escaped on the way down, so the file inside the
    // renamed folder wears the escape on disk; judged against the server's
    // spelling the move goes up as a rename to the escaped byte-name, and the
    // escape becomes the file's real name everywhere.
    let world = World::of(
        16,
        &[("pc", Platform::Linux), ("win", Platform::Windows)],
    );
    let mut committed = Committed::default();

    let body = b"a name Windows cannot spell, in a folder";
    world.device("pc").fs.user_mkdir("Sub");
    world.device("pc").fs.user_write("Sub/a:b.txt", body);
    committed.note("Sub/a:b.txt", body);
    assert!(world.settle().is_some());
    let versions_before = world.server.all_versions().len();

    world.device("win").fs.user_rename("Sub", "Moved");

    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["Moved".to_string(), "Moved/a:b.txt".to_string()],
        "the folder moved; the escape stayed on the disk that needs it"
    );
    assert_eq!(world.server.all_versions().len(), versions_before);
    assert_converged(&world);
}

#[test]
fn a_park_that_gave_up_a_copy_keeps_its_complaint_open() {
    // A materialized file that loses its name is parked by an OPERATION, which
    // moves the copy to the trash and raises the complaint itself; naming
    // never re-raises it, because the status already says so. That one
    // complaint has to survive every pass that follows. A withdraw rule that
    // compared it by wording against the reason on the record dismissed it on
    // the very next pass: parked, copy gone, and nothing on the issues panel.
    let world = World::of(17, &[("pc", Platform::Linux), ("mac", Platform::MacOs)]);
    let mac = world.device("mac");
    let mut committed = Committed::default();

    let pc = &world.device("pc").fs;
    pc.user_write("A.txt", b"the one that will lose its name");
    pc.user_write("b.txt", b"the one already wearing it");
    committed.note("A.txt", b"the one that will lose its name");
    committed.note("b.txt", b"the one already wearing it");
    assert!(world.settle().is_some());

    pc.user_rename("A.txt", "B.txt");
    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);

    let parked = mac
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.remote.name == "B.txt")
        .expect("the renamed file's record");
    assert!(
        matches!(parked.status, jd_core::model::LocalStatus::Unsyncable(_)),
        "the Mac cannot hold B.txt beside b.txt: {:?}",
        parked.status
    );
    let complaints = |kind: &str| {
        mac.store
            .open_issues()
            .unwrap()
            .into_iter()
            .filter(|i| i.kind == kind && i.entity == Some(parked.id))
            .count()
    };
    assert_eq!(complaints("unsyncable"), 1, "one state complaint about the parked file");
    assert_eq!(complaints("parked"), 1, "and the event: its copy went to the trash");

    for _ in 0..3 {
        world.pass(mac);
    }
    assert_eq!(
        complaints("unsyncable"),
        1,
        "the complaint is still true, so it is still open"
    );
    assert_eq!(complaints("parked"), 1, "an event is never withdrawn by a pass");
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
fn a_twin_spelling_the_disk_already_holds_is_written_down_not_pushed() {
    // Two spellings of one word are two files on the server and one slot on
    // this volume. The Mac holds one and parks the other -- and then its disk
    // comes to hold the OTHER spelling, which APFS allows and a user (or a
    // composing device) can do. The scan reads that as a rename onto the parked
    // twin's byte-name, and the server refuses it for ever, because the name is
    // another live file's. Pushed, that was one refused op and one issue per
    // pass, for good: estate seed 5096132.
    //
    // The file is where the agreement says it is; only the spelling differs,
    // and this filesystem cannot tell the two apart. So the spelling is written
    // down as a local name and nothing goes up.
    let world = World::of(14, &[("mac", Platform::MacOs)]);
    let mac = world.device("mac");
    let mut committed = Committed::default();

    let held = b"the spelling the Mac took";
    let twin = b"the spelling the Mac parked";
    world.server.seed_file(None, "caf\u{e9}-1.txt", held);
    committed.note("caf\u{e9}-1.txt", held);
    assert!(world.settle().is_some());
    world.server.seed_file(None, "cafe\u{301}-1.txt", twin);
    committed.note("cafe\u{301}-1.txt", twin);
    assert!(world.settle().is_some(), "the twin parks and the fleet settles");
    assert_eq!(paths(&world, "mac"), vec!["caf\u{e9}-1.txt".to_string()]);
    let versions_before = world.server.all_versions().len();
    let issues_before = mac.store.open_issues().unwrap().len();

    // The disk comes to hold the other spelling.
    mac.fs.user_rename("caf\u{e9}-1.txt", "cafe\u{301}-1.txt");

    assert!(
        world.settle().is_some(),
        "a rename the server refuses, pushed every pass, never settles"
    );
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["cafe\u{301}-1.txt".to_string(), "caf\u{e9}-1.txt".to_string()],
        "the server was asked for nothing"
    );
    assert_eq!(
        world.server.all_versions().len(),
        versions_before,
        "no version was minted over a spelling"
    );
    assert_eq!(
        paths(&world, "mac"),
        vec!["cafe\u{301}-1.txt".to_string()],
        "the disk keeps the spelling it has; the engine renames nothing"
    );
    let e = mac
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.remote.name == "caf\u{e9}-1.txt")
        .expect("the held file's record");
    assert_eq!(
        e.synced_placement.as_ref().map(|p| p.name.as_str()),
        Some("caf\u{e9}-1.txt"),
        "the agreement is untouched -- placement stays the server's"
    );
    assert_eq!(
        e.local_name.as_deref(),
        Some("cafe\u{301}-1.txt"),
        "the spelling is written down as what it is: a local name"
    );
    assert_eq!(
        mac.store.open_issues().unwrap().len(),
        issues_before,
        "nothing new is raised: the parked twin's one issue, not one per pass"
    );
    assert_converged(&world);

    // An edit under the recorded spelling is an edit of THIS file. A record
    // that had not written the spelling down would pair the file by content
    // alone, and content that has changed pairs with nothing: the edit would
    // read as a deletion plus a stranger.
    let edited = b"the held file, edited under its other spelling";
    mac.fs.user_write("cafe\u{301}-1.txt", edited);
    committed.note("caf\u{e9}-1.txt", edited);
    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["cafe\u{301}-1.txt".to_string(), "caf\u{e9}-1.txt".to_string()],
        "an edit is not a new file"
    );
    assert_eq!(
        world.server.all_versions().len(),
        versions_before + 1,
        "one new version, on the file that was edited"
    );
    let versions_before = world.server.all_versions().len();
    assert_converged(&world);

    // A rename the server CAN grant, made under the recorded spelling. It goes
    // up and is granted, and the record then agrees on the new name -- at which
    // point the spelling it wrote down earlier is not a fact about the disk
    // any more and must not survive. Left in place, the next scan looks for the
    // file under a name it no longer wears; unedited it is found by content
    // and the record heals, but edited it is found by neither path nor
    // content: a deletion and a stranger, for a file the user merely renamed
    // and then edited.
    let held_id = mac
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.remote.name == "caf\u{e9}-1.txt")
        .expect("the held file's record")
        .id;
    mac.fs.user_rename("cafe\u{301}-1.txt", "Caf\u{e9}-1.txt");
    // ONE pass: the rename goes up and is granted inside it. The edit below
    // lands before the next scan, which is the window in which a stale
    // spelling does its damage -- one scan later the way-back rule would have
    // healed the record, and the test would prove nothing.
    world.pass(mac);
    assert_eq!(
        server_names(&world),
        vec!["Caf\u{e9}-1.txt".to_string(), "cafe\u{301}-1.txt".to_string()],
        "the rename was granted in that pass"
    );
    let e = mac
        .store
        .get_entry(held_id)
        .unwrap()
        .expect("the same record, under its new name");
    assert_eq!(e.remote.name, "Caf\u{e9}-1.txt");
    assert_eq!(
        e.local_name, None,
        "a granted rename is the disk's exact spelling; no mapping survives it"
    );
    let capitalised = b"edited after the rename was granted";
    mac.fs.user_write("Caf\u{e9}-1.txt", capitalised);
    committed.note("Caf\u{e9}-1.txt", capitalised);
    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["Caf\u{e9}-1.txt".to_string(), "cafe\u{301}-1.txt".to_string()],
        "an edit after a granted rename is an edit, not a deletion plus a stranger"
    );
    assert_eq!(
        world.server.all_versions().len(),
        versions_before + 1,
        "one new version, on the same file"
    );
    assert_eq!(
        mac.store.get_entry(held_id).unwrap().map(|e| e.remote.name),
        Some("Caf\u{e9}-1.txt".to_string()),
        "the same entity carries the edit"
    );
    assert_eq!(
        mac.store.open_issues().unwrap().len(),
        issues_before,
        "nothing new is raised by a granted rename and an edit"
    );
    let versions_before = world.server.all_versions().len();
    assert_converged(&world);

    // And the way back. The disk returns to the server's own spelling, which
    // is not a rename either: the server already calls it that.
    mac.fs.user_rename("Caf\u{e9}-1.txt", "caf\u{e9}-1.txt");
    assert!(
        world.settle().is_some(),
        "a rename the server can grant goes up once and settles"
    );
    assert_eq!(world.server.all_versions().len(), versions_before);
    assert_eq!(paths(&world, "mac"), vec!["caf\u{e9}-1.txt".to_string()]);
    let e = mac
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.remote.name == "caf\u{e9}-1.txt")
        .expect("the held file's record");
    assert_eq!(e.local_name, None, "back in step with the server, no mapping at all");
    assert_eq!(
        mac.store.open_issues().unwrap().len(),
        issues_before,
        "nothing new is raised on the way back either"
    );
    assert_converged(&world);

    // The same edit, back under the server's spelling.
    let edited_again = b"the held file, edited under the server's own spelling";
    mac.fs.user_write("caf\u{e9}-1.txt", edited_again);
    committed.note("caf\u{e9}-1.txt", edited_again);
    assert!(world.settle().is_some());
    assert_nothing_lost(&world, &committed);
    assert_eq!(
        server_names(&world),
        vec!["cafe\u{301}-1.txt".to_string(), "caf\u{e9}-1.txt".to_string()],
        "an edit after the way back is not a new file either"
    );
    assert_eq!(world.server.all_versions().len(), versions_before + 1);
    assert_converged(&world);
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
