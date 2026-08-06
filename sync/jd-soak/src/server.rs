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

/// Every content hash in a file's saved version history.
pub fn version_contents(api: &dyn DriveApi, file_id: i64) -> Result<Vec<String>, ProtoError> {
    let answer = api.action("drive_versions", json!({ "file_id": file_id }))?;
    Ok(answer
        .get("versions")
        .and_then(Value::as_array)
        .map(|list| {
            list.iter()
                .filter_map(|v| v.get("content_sha256").and_then(Value::as_str))
                .map(str::to_string)
                .collect()
        })
        .unwrap_or_default())
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
) -> Result<(std::collections::BTreeSet<String>, usize), ProtoError> {
    let mut found = std::collections::BTreeSet::new();
    let mut asked = 0;
    if wanted.is_empty() {
        return Ok((found, asked));
    }
    for file in tree.files.values() {
        if asked >= budget || found.len() == wanted.len() {
            break;
        }
        asked += 1;
        // One file's history failing is not a reason to abandon the search —
        // the content might be in the next one, and reporting loss because a
        // single call errored would be a false violation.
        let Ok(versions) = version_contents(api, file.id) else {
            continue;
        };
        for sha in versions {
            if wanted.contains(&sha) {
                found.insert(sha);
            }
        }
    }
    Ok((found, asked))
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
        assert_eq!(version_contents(&api, 7).unwrap(), vec!["second", "first"]);
    }
}
