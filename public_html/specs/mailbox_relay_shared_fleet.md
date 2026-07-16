# Mailbox — Shared Relay Fleet (Hosted Fortress Ingest)

**Status:** Draft / awaiting implementation
**Version:** 1.1 — tenancy-native design: the relay stack is multi-tenant at
every layer with self-hosted as the N=1 case; map sync becomes fragment push +
shard-side merge, named as the domain-claim enforcement point; rebuild carries
the spool across the wipe; relay spam scoring is stateless (no Bayes).
**Builds on:** `specs/implemented/mailbox_hardened_ingest_relay.md` (the relay
stack this fleet runs), `specs/implemented/mailbox_relay_inbound_only.md` (**hard
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
key-only SSH. One codebase, no fork — and no fleet *mode*: the relay stack is
**natively multi-tenant, and a self-hosted relay is a fleet of one**. Every
layer below is the only code path, with N=1 as the degenerate case — one spool
subdirectory, one pull account, one WireGuard peer, one map fragment. A shard
is a relay on which the add-tenant operation has run more than once; nothing
is migrated or reconfigured to go from one tenant to many. The honest cost of
this at N=1 is slight indirection (one directory level, one merge step of one
fragment) — paid once, in exchange for tenant two being an operation instead
of a redesign.

### DNS indirection: per-tenant MX hostnames

Each tenant gets a stable hostname the operator controls, e.g.
`t-{tenant}.mx.getjoinery.com`, and points every hosted domain's MX at it.
MX targets cannot be CNAMEs, so the indirection is an operator-controlled
**A record**: re-sharding a tenant, rotating a shard's IP, or replacing a
burned box is an A-record change on the operator's side — tenants never
touch DNS after setup. PTR for each shard IP resolves to its shard hostname.

### Multi-tenancy on a shard

- **Per-tenant WireGuard peers with allocated tunnel addresses.** Tenants
  dial out exactly as today; a shard is a passive listener with N peers.
  Peer isolation is the tunnel boundary. Tunnel IPs are allocated per tenant
  at enrollment from the relay's subnet (the relay is `.1`; the first
  tenant's allocation is today's fixed peer address), replacing the
  hard-coded one-peer address pair.
- **Per-tenant spool namespaces + pull accounts.** Each tenant's sealed items
  land under its own spool directory; each tenant pulls over its own SSH
  account, chrooted/scoped to its directory. Tenant A cannot list, read, or
  ack tenant B's spool. This replaces the single flat spool pulled over a
  root-class login even at N=1 — a strict improvement the relay's own threat
  model wants (the pull credential grants access to that tenant's spool and
  nothing else).
- **Per-tenant routing-map entries.** Seal-target selection already rides the
  map per alias and per domain, so every tenant's Fortress mail seals to its
  own users' keys and Standard/Private mail to that tenant's transport key
  with no sealer logic change. What is global in the routing map today and
  moves into per-tenant blocks: the SRS secret, the forward From identity,
  the transport-key fallback for SRS bounces, and the spool directory the
  sealer writes to. Modest sealer + map-schema changes, not a rewrite.
- **Per-tenant rate limits.** Inbound acceptance and forward-mode relaying
  are throttled per tenant shard-side, so one tenant's flood or forwarded
  spam degrades only that tenant. This is new machinery, not an adaptation —
  the relay has no throttling of any kind today — built with per-tenant keys
  from day one; at N=1 the limits are effectively box limits.
- **Stateless spam scoring.** The relay's rspamd stamps score headers from
  static rules only; the Bayes classifier and autolearn are off, and no
  statistical state persists in redis. Learned state on a shard would be one
  model trained on every tenant's mail — a cross-tenant privacy leak in
  token form and a poisoning vector (one tenant's flood skews everyone's
  scores). Nothing of value is lost: the relay's header was never the
  verdict — each tenant's own rspamd re-scores at ingest with its own state.
  One code path: self-hosted relays run the same stateless configuration.

### Map sync: fragment push and shard-side merge

