//! Putting decided actions into an order that is safe to carry out.
//!
//! The reconciler decides *what* should happen to each entry independently.
//! That is the right way to decide and the wrong way to execute: you cannot
//! upload into a folder that does not exist yet, and you cannot delete a folder
//! before what is inside it. So a round's actions are staged.
//!
//! Rename cycles are the interesting case. If the user swaps two names — `A`
//! becomes `B` while `B` becomes `A` — there is no order that works, because
//! each move's destination is occupied by the other. Every filesystem and every
//! server will refuse one of them. The way out is a scratch name: move one of
//! them somewhere nothing else wants, complete the rest, then bring it back.
//! The engine detects the cycle rather than discovering it as an error, because
//! an error here would leave half a swap applied.

use std::collections::{HashMap, HashSet};

use crate::model::{EntityId, EntityType, Placement};
use crate::reconcile::Action;

/// The order things happen in within a round. Everything in one stage may run
/// concurrently (subject to per-entry serialization); a stage does not start
/// until the one before it has finished.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Stage {
    /// Folders first, shallowest first — a child cannot be created before its
    /// parent exists.
    CreateFolders,
    /// Then placement changes, cycles already broken.
    Move,
    /// Then the bytes. This is the slow stage and the parallel one.
    Transfer,
    /// Deletes last, deepest first, so a folder is empty before it goes.
    Delete,
}

/// One action, placed.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct PlannedOp {
    pub entity: EntityId,
    pub action: Action,
    pub stage: Stage,
    /// Ordering within the stage. Lower runs first.
    pub rank: i64,
    /// For a move: where the thing is right now.
    ///
    /// Carried because it is not derivable later. A file the user moved on this
    /// computer is no longer at the agreed placement, and an executor that
    /// looks for it there finds nothing and can only give up — every round,
    /// forever, because nothing about that situation ever changes on its own.
    pub from: Option<Placement>,
}

/// What the planner needs to know about an action beyond the action itself.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct PlanItem {
    pub entity: EntityId,
    pub action: Action,
    /// Depth in the folder tree; the root's children are 0. Drives top-down
    /// creates and bottom-up deletes.
    pub depth: i64,
    /// For a move: where it is now, and where it is going. Used to find cycles.
    pub move_from: Option<Placement>,
    pub move_to: Option<Placement>,
}

impl PlanItem {
    pub fn new(entity: EntityId, action: Action, depth: i64) -> Self {
        PlanItem {
            entity,
            action,
            depth,
            move_from: None,
            move_to: None,
        }
    }

    pub fn moving(mut self, from: Placement, to: Placement) -> Self {
        self.move_from = Some(from);
        self.move_to = Some(to);
        self
    }
}

/// A scratch name nothing else will ever want. The `.jd-` prefix is refused for
/// real files (see `jd_vfs::names`), so this cannot collide with a user's file
/// even by malice.
pub fn swap_name(token: &str) -> String {
    format!(".jd-swap-{}", token)
}

/// The ordered plan for one round.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Plan {
    pub ops: Vec<PlannedOp>,
    /// Entities that had to be parked under a scratch name to break a cycle,
    /// with the name used. The executor moves each to its real destination once
    /// the rest of the cycle has moved.
    pub broken_cycles: Vec<(EntityId, String)>,
}

impl Plan {
    pub fn is_empty(&self) -> bool {
        self.ops.is_empty()
    }

    /// Ops in the order they should run.
    pub fn ordered(&self) -> Vec<&PlannedOp> {
        let mut refs: Vec<&PlannedOp> = self.ops.iter().collect();
        refs.sort_by_key(|o| (o.stage, o.rank, o.entity));
        refs
    }
}

