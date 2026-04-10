# Patreon Feature Parity Ideas

This document captures features from the Patreon creator membership platform that Joinery does not yet have, organized by priority. These are ideas for consideration, not committed roadmap items.

**Reference:** Patreon (patreon.com) is a creator membership platform focused on subscription-based fan support, exclusive content, digital product sales, and creator-patron community.

---

## Already Present in Joinery

For reference, these Patreon features already exist in Joinery:

- Multiple subscription tiers with custom names, descriptions, and JSONB feature lists
- Yearly billing cycle option (via product versions)
- One-time payments and donation products
- Digital product delivery (file links, post-purchase messages)
- Physical goods address capture
- Member management with group-based organization
- User import and export
- 1-to-1 and group direct messaging with block system enforcement
- Stripe and PayPal checkout and subscription processing
- Coupon/discount codes with usage tracking
- Comments on posts (threaded, with approval workflow and anti-spam)
- Polls and multi-type surveys
- Reactions (like/favorite/bookmark)
- In-app notifications
- Email automation and queued email delivery
- Events and event registrations
- Trial period field on product versions
- API key management
- Change tracking / audit log for tier changes

---

## Gaps — High Priority (Core Creator Monetization)

### 1. ~~Per-Post Tier Gating (Inline Paywall)~~ — IMPLEMENTED
**Patreon:** Each post can be set to public, patron-only, or specific tier(s). Non-members see a paywall prompt mid-content ("draggable paywall") showing a preview before the lock.
**Joinery:** Implemented via `tier_min_level` fields on all content entities (posts, pages, events, files, videos, products, mailing lists, page contents, event sessions). The `authenticate_tier()` method enforces access, with a configurable preview length before the paywall and a tier gate prompt component showing upgrade CTAs. See `specs/implemented/tier_gating.md`.

---

### 2. ~~Early Access Content~~ — IMPLEMENTED
**Patreon:** Posts can be set as patron-only initially, then flipped to public after a specified date (e.g. patrons get content 7 days early).
**Joinery:** Implemented via `{prefix}_tier_public_after_hours` field on posts, pages, and events. The `authenticate_tier()` method computes `publish_time + delay_hours` and automatically lifts the gate when the delay elapses — no scheduled task needed. Admin UI provides presets from 1 hour to 90 days. See `specs/implemented/tier_gating.md`.

---

### 3. Patron Spot Limits Per Tier
**Patreon:** Creators can cap the maximum number of patrons in a tier to create scarcity (e.g. "only 50 spots at this level").
**Joinery:** No visible member cap on tiers.
**Notes:** Requires a `sbt_max_members` field on `subscription_tiers`. The join/upgrade flow must check current count against the cap and block or waitlist when full.

---

### 4. Charge-Upfront Billing Option
**Patreon:** Creators can enable charge-upfront billing, where new patrons pay immediately on joining (not just on the 1st of next month). Without this, a patron can join, consume content, and cancel before the billing date for free.
**Joinery:** Billing timing behavior is not explicitly configurable; depends on Stripe subscription setup.
**Notes:** In Stripe this is controlled by `payment_behavior` and `proration_behavior` on subscription creation. Needs an admin setting and corresponding checkout session configuration.

---

### 5. Gift Memberships
**Patreon:** Creators can gift memberships to fans (1–12 months), and fans can gift memberships to other fans. Gift links allow bulk gifting. Recipients have a 90-day redemption window.
**Joinery:** No gift membership mechanism found.
**Notes:** Requires a gift voucher/redemption system: generate a time-limited token tied to a tier + duration, send it by email or link, and redeem it to activate a membership without payment.

---

### 6. Free Trial Memberships
**Patreon:** Creators can offer a 7-day free trial on any tier; each patron gets one trial per creator per lifetime.
**Joinery:** `prv_trial_period_days` field exists but no trial-to-paid conversion logic or one-per-patron enforcement is visible.
**Notes:** Stripe Checkout supports `trial_period_days` natively. Needs a per-user-per-tier record tracking whether a trial was used, plus admin UI to enable trials on specific tiers.

---

