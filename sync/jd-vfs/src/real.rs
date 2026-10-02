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

/// A fingerprint as the engine is shown it: on a volume whose ids are only
/// positions, with no id and no birth, so nothing reads one
/// (`Personality::positional_file_ids`).
fn as_seen(fp: Fingerprint, personality: &Personality) -> Fingerprint {
    if personality.positional_file_ids {
        Fingerprint { file_id: 0, birth_ns: 0, ..fp }
    } else {
        fp
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
        // Beside the fingerprint, never in it: the raw id, only where this
        // volume's ids may break a tie (`Personality::id_tie_break`).
        let tie_break_id = if personality.id_tie_break == crate::personality::IdTieBreak::None {
            0
        } else {
            match kind {
                EntryKind::File => fingerprint_of(&entry.path(), &md).file_id,
                EntryKind::Directory => directory_id_of(&entry.path(), &md),
                _ => 0,
            }
        };
        out.push(DirEntry {
            name,
            kind,
            tie_break_id,
            fingerprint: match kind {
                EntryKind::File => Some(as_seen(fingerprint_of(&entry.path(), &md), personality)),
                EntryKind::Directory => Some(if personality.positional_file_ids {
                    Fingerprint::of_directory(0, 0)
                } else {
                    Fingerprint::of_directory(directory_id_of(&entry.path(), &md), birth_of(&md))
                }),
                _ => None,
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
        birth_ns: birth_of(md),
    }
}

/// The identity of the file at `path`, read the way the scan reads it.
/// `None` when nothing can be read there.
pub(crate) fn identity_at(path: &Path) -> Option<crate::FileIdentity> {
    let md = fs::symlink_metadata(path).ok()?;
    Some(fingerprint_of(path, &md).identity())
}

/// Is this a volume whose 64-bit file index is documented as not unique?
/// ReFS (and so a Windows 11 Dev Drive) numbers files with 128 bits, and the
/// index `file_index` reads is not guaranteed to tell two of them apart.
#[cfg(windows)]
pub(crate) fn ids_not_unique_on_this_volume(dir: &Path) -> bool {
    // Unanswered is not an answer that trusts the ids.
    volume_filesystem_name(dir).is_none_or(|n| n.eq_ignore_ascii_case("ReFS"))
}

/// Is this a FAT or exFAT volume, whose file ids say where a file sits rather
/// than which file it is? Named by the filesystem type, because on Linux and
/// macOS the rename probe cannot see it (`specs/drive_weak_volume_identity.md`,
/// F1: Windows moves the id with the directory entry, macOS FAT32 with the
/// first data cluster, Linux and macOS exFAT with the mount).
#[cfg(windows)]
pub(crate) fn ids_are_positions_on_this_volume(dir: &Path) -> bool {
    volume_filesystem_name(dir).is_some_and(|n| {
        ["FAT", "FAT32", "exFAT"].iter().any(|fat| n.eq_ignore_ascii_case(fat))
    })
}

#[cfg(target_os = "linux")]
pub(crate) fn ids_are_positions_on_this_volume(dir: &Path) -> bool {
    use std::os::unix::ffi::OsStrExt;
    const MSDOS_SUPER_MAGIC: i64 = 0x4d44;
    const EXFAT_SUPER_MAGIC: i64 = 0x2011_bab0;
    let Ok(c) = std::ffi::CString::new(dir.as_os_str().as_bytes()) else {
        return false;
    };
    let mut st: libc::statfs = unsafe { std::mem::zeroed() };
    // SAFETY: a NUL-terminated path and a zeroed struct of the right type.
    if unsafe { libc::statfs(c.as_ptr(), &mut st) } != 0 {
        return false;
    }
    matches!(st.f_type as i64, MSDOS_SUPER_MAGIC | EXFAT_SUPER_MAGIC)
}

#[cfg(target_os = "macos")]
pub(crate) fn ids_are_positions_on_this_volume(dir: &Path) -> bool {
    use std::os::unix::ffi::OsStrExt;
    let Ok(c) = std::ffi::CString::new(dir.as_os_str().as_bytes()) else {
        return false;
    };
    let mut st: libc::statfs = unsafe { std::mem::zeroed() };
    // SAFETY: a NUL-terminated path and a zeroed struct of the right type.
    if unsafe { libc::statfs(c.as_ptr(), &mut st) } != 0 {
        return false;
    }
    // SAFETY: the kernel NUL-terminates the type name inside the array.
    let name = unsafe { std::ffi::CStr::from_ptr(st.f_fstypename.as_ptr()) };
    matches!(name.to_bytes(), b"msdos" | b"exfat")
}

#[cfg(all(unix, not(any(target_os = "linux", target_os = "macos"))))]
pub(crate) fn ids_are_positions_on_this_volume(_dir: &Path) -> bool {
    false
}

/// How far this FAT or exFAT volume's ids hold still (F1): Windows ids are a
/// directory entry's position and move on a rename; Linux FAT and exFAT ids
/// hold for one mount; macOS FAT32 ids are a file's first data cluster and
/// survive renames and remounts, while macOS exFAT ids hold for one mount.
#[cfg(windows)]
pub(crate) fn id_tie_break_on_this_volume(_dir: &Path) -> crate::personality::IdTieBreak {
    crate::personality::IdTieBreak::None
}

#[cfg(target_os = "linux")]
pub(crate) fn id_tie_break_on_this_volume(dir: &Path) -> crate::personality::IdTieBreak {
    if ids_are_positions_on_this_volume(dir) {
        crate::personality::IdTieBreak::MountSession
    } else {
        crate::personality::IdTieBreak::None
    }
}

#[cfg(target_os = "macos")]
pub(crate) fn id_tie_break_on_this_volume(dir: &Path) -> crate::personality::IdTieBreak {
    use std::os::unix::ffi::OsStrExt;
    let Ok(c) = std::ffi::CString::new(dir.as_os_str().as_bytes()) else {
        return crate::personality::IdTieBreak::None;
    };
    let mut st: libc::statfs = unsafe { std::mem::zeroed() };
    // SAFETY: a NUL-terminated path and a zeroed struct of the right type.
    if unsafe { libc::statfs(c.as_ptr(), &mut st) } != 0 {
        return crate::personality::IdTieBreak::None;
    }
    // SAFETY: the kernel NUL-terminates the type name inside the array.
    let name = unsafe { std::ffi::CStr::from_ptr(st.f_fstypename.as_ptr()) };
    match name.to_bytes() {
        b"msdos" => crate::personality::IdTieBreak::Durable,
        b"exfat" => crate::personality::IdTieBreak::MountSession,
        _ => crate::personality::IdTieBreak::None,
    }
}

#[cfg(all(unix, not(any(target_os = "linux", target_os = "macos"))))]
pub(crate) fn id_tie_break_on_this_volume(_dir: &Path) -> crate::personality::IdTieBreak {
    crate::personality::IdTieBreak::None
}

/// The filesystem's name for the volume holding `dir`, or None unanswered.
#[cfg(windows)]
fn volume_filesystem_name(dir: &Path) -> Option<String> {
    use std::os::windows::fs::OpenOptionsExt;
    use std::os::windows::io::AsRawHandle;
    use windows_sys::Win32::Storage::FileSystem::{
        GetVolumeInformationByHandleW, FILE_FLAG_BACKUP_SEMANTICS, FILE_READ_ATTRIBUTES,
        FILE_SHARE_DELETE, FILE_SHARE_READ, FILE_SHARE_WRITE,
    };
    let Ok(handle) = fs::OpenOptions::new()
        .access_mode(FILE_READ_ATTRIBUTES)
        .share_mode(FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE)
        .custom_flags(FILE_FLAG_BACKUP_SEMANTICS)
        .open(dir)
    else {
        return None;
    };
    let mut name = [0u16; 64];
    // SAFETY: the handle is live for the call and the buffer's length is the
    // one passed; every other out-parameter is optional and passed null.
    let ok = unsafe {
        GetVolumeInformationByHandleW(
            handle.as_raw_handle() as _,
            std::ptr::null_mut(),
            0,
            std::ptr::null_mut(),
            std::ptr::null_mut(),
            std::ptr::null_mut(),
            name.as_mut_ptr(),
            name.len() as u32,
        )
    };
    if ok == 0 {
        return None;
    }
    let end = name.iter().position(|c| *c == 0).unwrap_or(name.len());
    Some(String::from_utf16_lossy(&name[..end]))
}

/// Unix volumes number files with an inode, unique on the volume.
#[cfg(unix)]
pub(crate) fn ids_not_unique_on_this_volume(_dir: &Path) -> bool {
    false
}

/// When the file came into existence, or 0 where the volume does not say.
///
/// `st_birthtime` on macOS, the creation time on Windows, `statx`'s btime on
/// Linux (ext4, btrfs, xfs v5; the standard library asks `statx` for it).
/// Unlike an mtime, no Linux call can set it; macOS and Windows can, and
/// their file ids do not recycle, so a copied birth never meets its old
/// number (`specs/drive_file_identity.md`, the platform table).
fn birth_of(md: &fs::Metadata) -> u64 {
    md.created()
        .ok()
        .and_then(|t| t.duration_since(std::time::UNIX_EPOCH).ok())
        .map(|d| u64::try_from(d.as_nanos()).unwrap_or(u64::MAX))
        .unwrap_or(0)
}

/// A directory's identity: its inode. The same thing a file's is, read the
/// same way; only the callers differ.
#[cfg(unix)]
fn directory_id_of(_path: &Path, md: &fs::Metadata) -> u64 {
    use std::os::unix::fs::MetadataExt;
    md.ino()
}

/// A directory's identity on Windows: its file index, through a handle opened
/// with `FILE_FLAG_BACKUP_SEMANTICS` (`file_index` already asks for it, since
/// without it a directory cannot be opened at all). 0 when the handle would
/// not open, which reads as "unknown" everywhere, never as a match.
#[cfg(windows)]
fn directory_id_of(path: &Path, _md: &fs::Metadata) -> u64 {
    file_index(path).unwrap_or(0)
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
    use windows_sys::Win32::Storage::FileSystem::{
        FILE_FLAG_BACKUP_SEMANTICS, FILE_READ_ATTRIBUTES, FILE_SHARE_DELETE, FILE_SHARE_READ,
        FILE_SHARE_WRITE,
    };

    let file = fs::OpenOptions::new()
        .access_mode(FILE_READ_ATTRIBUTES)
        .share_mode(FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE)
        // Without this a directory cannot be opened at all.
        .custom_flags(FILE_FLAG_BACKUP_SEMANTICS)
        .open(path)
        .ok()?;
    index_of_handle(&file)
}

/// The file index of the file an open handle reads.
#[cfg(windows)]
fn index_of_handle(file: &File) -> Option<u64> {
    use std::os::windows::io::AsRawHandle;
    use windows_sys::Win32::Storage::FileSystem::{GetFileInformationByHandle, BY_HANDLE_FILE_INFORMATION};

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
        birth_ns: birth_of(md),
    }
}

/// Open the file at `path` for reading without following a link: `None` when
/// a symlink stands there. Whatever is put at the path between a check and
/// this open, the handle is never the target of a link the user did not
/// sync (reset B12). Non-blocking as well, so that a FIFO put there is
/// opened and turned away rather than waited on for a writer; a regular
/// file reads the same either way.
#[cfg(unix)]
fn open_not_following(path: &Path) -> std::io::Result<Option<File>> {
    use std::os::unix::fs::OpenOptionsExt;
    match fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(path)
    {
        Ok(f) => Ok(Some(f)),
        Err(e) if e.raw_os_error() == Some(libc::ELOOP) => Ok(None),
        Err(e) => Err(e),
    }
}

/// The same on Windows: opened as the reparse point itself, a link is seen
/// for what it is and refused. Any other reparse point (a deduplicated file,
/// a cloud placeholder) keeps its bytes behind the filter that owns it, and
/// read as the reparse point it would give the wrong ones: that file is
/// opened the ordinary way and kept only if it is the same file.
#[cfg(windows)]
fn open_not_following(path: &Path) -> std::io::Result<Option<File>> {
    use std::os::windows::fs::{MetadataExt, OpenOptionsExt};
    use windows_sys::Win32::Storage::FileSystem::{FILE_ATTRIBUTE_REPARSE_POINT, FILE_FLAG_OPEN_REPARSE_POINT};
    let itself = fs::OpenOptions::new()
        .read(true)
        .custom_flags(FILE_FLAG_OPEN_REPARSE_POINT)
        .open(path)?;
    let md = itself.metadata()?;
    if md.file_type().is_symlink() || !md.is_file() {
        return Ok(None);
    }
    if md.file_attributes() & FILE_ATTRIBUTE_REPARSE_POINT == 0 {
        return Ok(Some(itself));
    }
    let file = File::open(path)?;
    match (index_of_handle(&itself), index_of_handle(&file)) {
        (Some(a), Some(b)) if a == b => Ok(Some(file)),
        _ => Ok(None),
    }
}

/// The fingerprint of the file an open handle reads, whatever its path names
/// by now: the same fields `fingerprint_of` reads, taken from the handle.
#[cfg(unix)]
fn fingerprint_of_handle(_file: &File, md: &fs::Metadata) -> Fingerprint {
    fingerprint_of(Path::new(""), md)
}

#[cfg(windows)]
fn fingerprint_of_handle(file: &File, md: &fs::Metadata) -> Fingerprint {
    use std::os::windows::fs::MetadataExt;
    Fingerprint {
        size: md.len(),
        mtime_ns: md.last_write_time().saturating_mul(100),
        file_id: index_of_handle(file).unwrap_or(0),
        birth_ns: birth_of(md),
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
            Ok(md) if md.is_file() => Ok(Some(as_seen(fingerprint_of(path, &md), &self.personality))),
            Ok(_) => Ok(None),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(None),
            Err(e) => Err(io_err(path, e)),
        }
    }

    fn directory_id(&self, path: &Path) -> VfsResult<Option<u64>> {
        match path.symlink_metadata() {
            // symlink_metadata, so a symlink to a directory is not a directory
            // here, exactly as it is not one in a listing.
            Ok(md) if md.is_dir() && !md.file_type().is_symlink() => {
                Ok(Some(if self.personality.positional_file_ids { 0 } else { directory_id_of(path, &md) }))
            }
            Ok(_) => Ok(None),
            Err(e) if not_there(&e) => Ok(None),
            Err(e) => Err(io_err(path, e)),
        }
    }

    fn directory_identity(&self, path: &Path) -> VfsResult<Option<crate::FileIdentity>> {
        directory_identity_at(path, &self.personality)
    }

    fn tie_break_id(&self, path: &Path) -> VfsResult<u64> {
        if self.personality.id_tie_break == crate::personality::IdTieBreak::None {
            return Ok(0);
        }
        match path.symlink_metadata() {
            Ok(md) if md.file_type().is_symlink() => Ok(0),
            Ok(md) if md.is_dir() => Ok(directory_id_of(path, &md)),
            Ok(md) if md.is_file() => Ok(fingerprint_of(path, &md).file_id),
            Ok(_) => Ok(0),
            Err(e) if not_there(&e) => Ok(0),
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
        trash_context().delete(&shell_path).map_err(|e| VfsError::Io {
            path: path.to_path_buf(),
            source: std::io::Error::other(e.to_string()),
        })
    }

    fn spool(&self, target: &Path, op: i64) -> VfsResult<Box<dyn SpoolFile>> {
        let name = format!(".jd-tmp-{}", (self.next_token)());
        let path = self.spool_dir.join(name);
        let file = File::create(&path).map_err(|e| io_err(&path, e))?;
        let folder_at_open = match target.parent() {
            Some(folder) => directory_identity_at(folder, &self.personality)?,
            None => None,
        };
        Ok(Box::new(OsSpoolFile {
            file: Some(file),
            path,
            personality: self.personality,
            target: target.to_path_buf(),
            folder_at_open,
            op,
            rename: |from, to| fs::rename(from, to),
            after_copy: |_| {},
        }))
    }

    fn open_read(&self, path: &Path) -> VfsResult<Box<dyn crate::ReadSeek>> {
        let f = File::open(path).map_err(|e| io_err(path, e))?;
        Ok(Box::new(BufReader::new(f)))
    }

    fn open_file(&self, path: &Path) -> VfsResult<Option<(Box<dyn crate::ReadSeek>, Fingerprint)>> {
        // A symlink is not a file here, exactly as `fingerprint` says. Asked
        // of the path first, so that nothing else is opened at all; the open
        // itself then refuses a link put there since (`open_not_following`).
        match path.symlink_metadata() {
            Ok(md) if md.is_file() => {}
            Ok(_) => return Ok(None),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(None),
            Err(e) => return Err(io_err(path, e)),
        }
        let file = match open_not_following(path) {
            Ok(Some(f)) => f,
            Ok(None) => return Ok(None),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(None),
            Err(e) => return Err(io_err(path, e)),
        };
        // Of the handle: whatever has taken the path since it opened, this
        // is the file it reads.
        let md = file.metadata().map_err(|e| io_err(path, e))?;
        if !md.is_file() {
            return Ok(None);
        }
        let fingerprint = as_seen(fingerprint_of_handle(&file, &md), &self.personality);
        Ok(Some((Box::new(BufReader::new(file)), fingerprint)))
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
    personality: Personality,
    #[allow(dead_code)]
    target: PathBuf,
    /// The directory standing where the target lands when the spool was
    /// opened; `None` if there was none (see `SpoolFile::commit`).
    folder_at_open: Option<crate::FileIdentity>,
    /// The download op this spool lands for; carried in a landing name.
    op: i64,
    /// `fs::rename`. A seam so a test can make the spool's rename cross a
    /// volume, which one machine's temp directory cannot be relied on to do.
    rename: fn(&Path, &Path) -> std::io::Result<()>,
    /// Runs between the copy onto another volume and the gates asked again
    /// after it. Nothing, outside the test that changes the target there.
    after_copy: fn(&Path),
}

/// How this platform moves a file to its trash.
///
/// On macOS the `trash` crate's default asks Finder to do it through an
/// AppleEvent, which a background daemon may not send without the user
/// granting it control of Finder: until they answer the prompt every trash
/// waits two minutes and fails (-1712), and if they decline, every trash
/// fails for good. `NSFileManager` needs no permission. Its one cost is a
/// macOS bug that can leave "Put Back" off the file's menu in the Trash; the
/// file is still there and still dragged out by hand.
fn trash_context() -> trash::TrashContext {
    #[allow(unused_mut)]
    let mut ctx = trash::TrashContext::default();
    #[cfg(target_os = "macos")]
    {
        use trash::macos::{DeleteMethod, TrashContextExtMacos};
        ctx.set_delete_method(DeleteMethod::NsFileManager);
    }
    ctx
}

/// The identity of the directory at `path`, `None` where none stands there.
fn directory_identity_at(path: &Path, personality: &Personality) -> VfsResult<Option<crate::FileIdentity>> {
    match path.symlink_metadata() {
        Ok(md) if md.is_dir() && !md.file_type().is_symlink() => Ok(Some(if personality.positional_file_ids {
            crate::FileIdentity { file_id: 0, birth_ns: 0 }
        } else {
            crate::FileIdentity { file_id: directory_id_of(path, &md), birth_ns: birth_of(&md) }
        })),
        Ok(_) => Ok(None),
        Err(e) if not_there(&e) => Ok(None),
        Err(e) => Err(io_err(path, e)),
    }
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

        self.guard_target(target, expect)?;

        if let Some(parent) = target.parent() {
            match self.folder_at_open {
                Some(was) => {
                    if directory_identity_at(parent, &self.personality)? != Some(was) {
                        return Err(VfsError::FolderMoved(parent.to_path_buf()));
                    }
                }
                None => fs::create_dir_all(parent).map_err(|e| io_err(parent, e))?,
            }
        }
        match (self.rename)(&self.path, target) {
            Ok(()) => {}
            // The spool is on the state store's volume and the sync root is
            // not: an external disk, a second partition, a mount point inside
            // the root. A rename cannot cross; the bytes are copied over and
            // then renamed into place on the target's own volume.
            Err(e) if e.kind() == std::io::ErrorKind::CrossesDevices => {
                self.land_across(target, expect)?
            }
            Err(e) => return Err(io_err(&self.path, e)),
        }

        let md = target.symlink_metadata().map_err(|e| io_err(target, e))?;
        Ok(as_seen(fingerprint_of(target, &md), &self.personality))
    }

    /// The commit onto another volume: copy the spool beside the target under
    /// a [`LAND_PREFIX`](crate::names::LAND_PREFIX) name, make it durable, ask
    /// every gate again, and rename it into place.
    ///
    /// The gates are asked twice because the copy widens the window between
    /// asking and acting from a few instructions to as long as the copy takes,
    /// and a user editing the target or moving its folder in that time is the
    /// very thing they exist for. Any failure leaves neither copy behind.
    fn land_across(&mut self, target: &Path, expect: Option<Fingerprint>) -> VfsResult<()> {
        let dir = target
            .parent()
            .ok_or_else(|| io_err(target, std::io::Error::other("a download target with no folder")))?;
        // The folder the gates above just passed: whatever stands there
        // after the copy must be this one.
        let folder = directory_identity_at(dir, &self.personality)?;
        let token = self
            .path
            .file_name()
            .map(|n| n.to_string_lossy().trim_start_matches(".jd-tmp-").to_string())
            .unwrap_or_default();
        let land = dir.join(crate::names::land_name(self.op, &token));

        // Never over a file already standing at the name: whatever is there
        // is not this commit's, so it is not this commit's to replace.
        let out = File::create_new(&land).map_err(|e| io_err(&land, e))?;
        let landed = (|| {
            {
                let mut out = out;
                let mut from = File::open(&self.path).map_err(|e| io_err(&self.path, e))?;
                std::io::copy(&mut from, &mut out).map_err(|e| io_err(&land, e))?;
                out.sync_all().map_err(|e| io_err(&land, e))?;
            } // Closed before anything renames or removes it: Windows refuses both on an open file.
            (self.after_copy)(target);
            self.guard_target(target, expect)?;
            if directory_identity_at(dir, &self.personality)? != folder {
                return Err(VfsError::FolderMoved(dir.to_path_buf()));
            }
            fs::rename(&land, target).map_err(|e| io_err(&land, e))?;
            // The rename is durable when the directory entry is. Unix only:
            // Windows has no directory handle to flush, and its rename is
            // journaled by the volume.
            #[cfg(unix)]
            {
                let _ = File::open(dir).and_then(|d| d.sync_all());
            }
            Ok(())
        })();
        if landed.is_err() {
            let _ = fs::remove_file(&land);
        }
        landed?;
        let _ = fs::remove_file(&self.path);
        Ok(())
    }

    /// The gates on the file at the target, asked before it is replaced.
    fn guard_target(&self, target: &Path, expect: Option<Fingerprint>) -> VfsResult<()> {
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
                        // Without ids the guard is size and time: the caller
                        // hashed the standing file a moment before, and that
                        // gate is the one about content (execute.rs,
                        // `download`).
                        let unchanged = if self.personality.positional_file_ids {
                            actual.size == expected.size
                                && actual.mtime_ns.abs_diff(expected.mtime_ns)
                                    < self.personality.mtime_granularity_ns.max(1)
                        } else {
                            actual.unchanged_from(&expected, &Personality::native())
                        };
                        if !unchanged {
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
        Ok(())
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

    /// An opened file is the file it opened: its fingerprint is read from
    /// the handle, and what it reads stays that file's after another file is
    /// renamed over its path (`specs/drive_file_identity.md`, T1-D).
    #[cfg(unix)]
    #[test]
    fn an_opened_file_stays_the_file_it_opened() {
        use std::os::unix::fs::MetadataExt;
        let d = TempDir::new("open-file");
        let v = vfs(&d);
        let root = v.root().unwrap();
        let (a, b) = (root.join("a.txt"), root.join("b.txt"));
        fs::write(&a, b"the file that was opened").unwrap();
        fs::write(&b, b"the file renamed over it").unwrap();
        let inode = fs::metadata(&a).unwrap().ino();
        let (mut reader, fingerprint) = v.open_file(&a).unwrap().expect("a file stands there");
        fs::rename(&b, &a).unwrap();
        let mut read = Vec::new();
        reader.read_to_end(&mut read).unwrap();
        assert_eq!(read, b"the file that was opened");
        assert_eq!(fingerprint.file_id, inode);
        assert_eq!(fingerprint.size, b"the file that was opened".len() as u64);
        assert_ne!(v.fingerprint(&a).unwrap().unwrap().file_id, inode, "the path now names the other file");
        assert!(v.open_file(&root.join("missing.txt")).unwrap().is_none());
        fs::create_dir_all(root.join("dir")).unwrap();
        assert!(v.open_file(&root.join("dir")).unwrap().is_none(), "a directory is not a file here");
    }

    /// The open itself refuses a link, whatever the check before it saw: the
    /// window between the two is where one can be put (reset B12).
    #[cfg(unix)]
    #[test]
    fn the_open_never_follows_a_link_put_at_the_path() {
        let d = TempDir::new("open-link");
        let v = vfs(&d);
        let root = v.root().unwrap();
        let (target, link) = (root.join("target.txt"), root.join("a.txt"));
        fs::write(&target, b"a file the link points at").unwrap();
        std::os::unix::fs::symlink(&target, &link).unwrap();
        assert!(open_not_following(&link).unwrap().is_none(), "the handle read the link's target");
        assert!(v.open_file(&link).unwrap().is_none());
        assert!(open_not_following(&target).unwrap().is_some());
    }

    /// The same on Windows: opened as the reparse point itself, a file link
    /// is refused and its target is not read (reset B12). Making a link needs
    /// the privilege to; without it there is nothing to test.
    #[cfg(windows)]
    #[test]
    fn the_open_never_follows_a_link_put_at_the_path() {
        let d = TempDir::new("open-link");
        let v = vfs(&d);
        let root = v.root().unwrap();
        let (target, link) = (root.join("target.txt"), root.join("a.txt"));
        fs::write(&target, b"a file the link points at").unwrap();
        if std::os::windows::fs::symlink_file(&target, &link).is_err() {
            eprintln!("no privilege to make a link here; skipped");
            return;
        }
        assert!(open_not_following(&link).unwrap().is_none(), "the handle read the link's target");
        assert!(v.open_file(&link).unwrap().is_none());
        let mut read = Vec::new();
        open_not_following(&target).unwrap().expect("a plain file opens").read_to_end(&mut read).unwrap();
        assert_eq!(read, b"a file the link points at");
    }

    /// A FIFO put at the path is opened and turned away, not waited on for a
    /// writer that never comes (reset B12).
    #[cfg(unix)]
    #[test]
    fn the_open_does_not_wait_on_a_fifo_put_at_the_path() {
        let d = TempDir::new("open-fifo");
        let v = vfs(&d);
        let fifo = v.root().unwrap().join("a.txt");
        let c = std::ffi::CString::new(fifo.as_os_str().as_encoded_bytes()).unwrap();
        // SAFETY: a valid NUL-terminated path; mkfifo reads nothing else.
        assert_eq!(unsafe { libc::mkfifo(c.as_ptr(), 0o600) }, 0);
        let (tx, rx) = std::sync::mpsc::channel();
        std::thread::spawn(move || {
            let opened = open_not_following(&fifo).map(|f| f.map(|f| f.metadata().unwrap().is_file()));
            let _ = tx.send(opened.ok().flatten());
        });
        let opened = rx
            .recv_timeout(std::time::Duration::from_secs(5))
            .expect("the open is still waiting for a writer");
        assert_eq!(opened, Some(false), "opened, and not a file");
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

        let mut spool = v.spool(&target, 0).unwrap();
        spool.write_all(b"partial").unwrap();
        // Mid-transfer: nothing at the destination yet.
        assert!(!target.exists());

        spool.commit(&target, None).unwrap();
        assert_eq!(fs::read(&target).unwrap(), b"partial");
    }

    #[test]
    fn a_spool_never_makes_again_a_folder_moved_while_it_was_filled() {
        let d = TempDir::new("moved");
        let v = vfs(&d);
        let root = v.root().unwrap();
        fs::create_dir(root.join("P")).unwrap();
        let target = root.join("P").join("plans.txt");

        let mut spool = v.spool(&target, 0).unwrap();
        spool.write_all(b"in flight").unwrap();
        fs::rename(root.join("P"), root.join("P2")).unwrap();
        let err = spool.commit(&target, None).unwrap_err();
        assert!(matches!(&err, VfsError::FolderMoved(p) if p == &root.join("P")), "got {err:?}");
        assert!(!root.join("P").exists(), "the folder's old name was made again");
        assert!(!root.join("P2").join("plans.txt").exists());

        // A folder missing when the spool opened is made, as it always was.
        let fresh = root.join("Q").join("notes.txt");
        let mut spool = v.spool(&fresh, 0).unwrap();
        spool.write_all(b"into a folder not made yet").unwrap();
        spool.commit(&fresh, None).unwrap();
        assert_eq!(fs::read(&fresh).unwrap(), b"into a folder not made yet");
    }

    #[test]
    fn a_discarded_spool_file_leaves_nothing_behind() {
        let d = TempDir::new("discard");
        let v = vfs(&d);
        let target = v.root().unwrap().join("never.txt");

        let mut spool = v.spool(&target, 0).unwrap();
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
        let mut spool = v.spool(&target, 0).unwrap();
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
        let mut spool = v.spool(&target, 0).unwrap();
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
        let mut spool = v.spool(&target, 0).unwrap();
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

        let mut spool = v.spool(&target, 0).unwrap();
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

        let mut spool = v.spool(&target, 0).unwrap();
        spool.write_all(b"new content").unwrap();
        spool.commit(&target, Some(seen)).unwrap();

        assert_eq!(fs::read(&target).unwrap(), b"new content");
    }

    /// A spool whose rename onto the root always crosses a volume, as it does
    /// when the sync root is on another disk from the state store.
    fn spool_across(v: &OsVfs, target: &Path, after_copy: fn(&Path)) -> Box<OsSpoolFile> {
        let path = v.spool_dir.join(format!(".jd-tmp-{}", (v.next_token)()));
        Box::new(OsSpoolFile {
            file: Some(File::create(&path).unwrap()),
            path,
            personality: v.personality,
            target: target.to_path_buf(),
            folder_at_open: directory_identity_at(target.parent().unwrap(), &v.personality).unwrap(),
            op: 7,
            rename: |_, _| Err(std::io::Error::from(std::io::ErrorKind::CrossesDevices)),
            after_copy,
        })
    }

    /// Nothing of a commit left behind: no spool, and no landing name beside
    /// the target.
    fn no_litter(v: &OsVfs, dir: &Path) {
        let spools = fs::read_dir(&v.spool_dir).unwrap().count();
        assert_eq!(spools, 0, "a spool file outlived its commit");
        let lands: Vec<String> = fs::read_dir(dir)
            .unwrap()
            .flatten()
            .map(|e| e.file_name().to_string_lossy().to_string())
            .filter(|n| n.starts_with(crate::names::LAND_PREFIX))
            .collect();
        assert!(lands.is_empty(), "landing copies left standing: {lands:?}");
    }

    #[test]
    #[cfg(target_os = "linux")]
    fn a_download_lands_on_a_root_on_another_volume() {
        // B1: the spool lives with the state store, and a sync root on an
        // external disk is on another volume. A rename cannot cross; before
        // this the commit failed, deleted the spool, and nothing from the
        // server ever arrived. Real volumes, no seam: the state under the temp
        // directory, the root on /dev/shm (tmpfs).
        let state = TempDir::new("across");
        let shm = TempDir(PathBuf::from("/dev/shm").join(format!(
            "jd-vfs-across-{}-{:?}",
            std::process::id(),
            std::thread::current().id()
        )));
        let _ = fs::remove_dir_all(shm.path());
        let root = shm.path().join("root");
        fs::create_dir_all(&root).unwrap();
        // The premise, checked rather than assumed: a pin that ran on one
        // volume would prove nothing and pass.
        let probe = state.path().join("probe");
        fs::write(&probe, b"x").unwrap();
        let crossing = fs::rename(&probe, root.join("probe")).unwrap_err();
        assert_eq!(crossing.kind(), std::io::ErrorKind::CrossesDevices, "the two volumes must really differ");
        let v = OsVfs::new(root.clone(), state.path().join("spool")).unwrap();

        let edited = root.join("known.txt");
        fs::write(&edited, b"original").unwrap();
        let seen = v.fingerprint(&edited).unwrap().unwrap();
        let mut spool = v.spool(&edited, 7).unwrap();
        spool.write_all(b"the server's newer version").unwrap();
        let fp = spool.commit(&edited, Some(seen)).unwrap();
        assert_eq!(fs::read(&edited).unwrap(), b"the server's newer version");
        assert_eq!(Some(fp), v.fingerprint(&edited).unwrap(), "the fingerprint is the landed file's own");

        let fresh = root.join("Inbox").join("fresh.txt");
        let mut spool = v.spool(&fresh, 8).unwrap();
        spool.write_all(b"new from the server").unwrap();
        spool.commit(&fresh, None).unwrap();
        assert_eq!(fs::read(&fresh).unwrap(), b"new from the server");

        no_litter(&v, &root);
        no_litter(&v, &root.join("Inbox"));
    }

    #[test]
    fn a_landing_never_replaces_a_file_already_at_its_name() {
        // The landing name is the engine's, but a file can stand there that
        // the engine did not put there. It is not this commit's to replace.
        let d = TempDir::new("across-taken");
        let v = vfs(&d);
        let root = v.root().unwrap();
        let target = root.join("t.txt");
        let mut spool = spool_across(&v, &target, |_| {});
        let token = spool.path.file_name().unwrap().to_string_lossy().trim_start_matches(".jd-tmp-").to_string();
        let squatter = root.join(crate::names::land_name(7, &token));
        fs::write(&squatter, b"not the engine's").unwrap();
        spool.write_all(b"bytes").unwrap();
        assert!(spool.commit(&target, None).is_err());
        assert_eq!(fs::read(&squatter).unwrap(), b"not the engine's");
        assert!(!target.exists());
    }

    #[test]
    fn a_file_edited_while_a_download_is_copied_across_volumes_is_kept() {
        // The copy is the widest window this commit has. The user's save in
        // it is the edit the guard exists for, so the gates are asked again
        // after the copy, not only before.
        let d = TempDir::new("across-edit");
        let v = vfs(&d);
        let root = v.root().unwrap();
        let target = root.join("contested.txt");
        fs::write(&target, b"original").unwrap();
        let seen = v.fingerprint(&target).unwrap().unwrap();

        let mut spool = spool_across(&v, &target, |t| fs::write(t, b"the user's edit, made mid-copy").unwrap());
        spool.write_all(b"stale download").unwrap();
        let err = spool.commit(&target, Some(seen)).unwrap_err();

        assert!(matches!(err, VfsError::AlreadyExists(_)), "got {err:?}");
        assert_eq!(fs::read(&target).unwrap(), b"the user's edit, made mid-copy");
        no_litter(&v, &root);
    }

    #[test]
    fn a_folder_replaced_while_a_download_is_copied_across_volumes_gets_nothing() {
        let d = TempDir::new("across-folder");
        let v = vfs(&d);
        let root = v.root().unwrap();
        fs::create_dir(root.join("P")).unwrap();
        let target = root.join("P").join("x.txt");

        let mut spool = spool_across(&v, &target, |t| {
            let folder = t.parent().unwrap();
            fs::rename(folder, folder.with_file_name("P2")).unwrap();
            fs::create_dir(folder).unwrap();
        });
        spool.write_all(b"bytes").unwrap();
        let err = spool.commit(&target, None).unwrap_err();

        assert!(matches!(&err, VfsError::FolderMoved(p) if p == &root.join("P")), "got {err:?}");
        assert!(!target.exists(), "nothing lands in a folder the user put in its place");
        assert_eq!(fs::read_dir(&v.spool_dir).unwrap().count(), 0);
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

        let mut spool = v.spool(&target, 0).unwrap();
        spool.write_all(b"the download").unwrap();
        fs::write(&target, b"something the user just saved").unwrap();
        let err = spool.commit(&target, None).unwrap_err();

        assert!(matches!(err, VfsError::AlreadyExists(_)));
        assert_eq!(fs::read(&target).unwrap(), b"something the user just saved");
    }

    #[test]
    fn a_files_identity_survives_a_rename_and_a_new_file_has_its_own() {
        let d = TempDir::new("identity");
        let a = d.path().join("a.txt");
        fs::write(&a, b"x").unwrap();
        let first = identity_at(&a).unwrap();
        let b = d.path().join("b.txt");
        fs::rename(&a, &b).unwrap();
        assert_eq!(identity_at(&b).unwrap(), first, "a rename keeps the file");
        // A new file under the old name -- the safe-save's second half -- is
        // another file, while the first still exists.
        fs::write(&a, b"y").unwrap();
        assert_ne!(identity_at(&a).unwrap(), first);
        // The probe trusts this volume exactly when it reports a birth and
        // keeps the id across a rename, which the lines above just did.
        assert_eq!(Personality::probe(d.path()).stable_file_identity, first.is_strong());
    }

    #[test]
    fn committing_creates_missing_parent_directories() {
        let d = TempDir::new("mkparent");
        let v = vfs(&d);
        let target = v.root().unwrap().join("deep/nested/file.txt");

        let mut spool = v.spool(&target, 0).unwrap();
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
        let mut spool = v.spool(&child, 0).unwrap();
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
    fn a_directory_keeps_its_identity_across_a_rename_and_two_directories_differ() {
        // What directory identity rests on (`specs/drive_directory_identity.md`):
        // the id is stable across a rename within the volume, two directories
        // never share one, and `fingerprint` still answers None for a
        // directory because a dozen callers read its Some as "a file is here".
        let d = TempDir::new("dirid");
        let v = vfs(&d);
        let root = v.root().unwrap();
        fs::create_dir(root.join("one")).unwrap();
        fs::create_dir(root.join("two")).unwrap();
        let one = v.directory_id(&root.join("one")).unwrap().unwrap();
        let two = v.directory_id(&root.join("two")).unwrap().unwrap();
        assert_ne!(one, two, "two directories share one id");
        assert_ne!(one, 0, "an id of 0 means unknown, and this one is known");
        fs::rename(root.join("one"), root.join("uno")).unwrap();
        assert_eq!(v.directory_id(&root.join("uno")).unwrap(), Some(one), "a rename changed the id");
        assert_eq!(v.directory_id(&root.join("one")).unwrap(), None);
        assert_eq!(v.fingerprint(&root.join("uno")).unwrap(), None, "fingerprint must stay None for a directory");
        fs::write(root.join("f.txt"), b"x").unwrap();
        assert_eq!(v.directory_id(&root.join("f.txt")).unwrap(), None, "a file has no directory id");
        // And the listing carries the same id and birth, with the fields
        // that mean nothing for a directory pinned to zero.
        let listed = v.read_dir(&root).unwrap();
        let uno = listed.iter().find(|e| e.name == "uno").unwrap();
        let born = v.directory_identity(&root.join("uno")).unwrap().unwrap();
        assert_eq!(born.file_id, one);
        assert_eq!(uno.fingerprint, Some(Fingerprint::of_directory(one, born.birth_ns)));
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
    #[cfg(target_os = "macos")]
    fn trashing_on_macos_needs_no_permission_to_control_finder() {
        // A daemon has no permission to script Finder, and asking Finder to
        // trash waited two minutes and failed (-1712) on every trash. Run
        // from an SSH session, which holds no such permission either, this
        // is exactly the daemon's position.
        let d = TempDir::new("mac-trash");
        let v = vfs(&d);
        let doomed = v.root().unwrap().join("jd-vfs-trash-test.txt");
        fs::write(&doomed, b"bound for the Trash").unwrap();
        let started = std::time::Instant::now();
        v.trash(&doomed).unwrap();
        assert!(!doomed.exists(), "the file left the tree");
        assert!(
            started.elapsed() < std::time::Duration::from_secs(30),
            "a trash took {:?}: something is waiting on a permission",
            started.elapsed()
        );
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
        let mut spool = v.spool(&v.root().unwrap().join("t.txt"), 0).unwrap();
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
