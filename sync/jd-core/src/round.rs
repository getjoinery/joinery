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
use crate::order::{plan, FolderParents, Plan, PlanItem};
use crate::reconcile::{is_mass_delete, reconcile, Action, Context, Issue, Side};

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
    /// The entries whose deletes were withheld, sorted: what the person is
    /// asked about, and all an approval of it covers (`held_fingerprint`).
    pub held: Vec<EntityId>,
}

/// A short, stable name for a set of withheld deletes: the same entries held
/// in the same directions give the same name on every pass, and any other set
/// gives another. A person approves the deletes they were shown by this name
/// (`DeletePolicy::ApprovedFor`), so an approval cannot carry out a delete
/// that joined the set after they looked -- one more file gone, a second
/// direction held -- and is spent, not stretched, when the set changes.
///
/// Sixteen hex digits of a SHA-256 over the sorted (direction, entity) list.
/// It tells two held sets apart; it guards nothing against a person who
/// can already reach the control channel, so it need not be longer.
pub fn held_fingerprint(paused: &[MassDeletePause]) -> String {
    use sha2::Digest;
    let mut lines: Vec<String> = paused
        .iter()
        .flat_map(|p| {
            let side = match p.direction {
                DeleteDirection::Local => "here",
                DeleteDirection::Remote => "server",
            };
            p.held
                .iter()
                .map(move |id| format!("{side}:{}:{}", id.entity_type, id.server_id))
        })
        .collect();
    lines.sort();
    lines.dedup();
    let digest = sha2::Sha256::digest(lines.join("\n").as_bytes());
    digest.iter().take(8).map(|b| format!("{b:02x}")).collect()
}

/// A record both sides agree on, and the folder it sits in on each side: what
/// a delete of a folder above it takes with it (`run_round`, the guard).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct SyncedRecord {
    pub id: EntityId,
    /// Its folder on this disk, where this round's scan found it.
    pub here: Option<i64>,
    /// Its folder on the server.
    pub there: Option<i64>,
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
    /// Deletes the guard would have withheld that a person had approved, set
    /// by set (`DeletePolicy::ApprovedFor`), and that this round lets through.
    pub approved: Vec<MassDeletePause>,
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
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum DeletePolicy {
    /// Stop and ask. The default, and what a headless run does when it cannot.
    Guard,
    /// The user looked at it and said go ahead.
    Approved,
    /// A person approved the held set they were shown, by its fingerprint
    /// (`held_fingerprint`). This round lets its deletes through only if what
    /// it would hold is exactly that set; anything else is held and asked
    /// about as under `Guard`.
    ApprovedFor(String),
}

