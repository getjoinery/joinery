//! One full pass of the engine: look at both sides, decide, do.
//!
//! Everything else in this crate is a piece. This is the piece that holds them:
//! ask the server what changed, look at the disk, work out what each side did
//! since the last agreement, decide, order, journal, execute. A client is that
//! loop run over and over.
//!
//! Two things about the order below are load-bearing.
//!
//! **The remote side is read first, and its cursor is advanced last.** The feed
//! is the only place a change is ever mentioned; a cursor moved before the work
//! is durable is a change nobody will look for again. Re-reading a change that
//! was already applied costs one wasted comparison, which is the cheap mistake.
//!
//! **Deltas for every tracked entry are computed every pass**, not just for the
//! entries the feed mentioned. An entry's remote delta is measured from the
//! agreement, so an edit reported once and interrupted before it landed is
//! reported again next pass, and the pass after that, until the bytes are
//! actually here. Measuring against the last *observation* instead would report
//! it once and then lose it forever.

use std::collections::HashMap;

use serde_json::{json, Value};

use crate::execute::{journal, run_queued, ExecEnv, ExecError, ExecReport};
use crate::model::{ContentId, Delta, EntityId, EntityType, Entry, LocalStatus, Placement};
use crate::reconcile::Context;
use crate::remote::{local_delta, remote_delta, RemoteState};
use crate::round::{run_round, DeletePolicy, RoundInput, RoundOutcome};
use crate::scan::{pair, KnownLocal, ObservedFile};

/// The server will not answer a stat for more than this many entities at once.
const STAT_BATCH: usize = 500;

/// How much of the feed to take in one pass. A bounded batch keeps a device
/// that has been off for a month from trying to hold a month of changes in
/// memory before it does anything useful with the first one.
const FEED_BATCH: i64 = 500;

#[derive(Debug, Clone, Default)]
pub struct PassOutcome {
    pub round: RoundOutcome,
    pub exec: ExecReport,
    /// The feed could not be resumed and the whole index was walked instead.
    pub reset: bool,
    /// Entities the server mentioned this pass.
    pub remote_changes: usize,
    /// Files found on disk that nothing was tracking.
    pub local_creations: usize,
    /// The sync folder was not available, so nothing was done. Deliberately not
    /// an error and emphatically not a mass delete: an unplugged drive means
    /// wait, not "every file is gone".
    pub root_unavailable: bool,
    /// What this filesystem could and could not be asked to hold.
    pub naming: crate::naming::NamingOutcome,
}

impl PassOutcome {
    /// Did this pass find nothing to do? A settled client is one where this
    /// keeps coming back true.
    ///
    /// Work waiting on a backoff counts as work. A pass that quietly reports
    /// nothing while four uploads sit in the journal waiting to be retried is
    /// telling the user their files are safely synced when they are not.
    pub fn quiet(&self) -> bool {
        self.round.plan.is_empty() && self.exec.attempted() == 0 && self.exec.deferred == 0
    }
}

