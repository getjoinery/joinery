//! What a filesystem will and will not do.
//!
//! The engine is written against one clean tree: case-sensitive, NFC, legal
//! names only. Real filesystems are not that, and each is not that in its own
//! way — macOS hands back decomposed names and ignores case, Windows rejects a
//! colon and reserves `CON`, ext4 does neither. Every one of those differences
//! is captured here rather than being sprinkled through the reconciler, because
//! the alternative is a matrix of `#[cfg]` branches inside the logic that
//! decides whether to delete somebody's file.
//!
//! A personality is data, not a compile-time target, which is what lets the
//! simulator run the Windows rules on Linux and catch a Windows-only bug
//! without a Windows machine.

/// How a filesystem treats names.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Personality {
    /// Do `Report.txt` and `report.txt` name the same file?
    pub case_insensitive: bool,
    /// Does the filesystem hand back decomposed (NFD) names regardless of what
    /// was written? HFS+ does, and some network shares; this tells the scanner
    /// not to treat the round-trip as a rename.
    pub decomposes_unicode: bool,
    /// Do `caf\u{e9}` and `cafe\u{301}` name the same file?
    ///
    /// Separate from `decomposes_unicode`, and the distinction is the whole
    /// point: HFS+ REWRITES the name, APFS keeps the bytes it was given and
    /// merely COMPARES without regard to spelling, and ext4 and NTFS do
    /// neither — there, the two spellings are two files, and a user may quite
    /// reasonably have both.
    ///
    /// Folding them everywhere is what this replaced, and it does not fail
    /// quietly: two names that differ only in normalization look like one slot
    /// with two claimants, so the loser is parked `UnicodeClash` — which says
    /// the file *cannot exist here* — on a disk where it plainly can. It never
    /// materializes, and nothing about a settled tree ever releases it. The
    /// platform sweep found it on Linux, which has no naming restrictions at
    /// all.
    pub normalization_insensitive: bool,
    /// Characters the filesystem refuses outright.
    pub illegal_chars: &'static [char],
    /// Stems that name a device rather than a file, whatever the extension.
    pub reserved_stems: &'static [&'static str],
    /// Are trailing dots and spaces silently stripped? (Windows does this,
    /// which turns `report.` into `report` behind your back.)
    pub strips_trailing_dots_and_spaces: bool,
    /// Longest single name component, in bytes.
    pub max_name_bytes: usize,
    /// Longest whole path below the sync root, in bytes.
    ///
    /// Separate from the per-name limit because they fail differently: an
    /// over-long *name* is one file nobody can create anywhere, while an
    /// over-long *path* is a perfectly ordinary file that happens to sit too
    /// deep — a tree written on macOS and synced to Windows hits this without
    /// any single name being unusual. Without the limit the operation fails
    /// with an opaque error and retries forever; with it, the user is told
    /// which file and why.
    pub max_path_bytes: usize,
    /// Coarsest modification-time granularity, in nanoseconds. FAT reports two
    /// seconds; a fingerprint comparison has to tolerate at least this much
    /// before it decides a file changed.
    pub mtime_granularity_ns: u64,
    /// Does a file's id and birth ([`crate::FileIdentity`]) name that one file
    /// for as long as it exists? True where the id survives a rename and the
    /// birth is real. False on FAT and exFAT, whose "ids" are the position of
    /// a directory entry and change when the entry moves, and on any volume
    /// [`Personality::probe`] catches changing an id on a rename or reporting
    /// no birth, or a birth that is not the time the file was made. Where it
    /// is false every file's identity is weak, and the engine reads the disk
    /// by its older rules (`specs/drive_file_identity.md`).
    pub stable_file_identity: bool,
    /// Is a file's id no identity at all, only where the file happens to sit?
    /// True on FAT and exFAT, named by the volume's filesystem type: their ids
    /// move with a directory entry (Windows), with the file's first data
    /// cluster (macOS FAT32), or with the mount (Linux; macOS exFAT), and the
    /// rename probe cannot catch the last two. Where it is true the volume
    /// reports every id and every birth as 0, so nothing in the engine reads
    /// one (`specs/drive_weak_volume_identity.md`, B1 and B1a). Implies
    /// `stable_file_identity` is false.
    pub positional_file_ids: bool,
    /// On a volume whose ids are no identity (`positional_file_ids`), may an
    /// id still break a tie between files the bytes cannot tell apart? Never
    /// identity, and never read by anything that reads identity: only a
    /// tie-break, carried beside a file as its tie-break id, never in its
    /// fingerprint (`specs/drive_weak_volume_identity.md`, the classes).
    pub id_tie_break: IdTieBreak,
    /// Is a creation time a property of the NAME rather than of the file?
    /// True on NTFS under Windows, named by the volume's filesystem name:
    /// Windows gives a file arriving at a name another file left within the
    /// last 15 seconds that file's creation time (tunnelling), so in a trade
    /// of two names the two births swap with the names, while each file
    /// keeps its index. Measured on a local NTFS volume: file births tunnel,
    /// directory births do not; the index never repeats (a reused MFT record
    /// comes back with its sequence number raised). That last is a property
    /// of local NTFS only: a share calling itself NTFS may number files by an
    /// inode that recycles, which is why every remote volume is read weak
    /// (`stable_file_identity` false) and the marker decides nothing there. Where it is true the volume
    /// reports every nonzero birth as [`INDEX_ONLY_BIRTH`], so a file's
    /// identity is its index alone, for files and directories alike
    /// (`birth_as_seen`). The rename probe cannot see tunnelling -- it
    /// renames to a name nothing left -- so this is named, never probed.
    pub births_are_names: bool,
}

