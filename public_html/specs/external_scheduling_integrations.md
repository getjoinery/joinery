# Bookings Roadmap — Native Gaps and External Integrations

**Status:** Active — not yet implemented. Revisited 2026-10-10 against new market research and the code as it stands.

**File name.** The file keeps its old name because other documents refer to it by name. Its scope is now the whole forward plan for the Bookings plugin: what the native engine still lacks (Part 1) and how external scheduling and calendar services connect (Part 2).

**Companion to** `specs/implemented/scheduling_system.md` (the personal calendar, schedules and slot generation) and `specs/implemented/native_booking_flow.md` (the booking engine). Both are built; the plugin's current behaviour is in `plugins/bookings/docs/overview.md`.

**Pre-launch note:** The platform has no production users. No data-preservation migrations are required.

## What changed in this revision

1. **The native engine is built.** The earlier draft was written before it shipped. Part 1 now compares it with what the 2026 market expects and ranks what is missing.
2. **Live calendar sync moved up, from "last" to a first-class need.** Reviewers rank "calendar sync that prevents double-booking" first among what they value in a booking tool. The old draft put sync last because the target buyer is leaving Google. Both facts are true, so Part 2 offers sync routes that do not require Google (§6).
3. **Cal.com closed its code on 2026-04-14.** That strengthens the case for a self-hosted booking tool and removes the best-known open-source comparison (§1.3).
4. **Calendly import no longer needs a Calendly OAuth app.** A one-time migration can use a personal access token the host pastes in (§7).
5. **Proxy mode for Calendly and Acuity is demoted** to "only on request". The reason is in §7.
6. **Corrections to names.** The booking type's prefix is `bty_` (the old draft said `bkt_`), and `cal_` is already the prefix of calendar entries, so calendar connections use `ccn_` (§8).
7. **Core already ships Google and Microsoft OAuth providers** (used for DNS publishing and mail), which shrinks the OAuth work for sync.

---

## 1. Research summary

Research date: 2026-10-10. Prices outside five vendor pages come from competitor comparison blogs and conflict with each other; re-read the vendor's own page before putting any figure on a sales page.

### 1.1 The market

| Product | Price | Model | What is gated |
|---|---|---|---|
| Calendly | Free (1 event type); Standard $10 per seat; Teams $16 per seat; Enterprise from $15k a year (vendor page) | Per seat | Payments, reminders/workflows and branding start at Standard; round-robin and routing at Teams |
| Cal.com | Free; Teams $12 per user a month; Organizations $28 (vendor page) | Per seat | Round-robin, branding removal and custom notifications from Teams |
| Acuity (Squarespace) | About $16–61 a month (sources conflict); no free plan | Flat per account | SMS and customization higher up; processor fees extra |
| SimplyBook.me | Free for 50 bookings; $11.90 / $24.90 / $49.90 (vendor page) | Flat tiers, capped by bookings and providers | SMS credits $8 per 100; custom domain $119; white-label only on Premium |
| Setmore, Zoho Bookings | Free tiers; about $5–9 per user | Per user | Payments on Zoho's top tier |
| Square Appointments | Free for 1 staff; paid per location (figures conflict) | Per location | Team features |
| Google Calendar appointment schedules | Free single page | Bundled | Multiple types, reminders, payments and spam email verification need eligible Workspace tiers |
| TidyCal, SavvyCal | $29 once; $10–12 per user | One-time / per user | |
| Amelia, Bookly (WordPress) | $49–679 a year per domain, or lifetime | Per domain | |
| Fresha, Booksy, Vagaro, Mindbody, Jane | About $15–139 per staff, location or practitioner | Vertical tools with POS and billing | Fresha adds a 20% fee on new marketplace clients; add-ons push a 5-person shop to $150–200 |

Individuals pay per seat and the pain is team scaling. The vertical tools are the wrong shape for a club or a nonprofit.

### 1.2 What users value, ranked

