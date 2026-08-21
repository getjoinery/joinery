//! Whole situations, start to finish.
//!
//! Each test here is a story: somebody does something to some files on some
//! computers, the world misbehaves, and afterwards the two invariants must
//! hold. Nothing asserts on how the engine got there — only that both sides
//! ended up agreeing and that nothing anybody wrote has gone missing.
//!
//! Every failing run prints its seed. A seed is a complete reproducer: put it
//! in the frozen list at the bottom of this file and it becomes a permanent
//! regression test.

use jd_sim::scenario::{
    assert_converged, assert_invariants, assert_nothing_lost, disk_tree, Committed, World,
};
use jd_sim::{FailureKind, FsOp, NetFaults, SimRng, SimVault};

// ---------------------------------------------------------------------------
// One computer
// ---------------------------------------------------------------------------

#[test]
fn a_file_created_on_one_computer_reaches_the_server() {
    let world = World::new(1, &["laptop"]);
    let mut committed = Committed::default();

    let body = b"the first thing anybody wrote";
    world.device("laptop").fs.user_write("hello.txt", body);
    committed.note("hello.txt", body);

    assert!(world.settle().is_some(), "it should settle");
    assert_invariants(&world, &committed);
    assert_eq!(world.server.live_counts(), (0, 1));
}

