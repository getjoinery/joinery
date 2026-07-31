//! jd-vfs — the filesystem the sync engine is allowed to see.
//!
//! `jd-core` never touches `std::fs`. It goes through the [`Vfs`] trait here,
//! which exists for two reasons. The first is that filesystems disagree with
//! each other in ways that change what "the same file" means (see
//! [`personality`]), and the engine should not have to care. The second is that
//! the simulator needs to be able to lie: to report an mtime that goes
//! backwards, to reuse an inode, to drop a watcher event, to fail a rename
//! halfway. A trait boundary is what lets those be ordinary test cases instead
//! of stories we tell ourselves about code we cannot run.
//!
//! The contract the engine relies on:
//!
//! - **Atomic materialization.** New content is written to a spool file and
//!   renamed into place, so a visible file is always complete. A partial
//!   download is never something the user can open.
//! - **Fingerprints filter, hashes decide.** [`Fingerprint`] is cheap and
//!   answers "might this have changed"; it is never taken as proof that it did
//!   not, because mtimes lie.
//! - **Deletes go to the trash.** The engine never unlinks a user's file. What
//!   it removes is recoverable, because the whole product promise is that
//!   losing files is not a thing that happens.

pub mod dirty;
pub mod names;
pub mod personality;
pub mod real;
pub mod watch;

use std::path::{Path, PathBuf};

pub use dirty::{DirtyPath, DirtySet, Hint};
pub use names::{
    comparison_key, conflict_copy_name, is_internal, nfc, resolve_siblings, EscapeReason,
    LocalName, Resolved, UnsyncableReason,
};
pub use personality::Personality;
pub use real::OsVfs;
pub use watch::{watch_root, Watcher};

#[derive(Debug, thiserror::Error)]
pub enum VfsError {
    #[error("not found: {0}")]
    NotFound(PathBuf),
    #[error("{0} is not a directory")]
    NotADirectory(PathBuf),
    #[error("{0} already exists")]
    AlreadyExists(PathBuf),
    #[error("permission denied: {0}")]
    PermissionDenied(PathBuf),
    #[error("the sync root {0} is not available")]
    RootUnavailable(PathBuf),
    #[error("no space left for {0}")]
    OutOfSpace(PathBuf),
    #[error("io error on {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
}

pub type VfsResult<T> = Result<T, VfsError>;

/// The cheap "has this changed?" filter.
///
/// Every field can lie on its own — an mtime can go backwards across a clock
/// change, a size can match by coincidence, an inode can be reused by a
/// different file — so drift here only ever triggers a hash, and a match here
/// only ever *skips* one. Nothing is deleted or uploaded on a fingerprint's
/// word alone.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Fingerprint {
    pub size: u64,
    pub mtime_ns: u64,
    /// inode on Unix, file id on Windows. Identifies the file across renames,
    /// which is how a 4 GB move stays a move instead of a delete plus an
    /// upload.
    pub file_id: u64,
}

impl Fingerprint {
    /// Whether two fingerprints are close enough to count as unchanged on a
    /// filesystem of this personality.
    ///
    /// FAT stores modification times in two-second steps, so a file written
    /// twice inside one step reports the same mtime. The size and the file id
    /// still have to match, and any doubt sends us to the hash — this only
    /// decides whether a rescan bothers to read the bytes.
    pub fn unchanged_from(&self, other: &Fingerprint, p: &Personality) -> bool {
        if self.size != other.size || self.file_id != other.file_id {
            return false;
        }
        let delta = self.mtime_ns.abs_diff(other.mtime_ns);
        delta < p.mtime_granularity_ns.max(1)
    }
}

/// What a directory entry is. Symlinks are called out rather than followed:
/// following one can escape the sync root or loop forever, so the engine marks
/// them unsyncable and says so.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EntryKind {
    File,
    Directory,
    Symlink,
    Other,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DirEntry {
    /// The name exactly as the filesystem reported it (which on macOS may be
    /// decomposed). Comparison goes through [`comparison_key`], never this.
    pub name: String,
    pub kind: EntryKind,
    pub fingerprint: Option<Fingerprint>,
}

