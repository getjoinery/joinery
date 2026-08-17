//! A filesystem that exists only in memory, and lies on request.
//!
//! Real filesystems are the hardest part of this product to test, because the
//! behaviours that break sync engines are the ones you cannot ask for. You
//! cannot tell ext4 to reuse an inode at a chosen moment, or ask APFS to hand
//! back a name in a different normalization than the one you wrote, or make a
//! disk fill up exactly between the write and the rename. So the engine is
//! written against a trait, and this is the implementation that can do all of
//! those on demand.
//!
//! What it models, and why each one is here:
//!
//! - **Personality** ([`jd_vfs::Personality`]) as data, so the Windows rules
//!   are exercised on Linux. Case-insensitive lookup, decomposition on write,
//!   illegal characters, coarse mtimes — all switchable.
//! - **File ids** that can be **reused** after a delete. A recycled inode is
//!   how a sync engine convinces itself a brand-new file is an old one it
//!   already knows, and then overwrites it.
//! - **mtimes** truncated to the personality's granularity, and settable
//!   backwards. The engine may never trust an mtime as proof of anything.
//! - **Failures at exact moments**: out of space, permission denied, I/O error,
//!   on a nominated operation.
//!
//! What it deliberately does *not* model is process death. A kill is not
//! something the filesystem does to you — it is the scheduler stopping the
//! engine. The simulator does that by dropping the engine and building a new
//! one over the same `MemFs`, which is exactly what a restart is: the disk is
//! still there, and everything the process was holding is gone.

use std::collections::BTreeMap;
use std::io::Write;
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};

use jd_vfs::{
    comparison_key, DirEntry, EntryKind, Fingerprint, Personality, SpoolFile, Vfs, VfsError,
    VfsResult,
};
use sha2::{Digest, Sha256};
use unicode_normalization::UnicodeNormalization;

use crate::clock::SimClock;

/// A node in the virtual tree.
#[derive(Debug, Clone)]
enum Node {
    Dir,
    File { bytes: Vec<u8>, mtime_ns: u64 },
}

/// Which operation a scheduled failure applies to.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FsOp {
    ReadDir,
    Fingerprint,
    Hash,
    CreateDir,
    Rename,
    Trash,
    Spool,
    Commit,
    OpenRead,
}

/// A failure to hand back, and how many more times to hand it back.
#[derive(Debug, Clone)]
struct ScheduledFailure {
    op: FsOp,
    /// `None` matches any path.
    path: Option<String>,
    kind: FailureKind,
    remaining: u32,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FailureKind {
    OutOfSpace,
    PermissionDenied,
    Io,
    /// The whole sync root has gone — an unmounted volume, a disconnected
    /// share. Distinct from every other failure because the engine's correct
    /// response is to pause, and the incorrect one is to conclude that every
    /// file was deleted.
    RootUnavailable,
}

#[derive(Debug)]
struct MemFsState {
    /// Relative path (`/`-joined, `""` is the root) → node. A `BTreeMap` so
    /// directory listings come out in a stable order without sorting.
    nodes: BTreeMap<String, Node>,
    /// Path → file id, kept beside the node so a rename preserves it and a
    /// delete releases it.
    file_ids: BTreeMap<String, u64>,
    next_file_id: u64,
    /// Ids released by deletes, handed out again when id reuse is enabled.
    freed_ids: Vec<u64>,
    reuse_file_ids: bool,
    /// Where trashed things went. The engine's promise is that a delete it got
    /// wrong is recoverable, so the simulator keeps the evidence.
    trash: Vec<(String, Node)>,
    root_available: bool,
    failures: Vec<ScheduledFailure>,
    /// Spool files in flight, by their temporary name.
    spools: BTreeMap<String, Vec<u8>>,
    next_spool: u64,
}

/// The virtual disk. Cloning shares it — that is what makes "restart the
/// engine over the same disk" a two-line operation.
#[derive(Debug, Clone)]
pub struct MemFs {
    state: Arc<Mutex<MemFsState>>,
    personality: Personality,
    clock: SimClock,
}

impl MemFs {
    pub fn new(personality: Personality, clock: SimClock) -> MemFs {
        let mut nodes = BTreeMap::new();
        nodes.insert(String::new(), Node::Dir);
        MemFs {
            state: Arc::new(Mutex::new(MemFsState {
                nodes,
                file_ids: BTreeMap::new(),
                next_file_id: 1000,
                freed_ids: Vec::new(),
                reuse_file_ids: false,
                trash: Vec::new(),
                root_available: true,
                failures: Vec::new(),
                spools: BTreeMap::new(),
                next_spool: 0,
            })),
            personality,
            clock,
        }
    }

