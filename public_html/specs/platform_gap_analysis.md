# Platform Spec Gap Analysis

**Status:** Analysis / planning document (not a single-feature implementation spec)
**Created:** 2026-06-11
**Purpose:** Inventory what the spec corpus is *missing* relative to where the platform is heading, so that any agent can pick up the highest-leverage work without re-doing the research. Each gap below either needs a new spec written or names a spec that should be sequenced/hardened before its dependents.

---

## How to use this document

This is a meta-spec. It does **not** itself implement anything. It does three jobs:

1. Records the strategic direction and current implemented baseline (so the gaps are interpretable).
2. Identifies capabilities the direction *implies* but that no spec covers — organized into four tiers by leverage.
3. Recommends a build/spec order and lists the items that are already unblocked and ready.

When you act on a gap, the next step is almost always **write the missing spec into `public_html/specs/`** (full implementation spec, following the conventions of existing specs in that directory), then implement. Move completed specs to `public_html/specs/implemented/` per CLAUDE.md.

This analysis was produced 2026-06-11 by reading all 38 active specs, the strategic docs, the implemented-spec set, and the live codebase. Spec file references below are relative to `public_html/specs/` unless noted.

---

## 1. Strategic context (the lens for every gap)

**The pivot.** `getjoinery_developer_pivot.md` (Draft, 2026-05-04) reframes Joinery from a hosted SaaS membership platform into a **source-available PHP application framework for developers** — solo devs, hobbyists, freelancers, "vibe coders" who want production-ready scaffolding (auth, payments, email, REST API, admin dashboard, plugin system) they can read and modify. Positioning: *"Skip the boilerplate. Build what matters."* The framework runs in production as ScrollDaddy (a DNS-filtering product), which is treated as **one instance of the platform, not the target**.

**Pricing model (finalized in the pivot doc):**
- Free — PolyForm Noncommercial license (personal/educational/nonprofit).
- White Glove Install — $99 (coupon `INSTALL`; base $299) one-time; installs Apache2/PHP/PostgreSQL/Joinery/SSL.
- Business License — $499 one-time (Elastic License 2.0 / ELv2) for commercial self-hosting.
- Go-live is **blocked**: Stripe keys not configured, White Glove product not created, Business License purchase flow not built, CTAs not wired.

**The core tension that produces most gaps:** the strategy is now a *developer framework*, but the active spec corpus is dominated by **product verticals** (dating, chat, crush-match, cold-email) and **ScrollDaddy-specific** work. The two halves barely reference each other. The platform-level abstractions those products share are the real targets, and several are unspecced.

**Stated top-5 "most important features"** (`combined_most_important_features.md`), in the team's own priority order:
1. Per-post tier gating — ✅ implemented.
2. Automated email workflows — not started.
3. Revenue analytics (MRR/churn) — not started.
4. Outgoing webhooks — not started.
5. Per-post/event analytics — not started.

**Recent git direction (last ~40 commits):** heavily weighted toward email infrastructure (inbound IMAP sync, two-way mail, reply/forward, OAuth2 XOAUTH2 SMTP relay, connected accounts, auth-verdict reading), the developer-facing API layer (FormWriterV2JSON renderer, per-user session API keys), and scheduled-task refactoring. This aligns with the developer-first pivot.

---

## 2. Implemented baseline (what already exists — don't re-spec it)

**Plugins:** Bookings Management, DNS Filtering (ScrollDaddy), Inbound Email (Postfix/Mailgun/SendGrid/IMAP, DKIM/SRS, local mailboxes), Items Management, Joinery AI (scheduled LLM recipes), Server Manager (remote nodes, upgrades, marketplace, backups, provisioning).

