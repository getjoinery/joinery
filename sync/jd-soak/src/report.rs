//! Turning a day of journals into something a person will actually read.
//!
//! Nobody reads a week of JSONL. The report is what gets looked at, so its job
//! is to make one number impossible to miss — **invariant violations, which must
//! read 0** — and to put next to it the handful of figures that say whether the
//! run was worth anything: how much the actors actually did, how many faults
//! actually landed, and how long convergence took at the tail rather than on
//! average.
//!
//! The p95 matters more than the mean here. A client that converges in four
//! seconds ninety times and eleven minutes once has a problem the mean hides
//! completely, and it is the eleven minutes that turns into a stall the week
//! after.

use std::collections::BTreeMap;

use crate::journal::Record;

/// Everything worth saying about a stretch of journal.
#[derive(Debug, Clone, Default)]
pub struct Summary {
    pub segments: u64,
    pub actor_ops: BTreeMap<String, u64>,
    pub actor_failures: u64,
    pub faults: BTreeMap<String, u64>,
    pub faults_refused: u64,
    pub verdicts_passed: u64,
    pub violations: Vec<(u64, String, String)>,
    pub convergence_ms: Vec<u64>,
    pub first_ts_ms: u64,
    pub last_ts_ms: u64,
}

impl Summary {
    pub fn total_ops(&self) -> u64 {
        self.actor_ops.values().sum()
    }

    pub fn total_faults(&self) -> u64 {
        self.faults.values().sum()
    }

    /// The convergence figure that matters. Mean hides the tail, and the tail is
    /// what becomes a stall.
    pub fn convergence_p95_ms(&self) -> u64 {
        percentile(&self.convergence_ms, 95)
    }

    pub fn convergence_p50_ms(&self) -> u64 {
        percentile(&self.convergence_ms, 50)
    }

    pub fn convergence_max_ms(&self) -> u64 {
        self.convergence_ms.iter().copied().max().unwrap_or(0)
    }

    /// Is there no evidence in this window at all?
    ///
    /// Every kind of record counts, including the ones that are themselves bad
    /// news: a single failed verdict means a campaign ran and something broke,
    /// which is emphatically not "nothing happened".
    pub fn nothing_happened(&self) -> bool {
        self.total_ops() == 0
            && self.actor_failures == 0
            && self.segments == 0
            && self.verdicts_passed == 0
            && self.violations.is_empty()
            && self.total_faults() == 0
            && self.faults_refused == 0
    }
}

fn percentile(values: &[u64], p: u64) -> u64 {
    if values.is_empty() {
        return 0;
    }
    let mut sorted = values.to_vec();
    sorted.sort_unstable();
    let index = ((sorted.len() as u64 - 1) * p / 100) as usize;
    sorted[index]
}

/// Reduce a timeline to a summary.
pub fn summarize(records: &[Record]) -> Summary {
    let mut s = Summary::default();
    for record in records {
        if s.first_ts_ms == 0 {
            s.first_ts_ms = record.ts_ms();
        }
        s.last_ts_ms = record.ts_ms();
        match record {
            Record::ActorCommit { persona, .. } => {
                *s.actor_ops.entry(persona.clone()).or_insert(0) += 1;
            }
            Record::ActorFailed { .. } => s.actor_failures += 1,
            Record::Fault { kind, target, .. } => {
                if kind == "refused" {
                    s.faults_refused += 1;
                } else {
                    *s.faults.entry(format!("{kind} ({target})")).or_insert(0) += 1;
                }
            }
            Record::Verdict {
                segment,
                assertion,
                ok,
                detail,
                ..
            } => {
                if *ok {
                    s.verdicts_passed += 1;
                } else {
                    s.violations
                        .push((*segment, assertion.clone(), detail.clone()));
                }
            }
            Record::Segment { kind, .. } => {
                if kind == "storm" {
                    s.segments += 1;
                }
            }
            Record::ActorIntent { .. } => {}
            // Read back out of the journal rather than passed in, so `jd-soak
            // report` run days later against a bundle produces the same numbers
            // the live run showed.
            Record::Sample { convergence_ms, .. } => {
                if let Some(ms) = convergence_ms {
                    s.convergence_ms.push(*ms);
                }
            }
        }
    }
    s
}

/// Convergence times pulled out of the verdict details the verifier wrote.
pub fn note_convergence(summary: &mut Summary, milliseconds: impl IntoIterator<Item = u64>) {
    summary.convergence_ms.extend(milliseconds);
}