    pub fn linux(clock: SimClock) -> MemFs {
        MemFs::new(Personality::linux(), clock)
    }
    /// A modern Mac: case-insensitive, and it hands names back exactly as they
    /// were written.
    pub fn macos(clock: SimClock) -> MemFs {
        MemFs::new(Personality::macos(), clock)
    }
    /// A volume that really does decompose — an HFS+ disk, or a network share.
    pub fn hfs_plus(clock: SimClock) -> MemFs {
        MemFs::new(Personality::hfs_plus(), clock)
    }

    pub fn windows(clock: SimClock) -> MemFs {
        MemFs::new(Personality::windows(), clock)
    }

    // ---- controls the scenario drives ------------------------------------

    /// Hand out deleted file ids again. Off by default because it is unusual;
    /// on when the scenario wants to prove the engine does not identify a file
    /// by its inode alone.
    pub fn reuse_file_ids(&self, on: bool) {
        self.state.lock().unwrap().reuse_file_ids = on;
    }

    /// Take the sync root away, or give it back.
    pub fn set_root_available(&self, available: bool) {
        self.state.lock().unwrap().root_available = available;
    }

    /// Fail the next `times` occurrences of `op` (optionally only at `path`).
    pub fn fail_next(&self, op: FsOp, path: Option<&str>, kind: FailureKind, times: u32) {
        self.state.lock().unwrap().failures.push(ScheduledFailure {
            op,
            path: path.map(|p| p.to_string()),
            kind,
            remaining: times,
        });
    }

    pub fn clear_failures(&self) {
        self.state.lock().unwrap().failures.clear();
    }

    // ---- the scenario's view of the disk ---------------------------------

    /// Write a file the way a *user* would: no engine involved, no atomic
    /// spool. This is how a scenario says "someone saved a document".
    pub fn user_write(&self, path: &str, bytes: &[u8]) {
        let key = self.store_path(path);
        let mut st = self.state.lock().unwrap();
        let mtime = self.truncated_now();
        Self::ensure_parents(&mut st, &key);
        if !st.file_ids.contains_key(&key) {
            let id = Self::alloc_id(&mut st);
            st.file_ids.insert(key.clone(), id);
        }
        st.nodes.insert(
            key,
            Node::File {
                bytes: bytes.to_vec(),
                mtime_ns: mtime,
            },
        );
    }

    /// A user creating a folder.
    pub fn user_mkdir(&self, path: &str) {
        let key = self.store_path(path);
        let mut st = self.state.lock().unwrap();
        Self::ensure_parents(&mut st, &key);
        st.nodes.entry(key).or_insert(Node::Dir);
    }

    /// A user deleting something outright — no trash, gone. Releases the file
    /// id, which is what makes reuse possible.
    pub fn user_remove(&self, path: &str) {
        let key = self.store_path(path);
        let mut st = self.state.lock().unwrap();
        let victims: Vec<String> = st
            .nodes
            .keys()
            .filter(|k| **k == key || k.starts_with(&format!("{key}/")))
            .cloned()
            .collect();
        for v in victims {
            st.nodes.remove(&v);
            if let Some(id) = st.file_ids.remove(&v) {
                st.freed_ids.push(id);
            }
        }
    }

    /// A user moving something.
    pub fn user_rename(&self, from: &str, to: &str) {
        let f = self.store_path(from);
        let t = self.store_path(to);
        let mut st = self.state.lock().unwrap();
        Self::ensure_parents(&mut st, &t);
        Self::move_subtree(&mut st, &f, &t);
    }

    /// Set an mtime by hand, including backwards. Filesystems and restore tools
    /// do this, and an engine that treats a newer mtime as proof of a newer
    /// file gets it wrong in both directions.
    pub fn set_mtime_ns(&self, path: &str, mtime_ns: u64) {
        let key = self.store_path(path);
        let mut st = self.state.lock().unwrap();
        if let Some(Node::File { mtime_ns: m, .. }) = st.nodes.get_mut(&key) {
            *m = mtime_ns;
        }
    }

    /// Read a file's bytes without going through the engine — the scenario's
    /// way of asking "what does the user actually have here?".
    pub fn peek(&self, path: &str) -> Option<Vec<u8>> {
        let key = self.store_path(path);
        let st = self.state.lock().unwrap();
        match st.nodes.get(&key) {
            Some(Node::File { bytes, .. }) => Some(bytes.clone()),
            _ => None,
        }
    }

    pub fn exists(&self, path: &str) -> bool {
        let key = self.store_path(path);
        self.state.lock().unwrap().nodes.contains_key(&key)
    }

    /// Every path on the disk, root first. Used by the convergence check.
    pub fn all_paths(&self) -> Vec<String> {
        let st = self.state.lock().unwrap();
        st.nodes.keys().filter(|k| !k.is_empty()).cloned().collect()
    }