/// Run one pass.
///
/// `token_for` supplies scratch names for rename cycles and `key_for` supplies
/// idempotency keys; both are parameters so a simulated run reproduces exactly
/// from its seed.
pub fn run_pass(
    env: &ExecEnv,
    ctx: &Context,
    policy: DeletePolicy,
    key_for: &mut dyn FnMut() -> String,
    token_for: &mut dyn FnMut(EntityId) -> String,
) -> Result<PassOutcome, ExecError> {
    let mut out = PassOutcome::default();
    if env.vfs.root().is_none() {
        out.root_unavailable = true;
        return Ok(out);
    }

    // ---- anything a kill left half-done -------------------------------------
    //
    // Asked again on every pass that finds something outstanding, rather than
    // once when the process starts. A machine that comes back up while the
    // network is still down cannot answer the question then, and the operation
    // it cannot answer is one nothing else will ever touch: interrupted ops are
    // not run, and their entities are not re-planned. Asked only at startup,
    // that is permanent -- the network comes back, the device carries on
    // reporting itself quiet, and the work stays frozen until somebody happens
    // to restart it.
    //
    // Cheap when there is nothing to do, which is almost always: one indexed
    // lookup that comes back empty.
    if !env.store.interrupted_ops()?.is_empty() {
        // The error is not this pass's to report. It means the server is out of
        // reach, which the poll below is about to say for itself.
        let _ = crate::execute::recover(env);
    }

    // ---- what the server did ------------------------------------------------
    let (fresh, next_cursor, reset) = poll_remote(env)?;
    out.reset = reset;
    out.remote_changes = fresh.len();
    for (id, state) in &fresh {
        absorb_remote(env, *id, state)?;
    }

    // ---- nothing left with no way back to the root --------------------------
    //
    // First, because everything below finds entries by walking down from the
    // root and so cannot see these at all.
    // Re-derived every pass, and withdrawn the moment it stops being true: this
    // issue describes a state rather than an event, so leaving a stale one
    // standing tells the user to look at something that has already resolved,
    // and the only way they could clear it is by hand.
    let mut still_stranded = false;
    if sweep_stranded_entries(env)? > 0 {
        // An entry the server knows about, with no way back to the root, is a
        // hole in this store's picture rather than a wrong entry: the folder it
        // sits in still exists, we have just lost our record of it. Re-deriving
        // from the index is what a feed reset does and it is the right answer
        // here too.
        //
        // A soak run spent three campaigns on this. A folder renamed while the
        // files inside it were still uploading left the old folder entry with a
        // missing ancestor, and the file beneath it retried an upload into a
        // path that could not be resolved — forever, silently, because the walk
        // from the root could not reach it to notice.
        for (id, state) in walk_index(env)? {
            absorb_remote(env, id, &state)?;
        }
        out.reset = true;
        let left = sweep_stranded_entries(env)?;
        still_stranded = left > 0;
        if left > 0 {
            // The server's index does not have the ancestor either. Say so and
            // leave it: the walk above will run again next pass, which is waste
            // but only waste. Discarding the records instead was tried, and the
            // soak run that followed lost seven files where the run before it
            // had lost none — the records are the only thing tying a local file
            // to what the server already holds, and throwing them away to stop
            // a repeated index walk trades a real risk for a cost.
            env.store.raise_issue(
                None,
                "store_inconsistent",
                &format!(
                    "{left} tracked item(s) sit in a folder this device has lost its record of, \
                     and the server's index does not have it either"
                ),
                (env.now_ms)() as i64,
            )?;
        }
    }
    if !still_stranded {
        env.store.withdraw_issues("store_inconsistent")?;
    }

    // ---- one directory, one entry; one file, one entry ----------------------
    //
    // Before naming, because naming is what turns this into a deadlock: it sees
    // two entries claiming one name and refuses the real one.
    merge_duplicate_folders(env)?;
    merge_duplicate_files(env)?;

    // ---- what each entry is called here -------------------------------------
    //
    // Before the disk is walked, not after. The scan pairs what is on disk
    // against what the engine believes each entry is called, so that belief has
    // to be current first — otherwise a name the server just changed is compared
    // against the old local spelling and reads as a local rename back.
    let root_prefix = env
        .vfs
        .root()
        .map(|r| r.as_os_str().len())
        .unwrap_or_default();
    out.naming = crate::naming::apply_naming(env, &env.vfs.personality(), root_prefix)?;
    for (id, reason) in &out.naming.unsyncable {
        env.store.raise_issue(
            Some(*id),
            "unsyncable",
            &format!("{reason:?}"),
            (env.now_ms)() as i64,
        )?;
    }
    // A name this disk cannot hold is a state, and states end: the rival gets
    // renamed, the clash clears, the entry goes away. The complaint has to end
    // with it, or the user is left with a permanent warning about a file that
    // is now perfectly fine and no way to clear it but by hand.
    //
    // The entry's own status is what says so — NOT `out.naming.unsyncable`,
    // which is only what this pass decided just now. An entry already settled as
    // unsyncable is not re-reported every pass, so reading that list as the full
    // set withdraws a complaint that is still true. A Mac holding one of two
    // case-clashing siblings caught exactly that.
    {
        let unsyncable_now: std::collections::HashSet<EntityId> = all_entries(env)?
            .into_iter()
            .filter(|e| matches!(e.status, LocalStatus::Unsyncable(_)))
            .map(|e| e.id)
            .collect();
        for issue in env.store.open_issues()? {
            if issue.kind != "unsyncable" {
                continue;
            }
            let Some(id) = issue.entity else { continue };
            if !unsyncable_now.contains(&id) {
                env.store.dismiss_issue(issue.issue_id)?;
            }
        }
    }

    // ---- what this computer did --------------------------------------------
    let observed = observe(env)?;
    let known = known_local(env)?;
    let scan = pair(&known, &observed);

    // Anything on disk that nothing is tracking gets an identity now, so that
    // the loop below can treat it like any other entry. Folders first: a new
    // file inside a new folder cannot say where it lives until the folder has
    // one.
    let dirs_on_disk = observed_dirs(env)?;
    let mut folder_ids = folder_paths(env)?;

    // A folder the user renamed is a folder, renamed — not a new folder plus a
    // thousand files that moved into it. Without this the old folder is left
    // behind on the server, everything inside is re-parented one file at a
    // time, and the folder's sharing and history stay with a shell nobody can
    // see any more.
    let folders = detect_folder_moves(env, &observed, &dirs_on_disk, &mut folder_ids)?;

    for dir in &dirs_on_disk {
        if folder_ids.contains_key(dir) {
            continue;
        }
        let Some(placement) = placement_of(dir, &folder_ids) else {
            continue;
        };
        let id = EntityId::folder(env.store.next_provisional_id()?);
        let mut entry = blank(id, &placement);
        entry.is_encrypted = parent_is_encrypted(env, placement.parent)?;
        env.store.put_entry(&entry)?;
        folder_ids.insert(dir.clone(), id.server_id);
        out.local_creations += 1;
    }
    for file in &scan.created {
        let Some(placement) = placement_of(&file.path, &folder_ids) else {
            // Its folder is not tracked yet. Nothing is lost: the folder gets an
            // identity above on this pass or the next, and the file follows.
            continue;
        };
        let id = EntityId::file(env.store.next_provisional_id()?);
        let mut entry = blank(id, &placement);
        // Encryption is a property of where a thing lives, not of the thing:
        // the server decides an upload is encrypted by looking at the
        // destination folder. Working that out HERE, when the file first gets
        // an identity, is what makes the upload path encrypt it — a file that
        // reached the uploader marked plaintext would be sent in the clear into
        // a folder the user believes is private, and the server would store it
        // exactly as sent.
        entry.is_encrypted = parent_is_encrypted(env, placement.parent)?;
        // ...and if it belongs in a vault this device cannot open, say so and
        // stop, exactly as the download side does. A device linked without
        // encrypted folders does not materialize the vault, but nothing stops
        // the user making a folder of that name and saving into it -- and the
        // engine then has a local file it can neither send in the clear (the
        // server would store it exactly as sent, inside the folder the user
        // believes is private) nor encrypt.
        //
        // Planned as an upload it becomes an operation that cannot succeed and
        // is retried anyway: two thousand attempts against "this device has no
        // key for encrypted folders", the device never quiet, and nothing ever
        // told to the user. `PendingKey` is the same bargain the download side
        // already makes -- the file waits, visibly, and `apply_naming` releases
        // it by itself the moment a key arrives.
        if entry.is_encrypted && env.vault.is_none() {
            entry.status = LocalStatus::PendingKey;
        }
        env.store.put_entry(&entry)?;
        out.local_creations += 1;
    }

    // ---- what each side did, per entry --------------------------------------
    let mut inputs: Vec<RoundInput> = Vec::new();
    let resolve = |path: &str| placement_of(path, &folder_ids);
    // Anything already in the journal is spoken for. Deciding about it again
    // would queue a second operation doing the same job, once per pass, for as
    // long as the first one kept failing.
    let busy = env.store.entities_with_open_ops()?;
    let written_off = env.store.written_off_now()?;

    for entry in all_entries(env)? {
        if entry.status == LocalStatus::OutOfScope || busy.contains(&entry.id) {
            continue;
        }
        // A name this filesystem cannot hold. There is no local file, so there
        // is nothing to compare and nothing to transfer — the entry waits,
        // visibly, until the clash clears. The one thing still worth acting on
        // is the server deleting it, which the ordinary path handles: no local
        // delta, a remote delete, and the entry is forgotten.
        // A park nobody is coming back for.
        //
        // `.jd-` names are the engine's own and are transient by contract: one
        // step inside one operation. If that operation is gone — withdrawn, or
        // dropped after a kill — the entity is left wearing the scratch name on
        // the server, the naming pass reads the reserved prefix, and the entry
        // parks `Unsyncable(ReservedPrefix)`. The skip immediately below then
        // makes it invisible to every later pass: the device goes QUIET with the
        // user's file under a name nobody chose, raising nothing. That is the
        // silent half of the stranded-park defect, and it is the half no resume
        // can reach, because there is no operation left to resume.
        //
        // Put it back where both sides last agreed. The agreement survives —
        // an index walk writes `remote` and leaves `synced_placement` alone — so
        // this restores the real name rather than inventing one. What is lost
        // with the operation is the journey it was making; the file is not.
        if jd_vfs::is_internal(&entry.remote.name)
            && !busy.contains(&entry.id)
            && !entry.remote_deleted
        {
            if let Some(agreed) = entry.synced_placement.clone() {
                if !jd_vfs::is_internal(&agreed.name) {
                    // The agreed name may have been taken while the park stood.
                    // Asking for it anyway is refused, the op is overtaken, the
                    // rescue is planned again next pass, and the file stays
                    // under the scratch name for ever — non-silent this time,
                    // but never settling either. Doctrine already answers it:
                    // park is a naming verdict, and a give-up that is not about
                    // the name goes BESIDE the agreement rather than into it.
                    let taken: std::collections::HashSet<String> = env
                        .store
                        .every_entry()?
                        .into_iter()
                        .filter(|e| {
                            e.id != entry.id
                                && !e.remote_deleted
                                && !e.id.is_provisional()
                                && e.remote.parent == agreed.parent
                        })
                        .map(|e| e.remote.name)
                        .collect();
                    let mut wanted = agreed.name.clone();
                    let mut n = 0u32;
                    while taken.contains(&wanted) && n < 1000 {
                        n += 1;
                        wanted = (env.conflict_name)(&agreed.name, n);
                    }
                    env.store.queue_op(
                        "move_remote",
                        entry.id,
                        &serde_json::json!({
                            "parent": agreed.parent, "name": wanted,
                        })
                        .to_string(),
                        &key_for(),
                    )?;
                    env.store.raise_issue(
                        Some(entry.id),
                        "reconcile",
                        &format!(
                            "an unfinished operation left this on the server as {}; \
                             putting it back as {}",
                            entry.remote.name, wanted
                        ),
                        (env.now_ms)() as i64,
                    )?;
                    continue;
                }
            }
            // No agreement to put it back to, or the agreement is itself a
            // scratch name. Nothing here can name the file, but going quiet
            // about it is the posture this whole branch prosecutes: the user
            // would be left with the engine's own name in their folder and
            // nothing saying why.
            env.store.raise_issue(
                Some(entry.id),
                "reconcile",
                &format!(
                    "an unfinished operation left this on the server as {}, and there is \
                     no recorded name to put it back to",
                    entry.remote.name
                ),
                (env.now_ms)() as i64,
            )?;
        }
        if matches!(entry.status, LocalStatus::Unsyncable(_)) && !entry.remote_deleted {
            continue;
        }
        // An encrypted file with no key for it here. Same shape as above and for
        // a sharper reason: falling through would decide about it in the
        // plaintext domain — the server's name is a placeholder and its hash is
        // of the ciphertext — and plan a download that writes bytes nobody can
        // read to a path that is not the file's name. A remote delete still gets
        // through, so a file that goes away while its key is outstanding does
        // not sit here forever.
        if entry.status == LocalStatus::PendingKey && !entry.remote_deleted {
            continue;
        }
        // Bytes this device has already proven it cannot open: they arrived
        // exactly as the server described them and still failed their
        // authentication tag, so fetching the same bytes with the same key can
        // only fail the same way. Planning the download again is how a device
        // stays busy for ever over one damaged file, reporting nothing but
        // "decryption failed" and never going quiet.
        //
        // Nothing here is permanent. The note names the content and the key it
        // was proven against, so better bytes or a corrected grant lift it with
        // no lifting logic to run. A remote delete still gets through, exactly
        // as it does for the two skips above, so a file thrown away while it
        // was unreadable is still cleaned up.
        if !entry.remote_deleted && written_off.contains(&entry.id) {
            continue;
        }

        // Something created here that the server has not named yet. There is no
        // remote side to compare against, so it stays a creation every pass
        // until the create actually lands. Falling through to the ordinary path
        // would read "no agreement" as "the server made this", and plan a
        // download of a file that exists nowhere but this disk.
        if entry.id.is_provisional() {
            let Some(path) = relative_path(env, &entry)? else {
                // Belt and braces: the sweep at the top of the pass has already
                // removed anything with no way back to the root, and this list
                // was walked from the root anyway.
                env.store.delete_subtree(entry.id)?;
                continue;
            };
            let gone = match entry.id.entity_type {
                EntityType::File => !observed.iter().any(|o| o.path == path),
                EntityType::Folder => !dirs_on_disk.contains(&path),
            };
            if gone {
                // Created and removed again before it ever reached the server.
                // There is nothing to tell anyone about — and for a folder that
                // means everything inside it too, or its children are left
                // pointing at a parent that is not there any more.
                env.store.delete_subtree(entry.id)?;
                continue;
            }
            let content = observed.iter().find(|o| o.path == path).map(|o| ContentId {
                sha256: o.sha256.clone(),
                size: o.fingerprint.size,
            });
            let placement = entry.remote.clone();
            let depth = depth_of(&path);
            inputs.push(RoundInput {
                entry,
                local: Delta::Created { placement, content },
                remote: Delta::None,
                depth,
            });
            continue;
        }

        let local = match scan.change_for(entry.id) {
            Some(change) => local_delta(change, resolve),
            // Folders are absent from the file scan, so what happened to one
            // locally is worked out separately.
            None => folder_delta(&entry, &folders),
        };
        // A move that arrives exactly where the agreement already puts it is not
        // a move.
        //
        // The scan works in paths and the agreement works in placements, and the
        // two come apart when a FOLDER has been displaced: every file inside it
        // is at a new path while its parent and name -- which is all a placement
        // is -- have not changed at all. Read as a move it becomes a request to
        // the server to put the file exactly where the server already has it:
        // accepted, applied, and derived again from the same disk on the next
        // pass, for as long as the folder stays where it is. One file, one
        // round-trip, every pass, and a device that is never quiet.
        let local = match local {
            Delta::Moved { ref to } if entry.synced_placement.as_ref() == Some(to) => Delta::None,
            Delta::MovedAndEdited { ref to, ref content }
                if entry.synced_placement.as_ref() == Some(to) =>
            {
                Delta::Edited {
                    content: content.clone(),
                }
            }
            other => other,
        };
        // A move that carries something across the edge of a vault.
        //
        // The server holds no key, so it cannot turn plaintext into ciphertext
        // or back again: a file cannot change protection level by being moved,
        // and the move is refused outright whichever way it is going. The way
        // across is the one the server names -- upload the bytes afresh at the
        // destination, and trash what was at the source.
        //
        // Planned as a move it is an operation that cannot succeed, refused
        // every time, dropped every time, and re-derived from the same disk on
        // the very next pass: the device never quiet, the queue always empty,
        // one issue raised the first time round and nothing after it. Seeds
        // 78350 and 78495 each spent a whole campaign there.
        if let Some(crossing) = crossing_a_vault_edge(env, &entry, &local)? {
            if crossing == Crossing::OutOfReach {
                // A vault folder on its way out. Say so, once, and do not plan
                // the move: the server refuses it, and asking again next pass
                // and every pass after that is the loop this whole branch
                // exists to end. Nothing is undone -- the folder stays where
                // the user dragged it, and the server keeps its encrypted copy
                // exactly where it was.
                env.store.raise_issue(
                    Some(entry.id),
                    "withdrawn",
                    "this folder is protected and cannot be moved out of the vault from here; \
                     change its protection level first, then move it",
                    (env.now_ms)() as i64,
                )?;
                continue;
            }
            if env.vault.is_none() {
                // No key here, so this device cannot do the re-upload either --
                // and it must not trash the server's copy on the strength of a
                // conversion it cannot perform. The same bargain the creation
                // path makes: the file stays where the user put it, the entry
                // waits visibly, and `apply_naming` releases it by itself the
                // moment a key arrives.
                if entry.status != LocalStatus::PendingKey {
                    let mut waiting = entry.clone();
                    waiting.status = LocalStatus::PendingKey;
                    env.store.put_entry(&waiting)?;
                }
                continue;
            }
            // Trash the source and forget it. `trash_remote` never touches the
            // local file, so the next scan finds the bytes at their new path as
            // a local creation and uploads them with the destination's
            // protection -- which is the conversion, done by the one part of
            // the engine that already knows how to do it.
            env.store.queue_op("trash_remote", entry.id, "{}", &key_for())?;
            continue;
        }
        // Measured from the agreement, using the freshest remote state we hold.
        // For an entity the feed did not mention this pass that is what we
        // recorded last time — which still reports an unfinished change, and is
        // the entire reason this is not measured from the last observation.
        let remote = remote_delta(&entry, &observed_remote(&entry));
        if local.is_none() && remote.is_none() {
            continue;
        }
        let depth = depth_for(env, &entry)?;
        inputs.push(RoundInput {
            entry,
            local,
            remote,
            depth,
        });
    }

    // ---- decide, journal, do -------------------------------------------------
    let synced_total = env.store.synced_count()?;
    out.round = run_round(inputs, synced_total, ctx, policy, token_for);
    for (id, issue) in &out.round.issues {
        env.store.raise_issue(
            Some(*id),
            "reconcile",
            &format!("{issue:?}"),
            (env.now_ms)() as i64,
        )?;
    }
    // The duplicate-name renames first, and in their own batch. They exist to
    // free a name somebody else is waiting on, so running them ahead of the
    // round's own work is the point rather than an accident of ordering -- and
    // they are decided before the scan, so they cannot be part of the round.
    if !out.naming.renames.is_empty() {
        let freeing = crate::order::Plan {
            ops: out
                .naming
                .renames
                .iter()
                .map(|(id, from, to)| crate::order::PlannedOp {
                    entity: *id,
                    action: crate::reconcile::Action::ApplyLocalMove { to: to.clone() },
                    stage: crate::order::Stage::Move,
                    rank: 0,
                    from: Some(from.clone()),
                })
                .collect(),
            broken_cycles: Vec::new(),
        };
        journal(env.store, &freeing, key_for)?;
    }
    journal(env.store, &out.round.plan, key_for)?;
    out.exec = run_queued(env)?;

    // The cursor moves only now, once everything the batch implied is durably
    // in the journal. A cursor advanced any earlier is a change the server will
    // never mention again and nothing local knows to ask about.
    if next_cursor > env.store.cursor()? {
        env.store.set_cursor(next_cursor)?;
    }
    Ok(out)
}

