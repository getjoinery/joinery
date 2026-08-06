//! Asking a device's daemon what it believes — and never taking the answer as
//! proof of anything.
//!
//! The daemon's own status is used for exactly two things: knowing when it has
//! *stopped working* so the verifier can start looking, and being checked
//! against reality afterwards. It is never the evidence. Assertion 2 exists
//! precisely because "the daemon said green" and "the trees agree" are different
//! statements, and the gap between them is the worst bug class this rig hunts.

use std::path::Path;

use jd_platform::control::{ask, Endpoint};
use serde_json::Value;

/// What a daemon says about itself.
#[derive(Debug, Clone)]
pub struct Status {
    pub indicator: String,
    pub summary: String,
    pub tracked: u64,
    pub settled: u64,
    pub pending_ops: u64,
    pub waiting_for_keys: u64,
    pub cursor: i64,
    pub blocker: Option<String>,
    /// `state → count`, e.g. `synced` → 1204.
    pub entries: std::collections::BTreeMap<String, u64>,
    /// The issues the answer carried, which is at most the daemon's reporting
    /// cap — **not** how many there are.
    pub issues: Vec<Issue>,
    /// How many there actually are.
    ///
    /// The daemon caps the list so the answer stays readable however bad things
    /// get, so counting `issues` understates the moment anything goes seriously
    /// wrong. A verifier that measured honesty against the capped list would
    /// report the daemon as hiding things at exactly the point the daemon had
    /// most carefully said how many there were.
    pub issues_total: u64,
}

#[derive(Debug, Clone)]
pub struct Issue {
    pub id: i64,
    pub kind: String,
    pub summary: String,
    pub detail: String,
}

impl Status {
    /// Has this daemon stopped working?
    ///
    /// Green is settled. **Attention is also settled** — but only in the
    /// specific sense that the daemon has finished doing what it can and has
    /// said out loud what it cannot: a surfaced name clash is a legitimate
    /// resting place, and waiting for it to clear itself would time out on a
    /// state that is behaving exactly as designed. What it is *not* is a pass;
    /// assertion 5 goes on to check that every unfinished entry has an issue
    /// with its name on it.
    ///
    /// `working` is not settled, however long it has been going. `stopped` is
    /// not settled either — the daemon is telling us it cannot sync at all.
    pub fn is_settled(&self) -> bool {
        (self.indicator == "green" || self.indicator == "attention") && self.pending_ops == 0
    }

    pub fn is_stopped(&self) -> bool {
        self.indicator == "stopped"
    }

    /// Entries in a state that is neither agreement nor a deliberate opt-out.
    pub fn unsettled_entries(&self) -> u64 {
        self.entries
            .iter()
            .filter(|(state, _)| state.as_str() != "synced" && state.as_str() != "out_of_scope")
            .map(|(_, n)| *n)
            .sum()
    }

    /// Entries that are **stuck**, as distinct from merely unfinished.
    ///
    /// The difference is whether anything on this machine is still going to
    /// happen. A file in `pending_upload` is work in progress: the daemon is
    /// reporting itself as working, the tray is spinning, and demanding a
    /// written explanation for each one would be demanding an alert per file in
    /// a queue that is draining normally. A file that is `unsyncable`, or
    /// waiting for a key, will never proceed on its own — those are the ones a
    /// person has to be told about, and those are what assertion 5 holds the
    /// daemon to.
    pub fn stuck_entries(&self) -> u64 {
        self.entries
            .iter()
            .filter(|(state, _)| crate::verify::STUCK_STATES.contains(&state.as_str()))
            .map(|(_, n)| *n)
            .sum()
    }

    /// Read one of `jd_daemon::daemon::snapshot_json`'s answers.
    ///
    /// Crate-visible rather than private so the verifier's tests can build a
    /// status the same way the rig does. A test that constructed the struct
    /// directly would keep passing after a field on the daemon's side was
    /// renamed, which is the one change that would silently blind the rig.
    pub(crate) fn from_json(v: &Value) -> Status {
        let s = |k: &str| v.get(k).and_then(Value::as_str).unwrap_or("").to_string();
        let n = |k: &str| v.get(k).and_then(Value::as_u64).unwrap_or(0);
        Status {
            indicator: s("indicator"),
            summary: s("summary"),
            tracked: n("tracked"),
            settled: n("settled"),
            pending_ops: n("pending_ops"),
            waiting_for_keys: n("waiting_for_keys"),
            cursor: v.get("cursor").and_then(Value::as_i64).unwrap_or(0),
            blocker: v.get("blocker").and_then(Value::as_str).map(str::to_string),
            issues_total: v
                .get("issues_total")
                .and_then(Value::as_u64)
                .unwrap_or_else(|| {
                    v.get("issues")
                        .and_then(Value::as_array)
                        .map(|l| l.len() as u64)
                        .unwrap_or(0)
                }),
            entries: v
                .get("entries")
                .and_then(Value::as_object)
                .map(|m| {
                    m.iter()
                        .map(|(k, v)| (k.clone(), v.as_u64().unwrap_or(0)))
                        .collect()
                })
                .unwrap_or_default(),
            issues: v
                .get("issues")
                .and_then(Value::as_array)
                .map(|list| {
                    list.iter()
                        .map(|i| Issue {
                            id: i.get("id").and_then(Value::as_i64).unwrap_or(0),
                            kind: i
                                .get("kind")
                                .and_then(Value::as_str)
                                .unwrap_or("")
                                .to_string(),
                            summary: i
                                .get("summary")
                                .and_then(Value::as_str)
                                .unwrap_or("")
                                .to_string(),
                            detail: i
                                .get("detail")
                                .and_then(Value::as_str)
                                .unwrap_or("")
                                .to_string(),
                        })
                        .collect()
                })
                .unwrap_or_default(),
        }
    }
}