/// Stage and order a round's actions.
///
/// `personality` decides what counts as the same slot. On a case-insensitive
/// volume `Report.txt` and `report.txt` are one slot and one of the two movers
/// has to wait; on Linux they are two and neither does. Getting that wrong does
/// not produce an error — it produces two renames into one name, and the second
/// quietly replaces the first.
///
/// `token_for` supplies the random part of a scratch name; it is a parameter so
/// a simulated run reproduces exactly from its seed.
pub fn plan(
    items: Vec<PlanItem>,
    personality: &jd_vfs::Personality,
    token_for: &mut dyn FnMut(EntityId) -> String,
) -> Plan {
    let mut plan = Plan::default();

    // Which entities need parking to break a rename cycle.
    let waits_for = dependency_graph(&items, personality);
    let parked = find_cycle_breakers(&waits_for);
    let move_rank = move_ranks(&waits_for, &parked);

    for item in &items {
        let stage = stage_for(&item.action);
        // Deletes run deepest-first and files before folders, so nothing is
        // removed while something still lives inside it. Everything else runs
        // shallowest-first, so a parent exists before its children need it.
        let rank = match stage {
            Stage::Delete => {
                let type_bias = match item.entity.entity_type {
                    EntityType::File => 0,
                    EntityType::Folder => 1,
                };
                -item.depth * 2 + type_bias
            }
            // Moves run in dependency order, not depth order: a rename into a
            // name someone else is still using has to wait for them to leave.
            // Depth says nothing about that, and a chain executed by depth
            // fails on its first link.
            Stage::Move => move_rank.get(&item.entity).copied().unwrap_or(0),
            _ => item.depth,
        };

        if parked.contains(&item.entity) {
            let token = token_for(item.entity);
            let name = swap_name(&token);
            plan.broken_cycles.push((item.entity, name.clone()));
        }

        plan.ops.push(PlannedOp {
            entity: item.entity,
            action: item.action.clone(),
            stage,
            rank,
            from: item.move_from.clone(),
        });
    }

    plan
}

fn stage_for(action: &Action) -> Stage {
    match action {
        Action::CreateRemoteFolder { .. } | Action::CreateLocalFolder { .. } => {
            Stage::CreateFolders
        }
        Action::ApplyLocalMove { .. } | Action::ApplyRemoteMove { .. } => Stage::Move,
        Action::Download
        | Action::UploadVersion
        | Action::UploadAsNew { .. }
        | Action::PreserveLocalAs { .. }
        | Action::Adopt => Stage::Transfer,
        Action::TrashLocal | Action::TrashRemote | Action::Forget | Action::RemoveFromScope => {
            Stage::Delete
        }
    }
}

/// A key identifying a slot in the tree: a name inside a parent. Two entities
/// cannot occupy the same slot.
fn slot(p: &Placement, personality: &jd_vfs::Personality) -> (Option<i64>, String) {
    (p.parent, jd_vfs::comparison_key(&p.name, personality))
}

/// Who has to get out of whose way.
///
/// A move *into* a slot depends on whatever currently occupies that slot
/// leaving first. That single edge per mover is the whole graph — a slot has at
/// most one occupant, so nothing can wait on two things at once.
fn dependency_graph(
    items: &[PlanItem],
    personality: &jd_vfs::Personality,
) -> HashMap<EntityId, EntityId> {
    // Who currently sits in each slot, among the entities that are moving.
    let mut occupant: HashMap<(Option<i64>, String), EntityId> = HashMap::new();
    for item in items {
        if let Some(from) = &item.move_from {
            occupant.insert(slot(from, personality), item.entity);
        }
    }

    // entity -> the entity that must move out of its way first.
    let mut waits_for: HashMap<EntityId, EntityId> = HashMap::new();
    for item in items {
        if let Some(to) = &item.move_to {
            if let Some(blocker) = occupant.get(&slot(to, personality)) {
                if *blocker != item.entity {
                    waits_for.insert(item.entity, *blocker);
                }
            }
        }
    }
    waits_for
}

