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
fn sealing_to_an_old_key_is_refused_and_names_the_rotation() {
    let old = jd_crypto::pkcs8::encode(&[9u8; 32]);
    let old_public = vault::public_key_from_secret_key(&old).unwrap();
    assert_eq!(b64::decode(&old_public).unwrap().len(), 32);
    let err = vault::seal_to_public_key(b"x", &old_public).unwrap_err();
    assert!(matches!(err, CryptoError::OldKey));
    assert!(err.to_string().contains("rotate"), "{err}");
}

#[test]
fn a_v1_seal_still_opens_with_its_old_secret() {
    // A v1 seal built by hand (X25519 ECIES, HKDF info 'sealed-vault:dek' ||
    // ephPub || recipientPub), as an unrotated vault's grants are.
    use aes_gcm::aead::Aead;
    use aes_gcm::{Aes256Gcm, KeyInit, Nonce};
    use hkdf::Hkdf;
    use sha2::Sha256;
    use x25519_dalek::{PublicKey, StaticSecret};

    let scalar = [5u8; 32];
    let secret = jd_crypto::pkcs8::encode(&scalar);
    let recipient = PublicKey::from(&StaticSecret::from(scalar));
    let eph = StaticSecret::from([6u8; 32]);
    let eph_pub = PublicKey::from(&eph);
    let shared = eph.diffie_hellman(&recipient);
    let mut info = b"sealed-vault:dek".to_vec();
    info.extend_from_slice(eph_pub.as_bytes());
    info.extend_from_slice(recipient.as_bytes());
    let mut key = [0u8; 32];
    Hkdf::<Sha256>::new(None, shared.as_bytes()).expand(&info, &mut key).unwrap();
    let iv = [1u8; 12];
    let ct = Aes256Gcm::new_from_slice(&key).unwrap().encrypt(Nonce::from_slice(&iv), b"old grant".as_ref()).unwrap();
    let mut raw = eph_pub.as_bytes().to_vec();
    raw.extend_from_slice(&iv);
    raw.extend_from_slice(&ct);

    let recipient_b64 = b64::encode(recipient.as_bytes());
    assert_eq!(vault::public_key_from_secret_key(&secret).unwrap(), recipient_b64);
    assert_eq!(vault::open_from_secret_key(&b64::encode(&raw), &secret, &recipient_b64).unwrap(), b"old grant");
    // ... and with the raw scalar, as the browser also accepts.
    assert_eq!(vault::open_from_secret_key(&b64::encode(&raw), &scalar, &recipient_b64).unwrap(), b"old grant");
}
