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
//!
//! # A credential store that is there and locked
//!
//! A Mac reached over SSH has a login Keychain this session cannot open. The
//! API secret and the device key go to a file there, as on a machine with no
//! store at all, so plain folders link and sync. The vault key does not: on a
//! computer that has a credential store, the key to the user's encrypted
//! folders is kept in it or not kept, and the person is told to link from
//! their desktop session to have them. A machine with no store at all keeps it
//! in a file like the rest, because there is nowhere else for it to be.
//!
//! # One installation, one set of names
//!
//! Two installations under one OS account — a personal drive and a work one,
//! each with its own `JOINERY_DRIVE_HOME` — hold the same three kinds of
//! secret and must never read each other's. The credential store keys an item
//! by a service name and an account name, so each installation's service name
//! is derived from its state directory (`installation_service`). Clients
//! before that kept every installation's items under one fixed name
//! (`LEGACY_SERVICE`). An installation an earlier client linked — it has a
//! config or a state store, and no key under its own name — looks there, and
//! moves what it finds to its own name: that is its link from before. A new
//! installation, or one linked since, leaves the old name alone: what is there
//! belongs to another installation, or to nobody.

use std::path::{Path, PathBuf};

use crate::dirs::Paths;

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

/// What a session that cannot open the credential store is told when it would
/// have been handed the key to encrypted folders.
pub const VAULT_NEEDS_DESKTOP: &str = "this computer's credential store is locked in this \
     session, and the key to encrypted folders is kept there or not at all. Plain folders \
     sync as usual; run joinery-drive login from your desktop session to enable encrypted \
     folders.";

/// Where a secret is.
///
/// Reported rather than assumed, because the answer depends on the machine and
/// on the session a secret was written in, not on the build: a keychain can be
/// locked, absent, or refuse. A client that silently degraded from one to the
/// other would be telling the user their keys are in the Keychain when they
/// are in a file.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Custody {
    /// The operating system's credential store.
    OsKeychain,
    /// A mode-0600 file in the state directory.
    File,
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
    const ALL: [Secret; 3] = [Secret::ApiSecret, Secret::DeviceKey, Secret::VaultKey];

    fn account(&self) -> &'static str {
        match self {
            Secret::ApiSecret => "api-secret",
            Secret::DeviceKey => "device-key",
            Secret::VaultKey => "vault-key",
        }
    }
}

/// The service name every installation on a computer kept its secrets under
/// before each had its own, and the start of each installation's own name.
/// Fixed for good: it is what those clients wrote, and it is not the bundle id,
/// so a rename of that does not rename this.
pub const LEGACY_SERVICE: &str = "com.joinery.drive";

/// The service name the installation whose state lives in `state` keeps its
/// secrets under: `com.joinery.drive.` and a hash of where the directory
/// really is.
///
/// Where it *really* is: a state directory reached through a symlink is the
/// same installation and must find the same secrets. The hash is FNV-1a,
/// written out here rather than taken from the standard library, whose hasher
/// is free to change between Rust releases — and a name that changed with the
/// compiler would strand every credential. It tells installations apart and
/// protects nothing; it does not need to.
pub fn installation_service(state: &Path) -> String {
    let real = as_it_resolves(state);
    format!(
        "{}.{:016x}",
        LEGACY_SERVICE,
        fnv1a_64(real.as_os_str().as_encoded_bytes())
    )
}

fn fnv1a_64(bytes: &[u8]) -> u64 {
    let mut hash: u64 = 0xcbf2_9ce4_8422_2325;
    for byte in bytes {
        hash ^= u64::from(*byte);
        hash = hash.wrapping_mul(0x0000_0100_0000_01b3);
    }
    hash
}

/// The path with its nearest existing ancestor resolved through any symlinks,
/// and the part that does not exist yet put back on the end — so the answer is
/// the same before and after the directory is made, which a name derived from
/// it has to be.
fn as_it_resolves(path: &Path) -> PathBuf {
    let mut existing = path.to_path_buf();
    let mut rest = Vec::new();
    loop {
        if let Ok(real) = std::fs::canonicalize(&existing) {
            let mut out = real;
            for part in rest.iter().rev() {
                out.push(part);
            }
            return out;
        }
        match (existing.parent(), existing.file_name()) {
            (Some(parent), Some(name)) => {
                rest.push(name.to_os_string());
                existing = parent.to_path_buf();
            }
            _ => return path.to_path_buf(),
        }
    }
}

/// The name every installation once shared, and what on disk says this one
/// was linked under it.
struct Legacy {
    service: String,
    /// What an installation that was ever linked has: its config, its state
    /// store. A new installation has neither, so whatever is under the old name
    /// is not its own.
    linked_here: Vec<PathBuf>,
}

