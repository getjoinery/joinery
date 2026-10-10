# Volunteers Plugin Spec (shifts, sign-ups and hours)

**Purpose:** A volunteer scheduling and hours tool that runs inside a Joinery site. Nonprofits, clubs, schools and congregations post volunteer shifts, people sign up, a coordinator marks who showed, and the site keeps an auditable record of hours. It is sold once at one price: there are no tiers, no volunteer caps, no admin-seat caps and no per-feature add-ons.

**Status:** Active — not yet implemented. Open questions are listed in §12.

**Plugin name:** `volunteers`. The product name is "Joinery Volunteers".

**Guiding rule: when in doubt, leave it out.** Same rule as the Boards spec. The competitors that win reviews win on easy sign-up, not on feature count. Every feature here has to earn its place.

---

## 1. Research summary

Research date: 2026-10-10. Evidence is thin in places; see "Evidence quality" at the end of this section.

### 1.1 The market

| Product | Price (aggregator figures unless noted) | What is gated |
|---|---|---|
| SignUpGenius | Free with ads; about $12 / $30 / $60 a month | Ads on free; a 5% payment fee is reported; a competitor says even paid plans do not track hours |
| VolunteerHub | About $143–150 a month (up to 1,000 active volunteers); about $288 a month above that; setup fee | Tiers by active-volunteer count |
| Volgistics | $9 a month for 50 volunteers up to $225 for 10,000 | Public sign-up site and kiosk are paid add-ons |
| Better Impact | Quote-based; estimates $69–315 a month | Tiers by profile count |
| Galaxy Digital | Quote-based; about $3–6k a year (estimate). Better Impact acquired it in March 2026 | No free tier |
| Timecounts | Free basics; Pro $59 a month | Pro adds controls |
| Track It Forward | Free under 25 volunteers; $12–36 a month | Capped by size |
| VolunteerLocal | **Vendor page:** $600 / $2,400 a year, or $200–3,000 per event | Hours and attendance tracking is a $200 a year add-on on the cheapest plan; vetting and group registration are also $200 add-ons |
| Bloomerang Volunteer | About $119 a month | Priced by volunteer records |
| Golden | Free basic tier; about $100 a month paid | Sources conflict |
| Civic Champs | About $49 a month plus setup | Geofenced check-in is the differentiator |

The pattern: price is gated by volunteer count, admin seats, events or SMS volume, and basics like hour tracking are sold as extras. No product found markets "one price, no limits".

### 1.2 What users value, most-cited first

1. **Easy sign-up.** SignUpGenius is 4.6 of 5 from about 2,000 Capterra reviews; VolunteerHub's reviewers praise ease for volunteers and admins alike.
2. **Hour tracking that gives something back.** A school-parent reviewer: it "allows our school parents to easily track their volunteer hours for the school year".
3. **Reminders** that reduce no-shows (mostly vendor-sourced).
4. **Responsive support.**
5. **Reports usable for grants and audits.**

### 1.3 What users complain about, and our answer

| Complaint | Our answer |
|---|---|
| Ads on the free tier (Capterra). | None. The site is the organization's own. |
| Basics are paywalled (hours, vetting, groups, kiosk, SMS caps). | Every feature is in the one price. |
| Stagnation and weak reporting: "has barely changed in about five years"; reports "cumbersome". | A small set of ready-made reports (§8). |
| CRM sync "regularly creates duplicates" (VolunteerHub–Salesforce reviewer). | A volunteer is the same user record as the member, the donor and the event attendee. There is nothing to sync. |
| Dated, cumbersome time-slot setup. | One flat shift form; repeat rules reuse the calendar's recurrence block. |
| You outgrow the free tier as the group grows. | No volunteer or admin cap. |
| One-time events are awkward to set up. | A one-time opportunity and a recurring program are the same object (§3). |
| A 5% payment fee. | Volunteers pay nothing. Anything paid goes through the site's own Stripe account. |

### 1.4 Facts the design leans on

