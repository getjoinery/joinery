//! Starting the daemon when the user logs in.
//!
//! A sync client that only runs while somebody remembers to run it is not a sync
//! client. Each platform has exactly one right way to do this for a *user*
//! agent — not a system service, which would run as the wrong account and be
//! unable to reach the user's keychain or their home directory:
//!
//! - **macOS** — a LaunchAgent plist in `~/Library/LaunchAgents`, and a second
//!   one for the menu-bar app where it is installed beside the daemon (the app
//!   bundle), so the icon is back after a restart without anyone opening it.
//! - **Windows** — a value under the current user's `Run` key. Where the tray
//!   is installed beside the daemon, the value starts the tray, which starts
//!   the daemon with no console window (see [`windows_run_command`]).
//! - **Linux** — a systemd user unit, which is also what makes `systemctl
//!   --user status joinery-drive` answer the question "why did it stop", plus
//!   the `default.target.wants` link that `systemctl --user enable` would make,
//!   and an XDG autostart entry for the tray where one is installed beside the
//!   daemon. Per user, because it is written by that user's own `autostart on`
//!   (or the setup's "start at login"), never by the package.
//!
//! The generated artifacts are produced by pure functions, so the exact plist a
//! Mac will load and the exact unit a Linux box will load are both inspectable
//! from a test on any machine. That matters more than it sounds: a plist with a
//! typo does not fail loudly, it simply never starts, and the user finds out
//! when their files are a week stale.

use std::path::{Path, PathBuf};

use crate::dirs::{APP_NAME, BINARY_NAME, BUNDLE_ID};

/// Tells the tray it was started at logon: start the sync daemon too, without
/// a window, unless one is already running.
pub const START_DAEMON_FLAG: &str = "--start-daemon";

#[derive(Debug, thiserror::Error)]
pub enum AutostartError {
    #[error("io error on {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("{0}")]
    Platform(String),
}

pub type AutostartResult<T> = Result<T, AutostartError>;

/// The macOS LaunchAgent that starts the daemon at login.
///
/// `RunAtLoad` plus `KeepAlive` restricted to a non-zero exit: the daemon comes
/// back if it crashes, and stays down if the user asked it to stop. `KeepAlive:
/// true` would make `joinery-drive pause` a fight with launchd.
pub fn launch_agent_plist(exe: &Path) -> String {
    format!(
        r#"<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key>
	<string>{BUNDLE_ID}</string>
	<key>ProgramArguments</key>
	<array>
		<string>{exe}</string>
		<string>daemon</string>
	</array>
	<key>RunAtLoad</key>
	<true/>
	<key>KeepAlive</key>
	<dict>
		<key>SuccessfulExit</key>
		<false/>
	</dict>
	<key>ProcessType</key>
	<string>Background</string>
</dict>
</plist>
"#,
        exe = xml_escape(&exe.to_string_lossy()),
    )
}

/// The macOS LaunchAgent that opens the menu-bar app at login.
///
/// Its own label in the daemon's family, so `launchctl` names the two apart and
/// either can be listed or stopped alone. `LimitLoadToSessionType Aqua`: only in
/// a graphical login, never in an SSH session, where there is no menu bar to
/// draw in. `ProcessType Interactive`: it is something a person looks at, and
/// is scheduled like one. No `KeepAlive`: a user who quits the icon has it
/// back at the next login, not seconds later.
pub fn tray_launch_agent_plist(tray: &Path) -> String {
    format!(
        r#"<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>Label</key>
	<string>{BUNDLE_ID}.tray</string>
	<key>ProgramArguments</key>
	<array>
		<string>{tray}</string>
	</array>
	<key>RunAtLoad</key>
	<true/>
	<key>LimitLoadToSessionType</key>
	<string>Aqua</string>
	<key>ProcessType</key>
	<string>Interactive</string>
</dict>
</plist>
"#,
        tray = xml_escape(&tray.to_string_lossy()),
    )
}

