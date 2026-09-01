//! The actual filesystem.
//!
//! Everything interesting here is about the two moments where a mistake is
//! unrecoverable: making a file visible, and making one disappear.
//!
//! A file becomes visible by an atomic rename from a spool file, never by
//! writing into place. So there is no instant at which the user can open a
//! half-downloaded document, and a process killed mid-transfer leaves a stray
//! spool file rather than a corrupted one they will discover in six months.
//!
//! A file disappears into the OS trash, never by unlink. The engine is a
//! program that deletes files for a living, and programs that do that get it
//! wrong sometimes; the difference between an incident and a catastrophe is
//! whether the user can get the file back.

use std::fs::{self, File};
use std::io::{BufReader, Read, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};

use sha2::{Digest, Sha256};

use crate::{DirEntry, EntryKind, Fingerprint, Personality, SpoolFile, Vfs, VfsError, VfsResult};

/// Bytes read per chunk when hashing. Large enough that syscall overhead is
/// noise on a big file, small enough not to matter on a small one.
const HASH_CHUNK: usize = 256 * 1024;

pub struct OsVfs {
    /// The resolved spelling of the root — symlinks followed, and on Windows the
    /// extended-length form. Every filesystem call and every watcher event is
    /// compared against this one spelling, because two spellings of the same
    /// folder is how a watcher ends up silently discarding every event it gets.
    root: PathBuf,
    /// Where spool files live: alongside the state store, outside the synced
    /// tree, but on the same volume as the root wherever possible so a commit
    /// is a rename rather than a copy across filesystems.
    spool_dir: PathBuf,
    personality: Personality,
    /// Supplies the random part of a spool name. Injected so a simulated run
    /// reproduces from its seed.
    next_token: Box<dyn Fn() -> String + Send + Sync>,
}

impl OsVfs {
    /// Open a sync root, asking the volume what kind of filesystem it is.
    ///
    /// The probe is not decoration. Whether this volume can tell `Report.txt`
    /// from `report.txt`, and whether it hands names back in the form they were
    /// written, decide which files can be materialized at all — and neither is
    /// reliably predicted by which operating system is running. A developer's
    /// case-sensitive APFS volume and a stock one are the same OS and different
    /// answers.
    pub fn new(root: PathBuf, spool_dir: PathBuf) -> VfsResult<OsVfs> {
        let root = crate::paths::canonical_root(&root);
        let personality = Personality::probe(&root);
        Self::with_personality(root, spool_dir, personality)
    }

    pub fn with_personality(
        root: PathBuf,
        spool_dir: PathBuf,
        personality: Personality,
    ) -> VfsResult<OsVfs> {
        fs::create_dir_all(&spool_dir).map_err(|e| VfsError::Io {
            path: spool_dir.clone(),
            source: e,
        })?;
        let counter = std::sync::atomic::AtomicU64::new(0);
        Ok(OsVfs {
            root: crate::paths::canonical_root(&root),
            spool_dir,
            personality,
            next_token: Box::new(move || {
                let n = counter.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                format!("{}-{}", std::process::id(), n)
            }),
        })
    }

    /// The root as everything else must spell it.
    pub fn root_path(&self) -> &Path {
        &self.root
    }

    /// Clear out spool files left behind by a previous run.
    ///
    /// These are always safe to remove: a spool file only ever becomes a real
    /// file through a rename, so anything still sitting here by definition
    /// never made it, and the transfer that produced it will be re-derived.
    pub fn sweep_spool(&self) -> VfsResult<usize> {
        let mut removed = 0;
        let entries = match fs::read_dir(&self.spool_dir) {
            Ok(e) => e,
            Err(_) => return Ok(0),
        };
        for entry in entries.flatten() {
            let name = entry.file_name().to_string_lossy().to_string();
            if crate::names::is_internal(&name) && fs::remove_file(entry.path()).is_ok() {
                removed += 1;
            }
        }
        Ok(removed)
    }
}

