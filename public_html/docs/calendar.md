# Personal Calendar

The personal calendar is a core, subject-owned timeline. Every time-bound thing in the platform — events, bookings, native entries, and later external feeds — projects onto one timeline per subject. Availability and booking are *derived from* the calendar, never the other way around.

## Subject identity

A calendar belongs to a **schedulable subject**, not directly to a user. `CalendarSubject` (`includes/calendar/CalendarSubject.php`) is a small value object — `{type, id}` — plus a resolver that turns it into the owner record (display name, timezone, avatar). It is the single place that knows subject types exist; everything else passes a `CalendarSubject` around without branching on type.

`user` is the only implemented type. `resource`, `team`, and `venue` are reserved (the schema and interfaces already key on `(subject_type, subject_id)`).

Owner identity is stored as `subject_type` + `subject_id` columns on the only two owner-bearing tables — `sch_schedules` and `cal_entries` — and resolved live. A polymorphic owner can't be a database foreign key to `usr_users`, so those two tables give up DB-level referential integrity on the owner column; owner cleanup on user deletion is handled by a declarative `$foreign_key_actions` cascade.

## Ownership model — system of record vs. projection

The calendar is a **system of record only for items that originate in the calendar itself.** Everything else is a **read-only projection** it links out to. There is no write-back to external systems and no two-way sync.

- **Native entries** (`type = personal`) — created directly on the calendar (a personal appointment, a block of busy time). Stored in `cal_entries`, owned by the calendar, fully editable. Exposed through the registry via `NativeCalendarItemSource`.
- **Projected items** (events, bookings, later external feeds) — owned by their originating system. The calendar renders them and deep-links (`url`) but never edits or moves them. Rescheduling a booking happens in the booking flow; editing an event happens in the events system.

Consequences: `CalendarItemSource` is a read-only contract (no write methods); there is no sync engine and no conflict resolution (every item has exactly one owner); projected items are not inline-editable.

## The calendar item

`CalendarItem` (`includes/calendar/CalendarItem.php`) is the unit on the timeline — a value object, not necessarily a stored row. Fields: `start_utc`/`end_utc` (UTC instants), `all_day`, `type` (`event`/`booking`/`external`/`personal`), `title` (owner-visible only), `url` (owner-visible only), `blocks_availability`, `visibility` (`details`/`busy`), `source`, `source_key` (stable id `{source}:{record-id}` for redraw/diff, ICS UID, click-to-edit), and — on native entries only — the edit coordinates `entry_id` (the `cal_entries` row; the parent for a recurring occurrence) and `occurrence_date` (set only on virtual occurrences), so a consumer opens the right editor without parsing the `url`. At `busy` visibility the projection boundary strips `title`, `url`, and both edit coordinates.

> **Note:** the stored native-entry model is `CalendarEntry` (`data/entries_class.php`, table `cal_entries`), which is distinct from the `CalendarItem` value object. `NativeCalendarItemSource` reads `CalendarEntry` rows and emits `CalendarItem` value objects.

### Visibility — enforced at the projection boundary

A personal calendar shows the owner "Dentist, 2pm"; a stranger loading the owner's public booking page must see only *busy*, never the title. Enforcement is in the registry, not in callers: the public availability path requests items at `busy` and the registry strips `title`/`url` before they leave the aggregation. Owner-facing requests get `details`.

## Item sources and the registry

`CalendarItemSource` (`includes/calendar/CalendarItemSource.php`) is the single contract every feature implements to put things on calendars:

```php
interface CalendarItemSource {
    public static function getKey(): string;
    public function getItems(CalendarSubject $subject, string $start_utc, string $end_utc, string $visibility): array;
}
```

`CalendarItemSourceRegistry` auto-discovers sources (EmailSender-style) from core `includes/calendar/item_sources/` and active plugins' `includes/calendar_item_sources/`. Shipped sources:

- **EventItemSource** (core) — events the subject leads plus their active registrations; recurring events expand via `Event::get_instances_for_range()`.
- **NativeCalendarItemSource** (core) — native `cal_entries` entries.
- **BookingItemSource** (bookings plugin) — confirmed bookings and active paid holds where the subject is host.

