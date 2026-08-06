//! The conductor: storm, settle, storm, settle, for as long as it takes.
//!
//! A campaign is a loop over two kinds of segment.
//!
//! A **storm** runs every actor and the chaos agent together for
//! three quarters of an hour. Nothing is verified during a storm — the world is
//! in motion and every "difference" would be a file in flight.
//!
//! A **settle** stops the actors, lifts anything chaos left in place, and hands
//! the world to the verifier. This is where the campaign either passes or
//! produces its one valuable output.
//!
//! Two ordering rules do the real work, and both are about not blaming the
//! client for the rig's own mess:
//!
//! - **Partitions are lifted before the settle starts.** A settle that began
//!   with a device still cut off fails convergence on the rig's leftovers and
//!   sends somebody hunting a bug that is not there.
//! - **Actors stop before the deadline clock starts.** Convergence is measured
//!   from a quiet world, or it measures how fast the actors were typing.
//!
//! On a violation the world is **frozen and captured** rather than cleaned up
//! and carried on with. The bundle is the whole product of a failed run: without
//! it a rare bug is a line in a log that nobody can reproduce, and with it the
//! next step is to encode the timeline as a `jd-sim` scenario and own the bug
//! forever.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use jd_proto::DriveApi;
use jd_vfs::Personality;

use crate::actor::{now_ms, Actor};
use crate::chaos::{phase_a_mix, Chaos, Reach};
use crate::fleet::Fleet;
use crate::journal::{self, Journal, Record};
use crate::persona::{self, PHASE_A_LOCAL};
use crate::remote::RemoteActor;
use crate::report;
use crate::verify::{self, Sample, Verification};

/// How a campaign is set up.
pub struct Campaign {
    /// How long a storm runs.
    pub storm: Duration,
    /// How long the verifier will wait for the world to go quiet.
    pub settle_deadline: Duration,
    /// Stop after this many storm/settle cycles. `None` runs until told to stop,
    /// which is what the systemd unit does.
    pub cycles: Option<u64>,
    /// The gap between one actor burst and the next. On a one-CPU box a
    /// flat-out actor is a rig that measures its own scheduler.
    pub pace: Duration,
    /// The mean interval between faults.
    pub fault_interval: Duration,
    /// Where a segment's persona mix comes from.
    pub seed: u64,
    /// Stop the whole campaign at the first violation, rather than capturing and
    /// carrying on. What the bounded gate wants; not what a week-long run wants.
    pub stop_on_violation: bool,
}

impl Default for Campaign {
    fn default() -> Campaign {
        Campaign {
            storm: Duration::from_secs(45 * 60),
            settle_deadline: Duration::from_secs(15 * 60),
            cycles: None,
            pace: Duration::from_millis(250),
            fault_interval: Duration::from_secs(20 * 60),
            seed: 1,
            stop_on_violation: false,
        }
    }
}

/// How a campaign ended.
#[derive(Debug, Clone, Default)]
pub struct Outcome {
    pub cycles: u64,
    pub violations: Vec<String>,
    pub bundles: Vec<PathBuf>,
}

impl Outcome {
    pub fn clean(&self) -> bool {
        self.violations.is_empty()
    }
}

