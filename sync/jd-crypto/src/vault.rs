//! VaultCrypto parity: the Sealed Vault's shared client-custody primitives.
//! Scope-agnostic, exactly like the JS — callers pass scopes/AD strings in.
//!
//! Key hierarchy (mirrors assets/js/vault-crypto.js):
//!   unlocker --KEK--> vault X25519 secret key --seals--> a data key (DEK)
//!                                                        --encrypts--> content

use aes_gcm::aead::{Aead, Payload};
use aes_gcm::{Aes256Gcm, KeyInit, Nonce};
use argon2::{Algorithm, Argon2, Params, Version};
use hkdf::Hkdf;
use rand_core::{CryptoRng, OsRng, RngCore};
use sha2::{Digest, Sha256};
use x25519_dalek::{PublicKey, StaticSecret};
use zeroize::{Zeroize, ZeroizeOnDrop};

use crate::{b64, pkcs8, CryptoError, Result};

/// HKDF info prefix binding a sealed box to the sealed-vault domain.
const SEAL_INFO_PREFIX: &[u8] = b"sealed-vault:dek";

/// Argon2id defaults — must match `VaultCrypto.DEFAULT_KDF_PARAMS`
/// (RFC 9106 second recommendation / Bitwarden default). `mem` is KiB.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct KdfParams {
    pub mem: u32,
    pub time: u32,
    pub parallelism: u32,
    pub hash_len: usize,
}

impl Default for KdfParams {
    fn default() -> Self {
        KdfParams {
            mem: 65536,
            time: 3,
            parallelism: 4,
            hash_len: 32,
        }
    }
}

/// A vault identity: raw 32-byte public key (std base64, stored cleartext
/// server-side) and the PKCS8-encoded secret key (wrapped under each
/// unlocker's KEK, or sealed to a device keypair — never stored unwrapped).
#[derive(Zeroize, ZeroizeOnDrop)]
pub struct VaultKeypair {
    #[zeroize(skip)]
    pub public_key_b64: String,
    pub secret_key_pkcs8: Vec<u8>,
}

pub fn generate_vault_keypair() -> VaultKeypair {
    generate_vault_keypair_with_rng(OsRng)
}

pub fn generate_vault_keypair_with_rng<R: RngCore + CryptoRng>(rng: R) -> VaultKeypair {
    let secret = StaticSecret::random_from_rng(rng);
    let public = PublicKey::from(&secret);
    VaultKeypair {
        public_key_b64: b64::encode(public.as_bytes()),
        secret_key_pkcs8: pkcs8::encode(secret.as_bytes()),
    }
}

// ---- KEK derivation (one per unlocker type) --------------------------------

/// Recovery-code KEK: SHA-256(salt || normalized code). Codes carry >=128 bits
/// of entropy, so a fast hash is correct — a slow KDF buys nothing.
/// Normalization is Crockford leniency: uppercase, O→0, I/L→1, strip
/// everything outside [A-Z0-9].
pub fn kek_from_recovery_code(code: &str, salt_b64: &str) -> Result<[u8; 32]> {
    let normalized: String = code
        .to_uppercase()
        .chars()
        .map(|c| match c {
            'O' => '0',
            'I' | 'L' => '1',
            c => c,
        })
        .filter(|c| c.is_ascii_uppercase() || c.is_ascii_digit())
        .collect();
    let salt = b64::decode(salt_b64)?;
    let mut hasher = Sha256::new();
    hasher.update(&salt);
    hasher.update(normalized.as_bytes());
    Ok(hasher.finalize().into())
}

/// Passphrase KEK: memory-hard Argon2id (the low-entropy fallback unlocker).
pub fn kek_from_passphrase(
    passphrase: &str,
    salt_b64: &str,
    params: &KdfParams,
) -> Result<[u8; 32]> {
    if params.hash_len != 32 {
        return Err(CryptoError::Kdf("KEK hash_len must be 32"));
    }
    let salt = b64::decode(salt_b64)?;
    let argon_params = Params::new(params.mem, params.time, params.parallelism, Some(32))
        .map_err(|_| CryptoError::Kdf("invalid Argon2 parameters"))?;
    let argon = Argon2::new(Algorithm::Argon2id, Version::V0x13, argon_params);
    let mut out = [0u8; 32];
    argon
        .hash_password_into(passphrase.as_bytes(), &salt, &mut out)
        .map_err(|_| CryptoError::Kdf("Argon2id derivation failed"))?;
    Ok(out)
}

