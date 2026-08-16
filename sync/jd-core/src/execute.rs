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
    /// A disk that would not take the bytes: the scratch file an encrypted
    /// upload is written to, most of the time. Retried, because a full or busy
    /// disk is a thing that stops being full or busy.
    #[error("could not write a working file: {0}")]
    Io(#[from] std::io::Error),
}

/// How one operation ended.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum OpOutcome {
    /// Applied, and the agreement now reflects it.
    Done,
    /// Refused in a way another attempt will not change: the server rejected
    /// it, or this build cannot carry it out. Dropped rather than retried, and
    /// raised as an issue — a person has to decide what happens next.
    Withdrawn(String),
    /// The premise no longer holds — the file was deleted while the op sat in
    /// the queue, the entry is gone from the server. Dropped rather than
    /// retried; the next round decides afresh from what is actually there.
    ///
    /// Separate from `Withdrawn` because nothing here is anybody's problem.
    /// Deleting a file while it happens to be uploading is an ordinary thing to
    /// do, and a soak run found it leaving behind an item asking the user to
    /// look at a file they had just thrown away. The work still stops; it just
    /// stops quietly.
    Overtaken(String),
    /// Try again later. Carries what to say about it in the meantime.
    Retry(String),
}

/// What one pass over the journal did.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct ExecReport {
    pub done: usize,
    pub withdrawn: usize,
    /// Dropped because the world moved on. Counted apart from `withdrawn` so a
    /// quiet drop still shows up somewhere — a pass that silently abandoned
    /// half its work would otherwise look like a pass with less to do.
    pub overtaken: usize,
    pub retrying: usize,
    /// Ops left alone because their backoff has not elapsed.
    pub deferred: usize,
}

