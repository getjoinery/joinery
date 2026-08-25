//! A Joinery Drive server that lives in a `Vec`.
//!
//! This is not a stub that returns whatever a test needs. It is a working
//! implementation of the Part-I contract — the change feed with its retention
//! window and its reset, id-keyed batch stat, the paged index across two id
//! spaces, resumable sequential-chunk uploads with offset enforcement, dedup by
//! possessed hash, quota at completion, idempotency, and trash. If the engine
//! can be made to do something wrong by a server that is behaving correctly,
//! that is a bug the engine has and this is where it shows up.
//!
//! A mock always risks becoming a description of what we *believe* the server
//! does. Two things keep it honest. It speaks the same JSON the real server
//! speaks, action by action, so nothing is smoothed over at the boundary. And
//! the live gate runs the same scenario script against this and against a real
//! deployment and diffs the observable outcomes — where they disagree, one of
//! the two is wrong and the gate fails until somebody decides which.
//!
//! It also keeps something a real server keeps and never exposes: **every
//! version of every file that was ever committed**. That is the oracle. The
//! invariant "no committed content is ever lost" is only checkable because
//! something in the simulation remembers everything that was ever true.

use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};

use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};

use crate::clock::SimClock;

/// Chunk size the mock advertises. Small on purpose: a 3-byte chunk over a
/// 10-byte file means an interruption lands in the middle of a transfer nearly
/// every time, which is the whole point.
pub const CHUNK_BYTES: u64 = 3;

/// How long a minted download URL is good for.
pub const SIGNED_URL_TTL_MS: u64 = 3_600_000;

/// Everything in this mock belongs to one user. Encrypted uploads must seal a
/// key to them or be refused — a vault file whose owner can never open it is a
/// file they can see, are billed for, and have permanently lost.
pub const OWNER_USER_ID: i64 = 1;

#[derive(Debug, Clone)]
struct FolderRow {
    id: i64,
    parent: Option<i64>,
    name: String,
    trashed: bool,
    encrypted: bool,
}

#[derive(Debug, Clone)]
struct FileRow {
    id: i64,
    folder: Option<i64>,
    name: String,
    sha256: String,
    size: u64,
    trashed: bool,
    head_change_id: i64,
    modified_time: Option<String>,
    /// The server holds these bytes encrypted. It models only what the SERVER
    /// knows: the name and sha here are then the placeholder and the ciphertext
    /// hash, exactly as the real export sends them.
    encrypted: bool,
    /// This caller's content key, sealed to their vault. A separate row in the
    /// real schema, and separate here for the same reason: it is issued on its
    /// own schedule, so a file can legitimately be visible for a while before
    /// the key to it is.
    wrapped_file_key: Option<String>,
    /// The `{name, mime, size, cid, …}` blob. Opaque to the server, exactly as
    /// it is to the real one — it is stored and handed back, never inspected.
    encrypted_metadata: Option<String>,
}

#[derive(Debug, Clone)]
struct ChangeRow {
    change_id: i64,
    entity_type: &'static str,
    entity_id: i64,
    kind: &'static str,
    /// Which device caused it. A device that skips its own changes without
    /// this would have to guess, and guessing wrong means either a loop or a
    /// missed update.
    actor: Option<String>,
}

/// One encrypted file as the server holds it, with everything a holder of the
/// key needs to see it the way its owner does.
///
/// The server's own view of a vault is not comparable with a disk: the title is
/// a placeholder, the hash is of ciphertext, and the real name is inside a blob
/// it cannot open. Anything asserting that the two sides agree has to translate
/// one into the other first, and this is what it translates from.
#[derive(Debug, Clone)]
pub struct VaultFile {
    pub id: i64,
    /// The folder it sits in, as a path. Empty for the drive root. Folder names
    /// inside a vault are plaintext on the server by design, so this needs no
    /// translating.
    pub folder_path: String,
    /// The opaque title the server stores, never the user's name.
    pub placeholder: String,
    pub wrapped_file_key: Option<String>,
    pub encrypted_metadata: Option<String>,
    /// The bytes of its current version, as stored.
    pub ciphertext: Option<Vec<u8>>,
}

/// One encrypted file's every stored version, with what it takes to read them.
#[derive(Debug, Clone)]
pub struct EncryptedContent {
    pub content_id: String,
    pub wrapped_file_key: String,
    pub ciphertexts: Vec<Vec<u8>>,
}

/// One committed content for a file. Never removed — this is the oracle.
#[derive(Debug, Clone)]
pub struct VersionRow {
    pub file_id: i64,
    pub sha256: String,
    pub size: u64,
    pub change_id: i64,
}

#[derive(Debug, Clone)]
struct UploadSession {
    token: String,
    name: String,
    folder: Option<i64>,
    /// Set when this is a new version of an existing file rather than a new one.
    file_id: Option<i64>,
    expected: u64,
    sha256: String,
    received: Vec<u8>,
    created_ms: u64,
    /// The destination is a vault, so the bytes arriving are ciphertext and the
    /// completion call must carry metadata and keys.
    encrypted: bool,
}

#[derive(Debug, Clone)]
struct SignedUrl {
    sha256: String,
    issued_ms: u64,
}

#[derive(Debug)]
struct ServerState {
    folders: BTreeMap<i64, FolderRow>,
    files: BTreeMap<i64, FileRow>,
    next_folder_id: i64,
    next_file_id: i64,
    next_change_id: i64,
    changes: Vec<ChangeRow>,
    /// Feed positions below this have been pruned. A client whose cursor is
    /// older gets told to start over rather than being handed a plausible
    /// answer with a hole in it.
    retained_from: i64,
    /// Content-addressed bytes. Two files with the same content share one
    /// entry, which is what makes dedup a real behaviour rather than a claim.
    blobs: BTreeMap<String, Vec<u8>>,
    versions: Vec<VersionRow>,
    uploads: BTreeMap<String, UploadSession>,
    signed: BTreeMap<String, SignedUrl>,
    next_url: u64,
    /// Idempotency key → the action and body it was first used with, and the
    /// response that produced. The request is kept because the key alone is not
    /// the promise: the platform stores a body hash beside the key and refuses a
    /// second, different request that reuses it (`ApiLogicEndpoint`
    /// § idempotencyResolveExisting).
    idempotency: BTreeMap<String, (String, Value, Value)>,
    /// How many times a key was offered for a second, different request. A
    /// client that cannot reproduce its own request byte for byte is refused
    /// here every time it asks, so any count above zero is a client that will
    /// go on asking forever.
    key_conflicts: usize,
    quota_bytes: Option<u64>,
    next_token: u64,
    /// Who is calling. Set by the network layer per device.
    actor: Option<String>,
    /// Drive vault public keys by user id — what an encrypted upload seals its
    /// file key to. A user absent from here has no vault, which is a state the
    /// upload path has to handle rather than a state that cannot happen.
    vault_public_keys: BTreeMap<i64, String>,
}

/// The server. Cloning shares it — two simulated devices hold the same one.
#[derive(Clone)]
pub struct MockServer {
    state: Arc<Mutex<ServerState>>,
    clock: SimClock,
    /// Run when an upload is about to be finalized, so a scenario can change
    /// the disk underneath an operation that is still in flight.
    ///
    /// Every other fault here is a network fault, and that left one very
    /// ordinary event unreachable: the user saving the file again while it is
    /// uploading. Applications do it to themselves — a safe-save renames a new
    /// inode over the path the moment the write finishes — so it is not a rare
    /// interleaving, and the client has a whole branch for it that nothing
    /// could exercise. The soak rig found what lived in that branch.
    during_upload: Arc<Mutex<Option<Box<dyn FnMut() + Send>>>>,
}

impl std::fmt::Debug for MockServer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MockServer").finish_non_exhaustive()
    }
}

/// What the mock answers with when a request is refused. Mirrors the platform's
/// closed `errortype` vocabulary, because the engine branches on it.
#[derive(Debug, Clone)]
pub struct ApiRefusal {
    pub status: u16,
    pub errortype: &'static str,
    pub message: String,
    pub data: Value,
}

pub type ServerResult = Result<Value, ApiRefusal>;

fn refuse(status: u16, errortype: &'static str, message: impl Into<String>) -> ApiRefusal {
    ApiRefusal {
        status,
        errortype,
        message: message.into(),
        data: Value::Null,
    }
}

/// A refusal a client can act on: the name is taken, and the marker says so in
/// a field rather than in English. Matches what the real endpoints return, so
/// the engine meets the same shape here as on a live server.
fn name_taken(message: &'static str) -> ApiRefusal {
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: message.into(),
        data: serde_json::json!({ "reason": "name_taken" }),
    }
}

/// The destination folder is in the trash, so nothing new may go into it. The
/// real server refuses this (`DriveHelper::folder_is_trashed`) at every verb
/// that places an item; without it here the mock would take the write and hold
/// a live item under a folder no listing shows, which is exactly the state that
/// went unnoticed on a real box until the rig grew this refusal.
///
/// The refusal names the folder, exactly as the real one does. A client cannot
/// work it out for itself: the parent it sent may not be the parent its
/// operation was planned with, since a create re-resolves the destination from
/// what it knows at the moment it runs. Guessing from the plan condemned a live
/// folder that merely shared a name with the trashed one.
/// A new version of a file that is in the trash, refused exactly as the real
/// server refuses it (`drive_upload_init_logic` / `drive_upload_complete_logic`).
///
/// The mock used to take the bytes AND quietly un-trash the row, which the real
/// server never does — it would leave the version inside a hidden file. That
/// kindness hid a client defect for the life of the sweep: an upload planned
/// before somebody's delete kept succeeding after it, the file came back on the
/// uploading device only, and the device that deleted it had already dropped
/// the tombstone. Both then reported themselves settled, permanently disagreeing.
fn file_trashed(file_id: i64) -> ApiRefusal {
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: "That file is in the trash.".into(),
        data: serde_json::json!({ "reason": "file_trashed", "file_id": file_id }),
    }
}

/// The move would carry something across the edge of a vault.
///
/// The server holds no key, so it cannot turn plaintext into ciphertext or back
/// again -- which means a file cannot change protection level by being moved.
/// The real server says so outright (`drive_move_logic`: "Move a Fortress file
/// by re-uploading it; only your browser can convert it", and for a folder "A
/// Fortress folder can only sit at the Drive root or inside another Fortress
/// folder"), and the crossing is done by re-uploading at the destination and
/// trashing the source.
///
/// The mock had no such rule and took the move. Both directions were silent
/// and both are the failure the encrypted design exists to prevent: a plaintext
/// file with its real name sitting inside a vault, or ciphertext nobody can
/// open sitting outside one. Nothing in a tree, a listing or a convergence
/// check would have shown either.
fn protection_boundary(entity_type: &str, folder_id: Option<i64>) -> ApiRefusal {
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: if entity_type == "folder" {
            "A vault folder can only sit at the drive root or inside another vault folder.".into()
        } else {
            "Move a vault file by re-uploading it; only a key holder can convert it.".to_string()
        },
        data: serde_json::json!({
            "reason": "protection_boundary",
            "folder_id": folder_id,
        }),
    }
}

