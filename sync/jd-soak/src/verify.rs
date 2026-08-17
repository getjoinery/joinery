//! Settle the world, then check the six things that must be true.
//!
//! The rule that governs every line of this module: **the daemon's opinion of
//! itself is never evidence**. It is used once, to know when it has stopped
//! working so there is a stable world to look at. Everything after that is the
//! verifier's own tree walks and the server's own answers. The bug class this
//! rig exists to catch is a client that reports green over a missing file, and a
//! verifier that asked the client whether it was right would agree with it every
//! single time.
//!
//! The six, in the order they run — which is also the order of how much they
//! cost and how bad it is when they fail:
//!
//! 1. **Convergence within the deadline.** A stall is a failure, not a wait. The
//!    product's promise is "never silently stop", so a client that is quietly
//!    still working an hour later is a first-class bug even with nothing lost.
//! 2. **Green is independently audited.** Every device's disk against the
//!    server, diffed by this code rather than by the thing under test.
//! 3. **No loss.** Every content an actor committed must still be findable
//!    somewhere legitimate.
//! 4. **Ciphertext never materializes.** No sync root holds bytes only the
//!    server was ever supposed to see.
//! 5. **Issues honesty.** Anything not finished has a surfaced reason with its
//!    name on it.
//! 6. **Leak watch.** Memory, descriptors, spool residue and store size,
//!    sampled every settle so a slow leak is visible before it is an outage.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;
use std::time::{Duration, Instant};

use jd_proto::DriveApi;
use jd_vfs::Personality;

use crate::control::{self, Status};
use crate::fleet::{Device, Fleet};
use crate::journal::{self, Record};
use crate::server::{self, ServerTree};
use crate::tree::{self, LocalTree};

/// What one assertion concluded.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Verdict {
    pub assertion: String,
    pub ok: bool,
    pub detail: String,
}

impl Verdict {
    pub fn pass(assertion: &str, detail: impl Into<String>) -> Verdict {
        Verdict {
            assertion: assertion.into(),
            ok: true,
            detail: detail.into(),
        }
    }

    pub fn fail(assertion: &str, detail: impl Into<String>) -> Verdict {
        Verdict {
            assertion: assertion.into(),
            ok: false,
            detail: detail.into(),
        }
    }
}

/// One settle's whole result.
#[derive(Debug, Clone)]
pub struct Verification {
    pub verdicts: Vec<Verdict>,
    pub samples: Vec<Sample>,
    /// How long each device took to stop working.
    pub convergence_ms: BTreeMap<String, u64>,
    /// Everything the server was holding at this settle, for the next one to
    /// hold it to.
    pub server_contents: BTreeSet<String>,
    /// Every lost content in full, because the verdict names only the first ten.
    pub losses: Losses,
}

/// Everything a settle found missing, untruncated.
///
/// The verdict line names ten and says how many there were, which is right for
/// something read on a terminal and wrong as the only record: a campaign that
/// reported twenty-three lost files left no artifact naming more than ten of
/// them, so the rest could not be chased afterwards at all.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Losses {
    /// Live paths whose last committed content is nowhere, as named lines.
    pub live: Vec<String>,
    /// Contents the server was seen holding and then lost, as short hashes.
    pub history: Vec<String>,
}

impl Losses {
    pub fn is_empty(&self) -> bool {
        self.live.is_empty() && self.history.is_empty()
    }
}

impl Verification {
    pub fn violated(&self) -> bool {
        self.verdicts.iter().any(|v| !v.ok)
    }

    pub fn failures(&self) -> Vec<&Verdict> {
        self.verdicts.iter().filter(|v| !v.ok).collect()
    }
}

/// The leak-watch reading for one device.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Sample {
    pub device: String,
    pub rss_kb: u64,
    pub fd_count: u64,
    pub spool_files: u64,
    pub spool_bytes: u64,
    pub store_bytes: u64,
    pub pending_ops: u64,
    /// How many entities this device is tracking. The denominator for RSS: a
    /// client holds a bounded amount of memory per entity, so a campaign that
    /// only ever adds files makes RSS rise at every settle no matter how
    /// healthy it is.
    pub tracked: u64,
}

// ---------------------------------------------------------------------------
// 1 — convergence
// ---------------------------------------------------------------------------

/// Fold one reading of one device into what the fleet is believed to have
/// reached.
///
/// A quiet reading is remembered at the time of the FIRST one, because how long
/// a device took to go quiet is the number worth reporting. A busy reading
/// forgets the device entirely, and that is the whole point of this being a
/// function rather than an insert: convergence has to mean every device quiet
/// **at once**. Banking the first quiet reading and never looking again let a
/// device settle, pick work back up — because another device had just uploaded,
/// or because its own last pass left a conflict copy the next scan had yet to
/// claim — and still count towards a fleet the audit then measured as though it
/// were standing still.
fn note_reading(
    settled_at: &mut BTreeMap<String, u64>,
    device: &str,
    quiet: bool,
    elapsed_ms: u64,
) {
    if quiet {
        settled_at.entry(device.to_string()).or_insert(elapsed_ms);
    } else {
        settled_at.remove(device);
    }
}

