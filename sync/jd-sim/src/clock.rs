//! Time the simulator controls.
//!
//! Two reasons this exists, and only one of them is speed.
//!
//! The obvious one: a debounce that waits two seconds, a retry that backs off
//! to fifteen minutes, and a token that expires in twenty-four hours are all
//! things the engine must get right and none of them are things a test should
//! sit through. Here they cost one function call.
//!
//! The one that actually finds bugs: real clocks move on their own, so a test
//! that passes might have passed because a sleep landed favourably. A clock
//! that only moves when the scenario says so removes that entirely — if a race
//! exists, the seed that hits it hits it every single time.
//!
//! Clocks also *lie*, and the simulator can lie in the same ways: jumping
//! backwards across an NTP correction or a timezone-confused filesystem, and
//! standing still. The engine is never allowed to treat "later" as proof of
//! anything, and this is where that gets tested.

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;

/// A shared clock reading milliseconds since an arbitrary start.
#[derive(Debug, Clone)]
pub struct SimClock {
    now_ms: Arc<AtomicU64>,
}

impl SimClock {
    /// Starts at a large, round, non-zero value. Zero is a bad default: code
    /// that treats "0" as "unset" would work by accident.
    pub fn new() -> SimClock {
        SimClock::starting_at(1_700_000_000_000)
    }

    pub fn starting_at(ms: u64) -> SimClock {
        SimClock {
            now_ms: Arc::new(AtomicU64::new(ms)),
        }
    }

    pub fn now_ms(&self) -> u64 {
        self.now_ms.load(Ordering::SeqCst)
    }

    pub fn now_ns(&self) -> u64 {
        self.now_ms().saturating_mul(1_000_000)
    }

    /// Move forward.
    pub fn advance_ms(&self, ms: u64) {
        self.now_ms.fetch_add(ms, Ordering::SeqCst);
    }

    pub fn advance_secs(&self, secs: u64) {
        self.advance_ms(secs.saturating_mul(1000));
    }

    /// Move *backwards*, the way a machine does when NTP corrects it or someone
    /// fixes the timezone. Nothing the engine decides may depend on time only
    /// increasing, and this is how that claim gets tested rather than assumed.
    pub fn rewind_ms(&self, ms: u64) {
        let _ = self
            .now_ms
            .fetch_update(Ordering::SeqCst, Ordering::SeqCst, |t| {
                Some(t.saturating_sub(ms))
            });
    }

    /// A closure the way `jd-vfs` and the engine want it.
    pub fn as_fn(&self) -> impl Fn() -> u64 + Send + Sync + 'static {
        let handle = Arc::clone(&self.now_ms);
        move || handle.load(Ordering::SeqCst)
    }
}

impl Default for SimClock {
    fn default() -> Self {
        SimClock::new()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn time_moves_only_when_told_to() {
        let c = SimClock::new();
        let t = c.now_ms();
        // No sleep, no yield, nothing: the point is that nothing else can move
        // it, so a race that exists is a race that reproduces.
        assert_eq!(c.now_ms(), t);
        c.advance_ms(2500);
        assert_eq!(c.now_ms(), t + 2500);
    }

    #[test]
    fn a_clone_shares_the_same_clock() {
        let c = SimClock::new();
        let d = c.clone();
        c.advance_secs(60);
        assert_eq!(d.now_ms(), c.now_ms());
    }

    #[test]
    fn the_clock_can_go_backwards() {
        let c = SimClock::starting_at(10_000);
        c.rewind_ms(3_000);
        assert_eq!(c.now_ms(), 7_000);
    }

    #[test]
    fn rewinding_past_the_start_stops_at_zero_rather_than_wrapping() {
        // A wrap would put the clock a few hundred million years in the future
        // and every expiry check would pass. Saturating is the only safe floor.
        let c = SimClock::starting_at(100);
        c.rewind_ms(1_000_000);
        assert_eq!(c.now_ms(), 0);
    }

    #[test]
    fn the_closure_form_tracks_the_clock() {
        let c = SimClock::starting_at(5);
        let f = c.as_fn();
        assert_eq!(f(), 5);
        c.advance_ms(10);
        assert_eq!(f(), 15);
    }
}
