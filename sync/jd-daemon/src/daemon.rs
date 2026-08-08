//! The loop that runs forever.
//!
//! Two threads and one rule about what each may touch.
//!
//! The **sync thread** owns the state store and runs passes. Nothing else ever
//! opens that database: SQLite would let a second connection in, and a second
//! connection deciding what the last agreement was, concurrently with the first,
//! is how two answers to that question come to exist.
//!
//! The **control thread** answers the tray, the CLI, and the settings page. It
//! holds no database handle at all. It reads a snapshot the sync thread
//! refreshes, and it posts commands into a queue the sync thread drains. So a
//! tray that hangs, or twenty CLI invocations at once, can slow nothing down and
//! corrupt nothing.
//!
//! **When a pass runs**, in the order the conditions are checked: something on
//! disk changed and has gone quiet; the watcher admitted it lost events; the
//! poll interval elapsed; somebody asked for one. The last two are what make the
//! client work at all when the first two fail — a watcher that dies silently
//! degrades this into a thirty-second polling client rather than into a client
//! that has stopped.

use std::sync::mpsc::{Receiver, Sender};
use std::sync::{Arc, Mutex};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use jd_core::execute::ExecEnv;
use jd_core::pass::run_pass;
use jd_core::reconcile::Context;
use jd_core::round::DeletePolicy;
use jd_core::store::Store;
use jd_core::vault::Vault;
use jd_proto::{Client, Credentials};
use jd_vfs::{watch, DirtySet, OsVfs, Vfs};

use crate::config::Config;
use crate::health::{self, Blocker, Health, Indicator};

/// How long the loop sleeps between looking at its conditions. Short enough that
/// a saved file syncs while the user is still looking at the folder, long enough
/// that an idle daemon costs nothing.
const TICK: Duration = Duration::from_millis(500);

/// What a client can ask the daemon to do.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Command {
    Pause,
    Resume,
    SyncNow,
    DismissIssue(i64),
    Stop,
}

/// What the control thread is allowed to see.
#[derive(Debug, Clone)]
pub struct Snapshot {
    pub health: Health,
    pub paused: bool,
    pub device_name: String,
    pub base_url: String,
    pub sync_root: String,
    pub custody: String,
    /// Whether this device holds the key for encrypted folders.
    ///
    /// Reported as a device fact rather than raised once per file. A laptop
    /// linked without encrypted folders can be looking at a thousand encrypted
    /// files, and a thousand identical alerts saying so would bury everything
    /// that actually needs attention, to say one thing that is true once.
    pub vault: bool,
}

/// The shared surface between the two threads.
pub struct Shared {
    pub snapshot: Mutex<Option<Snapshot>>,
    pub commands: Mutex<Sender<Command>>,
}

impl Shared {
    pub fn snapshot(&self) -> Option<Snapshot> {
        self.snapshot.lock().ok().and_then(|s| s.clone())
    }

    /// Post a command. Returns whether the sync thread is still there to take
    /// it — a queued command nobody will ever run should not be reported as
    /// done.
    pub fn send(&self, command: Command) -> bool {
        self.commands
            .lock()
            .map(|tx| tx.send(command).is_ok())
            .unwrap_or(false)
    }
}

/// Everything the sync thread owns.
pub struct Daemon {
    config: Config,
    store: Store,
    vfs: OsVfs,
    client: Client,
    dirty: Arc<Mutex<DirtySet>>,
    /// Kept alive for as long as the daemon runs: dropping it stops the
    /// operating system delivering events, and nothing would say so.
    _watcher: Option<watch::Watcher>,
    shared: Arc<Shared>,
    commands: Receiver<Command>,
    paused: bool,
    last_pass_ms: Option<u64>,
    last_poll_ms: u64,
    blocker: Option<Blocker>,
    custody: String,
    /// The key for encrypted folders. Read back from the credential store once,
    /// at startup, and held for the life of the process — the alternative is
    /// touching the keychain once per file, which on macOS is a prompt.
    vault: Option<Vault>,
}