Today the tenant's map sync writes the relay's global state directly:
`RelayMapSync` rsyncs whole map files into `/etc/postfix` over a root-class
SSH login, runs `postmap`, and reloads Postfix — a full replace. Neither the
privilege nor the replace semantics can survive multi-tenancy: a chrooted
tenant must not touch `/etc/postfix`, and tenant A's push must not erase
tenant B.

The replacement: each tenant pushes its **map fragment** — its own
recipients, domains, and per-tenant routing block — into a drop area inside
its chrooted home. A shard-side **merge unit** (a validation script triggered
on drop, not a stateful daemon) then:

1. **Validates the fragment against the tenant's domain allowlist** — the
   set of domains verified for that tenant, written outside the chroot by
   the fleet service on TXT-challenge success (or by `provision_relay.sh` at
   provision time on a self-hosted relay). A fragment naming any domain
   outside its allowlist is rejected whole and reported; nothing from it is
   installed.
2. Merges all tenants' valid fragments into the shard's Postfix maps and the
   sealer's routing map, runs `postmap`, and reloads.

**The merge is where the domain-claim boundary is mechanically enforced.**
Enrollment-time TXT verification alone does not close the cross-claim
attack: without merge-time validation, tenant A could bypass the challenge
by simply pushing a fragment naming tenant B's domain. The allowlist check
makes the claim a property the shard enforces on every sync, not a one-time
gate at enrollment.

At N=1, merging one fragment produces exactly the maps a self-hosted relay
has today — observable behavior identical — which is what lets self-hosted
and fleet stay one code path. Tenant-side `RelayMapSync` changes only its
target (a fragment into the drop area instead of files into `/etc/postfix`)
and sheds its root requirement; pull/ack semantics are untouched.

### Enrollment

In the Fortress setup flow, "hosted relay" appears beside "provision your
own." Choosing it:

1. The tenant instance calls the fleet service API (operator-side) to request
   a slot; the fleet assigns a shard and returns the tenant MX hostname, the
   shard's WireGuard endpoint + public key, the tenant's allocated tunnel
   address, and spool/pull-account details.
2. **Domain-ownership verification before the fleet accepts a single message
   for a domain** — a DNS TXT challenge the tenant's Setup tab walks through,
   with fleet-wide uniqueness enforcement. This is a security boundary, not
   bookkeeping: without it, tenant A claims tenant B's domain and the fleet
   delivers B's mail into A's sealed spool. On success the fleet service
   writes the domain into the tenant's shard-side allowlist, which the map
   merge then enforces on every subsequent sync (see *Map sync* above) — the
   claim is checked continuously, not only at enrollment.
3. The tenant pushes its WireGuard public key and map fragment; the existing
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
checks. The provision-relay job type gains fleet operations (provision
shard, add/remove tenant on shard, migrate tenant between shards = spool
drain + map move + A-record flip). Scheduled rebuild runs per shard on the
published cadence; senders' MTAs retry through the minutes of downtime as
standard.

**Division of labor — the same split the self-hosted relay already uses.**
The mailbox plugin is the brain: the fleet service API decides (this tenant
gets a slot on this shard, this domain is verified, this tenant is evicted)
and effects each decision by dispatching a server_manager job. server_manager
is the hands: it executes box-level work on shard nodes and never knows what
a tenant or a domain claim is; the mailbox plugin never SSHes to a shard
itself. Fleet work extends the existing relay job type — it adds no new
subsystem to server_manager.

**Rebuild carries the spool across the wipe.** "Nothing is lost in a
rebuild" is only true of mail the relay has not yet accepted — an accepted,
sealed, spooled item not yet pulled exists only on the relay's disk, and its
sender got a 250 and will never resend. The spool is precisely the buffer
for an unreachable tenant, so a calendar-driven fleet rebuild will
eventually fire while some tenant is offline holding hours of queued mail.
The rebuild job therefore copies the spool aside and puts it back with a
**validating restore**: only files matching the strict spool entry pattern
(`<id>.seal` / `<id>.meta`), restored into the owning tenant's directory
with correct ownership and no exec bits. This preserves data without
preserving persistence — the rebuild's security purpose is killing implanted
code, and the restored bytes are exactly the ciphertext + bounded metadata
the disk was already trusted to hold. (A relay compromised before rebuild
could have poisoned spool entries regardless; carrying them across adds no
surface the pull path did not already face.) The rejected alternative —
skipping rebuild while any tenant's spool is undrained — would let one
offline tenant stall the fleet's published security cadence indefinitely.

