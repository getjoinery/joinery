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
fn an_upload_retried_after_the_file_changed_is_not_refused_over_its_key() {
    // The window the rig kept finding, and it needs both halves.
    //
    // First the bytes go up, the server takes the completion, and only the
    // answer is lost. Then -- because a person is still working -- the file
    // changes again before the retry runs. The retry therefore uploads
    // different content, so the server cannot recognise it as the same upload
    // and short-circuit; it opens a new upload and is issued a new token.
    //
    // That completion is a genuinely different request. A key written down
    // before the first attempt now names two of them, and the platform refuses
    // that ahead of every other branch -- including the takeover of an
    // abandoned original -- so it fails the same way forever. On the rig this
    // was upload_version sitting at ten attempts while the file never arrived.
    let (clock, _server, device) = world();
    device.fs.user_write("once.txt", b"the first thing that was written");
    let id = EntityId::file(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(id, None, "once.txt", LocalStatus::PendingUpload))
        .unwrap();

    // Only the completion answer, so the attempt reaches the end before it is
    // cut. A blanket drop rate loses the init instead and never gets here.
    device.net.set_faults(NetFaults {
        lose_answer_to: Some("drive_upload_complete".into()),
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
    assert_eq!(report.retrying, 1, "a lost answer is a retry, not a failure");

    // The user carries on typing. This is what stops the retry being waved
    // through by dedup, and it is the ordinary case rather than a corner.
    device
        .fs
        .user_write("once.txt", b"and then it was edited again, at length");

    clock.advance_secs(30 * 60);
    let now = device.now();
    let e = env(&device, &now);
    run_queued(&e).unwrap();

    for op in device.store.queued_ops().unwrap() {
        let err = op.last_error.clone().unwrap_or_default();
        assert!(
            !err.contains("Idempotency-Key"),
            "the completion was refused over its key, which no retry can ever \
             get past: {err}"
        );
    }
}

#[test]
fn a_move_into_a_folder_the_server_has_never_heard_of_waits_instead_of_asking() {
    // A provisional id is a local placeholder. It names nothing the server can
    // look up, so a move that sends one as a destination cannot land -- and the
    // file stays where it was. The rename that follows then asks for a name in
    // the OLD folder, where the old neighbours are still sitting, and that
    // comes back `name_taken`: the one refusal this client waits on forever,
    // because waiting is right when a sibling is genuinely on its way and this
    // sibling never was.
    //
    // Uploading and creating a folder both already check this. Moving did not.
    // On the rig it was three move_remote ops at sixteen, fifteen and ten
    // attempts, each with a negative destination parent, holding two devices
    // apart for every remaining cycle of the campaign.
    let (_clock, server, device) = world();
    let folder = server.seed_folder(None, "Projects");
    let file_id = server.seed_file(Some(folder), "doc-8.txt", b"the one being moved");
    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(
            id,
            Some(folder),
            "doc-8.txt",
            LocalStatus::Synced,
        ))
        .unwrap();

    // The neighbour that makes a degenerate move fail as `name_taken` rather
    // than fail quietly -- exactly the collision the rig kept landing on.
    server.seed_file(Some(folder), "doc-8 (copy).txt", b"the neighbour");

    // Created here a moment ago, and not yet on the server.
    let destination = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(
            destination,
            None,
            "Projects (2)",
            LocalStatus::PendingUpload,
        ))
        .unwrap();

    let report = do_one(
        &device,
        id,
        Action::ApplyLocalMove {
            to: Placement {
                parent: Some(destination.server_id),
                name: "doc-8.txt".into(),
            },
        },
    );

    assert_eq!(report.done, 0, "there is nowhere on the server to move it to");
    assert_eq!(report.retrying, 1, "the folder is coming; this waits for it");

    let queued = device.store.queued_ops().unwrap();
    assert_eq!(queued.len(), 1);
    let err = queued[0].last_error.clone().unwrap_or_default();
    assert!(
        !err.contains("already using that name"),
        "it asked anyway and got stuck on a name no arriving sibling can free: {err}"
    );
    assert!(
        err.contains("not on the server yet"),
        "it should be waiting on the folder, not on something else: {err}"
    );
}

