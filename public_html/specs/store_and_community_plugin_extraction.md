# Store and Community Plugin Extraction

## Goal

The platform is refocusing on the single-user product: email, AI, calendar, chat. A standard install sells nothing and runs no events — but events, the store (products/orders/checkout/billing), and questions/surveys currently live in core. This spec extracts them into **two plugins**:

- **`store`** — the ability to take money: products, cart/checkout, orders, coupons, Stripe/PayPal, recurring subscriptions, the billing portal, and product requirements. An install that sells paid subscriptions (ScrollDaddy, hosting) activates this and nothing else.
- **`community`** — the membership-organization toolkit: events (+ locations), questions/surveys, and their store integrations (event tickets, intake surveys). Depends on `store`.

Everything here was derived from a full dependency trace. The guiding rule: **couplings internal to a plugin move with it untouched; every coupling that crosses a plugin boundary (core↔store, core↔community, store↔community) goes through one of a small, fixed set of extension points, decided once, below.**

The dependency direction that makes the split work: events need products (tickets are sold at checkout), but the store never fundamentally needs events — its two reaches into events (ticket fulfillment, survey collection at checkout) become provider registrations supplied by `community`.

## Naming and shape

- Plugin directories: `plugins/store/`, `plugins/community/`. `community` declares `"depends": { "store": ">=1.0.0" }` (enforced at activation; blocks deactivating store while community is active).
- **All table names, column names (except the generalized ones listed below), and setting names are preserved.** `PluginManager::sync()` sees the same schema from the new locations; no data moves.
- Public URLs are preserved (`/events`, `/event/{slug}`, `/product/{slug}`, `/cart`, `/checkout`, `/pricing`, `/survey`, `/profile/events`, `/profile/orders`, `/profile/billing`, ...). See "Routing" below.

## What moves into `plugins/store/`

### Data classes (table names unchanged)
`products`, `product_details`, `product_groups`, `product_versions`, `orders`, `order_items`, `order_item_requirements`, `coupon_codes`, `coupon_code_products`, `coupon_code_uses`, `stripe_invoices`, `product_requirements`, `product_requirement_instances`. New: `stripe_customers` (see "Stripe customer identity").

### Includes
`StripeHelper.php`, `PaypalHelper.php`, `ShoppingCart.php`, `includes/requirements/` — the `AbstractProductRequirement` registry plus the built-in requirement types (Address, DOB, Email, FullName, NewsletterSignup, PhoneNumber, UserPrice). The Question and Survey requirement types go to `community` (see below); the registry already scans plugin directories, so plugin-contributed types need no new mechanism.

New in the plugin: `includes/TierBilling.php` — receives the four billing-facing methods split out of `SubscriptionTier` (see "Tier split").

### Logic
`cart_logic`, `cart_charge_logic`, `cart_clear_logic`, `checkout_logic`, `product_logic`, `products_logic`, `pricing_logic`, `order_list_logic`, `orders_profile_logic`, `orders_recurring_action_logic`, `billing_logic`, `subscriptions_logic`, `subscription_summary_logic`, `change_tier_logic`.

### Views
`product`, `products`, `cart`, `cart_charge`, `cart_clear`, `cart_confirm`, `checkout`, `pricing`, `profile/orders` (plus billing/subscriptions profile views).

### Admin (URLs become `/plugins/store/admin/...`, menus via plugin.json `adminMenu`)
`admin_product*`, `admin_order*`, `admin_orders`, `admin_stripe_orders`, `admin_stripe_invoices`, `admin_coupon_code*`, `admin_settings_payments`, `admin_user_payment_methods`, `admin_order_refund`, plus their `adm/logic/*` counterparts.

### Ajax
`checkout_ajax.php`, `stripe_webhook.php`, `paypal_subscription_webhook.php`. Webhook URLs change to the plugin namespace — **update the endpoint URLs configured in the Stripe and PayPal dashboards** (and `stripe_endpoint_secret` if re-created) as part of rollout. `checkout_ajax` currently writes survey answers inline, bypassing the requirement abstraction — that block is refactored to go through the requirement interface so the survey knowledge lands in `community`'s requirement types, not in store code.

### Utils
`utils/*stripe*.php`, `utils/refresh_stripe_test_keys.php`.

