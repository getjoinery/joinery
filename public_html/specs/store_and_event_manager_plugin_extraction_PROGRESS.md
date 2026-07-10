# Store/Event Manager Extraction — Progress Tracker

Spec: specs/store_and_event_manager_plugin_extraction.md
Branch: security-levels

## Phase 1 — Platform groundwork (still in core)  ✅ DONE
- [x] Sync-time collision validator (PluginManager::validateFlatNamespaceCollisions) — top-level glob only (recursing tests/ would false-collide on run.php)
- [x] CurrencyHelper (includes/CurrencyHelper.php) + removed Product::$currency_symbols; switched all core call sites; preloaded via LibraryFunctions.php + RouteHelper core-guarantees
- [x] model-discovery fix: api/apiv1.php:350 include_plugins
- [x] model-discovery fix: utils/fix_sequences.php, fix_duplicate_keys.php
- [x] model-discovery fix: tests/models/run_multi.php, models_test.php, run_all.php, run_automated.php
- [x] FormWriter prefix map: FormWriterV2Base::getModelPrefixMap scans plugins/*/data/
- [x] deletion-rule prune behavior — no code change (already keys on FS). Regression test DEFERRED to phase 3 (needs store as plugin).
- [x] installer: _site_init.sh REQUIRED_TABLES drop evt_events/pro_products; CRITICAL_SETTINGS drop events_active
- VERIFIED: public pages / /products /pricing all 200, no fatals; CurrencyHelper CLI smoke ok. TODO: admin-page currency smoke via browser at a checkpoint.

## Phase 2 — Core extension points (still in core)
- [x] Route plugin option (view + handler forms) in RouteHelper handleDynamicRoute — MECHANISM done; serve.php entries in phase 3/4
- [ ] Point 3: Header menu providers (PublicPageBase) — TODO (tied to point 2)
- [ ] Point 4: ProfileDashboardRegistry consumers (registry class DONE; consumers profile_logic/profile_dashboard_logic/profile.php TODO)
- [ ] Point 5: AdminUserPanelRegistry consumers (registry class TODO + admin_user.php/logic)
- [x] Point 6: RecipientGroupProviderRegistry + Group/Event/WaitingList providers; erg columns generalized+migrated; emails_class/email_recipient_groups/admin_email*/queue converted. (admin_users_message deferred to ph4)
- [x] Point 7: AccessGateRegistry + EventRegistrationGate; vid/fil columns generalized+migrated; is_viewable/is_public/admin pickers+displays converted
- [x] Point 8: SeoPageMetadata::register_entity_class + core registrations (all 7 incl MOVED-TO-PLUGIN); admin_seo_page_edit_logic converted. site-directory gating TODO.
- [x] Point 9: FulfillmentRegistry + EventRegistrationFulfillment; pro column generalized+migrated; cart_charge Pass1/Pass2/confirmation, products_class extraRequirements, admin_product*/order_item_edit converted
- [~] Point 10: TierGatedContentRegistry DONE+consumer. TierBilling split + tier_upgrade_url + gate prompt TODO.
- [ ] Point 11: stripe_customers — phase 3 (activate.php). StripeHelper accessor + User column removal TODO.
- [x] Point 12: MessageContextRegistry + msg columns generalized+migrated + event resolver (MOVED-TO-PLUGIN). sev DEFERRED to ph4.
- [ ] Point 13: theme requires_plugins enforcement + migrate_entity_photos guard + IcsHelper location guard TODO
- [x] Point 14: EntityPhotoRegistry + consumer entity_photos_ajax converted
- [ ] Point 2: cart out of SessionControl + theme PublicPage conversions — TODO
- [x] Generalization migrations msg/erg/vid/fil/pro: WRITTEN v141-145, RUN on dev, VERIFIED (rows 141732/12/166/0/57, old cols dropped, new populated, public pages 200 no fatals)

## SEQUENCING DEVIATION (deliberate):
Pure-seam registries (no column change) → consumer conversion + provider registration done
WITH the plugin move, not phase 2, because providers register from plugin serve.php and
leaving consumers untouched keeps phase 2 working + matches acceptance's phased degradation:
- Point 2/3 (cart out of SessionControl + header providers) → PHASE 3 (store serve.php owns cart provider + coupon capture; ShoppingCart::current; theme PublicPage conversions)
- Point 4 (profile dashboard) → consumer convert in PHASE 3; store provider ph3, event provider ph4
- Point 5 (admin-user panels) → consumer convert in PHASE 3; store panels ph3, event panel ph4
- Point 11 (stripe_customers) → PHASE 3 (table created on store activate)
Registry CLASSES already built in includes/ (ProfileDashboardRegistry done; AdminUserPanelRegistry TODO build class now).

## Phase-2 REMAINING (core-only, do now):  ✅ ALL DONE
- [x] Point 10: TierBilling split (includes/TierBilling.php, 4 methods moved), tier_upgrade_url core setting (empty), gate prompt uses it, authenticate_tier upgrade_options key removed, callers switched (cart_charge/change_tier/webhooks). admin_subscription_tiers gated-content via registry VERIFIED. Products-panel move=phase3.
- [x] Point 13: ThemeManager::activate requires_plugins enforcement; phillyzouk/zoukroom/devonnearhill theme.json requires_plugins=[event_manager]; migrate_entity_photos to_regclass guard; IcsHelper Location plugin-aware guard
- [x] Point 8: site-directory $events_available flag (plugin-aware require + gate)
- [x] ScrollDaddy tier_features.json moved theme/scrolldaddy → plugins/dns_filtering
- [x] items admin_item_relation_types.php repaired (MultiItemRelationType/itt_name)
- [x] AdminUserPanelRegistry class built (consumer conversion deferred to phase 3/4)
- VERIFIED: tier admin renders (gated-content registry), product-edit fulfillment picker, video access-gate picker, recipient providers CLI, site-directory 200, all public 200

## PHASE 2 COMPLETE (core-only portions). Deferred to phase 3/4: points 2,3,4,5,11 (see deviation note).

## Phase-2 REMAINING:
- Point 2 (cart/SessionControl/header providers point 3) — tied together
- Point 4 consumers (profile dashboard)
- Point 5 (admin-user panels)
- Point 10 rest (TierBilling, tier_upgrade_url, gate prompt)
- Point 11 (stripe_customers StripeHelper accessor + User column removal — table lands phase 3)
- Point 13 (theme requires_plugins, migrate_entity_photos guard, IcsHelper guard)
- Point 8 site-directory Events/Locations gating
- Admin browser verification of converted admin pages (product/video/file edit, email recipients)

