//! One daemon per home.
//!
//! Two daemons on one `JOINERY_DRIVE_HOME` drive one sync folder from two
//! stores, and each reads the other's work as a change to answer: a file
//! comes back as a conflicted copy of itself, on the computer and on the
//! server. Nothing about a second start is unusual — a daemon started by hand
//! beside the one autostart runs, a restart loop of the user's own, a copy that
//! relaunched itself after its program file was replaced — so the daemon takes
//! an exclusive lock on `daemon.lock` in its state folder before it does
//! anything else, and a copy that cannot take it stands down.
//!
//! The lock is the operating system's (`flock` on macOS and Linux,
//! `LockFileEx` on Windows): it goes with the process, a crash included, so
//! there is never a stale lock to clear. It lives in the state folder, not the
//! sync folder, which can be on a drive (FAT, a network share) where locks do
//! not hold, and where a lock file would be one more file to sync.
//!
//! The holder's process id is written beside it, in `daemon.pid`: a Windows
//! lock keeps every other handle from reading the locked file, so the id could
//! not be read back out of `daemon.lock` itself.

use std::fs::{File, TryLockError};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

/// The lock file, in the state folder.
pub const LOCK_FILE: &str = "daemon.lock";

/// The holder's process id, beside the lock.
pub const PID_FILE: &str = "daemon.pid";

/// How long a starting daemon waits for the lock before it stands down: long
/// enough for the copy before it to finish exiting (`stop` and then a start, a
/// supervisor's restart after an update), short enough that a copy started
/// beside a running one says so promptly.
pub const WAIT: Duration = Duration::from_secs(10);

/// Holds the home for as long as it lives. Dropping it lets go.
#[derive(Debug)]
pub struct Guard {
    // Closing the file releases the lock.
    _file: File,
    pid_file: PathBuf,
}

impl Drop for Guard {
    fn drop(&mut self) {
        // Removed while the lock is still held (the file closes after this
        // runs), so it never removes the id a next holder has written.
        let _ = std::fs::remove_file(&self.pid_file);
    }
}

/// What taking the home came to.
#[derive(Debug)]
pub enum Taken {
    /// This process holds the home.
    Held(Guard),
    /// Locks do not work where the state folder is. The daemon runs
    /// unguarded, as it always did, rather than refuse to run at all.
    Unguarded(String),
    /// Another daemon holds this home; `pid` when it could be read.
    AlreadyRunning { pid: Option<u32> },
}

/// Take the home whose state folder is `state`, waiting up to `wait` for a
/// copy that holds it to exit.
pub fn take(state: &Path, wait: Duration) -> Taken {
    let path = state.join(LOCK_FILE);
    let file = match File::options()
        .read(true)
        .write(true)
        .create(true)
        .truncate(false)
        .open(&path)
    {
        Ok(f) => f,
        Err(e) => return Taken::Unguarded(format!("cannot open {}: {e}", path.display())),
    };
    let deadline = Instant::now() + wait;
    loop {
        match classify(file.try_lock()) {
            Attempt::Locked => {
                let pid_file = state.join(PID_FILE);
                let _ = std::fs::write(&pid_file, std::process::id().to_string());
                return Taken::Held(Guard {
                    _file: file,
                    pid_file,
                });
            }
            Attempt::Unsupported(why) => return Taken::Unguarded(why),
            Attempt::Busy => {
                if Instant::now() >= deadline {
                    return Taken::AlreadyRunning {
                        pid: holder_pid(state),
                    };
                }
                std::thread::sleep(Duration::from_millis(200));
            }
        }
    }
}

/// Is the home whose state folder is `state` held by a live daemon? Asked by
/// the copy that watches an update come up: a new copy that found the home
/// held exits at once, cleanly, and that is not the update failing. Takes the
/// lock for a moment if it is free; a daemon starting in that moment waits it
/// out (`WAIT`).
pub fn held_elsewhere(state: &Path) -> bool {
    let Ok(file) = File::options()
        .read(true)
        .write(true)
        .open(state.join(LOCK_FILE))
    else {
        return false;
    };
    match file.try_lock() {
        Err(TryLockError::WouldBlock) => true,
        Ok(()) => {
            let _ = file.unlock();
            false
        }
        Err(TryLockError::Error(_)) => false,
    }
}

/// The holder's process id, if it wrote one.
pub fn holder_pid(state: &Path) -> Option<u32> {
    std::fs::read_to_string(state.join(PID_FILE))
        .ok()
        .and_then(|s| s.trim().parse().ok())
}

enum Attempt {
    Locked,
    Busy,
    Unsupported(String),
}

