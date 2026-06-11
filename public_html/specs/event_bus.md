# Event Bus Spec

**Purpose:** Give the platform one canonical "something happened" primitive:
`EventBus::dispatch($event, $payload)` with a **structured** payload, and a
subscriber registry that fans the event out to every interested consumer. The
implemented notification-hooks system becomes the bus's first subscriber;
outgoing webhooks and automated email workflows (top-5 features #4 and #2)
become its second and third, each in their own later spec. Plugins subscribe
through their manifest, which makes the bus the platform's WordPress-hooks /
Laravel-events equivalent — a headline capability for the framework pivot.

**Created:** 2026-06-11

**Status:** Active — not yet implemented.

**Builds on:** the implemented
[Notification Hooks](implemented/notification_hooks.md) system
(`includes/Notify.php`, `notification_hooks.json`,
`ntp_notification_preferences`). This spec inserts a generic event layer
*beneath* it; it does not change what notifications look like to admins.

**Unblocks (do not build here):** outgoing webhooks, automated email
workflows, per-event analytics counters, plugin event handlers.

---

## Problem

The platform already fires 12 well-named semantic events (`account.signup`,
`purchase.completed`, `subscription.started`, …) from 14 call sites — but they
are fired through `Notify::fire()`, whose payload is **presentational**: a
pre-rendered title string, a body string, a link. Three consequences:

1. **The payload is unusable by any non-notification consumer.** A webhook
   needs `{"order_id": 712, "amount": 49.00}`; an email-workflow template needs
   structured fields to merge; an analytics counter needs ids. None of that can
   be recovered from `"Sale completed: Basic Plan"`.
2. **There is no subscriber seam.** In-app notification + queued email are
   hard-coded into `Notify::_dispatch()`. Adding a third reaction to an event
   means editing the dispatcher.
3. **Presentation is scattered.** Every call site hand-builds title/body/link
   strings inline, so the same event is worded differently per site and the
   wording lives in business logic.

Two of the team's stated top-5 features (automated email workflows, outgoing
webhooks) both need the same missing substrate. Building either one directly
on `Notify` would bake a second consumer into an email-shaped dispatcher;
building each with its own trigger wiring would duplicate the 14 call sites a
third and fourth time.

---

## Concepts

### Event

A named fact that already happened, e.g. `purchase.completed`. Names keep the
existing `noun.verb` taxonomy. Events are **declared once** in a static
catalog (so consumers can enumerate them and their payload fields) and
**dispatched** wherever they occur in code.

Events are immutable facts, not extension points: subscribers cannot veto an
event or mutate its payload for other subscribers. (Mutable "filter" hooks are
a different primitive and are explicitly out of scope — see Future
enhancements.)

### Structured payload

A flat associative array of **JSON-serializable scalars** (plus nullable
values): entity ids, names, amounts, ISO-8601 UTC times. Never objects,
resources, or pre-rendered HTML. The payload is the machine-readable record of
the event; every consumer derives its own presentation from it.

### Subscriber

A class with a static handler method that receives `($event, $payload)`.
Subscribers are registered declaratively — core subscribers in
`event_subscribers.json`, plugin subscribers in the plugin's `plugin.json` —
and may subscribe to exact event names, prefix wildcards (`subscription.*`),
or everything (`*`).

A subscriber decides its own delivery semantics. The bus invokes handlers
**synchronously, inline, fire-and-forget**; a subscriber whose real work is
slow or retryable (webhook HTTP delivery, bulk email) must do what `Notify`
already does with email: persist a cheap work row inline and let a scheduled
task drain it.

### Layering

```
call site ──► EventBus::dispatch('purchase.completed', [...])
                 │
                 ├──► Notify            (subscriber #1, this spec)
                 │      in-app rows + queued emails, per ntp_ preferences
                 ├──► WebhookDispatcher (subscriber #2, future spec)
                 ├──► EmailWorkflows    (subscriber #3, future spec)
                 └──► plugin handlers   (via plugin.json)
```

---

## The bus: `EventBus`

New class `includes/EventBus.php`. One public entry point:

