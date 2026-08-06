//! The key this device holds for encrypted folders, and what it can open.
//!
//! Encrypted Drive content is sealed to a **vault** — an X25519 keypair whose
//! secret half never leaves the user's own machines. Every encrypted file has
//! its own random content key, and each reader gets that content key sealed to
//! their vault (a *grant*, `wrapped_file_key`). So opening a file is two steps:
//! unseal the grant with the vault key, then decrypt the content with what came
//! out. This type owns the first step. Nothing else in the engine holds key
//! material.
//!
//! Three properties are deliberate.
//!
//! **The vault key arrives at link time or not at all.** It is handed over
//! during the browser ceremony, sealed to a keypair this device generated for
//! that one purpose, and stored in the operating system's credential store. It
//! cannot be derived from anything on disk and the server never has it, so a
//! device that did not get one is not one grant away from reading encrypted
//! files — it is one *link* away, and the engine says so rather than retrying.
//!
//! **The public half is derived, never carried.** See
//! [`jd_crypto::vault::public_key_from_secret_key`].
//!
//! **A vault is optional and its absence is ordinary.** A user who has not
//! turned on encrypted folders, or who linked this laptop without them, has an
//! account that syncs perfectly well. Everything here is reached through an
//! `Option`, and the `None` arm is a supported state rather than a failure to
//! report.

use jd_crypto::drive::FileKey;
use zeroize::Zeroizing;

#[derive(Debug, thiserror::Error)]
pub enum VaultError {
    /// The stored bytes are not a vault secret key at all — a truncated write,
    /// or a credential store handing back something else under this name.
    #[error("this device's stored vault key is not usable: {0}")]
    Unusable(String),
    /// The grant will not open with this vault key. Not retried and not
    /// reported as corruption: the ordinary cause is a grant issued to a
    /// vault the user has since replaced, and the fix is a fresh grant.
    #[error("this file's key was not issued to this device's vault")]
    NotForThisVault,
}

pub type VaultResult<T> = Result<T, VaultError>;

/// This device's copy of the Drive vault identity.
pub struct Vault {
    /// PKCS8 DER, as WebCrypto exports it and as the ceremony hands it over.
    /// Wrapped so it is wiped when the daemon stops rather than left in a freed
    /// page.
    secret_key_pkcs8: Zeroizing<Vec<u8>>,
    public_key_b64: String,
}

impl Vault {
    /// Take custody of a vault secret key.
    ///
    /// Fails rather than storing something unusable, because the alternative is
    /// a daemon that starts, reports encrypted folders as enabled, and refuses
    /// every file for a reason that points at the files.
    pub fn from_secret_key(secret_key_pkcs8: &[u8]) -> VaultResult<Vault> {
        let public_key_b64 = jd_crypto::vault::public_key_from_secret_key(secret_key_pkcs8)
            .map_err(|e| VaultError::Unusable(e.to_string()))?;
        Ok(Vault {
            secret_key_pkcs8: Zeroizing::new(secret_key_pkcs8.to_vec()),
            public_key_b64,
        })
    }

    /// The vault's public key, which is what grants are sealed to. Needed when
    /// this device seals a key of its own — encrypting a new file means sealing
    /// its content key back to the vault so every other device can read it.
    pub fn public_key_b64(&self) -> &str {
        &self.public_key_b64
    }

    /// Turn one file's grant into the key that decrypts its content.
    pub fn open_file_key(&self, wrapped_file_key: &str) -> VaultResult<FileKey> {
        jd_crypto::drive::open_wrapped_file_key(
            wrapped_file_key,
            &self.secret_key_pkcs8,
            &self.public_key_b64,
        )
        .map_err(|_| VaultError::NotForThisVault)
    }
}

impl std::fmt::Debug for Vault {
    /// Prints the public key and says the secret exists. A derived `Debug` would
    /// put the vault key into any log line that formatted an `ExecEnv`.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Vault")
            .field("public_key_b64", &self.public_key_b64)
            .field("secret_key_pkcs8", &"<held>")
            .finish()
    }
}

/// What this device can do with an encrypted entry, right now.
///
/// Kept separate from the crypto so the engine can ask the cheap structural
/// question — is there a key at all — on every entry on every pass, without
/// doing scalar multiplication to find out.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KeyState {
    /// This device has no vault key. Nothing encrypted can be read here until
    /// the user links it again with encrypted folders enabled.
    NoVault,
    /// There is a vault, but no grant has arrived for this entry. Ordinary and
    /// often temporary: the grant travels on its own schedule, and for a file
    /// shared by somebody else it arrives when they grant it.
    NoGrant,
    /// A vault and a grant. Whether the grant actually opens is not asked here.
    Grantable,
}

