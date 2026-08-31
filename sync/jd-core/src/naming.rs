//! Deciding what each entry is called **on this computer**.
//!
//! The server's namespace is more permissive than any local one. It will store
//! `Q3: final.xlsx`, and `q3: FINAL.xlsx` beside it, and a name whose last
//! character is a space, and a folder called `CON`. A Mac can hold the colon but
//! not both files; a PC can hold neither. Left unresolved, every one of those
//! becomes a filesystem error the executor retries forever — a client that is
//! busy, warm, and permanently wrong.
//!
//! So before any pass decides anything, every entry is asked one question: what
//! is your name here? There are exactly three answers.
//!
//! - **The same as on the server.** The overwhelming majority.
//! - **An adjusted name**, recorded in `local_name`. The recorded mapping is
//!   authoritative — the escape is not relied on to be reversible, so a user's
//!   genuine `%3A` in a filename is not a trap.
//! - **It cannot exist here**, recorded as [`LocalStatus::Unsyncable`] with the
//!   reason. Not an error and never silent: the file stays on the server, the
//!   entry stays tracked, and the user is told which file and why.
//!
//! The third answer is the one that matters most, and the temptation is to avoid
//! it — to materialize the second of two case-clashing siblings under a mangled
//! name so that "everything syncs". That is worse than refusing. The mangled
//! name reads as a rename on the next scan and gets pushed back to the server,
//! so the user's file is renamed on every device by a program they did not ask
//! to rename anything. Refusing and saying so leaves the file exactly where it
//! was.

use std::collections::HashMap;

use jd_vfs::{LocalName, Personality, UnsyncableReason};

use crate::execute::{ExecEnv, ExecError};
use crate::model::{EntityId, EntityType, Entry, LocalStatus, Placement};

/// What one round of naming changed.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct NamingOutcome {
    /// Entries whose name had to be adjusted to fit this filesystem.
    pub escaped: usize,
    /// Entries that cannot be materialized here, and why.
    pub unsyncable: Vec<(EntityId, UnsyncableReason)>,
    /// Entries that were unsyncable and are not any more — the sibling that was
    /// in the way got renamed or deleted. Recovery has to be automatic, or a
    /// user who fixes the clash sees nothing happen.
    pub recovered: Vec<EntityId>,
    /// Encrypted files this device is renaming because two of them hold one
    /// name, with where each is now and what it is to be called.
    ///
    /// Carried out of here rather than acted on here: renaming is the pass's
    /// business, journalled with a key like every other order, and this stage
    /// only decides.
    pub renames: Vec<(EntityId, Placement, Placement)>,
    /// Entries on their way to a name this disk cannot hold beside what is
    /// already there. Carried out rather than acted on here: giving up a local
    /// copy is an operation with a filesystem step and a precondition, and this
    /// stage only decides.
    pub park_at_destination: Vec<(EntityId, UnsyncableReason)>,
}

impl NamingOutcome {
    pub fn is_empty(&self) -> bool {
        self.escaped == 0
            && self.unsyncable.is_empty()
            && self.recovered.is_empty()
            && self.renames.is_empty()
    }
}

/// Who keeps a name when two encrypted files in one folder hold it.
///
/// **The problem.** The server enforces name uniqueness on the title it stores,
/// and an encrypted file's stored title is an opaque per-file id — unique by
/// construction. So it cannot refuse a duplicate real name, and two files in
/// one vault folder can genuinely be called the same thing. Neither is a
/// conflicted version of the other; they are two files that arrived at one
/// name. The name resolver marks the loser unsyncable, and unsyncable is not a
/// state this one can leave: nothing about a settled tree changes to release
/// it, so one of the two files simply never appears on any disk, forever.
///
/// **The rule.** The lowest server id keeps the name. Not resolution order,
/// which ranks materialized entries first and therefore differs from one
/// computer to the next: two devices would each rename the other's file and
/// neither would ever hold still. A server id is the same number everywhere and
/// does not move, so every device renames the same file to the same thing and
/// arrives without needing to agree first.
///
/// An entry the server has never seen loses to one it has. It has no id to
/// compare and nothing has been told about it yet, so renaming it costs
/// nothing.
fn duplicate_losers(
    pairs: &[(Entry, jd_vfs::Resolved)],
    personality: &Personality,
) -> HashMap<EntityId, String> {
    // Only files, and only encrypted ones. A plaintext duplicate cannot happen
    // -- the server refuses it -- so renaming on the strength of one would mean
    // acting on a state the server says is impossible.
    let mut groups: HashMap<String, Vec<&Entry>> = HashMap::new();
    for (entry, resolved) in pairs {
        if !entry.is_encrypted || entry.id.entity_type != EntityType::File {
            continue;
        }
        let name = &competing_placement(entry).name;
        groups
            .entry(jd_vfs::comparison_key(name, personality))
            .or_default()
            .push(entry);
        let _ = resolved;
    }

    // Every name in the folder, so a chosen replacement does not walk into
    // another sibling. Includes the plaintext ones: they are on the same disk
    // and compete for the same slots.
    let taken: Vec<String> = pairs
        .iter()
        .map(|(e, _)| jd_vfs::comparison_key(&competing_placement(e).name, personality))
        .collect();

    let mut out = HashMap::new();
    for (_, mut group) in groups {
        if group.len() < 2 {
            continue;
        }
        // Provisional last, then by id. `sort_by_key` is stable, so equal keys
        // keep the order they came in -- which cannot happen here, because two
        // entries never share a server id.
        group.sort_by_key(|e| (e.id.is_provisional(), e.id.server_id));
        let mut assigned: Vec<String> = Vec::new();
        for loser in group.into_iter().skip(1) {
            let name = &competing_placement(loser).name;
            // From 2, the way a person counts copies. Bounded for the same
            // reason the conflict-copy search is: a folder that defeats this
            // has something else wrong with it.
            let free = (2..=1000).find_map(|n| {
                let candidate = jd_vfs::numbered_name(name, n);
                let key = jd_vfs::comparison_key(&candidate, personality);
                (!taken.contains(&key) && !assigned.contains(&key)).then_some(candidate)
            });
            if let Some(free) = free {
                assigned.push(jd_vfs::comparison_key(&free, personality));
                out.insert(loser.id, free);
            }
        }
    }
    out
}

