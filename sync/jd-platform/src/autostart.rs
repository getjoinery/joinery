//! Starting the daemon when the user logs in.
//!
//! A sync client that only runs while somebody remembers to run it is not a sync
//! client. Each platform has exactly one right way to do this for a *user*
//! agent — not a system service, which would run as the wrong account and be
//! unable to reach the user's keychain or their home directory:
//!
//! - **macOS** — a LaunchAgent plist in `~/Library/LaunchAgents`.
//! - **Windows** — a value under the current user's `Run` key.
//! - **Linux** — a systemd user unit, which is also what makes `systemctl
//!   --user status joinery-drive` answer the question "why did it stop".
//!
//! The generated artifacts are produced by pure functions, so the exact plist a
//! Mac will load and the exact unit a Linux box will load are both inspectable
//! from a test on any machine. That matters more than it sounds: a plist with a
//! typo does not fail loudly, it simply never starts, and the user finds out
//! when their files are a week stale.

use std::path::{Path, PathBuf};

use crate::dirs::BUNDLE_ID;

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

/// Register the daemon to start at login. Safe to repeat.
pub fn enable(exe: &Path, home: &Path) -> AutostartResult<()> {
    #[cfg(target_os = "windows")]
    {
        let _ = home;
        return windows_run_key::set(&format!("\"{}\" daemon", exe.display()));
    }
    #[allow(unreachable_code)]
    {
        let Some(path) = autostart_path(home) else {
            return Ok(());
        };
        if let Some(parent) = path.parent() {
            std::fs::create_dir_all(parent).map_err(|e| AutostartError::Io {
                path: parent.to_path_buf(),
                source: e,
            })?;
        }
        let body = if cfg!(target_os = "macos") {
            launch_agent_plist(exe)
        } else {
            systemd_user_unit(exe)
        };
        std::fs::write(&path, body).map_err(|e| AutostartError::Io {
            path: path.clone(),
            source: e,
        })
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
        let Some(path) = autostart_path(home) else {
            return Ok(());
        };
        match std::fs::remove_file(&path) {
            Ok(()) => Ok(()),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(e) => Err(AutostartError::Io { path, source: e }),
        }
    }
}

pub fn is_enabled(home: &Path) -> bool {
    #[cfg(target_os = "windows")]
    {
        let _ = home;
        return windows_run_key::is_set();
    }
    #[allow(unreachable_code)]
    {
        autostart_path(home).map(|p| p.exists()).unwrap_or(false)
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
        let plist = launch_agent_plist(Path::new("/Applications/Joinery Drive.app/joinery-drive"));
        assert!(plist.contains("<string>com.joinery.drive</string>"));
        assert!(plist.contains("/Applications/Joinery Drive.app/joinery-drive"));
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
}