impl Daemon {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        config: Config,
        store: Store,
        vfs: OsVfs,
        credentials: Credentials,
        shared: Arc<Shared>,
        commands: Receiver<Command>,
        custody: String,
        vault: Option<Vault>,
    ) -> Daemon {
        let client = Client::with_credentials(&config.base_url, credentials);
        let dirty = Arc::new(Mutex::new(DirtySet::with_default_quiet_period()));

        // A watcher that will not start is a degradation, not a failure: the
        // poll interval still runs every pass, so the client keeps working and
        // simply notices local edits within thirty seconds instead of two. A
        // client that refused to start here would be a client that does nothing
        // at all on a machine whose inotify limit is exhausted.
        let watcher = match vfs.root() {
            Some(root) => {
                let sink = Arc::clone(&dirty);
                watch::Watcher::start(&root, sink, now_ms).ok()
            }
            None => None,
        };

        Daemon {
            config,
            store,
            vfs,
            client,
            dirty,
            _watcher: watcher,
            shared,
            commands,
            paused: false,
            last_pass_ms: None,
            last_poll_ms: 0,
            blocker: None,
            custody,
            vault,
        }
    }

    /// Run until told to stop.
    pub fn run(&mut self) {
        // Anything interrupted by the last shutdown is re-derived before new
        // work is planned on top of it.
        self.recover();
        loop {
            if !self.drain_commands() {
                return;
            }
            if self.should_pass() {
                self.one_pass();
            }
            self.publish();
            std::thread::sleep(TICK);
        }
    }

    /// Drain the command queue. Returns false when told to stop.
    fn drain_commands(&mut self) -> bool {
        while let Ok(command) = self.commands.try_recv() {
            match command {
                Command::Pause => {
                    self.paused = true;
                    self.blocker = Some(Blocker::Paused);
                }
                Command::Resume => {
                    self.paused = false;
                    self.blocker = None;
                    // Immediately, rather than up to thirty seconds later. A
                    // resume that appears to do nothing reads as a broken
                    // button.
                    self.last_poll_ms = 0;
                }
                Command::SyncNow => self.last_poll_ms = 0,
                Command::DismissIssue(id) => {
                    let _ = self.store.dismiss_issue(id);
                }
                Command::Stop => return false,
            }
        }
        true
    }

    /// Is there a reason to run a pass right now?
    fn should_pass(&self) -> bool {
        if self.paused {
            return false;
        }
        let now = now_ms();
        if now.saturating_sub(self.last_poll_ms) >= self.config.poll_seconds * 1_000 {
            return true;
        }
        match self.dirty.lock() {
            Ok(mut set) => set.rescan_needed() || !set.take_settled(now).is_empty(),
            // A poisoned lock means the watcher thread panicked holding it. The
            // events it was carrying are gone, so the honest response is to look
            // at everything rather than to believe the empty set.
            Err(_) => true,
        }
    }

    fn one_pass(&mut self) {
        // Taken and cleared together with the pass, because the pass walks the
        // whole tree: anything the watcher noticed before it started is covered
        // by it, and carrying those paths forward would mean walking again for
        // no reason.
        if let Ok(mut set) = self.dirty.lock() {
            let _ = set.take_settled(now_ms());
            set.clear_rescan();
        }

        let ctx = Context {
            date: today(),
            device_name: self.config.device_name.clone(),
            conflict_suffix: 1,
            personality: self.vfs.personality(),
        };
        let namer = {
            let device = self.config.device_name.clone();
            let date = today();
            move |name: &str, suffix: u32| jd_vfs::conflict_copy_name(name, &date, &device, suffix)
        };
        let now = now_ms;
        let env = ExecEnv {
            store: &self.store,
            vfs: &self.vfs,
            api: &self.client,
            now_ms: &now,
            conflict_name: &namer,
            vault: self.vault.as_ref(),
        };
        let mut keys = jd_proto::Client::new_idempotency_key;
        let mut tokens = |id: jd_core::EntityId| format!("{}", id.server_id.abs());

        self.last_poll_ms = now_ms();
        match run_pass(&env, &ctx, DeletePolicy::Guard, &mut keys, &mut tokens) {
            Ok(outcome) => {
                self.last_pass_ms = Some(now_ms());
                self.blocker = if outcome.root_unavailable {
                    Some(Blocker::RootUnavailable {
                        path: self.config.sync_root.display().to_string(),
                    })
                } else {
                    None
                };
                // Anything that changed the server is worth telling the other
                // devices about promptly, and the cheapest way to do that is to
                // come straight back round rather than wait out the interval.
                if !outcome.quiet() {
                    self.last_poll_ms = 0;
                }
            }
            Err(e) => self.blocker = Some(blocker_for(&e)),
        }
    }

    fn recover(&mut self) {
        let namer = |name: &str, _suffix: u32| name.to_string();
        let now = now_ms;
        let env = ExecEnv {
            store: &self.store,
            vfs: &self.vfs,
            api: &self.client,
            now_ms: &now,
            conflict_name: &namer,
            vault: self.vault.as_ref(),
        };
        if let Err(e) = jd_core::recover(&env) {
            self.blocker = Some(blocker_for(&e));
        }
        let _ = self.vfs.sweep_spool();
    }

    /// Refresh what the control thread is allowed to see.
    fn publish(&self) {
        let Ok(health) = health::read(&self.store, self.blocker.clone(), self.last_pass_ms) else {
            return;
        };
        let snapshot = Snapshot {
            health,
            paused: self.paused,
            device_name: self.config.device_name.clone(),
            base_url: self.config.base_url.clone(),
            sync_root: self.config.sync_root.display().to_string(),
            custody: self.custody.clone(),
            vault: self.vault.is_some(),
        };
        if let Ok(mut slot) = self.shared.snapshot.lock() {
            *slot = Some(snapshot);
        }
    }
}

