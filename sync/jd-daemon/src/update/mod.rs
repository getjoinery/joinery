//! Keeping the client current.
//!
//! One signed feed for every installation (`specs/drive_sync_clients.md`, D19):
//! a small JSON document per channel at getjoinery.com, naming the newest
//! release and where each platform's copy is. The daemon reads it a few minutes
//! after starting and every six hours after that, each time at a randomised
//! moment so a fleet of laptops waking at nine does not arrive at once.
//!
//! **What it believes, in order:** the feed's signature, against keys compiled
//! into this build ([`manifest`]); then the feed; then the downloaded payload's
//! size and SHA-256, against the signed feed; then the new program itself,
//! which has to run and answer with the version the feed promised before
//! anything is swapped ([`preflight`]). It never installs a version lower than
//! or equal to its own.
//!
//! **What it does with a newer version** depends on how it was installed
//! (`jd_platform::install`). A Mac app bundle, the Windows program folder, and
//! the Linux tarball install replace themselves. A `.deb` or `.rpm` install
//! only says an update is available: the package manager owns those files. A
//! build directory is left alone and is not even checked unless asked.
//!
//! **Installing is a restart.** The update thread asks the sync loop to stop —
//! the pass in progress stands down at its next safe point, between two
//! operations or two chunks of a transfer — and `main` does the swap once the
//! store is closed. The new daemon is on trial until it is up
//! ([`pending`]); one that does not come up is replaced by the old one again.
//! The same restart follows when the program file is replaced underneath the
//! daemon by something else — a package manager, a new app dragged over the
//! old one — once the new file has answered `--version`.
//!
//! **Threads.** This runs on a third thread of its own, beside the sync thread
//! and the control thread. It holds no database handle; it reads the snapshot
//! (to know when a new copy is up) and posts one command (`Stop`, to install).
//! What it knows is published in [`Handle`], which the control thread reads for
//! the status answer.

pub mod manifest;
pub mod payload;
pub mod pending;

use std::io::Read;
use std::path::{Path, PathBuf};
use std::sync::mpsc::{channel, Receiver, RecvTimeoutError, Sender};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use jd_platform::install::{self, ExeWatch, Install, Os, Swap};
use ring::digest::{Context, SHA256};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use crate::daemon::{now_ms, Command, Shared};
pub use manifest::{Artifact, Decision, FeedError, Manifest, TrustedKeys, Version};

/// The channel a new installation follows.
pub const DEFAULT_CHANNEL: &str = "beta";

/// Where every channel's manifest lives: `<base>/<channel>.json`.
pub const DEFAULT_FEED_BASE: &str = "https://getjoinery.com/drive/updates";

/// How often to look, and how far either side of that to land.
pub const CHECK_INTERVAL: Duration = Duration::from_secs(6 * 3600);
pub const CHECK_JITTER: Duration = Duration::from_secs(3600);

/// The first look after starting: soon, but not at once — a machine that has
/// just logged in has better things to do with its first seconds — and spread
/// out, so a feed outage that ends does not meet every client at the same
/// instant.
pub const FIRST_CHECK: (Duration, Duration) = (Duration::from_secs(30), Duration::from_secs(300));

/// After a check that failed, try again sooner than the interval.
pub const RETRY_AFTER_FAILURE: (Duration, Duration) =
    (Duration::from_secs(1800), Duration::from_secs(3600));

/// How long the staged program has to answer `--version`.
pub const PREFLIGHT_TIMEOUT: Duration = Duration::from_secs(30);

/// How often the update thread looks at what it watches between checks.
const TICK: Duration = Duration::from_secs(2);

/// The version this build is: the workspace version, which is also what the
/// server's upgrade gate is told and what every installer is stamped with.
pub fn current_version() -> &'static str {
    jd_proto::CLIENT_VERSION
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/// How this computer follows the feed: `update.json` in the config directory.
///
/// Kept apart from `config.json` because it belongs to the installed program,
/// not to the link: unlinking forgets the account, not which channel this
/// computer follows. Absent is the defaults.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Settings {
    #[serde(default = "default_channel")]
    pub channel: String,
    /// The manifest's address, when it is not the default for the channel.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub feed_url: Option<String>,
    /// Install updates without being asked. Off still checks and reports.
    #[serde(default = "default_true")]
    pub automatic: bool,
}

fn default_channel() -> String {
    DEFAULT_CHANNEL.to_string()
}

fn default_true() -> bool {
    true
}

impl Default for Settings {
    fn default() -> Settings {
        Settings {
            channel: default_channel(),
            feed_url: None,
            automatic: true,
        }
    }
}

impl Settings {
    pub const FILE: &'static str = "update.json";

    /// Read the settings. A damaged file is reported and the defaults used:
    /// unlike the link config, nothing is lost by following the default
    /// channel for a while, and refusing to start over it would take sync
    /// down to protect an update preference.
    pub fn load(config_dir: &Path) -> (Settings, Option<String>) {
        let path = config_dir.join(Self::FILE);
        let raw = match std::fs::read_to_string(&path) {
            Ok(raw) => raw,
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => {
                return (Settings::default(), None)
            }
            Err(e) => {
                return (
                    Settings::default(),
                    Some(format!("{}: {e}", path.display())),
                )
            }
        };
        match serde_json::from_str::<Settings>(&raw) {
            Ok(s) if valid_channel(&s.channel) => (s, None),
            Ok(s) => (
                Settings::default(),
                Some(format!(
                    "{}: {:?} is not a channel name",
                    path.display(),
                    s.channel
                )),
            ),
            Err(e) => (
                Settings::default(),
                Some(format!("{} is not readable: {e}", path.display())),
            ),
        }
    }

    pub fn feed_url(&self) -> String {
        self.feed_url
            .clone()
            .unwrap_or_else(|| format!("{DEFAULT_FEED_BASE}/{}.json", self.channel))
    }
}

/// A channel name goes into a URL, so it is kept to letters, digits and dashes.
fn valid_channel(name: &str) -> bool {
    !name.is_empty()
        && name.len() <= 32
        && name
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
}

// ---------------------------------------------------------------------------
// What is published
// ---------------------------------------------------------------------------

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum State {
    /// This build cannot check: no key, or a version it cannot read.
    Disabled,
    /// Nothing to do: up to date, or not checked yet.
    Idle,
    Checking,
    Downloading,
    /// Downloaded, checked, and waiting for the sync loop to stop.
    Installing,
    /// A newer version exists that this copy will not install itself.
    Available,
    /// The last check failed.
    Failed,
}

impl State {
    pub fn as_str(&self) -> &'static str {
        match self {
            State::Disabled => "disabled",
            State::Idle => "idle",
            State::Checking => "checking",
            State::Downloading => "downloading",
            State::Installing => "installing",
            State::Available => "available",
            State::Failed => "failed",
        }
    }
}

/// Everything the status answer says about updates.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Status {
    pub state: State,
    pub current: String,
    pub channel: String,
    pub automatic: bool,
    /// How this copy was installed, in words.
    pub install: String,
    pub can_install: bool,
    pub available: Option<String>,
    /// This build is older than the feed still supports.
    pub required: bool,
    pub release_notes_url: Option<String>,
    /// Why an available update is not being installed here.
    pub detail: Option<String>,
    pub last_check_ms: Option<u64>,
    pub last_error: Option<String>,
    /// The version this process replaced, once it is up.
    pub updated_from: Option<String>,
}

