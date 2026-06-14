# Platform — Features Left to Build

Checklist of platform-level work that has no implementation yet. When you pick one up, write the spec into `public_html/specs/` (unless one already exists), implement, then move it to `public_html/specs/implemented/`.

## Need a spec, then implement

- **Automated email workflows** — trigger-based sequences (`member.created → welcome`, `event.start −24h → reminder`, `order.completed → receipt`). MVP can be single-step; branching/multi-step deferred.
- **Outgoing webhooks** — `webhook_subscriptions` + `webhook_delivery_log` tables, HMAC-SHA256 signing, retry w/ exponential backoff. `WebhookLog` model already exists — confirm scope vs. existing.
- **Push notification platform** — token registry, web push + APNs + FCM transports, delivery + retry, per-user/per-device targeting. Unblocks 2 apps + 2 plugins (currently each redefines its own push).
- **Core block + report systems** — soft-block relationship table, report queue + admin moderation surface, enforcement hooks in messaging/feed/discovery. Pull out of `dating_platform_spec.md` into a core spec.
- **App distribution / release automation** — TestFlight/App Store + Play Store submission, code signing, store APIs.
- **E-commerce setup & fulfillment for the licenses** — Stripe keys, White Glove $99 product + `INSTALL` coupon, Business License purchase flow, CTA wiring. White Glove fulfillment ties into Server Manager provisioning.
- **Scaffolding / code generators** — `create model / logic / view / admin` to eliminate boilerplate.
- **REST API test architecture** — 40+ endpoints, zero automated tests.
- **Plugin Builder discovery/design** — replace the 26-line `plugin_builder_hosted_product.md` stub with a real spec.

## Ready to build now (spec exists / unblocked)

- **`FUTURE_formwriter_descriptors.md`** — `FormWriter::fromDescriptor()` generates fields from a descriptor's `input` schema. Low-risk, non-breaking, opportunistic adoption.
- **`FUTURE_descriptor_consumers.md`** — REST API + AI tools read logic descriptors instead of duplicated `_logic_api()`. 5 files still need descriptors: `booking_logic`, `event_sessions_logic`, `cart_logic`, `event_sessions_course_logic`, `survey_logic`.
- **`component_version_integrity.md`** — fixes a live manifest/DB version-drift bug; publish-time version bumping is missing.
- **`sms_messaging.md`** — harden the rough 3-phase outline; prerequisite for `uptime_monitor.md` alerts, `scheduling_system.md` reminders, and 2FA-over-SMS.
- **`geolocation_postgis_spec.md`** — specced standalone; hard dependency of the dating distance/discovery engine. Land PostGIS once, first.

## Deferred (not near-term)

Revenue analytics (MRR/churn), per-post/event analytics, `FUTURE_organizations.md`, `FUTURE_attribution_models.md`, `FUTURE_personal_ai_recipes.md`.
