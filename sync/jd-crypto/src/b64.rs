//! Wire encoding: standard base64 with padding, exactly like the browser's
//! `btoa` path. WebAuthn credential ids / PRF outputs are base64url — those
//! never reach this crate (browser-only ceremony), but the lenient decoder
//! below accepts both so CLI recovery inputs can't trip on the variant.

use base64::engine::general_purpose::STANDARD;
use base64::engine::{DecodePaddingMode, GeneralPurpose, GeneralPurposeConfig};
use base64::{alphabet, Engine};

use crate::{CryptoError, Result};

/// `atob` accepts unpadded input; mirror that leniency on decode.
const LENIENT_CONFIG: GeneralPurposeConfig =
    GeneralPurposeConfig::new().with_decode_padding_mode(DecodePaddingMode::Indifferent);
const STANDARD_LENIENT: GeneralPurpose = GeneralPurpose::new(&alphabet::STANDARD, LENIENT_CONFIG);

pub fn encode(bytes: &[u8]) -> String {
    STANDARD.encode(bytes)
}

pub fn decode(s: &str) -> Result<Vec<u8>> {
    STANDARD_LENIENT.decode(s).map_err(|_| CryptoError::Base64)
}