```php
EventBus::dispatch(string $event, array $payload = array()): void
```

**Contract:**

- **Never throws into the caller.** The entire dispatch is wrapped, and each
  subscriber is additionally wrapped, in try/catch. One failing subscriber is
  logged and the rest still run. An event failure must never break the request
  or operation that produced it (same contract as `Notify::fire()` and product
  purchase hooks).
- **Synchronous and ordered.** Handlers run inline in deterministic order:
  core subscribers in `event_subscribers.json` file order, then plugin
  subscribers in active-plugin load order. No priority system in v1.
- **Session-less safe.** Dispatch happens from web requests, webhooks
  endpoints, and CLI scheduled tasks. Neither the bus nor any handler may read
  the session implicitly; everything a handler needs is in the payload
  (`source_user_id` is an explicit payload key, never `$_SESSION`).
- **Post-commit only.** Where the producing operation runs inside a DB
  transaction (the checkout path), `dispatch()` is called only after the
  transaction commits. Subscribers may assume the fact is final.
- **Undeclared events dispatch anyway.** Dispatching an event missing from the
  catalog logs a warning and still invokes matching subscribers — forgiving
  during development, and wildcard subscribers (webhooks) should not silently
  lose events over a missing catalog entry. The catalog is the contract for
  *consumers enumerating events*, not a gate.
- **Re-entrancy is bounded.** A handler may itself dispatch events (e.g. an
  email-workflow step that completes a purchase). The bus tracks dispatch
  depth; beyond depth 5 it logs and drops the dispatch rather than recurse
  indefinitely.
- **Lazy loading.** A subscriber's file is `require_once`d only when one of
  its subscribed events actually fires.

**Debugging:** a core setting `event_bus_debug` (declared in `settings.json`,
default off) logs every dispatch — event name, JSON payload, subscriber list,
per-handler timing — to the error log. With it on, the bus also
`json_encode()`-checks the payload and logs any non-serializable value (the
check is debug-only; production dispatch never pays for it).

### Payload conventions

- Entity references are `<entity>_id` integers: `user_id`, `order_id`,
  `product_id`, `event_id`, `comment_id`. Handlers needing more than the
  payload carries load the model by id.
- `source_user_id` — the user whose action caused the event, when there is
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

## Declaring events: `events.json`

A new `events.json` at the `public_html/` root (sibling of `settings.json`
and the current `notification_hooks.json`, which it **replaces** — see
Migration). Plugins declare events under an `events` key in `plugin.json`,
same shape. `EventBus::events()` merges core + active plugins and caches
per-request — the identical pattern to `Notify::hook_points()` today.

