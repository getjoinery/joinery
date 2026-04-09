# Calendly Feature Parity Ideas

This document captures features from the Calendly scheduling platform that Joinery does not yet have, organized by priority. These are ideas for consideration, not committed roadmap items.

**Reference:** Calendly (calendly.com) is an appointment scheduling platform focused on letting hosts define availability windows and letting invitees self-select time slots, with automatic calendar sync and conferencing link creation.

---

## Architectural Context: Two Different Event Models

Joinery and Calendly solve related but distinct problems. Understanding the difference is critical before planning any feature work.

**Joinery's current model — Fixed-time event management:**
- Admin creates an event at a specific date/time
- Guests browse events and register (via product purchase)
- Good for: classes, workshops, webinars, conferences, group sessions

**Calendly's model — Dynamic appointment scheduling:**
- Host defines *availability windows* (e.g., "Mon–Fri 9am–5pm")
- System generates open time slots, excluding calendar conflicts
- Invitee self-selects a slot; meeting is created automatically
- Good for: 1:1 meetings, consultations, demos, interviews

Joinery already has an incomplete Calendly integration (in `/ajax/calendly_webhook.php`, `/utils/calendly_synchronize.php`, and the `bookings` plugin) that was partially built but is currently disabled due to API payload mismatches. Several gaps below could be addressed by fixing and completing that integration rather than rebuilding natively.

---

## Already Present in Joinery

- Fixed-time events with registration (capacity, waitlist, surveys)
- Recurring events (daily, weekly, monthly, yearly with intervals)
- Event sessions (sub-events within an event)
- Timezone-aware event times (UTC storage + IANA timezone per event)
- Add-to-calendar links for Google, Yahoo, Outlook, and ICS download
- Event-linked product payments (Stripe, PayPal)
- Waitlist signup when event is full
- Pre-booking survey attachment (`evt_svy_survey_id`)
- Registrant management (list, export emails, remove registrant)
- Event leader/organizer field (`evt_usr_user_id_leader`)
- Location object with address, name, and website
- In-app notifications
- Partial Calendly integration (broken/disabled — bookings plugin, webhook handlers)

---

## Gaps — High Priority (Core Scheduling Engine)

### 1. Host Availability Windows
**Calendly:** Hosts define their working hours per day of week (e.g., "Monday–Friday, 9am–5pm"). The system generates available time slots from these windows, excluding blocks found in connected calendars.
**Joinery:** No availability window concept. Events have fixed start/end times set by admins; there is no slot-generation engine.
**Notes:** This is the foundational capability that separates appointment scheduling from event management. Implementing it requires:
- A `host_availability` table: user × day-of-week × start-time × end-time
- A slot generation algorithm that produces candidate times from availability windows
- Conflict exclusion based on existing bookings and (ideally) calendar sync
- An admin UI for hosts to set their weekly availability template

---

### 2. Appointment Booking Event Type
**Calendly:** An "event type" defines the meeting format (duration, location, intake questions, buffer times, etc.). Invitees book a specific slot from the generated availability.
**Joinery:** The `bookings` plugin has a `BookingType` model (with `bkt_calendly_event_type_uri`) suggesting this was planned, but no native booking-type creation or slot-selection UI exists.
**Notes:** Requires a new "appointment" event type distinct from the existing fixed-event model. A booking type defines: duration, buffer before/after, min/max advance notice, max bookings per day, intake questions, and location/conferencing method. Invitees see a calendar grid of open slots and pick one.

---

### 3. Buffer Time Before/After Bookings
**Calendly:** Hosts set padding before and/or after each meeting (e.g., 15-min prep buffer, 30-min recovery buffer). Buffered time is blocked and not shown as available.
**Joinery:** No buffer time concept.
**Notes:** Fields needed: `bkt_buffer_before_minutes` and `bkt_buffer_after_minutes` on the booking type. The slot generator must exclude the buffer window from adjacent availability.

---

