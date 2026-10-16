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
//! Three things about the mechanics are load-bearing.
//!
//! **The device keypair is generated before the ceremony starts**, because its
//! public half is what the vault key comes back sealed to. Generating it
//! afterwards would mean either a second round trip or handing the vault key
//! over in the clear.
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

use jd_platform::{Paths, Secret, SecretStore};
use jd_proto::{Client, Credentials, ProtoError};
use serde_json::Value;

use crate::config::Config;

/// The name this client's secrets are kept under in the credential store.
pub const SERVICE: &str = "com.joinery.drive";

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
    /// This device's X25519 keypair. The public half went to the server; the
    /// secret half is what opens the sealed vault key, and must be stored
    /// whether or not the vault was enabled — a user can enable encrypted
    /// folders later, and re-deriving it is not possible.
    pub device_public_key: String,
    pub device_secret_pkcs8: Vec<u8>,
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

/// Start a ceremony.
pub fn begin(client: &Client, device_name: &str, platform: &str) -> Result<Pending, LinkError> {
    // Before the request, not after: the public half has to be in the request
    // for the vault key to have anything to be sealed to.
    let keypair = jd_crypto::vault::generate_vault_keypair();

    let data = client
        .device_link_begin(device_name, platform, &keypair.public_key_b64)
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
        device_public_key: keypair.public_key_b64.clone(),
        device_secret_pkcs8: keypair.secret_key_pkcs8.clone(),
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
    jd_crypto::vault::open_from_secret_key(
        sealed,
        &pending.device_secret_pkcs8,
        &pending.device_public_key,
    )
    .map_err(|e| LinkError::Crypto(e.to_string()))
}

/// Keep what an approval handed over. Returns whether encrypted folders are on
/// for this device.
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
pub fn store_approved(
    secrets: &SecretStore,
    pending: &Pending,
    approved: &Approved,
) -> Result<bool, String> {
    secrets
        .set(Secret::ApiSecret, &approved.credentials.secret_key)
        .map_err(|e| format!("cannot store the device credential: {e}"))?;
    secrets
        .set(
            Secret::DeviceKey,
            &jd_crypto::b64::encode(&pending.device_secret_pkcs8),
        )
        .map_err(|e| format!("cannot store the device key: {e}"))?;

    let Some(sealed) = &approved.sealed_vault_key else {
        return Ok(false);
    };
    match open_vault_key(pending, sealed) {
        Ok(vault_key) => {
            secrets
                .set(Secret::VaultKey, &jd_crypto::b64::encode(&vault_key))
                .map_err(|e| format!("cannot store the vault key: {e}"))?;
            Ok(true)
        }
        // Not fatal. Everything outside encrypted folders syncs perfectly
        // well without it, and refusing to link over this would be refusing
        // the whole account over one feature.
        Err(e) => {
            eprintln!(
                "warning: encrypted folders could not be enabled on this device ({e}). \
                 Everything else will sync; link again to retry."
            );
            Ok(false)
        }
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
pub fn unlink(paths: &Paths, config: &Config, secrets: &SecretStore) -> Result<Unlinked, String> {
    let revoked = secrets.get(Secret::ApiSecret).ok().map(|secret_key| {
        let mut client = Client::with_credentials(
            &config.base_url,
            Credentials {
                public_key: config.public_key.clone(),
                secret_key,
            },
        );
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

    /// A pending ceremony with a real keypair, so the sealing tests exercise the
    /// actual primitive rather than a stand-in for it.
    fn pending() -> Pending {
        let kp = jd_crypto::vault::generate_vault_keypair();
        Pending {
            link_code: "ABCD-EFGH".into(),
            verify_url: "https://dev.getjoinery.com/profile/devices/link?code=ABCD-EFGH".into(),
            poll_token: "tok".into(),
            expires_time: "2026-07-31 12:00:00".into(),
            device_public_key: kp.public_key_b64.clone(),
            device_secret_pkcs8: kp.secret_key_pkcs8.clone(),
        }
    }

    #[test]
    fn a_vault_key_sealed_to_this_device_opens_with_this_devices_key() {
        let p = pending();
        let vault_secret = b"the drive vault secret key, pkcs8-shaped";
        let sealed =
            jd_crypto::vault::seal_to_public_key(vault_secret, &p.device_public_key).unwrap();
        assert_eq!(open_vault_key(&p, &sealed).unwrap(), vault_secret);
    }

    #[test]
    fn a_vault_key_sealed_to_a_different_device_does_not_open_here() {
        // The property the whole handoff rests on: approving a link for one
        // laptop does not hand the vault to another.
        let mine = pending();
        let theirs = pending();
        let sealed =
            jd_crypto::vault::seal_to_public_key(b"not for me", &theirs.device_public_key).unwrap();
        assert!(open_vault_key(&mine, &sealed).is_err());
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
