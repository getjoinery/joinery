//! The start marker: how an update that does not start is undone.
//!
//! Before the first file moves, the old daemon writes `pending.json` in the
//! updates folder: which version it is replacing with which, every swap it is
//! about to make, and whether syncing was paused. The new daemon reads it
//! **before anything else it does**, and counts itself as one attempt. It
//! clears the marker only once it has really started — its sync loop has run
//! the startup recovery and published its first status — and from then on the
//! old copy is deleted.
//!
//! A new copy that crashes, hangs on the way up, or exits before that point
//! never clears it. What happens next depends on who restarts the daemon:
//!
//! - **The LaunchAgent or the systemd unit** starts it again, and every start
//!   is another attempt. Past [`MAX_START_ATTEMPTS`], the new copy puts the old
//!   one back itself and exits to be restarted — as the old one.
//! - **Nobody** (Windows, or a daemon started by hand): the old process starts
//!   the new one and stays to watch ([`watch`]). If the new one exits or is
//!   still not up after [`WATCH_TIMEOUT`], the old process puts itself back and
//!   starts again.
//!
//! The old copy that comes back finds the marker saying so, reports that the
//! update did not start, and does not offer that version again until a newer
//! one is published or somebody asks for an update by hand.
//!
//! The one failure this cannot catch is a new copy that will not run at all —
//! the wrong architecture, a broken signature, a missing system library —
//! because such a program never reaches the line that counts the attempt. That
//! case is caught earlier instead: the staged copy is run once, and has to
//! answer with its version, before anything is swapped (`super::preflight`).

use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

use jd_platform::install::{self, Swap};
use serde::{Deserialize, Serialize};

/// Starts a new copy gets before it is judged not to work.
///
/// More than one, because a start can fail for reasons that have nothing to
/// do with the new copy — the machine shutting down mid-start, say — and a
/// single unlucky start should not throw away a good update.
pub const MAX_START_ATTEMPTS: u32 = 3;

/// How long the old process waits, when nothing else will restart the daemon,
/// for the new one to come up.
pub const WATCH_TIMEOUT: Duration = Duration::from_secs(120);

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Phase {
    /// Files are moving. A start that finds this phase is a swap that died
    /// halfway, and everything is put back.
    Swapping,
    /// Everything is in place and the new copy is on trial.
    Swapped,
    /// The old copy was put back; the next old daemon reports it.
    RolledBack,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Pending {
    pub from: String,
    pub to: String,
    pub phase: Phase,
    pub attempts: u32,
    /// Syncing was paused when the update was installed, and stays paused.
    pub paused: bool,
    pub swaps: Vec<Swap>,
    /// The staging folder, which ends up holding the copy that lost: the old
    /// one after a good update, the new one after a rollback.
    pub staging: PathBuf,
    /// The daemon's live path, which is what gets started again.
    pub daemon: PathBuf,
    #[serde(default)]
    pub reason: Option<String>,
}

impl Pending {
    pub fn load(path: &Path) -> Option<Pending> {
        serde_json::from_str(&std::fs::read_to_string(path).ok()?).ok()
    }

    /// Write it through a temporary file and a rename, and flush it, because
    /// this file is the only record of how to undo what is about to happen.
    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        if let Some(dir) = path.parent() {
            std::fs::create_dir_all(dir)?;
        }
        let tmp = path.with_extension("json.tmp");
        {
            use std::io::Write;
            let mut f = std::fs::File::create(&tmp)?;
            f.write_all(
                serde_json::to_string_pretty(self)
                    .expect("pending serializes")
                    .as_bytes(),
            )?;
            f.sync_all()?;
        }
        std::fs::rename(&tmp, path)
    }

    /// Make every swap, recording progress as it goes. On any failure,
    /// everything already moved is moved back before the error is returned.
    pub fn install(&mut self, path: &Path) -> Result<(), String> {
        self.phase = Phase::Swapping;
        self.save(path)
            .map_err(|e| format!("cannot record the update: {e}"))?;
        for i in 0..self.swaps.len() {
            let result = install::replace(&mut self.swaps[i]);
            // Saved after every swap, even a failed one: on a Mac the swap
            // records the new bundle's identity, which is what tells a later
            // restore whether the exchange happened.
            let _ = self.save(path);
            if let Err(e) = result {
                let why = format!("cannot put {} in place: {e}", self.swaps[i].live.display());
                self.roll_back(path, &why);
                return Err(why);
            }
        }
        self.phase = Phase::Swapped;
        self.attempts = 0;
        self.save(path)
            .map_err(|e| format!("cannot record the update: {e}"))
    }

    /// Put every old copy back, last swap first.
    pub fn roll_back(&mut self, path: &Path, reason: &str) {
        let mut trouble = Vec::new();
        for swap in self.swaps.iter().rev() {
            if let Err(e) = install::restore(swap) {
                trouble.push(format!("{}: {e}", swap.live.display()));
            }
        }
        self.phase = Phase::RolledBack;
        self.reason = Some(if trouble.is_empty() {
            reason.to_string()
        } else {
            format!("{reason}; and could not put back {}", trouble.join(", "))
        });
        let _ = self.save(path);
    }
}

