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
    /// was written? macOS does; the engine compares in NFC either way, but this
    /// tells the scanner not to treat the round-trip as a rename.
    pub decomposes_unicode: bool,
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
            illegal_chars: POSIX_ILLEGAL,
            reserved_stems: &[],
            strips_trailing_dots_and_spaces: false,
            max_name_bytes: 255,
            max_path_bytes: 4096,
            mtime_granularity_ns: 1,
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
            illegal_chars: POSIX_ILLEGAL,
            reserved_stems: &[],
            strips_trailing_dots_and_spaces: false,
            max_name_bytes: 255,
            max_path_bytes: 1024,
            mtime_granularity_ns: 1,
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
        }
    }

    /// A removable drive formatted FAT32: everything Windows refuses, plus a
    /// modification time that only moves in two-second steps.
    pub const fn fat32() -> Self {
        Personality {
            mtime_granularity_ns: 2_000_000_000,
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
    fn an_unwritable_directory_probes_to_the_native_default() {
        // The engine has to start. A probe that cannot run is not a reason to
        // refuse to sync; the compile-time default is the conservative answer.
        let missing = std::env::temp_dir().join("jd-probe-does-not-exist-at-all");
        let _ = std::fs::remove_dir_all(&missing);
        assert_eq!(Personality::probe(&missing), Personality::native());
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