    /// What was trashed, in order. The other half of "no committed content is
    /// ever lost": content that left the tree has to be findable here.
    pub fn trashed(&self) -> Vec<(String, Option<Vec<u8>>)> {
        let st = self.state.lock().unwrap();
        st.trash
            .iter()
            .map(|(p, n)| {
                (
                    p.clone(),
                    match n {
                        Node::File { bytes, .. } => Some(bytes.clone()),
                        Node::Dir => None,
                    },
                )
            })
            .collect()
    }

    /// The fingerprint of a path, as the engine would see it. For a scenario
    /// that needs to record what the engine last agreed about a file.
    pub fn fingerprint_at(&self, path: &str) -> Option<Fingerprint> {
        let key = self.store_path(path);
        let st = self.state.lock().unwrap();
        MemFs::fingerprint_of(&st, &key)
    }

    /// How many spool files are still open.
    ///
    /// Always zero once a scenario settles. A transfer that was abandoned
    /// without cleaning up leaves the user's disk filling with invisible
    /// half-files, which is a slow version of running out of space.
    pub fn spool_count(&self) -> usize {
        self.state.lock().unwrap().spools.len()
    }

    pub fn file_id_of(&self, path: &str) -> Option<u64> {
        let key = self.store_path(path);
        self.state.lock().unwrap().file_ids.get(&key).copied()
    }

    // ---- internals --------------------------------------------------------

    /// How a name is *stored*, which is not always how it was asked for. A
    /// decomposing filesystem hands back a different sequence of code points
    /// than the one written to it, and an engine that compares raw bytes reads
    /// that as a rename of every file with an accent in its name.
    /// A filesystem that does not decompose does not compose either: ext4 and
    /// APFS store the bytes they are handed. Composing here made the simulator
    /// kinder than any real disk — a decomposed name written to a simulated
    /// Linux volume came back composed, so the engine's composed idea of the
    /// name always matched and the mismatch that wedges a real client could not
    /// be reproduced at all.
    fn store_name(&self, name: &str) -> String {
        if self.personality.decomposes_unicode {
            name.nfd().collect()
        } else {
            name.to_string()
        }
    }

    fn store_path(&self, path: &str) -> String {
        let trimmed = path.trim_matches('/');
        if trimmed.is_empty() {
            return String::new();
        }
        trimmed
            .split('/')
            .map(|seg| self.store_name(seg))
            .collect::<Vec<_>>()
            .join("/")
    }

    /// Turn an absolute engine path into a stored relative key, resolving each
    /// segment case-insensitively when the personality says the filesystem is.
    fn key_for(&self, path: &Path) -> VfsResult<String> {
        let root = PathBuf::from("/sync");
        let rel = path
            .strip_prefix(&root)
            .map_err(|_| VfsError::NotFound(path.to_path_buf()))?;
        let mut key = String::new();
        for seg in rel.iter() {
            let seg = seg.to_string_lossy().to_string();
            let candidate = if key.is_empty() {
                self.store_name(&seg)
            } else {
                format!("{}/{}", key, self.store_name(&seg))
            };
            key = if self.personality.case_insensitive {
                self.resolve_case(&candidate).unwrap_or(candidate)
            } else {
                candidate
            };
        }
        Ok(key)
    }

    /// Find an existing path that differs only by case or normalization.
    fn resolve_case(&self, candidate: &str) -> Option<String> {
        let st = self.state.lock().unwrap();
        if st.nodes.contains_key(candidate) {
            return Some(candidate.to_string());
        }
        let want = self.fold_path(candidate);
        st.nodes.keys().find(|k| self.fold_path(k) == want).cloned()
    }

    fn fold_path(&self, path: &str) -> String {
        path.split('/')
            .map(|seg| comparison_key(seg, &self.personality))
            .collect::<Vec<_>>()
            .join("/")
    }

    fn truncated_now(&self) -> u64 {
        let g = self.personality.mtime_granularity_ns.max(1);
        (self.clock.now_ns() / g) * g
    }

    fn alloc_id(st: &mut MemFsState) -> u64 {
        if st.reuse_file_ids {
            if let Some(id) = st.freed_ids.pop() {
                return id;
            }
        }
        st.next_file_id += 1;
        st.next_file_id
    }

    fn ensure_parents(st: &mut MemFsState, key: &str) {
        let parts: Vec<&str> = key.split('/').collect();
        for i in 1..parts.len() {
            let prefix = parts[..i].join("/");
            st.nodes.entry(prefix).or_insert(Node::Dir);
        }
    }

    fn move_subtree(st: &mut MemFsState, from: &str, to: &str) {
        let moving: Vec<String> = st
            .nodes
            .keys()
            .filter(|k| **k == *from || k.starts_with(&format!("{from}/")))
            .cloned()
            .collect();
        for old in moving {
            let suffix = &old[from.len()..];
            let new = format!("{to}{suffix}");
            if let Some(node) = st.nodes.remove(&old) {
                st.nodes.insert(new.clone(), node);
            }
            if let Some(id) = st.file_ids.remove(&old) {
                st.file_ids.insert(new, id);
            }
        }
    }