/// Turn an engine error into something the user can act on.
///
/// The distinction that matters is between "come back later" and "do something".
/// A network problem resolves itself and should not send anybody to a settings
/// page; a revoked credential never resolves itself and a spinner is a lie.
fn blocker_for(error: &jd_core::ExecError) -> Blocker {
    let text = error.to_string();
    if text.contains("401") || text.to_lowercase().contains("unauthor") {
        Blocker::NotAuthorized
    } else {
        Blocker::ServerUnreachable { detail: text }
    }
}

pub fn now_ms() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as u64)
        .unwrap_or(0)
}

/// Today's date, for conflict-copy names.
///
/// Computed from the epoch rather than through a calendar library: the only
/// consumer is a filename, and being a few hours out at a timezone boundary
/// costs a filename that says yesterday. Carrying a date library to avoid that
/// is not a trade worth making.
pub fn today() -> String {
    let days = now_ms() / 86_400_000;
    let (y, m, d) = civil_from_days(days as i64);
    format!("{y:04}-{m:02}-{d:02}")
}

/// Howard Hinnant's days-from-civil, run backwards. Exact for every date the
/// program will ever see.
fn civil_from_days(z: i64) -> (i64, u32, u32) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = (z - era * 146_097) as u64;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let y = yoe as i64 + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = (doy - (153 * mp + 2) / 5 + 1) as u32;
    let m = if mp < 10 { mp + 3 } else { mp - 9 } as u32;
    (if m <= 2 { y + 1 } else { y }, m, d)
}

/// Answer one control request.
///
/// A free function over `Shared` rather than a method on the daemon, because the
/// control thread must not be able to reach the store even by accident.
pub fn handle_control(
    shared: &Shared,
    request: &crate::control::Request,
) -> (u16, serde_json::Value) {
    use serde_json::json;

    let path = request.path.split('?').next().unwrap_or("/");
    match path {
        "/status" => match shared.snapshot() {
            Some(s) => (200, snapshot_json(&s)),
            // Running, but has not finished its first pass. Saying "starting" is
            // true; saying "up to date" would not be.
            None => (
                200,
                json!({ "indicator": "working", "summary": "Starting up" }),
            ),
        },
        "/pause" => (200, json!({ "ok": shared.send(Command::Pause) })),
        "/resume" => (200, json!({ "ok": shared.send(Command::Resume) })),
        "/sync-now" => (200, json!({ "ok": shared.send(Command::SyncNow) })),
        "/stop" => (200, json!({ "ok": shared.send(Command::Stop) })),
        "/dismiss" => match request.body.get("issue_id").and_then(|v| v.as_i64()) {
            Some(id) => (200, json!({ "ok": shared.send(Command::DismissIssue(id)) })),
            None => (400, json!({ "error": "dismiss needs an issue_id" })),
        },
        _ => (
            404,
            json!({ "error": format!("no such control path: {path}") }),
        ),
    }
}

/// How many issues one answer carries.
///
/// The list is what a person is going to read, and nobody reads three hundred
/// of anything — so the cap costs the user nothing and buys a bounded answer.
///
/// Bounded matters more than it sounds. The answer grows with the number of
/// things needing attention, so an uncapped list makes the status call largest
/// exactly when the user has most to look at; past a certain size a client
/// simply cannot read it, and the whole surface goes dark while sync carries on
/// invisibly behind it. That happened at 306 issues, and it took the dismiss
/// command down with it — the ids come from this very call, so there was no way
/// back under the limit. The count is always reported in full, so nothing is
/// hidden by being left out of the list.
pub const MAX_REPORTED_ISSUES: usize = 50;