fn parent_trashed(folder_id: i64) -> ApiRefusal {
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: "That folder is in the trash.".into(),
        data: serde_json::json!({ "reason": "parent_trashed", "folder_id": folder_id }),
    }
}

pub fn sha256_hex(bytes: &[u8]) -> String {
    let mut h = Sha256::new();
    h.update(bytes);
    h.finalize().iter().map(|b| format!("{b:02x}")).collect()
}

impl MockServer {
    pub fn new(clock: SimClock) -> MockServer {
        MockServer {
            state: Arc::new(Mutex::new(ServerState {
                folders: BTreeMap::new(),
                files: BTreeMap::new(),
                next_folder_id: 500,
                next_file_id: 900,
                next_change_id: 0,
                changes: Vec::new(),
                retained_from: 0,
                blobs: BTreeMap::new(),
                versions: Vec::new(),
                uploads: BTreeMap::new(),
                signed: BTreeMap::new(),
                next_url: 0,
                idempotency: BTreeMap::new(),
                key_conflicts: 0,
                quota_bytes: None,
                next_token: 0,
                actor: None,
                vault_public_keys: BTreeMap::new(),
            })),
            clock,
            during_upload: Arc::new(Mutex::new(None)),
        }
    }

    /// Do something to the world each time an upload reaches completion, just
    /// before the server names the file.
    ///
    /// That instant is the one the client cannot control: the bytes are the
    /// server's, the answer is not back yet, and whatever the user does to the
    /// file now happens to a file the client believes it is still holding
    /// still. Scenarios use it to rewrite, rename or delete the file being
    /// uploaded.
    pub fn while_completing_an_upload(&self, f: impl FnMut() + Send + 'static) {
        *self.during_upload.lock().unwrap() = Some(Box::new(f));
    }

    /// Register a user's Drive vault public key.
    ///
    /// Everything in this mock belongs to one owner, [`OWNER_USER_ID`], because
    /// the sharing model is not what these scenarios are about — what matters is
    /// that an encrypted upload has to ASK who the readers are and seal to every
    /// one of them, and that a reader without a vault is a case it survives.
    pub fn set_vault_public_key(&self, user_id: i64, public_key: &str) {
        self.state
            .lock()
            .unwrap()
            .vault_public_keys
            .insert(user_id, public_key.to_string());
    }

    /// Who subsequent calls are attributed to.
    pub fn acting_as(&self, device: Option<&str>) {
        self.state.lock().unwrap().actor = device.map(|d| d.to_string());
    }

    pub fn set_quota_bytes(&self, quota: Option<u64>) {
        self.state.lock().unwrap().quota_bytes = quota;
    }

    /// Throw away feed history below `keep_from`, so a client that has been
    /// offline long enough is told to re-list. Real servers prune; a client
    /// that has never been made to handle it will fail the first time it
    /// happens, months after shipping.
    pub fn prune_feed_before(&self, keep_from: i64) {
        let mut st = self.state.lock().unwrap();
        st.retained_from = keep_from;
        st.changes.retain(|c| c.change_id >= keep_from);
    }

    /// Expire every outstanding signed URL — the 24-hour sweep, on demand.
    pub fn expire_signed_urls(&self) {
        self.state.lock().unwrap().signed.clear();
    }

    /// Every content ever committed. The oracle for "nothing was lost".
    pub fn all_versions(&self) -> Vec<VersionRow> {
        self.state.lock().unwrap().versions.clone()
    }

    /// The bytes behind a content hash, for whatever wants to check them.
    pub fn blob(&self, sha256: &str) -> Option<Vec<u8>> {
        self.state.lock().unwrap().blobs.get(sha256).cloned()
    }

    /// Spoil what the server is holding for a file, and have it declare the
    /// spoiled bytes as if they were always the right ones.
    ///
    /// This is the shape a hash check cannot catch and a retry cannot fix: the
    /// bytes come down exactly as advertised and still fail their
    /// authentication tag. It stands in for the real ways that happens —
    /// storage that rotted and was re-hashed, or a client that uploaded
    /// ciphertext it had already corrupted — and for a wrapped key that opens
    /// but is the wrong one, which fails identically from the client's side.
    ///
    /// Deliberately silent: no change is recorded, because a server that knew
    /// something had happened would have said so. To the client nothing has
    /// changed except that the file no longer opens.
    pub fn rot_ciphertext(&self, file_id: i64, replacement: &[u8]) {
        let sha = sha256_hex(replacement);
        let mut st = self.state.lock().unwrap();
        st.blobs.insert(sha.clone(), replacement.to_vec());
        let Some(f) = st.files.get_mut(&file_id) else {
            panic!("rot_ciphertext: no file {file_id}");
        };
        let was = f.sha256.clone();
        f.sha256 = sha.clone();
        f.size = replacement.len() as u64;
        for v in st.versions.iter_mut().filter(|v| v.file_id == file_id) {
            if v.sha256 == was {
                v.sha256 = sha.clone();
                v.size = replacement.len() as u64;
            }
        }
    }

    pub fn latest_change_id(&self) -> i64 {
        self.state.lock().unwrap().next_change_id
    }

    /// How many live folders and files the server holds.
    ///
    /// Not derivable from [`tree`](Self::tree): two siblings may legitimately
    /// carry the same name, and a path map collapses them into one. Anything
    /// counting *entities* has to count entities.
    pub fn live_counts(&self) -> (usize, usize) {
        let st = self.state.lock().unwrap();
        (
            st.folders.values().filter(|f| !f.trashed).count(),
            st.files.values().filter(|f| !f.trashed).count(),
        )
    }

    /// The live folder standing at this path, if any. Paths are the same
    /// slash-joined form [`tree`](Self::tree) reports.
    pub fn folder_id_at(&self, path: &str) -> Option<i64> {
        let st = self.state.lock().unwrap();
        st.folders
            .values()
            .filter(|f| !f.trashed)
            .find(|f| Self::folder_path(&st, f.id).as_deref() == Some(path))
            .map(|f| f.id)
    }

    /// Destroy one folder outright, whatever state it is in. The single-entity
    /// form of [`purge_trashed`](Self::purge_trashed).
    pub fn forget_folder(&self, id: i64) -> bool {
        self.state.lock().unwrap().folders.remove(&id).is_some()
    }

    /// Destroy trashed entities outright, the way the retention purge does on a
    /// real server.
    ///
    /// Not an API action — no client asks for this, it happens to them. It is
    /// the only way to reach the state where a client holds a record of
    /// something the server's index cannot account for at all, which is
    /// different from the item merely being in the trash, and which the engine
    /// has to survive without deciding its own store is broken.
    pub fn purge_trashed(&self) -> usize {
        let mut st = self.state.lock().unwrap();
        let folders: Vec<i64> = st
            .folders
            .values()
            .filter(|f| f.trashed)
            .map(|f| f.id)
            .collect();
        let files: Vec<i64> = st
            .files
            .values()
            .filter(|f| f.trashed)
            .map(|f| f.id)
            .collect();
        for id in &folders {
            st.folders.remove(id);
        }
        for id in &files {
            st.files.remove(id);
        }
        folders.len() + files.len()
    }

    /// Live items whose parent folder is trashed or absent, described for a
    /// failure message.
    ///
    /// Such an item is owned, undeleted and unreachable: no listing walks into
    /// a trashed folder, so no client can be told where it lives, and it sits
    /// on the server forever costing quota nobody can account for. One live
    /// account had eighty-three, every one of them created after its parent
    /// went to the trash.
    pub fn live_orphans(&self) -> Vec<String> {
        let st = self.state.lock().unwrap();
        let mut out = Vec::new();
        let dead = |parent: Option<i64>| match parent {
            None => false, // the root is always a place
            Some(p) => st.folders.get(&p).map(|f| f.trashed).unwrap_or(true),
        };
        for f in st.folders.values() {
            if !f.trashed && dead(f.parent) {
                out.push(format!("folder {} ({}) under {:?}", f.id, f.name, f.parent));
            }
        }
        for f in st.files.values() {
            if !f.trashed && dead(f.folder) {
                out.push(format!("file {} ({}) under {:?}", f.id, f.name, f.folder));
            }
        }
        out.sort();
        out
    }

    /// A flat picture of the live tree, as `path -> content hash` (folders map
    /// to `None`). What the convergence check compares against the disk.
    ///
    /// Same-named siblings collapse to one key here, deliberately: a filesystem
    /// cannot hold two things at one path either, and reconciling that is the
    /// client's job (`jd_vfs::names::resolve_siblings`). Use
    /// [`live_counts`](Self::live_counts) when the question is about entities.
    pub fn tree(&self) -> BTreeMap<String, Option<String>> {
        let st = self.state.lock().unwrap();
        let mut out = BTreeMap::new();
        for f in st.folders.values() {
            if f.trashed {
                continue;
            }
            if let Some(path) = Self::folder_path(&st, f.id) {
                out.insert(path, None);
            }
        }
        for f in st.files.values() {
            if f.trashed {
                continue;
            }
            let parent = match f.folder {
                None => Some(String::new()),
                Some(p) => Self::folder_path(&st, p),
            };
            if let Some(prefix) = parent {
                let path = if prefix.is_empty() {
                    f.name.clone()
                } else {
                    format!("{}/{}", prefix, f.name)
                };
                out.insert(path, Some(f.sha256.clone()));
            }
        }
        out
    }

    /// Seed content without going through the upload protocol — how a scenario
    /// says "this was already on the server before the client existed".
    pub fn seed_file(&self, folder: Option<i64>, name: &str, bytes: &[u8]) -> i64 {
        let sha = sha256_hex(bytes);
        let mut st = self.state.lock().unwrap();
        st.next_file_id += 1;
        let id = st.next_file_id;
        st.blobs.insert(sha.clone(), bytes.to_vec());
        let change_id = Self::record(&mut st, "file", id, "created");
        st.files.insert(
            id,
            FileRow {
                id,
                folder,
                name: name.to_string(),
                sha256: sha.clone(),
                size: bytes.len() as u64,
                trashed: false,
                head_change_id: change_id,
                modified_time: None,
                encrypted: false,
                wrapped_file_key: None,
                encrypted_metadata: None,
            },
        );
        st.versions.push(VersionRow {
            file_id: id,
            sha256: sha,
            size: bytes.len() as u64,
            change_id,
        });
        id
    }

