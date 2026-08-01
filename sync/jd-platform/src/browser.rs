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
/// from a server) and `sh -c "open $url"` is a remote shell.
pub fn open_url(url: &str) -> bool {
    if !is_safe_url(url) {
        return false;
    }

    #[cfg(target_os = "macos")]
    let attempts: &[(&str, &[&str])] = &[("/usr/bin/open", &[])];

    #[cfg(target_os = "windows")]
    // `start` is a shell builtin, so it needs cmd — and `,` and `&` in a URL are
    // cmd metacharacters. The empty string is `start`'s window-title argument,
    // without which cmd treats a quoted URL as the title and opens nothing.
    let attempts: &[(&str, &[&str])] = &[("cmd", &["/C", "start", ""])];

    #[cfg(not(any(target_os = "macos", target_os = "windows")))]
    let attempts: &[(&str, &[&str])] = &[("xdg-open", &[]), ("gio", &["open"]), ("wslview", &[])];

    for (program, prefix) in attempts {
        let mut cmd = Command::new(program);
        cmd.args(prefix.iter()).arg(url);
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
        // second line in anything that logs it.
        && !url.contains(['\n', '\r', '\0'])
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
        std::env::var("DISPLAY").is_ok() || std::env::var("WAYLAND_DISPLAY").is_ok()
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
    fn the_scheme_check_is_not_fooled_by_capitals() {
        assert!(is_safe_url("HTTPS://example.com/x"));
        assert!(!is_safe_url("FILE:///etc/passwd"));
    }

    #[test]
    fn refusing_to_open_is_reported_rather_than_pretended() {
        // A headless box has no browser and never will; the ceremony still works
        // with the user reading the URL and typing it somewhere else. What must
        // not happen is the client saying it opened something it did not.
        assert!(!open_url("file:///etc/passwd"));
    }
}
