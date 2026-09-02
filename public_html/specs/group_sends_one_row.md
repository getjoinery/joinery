# Group sends — an email campaign, queued

**Status:** BUILT 2026-09-02 (WP1–WP4 and WP5's migration), uncommitted. Scope
reduced the same day by the owner: a group send is an email campaign, queued
and drained in the background. The in-app announcement channel and batch
sending are **deferred** and kept whole in §9. `update_database` (plain, then
`--upgrade` for the index pass) has run on dev: migration 177 exported and
deleted 3411 rows; the three indexes exist, the unique one as a partial index
over live rows, and migration 178 retired the hand-made index on the same pair. Open: WP5's column and option
removal (§3.7 step 2) waits one release after the migration has run fleet-wide.
Emails sent before this build carry no audience row, so the event and group
boxes list sends from this build on; the earlier ones remain under
`/admin/admin_emails`.

**Touches:** `Email` / `MultiEmail`, `/admin/admin_users_message`,
`/admin/admin_emails_queue`, the event admin page's "Messages to Registrants"
box, the group members page, `Message` (three column removals, one read check),
`ConversationParticipant` and `Notification` (index declarations).

**Companion specs:** `implemented/joinery_messenger.md` (the conversation model
the deferred build extends), `chat_plugin.md`.

---

## 1. What this is, in plain terms

When staff send a message to everyone registered for an event, everyone in a
group, or an event's waiting list, the platform should treat it as what it is:
one email to an audience. It should store the email once, expand the audience
in the background, send with retries, and answer the admin's Send click at
once.

Today it stores a copy of a message row for every recipient, writes a
notification for every recipient one at a time, and makes one email API call
per recipient, all inside the one web request the Send button starts. A send
to the largest event on dev is 706 rows, 706 notifications, 706 email rows and
706 sequential HTTP calls before the page can respond. That is the scaling
wall this spec removes.

After this spec:

- A group send is **one** `Email` row with the audience attached as a recipient
  group — the same shape as any campaign.
- The page queues it and returns. The scheduled email task expands the audience
  and sends, resuming after a failure instead of repeating.
- The event admin page and the group members page list the emails sent to that
  audience, with per-recipient delivery state one click away.
- `msg_messages` holds conversation messages only. The per-recipient copies and
  the three columns that carried them go.

## 2. Today — the facts

`msg_messages` carries two models side by side.

**The conversation model** (the messenger, `docs/social_features.md`): one row
per message; who can see it is a `cnp_conversation_participants` row per
member; read state is one timestamp per member. This model is untouched here.

**The per-recipient model** (`adm/logic/admin_users_message_logic.php`, reached
from "Email registrants", "Email waiting list", "Email group", "Send email to
user"): one `msg_messages` row per recipient with `msg_usr_user_id_recipient`
set and `msg_cnv_conversation_id` NULL, plus one "record" row with recipient
NULL and `msg_context_type`/`msg_context_id` naming the event. Then, per
recipient, in the same request: one `Notification` save, one
`EmailRecipient` save, one `EmailSender::send()` (an HTTP call).

Dev database, 2026-09-02:

| Fact | Value |
|---|---|
| `msg_messages` rows | 3436 |
| Rows with no conversation (the per-recipient model) | 3411 |
| Of which: record rows (recipient NULL, context set) | 99 |
| Of which: per-recipient copies with an event context | 3175 |
| Of which: single-user sends (recipient set, no context) | 106 |
| Of which: neither recipient nor context | 31 |
| Distinct bodies behind the copies | 105 |
| Largest event registrant list | 706 |

**No member surface renders the copies.** Their readers are the event admin
page (`context_id_only`, which selects only the record row),
`/admin/admin_message`, and the ScrollDaddy profile logic, which loads five of
them and whose view never uses them. A member experiences a group send as an
email plus a notification whose link is null. The three columns are absent
from `docs/social_features.md`.

**The email campaign pipeline already has the right shape.** An `Email` row is
the body; `erg_email_recipient_groups` names an audience through the
`RecipientGroupProvider` registry (`group`, `mailing_list`, and event_manager's
`event`, `event_waiting_list`); `erc_email_recipients` holds one row per
person with delivery state; `SendQueuedEmails` drains queued emails in the
background. The group-send page bypasses all of it.

### 2.1 Bugs in the current path

All in `admin_users_message_logic.php` unless stated.

- **B1** The group branch reads `$event->key` while `$event` is null and files
  the record as context type `event` with a null id — the 31 orphan rows.
- **B2** The group branch's `EmailSender::send()` sits *after* the `foreach`, so
  only the last member is emailed, while every member's recipient row is
  marked sent.
- **B3** With zero recipients `$result` is undefined; `eml_status` is set to
  SENT regardless of outcome.
- **B4** The page refuses without `mailgun_domain`/`mailgun_api_key` although
  `EmailSender` is provider-agnostic. `admin_emails_queue.php` has the same
  check.
- **B5** The send is synchronous and per-recipient: a timeout halfway leaves a
  partial send with no resume, and a retry re-sends to those already sent.
- **B6** `Message::authenticate_read()` (REST) admits only sender, recipient or
  staff, so a group-conversation participant who is neither is refused.
- **B7** No index on `cnp_usr_user_id` alone (the inbox and unread queries
  filter on it) and none on `ntf_usr_user_id` (the badge count).
- **B8** No test covers `admin_users_message`.
- **B9** The unique index on `(cnp_cnv_conversation_id, cnp_usr_user_id)` exists
  on dev (`idx_cnp_conversation_user`) but is declared nowhere in code.
- **B10** The campaign runner (`adm/admin_emails_send.php`) builds its template
  with no outer or footer argument, so the `bulk_outer_template` /
  `bulk_footer` settings are read by nothing. Deferred with batching (§9.6); a
  one-line fix if wanted sooner.

B1–B5 disappear with this spec. B6, B7 and B9 ride along as small fixes (§3.6).
B8 is §6.

## 3. Design

**Principle:** a group send is an email campaign. The platform already knows
how to store one, expand its audience, send it in the background and show
what happened. The page's job is to create it and queue it.

### 3.1 `Email::queue()` — the expansion moves onto the model

The code that turns recipient groups into `erc_email_recipients` rows lives
inside a page today (`adm/admin_emails_queue.php`). It becomes a model method
so that a page and the group-send logic call the same thing:

```php
$email->add_recipient_group('event', $event->key);   // as today
$email->add_recipient_group('user', $leader->key);   // new provider, §3.2
$queued = $email->queue();                            // int: recipients queued
```

`queue()`:

1. Resolves every `add` group through its provider, subtracts every `remove`
   group, de-duplicates by user id. The mailing-list branch
   (`eml_mlt_mailing_list_id`) moves in with it.
2. Writes one `EmailRecipient` per user not already on the email
   (`EmailRecipient::CheckIfExists`, as today).
3. With at least one recipient: sets `eml_scheduled_time = now()`,
   `eml_status = EMAIL_QUEUED`, saves, returns the count. With none: leaves the
   email as it was and returns 0. It never marks anything sent (B3).

`admin_emails_queue.php` calls it and prints the summary it prints today. The
Mailgun credential check on that page goes (B4); a missing service is the
runner's to report, as it already does.

### 3.2 A `user` recipient provider

A single person is an audience of one. Core registers `UserRecipientProvider`
(`key() = 'user'`, `resolve($id) = [$id]`, label = the display name). It is
what lets the sender's copy, the event leader's copy and the "Send email to
user" entry all be recipient groups on the same email, with no second code
path and no `COPY:` subject variant. `options()` returns an empty list: the
picker on the campaign page does not offer it, because a campaign to one
person is typed into the recipients box, not picked from a dropdown.

### 3.3 `/admin/admin_users_message` after

The form stays FormWriter-built. On submit the logic does, in order:

1. Create the `Email`: subject, HTML body, plain body, from/reply-to from
   settings, `eml_usr_user_id` = sender, `eml_message_template_html` = the
   inner template setting for the entry (event / group / individual, as now),
   status CREATED.
2. Attach the audience and the copies:

| Entry | Recipient groups |
|---|---|
| `evt_event_id` | `('event', id)`, `('user', sender)`, `('user', leader)` when the event has one |
| `evt_event_id&waiting_list=1` | `('event_waiting_list', id)`, `('user', sender)` |
| `grp_group_id` | `('group', id)`, `('user', sender)` |
| `usr_user_id` | `('user', id)`, `('user', sender)` |

3. `$email->queue()`.
4. Render the success view: "Queued to N recipients", linking to
   `/admin/admin_email?eml_email_id=N` where delivery state is visible per
   person.

No `Message` row, no `Notification` row, no inline send. The logic is under
100 lines. A zero-recipient audience is reported as such, not as a success.

The `usr_user_id` entry goes through the queue like the others: one code path,
one place delivery state lives, and the scheduled task runs every minute so
the difference is not one a person notices.

### 3.4 Where the record shows

**`MultiEmail`** gains a `recipient_group` option: `['provider' => 'event',
'reference_id' => 12]`, implemented as a join to `erg_email_recipient_groups`
with `erg_operation = 'add'`. The `deleted` column filter applies as on any
model.

**The event admin page** replaces "Messages to Registrants" with "Emails to
Registrants": subject, sent by, status, queued/sent counts, time, linking to
`/admin/admin_email`. A second box, "Emails to Waiting List", reads the
`event_waiting_list` group. The `context_id_only` query goes.

**The group members page** gains the same box for the `group` provider.

`/admin/admin_message` and `/admin/admin_conversation` keep showing
conversation messages; the recipient and context lines in `admin_message.php`
go with the columns.

### 3.5 Notifications

Group sends write none. The notification each recipient gets today says "New
message about X" and links to nothing, because there is no page that shows the
copy. An email is the channel; the message is in the inbox it was sent to.
When the in-app channel is built (§9) the notification returns with a link
that goes somewhere.

### 3.6 Small fixes that ride along

- **B6** `Message::authenticate_read()` becomes: staff, or a participant of the
  message's conversation (`$conversation->has_participant($uid)`).
  `$ai_owner_field` becomes `'msg_usr_user_id_sender'` — the recipient column
  is gone, and for conversation messages the AI already saw only rows the
  member sent (their recipient column was always null), so this is no change
  in what the AI can read. A membership scope is part of the deferred build
  (§9.5).
- **B7, B9** Declared in field specs, created by `update_database`:
  `cnp_cnv_conversation_id` → `'unique_with' => ['cnp_usr_user_id']`;
  `cnp_usr_user_id` → `'index' => true`;
  `ntf_usr_user_id` → `'index_with' => ['ntf_is_read']`.

### 3.7 The legacy rows, then the column drop

The 3411 rows with no conversation are copies of emails that were sent, and no
member surface has ever shown them. They are **exported, then deleted**:

1. A migration in `/migrations/` writes every `msg_messages` row with
   `msg_cnv_conversation_id IS NULL` to
   `{site root}/legacy_message_export_{date}.json` (the directory alongside
   `public_html`, outside the web root), then deletes those rows. It refuses to
   delete if the file did not write, and is idempotent (nothing left to select
   on a second run).
2. In the **release after** the migration has run on every node (WP5):
   `msg_usr_user_id_recipient`, `msg_context_type`, `msg_context_id` leave
   `Message::$field_specifications` and `$foreign_key_actions`;
   `msg_cnv_conversation_id` becomes `'is_nullable' => false`; the
   `user_id_recipient`, `context_type`, `context_id`, `context_id_only` options
   leave `MultiMessage`; `MessageContextRegistry` and its registrations in
   `plugins/event_manager/serve.php` go; `migrations/generalize_msg_event_context.php`
   is retired; the five-row list in `plugins/dns_filtering/logic/profile_logic.php`
   goes.

Matching the 3175 copies back to memberships is not done: that only had value
for an in-app channel, and §9.7 keeps the recipe for it.

## 4. What does not change

- The conversation model, the messenger, its protection levels and federation.
- `Email`, `EmailRecipient`, `EmailRecipientGroup`, `SendQueuedEmails` and the
  runner's one-send-per-recipient loop. They gain callers, not shape.
- The `RecipientGroupProvider` interface. It gains one core implementation.
- The FormWriter form on the group-send page.

## 5. Work packages

| WP | What | Fixes |
|---|---|---|
| 1 | `Email::queue()` extracted from `admin_emails_queue.php`; page calls it; `UserRecipientProvider` | B3 B4 |
| 2 | Rewrite `admin_users_message_logic.php` per §3.3 | B1 B2 B5 |
| 3 | `MultiEmail` `recipient_group` filter; event admin page and group members page boxes per §3.4 | — |
| 4 | `authenticate_read` participant check; `$ai_owner_field`; three index declarations | B6 B7 B9 |
| 5 | Export-and-delete migration; column and option removal one release later | — |

WP1–WP4 and WP5's migration ship together. WP5's removal half waits one
release.

## 6. Tests (B8)

`db` tier, in `tests/email/`, `tests/models/` and `plugins/event_manager/tests/`:

- `Email::queue()`: expands add and remove groups through fake providers,
  de-duplicates, skips existing recipient rows, sets status and time only when
  at least one recipient exists, returns the count; a mailing-list email
  expands its list; `admin_emails_queue.php` output unchanged.
- `UserRecipientProvider`: resolves to the one id; empty picker options.
- `admin_users_message` for each of the four entries: one `Email`, the right
  recipient groups, status QUEUED, no `Message` row, no `Notification` row, no
  provider call during the request (a fake transport that fails the test if
  touched); zero-recipient audience reports zero and queues nothing.
- `MultiEmail` `recipient_group`: returns emails with that add-group only; a
  remove-group does not match.
- REST read of a group message by a non-sender participant succeeds; by a
  non-participant fails.
- Migration on a fixture with all four legacy shapes: the export file holds
  every row, the rows are gone, conversation messages untouched, second run is
  a no-op, refusal when the export path is unwritable.

## 7. Docs

`docs/email_system.md` documents `Email::queue()`, the `user` provider and the
`recipient_group` filter. `docs/social_features.md` § Messaging loses nothing it
did not already omit; its table is already the end state. The event manager
overview names the two email boxes. Written as the end state, per the
documentation rule.

## 8. Decisions (owner, 2026-09-02)

- **Group sends are email campaigns.** The in-app announcement channel was a
  feature the first draft added, not a bug it fixed; no member surface shows
  the copies today. Deferred whole in §9.
- **No batch sending.** With the queue in place a 706-person send is minutes of
  background task time. Batching needs a per-recipient variable contract
  across every provider because the default footer personalises the
  unsubscribe link. Deferred in §9.6.
- **Legacy copies are exported and deleted**, not matched into memberships.
- Earlier, under the first draft: leaving an audience removes the membership
  row (§9.2), and batching was chosen in before its real size was known.

## 9. DEFERRED — the in-app announcement channel and batch sending

Everything below was the first draft's design and is kept so it can be picked
up without rediscovery. It builds **on top of** §3: the `Email` row stays the
record of a send, and an audience conversation is where the same send also
appears in Messages. Section numbers here are the deferred build's own.

**Principle of the deferred build:** a send is one row; who received it is a
membership; per-person delivery state lives in the tables built for it
(`cnp_` for reading, `erc_` for email).

### 9.1 Audience conversations

A conversation may be bound to an **audience**: a rule that names its members
rather than a hand-picked list. The rule is the platform's existing one — the
`RecipientGroupProvider` registry that bulk email already targets (`group`,
`mailing_list`, and event_manager's `event`, `event_waiting_list`). Any plugin
that can be an email audience is a message audience with no new code.

`cnv_conversations` gains two nullable columns:

| Column | Type | Meaning |
|---|---|---|
| `cnv_audience_provider` | varchar(32) | A `RecipientGroupProvider` key; NULL for an ordinary conversation |
| `cnv_audience_reference_id` | int4 | The provider's reference (the event id, the group id) |

Declared `unique_with` each other so there is **one** conversation per audience.

```php
$conversation = Conversation::for_audience('event', $event->key);   // get-or-create
$conversation->add_message($sender_user_id, $body);                 // one row
```

`for_audience()`:

- Creates the conversation Standard, with `cnv_subject` =
  `$provider->reference_label($id)` (the event's name, the group's name). The
  subject is refreshed on every `for_audience()` call, so a renamed event renames
  its channel; `rename()` refuses on an audience conversation.
- Marks no participant as admin. **Posting is by permission, not membership:**
  `add_message()` on an audience conversation accepts a sender with session
  permission ≥ 8 (the page's own gate) or a system message, and refuses anyone
  else with "This is an announcement channel." The sender need not be in the
  audience; `add_message()` adds a participant row for them so the thread is in
  their inbox too.
- Is exempt from `messenger_max_group_size`. That limit protects members from
  each other's hand-picked lists; an audience's size is the audience's.
- **Standard only.** `raise()` refuses on an audience conversation: protection
  wraps one key per member, and an announcement channel of 706 people who need
  not all hold vaults is not that ceremony. Refusal text names the reason.
- `is_group()` is true (it has a subject). `add_participant()`,
  `remove_participant()`, `leave()` and `set_admin()` refuse on an audience
  conversation: membership is the rule's, not anyone's. A member who wants it
  out of their inbox uses the ordinary clear (`cnp_delete_time`), which the next
  send undoes, or mute.

**Reply policy (first-draft decision):** announcement-only. A reply-all to 706 registrants
is a storm nobody asked for. The alternative — an open group chat per event —
is a community feature that would need its own moderation story and belongs
with `chat_plugin.md`, not here. If a member reply is wanted later it opens a
1:1 with the sender; nothing in this design blocks that.

### 9.2 Membership sync — set-based

`for_audience()` does not touch membership. **Sending does.** Immediately before
storing the row, `add_message()` on an audience conversation calls
`sync_audience()`:

1. Resolve the audience: `$provider->resolve($reference_id)` → user ids.
2. One `INSERT INTO cnp_conversation_participants (...) SELECT ... ON CONFLICT
   (cnp_cnv_conversation_id, cnp_usr_user_id) DO NOTHING` for the resolved ids
   (bound as an array parameter), stamped `cnp_create_time`.
3. One `DELETE ... WHERE cnp_cnv_conversation_id = ? AND cnp_usr_user_id <> ALL(?)`
   for everyone no longer in the audience, excluding the sender's own row.

Two statements whether the audience is 5 or 5000. `forget_membership()` after.

**Leaving the audience removes the row**, exactly as leaving a group does
(`docs/social_features.md`: "Leaving a group removes the row"). A registrant who
cancels stops seeing the registrants' channel, history included. Of the 3175
legacy copies on dev, 259 went to people who are no longer registered; after
migration they are members until the next send to that audience, then removed.

`resolve()` is a PHP list today (the event provider loads a `MultiEventRegistrant`
and returns ids). That is fine at thousands; the two SQL statements above are
what keep the write side flat. A provider that wants to go further may later
return a SQL fragment instead — out of scope.

### 9.3 `add_message()` fan-out — set-based, for every conversation

The per-participant PHP loop in `add_message()` becomes two statements, and this
applies to ordinary conversations too:

1. **Resurface:** `UPDATE cnp_conversation_participants SET cnp_delete_time = NULL
   WHERE cnp_cnv_conversation_id = ? AND cnp_delete_time IS NOT NULL`.
2. **Notify:** `INSERT INTO ntf_notifications (ntf_usr_user_id, ntf_type,
   ntf_title, ntf_body, ntf_link, ntf_source_usr_user_id, ntf_create_time)
   SELECT cnp_usr_user_id, 'message', ?, ?, ?, ?, now() FROM
   cnp_conversation_participants WHERE cnp_cnv_conversation_id = ? AND
   cnp_is_muted = false AND (? IS NULL OR cnp_usr_user_id <> ?)`.

Title, preview and the Guarded content rule are computed once, as now. The
current user's `notification_unread_count` session cache is cleared when they
are among the recipients (they are, for any conversation they did not send in).
`Notification::create_notification()` stays for single notifications; the new
`Notification::create_for_participants()` is the set form and lives on the
Notification model beside it.

The `UserBlock` branch (a class that does not exist) stays as it is.

### 9.4 What members see

Messenger thread payload gains `can_post` (bool) and `audience` (`null` or
`{provider, reference_id, label}`). The composer renders "Announcements from
{site} about {label} — replies are off" instead of the input when `can_post` is
false. Group-management menu items hide on an audience conversation. Everything
else — inbox row, unread badge, poll, mute, clear, reactions, read receipts —
is the ordinary group behaviour and needs no change. The four core actions the
iOS surface calls are unchanged; `conversation_send` into an audience
conversation returns the same refusal text as the web.

### 9.5 AI read scope by membership

`OwnerScopeResolver` gains a **membership** declaration, the one shape it lacks:

```php
public static $ai_owner_field = ['member_of' => [
    'table'       => 'cnp_conversation_participants',
    'local_column'=> 'msg_cnv_conversation_id',
    'fk_column'   => 'cnp_cnv_conversation_id',
    'user_column' => 'cnp_usr_user_id',
]];
```

Resolves to `['mode' => 'member', ...]` and scopes `WHERE local_column IN
(SELECT fk_column FROM table WHERE user_column = me)`. A declaration naming a
column that does not exist resolves HIDDEN, as the other modes do. Message
declares it; `ConversationParticipant` and `Conversation` are unchanged.

### 9.6 Batch sending that keeps personalisation

Every campaign email is personalised, whether the author meant it or not: the
runner's template gets `default_footer`, and that footer carries the
recipient's unsubscribe link built from `*recipient->key*` and
`*recipient->usr_authhash*`. So "render once, send to 500" is not available as
it stands — the one body would carry one person's unsubscribe link to
everyone, or a literal placeholder. `EmailSender::sendBatch()` today takes a
finished body and a list of addresses, and the Mailgun provider fills its
`recipient-variables` with nothing but the address. Batching has to carry
per-recipient variables end to end.

**The contract.** A batch is one body with neutral markers plus, per recipient,
the values for them:

```php
$sender->sendBatch($message, [
    'alice@example.com' => ['name' => 'Alice', 'vars' => ['key' => 12, 'usr_authhash' => '…', 'usr_email' => 'alice@example.com', 'usr_first_name' => 'Alice']],
    'bob@example.com'   => [...],
]);
```

A plain list of addresses is still accepted (no variables) so existing callers
and tests keep working.

**Rendering once.** `EmailTemplate::fill_template()` accepts
`'recipient' => EmailTemplate::DEFERRED`. In that mode every
`*recipient->X*` renders to the neutral marker `{{recipient.X}}`, `{recipient}`
conditionals treat the recipient as present, and pipes (date formats) on a
deferred value throw, because they cannot be applied later. The runner renders
the template once this way per email.

**Each provider fills the markers its own way**, inside its `sendBatch()`:

| Provider | How |
|---|---|
| Mailgun | markers → `%recipient.X%`, `recipient-variables` carries the vars (500 per call, as now) |
| SendGrid | one personalization per recipient with `substitutions` |
| Brevo | `params` per message version |
| Mailjet | `Vars` per message |
| SES | templated send with per-destination replacement data |
| Postmark, Resend | their batch endpoints take a body per message: render in PHP per recipient, send the chunk in one call |
| SMTP, relay, connected mailbox | render in PHP per recipient and send one at a time — what they do today, no regression |

The PHP-side fill is one shared helper, `EmailPersonalizer::fill($body, $vars)`,
so no provider hand-rolls it. A marker with no value fills as empty.

**Failures and test mode.** `sendBatch()`'s fallback path re-queues each failed
address as an individual `EmailMessage`; it must fill the markers for that
recipient first, or the retry goes out with `{{recipient.key}}` in the
unsubscribe link. Test mode sends one message to the trap: it is filled with
the first original recipient's variables so the trap shows a real rendering.

**The runner.** For each queued email: render once deferred; select unsent
`erc` rows in chunks of 500; build the recipient map from the user rows;
`sendBatch()`; mark the chunk's rows sent, and the addresses in
`failed_recipients` as ERROR. The "another thread already sent it" re-check
becomes a `SELECT ... FOR UPDATE SKIP LOCKED` on the chunk. B10 is fixed on the
way: the runner passes the `bulk_outer_template` and `bulk_footer` settings.

### 9.7 Migration of copies into memberships (deferred)

If the channel is built after §3.7 has run, the export file is the source: for
each record row (recipient NULL, context set) create the audience conversation
and a message; for each copy (recipient set, same sender, body and context
within five minutes of a record row) upsert a `cnp` row for its recipient;
single-user sends become 1:1 conversation messages; the 31 B1 orphans go to a
`('legacy', 0)` conversation whose only member is the sender. Idempotent on
`msg_guid`, which the export carries.

### 9.8 Deferred work packages

| WP | What |
|---|---|
| D1 | `cnv_audience_*` columns, `Conversation::for_audience()`, `sync_audience()`, posting rule, refusals on membership/rename/raise, size-limit exemption |
| D2 | Set-based resurface + `Notification::create_for_participants()` in `add_message()` |
| D3 | Messenger `can_post` / `audience` payload, composer state, hidden group menu |
| D4 | `member_of` owner scope in `OwnerScopeResolver`; `Message` declares it |
| D5 | Batch sending with personalisation (§9.6); B10 |
| D6 | Import of the export file into memberships (§9.7) |

### 9.9 Deferred tests

- `for_audience()` returns the same conversation twice; subject follows the
  provider's label; `raise()`, `add_participant()`, `rename()` refuse.
- `sync_audience()` with a fake provider: adds the missing, removes the
  departed, never removes the sender, two statements.
- A send to a 500-member fake audience: one `msg_messages` row, 500 `cnp` rows
  on the first send and 0 new on the second, one notification per unmuted
  non-sender, statement count independent of N.
- `add_message()` on an ordinary group: notifications match the old loop's
  output row for row (muted skipped, sender skipped, Guarded preview text).
- Posting rule: a non-staff participant's `add_message()` refuses;
  `conversation_send` and `messenger_send` return the refusal.
- AI `member_of` scope: a member sees only rows in conversations they are in;
  a bad column resolves HIDDEN.
- Batching: deferred rendering emits markers and refuses a pipe;
  `EmailPersonalizer` fills and blanks; each provider's `sendBatch()` against
  a fake transport receives its own substitution shape; the fallback re-queue
  carries a filled body; test mode fills with the first recipient; the runner
  marks exactly the failed addresses ERROR, and two runners never send an
  address twice.