/// Only "someone holds it" means another daemon. Any other refusal (no lock
/// support on this volume, ENOLCK on a network mount) leaves the daemon
/// unguarded: refusing to run on a volume without locks would stop sync where
/// it worked before.
fn classify(attempt: Result<(), TryLockError>) -> Attempt {
    match attempt {
        Ok(()) => Attempt::Locked,
        Err(TryLockError::WouldBlock) => Attempt::Busy,
        Err(TryLockError::Error(e)) => Attempt::Unsupported(format!("cannot lock the state folder: {e}")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn temp(tag: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!(
            "jd-instance-{tag}-{}-{:?}",
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        dir
    }

    /// Let go is not always the same instant as released: a process another
    /// thread is starting holds a copy of every open file until its program
    /// runs, the lock's included. A daemon waits that out (`WAIT`); so does a
    /// test, for a moment.
    fn released(state: &Path) -> bool {
        let deadline = Instant::now() + Duration::from_secs(2);
        while held_elsewhere(state) {
            if Instant::now() >= deadline {
                return false;
            }
            std::thread::sleep(Duration::from_millis(20));
        }
        true
    }

    /// A second take of a held home stands down and names the holder; once
    /// the holder lets go, the home can be taken again and the id is the new
    /// holder's.
    #[test]
    fn a_held_home_cannot_be_taken_twice_and_is_free_once_let_go() {
        let state = temp("twice");
        let Taken::Held(first) = take(&state, Duration::ZERO) else {
            panic!("a free home was not taken");
        };
        assert!(held_elsewhere(&state));
        match take(&state, Duration::ZERO) {
            Taken::AlreadyRunning { pid } => assert_eq!(pid, Some(std::process::id())),
            other => panic!("a held home was taken again: {other:?}"),
        }
        drop(first);
        assert!(holder_pid(&state).is_none(), "the id outlived its guard");
        assert!(released(&state), "the lock outlived its guard");
        assert!(matches!(take(&state, Duration::from_secs(2)), Taken::Held(_)));
        let _ = std::fs::remove_dir_all(&state);
    }

    /// A starting copy waits for one that is on its way out.
    #[test]
    fn a_start_waits_for_a_holder_that_is_exiting() {
        let state = temp("wait");
        let Taken::Held(first) = take(&state, Duration::ZERO) else {
            panic!("a free home was not taken");
        };
        let letting_go = std::thread::spawn(move || {
            std::thread::sleep(Duration::from_millis(400));
            drop(first);
        });
        assert!(matches!(take(&state, Duration::from_secs(5)), Taken::Held(_)));
        letting_go.join().unwrap();
        let _ = std::fs::remove_dir_all(&state);
    }

    /// Only a held lock is "already running"; a volume that cannot lock at all
    /// runs unguarded rather than not at all.
    #[test]
    fn a_lock_that_cannot_be_taken_at_all_runs_unguarded() {
        assert!(matches!(classify(Ok(())), Attempt::Locked));
        assert!(matches!(classify(Err(TryLockError::WouldBlock)), Attempt::Busy));
        let unsupported = std::io::Error::new(std::io::ErrorKind::Unsupported, "no locks here");
        assert!(matches!(
            classify(Err(TryLockError::Error(unsupported))),
            Attempt::Unsupported(_)
        ));
        // And a state folder that cannot even hold the file.
        let missing = temp("missing").join("not-made");
        assert!(matches!(take(&missing, Duration::ZERO), Taken::Unguarded(_)));
    }

    /// A process the holder started does not keep the home once the holder
    /// lets go: the lock's file is not handed down to children.
    #[test]
    fn a_child_of_the_holder_does_not_keep_the_home() {
        let state = temp("child");
        let Taken::Held(first) = take(&state, Duration::ZERO) else {
            panic!("a free home was not taken");
        };
        #[cfg(unix)]
        let mut child = std::process::Command::new("sleep").arg("5").spawn().unwrap();
        #[cfg(windows)]
        let mut child = std::process::Command::new("cmd")
            .args(["/C", "ping -n 6 127.0.0.1 >NUL"])
            .spawn()
            .unwrap();
        drop(first);
        // The child lives five seconds; the home is free well inside them.
        assert!(
            matches!(take(&state, Duration::from_secs(2)), Taken::Held(_)),
            "a child of the holder kept the home"
        );
        assert!(child.try_wait().unwrap().is_none(), "the child had already gone");
        let _ = child.kill();
        let _ = child.wait();
        let _ = std::fs::remove_dir_all(&state);
    }
}
