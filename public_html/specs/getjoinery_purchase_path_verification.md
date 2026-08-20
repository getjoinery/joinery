# getjoinery.com Purchase Path & Provisioning — Live Verification

**Goal:** be able to say, from evidence, that every product and product
combination on getjoinery.com can be purchased and fulfills correctly — an
ownership minted for each tagged product, a server provisioned for Automatic
Install — under the pipeline that is actually running today.

**Why this is owed:** the store's configuration is complete, but the only
order ever processed on getjoinery was a $5 test-mode purchase on 2026-07-18,
against a since-deleted product, through provisioning tasks that were retired
on 2026-08-03. The current SKU lineup, the ownership-minting path, live-mode
Stripe, the consolidated "Advance customer provisioning" task, and every
multi-item cart shape have **zero runs**. This spec is the campaign that
closes that gap.

## Baseline (verified live 2026-08-20, via node_exec against getjoinery 0.8.306)

| # | Product | Price | Fulfillment | Ownership tag | Purchase script |
|---|---------|-------|-------------|---------------|-----------------|
| 5 | Founder Licence | $499 | — | `*` (bundle) | mint_license_key_product_script |
| 6 | Standard Business Licence | $399 | — | `business` | mint_license_key_product_script |
| 7 | Store | $99 | — | `store` | mint_license_key_product_script |
| 8 | Server Manager | $149 | — | `server_manager` | mint_license_key_product_script |
| 9 | Automatic Install | $39.99 | customer_cloud | (none) | (none) |

- All five active, live Stripe product + price ids stamped. Old products
  2/3/4 soft-deleted.
- `debug = 0` → store runs **live** Stripe keys (present). Test keys also
  present. Test-mode Stripe product/price objects self-create on first
  test-mode checkout (`StripeHelper::get_or_create_price` stamps the
  `_test` columns), so test mode needs no Stripe pre-setup.
- Scheduled tasks: **Advance customer provisioning** active, green every
  15 min (has never had work). **Send Queued Emails** active, green. The
  three July-era provisioning tasks are retired.
- Ownership model shipped: `own_ownerships` exists, **0 rows ever**.
  `lck_license_keys` exists with 0 rows (drop is queued cleanup, not part
  of this spec).
- Provision history: exactly one `cvp` row (`done`, July 18, old pipeline).

## Ground rules

1. **Evidence is a database row or a serving site, never a success page.**
   Every checkpoint below names the row to look at. Read-only queries run
   via `node_exec.php getjoinery --stdin`.
2. **Verify on getjoinery itself.** Dev proving something is not this spec;
   the point is the production control plane's own config and cron.
3. **Phases are ordered by blast radius:** test-mode first, one small live
   charge before the expensive live provision. A phase does not start until
   the previous one's checkpoints all pass.
4. Buyer accounts: use fresh throwaway buyer accounts (real email the owner
   can read) — a buyer who owns nothing is the case a real customer is in.
   The owner's own Linode account is the OAuth grant for Phase 4.
5. Findings that need code changes stop the campaign, get fixed and shipped
   through the normal release cycle, then the failed phase re-runs from its
   start.

## How test mode is entered

`StripeHelper::isTestMode()` = session `test_mode` OR the `debug` setting.
No shipped UI sets the session flag (the only setter is a legacy handler
with no referrers), so the working lever is the **`debug` setting flipped
to 1 for the duration of Phases 1–2, then back to 0** — exactly how the
July e2e ran. Site-wide, so: do it in one sitting, flip back immediately,
and confirm `debug = 0` again before Phase 3. Orders stamp `ord_test_mode`,
so test orders remain distinguishable forever.

## Phase 1 — test mode, each product alone

Five checkouts, one per product, each from a fresh buyer (or the same
buyer where `pro_max_purchase_count` permits; note what it actually
allows — own-once limits are themselves under test here).

Per-product checkpoints, after checkout completes:

- [ ] Order row: `ord_status` paid/complete, `ord_test_mode = 1`, correct
      `ord_total_cost`.
- [ ] Products 5–8: exactly one new `own_ownerships` row — correct
      `own_tag`, `own_usr_user_id` = buyer, `own_license_key` non-empty,
      `own_ord_order_id` / `own_odi_order_item_id` linked.
- [ ] Product 9: a new `cvp_customer_cloud_provisions` row in a
      waiting-for-connect state, `cvp_origin = order`, buyer email/name
      stamped; after-purchase message shows the Connect link; domain
      question answer captured.
- [ ] Buyer-visible surface: wherever the licence key / provision status
      is supposed to appear for the buyer, it appears. (Record where that
      is — if the answer is "nowhere", that is a finding.)