## Phase 3 — Move the store  (IN PROGRESS)
- [x] Create plugins/store/ (plugin.json ✓ minus tier_upgrade_url; serve.php ✓; activate.php ✓; stripe_customers_class.php ✓ NEW)
- [x] MOVED all files (git mv, resilient for untracked TierBilling/FulfillmentRegistry): 13 data classes, 5 includes (StripeHelper/PaypalHelper/ShoppingCart/TierBilling/FulfillmentRegistry), requirements/ (10), 14 logic, 13 views, 4 tasks, 21 admin + 13 admin/logic, 4 utils, 2 test dirs, 2 ajax webhooks, 2 docs
- [x] REPOINTED all getIncludePath refs across repo (perl, lookbehind-guarded). Removed broken __DIR__ PathHelper bootstraps; fixed 2 CLI utils (stripe_charges/admin_stripe_invoices) + refresh_stripe_test_keys to guarded dirname(__DIR__,3) bootstrap; fixed StripeHelper/PaypalHelper bare requires. View logic-loads get plugin param 'store' (13 store views + 5 scrolldaddy overrides). php -l clean across store tree.
- [x] CART OUT OF SESSIONCONTROL (point 2): ShoppingCart::current()/capture_marketing_coupon/apply_pending_coupon/pending_coupon_flash static + COUPON_*_KEY consts moved to ShoppingCart. SessionControl stripped (line 9 require, get/save_shopping_cart, 3 coupon methods, 2 consts). RouteHelper capture call removed. All store call sites → ShoppingCart::current(). Header cart provider (point 3): PublicPageBase::register_header_menu_provider + store header_menu_cart_provider.php; get_menu_data loops providers. Theme conversions: scrolldaddy/zoukroom PublicPage.php → $menu_data['cart']; scrolldaddy cart_confirm/pricing views → ShoppingCart::current()/pending_coupon_flash().
- [x] serve.php ROUTES: bumped @version 1.3.0; /product/{slug} +plugin; new /products,/cart,/checkout,/pricing +plugin; profile store routes before /profile/* wildcard. store serve.php registers product SEO/tier/entityphoto (removed from core defaults) + header cart + coupon capture.
- [x] settings.json: 29 store keys removed (tier_upgrade_url STAYS core empty). signals.json: 6 store signals removed (event.* stay for ph4).
- [x] SITE BOOTS with store INACTIVE (homepage/login/products/pricing/sitemap all 200).
- [x] Point 11: StripeCustomer class (stc_stripe_customers); StripeHelper read_customer_id/write_customer_id accessors; removed usr_stripe_customer_id[_test] cols + GetByStripeCustomerId from User; converted billing_logic, admin_user_payment_methods, stripe_charges_synchronize (GetUserIdByCustomerId), refresh_stripe_test_keys (stc SQL), SubscriptionTierTester, deletion_rule_source_table_test. Backfill+col-drop in activate.php (runs on activation).
- [x] depends on store: dns_filtering (v1.1.3) + bookings (v1.1.2) — "depends":{"store":">=1.0.0"}
- [x] store activation upgrade step in update_database.php (after plugin-sync block; one-time marker _store_event_autoactivate_v1; to_regclass-guarded row counts; store-before-events; non-fatal)
- [x] menu-prune migration v147 prune_extracted_menu_rows.php (store+event slugs; children→parents→profile). admin_menus.json STORE entries removed (8 admin + 2 profile). Event menus + imperative seed removal = phase 4.
- [x] tier_gate_prompt hardened: TierBilling fallback gated on isPluginActive('store') (degrades to contact when inactive).
- [x] LATENT COUPLING FIXES (cache-masked, found on cache clear): events_class::get_register_url requires store products_class; adm/admin_event.php requires store orders/order_items. RouteHelper: plugin-delegated route with INACTIVE plugin now HARD-404s (self::show404) instead of falling through to a theme/base view of same name (getjoinery ships pricing.php) — match_only returns false.
- [x] Cleared stale static_pages cache (57 entries) — routing changed broadly.
- [x] VERIFIED store-INACTIVE state: safe tier 30/30 (619 checks); routing_test 58/58 (made store-state-aware via isPluginActive); public smoke: / login /events /event/{slug} /site-directory /survey /sitemap /rss all 200; /pricing /cart /checkout /products all 404 (hard-gated).
- [~] checkout_ajax retirement — PARTIAL + BLOCKER FOUND:
    - [x] session_search_ajax.php DELETED (zero callers).
    - [x] checkout_ajax.php line 16 fixed ($session->get_shopping_cart() → ShoppingCart::current()) so it isn't fatal; still in ajax/ pending full retirement.
    - [ ] BLOCKER for the 3 store API actions (checkout_apply_coupon/remove_coupon/check_email): they must be `requires_browser_session` (cart lives in $_SESSION), and check_email + guest checkout are GUEST-reachable — but ApiAuth::authenticateBrowserSession REJECTS anonymous sessions (includes/ApiAuth.php:190 `if(!$user_id) auth_failure`) AND the joinery-api-csrf meta tag is emitted only for is_logged_in() (PublicPageBase.php:583). So a guest cannot authenticate a browser-session /api/v1 call today. get_api_csrf_token() itself works for anon (SessionControl:735). RESOLUTION (do at activation, browser-testing real checkout): decide whether checkout coupon/email ops are guest-reachable in practice; if yes, extend anon browser-session auth (emit CSRF meta for any session + allow anon principal for requires_browser_session actions with min_user_permission=0) — a security-boundary change to verify live. Descriptor auth model confirmed: `'auth'=>['capability','requires_browser_session','min_user_permission']`; API envelope: 200 `{data:{...}}`, 422 `{error}`. JS pattern: `fetch('/api/v1/action/store/...',{headers:{'X-Joinery-Csrf':csrf}})`.
    - [ ] submit_survey branch → event_manager action (phase 4); then delete checkout_ajax.php + dead validate_section/validate_checkout_section.
- [~] Point 4/5 PARTIAL:
    - [x] NATIVE dashboard (the load-bearing app contract) → registry: built plugins/store/includes/profile_dashboard_provider.php (recent_orders + subscriptions sections, exact native data keys + web display fields); profile_dashboard_logic.php replaces the inline products_active orders/subscriptions blocks with a ProfileDashboardRegistry::sections() loop (emits $out[id]=items' data + $out[stat->key]); removed core's store requires. serve.php registers both providers.
    - [ ] WEB profile (profile_logic.php + views/profile/profile.php orders/subscriptions sections) still INLINE + setting-gated (products_active null-guard so store-inactive shows nothing → functional acceptance met). Registry/generic-loop conversion deferred (polish).
    - [ ] ADMIN-user panels (adm/admin_user.php + admin_user_logic Orders/Subscriptions) still INLINE + setting-gated. AdminUserPanelRegistry + OrdersPanel/SubscriptionsPanel not built; serve.php registration still stubbed. (Events panel = phase 4.)
- [x] APP endpoint constants: iOS MemberAPI.swift + Android MemberApi.kt — order_list, subscription_summary, orders_recurring_action → "store/..." (my_events → phase 4 event_manager). submitAction builds /api/v1/action/{action}; DNSFilterKit plugin-prefix precedent. NOTE: tests/functional/api/member_screens_test.php (live tier) + android member_gate.sh must switch to store/ prefix + need store ACTIVE — verify at activation.
- [x] VERIFIED green after these changes: safe 30/30 (619 checks).
- [x] **STORE ACTIVATED (2026-07-10, user-approved DB write)** via direct PluginManager (NOT the update_database hook — marker _store_event_autoactivate_v1 left UNSET so the deploy path stays testable). Results verified:
    - plugin sync: store registered (no flat-namespace collision); bookings/dns_filtering picked up depends. store plg_active=1.
    - stc_stripe_customers created; 976 rows backfilled; usr_stripe_customer_id[_test] DROPPED (0 remain).
    - purchase_receipt_default + _product_default seeded (2); tier_upgrade_url='/pricing'.
    - Menus: store admin (orders/orders-list/stripe-payments/shadow-sessions/products/products-list/product-groups/coupon-codes → /plugins/store/admin/...) + profile store-orders/store-subscriptions seeded; deleted stale core-orders/core-subscriptions dupes (v147's job, done manually since prune deferred to ph4 to avoid pruning still-core event menus).
    - PLATFORM FIX REQUIRED (spec gap): PluginManager::validateDeclaredSettings enforces a `{plugin}_` prefix on declared settings, which conflicts with the spec's "names preserved". Added a per-setting `"legacy_core": true` opt-out (keeps collision + string-default rules); flagged all 29 store settings. event_manager settings (ph4) need the same flag.
- [x] VERIFIED store-ACTIVE (browser, logged in perm-10): /products /pricing /cart /checkout /product/{slug} all 200; admin_products list renders; admin_product_edit FULFILLMENT PICKER renders "Event registration: {event}" options (store↔event seam live); admin_subscription_tiers renders (gated-content registry, no raw-SQL fatal); safe tier 30/30 (622 checks); no new fatals in log.
    - [ ] NOT yet browser-verified (needs more time / guest-auth resolution): full checkout purchase flow, coupon apply/remove + check_email JS (still on unblocked /ajax/checkout_ajax), billing portal, tier purchase, orders admin, member API screens (need store/ prefix + session).

## Phase 4 — Move event_manager  (IN PROGRESS — code complete, pending active-state DB verification)
### Task 0 (prerequisite): web consumers → registries  ✅
- [x] ProfileDashboardRegistry web consumer: profile_logic.php iterates sections(); views/profile/profile.php renders a generic
      section loop (dashboard_render_section helper) + stat-card loop; actions banner reads pending_surveys. Removed all inline
      event/order/subscription blocks + event class requires. profile_dashboard_logic (native) drops inline events/pending_surveys
      (registry loop already emits them).
- [x] Store providers: OrdersPanel + SubscriptionsPanel (plugins/store/includes/admin_user_panels/); registered from store serve.php.
      Store dashboard providers already existed (phase 3).
- [x] AdminUserPanelRegistry web consumer: adm/admin_user.php renders panels loop (Orders/Subscriptions/Events); adm/logic/admin_user_logic.php
      drops event/order/subscription loading + requires, dispatches POST via AdminUserPanelRegistry::handlePost. Tier/Groups stay core inline.
      Session-visits card folded into EventsPanel. admin_user.php:303 tier-change order link gated on store-active.
- [x] DRIVEN (store active, event_manager inactive): /admin/admin_user renders Orders + Subscriptions panels, NO events panel, no fatals.
### event_manager plugin  ✅ (code)
- [x] plugins/event_manager/ scaffold: plugin.json (depends store, legacy_core settings, verbatim event signals), serve.php (all
      registrations), activate.php (sev backfill+drop + max_entity_photos JSON merge). Removed 6 event settings + 3 event signals +
      event/location keys of max_entity_photos default from root settings.json/signals.json.
- [x] MOVED all event files (git mv): 7 data classes (events/event_registrants/event_sessions/event_session_files/event_types/
      event_waiting_lists/locations — event_logs + location_info_data + session_analytics STAY core), 10 logic, 4 top + 4 profile views,
      13 admin + 6 admin/logic, 4 tasks, EventItemSource (calendar_item_sources/), 4 provider files, docs/recurring_events.md.
- [x] REPOINTED all requires (perl, lookbehind-guarded): data classes → plugin paths; removed broken __DIR__ PathHelper bootstraps;
      admin page → admin logic requires; view logic-loads get plugin param 'event_manager'; swept plugin-internal /admin/admin_event*
      + /admin/admin_location* links → /plugins/event_manager/admin/* (fixed double-prefix collateral on hand-written files).
- [x] Removed event core-default registrations (Task 4): SEO event/location, TierGatedContent Events, EntityPhoto event/location,
      Recipient event+waitlist, AccessGate event_registration (registerCoreDefaults now empty → fail-closed), MessageContext event
      resolver, FulfillmentRegistry event → all now register from event_manager serve.php. Latent-coupling cleanup: removed VESTIGIAL
      event requires from store files (cart_charge/subscriptions/stripe_webhook/ShoppingCart/admin_product*_logic) + dead
      get_event_sessions/fileadd/fileremove from files_class/admin_file_logic; gated live edges (SurveyRequirement registrant-mark,
      admin_file_upload_process attach, admin_users_message) on isPluginActive('event_manager').
- [x] serve.php ROUTES (@version 1.4.0): event view routes +plugin=event_manager; ICS closures → plugin handler files
      (ics_event_route/ics_calendar_route) via handler-form routes (BEFORE /event/{slug}); added /event_waiting_list + 4 profile routes
      (incl. /profile/event_sessions_course, missing from spec verbatim list) before /profile/* wildcard. Audited ALL moved views for coverage.
- [x] sev generalization: session_analytics_class (core) drops old cols from spec + Multi filter uses entity pair; event_sessions_class
      record_analytic + get_last_visited_* + get_number_visits rewritten to sev_entity_type/id (join evs to recover event). Backfill+drop
      in activate.php.
- [x] admin_users_message decouple: event requires + `new Event`/Multi* gated on plugin-active (error if event target w/o plugin); view
      requires removed; return-link → plugin path. session_search already deleted (phase 3).
- [x] Wiring: update_database auto-activation ALREADY covers event_manager (phase 3, store-before-events). App endpoints my_events →
      event_manager/my_events (iOS MemberAPI.swift + Android MemberApi.kt + member_screens_test store/+event_manager/ prefixes).
      theme requires_plugins reinstated on phillyzouk/zoukroom/devonnearhill (+version bump). Menu: removed 5 event adminMenu + core-events
      profileMenu from admin_menus.json; removed imperative core-events/core-event-sessions seeds from migrations.php; prune migration v147
      already lists event slugs. admin_survey Associated-Events card gated + link fixed.
- [x] Cleared stale static_pages cache (events.html/event_*.html served /events as stale 200).
- [x] Made calendar_core_test + routing_test event_manager-state-aware (mirror store-aware pattern).
- [x] VERIFIED event_manager-INACTIVE: safe 30/30 (621 checks); all event URLs (/events,/event/x,/location,/events/calendar.ics,
      /profile/events,/event_waiting_list) hard-404; core+store pages 200; ZERO warnings/fatals in log.
- [x] **event_manager ACTIVATED on dev (2026-07-10, user-approved DB write)** via direct PluginManager sync+activate (marker
      _store_event_autoactivate_v1 left UNSET so the deploy path stays testable). Verified: plg_active=1, store still active; sev old cols
      DROPPED (0), 22530 rows backfilled to entity_id; max_entity_photos merged {user,mailing_list,event,location}; 6 event menu rows seeded.
- [x] BUG FOUND+FIXED on activation (latent, cache-masked): events_class.php used MultiEventSessions/Location without requiring
      event_sessions_class/locations_class (ambient-loaded pre-move) → /events 500. Added the two requires.
- [x] BUG FOUND+FIXED: event_register_logic.php loaded event_logic via getThemeFilePath WITHOUT the plugin param → /api/v1/action/
      event_manager/event_register 500. Added 'event_manager' param.
- [x] Made calendar_core_test + routing_test (3 spots) event_manager-state-aware; cleared stale static_pages cache (served /events 200 stale then 500).
- [x] DRIVEN active-state (browser, logged in):
    * /events + /event/{slug} + /event/{slug}.ics + /events/calendar.ics all 200 (valid VCALENDAR); admin_events/admin_event(registrants)/
      admin_locations render.
    * **PAID-EVENT REGISTER THROUGH CART end-to-end**: product page (with required consent question) → add to cart → /cart → /checkout →
      Complete Order → /cart_confirm "Purchase Confirmed!". DB: order 6461 status=2 PAID (no error); EventRegistrant 4325 created for
      user164+event86 linked to order 6461 + item 6471; order_item 6471 odi_evr_event_registrant_id=4325 (fulfillment provider wrote the
      event-internal column). The store↔event_manager FulfillmentRegistry seam works live.
    * WEB PROFILE dashboard (user164) via registries: "4 Upcoming Events" + "0 Active subscriptions" stat cards; Upcoming Events section
      (3 Active items) + Recent Orders + Subscriptions sections; all via the generic loop. No fatals.
    * ADMIN-USER panels (user164) via registries: Orders + Subscriptions (store) + Events (table + Add-to-event + Session Visits) panels.
    * admin_users_message?evt_event_id=86 renders "Send email to registrants of ...".
    * plugin API actions reachable: event_manager/my_events + store/order_list + event_manager/event_register all 400 (no-creds), not 404.
    * safe 30/30 (624 checks) active.
- [x] UPGRADE SIMULATION on scratch DB joinery_upgradetest (cloned dev, reverted pre-phase-4: event_manager unregistered, sev old cols
      re-added+populated + entity nulled, max_entity_photos stripped, marker unset, event menus removed). Ran REAL update_database via a
      reflection wrapper repointing Globalvars dbname to the scratch DB with a HARD current_database() guard (dev never opened):
        * WITHOUT --cleanup: migrations 2 run 0 failed; auto-activation ✓ "Activated plugin 'event_manager'" (store=yes,events=yes);
          activate.php folded all 22530 sev rows old→entity + dropped old cols; max_entity_photos merged; 3+ event menus seeded. NO data loss.
        * WITH --cleanup: same — 22530 sev rows backfilled, old cols gone, store stc 976 intact, chain 2 run 0 failed. Cleanup ordering safe.
      Scratch DB dropped; dev verified untouched (event_manager active, 22530 sev rows).
- [x] Inactive-state verified earlier (safe 30/30/621, all event URLs hard-404, zero fatals).

### Phase-4 upgrade-sim finding (PHASE-3 gap, NOT fixed — flagged for decision):
- update_database core menu seed warns: syncMenus('core') entry slug='subscription-tiers' references parent slug 'products' which does not
  exist. Cause: phase 3 moved the `products` admin menu to the store plugin, but core admin_menus.json keeps `subscription-tiers`
  (settingActivate=subscriptions_active) parented under `products`. Non-fatal on existing installs (row persists), but a FRESH install skips
  seeding subscription-tiers (admin reachable only by URL). Needs a product decision on where subscription-tiers lives now (top-level core, or
  under a core parent) — left unchanged to avoid an arbitrary nav change.

### Phase-4 deviations / notes:
- File↔event-session attach (point 7): gated-in-place on plugin-active rather than relocated to event_manager admin logic (get_event_sessions
  + fileadd/fileremove were DEAD; only the upload-attach path is live and gated). Functionally equal (works active, absent inactive).
- admin_users_message: gated-in-place rather than full provider-fed targeting refactor (secondary admin page; works active, errors cleanly inactive).
- event_manager SEO admin_edit_url uses correct plugin path; store's still says /admin/admin_product_edit (pre-existing latent 404 — left per "don't touch store").
- migrations.php still has core-orders/core-subscriptions imperative seeds (phase-3 gap; prune migration v147 cleans the DB rows).
- /profile/event_register_finish (purchase-receipt email link) has no view file anywhere — pre-existing, not introduced by this move.

## Phase 5 — Finalize
- [ ] Regenerate joinery-install.sql.gz
- [ ] Update docs
- [ ] Move spec to implemented/; trim plugin_ajax_namespace_collision.md

## KEY PHASING DECISION (applies to ALL registries)
Phase 2 = registry MECHANISM only; behavior stays IDENTICAL. So core `registerCoreDefaults`
registers EVERYTHING currently hardcoded, INCLUDING to-be-moved types (product/event/location).
In phase 3/4 those specific registrations MOVE OUT of core defaults into plugin serve.php.
Search marker in code: `MOVED-TO-PLUGIN (phase 3/4)`.

### Registrations to MOVE to plugin serve.php (remove from core defaults):
STORE (phase 3):
- SeoPageMetadata::register_core_entity_classes → remove 'product' line; add to store serve.php
- TierGatedContentRegistry::registerCoreDefaults → remove 'Products'; add to store serve.php
- EntityPhotoRegistry::registerCoreDefaults → remove 'product'; add to store serve.php
EVENT_MANAGER (phase 4):
- SeoPageMetadata → remove 'event','location'; add to event_manager serve.php
- TierGatedContentRegistry → remove 'Events'; add to event_manager serve.php
- EntityPhotoRegistry → remove 'event','location'; add to event_manager serve.php

### Core-default registration idiom chosen:
Inline `X::registerCoreDefaults()` (or `register_core_*()`) call at BOTTOM of each registry/class
file (AbstractProductRequirement precedent). Works web + CLI. Plugins add via serve.php.
Registry classes created in includes/ during phase 2; STORE-OWNED ones (FulfillmentRegistry,
TierBilling) move to plugins/store/includes/ in phase 3.

## Phase-4 followups noted while in phase 2:
- admin_users_message.php + logic: guard `new Event`/`MultiEventRegistrant`/`MultiWaitingList` behind plugin-active/class_exists; make targeting provider-fed; view line 6 remove events_class require, evt_name → reference_label. (Works now since events core; decouple when events move.)
- sev (session_analytics) column generalization: DEFERRED to phase 4 with event_sessions_class.php move. Columns sev_entity_type/id already ADDED (unused). Old sev_evt_event_id/sev_evs_event_session_id still present. Rewrite record_analytic + get_last_visited_* queries (join evs to recover event) + drop migration generalize_sev_entity.php in phase 4.
- MessageContextRegistry 'event' resolver: MOVED-TO-PLUGIN → event_manager serve.php
- Recipient providers EventRecipientProvider + EventWaitingListRecipientProvider: MOVE to event_manager (files + registration)

## Phase-3 followups noted while in phase 2:
- admin_subscription_tiers.php "Products Granting Tiers" panel (raw SQL pro_products) → move to store product admin
- checkout_ajax.php still references CurrencyHelper (fine; file deleted in phase 3)

## Registries built so far (phase 2):
- includes/TierGatedContentRegistry.php (+consumer admin_subscription_tiers.php) DONE
- includes/MessageContextRegistry.php (class only; consumer conversion pending)
- includes/EntityPhotoRegistry.php (+consumer entity_photos_ajax.php) DONE
- includes/AccessGateRegistry.php (class only; consumers pending)
- includes/RecipientGroupProviderRegistry.php (class only; GroupProvider + consumers pending)
- includes/FulfillmentRegistry.php (class only; consumers pending; MOVES to store phase 3)
- includes/ProfileDashboardRegistry.php (class only; consumers pending)
- SeoPageMetadata registry conversion (+consumer admin_seo_page_edit_logic) DONE

## Notes / drift found
- PluginManager sync parent::sync at :1148 (spec said :1148 ✓)
- site_currency DB value is 'usd' (manifest default "US Dollar" is pre-v130 but harmless via ON CONFLICT)
- Route delegation MECHANISM in RouteHelper done; serve.php `plugin` entries added in phase 3/4 when files move

## Code-review fix pack (applied + verified 2026-07-09, after phase 2)
All 10 findings from the high-effort review were fixed and verified (safe tier 30/30, live anon smoke):
1. AccessGateRegistry::userMayAccess now (?string $provider, $ref, ?int $user_id): null provider→true, anonymous+gated→false, unknown provider→false.
2. FileStorageProfile eligibilityWhere/reverseEligibilityWhere use fil_access_provider IS NULL (old fil_evt_event_id reference removed).
3. Tier feature keys canonicalized to dns_filtering_scrolldaddy_*; all plugin call sites updated; migrations/rename_scrolldaddy_tier_feature_keys.php written AND already run on dev (sbt_features rows carry prefixed keys).
4. update_database: column-drop cleanup moved to Step 4.5 (AFTER migrations) so --cleanup can't drop un-backfilled columns. processAdvancedColumnOperations at step 2 no longer drops.
5. theme requires_plugins REMOVED from phillyzouk/devonnearhill/zoukroom theme.json (event_manager plugin doesn't exist yet). ThemeManager::activate() enforcement mechanism KEPT. → MUST REINSTATE the three declarations in phase 4 when event_manager lands.
6. RecipientGroupProviderRegistry::get(?string): ?RecipientGroupProvider; all 4 admin email callers guard if($provider).
7. tier_gate_prompt: empty tier_upgrade_url falls back to TierBilling::getUpgradeOptions() product buttons (class_exists-gated, file_exists-guarded require) → degrades to contact message once store is a plugin. Phase 3: when TierBilling moves to plugins/store/includes/, this fallback must still resolve when the store plugin is ACTIVE (registry/provider or keep class loadable), and degrade cleanly when inactive.
8. TierBilling::getUpgradeOptions uses correct MultiProduct option keys (is_active, deleted).
9. cart_charge_logic: pro_fulfillment_provider set but unresolvable → error_log + ord_error stamped on order, charge still completes. (Phase 4: event fulfillment provider registration moves to plugin serve.php; this error path is the deactivated-plugin behavior.)
10. discover_model_classes gained plugin_status option ('all' default; 'active' filters via PluginHelper); apiv1.php passes 'active'; per-class loader catches Throwable. Maintenance scripts keep 'all'.

## Phase 3 code review (2026-07-10) — 10 CONFIRMED findings, FIX PACK IN PROGRESS
Phase 3 is NOT a working checkpoint: purchase flow broken end-to-end on dev (verified live).
Findings (fix pack details in supervisor handoff):
1. Cart wiped every request — ShoppingCart instance serialized into $_SESSION but class loads after session_start → __PHP_Incomplete_Class discarded by instanceof guard.
2. /cart_charge, /cart_confirm, /cart_clear have no serve.php delegated routes → 404; Stripe success_url lands on 404 after charging the card.
3. StripeCustomer::GetForUser/GetUserIdByCustomerId call SingleRowFetch with 3 of 5 required args + array-access a FETCH_OBJ row → fatal on every Stripe customer lookup; would create duplicate Stripe customers.
4. update_database cleanup (Step 4.5) drops usr_stripe_* columns BEFORE store auto-activation backfill runs → permanent loss of Stripe mappings on cleanup-mode upgrade.
5. Migration v145 references pro_fulfillment_* columns that only exist after store plugin sync (which runs after migrations) → first upgrade run fails v145, blocks v146/v147.
6. Store admin pages + core adm pages still link/redirect to retired /admin/admin_* URLs → 404s throughout store admin (post-save redirect, breadcrumbs, order links).
7. Core files (profile_logic:23, events_class:225-226, admin_user_logic:126) unconditionally require plugins/store files → fatal on store-less installs.
8. RouteHelper plugin-inactive hard-404 runs AFTER check_setting → missing setting row (fresh install) falls through to theme view, leaking store pages. Gate must run first.
9. /ajax/checkout_ajax outside the plugin-inactive gate → live store endpoint (or 500) when store inactive.
10. $menu_data['cart'] no longer always set but no core PublicPage class isset-guards it → undefined-key warnings on every store-less page (already in dev error log).
LESSON RECORDED: page-200 browser checks are NOT verification. Acceptance requires DRIVEN flows
(end-to-end purchase incl. cart persistence + charge + order row + fulfillment) and upgrade
simulation from pre-migration DB state, with and without cleanup mode.
Commit of phases 1-3 is HELD until fix pack lands + re-verification.

## Phase 3 code review FIX PACK — ALL 10 APPLIED (2026-07-10)
php -l clean on all 236 changed PHP files; validate_php_file clean on key files;
`php tests/run.php safe` = 30/30, 622 checks, routing 61/61.

1. [FIXED+DRIVEN] Cart persistence — ShoppingCart now stores PLAIN session data
   (SESSION_KEY holds items as product_id/version_id/form_data/price/discount + coupons +
   billing_user + last_receipt), never a class instance. current() rehydrates a live cart
   (rebuilds Product/ProductVersion by id); every mutating method calls persist(); direct
   billing_user writes in checkout_logic call persist(). DRIVEN LIVE: product→/cart→/checkout
   across 3 fresh requests, item + question answer + total all survived; billing user
   auto-fill persisted; /cart_clear emptied it. Zero __PHP_Incomplete_Class in log.
2. [FIXED+DRIVEN] Added delegated routes /cart_charge, /cart_confirm, /cart_clear (plugin=store,
   check_setting=products_active). Audited all plugins/store/views/* — every top-level + profile
   view has a route. DRIVEN: /cart_confirm and /cart_clear resolve (were 404); checkout renders
   Stripe card form + PayPal (the /cart_charge POST targets).
3. [FIXED+DRIVEN] StripeCustomer::GetForUser / GetUserIdByCustomerId now call SingleRowFetch
   with 5 args (PDO type + SINGLE_ROW_ALL_COLUMNS) and read FETCH_OBJ props (->stc_*), mirroring
   the deleted User::GetByStripeCustomerId. DRIVEN: checkout billing-user + Stripe price render
   with no fatal (both go through read_customer_id → GetForUser).
4. [FIXED] update_database column-drop cleanup moved to be the LAST schema step — after plugin
   sync AND store/event auto-activation — so activation-hook backfills (usr_stripe_customer_id →
   stc_stripe_customers, pro_evt_event_id → pro_fulfillment_*) read the columns before they drop.
   Cleanup errors folded back into the migration_log row post-hoc. Closes the bug class (Phase 2
   had the same shape).
5. [FIXED] Migration v145 (generalize_pro_fulfillment) neutered to a success no-op tombstone
   (NOT renumbered — already recorded run on dev). The pro fulfillment backfill + pro_evt_event_id
   drop moved into store activate.php step 1b, guarded on the old column existing. No core
   migration references plugin-owned columns now.
6. [FIXED+DRIVEN] Swept plugins/store/admin/** + core adm/** — repointed every link/redirect to
   a MOVED store page to /plugins/store/admin/* (moved-slug allowlist + negative lookahead so
   admin_order≠admin_orders and core pages admin_user/admin_settings*/admin_shadow_session_edit
   stay /admin/*). Core→store links gated: settings Payment-Settings tab, admin_user Payment-
   Methods altlink, admin_subscription_tiers "Products Granting Tiers" panel all skip when store
   inactive. DRIVEN: store admin list→edit→SAVE→redirect→view all land on /plugins/store/admin/*
   (post-save redirect was 404 before); fulfillment picker + breadcrumbs correct.
7. [FIXED+DRIVEN(active)] Gated store requires + usage behind PluginHelper::isPluginActive('store')
   in logic/profile_logic.php (orders/subscriptions null when inactive), data/events_class.php
   get_register_url (returns external link or null), adm/logic/admin_user_logic.php + adm/admin_user.php
   (orders/subscription panels omitted when inactive). DRIVEN: /profile renders with store active.
   Store-INACTIVE degradation pending (needs deactivation — DB write, see below).
8. [FIXED] RouteHelper: plugin-active check now runs BEFORE check_setting, so a fresh install
   (gating setting row absent until activation) 404s instead of soft-falling through to a theme
   view. (verified store-active routing intact; fresh-install path pending deactivation test.)
9. [FIXED] /ajax/checkout_ajax gated at top on isPluginActive('store') → 404 when inactive,
   before it requires any store file.
10. [FIXED] isset-guarded $menu_data['cart'] in all 5 core PublicPage consumers (PublicPage,
    JoinerySystem, Falcon, Tailwind, TailwindHTML5). Theme overrides already guarded. DRIVEN:
    zero cart undefined-key warnings in log across the browse session.

DRIVEN DB-WRITE VERIFICATIONS — ALL PASSED (2026-07-10, user authorized):
- STORE-INACTIVE degradation: flipped store plg_active=0 (reversible, restored after). All store
  URLs + /ajax/checkout_ajax hard-404; /, /events, /event/{slug}, /profile, /login all 200;
  ZERO PHP warnings/fatals across the whole session (findings 7/8/9/10 confirmed live). Restored
  store active, URLs 200 again.
- END-TO-END PURCHASE (Stripe test mode): added $5 product → cart persisted → checkout → paid
  with 4242 test card → /cart_confirm receipt rendered. DB: order 6460 status=2 PAID, error=none,
  order_item 6470 (fulfillment ran); stripe customer rows for the user STAYED 1 (reused
  cus_UBrS89DuP1MU5e — NO duplicate, finding 3 payoff). last_receipt survived the cart_charge→
  cart_confirm redirect (finding 1 persistence for receipts).
- UPGRADE SIMULATION on scratch DB joinery_upgradetest (cloned dev, reverted to pre-extraction:
  usr_stripe cols repopulated, stc dropped, pro_evt_event_id restored, fulfillment cols dropped,
  store inactive, v145/146/147 pending). Ran the REAL update_database CLI via a reflection wrapper
  that repoints Globalvars dbname to the scratch DB (shared config untouched; dev DB never opened):
    * WITH --cleanup: migrations #Run 3 #Failed 0 (v145 tombstone SUCCESS → v146/v147 ran, finding
      5); plugin sync recreated stc + re-added pro_fulfillment cols; auto-activation activated store
      → activate.php backfilled 976 stc rows + 57 fulfillment rows + dropped old cols; DEFERRED
      cleanup ran LAST. Final: 976 stc rows (exact baseline), usr_stripe cols gone, no data loss —
      proves cleanup no longer pre-empts the backfill (finding 4). Under old ordering this would
      have yielded 0 stc rows.
    * WITHOUT --cleanup: chain completes (3 run, 0 failed), 976 stc + 57 fulfillment backfilled;
      activate.php self-drops the plugin-owned old columns regardless of cleanup. Confirms the
      chain + backfill don't depend on cleanup mode.
  Scratch DB dropped, snapshot removed. Dev DB verified healthy (store active, 976 stc, 0 usr_stripe
  cols, homepage 200). Final safe gate 30/30, 622 checks.

ALL 10 FINDINGS FIXED + DRIVEN-VERIFIED. Ready for re-review; commit still HELD pending user's
explicit go-ahead (per version-control rules).

## Phase 4 code review (2026-07-10) — 10 CONFIRMED findings, FIX PACK PENDING

High-effort workflow review of the working-tree diff vs e9cfc234 (phases 1-3 commit). 26 candidate
findings, adversarial verify pass kept 25, deduped to 10 reported. Supervisor spot-verified the top
findings in source. Ranked most-severe first:

1. STORE ADMIN FATALS: plugins/store/admin/logic/admin_product_edit_logic.php:14 and
   plugins/store/admin/admin_order_item_edit.php:10-11 still require deleted core paths
   /data/events_class.php + /data/event_registrants_class.php; plugins/store/tests/products/
   ProductTester.php:29 same. Product edit + order item edit pages are DOWN even with both
   plugins active. (Executor's 6-file dead-require sweep missed these.)
2. THEME EVENT PAGES 500: theme/zoukroom-html5/views/event.php:13 (event_logic.php) and
   theme/phillyzouk-html5/views/events.php:3 (events_logic.php) load moved logic via
   getThemeFilePath WITHOUT the 'event_manager' plugin argument → File not found on those themes.
3. MOBILE WITHDRAW BROKEN: ios .../MemberAPI.swift:65 and android .../MemberApi.kt:60 still call
   bare 'event_withdraw'; action now only resolves as 'event_manager/event_withdraw' (sibling
   my_events call WAS renamed). Live-verified: bare name 404s.
4. dns_filtering/logic/profile_logic.php:12-14: three UNGATED requires of event_manager data
   classes that are NEVER USED in the file. Delete them (ScrollDaddy ships without event_manager).
5. ajax/checkout_ajax.php:107 submit_survey: ungated require of event_registrants_class + ungated
   EventRegistrant::check_if_registrant_exists when event_id posted. Gate like the
   SurveyRequirement::afterPurchase path in the same diff.
6. views/site-directory.php:18: fallback elseif file_exists(plugin path) always true → with
   event_manager INACTIVE, loads plugin classes anyway and renders Events/Locations sections whose
   links 404 (or 500s if tables absent). Delete the fallback branch; gate on plugin-active only.
7. SCHEDULED TASKS NOT RE-ATTRIBUTED: sct_scheduled_tasks rows for WeeklyEventsDigest/
   SendPostEventSurveys still have sct_plugin_name NULL (verified on dev) → deactivate/uninstall
   never suspends them, and ScheduledTask::resolve_task_file globs plugins/*/tasks/ without a
   plugin-active check so digests keep sending after deactivation. Re-attribute rows (activate.php)
   + gate resolve_task_file on plugin-active.