/// What asking the credential store to hold something told us.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Probe {
    /// It took the secret and gave it back.
    Works,
    /// It is there and will not open. Almost always: no one is logged in to the
    /// desktop session this process runs in, so the login keychain is locked
    /// and no prompt can be shown.
    Locked,
    /// There is nothing here to use.
    Absent,
}

/// The credential store for one installation.
pub struct SecretStore {
    /// This installation's own name in the credential store.
    service: String,
    /// A name this installation's secrets may still be under, from a client
    /// that shared one name between installations. Read once and emptied.
    legacy: Option<Legacy>,
    fallback_dir: PathBuf,
    keychain: Keychain,
    /// What the credential store did when this process asked it to hold
    /// something. `Locked` is remembered so that a later "not found" can say
    /// which of the two it is, and so the vault key is never put in a file on a
    /// computer that has a store for it.
    probe: Probe,
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
    /// The credential store of the installation laid out at `paths`. What the
    /// daemon, `login`, `unlink` and the soak rig all open, so they cannot
    /// disagree about where one installation's secrets are.
    pub fn for_installation(paths: &Paths) -> SecretStore {
        SecretStore::open_migrating(
            &installation_service(&paths.state),
            LEGACY_SERVICE,
            &paths.state,
            &[paths.config_file(), paths.state_db()],
        )
    }

    /// Open the store under `service`, taking over what an earlier client left
    /// under `legacy` the first time something is not found under `service` —
    /// if this installation was linked before (one of `linked_here` exists)
    /// and holds no key of its own.
    pub fn open_migrating(
        service: &str,
        legacy: &str,
        fallback_dir: &Path,
        linked_here: &[PathBuf],
    ) -> SecretStore {
        SecretStore::probed(
            Keychain::Os,
            service,
            Some(Legacy {
                service: legacy.to_string(),
                linked_here: linked_here.to_vec(),
            }),
            fallback_dir,
            HAS_NATIVE_KEYCHAIN,
        )
    }

    /// Open the best store this machine offers, under one name and no other.
    ///
    /// Where a real backend exists it is probed by *using* it, not by asking
    /// whether it exists: a present-but-locked keychain answers the existence
    /// question yes and the write question no, and the second answer is the one
    /// that matters.
    pub fn open(service: &str, fallback_dir: &Path) -> SecretStore {
        SecretStore::probed(
            Keychain::Os,
            service,
            None,
            fallback_dir,
            HAS_NATIVE_KEYCHAIN,
        )
    }

    /// Open a store that never touches the system keychain. For tests, and for
    /// a user who would rather not have the daemon in their keyring.
    pub fn file_only(service: &str, fallback_dir: &Path) -> SecretStore {
        SecretStore::probed(Keychain::Os, service, None, fallback_dir, false)
    }

    /// A store backed by an in-memory stand-in for the credential store, which
    /// a test can lock and unlock. Never in a shipped build: it is exactly the
    /// forgets-everything-at-exit store `HAS_NATIVE_KEYCHAIN` exists to refuse.
    /// `legacy` and `linked_here` are as for `open_migrating`.
    #[cfg(any(test, feature = "stand-in-keychain"))]
    pub fn with_stand_in(
        keychain: &StandInKeychain,
        service: &str,
        legacy: Option<&str>,
        fallback_dir: &Path,
        linked_here: &[PathBuf],
    ) -> SecretStore {
        SecretStore::probed(
            Keychain::StandIn(keychain.clone()),
            service,
            legacy.map(|legacy| Legacy {
                service: legacy.to_string(),
                linked_here: linked_here.to_vec(),
            }),
            fallback_dir,
            true,
        )
    }

    fn probed(
        keychain: Keychain,
        service: &str,
        legacy: Option<Legacy>,
        fallback_dir: &Path,
        compiled_in: bool,
    ) -> SecretStore {
        let mut store = SecretStore {
            service: service.to_string(),
            legacy,
            fallback_dir: fallback_dir.to_path_buf(),
            keychain,
            probe: Probe::Absent,
        };
        if compiled_in {
            store.probe = store.probe_keychain();
        }
        store
    }

    /// Where a secret written now goes.
    pub fn custody(&self) -> Custody {
        match self.probe {
            Probe::Works => Custody::OsKeychain,
            Probe::Locked | Probe::Absent => Custody::File,
        }
    }

