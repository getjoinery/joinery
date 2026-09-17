# Managed hosting and Joinery-run services — configure, pay once, activate; mail and backups on our accounts, with the nudge to your own

**Status:** Draft, 2026-09-17. Consolidates the purchase side of
`implemented/hosted_trial_provisioning.md` (the Managed product, built
2026-09-06), the checkout intake half of
`implemented/managed_domain_registration.md`, the never-shipped
checkout-time configuration question in
`DEFERRED_automatic_install_mail_topology.md`, and the shelved self-hoster
backup design that was `managed_backups.md` (absorbed here, §14.3, §14.4).
The provisioning pipeline, the mail leg, the backup leg, the trial row, the
banner and the subscription signals are built for Managed and unchanged;
this spec moves **where the buyer configures the site**, **what the cart
carries**, and extends the mail and backup services to **self-hosted
installs**, with the nudge that moves people onto their own accounts.

**Owner decisions, 2026-09-17:**
- The buyer configures first, then pays; payment activates (§2).
- The domain is stored at configure time and registered only after payment.
- Joinery's outbound mail and backup accounts are offered to streamline an
  install, and the product then nudges people to their own accounts. The
  keys stay ours (§14.1).
- **No free trial, as things stand.** A free start is an incentive for
  spammers to create accounts (§14.9). A self-hoster's use of our accounts
  begins with a charge.
- **Pricing is deferred to `DEFERRED_services_pricing.md`.** The owner's
  words: build the provisioning and the nudge machinery "with the
  stipulation that we have no idea how we're going to price." §14.8 is
  written so that every pricing shape is a product on getjoinery and
  nothing in code changes. Price, term and allowances live on the product.
  Any number below is an example.

**Companions:** `DEFERRED_services_pricing.md` (everything about price),
`implemented/hosted_trial_provisioning.md` (the product, the
allowances, the lifecycle, the per-run minted key), `implemented/managed_domain_registration.md` (the
domain leg after payment), `implemented/admin_cloud_instance_birth.md` (the
precedent: a provision row that exists before an order item),
`implemented/mailbox_relay_shared_fleet.md` (the tenant-side enrolment
shape this reuses), `keyless_provisioning.md`, `managed_customer_departure.md`
(leaving Managed), `managed_backup_recovery.md` (recovery-key custody,
deliberately separate), `hosted_bounce_handling.md` (the webhook),
`getjoinery_hosted_tier_copy.md` (the site copy; its buttons land on §4.1),
`getjoinery_purchase_path_verification.md` (the ownership products; its
hosting phase is replaced by §11 here), `subdomain_sandbox_tier.md`
(separate, not a funnel into this).

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

**Someone who installs Joinery themselves** reaches the setup wizard's Email
step and Backups step with the same problem a Managed buyer never sees: open
an account somewhere else, paste keys, publish records. Each step now offers
one more choice, *use getjoinery's accounts*, at whatever is on sale, a
purchase on getjoinery after which the step works in one click. From then
on the site tells them how much of each allowance they are using, and
before the paid period ends, the two ways to keep going: open their own
account and paste it in, or keep paying getjoinery. The switch to their own
is a form on the same wizard step, and nothing is lost in the move.

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
5. Never a key of ours on a machine we create — or on a machine we did not
   (§14.1).

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
a Managed site always uses ours (§14), that is what Managed means.

**Adding a question later** is: a column in `$field_specifications`, a field
on this form, a line in the summary. Nothing in the store changes. That is
the property the checkout could not give.

The page shows the price as a sentence, read from the product (today's
Managed price is $12.99 a month, from the two-choice decision; `DEFERRED_services_pricing.md` may move
it): *$12.99 a month from today* plus,
when registering, *and $X once for the domain year*. The submit button is
**Continue to payment**.

### 4.2 Continue to payment

Continue validates the draft in full (domain shape and TLD gate, availability
re-checked and the quote refreshed, registrant block complete, region in the
list, slug not held by any live provision — the slug check is advisory here
and binding at activation), then:

- flips the draft to `pending_payment`, which freezes it (§5.2),
- adds the Managed product to the cart with the draft id as the line's one
  requirement answer, and the domain-year companion line from the frozen
  quote when registering (§6),
- sends the buyer to the cart.

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

