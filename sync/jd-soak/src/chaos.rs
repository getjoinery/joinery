//! Breaking things on purpose, on a schedule, and writing down every break.
//!
//! Faults are injected **from outside the daemon** — signals, network rules,
//! container lifecycle. Nothing here needs a cooperating build, and that is the
//! point (spec S2): a daemon compiled with soak hooks in it is a different
//! program from the one that ships, and proving that one survives says nothing
//! about this one.
//!
//! Every fault is journaled with a timestamp before and after, because the whole
//! substitute for seed replay is being able to say which fault was in flight
//! when a file was last seen. An unjournaled fault turns a violation into a
//! mystery.
//!
//! Phase A carries the two that hunt the biggest game:
//!
//! - **kill** — `SIGKILL` to the daemon, mean interval ~20 minutes. Its
//!   supervisor restarts it, which is reboot semantics without a reboot. What
//!   it hunts: any state the engine holds only in memory, and any operation
//!   whose journal-then-act ordering is the wrong way round.
//! - **partition** — the device's traffic to the server dropped for a few
//!   minutes. What it hunts: retries, backoff, resumed transfers, and a client
//!   that quietly gives up rather than saying it cannot reach anything.
//!
//! Also here because they are free: **freeze** (`SIGSTOP`/`SIGCONT`, a hang that
//! resumes) and **restart** (stop and start the container, so the process tree
//! and its page cache go with it).

use std::path::Path;
use std::process::Command;
use std::time::Duration;

use crate::actor::now_ms;
use crate::fleet::Device;
use crate::journal::{Journal, Record};
use crate::rng::Rng;

/// A fault this rig knows how to cause.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Fault {
    Kill,
    Freeze { seconds: u64 },
    Partition { seconds: u64 },
    Restart,
}

impl Fault {
    pub fn kind(&self) -> &'static str {
        match self {
            Fault::Kill => "kill",
            Fault::Freeze { .. } => "freeze",
            Fault::Partition { .. } => "partition",
            Fault::Restart => "restart",
        }
    }

    pub fn detail(&self) -> String {
        match self {
            Fault::Kill => "SIGKILL to the daemon; its supervisor restarts it".into(),
            Fault::Freeze { seconds } => {
                format!("SIGSTOP for {seconds}s, then SIGCONT — a hang that resumes")
            }
            Fault::Partition { seconds } => {
                format!("all traffic to the server dropped for {seconds}s")
            }
            Fault::Restart => "the container stopped and started".into(),
        }
    }
}

/// The Phase A mix. Kill dominates because it is the one that finds data loss;
/// the rest are cheap to run alongside it.
pub fn phase_a_mix(rng: &mut Rng) -> Fault {
    match rng.below(10) {
        0..=4 => Fault::Kill,
        5..=7 => Fault::Partition {
            seconds: rng.range(30, 240),
        },
        8 => Fault::Freeze {
            seconds: rng.range(20, 120),
        },
        _ => Fault::Restart,
    }
}

/// How the rig reaches a device to break it.
///
/// A trait rather than a straight call to `docker`, so the scheduling and the
/// journaling can be tested without a container runtime — and so the same agent
/// works against a daemon running as a plain process on the host.
pub trait Reach: Send + Sync {
    /// Signal the daemon inside this device. `signal` is a name like `KILL`.
    fn signal(&self, device: &Device, signal: &str) -> Result<(), String>;
    /// Drop or restore this device's traffic to the server.
    fn set_partition(&self, device: &Device, server_host: &str, on: bool) -> Result<(), String>;
    fn restart(&self, device: &Device) -> Result<(), String>;
}

/// The real one: `docker exec` for a containerized device, plain signals for a
/// device running on this host.
pub struct RealReach;

impl RealReach {
    fn run(argv: &[&str]) -> Result<(), String> {
        let output = Command::new(argv[0])
            .args(&argv[1..])
            .output()
            .map_err(|e| format!("cannot run {}: {e}", argv.join(" ")))?;
        if output.status.success() {
            return Ok(());
        }
        Err(format!(
            "{} failed ({}): {}",
            argv.join(" "),
            output.status,
            String::from_utf8_lossy(&output.stderr).trim()
        ))
    }
}