### Settings → plugin.json `settings` (names preserved — menu gating keys on the name)
`products_active`, `products_list_items_active`, `coupons_active`, `subscriptions_active`, `checkout_type`, `cart_intermediate_page`, `site_currency`, `use_paypal_checkout`, `use_venmo_checkout`, all `stripe_*` and `paypal_*` keys, all `subscription_*` policy keys, `max_subscriptions_per_user`, `pricing_page`. (`products_list_events_active` goes to `community` — it gates event products on the listing page.) Remove all of these from root `settings.json`.

### Menus
From `admin_menus.json`: `orders` parent + `orders-list`, `stripe-payments`, `shadow-sessions`; `products` parent + `products-list`, `product-groups`, `coupon-codes`. ProfileMenu: `core-orders`, `core-subscriptions` (with `nativeScreen`, **slugs preserved** so app navigation is unaffected). `subscription-tiers` menu entry **stays core**.

### Signals
`purchase.completed`, `payment.failed`, `subscription.started`, `subscription.cancelled`, `subscription.payment_failed`, `subscription.expired` move from root `signals.json` to store's `signals` key. SignalBus merges plugin-declared signals; core and other plugins can subscribe to them unchanged.

### Email templates
`purchase_receipt_default`, `purchase_receipt_product_default` seeding moves to store migrations.

