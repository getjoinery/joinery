//! One round of the engine: deltas in, an ordered plan out.
//!
//! This is where the pieces meet — the local scan, the remote poll, the
//! reconciliation matrix, the ordering — and where the last safety net sits.
//!
//! That net is the mass-delete guard, and it exists because of a category of
//! disaster the engine genuinely cannot distinguish from ordinary work.
//! Ransomware encrypting a home folder, a network volume that mounted empty, a
//! sync root the user moved without telling anyone, a server-side accident —
//! all of them arrive as "a great many files were deleted", which is
//! byte-for-byte what a legitimate cleanup looks like. There is no clever test
//! that separates them.
//!
//! So the engine does not try to be clever. Past a threshold it stops and asks
//! a person. The cost of asking when it was legitimate is one dialog. The cost
//! of not asking when it was not is everything the user had.

use std::collections::HashMap;

use crate::model::{Delta, EntityId, Entry};
use crate::order::{plan, Plan, PlanItem};
use crate::reconcile::{is_mass_delete, reconcile, Action, Context, Issue};

/// Which direction a paused delete would have gone.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DeleteDirection {
    /// Files would have been removed from this computer.
    Local,
    /// Entries would have been trashed on the server.
    Remote,
}

/// A round's deletes were large enough to stop and ask about.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MassDeletePause {
    pub direction: DeleteDirection,
    pub would_delete: usize,
    pub synced_total: usize,
}

/// Everything one entry contributed to the round.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RoundInput {
    pub entry: Entry,
    pub local: Delta,
    pub remote: Delta,
    /// Depth in the folder tree, for ordering.
    pub depth: i64,
}

#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct RoundOutcome {
    pub plan: Plan,
    pub issues: Vec<(EntityId, Issue)>,
    /// Deletes that were withheld pending a human answer. Their entries are
    /// untouched; nothing is lost by waiting.
    pub paused: Vec<MassDeletePause>,
}

impl RoundOutcome {
    pub fn is_empty(&self) -> bool {
        self.plan.is_empty()
    }

    /// Did anything get held back for a person to confirm?
    pub fn needs_confirmation(&self) -> bool {
        !self.paused.is_empty()
    }
}

/// How the caller answered the mass-delete prompt.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DeletePolicy {
    /// Stop and ask. The default, and what a headless run does when it cannot.
    Guard,
    /// The user looked at it and said go ahead.
    Approved,
}

/// Run one reconciliation round.
///
/// `synced_total` is the number of entries currently in agreement — the
/// denominator the guard measures against.
pub fn run_round(
    inputs: Vec<RoundInput>,
    synced_total: usize,
    ctx: &Context,
    policy: DeletePolicy,
    token_for: &mut dyn FnMut(EntityId) -> String,
) -> RoundOutcome {
    let mut out = RoundOutcome::default();
    let mut resolved: Vec<(RoundInput, Vec<Action>)> = Vec::new();

    for input in inputs {
        let res = reconcile(&input.entry, &input.local, &input.remote, ctx);
        for issue in res.issues {
            out.issues.push((input.entry.id, issue));
        }
        if !res.actions.is_empty() {
            resolved.push((input, res.actions));
        }
    }

    // Count what this round would remove, in each direction separately. A round
    // that tidies up locally is not evidence about the server and vice versa,
    // so a legitimate bulk local cleanup must not be blocked by a coincidental
    // handful of remote deletes.
    let local_deletes = count_actions(&resolved, |a| matches!(a, Action::TrashLocal));
    let remote_deletes = count_actions(&resolved, |a| matches!(a, Action::TrashRemote));

    let mut block_local = false;
    let mut block_remote = false;

    if policy == DeletePolicy::Guard {
        if is_mass_delete(local_deletes, synced_total) {
            block_local = true;
            out.paused.push(MassDeletePause {
                direction: DeleteDirection::Local,
                would_delete: local_deletes,
                synced_total,
            });
        }
        if is_mass_delete(remote_deletes, synced_total) {
            block_remote = true;
            out.paused.push(MassDeletePause {
                direction: DeleteDirection::Remote,
                would_delete: remote_deletes,
                synced_total,
            });
        }
    }

    let mut items: Vec<PlanItem> = Vec::new();
    for (input, actions) in resolved {
        for action in actions {
            // Withhold only the deletes, and only in the blocked direction.
            // Everything else in the round is ordinary work and still runs —
            // pausing a download because some deletes looked alarming would
            // turn one scary moment into a stalled client.
            let withheld = (block_local && matches!(action, Action::TrashLocal))
                || (block_remote && matches!(action, Action::TrashRemote));
            if withheld {
                continue;
            }

            let mut item = PlanItem::new(input.entry.id, action.clone(), input.depth);
            // Where the thing is right now, on the side the move applies to.
            //
            // Applying the server's move means moving a file on this computer,
            // and if the user already moved it here then that is where it is —
            // not the agreed path, which it left. Pushing our own move means
            // renaming on the server, where nothing has happened yet, so the
            // agreement is what is current there.
            let current = match &action {
                Action::ApplyRemoteMove { .. } => input
                    .local
                    .placement()
                    .cloned()
                    .or_else(|| input.entry.synced_placement.clone()),
                _ => input.entry.synced_placement.clone(),
            };
            if let (Some(from), Some(to)) = (current, move_target(&action)) {
                item = item.moving(from, to);
            }
            items.push(item);
        }
    }

    out.plan = plan(items, &ctx.personality, token_for);
    out
}

