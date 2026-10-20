//! `joinery-drive stop`, run as the real binary against a real daemon.
//!
//! A script that stops the daemon and then touches its folder or its state
//! (an unlink, a reset, an uninstaller) must find it gone, not still finishing
//! a pass. Nothing here reaches a network: the daemon is not linked, so it
//! serves first-run setup on this machine and syncs nothing.

use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::time::{Duration, Instant};

use jd_platform::instance::{holder, Holder};

fn temp(tag: &str) -> PathBuf {
    let dir = std::env::temp_dir().join(format!("jd-stop-cli-{tag}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&dir);
    std::fs::create_dir_all(&dir).unwrap();
    dir
}

fn joinery_drive(home: &Path, args: &[&str]) -> Command {
    let mut command = Command::new(env!("CARGO_BIN_EXE_joinery-drive"));
    command
        .args(args)
        .env("JOINERY_DRIVE_HOME", home)
        .env_remove("DISPLAY")
        .env_remove("WAYLAND_DISPLAY")
        // Not under a supervisor, whatever the test runner is under.
        .env_remove("INVOCATION_ID")
        .env_remove("SYSTEMD_EXEC_PID")
        .env_remove("XPC_SERVICE_NAME");
    command
}

/// Wait for a process, failing the test rather than hanging it.
fn wait_for(mut child: Child, what: &str) {
    let deadline = Instant::now() + Duration::from_secs(60);
    while child.try_wait().unwrap().is_none() {
        if Instant::now() > deadline {
            let _ = child.kill();
            let _ = child.wait();
            panic!("{what} was still running after a minute");
        }
        std::thread::sleep(Duration::from_millis(50));
    }
}

#[test]
fn stop_returns_once_the_daemon_is_gone() {
    let home = temp("gone");
    let state = home.join("state");
    let daemon = joinery_drive(&home, &["daemon"])
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
        .unwrap();
    let deadline = Instant::now() + Duration::from_secs(30);
    while !state.join("control.json").exists() && Instant::now() < deadline {
        std::thread::sleep(Duration::from_millis(50));
    }
    assert_eq!(holder(&state), Holder::Running, "the daemon did not come up");

    let out = joinery_drive(&home, &["stop"]).output().unwrap();
    let said = String::from_utf8_lossy(&out.stdout).to_string();
    // Read before anything else: the daemon had let go of the home by the time
    // stop came back.
    let after = holder(&state);
    wait_for(daemon, "the daemon after stop");
    assert!(out.status.success(), "stop failed: {said}{}", String::from_utf8_lossy(&out.stderr));
    assert_eq!(after, Holder::Gone, "stop came back while the daemon still held its home");
    assert!(said.contains("Stopped."), "{said}");
    let _ = std::fs::remove_dir_all(&home);
}
