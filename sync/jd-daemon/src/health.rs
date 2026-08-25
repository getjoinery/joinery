//! "Never silently stop", made into something a person can see.
//!
//! The promise the whole product rests on is not that sync never goes wrong. It
//! is that when it does, **you find out**. Nextcloud's sync is not disliked
//! because it fails; it is disliked because it fails quietly and the user
//! discovers it weeks later. So every entry the engine tracks is always in
//! exactly one visible state, and this module reduces the whole set to one
//! honest indicator plus a list of things a person could act on.
//!
//! Four states, and the rules for choosing between them are stated here rather
//! than in the tray, because there are three trays and one truth:
//!
//! - **Green** — converged. Everything is either in agreement or deliberately
//!   not synced here.
//! - **Working** — transfers in flight or queued.
//! - **Attention** — n things need a human: name clashes, conflicts, a full
//!   Drive, a key that has not been granted.
//! - **Stopped** — the client cannot do its job at all: no server, dead
//!   credentials, the sync folder is not there.
//!
//! Two rules do the real work, and both are about refusing to look better than
//! things are. **Attention outranks working**: a client that shows a cheerful
//! spinner while three files cannot sync has hidden the three files. And **work
//! waiting on a backoff is work**: a queue held for fifteen minutes after a
//! failure is not an idle client, and reporting green there is the exact lie
//! this module exists to prevent.

use std::collections::BTreeMap;

use jd_core::store::{Store, StoreError, StoredIssue};

/// The single indicator. Ordered worst-first so `max` picks the honest one.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Indicator {
    /// Converged and idle.
    Green,
    /// Transfers running or queued.
    Working,
    /// Something needs a person.
    Attention,
    /// Cannot sync at all.
    Stopped,
}

impl Indicator {
    pub fn as_str(&self) -> &'static str {
        match self {
            Indicator::Green => "green",
            Indicator::Working => "working",
            Indicator::Attention => "attention",
            Indicator::Stopped => "stopped",
        }
    }
}

/// Why the client cannot sync at all. Every variant carries what the user should
/// do about it, because "error" on its own has never helped anybody.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Blocker {
    /// The sync folder is not there — an unmounted volume, or the user moved it.
    /// Emphatically not a mass delete; the engine pauses.
    RootUnavailable { path: String },
    /// The server cannot be reached.
    ServerUnreachable { detail: String },
    /// This device's credential was revoked or expired. Re-linking is the fix.
    NotAuthorized,
    /// The user pressed pause.
    Paused,
}

impl Blocker {
    pub fn message(&self) -> String {
        match self {
            Blocker::RootUnavailable { path } => {
                format!("Your sync folder is not where it was ({path}). Nothing has been deleted — reconnect the drive or move the folder back.")
            }
            Blocker::ServerUnreachable { detail } => {
                format!("Cannot reach the server: {detail}. Retrying.")
            }
            Blocker::NotAuthorized => {
                "This device is no longer authorized. Link it again to resume syncing.".into()
            }
            Blocker::Paused => "Syncing is paused.".into(),
        }
    }
}

/// Everything the tray, the CLI, and the settings page draw from.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Health {
    pub indicator: Indicator,
    /// Entries per state, e.g. `synced` → 1204.
    pub entries: BTreeMap<String, usize>,
    /// Operations queued or in flight, including ones held on a backoff.
    pub pending_ops: usize,
    /// Things a person could act on.
    pub issues: Vec<Issue>,
    /// Why the client is stopped, when it is.
    pub blocker: Option<Blocker>,
    /// Milliseconds since the epoch of the last pass that completed.
    pub last_pass_ms: Option<u64>,
    /// The feed position this device has acknowledged.
    pub cursor: i64,
}

/// One thing that needs attention, in words rather than in enum names.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Issue {
    pub id: i64,
    pub kind: String,
    pub detail: String,
    pub summary: String,
    pub created_at: i64,
}

impl Health {
    /// How many entries are in a state that counts as settled.
    ///
    /// `out_of_scope` counts as settled because it is a deliberate choice, not
    /// an unfinished job — a descoped subtree is exactly where the user asked
    /// for it to be.
    pub fn settled(&self) -> usize {
        self.entries.get("synced").copied().unwrap_or(0)
            + self.entries.get("out_of_scope").copied().unwrap_or(0)
    }

    pub fn tracked(&self) -> usize {
        self.entries.values().sum()
    }

