# Core Improvements for Plugins

**Purpose:** The one list of core, store and mailbox-plugin work that the plugin specs depend on. Small changes to existing core and store code that the Boards, Volunteers, Donations and Bookings plugin specs depend on or are simpler for. Each one removes duplicated code or closes a gap that more than one plugin would otherwise work around. They are collected here so they are built once, before the plugins that need them.

**Status:** Active, not yet implemented. Written 2026-10-10 from reviewer1's findings on the Volunteers, Donations and Bookings specs.

**Layout:** Part 1 is the calendar and store cleanups (C1, C2, C9, C10, C11). Part 2 is email handlers and personal addresses (A, B, E), for Boards. (C and D, which served reply-by-email, were removed on 2026-10-11 when the owner dropped that feature.) Each plugin has its own spec and points here for anything it needs from core.

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

# Part 2: Email handlers and personal addresses

**Purpose:** Let any plugin receive email addressed to one of its features. Today an email arriving at the site can only be forwarded or stored in a mailbox. This spec adds the small, general pieces that make a plugin able to act on it:

- **A.** A stored mailbox address can also hand each new message to a plugin.
- **B.** A plugin can give each person a secret address that ties an incoming message back to them and to the thing it is about (for Boards: their address for one board).
- **E.** Other plugins can add a button to the mailbox's message reader.

**Status (this part):** Reviewed 2026-10-10 (reviewer2); findings applied. **Built 2026-10-11** (uncommitted): A, B and E in the mailbox plugin, and the relay `+tag` fallback (relay version 3.9). Not yet done: the relay release itself (the publish pipeline rebuilds and signs the sealer binaries), and the live walk on dev.

**Built as:**
- A: `iea_handler` on the alias; `InboundAddressHandler`, `InboundHandlerRegistry`; `InboundEmailAlias::resolveRecipient()` holds the `+tag` rule for `lookupAlias`/`GetByAddress`; `InboundEmailRouter::handOver()` logs a `handler` line per outcome; the recipient query clause and the `iea_handler` grant (`install_email.sh` adds the column to the grant only once `update_database` has created it); `InboundEmailHealth::checkHandlerAliasLevels()`; grants on a handler alias refused. Relay: `accepts_tag` in the exported map, `resolve()` fallback, and a regexp accept line per such alias in the relay's `joinery-srs` map (the recipient access map is exact-address, so the fallback alone would have been refused at RCPT).
- B: `InboundPersonalAddress` (`senderIs`, `userIsActive`), `InboundEmailMessage::senderAttested()`/`senderAddress()`. Attestation reads the signing domain from the verifying server's own Authentication-Results line where there is one, so a forged `DKIM-Signature` header beside a valid signature for another domain does not pass; where a provider gave only a verdict the headers stand in.
- E: `MailboxMessageActions` (`declared()`, `openForAction()`), `MailboxViewer::canReadMessage()`, the reader's `messageActions` mount data and menu items.
- Settings: `mailbox_personal_address_retention_days` (90).
- Tests: `inbound_handler_test.php`, `inbound_personal_address_test.php`, `mailbox_message_actions_test.php`, the `recipient_lookup_test.php` cases, and the relay-sealer Go test `routing_subaddr_test.go`.

**First consumer:** the Boards plugin spec, for email-to-board and "Make a card". A possible later user is an event RSVP-by-email or support-ticket feature. None of the pieces knows anything about Boards.

**Where things live:**

| Pieces | Home | Why |
|---|---|---|
| A, B, E | Mailbox plugin | Receiving mail is mailbox's job. |

If the mailbox plugin is inactive, A, B and E don't exist, and consumers check for them with `class_exists('InboundPersonalAddress')`.

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

Each handler key owns at most one alias **per domain**, and its local part is the handler key itself (`boards@clubA.org`, `boards@clubB.org`). The registry enforces this. Decided 2026-10-11: a plugin may use several domains (Boards lets each board choose one), so there is one alias per handler per domain and the plugin asks for the ones it needs.

