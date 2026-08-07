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
    sweep_stranded_entries(env)?;

    // ---- one directory, one entry -------------------------------------------
    //
    // Before naming, because naming is what turns this into a deadlock: it sees
    // two entries claiming one name and refuses the real one.
    merge_duplicate_folders(env)?;

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

    for entry in all_entries(env)? {
        if entry.status == LocalStatus::OutOfScope || busy.contains(&entry.id) {
            continue;
        }
        // A name this filesystem cannot hold. There is no local file, so there
        // is nothing to compare and nothing to transfer — the entry waits,
        // visibly, until the clash clears. The one thing still worth acting on
        // is the server deleting it, which the ordinary path handles: no local
        // delta, a remote delete, and the entry is forgotten.
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
                env.store.delete_provisional_subtree(entry.id)?;
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
                env.store.delete_provisional_subtree(entry.id)?;
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
    for change in feed
        .get("changes")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default()
    {
        let Some(id) = entity_of(&change) else {
            continue;
        };
        if !wanted.contains(&id) {
            wanted.push(id);
        }
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
fn stat_all(env: &ExecEnv, ids: &[EntityId]) -> Result<Vec<(EntityId, RemoteState)>, ExecError> {
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
fn absorb_remote(env: &ExecEnv, id: EntityId, state: &RemoteState) -> Result<(), ExecError> {
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
    let mut queue = vec![(root.clone(), String::new())];
    let mut guard = 0;
    while let Some((dir, rel)) = queue.pop() {
        guard += 1;
        if guard > 100_000 {
            return Err(ExecError::Contract("the local walk does not end".into()));
        }
        for child in env.vfs.read_dir(&dir)? {
            if jd_vfs::is_internal(&child.name) {
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
                    let sha = match env.store.cached_hash(fingerprint)? {
                        Some(s) => s,
                        None => {
                            let s = env.vfs.hash(&full)?;
                            env.store.cache_hash(fingerprint, &s, None)?;
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
    let mut missing: Vec<(String, EntityId)> = Vec::new();
    for (path, id) in &tracked {
        if dirs_on_disk.contains(path) {
            scan.present.insert(*id);
        } else {
            missing.push((path.clone(), *id));
        }
    }
    if missing.is_empty() {
        return Ok(scan);
    }

    // What the engine believes about the files in each of those folders.
    let mut children: HashMap<String, Vec<(String, u64)>> = HashMap::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::File {
            continue;
        }
        let (Some(fingerprint), Some(path)) =
            (entry.synced_fingerprint, relative_path(env, &entry)?)
        else {
            continue;
        };
        if let Some((dir, name)) = path.rsplit_once('/') {
            children
                .entry(dir.to_string())
                .or_default()
                .push((name.to_string(), fingerprint.file_id));
        }
    }

    let by_path: HashMap<&str, &ObservedFile> =
        observed.iter().map(|o| (o.path.as_str(), o)).collect();

    let mut claimed: Vec<EntityId> = Vec::new();
    // Shallowest first, so a renamed parent is resolved before the folders
    // inside it are asked where they live.
    let mut candidates: Vec<&String> = dirs_on_disk
        .iter()
        .filter(|d| !folder_ids.contains_key(*d))
        .collect();
    candidates.sort_by_key(|d| (depth_of(d), d.to_string()));

    for candidate in candidates {
        let mut best: Option<(EntityId, String, usize)> = None;
        for (old_path, id) in &missing {
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
            // The most corroborated match wins, and ties break on the folder id
            // so two devices reach the same answer.
            if best
                .as_ref()
                .is_none_or(|(bid, _, count)| matched > *count || (matched == *count && *id < *bid))
            {
                best = Some((*id, old_path.clone(), matched));
            }
        }

        let Some((id, _, _)) = best else { continue };
        claimed.push(id);
        scan.present.insert(id);
        folder_ids.insert(candidate.clone(), id.server_id);
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
    Ok(scan)
}

/// What the engine last recorded about each file it tracks.
fn known_local(env: &ExecEnv) -> Result<Vec<KnownLocal>, ExecError> {
    let mut out = Vec::new();
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
        out.push(KnownLocal {
            id: entry.id,
            path,
            fingerprint: entry.synced_fingerprint,
            sha256: entry.synced_content.as_ref().map(|c| c.sha256.clone()),
        });
    }
    Ok(out)
}

// ---------------------------------------------------------------------------
// Tree arithmetic
// ---------------------------------------------------------------------------

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
    let mut swept = 0;
    for entry in &all {
        let Some(parent) = entry.local_placement().parent else {
            continue;
        };
        if folders.contains(&parent) || !entry.id.is_provisional() {
            continue;
        }
        swept += env.store.delete_provisional_subtree(entry.id)?;
    }
    Ok(swept)
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
