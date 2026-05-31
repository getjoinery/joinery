# Move per-user subscription maintenance off page-view into a scheduled task

## Overview

`utils/registrant_maintenance.php` and `utils/order_maintenance.php` perform
**Stripe subscription reconciliation and write an `EventLog` audit row** — real,
mutating maintenance work — but they run **synchronously, inline, on a GET page
render**. They are `include()`d into request logic, not driven by the cron
runner. This spec moves that work to the scheduled-task system where it belongs,
**consolidates the two files into a single global task with the most
Stripe-call-efficient reconciliation path**, and removes the inline includes (and
the GET-mutation opt-ins that were added as a stopgap).

This is a follow-up to `admin_logic_get_submission_guard_fix.md`. That fix added
`SystemBase::$allow_get_mutation` opt-ins around these two files so the new
GET-is-read-only tripwire wouldn't fire on them. The opt-in is a marker, not a
cure: the correct fix is for these not to mutate on a GET at all.

## How it works today (the problem)

Both files depend on a `$user` from the **including scope** (per-user work) and
are pulled in via `include()`:

| Include site | Files included | Audience |
|---|---|---|
| `adm/logic/admin_user_logic.php:54-55` | `registrant_maintenance` + `order_maintenance` | admin views a user |
| `logic/profile_logic.php:45` | `registrant_maintenance` | a user views their own profile |

What each does (per the included `$user`):

- **`registrant_maintenance.php`** — writes an `EventLog`
  (`evl_event = 'event_registrant_maintenance'`), loads
  `MultiEventRegistrant` for `$user->key`, and for each registration backed by a
  subscription order item calls
  `StripeHelper::update_subscription_in_order_item()`. Marks the `EventLog`
  success at the end.
- **`order_maintenance.php`** — loads `MultiOrder` for `$user->key` and calls
  `StripeHelper::update_all_subscriptions_in_order()` on each.

Consequences:

1. **Mutation on a GET.** Both `save()` (orders, order items, EventLog) during a
   page render. This is what tripped the tripwire; it is currently silenced with
   `$allow_get_mutation`.
2. **A Stripe round-trip on every page view.** Reconciliation hits the Stripe API
   inline, adding latency to a page that is only meant to *display* state.
3. **Audit-log noise.** One `EventLog` row is written **per page view**, not per
   maintenance run.
4. **A latent permission conflict.** `registrant_maintenance.php` calls
   `$session->check_permission(10)` near the top. That is fine under
   `admin_user_logic` (admins), but `profile_logic` serves the **non-admin**
   profile page — a permission-0 user hitting that `include` trips the level-10
   check, which redirects to login. Either the profile page is silently broken
   for normal users or this path never really executes for them. Resolved by
   removing the include (see step 5).

## Key facts established during analysis

These drive the consolidation and are confirmed against the code:

- **Both files funnel through one method.**
  `update_all_subscriptions_in_order($order)` (StripeHelper.php:838) simply loops
  the order's items calling `update_subscription_in_order_item($order_item)`
  (StripeHelper.php:846). `registrant_maintenance` calls the same per-item method
  directly. **The subscription order item is the atomic reconciliation unit**;
  everything else is just two different ways of reaching the same set.
- **The order-walk is a superset of the registrant-walk.** Every subscription
  order item belongs to an order, so iterating subscription order items globally
  covers everything both files reached. The registrant file's only unique output
  is the `EventLog` row (which this spec collapses to one-per-run anyway).
- **A global filter already exists.**
  `MultiOrderItem(['is_active_subscription' => true])` resolves to
  `odi_is_subscription = TRUE AND odi_subscription_cancelled_time IS NULL AND
  odi_status = OrderItem::STATUS_PAID` (order_items_class.php:396-398). That is
  exactly the minimal set worth reconciling — paid, not-yet-cancelled
  subscriptions — with no order/registrant walk and no scanning of
  non-subscription rows.
- **A bulk-list wrapper already exists.** `StripeHelper::get_subscriptions($params)`
  (StripeHelper.php:818) wraps `$this->stripe->subscriptions->all($params)`.
  Stripe's list endpoint returns up to 100 subscriptions per page.
- **`update_subscription_in_order_item()` has four other callers** that must keep
  working unchanged: `adm/admin_user.php:333`, `adm/logic/admin_order_logic.php:72`,
  and `data/order_items_class.php:203` & `:287`.