/// The birth every file and directory reports on a volume whose births are
/// names (`Personality::births_are_names`). Nonzero, so an identity read
/// there is still whole (`FileIdentity::is_strong`); the same for every
/// file, so two identities there are equal exactly when the indexes are.
/// What it costs, stated: an older client reading a store written this way
/// finds no record's own file (every stored birth is the marker), and step
/// 3 of the scan takes each file standing at its record's path back by
/// path; a file moved while that older client ran reads as deleted plus
/// created. And an index recycles only after 65,536 reuses of one MFT
/// record (its 16-bit sequence number wraps).
pub const INDEX_ONLY_BIRTH: u64 = 1;

/// A birth as the engine is shown it: the marker on a volume whose births
/// are names, zero (no birth) left as zero, every other birth as read. The
/// one place that decides it; the real volume and the simulator both call it.
pub fn birth_as_seen(birth_ns: u64, births_are_names: bool) -> u64 {
    if births_are_names && birth_ns != 0 {
        INDEX_ONLY_BIRTH
    } else {
        birth_ns
    }
}

/// How far a FAT or exFAT volume's ids hold still, from the traced facts (F1).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum IdTieBreak {
    /// Not at all for the purpose: every rename can move one (Windows FAT and
    /// exFAT, a directory entry's position), and any volume not named FAT.
    None,
    /// Through renames, moves and remounts; an in-place save moves only the
    /// saved file's (macOS FAT32, the first data cluster).
    Durable,
    /// For one mount; renumbered on the next (Linux FAT and exFAT, macOS
    /// exFAT).
    MountSession,
}

const WINDOWS_ILLEGAL: &[char] = &['<', '>', ':', '"', '/', '\\', '|', '?', '*'];
const POSIX_ILLEGAL: &[char] = &['/'];

const WINDOWS_RESERVED: &[&str] = &[
    "CON", "PRN", "AUX", "NUL", "COM1", "COM2", "COM3", "COM4", "COM5", "COM6", "COM7", "COM8",
    "COM9", "LPT1", "LPT2", "LPT3", "LPT4", "LPT5", "LPT6", "LPT7", "LPT8", "LPT9",
];

impl Personality {
    /// ext4 and friends: bytes are bytes, only `/` and NUL are off limits.
    pub const fn linux() -> Self {
        Personality {
            case_insensitive: false,
            decomposes_unicode: false,
            normalization_insensitive: false,
            illegal_chars: POSIX_ILLEGAL,
            reserved_stems: &[],
            strips_trailing_dots_and_spaces: false,
            max_name_bytes: 255,
            max_path_bytes: 4096,
            mtime_granularity_ns: 1,
            stable_file_identity: true,
            positional_file_ids: false,
            id_tie_break: IdTieBreak::None,
            births_are_names: false,
        }
    }