// ---- wrap / unwrap the vault secret key under a KEK (the uew blob) ----------

/// `b64(IV[12] || AES-256-GCM ct)`, AD = the caller's row-binding string
/// (e.g. "vault:drive:passkey:42") so a wrapping can't be spliced onto
/// another row and still open.
pub fn wrap_secret_key(secret_key: &[u8], kek: &[u8; 32], ad: &str) -> Result<String> {
    wrap_secret_key_with_rng(secret_key, kek, ad, OsRng)
}

pub fn wrap_secret_key_with_rng<R: RngCore + CryptoRng>(
    secret_key: &[u8],
    kek: &[u8; 32],
    ad: &str,
    mut rng: R,
) -> Result<String> {
    let cipher = aes(kek);
    let mut iv = [0u8; 12];
    rng.fill_bytes(&mut iv);
    let ct = cipher
        .encrypt(
            Nonce::from_slice(&iv),
            Payload {
                msg: secret_key,
                aad: ad.as_bytes(),
            },
        )
        .map_err(|_| CryptoError::DecryptFailed)?;
    let mut blob = Vec::with_capacity(12 + ct.len());
    blob.extend_from_slice(&iv);
    blob.extend_from_slice(&ct);
    Ok(b64::encode(&blob))
}

pub fn unwrap_secret_key(blob: &str, kek: &[u8; 32], ad: &str) -> Result<Vec<u8>> {
    let raw = b64::decode(blob)?;
    if raw.len() < 12 + 16 {
        return Err(CryptoError::Malformed("wrapped key blob too short"));
    }
    let cipher = aes(kek);
    cipher
        .decrypt(
            Nonce::from_slice(&raw[..12]),
            Payload {
                msg: &raw[12..],
                aad: ad.as_bytes(),
            },
        )
        .map_err(|_| CryptoError::DecryptFailed)
}

// ---- seal / open to the vault keypair (HKDF ECIES over X25519) --------------

fn seal_key(shared: &[u8], eph_pub: &[u8; 32], recipient_pub: &[u8; 32]) -> [u8; 32] {
    let mut info = Vec::with_capacity(SEAL_INFO_PREFIX.len() + 64);
    info.extend_from_slice(SEAL_INFO_PREFIX);
    info.extend_from_slice(eph_pub);
    info.extend_from_slice(recipient_pub);
    // WebCrypto derives with an explicit zero-length salt; RFC 5869 maps the
    // absent salt to the same HMAC key, so `None` here is byte-identical.
    let hk = Hkdf::<Sha256>::new(None, shared);
    let mut okm = [0u8; 32];
    hk.expand(&info, &mut okm)
        .expect("32 bytes is a valid HKDF-SHA256 output length");
    okm
}

/// Seal bytes to a recipient's vault public key:
/// `b64(ephPub[32] || IV[12] || AES-256-GCM ct)`. Anyone can seal.
pub fn seal_to_public_key(data: &[u8], recipient_public_key_b64: &str) -> Result<String> {
    seal_to_public_key_with_rng(data, recipient_public_key_b64, OsRng)
}

pub fn seal_to_public_key_with_rng<R: RngCore + CryptoRng>(
    data: &[u8],
    recipient_public_key_b64: &str,
    mut rng: R,
) -> Result<String> {
    let recipient_bytes = decode_public_key(recipient_public_key_b64)?;
    let recipient = PublicKey::from(recipient_bytes);
    let eph = StaticSecret::random_from_rng(&mut rng);
    let eph_pub = PublicKey::from(&eph);
    let shared = eph.diffie_hellman(&recipient);
    if !shared.was_contributory() {
        return Err(CryptoError::BadPublicKey);
    }
    let key = seal_key(shared.as_bytes(), eph_pub.as_bytes(), &recipient_bytes);
    let cipher = aes(&key);
    let mut iv = [0u8; 12];
    rng.fill_bytes(&mut iv);
    let ct = cipher
        .encrypt(Nonce::from_slice(&iv), data)
        .map_err(|_| CryptoError::DecryptFailed);
    let ct = ct?;
    let mut blob = Vec::with_capacity(32 + 12 + ct.len());
    blob.extend_from_slice(eph_pub.as_bytes());
    blob.extend_from_slice(&iv);
    blob.extend_from_slice(&ct);
    Ok(b64::encode(&blob))
}

