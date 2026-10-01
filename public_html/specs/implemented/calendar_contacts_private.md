# Calendar and contacts: the Private rung

**Status: BUILT 2026-10-01 (both parts; uncommitted, owner commits).** Work
item 6 of `specs/protection_levels_platform.md` (calendar), plus the contacts
gap that spec did not list. Doctrine, the ladder and the add-on rules live
there and in `docs/protection_levels.md`; this spec only says what each of the
two services builds. Everything under "Today" was read from the tree on
2026-10-01, before the build. Current state is in `docs/calendar.md`
§ Protection level and the mailbox overview § Contacts; one draft claim was
corrected at build (Part 2, build step 1). Tests:
`tests/functional/calendar/level_change_test.php` (36 checks) and
`plugins/mailbox/tests/contact_level_converge_test.php` (33 checks).

## Intent

A member who has made their mail Private should be able to make their calendar
Private with the same word meaning the same thing: encrypted at rest, opened
only while they are signed in and unlocked, AI and reminders still working
where they honestly can. Their contact list should already match the mailbox it
belongs to, with no second choice to make, and the mailbox's level change should
take the contacts with it.

Neither service gains add-ons or a Fortress rung. Calendar has no door of its
own to guard (no inbound traffic, no public feed, no outside sender), and
client-custody calendar would black out the AI scheduling it exists to feed.
Contacts are a cache of a mailbox, and take that mailbox's protection.

---

## Part 1 — Calendar

### Today

- **Storage.** Native entries are `cal_entries` (`CalendarEntry`,
  `data/entries_class.php`). Content columns: `cal_title` (varchar 255),
  `cal_location` (varchar 255), `cal_link` (text), `cal_notes` (text).
  Everything else describes the schedule: UTC and wall-clock times, zone,
  all-day, `cal_blocks_availability`, `cal_status`, reminder lead, recurrence
  pattern, parent/exception links, `cal_source` + `cal_source_event_id`
  (dedup key for imports and AI proposals).
- **Owner.** A `CalendarSubject` stored as `cal_subject_type` +
  `cal_subject_id`; `user` is the only implemented type.
- **Readers all go through the model.** `NativeCalendarItemSource` builds
  `CalendarItem` value objects with `$entry->get('cal_title')` and friends; the
  calendar page, the entry editor (`calendar_entry`), the feed
  (`calendar_feed`), the `.ics` importer, `CalendarEntryImporter` (AI writes)
  and `CalendarEmailEngine` are the only sites that touch the four content
  columns outside the model. No raw-row reader exists.
- **No export.** `.ics` is import-only; there is no feed URL and no CalDAV.
- **Reminders and summaries** run from cron (`CalendarEmails` task).
  `CalendarEmailEngine::reminderVars()` is already documented as the single
  chokepoint deciding what an email may say about an entry; the
  `calendar_reminder` template renders a generic "You have a calendar entry
  coming up" when the vars carry only the time. Summaries list every item from
  the registry aggregation.
- **AI.** `CalendarEntry::$ai_readable = true` with a polymorphic owner scope;
  the generic model reader decrypts through the same hooks every sealed model
  uses. AI writes go through `CalendarEntryImporter::upsert()` (ordinary
  `set()`/`save()`), either from the `create_calendar_entry` action or an
  approved `email_schedule` proposal.
- **Native app.** JoineryCalendarKit consumes `calendar_feed`,
  `calendar_entry`, `calendar_entry_save`, `calendar_entry_delete`.
- **Per-user calendar row.** `cpr_calendar_preferences` (`CalendarPreference`),
  one row per user, absence means defaults; `CalendarPreference::get_for()`
  never returns null.
- **Setup wizard** has a Calendar step (`includes/setup_steps/calendar.php`:
  import an `.ics`, reminder/summary preferences). The encryption-key step
  comes before it, so the viewer may already hold a vault there.

### The rung

**One dial per personal calendar, two cards: Standard / Private.** The level
lives on the member's `cpr_calendar_preferences` row as `cpr_protection_level`
(varchar 10, default `standard`). A member with no row is Standard. Only
`user` subjects have a calendar; reserved subject types stay Standard and are
out of scope.

