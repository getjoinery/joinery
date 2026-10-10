# Core Improvements for Plugins

**Purpose:** The one list of core, store and mailbox-plugin work that the plugin specs depend on. Small changes to existing core and store code that the Boards, Volunteers, Donations and Bookings plugin specs depend on or are simpler for. Each one removes duplicated code or closes a gap that more than one plugin would otherwise work around. They are collected here so they are built once, before the plugins that need them.

**Status:** Active, not yet implemented. Written 2026-10-10 from reviewer1's findings on the Volunteers, Donations and Bookings specs.

**Layout:** Part 1 is the calendar and store cleanups (C1, C2, C9, C10, C11). Part 2 is email handlers and reply addresses (A–E), for Boards. Each plugin has its own spec and points here for anything it needs from core.

**Scope rule:** every item changes code that already exists and has tests nearby. Nothing here adds a feature a site owner would see; the visible gain arrives with the plugins.

| ID | Change | Needed by | Build |
|---|---|---|---|
| C1 | One recurrence-rule class | Volunteers | Now |
| C2 | One VEVENT builder | Volunteers, Bookings | Now |
| C10 | Retire the dormant session counter | Bookings (N2 credits) | Now |
| C11 | Calendar registry collapses duplicate items | Bookings (calendar sync) | Now |
| C9 | Waitlist promotion and attendance helpers | Event Manager, Volunteers | Open (Q1) |

Part 2 below holds the email pieces (A–E) that the Boards spec needs. The store changes Donations needs (S1–S6) stay in the Donations spec §4, because they are specific to it.

---

# Part 1: Calendar and store cleanups

## C1. One recurrence-rule class

**What it does for the user:** a repeating event, calendar entry or volunteer shift lands on the same dates, and a daylight-saving or month-end bug is fixed once.

**Today.** Two copies of the same arithmetic exist:

- `CalendarEntry` (`data/entries_class.php`): `_recurrence_anchor`, `_date_matches`, `compute_dates_in_range`, `nth_occurrence_date`, `get_recurrence_description`.
- `Event` (`plugins/event_manager/data/events_class.php`): `date_matches_pattern`, `compute_occurrence_dates`, `get_recurrence_description`.

Volunteers would add a third. All three read the same five columns (`*_recurrence_type`, `_interval`, `_days_of_week`, `_week_of_month`, `_end_date`).

**Design.** A plain class `RecurrenceRule` in `includes/`, built from the five values and a start date. It has no database access.

- `RecurrenceRule::fromArray(type, interval, days_of_week, week_of_month, end_date, start_date)`
- `datesInRange($from, $to): string[]` (Y-m-d, inclusive)
- `nthOccurrence($count): ?string`
- `matches($date): bool`
- `describe(): string`

`CalendarEntry` and `Event` keep their public method names and delegate to the class, so no caller changes. Each model builds the rule from its own columns; the class does not know the prefixes.

**Method.** Move `CalendarEntry`'s implementation across unchanged (it is the newer one, with the bounded walks), point `Event` at it, and diff the two implementations' output over a fixed set of rules before deleting `Event`'s copy. Any difference is a bug in one of them and is decided case by case, not papered over.

**Tests.** `tests/calendar/recurrence_nth_occurrence_test.php` and `plugins/event_manager/tests/event_recurrence_test.php` must pass unchanged. Add a `RecurrenceRule` unit test that runs the same rule table through both models and through the class directly.

---

## C2. One VEVENT builder

**What it does for the user:** every calendar invite and `.ics` feed on the platform escapes text, folds long lines and builds its UID the same way, so calendar apps accept all of them.

**Today.**

- `IcsHelper::generateVevent` (`includes/IcsHelper.php`) is shaped around an `Event` object. Its helpers (`escapeText`, `formatUtcDateTime`, `foldLines`, `wrapInVcalendar`, `outputIcs`) are private or event-specific.
- Bookings hand-rolls `booking_build_ics` (`plugins/bookings/logic/book_logic.php`). It does not escape or fold, strips newlines only, and builds the UID as `booking-{id}@joinery`, not the `booking-{id}@{site host}` the Bookings spec relies on for calendar echo dedup (C11).
- Volunteers would add a third.

**Design.** Add a public method to `IcsHelper`:

