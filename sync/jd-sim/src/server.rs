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
    /// Idempotency key → the response first produced for it.
    idempotency: BTreeMap<String, Value>,
    quota_bytes: Option<u64>,
    next_token: u64,
    /// Who is calling. Set by the network layer per device.
    actor: Option<String>,
}

/// The server. Cloning shares it — two simulated devices hold the same one.
#[derive(Debug, Clone)]
pub struct MockServer {
    state: Arc<Mutex<ServerState>>,
    clock: SimClock,
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
                quota_bytes: None,
                next_token: 0,
                actor: None,
            })),
            clock,
        }
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
        st.folders.insert(
            id,
            FolderRow {
                id,
                parent,
                name: name.to_string(),
                trashed: false,
                encrypted: false,
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
    pub fn action_idempotent(&self, name: &str, body: &Value, key: &str) -> ServerResult {
        if let Some(prior) = self.state.lock().unwrap().idempotency.get(key) {
            return Ok(prior.clone());
        }
        let out = self.action(name, body)?;
        self.state
            .lock()
            .unwrap()
            .idempotency
            .insert(key.to_string(), out.clone());
        Ok(out)
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
            if !st.folders.contains_key(&p) {
                return Err(refuse(404, "NotFound", "That folder does not exist."));
            }
        }
        st.next_folder_id += 1;
        let id = st.next_folder_id;
        Self::record(&mut st, "folder", id, "created");
        let row = FolderRow {
            id,
            parent,
            name,
            trashed: false,
            // A folder the CLIENT creates is plaintext. Making an encrypted
            // folder is a browser ceremony this surface does not offer.
            encrypted: false,
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
        if name.is_empty() {
            return Err(refuse(400, "ValidationError", "A name is required."));
        }
        let mut st = self.state.lock().unwrap();
        if t == "folder" {
            match st.folders.get_mut(&id) {
                Some(f) => f.name = name,
                None => return Err(refuse(404, "NotFound", "That folder does not exist.")),
            }
            Self::record(&mut st, "folder", id, "renamed");
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
        let dest = body.get("folder_id").and_then(Value::as_i64);
        let mut st = self.state.lock().unwrap();
        if let Some(d) = dest {
            if !st.folders.contains_key(&d) {
                return Err(refuse(404, "NotFound", "That folder does not exist."));
            }
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
        if name.is_empty() || sha.is_empty() || size == u64::MAX {
            return Err(refuse(
                400,
                "ValidationError",
                "name, size_bytes and sha256 are required.",
            ));
        }
        let folder = body.get("folder_id").and_then(Value::as_i64);
        let file_id = body.get("file_id").and_then(Value::as_i64);

        let mut st = self.state.lock().unwrap();
        if let Some(f) = folder {
            if !st.folders.contains_key(&f) {
                return Err(refuse(404, "NotFound", "That folder does not exist."));
            }
        }

        // Dedup: the server already holds these exact bytes, so there is
        // nothing to send. The engine has to handle a completed upload that
        // moved no bytes at all, which is a different code path from the one
        // it usually takes and therefore one worth exercising constantly.
        if st.blobs.contains_key(&sha) {
            let file = Self::commit_content(&mut st, file_id, folder, &name, &sha, size)?;
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
        if actual != session.sha256 {
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

        st.blobs
            .insert(session.sha256.clone(), session.received.clone());
        let file = Self::commit_content(
            &mut st,
            session.file_id,
            session.folder,
            &session.name,
            &session.sha256,
            session.expected,
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
    fn commit_content(
        st: &mut ServerState,
        file_id: Option<i64>,
        folder: Option<i64>,
        name: &str,
        sha: &str,
        size: u64,
    ) -> ServerResult {
        let id = match file_id {
            Some(id) => {
                if !st.files.contains_key(&id) {
                    return Err(refuse(404, "NotFound", "That file does not exist."));
                }
                id
            }
            None => {
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
                f.trashed = false;
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
                        // Uploaded through the plaintext path. The encrypted
                        // upload path carries its own key payload and does not
                        // land here.
                        encrypted: false,
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
        s.action_idempotent("drive_folder_create", &json!({ "name": "Docs" }), "k-2")
            .unwrap();
        // Two entities, one path — counted as entities, because that is what
        // "was the work done twice?" is actually asking.
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