    /// APFS as shipped: case-insensitive, and **normalization-preserving**.
    ///
    /// The second half is the one that surprises people, including whoever
    /// wrote this the first time. HFS+ normalized every name to NFD on the way
    /// in, and "macOS decomposes your filenames" became folklore that outlived
    /// the filesystem. APFS, which replaced it in 2017, stores exactly the bytes
    /// it was given and merely *compares* insensitively — so a name written
    /// composed comes back composed.
    ///
    /// Getting this wrong in the assumed default is survivable only because the
    /// real client does not use the assumed default: [`Personality::probe`] asks
    /// the volume. Which is the argument for probing, made by the thing it
    /// caught — a Mac gate found this, on a real Mac, against code that had been
    /// tested against a simulator faithfully reproducing a filesystem nobody has
    /// used for years.
    pub const fn macos() -> Self {
        Personality {
            case_insensitive: true,
            decomposes_unicode: false,
            // APFS keeps the spelling and ignores it when comparing.
            normalization_insensitive: true,
            illegal_chars: POSIX_ILLEGAL,
            reserved_stems: &[],
            strips_trailing_dots_and_spaces: false,
            max_name_bytes: 255,
            max_path_bytes: 1024,
            mtime_granularity_ns: 1,
            stable_file_identity: true,
            positional_file_ids: false,
            id_tie_break: IdTieBreak::None,
            births_are_names: false,
        }
    }

    /// A volume that really does decompose.
    ///
    /// Still worth modelling, and not a museum piece: HFS+ volumes are still
    /// mounted (older external drives, Time Machine disks), and network shares
    /// normalize on their own terms. The engine cannot tell any of them apart
    /// from a probe's answer, and does not need to.
    pub const fn hfs_plus() -> Self {
        Personality {
            decomposes_unicode: true,
            ..Personality::macos()
        }
    }

    /// NTFS: case-insensitive, a list of forbidden characters, DOS device
    /// names still reserved four decades on.
    pub const fn windows() -> Self {
        Personality {
            case_insensitive: true,
            decomposes_unicode: false,
            // NTFS compares UTF-16 code units. Two spellings, two files.
            normalization_insensitive: false,
            illegal_chars: WINDOWS_ILLEGAL,
            reserved_stems: WINDOWS_RESERVED,
            strips_trailing_dots_and_spaces: true,
            max_name_bytes: 255,
            // Every filesystem call goes out as an extended-length (`\\?\`)
            // path, so the famous 260-character limit does not apply and the
            // real ceiling is the Win32 32767-wide-character one. Sixteen
            // characters of headroom below it for the drive prefix and the
            // engine's own scratch names.
            max_path_bytes: 32_000,
            mtime_granularity_ns: 100,
            stable_file_identity: true,
            positional_file_ids: false,
            id_tie_break: IdTieBreak::None,
            births_are_names: true,
        }
    }

    /// A removable drive formatted FAT32: everything Windows refuses, plus a
    /// modification time that only moves in two-second steps.
    pub const fn fat32() -> Self {
        Personality {
            mtime_granularity_ns: 2_000_000_000,
            stable_file_identity: false,
            positional_file_ids: true,
            births_are_names: false,
            ..Personality::windows()
        }
    }

