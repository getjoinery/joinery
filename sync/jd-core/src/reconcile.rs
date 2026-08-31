//! The decision that matters: given what each side did since they last agreed,
//! what should happen?
//!
//! This is a pure function. No filesystem, no network, no clock — feed it three
//! states and it tells you the actions. That is deliberate: this is the code
//! that decides whether somebody's file gets deleted, and it should be possible
//! to enumerate its behavior exhaustively in a test suite that runs in
//! milliseconds, rather than inferring it from a system with a network in it.
//!
//! Two rules run underneath the whole table, and they are worth stating before
//! the mechanics, because every awkward case resolves by appealing to them:
//!
//! **An edit always beats a delete, in both directions.** They are not
//! symmetric outcomes. A delete that loses can be recovered — the server keeps
//! trash and version history, the local side goes to the OS trash. An edit that
//! loses is gone. So when the two collide, the edit survives, even though that
//! means occasionally resurrecting a file somebody meant to remove. The
//! recoverable mistake is the one to make.
//!
//! **Nothing is ever adopted on a fingerprint's word.** Whenever the engine
//! concludes "these two are the same, no transfer needed", that conclusion
//! comes from comparing content hashes. Sizes and modification times decide
//! only whether it is worth hashing.

use crate::model::{Delta, Entry, Placement};

/// One thing to do. The executor turns these into API calls and filesystem
/// operations; the reconciler never performs them itself.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Action {
    /// Fetch the remote content and materialize it locally.
    Download,
    /// Send the local content as a new version of the existing entry.
    UploadVersion,
    /// Create a *new* server entry from local content. Used when the entry the
    /// local content belonged to is gone server-side — the old id cannot be
    /// resurrected, so the rescued content arrives as a new file.
    UploadAsNew { placement: Placement },
    /// Create the folder on the server.
    CreateRemoteFolder { placement: Placement },
    /// Create the folder on this computer.
    CreateLocalFolder { placement: Placement },
    /// Move/rename the local file to match the server.
    ApplyRemoteMove { to: Placement },
    /// Move/rename on the server to match local.
    ApplyLocalMove { to: Placement },
    /// Send the local file to the OS trash.
    TrashLocal,
    /// Trash the entry on the server.
    TrashRemote,
    /// Preserve the losing local content beside the canonical file under this
    /// name, then upload it as a new entry. The remote head keeps the path the
    /// user knows.
    PreserveLocalAs { name: String },
    /// Both sides removed it. Drop the entry; there is nothing left to track.
    Forget,
    /// The two sides already agree. Record the agreement (last-agreed state
    /// advances) and move no bytes.
    Adopt,
    /// Both sides moved it to the same place. Record where it now lives and
    /// move nothing.
    AdoptPlacement { to: Placement },
    /// Stop materializing this locally, but keep tracking it.
    RemoveFromScope,
    /// Give up the local copy and park the entry, because this filesystem
    /// cannot hold the name the server has given it.
    ///
    /// A park has always implied "no file of this entry is on this disk" --
    /// `competing_placement`, the scan's reserved set and the convergence
    /// oracle all read it that way. An entry that is ALREADY materialized and
    /// then loses its name would break that implication, and a status flip
    /// would leave the file stranded under a name the server no longer has.
    /// So the local copy goes first and the park is recorded when it is gone.
    UnmaterializeAndPark { reason: jd_vfs::UnsyncableReason },
}

/// Something the user should be told about. Issues are never fatal and never
/// silent — they accumulate in a panel with a reason a person can act on.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Issue {
    /// Both sides changed the same file. Both versions survive; this says so.
    ConflictResolved {
        kept_remote: String,
        local_preserved_as: String,
    },
    /// Both sides moved it somewhere different. The server's placement wins so
    /// that every device agrees, and the user is told which one lost.
    MoveRaceServerWon { local_wanted: Placement },
    /// A delete was overridden because the other side had edited the file.
    DeleteLostToEdit { side: Side },
    /// A folder was removed remotely but held local edits; those were rescued
    /// to new server entries rather than going down with it.
    RescuedFromDeletedFolder { count: usize },
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Side {
    Local,
    Remote,
}

/// What to do about one entry, and what to tell the user.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Resolution {
    pub actions: Vec<Action>,
    pub issues: Vec<Issue>,
}

