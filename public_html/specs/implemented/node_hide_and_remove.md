# Hide a site, or remove it for good

**Status:** Implemented 2026-10-09 (server_manager 1.30.39, migration `sm_018_node_records_cleanup`, test
`node_hide_and_remove`, 59 checks; the Actions menu and the two-step attest confirm checked on a rendered page). Live
checks open in the live verification queue under node_hide_and_remove. Departures from the draft, found while building:

- The clean-up keeps nodeless **Publish Upgrade** jobs (135 on dev): before September they ran on the management
  node itself with no node, so they never belonged to a removed node. It also deletes jobs naming a node that no
  longer exists (114 on dev; the null rule never reached them).
- A shelf object whose run is gone but whose space is live keeps its row and stops naming the run; only rows of a
  missing space, or with no space and a missing run, are deleted.
- `svo_sps_storage_space_id` becomes cascade (a space removed for good takes its ledger rows), and the backup-target
  and service-tenant rules for spaces and runs become `permanent_delete` too (the §4 "check while building").
- Remove Permanently on a live node releases its site records (domain parking, subscription note) rather than running
  the whole hide: its jobs and space are deleted anyway.
- The backup check runs on render only for a hidden node, as before; for a live one it runs on submit.

## The ask

A node's Actions menu offers **Remove from Dashboard**. It becomes two actions:

- **Hide from Dashboard** — for production sites. Every record stays; the site drops out of every
  list and nothing keeps working on it. Reversible with **Restore to Dashboard**.
- **Remove Permanently** — every record the node owns goes, jobs included, through the deletion
  system's declared rules. A machine we host must already be gone; a machine we don't host is simply
  no longer managed and keeps running.

**Permanently Delete Site** (tear the container down on its host) is unchanged and separate.

## What exists today

- `delete_node` → `ManagedNode::soft_delete()` (`data/managed_nodes_class.php`): releases the
  provisioning record and hosted trial (soft), parks a bought domain, cancels unfinished jobs, drains
  the node's backup space, then soft-deletes the node. `IncidentReconciler::clear_removed()` closes its
  open incidents.
- `purge_node` (`logic/node_detail_actions_logic.php`) → `ManagedNode::permanent_delete()`: allowed
  only on an already-removed node, after the slug is typed, and refused while offsite backups exist
  (`StorageSpace::owner_object_count`). Removed nodes are listed with `?show_all=1`.
- No restore.

## Decisions

- **Q1 (A):** Hiding keeps its tidy-up — cancel unfinished jobs, release the provisioning record and
  trial (soft), park a bought domain, drain the backup space. Left undone, each keeps something running
  against a site nobody watches.
- **Q2 (A):** Add **Restore to Dashboard**. It does not reverse the tidy-up, and its confirmation says
  so.
- **Q3 (A + account):** Every node records **where it is hosted**: Joinery Main Linode, Joinery Test
  Linode, a customer's connected cloud account, or elsewhere. "Hosted by us" means one of our accounts.
- **Q4 (A):** Removing a hosted-by-us node permanently is refused until its machine is gone. The
  platform never deletes a cloud instance itself.
- **Q5 (A):** A one-time migration deletes what earlier permanent deletes left behind.
- **Q6 (B):** A plane holds one operator token (dev: Test, getjoinery: Main). Where it cannot ask the
  provider about the node's account, the person attests the machine is deleted by typing its instance
  ID (or IP); the attestation is logged.

## Design

### 1. Where a node is hosted

`mgn_cloud_account` (today `main`/`test`, stamped on every node for the dashboard tabs) becomes the
node's hosting account. Values:

| Value | Meaning | Hosted by us |
|---|---|---|
| `main` | Joinery Main Linode | yes |
| `test` | Joinery Test Linode | yes |
| `customer` | the customer's connected cloud account (`cvp_cca_customer_cloud_account_id` names it) | no |
| `external` | anywhere else (a site someone runs on their own server) | no |

- `CloudAccounts` gains `customer`/`external` with labels and `is_ours($node)`. Dashboard tabs:
  `customer` and `external` nodes show on the Main tab (`normalize()` already maps unknowns to main;
  keep that for the tab, never for `is_ours`).
- Set from evidence where it exists: a provisioning record with `cvp_hosting_mode` `operator` → the
  plane's account; `customer`/`transferred` → `customer`. A node placed on a host takes its host's
  account (as `of_node()` does today).
- A hand-added node defaults to the plane's account (ours — the safe direction). Editable as "Hosted
  at" in Connection Settings and on the Add Node form.
- Backfill: a migration sets `customer` for nodes whose provisioning record says so. Hand-added nodes
  keep their current stamp; the owner reviews the list (dev: Scrolldaddy, the two DNS boxes, Getjoinery,
  jeremytunnell.com, the relay, docker-prod, …) and marks any that are not ours `external`.

### 2. Hide from Dashboard

The current `delete_node`, renamed in the menu. Two fixes so a hidden site is actually hidden:

- **Recent Jobs** (`views/admin/index.php`) puts a job whose node is not in the listed set on the Main
  tab (`?? CloudAccounts::MAIN`). Skip jobs of removed nodes unless `show_all`.
- **Jobs page** (`views/admin/jobs.php`): exclude jobs of removed nodes by default, with the same
  show-all toggle.

### 3. Restore to Dashboard

New action `restore_node` on a removed node's Actions menu (POST, CSRF, confirm):

