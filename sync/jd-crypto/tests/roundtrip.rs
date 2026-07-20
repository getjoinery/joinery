//! In-crate round-trip and refusal tests. These prove jd-crypto is
//! self-consistent; byte-parity with the browser JS is proven separately by
//! tests/functional/drive/sync_crypto_parity_gate.sh (public_html).

use jd_crypto::drive::{self, FileKey, FileMetadata, CHUNK_BYTES};
use jd_crypto::{pkcs8, vault};

fn patterned(len: usize, mul: usize, add: usize) -> Vec<u8> {
    (0..len).map(|i| ((i * mul + add) & 0xff) as u8).collect()
}

fn count_blocks(cipher: &[u8]) -> usize {
    let mut pos = 0;
    let mut blocks = 0;
    while pos < cipher.len() {
        let len = u32::from_be_bytes(cipher[pos..pos + 4].try_into().unwrap()) as usize;
        pos += 4 + len;
        blocks += 1;
    }
    assert_eq!(
        pos,
        cipher.len(),
        "container is self-delimiting to the exact byte"
    );
    blocks
}

#[test]
fn content_round_trips_across_chunks() {
    let data = patterned(CHUNK_BYTES * 2 + 777, 31, 7);
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let cipher = drive::encrypt_content(&data, &fk, &cid);
    assert!(cipher.len() > data.len());
    assert_eq!(count_blocks(&cipher), 3);
    assert_eq!(
        cipher.len() as u64,
        drive::encrypted_size(data.len() as u64)
    );
    assert_eq!(drive::decrypt_content(&cipher, &fk, &cid).unwrap(), data);
}

#[test]
fn exact_chunk_boundary_is_one_block_per_chunk() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let one = drive::encrypt_content(&patterned(CHUNK_BYTES, 3, 1), &fk, &cid);
    assert_eq!(count_blocks(&one), 1);
    let two = drive::encrypt_content(&patterned(CHUNK_BYTES * 2, 3, 1), &fk, &cid);
    assert_eq!(count_blocks(&two), 2);
}

#[test]
fn empty_file_is_exactly_one_empty_chunk() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let cipher = drive::encrypt_content(&[], &fk, &cid);
    assert_eq!(count_blocks(&cipher), 1);
    assert_eq!(cipher.len(), 4 + 12 + 16); // len prefix + IV + tag-only block
    assert_eq!(cipher.len() as u64, drive::encrypted_size(0));
    assert_eq!(
        drive::decrypt_content(&cipher, &fk, &cid).unwrap(),
        Vec::<u8>::new()
    );
}

#[test]
fn wrong_content_id_refuses() {
    let fk = FileKey::generate();
    let cipher = drive::encrypt_content(b"hello", &fk, &drive::new_content_id());
    assert!(drive::decrypt_content(&cipher, &fk, &drive::new_content_id()).is_err());
}

#[test]
fn reordered_chunks_refuse() {
    let data = patterned(CHUNK_BYTES + 99, 17, 3);
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let cipher = drive::encrypt_content(&data, &fk, &cid);
    // swap the two blocks
    let len0 = u32::from_be_bytes(cipher[..4].try_into().unwrap()) as usize;
    let (b0, b1) = cipher.split_at(4 + len0);
    let mut swapped = Vec::new();
    swapped.extend_from_slice(b1);
    swapped.extend_from_slice(b0);
    assert!(drive::decrypt_content(&swapped, &fk, &cid).is_err());
}

#[test]
fn tampered_ciphertext_refuses() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let mut cipher = drive::encrypt_content(b"attack at dawn", &fk, &cid);
    let last = cipher.len() - 1;
    cipher[last] ^= 0x01;
    assert!(drive::decrypt_content(&cipher, &fk, &cid).is_err());
}

#[test]
fn truncated_container_refuses() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let cipher = drive::encrypt_content(b"some content", &fk, &cid);
    assert!(drive::decrypt_content(&cipher[..cipher.len() - 3], &fk, &cid).is_err());
    assert!(drive::decrypt_content(&[], &fk, &cid).is_err());
}

#[test]
fn metadata_round_trips_with_mtime_and_tolerates_unknown_fields() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let mut meta = FileMetadata::new(
        "Report: final.xlsx",
        "application/vnd.ms-excel",
        12345,
        &cid,
    );
    meta.thumb = true;
    meta.mtime = Some("2026-07-20T12:34:56Z".to_string());
    let blob = drive::encrypt_metadata(&meta, &fk).unwrap();
    assert_eq!(drive::decrypt_metadata(&blob, &fk).unwrap(), meta);

    // a future writer adds a field this reader has never heard of
    let future = vault::encrypt_string(
        &format!(
            r#"{{"v":1,"name":"x","mime":"text/plain","size":1,"cid":"{cid}","chunk":4194304,"thumb":false,"someday":"yes"}}"#
        ),
        &fk.0,
    )
    .unwrap();
    assert_eq!(drive::decrypt_metadata(&future, &fk).unwrap().name, "x");
}

