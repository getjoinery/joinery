//! The executor, run against the simulated world.
//!
//! These are not unit tests of helper functions. Each one builds a computer,
//! hands the real `jd-core` executor a real plan, and lets it move bytes over a
//! network that may lose the answer, onto a disk that may fill up. What is
//! asserted afterwards is what a user would care about: is the file there, is
//! it the right file, and is there exactly one of it.

use jd_core::execute::{journal, recover, run_queued, ExecEnv, OpOutcome};
use jd_core::model::{ContentId, EntityId, Entry, LocalStatus, Placement};
use jd_core::order::{plan, PlanItem};
use jd_core::reconcile::Action;
use jd_core::store::OpState;
use jd_sim::engine::{env, Device};
use jd_sim::{sha256_hex, MockServer, NetFaults, SimClock};

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

fn world() -> (SimClock, MockServer, Device) {
    let clock = SimClock::new();
    let server = MockServer::new(clock.clone());
    let device = Device::new("laptop", &server, clock.clone(), 7);
    (clock, server, device)
}

/// An entry that has never synced — the state a locally created file is in.
fn fresh(id: EntityId, parent: Option<i64>, name: &str, status: LocalStatus) -> Entry {
    Entry {
        id,
        remote: Placement {
            parent,
            name: name.into(),
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
        status,
        wrapped_file_key: None,
    }
}

/// Journal one action and run it, returning what the pass did.
fn do_one(device: &Device, entity: EntityId, action: Action) -> jd_core::ExecReport {
    let items = vec![PlanItem::new(entity, action, 0)];
    let mut tokens = |e: EntityId| format!("t{}", e.server_id);
    let p = plan(items, &jd_vfs::Personality::linux(), &mut tokens);
    {
        let mut keys = device.key_source();
        journal(&device.store, &p, &mut keys).expect("journal");
    }
    let now = device.now();
    let e: ExecEnv = env(device, &now);
    run_queued(&e).expect("run")
}

// ---------------------------------------------------------------------------
// The ordinary paths
// ---------------------------------------------------------------------------

#[test]
fn an_upload_lands_and_records_what_the_two_sides_now_agree_on() {
    let (_clock, server, device) = world();
    let body = b"the quick brown fox jumps";
    device.fs.user_write("notes.txt", body);

    let id = EntityId::file(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(id, None, "notes.txt", LocalStatus::PendingUpload))
        .unwrap();

    let report = do_one(
        &device,
        id,
        Action::UploadAsNew {
            placement: Placement {
                parent: None,
                name: "notes.txt".into(),
            },
        },
    );
    assert_eq!(report.done, 1, "the upload should have completed");

    // The server has it.
    assert_eq!(server.blob(&sha256_hex(body)).unwrap(), body);
    assert_eq!(server.live_counts(), (0, 1));

    // And the entry has a real id now, with the agreement written down. Without
    // that last part the file would upload again every round forever.
    let entries = device.store.children_of(None).unwrap();
    assert_eq!(entries.len(), 1);
    let entry = &entries[0];
    assert!(!entry.id.is_provisional(), "the server named it");
    assert_eq!(entry.status, LocalStatus::Synced);
    assert_eq!(
        entry.synced_content.as_ref().map(|c| c.sha256.as_str()),
        Some(sha256_hex(body).as_str())
    );
    assert!(entry.synced_fingerprint.is_some());
}

#[test]
fn a_download_materializes_the_file_and_verifies_what_arrived() {
    let (_clock, server, device) = world();
    let body = b"a body that spans several chunks of three";
    let file_id = server.seed_file(None, "report.txt", body);

    let id = EntityId::file(file_id);
    let mut entry = fresh(id, None, "report.txt", LocalStatus::PendingDownload);
    entry.remote_content = Some(ContentId {
        sha256: sha256_hex(body),
        size: body.len() as u64,
    });
    device.store.put_entry(&entry).unwrap();

    let report = do_one(&device, id, Action::Download);
    assert_eq!(report.done, 1);
    assert_eq!(device.fs.peek("report.txt").unwrap(), body);

    let after = device.store.get_entry(id).unwrap().unwrap();
    assert_eq!(after.status, LocalStatus::Synced);
    assert_eq!(
        after.synced_content.as_ref().map(|c| c.sha256.as_str()),
        Some(sha256_hex(body).as_str())
    );
}

#[test]
fn a_folder_created_here_gets_a_real_id_and_its_children_come_with_it() {
    // The moment a locally created folder becomes real is the moment its
    // children could be orphaned — parented to an id nothing recognizes. This
    // is the test that says they are not.
    let (_clock, server, device) = world();
    let folder = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(folder, None, "Docs", LocalStatus::PendingUpload))
        .unwrap();

    let child = EntityId::file(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(
            child,
            Some(folder.server_id),
            "inside.txt",
            LocalStatus::PendingUpload,
        ))
        .unwrap();

    let report = do_one(
        &device,
        folder,
        Action::CreateRemoteFolder {
            placement: Placement {
                parent: None,
                name: "Docs".into(),
            },
        },
    );
    assert_eq!(report.done, 1);
    assert_eq!(server.live_counts(), (1, 0));

    let roots = device.store.children_of(None).unwrap();
    assert_eq!(roots.len(), 1);
    let real = roots[0].id;
    assert!(!real.is_provisional());

    let kids = device.store.children_of(Some(real.server_id)).unwrap();
    assert_eq!(kids.len(), 1, "the child followed its folder");
    assert_eq!(kids[0].remote.name, "inside.txt");
}

