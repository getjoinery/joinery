# Mailbox — Shared Relay Fleet (Hosted Fortress Ingest)

**Status:** Draft / awaiting implementation
**Version:** 1.0
**Builds on:** `specs/implemented/mailbox_hardened_ingest_relay.md` (the relay
stack this fleet runs), `specs/mailbox_relay_inbound_only.md` (**hard
dependency** — the fleet exists only in inbound-only mode; a shared box that
originates tenants' compose mail would own shared sender reputation, which is
exactly what that spec removes), `specs/implemented/mailbox_security_levels.md`
(Fortress requires a relay).

## Goal

The relay is the one piece of Fortress a typical operator won't build: a
second VPS to buy, DNS and PTR records to manage, a box to rotate. The $5/month
and the ops are the blocker — anyone willing to pay and tinker can already
self-host the relay, so the audience for a hosted option is precisely the
people for whom per-tenant pricing makes no sense.

The offer: **the platform operator (getjoinery) runs a shared fleet of
hardened relays as a service.** A tenant deployment points its MX at a fleet
hostname and gets edge-sealed ingest and a hidden origin with zero extra
infrastructure, zero extra logins, and marginal cost near zero (one shard
fronts many tenants).

**The trust statement, up front and published:** this trades one insider in.
The fleet operator stands at the plaintext-arrival moment for every tenant on
the fleet and *could* read inbound mail in transit while actively compromised
or malicious — the same position Proton Mail's MX occupies, and the same
honest caveat. What the operator can never reach: the tenant's archive, keys,
drive, passwords (all on the tenant's box), and the tenant's sending identity
(DKIM keys never leave the tenant's app). The exit ramp keeps the trust cheap:
point your MX at your own relay whenever you want — same stack, nothing else
changes.

## Threat Model

**Compromise of a fleet shard yields:** inbound transit mail for the tenants
on that shard, from compromise until rebuild, plus spool metadata (recipients,
message-ids, sizes) awaiting pull. Disk at rest is ciphertext-only, exactly as
on a self-hosted relay.

**It never yields:** tenant archives or history, user keys, outbound sending
capability as any tenant, or credentials into any tenant box (each WireGuard
peering is initiated by the tenant and grants access only to that tenant's
spool).

**The new risk is aggregation** — one shard is many tenants' transit windows.
Named mitigations, all policy-visible:

- **Sharding.** The fleet is N boxes, not one; a compromise is bounded to a
  shard's tenant list. Shard size is a dial (capacity/blast-radius tradeoff),
  not a redesign.
- **Scheduled rebuild as fleet policy.** The relay spec's routine in-place
  rebuild runs fleet-wide on a published cadence: persistence on a shard has
  a shelf life, and an attacker must re-win a near-codeless box repeatedly.
- **Metadata honesty.** The operator necessarily sees envelope metadata
  (who's mailing whom) for all fleet tenants — inherent to operating any MX,
  stated in the security model, never minimized.

## Architecture

A shard runs **exactly the self-hosted relay stack** — `provision_relay.sh`,
Postfix + verify milters + RBL, the Go sealer, filesystem spool, WireGuard,
key-only SSH — plus the multi-tenant additions below. One codebase, no fork:
a shard is a relay whose maps happen to contain more than one tenant.

### DNS indirection: per-tenant MX hostnames

Each tenant gets a stable hostname the operator controls, e.g.
`t-{tenant}.mx.getjoinery.com`, and points every hosted domain's MX at it.
MX targets cannot be CNAMEs, so the indirection is an operator-controlled
**A record**: re-sharding a tenant, rotating a shard's IP, or replacing a
burned box is an A-record change on the operator's side — tenants never
touch DNS after setup. PTR for each shard IP resolves to its shard hostname.

### Multi-tenancy on a shard

- **Per-tenant WireGuard peers.** Tenants dial out exactly as today; a shard
  is a passive listener with N peers. Peer isolation is the tunnel boundary.
- **Per-tenant spool namespaces + pull accounts.** Each tenant's sealed items
  land under its own spool directory; each tenant pulls over its own SSH
  account, chrooted/scoped to its directory. Tenant A cannot list, read, or
  ack tenant B's spool.
- **Merged recipient map.** Per-tenant alias maps (already the sync unit)
  merge into the shard's Postfix recipient validation and the sealer's
  seal-target lookup. Sealing needs no multi-tenant changes at all — the map
  already carries a seal target per alias, so every tenant's Fortress mail is
  sealed to its own users' keys and Standard/Private mail to that tenant's
  transport key.
- **Per-tenant rate limits.** Inbound acceptance and forward-mode relaying
  are throttled per tenant shard-side, so one tenant's flood or forwarded
  spam degrades only that tenant.

### Enrollment

In the Fortress setup flow, "hosted relay" appears beside "provision your
own." Choosing it:

1. The tenant instance calls the fleet service API (operator-side) to request
   a slot; the fleet assigns a shard and returns the tenant MX hostname, the
   shard's WireGuard endpoint + public key, and spool-account details.
2. **Domain-ownership verification before the fleet accepts a single message
   for a domain** — a DNS TXT challenge the tenant's Setup tab walks through,
   with fleet-wide uniqueness enforcement. This is a security boundary, not
   bookkeeping: without it, tenant A claims tenant B's domain and the fleet
   delivers B's mail into A's sealed spool.
3. The tenant pushes its WireGuard public key and alias map; the existing
   setup checks re-target the fleet hostname (MX resolves to fleet, port 25,
   tunnel up, spool draining, map fresh, "Joinery IP absent from mail DNS").

Steps 1–3 are the same detect–instruct–verify Setup-tab pattern as
self-hosted provisioning; the only new manual step for the tenant is the MX
record itself, with copy-ready values inline.

### No outbound, structurally

Fleet shards run in inbound-only mode with **no smarthost opt-in offered** —
the option is self-hosted-relay-only. Tenant compose mail leaves via each
tenant's own outbound provider per the inbound-only spec; the fleet never
originates a tenant's compose send, so there is no shared sending IP to
protect and no fleet reputation to manage. The one remaining sending surface
is **forward-mode aliases** (inbound redistribution, executed shard-side at
the plaintext moment as on any relay): low volume, user-chosen destinations,
per-tenant throttled. Whether fleet forwards leave direct from the shard IP
or via an operator-owned provider account scoped to forwarding is an open
item below.

### Fleet operations

Shards are managed nodes on the **operator's** server_manager (job/agent
machinery, health dots, heartbeats) — tenants see only their own setup
checks. The provision-relay job type gains a fleet mode (provision shard,
add/remove tenant on shard, migrate tenant between shards = spool drain +
map move + A-record flip). Scheduled rebuild runs per shard on the published
cadence; senders' MTAs retry through the minutes of downtime as standard.

### Billing / tier

A fleet slot is a subscription feature on the operator's platform
(`core_tier_features.json` gating on the tenant side; the fleet service API
validates entitlement at enrollment and on a periodic re-check). Marginal
cost per tenant is cents, so where the feature lands in the tier ladder is a
product decision, not a cost one; suspension for lapsed entitlement stops
accepting new enrollments and, after a grace window, stops the tenant's
slot (tenant falls back to colocated MX or its own relay — mail queues at
senders during the DNS change, nothing is lost).

## Integration Points That Change

- **`provision_relay.sh` / relay sealer / map format** — multi-tenant map
  namespacing, per-tenant spool roots, per-tenant SSH pull accounts,
  per-tenant WireGuard peers. Everything stays one stack; a self-hosted
  relay is the one-tenant case.
- **Fleet service API (operator-side, new)** — enrollment, entitlement
  check, shard assignment, domain-claim challenge issuance/verification,
  tenant lifecycle (suspend, migrate, evict).
- **Fortress setup flow (`security_levels` guided setup)** — the
  hosted-vs-self-hosted fork; TXT-challenge walk-through; fleet-hostname
  variants of the existing checks.
- **`RelayMapSync` / `RelaySpoolConsumer` / `RelaySsh` (tenant side)** —
  target coordinates come from enrollment instead of self-provisioning;
  pull/ack semantics unchanged.
- **`server_manager` (operator side)** — shard node class, fleet job types,
  rebuild schedule.

## Documentation to Update

Current-state only, per docs rules:

- `plugins/mailbox/docs/overview.md` — receiving architecture gains the
  hosted-fleet topology beside colocated and self-hosted-relay; enrollment
  and the per-tenant MX hostname pattern.
- `specs/mailbox_security_model_public.md` — the hosted-relay trust
  statement verbatim-grade: what the operator can and cannot read, the
  aggregation risk and its mitigations (sharding, published rebuild
  cadence), metadata visibility, and the exit ramp.
- `docs/subscription_tiers.md` — the fleet-slot feature and its gating.

## Open Items to Confirm During Implementation

- **Forward-path sending from shards:** direct from shard IP (simplest; PTR
  and SPF for the forwarding subdomain name the shard) vs. an operator-owned
  provider account for forwards only (keeps shard IPs out of the sending
  business entirely but rests an operator API key on the shard — bounded to
  the forwarding subdomain). Decide once, fleet-wide.
- **Shard sizing and assignment policy** (tenants per shard; whether
  Fortress-heavy tenants are spread or packed).
- **Spool-directory quota per tenant** (a tenant that stops pulling must not
  fill a shard's disk for everyone).
- **Entitlement re-check cadence and grace-window length** on lapse.
- **Whether the fleet service API lives in the existing hosted-product
  machinery** (`specs/automated_hosting_provisioning_setup.md` /
  `specs/plugin_builder_hosted_product.md`) or stands alone.
- **Published rebuild cadence** (weekly vs monthly) — a marketing-visible
  security property; pick the number the ops budget can actually honor.
