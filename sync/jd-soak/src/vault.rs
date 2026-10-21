//! The encrypted lane's key (`specs/drive_sync_soak.md` § The encrypted lane).
//!
//! The rig account holds a Drive vault and nothing above it: a real account's
//! Drive vault opens through its root, and a root needs a passkey, which from a
//! rig would mean a software authenticator testing the browser rather than the
//! client. The vault's keypair is **derived** from the account's own password
//! and address, so every rig process — provisioning, the verifier opening what
//! the server holds — arrives at the same key without one being written down.
//! The server is only ever told the public half.

use std::collections::BTreeMap;
use std::path::PathBuf;

use hkdf::Hkdf;
use jd_crypto::drive::FileKey;
use jd_proto::DriveApi;
use serde_json::{json, Value};
use sha2::Sha256;

use crate::actor::hash_bytes;
use crate::fleet::Fleet;
use crate::server::ServerTree;

/// What makes this derivation the rig's and nothing else's. A change here is a
/// new key for every account, so it is versioned rather than edited.
const INFO: &[u8] = b"jd-soak drive vault v1";

/// The Drive vault keypair of one rig account.
#[derive(Clone)]
pub struct DriveKey {
    /// PKCS8, the form a device is handed and keeps.
    pub secret_pkcs8: Vec<u8>,
    /// Standard base64 of the raw X25519 public key, the form the server keeps.
    pub public_key: String,
}

impl std::fmt::Debug for DriveKey {
    // Never the secret half, whoever prints one.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DriveKey")
            .field("public_key", &self.public_key)
            .finish_non_exhaustive()
    }
}

/// The account's Drive vault key. The address is the salt, so two accounts
/// sharing the rig's one password still hold different keys.
///
/// HKDF is not a password hash: the key is exactly as strong as the password,
/// and the server keeps a hash of that password, so cracking the one yields
/// the other. Right for the rig's machine-generated password and synthetic
/// files; wrong for anything a person typed.
pub fn drive_key(account: &str, password: &str) -> DriveKey {
    let mut scalar = [0u8; 32];
    Hkdf::<Sha256>::new(Some(account.trim().to_lowercase().as_bytes()), password.as_bytes())
        .expand(INFO, &mut scalar)
        .expect("32 bytes is a valid HKDF-SHA256 length");
    let secret_pkcs8 = jd_crypto::pkcs8::encode(&scalar);
    let public_key = jd_crypto::vault::public_key_from_secret_key(&secret_pkcs8)
        .expect("a 32-byte scalar in its PKCS8 envelope is a valid X25519 key");
    DriveKey {
        secret_pkcs8,
        public_key,
    }
}

/// The encrypted folder the personas' vault twins write into, at the drive
/// root of every device.
pub const VAULT_FOLDER: &str = "Vault";

/// Make the `Vault` folder, encrypted, unless the account has it already.
/// One that exists unencrypted is refused: everything written into it would be
/// plaintext journaled as encrypted.
pub fn ensure_vault_folder(api: &dyn DriveApi) -> Result<(), String> {
    let tree = crate::server::walk(api).map_err(|e| format!("cannot list the drive: {e}"))?;
    if let Some(found) = tree
        .folders
        .values()
        .find(|f| f.parent_id.is_none() && !f.deleted && f.name == VAULT_FOLDER)
    {
        return if found.encrypted {
            Ok(())
        } else {
            Err(format!("the account's {VAULT_FOLDER} folder is not encrypted"))
        };
    }
    api.action(
        "drive_folder_create",
        json!({ "name": VAULT_FOLDER, "protection_level": "fortress" }),
    )
    .map_err(|e| format!("cannot create the {VAULT_FOLDER} folder: {e}"))?;
    Ok(())
}

/// Opens what the server holds encrypted, so the oracle can judge it in the
/// same terms as everything else (spec § The encrypted lane, E4).
///
/// The name and the size come from the file's metadata, which the index walk
/// already carries. The plaintext's hash needs the bytes, so each ciphertext
/// is fetched once, ever: what it opened to is kept beside the journals, and a
/// later settle reads it from there.
pub struct Opener {
    key: DriveKey,
    map_file: PathBuf,
    map: BTreeMap<String, String>,
}

/// What opening a tree came to.
#[derive(Debug, Default)]
pub struct Opened {
    /// What could not be opened, each by file id and why. Every one fails the
    /// settle.
    pub failures: Vec<String>,
    /// Where trashed files stood whose content was never seen while they were
    /// live: their bytes cannot be had, so their plaintext is unknown.
    pub unread_trashed: Vec<String>,
}

