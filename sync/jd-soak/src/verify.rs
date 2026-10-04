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
//! 7. **Swaps keep their histories apart.** No file on the server holds both
//!    bodies of a swap the user made -- a trade read as two edits mixes two
//!    files' histories while every tree still agrees.
//! 8. **Custody.** A content stands in the folder the user put it in, judged
//!    by that folder's directory inode on the device that put it there.
//! 9. **The stores hold together.** No record whose parent is not in its
//!    store, and no directory held by two folder records.
//!
//! And one honesty check beside them: how many no-loss claims named a path
//! nothing is at and so were not judged, which past a threshold is a run that
//! cannot say much about loss at all.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;
use std::time::{Duration, Instant};

use jd_proto::DriveApi;
use jd_vfs::Personality;

use crate::actor::now_ms;
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
    /// How much of the no-loss check was a judgement.
    pub coverage: Coverage,
}

/// The live claims the no-loss check had, and how many of them it could not
/// judge because they named a path nothing is at.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Coverage {
    pub claims: usize,
    pub at_dead_paths: usize,
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
///
/// **And it has to stay settled for a whole poll.** A device is quiet whenever
/// its queue is empty, which includes being quiet because it has not yet ASKED
/// what changed: there is no push channel, so another device's work is invisible
/// to it for up to `poll_seconds`. Returning on the first simultaneously-quiet
/// reading therefore measured a fleet that had merely not heard the news, and
/// the audit ran in the gap before it did.
///
/// The simulator has always required two consecutive quiet rounds for exactly
/// this reason. The rig required one instant, and paid for it in transient
/// audited-green failures: a folder trashed on the server forty seconds before
/// the audit, by the very device that had already reported itself finished.
pub fn await_convergence(
    fleet: &Fleet,
    api: &dyn DriveApi,
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
    // How long the whole fleet has to have been quiet before the quiet counts.
    // One full poll plus a margin: that is exactly how long a device can look
    // finished purely because it has not yet asked the server what changed.
    let confirm = confirmation_window(fleet.poll_seconds, deadline);
    let mut quiet_since: Option<Instant> = None;
    // The wall-clock moment this settle began. A device has to have completed a
    // pass AFTER it, or its quiet is about the world as it was during the storm.
    let settle_began_ms = now_ms();

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
        // Any device busy resets the clock: the window has to be unbroken.
        if settled_at.len() < fleet.devices.len() {
            quiet_since = None;
        } else if quiet_since.is_none() {
            quiet_since = Some(Instant::now());
        }
        let mut held = quiet_since.is_some_and(|t| t.elapsed() >= confirm);
        // Quiet is not the same as up to date, and only the server can tell the
        // two apart. A daemon says `pending_ops: 0` the instant its queue
        // drains -- including while a deletion made forty seconds ago still
        // sits unread in the change feed, because a change it has not been told
        // about is not work it knows it has. Believed on the strength of the
        // queue alone, the audit then walks a disk that is simply behind and
        // reports every not-yet-applied change as a disagreement. Both devices
        // produce the identical list, because it is one lag, not two faults.
        //
        // So the window is a floor, not the proof. The proof is each device's
        // own cursor having reached the end of the feed. Asked only once the
        // window has already held, so a settle pays for it once rather than
        // every two seconds.
        // Has every device actually LOOKED since the storm stopped? A daemon
        // reports an empty queue whether or not it has scanned the disk since
        // the user last touched it -- the watcher can miss a deep removal made
        // by another account, and the full walk then waits out the poll
        // interval. Run 107 settled on that: convergence was declared, and
        // sixty-two seconds later the device noticed a folder had gone and
        // trashed it on the server, by which time the audit had already
        // compared a disk against a server that still had it.
        if held
            && !fleet.devices.iter().all(|d| {
                has_looked_since(last.get(&d.name).and_then(|s| s.as_ref()), settle_began_ms)
            })
        {
            held = false;
        }
        if held {
            for device in &fleet.devices {
                let Some(Some(status)) = last.get(&device.name) else {
                    continue;
                };
                match server::changes_pending(api, status.cursor) {
                    Ok(false) => {}
                    Ok(true) => {
                        // Behind. Ask it to look now rather than waiting out
                        // its poll interval, and stop calling this quiet.
                        control::sync_now(&device.control_file());
                        quiet_since = None;
                        held = false;
                        break;
                    }
                    // The server could not be asked. That is not evidence
                    // either way, and refusing to settle on it would turn a
                    // transient into a convergence failure. The window stands
                    // on its own, as it did before there was anything better.
                    Err(_) => {}
                }
            }
        }
        if settled_at.len() == fleet.devices.len() && held {
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
/// How long the fleet must stay quiet before the quiet is believed.
///
/// One change-feed poll is the floor, because a device that has not polled yet
/// is indistinguishable from one with nothing to do; the margin covers the pass
/// that follows the poll actually finding something. Capped at a third of the
/// deadline so a short settle still gets to run rather than spending its whole
/// budget confirming.
fn confirmation_window(poll_seconds: u64, deadline: Duration) -> Duration {
    let want = Duration::from_secs(poll_seconds).saturating_add(Duration::from_secs(5));
    want.min(deadline / 3)
}

/// Has this device completed a pass since `since_ms`?
///
/// An empty queue says nothing about whether the disk has been looked at. The
/// watcher can miss a removal made by another account -- on the rig the actors
/// run as root inside a daemon's tree -- and the full walk then waits out the
/// poll interval. A device in that state answers every question correctly and
/// is describing the world as it was during the storm.
///
/// No pass at all is not looking. A device that has only ever passed before the
/// settle began has not looked either.
fn has_looked_since(status: Option<&Status>, since_ms: u64) -> bool {
    status.is_some_and(|s| s.last_pass_ms.is_some_and(|ms| ms >= since_ms))
}

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

/// Where a claim would be standing on a disk, in the comparison form the device
/// trees are keyed by.
///
/// A claim's path is relative to the persona's workspace, and which workspace
/// that is depends on the persona: `sqlite-app` and `browser` get one per device
/// (two programs writing one database is a corrupt file, not a sync bug), and
/// everyone else shares. `orchestrate::run` decides that, and this has to agree
/// with it — disagree and every claim looks dead, which would turn the guard
/// below into a switch that quietly disables half the oracle.
fn standing_key(claim: &journal::Committed, personality: &Personality) -> String {
    let device = claim.actor.split('/').next().unwrap_or_default();
    let workspace = if claim.persona == "sqlite-app" || claim.persona == "browser" {
        format!("{device}-{}", claim.persona)
    } else {
        format!("Shared-{}", claim.persona)
    };
    tree::key_for(&format!("{workspace}/{}", claim.path), personality)
}

/// Every path a file is standing at, across the whole fleet, keyed for
/// comparison. Directories are left out: a claim is about a file.
pub fn standing_paths(trees: &BTreeMap<String, LocalTree>) -> BTreeSet<String> {
    trees
        .values()
        .flat_map(|t| t.entries.iter())
        .filter(|(_, e)| !e.is_dir)
        .map(|(key, _)| key.clone())
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
    standing: &BTreeSet<String>,
    personality: &Personality,
) -> (Verdict, Losses, Coverage) {
    let latest = journal::last_committed(records);
    let mut lost_live: Vec<String> = Vec::new();
    let mut at_dead_paths: Vec<String> = Vec::new();
    for (path, claim) in &latest {
        if recoverable.find(&claim.sha256).is_some() {
            continue;
        }
        // Is anything actually standing where this claim says it is?
        //
        // A claim is keyed by its path STRING, and a claim's ancestry can be
        // renamed by either device at any moment. `last_committed` re-keys a
        // claim through one rename per actor, and the rig's folder names
        // accumulate suffixes all run — so a claim whose ancestry was renamed
        // twice keeps a key that names a path nothing is at, while the physical
        // file went on being overwritten under its new name. That is not a loss.
        // It is this oracle failing to follow the file.
        //
        // Runs 218, 255 and 265 were each reported as data loss and each cost a
        // session; all three claimed a path that appears ZERO times in the
        // frozen trees, while the same physical file was overwritten seconds
        // later at the renamed path. Sweeping aliases was tried twice to fix the
        // re-keying itself and made the oracle measurably worse (see
        // `journal::Aliases`), because a claim models one path and two diverging
        // devices genuinely disagree about where a file is.
        //
        // So the rule is the check's own definition, enforced: `lost_live` is
        // the last committed content of every LIVE path, and a path nothing is
        // at is not one. Dropped rather than reported — and COUNTED, because a
        // silently narrowed oracle is how "no-loss green" comes to mean less
        // than it reads as.
        //
        // The stronger half is untouched by this. Any content the server was
        // observed to take is still owed back whatever happened to its path.
        if !standing.contains(&standing_key(claim, personality)) {
            at_dead_paths.push(path.clone());
            continue;
        }
        lost_live.push(format!(
            "{path} (last written by {} at {}, sha {})",
            claim.actor,
            claim.ts_ms,
            &claim.sha256[..std::cmp::min(12, claim.sha256.len())]
        ));
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

    // Never silent. A claim this oracle could not follow is a hole in it, and a
    // run that stops saying how many there were is a run that has stopped
    // knowing how much "no-loss green" is worth.
    let stale = if at_dead_paths.is_empty() {
        String::new()
    } else {
        format!(
            "; {} unaccounted claim(s) named a path nothing is at and were not judged: {}",
            at_dead_paths.len(),
            at_dead_paths
                .iter()
                .take(5)
                .cloned()
                .collect::<Vec<_>>()
                .join(", ")
        )
    };

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
                format!("{} live paths findable; {history}{}", latest.len(), stale),
            ),
            Losses::default(),
            Coverage { claims: latest.len(), at_dead_paths: at_dead_paths.len() },
        );
    }

    let mut detail = Vec::new();
    if !at_dead_paths.is_empty() {
        detail.push(stale.trim_start_matches("; ").to_string());
    }
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
        Coverage { claims: latest.len(), at_dead_paths: at_dead_paths.len() },
    )
}

/// Claims the no-loss check could not judge, past which a run cannot speak to
/// loss: more than this many, and more than a fifth of all live claims.
pub const DEAD_PATH_ALARM_MIN: usize = 10;

/// The count `check_no_loss` drops, as a verdict of its own.
///
/// A claim keyed by a path nothing is at is not judged (see `check_no_loss`
/// for why), and that was one clause in a detail string a reader skims past.
/// A handful is the rig's renames outrunning the claim keys; a fifth of every
/// claim is a run whose "no-loss green" says almost nothing, and that is a
/// finding, not a footnote.
pub fn check_no_loss_coverage(coverage: Coverage) -> Verdict {
    let detail = format!(
        "judged {} of {} live claim(s); {} named a path nothing is at",
        coverage.claims - coverage.at_dead_paths,
        coverage.claims,
        coverage.at_dead_paths
    );
    if coverage.at_dead_paths > DEAD_PATH_ALARM_MIN && coverage.at_dead_paths * 5 > coverage.claims {
        Verdict::fail("no-loss-coverage", format!("{detail}: the no-loss verdict cannot vouch for this run"))
    } else {
        Verdict::pass("no-loss-coverage", detail)
    }
}

