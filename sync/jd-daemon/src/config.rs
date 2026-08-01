//! What this installation is set to, minus anything secret.
//!
//! Secrets live in the credential store (`jd_platform::SecretStore`) and never
//! here. The split is not tidiness: this file is small, worth backing up, and
//! frequently shown to a person diagnosing something, and a config file that
//! also holds the API secret cannot be any of those things.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

/// How often to ask the server what changed, when nothing else has prompted a
/// look.
///
/// Thirty seconds is the design's answer (there is no push channel yet), and the
/// feed is cheap enough that it costs one small request per device per half
/// minute. Anything that mutates the server triggers an immediate poll, so this
/// only sets how quickly *another* device's change is noticed.
pub const DEFAULT_POLL_SECONDS: u64 = 30;

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct Config {
    /// The instance this device is linked to. There is no default: this is a
    /// platform, not one service, and guessing somebody's server is not a
    /// convenience.
    pub base_url: String,
    /// The folder being synced.
    pub sync_root: PathBuf,
    /// What the server calls this device, and what appears in conflict-copy
    /// names.
    pub device_name: String,
    /// The `SyncDevice` row id, for the management surface.
    #[serde(default)]
    pub device_id: Option<i64>,
    /// The public half of the session key. The secret half is in the credential
    /// store; this is here so `status` can say which key is in use without
    /// reading a secret to do it.
    pub public_key: String,
    #[serde(default = "default_poll_seconds")]
    pub poll_seconds: u64,
    /// Subtrees the user has opted out of, as paths below the root.
    #[serde(default)]
    pub excluded: Vec<String>,
    /// Whether encrypted folders were enabled on this device at link time.
    #[serde(default)]
    pub vault_enabled: bool,
    /// Start with the user's session.
    #[serde(default)]
    pub autostart: bool,
}

fn default_poll_seconds() -> u64 {
    DEFAULT_POLL_SECONDS
}

#[derive(Debug, thiserror::Error)]
pub enum ConfigError {
    #[error("not linked to an instance yet — run: joinery-drive login <url>")]
    NotLinked,
    #[error("the config file at {path} is not readable as config: {detail}")]
    Corrupt { path: PathBuf, detail: String },
    #[error("io error on {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
}

impl Config {
    pub fn load(path: &Path) -> Result<Config, ConfigError> {
        let raw = match std::fs::read_to_string(path) {
            Ok(r) => r,
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => {
                return Err(ConfigError::NotLinked)
            }
            Err(e) => {
                return Err(ConfigError::Io {
                    path: path.to_path_buf(),
                    source: e,
                })
            }
        };
        serde_json::from_str(&raw).map_err(|e| ConfigError::Corrupt {
            path: path.to_path_buf(),
            detail: e.to_string(),
        })
    }

    /// Write the config, replacing it atomically.
    ///
    /// Through a temporary file and a rename, because the alternative — writing
    /// in place — has a window in which the file is truncated. A power cut in
    /// that window leaves a client that has forgotten which server it belongs
    /// to and which folder it was syncing, holding a state store full of
    /// decisions about both.
    pub fn save(&self, path: &Path) -> Result<(), ConfigError> {
        if let Some(dir) = path.parent() {
            std::fs::create_dir_all(dir).map_err(|e| ConfigError::Io {
                path: dir.to_path_buf(),
                source: e,
            })?;
        }
        let body = serde_json::to_string_pretty(self).expect("config serializes");
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, body).map_err(|e| ConfigError::Io {
            path: tmp.clone(),
            source: e,
        })?;
        std::fs::rename(&tmp, path).map_err(|e| ConfigError::Io {
            path: path.to_path_buf(),
            source: e,
        })
    }

    /// Is this path inside a subtree the user opted out of?
    ///
    /// Compared component by component, so opting out of `Photos` does not also
    /// exclude `Photos of the dog.zip`.
    pub fn is_excluded(&self, relative_path: &str) -> bool {
        self.excluded.iter().any(|prefix| {
            relative_path == prefix
                || relative_path.starts_with(&format!("{}/", prefix.trim_end_matches('/')))
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sample() -> Config {
        Config {
            base_url: "https://dev.getjoinery.com".into(),
            sync_root: PathBuf::from("/home/u/Joinery Drive"),
            device_name: "Laptop".into(),
            device_id: Some(7),
            public_key: "pk-public-half".into(),
            poll_seconds: DEFAULT_POLL_SECONDS,
            excluded: vec!["Photos".into()],
            vault_enabled: false,
            autostart: true,
        }
    }

    fn temp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-config-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    #[test]
    fn a_config_survives_a_round_trip_unchanged() {
        let dir = temp("roundtrip");
        let path = dir.join("config.json");
        sample().save(&path).unwrap();
        assert_eq!(Config::load(&path).unwrap(), sample());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn no_secret_is_ever_written_to_the_config_file() {
        // The file is small, worth backing up, and routinely pasted into a
        // support conversation. It cannot be any of those things if the API
        // secret is in it.
        let dir = temp("nosecret");
        let path = dir.join("config.json");
        sample().save(&path).unwrap();
        let raw = std::fs::read_to_string(&path).unwrap();
        assert!(raw.contains("public_key"));
        assert!(!raw.contains("secret"), "config carries no secret half");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn no_config_at_all_says_what_to_run_rather_than_reporting_an_io_error() {
        let dir = temp("missing");
        let err = Config::load(&dir.join("config.json")).unwrap_err();
        assert!(matches!(err, ConfigError::NotLinked));
        assert!(err.to_string().contains("joinery-drive login"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_damaged_config_is_reported_and_not_quietly_replaced_with_defaults() {
        // Defaulting here would point a client that had been syncing one folder
        // at a different one, and the first pass would read that as everything
        // having been deleted.
        let dir = temp("corrupt");
        let path = dir.join("config.json");
        std::fs::write(&path, "{ this is not json").unwrap();
        assert!(matches!(
            Config::load(&path),
            Err(ConfigError::Corrupt { .. })
        ));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_config_written_by_an_older_build_still_loads() {
        // Every field added since is optional with a sensible default, so an
        // upgrade does not present itself as a corrupt config.
        let dir = temp("older");
        let path = dir.join("config.json");
        std::fs::write(
            &path,
            r#"{"base_url":"https://x","sync_root":"/r","device_name":"D","public_key":"pk"}"#,
        )
        .unwrap();
        let cfg = Config::load(&path).unwrap();
        assert_eq!(cfg.poll_seconds, DEFAULT_POLL_SECONDS);
        assert!(cfg.excluded.is_empty());
        assert!(!cfg.autostart);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn saving_leaves_no_temporary_file_behind() {
        let dir = temp("atomic");
        let path = dir.join("config.json");
        sample().save(&path).unwrap();
        sample().save(&path).unwrap();
        let names: Vec<String> = std::fs::read_dir(&dir)
            .unwrap()
            .flatten()
            .map(|e| e.file_name().to_string_lossy().to_string())
            .collect();
        assert_eq!(names, vec!["config.json".to_string()]);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn excluding_a_folder_does_not_exclude_a_file_whose_name_starts_the_same_way() {
        let cfg = sample();
        assert!(cfg.is_excluded("Photos"));
        assert!(cfg.is_excluded("Photos/2026/a.jpg"));
        assert!(!cfg.is_excluded("Photos of the dog.zip"));
        assert!(!cfg.is_excluded("Documents/Photos"));
    }
}