    /// One sentence a person can act on, for `status` and the settings page,
    /// about secrets kept in `custody`.
    pub fn describe(&self, custody: Custody) -> &'static str {
        match (custody, self.probe) {
            (Custody::OsKeychain, _) => "in this computer's credential store",
            (Custody::File, Probe::Absent) => {
                "in a file only your account can read (no system keyring available)"
            }
            (Custody::File, _) => {
                "in a file only your account can read (this computer's credential store was \
                 locked when it was linked)"
            }
        }
    }

    /// This computer has a credential store, and this session cannot open it.
    pub fn keychain_locked(&self) -> bool {
        self.probe == Probe::Locked
    }

    /// Whether this store will keep `secret` in this session. Everything but
    /// the vault key, always; the vault key everywhere except a session that
    /// cannot open a credential store the computer has.
    pub fn can_keep(&self, secret: Secret) -> bool {
        !(secret == Secret::VaultKey && self.probe == Probe::Locked)
    }

    pub fn get(&self, secret: Secret) -> SecretResult<String> {
        self.get_held(secret).map(|(value, _)| value)
    }

    /// A secret, and where it was found.
    ///
    /// Looked for wherever it could have been written: the credential store,
    /// when this session can open it, then the file — a secret written while
    /// the store was locked lives there, and is just as much this device's —
    /// then under the name an earlier client shared between installations.
    pub fn get_held(&self, secret: Secret) -> SecretResult<(String, Custody)> {
        if self.probe == Probe::Works {
            match self.keychain.read(&self.service, secret.account()) {
                Ok(value) => return Ok((value, Custody::OsKeychain)),
                Err(Fault::Missing) => {}
                Err(Fault::Refused(why)) => return Err(SecretError::Store(why)),
            }
        }
        let path = self.file_for(secret);
        match std::fs::read_to_string(&path) {
            Ok(s) => return Ok((s.trim_end_matches('\n').to_string(), Custody::File)),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
            Err(e) => return Err(SecretError::Io { path, source: e }),
        }
        if self.probe == Probe::Works && self.take_over_legacy()? {
            if let Ok(value) = self.keychain.read(&self.service, secret.account()) {
                return Ok((value, Custody::OsKeychain));
            }
        }
        if self.probe == Probe::Locked {
            // Nothing in the file — but this machine has a credential store
            // that would not open, and the credential is most likely in there.
            // Saying "missing" would send the user to re-link something that is
            // not lost.
            Err(SecretError::Locked(
                "this computer's credential store is locked, so the sync credential cannot be \
                 read. Start Joinery Drive from your desktop session after signing in — a daemon \
                 started over SSH or before you log in cannot open it."
                    .into(),
            ))
        } else {
            Err(SecretError::NotFound(secret.account().into()))
        }
    }

    /// Keep a secret.
    ///
    /// In the credential store when this session can open it, and then any
    /// copy in a file goes: one copy of each secret, where it was last written,
    /// so a file left by a link made while the store was locked cannot outlive
    /// the key it holds. In a file otherwise — except the vault key, which a
    /// locked store refuses rather than letting it land on disk (see the module
    /// documentation).
    pub fn set(&self, secret: Secret, value: &str) -> SecretResult<()> {
        match self.probe {
            Probe::Works => {
                self.keychain
                    .write(&self.service, secret.account(), value)
                    .map_err(|f| SecretError::Store(f.to_string()))?;
                remove_if_present(&self.file_for(secret))
            }
            Probe::Locked if secret == Secret::VaultKey => {
                Err(SecretError::Locked(VAULT_NEEDS_DESKTOP.into()))
            }
            Probe::Locked | Probe::Absent => {
                std::fs::create_dir_all(&self.fallback_dir).map_err(|e| SecretError::Io {
                    path: self.fallback_dir.clone(),
                    source: e,
                })?;
                write_private(&self.file_for(secret), value)
            }
        }
    }

    /// Remove a secret, from everywhere this installation keeps it: the file,
    /// and its item in the credential store. Absent is success — unlinking a
    /// device that never finished linking must not fail on the half that was
    /// never written.
    ///
    /// Not the old shared name: what is there is another installation's. This
    /// installation's own link from before per-installation names was moved
    /// off it the first time it was read (`get`), which unlinking does first.
    ///
    /// A store this session cannot open is not reached. Callers that must not
    /// leave a key behind check `keychain_locked` first.
    pub fn delete(&self, secret: Secret) -> SecretResult<()> {
        remove_if_present(&self.file_for(secret))?;
        if self.probe == Probe::Works {
            match self.keychain.remove(&self.service, secret.account()) {
                Ok(()) | Err(Fault::Missing) => {}
                Err(Fault::Refused(why)) => return Err(SecretError::Store(why)),
            }
        }
        Ok(())
    }

    /// Remove everything this device holds. The unlink path, and the one that
    /// has to be thorough: a leftover API secret is a device that can still
    /// read the user's Drive after they told it not to, and a leftover vault key
    /// is the key to their encrypted folders sitting on a computer that is no
    /// longer theirs to sync.
    pub fn clear(&self) -> SecretResult<()> {
        for secret in Secret::ALL {
            self.delete(secret)?;
        }
        Ok(())
    }

    /// Move what an earlier client left under the shared name to this
    /// installation's own: all three secrets at once, so nothing is left
    /// behind there for the next installation to read as its own. Returns
    /// whether anything was moved.
    ///
    /// Only for an installation an earlier client linked: one with a config
    /// or a state store on disk (`Legacy::linked_here`), and no API secret of
    /// its own. What is under the shared name is then its link from before.
    /// A new installation has neither file; one linked since has its own key.
    /// For both, the shared items are another installation's, waiting for that
    /// one to read them — taken here, that one would stop working, and this
    /// one would be opening encrypted folders with a key nobody handed it.
    ///
    /// A secret already under this installation's own name stays as it is.
    /// The shared item goes only once it is safely under the new name.
    fn take_over_legacy(&self) -> SecretResult<bool> {
        let Some(legacy) = &self.legacy else {
            return Ok(false);
        };
        if !legacy.linked_here.iter().any(|p| p.exists()) || self.holds_own_link()? {
            return Ok(false);
        }
        let legacy = &legacy.service;
        let mut found = false;
        for secret in Secret::ALL {
            let value = match self.keychain.read(legacy, secret.account()) {
                Ok(value) => value,
                Err(Fault::Missing) => continue,
                Err(Fault::Refused(why)) => return Err(SecretError::Store(why)),
            };
            found = true;
            match self.keychain.read(&self.service, secret.account()) {
                Ok(_) => {}
                Err(Fault::Missing) => self
                    .keychain
                    .write(&self.service, secret.account(), &value)
                    .map_err(|f| SecretError::Store(f.to_string()))?,
                Err(Fault::Refused(why)) => return Err(SecretError::Store(why)),
            }
            match self.keychain.remove(legacy, secret.account()) {
                Ok(()) | Err(Fault::Missing) => {}
                Err(Fault::Refused(why)) => return Err(SecretError::Store(why)),
            }
        }
        Ok(found)
    }

    /// Whether this installation holds an API secret of its own, under its
    /// own name or in its file: whether this client has linked it.
    fn holds_own_link(&self) -> SecretResult<bool> {
        if self.file_for(Secret::ApiSecret).exists() {
            return Ok(true);
        }
        match self
            .keychain
            .read(&self.service, Secret::ApiSecret.account())
        {
            Ok(_) => Ok(true),
            Err(Fault::Missing) => Ok(false),
            Err(Fault::Refused(why)) => Err(SecretError::Store(why)),
        }
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
        match self.keychain.write(&self.service, PROBE, "probe") {
            Ok(()) => {
                let ok = self
                    .keychain
                    .read(&self.service, PROBE)
                    .map(|v| v == "probe")
                    .unwrap_or(false);
                let _ = self.keychain.remove(&self.service, PROBE);
                if ok {
                    Probe::Works
                } else {
                    Probe::Absent
                }
            }
            Err(Fault::Refused(why)) if is_locked(&why) => Probe::Locked,
            Err(_) => Probe::Absent,
        }
    }
}