    /// Ask the volume itself, instead of assuming from the operating system.
    ///
    /// Two of these traits belong to the *volume*, not the OS, and guessing
    /// them from `target_os` is wrong often enough to matter: a developer's
    /// case-sensitive APFS volume, a Windows directory with per-directory
    /// case sensitivity enabled, an exFAT stick mounted on Linux, a network
    /// share that decomposes. Guessing wrong in the permissive direction
    /// materializes two files the volume can only hold one of, and one of them
    /// silently becomes the other. Guessing wrong in the strict direction
    /// refuses a file that would have been fine.
    ///
    /// So we run the experiment: write one probe file, ask for it back under a
    /// different spelling, and believe the answer. It costs two file creations
    /// once per sync root, at startup.
    ///
    /// Everything else stays as the compile-time default, because the rest
    /// really is an OS property — Win32 refuses a colon whatever the volume is
    /// formatted as.
    ///
    /// A probe that cannot run (read-only directory, no space) returns the
    /// native default rather than failing: the engine has to start, and the
    /// defaults are the conservative answer for the platform.
    pub fn probe(dir: &std::path::Path) -> Personality {
        let mut p = Personality::native();
        let token = format!("{}-{:?}", std::process::id(), std::thread::current().id());
        // NFC-composed é in the name, so the decomposition question has
        // something to answer with.
        let base = format!("{}probe-caf\u{e9}-{}", crate::names::INTERNAL_PREFIX, token);
        let path = dir.join(&base);
        let _ = std::fs::remove_file(&path);
        if std::fs::write(&path, b"probe").is_err() {
            // The platform's names are its safe guess; for identity the safe
            // guess is none, which costs only the older rules.
            p.stable_file_identity = false;
            p.positional_file_ids = crate::real::ids_are_positions_on_this_volume(dir);
            p.id_tie_break = crate::real::id_tie_break_on_this_volume(dir);
            p.births_are_names = crate::real::births_are_names_on_this_volume(dir);
            return p;
        }

        // Does asking for the same name in a different case find it? If it
        // does, the volume cannot tell `Report.txt` from `report.txt`.
        let shouted = dir.join(base.to_uppercase());
        if shouted != path {
            p.case_insensitive = std::fs::metadata(&shouted).is_ok();
        }

        // Did the name come back in a different normal form than it went in?
        if let Ok(rd) = std::fs::read_dir(dir) {
            for entry in rd.flatten() {
                let name = entry.file_name().to_string_lossy().to_string();
                if crate::names::nfc(&name) == base {
                    p.decomposes_unicode = name != base;
                    break;
                }
            }
        }

        // Does asking for the same name in the other normal form find it? A
        // volume that rewrites the spelling necessarily cannot hold both, so it
        // answers yes here too -- but a volume that merely compares without
        // regard to spelling answers yes while changing nothing, and that is
        // the case no other question here reaches.
        let respelled = dir.join(crate::names::nfd(&base));
        if respelled != path {
            p.normalization_insensitive = std::fs::metadata(&respelled).is_ok();
        } else {
            p.normalization_insensitive = p.decomposes_unicode;
        }

        // Does a file keep one identity for as long as it exists? Read the
        // probe's id and birth, rename it in place to a much longer name (on
        // FAT that moves the directory entry, which is what its "id" is), and
        // read them again. Weak if the id moved, if either half is missing, or
        // if the birth is not the moment the file was made: a constant or an
        // epoch birth would make the pair a bare file id again. Read raw
        // (`identity_at`), before any marker: on a volume whose births are
        // names the engine is shown INDEX_ONLY_BIRTH, which is a statement
        // that the index alone is the identity there, not a missing birth.
        let renamed = dir.join(format!(
            "{base}-renamed-under-a-much-longer-name-so-its-entry-has-to-move"
        ));
        let before = crate::real::identity_at(&path);
        p.stable_file_identity = match std::fs::rename(&path, &renamed) {
            Ok(()) => {
                let after = crate::real::identity_at(&renamed);
                let _ = std::fs::remove_file(&renamed);
                let now_ns = std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .map(|d| u64::try_from(d.as_nanos()).unwrap_or(u64::MAX))
                    .unwrap_or(0);
                match (before, after) {
                    (Some(b), Some(a)) => {
                        b.is_strong()
                            && a == b
                            && now_ns.abs_diff(b.birth_ns) < 60_000_000_000
                            && !crate::real::ids_not_unique_on_this_volume(dir)
                    }
                    _ => false,
                }
            }
            Err(_) => false,
        };
        // Named, not only asked: on Linux a FAT id survives a rename and the
        // volume reports the creation time, so the probe above reads it
        // strong, and every id changes at the next mount (F1).
        if crate::real::ids_are_positions_on_this_volume(dir) {
            p.positional_file_ids = true;
            p.stable_file_identity = false;
            p.id_tie_break = crate::real::id_tie_break_on_this_volume(dir);
        }

        // Named, never probed: the rename above lands on a name nothing left,
        // so it cannot see tunnelling (`births_are_names`).
        p.births_are_names = crate::real::births_are_names_on_this_volume(dir);

        let _ = std::fs::remove_file(&path);
        p
    }

