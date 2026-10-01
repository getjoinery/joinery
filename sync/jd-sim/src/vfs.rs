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

use std::collections::{BTreeMap, BTreeSet};
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

/// What a disk's file id means: the question every FAT and exFAT volume
/// answers differently, each traced on a real system
/// (`specs/drive_weak_volume_identity.md`, F1).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum FileIds {
    /// One id for as long as the file exists: every strong disk.
    Stable,
    /// Windows FAT and exFAT: the position of the file's directory entry. It
    /// moves when the entry has to (another folder, a longer name), a freed
    /// slot is taken again first-fit by the next entry in that folder, and a
    /// name taken again within 15 seconds is given the creation time of the
    /// file that left it (tunnelling). Its probe reads it weak.
    DirectorySlot,
    /// macOS 15 FAT32: the file's first data cluster. A rename, a move and a
    /// remount keep it; rewriting the content moves it, and an empty file has
    /// a temporary id that a remount replaces. Its probe reads it strong.
    DataCluster,
    /// Linux FAT and exFAT, macOS exFAT: an id handed out per mount, kept
    /// through renames, and every one new after a remount; births kept. Its
    /// probe reads it strong.
    MountSession,
}

/// The server's latest change id, read when a listing is made.
#[derive(Clone)]
pub struct ChangeClock(pub std::sync::Arc<dyn Fn() -> i64 + Send + Sync>);

impl std::fmt::Debug for ChangeClock {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("ChangeClock")
    }
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
    /// Path -> the directory's birth: a number handed out once, in creation
    /// order, and never again. An id is a disk fact and under `reuse_file_ids`
    /// a deleted directory's id comes straight back on the next mkdir; a birth
    /// is a harness fact, so an oracle that keeps a handle on a directory
    /// across the run (`zz_sweep`'s custody oracle) keys it by birth and can
    /// never mistake the folder that inherited an id for the one that died
    /// with it. Carried by a rename like the id, dropped by a remove.
    births: BTreeMap<String, u64>,
    next_birth: u64,
    /// Path -> the FILE's birth, which unlike a directory's is a disk fact:
    /// it is what `statx` btime, `st_birthtime` and an NTFS creation time
    /// report, and the engine reads it (`Fingerprint::birth_ns`). Handed out
    /// once, never again -- so under `reuse_file_ids` a recycled id arrives
    /// with a new birth, as on a real disk -- carried by a rename like the id,
    /// dropped by a remove. Kept apart from `births` so a directory's number
    /// is the same with or without files beside it.
    file_births: BTreeMap<String, u64>,
    next_file_birth: u64,
    /// Report every file's birth as 0: the volume with no birth time, where
    /// every file's identity is weak.
    births_hidden: bool,
    /// Ids released by deletes, handed out again when id reuse is enabled.
    freed_ids: Vec<u64>,
    reuse_file_ids: bool,
    /// What an id means on this disk. See [`FileIds`].
    file_id_model: FileIds,
    /// Under `DirectorySlot`: the entry slots freed in each directory, by
    /// the directory's key, taken again lowest first.
    free_slots: BTreeMap<String, BTreeSet<u64>>,
    /// Under `DirectorySlot`: each name a file left, with that file's birth
    /// and when it left, for tunnelling.
    vacated: BTreeMap<String, (u64, u64)>,
    /// Under `DataCluster`: the next temporary id for an empty file, counted
    /// down from the top as macOS does.
    next_temp_id: u64,
    /// Every path a change touched since the watcher was last asked, the way
    /// a perfect watcher reports them, and whether it lost track (a remount,
    /// or the engine not running). Read by the pass runner on FAT models to
    /// keep the path-keyed hash cache honest (`MemFs::take_touched`).
    touched: BTreeSet<String>,
    watch_lost: bool,
    /// Where trashed things went. The engine's promise is that a delete it got
    /// wrong is recoverable, so the simulator keeps the evidence.
    trash: Vec<(String, Node)>,
    root_available: bool,
    failures: Vec<ScheduledFailure>,
    /// Spool files in flight, by their temporary name.
    spools: BTreeMap<String, Vec<u8>>,
    next_spool: u64,
    /// Every body the user wrote, as (path, sha256), in order.
    ///
    /// The one record of what the user put where. An oracle that wants to know
    /// which bytes were sealed when they were written -- and which of those the
    /// user later copied somewhere plain by their own hand -- reads it here
    /// rather than from a list the generator keeps, because the chaos writers
    /// and the safe-save's temporary do not go through the generator's list
    /// and do go through [`MemFs::user_write`].
    user_writes: Vec<UserWrite>,
    /// Every file path the user gave a file by renaming, in order: the file
    /// itself, or every file under a folder they renamed. Each with whether
    /// it then stood under a directory marked sealed.
    ///
    /// A rename writes no body, so it is not in `user_writes`, but it does
    /// put a NAME somewhere, and an oracle asking which names the user only
    /// ever used inside a vault has to see it. Without this, a plain file
    /// renamed to a name that also exists sealed read as the sealed name
    /// reaching the server (seed 74000 on the two-device ring arm).
    user_renames: Vec<(String, bool)>,
    /// Directories the harness has declared sealed, by their current path.
    ///
    /// The one piece of directory identity this disk keeps, and it keeps it
    /// for the harness only: the engine cannot see it. A mark travels with
    /// the directory through every rename, the user's or the engine's, and
    /// dies with it, so "was this write inside the folder the user sealed"
    /// can be answered after the folder has traded names -- which a path
    /// prefix cannot, because the ring arm trades the encrypted ring's name
    /// onto plain folders all run long. A directory the engine mints afresh
    /// under a sealed name carries no mark: it is not the folder the user
    /// sealed, whatever it is called.
    sealed_dirs: BTreeSet<String>,
    /// Bodies (sha256) and leaf names the engine on this device could have
    /// known were sealed: seen in a vault by a scan that completed, or held
    /// in a record that places them in one. A harness fact; the sealed
    /// oracle counts nothing else as sealed (owner decision Q4,
    /// `specs/drive_weak_volume_identity.md`).
    sealed_known: BTreeMap<String, i64>,
    sealed_names_known: BTreeSet<String>,
    /// Bodies and leaf names the engine's own directory listings have shown
    /// it under a sealed directory since the harness last took them: what a
    /// scan saw in a vault, at the moment it looked, with the server's change
    /// id at that moment when a clock is set.
    listed_in_a_vault: Vec<(String, String, Option<i64>)>,
    /// The server's change id now, for stamping a listing when it is made.
    change_clock: Option<ChangeClock>,
    /// Every directory answers 0 for its identity. See
    /// [`MemFs::directory_ids_unreadable`].
    directory_ids_unreadable: bool,
}

/// One body the user wrote, and where it stood when they did.
#[derive(Clone, Debug)]
pub struct UserWrite {
    /// The path as stored, so a decomposing volume reports the form it kept.
    pub path: String,
    pub sha256: String,
    /// Under a directory marked sealed at the moment of the write.
    pub in_sealed_dir: bool,
    /// The write made a new file at this path. Saving over a file that is
    /// there already chooses no name: the name was given when that file was
    /// made, by the user or by the engine.
    pub created: bool,
    /// The birth of the directory the file was written into, read when it
    /// was written: the folder the user put it in, whatever that directory
    /// is called by the time anyone asks.
    pub parent_birth: Option<u64>,
}