#[test]
fn a_move_into_a_provisional_folder_that_no_longer_exists_is_replanned_not_awaited() {
    // The other half. A provisional id is local and is never reissued, so once
    // the entry holding it is gone -- the folder took a real id, or was folded
    // into one that already had it -- nothing will ever answer to it again.
    // Waiting is then waiting forever, and the honest move is to drop the plan
    // and let the next scan write a new one from what is really there.
    //
    // The rig had five of these across two devices at up to nineteen attempts,
    // every one naming a provisional parent with no entry left behind it.
    let (_clock, server, device) = world();
    let folder = server.seed_folder(None, "Projects");
    let file_id = server.seed_file(Some(folder), "doc-7.txt", b"still wanted");
    let id = EntityId::file(file_id);
    device
        .store
        .put_entry(&fresh(id, Some(folder), "doc-7.txt", LocalStatus::Synced))
        .unwrap();

    // A provisional id that nothing is tracking: no entry is ever put for it.
    let vanished = device.store.next_provisional_id().unwrap();

    let report = do_one(
        &device,
        id,
        Action::ApplyLocalMove {
            to: Placement {
                parent: Some(vanished),
                name: "doc-7.txt".into(),
            },
        },
    );

    assert_eq!(report.done, 0, "there was nowhere to put it");
    assert_eq!(
        report.retrying, 0,
        "waiting on an id that can never come back is waiting forever"
    );
    assert!(
        device.store.queued_ops().unwrap().is_empty(),
        "the stale plan is dropped so the next scan can write a real one"
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

#[test]
fn a_name_held_by_something_we_already_know_about_is_not_waited_on() {
    // `name_taken` earns a wait because of what the client does *not* know: the
    // server is holding a sibling nobody has told this device about, hearing
    // about it is what moves our copy aside, and until then there is nothing to
    // do but wait.
    //
    // Here the occupant is already in the store, live and settled. Nothing is on
    // its way, so the wait is forever — and forever costs a device that never
    // reports itself settled over an operation the next scan throws away. The
    // rig had these at four hundred attempts apiece.
    let (_clock, server, device) = world();
    let mover = EntityId::file(server.seed_file(None, "draft.txt", b"the one being renamed"));
    let occupant = EntityId::file(server.seed_file(None, "final.txt", b"already here"));
    for (id, name) in [(mover, "draft.txt"), (occupant, "final.txt")] {
        device
            .store
            .put_entry(&fresh(id, None, name, LocalStatus::Synced))
            .unwrap();
    }

    let report = do_one(
        &device,
        mover,
        Action::ApplyLocalMove {
            to: Placement {
                parent: None,
                name: "final.txt".into(),
            },
        },
    );

    assert_eq!(report.retrying, 0, "it has to stop, not wait forever");
    assert_eq!(report.overtaken, 1, "somebody else got there first");
    assert!(
        device.store.queued_ops().unwrap().is_empty(),
        "the op is stale, so it goes; the next pass plans from what is there now"
    );
    assert!(
        device
            .store
            .open_issues()
            .unwrap()
            .iter()
            .all(|i| i.kind != "withdrawn"),
        "there is nothing here for a person to decide"
    );
}

#[test]
fn a_folder_the_server_says_is_in_its_trash_is_recorded_as_deleted() {
    // The refusal carries a fact: a folder this device still believes in is in
    // the server's trash. Calling the operation overtaken and throwing the fact
    // away leaves the belief standing, so the next pass plans the same work
    // into the same folder and hears the same refusal — resolved only if and
    // when the delete turns up on the change feed, and unbounded until it does.
    //
    // On the rig it did not: a folder made here and deleted from the other
    // machine held a file at three hundred and forty-seven attempts, and the
    // device never went quiet.
    let (_clock, server, device) = world();
    let folder = server.seed_folder(None, "Projects");
    device
        .store
        .put_entry(&fresh(
            EntityId::folder(folder),
            None,
            "Projects",
            LocalStatus::Synced,
        ))
        .unwrap();
    server
        .action(
            "drive_trash",
            &serde_json::json!({ "entity_type": "folder", "entity_id": folder }),
        )
        .expect("trash");

    // Something of ours was still on its way into it.
    let child = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(
            child,
            Some(folder),
            "Drafts",
            LocalStatus::PendingUpload,
        ))
        .unwrap();
    device.fs.user_mkdir("Projects/Drafts");

    let report = do_one(
        &device,
        child,
        Action::CreateRemoteFolder {
            placement: Placement {
                parent: Some(folder),
                name: "Drafts".into(),
            },
        },
    );

    assert_eq!(report.overtaken, 1, "somebody else's delete got there first");
    assert!(
        device
            .store
            .get_entry(EntityId::folder(folder))
            .unwrap()
            .expect("the folder record is still there")
            .remote_deleted,
        "the server just said this folder is in its trash; believing it is what \
         starts the delete being handled instead of planned around forever"
    );
}

#[test]
fn a_move_with_nowhere_to_move_from_forgets_where_it_thought_the_file_was() {
    // The server moved a file into a folder this device has. Applying that move
    // means renaming the local file — but the record of where it sits here
    // hangs off a folder the store no longer has, so no path can be built for
    // it. Not on this attempt and not on any later one: nothing is going to put
    // that folder back.
    //
    // Reporting the move overtaken and leaving the record alone made that
    // permanent. The next pass read the same unusable placement, planned the
    // same move, and got the same answer — no error, no queued work, nothing to
    // show for it, and a device that never went quiet again.
    let (_clock, server, device) = world();
    let destination = server.seed_folder(None, "Sorted");
    let file = server.seed_file(Some(destination), "receipt.pdf", b"a receipt");
    device
        .store
        .put_entry(&fresh(
            EntityId::folder(destination),
            None,
            "Sorted",
            LocalStatus::Synced,
        ))
        .unwrap();

    // Synced under folder 4242, which is not in the store at all.
    let id = EntityId::file(file);
    let mut entry = fresh(id, Some(destination), "receipt.pdf", LocalStatus::Synced);
    entry.synced_placement = Some(Placement {
        parent: Some(4242),
        name: "receipt.pdf".into(),
    });
    entry.synced_content = Some(jd_core::model::ContentId {
        sha256: "whatever-we-last-agreed".into(),
        size: 9,
    });
    device.store.put_entry(&entry).unwrap();

    let report = do_one(
        &device,
        id,
        Action::ApplyRemoteMove {
            to: Placement {
                parent: Some(destination),
                name: "receipt.pdf".into(),
            },
        },
    );

    assert_eq!(report.overtaken, 1);
    let after = device.store.get_entry(id).unwrap().expect("still tracked");
    assert!(
        after.synced_placement.is_none() && after.synced_content.is_none(),
        "the whole agreement has to go, not half of it: an entry still holding \
         a content agreement reads as established, and an established entry \
         with an unchanged remote gives the pass nothing to do about a file \
         that is not on this disk at all"
    );
    assert_eq!(after.status, LocalStatus::PendingDownload);
}

#[test]
fn a_move_that_is_also_a_rename_does_not_stall_on_a_name_it_is_leaving_behind() {
    // Two calls means one intermediate state, and the order decides which one.
    // Moving first parks the file under its OLD name among the destination's
    // siblings — and the old name is a contested one, which is usually the
    // whole reason a rename is happening. Here `Sorted` already has a
    // `notes.txt`, so the intermediate is refused even though the name the file
    // is actually going to is free.
    //
    // The mock let this through for as long as it existed, so no scenario could
    // see it. Against a server that refuses — which the real one does — every
    // combined move-and-rename out of a folder whose name the destination also
    // used stalled: fifteen seeds of a fifteen-hundred-seed sweep.
    let (_clock, server, device) = world();
    let sorted = server.seed_folder(None, "Sorted");
    server.seed_file(Some(sorted), "notes.txt", b"the one already there");
    let id = EntityId::file(server.seed_file(None, "notes.txt", b"the one being moved"));
    device
        .store
        .put_entry(&fresh(id, None, "notes.txt", LocalStatus::Synced))
        .unwrap();

    let report = do_one(
        &device,
        id,
        Action::ApplyLocalMove {
            to: Placement {
                parent: Some(sorted),
                name: "notes (conflicted copy).txt".into(),
            },
        },
    );

    assert_eq!(report.done, 1, "the name it is going to is free");
    let tree = server.tree();
    assert!(tree.contains_key("Sorted/notes (conflicted copy).txt"));
    assert!(
        tree.contains_key("Sorted/notes.txt"),
        "and the file that was already there is untouched"
    );
}

#[test]
fn a_move_that_is_also_a_rename_falls_back_when_the_old_neighbours_hold_the_new_name() {
    // The mirror case, and why neither order can simply be preferred: renaming
    // first parks the file under its NEW name among the neighbours it has not
    // left yet, and one of them is already using it. Moving first is fine here,
    // because the destination has nothing called `draft.txt`.
    let (_clock, server, device) = world();
    let sorted = server.seed_folder(None, "Sorted");
    server.seed_file(None, "final.txt", b"the neighbour it is leaving");
    let id = EntityId::file(server.seed_file(None, "draft.txt", b"the one being moved"));
    device
        .store
        .put_entry(&fresh(id, None, "draft.txt", LocalStatus::Synced))
        .unwrap();

    let report = do_one(
        &device,
        id,
        Action::ApplyLocalMove {
            to: Placement {
                parent: Some(sorted),
                name: "final.txt".into(),
            },
        },
    );

    assert_eq!(report.done, 1, "one order is refused; the other is not");
    let tree = server.tree();
    assert!(tree.contains_key("Sorted/final.txt"));
    assert!(
        tree.contains_key("final.txt"),
        "and the neighbour that held the name at the old address keeps it"
    );
}

#[test]
fn a_rescue_lands_in_the_folder_its_name_was_chosen_for() {
    // The server deleted this file while the user had it open somewhere else,
    // so the content is rescued as a new file. The rescue name is picked to be
    // free among the siblings it is landing beside -- and that is the only
    // place it means anything. Posting it to the folder the deleted entry used
    // to live in asks for a name that folder may already hold, and the refusal
    // that comes back sends the whole thing to the planner, which reads an
    // unchanged tree and decides on the identical rescue again.
    let (_clock, server, device) = world();
    let folder = server.seed_folder(None, "Sub 30 renamed");
    let rescue_name = "contested (conflicted copy 2026-07-31 from laptop).txt";
    // The root already has something by that name, and it is nothing to do with
    // this file.
    server.seed_file(None, rescue_name, b"an unrelated file of the same name");

    let body = b"the work that must not be lost";
    device.fs.user_mkdir("Sub 30 renamed");
    device
        .fs
        .user_write(&format!("Sub 30 renamed/{rescue_name}"), body);

    let mut tracked_folder = fresh(
        EntityId::folder(folder),
        None,
        "Sub 30 renamed",
        LocalStatus::Synced,
    );
    tracked_folder.synced_placement = Some(Placement {
        parent: None,
        name: "Sub 30 renamed".into(),
    });
    device.store.put_entry(&tracked_folder).unwrap();

    // The entry as a rescue finds it: the server has deleted it, and the
    // agreement still points at the drive root where it used to be.
    let id = EntityId::file(1);
    let mut entry = fresh(id, None, "contested.txt", LocalStatus::Synced);
    entry.remote_deleted = true;
    entry.synced_placement = Some(Placement {
        parent: None,
        name: "contested.txt".into(),
    });
    device.store.put_entry(&entry).unwrap();

    let report = do_one(
        &device,
        id,
        Action::UploadAsNew {
            placement: Placement {
                parent: Some(folder),
                name: rescue_name.into(),
            },
        },
    );

    assert_eq!(report.done, 1, "the rescue should land, not bounce");
    let tree = server.tree();
    assert_eq!(
        tree.get(&format!("Sub 30 renamed/{rescue_name}")),
        Some(&Some(sha256_hex(body))),
        "the rescued content should be in the folder the name was chosen for: {:?}",
        tree.keys().collect::<Vec<_>>()
    );
    assert_eq!(
        tree.get(rescue_name),
        Some(&Some(sha256_hex(b"an unrelated file of the same name"))),
        "and the unrelated file at the root keeps its name and its bytes"
    );
}

#[test]
fn a_folder_create_whose_answer_was_lost_does_not_collide_with_its_own_folder() {
    // The create landed; the answer did not come back. Before the retry gets
    // its replayed answer, an index walk picks the folder up under its real id
    // -- and somebody has moved it in the meantime, so the repair that folds a
    // stray provisional into its real twin cannot recognise the pair by name
    // and parent. The retry then tries to take an id its own store already
    // holds, which the database refuses, on every attempt, forever. Everything
    // queued underneath waits on a parent that can never exist.
    let (clock, server, device) = world();
    let elsewhere = server.seed_folder(None, "Sorted");

    let provisional = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(provisional, None, "Projects", LocalStatus::PendingUpload))
        .unwrap();
    let child = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(
            child,
            Some(provisional.server_id),
            "Inner",
            LocalStatus::PendingUpload,
        ))
        .unwrap();

    // The create reaches the server and does its work; the answer is lost.
    device.net.set_faults(NetFaults {
        lose_answer_to: Some("drive_folder_create".into()),
        ..NetFaults::none()
    });
    let first = do_one(
        &device,
        provisional,
        Action::CreateRemoteFolder {
            placement: Placement {
                parent: None,
                name: "Projects".into(),
            },
        },
    );
    assert_eq!(first.retrying, 1, "the answer was lost, so it retries");
    device.net.set_faults(NetFaults::none());
    assert!(
        server.tree().contains_key("Projects"),
        "but the folder is on the server: {:?}",
        server.tree().keys().collect::<Vec<_>>()
    );

    // Another device moves it, so the stray provisional and the real folder no
    // longer look alike by name and parent.
    let real = elsewhere + 1;
    server
        .action(
            "drive_move",
            &serde_json::json!({
                "entity_type": "folder",
                "entity_id": real,
                "parent_id": elsewhere,
            }),
        )
        .expect("the move should be accepted");

    // And an index walk takes the folder up under its real id.
    let mut adopted = fresh(
        EntityId::folder(real),
        Some(elsewhere),
        "Projects",
        LocalStatus::Synced,
    );
    adopted.synced_placement = Some(Placement {
        parent: Some(elsewhere),
        name: "Projects".into(),
    });
    device.store.put_entry(&adopted).unwrap();

    // Now the retry gets its replayed answer, naming an id this store has.
    clock.advance_secs(30 * 60);
    let now = device.now();
    let e: ExecEnv = env(&device, &now);
    let report = run_queued(&e).expect("run");

    assert_eq!(
        report.done, 1,
        "the folder is on the server; saying so is the only honest outcome"
    );
    let left: Vec<i64> = device
        .store
        .every_entry()
        .unwrap()
        .iter()
        .filter(|e| e.id.entity_type == jd_core::EntityType::Folder)
        .map(|e| e.id.server_id)
        .collect();
    assert!(
        !left.contains(&provisional.server_id),
        "the duplicate record should be folded away, not left to retry: {left:?}"
    );
    let inner = device.store.get_entry(child).unwrap().expect("the child");
    assert_eq!(
        inner.remote.parent,
        Some(real),
        "and what was inside it now hangs off the folder that really exists"
    );
}

