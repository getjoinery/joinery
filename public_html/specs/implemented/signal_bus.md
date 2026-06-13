# Signal Bus Spec

**Purpose:** Give the platform one canonical "something happened" primitive:
`SignalBus::dispatch($signal, $payload)` with a **structured** payload, and a
subscriber registry that fans the signal out to every interested consumer. The
implemented notification-hooks system becomes the bus's first subscriber;
outgoing webhooks and automated email workflows (top-5 features #4 and #2)
become its second and third, each in their own later spec. Plugins subscribe
through their manifest, which makes the bus the platform's WordPress-hooks /
Laravel-events equivalent — a headline capability for the framework pivot.

**Created:** 2026-06-11

**Status:** Implemented — 2026-06-13.

**Builds on:** the implemented
[Notification Hooks](notification_hooks.md) system (`includes/Notify.php`,
`notification_hooks.json`, `ntp_notification_preferences`). This spec inserts a
generic signal layer *beneath* it; it does not change what notifications look
like to admins.

**Unblocks (do not build here):** outgoing webhooks, automated email
workflows, per-signal analytics counters, plugin signal handlers.

---

## Problem

The platform already fires 12 well-named semantic signals (`account.signup`,
`purchase.completed`, `subscription.started`, …) from 14 call sites — but they
are fired through `Notify::fire()`, whose payload is **presentational**: a
pre-rendered title string, a body string, a link. Three consequences:

1. **The payload is unusable by any non-notification consumer.** A webhook
   needs `{"order_id": 712, "amount": 49.00}`; an email-workflow template needs
   structured fields to merge; an analytics counter needs ids. None of that can
   be recovered from `"Sale completed: Basic Plan"`.
2. **There is no subscriber seam.** In-app notification + queued email are
   hard-coded into `Notify::_dispatch()`. Adding a third reaction to a signal
   means editing the dispatcher.
3. **Presentation is scattered.** Every call site hand-builds title/body/link
   strings inline, so the same signal is worded differently per site and the
   wording lives in business logic.

Two of the team's stated top-5 features (automated email workflows, outgoing
webhooks) both need the same missing substrate. Building either one directly
on `Notify` would bake a second consumer into an email-shaped dispatcher;
building each with its own trigger wiring would duplicate the 14 call sites a
third and fourth time.

---

## Concepts

### Signal

A named fact that already happened, e.g. `purchase.completed`. Names keep the
existing `noun.verb` taxonomy. Signals are **declared once** in a static
catalog (so consumers can enumerate them and their payload fields) and
**dispatched** wherever they occur in code.

Signals are immutable facts, not extension points: subscribers cannot veto an
signal or mutate its payload for other subscribers. (Mutable "filter" hooks are
a different primitive and are explicitly out of scope — see Future
enhancements.)

### Structured payload

A flat associative array of **JSON-serializable scalars** (plus nullable
values): entity ids, names, amounts, ISO-8601 UTC times. Never objects,
resources, or pre-rendered HTML. The payload is the machine-readable record of
the signal; every consumer derives its own presentation from it.

### Subscriber

A class with a static handler method that receives `($signal, $payload)`.
Subscribers are registered declaratively — core subscribers in
`signal_subscribers.json`, plugin subscribers in the plugin's `plugin.json` —
and may subscribe to exact signal names or everything (`*`).

A subscriber decides its own delivery semantics. The bus invokes handlers
**synchronously, inline, fire-and-forget**; a subscriber whose real work is
slow or retryable (webhook HTTP delivery, bulk email) must do what `Notify`
already does with email: persist a cheap work row inline and let a scheduled
task drain it.

### Layering

```
call site ──► SignalBus::dispatch('purchase.completed', [...])
                 │
                 ├──► Notify            (subscriber #1, this spec)
                 │      in-app rows + queued emails, per ntp_ preferences
                 ├──► WebhookDispatcher (subscriber #2, future spec)
                 ├──► EmailWorkflows    (subscriber #3, future spec)
                 └──► plugin handlers   (via plugin.json)
```

---

## The bus: `SignalBus`

New class `includes/SignalBus.php`. One public entry point:

```php
SignalBus::dispatch(string $signal, array $payload = array()): void
```

**Contract:**