/// Run one reconciliation round.
///
/// `synced_total` is the number of entries currently in agreement — the
/// denominator the guard measures against. `synced` is each of them with its
/// folder on each side, which is what a folder's delete is weighed by.
pub fn run_round(
    inputs: Vec<RoundInput>,
    synced_total: usize,
    synced: &[SyncedRecord],
    ctx: &Context,
    policy: DeletePolicy,
    parents: &FolderParents,
    trash_already_queued: &std::collections::HashSet<i64>,
) -> RoundOutcome {
    let mut out = RoundOutcome::default();
    let mut resolved: Vec<(RoundInput, Vec<Action>)> = Vec::new();
    // Files this device agreed on that the server holds edited since, and
    // the folder this round puts each in: a record deleted here whose delete
    // lost to the edit, or one a peer edited, wherever this round resolves it
    // -- the place a move it plans takes it to, either way (a race the server
    // won, or this device's rename merged with a peer's move to another
    // folder, by halves), else where the server has it. The edit comes back
    // there, and so do its folders (below); a file whose own move here keeps
    // it out of a folder is not kept for. So is a file another device saved
    // that this device never had (`arrived`).
    let mut edit_kept: Vec<(EntityId, Option<i64>)> = Vec::new();
    let mut lost_a_delete: std::collections::HashSet<EntityId> = std::collections::HashSet::new();
    let mut arrivals: std::collections::HashSet<EntityId> = std::collections::HashSet::new();

    for input in inputs {
        let res = reconcile(&input.entry, &input.local, &input.remote, ctx);
        let lost = res.issues.iter().any(|i| matches!(i, Issue::DeleteLostToEdit { side: Side::Local, .. }));
        let edited_there = input.entry.id.entity_type == crate::model::EntityType::File
            && input.entry.is_established()
            && input.remote.touched_content()
            && !matches!(input.remote, Delta::Created { .. });
        // A file another device saved that this device never had, on its way
        // here: content the delete of its folder here never saw. New content
        // beats a delete as an edit does (owner decision D1). Not this
        // device's own file never sent -- the user who deleted the folder is
        // the one sending it -- and not one the server has trashed.
        let arrived = input.entry.id.entity_type == crate::model::EntityType::File
            && !input.entry.is_established()
            && !input.entry.id.is_provisional()
            && !input.entry.remote_deleted
            && res.actions.iter().any(|a| matches!(a, Action::Download));
        if arrived {
            edit_kept.push((input.entry.id, input.entry.remote.parent));
            arrivals.insert(input.entry.id);
        }
        if lost || edited_there {
            let moved_to = res.actions.iter().find_map(|a| match a {
                Action::ApplyRemoteMove { to, .. } | Action::ApplyLocalMove { to } => Some(to.parent),
                _ => None,
            });
            let parent = moved_to
                .or_else(|| input.remote.placement().map(|p| p.parent))
                .unwrap_or(input.entry.remote.parent);
            edit_kept.push((input.entry.id, parent));
        }
        if lost {
            lost_a_delete.insert(input.entry.id);
        }
        for issue in res.issues {
            out.issues.push((input.entry.id, issue));
        }
        if !res.actions.is_empty() {
            resolved.push((input, res.actions));
        }
    }

    // What arrives under a folder the user removed here is not brought in:
    // its trash runs, the server trashes the subtree, and the feed forgets
    // what was under it. Except content: a file whose delete here lost to an
    // edit there, and a file another device saved that this device never had.
    // An edit beats a delete, and so does new content; trashed with the
    // folder, the server's cascade took it while its coming here was dropped
    // as an arrival (B-O1; D1, clean2 74230, kill2 75224). So every folder up
    // the chain the server keeps that file in, that this round would trash,
    // is put back here instead, at the server's placement; the file lands in
    // it, and what nobody touched goes to the trash on its own.
    //
    // Only what this round decides. A folder whose trash is still in the
    // journal from an earlier pass is not taken back here: that trash was
    // decided before the edit was heard of, and it is the executor that
    // stands it down (`trash_remote`), after which a later round decides
    // again from here. Until then the edit's download stays dropped with the
    // other arrivals.
    //
    // The server's own cascade is unconditional: an edit that reaches the
    // server after this device's last read of the feed and before its trash
    // still goes with the folder, to the server's trash -- recoverable, not
    // visible. Closing that needs the server to refuse a trash once something
    // under the folder has changed since a given cursor.
    let mut kept_for_an_edit: std::collections::HashSet<i64> = std::collections::HashSet::new();
    for (_, mut parent) in edit_kept.iter().copied() {
        let mut guard = 0;
        while let Some(id) = parent {
            if !kept_for_an_edit.insert(id) {
                break;
            }
            guard += 1;
            if guard > 512 {
                break;
            }
            parent = parents.remote.get(&id).copied().flatten();
        }
    }
    let put_back = |input: &RoundInput, action: &Action| {
        input.entry.id.entity_type == crate::model::EntityType::Folder
            && matches!(action, Action::TrashRemote)
            && kept_for_an_edit.contains(&input.entry.id.server_id)
            && !trash_already_queued.contains(&input.entry.id.server_id)
    };
    let restored: std::collections::HashSet<i64> = resolved
        .iter()
        .filter(|(input, actions)| actions.iter().any(|a| put_back(input, a)))
        .map(|(input, _)| input.entry.id.server_id)
        .collect();
    for (id, mut parent) in edit_kept {
        let mut guard = 0;
        let mut folder_kept = false;
        while let Some(f) = parent {
            if restored.contains(&f) {
                folder_kept = true;
                break;
            }
            guard += 1;
            if guard > 512 {
                break;
            }
            parent = parents.remote.get(&f).copied().flatten();
        }
        if folder_kept {
            if arrivals.contains(&id) {
                out.issues.push((id, Issue::ArrivalKeptFolder));
            } else if lost_a_delete.contains(&id) {
                for (who, issue) in out.issues.iter_mut() {
                    if *who == id {
                        if let Issue::DeleteLostToEdit { side: Side::Local, folder_kept: kept } = issue {
                            *kept = true;
                        }
                    }
                }
            } else {
                // Edited and moved into it from elsewhere: the user's delete of
                // the folder is what lost, and says so on the file.
                out.issues.push((id, Issue::DeleteLostToEdit { side: Side::Local, folder_kept: true }));
            }
        }
    }
    for (input, actions) in resolved.iter_mut() {
        for action in actions.iter_mut() {
            if put_back(input, action) {
                *action = Action::CreateLocalFolder { placement: input.entry.remote.clone() };
            }
        }
    }

    // A file or folder this device holds outside a folder it is deleting is
    // not handed to that folder by a peer's move. Applied, the server's move
    // lands it in the folder this device's own trash then takes, with
    // nobody's delete behind it: the user's rotation of ring folders on FAT,
    // read as a new folder and the old one gone, while the file's move to the
    // new one raced a peer's move into the old (clean2 74014), and a file
    // this device never moved that a peer moved into the folder going (plat3
    // 75424, swaps off). It stays where it is here, and is moved there on the
    // server, planned from where the server has it: that is the copy the move
    // acts on, so the executor's move agrees with the plan, where planned from
    // the agreed placement it is overtaken every pass. The folder's trash
    // waits for that move: `trash_remote` retries while a queued move's
    // entity still sits under it on the server. A folder D1 brought back
    // above for an edit is not going, and what arrives in it stays: an
    // edit's folders are all brought back, so this never meets an edit. A
    // trash still in the journal from an earlier pass is not this round's:
    // the server refuses it once the peer's move has changed the folder
    // (`unchanged_since`), the executor stands it down, and the round that
    // decides it again applies this.
    let trashed_here: std::collections::HashSet<i64> = resolved
        .iter()
        .filter(|(input, actions)| {
            input.entry.id.entity_type == crate::model::EntityType::Folder
                && actions.iter().any(|a| matches!(a, Action::TrashRemote))
        })
        .map(|(input, _)| input.entry.id.server_id)
        .collect();
    if !trashed_here.is_empty() {
        let under_a_trashed = |mut at: Option<i64>| {
            let mut guard = 0;
            while let Some(f) = at {
                if trashed_here.contains(&f) {
                    return true;
                }
                guard += 1;
                if guard > 512 {
                    break;
                }
                at = parents.remote.get(&f).copied().flatten();
            }
            false
        };
        for (input, actions) in resolved.iter_mut() {
            let id = input.entry.id;
            // Where this device holds it: where its user moved it, in a race
            // the server won, or where it stands, for one only the server
            // moved.
            let raced = out.issues.iter().find_map(|(who, issue)| match issue {
                Issue::MoveRaceServerWon { local_wanted, .. } if *who == id => Some(local_wanted.clone()),
                _ => None,
            });
            let unmoved_here = input.local.is_none().then(|| input.entry.local_placement().clone());
            let Some(local_wanted) = raced.or(unmoved_here) else {
                continue;
            };
            let into_a_trashed = actions
                .iter()
                .any(|a| matches!(a, Action::ApplyRemoteMove { to, .. } if under_a_trashed(to.parent)));
            if into_a_trashed && !under_a_trashed(local_wanted.parent) {
                input.entry.synced_placement = Some(input.entry.remote.clone());
                *actions = vec![Action::ApplyLocalMove { to: local_wanted }];
                out.issues.retain(|(who, issue)| !(*who == id && matches!(issue, Issue::MoveRaceServerWon { .. })));
            }
        }
    }

    // A move race this side kept the folder half of takes the file out of a
    // folder the server still has it in: the file goes where this side put it,
    // the agreement stays at the server's place, and the next scan sends the
    // kept half as an ordinary move. A trash of that folder decided in the same
    // round ran first, and the server's cascade took the file -- with whatever
    // the server had of it, a peer's edit included (B-E8, clean3 74927). Such
    // a folder's trash waits a round: the next one sends the move, and the
    // trash waits for a move still on its way out (`trash_remote`).
    let chain = |mut parent: Option<i64>| {
        let mut out: Vec<i64> = Vec::new();
        let mut guard = 0;
        while let Some(id) = parent {
            out.push(id);
            guard += 1;
            if guard > 512 {
                break;
            }
            parent = parents.remote.get(&id).copied().flatten();
        }
        out
    };
    let mut left_by_a_kept_half: std::collections::HashSet<i64> = std::collections::HashSet::new();
    for (_, actions) in resolved.iter() {
        for action in actions {
            if let Action::ApplyRemoteMove { to, agree_at: Some(server_place) } = action {
                let going_to = chain(to.parent);
                for f in chain(server_place.parent) {
                    if !going_to.contains(&f) {
                        left_by_a_kept_half.insert(f);
                    }
                }
            }
        }
    }
    // Withheld, the trash is as decided as one still in the journal: nothing
    // arrives under such a folder meanwhile (`going`, below), or a move into it
    // this round rebuilt the directory the user deleted and the next scan read
    // the folder as present again. The executor's own guard (a move into a
    // folder not on this disk is overtaken) already refuses that move; this
    // keeps the round from planning it.
    let mut withheld_trash: std::collections::HashSet<i64> = std::collections::HashSet::new();
    if !left_by_a_kept_half.is_empty() {
        for (input, actions) in resolved.iter_mut() {
            if input.entry.id.entity_type == crate::model::EntityType::Folder
                && left_by_a_kept_half.contains(&input.entry.id.server_id)
                && actions.iter().any(|a| matches!(a, Action::TrashRemote))
            {
                actions.retain(|a| !matches!(a, Action::TrashRemote));
                withheld_trash.insert(input.entry.id.server_id);
            }
        }
    }

    // Count what this round would remove, in each direction separately. A round
    // that tidies up locally is not evidence about the server and vice versa,
    // so a legitimate bulk local cleanup must not be blocked by a coincidental
    // handful of remote deletes.
    //
    // Counted in records, not in deletes: a folder's delete removes every
    // synced record under it as well. A folder deleted here reaches the round
    // as a delete of each record in it, but a folder trashed on the server
    // arrives as one change, and counted as one delete, a web trash of the
    // folder holding everything emptied every device without asking (B1). The
    // records under it on both sides are what its delete takes -- one moved
    // out on either side is moved out first -- so they are counted, held and
    // named with it, and an approval covers exactly them.
    let local_deletes = removed_by(&resolved, synced, parents, |a| matches!(a, Action::TrashLocal));
    let remote_deletes = removed_by(&resolved, synced, parents, |a| matches!(a, Action::TrashRemote));

    let mut paused: Vec<MassDeletePause> = Vec::new();
    if policy != DeletePolicy::Approved {
        if is_mass_delete(local_deletes.len(), synced_total) {
            paused.push(MassDeletePause {
                direction: DeleteDirection::Local,
                would_delete: local_deletes.len(),
                synced_total,
                held: local_deletes,
            });
        }
        if is_mass_delete(remote_deletes.len(), synced_total) {
            paused.push(MassDeletePause {
                direction: DeleteDirection::Remote,
                would_delete: remote_deletes.len(),
                synced_total,
                held: remote_deletes,
            });
        }
    }
    // An approval is for the deletes the person was shown, all of them or
    // none: a set that has grown, shrunk or turned since is a question they
    // have not answered, and it is held and asked again.
    if let DeletePolicy::ApprovedFor(shown) = &policy {
        if !paused.is_empty() && held_fingerprint(&paused) == *shown {
            out.approved = std::mem::take(&mut paused);
        }
    }
    let block_local = paused.iter().any(|p| p.direction == DeleteDirection::Local);
    let block_remote = paused.iter().any(|p| p.direction == DeleteDirection::Remote);
    out.paused = paused;

    // Folders the user removed on this computer: their trash is in the plan
    // this round, or still in the journal from an earlier one (refused once
    // by the network, or a kill mid-call -- such a folder is busy and out of
    // this round, and its trash is just as decided). Nothing arrives under
    // them meanwhile (above). Transfers run before deletes, and a landing
    // creates its parent directories -- on a real disk too -- so a download
    // planned under such a folder rebuilt the directory the user had just
    // deleted, the trash then ran on the server, and the next scan met a
    // directory nobody knew, holding the download, and minted a new folder
    // for it: the user's delete undone, in a folder nobody made (the reset's
    // WP1d finding C2). Walked up the REMOTE chain, which is where an
    // arrival's parent is stated. Read before the mass-delete withholding
    // below, deliberately: while that pause holds a folder's trash for a
    // person to answer, the arrivals under it wait with it.
    let mut going: std::collections::HashSet<i64> = trash_already_queued.clone();
    going.extend(withheld_trash.iter().copied());
    going.extend(
        resolved
            .iter()
            .filter(|(input, actions)| {
                input.entry.id.entity_type == crate::model::EntityType::Folder
                    && actions.iter().any(|a| matches!(a, Action::TrashRemote))
            })
            .map(|(input, _)| input.entry.id.server_id),
    );
    let under_a_going_folder = |mut parent: Option<i64>| -> bool {
        let mut guard = 0;
        while let Some(id) = parent {
            if going.contains(&id) {
                return true;
            }
            guard += 1;
            if guard > 512 {
                return false;
            }
            parent = parents.remote.get(&id).copied().flatten();
        }
        false
    };

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
            let arriving_under_a_going_folder = match &action {
                Action::Download | Action::CreateLocalFolder { .. } => {
                    under_a_going_folder(input.entry.remote.parent)
                }
                Action::ApplyRemoteMove { to, .. } => under_a_going_folder(to.parent),
                _ => false,
            };
            if arriving_under_a_going_folder {
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
            // A folder being created on this disk wants a slot too, and a
            // move whose destination is that folder wants it to exist first.
            if let Action::CreateLocalFolder { placement } = &action {
                item = item.arriving(placement.clone());
            }
            // A delete frees a slot a move in this round may be waiting for:
            // on the server where it trashes there, here where it trashes here.
            let freed = match &action {
                Action::TrashRemote => Some(input.entry.remote.clone()),
                Action::TrashLocal => input
                    .local
                    .placement()
                    .cloned()
                    .or_else(|| input.entry.synced_placement.clone()),
                _ => None,
            };
            if let Some(slot) = freed {
                item = item.vacating(slot);
            }
            items.push(item);
        }
    }

    out.plan = plan(items, &ctx.personality, parents);
    out
}