**Platform capabilities present (selected, from `includes/`, `data/`, `docs/`):**
- Auth/session: SessionControl, PasswordHash, Activation, remember-me tokens, TOTP 2FA, SecretBox, per-user API keys.
- Commerce: Product/Order/Coupon/SubscriptionTier, ShoppingCart, StripeHelper, PaypalHelper, receipts.
- Events: Event/EventType/EventSession/EventRegistrant/WaitingList, recurring events, iCal/ICS.
- Email: full provider abstraction (Postmark, Resend, SendGrid, Brevo, Mailjet, Amazon SES, Mailgun, PHPMailer), templates, recurring mailers, inbound IMAP/forwarding, OAuth2 SMTP relay, **notification hooks system (implemented)**.
- Content/forms: Pages, Posts, Components + ComponentRenderer, FormWriter + FormWriterV2 (HTML5/Bootstrap/Tailwind/JSON/Base), Questions/Surveys, Validator.
- Social primitives: Group/GroupMember, Conversation/Message, Comment, Reaction, VisitorEvent.
- Infra: Server Manager, DNS filtering, scheduled tasks (cron runner), cloud storage (S3-compatible driver), multi-domain capability, A/B testing framework, OAuth2 core + provider catalog, analytics/session tracking, deletion system, plugin/theme marketplace + upgrade pipeline.
- AI: Joinery AI — `rcp_recipes` / `rcr_recipe_runs` / `recn_recipe_notes` tables and data classes exist; recipe **data layer ~60% built**.

**Logic-descriptor refactor:** `implemented/logic_code_refactor.md` Steps 1–5 landed 2026-05-06. ~19 `_logic_descriptor()` functions live. `apiv1.php` already has action discovery (`GET /api/v1/actions`) and invocation (`POST /api/v1/action/{name}`). This unblocks the two FUTURE descriptor specs (see §6).

---

## 3. GAP TIER 1 — Named top priorities with NO spec

The team's own stated top-5 features (`combined_most_important_features.md`): #1 is built, **#2–#5 have no implementation spec at all.** These are the most defensible specs to write because the priority is already endorsed.

| # | Feature | Notes for the spec author |
|---|---------|---------------------------|
| 2 | **Automated email workflows** | Trigger-based sequences: `member.created → welcome`, `event.start −24h → reminder`, `order.completed → receipt`. MVP can be single-step; branching/multi-step deferred. **Shares the trigger-dispatch substrate with #4 — spec them against the same bus (Tier 2 #2).** Existing notification-hooks system is the closest prior art; check whether it can be the dispatch layer. |
| 3 | **Revenue analytics dashboard** | MRR, member growth, churn — derivable from existing `orders` + `change_tracking`. Admin already uses Chart.js. Per-post/per-event metrics need a small tracking-data addition. Low implementation cost, high insight; data already exists. |
| 4 | **Outgoing webhooks** | `webhook_subscriptions` + `webhook_delivery_log` tables, HMAC-SHA256 signing, retry w/ exponential backoff. Consumers: Zapier/Make, post-publish distribution, patron tier-change reactions, pre/post-event automation. Note `WebhookLog` model already exists — confirm scope vs. existing. |
| 5 | **Per-post/event analytics** | Requires a small data-collection change, then additive. Build after #3's dashboard shell exists. |

---

## 4. GAP TIER 2 — Cross-cutting platform capabilities each product re-invents

This is the clearest violation of the team principle *"inventory all integration points up front and decide once."* Multiple active specs each define their own copy of the same primitive. Each item below should become **one platform spec** consumed by all listed dependents.

1. **Push notification platform.** `scrolldaddy_ios_app.md`, `scrolldaddy_android_app.md`, `chat_plugin.md`, and `crush_match_plugin.md` *each* define their own push delivery (PWA web push, APNs, FCM). No shared spec exists. Spec one capability: token registry, web push + APNs + FCM transports, delivery + retry, per-user/per-device targeting. **Highest fan-out: unblocks 2 apps + 2 plugins.**

