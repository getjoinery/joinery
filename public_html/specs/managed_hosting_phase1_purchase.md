# Managed hosting, phase 1 — configure in Server Manager, pay once, activate on payment

**Status:** Draft, 2026-09-19. Phase 1 of `managed_hosting_and_services.md`
(the umbrella; read its phase map and contract C1 first). Consolidates the
purchase side of `implemented/hosted_trial_provisioning.md` (the Managed
product, built 2026-09-06), the checkout intake half of
`implemented/managed_domain_registration.md`, and the never-shipped
checkout-time configuration question in
`DEFERRED_automatic_install_mail_topology.md`. The provisioning pipeline,
the mail leg, the backup leg, the trial row, the banner and the subscription
signals are built and unchanged; this phase moves **where the buyer
configures the site** and **what the cart carries**.

**Owner decisions, 2026-09-17:** the buyer configures first, then pays;
payment activates (§2). The domain is stored at configure time and
registered only after payment.

**Depends on:** nothing unbuilt. **Feeds:** phase 3, through contract C1.

**Companions:** `implemented/hosted_trial_provisioning.md`,
`implemented/managed_domain_registration.md`,
`implemented/admin_cloud_instance_birth.md` (the precedent: a provision row
that exists before an order item), `keyless_provisioning.md`,
`managed_customer_departure.md`, `getjoinery_hosted_tier_copy.md` (its
buttons land on §4.1), `getjoinery_purchase_path_verification.md` (its
hosting phase is replaced by §11 here).

---

## 1. What this does for the buyer

Someone who wants us to run Joinery for them signs in to getjoinery, fills in
one page describing the site they want — the domain (theirs, or one we
register for them), the site's name, where it should live — sees the whole
price, and pays once. Minutes later their site exists at that domain, mail
sends, and the Sites page shows them their admin password one time.

Nothing about the site is asked in the cart. The cart carries one line for
Managed hosting and, if we are registering a domain, one line for the domain
year. Every question about the site lives on one page in Server Manager, and
adding a question later is a column and a form field on that page, never a
change to the store.

If they walk away before paying, nothing has been bought, nothing has been
created, and the draft waits for them on the Sites page for two weeks.

## 2. The order of events, and why

Two orders were possible. **Charge first, then configure** keeps money ahead
of any spend and needs no draft, but the buyer pays before they know whether
their domain is available or what the form asks, the billing clock starts
before the site exists, and a paid buyer who never finishes configuring is a
support case we made. **Configure first, then pay** validates every answer
while walking away is free, starts billing at delivery, and costs nothing
for an abandoned draft; its price is a draft state with an expiry and a rule
that a draft holds nothing.

The owner chose configure-first. With no trial and no setup fee the first
charge is the buyer's whole decision, and they make it after seeing exactly
what they are buying.

**A draft holds nothing.** It reserves no domain, no slug, no instance, no
subaccount. The domain is checked for availability at configure time and
bought only after payment, by the existing registration phase, which
already refuses to buy from anything but a paid row. The gap between the
check and the purchase is seconds to minutes; when it is not, §7 says what
happens.

## 3. Doctrine (inherited, restated once)

1. Whose account the server is born on is the **product's** decision
   (`cvp_hosting_mode`, from the fulfilment reference), never the buyer's.
2. Nothing that costs money happens before payment. Registration, instance
   birth, the SMTP2GO subaccount and the shelf all start from a paid row.
3. A step that spends is guarded by **status**, not a timestamp.
4. The store never learns what a line is for. It carries a product, a price
   and an opaque answer; Server Manager interprets the answer.
5. Never a key of ours on a machine we create.

## 4. Buyer journey (end state)

### 4.1 Configure — `/profile/server_manager/configure`

Signed-in only. The Managed product page on getjoinery and every "Managed"
button in the site copy land here (a visitor who is not signed in registers
or signs in first, as any profile page does). The page is one FormWriter form
that creates or edits a **draft provision** — a `cvp_customer_cloud_provisions`
row at status `draft` with `cvp_origin = buyer`.

What it asks, in order:

1. **Your domain.** Two choices, `cvp_domain_source`:
   - *Register one for me* — the name, with the live availability check and
     price the product page runs today (`server_manager/domain_check`), and
     the registrant contact block (name, address, phone with country code —
     the same fields and the same refusals as today's intake). The quote is
     frozen on the row (`cvp_domain_quote`, `cvp_domain_quote_time`); the
     registrant block is sealed on the row (`cvp_registrant_sealed`).
   - *I own one* — the name only. The welcome email carries the A record
     instruction as it does today.
2. **Site name.** The display name (`cvp_sitename`), defaulting to the
   domain's second-level label.
3. **Region.** A choice from the operator's list
   (`server_manager_hosted_regions`, default the one region in
   `server_manager_customer_cloud_region`). One region in the list means the
   field does not render.
4. **Site admin email.** Defaults to the account email; becomes
   `cvp_buyer_email`, the site's admin login.

Not asked, deliberately: instance type (one tier, from the setting), mail
topology (Managed is single-server, D2 of the hosted spec), install mode
(`fresh`; bringing an existing site in is `managed_customer_departure.md`'s
mirror image and is not in this spec), and the mail and backup services —
a Managed site always uses ours (the built hosted legs), that is what
Managed means.

**Adding a question later** is: a column in `$field_specifications`, a field
on this form, a line in the summary. Nothing in the store changes. That is
the property the checkout could not give.

The page shows the price as a sentence, read from the product (today's
Managed price is $12.99 a month, from the two-choice decision; `DEFERRED_services_pricing.md` may move
it): *$12.99 a month from today* plus,
when registering, *and $X once for the domain year*. The submit button is
**Continue to payment**.

### 4.2 Continue to payment

Continue is two steps, so that the cart is entered through the store's own
add-to-cart path and nothing is faked:

1. **Save and continue** (the configure form's submit) validates the draft
   in full (domain shape and TLD gate, availability re-checked and the quote
   refreshed, registrant block complete, region in the list, slug not held
   by any live provision — the slug check is advisory here and binding at
   activation), then flips the draft to `pending_payment`, which freezes it
   (§5.2), and renders the summary with step 2.
2. **Continue to payment** is a single-button form (the one kind of form
   that may be hand-rolled; hidden inputs and a submit) whose action is the
   Managed product's URL. It posts exactly what the product page's own form
   posts — `product_version` (the product's single active version id, as
   `output_product_form()` emits it) — plus `managed_site=<draft id>`. The
   store's `product_logic` then runs as for any product-page submit:
   `validate_form()`, the requirement's `validate()` and `process()`, the
   companion domain-year line from the frozen quote (§6), the cart, and the
   redirect to the cart.

The Managed product is found by `MultiProduct(['fulfillment_provider' =>
'customer_cloud', 'fulfillment_ref' => 1])`, active, exactly one expected;
if none is active the page says Managed is not on sale and renders no
step 2. The same button appears on the `pending_payment` card of the Sites
page.

### 4.3 Checkout — one payment

The cart shows *Managed hosting — $12.99/month* and, when registering,
*Domain registration (1 year) — example.com — $X*. Stripe Checkout in
subscription mode, as today; PayPal is unavailable for this cart shape, as
today. No question is asked on the product page, in the cart or at checkout.

### 4.4 Activation

Payment fulfils the order item. Fulfilment finds the draft by the answer on
the line, checks it is still `pending_payment` and owned by the buyer, and
**activates** it: status `ready`, `cvp_external_order_item_id` stamped, mail
state `pending`, and — when registering — the `rdm_registered_domains` row
created from the draft's sealed registrant and frozen quote, exactly the row
today's `post_purchase()` creates. From here the pipeline is the one that
exists: instance born on the operator token, install with the sealed admin
password, domain registered and wired, mail leg, welcome email, trial row
`subscribed`, banners.

### 4.5 Sites page — `/profile/server_manager`

Already the buyer's page. It gains the pre-payment states:

| State | Card shows | Actions |
|---|---|---|
| `draft` | "Not finished" and the summary | Continue, Edit, Delete |
| `pending_payment` | "In your cart" and the summary | Finish payment (the cart), Edit (§5.2), Delete |
| `ready` onward | as today | as today |

A domain leg that failed after payment shows on the same card (§7). Phase 3
adds a Services card to this page for self-hosted sites; nothing here
depends on it.

## 5. The draft

### 5.1 Shape

`cvp_customer_cloud_provisions` gains:

| Column | Type | Meaning |
|---|---|---|
| `cvp_origin` | existing; allowed values gain `buyer` | the buyer created the row themselves |
| `cvp_status` | existing; gains `draft`, `pending_payment` before `ready` | §5.2 |
| `cvp_domain_source` | varchar(10) `own` \| `register` | whether we register the name |
| `cvp_domain_quote` | numeric(10,2) | the frozen domain-year price, USD, when registering |
| `cvp_domain_quote_time` | timestamp(6) | when it was frozen |
| `cvp_registrant_sealed` | text | the registrant block, sealed, when registering |

`cvp_external_order_item_id` stays nullable and unique; it is null until
activation. `validate_row()` treats `draft` and `pending_payment` as the
admin-origin row is treated: no order item required. The hosting mode is
stamped at activation from the product's fulfilment reference, so a draft
carries none until then and the same page serves a customer-cloud product if
one is ever sold again (the Connect step returns after activation, as the
pipeline already does for that mode).

### 5.2 States and the lock

```
draft ──Continue──▶ pending_payment ──payment──▶ ready ─▶ (existing pipeline)
  ▲                       │
  └────────Edit───────────┘
```

- **`draft`** is editable. It expires: the umbrella task's new draft sweep
  soft-deletes any `draft` older than `server_manager_draft_days` (default
  14) with no order item.
- **`pending_payment`** is frozen. The cart line was built from it, so the
  row must not move under the line. The sweep returns a `pending_payment`
  row older than the same number of days to `draft` (its quote is stale).
- **Edit** on a `pending_payment` row returns it to `draft`. The cart line,
  if still there, is now stale, and the charge refuses it (§6.3). The page
  says so before the buyer confirms: *this removes the site from your cart;
  you will continue to payment again when you are done.*
- **Delete** soft-deletes a `draft` or `pending_payment` row. A row at
  `ready` or later is not deletable by the buyer; it is a paid site.

A draft never reserves anything. Two buyers may hold drafts naming the same
domain; the second to pay finds the name gone at registration (§7) or the
slug held at activation (§6.4).

## 6. The cart coupling

### 6.1 One requirement, one answer

`CustomerCloudFulfillment::extraRequirements()` returns a new
`ManagedSiteRequirement` (Server Manager) instead of the domain
`QuestionRequirement`. It is the whole coupling:

- **On the product page** it renders no fields. It renders the buyer's
  `pending_payment` draft as a one-line summary with a hidden id when one
  exists, and otherwise a single button, *Set up your site*, to §4.1. The
  product page is reachable, but the path through it is the configure page.
- **`validate()`** refuses add-to-cart unless the id names a
  `pending_payment` draft owned by the current user.
- **`process()`** stores `managed_site => ['question' => 'Site',
  'answer' => <draft id>]` (the question/answer shape the order item needs)
  and, when registering, `managed_domain_price_line => <frozen quote>` and
  `managed_domain => ['question' => 'Registered domain', 'answer' =>
  <domain>]` — the same keys the registration guard reads today, so
  `unpaid_reason()` and `claimed_registrations()` work unchanged.
- **`extra_cart_lines()`** returns the domain-year line from the frozen quote
  through the existing `prv_price_type = 'user'` product, deterministic for
  identical form data, as the contract requires.

The domain Question, the `server_manager_provisioning_domain_question_id`
setting and the product-page domain intake in `ManagedDomainRequirement` are
retired from the customer-cloud path. `PollHostingOrders` keeps reading the
Question for shared-host orders from a remote store; that path is untouched.
The registrar quote, the registrant validation and the sealing move from
`ManagedDomainRequirement` into a shared `ManagedDomainIntake` helper that
the configure page and the requirement both call, so one gate decides what a
domain and a registrant may be.

### 6.2 Fulfilment activates

`CustomerCloudFulfillment::fulfill()` → `create_provision()` becomes
`activate_draft()`: read the draft id from the line, load it, require
`pending_payment` and the buyer's ownership, then the transition in §4.4.
Dedup is on `cvp_external_order_item_id` as today. Any refusal is logged and
alerted with the order item id; the order is paid, so a refusal here is an
operator's task, never silent.

### 6.3 The pre-charge refusal

`checkAvailability()` — the interface's existing seam, asked before the
charge in `cart_charge_logic` — returns a sentence when the line's draft is
not `pending_payment` or not the buyer's: *You changed your site setup
after adding it to the cart. Remove it from the cart and continue to
payment again.* Today it always returns null; this is its first use.

**One store change makes it possible.** The interface today is
`checkAvailability(Product $product, int $ref, int $quantity)`: it cannot
see the line, so it cannot know which draft. The call site already holds
the line's form data (`$data`, the third element of the cart item). Add a
fourth parameter with a default, `array $data = []`, to the interface in
`FulfillmentRegistry.php` and pass `$data` at the call site. The existing
event-registration provider ignores it and is unchanged. This is the
general fix: any provider whose availability depends on what the buyer
answered gets the answers.

### 6.4 Slug and domain held

At activation, a slug or domain already held by a live provision (any status
from `ready` on, not deleted) fails the activation closed with an alert. The
Continue check makes this rare; it cannot make it impossible, and a paid
order needs a person.

## 7. When the domain is gone after payment

The registration phase already handles this: it asks the registrar whether
we hold the name (the crash-recovery question), and if we do not and it is
unavailable, the row fails with an alert saying nothing was charged by us
for it. What is added is the buyer's side:

- The Sites page card shows *The name example.com was taken before we could
  register it. Choose another name.* with a field and the availability
  check.
- Submitting an alternate updates the failed row's domain and returns it to
  `pending`. The next tick tries to register it under the existing paid-line
  guard: the paid amount covers the new name, or the row fails again and the
  alert says the price is higher. The provision's own domain and slug follow
  the alternate before the box is installed; after the box exists the
  operator is alerted instead and handles the rename by hand (this is the
  seconds-to-minutes window closing badly, and it is rare enough to stay a
  person's job).

Refund of a domain line the buyer abandons stays a person at Stripe.

## 8. What retires

- The domain `QuestionRequirement` injection for customer-cloud products
  (`CustomerCloudFulfillment::extraRequirements()` no longer returns it).
  The setting that names the Question **stays**: `PollHostingOrders` still
  reads it for shared-host orders placed on a remote store, and that path
  is untouched. Provisioning Setup's "domain Question" card stays for the
  same reason, reworded to say it serves shared-host products only; the
  customer-cloud product card says the site is configured on the configure
  page.
- `ManagedDomainRequirement`'s product-page fields and `post_purchase()`
  intake. The class is deleted once the helper carries its logic; the
  "Managed domain" tick under *Info to collect before purchase* goes with it.
- The `pri_` attachment of the managed-domain requirement to the Managed
  product on getjoinery (the product row script `apply_hosted_product.php`
  gains a version that removes it).
- Three readers of the domain out of order item requirements
  (`create_provision()`, the managed-domain `post_purchase()`, and the
  Provisioning Setup badge) — replaced by the draft.

## 9. Settings

| Setting | Default | Meaning |
|---|---|---|
| `server_manager_draft_days` | 14 | drafts older than this are swept; frozen drafts return to draft |
| `server_manager_hosted_regions` | the customer-cloud region | the regions a buyer may choose, space-separated Linode ids |
| `server_manager_namecheap_promotion_code` | — | secret; the registrar coupon passed on the quote and the registration (item 2a) |

`server_manager_provisioning_domain_question_id` stays in `plugin.json`
for the shared-host poll (§8). The hosted, domains and customer-cloud
groups are the existing ones.

## 10. Build order

0. Store: the `checkAvailability()` fourth parameter and the call site
   (§6.3). One small commit on its own.
1. Data class: the columns in §5.1, `validate_row()` for the two pre-payment
   states, `DISMISSIBLE_STATUSES` unchanged (a draft is deleted, not
   dismissed).
2. `ManagedDomainIntake`: quote, validate, seal — lifted from
   `ManagedDomainRequirement`; the configure page and the requirement call it.
2a. **The registrar coupon.** One sealed setting,
   `server_manager_namecheap_promotion_code` (group `domains`, secret), read
   in two places in `NamecheapRegistrar`: the pricing call passes it as
   `PromotionCode` and the quote reads `CouponPrice` when present, falling
   back to `YourPrice`; the create call passes the same `PromotionCode`.
   Both or neither — the paid-line guard compares what the buyer paid with
   what the registrar charges, so a coupon applied on one side only breaks
   it in one direction or the other. Empty setting = today's behaviour. The
   Provisioning Setup registrar card shows whether a code is set. Owner
   holds a 20% corporate code (2026-09-19); it goes in the setting, never
   in a file or the chat.
3. Configure page: view + logic, FormWriter with `visibility_rules` for the
   register/own split, `domain_check` reused, Continue does §4.2. Sites page
   gains the two cards and the Edit/Delete actions (POST, `action_button`).
4. `ManagedSiteRequirement` + `CustomerCloudFulfillment` 1.4:
   `extraRequirements()` returns it, `checkAvailability()` refuses a stale
   draft, `fulfill()` activates. Domain row creation moves here from
   `post_purchase()`.
5. Draft sweep as a phase of `ServerManagerAdvanceProvisioning`, before
   Orders.
6. The taken-name card and alternate-name action (§7) on the Sites page.
7. Retire §8; `apply_hosted_product.php` 3.0 detaches the requirement;
   Provisioning Setup rewords the Question card and the product card.
8. Docs: `plugins/server_manager/docs/overview.md` (provisioning pipeline,
   the buyer pages), `plugins/store/docs/product_requirements.md` if the
   requirement contract text names the domain example, `docs/hosted_tier.md`.
9. Tests: the db-tier provisioning suite gains draft → activation, the
   stale-draft refusal, the sweep, and the taken-name alternate; the
   managed-domain suite's intake tests move to the helper.

## 11. Verification (carried from the hosted spec's §11.7, never run)

1. A Stripe **test-mode** order on dev: configure with a registered domain
   (registrar sandbox), Continue, pay, watch the draft activate, the
   subscription line carry the provision, the domain line carry the frozen
   quote, and the pipeline reach `done` with the password reveal.
2. The same with an owned domain: no domain line, welcome email carries the
   A record instruction.
3. Edit after Continue: the charge is refused with the sentence in §6.3.
4. The sweep: a draft aged past the setting disappears; a frozen draft
   returns to `draft`.
5. The taken name: register the sandbox name by hand between Continue and
   the tick; the card offers the alternate; the alternate registers.
5a. The coupon, on the **live** account only (the sandbox may not honour a
   real code): with the code set, the pricing call for one TLD returns a
   `CouponPrice` below `YourPrice`, the quote shows it, and one live
   registration is charged at it. If the registrar refuses the code at
   registration the row fails with the alert, never a silent full-price
   charge. Also confirm which TLDs the code covers, since an excluded TLD
   quotes at `YourPrice` and must register at `YourPrice`.
6. Then the live gate on a fresh install (the hosted spec's gate), which
   needs the owner accounts listed in the umbrella.

## 12. Out of scope

- Bringing an existing site into Managed (restore from the buyer's own
  archive). The mirror of `managed_customer_departure.md`; a later spec.
- The relay topology for Managed (D2 of the hosted spec: single server).
- Selling the customer-cloud product again. The page supports the mode; the
  product stays inactive.
- Services for self-hosted sites (phase 2) and selling them (phase 3).

## 13. Open

- **Q1. Region list.** Not blocking. The build ships with the setting
  holding one region, so the field does not render; the operator adds
  regions when they want the choice offered.

## 14. Implementation notes for the executor (verified against the tree, 2026-09-19)

Read these before the build order. Each names a fact an implementer would
otherwise have to rediscover.

- **Statuses are enforced by code, not the schema.** `cvp_status` has no
  `allowed_values`. The places that enumerate it and need the two new
  values: the Sites page label map (`views/profile/index.php`),
  `logic/profile_sites_logic.php`, the admin overview
  (`views/admin/index.php`), and `validate_row()` in the data class.
  `ProvisionCustomerCloud`'s actionable query names `ready | booting |
  installing | failed`, so drafts are invisible to the pipeline with no
  change. `DISMISSIBLE_STATUSES` stays `failed | pending_connect`.
- **`validate_row()` origin rule.** Today `order` requires an order item and
  `admin` does not. Add `buyer`: no order item required while the status is
  `draft` or `pending_payment`; required from `ready` on. `cvp_hosting_mode`
  is stamped at activation, so a draft carries the column default until
  then — do not validate it before `ready`.
- **Sealing.** `SecretBox` is instance-based: `(new SecretBox())->seal($locator,
  $plaintext)` and `->open($stored)` (returns an array). Mirror the locator
  convention `ManagedDomainRequirement::post_purchase()` uses for the
  registrant today. The locator needs the row key, so a new draft is saved
  once, then sealed and saved again.
- **The domain intake lift.** `ManagedDomainRequirement` (in
  `plugins/server_manager/includes/requirements/`): `validate()` at :240
  (registrar availability + quote), `process()` at :309, `registrantFrom()`
  at :451, and the shared name/TLD gates in `DomainRegistrarRegistry`.
  The helper takes those three; the requirement and the configure page call
  it. `normalizeRegistrantPhone()` stays on the registrar seam and keeps
  refusing a bare national number.
- **The coupon touches two calls.** `NamecheapRegistrar::register()` builds
  its params at :153; `tldPrice()` calls `users.getPricing` at :310 and reads
  `YourPrice` at :324. `CouponPrice` is an attribute on the same `Price`
  node; it is empty when no code applies, so read it only when non-empty
  and numeric. The `AdditionalCost` (ICANN fee) handling below it is
  unchanged.
- **The taken-name alternate is safe.** `unpaid_reason()` compares the paid
  line's price with `rdm_price_paid`, and `claimed_registrations()` counts
  by order id; neither keys on the domain string, so updating `rdm_domain`
  and returning the row to `pending` re-runs registration under the same
  guards.
- **Companion line contract.** `extra_cart_lines()` must be deterministic
  for identical form data (the edit-cart path relies on it). The frozen
  quote on the draft is what makes it so; never re-quote inside it.
- **The requirement's display.** `get_display_data()` should show the domain,
  not the draft id, and `fulfill()` already returns `label => 'Server for
  <domain>'` for the order line.
- **The welcome email for an owned domain.** Confirm at build that the
  existing email carries the A record instruction when no `rdm_` row
  exists for the provision; that is today's customer-cloud behaviour and
  must survive the intake change.
- **Dev fixtures for the gate.** Dev has product #4769 "Automatic Install,
  hosted" (customer-cloud, ref 1, inactive). `apply_hosted_product.php`
  renames and activates it; run `--check` first. The domain product is
  named by `store_domain_registration_product_id`; if unset on dev, the
  Provisioning Setup registrar card creates it. The Namecheap sandbox flag
  is a setting.
- **Tests.** db tier. The test database carries no content: suites create
  their own product with the customer-cloud grant, a domain product, and
  a user. Start from the existing suites in `plugins/server_manager/tests/`
  for provisioning, the fulfilment provider, and managed domains.
- **Version numbers.** Bump `@version` on every touched class and view
  (`CustomerCloudFulfillment` → 1.4, the data class, the Sites view and
  logic, `FulfillmentRegistry`), per the repository rule.
- **Validation.** `php -l` and `validate_php_file.php` on every touched
  file; `php tests/run.php db --changed` before hand-back.

## 15. What the gate can and cannot prove on dev

**Dev is provisioned for the whole of §11 items 1–5 (owner, 2026-09-19):**
a Namecheap key with the sandbox flag on, the Stripe test keys, and the
operator Linode token are sealed on dev, so the proof can run through to
`done` on a real Nanode (delete each test instance at Linode afterwards;
the platform never deletes). Two things the executor completes: the Stripe
**test-mode webhook** endpoint and its signing secret
(`stripe_endpoint_secret` is empty; activation runs from the webhook, so
without it a test order pays and nothing activates), and the domain product
(`store_domain_registration_product_id` is empty; the Provisioning Setup
registrar card creates it). Dev is a development plane only; the production
Server Manager is getjoinery, and the live gate (item 6) runs there with the
accounts listed in the umbrella.