    /// A fingerprint as the engine is shown it on this volume: no id and no
    /// birth where ids are only positions, the index alone where births are
    /// names.
    pub fn seen(&self, fp: crate::Fingerprint) -> crate::Fingerprint {
        if self.positional_file_ids {
            crate::Fingerprint { file_id: 0, birth_ns: 0, ..fp }
        } else {
            crate::Fingerprint { birth_ns: birth_as_seen(fp.birth_ns, self.births_are_names), ..fp }
        }
    }

    /// An identity as the engine is shown it on this volume (`seen`).
    pub fn seen_identity(&self, id: crate::FileIdentity) -> crate::FileIdentity {
        if self.positional_file_ids {
            crate::FileIdentity { file_id: 0, birth_ns: 0 }
        } else {
            crate::FileIdentity { birth_ns: birth_as_seen(id.birth_ns, self.births_are_names), ..id }
        }
    }

    /// The personality of the machine this build is running on.
    pub const fn native() -> Self {
        #[cfg(target_os = "windows")]
        {
            Personality::windows()
        }
        #[cfg(target_os = "macos")]
        {
            Personality::macos()
        }
        #[cfg(not(any(target_os = "windows", target_os = "macos")))]
        {
            Personality::linux()
        }
    }
}

impl Default for Personality {
    fn default() -> Self {
        Personality::native()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn probing_this_volume_agrees_with_itself_across_runs() {
        // Whatever the answer is on the machine running the tests, it has to be
        // the same answer twice — an unstable probe would make the engine
        // change its mind about which files can exist.
        let dir = std::env::temp_dir().join(format!("jd-probe-{}", std::process::id()));
        let _ = std::fs::create_dir_all(&dir);
        assert_eq!(Personality::probe(&dir), Personality::probe(&dir));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn probing_leaves_nothing_behind() {
        let dir = std::env::temp_dir().join(format!("jd-probe-clean-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        Personality::probe(&dir);
        assert_eq!(std::fs::read_dir(&dir).unwrap().count(), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_unwritable_directory_probes_to_the_native_names_and_no_identity() {
        // The engine has to start. A probe that cannot run is not a reason to
        // refuse to sync; for names the compile-time default is the
        // conservative answer. For file identity it is not: ids nobody tried
        // are ids nobody trusts, and distrusting them costs only the older
        // rules.
        let missing = std::env::temp_dir().join("jd-probe-does-not-exist-at-all");
        let _ = std::fs::remove_dir_all(&missing);
        assert_eq!(
            Personality::probe(&missing),
            Personality {
                stable_file_identity: false,
                ..Personality::native()
            }
        );
    }

    #[test]
    fn the_probe_reads_this_linux_volume_as_case_sensitive_and_composing() {
        // Pinned to the dev/CI platform: ext4 tells both apart. If this ever
        // fails on Linux the probe has stopped working, which would be silent
        // otherwise.
        #[cfg(target_os = "linux")]
        {
            let dir = std::env::temp_dir().join(format!("jd-probe-lin-{}", std::process::id()));
            let _ = std::fs::create_dir_all(&dir);
            let p = Personality::probe(&dir);
            assert!(!p.case_insensitive);
            assert!(!p.decomposes_unicode);
            let _ = std::fs::remove_dir_all(&dir);
        }
    }

    #[test]
    fn a_windows_path_budget_is_the_extended_length_one_not_260() {
        // Everything goes out as a `\\?\` path, so the limit users actually hit
        // is the Win32 ceiling. Pinning it here because quietly reverting to
        // 260 would refuse ordinary deep trees from a Mac.
        assert!(Personality::windows().max_path_bytes > 260);
    }
}