/// File ids per `drive_stat` call when fetching download links.
const STAT_BATCH: usize = 50;

impl Opener {
    /// The opener for a fleet with the encrypted lane, `None` for one without.
    pub fn for_fleet(fleet: &Fleet) -> Result<Option<Opener>, String> {
        if !fleet.vault {
            return Ok(None);
        }
        let (email, password) = Fleet::credentials().map_err(|e| e.to_string())?;
        let map_file = fleet.journal_dir.join("vault-contents.json");
        Ok(Some(Opener::new(drive_key(&email, &password), map_file)))
    }

    pub fn new(key: DriveKey, map_file: PathBuf) -> Opener {
        // A map that cannot be read starts empty: everything is fetched again,
        // which costs downloads and never a wrong answer.
        let map = std::fs::read(&map_file)
            .ok()
            .and_then(|b| serde_json::from_slice(&b).ok())
            .unwrap_or_default();
        Opener { key, map_file, map }
    }

    /// Open every encrypted file in `tree` in place.
    pub fn open(&mut self, api: &dyn DriveApi, tree: &mut ServerTree) -> Opened {
        let mut failures = Vec::new();
        let mut unread_trashed = Vec::new();
        let mut fetch: Vec<(i64, FileKey, String, String)> = Vec::new();
        let ids: Vec<i64> = tree
            .files
            .iter()
            .filter(|(_, f)| f.encrypted)
            .map(|(id, _)| *id)
            .collect();
        for id in ids {
            let Some(sealed) = tree.sealed.get(&id).cloned() else {
                failures.push(format!("file {id} came without its name and key"));
                continue;
            };
            let fk = match jd_crypto::drive::open_wrapped_file_key(
                &sealed.wrapped_file_key,
                &self.key.secret_pkcs8,
                &self.key.public_key,
            ) {
                Ok(fk) => fk,
                Err(e) => {
                    failures.push(format!("file {id}: its key does not open with the Drive key ({e})"));
                    continue;
                }
            };
            let meta = match jd_crypto::drive::decrypt_metadata(&sealed.metadata, &fk) {
                Ok(m) => m,
                Err(e) => {
                    failures.push(format!("file {id}: its name does not open ({e})"));
                    continue;
                }
            };
            let file = tree.files.get_mut(&id).expect("listed above");
            file.name = meta.name;
            file.size = meta.size;
            let Some(cipher) = file.sha256.clone() else {
                failures.push(format!("file {id} has no content hash"));
                continue;
            };
            tree.cipher.insert(id, cipher.clone());
            match self.map.get(&cipher) {
                Some(plain) => file.sha256 = Some(plain.clone()),
                None => fetch.push((id, fk, meta.cid, cipher)),
            }
        }

        for batch in fetch.chunks(STAT_BATCH) {
            let entities: Vec<Value> = batch
                .iter()
                .map(|(id, ..)| json!({ "entity_type": "file", "entity_id": id }))
                .collect();
            let body = json!({ "entities": entities, "urls": true });
            let urls: BTreeMap<i64, String> =
                match api.action("drive_stat", body.clone()).or_else(|_| api.action("drive_stat", body)) {
                    Ok(answer) => answer
                        .get("items")
                        .and_then(Value::as_array)
                        .into_iter()
                        .flatten()
                        .filter_map(|item| {
                            Some((
                                item.get("id")?.as_i64()?,
                                item.get("download_url")?.as_str()?.to_string(),
                            ))
                        })
                        .collect(),
                    Err(e) => {
                        failures.push(format!("{} file(s) could not be looked up to open: {e}", batch.len()));
                        continue;
                    }
                };
            for (id, fk, cid, cipher) in batch {
                match self.fetch_plain(api, urls.get(id), fk, cid, cipher) {
                    Ok(plain) => {
                        self.map.insert(cipher.clone(), plain.clone());
                        if let Some(file) = tree.files.get_mut(id) {
                            file.sha256 = Some(plain);
                        }
                    }
                    // A trashed file's link answers 404: its bytes come back
                    // only with a restore, which the rig will not do to the
                    // world it is judging. Its name and size are open; its
                    // content is not, and is counted rather than guessed.
                    Err(_) if tree.files.get(id).is_some_and(|f| f.deleted) => {
                        if let Some(file) = tree.files.get_mut(id) {
                            file.sha256 = None;
                        }
                        if let Some(path) = tree.files.get(id).and_then(|f| tree.path_of(f)) {
                            unread_trashed.push(path);
                        }
                    }
                    Err(why) => failures.push(format!("file {id}: {why}")),
                }
            }
        }

        tree.opened = self.map.clone();
        self.save();
        Opened {
            failures,
            unread_trashed,
        }
    }