8. plugin.json:70 profileMenu re-seeds 'Event Sessions' → /profile/event_sessions with no event id —
   the exact broken menu row migration v140 deleted (always errors). Verified re-seeded on dev.
9. plugins/event_manager/activate.php:36: sev backfill guards only the OLD column existing, not the
   NEW sev_entity_type/sev_entity_id → activation before core update_database fails with raw SQL
   error. Guard on new columns too.
10. views/profile/profile.php + store profile_dashboard_provider: Subscriptions card was
    previously sidebar-only when active_subscription_count > 0; now renders unconditionally in main
    column with 'Nothing here yet.' empty state. Provider should return null on empty to preserve
    prior behavior.

Also flagged by review (below cap, not in the 10): EventsPanel runs its registrations×sessions
fan-out twice per render; store+event dashboard providers each load the same registrant collection
(duplicate query); admin panels read $_GET['show_all'] directly; StaticPageCache serves cached
anonymous event pages after plugin deactivation until cache expiry (self-healing). Refuted: the
IcsHelper inactive-fallback claim (documented degrade-gracefully intent).

Executor-flagged items awaiting user product decision: subscription-tiers menu parent on fresh
installs; store SEO admin_edit_url retired-slug (phase-3 latent).

COMMIT OF PHASE 4 IS HELD until this fix pack lands + independent re-verification.

