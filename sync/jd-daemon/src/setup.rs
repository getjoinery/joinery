//! The first run: linking this computer from the settings page.
//!
//! A daemon with no config used to refuse to start and tell the user to run a
//! command. That is fine for the person who installed it from a terminal and
//! nobody else: the tray could start it, and it would exit, and the tray would
//! say "not running" forever. So a daemon with nothing to sync starts anyway,
//! opens its control channel, and waits here — and the settings page, opened
//! from the tray, walks the user through the same four steps the CLI's `login`
//! takes, in the order the spec gives them: the instance's address, the
//! approval in a signed-in browser, where the files should go, and start.
//!
//! The control thread never does any of this work. Contacting a server can take
//! fifteen seconds to fail, and the control thread answering the tray must not
//! be the thing that waits. It posts a [`SetupCommand`] and reads back a
//! [`View`], exactly as it does with the sync thread once the device is linked.
//!
//! A session that cannot open the computer's credential store (a Mac reached
//! over SSH) links plain folders only: no key is offered for the vault to be
//! sealed to, and the folder step says how to have encrypted folders
//! (`link::VaultHandoff`).
//!
//! **The credential is stored the moment it arrives**, before the user has
//! picked a folder, because the server hands it over once and scrubs it. What
//! the folder step still needs is written beside it in `pending_link.json` (no
//! secret in it), so a daemon restarted between the approval and the folder
//! picks up at the folder rather than leaving a live credential on the server
//! that nothing here can name.

use std::path::{Path, PathBuf};
use std::sync::mpsc::{channel, Receiver, Sender};
use std::time::{Duration, Instant};

use jd_platform::{Paths, Secret, SecretStore};
use jd_proto::{Client, Credentials};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use crate::config::{Config, ConfigError, DEFAULT_POLL_SECONDS};
use crate::daemon::Shared;
use crate::link;

/// How often the setup loop looks at its queue. Short, because a person is
/// watching the page; the loop does nothing expensive while idle.
const TICK: Duration = Duration::from_millis(250);

/// How long to keep asking about one approval. The server's code lasts ten
/// minutes and answers "expired" after that; this is the backstop for a server
/// that stopped answering at all, so the page cannot wait forever.
const GIVE_UP_AFTER: Duration = Duration::from_secs(15 * 60);

/// Where the setup is.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Stage {
    /// Asking for the instance's address.
    Instance,
    /// Asking the server to start a link.
    Starting,
    /// Waiting for a person to approve the link in their browser.
    Approve,
    /// Approved; asking where the files should go.
    Folder,
    /// Taking this computer off its account, on the way back to the start.
    Unlinking,
}

impl Stage {
    pub fn as_str(&self) -> &'static str {
        match self {
            Stage::Instance => "instance",
            Stage::Starting => "starting",
            Stage::Approve => "approve",
            Stage::Folder => "folder",
            Stage::Unlinking => "unlinking",
        }
    }
}

/// What the page shows while this computer is not linked.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct View {
    pub stage: Stage,
    /// Something went wrong with the last step, in words for the user.
    pub error: Option<String>,
    /// Something worth saying that is not a failure ("Unlinked…").
    pub notice: Option<String>,
    pub base_url: String,
    pub device_name: String,
    pub link_code: String,
    pub verify_url: String,
    pub expires_time: String,
    /// Whether the approval page was opened for the user, or they have to
    /// open it themselves.
    pub browser_opened: bool,
    pub suggested_root: String,
    /// Whether encrypted folders were enabled when the link was approved.
    pub vault: bool,
    /// Why they were not, when there is more to say than that the box was
    /// not ticked (`link::VaultHandoff::sentence`).
    pub vault_note: Option<String>,
}

impl View {
    pub fn fresh(notice: Option<String>) -> View {
        View {
            stage: Stage::Instance,
            error: None,
            notice,
            base_url: String::new(),
            device_name: jd_platform::suggested_device_name(),
            link_code: String::new(),
            verify_url: String::new(),
            expires_time: String::new(),
            browser_opened: false,
            suggested_root: jd_platform::default_sync_root().display().to_string(),
            vault: false,
            vault_note: None,
        }
    }

    pub fn unlinking() -> View {
        View {
            stage: Stage::Unlinking,
            ..View::fresh(None)
        }
    }
}

/// What the page can ask the setup loop to do.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum SetupCommand {
    /// Start a link with this instance, under this device name.
    Begin {
        base_url: String,
        device_name: String,
    },
    /// Go back to the start. After an approval this also takes the new
    /// credential off the server, so cancelling never leaves a live key behind.
    Cancel,
    /// Sync this folder, and start. Already checked by the control thread;
    /// checked again here, where the folder is made.
    Finish { sync_root: PathBuf, autostart: bool },
    /// Stop the daemon.
    Stop,
}