```
IcsHelper::buildVevent([
  'uid' => ..., 'start_utc' => ..., 'end_utc' => ...,
  'summary' => ..., 'description' => ..., 'location' => ...,
  'url' => ..., 'status' => 'CONFIRMED'|'CANCELLED', 'timezone' => optional,
])
```

It returns one VEVENT block using the existing escaping and UTC formatting. `generateVevent($event, $instance_date)` becomes a mapper that builds that array from an `Event` and calls `buildVevent`. `wrapInVcalendar` and `outputIcs` are already public and are reused as they are.

A UID helper, `IcsHelper::uid($kind, $id, $instance = null)`, returns `{kind}-{id}[-{instance}]@{site host}` so UIDs are consistent and recoverable. `booking_build_ics` becomes a mapper too.

**Tests.** `plugins/event_manager/tests/event_ics_test.php` must pass unchanged. Add one test for `buildVevent` covering escaping of commas, semicolons and newlines, folding at 75 octets, and the cancelled status. Add one for the Bookings mapper checking the UID host.

The vendored `includes/calendar-links/Generators/Ics.php` is third-party and is left alone.

---

## C10. Retire the dormant session counter

**What it does for the user:** the platform has one place that says how many sessions or credits a person has left. Bookings credits (N2) is that place.

**Today.** The store has `prd_product_details` (`plugins/store/data/product_details_class.php`): per user and product, `prd_num_sessions`, `prd_num_used` and a note. No purchase path writes to it. Only two admin pages touch it: `plugins/store/admin/admin_shadow_sessions.php` (the list, in the store admin menu as "shadow-sessions") and `adm/admin_shadow_session_edit.php` with its logic file. It has no expiry and no history. The table is empty on the dev site.

**Change.** Remove the model, the two admin pages and the logic file, the store menu entry in `plugins/store/plugin.json`, and the stray `require_once` of the class in `plugins/store/logic/cart_charge_logic.php` and `adm/logic/admin_shadow_session_edit_logic.php`. The table itself is dropped by the schema sync once the model is gone; check how `update_database` handles an orphaned table and follow the existing precedent for removing a model.

**Check first.** Before removing, confirm no deployed site has rows in `prd_product_details`. The platform has no production users yet, so the expected answer is none. If a site does have rows, they need to be copied into Bookings credits before the table goes.

**Also check.** `prd_usr_user_id` and `prd_pro_product_id` appear in the user-deletion and product-deletion rules and in the AI data-access list. Removing the model removes those rules; the deletion tests must still pass.

**Tests.** The deletion and AI-surface suites cover the removed references. No new test is needed beyond running them.

---

## C11. Calendar registry collapses duplicate items

**What it does for the user:** the same meeting never shows twice on a calendar, even when two sources both know about it, such as a Bookings appointment and its copy on the host's Google calendar.

**Today.** `CalendarItemSourceRegistry::getItems` (`includes/calendar/CalendarItemSourceRegistry.php`) appends every source's items. Each `CalendarItem` already carries a stable `source_key` such as `bookings:bkn-12`. `EventItemSource` removes duplicates inside its own output; nothing does across sources.

**Change.** In `getItems`, after a source's items pass `atVisibility`, skip any item whose non-empty `source_key` was already seen. Items without a key are never merged.

**Which copy wins.** The first source discovered wins. Discovery runs core directories first, then active plugins in order. To make the native record win over an external echo, add an optional `priority()` to the source contract with a default of 0 and sort sources by it before iterating; the native sources keep 0 and external-calendar sources return a negative number. If that is judged too much for now, order alone is enough while only one external source exists.

**Safety.** Availability is unaffected: busy blocks are merged by time range in `getBusyBlocks`, so a removed duplicate cannot free a slot that another copy still blocks. Visibility reduction happens before the check, so a `busy`-level request never leaks a title through the key.

**Tests.** Add to `tests/calendar/calendar_core_test.php`: two fake sources returning the same key give one item; items with empty keys both survive; the winner is the higher-priority source.

---

## C9. Waitlist promotion and attendance helpers (open, Q1)

**What it does for the user:** when someone cancels a full event or shift, the first person waiting gets the seat automatically, and organizers can mark who showed up.

**Today.**