/// Where an entry is competing for a name, and under what name.
///
/// Normally the last-agreed placement: while a remote move is known but not yet
/// applied the file is physically still in its old folder, and that is the
/// folder whose siblings it is really up against.
///
/// **An entry holding no local file is the exception.** It was never
/// materialized, so it is competing with nobody — and judging it in the folder
/// it was last agreed to be in is how it stays stuck there. For a parked entry
/// that is a closed loop: it lost the name there, so its move was never
/// applied; its move was never applied, so it is still judged there. Nothing
/// about a settled tree ever changes to release it.
///
/// The vault sweep found it as one file frozen in a folder it had already left
/// on one device while the other had applied the move, both reporting
/// themselves settled. Encryption is not required to reach it — it needs only a
/// name taken at the old location — but a vault makes it likelier, because the
/// server cannot refuse the duplicate name that starts it.
///
/// An entry waiting for a key is the same bargain and the harm runs the other
/// way: it holds nothing in its old folder either, and leaving it there lets it
/// park a REAL file that wants that name — freezing a file this device could
/// otherwise sync perfectly well, over a rival that is not there.
///
/// `PendingDownload` deliberately still counts as holding its old spot: those
/// bytes are on their way to that path.
fn competing_placement(entry: &Entry) -> &crate::model::Placement {
    if entry.holds_a_local_file() {
        entry.local_placement()
    } else {
        &entry.remote
    }
}


/// Resolve every tracked entry's local name against this filesystem.
///
/// `root_prefix_bytes` is the length of the sync root's own path, which counts
/// against the total path budget: the same tree fits under `D:\jd` and does not
/// fit under `C:\Users\someone\OneDrive\Documents\Joinery Drive`.
///
/// Runs before the local scan, because the scan pairs what is on disk against
/// what the engine believes it is called, and that belief is what this computes.
/// One consequence is worth stating plainly: a file created locally during *this*
/// pass has no entry yet, so it does not claim its slot until the next one. That
/// is self-correcting and costs nothing — a remote sibling arriving into an
/// occupied slot is handled by the ordinary occupied-slot path, which preserves
/// the occupant rather than overwriting it.
pub fn apply_naming(
    env: &ExecEnv,
    personality: &Personality,
    root_prefix_bytes: usize,
) -> Result<NamingOutcome, ExecError> {
    let mut out = NamingOutcome::default();

    // Group by the folder each entry sits in *locally*. The last-agreed parent,
    // not the remote one: while a remote move is known but not yet applied the
    // file is still in its old folder, and that is the folder whose siblings it
    // is actually competing with. See `competing_placement` for the one case
    // where that is not true.
    let mut by_parent: HashMap<Option<i64>, Vec<Entry>> = HashMap::new();
    for entry in crate::pass::all_entries(env)? {
        // Out of scope is a deliberate absence, and something the server has
        // already deleted is on its way out. Neither should hold a slot against
        // a sibling that wants to exist.
        if entry.status == LocalStatus::OutOfScope || entry.remote_deleted {
            continue;
        }
        by_parent
            .entry(competing_placement(&entry).parent)
            .or_default()
            .push(entry);
    }

    // Folder paths, for the total-path-length check. Computed once from the
    // names as they stand; a folder whose own name changes this pass shifts its
    // children's lengths by a byte or two, which the next pass settles.
    let folder_paths = folder_path_lengths(env)?;

    // Folder -> what actually holds a name in it, filled as each folder is
    // resolved and read by `judge_destinations` afterwards.
    let mut settled: HashMap<Option<i64>, Vec<(EntityId, String)>> = HashMap::new();

    for (parent, mut siblings) in by_parent {
        siblings.sort_by_key(resolution_order);
        let names: Vec<String> = siblings
            .iter()
            .map(|e| competing_placement(e).name.clone())
            .collect();
        let resolved = jd_vfs::resolve_siblings(&names, personality);
        let pairs: Vec<(Entry, jd_vfs::Resolved)> =
            siblings.into_iter().zip(resolved).collect();
        // Decided over the whole folder, before any single entry is judged: who
        // keeps a duplicated name depends on who else is holding it.
        let renamed = duplicate_losers(&pairs, personality);

        let parent_len = parent
            .and_then(|id| folder_paths.get(&id).copied())
            .map(|len| len + 1) // the separator
            .unwrap_or(0);

        for (entry, r) in pairs {
            let (local_name, verdict) = match r.outcome {
                LocalName::AsIs(name) => {
                    // Equal to the server's spelling in the common case. Not
                    // equal when the server holds a decomposed name and this
                    // filesystem wants the composed one, which is a mapping like
                    // any other and has to be recorded as one.
                    let mapped = (name != competing_placement(&entry).name).then_some(name);
                    (mapped, None)
                }
                LocalName::Escaped { local, .. } => {
                    out.escaped += 1;
                    (Some(local), None)
                }
                LocalName::Unsyncable(reason) => (entry.local_name.clone(), Some(reason)),
            };

            // Having no key for something outranks every name verdict, and is
            // checked first because it does not depend on the name at all.
            // Reporting a case clash for a file this device could not have
            // written either way would send the user to rename something that
            // was never the problem.
            if let Some(status) = no_key_for(env, &entry) {
                let mut updated = entry.clone();
                updated.status = status;
                if updated != entry {
                    env.store.put_entry(&updated)?;
                }
                continue;
            }

            // Two encrypted files in one folder holding one name, and this is
            // the one that gives it up. Marking it unsyncable is what used to
            // happen, and nothing ever released it -- the file existed on the
            // server and appeared on no disk anywhere, for good.
            //
            // The new name is given to the entry here, in the same breath as
            // the rename is recorded, and that ordering is the whole point. The
            // rename is a request to a server that may not answer this pass, or
            // the next one; the download is a local act that happens as soon as
            // the queue reaches it. Left to disagree, the file lands on the
            // occupied name, the occupied-name path preserves the occupant as a
            // conflict copy, and the user is told two people edited one file
            // when nobody did.
            let (local_name, verdict) = match renamed.get(&entry.id) {
                Some(to) => {
                    out.renames.push((
                        entry.id,
                        entry.remote.clone(),
                        Placement {
                            parent: entry.remote.parent,
                            name: to.clone(),
                        },
                    ));
                    (Some(to.clone()), None)
                }
                None => (local_name, verdict),
            };

            // Length is checked against the resolved name, because escaping
            // makes names longer — a colon becomes three characters — and a name
            // that only just fitted may not any more.
            let verdict = verdict.or_else(|| {
                let name_len = local_name
                    .as_deref()
                    .unwrap_or(&competing_placement(&entry).name)
                    .len();
                let total = parent_len + name_len;
                (!jd_vfs::path_fits(total, root_prefix_bytes, personality)).then(|| {
                    UnsyncableReason::PathTooLong {
                        bytes: total + root_prefix_bytes + 1,
                        limit: personality.max_path_bytes,
                    }
                })
            });

            // Was this entry parked, for either of the two reasons an entry
            // gets parked? A key that arrives has to release the file exactly
            // as a cleared name clash does — a status nothing ever clears is a
            // file that never syncs again, and nobody would know why.
            let was_held = matches!(
                entry.status,
                LocalStatus::Unsyncable(_) | LocalStatus::PendingKey
            );
            let mut updated = entry.clone();
            updated.local_name = local_name;

            match verdict {
                Some(reason) => {
                    // Re-raising the same verdict every pass would fill the
                    // issues panel with one problem repeated a thousand times.
                    if entry.status != LocalStatus::Unsyncable(reason.clone()) {
                        out.unsyncable.push((entry.id, reason.clone()));
                    }
                    updated.status = LocalStatus::Unsyncable(reason);
                }
                None if was_held => {
                    // Whatever was in the way has cleared. Whether it can be
                    // synced now is not this layer's call — it says only that
                    // the entry is usable again and hands it back to the
                    // ordinary path, which works out from the agreement what
                    // needs to happen.
                    updated.status = if entry.synced_placement.is_some() {
                        LocalStatus::Synced
                    } else {
                        LocalStatus::PendingDownload
                    };
                    out.recovered.push(entry.id);
                }
                None => {}
            }

            if !matches!(updated.status, LocalStatus::Unsyncable(_)) {
                settled
                    .entry(parent)
                    .or_default()
                    .push((updated.id, competing_placement(&updated).name.clone()));
            }

            if updated != entry {
                env.store.put_entry(&updated)?;
            }
        }
    }

    judge_destinations(env, personality, &settled, &mut out)?;

    Ok(out)
}

