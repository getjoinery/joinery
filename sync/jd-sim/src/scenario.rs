//! Running a whole situation, and checking the two things that matter.
//!
//! A scenario is: some computers, some users doing things to files, a network
//! misbehaving, and processes dying at inconvenient moments. It ends by letting
//! everything settle and then asking two questions.
//!
//! **1. Did they converge?** Once the dust has settled, every device's disk and
//! the server agree about what exists and what is in it.
//!
//! **2. Was anything lost?** Every version that was ever committed is still
//! reachable — as the live file, as a version in the server's history, in a
//! trash, or as a conflict copy beside its winner.
//!
//! The second question is the one that actually matters. A sync engine that
//! fails to converge is annoying and visible; someone notices and complains. A
//! sync engine that loses a file is unforgivable and silent, and the person it
//! happens to finds out months later. So the mock server keeps every version it
//! has ever been given — it is the oracle, and it is why "was anything lost" is
//! a question with a checkable answer rather than a hope.
//!
//! ## Settling
//!
//! There is no "the sync is finished" event, so settling is a fixed point: run
//! passes until nothing changes for a whole round of every device, or give up
//! after a bound. Giving up is itself a finding — a client that never settles
//! is a client that never stops using the network.

use std::collections::BTreeMap;

use jd_core::execute::ExecEnv;
use jd_core::model::EntityId;
use jd_core::pass::run_pass;
use jd_core::reconcile::Context;
use jd_core::round::DeletePolicy;

use crate::clock::SimClock;
use crate::engine::{env, Device};
use crate::rng::SimRng;
use crate::server::MockServer;

/// How many passes a device gets before a scenario calls it stuck. Generous:
/// a pass moves one round's worth of work, and a chaotic run legitimately needs
/// many of them because most attempts fail.
pub const MAX_PASSES: usize = 400;

/// A situation: a server, a clock, and the computers attached to it.
pub struct World {
    pub clock: SimClock,
    pub server: MockServer,
    pub devices: Vec<Device>,
    pub rng: SimRng,
}

impl World {
    pub fn new(seed: u64, device_names: &[&str]) -> World {
        let clock = SimClock::new();
        let server = MockServer::new(clock.clone());
        let devices = device_names
            .iter()
            .enumerate()
            .map(|(i, name)| {
                Device::new(name, &server, clock.clone(), seed ^ ((i as u64 + 1) << 8))
            })
            .collect();
        World {
            clock,
            server,
            devices,
            rng: SimRng::new(seed),
        }
    }

    pub fn device(&self, name: &str) -> &Device {
        self.devices
            .iter()
            .find(|d| d.name == name)
            .unwrap_or_else(|| panic!("no device called {name}"))
    }

    /// One pass on one device, with custody checked around it.
    ///
    /// This is where the no-loss invariant is actually enforced, and it is
    /// enforced continuously rather than at the end. Anything that was on this
    /// disk when the pass began and is not on it when the pass ends was removed
    /// **by the engine**, and must therefore still be reachable somewhere: the
    /// server, another device, a trash, a conflict copy.
    ///
    /// Checking it here rather than at the end of a scenario is what makes it
    /// mean something. A user overwriting their own file between passes is not
    /// a loss and never registers as one, because the snapshot is taken after
    /// they did it. What is left is exactly the engine's own removals.
    pub fn pass(&self, device: &Device) -> jd_core::pass::PassOutcome {
        let before = held_by(device);
        let outcome = self.run_pass_on(device);
        let after = held_by(device);
        for hash in &before {
            if after.contains(hash) {
                continue;
            }
            assert!(
                locate(self, hash).is_some(),
                "{} removed content during a pass and it is now nowhere a person could look ({})",
                device.name,
                &hash[..12]
            );
        }
        outcome
    }

    fn run_pass_on(&self, device: &Device) -> jd_core::pass::PassOutcome {
        let ctx = Context {
            date: "2026-07-31".into(),
            device_name: device.name.clone(),
            conflict_suffix: 1,
        };
        let now = device.now();
        let e: ExecEnv = env(device, &now);
        let mut keys = device.key_source();
        let mut tokens = |id: EntityId| format!("{}-{}", device.name, id.server_id.abs());
        // A pass that errors is a finding, not a crash: report it as a pass that
        // did nothing, and let settling time out if it never recovers.
        run_pass(&e, &ctx, DeletePolicy::Guard, &mut keys, &mut tokens).unwrap_or_default()
    }

    /// Run passes on every device until nothing changes any more.
    ///
    /// Returns how many rounds it took, or `None` if it never settled — which
    /// is a failure worth reporting rather than a timeout to shrug at.
    pub fn settle(&self) -> Option<usize> {
        let mut quiet_rounds = 0;
        for round in 1..=MAX_PASSES {
            let mut any_work = false;
            for device in &self.devices {
                // Time moves between passes so backoffs elapse. Without this a
                // scenario with any retry in it would deadlock against its own
                // exponential backoff and look like a convergence failure.
                self.clock.advance_secs(20 * 60);
                let outcome = self.pass(device);
                if !outcome.quiet() {
                    any_work = true;
                }
            }
            if any_work {
                quiet_rounds = 0;
            } else {
                quiet_rounds += 1;
                // Two quiet rounds, because one device can go quiet while
                // another is still pushing work that will wake it again.
                if quiet_rounds >= 2 {
                    return Some(round);
                }
            }
        }
        None
    }
}

