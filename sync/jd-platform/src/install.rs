//! How this copy of the program was installed, and how a newer copy takes its
//! place.
//!
//! The daemon keeps itself current (`jd_daemon::update`), and everything about
//! that which differs by operating system lives here: whether this copy may
//! replace itself at all, how the files are swapped, who starts the program
//! again afterwards, and how a running program notices that the file it was
//! started from has been replaced.
//!
//! **Whether a copy may replace itself** is a question about who owns its
//! files, not about the platform. A `.deb` or `.rpm` install belongs to the
//! package manager, and an updater that rewrote `/usr/bin` behind it would
//! leave a package database describing files that are no longer there. A copy
//! running out of a cargo build directory belongs to whoever is building it.
//! Both are told an update exists and left alone. What may be replaced is a
//! macOS app bundle, and the program's own folder on Windows and in the Linux
//! tarball install — each only when this account can write where it lives.
//!
//! **Every swap can be undone from any point it stops at.** A swap that dies
//! halfway leaves a marker saying so (the daemon writes it), and [`restore`]
//! works out from what is on disk which steps happened, so the old copy is
//! always recoverable. The one exception is stated where it lives: Windows has a
//! moment, between two renames, with no program at the live path.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::dirs::{APP_NAME, BINARY_NAME};

/// The operating systems a build can be for, as the update feed spells them.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Os {
    MacOs,
    Windows,
    Linux,
}

impl Os {
    pub const fn current() -> Os {
        #[cfg(target_os = "macos")]
        {
            Os::MacOs
        }
        #[cfg(target_os = "windows")]
        {
            Os::Windows
        }
        #[cfg(not(any(target_os = "macos", target_os = "windows")))]
        {
            Os::Linux
        }
    }

    pub const fn as_str(&self) -> &'static str {
        match self {
            Os::MacOs => "macos",
            Os::Windows => "windows",
            Os::Linux => "linux",
        }
    }

    /// The daemon's file name on this system.
    pub fn daemon_file_name(&self) -> String {
        match self {
            Os::Windows => format!("{BINARY_NAME}.exe"),
            _ => BINARY_NAME.to_string(),
        }
    }

    /// The tray's file name on this system. It sits beside the daemon in
    /// every packaging layout.
    pub fn tray_file_name(&self) -> String {
        match self {
            Os::Windows => format!("{BINARY_NAME}-tray.exe"),
            _ => format!("{BINARY_NAME}-tray"),
        }
    }
}

/// The app bundle's folder name on macOS.
pub fn bundle_name() -> String {
    format!("{APP_NAME}.app")
}

/// How this copy was installed, which decides whether it may replace itself.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Install {
    /// A macOS app bundle in a folder this account can write. Replaced whole,
    /// by one atomic swap of the bundle directory.
    Bundle { bundle: PathBuf },
    /// The program's files in a folder this account owns: the per-user Windows
    /// install and the Linux tarball. Each file is replaced in place.
    Folder { dir: PathBuf },
    /// Installed by a package manager, which owns the files.
    PackageManager { name: String },
    /// Cannot replace itself, and why.
    ReadOnly { why: String },
    /// Running out of a cargo build directory. Not replaced, not restarted
    /// when rebuilt, and not checked against the feed unless somebody asks:
    /// a build tree updates by being rebuilt, and the soak rig's devices run
    /// from one.
    BuildTree,
}

impl Install {
    /// Whether the updater may put a newer copy here itself.
    pub fn can_replace(&self) -> bool {
        matches!(self, Install::Bundle { .. } | Install::Folder { .. })
    }

    /// One sentence for `status` and the settings page.
    pub fn describe(&self) -> String {
        match self {
            Install::Bundle { bundle } => format!("the app at {}", bundle.display()),
            Install::Folder { dir } => format!("the program folder {}", dir.display()),
            Install::PackageManager { name } => {
                format!("installed by {name}, which installs its updates")
            }
            Install::ReadOnly { why } => why.clone(),
            Install::BuildTree => {
                "running from a build directory, which updates by being rebuilt".into()
            }
        }
    }

