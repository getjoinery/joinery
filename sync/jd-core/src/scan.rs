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
    /// The volume's own id for the file, only where its ids may break a tie
    /// (`jd_vfs::Personality::id_tie_break`), and 0 everywhere else. Never
    /// identity: read only by the tie-break in [`pair_files`].
    pub tie_break_id: u64,
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
    /// Where the server keeps this record, for one this device keeps
    /// somewhere else on purpose: a sealed file held outside its vault
    /// (owner decision D1). Its own file standing there is the file come
    /// home, whatever now stands at the path it was held at.
    pub server_home: Option<String>,
    /// Where the server has this record, as a path, when that is not where
    /// this disk has it: a move owed or in flight either way, or a hold. A
    /// name the server gives this record is a name it holds (step 2).
    pub server_path: Option<String>,
    /// The file on this disk that is this record's own
    /// (`Entry::own_file`). `pair_files` pairs by it; `pair_with` reads it
    /// only for a record never sent (`never_sent_own_id`).
    pub own_file: Option<jd_vfs::FileIdentity>,
    /// For a claimant -- a record waiting for a vault key to send the file its
    /// source had -- the source. A claimant stands for the bytes at its path
    /// in the vault: its file standing anywhere else is its source's again.
    pub claimant_for: Option<EntityId>,
    /// The hash of this record's file when the last scan saw it
    /// (`Entry::last_seen_sha`): on a weak volume, the identity `pair_files`
    /// reads the record by (layer 1).
    pub last_seen_sha: Option<String>,
    /// The volume's own id for the record's file as last read in this mount
    /// session, where its ids may break a tie, and 0 where there is none
    /// (`jd_vfs::Personality::id_tie_break`). Never identity.
    pub tie_break: u64,
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
    /// Which file each record was paired with, by identity (`pair_files`):
    /// the record's own file from now on. Includes a record read unchanged
    /// against a file that is not the one it knew -- restored from a backup,
    /// saved again with the same bytes -- which no change reports.
    pub bound: Vec<(EntityId, jd_vfs::FileIdentity)>,
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
    pair_with_given(known, observed, awaiting_bytes, &vec![false; known.len()], &vec![false; observed.len()], &vec![false; observed.len()])
}