// ---------------------------------------------------------------------------
// Invariant 1: convergence
// ---------------------------------------------------------------------------

/// Every content hash currently on a device's disk.
fn held_by(device: &Device) -> Vec<String> {
    device
        .fs
        .all_paths()
        .iter()
        .filter_map(|p| device.fs.peek(p))
        .map(|b| crate::sha256_hex(&b))
        .collect()
}

/// What is actually on a device's disk: path → content hash (`None` for a
/// folder). Conflict copies are included — they are real files a user can see.
pub fn disk_tree(device: &Device) -> BTreeMap<String, Option<String>> {
    let mut out = BTreeMap::new();
    for path in device.fs.all_paths() {
        if path.is_empty() || jd_vfs::is_internal(&path) {
            continue;
        }
        let hash = device.fs.peek(&path).map(|b| crate::sha256_hex(&b));
        out.insert(path, hash);
    }
    out
}

/// The server's live tree in the same shape.
pub fn server_tree(server: &MockServer) -> BTreeMap<String, Option<String>> {
    server.tree()
}

/// Every device agrees with the server, and with each other.
///
/// Panics with the difference rather than a bare false, because "they did not
/// converge" is not a useful thing to read at three in the morning.
pub fn assert_converged(world: &World) {
    let server = server_tree(&world.server);
    for device in &world.devices {
        let disk = disk_tree(device);
        if disk != server {
            let only_disk: Vec<_> = disk.keys().filter(|k| !server.contains_key(*k)).collect();
            let only_server: Vec<_> = server.keys().filter(|k| !disk.contains_key(*k)).collect();
            let differing: Vec<_> = disk
                .iter()
                .filter(|(k, v)| server.get(*k).map(|s| s != *v).unwrap_or(false))
                .map(|(k, _)| k)
                .collect();
            panic!(
                "{} did not converge with the server\n  only on the disk: {only_disk:?}\n  only on the server: {only_server:?}\n  same path, different content: {differing:?}",
                device.name
            );
        }
    }
}

// ---------------------------------------------------------------------------
// Invariant 2: nothing committed is ever lost
// ---------------------------------------------------------------------------

/// Every content hash a scenario ever deliberately created.
///
/// A scenario records what it wrote here. Anything in this set has to still be
/// findable somewhere at the end — that is the whole promise.
#[derive(Debug, Default, Clone)]
pub struct Committed {
    pub hashes: Vec<(String, String)>,
}

impl Committed {
    /// Note that a user deliberately created this content at this path.
    pub fn note(&mut self, path: &str, bytes: &[u8]) {
        let hash = crate::sha256_hex(bytes);
        if !self.hashes.iter().any(|(h, _)| *h == hash) {
            self.hashes.push((hash, path.to_string()));
        }
    }
}

/// Where a piece of content was found. Any of these counts as not lost — a user
/// can reach all of them.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FoundIn {
    LiveOnServer,
    ServerHistory,
    OnADisk,
    InATrash,
}

/// Look for one hash everywhere it could legitimately be.
pub fn locate(world: &World, hash: &str) -> Option<FoundIn> {
    // The server's version history is the oracle: it is never pruned, so
    // anything that was ever committed is in it.
    if world.server.all_versions().iter().any(|v| v.sha256 == hash) {
        return Some(FoundIn::ServerHistory);
    }
    if world.server.blob(hash).is_some() {
        return Some(FoundIn::LiveOnServer);
    }
    for device in &world.devices {
        for path in device.fs.all_paths() {
            if device
                .fs
                .peek(&path)
                .map(|b| crate::sha256_hex(&b))
                .as_deref()
                == Some(hash)
            {
                return Some(FoundIn::OnADisk);
            }
        }
        for (_, bytes) in device.fs.trashed() {
            if bytes.map(|b| crate::sha256_hex(&b)).as_deref() == Some(hash) {
                return Some(FoundIn::InATrash);
            }
        }
    }
    None
}

/// Nothing a user committed has disappeared.
pub fn assert_nothing_lost(world: &World, committed: &Committed) {
    let mut lost = Vec::new();
    for (hash, path) in &committed.hashes {
        if locate(world, hash).is_none() {
            lost.push(format!("{path} ({})", &hash[..12]));
        }
    }
    assert!(
        lost.is_empty(),
        "content that a user committed is gone from everywhere a person could look: {lost:?}"
    );
}

/// Both invariants, which is what every scenario ends with.
pub fn assert_invariants(world: &World, committed: &Committed) {
    // Order matters for readability: a loss is the more serious finding, so it
    // is the one reported first when both fail.
    assert_nothing_lost(world, committed);
    assert_converged(world);
}
