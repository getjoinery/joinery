//! `remote-user` — the person on the website, with no device of their own.
//!
//! Everything the other personas do arrives at a daemon as a local change. This
//! one arrives as a **remote delta**, which is the entirely different half of
//! the engine: the change feed, restore classification, move detection from the
//! server's side, and the whole question of what a device does about something
//! it did not do itself.
//!
//! It is also the only way to reach several states at all without a second
//! device sitting there being the other half of a race — and, from Phase C, the
//! only way to populate an encrypted folder while the devices still refuse to.
//!
//! Its journal records are the ordinary actor ones, so the no-loss oracle treats
//! a file uploaded through the API exactly as it treats one written to a disk.
//! That is the correct reading: the user put content somewhere and the system
//! promised to keep it. Where they were standing at the time is not the
//! system's business.

use std::io::Cursor;

use jd_proto::{DriveApi, ProtoError, UploadParams};
use serde_json::{json, Value};

use crate::actor::{hash_bytes, now_ms};
use crate::journal::{Journal, Record};
use crate::rng::{content_bytes, Rng};

/// What the remote user did.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum RemoteOp {
    CreateFolder { name: String },
    Upload { name: String, folder: Option<i64> },
    NewVersion { file_id: i64 },
    Rename { file_id: i64, to: String },
    Move { file_id: i64, folder: Option<i64> },
    Trash { file_id: i64 },
    Restore { file_id: i64 },
    RestoreVersion { file_id: i64 },
}

impl RemoteOp {
    pub fn kind(&self) -> &'static str {
        match self {
            RemoteOp::CreateFolder { .. } => "remote_folder",
            RemoteOp::Upload { .. } => "remote_upload",
            RemoteOp::NewVersion { .. } => "remote_version",
            RemoteOp::Rename { .. } => "remote_rename",
            RemoteOp::Move { .. } => "remote_move",
            RemoteOp::Trash { .. } => "remote_trash",
            RemoteOp::Restore { .. } => "remote_restore",
            RemoteOp::RestoreVersion { .. } => "remote_version_restore",
        }
    }
}

/// The remote actor's own memory of what it has made.
///
/// Deliberately its own rather than a re-read of the server: an actor that
/// re-derived its world from the thing under test would follow the server into
/// whatever wrong state it had reached, and the two would agree all the way
/// down.
#[derive(Debug, Default)]
pub struct Made {
    pub folders: Vec<(i64, String)>,
    pub files: Vec<(i64, String)>,
    pub trashed: Vec<i64>,
    pub versioned: Vec<i64>,
}

pub struct RemoteActor<'a> {
    api: &'a dyn DriveApi,
    rng: Rng,
    journal: Journal,
    made: Made,
    /// A folder every file goes into, so the remote user's work is not scattered
    /// through the personas' workspaces where a collision would be noise rather
    /// than a finding.
    home: Option<i64>,
    home_name: String,
    ops: u64,
}

