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
//! corrupt nothing. Before the device is linked there is no sync thread; the
//! setup loop (`crate::setup`) stands in its place on the same terms.
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
use crate::control::{Caller, Request};
use crate::health::{self, Blocker, Health, Indicator};
use crate::setup::{self, Desk, SetupCommand, Stage, View};
use serde_json::{json, Value};

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
    /// Stop syncing and hand back to the caller to take this computer off its
    /// account. The sync thread does not do the unlinking itself: it would be
    /// revoking the credential its own pass might be halfway through using.
    Unlink,
}

/// Why the loop stopped.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Exit {
    Stopped,
    Unlinked,
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
    /// While this computer is not linked: what the first-run page shows, and
    /// the queue to the setup loop. `None` once it is linked.
    pub setup: Mutex<Option<Desk>>,
    /// The folder tree, refreshed by the sync thread when a pass changed
    /// something. Kept apart from the snapshot, which is refreshed twice a
    /// second and read by every tray poll: the tree can be thousands of rows
    /// and only the settings page wants it.
    pub folders: Mutex<Option<Value>>,
}

impl Default for Shared {
    fn default() -> Shared {
        Shared::new()
    }
}

impl Shared {
    /// A surface with nothing behind it yet: no snapshot, and a command queue
    /// nobody drains, so a command sent now is honestly reported as not done.
    pub fn new() -> Shared {
        let (tx, _) = std::sync::mpsc::channel();
        Shared {
            snapshot: Mutex::new(None),
            commands: Mutex::new(tx),
            setup: Mutex::new(None),
            folders: Mutex::new(None),
        }
    }

    pub fn snapshot(&self) -> Option<Snapshot> {
        self.snapshot.lock().ok().and_then(|s| s.clone())
    }

    /// Point the command queue at a new sync thread, forgetting what the last
    /// one published.
    pub fn attach(&self, commands: Sender<Command>) {
        if let Ok(mut slot) = self.commands.lock() {
            *slot = commands;
        }
        if let Ok(mut slot) = self.snapshot.lock() {
            *slot = None;
        }
        if let Ok(mut slot) = self.folders.lock() {
            *slot = None;
        }
    }

    /// Enter the first-run setup: from here until [`Shared::leave_setup`] the
    /// status answer is the setup's, and only setup requests are taken.
    pub fn enter_setup(&self, desk: Desk) {
        let (tx, _) = std::sync::mpsc::channel();
        self.attach(tx);
        if let Ok(mut slot) = self.setup.lock() {
            *slot = Some(desk);
        }
    }

    /// Show a setup stage with no setup loop behind it yet — the moment
    /// between a sync thread stopping to unlink and the setup loop starting.
    pub fn show_setup(&self, view: View, paths: &jd_platform::Paths) {
        let (tx, _) = std::sync::mpsc::channel();
        self.enter_setup(Desk {
            view,
            commands: tx,
            paths: paths.clone(),
        });
    }

    pub fn publish_setup(&self, view: &View) {
        if let Ok(mut slot) = self.setup.lock() {
            if let Some(desk) = slot.as_mut() {
                if desk.view != *view {
                    desk.view = view.clone();
                }
            }
        }
    }

    pub fn leave_setup(&self) {
        if let Ok(mut slot) = self.setup.lock() {
            *slot = None;
        }
    }

    pub fn setup_view(&self) -> Option<View> {
        self.setup
            .lock()
            .ok()
            .and_then(|s| s.as_ref().map(|d| d.view.clone()))
    }

    fn setup_paths(&self) -> Option<jd_platform::Paths> {
        self.setup
            .lock()
            .ok()
            .and_then(|s| s.as_ref().map(|d| d.paths.clone()))
    }

