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
    /// Private: the server holds the key wrapping and opens the bytes only
    /// inside the owner's unlock window, which a sync client never holds.
    /// Set on a top-level folder and its whole subtree at once
    /// ([`MockServer::set_private`]), and inherited by a folder made inside.
    private: bool,
}

/// One folder as the server knows it, for oracles that ask by id.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FolderFact {
    pub id: i64,
    pub parent: Option<i64>,
    pub name: String,
    pub encrypted: bool,
    pub trashed: bool,
}

/// One file as the server holds it, for an oracle that asks about custody:
/// which folder id it stands in and which bytes it carries.
#[derive(Debug, Clone)]
pub struct FileFact {
    pub id: i64,
    pub folder: Option<i64>,
    pub name: String,
    /// The hash of what is stored: the plaintext for an ordinary file, the
    /// ciphertext for an encrypted one.
    pub sha256: String,
    pub encrypted: bool,
    pub trashed: bool,
    /// The content's modification time as the server stores it
    /// (`fil_content_modified_time`): whole seconds, UTC, no zone. Never set
    /// on an encrypted file.
    pub modified_time: Option<String>,
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
    /// What `fil_content_modified_time` holds: the time the uploading client
    /// declared, normalized as `drive_upload_init` normalizes it.
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
    /// Sealed by the server (Private): its bytes open only inside the owner's
    /// window, so a sync client is given no link to them, and replacing them
    /// or moving the file out of its Private folder needs the window.
    sealed: bool,
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

/// One trash that took something live: what it named, whose call it was, and
/// everything that went to the trash with it -- the entity named, if it was
/// live, and each live descendant its folder's cascade took.
#[derive(Debug, Clone)]
pub struct TrashCall {
    pub entity_type: &'static str,
    pub id: i64,
    /// The device whose call it was; `None` for the harness's own hand, a
    /// person trashing it on the web.
    pub caller: Option<String>,
    /// Where the order of the user's acts stood when it ran
    /// (`vfs::user_acts_so_far`): every act of a user's hand with an order
    /// below it came first.
    pub order: u64,
    pub change_id: i64,
    /// Every entity taken, as (entity type, id), the one named first.
    pub took: Vec<(&'static str, i64)>,
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
    /// Settled at init, as `fup_content_modified_time` is: an upload cannot
    /// acquire a time it was not opened with.
    modified_time: Option<String>,
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
    /// Every outcome a key produced, refusals included: the platform stores
    /// the response whatever its status and replays it for the same key
    /// (`ApiLogicEndpoint::idempotencyResolveExisting`). Kept successes only,
    /// the mock let a client re-ask a refused request under its old key and
    /// get a fresh answer the real server never gives. Never a 5xx: the
    /// platform stores what the action answered, a success or a refusal, and a
    /// server failure never reaches that store. The 5xx modelled here is one
    /// that never reached the claim (a gateway, an overload). A PHP error
    /// after the claim leaves the row with no status, and the platform
    /// answers the same key with a 409 'still in progress' for five minutes
    /// before a retry takes it over; that case is not modelled.
    idempotency: BTreeMap<String, (String, Value, ServerResult)>,
    /// How many times a key was offered for a second, different request. A
    /// client that cannot reproduce its own request byte for byte is refused
    /// here every time it asks, so any count above zero is a client that will
    /// go on asking forever.
    key_conflicts: usize,
    quota_bytes: Option<u64>,
    next_token: u64,
    /// Folders and files another member owns and has shared with this account,
    /// each with everything inside it (single-owner trees: what is made inside
    /// one belongs to its owner too). The account syncs its own Drive only, so
    /// a client asking for `scope: mine` is never told of these.
    shared_in: std::collections::BTreeSet<(&'static str, i64)>,
    /// What is shared in is shared as a viewer's: nothing may be written into
    /// it, nor any of it changed (`MockServer::shared_in_read_only`).
    shared_in_read_only: bool,
    /// Every upload a client began, refused or not: what a refusal costs.
    upload_inits: usize,
    /// Every trash a client asked for, refused or not.
    trash_requests: usize,
    list_requests: usize,
    /// The next call to each named action is answered with this status, once.
    answer_next: Vec<(String, u16, Option<&'static str>)>,
    /// Who is calling. Set by the network layer per device.
    actor: Option<String>,
    /// The device whose call is being answered right now, and `None`
    /// between calls: unlike `actor`, which keeps the last device's name
    /// after its call returns, this tells the harness's own hand apart.
    caller: Option<String>,
    /// Every trash that took something live, kept whole: the change feed is
    /// pruned, this is not.
    trash_calls: Vec<TrashCall>,
    /// Drive vault public keys by user id — what an encrypted upload seals its
    /// file key to. A user absent from here has no vault, which is a state the
    /// upload path has to handle rather than a state that cannot happen.
    vault_public_keys: BTreeMap<i64, String>,
    /// The owner's vault key generation, as `vault_client_probe` answers it:
    /// 1 once a vault is set up, one more at every rotation.
    vault_key_generation: i64,
    /// The devices the owner's vault secret was handed to, by name: the
    /// scopes a device row lists (`sde_vault_scopes`). A rotation takes the
    /// scope off every one of them (`VaultClientCustody::forgetScopeOnDevices`).
    vault_holders: std::collections::BTreeSet<String>,
    index_requests: usize,
    probe_requests: usize,
}

/// Code a scenario runs inside one of the server's calls.
type ServerHook = Arc<Mutex<Option<Box<dyn FnMut() + Send>>>>;
/// Shown each move or rename as it arrives, until it answers true.
type MoveHook = Arc<Mutex<Option<Box<dyn FnMut(&str, &Value) -> bool + Send>>>>;

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
    during_upload: ServerHook,
    /// Run when a download asks for its link: the operation has started and
    /// no byte has moved. See [`MockServer::while_minting_a_download`].
    minting_a_download: ServerHook,
    /// Run when a move or rename arrives, before the server reads it. See
    /// [`MockServer::when_a_move_arrives`].
    move_arrives: MoveHook,
    /// Refuse in English only: every refusal leaves without the machine-readable
    /// marker that says WHY.
    ///
    /// Every refusal here carries one, and every refusal the current platform
    /// sends carries one — which quietly made "the client can always tell what
    /// kind of refusal this is" an assumption no scenario could break. It is not
    /// true of a server a version behind, of one endpoint that was missed, or of
    /// any refusal invented after this client shipped. The soak rig found the
    /// consequence on a box whose folder rename refused without the marker.
    plain_refusals: Arc<Mutex<bool>>,
    /// Answer as a server a version behind: `unchanged_since` is not read, and
    /// a trash takes what is under it whatever changed. What a client meets
    /// before its server has the check, and the only way to see what the
    /// client does on its own (`specs/drive_trash_unchanged_since.md`).
    ignores_unchanged_since: Arc<Mutex<bool>>,
    /// See [`MockServer::ignores_scope`].
    ignores_scope: Arc<Mutex<bool>>,
    /// See [`MockServer::index_shows_nothing`].
    index_shows_nothing: Arc<Mutex<bool>>,
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

/// A refusal as the platform answers every one from the actions a sync
/// client calls: 422, with its reason in the data (`docs/drive.md`, Refusal
/// reasons). Never the 404 or 400 an HTTP mock reaches for: the platform
/// sends neither for these, and a client met in the field by a 422 where the
/// mock had sent it a 404 was tested against a kinder server than it ran on.
fn refused(reason: &'static str, message: impl Into<String>) -> ApiRefusal {
    // A malformed request is refused again as sent: it stands, as the
    // platform's does (`docs/drive.md`, Standing refusals).
    let data = if reason == "invalid_request" {
        json!({ "reason": reason, "standing": true })
    } else {
        json!({ "reason": reason })
    };
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: message.into(),
        data,
    }
}

/// `drive_upload_init`'s reading of a declared modification time
/// (`_drive_parse_modified_time`): nothing sent is `None`; a time it cannot
/// use -- not a time, before 1970, or more than fifty years past the server's
/// clock -- is `Err`, refused rather than dropped; anything else is stored to
/// the second, in UTC, with no zone, which is the spelling `file_export` then
/// hands back.
fn modified_time_of(raw: Option<&Value>, now_ms: u64) -> Result<Option<String>, ()> {
    let text = match raw {
        None | Some(Value::Null) | Some(Value::Bool(false)) => return Ok(None),
        Some(Value::String(t)) if t.is_empty() => return Ok(None),
        Some(Value::String(t)) => t.clone(),
        Some(_) => return Err(()),
    };
    let at = jd_proto::mtime::parse(&text).ok_or(())?;
    let fifty_years_ns = 50 * 365 * 24 * 3600 * 1_000_000_000u64;
    if at > now_ms.saturating_mul(1_000_000).saturating_add(fifty_years_ns) {
        return Err(());
    }
    // Whole seconds, as `strtotime` reads it.
    let iso = jd_proto::mtime::format(at / 1_000_000_000 * 1_000_000_000);
    Ok(Some(format!("{} {}", &iso[..10], &iso[11..19])))
}

fn refuse(status: u16, errortype: &'static str, message: impl Into<String>) -> ApiRefusal {
    ApiRefusal {
        status,
        errortype,
        message: message.into(),
        data: Value::Null,
    }
}

/// The bytes did not all arrive as declared, as the platform's completion
/// says it: 422 with `reason: upload_incomplete`, which a retry cures.
fn upload_incomplete(message: &'static str) -> ApiRefusal {
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: message.into(),
        data: json!({ "reason": "upload_incomplete" }),
    }
}

/// A refusal the same request would get again (`standing: true`), as the
/// platform renders every one: 422, with its data beside the message.
fn standing(reason: &'static str, message: &'static str) -> ApiRefusal {
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: message.into(),
        data: json!({ "reason": reason, "standing": true }),
    }
}

/// A refusal a client can act on: the name is taken, the marker says so in a
/// field rather than in English, and the refusal names what holds the name --
/// the shape the platform sends (`DriveHelper::name_taken_data`), held to the
/// example in jd-proto's `tests/fixtures/name_taken_refusal.json`.
///
/// The server has the colliding row in its hand at the moment it refuses — it
/// just looked the collision up in order to decide. Saying which entity holds
/// the name costs nothing here and saves the client an index walk it cannot
/// afford: `drive_index` is a whole-tree cursor walk with no way to scope it to
/// a folder, and running one per refusal is what exhausted this rig's rate
/// limit when the verifier tried it every settle.
///
/// The precedent is `parent_trashed`, which already names the folder it hit.
///
/// `holder_sha256` is present only for a file, and it is the hash the SERVER
/// holds — which for an encrypted file is of the CIPHERTEXT. Identical
/// plaintext encrypts to different bytes every time, so an encrypted holder can
/// never compare equal and adoption is structurally unavailable there. That is
/// correct rather than a gap: an encrypted file's server name is
/// `enc-{content id}`, which nothing else can be holding.
fn name_taken_by(
    message: &'static str,
    holder_type: &str,
    holder_id: i64,
    holder_name: &str,
    holder_sha256: Option<&str>,
) -> ApiRefusal {
    let mut data = serde_json::json!({
        "reason": "name_taken",
        "holder_type": holder_type,
        "holder_id": holder_id,
        // The occupant's name as the SERVER stores it, exact bytes. A refusal
        // can come from an insensitive match -- case, or unicode
        // normalization -- where the name asked for and the name held are not
        // the same string, and a client adopting the holder has to record the
        // spelling the server actually has.
        "holder_name": holder_name,
    });
    if let Some(sha) = holder_sha256 {
        data["holder_sha256"] = serde_json::json!(sha);
    }
    ApiRefusal {
        status: 422,
        errortype: "ActionError",
        message: message.into(),
        data,
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
                shared_in: std::collections::BTreeSet::new(),
                shared_in_read_only: false,
                upload_inits: 0,
                trash_requests: 0,
                list_requests: 0,
                answer_next: Vec::new(),
                actor: None,
                caller: None,
                trash_calls: Vec::new(),
                vault_public_keys: BTreeMap::new(),
                vault_key_generation: 0,
                vault_holders: std::collections::BTreeSet::new(),
                index_requests: 0,
                probe_requests: 0,
            })),
            clock,
            during_upload: Arc::new(Mutex::new(None)),
            minting_a_download: Arc::new(Mutex::new(None)),
            move_arrives: Arc::new(Mutex::new(None)),
            plain_refusals: Arc::new(Mutex::new(false)),
            ignores_unchanged_since: Arc::new(Mutex::new(false)),
            ignores_scope: Arc::new(Mutex::new(false)),
            index_shows_nothing: Arc::new(Mutex::new(false)),
        }
    }

    /// A folder another member owns and has shared with this account, as its
    /// editor: everything later made inside it is that member's too.
    pub fn seed_shared_in_folder(&self, parent: Option<i64>, name: &str) -> i64 {
        let id = self.seed_folder(parent, name);
        self.state.lock().unwrap().shared_in.insert(("folder", id));
        id
    }

    /// The entity, and everything inside it, now belongs to another member
    /// who shares it with this account: ownership moved (a site copy, an
    /// account merge), so it leaves the account's own Drive.
    pub fn hand_to_another_member(&self, entity_type: &'static str, id: i64) {
        self.state.lock().unwrap().shared_in.insert((entity_type, id));
    }

    /// The index answers that there is nothing, as a walk that showed nothing
    /// would: what a full walk did not return is never, by that alone, gone.
    pub fn index_shows_nothing(&self, on: bool) {
        *self.index_shows_nothing.lock().unwrap() = on;
    }

    /// What is shared in to the account is shared as a viewer's: an upload,
    /// a new version or a folder made inside it is refused, as the platform
    /// refuses a write a grant does not cover.
    pub fn shared_in_read_only(&self, on: bool) {
        self.state.lock().unwrap().shared_in_read_only = on;
    }

    /// How many trashes clients have asked for.
    pub fn trash_requests(&self) -> usize {
        self.state.lock().unwrap().trash_requests
    }

    /// How many folder listings clients have asked for.
    pub fn list_requests(&self) -> usize {
        self.state.lock().unwrap().list_requests
    }

    /// How many index pages were asked for: every full walk asks for at
    /// least one.
    pub fn index_requests(&self) -> usize {
        self.state.lock().unwrap().index_requests
    }

    /// How many times a device asked about the vault (`vault_client_probe`).
    pub fn probe_requests(&self) -> usize {
        self.state.lock().unwrap().probe_requests
    }

    /// The next call to `action` fails with `status`, once: a server error
    /// (5xx) on one chosen call, which the rate knobs cannot aim.
    pub fn answer_next(&self, action: &str, status: u16) {
        self.state.lock().unwrap().answer_next.push((action.to_string(), status, None));
    }

    /// As `answer_next`, the refusal carrying this `reason`.
    pub fn answer_next_because(&self, action: &str, status: u16, reason: &'static str) {
        self.state.lock().unwrap().answer_next.push((action.to_string(), status, Some(reason)));
    }

    /// How many uploads clients have begun.
    pub fn upload_inits(&self) -> usize {
        self.state.lock().unwrap().upload_inits
    }

    /// Answer as a server from before the own-Drive scope: `scope` is not
    /// read, so the feed and `drive_stat` hand a sync client what is shared
    /// in to the account, as they did.
    pub fn ignores_scope(&self, on: bool) {
        *self.ignores_scope.lock().unwrap() = on;
    }

    /// Is this entity, or a folder above it, shared in to the account?
    fn is_shared_in(st: &ServerState, t: &str, id: i64) -> bool {
        let mut folder = if t == "folder" {
            if st.shared_in.contains(&("folder", id)) {
                return true;
            }
            st.folders.get(&id).and_then(|f| f.parent)
        } else {
            if st.shared_in.contains(&("file", id)) {
                return true;
            }
            st.files.get(&id).and_then(|f| f.folder)
        };
        let mut guard = 0;
        while let Some(fid) = folder {
            if st.shared_in.contains(&("folder", fid)) {
                return true;
            }
            guard += 1;
            if guard > 10_000 {
                return false;
            }
            folder = st.folders.get(&fid).and_then(|f| f.parent);
        }
        false
    }

    /// Does this request ask for the account's own Drive only, and does this
    /// server read the question?
    fn own_drive_only(&self, body: &Value) -> bool {
        body.get("scope").and_then(Value::as_str) == Some("mine") && !*self.ignores_scope.lock().unwrap()
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

    /// Do something to the world each time a download asks for its link,
    /// just before the server answers.
    ///
    /// The download is under way at that instant -- planned, journaled, its
    /// own fresh look at the file asked for -- and not one byte has moved.
    /// Whatever the server changes now is what the download is about to be
    /// decided against: a key withdrawn here is withdrawn from a download in
    /// flight.
    pub fn while_minting_a_download(&self, f: impl FnMut() + Send + 'static) {
        *self.minting_a_download.lock().unwrap() = Some(Box::new(f));
    }

    /// Show each move or rename (`drive_move`, `drive_rename`) to `f` as it
    /// arrives, before the server reads it, until `f` answers true.
    ///
    /// That instant is the one between a device deciding a move against what
    /// it last heard and the server carrying it out. A peer's move landing now
    /// is one the deciding device cannot have heard of: a device that slept
    /// between the two put a file back over another device's newer move (soak
    /// rig run 2416). `f` is taken out while it runs, so it can change the
    /// server through the server's own calls.
    pub fn when_a_move_arrives(&self, f: impl FnMut(&str, &Value) -> bool + Send + 'static) {
        *self.move_arrives.lock().unwrap() = Some(Box::new(f));
    }

    /// Answer every refusal from here on in prose alone, with no marker saying
    /// what kind of refusal it is, and leave the feed's `refusals: reasoned`
    /// out: an older server. See [`MockServer::plain_refusals`].
    pub fn refuses_without_saying_why(&self, on: bool) {
        *self.plain_refusals.lock().unwrap() = on;
    }

    /// Stop reading `unchanged_since` on a trash. See
    /// [`MockServer::ignores_unchanged_since`].
    pub fn ignores_unchanged_since(&self, on: bool) {
        *self.ignores_unchanged_since.lock().unwrap() = on;
    }

    /// Register a user's Drive vault public key.
    ///
    /// Everything in this mock belongs to one owner, [`OWNER_USER_ID`], because
    /// the sharing model is not what these scenarios are about — what matters is
    /// that an encrypted upload has to ASK who the readers are and seal to every
    /// one of them, and that a reader without a vault is a case it survives.
    pub fn set_vault_public_key(&self, user_id: i64, public_key: &str) {
        let mut st = self.state.lock().unwrap();
        st.vault_public_keys.insert(user_id, public_key.to_string());
        if user_id == OWNER_USER_ID && st.vault_key_generation == 0 {
            st.vault_key_generation = 1;
        }
    }

    /// The owner's vault secret was handed to this device at its link, so
    /// its device row lists the scope (`sde_vault_scopes`).
    pub fn hand_vault_to(&self, device: &str) {
        self.state.lock().unwrap().vault_holders.insert(device.to_string());
    }

    /// Rotate the owner's vault key, as the browser and the platform do it
    /// (`docs/sealed_vault.md`, Rotating a client-custody key): every grant
    /// the owner holds is opened with the old secret and sealed to the new
    /// public key (`drive_key_grants_reseal`), the new key becomes the key
    /// and its generation moves on, and the scope is taken off every device
    /// that held the old one. No file's bytes, content id or file key
    /// change, and no feed row is written: the reseal records none, so a
    /// device learns of a rotation only by asking (`vault_client_probe`).
    ///
    /// Returns how many grants were re-sealed.
    pub fn rotate_vault(&self, from: &crate::engine::SimVault, to: &crate::engine::SimVault) -> usize {
        let mut st = self.state.lock().unwrap();
        let mut resealed = 0;
        for f in st.files.values_mut() {
            let Some(wrapped) = f.wrapped_file_key.as_deref() else {
                continue;
            };
            let key = jd_crypto::drive::open_wrapped_file_key(
                wrapped,
                &from.secret_key_pkcs8,
                &from.public_key_b64,
            )
            .expect("rotate_vault: a grant the old vault key does not open");
            f.wrapped_file_key = Some(to.grant(&key));
            resealed += 1;
        }
        st.vault_public_keys.insert(OWNER_USER_ID, to.public_key_b64.clone());
        st.vault_key_generation += 1;
        st.vault_holders.clear();
        resealed
    }

    /// The device whose call created this entity, as the change feed
    /// attributes it (`acting_as`); `None` for one the harness made by hand.
    pub fn created_by(&self, entity_type: &str, id: i64) -> Option<String> {
        let st = self.state.lock().unwrap();
        st.changes
            .iter()
            .find(|c| c.entity_type == entity_type && c.entity_id == id && c.kind == "created")
            .and_then(|c| c.actor.clone())
    }

    /// Who subsequent calls are attributed to.
    pub fn acting_as(&self, device: Option<&str>) {
        self.state.lock().unwrap().actor = device.map(|d| d.to_string());
    }

    /// The device whose call is being answered, set for the length of the
    /// call by the network layer and cleared after it.
    pub fn calling_from(&self, device: Option<&str>) {
        self.state.lock().unwrap().caller = device.map(|d| d.to_string());
    }

    /// Every trash that took something live, in order.
    pub fn trash_calls(&self) -> Vec<TrashCall> {
        self.state.lock().unwrap().trash_calls.clone()
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

    /// Every folder the server holds, live or trashed, as the facts an oracle
    /// asks about identity: which id wears which name, under which parent,
    /// with which protection. A tree cannot answer that -- a folder that has
    /// stopped being the vault and a vault that has moved can draw the same
    /// picture -- so this is asked by id.
    pub fn folders(&self) -> Vec<FolderFact> {
        let st = self.state.lock().unwrap();
        let mut out: Vec<FolderFact> = st
            .folders
            .values()
            .map(|f| FolderFact {
                id: f.id,
                parent: f.parent,
                name: f.name.clone(),
                encrypted: f.encrypted,
                trashed: f.trashed,
            })
            .collect();
        out.sort_by_key(|f| f.id);
        out
    }

    /// Every file the server holds, live or trashed, as the facts an oracle
    /// asks about custody.
    pub fn files(&self) -> Vec<FileFact> {
        let st = self.state.lock().unwrap();
        let mut out: Vec<FileFact> = st
            .files
            .values()
            .map(|f| FileFact {
                id: f.id,
                folder: f.folder,
                name: f.name.clone(),
                sha256: f.sha256.clone(),
                encrypted: f.encrypted,
                trashed: f.trashed,
                modified_time: f.modified_time.clone(),
            })
            .collect();
        out.sort_by_key(|f| f.id);
        out
    }

    /// Every file the server holds as encrypted, trashed or not. What it
    /// stores for these is a placeholder name and ciphertext; a version of one
    /// whose bytes are a user's plaintext is a file that went up sealed in
    /// name only.
    pub fn encrypted_file_ids(&self) -> std::collections::BTreeSet<i64> {
        let st = self.state.lock().unwrap();
        st.files.values().filter(|f| f.encrypted).map(|f| f.id).collect()
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
    /// Turn a top-level folder Private, or back to Standard, as its owner does
    /// in a browser (`drive_level_change`): the whole subtree's promise flips
    /// at once, its files are sealed or opened, and the feed carries one
    /// change, on the top folder (`level_changed`), as the platform's does.
    pub fn set_private(&self, path: &str, on: bool) {
        let top = self.folder_id_at(path).expect("a folder at that path");
        let mut st = self.state.lock().unwrap();
        assert!(st.folders[&top].parent.is_none(), "only a top-level folder changes level");
        assert!(!st.folders[&top].encrypted, "a vault is not a level this changes");
        let mut tree = vec![top];
        let mut i = 0;
        while i < tree.len() {
            let here = tree[i];
            tree.extend(st.folders.values().filter(|f| f.parent == Some(here)).map(|f| f.id));
            i += 1;
        }
        for id in &tree {
            if let Some(f) = st.folders.get_mut(id) {
                f.private = on;
            }
        }
        for f in st.files.values_mut() {
            if f.folder.is_some_and(|d| tree.contains(&d)) {
                f.sealed = on;
            }
        }
        Self::record(&mut st, "folder", top, "level_changed");
    }

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
            // The account's own Drive: what is shared in to it is another
            // member's, and not synced.
            if f.trashed || Self::is_shared_in(&st, "folder", f.id) {
                continue;
            }
            if let Some(path) = Self::folder_path(&st, f.id) {
                out.insert(path, None);
            }
        }
        for f in st.files.values() {
            if f.trashed || Self::is_shared_in(&st, "file", f.id) {
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
        let sealed = folder.is_some_and(|d| st.folders.get(&d).is_some_and(|f| f.private));
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
                sealed,
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

    /// A new version of a plain file, as another device's upload would land
    /// it: the bytes, a `content` row, and the file's head moved to it. For a
    /// scenario that needs a peer's edit to reach the server at a moment no
    /// pass can reach -- mid-way through another device's pass.
    pub fn seed_version(&self, file_id: i64, bytes: &[u8]) {
        let sha = sha256_hex(bytes);
        let mut st = self.state.lock().unwrap();
        st.blobs.insert(sha.clone(), bytes.to_vec());
        let change_id = Self::record(&mut st, "file", file_id, "content");
        let f = st.files.get_mut(&file_id).expect("seed_version: no such file");
        f.sha256 = sha.clone();
        f.size = bytes.len() as u64;
        f.head_change_id = change_id;
        st.versions.push(VersionRow {
            file_id,
            sha256: sha,
            size: bytes.len() as u64,
            change_id,
        });
    }

    /// Give a plain file the modification time a client declared for it, in
    /// the server's spelling (`2021-03-04 05:06:07`), or none: a file put up
    /// by a client that declares one, as the browser's uploader does not.
    pub fn seed_modified_time(&self, file_id: i64, modified_time: Option<&str>) {
        let mut st = self.state.lock().unwrap();
        let f = st.files.get_mut(&file_id).expect("seed_modified_time: no such file");
        assert!(!f.encrypted, "the server holds no time for an encrypted file");
        f.modified_time = modified_time.map(str::to_string);
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
        let (id, grant) = self.seed_vault_file_awaiting_its_key(folder, name, plaintext, vault_public_key_b64);
        if let Some(f) = self.state.lock().unwrap().files.get_mut(&id) {
            f.wrapped_file_key = Some(grant);
        }
        id
    }

    /// The same file, with this user's key not issued yet: the file row and
    /// its bytes are on the server, and the grant is handed back for the
    /// scenario to issue later (`grant_file_key`). What a member a file was
    /// shared with sees between the owner's two calls: `drive_share_sync`
    /// grants access, and `drive_key_grants_sync` the key, after it.
    pub fn seed_vault_file_awaiting_its_key(
        &self,
        folder: Option<i64>,
        name: &str,
        plaintext: &[u8],
        vault_public_key_b64: &str,
    ) -> (i64, String) {
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
            // The real export sends no plaintext modification time for an
            // encrypted file; the true one lives inside the blob.
            f.modified_time = None;
        }
        (id, grant)
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
    ///
    /// A key that arrives is a `grant_changed` row on the feed, addressed to
    /// the user who gained it -- the platform's contract for a key grant
    /// (`drive_key_grants_sync`), one row per file per user who gained or
    /// lost the key. Everything here belongs to one user, so that user is the
    /// row's whole audience. Without the row nothing tells a device the grant
    /// is there: the feed names only what changed, and the file itself did
    /// not.
    pub fn grant_file_key(&self, file_id: i64, wrapped_file_key: &str) {
        let mut st = self.state.lock().unwrap();
        let Some(f) = st.files.get_mut(&file_id) else {
            return;
        };
        let gained = f.wrapped_file_key.is_none();
        f.wrapped_file_key = Some(wrapped_file_key.to_string());
        if gained {
            Self::record(&mut st, "file", file_id, "grant_changed");
        }
    }

    /// Take this caller's key to an encrypted file away again: the grant row
    /// deleted, as `drive_key_grants_sync` deletes one for a user left out of
    /// a file's set, and a `grant_changed` row addressed to the user who lost
    /// it. The file and its bytes stay; what is gone is this user's way in.
    pub fn revoke_file_key(&self, file_id: i64) {
        let mut st = self.state.lock().unwrap();
        let Some(f) = st.files.get_mut(&file_id) else {
            return;
        };
        if f.wrapped_file_key.take().is_some() {
            Self::record(&mut st, "file", file_id, "grant_changed");
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
        // The real server takes a protection level only at the root; a folder
        // under a parent inherits the parent's, and raising it part-way down
        // a tree is refused (`drive_folder_create_logic`). A vault under a
        // plain folder is a state the server cannot be talked into, and a
        // scenario built on one proves nothing about the platform.
        if let Some(p) = parent {
            let plain = self
                .state
                .lock()
                .unwrap()
                .folders
                .get(&p)
                .is_some_and(|f| !f.encrypted);
            assert!(
                !plain,
                "a vault folder can sit only at the drive root or inside another vault; \
                 folder {p} is plain"
            );
        }
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
        let private = parent.is_some_and(|p| st.folders.get(&p).is_some_and(|f| f.private));
        st.folders.insert(
            id,
            FolderRow {
                id,
                parent,
                name: name.to_string(),
                trashed: false,
                encrypted,
                private,
            },
        );
        id
    }

    // ---- the action surface ------------------------------------------------

    /// `POST /action/{name}`.
    pub fn action(&self, name: &str, body: &Value) -> ServerResult {
        let out = self.dispatch(name, body);
        match out {
            Err(mut refusal) if *self.plain_refusals.lock().unwrap() => {
                refusal.data = Value::Null;
                Err(refusal)
            }
            Ok(mut answer) if *self.plain_refusals.lock().unwrap() => {
                if let Some(o) = answer.as_object_mut() {
                    o.remove("refusals");
                }
                Ok(answer)
            }
            other => other,
        }
    }

    fn dispatch(&self, name: &str, body: &Value) -> ServerResult {
        if name == "drive_move" || name == "drive_rename" {
            let hook = self.move_arrives.lock().unwrap().take();
            if let Some(mut f) = hook {
                if !f(name, body) {
                    let mut slot = self.move_arrives.lock().unwrap();
                    if slot.is_none() {
                        *slot = Some(f);
                    }
                }
            }
        }
        {
            let mut st = self.state.lock().unwrap();
            if name == "drive_trash" {
                st.trash_requests += 1;
            }
            if name == "drive_list" {
                st.list_requests += 1;
            }
            if name == "drive_index" {
                st.index_requests += 1;
            }
            if name == "vault_client_probe" {
                st.probe_requests += 1;
            }
            if let Some(i) = st.answer_next.iter().position(|(a, _, _)| a == name) {
                let (_, status, reason) = st.answer_next.remove(i);
                let mut refusal = refuse(status, "ServerError", "The server could not answer just now.");
                if let Some(reason) = reason {
                    refusal.data = json!({ "reason": reason });
                }
                return Err(refusal);
            }
        }
        match name {
            "drive_changes" => self.drive_changes(body),
            "drive_stat" => self.drive_stat(body),
            "drive_index" => self.drive_index(body),
            "drive_list" => self.drive_list(body),
            "drive_folder_create" => self.drive_folder_create(body),
            "drive_rename" => self.drive_rename(body),
            "drive_move" => self.drive_move(body),
            "drive_trash" => self.drive_trash(body),
            "drive_restore" => self.drive_restore(body),
            "drive_versions" => self.drive_versions(body),
            "drive_upload_init" => self.drive_upload_init(body),
            "drive_public_keys" => self.drive_public_keys(body),
            "drive_upload_complete" => self.drive_upload_complete(body),
            "vault_client_probe" => self.vault_client_probe(body),
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
            return prior_response;
        }
        let out = self.action(name, body);
        if !matches!(&out, Err(refusal) if refusal.status >= 500) {
            self.state.lock().unwrap().idempotency.insert(
                key.to_string(),
                (name.to_string(), body.clone(), out.clone()),
            );
        }
        out
    }

    /// How many times a key was offered for a second, different request.
    pub fn key_conflicts(&self) -> usize {
        self.state.lock().unwrap().key_conflicts
    }

    fn drive_changes(&self, body: &Value) -> ServerResult {
        let cursor = body.get("cursor").and_then(Value::as_i64).unwrap_or(0);
        let limit = body.get("limit").and_then(Value::as_i64).unwrap_or(500);
        let st = self.state.lock().unwrap();

        // The cursor cannot be proven contiguous with what the feed still
        // holds, as the platform judges it (`_drive_changes_must_reset`): a
        // cold start on a server with history, which no row vouches for (the
        // feed keeps only its retention window, so a file untouched for
        // longer has no row left: B-CS); a cursor into history we no longer
        // have; a feed trimmed empty and a cursor short of its head; or a
        // cursor ahead of the head, one this server never issued (a restore
        // from an older backup, a copy of the site). A cursor at the head of
        // an empty feed missed nothing, so a client reset once parks there.
        // Anything but a reset here hands the client
        // a feed with a hole in it, and a hole in a change feed is a file that
        // silently never syncs again.
        let head = st.next_change_id;
        let reset = cursor > head
            || head > 0
                && (cursor == 0
                || if st.changes.is_empty() {
                    cursor < head
                } else {
                    cursor < st.retained_from
                });
        if reset {
            return Ok(json!({
                "ok": true,
                "reset": true,
                "changes": [],
                "next_cursor": st.next_change_id,
                "refusals": "reasoned",
            }));
        }

        let mine = self.own_drive_only(body);
        let mut out = Vec::new();
        let mut next = cursor;
        for c in st.changes.iter().filter(|c| c.change_id > cursor) {
            if out.len() as i64 >= limit {
                break;
            }
            if mine && Self::is_shared_in(&st, c.entity_type, c.entity_id) {
                next = c.change_id;
                continue;
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
        Ok(json!({ "ok": true, "changes": out, "next_cursor": next, "refusals": "reasoned" }))
    }

    fn drive_stat(&self, body: &Value) -> ServerResult {
        let entities = body
            .get("entities")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        if entities.is_empty() {
            return Err(refused(
                "invalid_request",
                "No entities were requested.",
            ));
        }
        if entities.len() > 500 {
            return Err(refused(
                "invalid_request",
                "At most 500 entities may be requested at once.",
            ));
        }
        let with_urls = body.get("urls").and_then(Value::as_bool).unwrap_or(false);
        // Before the server state is locked, as for the upload hook: the
        // scenario's code changes the server, through its own calls.
        if with_urls {
            if let Some(f) = self.minting_a_download.lock().unwrap().as_mut() {
                f();
            }
        }

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
            return Err(refused(
                "invalid_request",
                "No valid entities were requested.",
            ));
        }

        let mine = self.own_drive_only(body);
        let now = self.clock.now_ms();
        let mut st = self.state.lock().unwrap();
        let mut items = Vec::new();
        let mut missing = Vec::new();
        let mut not_yours = Vec::new();
        for (t, id) in wanted {
            if mine && Self::is_shared_in(&st, &t, id) {
                missing.push(json!({ "entity_type": t, "entity_id": id }));
                not_yours.push(json!({ "entity_type": t, "entity_id": id }));
                continue;
            }
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
        Ok(json!({ "ok": true, "items": items, "missing": missing, "not_yours": not_yours }))
    }

    fn drive_index(&self, body: &Value) -> ServerResult {
        let token = body
            .get("after_id")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        let limit = body.get("limit").and_then(Value::as_i64).unwrap_or(500) as usize;
        let (phase, after) = parse_index_token(&token);
        if *self.index_shows_nothing.lock().unwrap() {
            return Ok(json!({ "ok": true, "scope": "mine", "items": [], "next_after_id": token, "done": true, "refusals": "reasoned" }));
        }

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
                    last = format!("folder:{id}");
                    if Self::is_shared_in(&st, "folder", *id) {
                        continue;
                    }
                    items.push(Self::folder_export(f));
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
                    last = format!("file:{id}");
                    if Self::is_shared_in(&st, "file", *id) {
                        continue;
                    }
                    items.push(Self::file_export(&mut st, &f, false, 0));
                }
            }
        }

        Ok(json!({
            "ok": true,
            "scope": "mine",
            "items": items,
            "next_after_id": last,
            "done": done,
            "refusals": "reasoned",
        }))
    }

    /// A folder's live children, the account's own, as `drive_list` returns
    /// them: one page, never truncated here.
    fn drive_list(&self, body: &Value) -> ServerResult {
        let folder = body.get("folder_id").and_then(Value::as_i64).filter(|f| *f > 0);
        let st = self.state.lock().unwrap();
        let mut items = Vec::new();
        for f in st.folders.values() {
            if !f.trashed && f.parent == folder && !Self::is_shared_in(&st, "folder", f.id) {
                items.push(json!({ "entity_type": "folder", "id": f.id, "name": f.name }));
            }
        }
        for f in st.files.values() {
            if !f.trashed && f.folder == folder && !Self::is_shared_in(&st, "file", f.id) {
                items.push(json!({ "entity_type": "file", "id": f.id, "name": f.name }));
            }
        }
        Ok(json!({ "ok": true, "items": items, "truncated": false }))
    }

    fn drive_folder_create(&self, body: &Value) -> ServerResult {
        let name = body
            .get("name")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();
        if name.is_empty() {
            return Err(refused("invalid_request", "A name is required."));
        }
        let parent = body.get("parent_id").and_then(Value::as_i64);
        let mut st = self.state.lock().unwrap();
        if let Some(p) = parent {
            match st.folders.get(&p) {
                None => return Err(refused("parent_not_found", "That folder does not exist.")),
                Some(f) if f.trashed => return Err(parent_trashed(p)),
                Some(_) => {}
            }
            if st.shared_in_read_only && Self::is_shared_in(&st, "folder", p) {
                return Err(standing("forbidden", "You do not have access to that folder."));
            }
        }
        // A live folder of that name in that parent already exists. The real
        // server refuses this (`DriveHelper::folder_name_taken`), and the mock
        // did not — so the engine was never once exercised against the refusal,
        // and a client that loops forever on it reached a live box before
        // anybody noticed. Trashed siblings do not count, exactly as there.
        if let Some(holder) = st
            .folders
            .values()
            .find(|f| !f.trashed && f.parent == parent && f.name == name)
        {
            // The marker, like every other name collision here and like the
            // real endpoint, which has sent `reason: name_taken` from this path
            // all along. Refusing in English only meant the client could not
            // tell this apart from a refusal it can do nothing about, so it
            // withdrew the operation and planned the identical one next pass --
            // a device that never goes quiet, against a server that had told it
            // exactly what was wrong.
            return Err(name_taken_by(
                "A folder with that name already exists here.",
                "folder",
                holder.id,
                &holder.name,
                None,
            ));
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
            // So is Private.
            private: parent.is_some_and(|p| st.folders.get(&p).is_some_and(|f| f.private)),
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
                return Err(refused(
                    "invalid_request",
                    "Encrypted files are renamed via their encrypted metadata, not a plaintext name.",
                ));
            }
            if enc_metadata.is_empty() {
                return Err(refused(
                    "invalid_request",
                    "Encrypted rename is missing its metadata.",
                ));
            }
        } else if name.is_empty() {
            return Err(refused("invalid_request", "A name is required."));
        }

        // The name the client last saw it under (`from_name`), as the platform
        // asks it: an item called something else now was renamed after this
        // rename was decided. A rename to the name it has asks nothing, and an
        // encrypted file's stored title is its placeholder whatever it is called.
        if let Some(seen) = body.get("from_name").and_then(Value::as_str).filter(|n| !n.is_empty() && !encrypted_file) {
            let now = if t == "folder" {
                st.folders.get(&id).map(|f| f.name.clone())
            } else {
                st.files.get(&id).map(|f| f.name.clone())
            };
            if now.is_some_and(|now| now != seen && now != name) {
                return Err(refused("moved_since", "That item has been renamed since."));
            }
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
                    None => return Err(refused("not_found", "That folder does not exist.")),
                };
                st.folders
                    .values()
                    .find(|f| f.id != id && !f.trashed && f.parent == parent && f.name == name)
                    .map(|f| ("folder", f.id, f.name.clone(), None))
            } else {
                let folder = match st.files.get(&id) {
                    Some(f) => f.folder,
                    None => return Err(refused("not_found", "That file does not exist.")),
                };
                st.files
                    .values()
                    .find(|f| f.id != id && !f.trashed && f.folder == folder && f.name == name)
                    .map(|f| ("file", f.id, f.name.clone(), Some(f.sha256.clone())))
            };
            if let Some((kind, holder_id, holder_name, sha)) = taken {
                return Err(name_taken_by(
                    "Something with that name is already here.",
                    kind,
                    holder_id,
                    &holder_name,
                    sha.as_deref(),
                ));
            }
        }

        if t == "folder" {
            match st.folders.get_mut(&id) {
                Some(f) => f.name = name,
                None => return Err(refused("not_found", "That folder does not exist.")),
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
                None => return Err(refused("not_found", "That file does not exist.")),
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
            return Err(refused(
                "invalid_request",
                "drive_move takes parent_id; folder_id is not read.",
            ));
        }
        let dest = body.get("parent_id").and_then(Value::as_i64);
        let mut st = self.state.lock().unwrap();
        // The top level is the owner's.
        if dest.is_none() && Self::is_shared_in(&st, t, id) {
            return Err(standing("forbidden", "Only the owner can move this to the top of their Drive."));
        }
        // Where the client last saw it (`from_parent_id`, 0 for the top), as
        // the platform asks it: an item standing in another folder now was
        // moved after this move was decided, and carried out, it would put the
        // item back over that move unseen. A move to where it already is asks
        // nothing.
        if let Some(seen) = body.get("from_parent_id").and_then(Value::as_i64) {
            let now = if t == "folder" {
                st.folders.get(&id).map(|f| f.parent)
            } else {
                st.files.get(&id).map(|f| f.folder)
            };
            if now.is_some_and(|now| now != dest && now.unwrap_or(0) != seen) {
                return Err(refused("moved_since", "That item has been moved since."));
            }
        }
        if let Some(d) = dest {
            match st.folders.get(&d) {
                None => return Err(refused("parent_not_found", "That folder does not exist.")),
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
                None => return Err(refused("not_found", "That folder does not exist.")),
            }
        } else {
            match st.files.get(&id) {
                Some(f) => f.encrypted,
                None => return Err(refused("not_found", "That file does not exist.")),
            }
        };
        if item_encrypted != dest_encrypted {
            return Err(protection_boundary(t, dest));
        }
        let dest_private = dest.and_then(|d| st.folders.get(&d)).is_some_and(|f| f.private);
        if t == "folder" {
            if st.folders.get(&id).is_some_and(|f| f.private) != dest_private {
                return Err(ApiRefusal {
                    status: 422,
                    errortype: "ActionError",
                    message: "Change the folder's protection level first, then move it.".into(),
                    data: json!({ "reason": "level_change_refused", "standing": true }),
                });
            }
        } else if st.files.get(&id).is_some_and(|f| f.sealed) && !dest_private {
            return Err(refused("vault_needed", "Unlock your vault to move a Private file out of its folder."));
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
                None => return Err(refused("not_found", "That folder does not exist.")),
            };
            st.folders
                .values()
                .find(|f| f.id != id && !f.trashed && f.parent == dest && f.name == name)
                .map(|f| ("folder", f.id, f.name.clone(), None))
        } else {
            let name = match st.files.get(&id) {
                Some(f) => f.name.clone(),
                None => return Err(refused("not_found", "That file does not exist.")),
            };
            st.files
                .values()
                .find(|f| f.id != id && !f.trashed && f.folder == dest && f.name == name)
                .map(|f| ("file", f.id, f.name.clone(), Some(f.sha256.clone())))
        };
        if let Some((kind, holder_id, holder_name, sha)) = occupied {
            return Err(name_taken_by(
                "Something with that name already exists in the destination.",
                kind,
                holder_id,
                &holder_name,
                sha.as_deref(),
            ));
        }
        if t == "folder" {
            // A folder inside itself detaches the whole subtree from the tree
            // and makes it unreachable — no listing walks into it, so it is
            // gone without being deleted.
            if Some(id) == dest || Self::is_descendant(&st, dest, id) {
                return Err(refused(
                    "move_into_self",
                    "A folder cannot be moved inside itself.",
                ));
            }
            match st.folders.get_mut(&id) {
                Some(f) => f.parent = dest,
                None => return Err(refused("not_found", "That folder does not exist.")),
            }
            Self::record(&mut st, "folder", id, "moved");
        } else {
            match st.files.get_mut(&id) {
                Some(f) => {
                    f.folder = dest;
                    // Sealed on its way into a Private folder, with the
                    // owner's public key alone.
                    f.sealed = dest_private;
                }
                None => return Err(refused("not_found", "That file does not exist.")),
            }
            Self::record(&mut st, "file", id, "moved");
        }
        Self::echo(&mut st, t, id)
    }

    fn drive_trash(&self, body: &Value) -> ServerResult {
        let (t, id) = Self::target(body)?;
        let mut st = self.state.lock().unwrap();
        // Trash is owner-only; an editor's grant never confers it.
        if Self::is_shared_in(&st, t, id) {
            return Err(standing("forbidden", "You do not have permission to delete this item."));
        }
        // The platform refuses a trash when something it would take changed
        // after the cursor the client sent (`specs/drive_trash_unchanged_since.md`):
        // a file created, edited, moved or restored there, or a subfolder moved
        // or restored there, carrying files whose own rows are older. This
        // mock is one lock, so the check and the marking below are atomic by
        // construction -- the platform needs its owner lock for the same.
        let since = body
            .get("unchanged_since")
            .and_then(Value::as_i64)
            .filter(|_| !*self.ignores_unchanged_since.lock().unwrap());
        if let Some(since) = since {
            let newest = if since > 0 && since + 1 < st.retained_from {
                // Older than the feed keeps, by the platform's own test
                // (`cursor + 1 < MIN(id)`): nothing proves the gap. This mock's
                // feed resets one cursor earlier (`cursor < retained_from`).
                Some(since)
            } else if t == "folder" {
                let (folders, files) = Self::subtree(&st, id);
                let live_file = |fid: &i64| st.files.get(fid).is_some_and(|f| !f.trashed);
                let live_folder = |did: &i64| st.folders.get(did).is_some_and(|f| !f.trashed);
                st.changes
                    .iter()
                    .filter(|c| c.change_id > since)
                    .filter(|c| match (c.entity_type, c.kind) {
                        ("file", "created" | "content" | "moved" | "restored") => {
                            files.contains(&c.entity_id) && live_file(&c.entity_id)
                        }
                        ("folder", "moved" | "restored") => {
                            folders.contains(&c.entity_id) && live_folder(&c.entity_id)
                        }
                        _ => false,
                    })
                    .map(|c| c.change_id)
                    .max()
            } else {
                st.changes
                    .iter()
                    .filter(|c| c.change_id > since && c.entity_type == "file" && c.entity_id == id)
                    .filter(|c| matches!(c.kind, "created" | "content" | "restored"))
                    .map(|c| c.change_id)
                    .max()
            };
            if let Some(newest) = newest {
                return Err(ApiRefusal {
                    status: 422,
                    errortype: "ActionError",
                    message: "Something in it changed since then.".into(),
                    data: json!({ "reason": "changed_since", "newest": newest }),
                });
            }
        }
        let mut took: Vec<(&'static str, i64)> = Vec::new();
        if t == "folder" {
            match st.folders.get_mut(&id) {
                Some(f) => {
                    if !f.trashed {
                        took.push(("folder", id));
                    }
                    f.trashed = true;
                }
                None => return Err(refused("not_found", "That folder does not exist.")),
            }
            // Trashing a folder takes its contents with it, and says so with
            // exactly ONE row -- the platform's shape.
            // `DriveHelper::soft_delete_folder_cascade` soft-deletes every
            // descendant by hand and `drive_trash_logic` records a single
            // KIND_TRASHED for the folder the user named; `FileChange::record`
            // is never called from a model, so a cascade is silent by
            // construction there.
            //
            // This mock emitted a row per descendant for most of its life,
            // which quietly told the client more than any real server does. A
            // client leaning on those rows passes every sweep and loses records
            // in the field, so the over-reporting is gone: what a device learns
            // about the contents it now has to ask for.
            //
            // Note the cascade reads its children HERE, at trash time, exactly
            // as the platform does under its placement lock -- so a child moved
            // out first is spared by both.
            let descendants = Self::subtree(&st, id);
            for fid in descendants.1 {
                if let Some(f) = st.files.get_mut(&fid) {
                    if !f.trashed {
                        took.push(("file", fid));
                    }
                    f.trashed = true;
                }
            }
            for did in descendants.0 {
                if let Some(f) = st.folders.get_mut(&did) {
                    if !f.trashed {
                        took.push(("folder", did));
                    }
                    f.trashed = true;
                }
            }
            Self::record(&mut st, "folder", id, "trashed");
        } else {
            match st.files.get_mut(&id) {
                Some(f) => {
                    if !f.trashed {
                        took.push(("file", id));
                    }
                    f.trashed = true;
                }
                None => return Err(refused("not_found", "That file does not exist.")),
            }
            Self::record(&mut st, "file", id, "trashed");
        }
        if !took.is_empty() {
            let call = TrashCall {
                entity_type: t,
                id,
                caller: st.caller.clone(),
                order: crate::vfs::user_acts_so_far(),
                change_id: st.next_change_id,
                took,
            };
            st.trash_calls.push(call);
        }
        let mut answer = Self::echo(&mut st, t, id)?;
        if let Some(since) = since {
            answer["checked_since"] = json!(since);
        }
        Ok(answer)
    }

    fn drive_restore(&self, body: &Value) -> ServerResult {
        let (t, id) = Self::target(body)?;
        let mut st = self.state.lock().unwrap();
        if t == "folder" {
            match st.folders.get_mut(&id) {
                Some(f) => f.trashed = false,
                None => return Err(refused("not_found", "That folder does not exist.")),
            }
            // Restoring a folder brings its contents back with it, and says so
            // with exactly ONE row -- which is what the platform does:
            // `DriveHelper::restore_folder_cascade` un-deletes every descendant
            // and `drive_restore_logic` records a single KIND_RESTORED for the
            // folder the user asked for. A client acting only on the entity
            // named in the row learns nothing about the contents, and every row
            // that would have described them is behind its cursor for ever.
            //
            // The platform restores only descendants trashed at or after the
            // folder's own delete_time, so a child trashed independently
            // earlier stays in the trash. This mock keeps no delete_time and
            // restores every trashed descendant -- more generous, and generous
            // in the direction that cannot hide the defect.
            let (folders, files) = Self::subtree(&st, id);
            for did in folders {
                if let Some(f) = st.folders.get_mut(&did) {
                    f.trashed = false;
                }
            }
            for fid in files {
                if let Some(f) = st.files.get_mut(&fid) {
                    f.trashed = false;
                }
            }
            Self::record(&mut st, "folder", id, "restored");
        } else {
            match st.files.get_mut(&id) {
                Some(f) => f.trashed = false,
                None => return Err(refused("not_found", "That file does not exist.")),
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

    // ---- vault ---------------------------------------------------------------

    /// `vault_client_probe`: the facts a native client may know about the
    /// caller's vault, and nothing an unlock is made from
    /// (`logic/vault_client_probe_logic.php`). Drive's scope only: the mock
    /// has no other client-custody vault.
    fn vault_client_probe(&self, body: &Value) -> ServerResult {
        let scope = body.get("scope").and_then(Value::as_str).unwrap_or("drive");
        if scope != "drive" {
            return Err(refused("invalid_request", "That is not a client-custody vault scope."));
        }
        let st = self.state.lock().unwrap();
        let held = st.caller.as_ref().is_some_and(|d| st.vault_holders.contains(d));
        Ok(match st.vault_public_keys.get(&OWNER_USER_ID) {
            None => json!({
                "ok": true,
                "scope": scope,
                "set_up": false,
                "public_key": null,
                "key_generation": 0,
                "pending_public_key": null,
                "held_by_this_device": false,
                "device_linked": true,
            }),
            Some(public_key) => json!({
                "ok": true,
                "scope": scope,
                "set_up": true,
                "public_key": public_key,
                "key_generation": st.vault_key_generation,
                "pending_public_key": null,
                "held_by_this_device": held,
                "device_linked": true,
            }),
        })
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
                return Err(refused("not_found", "That folder does not exist."));
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
        // Read before anything else, as the platform reads it: an unusable
        // time is refused for any destination rather than dropped, since the
        // client believes it set one.
        let Ok(modified_time) = modified_time_of(body.get("modified_time"), self.clock.now_ms()) else {
            return Err(refused("invalid_request", "Invalid modification time; use ISO-8601 UTC."));
        };

        let mut st = self.state.lock().unwrap();
        st.upload_inits += 1;
        if let Some(f) = folder {
            match st.folders.get(&f) {
                None => return Err(refused("parent_not_found", "That folder does not exist.")),
                Some(fo) if fo.trashed => return Err(parent_trashed(f)),
                Some(_) => {}
            }
        }
        if let Some(id) = file_id {
            if st.files.get(&id).is_some_and(|f| f.trashed) {
                return Err(file_trashed(id));
            }
        }
        let into_shared = match file_id {
            Some(id) => Self::is_shared_in(&st, "file", id),
            None => folder.is_some_and(|f| Self::is_shared_in(&st, "folder", f)),
        };
        if st.shared_in_read_only && into_shared {
            return Err(standing("forbidden", "You do not have access to that folder."));
        }
        // The platform refuses at init, before a byte moves, and asks again at
        // completion only for uploads that raced each other past it.
        if let Some(quota) = st.quota_bytes {
            let used: u64 = st.blobs.values().map(|b| b.len() as u64).sum();
            if size != u64::MAX && used + size > quota {
                return Err(standing("quota", "That upload would exceed the storage quota."));
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
            return Err(refused(
                "invalid_request",
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
            if modified_time.is_some() {
                return Err(refused(
                    "invalid_request",
                    "An encrypted upload carries its modification time inside its encrypted metadata, not as a parameter.",
                ));
            }
        } else if sha.is_empty() {
            return Err(refused("invalid_request", "sha256 is required."));
        }

        // Dedup: the server already holds these exact bytes, so there is
        // nothing to send. The engine has to handle a completed upload that
        // moved no bytes at all, which is a different code path from the one
        // it usually takes and therefore one worth exercising constantly.
        if !encrypted && st.blobs.contains_key(&sha) {
            let file = Self::commit_content(
                &mut st, file_id, folder, &name, &sha, size, false, None, None, modified_time,
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
                modified_time,
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
            return Err(refused("not_found", "Unknown or expired upload token."));
        };
        if session.received.len() as u64 != session.expected {
            return Err(refused(
                "upload_incomplete",
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
            // Sealed under the file's existing key, which only the owner's
            // window opens; the pending upload is kept for a retry made
            // inside it, as the platform keeps it.
            if st.files.get(&id).is_some_and(|f| f.sealed) {
                return Err(refused("vault_needed", "Unlock your vault to replace the contents of a Private file."));
            }
        }
        // Checked whenever one was declared, encrypted or not, exactly as
        // `drive_upload_complete_logic` checks `fup_expected_sha256`. Skipping
        // it for encrypted uploads left them with no integrity check at all,
        // and a corrupted chunk then became a file no device could ever open.
        if !session.sha256.is_empty() && actual != session.sha256 {
            st.uploads.remove(&token);
            return Err(upload_incomplete(
                "The uploaded bytes do not match the declared hash.",
            ));
        }
        // Quota is enforced here rather than at init, because at init nobody
        // knows whether the bytes will dedup away to nothing.
        if let Some(quota) = st.quota_bytes {
            let used: u64 = st.blobs.values().map(|b| b.len() as u64).sum();
            if used + session.expected > quota {
                st.uploads.remove(&token);
                return Err(standing("quota", "That upload would exceed the storage quota."));
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
                    return Err(refused(
                        "invalid_request",
                        "A new version of an encrypted file must reuse its existing file key; do not send a new wrapped key.",
                    ));
                }
            } else {
                // Without metadata the file has no name, and without a key for
                // the owner it is a file in their own vault they can never open.
                if enc_metadata.as_deref().unwrap_or("").is_empty() || wrapped_keys.is_empty() {
                    st.uploads.remove(&token);
                    return Err(refused(
                        "invalid_request",
                        "Encrypted upload is missing its metadata or keys.",
                    ));
                }
                if !wrapped_keys.contains_key(&OWNER_USER_ID) {
                    st.uploads.remove(&token);
                    return Err(refused(
                        "invalid_request",
                        "Encrypted upload is missing the folder owner's wrapped key.",
                    ));
                }
            }
        } else if enc_metadata.is_some() || !wrapped_keys.is_empty() {
            st.uploads.remove(&token);
            return Err(refused(
                "invalid_request",
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
            session.modified_time.clone(),
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
        modified_time: Option<String>,
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
                if let Some(holder) = st
                    .files
                    .values()
                    .find(|f| !f.trashed && f.folder == folder && f.name == name)
                {
                    return Err(name_taken_by(
                        "A file with that name already exists here.",
                        "file",
                        holder.id,
                        &holder.name,
                        Some(&holder.sha256),
                    ));
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
        let sealed_here = folder.is_some_and(|d| st.folders.get(&d).is_some_and(|f| f.private));
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
                // Carried onto the file only when the upload declared one, as
                // `drive_upload_complete_logic` carries it: a version without
                // one keeps the time the file had.
                if !f.encrypted && modified_time.is_some() {
                    f.modified_time = modified_time;
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
                        modified_time: if encrypted { None } else { modified_time },
                        encrypted,
                        wrapped_file_key: wrapped_key,
                        encrypted_metadata: enc_metadata,
                        sealed: sealed_here,
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
            "protection_level": Self::level_of(f.encrypted, f.private),
            // A sync client skips a Private subtree: it could never open the
            // bytes (docs/drive.md).
            "syncable": !f.private,
        })
    }

    fn level_of(encrypted: bool, private: bool) -> &'static str {
        if encrypted {
            "fortress"
        } else if private {
            "private"
        } else {
            "standard"
        }
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
        out.insert("protection_level".into(), json!(Self::level_of(f.encrypted, f.sealed)));
        out.insert("syncable".into(), json!(!f.sealed));
        if f.sealed {
            // The bytes open only in the owner's window, on a browser session:
            // a sync client is told so and given no link, as the platform
            // gives an API caller none (`DriveHelper::export_file`).
            out.insert("requires_window".into(), json!(true));
        }
        let url = if with_urls && !f.sealed {
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

    /// A name refusal here has the keys, and the JSON types, of the platform's
    /// (jd-proto's `tests/fixtures/name_taken_refusal.json`, which the
    /// platform's `drive_sync_contract` test holds the server to). The two
    /// drifted once: only this mock named the holder, and the client's
    /// adoption of its own upload ran nowhere else.
    #[test]
    fn a_name_refusal_has_the_platforms_shape() {
        let example: Value = serde_json::from_str(include_str!(concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../jd-proto/tests/fixtures/name_taken_refusal.json"
        )))
        .unwrap();
        let shape = |v: &Value| -> Vec<(String, &'static str)> {
            let mut keys: Vec<(String, &'static str)> = v
                .as_object()
                .unwrap()
                .iter()
                .map(|(k, v)| {
                    let kind = match v {
                        Value::String(_) => "string",
                        Value::Number(n) if n.is_i64() => "integer",
                        _ => "other",
                    };
                    (k.clone(), kind)
                })
                .collect();
            keys.sort();
            keys
        };
        let file = name_taken_by("taken", "file", 7, "a.txt", Some("ab"));
        let folder = name_taken_by("taken", "folder", 8, "A", None);
        assert_eq!(shape(&file.data), shape(&example["file"]));
        assert_eq!(shape(&folder.data), shape(&example["folder"]));
    }

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
    fn a_cold_client_is_told_to_walk() {
        // As the platform answers: a cold start reads nothing from the feed,
        // which may already have trimmed the rows of files older than its
        // window, and is sent to the index at the feed's head.
        let s = server();
        s.seed_file(None, "a.txt", b"one");
        s.seed_file(None, "b.txt", b"two");
        let out = s.action("drive_changes", &json!({ "cursor": 0 })).unwrap();
        assert_eq!(out["reset"], json!(true));
        assert_eq!(out["changes"].as_array().unwrap().len(), 0);
        assert_eq!(out["next_cursor"], json!(s.latest_change_id()));
    }

    #[test]
    fn a_client_at_the_head_of_a_trimmed_feed_is_not_reset_again() {
        // A quiet server whose every row was trimmed: reset once, then parked
        // at the head, never reset -- and so sent to walk the index -- on
        // every poll.
        let s = server();
        s.seed_file(None, "a.txt", b"one");
        let head = s.latest_change_id();
        s.prune_feed_before(head + 1);
        let out = s.action("drive_changes", &json!({ "cursor": 0 })).unwrap();
        assert_eq!(out["reset"], json!(true));
        assert_eq!(out["next_cursor"], json!(head));
        let out = s.action("drive_changes", &json!({ "cursor": head })).unwrap();
        assert_ne!(out["reset"], json!(true), "{out}");
        assert_eq!(out["changes"].as_array().unwrap().len(), 0);
    }

    #[test]
    fn a_cursor_this_server_never_issued_is_told_to_walk() {
        // A site restored from an older backup: the devices' cursors are
        // ahead of every id the server now has.
        let s = server();
        s.seed_file(None, "a.txt", b"one");
        let head = s.latest_change_id();
        let out = s.action("drive_changes", &json!({ "cursor": head + 50 })).unwrap();
        assert_eq!(out["reset"], json!(true), "{out}");
        assert_eq!(out["next_cursor"], json!(head));
    }

    #[test]
    fn a_server_that_never_recorded_a_change_resets_nothing() {
        let s = server();
        let out = s.action("drive_changes", &json!({ "cursor": 0 })).unwrap();
        assert_ne!(out["reset"], json!(true), "{out}");
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
        // As the platform answers it: refused, and saying the bytes did not
        // arrive whole, so a client sends them again.
        assert_eq!((err.status, err.data["reason"].as_str()), (422, Some("upload_incomplete")));
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
    fn quota_is_refused_at_the_start_and_again_at_completion() {
        // As the platform: an upload over quota is refused at init, before a
        // byte moves, and the refusal stands. Uploads that each fit when they
        // began are asked again at completion, where the second to land is
        // refused the same way.
        let s = server();
        s.set_quota_bytes(Some(8));
        let err = s
            .action("drive_upload_init", &json!({ "name": "big.bin", "size_bytes": 9, "sha256": sha256_hex(b"123456789") }))
            .unwrap_err();
        assert_eq!((err.status, err.data["standing"].as_bool()), (422, Some(true)));
        let a = s
            .action("drive_upload_init", &json!({ "name": "a.bin", "size_bytes": 5, "sha256": sha256_hex(b"12345") }))
            .unwrap();
        let b = s
            .action("drive_upload_init", &json!({ "name": "b.bin", "size_bytes": 5, "sha256": sha256_hex(b"abcde") }))
            .unwrap();
        let (ta, tb) = (a["upload_token"].as_str().unwrap().to_string(), b["upload_token"].as_str().unwrap().to_string());
        s.upload_chunk(&ta, 0, b"12345").unwrap();
        s.upload_chunk(&tb, 0, b"abcde").unwrap();
        s.action("drive_upload_complete", &json!({ "upload_token": ta })).unwrap();
        let err = s.action("drive_upload_complete", &json!({ "upload_token": tb })).unwrap_err();
        assert_eq!((err.status, err.data["standing"].as_bool()), (422, Some(true)));
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
    fn a_server_failure_is_not_replayed_under_its_key() {
        // The platform stores what the action answered; a 5xx never reached
        // that store, so the same key asked again is answered afresh. Replayed,
        // one passing failure failed every retry under that key for good.
        let s = server();
        s.answer_next("drive_folder_create", 503);
        let first = s.action_idempotent("drive_folder_create", &json!({ "name": "Docs" }), "k-1");
        assert_eq!(first.map_err(|e| e.status), Err(503));
        s.action_idempotent("drive_folder_create", &json!({ "name": "Docs" }), "k-1").unwrap();
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
    fn trashing_a_folder_takes_the_subtree_and_reports_one_row() {
        // The platform cascades the flags over every descendant and records a
        // single change row, for the folder. This mock says the same thing,
        // and the fidelity is the point: a mock that reported a row per
        // descendant would let a client lean on rows the real server never
        // sends, and the sweep would go green over a defect that only appears
        // in the field.
        //
        // The client's answer to the one row is to forget the subtree, which
        // is `forget_folder_the_server_confirms` in `jd-core`.
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
        let rows: Vec<(&str, &str, i64)> = out["changes"]
            .as_array()
            .unwrap()
            .iter()
            .map(|c| {
                (
                    c["kind"].as_str().unwrap(),
                    c["entity_type"].as_str().unwrap(),
                    c["entity_id"].as_i64().unwrap(),
                )
            })
            .collect();
        assert_eq!(
            rows,
            vec![("trashed", "folder", f)],
            "one row, naming the folder itself"
        );
        // The cascade still happened; it is only unreported.
        assert!(
            s.tree().is_empty(),
            "the whole subtree is gone, {:?} remains",
            s.tree()
        );
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
        assert_eq!((err.status, err.data["reason"].as_str()), (422, Some("invalid_request")));
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
        s.seed_file(None, "before.txt", b"x");
        let at = s.latest_change_id();
        s.acting_as(Some("laptop"));
        s.action("drive_folder_create", &json!({ "name": "Docs" }))
            .unwrap();
        let out = s.action("drive_changes", &json!({ "cursor": at })).unwrap();
        assert_eq!(out["changes"][0]["actor"], json!("laptop"));
    }

    fn kinds_after(s: &MockServer, cursor: i64) -> Vec<(i64, String)> {
        let out = s.action("drive_changes", &json!({ "cursor": cursor })).unwrap();
        out["changes"]
            .as_array()
            .unwrap()
            .iter()
            .map(|c| (c["entity_id"].as_i64().unwrap(), c["kind"].as_str().unwrap().to_string()))
            .collect()
    }

    #[test]
    fn a_key_granted_or_withdrawn_is_a_feed_row_for_the_user_it_concerns() {
        // The platform's contract (`drive_key_grants_sync`): one
        // `grant_changed` row per file per user who gained or lost the key.
        // A key issued again unchanged in who holds it is no row at all.
        let s = server();
        let vault = crate::engine::SimVault::new(7);
        let (id, grant) = s.seed_vault_file_awaiting_its_key(None, "a.txt", b"one", &vault.public_key_b64);
        let at = s.latest_change_id();
        s.grant_file_key(id, &grant);
        s.grant_file_key(id, &grant);
        assert_eq!(kinds_after(&s, at), vec![(id, "grant_changed".to_string())]);
        let stat = s
            .action("drive_stat", &json!({ "entities": [{ "entity_type": "file", "entity_id": id }] }))
            .unwrap();
        assert_eq!(stat["items"][0]["wrapped_file_key"], json!(grant));

        let at = s.latest_change_id();
        s.revoke_file_key(id);
        s.revoke_file_key(id);
        assert_eq!(kinds_after(&s, at), vec![(id, "grant_changed".to_string())]);
        let stat = s
            .action("drive_stat", &json!({ "entities": [{ "entity_type": "file", "entity_id": id }] }))
            .unwrap();
        assert_eq!(stat["items"][0]["wrapped_file_key"], Value::Null, "an encrypted file says it has no grant");
    }

    #[test]
    fn a_rotation_reseals_every_grant_and_the_probe_says_so() {
        let s = server();
        let old = crate::engine::SimVault::new(8);
        let new = crate::engine::SimVault::new(9);
        s.set_vault_public_key(OWNER_USER_ID, &old.public_key_b64);
        s.hand_vault_to("laptop");
        let id = s.seed_vault_file(None, "a.txt", b"one", &old.public_key_b64);
        let probe = |device: &str| {
            s.calling_from(Some(device));
            let out = s.action("vault_client_probe", &json!({ "scope": "drive" })).unwrap();
            s.calling_from(None);
            out
        };
        let before = probe("laptop");
        assert_eq!(before["public_key"], json!(old.public_key_b64));
        assert_eq!(before["key_generation"], json!(1));
        assert_eq!(before["held_by_this_device"], json!(true));
        let at = s.latest_change_id();

        assert_eq!(s.rotate_vault(&old, &new), 1);
        let after = probe("laptop");
        assert_eq!(after["public_key"], json!(new.public_key_b64));
        assert_eq!(after["key_generation"], json!(2));
        assert_eq!(after["held_by_this_device"], json!(false), "a rotation takes the scope off every device");
        assert!(kinds_after(&s, at).is_empty(), "the reseal writes nothing to the feed");
        let grant = s.vault_files()[0].wrapped_file_key.clone().unwrap();
        let opened = jd_crypto::drive::open_wrapped_file_key(&grant, &new.secret_key_pkcs8, &new.public_key_b64);
        assert!(opened.is_ok(), "the grant is sealed to the new key");
        assert!(
            jd_crypto::drive::open_wrapped_file_key(&grant, &old.secret_key_pkcs8, &old.public_key_b64).is_err(),
            "and no longer to the old one"
        );
        assert_eq!(s.vault_files()[0].id, id);
        assert!(s.action("vault_client_probe", &json!({ "scope": "mail" })).is_err());
    }
}