### 7. Tier Repricing with Grandfathering Control
**Patreon:** Creators can change a tier's price and choose whether to: (a) grandfather existing members at the old price, or (b) migrate all members to the new price with advance notice.
**Joinery:** Prices are on `product_versions` which can be edited, but no grandfathering or member notification workflow exists.
**Notes:** Requires storing the price at which each subscription was initiated, a repricing workflow with admin controls, and automated member notification emails.

---

### 8. Benefits Fulfillment Tracker
**Patreon:** A dedicated view shows creators each benefit they've promised, which patrons are owed it, and whether it has been marked as delivered.
**Joinery:** The product requirements system exists for order-based benefits, but no fulfillment tracker UI for recurring tier benefits.
**Notes:** Requires a data model for recurring benefit delivery (e.g. "monthly download" delivered/not per member per month) and an admin UI to mark fulfillment.

---

### 9. Annual vs. Monthly Pricing Display
**Patreon:** Tiers prominently show both monthly and annual pricing, with the annual discount highlighted to nudge patrons toward longer commitments.
**Joinery:** Yearly product versions exist but there is no UI pattern for displaying both options side-by-side with a savings callout.
**Notes:** Primarily a theme/view change, but requires the tier display component to look up and render both price points for each tier.

---

## Gaps — High Priority (Analytics & Revenue Intelligence)

### 10. Monthly Recurring Revenue (MRR) Dashboard
**Patreon:** Creators see MRR and ARR at a glance, broken down by tier, with trend over time.
**Joinery:** No revenue metrics dashboard. Analytics track user activity but not subscription revenue.
**Notes:** MRR can be computed from active subscriptions × their monthly price. Requires a revenue aggregation query and a chart on the analytics dashboard. The underlying data (subscriptions, prices) already exists.

---

### 11. Churn Rate and Retention Analytics
**Patreon:** Shows monthly churn rate, patron count trend, new vs. lost patrons over time.
**Joinery:** Change tracking logs tier changes but no aggregated churn/retention report exists.
**Notes:** Requires queries over `change_tracking` records (or a dedicated subscription event log) to compute cohort retention and monthly churn.

---

### 12. Per-Post Performance Analytics
**Patreon:** Each post shows view count, comment count, patron reaction count, and for newsletters: open rate and click rate.
**Joinery:** Visitor events are tracked but not associated with specific posts; no per-post stats UI.
**Notes:** Requires tagging `visitor_events` records with a `pst_post_id` and building a per-post summary view in admin.

---

### 13. Patron Lifetime Value
**Patreon:** The Relationship Manager shows each patron's total lifetime spend, making it easy to identify and prioritize high-value members.
**Joinery:** Order history exists per user but no lifetime spend aggregation or prominent display.
**Notes:** A `SUM(order total)` per user query, surfaced on the admin user detail page and in a patron list view.

---

## Gaps — Medium Priority (Patron & Community Management)

### 14. Patron Relationship Manager
**Patreon:** A dedicated patron management view showing each member's tier, status, join date, lifetime spend, and labels — with search, filter, and bulk action support.
**Joinery:** Admin user pages exist, but they're not creator-oriented. No combined view of tier + payment history + labels per member.
**Notes:** Essentially a specialized admin list view that joins users, their current tier, subscription status, and total spend. Requires no new data models — only a new query and view.

---

### 15. Patron Labels / Tags
**Patreon:** Creators can apply custom labels to patrons (e.g. "VIP", "Contest Winner", "Early Supporter") for segmentation and targeted messaging.
**Joinery:** Groups exist but are used for tiers and system purposes; no lightweight user-tagging for creator-defined labels.
**Notes:** Could reuse the groups system with a new `grp_category = 'patron_label'`, or implement a simpler user-tag table. Needs an add/remove label UI on the patron detail page.

---

### 16. Bulk Messaging to a Tier
**Patreon:** Creators can send a direct message to all patrons in a specific tier or all patrons at once.
**Joinery:** Mailing lists and email broadcasts exist; direct messaging is 1-to-1 or existing group conversations. No "broadcast a DM to all Tier X members" workflow.
**Notes:** Could be implemented as a bulk conversation creator: open individual conversations with each tier member from a single admin action, or send via the email system with a clear "message from creator" template.

---