impl Reach for RealReach {
    fn signal(&self, device: &Device, signal: &str) -> Result<(), String> {
        match &device.container {
            Some(container) => RealReach::run(&[
                "docker",
                "exec",
                container,
                "pkill",
                &format!("-{signal}"),
                "-f",
                "joinery-drive daemon",
            ]),
            None => {
                // On the host, two devices' daemons are told apart by the home
                // directory they were started with — the process names are
                // identical, and killing by name would take out the whole fleet
                // on every fault.
                let pid = host_pid(device)
                    .ok_or_else(|| format!("no daemon found for {}", device.name))?;
                RealReach::run(&["kill", &format!("-{signal}"), &pid.to_string()])
            }
        }
    }

    /// Cut one device's traffic to the server, and only that device's.
    ///
    /// On the host this is an owner-uid match, which is the whole reason each
    /// device runs as its own unix account: a plain destination rule would cut
    /// every daemon at once, and the journal would then say one device was
    /// partitioned while all of them were — a timeline that points at the wrong
    /// device is worse than no timeline.
    fn set_partition(&self, device: &Device, server_host: &str, on: bool) -> Result<(), String> {
        let rule = if on { "-I" } else { "-D" };
        if let Some(container) = &device.container {
            return RealReach::run(&[
                "docker",
                "exec",
                container,
                "iptables",
                rule,
                "OUTPUT",
                "-d",
                server_host,
                "-j",
                "DROP",
            ]);
        }
        let user = device.unix_user.as_ref().ok_or_else(|| {
            format!(
                "{} has neither a container nor a unix account, so its traffic cannot be cut without cutting every device's",
                device.name
            )
        })?;
        RealReach::run(&[
            "iptables",
            rule,
            "OUTPUT",
            "-m",
            "owner",
            "--uid-owner",
            user,
            "-d",
            server_host,
            "-j",
            "DROP",
        ])
    }

    /// Take the whole device down and bring it back — reboot semantics.
    fn restart(&self, device: &Device) -> Result<(), String> {
        if let Some(container) = &device.container {
            return RealReach::run(&["docker", "restart", container]);
        }
        let service = device.service.as_ref().ok_or_else(|| {
            format!(
                "{} has no supervisor, so it could be stopped but nothing would start it again",
                device.name
            )
        })?;
        RealReach::run(&["systemctl", "restart", service])
    }
}

/// The daemon on this host that was started with a given device's home.
fn host_pid(device: &Device) -> Option<u32> {
    let home = device.home.to_string_lossy().to_string();
    for entry in std::fs::read_dir("/proc").ok()?.flatten() {
        let Ok(pid) = entry.file_name().to_string_lossy().parse::<u32>() else {
            continue;
        };
        let Ok(cmdline) = std::fs::read(format!("/proc/{pid}/cmdline")) else {
            continue;
        };
        if !String::from_utf8_lossy(&cmdline).contains("joinery-drive") {
            continue;
        }
        if let Ok(environ) = std::fs::read(format!("/proc/{pid}/environ")) {
            if String::from_utf8_lossy(&environ).contains(&format!("JOINERY_DRIVE_HOME={home}")) {
                return Some(pid);
            }
        }
    }
    None
}

/// The per-device fault agent.
pub struct Chaos<'a> {
    pub reach: &'a dyn Reach,
    pub server_host: String,
    journal: Journal,
}