/// The systemd user unit.
///
/// `WantedBy=default.target` rather than `multi-user.target`, because this is a
/// user unit and `default.target` is what a user session actually reaches.
/// `Restart=on-failure` for the same reason as macOS: come back from a crash,
/// stay down when asked to stop.
pub fn systemd_user_unit(exe: &Path) -> String {
    format!(
        "[Unit]\n\
         Description=Joinery Drive sync\n\
         After=network-online.target\n\
         \n\
         [Service]\n\
         Type=simple\n\
         ExecStart={exe} daemon\n\
         Restart=on-failure\n\
         RestartSec=10\n\
         \n\
         [Install]\n\
         WantedBy=default.target\n",
        exe = exe.display(),
    )
}

/// The command line the Windows `Run` value holds.
///
/// The daemon is a console program, because it is also the command line, and
/// Windows gives a console program started at logon a console window of its
/// own — one that stops sync when somebody closes it. So where the tray is
/// installed beside the daemon, the tray is what starts at logon: a windowed
/// program, which starts the daemon with no console window at all and then
/// shows its icon. A daemon installed on its own is started directly, and
/// lets go of the console it is given (`console::release_own_console`).
pub fn windows_run_command(exe: &Path, tray: Option<&Path>) -> String {
    match tray {
        Some(tray) => format!("\"{}\" {START_DAEMON_FLAG}", tray.display()),
        None => format!("\"{}\" daemon", exe.display()),
    }
}

/// The tray installed beside `exe`, if there is one.
pub fn tray_beside(exe: &Path) -> Option<PathBuf> {
    let tray = exe.with_file_name(crate::install::Os::current().tray_file_name());
    tray.is_file().then_some(tray)
}

/// The XDG autostart entry that brings the tray back at each desktop login.
///
/// Plain `joinery-drive-tray`, never the Windows flag: on Linux systemd starts
/// the daemon, and a tray racing it to the same job would start a second one.
pub fn xdg_autostart_entry(tray: &Path) -> String {
    format!(
        "[Desktop Entry]\n\
         Type=Application\n\
         Name={APP_NAME}\n\
         Comment=The {APP_NAME} status icon\n\
         Exec={exec}\n\
         Icon={BINARY_NAME}\n\
         Terminal=false\n\
         NoDisplay=true\n\
         X-GNOME-Autostart-enabled=true\n",
        exec = desktop_exec_arg(&tray.to_string_lossy()),
    )
}

/// One argument of a desktop entry's `Exec` line, quoted as the Desktop Entry
/// Specification requires.
///
/// A path with a space in it, unquoted, is two arguments, and the session
/// starts a program that does not exist — silently, at every login. `%` starts
/// a field code anywhere on the line, so it is doubled; inside quotes `"`,
/// `` ` ``, `$` and `\` take a backslash; and the file format's own escaping
/// doubles every backslash on top of that.
fn desktop_exec_arg(arg: &str) -> String {
    const RESERVED: &[char] = &[
        ' ', '\t', '\n', '"', '\'', '\\', '>', '<', '~', '|', '&', ';', '$', '*', '?', '#', '(',
        ')', '`',
    ];
    let arg = arg.replace('%', "%%");
    if !arg.contains(RESERVED) {
        return arg;
    }
    let mut quoted = String::from("\"");
    for c in arg.chars() {
        if matches!(c, '"' | '`' | '$' | '\\') {
            quoted.push('\\');
        }
        quoted.push(c);
    }
    quoted.push('"');
    quoted.replace('\\', "\\\\")
}

/// Where the platform expects its artifact.
pub fn autostart_path(home: &Path) -> Option<PathBuf> {
    #[cfg(target_os = "macos")]
    {
        Some(
            home.join("Library/LaunchAgents")
                .join(format!("{BUNDLE_ID}.plist")),
        )
    }
    #[cfg(target_os = "windows")]
    {
        // Windows keeps this in the registry rather than a file.
        let _ = home;
        None
    }
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    {
        Some(
            home.join(".config/systemd/user")
                .join(format!("{}.service", crate::dirs::BINARY_NAME)),
        )
    }
}

/// The link that makes a systemd user unit start at login.
///
/// The unit file alone does nothing. systemd reads `[Install] WantedBy=` only
/// when something enables the unit; at login it starts what is linked into
/// `default.target.wants/`, and a unit that is merely present sits there unrun
/// while every check that looks for the file says autostart is on. This is the
/// link `systemctl --user enable` makes, made directly so that enabling works
/// without a running user manager — over SSH, in a package script, in a test.
#[cfg(not(any(target_os = "macos", target_os = "windows")))]
fn systemd_wants_link(home: &Path) -> PathBuf {
    home.join(".config/systemd/user/default.target.wants")
        .join(format!("{}.service", crate::dirs::BINARY_NAME))
}

