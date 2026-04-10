# Combined Most Important Features

These are the features that surfaced as high-priority gaps across all three platform analyses (Ghost, Patreon, Calendly). Because they appear in multiple contexts, they should be treated as platform-level investments — implementing them moves the needle on all three comparisons simultaneously rather than serving just one use case.

**Source specs:**
- [`ghost_feature_ideas.md`](ghost_feature_ideas.md)
- [`patreon_feature_ideas.md`](patreon_feature_ideas.md)
- [`calendly_feature_ideas.md`](calendly_feature_ideas.md)

---

## 1. Per-Post / Per-Content Tier Gating — IMPLEMENTED

**Appears in:** Ghost (gap #1), Patreon (gap #1)

**Status:** Implemented. See `specs/implemented/tier_gating.md` and `docs/subscription_tiers.md` (Tier Gating section).

**What was built:**
- `{prefix}_tier_min_level` field on posts, pages, events, files, videos, products, mailing lists, page contents, and event sessions.
- `authenticate_tier()` method on SystemBase with admin bypass, early access timer support, and structured return data for the view layer.
- `{prefix}_tier_public_after_hours` field on posts, pages, and events for time-delayed public release (Patreon's "early access" pattern).
- Tier gate prompt component (`includes/tier_gate_prompt.php`) showing upgrade CTAs or login/signup for non-authenticated users.
- Configurable preview length (`tier_gate_preview_length` setting) for showing N characters before the paywall.
- Lock indicators in blog listings, RSS feed exclusion of gated content, and `tier_gate_hide_from_listings` setting.
- Admin UI on all entity edit pages with tier dropdown and early access presets.
- Gated content counts on the subscription tiers admin page.

---

## 2. Outgoing Webhooks

**Appears in:** Ghost (gap #24), Patreon (gap #31), Calendly (gap implied throughout integration discussion)

**What it is:** When something happens inside Joinery (a member joins, a post is published, a booking is made, an order completes), Joinery fires an HTTP POST containing a JSON payload to one or more operator-configured URLs. External systems (Zapier, Make, custom apps, Discord bots, CRMs) receive these notifications in real time and react without polling.

**Why it matters across platforms:**
- Ghost needs them for post.published and member events so operators can trigger external email tools, CDN cache purges, and social sharing automation.
- Patreon needs them for patron tier changes so operators can sync Discord roles, update CRM records, and trigger retention workflows.
- Calendly needs them for booking.created and booking.cancelled so operators can push appointments into external calendars, notify teammates via Slack, or update CRM pipelines.
- More broadly: outgoing webhooks are what turn Joinery from a silo into a platform. Any integration Joinery doesn't build natively can be wired up by an operator via Zapier or a custom script — without any Joinery code change.

**Event types to support (minimum viable set):**

| Event | Triggered when |
|---|---|
| `member.created` | New user registers |
| `member.tier_changed` | Subscription tier upgrades, downgrades, or cancels |
| `member.deleted` | User account deleted |
| `post.published` | Post status changes to published |
| `order.completed` | Payment succeeds for any order |
| `event.registration_created` | User registers for an event |
| `event.registration_cancelled` | User withdraws from an event |
| `booking.created` | Appointment booking confirmed |
| `booking.cancelled` | Appointment booking cancelled |

**What Joinery needs:**
- A `webhook_subscriptions` table: `whs_id`, `whs_url`, `whs_events` (array of event names to fire on), `whs_secret` (for HMAC signature verification), `whs_is_active`, `whs_created_time`.
- A `webhook_delivery_log` table: `whl_id`, `whl_whs_id`, `whl_event`, `whl_payload`, `whl_response_code`, `whl_response_body`, `whl_delivered_time`, `whl_is_success` — so operators can see what was sent and diagnose failures.
- A dispatch function called from within the relevant save/logic flows (e.g. inside `save()` on Post when `pst_is_published` flips to true, inside the tier change flow, inside order completion).
- An admin UI to register, test, and view delivery history for webhook endpoints.
- HMAC-SHA256 signature on each delivery (`X-Joinery-Signature` header) so receiving endpoints can verify the payload is genuine.
- A retry mechanism for failed deliveries (non-2xx responses): retry with exponential backoff, up to a configurable limit.

**Note:** Joinery already logs *inbound* webhooks from Stripe, PayPal, and Mailgun. Outgoing webhooks are the reverse direction and require new infrastructure.

---

## 3. Automated Email Workflows (Trigger-Based)

**Appears in:** Ghost (gaps #2 email-only posts, audience segmentation), Patreon (gaps #16 bulk messaging, #10 personalization), Calendly (gaps #13 pre-event reminders, #14 post-event follow-up, #18 workflow engine)

**What it is:** A configurable trigger-action system where an email (or sequence of emails) is sent automatically when a specific event occurs, at a configurable time offset relative to that event. Examples: send a welcome email 0 minutes after joining, a check-in email 3 days after joining, a pre-event reminder 24 hours before a booking, a follow-up survey 1 hour after an event ends.

**Why it matters across platforms:**
- Ghost operators need post-publish distribution emails and subscriber onboarding sequences.
- Patreon operators need welcome emails on join, upgrade/downgrade notifications, and win-back emails on cancellation.
- Calendly operators need pre-event reminders to reduce no-shows, and post-event follow-ups for feedback and rebooking.
- Currently, all of these require manual admin action (exporting emails and sending outside the platform). Automating them removes ongoing operational overhead.

**What Joinery needs:**

**Data model:**
- `email_workflows` table: `ewf_id`, `ewf_name`, `ewf_trigger` (enum: `member.created`, `member.tier_changed`, `order.completed`, `event.registration_created`, `event.start`, `event.end`, `booking.created`, `booking.cancelled`), `ewf_offset_minutes` (negative = before trigger time, positive = after), `ewf_is_active`, `ewf_filter_json` (optional conditions, e.g. "only for tier X").
- `email_workflow_steps` table (for sequences): `ews_id`, `ews_ewf_id`, `ews_offset_minutes`, `ews_email_template_id`.
- `email_workflow_sends` table: tracks which user × workflow step has been sent, to prevent duplicates.

**Trigger integration:**
- Workflow triggers share the same dispatch points as outgoing webhooks (gap #2) — the same hook that fires a webhook event can also enqueue workflow emails. These two features are naturally built together.

**Template system:**
- Reuse the existing `email_templates` system. Workflow emails reference a template by ID.
- Add merge tag support in templates: `{{first_name}}`, `{{event_name}}`, `{{event_time}}`, `{{tier_name}}`, `{{cancel_link}}`, `{{reschedule_link}}`.

**Execution:**
- A scheduled task (already used elsewhere in Joinery) runs on a short interval (e.g. every 5 minutes), queries `email_workflow_steps` where the calculated fire time (`trigger_time + offset_minutes`) is in the past and not yet sent, and dispatches via `SystemMailer` (which now uses the pluggable `EmailProviderInterface` supporting Mailgun, PHPMailer, and SMTP providers — see `specs/implemented/email_provider_abstraction.md`).

**Admin UI:**
- A workflow builder: choose trigger, add one or more steps (time offset + template), set optional filter conditions, preview, activate/deactivate.

**Minimum viable implementation:**
- Single-step workflows only (no sequences) covering the highest-value triggers: `member.created` (welcome email), `event.start` (pre-event reminder at configurable offset), `event.end` (post-event follow-up), `order.completed` (receipt/thank-you).
- Sequences and conditional branching can be added later.

---

## 4. Analytics Dashboards (Revenue & Engagement)

**Appears in:** Ghost (gaps #14 per-post analytics, #15 subscriber growth), Patreon (gaps #10 MRR dashboard, #11 churn, #12 per-post performance, #13 lifetime value), Calendly (gap #25 booking analytics)

**What it is:** Aggregated, time-series dashboards that give operators visibility into the health of their platform: revenue trends, member growth and churn, content engagement, and event/booking conversion.

**Why it matters across platforms:**
- All three platforms centre around a business model (subscriptions, content monetization, appointment booking) that only works if operators can see what's working and what's not.
- Joinery already collects the underlying data (orders, tier changes, registrations, session views) but does not aggregate or visualise it meaningfully.

**What Joinery needs (in order of value):**

**Revenue dashboard (Patreon parity):**
- Monthly Recurring Revenue (MRR): sum of active monthly subscription prices for the current period.
- MRR trend: MRR over the last 12 months as a line chart.
- New MRR / churned MRR / net MRR change per month.
- All data derivable from existing `orders`, `order_items`, and `product_versions` tables — no new data collection needed, only aggregation queries.

**Member growth and churn (Patreon/Ghost parity):**
- Total active members over time (line chart).
- New members per month, cancelled per month, net change.
- Churn rate: cancellations ÷ active members at start of period.
- Derivable from existing `change_tracking` and user creation timestamps.

**Per-post performance (Ghost/Patreon parity):**
- View count, unique visitor count, comment count, reaction count per post.
- Requires tagging `visitor_events` with `pst_post_id` at tracking time (a small change to the analytics tracking code).

**Booking / event analytics (Calendly parity):**
- Registration count, cancellation count, no-show count, waitlist count per event and per event type.
- Conversion rate: visitors to event page ÷ registrations.
- Derivable from existing `event_registrants` and `session_analytics` tables.

**Implementation notes:**
- Chart.js is already used in Joinery's admin analytics pages. New dashboards follow the same pattern.
- Most of the data already exists; this is primarily new SQL aggregation queries and new admin view templates.
- MRR and churn are the highest-value additions and the most directly actionable by operators.

---

## Priority Order

If implementing these sequentially, the recommended order is:

| # | Feature | Rationale |
|---|---|---|
| 1 | Per-post tier gating | Highest user-facing impact; makes the subscription system meaningful |
| 2 | Automated email workflows | High operational value; reduces manual work for every operator |
| 3 | Revenue analytics (MRR/churn) | Data already exists; relatively low implementation cost for high insight value |
| 4 | Outgoing webhooks | Unlocks third-party integrations; best built alongside the workflow trigger infrastructure |
| 5 | Per-post / per-event analytics | Requires a small data collection change but is otherwise additive |

Note that **outgoing webhooks and automated email workflows share the same trigger dispatch infrastructure** — the point in the code where a webhook fires is the same point where a workflow email is enqueued. Building them together is more efficient than building them separately.
