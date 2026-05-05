# Spec: Receipt System Refactor

**Status:** Draft — design decided, two-phase implementation
**Created:** 2026-05-05
**Updated:** 2026-05-05

---

## 1. Background

During investigation of a 500 error on `admin_product_edit`, the receipt system was audited. The original `pro_receipt_*` UI added in 2021 was never wired to delivery, and the FormWriter V2 conversion in October 2025 left a dead reference to `pro_receipt_body` in `$editable_fields` that crashed every product save (since the field was never in `$field_specifications`). That bug was patched separately. This spec defines the proper completion of the receipt feature.

---

## 2. Current State (as of audit)

### Customer-facing emails sent during checkout

| Outcome | Customer email today | Notes |
|---|---|---|
| Single non-event purchase | **None** | Only `single_purchase_notification_emails` admin alert goes out |
| Subscription | **None** | `subscription_created` template exists but is never called |
| Event registration | `event_reciept_content` (per registrant) | Live; carries activation link |
| Event bundle | `event_bundle_content` (per registrant) | Live |
| Free purchase ($0 cart) | None | Falls through the same gaps |

### On-screen confirmation
`cart_confirm.php` shows a per-line item summary built from `$cart->last_receipt`. Does **not** display `pro_after_purchase_message`.

### Dead/latent fields

| Field | In `$field_specifications` | In DB | Wired up | Status |
|---|---|---|---|---|
| `pro_after_purchase_message` | Yes | Yes | No | Defined but unread anywhere — needs wiring |
| `pro_receipt_body` | No | No | No | Removed from `$editable_fields` (the original bug fix) |
| `pro_receipt_template` | No | No | No | Never made it to the model |
| `pro_receipt_subject` | No | No | No | Never made it to the model |
| `evt_after_purchase_message` | **No** | **No** | No | Orphan `$event->set()` call in `admin_event_edit_logic.php:112-113` — guarded by `if($post_vars[...])` so it never fires; column doesn't exist |

### Email-template typo

Four templates were created in 2020 with the misspelling `reciept`:

| Template | In code? |
|---|---|
| `event_reciept_content` | **Yes** — `cart_charge_logic.php:531` |
| `event_deposit_reciept_content` | No |
| `single_donation_reciept` | No |
| `monthly_donation_reciept` | No |

Only one is live; the other three are orphans.

---

## 3. Design Decision: Option 2 (selected)

**Outcome-keyed default template, plus an optional per-product template override (FK to `emt_email_templates`).**

This is the receipt-side analogue of `pro_sbt_subscription_tier_id`: a reusable email template can be referenced from any product, and if no override is set, the system falls back to a sensible default. Per-product per-line copy is supplied through `pro_after_purchase_message` (already on the model).

### Why Option 2 over Option 1

Option 1 (defaults only, no override) would handle the 95% case fine via `pro_after_purchase_message`, but cannot accommodate the case where one specific product needs a wholly different layout (different attachments, different CTAs, custom branding, etc.). Option 2 is a strict superset: the override field costs one column and one resolution branch, and behavior with no overrides set is identical to Option 1.

### Why not a separate `receipt_*` model

Adding a separate "receipt" model parallel to `emt_email_templates` would duplicate the template-storage and template-versioning infrastructure that already works fine. Receipts *are* emails; they belong in `emt_email_templates` and benefit from its existing admin UI, its versioning via `ContentVersion`, and its template DSL (conditionals, variables, footer composition).

---

## 4. Phasing

This spec is implemented in two phases:

- **Phase 1 — EmailTemplate iteration support** (Section 5). A foundational improvement to `includes/EmailTemplate.php` adding `{loop X as Y}...{end}` syntax to the template DSL. Standalone deliverable, useful independently of receipts.
- **Phase 2 — Receipt system refactor** (Sections 6–11). Schema changes, new templates, cart_charge_logic refactor, admin UI, confirmation-page wiring.

Phase 1 must be merged, deployed, and stable in production before Phase 2 work begins. This decouples risk: Phase 1 is a contained engine improvement that touches a load-bearing file but adds a no-op pre-pass for any template not using the new syntax; Phase 2 is a feature build that consumes the new capability. Splitting the phases also means Phase 1 can be reviewed, tested, and shipped on its own merits — its value isn't contingent on receipts.

---

## 5. Phase 1: EmailTemplate `{loop ... as ...}` Iteration

