//! Opening the user's browser.
//!
//! Needed for exactly one thing, and it is the important one: the device-link
//! ceremony happens in a signed-in browser, because that is where WebAuthn works
//! and where the vault can be unlocked. The client's job is to get the user
//! there.
//!
//! It also has to be able to *fail gracefully*. A headless server has no browser
//! and never will, and the ceremony works perfectly well with the user reading
//! the URL off one screen and typing it into another. So this returns whether it
//! managed to open anything, and the caller prints the URL either way.

use std::process::Command;

/// Ask the desktop to open `url`, returning whether anything took it.
///
/// The URL is passed as a separate argument to a program, never interpolated
/// into a shell command line — a URL is attacker-influenced text (it comes back
/// from a server) and `sh -c "open $url"` is a remote shell. No shell is
/// involved on any platform; see [`launchers`].
pub fn open_url(url: &str) -> bool {
    if !is_safe_url(url) {
        return false;
    }
    for (program, args) in launchers(Desktop::current(), url) {
        let mut cmd = Command::new(program);
        cmd.args(&args);
        // The browser outlives this process; its output belongs to nobody.
        cmd.stdout(std::process::Stdio::null());
        cmd.stderr(std::process::Stdio::null());
        if let Ok(mut child) = cmd.spawn() {
            // Not waited on: `xdg-open` may block for as long as the browser
            // runs, and blocking here would hang the login command.
            let _ = child.try_wait();
            return true;
        }
    }
    false
}

/// Which desktop's way of opening a URL to use.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Desktop {
    MacOs,
    Windows,
    /// Linux and the other Unixes: the freedesktop launchers, and WSL's.
    Other,
}

impl Desktop {
    pub const fn current() -> Desktop {
        #[cfg(target_os = "macos")]
        {
            Desktop::MacOs
        }
        #[cfg(target_os = "windows")]
        {
            Desktop::Windows
        }
        #[cfg(not(any(target_os = "macos", target_os = "windows")))]
        {
            Desktop::Other
        }
    }
}

/// The programs that can open `url` on `desktop`, in the order to try them,
/// each with its whole argument list. The URL is always one argument of its
/// own, exactly as given.
///
/// A pure function, so what every platform would run is checked on every
/// platform. That matters most for Windows, where the obvious way — `cmd /C
/// start "" <url>` — hands the URL to cmd's parser: `&` ends the command and
/// starts another, and `%` expands variables. A settings link whose fragment
/// is `#t=<ticket>&s=issues` opened the page without its section and then tried
/// to run a program called `s=issues`; a server's approval address with `&` in
/// its query lost everything after it. `rundll32 url.dll,FileProtocolHandler`
/// opens the default browser through `CreateProcess`, with no shell to parse
/// anything.
pub fn launchers(desktop: Desktop, url: &str) -> Vec<(&'static str, Vec<String>)> {
    let with = |prefix: &[&str]| -> Vec<String> {
        prefix
            .iter()
            .map(|a| a.to_string())
            .chain(std::iter::once(url.to_string()))
            .collect()
    };
    match desktop {
        Desktop::MacOs => vec![("/usr/bin/open", with(&[]))],
        Desktop::Windows => vec![("rundll32.exe", with(&["url.dll,FileProtocolHandler"]))],
        Desktop::Other => vec![
            ("xdg-open", with(&[])),
            ("gio", with(&["open"])),
            ("wslview", with(&[])),
        ],
    }
}

/// Open `url` for a person to look at, returning whether that can have happened.
///
/// [`open_url`] answers whether a program started, not whether a page
/// appeared: on a box with no display `xdg-open` starts, finds nothing to show
/// a page on, and quietly gives up. So this asks first whether there is a
/// desktop at all. Callers use the answer to decide between "opened in your
/// browser" and telling the person to open the address themselves, and the
/// first must never be said when the second was the truth.
pub fn show_url(url: &str) -> bool {
    has_desktop() && open_url(url)
}

/// Only `http` and `https` are ever opened.
///
/// The URL comes back from a server, and handing an arbitrary scheme to the
/// desktop's "open this" machinery is handing it a way to run a program:
/// `file://`, `smb://`, and half a dozen registered handlers all do something
/// more interesting than show a page.
pub fn is_safe_url(url: &str) -> bool {
    let lower = url.to_ascii_lowercase();
    (lower.starts_with("https://") || lower.starts_with("http://"))
        // A newline would let a crafted URL become a second argument, or a
        // second line in anything that logs it. Whitespace and quotes are not
        // in any real URL (they are sent %-encoded), and refusing them keeps
        // the URL one bare argument on Windows, where the standard library
        // quotes an argument that has either.
        && !url
            .chars()
            .any(|c| c.is_control() || c.is_whitespace() || c == '"')
}

