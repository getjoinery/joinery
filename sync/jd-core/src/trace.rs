//! A record of what one pass saw and decided, for the soak devices.
//!
//! A soak failure is judged at a settle, long after the passes that made it,
//! and a device's store keeps only where each record ended up. Two failures
//! could not be traced from that alone: a folder frozen for a minute and a
//! half, then one burst of operations, one of them wrong (soak Mac run 38,
//! rig run 1805). This is the evidence those needed: per pass, what the
//! server said, what naming decided, how each directory on the disk was
//! bound, what the scan read, what each record was NOT given and where, what
//! the round planned, and how every operation ended.
//!
//! Collected only when the caller asks (`Context::trace`), and built from
//! values the pass already holds: every method here takes values, never the
//! store or the disk, so collecting it cannot change what a pass reads or
//! does. File names are user data, so nothing writes it unless a person
//! running a soak device turns it on.

use crate::model::EntityId;

/// What one pass saw and decided.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct PassTrace {
    /// When the pass began, its scan ended, its round ended, and it ended
    /// (ms): whether many passes decided nothing or one pass took long.
    pub started_ms: u64,
    pub scan_ended_ms: u64,
    pub round_ended_ms: u64,
    pub ended_ms: u64,
    /// The feed could not be resumed and the index was walked instead.
    pub reset: bool,
    /// Records with no way back to the root, swept this pass.
    pub stranded: usize,
    /// What the server said this pass: entity, then its placement, head and
    /// whether it is deleted.
    pub remote: Vec<(String, String)>,
    /// A provisional record folded into the server's record of the same
    /// name: (provisional, server).
    pub merges: Vec<(String, String)>,
    /// Records naming could not materialize here, and why (with the rival).
    pub unsyncable: Vec<(String, String)>,
    /// Records that could again.
    pub recovered: Vec<String>,
    /// Each directory on the disk and how it is bound: a folder id, held, or
    /// unbound.
    pub dirs: Vec<(String, String)>,
    /// What the folder scan read: folders moved, found under a directory with
    /// no identity yet, and directories held.
    pub folders: Vec<(String, String)>,
    /// The scan's reading of each file that is not "unchanged", and every
    /// file found that nothing tracks ("created": as the scan found it, before
    /// any adoption or mint later in the pass).
    pub verdicts: Vec<(String, String)>,
    /// What was declined, where: a record or a path, and the source line of
    /// the `continue` that passed it by.
    pub skips: Vec<(String, String)>,
    /// What the round planned: entity, action.
    pub plan: Vec<(String, String)>,
    /// Every operation run: op id, kind, entity, outcome.
    pub ops: Vec<(i64, String, String, String)>,
}

/// An entity as the trace names it: `file:123`, `folder:-4`.
pub fn name(id: EntityId) -> String {
    format!("{}:{}", id.entity_type, id.server_id)
}

impl PassTrace {
    pub fn started(now_ms: u64) -> PassTrace {
        PassTrace { started_ms: now_ms, ..PassTrace::default() }
    }

    /// A record passed by at `line` of the pass.
    pub fn skip(&mut self, id: EntityId, line: u32) {
        self.skips.push((name(id), format!("pass.rs:{line}")));
    }

    /// A path passed by at `line`, for something with no record yet.
    pub fn skip_path(&mut self, path: &str, line: u32, why: &str) {
        self.skips.push((path.to_string(), format!("pass.rs:{line} {why}")));
    }

    /// Did this pass find and do nothing? Then it is written as one short line.
    pub fn is_quiet(&self) -> bool {
        self.remote.is_empty()
            && self.merges.is_empty()
            && self.unsyncable.is_empty()
            && self.recovered.is_empty()
            && self.folders.is_empty()
            && self.stranded == 0
            && self.verdicts.is_empty()
            && self.skips.is_empty()
            && self.plan.is_empty()
            && self.ops.is_empty()
            && !self.reset
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn an_entity_is_named_by_its_kind_and_id() {
        assert_eq!(name(EntityId::folder(-4)), "folder:-4");
        assert_eq!(name(EntityId::file(123)), "file:123");
    }

    #[test]
    fn a_pass_that_found_nothing_is_quiet() {
        let mut t = PassTrace::started(5);
        t.dirs.push(("Docs".into(), "folder:1".into()));
        assert!(t.is_quiet(), "the directory listing alone is not news");
        t.skip(EntityId::file(2), 900);
        assert!(!t.is_quiet());
        assert_eq!(t.skips[0], ("file:2".into(), "pass.rs:900".into()));
        // Naming or the folder scan saying something is news on its own.
        for news in [
            PassTrace { unsyncable: vec![("file:3".into(), "CaseClash".into())], ..PassTrace::default() },
            PassTrace { recovered: vec!["file:3".into()], ..PassTrace::default() },
            PassTrace { folders: vec![("folder:4".into(), "moved".into())], ..PassTrace::default() },
            PassTrace { stranded: 1, ..PassTrace::default() },
        ] {
            assert!(!news.is_quiet(), "{news:?}");
        }
    }
}