A domain leg that failed after payment shows on the same card (§7). A
self-hosted site enrolled in a Joinery-run service (§14) appears on the same
page as a **Services** card: the site's address, each service in use with
its figure, and the release action.

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
charge — returns a sentence when the line's draft is not `pending_payment`
or not the buyer's: *You changed your site setup after adding it to the
cart. Remove it from the cart and continue to payment again.* Today it
always returns null; this is its first use.

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

- The domain `QuestionRequirement` injection for customer-cloud products,
  and the setting that names it. Provisioning Setup's "domain Question"
  card goes; the page's product card says the product's questions are asked
  on the configure page.
- `ManagedDomainRequirement`'s product-page fields and `post_purchase()`
  intake. The class is deleted once the helper carries its logic; the
  "Managed domain" tick under *Info to collect before purchase* goes with it.
- The `pri_` attachment of the managed-domain requirement to the Managed
  product on getjoinery (the product row script `apply_hosted_product.php`
  gains a version that removes it).
- Three readers of the domain out of order item requirements
  (`create_provision()`, the managed-domain `post_purchase()`, and the
  Provisioning Setup badge) — replaced by the draft.
- `managed_backups.md` as a separate flat-fee product for backups alone.
  Its storage design is §14.3 and its subscription is the one in §14.8,
  which covers both services.

## 9. Settings

| Setting | Default | Meaning |
|---|---|---|
| `server_manager_draft_days` | 14 | drafts older than this are swept; frozen drafts return to draft |
| `server_manager_hosted_regions` | the customer-cloud region | the regions a buyer may choose, space-separated Linode ids |
| `server_manager_services_url` | `https://getjoinery.com` | the tenant side: where a self-hosted site enrols (§14.4) |
| `server_manager_services_api_public_key` / `_secret_key` | — | the tenant side: the getjoinery account's API key (sealed) |
| `server_manager_services_grace_days` | 14 | the tenant side of a lapse: how long a service runs after the paid-through date (§14.5) |
| `server_manager_services_notice_days` | 21 | how far ahead of the paid-through date the nudge begins (§15) |

No setting names a services product. A product declares that it grants a
service through its Purchase grants picker (§14.8), and the operator side
finds every such product by that declaration — so a new tier is a new
product, and a price change is a product edit.

`server_manager_provisioning_domain_question_id` is removed from
`plugin.json`. The allowances (`server_manager_hosted_send_allowance`,
`server_manager_hosted_shelf_allowance_gb`), the referral links
(`server_manager_smtp2go_referral_url`, `server_manager_storage_referral_url`,
`server_manager_linode_referral_url`) and the hosted, domains and
customer-cloud groups are the existing ones and apply to self-hosted tenants
unchanged.

## 10. Build order

1. Data class: the columns in §5.1, `validate_row()` for the two pre-payment
   states, `DISMISSIBLE_STATUSES` unchanged (a draft is deleted, not
   dismissed).
2. `ManagedDomainIntake`: quote, validate, seal — lifted from
   `ManagedDomainRequirement`; the configure page and the requirement call it.
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
   Provisioning Setup drops the Question card.
8. **Services, operator side** (§14.4): the tenant row, the enrol / status /
   release actions, the B2 standing-key mint and revoke through the client
   that already exists for per-run keys, the SMTP2GO subaccount leg reused
   from `ProvisionHostedMail` with the node-less branch, the reconcile and
   metering phases (§14.5).
9. **Services, site side** (§14.4, §15): the *use getjoinery's account*
   choice on the Email and Backups wizard steps, the `managed` target
   provider, the status poll, the `services` banner state, the switch-over
   forms.
10. The nudge emails (§15.3), sent by the operator side from the metering
    phase.
10a. Joinery Services (§14.8): the `joinery_services` fulfilment provider
    with its plan ladder, the `JoineryServiceRequirement` carrying the
    tenant id, a placeholder product on dev at any price for the gate, the
    paid-through derivation in the reconcile, and the Buy action on the
    Sites page's Services card. The getjoinery product script waits for
    `DEFERRED_services_pricing.md`.
11. Docs: `plugins/server_manager/docs/overview.md` (provisioning pipeline,
    the buyer pages, the services), `plugins/store/docs/product_requirements.md`
    if the requirement contract text names the domain example,
    `docs/hosted_tier.md`, `docs/backups.md` (the `managed` provider),
    `docs/email_system.md` (the getjoinery send choice).