## Phase 4 code review FIX PACK — ALL 12 APPLIED + DRIVEN (2026-07-10)
php -l + validate_php_file clean on every touched file. safe 30/30 in BOTH plugin states
(624 checks event_manager-active, 621 inactive). No new fatals in log across all driving.

1. [FIXED+DRIVEN] Store admin fatals. admin_product_edit_logic: removed the dead /data/events_class.php
   require AND the dead MultiEvent load + events/numevents render vars (the fulfillment picker is
   provider-fed, so the direct events dropdown was already vestigial). admin_order_item_edit: repointed
   the two event requires to plugins/event_manager/data/*, gated on PluginHelper::isPluginActive('event_manager');
   gated the POST registrant-sync block and the Event registration dropdown on the same flag.
   ProductTester: deleted the dead events_class require (Event never used). Swept all of plugins/store/ —
   only remaining data/event* refs are data/event_logs_class.php (event_logs STAYS core). SurveyRequirement
   already gated (confirmed).
   * SECOND LATENT BUG found while driving order_item_edit ACTIVE (500 the moment the require was fixed):
     MultiEventRegistrant ORDER BY key 'event_id' → base prefixes to nonexistent 'evr_event_id'. Real
     column is evr_evt_event_id. Fixed the caller's sort key. Page never worked post-move; now loads+saves.
   DRIVEN (event_manager ACTIVE): admin_product_edit (load + SAVE→redirect) and admin_order_item_edit
   (load with Event-registration dropdown + SAVE→redirect) both clean. DRIVEN (INACTIVE): both load+save
   with no Event dropdown, no 500.
2. [FIXED+PROVEN] Theme event views: added the 'event_manager' plugin arg (full 6-arg getThemeFilePath
   form) to zoukroom-html5/views/event.php:13 (event_logic) + phillyzouk-html5/views/events.php:3
   (events_logic). Swept every theme/*/views/ — only those two loaded moved logic without the arg
   (zoukroom events.php is self-contained; scrolldaddy already had 'store'). PROVEN via CLI PathHelper
   resolution: both resolve to plugins/event_manager/logic/*.
3. [FIXED+DRIVEN] Mobile withdraw: iOS MemberAPI.swift:65 + Android MemberApi.kt:60 → event_manager/event_withdraw
   (+ header-comment action names). Swept both kits + member_screens_test + gates: no other bare event
   actions (my_events already prefixed; "events"/fixture matches are screen/fixture names). DRIVEN via API:
   bare event_withdraw → 404 Unknown action; event_manager/event_withdraw → 400 auth (resolves).
4. [FIXED] dns_filtering/logic/profile_logic.php: deleted the 3 unused event_manager requires (Event
   classes never referenced; order_items IS used and store is a hard dep).
5. [FIXED+DRIVEN] checkout_ajax submit_survey: moved the event_registrants require into the registrant
   block and gated the block on isPluginActive('event_manager'). DRIVEN (event_manager INACTIVE): POST
   with a posted event_id → 200 {"success":true}, survey answer saved, registrant check skipped, no 500.
6. [FIXED+DRIVEN] site-directory: deleted the always-true file_exists fallback elseif; gate is now
   plugin-active only. DRIVEN (INACTIVE): /site-directory 200, no Events/Locations sections, no 500.
7. [FIXED+DRIVEN] Scheduled tasks two-layer: (a) ScheduledTask::resolve_task_file skips plugins/*/tasks/
   whose owning plugin is inactive; (b) idempotent re-attribution UPDATE in event_manager/activate.php
   (WeeklyEventsDigest/SendPostEventSurveys) + store/activate.php (ReconcileStripeSubscriptions/
   SyncPaypalSubscriptions). Ran the event_manager UPDATE on dev (WeeklyEventsDigest → event_manager,
   is_active left off). Store has no sct rows yet (no-op). PurgeOldErrors stays NULL (genuinely core).
   DRIVEN: resolve_task_file returns NULL for both event tasks when event_manager inactive, resolves
   when active.