/// Wait for every device to stop working, or run out of patience.
///
/// Returns `(verdict, per-device milliseconds, last status seen)`.
///
/// A device with no control channel at all is *not* immediately a failure: it
/// may be a daemon that was killed a second ago and whose supervisor has not
/// restarted it yet, which is an ordinary thing for this rig to have caused. It
/// becomes a failure by never answering before the deadline, which is the same
/// bar as never converging — either way the client has silently stopped.
///
/// **Settled means settled at the same moment.** A device that goes quiet is
/// re-checked, not banked: one that reports itself finished and then picks up
/// work — because another device just uploaded, or because its own last pass
/// left a conflict copy the next scan has yet to claim — has not converged, and
/// the fleet it belongs to has not either. Banking the first quiet reading let
/// the audit run against a tree still in motion, and it showed up as a single
/// file on one side and not the other, at the end of a settle that called
/// itself clean.
pub fn await_convergence(
    fleet: &Fleet,
    deadline: Duration,
    sleep: &dyn Fn(Duration),
) -> (
    Verdict,
    BTreeMap<String, u64>,
    BTreeMap<String, Option<Status>>,
) {
    for device in &fleet.devices {
        control::sync_now(&device.control_file());
    }

    let started = Instant::now();
    let mut settled_at: BTreeMap<String, u64> = BTreeMap::new();
    let mut last: BTreeMap<String, Option<Status>> = BTreeMap::new();

    loop {
        for device in &fleet.devices {
            let status = control::status(&device.control_file());
            let quiet = status.as_ref().is_some_and(Status::is_settled);
            note_reading(
                &mut settled_at,
                &device.name,
                quiet,
                started.elapsed().as_millis() as u64,
            );
            last.insert(device.name.clone(), status);
        }
        if settled_at.len() == fleet.devices.len() {
            let slowest = settled_at.values().copied().max().unwrap_or(0);
            return (
                Verdict::pass(
                    "convergence",
                    format!(
                        "all {} devices settled, slowest {}s",
                        fleet.devices.len(),
                        slowest / 1000
                    ),
                ),
                settled_at,
                last,
            );
        }
        if started.elapsed() >= deadline {
            break;
        }
        // Scaled to the deadline rather than fixed. Two seconds is right when a
        // device has fifteen minutes to settle and wrong when it has two — a
        // settle that spent most of its budget asleep would report a stall it
        // never gave the client a chance to avoid.
        sleep(poll_interval(deadline));
    }

    let stragglers: Vec<String> = fleet
        .devices
        .iter()
        .filter(|d| !settled_at.contains_key(&d.name))
        .map(|d| match last.get(&d.name).and_then(|s| s.clone()) {
            Some(s) => {
                // Say which of the three settling conditions is unmet, because
                // they fail for different reasons and want different questions
                // asked next. A queue that is still draining is work in motion;
                // entries in flight with nothing queued for them is the silent
                // stall this rig exists to find; a bad indicator is the daemon
                // saying it cannot sync at all.
                let mut waiting_on = Vec::new();
                if s.indicator != "green" && s.indicator != "attention" {
                    waiting_on.push(format!("indicator {}", s.indicator));
                }
                if s.pending_ops > 0 {
                    waiting_on.push(format!("{} queued", s.pending_ops));
                }
                for (state, n) in s.in_flight_by_state() {
                    waiting_on.push(format!("{n} {state}"));
                }
                format!(
                    "{} is {} (waiting on {}): {}",
                    d.name,
                    s.indicator,
                    waiting_on.join(", "),
                    s.summary
                )
            }
            None => format!("{} never answered its control channel", d.name),
        })
        .collect();

    (
        Verdict::fail(
            "convergence",
            format!(
                "still working after {}s — {}",
                deadline.as_secs(),
                stragglers.join("; ")
            ),
        ),
        settled_at,
        last,
    )
}

/// How often to ask a device whether it has finished.
///
/// A quarter of the deadline, capped at two seconds and floored at fifty
/// milliseconds. Cheap enough to run every two seconds on a long settle, and
/// short enough on a two-second one that the answer is not dominated by the
/// waiting.
fn poll_interval(deadline: Duration) -> Duration {
    (deadline / 4).clamp(Duration::from_millis(50), Duration::from_secs(2))
}

// ---------------------------------------------------------------------------
// 2 — green, audited
// ---------------------------------------------------------------------------

/// Diff every device's disk against the server, in both directions.
pub fn audit_trees(
    fleet: &Fleet,
    trees: &BTreeMap<String, LocalTree>,
    server_tree: &ServerTree,
    personality: &Personality,
    excluded: &BTreeMap<String, Vec<String>>,
) -> Verdict {
    let mut all: Vec<String> = Vec::new();
    for device in &fleet.devices {
        let Some(local) = trees.get(&device.name) else {
            all.push(format!("{} was not walked", device.name));
            continue;
        };
        let none = Vec::new();
        let differences = tree::diff(
            local,
            server_tree,
            personality,
            excluded.get(&device.name).unwrap_or(&none),
        );
        for difference in differences.iter().take(20) {
            all.push(format!("{}: {}", device.name, difference.describe()));
        }
        if differences.len() > 20 {
            all.push(format!(
                "{}: and {} further differences",
                device.name,
                differences.len() - 20
            ));
        }
    }
    if all.is_empty() {
        Verdict::pass(
            "audited-green",
            format!(
                "{} devices agree with the server across {} live entities",
                fleet.devices.len(),
                server_tree.live_paths().len()
            ),
        )
    } else {
        Verdict::fail("audited-green", all.join("; "))
    }
}

// ---------------------------------------------------------------------------
// 3 — no loss
// ---------------------------------------------------------------------------

/// Where a content was found. Every one of these is a legitimate place for the
/// last copy of something to be.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Found {
    OnADevice,
    ServerHead,
    ServerVersion,
    LocalTrash,
}

/// Everything the world can still produce.
pub struct Recoverable {
    pub on_devices: BTreeSet<String>,
    pub server: BTreeSet<String>,
    pub local_trash: BTreeSet<String>,
}

impl Recoverable {
    pub fn find(&self, sha256: &str) -> Option<Found> {
        if self.on_devices.contains(sha256) {
            Some(Found::OnADevice)
        } else if self.server.contains(sha256) {
            // Head and version history are collected together; both are the
            // server keeping its promise, and telling them apart would only
            // matter for a report nobody reads.
            Some(Found::ServerHead)
        } else if self.local_trash.contains(sha256) {
            Some(Found::LocalTrash)
        } else {
            None
        }
    }
}

/// Entry states that will never proceed without a person.
///
/// Everything else that is not `synced`/`out_of_scope` is work in flight, which
/// the daemon already reports by spinning rather than by raising an issue per
/// file.
pub const STUCK_STATES: &[&str] = &["unsyncable", "pending_key"];

/// How many files' version histories one settle will look through.
///
/// A ceiling rather than a target: the search stops as soon as everything it was
/// looking for turns up, and in a healthy settle it is never entered at all.
///
/// It was 500, chosen when the instance's rate limit was 1,000 requests an hour.
/// The limit is 500,000 now, and 500 was being hit with over a thousand contents
/// still unlooked-for — so the no-loss verdict was describing how far the search
/// got. A ceiling only does its job if reaching it is rare.
pub const VERSION_LOOKUP_BUDGET: usize = 5_000;

/// Committed contents that are not findable anywhere cheap.
///
/// The input to deciding whether the expensive search is worth doing, and
/// deliberately the same question `check_no_loss` asks — so a content the
/// verdict would complain about is exactly one this goes looking for.
pub fn unaccounted(records: &[Record], recoverable: &Recoverable) -> BTreeSet<String> {
    journal::all_committed_content(records)
        .into_iter()
        .filter(|sha| recoverable.find(sha).is_none())
        .collect()
}