- **Never throws into the caller.** The entire dispatch is wrapped, and each
  subscriber is additionally wrapped, in try/catch. One failing subscriber is
  logged and the rest still run. A signal failure must never break the request
  or operation that produced it (same contract as `Notify::fire()` and product
  purchase hooks).
- **Synchronous and ordered.** Handlers run inline in deterministic order:
  core subscribers in `signal_subscribers.json` file order, then plugin
  subscribers in active-plugin load order. No priority system in v1.
- **Session-less safe.** Dispatch happens from web requests, webhooks
  endpoints, and CLI scheduled tasks. Neither the bus nor any handler may read
  the session implicitly; everything a handler needs is in the payload
  (`source_user_id` is an explicit payload key, never `$_SESSION`).
- **Post-commit only.** Where the producing operation runs inside a DB
  transaction (the checkout path), `dispatch()` is called only after the
  transaction commits. Subscribers may assume the fact is final.
- **Undeclared signals dispatch anyway.** Dispatching a signal missing from the
  catalog logs a warning and still invokes matching subscribers — forgiving
  during development, and wildcard subscribers (webhooks) should not silently
  lose signals over a missing catalog entry. The catalog is the contract for
  *consumers enumerating signals*, not a gate.
- **Recursion guardrail.** No v1 subscriber dispatches from inside a handler.
  The bus carries a simple recursion-depth counter purely so that a future
  handler that *does* re-dispatch can't hang the request with unbounded
  recursion: past a sanity limit it logs and drops. This is a guardrail against
  a footgun, not an invitation to re-entrant dispatch — handlers should not
  dispatch signals.
- **Lazy loading.** A subscriber's file is `require_once`d only when one of
  its subscribed signals actually fires.

**Debugging:** a core setting `signal_bus_debug` (declared in `settings.json`,
default off) logs each dispatch (signal name + JSON payload) to the error log,
and `json_encode()`-checks the payload, logging any non-serializable value.
That serializability check is the point — it enforces the spec's most important
discipline (payloads stay JSON-serializable for webhooks/workflows) — and it is
debug-only, so production dispatch never pays for it.

### Payload conventions

- Entity references are `<entity>_id` integers: `user_id`, `order_id`,
  `product_id`, `event_id`, `comment_id`. Handlers needing more than the
  payload carries load the model by id.
- `source_user_id` — the user whose action caused the signal, when there is
  one. Consumers use it for "don't notify the actor" logic and attribution.
- Times are ISO-8601 UTC strings (`gmdate('Y-m-d H:i:s')`), matching DB
  storage.
- Human-derived convenience fields (names, excerpts) are allowed and
  encouraged where every consumer would otherwise re-derive them
  (`buyer_name`, `product_name`, `comment_excerpt`) — but they supplement the
  ids, never replace them. Derived display strings are computed at the call
  site (which holds the loaded models) so subscribers don't each re-query.
- Money is a decimal-string `amount` plus a separate `currency`.

---

## Declaring signals: `signals.json`

A new `signals.json` at the `public_html/` root (sibling of `settings.json`
and the current `notification_hooks.json`, which it **replaces** — see
Migration). Plugins declare signals under a `signals` key in `plugin.json`,
same shape. `SignalBus::signals()` merges core + active plugins and caches
per-request — the identical pattern to `Notify::hook_points()` today.

Each entry declares a signal's **identity** (`label`, `description`,
`category`), the **shape of its payload**, and — optionally — a `notify` block
holding the config for subscriber #1 (Notify). The decoupling that matters is
in *code, not file count*: **the bus reads only `label`/`description`/`payload`
and never looks at `notify` or any other consumer block.** Consumer config is
co-located here for the one consumer that needs static per-signal config
(Notify); every other known consumer — webhooks, workflows — configures itself
in its own runtime tables and only *reads* the payload schema, so the catalog
never accretes a block per consumer.

One entry per signal:

```json
"purchase.completed": {
    "label": "Sale completed",
    "description": "A purchase or order was completed successfully.",
    "category": "Orders",
    "payload": {
        "order_id": "Order id",
        "user_id": "Buyer user id",
        "source_user_id": "Acting user id (the buyer)",
        "product_id": "Product id",
        "product_name": "Product display name",
        "buyer_name": "Buyer display name",
        "buyer_email": "Buyer email address",
        "amount": "Order total, decimal string",
        "currency": "ISO currency code"
    },
    "notify": {
        "ntf_type": "order",
        "supports_topic": true,
        "default_email": true,
        "title_template": "Sale completed: {product_name}",
        "body_template": "{buyer_name} ({buyer_email}) completed a purchase — Order #{order_id}.",
        "link_template": "/admin/admin_orders"
    }
}
```

