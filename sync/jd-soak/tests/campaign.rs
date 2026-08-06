//! The rig, driven end to end against a world it cannot break.
//!
//! Everything in this file exists to answer one question the unit tests cannot:
//! **does the conductor actually catch a violation, and does it produce evidence
//! when it does?** A verifier whose parts all pass their own tests and which
//! nonetheless reports green over a missing file is exactly the failure mode
//! this rig is meant to eliminate in the client, and it would be no less fatal
//! here.
//!
//! So the world is faked at the two seams the rig already has traits for — the
//! server API and the way faults reach a device — and then the campaign is run
//! for real: real threads, real actors, real files on a real disk, the real
//! journal, the real settle. The only things missing are a daemon and a network,
//! and their absence is itself something to assert about, because a rig that
//! passed with no client running would pass with a broken one.

use std::collections::BTreeMap;
use std::io::Write;
use std::path::PathBuf;
use std::sync::atomic::AtomicBool;
use std::sync::Mutex;
use std::time::Duration;

use jd_proto::{DriveApi, ProtoError, ReadSeek, UploadOutcome, UploadParams};
use jd_soak::chaos::Reach;
use jd_soak::fleet::{Device, Fleet};
use jd_soak::orchestrate::{self, Campaign};
use jd_soak::{journal, report, Record};
use serde_json::{json, Value};

/// A server that remembers what it was given and can answer an index walk.
///
/// Deliberately *not* a sync server — nothing here pushes bytes to a device.
/// That is what makes it useful: the devices in this test hold files the server
/// has never heard of, so every settle has real differences to find, and a
/// verifier that reported green would be caught immediately.
#[derive(Default)]
struct Paper {
    files: Mutex<Vec<(i64, String, String)>>,
    calls: Mutex<BTreeMap<String, u64>>,
}

impl Paper {
    fn note(&self, name: &str) {
        *self.calls.lock().unwrap().entry(name.into()).or_insert(0) += 1;
    }
    fn saw(&self, name: &str) -> u64 {
        self.calls.lock().unwrap().get(name).copied().unwrap_or(0)
    }
}

impl DriveApi for Paper {
    fn action(&self, name: &str, body: Value) -> Result<Value, ProtoError> {
        self.note(name);
        Ok(match name {
            "drive_index" => {
                let items: Vec<Value> = self
                    .files
                    .lock()
                    .unwrap()
                    .iter()
                    .map(|(id, file_name, sha)| {
                        json!({
                            "entity_type": "file", "id": id, "name": file_name,
                            "folder_id": null, "deleted": false, "encrypted": false,
                            "content_sha256": sha, "size": 1,
                        })
                    })
                    .collect();
                json!({ "items": items, "done": true })
            }
            "drive_versions" => json!({ "versions": [] }),
            "drive_folder_create" => {
                let id = 1000 + self.files.lock().unwrap().len() as i64;
                json!({ "folder": { "id": id, "name": body.get("name").cloned() } })
            }
            _ => json!({ "ok": true }),
        })
    }

    fn action_idempotent(&self, name: &str, body: Value, key: &str) -> Result<Value, ProtoError> {
        assert!(!key.is_empty(), "{name} carried no idempotency key");
        self.action(name, body)
    }

    fn upload(
        &self,
        params: &UploadParams,
        reader: &mut dyn ReadSeek,
    ) -> Result<UploadOutcome, ProtoError> {
        self.note("upload");
        let mut sink = Vec::new();
        std::io::copy(reader, &mut sink).map_err(ProtoError::Io)?;
        let mut files = self.files.lock().unwrap();
        let id = 1 + files.len() as i64;
        files.push((id, params.name.clone(), params.sha256.clone()));
        Ok(UploadOutcome {
            deduped: false,
            file: json!({ "id": id }),
        })
    }

    fn download(&self, _url: &str, _from: u64, _out: &mut dyn Write) -> Result<u64, ProtoError> {
        unimplemented!("nothing in the rig downloads")
    }
}

/// A device that cannot be reached, which is what a fault agent meets when the
/// container runtime is not there.
#[derive(Default)]
struct Unreachable {
    attempts: Mutex<Vec<String>>,
}

impl Reach for Unreachable {
    fn signal(&self, device: &Device, signal: &str) -> Result<(), String> {
        self.attempts
            .lock()
            .unwrap()
            .push(format!("{signal} {}", device.name));
        Err("there is no daemon here".into())
    }
    fn set_partition(&self, device: &Device, _host: &str, on: bool) -> Result<(), String> {
        self.attempts
            .lock()
            .unwrap()
            .push(format!("partition {} {on}", device.name));
        Err("there is no container here".into())
    }
    fn restart(&self, device: &Device) -> Result<(), String> {
        self.attempts
            .lock()
            .unwrap()
            .push(format!("restart {}", device.name));
        Err("there is no container here".into())
    }
}