fn list_dir(
path: &Path,
personality: &Personality,
include_internal: bool,
) -> VfsResult<Vec<DirEntry>> {
    let rd = fs::read_dir(path).map_err(|e| io_err(path, e))?;
    let mut out = Vec::new();
    for entry in rd {
        let entry = entry.map_err(|e| io_err(path, e))?;
        let raw = entry.file_name().to_string_lossy().to_string();
        // macOS hands back what it stored, which is decomposed, whatever
        // spelling the file was created with. Left alone, every file with an
        // accent in its name reads as a rename on the very next scan — the
        // engine asks the server for `café.txt`, finds `café.txt` spelled
        // the other way, and pushes the "new" name back. Two devices then
        // rename it at each other forever.
        //
        // Lookups are unaffected: a volume that decomposes also accepts
        // either spelling when asked for a file, so the composed form we
        // hand back opens the same file.
        let name = if personality.decomposes_unicode {
            crate::names::nfc(&raw)
        } else {
            raw
        };
        // The engine's own spool and swap files are not part of the tree --
        // except to `read_dir_all`, whose whole job is to see what this
        // filter hides.
        if !include_internal && crate::names::is_internal(&name) {
            continue;
        }
        // symlink_metadata, not metadata: a symlink must be reported as a
        // symlink rather than silently followed to whatever it points at,
        // which could be outside the root or a loop back into it.
        let md = match entry.path().symlink_metadata() {
            Ok(md) => md,
            Err(_) => continue, // vanished between listing and stat — the rescan will catch up
        };
        let kind = if md.file_type().is_symlink() {
            EntryKind::Symlink
        } else if md.is_dir() {
            EntryKind::Directory
        } else if md.is_file() {
            EntryKind::File
        } else {
            EntryKind::Other
        };
        out.push(DirEntry {
            name,
            kind,
            fingerprint: if kind == EntryKind::File {
                Some(fingerprint_of(&entry.path(), &md))
            } else {
                None
            },
        });
    }
    // A stable order so two scans of an unchanged directory produce
    // identical results; readdir order is not guaranteed.
    out.sort_by(|a, b| a.name.cmp(&b.name));
    Ok(out)
}

/// The guard's look at the target, and the one place a test can make it fail.
///
/// The state this seam exists for cannot be produced from outside: every stat
/// failure a test can force on a real filesystem -- an unsearchable parent, a
/// symlink loop, an over-long name -- fails the rename that follows too, so the
/// file survives whether the guard ran or not and the test proves nothing. The
/// dangerous case is the TRANSIENT one, where the stat fails and the rename
/// would then have succeeded, and nothing outside this process can stage it.
///
/// So it is staged from inside, in test builds only. The rest of this codebase
/// injects the filesystem, the network and the clock for exactly this reason;
/// this is the same move at the one layer that reaches the OS directly.
#[cfg(test)]
fn guard_stat(target: &Path) -> std::io::Result<fs::Metadata> {
    if let Some(kind) = tests::take_injected_stat_error() {
        return Err(std::io::Error::new(kind, "injected stat failure"));
    }
    target.symlink_metadata()
}

#[cfg(not(test))]
fn guard_stat(target: &Path) -> std::io::Result<fs::Metadata> {
    target.symlink_metadata()
}

/// Does this stat error mean "nothing is at that path"?
///
/// `NotFound` is the ordinary answer. `NotADirectory` is one too: a component
/// of the path is a file, so nothing can be at the path either, and the
/// create-the-parent step below turns that into an error that names the
/// blocker. Every other error means the question was not answered, which at a
/// gate is a refusal rather than a pass.
fn not_there(e: &std::io::Error) -> bool {
    matches!(
        e.kind(),
        std::io::ErrorKind::NotFound | std::io::ErrorKind::NotADirectory
    )
}

fn io_err(path: &Path, e: std::io::Error) -> VfsError {
    match e.kind() {
        std::io::ErrorKind::NotFound => VfsError::NotFound(path.to_path_buf()),
        std::io::ErrorKind::PermissionDenied => VfsError::PermissionDenied(path.to_path_buf()),
        std::io::ErrorKind::AlreadyExists => VfsError::AlreadyExists(path.to_path_buf()),
        _ => VfsError::Io {
            path: path.to_path_buf(),
            source: e,
        },
    }
}

#[cfg(unix)]
fn fingerprint_of(_path: &Path, md: &fs::Metadata) -> Fingerprint {
    use std::os::unix::fs::MetadataExt;
    Fingerprint {
        size: md.len(),
        // Nanosecond resolution where the filesystem provides it. On one that
        // does not, the coarse value is what the fingerprint comparison is
        // told to tolerate via Personality::mtime_granularity_ns.
        mtime_ns: (md.mtime() as u64)
            .saturating_mul(1_000_000_000)
            .saturating_add(md.mtime_nsec() as u64),
        file_id: md.ino(),
    }
}

