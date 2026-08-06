//! What the server did since we last agreed.
//!
//! There is one mistake available here and it is easy to make: measuring the
//! remote change against **what we last saw on the server** instead of against
//! **what the two sides last agreed on**. They are different, and the
//! difference is exactly a bug class.
//!
//! Suppose the poller notices a remote edit, records it, and the process dies
//! before the download completes. On restart, "compare against what we last
//! saw" reports no remote change — we already saw it — and the edit is lost
//! forever, because the server will never mention it again and nothing local
//! knows to ask. Measuring against the last agreement instead reports the edit
//! every round until it has actually been applied, which is the only version
//! that survives an interruption at an arbitrary instruction.
//!
//! So the rule, without exception: **deltas are measured from the last
//! agreement, never from the last observation.** The observed remote state is
//! bookkeeping; the agreement is the pivot.

use crate::model::{ContentId, Delta, Entry, Placement};

/// A fresh statement of what the server holds for one entity, as returned by
/// `drive_stat` or a `drive_index` walk.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RemoteState {
    pub placement: Placement,
    pub content: Option<ContentId>,
    pub head_change_id: i64,
    /// The server's trash is not deletion — but from the client's point of view
    /// a trashed entry should stop being materialized, so it arrives here as a
    /// delete and is recoverable from the server's trash either way.
    pub deleted: bool,
    /// The server holds this file's bytes encrypted and cannot read them.
    ///
    /// Everything else in this struct means something DIFFERENT when this is
    /// set, which is why it is carried rather than inferred: `placement.name`
    /// is a server-side placeholder rather than the file's name, `content` is
    /// the hash of the CIPHERTEXT (so it changes on every re-encryption of
    /// identical plaintext), and the real name, mime and size live inside
    /// `encrypted_metadata`. An engine that does not look at this flag treats
    /// all three as if they were the plaintext facts — which is how ciphertext
    /// ends up written to disk under a placeholder name.
    pub is_encrypted: bool,
    /// This file's key, sealed to the caller's vault public key. Absent when the
    /// owner has not granted this user a key yet — visible file, no way in. Not
    /// an error: the grant may simply not have arrived.
    pub wrapped_file_key: Option<String>,
    /// The encrypted `{name, mime, size, cid, ...}` blob. Opening it needs the
    /// file key, so the plaintext name is not knowable until that is unwrapped.
    pub encrypted_metadata: Option<String>,
}

/// What the server did to an entry we already track, measured from the
/// agreement.
pub fn remote_delta(entry: &Entry, now: &RemoteState) -> Delta {
    if now.deleted {
        return Delta::Deleted;
    }

    // Never synced: everything about it is new to us.
    if !entry.is_established() {
        return Delta::Created {
            placement: now.placement.clone(),
            content: now.content.clone(),
        };
    }

    // Which side of the agreement to hold the server's answer against.
    //
    // For a plaintext file there is only one hash and this is the agreement.
    // For an encrypted one the server has never seen the plaintext, so its
    // answer can only be compared with the ciphertext hash recorded at the last
    // sync. Comparing it against the plaintext hash would report an edit on
    // every single pass, forever, for a file nobody has touched — and the
    // download it queued would then land identical bytes and report the edit
    // again next time.
    let agreed_remote = if entry.is_encrypted {
        entry.synced_remote_content.as_ref()
    } else {
        entry.synced_content.as_ref()
    };

    let content_changed = match (agreed_remote, &now.content) {
        (Some(agreed), Some(fresh)) => agreed.sha256 != fresh.sha256,
        (None, Some(_)) => true,
        // Content that has gone from the server without the entry being deleted
        // is not something the client should act on by discarding bytes it
        // holds; treat it as no content change and let the next round settle it.
        (Some(_), None) => false,
        (None, None) => false,
    };

    let placement_changed = entry
        .synced_placement
        .as_ref()
        .map(|agreed| *agreed != now.placement)
        .unwrap_or(false);

    match (placement_changed, content_changed) {
        (false, false) => Delta::None,
        (false, true) => Delta::Edited {
            content: now
                .content
                .clone()
                .expect("content_changed implies content"),
        },
        (true, false) => Delta::Moved {
            to: now.placement.clone(),
        },
        (true, true) => Delta::MovedAndEdited {
            to: now.placement.clone(),
            content: now
                .content
                .clone()
                .expect("content_changed implies content"),
        },
    }
}

