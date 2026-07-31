//! Carrying out a plan, on a machine that may be switched off mid-instruction.
//!
//! Deciding what should happen is a pure function ([`reconcile`](crate::reconcile)).
//! Doing it is not: every step touches a disk that can fill up and a network
//! that can lose an answer after the work was already done. The discipline that
//! makes that survivable is one rule applied without exception:
//!
//! > **Write down what you are about to do, with the key you will do it under,
//! > before you do it.**
//!
//! That is the ops journal. Each intent is a row carrying an idempotency key,
//! and the key is durable *before* the first byte goes out. So the awkward case
//! — the server applied the change and the answer never came back — costs a
//! retry that the server recognizes rather than a second copy of the file.
//!
//! ## What happens after a crash
//!
//! Anything found `InFlight` at startup was interrupted somewhere unknowable.
//! It is **not** re-run. It is re-derived: the executor asks the server what is
//! actually there now and compares that against the intent. If the server has
//! already done it, the op is finished and the agreement is recorded. If not,
//! it goes back to `Queued` and runs again under its original key. Re-running
//! blindly would be correct for some ops and destructive for others, and the
//! journal cannot tell which from the row alone — only the server can.
//!
//! ## What the executor refuses to be clever about
//!
//! - **Nothing is adopted on a fingerprint's word.** A download is verified by
//!   hashing what actually landed, and the spool is committed only if the file
//!   it replaces still matches what the decision was made against.
//! - **A local file is never unlinked.** Removals go to the trash, so a delete
//!   the engine got wrong is recoverable by the person it happened to.
//! - **A failure is never silently absorbed.** It goes back in the journal with
//!   a backoff, or it is withdrawn on the record because its premise is gone.

use std::io::Write;
use std::path::PathBuf;

use serde_json::{json, Value};
use sha2::{Digest, Sha256};

use jd_proto::{DriveApi, ProtoError, UploadParams};
use jd_vfs::Vfs;

use crate::model::{ContentId, EntityId, EntityType, Entry, LocalStatus, Placement};
use crate::order::Plan;
use crate::reconcile::Action;
use crate::round::retry_delay_ms;
use crate::store::{Op, OpState, Store, StoreError};

/// How many times a download will re-mint an expired link before giving up on
/// this attempt. One is enough: a second expiry inside one attempt means
/// something is wrong beyond a stale link, and hammering it is not the answer.
const URL_REMINTS: u32 = 1;

#[derive(Debug, thiserror::Error)]
pub enum ExecError {
    #[error(transparent)]
    Store(#[from] StoreError),
    #[error(transparent)]
    Vfs(#[from] jd_vfs::VfsError),
    #[error(transparent)]
    Proto(#[from] ProtoError),
    /// The server answered something the contract does not allow. Never
    /// retried: repeating a request that produced nonsense produces nonsense.
    #[error("the server answered outside the contract: {0}")]
    Contract(String),
    /// The journal holds an op the executor cannot make sense of — a newer
    /// client's kind, or a corrupted row.
    #[error("unrecognized operation: {0}")]
    UnknownOp(String),
}

/// How one operation ended.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum OpOutcome {
    /// Applied, and the agreement now reflects it.
    Done,
    /// The premise no longer holds — the file was deleted while the op sat in
    /// the queue, the entry is gone from the server. Dropped rather than
    /// retried; the next round decides afresh from what is actually there.
    Withdrawn(String),
    /// Try again later. Carries what to say about it in the meantime.
    Retry(String),
}

/// What one pass over the journal did.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct ExecReport {
    pub done: usize,
    pub withdrawn: usize,
    pub retrying: usize,
    /// Ops left alone because their backoff has not elapsed.
    pub deferred: usize,
}

impl ExecReport {
    pub fn attempted(&self) -> usize {
        self.done + self.withdrawn + self.retrying
    }
}

/// Everything the executor is allowed to touch.
///
/// The filesystem, the network and the clock all arrive here rather than being
/// reached for, which is what lets the simulator run this exact code against a
/// world that loses answers and fills up disks.
pub struct ExecEnv<'a> {
    pub store: &'a Store,
    pub vfs: &'a dyn Vfs,
    pub api: &'a dyn DriveApi,
    /// Milliseconds since the epoch. Injected so backoff is reproducible.
    pub now_ms: &'a dyn Fn() -> u64,
    /// Given a filename, the name a losing copy of it is kept under.
    ///
    /// The executor needs this because a move or a download can find something
    /// already sitting where it has to go — something nobody has uploaded and
    /// nothing is tracking. It is never overwritten; it is moved aside under a
    /// name that says what it is, and picked up as a new file next pass.
    pub conflict_name: &'a dyn Fn(&str) -> String,
}

// ---------------------------------------------------------------------------
// Journaling
// ---------------------------------------------------------------------------