impl Status {
    /// One sentence for the tray and `status`.
    pub fn summary(&self) -> String {
        match self.state {
            State::Disabled => self
                .detail
                .clone()
                .unwrap_or_else(|| "Updates are not available for this build.".into()),
            State::Checking => "Checking for updates…".into(),
            State::Downloading => format!(
                "Downloading version {}…",
                self.available.as_deref().unwrap_or("?")
            ),
            State::Installing => format!(
                "Installing version {}; restarting…",
                self.available.as_deref().unwrap_or("?")
            ),
            State::Available => {
                let lead = if self.required {
                    format!(
                        "A required update is waiting: version {}, because this one is no \
                         longer supported.",
                        self.available.as_deref().unwrap_or("?")
                    )
                } else {
                    format!(
                        "Version {} is available.",
                        self.available.as_deref().unwrap_or("?")
                    )
                };
                match &self.detail {
                    Some(d) => format!("{lead} {d}"),
                    None => lead,
                }
            }
            State::Failed => format!(
                "The last update check failed: {}",
                self.last_error.as_deref().unwrap_or("unknown error")
            ),
            State::Idle => {
                let lead = match (&self.updated_from, self.last_check_ms) {
                    (Some(from), _) => format!("Updated to {} from {from}.", self.current),
                    (None, Some(_)) => format!("Up to date ({}).", self.current),
                    (None, None) => format!("Version {}.", self.current),
                };
                // An update that was undone is said here, where a person
                // looks, and not only in a field nobody reads.
                match &self.last_error {
                    Some(e) => format!("{lead} {e}"),
                    None => lead,
                }
            }
        }
    }

    pub fn to_json(&self) -> Value {
        json!({
            "state": self.state.as_str(),
            "summary": self.summary(),
            "current": self.current,
            "channel": self.channel,
            "automatic": self.automatic,
            "install": self.install,
            "can_install": self.can_install,
            "available": self.available,
            "required": self.required,
            "release_notes_url": self.release_notes_url,
            "detail": self.detail,
            "last_check_ms": self.last_check_ms,
            "last_error": self.last_error,
            "updated_from": self.updated_from,
        })
    }
}

/// What the old process does once the sync loop has stopped.
#[derive(Debug)]
pub enum Restart {
    /// Swap in a staged update, then start the new copy.
    Install(Staged),
    /// The program file was replaced underneath this process (a package
    /// manager, or somebody by hand): start whatever is there now.
    Reexec { daemon: PathBuf },
}

/// The update thread's side of the shared surface.
pub struct Handle {
    status: Mutex<Status>,
    wake: Mutex<Option<Sender<()>>>,
    restart: Mutex<Option<Restart>>,
    /// Syncing was paused when this copy was installed (`keep_paused`).
    paused_before: std::sync::atomic::AtomicBool,
}

impl Handle {
    fn new(status: Status) -> Handle {
        Handle {
            status: Mutex::new(status),
            wake: Mutex::new(None),
            restart: Mutex::new(None),
            paused_before: std::sync::atomic::AtomicBool::new(false),
        }
    }

    pub fn status(&self) -> Status {
        self.status
            .lock()
            .map(|s| s.clone())
            .unwrap_or_else(|e| e.into_inner().clone())
    }

    /// The `update` field of the status answer.
    pub fn json(&self) -> Value {
        self.status().to_json()
    }

    /// Ask for a check now. An error says why none will happen.
    pub fn check_now(&self) -> Result<(), String> {
        if self.status().state == State::Disabled {
            return Err(self.status().summary());
        }
        let sent = self
            .wake
            .lock()
            .ok()
            .and_then(|w| w.as_ref().map(|tx| tx.send(()).is_ok()))
            .unwrap_or(false);
        if sent {
            Ok(())
        } else {
            Err("the update check is not running".into())
        }
    }

    /// What `main` should do after the sync loop returns, if the loop was
    /// stopped for an update.
    pub fn take_restart(&self) -> Option<Restart> {
        self.restart.lock().ok().and_then(|mut r| r.take())
    }

    fn set(&self, change: impl FnOnce(&mut Status)) {
        if let Ok(mut s) = self.status.lock() {
            change(&mut s);
        }
    }
}

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

