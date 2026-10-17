//! Linking this computer to an account, through the user's browser.
//!
//! A terminal is the wrong place to authenticate a device, and the reasons stack
//! up: a passkey-first account has no password to type, a step-up challenge
//! cannot be answered at a prompt, and the vault key can only be unlocked where
//! WebAuthn works. So the device asks for a link, the browser approves it, and
//! the credential exists only after a person said yes somewhere they were
//! already signed in. This is the same shape as `tailscale up`, for the same
//! reasons.
//!
//! Four things about the mechanics are load-bearing.
//!
//! **The device keypair is generated before the ceremony starts**, because its
//! public half is what the vault key comes back sealed to. Generating it
//! afterwards would mean either a second round trip or handing the vault key
//! over in the clear.
//!
//! **A computer that cannot keep the vault key does not ask for it.** In a
//! session that cannot open the computer's credential store — a Mac reached
//! over SSH — no public key is offered, so the approval page does not offer
//! encrypted folders, and the person is told to link from their desktop
//! session to have them (`jd_platform::secret`).
//!
//! **The credential arrives exactly once.** The server scrubs it from the link
//! row on the first successful poll after approval, so the poll that receives it
//! must store it before doing anything else that could fail. Losing it means
//! starting over, not asking again.
//!
//! **Approval is not the end.** A poll can also come back denied or expired, and
//! both are ordinary outcomes rather than errors — a user who clicks "not me" is
//! doing exactly what that button is for.

use std::path::PathBuf;
use std::time::Duration;

use jd_core::vault::Vault;
use jd_platform::{Paths, Secret, SecretError, SecretStore};
use jd_proto::{Client, Credentials, DriveApi, ProtoError};
use serde_json::{json, Value};

use crate::config::{Config, ConfigError};

/// This installation's credential store: the one `login`, the settings page's
/// first run, the daemon and `unlink` all open, under a name of its own
/// (`jd_platform::installation_service`).
pub fn secrets(paths: &Paths) -> SecretStore {
    SecretStore::for_installation(paths)
}

/// How often to ask whether the user has approved yet.
///
/// Three seconds against a ten-minute ceremony is two hundred requests, which
/// the rate bucket for this endpoint is sized for (600/hour). Polling faster
/// buys nothing a person would notice and risks the bucket; slower makes the
/// approval feel broken.
pub const POLL_INTERVAL: Duration = Duration::from_secs(3);

