# Easy signup — finishing the buy, the install and the provider choice

**Status:** Draft, 2026-10-25; WP1 rewritten 10-26 after Q2 was decided. The running
refactors it waited on (declared_permissions, optional_username) are committed and released
(0.8.537). WP1 corrected 10-26 after a re-read: the card checkout works; WP1 is its edges.
WP1a built 10-26 (suite `card_checkout_failures` 33/33, Stripe doubled; its live check is in the
live verification queue). WP1b built 10-26 (`order_refund` 18/18). WP1c built 10-26 (`card_checkout_failures` 45/45), verified on dev against Stripe test mode. WP1d built
10-26 (`hosted_checkout` 24/24). WP1 is built; its remaining live checks are in the live verification queue.

**Companions:** `managed_hosting_and_services.md` (the umbrella and its contracts C1/C2),
`managed_hosting_phase1_purchase.md`, `services_phase2_platform.md`,
`services_phase3_selling_nudge.md`, `DEFERRED_services_pricing.md`,
`getjoinery_hosted_tier_copy.md`, `getjoinery_purchase_path_verification.md`,
`linode_quick_deploy_app.md`, `starter_tier.md`. This spec does not redesign any of them.
It lists what stands between "built" and "a stranger can sign up", in build order.

## 1. What this is for

A person who has never heard of us lands on getjoinery.com and leaves with a working
site, by one of two doors:

- **Managed.** We run the server. They pick a domain, pay $12.99 a month, and sign in
  to their site a few minutes later. Mail and backups are ours.
- **Self-hosted.** They run the server, in their own cloud account, following the
  quick start. For mail and backups they either bring their own accounts, or use ours.

Today neither door can be walked by a stranger from start to finish. §2 says where
each one stops.

## 2. Where it stands (2026-10-25)

Grades: **works** = a passing suite today or a dev row showing a real run;
**built** = code exists, never run for real; **missing** = no code.

### 2.1 Managed

| Step | Grade | Evidence |
|---|---|---|
| Start page, sign in or sign up | works | `start_logic.php:22-59`; suite `managed_start_page` 18/18 |
| Configure a draft, freeze at `pending_payment` | works | `profile_configure_logic.php:29-93`, `ManagedSiteDraft.php:235,330`; walked in the browser 10-25 (draft 36896, deleted after) |
| Continue to the cart | works | `ManagedSiteDraft.php:477-485`, `ManagedSiteRequirement.php:194-232` |
| Refuse a stale draft before the charge | works (suite only) | `CustomerCloudFulfillment.php:106-122`, `checkout_logic.php:200` |
| Payment activates the draft | works | `CustomerCloudFulfillment.php:124-139,148+`; dev order 23935 → provision 26420 done, 10-17 |
| Server born on the operator's Linode | works | provision 26420 → Linode 108417126, node 166978 |
| Domain registered (Namecheap sandbox) | works, last 09-19 | rdm 713 `test380s.com`, order 13518 |
| Hosted mail (SMTP2GO subaccount) | **never run** | `ProvisionHostedMail.php:107-113` refuses: `server_manager_smtp2go_api_key` unset on dev; no provision has ever reached mail state `done` |
| Hosted backups | **built, never run for a buyer** | no buyer node has a backup; inferred, not yet traced: nothing in the buy flow sets the recovery key the backups are sealed to |
| Plan banner, password reveal | works, once each | htr 4437 (10-17); provision 3345 reveal (09-19) |
| Production (getjoinery) | **missing** | no Managed product, no copy, no link to `/server_manager/start`, owner accounts unset (umbrella, "Owner operations") |

### 2.2 Self-hosted

| Piece | Grade | Evidence |
|---|---|---|
| `/page/install`, `/page/quickstart`, existing-domain checker | works | live on getjoinery; suite `domain_setup_check` 47/47 |
| StackScript 2217113 and the install it runs | built since 09-08 | last real deploy 09-08 (jeremytunnell.info); `install.sh` 3.33+ never run end to end on a real box; the DNS-by-token path (the one the quick start sends everyone down) has never run |
| Deploy-form email key and bucket fields | built | `linode_stackscript_wrapper.sh:75-78` → `linode_stackscript.sh:397-410` → `utils/install_mail_provider.php`, `utils/install_backup_target.php`; `stackscript_services_at_install.md` live gate unrun |
| Setup wizard | works (suite) | `includes/SetupSteps.php:460-736`; `setup_wizard_gates` 31/31 |
| Install into the customer's own Linode (paid automatic install) | built, withdrawn from sale 09-08 | `customer_cloud_provisioning` 132/132; `apply_remove_automatic_install.php:404-407` |
| A buyer's effort | — | 4 accounts (registrar, SMTP2GO, Backblaze, Linode), 5 keys pasted, about 11 wizard screens |

### 2.3 Our mail and bucket, or theirs

