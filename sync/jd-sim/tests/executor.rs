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
    let (clock, server, device) = world();
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