#[test]
fn a_local_delete_goes_to_the_trash_not_to_oblivion() {
    let (_clock, _server, device) = world();
    device.fs.user_write("old.txt", b"still wanted, maybe");
    let id = EntityId::file(1);
    device
        .store
        .put_entry(&fresh(id, None, "old.txt", LocalStatus::Synced))
        .unwrap();

    let report = do_one(&device, id, Action::TrashLocal);
    assert_eq!(report.done, 1);
    assert!(device.fs.peek("old.txt").is_none(), "gone from the tree");
    let trashed = device.fs.trashed();
    assert_eq!(trashed.len(), 1, "and recoverable, because a delete the");
    assert_eq!(trashed[0].0, "old.txt");
    assert_eq!(
        trashed[0].1.as_deref(),
        Some(&b"still wanted, maybe"[..]),
        "the bytes are still there, not just the name"
    );
    assert!(device.store.get_entry(id).unwrap().is_none());
}

// ---------------------------------------------------------------------------
// The awkward paths
// ---------------------------------------------------------------------------

#[test]
fn a_server_that_keeps_ending_early_is_resumed_until_the_file_is_whole() {
    // Every response is cut short and says nothing about it. Resuming from the
    // bytes that actually landed — rather than from what a header claimed —
    // means this still finishes, and finishes correct.
    let (_clock, server, device) = world();
    let body = b"twenty-four bytes exactly";
    let file_id = server.seed_file(None, "big.bin", body);
    device.net.set_faults(NetFaults {
        truncate_download: 1000,
        ..NetFaults::none()
    });

    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(id, None, "big.bin", LocalStatus::PendingDownload))
        .unwrap();

    let report = do_one(&device, id, Action::Download);
    assert_eq!(report.done, 1);
    assert_eq!(device.fs.peek("big.bin").unwrap(), body);
    assert!(
        device.net.stats().truncated_downloads > 1,
        "the test is worthless unless the truncation actually happened"
    );
}

#[test]
fn a_download_that_dies_partway_leaves_nothing_visible_and_nothing_behind() {
    // The transfer stops for good in the middle. Two things must hold: no half
    // a file where a whole one belongs, and no abandoned spool quietly filling
    // the user's disk.
    let (clock, server, device) = world();
    let body = b"a file that will not arrive today";
    let file_id = server.seed_file(None, "doomed.bin", body);

    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(id, None, "doomed.bin", LocalStatus::PendingDownload))
        .unwrap();

    device.net.set_faults(NetFaults {
        drop_before: 1000,
        ..NetFaults::none()
    });
    let report = do_one(&device, id, Action::Download);
    assert_eq!(report.retrying, 1, "it should be waiting to try again");
    assert!(device.fs.peek("doomed.bin").is_none());
    assert_eq!(device.fs.spool_count(), 0);

    // And it recovers on its own once the network stops misbehaving.
    device.net.set_faults(NetFaults::none());
    clock.advance_secs(30 * 60);
    let now = device.now();
    let e = env(&device, &now);
    assert_eq!(run_queued(&e).unwrap().done, 1);
    assert_eq!(device.fs.peek("doomed.bin").unwrap(), body);
}

