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
            mtime_granularity_ns: 1,
        }
    }

    /// APFS/HFS+ as shipped: case-insensitive and decomposing.
    pub const fn macos() -> Self {
        Personality {
            case_insensitive: true,
            decomposes_unicode: true,
            illegal_chars: POSIX_ILLEGAL,
            reserved_stems: &[],
            strips_trailing_dots_and_spaces: false,
            max_name_bytes: 255,
            mtime_granularity_ns: 1,
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