impl Resolution {
    fn nothing() -> Self {
        Resolution::default()
    }
    fn just(action: Action) -> Self {
        Resolution {
            actions: vec![action],
            issues: Vec::new(),
        }
    }
    fn with_issue(mut self, issue: Issue) -> Self {
        self.issues.push(issue);
        self
    }
    pub fn is_empty(&self) -> bool {
        self.actions.is_empty()
    }
}

/// Everything the decision needs that is not a delta: what to call a conflict
/// copy, and which suffix to use when today already has one.
#[derive(Debug, Clone)]
pub struct Context {
    /// Today, `YYYY-MM-DD`, for conflict-copy names. Passed in rather than
    /// read from a clock so a simulated run is reproducible.
    pub date: String,
    /// This device's name, as the user set it on the security page. It appears
    /// in conflict-copy names so "which machine did this come from" is
    /// answerable without guessing.
    pub device_name: String,
    /// Disambiguator when a name is already taken.
    pub conflict_suffix: u32,
    /// What this computer's filesystem will and will not do.
    ///
    /// Carried here because "do these two entries want the same slot" has a
    /// different answer on each platform: `Report.txt` and `report.txt` are two
    /// files on Linux and one on macOS. Deciding that with a hardcoded
    /// personality means the ordering stage plans two moves into one slot on
    /// every Mac and every PC, and the second one silently replaces the first.
    pub personality: jd_vfs::Personality,
}

/// Work out what to do with one entry.
///
/// `entry` carries the last-agreed state; `local` and `remote` are what each
/// side did since. The result is ordered: placement changes come before content
/// transfers, because uploading to a path that is about to move is wasted work.
pub fn reconcile(entry: &Entry, local: &Delta, remote: &Delta, ctx: &Context) -> Resolution {
    // Deletes are not just another value on the content axis — they interact
    // with the other side's changes by rules of their own, so they branch out
    // first.
    if local.is_delete() || remote.is_delete() {
        return reconcile_with_delete(entry, local, remote);
    }

    // A creation is not a move. An entity that does not yet exist on the other
    // side needs MAKING there, not relocating — and the placement axis below,
    // which compares where a thing was against where it is, would read the
    // brand-new placement as a relocation of something that was never there.
    // Only short-circuit when the other side has nothing to say; a creation
    // meeting a creation is a genuine two-sided case and falls through to the
    // content axis, where hash-equal adopts and different conflicts.
    if let Delta::Created { placement, content } = local {
        if remote.is_none() {
            return match entry.id.entity_type {
                crate::model::EntityType::Folder => Resolution::just(Action::CreateRemoteFolder {
                    placement: placement.clone(),
                }),
                crate::model::EntityType::File => {
                    let _ = content;
                    Resolution::just(Action::UploadAsNew {
                        placement: placement.clone(),
                    })
                }
            };
        }
    }
    if let Delta::Created { placement, .. } = remote {
        if local.is_none() {
            return match entry.id.entity_type {
                crate::model::EntityType::Folder => Resolution::just(Action::CreateLocalFolder {
                    placement: placement.clone(),
                }),
                crate::model::EntityType::File => Resolution::just(Action::Download),
            };
        }
    }

    let mut res = Resolution::nothing();

    // ---- placement ---------------------------------------------------------
    // Independent of content, because identity is the server id: a file can be
    // moved on one side and edited on the other without those two decisions
    // having anything to say to each other.
    match (local.placement(), remote.placement()) {
        (Some(l), Some(r)) => {
            if l != r {
                // Both moved, to different places. Somebody has to win, and it
                // has to be the same winner on every device — so it is the
                // server, always, and the user is told.
                res.actions.push(Action::ApplyRemoteMove { to: r.clone() });
                res.issues.push(Issue::MoveRaceServerWon {
                    local_wanted: l.clone(),
                });
            }
            // Same target: they agree. Nothing to move — but the agreement
            // still has to be written down, and that is not a formality.
            //
            // The last-agreed placement is what the engine believes about where
            // this file sits on this disk. Every later pass reads it: the scan
            // looks for the file there, and naming counts it as a sibling of
            // whatever else is in that folder. Leaving it pointing at the folder
            // the file has left means the entry holds a name it is not using,
            // and any real file that wants that name is parked
            // `Unsyncable(DuplicateName)` against a rival that is not there —
            // permanently, because nothing about a settled tree ever changes to
            // release it. The soak sweep had it as a file that simply never
            // appeared on one device out of three.
            //
            // Only when the record is actually out of date. Two sides creating
            // the same path have no agreement to correct, and the content axis
            // below establishes one either way.
            else if entry.synced_placement.as_ref().is_some_and(|p| p != r) {
                res.actions.push(Action::AdoptPlacement { to: r.clone() });
            }
        }
        (Some(l), None) => res.actions.push(Action::ApplyLocalMove { to: l.clone() }),
        (None, Some(r)) => res.actions.push(Action::ApplyRemoteMove { to: r.clone() }),
        (None, None) => {}
    }

    // ---- content -----------------------------------------------------------
    match (local.content(), remote.content()) {
        (Some(l), Some(r)) => {
            if l.sha256 == r.sha256 {
                // Both sides arrived at identical bytes — two people saving the
                // same download, or the same edit applied twice. Not a conflict;
                // there is nothing to reconcile. Record the agreement and move
                // nothing.
                //
                // This can never fire for an encrypted file, and deliberately
                // so: the two hashes are of plaintext and of ciphertext, and
                // encrypting the same bytes twice produces different ciphertext,
                // so there is no honest way to notice that both sides agree. The
                // cost is a conflict copy nobody needed; the alternative — re-
                // encrypting to compare — cannot work at all.
                res.actions.push(Action::Adopt);
            } else {
                // A real conflict. Nothing is overwritten: the remote head keeps
                // the path the user knows, and the local version lands beside it
                // under a name that says where it came from.
                let copy = jd_vfs::conflict_copy_name(
                    &remote_display_name(entry, remote),
                    &ctx.date,
                    &ctx.device_name,
                    ctx.conflict_suffix,
                );
                res.actions
                    .push(Action::PreserveLocalAs { name: copy.clone() });
                res.actions.push(Action::Download);
                res.issues.push(Issue::ConflictResolved {
                    kept_remote: remote_display_name(entry, remote),
                    local_preserved_as: copy,
                });
            }
        }
        (Some(_), None) => res.actions.push(Action::UploadVersion),
        (None, Some(_)) => res.actions.push(Action::Download),
        (None, None) => {}
    }

    res
}