/// The Windows equivalent of an inode: the volume's file index.
///
/// It has to come from an *opened handle* — `fs::metadata` will not report it —
/// so this opens one with `FILE_READ_ATTRIBUTES` and full sharing. Both details
/// matter: asking for read access would fail on a file another program holds
/// exclusively (which on Windows is most files most of the time), and a
/// stricter share mode would make the sync client itself the reason somebody's
/// save fails.
///
/// The alternative, standing in with the creation time, is wrong in the way that
/// costs data: Windows *preserves* creation time across a move, and worse,
/// copies it onto a file restored from a backup — so two unrelated files
/// routinely share one, and the pairing logic would call them the same file.
#[cfg(windows)]
fn file_index(path: &Path) -> Option<u64> {
    use std::os::windows::fs::OpenOptionsExt;
    use std::os::windows::io::AsRawHandle;
    use windows_sys::Win32::Storage::FileSystem::{
        GetFileInformationByHandle, BY_HANDLE_FILE_INFORMATION, FILE_FLAG_BACKUP_SEMANTICS,
        FILE_READ_ATTRIBUTES, FILE_SHARE_DELETE, FILE_SHARE_READ, FILE_SHARE_WRITE,
    };

    let file = fs::OpenOptions::new()
        .access_mode(FILE_READ_ATTRIBUTES)
        .share_mode(FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE)
        // Without this a directory cannot be opened at all.
        .custom_flags(FILE_FLAG_BACKUP_SEMANTICS)
        .open(path)
        .ok()?;

    // SAFETY: the handle is live for the duration of the call, and the struct is
    // plain old data the API fills in.
    unsafe {
        let mut info: BY_HANDLE_FILE_INFORMATION = std::mem::zeroed();
        if GetFileInformationByHandle(file.as_raw_handle() as _, &mut info) == 0 {
            return None;
        }
        Some(((info.nFileIndexHigh as u64) << 32) | info.nFileIndexLow as u64)
    }
}

#[cfg(windows)]
fn fingerprint_of(path: &Path, md: &fs::Metadata) -> Fingerprint {
    use std::os::windows::fs::MetadataExt;
    Fingerprint {
        size: md.len(),
        // Windows reports 100-nanosecond ticks since 1601; the epoch does not
        // matter because every comparison is against another value from the
        // same source.
        mtime_ns: md.last_write_time().saturating_mul(100),
        // Zero when the handle could not be opened. That reads as "identity
        // unknown", which the fingerprint comparison treats as changed — the
        // safe direction: it costs a hash, where a wrong identity costs a file.
        file_id: file_index(path).unwrap_or(0),
    }
}

impl Vfs for OsVfs {
    fn personality(&self) -> Personality {
        self.personality
    }

    fn root(&self) -> Option<PathBuf> {
        // An unmounted volume or a folder the user moved: the engine must read
        // this as "pause", never as "every file was deleted". Returning None is
        // what stops an unplugged drive from propagating as a mass delete.
        if self.root.is_dir() {
            Some(self.root.clone())
        } else {
            None
        }
    }

    fn read_dir(&self, path: &Path) -> VfsResult<Vec<DirEntry>> {
        list_dir(path, &self.personality, false)
    }

    fn read_dir_all(&self, path: &Path) -> VfsResult<Vec<DirEntry>> {
        list_dir(path, &self.personality, true)
    }

    fn fingerprint(&self, path: &Path) -> VfsResult<Option<Fingerprint>> {
        match path.symlink_metadata() {
            Ok(md) if md.is_file() => Ok(Some(fingerprint_of(path, &md))),
            Ok(_) => Ok(None),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(None),
            Err(e) => Err(io_err(path, e)),
        }
    }

    fn hash(&self, path: &Path) -> VfsResult<String> {
        let file = File::open(path).map_err(|e| io_err(path, e))?;
        let mut reader = BufReader::new(file);
        let mut hasher = Sha256::new();
        let mut buf = vec![0u8; HASH_CHUNK];
        loop {
            let n = reader.read(&mut buf).map_err(|e| io_err(path, e))?;
            if n == 0 {
                break;
            }
            hasher.update(&buf[..n]);
        }
        Ok(format!("{:x}", hasher.finalize()))
    }

    fn create_dir(&self, path: &Path) -> VfsResult<()> {
        match fs::create_dir_all(path) {
            Ok(()) => Ok(()),
            Err(e) => Err(io_err(path, e)),
        }
    }

    fn rename(&self, from: &Path, to: &Path) -> VfsResult<()> {
        if let Some(parent) = to.parent() {
            fs::create_dir_all(parent).map_err(|e| io_err(parent, e))?;
        }
        fs::rename(from, to).map_err(|e| io_err(from, e))
    }

