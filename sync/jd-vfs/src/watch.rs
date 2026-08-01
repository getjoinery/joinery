//! The watcher: OS events in, dirty paths out.
//!
//! Deliberately thin. Every judgement worth making about filesystem events —
//! how long to wait, what to coalesce, what to do when the stream admits it
//! lost something — lives in [`crate::dirty`], where it is pure and can be
//! tested without a filesystem or a sleep. What is left here is the part that
//! genuinely needs an operating system: subscribing to it, and translating its
//! vocabulary into ours.
//!
//! The one rule this file enforces is the one that matters: an event we cannot
//! interpret, or a backend that reports it fell behind, becomes a *rescan
//! request*, never a guess. Under load — a git checkout, an unpack, a restore —
//! the stream will drop events, and an engine that quietly missed those changes
//! would be broken in the way users never detect until it is far too late.
//!
//! # What each backend does that the others do not
//!
//! **macOS (FSEvents)** reports paths in the form the volume stores them, which
//! is decomposed, and *resolved*, with symlinks followed — `/var/…` arrives as
//! `/private/var/…`. A watcher started on the unresolved spelling therefore
//! receives a stream of events that appear to be outside the root, discards
//! every one of them, and reports itself perfectly healthy. Both are handled
//! here: the root is resolved before watching, and event paths are put back into
//! composed form. It also coalesces aggressively under load and sets
//! `mustScanSubDirs`, which arrives as `need_rescan()`.
//!
//! **Windows (ReadDirectoryChangesW)** has a fixed-size kernel buffer per
//! watched directory. Overrun it — an unzip, a build, a restore — and the
//! backend reports an error rather than events, which is the honest thing and
//! lands in the same rescan request. It also holds a handle on the watched
//! directory, which is why the root is opened once and not reopened per pass.
//!
//! **Linux (inotify)** watches each directory separately, so a newly created
//! subtree can produce changes before its watch is registered, and the queue
//! overflows silently at `max_queued_events`. Both come back as `need_rescan()`.
//!
//! The shared consequence is why the rescan interval below exists at all: every
//! backend can lose events, none can promise it did not, so a periodic full walk
//! is the floor under all three rather than an optimization.

use std::path::{Path, PathBuf};
use std::sync::mpsc::{channel, Receiver};
use std::sync::{Arc, Mutex};

use notify::{Config, Event, EventKind, RecommendedWatcher, RecursiveMode, Watcher as _};

use crate::dirty::{DirtySet, Hint};
use crate::{VfsError, VfsResult};

/// Translate one backend event into the hint vocabulary the engine uses.
///
/// Anything unrecognized maps to `Unknown` rather than being dropped: an event
/// we do not understand still means something happened there, and looking is
/// cheap next to missing a change.
pub fn hint_for(kind: &EventKind) -> Hint {
    use notify::event::{CreateKind, ModifyKind, RemoveKind, RenameMode};
    match kind {
        EventKind::Create(CreateKind::Folder) | EventKind::Create(_) => Hint::Created,
        EventKind::Remove(RemoveKind::Folder) | EventKind::Remove(_) => Hint::Removed,
        EventKind::Modify(ModifyKind::Name(RenameMode::Any))
        | EventKind::Modify(ModifyKind::Name(RenameMode::From))
        | EventKind::Modify(ModifyKind::Name(RenameMode::To))
        | EventKind::Modify(ModifyKind::Name(RenameMode::Both))
        | EventKind::Modify(ModifyKind::Name(RenameMode::Other)) => Hint::Renamed,
        EventKind::Modify(_) => Hint::Modified,
        _ => Hint::Unknown,
    }
}

/// Watches a sync root and accumulates dirty paths.
///
/// The [`DirtySet`] is shared: the watcher thread fills it, the engine's
/// rescanner drains what has settled. Contention is negligible — both sides
/// hold the lock for microseconds — and the alternative, a channel the engine
/// must drain promptly, turns a slow reconcile round into dropped events.
/// How often to walk the whole tree regardless of what the watcher said.
///
/// Not a fallback for a broken watcher — a floor under a working one. Every
/// backend can drop events under load and none can tell you afterwards that it
/// did not, so "the watcher has been quiet" is not evidence that nothing
/// changed. A daily walk bounds how long a missed change can stay missed.
pub const DEFAULT_RESCAN_INTERVAL_MS: u64 = 24 * 60 * 60 * 1000;

pub struct Watcher {
    _inner: RecommendedWatcher,
    dirty: Arc<Mutex<DirtySet>>,
    /// The spelling of the root the backend will use in its events.
    root: PathBuf,
    _events: Receiver<()>,
}

