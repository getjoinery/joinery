# Store and Event Manager Plugin Extraction

## Goal

The platform is refocusing on the single-user product: email, AI, calendar, chat. A standard install sells nothing and runs no events — but events and the store (products/orders/checkout/billing) currently live in core. This spec extracts them into **two plugins**:

- **`store`** — the ability to take money: products, cart/checkout, orders, coupons, Stripe/PayPal, recurring subscriptions, the billing portal, and product requirements. An install that sells paid subscriptions (ScrollDaddy, hosting) activates this and nothing else.
- **`event_manager`** — the events toolkit: events (+ locations) and their store integrations (event tickets, event surveys at checkout). Depends on `store`.

**Questions/surveys stay in core.** They are the platform's general data-collection primitive (the no-ad-hoc-forms doctrine), the survey code has no dependency on events — events reference surveys in the plugin→core direction only (`evt_svy_survey_id`) — and keeping them core lets bookings run intake surveys with no events system installed.

Everything here was derived from a full dependency trace plus a follow-up audit sweep of core, themes, tasks, tests, tooling, and the installer. The guiding rule: **couplings internal to a plugin move with it untouched; every coupling that crosses a plugin boundary (core↔store, core↔event_manager, store↔event_manager) goes through one of a small, fixed set of extension points, decided once, below.**

The dependency direction that makes the split work: events need products (tickets are sold at checkout), but the store never fundamentally needs events — its reaches into events (ticket fulfillment, event-survey attachment at checkout) become provider registrations supplied by `event_manager`.

## Naming and shape

- Plugin directories: `plugins/store/`, `plugins/event_manager/`. `event_manager` declares `"depends": { "store": ">=1.0.0" }` (enforced at activation; blocks deactivating store while event_manager is active).
- **All table names, column names (except the generalized ones listed below), and setting names are preserved.** `PluginManager::sync()` sees the same schema from the new locations; no data moves.
- Public URLs are preserved (`/events`, `/event/{slug}`, `/product/{slug}`, `/cart`, `/checkout`, `/pricing`, `/profile/events`, `/profile/orders`, `/profile/billing`, `/event/{slug}.ics`, `/event/{slug}/{date}.ics`, `/events/calendar.ics`, ...). See "Routing" below. `/survey` never moves — it stays a plain core view.
- **ProfileMenu slugs are renamed, not preserved**: plugin sync rejects plugin-declared `core-*` slugs (`PluginManager.php:1451-1455`, `PluginHelper.php:124,163,197`), so `core-orders` → `store-orders`, `core-subscriptions` → `store-subscriptions`, `core-events` → `event-manager-events`, `core-event-sessions` → `event-manager-event-sessions`. Native-app navigation keys on `nativeScreen` values and the menu store, not slug literals (verified: `app_navigation` does not reference these slugs), and the `nativeScreen` values are preserved exactly. A cleanup migration removes the old rows; plugin manifests seed the new ones (see Implementation decisions § Menu and settings lifecycle).
- **Webhook URLs do not change.** `stripe_webhook.php` and `paypal_subscription_webhook.php` move to `plugins/store/ajax/` and keep resolving at their existing flat `/ajax/` URLs (webhooks are a legitimate permanent use of the flat namespace, guarded by the sync-time collision validator — see "Endpoint doctrine"). No Stripe/PayPal dashboard reconfiguration.

## Endpoint doctrine (bundled from `plugin_ajax_namespace_collision.md`)

The settled platform rule: plugin browser-facing endpoints are API actions at `/api/v1/action/{plugin}/{action}` (isolated by address); the flat `ajax/`/`utils/`/`tests/` namespace remains only for webhooks, utils, and tests, guarded by a validator. This extraction ships the validator and applies the rule to every endpoint it touches; the remaining per-plugin migrations (server_manager, dns_filtering, mailbox, bookings) stay in that spec.

- **Sync-time collision validator**: `PluginManager::sync()` collects every `ajax/`, `utils/`, and `tests/` basename across all active plugins plus the corresponding core directory and fails the sync with a named-file error on any duplicate within a directory type. This must land before the moves — the extraction itself creates the first `plugins/store/ajax/` files. Exact placement and semantics in Implementation decisions § Collision validator.
- **`checkout_ajax.php` is retired, not moved.** It has four live branches and one dead one. The three store-owned operations become store logic actions (`_logic_descriptor()` opt-in) called as `POST /api/v1/action/store/...` with the browser-session credential (session cookie + `X-Joinery-Csrf` header): `checkout_apply_coupon`, `checkout_remove_coupon`, `checkout_check_email`. The fourth live branch, `submit_survey`, is event-coupled (it writes `evr_survey_completed`) and becomes `POST /api/v1/action/event_manager/checkout_submit_survey`, persisting answers through the requirement interface instead of hand-rolled SQL. The `validate_section` branch and `validate_checkout_section()` (`checkout_logic.php:239`) are dead code (no callers) — delete, don't port. The old file is deleted.
- **`ajax/session_search_ajax.php` is deleted outright** — it has zero callers repo-wide (verified). No replacement action is created unless the admin session picker is ever reintroduced.
- Core `/ajax/` endpoints that stay core (e.g. `entity_photos_ajax.php`) are untouched by this rule (migrate-when-touched applies as usual).

## What moves into `plugins/store/`

### Data classes (table names unchanged)
`products`, `product_details`, `product_groups`, `product_versions`, `orders`, `order_items`, `order_item_requirements`, `coupon_codes`, `coupon_code_products`, `coupon_code_uses`, `stripe_invoices`, `product_requirements`, `product_requirement_instances`. New: `stripe_customers` (see "Stripe customer identity").

Before `Product` departs, its platform-wide static `Product::$currency_symbols` (`data/products_class.php:49`) moves to a new core helper `includes/CurrencyHelper.php` (`CurrencyHelper::symbol($code)`, `::all()`); all call sites — core analytics (`adm/admin_analytics_attribution.php:14`) and store code alike — switch to it.

### Includes
`StripeHelper.php`, `PaypalHelper.php`, `ShoppingCart.php`, `includes/requirements/` — the `AbstractProductRequirement` registry plus the built-in requirement types (Address, DOB, Email, FullName, NewsletterSignup, PhoneNumber, UserPrice, **Question, Survey**). The Question and Survey requirement types are store built-ins that read the core survey tables — store→core, the allowed direction; the registry already scans plugin directories, so plugin-contributed types need no new mechanism.

New in the plugin: `includes/TierBilling.php` — receives the four billing-facing methods split out of `SubscriptionTier` (see "Tier split").

### Logic
`cart_logic`, `cart_charge_logic`, `cart_clear_logic`, `checkout_logic`, `product_logic`, `products_logic`, `pricing_logic`, `order_list_logic`, `orders_profile_logic`, `orders_recurring_action_logic`, `billing_logic`, `subscriptions_logic`, `subscription_summary_logic`, `change_tier_logic`, plus the new logic actions replacing `checkout_ajax.php`.

### Views
`product`, `products`, `cart`, `cart_charge`, `cart_clear`, `cart_confirm`, `checkout`, `pricing`, `profile/orders`, `profile/billing`, `profile/subscriptions`, `profile/change-tier`, `profile/orders_recurring_action` (the last two pair with `change_tier_logic`/`orders_recurring_action_logic` and get explicit delegated routes).

### Tasks
`tasks/ReconcileStripeSubscriptions` (.php + .json — the daily subscription-reconciliation backstop; requires StripeHelper and MultiOrderItem) and `tasks/SyncPaypalSubscriptions` (.php + .json — PayPal subscription-status sync) → `plugins/store/tasks/`.

### Admin (URLs become `/plugins/store/admin/...`, menus via plugin.json `adminMenu`)
`admin_product*`, `admin_order*`, `admin_orders`, `admin_stripe_orders`, `admin_stripe_invoices`, `admin_coupon_code*`, `admin_settings_payments`, `admin_user_payment_methods`, `admin_order_refund`, **`admin_yearly_report_donations`** (entirely store-data-driven: `MultiProduct` + raw `odi_order_items` SQL), plus their `adm/logic/*` counterparts.

### Ajax
`stripe_webhook.php`, `paypal_subscription_webhook.php` → `plugins/store/ajax/` (URLs unchanged via flat resolution; validator-guarded). `checkout_ajax.php` retired per the endpoint doctrine above.

### Utils
`utils/*stripe*.php`, `utils/refresh_stripe_test_keys.php`, `utils/products_list.php`.

### Tests
`tests/functional/products/` and `tests/functional/subscription_tiers/` move to `plugins/store/tests/` — `tests/lib/discovery.php:65` already scans `plugins/*/tests/`, so no runner change; the moved `run.php` entrypoints keep their `@joinery-test` headers. Their internal requires repoint to the new plugin paths (see "Test estate and tooling").

### Settings → plugin.json `settings` (names preserved — menu gating keys on the name)
`products_active`, `products_list_items_active`, `coupons_active`, `subscriptions_active`, `checkout_type`, `cart_intermediate_page`, `site_currency`, `use_paypal_checkout`, `use_venmo_checkout`, all `stripe_*` and `paypal_*` keys, `connected_account_id` (Stripe Connect, edited on `admin_settings_payments`), all `subscription_*` policy keys, `max_subscriptions_per_user`, `pricing_page`. (`products_list_events_active` goes to `event_manager` — it gates event products on the listing page.) Remove all of these from root `settings.json`. Existing `stg_settings` rows keep their values through the move — both seeders are `INSERT ... ON CONFLICT DO NOTHING` and nothing deletes undeclared rows (verified `settings_class.php:101-117`); no settings migration is needed.

### Menus
From `admin_menus.json`: `orders` parent + `orders-list`, `stripe-payments`, `shadow-sessions`; `products` parent + `products-list`, `product-groups`, `coupon-codes`. ProfileMenu: `core-orders` → **`store-orders`**, `core-subscriptions` → **`store-subscriptions`** (slug rename required — see "Naming and shape"; `nativeScreen` values `orders`/`subscriptions` preserved exactly). `subscription-tiers` menu entry **stays core**. Also move `adm/admin_shadow_sessions.php` with the `shadow-sessions` menu entry.

### Signals
`purchase.completed`, `payment.failed`, `subscription.started`, `subscription.cancelled`, `subscription.payment_failed`, `subscription.expired` move from root `signals.json` to store's `signals` key. SignalBus merges plugin-declared signals; core and other plugins can subscribe to them unchanged. (`VisitorEvent::TYPE_PURCHASE` stays core analytics vocabulary; with store absent the analytics "Purchase" funnel stage is simply empty.)

### Email templates
`purchase_receipt_default`, `purchase_receipt_product_default` seeding moves to store migrations.

