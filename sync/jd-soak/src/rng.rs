//! A seeded random number generator, so a segment can be described by a number.
//!
//! There is no determinism promise in this rig — a real kernel and a real
//! network cannot be replayed (spec S5). What a seed buys is narrower and still
//! worth having: the *sequence of decisions a persona made* is reproducible, so
//! a forensics bundle can say "office actor, seed 41029, op 317" and somebody
//! can run that persona again and watch it choose the same things. What the
//! filesystem and the daemon then did about it is the part nobody can replay.
//!
//! SplitMix64, which is four lines and has no bad seeds — including zero, which
//! is where hand-rolled xorshift generators famously get stuck emitting nothing
//! but zeroes forever. A rig whose randomness silently died would keep running
//! and keep reporting green.

pub struct Rng {
    state: u64,
}

impl Rng {
    pub fn new(seed: u64) -> Rng {
        Rng { state: seed }
    }

    pub fn next_u64(&mut self) -> u64 {
        self.state = self.state.wrapping_add(0x9E37_79B9_7F4A_7C15);
        let mut z = self.state;
        z = (z ^ (z >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
        z = (z ^ (z >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
        z ^ (z >> 31)
    }

    /// A number in `0..n`. Returns 0 for an empty range rather than panicking:
    /// this is called with `things.len()` all over the personas, and a rig that
    /// aborted because a persona had not created anything yet would be a rig
    /// that cannot start.
    pub fn below(&mut self, n: usize) -> usize {
        if n == 0 {
            return 0;
        }
        (self.next_u64() % n as u64) as usize
    }

    pub fn range(&mut self, low: u64, high: u64) -> u64 {
        if high <= low {
            return low;
        }
        low + self.next_u64() % (high - low)
    }

    /// True with probability `percent`/100.
    pub fn chance(&mut self, percent: u64) -> bool {
        self.next_u64() % 100 < percent
    }

    pub fn pick<'a, T>(&mut self, items: &'a [T]) -> Option<&'a T> {
        if items.is_empty() {
            None
        } else {
            let i = self.below(items.len());
            items.get(i)
        }
    }

    /// An exponentially distributed wait, in milliseconds, with the given mean.
    ///
    /// Faults are scheduled this way rather than on a fixed interval because a
    /// fixed interval is a pattern the system can accidentally be safe against:
    /// kill the daemon every 20 minutes on the dot and it is never killed
    /// mid-upload of the big file that starts at minute 19. Poisson arrivals
    /// have no such gaps.
    pub fn exponential_ms(&mut self, mean_ms: u64) -> u64 {
        // Inverse transform on a uniform in (0,1], clamped away from zero so the
        // logarithm stays finite.
        let u = ((self.next_u64() >> 11) as f64 / (1u64 << 53) as f64).max(1e-12);
        let scaled = -(u.ln()) * mean_ms as f64;
        scaled.min(mean_ms as f64 * 8.0) as u64
    }
}

/// Deterministic file content: `size` bytes derived from `seed`.
///
/// Generated rather than stored so the oracle can hold a whole campaign's worth
/// of content identities in a few bytes each, and so a verifier can reconstruct
/// exactly what an actor claims to have written without the actor having kept a
/// copy. Compressible-but-not-constant, which is what real documents are.
pub fn content_bytes(seed: u64, size: usize) -> Vec<u8> {
    let mut rng = Rng::new(seed);
    let mut out = Vec::with_capacity(size);
    while out.len() < size {
        let word = rng.next_u64().to_le_bytes();
        let want = std::cmp::min(8, size - out.len());
        out.extend_from_slice(&word[..want]);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_seed_reproduces_its_stream() {
        let a: Vec<u64> = (0..16).map(|_| Rng::new(7).next_u64()).collect();
        assert!(
            a.windows(2).all(|w| w[0] == w[1]),
            "same seed, same first draw"
        );

        let mut one = Rng::new(99);
        let mut two = Rng::new(99);
        for _ in 0..1000 {
            assert_eq!(one.next_u64(), two.next_u64());
        }
    }

    #[test]
    fn a_zero_seed_is_not_a_dead_generator() {
        // The classic xorshift trap: seed 0 emits zeroes forever. A rig whose
        // randomness had silently died would keep running and keep reporting
        // green, which is the one failure mode a verifier must not have.
        let mut rng = Rng::new(0);
        let draws: Vec<u64> = (0..32).map(|_| rng.next_u64()).collect();
        assert!(draws.iter().any(|&d| d != 0));
        assert!(draws.windows(2).any(|w| w[0] != w[1]));
    }

    #[test]
    fn below_an_empty_range_does_not_panic() {
        // Called with `things.len()` throughout the personas, and a persona that
        // has not created anything yet is the normal first second of a segment.
        assert_eq!(Rng::new(1).below(0), 0);
        assert!(Rng::new(1).pick::<u8>(&[]).is_none());
    }

    #[test]
    fn below_stays_inside_its_range() {
        let mut rng = Rng::new(1234);
        for _ in 0..10_000 {
            assert!(rng.below(7) < 7);
        }
    }

    #[test]
    fn a_poisson_wait_is_spread_out_rather_than_a_fixed_interval() {
        // The whole point: a fixed interval is a pattern the system can be
        // accidentally safe against.
        let mut rng = Rng::new(5);
        let waits: Vec<u64> = (0..500).map(|_| rng.exponential_ms(60_000)).collect();
        let distinct: std::collections::BTreeSet<u64> = waits.iter().copied().collect();
        assert!(distinct.len() > 400, "waits are spread, not clustered");
        let mean = waits.iter().sum::<u64>() / waits.len() as u64;
        assert!(
            (30_000..120_000).contains(&mean),
            "mean {mean} is nowhere near the requested 60000"
        );
        assert!(waits.iter().any(|&w| w < 20_000), "some arrive early");
    }

    #[test]
    fn content_is_reproducible_from_its_seed_and_the_right_length() {
        // The oracle stores a seed and a size instead of the bytes; if these two
        // disagreed the verifier would report loss that never happened.
        assert_eq!(content_bytes(42, 1000), content_bytes(42, 1000));
        assert_ne!(content_bytes(42, 1000), content_bytes(43, 1000));
        assert_eq!(content_bytes(42, 1001).len(), 1001);
        assert_eq!(content_bytes(42, 0).len(), 0);
    }

    #[test]
    fn content_is_not_one_byte_repeated() {
        // Constant content would dedup against itself on the server and make
        // every no-loss check pass by coincidence.
        let bytes = content_bytes(11, 4096);
        let distinct: std::collections::BTreeSet<u8> = bytes.iter().copied().collect();
        assert!(distinct.len() > 32);
    }
}
