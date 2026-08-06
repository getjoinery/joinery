//! The half that touches a real disk.
//!
//! [`crate::persona`] decides what a program would do; this applies it and
//! writes down what happened. Keeping the two apart is what makes the personas
//! testable without a filesystem, and it puts every rule about *evidence* in one
//! place instead of once per persona.
//!
//! Three rules, and all three exist because of how a violation gets
//! investigated months later:
//!
//! - **An intent goes down before the operation and a commit only after it
//!   returned success.** The oracle believes commits. An intent with no commit
//!   is not loss, it is an operation nobody can say happened — which is exactly
//!   what a process killed mid-write leaves behind.
//! - **A refusal is recorded, not swallowed.** Two actors racing for the same
//!   path means one of them loses, routinely; a rig that hid those would leave
//!   gaps in the timeline that look like the actor stopping for no reason.
//! - **Nothing is written outside the workspace.** Paths from a persona are
//!   relative and are resolved against the actor's own root; anything that
//!   escapes is refused rather than performed, because the thing immediately
//!   above a sync root in this rig is the journal.

use std::io::Write;
use std::path::{Path, PathBuf};
use std::time::{SystemTime, UNIX_EPOCH};

use sha2::{Digest, Sha256};

use crate::journal::{Journal, Record};
use crate::persona::{FsOp, Persona};
use crate::rng::{content_bytes, Rng};

/// One persona running against one directory.
pub struct Actor {
    /// What this actor is called in the journal — normally `device-a/office`.
    pub label: String,
    /// The device this actor is typing on, for correlating against faults.
    pub device: String,
    root: PathBuf,
    persona: Box<dyn Persona>,
    rng: Rng,
    journal: Journal,
    ops: u64,
}