/// The rolling report, as text.
pub fn render(summary: &Summary) -> String {
    let mut out = String::new();
    let hours = (summary.last_ts_ms.saturating_sub(summary.first_ts_ms)) as f64 / 3_600_000.0;

    out.push_str("Joinery Drive sync — soak report\n");
    out.push_str("================================\n\n");

    // First line, largest consequence. Everything below it is context for this
    // one number.
    out.push_str(&format!(
        "INVARIANT VIOLATIONS: {}\n\n",
        summary.violations.len()
    ));

    out.push_str(&format!(
        "Window          {hours:.1} hours, {} storm segments\n",
        summary.segments
    ));
    out.push_str(&format!(
        "Actor operations {} committed, {} refused\n",
        summary.total_ops(),
        summary.actor_failures
    ));
    out.push_str(&format!(
        "Faults injected  {}{}\n",
        summary.total_faults(),
        if summary.faults_refused > 0 {
            format!(" ({} could not be injected)", summary.faults_refused)
        } else {
            String::new()
        }
    ));
    out.push_str(&format!(
        "Convergence      p50 {}s, p95 {}s, max {}s\n",
        summary.convergence_p50_ms() / 1000,
        summary.convergence_p95_ms() / 1000,
        summary.convergence_max_ms() / 1000
    ));
    out.push_str(&format!("Assertions passed {}\n", summary.verdicts_passed));

    if !summary.actor_ops.is_empty() {
        out.push_str("\nBy persona\n");
        for (persona, count) in &summary.actor_ops {
            out.push_str(&format!("  {persona:<16} {count}\n"));
        }
    }

    if !summary.faults.is_empty() {
        out.push_str("\nFaults\n");
        for (fault, count) in &summary.faults {
            out.push_str(&format!("  {fault:<26} {count}\n"));
        }
    }

    if summary.nothing_happened() {
        // The hazard this closes: a rig that has never run produces a report
        // whose headline reads "INVARIANT VIOLATIONS: 0". Nothing about that
        // sentence is false, and somebody skimming it would conclude the
        // opposite of the truth.
        out.push_str(
            "\nNOTHING HAS RUN. There is no evidence in this window at all — not one\n\
             actor operation, fault or verdict. The zero above is the absence of a\n\
             campaign, not the result of one.\n",
        );
        return out;
    }

    if summary.violations.is_empty() {
        out.push_str("\nNo invariant was broken in this window.\n");
    } else {
        out.push_str("\nViolations\n");
        for (segment, assertion, detail) in &summary.violations {
            out.push_str(&format!("  segment {segment} — {assertion}: {detail}\n"));
        }
    }

    // Said out loud rather than inferred from a zero, because "no faults" and
    // "the fault agent could not reach anything" look identical in a table and
    // mean opposite things.
    if summary.total_faults() == 0 && summary.segments > 0 {
        out.push_str(
            "\nNOTE: no fault was injected in this window. A green run with no adversary\n\
             in it proves nothing — check that the chaos agent can reach the devices.\n",
        );
    }
    if summary.faults_refused > 0 {
        out.push_str(&format!(
            "\nNOTE: {} fault(s) could not be injected. The run is weaker than it looks.\n",
            summary.faults_refused
        ));
    }

    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn commit(persona: &str, ts: u64) -> Record {
        Record::ActorCommit {
            seq: 1,
            actor: "device-a/office".into(),
            persona: persona.into(),
            op: "write".into(),
            path: "a.txt".into(),
            sha256: Some("aa".into()),
            size: 1,
            mtime_ms: None,
            ts_ms: ts,
        }
    }

    fn fault(kind: &str, ts: u64) -> Record {
        Record::Fault {
            seq: 1,
            kind: kind.into(),
            target: "device-a".into(),
            detail: String::new(),
            ts_ms: ts,
        }
    }

    fn verdict(ok: bool, assertion: &str, ts: u64) -> Record {
        Record::Verdict {
            seq: 1,
            segment: 3,
            assertion: assertion.into(),
            ok,
            detail: "a file is gone".into(),
            ts_ms: ts,
        }
    }

    #[test]
    fn a_clean_window_says_zero_violations_first() {
        // The one number that matters has to be impossible to miss.
        let summary = summarize(&[
            commit("office", 1),
            fault("kill", 2),
            verdict(true, "no-loss", 3),
        ]);
        let text = render(&summary);
        assert!(text.contains("INVARIANT VIOLATIONS: 0"));
        assert!(text
            .lines()
            .take(5)
            .any(|l| l.contains("INVARIANT VIOLATIONS")));
    }

    #[test]
    fn a_violation_is_reported_with_its_segment_and_its_detail() {
        let summary = summarize(&[verdict(false, "no-loss", 1)]);
        assert_eq!(summary.violations.len(), 1);
        let text = render(&summary);
        assert!(text.contains("INVARIANT VIOLATIONS: 1"));
        assert!(text.contains("segment 3 — no-loss: a file is gone"));
    }

    #[test]
    fn work_is_counted_per_persona() {
        let summary = summarize(&[
            commit("office", 1),
            commit("office", 2),
            commit("browser", 3),
        ]);
        assert_eq!(summary.actor_ops["office"], 2);
        assert_eq!(summary.actor_ops["browser"], 1);
        assert_eq!(summary.total_ops(), 3);
    }

    #[test]
    fn a_fault_that_could_not_be_injected_is_counted_apart_from_one_that_landed() {
        // Counting a refusal as a fault would report a hundred kills that never
        // happened.
        let summary = summarize(&[fault("kill", 1), fault("refused", 2)]);
        assert_eq!(summary.total_faults(), 1);
        assert_eq!(summary.faults_refused, 1);
        assert!(render(&summary).contains("weaker than it looks"));
    }

    #[test]
    fn a_run_with_no_adversary_in_it_says_so_rather_than_reading_as_clean() {
        // "No faults" and "the fault agent could not reach anything" look
        // identical in a table and mean opposite things.
        let summary = summarize(&[
            Record::Segment {
                seq: 1,
                index: 1,
                kind: "storm".into(),
                detail: String::new(),
                ts_ms: 1,
            },
            commit("office", 2),
            verdict(true, "no-loss", 3),
        ]);
        let text = render(&summary);
        assert!(text.contains("proves nothing"), "{text}");
    }

    #[test]
    fn the_tail_of_convergence_is_reported_and_not_just_the_middle() {
        // A client that converges in four seconds ninety times and eleven
        // minutes once has a problem the mean hides completely.
        let mut summary = Summary::default();
        let mut times: Vec<u64> = vec![4_000; 90];
        times.push(660_000);
        note_convergence(&mut summary, times);
        assert_eq!(summary.convergence_p50_ms(), 4_000);
        assert_eq!(summary.convergence_max_ms(), 660_000);
        assert!(render(&summary).contains("max 660s"));
    }

    #[test]
    fn an_empty_window_renders_without_dividing_by_zero() {
        let summary = summarize(&[]);
        let text = render(&summary);
        assert!(text.contains("INVARIANT VIOLATIONS: 0"));
        assert_eq!(summary.convergence_p95_ms(), 0);
    }

    #[test]
    fn a_rig_that_never_ran_says_so_instead_of_reading_as_a_clean_campaign() {
        // "INVARIANT VIOLATIONS: 0" over an empty journal is true and means the
        // opposite of what somebody skimming it would take from it.
        let text = render(&summarize(&[]));
        assert!(text.contains("NOTHING HAS RUN"), "{text}");
        assert!(!text.contains("No invariant was broken"), "{text}");
    }

    #[test]
    fn a_window_with_real_work_in_it_does_not_claim_nothing_ran() {
        let text = render(&summarize(&[
            commit("office", 1),
            verdict(true, "no-loss", 2),
        ]));
        assert!(!text.contains("NOTHING HAS RUN"), "{text}");
        assert!(text.contains("No invariant was broken"), "{text}");
    }

    #[test]
    fn a_single_bad_verdict_is_evidence_that_something_ran() {
        // The trap in the other direction: a window holding nothing but a
        // failure is the most important window there is, and swallowing it
        // behind "nothing has run" would hide exactly the finding the rig
        // exists to produce.
        let summary = summarize(&[verdict(false, "no-loss", 1)]);
        assert!(!summary.nothing_happened());
        let text = render(&summary);
        assert!(text.contains("INVARIANT VIOLATIONS: 1"), "{text}");
        assert!(text.contains("a file is gone"), "{text}");
        assert!(!text.contains("NOTHING HAS RUN"), "{text}");
    }
}