impl<'a> RemoteActor<'a> {
    pub fn new(
        api: &'a dyn DriveApi,
        seed: u64,
        home_name: &str,
        journal_dir: &std::path::Path,
    ) -> Result<RemoteActor<'a>, crate::journal::JournalError> {
        Ok(RemoteActor {
            api,
            rng: Rng::new(seed),
            journal: Journal::open(journal_dir, "actor-remote-user")?,
            made: Made::default(),
            home: None,
            home_name: home_name.to_string(),
            ops: 0,
        })
    }

    pub fn ops_done(&self) -> u64 {
        self.ops
    }

    /// Pick something to do and do it.
    pub fn step(&mut self) -> Result<(), crate::journal::JournalError> {
        let op = self.choose();
        let seq = self.journal.next_seq();
        self.journal.write(&Record::ActorIntent {
            seq,
            actor: "remote-user".into(),
            persona: "remote-user".into(),
            op: op.kind().into(),
            path: describe(&op),
            ts_ms: now_ms(),
        })?;

        self.ops += 1;
        match self.perform(&op) {
            Ok(commits) => {
                for (path, sha, size) in commits {
                    let seq = self.journal.next_seq();
                    self.journal.write(&Record::ActorCommit {
                        seq,
                        actor: "remote-user".into(),
                        persona: "remote-user".into(),
                        op: op.kind().into(),
                        path,
                        sha256: sha,
                        size,
                        mtime_ms: None,
                        ts_ms: now_ms(),
                    })?;
                }
                Ok(())
            }
            Err(e) => {
                let seq = self.journal.next_seq();
                self.journal.write(&Record::ActorFailed {
                    seq,
                    actor: "remote-user".into(),
                    persona: "remote-user".into(),
                    op: op.kind().into(),
                    path: describe(&op),
                    error: e.to_string(),
                    ts_ms: now_ms(),
                })
            }
        }
    }

    fn choose(&mut self) -> RemoteOp {
        if self.home.is_none() {
            return RemoteOp::CreateFolder {
                name: self.home_name.clone(),
            };
        }
        let live: Vec<i64> = self
            .made
            .files
            .iter()
            .map(|(id, _)| *id)
            .filter(|id| !self.made.trashed.contains(id))
            .collect();
        if live.len() < 3 {
            let n = self.made.files.len() + 1;
            return RemoteOp::Upload {
                name: format!("web-{n:03}.txt"),
                folder: self.home,
            };
        }
        let file_id = live[self.rng.below(live.len())];
        match self.rng.below(10) {
            0 | 1 => RemoteOp::Upload {
                name: format!("web-{:03}.txt", self.made.files.len() + 1),
                folder: self.home,
            },
            2 | 3 => RemoteOp::NewVersion { file_id },
            4 => RemoteOp::Rename {
                file_id,
                to: format!("renamed-{}-{}.txt", file_id, self.rng.below(1000)),
            },
            5 => RemoteOp::Move {
                file_id,
                // Back to the drive root and then into the folder again, which
                // is a move a device has to follow in both directions.
                folder: if self.rng.chance(50) { None } else { self.home },
            },
            6 => RemoteOp::Trash { file_id },
            7 => match self.made.trashed.first().copied() {
                // A restore is the classification the engine is most likely to
                // get wrong — it looks exactly like a create at a path that used
                // to exist.
                Some(id) => RemoteOp::Restore { file_id: id },
                None => RemoteOp::Trash { file_id },
            },
            _ => match self.made.versioned.first().copied() {
                Some(id) => RemoteOp::RestoreVersion { file_id: id },
                None => RemoteOp::NewVersion { file_id },
            },
        }
    }

    /// Returns the `(path, sha256, size)` triples to journal as commits.
    fn perform(&mut self, op: &RemoteOp) -> Result<Vec<(String, Option<String>, u64)>, ProtoError> {
        match op {
            RemoteOp::CreateFolder { name } => {
                let answer = self.api.action_idempotent(
                    "drive_folder_create",
                    json!({ "name": name }),
                    &new_key(&mut self.rng),
                )?;
                let id = folder_id(&answer)?;
                self.made.folders.push((id, name.clone()));
                if self.home.is_none() {
                    self.home = Some(id);
                }
                Ok(vec![(name.clone(), None, 0)])
            }
            RemoteOp::Upload { name, folder } => {
                let seed = self.rng.next_u64();
                let size = self.rng.range(200, 200_000) as usize;
                let bytes = content_bytes(seed, size);
                let sha = hash_bytes(&bytes);
                let mut params =
                    UploadParams::plain(name.clone(), *folder, bytes.len() as u64, sha.clone());
                params.idempotency_key = Some(new_key(&mut self.rng));
                let outcome = self.api.upload(&params, &mut Cursor::new(bytes.clone()))?;
                let id = file_id(&outcome.file)?;
                self.made.files.push((id, name.clone()));
                Ok(vec![(
                    self.path_of(name, *folder),
                    Some(sha),
                    bytes.len() as u64,
                )])
            }
            RemoteOp::NewVersion { file_id: id } => {
                let name = self.name_of(*id);
                let seed = self.rng.next_u64();
                let size = self.rng.range(200, 200_000) as usize;
                let bytes = content_bytes(seed, size);
                let sha = hash_bytes(&bytes);
                let mut params =
                    UploadParams::plain(name.clone(), self.home, bytes.len() as u64, sha.clone());
                params.file_id = Some(*id);
                params.idempotency_key = Some(new_key(&mut self.rng));
                self.api.upload(&params, &mut Cursor::new(bytes.clone()))?;
                if !self.made.versioned.contains(id) {
                    self.made.versioned.push(*id);
                }
                Ok(vec![(
                    self.path_of(&name, self.home),
                    Some(sha),
                    bytes.len() as u64,
                )])
            }
            RemoteOp::Rename { file_id: id, to } => {
                self.api.action_idempotent(
                    "drive_rename",
                    json!({ "entity_type": "file", "entity_id": id, "name": to }),
                    &new_key(&mut self.rng),
                )?;
                let old = self.name_of(*id);
                for entry in self.made.files.iter_mut() {
                    if entry.0 == *id {
                        entry.1 = to.clone();
                    }
                }
                // The old path stops being a place anything should be, and the
                // new one carries the same content forward. Journaled as a pair
                // so the oracle follows the file rather than losing it.
                let home = self.home;
                Ok(vec![
                    (self.path_of(&old, home), None, 0),
                    (self.path_of(to, home), None, 0),
                ])
            }
            RemoteOp::Move {
                file_id: id,
                folder,
            } => {
                // The drive root is expressed by *omitting* parent_id, not by
                // sending null: the server reads an absent or non-positive
                // parent as the root, and a null would be an int that is not
                // one.
                let mut body = json!({ "entity_type": "file", "entity_id": id });
                if let Some(f) = folder {
                    body["parent_id"] = json!(f);
                }
                self.api
                    .action_idempotent("drive_move", body, &new_key(&mut self.rng))?;
                Ok(Vec::new())
            }
            RemoteOp::Trash { file_id: id } => {
                self.api.action_idempotent(
                    "drive_trash",
                    json!({ "entity_type": "file", "entity_id": id }),
                    &new_key(&mut self.rng),
                )?;
                if !self.made.trashed.contains(id) {
                    self.made.trashed.push(*id);
                }
                let name = self.name_of(*id);
                let home = self.home;
                // A trash withdraws the live claim. The content still has to be
                // findable — the server's trash is one of the legitimate places
                // — but no device is expected to hold it any more.
                Ok(vec![(self.path_of(&name, home), None, 0)])
            }
            RemoteOp::Restore { file_id: id } => {
                self.api.action_idempotent(
                    "drive_restore",
                    json!({ "entity_type": "file", "entity_id": id }),
                    &new_key(&mut self.rng),
                )?;
                self.made.trashed.retain(|t| t != id);
                Ok(Vec::new())
            }
            RemoteOp::RestoreVersion { file_id: id } => {
                let versions = self
                    .api
                    .action("drive_versions", json!({ "file_id": id }))?;
                let Some(version) = versions
                    .get("versions")
                    .and_then(Value::as_array)
                    .and_then(|list| list.last())
                    .and_then(|v| v.get("version_id"))
                    .and_then(Value::as_i64)
                else {
                    return Ok(Vec::new());
                };
                self.api.action_idempotent(
                    "drive_version_restore",
                    json!({ "file_id": id, "version_id": version }),
                    &new_key(&mut self.rng),
                )?;
                Ok(Vec::new())
            }
        }
    }

    fn name_of(&self, id: i64) -> String {
        self.made
            .files
            .iter()
            .find(|(f, _)| *f == id)
            .map(|(_, n)| n.clone())
            .unwrap_or_else(|| format!("file-{id}"))
    }

    /// The path a device will see this file at, which is what the oracle keys
    /// on — not the server's id.
    fn path_of(&self, name: &str, folder: Option<i64>) -> String {
        match folder {
            Some(id) => match self.made.folders.iter().find(|(f, _)| *f == id) {
                Some((_, folder_name)) => format!("{folder_name}/{name}"),
                None => name.to_string(),
            },
            None => name.to_string(),
        }
    }
}