- Event Manager: the waiting list (`ewl_waiting_lists`) is only a list. An admin views it and emails it by hand. Nothing promotes anyone into a freed seat, and `evr_event_registrants` has no attended mark. A registration runs through a store product, so a promoted person would need a payment link, not a silent confirmation.
- Volunteers: designed with automatic promotion and an attendance status on its own sign-up table.

**Design if built.** A small class `SeatLedger` that takes callbacks so each plugin keeps its own tables: `takeSeat` (lock the parent row, count confirmed, confirm or waitlist), `freeSeat` (promote the oldest waiting person in the same transaction and return who was promoted, so the caller sends notifications after commit) and `markAttendance` (status plus an optional hook). Each plugin supplies "count confirmed", "list waiting, oldest first" and "confirm this person".

**Who else could use it.** Only Event Manager and Volunteers have capacity today. Bookings sends group classes to Event Manager, and Boards and Donations have no capacity. A Courses plugin with cohorts, or Bookings resource pools, would be future users but neither is specified. The case that stands on its own is Event Manager's missing promotion.

**Recommendation.** Build it against Event Manager's waiting list first, with Volunteers as the second user, or leave it out until a third plugin needs it. Volunteers carries its own short version until then.

---

## Order and testing

1. C10 and C11 are independent and can be built in any order.
2. C1 before the Volunteers plugin. C2 before the Volunteers and Bookings work that builds invites.
3. Run `php tests/run.php db --changed` after each change.

## Docs

Update `docs/calendar.md` for C1, C2 and C11, and the store overview for C10, as current-state descriptions only.

## Questions

- **Q1. Include C9?** *Yes:* Event Manager gets automatic waitlist promotion and attendance, and Volunteers uses the shared code. The catch is designing for a payment link on promotion, and a second caller that does not yet exist for anything else. *No:* Volunteers carries its own version and the code is lifted into core when a third user appears. Recommend yes only if the Event Manager gap is worth fixing on its own.

---

# Part 2: Email handlers and reply addresses

**Purpose:** Let any plugin receive email and be replied to by email. Today an email arriving at the site can only be forwarded or stored in a mailbox, and a notification email can't be answered. This spec adds the small, general pieces that make both possible:

- **A.** A stored mailbox address can also hand each new message to a plugin.
- **B.** A plugin can give each person a secret reply address that ties a reply back to them and to the thing they're replying about.
- **C.** A notification can declare its reply address and its thread, with no plugin code.
- **D.** One shared helper pulls the new text out of a reply.
- **E.** Other plugins can add a button to the mailbox's message reader.

**Status (this part):** Reviewed 2026-10-10 (reviewer2); findings applied.

**First consumer:** the Boards plugin spec, for email-to-board, reply-by-email comments and "Make a card". Possible later users are event RSVP-by-reply, messenger reply-by-email and support tickets. None of the pieces knows anything about Boards.

**Where things live:**

| Pieces | Home | Why |
|---|---|---|
| A, B, E | Mailbox plugin | Receiving mail is mailbox's job. |
| C, D | Core | Notify and outbound email are core, and D is plain text handling. |

If the mailbox plugin is inactive, A, B and E don't exist, and consumers check for them with `class_exists('InboundReplyAddress')`. C's thread headers work either way. Its reply address is simply left off when B isn't there.

**Boundary.** Handlers are for mail addressed to the site's own features. Answering outside senders as a team (shared inboxes) is the mailbox group-collaboration spec's job, not this one.

---

### A. Handler aliases (mailbox)

#### The idea

A handler alias is an ordinary **store-mode** alias that also names a plugin handler.

1. The router stores each message exactly as it does today.
2. It then hands the stored message to the plugin.

So spam checks, duplicate detection, attachment Files, raw retention, the message timeline, sealing rules and retention all work unchanged. If a handler fails, the mail is still readable in the mailbox for an admin, rather than lost.

#### Schema and declaration

- **New column.** `iea_inbound_email_aliases` gains one nullable column, `iea_handler` (varchar 64, the handler key). It is only meaningful on a store-mode alias.
- **Manifest key.** Plugins declare handlers in `plugin.json`:
  ```json
  "inboundHandlers": {
      "boards": { "class": "BoardsInboundHandler", "label": "Boards" }
  }
  ```
