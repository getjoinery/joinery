# Managed hosting and Joinery-run services — the umbrella

**Status:** Draft, 2026-09-19. Split into three phase specs on 2026-09-19 so
that each fits in one head at a time; this file is the map, the doctrine,
and the two contracts between the phases. Nothing here is built.

| Phase | File | Delivers | Depends on |
|---|---|---|---|
| 1 | `managed_hosting_phase1_purchase.md` | a Managed site configured on a Server Manager page, paid once, activated by payment | nothing unbuilt |
| 2 | `services_phase2_platform.md` | our mail and our backup shelf for self-hosted sites, against a paid-through date set by hand; the lapse ladder; the switch-over | nothing unbuilt |
| 3 | `services_phase3_selling_nudge.md` | buying the services in whatever shape pricing decides; the two-door nudge | phases 1 and 2, and `DEFERRED_services_pricing.md` |

```
Phase 1 (Managed purchase) ──┐
                             ├──▶ Phase 3 (selling services, the nudge)
Phase 2 (services platform) ─┘
```

Phases 1 and 2 share nothing and can be built in either order or in
parallel. Phase 3 needs both and the pricing decision, so it lands last by
construction. Start with phase 1 (closest to money, everything under it
built and proven); pull phase 2 forward if beta testers should use the
services before pricing exists — its hand-set date makes that possible.

**Owner decisions, 2026-09-17, in order:**
- The buyer configures first, then pays; payment activates. The domain is
  stored at configure time and registered only after payment.
- Joinery's outbound mail and backup accounts are offered to self-hosters
  to streamline an install, and the product then nudges people to their
  own accounts. The keys stay ours.
- No free trial: a free start is an incentive for spammers to create
  accounts. A warm-up ramp on new subaccounts is unnecessary complexity.
- Pricing is deferred to `DEFERRED_services_pricing.md`, in the owner's
  words: build the provisioning and the nudge machinery "with the
  stipulation that we have no idea how we're going to price." Price, term
  and allowances live on the product; nothing in code changes with them.

## Doctrine, once

1. Whose account the server is born on is the **product's** decision,
   never the buyer's.
2. Nothing that costs money happens before payment, and a step that spends
   is guarded by **status**, not a timestamp.
3. The store never learns what a line is for. It carries a product, a price
   and an opaque answer; Server Manager interprets the answer.
4. Master keys never leave the plane. What reaches a box is cut to that
   customer's own slice and revocable on its own; the box owner can read
   anything on the box, so every credential there is one they are allowed
   to have. Enforcement lives at the provider or on the plane, never in a
   setting on the box.
5. The bytes we store we cannot read: backups seal to the customer's
   recovery key before upload.
6. The nudge names the customer's own account first and never sells a
   bigger plan as the answer to an allowance.

## Contract C1 — a line that points at a row (phase 1 → phase 3)

A product that Server Manager fulfils carries **one requirement with one
hidden answer**: the id of a Server Manager row the buyer already owns.
The requirement renders no fields; `validate()` refuses a row that is not
the buyer's or not in the expected state; `process()` stores the id in the
question/answer shape an order item needs. The fulfilment provider's
`checkAvailability()` refuses the charge if the row moved since the line
was made, with a buyer-facing sentence; `fulfill()` loads the row by the
id, checks state and ownership again, and performs the activation, stamping
`*_external_order_item_id` on the row. The order item id is the thread from
every store fact back to the row.

Phase 1 instantiates it as `ManagedSiteRequirement` → a draft provision
(`pending_payment` → `ready`). Phase 3 instantiates it as
`JoineryServiceRequirement` → a service tenant (a date written).

## Contract C2 — the tenant row and the status response (phase 2 → phase 3)

`svt_service_tenants`, one row per site per service, with at least:
`svt_paid_until` (timestamp, null = not entitled), `svt_allowance`
(integer, the service's unit: sends a month or GB), `svt_state`, the
provider ids, the figure and its measured time, and the ladder timestamps.
Phase 2 writes the date from an admin grant action; phase 3 writes it from
a payment. The reconcile treats the two identically.

The **status** action returns, per service: `figure`, `allowance`,
`paid_until`, `state`, `notice`, and — added by phase 3 — the product on
sale for this tenant (`label`, `price`, `url`), empty when none. The site
writes these into the five banner settings and never interprets them
further; the second door renders only when the product fields are present.

## What is retired or absorbed by the three phases

- `hosted_trial_provisioning.md` → `implemented/` (built; its verification
  is phase 1 §11 and phase 2 §11).
- `automatic_install_mail_topology.md` → `DEFERRED_` (never shipped; the
  Managed topology is single-server by decision).
- `managed_backups.md` removed (its design is phase 2 §3–§7).
- The domain Question intake, the product-page domain fields and
  `ManagedDomainRequirement`'s `post_purchase()` — retired by phase 1 §8.

Still separate and referenced: `managed_customer_departure.md`,
`managed_backup_recovery.md`, `hosted_bounce_handling.md`,
`getjoinery_hosted_tier_copy.md`, `getjoinery_purchase_path_verification.md`,
`subdomain_sandbox_tier.md`, `keyless_provisioning.md`.

## Owner operations

**Dev (the development plane) holds, since 2026-09-19:** a Namecheap key
against the sandbox, the Stripe test keys, and the operator Linode token.
Phase 1's gate runs there.

**getjoinery (the production Server Manager) still needs, before any live
gate:** SMTP2GO account, master key, webhook secret, MSP and affiliate
enrolment; a Linode token scoped `linodes:read_write`; a B2 bucket and a
master key with `writeKeys`, `listKeys`, `deleteKeys` (the current key
cannot create keys, so every shelf path ships off until then); Namecheap
API eligibility on the live account and the plane's IP allowlisted; a
storage referral link.

## Not of this build

- **E0. The owner's record of what the plane read** — a precondition of
  the first customer-owned node being paired, not of these phases.
  `agent_log_access.md` lets a paired plane read a node's redacted log
  excerpts, on by default, and records each read only in the plane's job
  row. That is the owner's record while owner and operator are the same
  person; a customer's node needs its own, shown on its own Management Node
  page, before it is paired. Small: the agent appends one line per read to
  a root-owned file and the page shows the last twenty.
