# Subdomain Sandbox — Kick-the-Tires Trial on a Name We Own

**Status:** Spec (unbuilt).
**Relationship to managed domains:** none, deliberately. This tier solves no
DNS or email problem for a paying buyer and is not part of the managed-domain
pipeline (`specs/managed_domain_registration.md`). A sandbox is a place to try
the product, not a place to live.

## What this does for the user

Someone curious about the platform gets a working site in seconds — no
payment, no domain, no postal address, no DNS. They type a name
(`jane` → `jane.joinery.app`), and a live instance exists before the page
finishes loading. They can click through the whole product: the member area,
the mailbox UI, Drive, the admin pages — everything a demo video would show,
except it is theirs to poke.

What it is **not**: an identity. The sandbox exists so a prospect can decide
whether to buy; it is explicitly temporary and says so in its own chrome. When
the sandbox holder buys the real package, they start fresh on their own domain
— nothing about the sandbox address carries over, and the product never
pretends otherwise.

## Decided up front

- **Not a funnel into the paid identity.** Email addresses cannot migrate off
  a subdomain — `jane@jane.joinery.app` dies when `jane@smithfamily.com` is
  born. So the sandbox never encourages anyone to accumulate identity on it:
  no contact-your-friends prompts, no "your new address" framing, and a
  permanent banner naming the expiry date and the upgrade path.
- **Temporary by construction.** Every sandbox has a fixed lifetime from
  creation (see D2 for the number). Expiry is destruction, not conversion —
  a sandbox that mattered is a reason to buy, and buying starts clean.
- **Outbound mail goes only to the holder.** The sandbox mailbox works —
  inbound to `*@jane.joinery.app` delivers, the reader and composer are fully
  live — but outbound is restricted to the email address the holder signed up
  with. That proves the mail flow end to end while making the tier worthless
  to a spammer, and it protects the parent domain's sending reputation, which
  demos and every other sandbox share.
- **Shared-host compute only.** Sandboxes are deployments on the operator's
  shared box (the existing `shared_host` compute mode) — never a dedicated
  cloud instance. Marginal cost per sandbox must stay near zero or the free
  tier is not free for the operator.
- **Zero data collected beyond signup.** An email address (verified — it is
  also the only allowed outbound destination) and the chosen subdomain. No
  registrant contact, no card.

## The prospect journey

1. Prospect enters a desired name on the try-it page; availability is a local
   uniqueness check (no registrar involved). Reserved and offensive names are
   blocked by a denylist.
2. Prospect verifies their email address. The sandbox provisions: a
   shared-host deployment, DNS under the parent zone, TLS via the wildcard
   certificate, mailbox active under the sandbox's own subdomain.
3. Welcome email (to their verified address) with the live URL and the expiry
   date.
4. Persistent in-product banner: what a sandbox is, when it expires, and the
   one action that matters — buy the real package on a domain of their own.
5. At expiry: a warning to the holder's verified address ahead of the date,
   then the deployment and its data are destroyed. No export path (D3), no
   grace resurrection.

## Architecture

Reuses the shared-host provisioning pipeline; the new work is the self-serve
front door, the parent-zone DNS, and the lifecycle.

| Piece | Where | What |
|-------|-------|------|
| Parent zone | operator DNS | One wildcard `A` record and a wildcard TLS certificate on the sandbox parent domain (D1). Per-sandbox DNS work is therefore zero. |
| Signup flow | getjoinery.com views + logic | Public try-it page: name check, email verification, provision trigger. No store order — this is not a product purchase. |
| Sandbox record | new data class `sbx_sandboxes` | One row per sandbox: subdomain, holder email, linked deployment, created + expires timestamps, `status` (`provisioning` / `active` / `expired` / `destroyed`). |
| Provisioning | existing shared-host pipeline | Same deployment mechanics as a paid shared-host order, minus billing. Sandbox deployments are flagged so fleet tooling can tell them from paying tenants. |
| Mail inbound | mailbox plugin | The sandbox subdomain is a normal inbound domain under the parent's MX; delivers to the sandbox's own mailboxes. |
| Outbound restriction | mailbox plugin / EmailSender on the sandbox deployment | Sandbox deployments run with an outbound policy of "holder's verified address only". Enforced server-side at send time, not hidden in the UI. |
| Expiry sweep | new scheduled task **Expire Sandboxes** | Warns ahead of expiry, then destroys expired deployments and their data, and marks the row `destroyed`. Idempotent; a failed destroy re-runs. |
| Name denylist | signup logic | Reserved names (`www`, `mail`, `admin`, product names, node hostnames) plus an offensive-terms list. |

## Abuse posture

The tier is designed to be useless to abusers rather than policed after the
fact: outbound mail cannot reach anyone but the holder, compute is shared and
resource-capped, lifetime is short, and one verified email address gets a
small fixed number of concurrent sandboxes. If that posture ever proves
insufficient, tightening happens at signup (rate limits, provider blocklists),
not by weakening the product for legitimate prospects.

## Open decisions

- **D1 — Parent domain.** `joinery.app` is the working placeholder. The real
  choice matters mainly for mail reputation: the sandbox parent should not be
  a domain whose sending reputation the operator needs for anything else.
  Leaning: a dedicated name bought for this purpose, not getjoinery.com.
- **D2 — Sandbox lifetime.** Long enough to evaluate honestly, short enough
  that nobody nests. Leaning: 30 days, non-extendable.
- **D3 — Data at expiry.** Straight destruction (leaning — a sandbox is a
  sandbox, and an export path invites people to treat it as storage) vs. a
  one-shot export offer in the final warning email.
- **D4 — Concurrent-sandbox cap per verified address.** Leaning: 1.

## Out of scope

- Any path that converts a sandbox in place into a paid deployment — buying
  starts fresh on a real domain, always.
- Custom domains on a sandbox.
- Outbound mail beyond the holder's own address, at any setting.
- Dedicated compute (`customer_cloud`) for sandboxes.

## Documentation

When built, update — current-state voice only:

- `docs/deploy_and_upgrade.md` or the shared-host docs — sandbox deployments
  and how fleet tooling distinguishes them.
- `plugins/mailbox/docs/overview.md` — the sandbox outbound policy.
- `docs/scheduled_tasks.md` — the Expire Sandboxes task.