The spool is not the only place an accepted message can live. The Postfix
queue holds mail in the seconds between SMTP acceptance and the sealer
pipe, and — much longer — outbound forwards a destination is temporarily
deferring: a re-injected forward exists nowhere but the deferred queue, for
hours or days if the destination greylists or flaps. The rebuild sequence
therefore **closes port 25 first, flushes the queue for a bounded window,
and carries any still-deferred queue files across the wipe** alongside the
spool (same trust argument: an attacker who had the box could already
inject queue entries, so carrying them adds no surface Postfix did not
already face). With spool and queue both carried, the claim is precise:
**no accepted message is ever lost in a rebuild**; mail not yet accepted
waits at senders' MTAs. Self-hosted rebuilds use the same sequence; N=1 is
the same job.

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

- **`provision_relay.sh`** — becomes natively multi-tenant: provisioning
  creates the shard skeleton plus tenant #1 via the same **add-tenant
  operation** the fleet reuses (spool subdirectory, chrooted pull account,
  WireGuard peer with allocated tunnel address, allowlist entry); tenant
  removal is its inverse. No separate fleet mode. rspamd is provisioned
  stateless (Bayes classifier and autolearn off).
- **Relay sealer / routing map** — per-tenant blocks for SRS secret, forward
  From identity, transport-key fallback, and spool directory; per-recipient
  and per-domain seal targets unchanged.
- **Shard merge unit (new, runs on every relay)** — fragment validation
  against the tenant domain allowlist, merge, `postmap` + reload. The
  domain-claim enforcement point.
- **Fleet service API (operator-side, new)** — enrollment, entitlement
  check, shard assignment, domain-claim challenge issuance/verification,
  allowlist writes on verification success, tenant lifecycle (suspend,
  migrate, evict).
- **Fortress setup flow (`security_levels` guided setup)** — the
  hosted-vs-self-hosted fork; TXT-challenge walk-through; fleet-hostname
  variants of the existing checks.
- **`RelayMapSync` (tenant side)** — pushes a fragment into the tenant drop
  area instead of replacing files in `/etc/postfix`; no root-class login.
- **`RelaySpoolConsumer` / `RelaySsh` (tenant side)** — semantics unchanged;
  target coordinates (tunnel address, chrooted account, spool subdirectory)
  come from enrollment instead of self-provisioning.
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
- **Merge-unit trigger mechanism:** SSH forced-command on fragment push
  (merge runs synchronously, tenant gets the validation verdict in-band) vs.
  a path watch/timer (simpler account setup, verdict reported via the setup
  checks). Either way it stays a triggered script, not a resident daemon.
- **Shard sizing and assignment policy** (tenants per shard; whether
  Fortress-heavy tenants are spread or packed).
- **Spool-directory quota per tenant** (a tenant that stops pulling must not
  fill a shard's disk for everyone).
- **Entitlement re-check cadence and grace-window length** on lapse.
- **Fleet service API location — DECIDED: standalone.** It lives in the
  mailbox plugin and does anything only on the operator's own deployment,
  built from what the platform already has: `/api/v1` key auth for the
  customer account, tier gating for entitlement, server_manager for the
  shards. The hosted-product drafts
  (`specs/automated_hosting_provisioning_setup.md`,
  `specs/plugin_builder_hosted_product.md`) are not dependencies — if that
  machinery is ever built, it calls this enrollment surface rather than
  replacing it, so nothing here is throwaway.
- **Published rebuild cadence** (weekly vs monthly) — a marketing-visible
  security property; pick the number the ops budget can actually honor.