- Refused if a live node now has the same slug ("Another site now uses the slug X").
- `ManagedNode::undelete()` (override of `SystemBase::undelete()`): clears `mgn_delete_time`, and
  reopens the node's draining backup space as active when it still holds one and nothing else is
  active for the node. Nothing else is reversed.
- Confirmation text: "Restore this site to the dashboard? Its agent is accepted again and monitoring
  resumes. Jobs cancelled when it was hidden stay cancelled, a removed provisioning record stays
  removed, and a parked domain stays parked."
- Verify while building: the agent channel accepts the restored node with no re-pairing, and the next
  fleet backup writes to the reopened space.

### 4. Remove Permanently

One action on a live **or** hidden node (`purge_node`, with its "hide first" guard removed): typed
slug, then the guards, then hide's tidy-up, then `permanent_delete()` — one transaction where the
model allows.

**Guards, in order** (each refusal says what to do):

1. Offsite backups exist for the node → refuse (unchanged).
2. **Hosted by us, container site:** refuse unless the container was verified gone — "Use Permanently
   Delete Site first." Evidence: `process_decommission_node` stamps a new `mgn_site_removed_time` on
   the victim when the host verifies the teardown (today that fact lives only in the host's job JSON).
   A node whose site was never confirmed (the existing `$site_ever_confirmed` test) has nothing to tear
   down and passes.
3. **Hosted by us, own machine:** refuse unless the provider says the instance is gone. Instance ID
   from the provisioning record (`cvp_instance_id`), else found by the node's IPv4 or IPv6 in
   `listInstances()` on that account. "Not found" passes; any other provider answer refuses. When
   this plane holds no token for the node's account (Q6), the person attests instead: the form asks
   them to type the instance ID, or the machine's IP address when no instance ID is recorded, under
   "I deleted this machine at <account label>". A match passes; the attestation (user, node name and
   slug, account, what was typed) is written to the error log as `[NODE_REMOVE_ATTEST]` before the
   delete, since the node's own record goes with it.
4. **Not hosted by us:** no machine guard. The confirmation says the site keeps running and is no
   longer managed.

**Rule changes** (each a `$foreign_key_actions` edit; the deletion system does the rest):

| Child | Today | Becomes | Why |
|---|---|---|---|
| `mjb_management_jobs.mjb_mgn_managed_node_id` | null | cascade | owned history; jobs have no children |
| `cvp_customer_cloud_provisions.cvp_mgn_managed_node_id` | null | permanent_delete | owned; its trial and transfers cascade from it, holds sealed passwords |
| `sps_storage_spaces.sps_mgn_managed_node_id` | cascade | permanent_delete | **B1**: has children; flat cascade strands them |
| `svr_shelf_runs.svr_mgn_managed_node_id` | cascade | permanent_delete | **B1**: has children (shelf objects, jobs) |
| `mgn_managed_nodes.mgn_copy_of_node_id` | unregistered | null (`source_table`) | **B2**: a copy's source |
| `cvp_customer_cloud_provisions.cvp_source_node_id` | unregistered | null (`source_table`) | **B2**: a copy provision's source |

Unchanged (mere references, the other side stays meaningful): join requests, relays, mail fleet
shards, hosts, machine and instance transfers, site copies, service tenants, registered domains (kept
for the buyer). Incidents already `permanent_delete`.

Check while building: `storage_spaces` and `shelf_runs` also cascade from backup targets and service
tenants — move those to `permanent_delete` too if the same trap applies, and verify a space's
`permanent_delete()` removes or detaches its shelf objects rather than leaving them pointing at nothing.

### 5. Clean-up migration (Q5)

One data migration, idempotent, on every node (a no-op where the tables are empty):

- delete jobs with `mjb_mgn_managed_node_id IS NULL` (every job is created for a node, so these all
  belonged to removed-for-good nodes; dev: 620);
- delete shelf objects whose `svo_svr_shelf_run_id` or `svo_sps_storage_space_id` names a row that no
  longer exists (dev: 30 on runs, 0 on spaces).

Guard the table and columns exist (plugin-table migration rule).

## Menu

| Node state | Items |
|---|---|
| Live | Hide from Dashboard · Remove Permanently… · Permanently Delete Site… (container sites) |
| Hidden | Restore to Dashboard · Remove Permanently… · Permanently Delete Site… (if a site was ever confirmed) |

Remove Permanently shows its refusal up front (as the backup block does today) when a guard already
fails on render; the provider check runs only on submit.

## Tests

- `ManagedNode` hide → restore round trip: listed again, agent accepted, space active, cancelled jobs
  stay cancelled, slug collision refused.
- Remove Permanently on a fixture node with jobs, incidents, a provisioning record with trial and
  transfer, a storage space with runs and shelf objects, and a copy pointing at it: afterwards none of
  the owned rows exist and nothing references the node id (sweep every column in §4's tables).
- Guards: hosted-by-us container without `mgn_site_removed_time` refused; own machine with a fake
  provider reporting it present refused, absent passes, provider error refused; no token for the
  account → wrong typed ID refused, matching ID passes and logs `[NODE_REMOVE_ATTEST]`; `external`
  passes.
- Dashboard Recent Jobs and Jobs page omit a hidden node's jobs unless `show_all`.
- Migration on a fixture with nodeless jobs and dangling shelf objects; second run is a no-op.
- `referential_integrity` stays green.

