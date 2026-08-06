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
fn a_public_key_derived_from_a_secret_key_is_the_one_it_was_generated_with() {
    // What a device that stored only the secret half relies on: the derived
    // public key has to be byte-identical, because it is mixed into the KDF and
    // a near-miss fails every unwrap with an error that names nothing.
    let kp = vault::generate_vault_keypair();
    let derived = vault::public_key_from_secret_key(&kp.secret_key_pkcs8).unwrap();
    assert_eq!(derived, kp.public_key_b64);

    let fk = FileKey::generate();
    let wrapped = drive::wrap_file_key_to(&fk, &kp.public_key_b64).unwrap();
    assert_eq!(
        drive::open_wrapped_file_key(&wrapped, &kp.secret_key_pkcs8, &derived)
            .unwrap()
            .0,
        fk.0
    );
}

#[test]
fn deriving_a_public_key_from_something_that_is_not_a_secret_key_fails() {
    assert!(vault::public_key_from_secret_key(b"not pkcs8").is_err());
    // The raw 32-byte scalar is the near-miss worth refusing by name: it is
    // what a caller reaches for when they forget the PKCS8 envelope.
    assert!(vault::public_key_from_secret_key(&[7u8; 32]).is_err());
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

// ---- the push-side decryptor (what a download uses) -------------------------

/// Feed a container to `ContentDecryptor` in increments of `step` bytes.
fn push_decrypt(cipher: &[u8], fk: &FileKey, cid: &str, step: usize) -> Vec<u8> {
    let mut dec = drive::ContentDecryptor::new(Vec::new(), fk, cid);
    for piece in cipher.chunks(step) {
        dec.push(piece).unwrap();
    }
    dec.finish().unwrap().0
}

#[test]
fn pushed_ciphertext_decrypts_the_same_however_it_is_split() {
    // The network decides where the boundaries land, and they will not line up
    // with chunk boundaries. Any split has to produce the same plaintext.
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    for size in [0usize, 1, 5000, drive::CHUNK_BYTES, drive::CHUNK_BYTES + 7] {
        let plain: Vec<u8> = (0..size).map(|i| (i % 251) as u8).collect();
        let cipher = drive::encrypt_content(&plain, &fk, &cid);
        for step in [1usize, 3, 4, 16, 4096, cipher.len().max(1)] {
            assert_eq!(
                push_decrypt(&cipher, &fk, &cid, step),
                plain,
                "size {size}, step {step}"
            );
        }
    }
}

#[test]
fn a_truncated_container_is_refused_at_the_end_rather_than_passed_off_as_short() {
    // The download that stopped early. Nothing in the bytes themselves says so
    // — only the fact that the container did not end on a block boundary.
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let cipher = drive::encrypt_content(b"a file that was cut off in transit", &fk, &cid);

    let mut dec = drive::ContentDecryptor::new(Vec::new(), &fk, &cid);
    dec.push(&cipher[..cipher.len() - 3]).unwrap();
    assert!(dec.finish().is_err());

    // And nothing at all is not an empty file: an empty file is one empty chunk.
    let dec = drive::ContentDecryptor::new(Vec::new(), &fk, &cid);
    assert!(dec.finish().is_err());
}

#[test]
fn a_tampered_chunk_fails_before_any_of_its_plaintext_is_written() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let plain: Vec<u8> = (0..(drive::CHUNK_BYTES + 100))
        .map(|i| (i % 97) as u8)
        .collect();
    let mut cipher = drive::encrypt_content(&plain, &fk, &cid);
    let last = cipher.len() - 1;
    cipher[last] ^= 0x01;

    let mut dec = drive::ContentDecryptor::new(Vec::new(), &fk, &cid);
    let mut failed = false;
    for piece in cipher.chunks(1024) {
        if dec.push(piece).is_err() {
            failed = true;
            break;
        }
    }
    assert!(failed, "the tampered chunk must be refused");
    // The first chunk verified and was written; the tampered second one was
    // not — nothing unverified reaches the sink.
    assert_eq!(dec.plain_bytes(), drive::CHUNK_BYTES as u64);
}

#[test]
fn a_container_from_another_file_does_not_decrypt_under_this_ones_id() {
    // The AAD binds every chunk to its content id, so a whole file transplanted
    // from elsewhere fails at the first block rather than producing garbage.
    let fk = FileKey::generate();
    let cipher = drive::encrypt_content(b"belongs to another file", &fk, &drive::new_content_id());
    let mut dec = drive::ContentDecryptor::new(Vec::new(), &fk, &drive::new_content_id());
    assert!(dec.push(&cipher).is_err());
}

#[test]
fn a_corrupt_length_prefix_is_refused_without_allocating_on_its_word() {
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    let mut dec = drive::ContentDecryptor::new(Vec::new(), &fk, &cid);
    assert!(dec.push(&[0xff, 0xff, 0xff, 0xff]).is_err());
}

#[test]
fn the_push_decryptor_and_the_pull_decryptor_agree() {
    // Two implementations of one format is a divergence waiting to happen, so
    // they are held to each other.
    let fk = FileKey::generate();
    let cid = drive::new_content_id();
    for size in [0usize, 1, drive::CHUNK_BYTES * 2 + 11] {
        let plain: Vec<u8> = (0..size).map(|i| (i % 13) as u8).collect();
        let cipher = drive::encrypt_content(&plain, &fk, &cid);
        assert_eq!(
            push_decrypt(&cipher, &fk, &cid, 777),
            drive::decrypt_content(&cipher, &fk, &cid).unwrap()
        );
    }
}