- **Value of volunteer time.** Independent Sector's 2026 national figure is **$36.14 an hour** (released 2026-04-21, up 3.9%). State figures range from $17.99 to $54.77. It is an average replacement-cost estimate, not a price per role. It changes every April, so it is a setting, not a constant (§8).
- **Court-ordered and student hours.** Tools that handle them (POINT, templates from MangoApps) keep the date, time, organization, service description and the **approving person**. They keep only the last four digits of identifiers. Each organization decides whether to accept court-ordered hours.
- **Auditability.** An hours record should show who approved the hours and when.

### 1.5 Evidence quality

- Only VolunteerLocal's pricing was read from the vendor's own page. Everything else is from aggregators that disagree with each other. Treat all other prices as ballpark and re-check before quoting them on a sales page.
- The comparison blogs (Galaxy Digital, Bugle, Rallyup, LiveImpact, Neon One) are marketing.
- **No Reddit or Trustpilot evidence was reached.** Review quotes come from Capterra, GetApp and Software Advice only.
- Not researched: swap and cover requests, Rosterfy's compliance features, and shift-style tools such as Deputy. The swap feature is therefore deferred (§2).
- Minors, background checks and data retention were **not** researched. Those parts of this spec are the author's own suggestions and must be checked by counsel before anyone relies on them (§9).

---

## 2. Scope

### P1 — the first sellable release

- **Opportunities and shifts:**
  - An *opportunity* is something people volunteer for ("Saturday food pantry"). It has one or more *shifts*, each with a start, an end, a role label and a capacity.
  - A one-time event and a recurring program are the same object. A recurring opportunity generates its shifts from a weekly or monthly rule.
- **Sign-up:**
  - Self sign-up from a public or members-only page.
  - Admin-assigned sign-up ("add this person to this shift").
  - **Guest sign-up** with no account: name, email and phone. An inactive user record is matched or created by email, the way the Bookings plugin does it.
  - A **waitlist** when a shift is full. A cancellation promotes the first person automatically.
  - Sign up for several shifts in one pass.
- **Attendance and hours:**
  - Coordinators mark each person on a shift's roster *attended* or *no-show* with one click.
  - Attending credits the shift length as hours, which the coordinator can edit.
  - A volunteer can also log hours they did on their own ("Self-reported hours"), which a coordinator approves or rejects.
  - Every approved hour record keeps who approved it and when.
- **Volunteer's page:** my upcoming shifts, cancel, my hours this year and in total, and a printable **hours letter** (§8).
- **Waiver and intake:** a per-opportunity waiver text the volunteer must accept (accepted time is stored), and an optional intake survey using the platform's Questions/Surveys.
- **Reminders and notices:** confirmation, reminder before the shift, waitlist promotion, cancellation by coordinator (§5).
- **Reports:** hours by person, by opportunity, by period, an in-kind value total, and CSV export (§8).
- **Coordinators:** a named person per opportunity who can see its roster and approve hours without being a site admin (§4).
- **Joinery wiring:** personal calendar, member dashboard, mobile apps, Joinery AI (§7).

### P2 — next release, same price

- **QR and kiosk check-in.** Each shift gets a code and a QR that a volunteer scans, or a staffed kiosk page lists today's shifts for tap-to-check-in. Check-in and check-out then credit exact hours.
- **SMS reminders,** once the platform's SMS integration exists (`specs/sms_messaging.md` is unbuilt). SMS is pass-through of the site's own provider, never billed as credits by us.
- **Volunteer groups** signing up together, using core Groups.
- **Guardian consent for minors** (§9).
- **Milestones:** a thank-you email and a badge at 25, 50, 100 hours.
- **Background-check status field:** a status, a date and an expiry, never the report itself (§9).
- **Student and court-ordered verification:** a supervisor attestation page with a signed-off record.
- **Swap and cover requests** between volunteers. Needs its own research first (§1.5).
- **Event link:** an Event Manager event can show its volunteer shifts, and a volunteer opportunity can link to its event (adds a nullable `vop_evt_event_id`, a plain integer with no foreign-key action so the event plugin may be absent).
- **Columns that arrive with their features:** `vsf_checkin_code`, `vsg_checkin_time`, `vsg_checkout_time` (check-in), and a contact-detail purge setting (retention). `update_database` adds columns for free, so none is added early.
- **Skills and interests** on the volunteer's profile, filterable on the roster.

