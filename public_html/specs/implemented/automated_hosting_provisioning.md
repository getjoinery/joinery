# Automated Hosting Provisioning

## Overview

Customers buy a hosting plan on **getjoinery.com** (the sales site). After checkout, the **Server Manager** plugin (running on a separate Joinery control plane) automatically provisions a new Joinery site on a managed Docker host. Server Manager owns all provisioning state. getjoinery requires **zero schema changes** — a domain `QuestionRequirement` attached to hosting products is the sole signal, and the existing CRUD API bridges the two systems.

## Architecture

Three independent Joinery installs:

- **getjoinery.com** — sales site. Catalog, checkout, customer accounts, REST API. No hosting plugin, no provisioning state machine.
- **Control plane** — runs Server Manager. Polls getjoinery, picks a host, drives installs, sends customer emails by POSTing to getjoinery's `QueuedEmail` API.
- **Managed hosts** — Docker servers. Each host runs many sites.

Communication is one-way HTTP via the existing `/api/v1/` CRUD layer. **No new endpoints on getjoinery.**

## Workflow

```
1. Customer buys hosting plan on getjoinery
   └─ Domain captured via QuestionRequirement answer in oir_order_item_requirements

2. Server Manager poll task (every 15 min)
   └─ GET /api/v1/OrderItemRequirements?oir_qst_question_id={domain_question_id}
   └─ Filter locally to paid order items not yet in mjb_management_jobs
   └─ For each new order: pick host, create install_node job

3. install_node job runs on chosen host
   └─ install.sh provisions site, manage_domain.sh configures Apache (no SSL)
   └─ New mgn_managed_nodes row created with mgn_mgh_host_id FK and mgn_ssl_state=pending

4. Job-completion hook
   └─ POST /api/v1/QueuedEmail with status=READY_TO_SEND
       (welcome email composed locally on control plane, sent through getjoinery's mail path)

5. Customer points DNS A record to host IP

6. SSL polling task on Server Manager (every 5–15 min)
   └─ For each managed_node where mgn_ssl_state=pending and install complete:
       └─ Resolve domain; if it points to host IP, run provision_ssl job
       └─ Job runs certbot, sets mgn_ssl_state=active
```

## getjoinery Changes (none)

**No schema changes required on getjoinery.**

Admin creates a Question ("What domain would you like to use for your site?", type: text, required) and attaches it to each hosting product as a `QuestionRequirement` via the existing product requirements UI. The answer lands in `oir_order_item_requirements.oir_answer`. All rendering, validation, and storage is handled by the existing requirements framework.

The presence of an answer to this question on a paid order item is the sole signal that provisioning is needed. No product flags, no new columns, no hooks.

### One small addition: `oir_qst_question_id` filter in `MultiOrderItemRequirement`

`MultiOrderItemRequirement::getMultiResults()` needs to accept `oir_qst_question_id` as a filter key (direct column match — one line). This allows SM to poll all answers to the domain question in a single API call.

That's the full extent of getjoinery changes.

## API Contract (existing CRUD only)

Auth: existing `public_key` / `secret_key` headers. Dedicated service user on getjoinery (e.g., `provisioning@getjoinery.com`) holds the permission-3 key, used only by the control plane.

**Server Manager polls for hosting orders (domain + order item ID in one call):**
```
GET /api/v1/OrderItemRequirements?oir_qst_question_id={domain_question_id}&numperpage=200
```
Each row gives `oir_odi_order_item_id` and `oir_answer` (the domain). For each new row (not already in `mjb_external_order_item_id`), SM makes two sequential lookups:

1. `GET /api/v1/OrderItem/{id}` — confirms `status=paid` (skip if not); gets `odi_usr_user_id`
2. `GET /api/v1/User/{usr_id}` — gets `usr_email` and name for the welcome email

Two extra calls per new order. Acceptable at provisioning volumes.

`domain_question_id` is stored in SM plugin settings (`provisioning_domain_question_id`).

**Server Manager queues the welcome email after provisioning:**
```
POST /api/v1/QueuedEmail
equ_from=support@getjoinery.com
equ_from_name=Get+Joinery+Support
equ_to=customer@example.com
equ_to_name=Jane+Doe
equ_subject=Your+site+is+ready
equ_body=<html>...</html>
equ_status=2   ← READY_TO_SEND
```
getjoinery's existing email worker sends it through getjoinery's SMTP — proper SPF/DKIM, no infra changes.

**No callback endpoint to "report provisioning status" is needed.** SM tracks state locally; getjoinery doesn't need to know.

**No claim/lock endpoint.** Pending list is stateless. SM dedups locally via `mjb_management_jobs.mjb_external_order_item_id`.

## Server Manager Schema Additions

### New table: `mgh_managed_hosts`

A host is a server that can run many sites. Today, host info (`mgn_host`, `mgn_ssh_*`) is duplicated on every site row. This refactor extracts host-level connection config and capacity.

