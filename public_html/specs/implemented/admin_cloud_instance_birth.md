# Admin Cloud Instance Birth

## Goal

An admin can create a brand-new VPS in a connected cloud account and have the platform install a site onto it — fresh or from-backup, Docker or bare-metal — through the same pipeline that fulfills customer hosting purchases. No manual instance creation, no manual SSH staging.

**First use (the acceptance test):** move jeremytunnell.com from the shared host onto its own bare-metal VPS, driven entirely from dev.getjoinery.com — instance born via the owner's Linode OAuth grant, installed from the existing node's backup.

## What already exists (do not rebuild)

- `LinodeOAuthProvider` + platform OAuth2 client; dev already has its own Linode OAuth client configured (`oauth_linode_client_id`/`secret` set).
- `/profile/server_manager/connect_cloud` works for any signed-in user — it does not require a pending purchase. The owner connects here.
- `LinodeComputeDriver` (create/get instance), `CustomerCloudAccount` grant storage.
- `ProvisionCustomerCloud` state machine: ready → booting → installing → done/failed, with compute-failure policy, boot timeout, reconnect parking, failure alerts.
- `JobCommandBuilder::build_install_node` already supports `mode: fresh|from_backup` and `docker_mode: docker|bare-metal` (bare-metal pre-stages `user1` with sudo and switches the node's SSH user; from_backup captures the source node's backup, fetches it to the control plane, pushes to the target).
- Admin **Install New Node** form already offers install type and deployment mode — but only for a target server that already exists (you type in its IP and SSH details).
- Dev has a live job agent.

## The gap

Instance *birth* is welded to the purchase pipeline. A provision row requires an order item (`cvp_external_order_item_id` is required + unique), and `ProvisionCustomerCloud::handle_booting` hardcodes `mode: fresh`, `docker_mode: docker`, `port: 8080`. There is no way for an admin to say "create a new instance in that connected account and install X onto it."

## Design decisions (the three cross-plane tensions, resolved)

1. **No cross-plane seam.** Each control plane owns its own grants. Dev's Linode OAuth client already exists; the owner connects their Linode account **on dev**. getjoinery.com's customer grant machinery is untouched. Nothing is handed between planes.
2. **Generalize the existing state machine; don't fork it.** Provision rows grow install parameters and an origin. The admin form creates an admin-origin provision; `ProvisionCustomerCloud` drives it through the identical ready → booting → installing path, reading the parameters instead of hardcoding them. The robustness test is precisely that the customer pipeline, generalized, births the owner's box.
3. **Key custody is trivial because birth plane = install plane.** Dev creates the instance, so dev injects its own provisioning key (`server_manager_customer_cloud_ssh_key_path` on dev — a one-off setting, see Preconditions). getjoinery's key never leaves getjoinery.

## Build

### 1. `CustomerCloudProvision` schema + validation

- `cvp_external_order_item_id`: drop `required` and `is_nullable=false`; keep `unique` (Postgres allows multiple NULLs in a unique column).
- New fields:
  - `cvp_origin` varchar(10), default `'order'` — `'order'` | `'admin'`.
  - `cvp_docker_mode` varchar(12), default `'docker'` — `'docker'` | `'bare-metal'`.
  - `cvp_install_mode` varchar(12), default `'fresh'` — `'fresh'` | `'from_backup'`.
  - `cvp_source_node_id` int8, nullable — source managed node for from_backup.
  - `cvp_backup_source` varchar(10), nullable — `'new'` | `'existing'` (mirrors the install form).
  - `cvp_port` int4, default 8080 — container port; ignored for bare-metal.