### Extension seams store *offers* (used by event_manager and future plugins)
- **Product fulfillment providers** (new — see extension point 9).
- **Requirement type registry** (existing `AbstractProductRequirement::register()` + plugin dir scan).
- **Purchase hooks** (`plugins/{name}/hooks/product_purchase.php` scan — loader lives in `products_class` and moves with it; the core stub file becomes convention documentation in store's docs).
- **Purchase signals** (above).

## What moves into `plugins/event_manager/`

### Data classes (table names unchanged)
`events`, `event_registrants`, `event_sessions`, `event_session_files`, `event_types`, `event_waiting_lists`, `locations`.

### Includes
`includes/calendar/item_sources/EventItemSource.php` → `plugins/event_manager/includes/calendar_item_sources/EventItemSource.php` (the registry `includes/calendar/CalendarItemSourceRegistry.php` stays core and already scans plugin dirs; zero registry changes — `plugins/bookings/includes/calendar_item_sources/BookingItemSource.php` is the precedent), the `event_registration` fulfillment provider and `event_registration` access-gate provider (new files, see extension points), and the two ICS route handlers (below).

### Logic
`event_logic`, `events_logic`, `event_register_logic`, `event_sessions_logic`, `event_sessions_course_logic`, `events_profile_logic`, `event_waiting_list_logic`, `event_withdraw_logic`, `my_events_logic`, `location_logic`, plus the new `checkout_submit_survey` logic action (from `checkout_ajax`'s `submit_survey` branch).

### Views
`event`, `events`, `event_waiting_list`, `location`, `profile/events`, `profile/event_sessions`, `profile/event_sessions_course`, `profile/event_withdraw`.

### ICS endpoints
`serve.php:234-307` (`/event/{slug}.ics` + dated variant) and `serve.php:309-367` (`/events/calendar.ics`) are closure routes that `require_once` the event/location classes directly. Their bodies move into `plugins/event_manager/includes/ics_event_route.php` and `ics_calendar_route.php`; the core routes delegate via the `plugin` route option extended to handler routes (extension point 1). URLs unchanged.

### Ajax / Tasks
`session_search_ajax.php` deleted (dead — zero callers); tasks `WeeklyEventsDigest`, `SendPostEventSurveys` (.php + .json) — post-event survey sending is event-side logic that reads the core survey tables (plugin→core, allowed).

### Signals
`event.registered`, `event.waitlisted`, `event.withdrawn` move from root `signals.json` to event_manager's `signals` key (they are dispatched only by event code).

### Settings
`events_active`, `events_label`, `products_list_events_active`, `event_email_inner/outer/footer_template`. The `event` and `location` keys inside the `max_entity_photos` JSON default are seeded by an event_manager migration. (`surveys_active` stays in root `settings.json`.)

### Menus
From `admin_menus.json`: `events` parent + `events-list`, `event-bundles`, `event-types`, `locations`. (`surveys-parent`, `surveys`, `survey-questions` stay core.) ProfileMenu: `core-events` → **`event-manager-events`** (+ `core-event-sessions` → **`event-manager-event-sessions`**, which exists only in the imperative seeds and has no `nativeScreen`); the `nativeScreen` value `events` is preserved. Remove the four moved imperative profile-menu seed rows in `migrations/migrations.php` (~lines 670-673, inside the wider `$core_user_dropdown_rows` block — `core-home` through `core-signout` STAY) — plugin manifests become the single seed source for the moved entries.

### The event↔store integrations (event_manager-side implementations of store seams)
- Ticket fulfillment: the `cart_charge_logic` blocks that construct `new Event(...)` and call `add_registrant()` move into event_manager's fulfillment provider.
- Survey-at-checkout — two distinct blocks, both to event_manager (the requirement *type* itself is a store built-in): the **required-before-purchase** auto-attach (`products_class.php:465-476`) moves into the fulfillment provider's `extraRequirements()`, and the **optional-at-confirmation** collection (`cart_charge_logic.php:851-865`) moves into event_manager's fulfillment Pass-2 code; `evt_svy_survey_id`, `evt_survey_display`, `evr_survey_completed` are event_manager-internal columns.
- Event_manager→store calls that move with their files and need **no seam** (allowed direction, since event_manager depends on store): `events_class.php:224-257` `get_register_url()` (`MultiProduct`, `is_sold_out()`), `events_class.php:340-381` `add_registrant($order_item)` and the `evr_ord_order_id`/`evr_odi_order_item_id` columns, `events_class.php:460-479` `output_product_dropdown()` (the event-edit "registration product" picker), `event_registrants_class.php:129` `remove()`'s `UPDATE odi_order_items SET odi_evr_event_registrant_id = NULL`, and `adm/admin_event.php:508-521` (the registrants panel's Order column).

### Docs
Move to plugin docs: `docs/recurring_events.md` → `plugins/event_manager/docs/`; `docs/product_requirements.md`, `docs/product_purchase_hooks.md` → `plugins/store/docs/`. Add an `overview.md` to each plugin. `docs/questions_surveys.md` stays core.

## What stays in core (and why)

- **Questions & surveys** — `questions`, `question_options`, `surveys`, `survey_questions`, `survey_answers`, `survey_logic`, the `/survey` and `/survey_finish` views, `admin_question*`/`admin_survey*` admin pages, and `surveys_active`. The platform's data-collection primitive (the doctrine is "never hand-roll data-collection forms — use Questions/Surveys"); no survey code references events. Two event-facing edges belong to event_manager, not surveys: the pending-surveys profile/dashboard sections are computed entirely from event registrations (`evr_survey_completed`) and become event_manager dashboard providers (extension point 4), and the "Associated Events" card on `adm/admin_survey.php` renders only when event_manager is active.
- **Subscription tiers (gating)** — `SubscriptionTier` gating surface, `SystemBase::authenticate_tier`, `_tier_min_level` columns on content types, tier feature flags in API session payloads, tier admin (`admin_subscription_tier_edit`, `admin_subscription_tiers` list, `admin_tier_edit` manual assignment). Tiers work with billing absent: admin assigns tier → group membership → gates and `getUserFeature()` resolve.
- **Groups** — core access-control primitive; tiers are built on it; files/videos gate on it.
- **Calendar and scheduling** — `calendar_entry*` is the personal calendar; `schedule`/`schedule_window`/`schedule_override` serve bookings/availability. No events file touches them.
- **Email engine** — `emails`, `queued_email`, `email_templates`, `email_recipient_groups`/`email_recipients` (recipient groups also target core groups; event targeting becomes a provider, below).
- **Analytics, including attribution** — the report stays core; its revenue enrichment (`adm/logic/admin_analytics_attribution_logic.php:81-86` joins `ord_orders` for `ord_total_cost`/refunds) runs only when store is active, and the view's currency symbol comes from `CurrencyHelper`. With store inactive the report renders without revenue columns. (The yearly donations report moves to store — it has no non-store content.)
- **`event_logs` (audit log) and `visitor_events`/`session_analytics` (analytics)** — name collisions only; not the events feature.
- **Videos, files, entity_photos** — content infrastructure; their event-registration gates become providers (below) and the entity-photo class map becomes a registry (point 14).
- **SEO metadata, public menus, page contents, content versions** — page-render infrastructure.
- **IcsHelper + calendar-links vendored lib** — used by the core calendar as well as events. Its location enrichment (`IcsHelper.php:205` hard-requires `locations_class`) is guarded with a plugin-active check and degrades to no-location output when event_manager is absent.
- **`CurrencyHelper`** (new) — the platform currency-symbol map, formerly `Product::$currency_symbols` (~28 references repo-wide switch to it; most move to store anyway, but core keeps `adm/admin_analytics_attribution.php:14` and `migrations/migrations.php:795`). `CurrencyHelper::symbol()` must degrade gracefully (default `'$'`) when `site_currency` is unset — it moves to store's manifest, so a store-less install has no such setting.

## Extension points (the complete set, decided once)

Providers register from each plugin's `serve.php`, which core loads on every request (`RouteHelper::loadPluginRoutes`) — that file is the plugin's request bootstrap. All registries fail soft: no provider registered means the section/option/gate simply isn't there. Points 1–8, 10–14 are core↔plugin seams; point 9 is the store↔event_manager seam.

### 1. Route delegation (`plugin` route option) — new, small
Plugins cannot own top-level dynamic routes (the namespace filter drops them by design). Add a `plugin` option to core route configs so a core-declared route resolves its view (and auto-loaded logic) from a plugin directory:

```php
'/event/{slug}' => ['view' => 'views/event', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
```

The option also applies to **handler routes**: a core route may name a handler file resolved from the plugin directory (used by the two ICS endpoints, whose closures currently live in `serve.php:236-366`).

Core `serve.php` remains the deliberate, auditable registry of top-level URLs; the plugins own the files. Store routes: `/product/{slug}`, plus new explicit entries for URLs currently served by view-directory fallback — `/products`, `/cart`, `/checkout`, `/pricing` — and profile pages `/profile/orders`, `/profile/billing`, `/profile/subscriptions`, `/profile/change-tier`, `/profile/orders_recurring_action`. Event_manager routes: `/event/{slug}/{date}`, `/event/{slug}`, `/location/{slug}`, `/events` (`serve.php:105-107,137`), `/event/{slug}.ics` (+ dated), `/events/calendar.ics`, `/profile/events`, `/profile/event_sessions`, `/profile/event_withdraw`. Each keeps/gains the matching `check_setting`; with the plugin inactive or the setting off, the route 404s as today. (`/survey` stays a plain core route.) The complete verbatim route entries, ordering constraints, and the resolution semantics are in Implementation decisions § Route delegation mechanics. `serve.php` carries `@version 1.2.0` at line 4 — bump it.

### 2. Shopping cart out of the session core
`SessionControl` currently hard-requires `ShoppingCart` (line 9), owns `get_shopping_cart()`/`save_shopping_cart()` (232-240), and captures `?coupon=` URL codes (`capture_marketing_coupon`, ~500-525). All of it moves to `store`:
- `ShoppingCart::current()` (static, store-side) manages `$_SESSION['shopping_cart']` itself; all call sites switch to it.
- Coupon capture and flash-message handling move into store's request bootstrap.
- `SessionControl` keeps zero knowledge of carts or coupons. The `VisitorEvent::TYPE_COUPON_ATTEMPT` analytics write stays available — store calls `$session->save_visitor_event()` like any caller.
- **Theme call sites**: `theme/scrolldaddy/includes/PublicPage.php:170,297,403` and `theme/zoukroom-html5/includes/PublicPage.php:109` call `$session->get_shopping_cart()` directly and would fatal on every page. They switch to the provider-fed `$menu_data['cart']` pattern already used by `empoweredhealth-html5:37` and `linka-reference-html5:145` (see point 3). `theme/scrolldaddy/views/cart_confirm.php:2,17` (a theme view override) also calls it — switch to `ShoppingCart::current()` (the view only renders when store is active). `includes/RouteHelper.php:1068` calls `$session->capture_marketing_coupon()` — remove; store's request bootstrap captures `?coupon=`.

### 3. Header menu providers
`PublicPageBase` builds the header cart link + item-count badge on every page (245-266). Replace with a small static registry, e.g. `PublicPageBase::register_header_menu_provider(callable)`; store registers the cart entry. No provider → no cart in the header. Theme PublicPage subclasses consume the provider data only (never `SessionControl`).

### 4. Profile dashboard section providers
`logic/profile_logic.php`, `logic/profile_dashboard_logic.php`, and `views/profile/profile.php` hand-build the events / orders / subscriptions / pending-surveys sections for both the web profile and the native-app dashboard summary. Add `includes/ProfileDashboardRegistry.php`: providers contribute stat cards and list sections in a declared shape; the web profile view and the dashboard API iterate the registry. Store registers recent orders + active subscriptions; event_manager registers upcoming events + pending surveys (pending-ness is derived from event registrations, so the section is event_manager's even though surveys are core).

### 5. Admin-user panel providers
`adm/admin_user.php` + its logic hand-build Orders, Subscriptions (incl. `StripeHelper::update_subscription_in_order_item`), and Events (add/remove registrant) panels. Add `AdminUserPanelRegistry`: providers receive the `User` and return rendered panels + handle their own POST actions. Store registers orders/subscriptions; event_manager registers events. The Subscription **Tier** card (manual tier display/assignment) stays core.

### 6. Email recipient-group providers
`email_recipient_groups` stays core but its event coupling (`erg_evt_event_id`, `Email::add_recipient_group($evt_event_id, ...)`, and the admin send UI resolving event registrants / waiting lists) generalizes:
- Columns: **both** `erg_evt_event_id` **and** `erg_grp_group_id` fold into `erg_provider` (varchar) + `erg_reference_id` (int) — the group provider must resolve through the registry too, or it stays a special case. Core ships the `group` provider; event_manager registers `event` and `event_waiting_list` providers.
- `RecipientGroupProviderRegistry` supplies both resolution (id list) and the admin UI's targeting options (label + picker).
- Touch points to convert: `data/emails_class.php` (95-115), `adm/admin_email.php`, `admin_email_recipients_modify.php`, `admin_emails_queue.php`, `adm/logic/admin_users_message_logic.php`, **and the `adm/admin_users_message.php` view itself** (it independently requires `events_class` and reads `evt_name` at lines 6,35,68,88 — its targeting UI becomes provider-fed).

### 7. Content access-gate providers
Videos and files gate on event registration via schema-level FKs (`vid_evt_event_id`; `fil_evt_event_id` + direct `esf_`/`MultiEventSessions`/`MultiEventRegistrant` queries in `files_class.php` 1302-1620). Generalize:
- Columns: `vid_evt_event_id` → `vid_access_provider` + `vid_access_ref`; `fil_evt_event_id` → `fil_access_provider` + `fil_access_ref`.
- `AccessGateRegistry`: a provider answers `userMayAccess($user_id, $ref)` and supplies the admin picker. Provider-supplied picker options replace the direct event queries across the admin UI: `admin_video_edit.php:7,167-179` + `adm/logic/admin_video_logic.php:9,71-85`, `admin_file.php:125-145`, `admin_file_edit.php:109-121`, `adm/logic/admin_file_logic.php:85-134`. Event_manager registers the `event_registration` provider.
- **File↔event-session attachment is a separate concern, NOT behind the gate**: the gate (`is_viewable`) never consults `esf_event_session_files` (verified — it gates only via `MultiEventRegistrant`). The attach/detach POSTs (`adm/logic/admin_file_logic.php:25-42` `fileadd`/`fileremove`, `admin_file_upload_process_logic.php:199-204`) and `get_event_sessions()` move to event_manager as plugin-owned admin logic, rendered only when the plugin is active.
- **Fail closed**: a gate whose provider is absent denies access.
- Group and tier gates (`fil_grp_group_id`, `*_tier_min_level`) are core and unchanged.

### 8. SEO entity registration
`SeoPageMetadata::ENTITY_CLASSES` hard-wires event/product/location (used by the sitemap, `enumerate_public_paths`, and admin SEO pages). Convert to `SeoPageMetadata::register_entity_class($type, $class, $file, $admin_edit_url)` — the registration carries the admin edit-URL pattern, replacing the hardcoded map in `adm/logic/admin_seo_page_edit_logic.php:80`. Core registers post/page/video/mailing_list, store registers `product`, event_manager registers `event` and `location`. `views/site-directory.php`'s Events and Locations sections gate on `events_active` (the Events one already does).

### 9. Product fulfillment providers (the store↔event_manager seam)
Today a product *is* an event ticket via `pro_evt_event_id`, and checkout hand-runs registration. Generalize so store never knows events exist:
- Columns: `pro_evt_event_id` → `pro_fulfillment_provider` (varchar) + `pro_fulfillment_ref` (int).
- `FulfillmentRegistry` (store-owned): a provider supplies the admin product-edit picker (label + options), a `fulfill($user, $product, $order_item, $order)` called on successful purchase, and display hooks for everywhere product admin currently renders the linked event: the picker in `admin_product_edit.php:71`, the event name/date render in `admin_product.php:76-80`, the listing in `admin_products.php:7,54` + `adm/logic/admin_product*_logic.php` requires, and `admin_order_item_edit`'s direct Event/EventRegistrant lookups.
- Event_manager registers `event_registration`: fulfill creates the `EventRegistrant`, handles bundle groups, and attaches the event's survey requirement per `evt_survey_display`.
- Event-bundle group membership (`pro_grp_group_id` → core `Group`) is a store→core call and stays in store.
- The `products_list_events_active` gate on the products listing becomes an event_manager-registered listing filter or stays as a simple setting check event_manager seeds — implementer's choice; no store→events reference either way.

### 10. Tier split (core keeps gating, store owns billing)
`SubscriptionTier` keeps: `GetUserTier`, `UserHasMinimumTier`, `getUserFeature`, feature JSON accessors, `getAllAvailableFeatures`, `getUserTierDisplay`, `requireMinimumTier`, `addUser`, `removeUserFromAllTiers`, `save` (group creation), `MultiSubscriptionTier::GetAllActive`.

Moving to `plugins/store/includes/TierBilling.php`: `handleProductPurchase`, `getUpgradeOptions`, `userHasActiveSubscription`, `handleSubscriptionExpired`. They call the core primitives (`addUser`, `removeUserFromAllTiers`, `GetUserTier`) — grant/revoke flows only ever cross the boundary through those. Callers (`cart_charge_logic:744`, `change_tier_logic:239`, both webhooks) are all store-side after the move.

Core degrades: `SystemBase::authenticate_tier` (line 1762) stops calling `getUpgradeOptions`; the upgrade CTA in `includes/tier_gate_prompt.php` (58-72, hardcoded `/product/{id}` links) is replaced by a settings-driven link — new core setting `tier_upgrade_url` (empty default → the existing "Contact us to learn about upgrading" fallback). Store seeds `tier_upgrade_url = /pricing`. The "Products Granting Tiers" panel in `adm/admin_subscription_tiers.php` (121-147, raw SQL on `pro_products`) moves to store's product admin. `pro_sbt_subscription_tier_id` stays a store-side column referencing a core table — that direction is fine.

The tier admin's **gated-content summary** (`adm/admin_subscription_tiers.php:87-104`) hardcodes a table map including `evt_events`/`evt_tier_min_level` — raw SQL that errors once the plugin owns the table. The map becomes registrations: core registers its gated content types; event_manager registers events; store registers products.

Also: move the ScrollDaddy tier-feature definitions from `theme/scrolldaddy/tier_features.json` (never discovered — `getAllAvailableFeatures` only scans `plugins/*/tier_features.json`) to `plugins/dns_filtering/tier_features.json`. Pre-existing wiring gap; fix it while touching this area.

### 11. Stripe customer identity off the users table
`usr_stripe_customer_id` / `usr_stripe_customer_id_test` and `User::GetByStripeCustomerId` are read/written only by billing code. Replace with a store table `stripe_customers` (`stc_`: `stc_usr_user_id` unique, `stc_customer_id`, `stc_customer_id_test`, both indexed varchar(64)) and a `StripeHelper` accessor. Update `billing_logic:58`, `admin_user_payment_methods`, the two stripe utils, the tier tester, remove the two columns + `GetByStripeCustomerId` from `data/users_class.php`, and update `tests/unit/deletion_rule_source_table_test.php` (references `usr_stripe_customer_id`). The backfill runs in `plugins/store/activate.php` (NOT a core migration — the plugin table doesn't exist at migration time); mechanics in Implementation decisions § Schema-change mechanics.

### 12. Polymorphic references replacing event FKs on core tables
- `messages.msg_evt_event_id` → `msg_context_type` + `msg_context_id` (generic entity-attached message context; event_manager writes `event`). Display resolves through a small context-resolver registration: `adm/admin_message.php:9,21` currently requires `events_class` and instantiates `Event` to label the context — with no resolver registered the context renders as plain type/id text.
- `session_analytics.sev_evt_event_id` / `sev_evs_event_session_id` → `sev_entity_type` + `sev_entity_id`; event_manager-side analytics writes use the generic pair.
- Prune the legacy `sva_*` column mapping in `includes/DatabaseUpdater.php:2000-2002`.

### 13. Platform fixes required by any extraction
- **REST API model discovery**: `api/apiv1.php:350` calls `discover_model_classes()` without `include_plugins`, so plugin data classes are invisible to `/api/v1` model endpoints (already silently affects mailbox classes). Change to `discover_model_classes(['include_plugins' => true])`. Per-class `api_readable`/`api_writable` authorization unchanged. The AI surface (`joinery_ai` ModelRegistry) already scans plugin data dirs.
- **Maintenance tooling discovery**: `utils/fix_sequences.php:161` and `utils/fix_duplicate_keys.php:196` call discovery without `include_plugins` and would silently skip the moved tables. Same fix.
- **FormWriter prefix map**: `FormWriterV2Base::getModelPrefixMap()` (`:655`) globs only core `data/*_class.php`; extend it to scan `plugins/*/data/` so `pro_`/`ord_`/`evt_`/`loc_`-prefixed form fields keep auto-detected validation on the moved admin forms.
- **Deletion-rule lifecycle — verify and regression-test, no code change needed**: the moved child models' FK rules (`ord_usr_user_id`→scrub, `odi_pro_product_id`→prevent, `evt_loc_location_id`→null, event_registrants→users) are registered by `PluginManager::sync()` (`:1231` → `registerAllActiveDeletionRules()`) since core registration runs `include_plugins=false`. Verified against code: `pruneOrphanedRules()` (`deletion_rule_class.php:294-322`) already keys on **filesystem** table existence (`getModelRegistry()` scans all `plugins/*/data/` regardless of active state), and runtime deletion (`permanent_delete` + `getModelClassForTable()`, `SystemBase.php:928-1164`) resolves through persisted `del_deletion_rules` rows + filesystem-wide class loading — deactivating store does NOT stop user deletion from scrubbing orders. The deliverable is a regression test (extend `tests/integration/deletion_rule_registration_test.php`): delete a user with store registered-but-inactive, assert orders are scrubbed.
- **Theme requirement enforcement**: (a) add `"requires_plugins": ["event_manager"]` to `phillyzouk-html5` (its `logic/index_logic.php:7,21` and `views/events.php:10-33` + `views/index.php:88-142` are event-coupled) and `zoukroom-html5` (`views/events.php:8,25`, `views/event.php:9,127` require event classes; `includes/PublicPage.php:102` hardcodes `/events` nav); (b) extend `ThemeManager::activate()` to enforce `requires_plugins` (today only enforced against plugin *deactivation*); (c) guard the requires with a plugin-active check anyway. `theme/devonnearhill-html5/views/index.php:38,92` has hardcoded `/events` CTAs — same treatment (requires_plugins or link removal, implementer's choice). `theme/scrolldaddy` needs no `requires_plugins` — its coupling is only the cart calls fixed in point 2.
- **Historical migration guard**: `migrations/migrate_entity_photos.php:16-17` references `evt_events`/`loc_locations`; add a table-exists guard so it no-ops where the plugin tables are absent.

### 14. Entity-photo entity registration
`ajax/entity_photos_ajax.php:127-143` hardcodes an entity→class map (`'event' => data/events_class.php`, `'location' => data/locations_class.php`, ...) and `require_once`s the files to sync primary photos — the first photo upload for an event or location would fatal after the move. Convert the map to a registry mirroring point 8: core registers its own entity types; event_manager registers `event` and `location`; unregistered types are rejected. (The `max_entity_photos` JSON keys are already seeded per-plugin, per the settings section.)

## Install and upgrade path

**Fresh installs** get the desired state for free: newly discovered plugins register **inactive** (`AbstractExtensionManager::sync()` → `PluginManager::getDefaultStatus()` = `'inactive'`; nothing auto-activates). Two installer fixes are required so a fresh install actually completes:
- `maintenance_scripts/install_tools/_site_init.sh:337` — `REQUIRED_TABLES` asserts `evt_events` and `pro_products` exist; drop both (they are plugin tables now, absent by default).
- `_site_init.sh:358` — `CRITICAL_SETTINGS` asserts `events_active`; drop it (plugin-seeded now).
- Regenerate the seeded install image (`joinery-install.sql.gz`) from a post-extraction fresh install so it reflects the core-only schema with both plugins registered inactive.

**Existing installs (the critical gap): a one-time conditional activation step ships in the same release as the moves.** On upgrade, the new plugin directories register inactive, which would silently kill checkout, webhooks, and event pages on live sites. A numbered migration CANNOT do this — migrations run at `update_database.php` Step 4 (`:374-505`), before `PluginManager::sync()` at `:703` registers the plugins. The activation hook is inline code in `update_database.php` immediately after the sync block (`:735`), one-time via a marker `stg_settings` row — full mechanism in Implementation decisions § Upgrade activation. The conditions:
- Activate `store` if any row exists in `pro_products` or `ord_orders`, or `stripe_api_key`/`paypal_api_key` is set non-empty (those are the real setting names — `stripe_secret_key`/`paypal_client_id` do not exist).
- Activate `event_manager` if any row exists in `evt_events` (activating `store` first to satisfy the dependency).
- Genuinely store-less installs stay clean — nothing activates.

This also covers the getjoinery.com control plane: `server_manager`'s `PollHostingOrders` reads orders from it remotely over `/api/v1`, so that deployment must end the upgrade with `store` (and `event_manager`) active — which the data-evidence rule guarantees.

## Dependent plugins

- **event_manager** → `"depends": { "store": ">=1.0.0" }`.
- **dns_filtering** → depends on `store` (profile page reads `MultiOrder`/`MultiOrderItem`; ScrollDaddy sells subscriptions). Cleanup opportunity, not required: its profile clone also copied the core profile's event sections — strip those rather than depending on event_manager.
- **bookings** → depends on `store` only (`bkt_pro_product_id`/`bkn_pro_product_id`). Its intake surveys (`bkt_svy_survey_id`, survey-answer writes) reference the core survey tables — no event_manager dependency; paid bookings with intake surveys work on a store-only install.
- **server_manager** → **no dependency.** `PollHostingOrders` reads orders, requirement answers, and users from a *remote* getjoinery site via `GetJoineryApiClient` (HTTP + API keys); it loads no local store or event_manager classes. The requirement lands on the remote deployment instead: it must run store + event_manager and expose `OrderItemRequirements`/`OrderItem`/`User` through `/api/v1` — which the `include_plugins` model-discovery fix (extension point 13) preserves after those classes move into plugins.
- **items** → **no dependency; fix a broken page instead.** `plugins/items/admin/admin_item_relation_types.php:25` instantiates `MultiProductGroup` but then reads `itr_name` (an item-relations column) from the rows and links to a nonexistent `/admin/admin_item_relation_edit` page — vestigial scaffolding. Repair the page to use the plugin's own `item_relation_types_class.php` as part of this work.

## Native apps

The native member screens call API actions that become plugin actions: `order_list`, `subscription_summary` → `/api/v1/action/store/...`; `my_events` → `/api/v1/action/event_manager/...`. Update the endpoint constants in `JoineryMemberKit` (`{repo root}/ios/`) and the Android member app, and rerun `tests/functional/android/member_gate.sh` + `tests/functional/api/member_screens_test.php`. Navigation is unaffected: profileMenu slugs and `nativeScreen` values are preserved through the plugin manifests, and the app navigation endpoint reads the same menu store.

## Test estate and tooling

- **Model test runners**: `tests/models/run_multi.php:37`, `tests/models/models_test.php:31`, `tests/models/run_all.php:65`, `tests/models/run_automated.php:35` all call `discover_model_classes()` without `include_plugins` — after the move, `MultiProduct`/`MultiOrder`/`MultiEvent`/`MultiLocation` and their CRUD tests would silently vanish from coverage. Pass `['include_plugins' => true]` in all four.
- **Suites that move**: `tests/functional/products/` and `tests/functional/subscription_tiers/` → `plugins/store/tests/` (auto-discovered; headers preserved).
- **Requires to repoint** (files stay core, paths change): `tests/functional/products/ProductTester.php` (StripeHelper + products/order_items/events/coupon_codes/orders classes), `tests/functional/subscription_tiers/SubscriptionTierTester.php:16-29`, `tests/integration/deletion_rule_registration_test.php:161` (`orders_class` — it uses the user→orders rule as its cross-table control, which now also exercises the plugin-owned-rule path), `tests/integration/routing_test.php:419,675,711`, `tests/functional/api/member_screens_test.php:26-30`.

## Execution order

1. **Platform groundwork, with everything still in core**: the sync-time collision validator; `CurrencyHelper`; the model-discovery fixes (apiv1, fix_sequences, fix_duplicate_keys, four model test runners); FormWriter prefix map; deletion-rule prune behavior; installer validator updates (`_site_init.sh`).
2. **Core extension points, still in core** (route `plugin` option incl. handler routes; header/dashboard/admin-user/recipient-group/access-gate/SEO/fulfillment/entity-photo registries; tier gated-content registrations; cart out of SessionControl + theme PublicPage conversions; tier split with `TierBilling` still in `includes/`; polymorphic columns + message context resolver; theme `requires_plugins` enforcement). Everything keeps working; each seam is verifiable independently.
3. **Move the store** into `plugins/store/` (files, tasks, tests, settings, menus, signals, templates, utils, docs; `checkout_ajax` → API actions; webhooks to plugin ajax at unchanged URLs); add `depends` to dns_filtering and bookings; ship the store activation upgrade step; update app endpoint constants. Verify core-without-store and store-active states.
4. **Move event_manager** into `plugins/event_manager/` (files, tasks, settings, menus, ICS handlers, docs), registering its fulfillment/gate/recipient/dashboard/SEO/entity-photo providers; `session_search` → API action; ship the event_manager activation upgrade step. Verify all three states.
5. Regenerate `joinery-install.sql.gz`; update docs; move this spec to `specs/implemented/` and update `plugin_ajax_namespace_collision.md` to reflect what shipped here.

## Acceptance criteria

With **both plugins inactive** (fresh-install state):
- Every public page and admin page loads with no fatals; no cart in the header; `/products`, `/cart`, `/pricing`, `/events`, `/event/x`, `/events/calendar.ics` 404.
- A fresh `_site_init.sh` install completes and passes its own validation.
- Questions/surveys work end-to-end with no plugins: create questions and a survey in admin, `/survey` collects answers, results render.
- Tier admin works end-to-end: create tier, set features, manually assign a user, `authenticate_tier` gates content, gate prompt renders the contact-us fallback (or `tier_upgrade_url` if set), `getUserFeature` resolves; the gated-content summary renders core types only.
- Sitemap, site directory, admin email send, video/file admin, profile, admin-user, message admin, and analytics attribution (without revenue columns) all render without the moved sections/options; no pending-surveys section on profile or dashboard; `adm/admin_survey.php` renders without the Associated Events card.
- dns_filtering, bookings, event_manager refuse to activate (missing dependency); server_manager and items activate and function (no local dependency).

With **store active, event_manager inactive**:
- Products, cart, checkout (via the new `/api/v1/action/store/...` actions), coupons, subscriptions, billing portal, and tier purchase (`TierBilling::handleProductPurchase` → `SubscriptionTier::addUser`; webhook revoke via `handleSubscriptionExpired`) all work. Stripe and PayPal webhooks respond at their **unchanged** `/ajax/` URLs. The subscription cron tasks (`ReconcileStripeSubscriptions`, `SyncPaypalSubscriptions`) run from the plugin. The product-edit fulfillment picker and requirement types show only store-provided options (including the Question/Survey built-ins backed by core tables). Attribution shows revenue; the donations report works from store admin. dns_filtering activates and functions.
- bookings activates and functions: paid bookings and intake surveys work with no events system installed.
- **Deactivating store does not break data integrity**: deleting a user still scrubs their orders per the registered deletion rules.

With **both active**:
- All preserved URLs render, including both ICS endpoints; event-ticket purchase creates registrants through the fulfillment provider; event survey requirements collect at checkout; pending surveys appear on profile/dashboard via the event_manager provider; events feed the core calendar via the plugin item source; recipient-group email targeting of registrants works (including the admin compose UI); gated videos/files enforce registration with provider-supplied pickers; event/location photo uploads work through the entity-photo registry; scrolldaddy and zoukroom-html5 themes render every page (provider-fed cart); native member screens pass their gate tests.
- **Upgrade simulation**: a database with existing orders and events data, upgraded through `update_database`, ends with both plugins active and all of the above working with no manual step.
- Plugin sync fails with a named-file error when two active participants declare the same flat `ajax/`/`utils/`/`tests/` basename.
- `tests/functional/products` and `tests/functional/subscription_tiers` (from their new plugin homes), `tests/functional/api/member_screens_test.php`, `tests/integration/routing_test.php`, `tests/integration/deletion_rule_registration_test.php`, `tests/models/run_multi.php` (enumerating the moved Multi classes) all pass.

## Documentation updates (same change, current-state voice)

- `docs/routing.md` — the `plugin` route option (view + handler forms); plugin browser endpoints are API actions; flat `ajax/`/`utils/`/`tests/` namespace for webhooks/utils/tests with sync-validated basenames.
- `docs/api.md` — model discovery includes plugin data classes.
- `docs/subscription_tiers.md` — tiers as core gating + group membership; billing integration described via the `TierBilling` seam and `tier_upgrade_url`; gated-content summary registrations.
- `docs/email_system.md` — recipient-group providers.
- `docs/questions_surveys.md` — stays core; describe the requirement-type integration as store-provided.
- `docs/analytics.md` — attribution revenue enrichment as store-dependent.
- `docs/deletion_system.md` — plugin-owned deletion rules persist by class-file existence.
- `docs/admin_pages.md` — admin-user panel registry; `docs/plugin_developer_guide.md` — all new registries, fulfillment providers, `requires_plugins` enforcement, header/dashboard providers, the collision validator, endpoints-as-API-actions.
- New `plugins/store/docs/overview.md` and `plugins/event_manager/docs/overview.md`; moved docs listed above.
- `specs/plugin_ajax_namespace_collision.md` — trim to its remaining scope (the four plugins' page-JS migrations) once the validator ships here.
- CLAUDE.md documentation index — update via the admin agent-files interface (never on disk).

---

# Implementation decisions (binding — follow as written)

Everything below was researched against the live code and database before handoff. These are decisions, not suggestions; where they name a line number it was verified. If code has drifted from a cited line, find the same construct — do not re-open the decision.

## Route delegation mechanics

Grounding: `RouteHelper::processRoutes()` order is static → theme serve.php → plugin serve.php → custom closures (`:1163`) → dynamic view routes (`:1247`) → view-directory fallback (`:1278`) → 404; first insertion-order match wins (`matchRoute` `:321-338`). `min_permission` enforces at match time (`:326`), `check_setting` inside `handleDynamicRoute()` (`:416`). View resolution already calls `PathHelper::getThemeFilePath(basename, dirname, 'system', null, $plugin_name_for_view, false, false)` (`:520`) with chain **theme → plugin → base** (`PathHelper.php:254-302`).

**The `plugin` option (view form)** — one new branch in `handleDynamicRoute()`:
1. After the `check_setting` gate: `if (!PluginHelper::isPluginActive($route['plugin'])) return false;` (falls through to 404). `PluginHelper::isPluginActive()` exists (`PluginHelper.php:452`). This is the authoritative inactivity guard, independent of whether the gating setting row still exists.
2. Set `$plugin_name_for_view = $route['plugin']` (overriding `extractPluginNameFromPattern()`). Keep `view` values as plain `views/{name}` paths — `getThemeFilePath` prepends the plugin dir itself. **Themes can still override plugin-delegated views** (theme wins the chain, unchanged).

**Logic loading**: the router never auto-loads logic; each view requires its own via `getThemeFilePath('{name}_logic.php', 'logic')`. Every moved view changes that call to pass the plugin explicitly: `getThemeFilePath('{name}_logic.php', 'logic', 'system', null, '{plugin}', false)` — resolving theme → `plugins/{plugin}/logic/` → core. No new autoload mechanism.

**The `handler` form (ICS endpoints)**: same gates, then resolve the handler path through the same `getThemeFilePath` call, `require_once` it, `return true`. The handler reads named params from the standard `$params` rebind (`$params['slug']`, `$params['date']`) — replacing today's `array_slice` URL parsing. `buildRouteRegex` (`:673-693`) handles `/event/{slug}.ics` → `#^/event/(?P<slug>[^/]+)\.ics$#` correctly (verified by trace). Existing closures are untouched; only the two ICS closures are retired.

**Verbatim route entries** (dynamic array; `.ics` entries MUST precede `/event/{slug}`, profile entries MUST precede the `/profile/*` wildcard at serve.php:136):

```php
// ---- event_manager: ICS handler routes (BEFORE '/event/{slug}') ----
'/event/{slug}/{date}.ics' => ['handler' => 'includes/ics_event_route',    'plugin' => 'event_manager', 'check_setting' => 'events_active'],
'/event/{slug}.ics'        => ['handler' => 'includes/ics_event_route',    'plugin' => 'event_manager', 'check_setting' => 'events_active'],
'/events/calendar.ics'     => ['handler' => 'includes/ics_calendar_route', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
// ---- event_manager: views (add 'plugin' to existing serve.php:105-107,137 entries) ----
'/event/{slug}/{date}'     => ['view' => 'views/event',    'plugin' => 'event_manager', 'check_setting' => 'events_active'],
'/event/{slug}'            => ['view' => 'views/event',    'plugin' => 'event_manager', 'check_setting' => 'events_active'],
'/location/{slug}'         => ['view' => 'views/location', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
'/events'                  => ['view' => 'views/events',   'plugin' => 'event_manager', 'check_setting' => 'events_active'],
// ---- store: views ('/product/{slug}' exists at serve.php:108 — add 'plugin') ----
'/product/{slug}'          => ['view' => 'views/product',  'plugin' => 'store', 'check_setting' => 'products_active'],
'/products'                => ['view' => 'views/products', 'plugin' => 'store', 'check_setting' => 'products_list_items_active'],
'/cart'                    => ['view' => 'views/cart',     'plugin' => 'store', 'check_setting' => 'products_active'],
'/checkout'                => ['view' => 'views/checkout', 'plugin' => 'store', 'check_setting' => 'products_active'],
'/pricing'                 => ['view' => 'views/pricing',  'plugin' => 'store', 'check_setting' => 'subscriptions_active'],
// ---- profile (BEFORE the '/profile/*' wildcard, serve.php:136) ----
'/profile/orders'                 => ['view' => 'views/profile/orders',                 'plugin' => 'store',         'check_setting' => 'products_active'],
'/profile/billing'                => ['view' => 'views/profile/billing',                'plugin' => 'store',         'check_setting' => 'subscriptions_active'],
'/profile/subscriptions'          => ['view' => 'views/profile/subscriptions',          'plugin' => 'store',         'check_setting' => 'subscriptions_active'],
'/profile/change-tier'            => ['view' => 'views/profile/change-tier',            'plugin' => 'store',         'check_setting' => 'subscriptions_active'],
'/profile/orders_recurring_action'=> ['view' => 'views/profile/orders_recurring_action','plugin' => 'store',         'check_setting' => 'subscriptions_active'],
'/profile/events'                 => ['view' => 'views/profile/events',                 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
'/profile/event_sessions'         => ['view' => 'views/profile/event_sessions',         'plugin' => 'event_manager', 'check_setting' => 'events_active'],
'/profile/event_withdraw'         => ['view' => 'views/profile/event_withdraw',         'plugin' => 'event_manager', 'check_setting' => 'events_active'],
```

**New files**: `plugins/store/serve.php` and `plugins/event_manager/serve.php` are new deliverables (only `plugins/server_manager/serve.php` exists today). They are the plugins' request bootstraps: loaded per-request for active plugins inside `ob_start()/ob_end_clean()` (`RouteHelper::loadPluginRoutes` `:1406-1486`) — output discarded, static registrations persist. Store's also runs coupon capture (`?coupon=`) and flash handling from extension point 2.

## Endpoint replacements (exact)

`api_resolve_logic_path()` (`apiv1.php:129-172`) maps `/api/v1/action/{plugin}/{action}` → `plugins/{plugin}/logic/{action}_logic.php` (function `{action}_logic()` + `{action}_logic_descriptor()`); inactive/unknown plugin returns the same 404 as a missing action. Descriptor pattern reference: `logic/cart_clear_logic.php:32-41`; docs/api.md:284-307, 694-726.

| New file | Action | Descriptor essentials | Replaces |
|---|---|---|---|
| `plugins/store/logic/checkout_apply_coupon_logic.php` | `POST /api/v1/action/store/checkout_apply_coupon` | browser-session required, mutates, input `coupon_code` (string, required); returns `{success, coupon_codes, total}` (symbol via `CurrencyHelper`) | `checkout_ajax` `apply_coupon` (caller views/checkout.php:558) |
| `plugins/store/logic/checkout_remove_coupon_logic.php` | `…/store/checkout_remove_coupon` | same shape | `remove_coupon` (views/checkout.php:578) |
| `plugins/store/logic/checkout_check_email_logic.php` | `…/store/checkout_check_email` | browser-session ONLY (refuse API keys — email-enumeration oracle), guest-reachable, read-only, input `email`; returns `{exists}`; JS converts GET→JSON POST | `check_email` (views/checkout.php:597) |
| `plugins/event_manager/logic/checkout_submit_survey_logic.php` | `…/event_manager/checkout_submit_survey` | requires logged-in session, mutates, input `survey_id` (int, required) + `event_id` (int, optional) + per-question fields; persists via the requirement interface, sets `evr_survey_completed` | `submit_survey` (views/cart_confirm.php:184) |

All four coupon/cart operations use `ShoppingCart::current()`. JS callers switch to JSON POST with the `X-Joinery-Csrf` header from the `joinery-api-csrf` meta tag. Delete: the `validate_section` branch, `validate_checkout_section()` (`checkout_logic.php:237-239` region), `ajax/checkout_ajax.php`, and `ajax/session_search_ajax.php` (zero callers).

## Collision validator

`protected function validateFlatNamespaceCollisions(): void` on `PluginManager`, called at the top of `sync()` **immediately after** `$result = parent::sync($options);` (`:1148`) and **before** the DatabaseUpdater loop (`:1158`) — so the active-plugin set is fresh and no schema changes have run when it throws. Build a map keyed `{dir_type}/{basename}` for `dir_type ∈ {ajax, utils, tests}` across core `ajax/*.php`/`utils/*.php`/`tests/*.php` plus each **active** plugin's same dirs (`new MultiPlugin(['plg_active'=>1])`, the `:1161` filter). On a duplicate, `throw new Exception(...)` (the file's error convention — `:653,:688,:782`); the exception propagates and aborts the sync before any table mutation. Message names both participants and the file:

```
"Plugin sync aborted: flat-namespace collision on {dir_type}/{basename} — declared by both
'{first}' and '{second}'. Files in ajax/, utils/, and tests/ resolve at a shared global URL,
so their basenames must be unique across core and all active plugins. Rename one file."
```

Newly discovered plugins register inactive and don't trip the check until activated — matching the acceptance criterion ("two **active** participants").

## Registry contracts

House style: all new registries use the explicit-`register()` pattern (precedents: `AbstractProductRequirement::register()`, `SignalBus`) with a per-request static store and a `resetCache()` for tests — because providers register from plugin `serve.php` on every request. Registration is idempotent (last-wins by key). All fail soft except AccessGate (fail-closed).

### Header menu (point 3)
Static members on `PublicPageBase` (no new file): `register_header_menu_provider(string $key, callable $provider)`; provider is `function(SessionControl $session): ?array`. `get_menu_data()` (`:245-268`) replaces the hard-coded cart block with a loop setting `$menu_data[$key] = $provider($session)` when non-null. **Cart payload keeps today's exact field names** — `['enabled','count','item_count','total_items','subtotal','link','has_items']` — so `empoweredhealth-html5:37` and `linka-reference-html5:145` need zero edits (their `isset($menu_data['cart'])` fallback also covers store-inactive). Store provider: `plugins/store/includes/header_menu_cart_provider.php`, function `store_header_menu_cart_provider()`, computes from `ShoppingCart::current()`, registered in store's serve.php under key `'cart'`.

### Profile dashboard (point 4)
`includes/ProfileDashboardRegistry.php`: `register(string $id, callable $provider)` where provider is `function(User $user): ?ProfileDashboardSection`; `sections(User $user): array` returns non-null results in registration order. Value objects (same file or `includes/ProfileDashboardSection.php`): `ProfileDashboardSection {id, title, ?view_all_url, ?ProfileDashboardStat stat, ProfileDashboardItem[] items}`; `ProfileDashboardStat {key, label, count, ?link}`; `ProfileDashboardItem {array data, title, ?subtitle, ?meta (may be safe HTML), ?badge, ?url}`.

**Native-app serialization is load-bearing**: `profile_dashboard_logic.php` emits `$out[$section->id] = array_map(fn($i)=>$i->data, $section->items)` plus `$out[$stat->key] = $stat->count`. The `data` payloads must reproduce today's keys EXACTLY: `recent_orders[{order_id,total,date}]`, `subscriptions[{order_item_id,product_name,price,status}]`, `active_subscription_count`, `upcoming_events[{registrant_id,event_id,event_name,next_session_time,expires_time,web_url}]`, `upcoming_event_count`, `pending_surveys[{survey_id,event_id,event_name}]`. Web profile (`views/profile/profile.php`) replaces the bespoke section HTML (events 140-168, orders 244-263, subscriptions 293-317) with one generic loop; the actions banner (79-103) reads `pending_surveys` items' `data['survey_id']`/`['event_id']`.

Providers: store (`plugins/store/includes/profile_dashboard_provider.php`) registers `recent_orders` (gate `products_active`; `MultiOrder(['user_id'=>…],['ord_order_id'=>'DESC'],3)`) and `subscriptions` (gate `products_active && subscriptions_active`; stat from `MultiOrderItem(['user_id'=>…,'is_active_subscription'=>true])->count_all()`; status = `odi_subscription_cancelled_time ? 'cancelled' : (odi_subscription_status ?: 'active')`). Event_manager registers `upcoming_events` + `pending_surveys` from one `MultiEventRegistrant(['user_id'=>…,'deleted'=>false],['evr_create_time'=>'DESC'])`; active = not expired (`evr_expires_time < now_utc`) and `evt_status == Event::STATUS_ACTIVE`; pending-survey derivation verbatim from current code: skip if `evr_survey_completed`, skip if no `evt_svy_survey_id`, include when `evt_survey_display ∈ {optional_at_confirmation, after_event}`, and for `after_event` require `evt_end_time` (fallback `evt_start_time`) `<= now_utc`. Core keeps building user card, conversations, mailing lists, notifications directly — NOT registry-driven.

### Admin-user panels (point 5)
`includes/AdminUserPanelRegistry.php`: `register(AdminUserPanel $panel)` keyed by `id()`; `panels(): array`; `handlePost(User $user, array $input): ?LogicResult` matches `$input['action']` against each panel's `actions()` and calls its `handle()`. Interface: `id(): string`, `render(User $user, AdminPage $page): string` (returns HTML; build via buffer when using the AdminPage table API), `actions(): array`, `handle(string $action, User $user, array $input): LogicResult` (returns `LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key)` — the current PRG pattern).

Ground truth: **only the events panel has POST actions** (`add_to_event`/`remove_from_event`, `admin_user_logic.php:89-99`); orders and subscriptions panels are read-only (`actions() == []`), and `StripeHelper::update_subscription_in_order_item()` runs during *render* (`admin_user.php:333`), not POST. Edits: `admin_user_logic.php:75-101` — after the core group branches, `$r = AdminUserPanelRegistry::handlePost($user, $input); if ($r) return $r;`, remove the event branches; the order/subscription loading (121-162) moves into provider `render()`. `admin_user.php` — replace Orders (451-503), Subscriptions (321-385), Events (391-449) blocks with the panels loop. The Tier card (253-319) and Groups stay core inline. Providers: `plugins/store/includes/admin_user_panels/OrdersPanel.php` (`store_orders`), `SubscriptionsPanel.php` (`store_subscriptions`), `plugins/event_manager/includes/admin_user_panels/EventsPanel.php` (`event_registrations`).

### Recipient groups (point 6)
`includes/RecipientGroupProviderRegistry.php`: `register(RecipientGroupProvider $p)`, `get(string $key)`, `all()`. Interface: `key()`, `label()`, `options(): array` (reference_id => label for the picker), `resolve(int $reference_id): array` (user ids; `[]` if unresolvable), `reference_label(int $reference_id): string`.

Resolution semantics: `group` (core, `includes/recipient_group_providers/GroupRecipientProvider.php`) — options from `MultiGroup(['category'=>'user','deleted'=>false])->get_dropdown_array()`, resolve via `Group::get_member_list()` collecting `grm_foreign_key_id`. `event` (event_manager) — `MultiEventRegistrant(['event_id'=>$id,'expired'=>false])` collecting `evr_usr_user_id`. `event_waiting_list` — `MultiWaitingList(['event_id'=>$id])` collecting `ewl_usr_user_id`.

Edits: `emails_class.php:97-120` — `add_recipient_group(string $provider, int $reference_id, string $op='add')`, duplicate check on `['erg_provider','erg_reference_id','erg_eml_email_id','erg_operation']`. `email_recipient_groups_class.php:36,53-81` — columns + filter options. Display/resolution dispatch replaces the group/event if-else at `admin_email.php:82-100`, `admin_email_recipients_modify.php:82-99`, `admin_emails_queue.php:59-74,82-99` (note the bare `else`-assumes-event at the remove-list — becomes explicit dispatch). Picker: `admin_email_recipients_modify.php:133-192` — the two hard-coded forms collapse to one loop over `all()` with hidden `action='add_recipient'` + `provider` + `reference_id`; the `addgroup`/`addevent` handlers collapse to one `add_recipient` handler. `admin_users_message.php` + its logic (direct-send path): targeting UI provider-fed; `evt_name` reads (view lines 6,35,68,88) become `reference_label()`.

### Access gates (point 7)
`includes/AccessGateRegistry.php`: `register()`, `get()`, `all()`, and `userMayAccess(?string $provider, $ref, int $user_id): bool` with exact semantics: **null/empty provider → `true`** (ungated, matches today's "All"); **non-empty provider with no registration → `false`** (fail-closed); else delegate. Interface: `key()`, `label()`, `options(): array` (caller prefixes the "All"/none option), `userMayAccess(int $user_id, int $ref): bool`.

Gate edits: `files_class.php:1552-1568` (inside `is_viewable`) and `videos_class.php:271-287` — replace the event blocks with one `AccessGateRegistry::userMayAccess($this->get('fil_access_provider'), $this->get('fil_access_ref'), $session->get_user_id())` check. Picker/display edits per Part 1 point 7. Provider: `plugins/event_manager/includes/access_gate_providers/EventRegistrationGate.php` — `userMayAccess` = `MultiEventRegistrant(['user_id'=>$uid,'event_id'=>$ref,'expired'=>false])->count_all() > 0`.

### SEO entities (point 8)
Class lives in `data/seo_page_metadata_class.php` (there is no `includes/SeoPageMetadata.php`). `ENTITY_CLASSES` const → `private static $entity_classes` + `register_entity_class(string $type, string $class, string $multi, string $file, string $namespace, string $admin_edit_url, string $og_type = 'website')` + `entity_classes(): array`. `admin_edit_url` is an id-append prefix (kills the map at `admin_seo_page_edit_logic.php:77-88`); `og_type` kills the switch at `:306-315`. Swap every `self::ENTITY_CLASSES` read (`:148-156, :327, :423-473, :489, :586` and `admin_seo_page_edit_logic.php:57`). Registrations — core: `post` (…`admin_post_edit?pst_post_id=`, og `article`), `page`, `video` (og `article`), `mailing_list` (namespace `list`); store: `product` (og `product`, file `plugins/store/data/products_class.php`); event_manager: `event` (og `article`), `location`.

### Fulfillment (point 9, store-owned)
`plugins/store/includes/FulfillmentRegistry.php`: `register()`, `get()`, `all()`. Interface: `key()`, `label()`, `options(): array`, `extraRequirements(Product $product, int $ref): array` (AbstractProductRequirement[] to auto-attach — the event provider returns the SurveyRequirement when `evt_survey_display === 'required_before_purchase'`), `fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array` returning `['ref_id'=>?int,'label'=>?string,'labels'=>?array]`, `displayReference(int $ref): string` (admin HTML link/label).

Invocation: `cart_charge_logic.php` Pass-1 loop (`:507`), replacing `:612-639`, after the order_item is `STATUS_PAID`+saved (`:606-610`); `$result['label']`/`['labels']` feed the line summaries (`:673-675`). The Pass-2 notification/signal block (`:764-787`) and the `outcome='event'` categorization (`:641-652`) move INTO the provider's `fulfill()` — event_manager owns `event.registered` and the "You're registered" notification. Requirement auto-attach: `products_class.php get_product_requirements() :461-479` — replace the inline block (466-476) with the provider's `extraRequirements()`. The optional-at-confirmation collection (`:851-865`) moves to event_manager per Part 1. Admin edits per Part 1 point 9; `odi_evr_event_registrant_id` stays an event_manager-internal column and the provider writes it. Provider: `plugins/event_manager/includes/fulfillment_providers/EventRegistrationFulfillment.php` — single event via `Event::add_registrant($user->key, $order_item, NULL, $product->get('pro_expires'))`, bundle via `pro_grp_group_id` → `Group` → per-member `add_registrant($user->key, $order_item, $product->get('pro_grp_group_id'), NULL)` (signature verified: `add_registrant($usr_user_id, $order_item=NULL, $bundle_id=NULL, $days_until_expire=NULL)`).

### Tier gated-content (point 10)
`includes/TierGatedContentRegistry.php`: `register(string $label, string $table, string $level_column, string $delete_column)` + `all()`. A plain descriptor suffices — the summary runs `COUNT(*) WHERE {level_col} = ? AND {delete_col} IS NULL` exactly as today (`admin_subscription_tiers.php:87-104`). Core registers Posts/Pages/Files/Videos; store registers `('Products','pro_products','pro_tier_min_level','pro_delete_time')`; event_manager registers `('Events','evt_events','evt_tier_min_level','evt_delete_time')`.

### Message context (point 12)
`includes/MessageContextRegistry.php`: `register(string $type, callable $resolver)` where resolver is `function(int $id): ?array` returning `['label','url']`; `resolve(string $type, int $id): ?array`. `admin_message.php:9,20-22,72-74` — drop the events require/instantiation; render resolved link, else plain `"{type} #{id}"`. `messages_class.php:53,114,118` — columns + filter options. Writers (`admin_users_message_logic.php:95,136,213,249,324`) set `('msg_context_type','event')` + `('msg_context_id',$event->key)` — those branches move with event_manager. Event_manager registers the `event` resolver in serve.php.

### Entity photos (point 14)
`includes/EntityPhotoRegistry.php`: `register(string $type, string $class, string $file)`, `get(string $type): ?array`, `has()`. Consumer edit at `entity_photos_ajax.php:126-143`: on first photo, if a registration exists, `require_once` + instantiate + `set_primary_photo($photo->key)` (guard with `method_exists`); unregistered types skip the sync (photo row still created — matches current behavior for unmapped types). Registrations — core: `user`, `mailing_list`, `post`, `page`; store: `product` (plugin path); event_manager: `event`, `location`.

### TierBilling (point 10)
All four methods are already `public static` on `SubscriptionTier` (`data/subscription_tiers_class.php` — there is no `includes/SubscriptionTier.php`) and move verbatim to `plugins/store/includes/TierBilling.php`: `handleProductPurchase` (was `:264`), `getUpgradeOptions` (`:316`), `userHasActiveSubscription` (`:174` — zero callers repo-wide; move anyway), `handleSubscriptionExpired` (`:195`). Internal calls to staying primitives become explicit `SubscriptionTier::`/`$tier->` calls. Call-site edits: `cart_charge_logic.php:744`, `change_tier_logic.php:239`, `stripe_webhook.php:132`, `paypal_subscription_webhook.php:160` — `SubscriptionTier::` → `TierBilling::`. Core degradation: `SystemBase.php:1763` — remove the `'upgrade_options'` key from `authenticate_tier`'s denial payload; `tier_gate_prompt.php:58-72` — replace the loop with the `tier_upgrade_url` link (empty → existing contact-us fallback at 70-71). Staying on `SubscriptionTier` (verified static/instance): `GetUserTier` (static `:147`), `UserHasMinimumTier` (`:254`), `getUserFeature` (`:390`), `addUser` (**instance** `:86`), `removeUserFromAllTiers` (`:460`), `save` (**instance** `:49`), `requireMinimumTier` (`:293`), `getUserTierDisplay` (`:303`), `getAllAvailableFeatures`, `clearUserCache`, `MultiSubscriptionTier::GetAllActive` (`:529`).

## Schema-change mechanics

Ground truth about the schema system (all verified): columns declared in `$field_specifications` are ADDED (nullable) by `update_database` Step 1 / plugin sync; **removed columns are NEVER auto-dropped** (drop lives only behind `--cleanup`, which never runs automatically); there is **no rename facility**; DatabaseUpdater manages **no FK constraints** (the `'foreign_key'` spec key is inert; `$foreign_key_actions` is deletion-cascade strategy, not DDL). None of the six converted columns has any index or FK constraint in the live DB — disposal is a plain `DROP COLUMN`, nothing to recreate.

**Universal recipe per conversion** (all land in phase 2, code still in core): (1) add the new columns to the still-core data class specs — Step 1 adds them before migrations run; (2) backfill + `DROP COLUMN` the old ones in a **`migration_file`** PHP script (`migration_sql` is single-statement only — verified `migrations_class.php`; scripts are content-hash one-shot, run in a transaction, precedent `migrations/drop_event_sessions_menu.php`), self-guarded on `information_schema` so it no-ops post-drop; (3) remove the old columns from the specs in the same change.

New provider/type columns: `varchar(32)`, nullable; ref/id columns: `int4`, nullable. Backfills (row counts from dev):

| Migration file | Backfill | Rows |
|---|---|---|
| `generalize_erg_recipient_provider.php` | `erg_provider='event', erg_reference_id=erg_evt_event_id WHERE erg_evt_event_id IS NOT NULL`; then `erg_provider='group', erg_reference_id=erg_grp_group_id WHERE erg_grp_group_id IS NOT NULL AND erg_provider IS NULL`; drop **both** old columns | 12 |
| `generalize_vid_access_gate.php` | `vid_access_provider='event_registration', vid_access_ref=vid_evt_event_id WHERE …` | 166 |
| `generalize_fil_access_gate.php` | same pattern | 0 |
| `generalize_pro_fulfillment.php` | `pro_fulfillment_provider='event_registration', pro_fulfillment_ref=pro_evt_event_id WHERE …` | 57 |
| `generalize_msg_event_context.php` | `msg_context_type='event', msg_context_id=msg_evt_event_id WHERE …` | 141,732 |
| `generalize_sev_entity.php` | session first: `sev_entity_type='event_session', sev_entity_id=sev_evs_event_session_id WHERE sev_evs_event_session_id IS NOT NULL AND sev_entity_id IS NULL`; then event; drop both | 22,530 |

**Accepted information loss on `sev`**: all 22,530 rows carry both event and session ids; the generic pair keeps the session (more specific — the event is recoverable via the session). Historical analytics only; forward writes use the generic pair.

**stripe_customers is different** — it's a plugin table that doesn't exist when core migrations run (migrations at Step 4 `:374-505`, plugin sync at `:703`). The backfill+disposal lives in **`plugins/store/activate.php`** (the `onActivate` hook runs after `runPluginTablesOnly` creates the table): guarded on `usr_stripe_customer_id` existing, `INSERT…SELECT` all non-null rows (`NOT EXISTS` dedup), then `ALTER TABLE usr_users DROP COLUMN IF EXISTS` both. Data class spec: `stc_usr_user_id` int8 not-null unique, `stc_customer_id`/`stc_customer_id_test` varchar(64) indexed. Remove the two columns from `User` specs in the same change. **Do NOT add a core drop migration for the usr_ columns** — it would run before sync/activation on the first upgrade pass and destroy the 967 real rows on the getjoinery control plane before the backfill fires. Store-less installs keep two all-NULL orphan columns (harmless; a later unconditional cleanup migration can remove them once no un-upgraded store install remains).

**Plugin migrations don't run at activation** (`onActivate` doesn't execute plugin numbered migrations; only `sync()` runs them for already-active plugins at `:1214` — the `upgrade.php` path covers it via its post-subprocess re-sync at `:1224`). Therefore anything store needs immediately at activation — the `purchase_receipt_default`/`purchase_receipt_product_default` email-template seeds — goes in `plugins/store/activate.php`, not a plugin migration. Declarative settings are fine (`syncSettings` runs inside `onActivate`).

## Menu and settings lifecycle

**Settings**: no migration needed. Both core `Setting::seed_declared()` (`settings_class.php:101-117`) and plugin `syncSettings` are `INSERT … ON CONFLICT (stg_name) DO NOTHING`; nothing deletes undeclared rows. Moving a key from settings.json to a manifest preserves the row and its admin-set value.

**Menus**: a cleanup migration is MANDATORY. Core menu seeding is `overwrite=false, prune=false` (`PluginManager.php:661-668`) — removing entries from `admin_menus.json` orphans the `amu_admin_menus` rows into dead links on plugin-inactive installs. Ship `migration_file` `prune_extracted_menu_rows.php`: DELETE (children first, respecting `amu_parent_menu_id`) the slugs `orders, orders-list, stripe-payments, shadow-sessions, products, products-list, product-groups, coupon-codes, core-orders, core-subscriptions, events, events-list, event-bundles, event-types, locations, core-events, core-event-sessions`. It runs at Step 4 (before sync): plugin-less installs lose the dead rows for good; on installs where a plugin activates, `syncMenus` (`overwrite=true, prune=true`, unique key `amu_slug`, ownership tracked in `plg_metadata['_menu_slugs']`) seeds the plugin's entries fresh — under the **renamed** slugs (`store-orders`, `store-subscriptions`, `event-manager-events`, `event-manager-event-sessions`), since sync rejects plugin-declared `core-*` slugs. Also delete the two imperative seed lines (`migrations.php:671-672`) so fresh installs don't re-seed them into core.

## Upgrade activation (exact mechanism)

Inline code in `utils/update_database.php` immediately after the plugin-sync try-block closes (`:735`), before the SEO seed (`:737`). One-time via a marker `stg_settings` row `_store_event_autoactivate_v1` (not declared in any settings.json, so seeding never touches it; checked via `MultiSetting`, written with `INSERT … ON CONFLICT DO NOTHING`). NOT state-idempotent on purpose — an admin who later deactivates store must not have it re-activated by the next deploy.

Logic: `$activate_store = rows('pro_products') > 0 || rows('ord_orders') > 0 || set('stripe_api_key') || set('paypal_api_key');` `$activate_events = rows('evt_events') > 0;` `if ($activate_events) $activate_store = true;` — guard row-counts with `to_regclass` (tables may not exist), activate via `PluginManager::activate()` only when the plugin row exists with `plg_active=0`, **store before event_manager** (activate() enforces `depends`, `:666`). `activate()` runs the full chain: validation, composer reconcile, `runPluginTablesOnly` (creates `stc_stripe_customers`), `syncSettings`, the `activate.php` hook (stripe backfill + email-template seeds fire here), deletion-rule registration, `syncMenus`. Wrap in try/catch, non-fatal like the surrounding steps. This automatically satisfies the getjoinery control plane (6,165 orders → store+event_manager active).

## Plugin manifests (verbatim)

Schema notes: **`tasks` is not a manifest key** — `ScheduledTask::resolve_task_file()` auto-discovers `plugins/{name}/tasks/*.php`+`.json` (docs/scheduled_tasks.md §Task Discovery); plugin tasks carry `sct_plugin_name`, suspend on deactivate, delete on uninstall. All four moved tasks need zero wiring. New plugins start at `1.0.0`. Children inherit parent `permission` unless overridden; `settingActivate` gates rendering.

`plugins/store/plugin.json`:

```json
{
    "name": "Store",
    "version": "1.0.0",
    "description": "Sell products: cart, checkout, orders, coupons, Stripe/PayPal, recurring subscriptions, billing portal, and product requirements",
    "author": "Joinery",
    "receives_upgrades": true,
    "included_in_publish": true,
    "requires": { "php": ">=8.0" },
    "adminMenu": [
        { "slug": "orders", "title": "Orders", "icon": "shopping-cart", "order": 4, "permission": 5, "settingActivate": "products_active",
          "items": [
            { "slug": "orders-list", "title": "Orders list", "url": "/plugins/store/admin/admin_orders", "order": 1, "permission": 5, "settingActivate": "products_active" },
            { "slug": "stripe-payments", "title": "Stripe Payments", "url": "/plugins/store/admin/admin_stripe_orders", "order": 2, "permission": 5, "settingActivate": "products_active" },
            { "slug": "shadow-sessions", "title": "Shadow Sessions", "url": "/plugins/store/admin/admin_shadow_sessions", "order": 15, "permission": 5, "settingActivate": "products_active" } ] },
        { "slug": "products", "title": "Products", "icon": "box", "order": 3, "permission": 8, "settingActivate": "products_active",
          "items": [
            { "slug": "products-list", "title": "Products list", "url": "/plugins/store/admin/admin_products", "order": 1, "permission": 8, "settingActivate": "products_active" },
            { "slug": "product-groups", "title": "Product Groups", "url": "/plugins/store/admin/admin_product_groups", "order": 2, "permission": 9, "settingActivate": "products_active" },
            { "slug": "coupon-codes", "title": "Coupon codes", "url": "/plugins/store/admin/admin_coupon_codes", "order": 10, "permission": 5, "settingActivate": "products_active" } ] }
    ],
    "profileMenu": [
        { "slug": "store-orders", "title": "Orders", "url": "/profile/orders", "order": 60, "permission": 0, "icon": "shopping-bag", "nativeScreen": "orders", "settingActivate": "products_active" },
        { "slug": "store-subscriptions", "title": "Subscriptions", "url": "/profile/subscriptions", "order": 70, "permission": 0, "icon": "refresh", "nativeScreen": "subscriptions", "settingActivate": "subscriptions_active" }
    ],
    "settings": [
        { "name": "products_active", "default": "1" },
        { "name": "products_list_items_active", "default": "1" },
        { "name": "coupons_active", "default": "1" },
        { "name": "subscriptions_active", "default": "1" },
        { "name": "checkout_type", "default": "stripe_regular" },
        { "name": "cart_intermediate_page", "default": "1" },
        { "name": "site_currency", "default": "US Dollar" },
        { "name": "use_paypal_checkout", "default": "0" },
        { "name": "use_venmo_checkout", "default": "0" },
        { "name": "pricing_page", "default": "1" },
        { "name": "max_subscriptions_per_user", "default": "10" },
        { "name": "subscription_cancellation_enabled", "default": "1" },
        { "name": "subscription_cancellation_prorate", "default": "1" },
        { "name": "subscription_cancellation_timing", "default": "Immediate" },
        { "name": "subscription_downgrade_prorate", "default": "1" },
        { "name": "subscription_downgrade_timing", "default": "Immediate" },
        { "name": "subscription_downgrades_enabled", "default": "1" },
        { "name": "subscription_reactivation_enabled", "default": "1" },
        { "name": "subscription_upgrade_prorate", "default": "1" },
        { "name": "stripe_api_key", "default": "" },
        { "name": "stripe_api_key_test", "default": "" },
        { "name": "stripe_api_pkey", "default": "" },
        { "name": "stripe_api_pkey_test", "default": "" },
        { "name": "stripe_endpoint_secret", "default": "" },
        { "name": "connected_account_id", "default": "" },
        { "name": "paypal_api_key", "default": "" },
        { "name": "paypal_api_key_test", "default": "" },
        { "name": "paypal_api_secret", "default": "" },
        { "name": "paypal_api_secret_test", "default": "" },
        { "name": "tier_upgrade_url", "default": "/pricing" }
    ],
    "signals": { }
}
```

The `signals` object carries the six store signal declarations copied **verbatim** from root `signals.json` (`purchase.completed`, `payment.failed`, `subscription.started`, `subscription.cancelled`, `subscription.payment_failed`, `subscription.expired` — full payload/notify blocks unchanged; elided here for length, copy them exactly). Note `tier_upgrade_url` appears twice by design: core settings.json declares it with empty default (the gate prompt reads it store-or-no-store); store's manifest seeds `/pricing` — ON CONFLICT DO NOTHING means whichever seeds first wins, so REMOVE it from store's manifest and instead have `plugins/store/activate.php` set it to `/pricing` only if currently empty. (Decision: core declares empty default; store's activate.php upgrades the value.)

`plugins/event_manager/plugin.json`:

```json
{
    "name": "Event Manager",
    "version": "1.0.0",
    "description": "Events toolkit: events, locations, event sessions and waiting lists, with store integration for ticketed events and event surveys at checkout",
    "author": "Joinery",
    "receives_upgrades": true,
    "included_in_publish": true,
    "requires": { "php": ">=8.0" },
    "depends": { "store": ">=1.0.0" },
    "adminMenu": [
        { "slug": "events", "title": "Events", "icon": "calendar", "order": 5, "permission": 8, "settingActivate": "events_active",
          "items": [
            { "slug": "events-list", "title": "Events List", "url": "/plugins/event_manager/admin/admin_events", "order": 1, "permission": 5, "settingActivate": "events_active" },
            { "slug": "event-bundles", "title": "Event Bundles", "url": "/plugins/event_manager/admin/admin_event_bundles", "order": 10, "permission": 8, "settingActivate": "events_active" },
            { "slug": "event-types", "title": "Event Types", "url": "/plugins/event_manager/admin/admin_event_types", "order": 5, "permission": 8, "settingActivate": "events_active" },
            { "slug": "locations", "title": "Locations", "url": "/plugins/event_manager/admin/admin_locations", "order": 5, "permission": 5, "settingActivate": "events_active" } ] }
    ],
    "profileMenu": [
        { "slug": "event-manager-events", "title": "My Events", "url": "/profile/events", "order": 80, "permission": 0, "icon": "calendar", "nativeScreen": "events", "settingActivate": "events_active" },
        { "slug": "event-manager-event-sessions", "title": "Event Sessions", "url": "/profile/event_sessions", "order": 90, "permission": 0, "icon": "clock", "settingActivate": "events_active" }
    ],
    "settings": [
        { "name": "events_active", "default": "1" },
        { "name": "events_label", "default": "" },
        { "name": "products_list_events_active", "default": "1" },
        { "name": "event_email_inner_template", "default": "blank_template" },
        { "name": "event_email_outer_template", "default": "default_outer_template" },
        { "name": "event_email_footer_template", "default": "event_bulk_footer" }
    ],
    "signals": { }
}
```

Event_manager's `signals` carries `event.registered`, `event.waitlisted`, `event.withdrawn` verbatim from root `signals.json`. The `max_entity_photos` `event`/`location` JSON keys are seeded by `plugins/event_manager/activate.php` (JSON-merge into the existing setting value — a declarative seed can't merge into JSON). `admin_menus.json` pre-existing quirk preserved verbatim: `event-types` and `locations` both carry `order: 5`.

## Verified inventory deltas (beyond Part 1's lists)

Complete old→new move tables were verified file-by-file (every glob expanded; every named file exists). Deltas and traps the executor must not miss:

- **Store admin files with no `_logic` counterpart** (self-contained; nothing missing): `admin_order_item_edit`, `admin_order_refund`, `admin_stripe_orders`, `admin_stripe_invoices`, `admin_settings_payments`, `admin_user_payment_methods`, `admin_shadow_sessions`. Event_manager equivalents: `admin_event_bundle*`, `admin_event_session_edit`, `admin_event_emails`, `admin_location`, `admin_location_edit`.
- **Full store admin move set**: `admin_product.php, admin_products.php, admin_product_edit.php, admin_product_group_edit.php, admin_product_groups.php, admin_product_version_edit.php, admin_order.php, admin_orders.php, admin_order_edit.php, admin_order_delete.php, admin_order_item_edit.php, admin_order_refund.php, admin_stripe_orders.php, admin_stripe_invoices.php, admin_shadow_sessions.php, admin_coupon_code.php, admin_coupon_codes.php, admin_coupon_code_edit.php, admin_settings_payments.php, admin_user_payment_methods.php, admin_yearly_report_donations.php` + logic: `admin_product_logic, admin_products_logic, admin_product_edit_logic, admin_product_group_edit_logic, admin_product_groups_logic, admin_product_version_edit_logic, admin_order_logic, admin_order_edit_logic, admin_order_delete_logic, admin_coupon_code_logic, admin_coupon_codes_logic, admin_coupon_code_edit_logic, admin_yearly_report_donations_logic`.
- **Full event_manager admin move set**: `admin_event.php, admin_events.php, admin_event_edit.php, admin_event_emails.php, admin_event_session_edit.php, admin_event_bundle.php, admin_event_bundles.php, admin_event_bundle_edit.php, admin_event_type_edit.php, admin_event_types.php, admin_location.php, admin_locations.php, admin_location_edit.php` + logic: `admin_event_logic, admin_events_logic, admin_event_edit_logic, admin_event_type_edit_logic, admin_event_types_logic, admin_locations_logic`.
- **Store utils**: `utils/stripe_charges_synchronize.php`, `utils/admin_stripe_invoices_synchronize.php`, `utils/refresh_stripe_test_keys.php`, `utils/products_list.php`.
- **Requirements dir is 10 files**: `AbstractProductRequirement, Address, DOB, Email, FullName, NewsletterSignup, PhoneNumber, Question, Survey, UserPrice` — Question/Survey are already IN `includes/requirements/` and simply move with the directory (they become "store built-ins" by location; no new files).
- **ShoppingCart call-site inventory** (complete): `SessionControl.php:9,232-240,500-525` (delete), `RouteHelper.php:1068` (delete call), `PublicPageBase.php:6,251` (provider), `cart_logic.php:7,18`, `cart_charge_logic.php:36,82`, `cart_clear_logic.php:5,19`, `checkout_logic.php:7,29`, `product_logic.php:5,76,94,109,118` (incl. `ShoppingCartException`), `views/cart_confirm.php:2,10`, `theme/scrolldaddy/views/cart_confirm.php:2,17`, `theme/scrolldaddy/includes/PublicPage.php:170,297,403`, `theme/zoukroom-html5/includes/PublicPage.php:109`, `tests/functional/products/ProductTester.php:21,890,1005,1026,1041,1052,1227,1354,1676,1725`, `utils/upgrade.php:1145` (comment only — update text). `$_SESSION['shopping_cart']` appears only in `SessionControl.php:234` — `ShoppingCart::current()` takes over that key.
- **Version bumps**: `serve.php` has `@version 1.2.0` (line 4) — bump. `RouteHelper.php`, `SessionControl.php`, `PublicPageBase.php`, `PluginManager.php` carry no `@version` header — no bump. Core `VERSION` (0.8.81) is release machinery — leave alone.
- **`_site_init.sh` is NOT under public_html**: full path `/var/www/html/joinerytest/maintenance_scripts/install_tools/_site_init.sh` (lines 337, 358).

## Execution-order adjustment

The Part-1 execution order stands with one insertion: the menu-prune migration, the six column-generalization migrations, and the update_database activation hook all ship in **step 3** (the store move) release; the event-column migrations that only matter to event_manager (`msg`, `sev`, `erg`, `vid`, `fil`) may ship in step 2 (groundwork/extension points) since the columns generalize while code is still in core — that is in fact required, because the registries consuming the new columns land in step 2. Concretely: generalization migrations belong to step 2; menu-prune + activation hook + stripe_customers (via activate.php) belong to step 3.

## Explicitly out of scope

Blog/posts/comments and reactions extraction (separate, already-clean candidates); mailing lists (transactional/marketing email share `eml_emails` — separate effort); analytics/AB write path; videos as a feature (stays core; only its event gate is generalized here); bookings relocation; the remaining `plugin_ajax_namespace_collision.md` migrations (server_manager, dns_filtering, mailbox, bookings page-JS endpoints); any renaming of tables, settings, URLs, or signals beyond the generalized columns listed.