/// The order the moves actually run in.
///
/// Everything waits for whoever is in its way, so a mover's rank is one past
/// the rank of what it waits for. A parked entity has already vacated by the
/// time this stage runs, so nothing waits on it — and its own move goes last,
/// after every slot it might want has been freed.
fn move_ranks(
    waits_for: &HashMap<EntityId, EntityId>,
    parked: &HashSet<EntityId>,
) -> HashMap<EntityId, i64> {
    fn rank_of(
        e: EntityId,
        waits_for: &HashMap<EntityId, EntityId>,
        parked: &HashSet<EntityId>,
        memo: &mut HashMap<EntityId, i64>,
        visiting: &mut HashSet<EntityId>,
    ) -> i64 {
        if let Some(r) = memo.get(&e) {
            return *r;
        }
        // Cycles are already broken by parking; this only guards against a
        // caller handing us a graph we did not derive.
        if !visiting.insert(e) {
            return 0;
        }
        let rank = match waits_for.get(&e) {
            // A parked blocker is already out of the way.
            Some(blocker) if !parked.contains(blocker) => {
                rank_of(*blocker, waits_for, parked, memo, visiting) + 1
            }
            _ => 0,
        };
        visiting.remove(&e);
        memo.insert(e, rank);
        rank
    }

    let mut memo = HashMap::new();
    let mut visiting = HashSet::new();
    for e in waits_for.keys().copied() {
        rank_of(e, waits_for, parked, &mut memo, &mut visiting);
    }
    for blocker in waits_for.values().copied() {
        rank_of(blocker, waits_for, parked, &mut memo, &mut visiting);
    }

    let last = memo.values().copied().max().unwrap_or(0) + 1;
    for e in parked {
        memo.insert(*e, last);
    }
    memo
}

