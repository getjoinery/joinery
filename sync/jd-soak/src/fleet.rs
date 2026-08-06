//! What the rig is made of: one server, some devices, one place for evidence.
//!
//! Held in a file rather than in flags because every one of the four roles needs
//! the same picture and they run as separate processes. A verifier that had been
//! told about two devices while the orchestrator was storming three would pass a
//! campaign over a device nobody was looking at.
//!
//! **Credentials are not in here.** They come from the environment
//! (`JD_SOAK_ACCOUNT` / `JD_SOAK_PASSWORD`), because this file is the one that
//! gets copied into a forensics bundle and read months later by whoever is
//! investigating. `JD_SOAK_ACCOUNT` is the sign-in identifier of an ordinary
//! account on the soak instance — an email address, because that is what the
//! platform signs in with. Nothing in this rig sends or receives mail.
//!
//! ## Why the devices are host processes rather than containers
//!
//! The daemon publishes its control channel on **loopback, on a
//! kernel-chosen port**, and that is the right design: binding it to every
//! interface would put a client's sync controls on the network. It also means a
//! daemon inside a container is unreachable from outside it, and the verifier
//! must be able to ask a device when it has stopped working — assertion 1 is
//! that question and assertion 5 is checked against the answer.
//!
//! The three ways out are: change the daemon (refused — spec S2 exists so that
//! the program soaked is the program that ships), proxy every status call
//! through `docker exec` (an extra moving part inside the one component whose
//! trustworthiness the whole rig rests on), or run the daemons as ordinary
//! processes on the host under **one unix account each**. The third costs
//! nothing and gives back the thing containers were wanted for: a per-device
//! network fault, through `iptables -m owner --uid-owner`, which cuts one
//! daemon's traffic and leaves its neighbours syncing.
//!
//! What is genuinely deferred by this is the *volume* fault — yanking a sync
//! root mid-storm — which wants a filesystem image rather than a container, and
//! which lands with the loopback devices in Phase B.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

/// One device: a real `joinery-drive` daemon with its own state and its own
/// sync root.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct Device {
    /// `device-a`. Also the container name, and how faults are addressed.
    pub name: String,
    /// `JOINERY_DRIVE_HOME` for this device — config, state store, spool, logs.
    /// One variable rather than three, so nothing can point the config at one
    /// account and the state at another.
    pub home: PathBuf,
    /// The synced folder, as the **host** sees it. The daemon sees the same
    /// bytes through a bind mount.
    pub root: PathBuf,
    /// The container the daemon runs in, when it runs in one.
    ///
    /// Normally `None`, and that is not a shortcut — see the module note. A
    /// containerized device's control channel is bound to loopback **inside**
    /// its own network namespace, so a verifier on the host cannot ask it
    /// anything, and the verifier not being able to ask is the one thing this
    /// rig cannot trade away.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub container: Option<String>,
    /// The unix account this device's daemon runs as.
    ///
    /// One account per device, which is what makes a *per-device* network fault
    /// possible without a network namespace: `iptables -m owner --uid-owner`
    /// cuts exactly this daemon's traffic and leaves the others alone.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub unix_user: Option<String>,
    /// The daemon account's **unix home**, where its OS trash lives.
    ///
    /// Not the same directory as `home`, which is `JOINERY_DRIVE_HOME` — the
    /// client's config and state. The engine never unlinks a user's file, so the
    /// freedesktop trash under this path is one of the legitimate places the
    /// last copy of something can be, and a verifier looking under the wrong
    /// home finds an empty trash every time and calls a correctly-trashed file
    /// lost.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub unix_home: Option<PathBuf>,
    /// The systemd unit that keeps the daemon running.
    ///
    /// The supervisor is what turns `kill -9` into reboot semantics: the process
    /// dies, something restarts it, and the engine has to recover from whatever
    /// it was doing. Without one, a kill is just a device that never comes back.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub service: Option<String>,
}

impl Device {
    /// Where the daemon publishes its control endpoint.
    pub fn control_file(&self) -> PathBuf {
        self.home.join("state/control.json")
    }

    pub fn state_db(&self) -> PathBuf {
        self.home.join("state/state.db")
    }

    pub fn spool(&self) -> PathBuf {
        self.home.join("state/spool")
    }

    pub fn config_file(&self) -> PathBuf {
        self.home.join("config/config.json")
    }

    /// Where this device's OS trash is, as best the fleet knows.
    ///
    /// Falls back to the drive home, which is right when the daemon runs as the
    /// same account as everything else — and wrong, silently, when it does not.
    /// That is why `unix_home` exists.
    pub fn trash_home(&self) -> PathBuf {
        self.unix_home.clone().unwrap_or_else(|| self.home.clone())
    }
}