/// The user saves the file again while it is going up.
///
/// The bytes that landed are a real version of the file and this device sent
/// them, so the upload has not failed and there is nothing to tell anyone
/// about. What matters is that the entry comes out of it **findable**: the
/// scanner is offered only entries with an agreed placement, so an entry left
/// without one has no path to be looked for at, and the file sitting on the
/// disk stops belonging to anything the engine knows.
///
/// From there the client never goes quiet again. Each pass reads those bytes as
/// a brand-new file, mints an identity, uploads it, and the server — which
/// allows one name per folder — hands back the id this entry already has, so
/// the record folds away and this one is left exactly as it was. Nothing is
/// queued, nothing is wrong, and the tree matches the server the whole time;
/// the device simply never reports itself settled. The soak rig ran a full
/// campaign like that before this was understood.
#[test]
fn a_file_saved_again_mid_upload_is_still_a_file_the_scanner_can_find() {
    let (_clock, server, device) = world();
    let first = b"the version that goes up";
    let second = b"the version the user saved over it while it was going";
    device.fs.user_write("notes.txt", first);

    // The save happens at the one instant the client cannot see: the bytes are
    // the server's, and the answer has not come back yet.
    let disk = device.fs.clone();
    server.while_completing_an_upload(move || {
        disk.user_write("notes.txt", second);
    });

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
    assert_eq!(report.done, 1, "the bytes landed, so the upload is done");
    assert_eq!(server.blob(&sha256_hex(first)).unwrap(), first);

    let entries = device.store.children_of(None).unwrap();
    assert_eq!(entries.len(), 1);
    let entry = &entries[0];
    assert!(!entry.id.is_provisional(), "the server named it");

    // The whole point: an agreed placement, so the next scan has somewhere to
    // look and the file on the disk is claimed rather than discovered afresh.
    assert_eq!(
        entry.synced_placement.as_ref().map(|p| p.name.as_str()),
        Some("notes.txt"),
        "an entry with no agreed placement is invisible to the scanner"
    );
    assert_eq!(
        entry.synced_content.as_ref().map(|c| c.sha256.as_str()),
        Some(sha256_hex(first).as_str()),
        "the agreement is about the bytes the server actually took"
    );

    // And no fingerprint, which is what stops the newer save being lost. A
    // fingerprint is only ever permission to skip reading the file; without one
    // the next scan re-hashes it, sees bytes that differ from the agreement,
    // and sends them.
    assert!(
        entry.synced_fingerprint.is_none(),
        "recording a fingerprint here would let the next scan skip the file and \
         the user's newer save would never go up"
    );
    assert_eq!(device.fs.peek("notes.txt").unwrap(), second);
}