/// Turn the local scanner's findings into the same delta vocabulary.
///
/// The scanner works in paths because that is what a filesystem has; the
/// reconciler works in placements because that is what identity means. This is
/// the seam, and it needs the caller's help to resolve a path back to a parent
/// folder id — which only the caller knows, since it holds the tree.
pub fn local_delta(
    change: &crate::scan::LocalChange,
    placement_of_path: impl Fn(&str) -> Option<Placement>,
) -> Delta {
    use crate::scan::LocalChange;
    match change {
        LocalChange::Unchanged => Delta::None,
        LocalChange::Deleted => Delta::Deleted,
        LocalChange::Edited {
            sha256,
            fingerprint,
        } => Delta::Edited {
            content: ContentId {
                sha256: sha256.clone(),
                size: fingerprint.size,
            },
        },
        LocalChange::Moved { to_path, .. } => match placement_of_path(to_path) {
            Some(to) => Delta::Moved { to },
            // The destination folder is not something the engine tracks yet —
            // it will be, once this round's folder creations land. Reporting no
            // change is right for now: the file has not been lost, and the next
            // round sees it in a folder that exists.
            None => Delta::None,
        },
        LocalChange::MovedAndEdited {
            to_path,
            sha256,
            fingerprint,
        } => {
            let content = ContentId {
                sha256: sha256.clone(),
                size: fingerprint.size,
            };
            match placement_of_path(to_path) {
                Some(to) => Delta::MovedAndEdited { to, content },
                // The move cannot be expressed yet, but the edit can, and an
                // edit must never be dropped waiting on a folder.
                None => Delta::Edited { content },
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::model::{EntityId, LocalStatus};

    fn content(sha: &str) -> ContentId {
        ContentId {
            sha256: sha.into(),
            size: 10,
        }
    }

    fn placement(parent: Option<i64>, name: &str) -> Placement {
        Placement {
            parent,
            name: name.into(),
        }
    }

    /// An entry in agreement at `sha`, sitting at the root under `name`.
    fn agreed(name: &str, sha: &str) -> Entry {
        Entry {
            id: EntityId::file(1),
            remote: placement(None, name),
            remote_content: Some(content(sha)),
            remote_modified_time: None,
            head_change_id: 10,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_content: Some(content(sha)),
            synced_remote_content: None,
            synced_placement: Some(placement(None, name)),
            synced_fingerprint: None,
            local_name: None,
            status: LocalStatus::Synced,
            wrapped_file_key: None,
        }
    }

    fn state(name: &str, sha: Option<&str>) -> RemoteState {
        RemoteState {
            placement: placement(None, name),
            content: sha.map(content),
            head_change_id: 11,
            deleted: false,
            is_encrypted: false,
            wrapped_file_key: None,
            encrypted_metadata: None,
        }
    }

    #[test]
    fn an_unchanged_server_state_is_no_delta() {
        let e = agreed("a.txt", "sha-a");
        assert_eq!(
            remote_delta(&e, &state("a.txt", Some("sha-a"))),
            Delta::None
        );
    }

    #[test]
    fn new_content_on_the_server_is_an_edit() {
        let e = agreed("a.txt", "sha-a");
        assert_eq!(
            remote_delta(&e, &state("a.txt", Some("sha-b"))),
            Delta::Edited {
                content: content("sha-b")
            }
        );
    }

    #[test]
    fn a_rename_on_the_server_is_a_move() {
        let e = agreed("a.txt", "sha-a");
        assert_eq!(
            remote_delta(&e, &state("renamed.txt", Some("sha-a"))),
            Delta::Moved {
                to: placement(None, "renamed.txt")
            }
        );
    }

    #[test]
    fn a_move_into_another_folder_is_a_move() {
        let e = agreed("a.txt", "sha-a");
        let mut s = state("a.txt", Some("sha-a"));
        s.placement = placement(Some(42), "a.txt");
        assert_eq!(
            remote_delta(&e, &s),
            Delta::Moved {
                to: placement(Some(42), "a.txt")
            }
        );
    }

    #[test]
    fn a_rename_plus_an_edit_reports_both() {
        let e = agreed("a.txt", "sha-a");
        assert_eq!(
            remote_delta(&e, &state("b.txt", Some("sha-b"))),
            Delta::MovedAndEdited {
                to: placement(None, "b.txt"),
                content: content("sha-b"),
            }
        );
    }

    #[test]
    fn a_trashed_entry_reads_as_deleted() {
        let e = agreed("a.txt", "sha-a");
        let mut s = state("a.txt", Some("sha-a"));
        s.deleted = true;
        assert_eq!(remote_delta(&e, &s), Delta::Deleted);
    }

    #[test]
    fn an_entry_we_have_never_synced_is_a_creation() {
        let mut e = agreed("a.txt", "sha-a");
        e.synced_content = None;
        e.synced_placement = None;
        assert_eq!(
            remote_delta(&e, &state("a.txt", Some("sha-a"))),
            Delta::Created {
                placement: placement(None, "a.txt"),
                content: Some(content("sha-a")),
            }
        );
    }

    #[test]
    fn a_remote_edit_is_still_reported_after_an_interrupted_download() {
        // The critical property. The poller saw the edit and recorded it in the
        // entry's remote fields, then the process died before downloading. If
        // the delta were measured against what we last SAW, this would now
        // report "nothing changed" and the edit would be lost permanently — the
        // server never mentions it again, and nothing local knows to ask.
        let mut e = agreed("a.txt", "sha-a");
        e.remote_content = Some(content("sha-b")); // observed, never applied
        e.head_change_id = 11;

        assert_eq!(
            remote_delta(&e, &state("a.txt", Some("sha-b"))),
            Delta::Edited {
                content: content("sha-b")
            },
            "an unapplied remote edit must keep being reported until it lands"
        );
    }

    #[test]
    fn an_unapplied_remote_move_is_likewise_still_reported() {
        let mut e = agreed("a.txt", "sha-a");
        e.remote = placement(None, "b.txt"); // observed, never applied
        assert_eq!(
            remote_delta(&e, &state("b.txt", Some("sha-a"))),
            Delta::Moved {
                to: placement(None, "b.txt")
            }
        );
    }

    #[test]
    fn content_vanishing_without_a_delete_is_not_acted_on() {
        // A malformed or partial export must never cause the client to discard
        // bytes it holds. Do nothing and let the next round settle it.
        let e = agreed("a.txt", "sha-a");
        assert_eq!(remote_delta(&e, &state("a.txt", None)), Delta::None);
    }

    // ---- the local seam ----------------------------------------------------

    fn fp(size: u64) -> jd_vfs::Fingerprint {
        jd_vfs::Fingerprint {
            size,
            mtime_ns: 1,
            file_id: 1,
        }
    }

    #[test]
    fn local_changes_map_into_the_same_vocabulary() {
        use crate::scan::LocalChange;
        let resolve = |p: &str| Some(placement(Some(3), p));

        assert_eq!(local_delta(&LocalChange::Unchanged, resolve), Delta::None);
        assert_eq!(local_delta(&LocalChange::Deleted, resolve), Delta::Deleted);
        assert_eq!(
            local_delta(
                &LocalChange::Edited {
                    sha256: "sha-x".into(),
                    fingerprint: fp(99)
                },
                resolve
            ),
            Delta::Edited {
                content: ContentId {
                    sha256: "sha-x".into(),
                    size: 99
                }
            }
        );
    }

    #[test]
    fn a_move_into_a_folder_the_engine_cannot_place_yet_waits() {
        use crate::scan::LocalChange;
        let unresolvable = |_: &str| None;
        // The destination folder has not been created server-side yet. Waiting
        // one round is correct; inventing a placement would put the file
        // somewhere nobody asked for.
        assert_eq!(
            local_delta(
                &LocalChange::Moved {
                    to_path: "NewFolder/a.txt".into(),
                    fingerprint: fp(10)
                },
                unresolvable
            ),
            Delta::None
        );
    }

    #[test]
    fn an_unplaceable_move_still_reports_its_edit() {
        use crate::scan::LocalChange;
        let unresolvable = |_: &str| None;
        // The move has to wait, but the edit must not — content changes are
        // never deferred, because a deferred edit is an edit at risk.
        assert_eq!(
            local_delta(
                &LocalChange::MovedAndEdited {
                    to_path: "NewFolder/a.txt".into(),
                    sha256: "sha-new".into(),
                    fingerprint: fp(20)
                },
                unresolvable
            ),
            Delta::Edited {
                content: ContentId {
                    sha256: "sha-new".into(),
                    size: 20
                }
            }
        );
    }
}
