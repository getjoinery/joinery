//! The console window Windows gives a console program whether it wants one or
//! not.
//!
//! `joinery-drive` is a console program, because it is also the command line.
//! Run from a terminal it shares that terminal's console, which is right: the
//! person can read what it says and stop it with Ctrl+C. Started any other way —
//! double-clicked, or by a logon entry that names the daemon itself — Windows
//! makes a console window for it alone, and closing that window stops sync with
//! no warning. So a daemon lets go of a console nothing else is attached to: the
//! window goes, the process stays.
//!
//! Logon itself does not depend on this. The `Run` value starts the windowed
//! tray, which starts the daemon with no console window at all
//! (`autostart::windows_run_command`); this covers every other way in.

/// Release this process's console if no other process is attached to it, and
/// say whether it did. Elsewhere than Windows there is nothing to release.
pub fn release_own_console() -> bool {
    #[cfg(windows)]
    {
        use windows_sys::Win32::System::Console::{FreeConsole, GetConsoleProcessList};
        let mut attached = [0u32; 2];
        // SAFETY: the buffer is live and its length is the one passed. When it
        // is too small the call returns the count without writing past it.
        let count = unsafe { GetConsoleProcessList(attached.as_mut_ptr(), attached.len() as u32) };
        // 0: no console at all. 2 or more: a terminal, or whatever started this
        // process, is sharing it, and it is theirs to close.
        if count == 1 {
            // SAFETY: no arguments; detaches this process from its console.
            return unsafe { FreeConsole() } != 0;
        }
        false
    }
    #[cfg(not(windows))]
    {
        false
    }
}

#[cfg(all(test, windows))]
mod tests {
    use super::*;
    use std::os::windows::process::CommandExt;

    /// Set in the copy of this test binary that runs with a console of its own.
    const OWN_CONSOLE_CHILD: &str = "JD_CONSOLE_TEST_OWN_CONSOLE";

    #[test]
    fn a_console_shared_with_whatever_started_the_program_is_kept() {
        if std::env::var_os(OWN_CONSOLE_CHILD).is_some() {
            return;
        }
        // The test runner shares the console cargo was started in.
        assert!(!release_own_console());
    }

    #[test]
    fn a_console_made_for_this_process_alone_is_released() {
        use windows_sys::Win32::System::Console::GetConsoleWindow;
        if std::env::var_os(OWN_CONSOLE_CHILD).is_some() {
            // The child: started with a console of its own, the way Windows
            // starts a console program from a logon entry or a double-click.
            let released = release_own_console();
            // SAFETY: no arguments; reads this process's console window, if any.
            let window_gone = unsafe { GetConsoleWindow() }.is_null();
            std::process::exit(if released && window_gone { 0 } else { 3 });
        }
        const CREATE_NEW_CONSOLE: u32 = 0x0000_0010;
        let status = std::process::Command::new(std::env::current_exe().unwrap())
            .args([
                "--exact",
                "console::tests::a_console_made_for_this_process_alone_is_released",
            ])
            .env(OWN_CONSOLE_CHILD, "1")
            .creation_flags(CREATE_NEW_CONSOLE)
            .status()
            .unwrap();
        assert!(status.success(), "the child kept its console: {status}");
    }
}