/// The bytes are already on the disk, and the engine has no record of it.
///
/// An ordinary way to arrive here: the same file reaches the folder from two
/// directions, or a device is re-linked over a drive folder it already has. The
/// download runs, `make_room` is handed the incoming hash, finds exactly those
/// bytes at the path and correctly leaves them alone — and the commit then
/// refuses, because an entry with no agreement has no fingerprint to guard the
/// swap with.
///
/// What that refusal means is "this file is already here", and it used to be
/// read as "somebody edited this while it was downloading". An entry that has
/// never synced has no agreed content to compare against, so that reading was
/// not merely wrong, it was unreachable-by-construction: the answer was always
/// an unseen edit, always Overtaken, and Overtaken drops the operation without
/// touching the record that asked for it. The next pass planned the same
/// download, and so did the one after.
///
/// The rig had it as thirteen files at once on a settled device — empty queue,
/// no error, no issue raised, and every one of those files sitting on the disk
/// at exactly the right size for the whole run.
#[test]
fn a_file_already_on_disk_is_adopted_rather_than_downloaded_forever() {
    let (_clock, server, device) = world();
    let body = b"the same bytes, arrived by some other road";
    let file_id = server.seed_file(None, "report.txt", body);

    // Already there, and nothing in the store knows it.
    device.fs.user_write("report.txt", body);

    let id = EntityId::file(file_id);
    let mut entry = fresh(id, None, "report.txt", LocalStatus::PendingDownload);
    entry.remote_content = Some(ContentId {
        sha256: sha256_hex(body),
        size: body.len() as u64,
    });
    device.store.put_entry(&entry).unwrap();

    let report = do_one(&device, id, Action::Download);
    assert_eq!(
        report.done, 1,
        "the bytes are already here, so the download has nothing left to do"
    );

    // The whole point: the record now says so, and the next pass plans nothing.
    let after = device.store.get_entry(id).unwrap().expect("the entry");
    assert_eq!(after.status, LocalStatus::Synced);
    assert_eq!(
        after.synced_content.as_ref().map(|c| c.sha256.as_str()),
        Some(sha256_hex(body).as_str()),
        "an entry with no agreement is one the next pass downloads again"
    );
    assert!(
        after.synced_fingerprint.is_some(),
        "without a fingerprint the commit guard refuses the same way next time"
    );
    assert_eq!(device.fs.peek("report.txt").unwrap(), body);
}