    /// Encrypted entries this device has no key for.
    ///
    /// Not counted as unfinished work, because nothing on this machine will
    /// finish it — the key arrives from elsewhere or it does not. Counted and
    /// said out loud all the same: "up to date" while a folder full of files is
    /// sitting there unreadable would be true only in the narrowest sense.
    pub fn waiting_for_keys(&self) -> usize {
        self.entries.get("pending_key").copied().unwrap_or(0)
    }

    /// One line for a tray tooltip or a CLI header.
    pub fn summary(&self) -> String {
        if let Some(blocker) = &self.blocker {
            return blocker.message();
        }
        match self.indicator {
            Indicator::Green => match self.waiting_for_keys() {
                0 => format!("Up to date — {} items", self.tracked()),
                n => format!(
                    "Up to date — {} items ({n} waiting for a key)",
                    self.tracked()
                ),
            },
            Indicator::Working => format!("Syncing — {} to go", self.pending_ops),
            Indicator::Attention => {
                let n = self.issues.len();
                let word = if n == 1 { "item needs" } else { "items need" };
                format!("{n} {word} your attention")
            }
            Indicator::Stopped => "Not syncing".into(),
        }
    }
}

/// Read the current health out of the state store.
///
/// Computed from the store on every request rather than accumulated as passes
/// run. An accumulated counter drifts: one missed decrement and the tray spins
/// forever, which teaches the user to ignore it — and an indicator nobody reads
/// is worse than none, because it looks like an answer.
pub fn read(
    store: &Store,
    blocker: Option<Blocker>,
    last_pass_ms: Option<u64>,
) -> Result<Health, StoreError> {
    let entries: BTreeMap<String, usize> = store.status_counts()?.into_iter().collect();
    let pending_ops = store.pending_op_count()?;
    let issues: Vec<Issue> = store.open_issues()?.into_iter().map(describe).collect();

    let indicator = if blocker.is_some() {
        Indicator::Stopped
    } else if !issues.is_empty() {
        // Deliberately above Working. A cheerful spinner running while three
        // files cannot sync has hidden the three files.
        Indicator::Attention
    } else if pending_ops > 0 || unsettled(&entries) > 0 {
        Indicator::Working
    } else {
        Indicator::Green
    };

    Ok(Health {
        indicator,
        entries,
        pending_ops,
        issues,
        blocker,
        last_pass_ms,
        cursor: store.cursor()?,
    })
}

fn unsettled(entries: &BTreeMap<String, usize>) -> usize {
    entries
        .iter()
        .filter(|(state, _)| {
            !matches!(
                state.as_str(),
                // Every state here is one the engine has deliberately stopped
                // acting on. `unreadable` is the newest: bytes proven not to
                // open, which no amount of waiting improves. The user still
                // hears about it — it raises an issue, and an issue outranks
                // Working — but it is not work in progress.
                "synced" | "out_of_scope" | "unsyncable" | "pending_key" | "unreadable"
            )
        })
        .map(|(_, n)| *n)
        .sum()
}

/// Turn a stored issue into something worth reading.
///
/// The engine records issues as a kind and a debug-formatted detail, which is
/// right for a log and useless in a panel. This is the one place that turns them
/// into a sentence, so all three shells say the same thing.
fn describe(raw: StoredIssue) -> Issue {
    let summary = match raw.kind.as_str() {
        "unsyncable" => unsyncable_summary(&raw.detail),
        "reconcile" => reconcile_summary(&raw.detail),
        "quota" => "The Drive this file belongs to is full.".into(),
        "pending_key" => {
            "Waiting for the owner to grant this device a key for an encrypted folder.".into()
        }
        _ => plain_enough(&raw.detail),
    };
    Issue {
        id: raw.issue_id,
        kind: raw.kind,
        detail: raw.detail,
        summary,
        created_at: raw.created_at,
    }
}

/// The last line of defence before a server's own words reach a person.
///
/// Most of what a server sends back is written for the user and passes through
/// untouched. Some of it is not: a soak run caught a folder create coming back
/// as a Postgres unique-violation, constraint name and all, because a
/// check-then-insert lost a race. That was fixed at the server, but the client
/// talks to servers of every version and nobody should ever be shown a
/// SQLSTATE. The raw text stays in `detail`, where it is still there to
/// diagnose with.
fn plain_enough(detail: &str) -> String {
    if detail.contains("SQLSTATE[") || detail.contains("PDOException") {
        return "The server could not carry this out and did not say why in terms worth showing. It will be tried again.".into();
    }
    detail.to_string()
}

