//! What the watcher tells us, and how much of it to believe.
//!
//! A filesystem event stream is a **hint**, never a source of truth. It drops
//! events under load, it reports a queue overflow and gives up, it fires four
//! times for one save because the application wrote a temp file and renamed it
//! twice. An engine that treated it as truth would miss changes silently, which
//! is the failure mode users never forgive because there is nothing to notice.
//!
//! So this layer does exactly two things. It **coalesces** — a path that fired
//! nine times in a second is one dirty path, examined once, after things go
//! quiet. And it **degrades honestly** — when the stream says it lost events,
//! that does not mark anything dirty, it raises a flag saying the whole tree
//! needs rescanning. The truth is always the filesystem itself; this only ever
//! narrows down where to look.

use std::collections::HashMap;
use std::path::{Path, PathBuf};

/// How long a path must go quiet before it is worth examining.
///
/// Saving a document is not one event — an application writes a temp file,
/// fsyncs, renames over the original, sometimes twice. Reacting to the first
/// event would read a half-written file; waiting for quiet reads the finished
/// one.
pub const DEFAULT_QUIET_PERIOD_MS: u64 = 2_000;

/// A path that has changed, and what the watcher thought happened.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Hint {
    /// Content or metadata changed.
    Modified,
    /// Appeared.
    Created,
    /// Went away.
    Removed,
    /// Moved from or to this path.
    Renamed,
    /// The backend could not say. Treated exactly like the others: something
    /// here is worth a look.
    Unknown,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DirtyPath {
    pub path: PathBuf,
    pub hint: Hint,
    /// When this path last saw an event.
    pub last_event_ms: u64,
}

/// The accumulated set of places worth looking, with the debounce applied.
///
/// Time is passed in rather than read from a clock, so a simulated run
/// reproduces exactly and a test does not have to sleep.
#[derive(Debug, Default)]
pub struct DirtySet {
    paths: HashMap<PathBuf, DirtyPath>,
    quiet_period_ms: u64,
    rescan_needed: bool,
    /// Where a rescan should start when one is needed. Empty means the whole
    /// root.
    rescan_roots: Vec<PathBuf>,
}

impl DirtySet {
    pub fn new(quiet_period_ms: u64) -> DirtySet {
        DirtySet {
            paths: HashMap::new(),
            quiet_period_ms,
            rescan_needed: false,
            rescan_roots: Vec::new(),
        }
    }

    pub fn with_default_quiet_period() -> DirtySet {
        DirtySet::new(DEFAULT_QUIET_PERIOD_MS)
    }

    /// Note that something happened at a path.
    ///
    /// Repeated events on one path collapse into a single entry whose timer
    /// restarts each time — which is what turns a save storm into one look at
    /// one file, taken once the storm is over.
    pub fn mark(&mut self, path: &Path, hint: Hint, now_ms: u64) {
        // The engine's own spool and swap files are not tree content, and
        // reacting to them would be reacting to ourselves.
        if let Some(name) = path.file_name().and_then(|n| n.to_str()) {
            if crate::names::is_internal(name) {
                return;
            }
        }
        let entry = self.paths.entry(path.to_path_buf()).or_insert(DirtyPath {
            path: path.to_path_buf(),
            hint,
            last_event_ms: now_ms,
        });
        entry.last_event_ms = now_ms;
        // A removal outranks whatever came before it: if a path ended up gone,
        // that is the fact worth carrying, however it got there.
        if hint == Hint::Removed {
            entry.hint = Hint::Removed;
        } else if entry.hint == Hint::Unknown {
            entry.hint = hint;
        }
    }

    /// The watcher lost events, or could not keep up.
    ///
    /// Deliberately does NOT mark anything dirty: we do not know what was
    /// missed, so pretending to know would be worse than admitting we do not.
    /// A rescan of the affected subtree is the only honest response.
    pub fn mark_overflow(&mut self, root: Option<&Path>) {
        self.rescan_needed = true;
        if let Some(r) = root {
            if !self.rescan_roots.iter().any(|p| p == r) {
                self.rescan_roots.push(r.to_path_buf());
            }
        } else {
            // Whole-tree rescan requested; individual roots no longer matter.
            self.rescan_roots.clear();
        }
    }