#[derive(Debug, thiserror::Error)]
pub enum LinkError {
    #[error("the server refused to start a device link: {0}")]
    Begin(String),
    #[error("the link was declined on the other device")]
    Denied,
    #[error("the link code expired before it was approved")]
    Expired,
    #[error("could not generate a device key: {0}")]
    Crypto(String),
    #[error("{0}")]
    Api(#[from] ProtoError),
    #[error("the server's answer did not carry {0}")]
    Contract(&'static str),
}

/// A ceremony in progress: what to show the user, and what to poll with.
#[derive(Debug, Clone)]
pub struct Pending {
    /// The eight-character code, for a user typing it in by hand.
    pub link_code: String,
    /// The page to open, with the code already in it.
    pub verify_url: String,
    pub poll_token: String,
    pub expires_time: String,
    /// This device's X25519 keypair, when it offered to take the vault key.
    /// The public half went to the server; the secret half is what opens the
    /// sealed vault key. None when this session cannot keep a vault key, so the
    /// approval page offers no encrypted folders rather than unlocking the
    /// vault for a key this computer would not keep.
    pub device_key: Option<DeviceKey>,
}

/// The keypair a device offers the vault key to be sealed to.
#[derive(Debug, Clone)]
pub struct DeviceKey {
    pub public_key: String,
    pub secret_pkcs8: Vec<u8>,
}

/// What the user decided.
#[derive(Debug, Clone)]
pub enum Outcome {
    /// Still waiting. Carries nothing: there is nothing to report yet, and a
    /// progress percentage would be invented.
    Pending,
    Approved(Approved),
    Denied,
    Expired,
}

/// The credential and everything that came with it.
#[derive(Debug, Clone)]
pub struct Approved {
    pub credentials: Credentials,
    pub device_id: Option<i64>,
    /// The Drive vault secret key, sealed to this device — present only if the
    /// user ticked the encrypted-folders box.
    pub sealed_vault_key: Option<String>,
}

/// Start a ceremony. `offer_vault` is whether this computer can keep the vault
/// key (`SecretStore::can_keep`): without it, no public key is sent, and the
/// server, which treats an empty one as none, does not offer encrypted folders.
pub fn begin(
    client: &Client,
    device_name: &str,
    platform: &str,
    offer_vault: bool,
) -> Result<Pending, LinkError> {
    // Before the request, not after: the public half has to be in the request
    // for the vault key to have anything to be sealed to.
    let device_key = offer_vault.then(|| {
        let keypair = jd_crypto::vault::generate_vault_keypair();
        DeviceKey {
            public_key: keypair.public_key_b64.clone(),
            secret_pkcs8: keypair.secret_key_pkcs8.clone(),
        }
    });
    let offered = device_key.as_ref().map_or("", |k| k.public_key.as_str());

    let data = client
        .device_link_begin(device_name, platform, offered)
        .map_err(|e| LinkError::Begin(e.to_string()))?;

    Ok(Pending {
        link_code: string_field(&data, "link_code")?,
        verify_url: string_field(&data, "verify_url")?,
        poll_token: string_field(&data, "poll_token")?,
        expires_time: data
            .get("expires_time")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string(),
        device_key,
    })
}

/// Ask once whether the ceremony has been decided.
pub fn poll(client: &Client, pending: &Pending) -> Result<Outcome, LinkError> {
    let data = match client.device_link_poll(&pending.poll_token) {
        Ok(d) => d,
        // The row is swept when it expires, so a vanished link is an expiry
        // rather than a failure — and telling the user their code ran out is a
        // far more useful sentence than a 404.
        Err(ProtoError::Api { status: 404, .. }) => return Ok(Outcome::Expired),
        Err(e) => return Err(e.into()),
    };

    match data.get("status").and_then(Value::as_str) {
        Some("approved") => {
            let public_key = string_field(&data, "public_key")?;
            let secret_key = string_field(&data, "secret_key")?;
            Ok(Outcome::Approved(Approved {
                credentials: Credentials {
                    public_key,
                    secret_key,
                },
                device_id: data.get("device_id").and_then(Value::as_i64),
                sealed_vault_key: data
                    .get("sealed_vault_key")
                    .and_then(Value::as_str)
                    .map(str::to_string),
            }))
        }
        Some("denied") => Ok(Outcome::Denied),
        Some("expired") => Ok(Outcome::Expired),
        _ => Ok(Outcome::Pending),
    }
}

/// What `joinery-drive login` tells the person while it waits.
///
/// The code and the address are printed whether or not a browser opened,
/// because the ceremony works perfectly well with a person reading them off one
/// screen and typing them into another — which is the only way it works on a
/// headless box. "Opened in your browser" is said only when a browser was in
/// fact asked to open it; otherwise the person is told to open it themselves,
/// rather than left looking for a window that never appeared.
pub fn instructions(base_url: &str, pending: &Pending, opened: bool) -> String {
    let then = if opened {
        "(opened in your browser)".to_string()
    } else {
        format!(
            "Open that address in a browser where you are signed in to {base_url} — on this \
             computer or any other — and approve this computer there."
        )
    };
    format!(
        "To link this computer, approve it while signed in to {base_url}:\n\n    {}\n\n    \
         or enter the code: {}\n\n{then}\n\nWaiting for approval\u{2026}\n",
        pending.verify_url, pending.link_code
    )
}

/// Open the sealed vault key with this device's secret key.
///
/// The recipient public key is required by the sealing scheme itself — it is
/// mixed into the key derivation — which is what makes a blob sealed to one
/// device unopenable by another even if that other device somehow had the
/// secret.
pub fn open_vault_key(pending: &Pending, sealed: &str) -> Result<Vec<u8>, LinkError> {
    let key = pending.device_key.as_ref().ok_or_else(|| {
        LinkError::Crypto("this computer offered no key for it to be sealed to".into())
    })?;
    jd_crypto::vault::open_from_secret_key(sealed, &key.secret_pkcs8, &key.public_key)
        .map_err(|e| LinkError::Crypto(e.to_string()))
}

/// What became of the key to encrypted folders when a link was approved.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum VaultHandoff {
    /// Opened and kept: encrypted folders open on this computer.
    Enabled,
    /// None was handed over: the box on the approval page was not ticked, or
    /// the account has no encrypted folders.
    NotHanded,
    /// This session cannot open the computer's credential store, so the key
    /// was not asked for, and would not have been kept.
    NeedsDesktop,
    /// Handed over, and it would not open with this computer's key.
    Unopenable(String),
}

impl VaultHandoff {
    pub fn enabled(&self) -> bool {
        *self == VaultHandoff::Enabled
    }