/// The credential store itself, behind the three things this file asks of it.
enum Keychain {
    /// The operating system's, through the `keyring` crate.
    Os,
    #[cfg(any(test, feature = "stand-in-keychain"))]
    StandIn(StandInKeychain),
}

/// Why the credential store did not do what it was asked.
#[derive(Debug)]
enum Fault {
    /// Nothing is kept under that name.
    Missing,
    /// It refused, in its own words.
    Refused(String),
}

impl std::fmt::Display for Fault {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Fault::Missing => write!(f, "no such item"),
            Fault::Refused(why) => write!(f, "{why}"),
        }
    }
}

impl Keychain {
    fn read(&self, service: &str, account: &str) -> Result<String, Fault> {
        match self {
            Keychain::Os => os_entry(service, account)?.get_password().map_err(os_fault),
            #[cfg(any(test, feature = "stand-in-keychain"))]
            Keychain::StandIn(k) => k.read(service, account),
        }
    }

    fn write(&self, service: &str, account: &str, value: &str) -> Result<(), Fault> {
        match self {
            Keychain::Os => os_entry(service, account)?
                .set_password(value)
                .map_err(os_fault),
            #[cfg(any(test, feature = "stand-in-keychain"))]
            Keychain::StandIn(k) => k.write(service, account, value),
        }
    }

