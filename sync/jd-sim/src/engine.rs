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
use jd_core::vault::Vault;

use crate::clock::SimClock;
use crate::net::SimNet;
use crate::rng::SimRng;
use crate::server::MockServer;
use crate::vfs::MemFs;

/// One computer in a scenario.
/// Builds the name a rescued copy is kept under, given the original name and
/// the suffix that disambiguates repeats within one day.
type ConflictNamer = Box<dyn Fn(&str, u32) -> String + Send + Sync>;

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
    namer: ConflictNamer,
    /// The key for encrypted folders, if this device was linked with them. A
    /// device without one is the ordinary case and gets simulated as such.
    vault: Option<Vault>,
}

/// A vault identity for a scenario: the secret half a device is given, and the
/// public half grants are sealed to.
///
/// Deterministic from a seed like everything else here, so a run that finds a
/// key-handling bug can be replayed. Real key material and real sealing — the
/// engine must not be able to tell it is in a simulation, and a stub that
/// always opened would test the one thing that cannot be allowed to be a stub.
#[derive(Debug, Clone)]
pub struct SimVault {
    pub secret_key_pkcs8: Vec<u8>,
    pub public_key_b64: String,
}

impl SimVault {
    pub fn new(seed: u64) -> SimVault {
        let mut rng = SimRng::new(seed);
        let mut scalar = [0u8; 32];
        for slot in scalar.chunks_mut(8) {
            let word = rng.next_u64().to_le_bytes();
            slot.copy_from_slice(&word[..slot.len()]);
        }
        let secret_key_pkcs8 = jd_crypto::pkcs8::encode(&scalar);
        let public_key_b64 = jd_crypto::vault::public_key_from_secret_key(&secret_key_pkcs8)
            .expect("a key just built in the PKCS8 shape");
        SimVault {
            secret_key_pkcs8,
            public_key_b64,
        }
    }

    /// Seal a fresh content key to this vault — one file's grant, exactly as the
    /// browser produces it when it shares an encrypted file.
    pub fn grant(&self, file_key: &jd_crypto::drive::FileKey) -> String {
        jd_crypto::drive::wrap_file_key_to(file_key, &self.public_key_b64)
            .expect("a public key this type derived itself")
    }
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
                Box::new(move |n: &str, suffix: u32| {
                    jd_vfs::conflict_copy_name(n, "2026-07-31", &device, suffix)
                })
            },
            vault: None,
        }
    }

    /// Link this device with encrypted folders enabled.
    ///
    /// A builder rather than a constructor argument, because most scenarios have
    /// nothing to do with encryption and a device without a vault is the state
    /// worth having as the default — it is what every device is until somebody
    /// turns the feature on.
    pub fn with_vault(mut self, vault: &SimVault) -> Device {
        self.set_vault(vault);
        self
    }

    /// The same thing, on a device a scenario is already holding.
    pub fn set_vault(&mut self, vault: &SimVault) {
        self.vault = Some(
            Vault::from_secret_key(&vault.secret_key_pkcs8).expect("a well-formed simulated vault"),
        );
    }

    /// Take the key away again: the vault is locked on this device from the
    /// next pass on, exactly as a device linked without one.
    pub fn lock_vault(&mut self) {
        self.vault = None;
    }

    pub fn vault(&self) -> Option<&Vault> {
        self.vault.as_ref()
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
        vault: device.vault.as_ref(),
    }
}