- **`InboundHandlerRegistry::ensureAlias($handler_key, $domain)`** is idempotent: it creates the handler's alias on that domain or re-enables it. A plugin calls it when it first needs a domain (its `sync.php` for the default domain, and again when a user or setting picks another). It never moves or deletes an alias: a domain a plugin stops using keeps its alias, so an address already given out there keeps working.
- **It refuses** when the local part is already taken by an alias that isn't this handler's, or when the alias would seal content. Either way it returns the reason.
- **`InboundHandlerRegistry::eligibleDomains()`** lists the domains where a new alias would not seal content: Standard level, and not sealed at a relay. A domain whose mail arrives through a relay is eligible only when that relay runs at least `RelayVersion::ACCEPTS_SUBADDRESSES` (the version that carries the `+tag` fallback below; the same pattern as `SEALS_FOR_BROWSERS` and `ANSWERS_HEALTH`, read from the relay's own health answer). Decided 2026-10-11: ship the relay release with this work and gate on its version, so an older relay never has a personal address handed out for it. `ensureAlias()` refuses such a domain with that reason, and the plugin's panel says "email needs relay version X". A plugin offers the eligible domains in its settings.
- **`InboundHandlerRegistry::aliasFor($handler_key, $domain)`** returns the alias on that domain, or `null`.
- **`InboundHandlerRegistry::defaultDomain()`** is the first eligible domain in a stable order (lowest domain id); what a plugin uses when nothing has been chosen. With one eligible domain it is the only choice, and a plugin has no domain picker to show.

#### Sub-addresses

A handler alias accepts sub-addresses: mail to `boards+anything@example.org` is stored under `boards@example.org`, and `anything` is the *tag*. Aliases without a handler are never sub-addressed, so their behaviour doesn't change.

Decided 2026-10-11: this is done by an explicit rule for handler aliases, **not** by turning on Postfix's `recipient_delimiter`. On a domain that rejects unknown addresses the recipient query answers the full address `boards+tag` with a 550, which Postfix treats as a definite answer, so it never falls back to `boards@`; and the delimiter would make every mailbox on the site accept `+tags`, changing behaviour nobody asked to change.

The fallback lives in one place on each side, so every path agrees:

- **Site.** `InboundEmailRouter::lookupAlias()` and `InboundEmailAlias::GetByAddress()` try the part before `+` when there is no exact match, and use it only if that alias has a handler. The pipe, webhook, relay-pull (`RelaySpoolConsumer::ingest`) and deferred-ingest paths all go through these two, so they agree without changes of their own.
- **Recipient check at the site's mail server.** `recipient_access_query()` (`provisioning/render_pgsql_map.php`) gains one clause: accept `%u` when `split_part(lower('%u'), '+', 1)` names an enabled alias with `iea_handler IS NOT NULL` on the domain. `recipient_lookup_test` gains these cases:
  - `alias+tag` on a handler alias is accepted;
  - `alias+tag` on an alias without a handler follows the unknown-address rule;
  - `unknown+tag` is refused where unmatched mail is refused;
  - the router agrees in every case.
- **Recipient check at the relay.** `relay-sealer/routing.go` `resolve()` matches the exact address only. It gains the same fallback, and the exported routing map marks handler aliases so the relay knows which bases accept a `+tag`. This ships as a **relay-sealer release, deployed separately from the site**. Until a site's relay runs it, sub-addresses on relay-fronted domains are refused at the relay.

#### Deploying the recipient-check change

The query ships in `plugins/mailbox/provisioning/install_email.sh`, the mailbox plugin's declared `host_installer`. Every upgrade (`utils/upgrade.php`) and the host converger (within a minute of a release, plugin manifest or active-plugin change) run it as root. It already renders the query, asks it once as the map database role, and replaces the installed file only if that answered (validate-then-swap); a failed probe leaves the old file in place. Containers have no Postfix and skip it. Nothing more is added: no upgrade step, no agent recipe and no drift check (the file is `640 root:postfix`, so the web user could not read it to compare).

#### The hand-over

After `storeMessage` succeeds for an alias with a handler, the router:

1. Checks `!$alias->seals_content()`. The protection level is per alias, and inherits from the domain. A sealing alias never hands over; the mail stays stored.
2. Skips duplicates (the existing Message-ID dedup already returns before a second store) and mail the spam decision filed as junk for a hard reason: failed authentication or the scanner's high-score rule (`reason` is `auth` or `scanner`). A junk verdict from the learned guesses (`sender_history`, `bayes`) does not block the hand-over (decided 2026-10-11): a message with a member's secret address that passes `senderIs()` is not junk, and nobody trains the handler alias's mailbox, so a wrong guess would silently drop a genuine message.
3. Calls the handler:
   ```php
   interface InboundAddressHandler {
       /** Return true when the message was handled; false for "not mine". */
       public function handle(InboundEmailMessage $msg, ?string $tag): bool;
   }
   ```

The handler reads the stored row the usual way: sender, subject, bodies, threading headers and attachment Files.

Every outcome is written to the routing log: handled, not mine, handler missing, or the handler threw. A thrown exception is caught and logged with the handler key, and the stored message is left in place. There is no retry; a handler that needs one keeps its own queue.

**Sender check helper (decided 2026-10-11).** `InboundPersonalAddress::senderIs(InboundEmailMessage $msg, User $user): bool` is the standard second check after a secret address (B). The `From` header alone is forgeable, so it is true only when both hold:

1. **The sender is the member.** The message's address (the addr-spec parsed out of `iem_sender`, which holds the display string "Name <addr>") matches the user's account email, or their verified recovery email (`usr_recovery_email` with `usr_recovery_email_verified_time`), case-insensitively.
2. **The message is attested** — `InboundEmailMessage::senderAttested()`, one rule for every handler, built on the verdicts the router already records at ingest (`iem_dmarc_result`, `iem_dkim_result`, `iem_spf_result`; see `InboundEmailRouter::readAuthResults`):
   - DMARC `pass`; or, because some providers report no DMARC verdict (Mailgun), 
   - DKIM `pass` with the `d=` of the DKIM-Signature equal to the From domain, or SPF `pass` with the Return-Path domain equal to the From domain, both read from `iem_raw_headers`.

A message that fails either test is left stored and not acted on, and the member is told in-app, naming the address it came from ("…came from bob@example.net, which isn't an address on your account, or couldn't be verified as coming from that domain"). A domain that cannot be verified therefore cannot use email-to-board; the Boards panel says so.

**A handler alias takes no grants (decided 2026-10-11).** Every message sent to a handler alias is stored in its mailbox, and for Boards that holds invite-only board content. Superadmins read it by oversight (they are admin on every board anyway); a grant on that mailbox to anyone else would expose boards they cannot open, so the mailbox admin refuses to grant a handler alias, and the plugin's docs say so.

**A handler alias on a domain raised to Private later.** An alias takes its level from its domain live, so the router keeps storing but stops handing over. `InboundEmailHealth` reports a handler alias on a sealing domain as an ordinary health problem, and a plugin's panel reads `seals_content()` and says its email is off.

#### Paths that never hand over

IMAP-polled accounts, archive imports, Joinery Direct, and relay user and client deliveries never reach a handler alias. They mirror or carry someone else's mailbox.

---

### B. Personal addresses (mailbox)

A personal address is a secret, unguessable address that stands for "this person, about this thing". For example, `boards+r7k2…@example.org` means "Alice, about board 7".

When a message arrives at one, the handler knows who sent it and what it's about, without trusting the `From` header alone.

**Table `ipa_inbound_personal_addresses` (`InboundPersonalAddress`):**

| Column | Detail |
|---|---|
| `ipa_token` | Unique; 26 characters of random base32 |
| `ipa_handler` | The handler key |
| `ipa_usr_user_id` | The person |
| `ipa_ref_type` | Varchar, chosen by the handler, for example `card` or `board` |
| `ipa_ref_id` | int8 |
| `ipa_create_time` | |
| `ipa_delete_time` | Revoking an address soft-deletes it |

**Uniqueness and retention:**

- `unique_with` on `(ipa_handler, ipa_usr_user_id, ipa_ref_type, ipa_ref_id)`. Because the table is soft-deletable, DatabaseUpdater makes this a partial unique index over live rows, so the same person and thing always reuse one live address.
- A `$retention_policy` purges rows deleted more than 90 days ago, swept by the core `RetentionSweep` task.

**API (static methods on `InboundPersonalAddress`):**

| Method | What it does |
|---|---|
| `addressFor(string $handler, int $user_id, string $ref_type, int $ref_id, ?int $domain_id = null): ?string` | Returns `{handler}+{token}@{domain}`, creating the row on first use. The domain is the one given, or the registry's default. Returns `null` when the handler has no alias on it (no eligible domain). The token is not tied to a domain: `resolve()` finds it from whichever domain's alias the mail arrived at. |
| `resolve(string $handler, ?string $tag): ?InboundPersonalAddress` | Returns the live row for that token and handler, or `null`. |
| `revoke(string $handler, int $user_id, string $ref_type, int $ref_id)` | Revokes one address. Resetting an address is revoke followed by `addressFor`. |
| `revokeAllFor(string $handler, string $ref_type, int $ref_id)` | Revokes every address for a thing, for example when a card is permanently deleted. |

**The standard check a handler makes:**

1. `resolve()` the tag.
2. Check `senderIs()` against the address's user.
3. Check that the person's account is active (not deleted or disabled; a soft delete leaves their address row in place), and then the handler's own permission: is this person still allowed to act on this thing? Because this check runs at the moment of use, an address held by someone who has since lost access (removed from a group, say, which fires no event) is harmless, so addresses are **not** revoked on access loss.

Mail that fails any step is left stored and is not acted on. The handler decides whether to tell the user, for example with an in-app notice that an email to their address came from someone else.

**Why both a token and a `From` check.**

- With the token alone, anyone holding the address (a copied link, a forwarded message) could act as that person.
- With `From` alone, anyone could forge the header.
- Together, a forger needs the secret address and a message that passes authentication for the person's own address.

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
- **Standard-level mailboxes only (decided 2026-10-11).** Those flags (`m.sealed`, `fortress_placeholder`) mark Fortress rows only; a Private-level row is decrypted for the viewer and carries no sealed flag, so the reader hides actions by the mailbox's `security_level` (already in the mount data). The target page also refuses `$alias->seals_content()` before it reads anything. Putting decrypted text into a Standard-level table would break the promise of the level, and a sealed attachment is a blob a plugin's own File could never open. This is the same rule the hand-over in A uses.
- The target page reads the message through the mailbox's own access checks, so an action never widens access to a message. When the action doesn't apply to this user, the page says so.
- **One shared access method (decided 2026-10-11).** `MailboxViewer::canReadMessage(InboundEmailMessage $m): bool` holds the rule `profile_original_logic.php` applies today: a viewer with a grant on the message's mailbox may read it, and a message that belongs to no mailbox (a NULL alias) is readable only by an all-access viewer. A target page calls it instead of re-deriving the rule.
- **Attachments.** A plugin adopts a stored attachment without copying bytes: `File::createFromExistingBlob()` shares the refcounted blob and returns a new File the plugin gates itself (Boards: `BoardsUploads::adopt()`). Attachments on sealed messages are out of scope: actions are Standard-level only (above).

---

### Docs

These docs change to describe the new pieces:

- **`plugins/mailbox/docs/overview.md`:** handler aliases, sub-addresses, personal addresses and reader actions.
- **`docs/notifications.md`:** correct the line calling targeted `recipients` unused, since mailbox, site, server-manager and Boards signals use it (also the matching comment in `Notify.php`).
- **`docs/plugin_developer_guide.md`:** the `inboundHandlers` and `mailboxMessageActions` keys in the `plugin.json` reference.

---

### Testing

Every test uses the shared harness and an `@joinery-test` header.

**`plugins/mailbox/tests/inbound_handler_test.php`** (test-db):

- Registry loading.
- A message is stored and then handed over.
- Sub-address fallback through `lookupAlias` and `GetByAddress`, for handler aliases only.
- Duplicates, and junk filed for the `auth` or `scanner` reason, are never handed over; junk filed for `bayes` or `sender_history` still is.
- A sealing alias never hands over.
- "Not mine", a missing handler and a thrown exception are each logged, and the message stays stored.
- `ensureAlias` is idempotent, creates one alias per domain, never moves one, and refuses a taken local part and an ineligible domain (sealing, or a relay below `RelayVersion::ACCEPTS_SUBADDRESSES`).
- `addressFor` uses the domain given, falls back to `defaultDomain()`, and returns `null` when there is none.

**`plugins/mailbox/tests/recipient_lookup_test.php`:** the handler sub-address cases above.

**Relay-sealer Go tests:** the `+tag` fallback for handler aliases only.

**`plugins/mailbox/tests/inbound_personal_address_test.php`** (test-db):

- One live address per (handler, user, ref), and a new one after revoke.
- `resolve` refuses revoked and other-handler tokens.
- `senderIs` is case-insensitive, accepts the verified recovery email, and refuses an unattested message (no DMARC pass and no aligned DKIM or SPF pass).
- Deleting a user removes their addresses (a `$foreign_key_actions` entry on `ipa_usr_user_id`, no hook in the user model).


**Live walk on dev:**

- A test handler alias on the dev inbound domain.
- Send to `alias+tag@` from an outside mailbox and see the message stored and the handler log the tag.
- Send to a personal `alias+token@` address from the member's own mailbox and see `resolve` match.