// ---------------------------------------------------------------------------
// The remote side
// ---------------------------------------------------------------------------

/// What the server currently holds for a set of entities, the feed position
/// reached, and whether the feed had to be abandoned for a full walk.
pub type RemotePoll = (Vec<(EntityId, RemoteState)>, i64, bool);

/// Read the change feed, or walk the whole index when the feed cannot be
/// resumed.
///
/// A cursor pointing into history the server no longer keeps is answered with a
/// reset, and the only correct response is to look at everything. Carrying on
/// from the new position would leave a hole in the feed, and a hole in a change
/// feed is a file that silently never syncs again.
fn poll_remote(env: &ExecEnv) -> Result<RemotePoll, ExecError> {
    let cursor = env.store.cursor()?;
    let feed = env.api.action(
        "drive_changes",
        json!({ "cursor": cursor, "limit": FEED_BATCH }),
    )?;
    let next = feed
        .get("next_cursor")
        .and_then(Value::as_i64)
        .unwrap_or(cursor);

    if feed.get("reset").and_then(Value::as_bool) == Some(true) {
        return Ok((walk_index(env)?, next, true));
    }

    let mut wanted: Vec<EntityId> = Vec::new();
    let mut a_folder_came_back = false;
    for change in feed
        .get("changes")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default()
    {
        let Some(id) = entity_of(&change) else {
            continue;
        };
        // A restored folder is the one change whose consequences are invisible
        // in the feed. The server brings the whole subtree back and reports the
        // folder alone, so the contents are live again with no record here and
        // every row that describes them already behind the cursor. Statting the
        // folder returns a folder; nothing enumerates what is now inside it,
        // and nothing ever will.
        //
        // The kind is what separates this from a folder that was just created,
        // whose contents are still coming as rows of their own -- so the answer
        // has to be read off `kind` rather than guessed from the shape.
        //
        // Treated as a hole in coverage, which is what it is, and answered the
        // way a feed reset is answered: look at everything. A restore is a rare
        // deliberate act, so one walk each is a bounded price for the only
        // thing that makes the contents visible again.
        if id.entity_type == EntityType::Folder
            && change.get("kind").and_then(Value::as_str) == Some("restored")
        {
            a_folder_came_back = true;
        }
        if !wanted.contains(&id) {
            wanted.push(id);
        }
    }
    if a_folder_came_back {
        return Ok((walk_index(env)?, next, true));
    }
    Ok((stat_all(env, &wanted)?, next, false))
}

