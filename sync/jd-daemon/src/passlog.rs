//! The pass journal: one line per pass of what it saw and decided
//! (`jd_core::trace::PassTrace`), for the soak devices.
//!
//! Off unless `JOINERY_DRIVE_PASS_JOURNAL` is set: `1` writes
//! `<logs>/passes.jsonl`, anything else is the path to write. File names are
//! user data, so no user's client ever writes it by default. Directories are
//! written in full on the first line of every file and after a feed reset,
//! and otherwise only where a binding changed; a pass that found and did
//! nothing is one short line, and a pass with the sync folder unavailable
//! says so. Rotated at 64 MB, keeping the one before, so the minutes
//! before a failure judged at a settle are still there when it is found.

use std::collections::BTreeMap;
use std::io::Write;
use std::path::{Path, PathBuf};

use jd_core::trace::PassTrace;
use serde_json::json;

pub const ENV: &str = "JOINERY_DRIVE_PASS_JOURNAL";
const ROTATE_AT: u64 = 64 * 1024 * 1024;

pub struct PassJournal {
    path: PathBuf,
    passes: u64,
    dirs: BTreeMap<String, String>,
    /// The next line opens a file: it lists every directory, so no file
    /// depends on the one before it.
    opens_a_file: bool,
}

impl PassJournal {
    /// The journal the environment asks for, if it asks.
    pub fn from_env(logs: &Path) -> Option<PassJournal> {
        let value = std::env::var(ENV).ok().filter(|v| !v.is_empty())?;
        let path = if value == "1" { logs.join("passes.jsonl") } else { PathBuf::from(value) };
        Some(PassJournal { path, passes: 0, dirs: BTreeMap::new(), opens_a_file: true })
    }

    pub fn path(&self) -> &Path {
        &self.path
    }

    /// The line for one pass. Directories as they changed since the last
    /// line: every one on the first pass and after a reset, else only those
    /// added, rebound or gone.
    pub fn line(&mut self, t: &PassTrace) -> serde_json::Value {
        self.passes += 1;
        let now: BTreeMap<String, String> = t.dirs.iter().cloned().collect();
        let full = std::mem::replace(&mut self.opens_a_file, false) || t.reset;
        let mut dirs: Vec<(String, String)> = Vec::new();
        for (path, bound) in &now {
            if full || self.dirs.get(path) != Some(bound) {
                dirs.push((path.clone(), bound.clone()));
            }
        }
        if !full {
            for path in self.dirs.keys() {
                if !now.contains_key(path) {
                    dirs.push((path.clone(), "gone".into()));
                }
            }
        }
        self.dirs = now;
        let timing = json!({
            "start": t.started_ms,
            "scan_ms": t.scan_ended_ms.saturating_sub(t.started_ms),
            "round_ms": t.round_ended_ms.saturating_sub(t.started_ms),
            "end_ms": t.ended_ms.saturating_sub(t.started_ms),
        });
        if t.is_quiet() && dirs.is_empty() {
            return json!({ "pass": self.passes, "t": timing, "quiet": true });
        }
        json!({
            "pass": self.passes,
            "t": timing,
            "reset": t.reset,
            "stranded": t.stranded,
            "remote": t.remote,
            "merges": t.merges,
            "unsyncable": t.unsyncable,
            "recovered": t.recovered,
            "dirs": dirs,
            "dirs_full": full,
            "folders": t.folders,
            "verdicts": t.verdicts,
            "skips": t.skips,
            "plan": t.plan,
            "ops": t.ops,
        })
    }

    /// Append one pass. A journal that cannot be written is not a reason to
    /// stop syncing; it says so once on stderr and carries on.
    pub fn write(&mut self, t: &PassTrace) {
        self.rotate();
        let line = self.line(t);
        self.append(&line);
    }

