# Notifications

The platform has two related notification layers:

1. **In-app notifications** (`ntf_notifications`) — the bell icon, unread badge,
   and `/notifications` page. Created with `Notification::create_notification()`.
2. **Notify** — a [signal bus](signals.md) subscriber that turns signals into
   in-app notifications and, per the recipient's preferences, queued emails.
   This is what you use to add a new "notify someone when X happens."

This document covers Notify. For the bus itself — the dispatch contract, payload
conventions, and declaring/subscribing — see [Signal Bus](signals.md).

## Concepts

- **Notifiable signal** — any signal whose `signals.json` entry carries a
  `notify` block. Notify ignores signals without one. All current notifiable
  signals are topic-based admin alerts (e.g. `comment.posted`,
  `purchase.completed`).
- **Topic recipient** — any user who opts in to a signal via their preferences.
- **Targeted recipient** — a user the dispatch names explicitly via a
  `recipients` payload key. Supported but unused by current signals; reserved for
  future user-facing notifications.
- **Channels** — in-app is the baseline (always created); email is a secondary,
  opt-in channel layered on top.

## Adding a notifiable signal

Two steps.

### 1. Declare the signal with a `notify` block

Add a `notify` block to the signal's entry in `signals.json` (plugins use the
`signals` key in `plugin.json`, same shape). The signal's identity and `payload`
schema are declared in the same entry — see [Signal Bus](signals.md):

```json
"comment.posted": {
    "label": "New comment posted",
    "description": "A new comment was posted on a blog post.",
    "category": "Content",
    "payload": {
        "post_title": "Blog post title",
        "post_url": "Blog post URL",
        "comment_excerpt": "Comment body excerpt",
        "author_name": "Comment author display name",
        "source_user_id": "Acting user id (the commenter)"
    },
    "notify": {
        "ntf_type": "comment",
        "supports_topic": true,
        "default_email": true,
        "title_template": "New comment by {author_name}",
        "body_template": "On \"{post_title}\": {comment_excerpt}",
        "link_template": "{post_url}"
    }
}
```

| `notify` key | Meaning |
|--------------|---------|
| `ntf_type` | Display type / icon for the in-app notification |
| `supports_topic` | Whether users may opt in to it |
| `default_email` | Default state of the "also email me" toggle when subscribing |
| `title_template` | Notification title (also the email subject) |
| `body_template` | Notification body (also the email body) |
| `link_template` | URL opened when the notification is clicked |

The `label`, `description`, and `category` (used by the preferences UI) live at
the top level of the entry, alongside the `payload` schema.

### 2. Dispatch the signal

Dispatch through the bus where the fact occurs — Notify is invoked automatically:

```php
require_once(PathHelper::getIncludePath('includes/SignalBus.php'));

SignalBus::dispatch('comment.posted', array(
    'post_title'      => $post->get('pst_title'),
    'post_url'        => $post->get_url(),
    'comment_excerpt' => mb_substr(strip_tags($comment_body), 0, 180),
    'author_name'     => $commenter_name,
    'source_user_id'  => $commenter_id,
));
```

## Template rendering

Notify renders `title_template` / `body_template` / `link_template` against the
payload. Substitution is deliberately dumb: `{field}` is replaced with the
payload value as plain text. A missing or null field substitutes the empty string
and logs once. There are no conditionals, formatting, or modifiers — anything
fancier is a derived payload field computed at the call site (e.g.
`comment_excerpt` is already truncated). Because templates reference payload field
names, they are versioned with the code, not exposed for live admin editing.

## Recipient & channel resolution

For each notifiable signal, Notify builds the recipient set from any targeted
`recipients` plus every topic subscriber (users with a `NotificationPreference`
row where `ntp_subscribed = true`), de-duplicates, and drops `source_user_id` so
nobody is notified of their own action.

Per recipient:

| Recipient | In-app | Email |
|-----------|--------|-------|
| Targeted, no preference row | yes | signal's `default_email` |
| Targeted, muted (`ntp_subscribed = false`) | skipped | — |
| Targeted, subscribed | yes | their `ntp_email_enabled` |
| Topic subscriber | yes | their `ntp_email_enabled` |

## Delivery

- **In-app** notifications are created inline via
  `Notification::create_notification()` — a cheap insert.
- **Email** is never sent inline. Notify writes a `equ_queued_emails` row with
  `READY_TO_SEND` status; the `SendQueuedEmails` scheduled task delivers it on its
  next run. This keeps email out of latency-sensitive paths like checkout. No
  notification email is time-critical.

## Preferences

Admins manage their own subscriptions at
`/admin/admin_notification_preferences`. Each notifiable signal can be subscribed
to, and optionally flagged "also email me." Preferences are stored one row per
(user, signal) in `ntp_notification_preferences` keyed by `ntp_signal_name`; the
load/save logic (`adm/logic/admin_notification_preferences_logic.php`) is
page-object-agnostic so a future user-facing preferences page can reuse it.

## What does NOT go through Notify

Mandatory transactional emails — purchase receipts, password reset, account
activation — are **not** notifications. They are required, non-opt-out messages
sent directly via `EmailSender`. Only opt-in notifications go through Notify.