/// What the control thread holds while this computer is not linked.
pub struct Desk {
    pub view: View,
    pub commands: Sender<SetupCommand>,
    pub paths: Paths,
}

/// The status answer while unlinked.
///
/// Carries `linked: false` and the same `indicator` and `summary` fields a
/// linked daemon's answer does, so the CLI and an older tray still say
/// something true about it.
pub fn status_json(view: &View) -> Value {
    let summary = match view.stage {
        Stage::Instance => "Not set up yet",
        Stage::Starting => "Setting up \u{2014} contacting your instance",
        Stage::Approve => "Setting up \u{2014} waiting for approval in your browser",
        Stage::Folder => "Setting up \u{2014} choose where your files go",
        Stage::Unlinking => "Unlinking this computer",
    };
    json!({
        "linked": false,
        "indicator": "stopped",
        "summary": summary,
        "version": crate::VERSION,
        "setup": {
            "stage": view.stage.as_str(),
            "error": view.error,
            "notice": view.notice,
            "base_url": view.base_url,
            "device_name": view.device_name,
            "link_code": view.link_code,
            "verify_url": view.verify_url,
            "expires_time": view.expires_time,
            "browser_opened": view.browser_opened,
            "suggested_root": view.suggested_root,
            "vault": view.vault,
            "vault_note": view.vault_note,
        },
    })
}

/// Turn what a person typed into an instance address, or say why not.
///
/// Forgiving about the scheme — people type `drive.example.org` — and strict
/// about what the scheme turns out to be. The link ceremony hands this computer
/// a credential that is sent with every request after it, so plain `http` is
/// refused except to this machine itself (`localhost`, `127.0.0.1`, `[::1]`),
/// where there is no network for it to cross. The settings page and
/// `joinery-drive login` both come through here, so they refuse the same
/// addresses in the same words.
pub fn instance_url(typed: &str) -> Result<String, String> {
    let typed = typed.trim();
    if typed.is_empty() {
        return Err(
            "Enter the address of your Joinery site, like https://drive.example.org.".into(),
        );
    }
    if typed.chars().any(|c| c.is_whitespace() || c.is_control()) {
        return Err("An address has no spaces in it.".into());
    }
    let (scheme, rest) = match typed.find("://") {
        Some(i) => (typed[..i].to_ascii_lowercase(), &typed[i + 3..]),
        None => ("https".to_string(), typed),
    };
    if scheme != "https" && scheme != "http" {
        return Err("The address has to start with https://.".into());
    }
    let not_an_address = || "That does not look like a web address.".to_string();
    let rest = rest.trim_end_matches('/');
    let host_port = rest.split(['/', '?', '#']).next().unwrap_or("");
    let (host, port) = match host_port.rsplit_once(':') {
        // An IPv6 literal has colons of its own, inside its brackets.
        Some((h, p)) if !p.contains(']') => (h, Some(p)),
        _ => (host_port, None),
    };
    let host_ok = !host.is_empty()
        && (host.starts_with('[') && host.ends_with(']')
            || host
                .chars()
                .all(|c| c.is_ascii_alphanumeric() || c == '.' || c == '-'));
    let port_ok = port.is_none_or(|p| !p.is_empty() && p.chars().all(|c| c.is_ascii_digit()));
    if !host_ok || !port_ok {
        return Err(not_an_address());
    }
    let host = host.to_ascii_lowercase();
    if scheme == "http" && !matches!(host.as_str(), "localhost" | "127.0.0.1" | "[::1]") {
        return Err(
            "The address has to start with https://. Over plain http, the key this computer is given when it links would cross the network unencrypted, where anyone along the way could read it.".into(),
        );
    }
    Ok(format!("{scheme}://{rest}"))
}

/// What this computer will be called, from what a person typed.
///
/// The name is shown in the account's list of devices and is written into the
/// names of conflict copies (`report (conflicted copy 2026-10-15 from Laptop)`),
/// so it keeps to what every disk accepts in a file name. Left empty, it is the
/// machine's own name.
pub fn device_name(typed: &str) -> Result<String, String> {
    let name = typed.trim();
    if name.is_empty() {
        return Ok(jd_platform::suggested_device_name());
    }
    if name.chars().count() > 64 {
        return Err("Keep the computer's name to 64 characters or fewer.".into());
    }
    if name
        .chars()
        .any(|c| c.is_control() || "/\\:*?\"<>|".contains(c))
    {
        return Err(
            "The computer's name goes into file names, so it cannot contain / \\ : * ? \" < > |."
                .into(),
        );
    }
    Ok(name.to_string())
}

/// What checking a folder found.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FolderCheck {
    pub path: PathBuf,
    /// How many things are in it already (counted up to a hundred). They will
    /// be uploaded, which is worth a person saying yes to first.
    pub existing: usize,
}

