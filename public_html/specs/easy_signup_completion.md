# Easy signup — finishing the buy, the install and the provider choice

**Status:** Draft, 2026-10-25. Written from a four-part inventory of the code and dev
the same day. Lands after the running refactors (declared_permissions, optional_username)
are committed and deployed: most of the store work below touches files those carry.

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

### WP1 — money handling for a subscription plus a one-time line

All in `plugins/store/logic/cart_charge_logic.php`, `StripeHelper.php`,
`plugins/store/admin/admin_order_refund.php`, `plugins/store/ajax/stripe_webhook.php`.
permissionsrefactor finished its edits to the first two on 10-25; re-read them before editing. Those
two files were being edited while this was written, so places in them are named by the
comment or function that marks them; line numbers are as of 2026-10-25 16:50 UTC.

- **B126 — a subscription is marked paid whatever Stripe says.** Under the
  `//HANDLE SUBSCRIPTIONS` comment (`cart_charge_logic.php` ~755) the line is set PAID right
  after `process_stripe_regular_subscription_from_order_item()`, and that function sets it
  PAID again (`//SAVE THE SUBSCRIPTION INFO FROM REGULAR CHECKOUT`, `StripeHelper.php` ~1462),
  neither reading the subscription's status. A
  card that fails its first invoice comes back `incomplete`, not as an error, and the hosting
  line is activated. Fix: only `active` or `trialing` is paid; anything else is an error line
  with a buyer-facing sentence, and fulfillment does not run.
- **B126b — an expiring incomplete subscription is silent.** `customer.subscription.updated`
  (`stripe_webhook.php:76-147`) syncs the status and dispatches nothing; only `.deleted`
  (`:148-162`) and the two invoice events (`:181-246`) send signals. Check which event Stripe
  sends when an incomplete subscription expires, and send `subscription.cancelled` for it.
- **B127 — a subscription-only order stays UNPAID.** The order starts UNPAID (~267); under
  `stripe_regular` it is marked PAID only after the one-time charge
  (`//STORE THE CHARGE ID AND MARK PAID`, ~543), inside `if($cart->get_non_recurring_total())`. Dev orders 13359, 23935 and 24018 are like this.
  Fix: mark the order paid once every line is paid (the charge, and each subscription's
  first invoice), and repair the existing rows by migration.
- **B128 — the domain is charged before the subscription exists.** The one-time charge runs
  first (`//NOW CHARGE THE CREDIT CARD`, ~491); the subscription is created later, in the
  post-charge pass (`//HANDLE SUBSCRIPTIONS`, ~755). If creating it fails, the buyer has paid
  for a domain and sees the success page; and `process_stripe_regular_subscription_from_order_item()`
  `exit`s when Stripe returns no subscription id (`StripeHelper.php` ~1460: blank page, cart kept).
  Fix: create the subscription first (Stripe charges its first invoice at once), then the
  one-time charge; on a one-time failure cancel the subscription; replace the `exit` with
  an exception the caller turns into an error line.
- **B125 — refunds.** `admin_order_refund.php:72-75` refunds against `ord_stripe_charge_id`,
  which on a hosting order is the domain charge; a subscription's own invoice is not on the
  order, so a subscription-only order cannot be refunded (`:79-81`). A refund never cancels
  the subscription or stops the server. Fix: refund a subscription line against its invoice's
  charge (`odi_stripe_foreign_invoice_id`), and offer "refund and cancel", which cancels
  through the store's cancel path so the hosting wind-down signal fires.
- **B129 — the hosting receipt reads as an event registration.** The receipt's outcome is
  `event` whenever the fulfillment result carries a `ref_id` (`cart_charge_logic.php` ~826-836, "Outcome category"),
  and `CustomerCloudFulfillment::fulfill()` returns the provision id there (`:137`). Fix: the
  provider result may name its own outcome (`'outcome' => 'subscription'`), checked before the
  `ref_id` rule; the store still never learns what the line is for.
- **B134 — a coupon on a subscription discounts every renewal.** The subscription is made at
  `$price - $discount` (`$final_price`, just under `//HANDLE SUBSCRIPTIONS`). Decide per coupon whether it is first-payment-only
  (Stripe coupon with `duration=once`) or for life; default first payment only.
- **B132 — a failure or cancel before the server is built is lost.**
  `HostedTrialSignals.php:48-53` returns when no plan row exists, and the row is opened only
  after provisioning finishes. Fix: the signal opens the row's grace when the provision
  exists without a plan row, or the provision itself is held until the row exists.
- **B133 — no 3-D Secure.** `stripe_regular` uses tokens and charges (`create_card_from_token`,
  `cart_charge_logic.php` ~469), which cannot run 3-D Secure, so cards that require it are
  declined. See Q2.