    /// What to tell the person, when there is more to say than whether
    /// encrypted folders are on.
    pub fn sentence(&self) -> Option<String> {
        match self {
            VaultHandoff::Enabled | VaultHandoff::NotHanded => None,
            VaultHandoff::NeedsDesktop => Some(
                "Encrypted folders are not enabled: this session cannot open this computer's \
                 credential store, where their key has to be kept. Run joinery-drive login from \
                 your desktop session to enable encrypted folders."
                    .into(),
            ),
            VaultHandoff::Unopenable(why) => Some(format!(
                "Encrypted folders could not be enabled on this computer ({why}). Everything \
                 else will sync; link again to retry."
            )),
        }
    }
}

/// Keep what an approval handed over, and say what became of the vault key.
///
/// Called the moment the approval arrives, before anything that could fail:
/// the server hands the credential over exactly once and scrubs it, so anything
/// done first that fails loses it for good. The CLI's `login` and the settings
/// page's first run both come through here, so they cannot disagree about what
/// a linked device holds.
///
/// Keys are stored base64 through `jd_crypto::b64`, the same implementation the
/// daemon reads them back with: two hand-rolled halves agree until one of them
/// is edited, and the symptom of disagreement is a key that stored fine and
/// cannot be read back.
///
/// Every key the computer holds afterwards is this link's. A link that hands
/// no vault key over leaves none behind: one kept from an earlier link — of
/// another account, or another installation's, moved off the name every
/// installation once shared — would have the daemon open and seal encrypted
/// files with a key that is not this account's.
pub fn store_approved(
    secrets: &SecretStore,
    pending: &Pending,
    approved: &Approved,
) -> Result<VaultHandoff, String> {
    secrets
        .set(Secret::ApiSecret, &approved.credentials.secret_key)
        .map_err(|e| format!("cannot store the device credential: {e}"))?;
    match &pending.device_key {
        Some(key) => secrets
            .set(
                Secret::DeviceKey,
                &jd_crypto::b64::encode(&key.secret_pkcs8),
            )
            .map_err(|e| format!("cannot store the device key: {e}"))?,
        // Not fatal if it cannot be removed: nothing reads a device key once
        // its link is over.
        None => {
            let _ = secrets.delete(Secret::DeviceKey);
        }
    }
    let handoff = receive_vault_key(secrets, pending, approved)?;
    if !handoff.enabled() {
        // Not fatal if it cannot be removed either: the config this link
        // writes says it handed no key over, and the daemon uses none
        // (`load_vault`).
        let _ = secrets.delete(Secret::VaultKey);
    }
    Ok(handoff)
}

/// Open and keep the vault key an approval carried, if this computer asked for
/// one and can keep it.
fn receive_vault_key(
    secrets: &SecretStore,
    pending: &Pending,
    approved: &Approved,
) -> Result<VaultHandoff, String> {
    // Nothing was asked for (`begin`), so nothing should have come. Whatever
    // did is not opened: this computer would not keep it, and should not hold
    // it even for the length of this call.
    if !secrets.can_keep(Secret::VaultKey) {
        return Ok(VaultHandoff::NeedsDesktop);
    }
    let Some(sealed) = &approved.sealed_vault_key else {
        return Ok(VaultHandoff::NotHanded);
    };
    match open_vault_key(pending, sealed) {
        Ok(vault_key) => {
            secrets
                .set(Secret::VaultKey, &jd_crypto::b64::encode(&vault_key))
                .map_err(|e| format!("cannot store the vault key: {e}"))?;
            Ok(VaultHandoff::Enabled)
        }
        // Not fatal. Everything outside encrypted folders syncs perfectly
        // well without it, and refusing to link over this would be refusing
        // the whole account over one feature.
        Err(e) => Ok(VaultHandoff::Unopenable(e.to_string())),
    }
}

/// The key for encrypted folders, at the daemon's start, if this computer has
/// one it should use; and what to say in the log about it, if anything.
///
/// Never fatal, in any of its failure modes, and that is the whole design of
/// this function. Everything outside encrypted folders syncs perfectly well
/// without a vault key, so refusing to start over a missing or unreadable one
/// would take an entire account offline over one feature. What it does instead
/// is say which situation it is in, because they need different things from
/// the user: nothing at all, link again, or look at the keychain.
///
/// Only a key this link was handed is used. The config says whether the link
/// that wrote it was (`vault_enabled`); a key in the store when it says not is
/// left from an earlier link, and is not even read.
///
/// And only a key that can be the account's. The server is asked once
/// (`vault_client_probe`):
/// - the account has no vault at all: the key is not its, and is not used;
/// - this device is listed as holding the account's key, and the one here is
///   another: it is not the key this device was handed, and is not used;
/// - the account's key is another one and this device is no longer listed:
///   that is what a rotation looks like, and the retired key still opens every
///   file key it opened before, so it is used, and the engine asks for the
///   computer to be linked again (`vault_relink`, `drive_sync.md`);
/// - the server cannot be asked, or will not say: used, as it always was, and
///   said in the log.
pub fn load_vault(
    secrets: &SecretStore,
    config: &Config,
    api: &dyn DriveApi,
) -> (Option<Vault>, Option<String>) {
    const LINK_AGAIN: &str = "Everything outside encrypted folders will sync; link again to \
                              restore it.";
    if !config.vault_enabled {
        return (None, None);
    }
    let stored = match secrets.get(Secret::VaultKey) {
        Ok(s) => s,
        Err(e) => {
            return (
                None,
                Some(format!(
                    "encrypted folders are enabled for this device but its key could not be \
                     read ({e}). {LINK_AGAIN}"
                )),
            )
        }
    };
    let Ok(raw) = jd_crypto::b64::decode(&stored) else {
        return (
            None,
            Some(format!(
                "this device's stored vault key is not readable. {LINK_AGAIN}"
            )),
        );
    };
    let vault = match Vault::from_secret_key(&raw) {
        Ok(v) => v,
        Err(e) => return (None, Some(format!("{e}. {LINK_AGAIN}"))),
    };
    let answer = match api.action("vault_client_probe", json!({ "scope": "drive" })) {
        Ok(answer) => answer,
        Err(e) => {
            return (
                Some(vault),
                Some(format!(
                    "could not ask the server whether this device's key for encrypted folders \
                     is the account's ({e}); it is used as it is"
                )),
            )
        }
    };
    const NOT_USED: &str = "It is not used: everything outside encrypted folders will sync; \
                            link this computer again (joinery-drive login, from the desktop \
                            session) to enable encrypted folders.";
    if answer.get("set_up").and_then(Value::as_bool) != Some(true) {
        return (
            None,
            Some(format!(
                "the account this computer is linked to has no key for encrypted folders, so \
                 the one stored here is not its. {NOT_USED}"
            )),
        );
    }
    // A mismatched key on a device the server does not list as holding one is
    // kept: that is exactly the state a rotation leaves, and the retired key
    // must go on opening what it opened. The only other way a key from
    // elsewhere arrives is two installations an earlier client both linked
    // under the shared name: on one account the key is the account's own, and
    // across two, the API secret taken with it is not the one the taker's
    // config names, so the server answers nothing for it at all — and where
    // it does answer, a device listed as holding its account's key is refused
    // here.
    let held = answer.get("held_by_this_device").and_then(Value::as_bool) == Some(true);
    let the_accounts = answer
        .get("public_key")
        .and_then(Value::as_str)
        .is_some_and(|key| same_key(key, vault.public_key_b64()));
    if held && !the_accounts {
        return (
            None,
            Some(format!(
                "this computer was handed the account's key for encrypted folders, and the one \
                 stored here is another. {NOT_USED}"
            )),
        );
    }
    (Some(vault), None)
}

/// Do two base64 public keys name the same key? Compared as keys: one key has
/// more than one spelling in base64.
fn same_key(a: &str, b: &str) -> bool {
    a == b
        || matches!(
            (jd_crypto::b64::decode(a.trim()), jd_crypto::b64::decode(b.trim())),
            (Ok(x), Ok(y)) if x == y
        )
}

/// The account a credential belongs to, as the server says (`auth/session`).
/// None when the server cannot be asked; a state store is then bound to the
/// site and folder only, as it would be without it.
pub fn account_of(base_url: &str, credentials: &Credentials) -> Option<i64> {
    Client::with_credentials(base_url, credentials.clone())
        .session()
        .ok()?
        .get("user_id")?
        .as_i64()
}

/// The link a new `login` replaces: its config, and the key that can take it
/// off the account.
pub struct Previous {
    pub config: Config,
    /// None when this computer no longer holds it.
    secret: Option<String>,
}

impl Previous {
    /// The link this computer has now, read before a new ceremony starts while
    /// its key is still the one in the store.
    ///
    /// Refused when that key is in a credential store this session cannot
    /// open: linking anew from here would leave the old device live on the
    /// account and its keys in the store, with nothing on this computer able
    /// to name either.
    pub fn find(paths: &Paths, secrets: &SecretStore) -> Result<Option<Previous>, String> {
        let config = match Config::load(&paths.config_file()) {
            Ok(config) => config,
            Err(ConfigError::NotLinked) => return Ok(None),
            Err(e) => {
                eprintln!(
                    "warning: {e}. An earlier link of this computer, if there was one, cannot \
                     be taken off the account from here."
                );
                return Ok(None);
            }
        };
        let secret = match secrets.get(Secret::ApiSecret) {
            Ok(secret) => Some(secret),
            Err(SecretError::Locked(_)) => {
                return Err(format!(
                    "this computer is linked to {} and its key is in the credential store, \
                     which this session cannot open, so the old link could not be taken off \
                     the account. Run joinery-drive login from your desktop session.",
                    config.base_url
                ))
            }
            Err(_) => None,
        };
        Ok(Some(Previous { config, secret }))
    }