impl ExecReport {
    pub fn attempted(&self) -> usize {
        self.done + self.withdrawn + self.overtaken + self.retrying
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
    /// Builds the name a rescued copy is kept under. Takes the suffix that
    /// disambiguates repeats, because the same file conflicting twice in one day
    /// must not produce the same name twice — see `free_conflict_path`.
    pub conflict_name: &'a dyn Fn(&str, u32) -> String,
    /// The key for encrypted folders, if this device was given one.
    ///
    /// `None` is an ordinary state, not a degraded one: an account with no
    /// encrypted folders never needs it, and a laptop linked without them syncs
    /// everything else exactly as it would have. What it must never be is
    /// silently absent — a device that had a vault and lost it would otherwise
    /// look identical to one that never had it, and the two need opposite
    /// advice.
    pub vault: Option<&'a crate::vault::Vault>,
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
            OpOutcome::Overtaken(_) => report.overtaken += 1,
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
        OpOutcome::Overtaken(_) => {
            // No issue: there is nothing here for a person to decide. The next
            // scan plans from what is on the disk and the server now.
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
    // A refused name is not a broken operation, and it is not something to give
    // up on and tell somebody about: the server is holding a live sibling with
    // this name, this device will be told about that sibling on the next index
    // walk, and landing it is what moves our copy aside and frees the name. The
    // thing that resolves this is already on its way.
    if let ExecError::Proto(p) = e {
        if p.name_taken() {
            return OpOutcome::Retry(
                "something here is already using that name; waiting to be told what it is".into(),
            );
        }
        // The destination went to the server's trash while we were working
        // towards it, so this op was overtaken by somebody else's delete rather
        // than failing. Saying so is what stops it being retried against a
        // folder that is never coming back: the same index walk that carries
        // the delete will trash our copy of the folder, and anything of ours
        // inside it that the server never took is rescued on the way out.
        if p.parent_trashed() {
            return OpOutcome::Overtaken("the folder it was going into is in the trash".into());
        }
    }
    match e {
        ExecError::Proto(ProtoError::Transport(m)) => OpOutcome::Retry(m.clone()),
        ExecError::Proto(ProtoError::Io(m)) => OpOutcome::Retry(m.to_string()),
        ExecError::Proto(ProtoError::Api {
            status, message, ..
        }) => match status {
            // Gone. Nothing to retry against; the next round re-derives, and
            // there is nothing here to tell anybody about.
            404 => OpOutcome::Overtaken(message.clone()),
            // Out of step with the server, which is a thing that resolves.
            409 | 423 | 429 => OpOutcome::Retry(message.clone()),
            s if *s >= 500 => OpOutcome::Retry(message.clone()),
            _ => OpOutcome::Withdrawn(message.clone()),
        },
        ExecError::Proto(other) => OpOutcome::Retry(other.to_string()),
        ExecError::Vfs(jd_vfs::VfsError::NotFound(p)) => {
            OpOutcome::Overtaken(format!("{} is no longer there", p.display()))
        }
        ExecError::Vfs(other) => OpOutcome::Retry(other.to_string()),
        ExecError::Contract(m) | ExecError::UnknownOp(m) => OpOutcome::Withdrawn(m.clone()),
        // A failing state store is not something the next attempt escapes.
        ExecError::Store(m) => OpOutcome::Retry(m.to_string()),
        ExecError::Io(m) => OpOutcome::Retry(m.to_string()),
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
        "move_remote" => {
            let from = params.get("from").and_then(|v| read_place(v).ok());
            move_remote(env, op, read_place(&params)?, from)
        }
        "move_local" => {
            let from = params.get("from").and_then(|v| read_place(v).ok());
            move_local(env, op, read_place(&params)?, from)
        }
        "park_remote" => {
            let entry = match env.store.get_entry(op.entity)? {
                Some(e) => e,
                None => return Ok(OpOutcome::Overtaken("the entry is gone".into())),
            };
            let to = Placement {
                parent: entry.remote.parent,
                name: read_place(&params)?.name,
            };
            // No recorded starting point, and none wanted: parking builds its
            // target from where the entry is right now, so it cannot be stale.
            move_remote(env, op, to, None)
        }
        "park_local" => {
            let entry = match env.store.get_entry(op.entity)? {
                Some(e) => e,
                None => return Ok(OpOutcome::Overtaken("the entry is gone".into())),
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

/// Why something has no path on this computer.
///
/// These are two unrelated conditions and they want opposite treatment, so they
/// are kept apart all the way to the caller. They used to arrive as one bare
/// `None` and every caller reported the first of them: the soak rig met it as a
/// `move_local` retrying ten times against a sync folder that was plainly on
/// disk the whole time, holding a device open for the rest of the campaign. A
/// user would have been told their sync folder had gone missing.
enum Unplaced {
    /// The sync folder itself is not there — an unplugged drive, a folder moved
    /// while the daemon ran. Waiting is right, because it may well come back,
    /// and treating an absent volume as a mass delete would be the single worst
    /// bug this program could have.
    SyncFolderGone,
    /// A folder between here and the root is not in the store, so there is no
    /// chain left to build a path out of. Trying again cannot put it back; the
    /// next round plans afresh against the tree as it actually is.
    AncestorMissing,
}

impl Unplaced {
    /// What an operation should do about it.
    fn outcome(self) -> OpOutcome {
        match self {
            Unplaced::SyncFolderGone => OpOutcome::Retry("the sync folder is not available".into()),
            // Deliberately quiet. Nothing here is the user's problem, and an
            // item asking them to look at a folder the engine has simply
            // stopped tracking would be noise they cannot act on.
            Unplaced::AncestorMissing => {
                OpOutcome::Overtaken("the folder it was in is no longer tracked".into())
            }
        }
    }
}

/// Where an entry lives on this computer, or why it cannot be said.
enum Placed {
    At(PathBuf),
    Not(Unplaced),
}

impl Placed {
    /// The path, for callers weighing one candidate against another.
    fn path(&self) -> Option<&PathBuf> {
        match self {
            Placed::At(p) => Some(p),
            Placed::Not(_) => None,
        }
    }

    /// The reason there is no path, if there is none.
    fn reason(self) -> Option<Unplaced> {
        match self {
            Placed::At(_) => None,
            Placed::Not(why) => Some(why),
        }
    }
}

/// Where an entry lives on this computer.
///
/// Built by walking parents, because the store keys by identity and the
/// filesystem addresses by path. A missing link in that chain is not an error
/// worth retrying — it means the tree changed underneath us and the next round
/// will produce a plan that fits the tree as it now is.
fn local_path(env: &ExecEnv, entry: &Entry) -> Result<Placed, ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok(Placed::Not(Unplaced::SyncFolderGone));
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
            return Ok(Placed::Not(Unplaced::AncestorMissing));
        };
        parts.push(folder.effective_local_name().to_string());
        parent = folder.local_placement().parent;
    }
    let mut path = root;
    for part in parts.iter().rev() {
        path.push(part);
    }
    Ok(Placed::At(path))
}

/// Where a placement would put something.
fn path_for(env: &ExecEnv, p: &Placement) -> Result<Placed, ExecError> {
    let probe = Entry {
        id: EntityId::file(0),
        remote: p.clone(),
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

/// What a download writes into: the bytes the server sent, on their way to
/// becoming the file the user has.
///
/// For a plaintext file those are the same bytes and this is a pass-through.
/// For an encrypted one they are not, and the difference has to be accounted
/// for on both sides at once:
///
/// - **The ciphertext side** is what the server measured and what a resumed
///   request has to count from. Its hash is the only thing the server's answer
///   can be checked against.
/// - **The plaintext side** is what lands on disk and what the agreement is
///   recorded in.
///
/// Every chunk is authenticated before any of its plaintext is written, so a
/// tampered or transplanted chunk stops the transfer instead of reaching the
/// spool. Nothing that failed to verify is ever committed.
struct Arrival<'a> {
    cipher_hasher: Sha256,
    cipher_written: u64,
    sink: ArrivalSink<'a>,
    /// A decrypt failure, kept because `Write` can only report io errors and
    /// "the tag did not match" must not be reported as a disk problem.
    fault: Option<String>,
}

enum ArrivalSink<'a> {
    Plain(Landing<'a>),
    Encrypted(Box<jd_crypto::drive::ContentDecryptor<Landing<'a>>>),
}

/// What a completed download turned out to be, in both domains.
struct Arrived {
    cipher: ContentId,
    plain: ContentId,
}

impl<'a> Arrival<'a> {
    fn plain(spool: &'a mut dyn Write) -> Arrival<'a> {
        Arrival {
            cipher_hasher: Sha256::new(),
            cipher_written: 0,
            sink: ArrivalSink::Plain(Landing {
                inner: spool,
                hasher: Sha256::new(),
                written: 0,
            }),
            fault: None,
        }
    }

    fn encrypted(
        spool: &'a mut dyn Write,
        file_key: &jd_crypto::drive::FileKey,
        content_id: &str,
    ) -> Arrival<'a> {
        Arrival {
            cipher_hasher: Sha256::new(),
            cipher_written: 0,
            sink: ArrivalSink::Encrypted(Box::new(jd_crypto::drive::ContentDecryptor::new(
                Landing {
                    inner: spool,
                    hasher: Sha256::new(),
                    written: 0,
                },
                file_key,
                content_id,
            ))),
            fault: None,
        }
    }

    /// Close the container and report both identities.
    fn finish(self) -> Result<Arrived, String> {
        if let Some(fault) = self.fault {
            return Err(fault);
        }
        let cipher_sha = hex(&self.cipher_hasher.finalize());
        let landing = match self.sink {
            ArrivalSink::Plain(landing) => landing,
            // Errors here are the ones only the end can see: a container that
            // stopped mid-block, or no chunks at all where an empty file would
            // still have one.
            ArrivalSink::Encrypted(dec) => dec.finish().map_err(|e| e.to_string())?.0,
        };
        Ok(Arrived {
            cipher: ContentId {
                sha256: cipher_sha,
                size: self.cipher_written,
            },
            plain: ContentId {
                sha256: hex(&landing.hasher.finalize()),
                size: landing.written,
            },
        })
    }
}

impl<'a> Write for Arrival<'a> {
    fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
        self.cipher_hasher.update(buf);
        self.cipher_written += buf.len() as u64;
        match &mut self.sink {
            ArrivalSink::Plain(landing) => landing.write_all(buf)?,
            ArrivalSink::Encrypted(dec) => {
                if let Err(e) = dec.push(buf) {
                    let text = e.to_string();
                    self.fault = Some(text.clone());
                    return Err(std::io::Error::other(text));
                }
            }
        }
        Ok(buf.len())
    }
    fn flush(&mut self) -> std::io::Result<()> {
        match &mut self.sink {
            ArrivalSink::Plain(landing) => landing.flush(),
            ArrivalSink::Encrypted(_) => Ok(()),
        }
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
/// A conflict-copy path nothing is at yet.
///
/// `conflict_copy_name` takes a suffix precisely so that repeats within one day
/// can be told apart, and for a long time nobody passed anything but 1. The
/// result was that every conflicted copy of one file, on one day, from one
/// device, was handed the identical name — and both places that rescue a file
/// land it with a plain rename, which silently destroys whatever is already
/// there. The two functions whose whole purpose is not losing the user's work
/// were overwriting the copy they had rescued an hour earlier.
///
/// So the name is chosen against the disk rather than computed and hoped for.
///
/// `taken` extends that to the other place a name can already be spoken for.
/// Two computers meeting the same conflict on the same day derive the same
/// name, and whichever uploads first owns it; the other's copy is then refused
/// that name for as long as it keeps asking, because the file holding it has
/// arrived and settled and is not going to move. Free has to mean free on both
/// sides — a disk this rescue can land on, and a name the server has not
/// already given away.
fn free_conflict_path(
    env: &ExecEnv,
    beside: &std::path::Path,
    name: &str,
    taken: &[String],
) -> Result<PathBuf, ExecError> {
    // Bounded so a directory that somehow defeats this cannot spin forever;
    // a thousand conflicted copies of one file in one day is already a story.
    for suffix in 1..=1000 {
        let candidate_name = (env.conflict_name)(name, suffix);
        if taken.iter().any(|t| t == &candidate_name) {
            continue;
        }
        let candidate = beside.with_file_name(&candidate_name);
        if env.vfs.fingerprint(&candidate)?.is_none() {
            return Ok(candidate);
        }
    }
    Err(ExecError::Contract(format!(
        "no free conflict-copy name for {name} after a thousand tries"
    )))
}

/// The names the server has already given out inside one folder.
///
/// Live only: a trashed sibling is not holding anything, and the server's own
/// uniqueness rule says the same, so treating one as an obstacle would push
/// every rescue a suffix further along for no reason.
fn names_the_server_has(env: &ExecEnv, parent: Option<i64>) -> Result<Vec<String>, ExecError> {
    Ok(env
        .store
        .every_entry()?
        .into_iter()
        .filter(|e| !e.remote_deleted && !e.id.is_provisional() && e.remote.parent == parent)
        .map(|e| e.remote.name)
        .collect())
}

fn make_room(
    env: &ExecEnv,
    path: &std::path::Path,
    incoming: Option<&str>,
) -> Result<(), ExecError> {
    let Some(fingerprint) = env.vfs.fingerprint(path)? else {
        return Ok(());
    };
    let existing = match env
        .store
        .cached_hash(fingerprint, env.vfs.personality().mtime_granularity_ns)?
    {
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
    // No server-side names to avoid: this moves aside whatever is sitting at a
    // path, which may be nothing the server has ever heard of.
    let aside = free_conflict_path(env, path, &name, &[])?;
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
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    let path = match local_path(env, &entry)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };

    // A signed link is minted here rather than remembered, because a link that
    // was fresh when the round was planned may not be by the time a queue of
    // large files reaches this one.
    let Some(item) = stat(env, op.entity, true)? else {
        return Ok(OpOutcome::Overtaken("the server no longer has it".into()));
    };
    if item.get("deleted").and_then(Value::as_bool) == Some(true) {
        return Ok(OpOutcome::Overtaken("it is in the server's trash".into()));
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

    // An encrypted file needs its key before a single byte is worth fetching.
    // Asked for here rather than at the top: an entry can lose its grant between
    // the round that planned this and the moment it runs.
    let file_key = if entry.is_encrypted {
        let (Some(vault), Some(wrapped)) = (env.vault, entry.wrapped_file_key.as_deref()) else {
            return Ok(OpOutcome::Retry(
                "waiting for a key for this encrypted file".into(),
            ));
        };
        match vault.open_file_key(wrapped) {
            Ok(k) => Some(k),
            Err(e) => return Ok(OpOutcome::Retry(e.to_string())),
        }
    } else {
        None
    };
    let content_id = entry.content_id.clone();
    if entry.is_encrypted && content_id.is_none() {
        // Without it no chunk can be authenticated, and downloading anyway
        // would mean writing bytes nothing has checked.
        return Ok(OpOutcome::Retry(
            "this encrypted file's details have not been read yet".into(),
        ));
    }

    let mut spool = env.vfs.spool(&path)?;
    let mut landing = match (&file_key, &content_id) {
        (Some(key), Some(cid)) => Arrival::encrypted(&mut *spool, key, cid),
        _ => Arrival::plain(&mut *spool),
    };

    let mut remints = 0u32;
    while landing.cipher_written < want_size {
        let from = landing.cipher_written;
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
                    return Ok(OpOutcome::Overtaken("the server no longer has it".into()));
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
                let written = landing.cipher_written;
                spool.discard();
                return Ok(match classify(&ExecError::Proto(e)) {
                    OpOutcome::Done => OpOutcome::Retry(format!("stopped after {written} bytes")),
                    other => other,
                });
            }
        }
    }

    let arrived = match landing.finish() {
        Ok(a) => a,
        Err(why) => {
            // Ciphertext that did not authenticate. Never committed, and worth
            // saying out loud rather than retrying quietly forever: bytes that
            // fail their tag are either corrupt in storage or altered in
            // transit, and both are things a person should hear about.
            spool.discard();
            env.store
                .raise_issue(Some(op.entity), "ciphertext", &why, (env.now_ms)() as i64)?;
            return Ok(OpOutcome::Retry(why));
        }
    };

    if arrived.cipher.size != want_size || arrived.cipher.sha256 != want_sha {
        // Nothing is ever adopted on a fingerprint's word, and nothing is ever
        // committed on a byte count's word either. A truncated or corrupted
        // transfer dies here rather than becoming the file the user opens.
        //
        // Measured against the CIPHERTEXT for an encrypted file, because that is
        // the only thing the server ever saw and therefore the only thing its
        // answer can be checked against.
        spool.discard();
        let got = arrived.cipher.size;
        return Ok(OpOutcome::Retry(format!(
            "what arrived does not match what was asked for ({got} of {want_size} bytes)"
        )));
    }

    // With no agreement there is no fingerprint to guard the swap, so anything
    // already at this path is something the engine has never seen. It is not
    // overwritten.
    if entry.synced_fingerprint.is_none() {
        // Compared in the plaintext domain: the question is whether the file
        // already sitting there is this same file, and what is on disk is
        // plaintext whatever the server holds.
        make_room(env, &path, Some(&arrived.plain.sha256))?;
    }

    // The guard: if the local file changed while this was in flight, the
    // decision that produced this download was made against something that no
    // longer exists, and overwriting would discard an edit nobody has seen.
    let fingerprint = match spool.commit(&path, entry.synced_fingerprint) {
        Ok(fp) => fp,
        Err(jd_vfs::VfsError::AlreadyExists(_)) => {
            return Ok(OpOutcome::Overtaken(
                "the file changed here while it was downloading".into(),
            ))
        }
        Err(e) => return Err(e.into()),
    };

    env.store.cache_hash(
        fingerprint,
        &arrived.plain.sha256,
        Some(op.entity),
        (env.now_ms)().saturating_mul(1_000_000),
    )?;

    let mut entry = entry;
    entry.remote_content = Some(arrived.cipher.clone());
    entry.head_change_id = item
        .get("head_change_id")
        .and_then(Value::as_i64)
        .unwrap_or(entry.head_change_id);
    // Both sides of the agreement, in the domain each side speaks. For a
    // plaintext file they are the same thing and the second is left unset; for
    // an encrypted one, recording only the plaintext half would mean every
    // later pass compared the server's ciphertext hash against a plaintext one
    // and reported an edit that never happened.
    entry.synced_remote_content = entry.is_encrypted.then(|| arrived.cipher.clone());
    agree(&mut entry, Some(arrived.plain), Some(fingerprint));
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

fn upload(env: &ExecEnv, op: &Op, as_new: Option<Placement>) -> Result<OpOutcome, ExecError> {
    let Some(entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    let path = match local_path(env, &entry)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };
    let Some(fingerprint) = env.vfs.fingerprint(&path)? else {
        return Ok(OpOutcome::Overtaken(
            "the file is no longer on this computer".into(),
        ));
    };

    let sha = match env
        .store
        .cached_hash(fingerprint, env.vfs.personality().mtime_granularity_ns)?
    {
        Some(s) => s,
        None => {
            let s = env.vfs.hash(&path)?;
            env.store.cache_hash(
                fingerprint,
                &s,
                Some(op.entity),
                (env.now_ms)().saturating_mul(1_000_000),
            )?;
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
    // A new entry, or a new version of the one we already have. The old id is
    // only usable when the server still has it — which is exactly what
    // `UploadAsNew` exists to say it does not.
    let file_id = if as_new.is_some() || op.entity.is_provisional() {
        None
    } else {
        Some(op.entity.server_id)
    };

    let mut params = UploadParams {
        name: placement.name.clone(),
        folder_id: placement.parent,
        file_id,
        size_bytes: fingerprint.size,
        sha256: sha.clone(),
        mime_type: None,
        // Journaled before the first byte went out, and scoped per attempt at
        // the point of use: an upload cannot be resumed across attempts, so the
        // completion of each one is its own request. A lost completion answer
        // is kept from producing a second copy by dedup at init, which matches
        // the retry on content hash.
        idempotency_key: Some(op.idempotency_key.clone()),
        encrypted_metadata: None,
        wrapped_file_keys: Vec::new(),
        modified_time: None,
    };

    // Encrypted uploads send ciphertext from a scratch file rather than the
    // file itself. Everything the server is told changes with it: the name
    // becomes a placeholder, the size becomes the ciphertext's, and the hash
    // goes away entirely — a plaintext hash would let the server's dedup
    // short-circuit match this file against somebody else's, and it never
    // matches anyway.
    let mut ciphertext = None;
    if entry.is_encrypted {
        match encrypt_for_upload(
            env,
            &entry,
            &path,
            &placement.name,
            fingerprint.size,
            file_id,
        )? {
            Ok(packed) => {
                params.name = format!("enc-{}", packed.content_id);
                params.size_bytes = packed.cipher_size;
                params.sha256 = String::new();
                params.mime_type = Some("application/octet-stream".into());
                params.encrypted_metadata = Some(packed.metadata);
                params.wrapped_file_keys = packed.wrapped_file_keys;
                ciphertext = Some(packed.bytes);
                // Carried forward whatever happens next: a re-keyed entry that
                // forgot its content id could not encrypt its own next version.
                let mut with_id = entry.clone();
                with_id.content_id = Some(packed.content_id.clone());
                env.store.put_entry(&with_id)?;
            }
            Err(why) => return Ok(why),
        }
    }

    let outcome = match &mut ciphertext {
        Some(reader) => {
            let mut source = Source(&mut **reader);
            env.api.upload(&params, &mut source)?
        }
        None => {
            let mut reader = env.vfs.open_read(&path)?;
            let mut source = Source(&mut *reader);
            env.api.upload(&params, &mut source)?
        }
    };
    drop(ciphertext);

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
    let was_encrypted = entry.is_encrypted;
    let content_id = entry.content_id.clone();
    apply_export(&mut entry, &file);
    // `apply_export` speaks the server's language, and for an encrypted file
    // that language says the name is `enc-…`. The name this device just sent up
    // inside the metadata blob is the real one, and it is the one every later
    // pass has to resolve against.
    if was_encrypted {
        entry.remote.name = placement.name.clone();
        entry.content_id = entry.content_id.clone().or(content_id);
        entry.synced_remote_content = entry.remote_content.clone();
    }
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

/// Re-encrypt an encrypted file's metadata under a new name.
///
/// The current blob is fetched rather than reconstructed, and that is the whole
/// care in this function. It carries the mime type, the size, the thumbnail flag
/// and the modification time as well as the name — and another device may have
/// changed any of them since this one last looked. Building a fresh blob from
/// what this device happens to remember would silently discard whatever it did
/// not know about, which is a data loss that looks exactly like a rename.
fn reseal_metadata(
    env: &ExecEnv,
    entry: &Entry,
    new_name: &str,
) -> Result<Result<String, OpOutcome>, ExecError> {
    let (Some(vault), Some(wrapped)) = (env.vault, entry.wrapped_file_key.as_deref()) else {
        return Ok(Err(OpOutcome::Retry(
            "waiting for a key for this encrypted file".into(),
        )));
    };
    let file_key = match vault.open_file_key(wrapped) {
        Ok(k) => k,
        Err(e) => return Ok(Err(OpOutcome::Retry(e.to_string()))),
    };
    let Some(item) = stat(env, entry.id, false)? else {
        return Ok(Err(OpOutcome::Overtaken(
            "the server no longer has it".into(),
        )));
    };
    let Some(blob) = item.get("encrypted_metadata").and_then(Value::as_str) else {
        return Ok(Err(OpOutcome::Retry(
            "the server did not send this file's details".into(),
        )));
    };
    let mut meta = match jd_crypto::drive::decrypt_metadata(blob, &file_key) {
        Ok(m) => m,
        Err(e) => return Ok(Err(OpOutcome::Retry(e.to_string()))),
    };
    meta.name = new_name.to_string();
    Ok(Ok(jd_crypto::drive::encrypt_metadata(&meta, &file_key)
        .map_err(|e| {
            ExecError::Contract(format!("sealing file metadata: {e}"))
        })?))
}

/// An encrypted upload, ready to send.
struct Packed {
    bytes: Box<dyn jd_vfs::ReadSeek>,
    cipher_size: u64,
    content_id: String,
    metadata: String,
    wrapped_file_keys: Vec<(i64, String)>,
}

/// Turn a local file into what an encrypted upload actually sends.
///
/// Three things are produced here and none of them can be produced later.
///
/// **The ciphertext**, written to a scratch file rather than encrypted on the
/// fly. A chunked upload that has to resume must re-send bytes identical to the
/// ones it sent before, and encryption cannot reproduce them: every IV is fresh,
/// so re-encrypting the same file yields a different stream. Encrypting into a
/// file the transfer can seek within is what makes resume possible at all.
///
/// **The metadata blob**, carrying the name, the size and the modification time
/// the server is never told. Everything in it is under the file key.
///
/// **The wrapped keys** — but only for a file the server does not have yet. A
/// new *version* reuses the existing key and content id, and the server refuses
/// a key payload on that path: minting a fresh key would leave the new content
/// readable only by this device, behind grants that all wrap the old one.
fn encrypt_for_upload(
    env: &ExecEnv,
    entry: &Entry,
    path: &std::path::Path,
    name: &str,
    plain_size: u64,
    file_id: Option<i64>,
) -> Result<Result<Packed, OpOutcome>, ExecError> {
    let Some(vault) = env.vault else {
        return Ok(Err(OpOutcome::Retry(
            "this device has no key for encrypted folders".into(),
        )));
    };

    // A new version reuses the file's existing key; a new file mints one. The
    // grant is the only proof this device may reuse the key, and the server
    // checks it too.
    let (file_key, content_id, is_new_file) = match (file_id, entry.wrapped_file_key.as_deref()) {
        (Some(_), Some(wrapped)) => {
            let Some(cid) = entry.content_id.clone() else {
                return Ok(Err(OpOutcome::Retry(
                    "this encrypted file's details have not been read yet".into(),
                )));
            };
            match vault.open_file_key(wrapped) {
                Ok(k) => (k, cid, false),
                Err(e) => return Ok(Err(OpOutcome::Retry(e.to_string()))),
            }
        }
        (Some(_), None) => {
            return Ok(Err(OpOutcome::Retry(
                "waiting for a key for this encrypted file".into(),
            )))
        }
        (None, _) => (
            jd_crypto::drive::FileKey::generate(),
            jd_crypto::drive::new_content_id(),
            true,
        ),
    };

    // Seal the key to the destination's FULL reader set, not just to this user.
    // A file in somebody's vault that its owner cannot open would be a file they
    // can see, are billed for, and have permanently lost — so the server
    // requires the owner's entry and refuses the upload without it.
    let mut wrapped_file_keys = Vec::new();
    if is_new_file {
        let readers = env.api.action(
            "drive_public_keys",
            json!({ "folder_id": entry.remote.parent }),
        )?;
        let list = readers
            .get("keys")
            .and_then(Value::as_array)
            .ok_or_else(|| ExecError::Contract("drive_public_keys returned no keys".into()))?;
        let mut without_vault = 0usize;
        for reader in list {
            let Some(user_id) = reader.get("user_id").and_then(Value::as_i64) else {
                continue;
            };
            match reader.get("public_key").and_then(Value::as_str) {
                Some(public_key) if !public_key.is_empty() => {
                    let sealed = jd_crypto::drive::wrap_file_key_to(&file_key, public_key)
                        .map_err(|e| ExecError::Contract(format!("sealing a file key: {e}")))?;
                    wrapped_file_keys.push((user_id, sealed));
                }
                // A member with no Drive vault cannot be given a key. Counted
                // and reported once rather than failing the upload: the file is
                // still readable by everyone who can read it, and refusing would
                // mean one member without a vault blocks the folder for the rest.
                _ => without_vault += 1,
            }
        }
        if wrapped_file_keys.is_empty() {
            return Ok(Err(OpOutcome::Retry(
                "nobody who can reach this folder has a Drive vault to unlock it with".into(),
            )));
        }
        if without_vault > 0 {
            env.store.raise_issue(
                Some(entry.id),
                "no_vault",
                &format!(
                    "{without_vault} member(s) of this folder have no Drive vault yet and cannot open {name}"
                ),
                (env.now_ms)() as i64,
            )?;
        }
    }

    let mut meta = jd_crypto::drive::FileMetadata::new(
        name,
        "application/octet-stream",
        plain_size,
        &content_id,
    );
    meta.mtime = entry.remote_modified_time.clone();
    let metadata = jd_crypto::drive::encrypt_metadata(&meta, &file_key)
        .map_err(|e| ExecError::Contract(format!("sealing file metadata: {e}")))?;

    let mut scratch = env.vfs.scratch()?;
    let mut reader = env.vfs.open_read(path)?;
    let mut encryptor =
        jd_crypto::drive::ContentEncryptor::new(&mut *scratch, &file_key, &content_id);
    std::io::copy(&mut *reader, &mut encryptor)?;
    encryptor.finish()?;
    drop(reader);

    Ok(Ok(Packed {
        bytes: scratch.finish()?,
        // Computed rather than measured: the container's size is an exact
        // function of the plaintext's, and the server's own size ceiling uses
        // the same function. A measured size that disagreed with it would be a
        // bug worth failing on, not a number to send.
        cipher_size: jd_crypto::drive::encrypted_size(plain_size),
        content_id,
        metadata,
        wrapped_file_keys,
    }))
}

/// The losing side of a content conflict, kept beside the winner.
///
/// The remote head keeps the path the user knows; the local content is renamed
/// out of the way and then uploaded under that name, so both survive and both
/// are reachable from every device. Nothing is discarded because two people
/// were editing at once.
fn preserve_local_as(env: &ExecEnv, op: &Op, params: &Value) -> Result<OpOutcome, ExecError> {
    let Some(entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    let name = params
        .get("name")
        .and_then(Value::as_str)
        .ok_or_else(|| ExecError::UnknownOp("no conflict-copy name".into()))?
        .to_string();
    let from = match local_path(env, &entry)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };
    let kept = Placement {
        parent: entry.remote.parent,
        name: name.clone(),
    };
    let to = match path_for(env, &kept)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };
    if env.vfs.fingerprint(&from)?.is_none() {
        return Ok(OpOutcome::Overtaken(
            "the local copy is no longer there".into(),
        ));
    }
    // The name came from the plan, which can see neither the disk nor the
    // server. Something already at this path is an earlier rescue of this same
    // file, and renaming over it would destroy the copy that rescue was for.
    // A name the server has already given to another device's rescue is the
    // same problem seen from the other side: landing here would earn a refusal
    // that never lifts, because the file holding the name has settled.
    let spoken_for = names_the_server_has(env, entry.remote.parent)?;
    let to = if env.vfs.fingerprint(&to)?.is_some() || spoken_for.iter().any(|t| t == &kept.name) {
        free_conflict_path(env, &to, &entry.remote.name, &spoken_for)?
    } else {
        to
    };
    env.vfs.rename(&from, &to)?;

    // Where it actually went, which is not always where the plan said. When the
    // planned path was occupied the search above picked another one, and an
    // entry recorded under the planned name would claim a name that is already
    // somebody else's — the earlier rescue's, which by now is a real file on the
    // server. It then uploads under that name forever: a second live file with
    // one name, which no device can ever fully materialize. That is where the
    // soak rig's duplicate names came from, and a permissive server took every
    // one of them without complaint.
    let kept = Placement {
        parent: entry.remote.parent,
        name: to
            .file_name()
            .map(|n| n.to_string_lossy().to_string())
            .unwrap_or(kept.name),
    };

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
        // A rescued copy is a NEW file with its own key: the content id and the
        // grant belong to the file it was copied away from, and carrying them
        // here would claim this file's bytes are that file's bytes.
        content_id: None,
        synced_content: None,
        synced_remote_content: None,
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
            return Ok(OpOutcome::Overtaken(
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
        return Ok(OpOutcome::Overtaken(
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
    let path = match path_for(env, &placement)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
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
        return Ok(OpOutcome::Overtaken(
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
fn move_remote(
    env: &ExecEnv,
    op: &Op,
    to: Placement,
    from: Option<Placement>,
) -> Result<OpOutcome, ExecError> {
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    if op.entity.is_provisional() {
        return Ok(OpOutcome::Overtaken(
            "it does not exist on the server yet".into(),
        ));
    }
    // Where the server had this when the move was decided. If it has since put
    // it somewhere else, somebody else moved it and this op describes a journey
    // that no longer starts where it thought — so pushing it fights them for a
    // name their file may now hold. Reconcile settles move against move, and it
    // never gets the chance while a stale rename is retried into a refusal that
    // waiting cannot lift. The soak rig found it as a move_remote on twenty-one
    // attempts, still asking for a name another file had taken hours earlier.
    if from.is_some_and(|f| entry.remote != f) {
        return Ok(OpOutcome::Overtaken(
            "the server has moved it since this was planned".into(),
        ));
    }
    // The folder this is moving INTO may not exist on the server yet, and a
    // provisional id names nothing the server can look up. Uploading and
    // creating a folder both already wait for this; moving did not, and sent
    // the negative id as a real one. The server cannot honour it, the file
    // stays where it was, and the rename that follows then asks for a name its
    // old neighbours are still using — which comes back `name_taken`, the one
    // refusal this client waits on forever. The soak rig had it on sixteen
    // attempts with the destination folder still uncreated.
    if let Some(p) = to.parent.filter(|p| *p < 0) {
        // Still waiting to be created: this is the ordinary case, and waiting
        // is right.
        if require_entry(env, EntityId::folder(p))?.is_some() {
            return Ok(OpOutcome::Retry(
                "the folder it belongs in is not on the server yet".into(),
            ));
        }
        // The entry is gone, so the folder took a real id or was folded into
        // one that already had it. A provisional id is local and is never
        // reissued, which means nothing will ever answer to this one and
        // waiting for it is waiting forever. Uploads sidestep this by reading
        // the parent as it stands now rather than as it was planned; a move
        // cannot, because its destination lives in the op rather than on the
        // entry. So the plan is stale and the next scan re-derives it from
        // what is actually on the disk and the server. The soak rig had five
        // of these between two devices, at up to nineteen attempts, every one
        // pointed at a provisional id with no entry left behind it.
        return Ok(OpOutcome::Overtaken(
            "the folder it was going into has changed since this was planned".into(),
        ));
    }
    let t = op.entity.entity_type.to_string();

    // Rename and reparent are separate calls, and a crash between them leaves
    // the entry renamed but not moved. That is a state the next round reads
    // correctly and finishes, which is why they do not need to be one call.
    if entry.remote.parent != to.parent {
        let mut body = json!({ "entity_type": t, "entity_id": op.entity.server_id });
        // `parent_id`, which is the only destination key `drive_move` declares.
        // Sending anything else is not a rejected request -- an undeclared key
        // is simply not read, so the move is taken as one to the drive root and
        // the file lands there. It succeeds, it is silent, and the file is gone
        // from where the user put it.
        body["parent_id"] = match to.parent {
            Some(p) => json!(p),
            None => Value::Null,
        };
        env.api
            .action_idempotent("drive_move", body, &format!("{}-move", op.idempotency_key))?;
    }
    if entry.remote.name != to.name {
        // An encrypted FILE has no plaintext name to send. Its name lives inside
        // the metadata blob, so renaming it means decrypting that blob, changing
        // one field, and encrypting it again under the same file key — the
        // server stores the result without ever learning either name. Sending
        // the name itself would hand over the one thing the whole arrangement
        // exists to keep, and the server refuses it outright.
        //
        // Folders are not encrypted this way: a vault folder's own name is
        // plaintext on the server, and only its contents are private.
        let body = if entry.is_encrypted && op.entity.entity_type == EntityType::File {
            match reseal_metadata(env, &entry, &to.name)? {
                Ok(blob) => json!({
                    "entity_type": t,
                    "entity_id": op.entity.server_id,
                    "encrypted_metadata": blob,
                }),
                Err(why) => return Ok(why),
            }
        } else {
            json!({
                "entity_type": t,
                "entity_id": op.entity.server_id,
                "name": to.name,
            })
        };
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
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    let agreed = local_path(env, &entry)?;
    let planned = match &source {
        Some(p) => Some(path_for(env, p)?),
        None => None,
    };
    // Prefer whichever candidate a file is actually at. Either can be stale,
    // and trusting one blindly is how a move retries against a path nothing has
    // been at for hours.
    let planned_at = planned.as_ref().and_then(Placed::path).cloned();
    let agreed_at = agreed.path().cloned();
    let from = match (planned_at, agreed_at) {
        (Some(p), _) if env.vfs.fingerprint(&p)?.is_some() => p,
        (_, Some(a)) if env.vfs.fingerprint(&a)?.is_some() => a,
        (Some(p), _) => p,
        (_, Some(a)) => a,
        // Neither candidate can be placed, so say which condition stopped us.
        // Assuming the volume is gone is how an untracked ancestor came to be
        // retried forever against something no retry can mend.
        _ => {
            let why = planned
                .and_then(Placed::reason)
                .or_else(|| agreed.reason())
                .unwrap_or(Unplaced::SyncFolderGone);
            return Ok(why.outcome());
        }
    };
    let dest = match path_for(env, &to)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };
    if from != dest {
        // A rename lands on top of whatever is at the destination. If that is
        // something nobody has uploaded, this is the moment it would disappear.
        let moving = env
            .vfs
            .fingerprint(&from)?
            .and_then(|fp| {
                env.store
                    .cached_hash(fp, env.vfs.personality().mtime_granularity_ns)
                    .ok()
                    .flatten()
            });
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

/// A few names and a count, so one issue can describe a folder full of files
/// without becoming unreadable.
fn summarise(names: &[String]) -> String {
    const SHOWN: usize = 3;
    if names.len() <= SHOWN {
        return names.join(", ");
    }
    format!(
        "{}, and {} more",
        names[..SHOWN].join(", "),
        names.len() - SHOWN
    )
}

/// Whether the server can be shown to already hold what is on disk here.
///
/// Two things both have to hold: the engine agreed some content with the server
/// for this file, and the bytes on disk are still that content. Either one
/// alone is not enough — an entry can be established and then edited, and an
/// edit nobody has uploaded is exactly the work worth saving.
///
/// Anything this cannot vouch for is treated as not uploaded. Being wrong that
/// way costs a spare copy beside the folder; being wrong the other way costs
/// the file.
fn is_on_the_server(env: &ExecEnv, child: &jd_vfs::DirEntry) -> Result<bool, ExecError> {
    let Some(fp) = child.fingerprint else {
        return Ok(false);
    };
    let Some(id) = env.store.entity_for_file_id(fp.file_id)? else {
        return Ok(false);
    };
    let Some(entry) = env.store.get_entry(id)? else {
        return Ok(false);
    };
    if entry.synced_content.is_none() {
        return Ok(false);
    }
    let Some(synced) = entry.synced_fingerprint else {
        return Ok(false);
    };
    Ok(fp.unchanged_from(&synced, &env.vfs.personality()))
}

/// Move work nobody has uploaded out of a folder that is about to be trashed.
///
/// Trashing a folder is a single rename and *everything* underneath goes with
/// it, including files the engine has no record of. On a device that was dead
/// or offline while the user kept working, those files are the user's only
/// copy: never uploaded, and about to be in the system trash without the user
/// having deleted anything.
///
/// So they are moved beside the folder first and picked up as new files on the
/// next pass, which uploads them. Files the server already has are left to go
/// with the folder — they are recoverable from the server, and rescuing them
/// would litter the tree with copies of things nobody lost.
fn rescue_unsynced(
    env: &ExecEnv,
    folder: &std::path::Path,
    into: &std::path::Path,
) -> Result<Vec<String>, ExecError> {
    let mut rescued = Vec::new();
    let children = match env.vfs.read_dir(folder) {
        Ok(c) => c,
        // A folder that is already gone has nothing to save out of it.
        Err(jd_vfs::VfsError::NotFound(_)) => return Ok(rescued),
        Err(e) => return Err(e.into()),
    };
    for child in children {
        let path = folder.join(&child.name);
        match child.kind {
            jd_vfs::EntryKind::Directory => {
                rescued.extend(rescue_unsynced(env, &path, into)?);
            }
            jd_vfs::EntryKind::File => {
                if is_on_the_server(env, &child)? {
                    continue;
                }
                // Its own name where that is free, because this is a rescue and
                // not a conflict — calling it a conflicted copy would describe
                // something that did not happen. The conflict name is the
                // fallback that keeps the rescue from overwriting anything.
                let plain = into.join(&child.name);
                let to = if env.vfs.fingerprint(&plain)?.is_none() {
                    plain
                } else {
                    free_conflict_path(env, &plain, &child.name, &[])?
                };
                env.vfs.rename(&path, &to)?;
                rescued.push(child.name.clone());
            }
            _ => {}
        }
    }
    Ok(rescued)
}

fn trash_local(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    let Some(entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    let path = match local_path(env, &entry)? {
        Placed::At(p) => p,
        // No chain of folders left to build a path from, so there is provably
        // nothing on this disk to put in the trash — and the record has to go
        // with that conclusion rather than survive it. Returning here and
        // leaving the entry is how a device ends up planning the same trash
        // every pass forever: the operation is dropped as overtaken, nothing
        // changes, and the entry keeps saying it is waiting on work that can
        // never happen.
        //
        // An unplugged drive is the opposite case and keeps waiting. The whole
        // point of telling the two apart is that a volume that is not there
        // must never be read as "every file is gone".
        Placed::Not(Unplaced::AncestorMissing) => {
            if op.entity.entity_type == EntityType::Folder {
                env.store.delete_subtree(op.entity)?;
            } else {
                env.store.delete_entry(op.entity)?;
            }
            return Ok(OpOutcome::Overtaken(
                "the folder it was in is no longer tracked".into(),
            ));
        }
        Placed::Not(why) => return Ok(why.outcome()),
    };
    if op.entity.entity_type == EntityType::Folder {
        if let Some(parent) = path.parent() {
            let rescued = rescue_unsynced(env, &path, parent)?;
            if !rescued.is_empty() {
                let detail = format!(
                    "{} file(s) here had not reached the server yet and were moved to {} \
                     rather than going to the trash with the folder: {}",
                    rescued.len(),
                    parent.display(),
                    summarise(&rescued),
                );
                env.store.raise_issue(
                    Some(op.entity),
                    "rescued_from_trash",
                    &detail,
                    (env.now_ms)() as i64,
                )?;
            }
        }
    }
    match env.vfs.trash(&path) {
        Ok(()) => {}
        // Gone already is the outcome we wanted.
        Err(jd_vfs::VfsError::NotFound(_)) => {}
        Err(e) => return Err(e.into()),
    }
    if op.entity.entity_type == EntityType::Folder {
        // The whole subtree, not just the folder. Deleting the folder alone
        // leaves its children naming a parent that is gone, and an entry with
        // no parent has no path — a pass finds work by resolving paths, so
        // those entries are never considered again and the files behind them
        // sit there forever. The server deleted the folder, which took its
        // contents with it, so there is nothing under here left to agree about.
        env.store.delete_subtree(op.entity)?;
    } else {
        env.store.delete_entry(op.entity)?;
    }
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
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    if entry.id.entity_type == EntityType::Folder {
        entry.synced_placement = Some(entry.remote.clone());
        entry.status = LocalStatus::Synced;
        env.store.put_entry(&entry)?;
        return Ok(OpOutcome::Done);
    }

    let path = match local_path(env, &entry)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };
    let Some(fingerprint) = env.vfs.fingerprint(&path)? else {
        return Ok(OpOutcome::Overtaken(
            "the file is no longer on this computer".into(),
        ));
    };
    let local_sha = match env
        .store
        .cached_hash(fingerprint, env.vfs.personality().mtime_granularity_ns)?
    {
        Some(s) => s,
        None => {
            let s = env.vfs.hash(&path)?;
            env.store.cache_hash(
                fingerprint,
                &s,
                Some(op.entity),
                (env.now_ms)().saturating_mul(1_000_000),
            )?;
            s
        }
    };
    let remote_sha = entry.remote_content.as_ref().map(|c| c.sha256.clone());
    if remote_sha.as_deref() != Some(local_sha.as_str()) {
        return Ok(OpOutcome::Overtaken(
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
