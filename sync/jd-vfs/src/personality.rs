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
        }
    }

    /// A removable drive formatted FAT32: everything Windows refuses, plus a
    /// modification time that only moves in two-second steps.
    pub const fn fat32() -> Self {
        Personality {
            mtime_granularity_ns: 2_000_000_000,
            stable_file_identity: false,
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
        // epoch birth would make the pair a bare file id again.
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

        let _ = std::fs::remove_file(&path);
        p
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