#[test]
fn thumbnail_round_trips_and_binds_to_content_id() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let plain = patterned(513, 7, 1);
    let ct = drive::encrypt_thumbnail(&plain, &fk, &cid);
    assert_eq!(drive::decrypt_thumbnail(&ct, &fk, &cid).unwrap(), plain);
    assert!(drive::decrypt_thumbnail(&ct, &fk, &drive::new_content_id()).is_err());
}

#[test]
fn sealed_box_round_trips_and_refuses_other_keypairs() {
    let kp = vault::generate_vault_keypair();
    let fk = FileKey::generate();
    let wrapped = drive::wrap_file_key_to(&fk, &kp.public_key_b64).unwrap();
    let opened =
        drive::open_wrapped_file_key(&wrapped, &kp.secret_key_pkcs8, &kp.public_key_b64).unwrap();
    assert_eq!(opened.0, fk.0);

    let other = vault::generate_vault_keypair();
    assert!(
        drive::open_wrapped_file_key(&wrapped, &other.secret_key_pkcs8, &other.public_key_b64)
            .is_err()
    );
    // the recipient public key is bound into the KDF: right secret key with the
    // wrong claimed public key must also refuse
    assert!(
        drive::open_wrapped_file_key(&wrapped, &kp.secret_key_pkcs8, &other.public_key_b64)
            .is_err()
    );
}

#[test]
fn wrapped_secret_key_binds_its_ad() {
    let kp = vault::generate_vault_keypair();
    let kek = vault::kek_from_recovery_code("ABCD-2345-EFGH-6789", "c2FsdHNhbHQ=").unwrap();
    let blob = vault::wrap_secret_key(&kp.secret_key_pkcs8, &kek, "vault:drive:recovery").unwrap();
    let opened = vault::unwrap_secret_key(&blob, &kek, "vault:drive:recovery").unwrap();
    assert_eq!(opened, kp.secret_key_pkcs8);
    assert!(vault::unwrap_secret_key(&blob, &kek, "vault:passwords:recovery").is_err());
}

#[test]
fn recovery_code_normalization_is_crockford_lenient() {
    // O→0, I/L→1, case-insensitive, separators stripped
    let a = vault::kek_from_recovery_code("oO0-iI1-lL1", "c2FsdA==").unwrap();
    let b = vault::kek_from_recovery_code("000 111 111", "c2FsdA==").unwrap();
    assert_eq!(a, b);
    let c = vault::kek_from_recovery_code("000 111 112", "c2FsdA==").unwrap();
    assert_ne!(a, c);
}

#[test]
fn passphrase_kek_is_deterministic_and_salt_sensitive() {
    let params = vault::KdfParams {
        mem: 8,
        time: 1,
        parallelism: 1,
        hash_len: 32,
    };
    let a = vault::kek_from_passphrase("correct horse", "c2FsdHNhbHQ=", &params).unwrap();
    let b = vault::kek_from_passphrase("correct horse", "c2FsdHNhbHQ=", &params).unwrap();
    assert_eq!(a, b);
    let c = vault::kek_from_passphrase("correct horse", "b3RoZXJzYWx0", &params).unwrap();
    assert_ne!(a, c);
}

#[test]
fn pkcs8_wraps_and_unwraps_the_raw_scalar() {
    let raw = [42u8; 32];
    let der = pkcs8::encode(&raw);
    assert_eq!(der.len(), 48);
    assert_eq!(pkcs8::decode(&der).unwrap(), raw);
    assert!(pkcs8::decode(&der[..47]).is_err());
    assert!(pkcs8::decode(&[0u8; 48]).is_err());
}

#[test]
fn streaming_and_buffer_paths_agree() {
    use std::io::Write;
    let data = patterned(CHUNK_BYTES + 4096, 13, 5);
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    // stream in awkward increments
    let mut enc = drive::ContentEncryptor::new(Vec::new(), &fk, &cid);
    for piece in data.chunks(70_001) {
        enc.write_all(piece).unwrap();
    }
    let cipher = enc.finish().unwrap();
    let mut out = Vec::new();
    let n = drive::decrypt_content_stream(&cipher[..], &mut out, &fk, &cid).unwrap();
    assert_eq!(n as usize, data.len());
    assert_eq!(out, data);
}

#[test]
fn content_id_is_32_lowercase_hex() {
    let cid = drive::new_content_id();
    assert_eq!(cid.len(), 32);
    assert!(cid
        .chars()
        .all(|c| c.is_ascii_hexdigit() && !c.is_ascii_uppercase()));
}
