//! Where a device's credentials live.
//!
//! The daemon holds three things it must never write in the clear: the API
//! secret that authenticates it as this device, the device's own X25519 secret
//! key, and — once encrypted folders are in play — the Drive vault key handed
//! over during linking. All three have the same property: whoever has them can
//! read the user's files, and none of them can be re-derived from anything on
//! disk.
//!
//! # What is promised, per platform
//!
//! **macOS and Windows** put them in the system credential store: the login
//! Keychain and the Credential Manager. Both are unlocked with the user's OS
//! account and encrypted at rest with a key the operating system holds. A stolen
//! disk image, a backup, or another user on the same machine gets nothing.
//!
//! **Linux** gets a file at mode 0600 in the state directory, and this is
//! deliberate rather than an omission. The Secret Service is not available on a
//! headless server — which the daemon explicitly supports — and requiring it
//! would mean the client cannot run on the machines most likely to want it.
//! Desktop Linux users who do have a keyring can build with the
//! `secret-service` feature and get the same treatment as everyone else.
//!
//! # What the file fallback actually costs, stated plainly
//!
//! The custody class becomes exactly that of `~/.ssh/id_ed25519`: safe against
//! another user on the box and against anything that reads the file without
//! permission, worthless against someone who already has the user's account.
//! There is no honest way to do better with a file — encrypting it with a key
//! stored beside it protects against nobody — so the client does not pretend
//! otherwise. It reports which custody it got, and the status command says so.

use std::path::{Path, PathBuf};

#[derive(Debug, thiserror::Error)]
pub enum SecretError {
    #[error("no credential stored for {0}")]
    NotFound(String),
    /// There is a credential store on this machine and it will not open.
    ///
    /// Told apart from `NotFound` because the two need opposite advice and the
    /// difference is invisible from the outside. The case that produces it: the
    /// user links from their desktop, the secret goes into the login keychain,
    /// and the daemon later starts somewhere that keychain is locked — over SSH,
    /// or at boot before anyone has logged in. Reported as "missing", the user
    /// is told their credential is gone when it is sitting right there.
    #[error("{0}")]
    Locked(String),
    #[error("the credential store refused: {0}")]
    Store(String),
    #[error("io error on {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
}

pub type SecretResult<T> = Result<T, SecretError>;

/// How well the secrets on this machine are actually protected.
///
/// Reported rather than assumed, because the answer depends on the machine and
/// not on the build: a keychain can be locked, absent, or refuse. A client that
/// silently degraded from one to the other would be telling the user their keys
/// are in the Keychain when they are in a file.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Custody {
    /// The operating system's credential store.
    OsKeychain,
    /// A mode-0600 file in the state directory.
    File,
}

impl Custody {
    /// One sentence a person can act on, for `status` and the settings page.
    pub fn describe(&self) -> &'static str {
        match self {
            Custody::OsKeychain => "in this computer's credential store",
            Custody::File => "in a file only your account can read (no system keyring available)",
        }
    }
}

/// The credentials this device holds. One name per thing, so revoking one does
/// not disturb the others.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Secret {
    /// The API secret half of the session key minted at link time.
    ApiSecret,
    /// This device's X25519 secret key — what the sealed vault key was sealed to.
    DeviceKey,
    /// The Drive vault secret key, once the user has enabled encrypted folders
    /// on this device.
    VaultKey,
}

impl Secret {
    fn account(&self) -> &'static str {
        match self {
            Secret::ApiSecret => "api-secret",
            Secret::DeviceKey => "device-key",
            Secret::VaultKey => "vault-key",
        }
    }
}

/// The credential store for one sync root.
pub struct SecretStore {
    /// Distinguishes two instances on one machine — a personal account and a
    /// work one are different credentials with the same names.
    service: String,
    fallback_dir: PathBuf,
    custody: Custody,
    /// This machine has a credential store, and it refused because it is locked
    /// rather than because it is absent. Remembered so that a later "not found"
    /// can say which of the two it is.
    keychain_locked: bool,
}