struct Rig {
    dir: PathBuf,
    fleet: Fleet,
}

impl Rig {
    fn new(tag: &str) -> Rig {
        let dir =
            std::env::temp_dir().join(format!("jd-soak-campaign-{}-{}", tag, std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        let devices: Vec<Device> = ["device-a", "device-b"]
            .iter()
            .map(|name| Device {
                name: (*name).into(),
                home: dir.join(name).join("home"),
                root: dir.join(name).join("root"),
                // Nothing for the fault agent to reach: no container, no unix
                // account, no supervisor. Every fault it tries will be refused,
                // which is one of the things being asserted.
                container: None,
                unix_home: None,
                unix_user: None,
                service: None,
            })
            .collect();
        for device in &devices {
            std::fs::create_dir_all(&device.root).unwrap();
            std::fs::create_dir_all(&device.home).unwrap();
        }
        let fleet = Fleet {
            server: "https://soak.invalid".into(),
            devices,
            journal_dir: dir.join("journal"),
            bundle_dir: dir.join("bundles"),
            storm_seconds: 1,
            settle_deadline_seconds: 1,
            poll_seconds: 5,
        };
        std::fs::create_dir_all(&fleet.journal_dir).unwrap();
        std::fs::create_dir_all(&fleet.bundle_dir).unwrap();
        Rig { dir, fleet }
    }

    fn campaign(&self) -> Campaign {
        Campaign {
            // Short on purpose: these tests roll into the safe tier, and the
            // thing being proven is that the conductor works, not that it can
            // run for forty-five minutes.
            storm: Duration::from_secs(1),
            settle_deadline: Duration::from_secs(1),
            cycles: Some(1),
            pace: Duration::from_millis(5),
            fault_interval: Duration::from_millis(200),
            seed: 4242,
            stop_on_violation: true,
        }
    }
}

impl Drop for Rig {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.dir);
    }
}

#[test]
fn a_campaign_storms_settles_and_leaves_a_full_timeline_behind() {
    let rig = Rig::new("full");
    let api = Paper::default();
    let reach = Unreachable::default();
    let stop = AtomicBool::new(false);

    let outcome = orchestrate::run(&rig.fleet, &rig.campaign(), &api, &reach, &stop).unwrap();
    assert_eq!(outcome.cycles, 1);

    let records = journal::read_dir(&rig.fleet.journal_dir).unwrap();

    // The storm really ran: actors wrote to real disks through the real
    // executor, and the remote actor really spoke to the API.
    let commits = records
        .iter()
        .filter(|r| matches!(r, Record::ActorCommit { .. }))
        .count();
    assert!(commits > 20, "the storm only produced {commits} commits");
    assert!(api.saw("upload") > 0, "the remote actor never uploaded");

    // Both segment boundaries are marked, which is what lets a report bucket
    // everything between two of them.
    let segments: Vec<&Record> = records
        .iter()
        .filter(|r| matches!(r, Record::Segment { .. }))
        .collect();
    assert_eq!(segments.len(), 2, "expected a storm and a settle");

    // And every assertion reached a verdict.
    let assertions: std::collections::BTreeSet<String> = records
        .iter()
        .filter_map(|r| match r {
            Record::Verdict { assertion, .. } => Some(assertion.clone()),
            _ => None,
        })
        .collect();
    for expected in [
        "convergence",
        "audited-green",
        "no-loss",
        "no-ciphertext",
        "issues-honest",
        "leak-watch",
    ] {
        assert!(
            assertions.contains(expected),
            "the settle never reached a verdict on {expected}: {assertions:?}"
        );
    }
}

#[test]
fn a_world_with_no_client_in_it_fails_rather_than_passing() {
    // The single most important property of the whole rig. There is no daemon
    // running here, so nothing was ever synced — and a verifier that called that
    // green would call a broken client green too.
    let rig = Rig::new("noclient");
    let api = Paper::default();
    let reach = Unreachable::default();
    let stop = AtomicBool::new(false);

    let outcome = orchestrate::run(&rig.fleet, &rig.campaign(), &api, &reach, &stop).unwrap();
    assert!(!outcome.clean(), "a world with no client in it passed");

    let joined = outcome.violations.join(" | ");
    // Two distinct reasons, and both must be caught: nobody ever converged, and
    // every file the actors wrote is on a disk and nowhere else.
    assert!(joined.contains("convergence"), "{joined}");
    assert!(joined.contains("audited-green"), "{joined}");

    // No-loss deliberately does NOT fire here, and that is the correct answer
    // rather than a gap. Nothing ever reached this server, so it cannot have
    // lost anything it took — and the files are all still sitting on the disks
    // the actors wrote them to. Reporting loss would be reporting a file as gone
    // while pointing at it.
    assert!(
        !joined.contains("no-loss"),
        "no-loss fired in a world where nothing was ever uploaded and nothing is missing: {joined}"
    );
}