| | Their own | Ours |
|---|---|---|
| Mail | works: 11 drivers, Mailgun proven on dev (`setup_steps/mail_send.php:738`, `adm/admin_settings_email.php`) | built, never run against the real provider: Connect `setup_logic.php:338`, `ServicesClient.php:101,120`, `JoineryServices.php:174,185`, `Smtp2GoLeg.php`; suites `services_connect` 44/44, `services_tenants` 99/99 (provider mocked); 0 `svt_service_tenants` rows on dev |
| Backups | works: 8 storage providers (`includes/StorageProvider.php:51-164`, `setup_steps/backups.php:72`) | **site side missing**: the plane side is built (`JoineryServices::enrolShelf`, the shelf broker), and Managed nodes back up through it (`BackupRunner.php:589-629`, 39 runs), but nothing on a self-hosted site calls `ServicesClient::shelf*`, and there is no `managed` target |
| Paying for ours | — | **missing**: phase 3, no `JoineryServiceRequirement`; the only grant is a date set by hand (`views/admin/service_tenants.php:134`) |
| Managed buyers | no choice: our SMTP2GO subaccount (`CustomerCloudFulfillment.php:205-212`) and the plane's bucket | |
| Install form | own key and a Backblaze bucket only; no "use getjoinery" option, no provider or region field for the bucket | |

## 3. Fixed on 2026-10-25 (uncommitted, cart-final)

| # | What was wrong | Fix |
|---|---|---|
| B124 | Refunding a Stripe Checkout order fataled: `get_charge_from_payment_intent()` called as a bare function | `admin_order_refund.php`: `$stripe_helper->get_charge_from_payment_intent()` |
| B131 | Changing a subscription's billing cycle set `odi_amount`, a column that does not exist, after Stripe had already been changed | `billing_logic.php`: sets `odi_price` |
| store B3 | PayPal plans and the PayPal script were always USD | `PaypalHelper.php`: currency from `site_currency`, on the plan and both SDK script tags |
| B137 | The Backups step said "all three work the same here" above a form with eight services | `setup_steps/backups.php` 2.6 |
| B138 | The email settings page offered Joinery services with no way to connect or enrol | `JoineryServicesProvider::getSetupUrl()` 1.1; the page links "Set it up" for any provider that has one; status errors escaped |
| B139 | The cart line showed "Site setup: 36896" and "Site setup frozen at …" | the two answers are marked `internal`; the cart skips internal answers, the admin's order page still shows them (`ManagedSiteRequirement` 1.2, `cart.php`, `product_requirements.md`) |
| B140 | The Cayman Islands calling code was `-344`: shown as "+-344", and a registrant phone read as Spain's +34 | migration 224 sets 1345, like the other NANP islands |
| B141 | $12.99 a month showed as "$12.99" on the product page, cart and checkout | `ProductVersion::price_period_suffix()`, `Product::price_period_suffix()`; product, pricing, cart and checkout show "/month"; checkout says what the total's subscription renews at |
| B135 | The quick start told buyers to give their Linode token Linodes Read/Write; the install only calls `/v4/domains` (`linode_stackscript.sh:242-339`) | content pack 3.5: Domains only (both quick starts) |
| B136 | The quick start said the site deletes the token; it deletes its own copy (`linode_stackscript.sh:383-397`) | content pack 3.5 says so and how to revoke it |
| B142 | The Managed description promised mail that receives, and a free install that was withdrawn | `apply_hosted_product.php` 3.1 |

Fixed meanwhile by permissionsrefactor (uncommitted): `StripeHelper::get_or_create_price` matches
interval and trial, and stores only the version's own amount (store B1, B130).

B135, B136 and B142 live in the content pack, which is not in git: they reach getjoinery only
when the owner runs the pack's apply scripts on the node (WP7).

## 4. Work packages

In build order. WP1–WP3 are independent of each other; WP4 needs WP2; WP7 needs WP1–WP4.

### WP1 — money handling (the store, in every checkout mode)

Re-read 10-26 against HEAD (`ab457eae4`). **The card checkout works**: one-time lines,
subscriptions and a mix go through `stripe_regular` to an order, its lines, receipts and
fulfillment; `products_test` drives it against Stripe test mode, dev order 23935 bought a
Managed site with it, and ScrollDaddy's live subscriptions use it
(`theme/scrolldaddy/views/checkout.php:220`). A failure after the card is charged is recorded on
the order and emailed to the operator (`cart_charge_logic.php:1229-1270`). What follows are the
paths around it, in build order.

Already fixed in HEAD: **B127** (a subscription-only order is marked paid by its live
subscription, `cart_charge_logic.php:956-963`); **B143** (PayPal's return compares `plan_id`,
`:226-231`); half of **B145** (a refused return lands on `/cart_confirm`, which offers no second
payment, `_cart_charge_paid_not_completed()` `:21-30`).

#### WP1a — the card form's failure paths (built 10-26)

- **B150 — a refused card says "Something went wrong".** The card is saved to the customer
  before anything is charged (`create_card_from_token()`, `cart_charge_logic.php:658`), and Stripe
  checks it then. That call sits outside every inner `try`, so its `SystemDisplayableError`
  reaches the outer `catch` (`:1297-1305`): the bank's reason is dropped and the order row
  (made at `:620-635`) stays UNPAID. Fix: catch it where it is called, as the charge's own
  failure is (`:697-724`): order in error, `payment.failed`, the bank's reason on `/checkout`.
- **B128 — a subscription is created after the one-time charge.** The one-time lines are
  charged at `:680-735`; each subscription is created later, inside pass 1 (`:944-964`). If
  creating it fails, the post-charge safety net records it and emails the operator, and the
  buyer sees the success page for a subscription that does not exist. (The `exit`s in
  `StripeHelper.php:1458,1467` are not reached in practice: a refused create throws first.)