/// Write a plan into the journal.
///
/// Every op is recorded before any of them runs, in the order they must run.
/// Two things follow from that. A crash between planning and acting loses
/// nothing — the work is on disk. And the idempotency key each op will carry
/// exists before the request that uses it, which is the whole point.
///
/// `key_for` supplies the keys; it is a parameter so a simulated run produces
/// the same ones from its seed.
pub fn journal(
    store: &Store,
    plan: &Plan,
    key_for: &mut dyn FnMut() -> String,
) -> Result<Vec<i64>, ExecError> {
    let mut ids = Vec::new();

    // Parking first. A cycle's victim has to be out of its slot before any of
    // the moves that want it can run, and the moves are journaled below in the
    // order the planner ranked them.
    for (entity, scratch) in &plan.broken_cycles {
        let side = plan
            .ops
            .iter()
            .find(|o| o.entity == *entity)
            .map(|o| match o.action {
                Action::ApplyRemoteMove { .. } => "park_local",
                _ => "park_remote",
            })
            .unwrap_or("park_remote");
        ids.push(store.queue_op(
            side,
            *entity,
            &json!({ "name": scratch }).to_string(),
            &key_for(),
        )?);
    }

    for op in plan.ordered() {
        let (kind, mut params) = encode(&op.action);
        // Where the thing is right now, recorded while it is still known. A
        // file the user moved on this computer is not at the agreed placement,
        // and by the time the op runs there is nothing left to work that out
        // from.
        if let Some(from) = &op.from {
            params["from"] = place(from);
        }
        ids.push(store.queue_op(kind, op.entity, &params.to_string(), &key_for())?);
    }
    Ok(ids)
}

fn encode(action: &Action) -> (&'static str, Value) {
    match action {
        Action::Download => ("download", json!({})),
        Action::UploadVersion => ("upload_version", json!({})),
        Action::UploadAsNew { placement } => ("upload_new", place(placement)),
        Action::CreateRemoteFolder { placement } => ("create_remote_folder", place(placement)),
        Action::CreateLocalFolder { placement } => ("create_local_folder", place(placement)),
        Action::ApplyLocalMove { to } => ("move_remote", place(to)),
        Action::ApplyRemoteMove { to } => ("move_local", place(to)),
        Action::TrashLocal => ("trash_local", json!({})),
        Action::TrashRemote => ("trash_remote", json!({})),
        Action::PreserveLocalAs { name } => ("preserve_local_as", json!({ "name": name })),
        Action::Forget => ("forget", json!({})),
        Action::Adopt => ("adopt", json!({})),
        Action::RemoveFromScope => ("remove_from_scope", json!({})),
    }
}

fn place(p: &Placement) -> Value {
    json!({ "parent": p.parent, "name": p.name })
}

fn read_place(params: &Value) -> Result<Placement, ExecError> {
    let name = params
        .get("name")
        .and_then(Value::as_str)
        .ok_or_else(|| ExecError::UnknownOp("operation has no name".into()))?;
    Ok(Placement {
        parent: params.get("parent").and_then(Value::as_i64),
        name: name.to_string(),
    })
}

// ---------------------------------------------------------------------------
// Running
// ---------------------------------------------------------------------------

/// Run every queued op whose backoff has elapsed, in journal order.
pub fn run_queued(env: &ExecEnv) -> Result<ExecReport, ExecError> {
    let now = (env.now_ms)() as i64;
    let mut report = ExecReport::default();

    for op in env.store.queued_ops()? {
        if op.next_retry_time.map(|t| t > now).unwrap_or(false) {
            report.deferred += 1;
            continue;
        }
        match run_one(env, &op)? {
            OpOutcome::Done => report.done += 1,
            OpOutcome::Withdrawn(_) => report.withdrawn += 1,
            OpOutcome::Retry(_) => report.retrying += 1,
        }
    }
    Ok(report)
}

/// Run one op, marking the crash window around it and recording how it ended.
pub fn run_one(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    // In flight from here. If the process dies now, recovery finds this row and
    // asks the server what actually happened rather than assuming either way.
    env.store.set_op_state(op.op_id, OpState::InFlight)?;

    let outcome = match perform(env, op) {
        Ok(o) => o,
        Err(e) => classify(&e),
    };

    match &outcome {
        OpOutcome::Done => {
            env.store.set_op_state(op.op_id, OpState::Done)?;
        }
        OpOutcome::Withdrawn(why) => {
            env.store.raise_issue(
                Some(op.entity),
                "withdrawn",
                &format!("{} was not carried out: {why}", op.kind),
                (env.now_ms)() as i64,
            )?;
            env.store.drop_op(op.op_id)?;
        }
        OpOutcome::Retry(why) => {
            let delay = retry_delay_ms(op.attempts + 1) as i64;
            env.store
                .record_op_failure(op.op_id, why, (env.now_ms)() as i64 + delay)?;
        }
    }
    Ok(outcome)
}

