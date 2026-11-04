//! The shared known answers (specs/post_quantum_sealing.md R9):
//! `public_html/tests/vault/fixtures/xwing_vector.json`, the file PHP, Go and
//! the browser are held to. "xwing" is draft-connolly-cfrg-xwing-kem-11
//! Appendix C; "seal" is the Joinery v2 seal SealedBox.php wrote from fixed
//! inputs. Every value is reproduced byte for byte, not merely round-tripped.

use jd_crypto::{b64, vault, xwing, CryptoError};
use serde_json::Value;

fn vectors() -> Value {
    let path = concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/../../public_html/tests/vault/fixtures/xwing_vector.json"
    );
    let text = std::fs::read_to_string(path).expect("the shared vector file");
    serde_json::from_str(&text).expect("vector JSON")
}

fn hex_of(v: &Value, k: &str) -> Vec<u8> {
    hex::decode(v[k].as_str().unwrap_or_else(|| panic!("field {k}"))).expect("hex")
}

#[test]
fn the_draft_vectors_reproduce() {
    let all = vectors();
    let list = all["xwing"].as_array().expect("xwing list");
    assert_eq!(list.len(), 3);
    for (i, v) in list.iter().enumerate() {
        let seed = hex_of(v, "seed");
        let pk = hex_of(v, "pk");
        let eseed: [u8; 64] = hex_of(v, "eseed").try_into().expect("64-byte eseed");
        assert_eq!(xwing::public_key(&seed).unwrap(), pk, "vector {i}: public key");
        let (ct, ss) = xwing::encapsulate_with(&pk, &eseed).unwrap();
        assert_eq!(ct, hex_of(v, "ct"), "vector {i}: ciphertext");
        assert_eq!(ss.to_vec(), hex_of(v, "ss"), "vector {i}: shared secret");
        assert_eq!(xwing::decapsulate(&seed, &ct).unwrap().to_vec(), hex_of(v, "ss"), "vector {i}: decapsulate");
    }
}

#[test]
fn the_seal_vectors_reproduce_and_open_only_under_their_scope() {
    let all = vectors();
    let list = all["seal"].as_array().expect("seal list");
    assert!(list.iter().any(|s| s["scope"] == "dek"), "a dek-scope seal is in the file");
    for s in list {
        let scope = s["scope"].as_str().unwrap();
        let seed = hex_of(s, "seed_hex");
        let public = b64::decode(s["public_b64"].as_str().unwrap()).unwrap();
        let eseed: [u8; 64] = hex_of(s, "eseed_hex").try_into().unwrap();
        let iv: [u8; 12] = hex_of(s, "iv_hex").try_into().unwrap();
        let plain = hex_of(s, "plaintext_hex");
        let sealed = b64::decode(s["sealed_b64"].as_str().unwrap()).unwrap();

        assert_eq!(xwing::public_key(&seed).unwrap(), public, "{scope}: public key from seed");
        assert_eq!(vault::seal_v2_with(&plain, &public, scope, &eseed, &iv).unwrap(), sealed, "{scope}: seal");
        assert_eq!(vault::open_v2(&sealed, &seed, scope).unwrap(), plain, "{scope}: open");
        let other = if scope == "dek" { "joinery-restore-approval:" } else { "dek" };
        assert!(vault::open_v2(&sealed, &seed, other).is_err(), "{scope}: refused under {other}");

        // The public entry point opens only the dek scope, chosen by the key's length.
        let opened = vault::open_from_secret_key(s["sealed_b64"].as_str().unwrap(), &seed, s["public_b64"].as_str().unwrap());
        assert_eq!(opened.is_ok(), scope == "dek", "{scope}: open_from_secret_key");
    }
}

#[test]
fn a_fresh_keypair_is_a_seed_and_round_trips() {
    let kp = vault::generate_vault_keypair();
    assert_eq!(kp.secret_key.len(), 32);
    assert_eq!(b64::decode(&kp.public_key_b64).unwrap().len(), 1216);
    assert_eq!(vault::public_key_from_secret_key(&kp.secret_key).unwrap(), kp.public_key_b64);
    let sealed = vault::seal_to_public_key(b"a file key", &kp.public_key_b64).unwrap();
    assert_eq!(b64::decode(&sealed).unwrap().len(), 1120 + 12 + 10 + 16);
    assert_eq!(vault::open_from_secret_key(&sealed, &kp.secret_key, &kp.public_key_b64).unwrap(), b"a file key");
    let other = vault::generate_vault_keypair();
    assert!(vault::open_from_secret_key(&sealed, &other.secret_key, &other.public_key_b64).is_err());
}

#[test]
fn only_x_wing_keys_seal_open_or_derive() {
    // A 32-byte public key is not a key to seal to or open with: malformed,
    // like any other wrong length.
    let short_public = b64::encode(&[9u8; 32]);
    let err = vault::seal_to_public_key(b"x", &short_public).unwrap_err();
    assert!(matches!(err, CryptoError::Malformed(_)), "{err}");
    let blob = b64::encode(&[0u8; 1120 + 12 + 16]);
    assert!(matches!(vault::open_from_secret_key(&blob, &[9u8; 32], &short_public), Err(CryptoError::Malformed(_))));
    // A 48-byte secret has no public key: only a 32-byte seed does.
    assert!(matches!(vault::public_key_from_secret_key(&[9u8; 48]), Err(CryptoError::Malformed(_))));
}
