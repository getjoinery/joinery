# Spec: Cart-Charge Atomicity — Validate Before Charge, Reconcile After

**Status:** Draft
**Created:** 2026-05-05
**Related context:** Surfaced during Phase 2 testing of `specs/receipts_refactor.md`. See order 6457 on the test site for a concrete instance.

---

## 1. Problem

`logic/cart_charge_logic.php` charges the customer's card (Stripe or PayPal) before completing the per-line work that turns a charge into a finished order. The function's shape today is:

```
1. Resolve billing user (may create one)
2. Create draft Order (status=UNPAID)
3. Validate coupon codes
4. CHARGE THE CARD              <-- money moves here
5. foreach cart line:
     - Resolve / create per-line recipient user
     - Create order_item, save coupons
     - Handle subscription / event registration
     - Run product scripts and requirement hooks
     - Apply subscription tier grants
6. Mark Order PAID
7. Send receipt emails (R1/R2/R3)
8. Clear cart
```

If anything between step 4 and step 6 throws, the customer's card has been charged but the order stays `UNPAID`, the line items aren't all created, no receipt fires, no event registrations happen, and the operator has to reconcile manually. The customer sees the generic "Something went wrong processing your order" message and a debit on their card with no email confirming anything happened.

This was directly observed during Phase 2 testing. A gift purchase to a new email address (`jeremy.tunnell+gift@gmail.com`) hit a length-mismatch in `User::CreateNew`'s temp-password generator. Stripe charged 4242…4242 successfully, then `User::CreateCompleteNew` threw `Your password must be at least 8 characters`, and the loop unwound. **Order 6457 sits in `ord_status=UNPAID` today with a real `ord_stripe_charge_id` populated.**

The temp-password bug itself was fixed pointwise (`data/users_class.php:323`). The structural issue — that **any** post-charge exception strands an order — remains.

---

## 2. Failure surface (post-charge code paths that can throw)

Enumerated from `cart_charge_logic.php` lines 303–614. Each is a potential charge-without-completion:

| Step | Source of throw | Likelihood |
|---|---|---|
| Per-line user resolution | `User::CreateCompleteNew` validation (email format, duplicate constraints, password generator, future tightening) | **Medium** — the recently-fixed bug; new validations would resurface this |
| order_item creation | `prepare()` validation, missing required fields | Low |
| Coupon-code-use insertion | Schema/validation errors | Low |
| Subscription handling (Stripe) | `process_stripe_regular_subscription_from_order_item` — Stripe API errors after the initial charge | **Medium** |
| Subscription handling (PayPal) | Subscription verification, status update | Low |
| Notification email | wrapped in try/catch already | Safe |
| `Event::add_registrant` | Capacity exhaustion (race), event soft-deleted between cart-add and checkout, schema errors | **Medium** |
| `$product->run_product_scripts` | Plugin code paths, anything with `require_once` | **Medium** — depends on plugins installed |
| `AbstractProductRequirement::post_purchase` hooks | Custom requirement code | **Medium** — extension point |
| `SubscriptionTier::handleProductPurchase` | Tier conflict resolution, db errors | Low |
| Receipt sends (R1/R2/R3) | `EmailSender::sendTemplate` — already wrapped in try/catch by Phase 2 | Safe |

The "Medium" entries are the dangerous ones — anything an admin/operator can trigger by changing site state between the customer adding to cart and clicking Pay (event capacity, plugin install, requirement misconfig) becomes a stranded-order scenario.

---

## 3. Design options

### Option A: Validate-then-charge (pre-charge audit pass)

Add a dry-run validation pass before the charge that exercises everything the post-charge loop will do, in a way that surfaces predictable failures without side effects.

- Pre-create / resolve all per-line users (real side effect, but a redundant user costs nothing)
- Validate capacity for each event registration intent
- Validate all coupon codes
- Validate product max-purchase-count limits
- Optionally: invoke a `pre_purchase_check()` method on requirement instances and product scripts

Charge only proceeds if every check passes.

**Pros:** Customer sees errors before being charged. Failures are recoverable from the cart UI.

**Cons:** Capacity and similar race-condition checks are best-effort — capacity could go to zero in the milliseconds between the audit pass and the charge. Some checks would require duplicating non-trivial logic. Plugin code paths can't be reliably "dry-run" without running them.

### Option B: Charge-then-reconcile (atomic charge + flagged orders)

Charge first. Mark order PAID immediately upon successful charge — before the per-line loop. Run the fulfillment loop in a section-level try/catch that records partial failure into the order, never throws. Provide an admin reconciliation UI listing PAID-but-incomplete orders.

- `Order::STATUS_PAID` is set the instant the charge clears
- A new `ord_fulfillment_status` enum tracks `complete | partial | failed`
- A new `ord_fulfillment_errors` JSON column captures per-step failure messages
- An admin page `/admin/admin_orders_reconcile` lists orders where `fulfillment_status != 'complete'` with a "retry fulfillment" action

**Pros:** Customer always gets a confirmation (since R1 is sent regardless of partial fulfillment). Charge is recorded as paid in our system the moment it's paid in Stripe — matches reality. Operator has tooling.

**Cons:** Admin workload to babysit reconciliation. Fulfillment retry logic must be idempotent at every step (some steps already are; others would need work). Customer might see a receipt before the activation token is actually usable.

### Option C: Hybrid — pre-charge validation + post-charge resilience (recommended)

Combine the cheap parts of A with the safety net of B.