- **B143 — a PayPal subscription on any plan pays for the cart.** On PayPal's return the
  subscription is checked for status only (`cart_charge_logic.php` ~412-415: `ACTIVE` or
  `APPROVED`), never for its `plan_id`, so an active subscription to a cheaper plan of ours
  marks the order paid. Fix: compare `plan_id` with the plan the checkout offered for this
  cart. (Raised by permissionsrefactor, 10-25.)
- **B144 — a version already holding a coupon's price id keeps it until next looked up.**
  `get_or_create_price` stores only the version's own amount from 10-25 on, and replaces a
  stored id at its next canonical lookup (`StripeHelper.php` ~1381-1384), but a version that
  is never looked up again keeps the discounted id, and `change_billing_cycle`
  (`billing_logic.php`) bills from the stored id. Fix: a one-time sweep that checks each
  version's stored Stripe price against `prv_version_price` and clears a mismatch. (Raised by
  permissionsrefactor, 10-25.)

- **B145-B147 — gaps left in the gateway return** (reported by permissionsrefactor 10-25
  after its return-path fix; trace each before building):
  - **B145:** on a return, an invalid coupon or an ownership refusal still sends a buyer who
    has already paid back to checkout with an error. Once money has moved, a refusal must
    become an order line in error plus a refund or an operator alert, never a bounce.
  - **B146:** an order the webhook makes for a buyer who never comes back stays PAID with no
    lines. The webhook must build the lines itself, from the recorded payment, when no
    return arrives.
  - **B147:** a return that lands in another browser session (a phone's in-app browser, a
    second tab after sign-out) tells the buyer to contact support. The recorded payment
    should find its order without the original session.

Tests: a store suite that drives each failure with the Stripe client stubbed: incomplete
subscription, subscription create failure after a charge, subscription-only order, refund of
each line kind, expiry webhook.

### WP2 — hosted mail runs once, for real

- Owner: an SMTP2GO account with a master key and webhook secret, on dev first (§7).
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

1. Check what getjoinery uses Mailgun for, read-only through its agent. `Smtp2GoProvider`
   only sends (its header: not a `RawMessageRelay`); if getjoinery also receives through
   Mailgun, receiving stays there or moves to Postfix on the box, and that is decided first.
2. The SMTP2GO account is the one WP2 opens (§7). Ask on the call whether getjoinery's own
   sends and every customer subaccount draw on one monthly allowance.
3. Dev first: `Smtp2GoProvider` on a test sending domain (`mg.joinerytest.com`), a password
   reset and a receipt delivered and passing DKIM.
4. getjoinery: a second key with only the Emails and Sender Domains groups
   (`Smtp2GoProvider::ENDPOINTS_USED`), separate from the master key, so either is revoked
   without the other. Add getjoinery.com as a sending domain, publish its three CNAMEs,
   wait for verified, switch the email settings, send a test.
5. After a clean day, remove Mailgun. Its DNS records stay until then, so going back is
   one setting.

### WP3 — hosted backups have a recovery key

The Managed product promises backups sealed to a key only the buyer holds
(`apply_hosted_product.php` description), and nothing in the buy flow appears to set that key
(inferred 10-25: trace `BackupRunner`'s refusal for a node with no recovery key first). Add the
recovery-key step to the buyer's first sign-in (the Sites page reveal is the natural place),
prove one backup lands for a test buyer, and prove a restore.

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
- **Q2. 3-D Secure.** Move hosting purchases to Stripe's hosted page (`stripe_checkout`,
  which handles 3-D Secure today; the buyer leaves our page to pay), or move `stripe_regular`
  to Payment Intents (more work; the buyer stays on our page). Recommend the hosted page.
  Its return path was broken until 10-25 (reviewer2's trace, fixed by permissionsrefactor,
  uncommitted at writing): the return now builds the order from the payment the browser
  recorded, under a lock the webhook shares (`ShoppingCart::record_payment()`,
  `Order::with_payment_lock()`). The hosted page is usable once a test-mode Managed order
  completes through it; B145-B147 are the return's remaining gaps.
- **Q3. Pricing for our mail and backups** (`DEFERRED_services_pricing.md`). WP9 waits on it.

## 7. Owner operations

Dev: an SMTP2GO master key and webhook secret (WP2); a welcome-from address and name.
getjoinery: SMTP2GO account on a plan with subaccounts, master key, webhook secret, MSP and
affiliate enrolment; a second SMTP2GO key for getjoinery's own mail and the getjoinery.com
sending-domain records (WP2); closing Mailgun after the switch; a Linode
token with `linodes:read_write`; a backup bucket and credential; Namecheap API eligibility with
the plane's IP allowlisted; the storage referral link (umbrella, "Owner operations").