/// Which failures are worth another go.
///
/// The distinction that matters is between "this did not work" and "this cannot
/// work". A transport error, a 5xx, a rate limit and a full disk are all the
/// first: the world may differ in a minute. A validation refusal or a missing
/// entity is the second, and retrying it forever is how a client ends up busy
/// and useless.
fn classify(e: &ExecError) -> OpOutcome {
    match e {
        ExecError::Proto(ProtoError::Transport(m)) => OpOutcome::Retry(m.clone()),
        ExecError::Proto(ProtoError::Io(m)) => OpOutcome::Retry(m.to_string()),
        ExecError::Proto(ProtoError::Api {
            status, message, ..
        }) => match status {
            // Gone. Nothing to retry against; the next round re-derives.
            404 => OpOutcome::Withdrawn(message.clone()),
            // Out of step with the server, which is a thing that resolves.
            409 | 423 | 429 => OpOutcome::Retry(message.clone()),
            s if *s >= 500 => OpOutcome::Retry(message.clone()),
            _ => OpOutcome::Withdrawn(message.clone()),
        },
        ExecError::Proto(other) => OpOutcome::Retry(other.to_string()),
        ExecError::Vfs(jd_vfs::VfsError::NotFound(p)) => {
            OpOutcome::Withdrawn(format!("{} is no longer there", p.display()))
        }
        ExecError::Vfs(other) => OpOutcome::Retry(other.to_string()),
        ExecError::Contract(m) | ExecError::UnknownOp(m) => OpOutcome::Withdrawn(m.clone()),
        // A failing state store is not something the next attempt escapes.
        ExecError::Store(m) => OpOutcome::Retry(m.to_string()),
    }
}

fn perform(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    let params: Value = serde_json::from_str(&op.params).unwrap_or_else(|_| json!({}));
    match op.kind.as_str() {
        "download" => download(env, op),
        "upload_version" => upload(env, op, None),
        "upload_new" => upload(env, op, Some(read_place(&params)?)),
        "create_remote_folder" => create_remote_folder(env, op, read_place(&params)?),
        "create_local_folder" => create_local_folder(env, op, read_place(&params)?),
        "move_remote" => move_remote(env, op, read_place(&params)?),
        "move_local" => {
            let from = params.get("from").and_then(|v| read_place(v).ok());
            move_local(env, op, read_place(&params)?, from)
        }
        "park_remote" => {
            let entry = match env.store.get_entry(op.entity)? {
                Some(e) => e,
                None => return Ok(OpOutcome::Withdrawn("the entry is gone".into())),
            };
            let to = Placement {
                parent: entry.remote.parent,
                name: read_place(&params)?.name,
            };
            move_remote(env, op, to)
        }
        "park_local" => {
            let entry = match env.store.get_entry(op.entity)? {
                Some(e) => e,
                None => return Ok(OpOutcome::Withdrawn("the entry is gone".into())),
            };
            let to = Placement {
                parent: entry.remote.parent,
                name: read_place(&params)?.name,
            };
            move_local(env, op, to, None)
        }
        "trash_local" => trash_local(env, op),
        "trash_remote" => trash_remote(env, op),
        "preserve_local_as" => preserve_local_as(env, op, &params),
        "forget" => {
            env.store.delete_entry(op.entity)?;
            Ok(OpOutcome::Done)
        }
        "adopt" => adopt(env, op),
        "remove_from_scope" => {
            if let Some(mut entry) = env.store.get_entry(op.entity)? {
                entry.status = LocalStatus::OutOfScope;
                env.store.put_entry(&entry)?;
            }
            Ok(OpOutcome::Done)
        }
        other => Err(ExecError::UnknownOp(other.to_string())),
    }
}

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

/// Where an entry lives on this computer.
///
/// Built by walking parents, because the store keys by identity and the
/// filesystem addresses by path. A missing link in that chain is not an error
/// worth retrying — it means the tree changed underneath us and the next round
/// will produce a plan that fits the tree as it now is.
fn local_path(env: &ExecEnv, entry: &Entry) -> Result<Option<PathBuf>, ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok(None);
    };
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
    let mut path = root;
    for part in parts.iter().rev() {
        path.push(part);
    }
    Ok(Some(path))
}

/// Where a placement would put something.
fn path_for(env: &ExecEnv, p: &Placement) -> Result<Option<PathBuf>, ExecError> {
    let probe = Entry {
        id: EntityId::file(0),
        remote: p.clone(),
        remote_content: None,
        remote_modified_time: None,
        head_change_id: 0,
        remote_deleted: false,
        is_encrypted: false,
        synced_content: None,
        synced_placement: None,
        synced_fingerprint: None,
        local_name: None,
        status: LocalStatus::Synced,
        wrapped_file_key: None,
    };
    local_path(env, &probe)
}

/// The root, or a retry if the volume is not mounted.
///
/// Deliberately never "everything was deleted". An unplugged drive that read as
/// a mass delete would be the single worst bug this program could have.
fn require_entry(env: &ExecEnv, id: EntityId) -> Result<Option<Entry>, ExecError> {
    Ok(env.store.get_entry(id)?)
}

// ---------------------------------------------------------------------------
// Transfers
// ---------------------------------------------------------------------------

/// A writer that counts and hashes on the way past.
///
/// Both numbers have to come from the bytes that actually reached the spool,
/// not from what the server said it would send. A download that ends early
/// without saying so is a real failure mode, and the only defence against it is
/// measuring the arriving bytes rather than believing a header.
struct Landing<'a> {
    inner: &'a mut dyn Write,
    hasher: Sha256,
    written: u64,
}

