//! Cross-implementation parity vehicle. Two modes:
//!
//!   jd-crypto-parity emit <out.json>     — generate a vector file (test keys
//!                                          in the clear; fixtures only)
//!   jd-crypto-parity verify <in.json>    — consume a vector file produced by
//!                                          the OTHER implementation, decrypt
//!                                          and check everything, exit non-zero
//!                                          on any mismatch
//!
//! The Node twin is public_html/tests/functional/drive/sync_crypto_parity.mjs;
//! the gate (sync_crypto_parity_gate.sh) runs Rust-emit→Node-verify and
//! Node-emit→Rust-verify. The vector JSON schema is the shared contract —
//! change it in both files or the gate fails, which is the point.

use std::io::Write as _;
use std::process::ExitCode;

use serde::{Deserialize, Serialize};

use jd_crypto::drive::{self, FileKey, CHUNK_BYTES};
use jd_crypto::{b64, vault};

#[derive(Serialize, Deserialize, Default)]
struct Vectors {
    producer: String,
    content: Vec<ContentVec>,
    metadata: Vec<MetaVec>,
    thumbs: Vec<ThumbVec>,
    sealed: Vec<SealedVec>,
    wrapped_keys: Vec<WrappedKeyVec>,
    refusals: Vec<Refusal>,
}

/// Plaintexts travel as generation rules, not bytes — byte[i] = (i*mul+add)&0xff
/// — so an 8 MiB vector costs nothing in the file.
#[derive(Serialize, Deserialize, Clone, Copy)]
struct Pattern {
    len: usize,
    mul: usize,
    add: usize,
}

impl Pattern {
    fn bytes(&self) -> Vec<u8> {
        (0..self.len)
            .map(|i| ((i * self.mul + self.add) & 0xff) as u8)
            .collect()
    }
}

#[derive(Serialize, Deserialize)]
struct ContentVec {
    label: String,
    pattern: Pattern,
    fk_b64: String,
    content_id: String,
    cipher_b64: String,
}

#[derive(Serialize, Deserialize)]
struct MetaVec {
    label: String,
    fk_b64: String,
    blob: String,
    /// Every key the producer wrote; the verifier requires exact equality on
    /// each of these keys after decryption.
    expect: serde_json::Value,
}

#[derive(Serialize, Deserialize)]
struct ThumbVec {
    fk_b64: String,
    content_id: String,
    pattern: Pattern,
    cipher_b64: String,
}

#[derive(Serialize, Deserialize)]
struct SealedVec {
    label: String,
    recipient_public_key_b64: String,
    recipient_secret_pkcs8_b64: String,
    plaintext_b64: String,
    sealed_b64: String,
}

#[derive(Serialize, Deserialize)]
struct WrappedKeyVec {
    label: String,
    kek: KekSource,
    ad: String,
    secret_b64: String,
    blob: String,
}

#[derive(Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
enum KekSource {
    Recovery {
        code: String,
        salt_b64: String,
    },
    Passphrase {
        passphrase: String,
        salt_b64: String,
        mem: u32,
        time: u32,
        parallelism: u32,
    },
}

impl KekSource {
    fn derive(&self) -> jd_crypto::Result<[u8; 32]> {
        match self {
            KekSource::Recovery { code, salt_b64 } => vault::kek_from_recovery_code(code, salt_b64),
            KekSource::Passphrase {
                passphrase,
                salt_b64,
                mem,
                time,
                parallelism,
            } => {
                let params = vault::KdfParams {
                    mem: *mem,
                    time: *time,
                    parallelism: *parallelism,
                    hash_len: 32,
                };
                vault::kek_from_passphrase(passphrase, salt_b64, &params)
            }
        }
    }
}

/// A decryption the verifier must REFUSE. Fields are per-kind.
#[derive(Serialize, Deserialize)]
struct Refusal {
    kind: String,
    reason: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    fk_b64: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    content_id: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    cipher_b64: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    sealed_b64: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    secret_pkcs8_b64: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    public_key_b64: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    blob: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    kek: Option<KekSource>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    ad: Option<String>,
}

fn refusal(kind: &str, reason: &str) -> Refusal {
    Refusal {
        kind: kind.into(),
        reason: reason.into(),
        fk_b64: None,
        content_id: None,
        cipher_b64: None,
        sealed_b64: None,
        secret_pkcs8_b64: None,
        public_key_b64: None,
        blob: None,
        kek: None,
        ad: None,
    }
}