fn unsyncable_summary(detail: &str) -> String {
    if let Some(with) = between(detail, "CaseClash", "with: \"", "\"") {
        return format!(
            "Cannot be saved here: the name differs from \u{201c}{with}\u{201d} only by capitalization, and this disk cannot tell the two apart. Rename one of them."
        );
    }
    if let Some(with) = between(detail, "UnicodeClash", "with: \"", "\"") {
        return format!(
            "Cannot be saved here: the name matches \u{201c}{with}\u{201d} once accents are normalized. Rename one of them."
        );
    }
    if let Some(with) = between(detail, "DuplicateName", "with: \"", "\"") {
        // The one name refusal the user cannot act on: there is a second item
        // called \u{201c}{with}\u{201d} on the server and no way to see it from
        // here, so asking them to rename it would be asking the impossible.
        return format!(
            "Cannot be saved here: the server has two items in this folder both called \u{201c}{with}\u{201d}, so only one of them can be put on this disk. Rename one of them on the web."
        );
    }
    if detail.starts_with("NameTooLong") {
        return "The name is too long for this disk. Shorten it.".into();
    }
    if detail.starts_with("PathTooLong") {
        return "The folders it sits in make the path too long for this disk. Move it somewhere shallower.".into();
    }
    if detail.starts_with("ReservedPrefix") {
        return "The name starts with `.jd-`, which the sync client uses for its own temporary files. Rename it.".into();
    }
    // Every other reason here is the disk refusing a NAME, and each ends by
    // asking the user to change one. This one must not: the name is fine, the
    // disk is willing, and there is nothing for them to fix. Falling through to
    // the generic line would send someone off renaming a file that was never
    // the problem.
    if detail.starts_with("EncryptedUnsupported") {
        return "This file is encrypted, and this version of the sync client cannot open encrypted files yet. It has been left on the server, untouched and unharmed.".into();
    }
    "Cannot be saved on this disk under this name.".into()
}

fn reconcile_summary(detail: &str) -> String {
    if detail.starts_with("ConflictResolved") {
        return "Changed in two places at once. Both versions were kept — the other one is beside it, marked as a conflicted copy.".into();
    }
    if detail.starts_with("MoveRaceServerWon") {
        return "Moved in two places at once. The server's location was used, so this device's move was undone.".into();
    }
    if detail.starts_with("DeleteLostToEdit") {
        return "Deleted in one place and edited in another. The edit was kept — a delete can be undone, an edit cannot.".into();
    }
    if detail.starts_with("RescuedFromDeletedFolder") {
        return "A folder was deleted elsewhere while it still held unsaved changes here. Those files were kept and re-uploaded.".into();
    }
    detail.to_string()
}

/// Pull a quoted value out of a debug-formatted reason.
fn between<'a>(haystack: &'a str, tag: &str, open: &str, close: &str) -> Option<&'a str> {
    if !haystack.starts_with(tag) {
        return None;
    }
    let rest = &haystack[haystack.find(open)? + open.len()..];
    let end = rest.find(close)?;
    Some(&rest[..end])
}

#[cfg(test)]
mod tests {
    use super::*;

    fn counts(pairs: &[(&str, usize)]) -> BTreeMap<String, usize> {
        pairs.iter().map(|(k, v)| (k.to_string(), *v)).collect()
    }

    fn health(entries: &[(&str, usize)], pending: usize, issues: usize) -> Health {
        let entries = counts(entries);
        let issues: Vec<Issue> = (0..issues)
            .map(|i| Issue {
                id: i as i64,
                kind: "unsyncable".into(),
                detail: "CaseClash { with: \"Report.txt\" }".into(),
                summary: "clash".into(),
                created_at: 0,
            })
            .collect();
        let indicator = if !issues.is_empty() {
            Indicator::Attention
        } else if pending > 0 || unsettled(&entries) > 0 {
            Indicator::Working
        } else {
            Indicator::Green
        };
        Health {
            indicator,
            entries,
            pending_ops: pending,
            issues,
            blocker: None,
            last_pass_ms: None,
            cursor: 0,
        }
    }

    #[test]
    fn everything_agreed_and_nothing_queued_is_green() {
        let h = health(&[("synced", 1204)], 0, 0);
        assert_eq!(h.indicator, Indicator::Green);
        assert!(h.summary().contains("Up to date"));
    }

