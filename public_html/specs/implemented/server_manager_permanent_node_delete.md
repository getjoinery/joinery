# Server Manager — Permanent Node Delete

**Status:** IMPLEMENTED (2026-07-24). Two-tier delete (Remove from Dashboard / Permanently
Delete Site), target-side Stored Backups browser, show-all-removed toggle, Permanently Delete
Entry with escrow/backup guards, and the evidence-based existence gate all built, tested, and
live-verified — including the first real teardown of the stale jeremytunnell container.
**Plugin:** server_manager
**Motivation:** The jeremytunnell.com move (2026-07-24) left a stale container running
on docker-prod (`23.239.11.53`) and a duplicate node record in the dashboard, because
the only "delete" the dashboard offers removes the *record* and leaves the *site*
running. Tearing the site down was about to be hand-run (`docker rm` / `docker volume
rm` / `a2dissite` one-offs). That is exactly the kind of destructive, un-tested,
one-off operation that should be a first-class, pre-tested platform capability.

---

## Problem

Today the node detail page has one delete action (`node_detail_actions_logic.php`,
`case 'delete_node'`). It calls `$node->soft_delete()` — a control-plane record change
only. The live site on the host (container, volumes, database, uploads, reverse-proxy
vhost, image) keeps running and serving traffic. Nothing in Server Manager can retire
the actual site.

The result is the orphan we just hit: the record disappears from (or lingers in) the
dashboard while the real deployment runs on untended, consuming a host slot, a port,
disk, and — worst case — still answering on its old domain.

## What already exists (and is tested)

- **`maintenance_scripts/sysadmin_tools/remove_account.sh` (v2.0)** already performs a
  complete host-side teardown and auto-detects deployment type. For a **docker** site it
  stops + removes the container, removes every `${site}_*` volume (postgres, uploads,
  config, sessions, caches, logs, backups), removes the `joinery-${site}:latest` image,
  and clears the `/root/${site}-build` build dir. For a **bare-metal** site it
  `a2dissite`s + removes the vhost, `configtest`s and reloads Apache, `rm -rf`s the site
  root and `${site}_test` root, and drops the `${site}` and `${site}_test` databases.
  Verified against the live jeremytunnell footprint — it matches exactly (10 volumes,
  the image, the vhost, the site root).
- **The job engine** (`JobCommandBuilder` → agent → `JobResultProcessor`) already ships
  and runs `sysadmin_tools/*` scripts on nodes (backup/restore do this today), supports
  `on_host` steps for docker hosts, and records structured results.

So this feature is **not** new teardown logic. It is: wire the existing tested script
into a guarded Server Manager job, add a machine-readable contract to the script, and
inventory the control-plane records that must also be cleaned up.

---

## Goal — a two-tier delete model

The node detail page offers two clearly distinct destructive actions:

1. **Remove from dashboard** (existing `delete_node`, unchanged) — record-only
   soft-delete. For nodes Server Manager should stop *tracking* but not *destroy*
   (e.g. a box handed back to its owner, a node managed elsewhere now). Renamed in the
   UI to make the "record only, site keeps running" meaning obvious.

2. **Permanently delete** (new) — record cleanup **plus** a host-side teardown job that
   runs `remove_account.sh`. This is the "this site is dead, erase it" action. Guarded
   by type-to-confirm and a plan preview.

Both require permission 10 and a valid `SmAdminCsrf` token (consistent with the rest of
the hardened plugin).

---

## The new job: `decommission_node`

A new job type built by `JobCommandBuilder::build_decommission_node($node, $params)`.

**Steps (docker node on a shared host):**
1. **Plan / pre-flight** (`on_host`) — confirm the container exists and list what will be
   destroyed (`docker ps -a`, `docker volume ls | grep ^${site}_`, vhost presence).
   Emits `DECOMMISSION_PLAN_OK` or, if nothing is found, `DECOMMISSION_NOTHING_TO_DO`.
2. **Ship the remover** — place `remove_account.sh` on the host at
   `/tmp/joinery_decommission_<transfer_id>/` (the install/backup pattern), because on a
   docker host the script must run at host scope with docker + apache access, not inside
   the container. Ship it rather than assume a host copy exists.
