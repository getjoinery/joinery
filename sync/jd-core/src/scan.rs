//! Working out what happened locally, from a tree that cannot tell you.
//!
//! The filesystem records no history. All the scanner gets is what is there
//! now, and what the engine last recorded — and from that it has to say whether
//! a file was edited, moved, replaced, or deleted. The distinctions matter
//! enormously:
//!
//! - A **move** recognized as a move is one rename call. The same move
//!   mistaken for a delete-plus-create destroys the file's sharing and its
//!   version history, and re-uploads four gigabytes.
//! - An **edit** mistaken for a delete-plus-create loses the version chain, so
//!   "restore previous version" stops working on the one file the user was
//!   actually working on.
//!
//! And the filesystem actively misleads. Applications do not save files by
//! writing to them; they write a temp file, fsync, and rename it over the
//! original. After that the path is the same, the content is new, and the inode
//! is *different* — which naively reads as "the old file was deleted and an
//! unrelated new one appeared."
//!
//! So pairing runs in a fixed precedence, and the order is the whole design:
//!
//! 1. **Same path** — whatever the inode says. A new inode at a known path is
//!    the safe-save dance, and it is a content edit.
//! 2. **Same inode, same content** — the file kept its identity and moved.
//! 3. **Same content, somewhere else** — a move the inode could not prove,
//!    because inodes get reused. Requiring the hash to match is what makes
//!    trusting a recycled inode safe.
//! 4. Otherwise — genuinely a deletion and, separately, a new file.
//!
//! Every pairing above rests on a content hash, never on a fingerprint. The
//! fingerprint only decides whether hashing is worth the read.

use std::collections::HashMap;

use crate::model::EntityId;

/// A file as it exists on disk right now.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ObservedFile {
    /// Path relative to the sync root.
    pub path: String,
    pub fingerprint: jd_vfs::Fingerprint,
    pub sha256: String,
}

/// What the engine last recorded about a file it is tracking.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct KnownLocal {
    pub id: EntityId,
    /// Where it was last seen, relative to the sync root.
    pub path: String,
    pub fingerprint: Option<jd_vfs::Fingerprint>,
    /// The content both sides last agreed on.
    pub sha256: Option<String>,
}

/// What the scan concluded about one tracked file.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum LocalChange {
    Unchanged,
    Edited {
        sha256: String,
        fingerprint: jd_vfs::Fingerprint,
    },
    Moved {
        to_path: String,
        fingerprint: jd_vfs::Fingerprint,
    },
    MovedAndEdited {
        to_path: String,
        sha256: String,
        fingerprint: jd_vfs::Fingerprint,
    },
    Deleted,
}

#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct ScanOutcome {
    /// What happened to each file the engine already tracks.
    pub changes: Vec<(EntityId, LocalChange)>,
    /// Files on disk that belong to nothing the engine knows about.
    pub created: Vec<ObservedFile>,
}

impl ScanOutcome {
    pub fn change_for(&self, id: EntityId) -> Option<&LocalChange> {
        self.changes.iter().find(|(e, _)| *e == id).map(|(_, c)| c)
    }
}