/// The menu-bar app's LaunchAgent, beside the daemon's.
#[cfg(target_os = "macos")]
fn tray_launch_agent_path(home: &Path) -> PathBuf {
    home.join("Library/LaunchAgents")
        .join(format!("{BUNDLE_ID}.tray.plist"))
}

/// The user's own XDG autostart entry for the tray.
#[cfg(not(any(target_os = "macos", target_os = "windows")))]
fn xdg_autostart_path(home: &Path) -> PathBuf {
    home.join(".config/autostart")
        .join(format!("{}.desktop", crate::dirs::TRAY_BINARY_NAME))
}

/// Refuse to register a copy of the program that will not be there tomorrow.
///
/// macOS runs an app opened straight from its download, or from inside the disk
/// image it came on, out of a randomized read-only mount under
/// `AppTranslocation` that is gone after the next restart. A LaunchAgent naming
/// that path loads today and never starts again. The way out is the one macOS
/// intends: move the app to Applications, which ends the translocation.
pub fn check_registrable(exe: &Path) -> AutostartResult<()> {
    if exe
        .components()
        .any(|c| c.as_os_str() == std::ffi::OsStr::new("AppTranslocation"))
    {
        return Err(AutostartError::Platform(format!(
            "{APP_NAME} is running from a temporary copy macOS made of the download, which \
             disappears at restart. Move it to the Applications folder, open it from there, \
             and turn this on again."
        )));
    }
    Ok(())
}

/// Register the daemon to start at login. Safe to repeat.
pub fn enable(exe: &Path, home: &Path) -> AutostartResult<()> {
    check_registrable(exe)?;
    #[cfg(target_os = "windows")]
    {
        let _ = home;
        return windows_run_key::set(&windows_run_command(exe, tray_beside(exe).as_deref()));
    }
    #[allow(unreachable_code)]
    {
        let Some(path) = autostart_path(home) else {
            return Ok(());
        };
        create_parent(&path)?;
        let body = if cfg!(target_os = "macos") {
            launch_agent_plist(exe)
        } else {
            systemd_user_unit(exe)
        };
        std::fs::write(&path, body).map_err(|e| AutostartError::Io {
            path: path.clone(),
            source: e,
        })?;

        // The menu-bar app comes back at login with the daemon when it is
        // installed beside it — inside the same app bundle, so a translocated
        // daemon (refused above) means a translocated app too.
        #[cfg(target_os = "macos")]
        if let Some(tray) = tray_beside(exe) {
            let agent = tray_launch_agent_path(home);
            std::fs::write(&agent, tray_launch_agent_plist(&tray)).map_err(|e| {
                AutostartError::Io {
                    path: agent.clone(),
                    source: e,
                }
            })?;
        }

        #[cfg(not(any(target_os = "macos", target_os = "windows")))]
        {
            // Replaced rather than kept: a link left behind by an older install
            // can point at a unit that no longer exists, and a dangling link
            // starts nothing.
            let link = systemd_wants_link(home);
            create_parent(&link)?;
            remove_if_present(&link)?;
            std::os::unix::fs::symlink(&path, &link).map_err(|e| AutostartError::Io {
                path: link.clone(),
                source: e,
            })?;

            // The status icon comes back at desktop login with the daemon, for
            // this user only, when it is installed beside it.
            if let Some(tray) = tray_beside(exe) {
                let entry = xdg_autostart_path(home);
                create_parent(&entry)?;
                std::fs::write(&entry, xdg_autostart_entry(&tray)).map_err(|e| {
                    AutostartError::Io {
                        path: entry.clone(),
                        source: e,
                    }
                })?;
            }
        }
        Ok(())
    }
}