/// Was a real credential store compiled into this build?
///
/// This has to be a compile-time question and not a runtime probe, and the
/// reason is a trap worth naming. With no platform backend compiled in, the
/// `keyring` crate still hands back a working `Entry` — backed by an in-process
/// map. It accepts a secret and gives it back, so every runtime probe passes,
/// and every secret is gone the moment the process exits. A client built that
/// way appears to link successfully and cannot start afterwards, reporting only
/// that its credential is missing.
const HAS_NATIVE_KEYCHAIN: bool = cfg!(any(
    target_os = "macos",
    target_os = "windows",
    feature = "secret-service"
));

impl SecretStore {
    /// Open the best store this machine offers.
    ///
    /// Where a real backend exists it is probed by *using* it, not by asking
    /// whether it exists: a present-but-locked keychain answers the existence
    /// question yes and the write question no, and the second answer is the one
    /// that matters.
    pub fn open(service: &str, fallback_dir: &Path) -> SecretStore {
        let mut store = SecretStore {
            service: service.to_string(),
            fallback_dir: fallback_dir.to_path_buf(),
            custody: Custody::OsKeychain,
            keychain_locked: false,
        };
        if !HAS_NATIVE_KEYCHAIN {
            store.custody = Custody::File;
            return store;
        }
        match store.probe_keychain() {
            Probe::Works => {}
            Probe::Locked => {
                store.custody = Custody::File;
                store.keychain_locked = true;
            }
            Probe::Absent => store.custody = Custody::File,
        }
        store
    }

    /// Open a store that never touches the system keychain. For tests, and for
    /// a user who would rather not have the daemon in their keyring.
    pub fn file_only(service: &str, fallback_dir: &Path) -> SecretStore {
        SecretStore {
            service: service.to_string(),
            fallback_dir: fallback_dir.to_path_buf(),
            keychain_locked: false,
            custody: Custody::File,
        }
    }

    pub fn custody(&self) -> Custody {
        self.custody
    }

    pub fn get(&self, secret: Secret) -> SecretResult<String> {
        match self.custody {
            Custody::OsKeychain => {
                self.entry(secret.account())?
                    .get_password()
                    .map_err(|e| match e {
                        keyring::Error::NoEntry => SecretError::NotFound(secret.account().into()),
                        other => SecretError::Store(other.to_string()),
                    })
            }
            Custody::File => {
                let path = self.file_for(secret);
                match std::fs::read_to_string(&path) {
                    Ok(s) => Ok(s.trim_end_matches('\n').to_string()),
                    Err(e) if e.kind() == std::io::ErrorKind::NotFound => {
                        // Nothing in the file — but if this machine has a
                        // credential store that would not open, the credential
                        // is most likely in there. Saying "missing" would send
                        // the user to re-link something that is not lost.
                        if self.keychain_locked {
                            Err(SecretError::Locked(
                                "this computer's credential store is locked, so the \
                                 sync credential cannot be read. Start Joinery Drive \
                                 from your desktop session after signing in — a daemon \
                                 started over SSH or before you log in cannot open it."
                                    .into(),
                            ))
                        } else {
                            Err(SecretError::NotFound(secret.account().into()))
                        }
                    }
                    Err(e) => Err(SecretError::Io { path, source: e }),
                }
            }
        }
    }

    pub fn set(&self, secret: Secret, value: &str) -> SecretResult<()> {
        match self.custody {
            Custody::OsKeychain => self
                .entry(secret.account())?
                .set_password(value)
                .map_err(|e| SecretError::Store(e.to_string())),
            Custody::File => {
                std::fs::create_dir_all(&self.fallback_dir).map_err(|e| SecretError::Io {
                    path: self.fallback_dir.clone(),
                    source: e,
                })?;
                let path = self.file_for(secret);
                write_private(&path, value)
            }
        }
    }