/// Run a campaign.
///
/// `api` is the server as the remote actor and the verifier reach it; `reach` is
/// how faults get to a device. Both are traits so the whole conductor can be
/// exercised without a server or a container runtime.
pub fn run(
    fleet: &Fleet,
    campaign: &Campaign,
    api: &dyn DriveApi,
    reach: &dyn Reach,
    stop: &AtomicBool,
) -> Result<Outcome, crate::journal::JournalError> {
    let mut conductor = Journal::open(&fleet.journal_dir, "orchestrator")?;
    let mut chaos = Chaos::new(reach, &server_host(&fleet.server), &fleet.journal_dir)?;
    let personality = Personality::probe(&fleet.devices[0].root);
    let excluded = excluded_per_device(fleet);

    let mut outcome = Outcome::default();
    let mut leak_history: Vec<Vec<Sample>> = Vec::new();
    // Grows across the campaign. The server taking a content is a promise to
    // keep it, and this is the memory that promise is checked against.
    let mut seen_on_server: std::collections::BTreeSet<String> = Default::default();
    let mut cycle = 0u64;

    while !stop.load(Ordering::Relaxed) {
        cycle += 1;
        if let Some(limit) = campaign.cycles {
            if cycle > limit {
                break;
            }
        }

        // ---- storm --------------------------------------------------------
        let mix = persona_mix(campaign.seed, cycle);
        let seq = conductor.next_seq();
        conductor.write(&Record::Segment {
            seq,
            index: cycle,
            kind: "storm".into(),
            detail: format!("personas: {}", mix.join(", ")),
            ts_ms: now_ms(),
        })?;
        storm(fleet, campaign, api, &mut chaos, &mix, cycle, stop)?;

        // ---- settle -------------------------------------------------------
        let seq = conductor.next_seq();
        conductor.write(&Record::Segment {
            seq,
            index: cycle,
            kind: "settle".into(),
            detail: format!("deadline {}s", campaign.settle_deadline.as_secs()),
            ts_ms: now_ms(),
        })?;
        // Before anything is measured. Chaos leftovers must never be what a
        // settle is diagnosing.
        chaos.clear(&fleet.devices);

        let records = journal::read_dir(&fleet.journal_dir)?;
        let verification = verify::settle(
            fleet,
            api,
            &records,
            &personality,
            &excluded,
            &leak_history,
            &seen_on_server,
            campaign.settle_deadline,
            &|d| std::thread::sleep(d),
        );
        leak_history.push(verification.samples.clone());
        seen_on_server.extend(verification.server_contents.iter().cloned());

        for verdict in &verification.verdicts {
            let seq = conductor.next_seq();
            conductor.write(&Record::Verdict {
                seq,
                segment: cycle,
                assertion: verdict.assertion.clone(),
                ok: verdict.ok,
                detail: verdict.detail.clone(),
                ts_ms: now_ms(),
            })?;
        }
        for sample in &verification.samples {
            let seq = conductor.next_seq();
            conductor.write(&Record::Sample {
                seq,
                segment: cycle,
                device: sample.device.clone(),
                rss_kb: sample.rss_kb,
                fd_count: sample.fd_count,
                spool_files: sample.spool_files,
                spool_bytes: sample.spool_bytes,
                store_bytes: sample.store_bytes,
                pending_ops: sample.pending_ops,
                convergence_ms: verification.convergence_ms.get(&sample.device).copied(),
                ts_ms: now_ms(),
            })?;
        }

        write_report(fleet)?;

        if verification.violated() {
            for failure in verification.failures() {
                outcome
                    .violations
                    .push(format!("{}: {}", failure.assertion, failure.detail));
            }
            match capture(fleet, cycle, &verification) {
                Ok(bundle) => outcome.bundles.push(bundle),
                Err(e) => eprintln!("warning: the forensics bundle could not be written: {e}"),
            }
            if campaign.stop_on_violation {
                outcome.cycles = cycle;
                return Ok(outcome);
            }
        }
        outcome.cycles = cycle;
    }

    Ok(outcome)
}