    /// The directory a swap stages its new copy in. It has to be on the same
    /// volume as what it replaces, because a swap is a rename and a rename
    /// cannot cross volumes — so it sits right beside it.
    pub fn staging_parent(&self) -> Option<PathBuf> {
        match self {
            Install::Bundle { bundle } => bundle.parent().map(Path::to_path_buf),
            Install::Folder { dir } => Some(dir.clone()),
            _ => None,
        }
    }
}

/// How this copy was installed, judged from where its executable is.
pub fn detect(exe: &Path) -> Install {
    classify(exe, Os::current(), &dir_writable)
}

/// The decision itself, as a pure function of the executable's path, the
/// platform, and which folders this account can write — so the rules for every
/// platform are tested from any platform.
pub fn classify(exe: &Path, os: Os, writable: &dyn Fn(&Path) -> bool) -> Install {
    let text = exe.to_string_lossy().replace('\\', "/");

    // A build tree belongs to whoever is building it. Replacing a developer's
    // freshly built binary with the published one would be a baffling way to
    // lose an afternoon's work.
    let parts: Vec<&str> = text.split('/').collect();
    if parts
        .windows(2)
        .any(|w| w[0] == "target" && (w[1] == "debug" || w[1] == "release"))
        || parts
            .windows(3)
            .any(|w| w[0] == "target" && (w[2] == "debug" || w[2] == "release"))
    {
        return Install::BuildTree;
    }

    let Some(dir) = exe.parent() else {
        return Install::ReadOnly {
            why: "cannot tell where this program is installed".into(),
        };
    };

    match os {
        Os::MacOs => {
            // `<X>.app/Contents/MacOS/joinery-drive`: the bundle is what gets
            // replaced, never one file inside it — a signed bundle with one
            // file changed is a bundle whose signature no longer holds.
            let bundle = dir
                .parent()
                .filter(|_| dir.file_name().is_some_and(|n| n == "MacOS"))
                .filter(|contents| contents.file_name().is_some_and(|n| n == "Contents"))
                .and_then(Path::parent)
                .filter(|b| b.extension().is_some_and(|e| e == "app"));
            match bundle {
                Some(bundle) => {
                    let parent = bundle.parent().unwrap_or(Path::new("/"));
                    if writable(parent) {
                        Install::Bundle {
                            bundle: bundle.to_path_buf(),
                        }
                    } else {
                        Install::ReadOnly {
                            why: format!(
                                "{} cannot be changed by this account; an administrator \
                                 has to install the update",
                                parent.display()
                            ),
                        }
                    }
                }
                None => Install::ReadOnly {
                    why: "not running from the app, which is what updates on a Mac".into(),
                },
            }
        }
        Os::Windows => {
            // MSIX packages live under WindowsApps, which the package manager
            // owns outright and nobody else can write.
            if text.to_ascii_lowercase().contains("/windowsapps/") {
                Install::PackageManager {
                    name: "the Microsoft Store package".into(),
                }
            } else if writable(dir) {
                Install::Folder {
                    dir: dir.to_path_buf(),
                }
            } else {
                Install::ReadOnly {
                    why: format!(
                        "{} cannot be changed by this account; reinstall to update",
                        dir.display()
                    ),
                }
            }
        }
        Os::Linux => {
            // /usr (but not /usr/local) is where a distribution's packages put
            // things, and nothing else may change them. /usr/local, /opt and a
            // home directory are the administrator's or the user's own.
            let packaged = (text.starts_with("/usr/") && !text.starts_with("/usr/local/"))
                || text.starts_with("/bin/")
                || text.starts_with("/sbin/");
            if packaged {
                Install::PackageManager {
                    name: "the system package manager (apt, dnf)".into(),
                }
            } else if text.starts_with("/snap/") {
                Install::PackageManager {
                    name: "snap".into(),
                }
            } else if text.starts_with("/nix/store/") {
                Install::PackageManager { name: "Nix".into() }
            } else if writable(dir) {
                Install::Folder {
                    dir: dir.to_path_buf(),
                }
            } else {
                Install::ReadOnly {
                    why: format!(
                        "{} cannot be changed by this account; install the update the \
                         way this copy was installed",
                        dir.display()
                    ),
                }
            }
        }
    }
}

