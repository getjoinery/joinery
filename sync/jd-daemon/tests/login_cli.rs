//! `joinery-drive login`, run as the real binary.
//!
//! The address check is the last thing between a typed address and a request
//! that, once approved, carries this computer's credential. So it is tested
//! where a person meets it — the command line — and not only as a function.
//! Nothing here reaches a network: a refused address sends nothing, and the
//! one accepted address is a port on this machine that nothing listens on.

use std::process::Command;

/// Run `login <url>` with its own state directory and no display, and return
/// whether it succeeded and what it said on stderr.
fn login(tag: &str, url: &str) -> (bool, String, std::path::PathBuf) {
    let home = std::env::temp_dir().join(format!("jd-login-cli-{tag}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&home);
    let out = Command::new(env!("CARGO_BIN_EXE_joinery-drive"))
        .args(["login", url])
        .env("JOINERY_DRIVE_HOME", &home)
        .env_remove("DISPLAY")
        .env_remove("WAYLAND_DISPLAY")
        .output()
        .expect("the binary runs");
    (
        out.status.success(),
        String::from_utf8_lossy(&out.stderr).to_string(),
        home,
    )
}

#[test]
fn a_plain_http_site_is_refused_and_the_person_is_told_why() {
    let (ok, said, home) = login("http", "http://drive.example.org");
    assert!(!ok);
    assert!(said.contains("https://"), "{said}");
    assert!(
        said.contains("unencrypted"),
        "says why, in plain words: {said}"
    );
    // Refused before anything was set up, let alone sent.
    assert!(!home.exists(), "a refused address left state behind");
}

#[test]
fn plain_http_to_this_machine_is_allowed_through() {
    // There is no network between a process and its own loopback. Port 1 needs
    // privileges to bind, so nothing is there: the request is made and fails,
    // which is exactly what shows the address check let it through.
    let (ok, said, home) = login("loopback", "http://127.0.0.1:1");
    assert!(!ok);
    assert!(!said.contains("unencrypted"), "{said}");
    assert!(said.contains("device link"), "{said}");
    let _ = std::fs::remove_dir_all(&home);
}