fn move_target(action: &Action) -> Option<crate::model::Placement> {
    match action {
        Action::ApplyLocalMove { to } | Action::ApplyRemoteMove { to, .. } => Some(to.clone()),
        _ => None,
    }
}

/// What the deletes of this kind would remove, sorted and once each: every
/// entry with such a delete, and every synced record under a folder with
/// one, on both sides.
fn removed_by(
    resolved: &[(RoundInput, Vec<Action>)],
    synced: &[SyncedRecord],
    parents: &FolderParents,
    pred: impl Fn(&Action) -> bool,
) -> Vec<EntityId> {
    let mut ids: std::collections::BTreeSet<EntityId> = resolved
        .iter()
        .filter(|(_, actions)| actions.iter().any(&pred))
        .map(|(input, _)| input.entry.id)
        .collect();
    let folders: std::collections::HashSet<i64> = ids
        .iter()
        .filter(|id| id.entity_type == crate::model::EntityType::Folder)
        .map(|id| id.server_id)
        .collect();
    if !folders.is_empty() {
        // Asked of the folder itself before its own parent, so a folder the
        // map leaves out -- one the server has deleted -- still holds what is
        // in it.
        let under = |mut at: Option<i64>, chain: &HashMap<i64, Option<i64>>| {
            let mut guard = 0;
            while let Some(f) = at {
                if folders.contains(&f) {
                    return true;
                }
                guard += 1;
                if guard > 512 {
                    break;
                }
                at = chain.get(&f).copied().flatten();
            }
            false
        };
        for record in synced {
            if under(record.here, &parents.local) && under(record.there, &parents.remote) {
                ids.insert(record.id);
            }
        }
    }
    ids.into_iter().collect()
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
    if attempts <= 0 {
        return BASE_MS;
    }
    let shift = attempts.min(20) as u32;
    BASE_MS.saturating_mul(1u64 << shift.min(20)).min(RETRY_CAP_MS)
}

