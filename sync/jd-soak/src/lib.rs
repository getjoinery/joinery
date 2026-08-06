//! jd-soak — the soak and chaos rig for the sync client
//! (`specs/drive_sync_soak.md`).
//!
//! `jd-sim` proves the engine's *logic* is right: every decision-matrix cell,
//! reproducible from a seed, in milliseconds. It cannot see a real kernel, a
//! real server, or real time. This crate is the other half of the verification
//! story — the shipping daemon, unmodified, on real filesystems, against a real
//! Joinery instance, driven by application write patterns that have historically
//! broken every sync client on the market, with real faults injected on a
//! schedule, for weeks.
//!
//! Five parts, and the split between them is the design:
//!
//! - [`persona`] decides what a program would do. Pure; no disk.
//! - [`actor`] does it and writes down what happened.
//! - [`chaos`] breaks things and writes down what it broke.
//! - [`verify`] settles the world and checks the invariants **without asking
//!   the daemon whether it is well** — the bug this rig exists to catch is a
//!   daemon that reports green over a missing file, and a verifier that
//!   consulted it would agree with it every time.
//! - [`orchestrate`] alternates storm and settle segments and freezes the world
//!   when an invariant breaks.
//!
//! The rig is **not deterministic** and does not pretend to be. What replaces
//! seed replay is forensics: three independent [`journal`]s on one timeline, so
//! a violation arrives with the evidence needed to rebuild it as a frozen
//! `jd-sim` scenario. The soak box finds bugs; the simulator then owns them.

pub mod actor;
pub mod chaos;
pub mod control;
pub mod fleet;
pub mod journal;
pub mod orchestrate;
pub mod persona;
pub mod remote;
pub mod report;
pub mod rng;
pub mod server;
pub mod tree;
pub mod verify;

pub use actor::Actor;
pub use fleet::{Device, Fleet};
pub use journal::{Journal, Record};
pub use persona::{FsOp, Persona};
pub use verify::{Verdict, Verification};
