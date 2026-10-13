# Notification preferences page shows the wrong state

**Status: built 2026-10-13 (option D below, chosen by the owner). Verified:
the new suites and the nine suites that send or test these signals pass, the
dev admin page shows user 1 ticked for the four superadmin alerts, and
migration 216 ran on dev. Remaining checks (migration 216 on a node that still
has ntp_hook_point; one incident email instead of two) are in the live
verification queue. Found while chasing duplicate incident emails.**

## What the user sees

`/admin/admin_notification_preferences` shows every box unticked, including
"A critical incident opened on a node". The same admin is receiving those
alerts, in the bell and by email. The page says they are not subscribed.

Unticking and saving does not help. For these signals the page cannot turn an
alert off in one save.

## Diagnosis

Two rules about a missing preference row (`ntp_notification_preferences`) have
drifted apart.

**Delivery** (`includes/Notify.php` `_dispatch`, documented in
`docs/notifications.md` "Recipient & channel resolution"): a user the dispatch
names in `recipients` (a *targeted* recipient) with no row is notified in-app,
and by email when the signal declares `default_email`. Only a row with
`ntp_subscribed = false` mutes them. A user who is not named gets the signal
only if they have a subscribed row (a *topic subscriber*).

**Display** (`adm/admin_notification_preferences.php`, the loop that fills
`$subscribed_checked` / `$email_checked`): ticks a box only when a row exists
with the matching flag. No row is shown as unticked.

