//! Where a sync client's files belong on each operating system.
//!
//! Three directories, and the distinction between them is not cosmetic.
//!
//! - **Config** — the instance URL, the sync-root path, selective-sync choices.
//!   Small, worth backing up, meaningless without the credentials.
//! - **State** — the SQLite store, the spool, the secret file where there is no
//!   keychain. Large, machine-specific, and **never inside the synced tree**:
//!   a state store that syncs itself is a client whose record of what it agreed
//!   to gets overwritten by another machine's record of what *it* agreed to.
//! - **Logs** — rotated JSONL, read by a person diagnosing a stall.
//!
//! Every path is derived from environment variables with documented fallbacks,
//! so a test can point the whole client at a temporary directory by setting one
//! of them.

use std::path::{Path, PathBuf};

/// The reverse-DNS identifier the platforms want, and the folder name the user
/// sees. Kept together because they have to agree.
pub const BUNDLE_ID: &str = "com.joinery.drive";
pub const APP_NAME: &str = "Joinery Drive";
pub const BINARY_NAME: &str = "joinery-drive";

/// Everything the client needs a path for.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Paths {
    pub config: PathBuf,
    pub state: PathBuf,
    pub logs: PathBuf,
}

impl Paths {
    /// The standard locations for this platform.
    ///
    /// `JOINERY_DRIVE_HOME`, when set, overrides all three at once and puts them
    /// side by side. That exists for tests and for a user running two instances,
    /// and it is one variable rather than three because three would let someone
    /// point the config at one account and the state at another — which reads as
    /// mass corruption on the next pass.
    pub fn discover() -> Paths {
        if let Some(home) = env_path("JOINERY_DRIVE_HOME") {
            return Paths {
                config: home.join("config"),
                state: home.join("state"),
                logs: home.join("logs"),
            };
        }
        Paths::for_home(&home_dir())
    }

    /// The standard locations relative to a given home directory. Split out so
    /// the layout for every platform is testable from any platform.
    pub fn for_home(home: &Path) -> Paths {
        #[cfg(target_os = "macos")]
        {
            let support = home.join("Library/Application Support").join(BUNDLE_ID);
            Paths {
                config: support.join("config"),
                state: support.join("state"),
                logs: home.join("Library/Logs").join(BUNDLE_ID),
            }
        }
        #[cfg(target_os = "windows")]
        {
            // Roaming holds what should follow the user between machines;
            // Local holds what must not. The state store is emphatically the
            // second: a roaming profile that copied one machine's record of what
            // it had agreed to onto another machine would have the second one
            // act on the first one's memory.
            let roaming = env_path("APPDATA").unwrap_or_else(|| home.join("AppData/Roaming"));
            let local = env_path("LOCALAPPDATA").unwrap_or_else(|| home.join("AppData/Local"));
            Paths {
                config: roaming.join(APP_NAME).join("config"),
                state: local.join(APP_NAME).join("state"),
                logs: local.join(APP_NAME).join("logs"),
            }
        }
        #[cfg(not(any(target_os = "macos", target_os = "windows")))]
        {
            let config = env_path("XDG_CONFIG_HOME").unwrap_or_else(|| home.join(".config"));
            let state = env_path("XDG_STATE_HOME").unwrap_or_else(|| home.join(".local/state"));
            Paths {
                config: config.join(BINARY_NAME),
                state: state.join(BINARY_NAME),
                logs: state.join(BINARY_NAME).join("logs"),
            }
        }
    }

    /// Create all three. Called once at startup, so that everything downstream
    /// can assume they exist.
    pub fn create(&self) -> std::io::Result<()> {
        for dir in [&self.config, &self.state, &self.logs] {
            std::fs::create_dir_all(dir)?;
        }
        Ok(())
    }

    pub fn config_file(&self) -> PathBuf {
        self.config.join("config.json")
    }