/// Is this a folder Joinery Drive can sync, or why not?
///
/// The refusals are the ways a sync folder goes badly wrong rather than merely
/// somewhere unusual: the whole home folder (everything in it would be
/// uploaded, and a mistake in it would be a mistake everywhere), the root of a
/// disk, and anywhere that holds or sits inside the client's own state — a state
/// store that syncs itself has its record of what was agreed overwritten by
/// another machine's.
pub fn check_folder(typed: &str, paths: &Paths, home: &Path) -> Result<FolderCheck, String> {
    let typed = typed.trim();
    if typed.is_empty() {
        return Err("Choose a folder.".into());
    }
    // People type `~/Joinery Drive`, and the browser has no way to fill in a
    // path from a picker, so the shorthand is honoured.
    let path = match typed.strip_prefix('~') {
        Some("") => home.to_path_buf(),
        Some(rest) if rest.starts_with('/') || rest.starts_with('\\') => {
            home.join(rest.trim_start_matches(['/', '\\']))
        }
        _ => PathBuf::from(typed),
    };
    if !path.is_absolute() {
        return Err("Give the folder's full path, starting from the top of the disk.".into());
    }
    let path = lexically_normal(&path);
    // Twice: as typed, and as it resolves on disk. A symlink to the home folder
    // is the home folder, and a link into the client's own state is the state;
    // a check that only read the spelling would wave both through.
    let own = [&paths.config, &paths.state, &paths.logs].map(|p| lexically_normal(p));
    refusals(&path, home, &own)?;
    refusals(
        &resolved(&path),
        &resolved(home),
        &own.map(|p| resolved(&p)),
    )?;
    let existing = match std::fs::metadata(&path) {
        Ok(meta) if !meta.is_dir() => {
            return Err("There is a file at that path, not a folder.".into());
        }
        Ok(_) => std::fs::read_dir(&path)
            .map_err(|e| format!("That folder cannot be read: {e}"))?
            .take(100)
            .count(),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => 0,
        Err(e) => return Err(format!("That folder cannot be used: {e}")),
    };
    Ok(FolderCheck { path, existing })
}

/// The ways a sync folder goes badly wrong, as opposed to merely unusual.
fn refusals(path: &Path, home: &Path, own: &[PathBuf]) -> Result<(), String> {
    if path.parent().is_none() {
        return Err("Choose a folder of its own, not the top of the disk.".into());
    }
    if home.starts_with(path) {
        return Err("Choose a folder of its own, not your whole home folder.".into());
    }
    for own in own {
        if own.starts_with(path) || path.starts_with(own) {
            return Err(format!(
                "That folder holds Joinery Drive's own records ({}), which must never be synced. Choose another.",
                own.display()
            ));
        }
    }
    Ok(())
}

/// Where a path really is: its nearest existing ancestor resolved through any
/// symlinks, with the part that does not exist yet put back on the end. The
/// part that does not exist cannot be a link, so this is the whole answer for a
/// folder that is about to be made as much as for one that is already there.
///
/// On Windows the resolved path comes back in its `\\?\` spelling, which is
/// taken off again so it compares with the ordinary spellings it is checked
/// against.
fn resolved(path: &Path) -> PathBuf {
    let mut existing = path.to_path_buf();
    let mut rest = Vec::new();
    loop {
        if let Ok(real) = std::fs::canonicalize(&existing) {
            let mut out = jd_vfs::strip_verbatim(&real);
            for part in rest.iter().rev() {
                out.push(part);
            }
            return out;
        }
        match (existing.parent(), existing.file_name()) {
            (Some(parent), Some(name)) => {
                rest.push(name.to_os_string());
                existing = parent.to_path_buf();
            }
            _ => return path.to_path_buf(),
        }
    }
}

/// `a/./b/../c` → `a/c`, without touching the disk: the folder may not exist
/// yet. Links are followed separately, by [`resolved`].
fn lexically_normal(path: &Path) -> PathBuf {
    use std::path::Component;
    let mut out = PathBuf::new();
    for part in path.components() {
        match part {
            Component::CurDir => {}
            Component::ParentDir => {
                out.pop();
            }
            other => out.push(other.as_os_str()),
        }
    }
    out
}

/// What the folder step needs from an approval, written down so a restart
/// does not lose it. No secret: those are in the credential store already.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct PendingLink {
    pub base_url: String,
    pub device_name: String,
    pub device_id: Option<i64>,
    /// The account it was approved for (`Config::account_id`).
    #[serde(default)]
    pub account_id: Option<i64>,
    pub public_key: String,
    pub vault_enabled: bool,
}

pub fn pending_path(paths: &Paths) -> PathBuf {
    paths.state.join("pending_link.json")
}

fn save_pending(paths: &Paths, pending: &PendingLink) -> std::io::Result<()> {
    let tmp = pending_path(paths).with_extension("json.tmp");
    std::fs::write(
        &tmp,
        serde_json::to_string_pretty(pending).expect("serializes"),
    )?;
    std::fs::rename(&tmp, pending_path(paths))
}

