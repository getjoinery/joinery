//! Does the engine's memory grow with passes, or only with the tree?
//!
//! The soak rig's `leak-watch` flags RSS that rises at every settle, and it has
//! done so in runs 21, 25, 26, 28 and 29. But a campaign only ever adds files —
//! the tree grew from 83 live paths to 499 in run 29 — so RSS rising along with
//! it is what a working program does. The question the rig cannot answer is
//! whether the growth is *per pass* or *per entity*, because it never holds the
//! tree still.
//!
//! This does. Build a tree, settle it, then run hundreds of passes that have
//! nothing to do, and watch.

use jd_sim::scenario::{Committed, World};

/// This process's resident set, in KB.
fn rss_kb() -> u64 {
    let s = std::fs::read_to_string("/proc/self/statm").unwrap_or_default();
    let pages: u64 = s
        .split_whitespace()
        .nth(1)
        .and_then(|v| v.parse().ok())
        .unwrap_or(0);
    pages * 4
}

#[test]
#[ignore = "diagnostic: run explicitly with --ignored --nocapture"]
fn memory_over_many_passes_on_a_tree_that_never_changes() {
    let world = World::new(101, &["laptop"]);
    let mut committed = Committed::default();

    // A tree big enough for per-entity cost to show up against the noise.
    let laptop = world.device("laptop");
    for d in 0..12 {
        laptop.fs.user_mkdir(&format!("Folder {d}"));
        for f in 0..25 {
            let path = format!("Folder {d}/doc-{f}.txt");
            let body = format!("content of {path}, padded {}", "x".repeat(400));
            laptop.fs.user_write(&path, body.as_bytes());
            committed.note(&path, body.as_bytes());
        }
    }
    assert!(world.settle().is_some(), "the tree should settle");

    // Let allocators reach steady state before the first reading.
    for _ in 0..20 {
        world.pass(laptop);
    }

    let base = rss_kb();
    let mut marks = Vec::new();
    for round in 1..=10 {
        for _ in 0..50 {
            world.pass(laptop);
        }
        marks.push((round * 50, rss_kb()));
    }

    println!("--- static tree, 300 folders+files, RSS by pass count ---");
    println!("baseline after warmup: {base} kB");
    for (passes, rss) in &marks {
        println!(
            "{passes:>4} passes: {rss:>8} kB   ({:+} kB from baseline)",
            *rss as i64 - base as i64
        );
    }

    let last = marks.last().unwrap().1;
    let growth = last as i64 - base as i64;
    println!("growth across 500 passes on an unchanging tree: {growth:+} kB");

    // Deliberately generous: this is a diagnostic, and the question is whether
    // growth is unbounded per pass, not whether it is exactly zero.
    assert!(
        growth < 20_000,
        "memory grew {growth} kB over 500 passes with nothing to do — that is \
         per-pass growth, not per-entity"
    );
}

#[test]
#[ignore = "diagnostic: run explicitly with --ignored --nocapture"]
fn memory_against_tree_size() {
    // The other half of the question. If memory is per-entity and linear, a
    // campaign that only ever adds files must show RSS rising at every settle,
    // and `leak-watch` is reading a working program as a broken one.
    let world = World::new(103, &["laptop"]);
    let laptop = world.device("laptop");

    println!("--- RSS against tree size (one process, growing tree) ---");
    let mut prev_entities = 0usize;
    let mut prev_rss = 0u64;
    for stage in 1..=6 {
        for d in 0..10 {
            let dir = format!("Stage {stage} Folder {d}");
            laptop.fs.user_mkdir(&dir);
            for f in 0..20 {
                let path = format!("{dir}/doc-{f}.txt");
                let body = format!("stage {stage} content of {path} {}", "x".repeat(400));
                laptop.fs.user_write(&path, body.as_bytes());
            }
        }
        assert!(world.settle().is_some(), "stage {stage} should settle");
        for _ in 0..5 {
            world.pass(laptop);
        }
        let entities = laptop.store.every_entry().unwrap().len();
        let rss = rss_kb();
        let d_ent = entities - prev_entities;
        let d_rss = rss as i64 - prev_rss as i64;
        println!(
            "stage {stage}: {entities:>5} entities, {rss:>8} kB   \
             (+{d_ent} entities, {d_rss:+} kB → {:.2} kB/entity)",
            if d_ent > 0 {
                d_rss as f64 / d_ent as f64
            } else {
                0.0
            }
        );
        prev_entities = entities;
        prev_rss = rss;
    }
}
