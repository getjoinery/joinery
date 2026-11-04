//! VaultCrypto parity: the Sealed Vault's shared client-custody primitives.
//! Scope-agnostic, exactly like the JS — callers pass scopes/AD strings in.
//!
//! Key hierarchy (mirrors assets/js/vault-crypto.js):
//!   unlocker --KEK--> vault X-Wing seed (32 bytes) --seals--> a data key (DEK)
//!                                                        --encrypts--> content

use aes_gcm::aead::{Aead, Payload};
use aes_gcm::{Aes256Gcm, KeyInit, Nonce};
use argon2::{Algorithm, Argon2, Params, Version};
use hkdf::Hkdf;
use rand_core::{CryptoRng, OsRng, RngCore};
use sha2::{Digest, Sha256};
use zeroize::{Zeroize, ZeroizeOnDrop};

use crate::{b64, xwing, CryptoError, Result};

/// HKDF info of a v2 seal: this prefix, then the scope (R2).
const V2_INFO_PREFIX: &[u8] = b"joinery-seal:v2:";
/// The scope of every key seal the sync client writes or opens.
pub const SCOPE_DEK: &str = "dek";

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

/// A vault identity: the 1216-byte X-Wing public key (std base64, stored
/// cleartext server-side) and the 32-byte seed it is generated from (wrapped
/// under each unlocker's KEK, or sealed to a device key, never stored
/// unwrapped).
#[derive(Zeroize, ZeroizeOnDrop)]
pub struct VaultKeypair {
    #[zeroize(skip)]
    pub public_key_b64: String,
    pub secret_key: Vec<u8>,
}

pub fn generate_vault_keypair() -> VaultKeypair {
    generate_vault_keypair_with_rng(OsRng)
}

pub fn generate_vault_keypair_with_rng<R: RngCore + CryptoRng>(mut rng: R) -> VaultKeypair {
    let mut seed = [0u8; xwing::SEED_BYTES];
    rng.fill_bytes(&mut seed);
    let kp = VaultKeypair {
        public_key_b64: b64::encode(&xwing::public_key(&seed).expect("a 32-byte seed")),
        secret_key: seed.to_vec(),
    };
    seed.zeroize();
    kp
}

