# Notifications

The platform has two related notification layers:

1. **In-app notifications** (`ntf_notifications`) — the bell icon, unread badge,
   and `/notifications` page. Created with `Notification::create_notification()`.
2. **Notification hooks** — a generic, configurable layer on top: developers
   declare and fire named *hook points*; admins opt in to the ones they want.
   This is what you use to add a new "notify someone when X happens."

This document covers the hooks layer. See `specs/notification_hooks.md` for the
full design rationale.

## Concepts

- **Hook point** — a named event, e.g. `comment.posted`, `purchase.completed`.
  Declared once in `notification_hooks.json`; fired wherever the event happens.
- **Topic recipient** — any user who opts in to a hook point via their
  preferences. All current hook points are topic-based admin alerts.
- **Targeted recipient** — a user the firing code names explicitly (the buyer,
  the post author). Supported by the dispatcher but unused by current hook
  points; reserved for future user-facing notifications.
- **Channels** — in-app is the baseline (always created); email is a secondary,
  opt-in channel layered on top.

## Adding a notifiable event

Two steps.

### 1. Declare the hook point

Add an entry to `notification_hooks.json` at the `public_html/` root (plugins
use a `notificationHooks` key in their `plugin.json`, same shape):

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

| Key | Meaning |
|-----|---------|
| `label` | Short name shown in the preferences UI |
| `description` | Longer explanation shown in the preferences UI |
| `category` | Grouping in the preferences UI (`Orders`, `Events`, ...) |
| `ntf_type` | Display type / icon for the in-app notification |
| `supports_topic` | Whether users may opt in to it |
| `default_email` | Default state of the "also email me" toggle when subscribing |

Declarations are static config — there is no database catalog and no sync step.
They are read at runtime by `Notify::hook_points()`, cached per request.

**Never rename a hook point in place** — its name is the contract with stored
user preferences. To rename, add the new name and deprecate the old one.

### 2. Fire it

Call `Notify::fire()` where the event happens:

```php
require_once(PathHelper::getIncludePath('includes/Notify.php'));

Notify::fire('comment.posted', array(
    'title' => 'New comment by ' . $commenter_name,
    'body'  => mb_substr(strip_tags($comment_body), 0, 180),
    'link'  => $post->get_url(),
    'source_user_id' => $commenter_id,
));
```

`Notify::fire()` never throws into the caller — a notification failure cannot
break the request that triggered it. It is also safe to call from CLI / cron
contexts (no session required).

## `Notify::fire()` parameters

`Notify::fire(string $hook_point, array $params)`:

| Key | Required | Meaning |
|-----|----------|---------|
| `title` | yes | Notification title (also the email subject) |
| `body` | no | Notification body (also the email body) |
| `link` | no | URL opened when the notification is clicked |
| `recipients` | no | Targeted recipient user id, or array of ids |
| `source_user_id` | no | The user who caused the event — excluded from recipients so nobody is notified of their own action |

The `ntf_type` and email default come from the hook point declaration, not the
call.

## Recipient & channel resolution

For each fired hook point, `fire()` builds the recipient set from the targeted
`recipients` plus every topic subscriber (users with a `NotificationPreference`
row where `ntp_subscribed = true`), de-duplicates, and drops `source_user_id`.

Per recipient:

| Recipient | In-app | Email |
|-----------|--------|-------|
| Targeted, no preference row | yes | hook point's `default_email` |
| Targeted, muted (`ntp_subscribed = false`) | skipped | — |
| Targeted, subscribed | yes | their `ntp_email_enabled` |
| Topic subscriber | yes | their `ntp_email_enabled` |

## Delivery

- **In-app** notifications are created inline via
  `Notification::create_notification()` — a cheap insert.
- **Email** is never sent inline. `fire()` writes a `equ_queued_emails` row with
  `READY_TO_SEND` status; the `SendQueuedEmails` scheduled task delivers it on
  its next run. This keeps email out of latency-sensitive paths like checkout.
  No hook-system email is time-critical (receipts, password reset etc. are
  direct sends, not hook points).

## Preferences

Admins manage their own subscriptions at
`/admin/admin_notification_preferences`. Each declared hook point can be
subscribed to, and optionally flagged "also email me." Preferences are stored
one row per (user, hook point) in `ntp_notification_preferences`; the
load/save logic (`adm/logic/admin_notification_preferences_logic.php`) is
page-object-agnostic so a future user-facing preferences page can reuse it.

## What is NOT a hook point

Mandatory transactional emails — purchase receipts, password reset, account
activation — are **not** hook points. They are required, non-opt-out messages
sent directly via `EmailSender`. Only opt-in notifications go through
`Notify::fire()`.