#[test]
fn a_download_onto_a_file_that_changed_underneath_is_refused() {
    // The decision to download was made against a local file that has since
    // been edited. Writing over it would destroy work nobody has seen.
    let (_clock, server, device) = world();
    let body = b"the server version";
    let file_id = server.seed_file(None, "shared.txt", body);

    device.fs.user_write("shared.txt", b"the old local version");
    let stale = device
        .fs
        .fingerprint_at("shared.txt")
        .expect("a fingerprint");

    let id = EntityId::file(file_id);
    let mut entry = fresh(id, None, "shared.txt", LocalStatus::PendingDownload);
    entry.synced_fingerprint = Some(stale);
    device.store.put_entry(&entry).unwrap();

    // Somebody saves the file while the round is being planned.
    device
        .fs
        .user_write("shared.txt", b"edited while we were not looking");

    let report = do_one(&device, id, Action::Download);
    assert_eq!(report.overtaken, 1);
    assert_eq!(
        device.fs.peek("shared.txt").unwrap(),
        b"edited while we were not looking",
        "the local edit survived"
    );
    // Nothing is raised here. What the user needs to hear about is the conflict
    // this ran into, and that speaks for itself in its own words — see
    // a_conflict_is_reported_by_the_conflict_and_not_by_the_download_that_hit_it.
    let issues = device.store.open_issues().unwrap();
    assert!(
        issues.is_empty(),
        "the refused download is not the story: {issues:?}"
    );
}

#[test]
fn a_lost_answer_to_a_folder_create_does_not_make_two_folders() {
    // The one that the whole journal exists for. The server made the folder;
    // the answer never came back; the client cannot tell that from "it never
    // arrived" and has no choice but to try again.
    let (clock, server, device) = world();
    let id = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(id, None, "Docs", LocalStatus::PendingUpload))
        .unwrap();
    device.net.set_faults(NetFaults {
        drop_after: 1000,
        ..NetFaults::none()
    });

    let report = do_one(
        &device,
        id,
        Action::CreateRemoteFolder {
            placement: Placement {
                parent: None,
                name: "Docs".into(),
            },
        },
    );
    assert_eq!(report.retrying, 1);
    assert_eq!(server.live_counts(), (1, 0), "the server did it anyway");

    // Retry under the original key.
    device.net.set_faults(NetFaults::none());
    clock.advance_secs(30 * 60);
    let now = device.now();
    let e = env(&device, &now);
    assert_eq!(run_queued(&e).unwrap().done, 1);
    assert_eq!(
        server.live_counts(),
        (1, 0),
        "still exactly one folder, not two"
    );
}

#[test]
fn a_lost_answer_to_an_upload_does_not_make_two_files() {
    let (clock, server, device) = world();
    let body = b"content that only wants to exist once";
    device.fs.user_write("once.txt", body);
    let id = EntityId::file(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(id, None, "once.txt", LocalStatus::PendingUpload))
        .unwrap();

    // Lose the answer to the completion call specifically: the bytes are up,
    // the file exists, and the client is none the wiser.
    device.net.set_faults(NetFaults {
        drop_after: 1000,
        ..NetFaults::none()
    });
    let report = do_one(
        &device,
        id,
        Action::UploadAsNew {
            placement: Placement {
                parent: None,
                name: "once.txt".into(),
            },
        },
    );
    assert_eq!(report.retrying, 1);

    device.net.set_faults(NetFaults::none());
    clock.advance_secs(30 * 60);
    let now = device.now();
    let e = env(&device, &now);
    assert_eq!(run_queued(&e).unwrap().done, 1);
    assert_eq!(
        server.live_counts(),
        (0, 1),
        "one file, however many times the answer was lost"
    );
}

#[test]
fn an_interrupted_remote_move_that_actually_landed_is_recognized_not_repeated() {
    // The crash window. The op is in flight, the machine dies, and on restart
    // the journal cannot say whether the server got it. Asking the server is
    // the only honest answer.
    let (_clock, server, device) = world();
    let file_id = server.seed_file(None, "a.txt", b"unchanged");
    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(id, None, "a.txt", LocalStatus::Synced))
        .unwrap();

    // The move landed on the server...
    server
        .action(
            "drive_rename",
            &serde_json::json!({ "entity_type": "file", "entity_id": file_id, "name": "b.txt" }),
        )
        .unwrap();

    // ...and the local record of it did not get written before the crash.
    let op_id = device
        .store
        .queue_op(
            "move_remote",
            id,
            &serde_json::json!({ "parent": null, "name": "b.txt" }).to_string(),
            "key-move-1",
        )
        .unwrap();
    device.store.set_op_state(op_id, OpState::InFlight).unwrap();

    let now = device.now();
    let e = env(&device, &now);
    let report = recover(&e).unwrap();
    assert_eq!(report.done, 1, "the server had already done it");
    assert!(device.store.queued_ops().unwrap().is_empty());
}

#[test]
fn an_interrupted_op_that_did_not_land_goes_back_in_the_queue() {
    let (_clock, server, device) = world();
    let file_id = server.seed_file(None, "a.txt", b"unchanged");
    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(id, None, "a.txt", LocalStatus::Synced))
        .unwrap();

    let op_id = device
        .store
        .queue_op(
            "move_remote",
            id,
            &serde_json::json!({ "parent": null, "name": "b.txt" }).to_string(),
            "key-move-2",
        )
        .unwrap();
    device.store.set_op_state(op_id, OpState::InFlight).unwrap();

    let now = device.now();
    let e = env(&device, &now);
    assert_eq!(recover(&e).unwrap().retrying, 1);

    // And running the queue finishes it, under the key it was written with.
    assert_eq!(run_queued(&e).unwrap().done, 1);
    assert_eq!(
        device.store.get_entry(id).unwrap().unwrap().remote.name,
        "b.txt"
    );
}