    fn fetch_plain(
        &self,
        api: &dyn DriveApi,
        url: Option<&String>,
        fk: &FileKey,
        cid: &str,
        cipher: &str,
    ) -> Result<String, String> {
        let url = url.ok_or("the server gave no link to its bytes")?;
        // Once more on a failure: one 429 or 5xx is noise, and failing the
        // whole settle over it would be the rig reporting its own luck.
        let mut bytes = Vec::new();
        if api.download(url, 0, &mut bytes).is_err() {
            bytes.clear();
            api.download(url, 0, &mut bytes)
                .map_err(|e| format!("its bytes could not be fetched: {e}"))?;
        }
        // The settle is after convergence, so the head the walk saw is the one
        // fetched; if not, the bytes are not the ones this hash names.
        if hash_bytes(&bytes) != cipher {
            return Err("its bytes changed between the walk and the fetch".into());
        }
        let plain = jd_crypto::drive::decrypt_content(&bytes, fk, cid)
            .map_err(|e| format!("its bytes do not open ({e})"))?;
        Ok(hash_bytes(&plain))
    }

    fn save(&self) {
        let Ok(body) = serde_json::to_vec(&self.map) else {
            return;
        };
        let tmp = self.map_file.with_extension("json.tmp");
        if std::fs::write(&tmp, body).is_ok() {
            let _ = std::fs::rename(&tmp, &self.map_file);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// One account, one key, every time and in every process; another account
    /// or another password, another key.
    #[test]
    fn the_key_is_the_accounts_own_and_the_same_every_time() {
        let a = drive_key("soak-rig-1@getjoinery.com", "pw");
        assert_eq!(a.secret_pkcs8, drive_key("soak-rig-1@getjoinery.com", "pw").secret_pkcs8);
        assert_eq!(a.public_key, drive_key(" Soak-Rig-1@getjoinery.com", "pw").public_key);
        assert_ne!(a.public_key, drive_key("soak-rig-2@getjoinery.com", "pw").public_key);
        assert_ne!(a.public_key, drive_key("soak-rig-1@getjoinery.com", "pw2").public_key);
        assert!(!format!("{a:?}").contains(&jd_crypto::b64::encode(&a.secret_pkcs8)));
    }

    use crate::server::{Entity, Sealed};
    use jd_proto::ProtoError;
    use std::io::Write;
    use std::sync::Mutex;

    /// A server holding encrypted files, as the index and `drive_stat` hand
    /// them over, counting what it was asked to download.
    struct Holding {
        bytes: BTreeMap<String, Vec<u8>>,
        downloads: Mutex<usize>,
    }

    impl DriveApi for Holding {
        fn action(&self, name: &str, body: Value) -> Result<Value, ProtoError> {
            assert_eq!(name, "drive_stat");
            assert_eq!(body["urls"], json!(true));
            let items: Vec<Value> = body["entities"]
                .as_array()
                .unwrap()
                .iter()
                .map(|e| json!({ "id": e["entity_id"], "download_url": format!("u{}", e["entity_id"]) }))
                .collect();
            Ok(json!({ "items": items }))
        }
        fn action_idempotent(&self, name: &str, body: Value, _k: &str) -> Result<Value, ProtoError> {
            self.action(name, body)
        }
        fn upload(
            &self,
            _p: &jd_proto::UploadParams,
            _r: &mut dyn jd_proto::ReadSeek,
        ) -> Result<jd_proto::UploadOutcome, ProtoError> {
            unimplemented!()
        }
        fn download(&self, url: &str, _f: u64, out: &mut dyn Write) -> Result<u64, ProtoError> {
            *self.downloads.lock().unwrap() += 1;
            let bytes = &self.bytes[url];
            out.write_all(bytes).unwrap();
            Ok(bytes.len() as u64)
        }
    }

    /// One encrypted file as a client seals it: its bytes, and its entity and
    /// sealed name the way the index lists them.
    fn sealed_file(id: i64, name: &str, plain: &[u8], to: &str) -> (Vec<u8>, Entity, Sealed) {
        let fk = jd_crypto::drive::FileKey::generate();
        let cid = jd_crypto::drive::new_content_id();
        let cipher = jd_crypto::drive::encrypt_content(plain, &fk, &cid);
        let meta = jd_crypto::drive::FileMetadata::new(name, "text/plain", plain.len() as u64, &cid);
        let entity = Entity {
            id,
            is_folder: false,
            name: format!("enc-{cid}"),
            parent_id: Some(7),
            deleted: false,
            encrypted: true,
            sha256: Some(hash_bytes(&cipher)),
            size: cipher.len() as u64,
        };
        let sealed = Sealed {
            metadata: jd_crypto::drive::encrypt_metadata(&meta, &fk).unwrap(),
            wrapped_file_key: jd_crypto::drive::wrap_file_key_to(&fk, to).unwrap(),
        };
        (cipher, entity, sealed)
    }

    fn scratch(tag: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!("jd-soak-vault-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        dir
    }

    /// An opened file reads as its plaintext — real name, plaintext size and
    /// hash — with the ciphertext's hash kept beside it; what it opened to is
    /// remembered, so the next settle downloads nothing; and a file sealed to
    /// another key fails by id rather than passing as something unreadable.
    #[test]
    fn the_opener_reads_encrypted_files_as_their_plaintext_and_remembers() {
        let key = drive_key("soak-rig-1@getjoinery.com", "pw");
        let stranger = drive_key("someone-else@getjoinery.com", "pw");
        let (cipher, entity, sealed) = sealed_file(1, "plan.txt", b"the plan", &key.public_key);
        let (other_cipher, other, other_sealed) =
            sealed_file(2, "theirs.txt", b"not ours", &stranger.public_key);
        let api = Holding {
            bytes: BTreeMap::from([("u1".to_string(), cipher.clone()), ("u2".to_string(), other_cipher)]),
            downloads: Mutex::new(0),
        };
        let tree = |with_other: bool| {
            let mut tree = ServerTree::default();
            tree.files.insert(1, entity.clone());
            tree.sealed.insert(1, sealed.clone());
            if with_other {
                tree.files.insert(2, other.clone());
                tree.sealed.insert(2, other_sealed.clone());
            }
            tree
        };
        let dir = scratch("opener");
        let map_file = dir.join("vault-contents.json");

        let mut first = tree(true);
        let failures = Opener::new(key.clone(), map_file.clone()).open(&api, &mut first).failures;
        assert_eq!(failures.len(), 1, "{failures:?}");
        assert!(failures[0].starts_with("file 2:"), "{failures:?}");
        let opened = &first.files[&1];
        assert_eq!(opened.name, "plan.txt");
        assert_eq!(opened.size, 8);
        assert_eq!(opened.sha256.as_deref(), Some(hash_bytes(b"the plan").as_str()));
        assert_eq!(first.cipher[&1], hash_bytes(&cipher));
        assert_eq!(
            first.plain_content(opened, &hash_bytes(&cipher)),
            Some(hash_bytes(b"the plan"))
        );
        assert_eq!(first.plain_content(opened, "never-opened"), None);
        assert!(first.ciphertexts().contains(&hash_bytes(&cipher)));
        assert_eq!(*api.downloads.lock().unwrap(), 1);

        let mut second = tree(false);
        assert!(Opener::new(key, map_file).open(&api, &mut second).failures.is_empty());
        assert_eq!(second.files[&1].sha256.as_deref(), Some(hash_bytes(b"the plan").as_str()));
        assert_eq!(*api.downloads.lock().unwrap(), 1, "a known ciphertext was fetched again");
        let _ = std::fs::remove_dir_all(&dir);
    }

    /// The key does what a vault key is for: a file key sealed to its public
    /// half opens with its secret half.
    #[test]
    fn a_file_key_sealed_to_it_opens_with_it() {
        let key = drive_key("soak-rig-1@getjoinery.com", "pw");
        let fk = jd_crypto::drive::FileKey::generate();
        let wrapped = jd_crypto::drive::wrap_file_key_to(&fk, &key.public_key).unwrap();
        let opened =
            jd_crypto::drive::open_wrapped_file_key(&wrapped, &key.secret_pkcs8, &key.public_key)
                .unwrap();
        let cid = jd_crypto::drive::new_content_id();
        let sealed = jd_crypto::drive::encrypt_content(b"hello", &fk, &cid);
        assert_eq!(jd_crypto::drive::decrypt_content(&sealed, &opened, &cid).unwrap(), b"hello");
    }
}