/// Stop starting at login. Absent is success: the user asking twice must not
/// see a failure the second time.
pub fn disable(home: &Path) -> AutostartResult<()> {
    #[cfg(target_os = "windows")]
    {
        let _ = home;
        return windows_run_key::remove();
    }
    #[allow(unreachable_code)]
    {
        // The link first: once it is gone nothing starts at login, whatever
        // happens to the unit file after it.
        #[cfg(not(any(target_os = "macos", target_os = "windows")))]
        {
            remove_if_present(&systemd_wants_link(home))?;
            remove_if_present(&xdg_autostart_path(home))?;
        }
        #[cfg(target_os = "macos")]
        remove_if_present(&tray_launch_agent_path(home))?;

        let Some(path) = autostart_path(home) else {
            return Ok(());
        };
        remove_if_present(&path)
    }
}

/// Will the daemon start at the next login? On Linux that is the link, not the
/// unit file — a unit enabled with `systemctl --user enable` from the packaged
/// copy has a link and no file here, and it starts.
pub fn is_enabled(home: &Path) -> bool {
    #[cfg(target_os = "windows")]
    {
        let _ = home;
        return windows_run_key::is_set();
    }
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    {
        return std::fs::symlink_metadata(systemd_wants_link(home)).is_ok();
    }
    #[allow(unreachable_code)]
    {
        autostart_path(home).map(|p| p.exists()).unwrap_or(false)
    }
}

fn create_parent(path: &Path) -> AutostartResult<()> {
    match path.parent() {
        Some(parent) => std::fs::create_dir_all(parent).map_err(|e| AutostartError::Io {
            path: parent.to_path_buf(),
            source: e,
        }),
        None => Ok(()),
    }
}

/// Remove a file or a link — including a dangling one, which `exists()` would
/// report as absent.
fn remove_if_present(path: &Path) -> AutostartResult<()> {
    match std::fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(AutostartError::Io {
            path: path.to_path_buf(),
            source: e,
        }),
    }
}

/// XML has exactly five characters that cannot appear as text, and a home
/// directory can legally contain three of them. An unescaped `&` in a path makes
/// launchd reject the whole plist — silently, at login, months later.
fn xml_escape(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&apos;"),
            _ => out.push(c),
        }
    }
    out
}

#[cfg(windows)]
mod windows_run_key {
    //! `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`.
    //!
    //! The per-user key, never the machine one: a machine-wide entry runs for
    //! every account on the PC, each of them trying to sync somebody else's
    //! Drive into somebody else's home directory.

    use super::{AutostartError, AutostartResult};
    use windows_sys::Win32::Foundation::ERROR_SUCCESS;
    use windows_sys::Win32::System::Registry::{
        RegCloseKey, RegDeleteValueW, RegOpenKeyExW, RegQueryValueExW, RegSetValueExW, HKEY,
        HKEY_CURRENT_USER, KEY_READ, KEY_WRITE, REG_SZ,
    };

    const RUN_KEY: &str = r"Software\Microsoft\Windows\CurrentVersion\Run";
    const VALUE_NAME: &str = "JoineryDrive";

    fn wide(s: &str) -> Vec<u16> {
        s.encode_utf16().chain(std::iter::once(0)).collect()
    }

    fn open(access: u32) -> AutostartResult<HKEY> {
        let mut key: HKEY = std::ptr::null_mut();
        // SAFETY: the path is a NUL-terminated wide string that outlives the
        // call, and `key` is written only on success.
        let rc = unsafe {
            RegOpenKeyExW(
                HKEY_CURRENT_USER,
                wide(RUN_KEY).as_ptr(),
                0,
                access,
                &mut key,
            )
        };
        if rc != ERROR_SUCCESS {
            return Err(AutostartError::Platform(format!(
                "cannot open the Run key (error {rc})"
            )));
        }
        Ok(key)
    }

    pub fn set(command: &str) -> AutostartResult<()> {
        let key = open(KEY_WRITE)?;
        let name = wide(VALUE_NAME);
        let value = wide(command);
        // Byte length including the terminating NUL, which is what the registry
        // wants for REG_SZ — omitting it produces a value Windows reads as
        // unterminated and refuses to run.
        let bytes = value.len() * std::mem::size_of::<u16>();
        // SAFETY: both buffers outlive the call and `bytes` describes `value`.
        let rc = unsafe {
            RegSetValueExW(
                key,
                name.as_ptr(),
                0,
                REG_SZ,
                value.as_ptr() as *const u8,
                bytes as u32,
            )
        };
        unsafe { RegCloseKey(key) };
        if rc != ERROR_SUCCESS {
            return Err(AutostartError::Platform(format!(
                "cannot write the Run value (error {rc})"
            )));
        }
        Ok(())
    }

