//! A soak line's mailbox: an ordinary account on the soak instance that a line
//! with no way in (a person's own computer) sends its results to, and that the
//! dev box reads them back from.
//!
//! Through the Drive API, with the client's own upload and download, so there
//! is one author of the wire format. Each message is a new file at the
//! account's root under a name the sender makes unique; nothing is ever
//! overwritten, and reading never removes anything.

use std::io::Cursor;
use std::path::{Path, PathBuf};

use jd_proto::{DriveApi, ProtoError, UploadParams};
use serde_json::{json, Value};

use crate::actor::hash_bytes;
use crate::server::walk;

/// Leave `bytes` in the mailbox as a new file called `name`. Returns the file's
/// id on the server.
pub fn send(api: &dyn DriveApi, name: &str, bytes: &[u8]) -> Result<i64, String> {
    check_name(name)?;
    let mut params = UploadParams::plain(
        name.to_string(),
        None,
        bytes.len() as u64,
        hash_bytes(bytes),
    );
    params.idempotency_key = Some(jd_proto::Client::new_idempotency_key());
    let outcome = api
        .upload(&params, &mut Cursor::new(bytes.to_vec()))
        .map_err(|e| format!("could not send {name}: {e}"))?;
    outcome
        .file
        .get("id")
        .and_then(Value::as_i64)
        .ok_or_else(|| format!("{name} was sent and the server answered without its id"))
}

/// Copy every message at the mailbox's root that `into` does not hold yet.
/// Returns the names copied, in the server's order.
///
/// A message is written beside its final name and renamed into place once its
/// bytes are checked against the server's hash, so a fetch cut short leaves no
/// message that looks whole and is not. A message whose name would put it
/// outside `into` is passed over with a warning: the sender is a computer
/// nobody can reach, and one bad name must not hide every message after it.
pub fn fetch(api: &dyn DriveApi, into: &Path) -> Result<Vec<String>, String> {
    std::fs::create_dir_all(into).map_err(|e| format!("{}: {e}", into.display()))?;
    let tree = walk(api).map_err(|e| format!("could not list the mailbox: {e}"))?;
    let mut fetched = Vec::new();
    for file in tree.files.values() {
        if file.deleted || file.parent_id.is_some() {
            continue;
        }
        if let Err(why) = check_name(&file.name) {
            eprintln!("warning: passed over file {}: {why}", file.id);
            continue;
        }
        if into.join(&file.name).exists() {
            continue;
        }
        let item = api
            .action(
                "drive_stat",
                json!({ "entities": [{ "entity_type": "file", "entity_id": file.id }], "urls": true }),
            )
            .map_err(|e| format!("could not ask for {}: {e}", file.name))?;
        let url = item
            .get("items")
            .and_then(Value::as_array)
            .and_then(|a| a.first())
            .and_then(|i| i.get("download_url"))
            .and_then(Value::as_str)
            .ok_or_else(|| format!("the server gave no link for {}", file.name))?
            .to_string();
        let mut bytes = Vec::new();
        api.download(&url, 0, &mut bytes)
            .map_err(|e: ProtoError| format!("could not fetch {}: {e}", file.name))?;
        if file.sha256.as_deref() != Some(hash_bytes(&bytes).as_str()) {
            return Err(format!("{} arrived with other bytes than the server holds", file.name));
        }
        let part: PathBuf = into.join(format!("{}.part", file.name));
        std::fs::write(&part, &bytes).map_err(|e| format!("{}: {e}", part.display()))?;
        std::fs::rename(&part, into.join(&file.name))
            .map_err(|e| format!("{}: {e}", part.display()))?;
        fetched.push(file.name.clone());
    }
    Ok(fetched)
}

