# Donations Plugin Spec (gifts, recurring giving, receipts and campaigns)

**Purpose:** A donation tool that runs inside a Joinery site. A nonprofit, club, school or congregation takes one-time and monthly gifts on its own pages, into its own Stripe or PayPal account, and the site keeps donor records, sends legally sound receipts and year-end statements, and shows campaign progress. It is sold once at one price: there is no platform fee on gifts, no "tip" to the vendor, no per-seat cost and no tiers.

**Status:** Active — not yet implemented. Open questions are listed in §14.

**Plugin name:** `donations`. The product name is "Joinery Donations".

**Depends on:** the **store** plugin. A donation is a store purchase, so payment, refunds, subscriptions, guest checkout and order history already work. Three small gaps in the store must close first (§4).

**Guiding rule: when in doubt, leave it out.** The research says donors and treasurers want an honest checkout, recurring gifts that don't silently fail, and receipts that satisfy the tax authority. They do not want a feature list.

---

## 1. Research summary

Research date: 2026-10-10. Pricing evidence is weak (see "Evidence quality").

### 1.1 The market

| Product | Cost to the organization | How it makes money | Notes |
|---|---|---|---|
| Zeffy | $0 | A "voluntary contribution" added to each donor's payment; Zeffy absorbs processing | Donorbox and some reviewers say the contribution is pre-set at about 15–17%; Zeffy says it is never defaulted. Not checked against a live checkout. |
| Givebutter | **Official page:** free, or $29 / $79 / $129 a month by contact count | Tips on: 0% platform fee. Tips off: 3% | Cards 2.9% + 30¢, ACH 1.9% + 30¢; instant payout 1.75%. Workflows, texting, custom reports and QuickBooks are on paid plans. |
| Donorbox | Free; Pro about $150 a month | Free plan takes 2.95% (3.95% for events, peer-to-peer and memberships); Pro about 1.75–2% | Aggregator figures. Zapier/API and team seats are Pro. |
| GiveWP (WordPress) | Free core; about $149–349 a year | A 2% platform fee on free-core Stripe (one source) | Recurring donations is a paid add-on; sources conflict on which plan. |
| Bloomerang Fundraising (Qgiv), Neon One, Tithe.ly, Planning Center Giving, Pushpay | About $15–139 a month, quote-only for several | Varies | Low confidence. |
| Every.org | $0 to the organization | Donors pay processing | Payout through Stripe free weekly, or through a partner at 1.5%. |
| Classy, Virtuous, DonorPerfect, Kindful, Funraise, Raisely | Quote-only | | No reliable 2026 prices found; not researched individually. |

Stripe's nonprofit rate, per third-party sources, is 2.2% + 30¢ instead of 2.9% + 30¢, by application, for a 501(c)(3) with at least 80% of volume from tax-deductible donations. It is not retroactive and excludes tickets, memberships and auctions. Because the organization keeps its **own** Stripe account on Joinery, it can apply for this rate directly. Hosted tools often cannot pass it on.

The sticker price of "free" is paid by the donor as a tip, or by the organization as roughly 3%. Joinery's honest alternative is: processing at the organization's own rate, nothing else.

### 1.2 What users value, most-cited first

1. **Price and keeping the money.** Zeffy and Givebutter win on a free sticker price.
2. **Ease of setup.**
3. **Recurring giving that works.** G2 rates Donorbox's recurring feature 9.0 across 870 reviews.
4. **Embeddable forms that match the site.**
5. **Receipts and records handled automatically.**
6. **Fast access to funds.**

### 1.3 What users complain about, and our answer

| Complaint | Our answer |
|---|---|
| Tips are confusing: "not clear exactly how to zero out the tip" (Capterra, Zeffy); "assumed 15% tip added" (G2, Givebutter). | There is no tip. The one optional extra is "cover the processing fee", default off, one honest sentence. |
| A platform fee on top of processing (Donorbox 2.95–3.95%). | No per-gift fee at all. |
| Payout friction: manual approvals, 3–5 days, a 1.75% instant-payout fee; Zeffy holds payouts for fraud review. | Money lands in the organization's own Stripe account on Stripe's schedule. |
| Limited customization of hosted forms. | The form is a page on the site, in the site's theme. |
| Rigid recurring options: no bi-monthly interval, no partial refunds, can't edit payment info. | Monthly and yearly in P1; partial refunds already work in the store; donors update their card from the existing billing portal. |
| GiveWP: donations stuck "pending" from webhook problems; one failed Stripe event marks a subscription failed forever. | Idempotent webhook handling: a later successful payment revives the subscription, and the admin shows when a webhook last arrived (§4, §8). |
| Donor data is split across tools. | Donations are in the same user, event and volunteer database. |

