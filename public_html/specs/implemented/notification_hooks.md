# Notification Hooks Spec

**Purpose:** Turn the in-app notification system into a generic, user-configurable
event system. Developers declare and fire "hook points" with a small snippet;
users opt in to the hook points they care about and optionally choose to also
receive an email when notified. Replaces hardcoded, settings-driven notification
emails (`comment_notification_emails`, etc.) with one extensible mechanism.

**Last Updated:** 2026-05-21

**Status:** Active — not yet implemented.

**Builds on:** the implemented
[Notification Center](implemented/notification_center_spec.md) (in-app
notifications, the bell UI, `Notification::create_notification()`). This spec
adds the developer-facing hook abstraction and per-user preferences on top.

---

## Problem

The platform has a working in-app notification system (`ntf_notifications`,
`Notification::create_notification()`, bell icon, `/notifications` page), but:

1. **Triggers are hardcoded.** Every notification/email is wired directly into a
   logic file — ~6 sends in `cart_charge_logic.php`, one in `post_logic.php`.
   Adding a new "notify someone when X happens" means editing core logic.
2. **No user control.** Users cannot choose what they are notified about, or
   whether a notification also reaches them by email.
3. **Admin notifications are settings-driven and rigid.** Three settings
   (`comment_notification_emails`, `single_purchase_notification_emails`,
   `subscription_notification_emails`) hold comma-separated email lists and send
   raw `EmailSender` calls. They are not in-app notifications and not per-user.

We want one mechanism: a developer drops a snippet at the event site; the event
flows to whoever opted in (plus whoever the event is naturally about); each
recipient gets an in-app notification and, optionally, an email.

---

## Concepts

### Hook point

A named, declared event — e.g. `comment.posted`, `purchase.completed`. Hook
points are **declared once** (so the preferences UI can list them before anyone
fires them) and **fired** wherever the event happens in code.

### Two recipient modes

A pure opt-in model breaks the most important notifications — nobody opts in to
their own purchase receipt or "someone replied to your post." So a hook point
delivers to two kinds of recipient, and a single hook point can use both:

- **Targeted** — recipients the firing code names from context (the buyer, the
  post author). They are notified by default; a user can mute a hook point, but
  they never had to opt in.
- **Topic** — open subscription. Any user can opt in via their preferences. Only
  hook points flagged `supports_topic` expose this.