/// For every content an actor committed, is it still findable?
///
/// Two questions, not one. The **last** committed content of every live path is
/// the one whose loss a user would notice, so it is reported first and named.
/// Every content the actors **ever** committed is the stronger claim, and it
/// holds because the soak instance keeps all versions on purpose — that is what
/// makes the server an oracle rather than merely the current state.
pub fn check_no_loss(
    records: &[Record],
    recoverable: &Recoverable,
    previously_on_server: &BTreeSet<String>,
) -> (Verdict, Losses) {
    let latest = journal::last_committed(records);
    let mut lost_live: Vec<String> = Vec::new();
    for (path, claim) in &latest {
        if recoverable.find(&claim.sha256).is_none() {
            lost_live.push(format!(
                "{path} (last written by {} at {}, sha {})",
                claim.actor,
                claim.ts_ms,
                &claim.sha256[..std::cmp::min(12, claim.sha256.len())]
            ));
        }
    }

    // The stronger half, and it is deliberately narrower than "every content an
    // actor ever wrote".
    //
    // A local write that was replaced at the same path seconds later, before any
    // client had a chance to upload it, is not something sync lost — it is a
    // file the user overwrote, and no sync client on earth captures every
    // intermediate save. Asserting otherwise produced three thousand
    // "violations" in one segment, every one of them the rig complaining about
    // its own actors typing quickly.
    //
    // What the server **was observed to hold** is a different matter entirely:
    // once it has taken a content, it promised to keep it, and this instance
    // keeps every version on purpose. That promise is what gets checked.
    let lost_history: Vec<String> = previously_on_server
        .iter()
        .filter(|sha| recoverable.find(sha).is_none())
        .filter(|sha| !latest.values().any(|c| &&c.sha256 == sha))
        .map(|sha| sha[..std::cmp::min(12, sha.len())].to_string())
        .collect();

    if lost_live.is_empty() && lost_history.is_empty() {
        // The historical half is vacuous on a campaign's first settle: there is
        // no earlier observation of the server to hold it to. Said out loud for
        // the same reason assertion 4 says it — "all 0 contents are still
        // there" reads as a check that ran and found nothing wrong, when in fact
        // no check ran at all.
        let history = if previously_on_server.is_empty() {
            "no earlier settle to hold the server to yet".to_string()
        } else {
            format!(
                "all {} contents the server had taken are still there",
                previously_on_server.len()
            )
        };
        return (
            Verdict::pass(
                "no-loss",
                format!("{} live paths findable; {history}", latest.len()),
            ),
            Losses::default(),
        );
    }

    let mut detail = Vec::new();
    if !lost_live.is_empty() {
        detail.push(format!(
            "{} committed file(s) are nowhere: {}",
            lost_live.len(),
            lost_live
                .iter()
                .take(10)
                .cloned()
                .collect::<Vec<_>>()
                .join(", ")
        ));
    }
    if !lost_history.is_empty() {
        detail.push(format!(
            "{} content(s) the server had taken have disappeared from it: {}",
            lost_history.len(),
            lost_history
                .iter()
                .take(10)
                .cloned()
                .collect::<Vec<_>>()
                .join(", ")
        ));
    }
    (
        Verdict::fail("no-loss", detail.join("; ")),
        Losses {
            live: lost_live,
            history: lost_history,
        },
    )
}

// ---------------------------------------------------------------------------
// 4 — ciphertext never materializes
// ---------------------------------------------------------------------------

/// No device holds bytes only the server was ever meant to see, and no encrypted
/// entity has quietly gone missing from the client's view.
///
/// When there are no encrypted entities at all, this **says so** rather than
/// passing silently. A vacuous pass reported as a pass is how a campaign runs
/// for a week with its encrypted lane switched off and nobody notices.
pub fn check_no_ciphertext(
    server_tree: &ServerTree,
    trees: &BTreeMap<String, LocalTree>,
    statuses: &BTreeMap<String, Option<Status>>,
) -> Verdict {
    let encrypted = server_tree.encrypted();
    if encrypted.is_empty() {
        return Verdict::pass(
            "no-ciphertext",
            "vacuous — the server holds no encrypted entities this segment",
        );
    }

    let mut problems = Vec::new();
    let ciphertexts: BTreeSet<String> = encrypted.iter().filter_map(|e| e.sha256.clone()).collect();
    for (device, local) in trees {
        for sha in &ciphertexts {
            if local.holds(sha) {
                problems.push(format!(
                    "{device} holds ciphertext {} on disk",
                    &sha[..std::cmp::min(12, sha.len())]
                ));
            }
        }
    }

    // The other half: an encrypted file a device cannot open must be *visible*
    // as such. Silently absent is the failure — the user is entitled to know
    // there is something there they cannot read.
    for (device, status) in statuses {
        let Some(status) = status else { continue };
        let surfaced = status.waiting_for_keys
            + status
                .issues
                .iter()
                .filter(|i| i.kind == "pending_key" || i.detail.contains("Encrypted"))
                .count() as u64;
        if surfaced == 0 {
            problems.push(format!(
                "{device} surfaces nothing for {} encrypted entities on the server",
                encrypted.len()
            ));
        }
    }

    if problems.is_empty() {
        Verdict::pass(
            "no-ciphertext",
            format!(
                "{} encrypted entities, none materialized, all surfaced",
                encrypted.len()
            ),
        )
    } else {
        Verdict::fail("no-ciphertext", problems.join("; "))
    }
}

// ---------------------------------------------------------------------------
// 5 — issues honesty
// ---------------------------------------------------------------------------

/// Every entry that is not finished has a surfaced reason with its name on it.
pub fn check_issues_honest(statuses: &BTreeMap<String, Option<Status>>) -> Verdict {
    let mut problems = Vec::new();
    let mut accounted = 0u64;
    for (device, status) in statuses {
        let Some(status) = status else {
            problems.push(format!(
                "{device} did not answer, so nothing can be checked"
            ));
            continue;
        };
        let unsettled = status.stuck_entries();
        // Waiting for a key is deliberately not one issue per file — a laptop
        // linked without encrypted folders can be looking at a thousand, and a
        // thousand identical alerts would bury everything that needs a person.
        // It is accounted for by the device-level count instead.
        // The true count, not the length of the capped list the answer carried.
        let explained = status.issues_total + status.waiting_for_keys;
        if unsettled > explained {
            problems.push(format!(
                "{device}: {unsettled} entries stuck but only {explained} surfaced ({})",
                status
                    .entries
                    .iter()
                    .filter(|(k, _)| STUCK_STATES.contains(&k.as_str()))
                    .map(|(k, v)| format!("{k}={v}"))
                    .collect::<Vec<_>>()
                    .join(" ")
            ));
        }
        accounted += explained;
    }
    if problems.is_empty() {
        Verdict::pass(
            "issues-honest",
            format!("every unfinished entry is surfaced ({accounted} across the fleet)"),
        )
    } else {
        Verdict::fail("issues-honest", problems.join("; "))
    }
}