1. **Reliable calendar sync that prevents double-booking.** Capterra reviewers credit Calendly for this. Calendly holds 4.7 from about 4,000 Capterra reviews.
2. **Simplicity and speed to set up.**
3. **Reminders** (the main no-show lever).
4. **Payments and deposits at booking.**
5. **Self-service reschedule and cancel links, and embeds on the user's own site.**

### 1.3 The open-source landscape changed

Cal.com announced on 2026-04-14 that it was moving production code out of public view. The public repository became **Cal.diy**, relicensed MIT, with the enterprise features removed (Teams, Organizations, Insights, Workflows, SSO), and described as "recommended for personal, non-production use". The company framed it as security; critics read it as an open-core upsell. A critical authentication flaw (CVE-2026-23478, versions before 6.0.7) was reported against it. The sources for these facts are secondary reporting plus the Cal.diy readme; the Cal.com blog was not read, and it should be before any sales page repeats them.

What remains open source is **Easy!Appointments** (PHP, GPL-3.0, actively committed): no payments, no memberships, no team features, Google Calendar sync only. So there is no maintained, production-grade, permissively licensed self-hosted booking product from a major vendor. That is Joinery's opening, and the source of the "degoogle" buyer this spec was written for.

### 1.4 What users complain about, and our answer

| Complaint | Our answer |
|---|---|
| Per-seat price ("quite expensive", G2; "steep across a full team"). | One price, unlimited hosts, types and bookings. |
| Useful features locked behind tiers (Capterra). | No tiers in the plugin. |
| Limited design and branding control (TechRadar on Acuity). | Booking pages render in the site's own theme. |
| Add-on creep: SMS credits sold separately (SimplyBook, Vagaro, Jane). | Use the site's own messaging; never sell credits. |
| Sync delay and iCloud trouble (one Capterra reviewer). | Show "last synced" on every connected calendar; the booking-time check reads fresh (§6). |
| Lock-in and relicensing (Cal.com, April 2026). | Code and data on the customer's own server. |
| **Booking is detached from members, events and the store.** No tool in the list ties booking to a membership tier or a purchase. | This is the core differentiator (§3). |

### 1.5 Evidence quality

- Vendor pricing pages were read only for Calendly, Cal.com and SimplyBook.me. Everything else is aggregator or competitor-blog data.
- **No Reddit or Cal.com GitHub threads were reached.** Complaint quotes come from Capterra and G2 summaries and secondary articles.
- Not researched: Appointlet and Bookly pricing, Microsoft Bookings plan gating, Calendly SMS gating, Mindbody's official pricing, Cal.com's API (relevant to §7).
- The advice about daylight-saving and range constraints in §3 comes from engineering blogs, not from the PostgreSQL documentation.

---

## 2. Where the native engine stands

From the plugin documentation as of 2026-10-10. Rows marked **verify** are not mentioned in the docs and need a quick check against the code before this table is relied on.

| 2026 expectation (E = essential, N = nice) | State | Part |
|---|---|---|
| Weekly availability, overrides, buffers, minimum notice, booking window, per-day and per-week caps (E) | Built | — |
| Several bookable types per host, with duration and price (E) | Built | — |
| Race-safe creation, no double-booking (E) | Built: a per-host advisory lock, then a re-check | — |
| Payments at booking, with a hold that expires (E) | Built (a booking type can name a product; holds count as occupied until they expire) | — |
| Cancel and reschedule by link, with notice rules (E) | Built | — |
| Email reminders and follow-up, idempotent across reschedules (E) | Built | — |
| ICS attachment (E) | Built | — |
| Intake questions (E) | Built (Questions/Surveys) | — |
| Timezones (E) | Built (UTC stored, invitee zone recorded) | — |
| Static meeting link or location per type (E) | Built | — |
| Group or class booking with capacity (E) | Not in Bookings; **Event Manager already has capacity, waiting lists and paid registration** | N1 |
| Membership gating, allowances and packages/credits (E, and our differentiator) | Not built; **verify** whether a booking type can be tier-gated today | N2 |
| Several hosts or a resource (room, court) behind one type (N; "E" for clubs and studios) | Not built; the calendar's subject model reserves `resource` and `team` | N3 |
| Spam and bot controls (E) | **Verify.** Paid holds expire; nothing else is documented | N4 |
| Embeddable widget (E) | **Verify** | N5 |
| SMS reminders (N) | Unbuilt platform piece (`specs/sms_messaging.md`) | N6 |
| Zoom or Meet link auto-created (N) | Not built | N7 |
| Two-way calendar sync (E) | Not built | Part 2 |
| Recurring series, waitlist, no-show fee holds (N) | Not built | Deferred |
| Workflows engine, routing forms, SSO, insights (skip) | Not planned | — |
| AI scheduling agent (skip for now) | Not planned; bookings are API actions, so an agent could use them later | — |