/// `pair_with`, after something already read some of the records (`given`)
/// and took some of the files (`taken`): those records get no change here and
/// those files are nobody else's, and all of them still count as evidence --
/// who holds which inode, which bytes, which path. Read without them, a
/// record whose own file another record had already taken found nothing of
/// its own elsewhere, and read the stranger at its path as its edit.
fn pair_with_given(
    known: &[KnownLocal],
    observed: &[ObservedFile],
    awaiting_bytes: &HashSet<String>,
    given: &[bool],
    taken: &[bool],
    taken_at_home: &[bool],
) -> ScanOutcome {
    let mut out = ScanOutcome::default();

    let by_path: HashMap<&str, &ObservedFile> =
        observed.iter().map(|o| (o.path.as_str(), o)).collect();

    // Which observed files have been accounted for. Anything left at the end is
    // genuinely new.
    let mut claimed: Vec<bool> = taken.to_vec();
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
    // The server's deleted records included: a record owns its file until it
    // is forgotten, as `pair_files` holds where births are shown. Left out,
    // a file swapped off the path of a file the server had deleted read as
    // the edit of the record whose path it landed on, and the deleted
    // record's trash took the other (kill2 75129; `specs/drive_file_ownership.md`).
    let nonzero = |id: u64| id != 0;
    let mut inode_owners: HashMap<u64, Vec<usize>> = HashMap::new();
    let mut sha_owners: HashMap<&str, Vec<usize>> = HashMap::new();
    let mut record_at: HashMap<&str, Vec<usize>> = HashMap::new();
    for (n, k) in known.iter().enumerate() {
        if let Some(id) = k.fingerprint.map(|f| f.file_id).filter(|id| nonzero(*id)) {
            inode_owners.entry(id).or_default().push(n);
        } else if let Some(id) = never_sent_own_id(k) {
            // A record never sent has no agreed inode or bytes; the file it
            // was minted for is all it has. Read only, as the question "is
            // the file here another record's that arrived by a trade?" -- so
            // a file a swap carried onto a settled record's path is not read
            // as that record's edit. It is never at home here (it has no
            // agreed bytes), so it always counts as not at home. Wrong about a
            // reused id only inside one pass, it costs the settled record's
            // history, which then ends at the backup name while the save goes
            // up new; no bytes are lost (`specs/drive_file_ownership.md`).
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
    let arrived_by_a_trade = |n: usize, k: &KnownLocal, obs: &ObservedFile| -> bool {
        if k.sha256.as_deref() == Some(obs.sha256.as_str()) {
            return false;
        }
        let standing = match k.fingerprint {
            Some(f) if nonzero(f.file_id) => observed_by_inode.get(&f.file_id),
            Some(_) => None,
            // A record never sent knows its file only by the id it was
            // minted for.
            None => match never_sent_own_id(k) {
                Some(id) => observed_by_inode.get(&id),
                None => k
                    .sha256
                    .as_deref()
                    .filter(|mine| *mine != EMPTY_SHA256)
                    .and_then(|mine| observed_by_sha.get(mine)),
            },
        };
        let mine_elsewhere: Vec<&ObservedFile> = standing
            .map(|os| os.iter().copied().filter(|o| o.path != k.path).collect())
            .unwrap_or_default();
        if mine_elsewhere.is_empty() {
            return false;
        }
        let another_not_at_home = |r: usize| r != n && !at_home(&known[r]);
        // A record never sent knows its file by the id it was minted for, and
        // reads by it as a record reads by its identity where births are
        // shown (`pair_files` step 2): its own file standing elsewhere is a
        // move, whatever now stands at its path -- unless it stands beside
        // it, at a path no record holds, and the file here is nobody's: a
        // backup made by renaming, and the file here is the save. Read by
        // the other evidence alone, a sealed file never sent, carried into a
        // plain folder by a swap, read the file left at its path as its edit
        // and was minted again where it went, plain (plat3 75429;
        // `specs/drive_file_ownership.md`, 2b).
        if never_sent_own_id(k).is_some() {
            let nobodys_here = !inode_owners
                .get(&obs.fingerprint.file_id)
                .is_some_and(|rs| rs.iter().any(|r| *r != n));
            let a_backup = nobodys_here
                && mine_elsewhere.iter().any(|o| {
                    folder_of(&o.path) == folder_of(&k.path)
                        && !record_at.contains_key(o.path.as_str())
                        && !awaiting_bytes.contains(o.path.as_str())
                });
            return !a_backup;
        }
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
        // A held record's own file back in the slot the server keeps it in:
        // the file dragged home, and whatever stands at the held path now is
        // another file -- an editor that still had it open, saving to the
        // path it knew. Read as an edit, the hold never released, the new
        // file was never sent, and the file at home was minted again as a
        // waiter on every pass (the reset's D1 return). No backup made by
        // renaming lands there: the held path and that slot are in
        // different folders.
        let home_again = !twin_at_home
            && k.server_home
                .as_deref()
                .is_some_and(|home| mine_elsewhere.iter().any(|o| o.path == home));
        by_inode || by_content || mine_on_anothers_path || home_again
    };

    let mut settled: Vec<bool> = given.to_vec();
    let mut gone_never_sent: Vec<(usize, usize)> = Vec::new();
    for (n, k) in known.iter().enumerate() {
        if settled[n] {
            continue;
        }
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
        if k.held && k.fingerprint.is_none_or(|fp| fp.file_id == 0 || fp.file_id != obs.fingerprint.file_id) {
            continue;
        }
        if arrived_by_a_trade(n, k, obs) {
            continue;
        }
        let i = index_of[obs.path.as_str()];
        // A file a follower took from elsewhere is not this record's, and the
        // record is not left to naming for it: it is read on by rules 2 to 5
        // (the strong reading's steps 3, 5 and 6 refuse it the same way; only
        // a file another record is AT HOME with leaves it unchanged).
        if taken[i] && !taken_at_home[i] {
            continue;
        }
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
            (None, None) => {
                if never_sent_own_id(k).is_some() {
                    gone_never_sent.push((n, out.changes.len()));
                }
                out.changes.push((k.id, LocalChange::Deleted))
            }
        }
    }

    // 6. Last, over what nobody else claimed: a record never sent follows the
    //    file it was minted for, by its file id. With no birth, an id is not
    //    an identity (rule 4), and it buys this only because of what a record
    //    never sent is: it has no file on the server and no version history,
    //    so a wrong match -- a reused id -- only puts a stranger under it,
    //    decided afresh where it stands, as a new file would have been; the
    //    record's hold, if it had one, holds the stranger instead. Nothing is
    //    sent on the id's word. Without it, a file saved in a vault and carried
    //    out by a swap before it was sent was minted again where it landed,
    //    and sent in the clear (hostile2 74415; `specs/drive_file_ownership.md`).
    for (n, at) in gone_never_sent {
        let Some(id) = never_sent_own_id(&known[n]) else { continue };
        let Some(i) = (0..observed.len()).find(|i| !claimed[*i] && observed[*i].fingerprint.file_id == id) else {
            continue;
        };
        claimed[i] = true;
        out.changes[at] = (
            known[n].id,
            LocalChange::Moved {
                to_path: observed[i].path.clone(),
                fingerprint: observed[i].fingerprint,
            },
        );
    }

    for (i, obs) in observed.iter().enumerate() {
        if !claimed[i] {
            out.created.push(obs.clone());
        }
    }

    out
}

/// The file id a record never sent was minted for: it has no agreed inode and
/// no agreed bytes, only its own file. `None` for any other record, and for a
/// zero id (no identity).
fn never_sent_own_id(k: &KnownLocal) -> Option<u64> {
    if k.fingerprint.is_some() || k.sha256.is_some() || !k.id.is_provisional() {
        return None;
    }
    k.own_file.map(|o| o.file_id).filter(|id| *id != 0)
}

/// The sha256 of no bytes: every empty file's, so it names no file.
pub(crate) const EMPTY_SHA256: &str = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

/// The folder a relative path stands in; "" is the sync root.
fn folder_of(path: &str) -> &str {
    path.rsplit_once('/').map_or("", |(dir, _)| dir)
}

/// Pair what is on disk against what the engine last recorded, by each file's
/// own identity first (`specs/drive_file_identity.md`).
///
/// A record whose own file is a strong identity -- an id and a birth, on a
/// volume whose personality trusts them (`strong_volume`) -- is paired by that
/// file wherever it now stands, and its path decides only when the file is
/// gone from this disk. The path rule above was right for the safe-save and
/// wrong for a trade: two files that exchanged names each read as the other's
/// edit, and a sealed file at a plain record's path went up as that record's
/// version (Defects AI, AH). A record without a strong own file is paired by
/// [`pair_with`], unchanged, over the files no strong record owns; with no
/// strong record at all this is `pair_with` exactly.
///
/// Each step works over files no earlier step claimed:
///
/// 1. **At home.** A record whose own file stands at its path takes it:
///    unchanged, or edited in place. Records sharing one path and one file
///    (case twins, a server-deleted record) rank by `contender_rank`; the
///    rest are unchanged for naming, or deleted if the server deleted them.
/// 2. **Followed.** A record whose own file stands elsewhere follows it:
///    moved, or moved and edited. An identity a record is at home with is
///    that record's, and a hard-linked twin reads by its path (step 3). A
///    backup made by renaming -- the record's own file under a new name in
///    the same folder, at a path no record holds, and a file no record owns
///    standing at the record's path -- is a save: the file at the path is the
///    edit, and the renamed one is new. Never for a held record: its file
///    stands in a plain folder while it is sealed, and read as a backup the
///    vault's plaintext went up as a new file.
/// 3. **Replaced.** A record whose own file is gone takes a file no record
///    owns at its path: the safe-save.
/// 4. **Weak records** run `pair_with` over the files left that no strong
///    record owns.
/// 5. **Found by content.** A record still unsettled whose agreed bytes stand
///    on a file no record owns has moved: another volume, a restore.
/// 6. The rest are deleted: a record whose own file stands nowhere. Another
///    record's file at home at its path is that record's, and does not keep
///    this one standing -- read as unchanged, it held a name with no file
///    behind it, a never-sent file held at that path claimed none, and
///    naming had nothing to settle: the server's file never came down again
///    (plat3 75422).
///
/// A claimant's own file standing anywhere but at its path is its source's
/// again: the source follows it (step 2), which is what ends the hold, and
/// the claimant reads its path like any record whose own file is gone. A
/// held record (its bytes held by a claimant, or held outside its vault)
/// reads by steps 1 and 2 only. A slot awaiting a download is a path a record
/// holds (steps 2, 3 and 5). Files no step claimed are new.
///
/// The invariant: a file whose strong identity a record owns is claimed by
/// that record or by nobody.
pub fn pair_files(
    known: &[KnownLocal],
    observed: &[ObservedFile],
    awaiting_bytes: &HashSet<String>,
    strong_volume: bool,
) -> ScanOutcome {
    if strong_volume {
        let own = |k: &KnownLocal| k.own_file.filter(|o| o.is_strong());
        if !known.iter().any(|k| own(k).is_some()) {
            return pair_with(known, observed, awaiting_bytes);
        }
        let identity = |o: &ObservedFile| Some(o.fingerprint.identity()).filter(|i| i.is_strong());
        return pair_by(known, observed, awaiting_bytes, own, identity, identity, Steps::All);
    }
    // A weak volume cannot say which file is which, and its bytes can: a
    // file whose bytes are still the ones a record last saw is that record's
    // file, unedited, wherever it now stands (layer 1 and rule X,
    // `specs/drive_weak_volume_identity.md`). Following it adds nothing to
    // the record's history that is not there already, so a wrong follow
    // mixes nothing (D1). Only bytes that name one record count: two records
    // with the same bytes (a copy, a template, an empty file) name neither.
    // And only bytes that stand somewhere: a record whose bytes are gone was
    // edited, and is read by `pair_with` as before -- the safe-save stays an
    // edit. Held records and claimants keep today's reading too; a vault on
    // a weak volume is refused (D3).
    // The bytes last seen, unless the agreed bytes stand at the record's own
    // path: then it is at home with them, whatever an older look saw (a
    // download that landed since, say), and an old copy elsewhere is not it.
    let at_path: HashMap<&str, &str> = observed.iter().map(|o| (o.path.as_str(), o.sha256.as_str())).collect();
    let key = |k: &KnownLocal| -> Option<String> {
        if k.held || k.server_home.is_some() || k.claimant_for.is_some() {
            return None;
        }
        let agreed_here = k.sha256.as_deref().filter(|a| at_path.get(k.path.as_str()) == Some(a));
        agreed_here
            .map(str::to_string)
            .or_else(|| k.last_seen_sha.clone())
            .or_else(|| k.sha256.clone())
            .filter(|sha| !sha.is_empty() && sha != EMPTY_SHA256)
    };
    let mut claims: HashMap<String, usize> = HashMap::new();
    for k in known {
        if let Some(sha) = key(k) {
            *claims.entry(sha).or_default() += 1;
        }
    }
    // Bytes two records claim (a copy, a conflict copy) name neither -- unless
    // the volume's ids may break the tie (`Personality::id_tie_break`): then a
    // record whose recorded id is the id of one file with those bytes names
    // that file, and only that file. Never identity: a record with no id, or
    // an id no such file carries, stays unnamed and is read as before.
    let shared: HashSet<&String> = claims.iter().filter(|(_, n)| **n > 1).map(|(sha, _)| sha).collect();
    let refined = |sha: String, tie_break: u64| -> String {
        if tie_break != 0 && shared.contains(&sha) {
            format!("{sha}#{tie_break}")
        } else {
            sha
        }
    };
    let mut claims: HashMap<String, usize> = HashMap::new();
    for k in known {
        if let Some(sha) = key(k) {
            *claims.entry(refined(sha, k.tie_break)).or_default() += 1;
        }
    }
    let mut standing: HashMap<String, usize> = HashMap::new();
    for o in observed {
        *standing.entry(refined(o.sha256.clone(), o.tie_break_id)).or_default() += 1;
    }
    let named: HashSet<String> = claims
        .into_iter()
        .filter(|(key, n)| *n == 1 && standing.get(key).is_some_and(|m| *m == 1 || !key.contains('#')))
        .map(|(key, _)| key)
        .collect();
    // A claimant, or a file held outside its vault, reads by none of this,
    // but a file standing at its own path with the bytes it was last seen
    // with is its file at home all the same, and names nobody else: another
    // record whose bytes are equal follows to a copy of them elsewhere, never
    // onto that one. Taken by such a follow, the claimant read as deleted and
    // was forgotten with its conversion, and the plain record it replaced kept
    // its server copy for ever (win hostile2 74407).
    let home_of_the_unkeyed: HashSet<&str> = known
        .iter()
        .filter(|k| k.claimant_for.is_some() || k.server_home.is_some())
        .filter(|k| k.last_seen_sha.as_deref().is_some_and(|seen| at_path.get(k.path.as_str()) == Some(&seen)))
        .map(|k| k.path.as_str())
        .collect();
    let own = |k: &KnownLocal| key(k).map(|sha| refined(sha, k.tie_break)).filter(|key| named.contains(key));
    let identity = |o: &ObservedFile| {
        Some(refined(o.sha256.clone(), o.tie_break_id))
            .filter(|key| named.contains(key))
            .filter(|_| !home_of_the_unkeyed.contains(o.path.as_str()))
    };
    pair_by(known, observed, awaiting_bytes, own, identity, |_: &ObservedFile| None, Steps::AtHomeAndFollowed)
}

/// How much of `pair_by` a reading uses.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Steps {
    /// Every step, with records that have no identity read by `pair_with`
    /// over the files no identity is owned by: the strong reading.
    All,
    /// At home and followed only; every record left, and every file left, is
    /// read by `pair_with`: the weak reading, whose identity (the bytes) is
    /// only ever evidence that a file is unedited.
    AtHomeAndFollowed,
}