**Private seals the four content columns.** `CalendarEntry` declares
`$sealed_fields = [cal_title, cal_location, cal_link, cal_notes]` plus the four
convention columns (`cal_content_sealed`, `cal_sealed_key`,
`cal_sealed_owner_user_id`, `cal_key_generation`). `cal_title` and
`cal_location` become `text`: a sealed value does not fit varchar(255).

Everything else stays plaintext, deliberately: start and end, zone, all-day,
busy/free, firmness, reminder lead, recurrence, parent and exception links,
`cal_source` and `cal_source_event_id`. Times describe the schedule, not its
content (the busy projection and the booking pages read them with no window);
the source id is an opaque dedup key (an ICS UID, or a message id for AI
proposals) and must stay queryable in plaintext for upsert to find the row.

**Per-row policy, decided at write time.** `shouldSeal($row)` is true when the
subject is a user whose calendar is Private and who holds a vault — the same
rule chat uses. `sealedOwnerUserIdFor($row)` returns `cal_subject_id` for a
user subject and null otherwise (a row with no resolvable owner is stored in
the clear, per the vault contract). Creating a sealed entry needs only the
owner's public key, so the `.ics` importer, the quick-create popover, the API
save and an approved AI proposal all work with the window closed. Editing a
sealed entry's content, or splitting a recurring series (the replacement row
and the split-off parent carry the parent's title, location, link and notes),
needs the owner's window; deleting, adding an exception and changing only
times or flags do not.

### Reading while locked

A closed window is a state, never an error. One mechanism serves every path:

- `CalendarItem` gains a boolean `locked`. `NativeCalendarItemSource` catches
  `VaultLockedException` per entry and emits the item with `locked = true`,
  `title` set to the placeholder `Locked entry`, `location`, `link` and `url`
  stripped, and the edit coordinates kept so a tap can offer the unlock. It
  catches at every visibility, so the busy projection (which strips the title
  anyway) never throws.
- **The web grid** renders a locked item as a lock chip. Its popover shows the
  time and an **Unlock** button that runs `JoineryVaultLock.unlock()` and
  refetches the feed. The page listens for the two lock-chip events so an
  unlock made anywhere on the page redraws the grid. `calendar_entry` for a
  sealed entry while locked answers `locked: true`; the editor runs the unlock
  and retries instead of opening empty fields.
- **The native app** gets the same `locked` flag on feed items and the same
  `locked: true` answer from `calendar_entry`, renders a locked agenda row and
  runs the native unlock (`specs/native_vault_unlock.md`) before refetching.
  The flag is the contract; the screens are the app's work.
- **Reminders** (cron, no window ever): `reminderVars()` reads
  `cal_content_sealed` and, for a sealed entry, builds the time-only vars
  without attempting a decrypt. The template already renders the generic
  email. **Summaries**: a locked item's line is `Private entry` with its time.
  The card states both costs.
- **AI**: the generic model reader already shows a locked placeholder; nothing
  calendar-specific.

### Changing the level

`CalendarLevel` (`includes/calendar/CalendarLevel.php`) implements
`ProtectionLevelScope` for one member's calendar: `label()` is "your
calendar", `levels()` is Standard/Private, `currentLevel()` reads the
preference row, `blockers()` refuses Private without a vault (same message
chat uses), `flip()` writes `cpr_protection_level`, and the converge half walks
the member's entries whose `cal_content_sealed` disagrees with the level, 50
rows per pass. Raising seals with the public key; lowering decrypts and so
needs the window (`ProtectionLevelChange` rule 4).

Two API actions mirror chat's: `calendar_level_change` (runs `gate()` then
`change()`; answers `stepup` as `requires_stepup`) and `calendar_level_batch`
(one `convergeBatch()` pass, called until `remaining` is 0). The page wraps the
change in `JoineryPasskeys.withStepUp`. The second driver is vault deferred
work, id `calendar_level`, so a change the page did not finish finishes in the
owner's next window.

**Core consumer.** `vault_consumers.json` gains `calendar_sealed`
(bootstrap `includes/calendar/CalendarSealed.php`, `reseals: true`,
`order` between Drive's 60 and the direct spool's 70). The bootstrap registers
`VaultUnlock::onReseal(VaultUnlock::modelReseal([CalendarEntry::class]))` and
the deferred-work drain. No `onWipe`: the calendar keeps no in-window
plaintext cache.

### Where the member sees it