impl<'a> Chaos<'a> {
    pub fn new(
        reach: &'a dyn Reach,
        server_host: &str,
        journal_dir: &Path,
    ) -> Result<Chaos<'a>, crate::journal::JournalError> {
        Ok(Chaos {
            reach,
            server_host: server_host.to_string(),
            journal: Journal::open(journal_dir, "chaos")?,
        })
    }

    /// Cause one fault and write down what happened.
    ///
    /// A fault that could not be injected is journaled too, as a fault of kind
    /// `refused`. Silently skipping it would leave a campaign reporting a
    /// hundred kills it never performed — and a green run over an adversary that
    /// was not there is the worst possible outcome for this rig.
    pub fn inject(
        &mut self,
        device: &Device,
        fault: Fault,
        sleep: &dyn Fn(Duration),
    ) -> Result<(), crate::journal::JournalError> {
        let outcome = match fault {
            Fault::Kill => self.reach.signal(device, "KILL"),
            Fault::Freeze { seconds } => match self.reach.signal(device, "STOP") {
                Ok(()) => {
                    sleep(Duration::from_secs(seconds));
                    self.reach.signal(device, "CONT")
                }
                Err(e) => Err(e),
            },
            Fault::Partition { seconds } => {
                match self.reach.set_partition(device, &self.server_host, true) {
                    Ok(()) => {
                        sleep(Duration::from_secs(seconds));
                        // Removed whatever happened while it was down. A rule
                        // left in place turns one timed partition into a device
                        // that is offline for the rest of the campaign, and
                        // every settle after it fails for the wrong reason.
                        self.reach.set_partition(device, &self.server_host, false)
                    }
                    Err(e) => Err(e),
                }
            }
            Fault::Restart => self.reach.restart(device),
        };

        let seq = self.journal.next_seq();
        match outcome {
            Ok(()) => self.journal.write(&Record::Fault {
                seq,
                kind: fault.kind().into(),
                target: device.name.clone(),
                detail: fault.detail(),
                ts_ms: now_ms(),
            }),
            Err(e) => self.journal.write(&Record::Fault {
                seq,
                kind: "refused".into(),
                target: device.name.clone(),
                detail: format!("{} could not be injected: {e}", fault.kind()),
                ts_ms: now_ms(),
            }),
        }
    }

    /// Restore anything a partition left behind.
    ///
    /// Run before every settle, unconditionally. A settle that began with a
    /// device still cut off would fail convergence on the rig's own leftovers
    /// and send somebody hunting a bug in the client.
    pub fn clear(&mut self, devices: &[Device]) {
        for device in devices {
            if device.container.is_none() && device.unix_user.is_none() {
                continue;
            }
            // Repeated because iptables holds duplicates, and one storm can
            // leave more than one rule behind if a partition overlapped a
            // restart. Deleting a rule that is not there fails, which is how the
            // loop knows to stop.
            for _ in 0..4 {
                if self
                    .reach
                    .set_partition(device, &self.server_host, false)
                    .is_err()
                {
                    break;
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    #[derive(Default)]
    struct Recording {
        calls: Mutex<Vec<String>>,
        refuse: bool,
    }

    impl Reach for Recording {
        fn signal(&self, device: &Device, signal: &str) -> Result<(), String> {
            self.calls
                .lock()
                .unwrap()
                .push(format!("signal {} {signal}", device.name));
            if self.refuse {
                return Err("no such process".into());
            }
            Ok(())
        }
        fn set_partition(&self, device: &Device, host: &str, on: bool) -> Result<(), String> {
            self.calls
                .lock()
                .unwrap()
                .push(format!("partition {} {host} {on}", device.name));
            if self.refuse {
                return Err("iptables is not available".into());
            }
            Ok(())
        }
        fn restart(&self, device: &Device) -> Result<(), String> {
            self.calls
                .lock()
                .unwrap()
                .push(format!("restart {}", device.name));
            if self.refuse {
                return Err("no such container".into());
            }
            Ok(())
        }
    }

    fn device() -> Device {
        Device {
            name: "device-a".into(),
            home: "/soak/device-a/home".into(),
            root: "/soak/device-a/root".into(),
            container: None,
            unix_home: None,
            unix_user: Some("soak-a".into()),
            service: Some("soak-device@a.service".into()),
        }
    }

    fn dir(tag: &str) -> std::path::PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-soak-chaos-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    fn nap(_: Duration) {}

    #[test]
    fn a_kill_is_one_signal_and_it_is_journaled() {
        let dir = dir("kill");
        let reach = Recording::default();
        let mut chaos = Chaos::new(&reach, "10.0.0.5", &dir).unwrap();
        chaos.inject(&device(), Fault::Kill, &nap).unwrap();

        assert_eq!(*reach.calls.lock().unwrap(), vec!["signal device-a KILL"]);
        let records = crate::journal::read_dir(&dir).unwrap();
        assert!(matches!(
            &records[0],
            Record::Fault { kind, target, .. } if kind == "kill" && target == "device-a"
        ));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_freeze_always_thaws() {
        // A SIGSTOP with no SIGCONT is not a hang that resumes, it is a device
        // that is gone for the rest of the campaign.
        let dir = dir("freeze");
        let reach = Recording::default();
        let mut chaos = Chaos::new(&reach, "10.0.0.5", &dir).unwrap();
        chaos
            .inject(&device(), Fault::Freeze { seconds: 30 }, &nap)
            .unwrap();
        assert_eq!(
            *reach.calls.lock().unwrap(),
            vec!["signal device-a STOP", "signal device-a CONT"]
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_partition_is_always_lifted() {
        // A rule left in place turns one timed partition into a device that is
        // offline for the rest of the campaign, and every settle after it fails
        // for the wrong reason.
        let dir = dir("partition");
        let reach = Recording::default();
        let mut chaos = Chaos::new(&reach, "10.0.0.5", &dir).unwrap();
        chaos
            .inject(&device(), Fault::Partition { seconds: 60 }, &nap)
            .unwrap();
        assert_eq!(
            *reach.calls.lock().unwrap(),
            vec![
                "partition device-a 10.0.0.5 true",
                "partition device-a 10.0.0.5 false"
            ]
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_fault_that_could_not_be_injected_is_journaled_as_refused() {
        // A campaign reporting a hundred kills it never performed is a green run
        // over an adversary that was never there.
        let dir = dir("refused");
        let reach = Recording {
            refuse: true,
            ..Default::default()
        };
        let mut chaos = Chaos::new(&reach, "10.0.0.5", &dir).unwrap();
        chaos.inject(&device(), Fault::Kill, &nap).unwrap();

        let records = crate::journal::read_dir(&dir).unwrap();
        match &records[0] {
            Record::Fault { kind, detail, .. } => {
                assert_eq!(kind, "refused");
                assert!(detail.contains("kill"), "{detail}");
                assert!(detail.contains("no such process"), "{detail}");
            }
            other => panic!("expected a refused fault, got {other:?}"),
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn clearing_lifts_partitions_before_a_settle_begins() {
        // A settle that started with a device still cut off would fail
        // convergence on the rig's own leftovers.
        let dir = dir("clear");
        let reach = Recording::default();
        let mut chaos = Chaos::new(&reach, "10.0.0.5", &dir).unwrap();
        chaos.clear(&[device()]);
        let calls = reach.calls.lock().unwrap();
        assert!(!calls.is_empty());
        assert!(calls.iter().all(|c| c.ends_with("false")));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_device_that_can_be_named_neither_way_refuses_a_partition() {
        // Applied without a container or an owner-uid match it would cut every
        // device at once, and the journal would say one device was partitioned
        // while all of them were. A timeline that points at the wrong device is
        // worse than no timeline.
        let mut d = device();
        d.container = None;
        d.unix_user = None;
        let err = RealReach.set_partition(&d, "10.0.0.5", true).unwrap_err();
        assert!(err.contains("every device"), "{err}");
    }

    #[test]
    fn a_device_with_no_supervisor_refuses_to_be_restarted() {
        // Stopping it would work perfectly well. Nothing would start it again,
        // and every settle for the rest of the campaign would fail on a device
        // the rig itself switched off.
        let mut d = device();
        d.container = None;
        d.service = None;
        let err = RealReach.restart(&d).unwrap_err();
        assert!(err.contains("nothing would start it again"), "{err}");
    }

    #[test]
    fn the_phase_a_mix_produces_every_fault_it_promises() {
        let mut rng = Rng::new(4242);
        let mut seen = std::collections::BTreeSet::new();
        for _ in 0..500 {
            seen.insert(phase_a_mix(&mut rng).kind());
        }
        assert_eq!(
            seen.into_iter().collect::<Vec<_>>(),
            vec!["freeze", "kill", "partition", "restart"]
        );
    }

    #[test]
    fn kills_dominate_the_mix_because_they_are_what_finds_data_loss() {
        let mut rng = Rng::new(99);
        let kills = (0..1000)
            .filter(|_| phase_a_mix(&mut rng) == Fault::Kill)
            .count();
        assert!((350..650).contains(&kills), "kills were {kills} of 1000");
    }

    #[test]
    fn every_fault_says_what_it_did_in_words() {
        // The detail lands in a forensics bundle read months later by somebody
        // who was not here.
        for fault in [
            Fault::Kill,
            Fault::Freeze { seconds: 30 },
            Fault::Partition { seconds: 60 },
            Fault::Restart,
        ] {
            assert!(!fault.detail().is_empty());
            assert!(!fault.kind().is_empty());
        }
    }
}