#[derive(Debug, thiserror::Error)]
pub enum ActorError {
    #[error("{0}")]
    Journal(#[from] crate::journal::JournalError),
    #[error("cannot prepare the actor workspace {path}: {source}")]
    Workspace {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
}

impl Actor {
    /// `root` is the actor's workspace — a directory *inside* a device's sync
    /// root. `journal_dir` must be outside every sync root.
    pub fn new(
        device: &str,
        root: &Path,
        persona: Box<dyn Persona>,
        seed: u64,
        journal_dir: &Path,
    ) -> Result<Actor, ActorError> {
        std::fs::create_dir_all(root).map_err(|e| ActorError::Workspace {
            path: root.to_path_buf(),
            source: e,
        })?;
        let label = format!("{device}/{}", persona.name());
        let journal = Journal::open(journal_dir, &format!("actor-{}-{}", device, persona.name()))?;
        Ok(Actor {
            label,
            device: device.to_string(),
            root: root.to_path_buf(),
            persona,
            rng: Rng::new(seed),
            journal,
            ops: 0,
        })
    }

    pub fn ops_done(&self) -> u64 {
        self.ops
    }

    /// One burst. Returns how many operations committed.
    pub fn step(&mut self) -> Result<usize, ActorError> {
        let burst = self.persona.step(&mut self.rng);
        let mut committed = 0;
        for op in burst {
            if self.apply(&op)? {
                committed += 1;
            }
            self.ops += 1;
        }
        Ok(committed)
    }

    /// Apply one operation, journaling the attempt and its outcome.
    fn apply(&mut self, op: &FsOp) -> Result<bool, ActorError> {
        let persona = self.persona.name().to_string();
        let seq = self.journal.next_seq();
        let subject = op.subject().to_string();
        self.journal.write(&Record::ActorIntent {
            seq,
            actor: self.label.clone(),
            persona: persona.clone(),
            op: op.kind().into(),
            path: subject.clone(),
            ts_ms: now_ms(),
        })?;

        match self.perform(op) {
            Ok(results) => {
                for done in results {
                    let seq = self.journal.next_seq();
                    self.journal.write(&Record::ActorCommit {
                        seq,
                        actor: self.label.clone(),
                        persona: persona.clone(),
                        op: done.op.clone(),
                        path: done.path,
                        sha256: done.sha256,
                        size: done.size,
                        mtime_ms: done.mtime_ms,
                        ts_ms: now_ms(),
                    })?;
                }
                Ok(true)
            }
            Err(e) => {
                let seq = self.journal.next_seq();
                self.journal.write(&Record::ActorFailed {
                    seq,
                    actor: self.label.clone(),
                    persona,
                    op: op.kind().into(),
                    path: subject,
                    error: e.to_string(),
                    ts_ms: now_ms(),
                })?;
                Ok(false)
            }
        }
    }

    /// Do the thing. Every returned [`Done`] becomes one commit record.
    fn perform(&self, op: &FsOp) -> std::io::Result<Vec<Done>> {
        match op {
            FsOp::Mkdir { path } => {
                let target = self.resolve(path)?;
                std::fs::create_dir_all(&target)?;
                Ok(vec![Done::dir(op.kind(), path)])
            }
            FsOp::Write { path, seed, size } => {
                let target = self.resolve(path)?;
                self.parent_of(&target)?;
                let bytes = content_bytes(*seed, *size);
                let mut file = std::fs::File::create(&target)?;
                file.write_all(&bytes)?;
                file.flush()?;
                Ok(vec![Done::content(op.kind(), path, &bytes, &target)])
            }
            FsOp::AtomicWrite {
                path,
                temp,
                seed,
                size,
            } => {
                let final_path = self.resolve(path)?;
                let temp_path = self.resolve(temp)?;
                self.parent_of(&final_path)?;
                let bytes = content_bytes(*seed, *size);
                let mut file = std::fs::File::create(&temp_path)?;
                file.write_all(&bytes)?;
                file.flush()?;
                drop(file);
                std::fs::rename(&temp_path, &final_path)?;
                // One commit, for the destination. The temp file never existed
                // as far as anything downstream is concerned, and journaling it
                // would put a path in the oracle that is *supposed* to vanish.
                Ok(vec![Done::content(op.kind(), path, &bytes, &final_path)])
            }
            FsOp::Append { path, seed, size } => {
                let target = self.resolve(path)?;
                self.parent_of(&target)?;
                let mut file = std::fs::OpenOptions::new()
                    .create(true)
                    .append(true)
                    .open(&target)?;
                file.write_all(&content_bytes(*seed, *size))?;
                file.flush()?;
                drop(file);
                // Re-hashed rather than tracked incrementally: the file may have
                // been truncated by something else since, and an oracle entry
                // built from what we *think* is in there is an oracle that
                // reports loss the rig caused itself.
                let (sha, len) = hash_file(&target)?;
                Ok(vec![Done {
                    op: op.kind().into(),
                    path: path.clone(),
                    sha256: Some(sha),
                    size: len,
                    mtime_ms: mtime_ms(&target),
                }])
            }
            FsOp::Rename { from, to } => {
                let source = self.resolve(from)?;
                let dest = self.resolve(to)?;
                self.parent_of(&dest)?;
                std::fs::rename(&source, &dest)?;
                let mut done = vec![Done::dir("rename", from)];
                // The destination carries the content forward, so it needs a
                // commit of its own or the oracle stops looking for bytes that
                // are still very much the user's.
                if dest.is_file() {
                    let (sha, len) = hash_file(&dest)?;
                    done.push(Done {
                        op: "rename_into".into(),
                        path: to.clone(),
                        sha256: Some(sha),
                        size: len,
                        mtime_ms: mtime_ms(&dest),
                    });
                } else {
                    done.push(Done::dir("rename_into", to));
                }
                Ok(done)
            }
            FsOp::Swap { a, b, via } => {
                let pa = self.resolve(a)?;
                let pb = self.resolve(b)?;
                let pv = self.resolve(via)?;
                std::fs::rename(&pa, &pv)?;
                std::fs::rename(&pb, &pa)?;
                std::fs::rename(&pv, &pb)?;
                let mut done = Vec::new();
                for (name, path) in [(a, &pa), (b, &pb)] {
                    if path.is_file() {
                        let (sha, len) = hash_file(path)?;
                        done.push(Done {
                            op: "swap".into(),
                            path: name.clone(),
                            sha256: Some(sha),
                            size: len,
                            mtime_ms: mtime_ms(path),
                        });
                    }
                }
                Ok(done)
            }
            FsOp::Remove { path } => {
                let target = self.resolve(path)?;
                std::fs::remove_file(&target)?;
                Ok(vec![Done::dir(op.kind(), path)])
            }
            FsOp::RemoveDir { path } => {
                let target = self.resolve(path)?;
                std::fs::remove_dir_all(&target)?;
                Ok(vec![Done::dir(op.kind(), path)])
            }
            FsOp::Touch {
                path,
                mtime_delta_secs,
            } => {
                let target = self.resolve(path)?;
                let file = std::fs::File::options().write(true).open(&target)?;
                let base = SystemTime::now();
                let when = if *mtime_delta_secs >= 0 {
                    base + std::time::Duration::from_secs(*mtime_delta_secs as u64)
                } else {
                    base - std::time::Duration::from_secs(mtime_delta_secs.unsigned_abs())
                };
                file.set_times(std::fs::FileTimes::new().set_modified(when))?;
                let (sha, len) = hash_file(&target)?;
                Ok(vec![Done {
                    op: op.kind().into(),
                    path: path.clone(),
                    sha256: Some(sha),
                    size: len,
                    mtime_ms: mtime_ms(&target),
                }])
            }
        }
    }

    /// Resolve a persona's relative path against this actor's workspace.
    ///
    /// Refused rather than performed if it escapes. The directory immediately
    /// above a sync root in this rig holds the journals, and an actor that wrote
    /// over its own evidence would produce a run nobody could investigate.
    fn resolve(&self, relative: &str) -> std::io::Result<PathBuf> {
        if relative.is_empty()
            || relative.starts_with('/')
            || relative.split('/').any(|c| c == ".." || c == ".")
        {
            return Err(std::io::Error::new(
                std::io::ErrorKind::InvalidInput,
                format!("path escapes the actor workspace: {relative}"),
            ));
        }
        Ok(self.root.join(relative))
    }

    fn parent_of(&self, path: &Path) -> std::io::Result<()> {
        if let Some(parent) = path.parent() {
            std::fs::create_dir_all(parent)?;
        }
        Ok(())
    }
}

/// One committed operation, as it will be journaled.
struct Done {
    op: String,
    path: String,
    sha256: Option<String>,
    size: u64,
    mtime_ms: Option<u64>,
}

impl Done {
    fn dir(op: &str, path: &str) -> Done {
        Done {
            op: op.into(),
            path: path.into(),
            sha256: None,
            size: 0,
            mtime_ms: None,
        }
    }

    fn content(op: &str, path: &str, bytes: &[u8], on_disk: &Path) -> Done {
        Done {
            op: op.into(),
            path: path.into(),
            sha256: Some(hash_bytes(bytes)),
            size: bytes.len() as u64,
            mtime_ms: mtime_ms(on_disk),
        }
    }
}

pub fn hash_bytes(bytes: &[u8]) -> String {
    let digest = Sha256::digest(bytes);
    digest.iter().map(|b| format!("{b:02x}")).collect()
}

/// `(sha256, size)` of a file on disk.
pub fn hash_file(path: &Path) -> std::io::Result<(String, u64)> {
    let file = std::fs::File::open(path)?;
    jd_proto::sha256_reader(file)
}

pub fn mtime_ms(path: &Path) -> Option<u64> {
    std::fs::metadata(path)
        .ok()?
        .modified()
        .ok()?
        .duration_since(UNIX_EPOCH)
        .ok()
        .map(|d| d.as_millis() as u64)
}

pub fn now_ms() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as u64)
        .unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::journal;
    use crate::persona::Office;