/// One storm: every actor and the chaos agent, running together.
fn storm(
    fleet: &Fleet,
    campaign: &Campaign,
    api: &dyn DriveApi,
    chaos: &mut Chaos<'_>,
    mix: &[String],
    cycle: u64,
    stop: &AtomicBool,
) -> Result<(), crate::journal::JournalError> {
    let quiet = AtomicBool::new(false);
    let deadline = Instant::now() + campaign.storm;

    std::thread::scope(|scope| {
        // Local actors: one thread per persona per device. The mix is the same
        // on every device on purpose — that is what puts two of them in the same
        // workspace, which is where the races are.
        for device in &fleet.devices {
            for name in mix {
                let Some(persona) = persona::build(name) else {
                    continue;
                };
                let seed = campaign
                    .seed
                    .wrapping_mul(1_000_003)
                    .wrapping_add(cycle.wrapping_mul(31))
                    .wrapping_add(hash_name(&device.name))
                    .wrapping_add(hash_name(name));
                // Shared, so two devices' `office` actors fight over the same
                // documents, and private, so `sqlite-app` is not two programs
                // writing one database — which is a corrupt file rather than a
                // sync bug.
                let workspace = if name == "sqlite-app" || name == "browser" {
                    device.root.join(format!("{}-{}", device.name, name))
                } else {
                    device.root.join(format!("Shared-{name}"))
                };
                let quiet = &quiet;
                scope.spawn(move || {
                    let mut actor = match Actor::new(
                        &device.name,
                        &workspace,
                        persona,
                        seed,
                        &fleet.journal_dir,
                    ) {
                        Ok(a) => a,
                        Err(e) => {
                            eprintln!("warning: {} could not start: {e}", device.name);
                            return;
                        }
                    };
                    let mut pace = crate::rng::Rng::new(seed);
                    while !quiet.load(Ordering::Relaxed) && Instant::now() < deadline {
                        if let Err(e) = actor.step() {
                            eprintln!("warning: actor journal failed: {e}");
                            return;
                        }
                        // Jittered, so the personas do not fall into lockstep and
                        // start hitting the server in waves the real world would
                        // never produce.
                        let jitter = pace.range(0, campaign.pace.as_millis() as u64 + 1);
                        std::thread::sleep(campaign.pace + Duration::from_millis(jitter));
                    }
                });
            }
        }

        // The remote actor: the web user, with no device of its own.
        {
            let quiet = &quiet;
            scope.spawn(move || {
                let mut actor = match RemoteActor::new(
                    api,
                    campaign.seed.wrapping_add(cycle),
                    "Web",
                    &fleet.journal_dir,
                ) {
                    Ok(a) => a,
                    Err(e) => {
                        eprintln!("warning: the remote actor could not start: {e}");
                        return;
                    }
                };
                // An order of magnitude slower than the local actors, because a
                // remote change costs a round trip and the point is realism
                // rather than throughput. Derived from the campaign's own pace
                // rather than fixed, so a short storm gets a proportionally
                // busy remote user instead of one that creates its folder and
                // then never does anything else.
                let remote_pace = std::cmp::max(campaign.pace * 12, Duration::from_millis(50));
                while !quiet.load(Ordering::Relaxed) && Instant::now() < deadline {
                    if actor.step().is_err() {
                        return;
                    }
                    std::thread::sleep(remote_pace);
                }
            });
        }

        // Chaos runs on the conductor's own thread so its journal handle is not
        // shared, and so a fault is never in flight while the storm is being
        // torn down.
        let mut rng = crate::rng::Rng::new(campaign.seed.wrapping_add(cycle.wrapping_mul(7)));
        while Instant::now() < deadline && !stop.load(Ordering::Relaxed) {
            let wait = rng.exponential_ms(campaign.fault_interval.as_millis() as u64);
            let until = Instant::now() + Duration::from_millis(wait);
            while Instant::now() < until {
                if Instant::now() >= deadline || stop.load(Ordering::Relaxed) {
                    break;
                }
                std::thread::sleep(Duration::from_millis(200));
            }
            if Instant::now() >= deadline || stop.load(Ordering::Relaxed) {
                break;
            }
            let device = &fleet.devices[rng.below(fleet.devices.len())];
            // Trimmed to what is left of the storm. A partition drawn at up to
            // four minutes and started ten seconds before the deadline used to
            // hold the whole segment open for the rest of its duration, and the
            // settle it delayed then measured a world that had been sitting
            // still — or, worse, one still cut off, which fails convergence on
            // the rig's own doing.
            let remaining = deadline.saturating_duration_since(Instant::now());
            let fault = trim_to_segment(phase_a_mix(&mut rng), remaining);
            if let Err(e) = chaos.inject(device, fault, &|d| std::thread::sleep(d)) {
                eprintln!("warning: the chaos journal failed: {e}");
            }
        }

        // Actors stop before the deadline clock starts, or convergence measures
        // how fast they were typing rather than how fast the client settles.
        quiet.store(true, Ordering::Relaxed);
    });

    Ok(())
}