// ---------------------------------------------------------------------------
// 6 — leak watch
// ---------------------------------------------------------------------------

/// Sample a device's resource use. Recorded every settle; the trend is what
/// matters, not the number.
pub fn sample(device: &Device, status: Option<&Status>) -> Sample {
    let (spool_files, spool_bytes) = dir_size(&device.spool());
    Sample {
        device: device.name.clone(),
        rss_kb: daemon_rss_kb(device).unwrap_or(0),
        fd_count: daemon_fd_count(device).unwrap_or(0),
        spool_files,
        spool_bytes,
        store_bytes: std::fs::metadata(device.state_db())
            .map(|m| m.len())
            .unwrap_or(0),
        pending_ops: status.map(|s| s.pending_ops).unwrap_or(0),
        tracked: status.map(|s| s.tracked).unwrap_or(0),
    }
}

/// The most memory a healthy client spends on one newly tracked entity, in
/// bytes, with room to spare.
///
/// Measured at about 5 kB and flat across an order of magnitude
/// (`jd-sim/tests/leak.rs`). The ceiling sits an order of magnitude above that,
/// because the job here is to tell "the tree grew" from "memory went somewhere
/// the tree cannot explain", not to police an allocator.
const MEMORY_PER_ENTITY_CEILING: u64 = 64 * 1024;

/// Has anything grown monotonically across every settle in the window?
///
/// Monotonic across the whole window rather than "bigger than it was", because a
/// store that grows with the tree is doing its job. What is not fine is a number
/// that has never once come down over a day of storms and settles. That is the
/// whole test for file descriptors and spool files, which have no reason to
/// scale with the tree.
///
/// **Resident memory is priced, not counted.** A client holds a bounded amount
/// of memory per tracked entity, and a campaign only ever adds files, so RSS
/// rises at every settle in a perfectly healthy run — which is what it did in
/// runs 21, 25, 26, 28 and 29 before anyone measured that 500 passes over an
/// unchanging tree move it by nothing at all. So RSS is reported only when it
/// rose at every settle *and* every increment bought more than
/// [`MEMORY_PER_ENTITY_CEILING`] per newly tracked entity.
///
/// A ceiling rather than a trend, because a steady leak has a perfectly flat
/// cost; requiring the cost to *rise* would miss the plainest case there is.
/// The cost is measured on increments rather than as `rss / tracked`, because
/// the latter is dominated by the process's fixed baseline and falls as the
/// tree grows. A settle that added memory but no entities divides by one, so it
/// scores as its whole increment — the sharpest form of the signal, not an
/// exclusion.
pub fn check_leaks(history: &[Vec<Sample>], window: usize) -> Verdict {
    if history.len() < window {
        return Verdict::pass(
            "leak-watch",
            format!(
                "{} of {window} settles sampled — not yet enough to call a trend",
                history.len()
            ),
        );
    }
    let recent = &history[history.len() - window..];
    let mut growing = Vec::new();
    let devices: BTreeSet<String> = recent
        .iter()
        .flat_map(|s| s.iter().map(|x| x.device.clone()))
        .collect();

    for device in devices {
        let series: Vec<&Sample> = recent
            .iter()
            .filter_map(|settle| settle.iter().find(|s| s.device == device))
            .collect();
        if series.len() < window {
            continue;
        }
        // Memory bought by each newly tracked entity, settle over settle.
        //
        // Deltas rather than rss/tracked, because a process carries a fixed
        // baseline of about 16 MB: dividing the total by the entity count is
        // dominated by that baseline, falls as the tree grows, and would hide
        // the very thing this is looking for. The increments have no baseline
        // in them at all.
        //
        // A settle that added no entities but did add memory is the sharpest
        // form of the signal, so it scores as the whole increment rather than
        // being skipped.
        let cost: Vec<u64> = series
            .windows(2)
            .map(|w| {
                let d_rss = w[1].rss_kb.saturating_sub(w[0].rss_kb);
                let d_tracked = w[1].tracked.saturating_sub(w[0].tracked);
                d_rss * 1024 / std::cmp::max(d_tracked, 1)
            })
            .collect();
        let rss_climbed = series.iter().all(|s| s.rss_kb > 0)
            && series.windows(2).all(|w| w[1].rss_kb > w[0].rss_kb);
        // Every settle bought memory the tree cannot account for. Not "the cost
        // rose" — a steady leak has a perfectly flat cost — but "the cost was
        // never plausible".
        let unexplained =
            rss_climbed && !cost.is_empty() && cost.iter().all(|&c| c > MEMORY_PER_ENTITY_CEILING);
        for (label, values) in [
            (
                "rss, and the tree does not account for it — bytes per newly tracked entity",
                if unexplained { cost } else { Vec::new() },
            ),
            ("fds", series.iter().map(|s| s.fd_count).collect::<Vec<_>>()),
            (
                "spool files",
                series.iter().map(|s| s.spool_files).collect::<Vec<_>>(),
            ),
        ] {
            // `!is_empty` matters: every `all` below is vacuously true on an
            // empty series, so a metric deliberately not flagged this round
            // would otherwise report itself as a leak.
            //
            // The rss series arrives already judged and is passed through; fds
            // and spool files have no reason to scale with the tree, so for
            // those a rise at every settle is the whole test.
            let already_judged = label.starts_with("rss");
            if !values.is_empty()
                && values.iter().all(|&v| v > 0)
                && (already_judged || values.windows(2).all(|w| w[1] > w[0]))
            {
                growing.push(format!(
                    "{device} {label} rose every settle: {}",
                    values
                        .iter()
                        .map(|v| v.to_string())
                        .collect::<Vec<_>>()
                        .join(" → ")
                ));
            }
        }
    }

    if growing.is_empty() {
        // Not "nothing grew monotonically" — memory usually does, because a
        // campaign only ever adds files, and saying otherwise next to samples
        // that plainly climbed reads as an oracle that cannot see. What passed
        // is the judgement, not the absence of growth.
        Verdict::pass(
            "leak-watch",
            format!("no growth across {window} settles that the tree does not account for"),
        )
    } else {
        Verdict::fail("leak-watch", growing.join("; "))
    }
}