/// What a starting daemon should do, having read the marker.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Start {
    /// Nothing pending for this copy.
    Normal,
    /// This copy is the new one, on trial: clear the marker once up. Syncing
    /// starts paused when it was paused before.
    Trial { paused: bool },
    /// The old copy has been put back under the live path; start that one.
    Restart { daemon: PathBuf },
}

/// Read the marker, first thing in `daemon`.
pub fn on_start(path: &Path, running: &str) -> Start {
    let Some(mut pending) = Pending::load(path) else {
        return Start::Normal;
    };
    match pending.phase {
        Phase::Swapping => {
            // The process installing it died partway. Whatever moved goes
            // back; then if this is the new copy, it is not the one to run.
            pending.roll_back(
                path,
                "the update was interrupted while it was being installed",
            );
            if running == pending.to {
                Start::Restart {
                    daemon: pending.daemon.clone(),
                }
            } else {
                Start::Normal
            }
        }
        Phase::Swapped if running == pending.to => {
            pending.attempts += 1;
            if pending.attempts > MAX_START_ATTEMPTS {
                let reason = format!(
                    "version {} did not start after {} attempts",
                    pending.to, MAX_START_ATTEMPTS
                );
                pending.roll_back(path, &reason);
                return Start::Restart {
                    daemon: pending.daemon.clone(),
                };
            }
            let _ = pending.save(path);
            Start::Trial {
                paused: pending.paused,
            }
        }
        // A copy that is neither — started from somewhere else while the
        // marker stands — leaves it for the copy it belongs to.
        _ => Start::Normal,
    }
}

/// The new copy is up. Delete the marker and the old copy; returns the version
/// it replaced.
pub fn confirm(path: &Path, running: &str) -> Option<String> {
    let pending = Pending::load(path)?;
    if pending.phase != Phase::Swapped || pending.to != running {
        return None;
    }
    let _ = std::fs::remove_dir_all(&pending.staging);
    let _ = std::fs::remove_file(path);
    Some(pending.from)
}

/// An update was put back, and this is the old copy reading about it: the
/// version that did not start, and why. The marker is removed.
pub fn take_rollback(path: &Path, running: &str) -> Option<(String, String)> {
    let pending = Pending::load(path)?;
    if pending.phase != Phase::RolledBack || pending.from != running {
        return None;
    }
    let _ = std::fs::remove_dir_all(&pending.staging);
    let _ = std::fs::remove_file(path);
    Some((
        pending.to,
        pending.reason.unwrap_or_else(|| "it did not start".into()),
    ))
}

/// How a watched start ended.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Watched {
    /// The new copy cleared the marker.
    Started,
    /// The new copy put the old one back itself, and has started it.
    HandledByNewCopy,
    /// The new copy exited or never came up. The caller puts the old copy
    /// back.
    Failed(String),
}