    pub fn rescan_needed(&self) -> bool {
        self.rescan_needed
    }

    /// Where a rescan should look. Empty means everywhere.
    pub fn rescan_roots(&self) -> &[PathBuf] {
        &self.rescan_roots
    }

    pub fn clear_rescan(&mut self) {
        self.rescan_needed = false;
        self.rescan_roots.clear();
    }

    pub fn len(&self) -> usize {
        self.paths.len()
    }

    pub fn is_empty(&self) -> bool {
        self.paths.is_empty()
    }

    /// Paths that have been quiet long enough to examine, removed from the set.
    ///
    /// A path still receiving events stays here — examining a file mid-write is
    /// how you upload half a document.
    pub fn take_settled(&mut self, now_ms: u64) -> Vec<DirtyPath> {
        let quiet = self.quiet_period_ms;
        let settled: Vec<PathBuf> = self
            .paths
            .iter()
            .filter(|(_, d)| now_ms.saturating_sub(d.last_event_ms) >= quiet)
            .map(|(p, _)| p.clone())
            .collect();

        let mut out: Vec<DirtyPath> = settled
            .iter()
            .filter_map(|p| self.paths.remove(p))
            .collect();
        // Shallowest first, so a folder is examined before the files inside it.
        out.sort_by(|a, b| {
            a.path
                .components()
                .count()
                .cmp(&b.path.components().count())
                .then_with(|| a.path.cmp(&b.path))
        });
        out
    }