fn dir_size(dir: &Path) -> (u64, u64) {
    let mut files = 0;
    let mut bytes = 0;
    if let Ok(entries) = std::fs::read_dir(dir) {
        for entry in entries.flatten() {
            if let Ok(meta) = entry.metadata() {
                if meta.is_file() {
                    files += 1;
                    bytes += meta.len();
                }
            }
        }
    }
    (files, bytes)
}

/// The daemon's resident set, read from `/proc`.
///
/// Best effort: a device whose daemon is in a container this process cannot see
/// into reports zero, and a zero is excluded from the trend rather than treated
/// as a reading that went down.
fn daemon_rss_kb(device: &Device) -> Option<u64> {
    let pid = daemon_pid(device)?;
    let status = std::fs::read_to_string(format!("/proc/{pid}/status")).ok()?;
    for line in status.lines() {
        if let Some(rest) = line.strip_prefix("VmRSS:") {
            return rest.split_whitespace().next()?.parse().ok();
        }
    }
    None
}

fn daemon_fd_count(device: &Device) -> Option<u64> {
    let pid = daemon_pid(device)?;
    Some(std::fs::read_dir(format!("/proc/{pid}/fd")).ok()?.count() as u64)
}

/// Find the daemon by the home directory it was started with, which is the only
/// thing that tells two devices' daemons apart on one host.
fn daemon_pid(device: &Device) -> Option<u32> {
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

// ---------------------------------------------------------------------------
// The whole settle
// ---------------------------------------------------------------------------

/// Run all six.
///
/// Convergence runs first and the rest run **whether or not it passed**. A
/// device that is still working is exactly when a lost file is most likely, and
/// stopping at the first failure would hide it behind the stall.
#[allow(clippy::too_many_arguments)]
pub fn settle(
    fleet: &Fleet,
    api: &dyn DriveApi,
    records: &[Record],
    personality: &Personality,
    excluded: &BTreeMap<String, Vec<String>>,
    leak_history: &[Vec<Sample>],
    // Contents the server was seen holding in an earlier settle. Once it has
    // taken one, losing it is the server breaking its own promise.
    previously_on_server: &BTreeSet<String>,
    deadline: Duration,
    sleep: &dyn Fn(Duration),
) -> Verification {
    let (convergence, convergence_ms, statuses) = await_convergence(fleet, deadline, sleep);

    let mut trees = BTreeMap::new();
    let mut on_devices = BTreeSet::new();
    let mut local_trash = BTreeSet::new();
    for device in &fleet.devices {
        match tree::walk_local(&device.root, personality) {
            Ok(local) => {
                on_devices.extend(local.contents());
                trees.insert(device.name.clone(), local);
            }
            Err(e) => {
                trees.insert(device.name.clone(), LocalTree::default());
                eprintln!("warning: cannot walk {}: {e}", device.root.display());
            }
        }
        local_trash.extend(tree::device_trash_contents(device));
    }

    let mut verdicts = vec![convergence];

    let server_tree = match server::walk(api) {
        Ok(t) => t,
        Err(e) => {
            // Without the server there is no audit and no oracle. That is a
            // failure of the settle, not a reason to report the rest as green.
            verdicts.push(Verdict::fail(
                "audited-green",
                format!("the server could not be walked: {e}"),
            ));
            let samples = fleet
                .devices
                .iter()
                .map(|d| sample(d, statuses.get(&d.name).and_then(|s| s.as_ref())))
                .collect();
            return Verification {
                verdicts,
                samples,
                convergence_ms,
                server_contents: BTreeSet::new(),
                losses: Losses::default(),
            };
        }
    };

    verdicts.push(audit_trees(
        fleet,
        &trees,
        &server_tree,
        personality,
        excluded,
    ));

    // Heads first, which the index walk already paid for. Version history is
    // one API call per file and is only worth paying for if something is
    // genuinely unaccounted for — walking it every settle made the verifier the
    // heaviest client on the rig and exhausted the server's rate limit, which is
    // how this shortcut came to exist.
    let mut recoverable = Recoverable {
        on_devices,
        server: server_tree.head_contents(),
        local_trash,
    };
    let missing = unaccounted(records, &recoverable);
    let mut never_looked_for = 0usize;
    let mut blind = None;
    if !missing.is_empty() {
        match server::find_in_version_history(api, &server_tree, &missing, VERSION_LOOKUP_BUDGET) {
            Ok(search) => {
                let (found, asked) = (search.found, search.asked);
                // A version the server lists but will not identify is not a
                // version that does not hold the content. Told apart here
                // because the two are the same empty set, and reading one as
                // the other turns every superseded version into a lost file.
                if search.unidentified > 0 || search.unreadable > 0 {
                    blind = Some(format!(
                        "the server did not say what {} listed version(s) hold{}, so version \
                         history could not be searched and whether anything was lost is UNKNOWN — \
                         this is not a pass and not a loss list",
                        search.unidentified,
                        if search.unreadable > 0 {
                            format!(
                                " and {} file histor(ies) could not be read",
                                search.unreadable
                            )
                        } else {
                            String::new()
                        }
                    ));
                }
                if asked >= VERSION_LOOKUP_BUDGET && found.len() < missing.len() {
                    never_looked_for = missing.len() - found.len();
                    // Said out loud rather than reported as loss. A verifier that
                    // ran out of budget and then announced missing files would
                    // manufacture violations out of its own thrift.
                    eprintln!(
                        "warning: stopped looking through version history after {asked} files with \
                         {never_looked_for} content(s) still unaccounted for — the no-loss verdict \
                         below may be reporting the search rather than the truth"
                    );
                }
                recoverable.server.extend(found);
            }
            Err(e) => verdicts.push(Verdict::fail(
                "no-loss",
                format!("version history could not be read, so nothing can be cleared: {e}"),
            )),
        }
    }
    let (mut no_loss, losses) = check_no_loss(records, &recoverable, previously_on_server);
    if let Some(why) = blind {
        // Only when it would otherwise announce losses. A settle that found
        // everything found it, and the fact that some other file's history was
        // unreadable does not take that away.
        if !no_loss.ok {
            no_loss.detail = format!("{why} — what it would have reported: {}", no_loss.detail);
        }
    }
    if never_looked_for > 0 {
        // The warning above goes to stderr, which the evidence bundle does not
        // keep — so a truncated search reached the bundle looking like a clean
        // list of lost files, and was read that way days later. The caveat
        // belongs on the verdict itself, where it is read.
        no_loss.detail = format!(
            "SEARCH TRUNCATED at {VERSION_LOOKUP_BUDGET} files with {never_looked_for} \
             content(s) never looked for, so treat what follows as a floor and not a \
             measurement — {}",
            no_loss.detail
        );
    }
    verdicts.push(no_loss);

    verdicts.push(check_no_ciphertext(&server_tree, &trees, &statuses));
    verdicts.push(check_issues_honest(&statuses));

    let samples: Vec<Sample> = fleet
        .devices
        .iter()
        .map(|d| sample(d, statuses.get(&d.name).and_then(|s| s.as_ref())))
        .collect();
    let mut history = leak_history.to_vec();
    history.push(samples.clone());
    verdicts.push(check_leaks(&history, 6));

    Verification {
        verdicts,
        samples,
        convergence_ms,
        server_contents: server_tree.head_contents(),
        losses,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::journal::Record;
    use crate::server::Entity;
    use serde_json::json;

    fn commit(path: &str, sha: &str, op: &str) -> Record {
        Record::ActorCommit {
            seq: 1,
            actor: "device-a/office".into(),
            persona: "office".into(),
            op: op.into(),
            path: path.into(),
            sha256: Some(sha.into()),
            size: 10,
            mtime_ms: Some(1),
            ts_ms: 1,
        }
    }

    fn recoverable(devices: &[&str], server: &[&str], trash: &[&str]) -> Recoverable {
        Recoverable {
            on_devices: devices.iter().map(|s| s.to_string()).collect(),
            server: server.iter().map(|s| s.to_string()).collect(),
            local_trash: trash.iter().map(|s| s.to_string()).collect(),
        }
    }

    fn status(indicator: &str, entries: serde_json::Value, issues: serde_json::Value) -> Status {
        let json = json!({
            "indicator": indicator, "summary": "", "tracked": 0, "settled": 0,
            "pending_ops": 0, "waiting_for_keys": 0, "cursor": 0,
            "entries": entries, "issues": issues,
        });
        // Built through the same reader the rig uses, so a change to the
        // daemon's snapshot shape breaks these tests rather than sliding past.
        Status::from_json(&json)
    }

    #[test]
    fn a_content_still_on_a_device_is_not_lost() {
        let records = vec![commit("a.txt", "aa", "write")];
        let (verdict, _losses) =
            check_no_loss(&records, &recoverable(&["aa"], &[], &[]), &BTreeSet::new());
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn a_content_only_on_the_server_is_not_lost() {
        // The normal state for anything the user deleted locally, or that has
        // not reached a second device yet.
        let records = vec![commit("a.txt", "aa", "write")];
        assert!(
            check_no_loss(&records, &recoverable(&[], &["aa"], &[]), &BTreeSet::new())
                .0
                .ok
        );
    }

    #[test]
    fn a_content_only_in_a_local_trash_is_not_lost() {
        // The engine never unlinks, so the trash is where a delete it got wrong
        // is recoverable from.
        let records = vec![commit("a.txt", "aa", "write")];
        assert!(
            check_no_loss(&records, &recoverable(&[], &[], &["aa"]), &BTreeSet::new())
                .0
                .ok
        );
    }

    #[test]
    fn a_content_that_is_nowhere_is_a_violation_that_names_the_file() {
        // The finding this whole rig exists to produce. It has to say which
        // file, who wrote it and when, or nobody can investigate it.
        let records = vec![commit("Projects/Report.docx", "abcdef0123456789", "write")];
        let (verdict, _losses) =
            check_no_loss(&records, &recoverable(&[], &[], &[]), &BTreeSet::new());
        assert!(!verdict.ok);
        assert!(
            verdict.detail.contains("Projects/Report.docx"),
            "{}",
            verdict.detail
        );
        assert!(
            verdict.detail.contains("device-a/office"),
            "{}",
            verdict.detail
        );
    }

    #[test]
    fn a_content_the_server_took_may_not_then_disappear_from_it() {
        // The stronger half, and it turns entirely on whether the server ever
        // had the content. This instance keeps every version on purpose, so once
        // it has taken one, losing it is the server breaking its own promise.
        let records = vec![
            commit("a.txt", "first", "write"),
            commit("a.txt", "second", "write"),
        ];
        let taken: BTreeSet<String> = ["first".to_string()].into_iter().collect();

        let (gone, _losses) = check_no_loss(&records, &recoverable(&["second"], &[], &[]), &taken);
        assert!(!gone.ok);
        assert!(
            gone.detail.contains("disappeared from it"),
            "{}",
            gone.detail
        );

        let (still_there, _l2) =
            check_no_loss(&records, &recoverable(&["second"], &["first"], &[]), &taken);
        assert!(still_there.ok, "{}", still_there.detail);
    }

    #[test]
    fn the_first_settle_says_it_has_no_history_to_check_rather_than_checking_none() {
        // "all 0 contents the server had taken are still there" reads as a check
        // that ran and found nothing wrong. On a first settle no check ran.
        let records = vec![commit("a.txt", "aa", "write")];
        let (verdict, _losses) =
            check_no_loss(&records, &recoverable(&["aa"], &[], &[]), &BTreeSet::new());
        assert!(verdict.ok);
        assert!(
            verdict.detail.contains("no earlier settle"),
            "{}",
            verdict.detail
        );
        assert!(!verdict.detail.contains("all 0"), "{}", verdict.detail);
    }

    #[test]
    fn a_local_write_the_server_never_saw_is_not_loss_when_it_is_overwritten() {
        // A file the user saved and then saved over seconds later, before any
        // client could upload it, is a file the user overwrote — no sync client
        // on earth captures every intermediate save. Asserting otherwise produced
        // nearly three thousand false violations in a single segment, every one
        // of them the rig complaining about its own actors typing quickly.
        let records = vec![
            commit("a.txt", "first", "write"),
            commit("a.txt", "second", "write"),
        ];
        let (verdict, _losses) = check_no_loss(
            &records,
            &recoverable(&["second"], &[], &[]),
            &BTreeSet::new(),
        );
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn a_file_the_user_deleted_is_not_reported_as_lost() {
        // Otherwise every intentional delete fails the run.
        let records = vec![
            commit("a.txt", "aa", "write"),
            Record::ActorCommit {
                seq: 2,
                actor: "device-a/office".into(),
                persona: "office".into(),
                op: "remove".into(),
                path: "a.txt".into(),
                sha256: None,
                size: 0,
                mtime_ms: None,
                ts_ms: 2,
            },
        ];
        // The content still has to be findable historically, but no live path
        // claims it.
        let (verdict, _losses) =
            check_no_loss(&records, &recoverable(&[], &["aa"], &[]), &BTreeSet::new());
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn an_entry_that_will_never_proceed_and_says_nothing_is_dishonest() {
        // "Never silently stop" made checkable: three files that cannot be saved
        // on this disk, with nothing said about any of them, is the exact
        // failure.
        let mut statuses = BTreeMap::new();
        statuses.insert(
            "device-a".to_string(),
            Some(status(
                "green",
                json!({"synced": 10, "unsyncable": 3}),
                json!([]),
            )),
        );
        let verdict = check_issues_honest(&statuses);
        assert!(!verdict.ok);
        assert!(
            verdict.detail.contains("unsyncable=3"),
            "{}",
            verdict.detail
        );
    }

    #[test]
    fn work_still_in_flight_does_not_need_an_issue_raised_against_it() {
        // A queue that is draining is not a problem, and demanding a written
        // explanation per file in it would mean an alert per file in a healthy
        // client. The daemon already reports this by spinning. Two hundred
        // pending uploads mid-storm is what a working client looks like, and the
        // rig read it as the daemon hiding two hundred things.
        let mut statuses = BTreeMap::new();
        statuses.insert(
            "device-a".to_string(),
            Some(status(
                "working",
                json!({"synced": 10, "pending_upload": 248, "pending_download": 333}),
                json!([]),
            )),
        );
        assert!(check_issues_honest(&statuses).ok);
    }

    #[test]
    fn honesty_is_measured_against_the_true_issue_count_not_the_capped_list() {
        // The daemon caps the list it sends so the answer stays readable however
        // bad things get. A verifier counting the array would accuse it of
        // hiding things at exactly the moment it had most carefully said how
        // many there were.
        let mut raw = json!({
            "indicator": "attention", "summary": "", "tracked": 0, "settled": 0,
            "pending_ops": 0, "waiting_for_keys": 0, "cursor": 0,
            "entries": {"synced": 10, "unsyncable": 400},
            "issues_total": 400,
            "issues": [],
        });
        raw["issues"] = json!((0..50)
            .map(|i| json!({"id": i, "kind": "unsyncable", "summary": "s", "detail": "d"}))
            .collect::<Vec<_>>());

        let mut statuses = BTreeMap::new();
        statuses.insert("device-a".to_string(), Some(Status::from_json(&raw)));
        let verdict = check_issues_honest(&statuses);
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn an_unfinished_entry_with_an_issue_against_it_is_honest() {
        let mut statuses = BTreeMap::new();
        statuses.insert(
            "device-a".to_string(),
            Some(status(
                "attention",
                json!({"synced": 10, "unsyncable": 1}),
                json!([{"id": 1, "kind": "unsyncable", "summary": "clash", "detail": "CaseClash"}]),
            )),
        );
        assert!(check_issues_honest(&statuses).ok);
    }

    #[test]
    fn a_device_that_did_not_answer_fails_the_honesty_check_rather_than_passing_it() {
        // Absence must never read as "nothing wrong".
        let mut statuses = BTreeMap::new();
        statuses.insert("device-a".to_string(), None);
        assert!(!check_issues_honest(&statuses).ok);
    }

    #[test]
    fn no_encrypted_entities_is_a_pass_that_says_it_is_vacuous() {
        // A campaign running for a week with its encrypted lane switched off,
        // reporting six green assertions, is the failure this wording prevents.
        let verdict =
            check_no_ciphertext(&ServerTree::default(), &BTreeMap::new(), &BTreeMap::new());
        assert!(verdict.ok);
        assert!(verdict.detail.contains("vacuous"), "{}", verdict.detail);
    }

    #[test]
    fn ciphertext_found_on_a_device_is_a_violation() {
        let mut server_tree = ServerTree::default();
        server_tree.files.insert(
            1,
            Entity {
                id: 1,
                is_folder: false,
                name: "secret".into(),
                parent_id: None,
                deleted: false,
                encrypted: true,
                sha256: Some("cipherhash".into()),
                size: 10,
            },
        );
        let mut trees = BTreeMap::new();
        let mut local = LocalTree::default();
        local.entries.insert(
            "leak.bin".into(),
            crate::tree::Local {
                path: "leak.bin".into(),
                is_dir: false,
                sha256: Some("cipherhash".into()),
                size: 10,
            },
        );
        trees.insert("device-a".to_string(), local);

        let verdict = check_no_ciphertext(&server_tree, &trees, &BTreeMap::new());
        assert!(!verdict.ok);
        assert!(verdict.detail.contains("ciphertext"), "{}", verdict.detail);
    }

    #[test]
    fn an_encrypted_file_a_device_says_nothing_about_is_a_violation() {
        // Silently absent is the failure — the user is entitled to know there is
        // something there they cannot read.
        let mut server_tree = ServerTree::default();
        server_tree.files.insert(
            1,
            Entity {
                id: 1,
                is_folder: false,
                name: "secret".into(),
                parent_id: None,
                deleted: false,
                encrypted: true,
                sha256: Some("cipherhash".into()),
                size: 10,
            },
        );
        let mut statuses = BTreeMap::new();
        statuses.insert(
            "device-a".to_string(),
            Some(status("green", json!({"synced": 5}), json!([]))),
        );
        let verdict = check_no_ciphertext(&server_tree, &BTreeMap::new(), &statuses);
        assert!(!verdict.ok);
        assert!(
            verdict.detail.contains("surfaces nothing"),
            "{}",
            verdict.detail
        );
    }

    fn sample_of(device: &str, rss: u64, fds: u64, spool: u64) -> Sample {
        // A fixed tree, so these read as "RSS moved and the tree did not" —
        // which is the only shape that should ever be called a leak.
        sample_tracking(device, rss, fds, spool, 1_000)
    }

    fn sample_tracking(device: &str, rss: u64, fds: u64, spool: u64, tracked: u64) -> Sample {
        Sample {
            device: device.into(),
            rss_kb: rss,
            fd_count: fds,
            spool_files: spool,
            spool_bytes: 0,
            store_bytes: 0,
            pending_ops: 0,
            tracked,
        }
    }

    #[test]
    fn memory_that_grows_with_the_tree_is_not_a_leak() {
        // The shape of every real campaign: a storm adds files, the client
        // tracks them, and RSS rises at every single settle because it holds a
        // bounded amount per entity. Raw RSS called this a leak in runs 21, 25,
        // 26, 28 and 29; 500 passes over an unchanging tree move it by nothing.
        // device-a's real series from run 29, with the entity counts that
        // produced it: 3.6 kB per new entity, against a measured 5 and a
        // ceiling of 64.
        let rss = [17_112, 17_692, 18_548, 18_784, 19_152, 19_652];
        let tracked = [120, 300, 480, 600, 700, 817];
        let history: Vec<Vec<Sample>> = rss
            .iter()
            .zip(tracked.iter())
            .map(|(&r, &t)| vec![sample_tracking("device-a", r, 40, 0, t)])
            .collect();
        let verdict = check_leaks(&history, 6);
        assert!(
            verdict.ok,
            "memory rising in step with the tree is a working client: {}",
            verdict.detail
        );
    }

    #[test]
    fn memory_outrunning_the_tree_is_a_leak() {
        // The same rising RSS, but the tree barely moves — so the cost per
        // entity climbs every settle, and that is memory nothing accounts for.
        let rss = [17_112, 18_692, 20_548, 22_784, 25_152, 28_652];
        let tracked = [800, 802, 805, 807, 810, 812];
        let history: Vec<Vec<Sample>> = rss
            .iter()
            .zip(tracked.iter())
            .map(|(&r, &t)| vec![sample_tracking("device-a", r, 40, 0, t)])
            .collect();
        let verdict = check_leaks(&history, 6);
        assert!(!verdict.ok, "{}", verdict.detail);
        assert!(
            verdict.detail.contains("per newly tracked entity"),
            "{}",
            verdict.detail
        );
    }

    #[test]
    fn a_number_that_rises_at_every_single_settle_is_a_leak() {
        let history: Vec<Vec<Sample>> = (0..6)
            .map(|i| vec![sample_of("device-a", 10_000 + i * 500, 40, 0)])
            .collect();
        let verdict = check_leaks(&history, 6);
        assert!(!verdict.ok);
        assert!(verdict.detail.contains("rss"), "{}", verdict.detail);
    }

    #[test]
    fn a_number_that_goes_up_and_down_is_a_working_client() {
        // A store that grows with the tree is doing its job. Only a number that
        // has never once come down is worth waking somebody for.
        let rss = [10_000, 12_000, 11_000, 13_000, 12_500, 14_000];
        let history: Vec<Vec<Sample>> = rss
            .iter()
            .map(|&r| vec![sample_of("device-a", r, 40, 0)])
            .collect();
        assert!(check_leaks(&history, 6).ok);
    }

    #[test]
    fn a_short_history_does_not_call_a_trend() {
        let history: Vec<Vec<Sample>> = (0..3)
            .map(|i| vec![sample_of("device-a", 10_000 + i * 500, 40, 0)])
            .collect();
        let verdict = check_leaks(&history, 6);
        assert!(verdict.ok);
        assert!(
            verdict.detail.contains("not yet enough"),
            "{}",
            verdict.detail
        );
    }

    #[test]
    fn a_reading_that_could_not_be_taken_is_excluded_rather_than_read_as_zero() {
        // A daemon inside a container this process cannot see into reports zero.
        // Treating that as a measurement would make every trend look flat.
        let history: Vec<Vec<Sample>> = (0..6)
            .map(|i| vec![sample_of("device-a", 0, 40 + i, 0)])
            .collect();
        let verdict = check_leaks(&history, 6);
        assert!(verdict.detail.contains("fds"), "{}", verdict.detail);
        assert!(!verdict.detail.contains("rss"), "{}", verdict.detail);
    }

    #[test]
    fn the_settle_poll_scales_with_the_deadline_it_was_given() {
        // A two-second settle that slept for two seconds between checks would
        // report a stall it never gave the client a chance to avoid; a
        // fifteen-minute one polling every 50ms would spend the settle asking.
        assert_eq!(
            poll_interval(Duration::from_secs(900)),
            Duration::from_secs(2)
        );
        assert_eq!(
            poll_interval(Duration::from_secs(4)),
            Duration::from_secs(1)
        );
        assert_eq!(
            poll_interval(Duration::from_millis(100)),
            Duration::from_millis(50)
        );
        // And never zero, which would spin a core flat out on a device that is
        // never going to answer.
        assert!(poll_interval(Duration::ZERO) > Duration::ZERO);
    }

    #[test]
    fn a_verification_reports_every_failure_rather_than_the_first() {
        let v = Verification {
            verdicts: vec![
                Verdict::fail("convergence", "still working"),
                Verdict::pass("audited-green", "fine"),
                Verdict::fail("no-loss", "a file is gone"),
            ],
            samples: Vec::new(),
            convergence_ms: BTreeMap::new(),
            server_contents: BTreeSet::new(),
            losses: Default::default(),
        };
        assert!(v.violated());
        assert_eq!(v.failures().len(), 2);
    }

    #[test]
    fn a_device_that_picks_work_back_up_stops_counting_as_settled() {
        let mut settled = BTreeMap::new();
        note_reading(&mut settled, "device-a", true, 1_000);
        note_reading(&mut settled, "device-b", true, 2_000);
        assert_eq!(settled.len(), 2, "both quiet at once is a converged fleet");

        // device-b hears about something device-a uploaded and starts again.
        note_reading(&mut settled, "device-b", false, 3_000);
        assert_eq!(
            settled.len(),
            1,
            "a fleet with one device working has not converged, whatever it \
             managed a moment ago"
        );
    }

    #[test]
    fn how_long_a_device_took_is_measured_from_the_first_time_it_went_quiet() {
        let mut settled = BTreeMap::new();
        note_reading(&mut settled, "device-a", true, 1_000);
        note_reading(&mut settled, "device-a", true, 9_000);
        assert_eq!(
            settled.get("device-a"),
            Some(&1_000),
            "later confirmations must not inflate the reported settle time"
        );
    }

    #[test]
    fn a_device_that_goes_quiet_again_is_timed_from_when_it_actually_did() {
        let mut settled = BTreeMap::new();
        note_reading(&mut settled, "device-a", true, 1_000);
        note_reading(&mut settled, "device-a", false, 2_000);
        note_reading(&mut settled, "device-a", true, 5_000);
        assert_eq!(
            settled.get("device-a"),
            Some(&5_000),
            "the earlier quiet spell was not the settle; the run went on past it"
        );
    }

}