    pub fn state_db(&self) -> PathBuf {
        self.state.join("state.db")
    }

    /// Where downloads are assembled before they become visible.
    ///
    /// Under the state directory rather than inside the sync root, so a spool
    /// file is never something the user sees, and never something the scanner
    /// has to be told to ignore. It is on the same volume as the state store,
    /// which is usually — not always — the same volume as the root; when it is
    /// not, the commit is a copy instead of a rename and still correct.
    pub fn spool(&self) -> PathBuf {
        self.state.join("spool")
    }

    /// The socket or named pipe the tray and the CLI talk to the daemon over.
    pub fn control_socket(&self) -> PathBuf {
        #[cfg(windows)]
        {
            // Named pipes are not filesystem paths; the name is what matters.
            PathBuf::from(format!(r"\\.\pipe\{}", BINARY_NAME))
        }
        #[cfg(not(windows))]
        {
            self.state.join("control.sock")
        }
    }
}

/// Where the synced folder goes when the user does not say.
///
/// Their home directory, under the product's name, because that is where every
/// other sync client puts one and because it is somewhere they will find it
/// without being told.
pub fn default_sync_root() -> PathBuf {
    home_dir().join(APP_NAME)
}

pub fn home_dir() -> PathBuf {
    #[cfg(windows)]
    {
        if let Some(p) = env_path("USERPROFILE") {
            return p;
        }
    }
    env_path("HOME").unwrap_or_else(|| PathBuf::from("."))
}

fn env_path(name: &str) -> Option<PathBuf> {
    match std::env::var(name) {
        Ok(v) if !v.is_empty() => Some(PathBuf::from(v)),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_state_store_never_lands_inside_the_synced_folder() {
        // A state store that syncs itself is a client whose record of what it
        // agreed to gets overwritten by another machine's record of what that
        // machine agreed to — and then both act on the wrong memory.
        let paths = Paths::for_home(Path::new("/home/u"));
        let root = PathBuf::from("/home/u").join(APP_NAME);
        for dir in [&paths.state, &paths.config, &paths.logs] {
            assert!(
                !dir.starts_with(&root),
                "{dir:?} must not be inside {root:?}"
            );
        }
        assert!(!paths.spool().starts_with(&root));
    }

    #[test]
    fn one_variable_moves_the_whole_installation_and_keeps_it_together() {
        // Three separate overrides would let someone point the config at one
        // account and the state at another, which reads as mass corruption on
        // the very next pass.
        let tmp = std::env::temp_dir().join(format!("jd-dirs-{}", std::process::id()));
        std::env::set_var("JOINERY_DRIVE_HOME", &tmp);
        let paths = Paths::discover();
        std::env::remove_var("JOINERY_DRIVE_HOME");

        assert!(paths.config.starts_with(&tmp));
        assert!(paths.state.starts_with(&tmp));
        assert!(paths.logs.starts_with(&tmp));
        assert_ne!(paths.config, paths.state);
    }

    #[test]
    fn creating_the_directories_is_safe_to_repeat() {
        let tmp = std::env::temp_dir().join(format!("jd-dirs-create-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&tmp);
        let paths = Paths {
            config: tmp.join("c"),
            state: tmp.join("s"),
            logs: tmp.join("l"),
        };
        paths.create().unwrap();
        paths.create().unwrap();
        assert!(paths.state.is_dir());
        let _ = std::fs::remove_dir_all(&tmp);
    }

    #[test]
    fn the_default_sync_root_is_somewhere_a_person_will_find_it() {
        assert!(default_sync_root().ends_with(APP_NAME));
    }

    #[test]
    fn the_state_store_and_the_spool_share_a_volume() {
        // So that committing a download is a rename rather than a copy of the
        // whole file.
        let paths = Paths::for_home(Path::new("/home/u"));
        assert!(paths.spool().starts_with(&paths.state));
        assert!(paths.state_db().starts_with(&paths.state));
    }
}
