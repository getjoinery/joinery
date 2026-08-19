//! The server, as the verifier and the remote actor see it.
//!
//! Everything here goes through the public API rather than the database, for one
//! reason: the client can only ever see what the API says, so an oracle reading
//! rows straight out of Postgres could pass a campaign in which the API was
//! quietly lying to every device on the rig. Reading it the way a client does
//! means an API-level bug fails the run instead of being invisible to it.

use std::collections::BTreeMap;

use jd_proto::{DriveApi, ProtoError};
use serde_json::{json, Value};

/// One entity as the server describes it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Entity {
    pub id: i64,
    pub is_folder: bool,
    pub name: String,
    /// Parent folder, or `None` for something at the drive root.
    pub parent_id: Option<i64>,
    pub deleted: bool,
    pub encrypted: bool,
    /// Head content identity. `None` for folders.
    pub sha256: Option<String>,
    pub size: u64,
}

/// The server's whole tree, in both id spaces.
#[derive(Debug, Clone, Default)]
pub struct ServerTree {
    pub folders: BTreeMap<i64, Entity>,
    pub files: BTreeMap<i64, Entity>,
}

impl ServerTree {
    /// The path of an entity from the drive root, or `None` if a parent is
    /// missing from the walk.
    ///
    /// A missing parent is reported rather than papered over with a synthetic
    /// root: it means the index handed back a child without its folder, which is
    /// itself a finding, and hiding it would present an orphan as if it lived at
    /// the top level.
    pub fn path_of(&self, entity: &Entity) -> Option<String> {
        let mut parts = vec![entity.name.clone()];
        let mut parent = entity.parent_id;
        let mut guard = 0;
        while let Some(id) = parent {
            let folder = self.folders.get(&id)?;
            parts.push(folder.name.clone());
            parent = folder.parent_id;
            guard += 1;
            if guard > 512 {
                // A cycle in the folder graph. Not something the server should
                // ever produce, so it is worth not looping forever over.
                return None;
            }
        }
        parts.reverse();
        Some(parts.join("/"))
    }

    /// Live entities by path — the server's half of the tree diff.
    ///
    /// Trashed entities are excluded because they are not where a file *is*.
    /// They are still findable for the no-loss check, which is a different
    /// question and asks it separately.
    pub fn live_paths(&self) -> BTreeMap<String, Entity> {
        let mut out = BTreeMap::new();
        for entity in self.folders.values().chain(self.files.values()) {
            if entity.deleted {
                continue;
            }
            // A live entity inside a trashed folder is not visible either.
            if self.has_trashed_ancestor(entity) {
                continue;
            }
            if let Some(path) = self.path_of(entity) {
                out.insert(path, entity.clone());
            }
        }
        out
    }

    fn has_trashed_ancestor(&self, entity: &Entity) -> bool {
        let mut parent = entity.parent_id;
        let mut guard = 0;
        while let Some(id) = parent {
            match self.folders.get(&id) {
                Some(folder) if folder.deleted => return true,
                Some(folder) => parent = folder.parent_id,
                None => return false,
            }
            guard += 1;
            if guard > 512 {
                return false;
            }
        }
        false
    }

    /// Every content hash the server currently holds as a head, live or trashed.
    pub fn head_contents(&self) -> std::collections::BTreeSet<String> {
        self.files
            .values()
            .filter_map(|f| f.sha256.clone())
            .collect()
    }

    /// Every encrypted entity, for the ciphertext-never-materializes check.
    pub fn encrypted(&self) -> Vec<&Entity> {
        self.folders
            .values()
            .chain(self.files.values())
            .filter(|e| e.encrypted)
            .collect()
    }
}