    /// Remove a secret. Absent is success — unlinking a device that never
    /// finished linking must not fail on the half that was never written.
    pub fn delete(&self, secret: Secret) -> SecretResult<()> {
        match self.custody {
            Custody::OsKeychain => match self.entry(secret.account())?.delete_credential() {
                Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
                Err(e) => Err(SecretError::Store(e.to_string())),
            },
            Custody::File => {
                let path = self.file_for(secret);
                match std::fs::remove_file(&path) {
                    Ok(()) => Ok(()),
                    Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
                    Err(e) => Err(SecretError::Io { path, source: e }),
                }
            }
        }
    }

    /// Remove everything this device holds. The unlink path, and the one that
    /// has to be thorough: a leftover API secret is a device that can still
    /// read the user's Drive after they told it not to.
    pub fn clear(&self) -> SecretResult<()> {
        for secret in [Secret::ApiSecret, Secret::DeviceKey, Secret::VaultKey] {
            self.delete(secret)?;
        }
        Ok(())
    }

    fn entry(&self, account: &str) -> SecretResult<keyring::Entry> {
        keyring::Entry::new(&self.service, account).map_err(|e| SecretError::Store(e.to_string()))
    }

    fn file_for(&self, secret: Secret) -> PathBuf {
        self.fallback_dir
            .join(format!("{}.secret", secret.account()))
    }

    /// Can this machine's keychain actually hold something and give it back?
    ///
    /// Probed by *using* it, not by asking whether it exists: a present-but-locked
    /// keychain answers the existence question yes and the write question no, and
    /// the second answer is the one that matters.
    fn probe_keychain(&self) -> Probe {
        const PROBE: &str = "startup-probe";
        let Ok(entry) = keyring::Entry::new(&self.service, PROBE) else {
            return Probe::Absent;
        };
        match entry.set_password("probe") {
            Ok(()) => {
                let ok = entry.get_password().map(|v| v == "probe").unwrap_or(false);
                let _ = entry.delete_credential();
                if ok {
                    Probe::Works
                } else {
                    Probe::Absent
                }
            }
            Err(e) => {
                if is_locked(&e.to_string()) {
                    Probe::Locked
                } else {
                    Probe::Absent
                }
            }
        }
    }
}

/// What asking the credential store to hold something told us.
enum Probe {
    /// It took the secret and gave it back.
    Works,
    /// It is there and will not open. Almost always: no one is logged in to the
    /// desktop, so the login keychain is locked and no prompt can be shown.
    Locked,
    /// There is nothing here to use.
    Absent,
}

/// Does this refusal mean "locked" rather than "not available"?
///
/// Matched on the message because that is what the platforms give us — macOS
/// says the item needs user interaction that is not allowed, Windows and the
/// Secret Service say the store is locked. A message this does not recognize
/// falls through to `Absent`, which is the safe direction: the client uses a
/// file, works, and reports which custody it got.
fn is_locked(message: &str) -> bool {
    let m = message.to_ascii_lowercase();
    m.contains("user interaction is not allowed")
        || m.contains("interaction is not allowed")
        || m.contains("locked")
        || m.contains("-25308")
}