**v1 scope:** every v1 hook point is a **topic** admin alert (see
[Initial hook points](#initial-hook-points)) — admins opt in to be told when
something needs attention. **Targeted** mode is fully designed and reserved for
future user-facing notifications, but nothing in v1 uses it. Illustration of
both modes:

| Hook point | Targeted | Topic |
|------------|----------|-------|
| `comment.posted` (v1) | — | yes — admins watching for new comments |
| `purchase.completed` (v1) | — | yes — admins watching for sales |
| `comment.reply` (future) | the parent commenter | optional |

### Channels

In-app is the baseline: a notification a user receives is always an
`ntf_notifications` row. **Email is a strictly secondary channel** — "also email
me when I get this notification." A user cannot get the email without the in-app
notification. This matches the requested model and keeps the preference UI to
two controls per hook point.

---

## Data Models

### Hook points are declarations, not a table

A hook point is **static, developer-authored config** — `label`, `description`,
`category`, `ntf_type`, `supports_topic`, `default_email`. None of it is mutable
at runtime, so it is not stored in the database. Hook points live only in their
declaration files (see [Declaring hook points](#declaring-hook-points)): core
hook points in `notification_hooks.json`, plugin hook points in each plugin's
`plugin.json`. `Notify::fire()` and the preferences UI read these declarations
at runtime, merged and cached per-request — the same pattern the platform
already uses for `settings.json` and declarative menus. No catalog table, no
seed/sync step.

### `NotificationPreference` — per-user, per-hook-point (`ntp`)

The one new data model — it follows the standard `SystemBase` /
`SystemMultiBase` Active Record pattern. One row per (user, hook point) the user
has explicitly configured. Absence of a row means defaults apply (see
[Channel resolution](#recipient--channel-resolution)).

```php
class NotificationPreference extends SystemBase {
    public static $prefix      = 'ntp';
    public static $tablename   = 'ntp_notification_preferences';
    public static $pkey_column = 'ntp_notification_preference_id';

    protected static $foreign_key_actions = [
        'ntp_usr_user_id' => ['action' => 'permanent_delete'],
    ];

    public static $field_specifications = [
        'ntp_notification_preference_id' => ['type' => 'int8', 'is_nullable' => false, 'serial' => true],
        'ntp_usr_user_id'   => ['type' => 'int4', 'required' => true],
        'ntp_hook_point'    => ['type' => 'varchar(100)', 'required' => true],  // matches nhp_name
        'ntp_subscribed'    => ['type' => 'bool', 'default' => true],           // opted in (topic) / not muted (targeted)
        'ntp_email_enabled' => ['type' => 'bool', 'default' => false],          // also email me
        'ntp_create_time'   => ['type' => 'timestamp(6)'],
        'ntp_delete_time'   => ['type' => 'timestamp(6)'],
    ];
}
```

Only two meaningful booleans, matching the two UI controls: **subscribe/mute**
and **also email me**.

Per-object subscriptions ("notify me about comments on *this* post") are a
future idea — see [Future enhancements](#future-enhancements-out-of-scope).
They need no model now.

---

## The Dispatcher: `Notify`

New class `includes/Notify.php`. A single static entry point developers call.

```php
Notify::fire(string $hook_point, array $params): void
```

`$params`:

| Key | Required | Meaning |
|-----|----------|---------|
| `recipients` | for targeted hook points | User id, or array of user ids — the people the event is *about*. Omit for pure-topic hook points. |
| `title` | yes | Notification title (`ntf_title`). |
| `body` | no | Notification body (`ntf_body`). |
| `link` | no | URL opened when the notification is clicked (`ntf_link`). |
| `source_user_id` | no | The user who caused the event (`ntf_source_usr_user_id`). |

The hook point's **display type** (`ntf_type`) and **email default** come from
the hook point declaration — the firing code does not pass them. The email
reuses `title` and `body` (the body wrapped in the standard inner template); a
dedicated email subject/body can be added to `fire()` later if a hook ever needs
email content that diverges from the in-app notification.

### The developer snippet

Adding a notifiable event is two small things:

**1. Declare the hook point once** (in `notification_hooks.json`, see below):

```json
"comment.posted": {
    "label": "New comment posted",
    "description": "A new comment was posted on a blog post.",
    "category": "Content",
    "ntf_type": "comment",
    "supports_topic": true,
    "default_email": true
}
```

**2. Fire it where the event happens.** A v1 hook point is a topic admin alert —
no `recipients`; subscribed admins are resolved automatically:

```php
Notify::fire('comment.posted', [
    'title' => 'New comment by ' . $commenter_name,
    'body'  => mb_substr(strip_tags($comment_body), 0, 140),
    'link'  => $post->get_url(),
]);
```

The dispatcher also accepts **targeted** recipients, for future user-facing
notifications — pass `recipients` and the event reaches those users directly,
in addition to any topic subscribers:

```php
Notify::fire('comment.reply', [
    'recipients'     => $parent_comment->get('cmt_usr_user_id'),
    'source_user_id' => $commenter_id,
    'title'          => $commenter_name . ' replied to your comment',
    'link'           => $post->get_url(),
]);
```

### Recipient & channel resolution

Inside `Notify::fire()`:

1. **Look up the hook point** in the merged declarations. Not found → log a
   warning and deliver to the targeted `recipients` only (topic resolution
   needs the declaration's `supports_topic` flag, which is unavailable).
2. **Build the recipient set:**
   - Targeted: every id in `recipients`.
   - Topic (only if `supports_topic`): every user with a `NotificationPreference`
     row for this hook point where `ntp_subscribed = true`.
   - *(future)* Per-object: users with a matching per-object subscription.
   - De-duplicate. Drop the `source_user_id` from the set (never notify someone
     of their own action).
3. **Per recipient, resolve channels:**

   | Recipient kind | In-app | Email |
   |----------------|--------|-------|
   | Targeted, no preference row | yes | hook point's `default_email` |
   | Targeted, `ntp_subscribed = false` | **skipped** (muted) | — |
   | Targeted, `ntp_subscribed = true` | yes | `ntp_email_enabled` |
   | Topic subscriber | yes (subscription implies it) | `ntp_email_enabled` |

4. **Deliver:**
   - In-app: `Notification::create_notification()` (the existing method
     stays as the low-level primitive). Created inline — a cheap insert.
   - Email: **enqueued, never sent inline.** Write one `equ_queued_emails` row
     per emailed recipient (`QueuedEmail` class) with status `READY_TO_SEND` —
     subject from `title`, body from `body` wrapped in the inner template
     (`individual_email_inner_template` setting). The existing `SendQueuedEmails`
     scheduled task drains it.

Errors delivering to one recipient are logged and do not abort the rest, and
never break the request that fired the hook (same contract as product purchase
hooks).

### Delivery: in-app inline, email queued

In-app notifications are created inline within the request — a cheap insert,
safe even on latency-sensitive paths such as checkout. Emails are **never sent
inline**: `Notify::fire()` enqueues them into `equ_queued_emails` and the
existing `SendQueuedEmails` scheduled task sends them on its next run. No
hook-system email is time-critical — receipts, password reset and the like are
direct sends, not hook points — so the drain delay is immaterial. This keeps all
email latency out of the firing request regardless of audience size. See
[Concerns & Edge Cases](#concerns--edge-cases).

---

## Declaring hook points

Hook points are declared in static JSON and read at runtime — there is no
database catalog and no seed/sync step.

- **Core hook points:** a new `notification_hooks.json` at the `public_html/`
  root (sibling of `settings.json`). Map of `hook.name` → metadata.
- **Plugin hook points:** a `notificationHooks` key in the plugin's
  `plugin.json`, same shape.

A small helper (e.g. `Notify::hook_points()`) reads `notification_hooks.json`
plus the `notificationHooks` key of every active plugin's `plugin.json`, merges
them, and caches the result in a per-request static. `Notify::fire()` and the
preferences UI both use it. Removing a declaration simply removes the hook
point; any orphaned `NotificationPreference` rows are harmless and ignored.
Because hook point names are the stable contract between declaration and
preferences, **rename a hook point only by deprecating the old name** — editing
a name in place silently orphans every user's preference for it.

---

## Preferences UI

The page where an admin opts in to alerts and chooses email. In v1 it is an
**admin page** — opting in is an admin function, and the admin interface is one
known theme, which avoids doing public-theme rendering work for a user base that
does not exist yet.

- **Route:** `/admin/admin_notification_preferences` (standard admin page).
- **Files:** `adm/admin_notification_preferences.php` +
  `adm/logic/admin_notification_preferences_logic.php`.
- **Layout:** hook points grouped by their declared `category`; each row shows
  `label` + `description` with a Subscribe toggle and an "Also email me" toggle
  (email disabled unless subscribed). All v1 hook points are topic; a targeted
  hook point would show a "Mute" toggle instead of Subscribe.
- **Form:** built with FormWriter via the admin page's `getFormWriter()`. Saving
  writes/updates `NotificationPreference` rows for the currently logged-in
  admin; a default-valued control may delete the row rather than store a
  redundant default.

### Reusable for a future user-facing page

The logic — assembling the hook-point list from declarations, loading a user's
`NotificationPreference` rows, validating and saving — is identical for an admin
or a regular user. It lives in the logic file as functions that take a user id
and do not depend on the page object. Adding notifications for regular users
later is then just a thin `/profile/notifications` view (rendered in the public
theme) plus a route, both reusing this logic — no rework of the feature itself.

---

## Replacing existing notifications

### Hardcoded admin-email settings → topic hook points

The three legacy settings are replaced by topic hook points:

| Legacy setting | New hook point |
|----------------|----------------|
| `comment_notification_emails` | `comment.posted` |
| `single_purchase_notification_emails` | `purchase.completed` |
| `subscription_notification_emails` | `subscription.started` |

**No data migration.** The platform has no production users yet, so there is no
configured-email data worth preserving. The three settings are deleted from
`settings.json`, the hardcoded send blocks are removed from `post_logic.php` and
`cart_charge_logic.php`, and an admin who wants these alerts opts in to the
topic hook points through the normal preferences UI like any other user.

### Receipts stay as direct sends — NOT converted

Purchase / order confirmation emails (`cart_charge_logic.php` lines ~415, ~457,
~482, and the subscription path) are **not** converted to hook points. They are
mandatory transactional emails, not opt-in notifications — a buyer must not be
able to mute their own receipt. They remain direct `EmailSender` calls,
untouched. This is the deliberate dividing line: notifications go through
`Notify::fire()`; mandatory transactional emails (receipts, password reset,
activation) stay as direct sends.

### Existing user-facing in-app notifications are left as-is

The Notification Center already creates in-app notifications for the
*user* on event registration and subscription confirmation (via
`Notification::create_notification()`). Those are user notifications, outside
v1's admin-alert scope, and are **left untouched**. The new admin-alert hook
points (`event.registered`, `subscription.started`) fire *alongside* them — they
notify subscribed admins, not the registrant/subscriber.
`Notification::create_notification()` remains the low-level primitive that
`Notify::fire()` builds on.

---

## Initial hook points

v1 ships **12 hook points, all admin alerts** — a topic an admin opts in to in
order to be told that something on the site needs attention. Every one is
declared in `notification_hooks.json` with `supports_topic: true` and
`default_email: true` (an admin subscribing to an alert generally wants the
email too). None uses a targeted recipient.

| Hook point | Category | `ntf_type` | Fires when | Fire site |
|------------|----------|-----------|-----------|-----------|
| `purchase.completed` | Orders | order | A sale completes | `cart_charge_logic.php` (post-commit) |
| `payment.failed` | Orders | order | A checkout payment is declined | `cart_charge_logic.php` |
| `subscription.started` | Subscriptions | subscription | A new subscriber signs up | `cart_charge_logic.php` (post-commit) |
| `subscription.cancelled` | Subscriptions | subscription | A subscription is cancelled | `ajax/stripe_webhook.php`, `ajax/paypal_subscription_webhook.php` |
| `subscription.payment_failed` | Subscriptions | subscription | A recurring charge fails / goes past-due | `ajax/stripe_webhook.php`, `ajax/paypal_subscription_webhook.php` |
| `subscription.expired` | Subscriptions | subscription | A subscription lapses | `data/subscription_tiers_class.php` |
| `event.registered` | Events | event | Someone registers for an event | `cart_charge_logic.php` (post-commit) + direct-registration path |
| `event.waitlisted` | Events | event | Someone joins an event waiting list | `logic/event_waiting_list_logic.php` |
| `event.withdrawn` | Events | event | Someone withdraws from an event | `logic/event_withdraw_logic.php` |
| `comment.posted` | Content | comment | A new comment is posted | `logic/post_logic.php` |
| `account.signup` | Members | account | A new user registers | `logic/register_logic.php` |
| `newsletter.signup` | Members | account | Someone subscribes to a mailing list | `data/mailing_lists_class.php` (`add_registrant()`) |

Plugins add their own hook points via `plugin.json`.

---

## Concerns & Edge Cases

### Hooks fire after the transaction commits

`cart_charge_logic.php` wraps the charge in a DB transaction. `Notify::fire()`
must be called only **after that transaction has committed** and the purchase is
final — never inside an open transaction. Otherwise a late rollback could leave
behind `ntf_notifications` rows or queued emails for a purchase that did not
happen. In practice: place every `fire()` call after the commit point, or
collect what to notify during the flow and fire the batch once post-commit.
`fire()` itself must not assume it runs inside a transaction.

### `Notify::fire()` is safe without an HTTP session

`fire()` is called from scheduled tasks as well as web requests — e.g.
`subscription.expired` is detected by a cron task running CLI PHP with no
`$_SESSION` and no current user. So `fire()` and everything it calls must be
session-less-safe: `source_user_id` is always an explicit parameter, never read
from the session, and the `notification_unread_count` session-cache
invalidation inside `create_notification()` must no-op cleanly when there is no
session. Verify `SessionControl::get_instance()` is CLI-safe during
implementation.

### Email is queued, not sent inline — reuse the existing queue

The platform already has an email queue: the `equ_queued_emails` table
(`QueuedEmail` class) and the `SendQueuedEmails` scheduled task, which runs on
every cron tick. Today it is exercised only as a failure-retry queue
(`EmailSender::queueForRetry()` inserts `ERROR_SENDING` rows), but `QueuedEmail`
already defines a `READY_TO_SEND` status — documented "Queued and approved,
ready to send" — for intentionally queued mail.

`Notify::fire()` reuses this: it inserts notification emails as `READY_TO_SEND`
rows. The only change is to widen `SendQueuedEmails` so its drain also sends
`READY_TO_SEND` rows, not just the failed-retry statuses. **No new outbox table
and no new scheduled task.** This keeps email out of the firing request — which
matters because `purchase.completed` fires on the checkout path, where an inline
provider call could stall a charge. Since no hook-system email is time-critical,
the per-cron-tick drain delay is acceptable.

### Topic notifications and content visibility — deferred (admin-only v1)

A topic subscriber could be notified about an object they cannot otherwise see
(a comment on a private or draft post), with the title, excerpt and link
exposing it. **In v1 this is not addressed:** topic subscribers are admins, who
can see all content, so there is no leak in practice.

The design keeps the fix to a clean later addition for when topics open to
regular users: the firing code — which already holds the object context —
decides whether to fire the topic event, firing targeted-only for restricted
content and topic-wide only for broadly-visible content. That is an `if` at the
fire point; the dispatcher does not change. An optional future
`topic_min_permission` key in the hook point declaration can additionally gate
who may subscribe to a given topic.

---

## Scope

The build is a single deliverable:

- `NotificationPreference` model (the only new table).
- `notification_hooks.json` declarations + the merged-read helper (core + plugin).
- `Notify::fire()` — in-app inline, email enqueued to `equ_queued_emails`. Topic
  resolution is the live path; the targeted-recipient branch is specified but
  unused by any v1 hook point.
- Admin preferences page `/admin/admin_notification_preferences`.
- Declare the 12 admin-alert hook points; remove the three legacy settings;
  replace the hardcoded admin emails in `post_logic.php` / `cart_charge_logic.php`
  with `Notify::fire()`, and add `fire()` calls at the remaining hook point sites.

## Future enhancements (out of scope)

Unscheduled ideas — noted so the design leaves room, not committed work:

- **Email digests** — a per-hook-point "daily digest vs immediate" option that
  aggregates a recipient's queued emails into one message before the
  `SendQueuedEmails` drain (reuses the same queue, no new task).
- **Notification grouping** — "3 people commented on your post."
- **Per-object subscriptions** — "Watch / Unwatch" a specific post or event;
  recipient resolution gains a per-object lookup step.
- **User-facing notifications** — opening hook points to regular users (the
  targeted recipient mode, a `/profile/notifications` view, topic content
  visibility — see [Concerns & Edge Cases](#concerns--edge-cases)).

---

## File Map

```
public_html/
  notification_hooks.json                       # core hook point declarations (new)

includes/
  Notify.php                                     # dispatcher (new)

data/
  notification_preferences_class.php             # NotificationPreference + Multi (new)
  notifications_class.php                        # bump @version 1.0 -> 1.1 (unchanged behavior)

adm/
  admin_notification_preferences.php             # admin preferences page (new)
adm/logic/
  admin_notification_preferences_logic.php       # preferences logic, page-object-agnostic (new)

docs/
  notifications.md                               # new developer doc (see below)
```

**Modified** — each gains a `Notify::fire()` call (or loses a hardcoded send):
- `logic/post_logic.php` — replace the `comment_notification_emails` block with
  `Notify::fire('comment.posted')`.
- `logic/cart_charge_logic.php` — remove the hardcoded admin-notification
  emails; fire `purchase.completed`, `payment.failed`, `subscription.started`
  and `event.registered` (purchase path) after the charge transaction commits
  (see Concerns). Receipts and the existing user-facing in-app notifications are
  left untouched.
- `ajax/stripe_webhook.php`, `ajax/paypal_subscription_webhook.php` — fire
  `subscription.cancelled` and `subscription.payment_failed`.
- `data/subscription_tiers_class.php` — fire `subscription.expired`.
- `data/events_class.php` (or the direct-registration logic) — fire
  `event.registered` for non-purchase registration.
- `logic/event_waiting_list_logic.php` — fire `event.waitlisted`.
- `logic/event_withdraw_logic.php` — fire `event.withdrawn`.
- `logic/register_logic.php` — fire `account.signup`.
- `data/mailing_lists_class.php` — `add_registrant()` fires `newsletter.signup`.
- `tasks/SendQueuedEmails.php` — extend the drain to also send `READY_TO_SEND`
  rows (intentionally queued notification emails), not only failed-retry rows.
- `settings.json` — remove the three legacy notification-email settings.

---

## Documentation

Add a new `docs/notifications.md` covering the whole subsystem (the in-app
system from the Notification Center spec has no `/docs/` home yet):

- The in-app notification model and bell UI.
- **How to add a notifiable event** — the two-step snippet: declare in
  `notification_hooks.json` (or `plugin.json`), then `Notify::fire()`.
- `Notify::fire()` parameter reference.
- Targeted vs. topic recipients and the channel resolution table.
- How hook point declarations and user preferences interact.

Link it from the docs index and the "Email System" / "Scheduled Tasks"
neighbours. The CLAUDE.md docs index is regenerated from the `agf_agent_files`
table — add the doc link to the "Internal CLAUDE.md" record at
`/admin/admin_agent_files`, not to `CLAUDE.md` on disk.

---

## Versioning

Bump `@version` headers on every modified file (`notifications_class.php`
1.0 → 1.1, etc.). New files start at `@version 1.0`.