    fn trash(&self, path: &Path) -> VfsResult<()> {
        if !path.exists() {
            // Already gone. The desired state holds, so this is success — a
            // retry after a crash must not fail on its own prior success.
            return Ok(());
        }
        // The recycle bin is a shell API, and the shell does not understand
        // extended-length paths — handed one it reports a path that does not
        // exist. Everywhere else this is a no-op.
        let shell_path = crate::paths::strip_verbatim(path);
        trash::delete(&shell_path).map_err(|e| VfsError::Io {
            path: path.to_path_buf(),
            source: std::io::Error::other(e.to_string()),
        })
    }

    fn spool(&self, target: &Path) -> VfsResult<Box<dyn SpoolFile>> {
        let name = format!(".jd-tmp-{}", (self.next_token)());
        let path = self.spool_dir.join(name);
        let file = File::create(&path).map_err(|e| io_err(&path, e))?;
        Ok(Box::new(OsSpoolFile {
            file: Some(file),
            path,
            target: target.to_path_buf(),
        }))
    }

    fn open_read(&self, path: &Path) -> VfsResult<Box<dyn crate::ReadSeek>> {
        let f = File::open(path).map_err(|e| io_err(path, e))?;
        Ok(Box::new(BufReader::new(f)))
    }

    fn scratch(&self) -> VfsResult<Box<dyn crate::ScratchFile>> {
        // Same directory and the same name prefix as spool files, so the
        // startup sweep that clears interrupted spools clears these too. A
        // scratch file left behind by a killed process is exactly the same
        // problem and deserves exactly the same broom.
        let name = format!(".jd-tmp-{}", (self.next_token)());
        let path = self.spool_dir.join(name);
        let file = File::create(&path).map_err(|e| io_err(&path, e))?;
        Ok(Box::new(OsScratchFile {
            file: Some(file),
            path,
        }))
    }
}

struct OsScratchFile {
    file: Option<File>,
    path: PathBuf,
}

/// Owns the scratch file for as long as anyone is reading it, and removes it on
/// drop. Deleting at `finish()` instead would work on Unix and leave the file
/// behind on Windows, where an open file cannot be unlinked.
struct OsScratchReader {
    file: File,
    path: PathBuf,
}

impl Write for OsScratchFile {
    fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
        match self.file.as_mut() {
            Some(f) => f.write(buf),
            None => Err(std::io::Error::other("scratch file already finished")),
        }
    }
    fn flush(&mut self) -> std::io::Result<()> {
        match self.file.as_mut() {
            Some(f) => f.flush(),
            None => Ok(()),
        }
    }
}

impl Drop for OsScratchFile {
    fn drop(&mut self) {
        // Only fires when the writer is dropped without finishing — an error
        // path. The reader owns the file afterwards.
        if self.file.is_some() {
            let _ = fs::remove_file(&self.path);
        }
    }
}

impl crate::ScratchFile for OsScratchFile {
    fn finish(mut self: Box<Self>) -> VfsResult<Box<dyn crate::ReadSeek>> {
        let mut file = self.file.take().ok_or_else(|| {
            io_err(
                &self.path,
                std::io::Error::other("scratch already finished"),
            )
        })?;
        file.flush().map_err(|e| io_err(&self.path, e))?;
        // No fsync: these bytes are never adopted as anybody's file. If the
        // machine dies mid-upload the whole transfer starts again, so paying
        // for durability here would buy nothing.
        file.seek(SeekFrom::Start(0))
            .map_err(|e| io_err(&self.path, e))?;
        Ok(Box::new(OsScratchReader {
            file,
            path: self.path.clone(),
        }))
    }
}

impl Read for OsScratchReader {
    fn read(&mut self, buf: &mut [u8]) -> std::io::Result<usize> {
        self.file.read(buf)
    }
}

impl Seek for OsScratchReader {
    fn seek(&mut self, pos: SeekFrom) -> std::io::Result<u64> {
        self.file.seek(pos)
    }
}

impl Drop for OsScratchReader {
    fn drop(&mut self) {
        let _ = fs::remove_file(&self.path);
    }
}

struct OsSpoolFile {
    file: Option<File>,
    path: PathBuf,
    #[allow(dead_code)]
    target: PathBuf,
}

