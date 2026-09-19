# Joinery-run services, phase 3 — selling them, and the nudge

**Status:** Draft, 2026-09-19. Phase 3 of `managed_hosting_and_services.md`
(the umbrella; read its phase map and contracts C1 and C2 first).

**What this phase delivers.** A self-hoster buys the services in whatever
shape `DEFERRED_services_pricing.md` decides, and the payment writes the
same paid-through date phase 2's grant action wrote by hand. Every surface
that nudges names two doors: the customer's own account first, then
getjoinery's, at whatever is on sale.

**Owner decisions, 2026-09-17:** price, term and allowances live on the
product, never in this spec or in code; the machinery must accept any
pricing shape. No free trial (phase 2 §8).

**Depends on:** phase 1 (the pattern of one hidden id on a line and a
provider that activates — contract C1), phase 2 (the tenant row, its date,
and the status response — contract C2), and the pricing decision, as
products. **Feeds:** nothing; it is last by construction.

**Companions:** `DEFERRED_services_pricing.md` (everything about price),
`getjoinery_hosted_tier_copy.md` (the site copy), `smtp2go_affiliate_signup.md`.

---

## 1. Selling the services — entitlement that does not know the price

A self-hoster who wants no accounts of their own buys **Joinery Services**
on getjoinery. What it costs, how often, for which services and at what
allowances is `DEFERRED_services_pricing.md`'s question. The machinery
below accepts any of those shapes without change, because the only thing
it reads is a **date per service per site**, and every shape produces one:

| Shape | What the store records | What the tenant's date becomes |
|---|---|---|
| one-time, for a term | a paid line on a product whose plan carries a term | payment day + term, extended from the old date on a repeat purchase |
| monthly or yearly subscription | an active subscription line with a period end the webhook keeps current | the line's period end, while the line is active |
| a bundle | one product whose plan grants both services | the same date on both tenant rows |
| per service | two products whose plans grant one service each | dates set independently |
| a bigger tier | another product, another plan in the ladder | the same, with bigger allowances |
| a trial | the store's own trial period on a subscription line | the period end covers it (phase 2 §8 stands against one) |
| included in Managed | nothing; the hosting is the entitlement | no date; always entitled |

**How a product says what it grants.** The store's Purchase grants picker
on the product edit page (`pro_fulfillment_provider` + `pro_fulfillment_ref`)
lets a product name a fulfilment provider and one integer reference. Server
Manager registers `joinery_services` beside `customer_cloud`; its reference
is a **plan number**, and a plan is a named entry in the provider's ladder:
which services it grants, the allowance for each, and — for a one-time
product — the term in days (a subscription product's term is its own billing
period, which the store already knows). A new plan is one line in the
ladder and one product with the picker set to it; the meter, the banner, the
Services card and the reconcile read the plan and need no change. The
operator side finds every granting product with
`MultiProduct(['fulfillment_provider' => 'joinery_services'])`, the filter
the model already has, so the Services card lists whatever is on sale, at
the price on the product.

**Why not a tier feature.** `SubscriptionTier::GetUserTier()` resolves one
tier per account, so per-service products could not compose, and a
one-time term is not a membership. Entitlement reads the store's lines
directly; the tier system is not involved.

**Per site, not per account.** The subaccount and the prefix are per site,
so the purchase is too. Buy on the Sites page's Services card for that site;
the product carries one requirement, `JoineryServiceRequirement`, which
stores the tenant id on the line exactly as `ManagedSiteRequirement` stores
the draft id (phase 1 §6.1): no fields, a hidden id, refused unless the tenant is
the buyer's. A site that has never enrolled has no tenant row yet, so the
site's first enrol attempt created its rows at `unpaid` (phase 2 §4), so
the card always has a row to buy for. A second site is a second purchase.

**Entitlement is a date, derived, never typed** — the same column phase 2's
grant action writes by hand. `fulfill()` runs at
payment and, for a one-time product, extends `svt_paid_until` on each
tenant row the plan grants by the plan's term from the later of today and
the current date, and stamps the plan's allowances. For a subscription
product it stamps the allowances and the line's id; the reconcile then
reads that line's `odi_subscription_period_end` every pass while it is
active and writes it to the row. So the reconcile, on every pass, and the
status call, compare one column with today:

1. a **Managed** tenant is always entitled (allowances from the hosted
   settings);
2. a self-hosted tenant is entitled while `svt_paid_until` is in the future;
3. otherwise the lapse ladder in phase 2 §5.

No new subscription signals: a cancelled or failed line stops advancing its
period end, and the date falls into the past on its own. A refund is a
person at Stripe who also sets the date back.

**The allowance is a property of the plan**, never a global: the tenant row
snapshots it at each reconcile (`svt_allowance`), the SMTP2GO subaccount
`limit` is set from it (and re-set when it changes — a bigger plan takes
effect at the next pass), and the shelf meter compares against it.

**Buying it** is the Sites page's Services card on getjoinery: one row per
product on sale, price read from the product, and — when the tenant has a
date — the date and, inside the nudge window, the same rows as *keep going*.
The site-side surfaces (§2) link to the card. A Managed customer never
sees the offer. A tenant whose services have both been moved to the
customer's own accounts shows the date and nothing to buy.

## 2. The nudge — the two doors, at 80% and at renewal

The principle is the hosted spec's, widened by one door: outgrowing an
allowance or reaching the end of the year is a fork, and every surface
names **the two ways to keep going**, in this order: open your own account,
through our referral link; or keep paying getjoinery, at whatever is on
sale. Nothing else
— no third product, no bundle. The nudge is quiet until it is needed, and
then it is the same two sentences in three places. (The hosted spec's "no
bigger plan" stands for Managed compute; Joinery Services may have larger
plans later, each its own product, and the second door names the smallest
one that fits.)

