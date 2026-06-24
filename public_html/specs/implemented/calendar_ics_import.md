# Calendar .ics Import (manual upload)

**Status:** Active — awaiting implementation
**Version:** 1.0

## Goal

Let a logged-in user populate their personal calendar by uploading an iCalendar
(`.ics`) file exported from another calendar (Google, Apple, Outlook/Microsoft
365, Fastmail, etc.). Each `VEVENT` in the file becomes a native calendar entry
(`cal_entries`) owned by that user's `CalendarSubject`.

This is **one-directional, one-time, manual file upload only.** Out of scope for
this spec (and deliberately so): URL/`webcal` feed subscription, periodic
re-fetch, live API sync (Google Calendar API, Microsoft Graph, CalDAV), and any
write-back to the source calendar. The schema fields that exist for future sync
(`cal_uid`, `cal_source`, `cal_source_event_id`, `cal_rrule_raw`) are populated
on import so a later sync feature has what it needs, but no sync is built here.

## Why this is cheap to add

The calendar was built anticipating this. `cal_entries` already carries every
field an import needs:

- `cal_uid` — the source event's `UID`, the stable identity across re-imports.
- `cal_source` — set to `ical_import` to tag provenance.
- `cal_source_event_id` — the source `UID` again, for future round-trip sync.
- `cal_rrule_raw` — the original `RRULE` string, preserved verbatim.

And `IcsHelper` (`includes/IcsHelper.php`) is a working RFC 5545 **writer**. The
import is its mirror image: an RFC 5545 **reader**. Escaping, line folding, and
UTC datetime formatting all have a reference implementation there to invert.

## Recurrence strategy (decided)

The native recurrence model is a deliberate **subset** of what `RRULE` can
express. The chosen approach is **map what fits, preserve the rest raw**:

1. If a `VEVENT`'s recurrence is expressible in the native model, translate it
   into the `cal_recurrence_*` columns. It then displays, expands, and edits
   exactly like a natively-authored recurring entry.
2. If it is **not** expressible (multiple ordinal `BYDAY`, `BYMONTH`,
   `BYSETPOS`, `BYWEEKNO`, second-level granularity, etc.), import the event as
   a **single entry at its `DTSTART`** and store the original rule in
   `cal_rrule_raw`. The raw rule is retained for fidelity and future use, but the
   native engine does **not** expand it — so a non-expressible series shows once,
   not repeatedly.

The honest consequence of (2): the calendar has no generic `RRULE` expansion
engine (it only expands the native `cal_recurrence_*` columns). "Preserve raw"
therefore means *kept but inert*, not *kept and recurring*. **This must be
surfaced** — see Import summary. Silent truncation is not acceptable.

## Architecture

One new class, `IcsImporter` (`includes/calendar/IcsImporter.php`) — the read-side
companion to `IcsHelper`. It is internally three stages (parse → translate
recurrence → map/persist) exposed as separate public static methods so each is
testable on its own and so a future sync feature can reuse the parser, but they
live in one file because they are one cohesive operation at this scale.

### `IcsImporter::parse(string $ics_text): array`

Pure format reader — no DB, no domain knowledge.

- Returns `['calendar' => [...envelope props...], 'events' => [ ...VEVENT structs... ]]`.
- Each `VEVENT` struct is an assoc array of decoded properties, each carrying its
  value plus parameters, e.g.
  `['DTSTART' => ['value' => '20260601T090000', 'params' => ['TZID' => 'America/New_York']]]`.
- Responsibilities (each the inverse of an `IcsHelper` step):
  - **Unfold** continuation lines (CRLF + single space/tab → joined). Inverse of
    `IcsHelper::foldLines()`.
  - **Split** `NAME;PARAM=val;PARAM=val:VALUE` into name, params map, value.
  - **Unescape** text values (`\n` → newline, `\,` `\;` `\\` → literals).
    Inverse of `IcsHelper::escapeText()`.
  - Tolerate CRLF and bare-LF line endings; tolerate a leading BOM.
  - Extract `VEVENT` blocks only (ignore `VTODO`, `VJOURNAL`, `VFREEBUSY`,
    `VALARM`). `VTIMEZONE` blocks are skipped — timezone resolution uses the
    `TZID` parameter against PHP's tz database (see Timezones).
- Does **not** interpret recurrence or compute UTC — that is the mapping stage's job.

### `IcsImporter::translateRecurrence(array $rrule, string $start_local): ?array`

Pure recurrence translator — the single source of truth for "is this rule
expressible?"

- Given a parsed `RRULE` (assoc of `FREQ`, `INTERVAL`, `BYDAY`, `BYMONTHDAY`,
  `UNTIL`, `COUNT`, …) and the event's local start, return the native recurrence
  field set (`['type','interval','days_of_week','week_of_month','end_date']`) **or
  `null`** when the rule is not natively expressible.
- `import()` calls it; a `null` return triggers the single-entry + raw-RRULE
  fallback.