fn describe(op: &RemoteOp) -> String {
    match op {
        RemoteOp::CreateFolder { name } => name.clone(),
        RemoteOp::Upload { name, .. } => name.clone(),
        RemoteOp::NewVersion { file_id }
        | RemoteOp::Trash { file_id }
        | RemoteOp::Restore { file_id }
        | RemoteOp::RestoreVersion { file_id }
        | RemoteOp::Move { file_id, .. } => format!("file {file_id}"),
        RemoteOp::Rename { file_id, to } => format!("file {file_id} -> {to}"),
    }
}

/// A fresh idempotency key. Every mutating call carries one — a retry after a
/// lost answer is otherwise a second file, which is the one upload failure a
/// user actually notices.
fn new_key(rng: &mut Rng) -> String {
    format!("{:016x}{:016x}", rng.next_u64(), rng.next_u64())
}

fn folder_id(answer: &Value) -> Result<i64, ProtoError> {
    answer
        .get("folder")
        .and_then(|f| f.get("id"))
        .and_then(Value::as_i64)
        .or_else(|| answer.get("id").and_then(Value::as_i64))
        .ok_or_else(|| ProtoError::Contract("folder create answered without an id".into()))
}

fn file_id(file: &Value) -> Result<i64, ProtoError> {
    file.get("id")
        .and_then(Value::as_i64)
        .ok_or_else(|| ProtoError::Contract("upload answered without a file id".into()))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cell::RefCell;
    use std::io::Write;

    /// A server that records what it was asked and answers plausibly.
    struct Spy {
        calls: RefCell<Vec<String>>,
        next_id: RefCell<i64>,
    }

    impl Spy {
        fn new() -> Spy {
            Spy {
                calls: RefCell::new(Vec::new()),
                next_id: RefCell::new(100),
            }
        }
        fn take_id(&self) -> i64 {
            let mut id = self.next_id.borrow_mut();
            *id += 1;
            *id
        }
        fn saw(&self, name: &str) -> bool {
            self.calls.borrow().iter().any(|c| c == name)
        }
    }

    impl DriveApi for Spy {
        fn action(&self, name: &str, _body: Value) -> Result<Value, ProtoError> {
            self.calls.borrow_mut().push(name.to_string());
            Ok(match name {
                "drive_folder_create" => json!({"folder": {"id": self.take_id()}}),
                "drive_versions" => json!({"versions": [{"version_id": 7}]}),
                _ => json!({"ok": true}),
            })
        }
        fn action_idempotent(
            &self,
            name: &str,
            body: Value,
            key: &str,
        ) -> Result<Value, ProtoError> {
            assert!(
                !key.is_empty(),
                "{name} was sent without an idempotency key"
            );
            self.action(name, body)
        }
        fn upload(
            &self,
            params: &UploadParams,
            reader: &mut dyn jd_proto::ReadSeek,
        ) -> Result<jd_proto::UploadOutcome, ProtoError> {
            self.calls.borrow_mut().push("upload".into());
            let mut sink = Vec::new();
            std::io::copy(reader, &mut sink).unwrap();
            assert_eq!(
                sink.len() as u64,
                params.size_bytes,
                "the reader did not yield the size the params promised"
            );
            assert_eq!(
                hash_bytes(&sink),
                params.sha256,
                "the bytes sent do not hash to the sha the params claimed"
            );
            Ok(jd_proto::UploadOutcome {
                deduped: false,
                file: json!({"id": self.take_id()}),
            })
        }
        fn download(&self, _u: &str, _f: u64, _o: &mut dyn Write) -> Result<u64, ProtoError> {
            unimplemented!()
        }
    }

    unsafe impl Sync for Spy {}
    unsafe impl Send for Spy {}

    fn dir(tag: &str) -> std::path::PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-soak-remote-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    #[test]
    fn the_first_thing_it_does_is_make_itself_somewhere_to_work() {
        let dir = dir("home");
        let api = Spy::new();
        let mut actor = RemoteActor::new(&api, 1, "Web", &dir).unwrap();
        actor.step().unwrap();
        assert!(api.saw("drive_folder_create"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_run_exercises_every_remote_delta_the_engine_has_to_classify() {
        // Upload, new version, rename, move, trash, restore and version restore
        // are seven different arrivals on the device side, and a rig that only
        // ever uploaded would be testing one of them.
        let dir = dir("coverage");
        let api = Spy::new();
        let mut actor = RemoteActor::new(&api, 8_675_309, "Web", &dir).unwrap();
        for _ in 0..400 {
            actor.step().unwrap();
        }
        for call in [
            "drive_folder_create",
            "upload",
            "drive_rename",
            "drive_move",
            "drive_trash",
            "drive_restore",
            "drive_version_restore",
        ] {
            assert!(api.saw(call), "{call} never happened in 400 steps");
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_upload_is_journaled_at_the_path_a_device_will_see_it_at() {
        // The oracle keys on paths, not server ids. A file uploaded into a
        // folder that was journaled at its bare name would be looked for at the
        // drive root and reported missing.
        let dir = dir("paths");
        let api = Spy::new();
        let mut actor = RemoteActor::new(&api, 5, "Web", &dir).unwrap();
        for _ in 0..6 {
            actor.step().unwrap();
        }
        let oracle = crate::journal::last_committed(&crate::journal::read_dir(&dir).unwrap());
        assert!(
            oracle.keys().any(|k| k.starts_with("Web/")),
            "nothing was journaled under the remote user's folder: {:?}",
            oracle.keys().collect::<Vec<_>>()
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_trash_withdraws_the_live_claim_on_that_path() {
        // Otherwise every file the remote user deleted is reported as loss.
        let dir = dir("trash");
        let api = Spy::new();
        let mut actor = RemoteActor::new(&api, 8_675_309, "Web", &dir).unwrap();
        for _ in 0..400 {
            actor.step().unwrap();
        }
        let records = crate::journal::read_dir(&dir).unwrap();
        let trashed = records
            .iter()
            .any(|r| matches!(r, Record::ActorCommit { op, .. } if op == "remote_trash"));
        assert!(trashed);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_rename_moves_the_claim_to_the_new_path() {
        let dir = dir("rename");
        let api = Spy::new();
        let mut actor = RemoteActor::new(&api, 8_675_309, "Web", &dir).unwrap();
        for _ in 0..400 {
            actor.step().unwrap();
        }
        let records = crate::journal::read_dir(&dir).unwrap();
        let renames: Vec<&Record> = records
            .iter()
            .filter(|r| matches!(r, Record::ActorCommit { op, .. } if op == "remote_rename"))
            .collect();
        assert!(
            renames.len() >= 2 && renames.len().is_multiple_of(2),
            "renames are journaled in pairs (old path away, new path in): {}",
            renames.len()
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_server_that_refuses_is_recorded_and_the_actor_keeps_going() {
        // The remote actor races the devices for the same entities, so refusals
        // are ordinary. Stopping on one would silence the remote lane for the
        // rest of the segment.
        struct Refuses;
        impl DriveApi for Refuses {
            fn action(&self, _n: &str, _b: Value) -> Result<Value, ProtoError> {
                Err(ProtoError::Contract("nope".into()))
            }
            fn action_idempotent(
                &self,
                _n: &str,
                _b: Value,
                _k: &str,
            ) -> Result<Value, ProtoError> {
                Err(ProtoError::Contract("nope".into()))
            }
            fn upload(
                &self,
                _p: &UploadParams,
                _r: &mut dyn jd_proto::ReadSeek,
            ) -> Result<jd_proto::UploadOutcome, ProtoError> {
                Err(ProtoError::Contract("nope".into()))
            }
            fn download(&self, _u: &str, _f: u64, _o: &mut dyn Write) -> Result<u64, ProtoError> {
                unimplemented!()
            }
        }
        let dir = dir("refused");
        let api = Refuses;
        let mut actor = RemoteActor::new(&api, 3, "Web", &dir).unwrap();
        for _ in 0..5 {
            actor.step().unwrap();
        }
        let records = crate::journal::read_dir(&dir).unwrap();
        assert!(records
            .iter()
            .any(|r| matches!(r, Record::ActorFailed { .. })));
        assert!(crate::journal::last_committed(&records).is_empty());
        let _ = std::fs::remove_dir_all(&dir);
    }
}