    struct Scripted(Vec<FsOp>);

    impl Persona for Scripted {
        fn name(&self) -> &'static str {
            "scripted"
        }
        fn step(&mut self, _rng: &mut Rng) -> Vec<FsOp> {
            if self.0.is_empty() {
                Vec::new()
            } else {
                vec![self.0.remove(0)]
            }
        }
    }

    struct Bed {
        dir: PathBuf,
        root: PathBuf,
        journal: PathBuf,
    }

    impl Bed {
        fn new(tag: &str) -> Bed {
            let dir = std::env::temp_dir().join(format!(
                "jd-soak-actor-{}-{}-{:?}",
                tag,
                std::process::id(),
                std::thread::current().id()
            ));
            let _ = std::fs::remove_dir_all(&dir);
            let root = dir.join("root/work");
            let journal = dir.join("journal");
            std::fs::create_dir_all(&root).unwrap();
            std::fs::create_dir_all(&journal).unwrap();
            Bed { dir, root, journal }
        }

        fn actor(&self, ops: Vec<FsOp>) -> Actor {
            Actor::new(
                "device-a",
                &self.root,
                Box::new(Scripted(ops)),
                1,
                &self.journal,
            )
            .unwrap()
        }

        fn records(&self) -> Vec<Record> {
            journal::read_dir(&self.journal).unwrap()
        }
    }

    impl Drop for Bed {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.dir);
        }
    }

    #[test]
    fn a_write_lands_on_disk_and_is_journaled_with_the_hash_of_what_landed() {
        let bed = Bed::new("write");
        let mut actor = bed.actor(vec![FsOp::Write {
            path: "a/b.txt".into(),
            seed: 5,
            size: 1234,
        }]);
        actor.step().unwrap();

        let on_disk = std::fs::read(bed.root.join("a/b.txt")).unwrap();
        assert_eq!(on_disk.len(), 1234);
        let oracle = journal::last_committed(&bed.records());
        assert_eq!(oracle["a/b.txt"].sha256, hash_bytes(&on_disk));
        assert_eq!(oracle["a/b.txt"].size, 1234);
    }

    #[test]
    fn an_atomic_write_leaves_no_temp_file_and_journals_only_the_destination() {
        // Journaling the temp path would put an entry in the oracle that is
        // *supposed* to vanish, and every safe save would then read as loss.
        let bed = Bed::new("atomic");
        let mut actor = bed.actor(vec![FsOp::AtomicWrite {
            path: "Report.docx".into(),
            temp: "tmp0001.tmp".into(),
            seed: 9,
            size: 500,
        }]);
        actor.step().unwrap();

        assert!(bed.root.join("Report.docx").exists());
        assert!(!bed.root.join("tmp0001.tmp").exists());
        let oracle = journal::last_committed(&bed.records());
        assert!(oracle.contains_key("Report.docx"));
        assert!(!oracle.contains_key("tmp0001.tmp"));
    }

    #[test]
    fn an_intent_is_written_before_the_operation_and_a_commit_only_after_it() {
        // The ordering is the whole forensic value: a kill between the two is
        // distinguishable from an operation that never started.
        let bed = Bed::new("order");
        let mut actor = bed.actor(vec![FsOp::Write {
            path: "x.txt".into(),
            seed: 1,
            size: 10,
        }]);
        actor.step().unwrap();
        let records = bed.records();
        assert!(matches!(records[0], Record::ActorIntent { .. }));
        assert!(matches!(records[1], Record::ActorCommit { .. }));
    }

    #[test]
    fn an_operation_the_filesystem_refuses_is_recorded_rather_than_swallowed() {
        // Two actors racing for a path means one of them loses, routinely. A rig
        // that hid those would leave gaps that look like the actor stopping.
        let bed = Bed::new("refused");
        let mut actor = bed.actor(vec![FsOp::Remove {
            path: "never-existed.txt".into(),
        }]);
        actor.step().unwrap();
        let records = bed.records();
        assert!(
            records
                .iter()
                .any(|r| matches!(r, Record::ActorFailed { .. })),
            "a refusal produced no record"
        );
        assert!(journal::last_committed(&records).is_empty());
    }

    #[test]
    fn a_path_that_escapes_the_workspace_is_refused_and_nothing_is_written() {
        // The directory above a sync root holds the journals. An actor that got
        // out would overwrite its own evidence.
        let bed = Bed::new("escape");
        for path in ["../outside.txt", "/etc/passwd", "a/../../b.txt"] {
            let mut actor = bed.actor(vec![FsOp::Write {
                path: path.into(),
                seed: 1,
                size: 4,
            }]);
            actor.step().unwrap();
        }
        assert!(!bed.dir.join("root/outside.txt").exists());
        assert!(!bed.dir.join("outside.txt").exists());
        let records = bed.records();
        assert_eq!(
            records
                .iter()
                .filter(|r| matches!(r, Record::ActorFailed { .. }))
                .count(),
            3
        );
        assert!(journal::last_committed(&records).is_empty());
    }

    #[test]
    fn a_rename_moves_the_content_claim_to_the_new_path() {
        let bed = Bed::new("rename");
        let mut actor = bed.actor(vec![
            FsOp::Write {
                path: "old.txt".into(),
                seed: 3,
                size: 64,
            },
            FsOp::Rename {
                from: "old.txt".into(),
                to: "new.txt".into(),
            },
        ]);
        actor.step().unwrap();
        actor.step().unwrap();

        let oracle = journal::last_committed(&bed.records());
        assert!(!oracle.contains_key("old.txt"));
        let content = std::fs::read(bed.root.join("new.txt")).unwrap();
        assert_eq!(oracle["new.txt"].sha256, hash_bytes(&content));
    }

    #[test]
    fn a_swap_exchanges_two_names_and_leaves_no_temporary_behind() {
        let bed = Bed::new("swap");
        let mut actor = bed.actor(vec![
            FsOp::Write {
                path: "a.dat".into(),
                seed: 1,
                size: 100,
            },
            FsOp::Write {
                path: "b.dat".into(),
                seed: 2,
                size: 200,
            },
            FsOp::Swap {
                a: "a.dat".into(),
                b: "b.dat".into(),
                via: ".swap-1.tmp".into(),
            },
        ]);
        for _ in 0..3 {
            actor.step().unwrap();
        }
        assert_eq!(
            std::fs::metadata(bed.root.join("a.dat")).unwrap().len(),
            200
        );
        assert_eq!(
            std::fs::metadata(bed.root.join("b.dat")).unwrap().len(),
            100
        );
        assert!(!bed.root.join(".swap-1.tmp").exists());

        let oracle = journal::last_committed(&bed.records());
        assert_eq!(oracle["a.dat"].size, 200);
        assert_eq!(oracle["b.dat"].size, 100);
    }

    #[test]
    fn an_append_rehashes_the_whole_file_rather_than_trusting_its_own_bookkeeping() {
        // The file may have been truncated by something else since. An oracle
        // built from what the actor *thinks* is in there reports loss the rig
        // caused itself.
        let bed = Bed::new("append");
        let mut actor = bed.actor(vec![
            FsOp::Append {
                path: "log.txt".into(),
                seed: 1,
                size: 100,
            },
            FsOp::Append {
                path: "log.txt".into(),
                seed: 2,
                size: 50,
            },
        ]);
        actor.step().unwrap();
        actor.step().unwrap();

        let content = std::fs::read(bed.root.join("log.txt")).unwrap();
        assert_eq!(content.len(), 150);
        let oracle = journal::last_committed(&bed.records());
        assert_eq!(oracle["log.txt"].sha256, hash_bytes(&content));
        assert_eq!(oracle["log.txt"].size, 150);
    }

    #[test]
    fn a_real_persona_run_leaves_the_disk_and_the_oracle_agreeing() {
        // The end-to-end property the whole no-loss assertion rests on: every
        // path the oracle still claims must be on the disk with exactly the
        // content it claims, when nothing has interfered.
        let bed = Bed::new("agree");
        let mut actor = Actor::new(
            "device-a",
            &bed.root,
            Box::new(Office::new()),
            77,
            &bed.journal,
        )
        .unwrap();
        for _ in 0..40 {
            actor.step().unwrap();
        }

        let oracle = journal::last_committed(&bed.records());
        assert!(oracle.len() > 1, "the run produced almost nothing");
        for (path, claim) in &oracle {
            let on_disk = bed.root.join(path);
            assert!(on_disk.exists(), "{path} is claimed but not on disk");
            let (sha, size) = hash_file(&on_disk).unwrap();
            assert_eq!(&sha, &claim.sha256, "{path} content differs from the claim");
            assert_eq!(size, claim.size);
        }
    }
}