- **Registry.** `InboundHandlerRegistry` (mailbox `includes/`) reads the key from active plugins, the same loop SignalBus and DirectKinds use.
- **Inactive plugin.** If the plugin is inactive, the class doesn't resolve. The message stays stored, and the routing log notes "handler not available".

#### One alias per handler

Each handler key owns at most one alias, and its local part is the handler key itself (`boards@`). The registry enforces this.

- **`InboundHandlerRegistry::ensureAlias($handler_key, $domain)`** is called from the plugin's `sync.php`. It is idempotent: it creates the alias, re-enables it, or moves it to a new domain.
- **It refuses** when the local part is already taken by an alias that isn't this handler's, or when the alias would seal content. Either way it returns the reason.
- **`InboundHandlerRegistry::eligibleDomains()`** lists the domains where a new alias would not seal content: Standard level, and not sealed at a relay. A plugin offers these in its settings.
- **`InboundHandlerRegistry::aliasFor($handler_key)`** returns the alias, or `null`.

#### Sub-addresses

A handler alias accepts sub-addresses: mail to `boards+anything@example.org` is stored under `boards@example.org`, and `anything` is the *tag*. Aliases without a handler are never sub-addressed, so their behaviour doesn't change.

The fallback lives in one place on each side, so every path agrees:

- **Site.** `InboundEmailRouter::lookupAlias()` and `InboundEmailAlias::GetByAddress()` try the part before `+` when there is no exact match, and use it only if that alias has a handler. The pipe, webhook, relay-pull (`RelaySpoolConsumer::ingest`) and deferred-ingest paths all go through these two, so they agree without changes of their own.
- **Recipient check at the site's mail server.** `recipient_access_query()` (`provisioning/render_pgsql_map.php`) gains one clause: accept `%u` when `split_part(lower('%u'), '+', 1)` names an enabled alias with `iea_handler IS NOT NULL` on the domain. `recipient_lookup_test` gains these cases:
  - `alias+tag` on a handler alias is accepted;
  - `alias+tag` on an alias without a handler follows the unknown-address rule;
  - `unknown+tag` is refused where unmatched mail is refused;
  - the router agrees in every case.
- **Recipient check at the relay.** `relay-sealer/routing.go` `resolve()` matches the exact address only. It gains the same fallback, and the exported routing map marks handler aliases so the relay knows which bases accept a `+tag`. This ships as a **relay-sealer release, deployed separately from the site**. Until a site's relay runs it, sub-addresses on relay-fronted domains are refused at the relay.

#### Deploying the recipient-check change

The site's mail server reads a query file that is written once by `install_email.sh` (`/etc/postfix/joinery-recipient-access.cf`). Nothing re-writes it on upgrade, and `recipient_lookup_test` tests the PHP string, not the deployed file.

The requirement: a changed query reaches every host where Postfix is colocated on the next upgrade, using the same validate-then-swap logic `install_email.sh` uses, and drift is visible.

- **Mechanism.** The implementation first checks what rights the upgrade runs with on a node. If it can write `/etc/postfix`, the re-render is a mailbox upgrade step. If not, it is an agent recipe the upgrade triggers.
- **Drift check.** `InboundEmailHealth` compares the deployed query with `recipient_access_query()` and reports any difference as an ordinary health problem.

#### The hand-over

After `storeMessage` succeeds for an alias with a handler, the router:

1. Checks `!$alias->seals_content()`. The protection level is per alias, and inherits from the domain. A sealing alias never hands over; the mail stays stored.
2. Skips messages the spam decision filed as junk, and duplicates (the existing Message-ID dedup already returns before a second store).
3. Calls the handler:
   ```php
   interface InboundAddressHandler {
       /** Return true when the message was handled; false for "not mine". */
       public function handle(InboundEmailMessage $msg, ?string $tag): bool;
   }
   ```

The handler reads the stored row the usual way: sender, subject, bodies, threading headers and attachment Files.

Every outcome is written to the routing log: handled, not mine, handler missing, or the handler threw. A thrown exception is caught and logged with the handler key, and the stored message is left in place. There is no retry; a handler that needs one keeps its own queue.