/// A handle to a spool file being filled before it becomes visible.
pub trait SpoolFile: std::io::Write {
    /// Flush to durable storage and rename onto `target`, replacing whatever is
    /// there. The rename is the moment the file becomes visible, and it is
    /// atomic — there is no instant at which a reader sees half of it.
    ///
    /// `expect` guards the replace: if the file at `target` no longer matches
    /// the fingerprint the engine decided against, the swap is refused so a
    /// change made while the download was in flight is not overwritten.
    ///
    /// **A commit that fails leaves nothing behind.** It consumes the handle,
    /// so after it returns the caller has no way to clean up; a temporary file
    /// abandoned here is one the user can neither see nor delete, and enough of
    /// them is a disk that fills up for no visible reason.
    fn commit(
        self: Box<Self>,
        target: &Path,
        expect: Option<Fingerprint>,
    ) -> VfsResult<Fingerprint>;

    /// Abandon the spool file and remove it.
    fn discard(self: Box<Self>);
}

/// A byte source that can be rewound. `?Sized` on the blanket implementation so
/// that a `dyn ReadSeek` handed back by [`Vfs::open_read`] still satisfies it,
/// and can therefore be passed straight to the upload protocol.
pub trait ReadSeek: std::io::Read + std::io::Seek {}
impl<T: std::io::Read + std::io::Seek + ?Sized> ReadSeek for T {}

/// Everything the engine may do to a filesystem.
pub trait Vfs: Send + Sync {
    fn personality(&self) -> Personality;

    /// The sync root. `None` when the root is not currently available — an
    /// unmounted volume, a disconnected network share. The engine treats that
    /// as "pause", never as "every file was deleted", which is the difference
    /// between an inconvenience and a catastrophe.
    fn root(&self) -> Option<PathBuf>;

    fn read_dir(&self, path: &Path) -> VfsResult<Vec<DirEntry>>;
    fn fingerprint(&self, path: &Path) -> VfsResult<Option<Fingerprint>>;
    fn hash(&self, path: &Path) -> VfsResult<String>;

    fn create_dir(&self, path: &Path) -> VfsResult<()>;
    fn rename(&self, from: &Path, to: &Path) -> VfsResult<()>;

    /// Move to the OS trash. Never an unlink: a delete the engine got wrong has
    /// to be recoverable by the person it happened to.
    fn trash(&self, path: &Path) -> VfsResult<()>;

    /// Open a spool file to receive content destined for `target`. Placed on
    /// the same volume as the root where possible, so the commit is a rename
    /// rather than a copy.
    fn spool(&self, target: &Path) -> VfsResult<Box<dyn SpoolFile>>;

    /// Open a file for reading.
    ///
    /// Seekable, not merely readable: a chunked upload told to resume at an
    /// earlier offset has to go back and read from there, and a source that
    /// cannot rewind turns every resync into starting the whole file again.
    fn open_read(&self, path: &Path) -> VfsResult<Box<dyn ReadSeek>>;
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fp(size: u64, mtime_ns: u64, file_id: u64) -> Fingerprint {
        Fingerprint {
            size,
            mtime_ns,
            file_id,
        }
    }

    #[test]
    fn identical_fingerprints_are_unchanged() {
        let p = Personality::linux();
        assert!(fp(10, 1000, 7).unchanged_from(&fp(10, 1000, 7), &p));
    }

    #[test]
    fn a_different_size_is_always_a_change() {
        let p = Personality::linux();
        assert!(!fp(11, 1000, 7).unchanged_from(&fp(10, 1000, 7), &p));
    }

    #[test]
    fn a_new_inode_at_the_same_size_is_a_change() {
        // The safe-save dance: the editor wrote a temp file and renamed it over
        // the original. Same size, same mtime, different file — and definitely
        // different content.
        let p = Personality::linux();
        assert!(!fp(10, 1000, 8).unchanged_from(&fp(10, 1000, 7), &p));
    }

    #[test]
    fn coarse_mtime_filesystems_tolerate_their_own_granularity() {
        let fat = Personality::fat32();
        // Two writes inside one FAT time step report mtimes a hair apart; the
        // filter must not call that a change on its own...
        assert!(fp(10, 1_500_000_000, 7).unchanged_from(&fp(10, 1_000_000_000, 7), &fat));
        // ...but a full step apart is real drift worth hashing.
        assert!(!fp(10, 3_000_000_000, 7).unchanged_from(&fp(10, 1_000_000_000, 7), &fat));
        // The same pair on ext4, which has real precision, is drift.
        assert!(!fp(10, 1_500_000_000, 7)
            .unchanged_from(&fp(10, 1_000_000_000, 7), &Personality::linux()));
    }

    #[test]
    fn a_backwards_clock_still_reads_as_drift() {
        let p = Personality::linux();
        assert!(!fp(10, 500, 7).unchanged_from(&fp(10, 1000, 7), &p));
    }
}