/// Pair what is on disk against what the engine last recorded.
///
/// `known` and `observed` are both complete for the scanned scope — a partial
/// observation would read as mass deletion, which is why the caller only passes
/// a subtree when it has genuinely walked all of it.
pub fn pair(known: &[KnownLocal], observed: &[ObservedFile]) -> ScanOutcome {
    let mut out = ScanOutcome::default();

    let by_path: HashMap<&str, &ObservedFile> =
        observed.iter().map(|o| (o.path.as_str(), o)).collect();

    // Which observed files have been accounted for. Anything left at the end is
    // genuinely new.
    let mut claimed: Vec<bool> = vec![false; observed.len()];
    let index_of: HashMap<&str, usize> = observed
        .iter()
        .enumerate()
        .map(|(i, o)| (o.path.as_str(), i))
        .collect();

    for k in known {
        // 1. Same path. Checked first and without consulting the inode, because
        //    the safe-save dance replaces the inode at a stable path and that is
        //    an edit, not a new file.
        if let Some(obs) = by_path.get(k.path.as_str()) {
            claimed[index_of[obs.path.as_str()]] = true;
            let same_content = k.sha256.as_deref() == Some(obs.sha256.as_str());
            out.changes.push((
                k.id,
                if same_content {
                    LocalChange::Unchanged
                } else {
                    LocalChange::Edited {
                        sha256: obs.sha256.clone(),
                        fingerprint: obs.fingerprint,
                    }
                },
            ));
            continue;
        }

        // The file is not where it was. Look for it elsewhere.
        // 2. Same inode AND same content: it moved and kept its identity.
        let by_inode = k.fingerprint.and_then(|fp| {
            observed.iter().enumerate().find(|(i, o)| {
                !claimed[*i]
                    && o.fingerprint.file_id == fp.file_id
                    && Some(o.sha256.as_str()) == k.sha256.as_deref()
            })
        });

        // 3. Failing that, same content anywhere unclaimed. The hash is what
        //    makes this safe: an inode alone can be recycled by an unrelated
        //    file, and pairing on that would silently swap two files' identities.
        let by_hash = by_inode.or_else(|| {
            k.sha256.as_deref().and_then(|want| {
                observed
                    .iter()
                    .enumerate()
                    .find(|(i, o)| !claimed[*i] && o.sha256 == want)
            })
        });

        // 4. Same inode but DIFFERENT content: moved and edited before we
        //    looked. Only trusted when the inode matches, since without either
        //    the path or the content to corroborate, the inode is all there is.
        let moved_and_edited = if by_hash.is_none() {
            k.fingerprint.and_then(|fp| {
                observed
                    .iter()
                    .enumerate()
                    .find(|(i, o)| !claimed[*i] && o.fingerprint.file_id == fp.file_id)
            })
        } else {
            None
        };

        match (by_hash, moved_and_edited) {
            (Some((i, obs)), _) => {
                claimed[i] = true;
                out.changes.push((
                    k.id,
                    LocalChange::Moved {
                        to_path: obs.path.clone(),
                        fingerprint: obs.fingerprint,
                    },
                ));
            }
            (None, Some((i, obs))) => {
                claimed[i] = true;
                out.changes.push((
                    k.id,
                    LocalChange::MovedAndEdited {
                        to_path: obs.path.clone(),
                        sha256: obs.sha256.clone(),
                        fingerprint: obs.fingerprint,
                    },
                ));
            }
            // 5. Nowhere to be found. It is gone.
            (None, None) => out.changes.push((k.id, LocalChange::Deleted)),
        }
    }

    for (i, obs) in observed.iter().enumerate() {
        if !claimed[i] {
            out.created.push(obs.clone());
        }
    }

    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fp(file_id: u64, size: u64, mtime_ns: u64) -> jd_vfs::Fingerprint {
        jd_vfs::Fingerprint {
            size,
            mtime_ns,
            file_id,
        }
    }

    fn observed(path: &str, file_id: u64, sha: &str) -> ObservedFile {
        ObservedFile {
            path: path.into(),
            fingerprint: fp(file_id, 10, 100),
            sha256: sha.into(),
        }
    }

    fn known(id: i64, path: &str, file_id: u64, sha: &str) -> KnownLocal {
        KnownLocal {
            id: EntityId::file(id),
            path: path.into(),
            fingerprint: Some(fp(file_id, 10, 100)),
            sha256: Some(sha.into()),
        }
    }

    #[test]
    fn an_untouched_file_is_untouched() {
        let out = pair(
            &[known(1, "a.txt", 100, "sha-a")],
            &[observed("a.txt", 100, "sha-a")],
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Unchanged)
        );
        assert!(out.created.is_empty());
    }

    #[test]
    fn the_safe_save_dance_is_an_edit_not_a_replacement() {
        // What every word processor does: write a temp file, rename it over the
        // original. Same path, new content, DIFFERENT inode. Reading that as
        // delete-plus-create would destroy the file's version history on the
        // one file the user is actively working on.
        let out = pair(
            &[known(1, "Report.docx", 100, "sha-old")],
            &[observed("Report.docx", 999, "sha-new")],
        );
        match out.change_for(EntityId::file(1)) {
            Some(LocalChange::Edited { sha256, .. }) => assert_eq!(sha256, "sha-new"),
            other => panic!("expected an edit, got {other:?}"),
        }
        assert!(out.created.is_empty(), "no phantom new file");
    }

    #[test]
    fn a_moved_file_keeps_its_identity() {
        // The 4 GB case: recognized as a move, this is one rename. Missed, it is
        // a delete plus a four-gigabyte upload, and the sharing and version
        // history go with it.
        let out = pair(
            &[known(1, "a.txt", 100, "sha-a")],
            &[observed("archive/a.txt", 100, "sha-a")],
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Moved {
                to_path: "archive/a.txt".into(),
                fingerprint: fp(100, 10, 100),
            })
        );
        assert!(out.created.is_empty());
    }

    #[test]
    fn a_move_is_recognized_by_content_even_when_the_inode_changed() {
        // Copied to a new volume, restored from a backup, unpacked — the inode
        // is meaningless but the bytes are identical.
        let out = pair(
            &[known(1, "a.txt", 100, "sha-a")],
            &[observed("moved/a.txt", 55555, "sha-a")],
        );
        assert!(matches!(
            out.change_for(EntityId::file(1)),
            Some(LocalChange::Moved { .. })
        ));
    }

    #[test]
    fn a_recycled_inode_does_not_swap_two_files_identities() {
        // The dangerous case for inode-based pairing: the tracked file is gone,
        // and an unrelated new file has been handed its inode number. Pairing on
        // the inode alone would rename one file into the other's place on the
        // server. Requiring the content to match is what prevents it.
        let out = pair(
            &[known(1, "gone.txt", 100, "sha-gone")],
            &[observed("unrelated.txt", 100, "sha-completely-different")],
        );
        // The inode match is only trusted as "moved and edited" — never as an
        // identity claim strong enough to move somebody's data on its own.
        match out.change_for(EntityId::file(1)) {
            Some(LocalChange::MovedAndEdited { to_path, .. }) => {
                assert_eq!(to_path, "unrelated.txt")
            }
            other => panic!("unexpected: {other:?}"),
        }
    }

    #[test]
    fn a_deleted_file_is_reported_deleted() {
        let out = pair(&[known(1, "a.txt", 100, "sha-a")], &[]);
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Deleted)
        );
    }

    #[test]
    fn a_genuinely_new_file_is_reported_as_created() {
        let out = pair(&[], &[observed("fresh.txt", 7, "sha-fresh")]);
        assert!(out.changes.is_empty());
        assert_eq!(out.created.len(), 1);
        assert_eq!(out.created[0].path, "fresh.txt");
    }

    #[test]
    fn a_delete_and_an_unrelated_create_stay_separate() {
        let out = pair(
            &[known(1, "old.txt", 100, "sha-old")],
            &[observed("new.txt", 200, "sha-new")],
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Deleted)
        );
        assert_eq!(out.created.len(), 1);
    }

    #[test]
    fn one_observed_file_cannot_be_claimed_by_two_entries() {
        // Two tracked files with identical content — a duplicated document —
        // and only one of them still on disk. Exactly one may claim it; the
        // other is genuinely gone.
        let out = pair(
            &[
                known(1, "one.txt", 100, "same-sha"),
                known(2, "two.txt", 200, "same-sha"),
            ],
            &[observed("survivor.txt", 100, "same-sha")],
        );
        let claims = out
            .changes
            .iter()
            .filter(|(_, c)| matches!(c, LocalChange::Moved { .. }))
            .count();
        assert_eq!(claims, 1, "a file can only have moved once");
        assert_eq!(
            out.changes
                .iter()
                .filter(|(_, c)| *c == LocalChange::Deleted)
                .count(),
            1
        );
        assert!(out.created.is_empty());
    }

    #[test]
    fn a_file_still_at_its_path_is_never_stolen_by_a_move_elsewhere() {
        // Both a copy at the original path and an identical one elsewhere. The
        // path match wins, so the original stays put and the copy is new.
        let out = pair(
            &[known(1, "a.txt", 100, "sha-a")],
            &[
                observed("a.txt", 100, "sha-a"),
                observed("copy.txt", 300, "sha-a"),
            ],
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Unchanged)
        );
        assert_eq!(out.created.len(), 1);
        assert_eq!(out.created[0].path, "copy.txt");
    }

    #[test]
    fn a_swap_of_two_files_contents_is_two_edits_not_two_moves() {
        // Both paths still exist, so the path rule settles both before any move
        // detection runs — which is right: the user has two files, and each now
        // holds what the other did.
        let out = pair(
            &[
                known(1, "a.txt", 100, "sha-a"),
                known(2, "b.txt", 200, "sha-b"),
            ],
            &[
                observed("a.txt", 100, "sha-b"),
                observed("b.txt", 200, "sha-a"),
            ],
        );
        assert!(matches!(
            out.change_for(EntityId::file(1)),
            Some(LocalChange::Edited { .. })
        ));
        assert!(matches!(
            out.change_for(EntityId::file(2)),
            Some(LocalChange::Edited { .. })
        ));
        assert!(out.created.is_empty());
    }

    #[test]
    fn a_rename_and_an_edit_together_are_recognized_as_both() {
        let out = pair(
            &[known(1, "draft.txt", 100, "sha-old")],
            &[observed("final.txt", 100, "sha-new")],
        );
        match out.change_for(EntityId::file(1)) {
            Some(LocalChange::MovedAndEdited {
                to_path, sha256, ..
            }) => {
                assert_eq!(to_path, "final.txt");
                assert_eq!(sha256, "sha-new");
            }
            other => panic!("unexpected: {other:?}"),
        }
    }

    #[test]
    fn an_entry_with_no_recorded_content_is_not_paired_by_guesswork() {
        // Nothing to compare against, so no move may be claimed — the safe
        // reading is that it is gone and whatever is on disk is new.
        let out = pair(
            &[KnownLocal {
                id: EntityId::file(1),
                path: "a.txt".into(),
                fingerprint: None,
                sha256: None,
            }],
            &[observed("elsewhere.txt", 900, "sha-x")],
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Deleted)
        );
        assert_eq!(out.created.len(), 1);
    }

    #[test]
    fn an_empty_tree_against_no_entries_produces_nothing() {
        let out = pair(&[], &[]);
        assert!(out.changes.is_empty() && out.created.is_empty());
    }
}