    fn remove(&self, service: &str, account: &str) -> Result<(), Fault> {
        match self {
            Keychain::Os => os_entry(service, account)?
                .delete_credential()
                .map_err(os_fault),
            #[cfg(any(test, feature = "stand-in-keychain"))]
            Keychain::StandIn(k) => k.remove(service, account),
        }
    }
}

fn os_entry(service: &str, account: &str) -> Result<keyring::Entry, Fault> {
    keyring::Entry::new(service, account).map_err(|e| Fault::Refused(e.to_string()))
}

fn os_fault(e: keyring::Error) -> Fault {
    match e {
        keyring::Error::NoEntry => Fault::Missing,
        other => Fault::Refused(other.to_string()),
    }
}

/// An in-memory credential store a test can lock: what the store logic is
/// tested against on every platform, Linux included, where the real one is a
/// build feature. Clones share their contents, as two processes share the
/// system's.
#[cfg(any(test, feature = "stand-in-keychain"))]
#[derive(Clone, Default)]
pub struct StandInKeychain {
    inner: std::sync::Arc<std::sync::Mutex<StandIn>>,
}

#[cfg(any(test, feature = "stand-in-keychain"))]
#[derive(Default)]
struct StandIn {
    items: std::collections::HashMap<(String, String), String>,
    locked: bool,
}

#[cfg(any(test, feature = "stand-in-keychain"))]
impl StandInKeychain {
    pub fn new() -> StandInKeychain {
        StandInKeychain::default()
    }

    /// Lock or unlock it, as a session without a desktop finds the login
    /// keychain: every request refused in the words macOS uses.
    pub fn set_locked(&self, locked: bool) {
        self.inner.lock().unwrap().locked = locked;
    }

    /// Put an item there directly, as an earlier client would have.
    pub fn put(&self, service: &str, account: &str, value: &str) {
        self.inner
            .lock()
            .unwrap()
            .items
            .insert((service.into(), account.into()), value.into());
    }

    /// What is kept under a name, looked at directly.
    pub fn peek(&self, service: &str, account: &str) -> Option<String> {
        self.inner
            .lock()
            .unwrap()
            .items
            .get(&(service.into(), account.into()))
            .cloned()
    }

    /// How many items it holds in all.
    pub fn len(&self) -> usize {
        self.inner.lock().unwrap().items.len()
    }

    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    fn refuse_if_locked(inner: &StandIn) -> Result<(), Fault> {
        if inner.locked {
            Err(Fault::Refused(
                "SecKeychainItemCreateFromContent (<default>): User interaction is not allowed."
                    .into(),
            ))
        } else {
            Ok(())
        }
    }

    fn read(&self, service: &str, account: &str) -> Result<String, Fault> {
        let inner = self.inner.lock().unwrap();
        Self::refuse_if_locked(&inner)?;
        inner
            .items
            .get(&(service.into(), account.into()))
            .cloned()
            .ok_or(Fault::Missing)
    }

    fn write(&self, service: &str, account: &str, value: &str) -> Result<(), Fault> {
        let mut inner = self.inner.lock().unwrap();
        Self::refuse_if_locked(&inner)?;
        inner
            .items
            .insert((service.into(), account.into()), value.into());
        Ok(())
    }

    fn remove(&self, service: &str, account: &str) -> Result<(), Fault> {
        let mut inner = self.inner.lock().unwrap();
        Self::refuse_if_locked(&inner)?;
        inner
            .items
            .remove(&(service.into(), account.into()))
            .map(|_| ())
            .ok_or(Fault::Missing)
    }
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

fn remove_if_present(path: &Path) -> SecretResult<()> {
    match std::fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(SecretError::Io {
            path: path.to_path_buf(),
            source: e,
        }),
    }
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

    /// The names a stand-in store is opened under in these tests.
    const OWN: &str = "com.joinery.drive.0123456789abcdef";

    /// A store for the installation whose state is `dir`, under the name
    /// `OWN`, with `dir/state.db` as what says it was linked before.
    fn stand_in_store(keychain: &StandInKeychain, dir: &Path) -> SecretStore {
        SecretStore::with_stand_in(
            keychain,
            OWN,
            Some(LEGACY_SERVICE),
            dir,
            &[dir.join("state.db")],
        )
    }

    /// Leave what an installation that was linked has on disk.
    fn linked_before(dir: &Path) {
        std::fs::write(dir.join("state.db"), b"").unwrap();
    }