    /// Seed a file the server holds ENCRYPTED.
    ///
    /// `name` and `bytes` are what the server sees, which for an encrypted file
    /// is the placeholder name and the ciphertext — never the user's name or
    /// their plaintext. Handing this the real name would model a server that
    /// can read what it stores, and a client tested against it would look
    /// correct while doing the wrong thing against the real one.
    pub fn seed_encrypted_file(&self, folder: Option<i64>, name: &str, ciphertext: &[u8]) -> i64 {
        let id = self.seed_file(folder, name, ciphertext);
        let mut st = self.state.lock().unwrap();
        if let Some(f) = st.files.get_mut(&id) {
            f.encrypted = true;
        }
        id
    }

    /// Seed a file into a vault the way the browser would have put it there:
    /// real ciphertext, real metadata, a real grant sealed to `vault`.
    ///
    /// `name` and `plaintext` are the user's — everything the server ends up
    /// holding is derived here, exactly as a real client derives it, so a client
    /// tested against this has to do the whole job to read the file back.
    /// Returns the file id.
    pub fn seed_vault_file(
        &self,
        folder: Option<i64>,
        name: &str,
        plaintext: &[u8],
        vault_public_key_b64: &str,
    ) -> i64 {
        let file_key = jd_crypto::drive::FileKey::generate();
        let content_id = jd_crypto::drive::new_content_id();
        let ciphertext = jd_crypto::drive::encrypt_content(plaintext, &file_key, &content_id);
        let meta = jd_crypto::drive::FileMetadata::new(
            name,
            "application/octet-stream",
            plaintext.len() as u64,
            &content_id,
        );
        let blob = jd_crypto::drive::encrypt_metadata(&meta, &file_key).expect("metadata seals");
        let grant = jd_crypto::drive::wrap_file_key_to(&file_key, vault_public_key_b64)
            .expect("a vault public key");

        let id = self.seed_file(folder, &format!("enc-{content_id}"), &ciphertext);
        let mut st = self.state.lock().unwrap();
        if let Some(f) = st.files.get_mut(&id) {
            f.encrypted = true;
            f.encrypted_metadata = Some(blob);
            f.wrapped_file_key = Some(grant);
            // The real export sends no plaintext modification time for an
            // encrypted file; the true one lives inside the blob.
            f.modified_time = None;
        }
        id
    }

    /// Everything the server holds encrypted, in a shape the harness can open.
    ///
    /// The no-loss oracle works in plaintext hashes and the server stores
    /// ciphertext, so without this every encrypted file is invisible to it —
    /// and an invariant that cannot see the thing it protects is worse than no
    /// invariant, because it still passes. The content id rides in the
    /// placeholder name, which is where both the browser and this client put
    /// it, so the harness needs nothing the server does not really hold.
    pub fn encrypted_contents(&self) -> Vec<EncryptedContent> {
        let st = self.state.lock().unwrap();
        st.files
            .values()
            .filter(|f| f.encrypted)
            .filter_map(|f| {
                Some(EncryptedContent {
                    content_id: f.name.strip_prefix("enc-")?.to_string(),
                    wrapped_file_key: f.wrapped_file_key.clone()?,
                    ciphertexts: st
                        .versions
                        .iter()
                        .filter(|v| v.file_id == f.id)
                        .filter_map(|v| st.blobs.get(&v.sha256).cloned())
                        .collect(),
                })
            })
            .collect()
    }

    /// Issue this caller their key to an encrypted file.
    ///
    /// Separate from seeding the file, because that is how it works: the file
    /// row and the grant are different records with different lifetimes, and the
    /// gap between them is a state the client has to handle rather than a state
    /// that cannot happen. A scenario that always seeded both together would
    /// never produce a file whose key has not arrived yet.
    pub fn grant_file_key(&self, file_id: i64, wrapped_file_key: &str) {
        let mut st = self.state.lock().unwrap();
        if let Some(f) = st.files.get_mut(&file_id) {
            f.wrapped_file_key = Some(wrapped_file_key.to_string());
        }
    }

    /// Every live encrypted file, in a shape something holding the key can read.
    pub fn vault_files(&self) -> Vec<VaultFile> {
        let st = self.state.lock().unwrap();
        let mut out = Vec::new();
        for f in st.files.values() {
            if f.trashed || !f.encrypted {
                continue;
            }
            let folder_path = match f.folder {
                None => Some(String::new()),
                Some(p) => Self::folder_path(&st, p),
            };
            let Some(folder_path) = folder_path else {
                continue;
            };
            out.push(VaultFile {
                id: f.id,
                folder_path,
                placeholder: f.name.clone(),
                wrapped_file_key: f.wrapped_file_key.clone(),
                encrypted_metadata: f.encrypted_metadata.clone(),
                ciphertext: st.blobs.get(&f.sha256).cloned(),
            });
        }
        out.sort_by_key(|f| f.id);
        out
    }

    /// The path of every live vault folder, outermost first.
    ///
    /// A device with no key does not materialize a vault folder at all -- an
    /// ordinary directory of that name would be a plaintext drop box inside
    /// what the user believes is the private folder, and anything they put
    /// there would go up in the clear. So the folder is deliberately absent,
    /// and anything comparing that device's disk with the server has to know
    /// which paths it is entitled to be missing.
    pub fn vault_folder_paths(&self) -> Vec<String> {
        let st = self.state.lock().unwrap();
        let mut out = Vec::new();
        for f in st.folders.values() {
            if f.trashed || !f.encrypted {
                continue;
            }
            if let Some(path) = Self::folder_path(&st, f.id) {
                out.push(path);
            }
        }
        out.sort_by_key(|p| p.len());
        out
    }

    /// Every live thing sitting inside a vault that the server can nonetheless
    /// read.
    ///
    /// The leak the whole encrypted design is arranged around, put as a question
    /// a harness can ask: is there anything under an encrypted folder whose
    /// bytes and whose name the server holds in the clear? A file is flagged
    /// encrypted at upload time from its destination folder, so the only ways
    /// into this list are a client that put plaintext where a vault was, or a
    /// server path that carried something across the boundary without
    /// converting it. Both are silent -- the tree looks right, every device
    /// agrees it is synced, and the user's private folder is private to nobody.
    pub fn plaintext_inside_a_vault(&self) -> Vec<String> {
        let st = self.state.lock().unwrap();
        let mut out = Vec::new();
        for f in st.files.values() {
            if f.trashed || f.encrypted {
                continue;
            }
            if Self::inside_a_vault(&st, f.folder) {
                out.push(format!("file {} in the clear, named {}", f.id, f.name));
            }
        }
        for f in st.folders.values() {
            if f.trashed || f.encrypted {
                continue;
            }
            if Self::inside_a_vault(&st, f.parent) {
                out.push(format!("folder {} not marked encrypted, named {}", f.id, f.name));
            }
        }
        out.sort();
        out
    }

    /// Does this parent chain reach an encrypted folder?
    fn inside_a_vault(st: &ServerState, mut parent: Option<i64>) -> bool {
        for _ in 0..256 {
            let Some(id) = parent else { return false };
            let Some(f) = st.folders.get(&id) else {
                return false;
            };
            if f.encrypted {
                return true;
            }
            parent = f.parent;
        }
        false
    }

    /// Seed an encrypted vault folder.
    pub fn seed_encrypted_folder(&self, parent: Option<i64>, name: &str) -> i64 {
        let id = self.seed_folder(parent, name);
        let mut st = self.state.lock().unwrap();
        if let Some(f) = st.folders.get_mut(&id) {
            f.encrypted = true;
        }
        id
    }

    pub fn seed_folder(&self, parent: Option<i64>, name: &str) -> i64 {
        let mut st = self.state.lock().unwrap();
        st.next_folder_id += 1;
        let id = st.next_folder_id;
        Self::record(&mut st, "folder", id, "created");
        // Inherited from the parent, exactly as `drive_folder_create` does it.
        // Seeding was the one way to make a folder, and it always made a
        // plaintext one -- so a scenario that put a subfolder inside a vault got
        // a folder the server thought was ordinary, holding files it would then
        // store in the clear. A state the real server cannot be talked into, and
        // one every check would have called correct.
        let encrypted = parent
            .and_then(|p| st.folders.get(&p))
            .map(|f| f.encrypted)
            .unwrap_or(false);
        st.folders.insert(
            id,
            FolderRow {
                id,
                parent,
                name: name.to_string(),
                trashed: false,
                encrypted,
            },
        );
        id
    }

    // ---- the action surface ------------------------------------------------

    /// `POST /action/{name}`.
    pub fn action(&self, name: &str, body: &Value) -> ServerResult {
        match name {
            "drive_changes" => self.drive_changes(body),
            "drive_stat" => self.drive_stat(body),
            "drive_index" => self.drive_index(body),
            "drive_folder_create" => self.drive_folder_create(body),
            "drive_rename" => self.drive_rename(body),
            "drive_move" => self.drive_move(body),
            "drive_trash" => self.drive_trash(body),
            "drive_restore" => self.drive_restore(body),
            "drive_versions" => self.drive_versions(body),
            "drive_upload_init" => self.drive_upload_init(body),
            "drive_public_keys" => self.drive_public_keys(body),
            "drive_upload_complete" => self.drive_upload_complete(body),
            other => Err(refuse(404, "NotFound", format!("no such action: {other}"))),
        }
    }

    /// The same, with the replay cache in front of it.
    ///
    /// This is the mechanism the whole crash story rests on. The engine writes
    /// down its key, sends, and may die before it learns the answer. The retry
    /// after restart carries the same key and gets the same answer — one
    /// upload, one folder, one move, no matter how many times the machine died
    /// trying.
    /// Reusing a key for a *different* request is refused rather than answered,
    /// which is what the platform does and is not a detail: the check runs ahead
    /// of every other branch, so a key whose request has changed can never be
    /// taken over or retried. It fails the same way forever. A mock that
    /// replayed the first answer instead would let a client ship a key that
    /// outlives the request it names, and every scenario here would pass while
    /// real devices wedged.
    pub fn action_idempotent(&self, name: &str, body: &Value, key: &str) -> ServerResult {
        let prior = {
            let mut st = self.state.lock().unwrap();
            match st.idempotency.get(key) {
                None => None,
                Some((prior_action, prior_body, prior_response)) => {
                    if prior_action != name || prior_body != body {
                        st.key_conflicts += 1;
                        return Err(ApiRefusal {
                            status: 409,
                            errortype: "ActionError",
                            message:
                                "This Idempotency-Key was already used with a different request"
                                    .into(),
                            data: json!({ "reason": "idempotency_key_reused" }),
                        });
                    }
                    Some(prior_response.clone())
                }
            }
        };
        if let Some(prior_response) = prior {
            return Ok(prior_response);
        }
        let out = self.action(name, body)?;
        self.state.lock().unwrap().idempotency.insert(
            key.to_string(),
            (name.to_string(), body.clone(), out.clone()),
        );
        Ok(out)
    }