/// Whether this machine plausibly has a desktop to open anything on. Used to
/// decide whether to print "we opened your browser" or "open this on another
/// device".
pub fn has_desktop() -> bool {
    #[cfg(target_os = "macos")]
    {
        std::path::Path::new("/usr/bin/open").exists()
    }
    #[cfg(target_os = "windows")]
    {
        true
    }
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    {
        // Set but empty is how a shell says "no display" as often as unset is.
        let set = |name: &str| std::env::var(name).is_ok_and(|v| !v.is_empty());
        set("DISPLAY") || set("WAYLAND_DISPLAY")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_web_pages_are_ever_opened() {
        // The URL comes back from a server. Handing an arbitrary scheme to the
        // desktop's open machinery is handing it a way to run a program.
        assert!(is_safe_url(
            "https://dev.getjoinery.com/profile/devices/link"
        ));
        assert!(is_safe_url("http://localhost:8080/x"));
        assert!(!is_safe_url("file:///etc/passwd"));
        assert!(!is_safe_url("smb://server/share"));
        assert!(!is_safe_url("javascript:alert(1)"));
        assert!(!is_safe_url("/profile/devices/link"));
        assert!(!is_safe_url(""));
    }

    #[test]
    fn a_url_carrying_a_newline_is_refused() {
        assert!(!is_safe_url("https://example.com/\nrm -rf"));
        assert!(!is_safe_url("https://example.com/\r\nSet-Cookie: x"));
    }

    #[test]
    fn a_url_with_spaces_or_quotes_is_refused() {
        assert!(!is_safe_url("https://example.com/a b"));
        assert!(!is_safe_url("https://example.com/a\tb"));
        assert!(!is_safe_url("https://example.com/\"&calc"));
    }

    #[test]
    fn every_platform_opens_a_url_as_one_untouched_argument_without_a_shell() {
        // The settings link carries `&s=issues` in its fragment, and a server's
        // approval address may carry `&` and `%` in its query. cmd read the
        // first as "run another command" and the second as a variable.
        let url = "http://127.0.0.1:4000/settings#t=ab12&s=issues&x=%PATH%,y";
        for desktop in [Desktop::MacOs, Desktop::Windows, Desktop::Other] {
            let tries = launchers(desktop, url);
            assert!(!tries.is_empty(), "{desktop:?}");
            for (program, args) in &tries {
                let name = program
                    .rsplit(['/', '\\'])
                    .next()
                    .unwrap()
                    .to_ascii_lowercase();
                assert!(
                    !matches!(
                        name.as_str(),
                        "cmd" | "cmd.exe" | "sh" | "bash" | "powershell" | "powershell.exe"
                    ),
                    "{desktop:?} goes through a shell: {program}"
                );
                assert_eq!(
                    args.iter().filter(|a| a.as_str() == url).count(),
                    1,
                    "{desktop:?} {args:?}"
                );
                assert_eq!(
                    args.last().map(String::as_str),
                    Some(url),
                    "the URL is its own last argument"
                );
            }
        }
        assert_eq!(
            launchers(Desktop::Windows, url),
            vec![(
                "rundll32.exe",
                vec!["url.dll,FileProtocolHandler".to_string(), url.to_string()]
            )]
        );
        assert_eq!(launchers(Desktop::MacOs, url)[0].0, "/usr/bin/open");
    }

    #[test]
    fn the_scheme_check_is_not_fooled_by_capitals() {
        assert!(is_safe_url("HTTPS://example.com/x"));
        assert!(!is_safe_url("FILE:///etc/passwd"));
    }

    /// The tests that set `DISPLAY` take turns: `cargo test` runs them on
    /// parallel threads in one process, and one setting it while another reads
    /// it is a test that passes alone and fails under load.
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    static DISPLAY_ENV: std::sync::Mutex<()> = std::sync::Mutex::new(());

    #[test]
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    fn with_no_display_nothing_claims_to_have_opened() {
        // The login command used to print "(opened in your browser)" here,
        // because xdg-open started and then had nowhere to show anything.
        let _guard = DISPLAY_ENV.lock().unwrap_or_else(|e| e.into_inner());
        let saved: Vec<_> = ["DISPLAY", "WAYLAND_DISPLAY"]
            .iter()
            .map(|k| (*k, std::env::var_os(k)))
            .collect();
        std::env::remove_var("DISPLAY");
        std::env::remove_var("WAYLAND_DISPLAY");
        let shown = show_url("https://drive.example.org/profile/devices/link");
        for (k, v) in saved {
            if let Some(v) = v {
                std::env::set_var(k, v);
            }
        }
        assert!(!shown);
    }

    #[test]
    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    fn an_empty_display_is_no_display() {
        // `DISPLAY=` is how a service unit or a shell says "none". Counting it
        // as a desktop had the settings page tell a user on a headless box that
        // the approval page had opened, when nothing could have.
        let _guard = DISPLAY_ENV.lock().unwrap_or_else(|e| e.into_inner());
        let saved: Vec<_> = ["DISPLAY", "WAYLAND_DISPLAY"]
            .iter()
            .map(|k| (*k, std::env::var_os(k)))
            .collect();
        std::env::set_var("DISPLAY", "");
        std::env::set_var("WAYLAND_DISPLAY", "");
        let empty = has_desktop();
        std::env::set_var("DISPLAY", ":0");
        let set = has_desktop();
        for (k, v) in saved {
            match v {
                Some(v) => std::env::set_var(k, v),
                None => std::env::remove_var(k),
            }
        }
        assert!(!empty);
        assert!(set);
    }

    #[test]
    fn refusing_to_open_is_reported_rather_than_pretended() {
        // A headless box has no browser and never will; the ceremony still works
        // with the user reading the URL and typing it somewhere else. What must
        // not happen is the client saying it opened something it did not.
        assert!(!open_url("file:///etc/passwd"));
    }
}