fn main() -> ExitCode {
    let args: Vec<String> = std::env::args().collect();
    match (args.get(1).map(String::as_str), args.get(2)) {
        (Some("emit"), Some(path)) => emit(path),
        (Some("verify"), Some(path)) => verify(path),
        _ => {
            eprintln!("usage: jd-crypto-parity emit|verify <vectors.json>");
            ExitCode::from(2)
        }
    }
}

// ---- emit -------------------------------------------------------------------

fn emit(path: &str) -> ExitCode {
    let mut v = Vectors {
        producer: "rust".into(),
        ..Default::default()
    };

    // content: the boundary set the spec names — empty, small, exact-chunk,
    // multi-chunk with remainder
    let cases = [
        (
            "empty",
            Pattern {
                len: 0,
                mul: 1,
                add: 0,
            },
        ),
        (
            "small",
            Pattern {
                len: 5,
                mul: 31,
                add: 7,
            },
        ),
        (
            "exact-boundary",
            Pattern {
                len: CHUNK_BYTES,
                mul: 13,
                add: 5,
            },
        ),
        (
            "multi-chunk",
            Pattern {
                len: CHUNK_BYTES + 777,
                mul: 17,
                add: 3,
            },
        ),
    ];
    for (label, pattern) in cases {
        let fk = FileKey::generate();
        let cid = drive::new_content_id();
        let cipher = drive::encrypt_content(&pattern.bytes(), &fk, &cid);
        v.content.push(ContentVec {
            label: label.into(),
            pattern,
            fk_b64: b64::encode(&fk.0),
            content_id: cid,
            cipher_b64: b64::encode(&cipher),
        });
    }

    // metadata: unicode name + mtime; and a plain one without mtime
    {
        let fk = FileKey::generate();
        let cid = drive::new_content_id();
        let mut meta = drive::FileMetadata::new(
            "Répor t: finäl 😀.xlsx",
            "application/vnd.ms-excel",
            12345,
            &cid,
        );
        meta.thumb = true;
        meta.mtime = Some("2026-07-20T12:34:56.000Z".into());
        v.metadata.push(MetaVec {
            label: "unicode+mtime".into(),
            fk_b64: b64::encode(&fk.0),
            blob: drive::encrypt_metadata(&meta, &fk).expect("metadata encrypts"),
            expect: serde_json::to_value(&meta).expect("metadata serializes"),
        });

        let fk2 = FileKey::generate();
        let cid2 = drive::new_content_id();
        let meta2 = drive::FileMetadata::new("plain.txt", "text/plain", 0, &cid2);
        v.metadata.push(MetaVec {
            label: "no-mtime".into(),
            fk_b64: b64::encode(&fk2.0),
            blob: drive::encrypt_metadata(&meta2, &fk2).expect("metadata encrypts"),
            expect: serde_json::to_value(&meta2).expect("metadata serializes"),
        });
    }

    // thumbnail
    {
        let fk = FileKey::generate();
        let cid = drive::new_content_id();
        let pattern = Pattern {
            len: 513,
            mul: 7,
            add: 1,
        };
        v.thumbs.push(ThumbVec {
            fk_b64: b64::encode(&fk.0),
            content_id: cid.clone(),
            cipher_b64: b64::encode(&drive::encrypt_thumbnail(&pattern.bytes(), &fk, &cid)),
            pattern,
        });
    }

    // sealed boxes: a 32-byte file key and a 48-byte PKCS8 secret (the
    // device-link vault handoff shape)
    for (label, payload) in [
        ("file-key", vault::random_bytes::<32>().to_vec()),
        (
            "pkcs8-handoff",
            vault::generate_vault_keypair().secret_key_pkcs8.clone(),
        ),
    ] {
        let kp = vault::generate_vault_keypair();
        v.sealed.push(SealedVec {
            label: label.into(),
            sealed_b64: vault::seal_to_public_key(&payload, &kp.public_key_b64)
                .expect("seal succeeds"),
            recipient_public_key_b64: kp.public_key_b64.clone(),
            recipient_secret_pkcs8_b64: b64::encode(&kp.secret_key_pkcs8),
            plaintext_b64: b64::encode(&payload),
        });
    }

    // wrapped secret keys under both CLI-recovery unlockers
    {
        let secret = vault::generate_vault_keypair().secret_key_pkcs8.clone();
        let salt = b64::encode(&vault::random_bytes::<16>());
        // messy Crockford entry on purpose: verifier must normalize identically
        let kek_src = KekSource::Recovery {
            code: "oO0i-Il1L 2345".into(),
            salt_b64: salt,
        };
        let kek = kek_src.derive().expect("recovery KEK derives");
        v.wrapped_keys.push(WrappedKeyVec {
            label: "recovery".into(),
            blob: vault::wrap_secret_key(&secret, &kek, "vault:drive:recovery")
                .expect("wrap succeeds"),
            kek: kek_src,
            ad: "vault:drive:recovery".into(),
            secret_b64: b64::encode(&secret),
        });

        let salt2 = b64::encode(&vault::random_bytes::<16>());
        let kek_src2 = KekSource::Passphrase {
            passphrase: "correct horse battery staple".into(),
            salt_b64: salt2,
            mem: 8192,
            time: 2,
            parallelism: 1,
        };
        let kek2 = kek_src2.derive().expect("passphrase KEK derives");
        v.wrapped_keys.push(WrappedKeyVec {
            label: "passphrase".into(),
            blob: vault::wrap_secret_key(&secret, &kek2, "vault:drive:passphrase")
                .expect("wrap succeeds"),
            kek: kek_src2,
            ad: "vault:drive:passphrase".into(),
            secret_b64: b64::encode(&secret),
        });
    }

    // refusals the other side must reject
    {
        let fk = FileKey::generate();
        let cid = drive::new_content_id();
        let data = Pattern {
            len: CHUNK_BYTES + 99,
            mul: 11,
            add: 9,
        }
        .bytes();
        let cipher = drive::encrypt_content(&data, &fk, &cid);

        let mut r = refusal("content", "wrong_cid");
        r.fk_b64 = Some(b64::encode(&fk.0));
        r.content_id = Some(drive::new_content_id());
        r.cipher_b64 = Some(b64::encode(&cipher));
        v.refusals.push(r);

        let len0 = u32::from_be_bytes(cipher[..4].try_into().expect("4-byte prefix")) as usize;
        let (b0, b1) = cipher.split_at(4 + len0);
        let mut swapped = b1.to_vec();
        swapped.extend_from_slice(b0);
        let mut r = refusal("content", "reordered");
        r.fk_b64 = Some(b64::encode(&fk.0));
        r.content_id = Some(cid.clone());
        r.cipher_b64 = Some(b64::encode(&swapped));
        v.refusals.push(r);

        let mut tampered = cipher.clone();
        let last = tampered.len() - 1;
        tampered[last] ^= 0x01;
        let mut r = refusal("content", "tampered");
        r.fk_b64 = Some(b64::encode(&fk.0));
        r.content_id = Some(cid);
        r.cipher_b64 = Some(b64::encode(&tampered));
        v.refusals.push(r);

        let kp = vault::generate_vault_keypair();
        let other = vault::generate_vault_keypair();
        let mut r = refusal("sealed", "wrong_keypair");
        r.sealed_b64 =
            Some(vault::seal_to_public_key(&fk.0, &kp.public_key_b64).expect("seal succeeds"));
        r.secret_pkcs8_b64 = Some(b64::encode(&other.secret_key_pkcs8));
        r.public_key_b64 = Some(other.public_key_b64.clone());
        v.refusals.push(r);

        let kek_src = KekSource::Recovery {
            code: "ABCD2345".into(),
            salt_b64: b64::encode(&vault::random_bytes::<16>()),
        };
        let kek = kek_src.derive().expect("recovery KEK derives");
        let mut r = refusal("wrapped_key", "wrong_ad");
        r.blob = Some(
            vault::wrap_secret_key(&fk.0, &kek, "vault:drive:recovery").expect("wrap succeeds"),
        );
        r.kek = Some(kek_src);
        r.ad = Some("vault:passwords:recovery".into());
        v.refusals.push(r);
    }

    let json = serde_json::to_string(&v).expect("vectors serialize");
    if let Err(e) = std::fs::write(path, json) {
        eprintln!("cannot write {path}: {e}");
        return ExitCode::FAILURE;
    }
    println!("emitted rust vectors to {path}");
    ExitCode::SUCCESS
}

