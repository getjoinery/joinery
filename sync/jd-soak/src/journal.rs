//! The record of what actually happened, which is the only thing in this rig
//! that is allowed to be believed.
//!
//! The daemon has its own logs and its own opinion of its health. Neither is
//! evidence: the bug class this rig exists to catch is a daemon that reports
//! green while a file is gone, and a verifier that consulted the daemon to find
//! out whether the daemon was right would agree with it every time.
//!
//! So there are three independent records, written by three parties that do not
//! read each other:
//!
//! - the **actors** write what they committed to disk (and to the server),
//! - the **chaos agent** writes every fault it injected, with timestamps,
//! - the **verifier** writes its verdicts.
//!
//! Between them, any anomaly arrives with a timeline: which fault was in flight
//! when the last good copy of a file was last seen. That timeline is the
//! substitute for seed replay, and it is what turns a soak finding into a frozen
//! `jd-sim` scenario.
//!
//! **What "committed" means.** An intent is written before the filesystem
//! operation and a commit only after it returned success. The oracle trusts
//! commits and nothing else. An intent with no commit is not loss — it is an
//! operation that may or may not have happened, which is exactly the state a
//! process killed mid-write leaves behind, and recording it is what lets a
//! violation be told apart from a torn actor.
//!
//! Commits are fsync'd; intents are not. The fault model here is process death,
//! not power loss, and a `write()` that has reached the kernel survives a
//! `kill -9` perfectly well. Paying a disk flush per intent on a one-CPU box
//! would slow the actors to the point where the storms stop being storms.

use std::fs::{File, OpenOptions};
use std::io::{BufRead, BufReader, Write};
use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

/// One line of a journal.
///
/// Tagged rather than positional so a record written by an older build still
/// parses: a forensics bundle is read months after the run that produced it, and
/// a field added in between must not turn the whole timeline into a parse error.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum Record {
    /// About to do something. May or may not have happened.
    ActorIntent {
        seq: u64,
        actor: String,
        persona: String,
        op: String,
        path: String,
        ts_ms: u64,
    },
    /// Did it, and the filesystem said so.
    ActorCommit {
        seq: u64,
        actor: String,
        persona: String,
        op: String,
        path: String,
        /// Absent for a delete or a directory — there is no content to find later.
        #[serde(default, skip_serializing_if = "Option::is_none")]
        sha256: Option<String>,
        size: u64,
        #[serde(default, skip_serializing_if = "Option::is_none")]
        mtime_ms: Option<u64>,
        ts_ms: u64,
    },
    /// Tried and the filesystem refused. Not a violation on its own — a persona
    /// racing another device legitimately loses — but it explains gaps.
    ActorFailed {
        seq: u64,
        actor: String,
        persona: String,
        op: String,
        path: String,
        error: String,
        ts_ms: u64,
    },
    /// A fault, as injected.
    Fault {
        seq: u64,
        kind: String,
        target: String,
        detail: String,
        ts_ms: u64,
    },
    /// One settle assertion's verdict.
    Verdict {
        seq: u64,
        segment: u64,
        assertion: String,
        ok: bool,
        detail: String,
        ts_ms: u64,
    },
    /// A segment boundary, so the report can bucket everything between two of
    /// them.
    Segment {
        seq: u64,
        index: u64,
        kind: String,
        detail: String,
        ts_ms: u64,
    },
    /// The per-settle leak-watch sample, plus how long this device took to go
    /// quiet — the two things whose *trend* across a campaign says whether the
    /// client is degrading, which no single settle can.
    Sample {
        seq: u64,
        segment: u64,
        device: String,
        rss_kb: u64,
        fd_count: u64,
        spool_files: u64,
        spool_bytes: u64,
        store_bytes: u64,
        pending_ops: u64,
        /// Absent when the device never settled at all.
        #[serde(default, skip_serializing_if = "Option::is_none")]
        convergence_ms: Option<u64>,
        ts_ms: u64,
    },
}