    #[test]
    fn files_waiting_for_a_key_are_counted_and_said_out_loud() {
        // Nothing on this machine will finish them, so they are not pending
        // work and the spinner should not run. But "Up to date — 1300 items"
        // with a hundred of them unreadable is true only in the narrowest
        // sense, so the count goes in the sentence.
        let h = health(&[("synced", 1200), ("pending_key", 100)], 0, 0);
        assert_eq!(h.indicator, Indicator::Green);
        assert_eq!(h.waiting_for_keys(), 100);
        assert!(
            h.summary().contains("100 waiting for a key"),
            "{}",
            h.summary()
        );

        let none = health(&[("synced", 1200)], 0, 0);
        assert_eq!(none.summary(), "Up to date — 1200 items");
    }

    #[test]
    fn waiting_for_a_key_is_not_treated_as_a_problem_to_alert_about() {
        // A laptop linked without encrypted folders can be looking at a
        // thousand of these. One alert per file would bury everything that
        // does need a person.
        let h = health(&[("synced", 3), ("pending_key", 1000)], 0, 0);
        assert_ne!(h.indicator, Indicator::Attention);
        assert!(h.issues.is_empty());
    }

    #[test]
    fn a_descoped_subtree_does_not_stop_the_client_being_up_to_date() {
        // Out of scope is a choice the user made, not an unfinished job. A
        // client that showed a spinner forever because of it would be reporting
        // the user's own preference as a problem.
        let h = health(&[("synced", 10), ("out_of_scope", 500)], 0, 0);
        assert_eq!(h.indicator, Indicator::Green);
        assert_eq!(h.settled(), 510);
    }

    #[test]
    fn work_waiting_on_a_retry_is_still_work() {
        // The exact lie this module exists to prevent: a queue held for fifteen
        // minutes after a failure is not an idle client.
        let h = health(&[("synced", 10)], 4, 0);
        assert_eq!(h.indicator, Indicator::Working);
        assert!(h.summary().contains("4 to go"));
    }

    #[test]
    fn something_needing_a_person_outranks_a_cheerful_spinner() {
        // A tray showing "syncing" while three files cannot sync has hidden the
        // three files.
        let h = health(&[("synced", 10), ("pending_download", 2)], 6, 3);
        assert_eq!(h.indicator, Indicator::Attention);
        assert_eq!(h.summary(), "3 items need your attention");
    }

    #[test]
    fn one_issue_is_described_in_the_singular() {
        assert_eq!(health(&[], 0, 1).summary(), "1 item needs your attention");
    }

    #[test]
    fn an_unsyncable_entry_is_not_pending_work_it_is_a_stopped_one() {
        // It will never proceed on its own, so counting it as work in flight
        // would mean the client spins forever with nothing happening.
        assert_eq!(unsettled(&counts(&[("unsyncable", 3)])), 0);
        assert_eq!(unsettled(&counts(&[("pending_upload", 3)])), 3);
    }

    #[test]
    fn a_missing_sync_folder_is_reported_as_a_pause_and_not_as_a_disaster() {
        // The single most important message in the product: the user has to
        // know their files were not deleted.
        let blocker = Blocker::RootUnavailable {
            path: "/Volumes/Backup/Joinery Drive".into(),
        };
        let message = blocker.message();
        assert!(message.contains("Nothing has been deleted"));
        assert!(message.contains("/Volumes/Backup/Joinery Drive"));
    }

    #[test]
    fn every_blocker_says_what_to_do_about_it() {
        for blocker in [
            Blocker::RootUnavailable { path: "/x".into() },
            Blocker::ServerUnreachable {
                detail: "timed out".into(),
            },
            Blocker::NotAuthorized,
            Blocker::Paused,
        ] {
            let m = blocker.message();
            assert!(!m.is_empty());
            assert!(
                m.ends_with('.'),
                "a blocker message is a sentence, not a code: {m:?}"
            );
        }
    }

    #[test]
    fn a_blocked_client_is_stopped_however_healthy_everything_else_looks() {
        let mut h = health(&[("synced", 1204)], 0, 0);
        h.blocker = Some(Blocker::NotAuthorized);
        h.indicator = Indicator::Stopped;
        assert!(h.summary().contains("Link it again"));
    }

    #[test]
    fn a_case_clash_is_explained_in_words_and_names_the_other_file() {
        // The stored form is a debug string. Shown raw it reads as
        // `CaseClash { with: "Report.txt" }`, which tells a user nothing.
        let issue = describe(StoredIssue {
            issue_id: 1,
            entity: None,
            kind: "unsyncable".into(),
            detail: "CaseClash { with: \"Report.txt\" }".into(),
            created_at: 0,
            dismissed: false,
        });
        assert!(issue.summary.contains("Report.txt"));
        assert!(issue.summary.contains("capitalization"));
        assert!(!issue.summary.contains("CaseClash"));
    }