- **B126 — a subscription whose first payment failed is fulfilled.** Stripe answers a declined
  first invoice with a subscription in status `incomplete`, not an error. The line is set PAID
  whatever the status (`cart_charge_logic.php:954`, `StripeHelper.php:1473`) and its fulfillment
  runs; only the order's own status reads the subscription's (`:956-963`). Narrow, because the
  card was checked when it was saved, but a card that fails on the amount (insufficient funds,
  a bank that wants 3-D Secure) reaches it.

  Fix for B128 and B126 together: create the cart's subscriptions **before** the one-time charge,
  keep them only when `active` or `trialing`, and undo what was made when anything after fails
  (cancel the subscription, refund a first invoice already paid). A declined subscription then
  reaches the buyer as a decline on `/checkout`, before any one-time line is charged.
- **B129 — the hosting receipt reads as an event registration.** The receipt's outcome is
  `event` whenever the fulfillment result has a `ref_id` (`:1023-1036`), and
  `CustomerCloudFulfillment::fulfill()` returns the provision id there. Fix: a provider's result
  may name its outcome (`'outcome' => 'subscription'`), checked first.

Tests: a store suite with the Stripe client stubbed: a card refused when saved, a subscription
created `incomplete`, a subscription refused after another was made, a one-time charge refused
after a subscription was made (refund + cancel), the hosting receipt's outcome.

#### WP1b — refunds and the hosting signals (built 10-26)

- **B125 — refunds.** The refund page (`admin_order_refund.php`) refunded every line from the
  order's one-time charge, posted an empty charge id for a Stripe Checkout order, and showed no
  refund link on a subscription-only order. Fix: `OrderItem::refund_payment()` refunds a line
  from the payment that paid it (`StripeHelper::refundable_charge_for_item()`: a subscription
  line from its subscription's latest invoice, any other line from the order's charge), found
  again on the server rather than read from the form, capped per line
  (`OrderItem::refundable_amount()`), and with "Also cancel the subscription now" cancels through
  `cancel_subscription_order_item()`; Stripe's `customer.subscription.deleted` webhook then sends
  `subscription.cancelled`, which starts the hosting wind-down, as any other cancel does. PayPal and
  wrong-mode orders say where to refund instead.
- **B151 — the order's refund total was a whole number.** `ord_refund_amount` was `int4`, so a
  12.99 refund stored as 13. It is `numeric(10,2)`, and `Order::refresh_refund_amount()` sums the
  lines' refunds (the order charge's part is the larger of its lines' refunds and Stripe's figure,
  so a dashboard refund still counts). A refund of a subscription invoice made in Stripe's
  dashboard is not mirrored on the order: `charge.refunded` finds no order for it.
- **B126b — an expiring incomplete subscription is silent.** `customer.subscription.updated`
  now sends `subscription.cancelled` when a line moves from `incomplete` to `incomplete_expired`;
  `.deleted` skips a line that ended that way, so one cancellation goes whichever arrives first
  (`_stripe_webhook_subscription_ended()`). Which of the two Stripe sends was not settled.
- **B132 — a failure or cancel before the server is built is lost.** A plan row opened by
  `HostedTrialWatch::open_row()` reads its order line (`HostedTrialSignals::catch_up()`): cancelled,
  or `past_due`/`unpaid`/`incomplete`, starts the wind-down then.
- **B144 — a version still holding a coupon's price id.** Fixed at its readers instead of by a
  sweep: a billing-cycle change and both tier changes ask `get_or_create_price()` for the
  version's own price, which repairs a stale stored id, instead of reading the column.

Tests: `order_refund` 18/18; `hosted_tier` gains the early-cancel check (113/113).

#### WP1c — 3-D Secure on the card form (built 10-26)

**B133.** The form turned the card into a token (`stripe.createToken`), saved it as a source and
charged with the Charges API, which cannot run the bank's extra check: a card whose bank requires
it was declined (most European cards, few US ones). Needed because the store plugin is sold (Q2).

Built (`plugins/store/docs/overview.md` § The card form):