fn move_target(action: &Action) -> Option<crate::model::Placement> {
    match action {
        Action::ApplyLocalMove { to } | Action::ApplyRemoteMove { to } => Some(to.clone()),
        _ => None,
    }
}

fn count_actions(resolved: &[(RoundInput, Vec<Action>)], pred: impl Fn(&Action) -> bool) -> usize {
    resolved
        .iter()
        .flat_map(|(_, actions)| actions.iter())
        .filter(|a| pred(a))
        .count()
}

/// How long to wait before retrying a failed operation.
///
/// Exponential, capped. The cap matters more than the curve: a client that
/// backs off indefinitely looks identical to a broken one, and the whole health
/// promise is that a stalled device is visible rather than silent. Fifteen
/// minutes means a transient server problem resolves on its own within a
/// quarter of an hour of being fixed, without anyone touching the client.
pub fn retry_delay_ms(attempts: i64) -> u64 {
    const BASE_MS: u64 = 1_000;
    const CAP_MS: u64 = 15 * 60 * 1_000;
    if attempts <= 0 {
        return BASE_MS;
    }
    let shift = attempts.min(20) as u32;
    BASE_MS.saturating_mul(1u64 << shift.min(20)).min(CAP_MS)
}

/// Group a round's issues by entity, for the panel a person reads.
pub fn issues_by_entity(outcome: &RoundOutcome) -> HashMap<EntityId, Vec<&Issue>> {
    let mut map: HashMap<EntityId, Vec<&Issue>> = HashMap::new();
    for (id, issue) in &outcome.issues {
        map.entry(*id).or_default().push(issue);
    }
    map
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::model::{ContentId, LocalStatus, Placement};
    use crate::order::Stage;

    fn content(sha: &str) -> ContentId {
        ContentId {
            sha256: sha.into(),
            size: 10,
        }
    }

    fn placement(name: &str) -> Placement {
        Placement {
            parent: None,
            name: name.into(),
        }
    }

    fn entry(id: i64, name: &str) -> Entry {
        Entry {
            id: EntityId::file(id),
            remote: placement(name),
            remote_content: Some(content("sha")),
            remote_modified_time: None,
            head_change_id: 1,
            remote_deleted: false,
            is_encrypted: false,
            content_id: None,
            synced_remote_content: None,
            synced_content: Some(content("sha")),
            synced_placement: Some(placement(name)),
            synced_fingerprint: None,
            local_name: None,
            status: LocalStatus::Synced,
            wrapped_file_key: None,
        }
    }

    fn ctx() -> Context {
        Context {
            date: "2026-07-16".into(),
            device_name: "PC".into(),
            conflict_suffix: 1,
            personality: jd_vfs::Personality::linux(),
        }
    }

    fn tokens() -> impl FnMut(EntityId) -> String {
        |e: EntityId| format!("t{}", e.server_id)
    }

    /// n entries deleted on one side.
    ///
    /// Note which direction each produces, because it is the opposite of the
    /// obvious reading: a delete made LOCALLY propagates to the server, so it
    /// produces `TrashRemote` and the guard sees it as a Remote-direction
    /// deletion. `DeleteDirection` names where the files would be REMOVED, not
    /// where the user did the removing.
    fn deleted_on(n: usize, side: Side) -> Vec<RoundInput> {
        (0..n)
            .map(|i| RoundInput {
                entry: entry(i as i64 + 1, &format!("f{i}.txt")),
                local: if side == Side::ThisComputer {
                    Delta::Deleted
                } else {
                    Delta::None
                },
                remote: if side == Side::ThisComputer {
                    Delta::None
                } else {
                    Delta::Deleted
                },
                depth: 0,
            })
            .collect()
    }

    #[derive(Clone, Copy, PartialEq, Eq)]
    enum Side {
        ThisComputer,
        Server,
    }

    #[test]
    fn an_ordinary_round_produces_an_ordered_plan() {
        let inputs = vec![
            RoundInput {
                entry: entry(1, "a.txt"),
                local: Delta::None,
                remote: Delta::Edited {
                    content: content("new"),
                },
                depth: 0,
            },
            RoundInput {
                entry: entry(2, "b.txt"),
                local: Delta::Edited {
                    content: content("mine"),
                },
                remote: Delta::None,
                depth: 0,
            },
        ];
        let mut t = tokens();
        let out = run_round(inputs, 100, &ctx(), DeletePolicy::Guard, &mut t);
        assert_eq!(out.plan.ops.len(), 2);
        assert!(!out.needs_confirmation());
    }

    #[test]
    fn a_routine_number_of_deletes_just_happens() {
        let mut t = tokens();
        let out = run_round(
            deleted_on(5, Side::ThisComputer),
            500,
            &ctx(),
            DeletePolicy::Guard,
            &mut t,
        );
        assert!(!out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 5);
    }

    #[test]
    fn a_wholesale_local_wipe_is_held_back_for_a_person() {
        // What an unmounted volume, or ransomware, looks like from here: every
        // local file gone, which would propagate as trashing the whole Drive.
        let mut t = tokens();
        let out = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &ctx(),
            DeletePolicy::Guard,
            &mut t,
        );

        assert!(out.needs_confirmation());
        assert_eq!(out.paused[0].direction, DeleteDirection::Remote);
        assert_eq!(out.paused[0].would_delete, 200);
        assert!(
            out.plan.is_empty(),
            "not one of those deletes may go through unasked"
        );
    }

    #[test]
    fn the_guard_works_in_the_other_direction_too() {
        // A server-side accident that would wipe this computer.
        let mut t = tokens();
        let out = run_round(
            deleted_on(200, Side::Server),
            400,
            &ctx(),
            DeletePolicy::Guard,
            &mut t,
        );
        assert_eq!(out.paused[0].direction, DeleteDirection::Local);
        assert!(out.plan.is_empty());
    }

    #[test]
    fn approval_lets_the_same_round_through_unchanged() {
        let mut t = tokens();
        let out = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &ctx(),
            DeletePolicy::Approved,
            &mut t,
        );
        assert!(!out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 200);
    }

    #[test]
    fn a_pause_in_one_direction_does_not_block_the_other() {
        // A legitimate bulk local cleanup is not evidence about the server.
        let mut inputs = deleted_on(200, Side::ThisComputer);
        inputs.push(RoundInput {
            entry: entry(9001, "removed-on-the-server.txt"),
            local: Delta::None,
            remote: Delta::Deleted,
            depth: 0,
        });

        let mut t = tokens();
        let out = run_round(inputs, 400, &ctx(), DeletePolicy::Guard, &mut t);

        assert_eq!(out.paused.len(), 1);
        assert_eq!(out.paused[0].direction, DeleteDirection::Remote);
        // The one file removed on the server still leaves this computer.
        assert_eq!(out.plan.ops.len(), 1);
        assert_eq!(out.plan.ops[0].action, Action::TrashLocal);
    }

    #[test]
    fn a_pause_does_not_stall_ordinary_work_in_the_same_round() {
        // The failure to avoid: one alarming set of deletes freezing the whole
        // client, so nothing syncs until somebody notices a dialog.
        let mut inputs = deleted_on(200, Side::ThisComputer);
        inputs.push(RoundInput {
            entry: entry(9002, "wanted.txt"),
            local: Delta::None,
            remote: Delta::Edited {
                content: content("fresh"),
            },
            depth: 0,
        });

        let mut t = tokens();
        let out = run_round(inputs, 400, &ctx(), DeletePolicy::Guard, &mut t);

        assert!(out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 1);
        assert_eq!(out.plan.ops[0].action, Action::Download);
    }

    #[test]
    fn issues_are_carried_out_of_the_round_attached_to_their_entry() {
        let inputs = vec![RoundInput {
            entry: entry(1, "Report.xlsx"),
            local: Delta::Edited {
                content: content("mine"),
            },
            remote: Delta::Edited {
                content: content("theirs"),
            },
            depth: 0,
        }];
        let mut t = tokens();
        let out = run_round(inputs, 10, &ctx(), DeletePolicy::Guard, &mut t);

        assert_eq!(out.issues.len(), 1);
        assert_eq!(out.issues[0].0, EntityId::file(1));
        assert!(matches!(out.issues[0].1, Issue::ConflictResolved { .. }));

        let grouped = issues_by_entity(&out);
        assert_eq!(grouped[&EntityId::file(1)].len(), 1);
    }

    #[test]
    fn a_round_that_swaps_two_names_still_gets_its_cycle_broken() {
        let inputs = vec![
            RoundInput {
                entry: entry(1, "A"),
                local: Delta::Moved { to: placement("B") },
                remote: Delta::None,
                depth: 0,
            },
            RoundInput {
                entry: entry(2, "B"),
                local: Delta::Moved { to: placement("A") },
                remote: Delta::None,
                depth: 0,
            },
        ];
        let mut t = tokens();
        let out = run_round(inputs, 10, &ctx(), DeletePolicy::Guard, &mut t);
        assert_eq!(out.plan.broken_cycles.len(), 1);
    }

    #[test]
    fn folder_creates_still_precede_transfers_across_a_whole_round() {
        let inputs = vec![
            RoundInput {
                entry: entry(1, "in-folder.txt"),
                local: Delta::Edited {
                    content: content("x"),
                },
                remote: Delta::None,
                depth: 1,
            },
            RoundInput {
                entry: Entry {
                    id: EntityId::folder(2),
                    synced_content: None,
                    synced_placement: None,
                    ..entry(2, "Folder")
                },
                local: Delta::Created {
                    placement: placement("Folder"),
                    content: None,
                },
                remote: Delta::None,
                depth: 0,
            },
        ];
        let mut t = tokens();
        let out = run_round(inputs, 10, &ctx(), DeletePolicy::Guard, &mut t);
        let stages: Vec<Stage> = out.plan.ordered().iter().map(|o| o.stage).collect();
        assert_eq!(stages, vec![Stage::CreateFolders, Stage::Transfer]);
    }

    #[test]
    fn an_empty_round_is_empty() {
        let mut t = tokens();
        let out = run_round(vec![], 0, &ctx(), DeletePolicy::Guard, &mut t);
        assert!(out.is_empty() && !out.needs_confirmation());
    }

    // ---- backoff -----------------------------------------------------------

    #[test]
    fn retries_back_off_but_never_give_up() {
        assert_eq!(retry_delay_ms(0), 1_000);
        assert_eq!(retry_delay_ms(1), 2_000);
        assert_eq!(retry_delay_ms(4), 16_000);
        // Capped: a client that backs off indefinitely is indistinguishable
        // from a broken one, and would never recover on its own.
        assert_eq!(retry_delay_ms(30), 15 * 60 * 1_000);
        assert_eq!(retry_delay_ms(i64::MAX), 15 * 60 * 1_000);
    }

    #[test]
    fn the_backoff_curve_is_monotonic() {
        let mut previous = 0;
        for attempts in 0..40 {
            let delay = retry_delay_ms(attempts);
            assert!(delay >= previous, "delay went backwards at {attempts}");
            previous = delay;
        }
    }
}