    #[test]
    fn an_encrypted_file_is_not_reported_as_a_naming_problem() {
        // The trap this guards: every other unsyncable reason is the disk
        // refusing a name, so the generic fallback tells the user to rename
        // something. For an encrypted file the name is fine and there is
        // nothing to fix — that advice would send them off editing a file that
        // was never the problem, and leave them believing they had broken it.
        let issue = describe(StoredIssue {
            issue_id: 1,
            entity: None,
            kind: "unsyncable".into(),
            detail: "EncryptedUnsupported".into(),
            created_at: 0,
            dismissed: false,
        });
        assert!(issue.summary.contains("encrypted"));
        assert!(
            !issue.summary.to_lowercase().contains("rename"),
            "must not ask for a rename: {}",
            issue.summary
        );
        assert!(
            !issue.summary.contains("under this name"),
            "must not fall through to the naming fallback: {}",
            issue.summary
        );
        assert!(!issue.summary.contains("EncryptedUnsupported"));
    }

    #[test]
    fn a_conflict_says_that_both_versions_were_kept() {
        // The single fact a user needs after a conflict: nothing was thrown
        // away.
        let issue = describe(StoredIssue {
            issue_id: 1,
            entity: None,
            kind: "reconcile".into(),
            detail: "ConflictResolved { kept_remote: \"a\", local_preserved_as: \"b\" }".into(),
            created_at: 0,
            dismissed: false,
        });
        assert!(issue.summary.contains("Both versions were kept"));
    }

    #[test]
    fn a_path_problem_and_a_name_problem_get_different_advice() {
        // Because the fixes are different: one is a rename, the other is a move.
        let name = unsyncable_summary("NameTooLong { bytes: 300, limit: 255 }");
        let path = unsyncable_summary("PathTooLong { bytes: 32100, limit: 32000 }");
        assert!(name.contains("Shorten"));
        assert!(path.contains("shallower"));
        assert_ne!(name, path);
    }

    #[test]
    fn a_duplicate_name_does_not_tell_the_user_to_rename_something_they_cannot_see() {
        // Every other name refusal ends by asking them to rename a file. This
        // one must not point at the disk: the second item is on the server and
        // has no path here to be seen at, so the only place to act is the web.
        let duplicate = unsyncable_summary("DuplicateName { with: \"app.db-wal\" }");
        let unicode = unsyncable_summary("UnicodeClash { with: \"café.txt\" }");
        assert!(duplicate.contains("app.db-wal"));
        assert!(duplicate.contains("on the web"));
        // And it is not the unicode advice, which would send them looking for a
        // spelling difference that is not there.
        assert!(!duplicate.contains("accents"));
        assert!(unicode.contains("accents"));
    }

    #[test]
    fn a_database_error_from_the_server_is_not_shown_to_the_user() {
        // A soak run caught a folder create returning a Postgres unique
        // violation verbatim. Fixed at that server, but the client meets
        // servers of every version and a SQLSTATE is never a sentence.
        let issue = describe(StoredIssue {
            issue_id: 1,
            entity: None,
            kind: "withdrawn".into(),
            detail: "create_remote_folder was not carried out: Database INSERT failed on table 'fol_folders' - SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value".into(),
            created_at: 0,
            dismissed: false,
        });
        assert!(!issue.summary.contains("SQLSTATE"));
        assert!(!issue.summary.contains("fol_folders"));
        // Still diagnosable: the raw text is kept where an operator looks.
        assert!(issue.detail.contains("SQLSTATE"));
    }

    #[test]
    fn a_server_message_written_for_a_person_is_left_alone() {
        let issue = describe(StoredIssue {
            issue_id: 1,
            entity: None,
            kind: "withdrawn".into(),
            detail: "create_remote_folder was not carried out: A folder with that name already exists here.".into(),
            created_at: 0,
            dismissed: false,
        });
        assert!(issue.summary.contains("already exists here"));
    }

    #[test]
    fn an_unrecognized_issue_is_passed_through_rather_than_swallowed() {
        // A kind this build has no wording for is still something the user
        // should see. Dropping it would be the silence the whole model is
        // against.
        let issue = describe(StoredIssue {
            issue_id: 1,
            entity: None,
            kind: "something-new".into(),
            detail: "a detail from a future build".into(),
            created_at: 0,
            dismissed: false,
        });
        assert_eq!(issue.summary, "a detail from a future build");
    }
}