impl<'a> Write for Landing<'a> {
    fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
        let n = self.inner.write(buf)?;
        self.hasher.update(&buf[..n]);
        self.written += n as u64;
        Ok(n)
    }
    fn flush(&mut self) -> std::io::Result<()> {
        self.inner.flush()
    }
}

/// The file being uploaded, handed to the transport.
///
/// A shim rather than a direct pass: the filesystem and the network each define
/// their own "readable and rewindable", and one trait object does not become
/// another just because their contents match. Wrapping in something concrete
/// satisfies both without either crate having to know about the other.
struct Source<'a>(&'a mut dyn jd_vfs::ReadSeek);

impl std::io::Read for Source<'_> {
    fn read(&mut self, buf: &mut [u8]) -> std::io::Result<usize> {
        self.0.read(buf)
    }
}

impl std::io::Seek for Source<'_> {
    fn seek(&mut self, pos: std::io::SeekFrom) -> std::io::Result<u64> {
        self.0.seek(pos)
    }
}

/// Clear a path for something that has to go there, without destroying what is
/// already in it.
///
/// This is the quiet way a sync engine loses a file. A remote move arrives, its
/// destination happens to hold something the user made here and nothing has
/// uploaded yet, and the rename lands on top of it. No error, no warning, and
/// the only copy that ever existed is gone. So anything in the way is moved
/// aside first, where the next pass finds it and uploads it as a new file.
///
/// Identical content is not in the way — replacing bytes with the same bytes
/// loses nothing, and treating it as a conflict would litter the folder with
/// copies of things that already agree.
fn make_room(
    env: &ExecEnv,
    path: &std::path::Path,
    incoming: Option<&str>,
) -> Result<(), ExecError> {
    let Some(fingerprint) = env.vfs.fingerprint(path)? else {
        return Ok(());
    };
    let existing = match env.store.cached_hash(fingerprint)? {
        Some(h) => h,
        None => env.vfs.hash(path)?,
    };
    if Some(existing.as_str()) == incoming {
        return Ok(());
    }
    let name = path
        .file_name()
        .map(|n| n.to_string_lossy().to_string())
        .unwrap_or_default();
    let aside = path.with_file_name((env.conflict_name)(&name));
    env.vfs.rename(path, &aside)?;
    env.store.raise_issue(
        None,
        "kept_aside",
        &format!(
            "{name} was moved aside to {} to make room for the synced copy",
            aside
                .file_name()
                .map(|n| n.to_string_lossy().to_string())
                .unwrap_or_default()
        ),
        (env.now_ms)() as i64,
    )?;
    Ok(())
}

/// Ask the server for this entity's current state, with a fresh signed link.
fn stat(env: &ExecEnv, id: EntityId, urls: bool) -> Result<Option<Value>, ExecError> {
    let body = json!({
        "entities": [{ "entity_type": id.entity_type.to_string(), "entity_id": id.server_id }],
        "urls": urls,
    });
    let out = env.api.action("drive_stat", body)?;
    Ok(out
        .get("items")
        .and_then(Value::as_array)
        .and_then(|a| a.first())
        .cloned())
}