/// Walk the entire index. Used after a feed reset, and on a first run.
pub fn walk_index(env: &ExecEnv) -> Result<Vec<(EntityId, RemoteState)>, ExecError> {
    let mut token = String::new();
    let mut out = Vec::new();
    let mut guard = 0;
    loop {
        guard += 1;
        if guard > 10_000 {
            return Err(ExecError::Contract("the index walk does not end".into()));
        }
        let page = env
            .api
            .action("drive_index", json!({ "after_id": token, "limit": 500 }))?;
        for item in page
            .get("items")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default()
        {
            if let Some((id, state)) = state_of(&item) {
                out.push((id, state));
            }
        }
        let next = page
            .get("next_after_id")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        let done = page.get("done").and_then(Value::as_bool) == Some(true);
        if done || next == token {
            break;
        }
        token = next;
    }
    Ok(out)
}

/// Stat a batch of entities, reporting anything the server no longer has as
/// deleted rather than as a failure. Those are opposite instructions and the
/// server distinguishes them, so the client must too.
/// Ask the server what one entity is right now.
///
/// For the places that hold an answer they cannot trust. An idempotent retry
/// replays the response the FIRST attempt produced — a snapshot of a moment
/// that has passed — so a create whose answer was lost comes back describing a
/// folder that may since have been deleted. Nothing in the payload says it is a
/// replay, and nothing can: the guarantee is that the action happened once, not
/// that the world stood still.
pub(crate) fn stat_one(env: &ExecEnv, id: EntityId) -> Result<Option<RemoteState>, ExecError> {
    Ok(stat_all(env, &[id])?.into_iter().next().map(|(_, s)| s))
}

pub(crate) fn stat_all(
    env: &ExecEnv,
    ids: &[EntityId],
) -> Result<Vec<(EntityId, RemoteState)>, ExecError> {
    let mut out = Vec::new();
    for chunk in ids.chunks(STAT_BATCH) {
        if chunk.is_empty() {
            continue;
        }
        let entities: Vec<Value> = chunk
            .iter()
            .map(|id| {
                json!({
                    "entity_type": id.entity_type.to_string(),
                    "entity_id": id.server_id,
                })
            })
            .collect();
        let answer = env
            .api
            .action("drive_stat", json!({ "entities": entities, "urls": false }))?;
        for item in answer
            .get("items")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default()
        {
            if let Some((id, state)) = state_of(&item) {
                out.push((id, state));
            }
        }
        for gone in answer
            .get("missing")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default()
        {
            if let Some(id) = entity_of(&gone) {
                out.push((
                    id,
                    RemoteState {
                        placement: Placement {
                            parent: None,
                            name: String::new(),
                        },
                        content: None,
                        head_change_id: 0,
                        deleted: true,
                        // A `missing` row says only "gone or no longer visible".
                        // It carries no facts about the entity, and absorb
                        // ignores everything but `deleted` for a deletion.
                        is_encrypted: false,
                        wrapped_file_key: None,
                        encrypted_metadata: None,
                    },
                ));
            }
        }
    }
    Ok(out)
}

fn entity_of(v: &Value) -> Option<EntityId> {
    let t = v.get("entity_type").and_then(Value::as_str)?;
    let id = v
        .get("entity_id")
        .or_else(|| v.get("id"))
        .and_then(Value::as_i64)?;
    match t {
        "folder" => Some(EntityId::folder(id)),
        "file" => Some(EntityId::file(id)),
        _ => None,
    }
}

fn state_of(item: &Value) -> Option<(EntityId, RemoteState)> {
    let id = entity_of(item)?;
    let name = item.get("name").and_then(Value::as_str)?.to_string();
    let parent = item
        .get(if id.entity_type == EntityType::Folder {
            "parent_id"
        } else {
            "folder_id"
        })
        .and_then(Value::as_i64);
    let content = item
        .get("content_sha256")
        .and_then(Value::as_str)
        .map(|sha| ContentId {
            sha256: sha.to_string(),
            size: item.get("size").and_then(Value::as_u64).unwrap_or(0),
        });
    Some((
        id,
        RemoteState {
            placement: Placement { parent, name },
            content,
            head_change_id: item
                .get("head_change_id")
                .and_then(Value::as_i64)
                .unwrap_or(0),
            deleted: item.get("deleted").and_then(Value::as_bool) == Some(true),
            // Read, never inferred from the presence of a sibling field: the
            // server states this outright, and every other field in this struct
            // means something different when it is set.
            is_encrypted: item.get("encrypted").and_then(Value::as_bool) == Some(true),
            wrapped_file_key: item
                .get("wrapped_file_key")
                .and_then(Value::as_str)
                .map(str::to_string),
            encrypted_metadata: item
                .get("encrypted_metadata")
                .and_then(Value::as_str)
                .map(str::to_string),
        },
    ))
}

