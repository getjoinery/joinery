# Managed Backups — Flat-Fee Storage Subscription

**Status:** Unbuilt, spec (2026-08-11).

**What it is:** a customer pays a flat monthly fee for N GB of backup storage
on a getjoinery-operated bucket, and their site is handed a storage target
that just works — no cloud account, no key ceremony in someone else's
console, no chance to configure it wrong. The site backs itself up exactly
as it does today: same engine, same schedule, same encrypted chains, same
recovery-key custody. The only thing the subscription changes is where the
bucket credentials come from.

Two properties fall out of the existing engine and are the product's honest
pitch:

- **getjoinery cannot read the backups.** Encryption is unconditional and
  happens on the customer's machine before upload (`BackupRunner` hard-codes
  it). The managed bucket holds ciphertext plus key envelopes sealed to the
  *customer's* recovery key. We store it; we cannot open it.
- **A compromised customer server cannot destroy its own backups.** The
  credential the site receives is a write-only B2 application key scoped to
  that tenant's prefix (`namePrefix` + `writeFiles`, no `deleteFiles`) — the
  same write-only model already proven on the fleet node path. Ransomware on
  the box can stop future backups; it cannot delete past ones.

**Companion design:** "managed recovery" — closing the remaining loss mode
(the customer losing their own recovery key) — is
`specs/managed_backup_recovery.md`, deliberately separate. This spec's
product does not change key custody at all, and its enrollment flow says so
in one plain sentence.

## What already exists to build on

- **Billing and entitlement:** a store subscription product grants a tier;
  tier features are checked with `SubscriptionTier::getUserFeature()`. The
  mailbox relay fleet gates exactly this way (`mailbox_fleet_slot`,
  `mailbox_fleet_max_domains`), including the lapse lifecycle in
  `plugins/mailbox/tasks/MailboxRelayReconcile.php` (entitlement gone →
  grace → suspend → reactivate on re-subscribe).
- **The enrollment channel:** the mailbox fleet is the same product shape —
  a getjoinery-hosted paid service consumed by independent self-hosted
  sites. Tenant holds an API key (`FleetClient`), calls enroll/status
  endpoints on getjoinery (`FleetService`), and customer-cloud provisions
  are seeded for one-click enrollment (`FleetProvisionSeeding`).
- **The target model:** `data/backup_target_class.php` already stores
  SecretBox-sealed credentials, and `backup_target_id` is how the scheduled
  task finds its destination. Enrollment writes an ordinary target row.
- **Management-node retention:** `FleetBackupRetention` already prunes a
  bucket shelf from the delete-capable side, chains deleted whole.

## Design decisions

- **The write-only key means the site cannot prune its own shelf**, so
  getjoinery prunes for every managed tenant, the way the fleet already
  prunes for managed nodes. Retention is a fixed property of the plan
  (e.g. newest 4 chains), not a tenant setting — fewer knobs, and it is
  what keeps most sites naturally inside their quota.
- **Quota enforcement is soft and never silent.** A B2 key cannot cap
  bytes. Overage warns on the tenant's Backups page (via the status call),
  then after sustained overage the write key is revoked with loud warnings
  on the tenant side — the site keeps taking local backups and says exactly
  why uploads stopped.
- **Tenant isolation is the key scope, not trust.** Enrollment assigns each
  tenant a unique slug; the minted key's `namePrefix` confines it to
  `{tenant-slug}/`, which becomes the target row's `bkt_path_prefix`. One
  tenant's credential cannot touch — or list — another tenant's objects.
- **The recovery ceremony is unchanged and still mandatory.** The engine
  already refuses encrypted backups until the key is proven, which is
  exactly right here. The enrollment flow states plainly that getjoinery
  cannot open backups and cannot help if the recovery key is lost.
- **Lapse never deletes quickly.** Entitlement gone → 14-day grace (the
  fleet default) → write key revoked, uploads stop, tenant warned →
  data retained 90 days from revocation → deleted. The 90 days is a
  customer-facing promise and appears in the product copy.

## Build items

### 1 — Shared hosted-service enrollment skeleton (core)