/// The virtual disk. Cloning shares it — that is what makes "restart the
/// engine over the same disk" a two-line operation.
#[derive(Clone)]
pub struct MemFs {
    state: Arc<Mutex<MemFsState>>,
    personality: Personality,
    clock: SimClock,
    /// Fires as a download is about to land, before it has looked at the path.
    ///
    /// The window this opens is a real one and a narrow one: the engine clears
    /// the way for an incoming file, and between clearing it and putting the
    /// file there, the user saves. `OsVfs`'s own refusal names that exact
    /// moment -- "between that check and this rename the user can save a file,
    /// and under a storm they do" -- and nothing could reach it from a test.
    #[allow(clippy::type_complexity)]
    landing: Arc<Mutex<Option<Box<dyn FnMut(&Path) + Send>>>>,
    dir_creating: Arc<Mutex<Option<Box<dyn FnMut(&Path) + Send>>>>,
    /// The disk changes under the executor between two of its reads of one
    /// path: the stored key, whether the first read has happened, and what
    /// the disk does before the second (`MemFs::between_reads`).
    #[allow(clippy::type_complexity)]
    between_reads: Arc<Mutex<Option<(String, bool, Box<dyn FnOnce(&MemFs) + Send>)>>>,
}

impl std::fmt::Debug for MemFs {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MemFs")
            .field("personality", &self.personality)
            .finish_non_exhaustive()
    }
}

impl MemFs {
    pub fn new(personality: Personality, clock: SimClock) -> MemFs {
        let mut nodes = BTreeMap::new();
        nodes.insert(String::new(), Node::Dir);
        // The root is a directory like any other and carries an id like any
        // other; the first one handed out.
        let mut file_ids = BTreeMap::new();
        file_ids.insert(String::new(), 1000);
        let mut births = BTreeMap::new();
        births.insert(String::new(), 1);
        MemFs {
            state: Arc::new(Mutex::new(MemFsState {
                nodes,
                file_ids,
                next_file_id: 1000,
                births,
                next_birth: 1,
                file_births: BTreeMap::new(),
                next_file_birth: 0,
                births_hidden: false,
                freed_ids: Vec::new(),
                reuse_file_ids: false,
                file_id_model: FileIds::Stable,
                free_slots: BTreeMap::new(),
                vacated: BTreeMap::new(),
                next_temp_id: u64::MAX,
                touched: BTreeSet::new(),
                watch_lost: false,
                trash: Vec::new(),
                root_available: true,
                failures: Vec::new(),
                spools: BTreeMap::new(),
                next_spool: 0,
                user_writes: Vec::new(),
                user_renames: Vec::new(),
                sealed_dirs: BTreeSet::new(),
                sealed_known: BTreeMap::new(),
                sealed_names_known: BTreeSet::new(),
                listed_in_a_vault: Vec::new(),
                change_clock: None,
                directory_ids_unreadable: false,
            })),
            personality,
            clock,
            landing: Arc::new(Mutex::new(None)),
            dir_creating: Arc::new(Mutex::new(None)),
            between_reads: Arc::new(Mutex::new(None)),
        }
    }

    /// Change the disk between two reads of one path: `f` runs at the start
    /// of the next access to `path` after the next one, whatever either
    /// access is. For pins of the rule that what the executor checks, hashes
    /// and sends is one file (`specs/drive_file_identity.md`, T1-D): a
    /// swap there lands after the executor's first look and before anything
    /// it reads again by the path.
    pub fn between_reads(&self, path: &str, f: impl FnOnce(&MemFs) + Send + 'static) {
        *self.between_reads.lock().unwrap() = Some((self.store_path(path), false, Box::new(f)));
    }

    /// The hook above, at an access to `key`: the first access arms it, the
    /// second runs it before it is served.
    fn run_between_reads(&self, key: &str) {
        let due = {
            let mut slot = self.between_reads.lock().unwrap();
            match slot.as_mut() {
                Some((k, seen, _)) if k == key => {
                    if *seen {
                        slot.take().map(|(_, _, f)| f)
                    } else {
                        *seen = true;
                        None
                    }
                }
                _ => None,
            }
        };
        if let Some(f) = due {
            f(self);
        }
    }

    /// Run this the instant a download begins to land, before it has looked at
    /// the path it is landing on. Use it to have the user save over the file in
    /// the window the engine believes it has already cleared.
    pub fn while_a_download_lands(&self, f: impl FnMut(&Path) + Send + 'static) {
        *self.landing.lock().unwrap() = Some(Box::new(f));
    }