/// The delete rules. Separated out because "one side removed it" is not a value
/// on the same scale as "one side changed it" — the question is always what the
/// *other* side did, and whether losing that would be recoverable.
fn reconcile_with_delete(entry: &Entry, local: &Delta, remote: &Delta) -> Resolution {
    match (local, remote) {
        // Both agree it is gone. Nothing to do but stop tracking it.
        (Delta::Deleted, Delta::Deleted) => Resolution::just(Action::Forget),

        // Deleted here, untouched there: carry the delete to the server, where
        // it lands in the trash and stays recoverable.
        (Delta::Deleted, Delta::None) => Resolution::just(Action::TrashRemote),

        // Deleted here, edited there. The edit wins: bring it back. The delete
        // is recoverable from the OS trash if it really was intended; the edit
        // would not have been.
        (Delta::Deleted, r) if r.touched_content() => {
            Resolution::just(restore_locally(entry, r))
                .with_issue(Issue::DeleteLostToEdit { side: Side::Local })
        }

        // Deleted here, moved there. A move proves someone is working with the
        // file, but it is not an edit — so the delete may proceed, and only if
        // the content is exactly what we last agreed on. If the bytes moved
        // too, there is an edit hiding behind the move and the edit wins.
        (Delta::Deleted, r @ Delta::Moved { .. }) => {
            if content_matches_last_agreement(entry) {
                Resolution::just(Action::TrashRemote)
            } else {
                Resolution::just(restore_locally(entry, r))
                    .with_issue(Issue::DeleteLostToEdit { side: Side::Local })
            }
        }

        // Gone there, untouched here: remove it locally — to the OS trash, so a
        // wrong call costs a trip to the trash rather than a file.
        //
        // Unless it was never here to remove. An entity this device heard about
        // but never materialized — the download had not run yet, or was still
        // waiting behind something — has nothing on disk to trash, and asking
        // for one is not merely wasted work: the executor finds no file, calls
        // the operation overtaken and drops it, and the entry is left exactly as
        // it was. So the next pass plans the same trash, and the pass after
        // that, while the entry sits in `pending_download` forever waiting for
        // bytes the server no longer has.
        //
        // It costs nothing visible and never stops. The soak rig read it the
        // only way it could — a device with entries still in flight has not
        // settled — and failed convergence on every cycle of every campaign for
        // as long as the rig has existed, over six files nobody could see.
        (Delta::None, Delta::Deleted) => {
            if entry.is_established() {
                Resolution::just(Action::TrashLocal)
            } else {
                Resolution::just(Action::Forget)
            }
        }

        // Gone there, edited here. Edit wins. The old server entry is gone and
        // cannot be resurrected under its id, so the rescued content goes up as
        // a new file at the place it was last known to live.
        (l, Delta::Deleted) if l.touched_content() => {
            let placement = l
                .placement()
                .cloned()
                .or_else(|| entry.synced_placement.clone())
                .unwrap_or_else(|| entry.remote.clone());
            Resolution::just(restore_remotely(entry, placement))
                .with_issue(Issue::DeleteLostToEdit { side: Side::Remote })
        }

        // Gone there, moved here. The content is untouched, but the user did
        // something deliberate with it, so it is re-created where they put it
        // rather than vanishing out from under them.
        (Delta::Moved { to }, Delta::Deleted) => {
            Resolution::just(restore_remotely(entry, to.clone()))
                .with_issue(Issue::DeleteLostToEdit { side: Side::Remote })
        }

        // Any remaining shape reduces to one of the above; treat an unexpected
        // pairing as "do nothing" rather than improvising a destructive guess.
        _ => Resolution::nothing(),
    }
}

