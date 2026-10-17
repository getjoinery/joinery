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
//! The queue is drained between passes, and a pass can be minutes into a large
//! transfer. So a stop or a pause is also said to the pass in progress the
//! moment it is posted (`Shared::send`), and the pass stands down at its next
//! safe point: between two operations, or between two chunks of a transfer
//! (`ExecEnv::stand_down`). The command still waits in the queue, and the loop
//! reads it the moment the pass is out.
//!
//! **When a pass runs**, in the order the conditions are checked: something on
//! disk changed and has gone quiet; the watcher admitted it lost events; the
//! poll interval elapsed; somebody asked for one. The last two are what make the
//! client work at all when the first two fail — a watcher that dies silently
//! degrades this into a thirty-second polling client rather than into a client
//! that has stopped.

use std::sync::atomic::{AtomicU8, Ordering};
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
    /// A person approved the deletes the mass-delete guard holds, by the
    /// fingerprint of the set they were shown (`route_approve_deletes`).
    ApproveDeletes(String),
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
    /// What the update thread knows, for the status answer (`crate::update`).
    /// Set once, when the thread starts.
    pub update: std::sync::OnceLock<Arc<crate::update::Handle>>,
    /// What the pass in progress has been asked to do: carry on, stand down
    /// for a pause, or stand down for a stop (`PASS_*`). Written by whoever
    /// posts the command, the moment it is posted (`send`), and read by the
    /// pass at each safe point (`standing_down`). Kept as which, not whether,
    /// so a resume lifts a pause and never a stop.
    stand_down: AtomicU8,
}