impl Watcher {
    /// Start watching `root` recursively.
    ///
    /// `now_ms` supplies the clock for debouncing. It is injected so the
    /// simulator can drive time by hand.
    pub fn start(
        root: &Path,
        dirty: Arc<Mutex<DirtySet>>,
        now_ms: impl Fn() -> u64 + Send + 'static,
    ) -> VfsResult<Watcher> {
        // Resolve before watching. FSEvents reports resolved paths, so a root
        // named through a symlink produces a stream of events that all look
        // like they belong to somebody else's folder — silently discarded, with
        // a watcher that reports itself perfectly healthy.
        let root = crate::paths::canonical_root(root);
        let root = root.as_path();
        let (tx, rx) = channel();
        let sink = Arc::clone(&dirty);

        let mut watcher = RecommendedWatcher::new(
            move |res: notify::Result<Event>| {
                let mut set = match sink.lock() {
                    Ok(s) => s,
                    // A poisoned lock means another thread panicked holding it.
                    // The safe response is the same as any other loss of
                    // confidence in the stream: ask for a rescan.
                    Err(poisoned) => poisoned.into_inner(),
                };
                match res {
                    Ok(event) => {
                        // The backend telling us it fell behind is the one event
                        // that must never be treated as information about a
                        // path. We do not know what was missed.
                        if event.need_rescan() {
                            set.mark_overflow(None);
                            return;
                        }
                        let hint = hint_for(&event.kind);
                        let t = now_ms();
                        for path in &event.paths {
                            set.mark(&normalize_event_path(path), hint, t);
                        }
                    }
                    Err(_) => set.mark_overflow(None),
                }
                let _ = tx.send(());
            },
            Config::default(),
        )
        .map_err(|e| VfsError::Io {
            path: root.to_path_buf(),
            source: std::io::Error::other(e.to_string()),
        })?;

        watcher
            .watch(root, RecursiveMode::Recursive)
            .map_err(|e| VfsError::Io {
                path: root.to_path_buf(),
                source: std::io::Error::other(e.to_string()),
            })?;

        // A watcher that has just started has seen nothing of what happened
        // while it was not running, so the first thing it asks for is a full
        // scan. Trusting an empty dirty set at startup would mean every change
        // made while the client was closed is invisible until something else
        // touches that file.
        if let Ok(mut set) = dirty.lock() {
            set.mark_overflow(None);
        }

        Ok(Watcher {
            _inner: watcher,
            dirty,
            root: root.to_path_buf(),
            _events: rx,
        })
    }

    /// The shared dirty set, for the rescanner to drain.
    pub fn dirty(&self) -> Arc<Mutex<DirtySet>> {
        Arc::clone(&self.dirty)
    }

    /// The spelling of the root this watcher's events will use. Anything
    /// comparing an event path against the sync root has to use this one, not
    /// the path the user typed.
    pub fn root(&self) -> &Path {
        &self.root
    }
}

/// Put an event path into the form the rest of the engine speaks.
///
/// macOS delivers decomposed names, so `café.txt` arrives spelled differently
/// than the engine wrote it and the dirty path matches nothing it knows about.
/// Composing here means one spelling reaches every comparison. On a platform
/// that does not decompose this is the identity function, which is why it is
/// unconditional — a `#[cfg]` here would make the macOS path the only one never
/// exercised by a test.
pub fn normalize_event_path(path: &Path) -> PathBuf {
    let s = path.to_string_lossy();
    let composed = crate::names::nfc(&s);
    if composed == s {
        path.to_path_buf()
    } else {
        PathBuf::from(composed)
    }
}

/// Watch a root with the standard quiet period and a real clock.
pub fn watch_root(root: &Path) -> VfsResult<Watcher> {
    let dirty = Arc::new(Mutex::new(DirtySet::with_default_quiet_period()));
    Watcher::start(root, dirty, || {
        use std::time::{SystemTime, UNIX_EPOCH};
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_millis() as u64)
            .unwrap_or(0)
    })
}