/// Record what the server currently holds, without touching the agreement.
///
/// The separation is the whole design: this is an *observation*, and an
/// observation must never be mistaken for a state both sides settled on. Only
/// the executor, once the bytes have moved, writes the agreement.
pub(crate) fn absorb_remote(
    env: &ExecEnv,
    id: EntityId,
    state: &RemoteState,
) -> Result<(), ExecError> {
    match env.store.get_entry(id)? {
        Some(mut entry) => {
            // The deleted flag is written down, not merely acted on. The feed
            // mentions a deletion exactly once; a pass that heard it and died
            // before removing the local file would never hear it again.
            entry.remote_deleted = state.deleted;
            if !state.deleted {
                entry.remote = state.placement.clone();
                entry.remote_content = state.content.clone();
                entry.head_change_id = state.head_change_id;
                entry.is_encrypted = state.is_encrypted;
                // A key that has ARRIVED is recorded; a key that is absent from
                // this observation does not erase one already held. The grant
                // travels on its own schedule, and a stat taken before it lands
                // must not look like the grant being taken away.
                if state.wrapped_file_key.is_some() {
                    entry.wrapped_file_key = state.wrapped_file_key.clone();
                }
                open_metadata(env, &mut entry, state);
            }
            env.store.put_entry(&entry)?;
        }
        None => {
            // Something on the server this device has never heard of. It gets an
            // entry with no agreement at all, which is what makes its first
            // delta a creation rather than something that looks like a change to
            // a file we already had.
            if state.deleted {
                return Ok(());
            }
            let mut entry = blank(id, &state.placement);
            entry.remote_content = state.content.clone();
            entry.head_change_id = state.head_change_id;
            entry.is_encrypted = state.is_encrypted;
            entry.wrapped_file_key = state.wrapped_file_key.clone();
            entry.status = match id.entity_type {
                EntityType::Folder => LocalStatus::PendingDownload,
                EntityType::File => LocalStatus::PendingDownload,
            };
            open_metadata(env, &mut entry, state);
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// Learn an encrypted file's real name and content id from its metadata blob.
///
/// This is where an encrypted entry stops being an opaque row and becomes a
/// file. Until the blob is opened, everything the server said about the file is
/// a placeholder: the name is `enc-…`, the size is the ciphertext's, and the
/// modification time is deliberately absent — a plaintext mtime would leak when
/// somebody last worked on it. The real values are inside, under the file key.
///
/// Silent on failure, in every one of its arms, and that is deliberate. No
/// vault, no grant yet, a grant issued to a vault the user has replaced, a blob
/// this build cannot parse: none of them is something the *entry* is doing
/// wrong, and none is fixed by refusing to record the rest of what the server
/// said. The entry keeps its placeholder name, `apply_naming` reads that as
/// having no key and marks it `PendingKey`, and the user is told once, at the
/// device level, rather than once per file.
fn open_metadata(env: &ExecEnv, entry: &mut Entry, state: &RemoteState) {
    if !state.is_encrypted {
        return;
    }
    let (Some(vault), Some(wrapped), Some(blob)) = (
        env.vault,
        entry.wrapped_file_key.as_deref(),
        state.encrypted_metadata.as_deref(),
    ) else {
        return;
    };
    let Ok(file_key) = vault.open_file_key(wrapped) else {
        return;
    };
    let Ok(meta) = jd_crypto::drive::decrypt_metadata(blob, &file_key) else {
        return;
    };
    if !meta.name.is_empty() {
        // The name the user chose, replacing the placeholder the server holds.
        // Everything downstream — sibling resolution, case-clash detection,
        // conflict-copy naming — then works in the plaintext domain, which is
        // the only domain those questions have answers in.
        entry.remote.name = meta.name;
    }
    if !meta.cid.is_empty() {
        entry.content_id = Some(meta.cid);
    }
    // The mtime the uploading device recorded, which for an encrypted file the
    // server is never told.
    if meta.mtime.is_some() {
        entry.remote_modified_time = meta.mtime;
    }
}

/// The remote state as currently recorded for an entry.
fn observed_remote(entry: &Entry) -> RemoteState {
    RemoteState {
        placement: entry.remote.clone(),
        content: entry.remote_content.clone(),
        head_change_id: entry.head_change_id,
        deleted: entry.remote_deleted,
        is_encrypted: entry.is_encrypted,
        wrapped_file_key: entry.wrapped_file_key.clone(),
        // Not persisted on the entry: the metadata blob is only ever needed at
        // the moment it is opened, and keeping a stale copy would invite a
        // decode of a name the server has since re-encrypted.
        encrypted_metadata: None,
    }
}

// ---------------------------------------------------------------------------
// The local side
// ---------------------------------------------------------------------------

/// Walk the sync folder and hash what is there.
///
/// The hash cache means a file whose fingerprint has not moved is not read
/// again — but a fingerprint is only ever allowed to skip the read. It is never
/// allowed to stand in for the answer.
fn observe(env: &ExecEnv) -> Result<Vec<ObservedFile>, ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok(Vec::new());
    };
    let mut out = Vec::new();
    let mut reserved: Vec<String> = Vec::new();
    let mut queue = vec![(root.clone(), String::new())];
    // Every scratch name the store still has a live entity for. Built once:
    // asking per file would be a query per directory entry, and the answer
    // cannot change inside one walk.
    let live_swap_names: std::collections::HashSet<String> = env
        .store
        .every_entry()?
        .into_iter()
        .filter(|e| !e.remote_deleted && e.remote.name.starts_with(crate::order::SWAP_PREFIX))
        .map(|e| e.remote.name)
        .collect();
    let mut guard = 0;
    let now_ns = (env.now_ms)().saturating_mul(1_000_000);
    let granularity = env.vfs.personality().mtime_granularity_ns.max(1);
    while let Some((dir, rel)) = queue.pop() {
        guard += 1;
        if guard > 100_000 {
            return Err(ExecError::Contract("the local walk does not end".into()));
        }
        // `read_dir_all`, not `read_dir`: the branch immediately below exists to
        // find abandoned scratch names, and the ordinary listing hides exactly
        // those. Against a real filesystem this walk saw none of them and the
        // recovery could never fire -- dead code that every sweep reported
        // working, because the simulator did not filter and production does.
        for child in env.vfs.read_dir_all(&dir)? {
            if jd_vfs::is_internal(&child.name) {
                // A park nobody is coming back for, left standing on THIS disk.
                //
                // The walk skips internal names, which is right for the spool
                // but leaves an abandoned scratch file permanent: never
                // uploaded (the server refuses the prefix for a real file),
                // never cleaned, invisible to every pass, and visible only to an
                // audit. A device that materialized a peer's park and then
                // watched that peer finish the dance is left holding exactly
                // this — the name it pulled down now belongs to nothing.
                //
                // `SWAP_PREFIX`, never `INTERNAL_PREFIX`: the spool mints
                // `.jd-tmp-` under the same umbrella, and a rule written
                // against `.jd-` would throw away a working file mid-transfer.
                //
                // Safe on a stale view in BOTH directions, which is rare enough
                // to say out loud. A wrong keep costs nothing — the next pass
                // asks again. A wrong trash costs a re-download, because the
                // server still holds the bytes and the trash still holds the
                // copy. So this may act on what it knows without waiting to be
                // certain.
                if child.name.starts_with(crate::order::SWAP_PREFIX)
                    && !live_swap_names.contains(&child.name)
                {
                    env.vfs.trash(&dir.join(&child.name))?;
                } else if !child.name.starts_with(crate::order::SWAP_PREFIX) {
                    // Not the engine's litter -- a file whose name the USER
                    // chose, which happens to start with the prefix this client
                    // reserves for itself. It cannot sync: the server refuses
                    // the prefix for a real file, and the ordinary listing hides
                    // it from every later pass.
                    //
                    // That is defensible; being quiet about it is not. Left
                    // alone the file sits in a synced folder looking synced, for
                    // ever, and the one failure this client is not allowed is
                    // the silent one. Collected here and said once below.
                    reserved.push(if rel.is_empty() {
                        child.name.clone()
                    } else {
                        format!("{rel}/{}", child.name)
                    });
                }
                continue;
            }
            let path = if rel.is_empty() {
                child.name.clone()
            } else {
                format!("{rel}/{}", child.name)
            };
            let full = dir.join(&child.name);
            match child.kind {
                jd_vfs::EntryKind::Directory => queue.push((full, path)),
                jd_vfs::EntryKind::File => {
                    let Some(fingerprint) = env.vfs.fingerprint(&full)? else {
                        continue;
                    };
                    let sha = match env.store.cached_hash(fingerprint, granularity)? {
                        Some(s) => s,
                        None => {
                            let s = env.vfs.hash(&full)?;
                            env.store.cache_hash(fingerprint, &s, None, now_ns)?;
                            s
                        }
                    };
                    out.push(ObservedFile {
                        path,
                        fingerprint,
                        sha256: sha,
                    });
                }
                // Symlinks are flagged and never followed: following one walks
                // out of the sync folder, and a loop walks forever.
                _ => {}
            }
        }
    }

    // A state, not an event: re-derived from the disk every pass and withdrawn
    // the moment the files are gone or renamed, so it can never outlive what it
    // describes. Compared before writing rather than raised blindly, because
    // raising the same wording every pass would churn the row and re-raising a
    // changed one would leave the stale wording standing beside it.
    reserved.sort();
    let want = if reserved.is_empty() {
        None
    } else {
        Some(format!(
            "{} file(s) here have names beginning {}, which this client reserves for its              own working files. They cannot be synced and are otherwise invisible to it.              Rename them and they will sync: {}",
            reserved.len(),
            jd_vfs::names::INTERNAL_PREFIX,
            reserved.join(", "),
        ))
    };
    let have = env
        .store
        .open_issues()?
        .into_iter()
        .find(|i| i.kind == "reserved_prefix")
        .map(|i| i.detail);
    if have != want {
        env.store.withdraw_issues("reserved_prefix")?;
        if let Some(detail) = want {
            env.store
                .raise_issue(None, "reserved_prefix", &detail, (env.now_ms)() as i64)?;
        }
    }

    Ok(out)
}

/// Directories on disk, relative to the root.
fn observed_dirs(env: &ExecEnv) -> Result<Vec<String>, ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok(Vec::new());
    };
    let mut out = Vec::new();
    let mut queue = vec![(root, String::new())];
    let mut guard = 0;
    while let Some((dir, rel)) = queue.pop() {
        guard += 1;
        if guard > 100_000 {
            return Err(ExecError::Contract("the local walk does not end".into()));
        }
        for child in env.vfs.read_dir(&dir)? {
            if jd_vfs::is_internal(&child.name) || child.kind != jd_vfs::EntryKind::Directory {
                continue;
            }
            let path = if rel.is_empty() {
                child.name.clone()
            } else {
                format!("{rel}/{}", child.name)
            };
            queue.push((dir.join(&child.name), path.clone()));
            out.push(path);
        }
    }
    // Shallowest first, so a parent always has an identity before its children
    // need one.
    out.sort_by_key(|p| (depth_of(p), p.clone()));
    Ok(out)
}

/// What happened to a tracked folder on this computer since the last agreement.
///
/// The delete branch is guarded on the folder having been *materialized*. A
/// folder the server told us about but which has never been created here has no
/// local presence to have lost, and reading its absence as a deletion would
/// propagate "this device has not caught up yet" to the server as "the user
/// removed this".
fn folder_delta(entry: &Entry, folders: &FolderScan) -> Delta {
    if let Some(to) = folders.moves.get(&entry.id) {
        return Delta::Moved { to: to.clone() };
    }
    if entry.id.entity_type == EntityType::Folder
        && entry.synced_placement.is_some()
        && !folders.present.contains(&entry.id)
    {
        return Delta::Deleted;
    }
    Delta::None
}