/// An approved link the setup is holding until a folder is chosen.
///
/// The secret is held in memory as well as stored, so the approved device can
/// always be taken back off the account by the one credential that names it —
/// even after something else has written over the credential store.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Approval {
    link: PendingLink,
    secret_key: String,
}

/// An approval from before a restart, if its credential is still here to go
/// with it. One without its credential is no use to anybody and is dropped.
/// A `login` run while the daemon was down has written its own credential
/// over the page's: the pair restored here then does not match, the revoke at
/// the next start is refused, and the page's device stays on the account as a
/// row nothing can sign in as, for the user to remove on /profile/security.
fn restore_pending(paths: &Paths, secrets: &mut Secrets) -> Option<Approval> {
    let raw = std::fs::read_to_string(pending_path(paths)).ok()?;
    let pending: Option<PendingLink> = serde_json::from_str(&raw).ok();
    match (pending, secrets.get().get(Secret::ApiSecret)) {
        (Some(link), Ok(secret_key)) => Some(Approval { link, secret_key }),
        _ => {
            let _ = std::fs::remove_file(pending_path(paths));
            None
        }
    }
}

/// The credential store, opened the first time something needs it: when the
/// user starts linking, which is when whether this session can keep a vault
/// key decides what the device offers (`link::begin`).
///
/// Not at startup: opening it on macOS probes the login keychain, and a fresh
/// install that has not been asked to link anything yet has no business
/// touching the user's keychain — or showing them a keychain prompt before they
/// have done anything at all.
pub struct Secrets {
    paths: Paths,
    store: Option<SecretStore>,
}

impl Secrets {
    pub fn new(paths: &Paths) -> Secrets {
        Secrets {
            paths: paths.clone(),
            store: None,
        }
    }

    #[cfg(test)]
    fn with(store: SecretStore) -> Secrets {
        Secrets {
            paths: Paths {
                config: PathBuf::new(),
                state: PathBuf::new(),
                logs: PathBuf::new(),
            },
            store: Some(store),
        }
    }

    pub fn get(&mut self) -> &SecretStore {
        let paths = &self.paths;
        self.store.get_or_insert_with(|| link::secrets(paths))
    }
}

/// A ceremony in flight.
struct Ceremony {
    client: Client,
    pending: link::Pending,
    base_url: String,
    device_name: String,
    next_poll: Instant,
    began: Instant,
}

/// Run the first-run setup until this computer is linked.
///
/// Returns the config to sync with, or `None` when the daemon was told to stop.
/// Also returns as soon as a config appears on disk by any other route — a
/// person who ran `joinery-drive login` in a terminal while this was waiting
/// should not have to restart anything.
pub fn run(paths: &Paths, shared: &Shared, notice: Option<String>) -> Option<Config> {
    let (tx, rx) = channel();
    let mut secrets = Secrets::new(paths);
    let mut view = View::fresh(notice);
    let mut approved = restore_pending(paths, &mut secrets);
    if let Some(Approval { link: p, .. }) = &approved {
        view.stage = Stage::Folder;
        view.base_url = p.base_url.clone();
        view.device_name = p.device_name.clone();
        view.vault = p.vault_enabled;
    }
    shared.enter_setup(Desk {
        view: view.clone(),
        commands: tx,
        paths: paths.clone(),
    });

    let mut ceremony: Option<Ceremony> = None;
    let outcome = loop {
        if let Ok(config) = Config::load(&paths.config_file()) {
            // `joinery-drive login`, run in a terminal, linked this computer
            // while the page's own link was approved and waiting for a folder.
            // Login has written its credential over the page's, so the
            // device the page approved would be left on the account with no
            // key for it anywhere — a row on /profile/security that nothing
            // will ever use. It is revoked here with the page's own secret,
            // held in memory for this.
            supersede(approved.take(), &config);
            break Some(config);
        }
        match step(
            paths,
            &mut secrets,
            &rx,
            &mut view,
            &mut ceremony,
            &mut approved,
            &|v| shared.publish_setup(v),
        ) {
            Step::Continue => {}
            Step::Linked(config) => break Some(*config),
            Step::Stop => break None,
        }
        shared.publish_setup(&view);
        std::thread::sleep(TICK);
    };
    let _ = std::fs::remove_file(pending_path(paths));
    shared.leave_setup();
    outcome
}

enum Step {
    Continue,
    Linked(Box<Config>),
    Stop,
}