// ---------------------------------------------------------------------------
// 7 — swaps keep their histories apart
// ---------------------------------------------------------------------------

/// The workspace the name-swapper persona trades names in. Shared by every
/// device, as `standing_key` and `orchestrate::run` place it.
pub const SWAP_WORKSPACE: &str = "Shared-name-swapper";

/// The two bodies of every swap an actor committed.
///
/// A swap journals the two names it exchanged as two consecutive commits of
/// one actor's own stream, each with the content now standing at that name.
/// Every body the persona writes is its own (`content_bytes` from a fresh
/// seed) and it never rewrites a name, so two bodies of one swap are two
/// files, always.
pub fn swap_pairs(records: &[Record]) -> Vec<(String, String)> {
    let mut open: BTreeMap<String, (u64, String)> = BTreeMap::new();
    let mut pairs = Vec::new();
    for record in records {
        let Record::ActorCommit { seq, actor, op, sha256, .. } = record else {
            continue;
        };
        let Some(sha) = sha256.as_ref().filter(|_| op == "swap") else {
            open.remove(actor);
            continue;
        };
        match open.remove(actor) {
            Some((first_seq, first)) if first_seq + 1 == *seq && first != *sha => {
                pairs.push((first, sha.clone()));
            }
            _ => {
                open.insert(actor.clone(), (*seq, sha.clone()));
            }
        }
    }
    pairs
}

/// No file's history holds both bodies of one swap.
///
/// `histories` is each file's saved version hashes, by file id, with its path
/// for the report. A file holding both is a trade read as two edits: each
/// record took the other file's bytes as a new version of itself (Defects AH
/// and AI), and the trees can agree perfectly while it happens.
pub fn check_swap_histories(
    histories: &BTreeMap<i64, (String, Vec<String>)>,
    pairs: &[(String, String)],
    unread: usize,
) -> Verdict {
    if pairs.is_empty() {
        return Verdict::pass("swaps-apart", "vacuous — no swap was committed this run");
    }
    let mut mixed = Vec::new();
    for (id, (path, hashes)) in histories {
        for (a, b) in pairs {
            if hashes.iter().any(|h| h == a) && hashes.iter().any(|h| h == b) {
                mixed.push(format!("file {id} ({path}) holds {} and {}", &a[..a.len().min(12)], &b[..b.len().min(12)]));
            }
        }
    }
    let note = if unread > 0 {
        format!("; {unread} history(ies) the server would not identify were not judged")
    } else {
        String::new()
    };
    if mixed.is_empty() {
        Verdict::pass(
            "swaps-apart",
            format!("{} swap(s) over {} file history(ies), none mixed{note}", pairs.len(), histories.len()),
        )
    } else {
        Verdict::fail(
            "swaps-apart",
            format!("{} history(ies) hold both sides of a swap: {}{note}", mixed.len(), mixed.iter().take(10).cloned().collect::<Vec<_>>().join("; ")),
        )
    }
}

/// Read the version history of every file in the swap workspace, live or
/// trashed. Bounded by `budget` calls; a history the server lists without
/// naming its contents is counted, not guessed.
fn swap_workspace_histories(
    api: &dyn DriveApi,
    tree: &ServerTree,
    budget: usize,
) -> (BTreeMap<i64, (String, Vec<String>)>, usize) {
    let mut histories = BTreeMap::new();
    let mut unread = 0;
    let prefix = format!("{SWAP_WORKSPACE}/");
    for file in tree.files.values() {
        let Some(path) = tree.path_of(file).filter(|p| p.starts_with(&prefix)) else {
            continue;
        };
        if histories.len() + unread >= budget {
            unread += 1;
            continue;
        }
        match server::version_contents(api, file.id) {
            Ok(h) if h.unidentified == 0 => {
                histories.insert(file.id, (path, h.hashes));
            }
            _ => unread += 1,
        }
    }
    (histories, unread)
}

// ---------------------------------------------------------------------------
// 8 — custody
// ---------------------------------------------------------------------------

/// What one device's disk holds, for the custody check: every file with its
/// content and the inode of the directory it stands in, and every directory
/// on the disk by inode, with its birth time where the filesystem keeps one.
#[derive(Debug, Clone, Default)]
pub struct Placement {
    pub files: Vec<(String, String, u64)>,
    pub dirs: BTreeMap<u64, Option<u64>>,
    /// The contents whose file this device's store says lost a move race,
    /// each with when the engine said so (its latest such issue): its user
    /// moved the file while a peer's user moved it elsewhere, the peer's
    /// move reached the server first, and the engine told this user so
    /// (`MoveRaceServerWon`). Read from the store, not the disk. The time is
    /// the engine's clock and the journal's is the actor's, both this
    /// device's host.
    pub lost_races: BTreeMap<String, u64>,
}

impl Placement {
    /// Whether the directory a commit named still stands on this disk: its
    /// inode is here, and, where both sides know a birth, it is the same
    /// directory and not a later one that took over the freed inode.
    fn holds(&self, inode: u64, birth_ns: Option<u64>) -> bool {
        match (self.dirs.get(&inode), birth_ns) {
            (None, _) => false,
            (Some(Some(now)), Some(then)) => *now == then,
            (Some(_), _) => true,
        }
    }
}

/// The contents of every file this device's store holds a lost move race
/// for. A store that cannot be read gives none: the race then excuses
/// nothing, which errs towards reporting.
pub fn lost_move_races(db: &Path) -> BTreeMap<String, u64> {
    let read = || -> Result<BTreeMap<String, u64>, rusqlite::Error> {
        use rusqlite::{Connection, OpenFlags};
        let conn = Connection::open_with_flags(db, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX)?;
        let mut q = conn.prepare(
            "SELECT e.synced_content_sha256, e.remote_content_sha256, e.last_seen_sha256, i.created_at
             FROM issues i JOIN entries e ON e.entity_type = i.entity_type AND e.server_id = i.server_id
             WHERE i.entity_type = 'file' AND i.detail LIKE 'MoveRaceServerWon%'",
        )?;
        let mut out: BTreeMap<String, u64> = BTreeMap::new();
        let shas = |r: &rusqlite::Row| -> rusqlite::Result<([Option<String>; 3], i64)> {
            Ok(([r.get(0)?, r.get(1)?, r.get(2)?], r.get(3)?))
        };
        for row in q.query_map([], shas)? {
            let (versions, at) = row?;
            for sha in versions.into_iter().flatten() {
                let at = at.max(0) as u64;
                out.entry(sha).and_modify(|t| *t = (*t).max(at)).or_insert(at);
            }
        }
        Ok(out)
    };
    read().unwrap_or_default()
}

/// Read a device's placement off its disk.
#[cfg(unix)]
pub fn placement_on_disk(root: &Path, tree: &LocalTree) -> Placement {
    use std::os::unix::fs::MetadataExt;
    let mut out = Placement::default();
    for entry in tree.entries.values() {
        let full = root.join(&entry.path);
        if entry.is_dir {
            if let Ok(m) = std::fs::metadata(&full) {
                out.dirs.insert(m.ino(), crate::actor::birth_ns(&m));
            }
            continue;
        }
        let (Some(sha), Some(parent)) = (entry.sha256.as_ref(), full.parent()) else {
            continue;
        };
        if let Ok(m) = std::fs::metadata(parent) {
            out.files.push((entry.path.clone(), sha.clone(), m.ino()));
        }
    }
    if let Ok(m) = std::fs::metadata(root) {
        out.dirs.insert(m.ino(), crate::actor::birth_ns(&m));
    }
    out
}

#[cfg(not(unix))]
pub fn placement_on_disk(_root: &Path, _tree: &LocalTree) -> Placement {
    Placement::default()
}

