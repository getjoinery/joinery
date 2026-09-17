# Calendar entry details: location, link, notes

**Status:** Implemented 2026-09-17. Columns on dev, migration 187 applied, the four suites pass (native_entry 25, calendar_emails 35, schedule_job_proposal 48, ics_import 38). Needs a release.
**Builds on:** `implemented/joinery_ai_calendar_ai_surface.md` (the owner-fixed
AI write door), `implemented/calendar_ics_import.md`,
`implemented/calendar_email_reminders.md`.
**Touches:** core calendar (`CalendarEntry`, `CalendarItem`, the native item
source, the calendar page and its API actions, `IcsImporter`,
`CalendarEmailEngine` + the reminder template), `joinery_ai`
(`EmailScheduleJob`, `CreateCalendarEntryTool`, `CalendarEntryImporter`),
`mailbox` (`EmailAttachmentDigest`, `EmailPipelineJobBase`).

## Why

A personal calendar entry today is a title and a time. Every major calendar
(Google, Outlook, Apple, Fastmail) puts three more things on its compact
create dialog, and the iCalendar standard carries all three: **where** it is,
**the link** to join or open, and **notes**. Without them the entry the AI
pulls out of an email loses exactly the parts the owner will need at the
moment the reminder fires: the join link, the confirmation number, the room.

Deferred on purpose (owner, 2026-09-17): per-event color, a people/"with"
field, attendees, attachments. Attendees are a subsystem (invitations, RSVP,
outbound mail), not a column; "Lunch with Sam" lives in the title.

## The three fields

| Column | Type | Rule |
|---|---|---|
| `cal_location` | `varchar(255)`, nullable | Free text as the owner or the email states it. |
| `cal_link` | `text`, nullable | Exactly one URL. `http` or `https` only, at most 2048 characters. Anything else is refused, never silently kept. |
| `cal_notes` | `text`, nullable | Plain text, at most 10 000 characters. Rendered escaped with line breaks kept; never HTML. |

Empty string and NULL are the same thing: no value. All three are optional
everywhere.

**One setter, every write path.** `CalendarEntry::set_detail_fields($location,
$link, $notes)` trims, caps, and validates; `CalendarEntry::normalize_link()`
is the link rule on its own for callers that must validate before they save
(the web form reports the error; the ICS importer drops the value with a
warning; the AI job drops the value). Nothing writes the three columns except
through the setter.

## Where they show up

- **The full entry form** (`/profile/calendar`, "More options"): Location and
  Link as text inputs under the time fields, Notes as a textarea. A bad link
  is a form error, the entry is not saved.
- **The quick-entry popover**: Location only, matching the compact dialogs
  the owner already knows. Link and notes are under "More options". A
  popover save touches only the fields it sends, so an entry's link and
  notes survive a quick edit.
- **The feed** (`calendar_feed`, `CalendarItem`): `location` and `link` ride
  with every native item at `details` visibility and are stripped at `busy`
  alongside the title. Notes stay out of the feed (it is a range of days,
  not one entry); the editor payload (`calendar_entry`) carries all three.
  The grid chip's tooltip adds the location.
- **The reminder email**: a Where line, a link the recipient can click, and
  the notes. `CalendarEmailEngine::reminderVars()` stays the one chokepoint
  deciding what an email may say about an entry; the three values are
  escaped there. The summary email appends the location to each line.
- **ICS import**: `LOCATION` → `cal_location`, `DESCRIPTION` → `cal_notes`,
  `URL` → `cal_link` (a `URL` that is not http(s) is dropped and counted as
  a warning).
- **The API**: `calendar_entry_save` accepts `location`, `link`, `notes`, each
  applied only when present, the same rule `reminder_minutes` follows.
  `calendar_entry` returns them. Native apps need no change to keep working.
- **Recurring edits**: a "this occurrence" replacement row and a "this and
  future" split-off parent copy the parent's three values, then apply
  whatever the save sent, exactly as the reminder override is carried.

## The AI recipe

`EmailScheduleJob` and `CreateCalendarEntryTool` both learn the three fields.

- **Verdict.** `location`, `link`, `notes` join the verdict schema, all
  optional. The prompt asks for the location as the email states it; the
  link that gets the owner into the event (join, ticket, confirmation); and
  notes as a few lines of facts from the email (confirmation number, dial-in,
  what to bring), never the whole message.
- **The link must be one the email actually contains.** At
  `recordVerdict()` the link is kept only when it is a valid http(s) URL
  **and** appears verbatim, as a whole URL, in the digest the model was
  shown (the body's URL list or the ICS EVENT block); a prefix of a listed
  URL is not a listed URL. Otherwise it is dropped and the entry is
  still proposed. This is the injection gate: a stranger's email may not
  plant a URL our own reminder email then renders as a button the owner
  clicks. The same digest is rebuilt from the message for the check, so the
  set of allowed links is exactly the set the model could read.
- **ICS invites.** The ICS EVENT block in the attachment digest gains
  `url:` and a capped `description:` so the prompt's "take the invite's
  fields verbatim" rule covers all three.
- **The card.** The owner's approval card gains a Where line, the link
  rendered completely (`ProposedActionFacts::verbatim`, so nothing can hide
  in a truncated tail), and a bounded Notes line. The link's host is what
  the owner checks before approving.
- **Reads.** `CalendarEntry` is already `$ai_readable`; the three columns
  are readable the moment they exist, so "what's the link for my 2pm?" works
  through `query_model` with no further change.

## Not built

- Color, a people field, attendees, attachments (deferred above).
- Rich text in notes. Plain text is what the AI writes and what every
  consumer can render safely.
- Location as anything but text (no map, no geocoding).

## Landing

1. `update_database` on dev adds the three columns (the form and the AI
   importer write them, so nothing on the calendar page works until it runs).
2. Migration 187 appends the Where / link / notes lines to the shipped
   `calendar_reminder` template where the body is still the factory one
   (compared with line endings normalized: the admin editor re-saves as
   CRLF). An admin-edited template is left alone; the new vars are
   available to it.
3. Tests: `native_entry`, `ics_import`, `calendar_emails`,
   `schedule_job_proposal`.