/// One turn of the setup loop: the queue, then the ceremony.
///
/// `publish` shows the page a stage before a step that waits on the network,
/// so "contacting your site" is on screen while it is true rather than after.
#[allow(clippy::too_many_arguments)]
fn step(
    paths: &Paths,
    secrets: &mut Secrets,
    rx: &Receiver<SetupCommand>,
    view: &mut View,
    ceremony: &mut Option<Ceremony>,
    approved: &mut Option<Approval>,
    publish: &dyn Fn(&View),
) -> Step {
    while let Ok(command) = rx.try_recv() {
        match command {
            SetupCommand::Stop => return Step::Stop,
            SetupCommand::Begin {
                base_url,
                device_name,
            } => {
                if view.stage != Stage::Instance {
                    continue;
                }
                let offer_vault = secrets.get().can_keep(Secret::VaultKey);
                begin(view, ceremony, base_url, device_name, offer_vault, publish);
            }
            SetupCommand::Cancel => {
                *ceremony = None;
                if let Some(approval) = approved.take() {
                    // Approved and then abandoned: the server holds a live key
                    // for this computer that nothing will ever use. Take it
                    // back rather than leave it on the account.
                    revoke(&approval);
                    let _ = secrets.get().clear();
                    let _ = std::fs::remove_file(pending_path(paths));
                }
                *view = View::fresh(Some("Setup was cancelled. Nothing was linked.".into()));
            }
            SetupCommand::Finish {
                sync_root,
                autostart,
            } => {
                let Some(approval) = approved.as_ref() else {
                    continue;
                };
                match finish(paths, &approval.link, &sync_root, autostart) {
                    Ok(config) => return Step::Linked(Box::new(config)),
                    Err(e) => view.error = Some(e),
                }
            }
        }
    }

    let Some(c) = ceremony.as_mut() else {
        return Step::Continue;
    };
    if Instant::now() < c.next_poll {
        return Step::Continue;
    }
    c.next_poll = Instant::now() + link::POLL_INTERVAL;
    match link::poll(&c.client, &c.pending) {
        Ok(link::Outcome::Pending) => {
            view.error = None;
            if c.began.elapsed() > GIVE_UP_AFTER {
                *ceremony = None;
                *view = View {
                    error: Some(
                        "The server stopped answering about this link. Start again.".into(),
                    ),
                    ..View::fresh(None)
                };
            }
        }
        Ok(link::Outcome::Approved(a)) => {
            let c = ceremony.take().expect("in flight");
            match link::store_approved(secrets.get(), &c.pending, &a) {
                Ok(handoff) => {
                    let pending = PendingLink {
                        base_url: c.base_url.clone(),
                        device_name: c.device_name.clone(),
                        device_id: a.device_id,
                        account_id: link::account_of(&c.base_url, &a.credentials),
                        public_key: a.credentials.public_key.clone(),
                        vault_enabled: handoff.enabled(),
                    };
                    // Not fatal if it fails: the folder step still has
                    // everything in memory. Only a restart before it would lose
                    // the approval, and the credential with it.
                    let _ = save_pending(paths, &pending);
                    view.stage = Stage::Folder;
                    view.error = None;
                    view.vault = handoff.enabled();
                    view.vault_note = handoff.sentence();
                    *approved = Some(Approval {
                        link: pending,
                        secret_key: a.credentials.secret_key.clone(),
                    });
                }
                Err(e) => {
                    // The credential was handed over once and cannot be kept,
                    // so it is taken straight back off the server rather than
                    // left live with nothing here to name it.
                    let mut client = Client::with_credentials(&c.base_url, a.credentials.clone());
                    let _ = client.logout();
                    let _ = secrets.get().clear();
                    *view = View {
                        error: Some(format!(
                            "This computer was approved, but its key could not be stored: {e}. Nothing was linked."
                        )),
                        ..View::fresh(None)
                    };
                }
            }
        }
        Ok(link::Outcome::Denied) => {
            *ceremony = None;
            *view = View {
                error: Some("The link was declined in the browser. Nothing was linked.".into()),
                ..View::fresh(None)
            };
        }
        Ok(link::Outcome::Expired) => {
            *ceremony = None;
            *view = View {
                error: Some(
                    "The code ran out before it was approved. Start again for a new one.".into(),
                ),
                ..View::fresh(None)
            };
        }
        // A network blip in the middle of waiting is not the end of the
        // ceremony: the code is still good on the server. Said, and retried.
        Err(e) => {
            view.error = Some(format!(
                "Having trouble reaching the server ({e}). Still trying."
            ))
        }
    }
    Step::Continue
}

fn begin(
    view: &mut View,
    ceremony: &mut Option<Ceremony>,
    base_url: String,
    device_name: String,
    offer_vault: bool,
    publish: &dyn Fn(&View),
) {
    view.stage = Stage::Starting;
    view.error = None;
    view.notice = None;
    view.base_url = base_url.clone();
    view.device_name = device_name.clone();
    publish(view);

    let client = Client::new(&base_url);
    match link::begin(
        &client,
        &device_name,
        jd_platform::platform_name(),
        offer_vault,
    ) {
        Ok(pending) => {
            view.stage = Stage::Approve;
            view.link_code = pending.link_code.clone();
            view.verify_url = pending.verify_url.clone();
            view.expires_time = pending.expires_time.clone();
            view.browser_opened = jd_platform::browser::show_url(&pending.verify_url);
            *ceremony = Some(Ceremony {
                client,
                pending,
                base_url,
                device_name,
                next_poll: Instant::now() + link::POLL_INTERVAL,
                began: Instant::now(),
            });
        }
        Err(e) => {
            view.stage = Stage::Instance;
            view.error = Some(format!("Could not start linking with {base_url}: {e}"));
        }
    }
}