/// The public half of a vault secret key, recomputed from the secret itself.
///
/// A holder of the secret key needs the public one too: the opener checks the
/// seal names it. Derived rather than
/// stored alongside: a pair carried as two fields can be got out of step by a
/// bad write or a stale config, and the symptom is every unwrap failing with an
/// opaque decryption error that names nothing.
///
/// The secret is the 32-byte X-Wing seed; anything else is refused.
pub fn public_key_from_secret_key(secret_key: &[u8]) -> Result<String> {
    if secret_key.len() != xwing::SEED_BYTES {
        return Err(CryptoError::Malformed("a vault secret key is a 32-byte seed"));
    }
    Ok(b64::encode(&xwing::public_key(secret_key)?))
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

// ---- seal / open to a public key (v2: X-Wing) -------------------------------
// `b64(ct[1120] || IV[12] || AES-256-GCM ct+tag)`, no AAD: a key seal binds to
// its row through the row's own AEAD. The AES key is HKDF-SHA256 over the
// X-Wing shared secret, salt empty, info 'joinery-seal:v2:' || scope. The
// sync client seals and opens only scope 'dek' (a file key, a device handoff);
// `seal_v2_with` takes a scope so the known-answer vectors of every scope can
// be reproduced.

fn v2_key(shared: &[u8; 32], scope: &str) -> [u8; 32] {
    let mut info = Vec::with_capacity(V2_INFO_PREFIX.len() + scope.len());
    info.extend_from_slice(V2_INFO_PREFIX);
    info.extend_from_slice(scope.as_bytes());
    let hk = Hkdf::<Sha256>::new(None, shared);
    let mut okm = [0u8; 32];
    hk.expand(&info, &mut okm)
        .expect("32 bytes is a valid HKDF-SHA256 output length");
    okm
}

/// The raw v2 seal of `data` to a 1216-byte key, given its encapsulation
/// randomness and IV. Only the known-answer vectors pass their own;
/// `seal_to_public_key` draws both fresh.
pub fn seal_v2_with(
    data: &[u8],
    recipient: &[u8],
    scope: &str,
    eseed: &[u8; xwing::ENCAPS_SEED_BYTES],
    iv: &[u8; 12],
) -> Result<Vec<u8>> {
    let (ct, mut ss) = xwing::encapsulate_with(recipient, eseed)?;
    let mut key = v2_key(&ss, scope);
    ss.zeroize();
    let sealed = aes(&key)
        .encrypt(Nonce::from_slice(iv), data)
        .map_err(|_| CryptoError::DecryptFailed);
    key.zeroize();
    let sealed = sealed?;
    let mut blob = Vec::with_capacity(ct.len() + 12 + sealed.len());
    blob.extend_from_slice(&ct);
    blob.extend_from_slice(iv);
    blob.extend_from_slice(&sealed);
    Ok(blob)
}

/// Open a raw v2 seal with the 32-byte seed under `scope`.
pub fn open_v2(raw: &[u8], seed: &[u8], scope: &str) -> Result<Vec<u8>> {
    if seed.len() != xwing::SEED_BYTES {
        return Err(CryptoError::Malformed(
            "this is sealed to a new key, and the secret given is not one",
        ));
    }
    if raw.len() < xwing::CIPHERTEXT_BYTES + 12 + 16 {
        return Err(CryptoError::Malformed("sealed blob too short"));
    }
    let (ct, rest) = raw.split_at(xwing::CIPHERTEXT_BYTES);
    let mut ss = xwing::decapsulate(seed, ct)?;
    let mut key = v2_key(&ss, scope);
    ss.zeroize();
    let opened = aes(&key)
        .decrypt(Nonce::from_slice(&rest[..12]), &rest[12..])
        .map_err(|_| CryptoError::DecryptFailed);
    key.zeroize();
    opened
}

/// Seal bytes to a recipient's 1216-byte X-Wing public key, scope 'dek'.
/// Anyone can seal.
pub fn seal_to_public_key(data: &[u8], recipient_public_key_b64: &str) -> Result<String> {
    seal_to_public_key_with_rng(data, recipient_public_key_b64, OsRng)
}

pub fn seal_to_public_key_with_rng<R: RngCore + CryptoRng>(
    data: &[u8],
    recipient_public_key_b64: &str,
    mut rng: R,
) -> Result<String> {
    let recipient = b64::decode(recipient_public_key_b64)?;
    if recipient.len() != xwing::PUBLIC_KEY_BYTES {
        return Err(CryptoError::Malformed("that is not a public key to seal to"));
    }
    let mut eseed = [0u8; xwing::ENCAPS_SEED_BYTES];
    rng.fill_bytes(&mut eseed);
    let mut iv = [0u8; 12];
    rng.fill_bytes(&mut iv);
    let blob = seal_v2_with(data, &recipient, SCOPE_DEK, &eseed, &iv);
    eseed.zeroize();
    Ok(b64::encode(&blob?))
}

/// Open a key seal (scope 'dek') sealed to the 1216-byte
/// `recipient_public_key_b64` with its 32-byte seed.
///
/// The open first checks that the named public key is the seed's own, as
/// `SealedBox::openEdge()` does. X-Wing already binds the ciphertext to the
/// real recipient, so this adds no security; it turns a key pair that has got
/// out of step into an error that says so, rather than an opaque decryption
/// failure.
pub fn open_from_secret_key(
    sealed_b64: &str,
    secret_key: &[u8],
    recipient_public_key_b64: &str,
) -> Result<Vec<u8>> {
    let raw = b64::decode(sealed_b64)?;
    let recipient = b64::decode(recipient_public_key_b64)?;
    if recipient.len() != xwing::PUBLIC_KEY_BYTES {
        return Err(CryptoError::Malformed("that is not a public key"));
    }
    if secret_key.len() != xwing::SEED_BYTES {
        return Err(CryptoError::Malformed(
            "this is sealed to a new key, and the secret given is not one",
        ));
    }
    if !constant_time_eq(&xwing::public_key(secret_key)?, &recipient) {
        return Err(CryptoError::Malformed("the public key named is not this secret key's"));
    }
    open_v2(&raw, secret_key, SCOPE_DEK)
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

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    a.len() == b.len() && a.iter().zip(b).fold(0u8, |acc, (x, y)| acc | (x ^ y)) == 0
}

pub fn random_bytes<const N: usize>() -> [u8; N] {
    let mut b = [0u8; N];
    OsRng.fill_bytes(&mut b);
    b
}