/// Open a sealed blob with the vault secret key (PKCS8). The recipient public
/// key is required because it is bound into the KDF info — a blob sealed to a
/// different keypair can never open, even with a colluding secret key.
pub fn open_from_secret_key(
    sealed_b64: &str,
    secret_key_pkcs8: &[u8],
    recipient_public_key_b64: &str,
) -> Result<Vec<u8>> {
    let raw = b64::decode(sealed_b64)?;
    if raw.len() < 32 + 12 + 16 {
        return Err(CryptoError::Malformed("sealed blob too short"));
    }
    let mut eph_pub_bytes = [0u8; 32];
    eph_pub_bytes.copy_from_slice(&raw[..32]);
    let recipient_bytes = decode_public_key(recipient_public_key_b64)?;
    let mut scalar = pkcs8::decode(secret_key_pkcs8)?;
    let secret = StaticSecret::from(scalar);
    scalar.zeroize();
    let shared = secret.diffie_hellman(&PublicKey::from(eph_pub_bytes));
    if !shared.was_contributory() {
        return Err(CryptoError::BadPublicKey);
    }
    let key = seal_key(shared.as_bytes(), &eph_pub_bytes, &recipient_bytes);
    let cipher = aes(&key);
    cipher
        .decrypt(Nonce::from_slice(&raw[32..44]), &raw[44..])
        .map_err(|_| CryptoError::DecryptFailed)
}

// ---- content blobs under a DEK/FK (the plain IV||ct contract, no AAD) -------

/// `VaultCrypto.encrypt`: `b64(IV[12] || ct)` of a UTF-8 string under an
/// AES-256 key. Drive's metadata blob rides this exact contract.
pub fn encrypt_string(plaintext: &str, key: &[u8; 32]) -> Result<String> {
    encrypt_string_with_rng(plaintext, key, OsRng)
}

pub fn encrypt_string_with_rng<R: RngCore + CryptoRng>(
    plaintext: &str,
    key: &[u8; 32],
    mut rng: R,
) -> Result<String> {
    let cipher = aes(key);
    let mut iv = [0u8; 12];
    rng.fill_bytes(&mut iv);
    let ct = cipher
        .encrypt(Nonce::from_slice(&iv), plaintext.as_bytes())
        .map_err(|_| CryptoError::DecryptFailed)?;
    let mut blob = Vec::with_capacity(12 + ct.len());
    blob.extend_from_slice(&iv);
    blob.extend_from_slice(&ct);
    Ok(b64::encode(&blob))
}

pub fn decrypt_string(blob: &str, key: &[u8; 32]) -> Result<String> {
    let raw = b64::decode(blob)?;
    if raw.len() < 12 + 16 {
        return Err(CryptoError::Malformed("content blob too short"));
    }
    let cipher = aes(key);
    let pt = cipher
        .decrypt(Nonce::from_slice(&raw[..12]), &raw[12..])
        .map_err(|_| CryptoError::DecryptFailed)?;
    String::from_utf8(pt).map_err(|_| CryptoError::Malformed("decrypted content is not UTF-8"))
}

// ---- shared helpers ---------------------------------------------------------

pub(crate) fn aes(key: &[u8; 32]) -> Aes256Gcm {
    Aes256Gcm::new_from_slice(key).expect("32-byte AES-256 key")
}

pub(crate) fn decode_public_key(public_key_b64: &str) -> Result<[u8; 32]> {
    let bytes = b64::decode(public_key_b64)?;
    bytes
        .try_into()
        .map_err(|_| CryptoError::Malformed("vault public key must be raw 32 bytes"))
}

pub fn random_bytes<const N: usize>() -> [u8; N] {
    let mut b = [0u8; N];
    OsRng.fill_bytes(&mut b);
    b
}