pub fn snapshot_json(s: &Snapshot) -> serde_json::Value {
    use serde_json::json;
    json!({
        "indicator": s.health.indicator.as_str(),
        "summary": s.health.summary(),
        "paused": s.paused,
        "device_name": s.device_name,
        "base_url": s.base_url,
        "sync_root": s.sync_root,
        "custody": s.custody,
        "vault": s.vault,
        "waiting_for_keys": s.health.waiting_for_keys(),
        "tracked": s.health.tracked(),
        "settled": s.health.settled(),
        "pending_ops": s.health.pending_ops,
        "cursor": s.health.cursor,
        "last_pass_ms": s.health.last_pass_ms,
        "entries": s.health.entries,
        "blocker": s.health.blocker.as_ref().map(|b| b.message()),
        // The full count, always, even though the list below is capped. A
        // shell that showed "50 issues" when there were three hundred would be
        // making the same understatement the whole health model exists to
        // prevent.
        "issues_total": s.health.issues.len(),
        "issues": s.health.issues.iter().take(MAX_REPORTED_ISSUES).map(|i| json!({
            "id": i.id,
            "kind": i.kind,
            "summary": i.summary,
            "detail": i.detail,
            "created_at": i.created_at,
        })).collect::<Vec<_>>(),
    })
}