### `IcsImporter::import(array $parsed, CalendarSubject $subject, string $tz): array`

Domain mapper. Persists `CalendarEntry` rows from parsed events and returns the
summary structure (see Import summary). Best-effort **per event**: one bad
`VEVENT` is recorded as failed and skipped; it does not abort the others. Per-event
mapping is described below.

## Field mapping: `VEVENT` → `cal_entries`

| `VEVENT` property | `cal_entries` field(s) | Notes |
|---|---|---|
| `SUMMARY` | `cal_title` | Truncate to 255. Untitled → `(no title)`. |
| `DTSTART` | `cal_start_utc`, `cal_start_local`, `cal_timezone` | See Timezones. |
| `DTEND` / `DURATION` | `cal_end_utc`, `cal_end_local` | If both absent: all-day → start + 1 day; timed → end = start. |
| `DTSTART;VALUE=DATE` | `cal_all_day = true` | Date-only value = all-day. |
| `UID` | `cal_uid`, `cal_source_event_id` | Identity for dedup / re-import. |
| `RRULE` | `cal_recurrence_*` (if expressible) + `cal_rrule_raw` (always) | See Recurrence strategy. |
| `EXDATE` | `cal_entry_exceptions` rows | Only when the event mapped to a native recurring parent; one row per excluded date. |
| `RECURRENCE-ID` | replacement entry (`cal_parent_entry_id` + `cal_parent_entry_date`) + exception | Modified single occurrence — see Recurrence overrides. |
| `TRANSP` | `cal_blocks_availability` | `OPAQUE` (or absent) → `true` (busy); `TRANSPARENT` → `false` (free). |
| — | `cal_source = 'ical_import'` | Provenance tag. |
| — | `cal_type = 'personal'` | Imported entries are owned, editable native entries. |
| — | `cal_visibility = 'details'` | Owner's own calendar. `CLASS` is not mapped in v1. |
| — | `cal_tzdata_version = '2026a'` | Match the value `calendar_logic` stamps. |

Description/location are **not** mapped: `cal_entries` has no description or
location column (the native entry model is title + time + busy/recurrence). They
are dropped on import; note this in the doc, not in the per-import summary.

### Timezones

`DTSTART` / `DTEND` appear in one of four forms; resolve each to both a UTC
instant and a local wall-clock string (the native model stores both, and uses
`cal_start_local` + `cal_timezone` as the DST-safe anchor for recurrence):

1. **UTC** (`...T090000Z`): `cal_start_utc` is direct. `cal_timezone` = importing
   user's tz; derive `cal_start_local` via `convert_time`.
2. **TZID** (`DTSTART;TZID=America/New_York:...`): value is local wall-clock in
   that zone. `cal_timezone` = the `TZID`; `cal_start_local` is the value;
   `cal_start_utc` via `convert_time(local, TZID, 'UTC')`.
3. **Date-only** (`VALUE=DATE`): all-day. `cal_start_local` = `date 00:00:00`,
   end = next day `00:00:00`, in the user's tz (mirrors `calendar_logic`'s all-day
   handling). `cal_timezone` = user tz.
4. **Floating** (no `Z`, no `TZID`): interpret in the importing user's tz.

If a `TZID` is not a valid PHP timezone (e.g. Outlook's Windows names like
`"Pacific Standard Time"`), fall back to the user's session tz and record a
per-event warning in the summary. A Windows→IANA lookup table is **out of scope
for v1** (noted as a future enhancement) — fall back and report instead.

Use `LibraryFunctions::convert_time()` for all conversions. `new DateTime()` is
permitted only inside the parser for raw datetime decoding (consistent with
`IcsHelper`), never for display.

### Recurrence overrides (`RECURRENCE-ID`)