**Sender check helper.** `InboundReplyAddress::senderIs(InboundEmailMessage $msg, User $user): bool` is true when the message's sender address matches the user's account email, compared case-insensitively. It is the standard second check after a reply address (B).

#### Paths that never hand over

IMAP-polled accounts, archive imports, Joinery Direct, and relay user and client deliveries never reach a handler alias. They mirror or carry someone else's mailbox.

---

### B. Reply addresses (mailbox)

A reply address is a secret, unguessable address that stands for "this person, about this thing". For example, `boards+r7k2…@example.org` means "Alice, about card 42".

When a reply arrives, the handler knows who sent it and what it's about, without trusting the `From` header alone.

**Table `ira_inbound_reply_addresses` (`InboundReplyAddress`):**

| Column | Detail |
|---|---|
| `ira_token` | Unique; 26 characters of random base32 |
| `ira_handler` | The handler key |
| `ira_usr_user_id` | The person |
| `ira_ref_type` | Varchar, chosen by the handler, for example `card` or `board` |
| `ira_ref_id` | int8 |
| `ira_create_time` | |
| `ira_delete_time` | Revoking an address soft-deletes it |

**Uniqueness and retention:**

- `unique_with` on `(ira_handler, ira_usr_user_id, ira_ref_type, ira_ref_id)`. Because the table is soft-deletable, DatabaseUpdater makes this a partial unique index over live rows, so the same person and thing always reuse one live address.
- A `$retention_policy` purges rows deleted more than 90 days ago, swept by the core `RetentionSweep` task.

**API (static methods on `InboundReplyAddress`):**

| Method | What it does |
|---|---|
| `addressFor(string $handler, int $user_id, string $ref_type, int $ref_id): ?string` | Returns `{handler}+{token}@{alias domain}`, creating the row on first use. Returns `null` when the handler has no alias. |
| `resolve(string $handler, ?string $tag): ?InboundReplyAddress` | Returns the live row for that token and handler, or `null`. |
| `revoke(string $handler, int $user_id, string $ref_type, int $ref_id)` | Revokes one address. Resetting an address is revoke followed by `addressFor`. |
| `revokeAllFor(string $handler, string $ref_type, int $ref_id)` | Revokes every address for a thing, for example when a card is permanently deleted. |
| `revokeAllForUser(int $user_id)` | Revokes everything for a person. Called from user soft delete and permanent delete. |

**The standard check a handler makes:**

1. `resolve()` the tag.
2. Check `senderIs()` against the address's user.
3. Check the handler's own permission: is this person still allowed to act on this thing?

Mail that fails any step is left stored and is not acted on. The handler decides whether to tell the user, for example with an in-app notice that an email to their address came from someone else.

**Why both a token and a `From` check.**

- With the token alone, anyone holding a forwarded notification could act as that person.
- With `From` alone, anyone could forge the header.
- Together, a forger needs both the secret address and the person's email address.

---

### C. Reply address and thread on notifications (core)

A signal's `notify` block gains two optional keys:

```json
"notify": {
    "ntf_type": "boards",
    "default_email": true,
    "title_template": "[{board_name}] {card_title}",
    "thread": "card-{card_id}",
    "reply_address": { "handler": "boards", "ref_type": "card", "ref_id": "{card_id}" }
}
```

- **`thread`** is rendered against the payload like the other templates. Notify sets both `In-Reply-To` and `References` to `<{rendered}@{site host}>`. Every email for the same rendered thread therefore lands in one conversation in Gmail, Apple Mail and Outlook, as long as the subject stays constant, which the title template controls.
  - We don't pin our own `Message-ID`. Some providers (Brevo, Postmark, Resend, SendGrid, Mailjet) replace it. Every provider passes `In-Reply-To` and `References` through, and those are what clients thread on.
- **`reply_address`** is rendered per field. For each email recipient, Notify calls `InboundReplyAddress::addressFor()` when that class exists, and uses the result as the Reply-To. If the class is missing, or the call returns `null`, the email goes out without a Reply-To.

**Plumbing:**