12. Tests: the db-tier provisioning suite gains draft → activation, the
    stale-draft refusal, the sweep, and the taken-name alternate; the
    managed-domain suite's intake tests move to the helper; the services
    suite in §14.7.

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
6. Then the live gate on a fresh install (the hosted spec's gate), and the
   owner accounts it needs, still unset: SMTP2GO master key and webhook
   secret, a Linode token scoped `linodes:read_write`, a B2 master key with
   `writeKeys`, Namecheap API eligibility and the plane's IP allowlisted.
7. Services on a self-hosted install (a fresh StackScript box against dev as
   the operator): buy the placeholder product in Stripe test mode from the
   wizard's link, enrol mail, send the test message through the subaccount,
   publish the records, see the figure on the banner; enrol backups, take a
   chain, prove from the operator side that the tenant key cannot list,
   read or delete; move the paid-through date back and watch the ladder
   (§14.5). Then the same with the placeholder as a monthly subscription:
   the date follows the period end, and a cancelled line lapses on the
   ladder with no new code.

## 12. Out of scope

- Bringing an existing site into Managed (restore from the buyer's own
  archive). The mirror of `managed_customer_departure.md`; a later spec.
- The relay topology for Managed (D2 of the hosted spec: single server).
- Selling the customer-cloud product again. The page supports the mode; the
  product stays inactive.
- Annual billing, larger tiers, the sandbox tier.
- Recovery-key custody for the shelf (`managed_backup_recovery.md`). Enrolment
  says in one sentence that we cannot open a backup and cannot help if the
  recovery key is lost.
- An AI provider key on our account. The AI step stays bring-your-own.

## 13. Open — the purchase

- **Q1. Region list.** Does Managed offer a region choice at launch, or one
  region and no field? The field costs nothing either way; the list is the
  operator's call at launch.

---

## 14. Joinery-run services: outbound mail and the backup shelf

The Managed site uses them because that is what Managed is. A self-hosted
site may use them to get through the wizard without opening two more
accounts. One design serves both; the difference is only how the credential
reaches the box.

### 14.1 Keeping control of the keys

The rules, all inherited from the hosted spec's doctrine and already
enforced on the Managed path:

- **Master keys never leave the management node.** The SMTP2GO master key,
  the B2 master key and the registrar credential are sealed settings on the
  plane. No install, Managed or self-hosted, ever holds one.
- **What reaches a box is cut to that customer's own slice.** A credential
  that can name only the customer's own domain or the customer's own
  prefix, and nothing else. A leaked credential exposes one customer's own
  service, and is revoked with one call from the plane.
- **The box owner can read anything on the box.** Design for it: every
  credential on a box is one the owner is allowed to have.
- **Enforcement lives at the provider or on the plane**, never in a setting
  on the box, which the owner can edit. The provider counts sends; the plane
  meters bytes, revokes keys and prunes.
- **The bytes we store we cannot read.** Backups seal to the customer's
  recovery key before upload; the shelf is ciphertext to us.

### 14.2 Outbound mail — one SMTP2GO subaccount per customer

Built for Managed (`ProvisionHostedMail`); the same leg serves a self-hosted
tenant with one branch. The plane creates a subaccount with the master key,
sets the monthly `limit` from the allowance setting, adds the sender domain
under it, and creates **one SMTP user inside that subaccount**. That
username and password is the whole credential the box gets. It can send as
the customer's domain and no other; the subaccount counts its sends and the
provider refuses past the limit. Kill switch: close the subaccount.

The one branch: a Managed box receives the SMTP settings over the agent
channel (`hosted_mail_settings`); a self-hosted box has no channel, so the
enrol call **returns** them and the site writes its own `email_service`,
host, port, user and password (§14.4). The DKIM, return-path and tracking
records come back the same way and the wizard's existing DNS publish box
publishes them; a Managed site has them published by the domain leg.

Sending identity is the customer's own domain in both cases. A sender domain
that never verifies (records not published within the provider's window)
leaves the tenant's mail row at `domain_added` and the wizard step amber,
exactly as the Managed leg reports it; nothing sends until it verifies,
which is also the abuse gate: you send as a domain whose DNS you control.

### 14.3 The backup shelf — a prefix-pinned key, and two ways to hand it over

The shelf is one B2 bucket of ours, one prefix per customer,
`{slug}/`. Every backup is sealed to the site's own recovery key before it
leaves the box (`BackupRunner` hard-codes it), so we store ciphertext, and
the recovery ceremony stays mandatory: a site with no proven key refuses to
back up at all, and the wizard step says the backups start when the key is
created.

**Managed: no standing key, built.** Backups are the fleet manager profile,
dispatched from the plane. At job pickup the plane mints a B2 application
key pinned to the prefix, `writeFiles` only, with a lifetime derived from the
job's timeout, and the agent holds it for that run alone. Restores use
presigned links from the plane's master credential. Ships off until the B2
master key is replaced by one that can create keys (`writeKeys`).

**Self-hosted: a standing scoped key.** The box runs its own backups on its
own schedule, so there is no plane-run job to mint into. Enrolment mints a
key pinned to the prefix, `writeFiles` only — no `listFiles`, no
`readFiles`, no `deleteFiles` — and returns it; the site writes an ordinary
target row with provider `managed` (behaves as `b2` for the engine; the
Backups page renders it locked, as a service, with the figure). What the
key can do if it leaks: write junk into one prefix. What it cannot: read a
backup, list one, delete one, touch another customer. Revocation is
`b2_delete_key` from the plane. The alternative — the box calls the plane
each run for a short-lived key — was considered and dropped: the box would
need a standing credential to make that call, so it trades one standing
key for another and adds a dependency on the plane at every run.

The site cannot prune its own shelf (write-only), so **the plane prunes
every tenant** from the delete-capable side, chains whole, to a fixed
retention that is a property of the service, not a tenant setting. The
prune pass sums the prefix; that figure is the meter.

### 14.4 Enrolment — how a self-hosted site gets a credential

The shape is the mailbox fleet's, already shipped: the tenant holds a
getjoinery account and one of its API keys; the tenant calls enrol, status
and release actions on getjoinery; the operator side keeps one row per
tenant per service. The shelved backup spec's first build item — lift that
tenant-to-operator pattern out of the mailbox plugin into core so a second
and third service sit on it — stands, and is item 8 of §10: a client class
(API-key auth against a service URL, the `FleetClient::call` shape), an
operator-side service base (qualification check, enrol / status / release,
a slot row with `provisioning | active | suspended | released`), and the
grace-lapse reconcile. The mailbox fleet migrates onto it; mail and backups
are its second and third consumers. Backups are core, so the tenant side of
that one lives in core, not a plugin.

**The account link.** A self-hosted site needs a getjoinery account. The
wizard step says so in one line and offers the two ways in: paste an API key
from your getjoinery profile, or — when the install came through the
StackScript or the configure page and the key was seeded — nothing. Seeding
is the existing customer-cloud pattern (`FleetProvisionSeeding`) and is not
new work for Managed; for the StackScript it is a field on the deploy form
(Q4 in §16).

**Enrol, per service:**

| Service | Operator side creates | Returns to the site | Site writes |
|---|---|---|---|
| Mail | subaccount, limit, sender domain, SMTP user | host, port, user, password, the DNS records | the send settings; the records go to the publish box |
| Backups | slug, the standing prefix-pinned write-only key | endpoint, bucket, prefix, key id + secret, retention | a `managed` target row, `backup_target_id` |

Idempotent on the tenant row: a second enrol returns the same coordinates.

**Status** returns, per service: the figure (sent this month of the
allowance; bytes on the shelf of the allowance), the state, and the notice
sentence if any. The site polls it daily (a scheduled task, like the fleet
status check) and on the wizard step's load, and feeds the banner (§15.1).

**Release** is the customer's action (§15.4) or the reconcile's (§14.5):
close the subaccount or revoke the key, and start the retention clock.

### 14.5 Enforcement and the lapse ladder

| Limit | Meter | At 80% | At 100% | Lever |
|---|---|---|---|---|
| Sends, 1,000/mo | the provider, against the subaccount limit; the webhook feeds the figure | banner + one email | the provider refuses (+10%) until the month rolls; the site's send log shows the refusals | the `limit` |
| Shelf, 10 GB | the prune pass sums the prefix | banner + one email | no new runs are accepted: Managed stops minting; self-hosted has its key revoked after `server_manager_services_grace_days` of sustained overage, with the banner saying exactly why uploads stopped | mint / revoke |

A stopped upload path is never quiet: the site keeps taking local backups,
the run history names the cause, and the wizard step is red with the
sentence.

**Lapse** (the paid-through date passes with no renewal — §14.8; the
reconcile compares the date every pass, so no signal is needed): grace of
`server_manager_services_grace_days`, then the
credential is closed or revoked and the tenant told, then the shelf is
retained 90 days from revocation and pruned. Re-qualifying at any point
before the prune reactivates in place. The 90 days is a customer-facing
promise and appears in the copy.

### 14.6 Data, operator side

- `svt_service_tenants`: user, site identity (the host, as
  `MarketplaceClient::site_identity()` reports it), service (`mail` |
  `shelf`), slug, state, provider ids (subaccount / domain / SMTP user, or
  key id), `svt_allowance` (the plan's allowance for this service,
  snapshotted each reconcile), `svt_paid_until` (the date the tenant is paid
  through; null on a Managed tenant, whose hosting is the entitlement),
  figure + measured time, grace-ends time, revoked time, prune-after time.
  The paid lines themselves stay in the store; the tenant row records the
  date they add up to. A Managed provision links to its tenant rows so the
  Sites page and the banner read one source.
- Nothing new on the site beyond the sealed settings in §9 and the
  `managed` target provider.

### 14.7 Tests (from the absorbed backup spec, plus mail)

- Enrol → row written, key scoped to the tenant prefix; a second tenant's
  key cannot read or write the first's objects.
- Write-only proof: the tenant key cannot delete or list outside — or
  inside — its prefix; refusal asserted, not assumed.
- Metering: bytes aggregate per tenant from a listing; overage flags at the
  allowance.
- Pruning: chains deleted whole, never a full out from under its
  incrementals; families aged independently.
- The ladder: grace → revocation → retained → pruned, and reactivation at
  every stage before the prune.
- Mail: enrol returns one SMTP user inside the tenant's subaccount; release
  closes the subaccount; a second enrol is idempotent.
- Site side: the `managed` target renders locked; the status figure
  populates; a revoked key surfaces as a failed-run cause, not silence.

### 14.8 Joinery Services — entitlement that does not know the price

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
| a trial | the store's own trial period on a subscription line | the period end covers it (§14.9 stands against one) |
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
the draft id (§6.1): no fields, a hidden id, refused unless the tenant is
the buyer's. A site that has never enrolled has no tenant row yet, so the
wizard's link creates one at `unpaid` first (E6). A second site is a second
purchase.

**Entitlement is a date, derived, never typed.** `fulfill()` runs at
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
3. otherwise the lapse ladder in §14.5.

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
The site-side surfaces (§15) link to the card. A Managed customer never
sees the offer. A tenant whose services have both been moved to the
customer's own accounts shows the date and nothing to buy.

### 14.9 Abuse

The only service with abuse value is outbound mail: the shelf is write-only
ciphertext with no share or download path, and a spammer gains nothing from
it. The defense for mail is three things and no more:

1. **A real charge before any sending.** There is no free start; the first
   thing a self-hosted tenant does is pay, which is a card that cleared
   Stripe's checks and a chargeback risk a throwaway card cannot carry at
   scale. The amount matters less than the charge.
2. **A verified sending domain.** Nothing sends until the DKIM and
   return-path records are published under DNS the customer controls
   (§14.2), and their own domain signs every message.
3. **The provider's own controls** on the subaccount, plus the plan's
   monthly limit, which the provider enforces.

A warm-up ramp on new subaccounts was considered and rejected by the owner
(2026-09-17): 1,000 sends a month is not a spam volume, and the ramp is
complexity that protects nothing at that size. There is no platform-side
bounce or complaint enforcement, per the 2026-09-06 decision.

## 15. The nudge — the two doors, at 80% and at renewal

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

### 15.1 The banner

`HostedPlanNotice` already renders, to permission 5 and up, where the
hosting stands and each allowance as a row: label, used of allowance, and
when the row is at 80% or more, the action as a link. On a Managed site
the plane pushes the figures over the channel today. Three additions:

- A state `services` beside the four billing states, for a self-hosted site
  using the services: the paid-through date, the allowance rows and the
  manage link; no billing sentence. Nothing renders until a tenant is paid,
  which is what keeps a plain self-hosted install silent.
- On a self-hosted site the figures come from the daily status poll
  (§14.4), written into the same five settings the channel writes on a
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

### 15.2 The wizard step

The Email step and the Backups step are where the services were chosen, so
they are where they are left. Before a purchase, each step offers the
choice as a paid one: *Use getjoinery's — <what is on sale, at its price>*,
linking to the Services card; the step enrols itself when the customer
returns paid. An enrolled step is green and says what is in use,
the figure, and the paid-through date, with the switch-over form (§15.4)
folded closed under one line: *When you are ready to use your own account,
set it up here.* At 80%, or three weeks before the date, the line opens by
itself and carries both links; the step stays green. At 100%, or in grace,
the step is amber with the paused sentence and the two doors. After
revocation it is red and says exactly why uploads or sends stopped.

The referral links are the three settings that exist. The SMTP2GO affiliate
enrolment (`smtp2go_affiliate_signup.md`) and a storage referral are owner
tasks; until a link is set the action renders without a link, as the banner
already does.

### 15.3 The emails

From the operator side's metering phase to the site's admin address, each
the row's sentence, the two doors with their links, and the sentence that
the site keeps working (mail resumes on the 1st; local backups continue).
No marketing beyond the two doors. At most: one at 80% and one at 100% per
service per month; one at the notice window before the paid-through date
and one on the day, neither for a line that renews on its own; one at
revocation. A quiet customer whose payments go through gets none.

### 15.4 The switch-over, and losing nothing

- **Mail.** The Email step's form takes the customer's own provider
  credentials, as it does today for any provider. The site sends the test
  message through the new provider; on the human *It arrived* click the site
  calls release, the plane closes the subaccount, and the `mail.<domain>`
  records the plane published are listed for the customer to replace. The
  order matters: the new provider is proven before the old one is closed.
- **Backups.** The Backups step's own-target form writes a second target;
  both run side by side (the engine allows it). Once the customer's own
  target has a verified chain, the step offers *stop using getjoinery's
  shelf*, which calls release; the shelf is kept 90 days from that day, and
  the step says so. Nothing is deleted while the customer has only one copy.
- **Releasing early refunds nothing** by itself. What release does to a
  paid line is a pricing question (`DEFERRED_services_pricing.md` §5).
- **Managed.** A Managed customer's off-ramp for email and backups is the
  same two forms — the hosted spec's §7 already says so — and for compute it
  is `managed_customer_departure.md`.

## 16. What remains to be worked out

Decisions for the owner:

- **Pricing, all of it,** is `DEFERRED_services_pricing.md`: cadence,
  bundle or per service, tiers, trial, Managed's price, what release does
  to a line. Nothing in this spec's build depends on it.
- **Q4. The StackScript deploy form:** add an optional getjoinery API key
  field so the wizard steps arrive linked to the account, or leave the
  paste. The field is the one-click story; the paste is zero new surface on
  a form that is public.

Engineering still to settle before the build:

- **E1. The enrolment skeleton's exact seam** — what moves from
  `FleetClient` / `FleetService` into core and what the mailbox fleet keeps.
  Read both before item 8 of §10.
- **E2. The `managed` target provider** on `BackupTarget`: confirm the
  engine's `b2` path needs nothing beyond the endpoint and that the Backups
  page can render a locked target.
- **E3. B2 verification items** (carried): a `namePrefix` key is honoured
  over the S3-compatible endpoint the engine uses; the account's application
  key ceiling; whether expired keys are reaped. Standing keys make the
  ceiling matter more than per-run keys did.
- **E4. The webhook figure for a self-hosted tenant:** SMTP2GO webhooks are
  per sending credential and carry `auth`; the one endpoint on the plane
  already meters Managed subaccounts, so a tenant's SMTP user needs its
  `auth` registered the same way.
- **E5. Site identity.** A tenant is keyed by the site's host. A site that
  changes its domain must re-enrol or carry the row; decide at build.
- **E6. The unpaid tenant row.** The wizard's buy link needs a tenant row to
  buy for before anything is enrolled (§14.8). Either the link's first call
  creates one at `unpaid`, or the Services card creates it from the site
  identity in the link. The former keeps creation on the operator side.

Owner operations (unchanged, still unset): SMTP2GO account, master key,
webhook secret, MSP and affiliate enrolment; a Linode token scoped
`linodes:read_write`; a B2 bucket and a master key with `writeKeys`,
`listKeys`, `deleteKeys`; Namecheap API eligibility and the plane's IP
allowlisted; a storage referral link.
