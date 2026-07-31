//! jd-core — the sync engine.
//!
//! Keeping a folder on a computer identical to a folder on a server sounds like
//! copying. It is not. Both sides change while nobody is looking, neither can
//! see the other's clock, and the machine in the middle can lose power at any
//! instruction. What makes it tractable is one discipline, applied everywhere:
//! remember exactly what the two sides last agreed on, and derive everything
//! else from that.
//!
//! The pieces, and why they are separate:
//!
//! - [`model`] — an entry, and what each side did to it since the agreement.
//! - [`reconcile`] — the pure decision: deltas in, actions out. No I/O, so its
//!   entire behavior is enumerable in tests that run instantly. This is the
//!   code that can lose somebody's files, so it is the code most worth being
//!   able to reason about exhaustively.
//! - [`order`] — turning a set of decided actions into a sequence that is safe
//!   to execute, including breaking rename cycles.
//! - [`scan`] — working out what happened locally from a tree that records no
//!   history, with the pairing precedence that keeps a move a move.
//! - [`remote`] — what the server did, always measured from the last agreement
//!   rather than the last observation, so an interrupted round loses nothing.
//! - [`round`] — the pieces meeting, plus the mass-delete guard.
//! - [`pass`] — the whole loop: poll the server, walk the disk, decide,
//!   journal, execute, and only then advance the cursor.
//! - [`execute`] — doing it, on a machine that can be switched off between any
//!   two instructions. Every intent is journaled with its idempotency key
//!   before it is acted on, which is what makes an interrupted operation
//!   recoverable rather than a guess.
//! - [`store`] — the last-agreed state, kept transactionally so a crash at any
//!   instruction is recoverable.
//!
//! The filesystem arrives as a `jd-vfs` trait and the network as a `jd-proto`
//! trait, both injected. That is not architectural decoration: it is what lets
//! the simulator run a thousand crash-and-corrupt scenarios per second against
//! the real engine.

pub mod execute;
pub mod model;
pub mod order;
pub mod pass;
pub mod reconcile;
pub mod remote;
pub mod round;
pub mod scan;
pub mod store;

pub use execute::{
    journal, recover, run_one, run_queued, ExecEnv, ExecError, ExecReport, OpOutcome,
};
pub use model::{ContentId, Delta, EntityId, EntityType, Entry, LocalStatus, Placement};
pub use pass::{run_pass, PassOutcome};
pub use reconcile::{is_mass_delete, reconcile, Action, Context, Issue, Resolution, Side};
pub use remote::{local_delta, remote_delta, RemoteState};
pub use round::{run_round, DeletePolicy, MassDeletePause, RoundInput, RoundOutcome};
pub use scan::{pair, KnownLocal, LocalChange, ObservedFile, ScanOutcome};
pub use store::{Op, OpState, Store, StoreError, StoredIssue};