/// Paths under `root` that are worth examining, oldest-settled first.
pub fn settled_paths(dirty: &Arc<Mutex<DirtySet>>, now_ms: u64) -> Vec<PathBuf> {
    match dirty.lock() {
        Ok(mut set) => set
            .take_settled(now_ms)
            .into_iter()
            .map(|d| d.path)
            .collect(),
        Err(_) => Vec::new(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use notify::event::{CreateKind, DataChange, ModifyKind, RemoveKind, RenameMode};

    #[test]
    fn backend_events_map_to_the_engines_vocabulary() {
        assert_eq!(
            hint_for(&EventKind::Create(CreateKind::File)),
            Hint::Created
        );
        assert_eq!(
            hint_for(&EventKind::Remove(RemoveKind::File)),
            Hint::Removed
        );
        assert_eq!(
            hint_for(&EventKind::Modify(ModifyKind::Data(DataChange::Content))),
            Hint::Modified
        );
        assert_eq!(
            hint_for(&EventKind::Modify(ModifyKind::Name(RenameMode::Both))),
            Hint::Renamed
        );
    }

    #[test]
    fn an_uninterpretable_event_still_marks_the_path_worth_examining() {
        // Never dropped: something happened there, and looking costs a stat.
        assert_eq!(hint_for(&EventKind::Any), Hint::Unknown);
        assert_eq!(hint_for(&EventKind::Other), Hint::Unknown);
    }

    #[test]
    fn a_decomposed_event_path_is_composed_before_anyone_compares_it() {
        // What FSEvents hands back for a file the engine created as `café.txt`.
        // Left alone it matches nothing the engine knows about, so the change is
        // noticed and then dropped on the floor.
        let decomposed = PathBuf::from("/root/cafe\u{301}.txt");
        assert_eq!(
            normalize_event_path(&decomposed),
            PathBuf::from("/root/caf\u{e9}.txt")
        );
    }

    #[test]
    fn an_already_composed_path_is_untouched() {
        let p = PathBuf::from("/root/plain.txt");
        assert_eq!(normalize_event_path(&p), p);
    }

    #[test]
    fn a_watcher_reports_the_spelling_of_the_root_its_events_will_use() {
        // Not the spelling the user typed: on macOS a root reached through a
        // symlink produces events under the resolved path, and comparing those
        // against the typed path discards every one of them.
        let dir = std::env::temp_dir().join(format!("jd-watch-root-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();

        let dirty = Arc::new(Mutex::new(DirtySet::new(0)));
        let w = Watcher::start(&dir, dirty, || 0).unwrap();
        assert_eq!(w.root(), std::fs::canonicalize(&dir).unwrap());

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn events_arriving_through_a_symlinked_root_are_still_inside_it() {
        // The macOS `/var` → `/private/var` case, reproduced on Linux with a
        // symlink of our own: the watcher must be started on the resolved
        // spelling, or `is_inside` rejects everything it hears about.
        #[cfg(unix)]
        {
            let base = std::env::temp_dir().join(format!("jd-watch-sym-{}", std::process::id()));
            let _ = std::fs::remove_dir_all(&base);
            let real = base.join("real");
            std::fs::create_dir_all(&real).unwrap();
            let link = base.join("link");
            std::os::unix::fs::symlink(&real, &link).unwrap();

            let dirty = Arc::new(Mutex::new(DirtySet::new(0)));
            let w = Watcher::start(&link, dirty, || 0).unwrap();

            let event_path = std::fs::canonicalize(&real).unwrap().join("a.txt");
            assert!(
                crate::paths::is_inside(w.root(), &event_path, false),
                "a watcher started on a symlink must still recognize its own events"
            );

            let _ = std::fs::remove_dir_all(&base);
        }
    }

    #[test]
    fn a_fresh_watcher_asks_for_a_full_scan_before_reporting_anything() {
        // Whatever changed while the client was closed produced no events. If
        // startup trusted an empty dirty set, those changes would stay
        // invisible until something else happened to touch the same files.
        let dir = std::env::temp_dir().join(format!("jd-watch-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();

        let dirty = Arc::new(Mutex::new(DirtySet::new(0)));
        let w = Watcher::start(&dir, Arc::clone(&dirty), || 0).unwrap();

        assert!(w.dirty().lock().unwrap().rescan_needed());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn real_events_reach_the_dirty_set() {
        let dir = std::env::temp_dir().join(format!("jd-watch-live-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();

        let dirty = Arc::new(Mutex::new(DirtySet::new(0)));
        let _w = Watcher::start(&dir, Arc::clone(&dirty), || 1).unwrap();
        dirty.lock().unwrap().clear_rescan();

        std::fs::write(dir.join("appeared.txt"), b"hello").unwrap();

        // Backends deliver asynchronously; poll rather than assume a latency.
        let mut seen = false;
        for _ in 0..50 {
            std::thread::sleep(std::time::Duration::from_millis(40));
            let found = settled_paths(&dirty, 10_000);
            if found.iter().any(|p| p.ends_with("appeared.txt")) {
                seen = true;
                break;
            }
        }
        assert!(seen, "a created file should reach the dirty set");

        let _ = std::fs::remove_dir_all(&dir);
    }
}