/// Judge an entry on its way somewhere against the folder it is going TO.
///
/// `competing_placement` resolves a materialized entry against its last AGREED
/// placement, deliberately -- until the move applies the file is still in its
/// old folder, competing with its old siblings. The consequence is that nothing
/// asks whether the DESTINATION can hold it, and `path_for` then derives a local
/// name from the destination name alone: a strictly weaker computation that
/// cannot see siblings, so it cannot see that the name it derived is already
/// taken.
///
/// What that cost: a user with a file genuinely called `%43ON.txt`, and
/// `CON.txt` moved into the same folder. Windows escapes `CON.txt` onto exactly
/// that name, the move landed on it, and `make_room` moved the user's real file
/// aside as a conflict copy -- which propagated to the server and to every other
/// device, including a Linux one where the two names never collided. It
/// converged, so no sweep could find it.
///
/// The entrant is judged LAST so the file already wearing the name keeps it,
/// and losing is not a rename of anybody: the loser gives up its own local copy
/// and parks. Nothing that belongs to the winner is touched.
fn judge_destinations(
    env: &ExecEnv,
    personality: &Personality,
    settled: &HashMap<Option<i64>, Vec<(EntityId, String)>>,
    out: &mut NamingOutcome,
) -> Result<(), ExecError> {
    for entry in crate::pass::all_entries(env)? {
        if entry.status == LocalStatus::OutOfScope || entry.remote_deleted {
            continue;
        }
        if !entry.holds_a_local_file() {
            continue;
        }
        // Placement inequality, not parent inequality. A server rename inside
        // one folder reaches the same clash with nothing reparented, and a
        // trigger watching only the parent would sail straight past it.
        if entry.remote == *entry.local_placement() {
            continue;
        }
        let mut names: Vec<String> = settled
            .get(&entry.remote.parent)
            .map(|v| {
                v.iter()
                    .filter(|(id, _)| *id != entry.id)
                    .map(|(_, n)| n.clone())
                    .collect()
            })
            .unwrap_or_default();
        let entrant = names.len();
        names.push(entry.remote.name.clone());
        let resolved = jd_vfs::resolve_siblings(&names, personality);
        // Only a COLLISION with something already in the destination. Whether
        // the name is usable at all -- too long, empty once escaped, wearing
        // the engine's own reserved prefix -- is the main loop's judgement and
        // it has already made it.
        //
        // The distinction is load-bearing, not tidiness. An entry whose server
        // name is `.jd-swap-...` is one an interrupted rename left half
        // finished, and the operation that renames it back is the only thing
        // that will ever clean it up. Parking it here for `ReservedPrefix` --
        // which is true of the name, and beside the point -- cancels that
        // recovery and strands the scratch name on the server for ever.
        let taken = match &resolved[entrant].outcome {
            jd_vfs::LocalName::Unsyncable(reason) => matches!(
                reason,
                UnsyncableReason::CaseClash { .. }
                    | UnsyncableReason::UnicodeClash { .. }
                    | UnsyncableReason::DuplicateName { .. }
            )
            .then(|| reason.clone()),
            _ => None,
        };
        if let Some(reason) = taken {
            out.park_at_destination.push((entry.id, reason));
        }
    }
    Ok(())
}

/// Is this an encrypted entry this device has no key for?
///
/// `Some(PendingKey)` means it waits: not an error, not a defect, and not
/// something the user does anything about *here* — the key arrives from
/// somewhere else or it does not. `None` means carry on with the ordinary name
/// resolution, which for an encrypted file runs against its **decrypted** name,
/// because that is the name that has to fit on this disk and the name a case
/// clash would be about.
///
/// A folder with no key is held back, and that is the load-bearing half. A
/// folder is encrypted so that everything inside it is; materializing one on a
/// device that cannot encrypt means the next file the user drops into it goes up
/// in the clear, into a folder they were told was private.
fn no_key_for(env: &ExecEnv, entry: &Entry) -> Option<LocalStatus> {
    if !entry.is_encrypted {
        return None;
    }
    if env.vault.is_none() {
        return Some(LocalStatus::PendingKey);
    }
    if entry.id.entity_type == EntityType::Folder {
        // A folder's own name is plaintext on the server; the vault key is what
        // makes it safe to have, not what makes it readable.
        return None;
    }
    if entry.id.is_provisional() {
        // Created here, inside an encrypted folder, and not yet uploaded. There
        // is no grant to hold because the file does not exist on the server —
        // this device mints its key when it uploads.
        return None;
    }
    // A grant, and a name that came out of it. The name is the proof that
    // matters: a grant that will not open, or metadata this build could not
    // read, leaves the entry holding the server's placeholder, and materializing
    // *that* is the exact failure the whole design is avoiding.
    match (entry.wrapped_file_key.is_some(), entry.content_id.is_some()) {
        (true, true) => None,
        _ => Some(LocalStatus::PendingKey),
    }
}

