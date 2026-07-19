# Mailbox — Topology-Aware Setup Tab (Relay/Fleet DNS Guidance)

**Status:** All decisions resolved (owner, 2026-07-19). Ready to build.
**Builds on:** `specs/mailbox_relay_shared_fleet.md` (hosted fleet, live-verified
2026-07-19 on shard1/t172), `specs/implemented/mailbox_relay_inbound_only.md`
(outbound rides the provider under a relay), the Setup tab's Receiving/Sending
grouping and prescribed-record work (admin_mailbox_setup 2.x,
InboundEmailSetupCheck 1.16).

## Goal

The Setup tab is the one page a user follows to get a domain's mail DNS right:
detect, prescribe a copy-ready record, verify. Today every prescription assumes
the **colocated** topology (the box is the MX). The moment a relay or fleet slot
fronts the deployment, that guidance flips from merely stale to **actively
wrong**: it tells the user to publish the box's hostname as MX and the box's IP
in SPF — the exact records the relay's own origin-hidden check exists to keep
out of DNS. Two tabs of one plugin prescribe contradictory records.

After this change, every prescription on the Setup tab is derived from the
deployment's **receive topology**, and the fleet's per-domain TXT ownership
claims surface as checklist rows with copy-ready fixes — so the Setup tab stays
the single answer to "what DNS do I publish for this domain," for every
topology. The Relay tab remains the lifecycle surface (enroll, claim, release);
it displays state but the Setup tab owns the guidance. Aggregate, never
duplicate.

## Topology model

Three receive topologies, resolved tenant-side from existing rows — no new
state:

| Topology | Resolution | MX target |
|---|---|---|
| **Colocated** | No live `MailboxRelay` row | `mailbox_mail_hostname` (the box) |
| **Self-hosted relay** | Live relay row, `mrl_is_hosted = false` | The relay's mail hostname |
| **Hosted fleet slot** | Live relay row, `mrl_is_hosted = true` | The slot's `mrl_mx_hostname` (e.g. `t172.mx.getjoinery.com`) |

**Topology is deployment-level; security level is per-domain.** There is no
per-domain topology fork: once a relay fronts the deployment it is the MX for
every hosted domain (fleet doctrine — a mixed MX keeps the box's IP in DNS and
its port 25 open, defeating the hidden origin for all domains; and the
shrunken-box end state closes the local listener, so a colocated domain would
stop receiving). "One account protected, one not" is expressed as per-domain
security levels (Standard domains ride the relay with transparent
transport-key sealing) on the single topology. Domains DO differ mid-cutover —
each domain's rows compare its actual DNS against the one prescribed target,
so an uncut domain honestly fails until its MX moves.

**Prescriptions follow the intended topology, not the enabled state.** A relay
row exists disabled during cutover (created by provisioning/enrollment; the
admin enables it after checks pass). The checklist's whole purpose is to walk
the user TO that end state, so a disabled relay row already switches the
prescriptions to relay targets — otherwise the tab would prescribe the
colocated records mid-cutover and the user would publish DNS they are about to
replace. (Decision 1 below.)

**One field carries the MX target for both relay topologies.** The hosted path
already stores it (`mrl_mx_hostname`, from slot coordinates). The self-hosted
path does not: `JobResultProcessor::register_relay_row` drops the
`mail_hostname` the admin typed into the provision form. Fix: persist it into
`mrl_mx_hostname` there, and backfill from the provision job params on the
Relay tab's reconcile pass for existing rows.

## Row-by-row changes (InboundEmailSetupCheck)

Grounded in the current row inventory. "Fronted" = self-hosted relay or fleet
slot unless split.

### Receiving group

- **`domain.mx`** — Fronted: PASS when the MX target string-equals the relay MX
  hostname AND that name resolves to the relay/shard public IP
  (`mrl_public_ip`). The owned-target heuristic does not apply (the fleet
  hostname lives in the operator's zone — exact match is the only correct
  target). Fix: `dns_record` MX, priority 10, value = relay MX hostname.
  Wrong-target summaries keep the "mail is still being delivered to the old
  provider" honesty.