**Pre-charge (Option A subset):**
- Resolve / create all per-line users in a separate loop *before* the charge
- Validate coupon codes (already done at line 149 today — keep)
- Validate product max-purchase-count limits
- Skip best-effort capacity checks, plugin code, requirement hooks — those become post-charge with reconciliation flagging

**Charge** as today.

**Post-charge (Option B subset):**
- Mark order PAID immediately after successful charge, before the line loop
- Wrap each per-line step in section-level try/catch that records failures into a new `ord_fulfillment_errors` JSON column
- Order ends with `ord_fulfillment_status` = `complete` or `partial`
- Receipt sends are best-effort (already are after Phase 2)
- Admin reconciliation page surfaces partial orders for manual recovery

This eliminates the "user creation strands the order" class of failures (Option A handles those), while accepting that plugin code, event capacity races, and other post-charge work needs reconciliation tooling rather than pre-flight prevention.

---

## 4. Recommendation: Option C

The cost/benefit ratio favors C:

- **Pre-charge user creation** is cheap, doesn't race, and would have prevented order 6457. It's the single change with the highest payoff.
- **Order marked PAID immediately** matches what Stripe says happened. UNPAID-with-charge-id is a contradiction that the current code creates.
- **Section-level try/catch in the loop** prevents one buggy plugin from nuking the entire order.
- **Reconciliation UI** is an admin-facing tool, not customer-facing — small surface, low risk.

Pure Option A (try to pre-validate everything) is too brittle: plugin code and external services don't behave well under dry-run, and capacity races are unsolvable. Pure Option B leaves user-creation failures customer-facing when they don't need to be.

---

## 5. Phasing

### Phase 1 — Pre-charge user resolution loop

Self-contained, smallest payoff. Add a new loop *before* the charge that walks `$cart->items` and resolves/creates the recipient user for each line. Any `User::CreateCompleteNew` failure surfaces to the cart page with a clear message *before* the customer's card is touched. Cache the resolved User objects on the cart item or in a parallel array so the existing post-charge loop can consume them by reference.

**Acceptance:** A new gift-recipient email that triggers a user-creation validation error redirects to `/cart` with an error message and **zero** Stripe activity.

### Phase 2 — Order marked PAID immediately

Move the `$order->set('ord_status', Order::STATUS_PAID)` call from line 614 to right after the successful charge (around line 295). Removes the UNPAID-with-charge-id contradiction in the database for in-flight orders.

**Acceptance:** No code path can land an order in `UNPAID` with `ord_stripe_charge_id` or `ord_paypal_order_id` populated.

### Phase 3 — Section-level try/catch + fulfillment status

Add `ord_fulfillment_status` (enum: `complete`, `partial`, `failed`) and `ord_fulfillment_errors` (JSON text column). Wrap each post-charge section in try/catch that appends to `ord_fulfillment_errors` instead of bubbling. Mark `partial` if any section caught; `complete` if none.

Schema change is two columns on `ord_orders`. Code change is wrapping the existing sections — no new business logic.

**Acceptance:** A simulated exception inside the event-registration block leaves the order PAID with `ord_fulfillment_status='partial'`, `ord_fulfillment_errors` populated, and **R1 still sent**.

### Phase 4 — Admin reconciliation UI

`/admin/admin_orders_reconcile` lists orders with `ord_fulfillment_status != 'complete'`. Each row shows the captured errors and a "retry fulfillment" button that re-runs the failed sections idempotently. Existing `cart_charge_logic` sections need an idempotency review for this to be safe (most are already — `Event::add_registrant` checks for existing registrants, `OrderItem` insertion is fine if guarded, etc.).

This phase is the largest and not strictly required to call the system "atomic." It's the operator tooling that converts incidental failure into a manageable workflow.

---

## 6. Schema changes (Phase 3)

`data/orders_class.php` — add to `$field_specifications`:

```php
'ord_fulfillment_status' => array('type'=>'varchar(20)', 'default'=>'complete'),
'ord_fulfillment_errors' => array('type'=>'text', 'is_nullable'=>true),
```

Constants on `Order`:

```php
const FULFILLMENT_COMPLETE = 'complete';
const FULFILLMENT_PARTIAL  = 'partial';
const FULFILLMENT_FAILED   = 'failed';
```

`ord_fulfillment_errors` stores a JSON array of `{section: string, message: string, time: iso8601}` entries — one append per caught exception. No migration needed for the columns themselves (`update_database` handles them); a backfill that sets existing rows to `complete` is also unnecessary because the default applies.

---

## 7. Out of scope

- **Refund automation for stranded orders.** A real-world reconciliation flow needs the operator to decide refund-vs-fulfill on a case-by-case basis. The admin UI just surfaces the failed sections; refunds happen via the Stripe dashboard (or a future extension).
- **Duplicate-charge prevention.** Submitting the cart form twice in quick succession can already create two orders today. Out of scope here; tracked separately if needed.
- **PayPal subscription webhook reconciliation.** Recurring billing failures are a separate flow handled by `paypal_subscription_webhook.php`. This spec only covers the synchronous checkout path.
- **Plugin sandbox / dry-run framework.** Truly safe pre-validation of plugin code paths would require a sandboxing layer the platform doesn't have. Plugin failures land in `partial` and require operator intervention. If this becomes a recurring pain a future spec can address it.

---

## 8. Concrete artifact: order 6457 on the test site

Order 6457 (status=UNPAID, charge_id=`ch_3TTojtFLAUmEtkvJ0GYgz6Ei`, $20) is the canonical example. Once Phase 2 of this spec ships, that combination becomes impossible. Phase 4's reconciliation UI would have surfaced it for a one-click retry.
