# Move per-user subscription maintenance off page-view into scheduled tasks

## Overview

`utils/registrant_maintenance.php` and `utils/order_maintenance.php` perform
**Stripe subscription reconciliation and write an `EventLog` audit row** — real,
mutating maintenance work — but they run **synchronously, inline, on a GET page
render**. They are `include()`d into request logic, not driven by the cron
runner. This spec moves that work to the scheduled-task system where it belongs
and removes the inline includes (and the GET-mutation opt-ins that were added as
a stopgap).

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
   for normal users or this path never really executes for them. **Verify and
   resolve this during implementation** — it is direct evidence the work was
   written as an admin/CLI job and wired into page loads by mistake.

## Goal

Run subscription reconciliation as a **scheduled task** on the cron runner, the
same way `SyncPaypalSubscriptions` already syncs PayPal subscriptions. Remove the
two inline `include()`s and the `$allow_get_mutation` opt-ins. Page logic returns
to read-only.

The existing `tasks/SyncPaypalSubscriptions.{json,php}` is the reference pattern
and the conceptual sibling (it is the PayPal half of the same job).

## Approach

### 1. New scheduled task: reconcile Stripe subscriptions globally

Create `tasks/ReconcileStripeSubscriptions.json` + `.php` following
`docs/scheduled_tasks.md` and the `SyncPaypalSubscriptions` shape. Instead of
"reconcile this one `$user`," it iterates the relevant set **globally**:

- Load active Stripe subscription order items (the same set the per-user code
  reaches, but across all users — e.g. `MultiOrderItem` with the
  `is_active_subscription` / Stripe-subscription filters already used elsewhere).
- For each, call the **same** `StripeHelper` methods
  (`update_subscription_in_order_item()` / `update_all_subscriptions_in_order()`).
  Do **not** rewrite the reconciliation logic — only change *what drives it*.
- Skip when `StripeHelper::is_initialized()` is false (mirrors current guards).
- Write **one** `EventLog` summary row per run (count processed / errors), not one
  per record.
- Support dry-run (`docs/scheduled_tasks.md#3-optional-add-dry-run-support`).

Decide cadence during implementation (PayPal sync's interval is a reasonable
starting point). Note: Stripe webhooks are the authoritative real-time path for
subscription state; this task is the periodic backstop, so it does not need to be
frequent.

### 2. Remove the inline includes

- `adm/logic/admin_user_logic.php:54-55` — delete both `include()`s.
- `logic/profile_logic.php:45` — delete the `registrant_maintenance` include.

After removal, neither page mutates on render. Confirm the admin user page and
the profile page still load and show the same data (subscription state will be as
of the last task run rather than live — see Decisions).

### 3. Retire the opt-ins and the util files

- Fold the per-user bodies into the task (or have the task call shared helpers),
  then **delete** `utils/registrant_maintenance.php` and
  `utils/order_maintenance.php`, or reduce them to thin functions the task calls.
- Either way, the `SystemBase::$allow_get_mutation = true; … finally …` wrappers
  added by the guard-fix spec go away — there is no longer a GET mutation to opt
  in. Re-grep `allow_get_mutation = true` afterward to confirm only the genuinely
  intentional GET-action sites remain (admin delete/toggle links, `cart_charge`
  payment return, `account_edit` photo actions).

### 4. Resolve the profile permission conflict

Confirm what `profile_logic`'s include was doing for non-admin users today
(likely redirecting them via the level-10 check). Once the include is gone the
conflict disappears; verify a permission-0 user can load `/profile` cleanly.

## Decisions / open questions

- **On-view freshness.** Moving to cron means a user/admin sees subscription
  state as of the last task run, not live. If an admin genuinely needs
  reconcile-now, add an **explicit POST action** ("Refresh subscription status"
  button) on the admin user page that runs the same reconciliation for that one
  user — a deliberate POST mutation, not a GET side-effect. Decide whether this
  is needed or whether webhook-driven state + periodic cron is sufficient.
- **Scope of the global sweep.** Confirm the exact filter set so the task covers
  every record the per-user code reached (active subscriptions across all users)
  without scanning the whole orders table each run.

## Non-goals

- **Changing the `StripeHelper` reconciliation logic itself.** This spec only
  changes *what triggers* it (cron vs page-view), not how a subscription is
  reconciled.
- **The PayPal sync.** `SyncPaypalSubscriptions` already exists; this is the
  Stripe counterpart, not a rework of PayPal.

## Testing

- Task runs under CLI (`is_initialized()` false on a non-Stripe dev → no-ops
  cleanly; with Stripe configured → reconciles and writes one `EventLog`).
- Dry-run reports intended changes without writing.
- Admin user page and `/profile` load with **no** `[GET_MUTATION]` log line and no
  Stripe call on render.
- A permission-0 user can load `/profile`.
- `grep -rn 'allow_get_mutation = true'` shows the maintenance files gone from the
  list.

## Documentation

- Add the new task to `docs/scheduled_tasks.md` examples (alongside
  `SyncPaypalSubscriptions`).
- Note in `docs/logic_architecture.md` (near the GET-is-read-only invariant) that
  maintenance/reconciliation must run on the cron runner, never via `include()`
  into page logic — a page render is read-only.

## Versioning

- New `tasks/ReconcileStripeSubscriptions.{json,php}`.
- Bump `@version` on `admin_user_logic.php`, `profile_logic.php`, and any
  `StripeHelper`/util files touched. No schema or settings changes.