---

## 3. Part 1 — Native gaps, in order

### N1. Classes and group sessions: use Event Manager, don't rebuild it

A class with 12 seats, a waiting list and a payment is already an event. Building a second capacity-and-waitlist engine inside Bookings would duplicate Event Manager. Instead:

- A **"Book with us" landing page** (`/book`) lists bookable one-to-one types and upcoming events (classes, workshops) together, grouped by host, from the two plugins' own data. Each entry links to its own flow.
- Both projections already land on the host's calendar (`BookingItemSource`, `EventItemSource`), so a class and a one-to-one cannot overlap on the host's calendar and availability (a class blocks the host's bookable time).
- No new tables. Cost: one view and a small provider list that each plugin registers into.

### N2. Memberships, allowances and packages (the differentiator)

This is where tying booking to the membership platform wins, and no competitor does it.

- **Gating by tier.** A booking type can be limited to people holding a subscription tier (`bty_tier_min_level`, using the platform's existing tier gating, the same as other tier-gatable entities). Others see a prompt to join.
- **Credits.** A new ledger, `bcr_booking_credits`: `bcr_usr_user_id`, `bcr_bty_booking_type_id` (null for "any type"), `bcr_delta` (+ grant, − use), `bcr_reason` (`purchase` / `tier_allowance` / `admin` / `booking` / `refund`), `bcr_bkn_booking_id`, `bcr_expires_time`, `bcr_odi_order_item_id`. A user's balance is the sum of unexpired rows.
- **Using a credit.** At booking, a user with an unexpired credit for that type pays with it instead of the product price: the credit row is written inside the same locked transaction that takes the slot. Canceling inside the notice window returns the credit; a late cancel or a no-show keeps it, per the type's policy text.
- **Selling a package.** A store product with fulfillment provider `booking_credits` (registered with the store's `FulfillmentRegistry`, the way events register `event_registration`) grants N credits on purchase ("10-session pack", valid 12 months).
- **Tier allowance.** "Gold members get 2 free sessions a month" is a monthly grant of credits, written by a scheduled task at the start of each billing period, or on each paid renewal (`subscription.payment_succeeded`, the signal proposed in the Donations spec §4.2). The task is simpler and does not depend on that signal; use it.
- **Refunds** of a package product revoke unspent credits (a negative `bcr_reason = refund` row, never below zero).

### N3. Several hosts and shared resources

Clubs and studios need "any coach" and "court 2".

- A booking type may name a **pool** (a core Group of hosts, or a list of resources) instead of one host. The slot picker shows the union of free slots; the booking is assigned to a specific member of the pool at creation.
- **Assignment:** the invitee may choose, otherwise the least-recently-booked free member (fair rotation), or the first by priority, as a per-type setting.
- **Resources** use the calendar's reserved subject type `resource` (a room or court with its own schedule). The seam was built for this: a subject type is added in one resolver and two tables.
- The existing race-safe pattern extends cleanly: the advisory lock is taken on the *chosen* host or resource after the pool decides, and the slot re-check is run for that subject only.
- This is the biggest build in Part 1. It should be specced on its own when scheduled.

### N4. Spam and abuse controls

A public booking page that sends emails is an open target for bots and for being used to mail strangers.

- Per-IP rate limit on the slots and booking actions (IPv4 and IPv6 compared as addresses).
- A honeypot field and the platform's existing CAPTCHA support (FormWriter hCaptcha/reCAPTCHA) as a per-type switch.
- **Email verification for unpaid bookings:** the booking is created as a short-lived hold (the paid-hold mechanism already exists) until the invitee clicks a link in the email. Google's own paid tier offers the same control for the same reason.
- Paid holds already expire; unpaid unverified holds expire in 15 minutes (a setting).

### N5. Embeds

An iframe snippet and a chrome-less view (`/book/{slug}?embed=1`) that drops the site header and footer and posts its height to the parent so the frame fits. The admin page for a booking type shows the snippet with a copy button. No script is loaded on the host's page beyond the one `<script>` they paste for auto-resize (optional).

### N6–N7. Later

- **N6 SMS reminders:** once the platform's SMS helper exists, add an SMS channel to the reminder task. The cost is the site's own provider's per-message fee; Joinery never bills credits.
- **N7 Auto-created video links:** needs a Google or Microsoft connection with a calendar-write grant; it falls out of Part 2's write-back and is not a separate build.

### Deliberately skipped

- **A database exclusion constraint for double-booking.** Engineering guidance says to use one (`btree_gist` with a range-overlap rule) in place of check-then-insert. Our creation path already holds a per-host lock and re-checks inside the transaction, which is the same guarantee. A constraint also can't express the *expiring paid hold* rule (`occupies_host_time()` depends on the current time, which cannot appear in a constraint). So the lock stays. If a second code path that creates bookings is ever added, route it through the same function.
- **Recurring series, waitlist for one-to-one, no-show card holds, a workflows engine, routing forms, SSO, analytics dashboards:** not asked for by the research's "essential" list, and each adds clutter.

### Open item carried over: tentative calendar entries and availability

AI-extracted calendar entries land as *tentative* and block availability by default. The plugin has never decided whether they should. The conservative choice, and the recommendation: **keep them blocking**, because a false "busy" costs a missed slot, while a false "free" costs a double-booking. Offer a per-host switch "Tentative entries don't block my bookable time" (default off). See Q1.

---

## 4. Part 2 — External services: the two uses

This is the original premise of the spec, unchanged: an external account can be used to **migrate off** (import its configuration, then disconnect) or to **proxy** (keep it as a live backend). Migration is the primary path for the buyer who is leaving Calendly or Acuity.

**The sync question is different and comes first now.** The old draft treated live calendar sync as a tether back to the services buyers are leaving. The research says the opposite is also true: the thing booking-tool users value most is that their other calendar blocks their bookable time. So Part 2 offers four ways to see someone's other calendar, from lightest to heaviest (§6), and the buyer picks one that fits their politics.

---

## 5. Provider abstraction (unchanged contract)

`SchedulingServiceProvider` and `SchedulingProviderRegistry` exist in `plugins/bookings/includes/`, as does `NativeSchedulingProvider` (headless, no connection). The interface carries everything an external provider needs (`getConnectionFields`, `getConnectUrl`, `listEventTypes`, `getAvailableSlots`, `createBooking`, `cancelBooking`, `getEmbedHtml`, `registerWebhooks`, `verifyWebhook`, `handleWebhook`), and the bookings table already has `bkn_provider` and `bkn_external_uri`. This spec adds implementations; it does not change the contract.

### Connections

**`bpc_provider_connections`** (bookings plugin; the prefix is unused):

- `bpc_provider_connection_id`
- `bpc_usr_user_id` — the host
- `bpc_provider`
- `bpc_credentials` — SecretBox-encrypted JSON (token, or API key and user id)
- `bpc_external_user_uri`
- `bpc_webhook_uri`, `bpc_webhook_signing_key` (encrypted)
- `bpc_status`, timestamps

Hosts connect at `/profile/bookings/connections`. After connecting, `listEventTypes()` powers an import step.

**Import step.** The page lists the external event types and lets the host bring each in as a **native** type (default, recommended: duration and a native schedule the host then tunes) or as a **proxy** type. When every type a host cares about is native, they disconnect with nothing left behind.

---

## 6. Calendar sync: four routes

All four plug into the existing `CalendarItemSource` registry, so once one exists the host's personal calendar shows the external events (`type=external`) and the busy projection that slot generation consumes already includes them. Nothing else changes.

| Route | How it works | Strengths | Weaknesses |
|---|---|---|---|
| **R1. Secret ICS feed** | The host pastes the "secret address in iCal format" that Google, Outlook, Fastmail and Proton can publish. We fetch it on a short TTL. | No OAuth, no Google app, no verification. Works with any service that can publish a feed. Fits the leaving-Google buyer. | **Read-only and stale.** Providers refresh published feeds slowly (Google has been reported to take hours; not measured here). A just-added meeting may not block a slot. The feed URL is a secret. |
| **R2. CalDAV** | Username and an app password; we read free/busy and optionally write events. | Open standard. Nextcloud, Fastmail, Radicale, iCloud and Google (via OAuth) speak it. Live. Two-way. Fits the degoogle buyer exactly. | A reviewer reported iCloud sync trouble. A CalDAV client is real work, and server behaviour varies. |
| **R3. Google Calendar API (OAuth)** | Read busy and write events with the host's grant. | The best live behaviour for the largest base. | Each self-hosted site needs its own Google OAuth client. The calendar scope is a *sensitive* scope that normally needs Google's app verification; while an app is in "Testing" status, refresh tokens are reported to expire after seven days and only listed test users can connect (**verify both against Google's current documentation before building**). A managed-hosting offering could share one verified app; a self-hoster cannot. |
| **R4. Microsoft Graph (OAuth)** | Same shape as R3 through Microsoft's endpoints. | Covers Outlook and Microsoft 365. | Same per-site app-registration burden. |

### Handling the SSRF risk in R1

A feed URL is a destination chosen by a user, so every fetch goes through `SafeHttpClient` (validated, pinned, redirects re-checked, response capped). No direct `curl` or `file_get_contents`.

### Shared rules for all four

- **Busy cache.** `cbb_calendar_busy_blocks` caches fetched busy blocks with a TTL. There is deliberately no background refresh task: it would spend quota on hosts nobody is viewing. Browsing tolerates a stale TTL; **booking confirmation makes one live freshness check** against connected calendars (R2–R4), so a stale cache can't cause a double-booking for live routes. For R1 the check can only be as fresh as the feed, and the host's page says so.
- **"Last synced" is shown** on every connection, with a Refresh button. This is the answer to the sync-delay complaint.
- **Echo dedup.** When a native booking is written to the host's external calendar (write-back) or arrives there by invitation, the same meeting is read back and would appear twice. Native bookings carry the ICS UID `booking-{id}@{site host}` (in their confirmation `.ics`, which the host may have accepted into Google). The external source sets each item's `source_key` from the UID, and the calendar and busy projection collapse items with the same key. A write-back stamps the same UID into the external event. This is the first place `source_key` dedup applies.
- **Write-back (R2–R4).** On confirmation, create an event on each calendar flagged `ccn_write_back` (attendee email, intake summary, location or auto-created video link); on cancellation, delete it. The external event id is stored on the booking (`bkn_external_calendar_event_id`, a new column). With R1, no write-back is possible; the host's confirmation email carries the `.ics` so they can add it themselves.
- **Disconnect** deletes cached blocks and leaves events already written in place.

### Core pieces

- **`CalendarConnection` / `ccn_calendar_connections`** (core; the old draft's `cal_` prefix collides with calendar entries): `ccn_usr_user_id`, `ccn_kind` (`ics` / `caldav` / `google` / `microsoft`), `ccn_label`, `ccn_external_calendar_id`, `ccn_credentials` (SecretBox JSON: ICS URL, CalDAV login, or OAuth token), `ccn_read_busy`, `ccn_write_back`, `ccn_last_synced_time`, `ccn_status`, `ccn_status_detail`.
- **`CalendarBusyBlock` / `cbb_calendar_busy_blocks`** (core): `cbb_ccn_calendar_connection_id`, `cbb_start_time`, `cbb_end_time`, `cbb_source_key` (ICS UID or event id), `cbb_fetched_time`.
- **`ExternalCalendarItemSource`** (core): serves cached blocks with on-demand refresh, owner-visible titles where the account grants them, `busy` visibility otherwise.
- **OAuth.** Core already ships `GoogleOAuthProvider` and `MicrosoftOAuthProvider` in the provider catalog. A new `CalendarSyncOAuthConsumer` (purpose `calendar_sync`) is added in `includes/oauth/consumers/` and requests the calendar scope (`https://www.googleapis.com/auth/calendar` for Google, `Calendars.ReadWrite` plus `offline_access` for Microsoft) and stores the token on the connection. Unlike DNS publishing, which deliberately persists nothing, calendar sync *must* keep the refresh token.
- **Connect page:** `/profile/bookings/calendars` ships with the Bookings plugin (connections are consumed only through scheduling today; it moves to core if a second consumer appears).

---

## 7. External booking providers

### Calendly (migration first)

- **Auth for import: a personal access token** the host pastes into a connection field. That removes the need for a Calendly OAuth app per site for the migration case. Calendly's token path should be checked against its developer documentation before building (not read for this revision).
- **Import:** `listEventTypes()` → recreate each as a native type. Intake questions become Survey questions; the location type maps to the native location mode; scheduling rules (buffers, notice, window) map to the native fields.
- **Proxy (deferred, below):** needs OAuth, an embed, webhook subscriptions, and signature checks. Calendly's webhooks may be limited to paid plans (**verify**).

### Acuity

- API key and user id as connection fields (HTTP Basic). Import-only to start.
- Proxy (headless): availability and booking through the Acuity API; deferred.

### Cal.com (candidate, unverified)

Cal.com's closure makes its cloud users a plausible migration audience. Whether its API exposes event types in a way that can be imported was **not researched**. Add as an import source only after reading its current API docs.

### Why proxy is demoted

A proxy type keeps the host tethered to the service they are trying to leave, adds three provider code paths (OAuth, embed, webhooks), and needs reconciliation tasks to heal missed webhooks. The research gives no sign that anyone asks for it. Build proxy only when a paying customer asks for a specific provider.

---

## 8. Phases

Each phase lands working and tested before the next.

### Phase 0 — close the unknowns (small)

- Check the "verify" rows in §2 against the code, and fix the table.
- Confirm Google's current rules for calendar-scope verification and Testing-mode refresh-token expiry (§6 R3), and Calendly's personal-token and webhook availability (§7).
- Decide Q1 (tentative entries).

### Phase 1 — native gaps

N1 (landing page), then N4 (spam controls) and N5 (embed), which are small, then N2 (credits and tier gating), then N3 (pools and resources) as its own spec.

*Checkpoint, N2:* a user buys a 10-session pack, books three sessions, cancels one inside the notice window and one late; the balance reads 8.

### Phase 2 — migration import

`bpc_provider_connections`, the connections page, the Calendly provider (`listEventTypes`), the import step. Then Acuity.

*Checkpoint:* a real Calendly account connects with a token; its event types appear; importing one as native creates a working type with Calendly disconnected.

### Phase 3 — calendar sync

- **3a. Core plumbing:** `ccn_` and `cbb_` classes, `ExternalCalendarItemSource`, the busy cache, the live check at confirmation, "last synced".
- **3b. R1 (ICS feed):** the smallest route. *Checkpoint:* an event on a published feed suppresses the matching slot after a refresh; the feed fetch goes through `SafeHttpClient`.
- **3c. R3 (Google):** `CalendarSyncOAuthConsumer`, busy-read, then write-back and the echo dedup. *Checkpoints:* an event in Google suppresses a slot; a booking appears in Google and disappears on cancel; it shows once on the calendar.
- **3d. R2 (CalDAV).**
- **3e. R4 (Microsoft).**

The order above is a recommendation (Q2).

### Phase 4 — proxy (only on request)

Calendly embed and webhooks, Acuity headless.

---

## 9. Deletion strategy

- **CalendarConnection:** disconnect deletes cached busy blocks; events already written externally stay.
- **ProviderConnection:** disconnect deactivates the host's proxy types for that provider; migrated native types are unaffected; ingested bookings remain.
- **BookingCredit:** a user delete sets `bcr_usr_user_id` to the deleted-user value and keeps the ledger (it is a financial record).
- **User as host:** deletion also deletes their calendar and provider connections.

---

## 10. Files

**Create (core):** `data/calendar_connections_class.php`, `data/calendar_busy_blocks_class.php`, `includes/calendar/item_sources/ExternalCalendarItemSource.php`, `includes/oauth/consumers/CalendarSyncOAuthConsumer.php`, a small CalDAV client and an ICS busy-feed reader under `includes/calendar/`.

**Create (plugin):** `data/provider_connections_class.php`, `data/booking_credits_class.php`, `includes/scheduling_providers/CalendlySchedulingProvider.php`, `includes/scheduling_providers/AcuitySchedulingProvider.php`, `includes/fulfillment_providers/BookingCreditsFulfillment.php`, a `BookingCreditGrants` scheduled task, `views/book.php`-level landing view (`/book`), `views/embed` handling, profile views for connections and calendars.

**Modify:** `plugins/bookings/data/bookings_class.php` (`bkn_external_calendar_event_id`), `plugins/bookings/data/booking_types_class.php` (`bty_tier_min_level`, pool fields, `bty_captcha`, `bty_verify_email`), `plugins/bookings/logic/book_logic.php` (credit use inside the locked transaction), the booking confirmation email (ICS UID), `plugin.json` (menu items, settings), `plugins/bookings/docs/overview.md`.

---

## 11. Testing

Each is a test with an `@joinery-test` header.

- **Credits** (test-db): purchase grants N; a booking uses one inside the locked transaction; early cancel returns it; late cancel keeps it; a refund revokes only unspent credits and never drives the balance below zero; two simultaneous bookings with one credit left produce one booking.
- **Tier gating** (test-db): a gated type refuses a non-holder at the slots endpoint and at submission.
- **Pools** (test-db, when built): two simultaneous bookings for the last slot in a pool of two hosts go to different hosts; rotation is fair; the lock is taken on the chosen host only.
- **Abuse** (test-db): rate limits count IPv4 and IPv6 addresses as addresses; an unverified hold expires and frees the slot; a honeypot post is dropped.
- **Busy cache** (test-db): the busy projection includes cached external blocks; the live check at confirmation refuses a slot an external calendar has just taken (a stubbed provider); TTL staleness is shown.
- **Echo dedup** (test-db): a booking written back and read back appears once, by UID.
- **ICS feed** (safe): a private-address feed URL is refused; a redirect to a private address is refused.
- **Import** (test-db with recorded fixtures): a recorded Calendly event-type list becomes native types with the right duration, buffers and location.
- **Live walk:** book through an embed on a page outside the site; book with a credit; connect a real Google test calendar and watch a slot disappear.

---

## 12. Documentation

When each piece lands, fold it into `plugins/bookings/docs/` (an "integrations" page covering connect, import, and the four sync routes; a "credits and tiers" page). Current-state only, per the docs rules.

---

## 13. Decisions and open questions

- **Q1. Do tentative (AI-extracted) calendar entries block bookable time?** *Option A, keep blocking (recommended):* never double-books; the catch is a few lost slots until the host confirms or deletes the entry. *Option B, don't block:* more slots; the catch is a real chance of a double-booking on an entry that turns out to be real. Draft: A, with a per-host switch to B.
- **Q2. Which sync route first?** *R1 (ICS feed):* smallest and avoids Google entirely; the catch is staleness and read-only. *R3 (Google OAuth):* best behaviour for the largest base; the catch is per-site app setup and Google's verification rules. *R2 (CalDAV):* the best fit for the leaving-Google buyer; the catch is the biggest client to write. Recommendation: R1 first (days of work, immediate value, honest about staleness), then R3, then R2. If a managed-hosting tier is where most buyers land, R3's per-site burden largely disappears and R3 could go first.
- **Q3. Demote the Calendly and Acuity proxy modes to "on request"?** *Yes (recommended):* three code paths and a tether with no evidence of demand. *No:* a customer who wants to keep Calendly can't. Draft: yes.
- **Q4. How much of N2 goes in the first release?** Tier gating and package credits are the differentiator; tier allowance (monthly free sessions) is the part with the most moving pieces (a grant task, period rules). Recommend credits and gating first, allowance second, in the same release if cheap.

---

## Sources

- Calendly pricing (vendor): https://calendly.com/pricing
- Cal.com pricing (vendor): https://cal.com/pricing
- Cal.com repository, now the Cal.diy readme: https://github.com/calcom/cal.com
- SimplyBook.me pricing (vendor): https://www.simplybook.me/en/pricing
- Cal.com going private (secondary): https://www.implicator.ai/cal-com-goes-private-as-self-hosted-calendly-choices-narrow-in-2026/, https://pinggy.io/blog/self_hosted_calendly_alternatives/, https://thenewstack.io/cal.com-codebase-security-ai/
- Capterra and G2, Calendly reviews: https://www.capterra.com/p/148036/Calendly/reviews/, https://www.g2.com/products/calendly/reviews?qs=pros-and-cons
- TechRadar, Squarespace Acuity: https://www.techradar.com/pro/website-building/squarespace-acuity-review-year
- Acuity pricing (third-party): https://pabau.com/blog/acuity-scheduling-price/, https://capterra.com/p/191978/Acuity-Scheduling/pricing/
- Setmore / Zoho comparison (vendor): https://www.zoho.com/bookings/explore/setmore-pricing.html
- TidyCal comparison (vendor): https://tidycal.com/compare/calendly-alternatives
- Vertical tools: https://www.fresha.com/for-business/salon/best-salon-software, https://pabau.com/blog/vagaro-vs-booksy/, https://www.softwareadvice.com/appointment-scheduling/jane-profile, https://schedulingkit.com/pricing-guides/jane-app-pricing, https://schedulingkit.com/pricing-guides/square-appointments-pricing, https://capterra.com/p/170263/Square-Appointments/pricing/
- WordPress plugins: https://www.booking-wp-plugin.com/blog/bookly-vs-amelia/, https://www.fs-code.com/blog/amelia-review
- Easy!Appointments: https://ideaproof.io/open-source/project/easy-appointments
- Google appointment schedules and paid schedules: https://support.google.com/a/answer/13765946, https://knowledge.workspace.google.com/admin/calendar/allow-paid-appointment-schedules-in-calendar
- AI scheduling trend (vendor press): https://www.destinationcrm.com/Articles/CRM-News/CRM-Across-the-Wire/CallRail-Integrates-Voice-Assist-with-Calendly-173406.aspx, https://www.schedulingkit.com/blog/ai-scheduling-trends-2026
- Double-booking and range constraints (engineering blogs, not PostgreSQL docs): https://supabase.com/blog/range-columns.md, https://betterstack.com/community/guides/databases/postgres-temporal-constraints/, https://techinterview.org/lld-calendar-app/