/// The steps of [`pair_files`], over whatever identity a reading has: a
/// file's id and birth on a strong volume, its bytes on a weak one. `own` is
/// a record's identity, `identity` a file's, and `bind` what a pairing
/// records as the record's own file from now on (`ScanOutcome::bound`).
fn pair_by<K: Clone + Eq + std::hash::Hash>(
    known: &[KnownLocal],
    observed: &[ObservedFile],
    awaiting_bytes: &HashSet<String>,
    own: impl Fn(&KnownLocal) -> Option<K>,
    identity: impl Fn(&ObservedFile) -> Option<K>,
    bind: impl Fn(&ObservedFile) -> Option<jd_vfs::FileIdentity>,
    steps: Steps,
) -> ScanOutcome {
    let held = |k: &KnownLocal| k.held || k.server_home.is_some();
    let moved = |k: &KnownLocal, o: &ObservedFile| {
        if k.sha256.as_deref() == Some(o.sha256.as_str()) {
            LocalChange::Moved {
                to_path: o.path.clone(),
                fingerprint: o.fingerprint,
            }
        } else {
            LocalChange::MovedAndEdited {
                to_path: o.path.clone(),
                sha256: o.sha256.clone(),
                fingerprint: o.fingerprint,
            }
        }
    };
    let unchanged_or_edited = |k: &KnownLocal, o: &ObservedFile| {
        if k.sha256.as_deref() == Some(o.sha256.as_str()) {
            LocalChange::Unchanged
        } else {
            LocalChange::Edited {
                sha256: o.sha256.clone(),
                fingerprint: o.fingerprint,
            }
        }
    };

    let mut out = ScanOutcome::default();
    let mut claimed = vec![false; observed.len()];
    let mut settled = vec![false; known.len()];
    let at_path: HashMap<&str, usize> =
        observed.iter().enumerate().map(|(i, o)| (o.path.as_str(), i)).collect();
    let mut by_identity: HashMap<K, Vec<usize>> = HashMap::new();
    for (i, o) in observed.iter().enumerate() {
        if let Some(id) = identity(o) {
            by_identity.entry(id).or_default().push(i);
        }
    }
    // Every strong record owns its file, the server's deleted ones included:
    // a record owns its file until it is forgotten.
    let owned_ids: HashSet<K> = known.iter().filter_map(&own).collect();
    let owned = |i: usize| identity(&observed[i]).is_some_and(|id| owned_ids.contains(&id));
    let held_paths: HashSet<&str> = known
        .iter()
        .map(|k| k.path.as_str())
        .chain(known.iter().filter_map(|k| k.server_path.as_deref()))
        .chain(awaiting_bytes.iter().map(String::as_str))
        .collect();
    // Who goes first among records after one file: live before the server's
    // deleted, a synced record before a provisional, then the lowest id.
    let contender_rank = |n: usize| {
        let k = &known[n];
        (k.server_deleted, k.id.is_provisional(), k.id.server_id)
    };
    let nearest = |k: &KnownLocal, candidates: &mut Vec<usize>| {
        candidates.sort_by(|a, b| {
            let key = |i: &usize| (folder_of(&observed[*i].path) != folder_of(&k.path), &observed[*i].path);
            key(a).cmp(&key(b))
        });
        candidates.first().copied()
    };

    // 1. At home.
    let mut home: std::collections::BTreeMap<usize, Vec<usize>> = Default::default();
    for (n, k) in known.iter().enumerate() {
        let Some(id) = own(k) else { continue };
        if let Some(&i) = at_path.get(k.path.as_str()) {
            if identity(&observed[i]) == Some(id) {
                home.entry(i).or_default().push(n);
            }
        }
    }
    let mut at_home_ids: HashSet<K> = HashSet::new();
    let mut home_taken: HashSet<usize> = HashSet::new();
    for (i, mut contenders) in home {
        let bytes_here = |n: &usize| known[*n].sha256.as_deref() != Some(observed[i].sha256.as_str());
        contenders.sort_by_key(|n| (bytes_here(n), contender_rank(*n)));
        claimed[i] = true;
        home_taken.insert(i);
        at_home_ids.extend(identity(&observed[i]));
        for (place, n) in contenders.into_iter().enumerate() {
            settled[n] = true;
            let k = &known[n];
            let change = if place == 0 {
                out.bound.extend(bind(&observed[i]).map(|id| (k.id, id)));
                unchanged_or_edited(k, &observed[i])
            } else if k.server_deleted {
                LocalChange::Deleted
            } else {
                LocalChange::Unchanged
            };
            out.changes.push((k.id, change));
        }
    }

    // 2. Followed.
    let mut followers: Vec<usize> = (0..known.len())
        .filter(|n| !settled[*n] && own(&known[*n]).is_some_and(|id| !at_home_ids.contains(&id)))
        .collect();
    followers.sort_by_key(|n| contender_rank(*n));
    for n in followers {
        let k = &known[n];
        let Some(id) = own(k) else { continue };
        let mut names: Vec<usize> = by_identity
            .get(&id)
            .map(|v| v.iter().copied().filter(|i| !claimed[*i]).collect())
            .unwrap_or_default();
        let Some(c) = nearest(k, &mut names) else { continue };
        if let Some(source) = k.claimant_for {
            // Path-bound: the claimant stays for step 3, and its source takes
            // the file where it stands.
            let Some(s) = known.iter().position(|r| r.id == source).filter(|s| !settled[*s]) else {
                continue;
            };
            claimed[c] = true;
            settled[s] = true;
            let obs = &observed[c];
            out.bound.extend(bind(obs).map(|id| (known[s].id, id)));
            out.changes.push((known[s].id, moved(&known[s], obs)));
            continue;
        }
        let backup = !held(k)
            && folder_of(&observed[c].path) == folder_of(&k.path)
            && !held_paths.contains(observed[c].path.as_str())
            && !awaiting_bytes.contains(k.path.as_str());
        if backup {
            if let Some(&o) = at_path.get(k.path.as_str()).filter(|o| !claimed[**o] && !owned(**o)) {
                claimed[o] = true;
                settled[n] = true;
                out.bound.extend(bind(&observed[o]).map(|id| (k.id, id)));
                out.changes.push((k.id, unchanged_or_edited(k, &observed[o])));
                continue;
            }
        }
        claimed[c] = true;
        settled[n] = true;
        out.bound.extend(bind(&observed[c]).map(|id| (k.id, id)));
        out.changes.push((k.id, moved(k, &observed[c])));
    }

    if steps == Steps::AtHomeAndFollowed {
        // Everything not read by its bytes is read as before, with every
        // record and file still in view as evidence.
        let mut at_home_files = vec![false; observed.len()];
        for i in &home_taken {
            at_home_files[*i] = true;
        }
        let weak_out = pair_with_given(known, observed, awaiting_bytes, &settled, &claimed, &at_home_files);
        let left: HashSet<&str> = weak_out.created.iter().map(|o| o.path.as_str()).collect();
        for i in 0..observed.len() {
            if !left.contains(observed[i].path.as_str()) {
                claimed[i] = true;
            }
        }
        out.changes.extend(weak_out.changes);
        let position: HashMap<EntityId, usize> =
            known.iter().enumerate().map(|(n, k)| (k.id, n)).collect();
        out.changes.sort_by_key(|(id, _)| position.get(id).copied().unwrap_or(usize::MAX));
        for (i, o) in observed.iter().enumerate() {
            if !claimed[i] {
                out.created.push(o.clone());
            }
        }
        return out;
    }

    // 3. Replaced.
    let mut replaced: Vec<usize> = (0..known.len())
        .filter(|n| !settled[*n] && own(&known[*n]).is_some() && !held(&known[*n]))
        .collect();
    replaced.sort_by_key(|n| contender_rank(*n));
    for n in replaced {
        let k = &known[n];
        if awaiting_bytes.contains(k.path.as_str()) {
            continue;
        }
        let Some(&o) = at_path.get(k.path.as_str()) else { continue };
        if claimed[o] || owned(o) {
            continue;
        }
        claimed[o] = true;
        settled[n] = true;
        out.bound.extend(bind(&observed[o]).map(|id| (k.id, id)));
        out.changes.push((k.id, unchanged_or_edited(k, &observed[o])));
    }

    // 4. Weak records, by today's rules, over what no strong record owns.
    // Not a record an earlier step settled: a claimant's source, which has no
    // identity of its own once it handed its file over, is settled by step 2
    // when its claimant's file stands elsewhere -- read again here it was
    // also Deleted, two changes for one record in one scan.
    let weak: Vec<KnownLocal> = known
        .iter()
        .enumerate()
        .filter(|(n, k)| own(k).is_none() && !settled[*n])
        .map(|(_, k)| k.clone())
        .collect();
    let rest: Vec<usize> = (0..observed.len()).filter(|i| !claimed[*i] && !owned(*i)).collect();
    let rest_files: Vec<ObservedFile> = rest.iter().map(|i| observed[*i].clone()).collect();
    let weak_out = pair_with(&weak, &rest_files, awaiting_bytes);
    let left: HashSet<&str> = weak_out.created.iter().map(|o| o.path.as_str()).collect();
    for i in rest {
        if !left.contains(observed[i].path.as_str()) {
            claimed[i] = true;
        }
    }
    out.changes.extend(weak_out.changes);

    // 5. Found by content.
    let mut searchers: Vec<usize> = (0..known.len())
        .filter(|n| !settled[*n] && own(&known[*n]).is_some() && !held(&known[*n]))
        .collect();
    searchers.sort_by_key(|n| contender_rank(*n));
    for n in searchers {
        let k = &known[n];
        let Some(want) = k.sha256.as_deref() else { continue };
        let mut hits: Vec<usize> = (0..observed.len())
            .filter(|i| {
                !claimed[*i]
                    && !owned(*i)
                    && observed[*i].sha256 == want
                    && !awaiting_bytes.contains(observed[*i].path.as_str())
            })
            .collect();
        let Some(i) = nearest(k, &mut hits) else { continue };
        claimed[i] = true;
        settled[n] = true;
        out.bound.extend(bind(&observed[i]).map(|id| (k.id, id)));
        out.changes.push((
            k.id,
            LocalChange::Moved {
                to_path: observed[i].path.clone(),
                fingerprint: observed[i].fingerprint,
            },
        ));
    }

    // 6. The rest.
    for (n, k) in known.iter().enumerate() {
        if settled[n] || own(k).is_none() {
            continue;
        }
        out.changes.push((k.id, LocalChange::Deleted));
    }

    // In the order the records were given, whichever step settled them.
    let position: HashMap<EntityId, usize> =
        known.iter().enumerate().map(|(n, k)| (k.id, n)).collect();
    out.changes.sort_by_key(|(id, _)| position.get(id).copied().unwrap_or(usize::MAX));
    out.bound.sort_by_key(|(id, _)| position.get(id).copied().unwrap_or(usize::MAX));
    for (i, o) in observed.iter().enumerate() {
        if !claimed[i] {
            out.created.push(o.clone());
        }
    }
    out
}