- The form makes a PaymentMethod (`stripe.createPaymentMethod`) and posts its id; a posted token
  (an old page, `products_test`'s `tok_visa`) is made into one (`payment_method_from_token()`).
- `cart_charge_logic` runs one sequence: save the card (`attach_card()`), **authorize** the one-time
  lines (`authorize_card_payment()`, manual capture, `setup_future_usage=off_session` when a
  subscription follows), make the subscription (off-session after an authorization, so the bank
  asks once), **capture** last. Nothing is taken for the one-time lines unless the subscription
  went through; this replaces WP1a's "subscription first" order.
- When the bank asks (`requires_action`), what was made is kept in the session
  (`StripeHelper::CARD_PENDING_KEY`, an hour) and the buyer returns to `/checkout`, where the form
  runs `stripe.confirmCardPayment` on load and posts `card_payment_confirm`; the checkout carries on
  with the same order, from Stripe's answer, if the cart's hash is unchanged. A confirmation that
  comes back still unanswered is refused, not asked again. A resumed checkout skips the pre-charge
  refusals it passed on its first pass. A buyer who never returns is charged nothing (an
  uncaptured authorization lapses; an incomplete subscription expires).
- Every refusal goes through `$payment_refused`: authorization released or payment refunded
  (`undo_card_payment()`), subscription cancelled and refunded, reason on the order, the buyer told
  what happened to the money.
- **One subscription per card checkout** (`ShoppingCart::card_checkout_refusal()`, also shown on the
  checkout page): a second subscription would be a second payment the bank may stop on, and a buyer
  leaving between them would have paid for one with no order. No dev order has ever had two
  (0 of 246 subscription orders, 10-26).
- Removed: `create_card_from_token()`, `process_charge()`, `create_charge()` (no other callers).

Tests: `card_checkout_failures` rewritten with Stripe doubled at its API so StripeHelper runs as
written: 45/45 (works, 3-D Secure confirmed / declined / unanswered / cart changed, refusals,
two subscriptions). **Verified on dev against Stripe test mode, 10-26:** `card_checkout_stripe_live`
32/32 and `products_test` 50/50; in the browser, a mixed cart with `4000 0025 0000 3155` showed one
bank confirmation on our page and completed (subscription charged off-session without a second),
`4000 0027 6000 3184` + Fail charged nothing and made no subscription, a subscription alone with
`4000 0000 0000 3220` completed after its confirmation, `4242` completed with no confirmation, a
checkout left at the confirmation charged nothing, and refund-and-cancel refunded the invoice and
cancelled at Stripe. Still owed: a ScrollDaddy subscription after release.

- **B154 (found in the browser run, fixed):** a failed bank confirmation showed Stripe's developer
  text ("The provided PaymentMethod has failed authentication. You can provide
  payment_method_data..."). `StripeHelper::buyer_reason()` shows Stripe's message only for a card
  error, and plain words for a failed confirmation or anything else.

#### WP1d — the hosted page's and PayPal's return (built 10-26)

Applies to `stripe_checkout` and PayPal; dev and ScrollDaddy use `stripe_regular`. What a gateway
payment was made for was kept only in the buyer's PHP session (`$_SESSION['cart_payments']`), and
written on every checkout page view, where a Stripe Checkout session was also made:

- **B146:** for a buyer who never came back, Stripe's webhook made an order marked PAID with no
  lines and stopped.
- **B147:** a return in another browser session found no record and was told to contact the site.
- **B145 (rest):** a return refused after payment left only a log line.

Built (`plugins/store/docs/overview.md` § Gateway returns):

- `GatewayPaymentRecord` (`gpr_gateway_payment_records`) holds the record, keyed by the gateway's
  reference; `ShoppingCart::record_payment()` / `recorded_payment()` / `forget_payment()` use it.
- Nothing is written or made at a gateway by a page view. The hosted page's button posts
  `start_hosted_checkout` to `/cart_charge`, which checks the cart
  (`ShoppingCart::payment_start_refusal()`), makes the session, records it and redirects to
  Stripe; the PayPal buttons call `store/checkout_record_paypal` before creating the order or
  subscription.
- The return finds the record by reference from any browser session (B147); only the session that
  started the payment is signed in and has its cart emptied (`gpr_session_hash`).
- The webhook completes the order from the record (`cart_charge_complete_from_webhook()`, B146);
  the buyer's later return shows the receipt kept on the record and empties the cart.
- A refusal after payment keeps the order in error with the reason, marks the record refused, and
  emails the operator (`_cart_charge_refused_after_payment()`, B145); a payment matching no record
  is emailed too.

reviewer2 (10-26): 1 NEEDED, 3 VALID, 6 NIT; R1-R3, R5-R7, R9, R10 taken (a guest whose
webhook beats the return is signed in; a reload after a refusal emails nobody again; PayPal
buttons send the page's cart hash and a wrong amount is an order in error; record life two days;
the webhook's alert says why; another session's return shows the receipt).

- **B155 (open, R4): a PayPal buyer who never comes back.** PayPal captures in the browser before
  the redirect, and no PayPal webhook completes a one-time order (`paypal_subscription_webhook.php`
  handles `BILLING.SUBSCRIPTION.*` only), so such a payment is never made into an order, and its
  open record is pruned at a week without a word. Fix: complete from `PAYMENT.CAPTURE.COMPLETED`
  by the capture's `custom_id`, as Stripe's webhook does; needs PayPal sandbox keys on dev to
  build against. B146 is fixed for Stripe Checkout only.
- **B156 (open, R8):** `stripe_webhook_test`'s first payload is now the no-record case, so each run
  on dev emails the operator. Give it a record (which also covers the webhook completion over
  HTTP).

Tests: `hosted_checkout` 29/29 (Stripe and PayPal stood in). Not run against Stripe or PayPal: dev's
checkout is the card form, and dev has no PayPal keys (live verification queue).

### WP2 — hosted mail runs once, for real

- Owner: an SMTP2GO account with a master key and webhook secret, on dev first (§7). Account:
  Professional (100k/month, subaccounts included), owner 10-26. The webhook form has its own
  Authorization: Basic fields; set JSON output and the *Email processed* event only.
- **dev has no webhook** (owner 10-26: dev must not receive live customers' mail events). The
  webhook is account-wide, so it is set up on getjoinery only, when it sells Managed; dev's test
  banner shows 0 sent. dev's master key reaches the whole account: before getjoinery has paying
  customers, revoke it or move dev to its own SMTP2GO account.
- **B158 (fixed 10-26):** the webhook counted both `processed` and `delivered`, so a webhook with
  both events ticked counted every message twice. Only `processed` counts (`smtp2go_webhook.php`
  1.4), which matches the provider's own monthly count.
- **dev's key (10-26):** Subaccounts, Sender Domains, SMTP Users, Statistics; no Emails. Read-only
  calls from dev all answered 200: `stats/email_summary` (0 of 100,000; carries `email_count`, which
  `monthToDateSends` reads), `subaccounts/search` (none), `domain/view`, `users/smtp/view`. The
  account already holds getjoinery.com and dev.getjoinery.com as sender domains, both unverified.
- **B159 (fixed 10-26):** `Smtp2GoProvider::recordsOf` read `dkim_value` / `rpath_value` /
  `cname_value`, which are what DNS answers today and are empty until published. The targets are
  `*_expected`. Every new domain read as zero records, so the hosted mail leg would have stopped
  and a site switching to SMTP2GO would have been shown nothing to publish. Smtp2GoProvider 1.5
  reads `*_expected`; the four suites' fakes carry the live shape (provider_dkim 1.3 fails on the
  old reader). Checked against the live `domain/view` answer.
- **Test order 10-27 (provision 38345, wp2.joinerytest.com, own domain, Stripe TEST card):** the site
  built (node 223184); the mail leg stopped at `subaccount/add` with a 400.
- **B160 (fixed 10-27):** the subaccount calls never matched the provider's published API
  (developers.smtp2go.com reference, fetched 10-27). `subaccount/add` sent `subaccount_name`,
  `company_name`, `email`; it takes `fullname` (required) and `limit`, and `subaccount_email`
  invites that address into the provider's console, which a hosted customer must never get.
  `subaccount/edit`, `close` and `reopen` sent `subaccount_id`; they take `id`. A `limit` is one of
  fixed sizes (2,000, 5,000, 10,000 …), so the default allowance of 1,000 was never valid.
  Smtp2GoClient 1.5: fullname plus limit at creation, no email, `id` on the subaccount calls, and
  `subaccountLimit()` rounds an allowance up to the smallest size covering it. Smtp2GoLeg 1.1,
  ProvisionHostedMail 1.4, JoineryServices 1.6 follow; the services suites' fakes carry the
  published fields. `stats/email_summary` takes `subaccount_id` as acting on its behalf (checked
  live: a made-up id is refused by name), so `monthToDateSends` stands.
- **B161 (fixed 10-27):** with B160 in, `subaccount/add` refused the name `test_Joinery hosted —
  wp2.joinerytest.com` ("Please ensure the Name field only contains valid characters"); the
  docs give no rule, and a subaccount cannot be deleted, so names are not found by trial.
  Smtp2GoClient 1.6 `subaccountName()` is letters and digits joined by hyphens (owner 10-27: start
  with what is sure to pass), e.g. `test-Joinery-hosted-wp2-joinerytest-com`. Which characters the
  provider accepts goes on the SMTP2GO call list.
- **Mail leg PASSED to done 10-27 (B161 in):** subaccount zW2ONl `test-Joinery-hosted-wp2-joinerytest-com`
  (Active, plan_size 2000); sender domain mail.wp2.joinerytest.com, its two CNAMEs read from
  `*_expected` (B159 live) and published by hand in the joinerytest.com zone (own domain), verified
  a minute later; SMTP user `test_wp2joinerytestcom-21f30f` in `sandbox` status; settings pushed.
- **B162 (fixed 10-27):** `stats/email_summary` for a subaccount answers 403 to a master key ("does
  not allow access via a parent accounts API key"), so `monthToDateSends` could never read a
  tenant's count. Smtp2GoClient 1.7 reads `plan_used` from `subaccounts/search` (live: 0 for
  zW2ONl); service_tenant_watch 1.4 fakes that listing.
- **B163 (fixed 10-27):** the site's password reset hung on SMTP: Linode blocks outbound 587 (dev
  itself cannot reach mail.smtp2go.com:587; 2525 is open). Smtp2GoLeg 1.3 hands out port 2525.
  The test box got 587 and needs its settings pushed again. Done 10-27 03:03 UTC: old SMTP user
  removed, mail step set back to domain_verified; the plane minted test_wp2joinerytestcom-ee1851
  and pushed it (mail_state done 03:05). The site's own connection check reads Connection
  Successful (authenticated) on mail.smtp2go.com:2525, and the password reset answered in 1.5 s,
  but nothing was sent: the site's error log (read through the agent, job 201468) shows B165.
- **B165 (fixed 10-27):** SMTP2GO refused every send at MAIL FROM: "Verification failed for
  bounces@mail.wp2.joinerytest.com, Unrouteable address, domain verify fail, 550". The provider
  requires the envelope sender's domain to have an MX or address record, and mail.<domain> carries
  only the two provider CNAMEs. Owner decision 10-27: hosted mail is an onboarding convenience for
  owners' everyday email from their own address, a small allowance, few transactional sends and
  almost no lists; anything more complicated brings its own key. So the sending domain is the
  site's own domain (Smtp2GoLeg 1.4), which is routable through the site's address record and is
  the domain the setup wizard's suggested From ({first name}@{domain}) is on. The bare-domain rule
  in mailbox/docs/domain_onboarding.md (automated mail on mail.<domain>) is for a customer's own
  provider setup, not hosted mail.
- **B166 (fixed 10-27):** a hosted site's From address (defaultemail) was blank until the owner ran
  the setup wizard's mail step, so password resets before then failed, though the provision's admin
  password comment relies on forgot-password once the mail leg is done. Owner 10-27: we pick a
  default. HostedMailSettingsMap 1.1 defaultFrom(): when hosted mail arrives and the site has no From
  address, it gets hello@<domain> with Reply-To the owner account's address (the buyer's, set at
  install); never over an address already chosen. Node-side, so it reaches sites with the release.