    /// Consume a scheduled failure for this op/path, if one is due.
    fn check_failure(&self, op: FsOp, key: &str, path: &Path) -> VfsResult<()> {
        let mut st = self.state.lock().unwrap();
        if !st.root_available {
            return Err(VfsError::RootUnavailable(PathBuf::from("/sync")));
        }
        let hit = st.failures.iter_mut().position(|f| {
            f.op == op && f.remaining > 0 && f.path.as_deref().map(|p| p == key).unwrap_or(true)
        });
        let Some(idx) = hit else { return Ok(()) };
        st.failures[idx].remaining -= 1;
        let kind = st.failures[idx].kind;
        if st.failures[idx].remaining == 0 {
            st.failures.remove(idx);
        }
        let p = path.to_path_buf();
        Err(match kind {
            FailureKind::OutOfSpace => VfsError::OutOfSpace(p),
            FailureKind::PermissionDenied => VfsError::PermissionDenied(p),
            FailureKind::RootUnavailable => VfsError::RootUnavailable(PathBuf::from("/sync")),
            FailureKind::Io => VfsError::Io {
                path: p,
                source: std::io::Error::other("simulated I/O error"),
            },
        })
    }

    fn fingerprint_of(st: &MemFsState, key: &str) -> Option<Fingerprint> {
        match st.nodes.get(key) {
            Some(Node::File { bytes, mtime_ns }) => Some(Fingerprint {
                size: bytes.len() as u64,
                mtime_ns: *mtime_ns,
                file_id: st.file_ids.get(key).copied().unwrap_or(0),
            }),
            _ => None,
        }
    }
}

impl Vfs for MemFs {
    fn personality(&self) -> Personality {
        self.personality
    }

    fn root(&self) -> Option<PathBuf> {
        if self.state.lock().unwrap().root_available {
            Some(PathBuf::from("/sync"))
        } else {
            None
        }
    }