### 1.4 Facts the design leans on

- **Failed payments are the biggest silent loss in recurring giving.** One nonprofit blog puts failed payments and expired cards at 20–40% of recurring cancellations (the author's experience, not a study). Stripe recommends timed retries, dunning emails, pre-expiry warnings and a link for the donor to update their card.
- **Card-testing attacks target donation forms** because they ask for little. Mitigations named by vendors and a first-hand account: CAPTCHA, a minimum amount, Stripe Radar rules (including blocking a null CVC), per-IP rate limits, not exposing a predictable form id. One nonprofit reported about 20,000 attempts, 3,537 of which got through. This is P1, because a free public form is an open target.
- **The fee-cover checkbox works when pre-checked, and does not hurt conversion** — in two *vendor* studies. Bloomerang's randomized test (June 2026): 73.7% covered fees when pre-checked versus 48.8% unchecked, with conversion flat (3.30% vs 3.33%). GiveCampus reports about 51% of donors cover fees, with no significant conversion change. **We still ship it unchecked** (decision in §14), because a pre-checked box is the same "assumed amount" complaint donors raise about tips.
- **US receipts (IRS).** A donor needs a written acknowledgment for any single gift of **$250 or more**, stating whether goods or services were provided and a good-faith value if so. The charity must give a written disclosure when it receives more than **$75** and the donor gets something in return (the $75 test applies to the whole payment). The IRS prescribes no format. A receipt with the legal name, EIN, date, amount, and "no goods or services provided" or the value of what was provided covers it. The current revision of IRS Publication 1771 was not confirmed.
- **Canada (CRA).** An official receipt needs all of: the words "official donation receipt for income tax purposes"; the charity's legal name, address and registration number; a unique serial number; the place of issue; the donor's full name and address; the gift amount and any advantage with its value, and the eligible amount; and an authorized signature. Missing any one invalidates it. Time and services cannot be receipted.
- **UK Gift Aid.** A declaration can be written, oral or electronic, up to four years after the gift. It needs the charity name, the donor's name, a home address (house number and postcode at least), whether it covers one gift or all gifts, and the donor's confirmation. No signature. The charity must keep the declarations. Sources conflict on whether the declaration date is required.
- **PCI.** Card data never touches our servers (Stripe Elements or Checkout), which should put a site in the lightest self-assessment (SAQ A). One scanning vendor reads the January 2025 revision as requiring that the payment page load only scripts from the payment processor. That is one vendor's reading and needs checking against the PCI Security Standards Council before we state it as fact. It points to keeping the donation page free of third-party analytics, chat and testing scripts.
- **State solicitation laws** are the organization's obligation, not the software's. About 40 states require charitable registration, and some treat a bare "donate" button as solicitation; sources disagree on the Charleston Principles and no authoritative list was found. The plugin says "you may need to register; check each state attorney general" and gives no legal advice.

### 1.5 Evidence quality

- **Only Givebutter's pricing page was read from the vendor.** The Donorbox pricing page failed to load. Every other price comes from aggregators and vendor blogs. Re-verify before quoting any of them on a sales page.
- The competing blogs (Donorbox on Zeffy, Zeffy on Donorbox, Bloomerang, GiveCampus, Stripe) are marketing, not neutral evidence.
- **No Reddit evidence was reached.** Review quotes are second-hand through Capterra and G2.
- **Not researched:** donor-advised funds, stock gifts, matching-gift databases, peer-to-peer feature depth, QuickBooks export formats, Apple Pay and Google Pay requirements, donor-privacy law, and Classy, Virtuous, DonorPerfect, Kindful, Funraise, Raisely and Qgiv in any depth.

---

## 2. Scope

### P1 — the first sellable release

- **Donation page** `/donate` (and `/donate/{campaign-slug}`):
  - suggested amounts and a custom amount;
  - one-time, monthly or yearly;
  - donor name, email, and address when the receipt rules need it;
  - "Cover the processing fee" checkbox, **off by default**, with one honest line showing the exact amount;
  - "Give anonymously" (hides the name on any public display);
  - "In honor of / in memory of" with a name;
  - a note to the organization;
  - optional extra questions from a Survey.
  - No account needed.
- **Payment through the store:** Stripe and PayPal as already configured, guest checkout, refunds (full and partial), coupons ignored. A donation is an order.
- **Recurring gifts:** monthly and yearly, with:
  - a donor self-service page to change the card (the store's billing portal), pause, or cancel;
  - failed-payment follow-up written for donors, not for tier members (§4.3);
  - revival of a recurring gift when a later payment succeeds.
- **Receipts:**
  - one receipt per gift, numbered, with the organization's legal name, address and EIN, the amount, the date, and the goods-or-services statement;
  - a benefit-value field for gifts that bought something (§6);
  - an annual giving statement per donor (§6).
- **Donor records:** the donor is the user record. A **Supporters** admin screen lists everyone who has given, with totals, first and last gift, and recurring status.
- **Offline gifts:** an admin form records cash, check and other gifts (date, amount, donor, campaign, optional receipt).
- **Campaigns:** a named appeal with a goal, dates, a page, and a progress thermometer. A site always has a default "General" campaign.
- **Abuse defences:** CAPTCHA on guest gifts, a minimum amount, per-IP rate limiting, and a setup checklist for Stripe Radar (§9).
- **Reports and export:** gifts by date, campaign, donor; CSV export; a year-end statements run.
- **Joinery wiring:** admin-user panel, member dashboard, notifications, mobile apps, Joinery AI (§10).

### P2 — next release, same price

- **Embeddable widget and QR code** for a campaign (an iframe snippet and a downloadable QR).
- **Tribute and memorial cards:** email the honoree or their family that a gift was made in their name (the amount hidden).
- **Peer-to-peer fundraising pages:** a supporter makes a personal page under a campaign and shares it.
- **Matching gifts** (a campaign has a sponsor who matches up to a cap) and **pledges** (a promised gift with reminders).
- **In-kind gifts** (goods, with a donor-supplied value; the organization does not value them on the receipt).
- **Thank-you automation:** a first-gift welcome, and a lapsed-donor reminder.
- **Apple Pay, Google Pay and ACH** (cheap through Stripe Elements; the store's checkout must support the payment methods).
- **Pre-expiry card warning** to recurring donors.
- **Canada (CRA) receipt mode** and **UK Gift Aid declaration capture** (§6.3).
- **QuickBooks-friendly export.**
- **Webhook health panel** (when the last payment event arrived; a red flag after N days of silence on a site that has live subscriptions).

### Deferred (not scheduled)

- Text-to-give (needs SMS carrier compliance).
- Stock and donor-advised-fund gifts (custodian workflows).
- Auctions.
- CRM-style donor scoring and wealth screening.
- State registration tracking (legal territory).

---

## 3. Data model

All tables come from `$field_specifications`. Prefixes were checked as unused on 2026-10-10.

| Class / table | Key columns |
|---|---|
| `Campaign` / `dcm_campaigns` | `dcm_title`, `dcm_slug` (unique), `dcm_description`, `dcm_goal_amount` (nullable), `dcm_start_time`, `dcm_end_time` (nullable), `dcm_suggested_amounts` (text, comma list), `dcm_allow_recurring`, `dcm_fil_file_id` (image, nullable), `dcm_svy_survey_id` (nullable extra questions), `dcm_show_progress`, `dcm_is_default`, `dcm_is_active`, `dcm_delete_time` |
| `Gift` / `dgf_gifts` | `dgf_usr_user_id`, `dgf_dcm_campaign_id`, `dgf_amount` (numeric(10,2); the whole payment, fee-cover included), `dgf_fee_covered_amount` (numeric, default 0), `dgf_currency`, `dgf_gift_date`, `dgf_method` (`online` / `cash` / `check` / `other`), `dgf_ord_order_id` (nullable), `dgf_odi_order_item_id` (nullable), `dgf_stripe_invoice_id` (nullable; unique when set, so a replayed webhook cannot record a second gift), `dgf_is_recurring`, `dgf_is_anonymous`, `dgf_dedication_kind` (`in_honor` / `in_memory` / null), `dgf_dedication_name`, `dgf_note`, `dgf_benefit_value` (numeric, default 0), `dgf_benefit_description`, `dgf_donor_name`, `dgf_donor_address` (text; **snapshots** at the time of the gift, so a later address change does not rewrite old receipts), `dgf_status` (`received` / `refunded` / `partially_refunded`), `dgf_refund_amount`, `dgf_usr_user_id_entered_by` (offline gifts), `dgf_delete_time` |
| `Receipt` / `drc_receipts` | `drc_dgf_gift_id`, `drc_number` (unique, sequential), `drc_issued_time`, `drc_amount`, `drc_eligible_amount` (amount minus `dgf_benefit_value`), `drc_legal_text` (snapshot of the wording used), `drc_void_time`, `drc_sent_time` |

**A gift is one payment.** A monthly donor has one gift row per successful charge, all pointing at the same order item. A one-time donor has one row. An offline gift has no order.

**Idempotence.** `dgf_stripe_invoice_id` has a unique index. The webhook handler that creates a gift for a renewal uses insert-or-ignore on it, so a repeated delivery of the same event never creates a second gift or a second receipt. This answers the GiveWP complaints about duplicate and stuck records.

**Refunds.** A refund through the store updates the gift's status and `dgf_refund_amount`. A refunded gift keeps its receipt, which is **voided** (`drc_void_time`) when refunded in full and **re-issued** with the reduced eligible amount when refunded in part, so the annual statement stays correct.

**Receipt numbers** come from a dedicated sequence per organization. Gaps are possible (sequences move forward only); the receipt records every number it uses. The number format is `{prefix}{year}-{number}`, prefix a setting.

**The donor is a user.** A guest becomes an inactive user matched or created by email (the store's guest checkout already does this). Donors with no password can still reach their giving page through the emailed receipt link, which carries a token; they can set a password later.

**Deletion.** See the deletion system doc.

- Gifts soft-delete, and only an admin can delete one that has no receipt. A gift with a receipt is **voided**, not deleted: the number must stay accounted for.
- A user delete: the gift's `dgf_usr_user_id` is set to the platform's deleted-user value. The snapshotted name and address stay for the organization's tax records; the donation settings page has an explicit "erase donor identity" action (superadmin) for privacy requests, and the page says what the organization remains legally required to keep.
- Campaigns soft-delete. Gifts keep their link to the deleted campaign.

---

## 4. Store changes this plugin needs first

These were found by reading the code on 2026-10-10. Each should be checked against the running system before work starts.

### 4.1 A recurring gift needs a chosen amount

A product version's price type is *one* of: `day`, `week`, `month`, `year` (a subscription at the version's price), or `user` (the buyer types the amount). There is no version that is both. `StripeHelper::get_or_create_price()` already creates a Stripe price for any amount on demand, so the Stripe side can do it. The store needs one change: let a version recur at an interval *and* take its amount from the buyer. Proposed: a version of price type `user` may carry `prv_user_price_interval` (null / `month` / `year`); `ProductVersion::is_subscription()` then answers from it. The cart, charge and reconciler paths that call `is_subscription()` then need no other change, but **the charge path and the PayPal subscription path were not traced for this spec** and must be checked first.

### 4.2 A renewal needs to announce itself

The Stripe webhook's `invoice.payment_succeeded` handler sets the order item active and dispatches a signal **only when a failed payment recovers** (`subscription.payment_recovered`). An ordinary renewal dispatches nothing, so nothing outside the store can hear it. The plugin needs a signal for every successful renewal. Add `subscription.payment_succeeded`, with `order_item_id`, `user_id`, `provider`, `provider_subscription_id`, `amount`, `currency`, `invoice_id` and `paid_time`, dispatched from both the Stripe handler and the PayPal sync. The Donations subscriber records a gift from it. (A signal handler is inline in the webhook request, so it does a bounded local insert only; the receipt email is queued, per the signal-bus cost budget.)

### 4.3 The failed-payment email talks about tiers

The Stripe webhook's failure email looks up the user's subscription tier and falls back to the words "your current plan". A donor with a failed monthly gift would be told their plan has a problem. Donations needs wording of its own. Smallest fix: the store's failure email asks the product's fulfillment provider (if it has one) for the sentence and link, and falls back to today's text. The `donation` provider answers: "Your monthly gift of {amount} to {organization} could not be processed. Update your card here: {link}", where the link opens the billing portal.

---

## 5. How a donation flows

1. The donor opens `/donate` (a view in the plugin, rendered by FormWriter in the active theme) and picks the amount, interval, and extras.
2. The page's JS adds a cart line through the store's cart action: the plugin's **donation product** (named by the `donations_product_id` setting, created on activation), the version matching the interval (once, monthly, yearly), with the amount, campaign, anonymity, dedication, note and fee-cover amount in the line's form data. The fee-cover amount is added into the line's price, not a second line, so the order total is the gift (§3).
3. The store's normal checkout runs (guest allowed, CAPTCHA enforced, §9).
4. On success, the plugin's `DonationFulfillment` (a `FulfillmentProvider` registered with the store, key `donation`) reads the line's form data and writes the `dgf_gifts` row, issues the receipt, and queues the receipt email. It returns a label for the order summary.
5. For a recurring gift, each later renewal arrives as `subscription.payment_succeeded` (§4.2) and the subscriber does the same: new gift row, new receipt, new email.
6. Refunds and cancellations flow back the other way (§3).

**Fee-cover amount.** The plugin shows the donor exactly what they are adding, calculated so the organization nets the chosen gift after processing:

`fee = (amount + fixed) / (1 − rate) − amount`

with `rate` and `fixed` taken from settings `donations_fee_percent` (default 2.9) and `donations_fee_fixed` (default 0.30). An organization on Stripe's nonprofit rate sets 2.2. The page prints the sentence: "Add $0.38 so that all $10.00 reaches {organization}". The exact figure is shown; no percentage default is ever pre-applied.

**The amount is never trusted from the browser.** The price is re-derived server-side from the form data when the cart is charged (the store already does this for coupons), and a minimum amount (`donations_min_amount`, default 3.00) is enforced there.

---

## 6. Receipts and statements

### 6.1 Legal settings

A settings group "Donations" holds the text that goes on every receipt:

- `donations_org_legal_name`, `donations_org_address`, `donations_org_tax_id` (EIN or registration number), `donations_org_country` (`US` for P1).
- `donations_receipt_statement` — default: "No goods or services were provided in exchange for this contribution." Shown when `dgf_benefit_value` is zero.
- `donations_receipt_benefit_statement` — default: "In exchange for this contribution, you received {benefit_description}, with an estimated value of {benefit_value}. The deductible amount is {eligible_amount}." Shown when a benefit value is set.
- `donations_receipt_signer_name` and title (printed on the receipt; CRA mode needs it).
- `donations_receipt_prefix`.

A receipt cannot be issued until the legal name and tax id are set, and the donation page shows a red admin banner until then. The page says: "Tax rules differ by country; check with your accountant."

### 6.2 What the receipt says (US)

Organization legal name, address and tax id; donor name and address; the gift date and amount; the receipt number; the goods-or-services statement above. It is an email and a page at `/profile/donations/receipt?id=…`, printable. Every online gift is under or over $250; **we issue the same receipt for all gifts**, so the $250 rule is satisfied without a threshold to maintain.

A gift with a benefit (a dinner ticket sold through the store as part of a fundraiser) gets its benefit value from the product: the donation product's cousin, an *event product*, can carry a `benefit_value` field so a dinner ticket receipts the right deductible amount. For P1, benefit values are entered on offline gifts and on admin-created gifts; connecting event tickets is P2.

### 6.3 Annual statement

`/profile/donations/statement?year=2026` shows all of a donor's gifts for the calendar year, each with its receipt number, and a total of the eligible amount. Gifts refunded in full are omitted; partial refunds use the reduced amount. An admin button "Email year-end statements" queues one message per donor (through the platform's email queue, not inline).

### 6.4 Other countries (P2)

- **Canada:** an `official_ca` mode that prints the CRA's required wording, serial number, place of issue, eligible amount and signature block, refuses to issue a receipt for a gift marked in-kind-service, and requires the donor's full address.
- **UK Gift Aid:** a checkbox "I am a UK taxpayer and want Gift Aid" with the declaration text, home address, and a stored declaration (name, address, date, wording, scope: this gift or all gifts). A Gift Aid claim export is a later step. The legal question of what to keep, and for how long, needs advice from a UK adviser.

---

## 7. Campaigns and public display

- `/donate` shows the default campaign. `/donate/{slug}` shows another, with its description, image, goal and progress.
- **Progress** is the sum of `dgf_amount` for non-refunded gifts of the campaign (net of partial refunds), computed with one aggregate query. It is not cached; if a large site needs it, a materialized total is a later optimization.
- **Public supporter list.** Off by default (`donations_show_supporters`). When on, a campaign page shows first name and last initial of donors who are not anonymous, never amounts unless `donations_show_supporter_amounts`.
- **Goal reached.** The thermometer simply fills past 100%. Nothing else changes (donors can keep giving).

---

## 8. Recurring gifts in operation

- **Self-service.** `/profile/donations` lists a donor's gifts and their active recurring gifts with **Update card**, **Pause** (a Stripe pause of collection for 1–6 months), **Change amount** (P2) and **Cancel**. Update card and cancel reuse the store's billing portal and subscription cancel.
- **Failed payments.** The store already marks the order item past-due on `invoice.payment_failed` and Stripe retries on its schedule. The donor gets the donation-specific email from §4.3 with the card-update link, at most once per failure (the store's existing dedup). If the subscription ends after retries, the donor gets a "your monthly gift has ended" message and the organization gets a notification.
- **Revival.** A later successful payment on a past-due subscription sets it active again and records the gift. A single failed event never puts a subscription into a permanently-failed state.
- **Organization view.** The Supporters screen shows each recurring donor's state (active, past due, cancelled) and the next charge date, and a "recurring at risk" filter for past-due ones.

---

## 9. Abuse defences

A free public form that takes small amounts invites card-testing. P1 ships:

1. **CAPTCHA** on guest gifts, using the platform's existing hCaptcha/reCAPTCHA support in FormWriter (a keyed setting). Logged-in members skip it.
2. **A minimum amount** (`donations_min_amount`, default 3.00), enforced server-side.
3. **Per-IP rate limit** on the cart/charge action for the donation product (default 5 attempts an hour per address, IPv4 and IPv6 handled as addresses, not text), and a short lockout after repeated card declines from one address.
4. **No predictable form id.** The page needs none; the donation product id is not in the page source in a way that lets a script post directly.
5. **A setup checklist** on the plugin's settings page: turn on Stripe Radar, add a rule blocking a missing CVC, set the Stripe dashboard alert for spikes in declines, and an explanation of what a card-testing attack looks like in the order list.
6. **A velocity alarm.** If more than N declined donation attempts occur in an hour (default 25), the plugin raises a site notification to admins and (setting, default on) turns on CAPTCHA for logged-in members too until an admin clears it.

**PCI scope.** The donation page uses the store's checkout, which keeps card data off our servers. The page must load **no third-party analytics, chat or testing scripts**; the plugin adds a test that fails if one is added to the donation view's script inventory (§13). The CAPTCHA script is the one allowed exception and sits on a separate step before the payment step.

---

## 10. Joinery integration

| System | How Donations uses it |
|---|---|
| **Store** | A donation is an order. The plugin depends on the store (`"depends": {"store": ">=1.5.12"}`), registers `DonationFulfillment` with `FulfillmentRegistry`, and creates its donation product on activation. |
| **API** | Every action is a logic action with a `_logic_descriptor()` under `/api/v1/action/donations/{action}`: `donations_options` (campaign, suggested amounts, fee preview), `donations_gift_save` (offline), `donations_receipt_resend`, `donations_receipt_void`, `donations_stats`. The public page's cart and charge calls use the store's existing guest-capable actions. No `/ajax/` endpoints. |
| **Signals / Notify** | `donations.gift_received` (admins, opt-in, default off: a busy site would drown), `donations.recurring_failed_final`, `donations.goal_reached`. Donors get receipt and statement emails through the email queue, with their own templates (from the platform's template system) so the organization can edit the wording. |
| **Users and admin** | The Supporters screen. An admin-user panel "Giving" on each user page (gifts, receipts, recurring state, a button to record an offline gift). If Volunteers is active, the panel also shows their volunteer hours. |
| **Member dashboard** | A `ProfileDashboardRegistry` section "Your giving" (this year's total, next recurring charge). |
| **Mobile apps** | A `profileMenu` entry (`/profile/donations`) shows the page as a web view. **Apple and Google rules on donations in apps are different for nonprofits and for everyone else, and were not researched**; until they are, the donate button inside the native app opens the system browser, not an in-app payment. |
| **Groups / tiers** | A gift can add the donor to a core Group (`donations_donor_group_id`, optional), so a site can gate content to "donors", or offer a tier to recurring donors. |
| **Joinery AI** | `Gift` and `Campaign` set `$ai_readable`. `dgf_note` and `dgf_dedication_name` go in `$ai_untrusted_fields`. No AI write access to gifts or receipts (they are financial records). |
| **Analytics** | The donation page sends the standard visitor events so campaign conversion (visit → gift) shows in the existing attribution reports. |
| **Existing report** | `admin_yearly_report_donations` (per-user totals across all products) is left alone; the new Supporters screen reports only real gifts. |

---

## 11. Pages

- `/donate` and `/donate/{slug}` (one `serve.php` placeholder route for the slug).
- `/profile/donations`, `/profile/donations/receipt`, `/profile/donations/statement`.
- `/plugins/donations/admin/admin_gifts` (list, filters, record offline gift, void), `admin_campaigns`, `admin_campaign_edit`, `admin_supporters`, `admin_donation_reports`, and the settings group.

**Forms.** Every form uses FormWriter. The donation page uses `visibility_rules` for the dedication fields and the recurring options; no hand-written toggle code. Void and resend are POST buttons.

**Front end.** Vanilla JS and CSS with the `.jy-ui` kit. Amount buttons are real buttons with a custom-amount field; the fee-cover line updates as the amount changes and always shows its dollar figure.

---

## 12. Packaging and sale

- `plugin.json`: `"license": "Joinery-Commercial"`, `"requires_entitlement": true`, `"status": "beta"` until the P1 checklist passes. `"depends": {"store": ">=1.5.12"}`.
- Sold as one store product with perpetual updates.
- **One-line pitch:** *Take donations into your own Stripe account with no platform fee and no tip, send proper receipts automatically, and keep your donors in the same place as your members.*
- **Marketing claims must be checkable.** The sales page may compare Joinery to hosted tools' *published* fees only after each figure is re-read from the vendor's own page (§1.5).

---

## 13. Testing

Each item below is a test with an `@joinery-test` header.

**`plugins/donations/tests/donations_gift_test.php`** (test-db):

- A completed one-time order writes one gift, one receipt, one email in the queue.
- A replayed renewal event (same invoice id) writes nothing new.
- A renewal after a failure recovers the subscription and writes a gift.
- A full refund voids the receipt; a partial refund re-issues with the reduced eligible amount; the annual total follows.
- A gift with a benefit value prints the eligible amount.
- An offline gift without an order records and receipts correctly.
- The receipt number sequence never repeats; a void keeps its number.

**`plugins/donations/tests/donations_pricing_test.php`** (test-db):

- The server rejects an amount below the minimum and ignores a browser-supplied fee-cover amount that doesn't match its own calculation.
- The fee-cover amount makes the organization net the chosen gift at the configured rate and fixed fee, rounded to the cent.
- Monthly and yearly versions take the chosen amount and create the right Stripe price (against Stripe test mode in the live tier).

**`plugins/donations/tests/donations_abuse_test.php`** (test-db):

- Guest gifts require a valid CAPTCHA; members don't.
- The per-IP limit applies to IPv4 and IPv6 addresses, compared as addresses.
- The velocity alarm raises one notification and turns CAPTCHA on for members.

**`plugins/donations/tests/donations_page_test.php`** (safe tier): the donation view loads no script from outside the allowed list.

**`plugins/donations/tests/donations_access_test.php`** (test-db): a donor sees only their own gifts and receipts (including via a receipt link token); a stranger doesn't; public campaign pages show only non-anonymous first names and last initials when enabled.

**Store tests belong with the store changes (§4):** a user-priced recurring version charges the typed amount; a renewal dispatches `subscription.payment_succeeded` once per invoice from Stripe and PayPal; the failure email asks the product's provider for its wording.

**Live walk (Stripe test mode, dev):**

- Give once as a guest, then monthly as a member.
- Fail a card with Stripe's decline test card and confirm the donor email and the card-update link.
- Refund part of a gift and see the statement change.
- Record a cheque as an admin.
- Do all of it on a phone width.

---

## 14. Decisions and open questions

- **D1. No tip, no platform fee, and the fee-cover box ships off.** The vendor studies say pre-checking raises coverage from about 49% to about 74% with no change in conversion. That is real money for an organization. But a pre-checked amount is the very complaint donors make about tips, the studies are by vendors who profit from it, and it can be a per-site setting later. Draft: off, setting available (`donations_fee_cover_default`, default off). *Recommend: ship off; let the organization choose.*
- **Q1. Should the plugin ship P1 with Stripe recurring only, or Stripe and PayPal?** The store supports both for subscriptions, but the PayPal path wasn't traced for chosen-amount recurring (§4.1). *Option A: both, after tracing PayPal. Catch: the PayPal sync is its own code path and delays release. Option B: recurring on Stripe only at first; PayPal one-time works from day one. Catch: sites that only use PayPal can't take monthly gifts. Recommend B unless the PayPal trace is cheap.*
- **Q2. Do receipts go out for every recurring charge?** Some organizations send one annual summary instead of twelve emails. *Recommend: a setting `donations_email_recurring_receipts`, default on, with the annual statement always available.*
- **Q3. Who owns the legal wording?** The spec ships US defaults and says to check with an accountant. Whether the sales page should carry any claim like "IRS-compliant receipts" needs counsel; the draft makes no such claim.
- **Q4. Apple and Google in-app rules for donations** were not researched (§10). The draft opens the system browser.

---

## Sources

- Givebutter pricing (official, fetched): https://givebutter.com/pricing
- Givebutter on Stripe for nonprofits (vendor): https://givebutter.com/blog/stripe-for-nonprofits
- Donorbox on Zeffy (vendor): https://donorbox.org/nonprofit-blog/zeffy-reviews
- Zeffy on how it makes money, on Donorbox, and reviews (vendor): https://www.zeffy.com/blog/how-does-zeffy-make-money, https://www.zeffy.com/blog/donorbox-review, https://www.zeffy.com/blog/zeffy-reviews
- Capterra, Zeffy / Donorbox / Givebutter: https://www.capterra.com/p/220131/Zeffy/reviews/, https://www.capterra.com/p/156860/Donorbox/, https://capterra.com/p/172048/Givebutter/reviews/
- G2, Donorbox and Givebutter: https://g2.com/products/donorbox/reviews, https://g2.com/products/givebutter/reviews
- Donorbox on Givebutter (vendor): https://donorbox.org/nonprofit-blog/givebutter-reviews
- 4aGoodCause, Donorbox vs Zeffy: https://4agoodcause.com/donorbox-vs-zeffy/
- Nonprofit Point, best platforms: https://nonprofitpoint.com/best-donation-platforms-for-nonprofits/
- Toolradar pricing, Donorbox and Qgiv: https://toolradar.com/tools/donorbox/pricing, https://toolradar.com/tools/qgiv/pricing
- Costbench: https://costbench.com/software/nonprofit-crm/givebutter/, https://costbench.com/compare/planning-center-vs-tithely/
- GiveWP pricing and a review: https://givewp.com/pricing/, https://wpmanageninja.com/givewp-review/
- GiveWP feedback board, failed Stripe event: https://feedback.givewp.com/bug-reports/p/stripe-failed-event-does-not-imply-failed-forever
- Every.org pricing: https://www.every.org/pricing
- PayPal Giving Fund fees: https://pep.paypal.com/cshelp/article/are-there-any-fees-charged-for-using-paypal-giving-fund--help207
- Stripe nonprofit rate (third-party): https://help.goharness.com/en/articles/3159903-nonprofit-discount-for-donation-processing-on-stripe-2-2-0-30, https://checkoutpage.com/blog/stripe-for-nonprofits
- IRS substantiation and disclosure: https://www.irs.gov/node/551, https://www.irs.gov/charities-non-profits/private-foundations/private-foundations-substantiation-and-disclosure-of-charitable-contributions
- Canada, official donation receipts: https://www.charitylawgroup.ca/charity-law-questions/how-can-canadian-charities-issue-official-donation-receipts, https://www.millerthomson.com/en/insights/social-impact-newsletter/cardinal-rules-donation-receipts/
- UK Gift Aid declarations: https://www.charitytaxgroup.org.uk/tax/donations/gift-aid/declarations/
- Card-testing: https://donorbox.org/nonprofit-blog/how-to-overcome-credit-card-testing-attacks (vendor), https://help-donorfy.theaccessgroup.com/en/articles/14529783-protect-your-donation-pages-from-card-testing-fraud (vendor), https://www.freecodecamp.org/news/stopping-credit-card-fraud-and-saving-our-nonprofit/
- Recurring-donor churn: https://aafrc.org/why-monthly-donors-cancel-and-how-to-reduce-churn/, https://stripe.com/resources/more/how-to-handle-recurring-donations-in-nonprofit-payments (vendor)
- Fee-cover studies (vendor): https://bloomerang.com/resources/fundraising-innovations/giftassist-opt-in-experiment-detail-page, https://support.givecampus.com/hc/en-us/articles/37844967602839-Donor-Covered-Payment-Processing-Fees
- PCI (vendor reading, unconfirmed): https://cside.com/blog/can-you-use-stripe-for-pci-dss
- State solicitation: https://www.venable.com/insights/publications/2014/01/state-registration-requirements-for-nonprofit-fund, https://charitycompliancesolutions.com/charleston-principles
- Other receipts and fees: https://pushpay.com/blog/text-to-give/ (vendor)
