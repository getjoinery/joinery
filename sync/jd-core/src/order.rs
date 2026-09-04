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

    /// Something that will occupy a slot without leaving one: a folder being
    /// created here. It waits for whoever holds that slot to move out, and a
    /// move into it waits for it to exist.
    pub fn arriving(mut self, to: Placement) -> Self {
        self.move_to = Some(to);
        self
    }
}

/// A scratch name nothing else will ever want. The `.jd-` prefix is refused for
/// real files (see `jd_vfs::names`), so this cannot collide with a user's file
/// even by malice.
pub fn swap_name(token: &str) -> String {
    format!("{SWAP_PREFIX}{token}")
}

/// The prefix [`swap_name`] mints, on its own.
///
/// Narrower than `jd_vfs::INTERNAL_PREFIX` (`.jd-`) ON PURPOSE, and anything
/// cleaning up after a park must use this one. The spool mints `.jd-tmp-`
/// names under the same umbrella prefix, and a rule written against `.jd-`
/// could throw away a working file mid-transfer.
pub const SWAP_PREFIX: &str = ".jd-swap-";

/// The ordered plan for one round.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Plan {
    pub ops: Vec<PlannedOp>,
    /// Entities that have to be parked under a scratch name to break a cycle.
    /// The journal names the scratch after the key of the move that finishes
    /// the dance, so that move can recognise its own park and complete it, and
    /// the executor moves each to its real destination once the rest of the
    /// cycle has moved.
    pub broken_cycles: Vec<EntityId>,
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
pub fn plan(items: Vec<PlanItem>, personality: &jd_vfs::Personality, parents: &FolderParents) -> Plan {
    let mut plan = Plan::default();

    // Which entities need parking to break a rename cycle, and which moves
    // cannot run this round at all.
    let (waits_for, impossible) = dependency_graph(&items, personality, parents);
    // Only something with a place to leave can be parked.
    let parkable: HashSet<EntityId> = items
        .iter()
        .filter(|i| i.move_from.is_some())
        .map(|i| i.entity)
        .collect();
    let parked = find_cycle_breakers(&waits_for, &parkable);
    let move_rank = move_ranks(&waits_for, &parked);

    for item in &items {
        if impossible.contains(&item.entity) {
            continue;
        }
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
            plan.broken_cycles.push(item.entity);
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
        | Action::Adopt
        // Touches no file and no server, so it belongs to no stage in
        // particular. Kept out of Move deliberately: the move stage orders
        // itself around who is vacating which name, and an entry that is
        // already where it is going vacates nothing.
        | Action::AdoptPlacement { .. } => Stage::Transfer,
        Action::TrashLocal
        | Action::TrashRemote
        | Action::Forget
        | Action::RemoveFromScope
        // It frees a name somebody else is waiting on, so it belongs with the
        // work that vacates names rather than with the deletions.
        | Action::UnmaterializeAndPark { .. } => Stage::Delete,
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
/// leaving first. There are two more edges now: a move into a folder being
/// created this round waits for the create, and a move into what is currently
/// its own subtree waits for the folder above it to leave.
///
/// A mover can be under more than one of them, and this map holds ONE — the
/// last derived, which is ancestry over create over slot. That is a known
/// hole and not a claim that one edge suffices: a mover left waiting only on
/// its create can be ranked ahead of the occupant whose slot it needs, and
/// the move then arrives at a name something else is still wearing. It is
/// bounded, because the executor refuses such an arrival rather than
/// overwriting anything and the next round derives the ordering again against
/// a changed world — but the order this produces is not the order it reads as
/// producing. Multi-edge is the fix: ranks take the highest blocker, and the
/// cycle search walks a graph rather than a chain. Spec, "Still open".
/// Where every folder currently sits, on each side: the folder's id to its
/// parent's (`None` at the root). `local` is this disk's layout, `remote` the
/// server's. A move that changes a folder's ancestry is ordered against these,
/// because a name slot is not the only thing a move can wait on.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct FolderParents {
    pub local: HashMap<i64, Option<i64>>,
    pub remote: HashMap<i64, Option<i64>>,
}

fn dependency_graph(
    items: &[PlanItem],
    personality: &jd_vfs::Personality,
    parents: &FolderParents,
) -> (HashMap<EntityId, EntityId>, HashSet<EntityId>) {
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

    // A move into a folder being created this round waits for the create: the
    // destination does not exist yet. With the create itself waiting for the
    // mover -- the mover's directory holds the name the new folder needs, a
    // folder replaced on the server by a namesake and moved inside it -- that
    // is a cycle, and the mover is the one parked: it steps aside under a
    // scratch name, the folder is created, and it moves in.
    // Estate seed 22081285.
    let creating: HashMap<i64, EntityId> = items
        .iter()
        .filter(|i| matches!(i.action, Action::CreateLocalFolder { .. }))
        .map(|i| (i.entity.server_id, i.entity))
        .collect();
    for item in items {
        if !matches!(item.action, Action::ApplyRemoteMove { .. }) {
            continue;
        }
        if let Some(parent) = item.move_to.as_ref().and_then(|t| t.parent) {
            if let Some(create) = creating.get(&parent) {
                waits_for.insert(item.entity, *create);
            }
        }
    }

    // Ancestry. A folder moved into what is currently its own subtree -- the
    // server swapped a parent and its child, say: the child moved out, then
    // the parent moved in under it -- cannot go until the folder it is going
    // into has left. The tree it is being brought to is a tree, so some move
    // on the way up from the destination takes that folder out; the mover
    // waits for the nearest one. With nothing on that chain moving, the move
    // is impossible this round and is left out; the next round derives it
    // again once the chain has changed. Applied out of order it asked the disk
    // to move a directory into itself, which no filesystem does, and recorded
    // an agreement with a loop in it that every later pass refused to touch:
    // the device went quiet with two folders each agreed inside the other.
    // Estate seed 21093056.
    let movers: HashSet<EntityId> = items
        .iter()
        .filter(|i| i.move_to.is_some() && i.entity.entity_type == EntityType::Folder)
        .map(|i| i.entity)
        .collect();
    let mut impossible: HashSet<EntityId> = HashSet::new();
    for item in items {
        if item.entity.entity_type != EntityType::Folder {
            continue;
        }
        let Some(to) = &item.move_to else { continue };
        let map = match item.action {
            Action::ApplyRemoteMove { .. } => &parents.local,
            Action::ApplyLocalMove { .. } => &parents.remote,
            _ => continue,
        };
        let mut cur = to.parent;
        let mut nearest_mover: Option<EntityId> = None;
        let mut guard = 0;
        while let Some(p) = cur {
            if p == item.entity.server_id {
                match nearest_mover {
                    Some(m) => {
                        waits_for.insert(item.entity, m);
                    }
                    None => {
                        impossible.insert(item.entity);
                    }
                }
                break;
            }
            let pid = EntityId::folder(p);
            if nearest_mover.is_none() && movers.contains(&pid) {
                nearest_mover = Some(pid);
            }
            guard += 1;
            if guard > 512 {
                break;
            }
            cur = map.get(&p).copied().flatten();
        }
    }
    (waits_for, impossible)
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
fn find_cycle_breakers(
    waits_for: &HashMap<EntityId, EntityId>,
    parkable: &HashSet<EntityId>,
) -> HashSet<EntityId> {
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
                // Among those that can step aside: a folder being created has
                // nowhere to go, so a cycle through one parks the mover.
                if let Some(victim) = path[at..].iter().copied().filter(|e| parkable.contains(e)).min() {
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

    /// A parent moved into its own child waits for the child to move out,
    /// and with the child not moving at all the parent's move is left out.
    #[test]
    fn a_folder_moving_into_its_own_subtree_waits_for_the_subtree_to_leave() {
        // Folder 1 holds folder 2 locally. The server has 2 at the root and 1
        // inside 2.
        let mut parents = FolderParents::default();
        parents.local.insert(1, None);
        parents.local.insert(2, Some(1));
        let items = vec![
            PlanItem::new(EntityId::folder(1), Action::ApplyRemoteMove { to: placement(Some(2), "A") }, 0)
                .moving(placement(None, "A"), placement(Some(2), "A")),
            PlanItem::new(EntityId::folder(2), Action::ApplyRemoteMove { to: placement(None, "B") }, 1)
                .moving(placement(Some(1), "B"), placement(None, "B")),
        ];
        let p = plan(items, &jd_vfs::Personality::linux(), &parents);
        let order: Vec<EntityId> = p.ordered().iter().map(|o| o.entity).collect();
        assert_eq!(order, vec![EntityId::folder(2), EntityId::folder(1)], "{p:?}");
        assert!(p.broken_cycles.is_empty());

        let items = vec![PlanItem::new(EntityId::folder(1), Action::ApplyRemoteMove { to: placement(Some(2), "A") }, 0)
            .moving(placement(None, "A"), placement(Some(2), "A"))];
        let p = plan(items, &jd_vfs::Personality::linux(), &parents);
        assert!(p.ops.is_empty(), "a move into its own subtree with nothing leaving was planned: {p:?}");
    }

    fn placement(parent: Option<i64>, name: &str) -> Placement {
        Placement {
            parent,
            name: name.into(),
        }
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
        assert_eq!(p.broken_cycles.len(), 1);
        // Deterministic victim, so two devices break the same swap identically.
        assert_eq!(p.broken_cycles[0], EntityId::file(1));
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
        assert_eq!(p.broken_cycles.len(), 1);
        assert_eq!(p.broken_cycles[0], EntityId::file(1));
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
        assert_eq!(p.broken_cycles[0], EntityId::file(1));
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
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
        let p = plan(items, &jd_vfs::Personality::linux(), &FolderParents::default());
        assert!(p.broken_cycles.is_empty());
    }
}
