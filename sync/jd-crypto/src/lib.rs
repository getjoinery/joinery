//! jd-crypto — native reimplementation of the platform's client-custody crypto:
//! `assets/js/vault-crypto.js` (VaultCrypto) + `assets/js/drive-crypto.js`
//! (DriveCrypto). Every format here must byte-match what the browser produces
//! and consumes; cross-implementation parity is enforced by
//! `tests/functional/drive/sync_crypto_parity_gate.sh` (Rust encrypts / Node
//! decrypts, and vice versa).
//!
//! Contract summary (specs/drive_sync_clients.md §III.1):
//! - Encodings: standard base64 (padded) on the wire. Vault public key = raw
//!   32-byte X25519, std base64. Vault secret key travels as PKCS8 DER.
//! - Sealed box: custom HKDF ECIES (NOT libsodium crypto_box_seal) —
//!   `b64(ephPub[32] || IV[12] || AES-256-GCM ct+tag)`, key =
//!   HKDF-SHA256(salt = empty, ikm = X25519(eph, recipient),
//!   info = "sealed-vault:dek" || ephPub || recipientPub), no AAD.
//! - Content container: per 4 MiB plaintext chunk,
//!   `uint32be(blockLen) || IV[12] || AES-256-GCM(ct+tag)`,
//!   AAD = utf8(contentId + ":" + chunkIndex); empty file = one empty chunk.
//! - Metadata blob: `b64(IV[12] || ct)` of the JSON under the file key, no AAD.
//! - Thumbnail: raw `IV[12] || ct`, AAD = contentId + ":thumb".
//! - Unlockers: Argon2id passphrase KEK, SHA-256(salt || code) recovery KEK
//!   with Crockford normalization.

pub mod b64;
pub mod drive;
pub mod pkcs8;
pub mod vault;

/// Every failure mode a caller can act on. AEAD failures are deliberately
/// opaque (`DecryptFailed`) — a tag mismatch must not reveal which byte broke.
#[derive(Debug, thiserror::Error)]
pub enum CryptoError {
    #[error("malformed input: {0}")]
    Malformed(&'static str),
    #[error("base64 decode failed")]
    Base64,
    #[error("decryption failed (wrong key, wrong AAD, or tampered ciphertext)")]
    DecryptFailed,
    #[error("key derivation failed: {0}")]
    Kdf(&'static str),
    #[error("non-contributory X25519 shared secret")]
    BadPublicKey,
    #[error("metadata JSON: {0}")]
    Json(#[from] serde_json::Error),
}

pub type Result<T> = std::result::Result<T, CryptoError>;