/// Walk `drive_index` to completion.
///
/// Paged rather than one big call, and the loop is bounded: a server that kept
/// handing back the same cursor would otherwise spin the verifier forever at
/// the exact moment it was supposed to be reporting a problem.
pub fn walk(api: &dyn DriveApi) -> Result<ServerTree, ProtoError> {
    let mut tree = ServerTree::default();
    let mut after: Option<String> = None;
    let mut pages = 0;
    loop {
        let mut body = json!({ "limit": 2000 });
        if let Some(cursor) = &after {
            body["after_id"] = json!(cursor);
        }
        let page = api.action("drive_index", body)?;
        for item in page
            .get("items")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default()
        {
            if let Some(entity) = entity_from(&item) {
                if entity.is_folder {
                    tree.folders.insert(entity.id, entity);
                } else {
                    tree.files.insert(entity.id, entity);
                }
            }
        }
        if page.get("done").and_then(Value::as_bool) == Some(true) {
            break;
        }
        let next = page
            .get("next_after_id")
            .and_then(Value::as_str)
            .map(str::to_string);
        if next.is_none() || next == after {
            return Err(ProtoError::Contract(
                "drive_index is not advancing its cursor".into(),
            ));
        }
        after = next;
        pages += 1;
        if pages > 10_000 {
            return Err(ProtoError::Contract(
                "drive_index walked 10000 pages without finishing".into(),
            ));
        }
    }
    Ok(tree)
}

fn entity_from(item: &Value) -> Option<Entity> {
    let kind = item.get("entity_type").and_then(Value::as_str)?;
    let is_folder = match kind {
        "folder" => true,
        "file" => false,
        _ => return None,
    };
    Some(Entity {
        id: item.get("id").and_then(Value::as_i64)?,
        is_folder,
        name: item.get("name").and_then(Value::as_str)?.to_string(),
        parent_id: item
            .get(if is_folder { "parent_id" } else { "folder_id" })
            .and_then(Value::as_i64),
        deleted: item
            .get("deleted")
            .and_then(Value::as_bool)
            .unwrap_or(false),
        encrypted: item
            .get("encrypted")
            .and_then(Value::as_bool)
            .unwrap_or(false),
        sha256: item
            .get("content_sha256")
            .and_then(Value::as_str)
            .map(str::to_string),
        size: item.get("size").and_then(Value::as_u64).unwrap_or(0),
    })
}

/// What a file's saved history holds, and whether the server would say.
///
/// The second field is the whole point of this being a struct. A server that
/// lists five versions and names the contents of none of them is not a server
/// with no history — it is a server that cannot answer the question, and the
/// two are indistinguishable from a list of hashes alone.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct History {
    pub hashes: Vec<String>,
    /// Version rows the server listed without saying what they hold.
    pub unidentified: usize,
}

/// Every content hash in a file's saved version history.
pub fn version_contents(api: &dyn DriveApi, file_id: i64) -> Result<History, ProtoError> {
    let answer = api.action("drive_versions", json!({ "file_id": file_id }))?;
    let mut history = History::default();
    for version in answer
        .get("versions")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default()
    {
        match version.get("content_sha256").and_then(Value::as_str) {
            Some(sha) => history.hashes.push(sha.to_string()),
            None => history.unidentified += 1,
        }
    }
    Ok(history)
}

/// Hunt through version history for contents that are not accounted for
/// anywhere else.
///
/// **Only called when something is actually missing**, and it stops the moment
/// everything it was looking for has turned up. Walking every file's history
/// unconditionally is one API call per file per settle — on a hundred-thousand
/// entry campaign that is a hundred thousand requests an hour, which exhausts
/// any sane rate limit and makes the verifier the heaviest client on the rig.
/// The first version of this did exactly that, and the rate limiter caught it.
///
/// The common case is that nothing is missing and this is never called at all.
/// When something is, the cost is bounded by how quickly it is found rather than
/// by the size of the drive.
pub fn find_in_version_history(
    api: &dyn DriveApi,
    tree: &ServerTree,
    wanted: &std::collections::BTreeSet<String>,
    budget: usize,
) -> Result<Search, ProtoError> {
    let mut search = Search::default();
    if wanted.is_empty() {
        return Ok(search);
    }
    for file in tree.files.values() {
        if search.asked >= budget || search.found.len() == wanted.len() {
            break;
        }
        search.asked += 1;
        // One file's history failing is not a reason to abandon the search —
        // the content might be in the next one, and reporting loss because a
        // single call errored would be a false violation.
        let Ok(history) = version_contents(api, file.id) else {
            search.unreadable += 1;
            continue;
        };
        search.unidentified += history.unidentified;
        for sha in history.hashes {
            if wanted.contains(&sha) {
                search.found.insert(sha);
            }
        }
    }
    Ok(search)
}