// ---- verify -----------------------------------------------------------------

struct Tally {
    failed: u32,
}

impl Tally {
    fn check(&mut self, ok: bool, label: &str) {
        let mut out = std::io::stdout().lock();
        let _ = writeln!(out, "{} {}", if ok { "PASS" } else { "FAIL" }, label);
        if !ok {
            self.failed += 1;
        }
    }
}

fn fk_of(b64key: &str) -> Option<FileKey> {
    FileKey::from_bytes(&b64::decode(b64key).ok()?).ok()
}

fn verify(path: &str) -> ExitCode {
    let raw = match std::fs::read_to_string(path) {
        Ok(r) => r,
        Err(e) => {
            eprintln!("cannot read {path}: {e}");
            return ExitCode::FAILURE;
        }
    };
    let v: Vectors = match serde_json::from_str(&raw) {
        Ok(v) => v,
        Err(e) => {
            eprintln!("vector file does not parse: {e}");
            return ExitCode::FAILURE;
        }
    };
    println!("verifying {} vectors from producer={}", path, v.producer);
    let mut t = Tally { failed: 0 };

    for c in &v.content {
        let ok = (|| {
            let fk = fk_of(&c.fk_b64)?;
            let cipher = b64::decode(&c.cipher_b64).ok()?;
            let plain = drive::decrypt_content(&cipher, &fk, &c.content_id).ok()?;
            Some(plain == c.pattern.bytes())
        })()
        .unwrap_or(false);
        t.check(ok, &format!("content:{}", c.label));
    }

    for m in &v.metadata {
        let ok = (|| {
            let fk = fk_of(&m.fk_b64)?;
            let got = serde_json::to_value(drive::decrypt_metadata(&m.blob, &fk).ok()?).ok()?;
            let expect = m.expect.as_object()?;
            Some(expect.iter().all(|(k, want)| got.get(k) == Some(want)))
        })()
        .unwrap_or(false);
        t.check(ok, &format!("metadata:{}", m.label));
    }

    for th in &v.thumbs {
        let ok = (|| {
            let fk = fk_of(&th.fk_b64)?;
            let cipher = b64::decode(&th.cipher_b64).ok()?;
            let plain = drive::decrypt_thumbnail(&cipher, &fk, &th.content_id).ok()?;
            Some(plain == th.pattern.bytes())
        })()
        .unwrap_or(false);
        t.check(ok, "thumbnail");
    }

    for s in &v.sealed {
        let ok = (|| {
            let secret = b64::decode(&s.recipient_secret_pkcs8_b64).ok()?;
            let opened =
                vault::open_from_secret_key(&s.sealed_b64, &secret, &s.recipient_public_key_b64)
                    .ok()?;
            Some(opened == b64::decode(&s.plaintext_b64).ok()?)
        })()
        .unwrap_or(false);
        t.check(ok, &format!("sealed:{}", s.label));
    }

    for w in &v.wrapped_keys {
        let ok = (|| {
            let kek = w.kek.derive().ok()?;
            let opened = vault::unwrap_secret_key(&w.blob, &kek, &w.ad).ok()?;
            Some(opened == b64::decode(&w.secret_b64).ok()?)
        })()
        .unwrap_or(false);
        t.check(ok, &format!("wrapped_key:{}", w.label));
    }

    for r in &v.refusals {
        let refused = match r.kind.as_str() {
            "content" => (|| {
                let fk = fk_of(r.fk_b64.as_ref()?)?;
                let cipher = b64::decode(r.cipher_b64.as_ref()?).ok()?;
                Some(drive::decrypt_content(&cipher, &fk, r.content_id.as_ref()?).is_err())
            })()
            .unwrap_or(false),
            "sealed" => (|| {
                let secret = b64::decode(r.secret_pkcs8_b64.as_ref()?).ok()?;
                Some(
                    vault::open_from_secret_key(
                        r.sealed_b64.as_ref()?,
                        &secret,
                        r.public_key_b64.as_ref()?,
                    )
                    .is_err(),
                )
            })()
            .unwrap_or(false),
            "wrapped_key" => (|| {
                let kek = r.kek.as_ref()?.derive().ok()?;
                Some(vault::unwrap_secret_key(r.blob.as_ref()?, &kek, r.ad.as_ref()?).is_err())
            })()
            .unwrap_or(false),
            _ => false,
        };
        t.check(refused, &format!("refusal:{}:{}", r.kind, r.reason));
    }

    if t.failed == 0 {
        println!("ALL PASS");
        ExitCode::SUCCESS
    } else {
        println!("{} FAILURE(S)", t.failed);
        ExitCode::FAILURE
    }
}