- [ ] Welcome/receipt email actually delivered (check the inbox, not just
      `equ_queued_emails`).
- [ ] Repeat-purchase guard: attempt to buy the same own-once product
      again with the same buyer; the store should refuse or the cart
      should exclude it. Record the actual behavior.

Product 9's provision is **not** advanced in this phase (no OAuth connect)
— Phase 1 only proves the order→provision-row seam. Park the row.

## Phase 2 — test mode, combos

The cart shapes that have never existed, still in test mode:

- [ ] **2a. Licence + plugin:** Standard Business (6) + Store (7) in one
      cart → one order, two ownership rows, distinct tags, both keys
      minted. This is the two-mint-scripts-one-order case.
- [ ] **2b. Full stack:** Standard Business (6) + Store (7) + Server
      Manager (8) + Automatic Install (9) — the realistic "give me
      everything" order. Mixed fulfillment: three ownership rows AND a
      provision row from a single order. This is the highest-value single
      test in the campaign.
- [ ] **2c. Bundle semantics:** buyer purchases Founder Licence (5, tag
      `*`), then attempts to also buy Store (7). Whatever the intended
      behavior is (blocked as already-covered, or allowed as a separate
      purchase), observe it, decide if it is right, and record the
      decision in this spec.
- [ ] Quantity guard: attempt qty 2 of an own-once product in one cart;
      confirm `pro_max_cart_count`/store behavior prevents double-mint.
- [ ] **2d. Provision to a serving box (SSL excluded — already proven):**
      advance one of the parked Automatic Install provisions all the way:
      account link carries a working Linode token → "Advance customer
      provisioning" picks the row up unattended on its own tick → Linode
      instance created → agent-executed install → site serves on the new
      IP (curl with Host header) → provision `done` → welcome email
      **delivered**. DNS/SSL steps are out of scope for this run.
      Cleanup: **no VPS is ever deleted by the agent — owner deletes
      instances manually** (owner directive 2026-08-20); report instance
      id/IP/label instead and leave the node record intact as the handle.
      Leave order/ownership/provision rows for audit (`ord_test_mode = 1`
      marks them forever).

**Autonomous-run mechanics (2d without operator OAuth):** the interactive
Connect flow exists to obtain a bearer token; the provisioner itself just
uses whatever the buyer's `cca_customer_cloud_accounts` row holds, and
`OAuth2Token::isExpired()` treats a NULL `cca_token_expires` as never
expired. So a Linode **Personal Access Token** (scope `linodes:read_write`),
SecretBox-encrypted into the buyer's account link with NULL expiry, lets
the pipeline run end-to-end unattended. The Connect-page UX itself is not
exercised by this — it was proven live in July and gets re-proven in
Phase 4's real-buyer pass.

Exit gate for test mode: flip `debug` back to 0, verify via settings query.

## Phase 3 — live mode, one real charge

Smallest SKU: **Automatic Install ($39.99)** — it is both the cheapest
real charge and the entry ticket to Phase 4, so one live purchase serves
both phases. Fresh buyer account, real card.

- [ ] Live checkout completes against live Stripe keys
      (`ord_test_mode = 0`, `ord_stripe_payment_intent_id` present).
- [ ] Charge visible in the live Stripe dashboard; amount and receipt
      email correct.
- [ ] Provision row created exactly as in Phase 1; after-purchase message
      with Connect link shown.
- [ ] Stripe webhook/return handling in live mode produced no errors in
      `logs/error.log` around the purchase timestamps.

Do **not** refund this charge — it funds Phase 4. (If Phase 4 must be
abandoned, refund through the store's refund path, which then gets its own
checkpoint: `ord_refund_time`/`ord_refund_amount` stamped and the refund
visible in Stripe.)

## Phase 4 — live provision, end to end

Carry the Phase 3 order through the full pipeline — the first-ever run of
"Advance customer provisioning" with real work, and the first provision
since the July pipeline was replaced.

- [ ] Buyer opens the Connect link, completes Linode OAuth (owner's Linode
      account; tokens are 2-hour, no refresh — connect immediately before
      proceeding).
- [ ] Advance customer provisioning picks the row up on its next tick with
      no manual nudging. (Watch, don't push — unattended operation is the
      claim under test.)
- [ ] Linode instance created in the grant owner's account; provision row
      records instance id/IP.
- [ ] Agent-executed install completes; site serves on the new IP
      (curl with Host header) before any DNS work.
- [ ] Owner points the test domain's A record; SSL issued automatically by
      the pipeline (no manual certbot).
- [ ] Provision reaches `done`; welcome email **delivered** with correct
      IP/domain content.