/// A message's name is one file name: it is written into a folder on the
/// reading side, so a separator or a dot-name would put it somewhere else.
fn check_name(name: &str) -> Result<(), String> {
    if name.is_empty() || name.starts_with('.') || name.contains(['/', '\\', ':']) {
        return Err(format!("{name:?} is not a name a message can have"));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use jd_proto::{ReadSeek, UploadOutcome};
    use std::collections::BTreeMap;
    use std::io::Write;
    use std::sync::Mutex;

    /// A server holding files at its root, by id: (name, bytes, folder).
    #[derive(Default)]
    struct Mailbox {
        files: Mutex<BTreeMap<i64, (String, Vec<u8>, Option<i64>)>>,
        downloads: std::sync::atomic::AtomicU32,
    }

    impl DriveApi for Mailbox {
        fn action(&self, name: &str, body: Value) -> jd_proto::Result<Value> {
            let files = self.files.lock().unwrap();
            match name {
                "drive_index" => Ok(json!({
                    "items": files.iter().map(|(id, (n, b, folder))| json!({
                        "entity_type": "file", "id": id, "name": n, "folder_id": folder,
                        "content_sha256": hash_bytes(b), "size": b.len(),
                    })).collect::<Vec<_>>(),
                    "done": true,
                })),
                "drive_stat" => {
                    let id = body["entities"][0]["entity_id"].as_i64().unwrap();
                    Ok(json!({ "items": [{ "id": id, "download_url": format!("mem://{id}") }] }))
                }
                other => panic!("unexpected action {other}"),
            }
        }
        fn action_idempotent(&self, name: &str, body: Value, _key: &str) -> jd_proto::Result<Value> {
            self.action(name, body)
        }
        fn upload(&self, params: &UploadParams, reader: &mut dyn ReadSeek) -> jd_proto::Result<UploadOutcome> {
            let mut bytes = Vec::new();
            reader.read_to_end(&mut bytes).unwrap();
            assert_eq!(hash_bytes(&bytes), params.sha256, "the hash sent is the bytes'");
            assert!(params.idempotency_key.is_some(), "a retried send must not make two files");
            let mut files = self.files.lock().unwrap();
            let id = 100 + files.len() as i64;
            files.insert(id, (params.name.clone(), bytes, params.folder_id));
            Ok(UploadOutcome { deduped: false, file: json!({ "id": id }) })
        }
        fn download(&self, url: &str, _from: u64, out: &mut dyn Write) -> jd_proto::Result<u64> {
            self.downloads.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
            let id: i64 = url.trim_start_matches("mem://").parse().unwrap();
            let bytes = self.files.lock().unwrap()[&id].1.clone();
            out.write_all(&bytes).unwrap();
            Ok(bytes.len() as u64)
        }
    }

    fn scratch(tag: &str) -> PathBuf {
        let d = std::env::temp_dir().join(format!("jd-soak-mailbox-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&d);
        d
    }

    #[test]
    fn a_message_sent_is_fetched_once_with_its_bytes() {
        let box_ = Mailbox::default();
        send(&box_, "pc-run1.txt", b"run 1 | green").unwrap();
        send(&box_, "pc-run2.txt", b"run 2 | FAIL").unwrap();
        let dir = scratch("once");

        let first = fetch(&box_, &dir).unwrap();
        assert_eq!(first, vec!["pc-run1.txt", "pc-run2.txt"]);
        assert_eq!(std::fs::read(dir.join("pc-run2.txt")).unwrap(), b"run 2 | FAIL");

        // What is here already is not fetched again; a new message is.
        send(&box_, "pc-run3.txt", b"run 3 | green").unwrap();
        assert_eq!(fetch(&box_, &dir).unwrap(), vec!["pc-run3.txt"]);
        assert_eq!(box_.downloads.load(std::sync::atomic::Ordering::Relaxed), 3);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn only_the_mailboxs_root_is_read() {
        let box_ = Mailbox::default();
        box_.files.lock().unwrap().insert(7, ("elsewhere.txt".into(), b"x".to_vec(), Some(3)));
        let dir = scratch("root");
        assert!(fetch(&box_, &dir).unwrap().is_empty());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_name_that_would_leave_the_folder_is_refused_both_ways() {
        let box_ = Mailbox::default();
        for bad in ["../up.txt", "a/b.txt", "a\\b.txt", ".hidden", "c:x", ""] {
            assert!(send(&box_, bad, b"x").is_err(), "{bad:?} was sent");
        }
        box_.files.lock().unwrap().insert(9, ("..\\up.txt".into(), b"x".to_vec(), None));
        box_.files.lock().unwrap().insert(10, ("after.txt".into(), b"y".to_vec(), None));
        let dir = scratch("names");
        // Passed over, and the message after it still arrives.
        assert_eq!(fetch(&box_, &dir).unwrap(), vec!["after.txt"]);
        assert!(!dir.join("..\\up.txt").exists());
        assert!(!dir.parent().unwrap().join("up.txt").exists());
        let _ = std::fs::remove_dir_all(&dir);
    }
}