    /// Run this the instant a directory is about to be created, before it
    /// exists. Use it to move the name out from under a device that is
    /// materialising a folder -- the shape the soak rig produces constantly and
    /// the simulator never has.
    pub fn while_creating_a_dir(&self, f: impl FnMut(&Path) + Send + 'static) {
        *self.dir_creating.lock().unwrap() = Some(Box::new(f));
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

    /// The paths touched since the last call, or None when the watcher lost
    /// track and everything must be read again. Clears both.
    pub fn take_touched(&self) -> Option<Vec<String>> {
        let mut st = self.state.lock().unwrap();
        let touched = std::mem::take(&mut st.touched);
        if std::mem::replace(&mut st.watch_lost, false) {
            None
        } else {
            Some(touched.into_iter().collect())
        }
    }

    /// The engine stopped: nothing watched the disk meanwhile.
    pub fn lose_watch(&self) {
        self.state.lock().unwrap().watch_lost = true;
    }

    /// Number files the way a FAT or exFAT volume does on one system. Set
    /// before anything is written; `reuse_file_ids` no longer applies.
    pub fn file_ids(&self, model: FileIds) {
        self.state.lock().unwrap().file_id_model = model;
    }

    /// The volume unplugged and plugged in again, between passes. What that
    /// does to ids depends on the volume: every id new (`MountSession`), the
    /// temporary ids of empty files new (`DataCluster`), nothing (the rest).
    /// Births are the disk's own and survive it.
    pub fn remount(&self) {
        let mut st = self.state.lock().unwrap();
        // Nothing watched the stick while it was out.
        st.watch_lost = true;
        let keys: Vec<String> = st.file_ids.keys().filter(|k| !k.is_empty()).cloned().collect();
        for k in keys {
            let renew = match st.file_id_model {
                FileIds::MountSession => true,
                FileIds::DataCluster => matches!(st.nodes.get(&k), Some(Node::File { bytes, .. }) if bytes.is_empty()),
                FileIds::Stable | FileIds::DirectorySlot => false,
            };
            if renew {
                let id = if st.file_id_model == FileIds::DataCluster {
                    Self::temp_id(&mut st)
                } else {
                    st.next_file_id += 1;
                    st.next_file_id
                };
                st.file_ids.insert(k, id);
            }
        }
    }

    /// Report no file's birth: the volume with no birth time, whose
    /// personality then says its file identity is weak, where the engine reads
    /// the disk by its older rules.
    pub fn hide_births(&self, on: bool) {
        self.state.lock().unwrap().births_hidden = on;
    }

    /// Report every directory's identity as 0 -- the Windows world where the
    /// handle a file index needs will not open. A reader that treats 0 as an
    /// id would pair every directory with every other; the scenario that
    /// turns this on is asking whether it does.
    pub fn directory_ids_unreadable(&self, on: bool) {
        self.state.lock().unwrap().directory_ids_unreadable = on;
    }

    /// Give every file and directory a fresh identity, keeping everything
    /// else: the disk after a restore from backup, a copy onto a new volume,
    /// or a re-created sync root. Every id the engine has recorded is now
    /// stale and stands nowhere, which is the world decision 2 of the reset's
    /// WP2 falls back from.
    pub fn renumber_every_id(&self) {
        let mut st = self.state.lock().unwrap();
        let keys: Vec<String> = st.file_ids.keys().cloned().collect();
        for k in keys {
            let id = Self::alloc_id(&mut st);
            if st.file_births.contains_key(&k) {
                let birth = Self::new_file_birth(&mut st);
                st.file_births.insert(k.clone(), birth);
            }
            st.file_ids.insert(k, id);
        }
    }

    /// Give every DIRECTORY a fresh identity and leave the files alone: the
    /// world an install that predates directory identity wakes up in, where
    /// files have their inodes on record and folders have nothing yet.
    pub fn renumber_directory_ids(&self) {
        let mut st = self.state.lock().unwrap();
        let keys: Vec<String> = st
            .file_ids
            .keys()
            .filter(|k| matches!(st.nodes.get(*k), Some(Node::Dir)))
            .cloned()
            .collect();
        for k in keys {
            let id = Self::alloc_id(&mut st);
            st.file_ids.insert(k, id);
        }
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
        let mtime = self.truncated_now(&st);
        Self::refuse_impossible(&st, &key, "write a file");
        assert!(
            !matches!(st.nodes.get(&key), Some(Node::Dir)),
            "a scenario wrote the file {key} over a directory. A real disk \
             answers EISDIR; this map would have swapped the node and left the \
             children hanging off a file, which is a shape no disk can hold. \
             The scenario is asking for something impossible -- fix the \
             scenario, not this check.",
        );
        Self::ensure_parents(&mut st, &key);
        let created = !st.file_ids.contains_key(&key);
        let now = self.clock.now_ns();
        if created {
            let id = Self::new_file_id(&mut st, &key, bytes.len());
            st.file_ids.insert(key.clone(), id);
            let birth = Self::new_file_birth(&mut st);
            st.file_births.insert(key.clone(), birth);
            Self::tunnel(&mut st, &key, now);
        } else if st.file_id_model == FileIds::DataCluster {
            // Rewritten in place: the content goes to new clusters.
            let id = Self::cluster_id(&mut st, bytes.len());
            st.file_ids.insert(key.clone(), id);
        }
        Self::watch_loss(&st, &key, "the user saving over it");
        st.touched.insert(key.clone());
        let in_sealed_dir = Self::under_a_sealed_dir(&st, &key);
        let parent_birth = st.births.get(key.rsplit_once('/').map(|(d, _)| d).unwrap_or("")).copied();
        st.user_writes.push(UserWrite {
            path: key.clone(),
            sha256: crate::sha256_hex(bytes),
            in_sealed_dir,
            created,
            parent_birth,
        });
        st.nodes.insert(
            key,
            Node::File {
                bytes: bytes.to_vec(),
                mtime_ns: mtime,
            },
        );
    }

    /// Every body the user has written to this disk, in the order written.
    pub fn user_writes(&self) -> Vec<UserWrite> {
        self.state.lock().unwrap().user_writes.clone()
    }

    /// Declare the directory at this path sealed, for the harness's own
    /// bookkeeping. See `MemFsState::sealed_dirs`.
    pub fn mark_sealed_dir(&self, path: &str) {
        let key = self.store_path(path);
        let mut st = self.state.lock().unwrap();
        assert!(
            matches!(st.nodes.get(&key), Some(Node::Dir)),
            "mark_sealed_dir: no directory at {key}"
        );
        st.sealed_dirs.insert(key);
    }

    /// Does a directory marked sealed stand above this path right now?
    /// Every file standing under a directory marked sealed, as (body sha256,
    /// leaf name): what a scan starting now would see in a vault.
    pub fn in_sealed_dirs(&self) -> Vec<(String, String)> {
        let st = self.state.lock().unwrap();
        st.nodes
            .iter()
            .filter(|(k, _)| Self::under_a_sealed_dir(&st, k))
            .filter_map(|(k, n)| match n {
                Node::File { bytes, .. } => {
                    Some((crate::sha256_hex(bytes), k.rsplit('/').next().unwrap_or(k).to_string()))
                }
                Node::Dir => None,
            })
            .collect()
    }

    /// What the engine's own listings have shown it under a sealed directory
    /// since this was last asked, and forget it. The harness credits it once
    /// the pass that listed it completes (owner decision Q4): read before the
    /// pass instead, a file carried out of the vault after that read and
    /// before the walk was counted as seen there when no engine ever saw it
    /// (FAT plat3 75428, a trade landing mid-pass).
    /// Stamp every listing in a vault with the server's change id at the
    /// moment it is made (a5's Q1, read per listing): a listing an operation
    /// makes late in a pass, after the pass's own uploads, is not known as of
    /// the pass's start.
    pub fn set_change_clock(&self, clock: ChangeClock) {
        self.state.lock().unwrap().change_clock = Some(clock);
    }

    pub fn take_listed_in_a_vault(&self) -> Vec<(String, String, Option<i64>)> {
        std::mem::take(&mut self.state.lock().unwrap().listed_in_a_vault)
    }

    /// Record bodies and names the engine here could have known were sealed,
    /// as of `as_of`: the server's change id when the pass that earned the
    /// knowledge started. A body keeps the earliest.
    pub fn know_sealed(&self, found: impl IntoIterator<Item = (String, String)>, as_of: i64) {
        let mut st = self.state.lock().unwrap();
        for (sha, name) in found {
            let at = st.sealed_known.entry(sha).or_insert(as_of);
            *at = (*at).min(as_of);
            st.sealed_names_known.insert(name);
        }
    }

    /// Bodies and leaf names this device's engine could have known were
    /// sealed. See [`MemFs::know_sealed`].
    pub fn sealed_known(&self) -> (BTreeMap<String, i64>, BTreeSet<String>) {
        let st = self.state.lock().unwrap();
        (st.sealed_known.clone(), st.sealed_names_known.clone())
    }

    pub fn under_sealed_dir(&self, path: &str) -> bool {
        let key = self.store_path(path);
        Self::under_a_sealed_dir(&self.state.lock().unwrap(), &key)
    }

    fn under_a_sealed_dir(st: &MemFsState, key: &str) -> bool {
        st.sealed_dirs
            .iter()
            .any(|d| key == d || key.starts_with(&format!("{d}/")))
    }

    /// A user creating a folder.
    pub fn user_mkdir(&self, path: &str) {
        let key = self.store_path(path);
        let mut st = self.state.lock().unwrap();
        Self::refuse_impossible(&st, &key, "create a folder");
        assert!(
            !matches!(st.nodes.get(&key), Some(Node::File { .. })),
            "a scenario made the folder {key} where a file already is. A real \
             disk answers EEXIST; this map would have kept the file and \
             created nothing, leaving the scenario to believe in a folder that \
             is not there.",
        );
        Self::ensure_parents(&mut st, &key);
        Self::make_dir(&mut st, &key);
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
        let now = self.clock.now_ns();
        for v in victims {
            Self::watch_loss(&st, &v, "the user deleting it");
            st.touched.insert(v.clone());
            Self::vacate(&mut st, &v, now);
            st.nodes.remove(&v);
            st.sealed_dirs.remove(&v);
            st.births.remove(&v);
            st.file_births.remove(&v);
            if let Some(id) = st.file_ids.remove(&v) {
                Self::release_id(&mut st, &v, id);
            }
        }
    }

    /// A user moving something.
    pub fn user_rename(&self, from: &str, to: &str) {
        let f = self.store_path(from);
        let t = self.store_path(to);
        let mut st = self.state.lock().unwrap();
        Self::refuse_impossible(&st, &t, "move something");
        Self::ensure_parents(&mut st, &t);
        Self::move_subtree(&mut st, &f, &t, self.clock.now_ns());
        let placed: Vec<(String, bool)> = st
            .nodes
            .iter()
            .filter(|(k, n)| {
                matches!(n, Node::File { .. }) && (**k == t || k.starts_with(&format!("{t}/")))
            })
            .map(|(k, _)| (k.clone(), Self::under_a_sealed_dir(&st, k)))
            .collect();
        st.user_renames.extend(placed);
    }

    /// Two files trade names through a parked name, the way an application
    /// swaps them on a filesystem with no atomic exchange. The user gives no
    /// name here: both names were given before -- by the user, and recorded
    /// then, or by the engine -- so none is recorded as the user's choice.
    /// Recorded, an engine-made conflict name a swap carried into a vault read
    /// as a sealed name the user chose, and the same engine name on an
    /// unrelated plain file read as that sealed name leaking (kill2 75108).
    ///
    /// Unless `b` was not there: then nothing traded, and `a` was simply given
    /// the name `b` in `b`'s folder -- a name the user chose there, recorded
    /// as any rename is. Dropped, a plain file the user named `sealed.txt` in
    /// a plain folder read as a sealed file's name leaking (FAT hostile2
    /// 74400: the workload's trade aimed at a vault file whose folder had
    /// since been renamed away).
    pub fn user_trade_names(&self, a: &str, b: &str, parked: &str) {
        let b_was_there = self.state.lock().unwrap().nodes.contains_key(&self.store_path(b));
        let kept = self.state.lock().unwrap().user_renames.len();
        self.user_rename(a, parked);
        self.user_rename(b, a);
        let named = self.state.lock().unwrap().user_renames.len();
        self.user_rename(parked, b);
        let mut st = self.state.lock().unwrap();
        let given: Vec<(String, bool)> = if b_was_there { Vec::new() } else { st.user_renames[named..].to_vec() };
        st.user_renames.truncate(kept);
        st.user_renames.extend(given);
    }

    /// Every file path the user has renamed something onto, in order, with
    /// whether it then stood under a directory marked sealed.
    pub fn user_renames(&self) -> Vec<(String, bool)> {
        self.state.lock().unwrap().user_renames.clone()
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

    /// The birth of the directory at this path (see `MemFsState::births`).
    /// `None` for a file or for nothing.
    /// The file's birth as the disk keeps it, whatever the engine is shown.
    pub fn file_birth_of(&self, path: &str) -> Option<u64> {
        let key = self.store_path(path);
        self.state.lock().unwrap().file_births.get(&key).copied()
    }

    pub fn birth_of(&self, path: &str) -> Option<u64> {
        let key = self.store_path(path);
        self.state.lock().unwrap().births.get(&key).copied()
    }

    /// Where the directory born as `birth` stands now, as stored; `None`
    /// once it has been removed.
    pub fn path_of_birth(&self, birth: u64) -> Option<String> {
        let st = self.state.lock().unwrap();
        st.births.iter().find(|(_, b)| **b == birth).map(|(k, _)| k.clone())
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
    /// A path's key from its OWN bytes, with no fold resolution.
    ///
    /// `store_name` still applies, so a volume that rewrites what it is given
    /// (HFS+ decomposes) still rewrites here — that is the disk behaving, not
    /// the fold. What this skips is `resolve_case`, which would hand back a
    /// name that already exists and erase a respell.
    fn store_path_for(&self, path: &Path) -> VfsResult<String> {
        let root = PathBuf::from("/sync");
        let rel = path
            .strip_prefix(&root)
            .map_err(|_| VfsError::NotFound(path.to_path_buf()))?;
        let mut out = String::new();
        for seg in rel.iter() {
            let seg = self.store_name(&seg.to_string_lossy());
            if out.is_empty() {
                out = seg;
            } else {
                out = format!("{out}/{seg}");
            }
        }
        Ok(out)
    }

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

    /// FAT and exFAT keep modification times in two-second steps, whatever
    /// the system mounting them.
    const FAT_MTIME_NS: u64 = 2_000_000_000;

    fn truncated_now(&self, st: &MemFsState) -> u64 {
        let fat = st.file_id_model != FileIds::Stable;
        let g = if fat { Self::FAT_MTIME_NS } else { self.personality.mtime_granularity_ns.max(1) };
        (self.clock.now_ns() / g) * g
    }

    /// A file's birth: never handed out twice.
    fn new_file_birth(st: &mut MemFsState) -> u64 {
        st.next_file_birth += 1;
        st.next_file_birth
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

    fn parent_key(key: &str) -> &str {
        key.rsplit_once('/').map(|(d, _)| d).unwrap_or("")
    }

    /// The id a new entry at `key` gets, holding `len` bytes.
    fn new_file_id(st: &mut MemFsState, key: &str, len: usize) -> u64 {
        match st.file_id_model {
            FileIds::Stable => Self::alloc_id(st),
            FileIds::DirectorySlot => Self::slot_id(st, key),
            FileIds::DataCluster => Self::cluster_id(st, len),
            FileIds::MountSession => {
                st.next_file_id += 1;
                st.next_file_id
            }
        }
    }

    /// A slot in `key`'s directory: the lowest one freed there, else a new one.
    fn slot_id(st: &mut MemFsState, key: &str) -> u64 {
        let dir = Self::parent_key(key).to_string();
        if let Some(id) = st.free_slots.get_mut(&dir).and_then(|s| s.pop_first()) {
            return id;
        }
        st.next_file_id += 1;
        st.next_file_id
    }

    fn cluster_id(st: &mut MemFsState, len: usize) -> u64 {
        if len == 0 {
            return Self::temp_id(st);
        }
        st.next_file_id += 1;
        st.next_file_id
    }

    fn temp_id(st: &mut MemFsState) -> u64 {
        st.next_temp_id -= 1;
        st.next_temp_id + 1
    }

    /// An id whose entry is gone.
    fn release_id(st: &mut MemFsState, key: &str, id: u64) {
        match st.file_id_model {
            FileIds::Stable => st.freed_ids.push(id),
            FileIds::DirectorySlot => {
                st.free_slots.entry(Self::parent_key(key).to_string()).or_default().insert(id);
            }
            FileIds::DataCluster | FileIds::MountSession => {}
        }
    }

    /// A file leaving the name `key` (`DirectorySlot` only: tunnelling).
    fn vacate(st: &mut MemFsState, key: &str, now: u64) {
        if st.file_id_model != FileIds::DirectorySlot {
            return;
        }
        if let Some(birth) = st.file_births.get(key).copied() {
            st.vacated.insert(key.to_string(), (birth, now));
        }
    }

    /// A file arriving at the name `key`: within 15 seconds of another file
    /// leaving it, Windows gives it that file's creation time.
    fn tunnel(st: &mut MemFsState, key: &str, now: u64) {
        if st.file_id_model != FileIds::DirectorySlot {
            return;
        }
        if let Some((birth, left)) = st.vacated.remove(key) {
            if now.saturating_sub(left) <= 15_000_000_000 && st.file_births.contains_key(key) {
                st.file_births.insert(key.to_string(), birth);
            }
        }
    }

    /// Refuse, loudly, a scenario asking this tree for a shape a disk cannot
    /// hold.
    ///
    /// These are the harness playing the user, so they cannot return an error
    /// -- and staying silent is how a harness bug turns into hours of looking
    /// at the engine. One did exactly that: a chaos knob wrote file bytes over
    /// directory paths, the map swapped the nodes without complaint, and the
    /// run failed much later as `trash_local ... is not a directory` that read
    /// for all the world like a client defect.
    fn refuse_impossible(st: &MemFsState, key: &str, doing: &str) {
        if let Some(blocker) = Self::file_in_the_way(st, key) {
            panic!(
                "a scenario tried to {doing} at {key}, but {blocker} is a \
                 file. Nothing lives beneath a file on a real disk.",
            );
        }
    }

    /// The ancestor of this key that is a file, if one is.
    ///
    /// A path is a file or a directory and never both, so nothing can be
    /// created beneath a file: a real disk answers `EEXIST` naming the file in
    /// the way, not the thing being written. This tree is a flat map with no
    /// such rule of its own, so the rule has to be stated — without it a file
    /// can hold children, which is a shape no disk this runs on can produce.
    fn file_in_the_way(st: &MemFsState, key: &str) -> Option<String> {
        let parts: Vec<&str> = key.split('/').collect();
        (1..parts.len())
            .map(|i| parts[..i].join("/"))
            .find(|prefix| matches!(st.nodes.get(prefix), Some(Node::File { .. })))
    }

    /// The path a stored key names, for an error a caller can read.
    fn path_of(key: &str) -> PathBuf {
        PathBuf::from("/sync").join(key)
    }

    fn ensure_parents(st: &mut MemFsState, key: &str) {
        let parts: Vec<&str> = key.split('/').collect();
        for i in 1..parts.len() {
            Self::make_dir(st, &parts[..i].join("/"));
        }
    }

    /// A directory at this key, made if it is not there, with an identity of
    /// its own. Directories carry ids from the same pool as files, kept in
    /// the same map, so a rename carries the id and a removal releases it
    /// (and, under `reuse_file_ids`, hands it out again) exactly as for a
    /// file -- the recycled-directory world is reachable from a scenario.
    fn make_dir(st: &mut MemFsState, key: &str) {
        if st.nodes.contains_key(key) {
            return;
        }
        st.nodes.insert(key.to_string(), Node::Dir);
        let id = match st.file_id_model {
            FileIds::Stable => Self::alloc_id(st),
            FileIds::DirectorySlot => Self::slot_id(st, key),
            FileIds::DataCluster | FileIds::MountSession => {
                st.next_file_id += 1;
                st.next_file_id
            }
        };
        st.file_ids.insert(key.to_string(), id);
        st.next_birth += 1;
        st.births.insert(key.to_string(), st.next_birth);
    }

    /// Say where content stopped existing, when `LOSE` names its hash.
    ///
    /// Set `LOSE` to the first few characters of a sha256 and every disk in the
    /// world reports the moment that content is built over, with the stack that
    /// did it. Content vanishing is the one failure that leaves no trace of
    /// itself: the file is simply not there at the end, and the tree, the
    /// entries and the queue all look right because by then they are.
    /// Reconstructing it from a settled world is guesswork, and this is the
    /// answer instead -- it named the actor behind a suspected engine loss in
    /// one command, and the actor was the harness.
    fn watch_loss(st: &MemFsState, key: &str, why: &str) {
        static WATCH: std::sync::OnceLock<Option<String>> = std::sync::OnceLock::new();
        let Some(prefix) = WATCH
            .get_or_init(|| std::env::var("LOSE").ok())
            .as_deref()
        else {
            return;
        };
        let Some(Node::File { bytes, .. }) = st.nodes.get(key) else {
            return;
        };
        let hash = crate::sha256_hex(bytes);
        if !hash.starts_with(prefix) {
            return;
        }
        eprintln!(
            "LOSE {} left {key} by {why}\n{}",
            &hash[..12],
            std::backtrace::Backtrace::force_capture()
        );
    }

    fn move_subtree(st: &mut MemFsState, from: &str, to: &str, now: u64) {
        Self::watch_loss(st, to, "a rename landing on it");
        Self::vacate(st, from, now);
        st.touched.insert(from.to_string());
        st.touched.insert(to.to_string());
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
                st.file_ids.insert(new.clone(), id);
            }
            if let Some(birth) = st.births.remove(&old) {
                st.births.insert(new.clone(), birth);
            }
            if let Some(birth) = st.file_births.remove(&old) {
                st.file_births.insert(new.clone(), birth);
            }
            if st.sealed_dirs.remove(&old) {
                st.sealed_dirs.insert(new);
            }
        }
        // Only the moved entry itself: what is under a moved directory stays
        // in that directory's own entries.
        if st.file_id_model == FileIds::DirectorySlot && st.file_ids.contains_key(to) {
            let longer = to.rsplit('/').next().map(str::len) > from.rsplit('/').next().map(str::len);
            if Self::parent_key(from) != Self::parent_key(to) || longer {
                let old = st.file_ids[to];
                let id = Self::slot_id(st, to);
                Self::release_id(st, from, old);
                st.file_ids.insert(to.to_string(), id);
            }
        }
        Self::tunnel(st, to, now);
    }

    /// Consume a scheduled failure for this op/path, if one is due.
    fn check_failure(&self, op: FsOp, key: &str, path: &Path) -> VfsResult<()> {
        self.run_between_reads(key);
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

    /// A directory's identity, or 0 when this disk cannot read one.
    /// The raw id the volume keeps for `key`, as the engine is shown it
    /// beside a fingerprint: only on the FAT models whose ids hold still long
    /// enough to break a tie (`Personality::id_tie_break`), 0 elsewhere.
    fn tie_break_of(st: &MemFsState, key: &str) -> u64 {
        match st.file_id_model {
            FileIds::DataCluster | FileIds::MountSession => st.file_ids.get(key).copied().unwrap_or(0),
            _ => 0,
        }
    }

    fn directory_id_of(st: &MemFsState, key: &str) -> u64 {
        if st.directory_ids_unreadable || st.file_id_model != FileIds::Stable {
            return 0;
        }
        st.file_ids.get(key).copied().unwrap_or(0)
    }

    /// A file's fingerprint as the engine is shown it. On a FAT or exFAT
    /// model the volume is named positional and reports no id and no birth,
    /// as `OsVfs` does (`Personality::positional_file_ids`); the disk's own
    /// numbers stay readable to the harness through `file_id_of`.
    fn fingerprint_of(st: &MemFsState, key: &str) -> Option<Fingerprint> {
        let positional = st.file_id_model != FileIds::Stable;
        match st.nodes.get(key) {
            Some(Node::File { bytes, mtime_ns }) => Some(Fingerprint {
                size: bytes.len() as u64,
                mtime_ns: *mtime_ns,
                file_id: if positional { 0 } else { st.file_ids.get(key).copied().unwrap_or(0) },
                birth_ns: if st.births_hidden || positional {
                    0
                } else {
                    st.file_births.get(key).copied().unwrap_or(0)
                },
            }),
            _ => None,
        }
    }
}

impl MemFs {
    fn list(&self, path: &Path, include_internal: bool) -> VfsResult<Vec<DirEntry>> {
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
        let mut sealed_here: Vec<(String, String)> = Vec::new();
        for (k, node) in st.nodes.iter() {
            if k.is_empty() || !k.starts_with(&prefix) {
                continue;
            }
            let rest = &k[prefix.len()..];
            if rest.is_empty() || rest.contains('/') {
                continue;
            }
            // `OsVfs` hides the engine's reserved names from an ordinary
            // listing, so this must too: without it the simulator hands the
            // engine files a real disk never shows it, and a recovery that can
            // only fire on those files looks alive here while being dead in
            // production. Sweep parity, not decoration.
            if !include_internal && jd_vfs::is_internal(rest) {
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
                fingerprint: match node {
                    Node::Dir => Some(Fingerprint::of_directory(Self::directory_id_of(&st, k))),
                    Node::File { .. } => Self::fingerprint_of(&st, k),
                },
                tie_break_id: Self::tie_break_of(&st, k),
            });
            if let Node::File { bytes, .. } = node {
                if Self::under_a_sealed_dir(&st, k) {
                    sealed_here.push((crate::sha256_hex(bytes), rest.to_string()));
                }
            }
        }
        let clock = st.change_clock.clone();
        drop(st);
        let stamp = clock.map(|c| (c.0)());
        self.state.lock().unwrap().listed_in_a_vault.extend(sealed_here.into_iter().map(|(h, n)| (h, n, stamp)));
        Ok(out)
    }
}

impl Vfs for MemFs {
    fn personality(&self) -> Personality {
        // A volume with no birth time is one whose file identity is weak, and
        // a real one says so through its probe.
        let mut p = self.personality;
        let st = self.state.lock().unwrap();
        if st.births_hidden {
            p.stable_file_identity = false;
        }
        // Every FAT and exFAT model is named by its filesystem type, whatever
        // the rename probe would have said (F1).
        if st.file_id_model != FileIds::Stable {
            p.stable_file_identity = false;
            p.positional_file_ids = true;
            p.id_tie_break = match st.file_id_model {
                FileIds::DataCluster => jd_vfs::IdTieBreak::Durable,
                FileIds::MountSession => jd_vfs::IdTieBreak::MountSession,
                _ => jd_vfs::IdTieBreak::None,
            };
            p.mtime_granularity_ns = p.mtime_granularity_ns.max(Self::FAT_MTIME_NS);
        }
        p
    }

    fn root(&self) -> Option<PathBuf> {
        if self.state.lock().unwrap().root_available {
            Some(PathBuf::from("/sync"))
        } else {
            None
        }
    }

    fn read_dir(&self, path: &Path) -> VfsResult<Vec<DirEntry>> {
        self.list(path, false)
    }

    fn read_dir_all(&self, path: &Path) -> VfsResult<Vec<DirEntry>> {
        self.list(path, true)
    }

    fn fingerprint(&self, path: &Path) -> VfsResult<Option<Fingerprint>> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::Fingerprint, &key, path)?;
        let st = self.state.lock().unwrap();
        Ok(Self::fingerprint_of(&st, &key))
    }