## Goal

Run subscription reconciliation as a **single global scheduled task** on the cron
runner, the same way `SyncPaypalSubscriptions` already syncs PayPal
subscriptions. The task reconciles **all** active Stripe subscriptions in the
fewest Stripe API calls. Remove the two inline `include()`s and the
`$allow_get_mutation` opt-ins; delete the two util files. Page logic returns to
read-only.

`tasks/SyncPaypalSubscriptions.{json,php}` is the reference pattern and the
conceptual sibling (it is the PayPal half of the same job).

## Approach

### 1. Refactor StripeHelper to separate the API fetch from the local write

`update_subscription_in_order_item()` currently does two things in one method: a
Stripe `retrieve()` **and** the local field writes (`odi_subscription_status`,
`odi_subscription_period_end`, `odi_subscription_cancelled_time`, then `save()`).
To allow bulk reconciliation, split the local-write half into its own method that
takes a **pre-fetched** Stripe subscription object and performs no API call:

```php
// New: no Stripe API call — applies an already-fetched subscription to the row.
public function apply_subscription_to_order_item($order_item, $stripe_subscription) {
    // (the writes currently at StripeHelper.php:851-861: canceled_at,
    //  period_end, status, save())
}

// Refactored: retrieve + apply. Existing four callers keep their exact behavior.
public function update_subscription_in_order_item($order_item) {
    if ($order_item->get('odi_is_subscription')) {
        try {
            $stripe_subscription = $this->get_subscription($order_item->get('odi_stripe_subscription_id'));
            $this->apply_subscription_to_order_item($order_item, $stripe_subscription);
            return $stripe_subscription;
        } catch (Exception $e) {
            return false; // unchanged fail-silent behavior
        }
    }
}
```

This is the **only** change to reconciliation logic, and it preserves the exact
field-write behavior — the per-record writes are not rewritten, only made
callable with a subscription that was fetched in bulk.

### 2. New scheduled task: `ReconcileStripeSubscriptions` (global, bulk-list)

Create `tasks/ReconcileStripeSubscriptions.json` + `.php` following
`docs/scheduled_tasks.md` and the `SyncPaypalSubscriptions` shape. It implements
`ScheduledTaskInterface` **and** `ScheduledTaskDryRunnable`.

`run(array $config)`:

1. Skip cleanly when `StripeHelper::is_initialized()` is false
   (`status => 'skipped'`), mirroring the current guards. On a non-Stripe dev
   this is a no-op.
2. Load the local working set:
   `new MultiOrderItem(['is_active_subscription' => true])`. If empty, return
   `status => 'skipped'`.
3. **Bulk-fetch from Stripe in pages**, not one retrieve per item. Page through
   `get_subscriptions(['status' => 'all', 'limit' => 100, ...])` using Stripe's
   pagination (`starting_after` cursor), building a map keyed by Stripe
   subscription id. `status => 'all'` is required so newly-canceled subscriptions
   are visible (the default list omits canceled). Because this platform creates
   every subscription on the account, the account's subscription set ≈ the local
   set, so paging is strictly cheaper than N individual retrieves
   (~N/100 calls vs N).
4. For each local order item, look up its `odi_stripe_subscription_id` in the
   map and call `apply_subscription_to_order_item($order_item, $sub)`. Count
   processed / changed / missing (id present locally but absent from the Stripe
   list → log + error counter).
5. Write **one** `EventLog` summary row per run
   (`evl_event = 'stripe_subscription_reconciliation'`,
   `evl_usr_user_id = User::USER_SYSTEM`, success flag, `evl_note` = the counts),
   not one row per record.
6. Return `array('status' => …, 'message' => "Reconciled N, changed C, errors E")`.

`dryRun(array $config)`: do steps 1-3 and the map lookups, but **skip
`apply_…`/`save()` and the EventLog write**. Return a message and optional `html`
preview of what *would* change (e.g. items whose status/period_end differ from
Stripe).

**Cadence.** `default_frequency: daily` (match `SyncPaypalSubscriptions`). Stripe
webhooks are the authoritative real-time path; this task is the periodic backstop
and does not need to be frequent. Document this in the `.json` description the way
the PayPal task's description does.

### 3. Remove the inline includes

- `adm/logic/admin_user_logic.php:54-55` — delete both `include()`s.
- `logic/profile_logic.php:45` — delete the `registrant_maintenance` include.