8. [FIXED+DRIVEN] Removed the 'Event Sessions' profileMenu entry from event_manager/plugin.json. syncMenus
   metadata-diff prune could not recover dev (metadata had already advanced past the slug) — added
   migration v148 drop_event_manager_event_sessions_menu.php (mirrors v140's drop_event_sessions_menu).
   Ran update_database → row pruned (0 rows remain).
9. [FIXED] sev backfill (event_manager/activate.php): now checks BOTH the old sev_evs_event_session_id
   AND the new sev_entity_type; if old exists but new doesn't, throws a clear "run update_database first"
   Exception (rolls back activation) instead of a raw SQL error.
10.[FIXED+DRIVEN] Dashboard empty-state: added optional ProfileDashboardSection $empty_message. Web
   renderer (profile.php) now: empty+no-message → NO card (stat still feeds the grid); empty+message →
   shows the message. Events provider passes 'No upcoming events.'; subscriptions/recent_orders keep the
   default (hidden when empty). Native (profile_dashboard_logic) untouched — still serializes items+stat,
   so the app contract is unchanged (chosen over "return null" specifically to preserve the always-on
   Active-Subscriptions stat AND the native keys). DRIVEN with a subscription-less member: NO Subscriptions
   card, NO Recent Orders card, NO "Nothing here yet." anywhere; "0 Active subscriptions" stat still shows;
   Upcoming Events card shows "No upcoming events."
11.[FIXED+DRIVEN] admin_menus.json subscription-tiers parent products→users. Core menu seed is insert-only
   (overwrite=false) so it won't re-parent an existing row; the store re-creates the 'products' parent with
   a new id each sync, orphaning the FK. Added migration v149 reparent_subscription_tiers_menu.php (looks up
   the users menu id). Ran update_database → subscription-tiers now parented under Users; DRIVEN in the
   sidebar/DB (renders under Users).
12.[FIXED] store serve.php product SEO admin_edit_url → /plugins/store/admin/admin_product_edit?pro_product_id=.

Optional: [DONE] EventsPanel computes the visited-session rows once (was a double registrations×sessions
fan-out + double last-visit lookup); render loop reuses the precomputed rows. Other below-cap items
(duplicate registrant load across dashboard providers, $_GET['show_all'] in panels, stale static cache
after deactivation) left as logged — not expanded.

DB writes on dev (user-authorized batch): WeeklyEventsDigest re-attribution; update_database (ran v148+v149,
0 failed; re-parented subscription-tiers, pruned Event Sessions row); event_manager plg_active flip
0→drive→1 (reversible, restored; static cache cleared each way); one test-mode $5 purchase (order 6462,
status=2 PAID, no error, no registrant — event_manager INACTIVE, closing the deferred "store purchase with
event_manager inactive" gap); one survey-answer delete (to clear a prior duplicate so the survey POST could
reach the event gate). Final dev state: store + event_manager both ACTIVE; /, /events, /products, /pricing,
/site-directory all 200.

DROVE vs INSPECTED split:
- DROVE (browser/API/CLI, both plugin states): store admin_product_edit + admin_order_item_edit load+save
  (active AND inactive); full store purchase end-to-end (inactive); checkout survey POST with event_id
  (inactive); /site-directory (inactive); event cron resolve gate (both states via CLI); bare vs prefixed
  event_withdraw API; theme event-view logic resolution (CLI); profile dashboard empty-states (member with
  and without subs); event URLs 404 inactive / 200 active; safe 30/30 both states.
- INSPECTED (code/DB, not separately driven): SendPostEventSurveys re-attribution (no dev row to drive —
  the UPDATE is a guarded no-op); store activate.php task re-attribution (store has no sct rows yet);
  the sev new-column activation guard (guard path only triggers pre-update_database — verified by reading,
  not by reverting dev schema); iOS/Android withdraw rename verified by the API 404/400 split + grep sweep,
  not by building the apps.

COMMIT STILL HELD (per version-control rules) pending user go-ahead. Phase 5 NOT started.
