# Mailbox Receive Mode & Relay Surface Placement

**Status:** Implemented 2026-07-19 (built same day it was decided, owner-directed).
**Scope:** The relay-or-direct onboarding gate, and where every relay surface lives after the Relay tab's dissolution.

## Background — what prompted this

The owner walked the tenant flow on jeremytunnell.com (the first customer-cloud VPS) as a fresh user and hit three walls:

1. After logging in there was **no clear next step** anywhere — the natural flow (Accounts → add a domain → add `info@scrolldaddy.app`) never asks how mail will physically reach the server. The relay was an opt-in side quest on a tab nothing pointed to, yet the answer changes every DNS record the Setup tab prescribes. Choosing a relay *after* publishing direct DNS means republishing everything.
2. The Relay tab was **jargon-dense and mixed audiences**: tenant status, tenant configuration, and the operator's shared-relay-service ("fleet") console all on one page. A customer deployment could see landlord vocabulary; the operator's own deployment (dev) showed both roles interleaved.
3. The word **"provisioned" misled**: a relay row can exist (a hosted slot reserved, a VPS built) while the relay is nowhere near *fully set up* — the customer's DNS cutover and ownership proof always remain, because no automation can reach into the customer's DNS zone. UI copy treating "row exists" as "relay chosen and working" reads wrong.

## Decisions

### D1 — The receive-mode choice gates everything (owner)

The FIRST thing an admin sees in the mailbox area is one question: does mail come straight to this server, or through a relay? Until it is answered, the Mailboxes, Accounts, and Setup tabs (and the domain/mailbox editors) render **only the choice card** — domains and mailboxes cannot be created first. Rationale: every DNS prescription hangs on the answer; collecting it after the fact invalidates published records.

### D2 — The choice belongs to the admin, even when a relay is already reserved (owner)

A relay row existing (e.g. a slot reserved automatically at order time) does **not** suppress the card. The card still appears at least once, with the relay column annotated that a spot is already reserved and choosing relay continues its setup. Rationale: reserved-and-ready is the platform's half of the job; the choice is the user's. This also resolves the "provisioned" confusion — copy now says *reserved*, never implies working.

Corollary: **running deployments are never gated.** Live domains with no stored choice mean the deployment made its choice by operating; resolution reports what it is doing (relay row → relay, else direct). Resolution order: stored choice (`mailbox_receive_mode` setting) → running state → undecided. Real running state is only consulted when no explicit choice exists; an explicit choice always wins (a stale one is surfaced by the Setup checks, not silently overridden).

### D3 — The card is a pros/cons table and names the Fortress requirement (owner)

Not two bare buttons: a brief comparison — setup effort, whether the server's address is public or hidden, and that **a relay is required for the Fortress email security level** (Fortress is the differentiator that makes the choice non-obvious). One choose button per column; one footnote (deployment-wide, changeable later). No explainer prose beyond the table.

### D4 — The Relay tab dissolves; surfaces follow their nature (owner: "both halves are in the wrong place")

- **Setup tab → Relay section** (`includes/relay_section.php`): everything that answers *is my relay set up and working* — relay rows with the health battery and lifecycle actions, hosted-slot enroll/refresh/release with the read-only ownership-proof state, the provision-your-own form, the origin-leak probe. Renders whenever the receive mode is relay or a relay row exists. The "Use a relay" choice lands here.
- **Settings tab → relay configuration**: the hosted relay service connection (URL + API keys) and the outbound sending mode. Configuration, not setup — and hidden entirely until the deployment is on the relay path, so direct deployments never see it.
- Shared machinery (action handlers, view-var assemblers, job dispatch, health battery, shard DNS rows, reconciles) lives in `includes/relay_admin.php` — one implementation serving both tenant surfaces and the fleet console.

### D5 — The operator fleet console hangs off Server Manager (owner)

The shared-relay-service control panel (service switch + MX zone, shards, DNS-to-publish, provision shard) is **landlord infrastructure, not mailbox usage**, and the landlord relationship is one-directional: tenants call the operator's public API and pull their mail; the operator never has access to tenant sites. So the console is its own page (`admin_mailbox_fleet`, superadmin, requires server_manager — shards are managed nodes and provisioning runs as its jobs), reached from a Relay Fleet button on the Server Manager dashboard. Customer deployments carry no trace of it in the mailbox tabs. The code stays in the mailbox plugin (it owns the fleet tables and FleetService); only the surface moved.

### D6 — No redirect for the old URL (owner)

`/plugins/mailbox/admin/admin_mailbox_relay` is deleted outright, not redirected. Pre-launch, nothing external links there; a compatibility stub is dead weight.

## Rejected alternatives

- **Relay row suppresses the choice card** (first draft of D2): rejected because provisioning-as-part-of-setup would silently decide for the user, and "provisioned" ≠ "fully set up".
- **Operator console as a mailbox "Fleet" tab or a Settings-tab section**: rejected — mixes the landlord role into tenant surfaces; Server Manager is where the operator already works and is a hard dependency anyway.
- **Redirecting the old Relay URL to Setup**: built first, then removed per D6.

## Follow-ups (open at time of writing)

- What the relay path shows *next* after choosing relay (guided enroll-vs-provision framing) — the owner has further feedback queued.
- Bug found during the walkthrough rollback, fix pending: **releasing a fleet slot never revokes its domain claims** (`fleet_release_logic` only flips slot status; the live-claim filter is status-based), so a released slot's pending claims block those domains fleet-wide against re-enrollment. Needs the release path to revoke claims plus a one-off revoke of slot t172's two stale claims on dev.
- Order-time fleet auto-enrollment (reserve the slot during customer-cloud provisioning) remains a build item in the fleet spec; D2 ensures it composes with the gate.