#[test]
fn a_violation_freezes_the_world_into_a_bundle_that_names_the_file() {
    let rig = Rig::new("bundle");
    let api = Paper::default();
    let reach = Unreachable::default();
    let stop = AtomicBool::new(false);

    let outcome = orchestrate::run(&rig.fleet, &rig.campaign(), &api, &reach, &stop).unwrap();
    let bundle = outcome
        .bundles
        .first()
        .expect("a violation produced no evidence");

    // The three things an investigation months later cannot proceed without.
    assert!(bundle.join("verdicts.txt").exists());
    assert!(bundle.join("timeline.txt").exists());
    assert!(bundle.join("journal").is_dir());

    let verdicts = std::fs::read_to_string(bundle.join("verdicts.txt")).unwrap();
    assert!(verdicts.contains("FAIL audited-green"), "{verdicts}");
    // Naming the file is what makes a finding actionable rather than alarming.
    // A verdict that says "the trees differ" and stops sends somebody to diff
    // two hundred thousand paths by hand.
    assert!(
        verdicts.contains(".docx") || verdicts.contains(".txt") || verdicts.contains(".psd"),
        "the failure named no file: {verdicts}"
    );

    // And the device trees are listed, so somebody can see what was where.
    assert!(bundle.join("device-a/tree.txt").exists());
}

#[test]
fn faults_that_could_not_be_injected_are_journaled_and_the_report_says_so() {
    // A campaign that reported a hundred kills it never performed would be a
    // green run over an adversary that was never there — worse than no run.
    let rig = Rig::new("refused");
    let api = Paper::default();
    let reach = Unreachable::default();
    let stop = AtomicBool::new(false);

    orchestrate::run(&rig.fleet, &rig.campaign(), &api, &reach, &stop).unwrap();

    assert!(
        !reach.attempts.lock().unwrap().is_empty(),
        "the chaos agent never tried to break anything"
    );
    let records = journal::read_dir(&rig.fleet.journal_dir).unwrap();
    let refused = records
        .iter()
        .filter(|r| matches!(r, Record::Fault { kind, .. } if kind == "refused"))
        .count();
    assert!(refused > 0, "a fault that failed was not journaled");

    let text = report::render(&report::summarize(&records));
    assert!(text.contains("weaker than it looks"), "{text}");
}

#[test]
fn the_rolling_report_is_written_where_a_person_will_look_for_it() {
    let rig = Rig::new("report");
    let api = Paper::default();
    let reach = Unreachable::default();
    let stop = AtomicBool::new(false);

    orchestrate::run(&rig.fleet, &rig.campaign(), &api, &reach, &stop).unwrap();

    let text = std::fs::read_to_string(rig.fleet.journal_dir.join("report.txt")).unwrap();
    assert!(text.contains("INVARIANT VIOLATIONS:"), "{text}");
    assert!(text.contains("By persona"), "{text}");
}

#[test]
fn stopping_a_campaign_ends_it_without_finishing_the_storm() {
    // The systemd unit's shutdown path. A campaign that ignored it would have to
    // be killed, and a killed conductor leaves partitions in place on every
    // device it had cut off.
    let rig = Rig::new("stop");
    let api = Paper::default();
    let reach = Unreachable::default();
    let stop = AtomicBool::new(true);

    let started = std::time::Instant::now();
    let outcome = orchestrate::run(&rig.fleet, &rig.campaign(), &api, &reach, &stop).unwrap();
    assert_eq!(outcome.cycles, 0);
    assert!(started.elapsed() < Duration::from_secs(2));
}

#[test]
fn the_actors_never_write_outside_the_sync_roots() {
    // The journals live next door. An actor that escaped would overwrite the
    // rig's own evidence, and the run would be unfalsifiable rather than merely
    // wrong.
    let rig = Rig::new("contained");
    let api = Paper::default();
    let reach = Unreachable::default();
    let stop = AtomicBool::new(false);

    orchestrate::run(&rig.fleet, &rig.campaign(), &api, &reach, &stop).unwrap();

    let mut stray = Vec::new();
    for entry in std::fs::read_dir(&rig.dir).unwrap().flatten() {
        let name = entry.file_name().to_string_lossy().to_string();
        if !["device-a", "device-b", "journal", "bundles"].contains(&name.as_str()) {
            stray.push(name);
        }
    }
    assert!(
        stray.is_empty(),
        "the actors left files outside the rig: {stray:?}"
    );

    // And the journal directory holds nothing but journals and the report.
    for entry in std::fs::read_dir(&rig.fleet.journal_dir).unwrap().flatten() {
        let name = entry.file_name().to_string_lossy().to_string();
        assert!(
            name.ends_with(".jsonl") || name == "report.txt",
            "something that is not evidence appeared in the journal directory: {name}"
        );
    }
}