    pub fn remove() -> AutostartResult<()> {
        let Ok(key) = open(KEY_WRITE) else {
            // No Run key at all means nothing is registered, which is the state
            // the caller asked for.
            return Ok(());
        };
        // SAFETY: the name outlives the call.
        unsafe {
            RegDeleteValueW(key, wide(VALUE_NAME).as_ptr());
            RegCloseKey(key);
        }
        Ok(())
    }

    /// Is the value present? The command line itself is never read back —
    /// nothing needs it, and a value written by an older build would only
    /// invite a comparison that fails for no useful reason.
    pub fn is_set() -> bool {
        let Ok(key) = open(KEY_READ) else {
            return false;
        };
        let name = wide(VALUE_NAME);
        let mut size: u32 = 0;
        // SAFETY: a null data pointer with a live size pointer is the documented
        // way to ask how big the value is.
        let rc = unsafe {
            RegQueryValueExW(
                key,
                name.as_ptr(),
                std::ptr::null_mut(),
                std::ptr::null_mut(),
                std::ptr::null_mut(),
                &mut size,
            )
        };
        unsafe { RegCloseKey(key) };
        rc == ERROR_SUCCESS && size > 0
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_launch_agent_names_the_binary_and_starts_it_at_login() {
        // The daemon's place in the installed app bundle (sync/packaging/macos).
        let plist = launch_agent_plist(Path::new(
            "/Applications/Joinery Drive.app/Contents/MacOS/joinery-drive",
        ));
        assert!(plist.contains("<string>com.joinery.drive</string>"));
        assert!(plist.contains(
            "<string>/Applications/Joinery Drive.app/Contents/MacOS/joinery-drive</string>"
        ));
        assert!(plist.contains("<key>RunAtLoad</key>"));
        assert!(plist.contains("<string>daemon</string>"));
    }

    #[test]
    fn a_crash_brings_the_daemon_back_and_a_deliberate_stop_does_not() {
        // `KeepAlive: true` would make `joinery-drive pause` a fight with
        // launchd, which the user always loses.
        let plist = launch_agent_plist(Path::new("/usr/local/bin/joinery-drive"));
        assert!(plist.contains("SuccessfulExit"));
        assert!(!plist.contains("<key>KeepAlive</key>\n\t<true/>"));

        let unit = systemd_user_unit(Path::new("/usr/local/bin/joinery-drive"));
        assert!(unit.contains("Restart=on-failure"));
        assert!(!unit.contains("Restart=always"));
    }

    #[test]
    fn a_home_directory_with_an_ampersand_in_it_still_produces_a_valid_plist() {
        // launchd does not report a malformed plist to anybody. It just never
        // starts, and the user finds out when their files are a week stale.
        let plist = launch_agent_plist(Path::new("/Users/dewey & co/bin/joinery-drive"));
        assert!(plist.contains("/Users/dewey &amp; co/bin/joinery-drive"));
        assert!(
            !plist.contains("dewey & co"),
            "the raw ampersand must not survive into the XML"
        );
    }

    #[test]
    fn the_systemd_unit_targets_the_user_session_not_the_machine() {
        // `multi-user.target` is a system target; a user unit wanted by it never
        // starts, and `systemctl --user status` says everything is fine.
        let unit = systemd_user_unit(Path::new("/usr/local/bin/joinery-drive"));
        assert!(unit.contains("WantedBy=default.target"));
        assert!(!unit.contains("multi-user.target"));
        assert!(unit.contains("ExecStart=/usr/local/bin/joinery-drive daemon"));
    }

    #[test]
    fn enabling_is_repeatable_and_disabling_twice_is_not_an_error() {
        let home = std::env::temp_dir().join(format!("jd-autostart-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&home);
        std::fs::create_dir_all(&home).unwrap();
        let exe = Path::new("/usr/local/bin/joinery-drive");

        // Windows keeps this in the registry, which a test on a Linux box has no
        // business writing to.
        if autostart_path(&home).is_some() {
            enable(exe, &home).unwrap();
            enable(exe, &home).unwrap();
            assert!(is_enabled(&home));

            disable(&home).unwrap();
            assert!(!is_enabled(&home));
            disable(&home).unwrap();
        }
        let _ = std::fs::remove_dir_all(&home);
    }

    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    #[test]
    fn enabling_on_linux_makes_the_link_systemd_starts_at_login() {
        // systemd never reads `WantedBy=` at login. Without this link the unit
        // is a file nobody runs, and the daemon stays down after every reboot.
        let home = std::env::temp_dir().join(format!("jd-autostart-wants-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&home);
        std::fs::create_dir_all(&home).unwrap();
        let unit = home.join(".config/systemd/user/joinery-drive.service");
        let link = home.join(".config/systemd/user/default.target.wants/joinery-drive.service");

        enable(Path::new("/usr/bin/joinery-drive"), &home).unwrap();
        assert_eq!(std::fs::read_link(&link).unwrap(), unit);
        assert!(std::fs::read_to_string(&link)
            .unwrap()
            .contains("ExecStart=/usr/bin/joinery-drive daemon"));

        // Enabled from the packaged unit with `systemctl --user enable`: a link
        // and no unit file here. That starts at login, so it reads as on, and
        // turning it off has to remove that link too.
        std::fs::remove_file(&unit).unwrap();
        assert!(is_enabled(&home));
        disable(&home).unwrap();
        assert!(std::fs::symlink_metadata(&link).is_err());
        assert!(!is_enabled(&home));
        let _ = std::fs::remove_dir_all(&home);
    }

    #[test]
    fn at_windows_logon_the_windowed_tray_starts_the_daemon_when_it_is_installed() {
        // The daemon itself would be given a console window, and closing that
        // window stops sync. The tray is a windowed program.
        let exe = Path::new(r"C:\Users\a b\AppData\Local\Programs\Joinery Drive\joinery-drive.exe");
        let tray =
            Path::new(r"C:\Users\a b\AppData\Local\Programs\Joinery Drive\joinery-drive-tray.exe");
        assert_eq!(
            windows_run_command(exe, Some(tray)),
            format!("\"{}\" --start-daemon", tray.display())
        );
        // A daemon installed on its own still starts, and lets go of its
        // console itself.
        assert_eq!(
            windows_run_command(exe, None),
            format!("\"{}\" daemon", exe.display())
        );
    }

    #[test]
    fn the_tray_autostart_entry_survives_spaces_and_percent_signs_in_its_path() {
        // Unquoted, a space splits the path into two arguments and the session
        // runs a program that does not exist, silently, at every login.
        let entry = xdg_autostart_entry(Path::new("/usr/bin/joinery-drive-tray"));
        assert!(entry.starts_with("[Desktop Entry]\n"));
        assert!(entry.contains("\nExec=/usr/bin/joinery-drive-tray\n"));
        assert!(entry.contains("\nIcon=joinery-drive\n"));
        assert!(
            !entry.contains(START_DAEMON_FLAG),
            "systemd starts the daemon on Linux"
        );

        let entry = xdg_autostart_entry(Path::new(
            "/home/dewey/Joinery Drive 100%/bin/joinery-drive-tray",
        ));
        assert!(
            entry.contains("\nExec=\"/home/dewey/Joinery Drive 100%%/bin/joinery-drive-tray\"\n"),
            "{entry}"
        );
        let entry = xdg_autostart_entry(Path::new("/opt/a$b/joinery-drive-tray"));
        assert!(
            entry.contains(r#"Exec="/opt/a\\$b/joinery-drive-tray""#),
            "{entry}"
        );
    }

    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    #[test]
    fn on_linux_the_tray_starts_at_login_for_the_user_who_turned_it_on() {
        // Never for everybody on the machine: the package installs no
        // system-wide entry, and this one is written into one home only.
        let root = std::env::temp_dir().join(format!("jd-autostart-tray-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&root);
        let home = root.join("home");
        let bin = root.join("bin");
        std::fs::create_dir_all(&home).unwrap();
        std::fs::create_dir_all(&bin).unwrap();
        let exe = bin.join("joinery-drive");
        std::fs::write(&exe, "").unwrap();
        let entry = home.join(".config/autostart/joinery-drive-tray.desktop");

        // No tray installed beside the daemon (a server): nothing to start.
        enable(&exe, &home).unwrap();
        assert!(!entry.exists());

        std::fs::write(bin.join("joinery-drive-tray"), "").unwrap();
        enable(&exe, &home).unwrap();
        let text = std::fs::read_to_string(&entry).unwrap();
        assert!(text.contains(&format!(
            "Exec={}",
            bin.join("joinery-drive-tray").display()
        )));

        disable(&home).unwrap();
        assert!(!entry.exists());
        let _ = std::fs::remove_dir_all(&root);
    }

    #[test]
    fn the_menu_bar_app_opens_at_a_graphical_login_and_stays_quit_when_quit() {
        let plist = tray_launch_agent_plist(Path::new(
            "/Applications/Joinery Drive.app/Contents/MacOS/joinery-drive-tray",
        ));
        assert!(plist.contains("<string>com.joinery.drive.tray</string>"));
        assert!(plist.contains(
            "<string>/Applications/Joinery Drive.app/Contents/MacOS/joinery-drive-tray</string>"
        ));
        assert!(plist.contains("<key>RunAtLoad</key>\n\t<true/>"));
        // Not in an SSH session, where there is no menu bar to draw in.
        assert!(plist.contains("<key>LimitLoadToSessionType</key>\n\t<string>Aqua</string>"));
        assert!(plist.contains("<key>ProcessType</key>\n\t<string>Interactive</string>"));
        // Quitting the icon must not be undone by launchd seconds later.
        assert!(!plist.contains("KeepAlive"));
        let odd = tray_launch_agent_plist(Path::new("/Users/dewey & co/joinery-drive-tray"));
        assert!(odd.contains("/Users/dewey &amp; co/joinery-drive-tray"));
    }

    #[cfg(target_os = "macos")]
    #[test]
    fn on_macos_autostart_also_brings_back_the_menu_bar_app_from_the_same_bundle() {
        let root = std::env::temp_dir().join(format!("jd-autostart-mac-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&root);
        let home = root.join("home");
        let macos = root.join("Joinery Drive.app/Contents/MacOS");
        std::fs::create_dir_all(&home).unwrap();
        std::fs::create_dir_all(&macos).unwrap();
        let exe = macos.join("joinery-drive");
        std::fs::write(&exe, "").unwrap();
        let agents = home.join("Library/LaunchAgents");

        // A bare daemon, no app beside it: only the daemon's agent.
        enable(&exe, &home).unwrap();
        assert!(agents.join("com.joinery.drive.plist").exists());
        assert!(!agents.join("com.joinery.drive.tray.plist").exists());

        std::fs::write(macos.join("joinery-drive-tray"), "").unwrap();
        enable(&exe, &home).unwrap();
        let tray = std::fs::read_to_string(agents.join("com.joinery.drive.tray.plist")).unwrap();
        assert!(tray.contains(&format!(
            "<string>{}</string>",
            macos.join("joinery-drive-tray").display()
        )));

        disable(&home).unwrap();
        assert!(!agents.join("com.joinery.drive.plist").exists());
        assert!(!agents.join("com.joinery.drive.tray.plist").exists());
        let _ = std::fs::remove_dir_all(&root);
    }

    #[test]
    fn a_translocated_copy_of_the_app_is_never_registered() {
        // macOS runs a downloaded app from a randomized mount that is gone at
        // the next restart. A LaunchAgent pointing there never starts again.
        let translocated = Path::new(
            "/private/var/folders/x1/abc/T/AppTranslocation/6B1C-44/d/Joinery Drive.app/Contents/MacOS/joinery-drive",
        );
        let refused = check_registrable(translocated).unwrap_err().to_string();
        assert!(refused.contains("Applications"), "{refused}");

        let home =
            std::env::temp_dir().join(format!("jd-autostart-transloc-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&home);
        std::fs::create_dir_all(&home).unwrap();
        if autostart_path(&home).is_some() {
            assert!(enable(translocated, &home).is_err());
            assert!(!is_enabled(&home));
        }
        let _ = std::fs::remove_dir_all(&home);

        assert!(check_registrable(Path::new(
            "/Applications/Joinery Drive.app/Contents/MacOS/joinery-drive"
        ))
        .is_ok());
    }
}