/// The whole rig.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct Fleet {
    /// The soak instance, e.g. `https://drivetest.getjoinery.com`. Never
    /// dev.getjoinery.com — weeks of synthetic load do not belong on the box
    /// people work against (spec S1).
    pub server: String,
    pub devices: Vec<Device>,
    /// Where the three journals go. **Outside every sync root**, or the actors'
    /// own record becomes files to sync and the verifier ends up diffing its own
    /// evidence.
    pub journal_dir: PathBuf,
    /// Where a frozen world is captured when an invariant breaks.
    pub bundle_dir: PathBuf,
    /// Storm length, in seconds.
    #[serde(default = "default_storm_seconds")]
    pub storm_seconds: u64,
    /// How long a settle may take before a stall is called a failure. A stall is
    /// a first-class bug even with zero bytes lost — the product's core promise
    /// is "never silently stop".
    #[serde(default = "default_settle_deadline_seconds")]
    pub settle_deadline_seconds: u64,
    /// What each device's `poll_seconds` is set to when it is provisioned.
    ///
    /// The shipping default (30s) is what a real installation runs and is the
    /// right thing for a long campaign. A bounded gate that only has ten minutes
    /// turns it down, because most of that budget would otherwise be spent
    /// waiting out poll intervals rather than exercising anything.
    #[serde(default = "default_poll_seconds")]
    pub poll_seconds: u64,
}

fn default_storm_seconds() -> u64 {
    45 * 60
}

fn default_settle_deadline_seconds() -> u64 {
    15 * 60
}

fn default_poll_seconds() -> u64 {
    jd_daemon::config::DEFAULT_POLL_SECONDS
}

#[derive(Debug, thiserror::Error)]
pub enum FleetError {
    #[error("cannot read the fleet description at {path}: {source}")]
    Io {
        path: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("the fleet description at {path} is not readable as one: {detail}")]
    Malformed { path: PathBuf, detail: String },
    #[error("{0}")]
    Invalid(String),
    #[error("{0} is not set — the rig needs an ordinary account on the soak instance to link its devices to")]
    NoCredentials(&'static str),
}

impl Fleet {
    pub fn load(path: &Path) -> Result<Fleet, FleetError> {
        let raw = std::fs::read_to_string(path).map_err(|e| FleetError::Io {
            path: path.to_path_buf(),
            source: e,
        })?;
        let fleet: Fleet = serde_json::from_str(&raw).map_err(|e| FleetError::Malformed {
            path: path.to_path_buf(),
            detail: e.to_string(),
        })?;
        fleet.check()?;
        Ok(fleet)
    }

    pub fn save(&self, path: &Path) -> Result<(), FleetError> {
        if let Some(dir) = path.parent() {
            std::fs::create_dir_all(dir).map_err(|e| FleetError::Io {
                path: dir.to_path_buf(),
                source: e,
            })?;
        }
        let mut body = serde_json::to_string_pretty(self).expect("a fleet serializes");
        body.push('\n');
        std::fs::write(path, body).map_err(|e| FleetError::Io {
            path: path.to_path_buf(),
            source: e,
        })
    }

    pub fn device(&self, name: &str) -> Option<&Device> {
        self.devices.iter().find(|d| d.name == name)
    }

    /// The account the rig links its devices to: `(sign-in identifier, password)`.
    ///
    /// Read from the environment every time rather than held, so a password
    /// never sits in a struct that something might one day serialize into a
    /// bundle.
    pub fn credentials() -> Result<(String, String), FleetError> {
        let email = std::env::var("JD_SOAK_ACCOUNT")
            .map_err(|_| FleetError::NoCredentials("JD_SOAK_ACCOUNT"))?;
        let password = std::env::var("JD_SOAK_PASSWORD")
            .map_err(|_| FleetError::NoCredentials("JD_SOAK_PASSWORD"))?;
        Ok((email, password))
    }