3. **Run teardown** (`on_host`) — `bash remove_account.sh <site> -y`. Site name derives
   from `mgn_container_name` (docker) or the web-root basename (bare-metal); never from
   free-typed input.
4. **Verify gone** (`on_host`) — re-probe container/volumes/vhost; emit
   `DECOMMISSION_VERIFIED` only when all are absent.
5. **Teardown** — remove the shipped script dir (`teardown`, `continue_on_error`).

**Steps (bare-metal / dedicated VPS node):** identical, minus `on_host` (the commands run
directly on the node), and `remove_account.sh` self-selects its bare-metal branch.

**Relay nodes:** a relay with live tenants must **not** be decommissioned this way — its
teardown is tenant-aware (`build_rebuild_relay` / `relay_remove_tenant`). The builder
refuses (`throw`) when `mgn_is_relay` is set and the relay still has tenants, directing
the operator to remove tenants first. A tenant-less relay decommissions normally.

**Result processing** (`JobResultProcessor::process_decommission_node`): on a job whose
output contains `DECOMMISSION_VERIFIED` **or** `DECOMMISSION_NOTHING_TO_DO`, the node
record is finalized (see § Control-plane cleanup). On failure the node is left intact and
enabled so the operator can retry — never a half-deleted record pointing at a live site.

---

## Integration-point inventory (decide once, cover all)

### Host footprint — handled by `remove_account.sh`
Container · all `${site}_*` volumes (**including postgres = the database, and uploads**) ·
image · build dir · vhost (a2dissite + reload) · site root + `_test` root · DB (bare-metal).
No additional host artifacts identified. The host itself (shared docker host) is kept; only
the one site is removed. A dedicated VPS box is **not** destroyed by this feature — Server
Manager does not own the cloud instance lifecycle; permanent-delete removes the *site and
its record*, and the empty box is the operator's to reclaim at the provider.

### Control-plane records tied to a node
- **`mgn_managed_nodes`** — the node. **Hard-delete vs soft-delete: see Open Decisions.**
- **`mjb_management_jobs`** — the node's job history. **Keep** (soft-delete cascade at
  most). Job history is the audit trail of what was done to the site; the decommission
  job itself must survive as the record that it happened.
- **`bke_backup_key_escrow`** — the node's escrow rows. **KEEP — do not delete.** These are
  the only way to decrypt the node's offsite backups. Deleting the node must never orphan
  its backups into unrecoverability. The escrow rows carry the fingerprint, not a node FK
  dependency that forces deletion.
- **`mgn_port`** — the container port. On a shared host the port allocator deliberately
  counts deleted rows so a freed port is not instantly reused (`next_container_port`). A
  hard-delete would drop that row and re-open the port for reuse; a soft-delete preserves
  the reservation. This is one concrete reason soft-delete is the safer default.
- **`mgn_bkt_backup_target_id`** — the shared backup **target** is never deleted; only the
  node's association to it goes away with the node.
- **Uptime / monitoring state** on the node row (last status, down-since, consecutive
  failures) — gone with the node; the node stops appearing in monitor problem lists.
- **`mjb_external_order_item_id` / hosting order linkage** — if the node was provisioned
  from a store hosting order, decommission does **not** reach back into the store order
  (no refund/cancel side effects). Out of scope; noted so it is a decision, not an oversight.