/// Can this account create and rename files in `dir`?
///
/// Asked by doing it, because permission bits are only one of the answers: an
/// ACL, a read-only mount, or macOS's app-management protection can each say no
/// to a directory whose mode says yes.
pub fn dir_writable(dir: &Path) -> bool {
    let probe = dir.join(format!(".jd-write-probe-{}", std::process::id()));
    let made = std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&probe)
        .is_ok();
    if made {
        let _ = std::fs::remove_file(&probe);
    }
    made
}

// ---------------------------------------------------------------------------
// Swapping
// ---------------------------------------------------------------------------

/// One thing being replaced.
///
/// `staged` is the new copy, already beside `live` on the same volume.
/// `previous` is where the old copy is kept until the new one has proved it
/// starts. On macOS the old bundle simply trades places with the new one, so it
/// ends up at `staged` and `previous` is unused; `new_id` is the new bundle's
/// identity, which is how [`restore`] tells a swap that happened from one that
/// did not.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Swap {
    pub live: PathBuf,
    pub staged: PathBuf,
    pub previous: PathBuf,
    #[serde(default)]
    pub new_id: Option<u64>,
}

/// Put the staged copy at the live path, keeping the old one.
pub fn replace(swap: &mut Swap) -> std::io::Result<()> {
    #[cfg(target_os = "macos")]
    {
        // One atomic exchange of two directory entries. At no instant is
        // there no app at the live path — which matters, because the
        // LaunchAgent names that path and a daemon that cannot be found is a
        // daemon nothing will ever start again to repair it.
        swap.new_id = Some(file_id(&swap.staged)?);
        exchange(&swap.staged, &swap.live)
    }
    #[cfg(target_os = "windows")]
    {
        // A running executable cannot be overwritten or deleted on Windows,
        // but it can be renamed. So the old one steps aside and the new one
        // takes its name. Between the two renames there is no program at the
        // live path; the gap is two system calls wide.
        rename(&swap.live, &swap.previous)?;
        if let Err(e) = rename(&swap.staged, &swap.live) {
            let _ = rename(&swap.previous, &swap.live);
            return Err(e);
        }
        Ok(())
    }
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    {
        // The old copy is kept as a second name for the same file, then the
        // new one is renamed over the live name — atomic, and the running
        // process keeps the old file open regardless.
        let _ = std::fs::remove_file(&swap.previous);
        if std::fs::hard_link(&swap.live, &swap.previous).is_err() {
            std::fs::copy(&swap.live, &swap.previous)?;
        }
        std::fs::rename(&swap.staged, &swap.live)
    }
}

