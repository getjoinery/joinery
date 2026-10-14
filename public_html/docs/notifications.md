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
  `notify` block. Notify ignores signals without one.
- **Topic recipient** — any user who opts in to a signal via their preferences.
- **Targeted recipient** — a user the signal addresses without an opt-in:
  either named by the dispatch in a `recipients` payload key (the people a
  mailbox or Boards signal is about), or a member of the signal's declared
  `audience` (the incident and system alerts go to every superadmin; a content report goes to every admin).
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
| `audience` | A group that always gets it, resolved by Notify at send time. `superadmins`: every superadmin who is a person. `admins`: every admin (permission 5 and up) who is a person. The dispatch passes no `recipients` for it |
| `default_email` | Whether a targeted recipient with no saved preference is emailed; also the default of the "also email me" toggle |
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
`recipients`, the members of its `audience`, and every topic subscriber (users with a `NotificationPreference`
row where `ntp_subscribed = true`), de-duplicates, and drops `source_user_id` so
nobody is notified of their own action. The system and deleted users
(`User::is_placeholder()`) are never recipients on either channel: they are not
people, and their email addresses are placeholders on a domain nobody here
controls.

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

Admins manage theirs at `/admin/admin_notification_preferences`, which lists
every notifiable signal. Members manage theirs at
`/profile/notification_preferences`, which lists the signals with
`supports_topic` false. Preferences are stored one row per (user, signal) in
`ntp_notification_preferences` keyed by `ntp_signal_name`.

Both pages show what the person actually gets, through
`Notify::preference_states()`: a saved row where there is one, otherwise
`Notify::default_preference()`, the delivery rule with no row:

| Signal | No saved row shows |
|--------|--------------------|
| `supports_topic` false (sent only to the people it is about) | on; email per `default_email` |
| Declares an `audience` the viewer belongs to | on; email per `default_email` |
| Anything else | off |

Both save through `Notify::save_preferences()`. A signal with a saved row is
updated. One without a row gets a row only when the choice differs from its
default, so unticking an alert someone receives writes the muted row in one
save, and a choice equal to the default leaves no row — an admin promoted into
an audience later still gets its alerts.

## What does NOT go through Notify

Mandatory transactional emails — purchase receipts, password reset, account
activation — are **not** notifications. They are required, non-opt-out messages
sent directly via `EmailSender`. Only opt-in notifications go through Notify.