- `equ_queued_emails` gains `equ_reply_to` (varchar 255, nullable) and `equ_thread_ref` (varchar 255, nullable, the rendered `<…@host>` id).
- `QueuedEmail::send` applies them with `EmailMessage::replyTo()` and `header('In-Reply-To', …)` / `header('References', …)`.
- `EmailSender::queueForRetry` copies both onto the retry row, so a notification whose first send fails keeps its reply address on retry.
- `Notify::_enqueue_email` is already called per recipient. It takes the two values as new parameters.

Signals without these keys behave exactly as they do today.

---

### D. Reply text extraction (core)

`EmailReplyText::extract(string $plain, ?string $html = null): string` returns only the new text of a reply. It cuts:

- quoted blocks (`>` lines);
- "On … wrote:" headers in the common languages and in Outlook's "From: … Sent: …" form;
- Gmail's `gmail_quote` and Outlook's `divRplyFwdMsg` / `appendonsend` containers in the HTML part (used when the plain part is missing or is the whole quoted thread);
- signatures after `-- `;
- "Sent from my iPhone"-style footers.

It returns `''` when nothing new is left.

Fixtures live in `tests/fixtures/email_replies/`: real replies from Gmail web, Gmail iOS, Apple Mail, Outlook web, Outlook desktop and Thunderbird, each with its expected output.

---

### E. Message reader actions (mailbox)

Today the reader's action bar is fixed. Other plugins need a way to add a button to a message, such as Boards' **Make a card**.

**Declaration.** Plugins declare reader actions in `plugin.json`:

```json
"mailboxMessageActions": {
    "boards_make_card": { "label": "Make a card", "icon": "tasks", "url": "/profile/boards/from_message" }
}
```

**How it works.**

- The reader's mount data carries the declared actions from active plugins once per page, not once per message.
- `mailbox_reader.js` renders them after the built-in buttons, opening `{url}?message_id=N`.
- It hides them on sealed messages, which it already knows per message (`m.sealed`, `fortress_placeholder`).
- The target page reads the message through the mailbox's own access checks, so an action never widens access to a message. When the action doesn't apply to this user, the page says so.

---

### Docs

These docs change to describe the new pieces:

- **`plugins/mailbox/docs/overview.md`:** handler aliases, sub-addresses, reply addresses and reader actions.
- **`docs/notifications.md`:** the `thread` and `reply_address` keys. Correct the line calling targeted `recipients` unused, since mailbox, site and server-manager signals use it (also the matching comment in `Notify.php`).
- **`docs/email_system.md`:** a pointer to `EmailReplyText`.
- **`docs/plugin_developer_guide.md`:** the `inboundHandlers` and `mailboxMessageActions` keys in the `plugin.json` reference.

---

### Testing

Every test uses the shared harness and an `@joinery-test` header.

**`plugins/mailbox/tests/inbound_handler_test.php`** (test-db):

- Registry loading.
- A message is stored and then handed over.
- Sub-address fallback through `lookupAlias` and `GetByAddress`, for handler aliases only.
- Junk and duplicates are never handed over.
- A sealing alias never hands over.
- "Not mine", a missing handler and a thrown exception are each logged, and the message stays stored.
- `ensureAlias` is idempotent, moves domains, and refuses a taken local part.

**`plugins/mailbox/tests/recipient_lookup_test.php`:** the handler sub-address cases above.

**Relay-sealer Go tests:** the `+tag` fallback for handler aliases only.

**`plugins/mailbox/tests/inbound_reply_address_test.php`** (test-db):

- One live address per (handler, user, ref), and a new one after revoke.
- `resolve` refuses revoked and other-handler tokens.
- `senderIs` is case-insensitive.
- `revokeAllForUser` runs on user delete.

**`tests/unit/notify_thread_reply_test.php`** (db):

- `thread` and `reply_address` are stored on the queued row and sent as headers.
- `queueForRetry` keeps them.
- Without the mailbox plugin, the email still goes out with no Reply-To.
- A signal without the keys is unchanged.

**`tests/unit/email_reply_text_test.php`** (safe): every fixture.

**Deploy check:** `InboundEmailHealth` reports a deployed recipient map that differs from `recipient_access_query()`.

**Live walk on dev:**

- A test handler alias on the dev inbound domain.
- Send to `alias+tag@` from an outside mailbox and see the message stored and the handler log the tag.
- Reply to a notification from Gmail and Apple Mail, and see `resolve` match and the replies thread under the original.