fn download(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    let Some(entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    let Some(path) = local_path(env, &entry)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };

    // A signed link is minted here rather than remembered, because a link that
    // was fresh when the round was planned may not be by the time a queue of
    // large files reaches this one.
    let Some(item) = stat(env, op.entity, true)? else {
        return Ok(OpOutcome::Withdrawn("the server no longer has it".into()));
    };
    if item.get("deleted").and_then(Value::as_bool) == Some(true) {
        return Ok(OpOutcome::Withdrawn("it is in the server's trash".into()));
    }
    let want_sha = item
        .get("content_sha256")
        .and_then(Value::as_str)
        .ok_or_else(|| ExecError::Contract("file export has no content hash".into()))?
        .to_string();
    let want_size = item.get("size").and_then(Value::as_u64).unwrap_or(0);
    let mut url = item
        .get("download_url")
        .and_then(Value::as_str)
        .ok_or_else(|| ExecError::Contract("file export has no download link".into()))?
        .to_string();

    let mut spool = env.vfs.spool(&path)?;
    let mut landing = Landing {
        inner: &mut *spool,
        hasher: Sha256::new(),
        written: 0,
    };

    let mut remints = 0u32;
    while landing.written < want_size {
        let from = landing.written;
        match env.api.download(&url, from, &mut landing) {
            Ok(0) => {
                // The server had nothing more to give at an offset it accepted.
                // Continuing would spin, so let the backoff have it.
                break;
            }
            Ok(_) => {}
            Err(ProtoError::Api { status: 403, .. }) if remints < URL_REMINTS => {
                // The link expired mid-transfer. The file is fine; the link is
                // not. Re-mint and carry on from where the bytes stopped —
                // starting over would punish exactly the largest files.
                remints += 1;
                let Some(fresh) = stat(env, op.entity, true)? else {
                    return Ok(OpOutcome::Withdrawn("the server no longer has it".into()));
                };
                url = fresh
                    .get("download_url")
                    .and_then(Value::as_str)
                    .ok_or_else(|| ExecError::Contract("re-minted export has no link".into()))?
                    .to_string();
            }
            Err(e) => {
                // Whatever landed stays in the spool; the spool is discarded
                // with it, because a partial file must never become visible.
                let written = landing.written;
                spool.discard();
                return Ok(match classify(&ExecError::Proto(e)) {
                    OpOutcome::Done => OpOutcome::Retry(format!("stopped after {written} bytes")),
                    other => other,
                });
            }
        }
    }

    let got_sha = hex(&landing.hasher.finalize());
    let written = landing.written;

    if written != want_size || got_sha != want_sha {
        // Nothing is ever adopted on a fingerprint's word, and nothing is ever
        // committed on a byte count's word either. A truncated or corrupted
        // transfer dies here rather than becoming the file the user opens.
        spool.discard();
        return Ok(OpOutcome::Retry(format!(
            "what arrived does not match what was asked for ({written} of {want_size} bytes)"
        )));
    }

    // With no agreement there is no fingerprint to guard the swap, so anything
    // already at this path is something the engine has never seen. It is not
    // overwritten.
    if entry.synced_fingerprint.is_none() {
        make_room(env, &path, Some(&want_sha))?;
    }

    // The guard: if the local file changed while this was in flight, the
    // decision that produced this download was made against something that no
    // longer exists, and overwriting would discard an edit nobody has seen.
    let fingerprint = match spool.commit(&path, entry.synced_fingerprint) {
        Ok(fp) => fp,
        Err(jd_vfs::VfsError::AlreadyExists(_)) => {
            return Ok(OpOutcome::Withdrawn(
                "the file changed here while it was downloading".into(),
            ))
        }
        Err(e) => return Err(e.into()),
    };

    let content = ContentId {
        sha256: want_sha,
        size: want_size,
    };
    env.store
        .cache_hash(fingerprint, &content.sha256, Some(op.entity))?;

    let mut entry = entry;
    entry.remote_content = Some(content.clone());
    entry.head_change_id = item
        .get("head_change_id")
        .and_then(Value::as_i64)
        .unwrap_or(entry.head_change_id);
    agree(&mut entry, Some(content), Some(fingerprint));
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

fn upload(env: &ExecEnv, op: &Op, as_new: Option<Placement>) -> Result<OpOutcome, ExecError> {
    let Some(entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    let Some(path) = local_path(env, &entry)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };
    let Some(fingerprint) = env.vfs.fingerprint(&path)? else {
        return Ok(OpOutcome::Withdrawn(
            "the file is no longer on this computer".into(),
        ));
    };

    let sha = match env.store.cached_hash(fingerprint)? {
        Some(s) => s,
        None => {
            let s = env.vfs.hash(&path)?;
            env.store.cache_hash(fingerprint, &s, Some(op.entity))?;
            s
        }
    };

    // The name comes from the plan; the destination folder comes from the entry
    // as it stands NOW. Those differ whenever the folder was created in the
    // same round: the plan was written against a provisional id, and the folder
    // acquired its real one a few operations ago.
    let placement = Placement {
        name: as_new
            .as_ref()
            .map(|p| p.name.clone())
            .unwrap_or_else(|| entry.remote.name.clone()),
        parent: entry.remote.parent,
    };
    if placement.parent.map(|p| p < 0).unwrap_or(false) {
        return Ok(OpOutcome::Retry(
            "the folder it belongs in is not on the server yet".into(),
        ));
    }
    let params = UploadParams {
        name: placement.name.clone(),
        folder_id: placement.parent,
        // A new entry, or a new version of the one we already have. The old id
        // is only usable when the server still has it — which is exactly what
        // `UploadAsNew` exists to say it does not.
        file_id: if as_new.is_some() || op.entity.is_provisional() {
            None
        } else {
            Some(op.entity.server_id)
        },
        size_bytes: fingerprint.size,
        sha256: sha.clone(),
        mime_type: None,
        // Journaled before the first byte went out. This is what stops a lost
        // completion answer from producing a second copy of the file.
        idempotency_key: Some(op.idempotency_key.clone()),
    };

    let mut reader = env.vfs.open_read(&path)?;
    let mut source = Source(&mut *reader);
    let outcome = env.api.upload(&params, &mut source)?;
    drop(reader);

    let file = outcome.file;
    let new_id = file
        .get("id")
        .and_then(Value::as_i64)
        .ok_or_else(|| ExecError::Contract("upload result has no file id".into()))?;

    // The file may have been edited while it was going up. The bytes on the
    // server are still a real version of it, so nothing is wrong — but calling
    // this the agreed state would mean the newer content never gets sent.
    let settled = env.vfs.fingerprint(&path)?.filter(|fp| *fp == fingerprint);

    let mut entry = entry;
    let target = EntityId {
        entity_type: EntityType::File,
        server_id: new_id,
    };
    if target != entry.id {
        env.store.rekey_entry(entry.id, target)?;
        entry.id = target;
    }
    apply_export(&mut entry, &file);
    let content = ContentId {
        sha256: sha,
        size: fingerprint.size,
    };
    match settled {
        Some(fp) => agree(&mut entry, Some(content), Some(fp)),
        None => {
            entry.status = LocalStatus::PendingUpload;
        }
    }
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

/// The losing side of a content conflict, kept beside the winner.
///
/// The remote head keeps the path the user knows; the local content is renamed
/// out of the way and then uploaded under that name, so both survive and both
/// are reachable from every device. Nothing is discarded because two people
/// were editing at once.
fn preserve_local_as(env: &ExecEnv, op: &Op, params: &Value) -> Result<OpOutcome, ExecError> {
    let Some(entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    let name = params
        .get("name")
        .and_then(Value::as_str)
        .ok_or_else(|| ExecError::UnknownOp("no conflict-copy name".into()))?
        .to_string();
    let Some(from) = local_path(env, &entry)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };
    let kept = Placement {
        parent: entry.remote.parent,
        name: name.clone(),
    };
    let Some(to) = path_for(env, &kept)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };
    if env.vfs.fingerprint(&from)?.is_none() {
        return Ok(OpOutcome::Withdrawn(
            "the local copy is no longer there".into(),
        ));
    }
    env.vfs.rename(&from, &to)?;

    // The rescued copy is a new entry with its own identity: it has never
    // agreed with anything, so it uploads as a creation on the next round.
    let id = EntityId {
        entity_type: EntityType::File,
        server_id: env.store.next_provisional_id()?,
    };
    let rescued = Entry {
        id,
        remote: kept,
        remote_content: None,
        remote_modified_time: None,
        head_change_id: 0,
        remote_deleted: false,
        is_encrypted: entry.is_encrypted,
        synced_content: None,
        synced_placement: None,
        synced_fingerprint: None,
        local_name: None,
        status: LocalStatus::PendingUpload,
        wrapped_file_key: None,
    };
    env.store.put_entry(&rescued)?;

    // The original entry now has no local file; the download of the winning
    // content is a separate op in the same round.
    let mut entry = entry;
    entry.synced_fingerprint = None;
    entry.status = LocalStatus::PendingDownload;
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

// ---------------------------------------------------------------------------
// Structure
// ---------------------------------------------------------------------------

fn create_remote_folder(
    env: &ExecEnv,
    op: &Op,
    planned: Placement,
) -> Result<OpOutcome, ExecError> {
    // Same reason as an upload: the parent may have been provisional when this
    // was planned and real by the time it runs.
    let parent = match env.store.get_entry(op.entity)? {
        Some(e) => e.remote.parent,
        None => {
            return Ok(OpOutcome::Withdrawn(
                "the entry is no longer tracked".into(),
            ))
        }
    };
    let placement = Placement {
        name: planned.name,
        parent,
    };
    if placement.parent.map(|p| p < 0).unwrap_or(false) {
        return Ok(OpOutcome::Retry(
            "the folder it belongs in is not on the server yet".into(),
        ));
    }
    let mut body = json!({ "name": placement.name });
    if let Some(parent) = placement.parent {
        body["parent_id"] = json!(parent);
    }
    let out = env
        .api
        .action_idempotent("drive_folder_create", body, &op.idempotency_key)?;
    let folder = out
        .get("folder")
        .ok_or_else(|| ExecError::Contract("folder create returned no folder".into()))?;
    let new_id = folder
        .get("id")
        .and_then(Value::as_i64)
        .ok_or_else(|| ExecError::Contract("created folder has no id".into()))?;

    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    let target = EntityId::folder(new_id);
    if target != entry.id {
        env.store.rekey_entry(entry.id, target)?;
        entry.id = target;
    }
    entry.remote = placement;
    agree(&mut entry, None, None);
    entry.synced_placement = Some(entry.remote.clone());
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

fn create_local_folder(
    env: &ExecEnv,
    op: &Op,
    placement: Placement,
) -> Result<OpOutcome, ExecError> {
    let Some(path) = path_for(env, &placement)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };
    match env.vfs.create_dir(&path) {
        Ok(()) => {}
        // Already there is the outcome we wanted. Creating a folder is the one
        // operation where "it was already done" and "I did it" are the same
        // result, which is why it needs no key.
        Err(jd_vfs::VfsError::AlreadyExists(_)) => {}
        Err(e) => return Err(e.into()),
    }
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    entry.remote = placement;
    agree(&mut entry, None, None);
    entry.synced_placement = Some(entry.remote.clone());
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

/// Apply this computer's move to the server.
fn move_remote(env: &ExecEnv, op: &Op, to: Placement) -> Result<OpOutcome, ExecError> {
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    if op.entity.is_provisional() {
        return Ok(OpOutcome::Withdrawn(
            "it does not exist on the server yet".into(),
        ));
    }
    let t = op.entity.entity_type.to_string();

    // Rename and reparent are separate calls, and a crash between them leaves
    // the entry renamed but not moved. That is a state the next round reads
    // correctly and finishes, which is why they do not need to be one call.
    if entry.remote.parent != to.parent {
        let mut body = json!({ "entity_type": t, "entity_id": op.entity.server_id });
        body["folder_id"] = match to.parent {
            Some(p) => json!(p),
            None => Value::Null,
        };
        env.api
            .action_idempotent("drive_move", body, &format!("{}-move", op.idempotency_key))?;
    }
    if entry.remote.name != to.name {
        let body = json!({
            "entity_type": t,
            "entity_id": op.entity.server_id,
            "name": to.name,
        });
        env.api.action_idempotent(
            "drive_rename",
            body,
            &format!("{}-rename", op.idempotency_key),
        )?;
    }

    entry.remote = to;
    entry.synced_placement = Some(entry.remote.clone());
    if entry.status == LocalStatus::Synced || entry.synced_content.is_some() {
        entry.status = LocalStatus::Synced;
    }
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

/// Apply the server's move to this computer.
///
/// `source` is where the plan says the file actually is, which is not always
/// where the agreement says. When both sides moved the same file the server
/// wins — and the file is still sitting wherever this computer's user put it.
/// Looking only at the agreed path finds nothing, and the move then fails every
/// round forever, because nothing about that situation changes on its own.
fn move_local(
    env: &ExecEnv,
    op: &Op,
    to: Placement,
    source: Option<Placement>,
) -> Result<OpOutcome, ExecError> {
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    let agreed = local_path(env, &entry)?;
    let planned = match &source {
        Some(p) => path_for(env, p)?,
        None => None,
    };
    // Prefer whichever candidate a file is actually at. Either can be stale,
    // and trusting one blindly is how a move retries against a path nothing has
    // been at for hours.
    let from = match (&planned, &agreed) {
        (Some(p), _) if env.vfs.fingerprint(p)?.is_some() => p.clone(),
        (_, Some(a)) if env.vfs.fingerprint(a)?.is_some() => a.clone(),
        (Some(p), _) => p.clone(),
        (_, Some(a)) => a.clone(),
        _ => return Ok(OpOutcome::Retry("the sync folder is not available".into())),
    };
    let Some(dest) = path_for(env, &to)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };
    if from != dest {
        // A rename lands on top of whatever is at the destination. If that is
        // something nobody has uploaded, this is the moment it would disappear.
        let moving = env
            .vfs
            .fingerprint(&from)?
            .and_then(|fp| env.store.cached_hash(fp).ok().flatten());
        make_room(env, &dest, moving.as_deref())?;
        match env.vfs.rename(&from, &dest) {
            Ok(()) => {}
            // Already at the destination — a repeat of a move that landed
            // before the answer got back. The wanted state holds either way.
            Err(jd_vfs::VfsError::NotFound(_)) if env.vfs.fingerprint(&dest)?.is_some() => {}
            Err(e) => return Err(e.into()),
        }
    }
    entry.remote = to;
    entry.synced_placement = Some(entry.remote.clone());
    entry.synced_fingerprint = env.vfs.fingerprint(&dest)?;
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

fn trash_local(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    let Some(entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    let Some(path) = local_path(env, &entry)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };
    match env.vfs.trash(&path) {
        Ok(()) => {}
        // Gone already is the outcome we wanted.
        Err(jd_vfs::VfsError::NotFound(_)) => {}
        Err(e) => return Err(e.into()),
    }
    env.store.delete_entry(op.entity)?;
    Ok(OpOutcome::Done)
}

fn trash_remote(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    if op.entity.is_provisional() {
        // It never reached the server. Forgetting it locally is the whole job.
        env.store.delete_entry(op.entity)?;
        return Ok(OpOutcome::Done);
    }
    let body = json!({
        "entity_type": op.entity.entity_type.to_string(),
        "entity_id": op.entity.server_id,
    });
    match env
        .api
        .action_idempotent("drive_trash", body, &op.idempotency_key)
    {
        Ok(_) => {}
        // Already gone from the server is the outcome we wanted.
        Err(ProtoError::Api { status: 404, .. }) => {}
        Err(e) => return Err(e.into()),
    }
    env.store.delete_entry(op.entity)?;
    Ok(OpOutcome::Done)
}

/// The two sides already hold the same thing. Record the agreement and move no
/// bytes — but only after checking, because adopting on a guess is how a sync
/// engine loses an edit it never looked at.
fn adopt(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Withdrawn(
            "the entry is no longer tracked".into(),
        ));
    };
    if entry.id.entity_type == EntityType::Folder {
        entry.synced_placement = Some(entry.remote.clone());
        entry.status = LocalStatus::Synced;
        env.store.put_entry(&entry)?;
        return Ok(OpOutcome::Done);
    }

    let Some(path) = local_path(env, &entry)? else {
        return Ok(OpOutcome::Retry("the sync folder is not available".into()));
    };
    let Some(fingerprint) = env.vfs.fingerprint(&path)? else {
        return Ok(OpOutcome::Withdrawn(
            "the file is no longer on this computer".into(),
        ));
    };
    let local_sha = match env.store.cached_hash(fingerprint)? {
        Some(s) => s,
        None => {
            let s = env.vfs.hash(&path)?;
            env.store.cache_hash(fingerprint, &s, Some(op.entity))?;
            s
        }
    };
    let remote_sha = entry.remote_content.as_ref().map(|c| c.sha256.clone());
    if remote_sha.as_deref() != Some(local_sha.as_str()) {
        return Ok(OpOutcome::Withdrawn(
            "the two sides turned out not to match after all".into(),
        ));
    }

    let content = entry.remote_content.clone();
    agree(&mut entry, content, Some(fingerprint));
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

// ---------------------------------------------------------------------------
// Recovery
// ---------------------------------------------------------------------------

/// What the crash window left behind, resolved against what the server has.
///
/// Every `InFlight` op died at an unknowable instruction. Re-running it blindly
/// is wrong: some are safe to repeat and some create a second copy. Asking the
/// server settles it in one call, and the answer is either "already done" or
/// "not done" — both of which the engine knows what to do with.
pub fn recover(env: &ExecEnv) -> Result<ExecReport, ExecError> {
    let mut report = ExecReport::default();
    for op in env.store.interrupted_ops()? {
        if already_satisfied(env, &op)? {
            env.store.set_op_state(op.op_id, OpState::Done)?;
            report.done += 1;
        } else {
            // Back in the queue under its original key, which is what makes
            // the retry recognizable rather than repeated.
            env.store.set_op_state(op.op_id, OpState::Queued)?;
            report.retrying += 1;
        }
    }
    Ok(report)
}

/// Did the interrupted op already take effect?
///
/// Answered from the server's current state, never from local bookkeeping — the
/// bookkeeping is precisely what did not get written.
fn already_satisfied(env: &ExecEnv, op: &Op) -> Result<bool, ExecError> {
    let params: Value = serde_json::from_str(&op.params).unwrap_or_else(|_| json!({}));
    match op.kind.as_str() {
        // Anything that only touches this computer is settled by looking at
        // this computer, and re-running it is harmless if it is not.
        "download"
        | "create_local_folder"
        | "move_local"
        | "trash_local"
        | "adopt"
        | "remove_from_scope"
        | "forget"
        | "preserve_local_as"
        | "park_local" => Ok(false),

        // A creation that landed cannot be found by id — the id we hold is the
        // provisional one. The server's replay cache is what answers this, so
        // the retry is safe and this need not guess.
        "create_remote_folder" | "upload_new" | "upload_version" => Ok(false),

        "move_remote" | "park_remote" => {
            let Some(item) = stat(env, op.entity, false)? else {
                return Ok(false);
            };
            let want = read_place(&params)?;
            let name = item.get("name").and_then(Value::as_str).unwrap_or("");
            let parent = item
                .get(if op.entity.entity_type == EntityType::Folder {
                    "parent_id"
                } else {
                    "folder_id"
                })
                .and_then(Value::as_i64);
            Ok(name == want.name && parent == want.parent)
        }

        "trash_remote" => {
            if op.entity.is_provisional() {
                return Ok(false);
            }
            match stat(env, op.entity, false)? {
                None => Ok(true),
                Some(item) => Ok(item.get("deleted").and_then(Value::as_bool) == Some(true)),
            }
        }

        _ => Ok(false),
    }
}

// ---------------------------------------------------------------------------
// Bookkeeping
// ---------------------------------------------------------------------------

/// Record that the two sides now agree.
///
/// The one write that everything else depends on. Every delta the engine will
/// ever compute is measured from here, so it is written **after** the bytes
/// have landed and never before — an agreement recorded ahead of the work it
/// describes is a change the engine will never look for again.
fn agree(entry: &mut Entry, content: Option<ContentId>, fingerprint: Option<jd_vfs::Fingerprint>) {
    entry.synced_placement = Some(entry.remote.clone());
    if content.is_some() {
        entry.synced_content = content;
    }
    if fingerprint.is_some() {
        entry.synced_fingerprint = fingerprint;
    }
    entry.status = LocalStatus::Synced;
}

/// Fold a server file export into the entry.
fn apply_export(entry: &mut Entry, file: &Value) {
    if let Some(name) = file.get("name").and_then(Value::as_str) {
        entry.remote.name = name.to_string();
    }
    entry.remote.parent = file.get("folder_id").and_then(Value::as_i64);
    if let Some(sha) = file.get("content_sha256").and_then(Value::as_str) {
        entry.remote_content = Some(ContentId {
            sha256: sha.to_string(),
            size: file.get("size").and_then(Value::as_u64).unwrap_or(0),
        });
    }
    if let Some(t) = file.get("modified_time").and_then(Value::as_str) {
        entry.remote_modified_time = Some(t.to_string());
    }
    if let Some(c) = file.get("head_change_id").and_then(Value::as_i64) {
        entry.head_change_id = c;
    }
}

fn hex(bytes: &[u8]) -> String {
    let mut s = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        s.push_str(&format!("{b:02x}"));
    }
    s
}