    fn read_dir(&self, path: &Path) -> VfsResult<Vec<DirEntry>> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::ReadDir, &key, path)?;
        let st = self.state.lock().unwrap();
        match st.nodes.get(&key) {
            Some(Node::Dir) => {}
            Some(_) => return Err(VfsError::NotADirectory(path.to_path_buf())),
            None => return Err(VfsError::NotFound(path.to_path_buf())),
        }
        let prefix = if key.is_empty() {
            String::new()
        } else {
            format!("{key}/")
        };
        let mut out = Vec::new();
        for (k, node) in st.nodes.iter() {
            if k.is_empty() || !k.starts_with(&prefix) {
                continue;
            }
            let rest = &k[prefix.len()..];
            if rest.is_empty() || rest.contains('/') {
                continue;
            }
            out.push(DirEntry {
                // Composed on the way out only where `OsVfs` composes: on a
                // volume that decomposes whatever it is given, so that the
                // round trip is not read as a rename. Elsewhere the stored
                // spelling is the answer, because that is what a real
                // `read_dir` returns.
                name: if self.personality.decomposes_unicode {
                    jd_vfs::nfc(rest)
                } else {
                    rest.to_string()
                },
                kind: match node {
                    Node::Dir => EntryKind::Directory,
                    Node::File { .. } => EntryKind::File,
                },
                fingerprint: Self::fingerprint_of(&st, k),
            });
        }
        Ok(out)
    }

    fn fingerprint(&self, path: &Path) -> VfsResult<Option<Fingerprint>> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::Fingerprint, &key, path)?;
        let st = self.state.lock().unwrap();
        Ok(Self::fingerprint_of(&st, &key))
    }

    fn hash(&self, path: &Path) -> VfsResult<String> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::Hash, &key, path)?;
        let st = self.state.lock().unwrap();
        match st.nodes.get(&key) {
            Some(Node::File { bytes, .. }) => {
                let mut h = Sha256::new();
                h.update(bytes);
                Ok(h.finalize().iter().map(|b| format!("{b:02x}")).collect())
            }
            Some(Node::Dir) => Err(VfsError::NotADirectory(path.to_path_buf())),
            None => Err(VfsError::NotFound(path.to_path_buf())),
        }
    }

    fn create_dir(&self, path: &Path) -> VfsResult<()> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::CreateDir, &key, path)?;
        let mut st = self.state.lock().unwrap();
        if st.nodes.contains_key(&key) {
            return Err(VfsError::AlreadyExists(path.to_path_buf()));
        }
        Self::ensure_parents(&mut st, &key);
        st.nodes.insert(key, Node::Dir);
        Ok(())
    }

    fn rename(&self, from: &Path, to: &Path) -> VfsResult<()> {
        let f = self.key_for(from)?;
        let t = self.key_for(to)?;
        self.check_failure(FsOp::Rename, &f, from)?;
        let mut st = self.state.lock().unwrap();
        let Some(source) = st.nodes.get(&f).cloned() else {
            return Err(VfsError::NotFound(from.to_path_buf()));
        };
        // A directory does not silently land on top of another one. Renaming
        // over a non-empty directory is `ENOTEMPTY` on every real filesystem
        // this runs on, and a file and a directory never replace each other at
        // all.
        //
        // This used to merge the two and overwrite whatever collided. Nothing
        // reported it, because on the surface the rename succeeded — and a
        // folder move that landed on a folder of the same name took a file
        // inside it that had never been uploaded anywhere. The rig's own oracle
        // caught it as content the engine removed and nobody could find again.
        if let Some(dest) = st.nodes.get(&t) {
            let dest_is_dir = matches!(dest, Node::Dir);
            if matches!(source, Node::Dir) != dest_is_dir {
                return Err(VfsError::NotADirectory(to.to_path_buf()));
            }
            if dest_is_dir
                && st
                    .nodes
                    .keys()
                    .any(|k| k.starts_with(&format!("{t}/")))
            {
                return Err(VfsError::AlreadyExists(to.to_path_buf()));
            }
        }
        Self::ensure_parents(&mut st, &t);
        Self::move_subtree(&mut st, &f, &t);
        Ok(())
    }

    fn trash(&self, path: &Path) -> VfsResult<()> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::Trash, &key, path)?;
        let mut st = self.state.lock().unwrap();
        let victims: Vec<String> = st
            .nodes
            .keys()
            .filter(|k| **k == key || k.starts_with(&format!("{key}/")))
            .cloned()
            .collect();
        if victims.is_empty() {
            return Err(VfsError::NotFound(path.to_path_buf()));
        }
        for v in victims {
            if let Some(node) = st.nodes.remove(&v) {
                st.trash.push((v.clone(), node));
            }
            if let Some(id) = st.file_ids.remove(&v) {
                st.freed_ids.push(id);
            }
        }
        Ok(())
    }

    fn spool(&self, target: &Path) -> VfsResult<Box<dyn SpoolFile>> {
        let key = self.key_for(target)?;
        self.check_failure(FsOp::Spool, &key, target)?;
        let mut st = self.state.lock().unwrap();
        st.next_spool += 1;
        let name = format!(".jd-spool-{}", st.next_spool);
        st.spools.insert(name.clone(), Vec::new());
        drop(st);
        Ok(Box::new(MemSpool {
            fs: self.clone(),
            name,
            buf: Vec::new(),
        }))
    }

    fn open_read(&self, path: &Path) -> VfsResult<Box<dyn jd_vfs::ReadSeek>> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::OpenRead, &key, path)?;
        let st = self.state.lock().unwrap();
        match st.nodes.get(&key) {
            Some(Node::File { bytes, .. }) => Ok(Box::new(std::io::Cursor::new(bytes.clone()))),
            Some(Node::Dir) => Err(VfsError::NotADirectory(path.to_path_buf())),
            None => Err(VfsError::NotFound(path.to_path_buf())),
        }
    }

    fn scratch(&self) -> VfsResult<Box<dyn jd_vfs::ScratchFile>> {
        // Counted with the spools, so a scenario that leaks one fails the same
        // "nothing invisible was left behind" check.
        let mut st = self.state.lock().unwrap();
        st.next_spool += 1;
        let name = format!(".jd-scratch-{}", st.next_spool);
        st.spools.insert(name.clone(), Vec::new());
        drop(st);
        Ok(Box::new(MemScratch {
            fs: self.clone(),
            name,
            buf: Vec::new(),
        }))
    }
}

/// Bytes that exist only for the length of a transfer and never become a file.
struct MemScratch {
    fs: MemFs,
    name: String,
    buf: Vec<u8>,
}

impl Write for MemScratch {
    fn write(&mut self, data: &[u8]) -> std::io::Result<usize> {
        self.buf.extend_from_slice(data);
        Ok(data.len())
    }
    fn flush(&mut self) -> std::io::Result<()> {
        Ok(())
    }
}

impl Drop for MemScratch {
    fn drop(&mut self) {
        // Dropped without finishing — an error path. The reader takes over the
        // bookkeeping when there is one.
        self.fs.state.lock().unwrap().spools.remove(&self.name);
    }
}

impl jd_vfs::ScratchFile for MemScratch {
    fn finish(mut self: Box<Self>) -> VfsResult<Box<dyn jd_vfs::ReadSeek>> {
        let bytes = std::mem::take(&mut self.buf);
        // Off the books here rather than when the reader is dropped: the
        // scenario check this feeds is "was anything left half-written", and a
        // finished scratch is not that. `Drop` then removes nothing, which is
        // the correct amount of work to do twice.
        self.fs.state.lock().unwrap().spools.remove(&self.name);
        Ok(Box::new(std::io::Cursor::new(bytes)))
    }
}