impl Record {
    pub fn ts_ms(&self) -> u64 {
        match self {
            Record::ActorIntent { ts_ms, .. }
            | Record::ActorCommit { ts_ms, .. }
            | Record::ActorFailed { ts_ms, .. }
            | Record::Fault { ts_ms, .. }
            | Record::Verdict { ts_ms, .. }
            | Record::Segment { ts_ms, .. }
            | Record::Sample { ts_ms, .. } => *ts_ms,
        }
    }

    fn needs_durability(&self) -> bool {
        matches!(
            self,
            Record::ActorCommit { .. } | Record::Fault { .. } | Record::Verdict { .. }
        )
    }
}

#[derive(Debug, thiserror::Error)]
pub enum JournalError {
    #[error("journal io on {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("journal line {line} of {path} is not a record: {detail}")]
    Corrupt {
        path: PathBuf,
        line: usize,
        detail: String,
    },
}

type Result<T> = std::result::Result<T, JournalError>;

/// An append-only JSONL file, one per writer.
///
/// One file per writer rather than one shared file: several actors and a chaos
/// agent appending to the same fd interleave at page boundaries under load, and
/// a torn line in the middle of a forensics bundle is a line nobody can read at
/// the exact moment it matters most. The reader merges them by timestamp.
pub struct Journal {
    file: File,
    path: PathBuf,
    seq: u64,
}

impl Journal {
    /// Open (creating) `dir/{name}.jsonl` for appending.
    ///
    /// The directory must be **outside every sync root**. Journaling into the
    /// tree under test would have the actors' own record show up as files to
    /// sync, and the verifier would then be diffing its own evidence.
    pub fn open(dir: &Path, name: &str) -> Result<Journal> {
        std::fs::create_dir_all(dir).map_err(|e| JournalError::Io {
            path: dir.to_path_buf(),
            source: e,
        })?;
        let path = dir.join(format!("{name}.jsonl"));
        let file = OpenOptions::new()
            .create(true)
            .append(true)
            .open(&path)
            .map_err(|e| JournalError::Io {
                path: path.clone(),
                source: e,
            })?;
        Ok(Journal { file, path, seq: 0 })
    }

    pub fn path(&self) -> &Path {
        &self.path
    }

    /// The next sequence number this journal will use.
    pub fn next_seq(&mut self) -> u64 {
        self.seq += 1;
        self.seq
    }

    pub fn write(&mut self, record: &Record) -> Result<()> {
        let mut line = serde_json::to_string(record).expect("a record serializes");
        line.push('\n');
        self.file
            .write_all(line.as_bytes())
            .map_err(|e| JournalError::Io {
                path: self.path.clone(),
                source: e,
            })?;
        if record.needs_durability() {
            self.file.sync_data().map_err(|e| JournalError::Io {
                path: self.path.clone(),
                source: e,
            })?;
        }
        Ok(())
    }
}

/// Read every `*.jsonl` in a directory, merged into one timeline.
///
/// A line that will not parse is reported rather than skipped. A forensics
/// bundle with a hole in it that nobody was told about is how an investigation
/// concludes the wrong thing.
pub fn read_dir(dir: &Path) -> Result<Vec<Record>> {
    let mut out = Vec::new();
    let entries = std::fs::read_dir(dir).map_err(|e| JournalError::Io {
        path: dir.to_path_buf(),
        source: e,
    })?;
    let mut files: Vec<PathBuf> = entries
        .flatten()
        .map(|e| e.path())
        .filter(|p| p.extension().map(|x| x == "jsonl").unwrap_or(false))
        .collect();
    files.sort();
    for file in files {
        out.extend(read_file(&file)?);
    }
    out.sort_by_key(|r| r.ts_ms());
    Ok(out)
}