/// The tie-break ids a pass may read (`jd_vfs::Personality::id_tie_break`,
/// `specs/drive_weak_volume_identity.md`): of those `recorded` (the id and
/// the mount session it was read in), the ones that still hold.
///
/// A mount-session volume renumbers on every mount, so an id read in another
/// session is void unread (O2): after a remount the numbers are handed out
/// again, and an old one can equal a different file's new one by chance. A
/// durable volume keeps its ids through a remount, and only the disk says one
/// has gone stale (O1): a record whose file stands unedited at its own path
/// under another id than the one recorded means the volume renumbered behind
/// the engine's back -- a stick whose clusters another machine reused -- and
/// then no recorded id names anything this pass. The same check runs on both.
/// Any such record counts, its bytes shared or not: a swap inside a set of
/// identical copies voids every id for the pass too, and the pass reads that
/// set as a disk with no ids does.
/// Where every id is void the pass reads by bytes and paths alone.
pub(crate) fn tie_breaks_that_hold(
    class: jd_vfs::IdTieBreak,
    session_now: i64,
    recorded: &HashMap<EntityId, (u64, i64)>,
    unedited_at_home: &HashMap<EntityId, u64>,
) -> HashMap<EntityId, u64> {
    let in_force: HashMap<EntityId, u64> = match class {
        jd_vfs::IdTieBreak::None => return HashMap::new(),
        jd_vfs::IdTieBreak::MountSession => recorded
            .iter()
            .filter(|(_, (_, session))| *session == session_now)
            .map(|(e, (id, _))| (*e, *id))
            .collect(),
        jd_vfs::IdTieBreak::Durable => recorded.iter().map(|(e, (id, _))| (*e, *id)).collect(),
    };
    let stale = in_force
        .iter()
        .any(|(e, id)| unedited_at_home.get(e).is_some_and(|now| now != id));
    if stale {
        return HashMap::new();
    }
    in_force
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fp(file_id: u64, size: u64, mtime_ns: u64) -> jd_vfs::Fingerprint {
        jd_vfs::Fingerprint {
            size,
            mtime_ns,
            file_id,
            birth_ns: 0,
        }
    }

    fn observed(path: &str, file_id: u64, sha: &str) -> ObservedFile {
        ObservedFile {
            path: path.into(),
            fingerprint: fp(file_id, 10, 100),
            sha256: sha.into(),
            tie_break_id: 0,
        }
    }

    fn known(id: i64, path: &str, file_id: u64, sha: &str) -> KnownLocal {
        KnownLocal {
            id: EntityId::file(id),
            path: path.into(),
            fingerprint: Some(fp(file_id, 10, 100)),
            sha256: Some(sha.into()),
            server_deleted: false, held: false, server_home: None, server_path: None, own_file: None, claimant_for: None, last_seen_sha: None, tie_break: 0,
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
            server_home: None,
            server_path: None,
            own_file: None,
            last_seen_sha: None, tie_break: 0,
            claimant_for: None,
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
    fn a_held_files_own_file_back_where_the_server_keeps_it_is_the_file_come_home() {
        // A held record's own file stands in the slot the server keeps it in,
        // and a stranger stands at the path it was held at. Its file came home:
        // a move, and the stranger is new. Without the server's slot the same
        // disk reads as an edit (the backup-by-rename reading).
        let held = KnownLocal {
            server_home: Some("Private/out.txt".into()),
            ..known(1, "Plain/out.txt", 100, "sha-held")
        };
        let disk = [observed("Plain/out.txt", 300, "sha-new"), observed("Private/out.txt", 100, "sha-held")];
        let out = pair(&[held.clone()], &disk);
        assert_eq!(
            out.change_for(EntityId::file(1)),
            Some(&LocalChange::Moved { to_path: "Private/out.txt".into(), fingerprint: fp(100, 10, 100) })
        );
        assert_eq!(out.created.iter().map(|c| c.path.as_str()).collect::<Vec<_>>(), vec!["Plain/out.txt"]);
        let out = pair(&[KnownLocal { server_home: None, ..held }], &disk);
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
                server_deleted: false, held: false, server_home: None, server_path: None, own_file: None, claimant_for: None, last_seen_sha: None, tie_break: 0,
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

    // ---- by identity (`pair_files`, specs/drive_file_identity.md) -----------

    fn sfp(file_id: u64, birth_ns: u64) -> jd_vfs::Fingerprint {
        jd_vfs::Fingerprint { size: 10, mtime_ns: 100, file_id, birth_ns }
    }

    /// A record whose own file is `(file_id, birth)`.
    fn mine(id: i64, path: &str, file_id: u64, birth: u64, sha: &str) -> KnownLocal {
        KnownLocal {
            fingerprint: Some(sfp(file_id, birth)),
            own_file: Some(jd_vfs::FileIdentity { file_id, birth_ns: birth }),
            last_seen_sha: None,
            ..known(id, path, file_id, sha)
        }
    }

    fn seen(path: &str, file_id: u64, birth: u64, sha: &str) -> ObservedFile {
        ObservedFile { path: path.into(), fingerprint: sfp(file_id, birth), sha256: sha.into(), tie_break_id: 0 }
    }

    fn by_identity(known: &[KnownLocal], observed: &[ObservedFile]) -> ScanOutcome {
        pair_files(known, observed, &HashSet::new(), true)
    }

    fn moved_to(out: &ScanOutcome, id: i64) -> Option<String> {
        match out.change_for(EntityId::file(id)) {
            Some(LocalChange::Moved { to_path, .. }) | Some(LocalChange::MovedAndEdited { to_path, .. }) => {
                Some(to_path.clone())
            }
            _ => None,
        }
    }

    fn created(out: &ScanOutcome) -> Vec<&str> {
        out.created.iter().map(|o| o.path.as_str()).collect()
    }

    #[test]
    fn by_identity_a_file_moved_and_edited_keeps_its_history() {
        // Rule 4's price, paid only by a bare inode: with a birth the file is
        // the same file, renamed and edited.
        let out = by_identity(&[mine(1, "draft.txt", 100, 7, "sha-old")], &[seen("final.txt", 100, 7, "sha-new")]);
        assert!(
            matches!(out.change_for(EntityId::file(1)), Some(LocalChange::MovedAndEdited { to_path, sha256, .. })
                if to_path == "final.txt" && sha256 == "sha-new"),
            "{:?}", out.change_for(EntityId::file(1))
        );
        assert!(out.created.is_empty());
    }

    #[test]
    fn by_identity_a_recycled_id_with_a_new_birth_is_another_file() {
        let out = by_identity(&[mine(1, "gone.txt", 100, 7, "sha-gone")], &[seen("unrelated.txt", 100, 8, "sha-other")]);
        assert_eq!(out.change_for(EntityId::file(1)), Some(&LocalChange::Deleted));
        assert_eq!(created(&out), vec!["unrelated.txt"]);
    }

    #[test]
    fn by_identity_two_files_trading_names_are_two_moves() {
        let out = by_identity(
            &[mine(1, "a.txt", 100, 1, "sha-a"), mine(2, "b.txt", 200, 2, "sha-b")],
            &[seen("a.txt", 200, 2, "sha-b"), seen("b.txt", 100, 1, "sha-a")],
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("b.txt"));
        assert_eq!(moved_to(&out, 2).as_deref(), Some("a.txt"));
        assert!(out.created.is_empty());
    }

    #[test]
    fn by_identity_a_rotation_caught_half_way_is_two_moves() {
        // a -> tmp, b -> a, and the scan lands before tmp -> b.
        let out = by_identity(
            &[mine(1, "a.txt", 100, 1, "sha-a"), mine(2, "b.txt", 200, 2, "sha-b")],
            &[seen("tmp", 100, 1, "sha-a"), seen("a.txt", 200, 2, "sha-b")],
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("tmp"));
        assert_eq!(moved_to(&out, 2).as_deref(), Some("a.txt"));
    }

    #[test]
    fn by_identity_a_file_moved_over_another_is_a_move_and_a_delete() {
        // `mv old-name.txt Report.txt`. The path rule read Report.txt's record
        // as edited with the mover's bytes -- a sealed mover's plaintext sent
        // as a plain file's version (AH) -- and the mover as deleted.
        let out = by_identity(
            &[mine(1, "old-name.txt", 100, 1, "sha-a"), mine(2, "Report.txt", 200, 2, "sha-b")],
            &[seen("Report.txt", 100, 1, "sha-a")],
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("Report.txt"));
        assert_eq!(out.change_for(EntityId::file(2)), Some(&LocalChange::Deleted));
    }

    #[test]
    fn by_identity_a_backup_made_by_renaming_is_still_a_save() {
        // p1 with births: the original renamed beside itself, a new file at
        // the name. The renamed one is new; the name's record is edited.
        let out = by_identity(
            &[mine(1, "notes.txt", 100, 1, "sha-old")],
            &[seen("notes.txt~", 100, 1, "sha-old"), seen("notes.txt", 101, 2, "sha-new")],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
        assert_eq!(created(&out), vec!["notes.txt~"]);
    }

    #[test]
    fn by_identity_a_file_renamed_into_another_folder_is_never_a_backup() {
        // A backup is written beside its original. The same disk across two
        // folders is the file moved, and a new file saved at its old name.
        let out = by_identity(
            &[mine(1, "Plain/out.txt", 100, 1, "sha-out")],
            &[seen("Private/out.txt", 100, 1, "sha-out"), seen("Plain/out.txt", 300, 3, "sha-new")],
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("Private/out.txt"));
        assert_eq!(created(&out), vec!["Plain/out.txt"]);
    }

    #[test]
    fn by_identity_a_held_file_renamed_in_place_beside_a_stranger_is_moved() {
        // O1. A held file is sealed and stands in a plain folder, so the
        // backup reading would make the stranger its edit and put the vault's
        // plaintext, renamed, up as a new plain file. Both kinds of hold.
        for held in [
            KnownLocal { held: true, ..mine(1, "Plain/out.txt", 100, 1, "sha-sealed") },
            KnownLocal { server_home: Some("Private/out.txt".into()), ..mine(1, "Plain/out.txt", 100, 1, "sha-sealed") },
        ] {
            let out = by_identity(
                &[held],
                &[seen("Plain/out2.txt", 100, 1, "sha-sealed"), seen("Plain/out.txt", 300, 3, "sha-stranger")],
            );
            assert_eq!(moved_to(&out, 1).as_deref(), Some("Plain/out2.txt"));
            assert_eq!(created(&out), vec!["Plain/out.txt"]);
        }
    }

    #[test]
    fn by_identity_a_trade_with_a_slot_awaiting_a_download_is_not_a_backup() {
        // O6 (T1, plain2 75292): b.txt is a live record's with nothing here
        // yet. The record's own file standing there is the file moved.
        let awaiting: HashSet<String> = ["b.txt".to_string()].into();
        let out = pair_files(
            &[mine(1, "a.txt", 100, 1, "sha-a")],
            &[seen("b.txt", 100, 1, "sha-a"), seen("a.txt", 300, 3, "sha-new")],
            &awaiting,
            true,
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("b.txt"));
        assert_eq!(created(&out), vec!["a.txt"]);
    }

    /// Without births (`specs/drive_file_ownership.md`): a record never sent
    /// knows its file by the id it was minted for.
    fn never_sent(id: i64, path: &str, file_id: u64) -> KnownLocal {
        KnownLocal { id: EntityId::file(id), sha256: None, fingerprint: None, ..mine(id, path, file_id, 0, "") }
    }

    #[test]
    fn without_births_a_provisional_traded_with_a_synced_file_is_two_moves() {
        // A swap: each file stands at the other's path. The settled record
        // does not read the never-sent file as its edit, nor the other way.
        let out = pair_files(
            &[never_sent(-1, "new.txt", 500), known(2, "old.txt", 600, "sha-old")],
            &[observed("new.txt", 600, "sha-old"), observed("old.txt", 500, "sha-draft")],
            &HashSet::new(),
            false,
        );
        assert_eq!(moved_to(&out, -1).as_deref(), Some("old.txt"));
        assert_eq!(moved_to(&out, 2).as_deref(), Some("new.txt"));
        assert!(out.created.is_empty(), "{:?}", out.created);
    }

    #[test]
    fn without_births_a_never_sent_file_carried_elsewhere_is_followed_by_its_id() {
        let out = pair_files(
            &[never_sent(-1, "Private/new.txt", 500)],
            &[observed("Plain/new.txt", 500, "sha-draft")],
            &HashSet::new(),
            false,
        );
        assert_eq!(moved_to(&out, -1).as_deref(), Some("Plain/new.txt"));
        assert!(out.created.is_empty(), "minted again where it landed: {:?}", out.created);
    }

    #[test]
    fn without_births_a_never_sent_file_carried_into_another_folder_is_followed_not_edited() {
        // A swap with a file nothing tracks yet: the file left at the record's
        // path is new, and the record follows its own file by its id.
        let out = pair_files(
            &[never_sent(-1, "Private/new.txt", 500)],
            &[observed("Private/new.txt", 600, "sha-other"), observed("Plain/other.txt", 500, "sha-draft")],
            &HashSet::new(),
            false,
        );
        assert_eq!(moved_to(&out, -1).as_deref(), Some("Plain/other.txt"));
        assert_eq!(created(&out), vec!["Private/new.txt"]);
    }

    #[test]
    fn without_births_a_never_sent_files_backup_by_rename_is_still_a_save() {
        // The editor renames the original to notes.txt~ and writes a new
        // notes.txt: the new file is the save, the backup is new.
        let out = pair_files(
            &[never_sent(-1, "notes.txt", 500)],
            &[observed("notes.txt~", 500, "sha-old"), observed("notes.txt", 700, "sha-new")],
            &HashSet::new(),
            false,
        );
        assert!(matches!(out.change_for(EntityId::file(-1)), Some(LocalChange::Edited { .. })), "{:?}", out.changes);
        assert_eq!(out.created.iter().map(|o| o.path.as_str()).collect::<Vec<_>>(), vec!["notes.txt~"]);
    }

    #[test]
    fn by_identity_a_provisional_traded_with_a_synced_file_is_two_moves() {
        // T1-C: a record never uploaded has its own file from its mint.
        let provisional = KnownLocal { id: EntityId::file(-1), sha256: None, fingerprint: None, ..mine(-1, "new.txt", 500, 5, "") };
        let out = by_identity(
            &[provisional, mine(2, "old.txt", 600, 6, "sha-old")],
            &[seen("new.txt", 600, 6, "sha-old"), seen("old.txt", 500, 5, "sha-draft")],
        );
        assert_eq!(moved_to(&out, -1).as_deref(), Some("old.txt"));
        assert_eq!(moved_to(&out, 2).as_deref(), Some("new.txt"));
    }

    #[test]
    fn by_identity_a_file_the_server_deleted_is_still_its_records_own() {
        // Its record is deleted on the server and not yet forgotten; its file
        // traded onto another record's path is not that record's edit.
        let out = by_identity(
            &[
                KnownLocal { server_deleted: true, ..mine(1, "sealed.txt", 100, 1, "sha-sealed") },
                mine(2, "plain.txt", 200, 2, "sha-plain"),
            ],
            &[seen("plain.txt", 100, 1, "sha-sealed")],
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("plain.txt"));
        assert_eq!(out.change_for(EntityId::file(2)), Some(&LocalChange::Deleted));
    }

    #[test]
    fn by_identity_a_hard_linked_file_is_followed_to_its_nearest_name() {
        let out = by_identity(
            &[mine(1, "a.txt", 100, 1, "sha-a")],
            &[seen("c.txt", 100, 1, "sha-a"), seen("b.txt", 100, 1, "sha-a")],
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("b.txt"));
        assert_eq!(created(&out), vec!["c.txt"]);
    }

    #[test]
    fn by_identity_a_safe_save_of_one_hard_linked_name_is_an_edit() {
        // p2 with births: the identity is at home under b.txt, so a.txt reads
        // by its path.
        let out = by_identity(
            &[mine(1, "a.txt", 100, 1, "sha-x"), mine(2, "b.txt", 100, 1, "sha-x")],
            &[seen("a.txt", 101, 2, "sha-y"), seen("b.txt", 100, 1, "sha-x")],
        );
        assert!(edited(&out, 1), "{:?}", out.change_for(EntityId::file(1)));
        assert_eq!(out.change_for(EntityId::file(2)), Some(&LocalChange::Unchanged));
    }

    #[test]
    fn by_identity_a_claimants_file_brought_back_out_is_its_sources_again() {
        // A keyless device dragged memo.txt into a vault: a claimant waits at
        // the vault path with the file, and the source is held. The file comes
        // back out under a new name and a new note is saved at the vault path.
        // The source moved; the claimant now stands for the note.
        let source = KnownLocal { held: true, own_file: None, ..known(1, "memo.txt", 100, "sha-memo") };
        let claimant = KnownLocal {
            claimant_for: Some(EntityId::file(1)),
            sha256: None,
            fingerprint: None,
            ..mine(-1, "Private/memo.txt", 100, 1, "")
        };
        let out = by_identity(
            &[source, claimant],
            &[seen("memo-again.txt", 100, 1, "sha-memo"), seen("Private/memo.txt", 300, 3, "sha-note")],
        );
        assert_eq!(moved_to(&out, 1).as_deref(), Some("memo-again.txt"));
        assert!(edited(&out, -1), "{:?}", out.change_for(EntityId::file(-1)));
        assert!(out.created.is_empty());
    }

    #[test]
    fn by_identity_nothing_strong_is_the_path_rule_exactly() {
        // No birth, or a volume that does not trust its ids: `pair_with`.
        let known = [known(1, "a.txt", 100, "sha-a"), known(2, "b.txt", 200, "sha-b")];
        let observed = [observed("a.txt", 200, "sha-b"), observed("b.txt", 100, "sha-a")];
        let weak = pair_with(&known, &observed, &HashSet::new());
        assert_eq!(by_identity(&known, &observed), weak);
        let strong = [mine(1, "a.txt", 100, 1, "sha-a"), mine(2, "b.txt", 200, 2, "sha-b")];
        let seen_strong = [seen("a.txt", 200, 2, "sha-b"), seen("b.txt", 100, 1, "sha-a")];
        assert_eq!(
            pair_files(&strong, &seen_strong, &HashSet::new(), false),
            pair_with(&strong, &seen_strong, &HashSet::new())
        );
    }

    // Layer 1 and rule X (`specs/drive_weak_volume_identity.md`): on a weak
    // volume a record is read by the bytes it last saw, where they stand.

    /// A record on a weak volume that last saw `sha` at `path`, with no id.
    fn saw(id: i64, path: &str, sha: &str) -> KnownLocal {
        KnownLocal { last_seen_sha: Some(sha.into()), ..known(id, path, 0, sha) }
    }

    fn weak(known: &[KnownLocal], observed: &[ObservedFile]) -> ScanOutcome {
        pair_files(known, observed, &HashSet::new(), false)
    }

    #[test]
    fn on_a_weak_volume_an_unedited_file_carried_to_another_folder_is_followed() {
        // plat3 75408: a new file nobody has seen was swapped onto a synced
        // record's path, and the record's file stands unedited in another
        // folder. Read by the path, the stranger was the record's edit.
        let known = [saw(1, "ring-2/r.txt", "sha-r")];
        let observed = [observed("ring-2/r.txt", 0, "sha-stranger"), observed("Sub/doc.txt", 0, "sha-r")];
        assert!(edited(&pair_with(&known, &observed, &HashSet::new()), 1), "the old reading");
        let out = weak(&known, &observed);
        assert_eq!(moved_to(&out, 1).as_deref(), Some("Sub/doc.txt"));
        assert_eq!(out.created.iter().map(|o| o.path.as_str()).collect::<Vec<_>>(), vec!["ring-2/r.txt"]);
    }

    #[test]
    fn on_a_weak_volume_a_backup_beside_is_still_a_save() {
        let known = [saw(1, "notes.txt", "sha-n")];
        let observed = [observed("notes.txt~", 0, "sha-n"), observed("notes.txt", 0, "sha-n2")];
        let out = weak(&known, &observed);
        assert!(edited(&out, 1), "{:?}", out.changes);
        assert_eq!(out.created.iter().map(|o| o.path.as_str()).collect::<Vec<_>>(), vec!["notes.txt~"]);
    }

    #[test]
    fn on_a_weak_volume_a_safe_save_is_an_edit() {
        let out = weak(&[saw(1, "a.txt", "sha-a")], &[observed("a.txt", 0, "sha-a2")]);
        assert!(edited(&out, 1));
        assert!(out.created.is_empty());
    }

    #[test]
    fn on_a_weak_volume_bytes_two_records_share_name_neither() {
        // A copy: both records last saw the same bytes, so the bytes cannot say
        // which file is whose, and the reading is the path rule's.
        let known = [saw(1, "x/a.txt", "sha-t"), saw(2, "y/b.txt", "sha-t")];
        let observed = [observed("x/a.txt", 0, "sha-q"), observed("z/c.txt", 0, "sha-t"), observed("y/b.txt", 0, "sha-t")];
        assert_eq!(weak(&known, &observed), pair_with(&known, &observed, &HashSet::new()));
    }

    /// What a reading decided, without the fingerprints the two disk kinds
    /// report differently.
    fn decided(out: &ScanOutcome) -> Vec<String> {
        let mut v: Vec<String> = out
            .changes
            .iter()
            .map(|(id, c)| match c {
                LocalChange::Unchanged => format!("{} unchanged", id.server_id),
                LocalChange::Edited { sha256, .. } => format!("{} edited {sha256}", id.server_id),
                LocalChange::Moved { to_path, .. } => format!("{} moved {to_path}", id.server_id),
                LocalChange::MovedAndEdited { to_path, sha256, .. } => format!("{} moved {to_path} edited {sha256}", id.server_id),
                LocalChange::Deleted => format!("{} deleted", id.server_id),
            })
            .chain(out.created.iter().map(|o| format!("new {}", o.path)))
            .collect();
        v.sort();
        v
    }

    #[test]
    fn the_strong_and_weak_readings_agree_on_unedited_files() {
        // One disk, read once by identity and once by bytes (a5's B3: the two
        // step-2 readings drifted apart once, so one fixture holds them
        // together). Each shape an unedited file can take: at home, carried
        // to another folder with a stranger left behind, a backup made
        // beside with the save at the name, a trade inside one folder, a
        // safe-save, and a file gone with another's moved onto its path.
        let (strong_known, strong_seen) = (
            [
                mine(1, "home.txt", 101, 1, "sha-home"),
                mine(2, "ring/r.txt", 102, 2, "sha-r"),
                mine(3, "doc/notes.txt", 103, 3, "sha-n"),
                mine(4, "t/a.txt", 104, 4, "sha-a"),
                mine(5, "t/b.txt", 105, 5, "sha-b"),
                mine(6, "saved.txt", 106, 6, "sha-s"),
                mine(7, "g/r.txt", 107, 7, "sha-gr"),
                mine(8, "g/f.txt", 108, 8, "sha-gf"),
            ],
            [
                seen("home.txt", 101, 1, "sha-home"),
                seen("ring/r.txt", 201, 21, "sha-stranger"),
                seen("Sub/doc.txt", 102, 2, "sha-r"),
                seen("doc/notes.txt~", 103, 3, "sha-n"),
                seen("doc/notes.txt", 202, 22, "sha-n2"),
                seen("t/a.txt", 105, 5, "sha-b"),
                seen("t/b.txt", 104, 4, "sha-a"),
                seen("saved.txt", 203, 23, "sha-s2"),
                seen("g/r.txt", 108, 8, "sha-gf"),
            ],
        );
        let weak_known: Vec<KnownLocal> = strong_known
            .iter()
            .map(|k| KnownLocal {
                fingerprint: Some(fp(0, 10, 100)),
                own_file: None,
                last_seen_sha: k.sha256.clone(),
                ..k.clone()
            })
            .collect();
        let weak_seen: Vec<ObservedFile> =
            strong_seen.iter().map(|o| ObservedFile { fingerprint: fp(0, 10, 100), ..o.clone() }).collect();
        let by_id = by_identity(&strong_known, &strong_seen);
        let by_bytes = weak(&weak_known, &weak_seen);
        assert_eq!(decided(&by_bytes), decided(&by_id));
        assert_eq!(moved_to(&by_bytes, 2).as_deref(), Some("Sub/doc.txt"), "{:?}", decided(&by_bytes));
    }

    #[test]
    fn a_followers_file_at_a_gone_records_path_reads_the_same_on_both_disks() {
        // R's file is gone from the disk; F's unedited file was moved onto R's
        // path. Strong: R deleted, F moved. Weak must agree.
        let strong_known = [mine(1, "d/r.txt", 101, 1, "sha-r"), mine(2, "d/f.txt", 102, 2, "sha-f")];
        let strong_seen = [seen("d/r.txt", 102, 2, "sha-f")];
        let weak_known: Vec<KnownLocal> = strong_known
            .iter()
            .map(|k| KnownLocal { fingerprint: Some(fp(0, 10, 100)), own_file: None, last_seen_sha: k.sha256.clone(), ..k.clone() })
            .collect();
        let weak_seen: Vec<ObservedFile> =
            strong_seen.iter().map(|o| ObservedFile { fingerprint: fp(0, 10, 100), ..o.clone() }).collect();
        let by_id = by_identity(&strong_known, &strong_seen);
        let by_bytes = weak(&weak_known, &weak_seen);
        assert_eq!(decided(&by_id), vec!["1 deleted", "2 moved d/r.txt"]);
        assert_eq!(decided(&by_bytes), decided(&by_id));
    }

    #[test]
    fn a_claimant_held_source_does_not_take_a_stranger_at_its_old_path_on_a_positional_disk() {
        // A source whose file a claimant holds (`held`): its path proves
        // nothing, and with no ids nothing can bring the file back to it by
        // identity. A file at its old name is new, never its edit -- read as
        // one, the source was alive again, the claimant's hold lapsed, and
        // the stranger went up as the source's version (a5, retracting B4).
        let source = KnownLocal { held: true, last_seen_sha: Some("sha-a".into()), ..known(1, "plain/a.txt", 0, "sha-a") };
        let observed = [observed("plain/a.txt", 0, "sha-stranger")];
        let out = weak(&[source], &observed);
        assert!(!edited(&out, 1), "{:?}", decided(&out));
        assert_eq!(out.created.iter().map(|o| o.path.as_str()).collect::<Vec<_>>(), vec!["plain/a.txt"]);
    }

    #[test]
    fn a_file_held_outside_its_vault_edited_in_place_on_a_positional_disk_is_its_edit() {
        // A sealed file held outside its vault (D1, `server_home`): with no ids
        // the path is all it has, and the file at it is its own, edited.
        let held = KnownLocal {
            server_home: Some("Private/a.txt".into()),
            last_seen_sha: Some("sha-a".into()),
            ..known(1, "plain/a.txt", 0, "sha-a")
        };
        let observed = [observed("plain/a.txt", 0, "sha-a2")];
        let out = weak(&[held], &observed);
        assert!(edited(&out, 1), "{:?}", decided(&out));
        assert!(out.created.is_empty(), "{:?}", decided(&out));
    }

    #[test]
    fn a_stale_last_seen_after_a_download_does_not_follow_a_copy() {
        // The record agreed on B (a download landed it); last_seen still says
        // A from before. The user's own copy of the old bytes stands in another
        // folder. R is at home with B; the copy is new.
        let r = KnownLocal { last_seen_sha: Some("sha-a".into()), ..known(1, "d/r.txt", 0, "sha-b") };
        let observed = [observed("d/r.txt", 0, "sha-b"), observed("old/r-copy.txt", 0, "sha-a")];
        let out = weak(&[r], &observed);
        assert_eq!(out.change_for(EntityId::file(1)), Some(&LocalChange::Unchanged), "{:?}", decided(&out));
        assert_eq!(out.created.iter().map(|o| o.path.as_str()).collect::<Vec<_>>(), vec!["old/r-copy.txt"]);
    }

    #[test]
    fn a_claimants_source_following_its_file_gets_one_change() {
        // The source handed its file to a claimant and has no identity of its
        // own; the claimant's file stands somewhere else, so the source follows
        // it. That is the source's one change: read again by the weak rules it
        // was also Deleted (hostile2 74424).
        let source = KnownLocal { own_file: None, ..known(1, "Plain/s.txt", 100, "sha-s") };
        let claimant = KnownLocal {
            claimant_for: Some(EntityId::file(1)),
            sha256: None,
            fingerprint: None,
            ..mine(-1, "Private/s.txt", 100, 1, "")
        };
        let out = by_identity(&[source, claimant], &[seen("Plain/moved.txt", 100, 1, "sha-s")]);
        let for_source: Vec<_> = out.changes.iter().filter(|(id, _)| *id == EntityId::file(1)).collect();
        assert_eq!(for_source.len(), 1, "{:?}", out.changes);
        assert_eq!(moved_to(&out, 1).as_deref(), Some("Plain/moved.txt"));
    }

    #[test]
    fn an_empty_tree_against_no_entries_produces_nothing() {
        let out = pair(&[], &[]);
        assert!(out.changes.is_empty() && out.created.is_empty());
    }
}

#[cfg(test)]
mod tie_break_tests {
    use super::*;

    fn fp0() -> jd_vfs::Fingerprint {
        jd_vfs::Fingerprint { size: 10, mtime_ns: 100, file_id: 0, birth_ns: 0 }
    }

    fn rec(id: i64, path: &str, seen: &str, tie_break: u64) -> KnownLocal {
        KnownLocal {
            id: EntityId::file(id),
            path: path.into(),
            fingerprint: Some(fp0()),
            sha256: Some(seen.into()),
            server_deleted: false,
            held: false,
            server_home: None,
            server_path: None,
            own_file: None,
            claimant_for: None,
            last_seen_sha: Some(seen.into()),
            tie_break,
        }
    }

    fn file(path: &str, sha: &str, tie_break_id: u64) -> ObservedFile {
        ObservedFile { path: path.into(), fingerprint: fp0(), sha256: sha.into(), tie_break_id }
    }

    /// Two files whose bytes each also stand as another record's copy, and
    /// each file now standing at the other's path with the other's id. With
    /// the ids in force the two are told apart and each follows its file;
    /// with none, each is read by its path as the other's edit.
    fn a_copy_swap(tie_breaks: &HashMap<EntityId, u64>) -> ScanOutcome {
        let tb = |id: i64| tie_breaks.get(&EntityId::file(id)).copied().unwrap_or(0);
        let known = [
            rec(1, "A/one.txt", "sha-x", tb(1)),
            rec(2, "A/two.txt", "sha-y", tb(2)),
            rec(3, "B/one-copy.txt", "sha-x", 0),
            rec(4, "B/two-copy.txt", "sha-y", 0),
        ];
        let seen = [
            file("A/one.txt", "sha-y", 9),
            file("A/two.txt", "sha-x", 7),
            file("B/one-copy.txt", "sha-x", 0),
            file("B/two-copy.txt", "sha-y", 0),
        ];
        pair_files(&known, &seen, &HashSet::new(), false)
    }

    fn crossed(out: &ScanOutcome) -> bool {
        matches!(out.change_for(EntityId::file(1)), Some(LocalChange::Moved { to_path, .. }) if to_path == "A/two.txt")
    }

    fn read_by_path(out: &ScanOutcome) -> bool {
        matches!(out.change_for(EntityId::file(1)), Some(LocalChange::Edited { .. }))
            && matches!(out.change_for(EntityId::file(2)), Some(LocalChange::Edited { .. }))
    }

    fn recorded(session: i64) -> HashMap<EntityId, (u64, i64)> {
        [(EntityId::file(1), (7, session)), (EntityId::file(2), (9, session))].into_iter().collect()
    }

    #[test]
    fn a_copy_swap_is_told_apart_by_ids_read_in_this_mount_session() {
        let holds = tie_breaks_that_hold(jd_vfs::IdTieBreak::MountSession, 4, &recorded(4), &HashMap::new());
        assert_eq!(holds.len(), 2);
        assert!(crossed(&a_copy_swap(&holds)), "{:?}", a_copy_swap(&holds).changes);
    }

    /// O2, with no file to notice: ids read before a remount are void on a
    /// mount-session volume, whatever they are. Numbers handed out again after
    /// a remount would otherwise tell the two apart the wrong way round. RED
    /// with the session comparison forced equal.
    #[test]
    fn ids_read_in_another_mount_session_break_no_tie() {
        let holds = tie_breaks_that_hold(jd_vfs::IdTieBreak::MountSession, 5, &recorded(4), &HashMap::new());
        assert!(holds.is_empty(), "{holds:?}");
        let out = a_copy_swap(&holds);
        assert!(!crossed(&out) && read_by_path(&out), "{:?}", out.changes);
    }

    /// A durable volume keeps its ids through a remount...
    #[test]
    fn a_durable_id_holds_across_mount_sessions() {
        let holds = tie_breaks_that_hold(jd_vfs::IdTieBreak::Durable, 5, &recorded(4), &HashMap::new());
        assert_eq!(holds.len(), 2);
    }

    /// ...until the disk says it has gone stale (O1): a file standing unedited
    /// at its own path under another id -- clusters another machine reused --
    /// voids every id, and the pass reads by path. RED without that check.
    #[test]
    fn a_durable_id_the_disk_has_moved_breaks_no_tie() {
        let moved: HashMap<EntityId, u64> = [(EntityId::file(3), 11)].into_iter().collect();
        let mut recorded = recorded(4);
        recorded.insert(EntityId::file(3), (5, 4));
        let holds = tie_breaks_that_hold(jd_vfs::IdTieBreak::Durable, 5, &recorded, &moved);
        assert!(holds.is_empty(), "{holds:?}");
        let out = a_copy_swap(&holds);
        assert!(!crossed(&out) && read_by_path(&out), "{:?}", out.changes);
    }
}