/// Put the old copy back, from wherever [`replace`] stopped.
///
/// Safe to call when the swap never happened (nothing changes) and safe to call
/// twice. What ends up at `staged` afterwards is the copy that was rejected.
pub fn restore(swap: &Swap) -> std::io::Result<()> {
    #[cfg(target_os = "macos")]
    {
        // Swapped exactly when the live path is the new bundle.
        let swapped = match (swap.new_id, file_id(&swap.live)) {
            (Some(new), Ok(live)) => new == live,
            _ => false,
        };
        if swapped {
            exchange(&swap.staged, &swap.live)?;
        }
        Ok(())
    }
    #[cfg(target_os = "windows")]
    {
        if swap.staged.exists() {
            // The new copy never moved in. Either nothing happened, or the old
            // copy stepped aside and the second rename did not follow.
            if !swap.live.exists() && swap.previous.exists() {
                rename(&swap.previous, &swap.live)?;
            }
            return Ok(());
        }
        if !swap.previous.exists() {
            return Ok(());
        }
        // The rejected copy can be renamed even while something still runs it.
        rename(&swap.live, &swap.staged)?;
        rename(&swap.previous, &swap.live)
    }
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    {
        if swap.staged.exists() {
            // The rename never happened; the live file is still the old one.
            let _ = std::fs::remove_file(&swap.previous);
            return Ok(());
        }
        if !swap.previous.exists() {
            return Ok(());
        }
        // Keep the rejected copy where the staged one was, then put the old
        // one back under the live name in one atomic step.
        let _ = std::fs::hard_link(&swap.live, &swap.staged);
        std::fs::rename(&swap.previous, &swap.live)
    }
}

/// A rename on Windows, given a moment to succeed.
///
/// A program file that has just been written or run is very often open in
/// somebody else's hands for a second afterwards — an antivirus scan, the
/// search indexer — and Windows refuses to rename a file open without
/// delete sharing. That refusal is about timing, not about the update, so it
/// is retried for two seconds before it is believed.
#[cfg(target_os = "windows")]
fn rename(from: &Path, to: &Path) -> std::io::Result<()> {
    let mut attempt = 0;
    loop {
        match std::fs::rename(from, to) {
            Err(e) if e.kind() == std::io::ErrorKind::PermissionDenied && attempt < 20 => {
                attempt += 1;
                std::thread::sleep(std::time::Duration::from_millis(100));
            }
            other => return other,
        }
    }
}

#[cfg(target_os = "macos")]
fn file_id(path: &Path) -> std::io::Result<u64> {
    use std::os::unix::fs::MetadataExt;
    Ok(std::fs::symlink_metadata(path)?.ino())
}

/// Atomically exchange two directory entries.
#[cfg(target_os = "macos")]
fn exchange(a: &Path, b: &Path) -> std::io::Result<()> {
    use std::ffi::CString;
    use std::os::raw::{c_char, c_int, c_uint};
    use std::os::unix::ffi::OsStrExt;

    extern "C" {
        // In libSystem since macOS 10.12, so every Mac the client supports.
        fn renamex_np(from: *const c_char, to: *const c_char, flags: c_uint) -> c_int;
    }
    const RENAME_SWAP: c_uint = 0x0000_0002;

    let a_c = CString::new(a.as_os_str().as_bytes())
        .map_err(|_| std::io::Error::other("path contains a NUL byte"))?;
    let b_c = CString::new(b.as_os_str().as_bytes())
        .map_err(|_| std::io::Error::other("path contains a NUL byte"))?;
    // SAFETY: both pointers are NUL-terminated strings that outlive the call.
    if unsafe { renamex_np(a_c.as_ptr(), b_c.as_ptr(), RENAME_SWAP) } == 0 {
        return Ok(());
    }
    let err = std::io::Error::last_os_error();
    // A volume that cannot exchange (some network filesystems) gets three
    // renames instead. Not atomic, so only when the atomic way is refused.
    if err.raw_os_error() == Some(45) {
        // ENOTSUP
        let aside = b.with_extension("jd-exchange");
        std::fs::rename(b, &aside)?;
        std::fs::rename(a, b)?;
        return std::fs::rename(&aside, a);
    }
    Err(err)
}

// ---------------------------------------------------------------------------
// Starting again
// ---------------------------------------------------------------------------

/// What starts the daemon again when it exits, when something does.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Supervisor {
    /// Our LaunchAgent: it restarts the daemon after any exit that is not a
    /// clean one.
    Launchd,
    /// A systemd unit whose own process this is: ours (`Restart=on-failure`),
    /// or one an administrator wrote.
    Systemd,
}