    fn directory_id(&self, path: &Path) -> VfsResult<Option<u64>> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::Fingerprint, &key, path)?;
        let st = self.state.lock().unwrap();
        Ok(match st.nodes.get(&key) {
            Some(Node::Dir) => Some(Self::directory_id_of(&st, &key)),
            _ => None,
        })
    }

    fn tie_break_id(&self, path: &Path) -> VfsResult<u64> {
        let key = self.key_for(path)?;
        let st = self.state.lock().unwrap();
        Ok(if st.nodes.contains_key(&key) { Self::tie_break_of(&st, &key) } else { 0 })
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
        // Taken and released before the state lock below, the way the landing
        // hook is: the closure reaches back into the server and another disk,
        // and holding this disk's lock across that deadlocks the run.
        if let Some(f) = self.dir_creating.lock().unwrap().as_mut() {
            f(path);
        }
        let mut st = self.state.lock().unwrap();
        // `create_dir_all` is what the real one calls, and it is content with a
        // directory that is already there. Only a FILE in the way is a refusal,
        // and it is the refusal the engine reads as harmless.
        match st.nodes.get(&key) {
            Some(Node::Dir) => return Ok(()),
            Some(Node::File { .. }) => {
                return Err(VfsError::AlreadyExists(path.to_path_buf()))
            }
            None => {}
        }
        if let Some(blocker) = Self::file_in_the_way(&st, &key) {
            return Err(VfsError::AlreadyExists(Self::path_of(&blocker)));
        }
        Self::ensure_parents(&mut st, &key);
        Self::make_dir(&mut st, &key);
        Ok(())
    }

    fn rename(&self, from: &Path, to: &Path) -> VfsResult<()> {
        let f = self.key_for(from)?;
        // The destination's LITERAL bytes, not what `key_for` resolves them to.
        //
        // `key_for` folds a candidate onto whatever is already standing at that
        // slot, which is right for finding a file and exactly wrong for
        // renaming one: asking to respell `café` as `cafe` + combining acute
        // resolved BOTH sides to the same existing key, so the remove and
        // reinsert cancelled and the rename silently did nothing. A real
        // `rename(2)` on APFS respells — the volume is insensitive when it
        // compares and preserving when it stores — so the mock was quietly
        // refusing a thing every Mac does, and any engine fix that worked by
        // respelling would have been eaten here with its test passing.
        //
        // Occupancy is still asked in FOLDED terms below: a destination that
        // resolves to a DIFFERENT node is genuinely occupied and still refused.
        // Only the same-slot case takes the literal name.
        let t = {
            let literal = self.store_path_for(to)?;
            match self.key_for(to)? {
                resolved if resolved == f => literal,
                resolved => resolved,
            }
        };
        self.check_failure(FsOp::Rename, &f, from)?;
        let mut st = self.state.lock().unwrap();
        let Some(source) = st.nodes.get(&f).cloned() else {
            return Err(VfsError::NotFound(from.to_path_buf()));
        };
        // A directory does not move into itself. Every real filesystem refuses
        // it (`EINVAL`), and a mock that allowed it produced a tree with a loop
        // in it that the engine then read back as its own agreement.
        if matches!(source, Node::Dir) && t.starts_with(&format!("{f}/")) {
            return Err(VfsError::Io {
                path: to.to_path_buf(),
                source: std::io::Error::new(
                    std::io::ErrorKind::InvalidInput,
                    "cannot move a directory into itself",
                ),
            });
        }
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
        if let Some(blocker) = Self::file_in_the_way(&st, &t) {
            return Err(VfsError::AlreadyExists(Self::path_of(&blocker)));
        }
        Self::ensure_parents(&mut st, &t);
        Self::move_subtree(&mut st, &f, &t, self.clock.now_ns());
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
        // Already gone is the state that was asked for, so this succeeded. The
        // real one says so explicitly, on the grounds that a retry after a
        // crash must not fail on its own prior success.
        if victims.is_empty() {
            return Ok(());
        }
        let now = self.clock.now_ns();
        for v in victims {
            Self::vacate(&mut st, &v, now);
            st.touched.insert(v.clone());
            if let Some(node) = st.nodes.remove(&v) {
                st.trash.push((v.clone(), node));
            }
            st.sealed_dirs.remove(&v);
            st.births.remove(&v);
            st.file_births.remove(&v);
            if let Some(id) = st.file_ids.remove(&v) {
                Self::release_id(&mut st, &v, id);
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

    fn open_file(&self, path: &Path) -> VfsResult<Option<(Box<dyn jd_vfs::ReadSeek>, Fingerprint)>> {
        let key = self.key_for(path)?;
        self.check_failure(FsOp::OpenRead, &key, path)?;
        // One look at the node: the bytes the handle reads and the
        // fingerprint of the file they are, as a descriptor holds its file.
        let st = self.state.lock().unwrap();
        match st.nodes.get(&key) {
            Some(Node::File { bytes, .. }) => {
                let fingerprint = MemFs::fingerprint_of(&st, &key).expect("a file node has a fingerprint");
                Ok(Some((Box::new(std::io::Cursor::new(bytes.clone())), fingerprint)))
            }
            Some(Node::Dir) | None => Ok(None),
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
        // Before anything is read or locked: the user gets their moment. This
        // is the window between the engine clearing this path and the file
        // arriving on it, and a scenario that wants to save into that window
        // has nowhere else to stand.
        if let Some(f) = self.fs.landing.lock().unwrap().as_mut() {
            f(target);
        }

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
        let mtime = { let st = self.fs.state.lock().unwrap(); self.fs.truncated_now(&st) };
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
            // Without ids, size and time, as `OsVfs` guards it.
            let unchanged = if st.file_id_model != FileIds::Stable {
                now.size == want.size
                    && now.mtime_ns.abs_diff(want.mtime_ns) < self.fs.personality.mtime_granularity_ns.max(1)
            } else {
                now.unchanged_from(&want, &self.fs.personality)
            };
            if !unchanged {
                return Err(VfsError::AlreadyExists(target.to_path_buf()));
            }
        }

        // No agreement at all means the engine has never seen whatever is at
        // this path: it belongs to the user and may be the only copy. The real
        // filesystem refuses this outright rather than trusting that the caller
        // cleared the way, and so must this one.
        //
        // Leaving it out is not a harmless simplification. Every download onto
        // an occupied path took the happy route here and the refusing one on a
        // real disk, so the whole branch the engine runs on that refusal went
        // unexercised — and what lived in it was a file the engine asked for
        // forever because the bytes were already on the disk. Eighteen thousand
        // seeds passed over it; the rig found it in a day.
        if expect.is_none() && st.nodes.contains_key(&key) {
            return Err(VfsError::AlreadyExists(target.to_path_buf()));
        }

        // A file standing where one of this path's folders should be. The real
        // one hits it inside the `create_dir_all` that precedes the rename, and
        // reports the file in the way rather than the target — which does not
        // exist and cannot until somebody moves that file.
        if let Some(blocker) = MemFs::file_in_the_way(&st, &key) {
            return Err(VfsError::AlreadyExists(MemFs::path_of(&blocker)));
        }

        MemFs::watch_loss(&st, &key, "a download committing on top of it");
        st.touched.insert(key.clone());
        MemFs::ensure_parents(&mut st, &key);
        // Replacing a file keeps its id — the rename lands on top of it, which
        // is what a real filesystem does and what makes "same inode, new
        // content" a case the engine has to handle.
        let len = self.buf.len();
        let id = match (st.file_ids.get(&key).copied(), st.file_id_model) {
            (Some(id), FileIds::Stable) => id,
            // A FAT volume has no "same inode, new content": the spool is a
            // new entry renamed over the old one, and the id is the new
            // entry's (a new slot, new clusters, a new number this mount).
            (Some(old), _) => {
                let id = MemFs::new_file_id(&mut st, &key, len);
                MemFs::release_id(&mut st, &key, old);
                st.file_ids.insert(key.clone(), id);
                id
            }
            (None, _) => {
                let id = MemFs::new_file_id(&mut st, &key, len);
                st.file_ids.insert(key.clone(), id);
                id
            }
        };
        // The birth goes with the id: kept where the id is kept, new where
        // the id is new.
        if !st.file_births.contains_key(&key) {
            let birth = MemFs::new_file_birth(&mut st);
            st.file_births.insert(key.clone(), birth);
            let now = self.fs.clock.now_ns();
            MemFs::tunnel(&mut st, &key, now);
        }
        let positional = st.file_id_model != FileIds::Stable;
        let birth_ns = if st.births_hidden || positional { 0 } else { st.file_births[&key] };
        let id = if positional { 0 } else { id };
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
            birth_ns,
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

    fn fat(model: FileIds) -> (MemFs, SimClock) {
        let clock = SimClock::new();
        let f = MemFs::linux(clock.clone());
        f.file_ids(model);
        (f, clock)
    }

    fn birth(f: &MemFs, path: &str) -> u64 {
        f.file_birth_of(path).unwrap()
    }

    /// Every FAT model is named by its filesystem type: positional, and the
    /// engine is shown no id and no birth (`Personality::positional_file_ids`).
    fn named_positional(f: &MemFs, path: &str) {
        let pers = f.personality();
        assert!(pers.positional_file_ids && !pers.stable_file_identity);
        let fp = f.fingerprint(&p(path)).unwrap().unwrap();
        assert_eq!((fp.file_id, fp.birth_ns), (0, 0), "the engine is shown no identity");
    }

    // The FAT models, each as traced on a real system on 2026-09-28
    // (`specs/drive_weak_volume_identity.md`, F1 and F4).

    #[test]
    fn on_windows_fat_an_id_is_the_entry_slot() {
        let (f, clock) = fat(FileIds::DirectorySlot);
        f.user_write("a.txt", b"a");
        f.user_write("b.txt", b"b");
        let a = f.file_id_of("a.txt").unwrap();
        f.user_rename("a.txt", "c.txt");
        assert_eq!(f.file_id_of("c.txt"), Some(a), "a rename to a name no longer keeps the slot");
        f.user_rename("c.txt", "a-much-longer-name.txt");
        let moved = f.file_id_of("a-much-longer-name.txt").unwrap();
        assert_ne!(moved, a, "a longer name moves the entry");
        f.user_mkdir("sub");
        assert_eq!(f.file_id_of("sub"), Some(a), "a freed slot is taken again first-fit");
        let b = f.file_id_of("b.txt").unwrap();
        f.user_rename("b.txt", "sub/b.txt");
        assert_ne!(f.file_id_of("sub/b.txt"), Some(b), "another folder is another slot");
        f.user_write("new.txt", b"n");
        assert_eq!(f.file_id_of("new.txt"), Some(b), "so a new file at the top takes the slot b left");
        named_positional(&f, "new.txt");
        let _ = clock;
    }

    #[test]
    fn on_windows_fat_a_name_taken_again_within_15_seconds_keeps_the_old_creation_time() {
        let (f, clock) = fat(FileIds::DirectorySlot);
        f.user_write("a.txt", b"a");
        clock.advance_secs(1);
        f.user_write("b.txt", b"b");
        let (ba, bb) = (birth(&f, "a.txt"), birth(&f, "b.txt"));
        assert_ne!(ba, bb);
        f.user_trade_names("a.txt", "b.txt", "tmp.txt");
        assert_eq!((birth(&f, "a.txt"), birth(&f, "b.txt")), (ba, bb), "the creation times stay with the names");
        clock.advance_secs(60);
        f.user_rename("a.txt", "x.txt");
        clock.advance_secs(20);
        f.user_write("a.txt", b"late");
        assert_ne!(birth(&f, "a.txt"), ba, "after 15 seconds nothing tunnels");
    }

    #[test]
    fn on_macos_fat32_an_id_is_the_first_cluster() {
        let (f, _) = fat(FileIds::DataCluster);
        f.user_write("a.txt", b"a");
        let a = f.file_id_of("a.txt").unwrap();
        f.user_mkdir("sub");
        f.user_rename("a.txt", "sub/a-much-longer-name.txt");
        f.remount();
        assert_eq!(f.file_id_of("sub/a-much-longer-name.txt"), Some(a), "a rename, a move and a remount keep it");
        f.user_write("sub/a-much-longer-name.txt", b"saved");
        assert_ne!(f.file_id_of("sub/a-much-longer-name.txt"), Some(a), "a save rewrites the clusters");
        f.user_write("empty.txt", b"");
        let e = f.file_id_of("empty.txt").unwrap();
        f.remount();
        assert_ne!(f.file_id_of("empty.txt"), Some(e), "an empty file's id is temporary");
        named_positional(&f, "empty.txt");
    }

    #[test]
    fn on_linux_fat_ids_last_one_mount() {
        let (f, _) = fat(FileIds::MountSession);
        f.user_write("a.txt", b"a");
        f.user_write("b.txt", b"b");
        let (a, b) = (f.file_id_of("a.txt").unwrap(), f.file_id_of("b.txt").unwrap());
        let ba = birth(&f, "a.txt");
        f.user_trade_names("a.txt", "b.txt", "tmp.txt");
        assert_eq!((f.file_id_of("b.txt"), f.file_id_of("a.txt")), (Some(a), Some(b)), "ids travel with the files while mounted");
        f.remount();
        assert!(f.file_id_of("b.txt") != Some(a) && f.file_id_of("a.txt") != Some(b), "a remount renumbers every file");
        assert_eq!(birth(&f, "b.txt"), ba, "births survive a remount");
        named_positional(&f, "a.txt");
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
    fn a_directory_carries_an_identity_that_survives_a_rename_and_dies_with_it() {
        let f = fs();
        f.user_mkdir("one");
        f.user_mkdir("two");
        let one = f.directory_id(&p("one")).unwrap().unwrap();
        let two = f.directory_id(&p("two")).unwrap().unwrap();
        assert_ne!(one, two);
        assert_eq!(f.fingerprint(&p("one")).unwrap(), None, "fingerprint stays None for a directory");
        f.user_rename("one", "uno");
        assert_eq!(f.directory_id(&p("uno")).unwrap(), Some(one));
        assert_eq!(f.directory_id(&p("one")).unwrap(), None);
        // Folders made on the way to a nested file get ids too, and the
        // listing reports them.
        f.user_write("uno/deep/f.txt", b"x");
        let deep = f.directory_id(&p("uno/deep")).unwrap().unwrap();
        let listed = f.read_dir(&p("uno")).unwrap();
        assert_eq!(listed[0].fingerprint, Some(Fingerprint::of_directory(deep)));
        assert_eq!(f.directory_id(&p("uno/deep/f.txt")).unwrap(), None);
        // Removed, the id is released; with reuse on, the next directory made
        // gets it back -- the recycled-directory world a scenario can reach.
        f.user_remove("two");
        assert_eq!(f.directory_id(&p("two")).unwrap(), None);
        f.reuse_file_ids(true);
        f.user_mkdir("three");
        assert_eq!(f.directory_id(&p("three")).unwrap(), Some(two));
    }

    #[test]
    fn a_files_birth_goes_where_its_id_goes_and_never_comes_back() {
        let f = fs();
        f.user_write("a.txt", b"x");
        let a = f.fingerprint(&p("a.txt")).unwrap().unwrap().identity();
        assert!(a.is_strong(), "a written file has an id and a birth: {a:?}");
        // A rename keeps both halves.
        f.user_rename("a.txt", "b.txt");
        assert_eq!(f.fingerprint(&p("b.txt")).unwrap().unwrap().identity(), a);
        // Written again in place: the same file.
        f.user_write("b.txt", b"xy");
        assert_eq!(f.fingerprint(&p("b.txt")).unwrap().unwrap().identity(), a);
        // Removed, and with reuse on the next file gets its number back --
        // with a birth of its own, so the pair is not the dead file's.
        f.reuse_file_ids(true);
        f.user_remove("b.txt");
        f.user_write("c.txt", b"z");
        let c = f.fingerprint(&p("c.txt")).unwrap().unwrap().identity();
        assert_eq!(c.file_id, a.file_id, "the id was recycled");
        assert_ne!(c.birth_ns, a.birth_ns, "the birth was not");
        // Hidden, no file has a birth to give.
        f.hide_births(true);
        assert_eq!(f.fingerprint(&p("c.txt")).unwrap().unwrap().birth_ns, 0);
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