- **`/profile/calendar_settings`** gets the picker at the top:
  `ProtectionLevelPicker::render()` with `levels` Standard/Private and a new
  copy flavour `SERVICE_CALENDAR`. Three lines per card, in the catalog's
  order (what it does / when to pick it / what it costs):
  - Standard: "Your appointments are stored as written." / "Best for a
    calendar with nothing on it you would mind someone reading." / "Nothing to
    set up."
  - Private: "Titles, places, links and notes are encrypted at rest; times stay
    readable so your availability still works." / "Best for a calendar that
    says where you go and who you see." / "Reminder emails stop naming the
    entry, and you unlock to see what is on your calendar."
  - A note under both cards: "Events and bookings shown on your calendar keep
    the protection of the place they were made."
- **The calendar page header** shows the level chip
  (`ProtectionLevelPicker::summary()`) linking to settings.
- **The setup wizard's Calendar step** offers the same two cards above the
  `.ics` import when the viewer holds a vault, so imported entries land sealed.
  It is the first choice on a new scope: no step-up, no backlog. Without a
  vault the step says one line about where to set one up and offers nothing.

### What does not change

Projected items (events, bookings) keep their own owners and protection; the
public booking page keeps reading busy time; deletion and the subject purge are
untouched; backups carry ciphertext like every other sealed model; no window
cap, no add-on, no Fortress card.

### Tests

- `tests/functional/calendar/level_change_test.php` (db tier), after
  `plugins/joinery_ai/tests/chat_level_change_test.php`, one suite covering
  all of the following (the draft listed them as separate files; one suite
  with sections was smaller and shares the fixtures): Private needs a vault;
  raising seals every content column with the window closed; lowering needs
  the window and opens everything back; the level flips first and a long
  calendar converges in bounded batches and the deferred drain finishes it; a
  change asks for a recent second factor when the owner has one; editing
  sealed content with the window closed is refused as locked; delete is not;
  a row still sealed on a lowered calendar is opened before an edit; a sealed
  entry's reminder vars carry only the time and a summary lists it as
  `Private entry`; a locked entry comes back from the feed as a locked item
  with its times, no title, no url, edit coordinates present; the busy
  projection over a locked calendar does not throw; the picker's calendar
  copy and mail's Fortress contacts note.
- `tests/vault/protection_level_change_test.php` already pins the shared rules.

### Docs (current state only)

`docs/calendar.md` gains a "Protection level" section; the Email reminders
section states the sealed-entry rule at `reminderVars()`. `docs/protection_levels.md`
adds `CalendarLevel` to the consumers table. `docs/mobile_apps.md` names the
`locked` flag under JoineryCalendarKit. The matrix row in
`specs/protection_levels_platform.md` moves to "built" when this lands.

---

## Part 2 — Contacts

### Today

- `imc_mailbox_contacts` (`MailboxContact`, `MailboxContacts`) is a
  per-mailbox autocomplete cache: `imc_address` and `imc_display_name` sealed
  per row to the **adding user's** vault; dedup by a blind index
  (`imc_address_hash`, keyed for a vault holder, plain SHA-256 otherwise).
- **The level already follows the mailbox.** `MailboxContacts::sealingVault()`
  seals a row only when the user holds a vault **and** the mailbox's effective
  level seals content (`InboundEmailAlias::seals_content()`: Private or
  Fortress). A vault holder on a Standard mailbox stores plaintext contacts,
  by design.
- Key rotation re-seals contact rows and the index key (mailbox bootstrap).
- Readers: compose autocomplete and the contact panel (`listForMailbox()`,
  answers `locked: true` when the window is closed), the Messenger people
  picker (`listForUser()`, a locked row is silently absent), and the Joinery
  Direct contact gate (`DirectContactGate`, declines when it cannot read).

### What is missing

**B1 — A mailbox's level change leaves its contacts behind.** The seal and
unseal convergences (`MailboxSealConvergence`, `MailboxUnsealConvergence` in
`plugins/mailbox/includes/MailboxProtectionLevel.php`) walk messages only.
Raising a mailbox to Private leaves every existing contact row in plaintext at
rest, against the promise just made. Lowering to Standard leaves the rows
sealed, so `listForMailbox()` reports the contacts **locked on a Standard
mailbox**, and a hand-add that finds the sealed row has no key to update it
with. Both directions are the same defect: the rows do not converge.