/// Ask the structural question about one entry.
pub fn key_state(vault: Option<&Vault>, wrapped_file_key: Option<&str>) -> KeyState {
    match (vault, wrapped_file_key) {
        (None, _) => KeyState::NoVault,
        (Some(_), None) => KeyState::NoGrant,
        (Some(_), Some(_)) => KeyState::Grantable,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use jd_crypto::vault as vc;

    fn vault() -> (Vault, String) {
        let kp = vc::generate_vault_keypair();
        let public = kp.public_key_b64.clone();
        (
            Vault::from_secret_key(&kp.secret_key_pkcs8).unwrap(),
            public,
        )
    }

    #[test]
    fn a_grant_sealed_to_this_vault_opens_to_the_file_key_that_was_sealed() {
        let (v, public) = vault();
        let fk = FileKey::generate();
        let grant = jd_crypto::drive::wrap_file_key_to(&fk, &public).unwrap();
        assert_eq!(v.open_file_key(&grant).unwrap().0, fk.0);
    }

    #[test]
    fn the_derived_public_key_is_the_one_grants_must_be_sealed_to() {
        // The device stores only the secret half, so this is the only address a
        // grant can be issued to. If derivation were wrong, sealing to the
        // reported key would produce a grant the same device could not open.
        let (v, _) = vault();
        let fk = FileKey::generate();
        let grant = jd_crypto::drive::wrap_file_key_to(&fk, v.public_key_b64()).unwrap();
        assert_eq!(v.open_file_key(&grant).unwrap().0, fk.0);
    }

    #[test]
    fn a_grant_issued_to_a_different_vault_is_refused_by_name() {
        // The case that actually happens: the user replaced their vault, and
        // grants issued to the old one are still on the files. Calling that
        // corruption would send somebody looking at the file.
        let (mine, _) = vault();
        let (_, theirs) = vault();
        let grant = jd_crypto::drive::wrap_file_key_to(&FileKey::generate(), &theirs).unwrap();
        assert!(matches!(
            mine.open_file_key(&grant),
            Err(VaultError::NotForThisVault)
        ));
    }

    #[test]
    fn a_tampered_grant_is_refused_rather_than_opened_to_something() {
        let (v, public) = vault();
        let grant = jd_crypto::drive::wrap_file_key_to(&FileKey::generate(), &public).unwrap();
        let mut bytes = jd_crypto::b64::decode(&grant).unwrap();
        let last = bytes.len() - 1;
        bytes[last] ^= 0xff;
        assert!(v.open_file_key(&jd_crypto::b64::encode(&bytes)).is_err());
        assert!(v.open_file_key("not base64 at all !!").is_err());
    }

    #[test]
    fn a_stored_key_that_is_not_a_vault_key_is_refused_at_custody() {
        // Refused here rather than at the first file, so the daemon can say
        // "this device's key is unusable" instead of blaming every file it
        // cannot open.
        assert!(matches!(
            Vault::from_secret_key(b"truncated"),
            Err(VaultError::Unusable(_))
        ));
    }

    #[test]
    fn debug_output_never_carries_the_secret_key() {
        // An ExecEnv is formatted in error paths; the vault key must not ride
        // along into a log file.
        let kp = vc::generate_vault_keypair();
        let v = Vault::from_secret_key(&kp.secret_key_pkcs8).unwrap();
        let shown = format!("{v:?}");
        assert!(shown.contains(&kp.public_key_b64));
        for byte in kp.secret_key_pkcs8.iter() {
            assert!(!shown.contains(&format!("{byte}, ")));
        }
    }

    #[test]
    fn the_cheap_question_tells_no_key_apart_from_no_grant() {
        // They need opposite advice: one is fixed by linking this device again,
        // the other by somebody granting access, and neither is fixed by waiting
        // for the other.
        let (v, _) = vault();
        assert_eq!(key_state(None, Some("grant")), KeyState::NoVault);
        assert_eq!(key_state(None, None), KeyState::NoVault);
        assert_eq!(key_state(Some(&v), None), KeyState::NoGrant);
        assert_eq!(key_state(Some(&v), Some("grant")), KeyState::Grantable);
    }
}
