//! jd-daemon — the Joinery Drive client as a program.
//!
//! `jd-core` is the engine and knows nothing about processes, terminals, or
//! operating systems. This crate is what turns it into something a person
//! installs: a background process that keeps running, a way to link the machine
//! to an account, a way to see whether it is working, and a way to make it stop.
//!
//! - [`link`] — the browser ceremony that produces this device's credential.
//! - [`config`] — what the installation is set to, minus anything secret.
//! - [`daemon`] — the loop, and the two-thread split that keeps a hung tray from
//!   being able to slow it down.
//! - the control channel itself lives in `jd-platform`, so the tray can use it
//!   without dragging the engine — and therefore a bundled SQLite and a
//!   cross-compiler — along with it.
//! - [`health`] — every entry's state reduced to one honest indicator, which is
//!   the whole "never silently stop" promise made visible.

pub mod config;
pub mod daemon;
pub mod health;
pub mod link;

pub use config::{Config, ConfigError};
pub use daemon::{Command, Daemon, Shared, Snapshot};
pub use health::{Blocker, Health, Indicator};
pub use jd_platform::control::{self, ControlServer, Endpoint};