#[test]
fn a_file_standing_where_a_folder_goes_is_moved_aside_not_built_over() {
    // A path is a file or a directory and never both, so a file holding the
    // name a folder needs has to go somewhere before the folder can exist.
    //
    // Nothing used to move it. Creating the folder read the refusal as
    // "already there is the outcome we wanted" -- true of a folder, and this is
    // not one -- and recorded the folder synced with a file standing in its
    // place. Every child then failed to land, because a disk puts nothing
    // beneath a file, and the refusal they got named the file in the way
    // rather than the file being written: read as an edit here, it dropped the
    // operation and left the record asking for the same download every pass.
    let (_clock, server, device) = world();
    let folder_id = server.seed_folder(None, "Report");
    let body = b"a child of the folder";
    let file_id = server.seed_file(Some(folder_id), "notes.txt", body);

    let mine = b"the user's own notes, never uploaded";
    device.fs.user_write("Report", mine);

    let fid = EntityId::folder(folder_id);
    device
        .store
        .put_entry(&fresh(fid, None, "Report", LocalStatus::PendingDownload))
        .unwrap();

    let placement = Placement {
        parent: None,
        name: "Report".into(),
    };
    let folder = do_one(&device, fid, Action::CreateLocalFolder { placement });
    assert_eq!(folder.done, 1, "the folder is made, the file steps aside");

    // The user's file is still here, under a name that says what happened.
    let kept = device
        .fs
        .all_paths()
        .into_iter()
        .find(|p| device.fs.peek(p).as_deref() == Some(mine.as_slice()))
        .expect("the file the user saved was not destroyed to make room");
    assert_ne!(kept, "Report", "and it is no longer holding the folder's name");
    assert!(
        device
            .store
            .open_issues()
            .unwrap()
            .iter()
            .any(|i| i.kind == "kept_aside"),
        "the user is told their file was moved"
    );

    // And with the folder real, its children land in it.
    let cid = EntityId::file(file_id);
    let mut child = fresh(cid, Some(folder_id), "notes.txt", LocalStatus::PendingDownload);
    child.remote_content = Some(ContentId {
        sha256: sha256_hex(body),
        size: body.len() as u64,
    });
    device.store.put_entry(&child).unwrap();

    let arrived = do_one(&device, cid, Action::Download);
    assert_eq!(arrived.done, 1, "nothing is in the way any more");
    assert_eq!(device.fs.peek("Report/notes.txt").unwrap(), body);
}