impl OsSpoolFile {
    /// The commit proper. Split out so every way it can fail runs through one
    /// cleanup path rather than each needing to remember.
    fn try_commit(&mut self, target: &Path, expect: Option<Fingerprint>) -> VfsResult<Fingerprint> {
        // Durable before visible. Without the fsync, a power cut just after the
        // rename can leave a file that exists, has the right name and length,
        // and contains zeroes — the worst possible outcome, because everything
        // downstream would treat it as real content.
        if let Some(mut f) = self.file.take() {
            f.flush().map_err(|e| io_err(&self.path, e))?;
            f.sync_all().map_err(|e| io_err(&self.path, e))?;
        }

        // The guard against overwriting work done while we were downloading. If
        // the file at the target is no longer what the engine decided against,
        // somebody changed it in the meantime and this download is stale.
        //
        // An unanswerable question at a gate is a NO. This used to read the
        // stat with `if let Ok(..)`, so an lstat that FAILED for any reason
        // other than the file being absent skipped the check entirely and let
        // the rename go ahead -- the last gate before the one irreversible act
        // in this program, silently absent for exactly the commit that could
        // not be checked. The conditions that produce a transient stat error
        // are the conditions two busy devices produce, and no in-memory
        // simulator can generate one, so nothing above would ever have caught
        // it. Absent is the only error that means "nothing is in the way".
        if let Some(expected) = expect {
            match guard_stat(target) {
                Ok(md) => {
                    if md.is_file() {
                        let actual = fingerprint_of(target, &md);
                        if !actual.unchanged_from(&expected, &Personality::native()) {
                            return Err(VfsError::AlreadyExists(target.to_path_buf()));
                        }
                    }
                }
                // Absent, or a path component that is a file rather than a
                // directory: both are answers, and the second has its own
                // handling below that names the blocker. Anything else is the
                // stat failing to answer at all.
                Err(e) if not_there(&e) => {}
                Err(e) => return Err(io_err(target, e)),
            }
        }

        // No agreement means the engine has never seen whatever is at this
        // path: it is the user's, and this is the only copy of it. The caller
        // moves such a file aside before getting here, but "the caller checked"
        // is not a guarantee, and the cost of being wrong is the one thing this
        // program may not do. Refuse and let the caller decide again.
        //
        // Still a check followed by a rename rather than one atomic step. On
        // Linux `renameat2(RENAME_NOREPLACE)` would close the remaining
        // instruction-width window; it is not in `std` and has no portable
        // equivalent, so it is deliberately left as the next thing to do here
        // rather than reached for with a dependency.
        //
        // Fails closed for the same reason as the branch above: `.is_ok()` read
        // a stat error as an empty path, which is the most dangerous possible
        // reading of "I could not look".
        if expect.is_none() {
            match guard_stat(target) {
                Ok(_) => return Err(VfsError::AlreadyExists(target.to_path_buf())),
                // Absent, or a path component that is a file rather than a
                // directory: both are answers, and the second has its own
                // handling below that names the blocker. Anything else is the
                // stat failing to answer at all.
                Err(e) if not_there(&e) => {}
                Err(e) => return Err(io_err(target, e)),
            }
        }

        if let Some(parent) = target.parent() {
            fs::create_dir_all(parent).map_err(|e| io_err(parent, e))?;
        }
        fs::rename(&self.path, target).map_err(|e| io_err(&self.path, e))?;

        let md = target.symlink_metadata().map_err(|e| io_err(target, e))?;
        Ok(fingerprint_of(target, &md))
    }
}

impl Write for OsSpoolFile {
    fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
        match self.file.as_mut() {
            Some(f) => f.write(buf),
            None => Err(std::io::Error::other("spool file already committed")),
        }
    }
    fn flush(&mut self) -> std::io::Result<()> {
        match self.file.as_mut() {
            Some(f) => f.flush(),
            None => Ok(()),
        }
    }
}

impl SpoolFile for OsSpoolFile {
    fn commit(
        mut self: Box<Self>,
        target: &Path,
        expect: Option<Fingerprint>,
    ) -> VfsResult<Fingerprint> {
        let spool = self.path.clone();
        let result = self.try_commit(target, expect);
        if result.is_err() {
            // The handle is gone once this returns, so the caller cannot tidy up
            // after us. Anything left here is invisible to the user and stays
            // until the disk is full.
            let _ = fs::remove_file(&spool);
        }
        result
    }

    fn discard(mut self: Box<Self>) {
        self.file.take();
        let _ = fs::remove_file(&self.path);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct TempDir(PathBuf);
    impl TempDir {
        fn new(tag: &str) -> TempDir {
            let p = std::env::temp_dir().join(format!(
                "jd-vfs-{}-{}-{:?}",
                tag,
                std::process::id(),
                std::thread::current().id()
            ));
            let _ = fs::remove_dir_all(&p);
            fs::create_dir_all(&p).unwrap();
            TempDir(p)
        }
        fn path(&self) -> &Path {
            &self.0
        }
    }
    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }

    fn vfs(dir: &TempDir) -> OsVfs {
        let root = dir.path().join("root");
        fs::create_dir_all(&root).unwrap();
        OsVfs::new(root, dir.path().join("spool")).unwrap()
    }

    #[test]
    fn hashing_matches_a_known_sha256() {
        let d = TempDir::new("hash");
        let v = vfs(&d);
        let p = v.root().unwrap().join("a.txt");
        fs::write(&p, b"hello").unwrap();
        assert_eq!(
            v.hash(&p).unwrap(),
            "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
        );
    }

    #[test]
    fn hashing_is_chunk_boundary_safe() {
        // A file larger than the read buffer must hash the same as one hashed
        // in a single pass, or every large file would sync forever.
        let d = TempDir::new("bighash");
        let v = vfs(&d);
        let p = v.root().unwrap().join("big.bin");
        let bytes: Vec<u8> = (0..(HASH_CHUNK * 2 + 12345))
            .map(|i| (i % 251) as u8)
            .collect();
        fs::write(&p, &bytes).unwrap();

        let mut expect = Sha256::new();
        expect.update(&bytes);
        assert_eq!(v.hash(&p).unwrap(), format!("{:x}", expect.finalize()));
    }

    #[test]
    fn a_spool_file_only_becomes_visible_on_commit() {
        let d = TempDir::new("spool");
        let v = vfs(&d);
        let target = v.root().unwrap().join("downloaded.txt");

        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"partial").unwrap();
        // Mid-transfer: nothing at the destination yet.
        assert!(!target.exists());