The member page (`views/profile/notification_preferences.php`, "No saved row
means the signal's default") already shows the delivery rule correctly. It can,
because the signals it lists are the ones with `supports_topic` false, which
are targeted only.

**Save** (`notification_preferences_save` in
`adm/logic/admin_notification_preferences_logic.php`): an unticked box with no
row writes nothing ("absence of a row already means not subscribed"). For a
targeted signal that is false. Absence means subscribed, so the unticked save
leaves the admin subscribed. To mute one they must tick and save, which writes
a row, then untick and save, which writes the muted row.

### Why the page cannot just apply the delivery rule

The page lists every signal with a `notify` block, and it cannot tell which
ones address people by name.

- The incident signals (`incident.opened`, `incident.opened_critical`) and
  `secret.unreadable` are declared `supports_topic: true`, but their dispatch
  sites always pass `recipients` (every superadmin, from
  `IncidentReconciler::notify()`). With no row, a superadmin is notified.
- `comment.posted` and similar are pure subscriptions. With no row, nobody is
  notified, and unticked is correct.

`supports_topic` says "users may also opt in". It does not say "the dispatch
already addresses the admins". Nothing in the signal declaration records the
second fact.

### Evidence

Rows for the two superadmin Gmail accounts on dev, 2026-10-13: user 1 has no
rows. User 4497 has rows only for `mail_import.finished`, `mail_import.scanned`
and two with a blank `ntp_signal_name`. Neither has an incident row, yet both
received every incident email (queued emails 649–654 for incidents 6289 and
6290).

Related finding, not this bug: both accounts deliver to one Gmail inbox, so
each incident arrives twice. See "Follow-on" below.

## Fix options

### A. Declare it on the signal

Add a flag to the `notify` block, for example `"addressed_to_admins": true`,
on every signal whose dispatch names its recipients: the two incident signals,
`secret.unreadable`, and any other with a `recipients` key in its payload that
is also `supports_topic: true`. Where the flag is set:

- **Display:** with no row, show subscribed, and email per `default_email`.
  (Same rule as the member page.)
- **Save:** always write a row, ticked or not, so unticking records a mute.
- **Delivery:** unchanged.

Gain: the page matches reality for every flagged signal, and opting out works
in one save. Catch: a signal that addresses people by name but lacks the flag
shows wrong again. A test should assert that every notifiable signal whose
payload declares `recipients` carries the flag or `supports_topic: false`.

### B. Always write a row on save, leave the display alone

Gain: opting out works in one save, and it is a one-line change. Catch: the
page still shows unticked boxes while the admin is being emailed, which is the
reported bug.

### C. Derive it from the dispatch

Have the page ask `Notify` who would be notified by default. Gain: no flag to
keep up. Catch: dispatch sites build `recipients` at send time (a query over
superadmins), so the page would have to run or mimic that query per signal.
Not recommended.

### What review of A found

- A fourth signal is affected: `site.outbound_transfer_high` also goes to
  every superadmin (`OutboundTransferMeter::email()`). Each of the four built
  its own superadmin list, in three places.
- A yes/no flag shows a permission-5 admin ticked boxes for alerts that only
  reach permission 10. The page opens at permission 5.
- A's guard test reads the declared payload, and the incident signals never
  declare `recipients`; it would have passed with the bug in place.
- The admin page also shows the 11 `supports_topic: false` signals (Boards,
  mail import) unticked while they arrive. Same bug, a second rule.

### D. The signal declares its audience, and delivery reads it (built)

The `notify` block names who always gets the signal: `"audience":
"superadmins"`. `Notify` resolves it at send time; the dispatch sites pass no
`recipients`. Sending and display read the same field, so they cannot drift.

- **Delivery:** `Notify::audience_ids()` adds the audience to the targeted
  set. An unknown audience, or a failed read, is logged and the topic
  subscribers still hear.
- **Display:** `Notify::preference_states()` gives each signal's state: a
  saved row, otherwise `Notify::default_preference()` — on (email per
  `default_email`) for a `supports_topic: false` signal or an audience the
  viewer is in, off otherwise. Both preferences pages use it.
- **Save:** `Notify::save_preferences()`, used by both pages. A row is written
  when one exists or when the choice differs from the default. Unticking
  mutes in one save; leaving a default alone writes nothing, so an admin
  promoted into an audience later still gets its alerts.

## What was built

1. `audience: superadmins` on `incident.opened`, `incident.opened_critical`,
   `secret.unreadable`, `site.outbound_transfer_high`; `recipients` removed
   from their payloads and from `IncidentReconciler::notify()`,
   `SecretReconciler::alert()` and `OutboundTransferMeter::email()`.
2. `Notify` 2.3: `audience_ids()`, `default_preference()`,
   `preference_states()`, `save_preferences()`. Admin and member preference
   logic and views use them; the admin page's own load/save helpers are gone.
3. `docs/notifications.md`: the `audience` key and the preferences rule.
4. Test `tests/functional/notification_preferences_test.php` (test-db): every
   declared audience is known and the four alerts declare theirs; no-row
   states for superadmin, permission-5 admin, opt-in and member signals; one
   save mutes; a default choice writes no row; a dispatch reaches a
   superadmin with no row and not one who muted.
5. Done on dev with the owner's approval: every alert on the Claude account
   (user 4497) is bell-only, through `Notify::save_preferences()`.
6. The two rows with a blank `ntp_signal_name` (see Evidence) were left by
   the June rename of `ntp_hook_point` to `ntp_signal_name`: the field spec
   changed, `update_database` added the new column empty, and nothing copied
   the old values. A site that never ran column cleanup still has the names
   in `ntp_hook_point`, with every preference saved before the rename ignored.
   Migration 216 (`migrations/notification_preferences_signal_name.php`)
   copies them back where the old column is there and removes rows naming no
   signal; test `tests/models/notification_preferences_signal_name_test.php`.
   Applied on dev: the two rows removed (dev's old column was already gone).

## Follow-on: who gets incident emails

Unrelated to this bug but found with it. `IncidentReconciler::notify()`
addresses every non-system superadmin, and Server Manager has no alert
recipient setting of its own. User 1 (`jeremy.tunnell@gmail.com`) and user 4497
(`jeremy.tunnell+claude@gmail.com`) reach the same inbox, so each incident
arrives as two identical emails. Muting 4497's incident emails (above) fixes
this one inbox. A "who gets incident emails" list on the Server Manager
dashboard would fix it for any account and stop test accounts being addressed.
That is a separate decision.

Done already on dev, 2026-10-13: user 182404 (a leftover walk fixture,
`claude-walk-bandwidth-limits@example.com`) was demoted from permission 10 to
0, so it no longer receives alerts at an address that does not exist.