fn finish(
    paths: &Paths,
    pending: &PendingLink,
    sync_root: &Path,
    autostart: bool,
) -> Result<Config, String> {
    let home = jd_platform::home_dir();
    let checked = check_folder(&sync_root.display().to_string(), paths, &home)?;
    std::fs::create_dir_all(&checked.path)
        .map_err(|e| format!("Cannot create {}: {e}", checked.path.display()))?;

    let mut autostart_on = false;
    if autostart {
        if let Ok(exe) = std::env::current_exe() {
            // Not a reason to refuse the setup: syncing works without it, and
            // the settings page shows whether it is on.
            autostart_on = jd_platform::autostart::enable(&exe, &home).is_ok();
        }
    }

    let config = Config {
        base_url: pending.base_url.clone(),
        sync_root: checked.path,
        device_name: pending.device_name.clone(),
        device_id: pending.device_id,
        account_id: pending.account_id,
        public_key: pending.public_key.clone(),
        poll_seconds: DEFAULT_POLL_SECONDS,
        excluded: Vec::new(),
        vault_enabled: pending.vault_enabled,
        autostart: autostart_on,
    };
    config.save(&paths.config_file()).map_err(|e| match e {
        ConfigError::Io { path, source } => format!("Cannot write {}: {source}", path.display()),
        other => other.to_string(),
    })?;
    Ok(config)
}

/// Take an approved device back off the account, as far as it can be.
///
/// With its own credential, so it can only ever revoke itself.
fn revoke(approval: &Approval) {
    let mut client = Client::with_credentials(
        &approval.link.base_url,
        Credentials {
            public_key: approval.link.public_key.clone(),
            secret_key: approval.secret_key.clone(),
        },
    );
    let _ = client.logout();
}