One entry per event:

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
    "notification": {
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
  is documentation and a merge-field source for future consumer UIs (webhook
  payload docs, email-workflow template pickers). It is not validated at
  dispatch time except under `event_bus_debug`.
- **`notification`** is the per-event config for subscriber #1 (Notify). Its
  keys are today's `notification_hooks.json` keys plus the three templates.
  An event with no `notification` block produces no notifications — it exists
  for other subscribers. This keeps one declaration per event with channel
  blocks inside it; a future webhook or workflow consumer that needs per-event
  static config gets its own sibling block rather than a second catalog file
  whose keys could drift.

**Template substitution** is deliberately dumb: `{field}` is replaced with the
payload value, a missing field substitutes the empty string and logs once. No
conditionals, no formatting, no modifiers — anything fancier (truncation,
pluralization) is a derived payload field computed at the call site (e.g.
`comment_excerpt` is already truncated). Values are substituted as plain text;
the existing email path HTML-escapes the body before wrapping, and in-app
notification bodies remain plain text, so templates introduce no injection
surface.

**Renames:** event names are the stable contract for subscriber declarations,
`ntp_hook_point` preference rows, and (future) webhook subscriptions. Rename
only by introducing the new name and deprecating the old — never edit a name
in place.

---

## Registering subscribers

### Core: `event_subscribers.json`

New file at the `public_html/` root. Ordered map of subscriber name →
declaration:

```json
{
    "notify": {
        "file": "includes/Notify.php",
        "class": "Notify",
        "method": "handle_event",
        "events": ["*"]
    }
}
```

- `file` — path relative to `public_html/`, resolved through
  `PathHelper::getIncludePath()` and required lazily on first matching
  dispatch.
- `class` / `method` — a static method with signature
  `(string $event, array $payload): void`. `method` defaults to `handle`.
- `events` — array of exact names (`purchase.completed`), prefix wildcards
  (`subscription.*` — a trailing `.*` matches the segment prefix), or `"*"`
  (everything). Webhooks will use `"*"` and filter per-subscription in their
  own tables.

### Plugins: `eventSubscribers` in `plugin.json`

Same shape, with `file` relative to the plugin directory. Read for active
plugins only, merged after core, cached per-request alongside the event
catalog. This is the plugin extension seam: a plugin reacts to
`purchase.completed` by declaring a subscriber — no core edits. Plugins also
*declare and dispatch their own events* (an `events` key plus
`EventBus::dispatch()` calls in plugin code), which other plugins or core
subscribers can consume.

Subscriber registration is static, developer-authored config — like events,
settings, and menus, it is not runtime-mutable and gets no database catalog or
admin CRUD. Runtime-configurable fan-out (which URLs get webhooks, which
workflows run) lives in the consumer specs' own tables, behind their
subscriber.

---

## Notify becomes subscriber #1

`Notify` keeps its name, its admin-preferences model
(`ntp_notification_preferences`, keyed by event name — the column
`ntp_hook_point` and its rows are unchanged), its recipient/channel
resolution, its inline in-app insert, and its `READY_TO_SEND` email
enqueueing. What changes is the *entry point and input*:

- **`Notify::fire()` is removed.** The platform is pre-launch; no
  compatibility shim. (Per-recipient and channel behavior is preserved, so
  admins notice nothing.)
- **`Notify::handle_event($event, $payload)`** is the new entry, invoked by
  the bus. It looks up the event's `notification` block in the merged catalog;
  no block → return immediately (cheap — the catalog is an in-memory array).
- **Rendering moves out of call sites** into the declaration templates:
  `handle_event()` renders `title_template` / `body_template` /
  `link_template` against the payload, then proceeds exactly as today
  (topic-subscriber resolution from `ntp_` rows, source-user exclusion using
  `$payload['source_user_id']`, in-app insert, per-preference email enqueue).
- **Targeted recipients** remain designed-but-unused, as in the implemented
  spec: the `notification` block gains an optional `recipients_field` key
  naming the payload field that holds the targeted user id(s)
  (e.g. `"recipients_field": "user_id"`). No v1 event sets it.
- `Notify::hook_points()` becomes a thin filter over `EventBus::events()`
  (entries having a `notification` block), so the admin preferences page logic
  keeps working with only its data source swapped.

The admin preferences UI (`/admin/admin_notification_preferences`) is
functionally untouched: same 12 topics, same two toggles, same table.

---

## Call-site migration

All 14 `Notify::fire()` call sites become `EventBus::dispatch()` with
structured payloads. The wording they currently hand-build moves into the
`notification.{title,body,link}_template` of the corresponding `events.json`
entry, preserving today's rendered output. Payload fields below are the
intent; the implementer confirms exact availability at each site (every site
already holds the loaded models — see e.g. `logic/cart_charge_logic.php:780`,
where `$product`, `$billing_user`, `$order` are all in scope).

| Event | Fire site(s) | Payload fields |
|-------|--------------|----------------|
| `account.signup` | `logic/register_logic.php` | `user_id`, `email`, `display_name`, `source_user_id` (= the new user) |
| `newsletter.signup` | `data/mailing_lists_class.php` | `mailing_list_id`, `email`, `user_id` (nullable) |
| `comment.posted` | `logic/post_logic.php` | `comment_id`, `post_id`, `post_title`, `post_url`, `comment_excerpt`, `author_name`, `source_user_id` |
| `purchase.completed` | `logic/cart_charge_logic.php` (post-commit) | `order_id`, `user_id`, `product_id`, `product_name`, `buyer_name`, `buyer_email`, `amount`, `currency`, `source_user_id` |
| `payment.failed` | `logic/cart_charge_logic.php` | `order_id`, `user_id`, `error_message` (truncated at site) |
| `subscription.started` | `logic/cart_charge_logic.php` (post-commit) | `order_id`, `order_item_id`, `user_id`, `product_id`, `product_name`, `buyer_name`, `buyer_email`, `source_user_id` |
| `subscription.cancelled` | `ajax/stripe_webhook.php`, `ajax/paypal_subscription_webhook.php` | `order_item_id`, `user_id`, `provider` (`stripe`/`paypal`), `provider_subscription_id` |
| `subscription.payment_failed` | `ajax/stripe_webhook.php`, `ajax/paypal_subscription_webhook.php` | `order_item_id`, `user_id`, `provider`, `provider_subscription_id` |
| `subscription.expired` | `data/subscription_tiers_class.php` | `user_id`, `tier_id` |
| `event.registered` | `logic/cart_charge_logic.php` (post-commit) | `event_id` (if resolvable), `product_id`, `product_name`, `user_id`, `registrant_name`, `order_id`, `source_user_id` |
| `event.waitlisted` | `logic/event_waiting_list_logic.php` | `event_id`, `user_id`, `source_user_id` |
| `event.withdrawn` | `logic/event_withdraw_logic.php` | `event_id`, `user_id`, `source_user_id` |

Where a current notification body interpolates something not in the payload
table, either add the field to the payload (preferred) or simplify the
template — never reach back to a model from inside template rendering.

---

## Known future consumers (integration inventory)

Decided up front so the bus design is checked against every known consumer —
none of these are built here:

| Consumer | How it attaches | What it needs from the bus |
|----------|-----------------|---------------------------|
| **Notify** (this spec) | core subscriber, `*` | catalog `notification` blocks, structured payload, `source_user_id` |
| **Outgoing webhooks** (top-5 #4, own spec) | core subscriber, `*`; per-URL subscriptions in its own tables; persists delivery rows inline, scheduled task delivers + retries | JSON-serializable payloads (ship as-is), event enumeration for the subscription UI, wildcard subscription |
| **Automated email workflows** (top-5 #2, own spec) | core subscriber on triggering events; enrolls users into sequences in its own tables; scheduled task advances steps (incl. time-offset triggers like `event.start −24h`, which are *scheduled evaluations*, not bus events) | structured payload as template merge context, `payload` schema for the template-field picker |
| **Per-event analytics counters** (top-5 #5) | either a small core subscriber writing `VisitorEvent` rows or direct instrumentation — decided in that spec | event taxonomy |
| **Plugin handlers** (e.g. ScrollDaddy reacting to `subscription.expired`) | `eventSubscribers` in `plugin.json` | lazy file loading, plugin-declared events |
| **Joinery AI recipes** (future) | a subscriber that enqueues a recipe run | structured payload as recipe input |

**Explicitly out of scope and untouched:** product purchase hooks
(`hooks/product_purchase.php`, `*_product_script` functions) — a per-product
provisioning mechanism with a different contract (inline, order-object
payload). It coexists; converting it would change per-product admin
configuration semantics for no current gain. Mandatory transactional emails
(receipts, password reset, activation) also stay as direct sends, per the
dividing line drawn in the notification-hooks spec.

### Candidate future events (non-normative)

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
the stakes rise: an event dispatched pre-rollback would leave third parties
told of a purchase that never happened.

### CLI / no-session dispatch

`subscription.expired` fires from a cron task; webhook endpoints have no
logged-in user. The bus and all subscribers must work with no `$_SESSION` and
no current user. This is already true of `Notify`'s internals; the bus adds no
session reads.

### Payloads must stay JSON-serializable

The temptation at call sites is to pass `$order` or `$user` objects. The
convention forbids it; under `event_bus_debug` the bus verifies. This is the
single most important discipline for keeping webhooks/workflows buildable on
top without a payload-translation layer later.

### Handler cost budget

Handlers run inline in the producing request — including checkout. The
contract for subscribers is: inline work is bounded to cheap local writes
(inserts into your own queue/work tables); network calls, bulk sends, and
heavy queries belong in a scheduled-task drain. `Notify` already models this
(inline `ntf_` insert, queued email). The webhook spec must follow it (inline
delivery-row insert; HTTP happens in the drain task).

### Two declaration sources must not drift

Events and their notification config live in **one** file per owner
(`events.json` or the plugin's `events` key) precisely so the catalog and the
notification templates cannot disagree. `notification_hooks.json` is deleted
in the same change that creates `events.json` — there is no period where both
exist.

---

## Scope

Single deliverable, no new database tables:

- `includes/EventBus.php` — `dispatch()`, merged `events()` catalog, merged
  subscriber registry, wildcard matching, depth guard, debug logging.
- `events.json` — the 12 existing events with payload schemas and
  `notification` blocks (content migrated from `notification_hooks.json`,
  which is deleted). `settings.json` gains `event_bus_debug`.
- `event_subscribers.json` — registering `Notify`.
- `plugin.json` support for `events` and `eventSubscribers` keys (replacing
  `notificationHooks`; no active plugin currently declares it, so nothing to
  migrate).
- `Notify` rewrite: `handle_event()` + template rendering; `fire()` removed;
  `hook_points()` reads the event catalog. Recipient/channel resolution,
  preferences model, email enqueueing unchanged.
- Migrate all 14 call sites to `EventBus::dispatch()` with structured
  payloads per the table above.
- Documentation per the Documentation section.

**No data migration:** `ntp_notification_preferences` rows keep their event
names; the platform is pre-launch and nothing else persists hook state.

## Future enhancements (out of scope)

- **Async subscriber wrapper** — a generic "enqueue this handler invocation
  as a work row, drain via scheduled task" helper, if a third consumer
  re-implements the inline-row + drain pattern and it's worth extracting.
- **Event log / replay** — a persistent `event_log` table for debugging and
  at-least-once redelivery to async consumers. Deferred: it grows unboundedly
  and no v1 consumer needs replay (webhooks persist their own delivery rows).
- **Filter hooks** — WordPress-style mutable filters ("modify this value
  before use") are a different primitive from events-as-facts and would get
  their own design if ever needed.
- **Priority ordering** — if subscriber order ever matters beyond
  core-then-plugins, add an integer `priority` to declarations.
- **Payload schema validation** — promote the advisory `payload` schema to
  enforced validation once consumer UIs depend on field presence.

---

## File Map

```
public_html/
  events.json                       # event catalog (new; absorbs + deletes notification_hooks.json)
  event_subscribers.json            # core subscriber registry (new)
  settings.json                     # + event_bus_debug

includes/
  EventBus.php                      # the bus (new)
  Notify.php                        # 1.0 -> 2.0: handle_event(), template rendering; fire() removed

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

ajax/
  stripe_webhook.php                # dispatch subscription.cancelled, subscription.payment_failed
  paypal_subscription_webhook.php   # dispatch subscription.cancelled, subscription.payment_failed

adm/logic/
  admin_notification_preferences_logic.php  # hook-point list now sourced from EventBus::events()
```

Bump `@version` on every modified file; new files start at 1.0.

---

## Documentation

- **New `docs/events.md`** — the subsystem doc: the dispatch contract, payload
  conventions, declaring events (`events.json` / plugin `events`), registering
  subscribers (`event_subscribers.json` / plugin `eventSubscribers`), the
  handler cost budget, and the "how to add an event" snippet (declare →
  dispatch → optionally add a `notification` block).
- **Update `docs/notifications.md`** — Notify is described as the bus
  subscriber that renders notification templates; the "add a notifiable
  event" snippet becomes: declare the event with a `notification` block, then
  `EventBus::dispatch()`. Written as current-state only, per docs rules.
- **Update `docs/plugin_developer_guide.md`** — document the `events` and
  `eventSubscribers` plugin.json keys where `notificationHooks` is documented
  today.
- **CLAUDE.md docs index** — add `docs/events.md` via the "Internal
  CLAUDE.md" record at `/admin/admin_agent_files` (never the file on disk).
