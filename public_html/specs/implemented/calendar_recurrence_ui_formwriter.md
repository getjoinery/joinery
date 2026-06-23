# Recurrence Authoring UI — Convert to Declarative FormWriter

## Overview

The recurring-entry form on `/profile/calendar` lets a user say "repeat weekly on Mon/Wed," "every 2 months on the 3rd Tuesday," "ends after 10 times," and so on. It works, but it's built the way the platform tells us *not* to: raw `<select>`/`<input>` HTML wired together by ~200 lines of hand-written JavaScript that show/hide rows, relabel units, compute an end date, and copy every widget's state into hidden form fields at submit time. None of the actual inputs are FormWriter inputs — they're decorative widgets shadowed by hidden fields.

This spec replaces that with real FormWriter inputs whose show/hide is driven entirely by declarative `visibility_rules`, and moves the one genuinely server-shaped computation (turning "after N occurrences" into an end date) onto the server, where the recurrence engine already lives. The net effect: the hidden-field marshalling disappears, the duplicated JavaScript date-math disappears, and the form becomes a normal FormWriter form.

**Hard dependency:** this requires `visibility_rules` to support checkbox and radio-group triggers — see `specs/formwriter_visibility_checkbox_radio.md`. The "Repeats" checkbox and the monthly-mode / ends-mode radio groups are triggers, so this spec cannot land until that one does.

---

## Why This Is Worth Doing

- **It's the documented standard.** CLAUDE.md: "For show/hide field behavior driven by a select/radio value, pass `visibility_rules` to the input — do NOT hand-roll JS toggles." The spec this implements (`recurring_native_calendar_entries.md`) said the same. The current form is the exception.
- **It removes a correctness hazard.** The "after N occurrences → end date" walk is currently reimplemented in JavaScript (`matchesPattern`, `computeEndDateFromCount`) as a parallel copy of `CalendarEntry::date_matches_pattern()` / `compute_dates_in_range()`. Two implementations of the same recurrence math drift. Moving the conversion server-side deletes the JS copy and leaves one source of truth.
- **It deletes the hidden-field layer.** Today the visible widgets don't submit; a `submit` handler copies their state into hidden `rec_*` inputs. Real FormWriter inputs submit their own values, so that entire marshalling step (and its failure mode — if the JS doesn't run, nothing is saved) goes away.

---

## Current State (what's being replaced)

In `views/profile/calendar.php`, the recurrence section is:

- A checkbox `cal-rec-enabled` ("Repeats") that toggles a `.cal-rec-fields` block via a class.
- Raw `<select>` / `<input>` widgets: `rec_freq_ui`, `rec_interval_ui`, a Sun–Sat checkbox row, monthly-mode radios + `rec_week_ui` / `rec_monthly_dow_ui`, ends radios + `rec_end_date_ui` / `rec_count_ui`, plus a live-text unit label and description preview.
- Hidden fields `rec_type`, `rec_interval`, `rec_days`, `rec_week`, `rec_end_date` populated by `syncRecHiddenFields()` on submit.
- ~200 lines of JS: `syncRecHiddenFields`, `syncFreqUI`, `updateRecPreview`, `ordinal`, `computeEndDateFromCount`, `matchesPattern`, `fmtDate`, and their listeners.

`logic/calendar_logic.php` reads the hidden `rec_*` fields and stores them via `_calendar_set_recurrence()`. The storage convention is unchanged by this spec: `cal_recurrence_days_of_week` holds a comma list for weekly, or a single weekday digit for monthly-by-weekday; `cal_recurrence_week_of_month` holds the ordinal.

---

## Target Design

### Real FormWriter inputs

| Purpose | Field (FormWriter) | Notes |
|---|---|---|
| Repeats on/off | `entry_repeats` **checkbox** | trigger: show/hide the recurrence block |
| Frequency | `rec_frequency` **dropinput** (daily/weekly/monthly/yearly) | trigger: weekly vs monthly sub-rows |
| Interval | `rec_interval` **numberinput** | plain |
| Weekly days | `rec_days` **checkboxList** (type=checkbox, options 0–6) | submits an array → joined to the comma list; a *target*, never a trigger |
| Monthly mode | `rec_monthly_mode` **radioinput** (day / week) | trigger: show week+weekday selects |
| Monthly ordinal | `rec_week` **dropinput** (1st…4th, last) | target |
| Monthly weekday | `rec_dow` **dropinput** (Sun–Sat) | target |
| Ends | `rec_ends` **radioinput** (never / date / count) | trigger: show date or count |
| End date | `rec_end_date` **dateinput** | target |
| Occurrence count | `rec_count` **numberinput** | target |

### Visibility, fully declarative

Each trigger carries its own `visibility_rules`; the rest is structure. Because FormWriter renders each field as its own sibling container (not nested), group the recurrence fields under thin structural wrapper `<div id="…">`s so each trigger owns a clean, non-overlapping target — structural layout divs, not form controls, so this respects the "no hand-rolled form controls" rule.

- `entry_repeats` (checkbox): `checked → show ['rec_section']`, `unchecked → hide ['rec_section']`, where `#rec_section` wraps the whole block.
- `rec_frequency` (select): `weekly → show ['rec_weekly_group'], hide ['rec_monthly_group']`; `monthly → show ['rec_monthly_group'], hide ['rec_weekly_group']`; `daily`/`yearly → hide both`.
- `rec_monthly_mode` (radio): `week → show ['rec_week','rec_dow']`; `day → hide ['rec_week','rec_dow']`.
- `rec_ends` (radio): `date → show ['rec_end_date'], hide ['rec_count']`; `count → show ['rec_count'], hide ['rec_end_date']`; `never → hide both`.