- **NEW `domain.ownership`** (hosted fleet only, REQUIRED, Receiving) — the
  fleet accepts no mail for a domain until its owner proves control by
  publishing a TXT code in that domain's DNS. **No user-facing verbs**: the
  words "claim" and "verify" never appear; there are no buttons. The row
  behaves like every other DNS row — publish the record, the check goes green:
  - The system files the challenge itself (idempotent): on enrollment for
    every already-registered domain, and on domain registration while a slot
    exists. The user never initiates anything.
  - Unproven → FAIL "Prove you own <domain>: publish this record at your DNS
    provider," TXT challenge as the copy-ready `dns_record` fix, summary "The
    relay accepts no mail for this domain until this record is published."
  - Proven → PASS "Ownership proven."
  Each check run asks the fleet to (re-)look for pending records — the
  operator-side verification is an idempotent DNS lookup, so running it on
  every pass is safe and removes the button. State comes from
  `FleetClient::status()` (Decision 2); API unreachable → one UNKNOWN row
  naming the error, never a fatal.
  The Relay tab's claims table becomes a read-only **Ownership proofs** state
  table (admin visibility, no required actions).
- **`mailhost.a_record` / `a_matches_ip`** — Fronted: verify the **relay MX
  hostname** resolves to the relay public IP. For a fleet slot this record is
  operator-published: on failure the row is INFO/WARN "the fleet operator's
  DNS is not in place yet" with no user fix (the tenant cannot act). For
  self-hosted it stays REQUIRED with the A-record fix (the tenant owns that
  zone).
- **`mailhost.ptr` / `ptr_matches`** — Fronted: the **relay IP's** PTR must
  name the relay hostname. Fleet: operator-owned, INFO row (states the
  expected value, no tenant fix). Self-hosted: REQUIRED, existing guidance
  retargeted. The box's own PTR rows drop under fronted topologies (nothing
  receives or sends directly from the box).