/// Is this snapshot one the tray should draw as settled?
pub fn is_green(s: &Snapshot) -> bool {
    s.health.indicator == Indicator::Green
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::mpsc::channel;

    fn shared() -> (Arc<Shared>, Receiver<Command>) {
        let (tx, rx) = channel();
        (
            Arc::new(Shared {
                snapshot: Mutex::new(None),
                commands: Mutex::new(tx),
            }),
            rx,
        )
    }

    #[test]
    fn a_daemon_that_has_not_finished_starting_says_so_rather_than_up_to_date() {
        // "Up to date" before the first pass would be a claim about files
        // nobody has looked at yet.
        let (shared, _rx) = shared();
        let (status, body) = handle_control(
            &shared,
            &crate::control::Request {
                method: "GET".into(),
                path: "/status".into(),
                body: serde_json::Value::Null,
            },
        );
        assert_eq!(status, 200);
        assert_eq!(body["summary"], "Starting up");
        assert_ne!(body["indicator"], "green");
    }

    #[test]
    fn a_status_answer_stays_readable_however_many_things_are_wrong() {
        // The failure this prevents, found on the soak rig: the answer grows with
        // the number of open issues, and past a certain size a client cannot read
        // it at all — so the tray, the CLI and the settings page all go dark
        // exactly when the user has most to look at, while sync carries on
        // invisibly behind them. Worse, `dismiss` needs the ids from this call,
        // so there was no way back under the limit.
        let issues: Vec<crate::health::Issue> = (0..400)
            .map(|i| crate::health::Issue {
                id: i,
                kind: "unsyncable".into(),
                detail: "CaseClash { with: \"a rather long file name.txt\" }".repeat(4),
                summary: "Cannot be saved here: the name differs only by capitalization.".into(),
                created_at: 0,
            })
            .collect();
        let snapshot = Snapshot {
            health: Health {
                indicator: Indicator::Attention,
                entries: Default::default(),
                pending_ops: 0,
                issues,
                blocker: None,
                last_pass_ms: None,
                cursor: 0,
            },
            paused: false,
            device_name: "device-a".into(),
            base_url: "https://example.com".into(),
            sync_root: "/home/u/Joinery Drive".into(),
            custody: "in a file".into(),
            vault: false,
        };

        let json = snapshot_json(&snapshot);
        let listed = json["issues"].as_array().unwrap().len();
        assert_eq!(listed, MAX_REPORTED_ISSUES);

        // The count is never capped, only the list. A client saying "50 issues"
        // to somebody who has four hundred would be understating, which is the
        // one direction this indicator must never err in.
        assert_eq!(json["issues_total"], 400);

        // And the whole answer stays comfortably inside what a client will read.
        let bytes = serde_json::to_string(&json).unwrap().len();
        assert!(
            bytes < 64 * 1024,
            "a status answer grew to {bytes} bytes with 400 issues open"
        );
    }

    #[test]
    fn control_commands_reach_the_sync_thread() {
        let (shared, rx) = shared();
        for (path, expect) in [
            ("/pause", Command::Pause),
            ("/resume", Command::Resume),
            ("/sync-now", Command::SyncNow),
            ("/stop", Command::Stop),
        ] {
            let (status, body) = handle_control(
                &shared,
                &crate::control::Request {
                    method: "POST".into(),
                    path: path.into(),
                    body: serde_json::Value::Null,
                },
            );
            assert_eq!(status, 200);
            assert_eq!(body["ok"], true);
            assert_eq!(rx.recv().unwrap(), expect);
        }
    }

    #[test]
    fn a_command_nobody_will_run_is_not_reported_as_done() {
        // The sync thread has gone. Answering "ok" here would tell a user their
        // sync is paused when nothing is listening.
        let (shared, rx) = shared();
        drop(rx);
        let (_, body) = handle_control(
            &shared,
            &crate::control::Request {
                method: "POST".into(),
                path: "/pause".into(),
                body: serde_json::Value::Null,
            },
        );
        assert_eq!(body["ok"], false);
    }

    #[test]
    fn dismissing_without_saying_what_is_a_bad_request() {
        let (shared, _rx) = shared();
        let (status, _) = handle_control(
            &shared,
            &crate::control::Request {
                method: "POST".into(),
                path: "/dismiss".into(),
                body: serde_json::json!({}),
            },
        );
        assert_eq!(status, 400);
    }

    #[test]
    fn an_unknown_control_path_is_a_404_and_not_a_silent_success() {
        let (shared, _rx) = shared();
        let (status, _) = handle_control(
            &shared,
            &crate::control::Request {
                method: "GET".into(),
                path: "/whatever".into(),
                body: serde_json::Value::Null,
            },
        );
        assert_eq!(status, 404);
    }

    #[test]
    fn a_query_string_does_not_hide_the_path() {
        let (shared, _rx) = shared();
        let (status, _) = handle_control(
            &shared,
            &crate::control::Request {
                method: "GET".into(),
                path: "/status?t=123".into(),
                body: serde_json::Value::Null,
            },
        );
        assert_eq!(status, 200);
    }

    #[test]
    fn a_revoked_credential_is_told_apart_from_a_network_problem() {
        // One resolves itself and should not send anybody to a settings page;
        // the other never does, and a spinner there is a lie.
        let network = blocker_for(&jd_core::ExecError::Contract("connection timed out".into()));
        assert!(matches!(network, Blocker::ServerUnreachable { .. }));

        let revoked = blocker_for(&jd_core::ExecError::Contract(
            "api error 401: unauthorized".into(),
        ));
        assert!(matches!(revoked, Blocker::NotAuthorized));
    }

    #[test]
    fn the_date_used_in_conflict_copy_names_is_a_real_date() {
        let today = today();
        assert_eq!(today.len(), 10);
        let parts: Vec<&str> = today.split('-').collect();
        assert_eq!(parts.len(), 3);
        assert!(parts[0].parse::<i64>().unwrap() >= 2026);
        let month: u32 = parts[1].parse().unwrap();
        let day: u32 = parts[2].parse().unwrap();
        assert!((1..=12).contains(&month));
        assert!((1..=31).contains(&day));
    }

    #[test]
    fn the_calendar_conversion_matches_known_dates() {
        // Including both leap-year rules, which is where hand-rolled date
        // arithmetic goes wrong: 2000 was a leap year and 2100 is not.
        assert_eq!(civil_from_days(0), (1970, 1, 1));
        assert_eq!(civil_from_days(19_782), (2024, 2, 29));
        assert_eq!(civil_from_days(20_722), (2026, 9, 26));
        assert_eq!(civil_from_days(11_017), (2000, 3, 1));
        assert_eq!(civil_from_days(47_541), (2100, 3, 1));
    }

    #[test]
    fn consecutive_days_are_consecutive_dates() {
        // A cheap sweep over a decade: every step forward must move the date by
        // exactly one day, which catches an off-by-one at any month or year
        // boundary in the range.
        let mut previous = civil_from_days(19_000);
        for day in 19_001..23_000 {
            let next = civil_from_days(day);
            let advanced = next.2 == previous.2 + 1
                || (next.2 == 1
                    && (next.1 == previous.1 + 1 || (next.1 == 1 && next.0 == previous.0 + 1)));
            assert!(advanced, "{previous:?} -> {next:?} is not one day");
            previous = next;
        }
    }
}