Nesting works because the inner triggers only manage fields inside the (visible) `#rec_section`; when `entry_repeats` hides the wrapper, everything inside is hidden regardless of inner state.

### Server-side "after N occurrences"

The form submits `rec_ends` plus either `rec_end_date` or `rec_count`. The logic computes the stored end date:

- `never` → `cal_recurrence_end_date = null`
- `date` → the entered date
- `count` → walk the pattern server-side to the Nth occurrence

Add `CalendarEntry::nth_occurrence_date(string $anchor_date, int $count): ?string`: with the recurrence fields already set on the entry, walk forward from the anchor using the existing `date_matches_pattern()`, counting matches until the Nth (bounded by the same safety cap as `compute_dates_in_range()`), and return that date. The logic sets the recurrence fields, calls this, and stores the result. The JS `computeEndDateFromCount` / `matchesPattern` / `fmtDate` are deleted.

(Consequence, accepted and conventional: a count-based series, once saved, is stored as an end date, so editing it later shows "Ends → on date" with that date — the same behavior major calendars use.)

### What stays as JavaScript

After conversion, `visibility_rules` cover every show/hide. Two genuinely-dynamic, text-only bits remain — decide once:

- **Unit label** ("every N **week(s)/month(s)**"): the one piece of live text. Recommended: a single FormWriter `custom_script` on `rec_frequency` that sets the label — the sanctioned Level-2 mechanism, ~3 lines. (Alternative: drop the separate label; the frequency dropdown already names the unit.)
- **Live description preview** ("Every Monday and Wednesday until…"): recommended **drop it**. The server already renders `get_recurrence_description()` on load/after save, and the guided dropdowns make the pattern self-evident. Re-adding a live preview later is a small `custom_script` if ever wanted.

If both recommendations are taken, the recurrence section ships with one ~3-line `custom_script` and zero hand-rolled toggle JS, down from ~200 lines.

### Adjacent cleanup (same form)

The **all-day** checkbox currently hides the time fields via its own hand-rolled toggle (`cal-times.is-allday`). With checkbox triggers available, convert it to `visibility_rules` on `entry_all_day`: `checked → hide ['entry_start','entry_end']`. Small, on-theme, removes more bespoke JS.

---

## Out of Scope

- The **edit-scope** and **delete-scope** modals (this-occurrence / this-and-future / all). Those are modal *flows* that set a hidden `scope` and reveal/submit a form — not field show/hide, so not a `visibility_rules` case. Unchanged here.
- The quick-entry **popover** (the AJAX day-click create) — separate surface, unaffected.
- Any change to the recurrence storage schema or the expansion engine.

---

## Phases

### Phase 1 — Form + logic conversion

1. Rebuild the recurrence section in `views/profile/calendar.php` with the FormWriter inputs above, structural group wrappers, and `visibility_rules` on the four triggers. Remove the hidden `rec_*` fields.
2. Update `logic/calendar_logic.php` to read the real fields: `entry_repeats` (→ null type when off), `rec_frequency`, `rec_interval`, `rec_days[]` (→ comma list), `rec_monthly_mode` + `rec_week` + `rec_dow`, `rec_ends` + `rec_end_date` / `rec_count`.
3. Add `CalendarEntry::nth_occurrence_date()`; handle `count → end date` server-side. Delete the JS date-math (`computeEndDateFromCount`, `matchesPattern`, `fmtDate`).
4. Ensure edit pre-fill maps stored values back onto the inputs (weekly checkboxes from the comma list; monthly mode from whether the ordinal is set; ends as never/date).

*Checkpoint:* create and edit each recurrence type in the browser with no hand-rolled toggle JS; "after N" produces the same end date the old JS did.

### Phase 2 — Residual cleanup + tests + docs

1. Apply the unit-label and live-preview decisions; remove the remaining recurrence JS.
2. Convert the all-day toggle to `visibility_rules`.
3. Tests: `nth_occurrence_date()` matches the previous JS output across daily/weekly/monthly/yearly with intervals; logic round-trips each form shape into the right `cal_recurrence_*` columns; a saved entry re-renders with the right inputs pre-filled.
4. Docs: note in the calendar docs that the recurrence form is FormWriter-declarative (no bespoke JS), and that "after N" is stored as an end date.

*Checkpoint:* the recurrence section is a plain FormWriter form; grep shows no hand-rolled show/hide or date-math JS in `calendar.php`.

---

## Files

**Modify:** `views/profile/calendar.php` — rebuild recurrence section (FormWriter inputs + `visibility_rules` + structural wrappers); convert all-day toggle; remove hidden fields and ~200 lines of JS.

**Modify:** `logic/calendar_logic.php` — parse the real recurrence fields; server-side `count → end date`.

**Modify:** `data/calendar_entry_class.php` — add `nth_occurrence_date()`.

**Modify (docs):** the calendar developer docs — recurrence form is declarative; count-stored-as-date note.

**Tests:** `tests/calendar/` — `nth_occurrence_date()` parity, logic field round-trip, edit pre-fill.

---

## Dependency

Blocked on `specs/formwriter_visibility_checkbox_radio.md` (checkbox & radio visibility triggers). Land that first; this spec consumes it.