### 5.1 Motivation

The existing email-template engine (`includes/EmailTemplate.php`) supports variable substitution (`*var*`, `*obj->prop*`) and conditional blocks (`{cond} ... {end}`, `{~cond} ... {end}`, `{a == b} ... {end}`). It does **not** support iteration over arrays. Today, code that needs to render N items in an email pre-renders the HTML in PHP and passes a single string variable (e.g., the `event_list` `implode('<br>', ...)` pattern at `cart_charge_logic.php:556`). This pattern leaks presentation logic into business code and prevents admins from customizing per-line markup through the email-template admin UI.

Adding iteration to the DSL removes a class of workaround and benefits any future "render N items in an email" use case — order summaries, monthly digests, batch admin notifications, mailing-list previews, and the unified receipt template that Phase 2 depends on.

### 5.2 Syntax

```
{loop array_key as item_name}
  *item_name->property*
  {item_name->is_gift_to}
    Sent to *item_name->is_gift_to*
  {end}
{end}
```

The `loop ` keyword prefix distinguishes the directive from existing conditionals. Loops nest with each other and with conditionals in any order.

### 5.3 Implementation approach

A pre-pass `_expand_loops($template_string, $values)` runs *before* `_process_conditionals`. By the time conditional and variable processing run, all `{loop ...}{end}` blocks have been expanded inline; the existing passes do not need to know about iteration.

Block matching uses the same stack-based pairing as conditionals — push on `{loop ...}` or `{cond}`, pop on `{end}`. The loop pre-pass only acts on outer-level loop blocks; nested loops and inner conditionals are handled by recursion.

A new `_render_string($template_string, $values)` method wraps the full pipeline (loops → conditionals → variables) so each loop iteration can recursively render its inner body with augmented values:

```php
protected function _render_string($template_string, $values) {
    $template_string = $this->_expand_loops($template_string, $values);
    list($template_string, $set_values) = $this->_process_conditionals($values, $template_string);
    $values = array_merge($values, $set_values);
    $template_string = $this->_substitute_variables($template_string, $values);
    return $template_string;
}

protected function _expand_loops($template_string, $values) {
    // Stack-match {loop X as Y}...{end} blocks at the outer level.
    // For each block:
    //   $array = $this->_process_value($X, $values);
    //   $output = '';
    //   foreach ($array as $element) {
    //       $loop_values = array_merge($values, [$Y => $element]);
    //       $output .= $this->_render_string($inner_block, $loop_values);
    //   }
    //   Replace the block with $output in $template_string.
    return $template_string;
}
```

`fill_template` is refactored to call `_render_string` on the inner template body. The variable-substitution loop currently inline in `fill_template` is extracted into `_substitute_variables($string, $values)` so `_render_string` can call it.

### 5.4 Behavior on edge cases

- **Empty array:** loop body renders zero times; output is empty string.
- **Missing array key:** loop body renders zero times. No error (matches existing conditional behavior for missing keys, which renders the negative branch).
- **Non-array value:** loop body renders zero times. Strict mode would throw; we are lenient to match the existing engine's tolerance for missing/wrong-type values.
- **Nested loops referencing outer loop variable:** works via the recursive `_render_string` call — outer loop's binding is in `$values` when inner loop runs.
- **Conditional inside loop referencing loop-local variable:** works for the same reason.
- **`{loop}` syntax false-matching existing conditionals:** the `loop ` keyword prefix is distinctive. Pre-merge: `grep` all template bodies in `emt_email_templates` and source-controlled migration seeds for `{loop` to confirm no collisions.

### 5.5 Risk

`EmailTemplate.php` is used by every email send. The `_expand_loops` pre-pass is a no-op for templates that don't contain `{loop ...}` (the regex finds nothing, the function returns the input unchanged). Blast radius for existing templates is zero. Regression tests cover every currently-seeded template name to confirm.

### 5.6 Phase 1 acceptance criteria

Phase 1 is complete when **all** of the following are true:

1. `_render_string()`, `_substitute_variables()`, and `_expand_loops()` are implemented in `EmailTemplate.php`.
2. `fill_template()` is refactored to call `_render_string()` on the inner template body.
3. `tests/email/template_iteration_test.php` exists and passes, covering: simple loop, nested loop, loop with conditionals inside, conditional wrapping a loop, empty array, missing key, non-array value, loop-local variable reference, multi-level `*item->obj->prop*` resolution inside a loop.
4. Regression tests pass for every currently-seeded template name in `emt_email_templates` — unchanged rendering output for templates without loops.
5. `docs/email_system.md` documents the `{loop ... as ...}` syntax with examples and edge-case behavior.
6. The change is deployed to the test site and at least one production node, with one full test cycle (a real-world checkout, password reset, etc.) before Phase 2 begins.