/// Shorten a timed fault so it cannot outlive the segment that started it.
///
/// Faults with a duration sleep for it, and the storm does not end until the
/// last one has finished. A four-minute partition begun ten seconds before the
/// deadline therefore held the segment open for the rest of its run, and the
/// settle that followed measured either a world that had been sitting still or —
/// worse — one still cut off from the server, which fails convergence for
/// something the rig did to itself.
///
/// A few seconds are always left, because a partition of zero seconds is not a
/// fault, it is a journal entry claiming one.
pub fn trim_to_segment(fault: crate::chaos::Fault, remaining: Duration) -> crate::chaos::Fault {
    use crate::chaos::Fault;
    let cap = remaining.as_secs().max(5);
    match fault {
        Fault::Freeze { seconds } => Fault::Freeze {
            seconds: seconds.min(cap),
        },
        Fault::Partition { seconds } => Fault::Partition {
            seconds: seconds.min(cap),
        },
        // Instantaneous — nothing to trim.
        other => other,
    }
}

/// Which personas this cycle runs.
///
/// Rotated rather than all-at-once: fourteen actors per device on a small box
/// spend their time in the scheduler, and a mix that changes is also a mix that
/// eventually produces combinations a fixed roster never would.
pub fn persona_mix(seed: u64, cycle: u64) -> Vec<String> {
    let mut rng = crate::rng::Rng::new(seed.wrapping_add(cycle.wrapping_mul(2_654_435_761)));
    let mut pool: Vec<&str> = PHASE_A_LOCAL.to_vec();
    let mut chosen = Vec::new();
    // Four is enough to have several kinds of storm running against each other
    // and few enough to leave the box able to run the daemon.
    for _ in 0..4 {
        if pool.is_empty() {
            break;
        }
        let i = rng.below(pool.len());
        chosen.push(pool.remove(i).to_string());
    }
    // `messy-human` every cycle: it is the one that breaks move detection, which
    // is the most expensive thing to get wrong.
    if !chosen.iter().any(|c| c == "messy-human") {
        chosen.pop();
        chosen.push("messy-human".into());
    }
    chosen.sort();
    chosen
}

fn hash_name(name: &str) -> u64 {
    name.bytes().fold(1469598103934665603u64, |h, b| {
        (h ^ b as u64).wrapping_mul(1099511628211)
    })
}

/// The host part of a server URL, which is what a firewall rule needs.
pub fn server_host(url: &str) -> String {
    url.trim_start_matches("https://")
        .trim_start_matches("http://")
        .split('/')
        .next()
        .unwrap_or(url)
        .split(':')
        .next()
        .unwrap_or(url)
        .to_string()
}

/// What each device was told not to sync, read from its own config.
fn excluded_per_device(fleet: &Fleet) -> BTreeMap<String, Vec<String>> {
    let mut out = BTreeMap::new();
    for device in &fleet.devices {
        let excluded = jd_daemon::config::Config::load(&device.config_file())
            .map(|c| c.excluded)
            .unwrap_or_default();
        out.insert(device.name.clone(), excluded);
    }
    out
}

/// Rewrite the rolling report from the journals.
///
/// Rebuilt from the journal every settle rather than accumulated in memory, so
/// the report a campaign has been writing for six days and the report `jd-soak
/// report` produces from the same files are the same document. An accumulated
/// one drifts, and the first time anybody notices is when the two disagree about
/// a violation count.
fn write_report(fleet: &Fleet) -> Result<(), crate::journal::JournalError> {
    let records = journal::read_dir(&fleet.journal_dir)?;
    let text = report::render(&report::summarize(&records));
    let _ = std::fs::write(fleet.journal_dir.join("report.txt"), text);
    Ok(())
}