    /// Refuse a rig that cannot produce trustworthy evidence.
    ///
    /// Every one of these is a way for a campaign to run for days and then be
    /// worthless, so they are refused at load rather than discovered in a
    /// bundle.
    fn check(&self) -> Result<(), FleetError> {
        if self.devices.is_empty() {
            return Err(FleetError::Invalid(
                "a fleet with no devices cannot sync anything".into(),
            ));
        }
        if !self.server.starts_with("http://") && !self.server.starts_with("https://") {
            return Err(FleetError::Invalid(format!(
                "the server address {} needs a scheme",
                self.server
            )));
        }
        for device in &self.devices {
            if self
                .devices
                .iter()
                .filter(|d| d.name == device.name)
                .count()
                > 1
            {
                return Err(FleetError::Invalid(format!(
                    "two devices are both called {}, so a fault could not say which it hit",
                    device.name
                )));
            }
            if device.home == device.root || device.home.starts_with(&device.root) {
                return Err(FleetError::Invalid(format!(
                    "{}'s state directory is inside its sync root, so the daemon would sync its own state store",
                    device.name
                )));
            }
            // The one that matters most: journals inside a sync root become
            // files to sync, and the verifier ends up diffing its own evidence.
            if self.journal_dir.starts_with(&device.root) {
                return Err(FleetError::Invalid(format!(
                    "the journal directory is inside {}'s sync root, so the evidence would be part of what is under test",
                    device.name
                )));
            }
            if self.bundle_dir.starts_with(&device.root) {
                return Err(FleetError::Invalid(format!(
                    "the bundle directory is inside {}'s sync root",
                    device.name
                )));
            }
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn device(name: &str, base: &str) -> Device {
        Device {
            name: name.into(),
            home: PathBuf::from(format!("{base}/{name}/home")),
            root: PathBuf::from(format!("{base}/{name}/root")),
            container: None,
            unix_home: Some(PathBuf::from(format!(
                "/var/lib/soak-{}",
                name.trim_start_matches("device-")
            ))),
            unix_user: Some(format!("soak-{}", name.trim_start_matches("device-"))),
            service: Some(format!(
                "soak-device@{}.service",
                name.trim_start_matches("device-")
            )),
        }
    }

    fn fleet() -> Fleet {
        Fleet {
            server: "https://drivetest.getjoinery.com".into(),
            devices: vec![device("device-a", "/soak"), device("device-b", "/soak")],
            journal_dir: PathBuf::from("/soak/journal"),
            bundle_dir: PathBuf::from("/soak/bundles"),
            storm_seconds: 2700,
            settle_deadline_seconds: 900,
            poll_seconds: 30,
        }
    }

    #[test]
    fn a_fleet_survives_a_round_trip() {
        let dir = std::env::temp_dir().join(format!("jd-soak-fleet-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("fleet.json");
        fleet().save(&path).unwrap();
        assert_eq!(Fleet::load(&path).unwrap(), fleet());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_fleet_with_no_devices_is_refused() {
        let mut f = fleet();
        f.devices.clear();
        assert!(f.check().is_err());
    }

    #[test]
    fn two_devices_with_one_name_are_refused() {
        // A fault journaled against "device-a" could not say which it hit, and
        // the timeline is the whole substitute for replay.
        let mut f = fleet();
        f.devices[1].name = "device-a".into();
        assert!(f.check().is_err());
    }

    #[test]
    fn a_journal_directory_inside_a_sync_root_is_refused() {
        // The failure this prevents is quiet and total: the actors' own record
        // becomes files to sync, and the verifier diffs its own evidence.
        let mut f = fleet();
        f.journal_dir = PathBuf::from("/soak/device-a/root/journal");
        let err = f.check().unwrap_err().to_string();
        assert!(err.contains("evidence"), "{err}");
    }

    #[test]
    fn a_state_directory_inside_a_sync_root_is_refused() {
        // The daemon would sync its own SQLite store, which is both a privacy
        // problem and an infinite loop.
        let mut f = fleet();
        f.devices[0].home = PathBuf::from("/soak/device-a/root/home");
        assert!(f.check().is_err());
    }

    #[test]
    fn a_server_without_a_scheme_is_refused() {
        let mut f = fleet();
        f.server = "drivetest.getjoinery.com".into();
        assert!(f.check().is_err());
    }

    #[test]
    fn missing_credentials_name_the_variable_to_set() {
        // A rig that failed with "unauthorized" three minutes into linking would
        // send somebody looking at the server.
        std::env::remove_var("JD_SOAK_ACCOUNT");
        let err = Fleet::credentials().unwrap_err().to_string();
        assert!(err.contains("JD_SOAK_ACCOUNT"), "{err}");
    }

    #[test]
    fn the_paths_a_device_owns_all_hang_off_its_one_home_variable() {
        let d = device("device-a", "/soak");
        assert!(d.control_file().starts_with(&d.home));
        assert!(d.state_db().starts_with(&d.home));
        assert!(d.spool().starts_with(&d.home));
        assert!(d.config_file().starts_with(&d.home));
    }
}
