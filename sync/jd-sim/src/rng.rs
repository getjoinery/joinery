//! Randomness that is the same every time.
//!
//! A simulator that finds a one-in-ten-thousand bug and cannot show it to you
//! again has found nothing. Every random choice in a run — which fault fires,
//! which order two devices act in, what a scratch name is called — comes from
//! here, and here is seeded. A failing seed printed in the test output is a
//! complete, permanent reproducer.
//!
//! This is not `rand`. It is a fixed algorithm written out in full so that the
//! same seed produces the same run on every machine, every platform, and every
//! future version of every dependency. A regression seed frozen in the repo has
//! to still mean something in a year.

/// SplitMix64. Chosen because it is eight lines, has no state beyond a `u64`,
/// and its output stream is defined by the code below rather than by a crate
/// whose next release is free to change it.
#[derive(Debug, Clone)]
pub struct SimRng {
    state: u64,
    seed: u64,
}

impl SimRng {
    pub fn new(seed: u64) -> SimRng {
        SimRng { state: seed, seed }
    }

    /// The seed this generator was built from — what a failing run prints.
    pub fn seed(&self) -> u64 {
        self.seed
    }

    pub fn next_u64(&mut self) -> u64 {
        self.state = self.state.wrapping_add(0x9E37_79B9_7F4A_7C15);
        let mut z = self.state;
        z = (z ^ (z >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
        z = (z ^ (z >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
        z ^ (z >> 31)
    }

    /// Uniform in `0..n`. Rejection-sampled rather than taken modulo, because a
    /// modulo bias makes rare faults rarer in exactly the region worth testing.
    pub fn below(&mut self, n: u64) -> u64 {
        assert!(n > 0, "below(0) has no answer");
        let zone = u64::MAX - (u64::MAX % n);
        loop {
            let v = self.next_u64();
            if v < zone {
                return v % n;
            }
        }
    }

    /// Inclusive range.
    pub fn between(&mut self, lo: u64, hi: u64) -> u64 {
        assert!(hi >= lo);
        lo + self.below(hi - lo + 1)
    }

    /// True with probability `numerator/denominator`.
    pub fn chance(&mut self, numerator: u64, denominator: u64) -> bool {
        self.below(denominator) < numerator
    }

    /// One of `items`, or `None` if empty.
    pub fn pick<'a, T>(&mut self, items: &'a [T]) -> Option<&'a T> {
        if items.is_empty() {
            return None;
        }
        items.get(self.below(items.len() as u64) as usize)
    }

    /// Shuffle in place (Fisher–Yates). Used to reorder concurrent work so that
    /// "these two devices happened to act in this order" is a thing the seed
    /// controls rather than a thing the thread scheduler decides.
    pub fn shuffle<T>(&mut self, items: &mut [T]) {
        if items.len() < 2 {
            return;
        }
        for i in (1..items.len()).rev() {
            let j = self.below(i as u64 + 1) as usize;
            items.swap(i, j);
        }
    }

    /// A short hex token — scratch names, idempotency keys, upload tokens.
    pub fn token(&mut self) -> String {
        format!("{:016x}", self.next_u64())
    }

    /// A generator derived from this one, so a sub-component can have its own
    /// stream without its consumption pattern shifting everybody else's.
    /// Adding one call inside the network layer should not change which files
    /// the workload generator produces.
    pub fn fork(&mut self, label: &str) -> SimRng {
        let mut h = self.next_u64();
        for b in label.as_bytes() {
            h = (h ^ *b as u64).wrapping_mul(0x100_0000_01B3);
        }
        SimRng::new(h)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_same_seed_replays_exactly() {
        let mut a = SimRng::new(42);
        let mut b = SimRng::new(42);
        let one: Vec<u64> = (0..64).map(|_| a.next_u64()).collect();
        let two: Vec<u64> = (0..64).map(|_| b.next_u64()).collect();
        assert_eq!(one, two, "a seed that does not replay is not a reproducer");
    }

    #[test]
    fn different_seeds_diverge() {
        let mut a = SimRng::new(1);
        let mut b = SimRng::new(2);
        assert_ne!(a.next_u64(), b.next_u64());
    }

    #[test]
    fn the_stream_is_pinned_to_these_exact_values() {
        // Frozen on purpose. A regression seed checked into the repo has to
        // still reproduce its bug next year, which it only does if this stream
        // never moves. If this test fails, the algorithm changed and every
        // frozen seed in the suite just became meaningless.
        let mut r = SimRng::new(0);
        assert_eq!(r.next_u64(), 0xE220_A839_7B1D_CDAF);
        assert_eq!(r.next_u64(), 0x6E78_9E6A_A1B9_65F4);
        assert_eq!(r.next_u64(), 0x06C4_5D18_8009_454F);
    }

    #[test]
    fn below_stays_in_range() {
        let mut r = SimRng::new(7);
        for _ in 0..500 {
            assert!(r.below(10) < 10);
        }
    }

    #[test]
    fn shuffle_keeps_every_element() {
        let mut r = SimRng::new(9);
        let mut v: Vec<u32> = (0..20).collect();
        r.shuffle(&mut v);
        v.sort();
        assert_eq!(v, (0..20).collect::<Vec<u32>>());
    }

    #[test]
    fn a_forked_stream_is_independent_but_reproducible() {
        // The point of forking: adding a call inside one component must not
        // shift what every other component sees.
        let mut a = SimRng::new(5);
        let mut b = SimRng::new(5);
        let fa = a.fork("net").next_u64();
        let fb = b.fork("net").next_u64();
        assert_eq!(fa, fb);
        assert_ne!(
            SimRng::new(5).fork("net").next_u64(),
            SimRng::new(5).fork("fs").next_u64()
        );
    }
}