- New field `cvp_sitename` varchar(50), nullable — install directory/DB name (`[a-z0-9_]`); falls back to the slug when absent (the order flow's behavior).
- Validation runs in `save()` (via a shared `validate_row()` also called from `prepare()` — `prepare()` is not guaranteed to run first): order item required only when origin is `'order'`; from_backup requires a source node id; docker/install modes restricted to their known values.

### 2. Admin form: birth option on Install New Node

The form's existing **Target Host** dropdown is the "where" selector — the cloud option joins it (alongside known hosts and *Other server*):

- **Known host / Other server** — the current fields (host IP, SSH user/key/port). Unchanged behavior: dispatches the install job directly.
- **Create a new cloud instance** — hides the SSH fields and shows:
  - Connected cloud account (dropdown of active `CustomerCloudAccount` rows, labeled "provider — user"; empty state links to the connect page).
  - Region and instance type (text inputs prefilled from the customer-cloud settings; the jeremytunnell move uses `g6-standard-1`).

On submit with the cloud target, the form does **not** build a job. It creates an admin-origin provision row at status `ready` carrying domain, slug, sitename, docker_mode, install_mode, source node, backup source, the chosen account id, region, and type. `cvp_usr_user_id` (and buyer email/name) = the **grant owner**, not the submitting admin — the reconnect flow resumes provisions by matching the user who can actually re-consent. The form redirects back to itself with a success notice; in-flight provisions show in a banner on the Server Manager dashboard.

From-backup with the cloud target always captures a fresh source backup (`backup_source: new`) — a brand-new instance has no cached backup list to point at.

Slug rule stays: the new node's slug must not collide with a live node's (the form auto-appends a counter). A from-backup clone of an existing node therefore gets a **new** slug; the domain may match the source — that's the cutover model.

### 3. `ProvisionCustomerCloud` generalization

- `handle_ready`: use `cvp_region`/`cvp_instance_type` as it already does (admin rows have them set at creation).
- `handle_booting`: build `$job_params` from the provision — `mode`, `docker_mode`, `sitename`, plus `port` (docker only) and `source_node_id`/`backup_source` when from_backup — instead of the hardcoded fresh/docker/8080. The `domain` job param follows `build_install_node`'s contract: the target domain for fresh installs, the **source** node's domain for from_backup (target domain comes from the node's site URL via post-restore fixups). Node rows also get `mgn_web_root` set from the sitename, and `mgn_port` only for docker.
- Welcome email logic is already order-linkage-gated and needs no change: admin provisions have no order item, so no customer welcome email is sent. Completion is visible on the node list; failures already alert the ops address.
- `park_for_reconnect` already emails `cvp_buyer_email`; for admin origin, fall back to the provisioning alert recipient chain so a parked admin provision isn't silent.

### 4. Token-lifetime reality (no code, just doctrine)

Linode issues **no refresh token**; a grant is a two-hour credential. The admin flow's contract: connect (or re-connect) immediately before submitting the form. If the grant is stale, the provision parks at `pending_connect`, the owner gets the reconnect email, and a fresh grant resumes it — same as the customer flow.

## Preconditions for the jeremytunnell.com move (one-offs, not build items)

1. ✅ Dev provisioning keypair generated via the Provisioning Setup page's **Generate provisioning key** button (`{site root}/config/provisioning_key`, setting saved by the same action).
2. Owner re-connects their Linode account at dev's `/profile/server_manager/connect_cloud` shortly before submitting (the account link exists; Linode tokens live two hours).
3. Instance size decision: **g6-standard-1 (2 GB, $12/mo)** — bare-metal full stack plus the mail stack (Postfix, DKIM signing) needs more headroom than a 1 GB nanode; 4 GB is not justified for this site's traffic.
4. Source node 32 (jeremytunnell) must have a working backup path (from_backup with `backup_source: new` captures one fresh).

## Acceptance (the live gate)

1. Submit the form on dev: cloud target, owner's Linode account, `g6-standard-1`, bare-metal, from_backup of node jeremytunnell, slug `jeremytunnell-vps`, domain `jeremytunnell.com`.
2. Provisioning task creates the instance in the owner's Linode account, waits for boot, creates the node with dev's key, dispatches the install job; dev's agent executes the bare-metal from-backup install.
3. Site verified serving on the new IP (curl with Host header / hosts-file check) **before** DNS cutover.
4. Owner points jeremytunnell.com DNS at the new IP; `ProvisionPendingSsl` issues the cert unattended (it already waits for DNS to match).
5. Old copy on the shared host is decommissioned only after the new box is verified (manual decision, not automated).

## Tests

- `plugins/server_manager/tests/`: provision-class checks for origin-conditional validation (admin origin needs no order item; from_backup needs a source node); `handle_booting` job-params assembly honors per-provision docker_mode/mode/port/source (unit-level, no live compute).
- Existing customer-cloud tests must stay green — order-origin defaults reproduce today's exact behavior (fresh/docker/8080).

## Docs

Update `plugins/server_manager/docs/overview.md`: the Install New Node target choice, admin-origin provisions, and the grant-lifetime contract. Current-state wording only.