/// The exit status a daemon uses to be started again by its supervisor.
///
/// Non-zero, so `KeepAlive { SuccessfulExit = false }` and
/// `Restart=on-failure` both bring it back, and EX_TEMPFAIL, which is what it
/// means: stopped on purpose, start me again.
pub const RESTART_EXIT_CODE: i32 = 75;

/// Is this process one a supervisor will start again when it exits?
///
/// Asked precisely, because the answer decides whether exiting is a restart or
/// a stop. A shell inside a systemd session carries `INVOCATION_ID` too, and a
/// daemon run by hand from that shell that exited expecting to be restarted
/// would simply be gone.
pub fn supervisor() -> Option<Supervisor> {
    #[cfg(target_os = "macos")]
    {
        // launchd sets this to the job's label; a Terminal shell has its own.
        if std::env::var("XPC_SERVICE_NAME").ok().as_deref() == Some(crate::dirs::BUNDLE_ID) {
            return Some(Supervisor::Launchd);
        }
        None
    }
    #[cfg(target_os = "linux")]
    {
        let exec_pid = std::env::var("SYSTEMD_EXEC_PID").ok();
        let invocation = std::env::var_os("INVOCATION_ID").is_some();
        let cgroup = std::fs::read_to_string("/proc/self/cgroup").unwrap_or_default();
        systemd_supervises(exec_pid.as_deref(), invocation, &cgroup, std::process::id())
            .then_some(Supervisor::Systemd)
    }
    #[cfg(not(any(target_os = "macos", target_os = "linux")))]
    {
        // Windows starts the daemon from the Run key at login and nothing
        // restarts it, so a restart is always a fresh start of its own.
        None
    }
}

/// Is this process a systemd unit's own process, judged from what systemd
/// puts in its environment and `/proc/self/cgroup`?
///
/// `SYSTEMD_EXEC_PID` names the unit's own process, and every process it
/// starts inherits it, so a daemon is the unit's exactly when it is its own
/// pid. Then exiting non-zero is the only way back, whoever wrote the unit:
/// systemd stops everything in a unit's control group when its own process
/// exits, so a replacement this process started would be stopped with it.
/// systemd older than 248 does not set it; there, only our own unit counts.
pub fn systemd_supervises(exec_pid: Option<&str>, invocation: bool, cgroup: &str, me: u32) -> bool {
    match exec_pid.and_then(|p| p.trim().parse::<u32>().ok()) {
        Some(pid) => pid == me,
        None => invocation && in_own_systemd_unit(cgroup),
    }
}

/// Is the process in our unit's control group, judged from `/proc/self/cgroup`?
pub fn in_own_systemd_unit(cgroup: &str) -> bool {
    let unit = format!("/{BINARY_NAME}.service");
    cgroup.lines().any(|line| line.trim_end().ends_with(&unit))
}

/// Start a program with no ties to this process: no shared terminal, no
/// inherited output, not in this process's group — so this one exiting, or the
/// terminal it was started from closing, takes nothing with it.
pub fn spawn_detached(exe: &Path, args: &[&str]) -> std::io::Result<std::process::Child> {
    let mut command = std::process::Command::new(exe);
    command
        .args(args)
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null());
    #[cfg(unix)]
    {
        use std::os::unix::process::CommandExt;
        command.process_group(0);
    }
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;
        // DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP: no console window, and
        // not in the group a Ctrl+C to this process would reach.
        command.creation_flags(0x0000_0008 | 0x0000_0200);
    }
    command.spawn()
}

// ---------------------------------------------------------------------------
// Noticing a replaced executable
// ---------------------------------------------------------------------------

/// What identifies one file, cheaply: enough to see that the file at a path is
/// not the one that was there before.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Stamp {
    len: u64,
    modified: Option<std::time::SystemTime>,
    id: Option<u64>,
}