/// Bytes accumulating somewhere invisible until the commit makes them the file.
///
/// The whole point of a spool is that there is no instant at which a reader
/// sees a partial download, so nothing here touches the target path until
/// `commit`, and `commit` either replaces it entirely or does not touch it.
struct MemSpool {
    fs: MemFs,
    name: String,
    buf: Vec<u8>,
}

impl Write for MemSpool {
    fn write(&mut self, data: &[u8]) -> std::io::Result<usize> {
        self.buf.extend_from_slice(data);
        Ok(data.len())
    }
    fn flush(&mut self) -> std::io::Result<()> {
        Ok(())
    }
}

impl SpoolFile for MemSpool {
    fn commit(
        self: Box<Self>,
        target: &Path,
        expect: Option<Fingerprint>,
    ) -> VfsResult<Fingerprint> {
        // Whatever happens below, the spool goes. The handle is consumed by
        // this call, so a spool left behind on a failure is one nothing can
        // ever reach again.
        let key = match self.fs.key_for(target) {
            Ok(k) => k,
            Err(e) => {
                self.fs.state.lock().unwrap().spools.remove(&self.name);
                return Err(e);
            }
        };
        if let Err(e) = self.fs.check_failure(FsOp::Commit, &key, target) {
            self.fs.state.lock().unwrap().spools.remove(&self.name);
            return Err(e);
        }
        let mtime = self.fs.truncated_now();
        let mut st = self.fs.state.lock().unwrap();
        st.spools.remove(&self.name);

        // The guard that makes an in-flight download safe: if the file under
        // us is not the one the engine decided about, the user changed it while
        // we were fetching, and their change wins by default. Overwriting here
        // would destroy work that was never uploaded.
        // Only when something is actually there. An absent target has no
        // change to protect: refusing then would deadlock a file that was moved
        // away locally while the server moved it somewhere else, because the
        // download that would settle it can never land. The real filesystem
        // behaves this way too, and a simulator that is stricter than the thing
        // it simulates reports bugs that do not exist while hiding ones that do.
        let current = MemFs::fingerprint_of(&st, &key);
        if let (Some(want), Some(now)) = (expect, current) {
            if !now.unchanged_from(&want, &self.fs.personality) {
                return Err(VfsError::AlreadyExists(target.to_path_buf()));
            }
        }

        MemFs::ensure_parents(&mut st, &key);
        // Replacing a file keeps its id — the rename lands on top of it, which
        // is what a real filesystem does and what makes "same inode, new
        // content" a case the engine has to handle.
        let id = match st.file_ids.get(&key) {
            Some(id) => *id,
            None => {
                let id = MemFs::alloc_id(&mut st);
                st.file_ids.insert(key.clone(), id);
                id
            }
        };
        let size = self.buf.len() as u64;
        st.nodes.insert(
            key,
            Node::File {
                bytes: self.buf,
                mtime_ns: mtime,
            },
        );
        Ok(Fingerprint {
            size,
            mtime_ns: mtime,
            file_id: id,
        })
    }