/// Ask the daemon that published `control.json` at this path.
///
/// `None` means there is no answer — the daemon is down, restarting after a
/// kill, or partitioned from its own control port. That is a normal state during
/// a storm and is reported as absence rather than as an error, because during a
/// settle it means something entirely different and the caller has to be the one
/// to decide which.
pub fn status(control_file: &Path) -> Option<Status> {
    let endpoint = Endpoint::load(control_file)?;
    let answer = ask(&endpoint, "GET", "/status", Value::Null)?;
    Some(Status::from_json(&answer))
}

/// Nudge a daemon into an immediate pass rather than waiting out its poll
/// interval. Used only at the start of a settle: thirty seconds per device per
/// settle, an hour apart, is otherwise pure waiting.
pub fn sync_now(control_file: &Path) -> bool {
    let Some(endpoint) = Endpoint::load(control_file) else {
        return false;
    };
    ask(&endpoint, "POST", "/sync-now", Value::Null)
        .and_then(|v| v.get("ok").and_then(Value::as_bool))
        .unwrap_or(false)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn status(indicator: &str, pending: u64, entries: Value, issues: Value) -> Status {
        Status::from_json(&json!({
            "indicator": indicator,
            "summary": "…",
            "tracked": 10,
            "settled": 8,
            "pending_ops": pending,
            "waiting_for_keys": 0,
            "cursor": 42,
            "entries": entries,
            "issues": issues,
        }))
    }

    #[test]
    fn a_snapshot_is_read_the_way_the_daemon_writes_it() {
        // Field for field against daemon::snapshot_json. A rename on that side
        // that silently defaulted here would have the verifier reading zero
        // pending operations off every device forever.
        let s = status(
            "working",
            3,
            json!({"synced": 8, "pending_upload": 2}),
            json!([{"id": 5, "kind": "unsyncable", "summary": "clash", "detail": "CaseClash"}]),
        );
        assert_eq!(s.indicator, "working");
        assert_eq!(s.pending_ops, 3);
        assert_eq!(s.cursor, 42);
        assert_eq!(s.entries["pending_upload"], 2);
        assert_eq!(s.issues[0].id, 5);
        assert_eq!(s.issues[0].kind, "unsyncable");
    }

    #[test]
    fn green_with_nothing_queued_is_settled() {
        assert!(status("green", 0, json!({"synced": 10}), json!([])).is_settled());
    }

    #[test]
    fn work_waiting_on_a_backoff_is_not_settled() {
        // The exact lie the health model exists to prevent, and the verifier
        // must not undo it: a queue held for fifteen minutes after a failure is
        // not an idle client.
        assert!(!status("working", 4, json!({"synced": 10}), json!([])).is_settled());
        assert!(!status("green", 4, json!({"synced": 10}), json!([])).is_settled());
    }

    #[test]
    fn a_surfaced_issue_is_a_resting_place_rather_than_a_stall() {
        // A name clash will never clear itself. Waiting for it would time out on
        // a state behaving exactly as designed — but it is not a pass either,
        // which is assertion 5's job.
        let s = status(
            "attention",
            0,
            json!({"synced": 9, "unsyncable": 1}),
            json!([{"id": 1, "kind": "unsyncable", "summary": "s", "detail": "d"}]),
        );
        assert!(s.is_settled());
        assert_eq!(s.unsettled_entries(), 1);
    }

    #[test]
    fn a_stopped_daemon_is_never_settled() {
        let s = status("stopped", 0, json!({"synced": 10}), json!([]));
        assert!(!s.is_settled());
        assert!(s.is_stopped());
    }

    #[test]
    fn a_descoped_subtree_does_not_count_as_unfinished() {
        let s = status(
            "green",
            0,
            json!({"synced": 5, "out_of_scope": 500}),
            json!([]),
        );
        assert_eq!(s.unsettled_entries(), 0);
    }

    #[test]
    fn a_daemon_that_is_not_there_is_absence_and_not_a_crash() {
        // Normal during a storm — it has just been killed. Meaningful during a
        // settle. The caller decides which, so this must not panic or invent.
        assert!(super::status(Path::new("/nonexistent/control.json")).is_none());
        assert!(!super::sync_now(Path::new("/nonexistent/control.json")));
    }
}