Extract the tenant↔operator pattern from the mailbox fleet into core so
both services sit on it: a client class (API-key auth against a service
URL, the `FleetClient::call` shape), an operator-side service base
(entitlement check by tier feature, enroll/status/release verbs, slot row
with `provisioning/active/suspended/released` states), and the
grace-lapse reconcile shape. Mailbox fleet migrates onto the skeleton;
managed backups is its second consumer. Backups are core, so the tenant
side must not require any plugin.

### 2 — B2 application-key client (server_manager plugin)

The first programmatic key provisioning in the tree: `b2_create_key` /
`b2_delete_key` against the B2 native API, minting per-tenant write-only
keys scoped by `namePrefix`, and deleting them at revocation. Lives beside
the existing `b2_authorize_account` endpoint-detection code in the
server_manager targets surface. Once it exists, the fleet's own
one-shared-node-key gap can be closed with per-node scoped keys through
the same client (separate work, same client).

### 3 — Managed backups service (operator side, server_manager plugin)

- Tenant table binding customer user → tenant slug → key id → state, on
  the skeleton's slot model.
- **Enroll:** verify tier feature, assign unique slug, mint the scoped
  write-only key, return the target config (endpoint, bucket, prefix,
  credentials, plan quota, retention policy).
- **Status:** bytes used, plan quota, retention state, lapse warnings —
  everything the tenant's Backups page renders.
- **Release:** customer-initiated teardown; revoke key, start the deletion
  clock.
- **Reconcile task:** entitlement lapse → grace → key revocation → 90-day
  retention → deletion, with reactivation on re-subscribe at any point
  before deletion.
- **Metering and pruning task:** list each tenant prefix with the
  delete-capable master credential, record bytes per tenant, prune chains
  whole to the plan's retention, flag overage. The `FleetBackupRetention`
  listing/pruning model, driven per tenant.

### 4 — Site side (core)

- A `managed` provider value on `BackupTarget`: behaves as `b2` for the
  engine, but the Backups page renders it as a product — no editable
  credential fields, a usage meter fed by the status call, plan name,
  and the custody sentence.
- Enrollment flow on `/admin/admin_backups`: unenrolled sites see the
  subscribe path alongside the DIY target form; a site holding a
  getjoinery API key (seeded or pasted) enrolls in one click, which writes
  the target row, sets `backup_target_id`, and points the operator at the
  Backup task activation and the recovery ceremony if not yet done.
- Over-quota and lapse states surface loudly on the Backups page and in
  the run history — a stopped upload path is never quiet.

### 5 — Store product and tier features

Core tier features `managed_backup_slot` (boolean) and
`managed_backup_bytes` (integer) in `core_tier_features.json`. Plan sizes
are separate subscription products granting tiers with different byte
values. Pricing and sizes are an owner decision at build time.

### 6 — Operations

The bucket itself, master (delete-capable) credential custody on
getjoinery, and the B2 lifecycle rule that reaps superseded manifest
versions — the same rule already named as an open item on the fleet
backups work; this build closes it for both.

## Tests

- Enroll → target row written, key scoped to the tenant prefix (a second
  tenant's key cannot read or write the first tenant's objects).
- Write-only proof: the tenant credential cannot delete or list outside
  its prefix; delete-refusal asserted, not assumed.
- Metering: bytes aggregate per tenant from a listing; overage flags at
  the feature value.
- Pruning: chains deleted whole, never a full out from under its
  incrementals; standalone and chain families aged independently.
- Lapse ladder: grace → revocation → retained → deleted, and
  reactivation at each pre-deletion stage.
- Site side: `managed` target renders locked, status meter populated,
  revoked-key state surfaces as a failed-run cause, not silence.

## Documentation (written at build time — docs describe current state only)

- `docs/backups.md`: a managed-target section (what the provider is, what
  the site can and cannot do with it, custody statement, quota and lapse
  behavior).
- `docs/subscription_tiers.md` / store docs: the two tier features.
- `plugins/server_manager/docs/overview.md`: the operator-side service,
  key minting, metering and pruning tasks.

## Open items

- Plan sizes and price points (owner decision; the tier mechanism handles
  any number of plans).
- Whether fleet per-node key scoping is retrofitted in the same build or
  immediately after (same B2 client either way).
- Managed recovery — companion spec: `specs/managed_backup_recovery.md`
  (wrapped-key custody; changes this spec's custody sentence only for
  tenants who opt into that tier).