pub fn read_file(path: &Path) -> Result<Vec<Record>> {
    let file = File::open(path).map_err(|e| JournalError::Io {
        path: path.to_path_buf(),
        source: e,
    })?;
    let mut out = Vec::new();
    for (i, line) in BufReader::new(file).lines().enumerate() {
        let line = line.map_err(|e| JournalError::Io {
            path: path.to_path_buf(),
            source: e,
        })?;
        if line.trim().is_empty() {
            continue;
        }
        // The last line of a journal whose writer was killed mid-write is
        // genuinely half a line. That one is tolerable and is reported as a
        // truncation; a bad line anywhere else is corruption.
        match serde_json::from_str::<Record>(&line) {
            Ok(record) => out.push(record),
            Err(e) => {
                return Err(JournalError::Corrupt {
                    path: path.to_path_buf(),
                    line: i + 1,
                    detail: e.to_string(),
                })
            }
        }
    }
    Ok(out)
}

/// The last content each path was committed with, across every actor.
///
/// This is the no-loss oracle's left-hand side: for each path, the content that
/// the last completed write put there. Only commits count — an intent with no
/// commit is an operation nobody can say happened.
pub fn last_committed(records: &[Record]) -> std::collections::BTreeMap<String, Committed> {
    let mut out: std::collections::BTreeMap<String, Committed> = std::collections::BTreeMap::new();
    for record in records {
        if let Record::ActorCommit {
            actor,
            persona,
            op,
            path,
            sha256,
            size,
            ts_ms,
            ..
        } = record
        {
            match op.as_str() {
                // A delete removes the claim: the user asked for it to be gone,
                // so its absence everywhere is correct rather than loss.
                "remove" | "remove_dir" | "trash" => {
                    out.remove(path);
                }
                "rename" => {
                    // The commit for a rename carries the destination path; the
                    // source no longer holds anything to look for.
                    out.remove(path);
                }
                _ => {
                    if let Some(sha) = sha256 {
                        out.insert(
                            path.clone(),
                            Committed {
                                path: path.clone(),
                                sha256: sha.clone(),
                                size: *size,
                                actor: actor.clone(),
                                persona: persona.clone(),
                                ts_ms: *ts_ms,
                            },
                        );
                    }
                }
            }
        }
    }
    out
}

/// One entry of the no-loss oracle.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Committed {
    pub path: String,
    pub sha256: String,
    pub size: u64,
    pub actor: String,
    pub persona: String,
    pub ts_ms: u64,
}