### 2.1 The banner

`HostedPlanNotice` already renders, to permission 5 and up, where the
hosting stands and each allowance as a row: label, used of allowance, and
when the row is at 80% or more, the action as a link. On a Managed site
the plane pushes the figures over the channel today. Three additions:

- A state `services` beside the four billing states, for a self-hosted site
  using the services: the paid-through date, the allowance rows and the
  manage link; no billing sentence. Nothing renders until a tenant is paid,
  which is what keeps a plain self-hosted install silent.
- On a self-hosted site the figures come from the daily status poll
  (phase 2 §4), written into the same five settings the channel writes on a
  Managed site, so the renderer does not know the difference.
- A second action per row. The first is the referral link, the second the
  Services card. The price in the sentence is pushed with the figures, read
  from the product, never written into a template.

The row reads, below 80%: *Email sent through getjoinery: 612 of 1,000 this
month.* At 80%: *Email sent through getjoinery: 840 of 1,000 this month —
[open your own SMTP2GO account] or [a bigger plan]* (the second link only
when a bigger plan is on sale). At 100%: *Email through getjoinery is paused
until the 1st: 1,000 of 1,000 sent — …*, and the notice level is urgent. The
paid period has its own row from three weeks out (a subscription that will
renew on its own shows no such row; the date advances): *Your getjoinery
mail and backups are paid through 14 November — [open your own accounts] or
[keep going, <the product's price>]*. After the date, during grace: *ended
on 14 November; mail and backups stop on 28 November — …*, urgent. The
window is a setting, `server_manager_services_notice_days`, default 21.

### 2.2 The wizard step

The Email step and the Backups step are where the services were chosen, so
they are where they are left. Before a purchase, each step offers the
choice as a paid one: *Use getjoinery's — <what is on sale, at its price>*,
linking to the Services card; the step enrols itself when the customer
returns paid. An enrolled step is green and says what is in use,
the figure, and the paid-through date, with the switch-over form (§2.4)
folded closed under one line: *When you are ready to use your own account,
set it up here.* At 80%, or three weeks before the date, the line opens by
itself and carries both links; the step stays green. At 100%, or in grace,
the step is amber with the paused sentence and the two doors. After
revocation it is red and says exactly why uploads or sends stopped.

The referral links are the three settings that exist. The SMTP2GO affiliate
enrolment (`smtp2go_affiliate_signup.md`) and a storage referral are owner
tasks; until a link is set the action renders without a link, as the banner
already does.

### 2.3 The emails

From the operator side's metering phase to the site's admin address, each
the row's sentence, the two doors with their links, and the sentence that
the site keeps working (mail resumes on the 1st; local backups continue).
No marketing beyond the two doors. At most: one at 80% and one at 100% per
service per month; one at the notice window before the paid-through date
and one on the day, neither for a line that renews on its own; one at
revocation. A quiet customer whose payments go through gets none.

### 2.4 The switch-over

The forms that move a service to the customer's own account, and the rule
that the old is closed only after the new is proven, are phase 2 phase 2 §9. What
this phase adds: releasing early refunds nothing by itself, and what release
does to a paid line is a pricing question (`DEFERRED_services_pricing.md`
§5). A Managed customer's off-ramp for compute is
`managed_customer_departure.md`.

## 3. Settings

| Setting | Default | Meaning |
|---|---|---|
| `server_manager_services_notice_days` | 21 | how far ahead of the paid-through date the nudge begins (§2) |

No setting names a services product. A product declares that it grants a
service through its Purchase grants picker (§1), and the operator side
finds every such product by that declaration — so a new tier is a new
product, and a price change is a product edit.

## 4. Build order

1. The `joinery_services` fulfilment provider with its plan ladder (§1), and
   `JoineryServiceRequirement` carrying the tenant id. `fulfill()` writes
   the date and the allowances; the reconcile (phase 2 §5) reads a
   subscription line's period end each pass.
2. The Services card on the Sites page (`/profile/server_manager`): one row
   per product on sale, price read from the product, the date, and the
   *keep going* rows inside the notice window.
3. The second door: `HostedPlanNotice` gains a second action per allowance
   row and the paid-period row (§2.1); the status response (contract C2)
   carries the product on sale and its price; the wizard lines (§2.2).
4. The nudge emails (§2.3), sent by the operator side from the metering
   phase.
5. A placeholder product on dev, at any price, for the gate. The getjoinery
   product script (`apply_services_product.php`, in the shape of
   `apply_hosted_product.php`) and the copy wait for
   `DEFERRED_services_pricing.md`.
6. Docs: `plugins/server_manager/docs/overview.md` (selling the services),
   `plugins/store/docs/product_requirements.md` if the contract text needs
   the second example.
7. Tests: fulfil → date extended from the old date, not today; allowance
   re-set at the next pass; a subscription line's period end followed; a
   cancelled line lapsing on the ladder with no new code; the stale-tenant
   refusal before the charge.

## 5. Verification

1. The placeholder product bought in Stripe test mode from the wizard's link
   on a phase 2 box: the date appears, enrol succeeds without the admin
   page, the banner shows both doors at 80%.
2. The same placeholder as a monthly subscription: the date follows the
   period end; cancel it and watch the date fall into the past and the
   ladder start with no new code.
3. Buy again before the date: it extends from the old date.
4. Edit or delete the tenant between cart and charge: the charge is refused
   with the sentence.

## 6. Out of scope

- The price, the term, the bundle question, tiers, Managed's price, refunds:
  `DEFERRED_services_pricing.md`.
- Provisioning, enrolment and the ladder (phase 2). The switch-over forms
  (phase 2 §9).