### Deferred (not scheduled)

- Geofenced automatic check-in.
- Shift-trading marketplaces.
- Full applicant vetting pipelines.
- A native volunteer app. The mobile apps carry the web pages (§7).
- Corporate volunteering and employee giving.
- Large multi-site event operations (Rosterfy-style).

---

## 3. Data model

All tables come from `$field_specifications`. Each model gets a Multi class. Prefixes were checked as unused on 2026-10-10.

| Class / table | Key columns |
|---|---|
| `Opportunity` / `vop_opportunities` | `vop_title`, `vop_slug` (unique), `vop_description`, `vop_location`, `vop_visibility` (`public` / `members`), `vop_signup_mode` (`self` / `admin_only`), `vop_requires_approval` (a coordinator must accept each sign-up), `vop_waiver_text`, `vop_svy_survey_id` (nullable intake), `vop_timezone` (IANA; every shift of the opportunity uses it), `vop_usr_user_id_coordinator` (the one named coordinator), the five recurrence columns `vop_recurrence_type` / `vop_recurrence_interval` / `vop_recurrence_days_of_week` / `vop_recurrence_week_of_month` / `vop_recurrence_end_date` (the same five the calendar keeps on `cal_entries`; all null for a one-time opportunity), `vop_grp_group_id` (optional core Group that approved attendees join), `vop_min_cancel_notice_minutes`, `vop_delete_time` (soft delete doubles as archive) |
| `Shift` / `vsf_shifts` | `vsf_vop_opportunity_id`, `vsf_role` (free text, "Greeter"), `vsf_start_time` (UTC), `vsf_end_time` (UTC), `vsf_capacity`, `vsf_waitlist_enabled`, `vsf_is_generated` (made from the recurrence rule), `vsf_cancelled_time`, `vsf_delete_time` |
| `Signup` / `vsg_signups` | `vsg_vsf_shift_id`, `vsg_usr_user_id`, `vsg_status` (`confirmed` / `pending` / `waitlisted` / `cancelled` / `attended` / `no_show`), `vsg_source` (`self` / `guest` / `admin`), `vsg_waiver_accepted_time`, `vsg_action_token` (the guest's cancel link), `vsg_note`, `vsg_reminder_sent_for_start_time`, `vsg_create_time`. The waitlist order is `vsg_create_time` among `waitlisted` rows, so there is no position to renumber. Unique per (shift, user) while not cancelled. |
| `HourRecord` / `vhr_hour_records` | `vhr_usr_user_id`, `vhr_vop_opportunity_id` (nullable for general hours), `vhr_vsg_signup_id` (nullable: set when it came from a shift), `vhr_work_date`, `vhr_hours` (numeric(6,2)), `vhr_description`, `vhr_status` (`pending` / `approved` / `rejected`), `vhr_source` (`attendance` / `self_reported` / `admin`), `vhr_usr_user_id_approver`, `vhr_decided_time`, `vhr_reject_reason`, `vhr_delete_time` |

**One hours ledger.** `vhr_hour_records` is the only source for totals, reports and letters. Marking a signup *attended* inserts one `attendance` row, already `approved`, with the coordinator as approver. Changing the signup afterward (no-show, edited hours) updates that same row. Self-reported hours start `pending`.

**Guest sign-up.** A guest is an inactive `usr_users` row (permission 0) matched or created by email inside the same transaction that takes the seat, copying the Bookings plugin's invitee code (`book_logic.php`). It deliberately does **not** use the store's guest checkout, which sends a welcome and activation email and logs the guest in. A lost race leaves no user row behind. A guest who later creates an account by the same email gets their history.

**Capacity is a race.** Taking a seat uses a transaction that locks the shift row, re-counts confirmed sign-ups, and then inserts or waitlists. Two people grabbing the last seat produce one confirmed sign-up and one waitlisted one.

**Waitlist promotion.** A cancellation or a capacity increase promotes the first waitlisted person (oldest `vsg_create_time` among waitlisted rows) in the same transaction and then sends the notification after commit. Promotion skips anyone who has since been blocked from the opportunity.

**Recurring shifts.** The opportunity keeps the same five recurrence columns the calendar keeps on `cal_entries` (weekly or monthly, days of week, interval, ends on a date; "after N times" is converted to an end date, as the calendar does). Unlike the calendar (where occurrences are computed on the fly), shifts are **materialized rows**, because people sign up to a specific shift and that sign-up must have somewhere to live. The opportunity form lifts the calendar's inline recurrence block (`views/profile/calendar.php`, a FormWriter block with `visibility_rules`) unchanged. The date arithmetic should come from a shared `RecurrenceRule` class extracted from `CalendarEntry` (core change C1, §7); until that exists the fallback is to call `CalendarEntry::compute_dates_in_range` on a transient unsaved entry, which the calendar's own tests already do. One `VolunteersMaintenance` task keeps shifts generated `volunteers_horizon_days` ahead (a setting, default 60) and sends reminders (§5). Rules:

- A shift's wall-clock time is expanded per occurrence in the opportunity's IANA zone, never by adding 24 hours, so a daylight-saving change does not move it.
- Editing the rule changes only future shifts that have no sign-ups. Shifts with sign-ups stay until a coordinator cancels them (which notifies everyone on them).
- A generated shift can be edited or cancelled individually.

**Why not Event Manager for shifts.** Checked against the code: registrants have no attendance field, the waiting list has no automatic promotion, recurring instances are materialized one at a time by an admin and are bound to events, registration runs through a store product, and an event's leader gets no permission from being the leader. Reusing it would mean changing all five, so shifts get their own small engine. The cost is a third sign-up-and-capacity engine on the platform; if waitlist promotion and attendance are built as small shared helpers (core change C9, §7), Event Manager can adopt them later.

**Intake answers.** Survey answers are keyed by (survey, question, user), so an intake is stored once per volunteer per survey, and a second sign-up overwrites the first. That is right for intake. Guests cannot use the core survey page (it requires login), so the sign-up handler saves answers itself with the matched user's id, copying `booking_save_survey_answers` in Bookings.

**Deletion.** See the deletion system doc.

- Opportunities soft-delete. Deleting cascades a soft delete to their shifts. Existing `vhr_hour_records` rows **stay**: hours already earned are the volunteer's record. Their opportunity link becomes null with the opportunity's title kept in `vhr_description`.
- A user delete sets `vsg_usr_user_id` and `vhr_usr_user_id` to the platform's deleted-user value, like event registrants do.

---

## 4. Access

| Who | Can do |
|---|---|
| Anyone | See public opportunities and sign up as a guest (when the opportunity is public) |
| Logged-in member | See members-only opportunities; manage their own sign-ups; log their own hours |
| Coordinator of an opportunity | See its roster and volunteer contact details; mark attendance; approve or reject hours; message the roster; add or remove people |
| Site admin (permission 5+) | Everything in the plugin, for every opportunity |

The check is one helper, `Volunteers::canCoordinate(Opportunity $o, int $user_id)`. Every coordinator action calls it first. Site admins pass it for all opportunities.

**Contact details are the sensitive part.** Public pages show only counts and first names ("4 of 6 filled · Sam, Priya…") unless the opportunity chooses to show first names. Email and phone are visible only to coordinators and site admins. The model overrides `authenticate_read` so the REST and AI surfaces cannot bypass this.

---

## 5. Email and notifications

All emails go through core Notify with signals declared in `plugin.json`, each with `"default_email": true`. Members can mute them on the existing notification preferences page. Guests, who have no preferences page, always get the transactional ones (confirmation, reminder, cancellation) and an unsubscribe-from-reminders link.

| Signal | Recipient | When |
|---|---|---|
| `volunteers.signed_up` | the volunteer | confirmed, with a link to download the `.ics` and a cancel link |
| `volunteers.waitlisted` | the volunteer | full shift, with the position |
| `volunteers.promoted` | the volunteer | moved from the waitlist, with the same cancel link |
| `volunteers.reminder` | the volunteer | `volunteers_reminder_hours` before the shift (default 24) |
| `volunteers.shift_cancelled` | everyone on the shift | a coordinator cancels or moves it |
| `volunteers.hours_decided` | the volunteer | self-reported hours approved or rejected |
| `volunteers.new_signup` | the coordinators | opt-in per coordinator, default off |

**Why a link, not an attachment.** Notify queues mail with no attachment column; Bookings sends its `.ics` directly. A link to `/volunteer/shift.ics?token=…` is simpler, and members already see the shift on their calendar through the item source (§7). The `.ics` is built by the shared VEVENT builder (core change C2, §7).

**Reminders.** The `VolunteersMaintenance` task (every run) finds confirmed sign-ups whose shift starts inside the window and whose `vsg_reminder_sent_for_start_time` differs from the shift start. It sends and then sets that column. A reminder therefore goes out once, and goes out again only if the shift moves.

**Cancel link.** Guests have no login, so the sign-up carries an unguessable token (`vsg_action_token`, like `bkn_action_token` in Bookings). The link cancels that one sign-up and honors a per-opportunity minimum notice.

**Messaging the roster.** A coordinator's "Email these volunteers" button queues a normal bulk email through the platform's email system, targeted by a `volunteer_shift` recipient-group provider (the same pattern as event registrants).

---

## 6. Pages

Auto-routed from `views/`, no `serve.php` route needed except the slug route.

- `/volunteer/{slug}` (one `serve.php` placeholder route): the opportunity page. Description, upcoming shifts as a list with capacity bars, and a **Sign up** button per shift. Guests get a small form; members confirm in one click. Multiple shifts can be ticked and submitted together.
- `/volunteer`: the list of open opportunities (public ones, plus members-only ones for members).
- `/profile/volunteers`: my shifts, my hours, log hours, the hours letter.
- `/profile/volunteers/coordinate`: for coordinators, each of their opportunities with the next shifts, a roster per shift, an attendance toggle per person, the pending-hours queue, and the add-a-person box.
- `/plugins/volunteers/admin/admin_opportunities`, `admin_opportunity_edit`, `admin_volunteer_reports`, and a settings group.

**Forms.** Every form uses FormWriter, including the guest sign-up form (name, email, phone, waiver checkbox, intake questions). Action buttons (cancel a shift, mark attended, approve hours) are POST buttons or API actions called through the session + CSRF client, never links.

**Front end.** Vanilla JS and CSS using the `.jy-ui` kit.

**The flat shift form.** Creating an opportunity asks: title, description, where, who can sign up. Then a shift grid: date, start, end, role, capacity, and a "repeat" link that opens the recurrence widget. Nothing is hidden behind menus.

---

## 7. Joinery integration

| System | How Volunteers uses it |
|---|---|
| **API** | Every action is a logic action with a `_logic_descriptor()`, exposed under `/api/v1/action/volunteers/{action}`. Actions: `volunteers_signup`, `volunteers_cancel`, `volunteers_shift_save`, `volunteers_shift_cancel`, `volunteers_attendance`, `volunteers_hours_log`, `volunteers_hours_decide`, `volunteers_roster`, `volunteers_report`. Guest-reachable sign-up and cancel declare `allow_guest`. No `/ajax/` endpoints. |
| **Calendar** | `VolunteerShiftItemSource` in `includes/calendar_item_sources/` projects a user's confirmed and waitlisted shifts as calendar items, so they appear on the personal calendar and in the native apps. Confirmed shifts block availability (so a Bookings host who volunteers is not double-booked). Waitlisted shifts do not. |
| **Groups** | A signed-up volunteer can be added to a core Group named by the opportunity (`vop_grp_group_id`, optional), which lets the site gate other content to "people who volunteered". Group membership follows an *approved attendance*, not just a sign-up. |
| **Member dashboard** | A `ProfileDashboardRegistry` section, "My next shift" with total hours. |
| **Mobile apps** | A `profileMenu` entry (`/profile/volunteers`, icon `calendar` or an existing one) shows it in the iOS and Android apps as a web view. |
| **Joinery AI** | `Opportunity`, `Shift` and `HourRecord` set `$ai_readable`. Free-text fields from guests (`vsg_note`) are listed in `$ai_untrusted_fields`. AI writes (draft an opportunity, propose shifts) go through the existing approval queue. |
| **Files and surveys** | Intake uses core Questions/Surveys. Waivers are text on the opportunity for P1. |
| **Donations plugin** | Shares nothing but the user record. The Donations spec's "Supporters" screen shows a person's hours beside their giving if both plugins are active (a read-only panel through the admin-user panel registry, not a dependency). |

### Core changes that would make this plugin simpler

Found by review on 2026-10-10. Each is a small change to code that already exists; the owner decides whether to make them (Q4).

- **C1. Extract a `RecurrenceRule` class** from the five recurrence columns: `compute_dates_in_range`, `nth_occurrence_date` and a description. `CalendarEntry` and `Event` each carry their own copy of this arithmetic today, so Volunteers would be the third. One class, with the two existing ones delegating to it, removes the copies and fixes any daylight-saving bug once.
- **C2. A generic VEVENT builder** (uid, start, end, zone, summary, location, description). `IcsHelper` is shaped around events and Bookings hand-rolls its own `.ics`; Volunteers would be the third. One builder, used by all three.
- **C9. Waitlist promotion and attendance marking as shared helpers**, which Event Manager can adopt (it has a waiting list with no automatic promotion and no attendance field).

---

## 8. Reports, letter and in-kind value

**Reports** at `/plugins/volunteers/admin/admin_volunteer_reports`, each exportable as CSV:

1. **Hours by person** for a date range.
2. **Hours by opportunity** for a date range, with head-count.
3. **Shift fill rate:** capacity, confirmed, attended, no-show.
4. **In-kind value:** approved hours × rate. The rate is the `volunteers_hour_rate` setting (default **$36.14**, the 2026 national figure, with the source and year shown beside it). A state figure can be typed in. The report prints the rate and the source it used, because an auditor will ask.
5. **Pending hours:** self-reported hours awaiting a decision.

**Hours letter.** `/profile/volunteers/letter?from=…&to=…` renders a printable page: the volunteer's name, the organization's name (a setting), the date range, each opportunity with approved hours, a total, the date issued, and the names of the approving people. It is the document a student, a court-ordered volunteer or an employer asks for. P2 adds a supervisor attestation line.

**Retention.** Hours are never purged automatically. A contact-detail purge setting is P2 (§9).

---

## 9. Privacy, minors and screening

These are the author's suggestions, **not researched** (§1.5). A lawyer who knows the organization's jurisdiction should review them before anyone relies on them.

- **Minors.** P1 does not knowingly serve people under 18. The opportunity page can state an age rule, and a sign-up asks for a date of birth only if the intake survey asks for it. P2 adds guardian consent: the guardian's name, email and a consent timestamp, with the minor's contact details hidden from every list except the coordinator's roster.
- **Background checks.** The platform never stores a report. P2 stores only a status (`not_required` / `pending` / `cleared` / `expired`), a date and an expiry, set by a coordinator, and a "cleared only" option on a shift.
- **Court-ordered and student hours** are only as trustworthy as the approver. The hours letter names the approver; a stricter attestation is P2.
- **Data minimization.** Guests give a name, email and phone only. No government identifiers are ever collected.
- **Protection level.** Standard. Volunteer records are not encrypted at rest beyond the platform's normal level; "Private" and "Fortress" are reserved for the encryption levels.

---

## 10. Packaging and sale

- `plugin.json`: `"license": "Joinery-Commercial"`, `"requires_entitlement": true`, and `"status": "beta"` until the full P1 checklist below passes.
- Sold as one store product with perpetual updates, through the existing ownership / license-key path.
- Depends on nothing but core. Event Manager and Donations links are optional and checked with `class_exists`.
- **One-line pitch:** *Sign-ups, reminders and a clean record of hours, for any number of volunteers, on your own site and in the same database as your members and donors.*

---

## 11. Testing

Each item below is a test with an `@joinery-test` header.

**`plugins/volunteers/tests/volunteers_signup_test.php`** (test-db):

- Two simultaneous sign-ups for the last seat: one confirmed, one waitlisted.
- A guest sign-up creates one inactive user; a lost race creates none; the same email later reuses the row.
- Cancel promotes the first waitlisted person, and a blocked person is skipped.
- Guest cancel link works once and respects minimum notice.
- Sign-up for several shifts in one request is all-or-nothing per shift, with a clear per-shift result.

**`plugins/volunteers/tests/volunteers_hours_test.php`** (test-db):

- Marking attended inserts one approved ledger row; changing it to no-show updates the same row; editing hours updates the total.
- Self-reported hours start pending and don't count until approved; a rejection records the reason.
- Totals and the letter agree with the ledger.
- Deleting an opportunity keeps the hours.

**`plugins/volunteers/tests/volunteers_recurrence_test.php`** (test-db):

- A weekly 9:00 shift stays at 9:00 local across a daylight-saving change.
- Editing the rule leaves shifts with sign-ups alone and notifies on cancellation.
- The generator is idempotent.

**`plugins/volunteers/tests/volunteers_access_test.php`** (test-db): a coordinator sees only their opportunities' rosters; a stranger sees no contact details; public pages show first names only when enabled; every API action refuses what it should.

**`plugins/volunteers/tests/volunteers_notify_test.php`** (test-db): recipients per signal; reminders go once and again only after the shift moves; guests get transactional mail without a saved preference.

**Live walk:** create an opportunity with a repeating shift; sign up as a guest on a phone; fill the shift; cancel to see the waitlist promote; mark attendance; print the hours letter.

---

## 12. Open questions

- **Q1. Should hours need approval for attended shifts?** The draft says no: marking someone attended is the coordinator's approval. A stricter option makes every attendance row pending until an admin signs off. Recommend the draft (fewer clicks), since the coordinator is already a named person.
- **Q2. Should the first release include QR check-in?** The research ranks it as a commonly paywalled feature, which makes it a differentiator, but roster tap-to-mark covers the need for small groups. Recommend P2.
- **Q4. Make core changes C1, C2 and C9?** *Yes:* Volunteers stays small and the platform loses duplicated code. The catch is touching the calendar and Event Manager, which are built and tested. *No:* Volunteers carries a transient-`CalendarEntry` workaround and its own `.ics` string. Recommend C1 and C2 (they are extractions with existing tests), and C9 only when Event Manager needs it.
- **Q3. Can members-only sign-up ask for a login before showing the shift list?** The draft shows the list to guests on public opportunities only. No open decision unless the owner wants public opportunities to require an account.

---

## Sources

- VolunteerLocal pricing (vendor page): https://www.volunteerlocal.com/pricing
- Capterra, SignUpGenius reviews and pricing: https://www.capterra.com/p/135392/SignUpGenius/reviews/
- Capterra, VolunteerHub: https://www.capterra.ie/reviews/79209/volunteerhub
- Capterra, Bloomerang Volunteer: https://www.capterra.com/p/144845/InitLive/
- Capterra, VolunteerMark reviews: https://www.capterra.com/reviews/133194/VolunteerMark
- GetApp, Timecounts and Volgistics: https://www.getapp.co.uk/software/105639/timecounts, https://www.getapp.com/all-software/a/volgistics
- G2 comparison, Timecounts / VolunteerHub / Volgistics: https://www.g2.com/compare/timecounts-vs-volunteerhub-vs-volgistics
- Toolradar, Volgistics pricing: https://toolradar.com/tools/volgistics/pricing
- Better Impact, acquisition of Galaxy Digital: https://www.betterimpact.com/better-together
- Independent Sector, value of volunteer time: https://independentsector.org/research/value-of-volunteer-time/
- NonprofitPRO, $36.14 an hour: https://www.nonprofitpro.com/article/new-estimate-puts-value-of-volunteer-time-at-36-14-an-hour/
- POINT, court-mandated hours: https://support.pointapp.org/knowledge/court-mandated-or-ordered-volunteer-hours-court-ordered-community-service
- MangoApps, court-ordered hours verification template: https://www.mangoapps.com/templates/forms/court-ordered-community-service-hours-verification-form
- Civic Champs, validating volunteer hours: https://www.civicchamps.com/post/how-to-validate-volunteer-hours
- Marketing-grade comparison blogs (low weight): Galaxy Digital, Bugle, Rallyup, LiveImpact, Neon One, Teachfloor, Civic Champs