    fn credentials(&self) -> Option<Credentials> {
        Some(Credentials {
            public_key: self.config.public_key.clone(),
            secret_key: self.secret.clone()?,
        })
    }

    /// Whose drive the old link synced: from its config, or asked of the
    /// server with its own key when the config is from before it said.
    pub fn account(&self) -> Option<i64> {
        self.config
            .account_id
            .or_else(|| account_of(&self.config.base_url, &self.credentials()?))
    }

    /// Put the old key back, when storing a new link failed part way: until
    /// the new link is kept, the old one is this computer's link.
    pub fn restore(&self, secrets: &SecretStore) {
        if let Some(secret) = &self.secret {
            let _ = secrets.set(Secret::ApiSecret, secret);
        }
    }

    /// Take the old device off the account, with its own key, so it can only
    /// ever revoke itself. None when this computer no longer holds the key.
    pub fn revoke(&self) -> Option<Result<(), String>> {
        let mut client = Client::with_credentials(&self.config.base_url, self.credentials()?);
        Some(client.logout().map(|_| ()).map_err(|e| e.to_string()))
    }
}

/// What unlinking did, for whoever is telling the user about it.
pub struct Unlinked {
    pub base_url: String,
    pub sync_root: PathBuf,
    /// Whether the server took the key back. `None` when this device held no
    /// credential to revoke.
    pub revoked: Option<Result<(), String>>,
}

/// Take this computer off its account and forget its keys.
///
/// Revokes server-side **before** forgetting locally. The other order leaves a
/// live credential on the server that nothing on this machine can name any
/// more, which is exactly the key a lost laptop is carrying. The synced files
/// are left exactly where they are: unlinking is "stop syncing this", never
/// "delete the user's documents".
///
/// Refused, with nothing changed, when this computer's key is in a credential
/// store this session cannot open: unlinking from there could neither revoke
/// the key nor remove it, yet would report the computer unlinked.
pub fn unlink(paths: &Paths, config: &Config, secrets: &SecretStore) -> Result<Unlinked, String> {
    let credentials = held_credentials(config, secrets)?;
    // Before the key goes, while it can still ask: say whose record the state
    // store is, so that linking it to another account later sets it aside
    // rather than reading it against a drive it is not the record of.
    let account = config.account_id.or_else(|| {
        credentials
            .as_ref()
            .and_then(|c| account_of(&config.base_url, c))
    });
    if let Some(account) = account {
        let _ = crate::daemon::note_store_account(&paths.state_db(), account);
    }
    let revoked = credentials.map(|credentials| {
        let mut client = Client::with_credentials(&config.base_url, credentials);
        client.logout().map(|_| ()).map_err(|e| e.to_string())
    });
    secrets.clear().map_err(|e| e.to_string())?;
    let _ = std::fs::remove_file(paths.config_file());
    let _ = jd_platform::autostart::disable(&jd_platform::home_dir());
    Ok(Unlinked {
        base_url: config.base_url.clone(),
        sync_root: config.sync_root.clone(),
        revoked,
    })
}

/// Whether `unlink` can go ahead from this session; the reason when not. For
/// a caller that has something to undo first, like stopping the daemon.
pub fn check_unlink(config: &Config, secrets: &SecretStore) -> Result<(), String> {
    held_credentials(config, secrets).map(|_| ())
}

/// This computer's credential for its link: None when it holds none, and
/// refused when it is in a credential store this session cannot open.
fn held_credentials(config: &Config, secrets: &SecretStore) -> Result<Option<Credentials>, String> {
    match secrets.get(Secret::ApiSecret) {
        Ok(secret_key) => Ok(Some(Credentials {
            public_key: config.public_key.clone(),
            secret_key,
        })),
        Err(SecretError::Locked(_)) => Err(format!(
            "this computer's key for {} is in its credential store, which this session cannot \
             open, so unlinking from here would leave the device live on the account and its \
             keys behind. Run joinery-drive unlink from your desktop session.",
            config.base_url
        )),
        Err(_) => Ok(None),
    }
}

fn string_field(data: &Value, name: &'static str) -> Result<String, LinkError> {
    data.get(name)
        .and_then(Value::as_str)
        .filter(|s| !s.is_empty())
        .map(str::to_string)
        .ok_or(LinkError::Contract(name))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    use jd_platform::StandInKeychain;

    /// A pending ceremony with a real keypair, so the sealing tests exercise the
    /// actual primitive rather than a stand-in for it.
    fn pending() -> Pending {
        let kp = jd_crypto::vault::generate_vault_keypair();
        Pending {
            link_code: "ABCD-EFGH".into(),
            verify_url: "https://dev.getjoinery.com/profile/devices/link?code=ABCD-EFGH".into(),
            poll_token: "tok".into(),
            expires_time: "2026-07-31 12:00:00".into(),
            device_key: Some(DeviceKey {
                public_key: kp.public_key_b64.clone(),
                secret_pkcs8: kp.secret_key_pkcs8.clone(),
            }),
        }
    }

    fn public_key(p: &Pending) -> &str {
        &p.device_key.as_ref().unwrap().public_key
    }

    fn temp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-link-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    fn paths(root: &std::path::Path) -> Paths {
        let p = Paths {
            config: root.join("config"),
            state: root.join("state"),
            logs: root.join("logs"),
        };
        p.create().unwrap();
        p
    }

    /// The name stand-in stores are opened under here.
    const OWN: &str = "com.joinery.drive.test-link";

    fn stand_in(keychain: &StandInKeychain, paths: &Paths) -> SecretStore {
        SecretStore::with_stand_in(
            keychain,
            OWN,
            Some(jd_platform::LEGACY_SERVICE),
            &paths.state,
            &[paths.config_file(), paths.state_db()],
        )
    }

    fn config(base_url: &str) -> Config {
        Config {
            base_url: base_url.into(),
            sync_root: "/r".into(),
            device_name: "Laptop".into(),
            device_id: Some(7),
            public_key: "old-public-key".into(),
            account_id: Some(41),
            poll_seconds: crate::config::DEFAULT_POLL_SECONDS,
            excluded: Vec::new(),
            vault_enabled: false,
            autostart: false,
        }
    }

    /// A loopback HTTP server that answers one request after another with the
    /// given JSON bodies, and hands back what each request was: its head,
    /// lowercased, and its body.
    fn scripted_server(
        answers: Vec<&'static str>,
    ) -> (String, std::thread::JoinHandle<Vec<String>>) {
        use std::io::{Read, Write};
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let base = format!("http://{}", listener.local_addr().unwrap());
        let handle = std::thread::spawn(move || {
            let mut seen = Vec::new();
            for answer in answers {
                let Ok((mut stream, _)) = listener.accept() else {
                    break;
                };
                stream
                    .set_read_timeout(Some(Duration::from_secs(5)))
                    .unwrap();
                let mut raw = Vec::new();
                let mut buf = [0u8; 4096];
                let head_end = loop {
                    if let Some(i) = raw.windows(4).position(|w| w == b"\r\n\r\n") {
                        break i + 4;
                    }
                    let n = stream.read(&mut buf).unwrap_or(0);
                    if n == 0 {
                        break raw.len();
                    }
                    raw.extend_from_slice(&buf[..n]);
                };
                let head = String::from_utf8_lossy(&raw[..head_end]).to_lowercase();
                let length = head
                    .lines()
                    .find_map(|l| l.strip_prefix("content-length:"))
                    .and_then(|v| v.trim().parse::<usize>().ok())
                    .unwrap_or(0);
                while raw.len() < head_end + length {
                    let n = stream.read(&mut buf).unwrap_or(0);
                    if n == 0 {
                        break;
                    }
                    raw.extend_from_slice(&buf[..n]);
                }
                let _ = write!(
                    stream,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{answer}",
                    answer.len()
                );
                seen.push(format!(
                    "{head}{}",
                    String::from_utf8_lossy(&raw[head_end..])
                ));
            }
            seen
        });
        (base, handle)
    }

    const BEGUN: &str = r#"{"data":{"link_code":"ABCD-EFGH","poll_token":"tok","verify_url":"https://example.org/link","expires_time":""}}"#;

    #[test]
    fn a_vault_key_sealed_to_this_device_opens_with_this_devices_key() {
        let p = pending();
        let vault_secret = b"the drive vault secret key, pkcs8-shaped";
        let sealed = jd_crypto::vault::seal_to_public_key(vault_secret, public_key(&p)).unwrap();
        assert_eq!(open_vault_key(&p, &sealed).unwrap(), vault_secret);
    }

    #[test]
    fn a_vault_key_sealed_to_a_different_device_does_not_open_here() {
        // The property the whole handoff rests on: approving a link for one
        // laptop does not hand the vault to another.
        let mine = pending();
        let theirs = pending();
        let sealed =
            jd_crypto::vault::seal_to_public_key(b"not for me", public_key(&theirs)).unwrap();
        assert!(open_vault_key(&mine, &sealed).is_err());
    }

    #[test]
    fn a_computer_that_cannot_keep_a_vault_key_offers_nothing_to_seal_it_to() {
        // So the approval page does not offer encrypted folders, and nobody
        // unlocks their vault in the browser for a key this computer would
        // not keep.
        let (base, server) = scripted_server(vec![BEGUN, BEGUN]);
        let client = Client::new(&base);
        let without = begin(&client, "Laptop", "macos", false).unwrap();
        assert!(without.device_key.is_none());
        let with = begin(&client, "Laptop", "macos", true).unwrap();
        let seen = server.join().unwrap();
        assert!(seen[0].contains(r#""device_pubkey":"""#), "{}", seen[0]);
        let offered = &with.device_key.as_ref().unwrap().public_key;
        assert!(seen[1].contains(offered.as_str()), "{}", seen[1]);
    }

    #[test]
    fn a_locked_credential_store_links_plain_folders_and_keeps_no_vault_key() {
        // Q1: a Mac reached over SSH. The API secret goes to a file as on any
        // machine without an open store; the key to encrypted folders goes
        // nowhere, and the person is told how to have them.
        let dir = temp("locked-link");
        let p = paths(&dir);
        let keychain = StandInKeychain::new();
        keychain.set_locked(true);
        let secrets = stand_in(&keychain, &p);
        let pending = pending();
        let approved = Approved {
            credentials: Credentials {
                public_key: "pk".into(),
                secret_key: "sk".into(),
            },
            device_id: Some(9),
            sealed_vault_key: Some(
                jd_crypto::vault::seal_to_public_key(b"vault secret", public_key(&pending))
                    .unwrap(),
            ),
        };

        let handoff = store_approved(&secrets, &pending, &approved).unwrap();
        assert_eq!(handoff, VaultHandoff::NeedsDesktop);
        assert!(!handoff.enabled());
        let said = handoff.sentence().unwrap();
        assert!(
            said.contains("joinery-drive login") && said.contains("desktop session"),
            "{said}"
        );

        assert_eq!(
            secrets.get(Secret::ApiSecret).unwrap(),
            "sk",
            "plain folders link"
        );
        assert!(secrets.get(Secret::VaultKey).is_err());
        assert!(
            !p.state.join("vault-key.secret").exists(),
            "never in a file"
        );
        keychain.set_locked(false);
        assert!(keychain.is_empty(), "and nothing in the store either");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_open_credential_store_keeps_the_vault_key() {
        let dir = temp("open-link");
        let p = paths(&dir);
        let keychain = StandInKeychain::new();
        let secrets = stand_in(&keychain, &p);
        let pending = pending();
        let approved = Approved {
            credentials: Credentials {
                public_key: "pk".into(),
                secret_key: "sk".into(),
            },
            device_id: Some(9),
            sealed_vault_key: Some(
                jd_crypto::vault::seal_to_public_key(b"vault secret", public_key(&pending))
                    .unwrap(),
            ),
        };
        assert_eq!(
            store_approved(&secrets, &pending, &approved).unwrap(),
            VaultHandoff::Enabled
        );
        assert_eq!(
            jd_crypto::b64::decode(&secrets.get(Secret::VaultKey).unwrap()).unwrap(),
            b"vault secret"
        );
        assert!(keychain.peek(OWN, "vault-key").is_some());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn linking_again_where_the_old_key_cannot_be_reached_is_refused_before_anything_starts() {
        // Linked from the desktop, so the key is in the login keychain; login
        // run again over SSH cannot read it to take the old device off the
        // account, and would leave it live with its keys still in the store.
        let dir = temp("previous-locked");
        let p = paths(&dir);
        config("https://drive.example.org")
            .save(&p.config_file())
            .unwrap();
        let keychain = StandInKeychain::new();
        stand_in(&keychain, &p)
            .set(Secret::ApiSecret, "old-secret")
            .unwrap();

        keychain.set_locked(true);
        let refused = Previous::find(&p, &stand_in(&keychain, &p)).err().unwrap();
        assert!(refused.contains("desktop session"), "{refused}");

        keychain.set_locked(false);
        let previous = Previous::find(&p, &stand_in(&keychain, &p))
            .unwrap()
            .unwrap();
        assert_eq!(previous.secret.as_deref(), Some("old-secret"));
        assert_eq!(
            previous.account(),
            Some(41),
            "from the config, without asking"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn nothing_linked_means_nothing_to_replace() {
        let dir = temp("previous-none");
        let p = paths(&dir);
        let keychain = StandInKeychain::new();
        assert!(Previous::find(&p, &stand_in(&keychain, &p))
            .unwrap()
            .is_none());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn linking_again_takes_the_old_device_off_the_account_with_its_own_key() {
        let (base, server) = scripted_server(vec![r#"{"data":{}}"#]);
        let previous = Previous {
            config: config(&base),
            secret: Some("old-secret".into()),
        };
        assert_eq!(previous.revoke(), Some(Ok(())));
        let seen = server.join().unwrap();
        assert!(
            seen[0].starts_with("post /api/v1/auth/logout "),
            "{}",
            seen[0]
        );
        assert!(
            seen[0].contains("public-key: old-public-key"),
            "{}",
            seen[0]
        );
        assert!(seen[0].contains("secret-key: old-secret"), "{}", seen[0]);

        // A key this computer no longer holds cannot revoke anything, and
        // says so rather than pretending.
        let lost = Previous {
            config: config(&base),
            secret: None,
        };
        assert_eq!(lost.revoke(), None);
    }

    /// An approval of `pending` carrying `sealed` (or no vault key at all).
    fn approval_with(sealed: Option<String>) -> Approved {
        Approved {
            credentials: Credentials {
                public_key: "pk".into(),
                secret_key: "sk".into(),
            },
            device_id: Some(9),
            sealed_vault_key: sealed,
        }
    }

    #[test]
    fn a_link_that_hands_no_vault_key_leaves_none_behind() {
        // A vault key kept from an earlier link -- another account's, or one
        // an earlier client left under the shared name -- would have the daemon
        // open and seal encrypted files with a key that is not this account's.
        let earlier = jd_sim::SimVault::new(5);
        let earlier_b64 = jd_crypto::b64::encode(&earlier.secret_key_pkcs8);
        for (case, sealed) in [
            ("box not ticked", None),
            (
                "sealed to another device",
                Some(jd_crypto::vault::seal_to_public_key(b"x", public_key(&pending())).unwrap()),
            ),
        ] {
            let dir = temp(&format!("no-handoff-{}", case.len()));
            let p = paths(&dir);
            let keychain = StandInKeychain::new();
            let secrets = stand_in(&keychain, &p);
            secrets.set(Secret::VaultKey, &earlier_b64).unwrap();
            secrets
                .set(Secret::DeviceKey, "an earlier device key")
                .unwrap();

            let mut this = pending();
            this.device_key = None;
            let handoff = store_approved(&secrets, &this, &approval_with(sealed)).unwrap();
            assert!(!handoff.enabled(), "{case}");
            assert!(
                matches!(secrets.get(Secret::VaultKey), Err(SecretError::NotFound(_))),
                "{case}: the earlier vault key is gone"
            );
            assert!(
                matches!(
                    secrets.get(Secret::DeviceKey),
                    Err(SecretError::NotFound(_))
                ),
                "{case}: and so is the earlier device key"
            );
            assert_eq!(secrets.get(Secret::ApiSecret).unwrap(), "sk");
            let _ = std::fs::remove_dir_all(&dir);
        }

        // In a session that cannot open the store, what it can reach goes: a
        // vault key in a file, from when this computer had no store.
        let dir = temp("no-handoff-locked");
        let p = paths(&dir);
        std::fs::write(p.state.join("vault-key.secret"), &earlier_b64).unwrap();
        let keychain = StandInKeychain::new();
        keychain.set_locked(true);
        let secrets = stand_in(&keychain, &p);
        let handoff = store_approved(&secrets, &pending(), &approval_with(None)).unwrap();
        assert_eq!(handoff, VaultHandoff::NeedsDesktop);
        assert!(!p.state.join("vault-key.secret").exists());
        let _ = std::fs::remove_dir_all(&dir);
    }

    /// A device "laptop" holding `key` as its vault key, linked with encrypted
    /// folders on, and the mock server it talks to.
    fn holding(
        key: &jd_sim::SimVault,
    ) -> (
        PathBuf,
        SecretStore,
        Config,
        jd_sim::MockServer,
        jd_sim::SimNet,
    ) {
        let dir = temp(&format!(
            "load-vault-{}",
            key.public_key_b64[..6].replace(['/', '+'], "_")
        ));
        let p = paths(&dir);
        let secrets = stand_in(&StandInKeychain::new(), &p);
        secrets
            .set(
                Secret::VaultKey,
                &jd_crypto::b64::encode(&key.secret_key_pkcs8),
            )
            .unwrap();
        let mut config = config("https://drive.example.org");
        config.vault_enabled = true;
        let clock = jd_sim::SimClock::new();
        let server = jd_sim::MockServer::new(clock.clone());
        let net = jd_sim::SimNet::new(server.clone(), clock, 1, "laptop");
        (dir, secrets, config, server, net)
    }

    #[test]
    fn a_vault_key_is_used_only_where_this_link_handed_one_over() {
        let mine = jd_sim::SimVault::new(1);
        let (dir, secrets, mut config, server, net) = holding(&mine);
        server.set_vault_public_key(jd_sim::server::OWNER_USER_ID, &mine.public_key_b64);
        server.hand_vault_to("laptop");
        config.vault_enabled = false;
        let (vault, said) = load_vault(&secrets, &config, &net);
        assert!(
            vault.is_none(),
            "a key the link did not hand over is not used"
        );
        assert_eq!(said, None);
        assert_eq!(server.probe_requests(), 0, "and nobody is asked about it");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_accounts_own_key_is_used() {
        let mine = jd_sim::SimVault::new(1);
        let (dir, secrets, config, server, net) = holding(&mine);
        server.set_vault_public_key(jd_sim::server::OWNER_USER_ID, &mine.public_key_b64);
        server.hand_vault_to("laptop");
        let (vault, said) = load_vault(&secrets, &config, &net);
        assert_eq!(vault.unwrap().public_key_b64(), mine.public_key_b64);
        assert_eq!(said, None);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_vault_key_other_than_the_one_this_computer_was_handed_is_not_used() {
        // The device row says this computer holds the account's key, and the
        // key here has another public half: not the key it was handed.
        let mine = jd_sim::SimVault::new(1);
        let theirs = jd_sim::SimVault::new(2);
        let (dir, secrets, config, server, net) = holding(&mine);
        server.set_vault_public_key(jd_sim::server::OWNER_USER_ID, &theirs.public_key_b64);
        server.hand_vault_to("laptop");
        let (vault, said) = load_vault(&secrets, &config, &net);
        assert!(vault.is_none());
        let said = said.unwrap();
        assert!(
            said.contains("another") && said.contains("joinery-drive login"),
            "{said}"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_vault_key_on_an_account_with_no_vault_is_not_used() {
        let mine = jd_sim::SimVault::new(1);
        let (dir, secrets, config, _server, net) = holding(&mine);
        let (vault, said) = load_vault(&secrets, &config, &net);
        assert!(vault.is_none());
        assert!(said.unwrap().contains("has no key for encrypted folders"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn after_a_rotation_the_retired_key_is_still_used_for_what_it_opens() {
        // The account's key changed and this device is no longer listed: a
        // rotation. The old key still opens every file key it opened before,
        // and the engine asks for the computer to be linked again.
        let old = jd_sim::SimVault::new(1);
        let new = jd_sim::SimVault::new(2);
        let (dir, secrets, config, server, net) = holding(&old);
        server.set_vault_public_key(jd_sim::server::OWNER_USER_ID, &old.public_key_b64);
        server.hand_vault_to("laptop");
        server.rotate_vault(&old, &new);
        let (vault, said) = load_vault(&secrets, &config, &net);
        assert_eq!(vault.unwrap().public_key_b64(), old.public_key_b64);
        assert_eq!(said, None);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_server_that_will_not_say_leaves_the_key_as_it_was() {
        let mine = jd_sim::SimVault::new(1);
        let (dir, secrets, config, server, net) = holding(&mine);
        server.answer_next("vault_client_probe", 500);
        let (vault, said) = load_vault(&secrets, &config, &net);
        assert!(vault.is_some(), "used, as it always was");
        assert!(said.unwrap().contains("could not ask the server"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_new_link_that_could_not_be_kept_leaves_the_old_key_in_place() {
        // Storing the new link failed after its key was written: until a new
        // link is kept, the old one is this computer's link.
        let dir = temp("restore");
        let p = paths(&dir);
        let keychain = StandInKeychain::new();
        let secrets = stand_in(&keychain, &p);
        secrets.set(Secret::ApiSecret, "the-new-key").unwrap();
        let previous = Previous {
            config: config("https://drive.example.org"),
            secret: Some("the-old-key".into()),
        };
        previous.restore(&secrets);
        assert_eq!(secrets.get(Secret::ApiSecret).unwrap(), "the-old-key");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_old_link_whose_config_does_not_name_its_account_asks_the_server() {
        let (base, server) = scripted_server(vec![r#"{"data":{"user_id":12}}"#]);
        let mut old = config(&base);
        old.account_id = None;
        let previous = Previous {
            config: old,
            secret: Some("old-secret".into()),
        };
        assert_eq!(previous.account(), Some(12));
        let seen = server.join().unwrap();
        assert!(
            seen[0].starts_with("get /api/v1/auth/session "),
            "{}",
            seen[0]
        );
    }

    #[test]
    fn unlinking_where_the_key_cannot_be_reached_is_refused_and_changes_nothing() {
        let dir = temp("unlink-locked");
        let p = paths(&dir);
        let config = config("https://drive.example.org");
        config.save(&p.config_file()).unwrap();
        let keychain = StandInKeychain::new();
        stand_in(&keychain, &p)
            .set(Secret::ApiSecret, "the-key")
            .unwrap();
        keychain.set_locked(true);

        let refused = unlink(&p, &config, &stand_in(&keychain, &p)).err().unwrap();
        assert!(
            refused.contains("joinery-drive unlink from your desktop session"),
            "{refused}"
        );
        assert!(p.config_file().exists(), "still linked");
        keychain.set_locked(false);
        assert_eq!(
            stand_in(&keychain, &p).get(Secret::ApiSecret).unwrap(),
            "the-key"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn login_says_the_browser_opened_only_when_it_did() {
        let p = pending();
        let opened = instructions("https://drive.example.org", &p, true);
        assert!(opened.contains("(opened in your browser)"));
        assert!(opened.contains(&p.verify_url) && opened.contains(&p.link_code));

        // Nothing opened: say so by telling the person what to do, and still
        // give them both ways in.
        let not = instructions("https://drive.example.org", &p, false);
        assert!(!not.contains("opened in your browser"), "{not}");
        assert!(not.contains("Open that address in a browser"), "{not}");
        assert!(not.contains(&p.verify_url) && not.contains(&p.link_code));
    }

    #[test]
    fn an_approval_missing_its_credential_is_refused_rather_than_half_stored() {
        // Storing half a credential leaves a client that believes it is linked
        // and fails every request with an authentication error nobody can
        // explain.
        let data = json!({ "status": "approved", "public_key": "pk" });
        let err = string_field(&data, "secret_key").unwrap_err();
        assert!(matches!(err, LinkError::Contract("secret_key")));
    }

    #[test]
    fn an_empty_string_field_counts_as_missing() {
        let data = json!({ "link_code": "" });
        assert!(string_field(&data, "link_code").is_err());
    }

    #[test]
    fn a_denial_and_an_expiry_are_outcomes_rather_than_errors() {
        // A user clicking "not me" is doing exactly what that button is for.
        for (status, expect_denied) in [("denied", true), ("expired", false)] {
            let outcome = match status {
                "denied" => Outcome::Denied,
                _ => Outcome::Expired,
            };
            assert_eq!(matches!(outcome, Outcome::Denied), expect_denied);
        }
    }

    #[test]
    fn the_poll_interval_fits_the_ceremony_inside_its_rate_bucket() {
        // Ten minutes of polling must stay under the 600-per-hour bucket the
        // server sizes for this endpoint, with room for a retry or two.
        let polls = (10 * 60) / POLL_INTERVAL.as_secs();
        assert!(polls <= 600, "{polls} polls would exhaust the bucket");
        assert!(
            POLL_INTERVAL.as_secs() <= 5,
            "slower than this and approval feels broken"
        );
    }
}