    /// How many times a key was offered for a second, different request.
    pub fn key_conflicts(&self) -> usize {
        self.state.lock().unwrap().key_conflicts
    }

    fn drive_changes(&self, body: &Value) -> ServerResult {
        let cursor = body.get("cursor").and_then(Value::as_i64).unwrap_or(0);
        let limit = body.get("limit").and_then(Value::as_i64).unwrap_or(500);
        let st = self.state.lock().unwrap();

        // The cursor points into history we no longer have. Anything but a
        // reset here hands the client a feed with a hole in it, and a hole in
        // a change feed is a file that silently never syncs again.
        if cursor > 0 && cursor < st.retained_from {
            return Ok(json!({
                "ok": true,
                "reset": true,
                "changes": [],
                "next_cursor": st.next_change_id,
            }));
        }

        let mut out = Vec::new();
        let mut next = cursor;
        for c in st.changes.iter().filter(|c| c.change_id > cursor) {
            if out.len() as i64 >= limit {
                break;
            }
            out.push(json!({
                "change_id": c.change_id,
                "entity_type": c.entity_type,
                "entity_id": c.entity_id,
                "kind": c.kind,
                "actor": c.actor,
            }));
            next = c.change_id;
        }
        Ok(json!({ "ok": true, "changes": out, "next_cursor": next }))
    }

    fn drive_stat(&self, body: &Value) -> ServerResult {
        let entities = body
            .get("entities")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        if entities.is_empty() {
            return Err(refuse(
                400,
                "ValidationError",
                "No entities were requested.",
            ));
        }
        if entities.len() > 500 {
            return Err(refuse(
                400,
                "ValidationError",
                "At most 500 entities may be requested at once.",
            ));
        }
        let with_urls = body.get("urls").and_then(Value::as_bool).unwrap_or(false);

        // Dedupe: a client replaying a feed run asks for the same file several
        // times over (created, renamed, content). Answering once is correct and
        // cheaper, and the real server does exactly this.
        let mut wanted: Vec<(String, i64)> = Vec::new();
        for e in &entities {
            let t = e
                .get("entity_type")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_string();
            let id = e.get("entity_id").and_then(Value::as_i64).unwrap_or(0);
            if id <= 0 || (t != "file" && t != "folder") {
                continue;
            }
            if !wanted.iter().any(|(wt, wid)| *wt == t && *wid == id) {
                wanted.push((t, id));
            }
        }
        if wanted.is_empty() {
            return Err(refuse(
                400,
                "ValidationError",
                "No valid entities were requested.",
            ));
        }

        let now = self.clock.now_ms();
        let mut st = self.state.lock().unwrap();
        let mut items = Vec::new();
        let mut missing = Vec::new();
        for (t, id) in wanted {
            let found = if t == "folder" {
                st.folders.get(&id).map(Self::folder_export)
            } else {
                let export = st.files.get(&id).cloned();
                export.map(|f| Self::file_export(&mut st, &f, with_urls, now))
            };
            match found {
                Some(v) => items.push(v),
                // Gone or no longer visible. Reported rather than errored, so
                // the client can tell "deleted" from "the request failed" —
                // which are opposite instructions.
                None => missing.push(json!({ "entity_type": t, "entity_id": id })),
            }
        }
        Ok(json!({ "ok": true, "items": items, "missing": missing }))
    }

    fn drive_index(&self, body: &Value) -> ServerResult {
        let token = body
            .get("after_id")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        let limit = body.get("limit").and_then(Value::as_i64).unwrap_or(500) as usize;
        let (phase, after) = parse_index_token(&token);

        let mut st = self.state.lock().unwrap();
        let mut items = Vec::new();
        let mut last = token.clone();

        // Folders walk before files, and each half is keyed by its own id
        // space. That is why the cursor is a token and not an integer: folder
        // 500 and file 500 are different places, and a bare number cannot say
        // which one it means.
        let mut phase = phase;
        if phase == "folder" {
            let ids: Vec<i64> = st
                .folders
                .keys()
                .copied()
                .filter(|id| *id > after)
                .take(limit)
                .collect();
            for id in &ids {
                if let Some(f) = st.folders.get(id) {
                    items.push(Self::folder_export(f));
                    last = format!("folder:{id}");
                }
            }
            if ids.len() < limit {
                phase = "file";
                last = "file:0".to_string();
            }
        }

        let mut done = false;
        if phase == "file" && items.len() < limit {
            let start = if parse_index_token(&last).0 == "file" {
                parse_index_token(&last).1
            } else {
                0
            };
            let room = limit - items.len();
            let ids: Vec<i64> = st
                .files
                .keys()
                .copied()
                .filter(|id| *id > start)
                .take(room)
                .collect();
            done = ids.len() < room;
            for id in &ids {
                let row = st.files.get(id).cloned();
                if let Some(f) = row {
                    items.push(Self::file_export(&mut st, &f, false, 0));
                    last = format!("file:{id}");
                }
            }
        }

        Ok(json!({
            "ok": true,
            "scope": "mine",
            "items": items,
            "next_after_id": last,
            "done": done,
        }))
    }

    fn drive_folder_create(&self, body: &Value) -> ServerResult {
        let name = body
            .get("name")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        if name.is_empty() {
            return Err(refuse(400, "ValidationError", "A name is required."));
        }
        let parent = body.get("parent_id").and_then(Value::as_i64);
        let mut st = self.state.lock().unwrap();
        if let Some(p) = parent {
            match st.folders.get(&p) {
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
                Some(f) if f.trashed => return Err(parent_trashed(p)),
                Some(_) => {}
            }
        }
        // A live folder of that name in that parent already exists. The real
        // server refuses this (`DriveHelper::folder_name_taken`), and the mock
        // did not — so the engine was never once exercised against the refusal,
        // and a client that loops forever on it reached a live box before
        // anybody noticed. Trashed siblings do not count, exactly as there.
        if st
            .folders
            .values()
            .any(|f| !f.trashed && f.parent == parent && f.name == name)
        {
            // The marker, like every other name collision here and like the
            // real endpoint, which has sent `reason: name_taken` from this path
            // all along. Refusing in English only meant the client could not
            // tell this apart from a refusal it can do nothing about, so it
            // withdrew the operation and planned the identical one next pass --
            // a device that never goes quiet, against a server that had told it
            // exactly what was wrong.
            return Err(name_taken("A folder with that name already exists here."));
        }
        st.next_folder_id += 1;
        let id = st.next_folder_id;
        Self::record(&mut st, "folder", id, "created");
        let row = FolderRow {
            id,
            parent,
            name,
            trashed: false,
            // Encryption is inherited, never asked for: a folder created inside
            // a vault is part of that vault. Creating a vault at the ROOT is a
            // browser ceremony this surface does not offer.
            encrypted: parent
                .and_then(|p| st.folders.get(&p))
                .map(|f| f.encrypted)
                .unwrap_or(false),
        };
        st.folders.insert(id, row.clone());
        Ok(json!({ "ok": true, "folder": Self::folder_export(&row) }))
    }

    fn drive_rename(&self, body: &Value) -> ServerResult {
        let (t, id) = Self::target(body)?;
        let name = body
            .get("name")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        let enc_metadata = body
            .get("encrypted_metadata")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        let mut st = self.state.lock().unwrap();

        // An encrypted file's display name lives INSIDE its metadata blob and
        // its stored title stays the opaque placeholder forever. A plaintext
        // name here would hand the server the one thing it is not supposed to
        // have, so it is refused rather than quietly accepted.
        let encrypted_file = t == "file" && st.files.get(&id).map(|f| f.encrypted).unwrap_or(false);
        if encrypted_file {
            if !name.is_empty() {
                return Err(refuse(
                    400,
                    "ValidationError",
                    "Encrypted files are renamed via their encrypted metadata, not a plaintext name.",
                ));
            }
            if enc_metadata.is_empty() {
                return Err(refuse(
                    400,
                    "ValidationError",
                    "Encrypted rename is missing its metadata.",
                ));
            }
        } else if name.is_empty() {
            return Err(refuse(400, "ValidationError", "A name is required."));
        }

        // Renaming onto a name a live sibling already holds is refused, exactly
        // as the real server's partial unique index refuses it. The mock checked
        // this when things were CREATED and not when they were renamed, so the
        // engine met the refusal on one path and never on the other — and a
        // rename that can never succeed was retried twenty-one times on a live
        // box, taking convergence down with it, while every scenario here passed.
        // An encrypted file is exempt: its stored title stays an opaque
        // placeholder, so siblings genuinely do share it.
        if !encrypted_file {
            let taken = if t == "folder" {
                let parent = match st.folders.get(&id) {
                    Some(f) => f.parent,
                    None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
                };
                st.folders
                    .values()
                    .any(|f| f.id != id && !f.trashed && f.parent == parent && f.name == name)
            } else {
                let folder = match st.files.get(&id) {
                    Some(f) => f.folder,
                    None => return Err(refuse(404, "NotFound", "That file does not exist.")),
                };
                st.files
                    .values()
                    .any(|f| f.id != id && !f.trashed && f.folder == folder && f.name == name)
            };
            if taken {
                return Err(name_taken("Something with that name is already here."));
            }
        }

        if t == "folder" {
            match st.folders.get_mut(&id) {
                Some(f) => f.name = name,
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
            }
            Self::record(&mut st, "folder", id, "renamed");
        } else if encrypted_file {
            if let Some(f) = st.files.get_mut(&id) {
                f.encrypted_metadata = Some(enc_metadata);
            }
            Self::record(&mut st, "file", id, "renamed");
        } else {
            match st.files.get_mut(&id) {
                Some(f) => f.name = name,
                None => return Err(refuse(404, "NotFound", "That file does not exist.")),
            }
            Self::record(&mut st, "file", id, "renamed");
        }
        Self::echo(&mut st, t, id)
    }