/// Bring an entity back on this computer after a local delete lost.
///
/// A folder is not restored by fetching anything — there is nothing to fetch,
/// and asking for it is not a slow path but a dead one: the executor withdraws
/// the download for want of a content hash, the folder keeps whatever name it
/// had on disk before the delete, and every sibling waiting for that name stays
/// parked behind it forever. The soak rig held a whole subtree that way, with a
/// second folder parked `DuplicateName` against a name whose owner the server
/// had long since renamed.
///
/// The destination is the server's, since it is the server's version that won.
fn restore_locally(entry: &Entry, remote: &Delta) -> Action {
    match entry.id.entity_type {
        crate::model::EntityType::File => Action::Download,
        crate::model::EntityType::Folder => Action::CreateLocalFolder {
            placement: remote
                .placement()
                .cloned()
                .unwrap_or_else(|| entry.remote.clone()),
        },
    }
}

/// Put an entity back on the server after a remote delete lost.
///
/// The counterpart of [`restore_locally`], and the same distinction: a folder
/// has no content to send as a new version, so it is made rather than uploaded.
/// Either way the old server id is gone and cannot be resurrected, so what
/// arrives is a new entity at the place the entity was last known to live.
fn restore_remotely(entry: &Entry, placement: Placement) -> Action {
    match entry.id.entity_type {
        crate::model::EntityType::File => Action::UploadAsNew { placement },
        crate::model::EntityType::Folder => Action::CreateRemoteFolder { placement },
    }
}

/// Is the local content still exactly what both sides last agreed on?
///
/// This is the guard that lets a delete proceed against a remote move. Note
/// what it does when the answer is unknown: an entry with no recorded agreement
/// returns false, so the delete does *not* proceed. Uncertainty resolves toward
/// keeping the file.
fn content_matches_last_agreement(entry: &Entry) -> bool {
    // Both sides of this comparison have to be in the same domain. The server's
    // answer for an encrypted file is a ciphertext hash, so it is held against
    // the ciphertext hash from the last sync — comparing it with the plaintext
    // one would answer "no" for every encrypted file that ever existed, and
    // silently turn this guard into a blanket refusal.
    let agreed = if entry.is_encrypted {
        entry.synced_remote_content.as_ref()
    } else {
        entry.synced_content.as_ref()
    };
    match (agreed, &entry.remote_content) {
        (Some(synced), Some(remote)) => synced.sha256 == remote.sha256,
        _ => false,
    }
}

/// The name to base a conflict copy on: whatever the file is currently called
/// on the side that keeps the canonical path.
fn remote_display_name(entry: &Entry, remote: &Delta) -> String {
    remote
        .placement()
        .map(|p| p.name.clone())
        .unwrap_or_else(|| entry.remote.name.clone())
}