/// A config appeared from somewhere else while this setup held an approval.
/// Unless it is that same approval, the approved device is now an orphan, and
/// goes.
fn supersede(approved: Option<Approval>, config: &Config) {
    if let Some(approval) = approved {
        if approval.link.public_key != config.public_key {
            revoke(&approval);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn paths(root: &Path) -> Paths {
        Paths {
            config: root.join("jd/config"),
            state: root.join("jd/state"),
            logs: root.join("jd/logs"),
        }
    }

    fn temp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-setup-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    #[test]
    fn an_address_typed_without_a_scheme_is_taken_as_https() {
        assert_eq!(
            instance_url("drive.example.org").unwrap(),
            "https://drive.example.org"
        );
        assert_eq!(
            instance_url(" https://drive.example.org/ ").unwrap(),
            "https://drive.example.org"
        );
        assert_eq!(
            instance_url("drive.example.org:8443").unwrap(),
            "https://drive.example.org:8443"
        );
    }

    #[test]
    fn plain_http_is_refused_except_to_this_machine() {
        // The credential travels in every request afterwards.
        let refused = instance_url("http://drive.example.org").unwrap_err();
        assert!(refused.contains("https://"), "{refused}");
        assert!(
            refused.contains("unencrypted"),
            "says why, in plain words: {refused}"
        );
        assert!(instance_url("http://[::1]:8080").is_ok());
        assert!(instance_url("http://localhost:8080").is_ok());
        assert!(instance_url("http://127.0.0.1:8080").is_ok());
        assert!(instance_url("http://localhost.attacker.example").is_err());
    }

    #[test]
    fn things_that_are_not_web_addresses_are_refused() {
        for typed in [
            "",
            "   ",
            "ftp://x",
            "file:///etc",
            "https://",
            "https:",
            "https://user@host",
            "a b.example",
            "https://host:",
            "https://host:abc",
            "javascript:alert(1)",
        ] {
            assert!(instance_url(typed).is_err(), "{typed:?} was accepted");
        }
    }

    #[test]
    fn a_device_name_keeps_to_what_a_file_name_can_hold() {
        // It is written into conflict-copy names.
        assert_eq!(device_name(" Kitchen iMac ").unwrap(), "Kitchen iMac");
        assert!(
            !device_name("").unwrap().is_empty(),
            "empty means the machine's own name"
        );
        for bad in ["a/b", "a\\b", "a:b", "a\nb", "a|b"] {
            assert!(device_name(bad).is_err(), "{bad:?}");
        }
        assert!(device_name(&"x".repeat(65)).is_err());
    }

    #[test]
    fn a_new_folder_under_home_is_fine() {
        let dir = temp("fine");
        let home = dir.join("home");
        std::fs::create_dir_all(&home).unwrap();
        let check = check_folder(
            &home.join("Joinery Drive").display().to_string(),
            &paths(&dir),
            &home,
        )
        .unwrap();
        assert_eq!(check.path, home.join("Joinery Drive"));
        assert_eq!(check.existing, 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_tilde_shorthand_means_home() {
        let dir = temp("tilde");
        let home = dir.join("home");
        std::fs::create_dir_all(&home).unwrap();
        let check = check_folder("~/Joinery Drive", &paths(&dir), &home).unwrap();
        assert_eq!(check.path, home.join("Joinery Drive"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_folder_with_things_in_it_says_how_many() {
        // They will be uploaded, which a person should say yes to first.
        let dir = temp("full");
        let home = dir.join("home");
        let root = home.join("Documents");
        std::fs::create_dir_all(&root).unwrap();
        std::fs::write(root.join("a.txt"), b"a").unwrap();
        std::fs::write(root.join("b.txt"), b"b").unwrap();
        let check = check_folder(&root.display().to_string(), &paths(&dir), &home).unwrap();
        assert_eq!(check.existing, 2);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_whole_home_folder_or_a_disk_root_is_refused() {
        let dir = temp("home");
        let home = dir.join("home");
        std::fs::create_dir_all(&home).unwrap();
        assert!(check_folder(&home.display().to_string(), &paths(&dir), &home).is_err());
        assert!(check_folder("~", &paths(&dir), &home).is_err());
        assert!(
            check_folder(&dir.display().to_string(), &paths(&dir), &home).is_err(),
            "an ancestor of home"
        );
        assert!(check_folder(
            &home.join("x/..").display().to_string(),
            &paths(&dir),
            &home
        )
        .is_err());
        #[cfg(unix)]
        assert!(check_folder("/", &paths(&dir), &home).is_err());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_folder_holding_or_inside_the_clients_own_records_is_refused() {
        // A state store that syncs itself has its record of what was agreed
        // overwritten by another machine's.
        let dir = temp("own");
        let home = dir.join("home");
        let p = Paths {
            config: home.join(".config/jd"),
            state: home.join(".local/state/jd"),
            logs: home.join(".local/state/jd/logs"),
        };
        assert!(check_folder(&home.join(".local").display().to_string(), &p, &home).is_err());
        assert!(check_folder(&p.state.join("root").display().to_string(), &p, &home).is_err());
        assert!(check_folder(&home.join("Joinery Drive").display().to_string(), &p, &home).is_ok());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    #[cfg(unix)]
    fn a_link_to_somewhere_refused_is_refused_too() {
        // The check used to read only the spelling, so a link to the home
        // folder, the top of the disk, or into the client's own state passed.
        use std::os::unix::fs::symlink;
        let dir = temp("links");
        let home = dir.join("home");
        let p = Paths {
            config: home.join(".config/jd"),
            state: home.join(".local/state/jd"),
            logs: home.join(".local/state/jd/logs"),
        };
        std::fs::create_dir_all(&p.state).unwrap();
        let elsewhere = dir.join("elsewhere");
        std::fs::create_dir_all(&elsewhere).unwrap();
        symlink(&home, dir.join("to-home")).unwrap();
        symlink("/", dir.join("to-root")).unwrap();
        symlink(&p.state, dir.join("to-state")).unwrap();
        symlink(&elsewhere, dir.join("to-elsewhere")).unwrap();

        let refused =
            check_folder(&dir.join("to-home").display().to_string(), &p, &home).unwrap_err();
        assert!(refused.contains("home folder"), "{refused}");
        let refused =
            check_folder(&dir.join("to-root").display().to_string(), &p, &home).unwrap_err();
        assert!(refused.contains("top of the disk"), "{refused}");
        // Not there yet, but it would be made inside the state directory.
        let refused =
            check_folder(&dir.join("to-state/root").display().to_string(), &p, &home).unwrap_err();
        assert!(refused.contains("own records"), "{refused}");
        // A link to an ordinary folder is an ordinary folder.
        assert!(check_folder(&dir.join("to-elsewhere").display().to_string(), &p, &home).is_ok());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_relative_path_or_a_file_is_refused() {
        let dir = temp("rel");
        let home = dir.join("home");
        std::fs::create_dir_all(&home).unwrap();
        assert!(check_folder("Joinery Drive", &paths(&dir), &home).is_err());
        assert!(check_folder("", &paths(&dir), &home).is_err());
        let file = home.join("file.txt");
        std::fs::write(&file, b"x").unwrap();
        assert!(check_folder(&file.display().to_string(), &paths(&dir), &home).is_err());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_approval_survives_a_restart_only_with_its_credential() {
        let dir = temp("pending");
        let p = paths(&dir);
        p.create().unwrap();
        let pending = PendingLink {
            base_url: "https://drive.example.org".into(),
            device_name: "Laptop".into(),
            device_id: Some(7),
            account_id: Some(41),
            public_key: "pk".into(),
            vault_enabled: false,
        };
        save_pending(&p, &pending).unwrap();
        let raw = std::fs::read_to_string(pending_path(&p)).unwrap();
        assert!(
            !raw.contains("secret"),
            "no secret is written beside the approval"
        );

        // A file-custody store in the test's own directory, so no keychain is
        // touched.
        let mut secrets = Secrets::with(SecretStore::file_only("com.joinery.drive.test", &p.state));
        assert!(
            restore_pending(&p, &mut secrets).is_none(),
            "no credential, nothing to resume"
        );
        assert!(!pending_path(&p).exists(), "and the orphan is dropped");

        save_pending(&p, &pending).unwrap();
        secrets
            .get()
            .set(Secret::ApiSecret, "the-secret-half")
            .unwrap();
        assert_eq!(
            restore_pending(&p, &mut secrets),
            Some(Approval {
                link: pending,
                secret_key: "the-secret-half".into()
            })
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    /// A one-request HTTP server on loopback that hands back the head of
    /// whatever it was sent.
    fn one_request_server() -> (
        String,
        std::thread::JoinHandle<Option<String>>,
        std::net::TcpListener,
    ) {
        use std::io::{Read, Write};
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let base = format!("http://{}", listener.local_addr().unwrap());
        let probe = listener.try_clone().unwrap();
        let handle = std::thread::spawn(move || {
            let (mut stream, _) = listener.accept().ok()?;
            stream.set_read_timeout(Some(Duration::from_secs(5))).ok()?;
            let mut raw = Vec::new();
            let mut buf = [0u8; 4096];
            while !raw.windows(4).any(|w| w == b"\r\n\r\n") {
                let n = stream.read(&mut buf).ok()?;
                if n == 0 {
                    break;
                }
                raw.extend_from_slice(&buf[..n]);
            }
            let body = br#"{"data":{}}"#;
            let _ = write!(
                stream,
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                body.len()
            );
            let _ = stream.write_all(body);
            Some(String::from_utf8_lossy(&raw).to_lowercase())
        });
        (base, handle, probe)
    }

    fn approval(base_url: &str) -> Approval {
        Approval {
            link: PendingLink {
                base_url: base_url.into(),
                device_name: "Laptop".into(),
                device_id: Some(7),
                account_id: None,
                public_key: "page-public-key".into(),
                vault_enabled: false,
            },
            secret_key: "page-secret-key".into(),
        }
    }

    fn config_with_key(public_key: &str) -> Config {
        Config {
            base_url: "https://drive.example.org".into(),
            sync_root: "/r".into(),
            device_name: "Laptop".into(),
            device_id: Some(8),
            account_id: None,
            public_key: public_key.into(),
            poll_seconds: DEFAULT_POLL_SECONDS,
            excluded: Vec::new(),
            vault_enabled: false,
            autostart: false,
        }
    }

    #[test]
    fn a_terminal_login_over_an_approved_page_link_revokes_the_page_device() {
        // Login wrote its credential over the page's. Left alone, the page's
        // device would sit on the account with no key for it anywhere.
        let (base, server, _probe) = one_request_server();
        supersede(Some(approval(&base)), &config_with_key("login-public-key"));
        let head = server
            .join()
            .unwrap()
            .expect("a request reached the server");
        assert!(head.starts_with("post /api/v1/auth/logout "), "{head}");
        // Revoked with its own credential, so it can only ever revoke itself.
        assert!(head.contains("public-key: page-public-key"), "{head}");
        assert!(head.contains("secret-key: page-secret-key"), "{head}");
    }

    #[test]
    fn the_config_the_page_itself_wrote_revokes_nothing() {
        let (base, _server, probe) = one_request_server();
        supersede(Some(approval(&base)), &config_with_key("page-public-key"));
        supersede(None, &config_with_key("login-public-key"));
        probe.set_nonblocking(true).unwrap();
        // Nothing connected. (The serving thread is still waiting for a
        // connection; it goes with the process.)
        assert!(probe.accept().is_err(), "no request was made");
    }

    #[test]
    fn the_unlinked_status_says_so_in_the_fields_every_client_reads() {
        let mut view = View::fresh(None);
        let status = status_json(&view);
        assert_eq!(status["linked"], false);
        assert_eq!(status["setup"]["stage"], "instance");
        assert_ne!(
            status["indicator"], "green",
            "an unlinked client is not up to date"
        );
        assert!(status["summary"].as_str().unwrap().contains("Not set up"));

        view.stage = Stage::Approve;
        view.link_code = "ABCD-EFGH".into();
        let status = status_json(&view);
        assert_eq!(status["setup"]["stage"], "approve");
        assert_eq!(status["setup"]["link_code"], "ABCD-EFGH");
    }
}
