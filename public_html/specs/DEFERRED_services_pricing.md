# Joinery Services pricing — deferred

**Status:** Deferred, 2026-09-17. Not to be decided until the machinery in
`managed_hosting_and_services.md` is built. That spec is written so that
every answer here is a product on getjoinery and nothing in code changes;
this file holds the reasoning so far, the cost basis, and the questions.

**Companion:** `managed_hosting_and_services.md` §14.8 (entitlement as a
date, the plan ladder, the Services card), §14.9 (abuse), §15 (the nudge).

## 1. What is settled and carries into any pricing

- **No free trial.** A free start is an incentive for spammers to create
  accounts. Whatever is sold, a charge stands in front of sending.
- **The two doors, own accounts first.** Every nudge names opening your own
  account (free at the current allowances) before paying getjoinery. Never a
  third product, never a bundle pitch in the nudge itself.
- **Price, term and allowances live on the product.** A change is a product
  edit; a tier is another product; the plan ladder in the fulfilment
  provider carries allowances and, for one-time products, the term.
- **Managed stays at $12.99 a month** (the two-choice decision of
  2026-09-06) unless this spec moves it. Services are included in Managed.
- **The copy says what the year, or the month, buys:** not opening two
  accounts, not pasting keys, not publishing records by hand. At the current
  allowances the customer's own accounts cost nothing — SMTP2GO's free plan
  is 1,000 a month, B2's first 10 GB are free — so this is convenience,
  and the site's subscription promise holds either way.

## 2. How the thinking went (2026-09-17, owner)

1. Two monthly products, Joinery Email $1.99 (1,000 a month) and Joinery
   Backups $1.99 (20 GB), tiers later.
2. Then: a fee under $4.99 "is basically nothing", and a trial in front of
   it makes no sense. Annual was raised: Stripe's fee is about 18% of a
   $1.99 charge and about 4% of a $19.99 one.
3. Then: a one-time charge beats "a forever-subscription" — $39.99 for a year
   of email and backups, renewal worked out at year end.
4. Then: "I didn't consider a VPS in the pricing." A self-hoster already pays
   about $60 a year for the box, so a yearly services fee on top reads as
   $80–90 a year and hurts conversion. Maybe monthly after all.
5. Decision: forget pricing; build the machinery with the stipulation that
   we have no idea how we will price.

A warm-up ramp on new subaccounts was rejected on the way (1,000 a month is
not a spam volume); it is not a pricing lever.

## 3. Cost basis (from the hosted spec §12, prices verified 2026-09-06)

| Line | At the allowances | Typical |
|---|---|---|
| Email, SMTP2GO | 1,000 × $0.00075 = $0.75 | 600 × $0.00075 = $0.45 |
| Backup shelf, B2 | 20 GB × $0.00695 = $0.14 | 2 GB = $0.01 |
| SMTP2GO plan share | Starter $10 covers ten at-cap customers; Professional $75 covers a hundred | — |
| Compute (Managed only) | 1 GB Nanode $5.00 | $5.00 |

Processing: Stripe standard is 2.9% + $0.30, so a monthly line under $5
loses a large share to fees; a yearly line does not.

## 4. Shapes the machinery accepts without change

One-time for a term; monthly or yearly subscription; a bundle; per service;
larger tiers; a trial (against §1); included in Managed. The tenant's
paid-through date is derived from the store's lines in every case
(`managed_hosting_and_services.md` §14.8).

## 5. Open

- **Cadence.** Monthly, yearly, or a one-time term; and whether a
  subscription auto-renews or the customer buys again.
- **Bundle or per service.** One product for both, or one each. At small
  prices one product is simpler; per service lets a customer keep only the
  shelf.
- **Tiers.** Which allowances beyond the first.
- **Managed's price**, and whether Managed should be shown as the sum of
  its parts (box plus services) or as one number.
- **What release does to a subscription line.** Moving a service to the
  customer's own account: cancel the line, or let it run to period end.
- **Refund policy** for early departure and for a domain name taken after
  payment (a person at Stripe today).

## 6. What to build when decided

- The product or products on getjoinery, through a content-pack script in
  the shape of `apply_hosted_product.php` (idempotent, drift reported never
  overwritten), with the Purchase grants picker set to the plan.
- The plan entries in the `joinery_services` ladder, if the allowances are
  not already there.
- The site copy: the Services card's sentences, the wizard step's line, and
  the pricing and install pages (`getjoinery_hosted_tier_copy.md`).