- **`host.port25`** — Fronted: inverts intent. The relay is the listener; after
  cutover the box should not expose port 25 ("shrunken main box"). Phase 1:
  the row becomes advanced-view INFO ("relay is the MX; close port 25 on this
  box after cutover"). Full decommission automation is out of scope
  (Decision 3).
- **`host.postfix` / `host.domain_map` / `host.transport` /
  `host.inbound_verification`** — unchanged while deferred-ingest parsing still
  runs on the box; they describe the local pipeline that processes pulled
  spool items. Revisit wording only.

### Sending group

- **`domain.spf`** — the prescribed record must never name the box under a
  fronted topology (`checkOriginHidden` fails on exactly that):
  - Provider outbound (default): `v=spf1 <provider mechanism> -all`, always
    copy-ready — the mechanism comes from the provider integration (static
    include, settings-derived `a:<smtp_host>` for custom SMTP, or fetched from
    the provider's API for per-account-record providers). Local-sendmail
    outbound under a fronted topology gets no SPF prescription: the row
    prescribes switching outbound providers (Decision 5).
  - Smarthost outbound (advanced, self-hosted only): `v=spf1 ip4:<relay
    public IP> -all`.
  - Colocated: unchanged (`ip4:<box IP>` + provider include).
- **`domain.fwd_spf` / `domain.fwd_mx`** — forwarding subdomain rows name the
  **relay** (fleet decision: forwards leave direct from the shard IP; PTR and
  SPF for the forwarding subdomain name the shard).
- **`domain.dkim` / `dkim_pending`** — unchanged: signing keys live on the box
  and sign at composition regardless of topology.
- **`domain.dmarc`** — unchanged.
- **`plugin.relay`** (outbound forwarding relay) — unchanged.

### Cutover completion

- **NEW `plugin.relay_enable`** (fronted, relay row disabled only) — when every
  hosted domain's claim is verified and MX matches the relay target, a
  REQUIRED FAIL row: "DNS is cut over but the relay is not enabled — mail is
  arriving at the relay with no consumer. Enable it on the Relay tab." Before
  DNS is ready it renders INFO ("enable after the rows above go green"). The
  enable itself stays the admin's explicit act on the Relay tab.

## Operator side: DNS to publish

The operator's fleet box (Relay tab) gains a **DNS to publish** table — the
operator half of the same guidance, currently tribal knowledge:

- Per live slot: A `<slot mx hostname>` → shard public IP, with a live
  resolution check (green/red) and copy fields.
- Per shard: A `<shard hostname>` → shard IP, and the shard PTR expectation.

Auto-publishing via a DNS provider API is explicitly out of scope here; this
table is the manual floor it would later automate.

## Integration points that change

- `plugins/mailbox/includes/InboundEmailSetupCheck.php` — topology resolution
  (one query for the live relay row + optional FleetClient status), prescribed
  MX/SPF/PTR/A derivations, `domain.ownership` + `plugin.relay_enable` rows,
  fronted-mode row suppressions/retargets.
- `plugins/mailbox/admin/admin_mailbox_setup.php` — no structural change; new
  rows land in the existing Receiving/Sending groups (claim row = Receiving).
- `plugins/server_manager/includes/JobResultProcessor.php` — persist
  `mail_hostname` → `mrl_mx_hostname` in `register_relay_row`.
- `plugins/mailbox/admin/admin_mailbox_relay.php` + logic — operator DNS table;
  claims table becomes the read-only Ownership proofs state table (Decision 4).
- Tests: setup-check verdicts per topology (colocated rows unchanged as the
  regression floor; fronted MX/SPF/claim-row matrix; the fleet test extends to
  hostname/claim coordinate assertions).

## Documentation to update

- `plugins/mailbox/docs/overview.md` — Setup tab section describes topology
  derivation and the claim row; operator section gains the DNS-to-publish
  table.
- `docs/email_system.md` — SPF guidance under fronted topologies (provider
  include only, never the box IP).

## Decisions (all resolved)

1. **Disabled relay row already flips prescriptions — DECIDED (owner,
   2026-07-19).** A relay row's existence (enabled or not) switches every
   prescription to relay targets; the checklist walks each domain to the end
   state. Only deleting/releasing the relay returns colocated guidance.
2. **FleetClient call from the setup check — DECIDED (owner, 2026-07-19):
   live.** One `FleetClient::status()` call per check run, no cache; on
   failure the claim row renders UNKNOWN naming the error and the rest of the
   page is unaffected.
3. **Box decommission scope — DECIDED (owner, 2026-07-19): out of scope
   here.** This spec only re-words `host.port25` under fronted topologies
   (honest "decommission pending" note). The decommission itself is specced
   separately in `specs/mailbox_listener_decommission.md`; when that lands,
   the note becomes an actionable control.
4. **Ownership-proof UX — DECIDED (owner, 2026-07-19): buttonless, no
   claim/verify vocabulary.** Challenges are filed automatically (enrollment +
   domain registration), verification re-runs on every check pass, and the row
   reads like any other DNS row: publish the record, the check goes green.
   `fleet_claim`/`fleet_verify` remain as API actions the automation calls;
   they stop being user-facing verbs. Relay tab table becomes read-only
   "Ownership proofs."
5. **SPF prescription is always copy-ready — DECIDED (owner, 2026-07-19).**
   The provider interface grows from a static include
   (`getSpfIncludeDomain()`) to producing the provider's **SPF mechanism for
   a domain**: static include for Mailgun-class providers; derived from
   configured settings for custom SMTP (`a:<smtp_host>`); fetched from the
   provider's API for per-account-record providers (Resend-class). The box IP
   is never prescribed under a fronted topology. The one residual
   non-prescription: local-sendmail outbound, where no record can both work
   and hide the origin — that row prescribes switching outbound providers
   (mail itself egresses from the box; SPF is not the leak), aligned with the
   decommission guardrail.