- **Send proven 10-27 04:07 UTC:** test order 38345 moved to its own domain (A record wp2 added;
  mail.wp2 domain, its CNAMEs and login -ee1851 removed; mail step set back to subaccount_created).
  The plane added wp2.joinerytest.com, its two CNAMEs were published by hand (buyer's own zone),
  it verified, and login -9de15f was pushed (mail_state done 04:06). From set by hand to
  hello@wp2.joinerytest.com (the site's release predates defaultFrom). A password reset left no
  Send failed line in the site's log and moved the subaccount's plan_used 0 to 1: sandbox sends
  count against the allowance. Owed: defaultFrom on a fresh hosted site once released.
- **B164 (fixed):** with the SMTP server unreachable, the test site's password reset hung for minutes
  and ended in a 502 Proxy Error (curl 01:48 UTC): PHPMailer waits 300 s to connect and 300 s per
  reply. SmtpMailer 2.5 gives up after 30 s (an unanswered connect measured 30.0 s); the failed send
  is queued for retry. The reset page keeps its one answer for every outcome, so it still says
  nothing about which accounts exist.
- **Send allowance 2,000 (owner 10-27):** "2000/month is our limit and the only plan we offer".
  The setting's default is 2,000 (server_manager 1.30.118); sm_028 moves a plane still at the old
  1,000 default; the getjoinery content pack (apply_hosted_product 3.2) says 2,000 emails a month.
  Product rows already written keep their text until the pack is run again (getjoinery) or the
  description is edited (dev).
- **B157 (closed 10-27, not needed):** getjoinery.com's SPF names only `mailgun.org`. SMTP2GO's mail is
  SPF-checked against its return path (`em1127764.getjoinery.com`, a CNAME to SMTP2GO's own record),
  never the apex, so the apex SPF needs no SMTP2GO include. Its `-all` stays. Revisit what it lists
  when Mailgun is removed (step 5).
- Run one Managed test order on dev to `mail_state = done`, and send a message from the site.
- Then the self-hosted Connect path: a second dev box connects to dev's plane, enrols mail,
  sends, and switches back to its own provider (`services_phase2_platform.md` §11 steps 1 and 3).

**getjoinery's own mail moves from Mailgun to SMTP2GO** (owner, 10-25: the Mailgun free
allowance is too small for what getjoinery sends). This is getjoinery's own mail (receipts,
password resets, the Managed welcome), separate from customers' mail: that goes through
per-customer subaccounts made with Server Manager's master key (`Smtp2GoClient::fromSettings()`)
and never through the site's email settings. Nothing in the Managed flow needs this move;
it rides on the same account and is the first real send through `Smtp2GoProvider`, whose
suites all run against a stubbed API.

**Key plan (owner 10-27).** Every site that sends has its own subaccount, and its credential lives
inside it. A key's permission groups limit which calls it makes, not which domain it sends as or
how much: a main-account key with Emails could send as any domain the account holds. A
subaccount sends only as the domains verified inside it and stops at its own monthly limit, so a
leaked or runaway sender is contained to its own domain and cap.

| Sender | Credential | Lives in | Limit |
|---|---|---|---|
| getjoinery's plane | API key: Subaccounts, Sender Domains, SMTP Users, Statistics | main account (the only key there) | makes subaccounts, sends nothing |
| getjoinery's own mail | API key: Emails, Sender Domains | subaccount `getjoinery-own-mail` (zW2WEd) | 20,000 |
| each Managed customer | SMTP user (built) | its own subaccount | 2,000 |
| dev's own mail | API key: Emails, Sender Domains | subaccount `dev-own-mail` (e9DwRW) | 2,000 |
| dev's plane | today's narrow key | main account | can see and close customers' subaccounts |

Dev's plane key is the one exception: making subaccounts needs a main-account key. It stays until
the first paying customer and is revoked then (owner 10-27); dev then gets an account of its own
if it keeps testing the Managed mail path. Both own-mail subaccounts were made 10-27 with dev's
key. A subaccount's API key is made in the provider's console (log into the subaccount, Sending,
API Keys); no code changes: `Smtp2GoProvider` reads one key and `domain/add` with a subaccount key
registers the domain inside that subaccount.

1. Check what getjoinery uses Mailgun for, read-only through its agent. `Smtp2GoProvider`
   only sends (its header: not a `RawMessageRelay`); if getjoinery also receives through
   Mailgun, receiving stays there or moves to Postfix on the box, and that is decided first.
   10-27: getjoinery.com's MX is `relay1.getjoinery.com`, so Mailgun only sends. Mailgun holds
   two of its domains, `getjoinery.com` (selector mailo) and `mail.getjoinery.com` (pic); which
   one the site sends as, and whether its From matches it (DMARC is strict, adkim=s), is read
   from its email settings before step 4.
2. The SMTP2GO account is the one WP2 opens (§7). Ask on the call whether every subaccount draws
   on the one plan allowance.
3. Dev first: the `dev-own-mail` key in dev's `smtp2go_api_key`, sending domain
   `mail.joinerytest.com` (the same shape as `mail.getjoinery.com`: a subdomain with no MX or
   address record), a password reset and a receipt delivered and passing DKIM.
   10-27: done for the reset. Key in dev's `smtp2go_api_key` (it lacks `/email/batch`; a refused
   batch falls back to one send per address). `domain/add` with that key put `mail.joinerytest.com`
   inside `dev-own-mail` only (the getjoinery subaccount answers that it does not exist); its two
   CNAMEs (Linode records 48402022, 48402023) verified at once. Dev sends From
   `info@mail.joinerytest.com`, Reply-To `info@dev.getjoinery.com` (was mailgun,
   `info@dev.getjoinery.com`, no Reply-To). A reset at 12:48:05 UTC reached test@dev.getjoinery.com at
   12:48:08: DKIM pass, SPF pass, ham 0.8; plan_used on `dev-own-mail` 0 to 1. So the API path takes
   a sending subdomain with no MX or address record. Receipt 10-27: a guest test-card purchase
   ('Test single product', $5, Stripe test mode, buyer gj-buyer8@dev.getjoinery.com) at 15:58 UTC
   brought the welcome, the email confirmation and 'Your purchase receipt' within 5 seconds, all
   From `info@mail.joinerytest.com` with DKIM pass and SPF pass; `dev-own-mail` plan_used 1 to 4.
   DMARC reads none because joinerytest.com publishes no DMARC record (a test domain).