    fn drive_move(&self, body: &Value) -> ServerResult {
        let (t, id) = Self::target(body)?;
        // `parent_id`, matching what the platform's `drive_move` declares. This
        // read was `folder_id` for as long as the client sent `folder_id`, so
        // the two agreed with each other and disagreed with the server: every
        // move passed here and went to the root out there, and no scenario
        // could see it. A key the real endpoint does not read is not a
        // destination, so an unrecognized one is refused rather than guessed at.
        if body.get("folder_id").is_some() {
            return Err(refuse(
                400,
                "ValidationError",
                "drive_move takes parent_id; folder_id is not read.",
            ));
        }
        let dest = body.get("parent_id").and_then(Value::as_i64);
        let mut st = self.state.lock().unwrap();
        if let Some(d) = dest {
            match st.folders.get(&d) {
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
                Some(f) if f.trashed => return Err(parent_trashed(d)),
                Some(_) => {}
            }
        }
        // Encryption is a property of where a thing lives, and the server
        // cannot change it: no key, no conversion. So the level of the
        // destination has to match the level of what is arriving, or the move
        // is refused and the client makes the crossing itself.
        let dest_encrypted = dest
            .and_then(|d| st.folders.get(&d))
            .map(|f| f.encrypted)
            .unwrap_or(false);
        let item_encrypted = if t == "folder" {
            match st.folders.get(&id) {
                Some(f) => f.encrypted,
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
            }
        } else {
            match st.files.get(&id) {
                Some(f) => f.encrypted,
                None => return Err(refuse(404, "NotFound", "That file does not exist.")),
            }
        };
        if item_encrypted != dest_encrypted {
            return Err(protection_boundary(t, dest));
        }

        // Something of that name is already living there. The real server
        // refuses this — `drive_move_logic` checks `folder_name_taken` and
        // `file_name_taken` against the destination and answers `name_taken`,
        // exactly as creating and renaming do — and the mock did not.
        //
        // So a move could put two live entities under one name in one folder,
        // a state the real server cannot be talked into, and the rig then spent
        // its time on what that produced: one entry parked `DuplicateName`
        // forever, the two devices holding different bytes at one path, and
        // both of them calling it synced. Meanwhile the refusal the client
        // really gets on a move was never once exercised. Trashed siblings do
        // not count, exactly as on the other two paths.
        let occupied = if t == "folder" {
            let name = match st.folders.get(&id) {
                Some(f) => f.name.clone(),
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
            };
            st.folders
                .values()
                .any(|f| f.id != id && !f.trashed && f.parent == dest && f.name == name)
        } else {
            let name = match st.files.get(&id) {
                Some(f) => f.name.clone(),
                None => return Err(refuse(404, "NotFound", "That file does not exist.")),
            };
            st.files
                .values()
                .any(|f| f.id != id && !f.trashed && f.folder == dest && f.name == name)
        };
        if occupied {
            return Err(name_taken(
                "Something with that name already exists in the destination.",
            ));
        }
        if t == "folder" {
            // A folder inside itself detaches the whole subtree from the tree
            // and makes it unreachable — no listing walks into it, so it is
            // gone without being deleted.
            if Some(id) == dest || Self::is_descendant(&st, dest, id) {
                return Err(refuse(
                    400,
                    "ValidationError",
                    "A folder cannot be moved inside itself.",
                ));
            }
            match st.folders.get_mut(&id) {
                Some(f) => f.parent = dest,
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
            }
            Self::record(&mut st, "folder", id, "moved");
        } else {
            match st.files.get_mut(&id) {
                Some(f) => f.folder = dest,
                None => return Err(refuse(404, "NotFound", "That file does not exist.")),
            }
            Self::record(&mut st, "file", id, "moved");
        }
        Self::echo(&mut st, t, id)
    }

    fn drive_trash(&self, body: &Value) -> ServerResult {
        let (t, id) = Self::target(body)?;
        let mut st = self.state.lock().unwrap();
        if t == "folder" {
            match st.folders.get_mut(&id) {
                Some(f) => f.trashed = true,
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
            }
            Self::record(&mut st, "folder", id, "trashed");
            // Trashing a folder takes its contents with it, and each one gets
            // its own feed row: a client that only heard about the folder would
            // keep every file inside it materialized forever.
            let descendants = Self::subtree(&st, id);
            for fid in descendants.1 {
                if let Some(f) = st.files.get_mut(&fid) {
                    f.trashed = true;
                }
                Self::record(&mut st, "file", fid, "trashed");
            }
            for did in descendants.0 {
                if let Some(f) = st.folders.get_mut(&did) {
                    f.trashed = true;
                }
                Self::record(&mut st, "folder", did, "trashed");
            }
        } else {
            match st.files.get_mut(&id) {
                Some(f) => f.trashed = true,
                None => return Err(refuse(404, "NotFound", "That file does not exist.")),
            }
            Self::record(&mut st, "file", id, "trashed");
        }
        Self::echo(&mut st, t, id)
    }

    fn drive_restore(&self, body: &Value) -> ServerResult {
        let (t, id) = Self::target(body)?;
        let mut st = self.state.lock().unwrap();
        if t == "folder" {
            match st.folders.get_mut(&id) {
                Some(f) => f.trashed = false,
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
            }
            Self::record(&mut st, "folder", id, "restored");
        } else {
            match st.files.get_mut(&id) {
                Some(f) => f.trashed = false,
                None => return Err(refuse(404, "NotFound", "That file does not exist.")),
            }
            Self::record(&mut st, "file", id, "restored");
        }
        Self::echo(&mut st, t, id)
    }

    fn drive_versions(&self, body: &Value) -> ServerResult {
        let file_id = body.get("file_id").and_then(Value::as_i64).unwrap_or(0);
        let st = self.state.lock().unwrap();
        let rows: Vec<Value> = st
            .versions
            .iter()
            .filter(|v| v.file_id == file_id)
            .map(|v| json!({ "sha256": v.sha256, "size": v.size, "change_id": v.change_id }))
            .collect();
        Ok(json!({ "ok": true, "versions": rows }))
    }

    // ---- upload ------------------------------------------------------------

    /// `drive_public_keys` in folder mode: who can read this folder, and what to
    /// seal a file key to for each of them.
    ///
    /// A reader with no Drive vault comes back with a null key rather than being
    /// left out. That distinction is the whole reason this returns rows instead
    /// of a map: the uploader has to be able to tell "this person cannot be given
    /// a key" from "this person is not a reader", and only the first is worth
    /// telling anybody about.
    fn drive_public_keys(&self, body: &Value) -> ServerResult {
        let folder = body.get("folder_id").and_then(Value::as_i64);
        let st = self.state.lock().unwrap();
        if let Some(id) = folder {
            if !st.folders.contains_key(&id) {
                return Err(refuse(404, "NotFound", "That folder does not exist."));
            }
        }
        let keys = vec![json!({
            "identifier": OWNER_USER_ID.to_string(),
            "user_id": OWNER_USER_ID,
            "public_key": st.vault_public_keys.get(&OWNER_USER_ID),
        })];
        Ok(json!({ "ok": true, "keys": keys }))
    }

    fn drive_upload_init(&self, body: &Value) -> ServerResult {
        let name = body
            .get("name")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        let size = body
            .get("size_bytes")
            .and_then(Value::as_u64)
            .unwrap_or(u64::MAX);
        let sha = body
            .get("sha256")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        let folder = body.get("folder_id").and_then(Value::as_i64);
        let file_id = body.get("file_id").and_then(Value::as_i64);

        let mut st = self.state.lock().unwrap();
        if let Some(f) = folder {
            match st.folders.get(&f) {
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
                Some(fo) if fo.trashed => return Err(parent_trashed(f)),
                Some(_) => {}
            }
        }
        if let Some(id) = file_id {
            if st.files.get(&id).is_some_and(|f| f.trashed) {
                return Err(file_trashed(id));
            }
        }
        // Whether this upload is encrypted is the DESTINATION's property, never
        // a client's claim. A file goes into a vault encrypted or it does not go
        // in at all.
        let encrypted = match file_id {
            Some(id) => st.files.get(&id).map(|f| f.encrypted).unwrap_or(false),
            None => folder
                .and_then(|f| st.folders.get(&f))
                .map(|f| f.encrypted)
                .unwrap_or(false),
        };

        if name.is_empty() || size == u64::MAX {
            return Err(refuse(
                400,
                "ValidationError",
                "name and size_bytes are required.",
            ));
        }
        // A hash is what the assembled upload is checked against at completion,
        // and an ENCRYPTED upload declares one too -- of its ciphertext. The
        // mock refused one outright, reasoning from dedup: a ciphertext hash is
        // unique per encryption and could only match somebody else's bytes by
        // accident. That is true of DEDUP and was applied to the whole hash, so
        // encrypted uploads arrived with nothing to check them against and
        // corrupt bytes were stored permanently -- unreadable to every device
        // for ever, reported only as "decryption failed". The real server never
        // had that rule: `drive_upload_init_logic` skips the dedup lookup for a
        // vault destination and records `fup_expected_sha256` regardless.
        //
        // So the guard belongs on the dedup short-circuit alone, which is where
        // it is below.
        if encrypted {
            if body.get("modified_time").is_some() {
                return Err(refuse(
                    400,
                    "ValidationError",
                    "An encrypted upload carries its modification time inside its encrypted metadata, not as a parameter.",
                ));
            }
        } else if sha.is_empty() {
            return Err(refuse(400, "ValidationError", "sha256 is required."));
        }

        // Dedup: the server already holds these exact bytes, so there is
        // nothing to send. The engine has to handle a completed upload that
        // moved no bytes at all, which is a different code path from the one
        // it usually takes and therefore one worth exercising constantly.
        if !encrypted && st.blobs.contains_key(&sha) {
            let file = Self::commit_content(
                &mut st, file_id, folder, &name, &sha, size, false, None, None,
            )?;
            return Ok(json!({ "ok": true, "deduped": true, "file": file }));
        }

        st.next_token += 1;
        let token = format!("upl-{}", st.next_token);
        let now = self.clock.now_ms();
        st.uploads.insert(
            token.clone(),
            UploadSession {
                token: token.clone(),
                name,
                folder,
                file_id,
                expected: size,
                sha256: sha,
                received: Vec::new(),
                created_ms: now,
                encrypted,
            },
        );
        Ok(json!({
            "ok": true,
            "deduped": false,
            "upload_token": token,
            "chunk_bytes": CHUNK_BYTES,
        }))
    }