    fn files_in(dir: &Path) -> Vec<String> {
        let mut names: Vec<String> = std::fs::read_dir(dir)
            .map(|d| {
                d.flatten()
                    .map(|e| e.file_name().to_string_lossy().to_string())
                    .collect()
            })
            .unwrap_or_default();
        names.sort();
        names
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
        let k = StandInKeychain::new();
        let s = stand_in_store(&k, &d.0);
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

        for secret in Secret::ALL {
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
    fn two_installations_keep_their_credentials_apart_in_one_credential_store() {
        // The credential store is shared by every installation under one OS
        // account, so it is the name, not the file, that keeps them apart: a
        // second drive linked on the same computer must not overwrite the
        // first's keys.
        let d = TempDir::new("two-keychain");
        let k = StandInKeychain::new();
        let personal_state = d.0.join("personal/state");
        let work_state = d.0.join("work/state");
        let open = |state: &Path| {
            SecretStore::with_stand_in(
                &k,
                &installation_service(state),
                Some(LEGACY_SERVICE),
                state,
                &[state.join("state.db")],
            )
        };
        let personal = open(&personal_state);
        let work = open(&work_state);
        assert_eq!(personal.custody(), Custody::OsKeychain);
        personal.set(Secret::VaultKey, "personal vault").unwrap();
        work.set(Secret::VaultKey, "work vault").unwrap();
        assert_eq!(
            open(&personal_state).get(Secret::VaultKey).unwrap(),
            "personal vault"
        );
        assert_eq!(
            open(&work_state).get(Secret::VaultKey).unwrap(),
            "work vault"
        );

        work.clear().unwrap();
        assert_eq!(
            personal.get(Secret::VaultKey).unwrap(),
            "personal vault",
            "unlinking one installation leaves the other's keys alone"
        );
    }

    #[test]
    fn an_installations_name_follows_where_its_state_really_is() {
        let d = TempDir::new("naming");
        let a = d.0.join("a/state");
        let b = d.0.join("b/state");
        let service = installation_service(&a);
        assert!(
            service.starts_with(&format!("{LEGACY_SERVICE}.")),
            "{service}"
        );
        assert_ne!(service, LEGACY_SERVICE);
        assert_ne!(
            service,
            installation_service(&b),
            "two installations, two names"
        );

        // The same before and after the directory exists, and however it is
        // spelled.
        std::fs::create_dir_all(&a).unwrap();
        assert_eq!(installation_service(&a), service);
        assert_eq!(installation_service(&d.0.join("a/./state")), service);
        #[cfg(unix)]
        {
            std::os::unix::fs::symlink(d.0.join("a"), d.0.join("link")).unwrap();
            assert_eq!(installation_service(&d.0.join("link/state")), service);
        }
    }

    #[test]
    fn the_name_hash_does_not_change_with_the_compiler() {
        // FNV-1a's published test vectors. A name that drifted between builds
        // would strand every credential an earlier build stored.
        assert_eq!(fnv1a_64(b""), 0xcbf2_9ce4_8422_2325);
        assert_eq!(fnv1a_64(b"a"), 0xaf63_dc4c_8601_ec8c);
        assert_eq!(fnv1a_64(b"foobar"), 0x8594_4171_f739_67e8);
    }

    #[test]
    fn credentials_under_the_old_shared_name_move_to_this_installations_own() {
        // What a client before per-installation names wrote. The first time it
        // is looked for, all three move at once, so the old name is left empty
        // rather than half-read for some other installation to find.
        let d = TempDir::new("legacy");
        let k = StandInKeychain::new();
        k.put(LEGACY_SERVICE, "api-secret", "sk-old");
        k.put(LEGACY_SERVICE, "device-key", "dk-old");
        k.put(LEGACY_SERVICE, "vault-key", "vk-old");
        linked_before(&d.0);

        let s = stand_in_store(&k, &d.0);
        assert_eq!(
            s.get_held(Secret::ApiSecret).unwrap(),
            ("sk-old".to_string(), Custody::OsKeychain)
        );
        assert_eq!(k.peek(OWN, "api-secret").as_deref(), Some("sk-old"));
        assert_eq!(k.peek(OWN, "device-key").as_deref(), Some("dk-old"));
        assert_eq!(k.peek(OWN, "vault-key").as_deref(), Some("vk-old"));
        for account in ["api-secret", "device-key", "vault-key"] {
            assert_eq!(
                k.peek(LEGACY_SERVICE, account),
                None,
                "{account} left under the old name"
            );
        }
        assert_eq!(s.get(Secret::VaultKey).unwrap(), "vk-old");
    }

    #[test]
    fn an_installation_linked_since_leaves_the_old_shared_name_to_its_owner() {
        // Another installation's link from before per-installation names sits
        // under the shared name. This one was linked by this client, without
        // encrypted folders: missing a vault key must not take that one's — it
        // would be opening encrypted folders with a key nobody handed it, and
        // leave the installation it belongs to with nothing.
        for linked_in in ["keychain", "file"] {
            let d = TempDir::new(&format!("legacy-not-mine-{linked_in}"));
            linked_before(&d.0);
            let k = StandInKeychain::new();
            k.put(LEGACY_SERVICE, "api-secret", "sk-theirs");
            k.put(LEGACY_SERVICE, "vault-key", "vk-theirs");
            if linked_in == "file" {
                k.set_locked(true);
            }
            stand_in_store(&k, &d.0)
                .set(Secret::ApiSecret, "sk-mine")
                .unwrap();
            k.set_locked(false);

            let s = stand_in_store(&k, &d.0);
            assert!(
                matches!(s.get(Secret::VaultKey), Err(SecretError::NotFound(_))),
                "linked in the {linked_in}"
            );
            assert_eq!(s.get(Secret::ApiSecret).unwrap(), "sk-mine");
            s.clear().unwrap();
            assert_eq!(
                k.peek(LEGACY_SERVICE, "vault-key").as_deref(),
                Some("vk-theirs")
            );
            assert_eq!(
                k.peek(LEGACY_SERVICE, "api-secret").as_deref(),
                Some("sk-theirs")
            );
        }
    }

    #[test]
    fn a_secret_written_while_the_credential_store_was_locked_is_found_once_it_opens() {
        // Linked over SSH, where the login keychain will not open: the API
        // secret went to a file. The daemon started later from the desktop has
        // the keychain open, and must still find it there.
        let d = TempDir::new("locked-then-open");
        let k = StandInKeychain::new();
        k.set_locked(true);
        let over_ssh = stand_in_store(&k, &d.0);
        assert!(over_ssh.keychain_locked());
        assert_eq!(over_ssh.custody(), Custody::File);
        over_ssh
            .set(Secret::ApiSecret, "sk-linked-over-ssh")
            .unwrap();

        k.set_locked(false);
        let desktop = stand_in_store(&k, &d.0);
        assert_eq!(desktop.custody(), Custody::OsKeychain);
        assert_eq!(
            desktop.get_held(Secret::ApiSecret).unwrap(),
            ("sk-linked-over-ssh".to_string(), Custody::File)
        );
        assert!(
            desktop
                .describe(Custody::File)
                .contains("locked when it was linked"),
            "status says where the key is, and why"
        );
    }

    #[test]
    fn a_session_that_cannot_open_the_credential_store_never_keeps_the_vault_key() {
        // Q1: on a computer with a credential store, the key to encrypted
        // folders lives in it or nowhere. Not in a file beside the others.
        let d = TempDir::new("locked-vault");
        let k = StandInKeychain::new();
        k.set_locked(true);
        let s = stand_in_store(&k, &d.0);
        assert!(!s.can_keep(Secret::VaultKey));
        assert!(s.can_keep(Secret::ApiSecret) && s.can_keep(Secret::DeviceKey));

        let refused = s.set(Secret::VaultKey, "vk").unwrap_err();
        assert!(matches!(refused, SecretError::Locked(_)), "{refused}");
        let said = refused.to_string();
        assert!(
            said.contains("joinery-drive login") && said.contains("desktop session"),
            "{said}"
        );
        assert!(!d.0.join("vault-key.secret").exists());

        // The others still link plain folders.
        s.set(Secret::ApiSecret, "sk").unwrap();
        assert_eq!(files_in(&d.0), vec!["api-secret.secret".to_string()]);
    }

    #[test]
    fn with_no_credential_store_at_all_the_vault_key_is_a_file_like_the_rest() {
        // A headless Linux server has no store to keep it in; refusing would
        // mean encrypted folders never sync there.
        let d = TempDir::new("absent-vault");
        let s = SecretStore::file_only("joinery-drive-test", &d.0);
        assert!(s.can_keep(Secret::VaultKey));
        s.set(Secret::VaultKey, "vk").unwrap();
        assert_eq!(s.get(Secret::VaultKey).unwrap(), "vk");
        assert!(s.describe(Custody::File).contains("no system keyring"));
    }

    #[test]
    fn writing_into_the_credential_store_leaves_no_older_copy_in_a_file() {
        // Linked over SSH, then linked again from the desktop: the file from
        // the first link must not outlive the key it holds.
        let d = TempDir::new("supersede-file");
        let k = StandInKeychain::new();
        k.set_locked(true);
        stand_in_store(&k, &d.0)
            .set(Secret::ApiSecret, "sk-first")
            .unwrap();
        k.set_locked(false);
        let desktop = stand_in_store(&k, &d.0);
        desktop.set(Secret::ApiSecret, "sk-second").unwrap();
        assert_eq!(files_in(&d.0), Vec::<String>::new());
        assert_eq!(
            desktop.get_held(Secret::ApiSecret).unwrap(),
            ("sk-second".to_string(), Custody::OsKeychain)
        );
    }

    #[test]
    fn unlinking_removes_every_copy_wherever_it_was_written() {
        // Keys written while the credential store was locked are files; the
        // rest are under this installation's own name. Unlinking takes all of
        // them, from a session where the store is open.
        let d = TempDir::new("clear-everywhere");
        let k = StandInKeychain::new();
        k.set_locked(true);
        let over_ssh = stand_in_store(&k, &d.0);
        over_ssh.set(Secret::ApiSecret, "sk").unwrap();
        over_ssh.set(Secret::DeviceKey, "dk").unwrap();
        k.set_locked(false);
        k.put(OWN, "vault-key", "vk");

        stand_in_store(&k, &d.0).clear().unwrap();

        assert_eq!(
            files_in(&d.0),
            Vec::<String>::new(),
            "no key file left on disk"
        );
        assert!(k.is_empty(), "no item left in the credential store");
    }

    #[test]
    fn unlinking_a_link_an_earlier_client_made_takes_its_keys_off_the_old_name_too() {
        // Unlinking reads the API secret first (to revoke it), which moves this
        // installation's link from before onto its own name; clearing then
        // leaves nothing under either.
        let d = TempDir::new("clear-legacy");
        let k = StandInKeychain::new();
        k.put(LEGACY_SERVICE, "api-secret", "sk-old");
        k.put(LEGACY_SERVICE, "device-key", "dk-old");
        k.put(LEGACY_SERVICE, "vault-key", "vk-old");
        linked_before(&d.0);
        let s = stand_in_store(&k, &d.0);
        assert_eq!(s.get(Secret::ApiSecret).unwrap(), "sk-old");
        s.clear().unwrap();
        assert!(k.is_empty(), "nothing left under either name");
    }

    #[test]
    fn a_new_installation_beside_one_an_earlier_client_linked_takes_nothing_of_it() {
        // An installation linked before the upgrade keeps its keys under the
        // shared name until it next reads them. A second installation that
        // starts first -- new, never linked, nothing on disk -- must leave them
        // there: taken, the first would stop working, and the second would hold
        // a vault key nobody handed it.
        let d = TempDir::new("legacy-two");
        let k = StandInKeychain::new();
        k.put(LEGACY_SERVICE, "api-secret", "sk-first");
        k.put(LEGACY_SERVICE, "device-key", "dk-first");
        k.put(LEGACY_SERVICE, "vault-key", "vk-first");
        let first = d.0.join("first/state");
        let second = d.0.join("second/state");
        std::fs::create_dir_all(&first).unwrap();
        std::fs::create_dir_all(&second).unwrap();
        linked_before(&first);
        let open = |state: &Path| {
            SecretStore::with_stand_in(
                &k,
                &installation_service(state),
                Some(LEGACY_SERVICE),
                state,
                &[state.join("state.db")],
            )
        };

        let new = open(&second);
        for secret in Secret::ALL {
            assert!(
                matches!(new.get(secret), Err(SecretError::NotFound(_))),
                "{secret:?}"
            );
        }
        assert_eq!(
            k.peek(LEGACY_SERVICE, "vault-key").as_deref(),
            Some("vk-first")
        );

        // And the installation they belong to still finds all of them.
        let old = open(&first);
        assert_eq!(old.get(Secret::ApiSecret).unwrap(), "sk-first");
        assert_eq!(old.get(Secret::VaultKey).unwrap(), "vk-first");
        assert!(k.peek(LEGACY_SERVICE, "api-secret").is_none());
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
        let k = StandInKeychain::new();
        k.set_locked(true);
        let store = stand_in_store(&k, &d.0);

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
        // The file is where it was written; a locked keychain is only an
        // explanation for its absence, never a reason to ignore it.
        let d = TempDir::new("lockedbutpresent");
        let k = StandInKeychain::new();
        k.set_locked(true);
        let store = stand_in_store(&k, &d.0);
        store
            .set(Secret::ApiSecret, "sk-not-a-real-secret")
            .unwrap();
        assert_eq!(
            stand_in_store(&k, &d.0).get(Secret::ApiSecret).unwrap(),
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
        assert!(s.describe(s.custody()).contains("file"));
    }
}
