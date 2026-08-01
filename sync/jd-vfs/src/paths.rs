//! Path shapes the operating systems disagree about.
//!
//! Two of these bite hard enough to be worth their own module.
//!
//! **macOS canonicalization.** `/var` is a symlink to `/private/var`, and
//! FSEvents reports the resolved path. A watcher started on the symlinked
//! spelling therefore receives events whose paths do not begin with the root it
//! thinks it is watching, and every one of them is discarded — a client that
//! looks healthy and notices nothing. The fix is to resolve the root once, up
//! front, and never speak of it in any other spelling.
//!
//! **Windows extended-length paths.** Anything longer than 260 characters needs
//! the `\\?\` prefix, which the Rust standard library adds for us on the way
//! into the filesystem. What it does not do is take it off again, and several
//! things downstream — the recycle-bin API, anything shown to a user — want the
//! ordinary spelling. So the conversion exists in both directions here.
//!
//! Both functions are pure string work and are tested on every platform, which
//! is the point: a Windows path bug found on the Linux dev box is a Windows path
//! bug that never shipped.

use std::path::{Path, PathBuf};

/// The Win32 extended-length prefix.
pub const VERBATIM_PREFIX: &str = r"\\?\";

/// Does this path carry the extended-length prefix?
pub fn is_verbatim(path: &Path) -> bool {
    path.to_string_lossy().starts_with(VERBATIM_PREFIX)
}

/// The ordinary spelling of a path, with any extended-length prefix removed.
///
/// UNC paths get the prefix removed too: `\\?\UNC\server\share` is the same
/// place as `\\server\share`, and an API handed the first spelling when it
/// expects the second looks for a machine called `?`.
pub fn strip_verbatim(path: &Path) -> PathBuf {
    let s = path.to_string_lossy();
    match s.strip_prefix(VERBATIM_PREFIX) {
        None => path.to_path_buf(),
        Some(rest) => match rest.strip_prefix("UNC\\") {
            Some(unc) => PathBuf::from(format!(r"\\{unc}")),
            None => PathBuf::from(rest),
        },
    }
}

/// Resolve a sync root to the single spelling everything else will use.
///
/// Falls back to the path as given when it cannot be resolved — a root that is
/// not currently mounted must still be nameable, because "the volume is not
/// here" is a state the engine handles (it pauses) and not an error it refuses
/// to start over.
pub fn canonical_root(path: &Path) -> PathBuf {
    std::fs::canonicalize(path).unwrap_or_else(|_| path.to_path_buf())
}

/// Is `path` inside `root`?
///
/// Compares component by component rather than by string prefix, so that
/// `/home/user/Joinery Drive Backup` is not mistaken for something inside
/// `/home/user/Joinery Drive`. On a case-insensitive volume the comparison
/// folds case, because there the two spellings genuinely are the same place.
pub fn is_inside(root: &Path, path: &Path, case_insensitive: bool) -> bool {
    let fold = |c: &std::path::Component| -> String {
        let s = c.as_os_str().to_string_lossy().to_string();
        let s = crate::names::nfc(&s);
        if case_insensitive {
            s.to_lowercase()
        } else {
            s
        }
    };
    let root_parts: Vec<String> = root.components().map(|c| fold(&c)).collect();
    let path_parts: Vec<String> = path.components().map(|c| fold(&c)).collect();
    path_parts.len() >= root_parts.len() && path_parts[..root_parts.len()] == root_parts[..]
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_extended_length_prefix_comes_off_a_drive_path() {
        assert_eq!(
            strip_verbatim(Path::new(r"\\?\C:\Users\a\Joinery Drive\x.txt")),
            PathBuf::from(r"C:\Users\a\Joinery Drive\x.txt")
        );
    }

    #[test]
    fn the_extended_length_prefix_comes_off_a_network_path_as_a_network_path() {
        // `\\?\UNC\server\share` and `\\server\share` are the same place. Handing
        // the first spelling to an API expecting the second sends it looking for
        // a machine named `?`.
        assert_eq!(
            strip_verbatim(Path::new(r"\\?\UNC\server\share\x.txt")),
            PathBuf::from(r"\\server\share\x.txt")
        );
    }

    #[test]
    fn an_ordinary_path_is_left_exactly_as_it_is() {
        assert_eq!(
            strip_verbatim(Path::new("/home/user/Joinery Drive/x.txt")),
            PathBuf::from("/home/user/Joinery Drive/x.txt")
        );
        assert!(!is_verbatim(Path::new(r"C:\Users\a")));
        assert!(is_verbatim(Path::new(r"\\?\C:\Users\a")));
    }

    #[test]
    fn a_sibling_folder_with_a_longer_name_is_not_inside_the_root() {
        // String-prefix containment says `Joinery Drive Backup` is inside
        // `Joinery Drive`, and then every event in the backup folder is treated
        // as a change to a synced file.
        let root = Path::new("/home/u/Joinery Drive");
        assert!(is_inside(
            root,
            Path::new("/home/u/Joinery Drive/a.txt"),
            false
        ));
        assert!(!is_inside(
            root,
            Path::new("/home/u/Joinery Drive Backup/a.txt"),
            false
        ));
    }

    #[test]
    fn the_root_itself_counts_as_inside_it() {
        let root = Path::new("/home/u/Joinery Drive");
        assert!(is_inside(root, root, false));
    }

    #[test]
    fn case_and_normalization_only_stop_mattering_where_the_volume_says_so() {
        let root = Path::new("/Users/u/Joinery Drive");
        let shouted = Path::new("/Users/u/JOINERY DRIVE/a.txt");
        assert!(is_inside(root, shouted, true));
        assert!(!is_inside(root, shouted, false));

        // macOS hands back a decomposed root in events; it is the same folder.
        let composed = Path::new("/Users/u/Caf\u{e9}");
        let decomposed = Path::new("/Users/u/Cafe\u{301}/a.txt");
        assert!(is_inside(composed, decomposed, false));
    }
}
