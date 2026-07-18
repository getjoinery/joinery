# Platform — Features Left to Build

Checklist of platform-level work that has no implementation yet. When you pick one up, write the spec into `public_html/specs/` (unless one already exists), implement, then move it to `public_html/specs/implemented/`.

## Need a spec, then implement

- **Automated email workflows** — trigger-based sequences (`member.created → welcome`, `event.start −24h → reminder`, `order.completed → receipt`). MVP can be single-step; branching/multi-step deferred.
- **Outgoing webhooks** — `webhook_subscriptions` + `webhook_delivery_log` tables, HMAC-SHA256 signing, retry w/ exponential backoff. `WebhookLog` model already exists — confirm scope vs. existing.
- **Push notification platform** — token registry, web push + APNs + FCM transports, delivery + retry, per-user/per-device targeting. Unblocks 2 apps + 2 plugins (currently each redefines its own push).
- **Core block + report systems** — soft-block relationship table, report queue + admin moderation surface, enforcement hooks in messaging/feed/discovery. Pull out of `dating_platform_spec.md` into a core spec.
- **App distribution / release automation** — TestFlight/App Store + Play Store submission, code signing, store APIs.
- **getjoinery.com re-messaging** — the production site still carries the abandoned "PHP framework for developers" positioning (spec deleted 2026-07-16, recoverable from git history: `getjoinery_developer_pivot.md`). The site needs re-messaging toward the current direction; its license-tier e-commerce follow-ons (White Glove/Business License products) die with the old positioning unless re-decided.
- **Plugin Builder discovery/design** — a real spec for the plugin-builder concept (the old hosted-product stub was dropped 2026-07-16; recoverable from git history). The scaffolding generator (`implemented/scaffolding_code_generator.md`), its prerequisite, is now built.

## Ready to build now (spec exists / unblocked)

- **`logic_api_descriptor_migration.md`** — the REST API and AI tools consume descriptors natively (`implemented/descriptor_rest_api_core.md`); this drains the legacy estate: author descriptors for the ~85 `_logic_api()`-only logic files (count as of 2026-07-16; the spec's inventory grep is the source of truth), then retire `_logic_api()` in one sweep.
- **`sms_messaging.md`** — harden the rough 3-phase outline; prerequisite for booking reminders (`implemented/scheduling_system.md`) and 2FA-over-SMS. (The standalone uptime-monitor spec was dropped 2026-07-16 — node checks live in Server Manager's `node_uptime_monitoring`.)
- **`geolocation_postgis_spec.md`** — specced standalone; hard dependency of the dating distance/discovery engine. Land PostGIS once, first.

## Deferred (not near-term)

Revenue analytics (MRR/churn), per-post/event analytics, `FUTURE_organizations.md`, `FUTURE_attribution_models.md`, `FUTURE_personal_ai_recipes.md`.