/// Wait for a new copy this process started to come up.
pub fn watch(path: &Path, child: &mut std::process::Child, timeout: Duration) -> Watched {
    let deadline = Instant::now() + timeout;
    loop {
        match Pending::load(path) {
            None => return Watched::Started,
            Some(p) if p.phase == Phase::RolledBack => return Watched::HandledByNewCopy,
            Some(_) => {}
        }
        if let Ok(Some(status)) = child.try_wait() {
            // One last look: it may have cleared the marker and then exited
            // for an unrelated reason, which is a start that worked.
            if Pending::load(path).is_none() {
                return Watched::Started;
            }
            return Watched::Failed(format!(
                "the new version exited ({status}) before it started"
            ));
        }
        if Instant::now() >= deadline {
            let _ = child.kill();
            let _ = child.wait();
            return Watched::Failed(format!(
                "the new version was not up after {} seconds",
                timeout.as_secs()
            ));
        }
        std::thread::sleep(Duration::from_millis(250));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn temp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-pending-{tag}-{}-{:?}",
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    /// An install folder with one program in it and a new copy staged.
    fn layout(dir: &Path) -> (Pending, PathBuf) {
        let live = dir.join("joinery-drive");
        let staging = dir.join(".jd-update-0.3.0");
        std::fs::create_dir_all(staging.join("previous")).unwrap();
        std::fs::write(&live, b"old").unwrap();
        std::fs::write(staging.join("joinery-drive"), b"new").unwrap();
        let pending = Pending {
            from: "0.2.0".into(),
            to: "0.3.0".into(),
            phase: Phase::Swapping,
            attempts: 0,
            paused: true,
            swaps: vec![Swap {
                live: live.clone(),
                staged: staging.join("joinery-drive"),
                previous: staging.join("previous/joinery-drive"),
                new_id: None,
            }],
            staging,
            daemon: live.clone(),
            reason: None,
        };
        (pending, live)
    }

    #[test]
    fn a_new_copy_that_keeps_failing_to_start_puts_the_old_one_back() {
        let dir = temp("rollback");
        let marker = dir.join("pending.json");
        let (mut pending, live) = layout(&dir);
        pending.install(&marker).unwrap();
        assert_eq!(std::fs::read(&live).unwrap(), b"new");

        // Each start counts. The pause is carried across.
        for _ in 0..MAX_START_ATTEMPTS {
            assert_eq!(on_start(&marker, "0.3.0"), Start::Trial { paused: true });
        }
        // One more and it gives up, puts the old copy back, and says to start
        // that.
        assert_eq!(
            on_start(&marker, "0.3.0"),
            Start::Restart {
                daemon: live.clone()
            }
        );
        assert_eq!(std::fs::read(&live).unwrap(), b"old");

        // The old copy reads why, once.
        let (version, reason) = take_rollback(&marker, "0.2.0").unwrap();
        assert_eq!(version, "0.3.0");
        assert!(reason.contains("did not start"));
        assert!(take_rollback(&marker, "0.2.0").is_none());
        assert!(!marker.exists());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_new_copy_that_starts_clears_the_marker_and_the_old_copy() {
        let dir = temp("confirm");
        let marker = dir.join("pending.json");
        let (mut pending, live) = layout(&dir);
        let staging = pending.staging.clone();
        pending.install(&marker).unwrap();

        assert!(matches!(on_start(&marker, "0.3.0"), Start::Trial { .. }));
        assert_eq!(confirm(&marker, "0.3.0").as_deref(), Some("0.2.0"));
        assert!(!marker.exists());
        assert!(
            !staging.exists(),
            "the old copy is deleted once the new one is up"
        );
        assert_eq!(std::fs::read(&live).unwrap(), b"new");
        // And a start after that is ordinary.
        assert_eq!(on_start(&marker, "0.3.0"), Start::Normal);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_swap_that_died_halfway_is_put_back_on_the_next_start() {
        let dir = temp("halfway");
        let marker = dir.join("pending.json");
        let (mut pending, live) = layout(&dir);
        // The swap happened but the process died before recording it as done.
        jd_platform::install::replace(&mut pending.swaps[0]).unwrap();
        pending.phase = Phase::Swapping;
        pending.save(&marker).unwrap();

        // The new copy is what runs at the live path now, and it must not be
        // the one left running.
        assert_eq!(
            on_start(&marker, "0.3.0"),
            Start::Restart {
                daemon: live.clone()
            }
        );
        assert_eq!(std::fs::read(&live).unwrap(), b"old");
        assert!(take_rollback(&marker, "0.2.0")
            .unwrap()
            .1
            .contains("interrupted"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_marker_for_some_other_copy_is_left_alone() {
        let dir = temp("other");
        let marker = dir.join("pending.json");
        let (mut pending, _live) = layout(&dir);
        pending.phase = Phase::Swapped;
        pending.save(&marker).unwrap();
        // Neither the version being replaced nor the one replacing it.
        assert_eq!(on_start(&marker, "0.9.9"), Start::Normal);
        assert_eq!(Pending::load(&marker).unwrap().attempts, 0);
        assert!(confirm(&marker, "0.9.9").is_none());
        assert!(marker.exists());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn no_marker_is_an_ordinary_start() {
        let dir = temp("none");
        assert_eq!(on_start(&dir.join("pending.json"), "0.3.0"), Start::Normal);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[cfg(unix)]
    #[test]
    fn a_watched_start_that_exits_early_is_a_failure_and_one_that_clears_is_not() {
        let dir = temp("watch");
        let marker = dir.join("pending.json");
        let (mut pending, _live) = layout(&dir);
        pending.phase = Phase::Swapped;
        pending.save(&marker).unwrap();

        let mut quitter = std::process::Command::new("sh")
            .args(["-c", "exit 3"])
            .spawn()
            .unwrap();
        assert!(matches!(
            watch(&marker, &mut quitter, Duration::from_secs(10)),
            Watched::Failed(_)
        ));

        // A child that clears the marker, the way a new daemon does once up.
        let mut starter = std::process::Command::new("sh")
            .args([
                "-c",
                &format!("sleep 0.3; rm '{}'; sleep 5", marker.display()),
            ])
            .spawn()
            .unwrap();
        assert_eq!(
            watch(&marker, &mut starter, Duration::from_secs(10)),
            Watched::Started
        );
        let _ = starter.kill();
        let _ = starter.wait();

        // And one that hangs is given up on.
        pending.save(&marker).unwrap();
        let mut hung = std::process::Command::new("sh")
            .args(["-c", "sleep 30"])
            .spawn()
            .unwrap();
        assert!(matches!(
            watch(&marker, &mut hung, Duration::from_millis(600)),
            Watched::Failed(_)
        ));
        let _ = std::fs::remove_dir_all(&dir);
    }
}