4. getjoinery: the `getjoinery-own-mail` key in its `smtp2go_api_key`. Add its sending domain,
   publish the records, wait for verified, switch the email settings, send a test.
   10-27: key entered by the owner. getjoinery sends From `support@getjoinery.com` while Mailgun
   signs as `mail.getjoinery.com`; with getjoinery.com's strict DMARC (adkim=s, p=quarantine) its
   mail FAILS DMARC today (B169: dev's copy of its 08-20 'Your site is ready' mail, DKIM pass, SPF
   pass, DMARC fail). So the SMTP2GO sending domain is `getjoinery.com` itself, which signs aligned
   and fixes B169 on the switch. Root cause: `MailgunProvider::send()` always went through `mailgun_domain`,
   even with the From domain active in the account; it now picks the From domain like the raw relay
   (MailgunProvider 1.11, commit email-trust-plain). Added to `getjoinery-own-mail` with dev's plane key; it wants
   CNAME `s1127764._domainkey` -> `dkim.smtp2go.net` and CNAME `em1127764` -> `return.smtp2go.net`
   in the Cloudflare zone, DNS only (not proxied). Neither touches the apex SPF or Mailgun's records.
   10-27 15:44 UTC: switched (primary SMTP2GO, fallback Mailgun). The self-test from getjoinery
   (0.8.542) to test@dev.getjoinery.com arrived From `info@getjoinery.com` with DKIM pass, SPF pass,
   DMARC pass (B169 closed for getjoinery), ham 1.3; `getjoinery-own-mail` plan_used 0 to 1.