/// The longest wait between two tries of an op (`retry_delay_ms`).
pub const RETRY_CAP_MS: u64 = 15 * 60 * 1_000;

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
            replaces: None,
            stand_in: None,
            stands_at: None,
            own_file: None,
            last_seen_sha: None,
            own_file_seen_at: None,
            remote_unsyncable: false,
        }
    }

    fn ctx() -> Context {
        Context {
            date: "2026-07-16".into(),
            device_name: "PC".into(),
            conflict_suffix: 1,
            personality: jd_vfs::Personality::linux(),
            trace: false,
        }
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
        let out = run_round(inputs, 100, &[], &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());
        assert_eq!(out.plan.ops.len(), 2);
        assert!(!out.needs_confirmation());
    }

    #[test]
    fn a_routine_number_of_deletes_just_happens() {
        let out = run_round(
            deleted_on(5, Side::ThisComputer),
            500,
            &[],
            &ctx(),
            DeletePolicy::Guard,
            &FolderParents::default(),
            &Default::default(),
        );
        assert!(!out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 5);
    }

    #[test]
    fn a_wholesale_local_wipe_is_held_back_for_a_person() {
        // What an unmounted volume, or ransomware, looks like from here: every
        // local file gone, which would propagate as trashing the whole Drive.
        let out = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &[],
            &ctx(),
            DeletePolicy::Guard,
            &FolderParents::default(),
            &Default::default(),
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
        let out = run_round(
            deleted_on(200, Side::Server),
            400,
            &[],
            &ctx(),
            DeletePolicy::Guard,
            &FolderParents::default(),
            &Default::default(),
        );
        assert_eq!(out.paused[0].direction, DeleteDirection::Local);
        assert!(out.plan.is_empty());
    }

    #[test]
    fn approval_lets_the_same_round_through_unchanged() {
        let out = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &[],
            &ctx(),
            DeletePolicy::Approved,
            &FolderParents::default(),
            &Default::default(),
        );
        assert!(!out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 200);
    }

    #[test]
    fn a_pause_names_every_delete_it_holds() {
        let out = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &[],
            &ctx(),
            DeletePolicy::Guard,
            &FolderParents::default(),
            &Default::default(),
        );
        let held: Vec<EntityId> = (1..=200).map(EntityId::file).collect();
        assert_eq!(out.paused[0].held, held);
    }

    #[test]
    fn a_held_set_is_named_by_what_it_holds_and_nothing_else() {
        let pause = |direction, ids: &[i64]| MassDeletePause {
            direction,
            would_delete: ids.len(),
            synced_total: 400,
            held: ids.iter().copied().map(EntityId::file).collect(),
        };
        let a = held_fingerprint(&[pause(DeleteDirection::Remote, &[1, 2, 3])]);
        assert_eq!(a.len(), 16);
        // The same set, however it was listed, is the same name.
        assert_eq!(a, held_fingerprint(&[pause(DeleteDirection::Remote, &[3, 1, 2])]));
        // One more, one fewer, or the same entries going the other way: a
        // question the person has not been asked.
        assert_ne!(a, held_fingerprint(&[pause(DeleteDirection::Remote, &[1, 2, 3, 4])]));
        assert_ne!(a, held_fingerprint(&[pause(DeleteDirection::Remote, &[1, 2])]));
        assert_ne!(a, held_fingerprint(&[pause(DeleteDirection::Local, &[1, 2, 3])]));
        assert_ne!(
            a,
            held_fingerprint(&[pause(DeleteDirection::Remote, &[1, 2, 3]), pause(DeleteDirection::Local, &[9])])
        );
        // A folder is not the file with its number.
        let folder = MassDeletePause { held: vec![EntityId::folder(1), EntityId::file(2), EntityId::file(3)], ..pause(DeleteDirection::Remote, &[]) };
        assert_ne!(a, held_fingerprint(&[folder]));
    }

    #[test]
    fn an_approval_lets_through_exactly_the_set_it_was_given_for() {
        let asked = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &[],
            &ctx(),
            DeletePolicy::Guard,
            &FolderParents::default(),
            &Default::default(),
        );
        let shown = held_fingerprint(&asked.paused);
        let out = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &[],
            &ctx(),
            DeletePolicy::ApprovedFor(shown),
            &FolderParents::default(),
            &Default::default(),
        );
        assert!(!out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 200);
        assert!(out.plan.ops.iter().all(|op| op.action == Action::TrashRemote));
        assert_eq!(out.approved, asked.paused, "the round says which approval it used");
    }

    #[test]
    fn an_approval_for_a_set_that_has_changed_lets_nothing_through() {
        // Shown 200, and one more file went before the answer came back: the
        // person never saw that one, so none of them go and the round asks
        // about all 201.
        let asked = run_round(
            deleted_on(200, Side::ThisComputer),
            400,
            &[],
            &ctx(),
            DeletePolicy::Guard,
            &FolderParents::default(),
            &Default::default(),
        );
        let shown = held_fingerprint(&asked.paused);
        let out = run_round(
            deleted_on(201, Side::ThisComputer),
            400,
            &[],
            &ctx(),
            DeletePolicy::ApprovedFor(shown.clone()),
            &FolderParents::default(),
            &Default::default(),
        );
        assert!(out.plan.is_empty(), "a delete the person was never shown went through");
        assert!(out.approved.is_empty());
        assert_eq!(out.paused[0].would_delete, 201);
        assert_ne!(held_fingerprint(&out.paused), shown);
    }

    #[test]
    fn an_approval_with_nothing_held_changes_nothing() {
        let out = run_round(
            deleted_on(5, Side::ThisComputer),
            500,
            &[],
            &ctx(),
            DeletePolicy::ApprovedFor("0123456789abcdef".into()),
            &FolderParents::default(),
            &Default::default(),
        );
        assert!(out.approved.is_empty() && !out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 5);
    }

    /// A folder the server trashed, one change in the round, and the synced
    /// records under it: `files` in it on both sides, two at the root.
    fn a_folder_trashed_on_the_server(files: i64) -> (Vec<RoundInput>, Vec<SyncedRecord>) {
        let art = Entry {
            id: EntityId::folder(1),
            synced_content: None,
            remote_content: None,
            ..entry(1, "Art")
        };
        let inputs = vec![RoundInput {
            entry: art,
            local: Delta::None,
            remote: Delta::Deleted,
            depth: 0,
        }];
        let mut synced = vec![SyncedRecord { id: EntityId::folder(1), here: None, there: None }];
        for n in 0..files {
            synced.push(SyncedRecord { id: EntityId::file(100 + n), here: Some(1), there: Some(1) });
        }
        synced.push(SyncedRecord { id: EntityId::file(7), here: None, there: None });
        synced.push(SyncedRecord { id: EntityId::file(8), here: None, there: None });
        (inputs, synced)
    }

    #[test]
    fn a_folder_trashed_on_the_server_weighs_everything_in_it() {
        // One change in the feed, sixty files on this disk. Counted as one
        // delete, the whole folder left this computer without anybody asked
        // (B1).
        let (inputs, synced) = a_folder_trashed_on_the_server(60);
        let out = run_round(inputs, synced.len(), &synced, &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());
        assert_eq!(out.paused.len(), 1);
        assert_eq!(out.paused[0].direction, DeleteDirection::Local);
        assert_eq!(out.paused[0].would_delete, 61);
        assert!(out.paused[0].held.contains(&EntityId::folder(1)) && out.paused[0].held.contains(&EntityId::file(159)));
        assert!(!out.paused[0].held.contains(&EntityId::file(7)), "a file outside the folder was counted");
        assert!(out.plan.is_empty(), "the folder's trash went through unasked");

        // Approved by the set it showed, the one trash goes through.
        let shown = held_fingerprint(&out.paused);
        let (inputs, synced) = a_folder_trashed_on_the_server(60);
        let out = run_round(inputs, synced.len(), &synced, &ctx(), DeletePolicy::ApprovedFor(shown), &FolderParents::default(), &Default::default());
        assert_eq!(out.plan.ops.len(), 1);
        assert_eq!(out.plan.ops[0].action, Action::TrashLocal);
    }

    #[test]
    fn a_folder_trashed_on_the_server_with_less_than_the_threshold_in_it_just_goes() {
        let (inputs, synced) = a_folder_trashed_on_the_server(30);
        let out = run_round(inputs, synced.len(), &synced, &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());
        assert!(!out.needs_confirmation());
        assert_eq!(out.plan.ops.len(), 1);
        assert_eq!(out.plan.ops[0].action, Action::TrashLocal);
    }

    #[test]
    fn a_record_moved_out_of_a_deleted_folder_on_either_side_is_not_counted_with_it() {
        // Moved out here, or by the server, before the trash: the move goes
        // first and the trash does not take it.
        let (inputs, mut synced) = a_folder_trashed_on_the_server(60);
        for r in synced.iter_mut().filter(|r| r.id.entity_type == crate::model::EntityType::File && r.id.server_id >= 100) {
            if r.id.server_id % 2 == 0 {
                r.here = None;
            } else {
                r.there = Some(42);
            }
        }
        let parents = FolderParents { local: Default::default(), remote: [(42, None)].into_iter().collect() };
        let out = run_round(inputs, synced.len(), &synced, &ctx(), DeletePolicy::Guard, &parents, &Default::default());
        assert!(!out.needs_confirmation(), "{:?}", out.paused);
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

        let out = run_round(inputs, 400, &[], &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());

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

        let out = run_round(inputs, 400, &[], &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());

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
        let out = run_round(inputs, 10, &[], &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());

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
        let out = run_round(inputs, 10, &[], &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());
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
        let out = run_round(inputs, 10, &[], &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());
        let stages: Vec<Stage> = out.plan.ordered().iter().map(|o| o.stage).collect();
        assert_eq!(stages, vec![Stage::CreateFolders, Stage::Transfer]);
    }

    #[test]
    fn an_empty_round_is_empty() {
        let out = run_round(vec![], 0, &[], &ctx(), DeletePolicy::Guard, &FolderParents::default(), &Default::default());
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