/// Write a file only the owner can read, and make it so *before* the secret is
/// in it.
///
/// Creating the file and then relaxing into a chmod leaves a window — however
/// short — in which the secret sits on disk world-readable. Anything watching
/// the directory wins that race, and it costs nothing to close.
fn write_private(path: &Path, value: &str) -> SecretResult<()> {
    use std::io::Write;

    let mut options = std::fs::OpenOptions::new();
    options.write(true).create(true).truncate(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }
    let mut file = options.open(path).map_err(|e| SecretError::Io {
        path: path.to_path_buf(),
        source: e,
    })?;
    file.write_all(value.as_bytes())
        .and_then(|()| file.sync_all())
        .map_err(|e| SecretError::Io {
            path: path.to_path_buf(),
            source: e,
        })?;

    // A file that already existed keeps the mode it was created with, so set it
    // again rather than trusting the create flag to have applied.
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let _ = std::fs::set_permissions(path, std::fs::Permissions::from_mode(0o600));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    struct TempDir(PathBuf);
    impl TempDir {
        fn new(tag: &str) -> TempDir {
            let p = std::env::temp_dir().join(format!(
                "jd-secret-{}-{}-{:?}",
                tag,
                std::process::id(),
                std::thread::current().id()
            ));
            let _ = std::fs::remove_dir_all(&p);
            std::fs::create_dir_all(&p).unwrap();
            TempDir(p)
        }
    }
    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    #[test]
    fn a_secret_comes_back_exactly_as_it_went_in() {
        let d = TempDir::new("roundtrip");
        let s = SecretStore::file_only("joinery-drive-test", &d.0);
        s.set(Secret::ApiSecret, "sk-not-a-real-secret").unwrap();
        assert_eq!(s.get(Secret::ApiSecret).unwrap(), "sk-not-a-real-secret");
    }

    #[test]
    fn the_three_secrets_do_not_collide() {
        let d = TempDir::new("distinct");
        let s = SecretStore::file_only("joinery-drive-test", &d.0);
        s.set(Secret::ApiSecret, "one").unwrap();
        s.set(Secret::DeviceKey, "two").unwrap();
        s.set(Secret::VaultKey, "three").unwrap();
        assert_eq!(s.get(Secret::ApiSecret).unwrap(), "one");
        assert_eq!(s.get(Secret::DeviceKey).unwrap(), "two");
        assert_eq!(s.get(Secret::VaultKey).unwrap(), "three");
    }

    #[test]
    fn asking_for_something_never_stored_says_so_rather_than_returning_nothing() {
        // An empty string would sail through as a credential and fail later as
        // an authentication error nobody can explain.
        let d = TempDir::new("missing");
        let s = SecretStore::file_only("joinery-drive-test", &d.0);
        assert!(matches!(
            s.get(Secret::VaultKey),
            Err(SecretError::NotFound(_))
        ));
    }

    #[test]
    fn a_secret_file_is_readable_only_by_its_owner() {
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let d = TempDir::new("mode");
            let s = SecretStore::file_only("joinery-drive-test", &d.0);
            s.set(Secret::ApiSecret, "sk-not-a-real-secret").unwrap();
            let mode = std::fs::metadata(d.0.join("api-secret.secret"))
                .unwrap()
                .permissions()
                .mode()
                & 0o777;
            assert_eq!(mode, 0o600, "group and other must not be able to read it");
        }
    }

    #[test]
    fn overwriting_a_secret_does_not_relax_its_permissions() {
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let d = TempDir::new("rewrite");
            let s = SecretStore::file_only("joinery-drive-test", &d.0);
            let path = d.0.join("api-secret.secret");
            std::fs::write(&path, "old").unwrap();
            std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o644)).unwrap();

            s.set(Secret::ApiSecret, "new").unwrap();
            let mode = std::fs::metadata(&path).unwrap().permissions().mode() & 0o777;
            assert_eq!(mode, 0o600, "a pre-existing file keeps its old mode");
        }
    }

    #[test]
    fn deleting_something_that_was_never_there_succeeds() {
        // The unlink path must not fail on the half of a ceremony that never
        // completed.
        let d = TempDir::new("delete-absent");
        let s = SecretStore::file_only("joinery-drive-test", &d.0);
        assert!(s.delete(Secret::VaultKey).is_ok());
        assert!(s.clear().is_ok());
    }

    #[test]
    fn unlinking_leaves_nothing_behind() {
        // A leftover API secret is a device that can still read the user's Drive
        // after they revoked it.
        let d = TempDir::new("clear");
        let s = SecretStore::file_only("joinery-drive-test", &d.0);
        s.set(Secret::ApiSecret, "one").unwrap();
        s.set(Secret::DeviceKey, "two").unwrap();
        s.set(Secret::VaultKey, "three").unwrap();

        s.clear().unwrap();

        for secret in [Secret::ApiSecret, Secret::DeviceKey, Secret::VaultKey] {
            assert!(matches!(s.get(secret), Err(SecretError::NotFound(_))));
        }
        assert_eq!(
            std::fs::read_dir(&d.0).unwrap().count(),
            0,
            "not even an empty file naming what used to be there"
        );
    }

    #[test]
    fn two_instances_on_one_machine_hold_separate_credentials() {
        // A personal account and a work account are the same three names and
        // different secrets.
        let d = TempDir::new("two");
        let personal = SecretStore::file_only("joinery-drive-test", &d.0.join("personal"));
        let work = SecretStore::file_only("joinery-drive-test", &d.0.join("work"));
        personal.set(Secret::ApiSecret, "personal").unwrap();
        work.set(Secret::ApiSecret, "work").unwrap();
        assert_eq!(personal.get(Secret::ApiSecret).unwrap(), "personal");
        assert_eq!(work.get(Secret::ApiSecret).unwrap(), "work");
    }

    #[test]
    fn a_build_with_no_real_keychain_never_claims_to_have_one() {
        // The trap this guards: with no backend compiled in, `keyring` hands
        // back a working in-process map. Every runtime probe passes, and every
        // secret vanishes when the process exits — so linking appears to work
        // and the daemon then cannot start, reporting only a missing credential.
        let d = TempDir::new("nokeychain");
        let store = SecretStore::open("joinery-drive-test", &d.0);
        if !HAS_NATIVE_KEYCHAIN {
            assert_eq!(store.custody(), Custody::File);
        }

        // And whatever it chose, a secret written through `open` has to still be
        // there for a second `open` — which is the property the mock lacks.
        store
            .set(Secret::ApiSecret, "sk-not-a-real-secret")
            .unwrap();
        let reopened = SecretStore::open("joinery-drive-test", &d.0);
        assert_eq!(
            reopened.get(Secret::ApiSecret).unwrap(),
            "sk-not-a-real-secret"
        );
        let _ = reopened.clear();
    }

    #[test]
    fn a_locked_store_is_told_apart_from_a_missing_credential() {
        // The two need opposite advice. "Missing" sends the user to re-link
        // something that is not lost; "locked" tells them to start the client
        // from their desktop, which is the actual fix.
        let d = TempDir::new("locked");
        let mut store = SecretStore::file_only("joinery-drive-test", &d.0);
        store.keychain_locked = true;

        let err = store.get(Secret::ApiSecret).unwrap_err();
        assert!(matches!(err, SecretError::Locked(_)));
        let message = err.to_string();
        assert!(message.contains("locked"));
        assert!(
            message.contains("desktop"),
            "a blocker has to say what to do about it: {message}"
        );
    }

    #[test]
    fn a_machine_with_no_store_at_all_still_reports_a_plain_missing_credential() {
        let d = TempDir::new("plainmissing");
        let store = SecretStore::file_only("joinery-drive-test", &d.0);
        assert!(matches!(
            store.get(Secret::VaultKey),
            Err(SecretError::NotFound(_))
        ));
    }

    #[test]
    fn a_locked_store_does_not_hide_a_credential_that_is_actually_there() {
        // The file is the custody in use; a locked keychain is only an
        // explanation for its absence, never a reason to ignore it.
        let d = TempDir::new("lockedbutpresent");
        let mut store = SecretStore::file_only("joinery-drive-test", &d.0);
        store
            .set(Secret::ApiSecret, "sk-not-a-real-secret")
            .unwrap();
        store.keychain_locked = true;
        assert_eq!(
            store.get(Secret::ApiSecret).unwrap(),
            "sk-not-a-real-secret"
        );
    }

    #[test]
    fn the_refusals_that_mean_locked_are_recognized_and_others_are_not() {
        // What each platform actually says when nobody is logged in.
        assert!(is_locked(
            "SecKeychainItemCreateFromContent (<default>): User interaction is not allowed."
        ));
        assert!(is_locked("The collection is locked"));
        assert!(is_locked("error -25308"));
        // Not locked: genuinely absent, which must fall through to a file.
        assert!(!is_locked("No such file or directory"));
        assert!(!is_locked("platform secure storage failure: no backend"));
    }

    #[test]
    fn custody_is_reported_honestly_rather_than_assumed() {
        let d = TempDir::new("custody");
        let s = SecretStore::file_only("joinery-drive-test", &d.0);
        assert_eq!(s.custody(), Custody::File);
        assert!(s.custody().describe().contains("file"));
    }
}