5. After a clean day (from 10-27 15:44 UTC), remove Mailgun. Its DNS records stay until then, so going back is
   one setting.

### WP3 — hosted backups have a recovery key

The Managed product promises backups sealed to a key only the buyer holds
(`apply_hosted_product.php` description), and nothing in the buy flow appears to set that key
(inferred 10-25: trace `BackupRunner`'s refusal for a node with no recovery key first). Add the
recovery-key step to the buyer's first sign-in (the Sites page reveal is the natural place),
prove one backup lands for a test buyer, and prove a restore.

- **Where the key is made (owner 10-27):** on the buyer's own site, in the existing setup wizard
  step, not at checkout. A key handed over from getjoinery is exactly what `BackupRunner::plan_manager`
  refuses (a key arriving over a wire could be anyone's), checkout is the worst place for a
  password-manager step, and the site does not exist yet at checkout.
- **Found 10-27 on wp2.joinerytest.com (node 223184, 23 h old):** no backup has ever been
  dispatched to it. Its hourly key report reads `RECOVERY_KEY=none`, so the fleet run skips it
  (`RecoveryKeyFleet` state missing). The buyer is not told: the hosted banner on every admin page
  said "Hosting, email and backups are included … there is nothing to do" over "Backups stored
  0 MB of 10 GB". The key step was step 8 of the wizard, and one "Finish later" ends the sign-in
  redirect; a signed-in owner then lands on /profile, which never mentions backups. The step's
  intro said "Point backups at a storage bucket" on a site the management node backs up, and its
  covered copy called the key one "its operator holds". `plane:backups_stopped` waited 7 days.
- **Built 10-27:** `SetupSteps` 1.18: `order` may be a callable; on a managed site
  Backups is order 6, right after the secure connection (5) the in-browser key needs, and its
  intro says the management node stores the backups and none run until the owner's key exists.
  `HostedPlanNotice` 1.3: in trial, subscribed and grace with no proven key, the banner is urgent,
  says "Your backups are off until you create your recovery key" with a link to the Backups page,
  and the trial no longer says there is nothing to do. `IncidentSourceBackupsStopped` 1.2: grace
  2 days. Suites: setup_steps_copy 11/11, hosted_tier 117/117, incident_sources 149/149,
  manager_coverage 8/8, setup_wizard_gates 31/31, setup_encryption_key_step 16/16.
- **A5 passed 10-27 on wp2 (0.8.543, upgraded by dev job 201655):** with no key, the admin banner was
  urgent and read "Your backups are off until you create your recovery key"; the wizard ran Welcome,
  Beta permissions, Secure connection, Backups (step 4 of 9). The key was made and verified in the
  browser on wp2's Backups page (fingerprint da0607f9…); the banner went calm and said there is
  nothing to do again. Dev's status check read the key proven (jobs 201657, 201658). A backup from
  dev's node Backups tab (job 201659) landed: full, 59.8 MB, chain-20261027_182918, Backblaze B2
  Joinery. The scheduled verify (201660) opened and read it: 5 archives, 2,848 files. Prepare (201662)
  staged it on wp2 with the site's own key; Restore (201663) put an approval on wp2's Backups page,
  written by wp2 from its own records; pasting the buyer's key approved it, and the restore finished
  RESTORE_OK. The site then served and a fresh sign-in worked.
- **B177 (fixed 10-27):** wp2's Backups page said "RECOVERY KEY verified" beside "Your recovery key
  has never been proven". Setup's paste-back is the Recovery Readiness ceremony's own check
  (`BackupReadinessItems::verify_recovery_key` calls `record_possession_proof`), but only the
  readiness page wrote the ledger row. `BackupRecoveryKey::prove_for()` (1.3) records it;
  `backup_recovery_prove` 1.1 and the Backups page's fallback form use it. Test:
  recovery_one_screen_flow 21/21. wp2 itself keeps the old line until its key is proven again (or
  the next site is set up): the fix applies to proofs made after it ships.

### WP4 — our backup storage for self-hosted sites

`services_phase2_platform.md` item 2b, site side: a `managed` backup target on the brokered
store, `BackupRunner` site runs through `ServicesClient::shelf*`, the verify call
(`shelfVerifiedRun`), and a choice on the Backups step. Until the verify call exists a
customer tenant alarms as never verified (`storage_targets.md`).

### WP5 — "use getjoinery" at install

The deploy form and the wizard's Email and Backups steps offer our mail and bucket beside
"my own key". The deploy form gains the bucket's provider and region (it assumes Backblaze
today). Depends on WP2 and WP4.

### WP6 — the self-hosted path, proven again

Deploy the public StackScript on a fresh Linode with a new domain, through the token path,
following the quick start word for word, and record each screen. Then make it a per-release
check: the StackScript installs whatever `latest_release` serves, so every release changes the
buyer's path (inventory finding: 0.8.535 shipped with `site_install` unproven).

### WP7 — getjoinery production

- Products: run `apply_hosted_product.php` 3.1 and `apply_domain_product.php` on the node
  (`--check` first), then activate.
- Copy: write `getjoinery_hosted_tier_copy.md` §1–§6 and link `/server_manager/start` from the
  site. Push content pack 3.5 (B135, B136).
- Owner accounts (§7). Then the phase 1 live gate (`managed_hosting_phase1_purchase.md` §11):
  one test-mode order, then one real order, on getjoinery.

### WP8 — the buyer's screens

Seen walking the dev flow 10-25 (browser, as an admin):

- The start page's sign-up form shows a "Type 'zen'" check, a "Dharma Name" field and a
  timezone list of about 400 entries. Check which of these are dev-only settings; a hosting
  buyer needs email, password, name.
- The configure page defaults to "Register one for me", which opens the full registrant
  address block before the buyer has chosen a name. Check the name inline, then ask for the
  address.
- "Site name" help speaks of folders and databases. The buyer needs a display name; derive
  the internal name.
- Checkout says "Step 2 of 2" while the start page's strip shows three steps, and checkout
  drops the strip.
- With their own domain, the site has no address or certificate until the buyer adds the A
  record from the email. Say so on the configure page, and show the record there too.

### WP9 — selling our services (phase 3)

Waits on Q3. `services_phase3_selling_nudge.md` as written.

## 5. Not in this spec

The $3 starter tier (`starter_tier.md`, waits on `hosted_abuse_response` and
`own_mail_server_sending`), the Akamai Marketplace listing (`linode_quick_deploy_app.md`),
Managed customer departure (`managed_customer_departure.md`), and the live run of
`managed_to_self_hosted_transfer.md`.

## 6. Decisions for the owner

- **Q1. Can a Managed buyer bring their own mail and bucket?** Yes: fewer of our accounts per
  customer, and the story "your keys, if you want them" holds for both doors. No: one shape
  to support, and it is what the code does today. Recommend no for launch; WP5 gives
  self-hosters the choice.
- **Q2. 3-D Secure. Decided 10-26:** the store's own card form gets it (WP1c), because the
  store plugin is sold. Stripe's hosted page stays a supported mode; WP1d fixes its return.
- **Q3. Pricing for our mail and backups** (`DEFERRED_services_pricing.md`). WP9 waits on it.
- **Q4. Coupons on a subscription.** A coupon has no duration (`coupon_codes_class.php:44-56`), so
  on a subscription it discounts every renewal (`$final_price`, `cart_charge_logic.php:948`). Add
  a "first payment only / every payment" choice to the coupon (a Stripe coupon with
  `duration=once` or `forever`), or keep every coupon for life. The choice suits store customers
  running a promotion; for life is today's behaviour and one less field.

## 7. Owner operations

Dev: an SMTP2GO master key and webhook secret (WP2); a welcome-from address and name.
getjoinery: SMTP2GO account on a plan with subaccounts, master key, webhook secret, MSP and
affiliate enrolment; a second SMTP2GO key for getjoinery's own mail and the getjoinery.com
sending-domain records (WP2); closing Mailgun after the switch; a Linode
token with `linodes:read_write`; a backup bucket and credential; Namecheap API eligibility with
the plane's IP allowlisted; the storage referral link (umbrella, "Owner operations").