- **`payload`** is the advisory schema: field name → one-line description. It
  is documentation and a merge-field source for consumer UIs (webhook payload
  docs, email-workflow template pickers) and for Notify's template rendering.
  It is not validated at dispatch time except under `signal_bus_debug`.
- **`notify`** is read only by Notify (see
  [Notify becomes subscriber #1](#notify-becomes-subscriber-1)), never by the
  bus. A signal with no `notify` block produces no notifications — it exists for
  other subscribers.

**Renames:** signal names are the stable contract for subscriber declarations,
`ntp_signal_name` preference rows, Notify's per-signal config keys, and (future)
webhook subscriptions. Rename only by introducing the new name and deprecating
the old — never edit a name in place.

---

## Registering subscribers

### Core: `signal_subscribers.json`

New file at the `public_html/` root. Ordered map of subscriber name →
declaration:

```json
{
    "notify": {
        "file": "includes/Notify.php",
        "class": "Notify",
        "method": "handle_signal",
        "signals": ["*"]
    }
}
```

- `file` — path relative to `public_html/`, resolved through
  `PathHelper::getIncludePath()` and required lazily on first matching
  dispatch.
- `class` / `method` — a static method with signature
  `(string $signal, array $payload): void`. `method` defaults to `handle`.
- `signals` — array of exact names (`purchase.completed`) or `"*"` (everything).
  Webhooks will use `"*"` and filter per-subscription in their own tables. A
  subscriber wanting a whole namespace lists the exact names today; prefix
  matching (`subscription.*`) is a deferred matcher enhancement — see Future
  enhancements — so the first real consumer can pin down its exact semantics.

### Plugins: `signalSubscribers` in `plugin.json`

Same shape, with `file` relative to the plugin directory. Read for active
plugins only, merged after core, cached per-request alongside the signal
catalog. This is the plugin extension seam: a plugin reacts to
`purchase.completed` by declaring a subscriber — no core edits. Plugins also
*declare and dispatch their own signals* (a `signals` key plus
`SignalBus::dispatch()` calls in plugin code), which other plugins or core
subscribers can consume. A plugin that wants a signal of its own to be
notifiable adds a `notify` block to that signal's `signals` entry (the per-signal
shape from [Declaring signals](#declaring-signals-signalsjson)) — it does not
register a second subscriber for that.

The signal catalog and the subscriber registry — including the `notify` block's
templates and toggles — are **code-bound developer config**, not runtime state.
They ship in the repo, are edited in source, and are deployed through the
upgrade pipeline; an upgrade overwriting them is correct, because they must stay
in lockstep with the `dispatch()` call sites and handler classes they describe
(the same way `admin_menus.json`, `theme.json`, and `plugin.json` are source,
not live-editable site config). Three consequences worth stating, because they
are the whole answer to "how do I change this without an upgrade clobbering it":

- **Never hand-edit `signals.json` / `signal_subscribers.json` on a deployed
  site** — edit them in source and deploy, exactly as you would core PHP.
- **A site that needs its own signals or subscribers ships a plugin** (`signals`
  / `signalSubscribers` in `plugin.json`). Core upgrades never touch plugin
  files, so there is no clobber and no core edit.
- **Runtime-mutable state lives in DB tables, not these files.** Per-user
  notification preferences are already in `ntp_notification_preferences`, edited
  through the admin preferences page and untouched by upgrades. Future
  runtime-configurable fan-out (which URLs get webhooks, which workflows run)
  likewise lives in the consumer specs' own tables, behind their subscriber —
  never in `signals.json`. Notification *wording* is intentionally developer
  config: templates are coupled to payload field names (`{product_name}` must
  match a payload key), so they are versioned with the code, not exposed for
  live admin editing.

---

## Notify becomes subscriber #1

`Notify` keeps its name, its admin-preferences model
(`ntp_notification_preferences`), its recipient/channel resolution, its inline
in-app insert, and its `READY_TO_SEND` email enqueueing. What changes is the
*entry point* and the *input* (structured payload, not a rendered message):

- **`Notify::fire()` is removed.** The platform is pre-launch; no
  compatibility shim. (Per-recipient and channel behavior is preserved, so
  admins notice nothing.)
- **`Notify::handle_signal($signal, $payload)`** is the new entry, invoked by
  the bus. It reads the signal's **`notify` block** from the merged catalog
  (`SignalBus::signals()`); no block → return immediately (cheap — an in-memory
  array). The bus itself never reads `notify`; only Notify does. Plugins that
  declare a notifiable signal carry the `notify` block inline in their `signals`
  entry (see [Declaring signals](#declaring-signals-signalsjson)) — no separate
  plugin key.
- **Rendering moves out of call sites** into the `notify` templates:
  `handle_signal()`
  renders `title_template` / `body_template` / `link_template` against the
  payload, then proceeds exactly as today (topic-subscriber resolution from
  `ntp_` rows, source-user exclusion using `$payload['source_user_id']`, in-app
  insert, per-preference email enqueue).

  **Template substitution** is deliberately dumb: `{field}` is replaced with the
  payload value, a missing field substitutes the empty string and logs once. No
  conditionals, no formatting, no modifiers — anything fancier (truncation,
  pluralization) is a derived payload field computed at the call site (e.g.
  `comment_excerpt` is already truncated). Values are substituted as plain text;
  the existing email path HTML-escapes the body before wrapping, and in-app
  notification bodies remain plain text, so templates introduce no injection
  surface.
- **`Notify::hook_points()` is retired** in favor of
  `Notify::notifiable_signals()`, which filters `SignalBus::signals()` to the
  entries carrying a `notify` block and returns the display metadata the
  preferences page needs (label/category alongside the notify config). The
  admin preferences page logic keeps working with only its data source swapped.

The admin preferences UI (`/admin/admin_notification_preferences`) is
functionally untouched: same 12 topics, same two toggles, same table. The
preference rows are keyed by signal name in the renamed column `ntp_signal_name`
(see Scope).

---

## Call-site migration

All 14 `Notify::fire()` call sites become `SignalBus::dispatch()` with
structured payloads. The wording they currently hand-build moves into the
`{title,body,link}_template` of the corresponding signal's `notify` block in
`signals.json`, preserving today's rendered output. Payload fields below are the
intent; the implementer confirms exact availability at each site (every site
already holds the loaded models — see e.g. `logic/cart_charge_logic.php:780`,
where `$product`, `$billing_user`, `$order` are all in scope).

| Signal | Fire site(s) | Payload fields |
|-------|--------------|----------------|
| `account.signup` | `logic/register_logic.php` | `user_id`, `email`, `display_name`, `source_user_id` (= the new user) |
| `newsletter.signup` | `data/mailing_lists_class.php` | `mailing_list_id`, `mailing_list_name`, `email`, `user_id` (nullable) |
| `comment.posted` | `logic/post_logic.php` | `comment_id`, `post_id`, `post_title`, `post_url`, `comment_excerpt`, `author_name`, `source_user_id` |
| `purchase.completed` | `logic/cart_charge_logic.php` (post-commit) | `order_id`, `user_id`, `product_id`, `product_name`, `buyer_name`, `buyer_email`, `amount`, `currency`, `source_user_id` |
| `payment.failed` | `logic/cart_charge_logic.php` | `order_id`, `user_id`, `error_message` (truncated at site) |
| `subscription.started` | `logic/cart_charge_logic.php` (post-commit) | `order_id`, `order_item_id`, `user_id`, `product_id`, `product_name`, `buyer_name`, `buyer_email`, `source_user_id` |
| `subscription.cancelled` | `ajax/stripe_webhook.php`, `ajax/paypal_subscription_webhook.php` | `order_item_id`, `user_id`, `provider` (`stripe`/`paypal`), `provider_subscription_id` |
| `subscription.payment_failed` | `ajax/stripe_webhook.php`, `ajax/paypal_subscription_webhook.php` | `order_item_id`, `user_id`, `provider`, `provider_subscription_id` |
| `subscription.expired` | `data/subscription_tiers_class.php` | `user_id`, `tier_id`, `tier_name` |
| `event.registered` | `logic/cart_charge_logic.php` (post-commit) | `event_id` (if resolvable), `product_id`, `product_name`, `user_id`, `registrant_name`, `order_id`, `source_user_id` |
| `event.waitlisted` | `logic/event_waiting_list_logic.php` | `event_id`, `event_name`, `user_id`, `source_user_id` |
| `event.withdrawn` | `logic/event_withdraw_logic.php` | `event_id`, `event_name`, `user_id`, `source_user_id` |

Where a current notification body interpolates something not in the payload
table, either add the field to the payload (preferred) or simplify the
template — never reach back to a model from inside template rendering.

**Signals fired from two sites converge to one template.**
`subscription.cancelled` and `subscription.payment_failed` each fire from both
`stripe_webhook.php` and `paypal_subscription_webhook.php`, today with
provider-specific wording. Under one template per signal the wording unifies via
the `{provider}` payload field (e.g. `A subscription was cancelled ({provider}
subscription {provider_subscription_id}).`), and `subscription.payment_failed`
uses a single link (`/admin/admin_orders`) in place of today's per-provider
links. This is the only intended change to existing notification output: the
provider distinction is preserved in the structured payload (where every
consumer reads it), and only the cosmetic admin-alert phrasing differs. All
other signals render byte-identical to today.

---

## Known future consumers (integration inventory)

Decided up front so the bus design is checked against every known consumer —
none of these are built here:

| Consumer | How it attaches | What it needs from the bus |
|----------|-----------------|---------------------------|
| **Notify** (this spec) | core subscriber, `*`; per-signal config in each signal's `notify` block | structured payload, `source_user_id`, payload schema for template fields |
| **Outgoing webhooks** (top-5 #4, own spec) | core subscriber, `*`; per-URL subscriptions in its own tables; persists delivery rows inline, scheduled task delivers + retries | JSON-serializable payloads (ship as-is), signal enumeration for the subscription UI, wildcard subscription |
| **Automated email workflows** (top-5 #2, own spec) | core subscriber on triggering signals; enrolls users into sequences in its own tables; scheduled task advances steps (incl. time-offset triggers like `event.start −24h`, which are *scheduled evaluations*, not bus signals) | structured payload as template merge context, `payload` schema for the template-field picker |
| **Per-signal analytics counters** (top-5 #5) | either a small core subscriber writing `VisitorEvent` rows or direct instrumentation — decided in that spec | signal taxonomy |
| **Plugin handlers** (e.g. ScrollDaddy reacting to `subscription.expired`) | `signalSubscribers` in `plugin.json` | lazy file loading, plugin-declared signals |
| **Joinery AI recipes** (future) | a subscriber that enqueues a recipe run | structured payload as recipe input |

**Explicitly out of scope and untouched:** product purchase hooks
(`hooks/product_purchase.php`, `*_product_script` functions) — a per-product
provisioning mechanism with a different contract (inline, order-object
payload). It coexists; converting it would change per-product admin
configuration semantics for no current gain. Mandatory transactional emails
(receipts, password reset, activation) also stay as direct sends, per the
dividing line drawn in the notification-hooks spec.

### Candidate future signals (non-normative)

Not part of this build; listed so consumer specs share one naming taxonomy
when they add them: `post.published`, `member.updated`, `member.deleted`,
`refund.issued`, `order.refunded`, `event.created`, `event.cancelled`,
`survey.completed`, `message.received`, `login.failed`.

---

## Concerns & Edge Cases

### Dispatch after commit — inherited rule, now load-bearing for more consumers

The checkout path wraps the charge in a DB transaction; `dispatch()` must run
only after commit (today's `Notify::fire()` sites already obey this — the
migration must not move them earlier). With webhooks as a future subscriber
the stakes rise: a signal dispatched pre-rollback would leave third parties
told of a purchase that never happened.

### CLI / no-session dispatch

`subscription.expired` fires from a cron task; webhook endpoints have no
logged-in user. The bus and all subscribers must work with no `$_SESSION` and
no current user. This is already true of `Notify`'s internals; the bus adds no
session reads.

### Payloads must stay JSON-serializable

The temptation at call sites is to pass `$order` or `$user` objects. The
convention forbids it; under `signal_bus_debug` the bus verifies. This is the
single most important discipline for keeping webhooks/workflows buildable on
top without a payload-translation layer later.

### Handler cost budget

Handlers run inline in the producing request — including checkout. The
contract for subscribers is: inline work is bounded to cheap local writes
(inserts into your own queue/work tables); network calls, bulk sends, and
heavy queries belong in a scheduled-task drain. `Notify` already models this
(inline `ntf_` insert, queued email). The webhook spec must follow it (inline
delivery-row insert; HTTP happens in the drain task).

### Catalog and notify config can't drift — they're one entry

A signal's identity, payload schema, and `notify` block live in a single
`signals.json` entry (or a single plugin `signals` entry), so the catalog and its
notification config cannot disagree about which signals exist. The one residual
mismatch — a template referencing a payload field not in the schema — stays
harmless (empty-string substitution, logged once), and is surfaced loudly under
`signal_bus_debug`. The decoupling between bus and subscriber is enforced in
code (the bus reads only `label`/`description`/`payload`, never `notify`), not
by file separation. `notification_hooks.json` is deleted in the same change
that creates `signals.json` — there is no period where the old file coexists.

---

## Scope

Single deliverable, no new database tables:

- `includes/SignalBus.php` — `dispatch()`, merged `signals()` catalog, merged
  subscriber registry, exact + `*` subscriber matching, recursion guardrail,
  debug logging.
- `signals.json` — the 12 existing signals: identity + `payload` schema, plus a
  `notify` block on each notifiable signal (`ntf_type`, `supports_topic`,
  `default_email`, the three templates). This absorbs and replaces
  `notification_hooks.json`, which is deleted. `settings.json` gains
  `signal_bus_debug`.
- `signal_subscribers.json` — registering `Notify`.
- `plugin.json` support for `signals` (with an optional inline `notify` block per
  signal) and `signalSubscribers` keys (replacing `notificationHooks`; no active
  plugin currently declares it, so nothing to migrate).
- `Notify` rewrite: `handle_signal()` reads each signal's `notify` block from the
  catalog and renders its templates; `fire()` removed; `hook_points()` retired
  for `notifiable_signals()`. Recipient/channel resolution, preferences model,
  email enqueueing unchanged.
- **Rename `ntp_hook_point` → `ntp_signal_name`** in
  `notification_preferences_class.php` `$field_specifications` and the
  `MultiNotificationPreference` filter key (`hook_point` → `signal_name`);
  update `NotificationPreference::get_for()`'s parameter name to match.
- Migrate all 14 call sites to `SignalBus::dispatch()` with structured
  payloads per the table above.
- Documentation per the Documentation section.

**No data migration:** the platform is pre-launch, so the `ntp_signal_name`
rename is handled entirely by `update_database` — no migration (schema changes
never go in migrations). As implemented:

- `update_database --upgrade` **adds** `ntp_signal_name`. The field spec's
  `'required' => true` is app-level validation only — `DatabaseUpdater` keys DB
  nullability off `is_nullable` (unset here), so the column is created
  `NULL`-able, exactly as `ntp_hook_point` already was. There is no `NOT NULL`
  add to fail on, regardless of existing rows.
- The old `ntp_hook_point` column is **not** dropped by `--upgrade`; column drops
  are gated behind `--cleanup` (a deliberate data-loss guard). It is left as a
  harmless nullable orphan — nothing reads it, and inserts that set only
  `ntp_signal_name` succeed. Run `update_database --cleanup` to remove it; not
  required for correctness. The same is true on prod deploy (`upgrade.php` runs
  `update_database`).
- Existing `ntp_notification_preferences` rows keep their old value in the orphan
  column and have `NULL` `ntp_signal_name`, so their subscription is effectively
  inactive — fine pre-launch (dev rows, discardable).

Nothing else persists signal/hook state. `signal_bus_debug` seeds into
`stg_settings` from `settings.json` during `update_database`.

## Future enhancements (out of scope)

- **Async subscriber wrapper** — a generic "enqueue this handler invocation
  as a work row, drain via scheduled task" helper, if a third consumer
  re-implements the inline-row + drain pattern and it's worth extracting.
- **Signal log / replay** — a persistent `signal_log` table for debugging and
  at-least-once redelivery to async consumers. Deferred: it grows unboundedly
  and no v1 consumer needs replay (webhooks persist their own delivery rows).
- **Filters** — WordPress-style mutable filters ("modify this value before
  use") are a different primitive from signals-as-facts and would get their own
  design if ever needed.
- **Prefix wildcard matching** — `subscription.*`-style namespace subscription.
  No v1 or named future consumer needs it (real consumers use `*`-and-filter or
  exact names), and adding it is a localized change to the matcher with no
  format, payload, or call-site impact. Deferred so the first consumer that
  wants it pins down the exact semantics (one segment vs. all descendants,
  whether the bare root matches) against a real use case.
- **Priority ordering** — if subscriber order ever matters beyond
  core-then-plugins, add an integer `priority` to declarations.
- **Payload schema validation** — promote the advisory `payload` schema to
  enforced validation once consumer UIs depend on field presence.

---

## File Map

```
public_html/
  signals.json                       # signal catalog: identity + payload schema + optional notify block (new)
  signal_subscribers.json            # core subscriber registry (new)
  settings.json                     # + signal_bus_debug
  notification_hooks.json           # DELETED (absorbed into signals.json)

includes/
  SignalBus.php                      # the bus (new); reads only identity + payload, never the notify block
  Notify.php                        # 1.0 -> 2.0: handle_signal() reads each signal's notify block,
                                    #   template rendering; fire() removed; hook_points() -> notifiable_signals()

logic/
  register_logic.php                # dispatch account.signup
  post_logic.php                    # dispatch comment.posted
  cart_charge_logic.php             # dispatch payment.failed, purchase.completed,
                                    #   subscription.started, event.registered (post-commit)
  event_waiting_list_logic.php      # dispatch event.waitlisted
  event_withdraw_logic.php          # dispatch event.withdrawn

data/
  subscription_tiers_class.php      # dispatch subscription.expired
  mailing_lists_class.php           # dispatch newsletter.signup
  notification_preferences_class.php # rename ntp_hook_point -> ntp_signal_name (field spec + filter key)

ajax/
  stripe_webhook.php                # dispatch subscription.cancelled, subscription.payment_failed
  paypal_subscription_webhook.php   # dispatch subscription.cancelled, subscription.payment_failed

adm/logic/
  admin_notification_preferences_logic.php  # notifiable-signal list now from Notify::notifiable_signals()
```

Bump `@version` on every modified file; new files start at 1.0.

---

## Documentation

- **New `docs/signals.md`** — the subsystem doc: the dispatch contract, payload
  conventions, declaring signals (`signals.json` / plugin `signals`), registering
  subscribers (`signal_subscribers.json` / plugin `signalSubscribers`), the
  handler cost budget, and the "how to add a signal" snippet (declare →
  dispatch → optionally add a `notify` block to make it notifiable).
- **Rewrite `docs/notifications.md`** — this doc is currently the
  "notification hooks" explainer (titled around hooks, "hook point" throughout,
  documenting `notification_hooks.json` / `Notify::fire()` /
  `Notify::hook_points()`). It is a **full rewrite, not a find-replace**:
  retitle to "Notifications"; reframe Notify as a *signal-bus subscriber* that
  renders templates from each signal's `notify` block; replace every "hook
  point" with "signal"; drop `notification_hooks.json` / `fire()` /
  `hook_points()`; point readers at `docs/signals.md` for the bus itself. The
  "add a notifiable signal" snippet becomes: declare the signal in `signals.json`
  with a `notify` block, then `SignalBus::dispatch()`. Written as current-state
  only, per docs rules.
- **Fix the two stale cross-references** to the old spec: `Notify.php`'s
  `See specs/notification_hooks.md` comment and `docs/notifications.md`'s same
  reference both point at `signal_bus.md` / `docs/signals.md`. The implemented
  `specs/implemented/notification_hooks.md` itself is left untouched (frozen
  historical record).
- **Update `docs/plugin_developer_guide.md`** — document the `signals` (with its
  optional per-signal `notify` block) and `signalSubscribers` plugin.json keys
  where `notificationHooks` is documented today.
- **CLAUDE.md docs index** — add `docs/signals.md` via the "Internal
  CLAUDE.md" record at `/admin/admin_agent_files` (never the file on disk).