2. **Event / trigger dispatch bus.** Email workflows (Tier 1 #2), outgoing webhooks (Tier 1 #4), the implemented notification-hooks system, and patron tier-change reactions all need the same "something happened → fan out to subscribers" substrate. Specced nowhere as a unit. Decide whether the notification-hooks system generalizes into this bus or whether a new event layer sits beneath all three. **This is the single most foundational missing spec — two of the top-5 block on it.**

3. **Core block + report systems.** `dating_platform_spec.md` carries these as "remaining core work," but they are platform primitives needed by chat, social feed, messaging, and any UGC vertical. Pull them out of the dating spec into their own core spec (soft-block relationship table, report queue + admin moderation surface, enforcement hooks in messaging/feed/discovery).

4. **DNS-plugin mobile API surface + `sbr_hard_block` flag.** ✅ **Implemented — see `implemented/plugin_api_actions.md` (specced and implemented 2026-06-11).** Correction to the original claim here: the surface *was* specced (in `scrolldaddy_ios_app.md` § Server-side work item 2), just not implemented and not standalone. The standalone spec extracted and expanded it into two phases — a plugin-aware core action resolver (`{plugin}/{action}` namespacing; the piece the iOS spec glossed over) and the full ScrollDaddy action surface. Both phases have landed; the apps' API dependency is no longer a blocker.

5. **App distribution / release automation.** TestFlight/App Store + Play Store submission, code signing, store APIs. Both app specs mark it out-of-scope; no spec owns it. Also relates to `mac_mini_ios_development_access.md` (covers the *dev* workflow, not *distribution*).

---

## 5. GAP TIER 3 — The developer-framework pivot is almost entirely unspecced

If the product is now a framework that lets developers "skip the boilerplate," the developer experience itself needs specs — and there are essentially none.

- **Scaffolding / code generators** (`create model / logic / view / admin`). The entire pitch is boilerplate-elimination, yet no generator spec exists. Note: `FUTURE_descriptor_consumers.md` + `FUTURE_formwriter_descriptors.md` are the closest existing thing and are **ready to build today** (see §6) — they reduce per-form/per-endpoint duplication and directly serve the pivot.
- **License key / commercial-use enforcement.** Business License (ELv2, $499) vs PolyForm noncommercial has **no mechanism** — no license-key issuance, validation, or commercial-use gating. The platform is being sold under a license it cannot currently enforce or even key.
- **E-commerce setup to actually sell the licenses.** The pivot doc flags this as blocked (Stripe keys, White Glove $99 product + `INSTALL` coupon, Business License purchase flow, CTA wiring). No spec captures the purchase/fulfillment flow end-to-end. Fulfillment for White Glove also ties into Server Manager provisioning.
- **Framework-consumer onboarding** beyond first install (`quickstart_guide.md` is implemented): local dev setup for downstream apps, the upgrade path for apps built *on* Joinery, API-reference generation, a public docs site.
- **Plugin Builder** (`plugin_builder_hosted_product.md`) is a 26-line stub with more open questions than content. Needs a real discovery/design spec before it is buildable.
- **REST API test coverage** — 40+ endpoints, zero automated tests (flagged in `system_features.md` §14). A test-architecture spec for the API surface.

---

## 6. Already unblocked / ready to build (NOT gaps — green lights)

- **`FUTURE_descriptor_consumers.md`** — REST API + AI tools consume logic descriptors instead of duplicated `_logic_api()`. Prereq (`logic_code_refactor` Steps 1–5) **done**. `apiv1.php` discovery/invocation already exists; this mostly rewires it to read descriptors first. 5 files still need descriptors (booking_logic, event_sessions_logic, cart_logic, event_sessions_course_logic, survey_logic) — mechanical migration. **Ready.**
- **`FUTURE_formwriter_descriptors.md`** — `FormWriter::fromDescriptor()` generates fields from a descriptor's `input` schema. All FormWriterV2 classes exist; type mapping is straightforward; adoption is opportunistic/non-breaking. **Ready, low-risk, highest bang-for-buck on duplication. Directly serves the pivot.**
- **`FUTURE_personal_ai_recipes.md`** — data layer ~60% built (`rcp_recipes`, `rcr_recipe_runs`, `recn_recipe_notes` + classes in `plugins/joinery_ai/`). Only the tool-loop runner, tool registry, scheduled dispatcher, cost tracking, and dashboard UI remain. Further along than its "pre-spec" label; pick up when AI is a near-term priority. Open design questions (recipe authoring UI vs config, tool-loop engine) need resolving first.
- **`FUTURE_organizations.md`** and **`FUTURE_attribution_models.md`** — correctly **deferred by design**. Organizations waits on concrete "one payer, many users" demand and on the multi-domain BrandContext mechanism being live. Attribution waits on visitor-event journey data (a "Part E" reporting infra that isn't present). Full specs are ready to execute when demand/data arrives. Leave them.

---

## 7. GAP TIER 4 — Dependency-ordering problems inside existing specs

These specs exist but are sequenced or scoped wrong relative to their dependents.

- **`sms_messaging.md` is under-specced but is a prerequisite** for `uptime_monitor.md` alerts, `scheduling_system.md` reminders, and 2FA-over-SMS. It is the *least* detailed of its dependents (rough 3-phase outline). **Harden it first.**
- **`component_version_integrity.md` is a present-tense correctness bug, not a future feature.** Component manifests already drift from the DB (e.g. inbound_email 1.5.0 in DB vs 1.15.0 in manifest). Publish-time version bumping is missing. Treat as near-term.
- **`scheduling_system.md` Phase 6** lists 8 extensions (waitlist, team scheduling, video conferencing, SMS, routing forms, analytics, widget, branding) each needing its own spec — none exist. Don't let Phase 6 become an unspecced backlog.
- **`geolocation_postgis_spec.md`** is specced standalone but is a hard dependency of the dating spec's distance/discovery engine. Nothing sequences the two; coordinate them (and any other location-aware feature) so PostGIS lands once, first.
- **Product brainstorm docs are not specs.** `patreon_feature_ideas.md` and `ghost_feature_ideas.md` are prioritized gap inventories (~30 items each, comparing Joinery to Patreon/Ghost). Use them as a backlog source, not as buildable specs. `ab_testing_ui_review.md` is a post-launch usability-review memo for the already-shipped A/B framework — not a gap.

---

## 8. Recommended order

Foundational specs first, because every product vertical blocks on them:

1. **Event / trigger dispatch bus** (Tier 2 #2) — unblocks email workflows + webhooks (two of the top-5). Decide first whether the notification-hooks system generalizes into it.
2. **Push notification platform** (Tier 2 #1) — unblocks 2 apps + 2 plugins.
3. **Core block + report systems** (Tier 2 #3) — unblocks dating, chat, social feed.
4. **License key / commercial-use enforcement** + **e-commerce setup for the licenses** (Tier 3) — unblocks actually monetizing the pivot.

Parallel quick wins that need no new foundation:
- Implement `FUTURE_formwriter_descriptors.md` and `FUTURE_descriptor_consumers.md` (ready now, serve the pivot).
- Implement `component_version_integrity.md` (fixes a live drift bug).
- Harden `sms_messaging.md` before its three dependents need it.

Then the named analytics work (Tier 1 #3 and #5) once the dashboard shell and tracking-data tweak exist.

---

## 9. Spec inventory reference (status at 2026-06-11)

**Active specs that are full, ready-ish implementation specs:** chat_plugin, crush_match_plugin, dating_platform_spec, social_feed, cold_email_system, content_pack_feature, scheduling_system, uptime_monitor, component_version_integrity, declarative_admin_tabs, edge_routing_tier, multi_distro_install_refactor, inbound_raw_message_storage, geolocation_postgis_spec, scrolldaddy_ios_app, scrolldaddy_android_app, scrolldaddy_combined_server_install, git_hosting, sister_brand_deployment.

**Active specs that are rough/outline:** sms_messaging, content_security_policy (Phase 1 only is near-term), plugin_builder_hosted_product (stub).

**Active specs that are ops runbooks, not features:** automated_hosting_provisioning_setup, mac_mini_ios_development_access, dev_workstation_migration.

**Active specs that are brainstorm/inventory, not buildable:** patreon_feature_ideas, ghost_feature_ideas, ghost/patreon-style backlogs, ab_testing_ui_review (post-launch review memo), joinery-marketing-targets, scrolldaddy_marketing_plan, combined_most_important_features (priority doc — source for Tier 1).

**FUTURE_ specs:** descriptor_consumers (ready), formwriter_descriptors (ready), personal_ai_recipes (partial foundation), organizations (deferred by design), attribution_models (deferred, needs visitor-event infra).

**Specs that should exist but do not (the gaps — write these):**
- ~~Signal/trigger dispatch bus~~ ✅ written 2026-06-11 — `signal_bus.md`
- Automated email workflows (now unblocked: consumes the signal bus)
- Outgoing webhooks (now unblocked: consumes the signal bus)
- Revenue analytics dashboard (+ per-post/event analytics)
- Push notification platform
- Core block + report systems
- ~~DNS-plugin mobile API surface + `sbr_hard_block`~~ ✅ implemented 2026-06-11 — `implemented/plugin_api_actions.md`
- App distribution / release automation
- License key / commercial-use enforcement
- E-commerce setup & fulfillment for the licenses
- Scaffolding / code generators (developer experience)
- REST API test architecture
- Plugin Builder discovery/design (replace the stub)