#[derive(Debug, thiserror::Error)]
pub enum UpdateError {
    #[error(transparent)]
    Feed(#[from] FeedError),
    #[error("cannot reach the update feed: {0}")]
    Network(String),
    #[error("the download does not match the signed feed: {0}")]
    Mismatch(String),
    #[error(transparent)]
    Payload(#[from] payload::PayloadError),
    #[error("the new version failed its check before installing: {0}")]
    Preflight(String),
    #[error("the update cannot be installed on this computer: {0}")]
    Unusable(String),
    #[error("{0}")]
    Local(String),
}

fn local(context: &str, e: impl std::fmt::Display) -> UpdateError {
    UpdateError::Local(format!("{context}: {e}"))
}

// ---------------------------------------------------------------------------
// One check
// ---------------------------------------------------------------------------

/// The HTTP agent for the feed and the downloads.
///
/// The user agent names the version and platform, which is the whole of what
/// the feed's server learns about a client — and enough to know how many
/// installations each release has reached.
pub fn agent(os: Os, arch: &str) -> ureq::Agent {
    ureq::AgentBuilder::new()
        .timeout_connect(Duration::from_secs(15))
        .timeout_read(Duration::from_secs(60))
        .timeout_write(Duration::from_secs(60))
        .user_agent(&format!(
            "joinery-drive/{} ({}; {arch})",
            current_version(),
            os.as_str()
        ))
        .build()
}

fn fetch(agent: &ureq::Agent, url: &str, limit: usize) -> Result<Vec<u8>, UpdateError> {
    let response = agent.get(url).call().map_err(|e| match e {
        ureq::Error::Status(code, _) => UpdateError::Network(format!("{url} answered {code}")),
        other => UpdateError::Network(other.to_string()),
    })?;
    let mut body = Vec::new();
    response
        .into_reader()
        .take(limit as u64 + 1)
        .read_to_end(&mut body)
        .map_err(|e| UpdateError::Network(format!("{url}: {e}")))?;
    if body.len() > limit {
        return Err(FeedError::Malformed(format!("{url} is larger than it can be")).into());
    }
    Ok(body)
}

/// Fetch the manifest and its signature, and open it.
pub fn fetch_manifest(
    agent: &ureq::Agent,
    url: &str,
    keys: &TrustedKeys,
    channel: &str,
) -> Result<Manifest, UpdateError> {
    if keys.is_empty() {
        return Err(FeedError::NoTrustedKeys.into());
    }
    if !manifest::acceptable_url(url) {
        return Err(UpdateError::Network(format!(
            "{url} is not an https address, so it is not checked"
        )));
    }
    let body = fetch(agent, url, manifest::MAX_MANIFEST_BYTES)?;
    let signature = fetch(agent, &format!("{url}.sig"), manifest::MAX_SIGNATURE_BYTES)?;
    // Held to the configured channel, never to anything the URL or the
    // document says about itself.
    Ok(manifest::open(&body, &signature, keys, channel)?)
}

/// Download an artifact into `dir`, checking its size and hash on the way in.
///
/// Written to a `.part` file and renamed only once both match, so a payload
/// at its final name is always one the signed feed vouches for.
pub fn download(
    agent: &ureq::Agent,
    artifact: &Artifact,
    dir: &Path,
) -> Result<PathBuf, UpdateError> {
    std::fs::create_dir_all(dir).map_err(|e| local("cannot create the updates folder", e))?;
    let final_path = dir.join(format!(
        "{}-{}-{}.tar.gz",
        artifact.sha256.get(..16).unwrap_or("payload"),
        artifact.os,
        artifact.arch
    ));
    let part = final_path.with_extension("part");
    let _ = std::fs::remove_file(&part);

    let response = agent.get(&artifact.url).call().map_err(|e| match e {
        ureq::Error::Status(code, _) => {
            UpdateError::Network(format!("{} answered {code}", artifact.url))
        }
        other => UpdateError::Network(other.to_string()),
    })?;
    let mut reader = response.into_reader().take(artifact.size + 1);
    let mut file =
        std::fs::File::create(&part).map_err(|e| local("cannot write the download", e))?;
    let mut hash = Context::new(&SHA256);
    let mut total: u64 = 0;
    let mut buf = vec![0u8; 64 * 1024];
    let outcome = (|| -> Result<(), UpdateError> {
        loop {
            let n = reader
                .read(&mut buf)
                .map_err(|e| UpdateError::Network(format!("{}: {e}", artifact.url)))?;
            if n == 0 {
                break;
            }
            total += n as u64;
            if total > artifact.size {
                return Err(UpdateError::Mismatch(format!(
                    "{} is larger than the {} bytes the feed says",
                    artifact.url, artifact.size
                )));
            }
            hash.update(&buf[..n]);
            std::io::Write::write_all(&mut file, &buf[..n])
                .map_err(|e| local("cannot write the download", e))?;
        }
        if total != artifact.size {
            return Err(UpdateError::Mismatch(format!(
                "{} is {total} bytes, not the {} the feed says",
                artifact.url, artifact.size
            )));
        }
        let got = hex(hash.finish().as_ref());
        if got != artifact.sha256 {
            return Err(UpdateError::Mismatch(format!(
                "{} has SHA-256 {got}, not {}",
                artifact.url, artifact.sha256
            )));
        }
        file.sync_all()
            .map_err(|e| local("cannot write the download", e))?;
        Ok(())
    })();
    drop(file);
    if let Err(e) = outcome {
        let _ = std::fs::remove_file(&part);
        return Err(e);
    }
    std::fs::rename(&part, &final_path).map_err(|e| local("cannot keep the download", e))?;
    Ok(final_path)
}

pub fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

/// A payload unpacked beside what it replaces, checked, and ready to swap.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Staged {
    pub version: Version,
    pub swaps: Vec<Swap>,
    pub staging: PathBuf,
    /// The daemon's live path: what is started once the swap is done.
    pub daemon: PathBuf,
}

/// The staging folder's name prefix. Recognisable, so a leftover from an
/// update that was interrupted can be found and removed.
pub const STAGING_PREFIX: &str = ".jd-update-";

/// Unpack a downloaded payload next to the live copy and check it.
///
/// `daemon` is the running daemon's own path, which says where inside the
/// payload the new daemon is and what to start afterwards.
pub fn stage(
    archive: &Path,
    install: &Install,
    version: Version,
    daemon: &Path,
) -> Result<Staged, UpdateError> {
    let parent = install
        .staging_parent()
        .ok_or_else(|| UpdateError::Local(format!("this copy is {}", install.describe())))?;
    let staging = parent.join(format!("{STAGING_PREFIX}{version}"));
    let _ = std::fs::remove_dir_all(&staging);
    let staged = (|| {
        let file =
            std::fs::File::open(archive).map_err(|e| local("cannot open the download", e))?;
        let roots = payload::unpack(file, &staging)?;
        std::fs::create_dir_all(staging.join("previous"))
            .map_err(|e| local("cannot prepare the update", e))?;
        let (swaps, new_daemon) = plan_swaps(install, &staging, &roots, daemon)?;
        preflight(&new_daemon, Some(version))?;
        Ok(Staged {
            version,
            swaps,
            staging: staging.clone(),
            daemon: daemon.to_path_buf(),
        })
    })();
    if staged.is_err() {
        let _ = std::fs::remove_dir_all(&staging);
    }
    staged
}

/// Which live paths the payload's entries replace, and where the new daemon
/// is inside it.
fn plan_swaps(
    install: &Install,
    staging: &Path,
    roots: &[String],
    daemon: &Path,
) -> Result<(Vec<Swap>, PathBuf), UpdateError> {
    let wrong = |why: String| Err(UpdateError::Payload(payload::PayloadError::Format(why)));
    match install {
        Install::Bundle { bundle } => {
            let [root] = roots else {
                return wrong(format!("a Mac update holds one app, not {roots:?}"));
            };
            if !root.ends_with(".app") {
                return wrong(format!("a Mac update holds an app, not {root}"));
            }
            let inside = daemon.strip_prefix(bundle).map_err(|_| {
                UpdateError::Local(format!(
                    "{} is not inside {}",
                    daemon.display(),
                    bundle.display()
                ))
            })?;
            let staged = staging.join(root);
            let new_daemon = staged.join(inside);
            if !new_daemon.is_file() {
                return wrong(format!("the app has no {}", inside.display()));
            }
            Ok((
                vec![Swap {
                    live: bundle.clone(),
                    staged,
                    previous: staging.join("previous").join(root),
                    new_id: None,
                }],
                new_daemon,
            ))
        }
        Install::Folder { dir } => {
            let name = daemon
                .file_name()
                .ok_or_else(|| UpdateError::Local("the daemon has no file name".into()))?
                .to_string_lossy()
                .to_string();
            if !roots.contains(&name) {
                return wrong(format!("the update does not contain {name}"));
            }
            // Every program this install has comes from the same release. A
            // payload carrying only the daemon would leave the last release's
            // tray beside the new daemon, drawing a menu for requests that may
            // no longer mean what it thinks.
            for program in [name.clone(), Os::current().tray_file_name()] {
                if dir.join(&program).is_file() && !roots.contains(&program) {
                    return Err(UpdateError::Unusable(format!(
                        "it does not contain {program}, which this install has, and installing \
                         only part of a release would leave the old {program} beside the new one"
                    )));
                }
            }
            let mut swaps = Vec::new();
            for root in roots {
                let staged = staging.join(root);
                if !staged.is_file() {
                    return wrong(format!("{root} is a folder; this install holds only files"));
                }
                let live = dir.join(root);
                // Only what this install already has is replaced: a new file
                // would need an undo that deletes it, and no release carries
                // one yet.
                if !live.is_file() {
                    return wrong(format!("{root} is not part of this install"));
                }
                swaps.push(Swap {
                    live,
                    staged,
                    previous: staging.join("previous").join(root),
                    new_id: None,
                });
            }
            Ok((swaps, staging.join(&name)))
        }
        other => Err(UpdateError::Local(format!(
            "this copy is {}",
            other.describe()
        ))),
    }
}

/// Run a program once (`--version`) and require it to answer with its
/// version.
///
/// **The first line of `--version` is a contract**, held by `main.rs`: its
/// last word is the version (`joinery-drive 0.3.0`). Changing that wording
/// changes what this reads, and every update would then fail this check.
///
/// This is the check that catches a program that cannot run here at all — the
/// wrong architecture, a signature the system refuses, a library this machine
/// does not have — which no start marker can catch, because such a program
/// never reaches the line that would count the attempt. It runs the very
/// bytes about to be installed, on this machine, before anything is moved.
pub fn preflight(program: &Path, expected: Option<Version>) -> Result<Version, UpdateError> {
    let fail = |why: String| Err(UpdateError::Preflight(why));
    let mut child = match std::process::Command::new(program)
        .arg("--version")
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::null())
        .spawn()
    {
        Ok(c) => c,
        Err(e) => return fail(format!("{} would not run: {e}", program.display())),
    };
    let deadline = Instant::now() + PREFLIGHT_TIMEOUT;
    let status = loop {
        match child.try_wait() {
            Ok(Some(status)) => break status,
            Ok(None) if Instant::now() < deadline => std::thread::sleep(Duration::from_millis(50)),
            Ok(None) => {
                let _ = child.kill();
                let _ = child.wait();
                return fail(format!(
                    "{} did not answer within {} seconds",
                    program.display(),
                    PREFLIGHT_TIMEOUT.as_secs()
                ));
            }
            Err(e) => return fail(e.to_string()),
        }
    };
    let mut out = String::new();
    if let Some(mut stdout) = child.stdout.take() {
        let _ = stdout.read_to_string(&mut out);
    }
    if !status.success() {
        return fail(format!("{} exited with {status}", program.display()));
    }
    // `joinery-drive 0.3.0`: the version is the last word of the first line.
    let said = out.lines().next().unwrap_or("").trim();
    let got: Version = match said.split_whitespace().last().unwrap_or("").parse() {
        Ok(v) => v,
        Err(_) => return fail(format!("answered {said:?} instead of its version")),
    };
    if let Some(expected) = expected {
        if got != expected {
            return fail(format!("it is version {got}, but the feed says {expected}"));
        }
    }
    Ok(got)
}