### 17. Community Chat Spaces
**Patreon:** Creators get up to 10 named chat channels, which can be gated by tier. Members can post text and photos in real time.
**Joinery:** Conversations are 1-to-1 or small groups; no channel-based community chat exists.
**Notes:** A significant feature. Would require a new chat room data model (separate from personal conversations), WebSocket or polling for real-time updates, and tier-based access control per channel.

---

### 18. Discord Role Sync
**Patreon:** Patrons connecting their Discord account get a server role automatically assigned based on their tier. Role is revoked on cancellation.
**Joinery:** Only a Discord link in social settings. No OAuth, role assignment, or sync.
**Notes:** Requires Discord OAuth integration, a mapping table (Joinery tier → Discord role ID), and webhook-triggered role grant/revoke calls to the Discord API on tier change events.

---

### 19. Patron-Controlled Notification Preferences
**Patreon:** Patrons can choose which types of creator activity to be notified about (new posts, messages, tier changes).
**Joinery:** Notifications exist but user-configurable preferences for notification types are not visible.
**Notes:** Requires a notification preferences table (user × notification type × on/off) and UI for patrons to manage their preferences.

---

## Gaps — Medium Priority (Content & Publishing)

### 20. Post Collections / Grouping
**Patreon:** Creators can organize posts into named collections (e.g. "Season 1 Episodes", "Tutorial Series") that appear as browsable shelves on their page.
**Joinery:** Posts exist as a flat list. No grouping or collection concept beyond tags.
**Notes:** Requires a `collections` data model, a many-to-many join to posts, and views for browsing a collection.

---

### 21. Post Bulk Editing
**Patreon:** Creators can select multiple posts and change their access tier, publish status, or tags in a single action.
**Joinery:** No bulk post editing UI visible.
**Notes:** Standard admin bulk-action pattern — checkboxes on the post list, a bulk action dropdown, and a confirmation step.

---

### 22. Native Video Hosting
**Patreon:** Creators can upload video files directly to Patreon, which handles transcoding and adaptive streaming.
**Joinery:** The file system supports uploads but no video transcoding or streaming infrastructure exists.
**Notes:** Significant infrastructure requirement. A lightweight alternative is embedding external video (YouTube/Vimeo) per post, with a structured field for video URL rather than a file upload.

---

### 23. Audio / Podcast Posts with RSS
**Patreon:** Creators can publish audio posts and provide a private RSS feed URL to patrons, usable in any podcast app (Apple Podcasts, Spotify, etc.). Each tier can have its own RSS feed.
**Joinery:** The existing RSS feed covers blog posts; no audio post type or per-tier private RSS feed exists.
**Notes:** Requires an audio post type (with a file attachment), a per-patron private RSS key, and a feed generator that filters by tier and includes enclosure elements pointing to audio files.

---