### 5.7 Phase 1 files

| File | Change |
|---|---|
| `includes/EmailTemplate.php` | Add `_expand_loops()` pre-pass; extract `_substitute_variables()` from `fill_template`; add `_render_string()` to wrap full pipeline; refactor `fill_template` to call `_render_string` |
| `tests/email/template_iteration_test.php` | **NEW** — covers the cases listed in 5.6 |
| `docs/email_system.md` | Document `{loop X as Y}` syntax |

### 5.8 Phase 1 implementation order

1. Read `EmailTemplate.php::fill_template` and confirm the variable-substitution block can be cleanly extracted into `_substitute_variables($string, $values)` without changing observable behavior.
2. Extract `_substitute_variables`. Run the existing email test suite — should be unchanged.
3. Add `_render_string` as a thin wrapper calling `_process_conditionals` and `_substitute_variables`. Refactor `fill_template` to use it. Re-run tests — still unchanged.
4. Implement `_expand_loops` as a no-op for templates without `{loop`. Wire it into `_render_string` as the first step. Re-run tests.
5. Implement loop expansion. Add new tests in `tests/email/template_iteration_test.php`.
6. Update `docs/email_system.md`.
7. Deploy. Observe.

Each step is independently verifiable; later steps don't break earlier ones if rolled back.

---

## 6. Phase 2: Receipt System Architecture

The remainder of this spec describes Phase 2 work, which assumes Phase 1 has shipped.

### 6.1 Routing model

Three rules govern who gets what:

**R1 — Default order receipt: one email to the billing user, always.**