    /// `PUT /drive_upload/{token}` with a `Content-Range`.
    ///
    /// Chunks are sequential and the server is the authority on how far it got.
    /// A chunk that does not start exactly where the server is holding gets a
    /// 409 carrying the true offset — which is what makes resume work after a
    /// crash, a duplicate delivery, or a retry of a request that actually
    /// landed.
    pub fn upload_chunk(&self, token: &str, start: u64, bytes: &[u8]) -> ServerResult {
        let mut st = self.state.lock().unwrap();
        let Some(session) = st.uploads.get_mut(token) else {
            return Err(refuse(404, "NotFound", "Unknown or expired upload token."));
        };
        let have = session.received.len() as u64;
        if start != have {
            return Err(ApiRefusal {
                status: 409,
                errortype: "TransactionError",
                message: format!("Chunk starts at {start}; the server holds {have}."),
                data: json!({ "received_bytes": have, "expected_bytes": session.expected }),
            });
        }
        if have + bytes.len() as u64 > session.expected {
            return Err(refuse(
                400,
                "ValidationError",
                "That chunk runs past the declared size.",
            ));
        }
        session.received.extend_from_slice(bytes);
        Ok(json!({
            "ok": true,
            "received_bytes": session.received.len(),
            "expected_bytes": session.expected,
        }))
    }

    /// `GET /drive_upload/{token}` — where the server thinks it is.
    pub fn upload_status(&self, token: &str) -> ServerResult {
        let st = self.state.lock().unwrap();
        match st.uploads.get(token) {
            Some(s) => Ok(json!({
                "ok": true,
                "received_bytes": s.received.len(),
                "expected_bytes": s.expected,
            })),
            None => Err(refuse(404, "NotFound", "Unknown or expired upload token.")),
        }
    }

    fn drive_upload_complete(&self, body: &Value) -> ServerResult {
        let token = body
            .get("upload_token")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        // Before the server state is locked: the hook touches the disk, not the
        // server, and holding this lock across somebody else's code is how a
        // scenario deadlocks instead of failing.
        if let Some(f) = self.during_upload.lock().unwrap().as_mut() {
            f();
        }
        let mut st = self.state.lock().unwrap();
        let Some(session) = st.uploads.get(&token).cloned() else {
            return Err(refuse(404, "NotFound", "Unknown or expired upload token."));
        };
        if session.received.len() as u64 != session.expected {
            return Err(refuse(
                400,
                "ValidationError",
                "The upload is not complete.",
            ));
        }
        // The hash is checked against the bytes that arrived, not against the
        // bytes that were meant to. A corrupted chunk that reassembles into the
        // wrong file is exactly what this catches, and it is the last place it
        // can be caught before it becomes the user's data.
        let actual = sha256_hex(&session.received);
        if session.sha256.is_empty() {
            // Nothing was declared, so there is nothing to check against. The
            // hash still has to exist — it is how the blob is addressed — so it
            // is taken from the bytes that actually arrived.
            if let Some(s) = st.uploads.get_mut(&token) {
                s.sha256 = actual.clone();
            }
        }
        let session = st.uploads.get(&token).cloned().expect("still present");
        // Trashed while the bytes were in flight — re-asked here, where they
        // actually land, for the same reason the destination folder is.
        if let Some(id) = session.file_id {
            if st.files.get(&id).is_some_and(|f| f.trashed) {
                st.uploads.remove(&token);
                return Err(file_trashed(id));
            }
        }
        // Checked whenever one was declared, encrypted or not, exactly as
        // `drive_upload_complete_logic` checks `fup_expected_sha256`. Skipping
        // it for encrypted uploads left them with no integrity check at all,
        // and a corrupted chunk then became a file no device could ever open.
        if !session.sha256.is_empty() && actual != session.sha256 {
            st.uploads.remove(&token);
            return Err(refuse(
                400,
                "ValidationError",
                "The uploaded bytes do not match the declared hash.",
            ));
        }
        // Quota is enforced here rather than at init, because at init nobody
        // knows whether the bytes will dedup away to nothing.
        if let Some(quota) = st.quota_bytes {
            let used: u64 = st.blobs.values().map(|b| b.len() as u64).sum();
            if used + session.expected > quota {
                st.uploads.remove(&token);
                return Err(refuse(
                    413,
                    "ValidationError",
                    "That would exceed the storage quota.",
                ));
            }
        }

        // Client-custody payloads. Opaque here, exactly as they are to the real
        // server: produced on a device, stored, handed back, never inspected.
        let enc_metadata = body
            .get("encrypted_metadata")
            .and_then(Value::as_str)
            .map(str::to_string);
        let wrapped_keys: BTreeMap<i64, String> = body
            .get("wrapped_file_keys")
            .and_then(Value::as_object)
            .map(|m| {
                m.iter()
                    .filter_map(|(k, v)| Some((k.parse::<i64>().ok()?, v.as_str()?.to_string())))
                    .collect()
            })
            .unwrap_or_default();

        if session.encrypted {
            if session.file_id.is_some() {
                // A new version reuses the file's existing key and content id,
                // so every prior version stays decryptable and every grant stays
                // valid. A fresh key here would strand the new content behind
                // grants that all wrap the old one.
                if !wrapped_keys.is_empty() {
                    st.uploads.remove(&token);
                    return Err(refuse(
                        400,
                        "ValidationError",
                        "A new version of an encrypted file must reuse its existing file key; do not send a new wrapped key.",
                    ));
                }
            } else {
                // Without metadata the file has no name, and without a key for
                // the owner it is a file in their own vault they can never open.
                if enc_metadata.as_deref().unwrap_or("").is_empty() || wrapped_keys.is_empty() {
                    st.uploads.remove(&token);
                    return Err(refuse(
                        400,
                        "ValidationError",
                        "Encrypted upload is missing its metadata or keys.",
                    ));
                }
                if !wrapped_keys.contains_key(&OWNER_USER_ID) {
                    st.uploads.remove(&token);
                    return Err(refuse(
                        400,
                        "ValidationError",
                        "Encrypted upload is missing the folder owner's wrapped key.",
                    ));
                }
            }
        } else if enc_metadata.is_some() || !wrapped_keys.is_empty() {
            st.uploads.remove(&token);
            return Err(refuse(
                400,
                "ValidationError",
                "A plaintext upload cannot carry encryption payloads.",
            ));
        }

        st.blobs
            .insert(session.sha256.clone(), session.received.clone());
        // The caller gets back their own grant, which is the one the export
        // carries. Everyone else's is stored against the file in the real
        // schema; this mock keeps only the owner's, since it has one user.
        let mine = wrapped_keys.get(&OWNER_USER_ID).cloned();
        let file = Self::commit_content(
            &mut st,
            session.file_id,
            session.folder,
            &session.name,
            &session.sha256,
            session.expected,
            session.encrypted,
            enc_metadata,
            mine,
        )?;
        st.uploads.remove(&session.token);
        Ok(json!({ "ok": true, "file": file }))
    }

    /// Drop upload sessions older than `age_ms` — the sweep that reclaims
    /// abandoned partial uploads.
    pub fn sweep_uploads(&self, age_ms: u64) {
        let now = self.clock.now_ms();
        let mut st = self.state.lock().unwrap();
        st.uploads
            .retain(|_, s| now.saturating_sub(s.created_ms) < age_ms);
    }

    // ---- download ----------------------------------------------------------

    /// Serve a signed URL. `from` is the resume offset.
    ///
    /// Returns the bytes, or a refusal. An expired URL is a 403 the engine has
    /// to answer by re-minting rather than by giving up — the file is fine, the
    /// link is not.
    pub fn download(&self, url: &str, from: u64) -> Result<Vec<u8>, ApiRefusal> {
        let st = self.state.lock().unwrap();
        let Some(signed) = st.signed.get(url) else {
            return Err(refuse(
                403,
                "SecurityError",
                "That link is no longer valid.",
            ));
        };
        if self.clock.now_ms().saturating_sub(signed.issued_ms) > SIGNED_URL_TTL_MS {
            return Err(refuse(403, "SecurityError", "That link has expired."));
        }
        let Some(bytes) = st.blobs.get(&signed.sha256) else {
            return Err(refuse(404, "NotFound", "That content is gone."));
        };
        if from > bytes.len() as u64 {
            return Err(refuse(
                416,
                "ValidationError",
                "That range starts past the end of the file.",
            ));
        }
        Ok(bytes[from as usize..].to_vec())
    }

    // ---- internals ---------------------------------------------------------

    fn target(body: &Value) -> Result<(&'static str, i64), ApiRefusal> {
        let t = body
            .get("entity_type")
            .and_then(Value::as_str)
            .unwrap_or("");
        let id = body.get("entity_id").and_then(Value::as_i64).unwrap_or(0);
        match (t, id) {
            ("folder", id) if id > 0 => Ok(("folder", id)),
            ("file", id) if id > 0 => Ok(("file", id)),
            _ => Err(refuse(
                400,
                "ValidationError",
                "entity_type and entity_id are required.",
            )),
        }
    }

    fn echo(st: &mut ServerState, t: &str, id: i64) -> ServerResult {
        if t == "folder" {
            let row = st.folders.get(&id).cloned();
            Ok(json!({ "ok": true, "folder": row.map(|f| Self::folder_export(&f)) }))
        } else {
            let row = st.files.get(&id).cloned();
            match row {
                Some(f) => {
                    let v = Self::file_export(st, &f, false, 0);
                    Ok(json!({ "ok": true, "file": v }))
                }
                None => Ok(json!({ "ok": true, "file": Value::Null })),
            }
        }
    }

    fn record(st: &mut ServerState, entity_type: &'static str, id: i64, kind: &'static str) -> i64 {
        st.next_change_id += 1;
        let change_id = st.next_change_id;
        let actor = st.actor.clone();
        st.changes.push(ChangeRow {
            change_id,
            entity_type,
            entity_id: id,
            kind,
            actor,
        });
        change_id
    }

