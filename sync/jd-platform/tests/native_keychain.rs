//! The system credential store, where there is one.
//!
//! The failure this exists to catch is specific and quiet. With no platform
//! backend compiled in, the `keyring` crate hands back a working `Entry` backed
//! by an in-process map: it accepts a secret and gives it back, so every check
//! inside one process passes, and everything is gone the moment that process
//! exits. A client built that way links successfully and then cannot start,
//! reporting only that its credential is missing.
//!
//! Checking inside one process therefore proves nothing. The only test that
//! means anything is whether a *different* process can read what this one wrote,
//! so that is what this does: it re-runs its own binary as a child and has the
//! child do the reading.

#![cfg(any(target_os = "macos", target_os = "windows", feature = "secret-service"))]

use jd_platform::{Custody, Secret, SecretStore};

/// A service name of our own, so a test never touches the real client's
/// credentials on a developer's machine.
const SERVICE: &str = "com.joinery.drive.tests";

/// Set in the child, to tell it which half of the test to be.
const CHILD_MARKER: &str = "JD_KEYCHAIN_CHILD";

fn scratch() -> std::path::PathBuf {
    let dir = std::env::temp_dir().join("jd-keychain-test");
    let _ = std::fs::create_dir_all(&dir);
    dir
}

#[test]
fn custody_is_whichever_store_this_session_can_actually_open() {
    // Deliberately not "it must be the Keychain". A login keychain is locked in
    // a session nobody has logged in to — over SSH, or at boot — and no prompt
    // can be shown to unlock it. Falling back to a file there is correct, and
    // this gate runs over SSH, so asserting Keychain would be asserting
    // something the product does not promise.
    //
    // What must hold is that the answer is one of the two and is reported, since
    // an unreported downgrade is the thing that would actually mislead somebody.
    let store = SecretStore::open(SERVICE, &scratch());
    assert!(matches!(
        store.custody(),
        Custody::OsKeychain | Custody::File
    ));
    assert!(!store.custody().describe().is_empty());
}

#[test]
fn a_secret_survives_the_process_that_wrote_it() {
    // The child is this same test binary, re-run with a marker. A second
    // process is the entire point: an in-process map passes every check that
    // stays inside one.
    if std::env::var(CHILD_MARKER).is_ok() {
        return;
    }

    let store = SecretStore::open(SERVICE, &scratch());
    let secret = "not-a-real-secret-just-a-round-trip-probe";
    store.set(Secret::ApiSecret, secret).unwrap();

    let exe = std::env::current_exe().expect("a test binary knows its own path");
    let output = std::process::Command::new(exe)
        .env(CHILD_MARKER, "read")
        .arg("--exact")
        .arg("reads_what_the_parent_wrote")
        .arg("--nocapture")
        .arg("--ignored")
        .output()
        .expect("re-running this test binary");

    // Tidy up before asserting, so a failure does not leave a credential behind
    // in a developer's keychain.
    let _ = store.clear();

    let stdout = String::from_utf8_lossy(&output.stdout);
    assert!(
        stdout.contains("child read the secret back"),
        "another process could not read what this one stored — the credential \
         store is not persisting anything.\nstdout: {stdout}\nstderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );
}

/// The child half. Ignored so it never runs on its own; the parent names it
/// explicitly.
#[test]
#[ignore]
fn reads_what_the_parent_wrote() {
    let store = SecretStore::open(SERVICE, &scratch());
    let value = store
        .get(Secret::ApiSecret)
        .expect("the credential store forgot a secret written by another process");
    assert_eq!(value, "not-a-real-secret-just-a-round-trip-probe");
    println!("child read the secret back");
}
