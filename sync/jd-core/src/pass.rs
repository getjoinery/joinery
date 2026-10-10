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
use crate::model::{ContentId, Delta, EntityId, EntityType, Entry, LocalStatus, Placement, StandsAt};
use crate::reconcile::Context;
use crate::remote::{local_delta, remote_delta, RemoteState};
use crate::round::{run_round, DeletePolicy, RoundInput, RoundOutcome};
use crate::scan::{pair_files, KnownLocal, LocalChange, ObservedFile, ScanOutcome};

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
    /// What the pass saw and decided, when the caller asked (`Context::trace`).
    pub trace: Option<crate::trace::PassTrace>,
}

impl PassOutcome {
    /// Did this pass find nothing to do? A settled client is one where this
    /// keeps coming back true.
    ///
    /// Work waiting on a backoff counts as work. A pass that quietly reports
    /// nothing while four uploads sit in the journal waiting to be retried is
    /// telling the user their files are safely synced when they are not.
    pub fn quiet(&self) -> bool {
        self.round.plan.is_empty() && self.exec.attempted() == 0 && self.exec.deferred == 0 && self.exec.waiting == 0
    }
}

/// Run one pass.
///
/// `key_for` supplies idempotency keys; it is a parameter so a simulated run
/// reproduces exactly from its seed. Scratch names for rename cycles are
/// derived from those keys by the journal.
pub fn run_pass(
    env: &ExecEnv,
    ctx: &Context,
    policy: DeletePolicy,
    key_for: &mut dyn FnMut() -> String,
) -> Result<PassOutcome, ExecError> {
    let mut out = PassOutcome::default();
    if env.vfs.root().is_none() {
        out.root_unavailable = true;
        return Ok(out);
    }
    // Values only, from here to the end of the pass: nothing below reads the
    // store or the disk for it.
    let mut trace = ctx.trace.then(|| crate::trace::PassTrace::started((env.now_ms)()));

    // ---- what this volume says a file's identity is -------------------------
    //
    // Before anything reads a stored birth. On a volume whose births are
    // names (NTFS) the volume shows every file with the same marker birth,
    // and a stored real birth would match no file: a trade would read as two
    // records whose own files stand nowhere, and the scan would hand each
    // the other's file as its edit (B-NTFSTUNNEL).
    if env.vfs.personality().births_are_names {
        env.store.read_births_as_names()?;
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
    if let Some(t) = trace.as_mut() {
        t.reset = reset;
        for (id, state) in &fresh {
            t.remote.push((
                crate::trace::name(*id),
                format!(
                    "{:?}/{} head={} deleted={}",
                    state.placement.parent, state.placement.name, state.head_change_id, state.deleted
                ),
            ));
        }
    }
    out.reset = reset;
    out.remote_changes = fresh.len();
    for (id, state) in &fresh {
        absorb_remote(env, *id, state)?;
    }

    // ---- files this device can open now that it holds the key ---------------
    open_what_the_key_unlocks(env)?;

    // ---- nothing left with no way back to the root --------------------------
    //
    // First, because everything below finds entries by walking down from the
    // root and so cannot see these at all.
    // Re-derived every pass, and withdrawn the moment it stops being true: this
    // issue describes a state rather than an event, so leaving a stale one
    // standing tells the user to look at something that has already resolved,
    // and the only way they could clear it is by hand.
    let mut still_stranded = false;
    let stranded = sweep_stranded_entries(env)?;
    if let Some(t) = trace.as_mut() {
        t.stranded = stranded;
    }
    if stranded > 0 {
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
    // A file held outside its vault says so while it is: gone, or back where
    // the server keeps it, and the sentence is false.
    for issue in env.store.open_issues()? {
        if issue.kind != HELD_OUTSIDE_THE_VAULT {
            continue;
        }
        let stands = match issue.entity {
            Some(id) => match env.store.get_entry(id)? {
                Some(e) => held_outside_its_vault(env, &e)?,
                None => false,
            },
            None => false,
        };
        if !stands {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    // A folder trash that was waiting on a parked child says so with a state
    // issue, withdrawn by the trash itself when it proceeds. The folder can
    // also stop being trashable without that trash ever running again --
    // forgotten with an ancestor, or restored on the server -- and forgetting
    // an entry does not take its issues with it. Then the sentence is false
    // and it goes here.
    for issue in env.store.open_issues()? {
        if issue.kind != "trash_waits" {
            continue;
        }
        let stands = match issue.entity {
            Some(id) => env.store.get_entry(id)?.is_some_and(|e| e.remote_deleted),
            None => false,
        };
        if !stands {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }

    // ---- one directory, one entry; one file, one entry ----------------------
    //
    // Before naming, because naming is what turns this into a deadlock: it sees
    // two entries claiming one name and refuses the real one.
    let merged_folders = merge_duplicate_folders(env)?;
    let merged_files = merge_duplicate_files(env)?;
    if let Some(t) = trace.as_mut() {
        for (from, into) in merged_folders.iter().chain(&merged_files) {
            t.merges.push((crate::trace::name(*from), crate::trace::name(*into)));
        }
    }

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
    // A file from the server refused a name here because a file held outside
    // its vault stands at that name on this disk. Said in its own words, and
    // re-derived every pass: the sentence is true exactly while the refusal
    // and the hold both stand.
    let waiting = waiting_for_a_held_file(env)?;
    for issue in env.store.open_issues()? {
        if issue.kind == WAITS_FOR_A_HELD_FILE
            && !waiting.iter().any(|(id, d)| issue.entity == Some(*id) && issue.detail == *d)
        {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    for (id, detail) in &waiting {
        env.store.raise_issue(Some(*id), WAITS_FOR_A_HELD_FILE, detail, (env.now_ms)() as i64)?;
    }
    for (id, reason) in &out.naming.unsyncable {
        if waiting.iter().any(|(w, _)| w == id) {
            continue;
        }
        env.store.raise_issue(
            Some(*id),
            "unsyncable",
            &format!("{reason:?}"),
            (env.now_ms)() as i64,
        )?;
    }
    // A park's EVENT complaint -- a copy was moved to the trash, and comes
    // back if the clash is resolved -- is over when the clash is resolved and
    // the entry is released: the copy comes back by the ordinary path, and
    // there is nothing left for the user to do or know. Left open after the
    // release it named a file that was back and fine, and on a sweep trace a
    // stall beside an open issue reads as a hold (B12).
    for id in &out.naming.recovered {
        for issue in env.store.open_issues()? {
            if issue.kind == "parked" && issue.entity == Some(*id) {
                env.store.dismiss_issue(issue.issue_id)?;
            }
        }
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
    //
    // The same goes for a complaint whose PARTICULARS have ended. The rival a
    // parked file clashes with can be renamed while the clash itself stays --
    // a case twin becoming a normalization twin -- and naming re-raises the
    // verdict with the new name in it. The old sentence names a file that is
    // no longer there; two open complaints about one parked file, the older
    // one now false, is the same permanent warning by another route.
    //
    // This rests on every "unsyncable" issue carrying the reason in one shape,
    // `{reason:?}`, whoever raised it -- naming here, or a park operation that
    // gave up a copy. What a park DID (moved a copy to the trash) is an event,
    // reported under its own kind, and nothing here touches events.
    {
        let unsyncable_now: std::collections::HashMap<EntityId, String> = all_entries(env)?
            .into_iter()
            .filter_map(|e| match &e.status {
                LocalStatus::Unsyncable(reason) => Some((e.id, format!("{reason:?}"))),
                _ => None,
            })
            .collect();
        for issue in env.store.open_issues()? {
            if issue.kind != "unsyncable" {
                continue;
            }
            let Some(id) = issue.entity else { continue };
            if unsyncable_now.get(&id) != Some(&issue.detail) {
                env.store.dismiss_issue(issue.issue_id)?;
            }
        }
    }

    // ---- a directory standing in for a vault this device cannot open --------
    //
    // Before the disk is walked, for the same reason naming runs before it:
    // the scan pairs what is on disk against where the engine believes each
    // entry is, and a stand-in the server has renamed is renamed here first so
    // that belief and the disk agree.
    placeholders_follow_the_server(env, key_for)?;

    // ---- what this computer did --------------------------------------------
    let observed = observe(env)?;
    let tie_breaks = tie_breaks_for_this_pass(env, &observed)?;
    let known = known_local(env, &tie_breaks)?;
    let strong_volume = env.vfs.personality().stable_file_identity;
    let awaiting = awaiting_bytes(env)?;
    let scan = pair_files(&known, &observed, &awaiting, strong_volume);
    if let Some(t) = trace.as_mut() {
        for (id, change) in &scan.changes {
            if !matches!(change, LocalChange::Unchanged) {
                t.verdicts.push((crate::trace::name(*id), format!("{change:?}")));
            }
        }
        for file in &scan.created {
            t.verdicts.push((file.path.clone(), "created".into()));
        }
    }
    adopt_own_files(env, &known, &observed)?;
    bind_own_files(env, &scan, strong_volume)?;
    forget_what_a_save_superseded(env, &scan)?;
    note_what_each_record_saw(env, &known, &observed, &scan)?;
    note_the_tie_breaks_each_record_saw(env, &known, &observed, &scan)?;
    say_where_two_files_may_have_swapped(env, &known, &scan)?;

    // Anything on disk that nothing is tracking gets an identity now, so that
    // the loop below can treat it like any other entry. Folders first: a new
    // file inside a new folder cannot say where it lives until the folder has
    // one.
    let (dirs_on_disk, dir_identity, dir_tie_breaks, dir_births) = observed_dirs_and_tie_breaks(env)?;
    let mut folder_ids = folder_paths(env, Some(&dir_identity))?;
    // What each folder record says its own path is, before this pass moves
    // anything: the only pairings a record may learn its directory from.
    // Only records with an agreement: a record the server has only named
    // has no path of its own to learn a directory at, and kept here it
    // stood in front of the agreed record at that path, which then never
    // relearned its directory after a restore (B-RESTORE).
    let mut agreed_paths: HashMap<String, i64> = HashMap::new();
    for e in all_entries(env)? {
        if e.id.entity_type == EntityType::Folder && e.synced_placement.is_some() {
            if let Some(path) = agreed_path(env, &e)? {
                agreed_paths.insert(path, e.id.server_id);
            }
        }
    }

    // A folder the user renamed is a folder, renamed — not a new folder plus a
    // thousand files that moved into it. Without this the old folder is left
    // behind on the server, everything inside is re-parented one file at a
    // time, and the folder's sharing and history stay with a shell nobody can
    // see any more.
    // Before the folders are read: on the first pass that finds the volume
    // weak, a vault whose directory the user moved in the same breath is
    // found by its contents, not read by its name. Parked after the folder
    // scan, that pass read a ring rotation by name -- the plain folder's
    // directory as the vault and the vault's sealed file as carried into a
    // plain folder -- and every pass after built on it (FAT hostile2 74400).
    park_vaults_on_a_weak_volume(env)?;
    say_whether_renames_can_be_tracked(env)?;
    let (followed, traded_with_a_vault) =
        follow_parked_vaults_by_their_contents(env, &observed, &dirs_on_disk, &dir_tie_breaks, &tie_breaks, &mut folder_ids)?;
    let folders = detect_folder_moves(
        env,
        &observed,
        &dirs_on_disk,
        &dir_identity,
        &dir_births,
        &mut folder_ids,
        &followed,
        &traded_with_a_vault,
    )?;
    lapse_parked_vaults_that_stand_nowhere(env, &folders, &followed, &mut folder_ids)?;
    point_paths_at_the_folders_whose_directories_they_are(env, &dir_identity, &mut folder_ids)?;
    note_the_tie_breaks_of_the_folders(env, &folder_ids, &dir_tie_breaks, &tie_breaks)?;
    lift_parks_whose_directories_came_home(env, &folders, &dir_identity, &folder_ids)?;
    if let Some(t) = trace.as_mut() {
        for (id, to) in &folders.moves {
            t.folders.push((crate::trace::name(*id), format!("moved to {:?}/{}", to.parent, to.name)));
        }
        for (id, path) in &folders.deferred {
            t.folders.push((crate::trace::name(*id), format!("found under an unbound directory at {path}")));
        }
        for dir in &folders.held {
            t.folders.push((dir.clone(), "held".into()));
        }
    }

    // The recorded paths of the folders not yet on this disk in their own
    // right, keyed the way the disk keys names. A directory is matched to
    // such a folder by that key and not by spelling: on a disk that folds
    // case, a stand-in the user respelled is the same directory, and read
    // raw it was adopted as a new plain folder beside a vault of the same
    // name, with everything under it sent up in the clear.
    let personality = env.vfs.personality();
    let fold = |path: &str| -> String {
        path.split('/')
            .map(|c| jd_vfs::comparison_key(c, &personality))
            .collect::<Vec<_>>()
            .join("/")
    };
    // Two such folders folding to one key -- the server keeps names by exact
    // spelling, so `Private` and `private` can share a parent -- match
    // nothing by key: a pick between them would be a pick by hash order,
    // and a directory tied to two vaults at once.
    let mut unmaterialized_by_key: HashMap<String, Option<(String, i64)>> = HashMap::new();
    for (path, id) in &folder_ids {
        if env
            .store
            .get_entry(EntityId::folder(*id))?
            .is_some_and(|e| e.synced_placement.is_none())
        {
            unmaterialized_by_key
                .entry(fold(path))
                .and_modify(|slot| *slot = None)
                .or_insert(Some((path.clone(), *id)));
        }
    }
    for dir in &dirs_on_disk {
        // A tracked folder's own directory, standing apart from its files:
        // not a new folder, and not adopted as one while the disagreement is
        // unresolved.
        if folders.held.contains(dir) || folders.held.iter().any(|h| dir.starts_with(&format!("{h}/"))) {
            if let Some(t) = trace.as_mut() {
                t.skip_path(dir, line!(), "directory held");
            }
            continue;
        }
        let matched = match folder_ids.get(dir) {
            Some(&id) => Some((dir.clone(), id)),
            None => unmaterialized_by_key.get(&fold(dir)).cloned().flatten(),
        };
        if let Some((recorded, id)) = matched {
            // A directory at the derived path of a vault folder this device
            // cannot open is the user's placeholder for that vault, and it is
            // tied to the folder here, by identity, the first time it is
            // seen. Left tied by name alone, the holder renaming the vault
            // on the server turned this directory into a new plain folder
            // of the old name and sent what the user saved in it up in the
            // clear -- see `Entry::stand_in`.
            let id = EntityId::folder(id);
            if let Some(mut entry) = env.store.get_entry(id)? {
                if entry.status == LocalStatus::PendingKey
                    && !entry.id.is_provisional()
                    && entry.synced_placement.is_none()
                    && entry.stand_in.is_none()
                {
                    entry.stand_in = placement_of(dir, &folder_ids);
                    env.store.put_entry(&entry)?;
                } else if recorded != *dir && entry.stand_in.is_some() {
                    // The same directory, respelled by the user. The record
                    // takes the disk's spelling, so every later path read
                    // matches exactly.
                    if let Some(leaf) = dir.rsplit('/').next() {
                        if let Some(stand_in) = entry.stand_in.as_mut() {
                            stand_in.name = leaf.to_string();
                        }
                    }
                    env.store.put_entry(&entry)?;
                }
            }
            if recorded != *dir {
                folder_ids.insert(dir.clone(), id.server_id);
            }
            continue;
        }
        let Some(placement) = placement_of(dir, &folder_ids) else {
            if let Some(t) = trace.as_mut() {
                t.skip_path(dir, line!(), "directory with no placement");
            }
            continue;
        };
        let id = EntityId::folder(env.store.next_provisional_id()?);
        let mut entry = blank(id, &placement);
        // The record knows its directory from the moment it is minted from
        // one. Left to `record_directory_identities` -- which reads agreed
        // paths only, on a later scan -- a folder the user renamed between
        // its create and that scan never learned its directory: the agreed
        // path held nothing, the record read as missing, and a namesake
        // arriving from the server adopted the directory with the folder's
        // own files inside it (the reset's WP3 finding C4, clean3 74821).
        // The create's landing keeps this (`agree` passes no fingerprint).
        entry.synced_fingerprint = dir_identity
            .get(dir)
            .copied()
            .filter(|id| *id != 0)
            .map(|id| jd_vfs::Fingerprint::of_directory(id, dir_births.get(dir).copied().unwrap_or(0)));
        entry.is_encrypted = parent_is_encrypted(env, placement.parent)?;
        // A folder made inside a vault this device cannot open waits for a
        // key, exactly as a file made there does (below). Pushed instead, it
        // is created on the server, parked `PendingKey` by naming on the next
        // pass, and from then on its record and this directory drift apart --
        // nothing keyless ever applies a rename to a parked entry -- so the
        // directory is adopted again as a brand-new folder, the create is
        // refused over the name its own twin holds, the executor steps aside
        // with a conflict name, and the server gains one more folder per
        // pass for ever. A device that never goes quiet, minting folders
        // nobody asked for.
        if entry.is_encrypted && env.vault.is_none() {
            entry.status = LocalStatus::PendingKey;
        }
        env.store.put_entry(&entry)?;
        folder_ids.insert(dir.clone(), id.server_id);
        out.local_creations += 1;
    }
    let mut folders = folders;
    folders.place_deferred(env, &folder_ids)?;
    record_directory_identities(env, &agreed_paths, &dir_identity, &dir_births)?;
    note_where_folders_stand(env, &folder_ids)?;
    // Sealed files this scan finds outside every vault while the server keeps
    // them in one: held from this pass on, and their names in the vault are
    // theirs from the start of the pass. The hold is written when the round
    // reaches the record, and a file arriving at its slot earlier in the same
    // pass -- a new file saved there, or another record moved there -- would
    // otherwise find the name free and go up under it: two sealed files with
    // one real name in one vault folder (`clear_of_a_held_name`).
    let mut leaving_a_vault: std::collections::HashSet<EntityId> = std::collections::HashSet::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::File
            || entry.id.is_provisional()
            || !entry.is_encrypted
            || entry.remote_deleted
            || !parent_is_encrypted(env, entry.remote.parent)?
        {
            continue;
        }
        let Some(LocalChange::Moved { to_path, .. } | LocalChange::MovedAndEdited { to_path, .. }) =
            scan.change_for(entry.id)
        else {
            continue;
        };
        if let Some(to) = placement_of(to_path, &folder_ids) {
            if !parent_is_encrypted(env, to.parent)? {
                leaving_a_vault.insert(entry.id);
            }
        }
    }
    // Every file nothing owns gets a record, including one standing where a
    // pending download will land: a file with no record is outside every
    // rule a record carries (`specs/drive_file_ownership.md`). One at a
    // download's slot waits for that download (`waits_for_a_download`), which
    // settles the two by identity when it lands.
    if let Some(t) = trace.as_mut() {
        let mut dirs: Vec<&String> = dirs_on_disk.iter().collect();
        dirs.sort();
        for dir in dirs {
            let bound = match folder_ids.get(dir.as_str()) {
                Some(id) => format!("folder:{id}"),
                None if folders.held.contains(dir.as_str()) => "held".to_string(),
                None => "unbound".to_string(),
            };
            t.dirs.push((dir.clone(), bound));
        }
    }
    for file in &scan.created {
        let Some(placement) = placement_of(&file.path, &folder_ids) else {
            // Its folder is not tracked yet. Nothing is lost: the folder gets an
            // identity above on this pass or the next, and the file follows.
            if let Some(t) = trace.as_mut() {
                t.skip_path(&file.path, line!(), "created file with no placement");
            }
            continue;
        };
        // A new file saved in a vault under the name a held file still holds
        // there goes up beside the held copy, never under that name.
        //
        // Unless this is the held file itself, back under a new record: moved
        // and edited in one pass, it reads as a creation (scan rule 4). Its
        // disk identity is the held record's own, and that buys a WAIT, never
        // a name: nothing is decided for it here, and it is not sent while the
        // hold stands (below).
        let coming_home = match held_owner_of(env, file)? {
            Some(held) => !held_file_stands(env, &held, &observed)?,
            None => false,
        };
        let placement = if coming_home {
            placement
        } else {
            clear_of_a_held_name(env, &file.path, &placement, None, &leaving_a_vault)?
        };
        let id = EntityId::file(env.store.next_provisional_id()?);
        let mut entry = blank(id, &placement);
        // The file this record is minted for is its own from this moment,
        // before any upload: a record with nothing to know its file by is
        // one any file at its path can pass for.
        entry.own_file = Some(file.fingerprint.identity());
        entry.last_seen_sha = Some(file.sha256.clone());
        // Encryption is a property of where a thing lives, not of the thing:
        // the server decides an upload is encrypted by looking at the
        // destination folder. Working that out HERE, when the file first gets
        // an identity, is what makes the upload path encrypt it — a file that
        // reached the uploader marked plaintext would be sent in the clear into
        // a folder the user believes is private, and the server would store it
        // exactly as sent.
        entry.is_encrypted = parent_is_encrypted(env, placement.parent)?;
        // On a volume with no file identities, bytes a sealed record last saw
        // or agreed on, while its own file no longer stands at its path with
        // them, are that sealed file carried out as far as anything here can
        // tell (a5, 2026-09-29: nothing whose bytes pair to a sealed record is
        // ever minted plain). Minted sealed, it is held and never sent. Minted
        // plain, a conflict copy of a sealed file traded out of its parked
        // vault went up in the clear (FAT kill2 75122). A copy the user made,
        // its original still standing, is not this.
        if !entry.is_encrypted && sealed_bytes_carried_out(env, &file.sha256, &observed)? {
            entry.is_encrypted = true;
        }
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
            // Where these bytes came from, if the disk still says so.
            //
            // A file MOVED into the vault arrives as a move and the crossing
            // branch mints the claimant with its provenance. A file moved AND
            // EDITED arrives here instead: with the inode no longer trusted for
            // identity, the scan cannot recognise it as the same file, so it is
            // let go of at the old path and adopted as a new one here. Correct,
            // and it loses the one fact that holds the server's copy: without
            // it the source reads as deleted and the server's copy is trashed
            // during a wait for a key that may never end.
            //
            // The inode is enough for THIS, and only because of what it costs
            // when it is wrong -- see `plaintext_source_of`.
            entry.replaces = plaintext_source_of(env, file.fingerprint.identity(), &observed)?;
        }
        env.store.put_entry(&entry)?;
        out.local_creations += 1;
    }
    // The same question, asked again of every claimant already standing. A
    // claimant that has never uploaded has no content of its own: whatever
    // stands at its path is its file, and the scan reads any change of bytes
    // there as an edit. When the bytes that arrived are a plaintext file's --
    // the original brought back OUT of the vault under a new name, then
    // dragged back IN over this path -- the original pairs with nothing, reads
    // deleted, and its server copy would be trashed while the only bytes wait
    // under an entry that cannot upload. So the hold follows the bytes: the
    // claimant takes over holding whichever server copy the inode says it now
    // stands in for, by the same rule and for the same reason as above.
    for mut claimant in all_entries(env)? {
        if !claimant.id.is_provisional()
            || claimant.id.entity_type != EntityType::File
            || !claimant.is_encrypted
            || claimant.status != LocalStatus::PendingKey
        {
            continue;
        }
        let Some(crate::scan::LocalChange::Edited { fingerprint, .. }) = scan.change_for(claimant.id)
        else {
            continue;
        };
        let Some(source) = plaintext_source_of(env, fingerprint.identity(), &observed)? else {
            continue;
        };
        if claimant.replaces != Some(source) {
            claimant.replaces = Some(source);
            env.store.put_entry(&claimant)?;
        }
    }

    // ---- what each side did, per entry --------------------------------------
    if let Some(t) = trace.as_mut() {
        t.scan_ended_ms = (env.now_ms)();
    }
    let mut inputs: Vec<RoundInput> = Vec::new();
    let resolve = |path: &str| placement_of(path, &folder_ids);
    // Anything already in the journal is spoken for. Deciding about it again
    // would queue a second operation doing the same job, once per pass, for as
    // long as the first one kept failing.
    let busy = env.store.entities_with_open_ops()?;
    let written_off = env.store.written_off_now()?;
    // Sources whose bytes are already claimed by an entry waiting for a key.
    //
    // Derived fresh every pass rather than remembered, so there is no state to
    // go stale: the moment the claimant's create lands it stops being
    // provisional and the hold lapses, and if the user moves the file back out
    // of the vault the claimant is swept away and the hold goes with it.
    let held_for_replacement: std::collections::HashSet<EntityId> = all_entries(env)?
        .iter()
        .filter(|e| e.id.is_provisional())
        .filter_map(|e| e.replaces)
        .collect();

    // ---- a stand-in whose vault was trashed on the server -------------------
    //
    // After the walk, so that what is held under it is what is on the disk
    // now, and before the round, which skips what this parks.
    park_stand_ins_of_trashed_vaults(env, &observed)?;
    let parked_on_a_weak_volume: std::collections::HashSet<EntityId> = env
        .store
        .open_issues()?
        .into_iter()
        .filter(|i| i.kind == VAULT_ON_A_WEAK_DRIVE)
        .filter_map(|i| i.entity)
        .collect();
    let held_by_park = held_by_a_weak_volume_park(env)?;
    // Everything under a folder parked out of scope is parked with it. A
    // claimant released by a key that arrives while its folder is still in
    // the trash would otherwise be planned as an upload into a trashed
    // parent, refused, and planned again every pass.
    let shadowed: std::collections::HashSet<i64> = {
        let mut set = std::collections::HashSet::new();
        for e in all_entries(env)? {
            if e.id.entity_type == EntityType::Folder
                && (e.status == LocalStatus::OutOfScope
                    || e.local_placement().parent.is_some_and(|p| set.contains(&p)))
            {
                set.insert(e.id.server_id);
            }
        }
        set
    };

    // Everything under a folder that is parked with no directory is parked with
    // it. A parked folder still has a NAME, and a child's path is built by
    // walking parent names -- so a child left in the round resolves onto
    // whatever directory won the slot and materializes INSIDE it. On a volume
    // that folds case that is how `readme` ends up holding the contents of
    // `README` as well, with nothing on the disk saying so and a delete of the
    // one taking the other's files with it.
    //
    // The parent's own issue is the whole story and the only thing the user can
    // act on, so nothing extra is raised here: the children simply wait with it,
    // exactly as the folder does, and the clash clearing lets them all through.
    //
    // Only when there is no directory, because that is the whole reason to hold
    // a child: there is nowhere right for it to go. A parked folder that DOES
    // have a directory -- one stranded under a scratch name, say -- holds the
    // user's files for real, and holding its children would stall them for
    // nothing. No construction found so far reaches that case: a stranded park
    // is put back rather than parked, and a naming clash parks the entrant,
    // which never had a directory. The condition states the reason rather than
    // guarding an observed case, and nothing pins it.
    let parked_without_a_directory: std::collections::HashSet<i64> = {
        let mut set = std::collections::HashSet::new();
        for e in all_entries(env)? {
            if e.id.entity_type != EntityType::Folder {
                continue;
            }
            let no_directory = e.synced_placement.is_none() && e.stand_in.is_none();
            if (matches!(e.status, LocalStatus::Unsyncable(_)) && no_directory)
                || e.local_placement().parent.is_some_and(|p| set.contains(&p))
            {
                set.insert(e.id.server_id);
            }
        }
        set
    };

    let sealed_names_at_risk = sealed_names_this_disk_cannot_vouch_for(env, &observed)?;
    let mut names_held: std::collections::HashSet<EntityId> = std::collections::HashSet::new();
    // A record the round passes by, and the line of the `continue` that does
    // it: what a trace says about a record that got no plan (`PassTrace`).
    macro_rules! declined {
        ($id:expr) => {{
            if let Some(t) = trace.as_mut() {
                t.skip($id, line!());
            }
            continue;
        }};
    }
    for mut entry in all_entries(env)? {
        let traced_id = entry.id;
        // A file never uploaded follows its own file: where the scan found it
        // is where it is (the reset's T1-C). Its placement is where it stands
        // now, and whether it goes up sealed is decided again from there. The
        // path rule read the file it left as deleted and its own file as a new
        // one -- which, carried out of a vault, went up in the clear.
        if entry.id.is_provisional() && entry.id.entity_type == EntityType::File {
            if let Some(LocalChange::Moved { to_path, .. } | LocalChange::MovedAndEdited { to_path, .. }) =
                scan.change_for(entry.id)
            {
                if let Some(to) = placement_of(to_path, &folder_ids) {
                    let to = clear_of_a_held_name(env, to_path, &to, Some(entry.id), &leaving_a_vault)?;
                    let into_a_vault = parent_is_encrypted(env, to.parent)?;
                    // Out of a vault the server has deleted: there is nothing
                    // left to keep it in, and the user moving it out is what
                    // the complaint about that vault asked for. It goes up as
                    // the ordinary file it now is.
                    let out_of_a_dead_vault = entry.is_encrypted
                        && !into_a_vault
                        && in_a_deleted_vault(env, entry.remote.parent)?;
                    entry.remote = to;
                    entry.local_name = None;
                    if into_a_vault && !entry.is_encrypted {
                        entry.is_encrypted = true;
                        if env.vault.is_none() {
                            entry.status = LocalStatus::PendingKey;
                        }
                    }
                    if out_of_a_dead_vault {
                        entry.is_encrypted = false;
                        entry.replaces = None;
                        if entry.status == LocalStatus::PendingKey {
                            entry.status = LocalStatus::PendingUpload;
                        }
                    }
                    env.store.put_entry(&entry)?;
                }
            }
        }
        // Written above even for a record whose upload is still queued: its
        // record says where its file is, and the queued upload, planned for
        // the place it left, stands down when it runs. Skipped, the record
        // kept naming the folder the file left, and that folder's trash
        // forgot it by that belief while the file lived on, owned by nobody
        // (plain2 75223; `specs/drive_file_ownership.md`).
        if busy.contains(&entry.id) {
            declined!(traced_id);
        }
        // An arrival naming left unjudged because the name it wants is held
        // by a busy entry waits the same pass its verdict waits: planned now,
        // its move would land on the holder's directory before the holder's
        // own op has said where that directory goes.
        if out.naming.pending.contains(&entry.id) {
            declined!(traced_id);
        }
        // Nothing on the server, and now nothing on the disk either: there is
        // no third place for it to be, so it is forgotten.
        //
        // Checked BEFORE the skips below rather than after, because every one
        // of them is a reason to wait and waiting needs a file to wait for. An
        // entry parked, or holding a slot for a key, whose file the user has
        // since taken away would otherwise sit in the store for its whole life
        // -- and anything derived from its existence sits with it. That is not
        // hypothetical: the hold one of these puts on the server copy it is
        // going to replace lapses only when it goes, so a file dragged into a
        // vault and straight back out again would never move at all.
        //
        // The skip for a parked vault folder counts as one of those skips. A
        // file the user saved inside a keyless placeholder after its vault was
        // trashed upstream, and took away again, is a record with no file
        // behind it and no server that ever heard of it -- under the park it
        // sat for good, with the round never looking. Estate seed 15091598.
        // Only an operation in flight comes first: a record whose upload is
        // mid-air is forgotten when the upload reports, not here.
        //
        // Looked for where it stands: through each folder's directory where
        // that stands now, which a folder the user renamed or moved in the
        // same interval has not yet told its agreement (`Entry::stands_at`).
        // Looked for under a folder's agreed name, a file just saved in it
        // or carried into it was not found, its record was forgotten in the
        // pass that minted or moved it, and the file -- carried out of a
        // vault, or out of a vault whose directory was mid-rotation -- was
        // met as a stranger and went up in the clear (plat3 75401, 75455,
        // hidden kill2 75109).
        if entry.id.is_provisional() {
            let path = relative_path(env, &entry)?;
            let gone = match path {
                // The sweep at the top of the pass has already removed anything
                // with no way back to the root; belt and braces.
                None => true,
                Some(path) => match entry.id.entity_type {
                    // The scan's list first, then the disk: this pass may have
                    // put the file where it now stands itself -- renamed off a
                    // held name as its record was minted -- after the scan
                    // looked. Read from the list alone, the record was
                    // forgotten and its file left with none (plat3 75425;
                    // `specs/drive_file_ownership.md`, E1c).
                    //
                    // And a file standing there that another record owns is
                    // that record's, not this one's: a claimant whose file went
                    // back to its source, with another record's file moved in
                    // after, stood on at that name and vetoed the other's
                    // upload for ever, as it vetoed its (plat3 75400).
                    //
                    // Owned as the scan reads ownership, the server's deleted
                    // records included: a record owns its file until it is
                    // forgotten (scan.rs, kill2 75129). Counting only live
                    // owners, a file the server had just deleted -- and this
                    // device had edited, so it goes up again as new -- read as
                    // nobody's, the never-sent record stood on at its path,
                    // and each vetoed the other's upload every pass for ever
                    // (rig run 1864).
                    EntityType::File => match env.vfs.root() {
                        Some(root) => match env.vfs.fingerprint(&root.join(&path))? {
                            None => !observed.iter().any(|o| o.path == path),
                            // Only when the file is not this record's own too:
                            // a held file and the record waiting for it own one
                            // file on purpose. Nor is its source another owner:
                            // it handed this record the file, and its agreement
                            // still names it.
                            Some(fp) => {
                                let owners = crate::execute::owners_here(env, &root.join(&path), fp, false)?;
                                !owners.iter().any(|o| o.id == entry.id)
                                    && owners.iter().any(|o| Some(o.id) != entry.replaces)
                            }
                        },
                        None => true,
                    },
                    EntityType::Folder => !dirs_on_disk.contains(&path),
                },
            };
            if gone {
                // Created and removed again before it ever reached the server.
                // There is nothing to tell anyone about -- and for a folder that
                // means everything inside it too, or its children are left
                // pointing at a parent that is not there any more.
                env.store.delete_subtree(entry.id)?;
                declined!(traced_id);
            }
        }
        // A file saved where a download is due waits for it: not sent while
        // the download is pending, so the two never race, and a download
        // placed at an escaped name and killed before it was recorded is never
        // sent up under the escape. The download settles it when it lands:
        // the same bytes are its own, handed over by identity; other bytes go
        // aside with their record and are sent from there
        // (`specs/drive_file_ownership.md`, design 1b). Asked fresh each pass,
        // so nothing is left to go stale when the download is withdrawn or the
        // file moves.
        if entry.id.is_provisional()
            && entry.id.entity_type == EntityType::File
            && relative_path(env, &entry)?.is_some_and(|p| awaiting.contains(&p))
        {
            declined!(traced_id);
        }
        // A vault parked on a weak volume follows its own directory when the
        // user renames or moves it (a5's D3 ruling, 2026-09-29): the local
        // placement only -- nothing is asked of the server, and it stays
        // parked. Left where it was, the directory and every file in it stood
        // on the disk with no record claiming them, and nothing would ever
        // scan, send, move or remove them (hidden hostile2 and clean2, the
        // ring rotation carrying the vault's name to a plain folder).
        if entry.id.entity_type == EntityType::Folder
            && entry.status == LocalStatus::OutOfScope
            && parked_on_a_weak_volume.contains(&entry.id)
        {
            if let Some(to) = folders.moves.get(&entry.id) {
                let mut moved = entry.clone();
                moved.synced_placement = Some(to.clone());
                moved.local_name = None;
                env.store.put_entry(&moved)?;
            }
            declined!(traced_id);
        }
        // The same for a file moved from one place under a parked vault to
        // another: its record follows it there, the local placement only.
        // Left behind, the file stood unclaimed in the other vault's folder
        // (hidden hostile2 74418). One moved OUT of the park is the
        // exception below.
        //
        // And for a sealed file held outside its vault and carried back into
        // one: every vault on this disk is parked, so the server is asked
        // nothing here either. Sent as a move, it waited for a folder of the
        // parked vault to reach the server, which it never does, and retried
        // for ever (win kill2 75102).
        if entry.id.entity_type == EntityType::File
            && !entry.id.is_provisional()
            && (entry.is_encrypted
                || entry.local_placement().parent.is_some_and(|p| held_by_park.contains(&p)))
        {
            if let Some(LocalChange::Moved { to_path, .. } | LocalChange::MovedAndEdited { to_path, .. }) =
                scan.change_for(entry.id)
            {
                if let Some(to) = placement_of(to_path, &folder_ids).filter(|to| to.parent.is_some_and(|p| held_by_park.contains(&p))) {
                    let mut moved = entry.clone();
                    moved.synced_placement = Some(to);
                    moved.local_name = None;
                    env.store.put_entry(&moved)?;
                    declined!(traced_id);
                }
            }
        }
        // A sealed file the user carries out of a parked vault is let through
        // to be held there (D1): skipped with its vault, its move was never
        // read, and the file standing outside minted as a new plain one and
        // went up in the clear (D3 on a weak volume, case (b)).
        let leaving_a_parked_vault = entry.id.entity_type == EntityType::File
            && entry.is_encrypted
            && match scan.change_for(entry.id) {
                Some(LocalChange::Moved { to_path, .. } | LocalChange::MovedAndEdited { to_path, .. }) => {
                    placement_of(to_path, &folder_ids).is_some_and(|to| !to.parent.is_some_and(|p| shadowed.contains(&p)))
                }
                _ => false,
            };
        if !leaving_a_parked_vault
            && (entry.status == LocalStatus::OutOfScope
            || entry.local_placement().parent.is_some_and(|p| shadowed.contains(&p))
            || entry
                .local_placement()
                .parent
                .is_some_and(|p| parked_without_a_directory.contains(&p)))
        {
            declined!(traced_id);
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
        //
        // Only a park THIS device made. A peer's park is the peer's to finish
        // or put back: with no op of its own for the entity this device cannot
        // tell an abandoned park from one whose finisher is a request away,
        // and putting it back reverts a cycle the peer is breaking (the
        // reset's C10). It is left standing -- the entity keeps the scratch
        // name here too, hidden as every internal name is -- until the
        // parking device finishes it or rescues it itself. A park with no tag
        // was minted before names were tagged and cannot say whose it is; it
        // keeps the old reading (rescued by any device with no op for it),
        // which still reverts a peer's live untagged park -- a window that
        // closes once every device runs a tagging build.
        //
        // Stated residual: a device that parks and never returns with the
        // same store -- uninstalled, a disk lost, a store reset or re-created,
        // a new enrollment -- leaves the entity under the scratch name, and
        // no peer will put it back. Closing that needs the server to say a
        // device is gone, which is a separate piece of work.
        let parked_by_this_device = match crate::order::park_tag_of(&entry.remote.name) {
            Some(tag) => env.store.own_park_tag()?.as_deref() == Some(tag),
            None => true,
        };
        if jd_vfs::is_internal(&entry.remote.name)
            && parked_by_this_device
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
                    //
                    // The agreed PARENT may be gone as well: trashed on the
                    // server and forgotten here, with only this agreement
                    // still naming it. A put-back into it cannot succeed, and
                    // against a server that refuses in prose alone it is worse
                    // than refused -- "that folder is in the trash" reads as
                    // possibly-about-the-name, the move parks the folder as its
                    // last resort, is refused again, and is withdrawn with the
                    // park standing; every device then rescues that park into
                    // the same trashed parent, minting a fresh scratch name
                    // each pass. Estate seed 16062180, three devices, for
                    // ever. So the put-back goes where the server has the
                    // folder now, under the agreed name, when the agreed
                    // parent is no longer a live folder in this store.
                    let live = |p: i64| -> Result<bool, ExecError> {
                        Ok(env
                            .store
                            .get_entry(EntityId::folder(p))?
                            .is_some_and(|f| !f.remote_deleted))
                    };
                    let parent = match agreed.parent {
                        None => None,
                        Some(p) if live(p)? => Some(p),
                        Some(_) => entry.remote.parent,
                    };
                    let taken: std::collections::HashSet<String> = env
                        .store
                        .every_entry()?
                        .into_iter()
                        .filter(|e| {
                            e.id != entry.id
                                && !e.remote_deleted
                                && !e.id.is_provisional()
                                && e.remote.parent == parent
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
                            "parent": parent, "name": wanted,
                            // The disk has not moved; the pass brings it along.
                            "disk_follows": true,
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
                    declined!(traced_id);
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
            declined!(traced_id);
        }
        // Wearing a scratch name on the server, with no agreed placement here
        // to read in its place (see `observed_remote`): some device is
        // mid-rename, and the only thing that can be done with it is the
        // wrong one -- landing it under the scratch name, where the local
        // walk cannot see it and the next pass reads it as deleted (the
        // reset's C11). It waits for the park to end.
        if entry.waiting_on_a_park() {
            declined!(traced_id);
        }
        // An encrypted file with no key for it here. Same shape as above and for
        // a sharper reason: falling through would decide about it in the
        // plaintext domain — the server's name is a placeholder and its hash is
        // of the ciphertext — and plan a download that writes bytes nobody can
        // read to a path that is not the file's name. A remote delete still gets
        // through, so a file that goes away while its key is outstanding does
        // not sit here forever.
        //
        // A FOLDER established here is not that: its name and its parent are
        // plaintext on the server, and moving its directory needs no key. A
        // vault locked after it was open keeps its directory, and skipped
        // here it never followed a peer's rename or move -- its old name was
        // then free for anyone (B-LV). Its placement is reconciled like any
        // folder's; what it holds inside waits for the key as before.
        let locked_folder = entry.status == LocalStatus::PendingKey
            && entry.id.entity_type == EntityType::Folder
            && entry.synced_placement.is_some();
        if entry.status == LocalStatus::PendingKey && !entry.remote_deleted && !locked_folder {
            declined!(traced_id);
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
        // The user moved this file into a vault this device has no key for, and
        // the bytes are already claimed at their new path by an entry waiting
        // for that key. There is nothing at the agreed path any more, which
        // reads as the user deleting it -- and acting on that would trash the
        // last copy anyone else can reach, in favour of a replacement this
        // device cannot upload yet. So the source waits for the replacement to
        // LAND, which is stricter than the keyed path: that one trashes first
        // and re-uploads after. A remote delete still gets through, exactly as
        // it does for the three skips above.
        if !entry.remote_deleted && held_for_replacement.contains(&entry.id) {
            // The hold rests on the source path being EMPTY -- that is what
            // reads as the user having moved the file in. When the scan finds
            // this entry's own file on this disk anyway, at its path or moved
            // to another, the premise is gone: the user kept a copy outside
            // the vault, or brought the bytes back out under a new name and
            // saved something else at the vault path. The bytes waiting inside
            // are then a new file, not this one's replacement, and holding
            // this one hostage to them leaves a real file claimed by nobody --
            // the scan pairs it with this entry every pass, the pairing is
            // thrown away here, and nothing ever scans, sends, moves or
            // removes it. Estate seed 6091570.
            //
            // So the hold lapses: the claimant stands on its own, and this
            // entry goes on as the file it is.
            let alive = match entry.id.entity_type {
                EntityType::File => matches!(
                    scan.change_for(entry.id),
                    Some(c) if !matches!(c, crate::scan::LocalChange::Deleted)
                ),
                // Folders are absent from the file scan; the folder scan
                // answers the same question -- standing at its path, or found
                // somewhere else by the files inside it.
                EntityType::Folder => {
                    folders.present.contains(&entry.id) || folders.moves.contains_key(&entry.id)
                }
            };
            if !alive {
                follow_the_server(env, &entry)?;
                declined!(traced_id);
            }
            for mut claimant in all_entries(env)?
                .into_iter()
                .filter(|e| e.replaces == Some(entry.id))
            {
                claimant.replaces = None;
                env.store.put_entry(&claimant)?;
            }
        }
        if !entry.remote_deleted && written_off.contains(&entry.id) {
            declined!(traced_id);
        }

        // Something created here that the server has not named yet. There is no
        // remote side to compare against, so it stays a creation every pass
        // until the create actually lands. Falling through to the ordinary path
        // would read "no agreement" as "the server made this", and plan a
        // download of a file that exists nowhere but this disk.
        if entry.id.is_provisional() {
            // The sweep above has already forgotten every provisional entry
            // with nothing behind it, so there is a file here.
            let Some(path) = relative_path(env, &entry)? else {
                declined!(traced_id);
            };
            // Written in a vault and carried out of it before it was ever
            // sent: the same file, so held as a sealed file taken out of its
            // vault is (owner decision D1). Nothing is sent; it waits here
            // until it goes back.
            if held_and_never_sent(env, &entry)? {
                say_it_was_never_sent(env, &entry)?;
                declined!(traced_id);
            }
            // Carrying a held record's disk identity: very likely that held
            // file, moved and edited in one pass (scan rule 4 reads it as a
            // creation). Sent, it would publish a sealed file's bytes; so it is
            // not sent while the hold stands. In a vault the hold ends as soon
            // as the held record's delete lands, and it goes up sealed then; in
            // a plain folder it waits with the held record (see there). An
            // inode funds this wait and nothing else.
            //
            // A hard link is the one other way to carry that identity: the held
            // file still standing where it agrees. In a vault that second file
            // goes up sealed at once -- harmless -- and only waits while the
            // held record is going (its delete in flight); in a plain folder it
            // is a copy out of the vault and waits like any other.
            if let Some(o) = observed.iter().find(|o| o.path == path) {
                if let Some(held) = held_owner_of(env, o)? {
                    let in_a_vault = parent_is_encrypted(env, entry.remote.parent)?;
                    let going = !held_file_stands(env, &held, &observed)?
                        || env.store.entities_with_open_ops()?.contains(&held.id);
                    if !in_a_vault || going {
                        declined!(traced_id);
                    }
                }
            }
            // Never sent under a sealed file's name this disk cannot vouch for
            // (the name hold, below).
            if !entry.is_encrypted && sealed_names_at_risk.contains(&jd_vfs::comparison_key(&entry.remote.name, &env.vfs.personality())) {
                say_a_sealed_name_is_held(env, &entry, &entry.remote.name)?;
                names_held.insert(entry.id);
                declined!(traced_id);
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
            declined!(traced_id);
        }

        let mut local = match scan.change_for(entry.id) {
            Some(change) => local_delta(change, resolve),
            // Folders are absent from the file scan, so what happened to one
            // locally is worked out separately.
            None => folder_delta(&entry, &folders),
        };
        // Only its placement: a locked vault's directory gone from this disk
        // is not read as the user deleting the vault until the key is back
        // and its contents can be accounted for, as before.
        if locked_folder && !entry.remote_deleted && matches!(local, Delta::Deleted) {
            declined!(traced_id);
        }
        // A plain folder renamed here onto a name the server still gives to a
        // vault parked on this weak disk (a5's ruling on the D3 follow,
        // 2026-09-29): the park sends nothing, so that rename would be refused
        // for good and the folder left with no directory. It waits: the
        // folder keeps its directory under the new name here, its server name
        // stays, and the user is told. Asked again every pass of the server's
        // current state: the name freed -- a peer renamed the vault -- and
        // the rename is sent that pass.
        if entry.id.entity_type == EntityType::Folder && entry.status != LocalStatus::OutOfScope {
            let waiting = env
                .store
                .open_issues()?
                .into_iter()
                .filter(|i| i.kind == NAME_HELD_BY_A_PARKED_VAULT && i.entity == Some(entry.id))
                .collect::<Vec<_>>();
            let agreed_parent = entry.synced_placement.as_ref().map(|p| p.parent);
            // Only while the server has not moved it either: a rename from
            // both sides at once is the ordinary reconciliation's to settle,
            // and a wait in front of it held the server's move off for good.
            let server_still_agrees = entry.synced_placement.as_ref() == Some(&entry.remote);
            // Deleted on the server, it waits for nothing: the delete goes
            // through as for any folder, and the wait's word is withdrawn.
            if entry.remote_deleted {
                for issue in &waiting {
                    env.store.dismiss_issue(issue.issue_id)?;
                }
            }
            match &local {
                Delta::Moved { to } if agreed_parent == Some(to.parent) && server_still_agrees => {
                    if let Some(vault) = parked_vault_holding(env, to)? {
                        let mut held = entry.clone();
                        held.local_name = Some(to.name.clone());
                        env.store.put_entry(&held)?;
                        if waiting.is_empty() {
                            env.store.raise_issue(
                                Some(entry.id),
                                NAME_HELD_BY_A_PARKED_VAULT,
                                &if vault.is_encrypted {
                                    format!(
                                        "{} cannot be renamed {} on the server: that is the name of your vault {}, \
                                         which this drive cannot sync. Rename the folder, or move the vault to a \
                                         drive that can keep track of its files.",
                                        entry.remote.name, to.name, vault.remote.name
                                    )
                                } else {
                                    format!(
                                        "{} cannot be renamed {} on the server yet: the folder {} still has that \
                                         name there, and cannot give it up while a vault this drive cannot sync \
                                         holds the name it wants. Rename the folders, or move the vault to a drive \
                                         that can keep track of its files.",
                                        entry.remote.name, to.name, vault.remote.name
                                    )
                                },
                                (env.now_ms)() as i64,
                            )?;
                        }
                        declined!(traced_id);
                    }
                }
                // Its own directory back at its agreed name, by the directory's
                // identity -- the user traded the names back: the wait is over,
                // and so is the name it wore for it (hidden clean2 74029).
                Delta::None
                    if !waiting.is_empty()
                        && entry.synced_fingerprint.map(|f| f.file_id).filter(|id| *id != 0).is_some_and(|mine| {
                            // Its agreed name, under its parents where
                            // their directories stand.
                            let mut home = entry.clone();
                            home.local_name = None;
                            home.stands_at = None;
                            relative_path(env, &home)
                                .ok()
                                .flatten()
                                .is_some_and(|p| dir_identity.get(&p) == Some(&mine))
                        }) =>
                {
                    for issue in waiting {
                        env.store.dismiss_issue(issue.issue_id)?;
                    }
                    let mut home = entry.clone();
                    home.local_name = None;
                    env.store.put_entry(&home)?;
                    declined!(traced_id);
                }
                Delta::None if !waiting.is_empty() => {
                    let target = entry
                        .local_name
                        .clone()
                        .zip(agreed_parent)
                        .map(|(name, parent)| Placement { parent, name });
                    match target {
                        Some(to) if server_still_agrees && parked_vault_holding(env, &to)?.is_some() => declined!(traced_id),
                        Some(to) => {
                            for issue in waiting {
                                env.store.dismiss_issue(issue.issue_id)?;
                            }
                            // The name it wore while it waited comes off here, so
                            // the move reads as the rename it is and not as the
                            // disk keeping a spelling; the move's landing writes
                            // the new agreement (`a_waiting_rename_lands_once_the_vaults_name_is_free`).
                            entry.local_name = None;
                            local = Delta::Moved { to };
                        }
                        None => {
                            for issue in waiting {
                                env.store.dismiss_issue(issue.issue_id)?;
                            }
                        }
                    }
                }
                _ => {}
            }
        }
        // A file moved INTO a vault slot a held file still holds on the
        // server goes there under a conflict name (`clear_of_a_held_name`).
        if entry.id.entity_type == EntityType::File {
            let to_path = match scan.change_for(entry.id) {
                Some(crate::scan::LocalChange::Moved { to_path, .. })
                | Some(crate::scan::LocalChange::MovedAndEdited { to_path, .. }) => Some(to_path.clone()),
                _ => None,
            };
            if let (Some(to_path), Delta::Moved { to } | Delta::MovedAndEdited { to, .. }) = (to_path, &mut local) {
                *to = clear_of_a_held_name(env, &to_path, to, Some(entry.id), &leaving_a_vault)?;
            }
        }
        // A plain file on a disk with no file identities is not sent under the
        // name of a sealed file this disk cannot vouch for (a5's name hold,
        // 2026-09-30). A sealed file traded out of its vault and the vault's
        // directory renamed in the same breath leave nothing -- no contents,
        // no id -- to say which directory is the vault, and the plain file
        // the user put in it reads as a plain folder's under the sealed
        // file's real name (FAT kill2 75105, 75112; plat3 75406). The move
        // goes up, the name does not: the server keeps the name it had, the
        // file wears the new one here, and the user is told. Held back, the
        // move left its record on the path it had left, and a new file there
        // fought it for the name for good (win plat3 75428). Naming works the
        // local name out afresh every pass, so the scan reads the rename again
        // each pass and this decides it again: the sealed file home, the name
        // goes up as the ordinary rename it is. A file never sent is held
        // where its creation is planned, above.
        let mut racing_a_peer = false;
        if entry.id.entity_type == EntityType::File && !entry.is_encrypted {
            let personality = env.vfs.personality();
            let at_risk = |n: &str| sealed_names_at_risk.contains(&jd_vfs::comparison_key(n, &personality));
            let same = |a: &str, b: &str| jd_vfs::comparison_key(a, &personality) == jd_vfs::comparison_key(b, &personality);
            if let Delta::Moved { to } | Delta::MovedAndEdited { to, .. } = &mut local {
                // Into a vault it goes up sealed, name and all: nothing to hold.
                if !same(&to.name, &entry.remote.name) && at_risk(&to.name) && !parent_is_encrypted(env, to.parent)? {
                    entry.local_name = Some(to.name.clone());
                    // Moved on the server meanwhile: a rename from both sides,
                    // the ordinary race's to settle (a5's R2) -- the server's
                    // move wins, the user is told as for any race, and the file
                    // is carried from the name it wears. Nothing of this name
                    // is sent: the race only decides.
                    let server_moved = entry.synced_placement.as_ref() != Some(&entry.remote);
                    if server_moved {
                        racing_a_peer = true;
                    } else {
                        say_a_sealed_name_is_held(env, &entry, &to.name)?;
                        names_held.insert(entry.id);
                        to.name = entry.remote.name.clone();
                    }
                    env.store.put_entry(&entry)?;
                    // A rename alone leaves nothing to send: the file stays in
                    // its slot, wearing the name here. Read as a respelling it
                    // would lose that name to the server's spelling.
                    if !server_moved && entry.synced_placement.as_ref() == Some(&*to) {
                        local = match std::mem::replace(&mut local, Delta::None) {
                            Delta::MovedAndEdited { content, .. } => Delta::Edited { content },
                            _ => Delta::None,
                        };
                    }
                }
            }
        }
        // A move that arrives at the slot the agreement already puts it in is
        // not a move. Three ways that happens, and what the record needs from
        // each is different.
        //
        // The scan works in paths and the agreement works in placements, and
        // the two come apart when a FOLDER has been displaced: every file
        // inside it is at a new path while its parent and name -- which is all
        // a placement is -- have not changed at all. Read as a move it becomes
        // a request to the server to put the file exactly where the server
        // already has it: accepted, applied, and derived again from the same
        // disk on the next pass, for as long as the folder stays where it is.
        // One file, one round-trip, every pass, and a device that is never
        // quiet. The file wears the name the record says it wears -- the LOCAL
        // name, where one is recorded, because that is the spelling a volume
        // that decomposes or escapes actually holds -- and the record is right.
        //
        // The second is a RESPELLING the server cannot grant. Two spellings of
        // one word are two files on the server and one slot on a volume that
        // folds them, and both are legal: a device that composes on the way
        // out can upload the composed twin of a name minted decomposed. So this
        // device can end up agreeing on one spelling while its disk holds the
        // other, and the scan reports a rename the user never made. Pushed, it
        // is refused for ever -- the byte-name belongs to a different live
        // file, so the answer is `name_taken`, the op is dropped, the record is
        // untouched, and the next pass derives exactly the same move. Two
        // estate seeds sat there, one raising an issue on every pass. There is
        // nothing to send: the file is where the agreement says, only its
        // spelling differs, and this filesystem cannot tell the two apart. So
        // the spelling is written down as what it is -- a local name, the field
        // for exactly this -- and the placement stays the server's.
        //
        // The third is the way back: the disk has returned to the server's own
        // spelling. Not a rename either, but the mapping that said otherwise
        // has to come off the record, because a record that still names the
        // old spelling pairs the file by content alone, and the next edit at
        // that path reads as a deletion.
        //
        // A respell onto a FREE name is none of these. It is an ordinary rename
        // and still goes up: the user renaming `report.txt` to `Report.txt` on
        // a case-folding volume means it, and the server can grant it.
        // (A rename held off a sealed name racing a peer's is a rename, not
        // a respelling: it goes to the race.)
        let local = match local {
            Delta::Moved { .. } | Delta::MovedAndEdited { .. } if racing_a_peer => local,
            Delta::Moved { ref to } | Delta::MovedAndEdited { ref to, .. } => {
                match same_slot_spelling(env, &entry, to, &busy)? {
                    Some(spelling) => {
                        if spelling != entry.local_name {
                            let mut respelled = entry.clone();
                            respelled.local_name = spelling;
                            env.store.put_entry(&respelled)?;
                        }
                        match local {
                            Delta::MovedAndEdited { content, .. } => Delta::Edited { content },
                            _ => Delta::None,
                        }
                    }
                    None => local,
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
        // The directory the file stands in now, by identity: the directory
        // the scan resolved its destination parent to.
        let dir_of_folder = |pid: Option<i64>| -> Option<u64> {
            match pid {
                None => None,
                Some(pid) => folder_ids
                    .iter()
                    .find(|(_, id)| **id == pid)
                    .and_then(|(path, _)| dir_identity.get(path))
                    .copied(),
            }
        };
        let standing_in = match &local {
            Delta::Moved { to } | Delta::MovedAndEdited { to, .. } if entry.id.entity_type == EntityType::File => {
                let agreed_parent = entry
                    .synced_placement
                    .as_ref()
                    .map(|p| p.parent)
                    .unwrap_or(entry.remote.parent);
                StandingIn {
                    file: dir_of_folder(to.parent),
                    agreed_parent: dir_of_folder(agreed_parent),
                }
            }
            _ => StandingIn::default(),
        };
        if let Some(crossing) = crossing_a_vault_edge(env, &entry, &local, standing_in)? {
            if crossing == Crossing::NotADrag {
                env.store.raise_issue(
                    Some(entry.id),
                    "withdrawn",
                    "this file is still in the folder it was in; the folder was misread and \
                     nothing is moved or converted until the folder scan reads it right",
                    (env.now_ms)() as i64,
                )?;
                declined!(traced_id);
            }
            // A sealed FILE on its way out is held (owner decision D1): the
            // user's move stands on this disk -- the record's agreed placement
            // is where the file now is -- and nothing is asked of the server,
            // which keeps the sealed copy where it was. The two sides of the
            // record now disagree across the vault's edge, which is what
            // `held_outside_its_vault` reads from here on.
            if crossing == Crossing::OutOfReach && entry.id.entity_type == EntityType::File {
                let to = match &local {
                    Delta::Moved { to } | Delta::MovedAndEdited { to, .. } => to.clone(),
                    _ => declined!(traced_id),
                };
                let mut held = entry.clone();
                held.synced_placement = Some(to);
                held.local_name = None;
                env.store.put_entry(&held)?;
                say_it_is_held(env, &held)?;
                declined!(traced_id);
            }
            if crossing == Crossing::OutOfReach {
                // A vault folder on its way out. Say so, once, and do not plan
                // the move: the server refuses it, and asking again next pass
                // and every pass after that is the loop this whole branch
                // exists to end. Nothing is undone -- the folder stays where
                // the user dragged it, and the server keeps its encrypted copy
                // exactly where it was.
                // Two folders come this way and want different words. A
                // folder INSIDE a vault is kept here, and the only ways out
                // are real ones: back into the vault, or its files downloaded
                // in the browser and uploaded elsewhere -- nothing on the
                // platform turns an encrypted folder back into plaintext, so
                // no protection-level change is offered. A vault's own root
                // cannot go into a plain folder at all -- the server keeps a
                // vault at the drive root or inside another vault.
                let agreed_parent = entry
                    .synced_placement
                    .as_ref()
                    .map(|p| p.parent)
                    .unwrap_or(entry.remote.parent);
                let detail = if parent_is_encrypted(env, agreed_parent)? {
                    let name = match &local {
                        Delta::Moved { to } | Delta::MovedAndEdited { to, .. } => to.name.clone(),
                        _ => entry.effective_local_name().to_string(),
                    };
                    format!(
                        "{name} is encrypted and stays in its vault on the server. It is kept only on \
                         this device. Move it back into the vault to sync it again, or download its \
                         files in the browser and upload them where you want them."
                    )
                } else {
                    "this folder is a vault, and a vault can sit only at the drive root or \
                     inside another vault; it stays on the server where it was"
                        .to_string()
                };
                // One sentence per folder: renamed again while held, the new
                // name replaces the old one.
                for issue in env.store.open_issues()? {
                    if issue.kind == "withdrawn"
                        && issue.entity == Some(entry.id)
                        && issue.detail != detail
                        && issue.detail.contains("is encrypted and stays in its vault on the server.")
                    {
                        env.store.dismiss_issue(issue.issue_id)?;
                    }
                }
                env.store.raise_issue(Some(entry.id), "withdrawn", &detail, (env.now_ms)() as i64)?;
                declined!(traced_id);
            }
            // A record never sent has no server copy for a claimant to hold
            // and replace, and it has already crossed: the follow above made
            // it the vault's where its file stands, clear of any held name.
            // Given a claimant too, or its placement written again from the
            // raw move, two records competed for one name inside the vault,
            // each vetoing the other's upload for ever (plat3 75400).
            if entry.id.is_provisional() && entry.id.entity_type == EntityType::File {
                declined!(traced_id);
            }
            {
                // The conversion is a claimant: a record in the vault that owns
                // the file from this moment and holds the source's server copy
                // until its own upload lands, when the source is trashed. With
                // the key here it uploads at once; without it, it waits.
                //
                // With the key this used to trash the source and forget it,
                // and let the next scan find the bytes at their new path as a
                // creation. Until that scan the file had no record, and a swap
                // in the gap carried it onto another record's path, where it
                // read as that record's edit (kill2 75112, 75116, 75119;
                // `specs/drive_file_ownership.md`, E1a).
                //
                // Without the key, this device cannot do the re-upload
                // either -- and it must not trash the server's copy on the
                // strength of a conversion it cannot perform.
                //
                // Saying so on the SOURCE does not work, and the shape of the
                // failure is worth keeping: a status meaning "the file went
                // somewhere I cannot follow" was cleared by the name resolver
                // on the very next pass, because that asks whether the ENTRY is
                // encrypted and the entry is still recorded in the plaintext
                // folder it came from. Set here, cleared there, every pass, for
                // ever -- and all the while nothing owned the bytes at their
                // new path: no scan adopted them, no upload sent them, no
                // delete removed them, and the next thing to want that name
                // would have written straight over them.
                //
                // So the memory goes on the record that is TRUE. The file is
                // inside the vault now, so it gets an entry that says exactly
                // that, at the path it is actually at, already waiting for a
                // key -- the same bargain the creation path makes for a file
                // the user saves into a vault this device cannot open. What it
                // carries beyond that is where it came from, which is what
                // holds the source's server copy until this upload lands.
                // The destination, from the same delta the crossing was read
                // from: only a move can cross an edge, so this always matches.
                let to = match &local {
                    Delta::Moved { to } | Delta::MovedAndEdited { to, .. } => to,
                    _ => declined!(traced_id),
                };
                // Only a PLAINTEXT entry can get here: `crossing_a_vault_edge`
                // answers Convert only for a move INTO a vault, and an
                // encrypted entry is never moving into one across an edge. The
                // mint below would be wrong for one twice over -- `is_encrypted`
                // hardcoded true, and a wait for a key the move does not need
                // -- so it is asserted rather than assumed.
                debug_assert!(
                    !entry.is_encrypted,
                    "an encrypted entry reached the crossing mint; the \
                     PendingKey skip above is meant to have taken it"
                );
                if !held_for_replacement.contains(&entry.id) {
                    // The claimant is the same kind of thing as the source. A
                    // FILE claimant for a folder source has nothing at its
                    // path, is swept on the next pass, and is minted again on
                    // the one after -- the folder never held, never sent,
                    // never told about, and the directory it stands in claimed
                    // by nothing. A folder claimant stands at the directory,
                    // and the files inside it then cross the edge one by one
                    // as moves under it, each minting its own claimant.
                    let claimant = EntityId {
                        entity_type: entry.id.entity_type,
                        server_id: env.store.next_provisional_id()?,
                    };
                    let mut waiting = blank(claimant, to);
                    waiting.is_encrypted = true;
                    if env.vault.is_none() {
                        waiting.status = LocalStatus::PendingKey;
                    }
                    waiting.replaces = Some(entry.id);
                    // The file standing at `to` is the source's own file,
                    // moved there. The claimant is the record that will send
                    // it, so the file is handed over: two records owning one
                    // file is not a hard link, and nothing could tell them
                    // apart.
                    waiting.own_file = entry.own_file.filter(|_| claimant.entity_type == EntityType::File);
                    env.store.put_entry(&waiting)?;
                    if waiting.own_file.is_some() {
                        let source = Entry { own_file: None, ..entry.clone() };
                        env.store.put_entry(&source)?;
                        follow_the_server(env, &source)?;
                        declined!(traced_id);
                    }
                }
                follow_the_server(env, &entry)?;
                declined!(traced_id);
            }
        }
        // Measured from the agreement, using the freshest remote state we hold.
        // For an entity the feed did not mention this pass that is what we
        // recorded last time — which still reports an unfinished change, and is
        // the entire reason this is not measured from the last observation.
        // A file held outside its vault: the server's side waits. Its placement
        // there is not a move for this disk to follow, so the remote delta is
        // measured as though the agreement stood where the server keeps it --
        // content and deletion only. Edits wait on both sides: a local edit
        // is never sent (the server would take it into the vault, or refuse
        // the plain folder) and a server edit is not written over the copy
        // here; the agreed contents are untouched, so both are still seen when
        // the file goes back. What does go through: the user moving it again
        // (into a vault, planned from where the server keeps it) or deleting
        // it, and the server deleting it -- unedited here, an ordinary delete;
        // edited here, the copy is kept on this device only and never sent.
        // Back at exactly the server's placement, it is agreed and done.
        if entry.id.entity_type == EntityType::File {
            let held = held_outside_its_vault(env, &entry)?;
            if held {
                // Moved again outside any vault -- renamed where it stands, or
                // into another plain folder -- is this disk's side alone: the
                // record follows the file, the issue follows the name, and the
                // server is asked nothing (planned, it was a move across the
                // edge the server refuses on every pass). Edited on the way, it
                // is the same file: the edit waits with it, as any edit to a
                // held file does, and the next scan reads it where it stands.
                if let Delta::Moved { to } | Delta::MovedAndEdited { to, .. } = &local {
                    if !parent_is_encrypted(env, to.parent)? {
                        let mut moved = entry.clone();
                        moved.synced_placement = Some(to.clone());
                        moved.local_name = None;
                        env.store.put_entry(&moved)?;
                        say_it_is_held(env, &moved)?;
                        declined!(traced_id);
                    }
                }
                // Read as deleted while a new file carrying this record's disk
                // identity stands outside any vault: very likely the held file
                // itself, moved and edited in one pass. The sealed copy is not
                // trashed for it, and that file is not sent (see the
                // provisional branch): both wait, the user told what the file
                // is now called. In a vault, or with no such file, the delete
                // goes through as any other.
                if matches!(local, Delta::Deleted) {
                    if entry.own_file_id().is_some() {
                        if let Some(o) = observed.iter().find(|o| entry.owns(o.fingerprint.identity())) {
                            let in_a_vault = match placement_of(&o.path, &folder_ids) {
                                Some(p) => parent_is_encrypted(env, p.parent)?,
                                None => false,
                            };
                            if !in_a_vault {
                                let name = o.path.rsplit('/').next().unwrap_or(&o.path).to_string();
                                if entry.remote_deleted {
                                    say_it_was_deleted_on_the_server(env, &entry, &name)?;
                                } else {
                                    say_it_waits(env, &entry, &name)?;
                                }
                                declined!(traced_id);
                            }
                        }
                    }
                }
                // Back at exactly the server's placement: agreed, and an edit
                // made on the way is an ordinary edit in the vault next pass.
                if let Delta::Moved { to } | Delta::MovedAndEdited { to, .. } = &local {
                    if *to == entry.remote {
                        let mut home = entry.clone();
                        home.synced_placement = Some(entry.remote.clone());
                        env.store.put_entry(&home)?;
                        declined!(traced_id);
                    }
                }
                let mut agreed_there = entry.clone();
                agreed_there.synced_placement = Some(entry.remote.clone());
                let remote = remote_delta(&agreed_there, &observed_remote(&entry));
                let go = match (&local, &remote) {
                    (Delta::Moved { .. } | Delta::MovedAndEdited { .. } | Delta::Deleted, _) => true,
                    (Delta::None, Delta::Deleted) => true,
                    (_, Delta::Deleted) => {
                        say_it_was_deleted_on_the_server(env, &entry, entry.effective_local_name())?;
                        false
                    }
                    _ => false,
                };
                if go {
                    // Gone from this disk while the server's copy has changed:
                    // the ordinary answer restores the server's copy, and it is
                    // restored where the server keeps it -- in the vault. The
                    // hold is over; written down first, because the download
                    // lands wherever the record says the file lives, and a
                    // sealed file's new contents must not land in a plain
                    // folder.
                    if matches!(local, Delta::Deleted)
                        && matches!(remote, Delta::Edited { .. } | Delta::MovedAndEdited { .. })
                    {
                        let mut released = agreed_there.clone();
                        released.local_name = None;
                        env.store.put_entry(&released)?;
                    }
                    // Planned from where the server keeps it: that is the copy
                    // any move or trash acts on.
                    let depth = depth_for(env, &entry)?;
                    inputs.push(RoundInput { entry: agreed_there, local, remote, depth });
                }
                declined!(traced_id);
            }
        }
        let remote = remote_delta(&entry, &observed_remote(&entry));
        if local.is_none() && remote.is_none() {
            declined!(traced_id);
        }
        let depth = depth_for(env, &entry)?;
        inputs.push(RoundInput {
            entry,
            local,
            remote,
            depth,
        });
    }

    for issue in env.store.open_issues()? {
        if issue.kind == NAME_OF_A_SEALED_FILE_HELD && !issue.entity.is_some_and(|e| names_held.contains(&e)) {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }

    // ---- decide, journal, do -------------------------------------------------
    let synced_total = env.store.synced_count()?;
    // Where every folder sits now, on each side, for the planner to order
    // moves that change ancestry. Every tracked folder, not only this round's
    // inputs: a chain runs through folders nothing is happening to. The local
    // side prefers what the scan found over the agreement, because a folder
    // the user moved is where they put it.
    let mut parents = crate::order::FolderParents::default();
    for e in all_entries(env)? {
        if e.id.entity_type != EntityType::Folder || e.remote_deleted {
            continue;
        }
        parents.local.insert(e.id.server_id, e.local_placement().parent);
        parents.remote.insert(e.id.server_id, e.remote.parent);
    }
    for input in &inputs {
        if input.entry.id.entity_type != EntityType::Folder {
            continue;
        }
        if let Some(p) = input.local.placement() {
            parents.local.insert(input.entry.id.server_id, p.parent);
        }
    }
    // Folders whose trash on the server is already in the journal from an
    // earlier pass: busy, so out of this round, and just as decided.
    let trash_already_queued: std::collections::HashSet<i64> = env
        .store
        .queued_ops()?
        .into_iter()
        .filter(|op| op.kind == "trash_remote" && op.entity.entity_type == EntityType::Folder)
        .map(|op| op.entity.server_id)
        .collect();
    out.round = run_round(inputs, synced_total, ctx, policy, &parents, &trash_already_queued);
    if let Some(t) = trace.as_mut() {
        t.round_ended_ms = (env.now_ms)();
        for op in &out.round.plan.ops {
            t.plan.push((crate::trace::name(op.entity), format!("{:?}", op.action)));
        }
    }
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
    // Entities naming is giving up this pass. Their park runs first, and a
    // park that cannot finish (it waits for something inside to be moved, or
    // followed) writes no status -- so nothing journalled after it may place
    // them on this disk in the same pass: the move would land on the very
    // name naming refused, and make room by moving aside whatever holds it,
    // the user's own folder included (B5, B6).
    let parking: std::collections::HashSet<EntityId> =
        out.naming.give_up_local_copy.iter().map(|(id, _)| *id).collect();
    let refused: HashMap<EntityId, Vec<jd_vfs::UnsyncableReason>> = {
        let mut m: HashMap<EntityId, Vec<jd_vfs::UnsyncableReason>> = HashMap::new();
        for (id, reason) in out.naming.give_up_local_copy.iter().chain(out.naming.held_destinations.iter()) {
            m.entry(*id).or_default().push(reason.clone());
        }
        m
    };
    // A held destination (a keyless entry whose server name this disk refuses
    // there) is said once and lifted when it ends; the move stays off the disk
    // meanwhile, by the filter below and the executor's guard.
    {
        let held: HashMap<EntityId, String> = out
            .naming
            .held_destinations
            .iter()
            .filter_map(|(id, _)| env.store.get_entry(*id).ok().flatten().map(|e| (*id, e.remote.name)))
            .collect();
        for issue in env.store.open_issues()? {
            if issue.kind != crate::naming::RENAME_HELD {
                continue;
            }
            let still = issue.entity.and_then(|id| held.get(&id)).is_some_and(|n| *n == issue.detail);
            if !still {
                env.store.dismiss_issue(issue.issue_id)?;
            }
        }
        let open: std::collections::HashSet<(EntityId, String)> = env
            .store
            .open_issues()?
            .into_iter()
            .filter(|i| i.kind == crate::naming::RENAME_HELD)
            .filter_map(|i| Some((i.entity?, i.detail)))
            .collect();
        for (id, name) in &held {
            if !open.contains(&(*id, name.clone())) {
                env.store.raise_issue(Some(*id), crate::naming::RENAME_HELD, name, (env.now_ms)() as i64)?;
            }
        }
    }
    if !out.naming.renames.is_empty() || !out.naming.give_up_local_copy.is_empty() {
        let mut ops: Vec<crate::order::PlannedOp> = out
            .naming
            .renames
            .iter()
            .filter(|(id, _, _)| !parking.contains(id))
            .map(|(id, from, to)| crate::order::PlannedOp {
                entity: *id,
                action: crate::reconcile::Action::ApplyLocalMove { to: to.clone() },
                stage: crate::order::Stage::Move,
                rank: 0,
                from: Some(from.clone()),
            })
            .collect();
        // A move planned before this verdict existed is now wrong, and it is
        // ahead of the park in the journal -- so it would run first, land on the
        // occupied name, and evict the very file the park exists to protect.
        // The decision has to reach back and cancel it.
        if !parking.is_empty() {
            // This also keeps the batch to one park per entity ACROSS passes: a
            // park still retrying from last pass is dropped here before the
            // fresh one is journalled. The cost is that dropping and requeueing
            // resets the attempt count, so a park that keeps retrying never
            // escalates its backoff -- it simply tries once per pass, at
            // whatever cadence passes run.
            for op in env.store.queued_ops()? {
                if parking.contains(&op.entity) {
                    env.store.drop_op(op.op_id)?;
                }
            }
        }
        // Giving up a local copy frees a name too, and it belongs in the same
        // batch for the same reason: it is decided before the scan, so it
        // cannot be part of the round, and whoever is waiting on the name
        // should not have to wait a further pass for it.
        ops.extend(out.naming.give_up_local_copy.iter().map(|(id, reason)| {
            crate::order::PlannedOp {
                entity: *id,
                action: crate::reconcile::Action::UnmaterializeAndPark {
                    reason: reason.clone(),
                },
                stage: crate::order::Stage::Delete,
                rank: 0,
                from: None,
            }
        }));
        let freeing = crate::order::Plan {
            ops,
            broken_cycles: Vec::new(),
        };
        journal(env.store, &freeing, key_for)?;
    }
    // The round of this same pass planned from the server's change before
    // naming's verdict, so it can hold a local placement for an entity being
    // parked; the cancellation above reaches only ops already queued. Only a
    // placement at the name naming refused goes: a move the round planned to
    // somewhere else is what ends the clash when the verdict was read against
    // an agreement the disk has left behind (rig run 1873), and dropping it
    // re-parks the entry every pass.
    if !refused.is_empty() {
        let personality = env.vfs.personality();
        let mut kept = Vec::with_capacity(out.round.plan.ops.len());
        for op in std::mem::take(&mut out.round.plan.ops) {
            let drop = match (refused.get(&op.entity), placed_name(env, &op)?) {
                (Some(reasons), Some(name)) => reasons.iter().any(|r| a_park_refuses(r, &name, &personality)),
                _ => false,
            };
            if !drop {
                kept.push(op);
            }
        }
        out.round.plan.ops = kept;
    }
    journal(env.store, &out.round.plan, key_for)?;
    out.exec = match trace.as_mut() {
        Some(t) => crate::execute::run_queued_with(env, &mut |op, outcome| {
            t.ops.push((op.op_id, op.kind.clone(), crate::trace::name(op.entity), format!("{outcome:?}")));
        })?,
        None => run_queued(env)?,
    };

    // The cursor moves only now, once everything the batch implied is durably
    // in the journal. A cursor advanced any earlier is a change the server will
    // never mention again and nothing local knows to ask about.
    if next_cursor > env.store.cursor()? {
        env.store.set_cursor(next_cursor)?;
    }
    if let Some(mut t) = trace {
        for (id, why) in &out.naming.unsyncable {
            t.unsyncable.push((crate::trace::name(*id), format!("{why:?}")));
        }
        t.recovered = out.naming.recovered.iter().map(|id| crate::trace::name(*id)).collect();
        t.ended_ms = (env.now_ms)();
        out.trace = Some(t);
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
                let _ = open_metadata(env, &mut entry, state);
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
            let _ = open_metadata(env, &mut entry, state);
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// Does this "move" leave the entry in the slot the agreement already gives
/// it -- and if so, what local name should the record carry?
///
/// `None`: a real move, which goes up. `Some(spelling)`: not a move; `spelling`
/// is the local name the record should hold, `None` there meaning the disk
/// spells the name exactly as the server does.
///
/// The parent must be the same. Then, in order: byte-equal to the name the
/// record already describes (`effective_local_name`, so an escaped or
/// decomposed mapping counts) -- a displaced folder, nothing to change;
/// byte-equal to the server's own spelling -- a mapping gone stale, to be
/// cleared; and only then the fold test: a name this volume cannot tell from
/// the agreed one, whose exact byte-name already belongs to a live entry that
/// is not on its way somewhere else and is not materialized here -- that is
/// what makes the server's refusal permanent rather than a race worth
/// retrying, and if the holder IS on this disk the two are fighting over one
/// slot, which is a naming clash for naming to park. Anything else is a rename
/// the server can grant, and it goes up.
///
/// Deliberately not restricted to a normalization difference. What decides this
/// is whether the name can be had, not which axis the two spellings differ on:
/// a case respell onto a free name goes up like any other rename, and a case
/// respell onto a name a live twin holds would loop exactly as these did.
fn same_slot_spelling(
    env: &ExecEnv,
    entry: &Entry,
    to: &Placement,
    busy: &[EntityId],
) -> Result<Option<Option<String>>, ExecError> {
    let Some(agreed) = entry.synced_placement.as_ref() else {
        return Ok(None);
    };
    if agreed.parent != to.parent {
        return Ok(None);
    }
    if to.name == entry.agreed_local_name() {
        return Ok(Some(entry.local_name.clone()));
    }
    if to.name == agreed.name {
        return Ok(Some(None));
    }
    let personality = env.vfs.personality();
    if jd_vfs::comparison_key(&agreed.name, &personality)
        != jd_vfs::comparison_key(&to.name, &personality)
    {
        return Ok(None);
    }
    for other in all_entries(env)? {
        if other.id == entry.id || other.remote_deleted {
            continue;
        }
        // An entry with work outstanding may be vacating this very name, and
        // waiting one pass for it is not a livelock. If its op never lands,
        // this rename goes up and is refused for as long as that op is stuck --
        // the loop this exists to end, but gated on another entity's visibly
        // stuck work rather than on nothing.
        if busy.contains(&other.id) {
            continue;
        }
        if other.remote.parent != to.parent || other.remote.name != to.name {
            continue;
        }
        return Ok((!holds_here(env, &other)?).then(|| Some(to.name.clone())));
    }
    Ok(None)
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
pub(crate) fn open_metadata(env: &ExecEnv, entry: &mut Entry, state: &RemoteState) -> bool {
    if !state.is_encrypted {
        return false;
    }
    let (Some(vault), Some(wrapped), Some(blob)) = (
        env.vault,
        entry.wrapped_file_key.as_deref(),
        state.encrypted_metadata.as_deref(),
    ) else {
        return false;
    };
    let Ok(file_key) = vault.open_file_key(wrapped) else {
        return false;
    };
    let Ok(meta) = jd_crypto::drive::decrypt_metadata(blob, &file_key) else {
        return false;
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
    true
}

/// The remote state as currently recorded for an entry.
fn observed_remote(entry: &Entry) -> RemoteState {
    // A scratch name is not a placement. It is one step inside a rename some
    // device is in the middle of -- this one's own park, or a peer's -- and
    // the operation that made it is the only thing that will end it. Read as
    // where the server has the entity, it is a remote move: this device
    // followed a PEER's park onto its disk, the local walk (which hides
    // internal names) then found the record's file missing, and the round
    // trashed the server copy -- a park in flight turned into a delete on
    // every device that followed it (the reset's C11). The file stays at its
    // real name here while the park stands; a user's edit to it uploads as
    // ever; the parker's finish arrives as an ordinary remote move from
    // there. Only the placement: content, deletion and keys are read as the
    // server has them. Everything that reasons about the park itself -- the
    // stranded-park rescue, a finisher recognising its own park -- reads
    // `entry.remote`, not this.
    //
    // Read in `agreed_placement`'s order: the agreement, else the directory
    // standing in for a vault folder this device cannot open. An entry with
    // a scratch name and neither never gets here: it is `waiting_on_a_park`,
    // and the pass skips it before asking.
    let placement = if entry.remote.name.starts_with(crate::order::SWAP_PREFIX) {
        entry.agreed_placement().clone()
    } else {
        entry.remote.clone()
    };
    RemoteState {
        placement,
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
pub(crate) fn observe(env: &ExecEnv) -> Result<Vec<ObservedFile>, ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok(Vec::new());
    };
    let mut out = Vec::new();
    let mut reserved: Vec<String> = Vec::new();
    let mut queue = vec![(root.clone(), String::new())];
    // Every scratch name the store still has a live entity for. Built once:
    // asking per file would be a query per directory entry, and the answer
    // cannot change inside one walk.
    //
    // Both kinds of park. A remote park is the entry's server name; a LOCAL
    // park -- the directory stepped aside so a namesake could be created at
    // its name -- is the spelling on this disk, with the finisher that moves
    // it on still queued. Counted by server names alone, a live local park
    // read as litter and was trashed with everything inside it, one pass
    // after it was made. A local park with nothing queued on it is the
    // abandoned kind and is still swept. Estate seed 22081285.
    let busy_now: std::collections::HashSet<EntityId> =
        env.store.entities_with_open_ops()?.into_iter().collect();
    // A landing name carries the id of the download op making it
    // (`jd_vfs::land_name`). One whose op is still open is that op's, crash
    // or no crash: the resumed download makes its own and this one goes the
    // pass after. Asked of the journal, like the swap names, rather than of
    // which thread happens to be running.
    let open_ops: std::collections::HashSet<i64> = env
        .store
        .queued_ops()?
        .into_iter()
        .chain(env.store.interrupted_ops()?)
        .map(|op| op.op_id)
        .collect();
    let live_swap_names: std::collections::HashSet<String> = env
        .store
        .every_entry()?
        .into_iter()
        .filter(|e| !e.remote_deleted)
        .flat_map(|e| {
            let remote = e
                .remote
                .name
                .starts_with(crate::order::SWAP_PREFIX)
                .then(|| e.remote.name.clone());
            let local = e
                .local_name
                .as_deref()
                .filter(|n| n.starts_with(crate::order::SWAP_PREFIX) && busy_now.contains(&e.id))
                .map(str::to_owned);
            remote.into_iter().chain(local)
        })
        .collect();
    let mut guard = 0;
    let now_ns = (env.now_ms)().saturating_mul(1_000_000);
    let granularity = env.vfs.personality().mtime_granularity_ns.max(1);
    let positional = env.vfs.personality().positional_file_ids;
    let vaults = if positional { vault_directories(env)? } else { Vec::new() };
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
                } else if let Some(op) = jd_vfs::landing_op(&child.name) {
                    // A download copied onto this volume and never renamed
                    // into place: the server still holds the bytes, and the
                    // download is planned again from scratch.
                    if !open_ops.contains(&op) {
                        env.vfs.trash(&dir.join(&child.name))?;
                    }
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
                    // Without file ids the cache is kept by path, and is only
                    // as good as the watcher that forgets it
                    // (`Store::forget_path_hashes`).
                    let cached = if positional {
                        env.store.cached_hash_at(&path, fingerprint, granularity)?
                    } else {
                        env.store.cached_hash(fingerprint, granularity)?
                    };
                    let sha = match cached {
                        Some(s) => s,
                        None => {
                            let s = env.vfs.hash(&full)?;
                            if positional {
                                env.store.cache_hash_at(&path, fingerprint, &s, now_ns)?;
                            } else {
                                env.store.cache_hash(fingerprint, &s, None, now_ns)?;
                            }
                            s
                        }
                    };
                    // Listed inside a vault on a disk with no file identities:
                    // these bytes are sealed from now on (owner decision Q4),
                    // written down as they are listed, before anything else
                    // this pass can be stopped by (a5's R3). Nothing sealed
                    // here otherwise remembers them, and carried out of the
                    // vault they read as a stranger's plain file.
                    if let Some(vault) = vault_holding(&vaults, &path) {
                        env.store.note_vault_listed_bytes(vault, &sha)?;
                    }
                    out.push(ObservedFile {
                        path,
                        fingerprint,
                        sha256: sha,
                        tie_break_id: child.tie_break_id,
                    });
                }
                // Symlinks are flagged and never followed: following one walks
                // out of the sync folder, and a loop walks forever.
                _ => {}
            }
        }
    }

    // The walk finished: bytes listed in a vault that now stand nowhere on the
    // disk are forgotten. Never mid-walk, where bytes not reached yet would
    // read as gone.
    if positional {
        env.store.keep_vault_listed_bytes(&out.iter().map(|o| o.sha256.as_str()).collect())?;
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
/// Every directory on the disk, shallowest first, and each one's identity
/// -- the id the filesystem gives it, 0 where it could not be read.
///
/// The identities cover the engine's own scratch directories too (a park
/// under a `.jd-` name), which the listing leaves out: whether an id stands
/// on this disk is a fact about the disk, and a parked folder's directory
/// stands as surely as any other.
pub(crate) fn observed_dirs(env: &ExecEnv) -> Result<(Vec<String>, HashMap<String, u64>), ExecError> {
    let (out, identity, _, _) = observed_dirs_and_tie_breaks(env)?;
    Ok((out, identity))
}

/// The same, with each directory's tie-break id (`DirEntry::tie_break_id`)
/// where the volume's ids may break a tie, and each directory's birth where
/// the volume reports one (absent where it does not).
#[allow(clippy::type_complexity)]
pub(crate) fn observed_dirs_and_tie_breaks(
    env: &ExecEnv,
) -> Result<(Vec<String>, HashMap<String, u64>, HashMap<String, u64>, HashMap<String, u64>), ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok((Vec::new(), HashMap::new(), HashMap::new(), HashMap::new()));
    };
    let mut out = Vec::new();
    let mut identity = HashMap::new();
    let mut tie_breaks = HashMap::new();
    let mut births = HashMap::new();
    let mut queue = vec![(root, String::new())];
    let mut guard = 0;
    while let Some((dir, rel)) = queue.pop() {
        guard += 1;
        if guard > 100_000 {
            return Err(ExecError::Contract("the local walk does not end".into()));
        }
        for child in env.vfs.read_dir_all(&dir)? {
            if child.kind != jd_vfs::EntryKind::Directory {
                continue;
            }
            let path = if rel.is_empty() {
                child.name.clone()
            } else {
                format!("{rel}/{}", child.name)
            };
            identity.insert(path.clone(), child.fingerprint.map(|fp| fp.file_id).unwrap_or(0));
            if let Some(birth) = child.fingerprint.map(|fp| fp.birth_ns).filter(|b| *b != 0) {
                births.insert(path.clone(), birth);
            }
            if child.tie_break_id != 0 {
                tie_breaks.insert(path.clone(), child.tie_break_id);
            }
            if jd_vfs::is_internal(&child.name) {
                // Known to stand, never listed, and not walked into: what a
                // park holds is the finisher's business.
                continue;
            }
            queue.push((dir.join(&child.name), path.clone()));
            out.push(path);
        }
    }
    // Shallowest first, so a parent always has an identity before its children
    // need one.
    out.sort_by_key(|p| (depth_of(p), p.clone()));
    Ok((out, identity, tie_breaks, births))
}

/// A folder record learns which directory is its own.
///
/// A folder record never knew: it found its directory by looking at what
/// stood at the path it expected and what was inside, and that inference
/// fails exactly when files and folders move at once (Defects AC, AD, AE, AF,
/// AG, AJ -- `specs/drive_directory_identity.md`). The directory itself has
/// always known: it has an id, stable across renames, exactly as a file has
/// an inode. This is where the record writes it down.
///
/// Recording only, and only from the directory standing at the record's OWN
/// agreed path -- never from a pairing the contents rule made this pass. A
/// record with no id yet, paired by contents to a directory its files
/// moved into (the AG shape, on an install that predates this or after a
/// restore), would otherwise cache the wrong directory as its own and every
/// reader would corroborate it for the life of the record. So the pairs come
/// from `folder_paths` as they stood before the scan moved anything; a
/// folder renamed before it had an id learns it one pass after the rename
/// is agreed, which is the whole cost. The record must have a settled
/// placement, and either no id yet or one that stands nowhere on this disk
/// any more (a restore, a re-created root, a volume swap -- the id is a
/// cache of evidence and its absence is ordinary). A record whose id stands
/// ELSEWHERE is left alone while no other live record carries that id: the
/// disagreement is evidence, and reading it is the readers' job, not this
/// one's. Once another live record carries it, the readers have had their
/// pass and given the directory away, and this record takes the directory at
/// its own path instead. In every case a record never takes an id another
/// live record already carries: one directory, one record, both ways round.
/// Nothing here changes what the engine plans; what it changes is what the
/// next pass can know.
fn record_directory_identities(
    env: &ExecEnv,
    agreed_paths: &HashMap<String, i64>,
    dir_identity: &HashMap<String, u64>,
    dir_births: &HashMap<String, u64>,
) -> Result<(), ExecError> {
    let on_disk: std::collections::HashSet<u64> =
        dir_identity.values().copied().filter(|id| *id != 0).collect();
    // Which live folder records name each directory id as their own.
    let mut carriers: HashMap<u64, Vec<i64>> = HashMap::new();
    for e in env.store.every_entry()? {
        if e.id.entity_type != EntityType::Folder || e.remote_deleted || e.id.is_provisional() {
            continue;
        }
        if let Some(id) = e.synced_fingerprint.map(|fp| fp.file_id).filter(|id| *id != 0) {
            carriers.entry(id).or_default().push(e.id.server_id);
        }
    }
    let carried_by_another = |id: u64, me: i64| carriers.get(&id).is_some_and(|c| c.iter().any(|o| *o != me));
    for (path, server_id) in agreed_paths {
        let Some(&id) = dir_identity.get(path) else {
            continue;
        };
        if id == 0 {
            continue;
        }
        let birth = dir_births.get(path).copied().unwrap_or(0);
        let Some(mut entry) = env.store.get_entry(EntityId::folder(*server_id))? else {
            continue;
        };
        if entry.synced_placement.is_none() {
            continue;
        }
        let stale = match entry.synced_fingerprint {
            None => true,
            // Gone from the disk, or standing under this folder's own path
            // -- a recycled id on a directory made inside the folder (C5);
            // either way not this folder's directory any more.
            // Or settled against it: the directory it names stands elsewhere
            // and another live folder record now holds it as its own, while
            // a different directory stands at this record's path. The
            // readers had their pass at that disagreement and gave the
            // directory to the other record; kept here, two records claimed
            // one directory, and every identity reader after was misled (a
            // folder renamed and its old name made again by a save through
            // it, its files not yet sent: soak run 1504).
            // Or its own directory at its own path with a birth the record
            // has not got: one it never learned (a record from before
            // directories had births), or a new directory that took a
            // deleted one's id at this path.
            Some(fp) => {
                (fp.file_id == id && fp.birth_ns != birth)
                    || !on_disk.contains(&fp.file_id)
                    || dir_identity
                        .iter()
                        .any(|(at, id)| *id == fp.file_id && at.starts_with(&format!("{path}/")))
                    || (fp.file_id != id && carried_by_another(fp.file_id, *server_id))
            }
        };
        // Never by taking a directory another live record already names as
        // its own: that makes the same two claims, the other way round. A
        // plain folder whose path a vault's directory had rotated onto took
        // the vault's id, and the vault's sealed file was read as the plain
        // folder's and went up in the clear (hostile2 74403).
        if stale && !carried_by_another(id, *server_id) {
            entry.synced_fingerprint = Some(jd_vfs::Fingerprint::of_directory(id, birth));
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// A source waiting on a keyless vault is held, not frozen.
///
/// The hold is about the BYTES -- do not trash the server's copy for a
/// replacement this device cannot send -- and it says nothing about the NAME.
/// The server can move the source meanwhile: a peer's move, or this device's
/// own move landing after its answer was lost. Both waiting exits skip
/// reconcile, so nothing else records that, and the agreement keeps naming
/// the place the file left. That name is then held against every file that
/// comes to take it: a peer's new file under the old name was parked as a
/// duplicate of a ghost, and never reached this disk. Estate seed 11091499.
///
/// So the server's placement is written down as the agreement. The scan then
/// looks for the source where the server has it, which is as empty as the old
/// path was, and the hold stands on the same premise as before.
///
/// A stranger standing at the server's path on this disk is not handed to
/// the source by this: the scan pairs a held record by inode, not by path
/// (`KnownLocal::held`).
fn follow_the_server(env: &ExecEnv, entry: &Entry) -> Result<(), ExecError> {
    if entry.synced_placement.as_ref() == Some(&entry.remote) {
        return Ok(());
    }
    let mut moved = entry.clone();
    let agreed_name = entry.synced_placement.as_ref().map(|p| p.name.as_str());
    if agreed_name != Some(entry.remote.name.as_str()) {
        moved.local_name = None;
    }
    moved.synced_placement = Some(entry.remote.clone());
    env.store.put_entry(&moved)?;
    Ok(())
}

/// What happened to a tracked folder on this computer since the last agreement.
///
/// A vault folder trashed on the server while this device, which cannot open
/// it, holds files in the directory standing in for it.
///
/// The ordinary reading of an unmaterialized folder's deletion is to forget
/// it -- and forgetting the folder forgets the claimants under it, leaves the
/// directory and the user's never-uploaded files on the disk, and the next
/// pass adopts the lot as a plain folder and uploads every file in the clear.
/// The files were put there as private; a deletion made elsewhere is no
/// permission to publish them. So the folder is parked out of scope instead:
/// the tie stays, the directory is not adopted, the claimants stay held, and
/// the user is told.
///
/// Decided every pass, after the disk has been walked and before the round,
/// from what the walk found under the directory -- not from the store.
/// Nothing under a stand-in has ever been sent (a keyless device downloads
/// nothing into a vault), so every file there is the user's and unsent,
/// whether it was saved there and has a claimant, or was moved there from a
/// synced path and gets its claimant in the round loop, after this decision.
/// Counting entries missed the second kind, and a folder was forgotten on the
/// very pass that found the file moved into it. Reading the disk also asks
/// nothing of a placeholder the user has replaced with a file of the same
/// name, which a stat under it would refuse.
///
/// With files held: parked and said. With none: back to waiting, the
/// complaint withdrawn, and the empty placeholder goes with the vault --
/// trashed here as a materialized empty vault folder would be -- before the
/// round forgets the folder. Left standing it was adopted as a plain folder
/// of the vault's name, everything saved into it went up in the clear, and
/// the holder's restore then met a plain sibling of the same name. Restored
/// from the trash, a parked folder goes back to waiting for a key the same
/// way.
/// The issue a vault parked by D3 carries, which is also what marks the park
/// as D3's to lift.
const VAULT_ON_A_WEAK_DRIVE: &str = "vault_on_a_weak_drive";

/// A vault is not synced on a volume that cannot say which file is which
/// (owner decision D3, `specs/drive_weak_volume_identity.md`): there a file
/// edited and carried across the vault's edge is the same disk as a save, and
/// its sealed bytes would go up in the clear. Each vault's top folder is
/// parked out of scope, and everything under it waits with it: nothing is
/// downloaded or created there, nothing in it is read as deleted or moved,
/// and a file saved into it gets a record and is never sent. A sealed file
/// held outside its vault is not under it, and keeps its hold -- which is why
/// this is a park and not a device with no key: a device that turns weak
/// already holds sealed plaintext, and a no-key reading let a held file go up
/// plain. The volume read strong again lifts the park.
fn park_vaults_on_a_weak_volume(env: &ExecEnv) -> Result<(), ExecError> {
    let weak = !env.vfs.personality().stable_file_identity;
    let parked_by_us: std::collections::HashSet<EntityId> = env
        .store
        .open_issues()?
        .into_iter()
        .filter(|i| i.kind == VAULT_ON_A_WEAK_DRIVE)
        .filter_map(|i| i.entity)
        .collect();
    for mut entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder || !entry.is_encrypted || entry.remote_deleted {
            continue;
        }
        // The vault's top folder: its children follow it by the shadow.
        if parent_is_encrypted(env, entry.local_placement().parent)? {
            continue;
        }
        let ours = parked_by_us.contains(&entry.id);
        if weak && !ours && !matches!(entry.status, LocalStatus::OutOfScope | LocalStatus::Unsyncable(_)) {
            entry.status = LocalStatus::OutOfScope;
            env.store.put_entry(&entry)?;
            env.store.raise_issue(
                Some(entry.id),
                VAULT_ON_A_WEAK_DRIVE,
                &format!(
                    "{} is a vault. This drive cannot keep track of which file is which (FAT, exFAT, \
                     or another kind that does not keep file identities), so it cannot keep a \
                     vault's files safe, and vaults are not synced on it. They stay on the server \
                     and on your other devices. Files you save into it here are not uploaded; \
                     files you move out of it are synced as ordinary files, except sealed files, \
                     which stay held.",
                    entry.remote.name
                ),
                (env.now_ms)() as i64,
            )?;
        } else if !weak && ours {
            if entry.status == LocalStatus::OutOfScope {
                entry.status = if entry.synced_placement.is_some() {
                    LocalStatus::Synced
                } else {
                    LocalStatus::PendingDownload
                };
                env.store.put_entry(&entry)?;
            }
            for issue in env.store.open_issues()? {
                if issue.kind == VAULT_ON_A_WEAK_DRIVE && issue.entity == Some(entry.id) {
                    env.store.dismiss_issue(issue.issue_id)?;
                }
            }
        }
    }
    Ok(())
}

/// The folders a D3 park holds on this disk: every vault parked on a weak
/// volume, and everything under it by the local tree.
pub(crate) fn held_by_a_weak_volume_park(env: &ExecEnv) -> Result<std::collections::HashSet<i64>, ExecError> {
    let mut set: std::collections::HashSet<i64> = env
        .store
        .open_issues()?
        .into_iter()
        .filter(|i| i.kind == VAULT_ON_A_WEAK_DRIVE)
        .filter_map(|i| i.entity)
        .filter(|id| id.entity_type == EntityType::Folder)
        .map(|id| id.server_id)
        .collect();
    if set.is_empty() {
        return Ok(set);
    }
    let folders: Vec<Entry> = all_entries(env)?.into_iter().filter(|e| e.id.entity_type == EntityType::Folder).collect();
    loop {
        let before = set.len();
        for f in &folders {
            if f.local_placement().parent.is_some_and(|p| set.contains(&p)) {
                set.insert(f.id.server_id);
            }
        }
        if set.len() == before {
            return Ok(set);
        }
    }
}

/// The standing notice on a drive whose ids cannot even break a tie
/// (Windows FAT and exFAT): renames there are read from contents and paths
/// alone, and the one thing that cannot be read -- two files with identical
/// contents elsewhere trading names -- is reported, not hidden (owner
/// decision 2026-09-29: such drives may work badly, but they fail loudly).
pub(crate) const RENAMES_UNTRACKED: &str = "renames_untracked_on_this_drive";

/// Raised per pair when it happens (`say_where_two_files_may_have_swapped`).
pub(crate) const NAMES_MAY_HAVE_SWAPPED: &str = "names_may_have_swapped";

fn say_whether_renames_can_be_tracked(env: &ExecEnv) -> Result<(), ExecError> {
    let personality = env.vfs.personality();
    let untracked = personality.positional_file_ids && personality.id_tie_break == jd_vfs::IdTieBreak::None;
    let open: Vec<_> = env.store.open_issues()?.into_iter().filter(|i| i.kind == RENAMES_UNTRACKED).collect();
    if untracked && open.is_empty() {
        env.store.raise_issue(
            None,
            RENAMES_UNTRACKED,
            "This drive (FAT or exFAT on Windows) cannot keep track of files when they are renamed. \
             Syncing still works, but if files with identical contents swap names, their version \
             histories may get mixed up. Each time that may have happened you will be told which files.",
            (env.now_ms)() as i64,
        )?;
    } else if !untracked {
        for issue in open {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    Ok(())
}

/// Two records each read as the other's edit: each one's new bytes are the
/// bytes the other last saw, and nothing (no tie-break id) told the files
/// apart. The likeliest story is that the two files traded names, and each
/// record is about to take the other's version into its history. The sync
/// goes ahead, and the user is told which two files, once per pair.
fn say_where_two_files_may_have_swapped(env: &ExecEnv, known: &[KnownLocal], scan: &ScanOutcome) -> Result<(), ExecError> {
    if !env.vfs.personality().positional_file_ids {
        return Ok(());
    }
    let record: HashMap<EntityId, &KnownLocal> = known.iter().map(|k| (k.id, k)).collect();
    let edits: Vec<(&KnownLocal, &str)> = scan
        .changes
        .iter()
        .filter_map(|(id, change)| match change {
            LocalChange::Edited { sha256, .. } => record.get(id).map(|k| (*k, sha256.as_str())),
            _ => None,
        })
        .filter(|(k, _)| k.tie_break == 0)
        .collect();
    let told: Vec<_> = env.store.open_issues()?.into_iter().filter(|i| i.kind == NAMES_MAY_HAVE_SWAPPED).collect();
    for (i, (a, a_now)) in edits.iter().enumerate() {
        for (b, b_now) in edits.iter().skip(i + 1) {
            let crossed = a.last_seen_sha.as_deref() == Some(*b_now) && b.last_seen_sha.as_deref() == Some(*a_now);
            if !crossed || a.last_seen_sha == b.last_seen_sha {
                continue;
            }
            let (Some(ea), Some(eb)) = (env.store.get_entry(a.id)?, env.store.get_entry(b.id)?) else {
                continue;
            };
            let detail = format!(
                "{} and {} may have swapped names in a way this drive gave no way to follow. \
                 Their version histories may now be mixed; check them before restoring an older version.",
                a.path, b.path
            );
            if told.iter().any(|t| t.entity == Some(ea.id) && t.detail == detail) {
                continue;
            }
            for id in [ea.id, eb.id] {
                env.store.raise_issue(Some(id), NAMES_MAY_HAVE_SWAPPED, &detail, (env.now_ms)() as i64)?;
                env.store.record_swap_report(id, a_now, b_now)?;
            }
        }
    }
    Ok(())
}

fn park_stand_ins_of_trashed_vaults(
    env: &ExecEnv,
    observed: &[ObservedFile],
) -> Result<(), ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok(());
    };
    for mut entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder || entry.stand_in.is_none() {
            continue;
        }
        let parked = entry.status == LocalStatus::OutOfScope;
        let here = relative_path(env, &entry)?;
        // Compared the way the disk compares, component by component. The
        // recorded path is the folder's spelling and the scan's paths are
        // the disk's, and on a disk that folds case the two can differ for
        // one directory: read raw, a placeholder the user had respelled
        // counted as empty and was trashed with the user's files in it.
        let personality = env.vfs.personality();
        let under = |path: &str, dir: &str| -> bool {
            let mut file = path.split('/');
            for want in dir.split('/') {
                match file.next() {
                    Some(have)
                        if jd_vfs::comparison_key(have, &personality)
                            == jd_vfs::comparison_key(want, &personality) => {}
                    _ => return false,
                }
            }
            file.next().is_some()
        };
        let held = match (&here, entry.remote_deleted) {
            (Some(here), true) => observed.iter().filter(|o| under(&o.path, here)).count(),
            _ => 0,
        };
        // What the disk's own listing says, which is wider than the scan: a
        // symlink, a file that vanished mid-walk. A placeholder holding only
        // those is not empty, and letting its tie lapse had it adopted as a
        // plain folder of the vault's name on the next pass.
        let dir = here.as_ref().map(|h| root.join(h));
        let dir_stands = dir.as_ref().is_some_and(|d| env.vfs.read_dir(d).is_ok());
        let dir_empty = match &dir {
            Some(d) if dir_stands => dir_is_empty(env, d)?,
            _ => true,
        };
        let should_park = entry.remote_deleted && dir_stands && (held > 0 || !dir_empty);
        if should_park && !parked {
            entry.status = LocalStatus::OutOfScope;
            env.store.put_entry(&entry)?;
            env.store.raise_issue(
                Some(entry.id),
                "vault_deleted_upstream",
                &format!(
                    "the protected folder {} was deleted on the server while this device, \
                     which cannot open it, holds files in its directory; they stay here and \
                     are not uploaded. Restore the folder from the trash to keep them \
                     protected, or move them out to sync them as ordinary files",
                    entry.remote.name
                ),
                (env.now_ms)() as i64,
            )?;
        } else if !should_park && parked {
            entry.status = LocalStatus::PendingKey;
            env.store.put_entry(&entry)?;
            for issue in env.store.open_issues()? {
                if issue.kind == "vault_deleted_upstream" && issue.entity == Some(entry.id) {
                    env.store.dismiss_issue(issue.issue_id)?;
                }
            }
        }
        if entry.remote_deleted && !should_park {
            // Gone, or empty by the disk's own account to any depth. An empty
            // placeholder goes with its vault; the tie lapses either way and
            // the round forgets the folder.
            if let Some(d) = &dir {
                if dir_stands && dir_empty {
                    env.vfs.trash(d)?;
                }
            }
            entry.stand_in = None;
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// Nothing at all under this directory, to any depth.
fn dir_is_empty(env: &ExecEnv, path: &std::path::Path) -> Result<bool, ExecError> {
    let Ok(entries) = env.vfs.read_dir(path) else {
        return Ok(false);
    };
    for child in entries {
        if child.kind != jd_vfs::EntryKind::Directory {
            return Ok(false);
        }
        if !dir_is_empty(env, &path.join(&child.name))? {
            return Ok(false);
        }
    }
    Ok(true)
}

/// Open the encrypted files this device absorbed before it had the key.
///
/// A file's real name and content id live in metadata only the key opens, and
/// the feed mentions each file once. A device that heard about a file while
/// keyless recorded the grant and the placeholder name and nothing else, and
/// nothing came back to it: the key arrived, the folder was materialized, and
/// every file inside stayed parked `PendingKey` for good -- the second laptop
/// linked, the vault unlocked on it, and the files never came down.
///
/// So the files whose grant this key opens, and whose metadata has not been
/// read, are asked about again. One batched stat, and only while there is
/// something to open: a file whose metadata will not open is asked about every
/// pass, which is bounded and visible, where a file never asked about is
/// neither.
fn open_what_the_key_unlocks(env: &ExecEnv) -> Result<(), ExecError> {
    let Some(vault) = env.vault else {
        return Ok(());
    };
    let waiting: Vec<EntityId> = all_entries(env)?
        .into_iter()
        .filter(|e| {
            e.id.entity_type == EntityType::File
                && e.is_encrypted
                && !e.remote_deleted
                && !e.id.is_provisional()
                && e.content_id.is_none()
                && e.wrapped_file_key
                    .as_deref()
                    .is_some_and(|w| vault.open_file_key(w).is_ok())
        })
        .map(|e| e.id)
        .collect();
    if waiting.is_empty() {
        return Ok(());
    }
    for (id, state) in stat_all(env, &waiting)? {
        absorb_remote(env, id, &state)?;
        // The grant opens and the metadata still does not: a blob this build
        // cannot read, or none stored. Asked about again every pass, which is
        // bounded, and said once, which is what makes it visible.
        if env.store.get_entry(id)?.is_some_and(|e| e.content_id.is_none()) {
            env.store.raise_issue(
                Some(id),
                "metadata_unreadable",
                "this device holds the key to this file but cannot read its name and \
                 content id from the server's copy; it waits, and is asked about again \
                 each pass",
                (env.now_ms)() as i64,
            )?;
        } else {
            // A state, not an event: it ends when the metadata opens.
            for issue in env.store.open_issues()? {
                if issue.kind == "metadata_unreadable" && issue.entity == Some(id) {
                    env.store.dismiss_issue(issue.issue_id)?;
                }
            }
        }
    }
    Ok(())
}

/// A directory standing in for a vault this device cannot open follows the
/// server, exactly as a materialized folder would.
///
/// The tie (`Entry::stand_in`) names the directory; this keeps it where the
/// server has the folder. Renamed on the server, the directory is renamed
/// here, and the files held under it resolve to their new paths through it
/// before the disk is walked. Removed by the user, the tie lapses: the
/// directory never held a byte of the vault, so there is nothing to delete
/// anywhere and nothing to tell anyone. Materialized for real, the agreement
/// is the tie from then on.
///
/// Done in the pass rather than as a journaled operation because it needs no
/// journal: a death between the rename and the record leaves the directory at
/// the server's path, where the next pass finds it at the folder's derived
/// path and ties it again from nothing.
///
/// Where something already stands at the server's path: a folder this device
/// tracks keeps the tie waiting, since its own record says where it goes;
/// anything else is moved aside under a conflict name first, as anything in
/// the way of a synced copy is.
fn placeholders_follow_the_server(
    env: &ExecEnv,
    key_for: &mut dyn FnMut() -> String,
) -> Result<(), ExecError> {
    let Some(root) = env.vfs.root() else {
        return Ok(());
    };
    let tracked = folder_paths(env, None)?;
    // Parents before children, so a stand-in inside a stand-in resolves its
    // path through a parent already moved.
    for mut entry in all_entries(env)? {
        if entry.stand_in.is_none() {
            continue;
        }
        if entry.synced_placement.is_some() {
            entry.stand_in = None;
            env.store.put_entry(&entry)?;
            continue;
        }
        if entry.remote_deleted {
            // Trashed on the server. With files held under the stand-in the
            // folder was parked out of scope when the deletion was absorbed,
            // and the tie is what keeps that directory from being adopted as
            // a plain folder; without them it is forgotten and the directory
            // is the user's own.
            continue;
        }
        // Not deleted, and a peer is mid-rename: the server's name is its
        // scratch name. A placeholder renamed after it would stand under an
        // internal name the local walk does not see, and the tie would lapse
        // as if the user had removed it (the reset's C11, for a stand-in). It
        // waits where it is until the park ends. Checked after the deletion,
        // so a folder trashed while parked is let go like any other.
        if entry.remote.name.starts_with(crate::order::SWAP_PREFIX) {
            continue;
        }
        let Some(here) = relative_path(env, &entry)? else {
            entry.stand_in = None;
            env.store.put_entry(&entry)?;
            continue;
        };
        if env.vfs.read_dir(&root.join(&here)).is_err() {
            entry.stand_in = None;
            env.store.put_entry(&entry)?;
            continue;
        }
        let there = {
            let mut probe = entry.clone();
            probe.stand_in = None;
            relative_path(env, &probe)?
        };
        let Some(there) = there else {
            continue;
        };
        if here == there {
            continue;
        }
        let to = root.join(&there);
        if placeholder_waits_for_its_parent(env, &entry)? {
            continue;
        }
        // A respelling of the same slot -- a case-only rename on a folding
        // disk -- finds the stand-in itself at the destination. Compared raw
        // it read as something in the way, was moved aside under a conflict
        // name, and the tie then lapsed on a directory that had merely
        // changed case: the files under it went up in the clear.
        let respell = crate::execute::same_slot(&here, &there, &env.vfs.personality());
        if !respell {
            if tracked.contains_key(&there) {
                // A folder this device tracks holds the name. Its own record
                // says where it is going; the tie stays and this waits.
                continue;
            }
            // Whatever else stands there is nothing this store can account
            // for -- a directory the user just made, a file -- and it is
            // moved aside under a conflict name, as anything in the way of a
            // synced copy is. Left in place it would be adopted as a folder
            // of its own, refused by the server for the name the vault holds,
            // and the record and the directory would part company for ever.
            crate::execute::make_room(env, &to, None, &key_for())?;
        }
        env.vfs.rename(&root.join(&here), &to)?;
        entry.stand_in = Some(entry.remote.clone());
        env.store.put_entry(&entry)?;
    }
    Ok(())
}

/// A placeholder whose vault the server has put in a folder that is not on
/// this disk: a vault moved into another vault under a folder made there,
/// which this device cannot open and no engine write makes (soak run 1571).
/// It waits where it is until that folder's directory stands -- made by the
/// user, or created when the key arrives -- rather than failing the rename,
/// and with it this pass and every pass after. While it waits it is not
/// leaving its name, and it keeps its directory: a folder the server makes
/// under its old name is only named here and contests nothing the stand-in
/// holds (`Entry::is_tied_here`). Asked by naming as well as by the follow,
/// because naming runs first and read such a placeholder as on its way out.
pub(crate) fn placeholder_waits_for_its_parent(env: &ExecEnv, entry: &Entry) -> Result<bool, ExecError> {
    if entry.stand_in.is_none() || entry.synced_placement.is_some() || entry.remote_deleted {
        return Ok(false);
    }
    let Some(root) = env.vfs.root() else {
        return Ok(false);
    };
    let there = {
        let mut probe = entry.clone();
        probe.stand_in = None;
        relative_path(env, &probe)?
    };
    let Some(there) = there else {
        return Ok(false);
    };
    Ok(root.join(&there).parent().is_some_and(|parent| env.vfs.read_dir(parent).is_err()))
}

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
    /// Tracked folders found under a directory nothing is tracking yet, and
    /// the path they were found at. Their placement cannot be written until
    /// that directory has an identity, which it gets when the untracked
    /// directories are adopted; `place_deferred` finishes the job then.
    ///
    /// The shape: `A/B/f.txt`, with `A` renamed to `X` and `B` to `C` in one
    /// go. `B` is found under `X/C` by its file, but `X` is nobody yet, so
    /// there is no parent id to place `B` under. Left unplaced, `B` was read
    /// as still at `A/B`, `A` went as deleted and took `B` with it, and the
    /// folder the user renamed was trashed and minted again -- its grants
    /// gone, for a rename of the folder above it.
    deferred: Vec<(EntityId, String)>,
    /// Directories that are somebody's -- a tracked folder's own directory
    /// standing where its files did not follow it -- and must not be adopted
    /// as new folders while that disagreement stands. Nothing under one is
    /// placed this pass; the folder's record is present and unmoved, and an
    /// issue names both readings.
    held: std::collections::HashSet<String>,
}

impl FolderScan {
    /// Write the placement of every folder found under a directory that has
    /// since been given an identity.
    fn place_deferred(&mut self, env: &ExecEnv, folder_ids: &HashMap<String, i64>) -> Result<(), ExecError> {
        for (id, path) in std::mem::take(&mut self.deferred) {
            let Some(placement) = placement_of(&path, folder_ids) else {
                // The directory it was found under still has no identity --
                // refused adoption for a name this disk cannot hold, say. The
                // folder is on the disk, so its record stands, and every
                // folder it is still recorded under stands with it: reading
                // one of those as deleted would trash it on the server and
                // take this folder down in the cascade.
                let mut parent = env
                    .store
                    .get_entry(id)?
                    .map(|e| e.agreed_placement().parent)
                    .unwrap_or(None);
                let mut guard = 0;
                while let Some(p) = parent {
                    self.present.insert(EntityId::folder(p));
                    guard += 1;
                    if guard > 512 {
                        break;
                    }
                    parent = env
                        .store
                        .get_entry(EntityId::folder(p))?
                        .map(|f| f.agreed_placement().parent)
                        .unwrap_or(None);
                }
                continue;
            };
            let unchanged = env
                .store
                .get_entry(id)?
                .and_then(|e| e.synced_placement)
                .is_some_and(|p| p == placement);
            if !unchanged {
                self.moves.insert(id, placement);
            }
        }
        Ok(())
    }
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
/// The issue a folder carries while its directory stands in one place and
/// its files in another (row 5 of the reset's WP2 table). Open, it is the
/// hold; dismissed, the hold is lifted.
pub(crate) const DIRECTORY_DISAGREES: &str = "directory_disagrees";

/// The issue a sealed file carries while it stands outside its vault here.
pub(crate) const HELD_OUTSIDE_THE_VAULT: &str = "held_outside_the_vault";

/// The issue a file from the server carries while a held file keeps its name.
pub(crate) const WAITS_FOR_A_HELD_FILE: &str = "waits_for_a_held_file";

/// Every file refused a name here only because a file held outside its vault
/// stands at that name, with the sentence that says so.
fn waiting_for_a_held_file(env: &ExecEnv) -> Result<Vec<(EntityId, String)>, ExecError> {
    let personality = env.vfs.personality();
    let all = all_entries(env)?;
    let mut out = Vec::new();
    for refused in &all {
        let with = match &refused.status {
            LocalStatus::Unsyncable(
                jd_vfs::UnsyncableReason::DuplicateName { with } | jd_vfs::UnsyncableReason::CaseClash { with },
            ) => with,
            _ => continue,
        };
        let key = jd_vfs::comparison_key(with, &personality);
        let parent = refused.remote.parent;
        for held in &all {
            if held.id == refused.id
                || held.local_placement().parent != parent
                || jd_vfs::comparison_key(held.effective_local_name(), &personality) != key
                || !held_outside_its_vault(env, held)?
            {
                continue;
            }
            let folder = match parent {
                None => "at the top of your drive".to_string(),
                Some(id) => format!(
                    "in {}",
                    env.store
                        .get_entry(EntityId::folder(id))?
                        .map(|f| f.effective_local_name().to_string())
                        .unwrap_or_default()
                ),
            };
            out.push((
                refused.id,
                format!(
                    "{} from the server was not put {folder}: the vault file {} is being kept there \
                     on this device. It will appear once that file goes back into its vault or is \
                     deleted.",
                    refused.remote.name,
                    held.effective_local_name()
                ),
            ));
            break;
        }
    }
    Ok(out)
}

/// The path map is seeded from the records' placements, and a placement is a
/// name: while folders trade names faster than the server can take the
/// trades, a path can be held by one record while another folder's directory
/// stands there. Every reader then saw the wrong folder -- a plain file whose
/// folder was only renamed read as carried into the vault that had held the
/// name, and was converted (hostile2 74424). So a path whose directory is,
/// by its identity, another live folder's recorded directory is that
/// folder's (the reset's WP2: the directory a file stands in is read against
/// the folder whose directory it is). A directory with no identity, or one
/// claimed by more than one record, keeps the name's reading: no evidence is
/// never a change.
fn point_paths_at_the_folders_whose_directories_they_are(
    env: &ExecEnv,
    dir_identity: &HashMap<String, u64>,
    folder_ids: &mut HashMap<String, i64>,
) -> Result<(), ExecError> {
    let entries = all_entries(env)?;
    // A record some entry replaces never wins the map: its claimant stands at
    // its directory by design (a folder dragged into a vault, held for the
    // replacement) -- the same set naming and the merge keep out.
    let replaced: std::collections::HashSet<EntityId> = entries
        .iter()
        .filter(|e| !e.remote_deleted && e.status != LocalStatus::OutOfScope)
        .filter_map(|e| e.replaces)
        .collect();
    let stands_at: HashMap<u64, &String> = dir_identity.iter().map(|(p, d)| (*d, p)).collect();
    let mut owners: HashMap<u64, Vec<i64>> = HashMap::new();
    for e in &entries {
        if e.id.entity_type != EntityType::Folder || e.remote_deleted || replaced.contains(&e.id) {
            continue;
        }
        let Some(dir) = e.synced_fingerprint.map(|f| f.file_id).filter(|d| *d != 0) else { continue };
        // Not an id recycled onto a directory under the record's own path: that
        // is some other directory, as `detect_folder_moves` reads it (C5,
        // frozen 1073449).
        let under_itself = agreed_path(env, e)?
            .is_some_and(|mine| stands_at.get(&dir).is_some_and(|at| at.starts_with(&format!("{mine}/"))));
        if under_itself {
            continue;
        }
        owners.entry(dir).or_default().push(e.id.server_id);
    }
    for (path, id) in folder_ids.iter_mut() {
        let Some(dir) = dir_identity.get(path).copied().filter(|d| *d != 0) else { continue };
        if let Some([owner]) = owners.get(&dir).map(Vec::as_slice) {
            if owner != id {
                *id = *owner;
            }
        }
    }
    Ok(())
}

/// Where each folder's directory stands on this disk, for every reader that
/// asks about the disk (`Entry::stands_at`).
///
/// Read from the scan's own answer, once it has matched directories to
/// folders: the path the folder map gives the folder. One resolver -- the
/// map every file the scan found is placed by -- and not a second reading
/// of directory ids beside it: read from the ids alone, a folder the scan
/// had kept at its agreed path (births hidden, the rename not matched) was
/// noted at its renamed directory, and a file saved in the directory the
/// scan placed it in was looked for in the other and forgotten (hidden
/// plain2 75283). Nothing is noted for a folder that is home, one the map
/// gives no path or two, one parked under a scratch name (the park's own
/// name says where it is), or one with no directory id -- on a volume that
/// keeps none the agreement goes on answering, as it always has. Nor is a plain
/// folder's directory carried into a vault: that crossing is never a move of
/// the record -- the folder converts, and a new record is minted for the
/// directory in the pass that finds it (or waits for a key). Noted for the
/// old record, the files it held stood where it said and never crossed with
/// it: a held sealed file was never handed to the new folder and went up a
/// second time beside its original, and on a device with no key the files
/// were left claimed by nothing. (The inward crossing stands in for the
/// precise statement, 'a folder a live claimant replaces': the claimant is
/// minted after this note, in the same pass.) A vault's folder carried OUT is noted: it
/// is held, the record keeps its directory, and its files are sent from
/// where they stand (B-CARRY).
fn note_where_folders_stand(env: &ExecEnv, folder_ids: &HashMap<String, i64>) -> Result<(), ExecError> {
    let mut paths_of: HashMap<i64, Vec<&String>> = HashMap::new();
    for (path, id) in folder_ids {
        paths_of.entry(*id).or_default().push(path);
    }
    for mut entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder || entry.id.is_provisional() {
            continue;
        }
        let mut found = None;
        if let (Some(agreed), Some(_)) = (
            entry.synced_placement.clone(),
            entry.synced_fingerprint.map(|fp| fp.file_id).filter(|id| *id != 0),
        ) {
            if !entry.local_name.as_deref().is_some_and(jd_vfs::is_internal) {
                let home = agreed_path(env, &entry)?;
                let paths = paths_of.get(&entry.id.server_id).map(Vec::as_slice).unwrap_or(&[]);
                if let ([path], false) = (paths, paths.iter().any(|p| Some(*p) == home.as_ref())) {
                    let here = placement_of(path, folder_ids)
                        .filter(|here| here.parent != agreed.parent || here.name != entry.agreed_local_name());
                    if let Some(here) = here {
                        let converts = !parent_is_encrypted(env, agreed.parent)? && parent_is_encrypted(env, here.parent)?;
                        if !converts {
                            found = Some(StandsAt { here, agreed });
                        }
                    }
                }
            }
        }
        if entry.stands_at != found {
            entry.stands_at = found;
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// A folder parked under a scratch local name because its directory stood
/// elsewhere (the executor's unmaterialize park, WP2's row 4) is found again
/// by that directory. When the directory stands at the folder's own agreed
/// placement, there is no move to report -- the folder never left its
/// agreement -- and the scratch name, which only a move cleared, was worn for
/// ever: the scan read the folder at a path that does not exist, and its park
/// was retried every pass (hostile2 74414, never settled). The name goes when
/// the directory is home and nothing is in flight for the folder.
fn lift_parks_whose_directories_came_home(
    env: &ExecEnv,
    folders: &FolderScan,
    dir_identity: &HashMap<String, u64>,
    folder_ids: &HashMap<String, i64>,
) -> Result<(), ExecError> {
    let busy = env.store.entities_with_open_ops()?;
    for mut entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder
            || entry.remote_deleted
            || busy.contains(&entry.id)
            || folders.moves.contains_key(&entry.id)
            || !entry.local_name.as_deref().is_some_and(jd_vfs::is_internal)
        {
            continue;
        }
        let (Some(agreed), Some(mine)) = (
            entry.synced_placement.clone(),
            entry.synced_fingerprint.map(|fp| fp.file_id).filter(|id| *id != 0),
        ) else {
            continue;
        };
        let mut at = dir_identity.iter().filter(|(_, id)| **id == mine).map(|(p, _)| p);
        let (Some(home), None) = (at.next(), at.next()) else { continue };
        if placement_of(home, folder_ids).as_ref() == Some(&agreed) {
            entry.local_name = None;
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// A vault parked on a weak drive is tied here by its agreement while its
/// directory stands. When nothing of it stands anywhere -- the folder scan
/// found it neither at its path nor moved, and the parked-vault follow found
/// it neither by its sealed files nor by its id -- the tie lapses, as a
/// stand-in's does when its directory goes: the record is then only named
/// here and contests no directory. Kept, the user removing a parked vault's
/// directory left the vault agreed at that name; a peer's new folder made
/// there was read the pass after as standing on the vault's path (no id can
/// tell two directories apart on this drive), lost it, read as deleted, and
/// was trashed on the server with its file.
///
/// Only a parked vault, and only on the scan's verdict. A parked vault asks
/// nothing of the server, so letting its tie go changes nothing there. Every
/// other record keeps its agreement: a locked vault the user removed waits
/// for the key and its delete then goes through whole, and one the user
/// renamed is found where it went, by identity or by what is in it.
fn lapse_parked_vaults_that_stand_nowhere(
    env: &ExecEnv,
    folders: &FolderScan,
    followed: &std::collections::HashSet<EntityId>,
    folder_ids: &mut HashMap<String, i64>,
) -> Result<(), ExecError> {
    let parked: std::collections::HashSet<EntityId> = env
        .store
        .open_issues()?
        .into_iter()
        .filter(|i| i.kind == VAULT_ON_A_WEAK_DRIVE)
        .filter_map(|i| i.entity)
        .collect();
    for mut entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder
            || entry.status != LocalStatus::OutOfScope
            || !parked.contains(&entry.id)
            || entry.synced_placement.is_none()
            || entry.remote_deleted
            || followed.contains(&entry.id)
            || folders.present.contains(&entry.id)
            || folders.moves.contains_key(&entry.id)
        {
            continue;
        }
        let path = relative_path(env, &entry)?;
        entry.synced_placement = None;
        entry.synced_fingerprint = None;
        entry.local_name = None;
        env.store.put_entry(&entry)?;
        if let Some(path) = path {
            if folder_ids.get(&path) == Some(&entry.id.server_id) {
                folder_ids.remove(&path);
            }
        }
    }
    Ok(())
}

/// A vault parked on a volume with no file identities follows its directory
/// by its sealed files' bytes (a5's D3 ruling, 2026-09-29). The directory the
/// user renamed or moved it to cannot be told by any id, and read by name it
/// was whichever plain folder held the new name: a file the user saved into
/// the renamed vault went up plain, and a plain file swapped into it put the
/// vault file's real name on the server (FAT hostile2 74400). A directory
/// holding the vault's sealed files at their own relative paths, by the bytes
/// each record last saw, IS the vault: the park follows it, the local
/// placement only -- nothing is asked of the server, and it stays parked.
///
/// Only one such directory, and none at the vault's own path: a copy of the
/// vault is two directories and follows nothing, and its sealed files are
/// held as moved out of it. Not a directory another live record's files stand
/// in by their own bytes: that is that folder, whatever it holds besides. A
/// vault whose sealed files pair nowhere (emptied, or never brought down)
/// has nothing to follow, and nothing sealed stands where it went.
fn follow_parked_vaults_by_their_contents(
    env: &ExecEnv,
    observed: &[ObservedFile],
    dirs_on_disk: &[String],
    dir_tie_breaks: &HashMap<String, u64>,
    tie_breaks: &HashMap<EntityId, u64>,
    folder_ids: &mut HashMap<String, i64>,
) -> Result<(std::collections::HashSet<EntityId>, Vec<(EntityId, String)>), ExecError> {
    let mut followed = std::collections::HashSet::new();
    let mut traded = Vec::new();
    if !env.vfs.personality().positional_file_ids {
        return Ok((followed, traded));
    }
    let parked: std::collections::HashSet<EntityId> = env
        .store
        .open_issues()?
        .into_iter()
        .filter(|i| i.kind == VAULT_ON_A_WEAK_DRIVE)
        .filter_map(|i| i.entity)
        .collect();
    if parked.is_empty() {
        return Ok((followed, traded));
    }
    let entries = all_entries(env)?;
    let mut file_paths: Vec<(&Entry, String)> = Vec::new();
    for e in &entries {
        if e.id.entity_type == EntityType::File && !e.remote_deleted && e.last_seen_sha.is_some() {
            if let Some(path) = relative_path(env, e)? {
                file_paths.push((e, path));
            }
        }
    }
    let bytes_at: HashMap<&str, &str> = observed.iter().map(|o| (o.path.as_str(), o.sha256.as_str())).collect();
    let stands = |e: &Entry, path: &str| bytes_at.get(path).copied() == e.last_seen_sha.as_deref();
    for vault in &entries {
        if vault.id.entity_type != EntityType::Folder
            || !parked.contains(&vault.id)
            || vault.status != LocalStatus::OutOfScope
            || !vault.is_encrypted
            || vault.remote_deleted
        {
            continue;
        }
        let Some(home) = relative_path(env, vault)? else { continue };
        let inside = format!("{home}/");
        let sealed: Vec<(&Entry, &str)> = file_paths
            .iter()
            .filter(|(e, _)| e.is_encrypted)
            .filter_map(|(e, p)| p.strip_prefix(&inside).map(|rel| (*e, rel)))
            .collect();
        let found_in = |dir: &str| sealed.iter().filter(|(e, rel)| stands(e, &format!("{dir}/{rel}"))).count();
        // Where the volume's ids may break a tie, the id its directory was
        // last read under says where it went when the contents cannot: its
        // only sealed file carried out in the same breath, or none brought
        // down. Contents first, the id second (a5, 2026-09-29).
        let recorded = tie_breaks.get(&vault.id).copied().filter(|id| *id != 0);
        let at_home_by_id = recorded.is_some() && dir_tie_breaks.get(&home).copied() == recorded;
        if dirs_on_disk.contains(&home) && (found_in(&home) > 0 || at_home_by_id) {
            continue;
        }
        let elsewhere = |d: &&String| **d != home && !d.starts_with(&inside) && !home.starts_with(&format!("{d}/"));
        // Not a directory another live record's files stand in by their
        // bytes: that is where the user carried the vault's files, not the
        // vault. Ruled out before choosing, so the id still gets its say: the
        // vault emptied into a plain folder and renamed in one breath read
        // the plain folder by contents, stopped there, and the renamed vault
        // went up as a new plain folder with what was saved into it.
        let anothers_in = |d: &str| {
            let there = format!("{d}/");
            file_paths.iter().any(|(e, p)| !p.starts_with(&inside) && p.starts_with(&there) && stands(e, p))
        };
        let by_contents: Vec<&String> = dirs_on_disk
            .iter()
            .filter(elsewhere)
            .filter(|d| found_in(d) > 0 && !anothers_in(d))
            .collect();
        let by_id: Vec<&String> = match recorded {
            Some(id) => dirs_on_disk
                .iter()
                .filter(elsewhere)
                .filter(|d| dir_tie_breaks.get(*d) == Some(&id) && !anothers_in(d))
                .collect(),
            None => Vec::new(),
        };
        let dest = match (by_contents.as_slice(), by_id.as_slice()) {
            ([one], _) => *one,
            ([], [one]) => *one,
            _ => continue,
        };
        let there = format!("{dest}/");
        let Some(placement) = placement_of(dest, folder_ids) else { continue };
        // The plain folder that held `dest` by name, when its own files
        // stand, by their bytes, at the vault's old path: the two traded
        // names. It is read as moved there. Left to the folder scan, which
        // on this volume has no id to find a directory by, it read as
        // deleted and was trashed, and a peer's move into it landed at the
        // top of the drive (FAT hostile2 74401, the ring rotated with the
        // swapper off).
        if let Some(holder) = folder_ids
            .get(dest.as_str())
            .copied()
            .filter(|h| *h != vault.id.server_id)
            .and_then(|h| entries.iter().find(|e| e.id == EntityId::folder(h)))
            .filter(|h| !h.is_encrypted && !h.remote_deleted && dirs_on_disk.contains(&home))
        {
            let theirs: Vec<(&Entry, &str)> = file_paths
                .iter()
                .filter_map(|(e, p)| p.strip_prefix(&there).map(|rel| (*e, rel)))
                .collect();
            if theirs.iter().any(|(e, rel)| stands(e, &format!("{home}/{rel}")))
                && !theirs.iter().any(|(e, rel)| stands(e, &format!("{dest}/{rel}")))
            {
                traded.push((holder.id, home.clone()));
            }
        }
        let mut moved = vault.clone();
        moved.synced_placement = Some(placement);
        moved.local_name = None;
        env.store.put_entry(&moved)?;
        if folder_ids.get(&home) == Some(&vault.id.server_id) {
            folder_ids.remove(&home);
        }
        folder_ids.insert((*dest).clone(), vault.id.server_id);
        followed.insert(vault.id);
    }
    Ok((followed, traded))
}

/// Record each folder's directory tie-break id, where the volume's ids may
/// break a tie, as the folder scan placed it: the id the parked-vault follow
/// reads when a vault's contents cannot say where it went.
fn note_the_tie_breaks_of_the_folders(
    env: &ExecEnv,
    folder_ids: &HashMap<String, i64>,
    dir_tie_breaks: &HashMap<String, u64>,
    tie_breaks: &HashMap<EntityId, u64>,
) -> Result<(), ExecError> {
    if env.vfs.personality().id_tie_break == jd_vfs::IdTieBreak::None {
        return Ok(());
    }
    let session = env.store.mount_session()?;
    for (path, id) in folder_ids {
        let folder = EntityId::folder(*id);
        let now = dir_tie_breaks.get(path).copied().unwrap_or(0);
        if tie_breaks.get(&folder).copied() != Some(now) {
            env.store.set_tie_break(folder, now, session)?;
        }
    }
    Ok(())
}

/// The issue a plain folder carries while its rename waits for a name the
/// server still gives to a vault parked on this weak disk.
pub(crate) const NAME_HELD_BY_A_PARKED_VAULT: &str = "name_held_by_a_parked_vault";

/// The issue a plain file carries while it is not sent under a sealed file's
/// name.
pub(crate) const NAME_OF_A_SEALED_FILE_HELD: &str = "name_of_a_sealed_file_held";

/// On a volume with no file identities, the names (comparison keys) of the
/// sealed files the server keeps, as this store knows them, that do not stand
/// at home: their last-seen bytes not at the path the server keeps them at. A
/// record that never carried bytes here counts as not home (a5's R1): leaving
/// it out, a sealed file forgotten here while the server keeps it released
/// its name one forget later. A plain file sent under one of those names, in
/// any folder, may be the sealed file's own directory read as a plain
/// folder's, and nothing here can say otherwise.
fn sealed_names_this_disk_cannot_vouch_for(
    env: &ExecEnv,
    observed: &[ObservedFile],
) -> Result<std::collections::HashSet<String>, ExecError> {
    let personality = env.vfs.personality();
    let mut out = std::collections::HashSet::new();
    if !personality.positional_file_ids {
        return Ok(out);
    }
    let at: HashMap<&str, &str> = observed.iter().map(|o| (o.path.as_str(), o.sha256.as_str())).collect();
    for e in all_entries(env)? {
        if e.id.entity_type != EntityType::File || !e.is_encrypted || e.remote_deleted {
            continue;
        }
        let mut home = e.clone();
        home.synced_placement = Some(e.remote.clone());
        home.stand_in = None;
        home.local_name = None;
        let stands = match e.last_seen_sha.as_deref() {
            Some(seen) => relative_path(env, &home)?.is_some_and(|p| at.get(p.as_str()) == Some(&seen)),
            None => false,
        };
        if !stands {
            out.insert(jd_vfs::comparison_key(&e.remote.name, &personality));
        }
    }
    Ok(out)
}

fn say_a_sealed_name_is_held(env: &ExecEnv, entry: &Entry, name: &str) -> Result<(), ExecError> {
    let detail = format!(
        "{name} is not synced: a file in one of your vaults has that name, and this drive cannot \
         keep track of which folder is the vault (FAT, exFAT, or another kind that does not keep \
         file identities), so sending it could show the vault file's name on the server. Rename \
         it, or put the vault's file back where it was."
    );
    for issue in env.store.open_issues()? {
        if issue.kind == NAME_OF_A_SEALED_FILE_HELD && issue.entity == Some(entry.id) && issue.detail != detail {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    env.store.raise_issue(Some(entry.id), NAME_OF_A_SEALED_FILE_HELD, &detail, (env.now_ms)() as i64)?;
    Ok(())
}

/// The vault parked on this weak disk (D3) that the server, as this device
/// last heard, still gives this name to in this folder.
fn parked_vault_holding(env: &ExecEnv, to: &Placement) -> Result<Option<Entry>, ExecError> {
    let parked: std::collections::HashSet<EntityId> = env
        .store
        .open_issues()?
        .into_iter()
        .filter(|i| i.kind == VAULT_ON_A_WEAK_DRIVE)
        .filter_map(|i| i.entity)
        .collect();
    if parked.is_empty() {
        return Ok(None);
    }
    let personality = env.vfs.personality();
    let wanted = jd_vfs::comparison_key(&to.name, &personality);
    // A folder whose own rename waits keeps its server name for as long as
    // it waits, so that name is held just the same: a rotation through the
    // vault's name waits all the way round. Left to the server, the next
    // folder's rename onto it was refused, and the folder read as deleted and
    // was trashed (hidden plat3 75400).
    let waiting: std::collections::HashSet<EntityId> = env
        .store
        .open_issues()?
        .into_iter()
        .filter(|i| i.kind == NAME_HELD_BY_A_PARKED_VAULT)
        .filter_map(|i| i.entity)
        .collect();
    let mut chained = None;
    for e in all_entries(env)? {
        if e.id.entity_type != EntityType::Folder
            || e.remote_deleted
            || e.remote.parent != to.parent
            || jd_vfs::comparison_key(&e.remote.name, &personality) != wanted
        {
            continue;
        }
        if parked.contains(&e.id) && e.status == LocalStatus::OutOfScope {
            return Ok(Some(e));
        }
        if waiting.contains(&e.id) {
            chained = Some(e);
        }
    }
    Ok(chained)
}

fn detect_folder_moves(
    env: &ExecEnv,
    observed: &[ObservedFile],
    dirs_on_disk: &[String],
    dir_identity: &HashMap<String, u64>,
    dir_births: &HashMap<String, u64>,
    folder_ids: &mut HashMap<String, i64>,
    followed: &std::collections::HashSet<EntityId>,
    traded_with_a_vault: &[(EntityId, String)],
) -> Result<FolderScan, ExecError> {
    let mut scan = FolderScan::default();
    // Where each tracked folder believes it is, and which of those are gone.
    let mut tracked: HashMap<String, EntityId> = HashMap::new();
    // Which directory each tracked folder knows to be its own, where it
    // knows (`record_directory_identities`), and which are vaults.
    let mut record_identity: HashMap<EntityId, u64> = HashMap::new();
    // The birth beside each of those ids, where the record knows one.
    let mut record_birth: HashMap<EntityId, u64> = HashMap::new();
    let mut encrypted: std::collections::HashSet<EntityId> = std::collections::HashSet::new();
    // Records that resolve to a path another record holds; see the tracked
    // loop. They join the contested pool below.
    let mut evicted: Vec<(String, EntityId)> = Vec::new();
    // Records in the walk that the server has only named: no agreement, no
    // stand-in, never standing anywhere here.
    let mut never_stood: std::collections::HashSet<EntityId> = std::collections::HashSet::new();
    // Records tied here that the server has deleted.
    let mut leaving: std::collections::HashSet<EntityId> = std::collections::HashSet::new();
    // Where each directory identity stands on this disk right now.
    let where_id_stands: HashMap<u64, &String> = dir_identity
        .iter()
        .filter(|(_, id)| **id != 0)
        .map(|(path, id)| (*id, path))
        .collect();
    // Which folder the record puts each tracked file in -- the parent id read
    // straight off the entry, no path resolved. Used only by the cheap question
    // below, and gathered here so it costs a field rather than a second pass.
    let mut believed_parent: HashMap<u64, Option<i64>> = HashMap::new();
    let entries = all_entries(env)?;
    // An id standing UNDER the record's own path is not the record's
    // directory, whatever the record says: nothing can be moved inside
    // itself, so the disk has handed a deleted directory's id to a new one
    // made under the folder's name (the reset's WP3 finding C5, frozen
    // 1073449: the vault claim read its folder into a subfolder of itself,
    // and the no-mint hold then kept that subfolder from ever syncing). Such
    // a record knows no directory this pass -- for every reader below, the
    // `owned` map included (a server folder arriving at that directory's
    // path would otherwise wait for an owner's move that is never planned)
    // -- and its identity is re-read from its agreed path by
    // `record_directory_identities`.
    let recycled_under_own_path = |e: &Entry, id: u64| -> Result<bool, ExecError> {
        Ok(agreed_path(env, e)?.is_some_and(|p| {
            where_id_stands.get(&id).is_some_and(|at| at.starts_with(&format!("{p}/")))
        }))
    };
    // Which live folder record knows each directory id as its own, before
    // the walk below asks about any path.
    let mut owned: HashMap<u64, EntityId> = HashMap::new();
    for e in &entries {
        if e.id.entity_type != EntityType::Folder || e.id.is_provisional() || e.remote_deleted {
            continue;
        }
        let Some(id) = e.synced_fingerprint.map(|fp| fp.file_id).filter(|id| *id != 0) else {
            continue;
        };
        if recycled_under_own_path(e, id)? {
            continue;
        }
        owned.insert(id, e.id);
    }
    for entry in entries {
        if entry.id.entity_type == EntityType::File {
            // Known by its own file, as the folder's contents are below.
            if let (Some(_), Some(own_id)) = (entry.synced_fingerprint, entry.own_file_id()) {
                believed_parent.insert(own_id, entry.local_placement().parent);
            }
            continue;
        }
        if entry.id.entity_type != EntityType::Folder || entry.id.is_provisional() {
            continue;
        }
        let own_id = match entry.synced_fingerprint.map(|fp| fp.file_id).filter(|id| *id != 0) {
            // Recycled under its own path: see `recycled_under_own_path`.
            Some(id) if recycled_under_own_path(&entry, id)? => None,
            other => other,
        };
        // Where its AGREEMENT puts it: a folder whose directory the user has
        // moved and not yet agreed is found moved from here, every pass,
        // until the move is agreed. Read where it stands, it was never moved
        // at all, and the move was never told to the server.
        if let Some(path) = agreed_path(env, &entry)? {
            // A folder the server has told us about and nothing has created
            // here yet -- no agreement, no stand-in -- has never stood
            // anywhere, so a directory at its name is not evidence that it
            // is present. Read by path alone it took the path: the directory
            // there was accounted for, the record whose OWN directory it was
            // could not be found at it (a plain folder corroborates and never
            // claims, and a tracked path is no candidate), that record was
            // read as deleted and its files as moved into the newcomer, and
            // the create then adopted the directory. The user renaming a
            // plain folder onto a name a peer had just used on the server
            // lost the folder's identity to the peer's. Where the directory
            // is KNOWN to be another live record's own, this record is not
            // at it: its create will meet that directory, decline it by
            // identity, and wait behind the owner's move. Where nobody knows
            // the directory, today's reading stands (the user's own folder
            // of that name is the server's folder arriving with an id).
            let named_only = entry.synced_placement.is_none() && entry.stand_in.is_none();
            if named_only
                && dir_identity
                    .get(&path)
                    .copied()
                    .filter(|id| *id != 0)
                    .is_some_and(|here| owned.get(&here).is_some_and(|o| *o != entry.id))
            {
                // Out of the path map too (own key only), so the directory
                // is a candidate for the record that owns it.
                if folder_ids.get(&path) == Some(&entry.id.server_id) {
                    folder_ids.remove(&path);
                }
                continue;
            }
            // Two records can resolve to ONE path -- a record that lags a
            // name trade beside the record the server has since put there --
            // and a map keyed by path can hold only one. It used to keep
            // whichever came last: the other simply vanished from the scan,
            // neither present nor in any pool, was read as gone, and was
            // re-materialized as a fresh directory while its real one stood
            // orphaned and was minted plain (clean2 74037, the vault). The
            // directory standing at the path decides who is at it: the record
            // whose own id it carries keeps the path, the other is evicted
            // into the contested pool -- its path holds a directory that is
            // not its own -- and found from there by identity or contents.
            // Records that contest no tie (`Entry::holds_its_tie`): one only
            // named here, which has never stood anywhere, and one deleted on
            // the server, which stood here and is on its way out.
            let yields = !entry.holds_its_tie();
            if named_only {
                never_stood.insert(entry.id);
            } else if yields {
                leaving.insert(entry.id);
            }
            match tracked.get(&path).copied() {
                None => {
                    tracked.insert(path, entry.id);
                }
                // A record the server has only named never contests a path a
                // record tied here resolves to, whichever the walk meets
                // first. The tied record's directory is its own while the tie
                // stands, whatever identity can or cannot say about it: after
                // a restore every recorded id stands nowhere, and the later
                // record took the path below, the tied one was evicted, read
                // as deleted, and its files moved into the newcomer
                // (B-RESTORE). The newcomer is in no pool: it has never stood
                // anywhere to be found from, and its create meets the
                // directory as another record's. A record the server has
                // deleted yields the same way, but to the pool: it stood here,
                // and its own directory may stand elsewhere to be found. Kept
                // at the path, a deleted folder held its old directory against
                // the folder the server had since made under its name, and a
                // vault's sealed files went up in the clear down the line
                // (win plat3 75406).
                Some(holder) if yields && !never_stood.contains(&holder) && !leaving.contains(&holder) => {
                    if named_only {
                        continue;
                    }
                    evicted.push((path, entry.id));
                }
                Some(holder) if !yields && (never_stood.contains(&holder) || leaving.contains(&holder)) => {
                    if leaving.contains(&holder) {
                        evicted.push((path.clone(), holder));
                    }
                    tracked.insert(path, entry.id);
                }
                Some(holder) => {
                    let here = dir_identity.get(&path).copied().filter(|id| *id != 0);
                    let holder_id = record_identity.get(&holder).copied();
                    let holder_owns = here.is_some() && holder_id == here;
                    let mine = here.is_some() && own_id == here;
                    // Known to stand somewhere ELSE on this disk: not at this
                    // path, whoever else is.
                    let elsewhere = |id: Option<u64>| {
                        id.is_some_and(|id| Some(id) != here && where_id_stands.contains_key(&id))
                    };
                    // The one that owns the directory here stays; failing
                    // that, the one known to stand elsewhere goes.
                    let evict_holder = (mine && !holder_owns)
                        || (!mine && !holder_owns && elsewhere(holder_id) && !elsewhere(own_id));
                    let evict_newcomer = (holder_owns && !mine)
                        || (!mine && !holder_owns && elsewhere(own_id) && !elsewhere(holder_id));
                    if evict_holder && !evict_newcomer {
                        evicted.push((path.clone(), holder));
                        tracked.insert(path, entry.id);
                    } else if evict_newcomer && !evict_holder {
                        evicted.push((path, entry.id));
                    } else if !mine && !holder_owns && elsewhere(holder_id) && elsewhere(own_id) {
                        // Neither is here, and both are known to stand
                        // somewhere else: neither takes the path, and both go
                        // to the pool, where each is found by its own
                        // directory. Left to "the later takes the path", the
                        // earlier record fell out of the scan entirely -- in
                        // no pool, never claimed, read as deleted -- and a
                        // VAULT so dropped was re-created while its real
                        // directory was minted plain and its sealed file went
                        // up in the clear (the reset's C8, kill2 75110).
                        evicted.push((path.clone(), holder));
                        evicted.push((path.clone(), entry.id));
                        tracked.remove(&path);
                    } else if followed.contains(&holder) {
                        // A parked vault this pass found by its own sealed
                        // files keeps the path it was found at; the record
                        // that only names it goes to the pool.
                        evicted.push((path, entry.id));
                    } else {
                        // Identity cannot say (neither knows its directory, or
                        // both claim it): today's reading, the later record
                        // takes the path. The earlier one goes to the pool
                        // rather than nowhere: no record leaves the scan
                        // without a pool to be found from.
                        evicted.push((path.clone(), holder));
                        tracked.insert(path, entry.id);
                    }
                }
            }
        }
        if let Some(id) = own_id {
            record_identity.insert(entry.id, id);
            if let Some(birth) = entry.synced_fingerprint.map(|fp| fp.birth_ns).filter(|b| *b != 0) {
                record_birth.insert(entry.id, birth);
            }
        }
        if entry.is_encrypted {
            encrypted.insert(entry.id);
        }
    }
    // A folder already held because its directory and its files went
    // different ways stays held -- present, unmoved, its directory not
    // adopted -- until the user puts one of them back. The hold is the open
    // issue itself: it lifts when the folder's directory stands at its own
    // path again (corroborated above) or is gone, and either way the issue
    // is dismissed here. Without this the hold lasted one pass: the files,
    // once placed elsewhere, no longer proposed anything, and the folder read
    // as an ordinary deletion the pass after the user was told it would not.
    //
    // Where each folder's own directory stands PROVEN: its id and its birth
    // both the record's, on a volume whose identities hold. A disk hands a
    // deleted directory's id to the next one made (`rm -rf A; mkdir B` gives
    // B A's inode on ext4), which is why a plain folder's id alone claims
    // nothing; but the new directory arrives with a new birth, so the pair
    // names the one directory the record knew and no other. A proven
    // directory is the folder, wherever it went and whatever is in it --
    // except under one of the engine's own scratch names: a park is
    // mid-cycle, not a place the folder went.
    let proven_at: HashMap<EntityId, &String> = if env.vfs.personality().stable_file_identity {
        record_birth
            .iter()
            .filter_map(|(id, birth)| {
                let at = *where_id_stands.get(record_identity.get(id)?)?;
                (dir_births.get(at) == Some(birth) && !at.split('/').any(jd_vfs::is_internal)).then_some((*id, at))
            })
            .collect()
    } else {
        HashMap::new()
    };
    let mut held_ids: Vec<EntityId> = Vec::new();
    let mut held_by_issue: Vec<(EntityId, i64, Option<&String>)> = Vec::new();
    for issue in env.store.open_issues()? {
        if issue.kind != DIRECTORY_DISAGREES {
            continue;
        }
        let Some(id) = issue.entity else { continue };
        let stands_at = record_identity.get(&id).and_then(|rid| where_id_stands.get(rid)).copied();
        held_by_issue.push((id, issue.issue_id, stands_at));
    }
    for (id, issue_id, stands_at) in held_by_issue {
        // Lifted only when the directory stands at the record's own path
        // again (corroborated above) or nowhere, or is proven the folder's
        // own wherever it stands: then there is nothing left to ask, and the
        // folder goes where its directory went like any other. Never because
        // something else took it: that is the outcome the hold exists to
        // prevent.
        let at_own_path = tracked
            .iter()
            .any(|(path, tid)| *tid == id && stands_at == Some(path));
        match stands_at {
            None => env.store.dismiss_issue(issue_id)?,
            Some(_) if at_own_path => env.store.dismiss_issue(issue_id)?,
            Some(d) if proven_at.get(&id) == Some(&d) => env.store.dismiss_issue(issue_id)?,
            Some(d) => {
                scan.present.insert(id);
                scan.held.insert(d.clone());
                held_ids.push(id);
            }
        }
    }

    // The path map is keyed by path too, and `folder_paths` kept the LAST of
    // two records that resolved to one path. `tracked` holds one record per
    // path decided by identity, so the map takes that: right by
    // construction, and every removal below is own-key-only -- a record
    // matched elsewhere drops the key only if it is the one at it -- so a
    // present record's directory is never dropped from the map and minted as
    // a stranger behind a stale twin's departure (plat3 75412 with the swap
    // verb off: the plain ring at its own path, its twin's stale record
    // claimed away, the key dropped, the directory minted new).
    //
    // Except a path `folder_paths` gave a folder not yet sent, minted from
    // the directory there: `tracked` holds no such record, and a tied record
    // it puts at that path has left it (`folder_paths`). Taken back for it,
    // the path was dropped the moment its move was found, and the directory
    // minted a second time (plain2 75217, 75283).
    for (path, id) in tracked.iter() {
        if folder_ids.get(path).is_some_and(|f| EntityId::folder(*f).is_provisional()) {
            continue;
        }
        folder_ids.insert(path.clone(), id.server_id);
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
    // Two folders trading names is the one move that satisfies both of those
    // and has still happened: nothing has gone, because each name still holds a
    // directory, and there is nothing unaccounted, because each directory is
    // one the engine already has a folder for. Taken on its own the test above
    // returns from a swap without looking, which is where a vault's contents
    // left it in the clear (Defect AD).
    //
    // What completes it is asking whether any tracked file has changed folders,
    // which no swap can be true without. It is answered from the parent id on
    // the record and the path map -- a lookup per file on the disk, and not one
    // path resolved. An ordinary single file moved between two folders answers
    // yes as well and pays for the evidence below; that is a scan where
    // something really did move, not the settled case this exit is here for.
    //
    // Files answer only for files the engine agreed on. Two folders with none
    // in them -- or whose files went back to the names they had while the
    // directories traded -- trade without a file changing folders, and the
    // exit took the records' word for where each folder is. The directories
    // say otherwise, and they are asked first: a tracked path standing on a
    // directory another live folder knows as its own is a trade, whatever the
    // files say. Left to the exit, the server's next rename of either folder
    // was refused by that directory's identity on every pass (the reset's C9;
    // plat3 75424). One lookup per tracked folder, and exact: both sides know
    // the identity.
    let crossed = || -> bool {
        tracked.iter().any(|(path, id)| {
            dir_identity
                .get(path)
                .copied()
                .filter(|d| *d != 0)
                .is_some_and(|here| owned.get(&here).is_some_and(|o| o != id))
        })
    };
    let a_file_changed_folders = || -> bool {
        for file in observed {
            let Some(believed) = believed_parent.get(&file.fingerprint.file_id) else {
                continue;
            };
            let on_disk = match file.path.rsplit_once('/') {
                None => None,
                Some((dir, _)) => match folder_ids.get(dir) {
                    Some(id) => Some(*id),
                    None => continue,
                },
            };
            if *believed != on_disk {
                return true;
            }
        }
        false
    };
    if missing.is_empty() && !unaccounted && !crossed() && !a_file_changed_folders() {
        return Ok(scan);
    }

    // What the engine believes about the files in each folder, keyed by the
    // folder record whose files they are -- never by the path that record
    // resolves to. Two records can resolve to one path (a server folder made
    // under a name the user's own folder has just left), and credited by path
    // the newcomer, which has never held a file, was matched on the other
    // record's files and given its directory; the owner, its directory proven
    // by identity, read as deleted (soak run 1633).
    let mut children: HashMap<EntityId, Vec<(String, u64)>> = HashMap::new();
    let mut known_file_ids: std::collections::HashSet<u64> = std::collections::HashSet::new();
    // Every folder in the store, as `relative_path` resolves each parent: not
    // `all_entries`, whose walk from the root by server parent leaves out a
    // folder it cannot reach and would stop the credit there.
    let folder_parent: HashMap<i64, Option<i64>> = env
        .store
        .every_entry()?
        .into_iter()
        .filter(|e| e.id.entity_type == EntityType::Folder)
        .map(|e| (e.id.server_id, e.local_placement().parent))
        .collect();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::File {
            continue;
        }
        // A file on this disk with an agreement, known by its own file.
        if entry.synced_fingerprint.is_none() {
            continue;
        }
        let (Some(own_id), Some(path)) = (entry.own_file_id(), relative_path(env, &entry)?) else {
            continue;
        };
        known_file_ids.insert(own_id);
        // Every folder above the file is credited with it, at the path
        // relative to that folder. A folder is found by what it holds, and a
        // folder whose files all sit in folders of its own holds them just
        // the same: a rename keeps the shape inside, so `Sub/f.txt` under the
        // new name is the same evidence `f.txt` would be. Credited with its
        // direct files only, the commonest shape of a folder -- subfolders
        // and nothing loose -- could not be matched at all, and a vault of
        // that shape was trashed for a rename (Defect Q).
        // The same walk `relative_path` made: the file's parent chain, one
        // folder per path component.
        let parts: Vec<&str> = path.split('/').collect();
        let mut folder = entry.local_placement().parent;
        let mut depth = 1;
        while let Some(id) = folder {
            if depth >= parts.len() {
                break;
            }
            children
                .entry(EntityId::folder(id))
                .or_default()
                .push((parts[parts.len() - depth..].join("/"), own_id));
            folder = folder_parent.get(&id).copied().flatten();
            depth += 1;
        }
    }

    let by_path: HashMap<&str, &ObservedFile> =
        observed.iter().map(|o| (o.path.as_str(), o)).collect();

    // A known child FOLDER is contents too, by identity: for each tracked
    // folder, the ids of the folders it holds, where they know them. The only
    // evidence a folder of subfolders and nothing loose has once a subfolder
    // was renamed too (Defect Q's shape, one level deeper: `A -> X`, `X/B ->
    // X/C`). Counted ONLY beside the parent's own id standing at the same
    // candidate: a child under a directory says where the child went, not
    // where the parent did -- the user moving `B` into a brand-new `X` and
    // deleting `A` puts `B` under `X` just the same, and pairing `A` to `X`
    // on that would carry `A`'s grants onto a folder the user made fresh (the
    // decision `renaming_a_folder_and_its_subfolder_together…` records). The
    // parent's id at `X` is what tells the two apart, and a plain folder's
    // own id may not claim on its own; the two together are a rename.
    let mut child_folders: HashMap<EntityId, Vec<u64>> = HashMap::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder {
            continue;
        }
        let (Some(parent), Some(own)) = (
            entry.local_placement().parent,
            entry.synced_fingerprint.map(|fp| fp.file_id).filter(|id| *id != 0),
        ) else {
            continue;
        };
        child_folders.entry(EntityId::folder(parent)).or_default().push(own);
    }
    // Is one of this folder's child directories standing directly under the
    // directory at `path`, AND is that directory the folder's own? Directly:
    // the child's own directory, not a file credited up the chain.
    let child_folder_under = |id: &EntityId, path: &str| -> bool {
        let own_here = record_identity
            .get(id)
            .is_some_and(|own| dir_identity.get(path).is_some_and(|here| here == own));
        own_here
            && child_folders.get(id).is_some_and(|kids| {
                kids.iter().any(|kid| {
                    where_id_stands
                        .get(kid)
                        .is_some_and(|at| at.rsplit_once('/').map(|(dir, _)| dir) == Some(path))
                })
            })
    };

    // Does the directory standing at this path hold any of the files this
    // folder is known to contain? Identity on this volume, not names: the same
    // file_id at the same place is the folder itself, and nothing else can
    // counterfeit it.
    let corroborated = |path: &String| -> bool {
        // The directory's own identity, where both sides know it, settles it
        // before any contents are consulted: the record's directory standing
        // at its path IS the folder, however empty (the files that stand
        // elsewhere moved OUT of it -- Defect AG), and a directory with
        // another identity is NOT the folder, however full (a stranger wearing
        // its name). Where either side does not know, the contents decide as
        // they always did.
        // Both sides must KNOW, and the record's id must stand somewhere on
        // this disk: an id that stands nowhere (a restore, a re-created root,
        // a directory deleted and remade under its name) is no evidence at
        // all, never "not mine" -- read that way, every folder on a restored
        // disk was contested for a pass.
        if let (Some(rec), Some(here)) = (
            tracked.get(path).and_then(|id| record_identity.get(id)),
            dir_identity.get(path).filter(|id| **id != 0),
        ) {
            if where_id_stands.contains_key(rec) {
                return rec == here;
            }
        }
        let Some(kids) = tracked.get(path).and_then(|id| children.get(id)) else {
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
    let mut displaced: Vec<(String, EntityId)> = tracked
        .iter()
        .filter(|(path, _)| {
            dirs_on_disk.contains(*path) && !corroborated(path) && holds_nothing_known(path)
        })
        .map(|(path, id)| (path.clone(), *id))
        .collect();
    // Folders whose directory is standing but is not theirs, and is not a
    // rebuilt shell either -- it is full of files the engine knows, belonging
    // to somebody else. That is the one shape `displaced` deliberately refuses,
    // because on its own it is also what one file moved out of a folder looks
    // like. Read as a RING it is unambiguous, and that is the only way it is
    // read below.
    let mut contested: Vec<(String, EntityId)> = tracked
        .iter()
        .filter(|(path, _)| {
            dirs_on_disk.contains(*path) && !corroborated(path) && !holds_nothing_known(path)
        })
        .map(|(path, id)| (path.clone(), *id))
        .collect();
    // An evicted record's path holds a directory that is not its own by
    // definition; whether that directory holds anything known is beside the
    // point, because it is somebody else's. Contested, and looked for.
    for (path, id) in &evicted {
        if !contested.iter().any(|(_, c)| c == id) {
            contested.push((path.clone(), *id));
        }
    }
    if missing.is_empty() && displaced.is_empty() && contested.is_empty() {
        return Ok(scan);
    }
    // Every file this disk holds, by its identity on the volume. Used only to
    // answer the two questions below, and only when there is a displaced or
    // contested folder to ask them about.
    let by_file_id: HashMap<u64, &ObservedFile> = if displaced.is_empty() && contested.is_empty() {
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
    let moved_wholesale = |id: &EntityId, candidate: &str| -> bool {
        let mut here = 0;
        // A child folder standing under the candidate, beside the folder's
        // own id there, counts as here -- and a file inside THAT child
        // directory is here too, whatever the child is now called: a
        // subfolder renamed along with its parent carries its files inside
        // it, and asking for them at the old relative path would read the
        // whole folder as moved elsewhere.
        let mut child_dirs_here: Vec<&String> = Vec::new();
        {
            if child_folder_under(id, candidate) {
                here += 1;
                if let Some(kids) = child_folders.get(id) {
                    for kid in kids {
                        if let Some(at) = where_id_stands.get(kid) {
                            if at.rsplit_once('/').map(|(dir, _)| dir) == Some(candidate) {
                                child_dirs_here.push(at);
                            }
                        }
                    }
                }
            }
        }
        let kids = children.get(id).map(|k| k.as_slice()).unwrap_or(&[]);
        for (name, file_id) in kids {
            match by_file_id.get(file_id) {
                // Not on this disk at all any more. Deleted, or never written
                // here. It says nothing either way, so it does not object.
                None => continue,
                Some(o) if o.path == format!("{candidate}/{name}") => here += 1,
                Some(o) if child_dirs_here.iter().any(|d| o.path.starts_with(&format!("{d}/"))) => {
                    here += 1
                }
                Some(_) => return false,
            }
        }
        here > 0
    };

    let mut claimed: Vec<EntityId> = held_ids.clone();
    // Shallowest first, so a renamed parent is resolved before the folders
    // inside it are asked where they live.
    // A held directory (see `FolderScan::held`) is somebody's and is not a
    // candidate for anyone else.
    let mut candidates: Vec<&String> = dirs_on_disk
        .iter()
        .filter(|d| !folder_ids.contains_key(*d) && !scan.held.contains(*d))
        .collect();
    candidates.sort_by_key(|d| (depth_of(d), d.to_string()));
    let mut taken: std::collections::HashSet<&String> = std::collections::HashSet::new();

    // A plain folder that traded names with a parked vault the pass followed
    // by its contents (`follow_parked_vaults_by_their_contents`): it stands
    // at the vault's old path, as its own files say.
    for (id, path) in traded_with_a_vault {
        let Some(path) = dirs_on_disk.iter().find(|d| *d == path) else { continue };
        if claimed.contains(id) || taken.contains(path) || scan.held.contains(path.as_str()) {
            continue;
        }
        claimed.push(*id);
        taken.insert(path);
        scan.present.insert(*id);
        folder_ids.insert(path.clone(), id.server_id);
        match placement_of(path, folder_ids) {
            Some(placement) => {
                let Some(mut entry) = env.store.get_entry(*id)? else { continue };
                if entry.synced_placement.as_ref() != Some(&placement) {
                    scan.moves.insert(*id, placement);
                } else if entry.local_name.is_some() {
                    // Back at its agreed name -- the user traded the names
                    // back -- while it was waiting under the vault's: the
                    // wait is over, and so is the name it wore for it.
                    entry.local_name = None;
                    env.store.put_entry(&entry)?;
                    for issue in env.store.open_issues()? {
                        if issue.kind == NAME_HELD_BY_A_PARKED_VAULT && issue.entity == Some(*id) {
                            env.store.dismiss_issue(issue.issue_id)?;
                        }
                    }
                }
            }
            None => scan.deferred.push((*id, path.clone())),
        }
    }

    // Folders that traded names with each other.
    //
    // A swap leaves nothing missing and nothing displaced: every path still
    // holds a directory, and every one of those directories is full of files
    // the engine knows -- they are just the OTHER folder's files. Asked one
    // path at a time there is no question to fail, so the engine reads the
    // whole thing as every file being re-parented, and a vault's contents
    // leave it in the clear under their real names (Defect AD).
    //
    // The evidence taken is a closed permutation and nothing less. Each
    // member's directory must hold exactly one other member's contents
    // WHOLESALE, and following who-left-where must come back to where it
    // started. One file moved out does not look like this; a folder emptied
    // into another does not close; two folders whose contents both landed
    // under one directory cannot be told apart and are refused. Because the
    // ring is what carries the evidence, this can be read from the very
    // shape `displaced` has to refuse -- with no need to widen `displaced`,
    // which drags a folder after a single file.
    //
    // By identity first: the directory standing at a contested path that
    // carries another tracked folder's own id is that folder arrived, whatever
    // its files say. Contents alone could not close a ring whose plain members
    // had had their files re-parented across passes (hostile2 74424: the three
    // ring directories stood rotated, each record knowing its id, and only the
    // vault's contents were still whole -- one arrival, no ring, every file
    // inside withdrawn as not-a-drag for ever). Within a ring every member is
    // live, tracked and standing, so this is not a plain folder claiming a
    // stranger; it is three folders agreeing on where each other went.
    // Contents decide where either side does not know.
    //
    // Not for a HELD record (its hold is about its own directory and it is
    // not placed by anything but the user), not onto a held path, and not
    // when more than one live record carries the id -- two records can carry
    // one id for a pass after a trade, and picking one by map order would be
    // picking by hash order; that is ambiguous, and contents decide.
    // Over displaced paths as well as contested ones: a path whose directory
    // holds nothing known reads as a rebuilt shell, but a shell carrying
    // another tracked folder's own id is that folder, arrived. Left out, a
    // vault and a plain folder that traded names after the plain one's files
    // had moved out never closed as a ring: both read as present, and the path
    // map, reading each directory by its identity, filed the plain folder's
    // new files into the vault's record and back (kill2 75100).
    let ring_paths: Vec<(String, EntityId)> = contested.iter().chain(displaced.iter()).cloned().collect();
    let mut arrived_at: HashMap<&String, EntityId> = HashMap::new();
    for (path, _) in ring_paths.iter() {
        let by_identity = if scan.held.contains(path.as_str()) {
            None
        } else {
            dir_identity
                .get(path.as_str())
                .filter(|id| **id != 0)
                .and_then(|here| {
                    let mut carriers = record_identity
                        .iter()
                        .filter(|(id, rid)| {
                            *rid == here
                                && tracked.get(path.as_str()) != Some(*id)
                                && !held_ids.contains(*id)
                        })
                        .map(|(id, _)| *id);
                    match (carriers.next(), carriers.next()) {
                        (Some(one), None) => Some(one),
                        _ => None,
                    }
                })
        };
        if let Some(id) = by_identity {
            arrived_at.insert(path, id);
            continue;
        }
        let mut found: Option<EntityId> = None;
        let mut ambiguous = false;
        for (other_path, other_id) in tracked.iter() {
            if other_path == path || !moved_wholesale(other_id, path) {
                continue;
            }
            if found.is_some() {
                ambiguous = true;
                break;
            }
            found = Some(*other_id);
        }
        if let (Some(id), false) = (found, ambiguous) {
            arrived_at.insert(path, id);
        }
    }
    // Where each arriving folder ended up, so a ring can be walked from the
    // folder that LEFT a path to the path it went to.
    let where_it_went: HashMap<EntityId, &String> =
        arrived_at.iter().map(|(p, id)| (*id, *p)).collect();
    let mut ring_members: Vec<(&String, EntityId)> = Vec::new();
    let mut in_a_ring: std::collections::HashSet<&String> = std::collections::HashSet::new();
    for (start_path, _) in ring_paths.iter() {
        if in_a_ring.contains(start_path) {
            continue;
        }
        let mut ring: Vec<(&String, EntityId)> = Vec::new();
        let mut at: &String = start_path;
        let closed = loop {
            let Some(arriving) = arrived_at.get(at) else { break false };
            ring.push((at, *arriving));
            // Who was standing here, and where did they go? A ring closes only
            // if that walk returns to the path it started from.
            let Some(leaving) = tracked.get(at) else { break false };
            let Some(next) = where_it_went.get(leaving) else { break false };
            if *next == start_path {
                break true;
            }
            if ring.len() > ring_paths.len() {
                break false;
            }
            at = *next;
        };
        if !closed {
            continue;
        }
        for (p, id) in ring {
            in_a_ring.insert(p);
            ring_members.push((p, id));
        }
    }
    // Shallowest first, so a ring inside a renamed parent is placed after the
    // parent it lives under. Claimed in full before any placement is worked
    // out: every path in a ring is another member's old path, so the map has
    // to describe the whole ring before it describes any of it.
    ring_members.sort_by_key(|(p, _)| (depth_of(p), p.to_string()));
    for (path, id) in &ring_members {
        claimed.push(*id);
        taken.insert(path);
        scan.present.insert(*id);
        folder_ids.insert((*path).clone(), id.server_id);
    }
    for (path, id) in &ring_members {
        match placement_of(path, folder_ids) {
            Some(placement) => {
                let unchanged = env
                    .store
                    .get_entry(*id)?
                    .and_then(|e| e.synced_placement.clone())
                    .is_some_and(|p| p == placement);
                if !unchanged {
                    scan.moves.insert(*id, placement);
                }
            }
            None => scan.deferred.push((*id, (*path).clone())),
        }
    }

    // Where the SERVER is moving each tracked folder to, as the path it would
    // occupy on this disk. A rename in flight from the other side is invisible
    // to `tracked`, which is what this disk believes; matching a folder onto
    // a name the server is already handing to another folder puts two moves
    // at one slot, and neither yields. Keyed by the full path, not the bare
    // name, so a trade one level down is covered as well as one at the root.
    //
    // The parent's path here is its CURRENT local placement. A parent that is
    // itself mid-rename gives a key that is stale for one pass, in either
    // direction: a false refusal is a one-pass wait that clears when the
    // parent's placement updates; a false allow is the nested-trade case,
    // which is unmeasured, and no wider than that.
    let mut remote_wants: HashMap<String, EntityId> = HashMap::new();
    for fid in tracked.values() {
        let Some(e) = env.store.get_entry(*fid)? else { continue };
        let path = match e.remote.parent {
            None => Some(e.remote.name.clone()),
            Some(pid) => match env.store.get_entry(EntityId::folder(pid))? {
                Some(parent) => {
                    relative_path(env, &parent)?.map(|pp| format!("{pp}/{}", e.remote.name))
                }
                None => None,
            },
        };
        if let Some(path) = path {
            remote_wants.insert(path, *fid);
        }
    }

    // Chains, by identity: the open cousin of a ring. A contested folder's own
    // directory stands at another tracked folder's path, that folder's own
    // directory stands at a third path, and so on until a path no record
    // holds. Every member but the last lands on a path its live, tracked
    // owner has left, so, as in a ring, this is folders agreeing on where
    // each other went, not a plain folder claiming a stranger. The last
    // lands on an untracked directory, where a plain folder's id alone
    // proves nothing: its files must say so too, wholesale, as they would
    // for any contested folder -- unless its directory is proven there (id
    // and birth), which needs no files. Not onto a held path or one the
    // server is giving another folder, and not for a held record or an id
    // two records carry. Before this a chain never resolved: its end waited for the
    // member moving into its old path, which could not be placed until the
    // end left, and the member was read as gone (kill2 75123, with the path
    // map reading its files as in it: the server's trade of the plain ring
    // folder ended in a conflict-named copy).
    let carrier_of = |rid: u64| -> Option<EntityId> {
        let mut carriers = record_identity.iter().filter(|(_, r)| **r == rid).map(|(id, _)| *id);
        match (carriers.next(), carriers.next()) {
            (Some(one), None) => Some(one),
            _ => None,
        }
    };
    // A chain may start from a folder whose old path is empty as well as from
    // one standing in another's way: B renamed to a new name and then A
    // renamed onto the B it left leaves A's path empty, and A at the head of
    // the chain. Walked only from the folders in the way, the chain started
    // at B's path, ended one link long and was dropped; A was read as deleted
    // and its directory, standing at B, was minted as a new folder carrying
    // its id -- refused at the disk by identity for ever after (plat3 75415,
    // swaps off). The head's first link is proven the way every other one
    // is, by its own directory standing at the next path.
    let pool: Vec<(String, EntityId)> = ring_paths.iter().chain(missing.iter()).cloned().collect();
    let old_path_of = |id: EntityId| pool.iter().find(|(_, e)| *e == id).map(|(p, _)| p.clone());
    let mut chains: Vec<Vec<(&String, EntityId)>> = Vec::new();
    for (_, head) in pool.iter() {
        let mut links: Vec<(&String, EntityId)> = Vec::new();
        let mut cur = *head;
        let complete = loop {
            if claimed.contains(&cur) || held_ids.contains(&cur) || chains.iter().flatten().any(|(_, e)| *e == cur) {
                break false;
            }
            let Some(old) = old_path_of(cur) else { break false };
            let Some(rid) = record_identity.get(&cur).copied() else { break false };
            if carrier_of(rid) != Some(cur) {
                break false;
            }
            let Some(at) = where_id_stands.get(&rid).copied() else { break false };
            if *at == old
                || taken.contains(at)
                || scan.held.contains(at.as_str())
                || links.iter().any(|(p, _)| *p == at)
                // The server naming the path's own holder there is the name
                // the holder is leaving in this chain, not another folder
                // arriving.
                || remote_wants.get(at).is_some_and(|w| *w != cur && tracked.get(at) != Some(w))
            {
                break false;
            }
            links.push((at, cur));
            match tracked.get(at).copied() {
                // The end: a path no record holds. Its files must agree --
                // or its own directory is proven there, which needs no files,
                // as a vault's needs none.
                None => {
                    break encrypted.contains(&cur)
                        || proven_at.get(&cur) == Some(&at)
                        || (children.get(&cur).is_some_and(|kids| {
                            kids.iter().any(|(name, file_id)| {
                                by_path
                                    .get(format!("{at}/{name}").as_str())
                                    .is_some_and(|o| o.fingerprint.file_id == *file_id)
                            })
                        }) && moved_wholesale(&cur, at));
                }
                // Its holder was already placed elsewhere this pass.
                Some(holder) if claimed.contains(&holder) => break true,
                Some(holder) if holder == cur => break false,
                Some(holder) => cur = holder,
            }
        };
        if complete && links.len() > 1 {
            chains.push(links);
        }
    }
    let chain_members: Vec<(&String, EntityId)> = chains.into_iter().flatten().collect();
    for (path, id) in &chain_members {
        claimed.push(*id);
        taken.insert(path);
        scan.present.insert(*id);
        if let Some(old) = old_path_of(*id) {
            if folder_ids.get(&old) == Some(&id.server_id) {
                folder_ids.remove(&old);
                // The path a member left is free for whoever's directory
                // stands there, judged as any candidate is. Left out, the
                // head of a chain walked from another head -- a third folder
                // renamed onto the path the first member left -- had nowhere
                // to be matched, read as gone, and its directory was minted
                // as a new folder.
                if let Some(dir) = dirs_on_disk.iter().find(|d| **d == old) {
                    if !candidates.contains(&dir) && !taken.contains(dir) && !scan.held.contains(dir.as_str()) {
                        candidates.push(dir);
                    }
                }
            }
        }
    }
    candidates.sort_by_key(|d| (depth_of(d), d.to_string()));
    for (path, id) in &chain_members {
        folder_ids.insert((*path).clone(), id.server_id);
    }
    for (path, id) in &chain_members {
        match placement_of(path, folder_ids) {
            Some(placement) => {
                let unchanged = env
                    .store
                    .get_entry(*id)?
                    .and_then(|e| e.synced_placement.clone())
                    .is_some_and(|p| p == placement);
                if !unchanged {
                    scan.moves.insert(*id, placement);
                }
            }
            None => scan.deferred.push((*id, (*path).clone())),
        }
    }

    // A vault's own directory, standing somewhere else under another name, is
    // the vault: an ENCRYPTED folder's identity may claim (the reset's WP2,
    // decision 1). No contents are needed -- this is the empty vault the user
    // renamed and then made a new empty folder under its old name, which no
    // contents rule can tell from AG because the two worlds are identical in
    // names and contents. Missing, displaced or contested alike: a vault
    // whose old path now holds a stranger full of somebody else's files is
    // still the vault wherever its directory stands (the executor's step-aside
    // under a conflict name left exactly that on clean2 74033). A wrong claim, on an id recycled onto a plain
    // directory after the vault was deleted, over-seals that directory and
    // publishes nothing; the alternative is a hold on every rename of an
    // empty vault. Plain folders never claim: their id corroborates a
    // proposal the contents make, below, and does nothing on its own.
    // Records the claim below takes a path from, by identity: the path map
    // gave them the candidate, but their own directory stands elsewhere.
    let mut lost_to_identity: Vec<(String, EntityId)> = Vec::new();
    for (old_path, id) in missing.iter().chain(displaced.iter()).chain(contested.iter()) {
        if !encrypted.contains(id) || claimed.contains(id) {
            continue;
        }
        let Some(candidate) = record_identity.get(id).and_then(|rid| where_id_stands.get(rid)) else {
            continue;
        };
        // A path another record merely NAMES, with no directory of its own
        // here yet (a server folder not materialized on this disk), does not
        // stand between a vault and its directory: that record's create is
        // refused at the directory by identity and re-decided, and the vault
        // takes what is its own. Left refused both ways, the two waited on
        // each other for ever (plat3 75415 with the swap verb off).
        let named_only = folder_ids
            .get(*candidate)
            .map(|sid| env.store.get_entry(EntityId::folder(*sid)))
            .transpose()?
            .flatten()
            .is_some_and(|e| e.synced_placement.is_none() && e.stand_in.is_none());
        // The record the path map gives the candidate to stands between the
        // vault and its directory only if it can be the directory's owner.
        // The candidate is where the vault's OWN id stands; a holder whose own
        // id is known to stand at ANOTHER path is not at the candidate, and
        // waiting for it to leave the map waits for a record that has nothing
        // to leave -- in a rotation, the vault was read as deleted within the
        // pass and its directory minted plain (the reset's C8b, plat3 75400).
        // Such a holder loses the path to identity and goes to the contested
        // pool, where the record whose path holds somebody else's directory
        // belongs. A holder whose identity is unknown still blocks: with no id
        // there is nothing to say it is not the owner (decision 2A).
        let holder_stands_elsewhere = folder_ids.get(*candidate).is_some_and(|sid| {
            record_identity
                .get(&EntityId::folder(*sid))
                .and_then(|rid| where_id_stands.get(rid))
                .is_some_and(|at| *at != *candidate)
        });
        if taken.contains(candidate)
            || (folder_ids.contains_key(*candidate) && !named_only && !holder_stands_elsewhere)
            || scan.held.contains(*candidate)
            || *candidate == old_path
        {
            continue;
        }
        // The server is already moving ANOTHER folder onto this path: two
        // renames met at one name, and the claim would plan a local move
        // that the remote move overtakes every pass, for ever (clean2 74033
        // on the first cut). The remote move lands first; the vault's
        // directory is still its own and is claimed on a later pass, from
        // wherever the room-making put it.
        if remote_wants.get(*candidate).is_some_and(|w| w != id && !named_only) {
            continue;
        }
        if holder_stands_elsewhere && !named_only {
            if let Some(sid) = folder_ids.get(*candidate) {
                let lost = EntityId::folder(*sid);
                // It is not at the path it held: present there, it would be a
                // second record on the vault's directory (the reset's C8b-4).
                scan.present.remove(&lost);
                lost_to_identity.push(((*candidate).clone(), lost));
            }
        }
        claimed.push(*id);
        taken.insert(candidate);
        scan.present.insert(*id);
        folder_ids.insert((*candidate).clone(), id.server_id);
        if folder_ids.get(old_path) == Some(&id.server_id) {
            folder_ids.remove(old_path);
        }
        match placement_of(candidate, folder_ids) {
            Some(placement) => {
                let unchanged = env
                    .store
                    .get_entry(*id)?
                    .and_then(|e| e.synced_placement.clone())
                    .is_some_and(|p| p == placement);
                if !unchanged {
                    scan.moves.insert(*id, placement);
                }
            }
            None => scan.deferred.push((*id, (*candidate).clone())),
        }
    }
    // The same for a PLAIN folder holding a path, by its record, where another
    // folder's own directory stands: identity says the path is not its, and it
    // stops counting as present there. The vault's claim above has said so
    // since the reset's C8b; a plain holder kept the path by its name. Two
    // folders renamed in one breath against a peer's trade of their names:
    // A renamed onto B's name and B onto a new one, B's only file moved out
    // first. B's record named the path where A's directory stood, the path
    // map and the executor read it as A's, the scan read it as B's -- and
    // A's create there and B's move away from it were refused for ever
    // (plat3 75415 with the swap verb on). Only the holder's presence goes:
    // the path joins the candidates, and whether the other folder takes it is
    // for its own files to propose, as for any directory -- a plain folder's
    // id still claims nothing on its own. Not on a volume whose ids are
    // positions, where a directory's id says nothing of whose it is.
    let mut freed_by_identity: Vec<&String> = Vec::new();
    if !env.vfs.personality().positional_file_ids {
        let unplaced: Vec<EntityId> = missing
            .iter()
            .chain(displaced.iter())
            .chain(contested.iter())
            .map(|(_, id)| *id)
            .filter(|id| !claimed.contains(id) && !held_ids.contains(id))
            .collect();
        for (path, holder) in tracked.iter() {
            if encrypted.contains(holder) || claimed.contains(holder) || taken.contains(path) || scan.held.contains(path.as_str()) {
                continue;
            }
            let Some(here) = dir_identity.get(path).copied().filter(|d| *d != 0) else { continue };
            let Some(owner) = carrier_of(here) else { continue };
            if owner == *holder || !unplaced.contains(&owner) {
                continue;
            }
            scan.present.remove(holder);
            lost_to_identity.push((path.clone(), *holder));
            if let Some(dir) = dirs_on_disk.iter().find(|d| *d == path) {
                freed_by_identity.push(dir);
            }
        }
    }
    for dir in freed_by_identity {
        if !candidates.contains(&dir) {
            candidates.push(dir);
        }
    }
    candidates.sort_by_key(|d| (depth_of(d), d.to_string()));
    // A record that lost its path to identity is matched as a contested one
    // from here on: its path holds a directory that is somebody else's.
    let lost_their_paths: Vec<EntityId> = lost_to_identity.iter().map(|(_, id)| *id).collect();
    let mut contested_after_claims: Vec<(String, EntityId)> = contested.clone();
    for (path, lost) in lost_to_identity {
        displaced.retain(|(_, e)| *e != lost);
        if !contested_after_claims.iter().any(|(_, e)| *e == lost) {
            contested_after_claims.push((path, lost));
        }
    }

    // A record that lost its path to a claim is searched for as a contested
    // one, and where neither its contents place it nor anything else does, it
    // takes today's reading. Stated residual (plat3 75412, rooted in T1): it
    // can be re-created at its server name while a directory it names still
    // stands. A stand-down there (row 5) was built and livelocked -- a hold
    // does not suspend the held record's server move, and deferring that move
    // has no clearing event that is not a guess. Placing it where the server
    // has it, when its own directory stands there, was built too and did not
    // land: dropping the record's presence at the lost path fixes every seed
    // that needs it, and no seed needed the placement
    // (specs/drive_sync_reset_c9a_unlanded.diff, with the same placement for
    // any contested folder, C9(a)).

    // `contested` differs from `displaced` in ONE respect: what the folder's OLD
    // path holds. That is evidence about somebody else, never about where MY
    // files went, so it cannot be a reason to refuse to look for them. The ring
    // walk above resolves the closed case; a chain that starts at a missing
    // path and ends at an untracked directory is just as real, and had nowhere
    // to be resolved. Matched with `whole_only`, exactly as `displaced` is, so
    // one file moved out of a folder still cannot drag the folder after it.
    //
    // Three refusals apply to the contested pool alone. Each refuses a match,
    // never takes one, and each was found by a failure the sweep could not
    // see -- one of them a 2000-pass livelock that the seed count called an
    // improvement.
    for (pool, whole_only, contested_pool) in
        [(&missing, false, false), (&displaced, true, false), (&contested_after_claims, true, true)]
    {
        for candidate in candidates.iter() {
            if taken.contains(candidate) {
                continue;
            }
            let mut best: Option<(EntityId, String, (bool, usize))> = None;
            for (old_path, id) in pool.iter() {
                if contested_pool {
                    // Already placed by the ring walk: spoken for.
                    if claimed.contains(id) {
                        continue;
                    }
                    // Somebody has moved wholesale INTO my old path and is not
                    // yet placed: half an exchange still being worked out.
                    // Taking a candidate now settles my half and strands
                    // theirs under a conflict name.
                    if arrived_at.get(old_path).is_some_and(|a| !claimed.contains(a)) {
                        continue;
                    }
                    // Another tracked folder still calls this candidate home.
                    // Earlier matches drop their old keys from the map, so a
                    // home can look free for the rest of the pass; taking it
                    // leaves two records on one directory.
                    if tracked
                        .get(*candidate)
                        .is_some_and(|owner| owner != id && !claimed.contains(owner) && !lost_their_paths.contains(owner))
                    {
                        continue;
                    }
                    // The server is already moving another folder onto this
                    // path.
                    if remote_wants.get(*candidate).is_some_and(|w| w != id) {
                        continue;
                    }
                    // A folder cannot move into its own subtree. `moved_wholesale`
                    // answers yes here when the folder's FILES were moved down
                    // into a new subdirectory of it -- the files moved, the
                    // folder did not -- and acting on that reparents the
                    // folder under itself: refused by the server, its old key
                    // dropped from the map, and the directory left standing at
                    // its real path adopted as a plain twin. On the three-device
                    // arm that twin was the vault root, and a sealed file ended
                    // up under it (seed 74826).
                    if candidate.starts_with(&format!("{old_path}/")) {
                        continue;
                    }
                }
                if claimed.contains(id) {
                    continue;
                }
                let kids = children.get(id).map(|k| k.as_slice()).unwrap_or(&[]);
                let matched_at = |at: &str| -> usize {
                    kids.iter()
                        .filter(|(name, file_id)| {
                            by_path
                                .get(format!("{at}/{name}").as_str())
                                .is_some_and(|o| o.fingerprint.file_id == *file_id)
                        })
                        .count()
                        + usize::from(child_folder_under(id, at))
                };
                let matched = matched_at(candidate);
                // The folder's own directory, proven, stands here: the
                // folder renamed or moved, whatever its files did. Nothing
                // inside need propose it -- its only file may have been
                // moved out on its own, or it never had one.
                let proven_here = proven_at.get(id) == Some(candidate);
                if matched == 0 && !proven_here {
                    continue;
                }
                // Wholesale guards against dragging a folder after one file
                // moved out of it. Where the folder's OWN directory stands
                // at the candidate, holding files of its own, nothing is
                // being dragged: the directory went there, and a file of it
                // found elsewhere is the file that moved out. The user
                // renamed the folder, saved through its old name, and moved
                // one file into the rebuilt directory; left to wholesale,
                // the folder stayed on the rebuilt directory, its own was
                // minted as a new folder, and every file was carried into
                // it, out of the directory the peer's user had put them in
                // (soak run 1508). Contents still have to propose it, so a
                // plain folder's id claims nothing on its own; not where ids
                // are positions.
                let own_directory_here = !env.vfs.personality().positional_file_ids
                    && record_identity
                        .get(id)
                        .is_some_and(|own| dir_identity.get(*candidate) == Some(own));
                if whole_only && !own_directory_here && !moved_wholesale(id, candidate) {
                    continue;
                }
                // The contents propose this directory; where does the
                // folder's OWN directory stand? At this same one: the
                // strongest corroboration there is. Somewhere else on this
                // disk: the files went one way and the directory another,
                // and the two readings cannot both be right. Neither is
                // taken -- the folder is left where its directory stands,
                // present and unmoved, and the disagreement is said out loud
                // rather than resolved by whichever rule ran first.
                //
                // Unless the folder's contents say it is where its directory
                // stands as well: the directory and its files went the same
                // way, and what this candidate holds was moved out of the
                // folder on its own. That is a rename and a move, and the
                // candidate standing where its directory stands takes the
                // folder in its turn. Held instead, it was held for good at
                // its old name: the directory never came back to it, so its
                // rename never went up and nothing saved in it afterwards
                // did either (soak run 1509: one file moved into a new folder
                // and the folder renamed, both before the next scan).
                //
                // Or unless the directory standing there is proven the
                // folder's own: then the files' proposal is files moved out,
                // whatever they number, and there is nothing to ask. Held
                // instead, a folder whose only file the user moved into a
                // new folder before renaming it was held for good, and
                // nothing saved in it afterwards reached the server (soak
                // runs 1512, 1513 and 1518).
                if let Some(stands_at) = record_identity.get(id).and_then(|rid| where_id_stands.get(rid)) {
                    if **stands_at != **candidate
                        && **stands_at != *old_path
                        && candidates.contains(stands_at)
                        && !taken.contains(*stands_at)
                        && (proven_at.get(id) == Some(stands_at)
                            || (matched_at(stands_at) > 0 && (!whole_only || moved_wholesale(id, stands_at))))
                    {
                        continue;
                    }
                    if **stands_at != **candidate && **stands_at != *old_path {
                        scan.present.insert(*id);
                        scan.held.insert((*stands_at).clone());
                        taken.insert(*stands_at);
                        // Once per folder per pass, whatever the candidates
                        // say: the hold is one fact.
                        let already = env
                            .store
                            .open_issues()?
                            .iter()
                            .any(|i| i.kind == DIRECTORY_DISAGREES && i.entity == Some(*id));
                        if !already {
                            env.store.raise_issue(
                                Some(*id),
                                DIRECTORY_DISAGREES,
                                &format!(
                                    "the folder {old_path} is standing at {stands_at} but its files \
                                     are under {candidate}: was the folder renamed, or were the files \
                                     moved out? Nothing is moved until one of them is put back",
                                ),
                                (env.now_ms)() as i64,
                            )?;
                        }
                        continue;
                    }
                }
                // A proven directory wins: the folder IS this directory, and
                // another folder's files found in it were moved into it.
                // Then the most corroborated match, and ties break on the
                // folder id so two devices reach the same answer.
                let rank = (proven_here, matched);
                if best.as_ref().is_none_or(|(bid, _, best_rank)| rank > *best_rank || (rank == *best_rank && *id < *bid)) {
                    best = Some((*id, old_path.clone(), rank));
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
            if old_path != **candidate && folder_ids.get(&old_path) == Some(&id.server_id) {
                folder_ids.remove(&old_path);
            }
            match placement_of(candidate, folder_ids) {
                Some(placement) => {
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
                // Under a directory nobody is yet: placed once that directory
                // has been adopted (`place_deferred`).
                None => scan.deferred.push((id, (*candidate).clone())),
            }
        }
    }

    // An empty folder cannot be matched by what is inside it, and for a plain
    // folder that is the end of it: one removed, one created, nothing lost.
    // An ENCRYPTED folder is not nothing when it is empty. The encryption is
    // the thing, and reading its rename as trash plus create trashes the
    // vault on the server and mints a plain folder under the name the user
    // just gave their vault -- into which everything they save next goes up
    // in the clear, on every device, with nothing saying so.
    //
    // So an encrypted folder gone from its path, with exactly one unaccounted
    // directory standing beside where it stood that holds nothing the engine
    // knows, is that folder renamed. A wrong pairing here errs the safe way:
    // a folder the user meant as plain stays a vault. The ambiguous case --
    // several such directories, or none -- gets what a plain folder gets.
    //
    // Only a vault folder that stood on THIS disk. One the server has told us
    // about and nothing has created here yet is not missing, it is not yet
    // downloaded -- and pairing it with a directory the user just made would
    // hand their new plain folder the vault's identity and send what they put
    // in it up encrypted under the vault's name. Same line the folder-deleted
    // reading draws, for the same reason.
    // Shallowest first, so a renamed vault is placed before a folder inside it.
    let mut empty_and_encrypted: Vec<(String, EntityId)> = Vec::new();
    for (old_path, id) in missing.iter() {
        if claimed.contains(id) || children.contains_key(id) {
            continue;
        }
        if env
            .store
            .get_entry(*id)?
            .is_some_and(|e| e.is_encrypted && e.synced_placement.is_some())
        {
            empty_and_encrypted.push((old_path.clone(), *id));
        }
    }
    empty_and_encrypted.sort_by_key(|(p, _)| (depth_of(p), p.clone()));
    // One missing vault per parent, or none. Two that left their places at
    // once beside one new directory cannot be told apart, and pairing either
    // would undo the user's deletion of the other and carry its grants onto
    // the folder they kept. So neither is paired -- and because that is the
    // very reading this rule exists to prevent, it is said out loud.
    let mut agreed_parents: HashMap<EntityId, Option<i64>> = HashMap::new();
    for (_, id) in &empty_and_encrypted {
        if let Some(e) = env.store.get_entry(*id)? {
            agreed_parents.insert(
                *id,
                e.synced_placement.as_ref().map(|p| p.parent).unwrap_or(e.remote.parent),
            );
        }
    }
    let mut per_parent: HashMap<Option<i64>, usize> = HashMap::new();
    for parent in agreed_parents.values() {
        *per_parent.entry(*parent).or_default() += 1;
    }
    for (old_path, id) in empty_and_encrypted {
        let Some(entry) = env.store.get_entry(id)? else {
            continue;
        };
        let agreed_parent = agreed_parents[&id];
        let several = per_parent.get(&agreed_parent).copied().unwrap_or(0) > 1;
        let beside: Vec<&&String> = candidates
            .iter()
            .filter(|d| {
                if taken.contains(*d) || !holds_nothing_known(d) {
                    return false;
                }
                let parent_here = match d.rsplit_once('/') {
                    None => Some(None),
                    Some((p, _)) => folder_ids.get(p).map(|id| Some(*id)),
                };
                parent_here == Some(agreed_parent)
            })
            .collect();
        if beside.is_empty() {
            // Nothing it could be. The ordinary deleted reading, which needs
            // no announcement of its own.
            continue;
        }
        if several || beside.len() > 1 {
            // Ambiguous on either side: several vaults gone from this parent,
            // or several new directories beside where this one stood. Said
            // out loud, whichever side is plural, because what follows is a
            // vault trashed and a plain folder under a name the user gave a
            // vault -- the very reading this rule exists to prevent.
            let names: Vec<&str> = beside.iter().map(|d| d.as_str()).collect();
            env.store.raise_issue(
                Some(id),
                "reconcile",
                &format!(
                    "the protected folder {} left its place, and which new folder here \
                     it became -- {} -- cannot be told{}; it is read as deleted and the \
                     new folder is plain. If it was renamed, restore it from the trash \
                     and move it back",
                    old_path,
                    names.join(", "),
                    if several { ", another protected folder left at the same time" } else { "" }
                ),
                (env.now_ms)() as i64,
            )?;
            continue;
        }
        let candidate = beside[0];
        claimed.push(id);
        taken.insert(candidate);
        scan.present.insert(id);
        folder_ids.insert((*candidate).clone(), id.server_id);
        if folder_ids.get(&old_path) == Some(&id.server_id) {
            folder_ids.remove(&old_path);
        }
        if let Some(placement) = placement_of(candidate, folder_ids) {
            if entry.synced_placement.as_ref() != Some(&placement) {
                scan.moves.insert(id, placement);
            }
        }
    }
    // An evicted record that matched nothing -- a plain EMPTY folder whose
    // directory a room-making moved aside under a conflict name, its path
    // now another record's by identity -- is left to today's reading: not
    // present, so read as deleted, and its directory minted as a new folder.
    // A hold was tried here (present, directory kept from adoption, an issue
    // naming both facts) and it livelocked plat3 75415: the held record kept
    // a placement at a path it did not own, its move from there was refused
    // every pass as not its directory, and the folder the server wanted at
    // that path could never be created behind it. A plain folder may not
    // claim, so nothing could lift it. That is the empty-plain residual the
    // reset spec states for the owner, and this shape is in it.

    // A directory whose identity belongs to a folder still alive here is that
    // folder, whatever it is called and whatever it holds, and is never
    // minted as a new one: the day it is, the folder has two records, and a
    // sealed file inside it is read as moved into a plain folder. It happens
    // when the folder could not be placed on it this pass -- the server is
    // moving another folder onto the name, and the claim waits for that move
    // to land (clean2 74033: the vault's directory, standing at the name the
    // server wanted for the plain ring, was minted plain in the same pass).
    // Held instead: nothing under it is placed this pass, and the claim takes
    // it from wherever the room-making leaves it. A record read as deleted
    // this pass (not present) holds nothing: a recycled id on a stranger is
    // the stranger's, and the stranger is minted as row 7 says.
    //
    // ENCRYPTED folders only, because only they claim. A plain folder's id
    // corroborates a proposal its contents make and never claims on its own
    // (a recycled id must not hand a stranger's files a folder's history), so
    // holding a plain folder's directory from the mint would hold it for ever
    // when no proposal comes -- a parent renamed and its subfolder renamed
    // and its old name rebuilt, say (`renaming_a_folder_and_its_subfolder_
    // with_the_old_name_rebuilt_keeps_the_subfolder`): the plain folder keeps
    // today's reading, a fresh record on its directory, until plain folders
    // are allowed to claim. Named as open in the reset spec.
    let alive_ids: std::collections::HashSet<u64> = record_identity
        .iter()
        .filter(|(id, _)| scan.present.contains(*id) && encrypted.contains(*id))
        .map(|(_, rid)| *rid)
        .collect();
    for dir in dirs_on_disk {
        if folder_ids.contains_key(dir) || taken.contains(dir) {
            continue;
        }
        if dir_identity.get(dir).is_some_and(|id| *id != 0 && alive_ids.contains(id)) {
            scan.held.insert(dir.clone());
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
/// Every inode the disk is currently showing, in one walk. Asked by the trash
/// path before it disowns a record, because the path a record REMEMBERS cannot
/// say whether its file is still here -- a folder-name trade moves the file
/// without the engine knowing -- and the inode can.

pub(crate) fn inodes_on_disk(env: &ExecEnv) -> Result<std::collections::HashSet<u64>, ExecError> {
    Ok(observe(env)?.into_iter().map(|o| o.fingerprint.file_id).collect())
}

/// The paths of live FILE records with nothing of theirs on this disk yet:
/// not provisional (a provisional was minted from a file here), never agreed
/// here (no `synced_placement`), not trashed on the server, and going to be
/// materialized here at all -- not parked (`holds_a_local_file`) and not
/// waiting on another device's park, whose name is a scratch name and no
/// slot the user can see. `known_local` leaves every one of them out; scan
/// rule 1 reads their paths as a record's that is not at home.
///
/// Sealed records are in the set on the same terms. Sealing says nothing
/// about where a record's file stands: a device without the key never counts
/// one (the vault records it never placed are `PendingKey` and tied to
/// nothing, so they hold no local file), and a device with the key derives
/// the real name the path is
/// read under, so a trade with a sealed slot is the same question as with a
/// plain one. A file dragged across the vault's edge by such a trade is read
/// as the move it is and handled by the drag rules, not by this set.
fn awaiting_bytes(env: &ExecEnv) -> Result<std::collections::HashSet<String>, ExecError> {
    let mut out = std::collections::HashSet::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::File
            || entry.id.is_provisional()
            || entry.remote_deleted
            || entry.synced_placement.is_some()
            || !entry.holds_a_local_file()
            || entry.waiting_on_a_park()
        {
            continue;
        }
        if let Some(path) = relative_path(env, &entry)? {
            out.insert(path);
        }
    }
    Ok(out)
}

/// A record that has no own file yet learns it the first time the scan
/// finds it at home: the file standing at its path carries the file id its
/// agreement recorded. That is every record from before own files were kept,
/// once; a record made since has one from its mint, its download or its
/// upload, and one learned here means a site that should have set it did not.
fn adopt_own_files(
    env: &ExecEnv,
    known: &[KnownLocal],
    observed: &[ObservedFile],
) -> Result<(), ExecError> {
    let at: HashMap<&str, &ObservedFile> =
        observed.iter().map(|o| (o.path.as_str(), o)).collect();
    for k in known.iter().filter(|k| k.own_file.is_none() && k.id.entity_type == EntityType::File) {
        let Some(recorded) = k.fingerprint.map(|f| f.file_id).filter(|id| *id != 0) else {
            continue;
        };
        let Some(here) = at.get(k.path.as_str()).filter(|o| o.fingerprint.file_id == recorded) else {
            continue;
        };
        let Some(mut entry) = env.store.get_entry(k.id)? else {
            continue;
        };
        entry.own_file = Some(here.fingerprint.identity());
        env.store.put_entry(&entry)?;
    }
    Ok(())
}

/// The scan paired a record with a file that is not the one it knew: a
/// safe-save read as an edit, a backup-by-rename's new file, a move found by
/// content, a file restored with the same bytes. That file is the record's
/// own from now on, or the next scan would follow the old identity to
/// wherever it still stands -- the backup the editor left, the copy the move
/// came from. A record paired by its own file (`scan.bound`) takes the file
/// it was paired with; a weak one, the file its edit or move names.
/// Record, on every record the scan read its file for, the bytes it saw
/// there (`Entry::last_seen_sha`, layer 1 of
/// `specs/drive_weak_volume_identity.md`). A record read unchanged is only
/// credited with the file at its path when those bytes are its own -- agreed
/// or last seen -- so a record left unchanged for naming, beside another's
/// file, learns nothing from it.
fn note_what_each_record_saw(
    env: &ExecEnv,
    known: &[KnownLocal],
    observed: &[ObservedFile],
    scan: &ScanOutcome,
) -> Result<(), ExecError> {
    let at: HashMap<&str, &ObservedFile> = observed.iter().map(|o| (o.path.as_str(), o)).collect();
    let record: HashMap<EntityId, &KnownLocal> = known.iter().map(|k| (k.id, k)).collect();
    for (id, change) in &scan.changes {
        let seen = match change {
            LocalChange::Unchanged => record.get(id).and_then(|k| {
                at.get(k.path.as_str())
                    .map(|o| o.sha256.clone())
                    .filter(|sha| k.sha256.as_ref() == Some(sha) || k.last_seen_sha.as_ref() == Some(sha))
            }),
            LocalChange::Edited { sha256, .. } | LocalChange::MovedAndEdited { sha256, .. } => Some(sha256.clone()),
            LocalChange::Moved { to_path, .. } => at.get(to_path.as_str()).map(|o| o.sha256.clone()),
            LocalChange::Deleted => None,
        };
        let Some(seen) = seen else { continue };
        if record.get(id).is_some_and(|k| k.last_seen_sha.as_ref() == Some(&seen)) {
            continue;
        }
        let Some(mut entry) = env.store.get_entry(*id)? else {
            continue;
        };
        if entry.id.entity_type == EntityType::File && entry.last_seen_sha.as_ref() != Some(&seen) {
            entry.last_seen_sha = Some(seen);
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// The tie-break ids this pass may read: those recorded in this mount
/// session, on a volume whose ids may break a tie at all
/// (`jd_vfs::Personality::id_tie_break`; `specs/drive_weak_volume_identity.md`).
///
/// And none at all when the disk says they have gone stale: a record whose
/// file stands unedited at its own path -- its last-seen bytes, right there
/// -- under another id than the one recorded means the volume renumbered
/// behind the engine's back (a stick remounted elsewhere, clusters reused by
/// another machine), and then no recorded id names anything this pass. The
/// pass runs by bytes and paths, as on a volume with no ids, and records them
/// again.
fn tie_breaks_for_this_pass(env: &ExecEnv, observed: &[ObservedFile]) -> Result<HashMap<EntityId, u64>, ExecError> {
    let class = env.vfs.personality().id_tie_break;
    if class == jd_vfs::IdTieBreak::None {
        return Ok(HashMap::new());
    }
    let recorded = env.store.tie_breaks()?;
    let at: HashMap<&str, &ObservedFile> = observed.iter().map(|o| (o.path.as_str(), o)).collect();
    // Each record whose file stands unedited at its own path -- its last-seen
    // bytes, right there -- and the id it stands under now.
    let mut unedited_at_home: HashMap<EntityId, u64> = HashMap::new();
    for e in all_entries(env)? {
        if e.id.entity_type != EntityType::File || !recorded.contains_key(&e.id) {
            continue;
        }
        let Some(path) = relative_path(env, &e)? else { continue };
        let Some(o) = at.get(path.as_str()) else { continue };
        if e.last_seen_sha.as_deref() == Some(o.sha256.as_str()) {
            unedited_at_home.insert(e.id, o.tie_break_id);
        }
    }
    Ok(crate::scan::tie_breaks_that_hold(class, env.store.mount_session()?, &recorded, &unedited_at_home))
}

/// Record, on a volume whose ids may break a tie, the id of the file each
/// record's scan found as its own, in this mount session.
fn note_the_tie_breaks_each_record_saw(
    env: &ExecEnv,
    known: &[KnownLocal],
    observed: &[ObservedFile],
    scan: &ScanOutcome,
) -> Result<(), ExecError> {
    if env.vfs.personality().id_tie_break == jd_vfs::IdTieBreak::None {
        return Ok(());
    }
    let session = env.store.mount_session()?;
    let at: HashMap<&str, &ObservedFile> = observed.iter().map(|o| (o.path.as_str(), o)).collect();
    let record: HashMap<EntityId, &KnownLocal> = known.iter().map(|k| (k.id, k)).collect();
    for (id, change) in &scan.changes {
        let found = match change {
            LocalChange::Unchanged => record.get(id).and_then(|k| {
                at.get(k.path.as_str()).filter(|o| {
                    k.sha256.as_deref() == Some(o.sha256.as_str()) || k.last_seen_sha.as_deref() == Some(o.sha256.as_str())
                })
            }),
            LocalChange::Edited { .. } => record.get(id).and_then(|k| at.get(k.path.as_str())),
            LocalChange::Moved { to_path, .. } | LocalChange::MovedAndEdited { to_path, .. } => at.get(to_path.as_str()),
            LocalChange::Deleted => None,
        };
        let id_now = found.map(|o| o.tie_break_id).unwrap_or(0);
        if record.get(id).is_some_and(|k| k.tie_break == id_now && id_now != 0) {
            continue;
        }
        env.store.set_tie_break(*id, id_now, session)?;
    }
    Ok(())
}

fn bind_own_files(env: &ExecEnv, scan: &ScanOutcome, strong_volume: bool) -> Result<(), ExecError> {
    if !strong_volume {
        // With no ids at all there is nothing to bind: the bytes do it (layer 1).
        if env.vfs.personality().positional_file_ids {
            return Ok(());
        }
        return bind_never_sent_own_ids(env, scan);
    }
    let by_identity: std::collections::HashSet<EntityId> = scan.bound.iter().map(|(id, _)| *id).collect();
    let weak = scan.changes.iter().filter(|(id, _)| !by_identity.contains(id)).filter_map(|(id, change)| {
        match change {
            LocalChange::Edited { fingerprint, .. }
            | LocalChange::Moved { fingerprint, .. }
            | LocalChange::MovedAndEdited { fingerprint, .. } => Some((*id, fingerprint.identity())),
            LocalChange::Unchanged | LocalChange::Deleted => None,
        }
    });
    let pairs: Vec<(EntityId, jd_vfs::FileIdentity)> = scan.bound.iter().copied().chain(weak).collect();
    for (id, identity) in pairs {
        if !identity.is_strong() {
            continue;
        }
        let Some(mut entry) = env.store.get_entry(id)? else {
            continue;
        };
        if entry.id.entity_type == EntityType::File && entry.own_file != Some(identity) {
            entry.own_file = Some(identity);
            env.store.put_entry(&entry)?;
        }
    }
    Ok(())
}

/// A file never sent that the scan read as the next version of the record it
/// was renamed over -- a save by rename whose temporary file a pass saw -- was
/// never a file of its own. Its record goes now, before anything acts, with
/// anything queued for it. Kept until the round, it still owned the file: the
/// conflict copy of that save was handed back to it under the temporary name,
/// and its upload sent the save up again as a new file (run 1531).
fn forget_what_a_save_superseded(env: &ExecEnv, scan: &ScanOutcome) -> Result<(), ExecError> {
    if scan.superseded.is_empty() {
        return Ok(());
    }
    let interrupted = env.store.interrupted_ops()?;
    let queued = env.store.queued_ops()?;
    for id in &scan.superseded {
        // An upload a crash left mid-air is resumed, and reports first.
        if interrupted.iter().any(|op| op.entity == *id) {
            continue;
        }
        for op in queued.iter().filter(|op| op.entity == *id) {
            env.store.drop_op(op.op_id)?;
        }
        env.store.delete_entry(*id)?;
    }
    Ok(())
}

/// Without births, a record never sent knows its file by the id it was
/// minted for, and the scan and the upload both ask it by that id
/// (`specs/drive_file_ownership.md`, 2b). The file the scan pairs it with --
/// a save that replaced the file at its path, or the file followed to where
/// it went -- is its file from now on, or the upload would refuse that file
/// for ever.
fn bind_never_sent_own_ids(env: &ExecEnv, scan: &ScanOutcome) -> Result<(), ExecError> {
    for (id, change) in &scan.changes {
        if !id.is_provisional() || id.entity_type != EntityType::File {
            continue;
        }
        let fingerprint = match change {
            LocalChange::Edited { fingerprint, .. }
            | LocalChange::Moved { fingerprint, .. }
            | LocalChange::MovedAndEdited { fingerprint, .. } => fingerprint,
            LocalChange::Unchanged | LocalChange::Deleted => continue,
        };
        if fingerprint.file_id == 0 {
            continue;
        }
        let Some(mut entry) = env.store.get_entry(*id)? else {
            continue;
        };
        if entry.synced_fingerprint.is_some() || entry.own_file.map(|o| o.file_id) == Some(fingerprint.file_id) {
            continue;
        }
        entry.own_file = Some(fingerprint.identity());
        env.store.put_entry(&entry)?;
    }
    Ok(())
}

fn known_local(env: &ExecEnv, tie_breaks: &HashMap<EntityId, u64>) -> Result<Vec<KnownLocal>, ExecError> {
    let positional = env.vfs.personality().positional_file_ids;
    let mut out = Vec::new();
    let mut deleted = Vec::new();
    let all = all_entries(env)?;
    // Sources whose bytes a claimant holds elsewhere on this disk -- a file
    // carried into a vault, going up sealed as a new file that replaces it.
    // Not only while the claimant waits: once it has landed the source is
    // still on the server until its trash lands, and a stranger at its old
    // path is no more its file then than before. Read by path the pass the
    // claimant landed, the stranger went up as the source's next version and
    // its history held both (kill2 75110).
    let held: std::collections::HashSet<EntityId> = all
        .iter()
        .filter(|e| !e.remote_deleted && e.status != LocalStatus::OutOfScope)
        .filter_map(|e| e.replaces)
        .collect();
    for entry in all {
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
        // A sealed file held outside its vault is kept away from where the
        // server keeps it on purpose; its own file back in that slot is the
        // file come home (scan, `arrived_by_a_trade`).
        let server_home = if held_outside_its_vault(env, &entry)? {
            server_path(env, &entry)?
        } else {
            None
        };
        // Where the server has it, when that is not where this disk has it: a
        // name the server gives this record is a name it holds (scan step 2).
        let on_the_server = if entry.id.is_provisional() || entry.remote_deleted {
            None
        } else {
            server_path(env, &entry)?.filter(|there| *there != path)
        };
        let known = KnownLocal {
            id: entry.id,
            path,
            // On a volume whose ids are only positions, an id recorded
            // before it was read that way names nothing: the record keeps
            // no id, as every file there has none.
            fingerprint: entry.synced_fingerprint.map(|f| if positional { jd_vfs::Fingerprint { file_id: 0, birth_ns: 0, ..f } } else { f }),
            sha256: entry.synced_content.as_ref().map(|c| c.sha256.clone()),
            server_deleted: entry.remote_deleted,
            held: held.contains(&entry.id),
            server_home,
            server_path: on_the_server,
            own_file: entry.own_file.filter(|_| !positional),
            claimant_for: entry.replaces.filter(|_| entry.id.is_provisional()),
            last_seen_sha: entry.last_seen_sha.clone(),
            tie_break: tie_breaks.get(&entry.id).copied().unwrap_or(0),
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
    /// Re-upload at the destination and trash the source. The conversion,
    /// into a vault only.
    Convert,
    /// Refused by the server and not something this client can do instead:
    /// a vault folder stays where it was on the server, a sealed file is held.
    OutOfReach,
    /// The file is standing in the very directory its folder record owns:
    /// nothing was dragged, the FOLDER was misread. Not converted -- a
    /// conversion here publishes a vault on the strength of a misreading --
    /// and not moved; said once, and left for the folder scan to put right.
    NotADrag,
}

/// Would this local move carry the entry across the edge of a vault, and if so
/// what can be done about it?
///
/// Only a move counts. A local edit, a delete or a creation all stay where they
/// are, and a creation is decided by the path it appeared at rather than by any
/// journey. A rename inside the same parent is no journey either. A reparent is
/// compared against the entry's OWN protection rather than the agreement's
/// parent, because that is what the server compares against when it refuses.
///
/// **Only on the way IN, for files and folders alike.** Dragging plaintext into
/// a vault is not merely a stuck move: until it is converted the user is looking
/// at something they believe is private while the server holds it in the clear,
/// live, at the old path. Converting is the only thing that makes the picture
/// true.
///
/// Out of a vault is the mirror image and is NOT done here. It would publish a
/// vault's contents in the clear on the strength of a drag, and nothing on the
/// platform converts across a Fortress edge -- not the server, not the browser.
/// A folder dragged out is refused by the server and says so as a `withdrawn`
/// issue; a FILE dragged out is held (owner decision D1): the file stays where
/// the user put it on this disk, the server keeps it sealed where it was, and
/// `held_outside_its_vault` reads the two sides from then on.
///
/// **Only when the destination's protection is actually known.** The drive root
/// is plaintext and says so; a folder is only an answer if this store holds it.
/// An unresolved parent reads as plaintext, and reading one as plaintext here
/// would trash the server's copy of a vault file that never left the vault.
/// Where things stand by directory identity when a move is judged: the
/// directory the file is in now, and the directory the scan has put its
/// agreed parent folder on. Either is `None` where nothing can be said.
#[derive(Debug, Clone, Copy, Default)]
struct StandingIn {
    file: Option<u64>,
    agreed_parent: Option<u64>,
}

fn crossing_a_vault_edge(
    env: &ExecEnv,
    entry: &Entry,
    local: &Delta,
    standing_in: StandingIn,
) -> Result<Option<Crossing>, ExecError> {
    let to = match local {
        Delta::Moved { to } | Delta::MovedAndEdited { to, .. } => to,
        _ => return Ok(None),
    };
    // A rename in place crosses nothing. A vault's own root folder is
    // encrypted and stands in a plain parent -- that is what a vault root IS
    // -- so judged by its destination parent alone every rename of it read as
    // a move out of the vault, was refused here, and the folder scan's answer
    // that the user had renamed the vault was thrown away. The server refuses
    // a reparent across the edge; it takes a rename.
    let agreed_parent = entry
        .synced_placement
        .as_ref()
        .map(|p| p.parent)
        .unwrap_or(entry.remote.parent);
    if to.parent == agreed_parent {
        return Ok(None);
    }
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
    // Did the FILE change directory, or only the reading of which folder its
    // directory is? `standing_in.file` is the directory the scan resolved the
    // destination FOLDER to, found through the path map -- the same thing as
    // the directory the file physically stands in for as long as that map is
    // keyed by the path a directory stands at, which it is; the day a record
    // is placed in it on a directory that is not at its path, this stops
    // meaning what it says. A user drag puts the file under another directory; the
    // engine re-attributing a directory around a file that never moved does
    // not, and converting on that publishes a vault for a misreading (Defect
    // AF, route 4: a name trade read as a move across the edge). Asked of
    // the directory the file stands in NOW against the id its agreed parent
    // folder recorded as its own. Unknown or 0 on either side is no evidence
    // -- a restore, a re-created root, a Windows handle that would not open
    // -- and falls to the rule above, never to "changed" (P1, P2 of the
    // reset's WP2).
    //
    // With one more question, because a folder dragged across the edge takes
    // its files with it and none of them changed directory either: where has
    // the scan put the agreed parent folder? On its own directory, or on no
    // directory at all this pass (it crossed the edge itself and a claimant
    // stands in for it): the folder crossed and the file crossed inside it,
    // a real conversion. On some OTHER directory: the folder was put where
    // its directory is not, and that is the misreading.
    if entry.id.entity_type == EntityType::File {
        let owned = match agreed_parent {
            None => None,
            Some(id) => env
                .store
                .get_entry(EntityId::folder(id))?
                .and_then(|f| f.synced_fingerprint)
                .map(|fp| fp.file_id)
                .filter(|id| *id != 0),
        };
        if let (Some(owned), Some(here)) = (owned, standing_in.file.filter(|id| *id != 0)) {
            let parent_put_elsewhere = standing_in.agreed_parent.is_some_and(|d| d != 0 && d != owned);
            if owned == here && parent_put_elsewhere {
                return Ok(Some(Crossing::NotADrag));
            }
        }
    }
    if destination {
        Ok(Some(Crossing::Convert))
    } else {
        Ok(Some(Crossing::OutOfReach))
    }
}

/// Which plaintext server copy do the bytes with this identity on the volume
/// stand in for -- an entry that agreed on this inode and whose own path is
/// now empty.
///
/// The inode is enough for THIS, and only because of what it costs when it is
/// wrong. It is not being asked who a file is -- only which server copy to hold
/// on to a little longer while an upload waits for a key. A wrong guess delays
/// one delete until the key arrives; a right one keeps the copy everyone else
/// can still reach. Order, not identity, which is the whole rule.
fn plaintext_source_of(
    env: &ExecEnv,
    here: jd_vfs::FileIdentity,
    observed: &[ObservedFile],
) -> Result<Option<EntityId>, ExecError> {
    Ok(all_entries(env)?
        .into_iter()
        .filter(|e| !e.id.is_provisional() && !e.is_encrypted && !e.remote_deleted)
        // Files only: a folder record carries its directory's id in the same
        // slot now, and an inode a deleted directory gave up can be a file's
        // next.
        .filter(|e| e.id.entity_type == EntityType::File)
        .filter(|e| e.synced_fingerprint.is_some() && e.owns(here))
        .find(|e| match relative_path(env, e) {
            Ok(Some(path)) => !observed.iter().any(|o| o.path == path),
            _ => false,
        })
        .map(|e| e.id))
}

/// A file held outside its vault still holds its name in the vault folder on
/// the server, though nothing stands at that path here. A file arriving at
/// that slot on this disk -- saved there, or moved there -- is a different
/// file, and it goes up beside the held copy under a conflict name: never
/// under the held name, which would put two sealed files with one real name
/// in one vault folder, and never as a version of the held file. Returns the
/// placement the arriving file now has.
///
/// `leaving` is the records this pass's scan finds outside their vault,
/// whose hold the round has not written yet: their names are held already.
fn clear_of_a_held_name(
    env: &ExecEnv,
    rel_path: &str,
    placement: &Placement,
    arriving: Option<EntityId>,
    leaving: &std::collections::HashSet<EntityId>,
) -> Result<Placement, ExecError> {
    if !parent_is_encrypted(env, placement.parent)? {
        return Ok(placement.clone());
    }
    let personality = env.vfs.personality();
    let key = jd_vfs::comparison_key(&placement.name, &personality);
    let mut held_name = false;
    for e in all_entries(env)? {
        if Some(e.id) != arriving
            && e.remote.parent == placement.parent
            && jd_vfs::comparison_key(&e.remote.name, &personality) == key
            && (leaving.contains(&e.id) || held_outside_its_vault(env, &e)?)
        {
            held_name = true;
            break;
        }
    }
    let Some(root) = env.vfs.root().filter(|_| held_name) else {
        return Ok(placement.clone());
    };
    let here = root.join(rel_path);
    let aside = crate::execute::free_conflict_path(env, &here, &placement.name, &[])?;
    env.vfs.rename(&here, &aside)?;
    let name = aside
        .file_name()
        .map(|n| n.to_string_lossy().to_string())
        .unwrap_or_default();
    env.store.raise_issue(
        None,
        "kept_aside",
        &format!(
            "{} is still the name of a protected file you moved out of the vault, so this file is {name}",
            placement.name
        ),
        (env.now_ms)() as i64,
    )?;
    Ok(Placement { parent: placement.parent, name })
}

/// Tell the user a file is held outside its vault, by the name it has here.
///
/// There is no way to take a file out of a Fortress vault on the server -- no
/// level change, no conversion in the browser -- so the sentence names the two
/// things the user can do. One open issue per file: a rename while held
/// replaces the old sentence rather than adding a second. Not for a file the
/// server has deleted meanwhile, which says something else (below).
/// A file written in a vault and taken out of it before it was ever sent.
fn say_it_was_never_sent(env: &ExecEnv, entry: &Entry) -> Result<(), ExecError> {
    let detail = format!(
        "{} was saved in a vault and moved out before it was uploaded, so it is kept only \
         on this device and not uploaded. Move it back into the vault to sync it encrypted, \
         or delete it.",
        entry.effective_local_name()
    );
    for issue in env.store.open_issues()? {
        if issue.kind == HELD_OUTSIDE_THE_VAULT && issue.entity == Some(entry.id) && issue.detail != detail {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    env.store.raise_issue(Some(entry.id), HELD_OUTSIDE_THE_VAULT, &detail, (env.now_ms)() as i64)?;
    Ok(())
}

pub(crate) fn say_it_is_held(env: &ExecEnv, held: &Entry) -> Result<(), ExecError> {
    if held.remote_deleted {
        return Ok(());
    }
    let detail = format!(
        "{} is encrypted and stays in its vault on the server. The copy here is kept only on \
         this device. Move it back into the vault to sync it again, or download it in the \
         browser and upload it where you want it.",
        held.effective_local_name()
    );
    for issue in env.store.open_issues()? {
        if issue.kind == HELD_OUTSIDE_THE_VAULT && issue.entity == Some(held.id) && issue.detail != detail {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    env.store.raise_issue(Some(held.id), HELD_OUTSIDE_THE_VAULT, &detail, (env.now_ms)() as i64)?;
    Ok(())
}

/// Is this a sealed file the user has taken out of its vault on this disk,
/// which the server keeps sealed where it was (owner decision D1)?
///
/// Derived from the record, never remembered beside it: the agreed placement
/// is where the user put the file here, the server's placement is still inside
/// a vault, and nothing else makes those two sides of one sealed FILE disagree
/// across a vault's edge. A folder never reads this -- a vault's own root
/// stands in a plain parent by definition -- and a plain file never does. The
/// agreed parent has to be a folder this store KNOWS to be plain (live or in
/// the server's trash) or the drive root: an unknown one is no evidence. A
/// peer moving the sealed copy between vault folders changes the server's
/// placement and leaves the hold standing; the file moving back into a vault,
/// or the server deleting it, ends it by the ordinary paths.
/// A file held outside its vault whose own file does not stand at its agreed
/// path: waiting while a file carrying its identity stands elsewhere, or gone.
/// It holds nothing at that path -- no name for naming, no claim for an
/// upload. Asked of the disk by the record's own identity at its own path,
/// the same test the scan's at-home rule makes.
pub(crate) fn held_and_away(env: &ExecEnv, entry: &Entry) -> Result<bool, ExecError> {
    if !held_outside_its_vault(env, entry)? {
        return Ok(false);
    }
    let Some(root) = env.vfs.root() else {
        return Ok(false);
    };
    let here = match relative_path(env, entry)? {
        Some(p) => env.vfs.fingerprint(&root.join(p))?.map(|fp| fp.identity()),
        None => None,
    };
    Ok(!here.is_some_and(|here| entry.owns(here)))
}

/// May the real entry of a merge take the provisional's own file? Never a
/// held one: its own file stands somewhere else on purpose -- with a claimant
/// waiting for a vault key, or outside its vault -- and a file at its name is
/// another file. Handed a stranger saved there, the scan followed the held
/// record to it, the hold lost its source, and the stranger went up as the
/// held file's next version (a_held_file_does_not_take_over_a_stranger_at_the_servers_new_path).
pub(crate) fn may_take_a_merged_file(env: &ExecEnv, real: &Entry) -> Result<bool, ExecError> {
    Ok(!env.store.is_held_by_a_provisional(real.id)? && !held_outside_its_vault(env, real)?)
}

/// A file saved in a vault and carried out of it before it was ever sent: a
/// provisional that stays sealed in a plain folder, held there and never
/// uploaded (owner decision D1; owner question Q2 in drive_file_identity.md).
pub(crate) fn held_and_never_sent(env: &ExecEnv, entry: &Entry) -> Result<bool, ExecError> {
    Ok(entry.id.is_provisional()
        && entry.id.entity_type == EntityType::File
        && entry.is_encrypted
        && !parent_is_encrypted(env, entry.remote.parent)?)
}

/// The held record whose own file this is, if any.
///
/// On a volume with no file identities, by its bytes: the held record's last
/// seen bytes. Asked of the id alone, nothing was ever a held file there, and
/// the held file set aside or renamed while another file took its path was
/// minted a new PLAIN file and sent in the clear (FAT hostile2 74403). The
/// bytes can also be a copy the user made; a copy out of a vault waits in a
/// plain folder all the same, so the reading costs nothing where it is wrong.
fn held_owner_of(env: &ExecEnv, file: &ObservedFile) -> Result<Option<Entry>, ExecError> {
    let here = file.fingerprint.identity();
    if here.file_id == 0 {
        if !env.vfs.personality().positional_file_ids {
            return Ok(None);
        }
        for e in all_entries(env)? {
            if e.id.entity_type == EntityType::File
                && !e.remote_deleted
                && e.last_seen_sha.as_deref() == Some(file.sha256.as_str())
                && held_outside_its_vault(env, &e)?
            {
                return Ok(Some(e));
            }
        }
        return Ok(None);
    }
    for e in env.store.owners_of_file(here, false)? {
        if held_outside_its_vault(env, &e)? {
            return Ok(Some(e));
        }
    }
    Ok(None)
}

/// Do these bytes belong to a sealed record whose own file no longer stands at
/// its path with them? Only on a volume with no file identities, where bytes
/// are all a file is known by.
fn sealed_bytes_carried_out(env: &ExecEnv, sha: &str, observed: &[ObservedFile]) -> Result<bool, ExecError> {
    if !env.vfs.personality().positional_file_ids || sha == crate::scan::EMPTY_SHA256 {
        return Ok(false);
    }
    // Bytes the walk listed in a vault, standing in none now: carried out, as
    // surely as a record's (a5's R3; win plat3 75428, a file saved in a parked
    // vault and traded out before anything recorded it).
    if env.store.vault_listed(sha)? {
        let vaults = vault_directories(env)?;
        if !observed.iter().any(|o| o.sha256 == sha && vault_holding(&vaults, &o.path).is_some()) {
            return Ok(true);
        }
    }
    for e in all_entries(env)? {
        if e.id.entity_type != EntityType::File
            || e.remote_deleted
            || !e.is_encrypted
            || !(e.last_seen_sha.as_deref() == Some(sha) || e.synced_content.as_ref().is_some_and(|c| c.sha256 == sha))
        {
            continue;
        }
        let at_home = relative_path(env, &e)?
            .is_some_and(|home| observed.iter().any(|o| o.path == home && o.sha256 == sha));
        if !at_home {
            return Ok(true);
        }
    }
    Ok(false)
}

/// Every vault folder's directory as the records place it on this disk.
fn vault_directories(env: &ExecEnv) -> Result<Vec<(String, i64)>, ExecError> {
    let mut out = Vec::new();
    for e in all_entries(env)? {
        if e.id.entity_type == EntityType::Folder && e.is_encrypted && !e.remote_deleted {
            if let Some(path) = relative_path(env, &e)? {
                out.push((path, e.id.server_id));
            }
        }
    }
    Ok(out)
}

/// The vault folder whose directory `path` stands in, if any.
fn vault_holding(vaults: &[(String, i64)], path: &str) -> Option<i64> {
    vaults
        .iter()
        .find(|(dir, _)| path.strip_prefix(dir.as_str()).is_some_and(|rest| rest.starts_with('/')))
        .map(|(_, id)| *id)
}

/// Does the held record's own file still stand where it agrees it does? Then a
/// second file carrying its identity is a hard link, not the file moved.
fn held_file_stands(env: &ExecEnv, held: &Entry, observed: &[ObservedFile]) -> Result<bool, ExecError> {
    Ok(relative_path(env, held)?
        .is_some_and(|home| observed.iter().any(|o| o.path == home && held.owns(o.fingerprint.identity()))))
}

/// The held record's issue once the server has deleted its sealed copy while an
/// edited copy stands here: kept on this device only, never sent. `name` is
/// what that copy is called here now.
fn say_it_was_deleted_on_the_server(env: &ExecEnv, held: &Entry, name: &str) -> Result<(), ExecError> {
    let detail = format!(
        "{name} was deleted on the server while it was kept outside its vault on this device. \
         The edited copy here is kept only on this device and is not uploaded."
    );
    for issue in env.store.open_issues()? {
        if issue.kind == HELD_OUTSIDE_THE_VAULT && issue.entity == Some(held.id) && issue.detail != detail {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    env.store.raise_issue(Some(held.id), HELD_OUTSIDE_THE_VAULT, &detail, (env.now_ms)() as i64)?;
    Ok(())
}

/// The held record's issue while a new file carrying its disk identity waits
/// outside any vault. True whichever that file is -- the held file under a new
/// name, or an unrelated file the disk gave a recycled identity.
fn say_it_waits(env: &ExecEnv, held: &Entry, name: &str) -> Result<(), ExecError> {
    let detail = format!(
        "{name} may be the vault file {} under a new name, so it is kept only on this \
         device and not uploaded. Move it into the vault to sync it encrypted, or delete it.",
        held.effective_local_name()
    );
    for issue in env.store.open_issues()? {
        if issue.kind == HELD_OUTSIDE_THE_VAULT && issue.entity == Some(held.id) && issue.detail != detail {
            env.store.dismiss_issue(issue.issue_id)?;
        }
    }
    env.store.raise_issue(Some(held.id), HELD_OUTSIDE_THE_VAULT, &detail, (env.now_ms)() as i64)?;
    Ok(())
}

pub(crate) fn held_outside_its_vault(env: &ExecEnv, entry: &Entry) -> Result<bool, ExecError> {
    if entry.id.entity_type != EntityType::File || !entry.is_encrypted {
        return Ok(false);
    }
    let Some(agreed) = entry.synced_placement.as_ref() else {
        return Ok(false);
    };
    if agreed.parent == entry.remote.parent || !parent_is_encrypted(env, entry.remote.parent)? {
        return Ok(false);
    }
    Ok(match agreed.parent {
        None => true,
        Some(id) => env
            .store
            .get_entry(EntityId::folder(id))?
            .is_some_and(|f| !f.is_encrypted),
    })
}

/// Does this folder hold encrypted content? `None` is the drive root, which is
/// never itself a vault.
/// Is this folder, or a vault folder above it, one the server has deleted?
/// A record never sent placed where its own file now stands. Its `remote`
/// is where it will be created, not anything the server said -- it has no
/// server side -- so the pass writes it, as the pass writes it for a file
/// the scan finds moved (T1-C).
pub(crate) fn follow_its_file(env: &ExecEnv, entry: &mut Entry, to: Placement) -> Result<(), ExecError> {
    debug_assert!(entry.id.is_provisional());
    entry.remote = to;
    entry.local_name = None;
    env.store.put_entry(entry)?;
    Ok(())
}

fn in_a_deleted_vault(env: &ExecEnv, mut parent: Option<i64>) -> Result<bool, ExecError> {
    let mut guard = 0;
    while let Some(id) = parent {
        let Some(folder) = env.store.get_entry(EntityId::folder(id))? else {
            return Ok(false);
        };
        // The vault itself, not a folder inside one: a subfolder of a live
        // vault trashed upstream leaves the vault there to keep its files in,
        // and a sealed file moved out of it is held, not published.
        if folder.is_encrypted && folder.remote_deleted && !parent_is_encrypted(env, folder.remote.parent)? {
            return Ok(true);
        }
        guard += 1;
        if guard > 512 {
            return Ok(false);
        }
        parent = folder.remote.parent;
    }
    Ok(false)
}

pub(crate) fn parent_is_encrypted(env: &ExecEnv, parent: Option<i64>) -> Result<bool, ExecError> {
    let Some(id) = parent else {
        return Ok(false);
    };
    Ok(env
        .store
        .get_entry(EntityId::folder(id))?
        .map(|f| f.is_encrypted)
        .unwrap_or(false))
}

pub(crate) fn blank(id: EntityId, placement: &Placement) -> Entry {
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
        replaces: None,
        stand_in: None,
        stands_at: None,
        own_file: None,
        last_seen_sha: None,
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
    // One read of the table, then the same walk from the root in memory: a
    // query per folder, on every one of the dozens of calls a pass makes, was
    // a third of the daemon's CPU on the soak rig (perf, run 1504). The order
    // is the one `children_of` gave, and an entry no walk from the root
    // reaches is left out as before.
    let mut children: HashMap<Option<i64>, Vec<Entry>> = HashMap::new();
    for entry in env.store.every_entry()? {
        children.entry(entry.remote.parent).or_default().push(entry);
    }
    let mut out = Vec::new();
    let mut queue: Vec<Option<i64>> = vec![None];
    let mut guard = 0;
    while let Some(parent) = queue.pop() {
        guard += 1;
        if guard > 100_000 {
            return Err(ExecError::Contract("the entry tree has a loop".into()));
        }
        for entry in children.get(&parent).cloned().unwrap_or_default() {
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
fn merge_duplicate_folders(env: &ExecEnv) -> Result<Vec<(EntityId, EntityId)>, ExecError> {
    let mut merged = Vec::new();
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
                crate::execute::redirect_queued_parent(env, e.id.server_id, id)?;
                merged.push((e.id, EntityId::folder(id)));
                this_round += 1;
            }
        }
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
fn merge_duplicate_files(env: &ExecEnv) -> Result<Vec<(EntityId, EntityId)>, ExecError> {
    let entries = all_entries(env)?;
    let replaced: std::collections::HashSet<EntityId> = entries
        .iter()
        .filter(|e| !e.remote_deleted && e.status != LocalStatus::OutOfScope)
        .filter_map(|e| e.replaces)
        .collect();
    let mut real: HashMap<(Option<i64>, String), i64> = HashMap::new();
    for e in &entries {
        if e.id.entity_type == EntityType::File && !e.id.is_provisional() && !e.remote_deleted {
            real.insert((e.remote.parent, e.remote.name.clone()), e.id.server_id);
        }
    }
    let mut merged = Vec::new();
    for e in &entries {
        if e.id.entity_type != EntityType::File || !e.id.is_provisional() {
            continue;
        }
        if let Some(&id) = real.get(&(e.remote.parent, e.remote.name.clone())) {
            let Some(r) = env.store.get_entry(EntityId::file(id))? else {
                continue;
            };
            // A held record is not this file under another record: its own
            // file stands elsewhere on purpose. Folded into it, the file here
            // was left owned by nobody, minted again by the next scan, and
            // folded again by the next pass. It stays a provisional of its
            // own, and its upload lands beside the held name under a conflict
            // name, as any file saved at a name a held file holds does.
            if !may_take_a_merged_file(env, &r)? {
                continue;
            }
            // A source a claimant has taken its file from, until the source is
            // trashed: its file is the claimant's, and a file at its old name
            // is somebody else's. Folded in, it took a never-sent file saved
            // there as its own and sent it as its version (kill2 75127;
            // `specs/drive_file_ownership.md`, E1a).
            if replaced.contains(&r.id) {
                continue;
            }
            // Nor a record whose bytes have not arrived here: matched by name
            // alone, a stranger saved at a download's slot was folded into the
            // download and its file left nobody's. The provisional waits for
            // the download instead, which settles the two by identity -- its
            // own lost upload included, found by its bytes when they land
            // (`specs/drive_file_ownership.md`, design 1c).
            if r.synced_placement.is_none() {
                continue;
            }
            // Nor is a provisional held outside its vault: it was never sent,
            // so no server file is its upload. Folded into whatever another
            // device put at its name, its file was owned by nobody and minted
            // again in the plain folder it stands in -- and sent in the clear.
            if held_and_never_sent(env, e)? {
                continue;
            }
            // Nor when each owns a different file: two records, two files,
            // whatever their names. Folded, the provisional's file was owned
            // by nobody, and the next scan read it as the real record's edit
            // at that path (the backup reading, plain2 75237). It stays a
            // provisional of its own, and its upload lands beside the real
            // one under a conflict name.
            let strong_volume = env.vfs.personality().stable_file_identity;
            let two_files = |a: Option<jd_vfs::FileIdentity>, b: Option<jd_vfs::FileIdentity>| {
                matches!((a, b), (Some(a), Some(b)) if a.is_strong() && b.is_strong() && a != b)
            };
            if strong_volume && two_files(r.own_file, e.own_file) {
                continue;
            }
            // Without births the disk answers it, by the id the provisional
            // was minted for (`specs/drive_file_ownership.md`, 2b): the two
            // are one file only if that file stands at the name they share.
            // Folded while a swap had carried it off, it was owned by nobody,
            // and the scan read it as the edit of the record whose path it
            // stood at (plain2 75227). The real record is read as the scan
            // reads it without births, by its path: its own id is not asked,
            // since nothing updates it when its file is saved by replacement,
            // and a provisional minted for the new file read as a second file
            // kept the two on one path for ever (plat3 75401).
            if !strong_volume {
                if let (Some(own), Some(root)) = (crate::execute::never_sent_own_id(e), env.vfs.root()) {
                    let here = match relative_path(env, e)? {
                        Some(p) => env.vfs.fingerprint(&root.join(p))?.map(|fp| fp.file_id),
                        None => None,
                    };
                    if here != Some(own) {
                        continue;
                    }
                }
                // And by the bytes, which every weak volume has: the file at
                // the shared name must be the real record's, unedited -- its
                // bytes the ones it last saw there or last agreed. Otherwise
                // its file is elsewhere and this is another file: folded in,
                // the real record read it as its own edit (plain2 75298,
                // 75237, 75201; `specs/drive_weak_volume_identity.md`, class
                // M). The provisional stays, and its upload lands beside.
                if let Some(root) = env.vfs.root() {
                    let here = match relative_path(env, e)? {
                        Some(p) if env.vfs.fingerprint(&root.join(&p))?.is_some() => Some(env.vfs.hash(&root.join(p))?),
                        _ => None,
                    };
                    let its_own = |h: &String| {
                        r.last_seen_sha.as_ref() == Some(h) || r.synced_content.as_ref().is_some_and(|c| &c.sha256 == h)
                    };
                    // And the provisional's too: a merge says the two name one
                    // file. A provisional whose file a swap carried away, with
                    // the real record's download landing at the name after,
                    // was folded in while its own file stood elsewhere with no
                    // record, and was read as another record's edit (plain2
                    // 75268, on a USB stick).
                    let provisionals_too = |h: &String| e.last_seen_sha.as_ref().is_none_or(|seen| seen == h);
                    if !here.as_ref().is_some_and(|h| its_own(h) && provisionals_too(h)) {
                        continue;
                    }
                }
            }
            // By name alone, and before the disk is read: the real entry takes
            // the provisional's file only if it has no file of its own here.
            env.store.merge_file(e.id, EntityId::file(id), r.own_file.is_none())?;
            merged.push((e.id, EntityId::file(id)));
        }
    }
    Ok(merged)
}

fn folder_paths(env: &ExecEnv, dir_identity: Option<&HashMap<String, u64>>) -> Result<HashMap<String, i64>, ExecError> {
    let mut out = HashMap::new();
    // Paths a record tied here resolves to. A record the server has only
    // named never takes one of them from it, whichever comes later: kept by
    // arrival order, a peer's new folder under a renamed folder's old name
    // took the path, and every reader of this map then put the renamed
    // folder's directory, and its files, in the newcomer (B-RESTORE).
    let mut tied: std::collections::HashSet<String> = std::collections::HashSet::new();
    // Paths holding the directory a folder not yet sent was minted from. Such
    // a record is no named-only one: it was made from that directory, and
    // stands there. It shares the path with a tied record only when that
    // record's directory has left it -- the user renamed the folder and a
    // save through the old path made the old name again -- and the path is
    // the new record's. Dropped for the tied one, the folder scan then found
    // the tied record moved away and the path holding nobody, and the
    // directory was minted a second time: one directory, two folders on the
    // server, and the one left without it trashed with a peer's unsent file
    // in it (plain2 75217, 75283). Only on the directory's id: where nothing
    // can tell -- a volume without ids, a record without one -- the tied
    // record keeps the path, as it always has.
    let mut minted_from: std::collections::HashSet<String> = std::collections::HashSet::new();
    for entry in all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder {
            continue;
        }
        // By agreement, as the folder scan reads them: the scan finds each
        // folder moved from here to where its directory stands, and this map
        // learns the second path from that (`detect_folder_moves`).
        if let Some(path) = agreed_path(env, &entry)? {
            if minted_from.contains(&path) {
                continue;
            }
            let its_directory = entry.id.is_provisional()
                && match (
                    entry.synced_fingerprint.map(|f| f.file_id).filter(|id| *id != 0),
                    dir_identity.and_then(|d| d.get(&path)).copied().filter(|id| *id != 0),
                ) {
                    (Some(mine), Some(here)) => mine == here,
                    _ => false,
                };
            if its_directory {
                minted_from.insert(path.clone());
            } else if entry.holds_its_tie() {
                tied.insert(path.clone());
            } else if tied.contains(&path) {
                continue;
            }
            out.insert(path, entry.id.server_id);
        }
    }
    Ok(out)
}

/// Does this record hold something on this disk?
///
/// Every record does but two kinds. One a park released (`Unsyncable`): the
/// park gave its copy up. And one with no key, or out of scope, that is tied
/// to nothing standing here: never placed, or placed and since removed by
/// the user while the engine could not act on it (`holds_a_local_file` says
/// why the status alone cannot answer). The agreement alone is not the tie:
/// a locked device whose user deleted the decrypted file, or the vault's
/// directory, keeps both records agreed with nothing at their paths, and
/// read as holding them they kept a peer's new file or folder of that name
/// off this disk until the key came back.
pub(crate) fn holds_here(env: &ExecEnv, entry: &Entry) -> Result<bool, ExecError> {
    if !entry.holds_a_local_file() {
        return Ok(false);
    }
    if !matches!(entry.status, LocalStatus::PendingKey | LocalStatus::OutOfScope) {
        return Ok(true);
    }
    let Some(path) = relative_path(env, entry)? else {
        return Ok(false);
    };
    let full = match env.vfs.root() {
        Some(root) => root.join(&path),
        None => std::path::PathBuf::from(&path),
    };
    Ok(match entry.id.entity_type {
        EntityType::Folder => env.vfs.read_dir(&full).is_ok(),
        EntityType::File => env.vfs.fingerprint(&full)?.is_some(),
    })
}

/// The name an op would place its entry under on this disk, for an op that
/// places it at all; `None` for remote-side work and removals.
fn placed_name(env: &ExecEnv, op: &crate::order::PlannedOp) -> Result<Option<String>, ExecError> {
    use crate::reconcile::Action;
    Ok(match &op.action {
        Action::ApplyRemoteMove { to, .. } | Action::AdoptPlacement { to } => Some(to.name.clone()),
        Action::CreateLocalFolder { placement } => Some(placement.name.clone()),
        Action::PreserveLocalAs { name, .. } => Some(name.clone()),
        Action::Download => env.store.get_entry(op.entity)?.map(|e| e.remote.name),
        Action::Adopt
        | Action::UploadVersion
        | Action::UploadAsNew { .. }
        | Action::CreateRemoteFolder { .. }
        | Action::ApplyLocalMove { .. }
        | Action::TrashLocal
        | Action::TrashRemote
        | Action::Forget
        | Action::RemoveFromScope
        | Action::UnmaterializeAndPark { .. } => None,
    })
}

/// Whether a park for this reason refuses placing its entry under `name`
/// here: a clash refuses the name it clashes with, any other reason refuses
/// a name this disk cannot hold at all. By name alone, without the parent: a
/// verdict at a destination is judged in the destination's own folder, so it
/// and the round's move there share a parent; a verdict on the agreed name
/// against a same-named move into another folder only holds that move a pass.
pub(crate) fn a_park_refuses(reason: &jd_vfs::UnsyncableReason, name: &str, p: &jd_vfs::Personality) -> bool {
    use jd_vfs::UnsyncableReason as R;
    match reason {
        R::CaseClash { with } | R::UnicodeClash { with } | R::DuplicateName { with } => {
            jd_vfs::comparison_key(name, p) == jd_vfs::comparison_key(with, p)
        }
        _ => matches!(jd_vfs::to_local_name(name, p), jd_vfs::LocalName::Unsyncable(_)),
    }
}

/// An entry's path relative to the sync root: where it stands on this disk,
/// through each folder's directory where that stands (`Entry::stands_at`).
///
/// The agreed tree has no loop, and neither has the disk; a walk mixing the
/// two -- one folder's place noted before another's agreement moved on in
/// the same pass -- can, and then the agreements answer until the next scan
/// notes both again.
pub(crate) fn relative_path(env: &ExecEnv, entry: &Entry) -> Result<Option<String>, ExecError> {
    let mut parts = vec![entry.effective_local_name().to_string()];
    let mut parent = entry.local_placement().parent;
    let mut seen = std::collections::HashSet::new();
    while let Some(id) = parent {
        if !seen.insert(id) {
            return agreed_path(env, entry);
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

/// An entry's path relative to the sync root by its agreement and its
/// folders' agreements: where both sides last settled it, not where a folder
/// the user has since moved stands (`Entry::stands_at`). For the readers that
/// ask what was agreed -- the folder scan above all, which finds a folder
/// moved by comparing where its directory stands with this.
pub(crate) fn agreed_path(env: &ExecEnv, entry: &Entry) -> Result<Option<String>, ExecError> {
    let mut parts = vec![entry.agreed_local_name().to_string()];
    let mut parent = entry.agreed_placement().parent;
    let mut guard = 0;
    while let Some(id) = parent {
        guard += 1;
        if guard > 512 {
            return Err(ExecError::Contract("folder tree has a loop in it".into()));
        }
        let Some(folder) = env.store.get_entry(EntityId::folder(id))? else {
            return Ok(None);
        };
        parts.push(folder.agreed_local_name().to_string());
        parent = folder.agreed_placement().parent;
    }
    parts.reverse();
    Ok(Some(parts.join("/")))
}

/// Where an entry's SERVER placement puts it on this disk, relative to the
/// sync root: its folders as this device holds them, and its name as this
/// volume would write it (the executor's `path_for` derives it the same way).
fn server_path(env: &ExecEnv, entry: &Entry) -> Result<Option<String>, ExecError> {
    let mut there = entry.clone();
    there.synced_placement = None;
    there.stand_in = None;
    there.stands_at = None;
    there.local_name = match jd_vfs::to_local_name(&entry.remote.name, &env.vfs.personality()) {
        jd_vfs::LocalName::Escaped { local, .. } => Some(local),
        jd_vfs::LocalName::AsIs(_) | jd_vfs::LocalName::Unsyncable(_) => None,
    };
    relative_path(env, &there)
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