**B2 — The Fortress mail card is silent about contacts.** Under a Fortress
mailbox the contact list is still server custody: sealed to the adding user's
`user` vault, opened in their window, readable by the server then. The card
says "Full end-to-end encryption"; the contact list is not.

### The rule

**Contacts have no level of their own and no picker.** A contact row is Private
when the mailbox it belongs to seals content, Standard when it does not, and a
change to the mailbox converges its contacts in the same ceremony that
converges its mail. This is what the code already means; the work is to make
it true across a change and to say it on the card.

### Build

1. **Converge contacts with the mailbox (B1).** A `MailboxContactConvergence`
   (`ProtectionLevelConvergence`) beside the message convergences: pending rows
   are the contact rows whose `imc_content_sealed` disagrees with their
   mailbox's effective level. **Every conversion runs in the adding user's
   window, in both directions** (corrected at build, 2026-10-01: the draft said
   sealing could use the public key alone and the blind index needed no
   rewrite; it does. `MailboxContacts::indexKey()` hands out the keyed index
   only when the mailbox seals, so `imc_address_hash` is a keyed HMAC on a
   sealing mailbox and a plain SHA-256 otherwise — the digest changes with the
   posture and must be rewritten with the content: the keyed form needs the
   user's index key, the plain form the decrypted address, and a sealed row
   beside a plain digest would hand an attacker with the database a dictionary
   attack on the address the seal hides). Rows added by another grantee wait
   for that grantee's window (`ProtectionLevelChange` rule 4, exactly as mail
   does) and drain through a new mailbox deferred-work consumer,
   `mailbox_contact_level`. A row whose new digest collides with one the user
   added after the flip is merged into it. The raise and lowering receipts
   name the contacts still to converge as their own fact row (not in the
   message count, whose live loop would otherwise read other members' rows as
   stuck). `MailboxContacts`'s request-scoped posture cache is cleared by the
   flip.
2. **Say it on the card (B2).** The mail flavour's Fortress card gets a note
   line, from the picker so the domain editor and the mailbox editor both show
   it: "Your contact list is encrypted on the server and opens while you're
   signed in — it is not end to end." The Private card needs no note: contacts
   are Private there in the same sense as the mail.
3. **Tests**, in `plugins/mailbox/tests/`: raising a mailbox seals its
   plaintext contacts; lowering opens them in the adder's window and leaves
   another grantee's rows counted until their window; after a lowering the
   list is readable with no window; the picker test checks the Fortress note.
4. **Docs.** `plugins/mailbox/docs/overview.md` § Contacts states the rule and
   the converge; the levels table gains a Contacts row.

---

## Resolved decisions

**D1 — RESOLVED (owner, 2026-10-01): contacts under a Fortress mailbox keep
server custody, and the card says so.** The list stays readable in the adder's
window, so compose autocomplete, the contact panel, the Messenger picker and the
Direct contact gate keep working; the Fortress card states that the contact
list is not end to end. The cost, accepted: a hacked live server can read the
contact list of a Fortress mailbox while its owner is unlocked. The
alternative, contacts in the `mail` client-custody vault, would turn
autocomplete into a browser-side list, leave the contact panel unable to answer
from the server, and stop the Direct contact gate (which reads the recipient's
contacts server-side to authorize inbound direct delivery) for Fortress
mailboxes. If ever wanted, that is a client-custody contacts spec of its own.

**Q1 — RESOLVED (owner, 2026-10-01): cloud models over Private calendar
content follow Drive.** The calendar carries no consent setting of its own.
Today the only per-source cloud consent is mail's (`ied_ai_processing_consent`,
local / trusted / cloud, enforced on pipeline recipes by `RecipeVaultScope`);
Drive Private has none, and an in-window chat on a cloud model can read a
Private file through the generic model tool with only the composer's passive
sensitivity banner and the chat's optional "Local models only" add-on in the
way. The owner's rule for closing that, which applies to the calendar the day it
applies to Drive: **never an absolute block on the AI account a member chooses,
but it must be very clear, and impossible by mistake, to send Private content to
a cloud that is not private.** That is a platform item, not a calendar one; it
is recorded as work item 8 in `specs/protection_levels_platform.md`, and this
spec builds nothing for it.

## Build order

Calendar is one self-contained piece (model, consumer, scope, actions, picker,
feed flag, email engine, wizard step, tests, docs). Contacts B1 and B2 are a
second, smaller piece inside the mailbox plugin. Either can land first.