fn stamp(path: &Path) -> Option<Stamp> {
    let meta = std::fs::metadata(path).ok()?;
    #[cfg(unix)]
    let id = {
        use std::os::unix::fs::MetadataExt;
        Some(meta.ino())
    };
    #[cfg(not(unix))]
    let id = None;
    Some(Stamp {
        len: meta.len(),
        modified: meta.modified().ok(),
        id,
    })
}

/// Watches the file a process was started from.
///
/// A running program keeps executing the copy it started from, whatever is
/// done to the path afterwards — so after an update, or after `apt upgrade`, a
/// process that is never restarted runs the old code indefinitely, and a tray
/// keeps drawing a menu the new daemon no longer answers. This is how each
/// notices and starts the new copy instead.
#[derive(Debug)]
pub struct ExeWatch {
    path: PathBuf,
    launched: Option<Stamp>,
    last: Option<Stamp>,
}

impl ExeWatch {
    /// Watch the running executable. None when the platform will not say what
    /// that is.
    pub fn current() -> Option<ExeWatch> {
        std::env::current_exe().ok().map(|p| ExeWatch::new(&p))
    }

    pub fn new(path: &Path) -> ExeWatch {
        let path = std::fs::canonicalize(path).unwrap_or_else(|_| path.to_path_buf());
        let launched = stamp(&path);
        ExeWatch {
            path,
            last: launched.clone(),
            launched,
        }
    }

    /// The path this process was started from.
    pub fn path(&self) -> &Path {
        &self.path
    }