#[test]
fn a_download_landing_does_not_overwrite_what_the_user_just_saved() {
    // The same window the fleet-level scenario covers, at the one operation
    // that opens it: the engine has decided this path is clear, and between
    // that decision and the bytes arriving the user saves over it.
    //
    // Nothing here is a conflict either side could have seen coming. The save
    // is the only copy of itself, so it has to survive -- under some name.
    let (_clock, server, device) = world();
    let theirs = b"the version the other device sent";
    let file_id = server.seed_file(None, "notes.txt", theirs);

    let mine_old = b"what this device had a moment ago";
    device.fs.user_write("notes.txt", mine_old);

    // Agreed content but no fingerprint, which is what sends the engine through
    // `make_room` rather than the fingerprint guard.
    let id = EntityId::file(file_id);
    let mut entry = fresh(id, None, "notes.txt", LocalStatus::PendingDownload);
    entry.synced_placement = Some(Placement {
        parent: None,
        name: "notes.txt".into(),
    });
    entry.synced_content = Some(ContentId {
        sha256: sha256_hex(mine_old),
        size: mine_old.len() as u64,
    });
    entry.remote_content = Some(ContentId {
        sha256: sha256_hex(theirs),
        size: theirs.len() as u64,
    });
    device.store.put_entry(&entry).unwrap();

    let mine_new = b"what the user typed while it was landing";
    let fs = device.fs.clone();
    let mut fired = false;
    device.fs.while_a_download_lands(move |_target| {
        if !fired {
            fired = true;
            fs.user_write("notes.txt", mine_new);
        }
    });

    do_one(&device, id, Action::Download);

    let held: Vec<Vec<u8>> = device
        .fs
        .all_paths()
        .iter()
        .filter_map(|p| device.fs.peek(p))
        .collect();
    assert!(
        held.iter().any(|b| b.as_slice() == mine_new.as_slice()),
        "the save made in the landing window was built over"
    );
}