    /// Everything pending, settled or not. Used when the engine is stopping and
    /// wants to persist what it knows rather than lose it.
    pub fn drain_all(&mut self) -> Vec<DirtyPath> {
        let mut out: Vec<DirtyPath> = self.paths.drain().map(|(_, d)| d).collect();
        out.sort_by(|a, b| a.path.cmp(&b.path));
        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn p(s: &str) -> PathBuf {
        PathBuf::from(s)
    }

    #[test]
    fn a_path_is_not_examined_until_it_goes_quiet() {
        let mut d = DirtySet::new(2000);
        d.mark(&p("/root/a.txt"), Hint::Modified, 1000);

        // Still inside the quiet period: leave it alone, it may be mid-write.
        assert!(d.take_settled(2000).is_empty());
        assert_eq!(d.len(), 1);

        assert_eq!(d.take_settled(3000).len(), 1);
        assert!(d.is_empty());
    }

    #[test]
    fn a_save_storm_collapses_into_one_look_at_one_file() {
        // What a word processor actually does: write, sync, rename, touch.
        let mut d = DirtySet::new(2000);
        for t in [100, 150, 180, 220, 260] {
            d.mark(&p("/root/Report.docx"), Hint::Modified, t);
        }
        assert_eq!(d.len(), 1, "one file, however many events it fired");

        // The timer restarts on every event, so quiet is measured from the last
        // one — not the first.
        assert!(d.take_settled(2100).is_empty());
        assert_eq!(d.take_settled(2260).len(), 1);
    }

    #[test]
    fn a_removal_outranks_earlier_hints_for_the_same_path() {
        let mut d = DirtySet::new(0);
        d.mark(&p("/root/a.txt"), Hint::Modified, 10);
        d.mark(&p("/root/a.txt"), Hint::Removed, 20);
        let settled = d.take_settled(100);
        assert_eq!(settled[0].hint, Hint::Removed);
    }

    #[test]
    fn an_unknown_hint_is_upgraded_by_a_later_specific_one() {
        let mut d = DirtySet::new(0);
        d.mark(&p("/root/a.txt"), Hint::Unknown, 10);
        d.mark(&p("/root/a.txt"), Hint::Created, 20);
        assert_eq!(d.take_settled(100)[0].hint, Hint::Created);
    }

    #[test]
    fn overflow_asks_for_a_rescan_and_marks_nothing_dirty() {
        // The stream admitted it lost events. We do not know which, so guessing
        // would be worse than saying so.
        let mut d = DirtySet::new(0);
        d.mark_overflow(Some(&p("/root/deep")));

        assert!(d.rescan_needed());
        assert!(d.is_empty(), "overflow must not invent dirty paths");
        assert_eq!(d.rescan_roots(), &[p("/root/deep")]);
    }

    #[test]
    fn a_whole_tree_overflow_supersedes_subtree_requests() {
        let mut d = DirtySet::new(0);
        d.mark_overflow(Some(&p("/root/a")));
        d.mark_overflow(None);
        assert!(d.rescan_needed());
        assert!(
            d.rescan_roots().is_empty(),
            "empty means everywhere, which already covers every subtree"
        );
    }

    #[test]
    fn repeated_overflows_on_one_subtree_do_not_pile_up() {
        let mut d = DirtySet::new(0);
        d.mark_overflow(Some(&p("/root/a")));
        d.mark_overflow(Some(&p("/root/a")));
        d.mark_overflow(Some(&p("/root/b")));
        assert_eq!(d.rescan_roots().len(), 2);
    }

    #[test]
    fn clearing_a_rescan_does_not_disturb_pending_paths() {
        let mut d = DirtySet::new(0);
        d.mark(&p("/root/a.txt"), Hint::Modified, 10);
        d.mark_overflow(None);
        d.clear_rescan();
        assert!(!d.rescan_needed());
        assert_eq!(d.len(), 1);
    }

    #[test]
    fn the_engines_own_spool_files_are_ignored() {
        // Reacting to our own download temporaries would be reacting to
        // ourselves, and every download would look like a local creation.
        let mut d = DirtySet::new(0);
        d.mark(&p("/root/.jd-tmp-1234"), Hint::Created, 10);
        d.mark(&p("/root/.jd-swap-abcd"), Hint::Renamed, 10);
        d.mark(&p("/root/real.txt"), Hint::Created, 10);
        assert_eq!(d.len(), 1);
        assert_eq!(d.take_settled(100)[0].path, p("/root/real.txt"));
    }

    #[test]
    fn settled_paths_come_out_shallowest_first() {
        // A folder has to be examined before the files inside it, or the files
        // have nowhere to belong.
        let mut d = DirtySet::new(0);
        d.mark(&p("/root/a/b/deep.txt"), Hint::Modified, 1);
        d.mark(&p("/root/a"), Hint::Created, 1);
        d.mark(&p("/root/a/b"), Hint::Created, 1);

        let order: Vec<PathBuf> = d.take_settled(100).into_iter().map(|x| x.path).collect();
        assert_eq!(
            order,
            vec![p("/root/a"), p("/root/a/b"), p("/root/a/b/deep.txt")]
        );
    }

    #[test]
    fn a_busy_path_does_not_hold_up_a_quiet_one() {
        let mut d = DirtySet::new(2000);
        d.mark(&p("/root/quiet.txt"), Hint::Modified, 100);
        d.mark(&p("/root/busy.txt"), Hint::Modified, 100);
        d.mark(&p("/root/busy.txt"), Hint::Modified, 2500);

        let settled = d.take_settled(2600);
        assert_eq!(settled.len(), 1);
        assert_eq!(settled[0].path, p("/root/quiet.txt"));
        assert_eq!(d.len(), 1, "the busy one is still settling");
    }

    #[test]
    fn draining_takes_everything_including_unsettled_paths() {
        // Used on shutdown: better to persist what we know than lose it.
        let mut d = DirtySet::new(10_000);
        d.mark(&p("/root/a.txt"), Hint::Modified, 100);
        d.mark(&p("/root/b.txt"), Hint::Modified, 100);
        assert!(d.take_settled(200).is_empty());
        assert_eq!(d.drain_all().len(), 2);
        assert!(d.is_empty());
    }
}