/// Freeze the world and take everything an investigation will need.
///
/// Copied rather than referenced, because the next storm overwrites all of it.
/// A bundle that pointed at live files would be empty by the time anybody opened
/// it.
pub fn capture(fleet: &Fleet, cycle: u64, verification: &Verification) -> std::io::Result<PathBuf> {
    let bundle = fleet
        .bundle_dir
        .join(format!("violation-cycle-{cycle}-{}", now_ms()));
    std::fs::create_dir_all(&bundle)?;

    // The three journals, whole. Trimming them to the loss window would throw
    // away the fault that caused it, which is often hours earlier.
    let journals = bundle.join("journal");
    std::fs::create_dir_all(&journals)?;
    if let Ok(entries) = std::fs::read_dir(&fleet.journal_dir) {
        for entry in entries.flatten() {
            let path = entry.path();
            if path.is_file() {
                let _ = std::fs::copy(&path, journals.join(entry.file_name()));
            }
        }
    }

    for device in &fleet.devices {
        let into = bundle.join(&device.name);
        std::fs::create_dir_all(&into)?;
        // The state store is the device's own account of what it agreed to, and
        // it is the first thing anybody will want to open.
        let _ = std::fs::copy(device.state_db(), into.join("state.db"));
        let _ = std::fs::copy(device.config_file(), into.join("config.json"));
        let logs = device.home.join("logs");
        if let Ok(entries) = std::fs::read_dir(&logs) {
            for entry in entries.flatten() {
                let _ = std::fs::copy(entry.path(), into.join(entry.file_name()));
            }
        }
        // A listing rather than a copy of the tree: the tree can be tens of
        // gigabytes, and what an investigation needs is what was where.
        let _ = std::fs::write(into.join("tree.txt"), list_tree(&device.root));
    }

    let mut verdicts = String::new();
    for verdict in &verification.verdicts {
        verdicts.push_str(&format!(
            "{} {}: {}\n",
            if verdict.ok { "PASS" } else { "FAIL" },
            verdict.assertion,
            verdict.detail
        ));
    }
    std::fs::write(bundle.join("verdicts.txt"), verdicts)?;
    std::fs::write(bundle.join("timeline.txt"), timeline(fleet))?;
    let _ = fleet.save(&bundle.join("fleet.json"));

    Ok(bundle)
}

/// The last stretch of the merged timeline, with faults called out.
///
/// This is the artifact the whole forensics-over-replay decision rests on:
/// correlating the loss window against the fault that was in flight is what
/// turns a soak finding into a `jd-sim` scenario.
fn timeline(fleet: &Fleet) -> String {
    let Ok(records) = journal::read_dir(&fleet.journal_dir) else {
        return "the journals could not be read\n".into();
    };
    let start = records.len().saturating_sub(5000);
    let mut out = String::new();
    for record in &records[start..] {
        let line = match record {
            Record::ActorCommit {
                actor, op, path, ..
            } => format!("       {actor} {op} {path}"),
            Record::ActorFailed {
                actor,
                op,
                path,
                error,
                ..
            } => format!("  x    {actor} {op} {path}: {error}"),
            Record::Fault {
                kind,
                target,
                detail,
                ..
            } => format!("!!!!!! FAULT {kind} on {target} — {detail}"),
            Record::Verdict {
                assertion,
                ok,
                detail,
                ..
            } => format!(
                "====== {} {assertion}: {detail}",
                if *ok { "PASS" } else { "FAIL" }
            ),
            Record::Segment { kind, index, .. } => format!("------ segment {index} {kind}"),
            _ => continue,
        };
        out.push_str(&format!("{} {line}\n", record.ts_ms()));
    }
    out
}