- [ ] Fleet-enrollment seeding: fired or correctly skipped per the buyer's
      entitlement (this buyer holds no fleet-entitled tier, so the correct
      outcome is a clean skip — verify it did not error).
- [ ] New node visible and healthy in Server Manager; jobs history clean.

Cleanup: delete the Linode instance and node record per the established
retry-install cleanup rules (keep joinery-base); document what a real
customer cancellation would have looked like as a finding if the platform
has no story for it.

## Exit criteria

The campaign is done when Phases 1–4 are all green, every finding is either
fixed-and-re-run or explicitly accepted below, and the claim can be stated
as: *a stranger with a card and a Linode account can buy any product or
combination on getjoinery.com and receive what they paid for with no
operator involvement.*

## Findings log

**2026-08-20 — Phases 1–2 run (buyers 595–602, orders 3–9, test mode):**

1. **Registration 500s wherever mailbox tables are absent** (launch-blocking,
   affects every fresh deployment with the plugin uninstalled/inactive).
   `logic/register_logic.php`, `logic/security_logic.php` and
   `logic/account_edit_logic.php` deliberately `require_once` the mailbox
   domain class past the plugin-active gate, then query
   `ied_inbound_email_domains` with no try/catch. `SessionControl` and
   `OutboundTransport` already guard the same load. **Fixed in dev (all
   three files, try/catch → "not hosted"); getjoinery NOT patched** —
   hot-patching prod code was declined by the session's permission layer;
   ships with the next release, or owner applies by hand. Until then no
   one can register on getjoinery.com.
2. **Test mode needed Stripe test objects that didn't exist.** Add to Cart
   500'd ("This product does not have a stripe product id"): the SKUs had
   live ids only, and `get_or_create_price` creates prices but never
   products. Resolved on getjoinery with the platform's own
   `plugins/store/utils/refresh_stripe_test_keys.php` (all 5 test
   products + prices created).
3. **No purchase email of any kind is queued** for any order — no receipt
   template on any product (`pro_emt_receipt_template_id` NULL), and the
   confirmation page still tells every buyer "An email has been sent."
   Store config gap plus false copy.
4. **Any product with a required question is unpurchasable through the UI**
   (launch-blocking — this is Automatic Install). Two stacked defects:
   `products_class.php::output_javascript` runs `list()` over the
   Question's associative `['value' => …]` rule array and emits
   `required: null`; `joinery-validate.js` then crashes on the null param
   (`TypeError … reading 'value'`), and the crash blocks the form submit
   entirely. **Both fixed in dev** (`products_class.php` handles the
   associative shape; `joinery-validate.js` 1.2.2 null-safe); getjoinery
   unpatched — Phase 1/2 orders for product 9 were placed by bypassing the
   broken client validator (`form.submit()`), which exercised the server
   path normally.
5. **Product 9 has no after-purchase message** — combined with finding 3,
   a real buyer is never shown or sent the Connect link, so their
   provision would sit at `pending_connect` forever. Store config
   (admin product edit), not code.
6. **Own-once double-add guard fires but the buyer sees a raw error
   page.** Second Add to Cart of an owned-once product raises
   `SystemDisplayableError` with a good message ("already in your cart"
   + link) that the error page swallows — buyer gets "An Error Occurred"
   and a 500.
7. Registration bypass note: buyers were minted server-side (finding 1
   blocks the real flow) and marked activated/verified — the
   registration → activation-email leg is UNTESTED and must re-run after
   finding 1 ships.

**Verified working (evidence in DB unless noted):** login, terms-accept
gate, setup wizard (dismiss requires the "I understand" checkbox — fine),
product pages, cart, coupon-less checkout with Stripe Elements (ZIP
required), charge recorded (`ord_stripe_charge_id`), orders 3–9 all
status 2 / test-mode-stamped / correct totals; ownership minting for
every tagged product incl. two- and three-mint combo orders; the
"What you own" page shows each licence key; owned products show "You
already own this" instead of a buy button; order→provision seam for
product 9 (domain question answer lands in `cvp_domain`, buyer stamped);
mixed four-product cart produced three ownerships + one provision from a
single order; PAT-backed account link accepted by the pipeline;
**"Advance customer provisioning" picked the ready provision up on its
first unattended tick and created Linode instance 103255756
(45.79.195.250)** — Phase 2d in progress past that point.

## Decisions log

- **2c (bundle semantics), observed 2026-08-20:** a Founder (`*`) holder
  sees "You already own this" on every covered product page — the bundle
  suppresses further purchases rather than allowing overlapping ones. No
  change requested; recorded as intended behavior.
