//! X-Wing (draft-connolly-cfrg-xwing-kem-11): ML-KEM-768 and X25519 in one
//! KEM, the one every runtime seals to (specs/post_quantum_sealing.md R1).
//! Composed here from `ml-kem`, `x25519-dalek`, `sha3` and `shake` exactly as
//! `includes/XWing.php` composes it, and held to the same known answers
//! (`public_html/tests/vault/fixtures/xwing_vector.json`, tests/xwing_vector.rs).
//!
//! - Secret: a 32-byte seed. SHAKE256(seed, 96) gives ML-KEM's d || z (64) and
//!   the X25519 scalar (32).
//! - Public: ML-KEM encapsulation key (1184) || X25519 public (32) = 1216.
//! - Ciphertext: ML-KEM ciphertext (1088) || X25519 ephemeral public (32) = 1120.
//! - Shared secret: SHA3-256(ss_M || ss_X || ct_X || pk_X || label).

use ml_kem::ml_kem_768::{Ciphertext, DecapsulationKey, EncapsulationKey};
use ml_kem::{B32, Decapsulate, Key, KeyExport, Seed};
use rand_core::{CryptoRng, RngCore};
use sha3::{Digest, Sha3_256};
use shake::{ExtendableOutput, Shake256, Update, XofReader};
use x25519_dalek::{PublicKey, StaticSecret};
use zeroize::Zeroize;

use crate::{CryptoError, Result};

pub const SEED_BYTES: usize = 32;
pub const PUBLIC_KEY_BYTES: usize = 1216;
pub const CIPHERTEXT_BYTES: usize = 1120;
/// Encapsulation randomness: ML-KEM's m (32) then the X25519 ephemeral (32).
pub const ENCAPS_SEED_BYTES: usize = 64;

const MLKEM_PUBLIC_BYTES: usize = 1184;
const MLKEM_CIPHERTEXT_BYTES: usize = 1088;
const LABEL: &[u8] = b"\x5c\x2e\x2f\x2f\x5e\x5c";

/// The seed's two halves: ML-KEM's decapsulation key and the X25519 secret.
struct Expanded {
    dk: DecapsulationKey,
    sk_x: StaticSecret,
}

fn expand(seed: &[u8]) -> Result<Expanded> {
    if seed.len() != SEED_BYTES {
        return Err(CryptoError::Malformed("an X-Wing secret is a 32-byte seed"));
    }
    let mut out = [0u8; 96];
    let mut xof = Shake256::default();
    xof.update(seed);
    xof.finalize_xof().read(&mut out);
    let mut m_seed = Seed::try_from(&out[..64]).expect("64 bytes");
    let dk = DecapsulationKey::from_seed(m_seed);
    m_seed.zeroize();
    let mut x = [0u8; 32];
    x.copy_from_slice(&out[64..]);
    out.zeroize();
    let sk_x = StaticSecret::from(x);
    x.zeroize();
    Ok(Expanded { dk, sk_x })
}

/// The 1216-byte public key a seed generates.
pub fn public_key(seed: &[u8]) -> Result<Vec<u8>> {
    let e = expand(seed)?;
    let mut pk = Vec::with_capacity(PUBLIC_KEY_BYTES);
    pk.extend_from_slice(e.dk.encapsulation_key().to_bytes().as_slice());
    pk.extend_from_slice(PublicKey::from(&e.sk_x).as_bytes());
    Ok(pk)
}

fn combine(ss_m: &[u8], ss_x: &[u8], ct_x: &[u8], pk_x: &[u8]) -> [u8; 32] {
    let mut h = Sha3_256::new();
    Digest::update(&mut h, ss_m);
    Digest::update(&mut h, ss_x);
    Digest::update(&mut h, ct_x);
    Digest::update(&mut h, pk_x);
    Digest::update(&mut h, LABEL);
    h.finalize().into()
}

/// Encapsulate to a 1216-byte key with fresh randomness: (ciphertext, shared secret).
pub fn encapsulate<R: RngCore + CryptoRng>(public: &[u8], mut rng: R) -> Result<(Vec<u8>, [u8; 32])> {
    let mut eseed = [0u8; ENCAPS_SEED_BYTES];
    rng.fill_bytes(&mut eseed);
    let out = encapsulate_with(public, &eseed);
    eseed.zeroize();
    out
}

/// Encapsulate with the randomness given (draft § EncapsulateDerand). Only the
/// known-answer vectors pass their own; `encapsulate` draws it fresh.
pub fn encapsulate_with(public: &[u8], eseed: &[u8; ENCAPS_SEED_BYTES]) -> Result<(Vec<u8>, [u8; 32])> {
    if public.len() != PUBLIC_KEY_BYTES {
        return Err(CryptoError::Malformed("an X-Wing public key is 1216 bytes"));
    }
    let ek_bytes = Key::<EncapsulationKey>::try_from(&public[..MLKEM_PUBLIC_BYTES])
        .map_err(|_| CryptoError::Malformed("an X-Wing public key is 1216 bytes"))?;
    let ek = EncapsulationKey::new(&ek_bytes).map_err(|_| CryptoError::BadPublicKey)?;
    let mut pk_x = [0u8; 32];
    pk_x.copy_from_slice(&public[MLKEM_PUBLIC_BYTES..]);

    let m = B32::try_from(&eseed[..32]).expect("32 bytes");
    let (ct_m, mut ss_m) = ek.encapsulate_deterministic(&m);

    let mut ek_x_bytes = [0u8; 32];
    ek_x_bytes.copy_from_slice(&eseed[32..]);
    let ek_x = StaticSecret::from(ek_x_bytes);
    ek_x_bytes.zeroize();
    let ct_x = PublicKey::from(&ek_x);
    let ss_x = ek_x.diffie_hellman(&PublicKey::from(pk_x));
    if !ss_x.was_contributory() {
        return Err(CryptoError::BadPublicKey);
    }

    let ss = combine(ss_m.as_slice(), ss_x.as_bytes(), ct_x.as_bytes(), &pk_x);
    ss_m.zeroize();
    let mut ct = Vec::with_capacity(CIPHERTEXT_BYTES);
    ct.extend_from_slice(ct_m.as_slice());
    ct.extend_from_slice(ct_x.as_bytes());
    Ok((ct, ss))
}

/// The shared secret a 1120-byte ciphertext carries for this seed.
pub fn decapsulate(seed: &[u8], ciphertext: &[u8]) -> Result<[u8; 32]> {
    if ciphertext.len() != CIPHERTEXT_BYTES {
        return Err(CryptoError::Malformed("an X-Wing ciphertext is 1120 bytes"));
    }
    let e = expand(seed)?;
    let ct_m = Ciphertext::try_from(&ciphertext[..MLKEM_CIPHERTEXT_BYTES]).expect("1088 bytes");
    let mut ct_x = [0u8; 32];
    ct_x.copy_from_slice(&ciphertext[MLKEM_CIPHERTEXT_BYTES..]);
    let mut ss_m = e.dk.decapsulate(&ct_m);
    let ss_x = e.sk_x.diffie_hellman(&PublicKey::from(ct_x));
    if !ss_x.was_contributory() {
        return Err(CryptoError::BadPublicKey);
    }
    let pk_x = PublicKey::from(&e.sk_x);
    let ss = combine(ss_m.as_slice(), ss_x.as_bytes(), &ct_x, pk_x.as_bytes());
    ss_m.zeroize();
    Ok(ss)
}