/// Who gets first claim on a name.
///
/// Anything already on this disk claims first. That is not a preference, it is
/// the only answer that does not delete data: demoting a materialized file
/// because a lower-numbered sibling turned up would mean removing a file the
/// user can currently see, to make room for one they cannot, over a name clash
/// neither of them caused.
///
/// Everything else goes by server id, which is stable, identical on every
/// device, and survives a state rebuild.
///
/// The consequence, stated honestly: two devices that downloaded a clashing pair
/// in different orders keep different members of it. Both files remain on the
/// server and both devices report the clash, so nothing is lost and the user is
/// told — which is the whole bargain of refusing rather than mangling.
///
/// "Already on this disk" has to mean already on this disk UNDER THE NAME IT IS
/// CLAIMING. Without that, an entry mid-move or mid-rename satisfies it from the
/// placement it is leaving, so a lower-numbered arrival outranks the file
/// already wearing the name and the settled file is the one demoted -- which
/// cost a user the filename they chose, on every device they owned, over a
/// clash that existed on one of them.
///
/// It is the PLACEMENT that has to be settled, not the parent. A rename inside
/// one folder claims a new name with nothing reparented, and a rule comparing
/// folders would call both of them incumbents and let the claimant win on id.
fn resolution_order(e: &Entry) -> (u8, i64) {
    let materialized =
        e.synced_placement.is_some() || e.synced_fingerprint.is_some() || e.id.is_provisional();
    let settled_here = e
        .synced_placement
        .as_ref()
        .map_or(true, |p| *p == e.remote);
    let rank = match (materialized, settled_here) {
        (true, true) => 0,
        (true, false) => 1,
        (false, _) => 2,
    };
    (rank, e.id.server_id)
}