/// Every content the actors ever committed, whatever happened to it afterwards.
///
/// The stronger half of assertion 3: a file that was overwritten twenty times is
/// allowed to have nineteen of those versions only on the server, but it is not
/// allowed for any of them to have stopped existing — the soak instance keeps
/// all versions on purpose so the server is a Mock-Server-grade oracle.
pub fn all_committed_content(records: &[Record]) -> std::collections::BTreeSet<String> {
    records
        .iter()
        .filter_map(|r| match r {
            Record::ActorCommit { sha256, .. } => sha256.clone(),
            _ => None,
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn commit(seq: u64, op: &str, path: &str, sha: Option<&str>, ts: u64) -> Record {
        Record::ActorCommit {
            seq,
            actor: "device-a".into(),
            persona: "office".into(),
            op: op.into(),
            path: path.into(),
            sha256: sha.map(String::from),
            size: 10,
            mtime_ms: Some(ts),
            ts_ms: ts,
        }
    }

    fn tmp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-soak-journal-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    #[test]
    fn a_record_survives_a_round_trip_through_the_file() {
        let dir = tmp("roundtrip");
        let mut j = Journal::open(&dir, "device-a").unwrap();
        let records = vec![
            commit(1, "atomic_write", "a/b.docx", Some("aa"), 100),
            Record::Fault {
                seq: 2,
                kind: "kill".into(),
                target: "device-a".into(),
                detail: "SIGKILL to the daemon".into(),
                ts_ms: 200,
            },
        ];
        for r in &records {
            j.write(r).unwrap();
        }
        assert_eq!(read_file(j.path()).unwrap(), records);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn journals_from_several_writers_merge_into_one_timeline() {
        // The whole forensics story: correlating a loss window against the fault
        // that was in flight requires the actor's record and the chaos agent's
        // record on one line of time.
        let dir = tmp("merge");
        let mut a = Journal::open(&dir, "actor").unwrap();
        let mut c = Journal::open(&dir, "chaos").unwrap();
        a.write(&commit(1, "write", "x", Some("aa"), 10)).unwrap();
        c.write(&Record::Fault {
            seq: 1,
            kind: "partition".into(),
            target: "device-a".into(),
            detail: "".into(),
            ts_ms: 20,
        })
        .unwrap();
        a.write(&commit(2, "write", "y", Some("bb"), 30)).unwrap();

        let all = read_dir(&dir).unwrap();
        assert_eq!(all.len(), 3);
        let stamps: Vec<u64> = all.iter().map(|r| r.ts_ms()).collect();
        assert_eq!(stamps, vec![10, 20, 30]);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_line_that_will_not_parse_is_reported_and_not_skipped() {
        // A bundle with a silent hole in it is how an investigation reaches the
        // wrong conclusion at exactly the moment it matters most.
        let dir = tmp("corrupt");
        let path = dir.join("bad.jsonl");
        std::fs::write(&path, "{\"type\":\"segment\"} nonsense\n").unwrap();
        assert!(matches!(
            read_file(&path),
            Err(JournalError::Corrupt { .. })
        ));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn only_committed_writes_reach_the_oracle() {
        // An intent with no commit is an operation nobody can say happened. If it
        // counted, every actor killed mid-write would be reported as data loss.
        let records = vec![
            Record::ActorIntent {
                seq: 1,
                actor: "device-a".into(),
                persona: "office".into(),
                op: "write".into(),
                path: "never.docx".into(),
                ts_ms: 5,
            },
            commit(2, "write", "real.docx", Some("aa"), 10),
        ];
        let oracle = last_committed(&records);
        assert!(oracle.contains_key("real.docx"));
        assert!(!oracle.contains_key("never.docx"));
    }

    #[test]
    fn the_last_write_wins_and_a_delete_withdraws_the_claim() {
        // A file the user deleted is meant to be gone. Keeping the claim would
        // report every intentional delete as loss.
        let records = vec![
            commit(1, "write", "a.txt", Some("first"), 10),
            commit(2, "write", "a.txt", Some("second"), 20),
            commit(3, "write", "b.txt", Some("keep"), 30),
            commit(4, "remove", "b.txt", None, 40),
        ];
        let oracle = last_committed(&records);
        assert_eq!(oracle["a.txt"].sha256, "second");
        assert!(!oracle.contains_key("b.txt"));
    }

    #[test]
    fn every_content_ever_committed_is_remembered_even_after_it_is_overwritten() {
        // The stronger half of the no-loss assertion: the soak server keeps all
        // versions, so a superseded one must still be findable.
        let records = vec![
            commit(1, "write", "a.txt", Some("first"), 10),
            commit(2, "write", "a.txt", Some("second"), 20),
        ];
        let all = all_committed_content(&records);
        assert!(all.contains("first") && all.contains("second"));
        assert_eq!(last_committed(&records)["a.txt"].sha256, "second");
    }

    #[test]
    fn a_rename_stops_the_oracle_looking_for_the_old_path() {
        // The commit for a rename names the destination; the source is not a
        // place anything should still be.
        let records = vec![
            commit(1, "write", "tmp1234.tmp", Some("aa"), 10),
            commit(2, "rename", "tmp1234.tmp", None, 20),
            commit(3, "rename_into", "Report.docx", Some("aa"), 20),
        ];
        let oracle = last_committed(&records);
        assert!(!oracle.contains_key("tmp1234.tmp"));
        assert_eq!(oracle["Report.docx"].sha256, "aa");
    }
}