A `VEVENT` carrying `RECURRENCE-ID` is a modified single occurrence of a series
identified by its shared `UID`. Map onto the native exception+replacement pattern
(the same shape `_calendar_save_recurring_scope()` produces for "this occurrence
only"):

1. Match the parent by `UID` among events imported from this same file.
2. Add a `cal_entry_exceptions` row for the `RECURRENCE-ID` date on the parent.
3. Create a standalone replacement entry with `cal_parent_entry_id` /
   `cal_parent_entry_date` set and the override's own times/title.

If no matching parent is found in the file (orphan override), import it as a
plain standalone entry and record a per-event note.

## Re-import / duplicate handling

Match candidates within the **same subject** by
`cal_source = 'ical_import' AND cal_source_event_id = UID`.

**Behavior: skip duplicates.** If a non-deleted entry with that UID already
exists for the subject, skip it and count it as "already imported." Do not update
it (avoids silently overwriting edits the user made after a prior import) and do
not create a duplicate. New UIDs are inserted.

Update-in-place on re-import (true re-sync semantics) is intentionally **not** in
this spec — it begins blurring into sync and can clobber post-import edits. It is
a candidate follow-up, tracked alongside the other sync-direction work that is out
of scope here.

## UI / flow

Per platform rules, no hand-rolled form — use **FormWriter**, and put the control
on the existing personal calendar surface rather than a new page.

- On `/profile/calendar` (`views/profile/calendar.php`), add an **Import** control:
  a FormWriter form with a single file input (`accept=".ics,text/calendar"`) and
  an "Import .ics" submit. Keep it unobtrusive (e.g. a small control near the grid
  header) — no explainer prose on the page (docs live in `/docs`).
- Submitting POSTs to an `import_entries` branch added to the existing
  `calendar_logic()` in `logic/calendar_logic.php` — alongside the `save_entry`
  and `delete_entry` branches, reusing the permission check, `CalendarSubject`,
  and tz it already resolves at the top. The branch:
  1. Read the upload from `$_FILES`. The file is parsed and **discarded** — it is
     not persisted to disk (nothing to store; we only need its contents once).
  2. Validate (see Validation & limits). On failure, redirect back with an error.
  3. `IcsImporter::parse()` → `IcsImporter::import()`.
  4. Redirect to `/profile/calendar` with a summary (flash/query param).
- The calendar page renders a summary banner from the result.

No preview/confirm step in v1 (direct import + summary). A pre-import preview is
noted as a future enhancement.

## Import summary

`IcsImporter::import()` returns, and the page surfaces:

- `created` — count of entries inserted.
- `skipped_duplicate` — already-imported UIDs skipped.
- `imported_as_single` — events with non-expressible recurrence imported as a
  single occurrence (the raw rule was kept but does not recur). **Must be shown**
  — this is the visible edge of the recurrence strategy.
- `warnings` — e.g. unresolved `TZID` fell back to your timezone; orphan override.
- `failed` — events that could not be parsed/saved, with a short reason each.
- `capped` — if the per-file event cap was hit, how many were not processed.

Banner copy reads plainly, e.g.: "Imported 42 entries. 3 events with advanced
repeat rules were added as single events. 1 was already imported. 1 could not be
read."

## Validation & limits

- **Extension/MIME:** accept `.ics` / `text/calendar`. Reject others with a clear
  message.
- **Size cap:** reject files over a sane limit (e.g. 5 MB) before parsing.
- **Envelope check:** content must contain a `BEGIN:VCALENDAR`. Otherwise: "That
  doesn't look like a calendar file."
- **Event cap:** process at most N events per file (e.g. 5000). If exceeded,
  process the first N and report the remainder via `capped` — never silently
  truncate.
- **Encoding:** treat input as UTF-8; strip a leading BOM.
- Standard upload safety: never `eval`/`include` file contents; treat all values
  as data; all DB writes go through the `CalendarEntry` model (prepared
  statements).

## Testing

One standalone test script, `tests/calendar/ics_import_test.php` (runnable as
`php tests/calendar/ics_import_test.php`, matching the existing calendar tests),
covering all three stages with inline `.ics` fixture strings — no separate fixture
files:

- **`translateRecurrence()`** — the expressible-subset boundary:
  `FREQ=WEEKLY;BYDAY=MO,WE;INTERVAL=2` → weekly map; `FREQ=MONTHLY;BYDAY=2TU` →
  monthly week-of-month map; `FREQ=MONTHLY;BYDAY=1MO,3MO` → `null` (not
  expressible); `UNTIL` and `COUNT` → correct `end_date` (reuse
  `CalendarEntry::nth_occurrence_date()` for `COUNT`).
- **`parse()`** — inline fixtures in Google/Apple/Outlook export style; assert
  unfolding, param parsing, text unescaping, all-day vs timed, `TZID` handling.
- **Round-trip** — generate an event with `IcsHelper`, parse it back with
  `IcsImporter::parse()`, and assert the expressible fields survive. This pins
  reader and writer to the same format contract.
- Run `php -l` and `validate_php_file.php` on every new/modified PHP file.

## Documentation

Add an **"Importing .ics files"** section to `docs/calendar.md` (after "Recurring
native entries"). It must describe, as the current end state (no migration
narration):

- The upload surface on the personal calendar page.
- The `VEVENT` → `cal_entries` field mapping, including which properties are
  dropped (description, location, `CLASS`).
- The recurrence subset: which `RRULE` shapes map to native recurrence and that
  anything else is imported as a single event with the raw rule preserved.
- Re-import behavior (skip duplicates by `UID`).
- That import is one-directional manual upload, with no feed subscription or sync.

## Files

**New**
- `includes/calendar/IcsImporter.php` — `parse()` + `translateRecurrence()` + `import()` in one class
- `tests/calendar/ics_import_test.php` — parse + translate + round-trip, inline `.ics` fixtures

**Modified**
- `logic/calendar_logic.php` — add the `import_entries` POST branch
- `views/profile/calendar.php` — add the FormWriter import control + summary banner
- `docs/calendar.md` — add the "Importing .ics files" section