/// Length in bytes of each tracked folder's path below the root.
fn folder_path_lengths(env: &ExecEnv) -> Result<HashMap<i64, usize>, ExecError> {
    let mut out = HashMap::new();
    for entry in crate::pass::all_entries(env)? {
        if entry.id.entity_type != EntityType::Folder {
            continue;
        }
        if let Some(path) = crate::pass::relative_path(env, &entry)? {
            out.insert(entry.id.server_id, path.len());
        }
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::model::{ContentId, Placement};
    use crate::store::Store;
    use jd_vfs::Fingerprint;

    fn entry(id: EntityId, name: &str) -> Entry {
        Entry {
            id,
            remote: Placement {
                parent: None,
                name: name.into(),
            },
            remote_content: Some(ContentId {
                sha256: "sha".into(),
                size: 1,
            }),
            remote_modified_time: None,
            head_change_id: 1,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: None,
            synced_placement: None,
            synced_fingerprint: None,
            local_name: None,
            status: LocalStatus::PendingDownload,
            wrapped_file_key: None,
        }
    }

    /// An entry that has completed a sync and is therefore on this disk.
    fn materialized(id: EntityId, name: &str) -> Entry {
        let mut e = entry(id, name);
        e.synced_placement = Some(e.remote.clone());
        e.synced_content = e.remote_content.clone();
        e.synced_fingerprint = Some(Fingerprint {
            size: 1,
            mtime_ns: 1,
            file_id: id.server_id as u64,
        });
        e.status = LocalStatus::Synced;
        e
    }

    struct Fixture {
        _dir: std::path::PathBuf,
        store: Store,
    }

    fn fixture(tag: &str) -> Fixture {
        let dir = std::env::temp_dir().join(format!(
            "jd-naming-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let store = Store::open(&dir.join("state.db")).unwrap();
        Fixture { _dir: dir, store }
    }

    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self._dir);
        }
    }

    /// Naming needs a store and nothing else, so the rest of the environment is
    /// a stub that would panic loudly if this layer ever reached for it.
    fn env(store: &Store) -> ExecEnv<'_> {
        crate::execute::ExecEnv {
            store,
            vfs: &NoFs,
            api: &NoNet,
            now_ms: &|| 0,
            conflict_name: &|n: &str, _s: u32| n.to_string(),
            vault: None,
        }
    }

    /// The same, on a device that was linked with encrypted folders enabled.
    fn env_with_vault<'a>(store: &'a Store, vault: &'a crate::vault::Vault) -> ExecEnv<'a> {
        ExecEnv {
            vault: Some(vault),
            ..env(store)
        }
    }

    fn a_vault() -> (crate::vault::Vault, String) {
        let kp = jd_crypto::vault::generate_vault_keypair();
        let public = kp.public_key_b64.clone();
        (
            crate::vault::Vault::from_secret_key(&kp.secret_key_pkcs8).unwrap(),
            public,
        )
    }

    struct NoFs;
    impl jd_vfs::Vfs for NoFs {
        fn personality(&self) -> Personality {
            Personality::linux()
        }
        fn root(&self) -> Option<std::path::PathBuf> {
            None
        }
        fn read_dir(&self, _p: &std::path::Path) -> jd_vfs::VfsResult<Vec<jd_vfs::DirEntry>> {
            Ok(Vec::new())
        }
        fn read_dir_all(&self, _p: &std::path::Path) -> jd_vfs::VfsResult<Vec<jd_vfs::DirEntry>> {
            Ok(Vec::new())
        }
        fn fingerprint(&self, _p: &std::path::Path) -> jd_vfs::VfsResult<Option<Fingerprint>> {
            Ok(None)
        }
        fn hash(&self, _p: &std::path::Path) -> jd_vfs::VfsResult<String> {
            unreachable!("naming does not read file contents")
        }
        fn create_dir(&self, _p: &std::path::Path) -> jd_vfs::VfsResult<()> {
            unreachable!("naming does not touch the filesystem")
        }
        fn rename(&self, _a: &std::path::Path, _b: &std::path::Path) -> jd_vfs::VfsResult<()> {
            unreachable!("naming does not touch the filesystem")
        }
        fn trash(&self, _p: &std::path::Path) -> jd_vfs::VfsResult<()> {
            unreachable!("naming never deletes anything")
        }
        fn spool(&self, _t: &std::path::Path) -> jd_vfs::VfsResult<Box<dyn jd_vfs::SpoolFile>> {
            unreachable!("naming does not transfer bytes")
        }
        fn open_read(&self, _p: &std::path::Path) -> jd_vfs::VfsResult<Box<dyn jd_vfs::ReadSeek>> {
            unreachable!("naming does not transfer bytes")
        }
        fn scratch(&self) -> jd_vfs::VfsResult<Box<dyn jd_vfs::ScratchFile>> {
            unreachable!("naming does not transfer bytes")
        }
    }

    struct NoNet;
    impl jd_proto::DriveApi for NoNet {
        fn action(
            &self,
            _name: &str,
            _body: serde_json::Value,
        ) -> Result<serde_json::Value, jd_proto::ProtoError> {
            unreachable!("naming does not talk to the server")
        }
        fn action_idempotent(
            &self,
            _name: &str,
            _body: serde_json::Value,
            _key: &str,
        ) -> Result<serde_json::Value, jd_proto::ProtoError> {
            unreachable!("naming does not talk to the server")
        }
        fn upload(
            &self,
            _p: &jd_proto::UploadParams,
            _r: &mut dyn jd_proto::ReadSeek,
        ) -> Result<jd_proto::UploadOutcome, jd_proto::ProtoError> {
            unreachable!("naming does not transfer bytes")
        }
        fn download(
            &self,
            _url: &str,
            _from: u64,
            _out: &mut dyn std::io::Write,
        ) -> Result<u64, jd_proto::ProtoError> {
            unreachable!("naming does not transfer bytes")
        }
    }

    #[test]
    fn a_legal_name_gets_no_mapping_at_all() {
        let f = fixture("plain");
        f.store
            .put_entry(&entry(EntityId::file(1), "Report.txt"))
            .unwrap();

        let out = apply_naming(&env(&f.store), &Personality::linux(), 10).unwrap();
        assert!(out.is_empty());
        let e = f.store.get_entry(EntityId::file(1)).unwrap().unwrap();
        assert_eq!(
            e.local_name, None,
            "no mapping means no row to keep in step"
        );
    }

    #[test]
    fn a_parked_entry_is_judged_where_the_server_says_it_is() {
        // It lost the name in folder 1 and was parked. The server has since put
        // it in folder 2, where nothing else wants that name -- so the park is
        // over, and saying so is the only way it ever gets there. Judging it in
        // the folder it was last agreed to be in is a closed loop: it lost the
        // name there, so its move was never applied; its move was never
        // applied, so it is still judged there. One device applied the move and
        // the other kept the file frozen in a folder it had already left, both
        // reporting themselves settled.
        let f = fixture("parkedmove");
        // Both folders have to exist as entries: everything in a pass is found
        // by walking down from the root, so a file under a folder nothing
        // tracks is never reached at all.
        f.store
            .put_entry(&materialized(EntityId::folder(1), "Shared"))
            .unwrap();
        f.store
            .put_entry(&materialized(EntityId::folder(2), "Elsewhere"))
            .unwrap();

        // The one holding the name, and holding the file.
        let mut winner = materialized(EntityId::file(1), "slot-2.dat");
        winner.remote.parent = Some(1);
        winner.synced_placement = Some(Placement {
            parent: Some(1),
            name: "slot-2.dat".into(),
        });
        f.store.put_entry(&winner).unwrap();

        // The parked one, whose agreement still points at folder 1 while the
        // server has it in folder 2.
        let mut parked = entry(EntityId::file(2), "slot-2.dat");
        parked.remote.parent = Some(2);
        parked.synced_placement = Some(Placement {
            parent: Some(1),
            name: "slot-2.dat".into(),
        });
        parked.status = LocalStatus::Unsyncable(UnsyncableReason::DuplicateName {
            with: "slot-2.dat".into(),
        });
        f.store.put_entry(&parked).unwrap();

        apply_naming(&env(&f.store), &Personality::linux(), 10).unwrap();

        let after = f.store.get_entry(EntityId::file(2)).unwrap().unwrap();
        assert_eq!(
            after.status,
            LocalStatus::Synced,
            "the clash is over -- it is not in that folder any more"
        );
        assert_eq!(
            f.store.get_entry(EntityId::file(1)).unwrap().unwrap().status,
            LocalStatus::Synced,
            "and the one that won the name is untouched"
        );
    }

    #[test]
    fn a_parked_entry_that_has_not_moved_stays_parked() {
        // The counterpart, and the reason the rule says "where the server says
        // it is" rather than "somewhere else": an entry still sitting in the
        // folder it lost the name in has nothing new to say.
        let f = fixture("parkedstill");
        f.store
            .put_entry(&materialized(EntityId::folder(1), "Shared"))
            .unwrap();

        let mut winner = entry(EntityId::file(1), "slot-2.dat");
        winner.remote.parent = Some(1);
        winner.synced_placement = Some(Placement {
            parent: Some(1),
            name: "slot-2.dat".into(),
        });
        f.store.put_entry(&winner).unwrap();

        let mut parked = entry(EntityId::file(2), "slot-2.dat");
        parked.remote.parent = Some(1);
        parked.synced_placement = Some(Placement {
            parent: Some(1),
            name: "slot-2.dat".into(),
        });
        parked.status = LocalStatus::Unsyncable(UnsyncableReason::DuplicateName {
            with: "slot-2.dat".into(),
        });
        f.store.put_entry(&parked).unwrap();

        apply_naming(&env(&f.store), &Personality::linux(), 10).unwrap();

        assert!(
            matches!(
                f.store.get_entry(EntityId::file(2)).unwrap().unwrap().status,
                LocalStatus::Unsyncable(UnsyncableReason::DuplicateName { .. })
            ),
            "two live entries still want one name in one folder"
        );
    }

    #[test]
    fn an_entry_waiting_for_a_key_does_not_park_a_real_file_in_a_folder_it_has_left() {
        // The harm runs the other way here. A device with no key for an
        // encrypted file holds nothing on disk for it; if the server moves that
        // file elsewhere, the stale agreement keeps it claiming a name in the
        // old folder -- and a real file that wants that name is parked against
        // a rival which is not there, and which nothing will ever move.
        let f = fixture("pendingkeymove");
        f.store
            .put_entry(&materialized(EntityId::folder(1), "Shared"))
            .unwrap();
        f.store
            .put_entry(&materialized(EntityId::folder(2), "Elsewhere"))
            .unwrap();

        // No key for this one, and the server has since moved it to folder 2.
        let mut keyless = entry(EntityId::file(1), "notes.txt");
        keyless.is_encrypted = true;
        keyless.remote.parent = Some(2);
        keyless.synced_placement = Some(Placement {
            parent: Some(1),
            name: "notes.txt".into(),
        });
        keyless.status = LocalStatus::PendingKey;
        f.store.put_entry(&keyless).unwrap();

        // An ordinary file that wants that name in folder 1, and can have it.
        let mut real = entry(EntityId::file(2), "notes.txt");
        real.remote.parent = Some(1);
        f.store.put_entry(&real).unwrap();

        apply_naming(&env(&f.store), &Personality::linux(), 10).unwrap();

        let after = f.store.get_entry(EntityId::file(2)).unwrap().unwrap();
        assert!(
            !matches!(after.status, LocalStatus::Unsyncable(_)),
            "nothing is in that folder to clash with, got {:?}",
            after.status
        );
        assert_eq!(
            f.store.get_entry(EntityId::file(1)).unwrap().unwrap().status,
            LocalStatus::PendingKey,
            "and the one waiting for a key is still waiting for it"
        );
    }

    #[test]
    fn a_colon_is_escaped_on_windows_and_left_alone_on_linux() {
        for (personality, expect) in [
            (Personality::windows(), Some("Q3%3A final.xlsx".to_string())),
            (Personality::linux(), None),
        ] {
            let f = fixture("colon");
            f.store
                .put_entry(&entry(EntityId::file(1), "Q3: final.xlsx"))
                .unwrap();
            apply_naming(&env(&f.store), &personality, 10).unwrap();
            assert_eq!(
                f.store
                    .get_entry(EntityId::file(1))
                    .unwrap()
                    .unwrap()
                    .local_name,
                expect
            );
        }
    }

    #[test]
    fn the_second_of_two_case_clashing_siblings_is_refused_not_mangled() {
        // The temptation is to materialize it as `report (1).txt` so that
        // "everything syncs". That name reads as a rename on the next scan and
        // gets pushed back to the server, renaming the user's file on every
        // device they own.
        let f = fixture("caseclash");
        f.store
            .put_entry(&entry(EntityId::file(1), "Report.txt"))
            .unwrap();
        f.store
            .put_entry(&entry(EntityId::file(2), "report.txt"))
            .unwrap();

        let out = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();

        assert_eq!(out.unsyncable.len(), 1);
        assert_eq!(out.unsyncable[0].0, EntityId::file(2));
        assert!(matches!(
            out.unsyncable[0].1,
            UnsyncableReason::CaseClash { .. }
        ));
        // The winner is untouched and materializes normally.
        let winner = f.store.get_entry(EntityId::file(1)).unwrap().unwrap();
        assert_eq!(winner.status, LocalStatus::PendingDownload);
        assert_eq!(winner.local_name, None);
    }

    #[test]
    fn both_case_variants_materialize_where_the_volume_can_tell_them_apart() {
        let f = fixture("caseok");
        f.store
            .put_entry(&entry(EntityId::file(1), "Report.txt"))
            .unwrap();
        f.store
            .put_entry(&entry(EntityId::file(2), "report.txt"))
            .unwrap();

        let out = apply_naming(&env(&f.store), &Personality::linux(), 10).unwrap();
        assert!(out.unsyncable.is_empty());
    }

    #[test]
    fn a_file_already_on_this_disk_keeps_its_name_against_a_lower_numbered_sibling() {
        // Ordering by server id alone would demote the materialized file, and
        // demoting it means removing a file the user can currently see to make
        // room for one they cannot.
        let f = fixture("materialized");
        f.store
            .put_entry(&entry(EntityId::file(1), "Report.txt"))
            .unwrap();
        f.store
            .put_entry(&materialized(EntityId::file(2), "report.txt"))
            .unwrap();

        let out = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();

        assert_eq!(out.unsyncable.len(), 1);
        assert_eq!(
            out.unsyncable[0].0,
            EntityId::file(1),
            "the one that is not on disk yields"
        );
        assert_eq!(
            f.store
                .get_entry(EntityId::file(2))
                .unwrap()
                .unwrap()
                .status,
            LocalStatus::Synced
        );
    }

    #[test]
    fn an_entry_recovers_by_itself_when_the_clash_clears() {
        // A user who renames the offending sibling and sees nothing happen has
        // no reason to believe the client works.
        let f = fixture("recover");
        f.store
            .put_entry(&entry(EntityId::file(1), "Report.txt"))
            .unwrap();
        f.store
            .put_entry(&entry(EntityId::file(2), "report.txt"))
            .unwrap();
        apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();
        assert!(matches!(
            f.store
                .get_entry(EntityId::file(2))
                .unwrap()
                .unwrap()
                .status,
            LocalStatus::Unsyncable(_)
        ));

        // The user renames the winner out of the way on another device.
        let mut winner = f.store.get_entry(EntityId::file(1)).unwrap().unwrap();
        winner.remote.name = "Quarterly.txt".into();
        f.store.put_entry(&winner).unwrap();

        let out = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();
        assert_eq!(out.recovered, vec![EntityId::file(2)]);
        assert_eq!(
            f.store
                .get_entry(EntityId::file(2))
                .unwrap()
                .unwrap()
                .status,
            LocalStatus::PendingDownload
        );
    }

    #[test]
    fn a_standing_clash_is_not_re_reported_every_pass() {
        // One problem repeated a thousand times is a panel nobody reads.
        let f = fixture("norepeat");
        f.store
            .put_entry(&entry(EntityId::file(1), "Report.txt"))
            .unwrap();
        f.store
            .put_entry(&entry(EntityId::file(2), "report.txt"))
            .unwrap();

        let first = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();
        let second = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();

        assert_eq!(first.unsyncable.len(), 1);
        assert!(second.unsyncable.is_empty(), "already told them");
    }

    #[test]
    fn something_the_server_deleted_does_not_hold_a_slot_against_a_live_sibling() {
        let f = fixture("deleted");
        let mut gone = entry(EntityId::file(1), "Report.txt");
        gone.remote_deleted = true;
        f.store.put_entry(&gone).unwrap();
        f.store
            .put_entry(&entry(EntityId::file(2), "report.txt"))
            .unwrap();

        let out = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();
        assert!(
            out.unsyncable.is_empty(),
            "the surviving file takes the name the deleted one had"
        );
    }

    #[test]
    fn a_path_too_deep_for_the_volume_is_refused_with_that_reason_not_a_name_one() {
        // Every name is legal; the tree is simply deeper than Windows will hold.
        // Told apart from a name problem because the fix is different — the user
        // moves the folder, they do not rename the file.
        let f = fixture("deep");
        let tight = Personality {
            max_path_bytes: 40,
            ..Personality::windows()
        };
        f.store
            .put_entry(&entry(EntityId::file(1), &"a".repeat(30)))
            .unwrap();

        let out = apply_naming(&env(&f.store), &tight, 20).unwrap();
        assert!(matches!(
            out.unsyncable.first().map(|(_, r)| r),
            Some(UnsyncableReason::PathTooLong { .. })
        ));
    }

    #[test]
    fn the_same_file_fits_or_does_not_depending_on_where_the_root_is() {
        let deep_root = Personality {
            max_path_bytes: 60,
            ..Personality::windows()
        };
        for (prefix, expect_refused) in [(5usize, false), (45usize, true)] {
            let f = fixture("rootlen");
            f.store
                .put_entry(&entry(EntityId::file(1), &"a".repeat(30)))
                .unwrap();
            let out = apply_naming(&env(&f.store), &deep_root, prefix).unwrap();
            assert_eq!(!out.unsyncable.is_empty(), expect_refused);
        }
    }

    #[test]
    fn out_of_scope_entries_are_left_entirely_alone() {
        // A descoped subtree holds no local presence, so it competes for no
        // names — and touching its status here would undo the descoping.
        let f = fixture("scope");
        let mut descoped = entry(EntityId::file(1), "Report.txt");
        descoped.status = LocalStatus::OutOfScope;
        f.store.put_entry(&descoped).unwrap();
        f.store
            .put_entry(&entry(EntityId::file(2), "report.txt"))
            .unwrap();

        let out = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();
        assert!(out.unsyncable.is_empty());
        assert_eq!(
            f.store
                .get_entry(EntityId::file(1))
                .unwrap()
                .unwrap()
                .status,
            LocalStatus::OutOfScope
        );
    }

    #[test]
    fn siblings_in_different_folders_do_not_compete() {
        let f = fixture("folders");
        let mut folder = entry(EntityId::folder(9), "Sub");
        folder.status = LocalStatus::Synced;
        folder.synced_placement = Some(folder.remote.clone());
        f.store.put_entry(&folder).unwrap();

        f.store
            .put_entry(&entry(EntityId::file(1), "Report.txt"))
            .unwrap();
        let mut nested = entry(EntityId::file(2), "report.txt");
        nested.remote.parent = Some(9);
        f.store.put_entry(&nested).unwrap();

        let out = apply_naming(&env(&f.store), &Personality::macos(), 10).unwrap();
        assert!(out.unsyncable.is_empty());
    }

    #[test]
    fn resolution_is_the_same_on_two_runs_over_the_same_state() {
        // Two devices, and the same device after a state rebuild, must agree
        // about which sibling is real.
        let f = fixture("stable");
        for (id, name) in [(1, "A.txt"), (2, "a.txt"), (3, "a.TXT")] {
            f.store.put_entry(&entry(EntityId::file(id), name)).unwrap();
        }
        let first = apply_naming(&env(&f.store), &Personality::windows(), 10).unwrap();

        let g = fixture("stable2");
        for (id, name) in [(3, "a.TXT"), (1, "A.txt"), (2, "a.txt")] {
            g.store.put_entry(&entry(EntityId::file(id), name)).unwrap();
        }
        let second = apply_naming(&env(&g.store), &Personality::windows(), 10).unwrap();

        assert_eq!(first.unsyncable, second.unsyncable);
        assert_eq!(
            first
                .unsyncable
                .iter()
                .map(|(id, _)| *id)
                .collect::<Vec<_>>(),
            vec![EntityId::file(2), EntityId::file(3)],
            "the lowest server id wins, whatever order they arrived in"
        );
    }

    // ---- encrypted entries -------------------------------------------------

    /// An encrypted file with `wrapped`, as the server would send it: a
    /// placeholder name, because the real one is inside the metadata blob.
    fn encrypted_file(id: i64, wrapped: Option<&str>) -> Entry {
        let mut e = entry(EntityId::file(id), &format!("encrypted-file-{id}"));
        e.is_encrypted = true;
        e.wrapped_file_key = wrapped.map(str::to_string);
        e
    }

    fn status_of(store: &Store, id: EntityId) -> LocalStatus {
        store.get_entry(id).unwrap().unwrap().status
    }

    #[test]
    fn an_encrypted_file_on_a_device_with_no_key_waits_for_one() {
        // Not a naming problem and not a defect. This is what every device
        // looks like before its user turns on encrypted folders, and the only
        // thing that fixes it happens somewhere else.
        let f = fixture("enc-nokey");
        f.store.put_entry(&encrypted_file(1, None)).unwrap();
        f.store
            .put_entry(&encrypted_file(2, Some("a grant this device cannot use")))
            .unwrap();

        let out = apply_naming(&env(&f.store), &Personality::linux(), 10).unwrap();

        assert_eq!(
            status_of(&f.store, EntityId::file(1)),
            LocalStatus::PendingKey
        );
        assert_eq!(
            status_of(&f.store, EntityId::file(2)),
            LocalStatus::PendingKey,
            "a grant is worth nothing without the vault key it was sealed to"
        );
        assert!(
            out.unsyncable.is_empty(),
            "waiting for a key is not something to alert about, per file, forever"
        );
    }

    #[test]
    fn an_encrypted_file_whose_grant_has_not_arrived_waits_for_it() {
        // The device can read encrypted folders; this particular file has not
        // been granted to it yet. Common while somebody is still sharing.
        let f = fixture("enc-nogrant");
        let (vault, _) = a_vault();
        f.store.put_entry(&encrypted_file(1, None)).unwrap();

        apply_naming(&env_with_vault(&f.store, &vault), &Personality::linux(), 10).unwrap();

        assert_eq!(
            status_of(&f.store, EntityId::file(1)),
            LocalStatus::PendingKey
        );
    }

    #[test]
    fn an_encrypted_file_this_device_can_open_gets_an_ordinary_name_verdict() {
        // Once the grant is held and the metadata has been read, the file is a
        // file: its DECRYPTED name is what has to fit on this disk and what a
        // case clash would be about. Nothing about it is special any more.
        let f = fixture("enc-grantable");
        let (vault, public) = a_vault();
        let grant =
            jd_crypto::drive::wrap_file_key_to(&jd_crypto::drive::FileKey::generate(), &public)
                .unwrap();
        let mut e = encrypted_file(1, Some(&grant));
        e.remote.name = "Quarterly plan.docx".into();
        e.content_id = Some("cid-1".into());
        f.store.put_entry(&e).unwrap();

        let out =
            apply_naming(&env_with_vault(&f.store, &vault), &Personality::linux(), 10).unwrap();

        assert_eq!(
            status_of(&f.store, EntityId::file(1)),
            LocalStatus::PendingDownload,
            "it is ready to be fetched, not waiting and not refused"
        );
        assert!(out.unsyncable.is_empty());
    }

    #[test]
    fn a_grant_whose_metadata_never_opened_leaves_the_file_waiting() {
        // A grant is not enough on its own. If the blob it unlocks could not be
        // read, the entry is still holding the SERVER's placeholder name, and
        // materializing that is the exact failure the design exists to avoid.
        let f = fixture("enc-nometa");
        let (vault, public) = a_vault();
        let grant =
            jd_crypto::drive::wrap_file_key_to(&jd_crypto::drive::FileKey::generate(), &public)
                .unwrap();
        let mut e = encrypted_file(1, Some(&grant));
        e.content_id = None;
        f.store.put_entry(&e).unwrap();

        apply_naming(&env_with_vault(&f.store, &vault), &Personality::linux(), 10).unwrap();

        assert_eq!(
            status_of(&f.store, EntityId::file(1)),
            LocalStatus::PendingKey
        );
    }

    #[test]
    fn an_encrypted_folder_materializes_only_where_its_contents_could_be_encrypted() {
        // The half that leaks. A materialized encrypted folder is an ordinary
        // directory to the user, so the next thing they drop into it goes
        // straight up — and on a device that cannot encrypt, it goes up in the
        // clear, into a folder they were told was private. Holding the folder
        // off THAT disk is what makes it impossible rather than unlikely.
        let (vault, _) = a_vault();
        for with_key in [false, true] {
            let f = fixture(if with_key { "enc-dir-key" } else { "enc-dir" });
            let mut folder = entry(EntityId::folder(1), "Private");
            folder.is_encrypted = true;
            f.store.put_entry(&folder).unwrap();

            let e = env(&f.store);
            let with_vault = env_with_vault(&f.store, &vault);
            apply_naming(
                if with_key { &with_vault } else { &e },
                &Personality::linux(),
                10,
            )
            .unwrap();

            let status = status_of(&f.store, EntityId::folder(1));
            if with_key {
                assert_ne!(
                    status,
                    LocalStatus::PendingKey,
                    "a device that can encrypt may hold the folder"
                );
            } else {
                assert_eq!(
                    status,
                    LocalStatus::PendingKey,
                    "a device that cannot encrypt must not hold the folder"
                );
            }
        }
    }

    #[test]
    fn a_file_created_locally_inside_a_vault_is_not_kept_waiting_for_a_grant() {
        // It has no grant because it does not exist on the server yet — this
        // device mints its key when it uploads. Treating that as "waiting for a
        // key" would mean a file dropped into a vault folder never leaves the
        // computer.
        let f = fixture("enc-local-new");
        let (vault, _) = a_vault();
        let mut e = entry(EntityId::file(-3), "notes.txt");
        e.is_encrypted = true;
        e.status = LocalStatus::PendingUpload;
        f.store.put_entry(&e).unwrap();

        apply_naming(&env_with_vault(&f.store, &vault), &Personality::linux(), 10).unwrap();

        assert_eq!(
            status_of(&f.store, EntityId::file(-3)),
            LocalStatus::PendingUpload
        );
    }

    #[test]
    fn a_grant_arriving_moves_a_waiting_file_off_the_waiting_list() {
        // The recovery direction. A file that waited for a key and then got one
        // has to leave PendingKey by itself — a status nothing ever clears is a
        // file that never syncs again.
        let f = fixture("enc-grant-arrives");
        let (vault, public) = a_vault();
        f.store.put_entry(&encrypted_file(1, None)).unwrap();
        let env = env_with_vault(&f.store, &vault);
        apply_naming(&env, &Personality::linux(), 10).unwrap();
        assert_eq!(
            status_of(&f.store, EntityId::file(1)),
            LocalStatus::PendingKey
        );

        let grant =
            jd_crypto::drive::wrap_file_key_to(&jd_crypto::drive::FileKey::generate(), &public)
                .unwrap();
        let mut e = f.store.get_entry(EntityId::file(1)).unwrap().unwrap();
        e.wrapped_file_key = Some(grant);
        e.content_id = Some("cid-1".into());
        f.store.put_entry(&e).unwrap();

        apply_naming(&env, &Personality::linux(), 10).unwrap();
        assert_ne!(
            status_of(&f.store, EntityId::file(1)),
            LocalStatus::PendingKey,
            "the key arrived; it is not waiting for one any more"
        );
    }
    // ---- two encrypted files with one name ---------------------------------

    fn vault_file(id: i64, name: &str) -> Entry {
        let mut e = entry(
            EntityId {
                entity_type: EntityType::File,
                server_id: id,
            },
            name,
        );
        e.is_encrypted = true;
        e
    }

    fn losers(entries: &[Entry]) -> Vec<(i64, String)> {
        let names: Vec<String> = entries.iter().map(|e| e.remote.name.clone()).collect();
        let p = Personality::linux();
        let resolved = jd_vfs::resolve_siblings(&names, &p);
        let pairs: Vec<(Entry, jd_vfs::Resolved)> =
            entries.iter().cloned().zip(resolved).collect();
        let mut out: Vec<(i64, String)> = duplicate_losers(&pairs, &p)
            .into_iter()
            .map(|(id, name)| (id.server_id, name))
            .collect();
        out.sort();
        out
    }

    #[test]
    fn the_lower_server_id_keeps_a_duplicated_name() {
        let got = losers(&[vault_file(7, "report.txt"), vault_file(9, "report.txt")]);
        assert_eq!(got, vec![(9, "report (2).txt".to_string())]);
    }

    #[test]
    fn who_gives_up_the_name_does_not_depend_on_the_order_they_are_seen_in() {
        // The whole reason the rule is the server id. Resolution order ranks
        // materialized entries first, so a file already on disk here and not on
        // the other computer would be the winner here and the loser there --
        // each device renaming the other's file, forever, neither ever holding
        // still. The id is the same number on every computer.
        let mut here = vault_file(9, "report.txt");
        here.synced_placement = Some(here.remote.clone());
        let there = vault_file(7, "report.txt");
        assert_eq!(
            losers(&[here.clone(), there.clone()]),
            losers(&[there, here]),
            "both computers have to rename the same file"
        );
    }

    #[test]
    fn a_third_file_with_the_same_name_gets_the_next_number() {
        let got = losers(&[
            vault_file(7, "report.txt"),
            vault_file(9, "report.txt"),
            vault_file(11, "report.txt"),
        ]);
        assert_eq!(
            got,
            vec![
                (9, "report (2).txt".to_string()),
                (11, "report (3).txt".to_string()),
            ]
        );
    }

    #[test]
    fn a_number_already_taken_by_a_sibling_is_skipped() {
        let got = losers(&[
            vault_file(7, "report.txt"),
            vault_file(9, "report.txt"),
            vault_file(11, "report (2).txt"),
        ]);
        assert_eq!(got, vec![(9, "report (3).txt".to_string())]);
    }

    #[test]
    fn a_file_the_server_has_never_seen_is_the_one_that_gives_way() {
        // It has no id to compare and nobody has been told about it, so
        // renaming it costs nothing and disturbs nothing.
        let mut fresh = vault_file(-4, "report.txt");
        fresh.id.server_id = -4;
        let known = vault_file(9, "report.txt");
        let got = losers(&[fresh, known]);
        assert_eq!(got, vec![(-4, "report (2).txt".to_string())]);
    }

    #[test]
    fn a_plaintext_duplicate_is_left_alone() {
        // The server enforces uniqueness on a plaintext title, so two of them
        // cannot exist. Renaming on the strength of one would mean acting on a
        // state the server says is impossible -- and the name resolver's
        // ordinary clash reporting is the right answer if it somehow does.
        let mut a = vault_file(7, "report.txt");
        let mut b = vault_file(9, "report.txt");
        a.is_encrypted = false;
        b.is_encrypted = false;
        assert!(losers(&[a, b]).is_empty());
    }

    #[test]
    fn two_different_names_are_not_a_duplicate() {
        assert!(losers(&[vault_file(7, "a.txt"), vault_file(9, "b.txt")]).is_empty());
    }

}