/// What one check concluded should happen here.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Plan {
    /// Up to date.
    Nothing,
    /// A newer version, and the reason this copy is not installing it.
    Report(String),
    /// Install this payload.
    Install(Artifact),
}

/// The decision, as a pure function of the feed's verdict and this copy's
/// circumstances.
///
/// `skip` is a version that was installed here once and did not start; it is
/// not tried again automatically, only when somebody asks (`manual`) or a
/// newer one appears.
pub fn plan(
    decision: &Decision,
    install: &Install,
    automatic: bool,
    manual: bool,
    skip: Option<Version>,
    os: &str,
    arch: &str,
) -> Plan {
    let Decision::Newer {
        version, artifact, ..
    } = decision
    else {
        return Plan::Nothing;
    };
    if !install.can_replace() {
        return Plan::Report(match install {
            Install::PackageManager { name } => format!("Install it with {name}."),
            other => format!("This copy is {}.", other.describe()),
        });
    }
    let Some(artifact) = artifact else {
        return Plan::Report(format!("No update has been published for {os}/{arch} yet."));
    };
    if !manual && skip == Some(*version) {
        return Plan::Report(format!(
            "Version {version} did not start on this computer last time; it will be tried \
             again when asked, or when a newer version is published."
        ));
    }
    // Off means off, a required update included: it waits for somebody to
    // ask, and the status leads with "A required update is waiting". Whether
    // a required update should install anyway is the owner's to decide.
    if !automatic && !manual {
        return Plan::Report(
            "Automatic updates are turned off; it installs when you ask (Check for updates)."
                .into(),
        );
    }
    Plan::Install(artifact.clone())
}

// ---------------------------------------------------------------------------
// The update thread
// ---------------------------------------------------------------------------

/// What the update thread remembers across restarts: `state.json` in the
/// updates folder.
#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
struct Remembered {
    /// A version that was installed here and did not start.
    #[serde(default)]
    skip: Option<String>,
    /// Said by the old copy after a rollback, until the next good check.
    #[serde(default)]
    last_error: Option<String>,
}

impl Remembered {
    fn load(dir: &Path) -> Remembered {
        std::fs::read_to_string(dir.join("state.json"))
            .ok()
            .and_then(|raw| serde_json::from_str(&raw).ok())
            .unwrap_or_default()
    }

    fn save(&self, dir: &Path) {
        let _ = std::fs::create_dir_all(dir);
        let tmp = dir.join("state.json.tmp");
        if std::fs::write(&tmp, serde_json::to_string_pretty(self).unwrap_or_default()).is_ok() {
            let _ = std::fs::rename(&tmp, dir.join("state.json"));
        }
    }
}

/// Everything the update thread works from.
#[derive(Debug, Clone)]
pub struct Updater {
    pub settings: Settings,
    /// A problem reading the settings, reported in the status.
    pub settings_problem: Option<String>,
    pub keys: TrustedKeys,
    /// The built-in key list did not parse; nothing is checked, and this says why.
    pub keys_problem: Option<String>,
    pub current: Option<Version>,
    pub os: Os,
    pub arch: String,
    /// The updates folder in the state directory: downloads, the marker, and
    /// what is remembered.
    pub dir: PathBuf,
    /// The running daemon's live path.
    pub daemon: PathBuf,
    pub install: Install,
}

impl Updater {
    /// The updater for this process.
    pub fn for_this_process(paths: &jd_platform::Paths) -> Updater {
        let (settings, settings_problem) = Settings::load(&paths.config);
        let (keys, keys_problem) = match TrustedKeys::built_in() {
            Ok(keys) => (keys, None),
            Err(e) => (TrustedKeys::default(), Some(e)),
        };
        let daemon = std::env::current_exe()
            .ok()
            .map(|p| std::fs::canonicalize(&p).unwrap_or(p))
            .unwrap_or_else(|| PathBuf::from(jd_platform::BINARY_NAME));
        Updater {
            settings,
            settings_problem,
            keys,
            keys_problem,
            current: current_version().parse().ok(),
            os: Os::current(),
            arch: std::env::consts::ARCH.to_string(),
            dir: updates_dir(paths),
            install: install::detect(&daemon),
            daemon,
        }
    }

    pub fn marker(&self) -> PathBuf {
        self.dir.join("pending.json")
    }

    fn initial_status(&self) -> Status {
        let disabled = if self.current.is_none() {
            Some(format!(
                "This build's version ({}) is not one the update feed can compare.",
                current_version()
            ))
        } else if let Some(problem) = &self.keys_problem {
            Some(format!(
                "This build's list of update keys is unreadable ({problem}), so it does not \
                 check for updates."
            ))
        } else if self.keys.is_empty() {
            Some(
                "This build carries no update signing key, so it does not check for updates."
                    .into(),
            )
        } else {
            None
        };
        Status {
            state: if disabled.is_some() {
                State::Disabled
            } else {
                State::Idle
            },
            current: current_version().to_string(),
            channel: self.settings.channel.clone(),
            automatic: self.settings.automatic,
            install: self.install.describe(),
            can_install: self.install.can_replace(),
            available: None,
            required: false,
            release_notes_url: None,
            detail: disabled,
            last_check_ms: None,
            last_error: self.settings_problem.clone(),
            updated_from: None,
        }
    }