After removal, neither page mutates on render. Subscription state shown is as of
the last task run (see Decisions).

### 4. Retire the opt-ins and delete the util files

- The per-user reconciliation now lives in the task (driven off the global filter
  and `apply_subscription_to_order_item`), so **delete**
  `utils/registrant_maintenance.php` and `utils/order_maintenance.php`.
- Their `SystemBase::$allow_get_mutation = true; … finally …` wrappers, and the
  ones in `adm/logic/admin_user_logic.php` that guarded the includes, go away —
  there is no longer a GET mutation to opt in. Re-grep `allow_get_mutation = true`
  afterward to confirm only the genuinely intentional GET-action sites remain
  (admin delete/toggle links, `cart_charge` payment return, `account_edit` photo
  actions, the survey/question/product-edit admin logic).

### 5. Resolve the profile permission conflict

Once `profile_logic`'s include is gone, the level-10 `check_permission` it pulled
in disappears with it. Verify a permission-0 user can load `/profile` cleanly.

## Decisions (previously open questions, now resolved)

- **Consolidate to one task, driven off subscription order items.** Do **not**
  port the order-walk and registrant-walk separately. The single global filter
  `MultiOrderItem(['is_active_subscription' => true])` is the minimal complete
  set; the order/registrant iteration was incidental to the per-user framing.
- **Most-efficient Stripe calls: bulk list, not per-item retrieve.** Page
  `subscriptions->all(status=all, 100/page)` and reconcile locally. This
  **intentionally overrides** the original non-goal of "don't touch StripeHelper
  reconciliation logic" — the only logic change is extracting
  `apply_subscription_to_order_item()` so a bulk-fetched subscription can be
  applied; per-record write behavior is unchanged.
- **On-view freshness.** Moving to cron means a user/admin sees subscription
  state as of the last task run, not live. Webhook-driven state + the daily
  backstop is considered sufficient; a manual "Refresh subscription status"
  POST action on the admin user page is **not** included in this spec. (If later
  desired, it would be a deliberate POST that calls
  `update_subscription_in_order_item()` for that one user's items — a POST
  mutation, never a GET side-effect.)

## Non-goals

- **Rewriting per-record reconciliation field logic.** The status/period_end/
  canceled_at writes are preserved verbatim; only their call site moves and the
  fetch is batched.
- **The PayPal sync.** `SyncPaypalSubscriptions` already exists; this is the
  Stripe counterpart, not a rework of PayPal.
- **A manual reconcile-now button.** Explicitly deferred (see Decisions).

## Testing

- Task runs under CLI: `is_initialized()` false on a non-Stripe dev →
  `status => skipped`, no-ops cleanly; with Stripe configured → reconciles via
  paged list and writes **one** `EventLog` row.
- **Call-count check:** with K active subscription order items, confirm the run
  makes ~ceil(K/100) Stripe list calls, not K retrieves.
- Dry-run reports intended changes (and optional HTML preview) without writing
  any order item or EventLog row.
- The four existing callers of `update_subscription_in_order_item()` still behave
  identically (admin user page, admin order page, `OrderItem` cancel/refresh
  paths).
- Admin user page and `/profile` load with **no** `[GET_MUTATION]` log line and
  no Stripe call on render.
- A permission-0 user can load `/profile`.
- `grep -rn 'allow_get_mutation = true'` shows both maintenance files gone from
  the list, and the `admin_user_logic` guards removed.

## Documentation

- Add `ReconcileStripeSubscriptions` to `docs/scheduled_tasks.md` examples
  (alongside `SyncPaypalSubscriptions`), noting the bulk-list pattern and that it
  is the periodic backstop to Stripe webhooks.
- Note in `docs/logic_architecture.md` (near the GET-is-read-only invariant) that
  maintenance/reconciliation must run on the cron runner, never via `include()`
  into page logic — a page render is read-only.
- If StripeHelper's subscription methods are documented anywhere, add
  `apply_subscription_to_order_item()` and note that
  `update_subscription_in_order_item()` is now retrieve-then-apply.

## Versioning

- New `tasks/ReconcileStripeSubscriptions.{json,php}`.
- Bump `@version` on `includes/StripeHelper.php`, `adm/logic/admin_user_logic.php`,
  and `logic/profile_logic.php`. Delete `utils/registrant_maintenance.php` and
  `utils/order_maintenance.php`. No schema or settings changes.