### 4. Minimum Advance Notice
**Calendly:** Hosts require a minimum gap between "now" and the earliest bookable slot (e.g., "must book at least 4 hours ahead").
**Joinery:** No minimum notice field.
**Notes:** A single integer field `bkt_min_notice_hours` on the booking type. The slot generator filters out any slot starting within that window from the current time.

---

### 5. Maximum Bookings Per Day / Per Period
**Calendly:** Hosts can cap how many meetings of a given type (or total meetings) they take in a day, week, or month.
**Joinery:** Events have `evt_max_signups` (total capacity), but no per-day or per-period booking limits.
**Notes:** Fields: `bkt_max_per_day`, `bkt_max_per_week`. The slot generator must count existing bookings for the day/week and suppress slots once the cap is reached.

---

### 6. Date Range / Booking Window
**Calendly:** Hosts control how far ahead invitees can book — rolling window ("next 60 days") or fixed date range.
**Joinery:** No booking window for appointment types; fixed events have set dates.
**Notes:** Fields: `bkt_rolling_days` (integer, e.g., 60) or `bkt_window_start` / `bkt_window_end` (date range). The slot generator only produces slots within this window.

---

### 7. Custom Date Overrides
**Calendly:** Hosts can add one-off availability exceptions — blocking a normally-available day (vacation) or opening a normally-closed time slot.
**Joinery:** No override concept.
**Notes:** Requires a `host_availability_overrides` table: user × date × start-time × end-time × type (add/remove). The slot generator applies overrides on top of the weekly template.

---

## Gaps — High Priority (Calendar & Conferencing Integration)

### 8. Live Calendar Sync (Read Availability)
**Calendly:** Calendly connects to Google Calendar, Outlook, Office 365, or Exchange and reads the host's existing events to prevent double-booking. Up to 6 calendars can be checked simultaneously.
**Joinery:** Supports exporting events as add-to-calendar links (write-out), but cannot read from a host's external calendar. The broken Calendly integration relied on Calendly itself to handle this.
**Notes:** Implementing native calendar reading requires OAuth for Google (Calendar API) and Microsoft (Graph API), token storage per user, and a polling or push-notification mechanism to refresh busy blocks. This is significant infrastructure. An alternative path: keep using Calendly as the availability engine (fix the broken integration) rather than rebuilding this natively.

---

### 9. Automatic Video Conferencing Link Creation
**Calendly:** When an invitee books, Calendly automatically creates a unique Zoom, Google Meet, or Teams meeting link and includes it in all confirmations. No copy-pasting of static links.
**Joinery:** Events have a text location field and session video references, but no automatic meeting link generation per booking.
**Notes:** Requires OAuth integration with each conferencing provider. Zoom: uses the Zoom API to create a meeting per booking. Google Meet: uses Google Calendar API (meet link is auto-included when creating a calendar event). Teams: uses Graph API. Minimum viable: support one provider (Zoom or Google Meet) before others.

---

### 10. Write-Back to Host Calendar
**Calendly:** When a booking is confirmed, Calendly adds the meeting to the host's calendar automatically with all invitee details.
**Joinery:** Hosts can click an add-to-calendar link manually, but nothing is added automatically.
**Notes:** Requires the same OAuth tokens as gap #8. On booking confirmation, use the calendar API to create an event on the host's calendar with attendee info, meeting link, and intake responses.

---

## Gaps — High Priority (Invitee Experience)

### 11. Self-Service Reschedule and Cancel by Invitee
**Calendly:** Every confirmation email contains links letting the invitee cancel or reschedule without contacting the host. The host controls whether these are allowed and minimum notice required.
**Joinery:** Guests can withdraw from a fixed event via `/profile/event_withdraw`, but there is no rescheduling flow and no confirmation-email link. The withdrawal is permanent (no alternative time offered).
**Notes:** Requires:
- A signed token in confirmation emails linking to a cancel/reschedule page
- A reschedule flow that shows remaining available slots for the same booking type
- A cancellation policy setting (`bkt_cancel_notice_hours`) that blocks cancellation if too close to the meeting

