//! X25519 secret keys travel as PKCS8 DER (RFC 8410) because that is what
//! WebCrypto's `exportKey('pkcs8', …)` emits and `importKey('pkcs8', …)`
//! expects. For X25519 the encoding is a fixed 16-byte prefix followed by the
//! raw 32-byte scalar:
//!
//! ```text
//! 30 2e                SEQUENCE (46)
//!   02 01 00           INTEGER 0 (version)
//!   30 05 06 03 2b 65 6e   AlgorithmIdentifier { OID 1.3.101.110 (X25519) }
//!   04 22 04 20 <32B>  OCTET STRING { CurvePrivateKey OCTET STRING }
//! ```

use crate::{CryptoError, Result};

const X25519_PKCS8_PREFIX: [u8; 16] = [
    0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x6e, 0x04, 0x22, 0x04, 0x20,
];

/// Wrap a raw 32-byte X25519 scalar in the PKCS8 envelope WebCrypto emits.
pub fn encode(raw_secret: &[u8; 32]) -> Vec<u8> {
    let mut out = Vec::with_capacity(48);
    out.extend_from_slice(&X25519_PKCS8_PREFIX);
    out.extend_from_slice(raw_secret);
    out
}

/// Extract the raw scalar from a PKCS8 X25519 secret key. Only the minimal
/// 48-byte form (what WebCrypto exports) is accepted — anything else is a
/// malformed handoff, not a format to be lenient about.
pub fn decode(pkcs8: &[u8]) -> Result<[u8; 32]> {
    if pkcs8.len() != 48 || pkcs8[..16] != X25519_PKCS8_PREFIX {
        return Err(CryptoError::Malformed("not a PKCS8 X25519 secret key"));
    }
    let mut raw = [0u8; 32];
    raw.copy_from_slice(&pkcs8[16..]);
    Ok(raw)
}