    fn discard(self: Box<Self>) {
        let mut st = self.fs.state.lock().unwrap();
        st.spools.remove(&self.name);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn p(s: &str) -> PathBuf {
        PathBuf::from("/sync").join(s.trim_start_matches('/'))
    }

    fn fs() -> MemFs {
        MemFs::linux(SimClock::new())
    }

    #[test]
    fn a_written_file_reads_back() {
        let f = fs();
        f.user_write("notes.txt", b"hello");
        assert_eq!(f.hash(&p("notes.txt")).unwrap().len(), 64);
        let mut r = f.open_read(&p("notes.txt")).unwrap();
        let mut got = Vec::new();
        r.read_to_end(&mut got).unwrap();
        assert_eq!(got, b"hello");
    }

    #[test]
    fn writing_a_nested_file_creates_the_folders_above_it() {
        let f = fs();
        f.user_write("a/b/c.txt", b"x");
        assert!(f.exists("a"));
        assert!(f.exists("a/b"));
        let listing = f.read_dir(&p("a")).unwrap();
        assert_eq!(listing.len(), 1);
        assert_eq!(listing[0].name, "b");
        assert_eq!(listing[0].kind, EntryKind::Directory);
    }

    #[test]
    fn a_directory_listing_names_only_its_own_children() {
        let f = fs();
        f.user_write("a/one.txt", b"1");
        f.user_write("a/two.txt", b"2");
        f.user_write("a/deep/three.txt", b"3");
        let names: Vec<String> = f
            .read_dir(&p("a"))
            .unwrap()
            .into_iter()
            .map(|e| e.name)
            .collect();
        assert_eq!(names, vec!["deep", "one.txt", "two.txt"]);
    }

    #[test]
    fn a_case_insensitive_filesystem_finds_a_file_under_the_wrong_case() {
        let f = MemFs::windows(SimClock::new());
        f.user_write("Report.TXT", b"x");
        // Windows would open this. An engine that assumed otherwise would
        // create a second file and then fight with itself forever.
        assert!(f.fingerprint(&p("report.txt")).unwrap().is_some());
    }

    #[test]
    fn a_case_sensitive_filesystem_keeps_them_apart() {
        let f = fs();
        f.user_write("Report.TXT", b"x");
        assert!(f.fingerprint(&p("report.txt")).unwrap().is_none());
    }

    #[test]
    fn a_decomposing_filesystem_stores_decomposed_and_reports_composed() {
        // Both halves are the contract. An HFS+ volume really does store `café`
        // in decomposed form — that is why `all_paths` shows it — and `OsVfs`
        // composes what it reads back from such a volume, so that the round trip
        // is not read as a rename. The simulator has to do the same, or it fails
        // scenarios the real client passes.
        let f = MemFs::hfs_plus(SimClock::new());
        f.user_write("caf\u{e9}.txt", b"x");

        assert!(
            f.all_paths().iter().any(|p| p == "cafe\u{301}.txt"),
            "the volume stores it decomposed"
        );
        let names: Vec<String> = f
            .read_dir(&p(""))
            .unwrap()
            .into_iter()
            .map(|e| e.name)
            .collect();
        assert_eq!(names, vec!["caf\u{e9}.txt"], "the engine is handed NFC");
        // ...and it is still the same file when asked for by either spelling.
        assert!(f.fingerprint(&p("caf\u{e9}.txt")).unwrap().is_some());
        assert!(f.fingerprint(&p("cafe\u{301}.txt")).unwrap().is_some());
    }

    #[test]
    fn a_preserving_filesystem_hands_back_the_spelling_it_was_given() {
        // ext4 and APFS store bytes. A name written decomposed is still
        // decomposed when it is read back, and no layer between the disk and
        // the engine quietly composes it.
        //
        // The simulator used to compose here, on both the write and the read,
        // and that kindness cost the soak rig nineteen wedged files: with the
        // spelling silently agreed on both sides, nothing in the simulated
        // world could show the engine recording one spelling while the disk
        // held the other.
        let f = fs();
        f.user_write("cafe\u{301}.txt", b"x");

        let names: Vec<String> = f
            .read_dir(&p(""))
            .unwrap()
            .into_iter()
            .map(|e| e.name)
            .collect();
        assert_eq!(
            names,
            vec!["cafe\u{301}.txt"],
            "the engine is handed what the disk holds"
        );
        // And the composed spelling is a different file here, not the same one.
        assert!(f.fingerprint(&p("cafe\u{301}.txt")).unwrap().is_some());
        assert!(f.fingerprint(&p("caf\u{e9}.txt")).unwrap().is_none());
    }

    #[test]
    fn mtimes_are_truncated_to_what_the_filesystem_can_store() {
        let clock = SimClock::starting_at(1_000_123);
        let fat = MemFs::new(Personality::fat32(), clock);
        fat.user_write("a.txt", b"x");
        let fp = fat.fingerprint(&p("a.txt")).unwrap().unwrap();
        let g = Personality::fat32().mtime_granularity_ns;
        assert_eq!(fp.mtime_ns % g, 0, "FAT cannot store finer than its step");
    }

    #[test]
    fn a_deleted_file_id_is_only_reused_when_asked_for() {
        let f = fs();
        f.user_write("a.txt", b"x");
        let first = f.file_id_of("a.txt").unwrap();
        f.user_remove("a.txt");
        f.user_write("b.txt", b"y");
        assert_ne!(f.file_id_of("b.txt").unwrap(), first);

        // With reuse on, the new file inherits the dead file's identity — the
        // exact trap that convinces an engine a stranger is an old friend.
        let g = fs();
        g.reuse_file_ids(true);
        g.user_write("a.txt", b"x");
        let id = g.file_id_of("a.txt").unwrap();
        g.user_remove("a.txt");
        g.user_write("b.txt", b"y");
        assert_eq!(g.file_id_of("b.txt").unwrap(), id);
    }

    #[test]
    fn a_rename_keeps_the_file_id_and_moves_the_whole_subtree() {
        let f = fs();
        f.user_write("box/a.txt", b"x");
        let id = f.file_id_of("box/a.txt").unwrap();
        f.rename(&p("box"), &p("crate")).unwrap();
        assert!(!f.exists("box"));
        assert_eq!(f.peek("crate/a.txt").unwrap(), b"x");
        assert_eq!(f.file_id_of("crate/a.txt").unwrap(), id);
    }

    #[test]
    fn trashing_keeps_the_bytes_where_they_can_be_found() {
        // The product promise is that a delete the engine got wrong is
        // recoverable. An unlink would make this test impossible to write.
        let f = fs();
        f.user_write("important.txt", b"the only copy");
        f.trash(&p("important.txt")).unwrap();
        assert!(!f.exists("important.txt"));
        let trashed = f.trashed();
        assert_eq!(trashed.len(), 1);
        assert_eq!(trashed[0].1.as_deref(), Some(&b"the only copy"[..]));
    }

    #[test]
    fn a_spool_is_invisible_until_it_commits() {
        let f = fs();
        let mut s = f.spool(&p("download.bin")).unwrap();
        s.write_all(b"partial").unwrap();
        assert!(
            !f.exists("download.bin"),
            "a half-written download must never be something the user can open"
        );
        s.write_all(b" and the rest").unwrap();
        s.commit(&p("download.bin"), None).unwrap();
        assert_eq!(f.peek("download.bin").unwrap(), b"partial and the rest");
    }

    #[test]
    fn a_discarded_spool_leaves_nothing_behind() {
        let f = fs();
        let mut s = f.spool(&p("download.bin")).unwrap();
        s.write_all(b"abandoned").unwrap();
        s.discard();
        assert!(!f.exists("download.bin"));
        assert_eq!(f.state.lock().unwrap().spools.len(), 0);
    }

    #[test]
    fn a_commit_refuses_to_overwrite_a_file_that_changed_underneath_it() {
        // The download started, the user saved over the file while it was in
        // flight, and their work has never been uploaded. Landing the download
        // on top of it would destroy the only copy.
        let f = fs();
        f.user_write("doc.txt", b"original");
        let before = f.fingerprint(&p("doc.txt")).unwrap().unwrap();

        let mut s = f.spool(&p("doc.txt")).unwrap();
        s.write_all(b"from the server").unwrap();
        f.user_write("doc.txt", b"the user's unsaved work");

        let outcome = s.commit(&p("doc.txt"), Some(before));
        assert!(outcome.is_err());
        assert_eq!(f.peek("doc.txt").unwrap(), b"the user's unsaved work");
    }

    #[test]
    fn a_commit_proceeds_when_the_file_is_still_the_one_we_decided_about() {
        let f = fs();
        f.user_write("doc.txt", b"original");
        let before = f.fingerprint(&p("doc.txt")).unwrap().unwrap();
        let mut s = f.spool(&p("doc.txt")).unwrap();
        s.write_all(b"from the server").unwrap();
        s.commit(&p("doc.txt"), Some(before)).unwrap();
        assert_eq!(f.peek("doc.txt").unwrap(), b"from the server");
    }

    #[test]
    fn an_unavailable_root_is_reported_as_a_pause_not_as_an_empty_tree() {
        // The difference between an inconvenience and a catastrophe: an engine
        // that reads an unmounted volume as "everything was deleted" will
        // faithfully delete everything on the server.
        let f = fs();
        f.user_write("a.txt", b"x");
        f.set_root_available(false);
        assert!(f.root().is_none());
        assert!(matches!(
            f.read_dir(&p("")),
            Err(VfsError::RootUnavailable(_))
        ));
        f.set_root_available(true);
        assert_eq!(f.read_dir(&p("")).unwrap().len(), 1);
    }

    #[test]
    fn a_scheduled_failure_fires_exactly_as_many_times_as_asked() {
        let f = fs();
        f.user_write("a.txt", b"x");
        f.fail_next(FsOp::Hash, None, FailureKind::Io, 2);
        assert!(f.hash(&p("a.txt")).is_err());
        assert!(f.hash(&p("a.txt")).is_err());
        assert!(f.hash(&p("a.txt")).is_ok());
    }

    #[test]
    fn a_failure_can_be_aimed_at_one_path() {
        let f = fs();
        f.user_write("a.txt", b"x");
        f.user_write("b.txt", b"y");
        f.fail_next(FsOp::Hash, Some("a.txt"), FailureKind::OutOfSpace, 1);
        assert!(f.hash(&p("b.txt")).is_ok());
        assert!(matches!(f.hash(&p("a.txt")), Err(VfsError::OutOfSpace(_))));
    }

    #[test]
    fn the_disk_outlives_the_handle_that_made_it() {
        // This is how a process kill is modelled: the engine goes away, the
        // disk does not. Everything the engine was holding in memory is lost;
        // everything it had committed is still here.
        let f = fs();
        f.user_write("survivor.txt", b"still here");
        let after_restart = f.clone();
        drop(f);
        assert_eq!(after_restart.peek("survivor.txt").unwrap(), b"still here");
    }
}