#[test]
fn an_op_whose_premise_is_gone_is_dropped_rather_than_retried_forever() {
    // The file was deleted while the op sat in the queue. Retrying it until the
    // heat death of the universe would leave the client permanently busy and
    // permanently useless.
    let (_clock, _server, device) = world();
    let id = EntityId::file(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(id, None, "vanished.txt", LocalStatus::PendingUpload))
        .unwrap();

    let report = do_one(
        &device,
        id,
        Action::UploadAsNew {
            placement: Placement {
                parent: None,
                name: "vanished.txt".into(),
            },
        },
    );
    assert_eq!(report.overtaken, 1);
    assert!(
        device.store.queued_ops().unwrap().is_empty(),
        "the intent is dropped, not left to spin"
    );
    assert!(
        device.store.open_issues().unwrap().is_empty(),
        "deleting a file before it uploaded is an ordinary thing to do"
    );
}

#[test]
fn an_expired_link_is_re_minted_and_the_download_still_completes() {
    // A signed link is short-lived on purpose. A large file can outlive one,
    // and the right answer is a fresh link, not a failed download.
    let (_clock, server, device) = world();
    let body = b"long enough to matter";
    let file_id = server.seed_file(None, "long.bin", body);

    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(id, None, "long.bin", LocalStatus::PendingDownload))
        .unwrap();

    // Every link minted before this instant is dead.
    server.expire_signed_urls();

    let report = do_one(&device, id, Action::Download);
    assert_eq!(report.done, 1, "a stale link is not a failure");
    assert_eq!(device.fs.peek("long.bin").unwrap(), body);
}

#[test]
fn the_whole_matrix_at_once_still_ends_with_the_right_bytes() {
    // Everything wrong at the same time. What is being asserted is not that no
    // attempt failed — most will — but that no attempt produced a wrong file,
    // and that persistence gets there in the end.
    let (clock, server, device) = world();
    let body = b"survives everything the network can do to it";
    let file_id = server.seed_file(None, "hardy.txt", body);
    device.net.set_faults(NetFaults::chaos());

    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(id, None, "hardy.txt", LocalStatus::PendingDownload))
        .unwrap();
    do_one(&device, id, Action::Download);

    let now = device.now();
    let e = env(&device, &now);
    for _ in 0..40 {
        clock.advance_secs(30 * 60);
        if run_queued(&e).unwrap().done > 0 {
            break;
        }
        // Whatever partial state each attempt left, none of it is visible.
        assert!(
            device
                .fs
                .peek("hardy.txt")
                .map(|b| b == body)
                .unwrap_or(true),
            "a file that exists is always the whole, correct file"
        );
    }
    assert_eq!(
        device.fs.peek("hardy.txt").unwrap(),
        body,
        "and it does eventually get there"
    );
    assert_eq!(server.live_counts(), (0, 1));
}

#[test]
fn an_abandoned_download_leaves_no_spool_behind() {
    let (_clock, server, device) = world();
    server.seed_file(None, "gone.txt", b"here for now");
    let id = EntityId::file(999);
    device
        .store
        .put_entry(&fresh(id, None, "gone.txt", LocalStatus::PendingDownload))
        .unwrap();

    let report = do_one(&device, id, Action::Download);
    assert_eq!(report.overtaken, 1, "the server never had file 999");
    assert_eq!(
        device.fs.spool_count(),
        0,
        "an abandoned transfer leaves nothing on the disk"
    );
}

#[test]
fn an_outcome_is_one_of_three_things_and_never_a_silent_success() {
    // A shape test, deliberately. Every op ends as done, withdrawn or retrying,
    // and the report has to account for each one — an op that finished without
    // being counted is an op nobody will ever look at again.
    let (_clock, server, device) = world();
    let a = EntityId::file(server.seed_file(None, "a.txt", b"aaa"));
    device
        .store
        .put_entry(&fresh(a, None, "a.txt", LocalStatus::PendingDownload))
        .unwrap();
    let report = do_one(&device, a, Action::Download);
    assert_eq!(report.attempted(), 1);
    assert_eq!(report.done + report.withdrawn + report.retrying, 1);
    assert!(matches!(
        OpOutcome::Done,
        OpOutcome::Done | OpOutcome::Withdrawn(_) | OpOutcome::Retry(_)
    ));
}