    /// Post to the setup loop. False when there is none to take it.
    pub fn send_setup(&self, command: SetupCommand) -> bool {
        self.setup
            .lock()
            .ok()
            .and_then(|s| s.as_ref().map(|d| d.commands.send(command).is_ok()))
            .unwrap_or(false)
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
    /// Whether the path-keyed hash cache has been kept in step with the
    /// watcher since this process started. False until the first pass
    /// forgets it all: nothing watched the disk while the client was down.
    path_hashes_trusted: bool,
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
    /// Where each pass's trace goes, on a soak device that asked
    /// (`crate::passlog`). None on every user's client.
    pass_journal: Option<crate::passlog::PassJournal>,
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
        pass_journal: Option<crate::passlog::PassJournal>,
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
            path_hashes_trusted: false,
            shared,
            commands,
            paused: false,
            last_pass_ms: None,
            last_poll_ms: 0,
            blocker: None,
            custody,
            vault,
            pass_journal,
        }
    }

    /// Run until told to stop, or to unlink.
    pub fn run(&mut self) -> Exit {
        // Anything interrupted by the last shutdown is re-derived before new
        // work is planned on top of it.
        self.recover();
        // What the store already knows, so the settings page has a tree to
        // show even while the first pass cannot reach the server.
        self.publish_folders();
        loop {
            if let Some(exit) = self.drain_commands() {
                return exit;
            }
            if self.should_pass() {
                self.one_pass();
            }
            self.publish();
            std::thread::sleep(TICK);
        }
    }

    /// Drain the command queue. Returns why to stop, when told to.
    fn drain_commands(&mut self) -> Option<Exit> {
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
                Command::Stop => return Some(Exit::Stopped),
                Command::Unlink => return Some(Exit::Unlinked),
            }
        }
        None
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
        //
        // On a volume with no file ids the scan's hash cache is kept by path,
        // and a path is only evidence while nothing has touched it: two files
        // of one size and one two-second tick can trade names. So every path
        // the watcher reports -- settled or not -- loses its cached hash
        // first, and all of them do on the first pass, after lost events, or
        // with no watcher at all (`specs/drive_weak_volume_identity.md`, B7).
        let positional = self.vfs.personality().positional_file_ids;
        let mut forget: Option<Vec<String>> = None;
        let mut forget_all = positional && (!self.path_hashes_trusted || self._watcher.is_none());
        match self.dirty.lock() {
            Ok(mut set) => {
                if positional {
                    forget_all |= set.rescan_needed();
                    let root = self.vfs.root();
                    forget = Some(
                        set.touched()
                            .iter()
                            .filter_map(|p| root.as_ref().and_then(|r| p.strip_prefix(r).ok()))
                            .map(|p| p.to_string_lossy().replace('\\', "/"))
                            .collect(),
                    );
                }
                let _ = set.take_settled(now_ms());
                set.clear_rescan();
            }
            Err(_) => forget_all = positional,
        }
        if positional {
            let done = if forget_all {
                // Whatever may have gone by unseen may include a remount: a
                // tie-break id read before now names nothing this session
                // (`specs/drive_weak_volume_identity.md`, the classes).
                let _ = self.store.bump_mount_session();
                self.store.forget_path_hashes(None)
            } else {
                self.store.forget_path_hashes(forget.as_deref())
            };
            // Unforgotten is untrusted: the next pass forgets everything.
            self.path_hashes_trusted = done.is_ok();
        }

        let ctx = Context {
            date: today(),
            device_name: self.config.device_name.clone(),
            conflict_suffix: 1,
            personality: self.vfs.personality(),
            trace: self.pass_journal.is_some(),
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

        self.last_poll_ms = now_ms();
        match run_pass(&env, &ctx, DeletePolicy::Guard, &mut keys) {
            Ok(outcome) => {
                if let Some(journal) = self.pass_journal.as_mut() {
                    match outcome.trace.as_ref() {
                        Some(t) => journal.write(t),
                        None if outcome.root_unavailable => journal.write_unavailable(),
                        None => {}
                    }
                }
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
                    self.publish_folders();
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

    /// Refresh the folder tree the settings page shows.
    fn publish_folders(&self) {
        let Ok(entries) = self.store.every_entry() else {
            return;
        };
        let rows = entries
            .iter()
            .filter(|e| e.id.entity_type == jd_core::model::EntityType::Folder && !e.remote_deleted)
            .map(|e| FolderRow {
                id: e.id.server_id,
                parent: e.remote.parent,
                name: e.remote.name.clone(),
                state: folder_state(&e.status),
                encrypted: e.is_encrypted,
            })
            .collect();
        if let Ok(mut slot) = self.shared.folders.lock() {
            *slot = Some(folders_json(rows));
        }
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

/// What a state store says it is the record of.
const STORE_SITE: &str = "linked_base_url";
const STORE_ROOT: &str = "linked_sync_root";

/// Open the state store for this configuration, starting a fresh one when the
/// store there is the record of a different site or folder.
///
/// The store is the last agreement between one folder and one site, and the
/// engine acts on it: an entry agreed as synced and missing from the folder is
/// something the user deleted here. Unlinking leaves the record in place, so
/// relinking to the same folder resumes without reading everything again. But
/// relinking to a *different* folder — one click away on the settings page —
/// would read every file of the old one as deleted here and send those deletes
/// to the server. So the store says what it is the record of, and a store of
/// something else is moved aside (kept, not deleted: it is the evidence if
/// anything went wrong) for a fresh one, whose first pass merges the folder and
/// the server without deleting anything. A store from before it said so is kept
/// as it always was, and stamped.
pub fn open_store(path: &std::path::Path, config: &Config) -> Result<Store, String> {
    let root = config.sync_root.display().to_string();
    let store = Store::open(path).map_err(|e| e.to_string())?;
    let site = store.get_meta(STORE_SITE).map_err(|e| e.to_string())?;
    let folder = store.get_meta(STORE_ROOT).map_err(|e| e.to_string())?;
    let same = match (site.as_deref(), folder.as_deref()) {
        (None, None) => true,
        (Some(s), Some(f)) => s == config.base_url && f == root,
        _ => false,
    };
    let store = if same {
        store
    } else {
        drop(store);
        let aside = format!("before-{}", now_ms());
        for suffix in ["", "-wal", "-shm"] {
            let from = std::path::PathBuf::from(format!("{}{suffix}", path.display()));
            if from.exists() {
                let to = format!("{}.{aside}{suffix}", path.display());
                std::fs::rename(&from, &to)
                    .map_err(|e| format!("cannot move the old sync record aside: {e}"))?;
            }
        }
        eprintln!(
            "the sync record was of {} in {}; kept it as {}.{aside} and started a fresh one",
            site.unwrap_or_default(),
            folder.unwrap_or_default(),
            path.display()
        );
        Store::open(path).map_err(|e| e.to_string())?
    };
    store
        .set_meta(STORE_SITE, &config.base_url)
        .and_then(|_| store.set_meta(STORE_ROOT, &root))
        .map_err(|e| e.to_string())?;
    Ok(store)
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

/// When a control request makes sense.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum When {
    Always,
    /// Only once this computer is linked.
    Linked,
    /// Only during the first-run setup.
    Unlinked,
}

/// One thing a client can ask the daemon.
pub struct Route {
    pub method: &'static str,
    pub path: &'static str,
    pub when: When,
    /// Whether the settings page may ask it. The tray and the CLI, holding the
    /// token, may ask anything; the page is let in by a ticket the token holder
    /// chose to hand out, and gets what it has a button for.
    pub page: bool,
    pub handle: fn(&Shared, &Request) -> (u16, Value),
}

/// Everything the control channel answers. A new request is one line here.
#[rustfmt::skip]
pub const ROUTES: &[Route] = &[
    Route { method: "GET", path: "/status", when: When::Always, page: true, handle: route_status },
    Route { method: "GET", path: "/folders", when: When::Linked, page: true, handle: route_folders },
    Route { method: "POST", path: "/pause", when: When::Linked, page: true, handle: route_pause },
    Route { method: "POST", path: "/resume", when: When::Linked, page: true, handle: route_resume },
    Route { method: "POST", path: "/sync-now", when: When::Linked, page: true, handle: route_sync_now },
    Route { method: "POST", path: "/dismiss", when: When::Linked, page: true, handle: route_dismiss },
    Route { method: "POST", path: "/autostart", when: When::Linked, page: true, handle: route_autostart },
    Route { method: "POST", path: "/unlink", when: When::Linked, page: true, handle: route_unlink },
    Route { method: "POST", path: "/setup/begin", when: When::Unlinked, page: true, handle: route_setup_begin },
    Route { method: "POST", path: "/setup/cancel", when: When::Unlinked, page: true, handle: route_setup_cancel },
    Route { method: "POST", path: "/setup/finish", when: When::Unlinked, page: true, handle: route_setup_finish },
    // Not the page's: a page that stopped the daemon would take itself down
    // with it, and stopping is the tray's and the CLI's to do.
    Route { method: "POST", path: "/stop", when: When::Always, page: false, handle: route_stop },
];

/// Answer one control request.
///
/// A free function over `Shared` rather than a method on the daemon, because the
/// control thread must not be able to reach the store even by accident.
pub fn handle_control(shared: &Shared, request: &Request) -> (u16, Value) {
    let path = request.path.split('?').next().unwrap_or("/");
    let Some(route) = ROUTES.iter().find(|r| r.path == path) else {
        return (
            404,
            json!({ "error": format!("no such control path: {path}") }),
        );
    };
    // A request that changes something is a POST, so that nothing a browser
    // does on its own — following a link, loading an image — can be one.
    if request.method != route.method {
        return (
            405,
            json!({ "error": format!("{path} takes {}, not {}", route.method, request.method) }),
        );
    }
    if request.caller == Caller::Page && !route.page {
        return (
            403,
            json!({ "error": format!("the settings page cannot ask for {path}") }),
        );
    }
    let linked = !shared.in_setup();
    match route.when {
        When::Linked if !linked => (
            409,
            json!({ "error": "this computer is not linked to an account yet" }),
        ),
        When::Unlinked if linked => (
            409,
            json!({ "error": "this computer is already linked to an account" }),
        ),
        _ => (route.handle)(shared, request),
    }
}

impl Shared {
    fn in_setup(&self) -> bool {
        self.setup.lock().map(|s| s.is_some()).unwrap_or(false)
    }
}

fn ok(sent: bool) -> (u16, Value) {
    (200, json!({ "ok": sent }))
}

fn route_status(shared: &Shared, _: &Request) -> (u16, Value) {
    if let Some(view) = shared.setup_view() {
        return (200, setup::status_json(&view));
    }
    let mut body = match shared.snapshot() {
        Some(s) => snapshot_json(&s),
        // Running, but has not finished its first pass. Saying "starting" is
        // true; saying "up to date" would not be.
        None => json!({
            "linked": true,
            "version": crate::VERSION,
            "indicator": "working",
            "summary": "Starting up",
        }),
    };
    body["autostart"] = json!(jd_platform::autostart::is_enabled(&jd_platform::home_dir()));
    (200, body)
}

fn route_folders(shared: &Shared, _: &Request) -> (u16, Value) {
    let folders = shared.folders.lock().ok().and_then(|f| f.clone());
    (
        200,
        folders.unwrap_or_else(|| json!({ "folders": [], "truncated": false, "loading": true })),
    )
}

fn route_pause(shared: &Shared, _: &Request) -> (u16, Value) {
    ok(shared.send(Command::Pause))
}

fn route_resume(shared: &Shared, _: &Request) -> (u16, Value) {
    ok(shared.send(Command::Resume))
}

fn route_sync_now(shared: &Shared, _: &Request) -> (u16, Value) {
    ok(shared.send(Command::SyncNow))
}

fn route_stop(shared: &Shared, _: &Request) -> (u16, Value) {
    if shared.in_setup() {
        return ok(shared.send_setup(SetupCommand::Stop));
    }
    ok(shared.send(Command::Stop))
}

fn route_dismiss(shared: &Shared, request: &Request) -> (u16, Value) {
    match request.body.get("issue_id").and_then(Value::as_i64) {
        Some(id) => ok(shared.send(Command::DismissIssue(id))),
        None => (400, json!({ "error": "dismiss needs an issue_id" })),
    }
}

fn route_autostart(_: &Shared, request: &Request) -> (u16, Value) {
    let home = jd_platform::home_dir();
    let done = match request.body.get("on").and_then(Value::as_bool) {
        Some(true) => match std::env::current_exe() {
            Ok(exe) => jd_platform::autostart::enable(&exe, &home).map_err(|e| e.to_string()),
            Err(e) => Err(format!("cannot find this program's own path: {e}")),
        },
        Some(false) => jd_platform::autostart::disable(&home).map_err(|e| e.to_string()),
        None => return (400, json!({ "error": "autostart needs on: true or false" })),
    };
    match done {
        Ok(()) => (
            200,
            json!({ "ok": true, "autostart": jd_platform::autostart::is_enabled(&home) }),
        ),
        Err(e) => (500, json!({ "error": e })),
    }
}

fn route_unlink(shared: &Shared, request: &Request) -> (u16, Value) {
    // Said in the request rather than assumed from it: unlinking revokes this
    // computer's key, and a stray POST is not a person deciding to do that.
    if request.body.get("confirm").and_then(Value::as_bool) != Some(true) {
        return (400, json!({ "error": "unlink needs confirm: true" }));
    }
    ok(shared.send(Command::Unlink))
}

fn route_setup_begin(shared: &Shared, request: &Request) -> (u16, Value) {
    let Some(view) = shared.setup_view() else {
        return (
            409,
            json!({ "error": "this computer is already linked to an account" }),
        );
    };
    if view.stage != Stage::Instance {
        return (
            409,
            json!({ "error": "a link is already under way — cancel it to start again" }),
        );
    }
    let typed = |k: &str| request.body.get(k).and_then(Value::as_str).unwrap_or("");
    let base_url = match setup::instance_url(typed("base_url")) {
        Ok(u) => u,
        Err(e) => return (400, json!({ "error": e })),
    };
    let device_name = match setup::device_name(typed("device_name")) {
        Ok(n) => n,
        Err(e) => return (400, json!({ "error": e })),
    };
    ok(shared.send_setup(SetupCommand::Begin {
        base_url,
        device_name,
    }))
}

fn route_setup_cancel(shared: &Shared, _: &Request) -> (u16, Value) {
    ok(shared.send_setup(SetupCommand::Cancel))
}

/// Checked here, where the person filling in the form can be answered at
/// once, and again by the setup loop where the folder is made.
fn route_setup_finish(shared: &Shared, request: &Request) -> (u16, Value) {
    let (Some(view), Some(paths)) = (shared.setup_view(), shared.setup_paths()) else {
        return (
            409,
            json!({ "error": "this computer is already linked to an account" }),
        );
    };
    if view.stage != Stage::Folder {
        return (
            409,
            json!({ "error": "there is no approved link to finish yet" }),
        );
    }
    let typed = request
        .body
        .get("sync_root")
        .and_then(Value::as_str)
        .unwrap_or("");
    let checked = match setup::check_folder(typed, &paths, &jd_platform::home_dir()) {
        Ok(c) => c,
        Err(e) => return (400, json!({ "error": e })),
    };
    let confirmed = request.body.get("confirm").and_then(Value::as_bool) == Some(true);
    if checked.existing > 0 && !confirmed {
        let count = if checked.existing >= 100 {
            "100 or more items".to_string()
        } else if checked.existing == 1 {
            "1 item".to_string()
        } else {
            format!("{} items", checked.existing)
        };
        return (
            200,
            json!({
                "ok": false,
                "confirm": format!(
                    "{} already has {count} in it. They will be uploaded to your Drive and appear on your other devices.",
                    checked.path.display()
                ),
            }),
        );
    }
    let autostart = request
        .body
        .get("autostart")
        .and_then(Value::as_bool)
        .unwrap_or(false);
    ok(shared.send_setup(SetupCommand::Finish {
        sync_root: checked.path,
        autostart,
    }))
}

/// One folder, as the settings page shows it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FolderRow {
    pub id: i64,
    pub parent: Option<i64>,
    pub name: String,
    pub state: &'static str,
    pub encrypted: bool,
}

/// The most folders one answer carries. The page draws them as a tree a
/// person reads; past this many it is not being read, and the answer stays
/// small however large the Drive.
pub const MAX_REPORTED_FOLDERS: usize = 2000;

/// What a folder's state means to the person looking at the tree.
pub fn folder_state(status: &jd_core::model::LocalStatus) -> &'static str {
    use jd_core::model::LocalStatus;
    match status {
        LocalStatus::Synced => "synced",
        LocalStatus::OutOfScope => "not_here",
        LocalStatus::PendingKey => "waiting_for_key",
        LocalStatus::Unsyncable(_) => "unsyncable",
        LocalStatus::Conflict => "conflict",
        _ => "pending",
    }
}

/// The tree as the page wants it: flat, each row naming its parent, sorted the
/// way a file browser sorts, and capped — with the cap said out loud rather
/// than leaving the page to show a partial tree as if it were all.
pub fn folders_json(mut rows: Vec<FolderRow>) -> Value {
    rows.sort_by(|a, b| {
        a.name
            .to_lowercase()
            .cmp(&b.name.to_lowercase())
            .then(a.id.cmp(&b.id))
    });
    let total = rows.len();
    rows.truncate(MAX_REPORTED_FOLDERS);
    json!({
        "folders": rows.iter().map(|r| json!({
            "id": r.id,
            "parent": r.parent,
            "name": r.name,
            "state": r.state,
            "encrypted": r.encrypted,
        })).collect::<Vec<_>>(),
        "total": total,
        "truncated": total > MAX_REPORTED_FOLDERS,
        // Every folder syncs: the engine has no per-folder opt-out yet, so the
        // page shows the tree and offers no checkboxes it could not honour.
        "selectable": false,
    })
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
    json!({
        "linked": true,
        "version": crate::VERSION,
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
        let shared = Arc::new(Shared::new());
        shared.attach(tx);
        (shared, rx)
    }

    fn req(method: &str, path: &str, body: Value) -> Request {
        Request {
            method: method.into(),
            path: path.into(),
            body,
            caller: Caller::Owner,
        }
    }

    #[test]
    fn a_daemon_that_has_not_finished_starting_says_so_rather_than_up_to_date() {
        // "Up to date" before the first pass would be a claim about files
        // nobody has looked at yet.
        let (shared, _rx) = shared();
        let (status, body) =
            handle_control(&shared, &req("GET", "/status", serde_json::Value::Null));
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
            let (status, body) =
                handle_control(&shared, &req("POST", path, serde_json::Value::Null));
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
        let (_, body) = handle_control(&shared, &req("POST", "/pause", serde_json::Value::Null));
        assert_eq!(body["ok"], false);
    }

    #[test]
    fn dismissing_without_saying_what_is_a_bad_request() {
        let (shared, _rx) = shared();
        let (status, _) = handle_control(&shared, &req("POST", "/dismiss", serde_json::json!({})));
        assert_eq!(status, 400);
    }

    #[test]
    fn an_unknown_control_path_is_a_404_and_not_a_silent_success() {
        let (shared, _rx) = shared();
        let (status, _) =
            handle_control(&shared, &req("GET", "/whatever", serde_json::Value::Null));
        assert_eq!(status, 404);
    }

    #[test]
    fn a_query_string_does_not_hide_the_path() {
        let (shared, _rx) = shared();
        let (status, _) = handle_control(
            &shared,
            &req("GET", "/status?t=123", serde_json::Value::Null),
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

    fn page(method: &str, path: &str, body: Value) -> Request {
        Request {
            caller: Caller::Page,
            ..req(method, path, body)
        }
    }

    fn in_setup(shared: &Shared, stage: Stage) -> Receiver<SetupCommand> {
        let (tx, rx) = channel();
        let dir = std::env::temp_dir().join(format!("jd-daemon-setup-{}", std::process::id()));
        shared.enter_setup(Desk {
            view: View {
                stage,
                ..View::fresh(None)
            },
            commands: tx,
            paths: jd_platform::Paths {
                config: dir.join("config"),
                state: dir.join("state"),
                logs: dir.join("logs"),
            },
        });
        rx
    }

    #[test]
    fn the_settings_page_cannot_stop_the_daemon() {
        // It would take itself down with it, and stopping is the tray's and
        // the CLI's to do. Everything the page has a button for, it may ask.
        let (shared, rx) = shared();
        let (status, _) = handle_control(&shared, &page("POST", "/stop", Value::Null));
        assert_eq!(status, 403);
        assert!(rx.try_recv().is_err(), "nothing reached the sync thread");

        for path in ["/pause", "/resume", "/sync-now"] {
            let (status, body) = handle_control(&shared, &page("POST", path, Value::Null));
            assert_eq!(status, 200, "{path}");
            assert_eq!(body["ok"], true);
        }
    }

    #[test]
    fn a_request_that_changes_something_must_be_a_post() {
        // So that nothing a browser does on its own -- follow a link, load an
        // image -- can be one.
        let (shared, rx) = shared();
        for path in [
            "/pause",
            "/resume",
            "/sync-now",
            "/stop",
            "/unlink",
            "/dismiss",
        ] {
            let (status, _) = handle_control(&shared, &req("GET", path, Value::Null));
            assert_eq!(status, 405, "{path}");
        }
        assert!(rx.try_recv().is_err());
    }

    #[test]
    fn unlinking_needs_to_be_said_in_so_many_words() {
        let (shared, rx) = shared();
        let (status, _) = handle_control(&shared, &page("POST", "/unlink", json!({})));
        assert_eq!(status, 400);
        assert!(rx.try_recv().is_err());
        let (status, body) = handle_control(
            &shared,
            &page("POST", "/unlink", json!({ "confirm": true })),
        );
        assert_eq!(status, 200);
        assert_eq!(body["ok"], true);
        assert_eq!(rx.recv().unwrap(), Command::Unlink);
    }

    #[test]
    fn an_unlinked_daemon_answers_with_its_setup_and_takes_only_setup_requests() {
        let (shared, rx) = shared();
        let setup_rx = in_setup(&shared, Stage::Instance);

        let (status, body) = handle_control(&shared, &req("GET", "/status", Value::Null));
        assert_eq!(status, 200);
        assert_eq!(body["linked"], false);
        assert_eq!(body["setup"]["stage"], "instance");

        // Nothing to pause before there is anything syncing.
        let (status, _) = handle_control(&shared, &page("POST", "/pause", Value::Null));
        assert_eq!(status, 409);
        assert!(rx.try_recv().is_err());

        // A bad address is answered at once, not after a trip to the setup loop.
        let (status, body) = handle_control(
            &shared,
            &page(
                "POST",
                "/setup/begin",
                json!({ "base_url": "http://drive.example.org" }),
            ),
        );
        assert_eq!(status, 400);
        assert!(body["error"].as_str().unwrap().contains("https"));
        assert!(setup_rx.try_recv().is_err());

        let (status, body) = handle_control(
            &shared,
            &page(
                "POST",
                "/setup/begin",
                json!({ "base_url": "drive.example.org", "device_name": "Laptop" }),
            ),
        );
        assert_eq!(status, 200);
        assert_eq!(body["ok"], true);
        assert_eq!(
            setup_rx.recv().unwrap(),
            SetupCommand::Begin {
                base_url: "https://drive.example.org".into(),
                device_name: "Laptop".into()
            }
        );

        // Stop reaches the setup loop rather than a sync thread that is not
        // there.
        let (_, body) = handle_control(&shared, &req("POST", "/stop", Value::Null));
        assert_eq!(body["ok"], true);
        assert_eq!(setup_rx.recv().unwrap(), SetupCommand::Stop);
    }

    #[test]
    fn a_linked_daemon_refuses_setup_requests() {
        let (shared, _rx) = shared();
        for path in ["/setup/begin", "/setup/cancel", "/setup/finish"] {
            let (status, _) = handle_control(&shared, &page("POST", path, json!({})));
            assert_eq!(status, 409, "{path}");
        }
    }

    #[test]
    fn finishing_before_an_approval_is_refused() {
        let (shared, _rx) = shared();
        let setup_rx = in_setup(&shared, Stage::Approve);
        let (status, _) = handle_control(
            &shared,
            &page("POST", "/setup/finish", json!({ "sync_root": "/tmp/x" })),
        );
        assert_eq!(status, 409);
        assert!(setup_rx.try_recv().is_err());
    }

    #[test]
    fn a_folder_with_things_in_it_is_confirmed_before_it_is_synced() {
        let (shared, _rx) = shared();
        let setup_rx = in_setup(&shared, Stage::Folder);
        let dir = std::env::temp_dir().join(format!("jd-daemon-full-{}", std::process::id()));
        let root = dir.join("Documents");
        std::fs::create_dir_all(&root).unwrap();
        std::fs::write(root.join("a.txt"), b"a").unwrap();
        let root_text = root.display().to_string();

        let (status, body) = handle_control(
            &shared,
            &page("POST", "/setup/finish", json!({ "sync_root": root_text })),
        );
        assert_eq!(status, 200);
        assert_eq!(body["ok"], false);
        assert!(body["confirm"].as_str().unwrap().contains("1 item"));
        assert!(setup_rx.try_recv().is_err());

        let (_, body) = handle_control(
            &shared,
            &page(
                "POST",
                "/setup/finish",
                json!({ "sync_root": root_text, "confirm": true }),
            ),
        );
        assert_eq!(body["ok"], true);
        assert!(matches!(
            setup_rx.recv().unwrap(),
            SetupCommand::Finish { .. }
        ));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn every_route_is_reachable_and_none_is_listed_twice() {
        for (i, a) in ROUTES.iter().enumerate() {
            assert!(a.path.starts_with('/'));
            assert!(matches!(a.method, "GET" | "POST"), "{}", a.path);
            for b in &ROUTES[i + 1..] {
                assert_ne!(a.path, b.path, "listed twice");
            }
        }
    }

    #[test]
    fn the_folder_tree_is_sorted_the_way_a_person_reads_it_and_says_when_it_is_cut_short() {
        let row = |id: i64, parent: Option<i64>, name: &str| FolderRow {
            id,
            parent,
            name: name.into(),
            state: "synced",
            encrypted: false,
        };
        let json = folders_json(vec![
            row(3, None, "photos"),
            row(1, None, "Archive"),
            row(2, Some(1), "2024"),
        ]);
        let names: Vec<&str> = json["folders"]
            .as_array()
            .unwrap()
            .iter()
            .map(|f| f["name"].as_str().unwrap())
            .collect();
        assert_eq!(names, vec!["2024", "Archive", "photos"]);
        assert_eq!(json["truncated"], false);
        assert_eq!(
            json["selectable"], false,
            "no checkboxes the engine cannot honour"
        );

        let many: Vec<FolderRow> = (0..(MAX_REPORTED_FOLDERS as i64 + 10))
            .map(|i| row(i, None, &format!("f{i}")))
            .collect();
        let json = folders_json(many);
        assert_eq!(
            json["folders"].as_array().unwrap().len(),
            MAX_REPORTED_FOLDERS
        );
        assert_eq!(json["truncated"], true);
        assert_eq!(json["total"], MAX_REPORTED_FOLDERS + 10);
    }

    #[test]
    fn a_folder_kept_off_this_computer_says_so() {
        use jd_core::model::LocalStatus;
        assert_eq!(folder_state(&LocalStatus::OutOfScope), "not_here");
        assert_eq!(folder_state(&LocalStatus::Synced), "synced");
        assert_eq!(folder_state(&LocalStatus::PendingDownload), "pending");
    }

    #[test]
    fn the_status_answer_says_it_is_linked_and_which_version_is_answering() {
        let (shared, _rx) = shared();
        let (_, body) = handle_control(&shared, &req("GET", "/status", Value::Null));
        assert_eq!(body["linked"], true);
        assert_eq!(body["version"], crate::VERSION);
    }

    #[test]
    fn relinking_to_another_folder_starts_a_fresh_record_and_keeps_the_old_one() {
        // The old record, read against a different folder, says every file it
        // agreed on was deleted here -- and the engine would tell the server.
        let dir = std::env::temp_dir().join(format!("jd-daemon-store-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let db = dir.join("state.db");
        let config = |site: &str, root: &str| Config {
            base_url: site.into(),
            sync_root: root.into(),
            device_name: "d".into(),
            device_id: None,
            public_key: "pk".into(),
            poll_seconds: 30,
            excluded: Vec::new(),
            vault_enabled: false,
            autostart: false,
        };
        let a = config("https://one.example", "/home/u/Joinery Drive");

        let store = open_store(&db, &a).unwrap();
        store.set_meta("marker", "from the first link").unwrap();
        drop(store);

        // Same site, same folder: the record carries on.
        let store = open_store(&db, &a).unwrap();
        assert_eq!(
            store.get_meta("marker").unwrap().as_deref(),
            Some("from the first link")
        );
        drop(store);

        // Another folder: a fresh record, and the old one kept beside it.
        let b = config("https://one.example", "/home/u/Elsewhere");
        let store = open_store(&db, &b).unwrap();
        assert_eq!(store.get_meta("marker").unwrap(), None);
        drop(store);
        let kept = std::fs::read_dir(&dir)
            .unwrap()
            .flatten()
            .filter(|e| {
                e.file_name()
                    .to_string_lossy()
                    .starts_with("state.db.before-")
            })
            .count();
        assert!(kept >= 1, "the old record is moved aside, not deleted");

        // Another site, same folder: also fresh.
        let store = open_store(&db, &b).unwrap();
        store.set_meta("marker", "b").unwrap();
        drop(store);
        let c = config("https://two.example", "/home/u/Elsewhere");
        let store = open_store(&db, &c).unwrap();
        assert_eq!(store.get_meta("marker").unwrap(), None);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_record_from_before_it_said_whose_it_was_is_kept() {
        let dir = std::env::temp_dir().join(format!("jd-daemon-oldstore-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let db = dir.join("state.db");
        let store = Store::open(&db).unwrap();
        store.set_meta("marker", "older build").unwrap();
        drop(store);
        let config = Config {
            base_url: "https://one.example".into(),
            sync_root: "/r".into(),
            device_name: "d".into(),
            device_id: None,
            public_key: "pk".into(),
            poll_seconds: 30,
            excluded: Vec::new(),
            vault_enabled: false,
            autostart: false,
        };
        let store = open_store(&db, &config).unwrap();
        assert_eq!(
            store.get_meta("marker").unwrap().as_deref(),
            Some("older build")
        );
        assert_eq!(
            store.get_meta(STORE_SITE).unwrap().as_deref(),
            Some("https://one.example")
        );
        let _ = std::fs::remove_dir_all(&dir);
    }
}