        spool.commit(&target, None).unwrap();
        assert_eq!(fs::read(&target).unwrap(), b"partial");
    }

    #[test]
    fn a_discarded_spool_file_leaves_nothing_behind() {
        let d = TempDir::new("discard");
        let v = vfs(&d);
        let target = v.root().unwrap().join("never.txt");

        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"abandoned").unwrap();
        spool.discard();

        assert!(!target.exists());
        assert_eq!(fs::read_dir(d.path().join("spool")).unwrap().count(), 0);
    }

    thread_local! {
        static INJECTED_STAT_ERROR: std::cell::Cell<Option<std::io::ErrorKind>> =
            const { std::cell::Cell::new(None) };
    }

    /// Make the guard's next look at the target fail, once.
    pub(super) fn fail_the_next_guard_stat(kind: std::io::ErrorKind) {
        INJECTED_STAT_ERROR.with(|c| c.set(Some(kind)));
    }

    pub(super) fn take_injected_stat_error() -> Option<std::io::ErrorKind> {
        INJECTED_STAT_ERROR.with(|c| c.take())
    }

    /// A stat that cannot answer must not be read as permission to proceed.
    ///
    /// This is the last gate before the one irreversible act in this program,
    /// and it used to be skipped entirely whenever the stat errored: the
    /// comparison was written `if let Ok(..)`, so a transient failure -- memory
    /// pressure, exhausted descriptors, anything two busy devices produce --
    /// removed the guard for exactly the commit that could not be checked, and
    /// the rename went ahead over whatever was standing there.
    ///
    /// Nothing outside this process can stage that: every stat failure a real
    /// filesystem can be provoked into fails the rename too, so the file
    /// survives either way and the test cannot tell the fix from its absence.
    /// Hence the injection.
    #[test]
    fn a_stat_that_cannot_answer_refuses_the_commit() {
        let d = TempDir::new("guard-blind");
        let v = vfs(&d);
        let target = v.root().unwrap().join("precious.txt");
        fs::write(&target, b"the only copy").unwrap();
        let seen = v.fingerprint(&target).unwrap().unwrap();

        // The file is UNCHANGED, so the guard would say yes if it could look.
        // The only thing wrong is that it cannot look.
        fail_the_next_guard_stat(std::io::ErrorKind::Other);
        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"the download").unwrap();
        let err = spool.commit(&target, Some(seen)).unwrap_err();

        assert!(
            matches!(err, VfsError::Io { .. }),
            "an unanswerable stat must refuse, not proceed; got {err:?}"
        );
        assert_eq!(
            fs::read(&target).unwrap(),
            b"the only copy",
            "the commit went ahead over a file it had not been able to check"
        );

        // And the refusal is the transient thing it says it is: the next
        // attempt, with the stat answering again, lands.
        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"the download").unwrap();
        spool.commit(&target, Some(seen)).unwrap();
        assert_eq!(fs::read(&target).unwrap(), b"the download");
    }

    /// The same, for a commit with no agreement to compare against.
    #[test]
    fn a_stat_that_cannot_answer_refuses_a_commit_with_no_agreement() {
        let d = TempDir::new("guard-blind-none");
        let v = vfs(&d);
        let target = v.root().unwrap().join("theirs.txt");
        fs::write(&target, b"a file the engine has never seen").unwrap();

        fail_the_next_guard_stat(std::io::ErrorKind::Other);
        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"the download").unwrap();
        let err = spool.commit(&target, None).unwrap_err();

        assert!(
            matches!(err, VfsError::Io { .. }),
            "a stat error must not read as an empty path; got {err:?}"
        );
        assert_eq!(
            fs::read(&target).unwrap(),
            b"a file the engine has never seen"
        );
    }

    #[test]
    fn committing_refuses_to_overwrite_a_file_that_changed_underneath() {
        // The download started against a known state; while it ran, the user
        // saved over the file. Landing the download would destroy their edit.
        let d = TempDir::new("guard");
        let v = vfs(&d);
        let target = v.root().unwrap().join("contested.txt");
        fs::write(&target, b"original").unwrap();
        let seen = v.fingerprint(&target).unwrap().unwrap();

        std::thread::sleep(std::time::Duration::from_millis(10));
        fs::write(&target, b"the user's newer edit").unwrap();

        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"stale download").unwrap();
        let err = spool.commit(&target, Some(seen)).unwrap_err();

        assert!(matches!(err, VfsError::AlreadyExists(_)));
        assert_eq!(fs::read(&target).unwrap(), b"the user's newer edit");
    }

    #[test]
    fn committing_proceeds_when_the_target_is_untouched() {
        let d = TempDir::new("guard-ok");
        let v = vfs(&d);
        let target = v.root().unwrap().join("quiet.txt");
        fs::write(&target, b"original").unwrap();
        let seen = v.fingerprint(&target).unwrap().unwrap();

        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"new content").unwrap();
        spool.commit(&target, Some(seen)).unwrap();

        assert_eq!(fs::read(&target).unwrap(), b"new content");
    }

    #[test]
    fn committing_with_no_agreement_refuses_a_file_that_is_already_there() {
        // No agreement means the engine has never seen whatever is at this
        // path, so it belongs to the user and nothing else knows about it. The
        // caller checks first and moves it aside, but between that check and
        // this rename the user can save a file — and under a storm they do.
        // Landing on top of it would destroy the only copy in existence.
        let d = TempDir::new("guard-none");
        let v = vfs(&d);
        let target = v.root().unwrap().join("theirs.txt");

        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"the download").unwrap();
        fs::write(&target, b"something the user just saved").unwrap();
        let err = spool.commit(&target, None).unwrap_err();

        assert!(matches!(err, VfsError::AlreadyExists(_)));
        assert_eq!(fs::read(&target).unwrap(), b"something the user just saved");
    }

    #[test]
    fn committing_creates_missing_parent_directories() {
        let d = TempDir::new("mkparent");
        let v = vfs(&d);
        let target = v.root().unwrap().join("deep/nested/file.txt");

        let mut spool = v.spool(&target).unwrap();
        spool.write_all(b"x").unwrap();
        spool.commit(&target, None).unwrap();

        assert!(target.exists());
    }

    #[test]
    fn nothing_can_be_created_beneath_a_file() {
        // A path is a file or a directory, never both, so a file standing where
        // a folder should be does not quietly step aside. Creating the folder
        // is refused, and so is every child that would land inside it. The
        // engine has to be told this, because a simulator whose tree is a flat
        // map will happily hold a file with children and report both as done.
        let d = TempDir::new("beneath-a-file");
        let v = vfs(&d);
        let root = v.root().unwrap();
        let occupied = root.join("Report");
        fs::write(&occupied, b"the user's own notes").unwrap();

        let refused = v.create_dir(&occupied).unwrap_err();
        assert!(
            matches!(refused, VfsError::AlreadyExists(_)),
            "a file in the folder's place, got {refused:?}"
        );

        // The refusal names the FILE IN THE WAY, not the child being written.
        // That distinction is the whole trap: the caller sees AlreadyExists and
        // reasonably reads it as the target having changed underneath it, when
        // the target does not exist at all and never will while this stands.
        let child = occupied.join("notes.txt");
        let mut spool = v.spool(&child).unwrap();
        spool.write_all(b"a child of the folder").unwrap();
        let err = spool.commit(&child, None).unwrap_err();
        assert!(
            matches!(&err, VfsError::AlreadyExists(p) if p == &occupied),
            "refused, naming the file in the way; got {err:?}"
        );
        assert!(!child.exists(), "and the child was not written anywhere");

        assert_eq!(
            fs::read(&occupied).unwrap(),
            b"the user's own notes",
            "the file that was in the way is still the user's"
        );
    }

    #[test]
    fn listing_hides_the_engines_own_files_and_flags_symlinks() {
        let d = TempDir::new("list");
        let v = vfs(&d);
        let root = v.root().unwrap();
        fs::write(root.join("real.txt"), b"x").unwrap();
        fs::write(root.join(".jd-tmp-leftover"), b"x").unwrap();
        fs::create_dir(root.join("folder")).unwrap();
        #[cfg(unix)]
        std::os::unix::fs::symlink(root.join("real.txt"), root.join("link.txt")).unwrap();

        let listed = v.read_dir(&root).unwrap();
        let names: Vec<&str> = listed.iter().map(|e| e.name.as_str()).collect();

        assert!(
            !names.contains(&".jd-tmp-leftover"),
            "spool files are not tree content"
        );
        assert!(names.contains(&"real.txt"));
        assert!(names.contains(&"folder"));

        #[cfg(unix)]
        {
            // Never followed: a symlink can escape the root or loop back into
            // it, so it is reported as what it is and handled as unsyncable.
            let link = listed.iter().find(|e| e.name == "link.txt").unwrap();
            assert_eq!(link.kind, EntryKind::Symlink);
        }
    }

    #[test]
    fn listing_is_ordered_so_two_scans_agree() {
        let d = TempDir::new("order");
        let v = vfs(&d);
        let root = v.root().unwrap();
        for n in ["c.txt", "a.txt", "b.txt"] {
            fs::write(root.join(n), b"x").unwrap();
        }
        let names: Vec<String> = v
            .read_dir(&root)
            .unwrap()
            .into_iter()
            .map(|e| e.name)
            .collect();
        assert_eq!(names, vec!["a.txt", "b.txt", "c.txt"]);
    }

    #[test]
    fn a_rewritten_file_gets_a_different_fingerprint() {
        let d = TempDir::new("fp");
        let v = vfs(&d);
        let p = v.root().unwrap().join("f.txt");
        fs::write(&p, b"one").unwrap();
        let before = v.fingerprint(&p).unwrap().unwrap();

        std::thread::sleep(std::time::Duration::from_millis(10));
        fs::write(&p, b"two but longer").unwrap();
        let after = v.fingerprint(&p).unwrap().unwrap();

        assert!(!after.unchanged_from(&before, &Personality::native()));
    }

    #[test]
    fn fingerprinting_something_absent_is_not_an_error() {
        // A file that vanished between being listed and being examined is an
        // ordinary race, not a failure — the next scan settles it.
        let d = TempDir::new("absent");
        let v = vfs(&d);
        assert_eq!(
            v.fingerprint(&v.root().unwrap().join("nope.txt")).unwrap(),
            None
        );
    }

    #[test]
    fn an_unavailable_root_reads_as_unavailable_not_as_an_empty_tree() {
        // The distinction that stops an unmounted drive propagating as a mass
        // delete of everything on the server.
        let d = TempDir::new("unmounted");
        let v = vfs(&d);
        assert!(v.root().is_some());
        fs::remove_dir_all(v.root().unwrap()).unwrap();
        assert!(v.root().is_none());
    }

    #[test]
    fn trashing_something_already_gone_succeeds() {
        // Retry after a crash must not fail on its own prior success.
        let d = TempDir::new("trash-absent");
        let v = vfs(&d);
        assert!(v.trash(&v.root().unwrap().join("ghost.txt")).is_ok());
    }

    #[test]
    fn sweeping_removes_leftover_spool_files() {
        let d = TempDir::new("sweep");
        let v = vfs(&d);
        let mut spool = v.spool(&v.root().unwrap().join("t.txt")).unwrap();
        spool.write_all(b"interrupted").unwrap();
        drop(spool); // process died here — the spool file is orphaned

        assert_eq!(v.sweep_spool().unwrap(), 1);
        assert_eq!(fs::read_dir(d.path().join("spool")).unwrap().count(), 0);
    }

    #[test]
    fn renaming_creates_the_destination_directory() {
        let d = TempDir::new("rename");
        let v = vfs(&d);
        let root = v.root().unwrap();
        let from = root.join("here.txt");
        fs::write(&from, b"x").unwrap();
        let to = root.join("new/place/here.txt");

        v.rename(&from, &to).unwrap();
        assert!(to.exists() && !from.exists());
    }
}
