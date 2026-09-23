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

use std::collections::{HashMap, HashSet};

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
    /// The server has deleted this, and its record keeps a local path only as a
    /// memory of where it used to be. Matters when a live entry is sitting at
    /// that same path: the file there is the live one's, and this entry has
    /// nothing on this disk at all.
    pub server_deleted: bool,
    /// The bytes are known to stand somewhere else on this disk: a claimant
    /// waiting for a vault key holds them, and this record is the source it
    /// replaces. Its path then proves nothing -- a file standing there is
    /// the source only if it is the same inode brought back, and otherwise
    /// a stranger the user saved under the old name.
    pub held: bool,
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
    pair_with(known, observed, &HashSet::new())
}

/// `pair`, told which paths belong to live records with nothing of theirs on
/// this disk yet (see `awaiting_bytes` in rule 1). `known` leaves those
/// records out -- there is no local file to have moved away from -- so this
/// is the only way rule 1 can see that such a path is somebody's.
pub fn pair_with(
    known: &[KnownLocal],
    observed: &[ObservedFile],
    awaiting_bytes: &HashSet<String>,
) -> ScanOutcome {
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

    // 1. Same path, for every entry, before anybody is allowed to go looking
    //    elsewhere. Checked without consulting the inode, because the safe-save
    //    dance replaces the inode at a stable path and that is an edit, not a
    //    new file.
    //
    //    Doing the whole of this step first is what makes it a precedence rather
    //    than a preference. Interleaved with the search rules it was only the
    //    first rule *per entry*, so an entry that thought it had moved somewhere
    //    could reach that path by content and claim it before the entry actually
    //    recorded as living there ever got its turn. The file is then read as
    //    two things at once: a move by one entry and, on the next pass, still
    //    the other's. The move is refused — the server will not put two live
    //    files under one name — and the record that provoked it is untouched, so
    //    the next pass plans exactly the same thing. No error, no queued work,
    //    no end.
    //
    //    A path can still be wanted by two entries, and only one file is there:
    //    a case-twin on a filesystem that cannot tell them apart, or an entry
    //    the server has deleted whose old spot a live one now occupies. What the
    //    loser gets said about it is the careful part, and it is not "look for
    //    it somewhere else" — hunting by hash from here would let it pair with
    //    an unrelated file and drag two identities into one another. An entry
    //    the server has deleted has nothing on this disk, so it is gone and can
    //    be let go of. A live one is a naming problem, which naming already
    //    handles, so the scan says nothing changed rather than inventing a
    //    deletion that would take the file off the server.
    // What rule 1 asks when the bytes at a record's path are not its own:
    // who holds which inode, which agreed content, and which path.
    let live = |k: &KnownLocal| !k.server_deleted;
    let nonzero = |id: u64| id != 0;
    let mut inode_owners: HashMap<u64, Vec<usize>> = HashMap::new();
    let mut sha_owners: HashMap<&str, Vec<usize>> = HashMap::new();
    let mut record_at: HashMap<&str, Vec<usize>> = HashMap::new();
    for (n, k) in known.iter().enumerate().filter(|(_, k)| live(k)) {
        if let Some(id) = k.fingerprint.map(|f| f.file_id).filter(|id| nonzero(*id)) {
            inode_owners.entry(id).or_default().push(n);
        }
        if let Some(sha) = k.sha256.as_deref() {
            sha_owners.entry(sha).or_default().push(n);
        }
        record_at.entry(k.path.as_str()).or_default().push(n);
    }
    let mut observed_by_inode: HashMap<u64, Vec<&ObservedFile>> = HashMap::new();
    let mut observed_by_sha: HashMap<&str, Vec<&ObservedFile>> = HashMap::new();
    for o in observed {
        if nonzero(o.fingerprint.file_id) {
            observed_by_inode.entry(o.fingerprint.file_id).or_default().push(o);
        }
        observed_by_sha.entry(o.sha256.as_str()).or_default().push(o);
    }
    // A record is at home when the file standing at its own path is its own:
    // its inode, or -- with no inode recorded -- its agreed bytes.
    let at_home = |r: &KnownLocal| {
        by_path.get(r.path.as_str()).is_some_and(|o| match r.fingerprint {
            Some(f) if nonzero(f.file_id) => f.file_id == o.fingerprint.file_id,
            _ => r.sha256.as_deref() == Some(o.sha256.as_str()),
        })
    };
    // Is the file at this record's path another file that arrived by a name
    // trade, rather than this record's own file saved again?
    //
    // Rule 1 reads a path without the inode because a save replaces the inode
    // at a stable path. A name trade does too, and reading it the same way
    // told each record the other's bytes were its edit: two new versions, and
    // two version histories each holding the other file's past (Defect AI; in
    // a vault, Defect AH). Three things together separate the trade from a
    // save, and each alone does not:
    //
    // - the bytes here are not the ones this record agreed on;
    // - this record's OWN file still stands on this disk under another name
    //   (a write-temp-rename-over save leaves it gone); and
    // - the file here is one the store already knows as ANOTHER record's that
    //   is not at home -- or this record's own file now stands at another
    //   record's path and that record is not at home.
    //
    // The third is what keeps a backup-by-rename save an edit. Emacs and vim
    // rename the original to `notes.txt~` and write a new `notes.txt`: the
    // original is still here, as in a trade, but what stands at the name is a
    // never-seen inode with never-seen bytes, and the backup lands at a path
    // no record holds. A hardlinked twin whose other name was safe-saved has
    // its other record at home. Bytes equal to another record's content count
    // only when non-empty, held by exactly one live record, and that record is
    // not at home -- an empty file or a template matches files that never
    // moved. A zero file id is no identity (a Windows handle that would not
    // open) and never counts on either side.
    //
    // What a trade read this way costs, stated: a file edited and then traded
    // has no agreed bytes left to be recognised by, and reads as deleted plus
    // a creation -- the version chain lost, no bytes lost. What the careful
    // form leaves: a trade whose file at this path the store cannot name
    // still reads as an edit, because from one scan it is the same disk as a
    // backup-by-rename save. So does a file renamed away with a NEW file
    // saved at its old name, unless the leaver's own file stands at another
    // record's path whose record is not at home -- then it is a move (a
    // rotation whose member at this path is new), and the server may refuse
    // the name while that record still holds it: the move then lands beside
    // under a conflict name, never dropped (the reset's C12, frozen 111120).
    // A hardlinked twin at home anywhere keeps the reading an edit (p8).
    //
    // A path held by a live record whose bytes have not landed here yet
    // (`awaiting_bytes`: a download still to come, or one the user saved over
    // as it landed) is another record's path whose record is not at home: it
    // has nothing on this disk to be at home with. Left out, a trade with such
    // a slot read as an edit and this record's own file was minted again as a
    // new one under the other name (the reset's T1, plain2 75292). A backup
    // made by renaming still reads as an edit: its backup lands on a path no
    // record holds.
    const EMPTY_SHA256: &str = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    let arrived_by_a_trade = |n: usize, k: &KnownLocal, obs: &ObservedFile| -> bool {
        if k.sha256.as_deref() == Some(obs.sha256.as_str()) {
            return false;
        }
        let standing = match k.fingerprint {
            Some(f) if nonzero(f.file_id) => observed_by_inode.get(&f.file_id),
            Some(_) => None,
            None => k
                .sha256
                .as_deref()
                .filter(|mine| *mine != EMPTY_SHA256)
                .and_then(|mine| observed_by_sha.get(mine)),
        };
        let mine_elsewhere: Vec<&ObservedFile> = standing
            .map(|os| os.iter().copied().filter(|o| o.path != k.path).collect())
            .unwrap_or_default();
        if mine_elsewhere.is_empty() {
            return false;
        }
        let another_not_at_home = |r: usize| r != n && !at_home(&known[r]);
        let by_inode = nonzero(obs.fingerprint.file_id)
            && inode_owners
                .get(&obs.fingerprint.file_id)
                .is_some_and(|rs| rs.iter().any(|r| another_not_at_home(*r)));
        let by_content = obs.sha256 != EMPTY_SHA256
            && sha_owners
                .get(obs.sha256.as_str())
                .is_some_and(|rs| rs.len() == 1 && another_not_at_home(rs[0]));
        // Not when my file also stands under a record at home with it: that
        // is a hardlinked twin, an edit by p2 whatever else is true (p8).
        let twin_at_home = mine_elsewhere.iter().any(|o| {
            record_at
                .get(o.path.as_str())
                .is_some_and(|rs| rs.iter().any(|r| *r != n && at_home(&known[*r])))
        });
        let mine_on_anothers_path = !twin_at_home
            && mine_elsewhere.iter().any(|o| {
                record_at
                    .get(o.path.as_str())
                    .is_some_and(|rs| rs.iter().any(|r| another_not_at_home(*r)))
                    || awaiting_bytes.contains(o.path.as_str())
            });
        by_inode || by_content || mine_on_anothers_path
    };

    let mut settled: Vec<bool> = vec![false; known.len()];
    for (n, k) in known.iter().enumerate() {
        let Some(obs) = by_path.get(k.path.as_str()) else {
            continue;
        };
        // A held record's bytes are somewhere else, so the path alone is not
        // it. Read by path, a stranger saved under the old name became the
        // held file edited: its bytes went up as a version of a file whose
        // real bytes were waiting in a vault this device cannot open, the
        // hold lapsed, and nothing said so. The inode brought back is the
        // file; anything else at the path is a creation. A held record with
        // no fingerprint -- its upload finished while the user was already
        // moving it -- has nothing to match and pairs by path with nobody;
        // the bytes brought back are still found by hash in the round below.
        if k.held && k.fingerprint.is_none_or(|fp| fp.file_id != obs.fingerprint.file_id) {
            continue;
        }
        if arrived_by_a_trade(n, k, obs) {
            continue;
        }
        let i = index_of[obs.path.as_str()];
        settled[n] = true;
        if claimed[i] {
            out.changes.push((
                k.id,
                if k.server_deleted {
                    LocalChange::Deleted
                } else {
                    LocalChange::Unchanged
                },
            ));
            continue;
        }
        claimed[i] = true;
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
    }

    for (n, k) in known.iter().enumerate() {
        if settled[n] {
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

        // 4. There is no fourth rule, and the reason is the doctrine this whole
        //    function turns on: a bare inode may fund ORDER -- a hold, a wait, a
        //    hint that costs only time when it is wrong -- but never IDENTITY: a
        //    name, a file's contents, a claim on somebody's data. Rule 3 above
        //    already says so; this is the same ruling applied twice.
        //
        //    There used to be one. Same inode, different content, read as moved
        //    and edited before we looked -- and its own comment admitted the
        //    inode was all there was. A real disk hands a deleted file's inode
        //    straight to the next file that wants one, so that rule bound a
        //    tracked entry to a stranger every time recycling beat the scan. It
        //    was wrong in both directions at once: applied, it renamed the entry
        //    onto the stranger's name and sent the stranger's bytes up over the
        //    entry's server content, poisoning the very version chain it existed
        //    to preserve; unapplied, its claim on the observed file stopped the
        //    stranger ever being adopted, and nothing owned those bytes again.
        //
        //    The price of not having it is known and chosen: a file both moved
        //    and edited between two scans reads as a delete plus a creation. The
        //    version chain is lost and no bytes are. Corruption against
        //    degradation is not a close call.
        let moved_and_edited: Option<(usize, &ObservedFile)> = None;

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
            server_deleted: false, held: false,
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
        // The tracked file is gone, and says so. Anything else is a claim on the
        // stranger: as a change it moves the entry onto the stranger's name, and
        // as a claim it stops the stranger being adopted by anyone else.
        match out.change_for(EntityId::file(1)) {
            Some(LocalChange::Deleted) => {}
            other => panic!("unexpected: {other:?}"),
        }
        // ...and the stranger is a new file, which is what it is.
        assert_eq!(
            out.created.iter().map(|o| o.path.as_str()).collect::<Vec<_>>(),
            vec!["unrelated.txt"],
            "the file that inherited the inode has to be adopted by somebody"
        );
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

    // Two files trading names, and the saves that look like one from a
    // single scan. The trade reads as two moves; every save still reads as an
    // edit. p1-p7 are the reviewers' probes (0e 2026-09-11, 25 2026-09-22).

    fn edited(out: &ScanOutcome, id: i64) -> bool {
        matches!(out.change_for(EntityId::file(id)), Some(LocalChange::Edited { .. }))
    }

    #[test]
    fn two_files_trading_names_are_two_moves_not_two_edits() {
        // p6 (Defect AI). a.txt and b.txt exchange names through a temp name:
        // each path now holds the other's inode and bytes. Read as edits, each
        // file's version history took the other's bytes.
        let out = pair(
            &[known(1, "a.txt", 100, "sha-a"), known(2, "b.txt", 200, "sha-b")],
            &[observed("a.txt", 200, "sha-b"), observed("b.txt", 100, "sha-a")],
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Moved { to_path: "b.txt".into(), fingerprint: fp(100, 10, 100) })
        );
        assert_eq!(
            out.change_for(EntityId::file(2)),
            Some(&LocalChange::Moved { to_path: "a.txt".into(), fingerprint: fp(200, 10, 100) })
        );
        assert!(out.created.is_empty());
    }

    #[test]
    fn a_backup_by_rename_save_is_an_edit() {
        // p1. Emacs, vim with backupcopy=no: the original is renamed to
        // notes.txt~ and a new notes.txt is written. The original is still on
        // the disk, as in a trade -- but what stands at the name is a new inode
        // with new bytes, and the backup is at a path no record holds.
        let out = pair(
            &[known(1, "notes.txt", 100, "sha-old")],
            &[observed("notes.txt~", 100, "sha-old"), observed("notes.txt", 101, "sha-new")],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
        // And the second save, which renames the first save over the backup.
        let out = pair(
            &[known(1, "notes.txt", 101, "sha-new")],
            &[observed("notes.txt~", 101, "sha-new"), observed("notes.txt", 102, "sha-newer")],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
    }

    #[test]
    fn a_safe_save_of_one_name_of_a_hardlinked_file_is_an_edit() {
        // p2. Two records on one inode; one name is safe-saved. Its inode still
        // stands under the other name -- whose record is at home there.
        let out = pair(
            &[known(1, "a.txt", 100, "sha-x"), known(2, "b.txt", 100, "sha-x")],
            &[observed("a.txt", 101, "sha-y"), observed("b.txt", 100, "sha-x")],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
        assert_eq!(out.change_for(EntityId::file(2)), Some(&LocalChange::Unchanged));
    }

    #[test]
    fn a_zero_file_id_is_no_identity() {
        // p3. A Windows handle that would not open records file id 0, so every
        // such file "shares" an inode with every other.
        let out = pair(
            &[known(1, "a.txt", 0, "sha-x"), known(2, "b.txt", 0, "sha-y")],
            &[observed("a.txt", 0, "sha-z"), observed("b.txt", 0, "sha-y")],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
    }

    #[test]
    fn an_edit_beside_a_copy_of_the_old_bytes_is_an_edit() {
        // p4/p5. A record with no inode recorded (its upload finished mid-move)
        // is edited while a copy of its old bytes exists: untracked (p4) or
        // synced as another record at home (p5).
        let bare = KnownLocal {
            id: EntityId::file(1),
            path: "a.txt".into(),
            fingerprint: None,
            sha256: Some("sha-x".into()),
            server_deleted: false,
            held: false,
        };
        let out = pair(
            &[bare.clone()],
            &[observed("a.txt", 101, "sha-y"), observed("copy.txt", 300, "sha-x")],
        );
        assert!(edited(&out, 1), "p4: {:?}", out.change_for(EntityId::file(1)));
        let out = pair(
            &[bare, known(2, "copy.txt", 300, "sha-x")],
            &[observed("a.txt", 101, "sha-y"), observed("copy.txt", 300, "sha-x")],
        );
        assert!(edited(&out, 1), "p5: {:?}", out.change_for(EntityId::file(1)));
    }

    #[test]
    fn a_backup_by_rename_save_matching_another_files_content_is_an_edit() {
        // p7. The new notes.txt holds bytes another record also holds -- a
        // template, a pasted copy -- and that record is at home. Equal bytes
        // are not a trade.
        let out = pair(
            &[known(1, "notes.txt", 100, "sha-old"), known(2, "other.txt", 200, "sha-t")],
            &[
                observed("notes.txt~", 100, "sha-old"),
                observed("notes.txt", 101, "sha-t"),
                observed("other.txt", 200, "sha-t"),
            ],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
        assert_eq!(out.change_for(EntityId::file(2)), Some(&LocalChange::Unchanged));
    }

    #[test]
    fn a_twin_at_home_beside_a_record_not_at_home_is_still_an_edit() {
        // p8. My inode stands under two other names: one whose record is at
        // home with it (a hardlinked twin), one whose record is not at home.
        // What stands at my path is a stranger. Nothing here is a trade.
        let out = pair(
            &[
                known(1, "a.txt", 100, "sha-a"),
                known(2, "b.txt", 100, "sha-a"),
                known(3, "c.txt", 400, "sha-c"),
            ],
            &[
                observed("a.txt", 300, "sha-new"),
                observed("b.txt", 100, "sha-a"),
                observed("c.txt", 100, "sha-a"),
                observed("d.txt", 400, "sha-c"),
            ],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
    }

    #[test]
    fn a_move_the_scan_cannot_confirm_reads_as_a_delete_plus_a_create() {
        // The price of refusing to pair on a bare inode, recorded rather than
        // discovered. A file renamed AND edited between two scans has neither
        // its path nor its content left to recognise it by, and an inode alone
        // cannot tell this apart from a stranger that inherited the number. So
        // it is let go of and picked up again as a new file: the version chain
        // is lost, and no bytes are. That is chosen, not overlooked.
        let out = pair(
            &[known(1, "draft.txt", 100, "sha-old")],
            &[observed("final.txt", 100, "sha-new")],
        );
        match out.change_for(EntityId::file(1)) {
            Some(LocalChange::Deleted) => {}
            other => panic!("unexpected: {other:?}"),
        }
        assert_eq!(
            out.created.iter().map(|o| o.path.as_str()).collect::<Vec<_>>(),
            vec!["final.txt"]
        );
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
                server_deleted: false, held: false,
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
    fn a_file_belongs_to_the_entry_that_is_still_on_the_server() {
        // Two entries remember living at one path: file 1, which the server has
        // deleted, and file 2, which is there now. There is one file on disk and
        // it is file 2's.
        //
        // Both used to be told it was theirs. File 1 then read its neighbour's
        // bytes as an edit of its own, lost that edit to the server's delete,
        // and set about rescuing bytes it had no claim to — which the server
        // refused, because file 2 was using the name. It waited for a sibling it
        // had already been told about, four hundred times over.
        let out = pair(
            &[
                known(2, "shared.txt", 200, "sha-live"),
                KnownLocal {
                    server_deleted: true, held: false,
                    ..known(1, "shared.txt", 100, "sha-old")
                },
            ],
            &[observed("shared.txt", 200, "sha-live")],
        );
        assert_eq!(
            out.change_for(EntityId::file(2)),
            Some(&LocalChange::Unchanged)
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Deleted),
            "a deleted entry has nothing at a path somebody else is using"
        );
        assert!(out.created.is_empty());
    }

    #[test]
    fn a_file_at_its_own_path_is_claimed_before_anybody_goes_looking() {
        // File 1 was moved on top of file 2. Both rules could fire on
        // `Report.txt`: file 2 is recorded as living there, and file 1 can reach
        // it by content from where it used to be. Only the first is evidence —
        // the other is a guess that happens to match.
        //
        // Applied per entry, the guess got there first whenever it came first in
        // the list, and the same file was read as two things at once. The move
        // it produced is one the server will not perform, because it will not
        // put two live files under one name, and nothing about the record that
        // produced it changes when the refusal comes back. Five of four hundred
        // random workloads planned that same move on every pass, forever.
        let out = pair(
            &[
                known(1, "old-name.txt", 100, "sha-a"),
                known(2, "Report.txt", 200, "sha-b"),
            ],
            &[observed("Report.txt", 100, "sha-a")],
        );
        assert_eq!(
            out.change_for(EntityId::file(2)),
            Some(&LocalChange::Edited {
                sha256: "sha-a".into(),
                fingerprint: fp(100, 10, 100),
            }),
            "the file at this path is the entry recorded at this path"
        );
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Deleted),
            "the one that was moved away is gone, not moved on top of a sibling"
        );
    }

    #[test]
    fn two_live_entries_over_one_file_is_a_naming_problem_not_a_deletion() {
        // A filesystem that cannot tell `Report.txt` from `report.txt` gives
        // both entries the same path, and only one of them can have the file.
        // The other holds different content, so being handed those bytes reads
        // as an edit — and it would push its neighbour's file to the server as
        // a new version of itself. Neither has been deleted by anybody, so
        // reporting a deletion instead would be worse still.
        let out = pair(
            &[
                known(1, "Report.txt", 100, "sha-a"),
                known(2, "Report.txt", 100, "sha-b"),
            ],
            &[observed("Report.txt", 100, "sha-a")],
        );
        assert_eq!(
            out.change_for(EntityId::file(2)),
            Some(&LocalChange::Unchanged),
            "the one that missed out is left alone for naming to sort out"
        );
    }

    #[test]
    fn an_empty_tree_against_no_entries_produces_nothing() {
        let out = pair(&[], &[]);
        assert!(out.changes.is_empty() && out.created.is_empty());
    }
}