    /// One check, start to finish: fetch, verify, decide, and — when this copy
    /// installs it — download and stage.
    pub fn check(
        &self,
        agent: &ureq::Agent,
        manual: bool,
        skip: Option<Version>,
        on_download: &dyn Fn(&str),
    ) -> Result<Checked, UpdateError> {
        let current = self
            .current
            .ok_or_else(|| UpdateError::Local("this build's version cannot be compared".into()))?;
        let manifest = fetch_manifest(
            agent,
            &self.settings.feed_url(),
            &self.keys,
            &self.settings.channel,
        )?;
        let decision = manifest::decide(current, &manifest, self.os.as_str(), &self.arch);
        let notes = manifest.release_notes_url.clone();
        let (version, required) = match &decision {
            Decision::UpToDate { latest } => return Ok(Checked::UpToDate { latest: *latest }),
            Decision::Newer {
                version, required, ..
            } => (*version, *required),
        };
        match plan(
            &decision,
            &self.install,
            self.settings.automatic,
            manual,
            skip,
            self.os.as_str(),
            &self.arch,
        ) {
            Plan::Nothing => Ok(Checked::UpToDate { latest: version }),
            Plan::Report(why) => Ok(Checked::Available {
                version,
                required,
                notes,
                why,
            }),
            Plan::Install(artifact) => {
                on_download(&version.to_string());
                let archive = download(agent, &artifact, &self.dir)?;
                let staged = stage(&archive, &self.install, version, &self.daemon);
                let _ = std::fs::remove_file(&archive);
                Ok(Checked::Ready {
                    staged: staged?,
                    required,
                    notes,
                })
            }
        }
    }

    /// The handle the control thread reads. Made before the thread starts,
    /// because the shared surface holds it and the thread needs the shared
    /// surface.
    pub fn handle(&self) -> Arc<Handle> {
        Arc::new(Handle::new(self.initial_status()))
    }

    /// Start the update thread.
    ///
    /// `trial` is the start marker's verdict: this process is a new copy, and
    /// clears the marker once the sync loop has published its first status.
    pub fn start(self, handle: Arc<Handle>, shared: Arc<Shared>, trial: bool) {
        let (tx, rx) = channel();
        if let Ok(mut wake) = handle.wake.lock() {
            *wake = Some(tx);
        }
        std::thread::spawn(move || self.run(handle, rx, shared, trial));
    }

    fn run(self, handle: Arc<Handle>, wake: Receiver<()>, shared: Arc<Shared>, mut trial: bool) {
        let marker = self.marker();
        let current = current_version();
        let mut remembered = Remembered::load(&self.dir);

        // The old copy, back after an update that did not start: say so, and
        // do not offer that version again on its own.
        if let Some((version, why)) = pending::take_rollback(&marker, current) {
            remembered.skip = Some(version.clone());
            remembered.last_error = Some(format!(
                "The update to {version} was undone, so this computer runs {current} again: {why}."
            ));
            remembered.save(&self.dir);
        }
        if remembered.last_error.is_some() {
            handle.set(|s| s.last_error = remembered.last_error.clone());
        }
        if !trial && !marker.exists() {
            sweep(&self.install, &self.dir);
        }

        let agent = agent(self.os, &self.arch);
        let can_check = self.current.is_some() && !self.keys.is_empty();
        // A build tree is a developer's: it is told about updates when it asks.
        let mut next_check = (can_check && self.install != Install::BuildTree)
            .then(|| Instant::now() + jitter(FIRST_CHECK));
        let mut watch = (self.install != Install::BuildTree).then(|| ExeWatch::new(&self.daemon));

        loop {
            // Up: the sync loop has published its first status, or — on a
            // computer not linked yet — the first-run setup is answering.
            if trial && (shared.snapshot().is_some() || shared.setup_view().is_some()) {
                trial = false;
                if let Some(from) = pending::confirm(&marker, current) {
                    handle.set(|s| s.updated_from = Some(from));
                }
                sweep(&self.install, &self.dir);
            }

            // Replaced underneath us — by `apt upgrade`, or by dragging a new
            // app over the old one. Start the new copy, once it has proved it
            // runs; a file that does not is reported and this copy carries on.
            if let Some(w) = watch.as_mut() {
                if !trial && w.replaced() {
                    match preflight(w.path(), None) {
                        Ok(_) => {
                            request_restart(
                                &handle,
                                &shared,
                                Restart::Reexec {
                                    daemon: w.path().to_path_buf(),
                                },
                            );
                            return;
                        }
                        Err(e) => {
                            let why = format!(
                                "the program file was replaced by one that does not start: {e}"
                            );
                            handle.set(|s| s.last_error = Some(why));
                            watch = None;
                        }
                    }
                }
            }

            let wait = next_check
                .map(|t| t.saturating_duration_since(Instant::now()))
                .unwrap_or(TICK)
                .min(TICK);
            let manual = match wake.recv_timeout(wait) {
                Ok(()) => true,
                Err(RecvTimeoutError::Timeout) => false,
                Err(RecvTimeoutError::Disconnected) => return,
            };
            let due = next_check.is_some_and(|t| Instant::now() >= t);
            if !(manual || due) || !can_check {
                continue;
            }

            handle.set(|s| s.state = State::Checking);
            let skip = if manual {
                None
            } else {
                remembered.skip.as_deref().and_then(|v| v.parse().ok())
            };
            let downloading = |version: &str| {
                handle.set(|s| {
                    s.state = State::Downloading;
                    s.available = Some(version.to_string());
                })
            };
            let result = self.check(&agent, manual, skip, &downloading);
            let finished = now_ms();
            next_check = Some(Instant::now() + jitter_around(CHECK_INTERVAL, CHECK_JITTER));

            match result {
                Ok(Checked::UpToDate { .. }) => {
                    remembered = Remembered::default();
                    remembered.save(&self.dir);
                    handle.set(|s| {
                        s.state = State::Idle;
                        s.available = None;
                        s.required = false;
                        s.detail = None;
                        s.last_error = None;
                        s.last_check_ms = Some(finished);
                    });
                    sweep(&self.install, &self.dir);
                }
                Ok(Checked::Available {
                    version,
                    required,
                    notes,
                    why,
                }) => handle.set(|s| {
                    s.state = State::Available;
                    s.available = Some(version.to_string());
                    s.required = required;
                    s.release_notes_url = notes;
                    s.detail = Some(why);
                    s.last_error = remembered.last_error.clone();
                    s.last_check_ms = Some(finished);
                }),
                Ok(Checked::Ready {
                    staged,
                    required,
                    notes,
                }) => {
                    if manual {
                        remembered.skip = None;
                        remembered.save(&self.dir);
                    }
                    handle.set(|s| {
                        s.state = State::Installing;
                        s.available = Some(staged.version.to_string());
                        s.required = required;
                        s.release_notes_url = notes;
                        s.detail = None;
                        s.last_error = None;
                        s.last_check_ms = Some(finished);
                    });
                    request_restart(&handle, &shared, Restart::Install(staged));
                    return;
                }
                Err(e) => {
                    next_check = Some(Instant::now() + jitter(RETRY_AFTER_FAILURE));
                    handle.set(|s| {
                        s.state = State::Failed;
                        s.last_error = Some(e.to_string());
                        s.last_check_ms = Some(finished);
                    });
                }
            }
        }
    }
}