#[test]
fn a_folder_of_files_arrives_with_its_shape_intact() {
    let world = World::new(2, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Projects");
    fs.user_mkdir("Projects/2026");
    for (path, body) in [
        ("Projects/notes.txt", &b"notes"[..]),
        ("Projects/2026/plan.txt", &b"the plan"[..]),
        ("Projects/2026/budget.txt", &b"numbers"[..]),
    ] {
        fs.user_write(path, body);
        committed.note(path, body);
    }

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_eq!(world.server.live_counts(), (2, 3));
}

#[test]
fn a_file_edited_locally_sends_the_new_version_and_keeps_the_old_one() {
    let world = World::new(3, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_write("draft.txt", b"first draft");
    committed.note("draft.txt", b"first draft");
    assert!(world.settle().is_some());

    fs.user_write("draft.txt", b"second draft, much better");
    committed.note("draft.txt", b"second draft, much better");
    assert!(world.settle().is_some());

    assert_invariants(&world, &committed);
    // Both versions are on the server. Editing a file has never been a reason
    // to lose what it used to say.
    assert_eq!(world.server.all_versions().len(), 2);
}

#[test]
fn an_edit_the_same_length_as_what_arrived_is_still_an_edit() {
    // The download writes the file; the user edits it before the clock has
    // moved, to a body of exactly the same length. Size, modification time and
    // file id are all unchanged, so the fingerprint is unchanged — and a hash
    // cached against what arrived answers for what the user wrote.
    //
    // The engine then sees a file that has not changed. It uploads nothing, and
    // records the entry as agreeing with the server's copy. Both sides believe
    // they are in sync while holding different bytes, and nothing ever looks
    // again, because nothing believes anything is wrong. It took two devices
    // and a rename to notice at all.
    // Passes are driven by hand rather than through `settle`, which advances
    // the clock between rounds and so hands every write a distinct time. The
    // whole point here is the write that does not get one.
    let world = World::new(77, &["laptop", "desktop"]);
    let mut committed = Committed::default();
    let laptop = world.device("laptop");
    let desktop = world.device("desktop");

    desktop.fs.user_write("notes.txt", b"aaaa");
    committed.note("notes.txt", b"aaaa");
    world.pass(desktop);
    world.pass(laptop);

    // It arrived. Now edit it, same length, at the same instant it landed.
    assert_eq!(laptop.fs.peek("notes.txt").as_deref(), Some(&b"aaaa"[..]));
    laptop.fs.user_write("notes.txt", b"bbbb");
    committed.note("notes.txt", b"bbbb");
    world.pass(laptop);

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_eq!(
        world.device("desktop").fs.peek("notes.txt").as_deref(),
        Some(&b"bbbb"[..]),
        "the edit has to reach the other computer"
    );
}

#[test]
fn a_rename_is_a_rename_and_not_a_delete_plus_an_upload() {
    let world = World::new(4, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_write("old-name.txt", b"contents that do not change");
    committed.note("old-name.txt", b"contents that do not change");
    assert!(world.settle().is_some());
    let versions_before = world.server.all_versions().len();

    fs.user_rename("old-name.txt", "new-name.txt");
    assert!(world.settle().is_some());

    assert_invariants(&world, &committed);
    assert!(world.server.tree().contains_key("new-name.txt"));
    assert_eq!(
        world.server.all_versions().len(),
        versions_before,
        "no bytes moved, so no new version — that is what makes renaming a big folder cheap"
    );
    assert_eq!(world.server.live_counts(), (0, 1), "and not a second file");
}

#[test]
fn renaming_a_folder_renames_the_folder_and_does_not_rebuild_it() {
    // The claim this defends is the one in the design: a folder of ten thousand
    // files renames in one operation. Get it wrong and the folder is re-created
    // under the new name, every file inside is re-parented one at a time, and
    // the original — with its sharing and its history — is left behind as an
    // empty shell nobody can find.
    //
    // The evidence a folder was renamed is what is inside it: renaming a folder
    // does not touch one file in it, so the files are still recognizable.
    let world = World::new(31, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Reports");
    for (path, body) in [
        ("Reports/q1.txt", &b"january through march"[..]),
        ("Reports/q2.txt", &b"april through june"[..]),
    ] {
        fs.user_write(path, body);
        committed.note(path, body);
    }
    assert!(world.settle().is_some());
    let versions_before = world.server.all_versions().len();
    let (folders_before, files_before) = world.server.live_counts();

    fs.user_rename("Reports", "Quarterly Reports");
    assert!(world.settle().is_some());

    assert_invariants(&world, &committed);
    assert_eq!(
        world.server.live_counts(),
        (folders_before, files_before),
        "one folder became one folder — no shell left behind, no files duplicated"
    );
    assert_eq!(
        world.server.all_versions().len(),
        versions_before,
        "not a byte moved"
    );
    assert!(world.server.tree().contains_key("Quarterly Reports/q1.txt"));
}

#[test]
fn a_nested_folder_rename_moves_the_whole_subtree_as_one() {
    let world = World::new(32, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Work");
    fs.user_mkdir("Work/2026");
    for (path, body) in [
        ("Work/readme.txt", &b"top level"[..]),
        ("Work/2026/plan.txt", &b"the plan"[..]),
    ] {
        fs.user_write(path, body);
        committed.note(path, body);
    }
    assert!(world.settle().is_some());
    let (folders_before, files_before) = world.server.live_counts();

    fs.user_rename("Work", "Archive");
    assert!(world.settle().is_some());

    assert_invariants(&world, &committed);
    assert_eq!(
        world.server.live_counts(),
        (folders_before, files_before),
        "the inner folder rides along; it did not move relative to its parent"
    );
    assert!(world.server.tree().contains_key("Archive/2026/plan.txt"));
}

#[test]
fn an_empty_folder_renamed_is_still_one_folder_afterwards() {
    // There is nothing inside to recognize it by, so this genuinely reads as one
    // folder removed and another created. Nothing is lost — an empty folder
    // holds nothing — and guessing from the name instead would pair two
    // unrelated folders and drag one's sharing onto the other.
    let world = World::new(33, &["laptop"]);
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Empty");
    assert!(world.settle().is_some());
    assert_eq!(world.server.live_counts(), (1, 0));

    fs.user_rename("Empty", "Still Empty");
    assert!(world.settle().is_some());

    assert_eq!(world.server.live_counts(), (1, 0));
    assert!(world.server.tree().contains_key("Still Empty"));
    assert_converged(&world);
}

#[test]
fn a_deleted_file_goes_to_the_server_trash() {
    let world = World::new(5, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_write("temp.txt", b"only wanted for a moment");
    committed.note("temp.txt", b"only wanted for a moment");
    assert!(world.settle().is_some());

    fs.user_remove("temp.txt");
    assert!(world.settle().is_some());

    assert_converged(&world);
    // Trashed, not gone. The content is still findable, which is the whole
    // point of a trash.
    assert_nothing_lost(&world, &committed);
    assert_eq!(world.server.live_counts(), (0, 0));
}

// ---------------------------------------------------------------------------
// Two computers
// ---------------------------------------------------------------------------

#[test]
fn a_file_written_on_one_computer_appears_on_the_other() {
    let world = World::new(6, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    let body = b"written here, wanted there";
    world.device("laptop").fs.user_write("shared.txt", body);
    committed.note("shared.txt", body);

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_eq!(
        world.device("desktop").fs.peek("shared.txt").unwrap(),
        body,
        "the other computer has it"
    );
}

#[test]
fn two_computers_making_the_same_folder_both_end_up_with_it() {
    // A guard, not a reproduction — and the distinction is the point.
    //
    // The soak rig found a real deadlock live on 2026-08-06: a device ended up
    // holding TWO entries for one directory, a provisional one it could never
    // create (the server refuses the duplicate name) and the server's real one
    // marked unsyncable for clashing with a name identical to its own. Neither
    // could ever resolve. 611 refused creates per folder, the queue flat for
    // fifteen minutes, the issue count past 1,300.
    //
    // This exercises the same collision through every ordering that could be
    // constructed here — simultaneous, sequenced, and with the loser cut off so
    // it mints its provisional before it can know the name is taken — and the
    // engine recovers from all of them. So this test does NOT cover the live
    // bug; it pins the paths that DO work, so a fix for the live one cannot
    // quietly break them. The live trigger is still unidentified.
    //
    // It also only became meaningful once the mock server started refusing
    // duplicate folder names the way the real one always has. Before that the
    // simulator was answering a question the real server never asks.
    let world = World::new(8_600_601, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    // Both make the folder before either has run a pass, and then the DESKTOP
    // goes first — far enough to give its directory a provisional identity and
    // to have its create refused, before it has ever heard of the laptop's.
    // That order is what the live rig produced by simple concurrency, and it is
    // the whole reproduction: a device that mints a provisional folder and only
    // afterwards learns the server already has one.
    for device in ["laptop", "desktop"] {
        let fs = &world.device(device).fs;
        fs.user_mkdir("Projects");
        let path = format!("Projects/from-{device}.txt");
        let body = format!("written on the {device}");
        fs.user_write(&path, body.as_bytes());
        committed.note(&path, body.as_bytes());
    }

    // The desktop is offline while it first notices its own folder. That is the
    // only way to get the ordering genuine concurrency produced live: a pass
    // reads the change feed BEFORE it walks the disk, so a connected device
    // always learns about the other's folder first and pairs with it cleanly.
    // Cut off, the desktop mints a provisional identity for a name it does not
    // yet know is taken.
    world.device("desktop").net.set_faults(NetFaults {
        drop_before: 1000,
        ..NetFaults::none()
    });
    world.pass(world.device("desktop"));

    // The laptop, online throughout, wins the name on the server.
    world.pass(world.device("laptop"));

    // The desktop comes back and meets a folder it already believes is its own.
    world.device("desktop").net.set_faults(NetFaults::none());

    assert!(
        world.settle().is_some(),
        "neither computer ever stopped trying: the loser of a folder-name race \
         re-plans the same create every pass instead of adopting the winner"
    );
    assert_invariants(&world, &committed);

    // One folder, and both files inside it — on the server and on both disks.
    assert_eq!(world.server.live_counts(), (1, 2));
    for device in ["laptop", "desktop"] {
        let fs = &world.device(device).fs;
        assert!(
            fs.peek("Projects/from-laptop.txt").is_some()
                && fs.peek("Projects/from-desktop.txt").is_some(),
            "{device} is missing one of the two files that went into the shared folder"
        );
    }
}

/// The live deadlock from the soak rig, reduced to its exact state, and now
/// fixed — `pass::merge_duplicate_folders` folds the two entries together
/// before naming can turn them into rivals.
///
/// Root cause, established 2026-08-06. A pass reads the change feed, then walks
/// the disk. A device that finds a directory with no matching entry mints a
/// provisional identity for it — correctly, because at feed-read time no such
/// folder existed on the server. In the window between that feed read and its
/// create landing, another device creates the same folder. The create is refused
/// ("A folder with that name already exists here") and the provisional survives.
///
/// The winner's folder then arrives in the feed as a real entry, and the device
/// is holding **two entries describing one directory**. Name resolution treats
/// them as rival siblings; `resolution_order` ranks a provisional as
/// materialized, so the provisional wins the name and the real folder is marked
/// `Unsyncable(DuplicateName)` — against a name identical to its own. Unsyncable
/// means it never materializes, so it never occupies the path, so the
/// provisional is never superseded, so it re-plans its create every pass. Live:
/// 611 refused creates per folder, the queue flat for fifteen minutes, issues
/// past 1,300, and the whole subtree beneath stranded.
///
/// The engine has no rule that a provisional entry and a remote entry at the
/// same placement are the same thing. For folders they always are — the server
/// enforces one name per parent — so the fix is to merge them before planning,
/// which also makes the state unreachable however else it might arise.
#[test]
fn a_folder_that_lost_a_creation_race_still_converges() {
    use jd_core::model::{EntityId, Entry, LocalStatus, Placement};
    use serde_json::json;

    let world = World::new(99, &["laptop"]);
    let device = world.device("laptop");

    // Another device already made Projects on the server.
    world
        .server
        .action("drive_folder_create", &json!({ "name": "Projects" }))
        .unwrap();

    // This one has the directory on disk and — the state the race leaves — a
    // provisional entry minted before it could know the name was taken.
    device.fs.user_mkdir("Projects");
    device.fs.user_write("Projects/mine.txt", b"written here");
    let id = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&Entry {
            id,
            remote: Placement {
                parent: None,
                name: "Projects".into(),
            },
            remote_content: None,
            remote_modified_time: None,
            head_change_id: 0,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: None,
            synced_placement: None,
            synced_fingerprint: None,
            local_name: None,
            status: LocalStatus::PendingUpload,
            wrapped_file_key: None,
        })
        .unwrap();

    assert!(
        world.settle().is_some(),
        "the device never stops trying: its provisional folder holds the name, \
         so the server's real folder is refused as clashing with itself, so it \
         never materializes, so the provisional is never superseded"
    );

    // One folder, and the file inside it reached the server.
    assert_eq!(world.server.live_counts(), (1, 1));
}

/// The deadlock the soak rig was left holding once duplicate names stopped
/// being possible on the server, reduced to the exact state it was found in.
///
/// Run 25, 2026-08-09. Making the server refuse a duplicate file name stopped
/// the corruption — 55 duplicate name groups became none — and turned what had
/// been a silent second file into a stuck pair: a provisional entry and the real
/// entry for one path. Naming ranks a provisional as materialized, so it takes
/// the name and the real entry is parked `Unsyncable(DuplicateName)` against a
/// name identical to its own; a pass skips an unsyncable entry, so it never
/// materializes, never occupies the path, and never supersedes the provisional,
/// whose upload the server refuses for as long as the name is taken. Nothing
/// downstream could resolve it — 12 of one device's 13 stuck entries and 17 of
/// the other's 18 were this, and in every case it was the **real** entry
/// wearing the reason.
///
/// `pass::merge_duplicate_files` folds them before naming can make them rivals,
/// exactly as `merge_duplicate_folders` has done for directories since run 3.
/// The local edit below is the part worth protecting: the fix must break the
/// deadlock *and* still send work that only this computer has.
#[test]
fn a_file_that_lost_a_naming_race_still_converges() {
    use jd_core::model::{EntityId, Entry, LocalStatus, Placement};

    let world = World::new(98, &["laptop"]);
    let device = world.device("laptop");
    let mut committed = Committed::default();

    // An ordinary file, synced the ordinary way, so the entry carries a real
    // agreement — which is what the rig's stuck entries had.
    let file_id = world
        .server
        .seed_file(None, "notes.txt", b"from the other computer");
    assert!(world.settle().is_some());

    // Now the state the race leaves behind: the entry refused against its own
    // name, and a provisional claiming the same path.
    let real = device
        .store
        .get_entry(EntityId::file(file_id))
        .unwrap()
        .expect("the seeded file should have reached the device");
    device
        .store
        .put_entry(&Entry {
            status: LocalStatus::Unsyncable(jd_vfs::UnsyncableReason::DuplicateName {
                with: "notes.txt".into(),
            }),
            ..real
        })
        .unwrap();
    let id = EntityId::file(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&Entry {
            id,
            remote: Placement {
                parent: None,
                name: "notes.txt".into(),
            },
            remote_content: None,
            remote_modified_time: None,
            head_change_id: 0,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: None,
            synced_placement: None,
            synced_fingerprint: None,
            local_name: None,
            status: LocalStatus::PendingUpload,
            wrapped_file_key: None,
        })
        .unwrap();

    // Work that exists nowhere else, written while the entry was stuck.
    let mine = b"edited here while the entry was stuck";
    device.fs.user_write("notes.txt", mine);
    committed.note("notes.txt", mine);

    assert!(
        world.settle().is_some(),
        "the computer never stops trying: its provisional holds the name, so the \
         server's real file is refused as clashing with itself, so it never \
         materializes and the provisional is never superseded"
    );
    assert_nothing_lost(&world, &committed);

    // One file, not two. The whole point of the server's uniqueness rule is that
    // a second live file with this name cannot exist, and the repair must not
    // reach for one to get unstuck.
    assert_eq!(world.server.live_counts(), (0, 1));

    // And nothing is left parked. An entry that survives as unsyncable here is
    // the deadlock wearing a different hat.
    let stuck: Vec<_> = device
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .filter(|e| matches!(e.status, LocalStatus::Unsyncable(_)))
        .map(|e| format!("{:?} {}", e.id, e.remote.name))
        .collect();
    assert!(stuck.is_empty(), "still stuck: {stuck:?}");
}

#[test]
fn work_whose_folder_is_no_longer_tracked_stops_instead_of_retrying_forever() {
    use jd_core::model::{EntityId, Entry, Placement};

    // An entry has no path for two unrelated reasons: the sync folder is not
    // there, or a folder between it and the root has gone from the store. They
    // want opposite treatment. The first is worth waiting for — a drive gets
    // plugged back in. The second is not, because no number of attempts puts a
    // missing folder back, and the next round will plan against the tree as it
    // actually is.
    //
    // Reporting both as the first is what the soak rig kept finding: operations
    // sitting on ten to seventeen attempts saying the sync folder was
    // unavailable while it sat plainly on disk, holding convergence open for
    // whole campaigns. A user would have been told their files had gone.
    let world = World::new(113, &["laptop"]);
    let device = world.device("laptop");

    let folder = world.server.seed_folder(None, "Papers");
    let file = world
        .server
        .seed_file(Some(folder), "notes.txt", b"already here");
    assert!(world.settle().is_some());

    // The hole itself, in the shape the rig produced it: the agreement names a
    // provisional folder that is not in the store. A real folder would be no
    // good here — the next index walk would fetch it back and the entry would
    // resolve again, proving nothing.
    let real = device
        .store
        .get_entry(EntityId::file(file))
        .unwrap()
        .expect("the seeded file should have reached the device");
    device
        .store
        .put_entry(&Entry {
            synced_placement: Some(Placement {
                parent: Some(-685),
                name: "notes.txt".into(),
            }),
            ..real
        })
        .unwrap();

    // Work planned before the folder went. That is the only way an operation
    // reaches the executor in this state, because a planner cannot reach an
    // entry it is unable to resolve in the first place.
    device
        .store
        .queue_op("download", EntityId::file(file), "{}", "stranded-download")
        .unwrap();

    // Deliberately not asserting that the world settles. A tracked entry with a
    // missing ancestor makes every pass re-walk the whole index by design, so
    // this device cannot go quiet while the hole is there — that is a separate
    // cost, written down in `pass.rs`, and it would mask what is being tested.
    // What matters here is the fate of the one operation.
    for _ in 0..5 {
        world.pass(device);
    }

    // Only this operation's fate is asserted. The disk copy stops being claimed
    // by an entry that can no longer be resolved, so the scan offers it up as
    // something new — real behaviour, a different question, and not this test's.
    let stuck: Vec<_> = device
        .store
        .queued_ops()
        .unwrap()
        .into_iter()
        .filter(|op| op.kind == "download" && op.entity == EntityId::file(file))
        .map(|op| format!("{} attempts={}", op.kind, op.attempts))
        .collect();
    assert!(
        stuck.is_empty(),
        "the download should have been dropped as overtaken rather than parked \
         for a retry that can never come good: {stuck:?}"
    );
}

#[test]
fn a_rename_the_server_has_already_overtaken_is_not_pushed_forever() {
    use jd_core::model::{EntityId, Entry, Placement};

    // Two computers rename one file differently. Ours was planned first, so it
    // is queued describing a journey from a name the file has already left --
    // and the name it is headed for belongs to somebody else's file by the time
    // it runs. The server is right to refuse, and no amount of waiting changes
    // its mind, so the rename has to be given up rather than retried: only then
    // can reconcile look at the two moves and decide between them.
    //
    // The soak rig found this as a move_remote on twenty-one attempts, still
    // asking for a name another file had held for hours, taking the convergence
    // invariant down with it every cycle.
    let world = World::new(131, &["laptop"]);
    let device = world.device("laptop");

    let ours = world.server.seed_file(None, "a.txt", b"ours");
    world.server.seed_file(None, "b.txt", b"somebody elses");
    assert!(world.settle().is_some());

    let entry = device
        .store
        .get_entry(EntityId::file(ours))
        .unwrap()
        .expect("the seeded file should have reached the device");

    // Our rename, recorded the way the planner records one: where the server
    // had it when we decided, and where we want it to end up.
    device
        .store
        .queue_op(
            "move_remote",
            EntityId::file(ours),
            &serde_json::json!({
                "from": { "parent": null, "name": "a.txt" },
                "parent": null,
                "name": "b.txt",
            })
            .to_string(),
            "our-rename",
        )
        .unwrap();

    // Meanwhile the other computer's rename lands, and an index walk brings it
    // home: the server no longer has this file under the name we planned from.
    device
        .store
        .put_entry(&Entry {
            remote: Placement {
                parent: None,
                name: "theirs.txt".into(),
            },
            ..entry
        })
        .unwrap();

    for _ in 0..5 {
        world.pass(device);
    }

    let stuck: Vec<_> = device
        .store
        .queued_ops()
        .unwrap()
        .into_iter()
        .filter(|op| op.kind == "move_remote" && op.entity == EntityId::file(ours))
        .map(|op| format!("{} attempts={}", op.kind, op.attempts))
        .collect();
    assert!(
        stuck.is_empty(),
        "the rename should have been given up once the server moved the file \
         out from under it: {stuck:?}"
    );
}

#[test]
fn a_rescue_does_not_claim_a_name_the_server_has_already_given_away() {
    use jd_core::model::EntityId;

    // Two computers hitting the same conflict on the same day derive the same
    // rescue name -- same file, same date, same suffix. Whichever uploads first
    // owns it. The other one lands its copy on a disk where that name happens
    // to be free, then asks the server for it forever: the file holding the
    // name has arrived and settled, so the refusal never lifts and no amount of
    // waiting changes it. The rig had a chain of these, one blocking the next,
    // at thirty-five to thirty-nine attempts.
    //
    // The name is already chosen against the disk rather than computed and
    // hoped for. It has to be chosen against the server too -- free means free
    // on both sides.
    use jd_core::model::{Entry, LocalStatus, Placement};

    let world = World::new(167, &["laptop", "desktop"]);
    let laptop = world.device("laptop");
    let desktop = world.device("desktop");

    laptop.fs.user_write("slot-1.dat", b"original");
    assert!(world.settle().is_some());

    // The exact name this device's rescue reaches for first, already spoken for
    // on the server. Out of scope so the pass leaves it alone -- what matters is
    // that it holds a name, not what else might be done about it.
    let first_choice = jd_vfs::conflict_copy_name("slot-1.dat", "2026-07-31", "desktop", 1);
    desktop
        .store
        .put_entry(&Entry {
            id: EntityId::file(90_001),
            remote: Placement {
                parent: None,
                name: first_choice.clone(),
            },
            remote_content: None,
            remote_modified_time: None,
            head_change_id: 0,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: None,
            synced_placement: None,
            synced_fingerprint: None,
            local_name: None,
            status: LocalStatus::OutOfScope,
            wrapped_file_key: None,
        })
        .unwrap();

    // A real conflict: both computers change it before either hears the other.
    laptop.fs.user_write("slot-1.dat", b"the laptop's edit");
    world
        .device("desktop")
        .fs
        .user_write("slot-1.dat", b"the desktop's edit");
    assert!(world.settle().is_some(), "the world never comes to rest");

    let on_disk = disk_tree(laptop);
    assert!(
        !on_disk.contains_key(&first_choice),
        "the rescue took a name the server had already given away, which is a \
         push that can only ever be refused"
    );
    assert!(
        on_disk.len() >= 2,
        "the losing edit has to survive somewhere: {:?}",
        on_disk.keys().collect::<Vec<_>>()
    );
}

#[test]
fn two_computers_editing_the_same_file_both_keep_their_work() {
    // The case people actually hit. Neither edit may be discarded, and both
    // computers must end up seeing the same two files.
    let world = World::new(7, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    world.device("laptop").fs.user_write("doc.txt", b"original");
    committed.note("doc.txt", b"original");
    assert!(world.settle().is_some());

    // Both edit while neither has heard from the other.
    world
        .device("laptop")
        .fs
        .user_write("doc.txt", b"the laptop's version of events");
    world
        .device("desktop")
        .fs
        .user_write("doc.txt", b"the desktop's version of events");
    committed.note("doc.txt", b"the laptop's version of events");
    committed.note("doc.txt", b"the desktop's version of events");

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert!(
        disk_tree(world.device("laptop")).len() >= 2,
        "the losing edit survives beside the winner rather than being discarded"
    );
}

#[test]
fn a_file_deleted_on_one_computer_goes_from_the_other_too() {
    let world = World::new(8, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    world
        .device("laptop")
        .fs
        .user_write("shared.txt", b"here for now");
    committed.note("shared.txt", b"here for now");
    assert!(world.settle().is_some());
    assert!(world.device("desktop").fs.peek("shared.txt").is_some());

    world.device("laptop").fs.user_remove("shared.txt");
    assert!(world.settle().is_some());

    assert_converged(&world);
    assert_nothing_lost(&world, &committed);
    assert!(world.device("desktop").fs.peek("shared.txt").is_none());
}

// ---------------------------------------------------------------------------
// A download: a file that lives for a while under a name it will not keep
// ---------------------------------------------------------------------------

#[test]
fn a_cancelled_download_does_not_leave_its_partial_on_the_server() {
    // A browser writes `thing.zip.crdownload`, the user cancels, and the
    // partial is deleted. If the client uploaded it in between, the delete has
    // to follow it: otherwise the server keeps a partial file forever, under a
    // name whose owner no longer exists on any disk, and no device will ever
    // pull it back down to notice.
    //
    // This is the shape the soak rig has been failing `audited-green` on for
    // several campaigns — one `download-001.zip.crdownload` on the server and
    // on neither device.
    let world = World::new(78, &["laptop", "desktop"]);
    let laptop = world.device("laptop");

    laptop.fs.user_write("thing.zip.crdownload", b"first 40%");
    assert!(world.settle().is_some());

    laptop.fs.user_remove("thing.zip.crdownload");
    assert!(world.settle().is_some());

    assert_converged(&world);
    assert!(
        !world.server.tree().contains_key("thing.zip.crdownload"),
        "the cancelled partial must not outlive itself on the server: {:?}",
        world.server.tree().keys().collect::<Vec<_>>()
    );
}

#[test]
fn a_finished_download_leaves_only_the_name_it_ended_up_with() {
    // The other ending: the partial is renamed to the real name once the bytes
    // are all there. One file arrives on the other computer, under the name the
    // user will actually see, and the working name does not survive anywhere.
    let world = World::new(79, &["laptop", "desktop"]);
    let laptop = world.device("laptop");
    let mut committed = Committed::default();

    laptop.fs.user_write("thing.zip.crdownload", b"all of it, eventually");
    assert!(world.settle().is_some());

    laptop
        .fs
        .user_rename("thing.zip.crdownload", "thing.zip");
    committed.note("thing.zip", b"all of it, eventually");
    assert!(world.settle().is_some());

    assert_invariants(&world, &committed);
    let names: Vec<String> = world.server.tree().into_keys().collect();
    assert_eq!(names, vec!["thing.zip".to_string()]);
    assert!(world.device("desktop").fs.peek("thing.zip").is_some());
}

#[test]
fn a_partial_whose_upload_landed_unheard_comes_back_rather_than_stranding() {
    // The upload of the partial reaches the server and the answer saying so is
    // lost, so the server holds `thing.zip.crdownload` and the client has no
    // record of it at all. The user then cancels and the partial leaves the
    // disk.
    //
    // What happens next is worth pinning down, because there are two ways to be
    // wrong and only one of them is safe. The client could decide the server
    // copy is its own orphaned upload and trash it — which means deleting
    // server content on the strength of a guess, and the guess is wrong the
    // moment somebody else put that file there. Or it can do what it does: read
    // an entity it has no record of as somebody else's, and bring it down.
    //
    // So the cancelled download reappears. That is a surprise, but it is a
    // recoverable one — the user deletes it again, this time with the client
    // watching. The alternative, stranding it on the server where no device
    // will ever pull it down, is the one that cannot be recovered from, and
    // this test exists to catch a change that trades one for the other.
    let world = World::new(81, &["laptop", "desktop"]);
    let laptop = world.device("laptop");

    laptop.net.set_faults(NetFaults {
        lose_answer_to: Some("drive_upload_complete".into()),
        ..NetFaults::none()
    });
    laptop.fs.user_write("thing.zip.crdownload", b"most of the way");
    world.pass(laptop);

    laptop.net.set_faults(NetFaults::none());
    laptop.fs.user_remove("thing.zip.crdownload");

    assert!(world.settle().is_some());
    // Whatever it decided, both sides agree about it and nobody is holding
    // something the other cannot see.
    assert_converged(&world);
    assert!(
        world
            .device("laptop")
            .fs
            .peek("thing.zip.crdownload")
            .is_some(),
        "it came back, rather than being left where nothing can reach it"
    );
}

#[test]
fn a_download_cancelled_while_its_upload_is_in_flight_still_leaves_nothing() {
    // The same cancel, with the network dropping answers underneath it — so the
    // upload may have landed on the server while the client never heard that it
    // did. The partial is gone from the disk either way, and the server must not
    // be left holding it.
    let world = World::new(80, &["laptop", "desktop"]);
    let laptop = world.device("laptop");
    laptop.net.set_faults(NetFaults::chaos());

    laptop.fs.user_write("thing.zip.crdownload", b"most of the way");
    world.pass(laptop);
    world.pass(laptop);

    laptop.net.set_faults(NetFaults::default());
    laptop.fs.user_remove("thing.zip.crdownload");
    assert!(world.settle().is_some());

    assert_converged(&world);
    assert!(
        !world.server.tree().contains_key("thing.zip.crdownload"),
        "server still holds the partial: {:?}",
        world.server.tree().keys().collect::<Vec<_>>()
    );
}

#[test]
fn a_file_deleted_before_it_ever_uploaded_leaves_nothing_needing_attention() {
    // Somebody saves a file and thinks better of it a moment later, while the
    // network happens to be down. Nothing is wrong and nothing was lost: the
    // file was never anywhere but this disk. The soak rig found the client
    // ending that story with an item asking the user to look at it.
    let world = World::new(61, &["laptop"]);
    let dev = world.device("laptop");

    dev.fs
        .user_write("fleeting.txt", b"typed, then thought better of");
    dev.net.set_faults(NetFaults {
        drop_before: u64::MAX, // nothing gets out
        ..NetFaults::none()
    });
    world.pass(dev);

    dev.fs.user_remove("fleeting.txt");
    dev.net.set_faults(NetFaults::none());
    assert!(world.settle().is_some(), "it should settle");

    assert_eq!(
        world.server.live_counts(),
        (0, 0),
        "a file deleted before its first upload never reaches the server"
    );
    let issues = dev.store.open_issues().unwrap();
    assert!(
        issues.is_empty(),
        "nothing here is the user's problem, so nothing should be raised: {issues:?}"
    );
    assert_eq!(
        dev.store.pending_op_count().unwrap(),
        0,
        "and no work is left queued for a file that no longer exists"
    );
}

#[test]
fn a_name_swap_does_not_lose_either_file() {
    // A → B while B → A. There is no order of moves that works, so the engine
    // has to park one of them. Getting this wrong loses a file outright.
    let world = World::new(9, &["laptop", "desktop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_write("a.txt", b"the contents of A");
    fs.user_write("b.txt", b"the contents of B");
    committed.note("a.txt", b"the contents of A");
    committed.note("b.txt", b"the contents of B");
    assert!(world.settle().is_some());

    fs.user_rename("a.txt", ".tmp-swap");
    fs.user_rename("b.txt", "a.txt");
    fs.user_rename(".tmp-swap", "b.txt");

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_eq!(
        world.device("laptop").fs.peek("a.txt").unwrap(),
        b"the contents of B"
    );
    assert_eq!(
        world.device("laptop").fs.peek("b.txt").unwrap(),
        b"the contents of A"
    );
}

// ---------------------------------------------------------------------------
// The world misbehaving
// ---------------------------------------------------------------------------

#[test]
fn a_hostile_network_delays_everything_and_loses_nothing() {
    let world = World::new(10, &["laptop", "desktop"]);
    let mut committed = Committed::default();
    for device in &world.devices {
        device.net.set_faults(NetFaults::chaos());
    }

    let fs = &world.device("laptop").fs;
    fs.user_mkdir("Work");
    for i in 0..6 {
        let body = format!("file number {i}");
        let path = format!("Work/f{i}.txt");
        fs.user_write(&path, body.as_bytes());
        committed.note(&path, body.as_bytes());
    }

    let rounds = world.settle();
    assert!(
        rounds.is_some(),
        "a client that never settles is a client that never stops using the network"
    );
    assert_invariants(&world, &committed);

    let stats = world.device("laptop").net.stats();
    assert!(
        stats.dropped_after > 0 || stats.server_errors > 0,
        "the test proves nothing unless the network actually misbehaved"
    );
}

#[test]
fn a_disk_that_fills_up_pauses_rather_than_corrupts() {
    let world = World::new(11, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    let body = b"a file that will take some getting to the other machine";
    world.device("laptop").fs.user_write("wanted.txt", body);
    committed.note("wanted.txt", body);

    // The receiving machine has no room, twice over.
    world
        .device("desktop")
        .fs
        .fail_next(FsOp::Commit, None, FailureKind::OutOfSpace, 2);

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_eq!(
        world.device("desktop").fs.spool_count(),
        0,
        "and nothing half-written was left behind filling the disk further"
    );
}

#[test]
fn an_unplugged_drive_is_a_pause_and_never_a_mass_delete() {
    // The single worst bug this program could have: a volume that is not
    // mounted read as "the user deleted everything", and the deletion dutifully
    // replicated to the server and every other computer.
    let world = World::new(12, &["laptop", "desktop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    for i in 0..5 {
        let body = format!("important file {i}");
        let path = format!("keep{i}.txt");
        fs.user_write(&path, body.as_bytes());
        committed.note(&path, body.as_bytes());
    }
    assert!(world.settle().is_some());
    let before = world.server.live_counts();

    // The drive goes away.
    fs.set_root_available(false);
    for _ in 0..5 {
        world.pass(world.device("laptop"));
    }
    assert_eq!(
        world.server.live_counts(),
        before,
        "nothing was deleted on the server while the drive was missing"
    );

    // And it comes back with everything still there.
    fs.set_root_available(true);
    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_eq!(world.server.live_counts(), before);
}

#[test]
fn a_change_feed_that_cannot_be_resumed_is_answered_by_looking_at_everything() {
    // A cursor pointing into history the server no longer keeps. Carrying on
    // from the new position leaves a hole in the feed, and a hole in a change
    // feed is a file that silently never syncs again.
    let world = World::new(13, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    world
        .device("laptop")
        .fs
        .user_write("early.txt", b"before the gap");
    committed.note("early.txt", b"before the gap");
    assert!(world.settle().is_some());

    // The desktop misses a stretch of history entirely.
    world
        .device("laptop")
        .fs
        .user_write("during.txt", b"during the gap");
    committed.note("during.txt", b"during the gap");
    for _ in 0..3 {
        world.pass(world.device("laptop"));
    }
    world
        .server
        .prune_feed_before(world.server.latest_change_id());

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_eq!(
        world.device("desktop").fs.peek("during.txt").unwrap(),
        b"during the gap",
        "the file the desktop never heard about arrived anyway"
    );
}

#[test]
fn a_process_killed_mid_sync_picks_up_where_it_left_off() {
    // The kill is modelled the way a kill actually works: the disk and the
    // journal survive, and everything the process was holding is gone.
    let world = World::new(14, &["laptop", "desktop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Docs");
    for i in 0..5 {
        let body = format!("document {i}");
        let path = format!("Docs/d{i}.txt");
        fs.user_write(&path, body.as_bytes());
        committed.note(&path, body.as_bytes());
    }

    // A few passes, then the machine dies partway through the work.
    for _ in 0..2 {
        world.pass(world.device("laptop"));
        world.clock.advance_secs(20 * 60);
    }
    for op in world.device("laptop").store.queued_ops().unwrap() {
        world
            .device("laptop")
            .store
            .set_op_state(op.op_id, jd_core::store::OpState::InFlight)
            .unwrap();
    }

    // Back on. Recover what was in flight, then carry on.
    let laptop = world.device("laptop");
    let now = laptop.now();
    let e = jd_sim::engine::env(laptop, &now);
    jd_core::execute::recover(&e).unwrap();

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
}

// ---------------------------------------------------------------------------
// Randomized runs
// ---------------------------------------------------------------------------

/// A random workload, driven entirely by the seed.
///
/// Everything a person might do to a folder of files, in an order nobody chose.
/// The point is to reach states no test author would think to write down —
/// which is where the bugs that lose files actually live.
fn random_scenario(seed: u64, steps: usize, devices: &[&str], chaos: bool) {
    let world = World::new(seed, devices);
    let committed = Committed::default();
    let mut rng = SimRng::new(seed ^ 0xA5A5_A5A5);
    if chaos {
        for device in &world.devices {
            device.net.set_faults(NetFaults::chaos());
        }
    }

    let mut paths: Vec<String> = Vec::new();
    let mut dirs: Vec<String> = vec![String::new()];

    for step in 0..steps {
        let device = &world.devices[rng.below(world.devices.len() as u64) as usize];
        match rng.below(10) {
            // Write a new file.
            0..=3 => {
                let dir = rng.pick(&dirs).cloned().unwrap_or_default();
                let name = format!("f{step}.txt");
                let path = if dir.is_empty() {
                    name
                } else {
                    format!("{dir}/{name}")
                };
                let body = format!("content {step} from {}", device.name);
                device.fs.user_write(&path, body.as_bytes());
                paths.push(path);
            }
            // Edit one that exists.
            4..=5 => {
                if let Some(path) = rng.pick(&paths).cloned() {
                    if device.fs.exists(&path) {
                        let body = format!("edit {step} from {}", device.name);
                        device.fs.user_write(&path, body.as_bytes());
                    }
                }
            }
            // Rename one.
            6 => {
                if let Some(path) = rng.pick(&paths).cloned() {
                    if device.fs.exists(&path) {
                        let to = format!("renamed{step}.txt");
                        device.fs.user_rename(&path, &to);
                        paths.push(to);
                    }
                }
            }
            // Delete one.
            7 => {
                if let Some(path) = rng.pick(&paths).cloned() {
                    if device.fs.exists(&path) {
                        device.fs.user_remove(&path);
                    }
                }
            }
            // Make a folder.
            8 => {
                let name = format!("dir{step}");
                device.fs.user_mkdir(&name);
                dirs.push(name);
            }
            // Let things sync for a bit.
            _ => {
                world.clock.advance_secs(20 * 60);
                world.pass(device);
            }
        }
    }

    let settled = world.settle();
    assert!(
        settled.is_some(),
        "seed {seed} never settled — rerun with this seed to reproduce"
    );
    // No-loss is enforced continuously by World::pass, around every pass this
    // scenario ran. What is left to check here is that everything the SERVER
    // was ever given is still reachable, and that the devices agree.
    assert_nothing_lost(&world, &committed);
    assert_converged(&world);
}

#[test]
fn random_workloads_on_a_clean_network() {
    // A spread of seeds rather than one, because a single seed only ever
    // explores one path through the space.
    for seed in 100..160 {
        random_scenario(seed, 24, &["laptop", "desktop"], false);
    }
}

#[test]
fn random_workloads_on_a_hostile_network() {
    // The whole range, including 216, 225 and 229, which spent a long time in
    // an ignored list described as a naming race. They were nothing of the
    // kind: each ended with the server holding a file one device had not caught
    // up to yet, because the harness counted a pass that could not reach the
    // server as a pass with nothing to do, and two of those in a row read as
    // settled. See `World::attempt_pass`.
    for seed in 200..232 {
        random_scenario(seed, 18, &["laptop", "desktop"], true);
    }
}

/// Seeds that once found a real bug.
///
/// These are frozen deliberately. The RNG's output stream is pinned by a test
/// in `rng.rs`, so a seed recorded here reproduces the same run in a year — and
/// that is the only reason writing it down is worth anything.
#[test]
fn frozen_regression_seeds() {
    // Each of these once failed for a reason worth never meeting again.
    let seeds: &[(u64, usize, usize, bool)] = &[
        // Found the pairing hole: a locally created file was invisible to the
        // scanner, so every pass minted it a fresh identity and uploaded it
        // again — one duplicate per pass, and a client that never went quiet.
        (1136, 46, 2, true),
        // Found the rename ping-pong: the local path was derived from where the
        // SERVER wanted the file, so an unapplied remote rename read as a local
        // rename the other way and the two devices renamed at each other
        // forever.
        (105, 24, 2, false),
        // Found the stale move source: both sides moved a file, the server won,
        // and the executor looked for it at the agreed path it had already left.
        (1071, 21, 3, false),
        // Found the hash cache vouching for bytes it had not read: a download,
        // then an edit of the same length inside the same tick of the clock,
        // and the fingerprint never moved. The edit was never uploaded and the
        // entry recorded itself as agreeing with a file it did not match.
        (350, 24, 2, false),
        (845, 30, 3, false),
    ];
    for (seed, steps, count, chaos) in seeds {
        let names: Vec<&str> = ["a", "b", "c"][..*count].to_vec();
        random_scenario(*seed, *steps, &names, *chaos);
    }
}

// ---------------------------------------------------------------------------
// Encrypted entries this build cannot open
// ---------------------------------------------------------------------------

/// The server holds a file encrypted; this build has no decryption path.
///
/// The failure this guards is not a crash — it is the engine quietly succeeding
/// at the wrong thing. For an encrypted file the server sends a PLACEHOLDER
/// name and the hash of the CIPHERTEXT. An engine that reads those as ordinary
/// facts downloads happily and leaves the user a file of unreadable bytes under
/// a name they never chose, reported as synced.
#[test]
fn an_encrypted_file_is_never_materialized_as_ciphertext() {
    let world = World::new(9_101, &["laptop"]);
    let committed = Committed::default();

    // What the SERVER sees: a placeholder name, and bytes it cannot read.
    world.server.seed_encrypted_file(
        None,
        "encrypted-file-88",
        b"\x91\x02ciphertext-not-text\xff",
    );

    assert!(world.settle().is_some(), "it should settle");

    let disk = disk_tree(world.device("laptop"));
    assert!(
        disk.is_empty(),
        "nothing should have been written for an encrypted file, found: {disk:?}"
    );
    assert_nothing_lost(&world, &committed);
}

/// The same protection in the other direction, which is the one that leaks.
///
/// An encrypted FOLDER that materialized locally would be an ordinary directory
/// to the user — so anything they dropped in it would be uploaded as plaintext
/// into a folder they believe is encrypted. Keeping the folder off the disk is
/// what makes that impossible rather than merely unlikely.
#[test]
fn an_encrypted_folder_never_becomes_a_plaintext_drop_box() {
    let world = World::new(9_102, &["laptop"]);
    let committed = Committed::default();

    let vault = world.server.seed_encrypted_folder(None, "Private");
    world
        .server
        .seed_encrypted_file(Some(vault), "encrypted-file-89", b"\x00ciphertext\x7f");

    assert!(world.settle().is_some(), "it should settle");

    let disk = disk_tree(world.device("laptop"));
    assert!(
        disk.is_empty(),
        "an encrypted folder must not materialize, found: {disk:?}"
    );
    assert_nothing_lost(&world, &committed);
}

/// A plaintext file alongside an encrypted one still syncs.
///
/// Refusing the encrypted entry must not become refusing the account: the whole
/// point of a per-entry verdict is that everything else carries on.
#[test]
fn plaintext_files_still_sync_beside_an_encrypted_one() {
    let world = World::new(9_103, &["laptop"]);
    let mut committed = Committed::default();

    world
        .server
        .seed_encrypted_file(None, "encrypted-file-90", b"\xc3ciphertext\x01");
    let body = b"an ordinary file, readable by everyone who should read it";
    world.server.seed_file(None, "notes.txt", body);
    committed.note("notes.txt", body);

    assert!(world.settle().is_some(), "it should settle");

    let disk = disk_tree(world.device("laptop"));
    assert!(
        disk.contains_key("notes.txt"),
        "the plaintext file should have arrived, found: {disk:?}"
    );
    assert_eq!(disk.len(), 1, "only the plaintext file, found: {disk:?}");
    assert_nothing_lost(&world, &committed);
}

/// The invariant that has to survive the decrypt path being built.
///
/// Once this device can open encrypted files it will start writing them, so
/// "nothing on disk" stops being the right assertion. What must never become
/// true, in any phase, is the specific wrong outcome: the server's bytes,
/// written under the server's name. The server's name is a placeholder and its
/// bytes are ciphertext — a disk holding that pair is a disk holding a file
/// nobody can read, under a name nobody chose, reported as synced.
#[test]
fn an_encrypted_file_is_never_written_under_the_name_the_server_uses_for_it() {
    let vault = SimVault::new(9_104);
    let mut world = World::new(9_104, &["laptop"]);
    world.give_vault("laptop", &vault);
    let committed = Committed::default();

    let ciphertext = b"\x11ciphertext the server cannot read\x99";
    let id = world
        .server
        .seed_encrypted_file(None, "encrypted-file-91", ciphertext);
    // A real grant, sealed to this device's real vault key. A stub that always
    // opened would test the one thing that must not be a stub.
    world
        .server
        .grant_file_key(id, &vault.grant(&jd_crypto::drive::FileKey::generate()));

    assert!(world.settle().is_some(), "it should settle");

    let disk = disk_tree(world.device("laptop"));
    assert!(
        !disk.contains_key("encrypted-file-91"),
        "the server's placeholder name must never appear on disk, found: {disk:?}"
    );
    let ciphertext_hash = jd_sim::sha256_hex(ciphertext);
    assert!(
        !disk
            .values()
            .any(|h| h.as_deref() == Some(&ciphertext_hash)),
        "the ciphertext must never appear on disk under any name, found: {disk:?}"
    );
    assert_nothing_lost(&world, &committed);
}

/// One laptop can open the encrypted folder and another cannot.
///
/// The asymmetry is the point: a device that was linked without encrypted
/// folders is not a broken device. Everything else on the account has to keep
/// converging on both of them, or turning the feature on for one machine would
/// quietly cost the user their sync on every other one.
#[test]
fn a_device_without_the_vault_key_still_syncs_everything_else() {
    let vault = SimVault::new(9_105);
    let mut world = World::new(9_105, &["with-key", "without-key"]);
    world.give_vault("with-key", &vault);
    let mut committed = Committed::default();

    let id = world
        .server
        .seed_encrypted_file(None, "encrypted-file-92", b"\x42ciphertext\x24");
    world
        .server
        .grant_file_key(id, &vault.grant(&jd_crypto::drive::FileKey::generate()));

    let body = b"a plaintext file both of them should end up holding";
    world.server.seed_file(None, "shared.txt", body);
    committed.note("shared.txt", body);

    assert!(world.settle().is_some(), "it should settle");

    for name in ["with-key", "without-key"] {
        let disk = disk_tree(world.device(name));
        assert!(
            disk.contains_key("shared.txt"),
            "{name} should hold the plaintext file, found: {disk:?}"
        );
        assert!(
            !disk.contains_key("encrypted-file-92"),
            "{name} must not hold the placeholder name, found: {disk:?}"
        );
    }
    assert_nothing_lost(&world, &committed);
}

// ---------------------------------------------------------------------------
// Encrypted content, end to end
// ---------------------------------------------------------------------------

/// A file in a vault arrives readable, under the name its owner gave it.
///
/// Everything the server holds for this file is wrong on purpose: the name is a
/// placeholder, the bytes are ciphertext, the hash is of the ciphertext, and
/// there is no modification time at all. The only place the truth exists is
/// inside the encrypted metadata, and the only thing that can open it is the
/// vault key on this device.
#[test]
fn a_file_in_a_vault_arrives_decrypted_under_its_real_name() {
    let vault = SimVault::new(9_201);
    let mut world = World::new(9_201, &["laptop"]);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    let mut committed = Committed::default();

    let private = world.server.seed_encrypted_folder(None, "Private");
    let body = b"the contents of a file nobody else is meant to read";
    world
        .server
        .seed_vault_file(Some(private), "secrets.txt", body, &vault.public_key_b64);
    committed.note("Private/secrets.txt", body);

    assert!(world.settle().is_some(), "it should settle");

    let disk = disk_tree(world.device("laptop"));
    assert_eq!(
        disk.get("Private/secrets.txt").cloned().flatten(),
        Some(jd_sim::sha256_hex(body)),
        "the plaintext should be on disk under its real name, found: {disk:?}"
    );
    assert_nothing_lost(&world, &committed);
}

/// A file dropped into a vault folder goes up encrypted, or it does not go up.
///
/// This is the leak the whole design is arranged around. The user sees an
/// ordinary folder; what leaves the machine must not be an ordinary file.
#[test]
fn a_file_dropped_into_a_vault_folder_never_leaves_as_plaintext() {
    let vault = SimVault::new(9_202);
    let mut world = World::new(9_202, &["laptop"]);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    let mut committed = Committed::default();

    world.server.seed_encrypted_folder(None, "Private");
    assert!(world.settle().is_some(), "the folder should arrive");

    let body = b"a memo the server must never be able to read";
    world
        .device("laptop")
        .fs
        .user_write("Private/memo.txt", body);
    committed.note("Private/memo.txt", body);

    assert!(world.settle().is_some(), "the upload should settle");

    // The plaintext must not exist anywhere the server can reach.
    assert!(
        world.server.blob(&jd_sim::sha256_hex(body)).is_none(),
        "the plaintext bytes reached the server"
    );
    let names = world.server.tree();
    assert!(
        !names.keys().any(|p| p.ends_with("memo.txt")),
        "the real name reached the server: {names:?}"
    );
    // ...and the file is still the user's file, on disk, unchanged.
    let disk = disk_tree(world.device("laptop"));
    assert_eq!(
        disk.get("Private/memo.txt").cloned().flatten(),
        Some(jd_sim::sha256_hex(body))
    );
    assert_nothing_lost(&world, &committed);
}

/// A chunk corrupted on the way up never becomes a file nobody can open.
///
/// Encryption removes the check that catches this everywhere else. A plaintext
/// upload declares its content hash and the server refuses the assembled bytes
/// if they do not match; an encrypted one used to declare nothing, on the
/// reasoning that a plaintext hash would tell the server what it is holding and
/// would collide with somebody else's file in the dedup table. Both are true of
/// the PLAINTEXT hash. The ciphertext's gives away nothing the server does not
/// already have and can collide with nothing, because every encryption uses
/// fresh IVs.
///
/// Without it the failure is total and silent: the file lands, it lists, every
/// device agrees it is there, and not one of them can ever open it. The vault
/// sweep found this on 389 of 400 hostile seeds, each device retrying a
/// download some three hundred times and reporting only that decryption failed.
#[test]
fn a_corrupted_chunk_never_becomes_an_encrypted_file_nobody_can_open() {
    let vault = SimVault::new(9_301);
    let mut world = World::new(9_301, &["laptop"]);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    world.server.seed_encrypted_folder(None, "Private");
    assert!(world.settle().is_some(), "the vault folder should arrive");

    // Every chunk arrives wrong. The upload cannot succeed, and the whole
    // question is what it leaves behind when it does not.
    world.device("laptop").net.set_faults(NetFaults {
        corrupt_chunk: 1000,
        ..NetFaults::none()
    });
    let body = b"a memo whose bytes are mangled on every attempt";
    world.device("laptop").fs.user_write("Private/memo.txt", body);
    world.settle();

    jd_sim::scenario::assert_the_vault_opens(&world);

    // And the local file is untouched -- a refused upload is not a lost file.
    assert_eq!(
        disk_tree(world.device("laptop"))
            .get("Private/memo.txt")
            .cloned()
            .flatten(),
        Some(jd_sim::sha256_hex(body)),
        "the user's file should still be exactly where they put it"
    );
}

/// A lost answer at the end of an encrypted upload does not make a second file.
///
/// What stops a lost completion answer from duplicating a file is dedup at
/// init: the retry declares the same content hash, the server recognizes bytes
/// it already has, and answers with the file rather than taking a second copy.
/// An encrypted upload never takes that path -- dedup is skipped for a vault
/// destination, and could not work anyway, because a retry re-encrypts with
/// fresh IVs and so declares a hash the server has never seen.
///
/// So the retry runs the whole upload again against a server that already did
/// it. Inside a vault nothing downstream catches the result: the stored title
/// is a per-file opaque id, unique by construction, so the two copies do not
/// even collide by name. The user gets two files called `notes.txt` in one
/// folder, on every device, and no device can put both of them at that path.
#[test]
fn a_lost_completion_answer_does_not_duplicate_an_encrypted_file() {
    let vault = SimVault::new(9_302);
    let mut world = World::new(9_302, &["laptop"]);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    world.server.seed_encrypted_folder(None, "Private");
    assert!(world.settle().is_some(), "the vault folder should arrive");

    world.device("laptop").net.set_faults(NetFaults {
        lose_answer_to: Some("drive_upload_complete".into()),
        ..NetFaults::none()
    });
    let body = b"a note whose upload lands and whose answer does not come back";
    world.device("laptop").fs.user_write("Private/notes.txt", body);
    assert!(world.settle().is_some(), "it should settle");

    // The fault has to have fired, or this proves nothing: a test that passes
    // because nothing happened is worse than no test.
    assert_eq!(
        world.device("laptop").net.stats().dropped_after,
        1,
        "the completion answer should have been lost exactly once"
    );

    let names: Vec<String> = world
        .server
        .vault_files()
        .iter()
        .filter_map(|f| jd_sim::scenario::what_the_vault_really_holds(&world, f).map(|(n, _)| n))
        .collect();
    assert_eq!(
        names,
        vec!["notes.txt".to_string()],
        "one upload should leave one file -- the answer was lost, not the bytes"
    );
}

/// The same fault on a plaintext upload, which is the control.
///
/// This is what dedup at init buys, and having it beside the encrypted case is
/// what makes the difference between them a finding rather than an assumption.
#[test]
fn a_lost_completion_answer_does_not_duplicate_a_plaintext_file() {
    let mut world = World::new(9_303, &["laptop"]);
    world.server.seed_folder(None, "Work");
    assert!(world.settle().is_some(), "the folder should arrive");

    world.device("laptop").net.set_faults(NetFaults {
        lose_answer_to: Some("drive_upload_complete".into()),
        ..NetFaults::none()
    });
    let body = b"an ordinary note whose answer goes missing";
    world.device("laptop").fs.user_write("Work/notes.txt", body);
    assert!(world.settle().is_some(), "it should settle");

    assert_eq!(
        world.device("laptop").net.stats().dropped_after,
        1,
        "the completion answer should have been lost exactly once"
    );

    let names: Vec<String> = world
        .server
        .tree()
        .into_iter()
        .filter(|(_, hash)| hash.is_some())
        .map(|(path, _)| path)
        .collect();
    assert_eq!(
        names,
        vec!["Work/notes.txt".to_string()],
        "one upload should leave one file"
    );
}

/// Two computers, one vault: what one encrypts the other can read.
///
/// The round trip is the real test of the format. A client that encrypts and
/// decrypts consistently with itself can still be wrong in a way that only
/// shows up when the bytes take the long way round.
#[test]
fn what_one_computer_encrypts_another_can_read() {
    let vault = SimVault::new(9_203);
    let mut world = World::new(9_203, &["desktop", "laptop"]);
    world.give_vault("desktop", &vault);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    let mut committed = Committed::default();

    world.server.seed_encrypted_folder(None, "Private");
    assert!(world.settle().is_some());

    let body = b"written on the desktop, opened on the laptop, never read in between";
    world
        .device("desktop")
        .fs
        .user_write("Private/plan.md", body);
    committed.note("Private/plan.md", body);

    assert!(world.settle().is_some(), "it should settle");

    for name in ["desktop", "laptop"] {
        let disk = disk_tree(world.device(name));
        assert_eq!(
            disk.get("Private/plan.md").cloned().flatten(),
            Some(jd_sim::sha256_hex(body)),
            "{name} should hold the plaintext, found: {disk:?}"
        );
    }
    assert_nothing_lost(&world, &committed);
}

/// An edit becomes a new version under the same key.
///
/// The server refuses a fresh key on a version, and it is right to: a new key
/// would leave the new content readable only by whoever uploaded it, behind
/// grants that every other device holds and that all wrap the old one. So this
/// settling at all is the assertion.
#[test]
fn editing_a_file_in_a_vault_keeps_the_key_it_already_had() {
    let vault = SimVault::new(9_204);
    let mut world = World::new(9_204, &["desktop", "laptop"]);
    world.give_vault("desktop", &vault);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    let mut committed = Committed::default();

    world.server.seed_encrypted_folder(None, "Private");
    let first = b"the first draft";
    world
        .device("desktop")
        .fs
        .user_write("Private/draft.txt", first);
    committed.note("Private/draft.txt", first);
    assert!(world.settle().is_some());

    let second = b"the second draft, which says something quite different";
    world
        .device("desktop")
        .fs
        .user_write("Private/draft.txt", second);
    committed.note("Private/draft.txt", second);
    assert!(world.settle().is_some(), "the new version should settle");

    let disk = disk_tree(world.device("laptop"));
    assert_eq!(
        disk.get("Private/draft.txt").cloned().flatten(),
        Some(jd_sim::sha256_hex(second)),
        "the other computer should hold the newer draft, found: {disk:?}"
    );
    assert_nothing_lost(&world, &committed);
}

/// An untouched encrypted file does not re-sync itself forever.
///
/// The trap this guards: the server's hash is of ciphertext and the disk's is
/// of plaintext, so an engine that compares the two across domains reports an
/// edit on every pass for a file nobody has opened — downloading it again, and
/// again, and never settling. `settle()` returning at all is the assertion; the
/// pass count is what makes it a sharp one.
#[test]
fn an_untouched_encrypted_file_settles_and_stays_settled() {
    let vault = SimVault::new(9_205);
    let mut world = World::new(9_205, &["laptop"]);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);

    let private = world.server.seed_encrypted_folder(None, "Private");
    world.server.seed_vault_file(
        Some(private),
        "quiet.txt",
        b"nothing about this file ever changes",
        &vault.public_key_b64,
    );

    assert!(world.settle().is_some(), "it should settle");
    // A second settle from a converged state must find nothing to do at all.
    let passes = world.settle().expect("still settled");
    assert!(
        passes <= 2,
        "a converged encrypted file kept the client working for {passes} passes"
    );
}

/// A vault the device cannot open is not a vault it may write into.
#[test]
fn a_device_without_the_key_will_not_upload_into_a_vault_folder() {
    let vault = SimVault::new(9_206);
    let mut world = World::new(9_206, &["with-key", "without-key"]);
    world.give_vault("with-key", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);

    world.server.seed_encrypted_folder(None, "Private");
    assert!(world.settle().is_some());

    // The vault folder exists on the device that can encrypt, and must not
    // exist on the one that cannot — there is nowhere for it to put a file.
    assert!(
        disk_tree(world.device("with-key"))
            .keys()
            .any(|p| p.starts_with("Private"))
            || world
                .device("with-key")
                .fs
                .all_paths()
                .iter()
                .any(|p| p == "Private")
    );
    let blind = world.device("without-key").fs.all_paths();
    assert!(
        !blind.iter().any(|p| p == "Private"),
        "a device that cannot encrypt must not hold the vault folder: {blind:?}"
    );
}

/// Renaming a file in a vault never tells the server its new name.
///
/// The server refuses a plaintext name on an encrypted file, so getting this
/// wrong is a rename that fails forever rather than a leak — but a rename that
/// fails forever is a file that stops syncing, and the user is never told why.
/// What actually has to happen: decrypt the metadata, change one field,
/// encrypt it again under the same key, and hand over the blob.
#[test]
fn renaming_a_file_in_a_vault_re_encrypts_its_name_rather_than_sending_it() {
    let vault = SimVault::new(9_207);
    let mut world = World::new(9_207, &["desktop", "laptop"]);
    world.give_vault("desktop", &vault);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    let mut committed = Committed::default();

    let private = world.server.seed_encrypted_folder(None, "Private");
    let body = b"a file that is about to be called something else";
    world
        .server
        .seed_vault_file(Some(private), "old name.txt", body, &vault.public_key_b64);
    committed.note("Private/old name.txt", body);
    assert!(world.settle().is_some(), "it should arrive first");

    world
        .device("desktop")
        .fs
        .user_rename("Private/old name.txt", "Private/new name.txt");

    assert!(world.settle().is_some(), "the rename should settle");

    // The new name must not be anywhere the server can read it.
    let names = world.server.tree();
    assert!(
        !names.keys().any(|p| p.contains("new name")),
        "the new name reached the server in the clear: {names:?}"
    );
    // ...and the other computer must nevertheless know about it.
    let disk = disk_tree(world.device("laptop"));
    assert!(
        disk.contains_key("Private/new name.txt"),
        "the other computer should have picked up the rename, found: {disk:?}"
    );
    assert!(!disk.contains_key("Private/old name.txt"));
    assert_nothing_lost(&world, &committed);
}

#[test]
fn a_conflict_is_reported_by_the_conflict_and_not_by_the_download_that_hit_it() {
    // A download refused because the file changed underneath is not itself
    // anything to tell somebody. What matters is the conflict it ran into, and
    // that has its own wording. Written to settle whether the refusal needs to
    // speak for itself: if this ever stops holding, it does.
    let world = World::new(62, &["laptop", "desktop"]);
    world.device("laptop").fs.user_write("doc.txt", b"original");
    assert!(world.settle().is_some());

    world
        .device("laptop")
        .fs
        .user_write("doc.txt", b"the laptop's version");
    world
        .device("desktop")
        .fs
        .user_write("doc.txt", b"the desktop's version");
    assert!(world.settle().is_some());

    let told: Vec<_> = world
        .devices
        .iter()
        .flat_map(|d| d.store.open_issues().unwrap())
        .collect();
    assert!(
        told.iter().any(|i| i.kind == "reconcile"),
        "the conflict itself is reported: {told:?}"
    );
}

#[test]
fn a_folder_deleted_before_it_ever_reached_the_server_takes_its_files_with_it() {
    // The shape a soak run ended in: thirty-two files in pending_upload with no
    // operation queued and nothing raised. A folder the server had never seen
    // was removed from the disk, its entry was dropped as never-existed, and
    // the entries for the files inside it were left naming a parent that had
    // gone. A pass builds an entry's path by walking parents to the root, so
    // those files had no path — and a walk from the root could not even see
    // them, so nothing could ever notice.
    let world = World::new(63, &["laptop"]);
    let committed = Committed::default();
    let dev = world.device("laptop");

    dev.net.set_faults(NetFaults {
        drop_before: u64::MAX,
        ..NetFaults::none()
    });
    dev.fs.user_mkdir("Projects");
    dev.fs.user_write("Projects/plan.txt", b"the plan");
    dev.fs.user_write("Projects/budget.txt", b"the numbers");
    world.pass(dev); // mints provisional entries; nothing can land

    // Thrown away again before any of it reached the server. Nothing here was
    // ever committed anywhere else, so nothing is lost by it going.
    dev.fs.user_remove("Projects/plan.txt");
    dev.fs.user_remove("Projects/budget.txt");
    dev.fs.user_remove("Projects");

    dev.net.set_faults(NetFaults::none());
    assert!(world.settle().is_some(), "it should settle");

    assert_invariants(&world, &committed);
    assert_eq!(
        world.server.live_counts(),
        (0, 0),
        "none of it ever reached the server"
    );
    assert!(
        dev.store.open_issues().unwrap().is_empty(),
        "and none of it is anybody's problem"
    );
}

#[test]
fn an_entry_whose_parent_has_gone_is_cleaned_up_rather_than_stranded_forever() {
    // However it arises, an entry naming a parent that is not in the store has
    // no path, and a pass finds work by resolving paths. A soak run ended with
    // thirty-two files exactly there: pending_upload, no operation queued,
    // nothing raised, on a device reporting itself busy rather than broken.
    //
    // The state is built directly rather than provoked, because the orderings
    // that produce it are the kind that shift under you — and what has to hold
    // is the recovery, not the route in.
    let world = World::new(64, &["laptop"]);
    let committed = Committed::default();
    let dev = world.device("laptop");

    dev.fs.user_write("real.txt", b"an ordinary file");
    assert!(world.settle().is_some());

    let ghost_folder = jd_core::EntityId::folder(dev.store.next_provisional_id().unwrap());
    let orphan = jd_core::EntityId::file(dev.store.next_provisional_id().unwrap());
    let mut entry = dev
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.id.entity_type == jd_core::EntityType::File)
        .expect("the ordinary file");
    entry.id = orphan;
    entry.remote = jd_core::Placement {
        parent: Some(ghost_folder.server_id),
        name: "stranded.txt".into(),
    };
    entry.synced_placement = None;
    entry.synced_content = None;
    entry.synced_fingerprint = None;
    entry.status = jd_core::LocalStatus::PendingUpload;
    dev.store.put_entry(&entry).unwrap();

    assert!(
        dev.store
            .every_entry()
            .unwrap()
            .iter()
            .any(|e| e.local_placement().parent == Some(ghost_folder.server_id)),
        "an entry now names a parent that does not exist, which is the state under test"
    );

    assert!(world.settle().is_some(), "it should still settle");
    assert_invariants(&world, &committed);
    assert!(
        dev.store.open_issues().unwrap().is_empty(),
        "nothing here was ever anywhere but this store"
    );
}

#[test]
fn a_folder_whose_ancestor_is_missing_is_re_derived_rather_than_left_uploading_forever() {
    // Taken from run 8 of the soak rig, which spent three campaigns failing to
    // converge on it. A device held folder "Sub 13" whose own parent was not in
    // the store at all, and a file beneath it retried an upload into a path
    // that could not be resolved — forever, and silently, because the pass
    // finds entries by walking down from the root and could not reach it.
    //
    // The entry is real: the server has the folder. So the answer is not to
    // drop it but to re-derive from the index, the same thing a feed reset does.
    let world = World::new(65, &["laptop"]);
    let mut committed = Committed::default();
    let dev = world.device("laptop");

    dev.fs.user_mkdir("Work");
    dev.fs.user_mkdir("Work/Reports");
    dev.fs
        .user_write("Work/Reports/q1.txt", b"january through march");
    committed.note("Work/Reports/q1.txt", b"january through march");
    assert!(world.settle().is_some());

    // Lose the record of the outer folder, exactly as the rig found it.
    let outer = dev
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.remote.name == "Work")
        .expect("the outer folder");
    dev.store.delete_entry(outer.id).unwrap();
    assert!(
        dev.store
            .every_entry()
            .unwrap()
            .iter()
            .any(|e| e.local_placement().parent == Some(outer.id.server_id)),
        "an entry the server knows about now has no way back to the root"
    );

    assert!(world.settle().is_some(), "it should settle");
    assert_invariants(&world, &committed);
    assert!(
        world.server.tree().contains_key("Work/Reports/q1.txt"),
        "and the file is still where it was: {:?}",
        world.server.tree().keys().collect::<Vec<_>>()
    );
    assert!(
        dev.store.open_issues().unwrap().is_empty(),
        "a store that repaired itself is not something to bother anybody with"
    );
}

#[test]
fn a_second_conflict_on_one_file_does_not_overwrite_the_first_rescue() {
    // conflict_copy_name takes a suffix so repeats within a day can be told
    // apart, and for a long time every caller passed 1. Both places that rescue
    // a losing local version land it with a plain rename, so the second rescue
    // of one file on one day destroyed the first — in the two functions whose
    // whole purpose is not losing the user's work.
    let world = World::new(66, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    world.device("laptop").fs.user_write("doc.txt", b"original");
    committed.note("doc.txt", b"original");
    assert!(world.settle().is_some());

    for (mine, theirs) in [
        (&b"laptop's first go"[..], &b"desktop's first go"[..]),
        (&b"laptop's second go"[..], &b"desktop's second go"[..]),
    ] {
        world.device("laptop").fs.user_write("doc.txt", mine);
        world.device("desktop").fs.user_write("doc.txt", theirs);
        committed.note("doc.txt", mine);
        committed.note("doc.txt", theirs);
        assert!(world.settle().is_some());
    }

    // Both rescues have to still exist. assert_nothing_lost checks the content
    // is findable somewhere; this checks it is findable *on the disk*, which is
    // where a conflicted copy is supposed to be.
    let tree = disk_tree(world.device("laptop"));
    let rescues = tree
        .keys()
        .filter(|p| p.contains("conflicted copy"))
        .count();
    assert!(
        rescues >= 2,
        "each conflict keeps its own copy; found {rescues} in {:?}",
        tree.keys().collect::<Vec<_>>()
    );
    assert_invariants(&world, &committed);
}

#[test]
fn a_rescued_copy_is_recorded_under_the_name_it_actually_landed_on() {
    // preserve_local_as takes its name from the plan, and the plan cannot see
    // the disk — so when that path is already taken by an earlier rescue it
    // picks another one. For a long time it renamed the file to the new path and
    // then recorded the entry under the PLANNED name, which by then belonged to
    // the earlier rescue's file on the server. That entry uploaded under a name
    // the server already had, and a server without a uniqueness rule took it:
    // two live files, one name, and no device able to materialize both. It is
    // where the soak rig's 55 duplicate names came from.
    //
    // The assertion is not "it settles" — it is that no name is claimed twice.
    let world = World::new(66, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    world.device("laptop").fs.user_write("doc.txt", b"original");
    committed.note("doc.txt", b"original");
    assert!(world.settle().is_some());

    // Two conflicts on one file on one day, which is what forces the second
    // rescue onto a path the first one already holds.
    for (mine, theirs) in [
        (&b"laptop one"[..], &b"desktop one"[..]),
        (&b"laptop two"[..], &b"desktop two"[..]),
    ] {
        world.device("laptop").fs.user_write("doc.txt", mine);
        world.device("desktop").fs.user_write("doc.txt", theirs);
        committed.note("doc.txt", mine);
        committed.note("doc.txt", theirs);
        assert!(world.settle().is_some(), "it should settle");
    }

    for name in ["laptop", "desktop"] {
        let device = world.device(name);
        let mut claimed: Vec<String> = Vec::new();
        for entry in device.store.every_entry().unwrap() {
            // A trashed entry is not claiming a name any more.
            if entry.remote_deleted {
                continue;
            }
            let claim = format!("{:?}/{}", entry.remote.parent, entry.remote.name);
            assert!(
                !claimed.contains(&claim),
                "{name} has two entries both claiming {claim}"
            );
            claimed.push(claim);
        }
    }

    // And the server took each of them exactly once. `tree()` is keyed by path,
    // so a duplicate would be invisible here — the count is what shows it.
    let tree = world.server.tree();
    let (folders, files) = world.server.live_counts();
    assert_eq!(
        tree.len(),
        folders + files,
        "the server holds more live entities than it has distinct paths, so two \
         of them share one: {:?}",
        tree.keys().collect::<Vec<_>>()
    );
    assert_invariants(&world, &committed);
}

#[test]
fn a_folder_going_to_the_trash_does_not_take_unuploaded_work_with_it() {
    // Trashing a folder is a single rename and everything underneath goes with
    // it, including files the engine has no record of. Run 22 of the soak rig
    // did exactly that: files written while a daemon was dead were swept into
    // the system trash when the folder they sat in was removed from the other
    // device — never uploaded, and gone from the user's tree without the user
    // having deleted anything.
    let world = World::new(67, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    world.device("laptop").fs.user_mkdir("Projects");
    world
        .device("laptop")
        .fs
        .user_write("Projects/keep.txt", b"this one reached the server");
    committed.note("Projects/keep.txt", b"this one reached the server");
    assert!(world.settle().is_some());

    // The laptop keeps working with nothing getting out, so this file exists
    // in exactly one place in the world.
    let laptop = world.device("laptop");
    laptop.net.set_faults(NetFaults {
        drop_before: u64::MAX,
        ..NetFaults::none()
    });
    laptop
        .fs
        .user_write("Projects/fresh.txt", b"never uploaded anywhere");
    committed.note("Projects/fresh.txt", b"never uploaded anywhere");
    world.pass(laptop);

    // Meanwhile somebody deletes the whole folder from the other computer.
    let desktop = world.device("desktop");
    desktop.fs.user_remove("Projects/keep.txt");
    desktop.fs.user_remove("Projects");
    world.pass(desktop);

    laptop.net.set_faults(NetFaults::none());
    assert!(world.settle().is_some(), "it should settle");

    // The never-uploaded file has to still be on the disk. assert_invariants
    // counts the trash as somewhere content may legitimately be, so it would
    // pass on a file swept away with the folder — which is the whole thing
    // this test exists to catch.
    let tree = disk_tree(laptop);
    let fresh = jd_sim::sha256_hex(b"never uploaded anywhere");
    let found: Vec<&String> = tree
        .iter()
        .filter(|(_, hash)| hash.as_deref() == Some(fresh.as_str()))
        .map(|(path, _)| path)
        .collect();
    assert!(
        !found.is_empty(),
        "work that never reached the server must be rescued out of a folder \
         being trashed, not go with it; disk holds {:?}",
        tree.keys().collect::<Vec<_>>()
    );
    assert!(
        laptop
            .store
            .open_issues()
            .unwrap()
            .iter()
            .any(|i| i.kind == "rescued_from_trash"),
        "and the user is told it moved, rather than being left to find it by \
         accident"
    );

    // Deliberately the two narrower assertions rather than assert_invariants.
    // The server really did delete the ancestor here, so the entry left naming
    // it is the state the engine already surfaces as store_inconsistent and
    // then deliberately keeps — discarding those records was tried once and
    // cost seven files. Holding this scenario to assert_no_entry_is_stranded
    // would be holding the engine to a stricter policy than the one it chose.
    assert_nothing_lost(&world, &committed);
    assert_converged(&world);
}

#[test]
fn a_file_moved_here_and_deleted_there_is_rescued_from_where_it_actually_is() {
    // The delete loses to the move, so the file goes back up at the place the
    // user put it. The rescue is an upload of a *new* server entity, and the
    // executor has to read the bytes off this disk to send them.
    //
    // It looked for them at the last agreed placement — which is precisely the
    // empty spot the user moved the file out of. Finding nothing there it
    // reported the operation overtaken and dropped it, leaving the entry
    // untouched, so the next pass planned the same rescue and the pass after
    // that. No error, no queued work, no end: the client never went quiet
    // again. Eight of a hundred and sixty random workloads ended that way.
    let world = World::new(84, &["laptop", "desktop"]);
    let mut committed = Committed::default();
    let laptop = world.device("laptop");
    let desktop = world.device("desktop");

    laptop.fs.user_mkdir("Archive");
    laptop.fs.user_write("note.txt", b"worth keeping");
    committed.note("note.txt", b"worth keeping");
    assert!(world.settle().is_some());
    assert!(desktop.fs.peek("note.txt").is_some());

    // The laptop goes quiet and files the note away.
    laptop.net.set_faults(NetFaults {
        drop_before: u64::MAX,
        ..NetFaults::none()
    });
    laptop.fs.user_rename("note.txt", "Archive/note.txt");

    // Meanwhile the desktop deletes it.
    desktop.fs.user_remove("note.txt");
    world.pass(desktop);

    laptop.net.set_faults(NetFaults::none());
    assert!(world.settle().is_some(), "it has to stop, not plan forever");

    assert_invariants(&world, &committed);
    assert!(
        world.server.tree().contains_key("Archive/note.txt"),
        "the rescued file should be on the server where the user put it: {:?}",
        world.server.tree().keys().collect::<Vec<_>>()
    );
}

#[test]
fn a_file_deleted_before_this_device_ever_fetched_it_stops_being_tracked() {
    // The laptop hears about a file and its download does not get through. The
    // file is then deleted on the server, so there is nothing left to fetch and
    // nothing here to remove — the record is the only trace of it left.
    //
    // The engine used to plan a local trash for it anyway. The executor found no
    // file, called the operation overtaken and dropped it, and nothing touched
    // the entry — so the next pass planned the same trash, forever, with the
    // entry sitting in `pending_download` waiting for bytes that no longer
    // existed. Silent, free, and endless, except that a device holding one never
    // reports itself settled again. The soak rig failed convergence on every
    // cycle of every campaign over six files in exactly this state.
    // The state is written straight into the store rather than raced into
    // existence. Getting there for real takes a download deferred behind a
    // folder that has not materialized and a delete arriving in the gap — the
    // soak rig reaches it a few times a campaign and it is not worth staging
    // here. What matters is what the engine does once a device holds one, and
    // that does not depend on how it arrived.
    let world = World::new(82, &["laptop", "desktop"]);
    let laptop = world.device("laptop");

    let id = world.server.seed_file(None, "doomed.txt", b"here and then not");
    world.pass(laptop);
    laptop.fs.user_remove("doomed.txt");
    world.pass(laptop);

    let orphan = jd_core::model::Entry {
        id: jd_core::model::EntityId::file(id + 5000),
        remote: jd_core::model::Placement {
            // In a folder this device has no record of — which is how the soak
            // rig's six always looked, the whole subtree having been deleted
            // before any of it materialized. It matters: with no chain of
            // folders to build a path from, the trash has nowhere to look, and
            // the executor used to return on that without dropping the record.
            parent: Some(999_111),
            name: "never-arrived.txt".into(),
        },
        remote_content: Some(jd_core::model::ContentId {
            sha256: "0".repeat(64),
            size: 12,
        }),
        remote_modified_time: None,
        head_change_id: 1,
        remote_deleted: true,
        is_encrypted: false,
        content_id: None,
        synced_remote_content: None,
        synced_content: None,
        synced_placement: None,
        synced_fingerprint: None,
        local_name: None,
        status: jd_core::model::LocalStatus::PendingDownload,
        wrapped_file_key: None,
    };
    laptop.store.put_entry(&orphan).unwrap();
    assert!(
        !orphan.is_established(),
        "the premise: it never landed on this disk"
    );

    assert!(world.settle().is_some());
    assert_converged(&world);

    let waiting: usize = laptop
        .store
        .status_counts()
        .unwrap()
        .into_iter()
        .filter(|(state, _)| {
            !matches!(
                state.as_str(),
                "synced" | "out_of_scope" | "unsyncable" | "pending_key"
            )
        })
        .map(|(_, n)| n)
        .sum();
    assert_eq!(
        waiting, 0,
        "the laptop is still carrying work for a file that no longer exists: {:?}",
        laptop.store.status_counts().unwrap()
    );
}

#[test]
fn work_landing_in_a_folder_another_device_just_deleted_is_not_left_on_the_server() {
    // The delete/create race, from the server's side. One device writes into a
    // folder while another puts that folder in the trash, and whichever order
    // the two requests arrive in, the server must not end up holding the new
    // item live underneath a trashed parent. Run 27 of the soak rig ended with
    // seventeen entries per device stuck pending_download forever because of
    // exactly this, and the account behind it had accumulated eighty-three live
    // orphans over four days — every one of them created after its parent had
    // already gone to the trash.
    let world = World::new(71, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    world.device("laptop").fs.user_mkdir("Projects");
    world
        .device("laptop")
        .fs
        .user_write("Projects/plan.txt", b"already agreed");
    committed.note("Projects/plan.txt", b"already agreed");
    assert!(world.settle().is_some());

    // The laptop goes quiet and keeps working inside Projects: a new file and a
    // new subfolder, neither of which the server has heard about.
    let laptop = world.device("laptop");
    laptop.net.set_faults(NetFaults {
        drop_before: u64::MAX,
        ..NetFaults::none()
    });
    laptop.fs.user_mkdir("Projects/Drafts");
    laptop
        .fs
        .user_write("Projects/late.txt", b"written into a doomed folder");
    committed.note("Projects/late.txt", b"written into a doomed folder");
    world.pass(laptop);

    // Meanwhile the desktop deletes the whole folder.
    let desktop = world.device("desktop");
    desktop.fs.user_remove("Projects/plan.txt");
    desktop.fs.user_remove("Projects");
    world.pass(desktop);

    // The laptop comes back and tries to land its work into a folder that is
    // now in the server's trash.
    laptop.net.set_faults(NetFaults::none());
    assert!(world.settle().is_some(), "it should settle");

    // The point of the test: the server refused, so there is nothing live
    // hidden under the trashed folder. assert_invariants checks this too now,
    // but naming it here says which failure this scenario is about.
    let orphans = world.server.live_orphans();
    assert!(
        orphans.is_empty(),
        "the server took a write into a trashed folder: {orphans:?}"
    );

    // And the refusal must not have cost the user the file. It never reached
    // the server, so the rescue is the only thing keeping it.
    let tree = disk_tree(laptop);
    let late = jd_sim::sha256_hex(b"written into a doomed folder");
    assert!(
        tree.values().any(|h| h.as_deref() == Some(late.as_str())),
        "work refused by the server must still be on the disk that made it; \
         disk holds {:?}",
        tree.keys().collect::<Vec<_>>()
    );
}

#[test]
fn tombstones_for_a_deleted_subtree_do_not_keep_the_store_looking_broken() {
    use jd_core::model::Entry;
    // A record of something the server has deleted, whose parent record is gone
    // from the store and gone from the server's index too, was counted as a
    // hole in the store. That count starts a full index walk, the walk cannot
    // supply a parent the server no longer has, and the next pass counts it
    // again — a full walk of every entity, every pass, for the life of the
    // client, plus a standing claim that items need the user's attention when
    // every one of them is already deleted.
    //
    // Run 28 of the soak rig ended with both devices there: five complaints on
    // one and three on the other, and every entry behind them carrying
    // remote_deleted.
    let world = World::new(73, &["laptop"]);
    let mut committed = Committed::default();

    let laptop = world.device("laptop");
    laptop.fs.user_mkdir("Projects");
    laptop
        .fs
        .user_write("Projects/doc.txt", b"deep in a subtree");
    laptop.fs.user_write("keep.txt", b"survives all this");
    committed.note("keep.txt", b"survives all this");
    assert!(world.settle().is_some());

    // Find the folder and the file under it, then force the shape the rig hit:
    // the file's record marked deleted, and the folder's record gone entirely.
    // Reaching it through the front door needs a purge racing a walk; the state
    // is what this test is about, not the route to it.
    let entries = laptop.store.every_entry().unwrap();
    let folder = entries
        .iter()
        .find(|e| e.id.entity_type == jd_core::EntityType::Folder && e.remote.name == "Projects")
        .expect("the folder should be tracked")
        .id;
    let file = entries
        .iter()
        .find(|e| e.id.entity_type == jd_core::EntityType::File && e.remote.name == "doc.txt")
        .expect("the file should be tracked")
        .clone();

    laptop
        .store
        .put_entry(&Entry {
            remote_deleted: true,
            ..file
        })
        .unwrap();
    laptop.store.delete_entry(folder).unwrap();

    // And the server forgets them completely, the way the retention purge does
    // once something has been in the trash long enough. This is what makes the
    // record unaccountable rather than merely trashed: no index walk can bring
    // the parent back, so nothing the engine does resolves it.
    assert!(
        world.server.forget_folder(folder.server_id),
        "the server should have had this folder to forget"
    );

    // Now run passes and watch for a device that keeps calling itself broken.
    for _ in 0..4 {
        world.pass(laptop);
    }

    let complaints: Vec<_> = laptop
        .store
        .open_issues()
        .unwrap()
        .into_iter()
        .filter(|i| i.kind == "store_inconsistent")
        .map(|i| i.detail)
        .collect();
    assert!(
        complaints.is_empty(),
        "the device calls its own store broken over a record of something the \
         server deleted, and will do so on every pass forever: {complaints:?}"
    );

    // And the file that had nothing to do with any of this is still fine.
    assert_nothing_lost(&world, &committed);
}

#[test]
fn an_issue_about_a_state_is_withdrawn_when_the_state_ends() {
    use jd_core::model::Entry;

    // An issue that reports a *state* — this name cannot be held here, these
    // items have no way back to the root — stops being true when the state
    // ends, and the pass that re-derives the state is the thing that knows.
    // Nothing else withdraws it: until this, the only way an issue ever cleared
    // was the user waving it away by hand, one row at a time. Run 28 of the
    // soak rig finished with a device reporting an unsyncable name while no
    // entry on it was unsyncable at all, and run 29 with a device insisting
    // items were stranded when none were.
    let world = World::new(79, &["laptop"]);
    let mut committed = Committed::default();

    let laptop = world.device("laptop");
    laptop.fs.user_mkdir("Projects");
    laptop
        .fs
        .user_write("Projects/doc.txt", b"ordinary content");
    laptop.fs.user_write("keep.txt", b"survives all this");
    committed.note("keep.txt", b"survives all this");
    committed.note("Projects/doc.txt", b"ordinary content");
    assert!(world.settle().is_some());

    // Force the stranded state, exactly as the tombstone scenario does, and let
    // a pass notice it.
    let entries = laptop.store.every_entry().unwrap();
    let folder = entries
        .iter()
        .find(|e| e.id.entity_type == jd_core::EntityType::Folder && e.remote.name == "Projects")
        .expect("the folder should be tracked")
        .id;
    let file = entries
        .iter()
        .find(|e| e.id.entity_type == jd_core::EntityType::File && e.remote.name == "doc.txt")
        .expect("the file should be tracked")
        .clone();
    let file_id = file.id;
    laptop.store.put_entry(&Entry { ..file }).unwrap();
    laptop.store.delete_entry(folder).unwrap();
    assert!(world.server.forget_folder(folder.server_id));

    world.pass(laptop);
    let complained = laptop
        .store
        .open_issues()
        .unwrap()
        .iter()
        .any(|i| i.kind == "store_inconsistent");
    assert!(
        complained,
        "the device should say so while items really are stranded"
    );

    // Now put the store back in order: the entry that had nowhere to go is
    // gone, so nothing is stranded any more.
    laptop.store.delete_entry(file_id).unwrap();
    world.pass(laptop);

    let stale: Vec<_> = laptop
        .store
        .open_issues()
        .unwrap()
        .into_iter()
        .filter(|i| i.kind == "store_inconsistent")
        .map(|i| i.detail)
        .collect();
    assert!(
        stale.is_empty(),
        "the complaint outlived the state it described, and only the user could \
         have cleared it: {stale:?}"
    );

    assert_nothing_lost(&world, &committed);
}

#[test]
fn a_file_two_people_filed_the_same_way_stops_holding_its_old_name() {
    // Two people tidy the same document into the same folder. That is not a
    // conflict — both sides want it in exactly the same place — and the engine
    // is right to move nothing. What it also has to do is write down where the
    // file now lives, and this is the case where it did not.
    //
    // The damage is not to that file, which is fine on every disk. It is to the
    // NEXT file: the engine goes on believing the moved document still occupies
    // its old path, so anything that legitimately arrives there is treated as a
    // duplicate of a file that is not there, parked, and never uploaded. On a
    // settled tree nothing ever changes to release it.
    let world = World::new(101, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    let laptop = world.device("laptop");
    let desktop = world.device("desktop");

    laptop.fs.user_mkdir("Inbox");
    laptop.fs.user_mkdir("Filed");
    laptop.fs.user_write("Inbox/report.txt", b"the quarterly report");
    committed.note("Inbox/report.txt", b"the quarterly report");
    assert!(world.settle().is_some(), "the setup should settle");

    // Both of them file it, into the same folder, before either has heard about
    // the other.
    desktop.fs.user_rename("Inbox/report.txt", "Filed/report.txt");
    laptop.fs.user_rename("Inbox/report.txt", "Filed/report.txt");

    // The desktop's move reaches the server first, so when the laptop next runs
    // it sees its own move and the server's as one and the same move.
    world.clock.advance_secs(60);
    world.pass(desktop);
    world.clock.advance_secs(60);
    world.pass(laptop);

    // Somebody now starts a new report where the old one used to be.
    laptop.fs.user_write("Inbox/report.txt", b"the next quarter");
    committed.note("Inbox/report.txt", b"the next quarter");

    assert!(
        world.settle().is_some(),
        "the new file has to reach the server; if the moved one is still \
         claiming that name it never will"
    );
    assert_invariants(&world, &committed);

    let tree = world.server.tree();
    assert!(
        tree.contains_key("Inbox/report.txt"),
        "the new report never reached the server: {:?}",
        tree.keys().collect::<Vec<_>>()
    );
    assert!(tree.contains_key("Filed/report.txt"), "and so did the old one");
    for device in [laptop, desktop] {
        let disk = disk_tree(device);
        assert!(
            disk.iter().any(|(p, _)| p == "Inbox/report.txt"),
            "{} never saw the new report: {:?}",
            device.name,
            disk.iter().map(|(p, _)| p).collect::<Vec<_>>()
        );
    }
}

#[test]
fn a_file_already_on_disk_is_adopted_rather_than_shadowed_by_a_new_one_every_pass() {
    // An entry the server has told this device about, whose file is already
    // sitting at the path it belongs at, with no agreement recorded between
    // them. It is reachable in ordinary use -- a download that landed while the
    // record of it did not, or an agreement cleared because the folder it named
    // had gone.
    //
    // The scan deliberately does not look for a file belonging to an entry that
    // has never been downloaded: there is no local file to have moved away
    // from, and counting one would read the entry as deleted. The cost, when
    // the file IS there, is that nothing claims it -- so it reads as brand new,
    // gets a fresh identity, and the duplicate-file repair folds that identity
    // away again. The real entry is untouched, and the next pass does it all
    // over. Nothing is queued, nothing is wrong, and the client never goes
    // quiet: the soak rig ran four consecutive cycles with the same two entries
    // pending and the provisional beside them renumbered every time.
    let world = World::new(202, &["laptop", "desktop"]);
    let mut committed = Committed::default();

    let body = b"the file that is already here";
    world.device("laptop").fs.user_write("report.txt", body);
    committed.note("report.txt", body);
    assert!(world.settle().is_some(), "the setup should settle");

    // Take the agreement away from the desktop, leaving the file where it is.
    let desktop = world.device("desktop");
    let entry = desktop
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| e.remote.name == "report.txt")
        .expect("the desktop should be tracking it");
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
    // And give the file a fingerprint nothing has seen, so the hash cache
    // cannot quietly answer the question the scan is failing to ask. That is
    // the state on the rig: no cached fingerprint for it under any identity.
    desktop.fs.set_mtime_ns("report.txt", 99_000_000_000);

    let before = desktop.store.next_provisional_id().unwrap();
    assert!(
        world.settle().is_some(),
        "it has to come back to rest; the file is on the disk and on the server \
         and the two agree"
    );
    let after = desktop.store.next_provisional_id().unwrap();

    assert_invariants(&world, &committed);
    assert_eq!(
        desktop
            .store
            .get_entry(id)
            .unwrap()
            .expect("the entry should still be there")
            .status,
        jd_core::model::LocalStatus::Synced,
        "the file that was already there should have been adopted"
    );
    assert!(
        (before - after).abs() <= 2,
        "a new identity was minted for the same file over and over: {before} to {after}"
    );
    assert_eq!(
        world.server.live_counts(),
        (0, 1),
        "and exactly one file on the server, not one per pass"
    );
}

#[test]
fn a_download_for_a_file_the_server_has_lost_stops_being_planned() {
    // The server is asked for a file and says it has no such file. The download
    // is abandoned -- correctly, there is nothing to fetch -- but the record
    // that asked for it is left exactly as it was, so the next pass reads the
    // same entry, wants the same bytes, and asks again. Nothing is queued
    // between passes and no attempt count ever rises, so it looks like a
    // perfectly idle client that simply never goes quiet.
    //
    // The soak rig has this on a live device: a download journalled, run,
    // and gone within seconds, over and over, with the entry never gaining an
    // agreement and `attempts` never leaving zero.
    let world = World::new(303, &["laptop"]);
    let mut committed = Committed::default();

    let laptop = world.device("laptop");
    laptop.fs.user_write("real.txt", b"a file that does exist");
    committed.note("real.txt", b"a file that does exist");
    assert!(world.settle().is_some(), "the setup should settle");

    // A record for a file the server has never heard of.
    let ghost = jd_core::model::EntityId::file(987_654);
    laptop
        .store
        .put_entry(&jd_core::model::Entry {
            id: ghost,
            remote: jd_core::model::Placement {
                parent: None,
                name: "ghost.txt".into(),
            },
            remote_content: Some(jd_core::model::ContentId {
                sha256: jd_sim::sha256_hex(b"bytes nobody has"),
                size: 16,
            }),
            remote_modified_time: None,
            head_change_id: 0,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: None,
            synced_placement: None,
            synced_fingerprint: None,
            local_name: None,
            status: jd_core::model::LocalStatus::PendingDownload,
            wrapped_file_key: None,
        })
        .unwrap();

    assert!(
        world.settle().is_some(),
        "a file the server does not have cannot be fetched, and asking again \
         forever is not a plan"
    );
    assert_nothing_lost(&world, &committed);
}

#[test]
fn bytes_on_this_disk_survive_the_server_losing_the_file_they_belonged_to() {
    // The other half of the same rule, and the half that matters. Writing down
    // that the server has lost a file hands the entry to the deletion path --
    // so that path had better rescue what is still on this disk rather than
    // tidy the record away and leave the user's only copy untracked.
    let world = World::new(304, &["laptop"]);
    let mut committed = Committed::default();

    let laptop = world.device("laptop");
    laptop.fs.user_write("keep.txt", b"an unrelated file");
    committed.note("keep.txt", b"an unrelated file");
    assert!(world.settle().is_some());

    let orphan_body = b"the only copy of this is right here";
    laptop.fs.user_write("orphan.txt", orphan_body);
    committed.note("orphan.txt", orphan_body);

    // A record claiming those bytes belong to a server file that does not exist.
    let ghost = jd_core::model::EntityId::file(987_655);
    laptop
        .store
        .put_entry(&jd_core::model::Entry {
            id: ghost,
            remote: jd_core::model::Placement {
                parent: None,
                name: "orphan.txt".into(),
            },
            remote_content: Some(jd_core::model::ContentId {
                sha256: jd_sim::sha256_hex(b"whatever the server was said to hold"),
                size: 36,
            }),
            remote_modified_time: None,
            head_change_id: 0,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: None,
            synced_placement: None,
            synced_fingerprint: None,
            local_name: None,
            status: jd_core::model::LocalStatus::PendingDownload,
            wrapped_file_key: None,
        })
        .unwrap();

    assert!(world.settle().is_some(), "it has to settle");
    assert_invariants(&world, &committed);
    // Checked against the live tree rather than `locate`, which answers
    // `ServerHistory` for anything ever uploaded and so cannot tell "the user
    // can still open it" from "it is only in the version log".
    let want = jd_sim::sha256_hex(orphan_body);
    let live = world.server.tree();
    assert!(
        live.values().any(|h| h.as_deref() == Some(want.as_str())),
        "the bytes the user still had should be a file on the server, not just \
         a line in its history: {:?}",
        live.keys().collect::<Vec<_>>()
    );
}

#[test]
fn a_device_goes_quiet_even_when_the_user_keeps_saving_mid_upload() {
    // The user is still working while the fleet syncs, which is the ordinary
    // case and not an exotic one: applications autosave on a timer, and they
    // save by renaming a new inode over the path, so a file being uploaded can
    // acquire new bytes partway through.
    //
    // Nothing here is at risk of loss — the bytes that landed are a real
    // version and the newer ones go up next pass. What was at risk is the
    // device ever reporting itself finished. An upload that ends with the file
    // changed used to record no agreement at all, and an entry with no agreed
    // placement is one the scanner is never offered, so the file on the disk
    // belonged to nothing: every pass read it as new, minted an identity,
    // uploaded it, got back the id this entry already had, folded the new
    // record away and left this one exactly as it was. Queue empty, tree
    // correct, forever busy. A whole soak campaign ran that way.
    let mut world = World::new(4242, &["laptop", "desktop"]);
    world.user_saves_during_uploads(1, 8);

    let laptop = world.device("laptop");
    laptop.fs.user_mkdir("Work");
    for i in 0..6 {
        laptop
            .fs
            .user_write(&format!("Work/doc-{i}.txt"), format!("draft {i}").as_bytes());
    }

    assert!(
        world.settle().is_some(),
        "a device whose user keeps saving must still reach a fixed point"
    );
    assert_converged(&world);
}

#[test]
#[ignore]
fn zzz_seed_search() {
    let mut bad = Vec::new();
    for seed in 4000u64..4200 {
        let r = std::panic::catch_unwind(move || {
            let mut world = World::new(seed, &["laptop", "desktop"]);
            world.user_saves_during_uploads(1, 8);
            let laptop = world.device("laptop");
            laptop.fs.user_mkdir("Work");
            for i in 0..6 {
                laptop.fs.user_write(&format!("Work/doc-{i}.txt"), format!("draft {i}").as_bytes());
            }
            world.settle().is_some()
        });
        match r {
            Ok(true) => {}
            _ => bad.push(seed),
        }
    }
    println!("STALLING SEEDS: {bad:?}");
}

#[test]
fn a_folder_whose_name_a_file_took_stops_being_trashed_forever() {
    // One device makes a folder; on the other, the user has already saved a
    // FILE by that name. A real disk holds one or the other, never both, so
    // the fleet has to pick -- and it does: the folder is moved aside under a
    // conflicted-copy name and everybody ends up with the same tree.
    //
    // What did not end was the tidying up afterwards. The folder that lost its
    // name is trashed on the server, so every device must remove it locally --
    // but by then the name belongs to the file, and a folder cannot be read to
    // rescue what is inside it when it is a file. The op failed, backed off,
    // and came due again forever. The plan was empty and the trees agreed, so
    // nothing looked wrong except that no device ever reported itself finished.
    let world = World::new(4242, &["laptop", "desktop"]);

    let laptop = world.device("laptop");
    laptop.fs.user_mkdir("Report");
    laptop.fs.user_write("Report/notes.txt", b"a child of the folder");

    let desktop = world.device("desktop");
    desktop.fs.user_write("Report", b"the user's own notes, never uploaded");

    assert!(
        world.settle().is_some(),
        "the fleet has to reach a fixed point, not merely the right tree"
    );
    assert_converged(&world);

    for name in ["laptop", "desktop"] {
        let d = world.device(name);
        assert!(
            d.store.queued_ops().unwrap().is_empty(),
            "{name} is still holding work it can never finish"
        );
    }

    // Which of the two keeps the plain name is the conflict rule's business.
    // What matters here is that neither was destroyed to settle the argument:
    // the user's file and the folder's child both survive, on both devices.
    for name in ["laptop", "desktop"] {
        let paths = world.device(name).fs.all_paths();
        assert!(
            paths.iter().any(|p| world.device(name).fs.peek(p).as_deref()
                == Some(b"the user's own notes, never uploaded".as_slice())),
            "{name} lost the file the user saved: {paths:?}"
        );
        assert!(
            paths.iter().any(|p| p.ends_with("/notes.txt")),
            "{name} lost the folder's child: {paths:?}"
        );
    }
}

#[test]
fn a_save_in_the_window_a_download_is_landing_in_is_not_built_over() {
    // The narrowest window the engine has. It clears a path for an incoming
    // file, and between clearing it and putting the file down, the user saves.
    // `OsVfs` refuses a commit with no agreement onto an occupied path for
    // exactly this reason -- "under a storm they do" -- and until the mock grew
    // a hook for it, no test could stand in that window at all.
    //
    // What must hold is custody, not any particular winner: the bytes the user
    // typed are the only copy in existence, so they have to be somewhere when
    // the dust settles, whichever file ends up wearing the plain name.
    let mut world = World::new(4242, &["laptop", "desktop"]);
    {
        let laptop = world.device("laptop");
        laptop.fs.user_mkdir("Work");
        for i in 0..6 {
            laptop
                .fs
                .user_write(&format!("Work/doc-{i}.txt"), format!("draft {i}").as_bytes());
        }
    }
    // Settle first, so the next round of downloads lands on occupied paths --
    // an empty path has no window to save into.
    assert!(
        world.settle().is_some(),
        "the fleet settles before the interesting part"
    );

    world.user_saves_while_downloads_land(1, 12);
    {
        let laptop = world.device("laptop");
        for i in 0..6 {
            laptop.fs.user_write(
                &format!("Work/doc-{i}.txt"),
                format!("second draft {i}").as_bytes(),
            );
        }
    }

    assert!(
        world.settle().is_some(),
        "a device whose user saves into that window must still reach a fixed point"
    );
    assert_converged(&world);

    // Whether each save survived is settled per pass, inside the custody
    // check, where the engine's removals can still be told from the user's.
    // What is left to assert here is that the window opened at all -- without
    // it this test would be green for the wrong reason.
    assert!(
        world.saves_made_while_downloads_landed() > 0,
        "the window was never actually entered"
    );

}

#[test]
fn a_stale_path_rebuilding_a_folders_old_name_does_not_strand_the_renamed_one() {
    // A folder renamed twice in a row, and then an application that still held
    // the folder's ORIGINAL path writing through it. Saving to a stale path
    // creates the missing directories on the way, so the old name is standing
    // on the disk again -- as a brand new, unrelated folder -- moments after
    // the engine agreed the folder had moved away from it.
    //
    // That is not a contrived shape. An editor with a document open, a build
    // tool with a configured output directory, a sync client's own temp path:
    // all of them keep a path rather than a handle, and none of them notice a
    // rename. The soak rig produced it on its own and the result persisted for
    // the rest of the campaign -- the device holding the subtree under the new
    // name, the server still calling it by the old one, and both sides
    // reporting themselves settled about it.
    let world = World::new(71, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Projects");
    fs.user_mkdir("Projects/Sub 10");
    fs.user_mkdir("Projects/Sub 10/Sub 18");
    fs.user_mkdir("Projects/Sub 10/Sub 18/Sub 21");
    for (path, body) in [
        ("Projects/Sub 10/doc-12.txt", &b"twelve"[..]),
        ("Projects/Sub 10/Sub 18/doc-11.txt", &b"eleven"[..]),
        ("Projects/Sub 10/Sub 18/Sub 21/doc-4.txt", &b"four"[..]),
    ] {
        fs.user_write(path, body);
        committed.note(path, body);
    }
    assert!(world.settle().is_some(), "the tree settles before the rename");
    let (folders_before, _) = world.server.live_counts();

    fs.user_rename("Projects/Sub 10", "Projects/Sub 10 (27)");
    fs.user_write("Projects/Sub 10 (27)/in-flight-31.txt", b"in flight");
    committed.note("Projects/Sub 10 (27)/in-flight-31.txt", b"in flight");
    fs.user_rename("Projects/Sub 10 (27)", "Projects/Sub 10 (27) (31)");

    // The stale path. Nothing here has been told about either rename.
    fs.user_write("Projects/Sub 10/Sub 18/Sub 21/in-flight-32.txt", b"stale path");
    committed.note("Projects/Sub 10/Sub 18/Sub 21/in-flight-32.txt", b"stale path");

    assert!(
        world.settle().is_some(),
        "the device must reach a fixed point, not sit renaming forever"
    );
    assert_invariants(&world, &committed);
    assert_converged(&world);
    assert!(
        world
            .server
            .tree()
            .contains_key("Projects/Sub 10 (27) (31)/doc-12.txt"),
        "the renamed folder reached the server under the name it actually has"
    );
    // The trees can agree while the records underneath them do not, and this is
    // where that shows. Three directories genuinely came into existence -- the
    // ones the stale save built on its way down -- and nothing else did. A
    // fourth means the engine read its own renamed folder as new content and
    // gave it a second identity, leaving the first pointed at a directory that
    // merely shares its old name. Nothing is missing yet; it goes wrong the next
    // time either of them is renamed.
    let (folders_after, _) = world.server.live_counts();
    assert_eq!(
        folders_after,
        folders_before + 3,
        "one folder stayed one folder: no second identity for the one that moved"
    );
}

#[test]
fn a_rename_whose_answer_was_lost_still_ends_where_the_user_left_it() {
    // The same stale-path shape, with the one network fault that makes a rename
    // genuinely ambiguous: the server does the work and the answer is lost on
    // the way back. The client has to retry a rename it does not know already
    // happened -- and by then the user has renamed the folder again and an
    // application has rebuilt the original name underneath it.
    //
    // What must not happen is the device coming to rest holding the subtree
    // under one name while the server calls it another and neither side plans
    // anything about it. That state reads as settled from inside and is
    // permanent from outside.
    let world = World::new(72, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Projects");
    fs.user_mkdir("Projects/Sub 10");
    fs.user_mkdir("Projects/Sub 10/Sub 18");
    fs.user_mkdir("Projects/Sub 10/Sub 18/Sub 21");
    for (path, body) in [
        ("Projects/Sub 10/doc-12.txt", &b"twelve"[..]),
        ("Projects/Sub 10/Sub 18/doc-11.txt", &b"eleven"[..]),
        ("Projects/Sub 10/Sub 18/Sub 21/doc-4.txt", &b"four"[..]),
    ] {
        fs.user_write(path, body);
        committed.note(path, body);
    }
    assert!(world.settle().is_some(), "the tree settles before the rename");

    world.device("laptop").net.set_faults(NetFaults {
        lose_answer_to: Some("drive_rename".into()),
        ..NetFaults::none()
    });

    fs.user_rename("Projects/Sub 10", "Projects/Sub 10 (27)");
    // One pass, so the rename is attempted -- and its answer lost -- before the
    // user touches the folder again.
    world.pass(world.device("laptop"));

    fs.user_write("Projects/Sub 10 (27)/in-flight-31.txt", b"in flight");
    committed.note("Projects/Sub 10 (27)/in-flight-31.txt", b"in flight");
    fs.user_rename("Projects/Sub 10 (27)", "Projects/Sub 10 (27) (31)");
    fs.user_write("Projects/Sub 10/Sub 18/Sub 21/in-flight-32.txt", b"stale path");
    committed.note("Projects/Sub 10/Sub 18/Sub 21/in-flight-32.txt", b"stale path");

    assert!(
        world.settle().is_some(),
        "the device must reach a fixed point, not sit renaming forever"
    );
    assert_invariants(&world, &committed);
    assert_converged(&world);
}

#[test]
fn a_folders_old_name_rebuilt_after_the_rename_settled_is_still_new_content() {
    // The rename is completely finished first -- server and device agree, no
    // work outstanding -- and only then does something write through the name
    // the folder used to have. So this is not a race with a rename in flight:
    // it is a brand new directory tree appearing at a path the engine has a
    // memory of, on a device that had just declared itself up to date.
    //
    // The rig produced exactly this and then reported itself settled while
    // holding three files the server had never been told about.
    let world = World::new(73, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Projects");
    fs.user_mkdir("Projects/Sub 25");
    fs.user_write("Projects/Sub 25/doc-31.txt", b"thirty one");
    committed.note("Projects/Sub 25/doc-31.txt", b"thirty one");
    assert!(world.settle().is_some());

    fs.user_rename("Projects", "Projects (30)");
    assert!(world.settle().is_some(), "the rename finishes completely");
    assert!(world.server.tree().contains_key("Projects (30)/Sub 25/doc-31.txt"));

    // Now the stale writer, which knows nothing about any of that.
    for (path, body) in [
        ("Projects/Sub 25/doc-31.txt", &b"thirty one, again"[..]),
        ("Projects/Sub 25/doc-32.txt", &b"thirty two"[..]),
        ("Projects/Sub 25/doc-33.txt", &b"thirty three"[..]),
    ] {
        fs.user_write(path, body);
        committed.note(path, body);
    }

    assert!(world.settle().is_some(), "and the device settles again");
    assert_invariants(&world, &committed);
    assert_converged(&world);
}

#[test]
fn a_new_folder_wearing_a_renamed_folders_old_name_is_not_that_folder() {
    // The engine decides a tracked folder is still where it left it by asking
    // whether a directory stands at that path. A directory does -- but it is
    // not the same directory. The user renamed the folder, and something that
    // still held the old path built a fresh one there afterwards.
    //
    // Getting this wrong is quiet. The trees still agree afterwards, because
    // the renamed directory is simply adopted as new and its files move into
    // it, so every ordinary check passes. What has actually happened is that
    // one folder became two identities -- the original pointing at a directory
    // that merely shares its old name, a new one holding the contents -- and
    // the damage surfaces later, when either of them is renamed again and the
    // two records start describing different trees. That is how the soak rig
    // ended a campaign with a whole subtree on one device that the server had
    // never heard of, both sides reporting themselves settled.
    let world = World::new(74, &["laptop"]);
    let mut committed = Committed::default();
    let fs = &world.device("laptop").fs;

    fs.user_mkdir("Field Notes");
    fs.user_write("Field Notes/observations.txt", b"what was seen");
    committed.note("Field Notes/observations.txt", b"what was seen");
    assert!(world.settle().is_some());
    assert_eq!(world.server.live_counts(), (1, 1));
    let original = folder_id_named(&world, "Field Notes").expect("the folder reached the server");

    // The rename, and then the stale writer rebuilding the name it remembers.
    // No pass between them: this is one storm, as a person would produce it.
    fs.user_rename("Field Notes", "Field Notes 2026");
    fs.user_write("Field Notes/scratch.txt", b"saved to a path that moved");
    committed.note("Field Notes/scratch.txt", b"saved to a path that moved");

    assert!(world.settle().is_some());
    assert_invariants(&world, &committed);
    assert_converged(&world);

    let (folders, files) = world.server.live_counts();
    assert_eq!((folders, files), (2, 2), "one folder became two, not three");
    let tree = world.server.tree();
    assert!(tree.contains_key("Field Notes 2026/observations.txt"));
    assert!(tree.contains_key("Field Notes/scratch.txt"));

    // The counts and the tree agree either way, which is what makes this quiet.
    // Identity is the only thing that tells the two apart: the folder the user
    // renamed has to be the SAME folder afterwards, carrying its id, its
    // history and its sharing with it. If instead the original stayed on the
    // rebuilt directory and the moved one was adopted as new, everything above
    // still passes and the two records go on to describe different trees.
    assert_eq!(
        folder_id_named(&world, "Field Notes 2026"),
        Some(original),
        "the folder the user renamed kept its identity; it was not re-created \
         while the original settled onto a new directory wearing its old name"
    );
}

/// The server id the device holds for the folder currently called `name`.
fn folder_id_named(world: &World, name: &str) -> Option<i64> {
    world
        .device("laptop")
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .find(|e| {
            e.id.entity_type == jd_core::EntityType::Folder
                && !e.remote_deleted
                && e.remote.name == name
        })
        .map(|e| e.id.server_id)
}

/// Two files in one vault folder, both really called `report.txt`.
///
/// The server enforces name uniqueness on the title it stores, and for an
/// encrypted file that title is an opaque per-file id — unique by construction.
/// So it cannot refuse this, and nothing upstream stops it: two people can save
/// a file of the same name into a shared vault at the same moment, and the
/// server takes both without complaint.
///
/// Whichever loses is renamed, and both end up on the disk. Before that, the
/// loser was marked unsyncable and stayed that way for good — a file that
/// existed on the server and appeared on no computer anywhere.
#[test]
fn two_files_in_a_vault_with_one_name_both_end_up_on_the_disk() {
    let vault = SimVault::new(9_401);
    let mut world = World::new(9_401, &["laptop"]);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);
    let mut committed = Committed::default();

    let private = world.server.seed_encrypted_folder(None, "Private");
    let first = b"the one that was there first";
    let second = b"the one that arrived second";
    world
        .server
        .seed_vault_file(Some(private), "report.txt", first, &vault.public_key_b64);
    world
        .server
        .seed_vault_file(Some(private), "report.txt", second, &vault.public_key_b64);
    committed.note("Private/report.txt", first);
    committed.note("Private/report.txt", second);

    assert!(world.settle().is_some(), "it should settle");

    let disk = disk_tree(world.device("laptop"));
    assert_eq!(
        disk.get("Private/report.txt").cloned().flatten(),
        Some(jd_sim::sha256_hex(first)),
        "the lower server id keeps the name, found: {disk:?}"
    );
    assert_eq!(
        disk.get("Private/report (2).txt").cloned().flatten(),
        Some(jd_sim::sha256_hex(second)),
        "the other is numbered rather than parked, found: {disk:?}"
    );
    assert_nothing_lost(&world, &committed);
    assert_converged(&world);
}

/// ...and the second computer renames the same one, without being told.
///
/// The rule has to be a fact about the file, not about the computer looking at
/// it. Ranking by anything local — which entry is already on this disk, what
/// order the folder was read in — makes each device rename the other's file,
/// and the two never hold still.
#[test]
fn both_computers_rename_the_same_file_in_a_vault() {
    let vault = SimVault::new(9_402);
    let mut world = World::new(9_402, &["laptop", "desktop"]);
    world.give_vault("laptop", &vault);
    world.give_vault("desktop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);

    let private = world.server.seed_encrypted_folder(None, "Private");
    world.server.seed_vault_file(
        Some(private),
        "report.txt",
        b"the one that was there first",
        &vault.public_key_b64,
    );
    world.server.seed_vault_file(
        Some(private),
        "report.txt",
        b"the one that arrived second",
        &vault.public_key_b64,
    );

    assert!(world.settle().is_some(), "it should settle");

    let a = disk_tree(world.device("laptop"));
    let b = disk_tree(world.device("desktop"));
    assert_eq!(a, b, "the two computers should hold the same tree");
    assert!(
        a.contains_key("Private/report (2).txt"),
        "both should have arrived at the same new name, found: {a:?}"
    );
    assert_converged(&world);
}

/// A vault rename whose answer goes missing has to be able to ask again.
///
/// The name inside a vault lives in a sealed blob, and sealing draws a fresh
/// random nonce every time. Build the blob once per attempt and each attempt is
/// a different request under one idempotency key — which the server refuses,
/// correctly, and refuses again identically for as long as anyone asks. The op
/// never completes, no queue visibly grows, and no issue is raised.
#[test]
fn a_vault_rename_survives_a_lost_answer() {
    let vault = SimVault::new(9_403);
    let mut world = World::new(9_403, &["laptop"]);
    world.give_vault("laptop", &vault);
    world.server.set_vault_public_key(1, &vault.public_key_b64);

    let private = world.server.seed_encrypted_folder(None, "Private");
    world.server.seed_vault_file(
        Some(private),
        "report.txt",
        b"the one that was there first",
        &vault.public_key_b64,
    );
    world.server.seed_vault_file(
        Some(private),
        "report.txt",
        b"the one that arrived second",
        &vault.public_key_b64,
    );

    // The answer to the rename is lost on the way back. The server did the
    // work and the client was never told, so it asks again under the key it
    // already spent -- and for an encrypted file it cannot even tell from the
    // change feed that the rename landed, because the name the server holds
    // for one of these never changes.
    world.device("laptop").net.set_faults(NetFaults {
        lose_answer_to: Some("drive_rename".into()),
        ..NetFaults::none()
    });

    assert!(
        world.settle().is_some(),
        "the rename never finished: a resealed blob makes every retry a \
         different request under a key that was already spent"
    );
    let disk = disk_tree(world.device("laptop"));
    assert!(
        disk.contains_key("Private/report (2).txt"),
        "the duplicate should still have been renamed, found: {disk:?}"
    );
    // Settling is not enough on its own: dropping the op and planning it again
    // under a fresh key also gets there, and hides the defect. What is being
    // asserted is that the retry asked the same question it asked the first
    // time.
    assert_eq!(
        world.server.key_conflicts(),
        0,
        "a retry sent a different request under a key it had already spent"
    );
    assert_converged(&world);
}