// ---------------------------------------------------------------------------
// A refusal names a folder, and only that folder is condemned
// ---------------------------------------------------------------------------

#[test]
fn a_parent_trashed_refusal_condemns_the_folder_the_server_named() {
    let (_clock, server, device) = world();

    // Two folders of the same name in different places. One is in the trash;
    // the other is live and holds work of its own.
    let trashed = server.seed_folder(None, "Archive");
    let live_parent = server.seed_folder(None, "Projects");
    let live = server.seed_folder(Some(live_parent), "Archive");
    server
        .action(
            "drive_trash",
            &serde_json::json!({ "entity_type": "folder", "entity_id": trashed }),
        )
        .expect("the folder goes to the trash");

    for (id, parent, name) in [
        (trashed, None, "Archive"),
        (live_parent, None, "Projects"),
        (live, Some(live_parent), "Archive"),
    ] {
        device
            .store
            .put_entry(&fresh(
                EntityId::folder(id),
                parent,
                name,
                LocalStatus::Synced,
            ))
            .unwrap();
    }

    // A folder waiting to be created. Its entry says it belongs in the trashed
    // folder — that is what the create will actually send, because a create
    // re-resolves its destination at the moment it runs. Its PLAN still names
    // the live folder of the same name, which is the stale half.
    let new_id = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(
            new_id,
            Some(trashed),
            "Notes",
            LocalStatus::PendingUpload,
        ))
        .unwrap();

    do_one(
        &device,
        new_id,
        Action::CreateRemoteFolder {
            placement: Placement {
                parent: Some(live),
                name: "Notes".into(),
            },
        },
    );

    let condemned = |id: i64| -> bool {
        device
            .store
            .get_entry(EntityId::folder(id))
            .unwrap()
            .expect("the folder is still tracked")
            .remote_deleted
    };

    assert!(
        !condemned(live),
        "the live folder that merely shares its name is untouched — reading the \
         stale plan instead of the server's answer condemned it, and the device \
         then trashed its own copy and forgot a folder the server still held"
    );
    assert!(!condemned(live_parent), "and neither is anything above it");
    assert!(
        condemned(trashed),
        "the folder the server refused is recorded as gone"
    );
}

#[test]
fn a_refusal_that_names_no_folder_condemns_nothing() {
    // A server that does not say which folder is in the trash leaves the client
    // with no evidence about any folder. Guessing from the plan is what this
    // replaces, so the answer is to record nothing and let the change feed
    // deliver the deletion the ordinary way.
    use jd_proto::ProtoError;

    let named = ProtoError::Api {
        status: 422,
        errortype: "ActionError".into(),
        message: "That folder is in the trash.".into(),
        data: serde_json::json!({ "reason": "parent_trashed", "folder_id": 512 }),
    };
    assert_eq!(named.parent_trashed_folder_id(), Some(512));

    let unnamed = ProtoError::Api {
        status: 422,
        errortype: "ActionError".into(),
        message: "That folder is in the trash.".into(),
        data: serde_json::json!({ "reason": "parent_trashed" }),
    };
    assert!(unnamed.parent_trashed(), "still recognised as the refusal");
    assert_eq!(unnamed.parent_trashed_folder_id(), None);

    let other = ProtoError::Api {
        status: 400,
        errortype: "ActionError".into(),
        message: "A folder with that name already exists here.".into(),
        data: serde_json::json!({ "reason": "name_taken", "folder_id": 512 }),
    };
    assert_eq!(
        other.parent_trashed_folder_id(),
        None,
        "a folder id on some other refusal is not a trashed parent"
    );
}