/// What a check found.
#[derive(Debug)]
pub enum Checked {
    UpToDate {
        latest: Version,
    },
    Available {
        version: Version,
        required: bool,
        notes: Option<String>,
        why: String,
    },
    Ready {
        staged: Staged,
        required: bool,
        notes: Option<String>,
    },
}

fn request_restart(handle: &Handle, shared: &Shared, restart: Restart) {
    if let Ok(mut slot) = handle.restart.lock() {
        *slot = Some(restart);
    }
    // The pass in progress hears it at once and stands down at its next safe
    // point, between two operations or two chunks of a transfer
    // (`Shared::send`); the loop then reads the stop from its queue and the
    // store closes. A computer not linked yet is running the first-run setup
    // instead, which stops the same way.
    if shared.setup_view().is_some() {
        shared.send_setup(crate::setup::SetupCommand::Stop);
    } else {
        shared.send(Command::Stop);
    }
}

/// Start the update thread for this process and put its handle on the shared
/// surface. `start` is the start marker's verdict, read first thing.
pub fn start(
    paths: &jd_platform::Paths,
    shared: &Arc<Shared>,
    start: &pending::Start,
) -> Arc<Handle> {
    let updater = Updater::for_this_process(paths);
    let handle = updater.handle();
    if let pending::Start::Trial { paused: true } = start {
        handle
            .paused_before
            .store(true, std::sync::atomic::Ordering::SeqCst);
    }
    let _ = shared.update.set(Arc::clone(&handle));
    let trial = matches!(start, pending::Start::Trial { .. });
    updater.start(Arc::clone(&handle), Arc::clone(shared), trial);
    handle
}

/// A copy installed while syncing was paused starts paused. Called once the
/// sync loop's command queue is attached and before its first pass, so the
/// pause is the first thing it reads.
pub fn keep_paused(shared: &Shared) {
    let paused = shared.update.get().is_some_and(|u| {
        u.paused_before
            .swap(false, std::sync::atomic::Ordering::SeqCst)
    });
    if paused {
        shared.send(Command::Pause);
    }
}

/// The updates folder for a set of paths.
pub fn updates_dir(paths: &jd_platform::Paths) -> PathBuf {
    paths.state.join("updates")
}

/// Remove what earlier updates left behind: staging folders beside the
/// install, and downloads. Only called when no marker stands, so nothing here
/// is anything an update in progress still needs. Best effort: on Windows a
/// copy that is still running (the old tray, for the few seconds before it
/// restarts itself) cannot be deleted yet, and the next sweep gets it.
fn sweep(install: &Install, dir: &Path) {
    if let Some(parent) = install.staging_parent() {
        if let Ok(entries) = std::fs::read_dir(&parent) {
            for entry in entries.flatten() {
                if entry
                    .file_name()
                    .to_string_lossy()
                    .starts_with(STAGING_PREFIX)
                {
                    let _ = std::fs::remove_dir_all(entry.path());
                }
            }
        }
    }
    if let Ok(entries) = std::fs::read_dir(dir) {
        for entry in entries.flatten() {
            let name = entry.file_name().to_string_lossy().to_string();
            if name.ends_with(".tar.gz") || name.ends_with(".part") {
                let _ = std::fs::remove_file(entry.path());
            }
        }
    }
}

/// A random moment in `[low, high)`.
fn jitter((low, high): (Duration, Duration)) -> Duration {
    let span = high.saturating_sub(low).as_millis() as u64;
    if span == 0 {
        return low;
    }
    low + Duration::from_millis(random_u64() % span)
}

/// `centre ± spread`.
fn jitter_around(centre: Duration, spread: Duration) -> Duration {
    jitter((centre.saturating_sub(spread), centre + spread))
}

fn random_u64() -> u64 {
    use ring::rand::{SecureRandom, SystemRandom};
    let mut bytes = [0u8; 8];
    // A failure here costs only the spread, never the check.
    let _ = SystemRandom::new().fill(&mut bytes);
    u64::from_le_bytes(bytes)
}

// ---------------------------------------------------------------------------
// After the sync loop stops
// ---------------------------------------------------------------------------

/// Swap in a staged update, recording how to undo it. `from` is the version
/// being replaced: this build's own, except in a test standing in for an older
/// one.
pub fn install_staged(
    staged: &Staged,
    marker: &Path,
    from: &str,
    paused: bool,
) -> Result<(), String> {
    let mut pending = pending::Pending {
        from: from.to_string(),
        to: staged.version.to_string(),
        phase: pending::Phase::Swapping,
        attempts: 0,
        paused,
        swaps: staged.swaps.clone(),
        staging: staged.staging.clone(),
        daemon: staged.daemon.clone(),
        reason: None,
    };
    pending.install(marker)
}

/// Do what the update thread asked for, then end this process.
///
/// Under the LaunchAgent or the systemd unit, ending with
/// [`install::RESTART_EXIT_CODE`] is the restart. Otherwise this process
/// starts the new daemon itself — exactly the command autostart runs — and,
/// for an update, stays to watch it come up, putting itself back if it does
/// not.
pub fn restart(restart: Restart, paths: &jd_platform::Paths, paused: bool) -> ! {
    let marker = updates_dir(paths).join("pending.json");
    let (daemon, watched) = match restart {
        Restart::Install(staged) => {
            if let Err(e) = install_staged(&staged, &marker, current_version(), paused) {
                // Everything was put back; this copy is still the one at the
                // live path. Start it again and say why on the next status.
                eprintln!("update: {e}");
                Remembered {
                    skip: Some(staged.version.to_string()),
                    last_error: Some(format!(
                        "The update to {} could not be installed: {e}.",
                        staged.version
                    )),
                }
                .save(&updates_dir(paths));
                let _ = std::fs::remove_file(&marker);
                (staged.daemon, false)
            } else {
                eprintln!("update: installed {}; restarting", staged.version);
                (staged.daemon, true)
            }
        }
        Restart::Reexec { daemon } => {
            eprintln!("update: the program was replaced; restarting into the new copy");
            (daemon, false)
        }
    };
    relaunch(&daemon, watched.then_some(marker.as_path()))
}

/// Start the daemon at `daemon` again and end this process. With a marker, the
/// new copy is watched (when nothing else will restart it) and undone if it
/// does not come up.
pub fn relaunch(daemon: &Path, marker: Option<&Path>) -> ! {
    if install::supervisor().is_some() {
        std::process::exit(install::RESTART_EXIT_CODE);
    }
    let undo = |marker: &Path, why: &str| {
        if let Some(mut p) = pending::Pending::load(marker) {
            p.roll_back(marker, why);
        }
        let _ = install::spawn_detached(daemon, &["daemon"]);
    };
    match install::spawn_detached(daemon, &["daemon"]) {
        Ok(mut child) => {
            if let Some(marker) = marker {
                if let pending::Watched::Failed(why) =
                    pending::watch(marker, &mut child, pending::WATCH_TIMEOUT)
                {
                    eprintln!("update: {why}; putting the previous version back");
                    undo(marker, &why);
                }
            }
            std::process::exit(0)
        }
        Err(e) => {
            eprintln!("update: cannot start {}: {e}", daemon.display());
            if let Some(marker) = marker {
                undo(
                    marker,
                    &format!("the new version could not be started: {e}"),
                );
            }
            std::process::exit(1)
        }
    }
}

