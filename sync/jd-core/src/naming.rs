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
use crate::model::{EntityId, EntityType, Entry, LocalStatus};

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
}

impl NamingOutcome {
    pub fn is_empty(&self) -> bool {
        self.escaped == 0 && self.unsyncable.is_empty() && self.recovered.is_empty()
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
    // is actually competing with.
    let mut by_parent: HashMap<Option<i64>, Vec<Entry>> = HashMap::new();
    for entry in crate::pass::all_entries(env)? {
        // Out of scope is a deliberate absence, and something the server has
        // already deleted is on its way out. Neither should hold a slot against
        // a sibling that wants to exist.
        if entry.status == LocalStatus::OutOfScope || entry.remote_deleted {
            continue;
        }
        by_parent
            .entry(entry.local_placement().parent)
            .or_default()
            .push(entry);
    }

    // Folder paths, for the total-path-length check. Computed once from the
    // names as they stand; a folder whose own name changes this pass shifts its
    // children's lengths by a byte or two, which the next pass settles.
    let folder_paths = folder_path_lengths(env)?;

    for (parent, mut siblings) in by_parent {
        siblings.sort_by_key(resolution_order);
        let names: Vec<String> = siblings
            .iter()
            .map(|e| e.local_placement().name.clone())
            .collect();
        let resolved = jd_vfs::resolve_siblings(&names, personality);

        let parent_len = parent
            .and_then(|id| folder_paths.get(&id).copied())
            .map(|len| len + 1) // the separator
            .unwrap_or(0);

        for (entry, r) in siblings.into_iter().zip(resolved) {
            let (local_name, verdict) = match r.outcome {
                LocalName::AsIs(name) => {
                    // Equal to the server's spelling in the common case. Not
                    // equal when the server holds a decomposed name and this
                    // filesystem wants the composed one, which is a mapping like
                    // any other and has to be recorded as one.
                    let mapped = (name != entry.local_placement().name).then_some(name);
                    (mapped, None)
                }
                LocalName::Escaped { local, .. } => {
                    out.escaped += 1;
                    (Some(local), None)
                }
                LocalName::Unsyncable(reason) => (entry.local_name.clone(), Some(reason)),
            };

            // Length is checked against the resolved name, because escaping
            // makes names longer — a colon becomes three characters — and a name
            // that only just fitted may not any more.
            let verdict = verdict.or_else(|| {
                let name_len = local_name
                    .as_deref()
                    .unwrap_or(&entry.local_placement().name)
                    .len();
                let total = parent_len + name_len;
                (!jd_vfs::path_fits(total, root_prefix_bytes, personality)).then(|| {
                    UnsyncableReason::PathTooLong {
                        bytes: total + root_prefix_bytes + 1,
                        limit: personality.max_path_bytes,
                    }
                })
            });

            let was_unsyncable = matches!(entry.status, LocalStatus::Unsyncable(_));
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
                None if was_unsyncable => {
                    // The clash cleared. Whether it can be synced now is not
                    // this layer's call — it says only that the name is usable
                    // again and hands the entry back to the ordinary path, which
                    // will work out from the agreement what needs to happen.
                    updated.status = if entry.synced_placement.is_some() {
                        LocalStatus::Synced
                    } else {
                        LocalStatus::PendingDownload
                    };
                    out.recovered.push(entry.id);
                }
                None => {}
            }

            if updated != entry {
                env.store.put_entry(&updated)?;
            }
        }
    }

    Ok(out)
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
fn resolution_order(e: &Entry) -> (u8, i64) {
    let materialized =
        e.synced_placement.is_some() || e.synced_fingerprint.is_some() || e.id.is_provisional();
    (if materialized { 0 } else { 1 }, e.id.server_id)
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
            conflict_name: &|n: &str| n.to_string(),
        }
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
}