    /// Land content on a file — new file, or a new version of an existing one.
    #[allow(clippy::too_many_arguments)]
    fn commit_content(
        st: &mut ServerState,
        file_id: Option<i64>,
        folder: Option<i64>,
        name: &str,
        sha: &str,
        size: u64,
        encrypted: bool,
        enc_metadata: Option<String>,
        wrapped_key: Option<String>,
    ) -> ServerResult {
        let id = match file_id {
            Some(id) => {
                if !st.files.contains_key(&id) {
                    return Err(refuse(404, "NotFound", "That file does not exist."));
                }
                id
            }
            None => {
                // A live file of that name in that folder already exists. The
                // real server refuses this (`DriveHelper::file_name_taken`), and
                // for most of this rig's life neither did — so two devices that
                // conflicted on one file both uploaded the same conflicted-copy
                // name, the server took both, and every device could then
                // materialize only one of them. A soak campaign ended with 55
                // duplicate names, 91 files no device could place, and a fleet
                // that never converged again. Trashed siblings do not count,
                // exactly as there.
                if st
                    .files
                    .values()
                    .any(|f| !f.trashed && f.folder == folder && f.name == name)
                {
                    return Err(name_taken("A file with that name already exists here."));
                }
                st.next_file_id += 1;
                st.next_file_id
            }
        };
        let kind = if file_id.is_some() {
            "content"
        } else {
            "created"
        };
        let change_id = Self::record(st, "file", id, kind);
        match st.files.get_mut(&id) {
            Some(f) => {
                f.sha256 = sha.to_string();
                f.size = size;
                f.head_change_id = change_id;
                // A new version's metadata follows its content — the size
                // inside the blob changed with the bytes. The file key, the
                // content id and every existing grant do not.
                if f.encrypted {
                    if let Some(blob) = enc_metadata {
                        f.encrypted_metadata = Some(blob);
                    }
                }
            }
            None => {
                st.files.insert(
                    id,
                    FileRow {
                        id,
                        folder,
                        name: name.to_string(),
                        sha256: sha.to_string(),
                        size,
                        trashed: false,
                        head_change_id: change_id,
                        modified_time: None,
                        encrypted,
                        wrapped_file_key: wrapped_key,
                        encrypted_metadata: enc_metadata,
                    },
                );
            }
        }
        // Never pruned. This is the record that lets the harness assert nothing
        // committed was ever lost, including things the live tree has moved on
        // from.
        st.versions.push(VersionRow {
            file_id: id,
            sha256: sha.to_string(),
            size,
            change_id,
        });
        let row = st.files.get(&id).cloned().expect("just inserted");
        Ok(Self::file_export(st, &row, false, 0))
    }

    fn folder_export(f: &FolderRow) -> Value {
        json!({
            "entity_type": "folder",
            "id": f.id,
            "name": f.name,
            "parent_id": f.parent,
            "deleted": f.trashed,
            "encrypted": f.encrypted,
        })
    }

    /// `now_ms` stamps any URL this mints, so the link ages with the simulated
    /// clock rather than with wall time.
    fn file_export(st: &mut ServerState, f: &FileRow, with_urls: bool, now_ms: u64) -> Value {
        let mut out = Map::new();
        out.insert("entity_type".into(), json!("file"));
        out.insert("id".into(), json!(f.id));
        out.insert("name".into(), json!(f.name));
        out.insert("size".into(), json!(f.size));
        out.insert("folder_id".into(), json!(f.folder));
        out.insert("deleted".into(), json!(f.trashed));
        out.insert("encrypted".into(), json!(f.encrypted));
        if f.encrypted {
            // Only on encrypted files, and null until a grant exists — the real
            // export omits all of these for plaintext, so a client that read a
            // grant off an ordinary file would be reading something the server
            // never sends.
            out.insert("wrapped_file_key".into(), json!(f.wrapped_file_key));
            out.insert("encrypted_metadata".into(), json!(f.encrypted_metadata));
        }
        out.insert("content_sha256".into(), json!(f.sha256));
        out.insert("modified_time".into(), json!(f.modified_time));
        out.insert("head_change_id".into(), json!(f.head_change_id));
        let url = if with_urls {
            st.next_url += 1;
            let u = format!("/sim/download/{}", st.next_url);
            st.signed.insert(
                u.clone(),
                SignedUrl {
                    sha256: f.sha256.clone(),
                    issued_ms: now_ms,
                },
            );
            Value::String(u)
        } else {
            Value::Null
        };
        out.insert("download_url".into(), url);
        Value::Object(out)
    }

    fn folder_path(st: &ServerState, id: i64) -> Option<String> {
        let mut parts = Vec::new();
        let mut cur = Some(id);
        let mut guard = 0;
        while let Some(c) = cur {
            guard += 1;
            if guard > 1000 {
                return None;
            }
            let f = st.folders.get(&c)?;
            if f.trashed {
                return None;
            }
            parts.push(f.name.clone());
            cur = f.parent;
        }
        parts.reverse();
        Some(parts.join("/"))
    }

    fn is_descendant(st: &ServerState, maybe_child: Option<i64>, ancestor: i64) -> bool {
        let mut cur = maybe_child;
        let mut guard = 0;
        while let Some(c) = cur {
            guard += 1;
            if guard > 1000 {
                return true;
            }
            if c == ancestor {
                return true;
            }
            cur = st.folders.get(&c).and_then(|f| f.parent);
        }
        false
    }

    /// Every folder and file beneath `root`, deepest last.
    fn subtree(st: &ServerState, root: i64) -> (Vec<i64>, Vec<i64>) {
        let mut folders = Vec::new();
        let mut queue = vec![root];
        while let Some(cur) = queue.pop() {
            for f in st.folders.values() {
                if f.parent == Some(cur) && !folders.contains(&f.id) {
                    folders.push(f.id);
                    queue.push(f.id);
                }
            }
        }
        let mut in_scope = folders.clone();
        in_scope.push(root);
        let files = st
            .files
            .values()
            .filter(|f| f.folder.map(|p| in_scope.contains(&p)).unwrap_or(false))
            .map(|f| f.id)
            .collect();
        (folders, files)
    }
}