/// The start marker's verdict for this process, read before anything else in
/// `daemon`. A `Restart` never returns.
pub fn on_start(paths: &jd_platform::Paths) -> pending::Start {
    let marker = updates_dir(paths).join("pending.json");
    let verdict = pending::on_start(&marker, current_version());
    if let pending::Start::Restart { daemon } = &verdict {
        eprintln!("update: this version did not start; putting the previous one back");
        relaunch(daemon, None);
    }
    verdict
}

#[cfg(test)]
mod tests {
    use super::*;

    fn newer(artifact: bool) -> Decision {
        Decision::Newer {
            version: Version::release(0, 3, 0),
            artifact: artifact.then(|| manifest::tests::sample("0.3.0").artifacts[1].clone()),
            required: false,
        }
    }

    fn folder() -> Install {
        Install::Folder {
            dir: PathBuf::from("/home/u/.local/bin"),
        }
    }

    #[test]
    fn what_each_kind_of_install_does_with_a_newer_version() {
        let p =
            |install: &Install| plan(&newer(true), install, true, false, None, "linux", "x86_64");
        assert!(matches!(p(&folder()), Plan::Install(_)));
        assert!(matches!(
            p(&Install::Bundle {
                bundle: "/Applications/Joinery Drive.app".into()
            }),
            Plan::Install(_)
        ));
        // A package manager's files are its own: report, and say who updates.
        match p(&Install::PackageManager {
            name: "the system package manager (apt, dnf)".into(),
        }) {
            Plan::Report(why) => assert!(why.contains("apt"), "{why}"),
            other => panic!("{other:?}"),
        }
        assert!(matches!(
            p(&Install::ReadOnly {
                why: "/opt is not writable".into()
            }),
            Plan::Report(_)
        ));
        assert!(matches!(p(&Install::BuildTree), Plan::Report(_)));
    }

    #[test]
    fn a_required_update_waits_for_a_person_when_automatic_updates_are_off() {
        // Held as it is until the owner decides otherwise: off means off.
        let required = Decision::Newer {
            version: Version::release(0, 3, 0),
            artifact: Some(manifest::tests::sample("0.3.0").artifacts[1].clone()),
            required: true,
        };
        let why = match plan(&required, &folder(), false, false, None, "linux", "x86_64") {
            Plan::Report(why) => why,
            other => panic!("{other:?}"),
        };
        assert!(matches!(
            plan(&required, &folder(), false, true, None, "linux", "x86_64"),
            Plan::Install(_)
        ));
        // And the status says so plainly, first.
        let s = Status {
            state: State::Available,
            current: "0.1.9".into(),
            channel: "beta".into(),
            automatic: false,
            install: String::new(),
            can_install: true,
            available: Some("0.3.0".into()),
            required: true,
            release_notes_url: None,
            detail: Some(why),
            last_check_ms: Some(1),
            last_error: None,
            updated_from: None,
        };
        let summary = s.summary();
        assert!(
            summary.starts_with("A required update is waiting: version 0.3.0"),
            "{summary}"
        );
        assert!(summary.contains("when you ask"), "{summary}");
    }