/// The pass in progress carries on.
const PASS_WANTED: u8 = 0;
/// It stands down for a pause, which a resume lifts.
const PASS_PAUSING: u8 = 1;
/// It stands down for a stop or an unlink, which nothing lifts: the sync
/// thread is ending, and the next one starts afresh (`attach`).
const PASS_STOPPING: u8 = 2;

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
            update: std::sync::OnceLock::new(),
            stand_down: AtomicU8::new(PASS_WANTED),
        }
    }

    pub fn snapshot(&self) -> Option<Snapshot> {
        self.snapshot.lock().ok().and_then(|s| s.clone())
    }

    /// Point the command queue at a new sync thread, forgetting what the last
    /// one published, and what the last one's pass was asked to do.
    pub fn attach(&self, commands: Sender<Command>) {
        if let Ok(mut slot) = self.commands.lock() {
            *slot = commands;
        }
        self.stand_down.store(PASS_WANTED, Ordering::SeqCst);
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
    ///
    /// A stop, an unlink or a pause is said to the pass in progress first, so
    /// it stands down at its next safe point rather than when its transfers
    /// end (`standing_down`); the loop still acts on the command once the pass
    /// is out. Whoever posts one is heard at once: the tray, the CLI, the
    /// settings page, and the update thread installing an update.
    pub fn send(&self, command: Command) -> bool {
        match command {
            Command::Stop | Command::Unlink => self.stand_down.store(PASS_STOPPING, Ordering::SeqCst),
            Command::Pause => {
                let _ = self.stand_down.compare_exchange(PASS_WANTED, PASS_PAUSING, Ordering::SeqCst, Ordering::SeqCst);
            }
            // Lifts a pause and never a stop: a resume posted just after a
            // stop would otherwise leave the stop to wait out the transfer.
            Command::Resume => {
                let _ = self.stand_down.compare_exchange(PASS_PAUSING, PASS_WANTED, Ordering::SeqCst, Ordering::SeqCst);
            }
            Command::SyncNow | Command::DismissIssue(_) | Command::ApproveDeletes(_) => {}
        }
        self.commands
            .lock()
            .map(|tx| tx.send(command).is_ok())
            .unwrap_or(false)
    }

    /// Should the pass in progress stand down now? The engine asks at each
    /// safe point (`ExecEnv::stand_down`).
    pub fn standing_down(&self) -> bool {
        self.stand_down.load(Ordering::SeqCst) != PASS_WANTED
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
    /// The held set of deletes last said in the log (`held_deletes_line`).
    held_logged: Option<String>,
    /// The vault probe refusal this process last wrote to its log
    /// (`say_a_refused_vault_probe`).
    probe_refusal_said: Option<String>,
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
            held_logged: None,
            probe_refusal_said: None,
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
            // A pass that stood down did so for a command posted to the queue
            // (`Shared::send`): read now, not a tick from now, so a stop or a
            // pause lands the moment the pass is out.
            if self.should_pass() && self.one_pass() {
                if let Some(exit) = self.drain_commands() {
                    return exit;
                }
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
                // Written down, not acted on: the next round reads it, and
                // carries the deletes out only if what it would hold is still
                // that set. Now, so the answer is not left waiting a poll.
                Command::ApproveDeletes(fingerprint) => {
                    let _ = jd_core::pass::approve_deletes(&self.store, &fingerprint);
                    self.last_poll_ms = 0;
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

    /// Run one pass. True when it stood down (`ExecEnv::stand_down`).
    fn one_pass(&mut self) -> bool {
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
        let stand_down = {
            let shared = Arc::clone(&self.shared);
            move || shared.standing_down()
        };
        let env = ExecEnv {
            store: &self.store,
            vfs: &self.vfs,
            api: &self.client,
            now_ms: &now,
            conflict_name: &namer,
            vault: self.vault.as_ref(),
            stand_down: &stand_down,
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
                if !outcome.root_unavailable {
                    let (held, line) = held_deletes_line(self.held_logged.as_deref(), &outcome.round);
                    if let Some(line) = line {
                        eprintln!("{line}");
                    }
                    self.held_logged = held;
                }
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
                self.say_a_refused_vault_probe();
                outcome.exec.stood_down
            }
            Err(e) => {
                self.blocker = Some(blocker_for(&e));
                false
            }
        }
    }

    /// Say once, in the log, that the server will not answer this device's
    /// vault probe. While it refuses, a rotated vault key cannot be noticed
    /// here, and the refusal raises no issue (it is held like an answer), so
    /// without this line nothing anywhere would show why nothing was said.
    fn say_a_refused_vault_probe(&mut self) {
        let refusal = self
            .store
            .get_meta(jd_core::pass::VAULT_PROBE_REFUSED)
            .ok()
            .flatten()
            .filter(|r| !r.is_empty());
        if let Some(line) = vault_probe_refusal_line(&mut self.probe_refusal_said, refusal) {
            eprintln!("{line}");
        }
    }

    fn recover(&mut self) {
        let namer = |name: &str, _suffix: u32| name.to_string();
        let now = now_ms;
        // Recovery moves no bytes and runs no op: nothing here to stand down.
        let env = ExecEnv {
            store: &self.store,
            vfs: &self.vfs,
            api: &self.client,
            now_ms: &now,
            conflict_name: &namer,
            vault: self.vault.as_ref(),
            stand_down: &|| false,
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
        // Holding a key the account has replaced is not being able to open
        // encrypted folders here.
        let vault = health.vault_opens(self.vault.is_some());
        let snapshot = Snapshot {
            health,
            paused: self.paused,
            device_name: self.config.device_name.clone(),
            base_url: self.config.base_url.clone(),
            sync_root: self.config.sync_root.display().to_string(),
            custody: self.custody.clone(),
            vault,
        };
        if let Ok(mut slot) = self.shared.snapshot.lock() {
            *slot = Some(snapshot);
        }
    }
}

/// What a state store says it is the record of.
const STORE_SITE: &str = "linked_base_url";
const STORE_ROOT: &str = "linked_sync_root";
const STORE_ACCOUNT: &str = "linked_account";

/// Open the state store for this configuration, starting a fresh one when the
/// store there is the record of a different site, folder or account.
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
///
/// The account is part of it. The same site and the same folder, linked again
/// to another account, is a different drive: its files are not the ones the
/// record agreed on. A store or a config from before the account was recorded
/// leaves that part unknown, and is judged on the site and folder alone.
pub fn open_store(path: &std::path::Path, config: &Config) -> Result<Store, String> {
    let root = config.sync_root.display().to_string();
    let store = Store::open(path).map_err(|e| e.to_string())?;
    let site = store.get_meta(STORE_SITE).map_err(|e| e.to_string())?;
    let folder = store.get_meta(STORE_ROOT).map_err(|e| e.to_string())?;
    let account = store.get_meta(STORE_ACCOUNT).map_err(|e| e.to_string())?;
    let same_place = match (site.as_deref(), folder.as_deref()) {
        (None, None) => true,
        (Some(s), Some(f)) => s == config.base_url && f == root,
        _ => false,
    };
    let same_account = match (account.as_deref(), config.account_id) {
        (Some(recorded), Some(linked)) => recorded == linked.to_string(),
        _ => true,
    };
    let same = same_place && same_account;
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
        let whose = match (&account, same_account) {
            (Some(account), false) => format!(" for account {account}"),
            _ => String::new(),
        };
        eprintln!(
            "the sync record was of {} in {}{whose}; kept it as {}.{aside} and started a fresh one",
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
    if let Some(account) = config.account_id {
        store
            .set_meta(STORE_ACCOUNT, &account.to_string())
            .map_err(|e| e.to_string())?;
    }
    Ok(store)
}

/// Say whose drive an existing state store is the record of, when it does not
/// say yet.
///
/// For a link made before the account was recorded, while its key can still
/// ask the server (`login` replacing it, `unlink`): without this, linking the
/// same folder to another account would carry the old record on as the new
/// one's. A store that already names an account is left as it is, and where
/// there is no store nothing is made.
pub fn note_store_account(path: &std::path::Path, account_id: i64) -> Result<(), String> {
    if !path.exists() {
        return Ok(());
    }
    let store = Store::open(path).map_err(|e| e.to_string())?;
    if store
        .get_meta(STORE_ACCOUNT)
        .map_err(|e| e.to_string())?
        .is_none()
    {
        store
            .set_meta(STORE_ACCOUNT, &account_id.to_string())
            .map_err(|e| e.to_string())?;
    }
    Ok(())
}

/// What the log says about deletes the mass-delete guard holds: a held set
/// once, when it is new, with its counts on each side; that an approved set
/// is being carried out; and that nothing is held any more. A box with no
/// tray and nobody reading `status` says so here. Returns the held set now
/// said, and the line to write, if there is one.
fn held_deletes_line(logged: Option<&str>, round: &jd_core::RoundOutcome) -> (Option<String>, Option<String>) {
    use jd_core::round::{held_fingerprint, DeleteDirection};
    let count = |pauses: &[jd_core::MassDeletePause], direction| -> usize {
        pauses.iter().filter(|p| p.direction == direction).map(|p| p.held.len()).sum()
    };
    if !round.paused.is_empty() {
        let set = held_fingerprint(&round.paused);
        if logged == Some(set.as_str()) {
            return (Some(set), None);
        }
        let line = format!(
            "joinery-drive: holding deletes until a person approves them (held set {set}): \
             {} to remove from this computer, {} to trash on the server; see: joinery-drive approve-deletes",
            count(&round.paused, DeleteDirection::Local),
            count(&round.paused, DeleteDirection::Remote),
        );
        return (Some(set), Some(line));
    }
    if !round.approved.is_empty() {
        let line = format!(
            "joinery-drive: carrying out approved deletes (held set {}): {} from this computer, {} on the server",
            held_fingerprint(&round.approved),
            count(&round.approved, DeleteDirection::Local),
            count(&round.approved, DeleteDirection::Remote),
        );
        return (None, Some(line));
    }
    match logged {
        Some(set) => (None, Some(format!("joinery-drive: deletes no longer held (held set {set} is gone)"))),
        None => (None, None),
    }
}

/// The log line for a vault probe refusal, once per refusal: `None` while the
/// refusal is the one already said, or there is none.
fn vault_probe_refusal_line(said: &mut Option<String>, now: Option<String>) -> Option<String> {
    if *said == now {
        return None;
    }
    *said = now.clone();
    now.map(|why| {
        format!(
            "warning: the server does not let this device ask about the vault key ({why}); \
             a rotation of the key cannot be noticed here"
        )
    })
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
    Route { method: "POST", path: "/approve-deletes", when: When::Linked, page: true, handle: route_approve_deletes },
    Route { method: "POST", path: "/autostart", when: When::Linked, page: true, handle: route_autostart },
    Route { method: "POST", path: "/unlink", when: When::Linked, page: true, handle: route_unlink },
    Route { method: "POST", path: "/check-update", when: When::Always, page: true, handle: route_check_update },
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
    if let Some(update) = shared.update.get() {
        body["update"] = update.json();
    }
    (200, body)
}

fn route_check_update(shared: &Shared, _: &Request) -> (u16, Value) {
    match shared.update.get().map(|u| u.check_now()) {
        Some(Ok(())) => ok(true),
        // A 409 with the reason, so the page shows why rather than a
        // generic failure: no key in this build, most often.
        Some(Err(why)) => (409, json!({ "ok": false, "error": why })),
        None => (
            409,
            json!({ "ok": false, "error": "updates are not running" }),
        ),
    }
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

/// Approve the deletes the mass-delete guard holds, by the fingerprint of the
/// set the person was shown (`held_set` in the status answer).
///
/// Refused here when that is not the set the daemon last said it holds, so a
/// person answering an old question hears so at once rather than finding
/// nothing deleted. The round that reads the approval checks again, against
/// what it would hold by then, which is the check that decides; before the
/// first pass there is nothing here to check against, and that one alone
/// does.
fn route_approve_deletes(shared: &Shared, request: &Request) -> (u16, Value) {
    let Some(fingerprint) = request
        .body
        .get("fingerprint")
        .and_then(Value::as_str)
        .filter(|f| jd_core::pass::is_a_held_set(f))
    else {
        return (400, json!({ "error": "approve-deletes needs the fingerprint of the held set" }));
    };
    if let Some(s) = shared.snapshot() {
        match held_deletes(&s) {
            None => return (409, json!({ "error": "no deletes are being held" })),
            Some((_, held)) if held != fingerprint => {
                return (
                    409,
                    json!({ "error": "the deletes being held have changed since that was shown; look again", "held_set": held }),
                )
            }
            Some(_) => {}
        }
    }
    ok(shared.send(Command::ApproveDeletes(fingerprint.to_string())))
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

/// The held-deletes issue, if one is open, and the set it names.
fn held_deletes(s: &Snapshot) -> Option<(&crate::health::Issue, &str)> {
    s.health
        .issues
        .iter()
        .find(|i| i.kind == jd_core::pass::DELETES_HELD)
        .and_then(|i| jd_core::pass::held_deletes_fingerprint(&i.detail).map(|set| (i, set)))
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
        "vault_relink": s.health.vault_needs_relink(),
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
        // On its own, outside the capped list below: the one question a shell
        // has to put to a person before anything more is deleted.
        "held_deletes": held_deletes(s).map(|(i, set)| json!({ "issue_id": i.id, "summary": i.summary, "detail": i.detail, "held_set": set })),
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

    /// A snapshot holding these issues, published as the sync thread does.
    fn publish_issues(shared: &Shared, issues: Vec<crate::health::Issue>) {
        *shared.snapshot.lock().unwrap() = Some(Snapshot {
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
        });
    }

    fn issue(id: i64, kind: &str, detail: &str) -> crate::health::Issue {
        crate::health::Issue {
            id,
            kind: kind.into(),
            detail: detail.into(),
            summary: detail.into(),
            created_at: 0,
        }
    }

    fn held(id: i64, set: &str) -> crate::health::Issue {
        issue(
            id,
            jd_core::pass::DELETES_HELD,
            &format!("61 items deleted on this computer have not been deleted on the server. To go ahead, approve these deletes (held set {set})."),
        )
    }

    fn approve(shared: &Shared, body: serde_json::Value) -> (u16, serde_json::Value) {
        handle_control(shared, &req("POST", "/approve-deletes", body))
    }

    #[test]
    fn approving_the_held_deletes_reaches_the_sync_thread_with_the_set_shown() {
        let (shared, rx) = shared();
        publish_issues(&shared, vec![held(7, "0123456789abcdef")]);
        let (status, body) = approve(&shared, serde_json::json!({ "fingerprint": "0123456789abcdef" }));
        assert_eq!((status, body["ok"].as_bool()), (200, Some(true)), "{body}");
        assert_eq!(rx.recv().unwrap(), Command::ApproveDeletes("0123456789abcdef".into()));
    }

    #[test]
    fn approving_a_set_that_is_not_the_one_held_is_refused_and_sends_nothing() {
        // The person is answering an old question: one more file has gone
        // since, or the files came back. Told at once, with what is held now.
        let (shared, rx) = shared();
        publish_issues(&shared, vec![held(7, "fedcba9876543210")]);
        let (status, body) = approve(&shared, serde_json::json!({ "fingerprint": "0123456789abcdef" }));
        assert_eq!(status, 409, "{body}");
        assert_eq!(body["held_set"], "fedcba9876543210");
        publish_issues(&shared, vec![]);
        let (status, _) = approve(&shared, serde_json::json!({ "fingerprint": "fedcba9876543210" }));
        assert_eq!(status, 409, "nothing is held");
        assert!(rx.try_recv().is_err(), "an approval of a set nobody holds was sent");
    }

    #[test]
    fn approving_without_saying_which_set_is_a_bad_request() {
        let (shared, rx) = shared();
        publish_issues(&shared, vec![held(7, "0123456789abcdef")]);
        for body in [serde_json::json!({}), serde_json::json!({ "fingerprint": "everything" })] {
            let (status, _) = approve(&shared, body);
            assert_eq!(status, 400);
        }
        assert!(rx.try_recv().is_err());
    }

    #[test]
    fn the_held_deletes_are_in_the_status_answer_however_many_issues_are_open() {
        // The issue list is capped; the one question a shell has to put to a
        // person before anything more is deleted is not left to fall past it.
        let (shared, _rx) = shared();
        let mut issues: Vec<crate::health::Issue> =
            (0..MAX_REPORTED_ISSUES as i64 + 5).map(|i| issue(i, "unsyncable", "CaseClash { with: \"a\" }")).collect();
        issues.push(held(900, "0123456789abcdef"));
        publish_issues(&shared, issues);
        let json = snapshot_json(&shared.snapshot().unwrap());
        assert_eq!(json["held_deletes"]["held_set"], "0123456789abcdef");
        assert_eq!(json["held_deletes"]["issue_id"], 900);
        publish_issues(&shared, vec![]);
        assert!(snapshot_json(&shared.snapshot().unwrap())["held_deletes"].is_null());
    }

    #[test]
    fn a_held_set_is_logged_once_when_it_is_new_and_again_when_it_goes() {
        // A headless box: nothing reads the tray, so the log is where a hold
        // shows. Once per held set, not once per pass.
        let pause = |ids: &[i64]| jd_core::MassDeletePause {
            direction: jd_core::round::DeleteDirection::Remote,
            would_delete: ids.len(),
            synced_total: 400,
            held: ids.iter().copied().map(jd_core::EntityId::file).collect(),
        };
        let held = jd_core::RoundOutcome { paused: vec![pause(&[1, 2, 3])], ..Default::default() };
        let set = jd_core::round::held_fingerprint(&held.paused);

        let (now, line) = held_deletes_line(None, &held);
        assert_eq!(now.as_deref(), Some(set.as_str()));
        let line = line.expect("a new hold is logged");
        assert!(line.contains(&set) && line.contains("0 to remove from this computer, 3 to trash on the server"), "{line}");

        assert_eq!(held_deletes_line(Some(&set), &held), (Some(set.clone()), None), "logged again on the next pass");

        let grown = jd_core::RoundOutcome { paused: vec![pause(&[1, 2, 3, 4])], ..Default::default() };
        let (now, line) = held_deletes_line(Some(&set), &grown);
        assert_ne!(now.as_deref(), Some(set.as_str()));
        assert!(line.is_some(), "a changed set is a new one");

        let approved = jd_core::RoundOutcome { approved: vec![pause(&[1, 2, 3])], ..Default::default() };
        let (now, line) = held_deletes_line(Some(&set), &approved);
        assert_eq!(now, None);
        assert!(line.unwrap().contains("carrying out approved deletes"));

        let (now, line) = held_deletes_line(Some(&set), &jd_core::RoundOutcome::default());
        assert_eq!(now, None);
        assert!(line.unwrap().contains("no longer held"));
        assert_eq!(held_deletes_line(None, &jd_core::RoundOutcome::default()), (None, None));
    }

    #[test]
    fn a_status_answer_says_when_encrypted_folders_need_this_computer_linked_again() {
        // The CLI and every shell read these two fields; the first alone said
        // "folders can be opened on this device" to a device holding a key
        // the account had replaced.
        let health = Health {
            indicator: Indicator::Attention,
            entries: Default::default(),
            pending_ops: 0,
            issues: vec![crate::health::Issue {
                id: 1,
                kind: jd_core::pass::VAULT_RELINK.into(),
                detail: String::new(),
                summary: String::new(),
                created_at: 0,
            }],
            blocker: None,
            last_pass_ms: None,
            cursor: 0,
        };
        let snapshot = Snapshot {
            vault: health.vault_opens(true),
            health,
            paused: false,
            device_name: "device-a".into(),
            base_url: "https://example.com".into(),
            sync_root: "/home/u/Joinery Drive".into(),
            custody: "in a file".into(),
        };
        let json = snapshot_json(&snapshot);
        assert_eq!(json["vault"], false);
        assert_eq!(json["vault_relink"], true);
    }

    #[test]
    fn a_refused_vault_probe_is_said_once_per_refusal() {
        // Every pass reads the note; the log hears of a refusal once, again
        // only when it changes, and again after one was answered in between.
        let mut said = None;
        let refused = || Some("403 Forbidden".to_string());
        let line = vault_probe_refusal_line(&mut said, refused()).expect("said the first time");
        assert!(line.contains("does not let this device ask about the vault key"), "{line}");
        assert!(line.contains("403 Forbidden"), "{line}");
        assert_eq!(vault_probe_refusal_line(&mut said, refused()), None, "said every pass");
        assert!(vault_probe_refusal_line(&mut said, Some("404 no such action".into())).is_some());
        assert_eq!(vault_probe_refusal_line(&mut said, None), None, "an answer is not a warning");
        assert!(vault_probe_refusal_line(&mut said, refused()).is_some(), "refused again after an answer");
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
    fn a_stop_or_a_pause_reaches_the_pass_at_once_and_a_resume_lifts_only_a_pause() {
        let (shared, rx) = shared();
        let post = |path: &str, body: Value| handle_control(&shared, &req("POST", path, body)).0;
        assert!(!shared.standing_down());
        assert_eq!(post("/pause", Value::Null), 200);
        assert!(shared.standing_down(), "a pause stands the pass down");
        assert_eq!(post("/resume", Value::Null), 200);
        assert!(!shared.standing_down(), "a resume lifts the pause");
        assert_eq!(post("/sync-now", Value::Null), 200);
        assert!(!shared.standing_down());
        assert_eq!(post("/stop", Value::Null), 200);
        assert!(shared.standing_down(), "a stop stands the pass down");
        assert_eq!(post("/resume", Value::Null), 200);
        assert!(shared.standing_down(), "a resume never lifts a stop");
        // Each is still queued: the loop acts on it once the pass is out.
        let queued: Vec<Command> = rx.try_iter().collect();
        assert_eq!(
            queued,
            [Command::Pause, Command::Resume, Command::SyncNow, Command::Stop, Command::Resume]
        );

        // A new sync thread starts with a pass that is wanted, and unlinking
        // stops it as a stop does.
        let (tx, _rx) = channel();
        shared.attach(tx);
        assert!(!shared.standing_down());
        assert_eq!(post("/unlink", json!({ "confirm": true })), 200);
        assert!(shared.standing_down(), "an unlink stands the pass down");
    }

    /// The engine's mock server (`jd_sim::MockServer`) over HTTP on 127.0.0.1,
    /// so a real daemon, with its real client, runs real passes against it.
    /// While `slow` is set a download goes out 4 KiB every 25 ms, as over a
    /// slow link: a stop that waited for it would be seen waiting. `sent`
    /// counts the download bytes written.
    struct Drive {
        base_url: String,
        server: jd_sim::MockServer,
        slow: Arc<std::sync::atomic::AtomicBool>,
        sent: Arc<std::sync::atomic::AtomicU64>,
    }

    impl Drive {
        fn start() -> Drive {
            let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
            let base_url = format!("http://{}", listener.local_addr().unwrap());
            let drive = Drive {
                base_url,
                server: jd_sim::MockServer::new(jd_sim::SimClock::new()),
                slow: Arc::new(std::sync::atomic::AtomicBool::new(true)),
                sent: Arc::default(),
            };
            let (server, slow, sent) = (drive.server.clone(), Arc::clone(&drive.slow), Arc::clone(&drive.sent));
            std::thread::spawn(move || {
                for stream in listener.incoming().flatten() {
                    let (server, slow, sent) = (server.clone(), Arc::clone(&slow), Arc::clone(&sent));
                    std::thread::spawn(move || answer_as_the_server(stream, &server, &slow, &sent));
                }
            });
            drive
        }

        fn sent(&self) -> u64 {
            self.sent.load(Ordering::SeqCst)
        }
    }

    /// One request: an action, as the platform's `/api/v1/action/{name}`
    /// envelope, or a signed download link, honouring a `Range`.
    fn answer_as_the_server(
        stream: std::net::TcpStream,
        server: &jd_sim::MockServer,
        slow: &std::sync::atomic::AtomicBool,
        sent: &std::sync::atomic::AtomicU64,
    ) {
        use std::io::{BufRead, BufReader, Read, Write};
        let mut out = match stream.try_clone() {
            Ok(s) => s,
            Err(_) => return,
        };
        let mut reader = BufReader::new(stream);
        let mut line = String::new();
        if reader.read_line(&mut line).is_err() {
            return;
        }
        let (mut length, mut key, mut from) = (0usize, None, 0u64);
        loop {
            let mut header = String::new();
            if reader.read_line(&mut header).unwrap_or(0) == 0 || header == "\r\n" {
                break;
            }
            let (name, value) = header.split_once(':').unwrap_or(("", ""));
            let value = value.trim();
            match name.to_ascii_lowercase().as_str() {
                "content-length" => length = value.parse().unwrap_or(0),
                "idempotency-key" => key = Some(value.to_string()),
                "range" => from = value.trim_start_matches("bytes=").trim_end_matches('-').parse().unwrap_or(0),
                _ => {}
            }
        }
        let mut body = vec![0u8; length];
        if reader.read_exact(&mut body).is_err() {
            return;
        }
        let path = line.split_whitespace().nth(1).unwrap_or("/").to_string();
        let refused = |r: jd_sim::server::ApiRefusal| {
            (r.status, json!({ "errortype": r.errortype, "error": r.message, "data": r.data }).to_string())
        };
        if let Some(name) = path.strip_prefix("/api/v1/action/") {
            let body: Value = serde_json::from_slice(&body).unwrap_or(Value::Null);
            let answer = match &key {
                Some(k) => server.action_idempotent(name, &body, k),
                None => server.action(name, &body),
            };
            let (status, text) = match answer {
                Ok(data) => (200, json!({ "data": data }).to_string()),
                Err(r) => refused(r),
            };
            let _ = write!(
                out,
                "HTTP/1.1 {status} Answer\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{text}",
                text.len()
            );
            return;
        }
        match server.download(&path, from) {
            Ok(bytes) => {
                let status = if from > 0 { 206 } else { 200 };
                let _ = write!(
                    out,
                    "HTTP/1.1 {status} Bytes\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                    bytes.len()
                );
                for piece in bytes.chunks(4096) {
                    if out.write_all(piece).is_err() {
                        return;
                    }
                    sent.fetch_add(piece.len() as u64, Ordering::SeqCst);
                    if slow.load(Ordering::SeqCst) {
                        std::thread::sleep(Duration::from_millis(25));
                    }
                }
            }
            Err(r) => {
                let (status, text) = refused(r);
                let _ = write!(
                    out,
                    "HTTP/1.1 {status} Refused\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{text}",
                    text.len()
                );
            }
        }
    }

    /// A linked daemon syncing an empty folder with a `Drive`, running on a
    /// thread of its own.
    struct Running {
        shared: Arc<Shared>,
        root: std::path::PathBuf,
        /// Its exit, when it comes, with every op it left open, as
        /// `kind State attempts=N`.
        exited: Receiver<(Exit, Vec<String>)>,
    }

    fn a_daemon_syncing_with(drive: &Drive, tag: &str) -> Running {
        let dir = std::env::temp_dir().join(format!("jd-daemon-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(dir.join("Drive")).unwrap();
        // Canonical: on a Mac the temporary directory is a symlink.
        let dir = std::fs::canonicalize(&dir).unwrap();
        let root = dir.join("Drive");
        let config = Config {
            base_url: drive.base_url.clone(),
            sync_root: root.clone(),
            device_name: "Laptop".into(),
            device_id: Some(1),
            account_id: None,
            public_key: "pk".into(),
            poll_seconds: 30,
            excluded: Vec::new(),
            vault_enabled: false,
            autostart: false,
        };
        let (shared, rx) = shared();
        let (done, exited) = channel();
        let theirs = Arc::clone(&shared);
        std::thread::spawn(move || {
            let store = Store::open_in_memory().unwrap();
            let vfs = OsVfs::new(config.sync_root.clone(), dir.join("spool")).unwrap();
            let credentials = Credentials { public_key: "pk".into(), secret_key: "sk".into() };
            let mut daemon = Daemon::new(config, store, vfs, credentials, theirs, rx, "test".into(), None, None);
            let exit = daemon.run();
            let open = daemon
                .store
                .queued_ops()
                .unwrap()
                .into_iter()
                .chain(daemon.store.interrupted_ops().unwrap())
                .map(|op| format!("{} {:?} attempts={}", op.kind, op.state, op.attempts))
                .collect();
            let _ = done.send((exit, open));
        });
        Running { shared, root, exited }
    }

    fn wait_for(what: &str, within: Duration, done: impl Fn() -> bool) {
        let started = std::time::Instant::now();
        while !done() {
            assert!(started.elapsed() < within, "{what}: not within {within:?}");
            std::thread::sleep(Duration::from_millis(20));
        }
    }

    #[test]
    fn a_stop_lands_in_the_middle_of_a_transfer_not_when_it_ends() {
        // Eight MiB at 160 KiB a second: fifty seconds of transfer, against a
        // stop that is to land at once. The installer and an update's restart
        // both wait for the daemon to exit, and before a stop reached the pass
        // in progress, they waited out whatever it was moving. Within one tick
        // of the loop: the stop is read the moment the pass stands down, with
        // no sleep between.
        let drive = Drive::start();
        let body = vec![7u8; 8 << 20];
        drive.server.seed_file(None, "big.bin", &body);
        let Running { shared, root, exited } = a_daemon_syncing_with(&drive, "stop");
        wait_for("the download starting", Duration::from_secs(30), || drive.sent() > 0);

        let asked = std::time::Instant::now();
        let (status, answer) = handle_control(&shared, &req("POST", "/stop", Value::Null));
        assert_eq!((status, &answer["ok"]), (200, &json!(true)));
        let (exit, open) = exited
            .recv_timeout(Duration::from_secs(15))
            .expect("the daemon was still running fifteen seconds after a stop: it waited for the transfer");
        let took = asked.elapsed();
        assert_eq!(exit, Exit::Stopped);
        assert!(took < TICK, "the stop took {took:?}");
        assert!(drive.sent() < body.len() as u64, "construction: the download had not finished");
        assert_eq!(open, ["download Queued attempts=0"], "back on the queue as it was, for the next start");
        assert!(!root.join("big.bin").exists(), "no partial file");
    }

    #[test]
    fn a_pause_stands_a_transfer_down_and_a_resume_finishes_it() {
        let drive = Drive::start();
        let body: Vec<u8> = (0..4u32 << 20).map(|i| (i % 251) as u8).collect();
        drive.server.seed_file(None, "big.bin", &body);
        let Running { shared, root, exited } = a_daemon_syncing_with(&drive, "pause");
        wait_for("the download starting", Duration::from_secs(30), || drive.sent() > 0);

        // Paused is published only once the pass is out and the loop has read
        // the pause, which it does the moment the pass stands down: within one
        // tick. Twenty-five seconds of transfer were left.
        assert_eq!(handle_control(&shared, &req("POST", "/pause", Value::Null)).0, 200);
        wait_for("the pause landing", TICK, || shared.snapshot().is_some_and(|s| s.paused));
        assert!(drive.sent() < body.len() as u64, "construction: the download had not finished");
        assert!(!root.join("big.bin").exists(), "no partial file");

        // Resumed, the op the pause put back on the queue runs again, and the
        // file lands whole.
        drive.slow.store(false, Ordering::SeqCst);
        assert_eq!(handle_control(&shared, &req("POST", "/resume", Value::Null)).0, 200);
        wait_for("the file landing after the resume", Duration::from_secs(30), || {
            std::fs::metadata(root.join("big.bin")).is_ok_and(|m| m.len() == body.len() as u64)
        });
        assert_eq!(std::fs::read(root.join("big.bin")).unwrap(), body);

        assert_eq!(handle_control(&shared, &req("POST", "/stop", Value::Null)).0, 200);
        let (exit, open) = exited.recv_timeout(Duration::from_secs(15)).expect("the daemon stopped");
        assert_eq!(exit, Exit::Stopped);
        assert!(open.is_empty(), "{open:?}");
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
            account_id: None,
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
            account_id: Some(41),
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
        assert_eq!(
            store.get_meta(STORE_ACCOUNT).unwrap().as_deref(),
            Some("41")
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    fn linked_to(account_id: Option<i64>) -> Config {
        Config {
            base_url: "https://one.example".into(),
            sync_root: "/home/u/Joinery Drive".into(),
            device_name: "d".into(),
            device_id: None,
            account_id,
            public_key: "pk".into(),
            poll_seconds: 30,
            excluded: Vec::new(),
            vault_enabled: false,
            autostart: false,
        }
    }

    /// The records moved aside in `dir` (not their `-wal` and `-shm` files).
    fn records_set_aside(dir: &std::path::Path) -> Vec<std::path::PathBuf> {
        std::fs::read_dir(dir)
            .unwrap()
            .flatten()
            .map(|e| e.path())
            .filter(|p| {
                let name = p.file_name().unwrap().to_string_lossy().to_string();
                name.starts_with("state.db.before-")
                    && !name.ends_with("-wal")
                    && !name.ends_with("-shm")
            })
            .collect()
    }

    fn set_aside_in(dir: &std::path::Path) -> usize {
        records_set_aside(dir).len()
    }

    #[test]
    fn the_same_folder_linked_to_another_account_starts_a_fresh_record() {
        // Same site, same folder, another person's drive: the old record says
        // which files were agreed with an account this link cannot see, and read
        // against the new one it would take every one of them as deleted there.
        let dir = std::env::temp_dir().join(format!("jd-daemon-account-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let db = dir.join("state.db");

        let store = open_store(&db, &linked_to(Some(41))).unwrap();
        store.set_meta("marker", "account 41").unwrap();
        drop(store);

        // The same account linked again (a new device row, a new key): the
        // record carries on.
        let store = open_store(&db, &linked_to(Some(41))).unwrap();
        assert_eq!(
            store.get_meta("marker").unwrap().as_deref(),
            Some("account 41")
        );
        drop(store);
        assert_eq!(set_aside_in(&dir), 0);

        let store = open_store(&db, &linked_to(Some(42))).unwrap();
        assert_eq!(store.get_meta("marker").unwrap(), None, "a fresh record");
        assert_eq!(
            store.get_meta(STORE_ACCOUNT).unwrap().as_deref(),
            Some("42")
        );
        drop(store);
        assert_eq!(set_aside_in(&dir), 1, "the old one kept beside it");

        // A config from before the account was recorded is judged on the site
        // and folder alone.
        let store = open_store(&db, &linked_to(None)).unwrap();
        assert_eq!(
            store.get_meta(STORE_ACCOUNT).unwrap().as_deref(),
            Some("42")
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_record_from_before_accounts_is_told_whose_it_was_before_another_account_links() {
        // A store from a link that never recorded its account. `login` and
        // `unlink` ask the server with the old key and write the answer in, so
        // the next link of another account sets it aside.
        let dir = std::env::temp_dir().join(format!("jd-daemon-note-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let db = dir.join("state.db");

        note_store_account(&db, 41).unwrap();
        assert!(!db.exists(), "no store is made where there was none");

        let store = open_store(&db, &linked_to(None)).unwrap();
        store.set_meta("marker", "older link").unwrap();
        drop(store);
        note_store_account(&db, 41).unwrap();
        note_store_account(&db, 99).unwrap();
        let store = open_store(&db, &linked_to(Some(42))).unwrap();
        assert_eq!(store.get_meta("marker").unwrap(), None);
        drop(store);
        let aside = records_set_aside(&dir);
        assert_eq!(aside.len(), 1);
        let old = Store::open(&aside[0]).unwrap();
        assert_eq!(
            old.get_meta(STORE_ACCOUNT).unwrap().as_deref(),
            Some("41"),
            "the first answer stands"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }
}