/// Whether this server can answer the question the no-loss invariant asks.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum VersionOracle {
    /// It names the content of the versions it keeps. The invariant is
    /// measurable.
    Answers,
    /// It lists versions and names the content of none of them. Every search of
    /// version history will come back empty whatever the server is holding, so
    /// no-loss cannot be measured at all.
    Anonymous,
    /// No file has a second version yet, so there was nothing to ask about.
    /// Inconclusive rather than good news.
    NothingToAsk,
}

/// Ask the server, before a campaign starts, whether it will identify the
/// contents of a file's saved versions.
///
/// This exists because it did not, for twenty-three runs. The rig's own server
/// predated `content_sha256` on `drive_versions`, so every history came back
/// anonymous, every search returned the empty set, and files the server was
/// holding safely were reported as permanently lost. Nothing about that was
/// visible until somebody read the endpoint's source. Ten seconds up front
/// beats two hours of a campaign that cannot measure its headline invariant.
pub fn version_oracle(api: &dyn DriveApi) -> Result<VersionOracle, ProtoError> {
    let tree = walk(api)?;
    for file in tree.files.values() {
        let history = version_contents(api, file.id)?;
        if !history.hashes.is_empty() {
            return Ok(VersionOracle::Answers);
        }
        if history.unidentified > 0 {
            return Ok(VersionOracle::Anonymous);
        }
    }
    Ok(VersionOracle::NothingToAsk)
}