| Column | Type | Notes |
|--------|------|-------|
| `mgh_id` | int8 serial | PK |
| `mgh_slug` | varchar(50) unique | e.g., `docker-prod` |
| `mgh_name` | varchar(100) | Display name |
| `mgh_host` | varchar(255) | IP or hostname. **Must be a public IP** when `mgh_provisioning_enabled=true` — used verbatim in DNS instructions to customers. |
| `mgh_ssh_user` | varchar(50) | Default `root` |
| `mgh_ssh_key_path` | varchar(500) | |
| `mgh_ssh_port` | int4 | Default 22 |
| `mgh_max_sites` | int4 | Hard cap (MVP) |
| `mgh_provisioning_enabled` | bool | Off-switch per host |
| `mgh_notes` | text | |
| timestamps | | |

### Changes to `mgn_managed_nodes`

- Add `mgn_mgh_host_id` int8 FK → `mgh_managed_hosts` (nullable for legacy nodes)
- Add `mgn_ssl_state` varchar(20) — `pending` / `active` / `failed`

Existing `mgn_host` / `mgn_ssh_*` columns stay as legacy overrides. Auto-provisioned sites inherit from the host.

### Backfill Migration

Runs automatically on plugin update (via the migrations system). Groups existing `mgn_managed_nodes` by `(mgn_host, mgn_ssh_user, mgn_ssh_key_path, mgn_ssh_port)`, creates one `mgh_managed_hosts` row per unique tuple, and FKs all member nodes to it.

Backfilled hosts default to `mgh_provisioning_enabled=false` — admin explicitly opts each host in for auto-provisioning. This prevents surprise capacity assignments on legacy hosts that were never intended for automated use.

### Changes to `mjb_management_jobs`

- Add `mjb_external_order_item_id` int8 — the `odi_order_item_id` this job is fulfilling. Indexed. Used for dedup against the polling list.

(No `mgn_external_*` column — the link is on the job; the resulting node is reachable via existing `mjb_mgn_node_id`.)

## Host Selection Algorithm (MVP)

```sql
SELECT mgh.*, COUNT(mgn.mgn_id) AS current_sites
FROM mgh_managed_hosts mgh
LEFT JOIN mgn_managed_nodes mgn 
  ON mgn.mgn_mgh_host_id = mgh.mgh_id 
  AND mgn.mgn_delete_time IS NULL
WHERE mgh.mgh_provisioning_enabled = true
  AND mgh.mgh_delete_time IS NULL
GROUP BY mgh.mgh_id
HAVING COUNT(mgn.mgn_id) < mgh.mgh_max_sites
ORDER BY current_sites ASC, mgh.mgh_id ASC
LIMIT 1
```

Least-full host wins. If no host has capacity, the order is logged with `"no host capacity"`, admin gets paged, no `install_node` job is created. Admin adds a host or raises `mgh_max_sites`; on next poll cycle the order picks up automatically.

Before creating any job, SM also checks for an existing `mgn_managed_nodes` row with the same slug (sanitized domain). If one exists and is not in `install_failed` state, the order is parked with error `"domain already provisioned for another order"` and admin is alerted. This handles the edge case of two customers purchasing hosting for the same domain — a manual resolution (refund) is required.

Future enhancement (not MVP): weighted capacity (Pro plan = 2 slots), disk-usage and load-aware scoring.

## Provisioning Job

Reuses the existing `install_node` job type. New parameters:
- `mgh_id` — chosen host
- `domain` — pulled from `oir_order_item_requirements.oir_answer` for this order item
- `slug` — domain sanitized to lowercase alphanumeric + hyphens (e.g., `acmewidgets-com`). Unique per customer domain.
- `admin_email` — buyer's email from `GET /api/v1/User/{odi_usr_user_id}`
- `mode` — always `fresh`
- `mjb_external_order_item_id` — the order_item this job fulfills

After successful install:
1. New `mgn_managed_nodes` row with `mgn_mgh_host_id` set and `mgn_ssl_state=pending`
2. Job-completion hook composes the welcome email and POSTs `/api/v1/QueuedEmail` on getjoinery
3. SSL polling task picks up the new node next cycle

On failure, `mgn_install_state=install_failed`; admin sees the failed job and node on the dashboard, clicks Retry. Retry updates the existing `mgn_managed_nodes` row with the new `mgn_mgh_host_id` and resets `mgn_install_state` — no new node record, no slug collision. The original dirty host needs manual cleanup (`rm -rf /var/www/html/{slug}`) but that's independent of the retry. After 3 retries the order parks until manually addressed.

## SSL Provisioning

New scheduled task: `plugins/server_manager/tasks/ProvisionPendingSsl.php`.

