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

use jd_platform::{installation_service, Custody, Paths, Secret, SecretError, SecretStore};

/// A service name of our own, so a test never touches the real client's
/// credentials on a developer's machine.
const SERVICE: &str = "com.joinery.drive.tests";

/// Stands in for the name every installation shared before each had its own.
/// Never the real one: that may hold a real client's credentials.
const SHARED: &str = "com.joinery.drive.tests.shared";

/// Set in the child, to tell it which half of the test to be.
const CHILD_MARKER: &str = "JD_KEYCHAIN_CHILD";

/// The tests that use the real store take turns. On Windows, the Credential
/// Manager does not keep apart writes and deletes made at the same moment from
/// two threads of one process: run side by side, these tests now and then left
/// behind items their own tests had deleted, though every delete reported
/// success, and run one at a time they never did. The client never does that —
/// the sync thread reads its keys once at start, and linking and unlinking run
/// alone — so the tests do not either.
static REAL_STORE: std::sync::Mutex<()> = std::sync::Mutex::new(());

fn take_turn() -> std::sync::MutexGuard<'static, ()> {
    REAL_STORE.lock().unwrap_or_else(|e| e.into_inner())
}

fn scratch() -> std::path::PathBuf {
    let dir = std::env::temp_dir().join("jd-keychain-test");
    let _ = std::fs::create_dir_all(&dir);
    dir
}

#[test]
fn custody_is_whichever_store_this_session_can_actually_open() {
    let _turn = take_turn();
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
    assert!(!store.describe(store.custody()).is_empty());
}

/// Whether this session's credential store is open, said where a test that
/// needs one would otherwise pass for having checked nothing.
fn open_store_or_say_why(store: &SecretStore, test: &str) -> bool {
    if store.custody() == Custody::OsKeychain {
        return true;
    }
    println!(
        "{test}: this session cannot use the credential store (locked: {}), so there is nothing \
         of it to check here; run it from a desktop session",
        store.keychain_locked()
    );
    false
}

fn unique_dir(tag: &str) -> std::path::PathBuf {
    let dir = scratch().join(format!("{tag}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&dir);
    std::fs::create_dir_all(&dir).unwrap();
    dir
}

#[test]
fn two_installations_on_one_computer_do_not_share_credentials() {
    let _turn = take_turn();
    // The store keys an item by its service name, and every installation under
    // one OS account shares the store: a second drive linked here must not
    // overwrite the first's keys.
    let personal_state = unique_dir("personal");
    let work_state = unique_dir("work");
    let personal = SecretStore::open_migrating(
        &installation_service(&personal_state),
        SHARED,
        &personal_state,
        &[],
    );
    let work =
        SecretStore::open_migrating(&installation_service(&work_state), SHARED, &work_state, &[]);
    if !open_store_or_say_why(&personal, "two_installations") {
        return;
    }
    personal
        .set(Secret::VaultKey, "personal-vault-probe")
        .unwrap();
    work.set(Secret::VaultKey, "work-vault-probe").unwrap();
    let read_personal = SecretStore::open_migrating(
        &installation_service(&personal_state),
        SHARED,
        &personal_state,
        &[],
    )
    .get(Secret::VaultKey);
    let read_work = work.get(Secret::VaultKey);
    let _ = personal.clear();
    let _ = work.clear();
    assert_eq!(read_personal.unwrap(), "personal-vault-probe");
    assert_eq!(read_work.unwrap(), "work-vault-probe");
    assert!(
        std::fs::read_dir(&personal_state).unwrap().next().is_none(),
        "the keychain held them, not a file"
    );
}

#[test]
fn credentials_under_the_shared_name_move_to_the_installations_own() {
    let _turn = take_turn();
    let state = unique_dir("migrate");
    let shared = SecretStore::open(SHARED, &state);
    if !open_store_or_say_why(&shared, "migration") {
        return;
    }
    shared.set(Secret::ApiSecret, "shared-api-probe").unwrap();
    shared.set(Secret::VaultKey, "shared-vault-probe").unwrap();
    // An installation an earlier client linked has its state store.
    let linked_here = [state.join("state.db")];
    std::fs::write(&linked_here[0], b"").unwrap();

    let own =
        SecretStore::open_migrating(&installation_service(&state), SHARED, &state, &linked_here);
    let read = own.get_held(Secret::ApiSecret);
    let left_shared = SecretStore::open(SHARED, &state).get(Secret::VaultKey);
    let moved_vault = own.get(Secret::VaultKey);
    let _ = own.clear();
    let _ = shared.clear();
    let after_clear =
        SecretStore::open_migrating(&installation_service(&state), SHARED, &state, &linked_here)
            .get(Secret::VaultKey);

    assert_eq!(
        read.unwrap(),
        ("shared-api-probe".to_string(), Custody::OsKeychain)
    );
    assert!(
        matches!(left_shared, Err(SecretError::NotFound(_))),
        "{left_shared:?}"
    );
    assert_eq!(moved_vault.unwrap(), "shared-vault-probe");
    assert!(
        matches!(after_clear, Err(SecretError::NotFound(_))),
        "{after_clear:?}"
    );
}

#[test]
fn a_session_that_cannot_open_the_store_keeps_no_vault_key_anywhere() {
    let _turn = take_turn();
    // The other half, and the one a gate run over SSH can check on a Mac: the
    // login keychain is there and locked. Q1: the vault key is refused, not
    // written to a file.
    let state = unique_dir("locked");
    let store = SecretStore::for_installation(&Paths {
        config: state.join("config"),
        state: state.clone(),
        logs: state.join("logs"),
    });
    if !store.keychain_locked() {
        println!("locked_session: the credential store is open (or absent) in this session; nothing to check");
        return;
    }
    assert!(!store.can_keep(Secret::VaultKey));
    assert!(matches!(
        store.set(Secret::VaultKey, "vk"),
        Err(SecretError::Locked(_))
    ));
    assert!(!state.join("vault-key.secret").exists());
    store.set(Secret::ApiSecret, "sk").unwrap();
    assert_eq!(
        store.get_held(Secret::ApiSecret).unwrap(),
        ("sk".to_string(), Custody::File)
    );
    store.clear().unwrap();
    assert!(std::fs::read_dir(&state).unwrap().next().is_none());
}

#[test]
fn a_secret_survives_the_process_that_wrote_it() {
    // The child is this same test binary, re-run with a marker. A second
    // process is the entire point: an in-process map passes every check that
    // stays inside one.
    if std::env::var(CHILD_MARKER).is_ok() {
        return;
    }
    let _turn = take_turn();

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