### Extension seams store *offers* (used by community and future plugins)
- **Product fulfillment providers** (new — see extension point 9).
- **Requirement type registry** (existing `AbstractProductRequirement::register()` + plugin dir scan).
- **Purchase hooks** (`plugins/{name}/hooks/product_purchase.php` scan — loader lives in `products_class` and moves with it; the core stub file becomes convention documentation in store's docs).
- **Purchase signals** (above).

## What moves into `plugins/community/`

### Data classes (table names unchanged)
`events`, `event_registrants`, `event_sessions`, `event_session_files`, `event_types`, `event_waiting_lists`, `locations`, `questions`, `question_options`, `surveys`, `survey_questions`, `survey_answers`.

### Includes
`calendar_item_sources/EventItemSource.php` (the calendar item-source registry already scans plugin dirs; zero registry changes — bookings is the precedent), `requirements/QuestionRequirement.php`, `requirements/SurveyRequirement.php` (registered into store's requirement registry), the `event_registration` fulfillment provider and `event_registration` access-gate provider (new files, see extension points).

### Logic
`event_logic`, `events_logic`, `event_register_logic`, `event_sessions_logic`, `event_sessions_course_logic`, `events_profile_logic`, `event_waiting_list_logic`, `event_withdraw_logic`, `my_events_logic`, `location_logic`, `survey_logic`.

### Views
`event`, `events`, `event_waiting_list`, `location`, `survey`, `survey_finish`, `profile/events`, `profile/event_sessions`, `profile/event_sessions_course`, `profile/event_withdraw`.

### Admin
`admin_event*`, `admin_location*`, `admin_question*`, `admin_survey*`, plus their `adm/logic/*` counterparts.

### Ajax / Tasks
`session_search_ajax.php`; tasks `WeeklyEventsDigest`, `SendPostEventSurveys` (.php + .json).

### Settings
`events_active`, `events_label`, `surveys_active`, `products_list_events_active`, `event_email_inner/outer/footer_template`. The `event` and `location` keys inside the `max_entity_photos` JSON default are seeded by a community migration.

### Menus
From `admin_menus.json`: `events` parent + `events-list`, `event-bundles`, `event-types`, `locations`; `surveys-parent`, `surveys`, `survey-questions`. ProfileMenu: `core-events` (+ `core-event-sessions` from the imperative seeds), slugs and `nativeScreen` preserved. Remove the duplicate imperative profile-menu seeds in `migrations/migrations.php:664-677` — plugin manifests become the single seed source for the moved entries.

### The event↔store integrations (community-side implementations of store seams)
- Ticket fulfillment: the `cart_charge_logic` blocks that construct `new Event(...)` and call `add_registrant()` move into community's fulfillment provider.
- Survey-at-checkout: the auto-attach of `SurveyRequirement` for events (`products_class:465-473`, `cart_charge_logic:857`) moves into community's requirement/fulfillment code; `evt_svy_survey_id`, `evt_survey_display`, `evr_survey_completed` are community-internal columns.
- Event ticket lookups from the event side (`events_class` using `MultiProduct`) are community→store calls — allowed by the dependency direction.

### Docs
Move to plugin docs: `docs/recurring_events.md`, `docs/questions_surveys.md` → `plugins/community/docs/`; `docs/product_requirements.md`, `docs/product_purchase_hooks.md` → `plugins/store/docs/`. Add an `overview.md` to each plugin.

## What stays in core (and why)

- **Subscription tiers (gating)** — `SubscriptionTier` gating surface, `SystemBase::authenticate_tier`, `_tier_min_level` columns on content types, tier feature flags in API session payloads, tier admin (`admin_subscription_tier_edit`, `admin_subscription_tiers` list, `admin_tier_edit` manual assignment). Tiers work with billing absent: admin assigns tier → group membership → gates and `getUserFeature()` resolve.
- **Groups** — core access-control primitive; tiers are built on it; files/videos gate on it.
- **Calendar and scheduling** — `calendar_entry*` is the personal calendar; `schedule`/`schedule_window`/`schedule_override` serve bookings/availability. No events file touches them.
- **Email engine** — `emails`, `queued_email`, `email_templates`, `email_recipient_groups`/`email_recipients` (recipient groups also target core groups; event targeting becomes a provider, below).
- **`event_logs` (audit log) and `visitor_events`/`session_analytics` (analytics)** — name collisions only; not the events feature.
- **Videos, files, entity_photos** — content infrastructure; their event-registration gates become providers (below).
- **SEO metadata, public menus, page contents, content versions** — page-render infrastructure.
- **IcsHelper + calendar-links vendored lib** — used by the core calendar as well as events.

## Extension points (the complete set, decided once)

Providers register from each plugin's `serve.php`, which core loads on every request (`RouteHelper::loadPluginRoutes`) — that file is the plugin's request bootstrap. All registries fail soft: no provider registered means the section/option/gate simply isn't there. Points 1–8 and 10–13 are core↔plugin seams; point 9 is the store↔community seam.

### 1. Route delegation (`plugin` route option) — new, small
Plugins cannot own top-level dynamic routes (the namespace filter drops them by design). Add a `plugin` option to core route configs so a core-declared route resolves its view (and auto-loaded logic) from a plugin directory:

```php
'/event/{slug}' => ['view' => 'views/event', 'plugin' => 'community', 'check_setting' => 'events_active'],
```

Core `serve.php` remains the deliberate, auditable registry of top-level URLs; the plugins own the files. Store routes: `/product/{slug}`, plus new explicit entries for URLs currently served by view-directory fallback — `/products`, `/cart`, `/checkout`, `/pricing` — and profile pages `/profile/orders`, `/profile/billing`, `/profile/subscriptions`. Community routes: `/event/{slug}/{date}`, `/event/{slug}`, `/location/{slug}`, `/events`, `/survey`, `/profile/events`, `/profile/event_sessions`, `/profile/event_withdraw`. Each keeps/gains the matching `check_setting`; with the plugin inactive or the setting off, the route 404s as today.

### 2. Shopping cart out of the session core
`SessionControl` currently hard-requires `ShoppingCart` (line 9), owns `get_shopping_cart()`/`save_shopping_cart()` (232-240), and captures `?coupon=` URL codes (`capture_marketing_coupon`, ~500-525). All of it moves to `store`:
- `ShoppingCart::current()` (static, store-side) manages `$_SESSION['shopping_cart']` itself; all call sites switch to it.
- Coupon capture and flash-message handling move into store's request bootstrap.
- `SessionControl` keeps zero knowledge of carts or coupons. The `VisitorEvent::TYPE_COUPON_ATTEMPT` analytics write stays available — store calls `$session->save_visitor_event()` like any caller.

### 3. Header menu providers
`PublicPageBase` builds the header cart link + item-count badge on every page (245-266). Replace with a small static registry, e.g. `PublicPageBase::register_header_menu_provider(callable)`; store registers the cart entry. No provider → no cart in the header.

### 4. Profile dashboard section providers
`logic/profile_logic.php`, `logic/profile_dashboard_logic.php`, and `views/profile/profile.php` hand-build the events / orders / subscriptions / pending-surveys sections for both the web profile and the native-app dashboard summary. Add `includes/ProfileDashboardRegistry.php`: providers contribute stat cards and list sections in a declared shape; the web profile view and the dashboard API iterate the registry. Store registers recent orders + active subscriptions; community registers upcoming events + pending surveys.

### 5. Admin-user panel providers
`adm/admin_user.php` + its logic hand-build Orders, Subscriptions (incl. `StripeHelper::update_subscription_in_order_item`), and Events (add/remove registrant) panels. Add `AdminUserPanelRegistry`: providers receive the `User` and return rendered panels + handle their own POST actions. Store registers orders/subscriptions; community registers events. The Subscription **Tier** card (manual tier display/assignment) stays core.

### 6. Email recipient-group providers
`email_recipient_groups` stays core but its event coupling (`erg_evt_event_id`, `Email::add_recipient_group($evt_event_id, ...)`, and the admin send UI resolving event registrants / waiting lists) generalizes:
- Columns: `erg_evt_event_id` → `erg_provider` (varchar) + `erg_reference_id` (int). Core ships the `group` provider; community registers `event` and `event_waiting_list` providers.
- `RecipientGroupProviderRegistry` supplies both resolution (id list) and the admin UI's targeting options (label + picker).
- Touch points to convert: `data/emails_class.php` (95-115), `adm/admin_email.php`, `admin_email_recipients_modify.php`, `admin_emails_queue.php`, `adm/logic/admin_users_message_logic.php`.

### 7. Content access-gate providers
Videos and files gate on event registration via schema-level FKs (`vid_evt_event_id`; `fil_evt_event_id` + direct `esf_`/`MultiEventSessions`/`MultiEventRegistrant` queries in `files_class.php` 1302-1620). Generalize:
- Columns: `vid_evt_event_id` → `vid_access_provider` + `vid_access_ref`; `fil_evt_event_id` → `fil_access_provider` + `fil_access_ref`.
- `AccessGateRegistry`: a provider answers `userMayAccess($user_id, $ref)` and supplies the admin picker (the "select an event" dropdown in `admin_video_edit` / `admin_file_edit` becomes provider-supplied options). Community registers the `event_registration` provider; file↔event-session attachment queries move behind it.
- **Fail closed**: a gate whose provider is absent denies access.
- Group and tier gates (`fil_grp_group_id`, `*_tier_min_level`) are core and unchanged.

### 8. SEO entity registration
`SeoPageMetadata::ENTITY_CLASSES` hard-wires event/product/location (used by the sitemap, `enumerate_public_paths`, and admin SEO pages). Convert to `SeoPageMetadata::register_entity_class($type, $class, $file)`; core registers post/page/video/mailing_list, store registers `product`, community registers `event` and `location`. `views/site-directory.php`'s Events and Locations sections gate on `events_active` (the Events one already does).

### 9. Product fulfillment providers (the store↔community seam)
Today a product *is* an event ticket via `pro_evt_event_id`, and checkout hand-runs registration. Generalize so store never knows events exist:
- Columns: `pro_evt_event_id` → `pro_fulfillment_provider` (varchar) + `pro_fulfillment_ref` (int).
- `FulfillmentRegistry` (store-owned): a provider supplies the admin product-edit picker (label + options), a `fulfill($user, $product, $order_item, $order)` called on successful purchase, and optional display hooks (order-item admin linking to the fulfilled thing — replaces `admin_order_item_edit`'s direct Event/EventRegistrant lookups).
- Community registers `event_registration`: fulfill creates the `EventRegistrant`, handles bundle groups, and attaches the event's survey requirement per `evt_survey_display`.
- Event-bundle group membership (`pro_grp_group_id` → core `Group`) is a store→core call and stays in store.
- The `products_list_events_active` gate on the products listing becomes a community-registered listing filter or stays as a simple setting check community seeds — implementer's choice; no store→events reference either way.

### 10. Tier split (core keeps gating, store owns billing)
`SubscriptionTier` keeps: `GetUserTier`, `UserHasMinimumTier`, `getUserFeature`, feature JSON accessors, `getAllAvailableFeatures`, `getUserTierDisplay`, `requireMinimumTier`, `addUser`, `removeUserFromAllTiers`, `save` (group creation), `MultiSubscriptionTier::GetAllActive`.

Moving to `plugins/store/includes/TierBilling.php`: `handleProductPurchase`, `getUpgradeOptions`, `userHasActiveSubscription`, `handleSubscriptionExpired`. They call the core primitives (`addUser`, `removeUserFromAllTiers`, `GetUserTier`) — grant/revoke flows only ever cross the boundary through those. Callers (`cart_charge_logic:744`, `change_tier_logic:239`, both webhooks) are all store-side after the move.

Core degrades: `SystemBase::authenticate_tier` (line 1762) stops calling `getUpgradeOptions`; the upgrade CTA in `includes/tier_gate_prompt.php` (58-72, hardcoded `/product/{id}` links) is replaced by a settings-driven link — new core setting `tier_upgrade_url` (empty default → the existing "Contact us to learn about upgrading" fallback). Store seeds `tier_upgrade_url = /pricing`. The "Products Granting Tiers" panel in `adm/admin_subscription_tiers.php` (121-147, raw SQL on `pro_products`) moves to store's product admin. `pro_sbt_subscription_tier_id` stays a store-side column referencing a core table — that direction is fine.

Also: move the ScrollDaddy tier-feature definitions from `theme/scrolldaddy/tier_features.json` (never discovered — `getAllAvailableFeatures` only scans `plugins/*/tier_features.json`) to `plugins/dns_filtering/tier_features.json`. Pre-existing wiring gap; fix it while touching this area.

### 11. Stripe customer identity off the users table
`usr_stripe_customer_id` / `usr_stripe_customer_id_test` and `User::GetByStripeCustomerId` are read/written only by billing code. Replace with a store table `stripe_customers` (`stc_`: `stc_usr_user_id`, `stc_customer_id`, `stc_customer_id_test`) and a `StripeHelper` accessor. Update `billing_logic:58`, `admin_user_payment_methods`, the two stripe utils, and the tier tester.

### 12. Polymorphic references replacing event FKs on core tables
- `messages.msg_evt_event_id` → `msg_context_type` + `msg_context_id` (generic entity-attached message context; community writes `event`).
- `session_analytics.sev_evt_event_id` / `sev_evs_event_session_id` → `sev_entity_type` + `sev_entity_id`; community-side analytics writes use the generic pair.
- Prune the legacy `sva_*` column mapping in `includes/DatabaseUpdater.php:2000-2002`.

### 13. Platform fixes required by any extraction
- **REST API model discovery**: `api/apiv1.php:345` calls `discover_model_classes()` without `include_plugins`, so plugin data classes are invisible to `/api/v1` model endpoints (already silently affects mailbox classes). Change to `discover_model_classes(['include_plugins' => true])`. Per-class `api_readable`/`api_writable` authorization unchanged. The AI surface (`joinery_ai` ModelRegistry) already scans plugin data dirs.
- **Theme requirement enforcement**: `theme/phillyzouk-html5/logic/index_logic.php:7,21` hard-requires `events_class` and would fatal with community off. (a) Add `"requires_plugins": ["community"]` to that theme.json; (b) extend `ThemeManager::activate()` to enforce `requires_plugins` (today only enforced against plugin *deactivation*); (c) guard the require with a plugin-active check anyway.

## Dependent plugins

- **community** → `"depends": { "store": ">=1.0.0" }`.
- **dns_filtering** → depends on `store` (profile page reads `MultiOrder`/`MultiOrderItem`; ScrollDaddy sells subscriptions). Cleanup opportunity, not required: its profile clone also copied the core profile's event sections — strip those rather than depending on community.
- **bookings** → depends on `store` (`bkt_pro_product_id`/`bkn_pro_product_id`) and `community` (`bkt_svy_survey_id` intake surveys, survey-answer writes).
- **server_manager** → **no dependency.** `PollHostingOrders` reads orders, requirement answers, and users from a *remote* getjoinery site via `GetJoineryApiClient` (HTTP + API keys); it loads no local store or community classes. The requirement lands on the remote deployment instead: it must run store + community and expose `OrderItemRequirements`/`OrderItem`/`User` through `/api/v1` — which the `include_plugins` model-discovery fix (extension point 13) preserves after those classes move into plugins.
- **items** → **no dependency; fix a broken page instead.** `plugins/items/admin/admin_item_relation_types.php:25` instantiates `MultiProductGroup` but then reads `itr_name` (an item-relations column) from the rows and links to a nonexistent `/admin/admin_item_relation_edit` page — vestigial scaffolding. Repair the page to use the plugin's own `item_relation_types_class.php` as part of this work.

## Native apps

The native member screens call API actions that become plugin actions: `order_list`, `subscription_summary` → `/api/v1/action/store/...`; `my_events` → `/api/v1/action/community/...`. Update the endpoint constants in `JoineryMemberKit` (`{repo root}/ios/`) and the Android member app, and rerun `tests/functional/android/member_gate.sh` + `tests/functional/api/member_screens_test.php`. Navigation is unaffected: profileMenu slugs and `nativeScreen` values are preserved through the plugin manifests, and the app navigation endpoint reads the same menu store.

## Execution order

1. **Core extension points first, with everything still in core** (route `plugin` option; header/dashboard/admin-user/recipient-group/access-gate/SEO/fulfillment registries; cart out of SessionControl; tier split with `TierBilling` still in `includes/`; polymorphic columns; apiv1 discovery; theme enforcement). Everything keeps working; each seam is verifiable independently.
2. **Move the store** into `plugins/store/` (files, settings, menus, signals, templates, utils, docs); add `depends` to dns_filtering, server_manager, bookings, items. Update webhook URLs and app endpoint constants. Verify core-without-store and store-active states.
3. **Move community** into `plugins/community/` (files, settings, menus, tasks, docs), registering its fulfillment/requirement/gate/recipient/dashboard/SEO providers. Verify all three states.
4. Update docs, then move this spec to `specs/implemented/`.

## Acceptance criteria

With **both plugins inactive** (fresh-install state):
- Every public page and admin page loads with no fatals; no cart in the header; `/products`, `/cart`, `/pricing`, `/events`, `/event/x`, `/survey` 404.
- Tier admin works end-to-end: create tier, set features, manually assign a user, `authenticate_tier` gates content, gate prompt renders the contact-us fallback (or `tier_upgrade_url` if set), `getUserFeature` resolves.
- Sitemap, site directory, admin email send, video/file admin, profile, and admin-user pages render without the moved sections/options.
- dns_filtering, bookings, community refuse to activate (missing dependency); server_manager and items activate and function (no local dependency).

With **store active, community inactive**:
- Products, cart, checkout, coupons, subscriptions, billing portal, webhooks, and tier purchase (`TierBilling::handleProductPurchase` → `SubscriptionTier::addUser`; webhook revoke via `handleSubscriptionExpired`) all work. The product-edit fulfillment picker and requirement types show only store-provided options. dns_filtering activates and functions.

With **both active**:
- All preserved URLs render; event-ticket purchase creates registrants through the fulfillment provider; survey requirements collect at checkout; events feed the core calendar via the plugin item source; recipient-group email targeting of registrants works; gated videos/files enforce registration; native member screens pass their gate tests; bookings activates with intake surveys and paid bookings working.
- `tests/functional/products`, `tests/functional/subscription_tiers`, `tests/functional/api/member_screens_test.php`, `tests/integration/routing_test.php`, `tests/models/run_multi.php` pass.

## Documentation updates (same change, current-state voice)

- `docs/routing.md` — the `plugin` route option.
- `docs/api.md` — model discovery includes plugin data classes.
- `docs/subscription_tiers.md` — tiers as core gating + group membership; billing integration described via the `TierBilling` seam and `tier_upgrade_url`.
- `docs/email_system.md` — recipient-group providers.
- `docs/admin_pages.md` — admin-user panel registry; `docs/plugin_developer_guide.md` — all new registries, fulfillment providers, `requires_plugins` enforcement, header/dashboard providers.
- New `plugins/store/docs/overview.md` and `plugins/community/docs/overview.md`; moved docs listed above.
- CLAUDE.md documentation index — update via the admin agent-files interface (never on disk).

## Explicitly out of scope

Blog/posts/comments and reactions extraction (separate, already-clean candidates); mailing lists (transactional/marketing email share `eml_emails` — separate effort); analytics/AB write path; videos as a feature (stays core; only its event gate is generalized here); bookings relocation; standalone surveys without the store (community depends on store); any renaming of tables, settings, URLs, or signals beyond the generalized columns listed.