    /// True once the file at the launch path is a different file from the one
    /// this process started from, and has looked the same on two looks in a
    /// row.
    ///
    /// The second look is what keeps a half-written file from being started: a
    /// copy that writes in place changes the file's size and time as it goes,
    /// and a program launched from the middle of that is a program that
    /// crashes. A missing file is never "replaced"; it is somebody partway
    /// through putting a new one there.
    pub fn replaced(&mut self) -> bool {
        let now = stamp(&self.path);
        let settled = now.is_some() && now == self.last;
        let changed = now.is_some() && now != self.launched;
        self.last = now;
        settled && changed
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn yes(_: &Path) -> bool {
        true
    }
    fn no(_: &Path) -> bool {
        false
    }

    #[test]
    fn a_mac_app_bundle_the_user_can_write_is_replaced_whole() {
        let exe = Path::new("/Applications/Joinery Drive.app/Contents/MacOS/joinery-drive");
        assert_eq!(
            classify(exe, Os::MacOs, &yes),
            Install::Bundle {
                bundle: PathBuf::from("/Applications/Joinery Drive.app")
            }
        );
        // And the bundle's own folder is where the new copy is staged: a swap
        // is a rename, and a rename cannot cross volumes.
        assert_eq!(
            classify(exe, Os::MacOs, &yes).staging_parent(),
            Some(PathBuf::from("/Applications"))
        );
    }

    #[test]
    fn a_bundle_this_account_cannot_write_is_reported_and_left_alone() {
        let exe = Path::new("/Applications/Joinery Drive.app/Contents/MacOS/joinery-drive");
        let install = classify(exe, Os::MacOs, &no);
        assert!(!install.can_replace());
        assert!(install.describe().contains("administrator"));
    }

    #[test]
    fn a_bare_binary_on_a_mac_is_not_something_the_updater_replaces() {
        // What a Mac update carries is a bundle; there is nothing to put in
        // place of a loose binary.
        let install = classify(Path::new("/usr/local/bin/joinery-drive"), Os::MacOs, &yes);
        assert!(!install.can_replace());
    }

    #[test]
    fn a_debian_or_rpm_install_belongs_to_the_package_manager() {
        for exe in [
            "/usr/bin/joinery-drive",
            "/usr/lib/joinery-drive/joinery-drive",
        ] {
            let install = classify(Path::new(exe), Os::Linux, &yes);
            assert!(
                matches!(install, Install::PackageManager { .. }),
                "{exe}: {install:?}"
            );
            assert!(!install.can_replace());
        }
    }

    #[test]
    fn a_tarball_install_in_a_folder_the_user_owns_replaces_itself() {
        for exe in [
            "/home/u/.local/bin/joinery-drive",
            "/usr/local/bin/joinery-drive",
            "/opt/joinery-drive/joinery-drive",
        ] {
            let install = classify(Path::new(exe), Os::Linux, &yes);
            assert!(
                matches!(install, Install::Folder { .. }),
                "{exe}: {install:?}"
            );
        }
        // The same path the account cannot write is reported instead.
        let install = classify(Path::new("/usr/local/bin/joinery-drive"), Os::Linux, &no);
        assert!(matches!(install, Install::ReadOnly { .. }));
    }

    #[test]
    fn a_windows_store_package_is_left_to_the_store() {
        let exe = Path::new(r"C:\Program Files\WindowsApps\Joinery.Drive_1.0\joinery-drive.exe");
        assert!(matches!(
            classify(exe, Os::Windows, &yes),
            Install::PackageManager { .. }
        ));
        let exe = Path::new(r"C:\Users\u\AppData\Local\Programs\Joinery Drive\joinery-drive.exe");
        assert!(matches!(
            classify(exe, Os::Windows, &yes),
            Install::Folder { .. }
        ));
    }

    #[test]
    fn a_build_directory_is_never_replaced_on_any_platform() {
        for (exe, os) in [
            ("/home/dev/sync/target/debug/joinery-drive", Os::Linux),
            ("/Users/dev/sync/target/release/joinery-drive", Os::MacOs),
            (r"C:\src\sync\target\release\joinery-drive.exe", Os::Windows),
            (
                "/home/dev/sync/target/x86_64-unknown-linux-musl/release/joinery-drive",
                Os::Linux,
            ),
        ] {
            let install = classify(Path::new(exe), os, &yes);
            assert_eq!(install, Install::BuildTree, "{exe}");
            assert!(!install.can_replace());
        }
    }

    #[test]
    fn a_unit_s_own_process_is_supervised_and_anything_it_started_is_not() {
        let me = 4242;
        // The unit's own process: ours, or one an administrator wrote.
        assert!(systemd_supervises(
            Some("4242"),
            true,
            "0::/user.slice/user-1000.slice/user@1000.service/app.slice/joinery-drive.service\n",
            me
        ));
        assert!(systemd_supervises(
            Some("4242"),
            true,
            "0::/system.slice/system-soak.slice/soak-device@a.service\n",
            me
        ));
        // Started by hand from a shell that some unit started: the shell's
        // environment says which process is the unit's, and it is not this one.
        // (Found on the dev box, whose shells run inside work-restore.service.)
        assert!(!systemd_supervises(
            Some("1001"),
            true,
            "0::/user.slice/user-1000.slice/user@1000.service/app.slice/work-restore.service\n",
            me
        ));
        // systemd older than 248: only our own unit.
        assert!(systemd_supervises(
            None,
            true,
            "0::/user.slice/user-1000.slice/user@1000.service/app.slice/joinery-drive.service\n",
            me
        ));
        assert!(!systemd_supervises(
            None,
            true,
            "0::/user.slice/user-1000.slice/session-4.scope\n",
            me
        ));
        assert!(!systemd_supervises(None, false, "", me));
    }

    #[test]
    fn only_our_own_unit_counts_as_being_supervised() {
        // A shell in a systemd session has INVOCATION_ID too; only our unit's
        // control group means "exiting is a restart".
        assert!(in_own_systemd_unit(
            "0::/user.slice/user-1000.slice/user@1000.service/app.slice/joinery-drive.service\n"
        ));
        assert!(!in_own_systemd_unit(
            "0::/user.slice/user-1000.slice/session-4.scope\n"
        ));
        assert!(!in_own_systemd_unit(
            "0::/user.slice/user-1000.slice/user@1000.service/app.slice/joinery-drive-tray.service\n"
        ));
    }

    fn temp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!("jd-install-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    #[cfg(not(target_os = "macos"))]
    #[test]
    fn a_file_swap_can_be_undone_from_every_point_it_stops_at() {
        let dir = temp("swap");
        let live = dir.join("joinery-drive");
        let staged = dir.join(".staged");
        let previous = dir.join(".previous");
        let reset = || {
            let _ = std::fs::remove_file(&live);
            let _ = std::fs::remove_file(&staged);
            let _ = std::fs::remove_file(&previous);
            std::fs::write(&live, b"old").unwrap();
            std::fs::write(&staged, b"new").unwrap();
        };
        let mut swap = Swap {
            live: live.clone(),
            staged: staged.clone(),
            previous: previous.clone(),
            new_id: None,
        };

        // Nothing happened yet: restoring changes nothing.
        reset();
        restore(&swap).unwrap();
        assert_eq!(std::fs::read(&live).unwrap(), b"old");

        // The whole swap, then back.
        reset();
        replace(&mut swap).unwrap();
        assert_eq!(std::fs::read(&live).unwrap(), b"new");
        assert_eq!(std::fs::read(&previous).unwrap(), b"old");
        restore(&swap).unwrap();
        assert_eq!(std::fs::read(&live).unwrap(), b"old");
        assert_eq!(std::fs::read(&staged).unwrap(), b"new");
        // Twice is the same as once.
        restore(&swap).unwrap();
        assert_eq!(std::fs::read(&live).unwrap(), b"old");

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[cfg(target_os = "macos")]
    #[test]
    fn a_bundle_swap_is_one_exchange_and_undoes_cleanly() {
        let dir = temp("bundle");
        let live = dir.join("Joinery Drive.app");
        let staged = dir.join(".staged.app");
        std::fs::create_dir_all(live.join("Contents")).unwrap();
        std::fs::write(live.join("Contents/v"), b"old").unwrap();
        std::fs::create_dir_all(staged.join("Contents")).unwrap();
        std::fs::write(staged.join("Contents/v"), b"new").unwrap();
        let mut swap = Swap {
            live: live.clone(),
            staged: staged.clone(),
            previous: dir.join(".unused"),
            new_id: None,
        };

        replace(&mut swap).unwrap();
        assert_eq!(std::fs::read(live.join("Contents/v")).unwrap(), b"new");
        assert_eq!(std::fs::read(staged.join("Contents/v")).unwrap(), b"old");

        restore(&swap).unwrap();
        assert_eq!(std::fs::read(live.join("Contents/v")).unwrap(), b"old");
        // Restoring again must not swap them back: the identity says the live
        // bundle is no longer the new one.
        restore(&swap).unwrap();
        assert_eq!(std::fs::read(live.join("Contents/v")).unwrap(), b"old");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_replaced_executable_is_noticed_only_once_it_has_settled() {
        let dir = temp("watch");
        let exe = dir.join("prog");
        std::fs::write(&exe, b"version one").unwrap();
        let mut watch = ExeWatch::new(&exe);
        assert!(!watch.replaced());

        // A new file at the path, by rename, the way every installer does it.
        let tmp = dir.join("prog.new");
        std::fs::write(&tmp, b"version two, longer").unwrap();
        std::fs::rename(&tmp, &exe).unwrap();
        // First look: changed, but not yet seen twice.
        assert!(!watch.replaced());
        // Second look, unchanged since: now it counts.
        assert!(watch.replaced());

        // A missing file is somebody partway through, never a replacement.
        let mut watch = ExeWatch::new(&exe);
        std::fs::remove_file(&exe).unwrap();
        assert!(!watch.replaced());
        assert!(!watch.replaced());
        let _ = std::fs::remove_dir_all(&dir);
    }
}