fn list_tree(root: &Path) -> String {
    let mut out = String::new();
    fn walk(root: &Path, dir: &Path, out: &mut String) {
        let Ok(entries) = std::fs::read_dir(dir) else {
            return;
        };
        let mut entries: Vec<_> = entries.flatten().collect();
        entries.sort_by_key(|e| e.path());
        for entry in entries {
            let path = entry.path();
            let relative = path.strip_prefix(root).unwrap_or(&path).display();
            match entry.metadata() {
                Ok(m) if m.is_dir() => {
                    out.push_str(&format!("d          {relative}\n"));
                    walk(root, &path, out);
                }
                Ok(m) => out.push_str(&format!("f {:>9} {relative}\n", m.len())),
                Err(_) => out.push_str(&format!("?          {relative}\n")),
            }
        }
    }
    walk(root, root, &mut out);
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::fleet::Device;

    fn fleet(dir: &Path) -> Fleet {
        Fleet {
            server: "https://soak.example.com".into(),
            devices: vec![
                Device {
                    name: "device-a".into(),
                    home: dir.join("device-a/home"),
                    root: dir.join("device-a/root"),
                    container: None,
                    unix_home: None,
                    unix_user: Some("soak-a".into()),
                    service: Some("soak-device@a.service".into()),
                },
                Device {
                    name: "device-b".into(),
                    home: dir.join("device-b/home"),
                    root: dir.join("device-b/root"),
                    container: None,
                    unix_home: None,
                    unix_user: Some("soak-b".into()),
                    service: Some("soak-device@b.service".into()),
                },
            ],
            journal_dir: dir.join("journal"),
            bundle_dir: dir.join("bundles"),
            storm_seconds: 1,
            settle_deadline_seconds: 1,
            poll_seconds: 30,
        }
    }

    fn tmp(tag: &str) -> PathBuf {
        let p = std::env::temp_dir().join(format!(
            "jd-soak-orch-{}-{}-{:?}",
            tag,
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&p);
        std::fs::create_dir_all(&p).unwrap();
        p
    }

    #[test]
    fn a_firewall_rule_gets_the_host_and_not_the_url() {
        assert_eq!(
            server_host("https://drivetest.getjoinery.com"),
            "drivetest.getjoinery.com"
        );
        assert_eq!(server_host("http://10.0.0.5:8080/x"), "10.0.0.5");
        assert_eq!(server_host("https://soak.example.com/"), "soak.example.com");
    }

    #[test]
    fn the_persona_mix_changes_from_cycle_to_cycle() {
        // A fixed roster is a fixed set of interactions, and the combinations a
        // rotating mix eventually produces are the ones nobody thought to write
        // down.
        let mixes: Vec<Vec<String>> = (1..12).map(|c| persona_mix(7, c)).collect();
        let distinct: std::collections::BTreeSet<Vec<String>> = mixes.iter().cloned().collect();
        assert!(distinct.len() > 3, "the mix barely changed: {distinct:?}");
    }

    #[test]
    fn the_persona_that_breaks_move_detection_runs_every_single_cycle() {
        // messy-human is the most expensive thing to get wrong, so it is never
        // rotated out.
        for cycle in 1..40 {
            let mix = persona_mix(11, cycle);
            assert!(
                mix.iter().any(|p| p == "messy-human"),
                "cycle {cycle} left out messy-human: {mix:?}"
            );
        }
    }

    #[test]
    fn every_persona_in_a_mix_is_one_that_can_actually_be_built() {
        for cycle in 1..40 {
            for name in persona_mix(13, cycle) {
                assert!(persona::build(&name).is_some(), "{name} is not buildable");
            }
        }
    }

    #[test]
    fn a_mix_is_small_enough_to_leave_the_box_able_to_run_the_daemon() {
        for cycle in 1..40 {
            let mix = persona_mix(17, cycle);
            assert!(
                mix.len() <= 4,
                "{} personas per device is too many",
                mix.len()
            );
            let distinct: std::collections::BTreeSet<&String> = mix.iter().collect();
            assert_eq!(
                distinct.len(),
                mix.len(),
                "a persona was listed twice: {mix:?}"
            );
        }
    }

    #[test]
    fn a_bundle_carries_the_journals_the_verdicts_and_a_timeline() {
        // Without these three a rare bug is a line in a log nobody can
        // reproduce.
        let dir = tmp("bundle");
        let fleet = fleet(&dir);
        std::fs::create_dir_all(&fleet.journal_dir).unwrap();
        std::fs::create_dir_all(&fleet.devices[0].root).unwrap();
        std::fs::write(fleet.devices[0].root.join("a.txt"), "x").unwrap();

        let mut journal = Journal::open(&fleet.journal_dir, "chaos").unwrap();
        journal
            .write(&Record::Fault {
                seq: 1,
                kind: "kill".into(),
                target: "device-a".into(),
                detail: "SIGKILL".into(),
                ts_ms: 100,
            })
            .unwrap();

        let verification = Verification {
            verdicts: vec![crate::Verdict::fail("no-loss", "Report.docx is nowhere")],
            samples: Vec::new(),
            convergence_ms: BTreeMap::new(),
            server_contents: Default::default(),
        };
        let bundle = capture(&fleet, 3, &verification).unwrap();

        assert!(bundle.join("journal/chaos.jsonl").exists());
        let verdicts = std::fs::read_to_string(bundle.join("verdicts.txt")).unwrap();
        assert!(verdicts.contains("FAIL no-loss: Report.docx is nowhere"));
        let timeline = std::fs::read_to_string(bundle.join("timeline.txt")).unwrap();
        assert!(timeline.contains("FAULT kill on device-a"), "{timeline}");
        let tree = std::fs::read_to_string(bundle.join("device-a/tree.txt")).unwrap();
        assert!(tree.contains("a.txt"), "{tree}");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_bundle_is_a_copy_and_survives_the_next_storm_overwriting_everything() {
        // A bundle that pointed at live files would be empty by the time anybody
        // opened it.
        let dir = tmp("copy");
        let fleet = fleet(&dir);
        std::fs::create_dir_all(&fleet.journal_dir).unwrap();
        let mut journal = Journal::open(&fleet.journal_dir, "actor").unwrap();
        journal
            .write(&Record::Fault {
                seq: 1,
                kind: "kill".into(),
                target: "device-a".into(),
                detail: String::new(),
                ts_ms: 1,
            })
            .unwrap();

        let bundle = capture(
            &fleet,
            1,
            &Verification {
                verdicts: Vec::new(),
                samples: Vec::new(),
                convergence_ms: BTreeMap::new(),
                server_contents: Default::default(),
            },
        )
        .unwrap();

        std::fs::remove_dir_all(&fleet.journal_dir).unwrap();
        assert!(bundle.join("journal/actor.jsonl").exists());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn the_timeline_marks_faults_so_they_can_be_found_by_eye() {
        // Somebody reading five thousand lines needs the faults to stand out.
        let dir = tmp("timeline");
        let fleet = fleet(&dir);
        std::fs::create_dir_all(&fleet.journal_dir).unwrap();
        let mut journal = Journal::open(&fleet.journal_dir, "mixed").unwrap();
        journal
            .write(&Record::ActorCommit {
                seq: 1,
                actor: "device-a/office".into(),
                persona: "office".into(),
                op: "write".into(),
                path: "a.docx".into(),
                sha256: Some("aa".into()),
                size: 1,
                mtime_ms: None,
                ts_ms: 1,
            })
            .unwrap();
        journal
            .write(&Record::Fault {
                seq: 2,
                kind: "partition".into(),
                target: "device-b".into(),
                detail: "60s".into(),
                ts_ms: 2,
            })
            .unwrap();

        let text = timeline(&fleet);
        assert!(text.contains("!!!!!! FAULT partition on device-b"));
        assert!(text.contains("device-a/office write a.docx"));
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_fault_cannot_outlive_the_storm_that_started_it() {
        // The bug this fixes was found live: a partition drawn at 195 seconds
        // started near the end of a 180-second storm, and the settle that
        // followed ran against a device the rig itself had cut off.
        use crate::chaos::Fault;
        let trimmed = trim_to_segment(Fault::Partition { seconds: 195 }, Duration::from_secs(20));
        assert_eq!(trimmed, Fault::Partition { seconds: 20 });

        let frozen = trim_to_segment(Fault::Freeze { seconds: 120 }, Duration::from_secs(30));
        assert_eq!(frozen, Fault::Freeze { seconds: 30 });
    }

    #[test]
    fn a_fault_that_still_has_room_is_left_alone() {
        use crate::chaos::Fault;
        let fault = Fault::Partition { seconds: 40 };
        assert_eq!(trim_to_segment(fault, Duration::from_secs(600)), fault);
    }

    #[test]
    fn a_fault_is_never_trimmed_away_to_nothing() {
        // A partition of zero seconds is not a fault, it is a journal entry
        // claiming one — and a campaign counting those is reporting an adversary
        // it did not have.
        use crate::chaos::Fault;
        let trimmed = trim_to_segment(Fault::Partition { seconds: 200 }, Duration::ZERO);
        assert_eq!(trimmed, Fault::Partition { seconds: 5 });
    }

    #[test]
    fn an_instantaneous_fault_is_unaffected_by_the_time_left() {
        use crate::chaos::Fault;
        assert_eq!(trim_to_segment(Fault::Kill, Duration::ZERO), Fault::Kill);
        assert_eq!(
            trim_to_segment(Fault::Restart, Duration::ZERO),
            Fault::Restart
        );
    }

    #[test]
    fn an_outcome_with_no_violations_is_clean() {
        assert!(Outcome::default().clean());
        let mut outcome = Outcome::default();
        outcome.violations.push("no-loss: gone".into());
        assert!(!outcome.clean());
    }
}