### 24. Draggable / Inline Paywall
**Patreon:** On public post previews, the paywall can be inserted inline — the post body is visible up to a certain point, then a subscribe-to-read prompt appears mid-content.
**Joinery:** If per-post gating is added (gap #1), the initial implementation would likely show nothing or a brief excerpt to non-members. An inline paywall is the polished UX version.
**Notes:** Depends on gap #1. Requires a configurable preview length and a styled paywall insertion component in the post view template.

---

## Gaps — Medium Priority (Creator Page & Discoverability)

### 25. Rich Creator Profile Page
**Patreon:** The creator page has: a large header/cover image, brand color, featured content shelf, about section, tier display with benefits, shop shelf, and customizable navigation links.
**Joinery:** User profiles exist with basic fields. No dedicated creator landing page with customizable sections or branding.
**Notes:** Distinct from a user profile — this is a storefront. Requires a creator page view with editable sections (header, about, featured post, tier cards) and admin controls to configure each section.

---

### 26. Featured Content on Creator Page
**Patreon:** Creators can pin specific posts, collections, or products to the top of their page in a "featured" shelf.
**Joinery:** `pst_is_pinned` and `pst_is_on_homepage` exist on posts, but no dedicated featured shelf component on a creator profile page.
**Notes:** A view component that queries pinned/featured posts and renders them prominently. Low complexity once a creator page exists.

---

### 27. Creator Discovery / Explore
**Patreon:** Platform-level explore/search lets fans find creators by category and interest. Discovery drives $200M+ annually to creators.
**Joinery:** No platform-level creator discovery (Joinery is single-site, not a marketplace). This gap is structural — Joinery is a white-label platform, not a network.
**Notes:** Not directly applicable to a single-tenant deployment, but relevant if Joinery were to offer a multi-tenant marketplace mode. Flag for future consideration only.

---

## Gaps — Lower Priority (Governance & Special Features)

### 28. Content Warnings and Age Gating
**Patreon:** Creators can flag content as adult/18+ and Patreon enforces age verification. Individual posts can have content warnings.
**Joinery:** No content warning field or age verification mechanism on posts.
**Notes:** Requires a `pst_content_warning` field (text), an `pst_is_age_restricted` boolean, and a view-layer gate (show warning, require acknowledgment or age check before displaying content).

---

### 29. Patron Blocking / Removal
**Patreon:** Creators can block specific patrons, cancelling their membership and preventing them from rejoining.
**Joinery:** User blocks exist in the messaging context but no creator-initiated patron block that also cancels the subscription.
**Notes:** Requires hooking into the user block system to also cancel any active subscription via the Stripe or PayPal API when a patron is blocked.

---

### 30. Special Offers / Limited-Time Promotions
**Patreon:** Creators can run time-limited promotional offers (discounted first month, bonus access, etc.) with a configurable start and end date.
**Joinery:** Coupon codes exist with expiry, but no "special offer" concept attached to a tier — visible to prospective patrons as a call-to-action.
**Notes:** Combines the coupon system with a UI that displays the offer prominently on the tier card during the active promotion window.

---

### 31. Outgoing Webhooks for Patron Events
**Patreon:** Operators can register webhook URLs to fire on `member.create`, `member.update`, `member.delete` events — enabling Zapier/n8n integrations.
**Joinery:** Only inbound webhooks exist (Stripe, PayPal, Mailgun). No outgoing event dispatch.
**Notes:** Also listed in `ghost_feature_ideas.md`. Requires an outgoing webhook registry (URL + events subscribed) and a dispatch call in the tier change, subscription, and registration flows.

---

### 32. Mobile App / PWA
**Patreon:** Native iOS and Android apps for both creators (dashboard, messaging) and patrons (content consumption, community).
**Joinery:** A `manifest.json` is present in the scrolldaddy plugin but no service worker or full PWA support. No native app.
**Notes:** Full native apps are significant scope. A Progressive Web App (service worker, offline support, add-to-home-screen) is a more achievable intermediate step.

---

## Summary of Biggest Gaps

| # | Feature | Impact | Complexity |
|---|---|---|---|
| 1 | Per-post tier gating with inline paywall | High | Medium |
| 10 | MRR / ARR revenue dashboard | High | Low |
| 14 | Patron Relationship Manager view | High | Low |
| 5 | Gift memberships | High | Medium |
| 4 | Charge-upfront billing option | High | Medium |
| 6 | Free trial memberships (with enforcement) | High | Low |
| 3 | Patron spot limits per tier | Medium | Low |
| 11 | Churn rate and retention analytics | Medium | Low |
| 12 | Per-post performance analytics | Medium | Low |
| 13 | Patron lifetime value display | Medium | Low |
| 15 | Patron labels / tags | Medium | Low |
| 16 | Bulk messaging to a tier | Medium | Medium |
| 17 | Community chat spaces | Medium | High |
| 18 | Discord role sync | Medium | High |
| 2 | Early access / timed public release | Medium | Low |
| 7 | Tier repricing with grandfathering | Medium | Medium |
| 8 | Benefits fulfillment tracker | Medium | Medium |
| 9 | Annual vs. monthly price display | Medium | Low |
| 20 | Post collections / grouping | Medium | Medium |
| 25 | Rich creator profile/storefront page | Medium | High |
| 23 | Audio posts with private RSS feeds | Medium | Medium |
| 29 | Patron block + subscription cancel | Low | Low |
| 28 | Content warnings and age gating | Low | Low |
| 31 | Outgoing webhooks for patron events | Low | Medium |
| 22 | Native video hosting | Low | High |
| 32 | PWA / mobile app | Low | High |