/// Every content stands in a folder the user put it in.
///
/// Each content's placing commits (a write, an append, a save, a rename or
/// swap carrying it in) say, by directory identity, where its writer put it
/// on the writer's own disk; a later commit under the same name in the same
/// directory, with other bytes, or a delete or rename away from that name in
/// that directory, withdraws one. On each device that still holds the content
/// and still has one of those directories, the content must stand in one of
/// them. Standing only somewhere else, the engine carried it into a folder
/// the user never put it in -- a conflict copy beside a peer's moved version,
/// a save taken along by another file's move -- which no tree comparison
/// sees, because every device agrees on the wrong place.
///
/// Placements are keyed by the directory and the name in it, never by path:
/// renaming a folder, by this device's user or by a peer's (which the engine
/// applies here with no record of its own), changes no directory's identity,
/// so it neither withdraws what stands beneath the folder nor leaves a
/// placement under a name the next commit cannot find (soak run 1572). A
/// removed folder drops out by leaving the disk. A commit that names no
/// directory, from a journal older than these identities, takes nothing.
///
/// What a device did not write is not judged on it: a peer's move, or the
/// remote actor's, is applied by the engine and lands where that user put it.
/// Nor is a content another device's user placed after this one did: that
/// user moved it on (a peer renaming the file a create-create clash left at
/// its own path, which was this device's), and the content's folder is that
/// user's choice, which this device's inodes cannot name (soak run 1520).
/// Placed means placed as a file: a write over a file is that file's next
/// version, and a peer's user who moves the file, before or after this
/// device's user saved over it, moves this version with it (soak run 1586).
/// Only a commit that chooses a folder places the file: a write over nothing,
/// a move into another directory, a swap. A write over a file, like a rename
/// in place, chooses none, so a peer's edit never takes the file from the
/// user who placed it. A file whose first version nobody here placed (the
/// remote actor's, a download of a server-made file) has no placer, and an
/// edit of it is judged on the device that made the edit.
///
/// Two users who move one file at once, neither having seen the other's
/// move, each place it, and the engine keeps whichever move reached the
/// server first, telling the other user theirs lost (soak run 1660). The
/// later placer by the clock is then not the file's owner. When the last
/// placer's store holds that lost race for the file, said after that
/// placer's last placing commit, and another device's user placed the file
/// before, the file is judged on that device instead: it must stand there in
/// a folder that user put it in. The race excuses the loser only for
/// standing where the winner chose, checked on the winner's own disk, so a
/// race reported wrongly hides nothing; and only for the move that lost, so
/// the loser's later move is judged on the loser as any other. A repeat of a
/// race the engine words identically keeps the first one's time, and is
/// then judged on the loser. The winner is the latest other placer, which
/// is the device the server kept when two devices place the file, as the
/// soak's do.
pub fn check_custody(records: &[Record], placements: &BTreeMap<String, Placement>) -> Verdict {
    // (device, directory inode, name in it) -> (content, directory birth) of
    // the placing commit standing there now.
    let mut at: BTreeMap<(String, u64, String), (String, Option<u64>)> = BTreeMap::new();
    // Content -> the first version of the file it is a version of.
    let mut file_of: BTreeMap<String, String> = BTreeMap::new();
    // File, by its first version -> the devices whose users placed it, with
    // when, in order; the last is the one whose user placed it last.
    let mut placed_by: BTreeMap<String, Vec<(String, u64)>> = BTreeMap::new();
    // Actor -> the directory the content it is renaming stood in, between
    // its `rename` and `rename_into` records.
    let mut renaming_from: BTreeMap<String, u64> = BTreeMap::new();
    for record in records {
        let Record::ActorCommit { actor, op, path, sha256, parent_inode, parent_birth_ns, replaces_sha256, .. } = record else {
            continue;
        };
        if let (Some(sha), Some(earlier)) = (sha256, replaces_sha256) {
            let first = file_of.get(earlier).cloned().unwrap_or_else(|| earlier.clone());
            file_of.entry(sha.clone()).or_insert(first);
        }
        let Some(dir) = *parent_inode else {
            if op == "rename" {
                renaming_from.remove(actor.as_str());
            }
            continue;
        };
        let device = actor.split('/').next().unwrap_or_default().to_string();
        let name = path.rsplit('/').next().unwrap_or(path).to_string();
        let key = (device, dir, name);
        match sha256 {
            Some(sha) => {
                // A rename within one directory names the content and chooses
                // no folder: its folder is still the one whoever placed it
                // last chose. A peer's user who moved it meanwhile chose that,
                // and the engine keeps both, the peer's folder with this name
                // (run 1541).
                let in_place = op == "rename_into" && renaming_from.remove(actor.as_str()) == Some(dir);
                let chooses_a_folder = match op.as_str() {
                    "rename_into" => !in_place,
                    "swap" => true,
                    _ => replaces_sha256.is_none(),
                };
                if chooses_a_folder {
                    let file = file_of.get(sha).unwrap_or(sha);
                    placed_by.entry(file.clone()).or_default().push((key.0.clone(), record.ts_ms()));
                }
                at.insert(key, (sha.clone(), *parent_birth_ns));
            }
            None => {
                if op == "rename" {
                    renaming_from.insert(actor.clone(), dir);
                } else if op == "rename_into" {
                    renaming_from.remove(actor.as_str());
                }
                // A delete or a rename away: what stood under this name in
                // this directory stands there no longer. A directory's own
                // commit withdraws nothing beneath it.
                at.remove(&key);
            }
        }
    }
    // File -> the one device it is judged on: its last placer, or, where that
    // placer lost a move race over it, the device whose user placed it before.
    let mut judged_on: BTreeMap<String, String> = BTreeMap::new();
    for (file, devices) in &placed_by {
        let Some((last, placed_at)) = devices.last() else { continue };
        let lost = placements.get(last).is_some_and(|p| {
            p.lost_races.iter().any(|(sha, said_at)| file_of.get(sha).unwrap_or(sha) == file && placed_at <= said_at)
        });
        let winner = devices.iter().rev().map(|(d, _)| d).find(|d| *d != last).filter(|_| lost);
        judged_on.insert(file.clone(), winner.unwrap_or(last).clone());
    }
    let mut wanted: BTreeMap<(String, String), BTreeSet<(u64, Option<u64>)>> = BTreeMap::new();
    for ((device, dir, _), (sha, birth)) in &at {
        wanted.entry((device.clone(), sha.clone())).or_default().insert((*dir, *birth));
    }
    let mut astray = Vec::new();
    let mut judged = 0usize;
    for ((device, sha), dirs) in &wanted {
        let Some(placement) = placements.get(device) else { continue };
        let file = file_of.get(sha).unwrap_or(sha);
        if judged_on.get(file).is_some_and(|on| on != device) {
            continue;
        }
        let standing: Vec<&(String, String, u64)> = placement.files.iter().filter(|(_, s, _)| s == sha).collect();
        let still_there: BTreeSet<u64> = dirs.iter().filter(|(d, born)| placement.holds(*d, *born)).map(|(d, _)| *d).collect();
        if standing.is_empty() || still_there.is_empty() {
            continue;
        }
        judged += 1;
        if !standing.iter().any(|(_, _, d)| still_there.contains(d)) {
            astray.push(format!(
                "{device}: {} stands at {} and in none of the {} folder(s) the user put it in",
                &sha[..sha.len().min(12)],
                standing.iter().map(|(p, _, _)| p.as_str()).take(3).collect::<Vec<_>>().join(", "),
                still_there.len()
            ));
        }
    }
    if astray.is_empty() {
        Verdict::pass("custody", format!("{judged} content(s) judged, every one in a folder the user put it in"))
    } else {
        Verdict::fail(
            "custody",
            format!("{} content(s) stand in a folder the user never put them in: {}", astray.len(), astray.iter().take(10).cloned().collect::<Vec<_>>().join("; ")),
        )
    }
}

// ---------------------------------------------------------------------------
// 9 — the stores hold together
// ---------------------------------------------------------------------------

/// One device's state store against itself, read-only, after the settle.
///
/// Two things the engine's own sweeps exist to keep true, judged from outside:
/// no live record whose parent is not in the store (stranded: no pass ever
/// walks down to it, and it is never decided about again), and no directory
/// held by two live folder records, by its id or by its name in its parent
/// (one directory, two owners: a delete of either takes the other's files).
pub fn store_findings(db: &Path, personality: &Personality) -> Result<Vec<String>, rusqlite::Error> {
    use rusqlite::{Connection, OpenFlags};
    let conn = Connection::open_with_flags(db, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX)?;
    conn.busy_timeout(Duration::from_secs(5))?;
    struct Row {
        folder: bool,
        id: i64,
        parent: Option<i64>,
        name: String,
        deleted: bool,
        holds: bool,
        dir_id: Option<i64>,
    }
    let mut stmt = conn.prepare(
        "SELECT entity_type, server_id, parent_folder_id, remote_name, local_name, remote_deleted,
                synced_parent_id, synced_name, stand_in_parent_id, stand_in_name, synced_fp_file_id
           FROM entries",
    )?;
    let rows: Vec<Row> = stmt
        .query_map([], |r| {
            let synced_name: Option<String> = r.get(7)?;
            let stand_in_name: Option<String> = r.get(9)?;
            let local_name: Option<String> = r.get(4)?;
            let remote_name: String = r.get(3)?;
            // The placement this disk has: agreed, else a stand-in, else the
            // server's (the engine's `local_placement`).
            let (parent, placed) = if synced_name.is_some() {
                (r.get::<_, Option<i64>>(6)?, synced_name.clone())
            } else if stand_in_name.is_some() {
                (r.get::<_, Option<i64>>(8)?, stand_in_name.clone())
            } else {
                (r.get::<_, Option<i64>>(2)?, None)
            };
            Ok(Row {
                folder: r.get::<_, String>(0)? == "folder",
                id: r.get(1)?,
                parent,
                name: local_name.or(placed).unwrap_or(remote_name),
                deleted: r.get::<_, i64>(5)? != 0,
                holds: synced_name.is_some() || stand_in_name.is_some(),
                dir_id: r.get::<_, Option<i64>>(10)?.filter(|id| *id != 0),
            })
        })?
        .collect::<Result<_, _>>()?;
    let folders: BTreeSet<i64> = rows.iter().filter(|r| r.folder).map(|r| r.id).collect();
    let mut findings = Vec::new();
    for row in rows.iter().filter(|r| !r.deleted) {
        if let Some(p) = row.parent.filter(|p| !folders.contains(p)) {
            findings.push(format!(
                "{} {} ({}) is stranded: its parent {p} is not in the store",
                if row.folder { "folder" } else { "file" },
                row.id,
                row.name
            ));
        }
    }
    let mut by_dir: BTreeMap<i64, i64> = BTreeMap::new();
    let mut by_slot: BTreeMap<(Option<i64>, String), i64> = BTreeMap::new();
    for row in rows.iter().filter(|r| r.folder && !r.deleted && r.holds) {
        if let Some(dir) = row.dir_id {
            if let Some(other) = by_dir.insert(dir, row.id) {
                findings.push(format!("folders {other} and {} both hold directory {dir}", row.id));
            }
        }
        let slot = (row.parent, jd_vfs::comparison_key(&row.name, personality));
        if let Some(other) = by_slot.insert(slot, row.id) {
            findings.push(format!("folders {other} and {} both claim {} in folder {:?}", row.id, row.name, row.parent));
        }
    }
    Ok(findings)
}