/// Find the moves that must be parked under a scratch name because their
/// destinations form a cycle.
///
/// Follow the dependencies; any cycle needs exactly one member parked to break
/// it, and the member is chosen deterministically (lowest id) so every device
/// breaks the same cycle the same way.
fn find_cycle_breakers(waits_for: &HashMap<EntityId, EntityId>) -> HashSet<EntityId> {
    let mut breakers = HashSet::new();
    let mut settled: HashSet<EntityId> = HashSet::new();

    for start in waits_for.keys().copied() {
        if settled.contains(&start) {
            continue;
        }
        // Walk the dependency chain from here. If we come back to something
        // already on this walk, that is a cycle.
        let mut path: Vec<EntityId> = Vec::new();
        let mut seen: HashSet<EntityId> = HashSet::new();
        let mut cur = start;
        loop {
            if !seen.insert(cur) {
                // Found a cycle: everything from the first sighting of `cur`
                // onward is in it. Park its lowest-id member — a stable choice,
                // so two devices resolving the same swap agree.
                let at = path.iter().position(|e| *e == cur).unwrap_or(0);
                if let Some(victim) = path[at..].iter().copied().min() {
                    breakers.insert(victim);
                }
                break;
            }
            path.push(cur);
            match waits_for.get(&cur) {
                Some(next) => cur = *next,
                None => break, // chain ends in something that can just move
            }
        }
        settled.extend(path);
    }

    breakers
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::model::Placement;

    fn placement(parent: Option<i64>, name: &str) -> Placement {
        Placement {
            parent,
            name: name.into(),
        }
    }

    fn tokens() -> impl FnMut(EntityId) -> String {
        |e: EntityId| format!("t{}", e.server_id)
    }

    #[test]
    fn folders_are_created_before_the_files_that_go_in_them() {
        let items = vec![
            PlanItem::new(EntityId::file(2), Action::Download, 1),
            PlanItem::new(
                EntityId::folder(1),
                Action::CreateRemoteFolder {
                    placement: placement(None, "New"),
                },
                0,
            ),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        let order = p.ordered();
        assert_eq!(order[0].stage, Stage::CreateFolders);
        assert_eq!(order[1].stage, Stage::Transfer);
    }

    #[test]
    fn nested_folders_are_created_shallowest_first() {
        let items = vec![
            PlanItem::new(
                EntityId::folder(2),
                Action::CreateRemoteFolder {
                    placement: placement(Some(1), "Child"),
                },
                1,
            ),
            PlanItem::new(
                EntityId::folder(1),
                Action::CreateRemoteFolder {
                    placement: placement(None, "Parent"),
                },
                0,
            ),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        let order = p.ordered();
        assert_eq!(order[0].entity, EntityId::folder(1));
        assert_eq!(order[1].entity, EntityId::folder(2));
    }

    #[test]
    fn deletes_run_deepest_first_and_folders_after_their_contents() {
        let items = vec![
            PlanItem::new(EntityId::folder(1), Action::TrashRemote, 0),
            PlanItem::new(EntityId::folder(2), Action::TrashRemote, 1),
            PlanItem::new(EntityId::file(3), Action::TrashRemote, 2),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        let order: Vec<EntityId> = p.ordered().iter().map(|o| o.entity).collect();
        assert_eq!(
            order,
            vec![EntityId::file(3), EntityId::folder(2), EntityId::folder(1)]
        );
    }

    #[test]
    fn a_file_is_removed_before_the_folder_it_sits_in_at_the_same_depth_ranking() {
        // Same depth: the file still has to go first, or the folder delete hits
        // a non-empty directory.
        let items = vec![
            PlanItem::new(EntityId::folder(1), Action::TrashLocal, 1),
            PlanItem::new(EntityId::file(2), Action::TrashLocal, 1),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        let order: Vec<EntityId> = p.ordered().iter().map(|o| o.entity).collect();
        assert_eq!(order, vec![EntityId::file(2), EntityId::folder(1)]);
    }

    #[test]
    fn deletes_come_after_everything_else() {
        let items = vec![
            PlanItem::new(EntityId::file(9), Action::TrashRemote, 0),
            PlanItem::new(EntityId::file(1), Action::Download, 0),
            PlanItem::new(
                EntityId::folder(2),
                Action::CreateRemoteFolder {
                    placement: placement(None, "F"),
                },
                0,
            ),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        let stages: Vec<Stage> = p.ordered().iter().map(|o| o.stage).collect();
        assert_eq!(
            stages,
            vec![Stage::CreateFolders, Stage::Transfer, Stage::Delete]
        );
    }

    #[test]
    fn an_ordinary_rename_needs_no_scratch_name() {
        let items = vec![PlanItem::new(
            EntityId::file(1),
            Action::ApplyLocalMove {
                to: placement(None, "b.txt"),
            },
            0,
        )
        .moving(placement(None, "a.txt"), placement(None, "b.txt"))];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        assert!(p.broken_cycles.is_empty());
    }

    #[test]
    fn a_chain_of_renames_needs_no_scratch_name() {
        // A→B, B→C: there is an order that works (B first, then A), so nothing
        // needs parking.
        let items = vec![
            PlanItem::new(
                EntityId::file(1),
                Action::ApplyLocalMove {
                    to: placement(None, "B"),
                },
                0,
            )
            .moving(placement(None, "A"), placement(None, "B")),
            PlanItem::new(
                EntityId::file(2),
                Action::ApplyLocalMove {
                    to: placement(None, "C"),
                },
                0,
            )
            .moving(placement(None, "B"), placement(None, "C")),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        assert!(p.broken_cycles.is_empty(), "a chain is not a cycle");
    }

    #[test]
    fn a_name_swap_parks_exactly_one_of_the_two() {
        // A→B while B→A. No order works; one must go to a scratch name first.
        let items = vec![
            PlanItem::new(
                EntityId::file(1),
                Action::ApplyLocalMove {
                    to: placement(None, "B"),
                },
                0,
            )
            .moving(placement(None, "A"), placement(None, "B")),
            PlanItem::new(
                EntityId::file(2),
                Action::ApplyLocalMove {
                    to: placement(None, "A"),
                },
                0,
            )
            .moving(placement(None, "B"), placement(None, "A")),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        assert_eq!(p.broken_cycles.len(), 1);
        // Deterministic victim, so two devices break the same swap identically.
        assert_eq!(p.broken_cycles[0].0, EntityId::file(1));
        assert!(p.broken_cycles[0].1.starts_with(".jd-swap-"));
    }

    #[test]
    fn a_three_way_rotation_parks_exactly_one() {
        let items = vec![
            PlanItem::new(
                EntityId::file(3),
                Action::ApplyLocalMove {
                    to: placement(None, "A"),
                },
                0,
            )
            .moving(placement(None, "C"), placement(None, "A")),
            PlanItem::new(
                EntityId::file(1),
                Action::ApplyLocalMove {
                    to: placement(None, "B"),
                },
                0,
            )
            .moving(placement(None, "A"), placement(None, "B")),
            PlanItem::new(
                EntityId::file(2),
                Action::ApplyLocalMove {
                    to: placement(None, "C"),
                },
                0,
            )
            .moving(placement(None, "B"), placement(None, "C")),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        assert_eq!(p.broken_cycles.len(), 1);
        assert_eq!(p.broken_cycles[0].0, EntityId::file(1));
    }

    #[test]
    fn a_scratch_name_can_never_collide_with_a_real_file() {
        // The engine's prefix is refused for real names, so parking is safe
        // even against a user deliberately trying to collide with it.
        let name = swap_name("abc");
        assert!(jd_vfs::is_internal(&name));
        assert!(matches!(
            jd_vfs::names::to_local_name(&name, &jd_vfs::Personality::linux()),
            jd_vfs::LocalName::Unsyncable(jd_vfs::UnsyncableReason::ReservedPrefix)
        ));
    }

    /// The order the moves come out in, for readability in the tests below.
    fn move_order(p: &Plan) -> Vec<EntityId> {
        p.ordered()
            .iter()
            .filter(|o| o.stage == Stage::Move)
            .map(|o| o.entity)
            .collect()
    }

    #[test]
    fn a_chain_of_renames_runs_from_the_far_end() {
        // A→B, B→C. Doing A first hits a name B has not left yet, and every
        // filesystem and the server both refuse it. B has to go first.
        let items = vec![
            PlanItem::new(
                EntityId::file(1),
                Action::ApplyLocalMove {
                    to: placement(None, "B"),
                },
                0,
            )
            .moving(placement(None, "A"), placement(None, "B")),
            PlanItem::new(
                EntityId::file(2),
                Action::ApplyLocalMove {
                    to: placement(None, "C"),
                },
                0,
            )
            .moving(placement(None, "B"), placement(None, "C")),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        assert_eq!(move_order(&p), vec![EntityId::file(2), EntityId::file(1)]);
    }

    #[test]
    fn a_parked_entity_moves_last() {
        // A→B, B→A. A is parked, so B can take A's name; A's own move runs
        // once B has left B.
        let items = vec![
            PlanItem::new(
                EntityId::file(1),
                Action::ApplyLocalMove {
                    to: placement(None, "B"),
                },
                0,
            )
            .moving(placement(None, "A"), placement(None, "B")),
            PlanItem::new(
                EntityId::file(2),
                Action::ApplyLocalMove {
                    to: placement(None, "A"),
                },
                0,
            )
            .moving(placement(None, "B"), placement(None, "A")),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        assert_eq!(p.broken_cycles[0].0, EntityId::file(1));
        assert_eq!(move_order(&p), vec![EntityId::file(2), EntityId::file(1)]);
    }

    #[test]
    fn a_three_way_rotation_runs_in_an_order_that_works() {
        // C→A, A→B, B→C with A parked. Whoever wants a slot must come after
        // whoever is sitting in it.
        let items = vec![
            PlanItem::new(
                EntityId::file(3),
                Action::ApplyLocalMove {
                    to: placement(None, "A"),
                },
                0,
            )
            .moving(placement(None, "C"), placement(None, "A")),
            PlanItem::new(
                EntityId::file(1),
                Action::ApplyLocalMove {
                    to: placement(None, "B"),
                },
                0,
            )
            .moving(placement(None, "A"), placement(None, "B")),
            PlanItem::new(
                EntityId::file(2),
                Action::ApplyLocalMove {
                    to: placement(None, "C"),
                },
                0,
            )
            .moving(placement(None, "B"), placement(None, "C")),
        ];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        let order = move_order(&p);
        let at = |e: EntityId| order.iter().position(|o| *o == e).unwrap();
        // 3 leaves C before 2 wants it. 3 can go first because the slot it
        // wants, A, was freed by the parking rather than by a move.
        assert!(at(EntityId::file(3)) < at(EntityId::file(2)));
        // The parked one goes last.
        assert_eq!(*order.last().unwrap(), EntityId::file(1));
    }

    #[test]
    fn moves_into_slots_nobody_is_leaving_are_not_cycles() {
        // Moving into a name that simply does not exist yet.
        let items = vec![PlanItem::new(
            EntityId::file(1),
            Action::ApplyLocalMove {
                to: placement(Some(5), "fresh.txt"),
            },
            0,
        )
        .moving(placement(None, "a.txt"), placement(Some(5), "fresh.txt"))];
        let mut t = tokens();
        let p = plan(items, &jd_vfs::Personality::linux(), &mut t);
        assert!(p.broken_cycles.is_empty());
    }
}
