//! jd-sim — the world the sync engine is tested in.
//!
//! The reliability claim for this product is not "we wrote it carefully". It is
//! that the engine has been run through hundreds of thousands of hostile
//! situations, each one reproducible from a seed, and came out with the user's
//! files intact. That claim needs somewhere for those situations to happen.
//!
//! The approach is the one FoundationDB used and Dropbox used for Nucleus:
//! **simulate the world, not the code paths.** A test that calls a function and
//! checks its return value proves that function. A simulation that gives the
//! real engine a filesystem, a network, a clock, and a server — all of them
//! fake, all of them adversarial, none of them known to the engine — proves the
//! engine. Everything the engine touches arrives through a trait, so nothing in
//! it needs to know it is being simulated, and there is no test-only branch to
//! be wrong in.
//!
//! The pieces:
//!
//! - [`clock`] — time that only moves when the scenario says so, and can move
//!   backwards.
//! - [`rng`] — one seed, one run, forever. A frozen seed is a permanent
//!   reproducer.
//! - [`vfs`] — a filesystem that can be case-insensitive, decompose names,
//!   recycle inodes, lie about mtimes, and fill up mid-write.
//! - [`server`] — a working implementation of the Part-I contract, which also
//!   remembers every version ever committed. That memory is the oracle.
//! - [`net`] — the network, including the failure that matters most: the work
//!   was done and the answer never came back.
//! - [`engine`] — one simulated computer with all of the above wired into the
//!   real executor, and a restart that keeps the disk and the journal while
//!   throwing away everything the process was holding.
//!
//! Two invariants get asserted after every scenario, and they are the whole
//! point:
//!
//! 1. **Both replicas converge** — once the dust settles, the disk and the
//!    server agree.
//! 2. **No committed content is ever lost** — every version that was ever
//!    real still exists somewhere a person can reach: the live file, the
//!    server's history, a trash, or a conflict copy.
//!
//! The second one is the one that actually matters. A sync engine that fails to
//! converge is annoying and visible. A sync engine that loses a file is
//! unforgivable and silent.

pub mod clock;
pub mod engine;
pub mod net;
pub mod rng;
pub mod scenario;
pub mod server;
pub mod vfs;

pub use clock::SimClock;
pub use engine::Device;
pub use net::{NetFaults, NetStats, SimNet};
pub use rng::SimRng;
pub use scenario::{assert_invariants, Committed, Platform, World};
pub use server::{sha256_hex, MockServer, VersionRow};
pub use vfs::{FailureKind, FsOp, MemFs};