/// What the local scan found out about folders.
///
/// Folders are absent from the file scan entirely — they have no content to
/// pair on — so the two things that can happen to one locally are worked out
/// here instead.
#[derive(Debug, Default)]
struct FolderScan {
    /// Tracked folders now sitting somewhere else, and where.
    moves: HashMap<EntityId, Placement>,
    /// Every tracked folder confirmed to still be on this disk — at its own
    /// path, or under a new one. Anything materialized and *not* in here is
    /// gone, and that is how a folder deleted locally reaches the server.
    present: std::collections::HashSet<EntityId>,
}

/// Work out which folders on disk are tracked folders that were renamed.
///
/// Files can be paired by content — the same bytes somewhere else is a move.
/// Folders have no bytes, so the evidence has to come from what is inside them:
/// a directory nothing is tracking, holding files the engine knows by their
/// identity on this volume, is the folder those files were already in. That is
/// exactly how a user's rename looks from the outside, because renaming a
/// folder does not touch a single file inside it — the inodes are untouched and
/// only the path to them changed.
///
/// Matches are written into `folder_ids` so that everything below resolves
/// children against the folder's real server id, and returned as the folder's
/// own move so the reconciler renames it on the server in one operation.
///
/// **An empty folder cannot be matched**, because there is no evidence: it reads
/// as one folder removed and another created. Nothing is lost by that — an empty
/// folder holds nothing — and the alternative, guessing from the name, would
/// pair two unrelated folders and drag one's sharing onto the other.
fn detect_folder_moves(
    env: &ExecEnv,
    observed: &[ObservedFile],
    dirs_on_disk: &[String],
    folder_ids: &mut HashMap<String, i64>,
) -> Result<FolderScan, ExecError> {
    let mut scan = FolderScan::default();
    // Where each tracked folder believes it is, and which of those are gone.
    let mut tracked: HashMap<String, EntityId> = HashMap::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder || entry.id.is_provisional() {
            continue;
        }
        if let Some(path) = relative_path(env, &entry)? {
            tracked.insert(path, entry.id);
        }
    }
    // Folders whose believed path holds no directory. These have plainly moved
    // or gone, and the check is cheap enough to make first.
    let mut missing: Vec<(String, EntityId)> = Vec::new();
    for (path, id) in &tracked {
        if dirs_on_disk.contains(path) {
            scan.present.insert(*id);
        } else {
            missing.push((path.clone(), *id));
        }
    }
    // Nothing has gone from where it was, and there is no unaccounted directory
    // for anything to have moved TO. Everything below this line is about
    // pairing one with the other, so there is nothing to pair and no reason to
    // pay for the evidence -- which costs a path resolution per tracked file.
    let unaccounted = dirs_on_disk.iter().any(|d| !folder_ids.contains_key(d));
    if missing.is_empty() && !unaccounted {
        return Ok(scan);
    }

    // What the engine believes about the files in each tracked folder.
    let mut children: HashMap<String, Vec<(String, u64)>> = HashMap::new();
    let mut known_file_ids: std::collections::HashSet<u64> = std::collections::HashSet::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::File {
            continue;
        }
        let (Some(fingerprint), Some(path)) =
            (entry.synced_fingerprint, relative_path(env, &entry)?)
        else {
            continue;
        };
        known_file_ids.insert(fingerprint.file_id);
        if let Some((dir, name)) = path.rsplit_once('/') {
            children
                .entry(dir.to_string())
                .or_default()
                .push((name.to_string(), fingerprint.file_id));
        }
    }

    let by_path: HashMap<&str, &ObservedFile> =
        observed.iter().map(|o| (o.path.as_str(), o)).collect();

    // Does the directory standing at this path hold any of the files this
    // folder is known to contain? Identity on this volume, not names: the same
    // file_id at the same place is the folder itself, and nothing else can
    // counterfeit it.
    let corroborated = |path: &String| -> bool {
        let Some(kids) = children.get(path) else {
            // Nothing to check it by -- an empty folder, or one whose files
            // have never been agreed. The path standing is all the evidence
            // there is, and it is enough: an empty folder cannot be matched
            // anywhere else either.
            return true;
        };
        kids.iter().any(|(name, file_id)| {
            by_path
                .get(format!("{path}/{name}").as_str())
                .is_some_and(|o| o.fingerprint.file_id == *file_id)
        })
    };

    // Folders whose believed path holds a directory that is NOT them: the user
    // renamed the folder, and something still carrying the old path -- an
    // editor with a document open, a build tool with a configured output
    // directory -- rebuilt that name afterwards by saving through it.
    //
    // Reading the path alone, the engine calls such a folder present, adopts
    // the directory it actually moved to as brand new content, and the folder
    // ends up with two identities: the original sitting on a directory that
    // merely shares its old name, a new one holding the contents. Nothing looks
    // wrong at the time -- the trees still agree, because the files move into
    // the new folder -- and it comes apart later, when either of them is
    // renamed again and the two records begin describing different trees. A
    // soak campaign ended holding a whole subtree one device had and the server
    // had never heard of, both sides reporting themselves settled about it.
    //
    // They stay in `present` regardless: a directory IS standing at the path, so
    // this is not a deletion, and reading it as one would remove a folder from
    // the server that the user still has.
    //
    // Matched in a SECOND round, after the folders that are genuinely nowhere.
    // A folder with no directory at all has to be found or it is reported
    // deleted; a displaced one is merely mis-attributed, and letting the two
    // compete for the same directory lets a speculative claim outbid a
    // necessary one. Second round, and only over what the first left unclaimed.
    // Is everything under this path content the engine has never seen? That is
    // what a rebuilt directory looks like, and it is the whole of the case: the
    // name was made again from nothing, moments after the folder left it.
    //
    // A folder that merely lent a file out, or had one safe-saved into a new
    // inode, still has tracked content standing under it and is NOT this. The
    // distinction matters more than it looks: without it, one file moved out of
    // a folder reads as the folder having moved, and the engine drags the
    // folder after the file.
    let holds_nothing_known = |path: &String| -> bool {
        let prefix = format!("{path}/");
        !observed
            .iter()
            .any(|o| o.path.starts_with(&prefix) && known_file_ids.contains(&o.fingerprint.file_id))
    };
    let displaced: Vec<(String, EntityId)> = tracked
        .iter()
        .filter(|(path, _)| {
            dirs_on_disk.contains(*path) && !corroborated(path) && holds_nothing_known(path)
        })
        .map(|(path, id)| (path.clone(), *id))
        .collect();
    if missing.is_empty() && displaced.is_empty() {
        return Ok(scan);
    }
    // Every file this disk holds, by its identity on the volume. Used only to
    // answer the question below, and only when there is a displaced folder to
    // ask it about.
    let by_file_id: HashMap<u64, &ObservedFile> = if displaced.is_empty() {
        HashMap::new()
    } else {
        observed
            .iter()
            .map(|o| (o.fingerprint.file_id, o))
            .collect()
    };

    // Did this folder move here WHOLESALE -- is every file of its the disk can
    // still find now inside this one directory?
    //
    // For a folder that has vanished from its path, a single recognized file is
    // enough: it is somewhere, and one corroborated child is the best evidence
    // available of where. For a folder whose directory is still standing, it is
    // not enough at all, because "one of its files is over there" is the
    // ordinary result of the user moving ONE FILE out. Overriding a standing
    // directory on that reading drags the whole folder after the file and
    // strands whatever else was in it. A renaming folder takes everything with
    // it; a folder that has merely lent out a file does not.
    let moved_wholesale = |old_path: &String, candidate: &str| -> bool {
        let Some(kids) = children.get(old_path) else {
            return false;
        };
        let mut here = 0;
        for (name, file_id) in kids {
            match by_file_id.get(file_id) {
                // Not on this disk at all any more. Deleted, or never written
                // here. It says nothing either way, so it does not object.
                None => continue,
                Some(o) if o.path == format!("{candidate}/{name}") => here += 1,
                Some(_) => return false,
            }
        }
        here > 0
    };

    let mut claimed: Vec<EntityId> = Vec::new();
    // Shallowest first, so a renamed parent is resolved before the folders
    // inside it are asked where they live.
    let mut candidates: Vec<&String> = dirs_on_disk
        .iter()
        .filter(|d| !folder_ids.contains_key(*d))
        .collect();
    candidates.sort_by_key(|d| (depth_of(d), d.to_string()));
    let mut taken: std::collections::HashSet<&String> = std::collections::HashSet::new();

    for (pool, whole_only) in [(&missing, false), (&displaced, true)] {
        for candidate in candidates.iter() {
            if taken.contains(candidate) {
                continue;
            }
            let mut best: Option<(EntityId, String, usize)> = None;
            for (old_path, id) in pool.iter() {
                if claimed.contains(id) {
                    continue;
                }
                let Some(kids) = children.get(old_path) else {
                    continue;
                };
                let matched = kids
                    .iter()
                    .filter(|(name, file_id)| {
                        by_path
                            .get(format!("{candidate}/{name}").as_str())
                            .is_some_and(|o| o.fingerprint.file_id == *file_id)
                    })
                    .count();
                if matched == 0 {
                    continue;
                }
                if whole_only && !moved_wholesale(old_path, candidate) {
                    continue;
                }
                // The most corroborated match wins, and ties break on the folder id
                // so two devices reach the same answer.
                if best.as_ref().is_none_or(|(bid, _, count)| {
                    matched > *count || (matched == *count && *id < *bid)
                }) {
                    best = Some((*id, old_path.clone(), matched));
                }
            }

            let Some((id, old_path, _)) = best else {
                continue;
            };
            claimed.push(id);
            taken.insert(candidate);
            scan.present.insert(id);
            folder_ids.insert((*candidate).clone(), id.server_id);
            // The folder is here, so it is no longer at the path it was believed to
            // be. Where that path still holds a directory -- the rebuilt one that
            // provoked this -- leaving the old key in place would hand every file
            // saved into it to the folder that moved away, and they would surface
            // under the new name. Dropping the key lets the directory be adopted
            // for what it is, with its own identity, on this same pass.
            if old_path != **candidate {
                folder_ids.remove(&old_path);
            }
            if let Some(placement) = placement_of(candidate, folder_ids) {
                // Only a real change is reported. A folder inside a renamed parent
                // reaches here too, and its own placement — this parent, this name —
                // has not moved at all; saying it did would queue a rename to where
                // it already is.
                let entry = env.store.get_entry(id)?;
                let unchanged = entry
                    .as_ref()
                    .and_then(|e| e.synced_placement.clone())
                    .is_some_and(|p| p == placement);
                if !unchanged {
                    scan.moves.insert(id, placement);
                }
            }
        }
    }
    Ok(scan)
}