The registry being the only coupling point is why this lives in core: a new source appears on every calendar and gates every availability calculation with no change to the grid, the slot generator, or any other source.

## Busy projection

There is exactly one upstream contract — items. "Busy time" is a derived view: aggregate `getItems(...)` at `busy` visibility, keep those with `blocks_availability === true`, reduce to `{start, end}`, and merge overlaps (`CalendarItemSourceRegistry::getBusyBlocks()` / `mergeBlocks()`). This projection is the only thing the availability engine and `SlotGenerator` consume. One registration therefore yields both outcomes — the item shows on the calendar **and** blocks availability.

`getBusyBlocks()` takes an optional `?callable $include` policy, checked after the `blocks_availability` filter and before items are reduced to `{start, end}` (the only point with per-item metadata left to filter on — the merged block shape that comes out has none). Default `null` keeps every caller's existing behavior — every busy item counts. A consumer that wants to treat firmness (below) as a factor in availability passes a callback reading `$item->status`; nothing does today, so bookings' policy on tentative entries is undecided (see `plugins/bookings/docs/overview.md`).

### Firmness (`cal_status`) vs. busy/free (`cal_blocks_availability`)

These are two independent axes, not one. `cal_blocks_availability` says whether an item occupies time at all (the iCal transparency axis) — untouched by firmness. `cal_status` (`tentative` | `confirmed` | `cancelled`) says how sure the calendar is that the entry is real: every human-authored path (the calendar form, `.ics` import) writes `confirmed`; an AI-extracted entry (`specs/joinery_ai_calendar_ai_surface.md`, `CalendarEntryImporter`) writes `tentative` until the owner acts on it. An AI-extracted meeting is still busy — the calendar records the truth about occupied time; risk tolerance for an *unconfirmed* commitment lives with each consumer via the `getBusyBlocks()` seam above, not by silently marking it free. `CalendarItem::$status` (default `confirmed`) carries this through the projection: `CalendarEntry`'s own projections (`get_instances_for_range()`, `NativeCalendarItemSource`) populate it from `cal_status`; every other source leaves the default, since a projected event or booking is always real.

## Entry details: location, link, notes

A native entry carries three optional details beyond its title and time, the same three every major calendar puts on its create dialog:

| Column | Holds | Rule |
|---|---|---|
| `cal_location` | Where it is, free text | Trimmed, 255 characters. |
| `cal_link` | The one URL that gets the owner in: join link, tickets, confirmation | Absolute `http`/`https` only, 2048 characters; anything else is refused, never silently kept. |
| `cal_notes` | Plain-text facts: confirmation number, dial-in, what to bring | Trimmed, 10 000 characters; rendered escaped with line breaks kept, never as HTML. |

Empty and NULL mean the same thing. **Every write goes through `CalendarEntry::set_detail_fields($location, $link, $notes)`**, and `CalendarEntry::normalize_link()` is the link rule on its own for a caller that must validate before saving — the calendar form reports a bad link as a form error, the `.ics` importer drops it with a warning, the AI schedule job drops it.