/// Every device's store, one verdict.
pub fn check_stores(devices: &[(String, std::path::PathBuf)], personality: &Personality) -> Verdict {
    let mut findings = Vec::new();
    let mut unread = Vec::new();
    for (name, db) in devices {
        match store_findings(db, personality) {
            Ok(found) => findings.extend(found.into_iter().map(|f| format!("{name}: {f}"))),
            Err(e) => unread.push(format!("{name}: {e}")),
        }
    }
    if !unread.is_empty() && findings.is_empty() {
        // A store that cannot be read is not a store that holds together.
        return Verdict::fail("stores-whole", format!("could not read: {}", unread.join("; ")));
    }
    if findings.is_empty() {
        Verdict::pass("stores-whole", format!("{} store(s): nothing stranded, no directory held twice", devices.len()))
    } else {
        Verdict::fail(
            "stores-whole",
            format!("{} finding(s): {}", findings.len(), findings.iter().take(10).cloned().collect::<Vec<_>>().join("; ")),
        )
    }
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
// 5b — the settle holds
// ---------------------------------------------------------------------------

/// Did the quiet last as long as the audit took to look at it?
///
/// Convergence is a photograph. It is taken, and then the verifier spends
/// minutes walking two disks and a server index before it says anything — and a
/// device that grew work in that window was never settled, it was between
/// attempts. Nobody is touching the fleet: the actors are stopped, the
/// partitions are lifted, and the audit only reads. So there is no honest
/// reason for a device to have anything to do.
///
/// This is the check that names run 247. A device there declared itself green,
/// and by the time the tree walk reached it, it was holding four files in
/// `pending_upload` with an empty queue and a folder move it had given up on —
/// a subtree the server had never been sent. The audit did report it, but as a
/// list of twenty paths that disagreed, which reads as a data problem and takes
/// a day to trace back to one refusal. Asking the device instead gets the same
/// failure in one line, in its own words, at the moment it happened.
///
/// Only devices that WERE settled are asked. One that never converged has
/// already been reported by the convergence verdict, and saying it twice buries
/// the run that has exactly one thing wrong with it.
pub fn check_settle_holds(
    at_convergence: &BTreeMap<String, Option<Status>>,
    after_the_audit: &BTreeMap<String, Option<Status>>,
) -> Verdict {
    let mut problems = Vec::new();
    let mut held = 0usize;
    for (device, before) in at_convergence {
        let Some(before) = before else { continue };
        if !before.is_settled() {
            continue;
        }
        match after_the_audit.get(device) {
            Some(Some(now)) if now.is_settled() => held += 1,
            Some(Some(now)) => problems.push(format!(
                "{device} converged and then found work with nobody touching it: {} \
                 queued op(s), {} ({})",
                now.pending_ops,
                now.indicator,
                describe_in_flight(now),
            )),
            _ => problems.push(format!(
                "{device} converged and then stopped answering, so its quiet cannot be believed"
            )),
        }
    }
    if problems.is_empty() {
        Verdict::pass(
            "settle-holds",
            format!("{held} device(s) still settled when the audit finished"),
        )
    } else {
        Verdict::fail("settle-holds", problems.join("; "))
    }
}

fn describe_in_flight(status: &Status) -> String {
    let states = status.in_flight_by_state();
    if states.is_empty() {
        "nothing it will name".to_string()
    } else {
        states
            .iter()
            .map(|(k, v)| format!("{k}={v}"))
            .collect::<Vec<_>>()
            .join(" ")
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
#[cfg(target_os = "linux")]
fn daemon_rss_kb(device: &Device) -> Option<u64> {
    let pid = device.daemon_pid()?;
    let status = std::fs::read_to_string(format!("/proc/{pid}/status")).ok()?;
    for line in status.lines() {
        if let Some(rest) = line.strip_prefix("VmRSS:") {
            return rest.split_whitespace().next()?.parse().ok();
        }
    }
    None
}

#[cfg(target_os = "linux")]
fn daemon_fd_count(device: &Device) -> Option<u64> {
    let pid = device.daemon_pid()?;
    Some(std::fs::read_dir(format!("/proc/{pid}/fd")).ok()?.count() as u64)
}

/// Off Linux the same two readings come from `ps` (kilobytes, as `/proc`
/// reports them) and `lsof`.
#[cfg(not(target_os = "linux"))]
fn daemon_rss_kb(device: &Device) -> Option<u64> {
    let pid = device.daemon_pid()?.to_string();
    let out = std::process::Command::new("ps")
        .args(["-o", "rss=", "-p", &pid])
        .output()
        .ok()?;
    String::from_utf8_lossy(&out.stdout).trim().parse().ok()
}

#[cfg(not(target_os = "linux"))]
fn daemon_fd_count(device: &Device) -> Option<u64> {
    let pid = device.daemon_pid()?.to_string();
    let out = std::process::Command::new("lsof")
        .args(["-n", "-P", "-p", &pid, "-F", "f"])
        .output()
        .ok()?;
    // One `f` line per descriptor; `cwd`, `txt` and the like are not ones.
    let count = String::from_utf8_lossy(&out.stdout)
        .lines()
        .filter(|l| l.strip_prefix('f').is_some_and(|n| n.parse::<u64>().is_ok()))
        .count();
    Some(count as u64)
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
    let (convergence, convergence_ms, statuses) = await_convergence(fleet, api, deadline, sleep);

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
                coverage: Coverage::default(),
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
    let (mut no_loss, losses, coverage) = check_no_loss(
        records,
        &recoverable,
        previously_on_server,
        &standing_paths(&trees),
        personality,
    );
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
    verdicts.push(check_no_loss_coverage(coverage));

    verdicts.push(check_no_ciphertext(&server_tree, &trees, &statuses));

    // 7 and 8 read what the walks above already hold, plus one history call
    // per file in the swap workspace -- a handful, the persona keeps three.
    let pairs = swap_pairs(records);
    let (histories, unread) = if pairs.is_empty() {
        (BTreeMap::new(), 0)
    } else {
        swap_workspace_histories(api, &server_tree, VERSION_LOOKUP_BUDGET)
    };
    verdicts.push(check_swap_histories(&histories, &pairs, unread));
    let placements: BTreeMap<String, Placement> = fleet
        .devices
        .iter()
        .filter_map(|d| {
            trees.get(&d.name).map(|t| {
                let mut placement = placement_on_disk(&d.root, t);
                placement.lost_races = lost_move_races(&d.state_db());
                (d.name.clone(), placement)
            })
        })
        .collect();
    verdicts.push(check_custody(records, &placements));
    let stores: Vec<(String, std::path::PathBuf)> = fleet.devices.iter().map(|d| (d.name.clone(), d.state_db())).collect();
    verdicts.push(check_stores(&stores, personality));
    verdicts.push(check_issues_honest(&statuses));

    // Asked last, so the window it covers is the whole audit and not a slice of
    // it. Everything above only reads.
    let after_the_audit: BTreeMap<String, Option<Status>> = fleet
        .devices
        .iter()
        .map(|d| (d.name.clone(), control::status(&d.control_file())))
        .collect();
    verdicts.push(check_settle_holds(&statuses, &after_the_audit));

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
        coverage,
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
            parent_inode: None,
            parent_birth_ns: None,
            replaces_sha256: None,
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

    /// The paths in `commit`'s workspace that something is standing at, in the
    /// form `check_no_loss` looks them up by. Everything these tests write about
    /// is a file the user still has, unless a test says otherwise.
    fn standing(paths: &[&str]) -> BTreeSet<String> {
        paths
            .iter()
            .map(|p| tree::key_for(&format!("Shared-office/{p}"), &Personality::linux()))
            .collect()
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

    /// `status()` above always reports an empty queue; this one can hold work.
    fn busy_status(pending_ops: u64, entries: serde_json::Value) -> Status {
        let json = json!({
            "indicator": "attention", "summary": "", "tracked": 0, "settled": 0,
            "pending_ops": pending_ops, "waiting_for_keys": 0, "cursor": 0,
            "entries": entries, "issues": [],
        });
        Status::from_json(&json)
    }

    fn fleet_of(pairs: Vec<(&str, Option<Status>)>) -> BTreeMap<String, Option<Status>> {
        pairs
            .into_iter()
            .map(|(n, s)| (n.to_string(), s))
            .collect()
    }

    #[test]
    fn a_device_that_grows_work_while_the_audit_runs_did_not_settle() {
        // Run 247: green at the poll, and by the time the trees were walked it
        // was holding four files it had no operation for.
        let converged = fleet_of(vec![(
            "device-a",
            Some(status("attention", json!({"synced": 577}), json!([]))),
        )]);
        let after = fleet_of(vec![(
            "device-a",
            Some(busy_status(0, json!({"synced": 577, "pending_upload": 4}))),
        )]);
        let verdict = check_settle_holds(&converged, &after);
        assert!(
            !verdict.ok,
            "a device that grew four pending uploads after converging was called settled"
        );
        assert!(
            verdict.detail.contains("pending_upload=4"),
            "the failure has to say what it grew: {}",
            verdict.detail
        );
    }

    #[test]
    fn a_device_that_stays_quiet_holds_the_settle() {
        let quiet = || {
            fleet_of(vec![(
                "device-a",
                Some(status("attention", json!({"synced": 577}), json!([]))),
            )])
        };
        assert!(check_settle_holds(&quiet(), &quiet()).ok);
    }

    #[test]
    fn a_device_that_never_converged_is_not_reported_twice() {
        // The convergence verdict already has this one. Saying it again here
        // buries the run whose single fault is a settle that did not hold.
        let never = fleet_of(vec![(
            "device-a",
            Some(busy_status(3, json!({"synced": 1, "pending_upload": 2}))),
        )]);
        let still_busy = fleet_of(vec![(
            "device-a",
            Some(busy_status(9, json!({"synced": 1, "pending_upload": 8}))),
        )]);
        assert!(check_settle_holds(&never, &still_busy).ok);
    }

    #[test]
    fn a_device_that_goes_silent_after_converging_is_not_believed() {
        let converged = fleet_of(vec![(
            "device-a",
            Some(status("green", json!({"synced": 4}), json!([]))),
        )]);
        let gone = fleet_of(vec![("device-a", None)]);
        let verdict = check_settle_holds(&converged, &gone);
        assert!(!verdict.ok, "{}", verdict.detail);
        assert!(verdict.detail.contains("stopped answering"), "{}", verdict.detail);
    }

    #[test]
    fn a_content_still_on_a_device_is_not_lost() {
        let records = vec![commit("a.txt", "aa", "write")];
        let (verdict, _losses, _coverage) =
            check_no_loss(&records, &recoverable(&["aa"], &[], &[]), &BTreeSet::new(), &standing(&["a.txt"]), &Personality::linux());
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn a_content_only_on_the_server_is_not_lost() {
        // The normal state for anything the user deleted locally, or that has
        // not reached a second device yet.
        let records = vec![commit("a.txt", "aa", "write")];
        assert!(
            check_no_loss(&records, &recoverable(&[], &["aa"], &[]), &BTreeSet::new(), &standing(&["a.txt"]), &Personality::linux())
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
            check_no_loss(&records, &recoverable(&[], &[], &["aa"]), &BTreeSet::new(), &standing(&["a.txt"]), &Personality::linux())
                .0
                .ok
        );
    }

    #[test]
    fn a_content_that_is_nowhere_is_a_violation_that_names_the_file() {
        // The finding this whole rig exists to produce. It has to say which
        // file, who wrote it and when, or nobody can investigate it.
        let records = vec![commit("Projects/Report.docx", "abcdef0123456789", "write")];
        let (verdict, _losses, _coverage) =
            check_no_loss(
            &records,
            &recoverable(&[], &[], &[]),
            &BTreeSet::new(),
            &standing(&["Projects/Report.docx"]),
            &Personality::linux(),
        );
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

        let live = standing(&["a.txt"]);
        let (gone, _losses, _coverage) = check_no_loss(
            &records,
            &recoverable(&["second"], &[], &[]),
            &taken,
            &live,
            &Personality::linux(),
        );
        assert!(!gone.ok);
        assert!(
            gone.detail.contains("disappeared from it"),
            "{}",
            gone.detail
        );

        let (still_there, _l2, _coverage) =
            check_no_loss(
            &records,
            &recoverable(&["second"], &["first"], &[]),
            &taken,
            &live,
            &Personality::linux(),
        );
        assert!(still_there.ok, "{}", still_there.detail);
    }

    #[test]
    fn the_first_settle_says_it_has_no_history_to_check_rather_than_checking_none() {
        // "all 0 contents the server had taken are still there" reads as a check
        // that ran and found nothing wrong. On a first settle no check ran.
        let records = vec![commit("a.txt", "aa", "write")];
        let (verdict, _losses, _coverage) =
            check_no_loss(&records, &recoverable(&["aa"], &[], &[]), &BTreeSet::new(), &standing(&["a.txt"]), &Personality::linux());
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
        let (verdict, _losses, _coverage) = check_no_loss(
            &records,
            &recoverable(&["second"], &[], &[]),
            &BTreeSet::new(),
            &standing(&["a.txt"]),
            &Personality::linux(),
        );
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn a_claim_at_a_path_nothing_is_at_is_counted_rather_than_reported_as_lost() {
        // The oracle's own blind spot, made harmless and made visible. A claim
        // is keyed by its path STRING, and the rig renames folders all run, so a
        // claim whose ancestry was renamed twice keeps a key naming a path
        // nothing is at while the physical file goes on being overwritten under
        // its new name. Runs 218, 255 and 265 were all reported as data loss on
        // exactly this, and in all three the claimed path appeared zero times in
        // the frozen trees.
        //
        // Not reported, because `lost_live` is about LIVE paths and this is not
        // one. Counted, because an oracle that quietly stops judging things is
        // worth less than one that says how much it could not judge.
        let records = vec![commit("Projects/Sub 9/doc.txt", "abcdef0123456789", "write")];
        let (verdict, losses, _coverage) = check_no_loss(
            &records,
            &recoverable(&[], &[], &[]),
            &BTreeSet::new(),
            &standing(&["Projects/Sub 9 (15)/doc.txt"]),
            &Personality::linux(),
        );
        assert!(
            verdict.ok,
            "a claim naming a path nothing is at is not a loss: {}",
            verdict.detail
        );
        assert!(losses.live.is_empty(), "{:?}", losses.live);
        assert!(
            verdict.detail.contains("1 unaccounted claim(s) named a path nothing is at"),
            "the run has to say how much the oracle could not judge: {}",
            verdict.detail
        );

        // And the guard must not be a way to switch the check off: the same
        // claim, at a path something IS standing at, still fails.
        let (still_fails, _l, _coverage) = check_no_loss(
            &records,
            &recoverable(&[], &[], &[]),
            &BTreeSet::new(),
            &standing(&["Projects/Sub 9/doc.txt"]),
            &Personality::linux(),
        );
        assert!(!still_fails.ok, "{}", still_fails.detail);
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
                parent_inode: None,
                parent_birth_ns: None,
                replaces_sha256: None,
                ts_ms: 2,
            },
        ];
        // The content still has to be findable historically, but no live path
        // claims it.
        let (verdict, _losses, _coverage) = check_no_loss(
            &records,
            &recoverable(&[], &["aa"], &[]),
            &BTreeSet::new(),
            &BTreeSet::new(),
            &Personality::linux(),
        );
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

    fn placed(seq: u64, actor: &str, op: &str, path: &str, sha: Option<&str>, inode: Option<u64>) -> Record {
        Record::ActorCommit {
            seq,
            actor: actor.into(),
            persona: actor.split('/').nth(1).unwrap_or("remote-user").into(),
            op: op.into(),
            path: path.into(),
            sha256: sha.map(String::from),
            size: 1,
            mtime_ms: None,
            parent_inode: inode,
            parent_birth_ns: None,
            replaces_sha256: None,
            ts_ms: seq,
        }
    }

    fn histories(files: &[(i64, &str, &[&str])]) -> BTreeMap<i64, (String, Vec<String>)> {
        files
            .iter()
            .map(|(id, path, hashes)| (*id, (path.to_string(), hashes.iter().map(|h| h.to_string()).collect())))
            .collect()
    }

    #[test]
    fn a_swap_read_as_two_edits_mixes_one_files_history_and_fails() {
        // The name-swapper traded a and b: two consecutive commits of its own.
        let records = vec![
            placed(7, "device-a/name-swapper", "swap", "slot-1.dat", Some("bodyB"), Some(5)),
            placed(8, "device-a/name-swapper", "swap", "slot-2.dat", Some("bodyA"), Some(5)),
        ];
        let pairs = swap_pairs(&records);
        assert_eq!(pairs, vec![("bodyB".to_string(), "bodyA".to_string())]);
        // One file took the other's bytes as a version of itself.
        let mixed = histories(&[(901, "Shared-name-swapper/slot-1.dat", &["bodyA", "bodyB"]), (902, "Shared-name-swapper/slot-2.dat", &["bodyB"])]);
        let verdict = check_swap_histories(&mixed, &pairs, 0);
        assert!(!verdict.ok, "{}", verdict.detail);
        assert!(verdict.detail.contains("file 901"), "{}", verdict.detail);
        // Each file kept its own body: the trade moved names, not bytes.
        let apart = histories(&[(901, "Shared-name-swapper/slot-1.dat", &["bodyA"]), (902, "Shared-name-swapper/slot-2.dat", &["bodyB"])]);
        assert!(check_swap_histories(&apart, &pairs, 0).ok);
    }

    #[test]
    fn swap_commits_from_two_actors_or_two_swaps_never_pair_across() {
        let records = vec![
            placed(7, "device-a/name-swapper", "swap", "slot-1.dat", Some("x"), Some(5)),
            placed(3, "device-b/name-swapper", "swap", "slot-1.dat", Some("y"), Some(6)),
            placed(8, "device-a/name-swapper", "swap", "slot-2.dat", Some("z"), Some(5)),
            placed(9, "device-a/name-swapper", "swap", "slot-3.dat", Some("w"), Some(5)),
        ];
        assert_eq!(swap_pairs(&records), vec![("x".to_string(), "z".to_string())]);
        assert!(check_swap_histories(&BTreeMap::new(), &[], 0).detail.starts_with("vacuous"));
    }

    fn disk(files: &[(&str, &str, u64)], dirs: &[u64]) -> Placement {
        Placement {
            files: files.iter().map(|(p, s, d)| (p.to_string(), s.to_string(), *d)).collect(),
            dirs: dirs.iter().map(|d| (*d, None)).collect(),
            lost_races: BTreeMap::new(),
        }
    }

    #[test]
    fn a_content_carried_into_a_folder_the_user_never_put_it_in_fails_custody() {
        // Written into the directory with inode 40 on device-a.
        let records = vec![placed(1, "device-a/office", "write", "Work/notes.txt", Some("cc"), Some(40))];
        // It stands in directory 41 -- a conflict copy put beside a peer's
        // moved version, say -- while directory 40 is still there.
        let astray = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Other/notes (conflicted copy).txt", "cc", 41)], &[40, 41]))]);
        let verdict = check_custody(&records, &astray);
        assert!(!verdict.ok, "{}", verdict.detail);
        assert!(verdict.detail.contains("device-a"), "{}", verdict.detail);
        // Standing in the folder it was written into.
        let home = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Work/notes.txt", "cc", 40)], &[40, 41]))]);
        assert!(check_custody(&records, &home).ok);
    }

    fn born(seq: u64, actor: &str, op: &str, path: &str, sha: Option<&str>, inode: u64, birth: u64) -> Record {
        let mut record = placed(seq, actor, op, path, sha, Some(inode));
        if let Record::ActorCommit { parent_birth_ns, .. } = &mut record {
            *parent_birth_ns = Some(birth);
        }
        record
    }

    #[test]
    fn run_1572_a_content_moved_under_a_folder_renamed_twice_stands_where_its_user_put_it() {
        // device-a's journal for doc-4, as soak run 1572 recorded it. Its user
        // wrote it into Projects (directory 1043024) and renamed it in place;
        // device-b's user renamed Projects to Projects (8), which the engine
        // applied here with no record; device-a's user moved it into Sub 9
        // (1043182) and renamed it back; then renamed Projects (8) itself five
        // times. It stands in 1043182, where its user put it, and the check
        // failed the run: a folder rename withdrew the true placement by path,
        // and the stale one under the old name was never withdrawn.
        let a = "device-a/messy-human";
        let records = vec![
            placed(552208, a, "write", "Projects/doc-4.txt", Some("b6ce29"), Some(1043024)),
            placed(554555, a, "rename", "Projects/doc-4.txt", None, Some(1043024)),
            placed(554556, a, "rename_into", "Projects/DOC-4.TXT", Some("b6ce29"), Some(1043024)),
            placed(573073, a, "rename", "Projects (8)/DOC-4.TXT", None, Some(1043024)),
            placed(573074, a, "rename_into", "Projects (8)/Sub 9/DOC-4.TXT", Some("b6ce29"), Some(1043182)),
            placed(576650, a, "rename", "Projects (8)/Sub 9/DOC-4.TXT", None, Some(1043182)),
            placed(576652, a, "rename_into", "Projects (8)/Sub 9/doc-4.txt", Some("b6ce29"), Some(1043182)),
            placed(585766, a, "rename", "Projects (8)", None, Some(1042071)),
            placed(585766, a, "rename_into", "Projects (8) (13)", None, None),
            placed(592839, a, "rename", "Projects (8) (13)", None, Some(1042071)),
            placed(592839, a, "rename_into", "Projects (8) (13) (17)", None, None),
            placed(659475, a, "rename", "Projects (8) (13) (17) (32) (33)", None, Some(1042071)),
            placed(659475, a, "rename_into", "Projects (8) (13) (17) (32) (33) (38)", None, None),
        ];
        let at = |dir: u64| {
            BTreeMap::from([(
                "device-a".to_string(),
                disk(&[("Shared-messy-human/Projects (8) (13) (17) (32) (33) (38)/Sub 9 (10)/doc-4.txt", "b6ce29", dir)], &[1042071, 1043024, 1043182]),
            )])
        };
        let verdict = check_custody(&records, &at(1043182));
        assert!(verdict.ok, "{}", verdict.detail);
        assert!(verdict.detail.starts_with("1 content(s) judged"), "{}", verdict.detail);
        // Still judged: carried back into the Projects folder it was moved out
        // of, it is astray.
        assert!(!check_custody(&records, &at(1043024)).ok);
    }

    #[test]
    fn run_1586_an_edit_goes_where_a_peers_user_moves_its_file() {
        // Soak run 1586: device-b's user wrote Projects/doc-5.txt; device-a
        // downloaded it and its user wrote over it; device-b's user, not yet
        // told, moved the file into Sub 8/Sub 10. The engine keeps one file,
        // device-a's edit in device-b's folder. The edit's custody is the
        // file's, and the peer moved the file.
        let records = |replaces: Option<&str>| {
            let mut edit = placed(206072, "device-a/messy-human", "write", "Projects/doc-5.txt", Some("0f2964"), Some(1042194));
            if let Record::ActorCommit { replaces_sha256, .. } = &mut edit {
                *replaces_sha256 = replaces.map(String::from);
            }
            vec![
                placed(192648, "device-b/messy-human", "write", "Projects/doc-5.txt", Some("1a88bb"), Some(1042315)),
                edit,
                placed(208824, "device-b/messy-human", "mkdir", "Projects/Sub 8/Sub 10", None, Some(1042423)),
                placed(208825, "device-b/messy-human", "rename", "Projects/doc-5.txt", None, Some(1042315)),
                placed(208826, "device-b/messy-human", "rename_into", "Projects/Sub 8/Sub 10/doc-5.txt", Some("1a88bb"), Some(1042448)),
            ]
        };
        let on_a = BTreeMap::from([(
            "device-a".to_string(),
            disk(&[("Shared-messy-human/Projects (19)/Sub 8 (11) (13)/Sub 10/doc-5.txt", "0f2964", 1042470)], &[1042194, 1042452, 1042470]),
        )]);
        let verdict = check_custody(&records(Some("1a88bb")), &on_a);
        assert!(verdict.ok, "{}", verdict.detail);
        // A write that went over nothing is a file of its own: device-b moved
        // another file, and this one is astray in device-b's folder.
        assert!(!check_custody(&records(None), &on_a).ok);
    }

    fn edit(seq: u64, actor: &str, path: &str, sha: &str, over: &str, inode: u64) -> Record {
        let mut record = placed(seq, actor, "write", path, Some(sha), Some(inode));
        if let Record::ActorCommit { replaces_sha256, .. } = &mut record {
            *replaces_sha256 = Some(over.into());
        }
        record
    }

    #[test]
    fn an_edit_goes_where_a_peers_user_moved_its_file_before_the_edit() {
        // Run 1586 in the other order: device-b's user wrote Projects/doc.txt
        // and moved it into Sub; device-a's user, not yet told, wrote over it
        // at Projects/doc.txt. The engine keeps one file, the edit in Sub.
        let records = vec![
            placed(1, "device-b/office", "write", "Projects/doc.txt", Some("v1"), Some(70)),
            placed(2, "device-b/office", "rename", "Projects/doc.txt", None, Some(70)),
            placed(3, "device-b/office", "rename_into", "Projects/Sub/doc.txt", Some("v1"), Some(71)),
            edit(4, "device-a/office", "Projects/doc.txt", "v2", "v1", 40),
        ];
        let on_a = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Projects/Sub/doc.txt", "v2", 41)], &[40, 41]))]);
        let verdict = check_custody(&records, &on_a);
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn a_peers_edit_takes_no_custody_of_this_devices_conflict_copy() {
        // device-a's user wrote Work/notes.txt (40) and edited it; device-b's
        // user, holding the first version, edited it later. The engine keeps
        // device-b's as the file and device-a's edit as a conflict copy. Put
        // in Other (41) on device-a, it is astray: device-b chose no folder.
        let records = vec![
            placed(1, "device-a/office", "write", "Work/notes.txt", Some("v1"), Some(40)),
            edit(2, "device-a/office", "Work/notes.txt", "v3", "v1", 40),
            edit(3, "device-b/office", "Work/notes.txt", "v2", "v1", 70),
        ];
        let on_a = |copy_in: u64| {
            BTreeMap::from([(
                "device-a".to_string(),
                disk(&[("Shared-office/Work/notes.txt", "v2", 40), ("Shared-office/Other/notes (conflicted copy).txt", "v3", copy_in)], &[40, 41]),
            )])
        };
        assert!(!check_custody(&records, &on_a(41)).ok);
        let verdict = check_custody(&records, &on_a(40));
        assert!(verdict.ok && verdict.detail.starts_with("1 content(s) judged"), "{}", verdict.detail);
    }

    #[test]
    fn an_edit_where_a_peer_moved_the_file_revives_no_earlier_placement() {
        // device-a wrote v1 into Work (40); device-b's user moved the file
        // into Other, which the engine applied on device-a with no record;
        // device-a's user then edited it there (41). The file is still
        // device-b's to place, so device-a's placement of v1 in 40 does not
        // come back: v1 surviving beside the file is not astray.
        let records = vec![
            placed(1, "device-a/office", "write", "Work/notes.txt", Some("v1"), Some(40)),
            placed(2, "device-b/office", "rename", "Work/notes.txt", None, Some(70)),
            placed(3, "device-b/office", "rename_into", "Other/notes.txt", Some("v1"), Some(71)),
            edit(4, "device-a/office", "Other/notes.txt", "v2", "v1", 41),
        ];
        let on_a = BTreeMap::from([(
            "device-a".to_string(),
            disk(&[("Shared-office/Other/notes.txt", "v2", 41), ("Shared-office/Other/notes (conflicted copy).txt", "v1", 41)], &[40, 41]),
        )]);
        let verdict = check_custody(&records, &on_a);
        assert!(verdict.ok, "{}", verdict.detail);
        // A touch there is a write over the file too.
        let mut touched = records.clone();
        touched[3] = edit(4, "device-a/office", "Other/notes.txt", "v1", "v1", 41);
        if let Record::ActorCommit { op, .. } = &mut touched[3] {
            *op = "touch".into();
        }
        let verdict = check_custody(&touched, &on_a);
        assert!(verdict.ok, "{}", verdict.detail);
    }

    #[test]
    fn a_users_own_folder_rename_leaves_what_stands_beneath_it_judged() {
        // device-a's user wrote it into P (directory 40) and then renamed P.
        // The rename changes no directory: the content is still held to 40,
        // and carried out of it, it is astray.
        let a = "device-a/office";
        let records = vec![
            placed(1, a, "write", "P/notes.txt", Some("cc"), Some(40)),
            placed(2, a, "rename", "P", None, Some(10)),
            placed(3, a, "rename_into", "Q", None, Some(10)),
        ];
        let carried = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Other/notes.txt", "cc", 41)], &[10, 40, 41]))]);
        assert!(!check_custody(&records, &carried).ok);
        let home = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Q/notes.txt", "cc", 40)], &[10, 40, 41]))]);
        let verdict = check_custody(&records, &home);
        assert!(verdict.ok && verdict.detail.starts_with("1 content(s) judged"), "{}", verdict.detail);
    }

    #[test]
    fn a_peers_folder_rename_leaves_no_stale_placement_to_bless_a_carry_back() {
        // device-a's user wrote it into P (directory 40). device-b's user
        // renamed P to P2, which the engine applied on device-a with no record
        // there. device-a's user then moved P2/notes.txt into P2/Sub (42). The
        // move withdraws the placement in 40 whatever 40 is called now, so the
        // engine carrying the content back into 40 is astray.
        let records = vec![
            placed(1, "device-a/office", "write", "P/notes.txt", Some("cc"), Some(40)),
            placed(2, "device-b/office", "rename", "P", None, Some(70)),
            placed(3, "device-b/office", "rename_into", "P2", None, Some(70)),
            placed(4, "device-a/office", "rename", "P2/notes.txt", None, Some(40)),
            placed(5, "device-a/office", "rename_into", "P2/Sub/notes.txt", Some("cc"), Some(42)),
        ];
        let back = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/P2/notes.txt", "cc", 40)], &[40, 42]))]);
        assert!(!check_custody(&records, &back).ok);
        let moved = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/P2/Sub/notes.txt", "cc", 42)], &[40, 42]))]);
        assert!(check_custody(&records, &moved).ok, "{}", check_custody(&records, &moved).detail);
    }

    #[test]
    fn a_folder_that_took_over_a_freed_inode_is_not_the_folder_the_user_chose() {
        // Written into Work (inode 40, born at 1000); its user then removed
        // Work, and a later folder took inode 40 over (born at 2000). The
        // folder the user chose is gone, so there is nothing to hold the
        // content to, wherever it stands.
        let records = vec![
            born(1, "device-a/office", "write", "Work/notes.txt", Some("cc"), 40, 1000),
            born(2, "device-a/office", "remove_dir", "Work", None, 10, 900),
        ];
        let mut later = disk(&[("Shared-office/Other/notes.txt", "cc", 41)], &[10, 41]);
        later.dirs.insert(40, Some(2000));
        let disks = BTreeMap::from([("device-a".to_string(), later.clone())]);
        let verdict = check_custody(&records, &disks);
        assert!(verdict.ok && verdict.detail.starts_with("0 content(s) judged"), "{}", verdict.detail);
        // The same inode with the same birth is the same folder, still held.
        later.dirs.insert(40, Some(1000));
        assert!(!check_custody(&records, &BTreeMap::from([("device-a".to_string(), later)])).ok);
    }

    #[test]
    fn a_delete_withdraws_the_placement_it_ends() {
        // device-b's user and then device-a's user each wrote the same bytes,
        // and device-a's user deleted its copy from directory 40. device-a
        // still has device-b's, downloaded into 41: device-a placed nothing
        // that still stands, so nothing is held to 40.
        let records = vec![
            placed(1, "device-b/office", "write", "V/same.txt", Some("cc"), Some(70)),
            placed(2, "device-a/office", "write", "W/same.txt", Some("cc"), Some(40)),
            placed(3, "device-a/office", "remove", "W/same.txt", None, Some(40)),
        ];
        let on_a = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/V/same.txt", "cc", 41)], &[40, 41]))]);
        let verdict = check_custody(&records, &on_a);
        assert!(verdict.ok && verdict.detail.starts_with("0 content(s) judged"), "{}", verdict.detail);
    }

    /// The engine said these contents' moves lost a race, at journal time `at`.
    fn lost_at(mut placement: Placement, shas: &[&str], at: u64) -> Placement {
        placement.lost_races = shas.iter().map(|s| (s.to_string(), at)).collect();
        placement
    }

    fn lost(placement: Placement, shas: &[&str]) -> Placement {
        lost_at(placement, shas, 6)
    }

    #[test]
    fn run_1660_a_move_race_is_judged_on_the_user_whose_move_the_server_kept() {
        // device-b's user wrote doc-2 into 70 and moved it into Sub 6 (72);
        // 61 ms later device-a's user, not having seen that, moved its copy
        // into a new Sub 5 (42). b's move reached the server first; a's engine
        // said a's move lost and put the file in its copy of Sub 6 (43).
        let records = vec![
            placed(1, "device-b/messy-human", "write", "Projects/doc-2.txt", Some("d2"), Some(70)),
            placed(2, "device-b/messy-human", "rename", "Projects/doc-2.txt", None, Some(70)),
            placed(3, "device-b/messy-human", "rename_into", "Projects/Sub 6/doc-2.txt", Some("d2"), Some(72)),
            placed(4, "device-a/messy-human", "rename", "Projects/doc-2.txt", None, Some(40)),
            placed(5, "device-a/messy-human", "rename_into", "Projects/Sub 5/doc-2.txt", Some("d2"), Some(42)),
        ];
        let on_a = disk(&[("Shared-messy-human/Projects/Sub 6/doc-2.txt", "d2", 43)], &[40, 42, 43]);
        let on_b = |dir| disk(&[("Shared-messy-human/Projects/Sub 6/doc-2.txt", "d2", dir)], &[70, 72]);
        let raced = BTreeMap::from([("device-a".to_string(), lost(on_a.clone(), &["d2"])), ("device-b".to_string(), on_b(72))]);
        let verdict = check_custody(&records, &raced);
        assert!(verdict.ok && verdict.detail.starts_with("1 content(s) judged"), "{}", verdict.detail);
        // No lost race on a's store: a placed it last, and it is not where a put it.
        let untold = BTreeMap::from([("device-a".to_string(), on_a.clone()), ("device-b".to_string(), on_b(72))]);
        let verdict = check_custody(&records, &untold);
        assert!(!verdict.ok && verdict.detail.contains("device-a"), "{}", verdict.detail);
        // The race told, but on the winner's disk the file is not where its
        // user put it: judged there, and it fails there.
        let carried = BTreeMap::from([("device-a".to_string(), lost(on_a, &["d2"])), ("device-b".to_string(), on_b(70))]);
        let verdict = check_custody(&records, &carried);
        assert!(!verdict.ok && verdict.detail.contains("device-b"), "{}", verdict.detail);
    }

    #[test]
    fn a_move_race_the_later_mover_won_is_judged_on_the_later_mover() {
        // The reverse: a's later move reached the server first, so b's store
        // holds the lost race, and a, the last placer, is judged as before.
        let records = vec![
            placed(1, "device-b/messy-human", "write", "Projects/doc-2.txt", Some("d2"), Some(70)),
            placed(2, "device-b/messy-human", "rename", "Projects/doc-2.txt", None, Some(70)),
            placed(3, "device-b/messy-human", "rename_into", "Projects/Sub 6/doc-2.txt", Some("d2"), Some(72)),
            placed(4, "device-a/messy-human", "rename", "Projects/doc-2.txt", None, Some(40)),
            placed(5, "device-a/messy-human", "rename_into", "Projects/Sub 5/doc-2.txt", Some("d2"), Some(42)),
        ];
        let on_b = lost(disk(&[("Shared-messy-human/Projects/Sub 5/doc-2.txt", "d2", 74)], &[70, 72, 74]), &["d2"]);
        let at_a = |dir| BTreeMap::from([
            ("device-a".to_string(), disk(&[("Shared-messy-human/Projects/Sub 5/doc-2.txt", "d2", dir)], &[40, 42, 43])),
            ("device-b".to_string(), on_b.clone()),
        ]);
        let verdict = check_custody(&records, &at_a(42));
        assert!(verdict.ok && verdict.detail.starts_with("1 content(s) judged"), "{}", verdict.detail);
        assert!(!check_custody(&records, &at_a(43)).ok);
    }

    #[test]
    fn a_lost_race_excuses_only_the_move_that_lost_never_the_losers_next() {
        // 1660, settled: the engine said a's move lost at 6. Later a's user
        // moves the file on, from its copy of Sub 6 (43) into Z (44).
        let records = vec![
            placed(1, "device-b/messy-human", "write", "Projects/doc-2.txt", Some("d2"), Some(70)),
            placed(2, "device-b/messy-human", "rename", "Projects/doc-2.txt", None, Some(70)),
            placed(3, "device-b/messy-human", "rename_into", "Projects/Sub 6/doc-2.txt", Some("d2"), Some(72)),
            placed(4, "device-a/messy-human", "rename", "Projects/doc-2.txt", None, Some(40)),
            placed(5, "device-a/messy-human", "rename_into", "Projects/Sub 5/doc-2.txt", Some("d2"), Some(42)),
            placed(900, "device-a/messy-human", "rename", "Projects/Sub 6/doc-2.txt", None, Some(43)),
            placed(901, "device-a/messy-human", "rename_into", "Projects/Z/doc-2.txt", Some("d2"), Some(44)),
        ];
        let m = |a: Placement, b: Placement| BTreeMap::from([("device-a".to_string(), a), ("device-b".to_string(), b)]);
        // The engine kept a's later move: Z on both disks.
        let right = m(
            lost(disk(&[("S/Projects/Z/doc-2.txt", "d2", 44)], &[40, 42, 43, 44]), &["d2"]),
            disk(&[("S/Projects/Z/doc-2.txt", "d2", 75)], &[70, 72, 75]),
        );
        let verdict = check_custody(&records, &right);
        assert!(verdict.ok, "a correct engine read as wrong: {}", verdict.detail);
        // The engine undid a's later move: back in Sub 6 on both disks.
        let undone = m(
            lost(disk(&[("S/Projects/Sub 6/doc-2.txt", "d2", 43)], &[40, 42, 43, 44]), &["d2"]),
            disk(&[("S/Projects/Sub 6/doc-2.txt", "d2", 72)], &[70, 72]),
        );
        let verdict = check_custody(&records, &undone);
        assert!(!verdict.ok && verdict.detail.contains("device-a"), "an undone move hidden: {}", verdict.detail);
        // A second race the later move lost, said after it, is excused again.
        let lost_again = m(
            lost_at(disk(&[("S/Projects/Sub 6/doc-2.txt", "d2", 43)], &[40, 42, 43, 44]), &["d2"], 902),
            disk(&[("S/Projects/Sub 6/doc-2.txt", "d2", 72)], &[70, 72]),
        );
        assert!(check_custody(&records, &lost_again).ok, "{}", check_custody(&records, &lost_again).detail);
    }

    #[test]
    fn a_users_own_later_move_or_a_gone_folder_is_not_misplacement() {
        // The user moved it on: the rename journals its new folder.
        let moved = vec![
            placed(1, "device-a/office", "write", "Work/notes.txt", Some("cc"), Some(40)),
            placed(2, "device-a/office", "rename", "Work/notes.txt", None, Some(40)),
            placed(3, "device-a/office", "rename_into", "Other/notes.txt", Some("cc"), Some(41)),
        ];
        let there = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Other/notes.txt", "cc", 41)], &[40, 41]))]);
        assert!(check_custody(&moved, &there).ok, "{}", check_custody(&moved, &there).detail);
        // The folder it was put in is gone: nothing to hold it to.
        let written = vec![placed(1, "device-a/office", "write", "Work/notes.txt", Some("cc"), Some(40))];
        let folder_gone = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Other/notes.txt", "cc", 41)], &[41]))]);
        assert!(check_custody(&written, &folder_gone).ok);
        // A peer's device is not judged by device-a's write.
        let on_b = BTreeMap::from([("device-b".to_string(), disk(&[("Shared-office/Other/notes.txt", "cc", 77)], &[77, 78]))]);
        assert!(check_custody(&written, &on_b).ok);
    }

    #[test]
    fn a_peers_user_moving_the_content_on_is_not_misplacement() {
        // device-b wrote it into directory 40; device-a's user then moved the
        // same bytes into a folder of its own. On device-b the engine carries
        // it to that folder, directory 41 there, while 40 still stands.
        let records = vec![
            placed(1, "device-b/office", "write", "Work/notes.txt", Some("cc"), Some(40)),
            placed(2, "device-a/office", "rename", "Work/notes.txt", None, Some(70)),
            placed(3, "device-a/office", "rename_into", "Other/notes.txt", Some("cc"), Some(90)),
        ];
        let on_b = BTreeMap::from([("device-b".to_string(), disk(&[("Shared-office/Other/notes.txt", "cc", 41)], &[40, 41]))]);
        assert!(check_custody(&records, &on_b).ok, "{}", check_custody(&records, &on_b).detail);
        // Without the peer's move, the same disk is a misplacement.
        let alone = vec![placed(1, "device-b/office", "write", "Work/notes.txt", Some("cc"), Some(40))];
        assert!(!check_custody(&alone, &on_b).ok);
    }

    #[test]
    fn a_rename_in_place_after_a_peers_move_chooses_no_folder() {
        // device-a wrote it into directory 40; device-b's user moved it into a
        // folder of its own; device-a's user, not yet told, renamed it where
        // it stood. The engine keeps both: the peer's folder, this name.
        let records = vec![
            placed(1, "device-a/office", "write", "Work/notes.txt", Some("cc"), Some(40)),
            placed(2, "device-b/office", "rename", "Work/notes.txt", None, Some(90)),
            placed(3, "device-b/office", "rename_into", "Other/notes.txt", Some("cc"), Some(91)),
            placed(4, "device-a/office", "rename", "Work/notes.txt", None, Some(40)),
            placed(5, "device-a/office", "rename_into", "Work/NOTES.TXT", Some("cc"), Some(40)),
        ];
        let on_a = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Other/NOTES.TXT", "cc", 41)], &[40, 41]))]);
        assert!(check_custody(&records, &on_a).ok, "{}", check_custody(&records, &on_a).detail);
        // Without the peer's move, the same disk is a misplacement.
        let alone: Vec<Record> = records.iter().filter(|r| !matches!(r, Record::ActorCommit { actor, .. } if actor.starts_with("device-b"))).cloned().collect();
        assert!(!check_custody(&alone, &on_a).ok);
        // The writer swapped: device-b's user wrote it, device-a has it by
        // download and its user renames it in place. The rename record says
        // which directory it left.
        let downloaded = vec![
            placed(1, "device-b/office", "write", "Work/notes.txt", Some("cc"), Some(80)),
            placed(2, "device-b/office", "rename", "Work/notes.txt", None, Some(80)),
            placed(3, "device-b/office", "rename_into", "Other/notes.txt", Some("cc"), Some(81)),
            placed(4, "device-a/office", "rename", "Work/notes.txt", None, Some(40)),
            placed(5, "device-a/office", "rename_into", "Work/NOTES.TXT", Some("cc"), Some(40)),
        ];
        assert!(check_custody(&downloaded, &on_a).ok, "{}", check_custody(&downloaded, &on_a).detail);
        // And a rename into another folder is a placement of its own.
        let mut moved = records.clone();
        moved[4] = placed(5, "device-a/office", "rename_into", "Mine/NOTES.TXT", Some("cc"), Some(42));
        let on_a = BTreeMap::from([("device-a".to_string(), disk(&[("Shared-office/Other/NOTES.TXT", "cc", 41)], &[40, 41, 42]))]);
        assert!(!check_custody(&moved, &on_a).ok);
    }

    #[test]
    fn a_no_loss_check_that_could_not_judge_a_fifth_of_its_claims_says_so() {
        let narrowed = check_no_loss_coverage(Coverage { claims: 60, at_dead_paths: 13 });
        assert!(!narrowed.ok, "{}", narrowed.detail);
        assert!(narrowed.detail.contains("judged 47 of 60"), "{}", narrowed.detail);
        assert!(check_no_loss_coverage(Coverage { claims: 60, at_dead_paths: 11 }).ok);
        assert!(check_no_loss_coverage(Coverage { claims: 20, at_dead_paths: 9 }).ok, "a handful is not an alarm");
    }

    fn store(tag: &str, rows: &[(&str, i64, Option<i64>, &str, Option<i64>, Option<&str>, Option<i64>, i64)]) -> std::path::PathBuf {
        let dir = std::env::temp_dir().join(format!("jd-soak-store-{tag}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let db = dir.join("state.db");
        let conn = rusqlite::Connection::open(&db).unwrap();
        conn.execute_batch(
            "CREATE TABLE entries (entity_type TEXT, server_id INTEGER, parent_folder_id INTEGER, remote_name TEXT,
             local_name TEXT, remote_deleted INTEGER, synced_parent_id INTEGER, synced_name TEXT,
             stand_in_parent_id INTEGER, stand_in_name TEXT, synced_fp_file_id INTEGER)",
        )
        .unwrap();
        for (kind, id, parent, name, synced_parent, synced_name, dir_id, deleted) in rows {
            conn.execute(
                "INSERT INTO entries VALUES (?1, ?2, ?3, ?4, NULL, ?5, ?6, ?7, NULL, NULL, ?8)",
                rusqlite::params![kind, id, parent, name, deleted, synced_parent, synced_name, dir_id],
            )
            .unwrap();
        }
        db
    }

    #[test]
    fn a_store_with_a_stranded_record_or_a_directory_held_twice_fails() {
        let p = Personality::linux();
        let stranded = store("stranded", &[("folder", 1, None, "A", None, Some("A"), Some(100), 0), ("file", 10, Some(2), "x.txt", Some(2), Some("x.txt"), Some(5), 0)]);
        let found = store_findings(&stranded, &p).unwrap();
        assert!(found.iter().any(|f| f.contains("stranded")), "{found:?}");
        let by_id = store("byid", &[("folder", 1, None, "A", None, Some("A"), Some(100), 0), ("folder", 2, None, "B", None, Some("B"), Some(100), 0)]);
        assert!(store_findings(&by_id, &p).unwrap().iter().any(|f| f.contains("directory 100")));
        let by_slot = store("byslot", &[("folder", 1, None, "A", None, Some("A"), Some(100), 0), ("folder", 2, None, "A", None, Some("A"), Some(101), 0)]);
        assert!(store_findings(&by_slot, &p).unwrap().iter().any(|f| f.contains("both claim")));
        let verdict = check_stores(&[("device-a".into(), by_id)], &p);
        assert!(!verdict.ok, "{}", verdict.detail);
        // A server-deleted record is a tombstone, not a hole; and a whole store is clean.
        let whole = store("whole", &[("folder", 1, None, "A", None, Some("A"), Some(100), 0), ("file", 10, Some(1), "x.txt", Some(1), Some("x.txt"), Some(5), 0), ("file", 11, Some(9), "gone.txt", Some(9), Some("gone.txt"), Some(6), 1)]);
        assert!(store_findings(&whole, &p).unwrap().is_empty());
        assert!(check_stores(&[("device-a".into(), whole)], &p).ok);
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
            coverage: Default::default(),
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
    fn the_confirmation_window_covers_a_whole_change_feed_poll() {
        // A device with an empty queue may simply not have ASKED what changed
        // yet, and there is no push channel to tell it. Anything shorter than a
        // full poll cannot tell that apart from being finished.
        let w = confirmation_window(30, Duration::from_secs(900));
        assert!(
            w >= Duration::from_secs(30),
            "a window shorter than one poll believes a device that has not looked"
        );
    }

    fn quiet_status(last_pass_ms: Option<u64>) -> Status {
        Status {
            indicator: "green".into(),
            summary: "up to date".into(),
            tracked: 3,
            settled: 3,
            pending_ops: 0,
            waiting_for_keys: 0,
            cursor: 100,
            last_pass_ms,
            blocker: None,
            entries: BTreeMap::from([("synced".to_string(), 3u64)]),
            issues: Vec::new(),
            issues_total: 0,
        }
    }

    #[test]
    fn a_device_that_has_not_looked_since_the_storm_is_not_settled() {
        // The exact shape run 107 settled on: a device reporting green with an
        // empty queue, whose last pass was during the storm. A minute after the
        // fleet was called converged it noticed a folder had been removed and
        // trashed it on the server -- and by then the audit had compared a disk
        // against a server that still had it.
        let began = 1_000_000u64;
        assert!(
            !has_looked_since(Some(&quiet_status(Some(began - 1))), began),
            "a pass that finished before the settle began describes the storm"
        );
        assert!(
            !has_looked_since(Some(&quiet_status(None)), began),
            "a device that has never passed has certainly not looked"
        );
        assert!(
            !has_looked_since(None, began),
            "and one that did not answer at all has not either"
        );
        assert!(
            has_looked_since(Some(&quiet_status(Some(began + 1))), began),
            "a pass after the settle began is the device saying it looked and found nothing"
        );
    }

    #[test]
    fn a_short_settle_still_gets_to_run() {
        // The window is a floor on quiet, not a licence to spend the whole
        // budget confirming: a two-second deadline must not demand thirty-five.
        let deadline = Duration::from_secs(2);
        assert!(
            confirmation_window(30, deadline) <= deadline / 3,
            "confirming must leave most of the deadline for actually settling"
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

#[cfg(test)]
mod frozen {
    //! Replaying a frozen campaign's journal against its frozen trees.
    //!
    //! The oracle's failures are all about paths, and a path is only wrong
    //! relative to a real tree — so the only honest way to measure a change to
    //! it is against evidence a real campaign left behind. Point it at a
    //! `/root/run<N>-*` directory pulled off the rig:
    //!
    //! ```text
    //! FROZEN=/path/to/run255-loss cargo test -p jd-soak frozen -- --ignored --nocapture
    //! ```
    //!
    //! It wants `journal/` and a `trees.txt` beside it (`tar tzf trees.tar.gz >
    //! trees.txt`). Prints what would be judged, what would be reported and what
    //! names a path nothing is at.
    use super::*;

    #[test]
    #[ignore] // needs frozen evidence; run it by name with FROZEN set
    fn replay_a_frozen_campaign() {
        let Ok(dir) = std::env::var("FROZEN") else {
            eprintln!("set FROZEN=/path/to/run<N>-loss");
            return;
        };
        let dir = Path::new(&dir);
        let records = journal::read_dir(&dir.join("journal")).expect("journal");
        let listing = std::fs::read_to_string(dir.join("trees.txt")).expect("trees.txt");
        let personality = Personality::linux();

        // `device-a/root/Shared-messy-human/x` is the same place a claim calls
        // `Shared-messy-human/x`; the device prefix is the tar's, not the tree's.
        let standing: BTreeSet<String> = listing
            .lines()
            .filter(|l| !l.ends_with('/'))
            .filter_map(|l| l.split_once("/root/").map(|(_, rest)| rest))
            .map(|rest| tree::key_for(rest, &personality))
            .collect();

        let latest = journal::last_committed(&records);
        let mut dead = 0usize;
        let mut live = 0usize;
        for claim in latest.values() {
            if standing.contains(&standing_key(claim, &personality)) {
                live += 1;
            } else {
                dead += 1;
            }
        }
        eprintln!(
            "FROZEN {}: {} records, {} claims — {live} at a live path, {dead} at a path nothing is at",
            dir.display(),
            records.len(),
            latest.len()
        );
        // The one the run actually failed on. Naming it is the whole point: a
        // bulk count says nothing about whether the guard catches the claim that
        // cost the session.
        if let Ok(claimed) = std::env::var("CLAIM") {
            match latest.get(&claimed) {
                None => eprintln!("CLAIM {claimed}: no such claim"),
                Some(c) => {
                    let key = standing_key(c, &personality);
                    eprintln!(
                        "CLAIM {claimed}: sha {} — key {key} is {}",
                        &c.sha256[..12],
                        if standing.contains(&key) { "LIVE (still judged)" } else { "DEAD (dropped)" }
                    );
                }
            }
        }
    }

    /// Custody, replayed on a frozen snapshot: the journal as it stood, and
    /// each device's placement rebuilt from its state store (every live file
    /// in the directory its folder record holds, every folder by the inode and
    /// birth its record holds). A settled store says what its disk holds; the
    /// replay is for telling the check's own errors from the engine's, not
    /// for judging a run.
    ///
    /// ```text
    /// FROZEN=/root/soak-evidence/run1618-*/violation-cycle-5-<ms> cargo test -p jd-soak custody_on_a -- --ignored --nocapture
    /// ```
    ///
    /// It wants `journal/` and `device-<x>/state.db` or `device-<x>-state.db`
    /// beside it.
    #[test]
    #[ignore] // needs frozen evidence; run it by name with FROZEN set
    fn custody_on_a_frozen_snapshot() {
        let Ok(dir) = std::env::var("FROZEN") else {
            eprintln!("set FROZEN=/path/to/a snapshot");
            return;
        };
        let dir = Path::new(&dir);
        let records = journal::read_dir(&dir.join("journal")).expect("journal");
        let mut placements = BTreeMap::new();
        for device in ["device-a", "device-b", "device-c"] {
            let db = [dir.join(device).join("state.db"), dir.join(format!("{device}-state.db"))].into_iter().find(|p| p.exists());
            if let Some(db) = db {
                placements.insert(device.to_string(), placement_from_store(&db).expect("state store"));
            }
        }
        let verdict = check_custody(&records, &placements);
        println!("CUSTODY {} {}: {}", dir.display(), if verdict.ok { "PASS" } else { "FAIL" }, verdict.detail);
    }

    fn placement_from_store(db: &Path) -> Result<Placement, rusqlite::Error> {
        let conn = rusqlite::Connection::open_with_flags(db, rusqlite::OpenFlags::SQLITE_OPEN_READ_ONLY)?;
        let mut out = Placement::default();
        let mut folders: BTreeMap<i64, u64> = BTreeMap::new();
        let mut q = conn.prepare(
            "SELECT server_id, synced_fp_file_id, synced_fp_birth_ns FROM entries
             WHERE entity_type = 'folder' AND remote_deleted = 0 AND synced_fp_file_id IS NOT NULL",
        )?;
        for row in q.query_map([], |r| Ok((r.get::<_, i64>(0)?, r.get::<_, i64>(1)?, r.get::<_, Option<i64>>(2)?)))? {
            let (id, inode, birth) = row?;
            folders.insert(id, inode as u64);
            out.dirs.insert(inode as u64, birth.map(|b| b as u64));
        }
        let mut q = conn.prepare(
            "SELECT remote_name, COALESCE(last_seen_sha256, synced_content_sha256), parent_folder_id FROM entries
             WHERE entity_type = 'file' AND remote_deleted = 0 AND parent_folder_id IS NOT NULL",
        )?;
        for row in q.query_map([], |r| Ok((r.get::<_, String>(0)?, r.get::<_, Option<String>>(1)?, r.get::<_, i64>(2)?)))? {
            let (name, sha, parent) = row?;
            if let (Some(sha), Some(inode)) = (sha, folders.get(&parent)) {
                out.files.push((name, sha, *inode));
            }
        }
        out.lost_races = lost_move_races(db);
        Ok(out)
    }
}