**Cadence:** every cron run (matches scheduled task system's 15-min cron, or 5-min if available).

**Logic:**
1. Load all `mgn_managed_nodes` where `mgn_ssl_state='pending'` and install is complete
2. For each: resolve `mgn_site_url` via `gethostbyname()`
3. If it resolves to the node's host IP, create a `provision_ssl` job
4. If not, leave `mgn_ssl_state=pending` for next cycle
5. Backoff: query `mjb_management_jobs` for the most recent `provision_ssl` job for this node; skip if it failed within the last hour. After ~16 hours of failures, flip `mgn_ssl_state=failed` and alert admin.

(Backoff state lives in job history, not separate columns.)

New job type: `provision_ssl`. Steps:
1. SSH to host, run `certbot --apache -d DOMAIN --non-interactive --agree-tos -m ADMIN_EMAIL`
2. Verify cert installed
3. Update `mgn_ssl_state=active`

## Failure & Retry

| Failure | Auto-retry | Customer sees | Admin action |
|---------|-----------|---------------|--------------|
| No host capacity | Next cycle (once admin adds capacity) | Order sits as "Provisioning, taking longer than expected" after 24h | Add host or raise `mgh_max_sites` |
| `install_node` fails | No (target dirty) | Same | Click Retry (picks fresh host) |
| `provision_ssl` fails | Yes, ~hourly via polling task | HTTP works; "HTTPS pending" banner | After ~16h auto-fail, manual retry or DNS fix |
| API call SM → getjoinery fails | Yes, local SM retry queue | Welcome email delayed | None unless persistent |

Retry attempts are tracked via job history (`mjb_management_jobs` rows for the same `mjb_external_order_item_id`). After 3 failed attempts, the order parks until admin clears it manually.

**Customer comms:**
- 24h pending → admin paged; customer dashboard banner ("taking longer than expected")
- Permanent failure → admin reaches out manually; no auto-failure email

## Customer Experience

- **Order confirmation email** (existing getjoinery flow) — sent immediately on checkout.
- **Welcome email** (new, queued by control plane via `QueuedEmail` API) — sent after install completes. Contains: domain, A-record IP, login URL, support contact.
- **No "My Sites" dashboard on getjoinery for MVP.** Customers manage their site from the site itself; they return to getjoinery for billing only. A dashboard view can be added later as a thin read-through to the control plane API if needed.
- **DNS-pending window:** customer waits until DNS resolves to log in. No preview URL.

## Server Manager Dashboard Accordion

Top-level grouping by host:

```
┌─ Agent Status ─────────────────────────────┐
│  Online · v1.2.3 · last heartbeat 12s ago  │
└────────────────────────────────────────────┘

┌─ Pending Provisions (3) ───────────────────┐
│  acmewidgets.com    polled        2 min ago │
│  bobsbakery.net     installing    5 min ago │
│  carlskiosk.io      awaiting DNS  1 hr ago  │
└────────────────────────────────────────────┘

▼ docker-prod (23.239.11.53)   12 / 50 sites   ✓ provisioning
   ├─ empoweredhealthtn      [healthy]
   ├─ scrolldaddy            [healthy]
   ├─ acmewidgets            [installing]
   └─ ...

▶ docker-prod-2 (45.6.7.8)     0 / 50 sites    ✓ provisioning

▶ legacy-host (98.7.6.5)       3 / 3 sites     ✗ provisioning disabled
```

- Host header: name + IP + capacity gauge + provisioning_enabled badge
- Body: existing per-node site cards filtered to that host
- Top "Pending Provisions" panel: composed locally from SM's job table + recent API poll results
- New **Add Host** button creates an `mgh_managed_hosts` record (separate from Add Node, which still works for adopting existing sites)

## Plugin Settings (control plane)

Adds to `plugins/server_manager/plugin.json`:
- `getjoinery_api_url` — e.g., `https://getjoinery.com`
- `getjoinery_api_public_key`
- `getjoinery_api_secret_key`
- `provisioning_admin_alert_email` — capacity-exhausted and persistent-failure alerts
- `provisioning_welcome_from_email` — From address for welcome emails (must be authorized for getjoinery's mail domain)
- `provisioning_welcome_from_name`
- `provisioning_domain_question_id` — the `qst_id` of the domain Question on getjoinery

## Implementation Phases

1. **Server Manager refactor** — `mgh_managed_hosts` + `mgn_mgh_host_id` + `mgn_ssl_state` + `mjb_external_order_item_id`. Manual Add Host UI. Dashboard accordion. Zero customer-visible change. Independently shippable.
2. **End-to-end pipeline** — getjoinery: domain Question attached to hosting products (admin UI, no code). SM: `PollHostingOrders` scheduled task, `install_node` parameter additions, job-completion hook that POSTs `QueuedEmail`. Sites provisioned automatically end-to-end on HTTP.
3. **SSL automation** — `ProvisionPendingSsl` scheduled task + `provision_ssl` job type. HTTPS comes online once DNS resolves.

Each phase is independently shippable.

## Out of Scope (explicit non-goals)

- **Subdomain offering** — BYO-domain only at this time
- **Preview URL** during DNS-pending window — customer waits for DNS
- **BYO-domain DNS pre-validation at checkout** — accept any well-formed domain; ownership validated at SSL time via Let's Encrypt HTTP-01
- **Subscription cancellation / decommission** — manual for now; future phase will add lifecycle automation
- **Customer "My Sites" dashboard on getjoinery** — deferred; can be added later as thin read-through to control plane
- **Multi-region host selection** — single capacity pool; future enhancement
- **Site migration between hosts** — manual via existing `copy_database` + restore primitives
