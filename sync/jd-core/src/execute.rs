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
use crate::pass::stat_one;
use crate::reconcile::Action;
use crate::remote::RemoteState;
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
    ///
    /// Only ever for progress some OTHER actor can make while this op sits in
    /// the journal: external state such as a vault unlock or a grant off the
    /// feed, another entity's operation, or an impediment this op has just
    /// cleared itself. Work the ROUND has to plan for this op's own entity can
    /// never be waited for here -- an entity with an open op is skipped by the
    /// round, so the op is what stops the round doing the thing it is waiting
    /// for. That reads as a device politely retrying and is a deadlock.
    ///
    /// The park is the instance: it refused while the local copy held bytes the
    /// server did not have, waiting for an upload only the round could plan,
    /// and spent two thousand attempts doing it. Stand down with `Overtaken`
    /// instead and let the next round decide afresh.
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
    let ordered = plan.ordered();
    // Every move's key is minted before any park is written down, because a
    // park is NAMED after the move that finishes it. `move_remote` knows its
    // own park by `swap_name(its key)` and completes the dance from there; a
    // park named any other way reads, once it has been recorded, as somebody
    // else's move of the same file, and the finisher is dropped as overtaken
    // -- with the file left on the server under the engine's own name for
    // ever. Estate seed 8060024, with the two ops running back to back.
    let keys: Vec<String> = ordered.iter().map(|_| key_for()).collect();

    // Parking first. A cycle's victim has to be out of its slot before any of
    // the moves that want it can run, and the moves are journaled below in the
    // order the planner ranked them.
    for entity in &plan.broken_cycles {
        let finisher = ordered.iter().position(|o| o.entity == *entity);
        let side = finisher
            .map(|i| match ordered[i].action {
                Action::ApplyRemoteMove { .. } => "park_local",
                _ => "park_remote",
            })
            .unwrap_or("park_remote");
        let scratch = match finisher {
            Some(i) => crate::order::swap_name(&keys[i]),
            // Nothing planned to finish it. The planner does not produce this;
            // the name still has to be unique.
            None => crate::order::swap_name(&key_for()),
        };
        ids.push(store.queue_op(
            side,
            *entity,
            &json!({ "name": scratch }).to_string(),
            &key_for(),
        )?);
    }

    for (op, key) in ordered.iter().zip(keys) {
        let (kind, mut params) = encode(&op.action);
        // Where the thing is right now, recorded while it is still known. A
        // file the user moved on this computer is not at the agreed placement,
        // and by the time the op runs there is nothing left to work that out
        // from.
        if let Some(from) = &op.from {
            params["from"] = place(from);
        }
        ids.push(store.queue_op(kind, op.entity, &params.to_string(), &key)?);
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
        Action::AdoptPlacement { to } => ("adopt_placement", place(to)),
        Action::RemoveFromScope => ("remove_from_scope", json!({})),
        Action::UnmaterializeAndPark { reason } => (
            "unmaterialize_and_park",
            json!({ "reason": crate::store::encode_reason(reason) }),
        ),
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

    for queued in env.store.queued_ops()? {
        // Read again, not run from the copy: an op earlier in this run can
        // have rewritten this one's parameters (`redirect_queued_parent`),
        // or dropped it.
        let Some(op) = env.store.get_op(queued.op_id)? else {
            continue;
        };
        if op.state != OpState::Queued {
            continue;
        }
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
        Err(e) => {
            note_the_parent_is_in_the_trash(env, &e)?;
            classify(&e)
        }
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
    // up on and tell somebody about. It is a plan that has gone out of date: a
    // name was chosen against what this device knew a moment ago, and the server
    // has just said that picture is wrong. Nothing about *this* operation can
    // improve, because the name it carries is fixed — so it is dropped, and the
    // next pass chooses again knowing what it knows then.
    //
    // Waiting was tried, on the reasoning that the sibling holding the name
    // would arrive on a later index walk and landing it would move our copy
    // aside. That holds only when the name we want is one our own copy is
    // sitting on. It is false for every other case, and the two that matter are
    // ordinary: the occupant is a live entry already in this store, so nothing
    // is on its way; or the name is a conflict copy's, chosen from the siblings
    // we had heard of, and the other device minted the same one at the same
    // moment. Nothing will ever free that name. The operation waited anyway —
    // four hundred attempts, in every unsettled seed of a fifteen-hundred-seed
    // sweep, with no error a person could see and no queue that appeared to be
    // growing.
    //
    // Choosing the next free name is emphatically the planner's job and not
    // this one's: the convention for what a copy is called lives with naming,
    // and the executor has no business inventing one.
    if let ExecError::Proto(p) = e {
        // A key already spent on something else. Nothing about this operation
        // can be made to fit the request the key was spent on, so it is dropped
        // and the next pass plans afresh with a key of its own. Retrying earns
        // the same refusal every time, and the loop is silent: no growing
        // queue, no issue, nothing anybody could look at.
        if p.key_reused() {
            return OpOutcome::Overtaken(
                "the key on this was already used for something else; planning it again".into(),
            );
        }
        if p.name_taken() {
            return OpOutcome::Overtaken(
                "something else is using that name; choosing again from what is there now".into(),
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

/// Write down what a `parent_trashed` refusal just told us.
///
/// Calling the operation overtaken is right — somebody else's delete got there
/// first — but it is only half an answer. The other half is that the server has
/// just said, plainly, that a folder this device still believes in is in its
/// trash. Throwing that away leaves the belief standing, so the next pass plans
/// the same work into the same trashed folder and hears the same refusal, and
/// the one after that.
///
/// It resolves eventually *if* the delete turns up on the change feed, which is
/// what the classification quietly assumes. When it has not turned up yet the
/// loop is unbounded, and it costs a device that never reports itself settled:
/// on the rig, a folder created here and deleted from the other machine held a
/// file at three hundred and forty-seven attempts.
///
/// Recording it starts the ordinary handling of a remote delete — the local
/// folder goes to the trash, and anything inside it the server never took is
/// rescued on the way out — instead of waiting for the feed to say the same
/// thing later.
/// What the server holds for this entity now, when the op has been tried before.
///
/// An idempotent retry replays the response the FIRST attempt produced. That is
/// the correct guarantee — the action happened once — but the payload is a
/// snapshot of a moment that has passed, and nothing in it says so. A mutation
/// whose answer was lost comes back describing an entity another device has
/// since renamed, moved or deleted.
///
/// Writing that snapshot down as a fresh agreement is what makes it permanent:
/// the device then believes it AGREES with the server, so nothing it plans will
/// ever disagree, and the difference survives every pass. A long hostile sweep
/// found it as an empty folder living on one device for good, and as a file one
/// device called `slot-3.dat` while the server and both its peers called it
/// `slot-1.dat` — every device quiet, every other invariant satisfied.
///
/// Only retries pay for the extra call. A first attempt's answer describes what
/// the server did a moment ago and is as fresh as anything can be.
fn server_view_after_retry(
    env: &ExecEnv,
    op: &Op,
    id: EntityId,
) -> Result<Option<RemoteState>, ExecError> {
    if op.attempts == 0 {
        return Ok(None);
    }
    stat_one(env, id)
}

fn note_the_parent_is_in_the_trash(env: &ExecEnv, e: &ExecError) -> Result<(), ExecError> {
    let ExecError::Proto(p) = e else {
        return Ok(());
    };
    if !p.parent_trashed() {
        return Ok(());
    }
    // Only the folder the SERVER names. The op's own plan is not evidence: an
    // operation may send a destination it re-resolved at the moment it ran —
    // `create_remote_folder` does exactly that, since a parent that was
    // provisional when the work was planned may be real by the time it runs —
    // so the plan and the request can name different folders. Reading the plan
    // condemned a live folder that merely shared its name with the trashed one
    // the server had refused about. The ordinary deletion path then trashed the
    // local copy and forgot the record, and the change feed had long since
    // moved past that folder's creation, so nothing ever re-learned it: one
    // seed in seventeen thousand, ending with a folder and a file missing from
    // a device while the server still held both.
    //
    // Without a named folder there is nothing to record, and guessing is what
    // this is here to stop. Nothing is lost by staying quiet: a trashed folder
    // arrives as deleted through the change feed like any other.
    let Some(parent) = p.parent_trashed_folder_id() else {
        return Ok(());
    };
    if let Some(mut folder) = env.store.get_entry(EntityId::folder(parent))? {
        if !folder.remote_deleted {
            folder.remote_deleted = true;
            env.store.put_entry(&folder)?;
        }
    }
    Ok(())
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
            // In place: a local park is a rename inside the folder the
            // directory is in NOW, not the one the server has it in -- that
            // one may not exist here yet, which can be the very reason for
            // the park.
            let to = Placement {
                parent: entry.local_placement().parent,
                name: read_place(&params)?.name,
            };
            move_local(env, op, to, None)
        }
        "trash_local" => trash_local(env, op),
        "trash_remote" => trash_remote(env, op),
        "preserve_local_as" => preserve_local_as(env, op, &params),
        "forget" => {
            // Everything under a folder names it as their parent, and
            // `all_entries` builds its list by walking DOWN from the root -- so
            // dropping the folder alone leaves its children with no way back.
            // No pass visits them, nothing plans against them, nothing clears
            // them, and no issue is raised: they are simply never seen again.
            // Soak run 209 left six live files that way, under a folder this
            // very operation had forgotten. `delete_subtree` was written for
            // exactly this and its doc comment describes the same failure.
            //
            // Folders only. `children_of` matches on parent id alone, with
            // nothing to say the parent is a folder, and a file's server id can
            // coincide with a folder's -- so handing it a file id would sweep
            // away an unrelated folder's contents.
            //
            // Deliberately NOT the confirmed forget that `trash_local` and
            // `trash_remote` use, though the same stale-belief argument applies
            // on paper. This op is reached when the folder is gone from the
            // server, and asking about the children then invites the opposite
            // failure: a child the server still answers for, kept, under a
            // parent this op is about to delete -- stranded, which is the state
            // `delete_subtree` exists to prevent and which cost soak run 209 six
            // live files. `forgetting_a_folder_takes_what_was_under_it` holds
            // that line and fails the moment this asks.
            //
            // It is also unreachable rather than merely unreproduced. This op
            // is planned only once the folder's trash row has been absorbed,
            // and a spared child's move committed before that trash, so its row
            // is lower and was absorbed in the same batch first: the pointer is
            // already repaired before anything here decides what to forget. See
            // `forget_folder_the_server_confirms` for the full statement.
            if op.entity.entity_type == EntityType::Folder {
                env.store.delete_subtree(op.entity)?;
            } else {
                env.store.delete_entry(op.entity)?;
            }
            Ok(OpOutcome::Done)
        }
        "adopt" => adopt(env, op),
        "adopt_placement" => adopt_placement(env, op, read_place(&params)?),
        "unmaterialize_and_park" => {
            let raw = params
                .get("reason")
                .and_then(Value::as_str)
                .ok_or_else(|| ExecError::UnknownOp("park has no reason".into()))?;
            let reason = crate::store::decode_reason(raw)
                .ok_or_else(|| ExecError::UnknownOp("park has an unreadable reason".into()))?;
            unmaterialize_and_park(env, op, reason)
        }
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
    // What this filesystem would actually call it. A placement carries the
    // SERVER's name, and a name the server is happy with can be one this disk
    // cannot write -- a reserved DOS stem, a forbidden character, a trailing dot
    // Windows strips. Left raw, a move lands the file under a name the volume
    // silently alters, so it ends up somewhere the engine never looks while the
    // record insists it is elsewhere.
    //
    // Derived rather than read from the store because the destination name is
    // new: naming records the mapping on the pass AFTER the move, and the file
    // has to go to the right place now. Deriving forwards is deterministic and
    // is the same function naming itself uses; only the reverse direction is
    // unreliable, and nothing here reverses it. The parents are unaffected --
    // they come from stored entries and already carry their own local names.
    let local_leaf = match jd_vfs::to_local_name(&p.name, &env.vfs.personality()) {
        jd_vfs::LocalName::Escaped { local, .. } => Some(local),
        // Unsyncable is left alone: refusing to place it is naming's verdict to
        // make and it parks the entry visibly, which is not this function's job.
        jd_vfs::LocalName::AsIs(_) | jd_vfs::LocalName::Unsyncable(_) => None,
    };
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
        local_name: local_leaf,
        status: LocalStatus::Synced,
        wrapped_file_key: None,
        replaces: None,
        stand_in: None,
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

/// A download that did not survive its own container.
struct Damaged {
    why: String,
    /// What actually arrived, measured the way the *server* measures it. This is
    /// the arbiter: compared against what the server said it was sending, it
    /// says whether the bytes were spoiled in transit or handed over spoiled.
    cipher: ContentId,
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
    ///
    /// A failure reports what arrived as well as what went wrong. Without it the
    /// caller cannot tell a transfer that was damaged on the way from bytes that
    /// were already wrong when the server handed them over, and those two need
    /// opposite answers: one is worth another go, the other never will be.
    fn finish(self) -> Result<Arrived, Damaged> {
        let cipher = ContentId {
            sha256: hex(&self.cipher_hasher.finalize()),
            size: self.cipher_written,
        };
        if let Some(fault) = self.fault {
            return Err(Damaged { why: fault, cipher });
        }
        let landing = match self.sink {
            ArrivalSink::Plain(landing) => landing,
            // Errors here are the ones only the end can see: a container that
            // stopped mid-block, or no chunks at all where an empty file would
            // still have one.
            ArrivalSink::Encrypted(dec) => match dec.finish() {
                Ok(l) => l.0,
                Err(e) => {
                    return Err(Damaged {
                        why: e.to_string(),
                        cipher,
                    })
                }
            },
        };
        Ok(Arrived {
            cipher,
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
        if self.fault.is_some() {
            // The container is already broken and nothing more will be kept.
            // The rest of the stream is still taken in, purely to finish
            // measuring what the server actually sent: a partial hash cannot be
            // compared with what was advertised, and that comparison is the only
            // thing that separates bytes spoiled on the way here from bytes that
            // were spoiled before they left. Give that up and the choice is
            // between retrying something hopeless for ever and abandoning a file
            // that one more attempt would have fetched perfectly.
            return Ok(buf.len());
        }
        match &mut self.sink {
            ArrivalSink::Plain(landing) => landing.write_all(buf)?,
            ArrivalSink::Encrypted(dec) => {
                if let Err(e) = dec.push(buf) {
                    // Recorded, not returned. Handed back as an io error this
                    // reads to the caller as a transfer that broke — the one
                    // thing it is not — and gets retried on that footing for
                    // ever. `finish` reports it as what it is.
                    self.fault = Some(e.to_string());
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
/// Is this path completely empty — no file AND no directory?
///
/// `fingerprint` alone cannot answer it: it returns `None` for a directory
/// exactly as it does for an empty spot, so "no file here" has been read as
/// "nothing here" at more than one place that then wrote into an occupied path.
fn nothing_at(env: &ExecEnv, path: &std::path::Path) -> Result<bool, ExecError> {
    Ok(env.vfs.fingerprint(path)?.is_none() && env.vfs.read_dir(path).is_err())
}

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
        // Free means NOTHING is there, not "no file is there". `fingerprint`
        // answers `None` for a directory exactly as it does for an empty spot,
        // so a conflict name already held by a folder read as available and the
        // suffix never advanced past it. For a file the search works and the rig
        // shows the proof -- `slot-1 (conflicted copy ...) 3.dat` -- while a
        // second folder conflict at one name on one day picked the same name
        // every time, and `rename` onto a non-empty directory is `ENOTEMPTY` on
        // every real filesystem. The operation failed, was retried, and got the
        // same answer for as long as it kept asking.
        if nothing_at(env, &candidate)? {
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
/// Make the disk wear the name the server settled on.
///
/// Both arms of a contested upload need this and for one reason: an entry whose
/// `remote` names one thing while the disk names another is read by the next
/// scan as a user rename, and pushed back at the server as a move onto the
/// occupant's name — refused, dropped, re-derived, for ever. The gap IS the
/// defect; closing it is not a tidy-up.
///
/// Returns where the file now is. Never silent: if the destination is occupied
/// or unplaceable the rename cannot happen, and that leaves exactly the gap
/// above, so it is said out loud rather than skipped.
fn follow_the_server_name(
    env: &ExecEnv,
    id: EntityId,
    from: &std::path::Path,
    wanted: &Placement,
    asked_for: &str,
    server_holds: &str,
) -> Result<PathBuf, ExecError> {
    let Placed::At(to) = path_for(env, wanted)? else {
        env.store.raise_issue(
            Some(id),
            "reconcile",
            &format!(
                "{asked_for} was taken, so the server holds this as {}; the local copy \
                 could not be renamed to match",
                wanted.name
            ),
            (env.now_ms)() as i64,
        )?;
        return Ok(from.to_path_buf());
    };
    if to == from {
        return Ok(to);
    }
    // `nothing_at`, not `fingerprint().is_none()`. A directory answers None to a
    // fingerprint exactly as an empty spot does, and this file names that trap
    // twice already. Walking past a directory here would hand `rename` a
    // kind mismatch — `NotADirectory` in the sim, `EISDIR` on a real disk —
    // AFTER the server side has succeeded, so the op fails and retries into the
    // same wall for ever.
    if !nothing_at(env, &to)? {
        // Something is in the way. If it is provably the same bytes the server
        // now holds under this very name, it is a redundant copy — most often
        // OUR OWN, left by an upload that landed and died before its rename —
        // and keeping it would mint the duplicate adoption exists to prevent.
        //
        // Proven with the same ladder `make_room` uses, including the
        // mtime-granularity guard that defuses a same-tick rewrite. Compared
        // against the hash the SERVER holds under the name, not against a
        // re-read of our own file: if ours was edited mid-upload the two differ,
        // and the whole justification for disposing of the blocker is that the
        // server already has its bytes.
        //
        // TRASH, never delete: the bytes survive on the server, in the copy
        // being renamed into place, and in the OS recycle bin. A directory can
        // never be hash-equal to a file, so one always takes the move-aside arm
        // below. On doubt — unreadable, or any error — move aside rather than
        // dispose. A duplicate is an annoyance; a wrong removal is loss.
        let identical = match env.vfs.fingerprint(&to)? {
            Some(fp) => {
                let existing = match env
                    .store
                    .cached_hash(fp, env.vfs.personality().mtime_granularity_ns)?
                {
                    Some(h) => Some(h),
                    None => env.vfs.hash(&to).ok(),
                };
                existing.as_deref() == Some(server_holds)
            }
            None => false,
        };
        if identical {
            env.vfs.trash(&to)?;
        } else {
            let aside = free_conflict_path(env, &to, &wanted.name, &[])?;
            env.vfs.rename(&to, &aside)?;
            env.store.raise_issue(
                Some(id),
                "kept_aside",
                &format!(
                    "{} was moved aside to make room for the synced copy",
                    wanted.name
                ),
                (env.now_ms)() as i64,
            )?;
        }
    }
    env.vfs.rename(from, &to)?;
    env.store.raise_issue(
        Some(id),
        "kept_aside",
        &format!("{asked_for} was already taken here, so this copy is {}", wanted.name),
        (env.now_ms)() as i64,
    )?;
    Ok(to)
}

fn names_the_server_has(env: &ExecEnv, parent: Option<i64>) -> Result<Vec<String>, ExecError> {
    Ok(env
        .store
        .every_entry()?
        .into_iter()
        .filter(|e| !e.remote_deleted && !e.id.is_provisional() && e.remote.parent == parent)
        .map(|e| e.remote.name)
        .collect())
}

pub(crate) fn make_room(
    env: &ExecEnv,
    path: &std::path::Path,
    incoming: Option<&str>,
) -> Result<(), ExecError> {
    match env.vfs.fingerprint(path)? {
        Some(fingerprint) => {
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
        }
        // Not a file. A *folder* can be in the way just as much as a file, and
        // this used to walk straight past one — there is no fingerprint for a
        // directory, so it read as an empty spot.
        //
        // What happened next depended entirely on the filesystem. A real one
        // refuses to rename a folder over a non-empty folder, so the operation
        // failed and was retried forever against a name that was never going to
        // free itself. The simulated one merged the two instead, and the merge
        // silently overwrote a file inside that had never been uploaded
        // anywhere — the rig's own oracle caught it as content the engine
        // removed that nobody could find again.
        //
        // Moving it aside is the same answer a file in the way gets, and it
        // keeps everything underneath: the user ends up with both folders, one
        // under a conflict name, rather than one folder and a hole in it.
        None => {
            if env.vfs.read_dir(path).is_err() {
                return Ok(());
            }
        }
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

    // Abandoning the download is right in both cases below -- there is nothing
    // to fetch. Abandoning it and saying nothing is what was wrong: the record
    // that asked for these bytes is left exactly as it was, so the next pass
    // reads the same entry, wants the same bytes, and asks again. No attempt
    // count rises and nothing stays queued between passes, so it looks like an
    // idle client that merely never goes quiet -- the soak rig had a download
    // journalled, run and gone every few seconds for a whole campaign.
    //
    // Writing down what the server said hands the entry to the ordinary
    // deletion path, which knows what to do with it: forget it if there is
    // nothing here, and rescue the bytes to a new file if the user still has
    // them on this disk.
    let gone = |why: &str| -> Result<OpOutcome, ExecError> {
        let mut entry = entry.clone();
        entry.remote_deleted = true;
        env.store.put_entry(&entry)?;
        Ok(OpOutcome::Overtaken(why.into()))
    };
    //
    // Only ever on a plain answer from the server. A refusal it could not
    // deliver -- an outage, a rate limit, a dropped connection -- comes back as
    // an error and is retried; `None` here means the server looked and has no
    // such file, which is the only footing on which a record should be retired.
    //
    // A signed link is minted here rather than remembered, because a link that
    // was fresh when the round was planned may not be by the time a queue of
    // large files reaches this one.
    let Some(item) = stat(env, op.entity, true)? else {
        return gone("the server no longer has it");
    };
    if item.get("deleted").and_then(Value::as_bool) == Some(true) {
        return gone("it is in the server's trash");
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
        Err(damaged) => {
            // Ciphertext that did not authenticate. Never committed either way;
            // what is left is to work out whether another attempt could do any
            // better, and the bytes themselves answer that.
            spool.discard();
            if damaged.cipher.size != want_size || damaged.cipher.sha256 != want_sha {
                // Not what the server set out to send. Something between there
                // and here spoiled it, which is an ordinary transfer failure and
                // is treated as one: no issue raised, because the next attempt
                // very likely succeeds and nobody needs to hear about a retry.
                let got = damaged.cipher.size;
                return Ok(OpOutcome::Retry(format!(
                    "what arrived does not match what was asked for ({got} of {want_size} bytes)"
                )));
            }
            // Byte for byte what the server meant to hand over, and it still
            // will not open. Fetching it again gets the same bytes and opens
            // them with the same key, so retrying is not patience — it is a
            // device that never goes quiet, reporting only "decryption failed"
            // for as long as it runs.
            //
            // Written off against this content and this key, so the moment
            // either changes the file is picked back up on its own.
            env.store.mark_unreadable(
                op.entity,
                &damaged.cipher,
                entry.wrapped_file_key.as_deref(),
                (env.now_ms)() as i64,
            )?;
            return Ok(OpOutcome::Withdrawn(damaged.why));
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

    // The last gate before bytes are destroyed, and the only one that cannot be
    // defeated by history.
    //
    // The guard below compares fingerprints, and a fingerprint is a claim about
    // content made by something that looked earlier. Every such claim can be
    // wrong about the file standing here NOW -- the recorded agreement, the
    // hash cache, an operation that refreshed the fingerprint on its way past
    // -- and each of them has been. A cheaper discriminator is defeated by
    // construction for the same reason: it is one of the suspects.
    //
    // So the file is read. It costs one hash of a file this operation is about
    // to rewrite in full, and only when something is actually standing there.
    // A file standing here is the only precondition. This used to also require
    // a recorded fingerprint, which read as a hole and was not one: with no
    // fingerprint, `spool.commit` is handed a `None` expectation, and both
    // filesystems refuse that outright over any standing file (`jd-vfs`
    // real.rs, and the simulator alongside it) precisely because a file the
    // engine has never seen may be the only copy of it. Every content case
    // landed in the same place either way -- the refusal arm below adopts,
    // refreshes or stands down exactly as this gate does, one call later.
    //
    // It is removed because of what the comment above claims. A gate described
    // as the one that cannot be defeated by history must not borrow its
    // totality from a guard two crates away: stated here, it should be true
    // here. Nothing about behaviour changes.
    //
    // The state that provoked the question, so the next reader finds the answer
    // rather than the doubt: an upload that finishes against a file the user
    // has already saved over records the agreement with no fingerprint on
    // purpose, so the next scan re-hashes and sends the newer save. That is the
    // record at its least trustworthy, and it is covered -- here now, and in
    // `commit` all along.
    if env.vfs.fingerprint(&path)?.is_some() {
        let here = env.vfs.hash(&path)?;
        let agreed = entry.synced_content.as_ref().map(|c| c.sha256.as_str());
        if here != arrived.plain.sha256 && agreed != Some(here.as_str()) {
            // Neither what has just arrived nor what both sides last agreed on.
            // Whatever it is, nobody has seen it, and it is not this
            // operation's to overwrite: the scan meets it as a conflict, which
            // keeps both copies. The same three-way answer the refusal arm
            // below gives, asked before the write rather than after it.
            return Ok(OpOutcome::Overtaken(
                "the file standing here is not the one this download was decided against".into(),
            ));
        }
    }

    // The guard: if the local file changed while this was in flight, the
    // decision that produced this download was made against something that no
    // longer exists, and overwriting would discard an edit nobody has seen.
    let fingerprint = match spool.commit(&path, entry.synced_fingerprint) {
        Ok(fp) => fp,
        Err(jd_vfs::VfsError::AlreadyExists(blocked)) => {
            let blocked = Some(blocked);
            // The guard fired. It compares fingerprints, and a fingerprint
            // drifts for reasons that are not edits — an application that saves
            // by writing a temporary and renaming it over the original leaves
            // the same bytes behind a new inode and a new mtime.
            //
            // The scan reads that correctly, because it compares content, and
            // reports nothing changed. So the two disagree about what "changed"
            // means, and the disagreement does not resolve: the pass keeps
            // planning a download the guard keeps refusing, with no error and
            // nothing queued to show for it.
            //
            // Asking the question the guard was really asking — is there an
            // edit here nobody has seen? — settles it. If the bytes still match
            // the agreement then there is not, and the only thing wrong is a
            // stale fingerprint, which is recorded so the next attempt gets
            // past. If they do differ, the local edit is real and the scan will
            // meet it as a conflict on the next pass.
            // The refusal may not be about the target at all. A file standing
            // where one of this path's folders should be is reported by name,
            // and the file being written does not exist and cannot until
            // somebody moves that one. Reading it as an edit here sends it to
            // Overtaken, which drops the operation without touching the record
            // that asked for it -- so the next pass plans the same download,
            // and every pass after that.
            if let Some(blocker) = blocked.filter(|b| b != &path) {
                return Ok(OpOutcome::Retry(format!(
                    "{} cannot be written while {} is a file",
                    path.display(),
                    blocker.display(),
                )));
            }
            // The fingerprint is taken BEFORE the hash, and the order is
            // load-bearing rather than incidental. These are two instants, and
            // on a file the size a real application saves they are milliseconds
            // apart -- a user save landing between them is not exotic.
            //
            // Taken in this order, such a save leaves the fingerprint stale in
            // the safe direction: it describes the file as it was BEFORE the
            // save, so nothing here can conclude the two agree, and the next
            // scan re-hashes and meets the edit. Reversed, the hash would
            // describe the old bytes and the fingerprint the new file, and the
            // adopt below would bind them together as one fact -- the edit
            // recorded as already synced, and swallowed without a trace.
            //
            // No simulator can catch a reversal: its map is behind a lock, so
            // nothing can land between the two calls. This comment is the only
            // thing standing between that ordering and a tidy-looking refactor.
            let Some(fp) = env.vfs.fingerprint(&path)? else {
                return Ok(OpOutcome::Overtaken(
                    "the file changed here while it was downloading".into(),
                ));
            };
            let here = env.vfs.hash(&path)?;

            // Is the file already the one that just arrived? Then there is
            // nothing to write and nothing wrong — the bytes are here, and all
            // that is missing is the record saying so.
            //
            // This is the case `make_room` walks away from a few lines above:
            // it is handed the incoming hash, finds the same content already at
            // the path, and correctly leaves it alone rather than shoving a
            // file aside to replace it with itself. The commit then refuses,
            // because with no agreement there is no fingerprint to guard the
            // swap with, and the two decisions disagree.
            //
            // Reading that refusal as an unseen edit is what made it endless.
            // An entry that has never synced has NO agreed content, so the
            // comparison below could never once be true for one — the answer
            // was always "somebody edited this", always Overtaken, and
            // Overtaken drops the operation without touching the record that
            // asked for it. So the next pass planned the same download, and the
            // one after that. On the rig it was thirteen files at a time, on a
            // device with an empty queue, no error and no issue raised: the
            // bytes were already on the disk, byte for byte, and the engine
            // went on asking for them for the whole run.
            if here == arrived.plain.sha256 {
                let mut entry = entry.clone();
                entry.remote_content = Some(arrived.cipher.clone());
                entry.head_change_id = item
                    .get("head_change_id")
                    .and_then(Value::as_i64)
                    .unwrap_or(entry.head_change_id);
                entry.synced_remote_content = entry.is_encrypted.then(|| arrived.cipher.clone());
                agree(&mut entry, Some(arrived.plain), Some(fp));
                env.store.put_entry(&entry)?;
                env.store.cache_hash(
                    fp,
                    &here,
                    Some(op.entity),
                    (env.now_ms)().saturating_mul(1_000_000),
                )?;
                return Ok(OpOutcome::Done);
            }

            // Still the bytes both sides last agreed on, so the only thing that
            // moved is the fingerprint — an application that saves by writing a
            // temporary and renaming it over the original leaves the same
            // content behind a new inode and a new mtime. Record the new
            // fingerprint so the next attempt gets past the guard.
            let agreed = entry.synced_content.as_ref().map(|c| c.sha256.as_str());
            if agreed == Some(here.as_str()) {
                let mut entry = entry.clone();
                entry.synced_fingerprint = Some(fp);
                env.store.put_entry(&entry)?;
                return Ok(OpOutcome::Retry(
                    "the file was rewritten with the same content".into(),
                ));
            }

            // Neither what arrived nor what was agreed: a real edit nobody has
            // seen. Leave it for the scan, which meets it as a conflict.
            return Ok(OpOutcome::Overtaken(
                "the file changed here while it was downloading".into(),
            ));
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
    // A new version of a file the server has since deleted. The op was planned
    // when the file was there; somebody trashed it while this upload was still
    // retrying, and the answer is no longer a version of anything.
    //
    // Sending it anyway is worse than useless. The bytes land against a row in
    // the trash, which nothing lists — so on this device the file appears to
    // come back, and on the device that deleted it nothing arrives at all,
    // because it dropped the tombstone once both sides agreed. The two never
    // speak about that file again and both report themselves settled. Chaos is
    // what makes it reachable: the upload has to still be retrying when the
    // delete lands, which a healthy network never leaves time for.
    //
    // Dropped rather than refused, because there IS a right answer and this op
    // cannot carry it: an edit beats a delete, so the next plan rescues these
    // bytes as a new file where the old one lived. Only reconcile can decide
    // that, and it never gets the chance while this op keeps succeeding.
    if as_new.is_none() && entry.remote_deleted {
        return Ok(OpOutcome::Overtaken(
            "the server deleted it while this upload was in flight".into(),
        ));
    }
    // Where the bytes are on this computer.
    //
    // Three places are worth looking, and every one of them can be the stale
    // one, so this takes whichever actually has a file rather than trusting any
    // of them:
    //
    // - **The plan's placement**, when there is one. This is where the file
    //   really is in every case that produces one: a local creation names the
    //   path it was found at, and both rescue rules name where the user moved
    //   it to.
    // - **The agreed placement**, which is right for an ordinary upload and
    //   exactly wrong for a rescue — the rescue happens *because* the user
    //   moved the file, so the agreement points at the spot they moved it out
    //   of.
    // - **The server's placement**, for the mirror case: a rename that has
    //   already been applied here, where the agreement still names the old
    //   spelling and the file has been sitting under the new one all along.
    //
    // Looking in only one of them and giving up is not a slow path but an
    // endless one. Nothing is wrong, so nothing is reported; the operation is
    // called overtaken and dropped without touching the entry, and the next
    // pass plans the same upload against the same empty path. No error, no
    // queued work, no end — the client simply never goes quiet again.
    //
    // A placement another live entry already claims is skipped whatever it
    // holds. Those are that entry's bytes, and sending them up under this one's
    // identity would put a single file on the server twice — the expensive way
    // to be wrong, and invisible once done.
    //
    // Unless that entry is PARKED, which is the difference between recording a
    // placement and holding a file. An entry the device has told the user it
    // will not be materializing has no bytes at that path and never had; what
    // is sitting there belongs to whoever won the name. Counting its claim
    // anyway is a deadlock: this upload is skipped over every candidate, found
    // nowhere, called overtaken, and planned again on the very next pass, while
    // the parked entry waits for a change that a settled tree will never
    // produce. Neither one is failing, nothing is queued, no issue is raised,
    // and the device simply never goes quiet again.
    //
    // A vault is where this stops being hypothetical. The server enforces name
    // uniqueness on the stored title, and an encrypted file's title is an
    // opaque per-file identifier -- so two files in one vault folder whose REAL
    // names are both `slot-2.dat` are a state the server cannot even see, let
    // alone refuse. The client is the only thing that can tell, it parks one of
    // them, and before this the other could never upload again.
    //
    // A HELD source is the same distinction from the other side. Its bytes are
    // known to stand elsewhere -- inside a vault this device has no key for,
    // claimed there by an entry waiting for the key -- and its record keeps
    // the server's placement only so the copy the rest of the fleet can reach
    // is not trashed. Nothing of its is at that path. Counting its claim
    // vetoed every upload of a stranger the user saved under the old name:
    // minted by one scan, dropped here, minted again by the next, for ever.
    let mine = |p: &Placement| -> Result<bool, ExecError> {
        let all = env.store.every_entry()?;
        let held: std::collections::HashSet<EntityId> = all
            .iter()
            .filter(|c| c.id.is_provisional())
            .filter_map(|c| c.replaces)
            .collect();
        Ok(!all.into_iter().any(|e| {
            e.id != op.entity
                && !e.remote_deleted
                && e.id.entity_type == EntityType::File
                && e.holds_a_local_file()
                && !held.contains(&e.id)
                && e.local_placement() == p
        }))
    };
    let mut vetoed = false;
    let mut found = None;
    let candidates = [
        as_new.clone(),
        Some(entry.local_placement().clone()),
        Some(entry.remote.clone()),
    ];
    let mut unplaced = None;
    for candidate in candidates.iter().flatten() {
        if !mine(candidate)? {
            vetoed = true;
            continue;
        }
        match path_for(env, candidate)? {
            Placed::At(path) => {
                if let Some(fp) = env.vfs.fingerprint(&path)? {
                    found = Some((path, fp));
                    break;
                }
            }
            // Remembered rather than returned. An unplaceable candidate is not
            // the answer while another candidate may still have the file, and
            // the reason only matters if none of them do.
            Placed::Not(why) => unplaced = unplaced.or(Some(why)),
        }
    }
    let Some((mut path, fingerprint)) = found else {
        if let Some(why) = unplaced {
            return Ok(why.outcome());
        }
        // Every candidate was refused because another entry already holds the
        // name -- which means the file is still HERE. Deleting the identity now
        // would say the opposite, and the next scan would find the same
        // untracked file, mint another identity, and arrive back here, for ever.
        //
        // The entry is left alone instead, so that naming -- which is what
        // decides who gets a contested name -- sees it on the next pass and
        // parks the loser visibly. A provisional is minted AFTER naming has run
        // for its pass, so surviving one pass is the only way it can ever be
        // judged.
        //
        // Deliberately silent. Naming raises the `unsyncable` issue when it
        // parks, and that is the only issue kind a pass withdraws again once the
        // state ends. An issue raised from here would describe a state that
        // resolves a pass later and would then sit in the user's list for ever,
        // clearable only by hand -- exactly what the dismissal block at the top
        // of a pass exists to prevent.
        if vetoed {
            return Ok(OpOutcome::Overtaken(
                "another file already holds this name".into(),
            ));
        }
        // A provisional entry exists for exactly one reason: a file was found on
        // this disk that nothing was tracking. It has no server side, so nothing
        // will ever arrive to resolve it — and with no file of its own left
        // anywhere, there is nothing it can ever be about. Keeping it means
        // planning this upload again on every pass, forever, over a file that is
        // gone or was never ours.
        //
        // Letting it go is safe in the direction that matters: if a file for it
        // does turn up, the next scan finds it as something new and uploads it,
        // which costs a transfer and loses nothing.
        if op.entity.is_provisional() {
            env.store.delete_entry(op.entity)?;
            return Ok(OpOutcome::Overtaken(
                "nothing here to upload, and nothing on the server waiting for it".into(),
            ));
        }
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

    // Name and destination folder come from the same decision, because the name
    // was chosen for that folder and means nothing anywhere else. A rescue name
    // is picked to be free among the siblings the file is landing beside; using
    // it in a different folder asks the server for a name that folder may well
    // already have, and `name_taken` sends the whole thing back to the planner,
    // which re-derives the identical plan from an unchanged tree. That is a
    // client that never goes quiet again, with nothing queued and nothing
    // wrong — the sweep had it as a rescue aimed at a subfolder being posted to
    // the drive root, where a file of that name already sat.
    //
    // The entry answers instead when the plan named a folder that did not exist
    // on the server yet: the plan was written against a provisional id and the
    // folder has since acquired a real one, which the entry already knows.
    let planned_parent = as_new
        .as_ref()
        .map(|p| p.parent)
        .filter(|p| !p.is_some_and(|id| id < 0));
    let placement = Placement {
        name: as_new
            .as_ref()
            .map(|p| p.name.clone())
            .unwrap_or_else(|| entry.remote.name.clone()),
        parent: planned_parent.unwrap_or(entry.remote.parent),
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
                // The CIPHERTEXT's hash, not the plaintext's. See `Packed`.
                params.sha256 = packed.cipher_sha256.clone();
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

    // Land beside an occupant rather than ask for its name for ever.
    //
    // The same refusal a folder create can meet, for the same reason and with
    // the same answer: the rival is a live file on the server this device has
    // never been told about, so nothing in the store can see it and no amount
    // of re-planning learns anything new. Read strictly, the refusal leaves the
    // entry `pending_upload`, the operation is minted and dropped on every pass,
    // and the device never goes quiet -- with the tree correct and the audit
    // green throughout, because only the bookkeeping is stuck. Soak rig run 302
    // is exactly that, against the real platform.
    //
    // The server settles on the conflict name and this disk keeps the original,
    // which is what the folder path does. That asymmetry is transient: when the
    // occupant syncs down, the ordinary conflict-copy machinery moves the local
    // file aside, and both sides end where the tracked same-name race already
    // ends.
    //
    // Encrypted files never reach this. Their server name is `enc-{content id}`,
    // which nothing else can be holding.
    let mut attempt = 0u32;
    let outcome = loop {
        let sent = match &mut ciphertext {
            Some(reader) => {
                let mut source = Source(&mut **reader);
                env.api.upload(&params, &mut source)
            }
            None => {
                let mut reader = env.vfs.open_read(&path)?;
                let mut source = Source(&mut *reader);
                env.api.upload(&params, &mut source)
            }
        };
        match sent {
            Ok(out) => break out,
            // A name this device can account for is not a name to step around:
            // the holder is on its way somewhere else and the refusal lifts by
            // itself. Renaming around it would hand the user a conflict name,
            // on every device, permanently, for a collision that was about to
            // clear.
            //
            // Gated on `may_be_about_the_name`, so a server answering in prose
            // alone still reaches it. The evidence here is entirely LOCAL -- a
            // tracked live sibling under that name with a server-side rename
            // this device still owes -- so it holds whatever the refusal turns
            // out to have been about, and it clears itself: once the owed
            // rename is Done, Withdrawn or Overtaken it leaves the queue, the
            // predicate goes false, and the next attempt meets the real
            // refusal.
            Err(e)
                if e.may_be_about_the_name()
                    && held_by_a_rename_this_device_owes(env, &params.name, params.folder_id)? =>
            {
                return Ok(OpOutcome::Retry(
                    "the name is spoken for by something this device is renaming".into(),
                ));
            }
            // The server already holds OUR bytes under that name. This is not a
            // contested name at all: it is a lost record, and the repair is to
            // take the server's entity as ours rather than mint a conflict copy
            // of a file the user never conflicted over.
            //
            // Soak rig run 302 is this case and only this case — seven entries
            // stuck `pending_upload` while `audited-green` passed, because the
            // disk and the server already agreed and only the bookkeeping had
            // been lost. Landing beside there would have manufactured seven
            // duplicates on the rig's one confirmed real-platform failure.
            //
            // Keyed on hash equality, never on name equality, and only when the
            // holder is a FILE — a folder holding the name is a kind mismatch
            // and adoption means nothing. A holder with no hash is no evidence
            // and must not compare equal to anything.
            //
            // Two byte-identical files at one placement are the same file for
            // every purpose a user has. If the identities really did differ they
            // diverge later and the ordinary conflict machinery catches it then,
            // so adopting cannot lose data — while landing beside on identical
            // content manufactures a visible duplicate out of nothing.
            Err(ref e)
                if e.name_taken()
                    && e.name_holder().is_some_and(|h| {
                        h.entity_type == "file"
                            && h.sha256.as_deref() == Some(params.sha256.as_str())
                    }) =>
            {
                let holder = e.name_holder().expect("just matched");
                let target = EntityId {
                    entity_type: EntityType::File,
                    server_id: holder.id,
                };
                if env.store.get_entry(target)?.is_some() {
                    // Both records exist here; folding them is the whole repair.
                    env.store.merge_file(entry.id, target)?;
                    return Ok(OpOutcome::Done);
                }
                let mut adopted = entry.clone();
                env.store.rekey_entry(entry.id, target)?;
                adopted.id = target;
                // The server's exact spelling, which an insensitive match can
                // make different from the name we asked for.
                adopted.remote = Placement {
                    parent: placement.parent,
                    name: holder.name.clone(),
                };
                adopted.synced_placement = Some(adopted.remote.clone());
                adopted.synced_content = Some(ContentId {
                    sha256: params.sha256.clone(),
                    size: fingerprint.size,
                });
                adopted.synced_fingerprint = Some(fingerprint);
                adopted.status = LocalStatus::Synced;
                // The disk has to follow here as well, and the crash window is
                // why. An upload that lands beside under a conflict name and
                // dies before its rename comes back through recovery: the
                // original name is still refused, the same conflict name is
                // minted again (the generator is deterministic and the counter
                // restarts), and it is now held by our OWN crashed upload — so
                // the hashes match and adoption fires. Returning here without
                // the rename would leave the record on the conflict name and
                // the disk on the original: the very gap this change exists to
                // close, reproduced by its own recovery.
                let settled_at = follow_the_server_name(
                    env,
                    adopted.id,
                    &path,
                    &adopted.remote,
                    &placement.name,
                    &params.sha256,
                )?;
                // Deliberately NOT re-fingerprinted here. A rename carries size,
                // mtime and file id across unchanged, so a re-read can only
                // return the fingerprint already recorded above — or, if the
                // user edited the file while the upload was in flight, the
                // POST-EDIT one. Recording that would be the worst outcome
                // available: adoption matched on the old hash, so the agreed
                // content is the old bytes while the agreed fingerprint is the
                // new ones, and the next scan sees a fingerprint that matches
                // the disk and skips hashing it. The edit would never be sent,
                // and both sides would report themselves settled while the
                // contents differed.
                //
                // A fingerprint is only ever permission to SKIP a hash. Leaving
                // the pre-upload one is the safe direction: it stops matching
                // the moment the file changes, the next scan re-hashes, and the
                // edit goes up.
                let _ = settled_at;
                env.store.put_entry(&adopted)?;
                return Ok(OpOutcome::Done);
            }
            Err(e) if e.name_taken() && !entry.is_encrypted && attempt < 1000 => {
                attempt += 1;
                params.name = (env.conflict_name)(&placement.name, attempt);
                // A fresh key per name, for the reason the folder create gives:
                // a key is a promise that the request behind it does not change.
                params.idempotency_key =
                    Some(format!("{}-n{attempt}", op.idempotency_key));
            }
            // A refusal that would not say why. Read strictly this arm does not
            // exist, and a server answering in prose alone leaves the upload
            // undone and the identical one planned again on every pass -- no
            // growing queue and no issue, only a device that never goes quiet.
            // Sweep seed 93128 is that; `move_remote` was widened for its twin,
            // seed 90664, and this path was left behind.
            //
            // Capped hard, and far below the marked cap, for the reason the
            // folder create gives: a refusal that really is about something
            // else would otherwise cost a thousand attempts a pass against a
            // server that was never going to take any of them -- and here each
            // attempt can be a full re-upload of the body.
            //
            // What this arm cannot do is tell a taken name from OUR OWN BYTES
            // already on the server under it. The marked path adopts those
            // (above), but adoption needs the holder the marker carries, so
            // under a silent server it is unreachable and this arm lands beside
            // instead -- leaving the user two identical copies. Both sides
            // converge and nothing is lost, which is why it is preferred to the
            // forever-loop, but it is a real cost and the cap of two is what
            // bounds it.
            Err(e) if e.refused_without_saying_why() && !entry.is_encrypted && attempt < 2 => {
                attempt += 1;
                params.name = (env.conflict_name)(&placement.name, attempt);
                params.idempotency_key =
                    Some(format!("{}-n{attempt}", op.idempotency_key));
            }
            Err(e) => return Err(e.into()),
        }
    };
    drop(ciphertext);

    // The disk has to follow the server across a land-beside.
    //
    // Left alone, the local file keeps the name the occupant now holds while the
    // entry claims the conflict name the server settled on — and the next scan
    // reads that gap as a user rename and pushes `move_remote` onto the
    // occupant's name, refused, dropped, re-derived, for ever. A second loop
    // exactly like the one this whole branch exists to end.
    //
    // The occupant syncing down would move the local file aside by itself, and
    // where that happens this rename is a no-op the machinery repeats
    // harmlessly. But the case that produced the defect is the one where it
    // never does: the device's record of the occupant is gone and its cursor is
    // past it, so nothing brings the rival down and nothing displaces our copy.
    // Doing it here does not depend on the rival ever arriving.
    if attempt > 0 {
        let kept = Placement {
            parent: placement.parent,
            name: params.name.clone(),
        };
        path = follow_the_server_name(
            env,
            entry.id,
            &path,
            &kept,
            &placement.name,
            &params.sha256,
        )?;
    }

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
        // The same collision a folder create can hit, for the same reason: the
        // upload landed, the answer was lost, and an index walk has since taken
        // the file up under its real id. The bytes are on the server either
        // way, so this is done — what is left is two records of one file, and
        // folding them is the whole repair.
        if env.store.get_entry(target)?.is_some() {
            env.store.merge_file(entry.id, target)?;
            return Ok(OpOutcome::Done);
        }
        env.store.rekey_entry(entry.id, target)?;
        entry.id = target;
    }
    // No replayed-snapshot guard here, unlike a create or a move, and the
    // reason is in the upload protocol itself: the completion's idempotency key
    // carries the token a FRESH init produced, so a retry never replays an
    // older answer. What stops a lost completion from making a second copy is
    // dedup at init, which short-circuits to the file the server already has
    // and describes it as of now.
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
            // The file moved on while its bytes were going up. What the server
            // took is still a real version of it, and this device is what sent
            // it, so there IS an agreement to record — about the content, and
            // emphatically not about the file now on the disk.
            //
            // Leaving the fingerprint off is what keeps those two apart. The
            // fingerprint is only ever a licence to skip reading the file, so
            // an entry without one is re-hashed by the next scan, which finds
            // bytes that differ from the agreed hash and sends them. Nothing
            // newer is lost by calling this agreed.
            //
            // Recording nothing at all was the alternative, and it is how an
            // entry goes invisible. `known_local` offers the scanner only
            // entries with an agreed placement, so one without a placement has
            // no path to be looked for at — and the file sitting on the disk
            // then belongs to nothing the engine knows. Every pass reads it as
            // a brand-new file, mints an identity, uploads it, and folds the
            // result back into this entry, which is never repaired, so the
            // pass after does the same. The rig had it as a device that stayed
            // busy for a whole run against a tree that already matched the
            // server: nothing queued, nothing wrong, never quiet.
            agree(&mut entry, Some(content), None);
            entry.synced_fingerprint = None;
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
    /// The hash of the ciphertext, which is what the upload declares and what
    /// the server checks the assembled bytes against.
    ///
    /// The PLAINTEXT hash is never sent and never could be: it would tell the
    /// server what it is holding, and it is what the dedup short-circuit
    /// matches on, so sending it would hand somebody else's file back as this
    /// one. The ciphertext hash gives away nothing — the server has those exact
    /// bytes — and cannot collide with anything, because every encryption uses
    /// fresh IVs. Without it an encrypted upload has no integrity check at all,
    /// and a chunk corrupted on the way up becomes a file that no device can
    /// ever open, reported for ever as "decryption failed".
    cipher_sha256: String,
    content_id: String,
    metadata: String,
    wrapped_file_keys: Vec<(i64, String)>,
}

/// A writer that hashes what passes through it on the way to another writer.
struct Tallied<W: Write> {
    inner: W,
    hasher: Sha256,
}

impl<W: Write> Write for Tallied<W> {
    fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
        self.hasher.update(buf);
        self.inner.write_all(buf)?;
        Ok(buf.len())
    }
    fn flush(&mut self) -> std::io::Result<()> {
        self.inner.flush()
    }
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
    // Hashed on the way past rather than by reading the scratch back: these are
    // the exact bytes the upload will send, and re-reading them to hash would
    // be a second chance for the two to disagree.
    let cipher_sha256 = {
        let tallied = Tallied {
            inner: &mut *scratch,
            hasher: Sha256::new(),
        };
        let mut encryptor =
            jd_crypto::drive::ContentEncryptor::new(tallied, &file_key, &content_id);
        std::io::copy(&mut *reader, &mut encryptor)?;
        hex(&encryptor.finish()?.hasher.finalize())
    };
    drop(reader);

    Ok(Ok(Packed {
        bytes: scratch.finish()?,
        cipher_sha256,
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
        replaces: None,
        stand_in: None,
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

/// Is that name held by something this batch is already about to move off it?
///
/// The refusal itself cannot say. `name_taken` means the server has something
/// there, and the useful question is whether this device already knows what and
/// has decided it is leaving.
///
/// It nearly always does, when it does at all. Folders are created before
/// anything is moved -- a child cannot be created before its parent exists --
/// so a batch that both renames a folder and builds a new one under its old
/// name attempts them in exactly the order that collides. The whole plan is
/// journaled before any of it runs, so the rename is sitting in the queue at
/// the moment the create is refused.
///
/// Waiting costs one pass. Stepping around it costs the user a conflict name,
/// on every device, permanently, for a collision that was about to resolve
/// itself.
fn held_by_a_rename_this_device_owes(
    env: &ExecEnv,
    name: &str,
    parent: Option<i64>,
) -> Result<bool, ExecError> {
    let holders: Vec<EntityId> = env
        .store
        .children_of(parent)?
        .into_iter()
        .filter(|e| e.remote.name == name && !e.remote_deleted)
        .map(|e| e.id)
        .collect();
    if holders.is_empty() {
        return Ok(false);
    }
    // Only the operations that change a name ON THE SERVER. A local move
    // rearranges this disk and leaves the server calling it exactly what it
    // calls it now, so waiting for one would be waiting for nothing.
    Ok(env.store.queued_ops()?.iter().any(|op| {
        holders.contains(&op.entity) && matches!(op.kind.as_str(), "move_remote" | "park_remote")
    }))
}

/// A provisional folder has just taken its real id: point every queued
/// operation that named the provisional id as a parent at the real one.
///
/// A move into a folder created moments earlier in the same round carries the
/// provisional id in its journaled destination, and the executor cannot send
/// that. Dropped and re-planned a pass later it would usually be harmless,
/// except that the round goes on: a delete of the folder the thing is leaving
/// runs after the moves, the server's cascade takes the thing with it, and a
/// folder the user merely renamed alongside its parent is trashed and minted
/// again with its grants gone. Redirected here, the move runs in its turn.
///
/// Safe under the idempotency rule -- a key promises the request behind it
/// does not change -- because an operation naming a provisional parent has
/// never been sent: it is turned back before the call.
pub(crate) fn redirect_queued_parent(env: &ExecEnv, old: i64, new: i64) -> Result<(), ExecError> {
    for op in env.store.queued_ops()? {
        let Ok(mut params) = serde_json::from_str::<Value>(&op.params) else {
            continue;
        };
        let mut changed = false;
        if params.get("parent").and_then(Value::as_i64) == Some(old) {
            params["parent"] = json!(new);
            changed = true;
        }
        if params
            .get("from")
            .and_then(|f| f.get("parent"))
            .and_then(Value::as_i64)
            == Some(old)
        {
            params["from"]["parent"] = json!(new);
            changed = true;
        }
        if changed {
            env.store.set_op_params(op.op_id, &params.to_string())?;
        }
    }
    Ok(())
}

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
    // The name the plan chose may already be spoken for, by a folder belonging
    // to a device this one has never met -- so nothing in this store can see
    // it, and the refusal is the only way to find out.
    //
    // Which makes the refusal an answer rather than a dead end. Dropping the op
    // and re-deciding re-decides the same thing: reconcile hands back the last
    // place the folder was known to live, every pass, and nothing it can see
    // has changed. Three operations overtaken per pass, forever, no error
    // raised and nothing queued -- a device that never goes quiet against a
    // server that said exactly what was wrong. Landing beside the occupant is
    // what the local side does when two things want one name, and it is the
    // same answer here: both survive, one wears a conflict name.
    //
    // Only ever in answer to a refusal, never ahead of one. Asking for a
    // different name up front -- because this store happens to hold an entry
    // under that name -- breaks the case this operation most needs to survive:
    // a create that landed while the answer was lost. The retry carries the
    // same key, the server replays the same folder, and the two records are
    // folded together below, but only if the retry asks for the SAME name.
    // Renaming first makes it a different request, and there are then
    // genuinely two folders with nothing to fold.
    let mut attempt = 0u32;
    let mut wanted = placement.name.clone();
    let out = loop {
        let mut body = json!({ "name": wanted });
        if let Some(parent) = placement.parent {
            body["parent_id"] = json!(parent);
        }
        // A fresh key per name. The key is a promise that the request behind it
        // does not change, and asking for a different name is a different
        // request -- under the original key the server would refuse every
        // attempt after the first, identically and forever.
        let key = match attempt {
            0 => op.idempotency_key.clone(),
            n => format!("{}-n{n}", op.idempotency_key),
        };
        match env.api.action_idempotent("drive_folder_create", body, &key) {
            Ok(out) => break out,
            // A name this device can account for is not a name to step around.
            // The whole case for landing beside an occupant is that nothing in
            // this store can see it, so no amount of re-planning learns
            // anything new. When the holder IS tracked here and is on its way
            // to another name, the refusal is temporary and the answer is to
            // wait: renaming around it would give the user a conflict name for
            // a collision that resolves itself moments later, permanently, on
            // every device.
            Err(e)
                if e.name_taken()
                    && held_by_a_rename_this_device_owes(env, &wanted, placement.parent)? =>
            {
                return Ok(OpOutcome::Retry(
                    "the name is spoken for by something this device is renaming".into(),
                ));
            }
            Err(e) if e.name_taken() && attempt < 1000 => {
                attempt += 1;
                wanted = (env.conflict_name)(&placement.name, attempt);
            }
            // A refusal that would not say why. It may be the name; nothing
            // here can tell. Stepping aside is the one answer that costs
            // nothing when it is right and one call when it is wrong -- and
            // read strictly this arm does not exist, so a server answering in
            // prose alone leaves the folder uncreated and the same create
            // planned again on every pass.
            //
            // Capped hard, and far below the marked cap. A refusal that really
            // is about something else would otherwise mint a thousand conflict
            // names against a server that was never going to take any of them.
            Err(e) if e.refused_without_saying_why() && attempt < 2 => {
                attempt += 1;
                wanted = (env.conflict_name)(&placement.name, attempt);
            }
            Err(e) => return Err(e.into()),
        }
    };
    let planned_name = placement.name.clone();
    let placement = Placement {
        name: wanted,
        parent: placement.parent,
    };
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
    // The folder landed under a name other than the one the directory here
    // wears. The disk follows, as it does for a file that lands beside an
    // occupant: left under the planned name, the record says one thing and
    // the directory another, the next pass reads the folder as gone from its
    // path and trashes it, adopts the directory as new, and lands it beside
    // again -- a folder minted on the server every pass, for ever.
    if placement.name != planned_name {
        if let (Placed::At(from), Placed::At(to)) =
            (local_path(env, &entry)?, path_for(env, &placement)?)
        {
            if from != to && env.vfs.read_dir(&from).is_ok() {
                make_room(env, &to, None)?;
                env.vfs.rename(&from, &to)?;
            }
        }
    }
    let target = EntityId::folder(new_id);
    if target != entry.id {
        // The id may already be in this store, and then re-keying onto it is
        // not a rename but a collision: two rows claiming one identity, which
        // the database refuses outright. It happens when the create landed and
        // the answer did not arrive — the retry replays the same answer, and by
        // then an index walk has picked the folder up under its real id.
        //
        // The repair that folds a stray provisional into its real twin matches
        // on name and parent, and cannot help here: whoever moved the folder in
        // the meantime is exactly why the two records no longer look alike. So
        // this settles it with the one fact that repair does not have — the
        // server has just said, by id, that these are the same folder.
        //
        // Left alone it is a store error on every attempt forever, and it takes
        // the whole subtree with it: everything queued underneath waits on a
        // parent that can never be created. The sweep found it at 347 attempts
        // with four uploads and a folder stacked up behind it.
        if env.store.get_entry(target)?.is_some() {
            env.store.merge_folder(entry.id, target)?;
            redirect_queued_parent(env, entry.id.server_id, new_id)?;
            return Ok(OpOutcome::Done);
        }
        env.store.rekey_entry(entry.id, target)?;
        redirect_queued_parent(env, entry.id.server_id, new_id)?;
        entry.id = target;
    }
    entry.remote = placement;
    // On a RETRY the answer cannot be taken at face value. An idempotent replay
    // returns what the first attempt produced — a snapshot of a moment that has
    // passed — so a create whose answer was lost comes back describing a folder
    // another device has since deleted. Recording that as a fresh agreement
    // makes this device the only one that believes the folder exists, and
    // because it believes it AGREES with the server, nothing it ever does will
    // disagree: the empty directory sits there for good, on one device, with
    // every invariant but the tree itself satisfied. A long hostile sweep found
    // it twice, and it is invisible to a healthy network, which never leaves an
    // answer in flight long enough for a delete to overtake it.
    //
    // Only retries pay for this. A first attempt's answer describes the folder
    // the server made a moment ago and is as fresh as anything can be.
    match server_view_after_retry(env, op, entry.id)? {
        Some(state) if state.deleted => {
            entry.remote_deleted = true;
            env.store.put_entry(&entry)?;
            return Ok(OpOutcome::Done);
        }
        Some(state) => entry.remote = state.placement,
        None => {}
    }
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
    // A file holding the name this folder needs is moved aside, exactly as one
    // holding a file's name is -- `make_room` is the same answer to the same
    // question, and it keeps the user's copy under a conflict name rather than
    // destroying it. Only a FILE: a directory already here IS this folder, and
    // moving that aside would rename the tree out from under itself every pass.
    if env.vfs.fingerprint(&path)?.is_some() {
        make_room(env, &path, None)?;
    }
    // A directory has been standing in for this folder while the device had
    // no key (`Entry::stand_in`). It is this folder, and what the user saved
    // under it is this folder's: a second directory made beside it would
    // leave those files in a plain folder of the old name. So the stand-in
    // becomes the folder, renamed to where the server has it when the two
    // still differ -- the pass keeps them the same, and they differ here only
    // while something else stands at the server's path.
    if let Some(entry) = env.store.get_entry(op.entity)? {
        if entry.stand_in.is_some() && entry.synced_placement.is_none() {
            if let Placed::At(from) = local_path(env, &entry)? {
                if from != path && env.vfs.read_dir(&from).is_ok() {
                    // A respelling of the same slot -- a case-only rename on
                    // a folding disk -- finds the stand-in itself at the
                    // destination, which is not something in the way.
                    let respell = same_slot(
                        &from.to_string_lossy(),
                        &path.to_string_lossy(),
                        &env.vfs.personality(),
                    );
                    if !respell && env.vfs.read_dir(&path).is_ok() {
                        return Ok(OpOutcome::Retry(format!(
                            "{} stands where the vault's directory {} must go",
                            path.display(),
                            from.display(),
                        )));
                    }
                    env.vfs.rename(&from, &path)?;
                }
            }
        }
    }
    // A directory standing here that another folder still holds is not this
    // one's to adopt. `create_dir` below is content to find a directory
    // already at the path, and for a stand-in, or a re-run of this very op,
    // that is exactly right. It is wrong when the directory belongs to a
    // DIFFERENT tracked folder whose own move has not run yet: the server
    // renamed that folder and gave its old name to a new one, and the new one
    // reaches the disk first. Adopting agrees this entry lives in the other's
    // directory; the other's rename then carries that directory away, this
    // entry reads as locally deleted, and the folder the user made is trashed
    // on the server -- with the two entries agreeing on one directory in the
    // meantime.
    //
    // What kept the create from being planned at all used to be a naming
    // verdict: the newcomer lost the name as a duplicate and was parked. That
    // verdict is gone deliberately -- it deadlocked against the leaver's own
    // move -- so the invariant it was carrying by accident is stated here
    // instead, in the layer that owns the question of whether a directory is
    // this folder's. The file case one block up is the same question in the
    // other voice: a FILE in the way is moved aside because nothing else is
    // coming for it; a tracked FOLDER in the way is waited for, because
    // something is. Estate seed 22081285, reviewer probe p1b.
    if env.vfs.read_dir(&path).is_ok() {
        for other in env.store.every_entry()? {
            if other.id == op.entity
                || other.id.entity_type != EntityType::Folder
                || other.remote_deleted
                || !other.holds_a_local_file()
            {
                continue;
            }
            if let Ok(Placed::At(theirs)) = local_path(env, &other) {
                if theirs == path {
                    return Ok(OpOutcome::Retry(format!(
                        "{} is still held by another folder; waiting for it to move",
                        path.display(),
                    )));
                }
            }
        }
    }
    match env.vfs.create_dir(&path) {
        // A folder that was already there is `Ok`: the call underneath is
        // `create_dir_all`, which is content to find its work done. Creating a
        // folder is the one operation where "it was already done" and "I did
        // it" are the same result, which is why it needs no key.
        Ok(()) => {}
        // Room was just made at this path, so a refusal now is about one of the
        // folders ABOVE it -- a file stands where a parent should be. That
        // parent has an operation of its own and folders are created
        // shallowest-first, so this clears once that one runs: waiting is
        // right, and waiting quietly is not. Reading it as "already there is
        // what we wanted" recorded this folder as synced with a file in its
        // place, and every child that tried to land inside it was then refused
        // by a disk that puts nothing beneath a file.
        Err(jd_vfs::VfsError::AlreadyExists(blocker)) => {
            return Ok(OpOutcome::Retry(format!(
                "{} cannot be created while {} is a file",
                path.display(),
                blocker.display(),
            )));
        }
        Err(e) => return Err(e.into()),
    }
    // Look before recording agreement. `create_dir` answering Ok and a directory
    // actually standing at that path are two different claims, and the rig has
    // ended runs with folders recorded `synced`, under the server's exact name,
    // with nothing on the disk: reconcile then plans nothing forever and every
    // server-side file underneath is unreachable, while convergence passes and
    // the device reports itself quiet.
    //
    // Whether the directory is never made or made and then taken away is not
    // settled -- this guard tells the two apart, because it fires only in the
    // first case. Retrying is bounded and costs a syscall; recording agreement
    // on a directory that is not there is neither bounded nor visible.
    if let Err(why) = env.vfs.read_dir(&path) {
        return Ok(OpOutcome::Retry(format!(
            "{} was created but is not there: {why}",
            path.display()
        )));
    }
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    // Where the SERVER has it is not this operation's to say, and it used to
    // say it anyway. The placement here is the one the plan chose, which can be
    // older than what the device already knows: the feed is read at the top of
    // a pass and the queue is run at the bottom, so an op journaled by a pass
    // that died carries a name the next pass has already been told is wrong.
    //
    // Writing it over `remote` destroyed the only copy of the newer answer, and
    // then agreed with it. Both sides looked settled, nothing was queued and
    // nothing was planned, and the change that would have said otherwise was
    // long past the cursor -- a folder left under a name the rest of the fleet
    // had stopped using, for good.
    //
    // The agreement describes what is on this disk, which is the folder just
    // created under the planned name. If the server has moved on, that now
    // disagrees with `remote`, and disagreeing is precisely what gets the move
    // planned on the next pass.
    agree(&mut entry, None, None);
    entry.synced_placement = Some(placement);
    entry.stand_in = None;
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
    // Our own half-finished dance is not somebody else's move.
    //
    // The park below renames the entity to `.jd-swap-{this op's key}` as a step
    // inside this very operation. If the process dies between that step and the
    // ones after it, the next pass's index walk records the scratch name as
    // `entry.remote` — correctly, because that IS where the server has it. The
    // recovered op then arrives here, the guard sees `entry.remote` differing
    // from `from`, and drops the operation as overtaken. The park stands, the
    // naming pass reads the reserved prefix, and the entry self-locks
    // `Unsyncable(ReservedPrefix)` — skipped by every later pass, with the
    // user's file on the server under a name nobody chose and its real name
    // gone from the record too.
    //
    // The scratch name carries the key of the op that minted it, so the op can
    // recognise its own work and finish it. Checked before the guard, because
    // the guard is what destroys it.
    //
    // The planner's park is the same step taken early: `journal` names a
    // cycle-breaking park after the key of the move that finishes it, so the
    // finisher arrives here looking at a name that is its own -- whether the
    // park ran a moment ago or a pass boundary lies between them.
    //
    // And the same for a move HALF done. Rename and reparent are two calls,
    // and this op can die, or lose its answer, between them: the server then
    // holds the entity under the new name in the old folder, or under the old
    // name in the new one. Both are this op's own work, one call from
    // finished. Read as somebody else's move it stood down, the next pass
    // re-derived the move from the disk against a record whose agreement was
    // now a pass stale, and naming disowned the record for a name it no longer
    // held: the same bytes uploaded again beside the half-moved copy.
    // Pinned by `a_half_applied_move_is_finished_as_ours_after_a_kill`.
    //
    // Only on a retry, and only HALF done. A first attempt has done nothing
    // yet, so a half-shape it meets is somebody else's move that merely looks
    // like one -- a peer moving the file into the same folder under its old
    // name -- and that is the overtaken case below, exactly as before. And a
    // move the server has already completed (a lost answer to a move that
    // changed only the folder, say, where the half IS the whole) has nothing
    // left to finish: proceeding would write the agreement here, blind to
    // what stands at the new path on this disk, which the ordinary path
    // looks at before it agrees to anything.
    let ours_to_finish = entry.remote.name == crate::order::swap_name(&op.idempotency_key)
        || (op.attempts > 0
            && entry.remote != to
            && from.as_ref().is_some_and(|f| {
                entry.remote == Placement { parent: f.parent, name: to.name.clone() }
                    || entry.remote == Placement { parent: to.parent, name: f.name.clone() }
            }));
    if !ours_to_finish && from.is_some_and(|f| entry.remote != f) {
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
    let reparent = || -> Result<(), ExecError> {
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
        Ok(())
    };
    // An encrypted FILE has no plaintext name to send. Its name lives inside
    // the metadata blob, so renaming it means decrypting that blob, changing
    // one field, and encrypting it again under the same file key — the server
    // stores the result without ever learning either name. Sending the name
    // itself would hand over the one thing the whole arrangement exists to
    // keep, and the server refuses it outright.
    //
    // Folders are not encrypted this way: a vault folder's own name is
    // plaintext on the server, and only its contents are private.
    //
    // Sealed once, and remembered. The blob carries a fresh random nonce every
    // time it is built, so resealing on each attempt would send a different
    // request under one idempotency key -- which the server refuses, and which
    // is refused identically every time after, so the op retries for as long as
    // the computer is on. Any transport hiccup during a vault rename was enough
    // to trigger it.
    let rename_body = if entry.is_encrypted && op.entity.entity_type == EntityType::File {
        let stored: Option<String> = serde_json::from_str::<Value>(&op.params)
            .ok()
            .and_then(|p| p.get("sealed_name").and_then(Value::as_str).map(str::to_owned));
        let blob = match stored {
            Some(blob) => blob,
            None => match reseal_metadata(env, &entry, &to.name)? {
                Ok(blob) => {
                    let mut params: Value =
                        serde_json::from_str(&op.params).unwrap_or_else(|_| json!({}));
                    params["sealed_name"] = json!(blob);
                    env.store.set_op_params(op.op_id, &params.to_string())?;
                    blob
                }
                Err(why) => return Ok(why),
            },
        };
        json!({
            "entity_type": t,
            "entity_id": op.entity.server_id,
            "encrypted_metadata": blob,
        })
    } else {
        json!({
            "entity_type": t,
            "entity_id": op.entity.server_id,
            "name": to.name,
        })
    };
    let rename = || -> Result<(), ExecError> {
        env.api.action_idempotent(
            "drive_rename",
            rename_body.clone(),
            &format!("{}-rename", op.idempotency_key),
        )?;
        Ok(())
    };
    // The way through when both intermediate states are occupied: step aside
    // into a name nothing can be using. `.jd-` is refused for real files, so a
    // swap name cannot collide with a user's file even deliberately, and it is
    // the same device the rename planner uses to break a cycle of renames.
    //
    // An encrypted file is left out. Its name lives inside a sealed blob, and
    // parking it would mean two extra reseals to hide a name the server never
    // learns anyway — so it takes the ordinary two orders and, if both are
    // refused, goes back to the planner.
    let park = || -> Result<(), ExecError> {
        if entry.is_encrypted && op.entity.entity_type == EntityType::File {
            return Err(ExecError::Contract(
                "an encrypted file cannot be parked under a scratch name".into(),
            ));
        }
        let scratch = crate::order::swap_name(&op.idempotency_key);
        // Already standing aside under this op's own name: the planner's park,
        // being finished now. Asking the server for the name it has is noise.
        if entry.remote.name == scratch {
            return Ok(());
        }
        env.api.action_idempotent(
            "drive_rename",
            json!({
                "entity_type": t,
                "entity_id": op.entity.server_id,
                "name": scratch,
            }),
            &format!("{}-park", op.idempotency_key),
        )?;
        Ok(())
    };

    let reparenting = entry.remote.parent != to.parent;
    let renaming = entry.remote.name != to.name;
    match (reparenting, renaming) {
        // Both. Two calls means one intermediate state, and there is no order
        // that cannot land in an occupied one: move first and the OLD name
        // arrives among the destination's siblings; rename first and the NEW
        // name arrives among the old neighbours. Neither can be assumed free —
        // a contested name is usually the whole reason for the rename.
        //
        // So it takes the order that puts the chosen name in the place it was
        // chosen for. A conflict copy's name is picked from what the
        // destination already holds, which says nothing about the folder it is
        // leaving, so renaming first is the half that was actually checked. If
        // the old neighbours refuse it, the other order gets its turn.
        //
        // Moving first unconditionally is what this used to do, and the server
        // that let it — the mock, not the real one — hid it completely. Against
        // a server that refuses, every combined move-and-rename out of a folder
        // whose name the destination also used stalled: fifteen seeds of a
        // fifteen-hundred-seed sweep, all of them this.
        //
        // Both can be occupied at once, and then neither order works while the
        // destination the file is actually going to sits free. Stepping aside
        // into a scratch name first costs one extra call and always works, so it
        // is the last resort rather than the rule.
        //
        // Gated on `may_be_about_the_name` rather than `name_taken`, so a
        // server that refuses in prose alone still reaches all three orders.
        // Read strictly, a refusal with no marker skips every branch here and
        // falls out to the caller, which drops the operation and leaves the
        // record exactly as it was -- and the next pass derives the identical
        // move from the same disk, for ever. Sweep seed 90664 is that, and the
        // soak rig ran into the same thing against a real core whose
        // folder-rename branches sent no marker. Each order below is verified
        // by the server taking or refusing its own call, so trying them on a
        // refusal that was about something else costs three calls and ends in
        // the same place.
        (true, true) => match rename() {
            Ok(()) => reparent()?,
            Err(ExecError::Proto(p)) if p.may_be_about_the_name() => match reparent() {
                Ok(()) => rename()?,
                Err(ExecError::Proto(p)) if p.may_be_about_the_name() => {
                    park()?;
                    reparent()?;
                    rename()?;
                }
                Err(e) => return Err(e),
            },
            Err(e) => return Err(e),
        },
        (true, false) => reparent()?,
        (false, true) => rename()?,
        (false, false) => {}
    }

    // Where it ACTUALLY is, when this op has been tried before — the calls above
    // may have been idempotent replays describing a journey the server has
    // since taken further. See server_view_after_retry.
    match server_view_after_retry(env, op, entry.id)? {
        Some(state) if state.deleted => {
            entry.remote_deleted = true;
            env.store.put_entry(&entry)?;
            return Ok(OpOutcome::Done);
        }
        // The answer comes back in the SERVER's language, and for an encrypted
        // file that language has no name in it: the stored title is
        // `enc-{content id}` for the life of the file, and the real name lives
        // sealed in the metadata blob beside it. Recording the answer whole
        // therefore writes the placeholder into the agreement -- and
        // `local_placement` prefers the agreement over everything else, so from
        // that moment the user's file IS called `enc-...`. The next download
        // lands under that name, the scan meets a file no entry knows, and the
        // engine offers it back to the server as a brand new file whose real
        // name is another file's placeholder: a name the vault exists to keep
        // secret, stored in the clear.
        //
        // The blob rides along with the stat, so opening it here recovers the
        // name exactly as the change feed does. The upload path already states
        // this rule; this is the other place that adopts a server view, and it
        // has to obey it too.
        Some(state) => {
            // The blob rides along with the stat, but opening it needs the
            // vault -- and the vault can be locked by the time a queued op is
            // retried, because the skip that gates PLANNING does not gate the
            // queue. Adopting anyway would put the placeholder into the
            // agreement exactly as before, and nothing re-stats an entry that
            // reads as settled, so it would never repair itself.
            //
            // So it waits, which is what every other wait in this engine does.
            // Scoped to files: a folder inside a vault wears its real name on
            // the server and has no blob to open, so asking it to produce one
            // would be a wait with nothing to wait for.
            entry.remote = state.placement.clone();
            let opened = crate::pass::open_metadata(env, &mut entry, &state);
            if entry.id.entity_type == EntityType::File && state.is_encrypted && !opened {
                // Nothing has been written down: `entry` is this call's own
                // copy and the store is only touched below.
                return Ok(OpOutcome::Retry(
                    "the vault is not open, so the server's answer cannot be read \
                     back into the file's real name"
                        .into(),
                ));
            }
        }
        None => entry.remote = to,
    }
    // A park is one step inside another operation, not a place the two sides
    // agreed on, and it is recorded as `remote` alone: that IS where the server
    // has the file. Written into the agreement it would take the real name
    // with it, and the recovery in `pass` that puts an abandoned park back
    // "where both sides last agreed" would find only the scratch name there.
    // The disk still wears the real name too, so the spelling stays.
    //
    // The same for a put-back the pass queued for an abandoned park: it is a
    // rename on the SERVER, of something the disk has not followed -- the
    // park may stand in another folder from the one the agreement names, or
    // go back under the next free name. Written into the agreement it says
    // the directory is already there, and the pass then reads the directory
    // where it really is as the user moving it back (or, once its parent's
    // trash has taken it, as the user deleting it: the server's copy followed
    // into the trash, estate seed 16062180). Recorded as `remote` alone, the
    // ordinary local move brings the disk along next pass.
    let disk_follows = serde_json::from_str::<Value>(&op.params)
        .ok()
        .and_then(|p| p.get("disk_follows").and_then(Value::as_bool))
        .unwrap_or(false);
    if op.kind != "park_remote" && !disk_follows {
        entry.synced_placement = Some(entry.remote.clone());
        // A rename this device derived from its own disk says the file wears
        // the new name byte for byte, and the server now calls it that too.
        // Any spelling written down earlier is not a fact about the disk any
        // more. Kept, it would send the next scan looking for the file under a
        // name it no longer has -- and edited bytes are then found by neither
        // path nor content, which reads as a deletion plus a stranger. A
        // reparent alone changes no name and keeps its mapping.
        if renaming {
            entry.local_name = None;
        }
    }
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
/// Is this kind of thing standing at that path?
///
/// `fingerprint` answers only for a regular FILE: handed a directory it returns
/// `None`, which is indistinguishable from "there is nothing here". So every
/// question of the form "is it there?" asked about something that might be a
/// folder has to be asked by kind, or it gets a confident no about a directory
/// that is plainly on disk -- and the caller then acts on a lie.
///
/// Two of those lies lived in `move_local`. Choosing between a planned and an
/// agreed starting point tested both candidates with `fingerprint`, so for a
/// folder both tests were dead and the planned path was always taken, blindly,
/// which is the thing that choice exists to avoid. And the replay guard below
/// -- already at the destination, so the move landed and only the answer was
/// lost -- was dead for the same reason, which turned a repeated folder move
/// into an error that no amount of retrying could ever clear.
fn is_at(
    env: &ExecEnv,
    kind: EntityType,
    path: &std::path::Path,
) -> Result<bool, ExecError> {
    Ok(match kind {
        EntityType::File => env.vfs.fingerprint(path)?.is_some(),
        EntityType::Folder => env.vfs.read_dir(path).is_ok(),
    })
}

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
    // Belt and braces beside the cancellation in `pass`. Naming may have parked
    // this entry because the destination cannot hold its name, and an op that
    // ran anyway would land on the occupied name and `make_room` would move a
    // file nobody touched out of the way. Dropped quietly: the park is already
    // a visible statement with an issue of its own.
    if matches!(entry.status, LocalStatus::Unsyncable(_)) {
        return Ok(OpOutcome::Overtaken(
            "the destination cannot hold this name".into(),
        ));
    }
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
    // A parked entry is at its scratch name and nowhere else: the path the
    // plan named is the one it stepped out of, and something else may be
    // standing there by now -- the folder it stepped aside FOR. Its record
    // says where it is; that is read first.
    let parked = entry
        .local_name
        .as_deref()
        .is_some_and(|n| n.starts_with(crate::order::SWAP_PREFIX));
    let from = match (planned_at, agreed_at) {
        (_, Some(a)) if parked && is_at(env, op.entity.entity_type, &a)? => a,
        (Some(p), _) if is_at(env, op.entity.entity_type, &p)? => p,
        (_, Some(a)) if is_at(env, op.entity.entity_type, &a)? => a,
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
            if matches!(why, Unplaced::AncestorMissing) {
                // The record of where this file sits here hangs off a folder
                // this device has stopped tracking, so it cannot be turned into
                // a path — not now and not on any later attempt, because
                // nothing is going to put that folder back.
                //
                // Reporting the move overtaken and leaving the record alone is
                // what makes that permanent: the next pass reads the same
                // unusable placement, plans the same move, and gets the same
                // answer. It costs no error and no queued work, so nothing
                // shows it, and the device simply never goes quiet again.
                //
                // Forgetting where it was says the true thing — this file has
                // no known place on this disk — and leaves the entry to be
                // materialized fresh at the place the server now has it.
                //
                // The whole agreement goes, not just the placement. Half of one
                // is worse than none: an entry still holding a content
                // agreement reads as established, an established entry's remote
                // side reads as unchanged, and the pass then has nothing to do
                // about a file that is not on this disk at all. It would go
                // quiet — correctly, by its own lights — while silently short a
                // file.
                // Only for a file. Its bytes are on the server, so forgetting
                // where it used to be costs a download and settles the matter.
                //
                // A folder has no bytes to come back. What it has is a
                // directory on this disk with the whole subtree inside it, and
                // "materialize it fresh" means CREATE A SECOND ONE -- an empty
                // folder at the server's current name, recorded as agreed,
                // while the original and everything under it sits at the old
                // name belonging to nothing. Both devices then report
                // themselves settled and the audit disagrees with them, which
                // is what the rig had: one entity, two create_local_folder ops
                // and a move, and `Sub 3/Sub 6` stranded under a parent name
                // that had been superseded hours earlier.
                //
                // So the placement stays. If the ancestor never comes back the
                // entry is stranded, which `sweep_stranded_entries` counts and
                // answers with an index walk -- the escape hatch built for
                // exactly this. If it does come back, the placement is still
                // known and reconcile plans the rename it should have planned.
                if op.entity.entity_type == EntityType::File {
                    entry.synced_placement = None;
                    entry.synced_fingerprint = None;
                    entry.synced_content = None;
                    entry.synced_remote_content = None;
                    entry.status = LocalStatus::PendingDownload;
                    env.store.put_entry(&entry)?;
                }
            }
            return Ok(why.outcome());
        }
    };
    let dest = match path_for(env, &to)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };
    // Has this already happened, with only the answer lost? Ask before touching
    // anything. `make_room` is about to treat whatever stands at the destination
    // as an impostor and move it aside, and for a folder it has no way to
    // recognise its own arrival -- for a file it compares the content hash and
    // steps back, but a directory has no hash to compare. So a replayed folder
    // move made a conflict copy of the folder out of the folder itself, and left
    // the real one wearing the copy's name.
    //
    // Source gone AND the destination occupied is the replay's signature. While
    // the source is still on disk this cannot fire, so an unrelated rival
    // holding the destination name is still moved aside exactly as before.
    // Into a folder that is not on this disk yet. Its path would resolve by
    // the server's name alone, which is whatever directory happens to wear
    // that name here -- this entry's own, when the server replaced the folder
    // with a namesake and moved this one inside it. Nothing to do until the
    // folder exists, and waiting here keeps this entry busy so the round
    // never plans the park that would let the folder be created: the op
    // stands down and the next round plans both, in order.
    //
    // Not for an entry whose park is this plan's own: the folder's create is
    // queued ahead of this finisher, and if the create is still being retried
    // the finisher waits with it. Standing down would leave the park with no
    // operation open on it, which reads as abandoned: judged, swept, and the
    // folder downloaded again.
    //
    // Asked of the PLAN, not of the disk. The scratch name appears only once
    // the park has landed, and park, create and finisher are three ops that
    // fail independently -- refuse the park's rename once and this finisher
    // runs first, sees an ordinary name, stands down as overtaken and is
    // dropped; the park lands a pass later with nothing left to finish it.
    // For a file the give-up that follows costs a re-download, which is the
    // case it was designed for. For a FOLDER it trashes the directory with
    // everything inside it, and the next scan pushes every child's deletion
    // to the server. So a park still queued counts exactly as much as one
    // already worn. Reviewer probe p2.
    let park_queued = env
        .store
        .queued_ops()?
        .iter()
        .any(|o| o.kind == "park_local" && o.entity == entry.id);
    let parked_here = park_queued
        || entry
            .local_name
            .as_deref()
            .is_some_and(|n| n.starts_with(crate::order::SWAP_PREFIX));
    if let Some(p) = to.parent {
        if let Some(parent) = env.store.get_entry(EntityId::folder(p))? {
            if parent.synced_placement.is_none() && parent.stand_in.is_none() {
                if parked_here {
                    return Ok(OpOutcome::Retry(
                        "the folder it is going into is not on this disk yet".into(),
                    ));
                }
                return Ok(OpOutcome::Overtaken(
                    "the folder it is going into is not on this disk yet; planning again".into(),
                ));
            }
        }
    }
    // A folder cannot be moved into its own subtree: the server's tree got
    // there by moving what is inside it out first, and this device applies
    // that move first too. Asked out of order -- the planner orders moves by
    // ancestry, this is the belt to its braces -- it waits for the subtree
    // to move out rather than asking the disk for something no filesystem
    // does, and rather than writing an agreement that says a folder sits
    // inside itself. Estate seed 21093056.
    if entry.id.entity_type == EntityType::Folder && from != dest && dest.starts_with(&from) {
        return Ok(OpOutcome::Retry(
            "it is being moved into its own subtree; waiting for what is inside to move out".into(),
        ));
    }
    let landed = from != dest
        && !is_at(env, entry.id.entity_type, &from)?
        && is_at(env, entry.id.entity_type, &dest)?;
    if from != dest && !landed {
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
            Err(jd_vfs::VfsError::NotFound(_)) if is_at(env, entry.id.entity_type, &dest)? => {}
            Err(e) => return Err(e.into()),
        }
    }
    // Only the agreement, never where the server has it. This operation is told
    // its destination by the plan that queued it, and a plan outlives the pass
    // that wrote it: the feed is read at the top of a pass and the queue is run
    // at the bottom, so an op left over from a pass that died carries a place
    // the device has since been told is wrong. Writing that into `remote`
    // destroys the newer answer and then agrees with itself, which reads as
    // settled -- and the change that said otherwise is already behind the
    // cursor. Recording only what this disk now holds leaves the disagreement
    // that plans the next move.
    // The name this file is NOW materialized under, recorded in the same breath
    // as the placement it belongs to.
    //
    // Without this the two halves of the entry disagree for a pass: the
    // placement is fresh and the mapping is stale, because naming resolves an
    // entry against its last AGREED name (see `competing_placement`) and so has
    // never looked at this destination. `effective_local_name` then bolts the
    // new placement to the old mapping and answers with a path the file is not
    // at -- the raw server name, which on Windows is one the volume would have
    // altered anyway. Anything resolving a path in that window writes there: a
    // download landing beside the file it was meant to replace, and a scan that
    // finds the result, does not recognise it, and offers it to the server as a
    // brand new file every pass for ever.
    //
    // `dest` is where this operation actually put it, so it is the one answer
    // that cannot be stale. Equal to the server's spelling in the ordinary case,
    // which is recorded as no mapping at all.
    let landed_as = dest
        .file_name()
        .and_then(|n| n.to_str())
        .map(|n| n.to_string());
    if op.kind == "park_local" {
        // A park is one step inside another operation, not a place the two
        // sides agreed on: recorded as the spelling this disk holds and
        // nothing else, as a remote park is recorded as `remote` alone. Into
        // the agreement, the scratch name became the entry's real name --
        // naming judged it unholdable, parked the entry, and swept the
        // directory it had just stepped aside into. Estate seed 22081285.
        entry.local_name = landed_as;
    } else {
        entry.local_name = match landed_as {
            Some(ref n) if *n != to.name => Some(n.clone()),
            _ => None,
        };
        entry.synced_placement = Some(to);
    }
    // A fingerprint is only ever recorded about bytes that have been looked at.
    //
    // This runs moments before the download guard uses it, and that guard has
    // no other reference point: it asks whether the local file changed since
    // the decision that planned the download, and it asks by comparing the
    // recorded fingerprint with the disk. Recording the fingerprint of whatever
    // happens to be standing at the destination hands that guard a reference
    // that matches by construction. The rig found the shape: the file at the
    // destination was a different one (a different inode) holding bytes nothing
    // had ever uploaded, this line recorded ITS fingerprint against a content
    // hash belonging to the old file, and the download in the same pass sailed
    // through the guard and destroyed the only copy.
    //
    // Asked by content rather than by inode, because inodes are reused and the
    // question is not whether this is the same file but whether these are the
    // bytes the entry agreed about. No agreement is the honest answer when they
    // are not: the download path then makes room instead of overwriting, and
    // the scan meets the bytes as the change they are. `synced_content` stays,
    // so a genuine local edit is still met as a conflict rather than as a
    // stranger.
    // Both halves have to be readable for the answer to mean anything. An
    // unreadable file and an entry with no content agreement are both `None`,
    // and letting those compare equal would record a fingerprint about bytes
    // nobody managed to read -- accidentally safe, because with no content
    // agreement every later guard falls through to its refusal arm, but safe by
    // luck rather than by decision.
    let agreed = entry.synced_content.as_ref().map(|c| c.sha256.clone());
    let verified = match (entry.id.entity_type, &agreed) {
        (EntityType::Folder, _) => true,
        (EntityType::File, Some(agreed)) => {
            env.vfs.hash(&dest).ok().as_deref() == Some(agreed.as_str())
        }
        (EntityType::File, None) => false,
    };
    entry.synced_fingerprint = if verified {
        env.vfs.fingerprint(&dest)?
    } else {
        None
    };

    // An agreement was just recorded, so say what that means for the entry's
    // status too. Leaving it behind is not cosmetic: an entry still claiming to
    // be waiting for bytes agrees with the server about everything there is to
    // agree about, so both deltas come back empty, the pass skips it, and
    // nothing ever looks at it again -- while every health count reads it as
    // work in flight and the client never reports itself up to date.
    //
    // Soak run 228 failed convergence on five of its six cycles over two
    // folders in exactly this state, sitting correctly on both devices, one
    // completed `move_local` apiece and nothing queued.
    //
    // Only from `pending_download`, and only on evidence. `pending_upload` is
    // owed work and still is; `unsyncable`, `pending_key` and `out_of_scope`
    // are verdicts this operation has no standing to overturn. The evidence
    // differs by kind: a directory at the destination IS the whole of a folder,
    // which is the same thing `create_local_folder` demands before it records
    // agreement, while a file is only here if its bytes were agreed -- without
    // that a download is genuinely still owed and the status is telling the
    // truth.
    if entry.status == LocalStatus::PendingDownload {
        let materialized = match entry.id.entity_type {
            EntityType::Folder => is_at(env, EntityType::Folder, &dest)?,
            EntityType::File => {
                entry.synced_content.is_some() && entry.synced_fingerprint.is_some()
            }
        };
        if materialized {
            entry.status = LocalStatus::Synced;
        }
    }
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

/// Do two paths name the same slot on this filesystem?
///
/// Folded per component, because the clashes that produce a park are decided
/// folded: a case-insensitive volume and a normalizing one both hand one file
/// to two spellings, and `resolve_siblings` groups them by exactly this key.
/// Comparing the strings raw would answer "different slot" for the very
/// collisions that put a stranger's file in front of a park.
pub(crate) fn same_slot(a: &str, b: &str, personality: &jd_vfs::Personality) -> bool {
    let (mut a, mut b) = (a.split('/'), b.split('/'));
    loop {
        match (a.next(), b.next()) {
            (None, None) => return true,
            (Some(x), Some(y)) => {
                if jd_vfs::comparison_key(x, personality)
                    != jd_vfs::comparison_key(y, personality)
                {
                    return false;
                }
            }
            _ => return false,
        }
    }
}

/// Is the file standing at this park's path somebody else's?
///
/// The question a park has to ask before it decides what to do about a file
/// that is not what it agreed. A stranger's file and this entry's own unsent
/// edit both differ from the agreement, so the bytes alone cannot separate
/// them.
///
/// Two conditions, and BOTH are needed. Another live entry has to say it lives
/// at this slot -- and the file actually standing here has to hold that
/// entry's agreed CONTENT. A claimant on its own is not enough:
/// the naming pass ranks by records rather than by disk, so an entry whose own
/// file has already left can be awarded a name while the loser's edited copy is
/// still lying at it. Disowning on the claim alone would throw away work
/// nobody has sent, which is the one thing this operation exists to refuse.
fn the_file_here_is_another_entrys(
    env: &ExecEnv,
    entry: &Entry,
    path: &std::path::Path,
) -> Result<bool, ExecError> {
    let Some(mine) = crate::pass::relative_path(env, entry)? else {
        return Ok(false);
    };
    let personality = env.vfs.personality();
    let mut here: Option<String> = None;
    for other in crate::pass::all_entries(env)? {
        if other.id == entry.id || other.remote_deleted {
            continue;
        }
        // A parked entry owns no file, so its claim says nothing about who the
        // bytes belong to.
        if matches!(other.status, LocalStatus::Unsyncable(_)) {
            continue;
        }
        let Some(theirs) = crate::pass::relative_path(env, &other)? else {
            continue;
        };
        if !same_slot(&mine, &theirs, &personality) {
            continue;
        }
        // Read once, and only when a claimant has already been found.
        //
        // By CONTENT, and deliberately not by fingerprint. A fingerprint match
        // is anchored on the inode -- `unchanged_from` requires file_id
        // equality -- so using one to decide whose file this is would fund an
        // identity claim with a recycled inode, which is the thing this engine
        // has already been bitten by and now refuses on principle. It would
        // also buy nothing: a claimant's genuinely unedited file matches by
        // content too, so the fingerprint arm has no true positive of its own
        // and only a false one.
        //
        // The false one is the worst state this codebase knows. A recycled
        // inode with a matching size, written in a tick the clock has not
        // moved, would have this entry's UNSENT edit read as the claimant's
        // file -- disowning the edit, and leaving the claimant's record
        // fingerprint-matching bytes that are not its agreed content. That
        // record then tells the claimant's own scan there is nothing to
        // re-read and hands the download guard a reference that agrees by
        // construction, which is precisely the shape frozen seed 2024110 pins.
        //
        // A content match cannot make that state: equal to the claimant's last
        // agreed content means the bytes are on the server, so nothing this
        // branch gives up can be lost.
        // A claimant with no agreed content cannot be matched against
        // anything, so it does not earn the read.
        let Some(agreed) = other.synced_content.as_ref() else {
            continue;
        };
        let hash = match &here {
            Some(h) => h.clone(),
            None => {
                let h = env.vfs.hash(path)?;
                here = Some(h.clone());
                h
            }
        };
        if agreed.sha256 == hash {
            return Ok(true);
        }
    }
    Ok(false)
}

/// Give up the local copy, then park.
///
/// Reached when the name the server has given this entry cannot be held on this
/// disk beside what is already there. The entry has to stop claiming a slot it
/// cannot occupy, and the file it currently has is a materialization of a
/// placement the server no longer holds -- so it is a stale copy under a name
/// that means nothing anywhere else.
///
/// The order is the whole point. Flipping the status first would leave a parked
/// entry with a file on the disk, and "parked implies nothing of this entry is
/// here" is relied on in at least four places: `competing_placement` judges a
/// parked entry by its remote placement, the scan's reserved set skips it, the
/// adoption path treats its path as free, and the convergence oracle expects
/// nothing on disk for it. A status flip alone strands the file under a
/// superseded name, which is exactly the class of defect this engine exists to
/// avoid.
///
/// Nothing is destroyed. The bytes are on the server -- proven here, not
/// assumed -- and the copy goes to the OS trash rather than being unlinked, so
/// a user who disagrees can take it back. A local edit that is NOT on the
/// server yet stops this cold: the op retries, the ordinary upload runs first,
/// and the park happens on a later pass once the work is safe.
fn unmaterialize_and_park(
    env: &ExecEnv,
    op: &Op,
    reason: jd_vfs::UnsyncableReason,
) -> Result<OpOutcome, ExecError> {
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    // The server is the only thing making this safe. If it has let go of the
    // entry, the local copy may be the last one and must not be touched here --
    // the deletion path knows how to rescue it.
    if entry.remote_deleted {
        return Ok(OpOutcome::Overtaken(
            "the server no longer has it, so the deletion path owns this".into(),
        ));
    }
    let path = match local_path(env, &entry)? {
        Placed::At(p) => p,
        Placed::Not(why) => return Ok(why.outcome()),
    };
    let on_disk = env.vfs.fingerprint(&path)?;
    if let Some(now) = on_disk {
        let agreed = entry.synced_fingerprint.filter(|agreed| {
            now.unchanged_from(agreed, &env.vfs.personality())
        });
        if agreed.is_none() || entry.synced_content.is_none() {
            // Is what is standing here even ours?
            //
            // A park's path is derived from an agreement the naming pass has
            // just overruled, so it can name a spot another entry now holds --
            // the escaped spelling of one file and the literal name of another
            // land on the same string, and the loser's path is the winner's
            // file. There is nothing of this entry's to give up in that case:
            // the record is what has to change, and the file belongs to
            // somebody else who is looking after it.
            //
            // It takes two things to say so, and the claim is not one of them
            // on its own: another live entry has to say it lives at this slot,
            // AND the file standing here has to hold that entry's agreed
            // content. Naming ranks by records rather than by disk, so an entry
            // whose own file has already left can win a name while the loser's
            // edited copy is still lying at it -- and disowning on the claim
            // alone would throw away work nobody has sent, which is the one
            // thing this operation exists to refuse.
            if the_file_here_is_another_entrys(env, &entry, &path)? {
                entry.synced_placement = None;
                entry.synced_fingerprint = None;
                entry.synced_content = None;
                entry.synced_remote_content = None;
                entry.local_name = None;
                entry.status = LocalStatus::Unsyncable(reason.clone());
                env.store.put_entry(&entry)?;
                // The state complaint, in the one shape every "unsyncable"
                // issue has: the reason as naming would have raised it. The
                // pass withdraws a complaint whose reason no longer matches the
                // entry's status, so a second wording here would be withdrawn
                // as stale on the next pass and leave the park silent. Nothing
                // was moved -- the file standing at the name was never this
                // entry's -- so there is no event to report alongside it.
                env.store.raise_issue(
                    Some(entry.id),
                    "unsyncable",
                    &format!("{reason:?}"),
                    (env.now_ms)() as i64,
                )?;
                return Ok(OpOutcome::Done);
            }
            // Stand down rather than retry, and the difference is the whole
            // operation. A retry keeps this op in the journal, an entity with
            // an open op is skipped by the round, and the upload this is
            // waiting for is planned by the round -- so retrying waits for work
            // that its own existence prevents anyone from doing. The rig found
            // it as a park on two thousand attempts against a file the user had
            // simply gone on editing, with the device never once quiet.
            //
            // Dropping it is not giving up. The clash that provoked the park is
            // still there, so the naming pass derives it again on a later pass
            // -- by which time the entity is free, the ordinary upload has run,
            // and the copy standing here is one the server has. That is the
            // order this operation always meant to run in.
            return Ok(OpOutcome::Overtaken(
                "the copy here has work the server does not have yet"
                    .into(),
            ));
        }
        match env.vfs.trash(&path) {
            Ok(()) => {}
            Err(jd_vfs::VfsError::NotFound(_)) => {}
            Err(e) => return Err(e.into()),
        }
    }
    let told = entry
        .synced_placement
        .as_ref()
        .map(|p| p.name.clone())
        .unwrap_or_else(|| entry.remote.name.clone());
    // Everything that said "there is a copy of this here" goes with the copy.
    // Left behind, they would describe a file that is not there any more; and
    // the entry has to look un-downloaded so that releasing it fetches the
    // bytes again under whatever name it is then allowed.
    entry.synced_placement = None;
    entry.synced_fingerprint = None;
    entry.synced_content = None;
    entry.synced_remote_content = None;
    entry.local_name = None;
    entry.status = LocalStatus::Unsyncable(reason.clone());
    env.store.put_entry(&entry)?;
    // Two things to tell the user, and they are different kinds. The STATE --
    // this name cannot be held here -- goes under "unsyncable" in the one
    // shape every such complaint has, the reason as naming raises it, so the
    // pass can withdraw it when the state ends and only then. The EVENT -- a
    // copy was moved to the trash -- happened, and no later state makes it
    // untrue, so it stands until the user waves it away.
    env.store.raise_issue(
        Some(entry.id),
        "unsyncable",
        &format!("{reason:?}"),
        (env.now_ms)() as i64,
    )?;
    env.store.raise_issue(
        Some(entry.id),
        "parked",
        &format!(
            "{told} was moved to the trash: this computer cannot hold the name \
             it now has on the server ({reason:?}). It is safe on the server, \
             and it comes back here if the clash is resolved."
        ),
        (env.now_ms)() as i64,
    )?;
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
                // Anything at all standing there, including a DIRECTORY of the
                // same name -- `into` is a real folder in the user's tree and
                // can hold either. Asked with `fingerprint` alone, a directory
                // read as an empty spot and the rescue renamed a file onto it,
                // which no filesystem allows: the error travelled up out of
                // `trash_local` and the operation was retried against a
                // directory that was never going to move.
                let to = if nothing_at(env, &plain)? {
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

/// Forget a folder, and of what was under it only what the SERVER confirms went
/// with it.
///
/// The two cascades are not the same cascade. The server takes the descendants
/// that are there when the trash runs; this store knows the descendants it last
/// believed were there. Those disagree exactly when a child left the folder
/// without this device learning of it yet — its own move whose answer was lost,
/// or another device's move that has not reached the feed here. A record
/// deleted on the strength of the stale half is not recoverable: the strand
/// sweep finds entries whose parent is missing, and a deleted entry is not
/// there to be found, while every change row that described it is already
/// behind this cursor.
///
/// So the believed subtree is only a list of QUESTIONS. Stat answers them, the
/// answers go through the ordinary absorb path, and only what came back gone is
/// forgotten. A spared child comes back alive at its real parent, absorption
/// re-parents it, and it stops being a descendant of this folder at all — it
/// survives structurally rather than by being exempted, which is why the same
/// code covers the lost answer, the other device and a lost stat alike.
///
/// The cost is one extra call for almost any real folder: `drive_stat` is
/// batched five hundred at a time on both sides, and this is paid only on
/// deletes. A stat that fails takes the whole operation with it rather than
/// falling back on belief — the operation retries, and trashing is idempotent.
///
/// # Why the other belief-based forgets are safe, and this one is not
///
/// **Spared implies moved-before-trashed implies absorbed-before-planned.** A
/// child the server spares is one whose move committed BEFORE the trash ran, so
/// its move row carries the lower change id — the feed is ordered by that id on
/// both the mock and the platform, and a pass absorbs the whole batch in order
/// before it plans anything. So any deletion MOTIVATED BY ABSORBED SERVER
/// KNOWLEDGE — `forget`, the `trash_local` arms, the strand sweep — has already
/// repaired the child's parent pointer by the time it decides what to forget,
/// and a spared child cannot be in its doomed set at all.
///
/// The deletion that escapes is the one motivated by nothing the server has
/// said yet: a device trashing a folder it deleted itself, in the same pass as
/// its own move whose FIRST attempt lost its answer. Nothing has been absorbed
/// to correct it and no retry has happened to repair it. That is `trash_remote`,
/// and that is sweep seed 93128.
///
/// `trash_local` is wired through here as well. Under the invariant above that
/// is belt-and-braces rather than load-bearing — it costs one call and covers
/// shapes nobody has enumerated, but a green estate is not evidence it was
/// needed.
fn forget_folder_the_server_confirms(env: &ExecEnv, root: EntityId) -> Result<(), ExecError> {
    let believed = env.store.subtree_ids(root)?;
    let (provisional, real): (Vec<EntityId>, Vec<EntityId>) = believed
        .into_iter()
        .filter(|id| *id != root)
        .partition(|id| id.is_provisional());

    // A provisional has no server side to ask about, and asking is worse than
    // pointless: `drive_stat` drops every id at or below zero, so provisionals
    // come back in neither `items` nor `missing` -- and a chunk that is ALL
    // provisional leaves nothing to ask, which both servers refuse with a 400
    // that classifies as Withdrawn and puts a spurious warning in front of the
    // user. Belief is the only account of a provisional that exists, which is
    // the same reason the provisional-root arm forgets its subtree wholesale.
    //
    // Nothing is lost by it here: a local file that has not reached the server
    // is rescued out of the folder before the trash, not forgotten with it.
    for id in provisional {
        env.store.forget_entry(id)?;
    }

    if real.is_empty() {
        env.store.forget_entry(root)?;
        return Ok(());
    }

    let answers = crate::pass::stat_all(env, &real)?;
    // Absorbed before anything is deleted, and deliberately including the ones
    // about to go: if this process dies between here and the deletes below,
    // every confirmed-gone descendant is left marked deleted and the next pass
    // clears it through the ordinary path, instead of the truth dying with the
    // process and the whole question being re-derived from nothing.
    for (id, state) in &answers {
        crate::pass::absorb_remote(env, *id, state)?;
    }
    // `deleted` here is the server declining to show it to us, which is not
    // quite the same statement as "trashed": a `missing` row means gone OR no
    // longer visible, so an entity this caller has merely lost access to counts
    // as gone. That is the right call for a record whose whole purpose is to
    // tie a local file to something reachable on the server -- but it is two
    // different server statements wearing one flag, and worth knowing if a
    // sharing change ever makes an entity stop being visible without being
    // deleted.
    let gone: std::collections::HashSet<EntityId> = answers
        .iter()
        .filter(|(_, state)| state.deleted)
        .map(|(id, _)| *id)
        .collect();
    for id in real {
        if gone.contains(&id) {
            env.store.forget_entry(id)?;
        }
    }
    env.store.forget_entry(root)?;
    Ok(())
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
            // Belief-based, and safe for the reason
            // `forget_folder_the_server_confirms` sets out: this arm is reached
            // off absorbed server knowledge, so a spared child's move row has
            // already been taken in and its pointer repaired.
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
        // A file standing at the folder's path is not the folder. The folder
        // went, something else took the name, and this op is not about it: the
        // state it wanted -- that folder gone from here -- already holds.
        //
        // Falling through instead is wrong twice over. Reading the folder to
        // rescue what is inside it cannot be done to a file, so the op fails
        // and retries on a premise that will never come back true; and if it
        // got past that, the trash below would bin a file this op was never
        // about. A folder trashed on the server while another device sent a
        // file of the same name is all it takes, and the rig found the fleet
        // still busy on an empty plan a whole campaign later.
        if env.vfs.fingerprint(&path)?.is_some() {
            env.store.delete_subtree(op.entity)?;
            return Ok(OpOutcome::Done);
        }
        // Not while something inside this directory belongs elsewhere on the
        // server. The trash below takes what is under the directory now; the
        // rescue before it saves only what never reached the server; and a
        // child the server has MOVED OUT -- live, under another folder, its
        // local copy still here because its new name cannot be placed on this
        // disk yet -- is neither. Trashed with the parent, its record stands
        // with an agreement and no directory, the next pass reads that as the
        // user deleting it, and the server's copy goes to the trash after the
        // local one. The server-side trash already waits for a move still on
        // its way out; this is the same rule for a move still on its way in,
        // kept across the passes until the child can be placed. Estate seed
        // 16062180, by way of a peer's abandoned park.
        for e in env.store.every_entry()? {
            if e.id == op.entity || e.id.is_provisional() || e.remote_deleted {
                continue;
            }
            if !local_chain_passes(env, &e, op.entity.server_id)? {
                continue;
            }
            if sits_under(env, e.id, op.entity.server_id)? {
                continue;
            }
            // A child that can move on its own -- a park being put back, a
            // move still queued -- clears in a pass or two, and the wait is
            // nobody's business. A child parked for good as far as this
            // device can see (no key for it, out of scope, a name this disk
            // cannot hold) holds the folder here until that ends, and a wait
            // with no end in sight and nothing said is the silent-busy shape
            // this engine refuses. Said as a state: raised while the trash
            // waits, and withdrawn below the moment it stops waiting.
            //
            // Parked anywhere on its way up to this folder: a file inside a
            // subfolder waiting for a key is itself Synced, and it is the
            // subfolder that cannot move.
            if local_chain_parked(env, &e, op.entity.server_id)? {
                env.store.raise_issue(
                    Some(op.entity),
                    "trash_waits",
                    &format!(
                        "{} was deleted on the server, but {} inside it now lives elsewhere on \
                         the server and cannot be moved there on this device yet; the folder \
                         stays until it can",
                        entry.remote.name, e.remote.name
                    ),
                    (env.now_ms)() as i64,
                )?;
            }
            return Ok(OpOutcome::Retry(format!(
                "something inside it now lives elsewhere on the server and has not been moved here yet ({})",
                e.id.server_id
            )));
        }
        for issue in env.store.open_issues()? {
            if issue.kind == "trash_waits" && issue.entity == Some(op.entity) {
                env.store.dismiss_issue(issue.issue_id)?;
            }
        }
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
        // sit there forever.
        //
        // But only what the server actually took: it cascaded over what was
        // there, and this store may still believe a child is inside that has
        // already left.
        forget_folder_the_server_confirms(env, op.entity)?;
    } else {
        env.store.delete_entry(op.entity)?;
    }
    Ok(OpOutcome::Done)
}

fn trash_remote(env: &ExecEnv, op: &Op) -> Result<OpOutcome, ExecError> {
    // A folder goes with everything under it, here as everywhere else.
    // `all_entries` walks DOWN from the root, so an entry whose parent has been
    // dropped is never visited, never decided about and never cleared -- and no
    // issue is raised, because nothing can see it to complain. `trash_local`
    // and `forget` are already written this way; this verb was not, and it is
    // the one the user reaches by deleting a folder on their own computer.
    //
    // Folders only. `children_of` matches on parent id alone, with nothing to
    // say the parent is a folder, and a file's server id can coincide with a
    // folder's -- so handing it a file id would sweep away an unrelated
    // folder's contents.
    let forget_here = |env: &ExecEnv| -> Result<(), ExecError> {
        if op.entity.entity_type == EntityType::Folder {
            // Only what the server confirms went with it. Its cascade took the
            // descendants that were there; this store knows the ones it last
            // believed were there, and a child that left without this device
            // hearing of it is in the second list and not the first.
            forget_folder_the_server_confirms(env, op.entity)?;
        } else {
            env.store.delete_entry(op.entity)?;
        }
        Ok(())
    };
    if op.entity.is_provisional() {
        // It never reached the server. Forgetting it locally is the whole job --
        // and a provisional folder can perfectly well have provisional children
        // beneath it, which have nowhere to belong once it goes.
        //
        // Belief is the only account of a provisional subtree that exists, so
        // it is forgotten wholesale: there is nothing to ask the server about,
        // and nothing it could answer.
        if op.entity.entity_type == EntityType::Folder {
            env.store.delete_subtree(op.entity)?;
        } else {
            env.store.delete_entry(op.entity)?;
        }
        return Ok(OpOutcome::Done);
    }
    // A folder is not trashed while something inside it is still on its way
    // out. The server's cascade takes whatever is under the folder at the
    // moment of the trash, and a move that has not landed yet -- refused for
    // now, or waiting on a folder still being created -- leaves its entity
    // under this one. Deletes run last in a round for exactly this reason;
    // this is the same rule, kept across the retries the round cannot see.
    if op.entity.entity_type == EntityType::Folder {
        for queued in env.store.queued_ops()? {
            if queued.kind != "move_remote" || queued.op_id == op.op_id {
                continue;
            }
            if sits_under(env, queued.entity, op.entity.server_id)? {
                return Ok(OpOutcome::Retry(format!(
                    "something inside it is still being moved out ({})",
                    queued.entity.server_id
                )));
            }
        }
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
    // The server took the folder, and its own cascade takes what was inside it,
    // so there is nothing under here left to agree about.
    forget_here(env)?;
    Ok(OpOutcome::Done)
}

/// Is this entity, as the server has it, somewhere under this folder?
/// Does this entry's LOCAL placement -- the agreement, or the placeholder tie,
/// or failing both the server's word -- lie under `folder`? The local twin of
/// `sits_under`, which asks the same of where the server has it.
fn local_chain_passes(env: &ExecEnv, entry: &Entry, folder: i64) -> Result<bool, ExecError> {
    let mut parent = entry.local_placement().parent;
    let mut guard = 0;
    while let Some(p) = parent {
        if p == folder {
            return Ok(true);
        }
        guard += 1;
        if guard > 512 {
            return Err(ExecError::Contract("folder tree has a loop in it".into()));
        }
        parent = match env.store.get_entry(EntityId::folder(p))? {
            Some(f) => f.local_placement().parent,
            None => None,
        };
    }
    Ok(false)
}

/// Is this entry, or any folder on its local chain below `folder`, parked --
/// anything but `Synced`, so waiting for a key, out of scope, or unsyncable?
fn local_chain_parked(env: &ExecEnv, entry: &Entry, folder: i64) -> Result<bool, ExecError> {
    if entry.status != LocalStatus::Synced {
        return Ok(true);
    }
    let mut parent = entry.local_placement().parent;
    let mut guard = 0;
    while let Some(p) = parent {
        if p == folder {
            break;
        }
        guard += 1;
        if guard > 512 {
            return Err(ExecError::Contract("folder tree has a loop in it".into()));
        }
        parent = match env.store.get_entry(EntityId::folder(p))? {
            Some(f) if f.status != LocalStatus::Synced => return Ok(true),
            Some(f) => f.local_placement().parent,
            None => None,
        };
    }
    Ok(false)
}

fn sits_under(env: &ExecEnv, id: EntityId, folder: i64) -> Result<bool, ExecError> {
    let Some(entry) = env.store.get_entry(id)? else {
        return Ok(false);
    };
    let mut parent = entry.remote.parent;
    let mut guard = 0;
    while let Some(p) = parent {
        if p == folder {
            return Ok(true);
        }
        guard += 1;
        if guard > 512 {
            return Err(ExecError::Contract("folder tree has a loop in it".into()));
        }
        parent = match env.store.get_entry(EntityId::folder(p))? {
            Some(f) => f.remote.parent,
            None => None,
        };
    }
    Ok(false)
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

/// Write down that this computer and the server put the file in the same place.
///
/// Nothing is moved, uploaded or downloaded — the file is already where both
/// sides want it. What changes is the record of where the engine believes it
/// lives, which is what the next scan searches and what naming treats as this
/// entry's claim on a name.
///
/// Guarded on the server's placement being the one that was agreed to, because
/// between planning this and running it somebody may have moved the file again;
/// recording an agreement that has already lapsed is how a record starts
/// describing a folder the file is not in, which is the very thing this exists
/// to prevent.
fn adopt_placement(env: &ExecEnv, op: &Op, to: Placement) -> Result<OpOutcome, ExecError> {
    let Some(mut entry) = require_entry(env, op.entity)? else {
        return Ok(OpOutcome::Overtaken(
            "the entry is no longer tracked".into(),
        ));
    };
    if entry.remote != to {
        return Ok(OpOutcome::Overtaken(
            "the server has moved it since this was planned".into(),
        ));
    }
    entry.synced_placement = Some(to);
    env.store.put_entry(&entry)?;
    Ok(OpOutcome::Done)
}

// ---------------------------------------------------------------------------
// Recovery
// ---------------------------------------------------------------------------

/// What the crash window left behind, put back on the queue.
///
/// Every `InFlight` op died at an unknowable instruction, so nothing here knows
/// whether the server acted on it. It does not need to: every op is written to
/// survive being run a second time, which is what its idempotency key, the
/// server's replay cache and its own check of where the server has the thing
/// now are all for. Re-running one is how it finds out.
///
/// This used to ask the server instead -- one call per op, and if the answer
/// was yes, the op was marked done. That is where it went wrong. Done is not
/// the same as finished: the op's own success path is what writes down what
/// happened, so an op retired on the strength of the answer left the record
/// naming the place the server had already moved the thing out of. Both sides
/// then believed they agreed, nothing was queued and nothing was planned, and
/// the device stayed quiet forever with a folder under a name the rest of the
/// fleet had stopped using.
///
/// Asking also made recovery need the network, at the one moment it is least
/// likely to be there -- a machine coming back up. An op nobody could ask about
/// stayed in flight, and an op in flight is not run and its entity is not
/// re-planned, so a single unreachable server froze a set of files with no
/// error and no symptom.
pub fn recover(env: &ExecEnv) -> Result<ExecReport, ExecError> {
    let mut report = ExecReport::default();
    for op in env.store.interrupted_ops()? {
        // Back in the queue under its original key, which is what makes the
        // retry recognizable rather than repeated -- and counted as the attempt
        // it was, so that everything downstream asking whether this is a retry
        // gets the true answer.
        env.store.requeue_interrupted_op(op.op_id)?;
        report.retrying += 1;
    }
    Ok(report)
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