### Offsite backups (cloud target / B2)
Permanent-delete **does not** purge the node's offsite backups. Old archives stay
recoverable (escrow rows are kept), and coupling backup destruction to node deletion is
dangerous. But a decommissioned site's backups must not become **unreachable** — which is
exactly what soft-delete would cause today, because every backup list/delete path runs
*through the node* (SSH or the node's API), and a decommissioned node has no live host to
proxy through. The bucket objects keep sitting there with no door to them.

The fix is a **target-side backup browser** run from the control plane, described in the
next section. Deleting a decommissioned site's backups happens there, deliberately, not as
a side effect of deleting the node.

---

## Offsite backup management (target-side, node-independent)

Backups on a cloud target must be listable and deletable **from the control plane, against
the bucket directly**, with no dependency on any node being alive. This is what makes a
soft-deleted node's backups reachable, and it is the correct home for offsite backup
management regardless of decommissioning.

**Why it already fits.** Offsite objects use a stable per-node key layout —
`{bkt_path_prefix}/{slug}/{filename}` (`JobCommandBuilder`, `$remote_key`). And
`S3Signer` (in `includes/`) already performs signed GET / DELETE / PUT and streamed GETs;
it is a plain S3 client that runs anywhere the target credentials are available. The
control plane *has* those credentials — `BackupTarget::get_credentials()` unseals them
(SecretBox, already hardened to fail loud on an undecryptable value). Today the delete
path proxies through the node only for historical reasons, not necessity.

**What to add.**
- A thin `S3Signer::list($creds, $bucket, $prefix)` helper — a signed `GET /?list-type=2&
  prefix=…` (ListObjectsV2) that parses the XML and follows the continuation token, so
  large buckets page fully. This is the one missing S3Signer verb.
- On the **Backup Targets** detail page (`targets.php`), a **Backups** panel that lists the
  target's objects grouped by the `{slug}` path segment — one group per node prefix. Each
  group is labeled with the owning node; a group whose node is soft-deleted is labeled
  **decommissioned**, and a group whose slug matches no node at all is labeled
  **orphaned**. This is the door to a dead site's backups.
- Delete actions, run from the control plane via `S3Signer::delete` over the listed keys:
  delete a single object, or delete an **entire node prefix** (all of one decommissioned
  site's backups). Permission 10, `SmAdminCsrf`, and type-to-confirm the slug for a
  whole-prefix wipe. Deleting a live node's whole prefix is allowed too, but the confirm
  copy notes the node still exists.

This panel is authoritative for **offsite** backups. The per-node Backups tab remains for a
live node's **local** `/backups` (which only the node can see) and continues to work for
cloud objects on a live node; the target panel is the path that survives decommissioning.

The decommission job itself still does **not** delete offsite backups — retention +
escrow is the safe default. Purging a retired site's offsite backups is a deliberate act
performed in this panel once the operator is sure.

---

## `remove_account.sh` changes (make it job-safe)

The script is correct for a human at a prompt; two additions make it safe as a job step:

1. **Machine-readable terminal marker.** Emit `REMOVE_ACCOUNT_OK <site>` on success and a
   distinct `REMOVE_ACCOUNT_NOTHING <site>` when no site is found, instead of `exit 1`
   for "nothing to remove." The job's verify step keys off these; "already gone" is a
   success for an idempotent teardown, not an error.
2. **Idempotency.** A second run (site already removed) exits 0 with
   `REMOVE_ACCOUNT_NOTHING`. Everything the script does is already tolerant of missing
   pieces; only the top-level "no site found → exit 1" needs to become a clean no-op.

Bump `remove_account.sh` to v2.1. No behavior change for the interactive path except the
extra final marker line.

---

## UI / UX

On the node detail overview tab, a **Danger Zone** section with two actions, matching the
plugin's guided, self-documenting style (no explainer prose — the controls carry the
meaning):

- **Remove from dashboard** — one-button action (existing soft-delete). Sub-label: stops
  tracking; the site keeps running.
- **Permanently delete this site** — opens a confirm modal (JoineryModal) that:
  - shows the **plan**: the exact container, volume list, database, vhost, and domain that
    will be destroyed (fetched via the plan pre-flight, or rendered from known node fields);
  - requires the operator to **type the site name** to enable the button (GitHub-repo-delete
    pattern);
  - states plainly that the database and all uploads are destroyed and that offsite backups
    are retained.

On submit it creates the `decommission_node` job and drops the operator on the job detail
page to watch it run (same flow as every other job). CSRF-guarded; permission 10.

Because both delete actions soft-delete the record, removed nodes leave the dashboard. A
**Show all sites (including removed)** toggle (`?show_all=1`) at the bottom of the Hosts &
Sites panel re-renders with soft-deleted nodes included, each carrying a **Removed** badge
and a link into its still-reachable node detail page. This is the lightweight way to find a
decommissioned node again; the Stored Backups panel is where its offsite backups are managed.

**Does the site still exist? (existence gate).** For a removed node the host-teardown action
is only worth offering if there is actually a site to tear down. The page cannot answer that
by probing the host — it renders as the web user, which holds no host SSH key — so the offer
is gated on evidence the control plane already has: a recorded status check, a read Joinery
version, or an uptime result all mean a live site was once seen there. With none of that (a
failed install that never stood a site up, e.g.) the teardown is hidden behind a short note
and only **Permanently Delete Entry** is offered. This is intentionally evidence-based, not a
live probe; the `decommission_node` job remains the authoritative check when the operator does
proceed (idempotent, `REMOVE_ACCOUNT_NOTHING` on an empty host). A future option, if a live
pre-check is ever wanted for the ambiguous middle case (a completed install later torn down by
hand), is an agent-run probe job whose result gates the offer — deferred as not worth the
extra job round-trip today.

---

## Testing (pre-tested is the whole point)

1. **Shell gate — `plugins/server_manager/tests/remove_account_gate.sh`** (`*_gate.sh`,
   live/host tier): build a throwaway docker site (or a minimal fixture with a dummy
   container + named volumes + a vhost file), run `remove_account.sh <fixture> -y`, assert
   the container, every `${fixture}_*` volume, the image, and the vhost are gone and
   `REMOVE_ACCOUNT_OK` was printed; run it **again** and assert `REMOVE_ACCOUNT_NOTHING`
   and exit 0 (idempotency). This is the safety proof the feature rests on.
2. **`job_command_builder_test.php`** — `build_decommission_node` emits the ship + `on_host`
   remove step with the site name derived from node fields (never from input), includes the
   verify step, and refuses a tenant-bearing relay. Assert no credential/secret material in
   the built commands.
3. **`job_result_processor_test.php`** — a job whose output contains `DECOMMISSION_VERIFIED`
   finalizes the node; a failed job leaves the node intact and enabled; escrow rows for the
   node are **present before and after** decommission (the keep-escrow invariant).

4. **`S3Signer` list/delete** — a test that `S3Signer::list` parses a multi-page
   ListObjectsV2 XML response (including a continuation token) into a flat key list, and
   that the target panel groups keys by the `{slug}` segment and classifies each group as
   live / decommissioned / orphaned against the node table. Live-tier coverage that a
   prefix delete against a scratch prefix on the real dev target removes exactly the listed
   keys and nothing else.

All run in the existing tiers; the gate joins the host/live set, the PHP suites join the
plugin's db-tier set, and the bucket delete check is live-tier against the dev target.

---

## Docs

Update `plugins/server_manager/docs/overview.md` (current-state voice, per the docs rule):
a "Retiring a node" section describing the two-tier delete — **Remove from dashboard**
(record only, site keeps running) vs **Permanently delete** (record + host teardown via the
`decommission_node` job) — and the retained-escrow / retained-offsite-backups guarantees.
No migration narrative.

---

## Resolved Decisions

- **Soft-delete the node record after a verified teardown** (not hard-delete). It preserves
  the port reservation on shared hosts, keeps the node's job history joinable, and keeps the
  escrow-row fingerprint association legible — while the site itself is genuinely destroyed
  on the host. A permanently-deleted node is filtered out of the dashboard and monitor lists
  (they already filter `deleted => false`). Hard-delete buys nothing and risks port-reuse
  collisions and orphaned audit rows.

- **Do not purge offsite backups on delete; add a target-side backup browser instead.**
  Decommission retains backups + escrow. A retired site's offsite backups stay reachable and
  deletable from the Backup Targets page (control-plane S3, node-independent), so soft-delete
  never strands them. Purging them is a deliberate act performed there.

## Open Decisions

1. **Should decommission touch the originating store hosting order?**
   *Recommendation: no.* Keep commerce side effects out of an infrastructure teardown. If a
   refund/cancel is ever wanted it belongs to a store-side flow, not here.

4. **Dedicated-VPS box reclamation.** For a bare-metal/VPS node, permanent-delete removes
   the *site* and the *record* but leaves the empty box running at the provider.
   *Recommendation: accept this for v1* and say so in the confirm modal ("the server itself
   is not destroyed; reclaim it at your provider"). Cloud-instance destruction is a provider
   API concern, not this feature's.