/// What the engine last recorded about each file it tracks.
///
/// Returned with the entries the server still has ahead of the ones it has
/// deleted. Pairing gives each file on disk to the first entry that claims it,
/// so where two entries name one path this decides which of them the file
/// belongs to — and a file sitting at a path a live entry is synced at is that
/// entry's, not a dead entry's memory of having once been there.
fn known_local(env: &ExecEnv) -> Result<Vec<KnownLocal>, ExecError> {
    let mut out = Vec::new();
    let mut deleted = Vec::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::File {
            continue;
        }
        // Things believed to be on this disk: either materialized here, or
        // created here and not yet sent.
        //
        // Both halves matter. Leaving out the materialized ones would read a
        // synced file as brand new. Leaving out the ones created here is worse
        // and less obvious: the scanner would find the file unclaimed on every
        // single pass, mint another identity for it, and upload it again — one
        // duplicate on the server per pass, and a client that never goes quiet.
        //
        // What stays out is an entry that came from the server and has not been
        // downloaded yet. There is no local file to have moved away from, and
        // counting it here would read it as deleted.
        if entry.synced_placement.is_none() && !entry.id.is_provisional() {
            continue;
        }
        let Some(path) = relative_path(env, &entry)? else {
            continue;
        };
        let known = KnownLocal {
            id: entry.id,
            path,
            fingerprint: entry.synced_fingerprint,
            sha256: entry.synced_content.as_ref().map(|c| c.sha256.clone()),
            server_deleted: entry.remote_deleted,
        };
        if entry.remote_deleted {
            deleted.push(known);
        } else {
            out.push(known);
        }
    }
    out.append(&mut deleted);
    Ok(out)
}

// ---------------------------------------------------------------------------
// Tree arithmetic
// ---------------------------------------------------------------------------

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Crossing {
    /// Re-upload at the destination and trash the source. The conversion.
    Convert,
    /// Refused by the server and not something this client can do instead.
    OutOfReach,
}

/// Would this local move carry the entry across the edge of a vault, and if so
/// what can be done about it?
///
/// Only a move counts. A local edit, a delete or a creation all stay where they
/// are, and a creation is decided by the path it appeared at rather than by any
/// journey. Compared against the entry's OWN protection rather than the
/// agreement's parent, because that is what the server compares against when it
/// refuses.
///
/// **A folder only on the way IN.** Dragging a plaintext folder into a vault is
/// not merely a stuck move: until it is converted the user is looking at a
/// folder they believe is private while the server holds every file in it in the
/// clear, live, at the old path. Converting is the only thing that makes the
/// picture true.
///
/// Out of a vault is the mirror image and is NOT done here. It would publish a
/// vault's contents in the clear on the strength of a drag, and the platform's
/// own answer is to change the folder's protection level first and move it
/// afterwards -- a verb this client does not have. Such a move is still refused
/// by the server and still says so as a `withdrawn` issue naming the folder;
/// what it does not yet get is an end to re-deriving it.
///
/// **Only when the destination's protection is actually known.** The drive root
/// is plaintext and says so; a folder is only an answer if this store holds it.
/// An unresolved parent reads as plaintext, and reading one as plaintext here
/// would trash the server's copy of a vault file that never left the vault.
fn crossing_a_vault_edge(
    env: &ExecEnv,
    entry: &Entry,
    local: &Delta,
) -> Result<Option<Crossing>, ExecError> {
    let to = match local {
        Delta::Moved { to } | Delta::MovedAndEdited { to, .. } => to,
        _ => return Ok(None),
    };
    let destination = match to.parent {
        None => false,
        Some(id) => match env.store.get_entry(EntityId::folder(id))? {
            Some(folder) => folder.is_encrypted,
            None => return Ok(None),
        },
    };
    if destination == entry.is_encrypted {
        return Ok(None);
    }
    if entry.id.entity_type == EntityType::File || destination {
        Ok(Some(Crossing::Convert))
    } else {
        Ok(Some(Crossing::OutOfReach))
    }
}

/// Does this folder hold encrypted content? `None` is the drive root, which is
/// never itself a vault.
fn parent_is_encrypted(env: &ExecEnv, parent: Option<i64>) -> Result<bool, ExecError> {
    let Some(id) = parent else {
        return Ok(false);
    };
    Ok(env
        .store
        .get_entry(EntityId::folder(id))?
        .map(|f| f.is_encrypted)
        .unwrap_or(false))
}

fn blank(id: EntityId, placement: &Placement) -> Entry {
    Entry {
        id,
        remote: placement.clone(),
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
    }
}

/// Every entry the store holds, walked from the root down so parents come
/// before children.
///
/// Deliberately a walk and not a read of the table. Callers here work in paths,
/// and an entry with no way back to the root has no path — handing them one
/// makes it a sibling of everything else at the root, and naming then refuses
/// the lot of them for clashing with each other. That is not a hypothetical: a
/// soak run with this reading the table came back with twelve hundred
/// unsyncable entries and nothing synced at all.
///
/// What the walk cannot see is swept up separately, before any of this runs —
/// see [`sweep_stranded_entries`].
pub(crate) fn all_entries(env: &ExecEnv) -> Result<Vec<Entry>, ExecError> {
    let mut out = Vec::new();
    let mut queue: Vec<Option<i64>> = vec![None];
    let mut guard = 0;
    while let Some(parent) = queue.pop() {
        guard += 1;
        if guard > 100_000 {
            return Err(ExecError::Contract("the entry tree has a loop".into()));
        }
        for entry in env.store.children_of(parent)? {
            if entry.id.entity_type == EntityType::Folder {
                queue.push(Some(entry.id.server_id));
            }
            out.push(entry);
        }
    }
    Ok(out)
}