/// What one pass through version history managed to establish.
///
/// `found` on its own cannot be read: an empty set means "the content is not in
/// any history" only if the search could actually see the histories it walked.
/// The other three fields are how far short of that it fell.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Search {
    pub found: std::collections::BTreeSet<String>,
    /// Files whose history was asked for.
    pub asked: usize,
    /// Version rows listed without a content identity, across every file asked.
    pub unidentified: usize,
    /// Files whose history could not be read at all.
    pub unreadable: usize,
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cell::RefCell;
    use std::io::Write;

    /// A server that hands back exactly the pages it was given.
    struct Canned {
        pages: RefCell<Vec<Value>>,
    }

    impl DriveApi for Canned {
        fn action(&self, _name: &str, _body: Value) -> Result<Value, ProtoError> {
            let mut pages = self.pages.borrow_mut();
            if pages.is_empty() {
                return Err(ProtoError::Contract("no more pages".into()));
            }
            Ok(pages.remove(0))
        }
        fn action_idempotent(
            &self,
            name: &str,
            body: Value,
            _key: &str,
        ) -> Result<Value, ProtoError> {
            self.action(name, body)
        }
        fn upload(
            &self,
            _p: &jd_proto::UploadParams,
            _r: &mut dyn jd_proto::ReadSeek,
        ) -> Result<jd_proto::UploadOutcome, ProtoError> {
            unimplemented!()
        }
        fn download(&self, _u: &str, _f: u64, _o: &mut dyn Write) -> Result<u64, ProtoError> {
            unimplemented!()
        }
    }

    // Safe here: the RefCell never crosses a thread in these tests, and the
    // trait requires Sync for the real client's benefit rather than this one's.
    unsafe impl Sync for Canned {}
    unsafe impl Send for Canned {}

    fn folder(id: i64, name: &str, parent: Option<i64>, deleted: bool) -> Value {
        json!({
            "entity_type": "folder", "id": id, "name": name,
            "parent_id": parent, "deleted": deleted, "encrypted": false,
        })
    }

    fn file(id: i64, name: &str, folder: Option<i64>, sha: &str, deleted: bool) -> Value {
        json!({
            "entity_type": "file", "id": id, "name": name,
            "folder_id": folder, "deleted": deleted, "encrypted": false,
            "content_sha256": sha, "size": 10,
        })
    }

    fn canned(pages: Vec<Value>) -> Canned {
        Canned {
            pages: RefCell::new(pages),
        }
    }

    /// A server that records what it was asked and answers a fixed feed page.
    struct Feed {
        rows: Vec<Value>,
        asked: RefCell<Vec<Value>>,
    }

    impl DriveApi for Feed {
        fn action(&self, _name: &str, body: Value) -> Result<Value, ProtoError> {
            self.asked.borrow_mut().push(body);
            Ok(json!({ "ok": true, "changes": self.rows.clone(), "next_cursor": 9 }))
        }
        fn action_idempotent(
            &self,
            name: &str,
            body: Value,
            _key: &str,
        ) -> Result<Value, ProtoError> {
            self.action(name, body)
        }
        fn upload(
            &self,
            _p: &jd_proto::UploadParams,
            _r: &mut dyn jd_proto::ReadSeek,
        ) -> Result<jd_proto::UploadOutcome, ProtoError> {
            unimplemented!()
        }
        fn download(&self, _u: &str, _f: u64, _o: &mut dyn Write) -> Result<u64, ProtoError> {
            unimplemented!()
        }
    }

    unsafe impl Sync for Feed {}
    unsafe impl Send for Feed {}

    #[test]
    fn a_cursor_with_nothing_after_it_is_up_to_date() {
        let feed = Feed {
            rows: vec![],
            asked: RefCell::new(Vec::new()),
        };
        assert!(!changes_pending(&feed, 41).unwrap());
        // Asked from the device's own cursor, or the answer is about somebody
        // else's position in the feed.
        assert_eq!(feed.asked.borrow()[0]["cursor"], json!(41));
    }

    #[test]
    fn one_unread_change_means_the_device_is_behind() {
        // The whole point: this device reports an empty queue and no entries in
        // flight, and is nonetheless about to learn that a folder it still has
        // on disk was deleted. Believing its quiet here is what makes an audit
        // compare a stale disk against a current server and call the lag a
        // disagreement -- on every device at once, because it is one lag rather
        // than two faults.
        let feed = Feed {
            rows: vec![json!({ "change_id": 42, "entity_type": "folder", "kind": "trashed" })],
            asked: RefCell::new(Vec::new()),
        };
        assert!(changes_pending(&feed, 41).unwrap());
    }

    #[test]
    fn a_paged_walk_collects_every_page() {
        let api = canned(vec![
            json!({"items": [folder(1, "Projects", None, false)], "next_after_id": "p1", "done": false}),
            json!({"items": [file(2, "a.txt", Some(1), "aa", false)], "next_after_id": "p2", "done": true}),
        ]);
        let tree = walk(&api).unwrap();
        assert_eq!(tree.folders.len(), 1);
        assert_eq!(tree.files.len(), 1);
        assert_eq!(
            tree.live_paths().keys().collect::<Vec<_>>(),
            vec!["Projects", "Projects/a.txt"]
        );
    }

    #[test]
    fn a_cursor_that_stops_advancing_is_refused_rather_than_looped_on() {
        // Otherwise the verifier spins forever at the exact moment it was
        // supposed to be reporting a problem.
        let api = canned(vec![
            json!({"items": [], "next_after_id": "same", "done": false}),
            json!({"items": [], "next_after_id": "same", "done": false}),
        ]);
        // The first page sets the cursor; the second repeats it.
        let err = walk(&api).unwrap_err().to_string();
        assert!(
            err.contains("advancing") || err.contains("no more pages"),
            "{err}"
        );
    }

    #[test]
    fn a_trashed_file_is_not_part_of_the_live_tree() {
        // A client that treated the trash as present would report every deleted
        // file as a difference, and every settle would fail.
        let api = canned(vec![json!({
            "items": [file(1, "gone.txt", None, "aa", true), file(2, "here.txt", None, "bb", false)],
            "done": true
        })]);
        let tree = walk(&api).unwrap();
        let live = tree.live_paths();
        assert!(live.contains_key("here.txt"));
        assert!(!live.contains_key("gone.txt"));
        // Still findable for the no-loss question, which is a different one.
        assert!(tree.head_contents().contains("aa"));
    }

    #[test]
    fn a_live_file_inside_a_trashed_folder_is_not_visible_either() {
        // Trashing a folder does not stamp every descendant, so a verifier that
        // only looked at the entity's own flag would demand that every device
        // still hold a subtree the user threw away.
        let api = canned(vec![json!({
            "items": [
                folder(1, "Old", None, true),
                folder(2, "Deeper", Some(1), false),
                file(3, "inside.txt", Some(2), "aa", false),
            ],
            "done": true
        })]);
        let live = walk(&api).unwrap().live_paths();
        assert!(live.is_empty(), "{live:?}");
    }

    #[test]
    fn an_entity_whose_parent_is_missing_is_left_out_rather_than_hoisted_to_the_root() {
        // Presenting an orphan as a top-level file would have the verifier
        // demand it at a path no device could ever have.
        let api = canned(vec![json!({
            "items": [file(9, "orphan.txt", Some(404), "aa", false)],
            "done": true
        })]);
        let tree = walk(&api).unwrap();
        assert!(tree.live_paths().is_empty());
    }

    #[test]
    fn a_cycle_in_the_folder_graph_does_not_hang_the_walk() {
        let api = canned(vec![json!({
            "items": [folder(1, "A", Some(2), false), folder(2, "B", Some(1), false)],
            "done": true
        })]);
        let tree = walk(&api).unwrap();
        assert!(tree.live_paths().is_empty());
    }

    #[test]
    fn folders_and_files_read_their_parent_from_the_field_each_one_actually_uses() {
        // They differ: a folder carries parent_id, a file carries folder_id.
        // Reading one field for both silently puts every file at the root.
        let api = canned(vec![json!({
            "items": [folder(1, "P", None, false), file(2, "f.txt", Some(1), "aa", false)],
            "done": true
        })]);
        let tree = walk(&api).unwrap();
        assert_eq!(tree.files[&2].parent_id, Some(1));
        assert_eq!(tree.folders[&1].parent_id, None);
    }

    #[test]
    fn version_history_is_read_by_content_hash() {
        // The whole reason drive_versions carries content_sha256: without it a
        // client can list a file's history but not say what is in it.
        let api = canned(vec![json!({
            "versions": [
                {"version_id": 2, "content_sha256": "second"},
                {"version_id": 1, "content_sha256": "first"},
            ]
        })]);
        assert_eq!(
            version_contents(&api, 7).unwrap(),
            History {
                hashes: vec!["second".into(), "first".into()],
                unidentified: 0,
            }
        );
    }

    #[test]
    fn a_version_the_server_will_not_identify_is_counted_not_ignored() {
        // The failure this exists to stop, found in run 23 and present in every
        // run before it: the soak server predates content_sha256 on
        // drive_versions, so every version came back anonymous. Filtering them
        // away made a full history read as no history, the search returned the
        // empty set every time, and two files the server was holding as
        // versions were reported permanently lost.
        let api = canned(vec![json!({
            "versions": [
                {"version_id": 2, "version_number": 2, "size": 10},
                {"version_id": 1, "version_number": 1, "size": 10},
            ]
        })]);
        let history = version_contents(&api, 7).unwrap();
        assert!(history.hashes.is_empty());
        assert_eq!(history.unidentified, 2);
    }

    #[test]
    fn a_search_of_anonymous_histories_reports_that_it_could_not_look() {
        let api = canned(vec![
            json!({"items": [file(1, "a.txt", None, "head", false)], "done": true}),
            json!({"versions": [{"version_id": 1, "version_number": 1, "size": 10}]}),
        ]);
        let tree = walk(&api).unwrap();
        let wanted = ["wanted".to_string()].into_iter().collect();
        let search = find_in_version_history(&api, &tree, &wanted, 100).unwrap();
        assert!(search.found.is_empty());
        assert_eq!(search.asked, 1);
        // The difference between "not there" and "could not look", which is the
        // whole point: without this the caller cannot tell them apart.
        assert_eq!(search.unidentified, 1);
    }
}

/// Is there anything in the change feed this cursor has not reached?
///
/// The one question a device's status cannot answer about itself. A daemon
/// reports `pending_ops: 0` and no entries in flight the moment its queue
/// drains -- and it reports exactly that while a folder deleted on the server
/// forty seconds ago still sits unread in the feed, because a change it has not
/// been told about cannot be work it knows it has. So "quiet" and "up to date"
/// are different claims, and only this one distinguishes them.
///
/// Asked the way the client asks, with the client's own cursor: an empty answer
/// is the server saying there is nothing after that point. One row is enough to
/// know, so the page is one row.
pub fn changes_pending(api: &dyn DriveApi, cursor: i64) -> Result<bool, ProtoError> {
    let answer = api.action("drive_changes", json!({ "cursor": cursor, "limit": 1 }))?;
    Ok(answer
        .get("changes")
        .and_then(Value::as_array)
        .is_some_and(|rows| !rows.is_empty()))
}