/// Would this round of deletes be a catastrophe rather than an intention?
///
/// A ransomware run, an unmounted volume that reads as an empty tree, a sync
/// root somebody moved — all of them look identical to "the user deleted
/// everything", and the engine cannot tell them apart. So it does not try: past
/// a threshold, deletes stop and a person is asked. The cost of asking is one
/// dialog; the cost of not asking is the user's files.
pub fn is_mass_delete(delete_count: usize, synced_total: usize) -> bool {
    if delete_count == 0 {
        return false;
    }
    let floor = 50;
    let proportional = synced_total / 4; // 25%
    delete_count > floor.max(proportional)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::model::{ContentId, EntityId, LocalStatus};

    fn content(sha: &str, size: u64) -> ContentId {
        ContentId {
            sha256: sha.into(),
            size,
        }
    }

    fn placement(parent: Option<i64>, name: &str) -> Placement {
        Placement {
            parent,
            name: name.into(),
        }
    }

    /// An entry that has completed a sync: both sides agreed on `sha` at `name`.
    fn established(name: &str, sha: &str) -> Entry {
        Entry {
            id: EntityId::file(1),
            remote: placement(None, name),
            remote_content: Some(content(sha, 10)),
            remote_modified_time: None,
            head_change_id: 5,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: Some(content(sha, 10)),
            synced_placement: Some(placement(None, name)),
            synced_fingerprint: None,
            local_name: None,
            status: LocalStatus::Synced,
            wrapped_file_key: None,
        }
    }

    fn ctx() -> Context {
        Context {
            date: "2026-07-16".into(),
            device_name: "MacBook".into(),
            conflict_suffix: 1,
            personality: jd_vfs::Personality::linux(),
        }
    }

    // ---- creations are not moves -------------------------------------------

    #[test]
    fn a_locally_created_folder_is_created_on_the_server_not_moved() {
        // The trap: a creation carries a placement, and comparing "where it is"
        // against "where it was" reads that placement as a relocation — of
        // something that has never existed on the other side. The result would
        // be a rename call against an id the server does not have.
        let mut e = established("New Folder", "");
        e.id = EntityId::folder(3);
        e.synced_content = None;
        e.synced_placement = None;

        let r = reconcile(
            &e,
            &Delta::Created {
                placement: placement(None, "New Folder"),
                content: None,
            },
            &Delta::None,
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::CreateRemoteFolder {
                placement: placement(None, "New Folder")
            }]
        );
    }

    #[test]
    fn a_remotely_created_folder_is_created_locally() {
        let mut e = established("Shared", "");
        e.id = EntityId::folder(4);
        e.synced_content = None;
        e.synced_placement = None;

        let r = reconcile(
            &e,
            &Delta::None,
            &Delta::Created {
                placement: placement(None, "Shared"),
                content: None,
            },
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::CreateLocalFolder {
                placement: placement(None, "Shared")
            }]
        );
    }

    #[test]
    fn a_locally_created_file_is_uploaded_as_a_new_entry() {
        let mut e = established("fresh.txt", "sha");
        e.synced_content = None;
        e.synced_placement = None;

        let r = reconcile(
            &e,
            &Delta::Created {
                placement: placement(None, "fresh.txt"),
                content: Some(content("sha-fresh", 5)),
            },
            &Delta::None,
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::UploadAsNew {
                placement: placement(None, "fresh.txt")
            }]
        );
    }

    #[test]
    fn two_creations_of_the_same_content_adopt_rather_than_conflict() {
        // Both sides made the same file — the same download saved twice. Not a
        // conflict; there is nothing to reconcile.
        let mut e = established("same.txt", "sha");
        e.synced_content = None;
        e.synced_placement = None;
        let same = content("identical", 9);

        let r = reconcile(
            &e,
            &Delta::Created {
                placement: placement(None, "same.txt"),
                content: Some(same.clone()),
            },
            &Delta::Created {
                placement: placement(None, "same.txt"),
                content: Some(same),
            },
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::Adopt]);
        assert!(r.issues.is_empty());
    }

    #[test]
    fn two_different_creations_at_one_path_keep_both() {
        let mut e = established("clash.txt", "sha");
        e.synced_content = None;
        e.synced_placement = None;

        let r = reconcile(
            &e,
            &Delta::Created {
                placement: placement(None, "clash.txt"),
                content: Some(content("mine", 1)),
            },
            &Delta::Created {
                placement: placement(None, "clash.txt"),
                content: Some(content("theirs", 2)),
            },
            &ctx(),
        );
        assert!(matches!(r.actions[0], Action::PreserveLocalAs { .. }));
        assert_eq!(r.actions[1], Action::Download);
        assert!(matches!(r.issues[0], Issue::ConflictResolved { .. }));
    }

    // ---- the quiet cases ---------------------------------------------------

    #[test]
    fn nothing_happened_means_nothing_happens() {
        let e = established("a.txt", "aaa");
        assert!(reconcile(&e, &Delta::None, &Delta::None, &ctx()).is_empty());
    }

    #[test]
    fn a_remote_edit_downloads() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::None,
            &Delta::Edited {
                content: content("bbb", 12),
            },
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::Download]);
    }

    #[test]
    fn a_local_edit_uploads_a_version() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Edited {
                content: content("bbb", 12),
            },
            &Delta::None,
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::UploadVersion]);
    }

    // ---- independence of the two axes --------------------------------------

    #[test]
    fn a_remote_move_and_a_local_edit_compose_without_conflict() {
        // The whole point of keying on server id: these two changes have nothing
        // to say to each other, so both apply.
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Edited {
                content: content("bbb", 12),
            },
            &Delta::Moved {
                to: placement(Some(9), "a.txt"),
            },
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![
                Action::ApplyRemoteMove {
                    to: placement(Some(9), "a.txt")
                },
                Action::UploadVersion,
            ]
        );
        assert!(r.issues.is_empty(), "composing is not a conflict");
    }

    #[test]
    fn moves_are_applied_before_content_transfers() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::None,
            &Delta::MovedAndEdited {
                to: placement(Some(3), "b.txt"),
                content: content("ccc", 4),
            },
            &ctx(),
        );
        assert!(matches!(r.actions[0], Action::ApplyRemoteMove { .. }));
        assert_eq!(r.actions[1], Action::Download);
    }

    #[test]
    fn a_local_move_renames_on_the_server() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Moved {
                to: placement(None, "renamed.txt"),
            },
            &Delta::None,
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::ApplyLocalMove {
                to: placement(None, "renamed.txt")
            }]
        );
    }

    #[test]
    fn both_sides_moving_to_the_same_place_is_agreement_not_a_race() {
        let e = established("a.txt", "aaa");
        let target = placement(Some(4), "same.txt");
        let r = reconcile(
            &e,
            &Delta::Moved { to: target.clone() },
            &Delta::Moved { to: target.clone() },
            &ctx(),
        );
        // Agreeing is not a conflict, and nothing is moved. But agreeing is
        // also not nothing: unless the new placement is written down, the
        // entry goes on claiming a name in the folder it has left.
        assert_eq!(r.actions, vec![Action::AdoptPlacement { to: target }]);
        assert!(r.issues.is_empty());
    }

    #[test]
    fn a_file_both_sides_moved_the_same_way_stops_claiming_its_old_name() {
        // The shape the soak sweep found: one device's user moves a file into
        // another folder, and the move reaches the server. The device then sees
        // its own move and the server's as the same move, agrees, and — before
        // this — left its record pointing at the folder the file came from.
        //
        // What made that fatal rather than untidy is that the stale record is a
        // claim on a name. A different file legitimately arriving at the old
        // path finds a rival that is not there, and is parked against it for
        // good.
        let e = established("contested.txt", "aaa");
        let moved = placement(Some(12), "contested.txt");
        let r = reconcile(
            &e,
            &Delta::Moved { to: moved.clone() },
            &Delta::Moved { to: moved.clone() },
            &ctx(),
        );
        match r.actions.as_slice() {
            [Action::AdoptPlacement { to }] => assert_eq!(*to, moved),
            other => panic!("the agreement was never recorded: {other:?}"),
        }
    }

    #[test]
    fn a_move_race_is_settled_by_the_server_and_reported() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Moved {
                to: placement(Some(1), "mine.txt"),
            },
            &Delta::Moved {
                to: placement(Some(2), "theirs.txt"),
            },
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::ApplyRemoteMove {
                to: placement(Some(2), "theirs.txt")
            }]
        );
        // Deterministic across devices, and the user is told which lost.
        assert!(matches!(r.issues[0], Issue::MoveRaceServerWon { .. }));
    }

    // ---- conflicts ---------------------------------------------------------

    #[test]
    fn identical_edits_on_both_sides_are_adopted_not_conflicted() {
        let e = established("a.txt", "aaa");
        let same = content("bbb", 12);
        let r = reconcile(
            &e,
            &Delta::Edited {
                content: same.clone(),
            },
            &Delta::Edited { content: same },
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::Adopt]);
        assert!(
            r.issues.is_empty(),
            "agreeing is not worth interrupting for"
        );
    }

    #[test]
    fn a_real_conflict_keeps_both_versions() {
        let e = established("Report.xlsx", "aaa");
        let r = reconcile(
            &e,
            &Delta::Edited {
                content: content("local", 1),
            },
            &Delta::Edited {
                content: content("remote", 2),
            },
            &ctx(),
        );
        // The local version is preserved FIRST, then the canonical path is
        // restored from the server. Downloading first would overwrite the local
        // bytes before they had been saved anywhere.
        assert_eq!(
            r.actions,
            vec![
                Action::PreserveLocalAs {
                    name: "Report (conflicted copy 2026-07-16 from MacBook).xlsx".into()
                },
                Action::Download,
            ]
        );
        assert!(matches!(r.issues[0], Issue::ConflictResolved { .. }));
    }

    #[test]
    fn conflict_copies_are_named_after_the_current_remote_name() {
        // The file was renamed remotely and edited locally: the copy should be
        // named after what the file is called now, not what it used to be.
        let e = established("old.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Edited {
                content: content("local", 1),
            },
            &Delta::MovedAndEdited {
                to: placement(None, "new.txt"),
                content: content("remote", 2),
            },
            &ctx(),
        );
        let preserved = r.actions.iter().find_map(|a| match a {
            Action::PreserveLocalAs { name } => Some(name.clone()),
            _ => None,
        });
        assert_eq!(
            preserved.unwrap(),
            "new (conflicted copy 2026-07-16 from MacBook).txt"
        );
    }

    // ---- edit beats delete, in both directions -----------------------------

    #[test]
    fn a_local_delete_loses_to_a_remote_edit() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Deleted,
            &Delta::Edited {
                content: content("bbb", 12),
            },
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::Download]);
        assert_eq!(
            r.issues,
            vec![Issue::DeleteLostToEdit { side: Side::Local }]
        );
    }

    #[test]
    fn a_remote_delete_loses_to_a_local_edit_and_the_content_goes_up_as_new() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Edited {
                content: content("bbb", 12),
            },
            &Delta::Deleted,
            &ctx(),
        );
        // The old server entry is gone; its id cannot come back, so the rescued
        // bytes arrive as a new file where the old one lived.
        assert_eq!(
            r.actions,
            vec![Action::UploadAsNew {
                placement: placement(None, "a.txt")
            }]
        );
        assert_eq!(
            r.issues,
            vec![Issue::DeleteLostToEdit { side: Side::Remote }]
        );
    }

    #[test]
    fn an_uncontested_delete_propagates_each_way() {
        let e = established("a.txt", "aaa");
        assert_eq!(
            reconcile(&e, &Delta::Deleted, &Delta::None, &ctx()).actions,
            vec![Action::TrashRemote]
        );
        assert_eq!(
            reconcile(&e, &Delta::None, &Delta::Deleted, &ctx()).actions,
            vec![Action::TrashLocal]
        );
    }

    #[test]
    fn a_delete_of_something_never_materialized_here_is_forgotten_not_trashed() {
        // The server mentioned a file, this device recorded it, and the download
        // had not run when the server trashed it. There is nothing on this disk
        // to put in the trash.
        //
        // Asking for one anyway is not harmless. The executor finds no file,
        // reports the operation overtaken and drops it, and nothing touches the
        // entry — so the next pass plans the same trash, and every pass after
        // it, while the entry stays in `pending_download` for good. It never
        // errors and never finishes, and a client with one of these never
        // reports itself settled again.
        let mut e = established("doc-13.txt", "aaa");
        e.synced_content = None;
        e.synced_placement = None;
        e.status = LocalStatus::PendingDownload;
        assert!(!e.is_established(), "the premise: it never landed here");

        assert_eq!(
            reconcile(&e, &Delta::None, &Delta::Deleted, &ctx()).actions,
            vec![Action::Forget],
            "nothing to trash, so the record goes rather than the loop starting"
        );
    }

    #[test]
    fn both_sides_deleting_just_forgets_the_entry() {
        let e = established("a.txt", "aaa");
        assert_eq!(
            reconcile(&e, &Delta::Deleted, &Delta::Deleted, &ctx()).actions,
            vec![Action::Forget]
        );
    }

    // ---- delete vs move: the subtle one ------------------------------------

    #[test]
    fn a_delete_proceeds_against_a_pure_remote_move() {
        // The file moved but its bytes are exactly what we last agreed on, so
        // nothing would be lost by honoring the delete.
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Deleted,
            &Delta::Moved {
                to: placement(Some(2), "a.txt"),
            },
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::TrashRemote]);
    }

    #[test]
    fn a_delete_loses_to_a_move_that_is_hiding_a_content_change() {
        // Same shape as above, but the remote content has drifted from what we
        // last agreed on — there is an edit behind the move, so the edit wins.
        let mut e = established("a.txt", "aaa");
        e.remote_content = Some(content("changed", 99));
        let r = reconcile(
            &e,
            &Delta::Deleted,
            &Delta::Moved {
                to: placement(Some(2), "a.txt"),
            },
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::Download]);
        assert_eq!(
            r.issues,
            vec![Issue::DeleteLostToEdit { side: Side::Local }]
        );
    }

    #[test]
    fn a_delete_does_not_proceed_when_the_agreement_is_unknown() {
        // No recorded agreement means we cannot prove the content is unchanged.
        // Uncertainty resolves toward keeping the file, every time.
        let mut e = established("a.txt", "aaa");
        e.synced_content = None;
        let r = reconcile(
            &e,
            &Delta::Deleted,
            &Delta::Moved {
                to: placement(Some(2), "a.txt"),
            },
            &ctx(),
        );
        assert_eq!(r.actions, vec![Action::Download]);
    }

    #[test]
    fn a_local_move_survives_a_remote_delete() {
        let e = established("a.txt", "aaa");
        let r = reconcile(
            &e,
            &Delta::Moved {
                to: placement(Some(7), "moved.txt"),
            },
            &Delta::Deleted,
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::UploadAsNew {
                placement: placement(Some(7), "moved.txt")
            }]
        );
    }

    // ---- a folder is not a file ---------------------------------------------

    /// A folder that has completed a sync. No content on either side, ever —
    /// which is the whole point: every content-shaped answer below is wrong for
    /// it, and a rule that only ever saw files will give one anyway.
    fn established_folder(id: i64, name: &str) -> Entry {
        let mut e = established(name, "");
        e.id = EntityId::folder(id);
        e.remote_content = None;
        e.synced_content = None;
        e
    }

    #[test]
    fn a_deleted_folder_the_server_renamed_is_made_again_not_downloaded() {
        // A folder has no bytes, so the agreement can never be shown to hold and
        // the delete always loses — correctly. What it loses to matters: asking
        // to download a folder is not a slow way back, it is no way back. The
        // executor withdraws it for want of a content hash, the folder keeps its
        // old name on disk, and anything waiting on that name waits forever.
        let e = established_folder(8138, "Projects (9)");
        let r = reconcile(
            &e,
            &Delta::Deleted,
            &Delta::Moved {
                to: placement(None, "Projects (9) (11) (15)"),
            },
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::CreateLocalFolder {
                placement: placement(None, "Projects (9) (11) (15)")
            }],
            "a folder comes back by being made, at the name the server won with"
        );
        assert_eq!(
            r.issues,
            vec![Issue::DeleteLostToEdit { side: Side::Local }]
        );
    }

    #[test]
    fn a_folder_moved_here_and_deleted_there_is_created_not_uploaded() {
        // The mirror image, and the same category error: there is no content to
        // send as a new file, so the rescue has to be a folder creation.
        let e = established_folder(8138, "Projects");
        let r = reconcile(
            &e,
            &Delta::Moved {
                to: placement(Some(7), "Projects"),
            },
            &Delta::Deleted,
            &ctx(),
        );
        assert_eq!(
            r.actions,
            vec![Action::CreateRemoteFolder {
                placement: placement(Some(7), "Projects")
            }]
        );
    }

    // ---- the mass-delete guard --------------------------------------------

    #[test]
    fn ordinary_deletes_are_not_a_mass_delete() {
        assert!(!is_mass_delete(0, 1000));
        assert!(!is_mass_delete(10, 1000));
        assert!(!is_mass_delete(49, 100));
    }

    #[test]
    fn wiping_a_quarter_of_a_large_tree_trips_the_guard() {
        assert!(is_mass_delete(300, 1000));
        assert!(!is_mass_delete(250, 1000), "exactly 25% is not yet over");
        assert!(is_mass_delete(251, 1000));
    }

    #[test]
    fn small_trees_get_a_flat_floor_so_normal_tidying_is_not_blocked() {
        // 25% of 20 files is 5 — deleting 6 of them is routine, not a disaster.
        assert!(!is_mass_delete(6, 20));
        assert!(!is_mass_delete(50, 20));
        assert!(is_mass_delete(51, 20));
    }
}