/// `folder:123` / `file:456` → the half of the walk and how far into it.
fn parse_index_token(token: &str) -> (&'static str, i64) {
    match token.split_once(':') {
        Some(("file", n)) => ("file", n.parse().unwrap_or(0)),
        Some(("folder", n)) => ("folder", n.parse().unwrap_or(0)),
        _ => ("folder", 0),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn server() -> MockServer {
        MockServer::new(SimClock::new())
    }

    fn upload(s: &MockServer, name: &str, folder: Option<i64>, bytes: &[u8]) -> Value {
        let sha = sha256_hex(bytes);
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": name, "folder_id": folder, "size_bytes": bytes.len(), "sha256": sha }),
            )
            .unwrap();
        if init.get("deduped").and_then(Value::as_bool) == Some(true) {
            return init.get("file").cloned().unwrap();
        }
        let token = init["upload_token"].as_str().unwrap().to_string();
        let mut offset = 0usize;
        while offset < bytes.len() {
            let end = (offset + CHUNK_BYTES as usize).min(bytes.len());
            s.upload_chunk(&token, offset as u64, &bytes[offset..end])
                .unwrap();
            offset = end;
        }
        let done = s
            .action("drive_upload_complete", &json!({ "upload_token": token }))
            .unwrap();
        done.get("file").cloned().unwrap()
    }

    #[test]
    fn a_cold_client_is_handed_the_whole_feed() {
        let s = server();
        s.seed_file(None, "a.txt", b"one");
        s.seed_file(None, "b.txt", b"two");
        let out = s.action("drive_changes", &json!({ "cursor": 0 })).unwrap();
        assert_eq!(out["changes"].as_array().unwrap().len(), 2);
        assert_eq!(out["next_cursor"], json!(2));
    }

    #[test]
    fn a_cursor_only_sees_what_happened_after_it() {
        let s = server();
        s.seed_file(None, "a.txt", b"one");
        let at = s.latest_change_id();
        s.seed_file(None, "b.txt", b"two");
        let out = s.action("drive_changes", &json!({ "cursor": at })).unwrap();
        let rows = out["changes"].as_array().unwrap();
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0]["kind"], json!("created"));
    }

    #[test]
    fn a_cursor_older_than_the_retained_window_is_told_to_start_over() {
        // The alternative is handing back a feed with a hole in it, and a hole
        // in a change feed is a file that silently never syncs again.
        let s = server();
        s.seed_file(None, "a.txt", b"one");
        s.seed_file(None, "b.txt", b"two");
        s.prune_feed_before(2);
        let out = s.action("drive_changes", &json!({ "cursor": 1 })).unwrap();
        assert_eq!(out["reset"], json!(true));
        assert_eq!(out["changes"].as_array().unwrap().len(), 0);
        assert_eq!(out["next_cursor"], json!(s.latest_change_id()));
    }

    #[test]
    fn a_still_valid_cursor_survives_a_prune() {
        let s = server();
        s.seed_file(None, "a.txt", b"one");
        s.seed_file(None, "b.txt", b"two");
        s.prune_feed_before(2);
        let out = s.action("drive_changes", &json!({ "cursor": 2 })).unwrap();
        assert!(out.get("reset").is_none());
    }

    #[test]
    fn stat_answers_by_id_and_names_what_is_gone() {
        let s = server();
        let id = s.seed_file(None, "a.txt", b"one");
        let out = s
            .action(
                "drive_stat",
                &json!({ "entities": [
                    { "entity_type": "file", "entity_id": id },
                    { "entity_type": "file", "entity_id": 99999 }
                ] }),
            )
            .unwrap();
        assert_eq!(out["items"].as_array().unwrap().len(), 1);
        assert_eq!(out["items"][0]["content_sha256"], json!(sha256_hex(b"one")));
        // Reported, not errored: "deleted" and "the request failed" are
        // opposite instructions to a client.
        assert_eq!(out["missing"].as_array().unwrap().len(), 1);
    }

    #[test]
    fn stat_answers_a_repeated_id_once() {
        let s = server();
        let id = s.seed_file(None, "a.txt", b"one");
        let out = s
            .action(
                "drive_stat",
                &json!({ "entities": [
                    { "entity_type": "file", "entity_id": id },
                    { "entity_type": "file", "entity_id": id }
                ] }),
            )
            .unwrap();
        assert_eq!(out["items"].as_array().unwrap().len(), 1);
    }

    #[test]
    fn stat_withholds_signed_urls_unless_asked() {
        let s = server();
        let id = s.seed_file(None, "a.txt", b"one");
        let plain = s
            .action(
                "drive_stat",
                &json!({ "entities": [{ "entity_type": "file", "entity_id": id }] }),
            )
            .unwrap();
        assert!(plain["items"][0]["download_url"].is_null());
        let with = s
            .action(
                "drive_stat",
                &json!({ "entities": [{ "entity_type": "file", "entity_id": id }], "urls": true }),
            )
            .unwrap();
        assert!(with["items"][0]["download_url"].is_string());
    }

    #[test]
    fn the_index_walks_folders_then_files_and_says_when_it_is_done() {
        let s = server();
        let f = s.seed_folder(None, "Docs");
        s.seed_file(Some(f), "a.txt", b"one");
        let mut token = String::new();
        let mut seen = Vec::new();
        for _ in 0..10 {
            let page = s
                .action("drive_index", &json!({ "after_id": token, "limit": 1 }))
                .unwrap();
            for item in page["items"].as_array().unwrap() {
                seen.push(item["entity_type"].as_str().unwrap().to_string());
            }
            token = page["next_after_id"].as_str().unwrap().to_string();
            if page["done"].as_bool() == Some(true) {
                break;
            }
        }
        assert_eq!(seen, vec!["folder", "file"]);
    }

    #[test]
    fn an_upload_arrives_in_chunks_and_lands_as_one_file() {
        let s = server();
        let file = upload(&s, "notes.txt", None, b"a longer body than one chunk");
        assert_eq!(
            file["content_sha256"],
            json!(sha256_hex(b"a longer body than one chunk"))
        );
        assert_eq!(
            s.blob(&sha256_hex(b"a longer body than one chunk"))
                .unwrap(),
            b"a longer body than one chunk"
        );
    }

    #[test]
    fn a_chunk_at_the_wrong_offset_is_told_where_the_server_actually_is() {
        // The 409 is what makes resume possible. Without the true offset in the
        // body the client can only start over, which for a large file over a
        // bad connection means never finishing.
        let s = server();
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": "a.bin", "size_bytes": 9, "sha256": sha256_hex(b"123456789") }),
            )
            .unwrap();
        let token = init["upload_token"].as_str().unwrap();
        s.upload_chunk(token, 0, b"123").unwrap();
        let err = s.upload_chunk(token, 6, b"789").unwrap_err();
        assert_eq!(err.status, 409);
        assert_eq!(err.data["received_bytes"], json!(3));
    }

    #[test]
    fn a_duplicate_chunk_is_refused_rather_than_appended() {
        // A retry of a request that actually landed must not write the bytes
        // twice; the file would be corrupt and the hash check would be the only
        // thing standing between that and the user's data.
        let s = server();
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": "a.bin", "size_bytes": 6, "sha256": sha256_hex(b"abcdef") }),
            )
            .unwrap();
        let token = init["upload_token"].as_str().unwrap();
        s.upload_chunk(token, 0, b"abc").unwrap();
        let again = s.upload_chunk(token, 0, b"abc").unwrap_err();
        assert_eq!(again.status, 409);
        assert_eq!(again.data["received_bytes"], json!(3));
    }

    #[test]
    fn content_the_server_already_holds_is_not_sent_again() {
        let s = server();
        upload(&s, "first.txt", None, b"shared body");
        let second = upload(&s, "second.txt", None, b"shared body");
        assert_eq!(second["content_sha256"], json!(sha256_hex(b"shared body")));
        // Two files, one blob.
        assert_eq!(s.tree().len(), 2);
    }

    #[test]
    fn bytes_that_do_not_match_the_declared_hash_are_refused() {
        // The last place corruption can be caught before it becomes the user's
        // data.
        let s = server();
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": "a.bin", "size_bytes": 3, "sha256": sha256_hex(b"abc") }),
            )
            .unwrap();
        let token = init["upload_token"].as_str().unwrap().to_string();
        s.upload_chunk(&token, 0, b"xyz").unwrap();
        let err = s
            .action("drive_upload_complete", &json!({ "upload_token": token }))
            .unwrap_err();
        assert_eq!(err.status, 400);
    }

    #[test]
    fn an_incomplete_upload_cannot_be_completed() {
        let s = server();
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": "a.bin", "size_bytes": 6, "sha256": sha256_hex(b"abcdef") }),
            )
            .unwrap();
        let token = init["upload_token"].as_str().unwrap().to_string();
        s.upload_chunk(&token, 0, b"abc").unwrap();
        assert!(s
            .action("drive_upload_complete", &json!({ "upload_token": token }))
            .is_err());
    }

    #[test]
    fn quota_is_enforced_at_completion_not_at_the_start() {
        // At init nobody knows whether the bytes will dedup away to nothing, so
        // refusing early would reject uploads that were never going to cost
        // anything.
        let s = server();
        s.set_quota_bytes(Some(4));
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": "big.bin", "size_bytes": 9, "sha256": sha256_hex(b"123456789") }),
            )
            .unwrap();
        assert!(init["upload_token"].is_string(), "init is allowed through");
        let token = init["upload_token"].as_str().unwrap().to_string();
        s.upload_chunk(&token, 0, b"123").unwrap();
        s.upload_chunk(&token, 3, b"456").unwrap();
        s.upload_chunk(&token, 6, b"789").unwrap();
        let err = s
            .action("drive_upload_complete", &json!({ "upload_token": token }))
            .unwrap_err();
        assert_eq!(err.status, 413);
    }

    #[test]
    fn the_same_idempotency_key_performs_the_work_once() {
        // The crash story rests entirely on this: the engine may die between
        // "the server committed it" and "the client wrote that down", and the
        // retry after restart must not create a second folder.
        let s = server();
        let key = "k-1";
        let a = s
            .action_idempotent("drive_folder_create", &json!({ "name": "Docs" }), key)
            .unwrap();
        let b = s
            .action_idempotent("drive_folder_create", &json!({ "name": "Docs" }), key)
            .unwrap();
        assert_eq!(a, b);
        assert_eq!(s.live_counts(), (1, 0));
    }

    #[test]
    fn a_different_key_does_the_work_again() {
        let s = server();
        s.action_idempotent("drive_folder_create", &json!({ "name": "Docs" }), "k-1")
            .unwrap();
        // A distinct name: a duplicate is refused on its own merits, which would
        // mask whether the key was honoured. The question here is only whether a
        // second key does the work a second time.
        s.action_idempotent("drive_folder_create", &json!({ "name": "Notes" }), "k-2")
            .unwrap();
        assert_eq!(s.live_counts(), (2, 0));
    }

    #[test]
    fn every_committed_version_is_still_there_after_the_file_moves_on() {
        // The oracle. Without this the "no committed content is ever lost"
        // invariant would have nothing to check itself against.
        let s = server();
        let file = upload(&s, "doc.txt", None, b"first draft");
        let id = file["id"].as_i64().unwrap();
        let sha = sha256_hex(b"second draft");
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": "doc.txt", "file_id": id, "size_bytes": 12, "sha256": sha }),
            )
            .unwrap();
        let token = init["upload_token"].as_str().unwrap().to_string();
        s.upload_chunk(&token, 0, b"sec").unwrap();
        s.upload_chunk(&token, 3, b"ond").unwrap();
        s.upload_chunk(&token, 6, b" dra").unwrap();
        s.upload_chunk(&token, 10, b"ft").unwrap();
        s.action("drive_upload_complete", &json!({ "upload_token": token }))
            .unwrap();

        let versions = s.all_versions();
        assert_eq!(versions.len(), 2);
        assert!(versions
            .iter()
            .any(|v| v.sha256 == sha256_hex(b"first draft")));
        assert!(s.blob(&sha256_hex(b"first draft")).is_some());
    }

    #[test]
    fn trashing_a_folder_reports_everything_inside_it_too() {
        // A client that only heard about the folder would keep every file
        // inside it materialized forever.
        let s = server();
        let f = s.seed_folder(None, "Docs");
        let sub = s.seed_folder(Some(f), "Sub");
        s.seed_file(Some(f), "a.txt", b"one");
        s.seed_file(Some(sub), "b.txt", b"two");
        let at = s.latest_change_id();
        s.action(
            "drive_trash",
            &json!({ "entity_type": "folder", "entity_id": f }),
        )
        .unwrap();
        let out = s.action("drive_changes", &json!({ "cursor": at })).unwrap();
        let kinds: Vec<&str> = out["changes"]
            .as_array()
            .unwrap()
            .iter()
            .map(|c| c["kind"].as_str().unwrap())
            .collect();
        assert_eq!(kinds.len(), 4, "the folder, its subfolder, and both files");
        assert!(kinds.iter().all(|k| *k == "trashed"));
        assert!(s.tree().is_empty());
    }

    #[test]
    fn a_folder_cannot_be_moved_inside_itself() {
        // It would detach the whole subtree: no listing walks into it, so it is
        // gone without ever being deleted.
        let s = server();
        let a = s.seed_folder(None, "A");
        let b = s.seed_folder(Some(a), "B");
        let err = s
            .action(
                "drive_move",
                &json!({ "entity_type": "folder", "entity_id": a, "folder_id": b }),
            )
            .unwrap_err();
        assert_eq!(err.status, 400);
    }

    #[test]
    fn the_tree_reads_as_paths_for_the_convergence_check() {
        let s = server();
        let f = s.seed_folder(None, "Docs");
        s.seed_file(Some(f), "a.txt", b"one");
        s.seed_file(None, "root.txt", b"two");
        let tree = s.tree();
        assert_eq!(tree.get("Docs"), Some(&None));
        assert_eq!(tree.get("Docs/a.txt"), Some(&Some(sha256_hex(b"one"))));
        assert_eq!(tree.get("root.txt"), Some(&Some(sha256_hex(b"two"))));
    }

    #[test]
    fn a_download_can_resume_from_an_offset() {
        let s = server();
        let id = s.seed_file(None, "a.txt", b"0123456789");
        let stat = s
            .action(
                "drive_stat",
                &json!({ "entities": [{ "entity_type": "file", "entity_id": id }], "urls": true }),
            )
            .unwrap();
        let url = stat["items"][0]["download_url"].as_str().unwrap();
        assert_eq!(s.download(url, 0).unwrap(), b"0123456789");
        assert_eq!(s.download(url, 4).unwrap(), b"456789");
    }

    #[test]
    fn an_expired_link_is_refused_while_the_file_stays_fine() {
        // The engine's correct response is to mint a new link, not to conclude
        // anything about the file.
        let s = server();
        let id = s.seed_file(None, "a.txt", b"body");
        let stat = s
            .action(
                "drive_stat",
                &json!({ "entities": [{ "entity_type": "file", "entity_id": id }], "urls": true }),
            )
            .unwrap();
        let url = stat["items"][0]["download_url"]
            .as_str()
            .unwrap()
            .to_string();
        s.expire_signed_urls();
        let err = s.download(&url, 0).unwrap_err();
        assert_eq!(err.status, 403);
        assert!(s.tree().contains_key("a.txt"));
    }

    #[test]
    fn abandoned_uploads_are_swept() {
        let clock = SimClock::new();
        let s = MockServer::new(clock.clone());
        let init = s
            .action(
                "drive_upload_init",
                &json!({ "name": "a.bin", "size_bytes": 3, "sha256": sha256_hex(b"abc") }),
            )
            .unwrap();
        let token = init["upload_token"].as_str().unwrap().to_string();
        clock.advance_secs(60 * 60 * 25);
        s.sweep_uploads(24 * 60 * 60 * 1000);
        assert!(s.upload_status(&token).is_err());
    }

    #[test]
    fn changes_carry_the_device_that_caused_them() {
        // A device that cannot recognize its own echo either loops forever or
        // guesses, and guessing wrong means missing somebody else's edit.
        let s = server();
        s.acting_as(Some("laptop"));
        s.action("drive_folder_create", &json!({ "name": "Docs" }))
            .unwrap();
        let out = s.action("drive_changes", &json!({ "cursor": 0 })).unwrap();
        assert_eq!(out["changes"][0]["actor"], json!("laptop"));
    }
}