    /// A pass that found the sync folder unavailable did nothing and has no
    /// trace; it is still a pass, so the record does not read as a daemon
    /// that stopped.
    pub fn write_unavailable(&mut self) {
        self.rotate();
        self.passes += 1;
        let line = json!({ "pass": self.passes, "root_unavailable": true });
        self.append(&line);
    }

    /// Before the line is built, so a new file's first line lists every
    /// directory.
    fn rotate(&mut self) {
        if std::fs::metadata(&self.path).is_ok_and(|m| m.len() > ROTATE_AT) {
            let mut old = self.path.clone().into_os_string();
            old.push(".1");
            if std::fs::rename(&self.path, old).is_ok() {
                self.opens_a_file = true;
            }
        }
    }

    fn append(&mut self, line: &serde_json::Value) {
        let written = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(&self.path)
            .and_then(|mut f| writeln!(f, "{line}"));
        if let Err(e) = written {
            if self.passes == 1 {
                eprintln!("pass journal: cannot write {}: {e}", self.path.display());
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn journal() -> PassJournal {
        PassJournal { path: PathBuf::from("/nonexistent"), passes: 0, dirs: BTreeMap::new(), opens_a_file: true }
    }

    #[test]
    fn directories_are_written_in_full_once_then_as_they_change() {
        let mut j = journal();
        let mut t = PassTrace::started(1);
        t.dirs = vec![("A".into(), "folder:1".into()), ("B".into(), "folder:2".into())];
        let first = j.line(&t);
        assert_eq!(first["dirs"].as_array().unwrap().len(), 2);
        assert_eq!(first["dirs_full"], true);
        // Nothing changed and nothing done: one short line.
        let second = j.line(&t);
        assert_eq!(second["quiet"], true);
        // B rebound, C new, A gone.
        t.dirs = vec![("B".into(), "unbound".into()), ("C".into(), "folder:3".into())];
        let third = j.line(&t);
        let dirs: Vec<(String, String)> = serde_json::from_value(third["dirs"].clone()).unwrap();
        assert_eq!(
            dirs,
            vec![("B".into(), "unbound".into()), ("C".into(), "folder:3".into()), ("A".into(), "gone".into())]
        );
        // A reset writes them all again.
        t.reset = true;
        assert_eq!(j.line(&t)["dirs"].as_array().unwrap().len(), 2);
        // So does the first line of a new file after a rotation.
        t.reset = false;
        j.opens_a_file = true;
        assert_eq!(j.line(&t)["dirs_full"], true);
        assert_eq!(j.line(&t)["quiet"], true);
    }

    #[test]
    fn a_rotation_starts_the_new_file_with_every_directory() {
        let dir = std::env::temp_dir().join(format!("jd-passlog-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("passes.jsonl");
        let mut j = PassJournal { path: path.clone(), passes: 0, dirs: BTreeMap::new(), opens_a_file: true };
        let mut t = PassTrace::started(1);
        t.dirs = vec![("A".into(), "folder:1".into())];
        t.skips = vec![("file:2".into(), "pass.rs:1".into())];
        j.write(&t);
        // Over the cap: the next write rotates first and lists A again.
        std::fs::write(&path, vec![b'x'; (ROTATE_AT + 1) as usize]).unwrap();
        j.write(&t);
        let fresh = std::fs::read_to_string(&path).unwrap();
        let first: serde_json::Value = serde_json::from_str(fresh.lines().next().unwrap()).unwrap();
        assert_eq!(first["dirs_full"], true, "{fresh}");
        assert!(dir.join("passes.jsonl.1").exists());
        // An unavailable root is a pass too.
        j.write_unavailable();
        let last: serde_json::Value = serde_json::from_str(std::fs::read_to_string(&path).unwrap().lines().last().unwrap()).unwrap();
        assert_eq!((last["pass"].as_u64(), last["root_unavailable"].as_bool()), (Some(3), Some(true)));
        let _ = std::fs::remove_dir_all(&dir);
    }
}