#[test]
fn a_create_retried_after_its_answer_was_lost_does_not_revive_a_deleted_folder() {
    let (clock, server, device) = world();

    let provisional = EntityId::folder(device.store.next_provisional_id().unwrap());
    device
        .store
        .put_entry(&fresh(provisional, None, "Sub 15", LocalStatus::PendingUpload))
        .unwrap();
    device.fs.user_mkdir("Sub 15");

    // The create reaches the server and does its work; the answer is lost.
    device.net.set_faults(NetFaults {
        lose_answer_to: Some("drive_folder_create".into()),
        ..NetFaults::none()
    });
    let first = do_one(
        &device,
        provisional,
        Action::CreateRemoteFolder {
            placement: Placement {
                parent: None,
                name: "Sub 15".into(),
            },
        },
    );
    assert_eq!(first.retrying, 1, "the answer was lost, so it retries");
    device.net.set_faults(NetFaults::none());

    // Somebody else deletes the folder while this device is still retrying.
    assert!(
        server.tree().contains_key("Sub 15"),
        "the folder reached the server despite the lost answer"
    );
    let id = folder_id_named(&server, "Sub 15").expect("the folder has an id");
    server
        .action(
            "drive_trash",
            &serde_json::json!({ "entity_type": "folder", "entity_id": id }),
        )
        .expect("the trash should be accepted");

    // The retry replays the answer the first attempt produced — a snapshot from
    // before the delete. Taking it at face value leaves this device the only
    // one that thinks the folder is there, AGREEING with a server that does
    // not have it, so nothing it ever does will disagree.
    clock.advance_secs(60 * 60); // past the retry backoff
    let now = device.now();
    let e: ExecEnv = env(&device, &now);
    run_queued(&e).expect("run");

    let entries: Vec<_> = device
        .store
        .every_entry()
        .unwrap()
        .into_iter()
        .filter(|entry| entry.id.entity_type == jd_core::model::EntityType::Folder)
        .collect();
    assert!(
        entries.iter().all(|e| e.remote_deleted),
        "the retry looked, and the folder it created is in the trash: {:?}",
        entries
            .iter()
            .map(|e| (e.id.server_id, e.remote.name.clone(), e.remote_deleted))
            .collect::<Vec<_>>()
    );
    assert!(
        !server.tree().contains_key("Sub 15"),
        "and the server still does not have it"
    );
}

/// The server id of the live folder with this name, if there is one.
fn folder_id_named(server: &MockServer, name: &str) -> Option<i64> {
    for id in 1..2000i64 {
        let answer = server.action(
            "drive_stat",
            &serde_json::json!({
                "entities": [{ "entity_type": "folder", "entity_id": id }]
            }),
        );
        if let Ok(v) = answer {
            if let Some(items) = v.get("items").and_then(|i| i.as_array()) {
                if let Some(item) = items.first() {
                    if item.get("name").and_then(|n| n.as_str()) == Some(name) {
                        return Some(id);
                    }
                }
            }
        }
    }
    None
}

#[test]
fn a_rename_retried_after_its_answer_was_lost_records_where_the_file_actually_is() {
    let (clock, server, device) = world();

    // A file both this device and another will rename.
    let id = EntityId::file(server.seed_file(None, "slot-3.dat", b"the contested bytes"));
    let mut entry = fresh(id, None, "slot-3.dat", LocalStatus::Synced);
    entry.synced_placement = Some(Placement {
        parent: None,
        name: "slot-3.dat".into(),
    });
    entry.remote_content = Some(ContentId {
        sha256: sha256_hex(b"the contested bytes"),
        size: 19,
    });
    entry.synced_content = entry.remote_content.clone();
    device.store.put_entry(&entry).unwrap();
    device.fs.user_write("slot-3.dat", b"the contested bytes");

    // The rename reaches the server and does its work; the answer is lost.
    device.net.set_faults(NetFaults {
        lose_answer_to: Some("drive_rename".into()),
        ..NetFaults::none()
    });
    let first = do_one(
        &device,
        id,
        Action::ApplyLocalMove {
            to: Placement {
                parent: None,
                name: "slot-2.dat".into(),
            },
        },
    );
    assert_eq!(first.retrying, 1, "the answer was lost, so it retries");
    device.net.set_faults(NetFaults::none());

    // Another device renames it again before the retry gets its turn.
    server
        .action(
            "drive_rename",
            &serde_json::json!({
                "entity_type": "file",
                "entity_id": id.server_id,
                "name": "slot-1.dat",
            }),
        )
        .expect("the second rename should be accepted");

    clock.advance_secs(60 * 60); // past the retry backoff
    let now = device.now();
    let e: ExecEnv = env(&device, &now);
    run_queued(&e).expect("run");

    let after = device
        .store
        .get_entry(id)
        .unwrap()
        .expect("the entry is still tracked");
    assert_eq!(
        after.remote.name, "slot-1.dat",
        "the retry replayed an answer describing a rename the server has moved \
         past; recording it would leave this device agreeing with a server that \
         calls the file something else, which no later pass can ever notice"
    );
    assert_eq!(
        after.synced_placement.as_ref().map(|p| p.name.as_str()),
        Some("slot-1.dat"),
        "and the agreement says the same"
    );
}