The billing user receives a single email containing the full financial summary and one block per line item. For items where the billing user is also the recipient (no per-line `data['email']`, or `data['email']` matches billing after `strtolower(trim(...))` canonicalization), activation/welcome/digital-link content renders inline. For items with a different recipient, the line shows a "Sent as a gift to {email}" acknowledgment — no activation token, no welcome content (the token is per-user and would let the buyer act on the recipient's behalf if leaked). Generic per-product info like a `pro_digital_link` URL still appears so the buyer knows what they purchased.

In the common case (no per-line `data['email']` anywhere) this is simply one email per purchase.

**R2 — Per-registrant activation email for event and bundle items only.**

When a line item has `data['email']` ≠ billing email AND the outcome is `event` or `bundle`, send an additional email to that recipient containing only their items' activation content (event registrant link, event list). No price, no full order detail. This preserves the existing event-registration flow where one buyer can register multiple distinct people in a single purchase.

`subscription` and `digital` items going to a non-billing recipient do **not** trigger this email — that's the current behavior of the system and changing it is out of scope. Buyers must forward digital links manually; subscription tier grants reach the recipient via login, not via email.

R2 reuses the same `purchase_receipt_default` template as R1, rendered in "registrant view" mode (`is_billing = false`) which hides price information.

**R3 — Per-product additional email (opt-in), to the billing user.**

For each *distinct* product in the order whose product record has either `pro_after_purchase_message` or `pro_emt_receipt_template_id` set, send one additional email to the **billing user**. Template = the override if set; otherwise the generic per-product wrapper. Variables include `after_purchase_message` (which may be empty if only the override was set).

This email always goes to the buyer regardless of whether any line item is a gift. It's admin-authored content for whoever is paying — typically thank-you or follow-up copy. There is no per-recipient routing or `is_gift` variable; admins author one voice. A product with neither field set never produces this email. **Dedupe rule:** at most one per (product, order) — quantity > 1 or multiple cart entries for the same product collapse to a single send.

**R4 — Stale FK fallback.**

If `pro_emt_receipt_template_id` points to a missing or soft-deleted template, the additional email still fires using the generic wrapper. The override is best-effort, never load-bearing.

### 6.2 Templates

Two templates carry the load:

- `purchase_receipt_default` — the default order receipt. Uses inline `{loop line_items as line}` (Phase 1 capability) to iterate over the order's lines, with conditional blocks per line to render the appropriate content (event activation, subscription welcome, digital download link, gift acknowledgment, etc.).
- `purchase_receipt_product_default` — the generic wrapper for the per-product additional email. Renders product name and `after_purchase_message`.

Customers can override `purchase_receipt_product_default` per-product via `pro_emt_receipt_template_id`. The default `purchase_receipt_default` is a single template; if a site needs heavily different default behavior they edit its body directly.

### 6.3 Standard template variables

`purchase_receipt_default` receives:

| Variable | Type | Notes |
|---|---|---|
| `recipient` | array | The recipient's user data (`export_as_array()`) |
| `is_billing` | bool | True if recipient is the billing user |
| `order` | array | Order data |
| `order_total` | numeric | Used only when `is_billing` |
| `currency_symbol` | string | |
| `line_items` | array of arrays | One entry per relevant line item — for billing user, all items; for gift recipient, only theirs |
| `coupon_codes_used` | array of strings | Only present when `is_billing` and at least one coupon was applied — drives the footnote |

Each entry in `line_items` carries:

| Field | Notes |
|---|---|
| `product_name` | Including version name |
| `quantity` | |
| `price` | After discount; omitted when not billing user |
| `is_gift_to` | Email/name of gift recipient if billing user is seeing a gift line; null otherwise |
| `outcome` | One of `event`, `bundle`, `subscription`, `digital`, `plain` |
| `event_registrant_id` | Set for `event` outcome (gift line for billing user: omitted) |
| `act_code` | Set for outcomes requiring activation (gift line for billing user: omitted) |
| `event_name` | Set for `event` |
| `event_list` | Set for `bundle` |
| `digital_link` | Set for `digital` (visible to billing user even on gift lines so they can forward) |
| `subscription_active` | Set for `subscription` |

`purchase_receipt_product_default` (and any per-product override) receives:

| Variable | Type |
|---|---|
| `recipient` | array — always the billing user under Scope B |
| `product_name` | string |
| `after_purchase_message` | string (HTML); empty if unset |
| `order_item` | array — one representative order_item for this product (the first line in the order) |
| `order` | array |

There is no `is_gift` variable. Per-product custom email always targets the billing user, so admins write one voice ("thanks for your purchase").

### 6.4 Template resolution

```php
function _resolve_receipt_template(Product $product, $default_name) {
    $override_id = $product->get('pro_emt_receipt_template_id');
    if ($override_id) {
        $tpl = new EmailTemplateStore($override_id, TRUE);
        if ($tpl->key && !$tpl->get('emt_delete_time')) {
            return $tpl->get('emt_name');
        }
    }
    return $default_name;
}
```

### 6.5 Migration of existing templates

The 2020-era `event_reciept_content`, `event_bundle_content`, and (orphaned) `subscription_created` templates are soft-deleted in a single migration step, with the migration release notes flagging the porting path: "if you customized one of these, copy your customized body into `purchase_receipt_default` (which now handles all outcomes via conditional blocks)."

Existing sites that customized those templates have a one-time port chore. New installs skip the issue.

The three orphan typo templates (no callers anywhere) are also soft-deleted unconditionally.

---

## 7. Phase 2: Scenario Validation Matrix

This section sanity-checks the routing rules against every combination of product type, recipient configuration, cart shape, and per-product custom config.

### 7.1 Dimensions

- **Outcome type** (per item): `EVENT` (`pro_evt_event_id` set), `BUNDLE` (`pro_grp_group_id` set), `SUBSCRIPTION` (`prv_*` is_subscription), `DIGITAL` (`pro_digital_link` set, no event/bundle/sub), `PLAIN` (none of the above)
- **Recipient** (per item): `SELF` (no `data['email']` or matches billing after canonicalization), `GIFT` (different from billing)
- **Custom config** (per product): `NONE`, `MSG` (only `pro_after_purchase_message`), `TPL` (only `pro_emt_receipt_template_id`), `BOTH`
- **Cart shape:** 1 item; N items same recipient; N items mixed recipients
- **Payment path:** `stripe_regular`, `stripe_checkout`, `paypal_one_time`, `paypal_subscription`, `free` — affects whether code reaches the receipt block, never affects routing once it does

### 7.2 Single-item carts

Notation: B = billing user; R1 = a non-billing line recipient. "→B" = email to B.

| # | Outcome | Recipient | Config | Emails | Notes |
|---|---|---|---|---|---|
| 1.1 | PLAIN | SELF | NONE | 1: →B default | Most basic case |
| 1.2 | EVENT | SELF | NONE | 1: →B default (activation inline) | |
| 1.3 | BUNDLE | SELF | NONE | 1: →B default (event list with activation) | |
| 1.4 | SUBSCRIPTION | SELF | NONE | 1: →B default (subscription-active confirmation inline) | Replaces orphaned `subscription_created` |
| 1.5 | DIGITAL | SELF | NONE | 1: →B default (download link inline) | |
| 1.6 | any | SELF | NONE | (free, $0) — 1 email, same as paid | |
| 1.7 | PLAIN | SELF | MSG | 2: →B default, →B per-product (msg) | |
| 1.8 | EVENT | SELF | TPL | 2: →B default (activation), →B per-product (override template) | |
| 1.9 | EVENT | SELF | BOTH | 2: →B default, →B per-product (override + msg variable) | |
| 1.10 | EVENT | GIFT | NONE | 2: →B default (gift ack, no activation token), →R1 activation email | R2 fires for events |
| 1.11 | EVENT | GIFT | MSG | 3: →B default (gift ack), →B per-product (msg goes to buyer), →R1 activation | Per-product always to B |
| 1.12 | BUNDLE | GIFT | NONE | 2: →B default (gift ack), →R1 activation (event list) | R2 fires for bundles |
| 1.13 | SUBSCRIPTION | GIFT | NONE | **1**: →B default (notes "tier granted to R1"). R1 receives no email. | Existing limitation preserved |
| 1.14 | DIGITAL | GIFT | NONE | **1**: →B default (gift ack + download link visible to buyer for forwarding). R1 receives no email. | Existing limitation preserved |
| 1.15 | PLAIN | GIFT | NONE | **1**: →B default (gift ack only). R1 receives no email. | Nothing to convey to R1 |

### 7.3 Multi-item carts, single recipient (all to billing user)

| # | Items | Config | Emails |
|---|---|---|---|
| 2.1 | EVENT-SELF + PLAIN-SELF | both NONE | 1: →B default |
| 2.2 | EVENT-SELF + PLAIN-SELF | EVENT has MSG | 2: →B default, →B per-product (event msg) |
| 2.3 | EVENT-SELF + SUBSCRIPTION-SELF | both NONE | 1: →B default (both inline) |
| 2.4 | 3× EVENT-SELF (different products) | all MSG | 4: →B default, 3× →B per-product (one per distinct product) |
| 2.5 | 2× same EVENT-SELF (qty=2 in one cart entry, or two cart entries same product) | MSG | 2: →B default (line shows "2 × $X"), →B per-product (one — deduped per R3) |

### 7.4 Multi-item carts, mixed recipients

| # | Items | Config | Emails |
|---|---|---|---|
| 3.1 | EVENT-SELF + EVENT-GIFT-R1 | both NONE | 2: →B default (own activation + gift ack for R1's), →R1 activation (their event only) |
| 3.2 | EVENT-GIFT-R1 + EVENT-GIFT-R1 (same R1, different events) | both NONE | 2: →B default (two gift acks), →R1 activation (both events) |
| 3.3 | EVENT-GIFT-R1 + EVENT-GIFT-R2 | both NONE | 3: →B default (two gift acks), →R1, →R2 |
| 3.4 | EVENT-SELF + EVENT-GIFT-R1 | both have custom config | 4: →B default, →B per-product for SELF event, →B per-product for GIFT event, →R1 activation. (Per-product always to B, deduped per product.) |
| 3.5 | EVENT-GIFT-R1 + PLAIN-GIFT-R1 (gift to same person) | both BOTH | 3: →B default (two gift acks), →B per-product event, →B per-product plain. R1 gets activation only for the event (PLAIN doesn't trigger R2). |
| 3.6 | DIGITAL-GIFT-R1 + EVENT-GIFT-R1 | both NONE | 2: →B default (gift acks; download link visible to B), →R1 activation (event only — DIGITAL doesn't trigger R2 for R1) |

### 7.5 Edge cases and resolutions

1. **What goes into B's default email for a gift item?** Gift acknowledgment line ("Sent as a gift to R1's email"). No `act_code`, no `event_registrant_id`, no welcome content. Generic per-product info (e.g., `pro_digital_link` URL) does appear, since it's not user-bound and the buyer needs to know what they purchased and may need to forward.

2. **Per-product email for a gift item — to whom?** **Always to the billing user.** The custom message is admin-authored copy for the buyer; there's no per-recipient routing of this email under Scope B. Admins write one voice.

3. **Email canonicalization.** Compare with `strtolower(trim(...))` on both sides. The cart already canonicalizes `billing_user['billing_email']`; the receipt routing applies the same to per-line `data['email']` before deciding `is_gift`.

4. **Empty `data['email']`.** Already collapses to billing user (cart_charge_logic.php:322-324). Treated as `is_gift = false` — same email goes to billing user only.

5. **Same product appearing N times for same recipient.** Each cart entry → one order_item. Default email summarizes all lines. Per-product additional email is deduped per (product, order) — see R3.

6. **Product with both event registration and tier grant.** Both happen (current code: event registration first, then `SubscriptionTier::handleProductPurchase`). The default email's line block renders both via conditional sub-blocks (event activation + subscription confirmation).

7. **Stripe Checkout return path.** `session_id` flow loads the existing order rather than creating one; items still process; receipts fire at the end. Same routing.

8. **PayPal subscription path.** `paypal_subscription_id` is stored on order_items; receipts fire from the same end-of-function block. Same routing.

9. **Stale `pro_emt_receipt_template_id`.** Helper resolves to the generic wrapper; per-product email still fires. Never crash.

10. **Failed payment.** `STATUS_ERROR` paths return early via `_checkout_error()`. No receipts. Already in code.

11. **User created during checkout.** By the time receipts fire, all per-line users exist (created earlier in cart_charge_logic). No issue.

12. **Subscription/digital giftee gap (preserved).** When a non-billing recipient is on a `SUBSCRIPTION` or `DIGITAL` line, no email goes to them. This matches today's behavior. Consequence: the buyer must convey the digital link manually, and subscription giftees discover their tier on next login. Documented as a known limitation; not solved by this spec.

### 7.6 Open questions (all resolved)

1. **Duplicate per-product emails when the same product appears N times.** **Resolved: dedupe by `product_id` within an order.** Per-product email always goes to the billing user, so the dedupe key is just `product_id`. Quantity > 1 or multiple cart lines for the same product collapse to one send.

2. **Quantity > 1 within a single cart line.** A cart entry can carry quantity > 1 with one shared `data` dict (one order_item). Default email line shows "3 × $X". Per-product additional email fires at most once per product (per R3 dedupe). Falls out of the model.

3. **Coupon/discount visibility in default email.** **Resolved: total only.** Each line displays its post-discount price; the bottom of the receipt shows the order total. When at least one coupon was applied, a small footnote names the coupon code(s) used. No per-line discount accounting in the default template — admins who need it can author an override template.

---

## 8. Phase 2: Schema Changes

### 8.1 Product model — `data/products_class.php`

Add to `$field_specifications`:

```php
'pro_emt_receipt_template_id' => array('type'=>'int4', 'is_nullable'=>true),
```

Add to `$foreign_key_actions`:

```php
'pro_emt_receipt_template_id' => ['action' => 'null'],
```

If an admin deletes a referenced template, the product's override quietly clears and falls back to the default — no broken references.

### 8.2 Editable fields — `adm/logic/admin_product_edit_logic.php`

Add `pro_emt_receipt_template_id` to `$editable_fields` at line 113.

### 8.3 No migration needed for the column

Schema management is automatic via `update_database`. Adding the field to `$field_specifications` is the entire schema change.

### 8.4 Template migration (data, not schema)

```php
// migration_receipt_templates_unify.php
//
// 1. Insert the new default templates (idempotent)
$migration['sql'][] = "
    INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
    SELECT 'purchase_receipt_default', 2, 'Your purchase from *site_name*', '<TEMPLATE BODY>', now(), now()
    WHERE NOT EXISTS (SELECT 1 FROM emt_email_templates WHERE emt_name = 'purchase_receipt_default')
";

$migration['sql'][] = "
    INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
    SELECT 'purchase_receipt_product_default', 2, '*product_name*', '<TEMPLATE BODY>', now(), now()
    WHERE NOT EXISTS (SELECT 1 FROM emt_email_templates WHERE emt_name = 'purchase_receipt_product_default')
";

// 2. Soft-delete the legacy and orphan templates (preserves customization data)
$migration['sql'][] = "
    UPDATE emt_email_templates
    SET emt_delete_time = now()
    WHERE emt_name IN (
        'event_reciept_content',
        'event_bundle_content',
        'subscription_created',
        'event_deposit_reciept_content',
        'single_donation_reciept',
        'monthly_donation_reciept'
    ) AND emt_delete_time IS NULL
";
```

The `WHERE NOT EXISTS` guard makes the inserts idempotent. The soft-delete preserves the bodies so admins can copy any customization forward. Release notes accompany this migration: "If you previously customized `event_reciept_content`, `event_bundle_content`, or `subscription_created`, your changes are still in the database but those templates are no longer called. Copy your customizations into the new `purchase_receipt_default` (which now uses `{loop line_items as line}` to render each line)."

---

## 9. Phase 2: Code Changes

### 9.1 `logic/cart_charge_logic.php`

Add a top-of-file helper:

```php
function _resolve_receipt_template(Product $product, $default_name) {
    require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
    $override_id = $product->get('pro_emt_receipt_template_id');
    if ($override_id) {
        $tpl = new EmailTemplateStore($override_id, TRUE);
        if ($tpl->key && !$tpl->get('emt_delete_time')) {
            return $tpl->get('emt_name');
        }
    }
    return $default_name;
}
```

**Replace existing per-registrant sends.** Remove the direct `EmailSender::sendTemplate` calls at lines 535 (`event_reciept_content`) and 559 (`event_bundle_content`). Activation content moves into the new default template via conditional blocks inside the `{loop}`.

**Capture per-line data during the existing item loop.** During the loop that creates order_items, accumulate `$line_summaries[$cart_key]`:

```php
$billing_email_canon = strtolower(trim($billing_user->get('usr_email')));
$line_email_canon = !empty($data['email'])
    ? strtolower(trim($data['email']))
    : $billing_email_canon;
$is_gift = ($line_email_canon !== $billing_email_canon);

$line_summaries[$key] = [
    'order_item' => $order_item,
    'product' => $product,
    'cart_data' => $data,
    'price' => $price,
    'discount' => $discount,
    'recipient_email' => $line_email_canon,
    'recipient_user' => $user,
    'is_gift' => $is_gift,
    'outcome' => $product->get('pro_evt_event_id') ? 'event'
                : ($product->get('pro_grp_group_id') ? 'bundle'
                : ($product_version->is_subscription() ? 'subscription'
                : ($product->get('pro_digital_link') ? 'digital'
                : 'plain'))),
    'act_code' => $user->get('usr_act_code'),
    'event_registrant_id' => isset($event_registrant) ? $event_registrant->key : null,
    'event_name' => isset($event) ? $event->get('evt_name') : null,
    'event_list' => isset($event_list) ? $event_list : null,
    'digital_link' => $product->get('pro_digital_link'),
];
```

**R1 — Send default order receipt to billing user.** After the loop, build `line_items` as a plain array of dicts (gift lines: `is_gift_to` set, `act_code` and `event_registrant_id` omitted; non-gift lines: full activation block). The template iterates with `{loop line_items as line}` (Phase 1 capability). Send `purchase_receipt_default` to billing user with `is_billing = true`.

**R2 — Send per-registrant activation emails.** Group `$line_summaries` by `recipient_email`, filtering to lines where `is_gift = true` AND `outcome IN ('event', 'bundle')`. For each non-empty group, send `purchase_receipt_default` to that recipient with `is_billing = false`, `line_items` containing only that recipient's lines (no price, no gift acks, full activation block). Same template, different fill data — the `{is_billing}` conditional and the loop body's per-line conditionals handle the differences.

**R3 — Send per-product additional emails to billing user.** Iterate `$line_summaries`, dedupe by `product_id`, and for each distinct product where `pro_after_purchase_message` or `pro_emt_receipt_template_id` is set, send `_resolve_receipt_template($product, 'purchase_receipt_product_default')` to billing user with `recipient = billing_user->export_as_array()`. No `is_gift` variable.

### 9.2 `views/cart_confirm.php`

Build `pro_after_purchase_message` into the receipts array in `cart_charge_logic.php`:

```php
$receipts[$key+1]['after_purchase_message'] = $product->get('pro_after_purchase_message');
```

Render it under each line item's name in `cart_confirm.php`. Treat as HTML (admin-authored).

### 9.3 `adm/admin_product_edit.php`

In the advanced section, add:

```php
require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
$templates = new MultiEmailTemplateStore(['deleted' => false], ['emt_name' => 'ASC']);
$templates->load();
$tpl_options = ['' => '-- Use system default --'];
foreach ($templates as $tpl) {
    $tpl_options[$tpl->key] = $tpl->get('emt_name');
}
$formwriter->dropinput('pro_emt_receipt_template_id', 'Receipt template (override)', [
    'options' => $tpl_options,
    'helptext' => 'Optional. When set, the per-product receipt email uses this template. Leave empty for the system default.',
]);

$formwriter->textbox('pro_after_purchase_message', 'After-purchase message', [
    'rows' => 4,
    'htmlmode' => 'yes',
    'helptext' => 'Shown on the confirmation page; also triggers a separate per-product email after purchase.',
]);
```

### 9.4 `adm/logic/admin_event_edit_logic.php`

Delete lines 112-113 — the orphan `evt_after_purchase_message` set is dead code (column doesn't exist, form has no input, set is guarded so it never fires).

---

## 10. Phase 2: Files Touched

| File | Change |
|---|---|
| `data/products_class.php` | Add `pro_emt_receipt_template_id` field spec + `$foreign_key_actions` entry |
| `adm/logic/admin_product_edit_logic.php` | Add to `$editable_fields` |
| `adm/admin_product_edit.php` | Add receipt-template dropdown + after-purchase message textbox to advanced section |
| `adm/logic/admin_event_edit_logic.php` | **Delete** lines 112-113 (dead `evt_after_purchase_message` set) |
| `logic/cart_charge_logic.php` | Add `_resolve_receipt_template()`; capture per-line data; remove direct event/bundle sends; send `purchase_receipt_default` to billing user and per-event/bundle gift recipient; send per-product additional emails |
| `views/cart_confirm.php` | Display `after_purchase_message` per receipt row |
| `migrations/migration_receipt_templates_unify.php` | **NEW** — insert two new default templates idempotently; soft-delete six legacy/orphan templates |
| `docs/email_system.md` | Add a "Receipt templates" section listing the two standard receipt-template names and their variables |
| `docs/admin_pages.md` (or similar) | Document the per-product receipt-template override |

---

## 11. Phase 2: Implementation Order

Begins only after Phase 1 (Section 5) has shipped and is stable.

1. **Schema:** add `pro_emt_receipt_template_id` to `Product`. Run `update_database`. Verify column appears.
2. **Templates:** write the unify migration and run it. Verify `purchase_receipt_default` and `purchase_receipt_product_default` appear; verify legacy templates are soft-deleted. Hand-test the new default's `{loop line_items as line}` body with a sample fill to confirm rendering matches expectations.
3. **Wire `cart_charge_logic.php`:** add `_resolve_receipt_template()` helper, capture per-line summaries, remove the existing event/bundle template sends, implement R1/R2/R3 routing. Test all five payment paths and at least scenarios 1.1, 1.10, 2.1, 3.3, 3.4 from the matrix.
4. **Admin UI:** add the receipt-template dropdown and the after-purchase-message textbox to `admin_product_edit.php`. Verify save/load.
5. **Confirm view:** display per-line `after_purchase_message` on `cart_confirm.php`. Spot-check on the test site.
6. **Delete dead `evt_after_purchase_message` code** in `admin_event_edit_logic.php`.
7. **Docs:** update `email_system.md` with the two receipt template names, their variable schemas, and the conditional blocks supported in `purchase_receipt_default`.

Each step is independently verifiable; later steps don't break earlier ones if rolled back.

---

## 12. Out of Scope

- **PayPal subscription receipt path** beyond what falls out automatically from the new routing — webhook-driven receipts (e.g., billing-cycle renewals) are a separate flow handled by `paypal_subscription_webhook.php` and not touched here.
- **Refund / cancellation receipts** — handled today by `change_tier_logic.php` lifecycle templates; no change.
- **Per-event receipt customization** (resurrecting `evt_after_purchase_message` properly with a column, a form field, and a flow into the event-receipt template) — possible follow-up if a real need surfaces. Not in scope here.
- **Donation flow** — the three orphan donation templates get soft-deleted. If donations come back as a feature, they get fresh templates with correct names.
- **Subscription/digital giftee emails** — the existing gap (non-billing recipients of `SUBSCRIPTION` or `DIGITAL` items receive nothing) is preserved, not closed. See Section 7.5 #12.