Where they appear: the full entry form edits all three; the quick-create popover takes the location only, and its save touches only the fields it sends, so a quick entry never clobbers a stored link or notes; the view popover shows all three. `location` and `link` travel on `CalendarItem` (`toArray()` → the feed; the grid chip's tooltip adds the location) at `details` visibility and are stripped at `busy` with the title. Notes stay off the feed and come back on the `calendar_entry` editor payload. Recurring scope edits carry all three onto a replacement row or a split-off parent exactly as the reminder override is carried. The reminder email shows them (see below), and the AI surface reads and proposes them (`plugins/joinery_ai/docs/overview.md` § Calendar access).

## Native entries and the personal calendar page

`/profile/calendar` renders the `calendar_grid` component against the owner's aggregated item feed (`/api/v1/action/calendar_feed`, `details` visibility). Native entries are created, viewed and edited there:

- **Click a day** → the quick-create popover (title, date, all-day/times, location; "More options" opens the full form). A new entry starts all-day and **not** blocking availability. Typing a time at the front of the title (`1pm dentist`, `14:30 standup`) moves it into Start/End as you type, turns All day off, and leaves just the words as the title; the end defaults to an hour later.
- **Click a native chip** → a read-only view popover: title, when, location, link, and — fetched from `calendar_entry` for that one entry — the repeat rule and notes. Its header carries an edit (pencil) icon and a delete (trash) icon; nothing is edited by the click itself.
- **The full form is a modal.** The pencil (and "More options") reloads the page with the entry in the URL (`?edit_entry=ID`, `?d=DATE`, or `/profile/calendar/entry/{id}/occurrence/{date}`) and the form opens in a page-owned `<dialog>` over the grid. Cancel, a save and a delete all return to the grid on the entry's month (`?m=Y-m-d`). A save that comes back with an error reopens the dialog with what was typed.
- **Recurring scope is asked at the moment of the action, in a dialog on top of the form**: saving an occurrence edit asks *this occurrence / this and future / all*; deleting a recurring entry asks the same (from the form's "Delete…" or the popover's trash). The form dialog is deliberately not the kit's `JoineryModal` — that dialog is a singleton whose content is replaced on every open, and it is what these stacked questions use.

A "blocking" entry removes its time from booking availability via the busy projection.

**Time zones.** Every entry carries the zone it was written in (`cal_timezone`) beside its UTC instants; the full form's "Time zone" picker (hidden for all-day entries) defaults to the owner's profile zone and accepts any IANA zone, so a 9 AM call in Los Angeles is entered as 9 AM Los Angeles and appears on a New York calendar at noon. An entry is edited in its own zone. The grid renders every item in the **calendar's** zone (the profile zone the feed reply names), never the browser's, so the calendar reads the same from any device. The view popover adds a line — `9:00 AM – 10:00 AM in Los Angeles (PDT)` — when an entry's zone differs from the viewer's. The quick-create popover has no picker and uses the profile zone; the `calendar_entry_save` action's `timezone` parameter is the same path.

## API surface (native apps and page JS)

Four core actions expose the personal calendar over `/api/v1` (session
credential; ownership enforced server-side — a foreign or missing entry is
always "Entry not found", no existence oracle). They share the exact write
path with the web form: the `_calendar_set_fields` / `_calendar_set_recurrence`
/ scope-split helpers in `logic/calendar_logic.php`.

| Action | Purpose |
|---|---|
| `calendar_feed` | Aggregated items over a UTC range (`start`, `end`; defaults −7d…+45d) at `details` visibility: `{items: [CalendarItem::toArray()...], timezone}`. A native item on a Private calendar read with the window closed carries `locked: true` (see Protection level). |
| `calendar_entry` | One native entry shaped for an editor (`entry_id`): wall-clock `date`/`start_time`/`end_time` + `timezone`, flags, `location`/`link`/`notes`, `is_recurring_parent`, `recurrence_description`, and the stored `recurrence` fields. A sealed entry read with the window closed answers `locked: true` with the times and empty content. |
| `calendar_level_change` / `calendar_level_batch` | The calendar's protection level (see Protection level): change it (`{level}`), then converge the entries one bounded batch at a time. |
| `calendar_entry_save` | Create/update: `date`, `title`, `all_day`, `blocks`, `start_time`/`end_time` (`HH:MM[:SS]`), optional `timezone` (IANA; defaults to the profile zone), optional `location`/`link`/`notes` (each applied only when present; `''` clears; a `link` that is not http(s) is an error), optional `recurrence` object (`type`, `interval`, `days_of_week`, `week_of_month`, `ends: never\|date\|count` with `end_date`/`count`). With `entry_id` + `occurrence_date` it is a scope-aware recurring edit (`scope: this\|future\|all`, defaulting to the safe `this`). |
| `calendar_entry_delete` | Delete: standalone entries soft-delete; recurring parents take `scope` (`all` default; `this`/`future` require `occurrence_date`). |

The wall-clock → UTC conversion happens server-side in the declared
`timezone`, so clients never do timezone math; "ends after N occurrences" is
converted to a stored end date by the same `nth_occurrence_date()` engine as
the web form. `/api/v1/action/calendar_feed` and the `calendar_entry_save`/`calendar_entry_delete` actions
remain the web grid's endpoints.

The native iOS surface consuming these actions is **JoineryCalendarKit** —
see [Mobile Apps](mobile_apps.md#joinerycalendarkit-native-calendar-module).

## Recurring native entries

A native entry can repeat on a schedule ("every Tuesday 9–10am"). A recurring entry stays **one row** — the parent `cal_entries` record — and its occurrences are computed on the fly; there is no materialization step. `cal_recurrence_type IS NOT NULL` is the authoritative test for "this is a recurring parent." This mirrors the events expansion pattern (see [Recurring Events](recurring_events.md)).

**Pattern fields** (on `cal_entries`): `cal_recurrence_type` (`daily`/`weekly`/`monthly`/`yearly`), `cal_recurrence_interval` (every N), `cal_recurrence_days_of_week` (weekly: comma list `0=Sun…6=Sat`; monthly-by-weekday: a single weekday digit), `cal_recurrence_week_of_month` (monthly: `1`–`4`, or `-1` for last), and `cal_recurrence_end_date` (NULL = open-ended). The pattern anchors on the entry's wall-clock start (`cal_start_local`), so it follows the owner's local time.

**Expansion.** `CalendarEntry::get_instances_for_range($start_utc, $end_utc, $visibility)` returns `CalendarItem`s for a window: `compute_dates_in_range()` walks the matching dates (`date_matches_pattern()` is the per-date test), and each occurrence's wall-clock time is recombined with its date and converted to UTC per instance — so an entry stays at the same local time across DST transitions. A date the pattern can't land on — the 31st in a 30-day month, Feb 29 in a non-leap year — is **skipped, not clamped**. `NativeCalendarItemSource` expands every recurring parent alongside standalone entries (loading all parents' exceptions in a single query), so virtual occurrences flow through the busy projection like any other item: a blocking recurring entry gates availability on every occurrence with no extra wiring.

**Exceptions.** A single occurrence is skipped by a row in `cal_entry_exceptions` (`cex_cal_entry_id` + `cex_exception_date`, unique together); skipped dates are dropped during expansion. These rows are removed when the parent entry is permanently deleted (the registered `cal_entries → cal_entry_exceptions` cascade).

**Editing or deleting an occurrence** offers three scopes, reached via `/profile/calendar/entry/{parent_id}/occurrence/{date}`:

| Scope | Effect |
|---|---|
| This occurrence | Add an exception for the date; on edit, also create a standalone replacement entry (`cal_parent_entry_id` / `cal_parent_entry_date` link it to the parent). |
| This and future | Set the parent's `cal_recurrence_end_date` to the day before the occurrence; on edit, start a new recurring parent from that date, carrying forward exceptions on or after it. |
| All occurrences | Update the parent in place (delete = soft-delete the parent); existing exceptions are preserved. |

"Ends after N occurrences" is converted to an end date at save time by `CalendarEntry::nth_occurrence_date()` — it walks the pattern to the Nth match using the same engine as expansion, so the count→date conversion has a single source of truth. A count-based series is therefore stored as a `cal_recurrence_end_date`; reopening it for edit shows "Ends → on date" with that date (the behavior major calendars use).

**The authoring form is declarative.** The recurrence editor on `/profile/calendar` is a plain FormWriter form: the "Repeats" checkbox, the frequency dropdown, the monthly-pattern radios, and the "Ends" radios are real FormWriter inputs whose show/hide is driven entirely by [`visibility_rules`](formwriter.md#6-field-visibility--custom-scripts) — there is no hand-rolled toggle JavaScript and no hidden-field marshalling. The fields submit their own values; the logic reads them directly (`entry_repeats`, `rec_frequency`, `rec_interval`, `rec_days[]`, `rec_monthly_mode`, `rec_week`, `rec_dow`, `rec_ends`, `rec_end_date`, `rec_count`) and maps them onto the `cal_recurrence_*` columns. The all-day checkbox hides the time fields the same declarative way. The edit-scope and delete-scope choices remain small modal flows (they set a `scope` field), which are not field show/hide and so stay as JavaScript.

## Importing .ics files

A user can populate their calendar by uploading an iCalendar (`.ics`) file exported from another calendar (Google, Apple, Outlook/Microsoft 365, Fastmail, …). Each `VEVENT` in the file becomes a native `cal_entries` row owned by the uploader's `CalendarSubject`. Import is **one-directional and manual** — a one-time read of an uploaded file. There is no feed subscription, no periodic re-fetch, and no API/CalDAV sync.

**Where the control lives.** The permanent home is the Actions menu on `/profile/calendar` — the kit's `.jy-actions-dropdown` (`assets/css/joinery-styles.css`), the same affordance the security and conversation pages use. Until the subject holds an entry of its own, a first-run prompt above the grid offers the same action a second time; `calendar_logic()` sets `has_own_entries` from a `MultiCalendarEntry::count_all()` over the subject's undeleted rows, counted account-wide so paging to an empty month does not bring the prompt back. The prompt retires permanently on the first entry from any source — authored or imported — and needs no dismissal state. Both triggers carry `data-cal-import` and open one `JoineryModal`-hosted copy of the upload form.

`IcsImporter` (`includes/calendar/IcsImporter.php`) is the reader — the mirror of the `IcsHelper` writer. It has three pure-ish stages exposed as static methods: `parse()` (text → structured events), `translateRecurrence()` (an `RRULE` → native recurrence fields, or `null`), and `import()` (events → saved rows + a summary). The upload is handled by the `import_entries` branch in `calendar_logic()`; the file is parsed in memory and discarded (never stored on disk).

**Field mapping** (`VEVENT` → `cal_entries`):

| `VEVENT` | `cal_entries` |
|---|---|
| `SUMMARY` | `cal_title` (truncated to 255; empty → `(no title)`) |
| `DTSTART` / `DTEND` / `DURATION` | `cal_start_utc` + `cal_end_utc` + `cal_start_local` + `cal_end_local` + `cal_timezone` |
| `DTSTART;VALUE=DATE` | `cal_all_day = true` |
| `TRANSP` | `cal_blocks_availability` (`TRANSPARENT` → free; `OPAQUE`/absent → busy) |
| `UID` | `cal_uid` + `cal_source_event_id` |
| `RRULE` | `cal_recurrence_*` when expressible + `cal_rrule_raw` (always) |
| `EXDATE` | `cal_entry_exceptions` rows (when the event maps to a recurring parent) |
| `RECURRENCE-ID` | exception on the parent + a standalone replacement entry (`cal_parent_entry_id` / `cal_parent_entry_date`) |
| `LOCATION` | `cal_location` |
| `DESCRIPTION` | `cal_notes` (plain text) |
| `URL` | `cal_link` (an `URL` that is not http(s) is left out and counted as a warning) |

Imported entries are `cal_type = personal`, `cal_visibility = details`, and `cal_source = ical_import`. `CLASS` and `ATTENDEE` are dropped — the native entry has no visibility beyond the owner and no attendee model.

**Timezones.** A `DTSTART` carrying a `TZID` is stored with that zone as `cal_timezone` and the value as the local wall-clock; a UTC (`…Z`) value is stored as the UTC instant with the local derived in the uploader's timezone; a date-only value is an all-day entry; a floating value is interpreted in the uploader's timezone. A `TZID` that is not a recognized IANA zone (e.g. an Outlook Windows name) falls back to the uploader's timezone and is reported as a warning.

**Recurrence subset.** The native recurrence model is a subset of `RRULE`. Common rules map to the `cal_recurrence_*` columns and then display, expand, and edit like any native recurring entry: `FREQ` daily/weekly/monthly/yearly, `INTERVAL`, weekly `BYDAY` lists, a single monthly ordinal weekday (`BYDAY=2TU`, `-1FR` → `cal_recurrence_week_of_month`), monthly/yearly by the start date's day, and `UNTIL`/`COUNT` (the latter converted to an end date via `CalendarEntry::nth_occurrence_date()`). Anything outside that subset — multiple ordinal `BYDAY`, `BYSETPOS`, `BYWEEKNO`, `BYMONTH`, sub-day granularity — is **not** expanded: the event is imported as a single entry at its `DTSTART` with the original rule preserved verbatim in `cal_rrule_raw`. The import summary reports how many events were handled this way (there is no generic `RRULE` expansion engine, so a preserved raw rule is retained but inert).

**Re-import.** Duplicates are matched within the subject by `cal_source = ical_import` and `cal_source_event_id` (the `UID`). An event whose `UID` was already imported is skipped, never updated or duplicated — so re-uploading a file does not overwrite edits made after a prior import. New `UID`s are inserted.

**Summary.** `import()` returns counts surfaced as a banner on the calendar page: `read` (VEVENTs the parser assembled), created, already-imported (skipped), imported-as-single (advanced recurrence), warnings, failed (per-event, best-effort — one bad `VEVENT` never aborts the rest), and capped (events beyond the per-file limit, reported rather than silently dropped). Failures are also rolled up by reason into `failed_reasons` (reason → count), which is what the banner lists — one malformed pattern repeated across forty events reads as one line, not forty. A `VEVENT` still open when the file ends sets `truncated` on the parse result and counts as a failure, so a partial download cannot leave an invisible gap.

**Run record.** Every import writes an `EventLog` row (`evl_event = calendar_ics_import`, `evl_usr_user_id` = the subject when it is a user, `evl_was_success` false when anything failed, was capped, or is unaccounted for) whose note carries the full tally and the per-reason failure rollup. The same line goes to the error log, followed by one line per failed event naming its `UID` and reason, bounded at 100 events. The banner is a one-shot session flash; the log row is the durable record, queryable per user long after the page is gone. Losing the log row never fails an import that otherwise succeeded.

## Protection level

A member's personal calendar is **Standard** or **Private**, chosen with the platform's shared picker ([Protection Levels](protection_levels.md)) on `/profile/calendar_settings` and, when the member already holds a vault, on the setup wizard's Calendar step above the `.ics` import so imported entries land under the chosen level. The calendar page header shows the level as a chip linking to the settings. The level lives on the member's `cpr_calendar_preferences` row (`cpr_protection_level`; no row means Standard; `CalendarPreference::protection_level()`). There are no add-ons (the calendar has no door of its own to guard) and no Fortress card (client custody would black out the AI scheduling the calendar exists to feed). Only `user` subjects have a level; reserved subject types stay Standard.

**What Private seals.** `CalendarEntry` declares `$sealed_fields = [cal_title, cal_location, cal_link, cal_notes]` plus the four Sealed Vault convention columns (`cal_content_sealed`, `cal_sealed_key`, `cal_sealed_owner_user_id`, `cal_key_generation`; [Sealed Vault](sealed_vault.md)). `cal_title` and `cal_location` are `text` columns because a sealed value outgrows any varchar cap; the plaintext caps live in the setters. Everything else stays plaintext on purpose: start and end, zone, all-day, busy/free, firmness, reminder lead, recurrence, parent and exception links, `cal_source` and `cal_source_event_id`. Times describe the schedule, not its content (the busy projection and the booking pages read them with no window), and the source id is the opaque dedup key an upsert must find in plaintext.

**Per-row policy, decided at write time.** `CalendarEntry::shouldSeal()` is true when the subject is a member whose calendar is Private and who holds a vault (`CalendarLevel::sealsFor()`, memoized per request); `sealedOwnerUserIdFor()` is the user subject. Creating a sealed entry needs only the owner's public key, so the `.ics` importer, the quick-create popover, `calendar_entry_save` and an approved AI proposal all work with the window closed. Editing a sealed entry's content, or splitting a recurring series (the replacement row and the split-off parent carry the parent's title, location, link and notes), needs the owner's window; deleting, adding an exception and changing only times or flags do not. `calendar_entry_save` answers a closed window with an error carrying `locked: true`; the web form reports it as a form error. An entry still sealed on a calendar lowered to Standard is opened by `CalendarLevel::settleBeforeEdit()` (inside `_calendar_set_fields`) before the edit lands, so the new content is stored as plaintext.

**Reading while locked.** A closed window is a state, never an error. `CalendarEntry::content()` is the one place the four reads are wrapped: it returns the content, or `locked` true with the placeholder title `CalendarItem::LOCKED_TITLE` ("Locked entry") and nothing else. `NativeCalendarItemSource` (and `get_instances_for_range()` for a recurring parent, once per series) reads content only at `details` visibility, so the busy projection never opens a sealed row, and emits a locked item with `CalendarItem::$locked` true, the placeholder title, `location`, `link` and `url` stripped, and the edit coordinates kept so a tap can offer the unlock. `toArray()` carries `locked` on the feed; the grid renders a locked item as a lock chip (`.calgrid-chip.is-locked`), its popover shows the time and an **Unlock** button running `JoineryVaultLock.unlock()`, and the page redraws the grid on the two lock-chip events (`joinery:vault-unlocked` / `joinery:vault-locked`). `calendar_entry` for a sealed entry while locked answers `locked: true` with the times and no content; the full-form dialog offers the unlock in place of the form. The native app gets the same `locked` flag on feed items and the same answer from `calendar_entry` ([Mobile Apps](mobile_apps.md#joinerycalendarkit-native-calendar-module)). The AI model reader shows its generic locked placeholder.

**Reminders and summaries** run from cron, which never holds a window. `CalendarEmailEngine::reminderVars()` reads `cal_content_sealed` and, for a sealed entry, builds the time-only vars without attempting a decrypt, so the template renders its generic form; a sealed entry's summary line reads `Private entry` with its time (`CalendarEmailEngine::PRIVATE_LABEL`). The Private card states both costs.

**Changing the level.** `CalendarLevel` (`includes/calendar/CalendarLevel.php`) implements `ProtectionLevelScope` for one member's calendar: two rungs, `blockers()` refuses Private without a vault, `flip()` writes the preference row, and the converge half walks the member's entries whose `cal_content_sealed` disagrees with the level, 50 rows per pass (`CalendarLevel::convertEntry()`; a row that fails is stamped in `cal_level_attempt_time` and passed by for an hour). Raising seals with the public key; lowering decrypts and so needs the window. Two API actions mirror chat's: `calendar_level_change` (`{level}`; `gate()` then `change()`, `stepup` answered as an error with `requires_stepup`; a calendar with no entries is a first choice and asks no step-up) and `calendar_level_batch` (one `convergeBatch()` pass, called until `remaining` is 0). The settings page wraps the change in `JoineryPasskeys.withStepUp` and runs the one unlock ceremony when a lowering needs the window. The second driver is the vault's deferred work, id `calendar_level`, so a change the page did not finish finishes in the owner's next window; the calendar page and the settings page also resume one on load.

**Core consumer.** `vault_consumers.json` declares `calendar_sealed` (bootstrap `includes/calendar/CalendarSealed.php`, `reseals: true`, order 65): it registers `VaultUnlock::modelReseal([CalendarEntry::class])` for key rotation and the deferred-work drain. No `onWipe`: the calendar keeps no in-window plaintext cache.

Projected items (events, bookings) keep their own owners and protection; the public booking page keeps reading busy time; deletion and the subject purge are untouched; backups carry ciphertext like every other sealed model.

Tests: `tests/functional/calendar/level_change_test.php` (db tier).

## Email reminders and summaries

The calendar emails members two things, both opt-in and both off by default: a **reminder** shortly before a native entry starts, and a **daily or weekly summary** of everything on their calendar. The `CalendarEmails` core scheduled task (`tasks/CalendarEmails.php`, `every_run`, activated on install) drives both; all behavior lives in `CalendarEmailEngine` (`includes/calendar/CalendarEmailEngine.php`), which takes its clock as a constructor argument so tests drive it, and exposes a `$sender` callable seam so tests and dry runs send nothing real. The task implements dry run — the admin Scheduled Tasks page previews exactly what would go out right now.

**Preferences** live in `cpr_calendar_preferences` (`CalendarPreference`), one row per user, edited on `/profile/calendar_settings` (the Calendar section of member settings; the page saves through the `calendar_settings` API action). Absence of a row means everything off. Three fields: `cpr_summary_frequency` (`none` / `daily` / `weekly`), `cpr_summary_hour` (local hour 0–23, in `usr_timezone`), and `cpr_reminder_default_minutes` (0 = off; 60 / 30 / 15 / 5 minutes before start). `CalendarPreference::get_for($user_id)` returns the row or an unsaved defaults object, so callers never branch on existence.

**Per-entry override.** `cal_reminder_minutes` on `cal_entries`: `NULL` inherits the owner's default, `0` silences this entry, any other value is an explicit lead. The entry form renders it as a dropdown whose first option shows what "Use my default" currently means. On a recurring parent the value applies to every occurrence; scope-aware edits carry it onto replacement rows and split-off parents. The quick-entry popover omits the field, and the API save (`reminder_minutes` on `calendar_entry_save`) only touches the column when the field is present — so a quick edit never clobbers a stored choice.

**Reminder semantics.** Native entries only, `user` subjects only. An entry/occurrence is due when `start − lead ≤ now < start`: created-late-but-before-start sends immediately; at-or-after-start never sends (a missed window is dropped — a reminder for something already underway is noise). All-day entries carry no timed reminder (the summary covers them); cancelled and soft-deleted entries are silent; tentative entries send, marked as tentative. Recurring parents expand through `get_instances_for_range()` over the scan window, so each occurrence reminds independently, and exceptions are honored for free.

**Summary semantics.** Due once per period when the member's local clock passes `cpr_summary_hour` — daily covers the local day, weekly covers Monday + 6 days and goes out on Mondays. Content is the full `CalendarItemSourceRegistry` aggregation (native entries, events, bookings — whatever the calendar page shows), grouped per local day. An empty period claims its ledger row but sends no email.

**The ledger.** Every send is claimed first in `cme_calendar_emails` (`CalendarEmail`) under a unique `cme_dedup_key` — `reminder:{entry_id}:{occurrence_start_utc}` or `{kind}:{user_id}:{period_key}` — which makes at-most-once a database guarantee (`CalendarEmail::claim()` returns `NULL` on a duplicate). A rescheduled entry gets a new occurrence start, hence a new key, hence a fresh reminder for the new time — intended. Rows expire on `calendar_email_log_retention_days` (default 90) via the retention sweep. Run-level audit goes to `EventLog` as `evl_event = calendar_emails_run`, written only when a pass sent something.

**Templates and sending.** `calendar_reminder` and `calendar_summary` are inner (`emt_type = 2`) email templates, editable at `/admin/admin_email_templates`. The reminder body renders title/tentative/location/link/notes inside conditionals so a vars array carrying only the time vars produces a generic "You have a calendar entry coming up" email — `CalendarEmailEngine::reminderVars()` is the single chokepoint deciding what an email may say about an entry, and it is where a sealed entry (a Private calendar's) is reduced to its time vars (see Protection level). It escapes `location`, `link`, and `notes` itself (the template renderer substitutes raw; an AI-extracted entry's notes came from a stranger's email), and `notes` keeps its newlines for the template's `|nl2br` modifier. The summary appends ` @ location` to each line that has one. Sending is strictly ambient (`EmailSender::sendTemplate`, the platform sender): cron holds no vault unlock window, so the session-gated compose transport is structurally unavailable to this path.

## Deletion

The owner column is polymorphic (`subject_type` + `subject_id`), so it can't be a real FK and the generic delete-cascade can't express it — a blind delete by id would also hit other subject types sharing the number. Owner cleanup is therefore subject-aware: `CalendarSubject::purge()` permanently deletes a subject's schedules and native entries (the latter cascading to their `cal_entry_exceptions`), and the owner's deletion path calls it — `User::permanent_delete()` purges the user subject. Soft-deleting a user changes nothing here (the owner still exists); only a permanent delete purges. Native entries have no external side effects.