/// Drop entries that name a parent the store does not have.
///
/// Everything else in a pass works in paths, and a path is built by walking
/// parents up to the root. An entry whose parent has gone has no path, so no
/// work is ever planned for it and nothing is ever raised about it — and
/// because the rest of the pass finds entries by walking down from the root, it
/// cannot even be reached to be noticed. A soak run ended with thirty-two files
/// exactly there: `pending_upload` forever, on a device reporting itself busy
/// rather than broken.
///
/// Only provisional entries are removed, and removing them is safe by
/// definition: the server has never seen them, so there is nothing to preserve
/// and nobody to tell. A stranded entry the server *does* know about would be a
/// different problem needing a different answer, and there is no evidence of one
/// — the sim asserts after every scenario that neither kind exists.
fn sweep_stranded_entries(env: &ExecEnv) -> Result<usize, ExecError> {
    let all = env.store.every_entry()?;
    let folders: std::collections::HashSet<i64> = all
        .iter()
        .filter(|e| e.id.entity_type == EntityType::Folder)
        .map(|e| e.id.server_id)
        .collect();
    let mut real_stranded = 0;
    for entry in &all {
        // A record of something the server has deleted cannot be stranded.
        // There is no path to resolve for it and no work to plan against it, so
        // counting it as a hole in the picture starts a walk that can only find
        // it again: `drive_index` returns trashed entities on purpose, marked
        // deleted, so the walk re-absorbs the very tombstone that provoked it,
        // its parent is still gone, and the next pass does the same. That loop
        // ran a full index walk every pass for the life of the client and told
        // the user items needed attention when every one of them was already
        // deleted.
        //
        // Note this skips *counting*, not keeping. Discarding these records was
        // tried once and cost seven files — they are what ties a local file to
        // what the server holds. They stay; they just stop being read as a
        // fault.
        let Some(parent) = entry.local_placement().parent else {
            continue;
        };
        if folders.contains(&parent) {
            continue;
        }
        if entry.id.is_provisional() {
            env.store.delete_subtree(entry.id)?;
        } else if entry.remote_deleted {
            // Deleted on the server *and* with no folder left here to reach it
            // through. The reason these records are kept does not apply: what
            // they are for is tying a local file to what the server holds, and
            // the server holds nothing. Nothing can find this entry either —
            // every pass walks down from the root, so an entry under a folder
            // that is gone is never visited, never decided about, never
            // cleared. It sat in `pending_download` claiming to be waiting for
            // bytes, and the client reported itself unsettled for the life of
            // the process because of it.
            //
            // Dropping it is safe in the direction that matters. If a local
            // file for it does turn up, the next scan finds it as something new
            // and uploads it, which costs a transfer and loses nothing.
            // Belief-based, and safe for the reason
            // `forget_folder_the_server_confirms` sets out: this sweep runs
            // after the feed has been absorbed, so a child the server spared
            // has already been re-parented and is not under here to be taken.
            env.store.delete_subtree(entry.id)?;
        } else {
            real_stranded += 1;
        }
    }
    Ok(real_stranded)
}

/// Path → folder id, for every folder the store tracks.
/// Fold away any provisional folder that turns out to be a real one.
///
/// The situation, which a device reaches through no fault of its own: a pass
/// reads the change feed, finds no folder of that name, walks the disk, and
/// gives the directory it finds a provisional identity. Between that feed read
/// and its create landing, another device creates the same folder. The create is
/// refused and the provisional survives — and the winner's folder then arrives
/// as a second entry for the same directory.
///
/// Nothing downstream can resolve that. Name resolution treats the two as rival
/// siblings and `resolution_order` ranks a provisional as materialized, so the
/// provisional takes the name and the real folder is refused as clashing with a
/// name identical to its own. Being unsyncable it never materializes, so it
/// never occupies the path, so the provisional is never superseded, so it
/// re-plans its doomed create every pass — forever, raising a fresh issue each
/// time. The soak rig found it at 611 refused creates per folder.
///
/// Matching on exact name and parent, not on a comparison key: this is a repair
/// for two records of one directory, and folding together two folders a
/// filesystem merely cannot tell apart is a different decision that belongs to
/// naming, which is equipped to make it.
///
/// Iterated because merging a parent re-points its children, which can expose a
/// pair one level down. Bounded, because a repair that could loop is worse than
/// one that waits for the next pass.
fn merge_duplicate_folders(env: &ExecEnv) -> Result<usize, ExecError> {
    let mut merged = 0;
    for _ in 0..8 {
        let entries = all_entries(env)?;
        let mut real: HashMap<(Option<i64>, String), i64> = HashMap::new();
        for e in &entries {
            if e.id.entity_type == EntityType::Folder && !e.id.is_provisional() && !e.remote_deleted
            {
                real.insert((e.remote.parent, e.remote.name.clone()), e.id.server_id);
            }
        }
        let mut this_round = 0;
        for e in &entries {
            if e.id.entity_type != EntityType::Folder || !e.id.is_provisional() {
                continue;
            }
            if let Some(&id) = real.get(&(e.remote.parent, e.remote.name.clone())) {
                env.store.merge_folder(e.id, EntityId::folder(id))?;
                this_round += 1;
            }
        }
        merged += this_round;
        if this_round == 0 {
            break;
        }
    }
    Ok(merged)
}

/// Fold away any provisional file that turns out to be a real one.
///
/// The file half of [`merge_duplicate_folders`], and the same deadlock: a
/// provisional entry and a real entry describing one path, which naming turns
/// into rivals and can never separate. The provisional outranks the real entry,
/// so the real one is parked `Unsyncable(DuplicateName)`; a pass skips an
/// unsyncable entry, so it never materializes, never takes the path, and never
/// supersedes the provisional — whose upload the server refuses for exactly as
/// long as the name is taken.
///
/// This is what the soak rig's run 25 was left holding once duplicate names
/// stopped being possible on the server. Every one of the 29 stuck entries
/// across the fleet was this pair, and none of them could ever have resolved:
/// the retry the client answers a `name_taken` refusal with is premised on the
/// sibling holding the name arriving on a later index walk, and here it had
/// already arrived — it was the other row.
///
/// Matching on exact name and parent, like the folder version, and for the same
/// reason: this repairs two records of one file, and folding together two files
/// a filesystem merely cannot tell apart is naming's decision to make.
///
/// Not iterated. Merging a file re-points nothing, so one pass over the pairs
/// finds all of them.
fn merge_duplicate_files(env: &ExecEnv) -> Result<usize, ExecError> {
    let entries = all_entries(env)?;
    let mut real: HashMap<(Option<i64>, String), i64> = HashMap::new();
    for e in &entries {
        if e.id.entity_type == EntityType::File && !e.id.is_provisional() && !e.remote_deleted {
            real.insert((e.remote.parent, e.remote.name.clone()), e.id.server_id);
        }
    }
    let mut merged = 0;
    for e in &entries {
        if e.id.entity_type != EntityType::File || !e.id.is_provisional() {
            continue;
        }
        if let Some(&id) = real.get(&(e.remote.parent, e.remote.name.clone())) {
            env.store.merge_file(e.id, EntityId::file(id))?;
            merged += 1;
        }
    }
    Ok(merged)
}

fn folder_paths(env: &ExecEnv) -> Result<HashMap<String, i64>, ExecError> {
    let mut out = HashMap::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder {
            continue;
        }
        if let Some(path) = relative_path(env, &entry)? {
            out.insert(path, entry.id.server_id);
        }
    }
    Ok(out)
}

/// An entry's path relative to the sync root.
pub(crate) fn relative_path(env: &ExecEnv, entry: &Entry) -> Result<Option<String>, ExecError> {
    let mut parts = vec![entry.effective_local_name().to_string()];
    let mut parent = entry.local_placement().parent;
    let mut guard = 0;
    while let Some(id) = parent {
        guard += 1;
        if guard > 512 {
            return Err(ExecError::Contract("folder tree has a loop in it".into()));
        }
        let Some(folder) = env.store.get_entry(EntityId::folder(id))? else {
            return Ok(None);
        };
        parts.push(folder.effective_local_name().to_string());
        parent = folder.local_placement().parent;
    }
    parts.reverse();
    Ok(Some(parts.join("/")))
}

/// Split a relative path into the folder that holds it and the name.
fn placement_of(path: &str, folders: &HashMap<String, i64>) -> Option<Placement> {
    match path.rsplit_once('/') {
        None => Some(Placement {
            parent: None,
            name: path.to_string(),
        }),
        Some((dir, name)) => folders.get(dir).map(|id| Placement {
            parent: Some(*id),
            name: name.to_string(),
        }),
    }
}

fn depth_of(path: &str) -> i64 {
    path.matches('/').count() as i64
}

fn depth_for(env: &ExecEnv, entry: &Entry) -> Result<i64, ExecError> {
    Ok(relative_path(env, entry)?
        .map(|p| depth_of(&p))
        .unwrap_or(0))
}