    fn program_dirs(tag: &str, live: &[&str], payload: &[&str]) -> (PathBuf, PathBuf, PathBuf) {
        let root =
            std::env::temp_dir().join(format!("jd-update-programs-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&root);
        let (dir, staging) = (root.join("install"), root.join("install/.jd-update-0.3.0"));
        std::fs::create_dir_all(&staging).unwrap();
        for name in live {
            std::fs::write(dir.join(name), b"old").unwrap();
        }
        for name in payload {
            std::fs::write(staging.join(name), b"new").unwrap();
        }
        (root, dir, staging)
    }

    #[test]
    fn a_folder_update_must_carry_every_program_the_install_has() {
        let os = Os::current();
        let (daemon, tray) = (os.daemon_file_name(), os.tray_file_name());

        // Daemon only, over an install with a tray: refused, naming the tray,
        // and nothing is swapped.
        let (root, dir, staging) = program_dirs("partial", &[&daemon, &tray], &[&daemon]);
        let install = Install::Folder { dir: dir.clone() };
        match plan_swaps(
            &install,
            &staging,
            std::slice::from_ref(&daemon),
            &dir.join(&daemon),
        ) {
            Err(UpdateError::Unusable(why)) => assert!(why.contains(&tray), "{why}"),
            other => panic!("{other:?}"),
        }
        let _ = std::fs::remove_dir_all(&root);

        // Both: both are swapped.
        let (root, dir, staging) = program_dirs("whole", &[&daemon, &tray], &[&daemon, &tray]);
        let install = Install::Folder { dir: dir.clone() };
        let (swaps, _) = plan_swaps(
            &install,
            &staging,
            &[daemon.clone(), tray.clone()],
            &dir.join(&daemon),
        )
        .unwrap();
        assert_eq!(swaps.len(), 2);
        let _ = std::fs::remove_dir_all(&root);

        // A headless install with no tray takes a daemon-only update.
        let (root, dir, staging) = program_dirs("headless", &[&daemon], &[&daemon]);
        let install = Install::Folder { dir: dir.clone() };
        let (swaps, _) = plan_swaps(
            &install,
            &staging,
            std::slice::from_ref(&daemon),
            &dir.join(&daemon),
        )
        .unwrap();
        assert_eq!(swaps.len(), 1);
        let _ = std::fs::remove_dir_all(&root);
    }

    #[test]
    fn up_to_date_is_nothing_for_every_kind_of_install() {
        let up = Decision::UpToDate {
            latest: Version::release(0, 3, 0),
        };
        for install in [folder(), Install::BuildTree] {
            assert_eq!(
                plan(&up, &install, true, true, None, "linux", "x86_64"),
                Plan::Nothing
            );
        }
    }

    #[test]
    fn a_version_that_did_not_start_here_is_not_tried_again_unless_asked() {
        let skip = Some(Version::release(0, 3, 0));
        assert!(matches!(
            plan(
                &newer(true),
                &folder(),
                true,
                false,
                skip,
                "linux",
                "x86_64"
            ),
            Plan::Report(_)
        ));
        // Somebody pressing "check for updates" is asking to try again.
        assert!(matches!(
            plan(&newer(true), &folder(), true, true, skip, "linux", "x86_64"),
            Plan::Install(_)
        ));
        // And a different version is a different question.
        assert!(matches!(
            plan(
                &newer(true),
                &folder(),
                true,
                false,
                Some(Version::release(0, 2, 9)),
                "linux",
                "x86_64"
            ),
            Plan::Install(_)
        ));
    }

    #[test]
    fn with_automatic_updates_off_a_newer_version_is_reported_until_asked() {
        assert!(matches!(
            plan(
                &newer(true),
                &folder(),
                false,
                false,
                None,
                "linux",
                "x86_64"
            ),
            Plan::Report(_)
        ));
        assert!(matches!(
            plan(
                &newer(true),
                &folder(),
                false,
                true,
                None,
                "linux",
                "x86_64"
            ),
            Plan::Install(_)
        ));
    }

    #[test]
    fn a_platform_with_no_published_update_says_so() {
        match plan(
            &newer(false),
            &folder(),
            true,
            true,
            None,
            "linux",
            "riscv64",
        ) {
            Plan::Report(why) => assert!(why.contains("linux/riscv64"), "{why}"),
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn settings_default_to_the_beta_channel_on_getjoinery() {
        let dir = std::env::temp_dir().join(format!("jd-update-settings-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let (s, problem) = Settings::load(&dir);
        assert!(problem.is_none());
        assert_eq!(
            s.feed_url(),
            "https://getjoinery.com/drive/updates/beta.json"
        );
        assert!(s.automatic);

        std::fs::write(dir.join(Settings::FILE), r#"{"channel":"stable"}"#).unwrap();
        assert_eq!(
            Settings::load(&dir).0.feed_url(),
            "https://getjoinery.com/drive/updates/stable.json"
        );

        // A channel name is part of a URL; nothing else gets in.
        std::fs::write(dir.join(Settings::FILE), r#"{"channel":"../../evil"}"#).unwrap();
        let (s, problem) = Settings::load(&dir);
        assert_eq!(s.channel, "beta");
        assert!(problem.is_some());

        // Damaged: the defaults, and the problem reported rather than fatal.
        std::fs::write(dir.join(Settings::FILE), "{ not json").unwrap();
        let (s, problem) = Settings::load(&dir);
        assert_eq!(s, Settings::default());
        assert!(problem.is_some());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_status_answer_says_what_a_person_needs_in_one_sentence() {
        let mut s = Status {
            state: State::Available,
            current: "0.2.0".into(),
            channel: "beta".into(),
            automatic: true,
            install: "installed by apt".into(),
            can_install: false,
            available: Some("0.3.0".into()),
            required: false,
            release_notes_url: None,
            detail: Some("Install it with the system package manager (apt, dnf).".into()),
            last_check_ms: Some(1),
            last_error: None,
            updated_from: None,
        };
        assert!(s.summary().starts_with("Version 0.3.0 is available."));
        assert!(s.summary().contains("apt"));
        s.required = true;
        assert!(s.summary().contains("required"));
        let j = s.to_json();
        assert_eq!(j["state"], "available");
        assert_eq!(j["available"], "0.3.0");
        assert_eq!(j["current"], "0.2.0");
        assert_eq!(j["can_install"], false);
    }

    #[test]
    fn an_update_that_was_undone_is_in_the_one_sentence_people_read() {
        // Found running a real daemon through a rollback: the reason sat in
        // `last_error` while the summary said only "Version 0.1.1."
        let s = Status {
            state: State::Idle,
            current: "0.1.1".into(),
            channel: "beta".into(),
            automatic: true,
            install: String::new(),
            can_install: true,
            available: None,
            required: false,
            release_notes_url: None,
            detail: None,
            last_check_ms: None,
            last_error: Some("The update to 0.1.2 was undone, so this computer runs 0.1.1 again: it did not start.".into()),
            updated_from: None,
        };
        assert!(s.summary().contains("0.1.2 was undone"), "{}", s.summary());
    }

    #[test]
    fn the_status_answer_carries_updates_and_the_page_can_ask_for_a_check() {
        use crate::control::{Caller, Request};
        let shared = Shared::new();
        let ask = |method: &str, path: &str, caller: Caller| {
            crate::daemon::handle_control(
                &shared,
                &Request {
                    method: method.into(),
                    path: path.into(),
                    body: Value::Null,
                    caller,
                },
            )
        };
        // No update thread: nothing is claimed, and a check says why not.
        let (_, body) = ask("GET", "/status", Caller::Owner);
        assert!(body.get("update").is_none());
        let (status, body) = ask("POST", "/check-update", Caller::Page);
        assert_eq!((status, body["ok"].clone()), (409, json!(false)));

        // A build with no key: the status says so, and so does a check — as a
        // reason the page can show, from the page's own button.
        let handle = Arc::new(Handle::new(Status {
            state: State::Disabled,
            current: "0.1.0".into(),
            channel: "beta".into(),
            automatic: true,
            install: String::new(),
            can_install: false,
            available: None,
            required: false,
            release_notes_url: None,
            detail: Some("This build carries no update signing key.".into()),
            last_check_ms: None,
            last_error: None,
            updated_from: None,
        }));
        assert!(shared.update.set(handle).is_ok());
        let (_, body) = ask("GET", "/status", Caller::Page);
        assert_eq!(body["update"]["state"], "disabled");
        assert!(body["update"]["summary"]
            .as_str()
            .unwrap()
            .contains("signing key"));
        let (status, body) = ask("POST", "/check-update", Caller::Page);
        assert_eq!(status, 409);
        assert!(body["error"].as_str().unwrap().contains("signing key"));
        // A check is a POST, like everything else that does something.
        assert_eq!(ask("GET", "/check-update", Caller::Owner).0, 405);
    }

    #[test]
    fn a_pause_from_before_the_update_is_put_back_once() {
        let shared = Shared::new();
        let (tx, rx) = std::sync::mpsc::channel();
        shared.attach(tx);
        let updater = Updater {
            settings: Settings::default(),
            settings_problem: None,
            keys: TrustedKeys::default(),
            keys_problem: None,
            current: Some(Version::release(0, 1, 0)),
            os: Os::current(),
            arch: std::env::consts::ARCH.into(),
            dir: std::env::temp_dir(),
            daemon: PathBuf::from("/nonexistent/joinery-drive"),
            install: Install::BuildTree,
        };
        let handle = updater.handle();
        handle
            .paused_before
            .store(true, std::sync::atomic::Ordering::SeqCst);
        let _ = shared.update.set(handle);
        keep_paused(&shared);
        keep_paused(&shared);
        assert_eq!(rx.try_recv().ok(), Some(Command::Pause));
        assert!(rx.try_recv().is_err(), "once, not on every attach");
    }

    #[test]
    fn jitter_stays_inside_its_window() {
        for _ in 0..200 {
            let d = jitter(FIRST_CHECK);
            assert!(d >= FIRST_CHECK.0 && d < FIRST_CHECK.1);
            let d = jitter_around(CHECK_INTERVAL, CHECK_JITTER);
            assert!(d >= CHECK_INTERVAL - CHECK_JITTER && d < CHECK_INTERVAL + CHECK_JITTER);
        }
    }

    #[test]
    fn a_check_request_to_a_build_that_cannot_check_says_why() {
        let handle = Handle::new(Status {
            state: State::Disabled,
            current: "0.1.0".into(),
            channel: "beta".into(),
            automatic: true,
            install: String::new(),
            can_install: false,
            available: None,
            required: false,
            release_notes_url: None,
            detail: Some("This build carries no update signing key.".into()),
            last_check_ms: None,
            last_error: None,
            updated_from: None,
        });
        let err = handle.check_now().unwrap_err();
        assert!(err.contains("signing key"), "{err}");
    }
}