---

### 12. Add Guests at Booking
**Calendly:** Invitees can add up to 10 additional attendees when booking. All added guests receive calendar invites.
**Joinery:** Registration is one user per order item; no guest addition mechanism.
**Notes:** Requires an "additional guests" field in the booking form (up to N email addresses), storage in a `booking_guests` join table, and inclusion in all confirmation/reminder emails.

---

### 13. Automated Pre-Event Reminders
**Calendly:** Email (and SMS on paid plans) reminders are sent automatically before the meeting — e.g., 24 hours before and 1 hour before — with customizable content.
**Joinery:** No automatic reminder system. Admins can manually export registrant emails, but nothing fires automatically.
**Notes:** Requires a scheduled task that queries upcoming events/bookings within a configured window and sends reminder emails via the existing `SystemMailer`. Fields needed: `bkt_reminder_hours` (array or multiple fields for timing). A more general "event workflow" system (see gap #18) would be the right long-term approach; a simple reminder-only implementation is achievable sooner.

---

### 14. Automated Post-Event Follow-Up
**Calendly:** Configurable follow-up emails are sent after the meeting — thank-you messages, feedback survey links, or next-steps.
**Joinery:** No post-event automation.
**Notes:** Same mechanism as gap #13 but triggered after `evt_end_time`. The existing survey system (`evt_svy_survey_id` with `evt_survey_display = 'after_event'`) covers one use case but requires manual email export today.

---

## Gaps — Medium Priority (Booking Page & Customization)

### 15. Embeddable Booking Widget
**Calendly:** Hosts can embed the booking interface on any external website as an inline block, a popup modal triggered by a button, or a floating button. The widget is fully self-contained.
**Joinery:** The tailwind theme has a Calendly inline widget embed (using Calendly's own JS), but no native embeddable booking widget exists.
**Notes:** A native booking widget (for embedding on external sites) requires a standalone HTML/JS component that loads available slots from a Joinery API endpoint and submits a booking. This is a significant frontend investment. A simpler approach: provide a hosted booking page URL (no iframe required) optimized for being shared as a link.

---

### 16. Booking Page Branding & Customization
**Calendly:** Hosts customize their booking page with logo, cover image, brand color, custom welcome message, and (on paid plans) removal of Calendly branding.
**Joinery:** The event detail page uses whatever the site theme provides. There are no per-booking-type branding controls (logo, cover image, accent color).
**Notes:** Requires per-booking-type or per-user customization fields: `bkt_cover_image_id`, `bkt_accent_color`, `bkt_welcome_message`. Theme layer must render these fields on the public booking page.

---

### 17. Custom Intake Questions on Booking Form
**Calendly:** Hosts add custom questions to the booking form (text, dropdown, checkbox) to collect information before the meeting. Responses are visible in the booking record.
**Joinery:** The survey system (`evt_svy_survey_id`) handles pre-booking questions at the event level, but the survey must be created separately and linked. There is no inline question builder per booking type.
**Notes:** An inline question builder on the booking type would be more operator-friendly than the current survey-link approach. Could be implemented as a simplified wrapper around the existing survey/questions system, or as a new `booking_intake_questions` table.

---

### 18. Event Workflow / Automation Engine
**Calendly:** "Workflows" are a trigger-action automation system: when an event is booked/canceled/no-showed/rescheduled, send an email or SMS at a configured time offset (before or after the event), with a customizable template.
**Joinery:** No workflow engine for events. Individual automations (reminder, follow-up) would need to be custom-coded per use case.
**Notes:** A lightweight event workflow engine would underpin gaps #13, #14, and no-show follow-up (#21). Minimal data model: `event_workflows` table with trigger (booked/canceled/reminder/followup), offset (e.g., -24 hours, +1 hour), channel (email/SMS), and template. A scheduled task processes pending workflow sends.

---

## Gaps — Medium Priority (Team & Multi-Host Scheduling)

### 19. Round Robin Scheduling
**Calendly:** Multiple team members share a booking type. The system assigns each booking to the next available team member in rotation, distributing load evenly.
**Joinery:** Events are single-host. No concept of a host pool or rotation.
**Notes:** Requires a `booking_type_hosts` join table (booking type → multiple users). The slot generator must find any host with availability for each slot and track assignment counts for rotation logic. Assignment is locked at booking time.

---

### 20. Collective Scheduling (All Hosts Must Be Free)
**Calendly:** Multiple required hosts all attend the meeting. Only times when all hosts are simultaneously available are shown to the invitee.
**Joinery:** Not present.
**Notes:** Same `booking_type_hosts` join table as round robin, but with a "collective" flag. The slot generator intersects availability across all required hosts instead of unioning it.

---

## Gaps — Medium Priority (Operations & Management)

### 21. No-Show Marking and Follow-Up
**Calendly:** After a meeting, hosts can mark an invitee as a no-show. This triggers a configured workflow (follow-up email, reschedule prompt) and is tracked in analytics.
**Joinery:** No no-show status on registrants or bookings.
**Notes:** Requires a `evr_is_no_show` boolean on `event_registrants` (or `bkn_is_no_show` on bookings) and an admin action to mark it. No-show status should suppress the "post-event" follow-up workflow and optionally trigger a "no-show" workflow instead.

---

### 22. Waitlist Auto-Promotion
**Calendly:** (Via group events) When a registered attendee cancels, the next person on the waitlist is automatically notified and given a time window to claim the spot.
**Joinery:** Waitlist signup exists but promotion is entirely manual. No automatic notification when a spot opens.
**Notes:** When a registrant's `evr_delete_time` is set (withdrawal), a hook should check `evt_allow_waiting_list` and if the event is now under capacity, send an email to the first waitlist entry with a time-limited claim link.

---

### 23. Attendee Check-In
**Calendly:** Not a Calendly feature per se, but scheduling platforms in this space commonly include check-in. Joinery's event management use case (classes, workshops) makes this relevant: marking who actually attended vs. who registered.
**Joinery:** No attendance/check-in tracking. Only registration records exist.
**Notes:** Requires an `evr_checked_in` boolean and `evr_checked_in_time` on `event_registrants`, plus an admin or mobile-friendly check-in UI. A QR code per registration (containing a signed token) would enable self-check-in.

---

### 24. Cancellation Policy Display and Enforcement
**Calendly:** Hosts set a cancellation policy (text + minimum notice requirement). The policy is displayed on the booking page and enforced when an invitee attempts to cancel.
**Joinery:** No cancellation policy field or enforcement.
**Notes:** Fields: `bkt_cancellation_policy_text` (displayed on booking page) and `bkt_cancel_notice_hours` (minimum hours before the meeting that cancellation is allowed). The invitee cancel flow (gap #11) must check this field.

---

### 25. Booking Analytics Dashboard
**Calendly:** Analytics show booking counts, completion rate, cancellation rate, no-show rate, top booking times, and per-event-type performance — filterable by date range and team member.
**Joinery:** `session_analytics` tracks event views, but no booking conversion funnel, no cancellation rate, and no per-event-type performance report.
**Notes:** Requires queries over `event_registrants`, `event_waiting_lists`, and the future bookings table, aggregated by event type, host, and date range. A new analytics admin page with charts (Chart.js is already present) would surface this data.

---

### 26. UTM Parameter Tracking on Bookings
**Calendly:** UTM parameters in booking page URLs (utm_source, utm_medium, utm_campaign) are captured at booking time and passed to Google Analytics as Key Events.
**Joinery:** UTM parameters are not captured or stored on registrations or bookings.
**Notes:** Requires storing `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` on the registrant/booking record at creation time. The booking form should read these from the URL query string (or session) and include them as hidden fields.

---

## Gaps — Lower Priority (Nice-to-Have)

### 27. SMS Reminders
**Calendly:** On paid plans, workflows can send SMS reminders via Twilio (up to 180 characters). Invitees opt in at booking.
**Joinery:** Email reminders only (once those are built).
**Notes:** Requires a Twilio (or similar) integration, an SMS consent field at booking, and an SMS send path in the workflow engine (gap #18). Meaningful only after the email reminder system is in place.

---

### 28. Routing Forms with Conditional Logic
**Calendly:** Before reaching a booking page, invitees fill out a routing form. Based on answers, the system routes them to a different host, event type, or external URL.
**Joinery:** The survey system exists but has no conditional routing logic.
**Notes:** Requires: a routing form builder (subset of the survey system with branching), a routing rules table (answer value → target event type or user), and a routing form view that redirects after submission. High complexity; most valuable for multi-host or multi-product organizations.

---

### 29. Booking Page Custom URL / Vanity Link
**Calendly:** Each event type has a shareable URL like `calendly.com/username/meeting-type`. Hosts can customize the slug.
**Joinery:** Events already have URL slugs (`evt_link`). Booking types would need their own slug.
**Notes:** Already partially handled by the existing `$url_namespace` / `evt_link` pattern. Booking types need their own slug field (`bkt_link`) and a route in `serve.php`.

---

### 30. Collective Intake / "One Link for Group Availability"
**Calendly:** A single scheduling link can check availability for a group of people and only show times all are free (e.g., send one link when scheduling a panel interview).
**Joinery:** Not present.
**Notes:** Depends on gap #20 (collective scheduling). Lower priority until core team scheduling is in place.

---

### 31. Fix and Complete the Existing Calendly Integration
**Current status:** The `bookings` plugin contains a partially-built Calendly sync (`/utils/calendly_synchronize.php`, `/ajax/calendly_webhook.php`, `/ajax/calendly_webhook_cancel.php`) that is disabled. Known issues:
- Uses Calendly API v1 payload format (nested `payload.event`); current API uses v2
- Field names reference `prd_*` (ProductDetail) instead of `bkn_*` (Booking)
- No webhook signature verification
- No curl error handling
- No pagination support in sync script

**Why this matters:** Fixing the Calendly integration (rather than rebuilding scheduling natively) would immediately provide: live calendar sync, slot generation from host availability, Zoom/Meet link creation, and invitee self-service cancel/reschedule — all features Calendly already handles well. This is potentially a faster path to appointment scheduling parity than building the slot engine from scratch (gaps #1–7).

---

## Summary of Biggest Gaps

| # | Feature | Impact | Complexity | Build vs. Fix Calendly |
|---|---|---|---|---|
| 31 | Fix existing Calendly integration | High | Medium | Fix |
| 1 | Host availability windows | High | High | Build |
| 2 | Appointment booking event type | High | High | Build |
| 13 | Automated pre-event reminders | High | Low | Build |
| 11 | Invitee self-service reschedule/cancel | High | Medium | Build |
| 8 | Live calendar sync (read availability) | High | High | Fix (via Calendly) |
| 9 | Auto video conferencing link creation | High | High | Fix (via Calendly) |
| 22 | Waitlist auto-promotion | High | Low | Build |
| 3 | Buffer time before/after meetings | Medium | Low | Build |
| 4 | Minimum advance notice | Medium | Low | Build |
| 5 | Max bookings per day/period | Medium | Low | Build |
| 18 | Event workflow / automation engine | Medium | Medium | Build |
| 23 | Attendee check-in | Medium | Low | Build |
| 25 | Booking analytics dashboard | Medium | Medium | Build |
| 21 | No-show marking and follow-up | Medium | Low | Build |
| 14 | Post-event follow-up automation | Medium | Low | Build |
| 19 | Round robin scheduling | Medium | High | Build |
| 17 | Inline intake questions on booking type | Medium | Medium | Build |
| 15 | Embeddable booking widget | Medium | High | Build |
| 24 | Cancellation policy enforcement | Medium | Low | Build |
| 27 | SMS reminders | Low | Medium | Build |
| 28 | Routing forms with conditional logic | Low | High | Build |
