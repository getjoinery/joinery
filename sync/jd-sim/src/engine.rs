//! One simulated computer running the real engine.
//!
//! A [`Device`] is a virtual disk, a network with a fault profile, and a state
//! store, wired into the [`ExecEnv`] that `jd-core` executes against. Nothing
//! here reimplements the engine — every byte moved in a scenario goes through
//! the same code that ships.
//!
//! **Restarting** is the operation this type exists for. A kill is not a thing
//! the filesystem does; it is the scheduler stopping the process. So the
//! simulator kills a device by dropping what the process was holding and
//! building a fresh [`ExecEnv`] over the same disk and the same journal — which
//! is precisely what the user's machine does when it comes back on.

use std::cell::RefCell;

use jd_core::execute::ExecEnv;
use jd_core::store::Store;

use crate::clock::SimClock;
use crate::net::SimNet;
use crate::rng::SimRng;
use crate::server::MockServer;
use crate::vfs::MemFs;

/// One computer in a scenario.
pub struct Device {
    pub name: String,
    pub fs: MemFs,
    pub net: SimNet,
    /// The last-agreed state. Survives a restart, because on a real machine it
    /// is a file on the disk that just came back.
    pub store: Store,
    clock: SimClock,
    keys: RefCell<SimRng>,
    /// How this device names a copy it had to keep out of the way.
    namer: Box<dyn Fn(&str) -> String + Send + Sync>,
}

impl Device {
    pub fn new(name: &str, server: &MockServer, clock: SimClock, seed: u64) -> Device {
        Device {
            name: name.to_string(),
            fs: MemFs::linux(clock.clone()),
            net: SimNet::new(server.clone(), clock.clone(), seed, name),
            store: Store::open_in_memory().expect("in-memory store"),
            clock,
            keys: RefCell::new(SimRng::new(seed ^ 0x5EED)),
            namer: {
                let device = name.to_string();
                Box::new(move |n: &str| jd_vfs::conflict_copy_name(n, "2026-07-31", &device, 1))
            },
        }
    }

    /// A device with a filesystem of a chosen personality — the Windows naming
    /// rules exercised on Linux, or a filesystem with second-granularity mtimes.
    pub fn with_fs(mut self, fs: MemFs) -> Device {
        self.fs = fs;
        self
    }

    /// Idempotency keys, from the device's own stream so a scenario replays
    /// identically from its seed.
    pub fn next_key(&self) -> String {
        format!("{}-{}", self.name, self.keys.borrow_mut().token())
    }

    /// A key generator that can be handed to `jd_core::journal`.
    pub fn key_source(&self) -> impl FnMut() -> String + '_ {
        move || self.next_key()
    }

    /// The clock, as the executor takes it.
    pub fn now(&self) -> impl Fn() -> u64 + '_ {
        let clock = self.clock.clone();
        move || clock.now_ms()
    }
}

/// Build the environment the executor runs in.
///
/// A free function rather than a method because `ExecEnv` borrows the clock
/// closure, and the caller has to own that for as long as the environment
/// lives.
pub fn env<'a>(device: &'a Device, now: &'a dyn Fn() -> u64) -> ExecEnv<'a> {
    ExecEnv {
        store: &device.store,
        vfs: &device.fs,
        api: &device.net,
        now_ms: now,
        conflict_name: &device.namer,
    }
}
