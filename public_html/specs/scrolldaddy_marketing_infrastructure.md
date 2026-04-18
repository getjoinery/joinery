# Specification: ScrollDaddy Marketing Infrastructure

## Overview
Foundation work that removes the technical barriers to marketing for ScrollDaddy:

- **(A) SEO Metadata Hooks** — Extend `PublicPageBase` with the missing head tags (meta description, Twitter Cards, og:locale, optional og_title/og_description), populate `$hoptions` in every entity view, and delete scrolldaddy's duplicated head overrides
- **(B) Coupon Auto-Apply** — Capture marketing-campaign coupon codes from URLs automatically
- **(C) UTM Capture Fixes** — Fix and extend the existing UTM visitor-event capture so attribution survives to conversion
- **(D) Named Conversion Events** — Record cart-add, checkout-start, purchase, signup events so the funnel UI has real signal to work with
- **(E) Attribution Reporting** — A basic admin page that slices visitor-event data by UTM so marketing can see what each channel produces

**Parent / sibling docs:**
- [`scrolldaddy_marketing_plan.md`](scrolldaddy_marketing_plan.md) — strategic plan this spec supports
- [`ab_testing_framework.md`](ab_testing_framework.md) — platform-level multi-armed bandit framework that depends on Part D as its reward signal (previously "Phase 2" of this doc, split out)
- [`product_photo_parity.md`](product_photo_parity.md) — prerequisite for Part A.2's Product row. Brings `Product` into parity with other entities on the primary-photo interface so `$product->get_picture_link('og_image')` works uniformly.

A separate effort ([multiple_domain_capability.md](multiple_domain_capability.md)) may eventually introduce multi-brand support. This spec does **not** pre-wire any of it — every change here is a trivial retrofit if/when multi-brand ships (nullable FK adds, query scoping extensions). Build for what's needed now.

---

## Part A — SEO Metadata Hooks

Three layers:

1. **A.1 — Platform-level additions to `PublicPageBase`.** Fill the real gaps in the base class's head emission: no `<meta name="description">`, no Twitter Card tags, no optional way to set social copy distinct from SEO copy, no `og:locale`. Base already emits canonical + og:title/description/url/type/site_name/image data-driven from `$options` — we extend, not replace. Every theme built on the platform inherits.
2. **A.2 — Populate `$hoptions` in every public entity-driven view.** Posts, products, events, pages, locations, videos, mailing lists each get their own SEO/OG metadata instead of the site-default fallback. Platform-level because the core `/views/*.php` files serve every theme, and the keys match what `PublicPageBase` already consumes.
3. **A.3 — Scrolldaddy cleanup.** With A.1 in place, the scrolldaddy plugin's `PublicPage.php` can shed its duplicated/conflicting head emission — ~25 lines deleted, zero added. No scrolldaddy-specific rewrite.

### Problem

- `PublicPageBase::global_includes_top()` (`includes/PublicPageBase.php:517-580`) already emits a real OG block (og:title, og:description, og:url, og:type, og:site_name, og:image) + canonical link, all data-driven from `$options` with site-level fallbacks. Base is most of the way there.
- **But:** base never emits `<meta name="description">` (the SEO tag, distinct from og:description) — a platform-level gap. No Twitter Cards, no `og:locale`, no way to set social copy distinct from SEO copy.
- The scrolldaddy plugin's `PublicPage.php` compounds the problem:
  - Line 231 reads `$options['description']` for `<meta name="description">`, but `public_header_common()` populates `$options['meta_description']`. **Key mismatch means every scrolldaddy page currently renders an empty meta description.** Hidden bug.
  - Overrides `global_includes_top()` at lines 77-100 with a partial reimplementation that emits only og:image (and custom_css) — throwing away the base's canonical + full OG block.
  - Then hardcodes an entirely separate static OG block in the HTML head at lines 280-288. Every scrolldaddy page shares the same social card regardless of content.
- **Every entity-driven public view** (post, event, product, page, location, video, mailing list) renders without entity-specific `meta_description` or `preview_image_url`. Blog-post shares show the site's generic card instead of the post's title/excerpt/featured image.

### A.1 — Platform-level additions to `PublicPageBase::global_includes_top()`

All additions emit from existing or newly-added `$options` keys, with site-level fallbacks where appropriate. Include the tags liberally — each is cheap and the duplication cost is zero.

1. **`<meta name="description">`.** Emit alongside `og:description` using the same source: `$options['meta_description']` → fallback `$settings->get_setting('site_description')`. Reuse base's existing strip-tags + truncation logic.

2. **Optional `og_title` / `og_description` keys.** If set, use them for og:title / og:description in preference to `title` / `meta_description`. Lets a view write a click-worthy social headline distinct from the SEO title. If not set, fall through to the existing keys (one-line `??` — no behavior change for existing callers).

3. **Twitter Card tags.** Mirror the og values:
   - `twitter:card` = `summary_large_image` (static)
   - `twitter:title` ← og:title value
   - `twitter:description` ← og:description value
   - `twitter:image` ← og:image value (only when an og:image was emitted)

4. **`og:locale`.** Static `en_US`. If multi-locale is ever needed, change to a setting then. Cheap to include now.

Every theme built on the platform inherits these automatically — including scrolldaddy once A.3 removes its overrides.

### A.2 — Populate `$hoptions` in every public view

Populate `meta_description`, `preview_image_url`, and (where not `website`) `og_type` in every public entity-driven view. Keys match what `PublicPageBase` already reads — no new key names introduced for existing concerns.

#### Scrolldaddy static views (hand-written copy)

These views don't have a model-backed entity; copy is written directly into `$hoptions`.

- `plugins/scrolldaddy/views/index.php` — homepage
- `plugins/scrolldaddy/views/pricing.php` — pricing page

**Example for `index.php`:**
```php
$hoptions = array(
    'is_valid_page'     => $is_valid_page,
    'title'             => 'ScrollDaddy | DNS Content Filtering | Save Your Sanity Online',
    'meta_description'  => 'Block social media, porn, gambling, and distractions at the DNS level. 5-minute setup, works on every device on your network. Save your sanity online.',
    'og_title'          => 'ScrollDaddy — Take control of your browsing',
    'og_description'    => 'DNS-level blocking that works across every device. 5-minute setup.',
    'preview_image_url' => 'https://scrolldaddy.app/plugins/scrolldaddy/assets/img/og/og-home.png',
);
```

Copy guidance: follow the marketing plan's format `ScrollDaddy | [Page topic] | Save Your Sanity Online`; mention "5-minute setup" and "DNS-level" where natural; descriptions ≤160 chars.

#### Entity-driven views (read from the loaded model)

Each entity has primary-image support via the polymorphic `eph_entity_photos` system (confirmed across all seven) and the joinery-system theme defines the `og_image` size variant (1200×630, crop=true) in `theme/joinery-system/theme.json`. `$entity->get_picture_link('og_image')` returns the correctly-sized URL.

| View file | Data class | `title` | `meta_description` | `preview_image_url` | `og_type` |
|---|---|---|---|---|---|
| `views/post.php` | `Post` | `pst_title` | `pst_short_description` | `$post->get_picture_link('og_image')` | `article` |
| `views/event.php` | `Event` | `evt_name` | `evt_short_description` (already populated) | `$event->get_picture_link('og_image')` | `article` |
| `views/product.php` | `Product` | `pro_name` | `pro_short_description` | `$product->get_picture_link('og_image')` (requires [`product_photo_parity.md`](product_photo_parity.md) first) | `product` |
| `views/page.php` | `Page` | `pag_title` | strip HTML + truncate `pag_body` to 160 chars | `$page->get_picture_link('og_image')` | (omit — defaults to `website`) |
| `views/location.php` | `Location` | `loc_name` | `loc_short_description` | `$location->get_picture_link('og_image')` | (omit) |
| `views/video.php` | `Video` | `vid_title` | `vid_description` (truncate to 160 if long) | no local file field — fall through to site default; embed-platform thumbnail if the API exposes it | `article` |
| `views/list.php` | `MailingList` | `mlt_name` | `mlt_description` | `$mlt->get_picture_link('og_image')` | (omit) |

`plugins/scrolldaddy/views/page.php` uses the same `Page` model; follow the `views/page.php` pattern.

**Common pattern:**

```php
$hoptions = array(
    'is_valid_page'     => $is_valid_page,
    'title'             => $entity->get('..._title'),
    'meta_description'  => $short_desc,  // ≤160 chars; strip_tags + mb_substr if needed
    'preview_image_url' => $entity->get_picture_link('og_image') ?: null,
    'og_type'           => 'article',  // omit for 'website'
    // Optional — only when social copy should diverge from SEO copy:
    // 'og_title'        => '…',
    // 'og_description'  => '…',
);
```

**Description truncation.** Keep `meta_description` ≤160 chars (Google truncation boundary). Strip HTML; `mb_substr` with a word-boundary clean is fine inline. If a `LibraryFunctions` helper already exists for this, use it.

**Excluded from SEO work:**
- Gated/transactional scrolldaddy views: `cart.php`, `login.php`, `logout.php`, `items.php`, `profile/*`, `forms_example.php`, `cart_confirm.php` — fall through to base-class defaults.
- Members-only entities (user profiles, groups) — rendered via `MemberPage`, not `PublicPage`, and shouldn't be indexed.
- Admin views and migration tooling.

### A.3 — Scrolldaddy cleanup

With A.1 in place, scrolldaddy's `PublicPage.php` can shed its duplicated head emission. Pure deletions:

1. **Delete the `global_includes_top()` override (lines 77-100).** Base now handles og:image (which is all the override was doing, plus a bug around the `preview_image_increment` default assignment at line 85). The override's only other contribution — emitting `<style>` for `custom_css` — is also already done by base (line 571). Nothing is lost.

2. **Delete the hardcoded static OG block in the HTML head (lines 280-288).** Base emits all of these (og:title, og:type, og:url, og:image, og:description, og:site_name, og:locale) from `$options` values populated by A.2.

3. **Delete the `<meta name="description">` line at 231.** A.1 adds this to the base. Leaving the scrolldaddy line in place would render it twice — once correctly from base, once empty from the typo'd `$options['description']` read.

Net: ~25 lines deleted from the scrolldaddy plugin, zero added. The plugin's `PublicPage.php` stops lying about head metadata and just inherits correct behavior.

---

## Part B — `?coupon=CODE` Auto-Apply

### Goal
A marketing URL like `https://scrolldaddy.app/?coupon=PH2026` pre-applies the coupon so the user sees the discounted price throughout the site and never has to type the code at checkout.

### Design decisions

1. **Hook location: `serve.php` front controller, gated by `isset($_GET['coupon'])`.** Marketing capture is an HTTP-request concern, not a session concern. `serve.php` runs once per request before routing; the query-string check keeps the include out of the request path on the 99.99% of requests that have no coupon. Alternatives rejected: `pricing_logic.php` is too narrow (campaigns land on homepage/product/blog pages, not just pricing); `SessionControl::__construct` would mix marketing intake into an already-overloaded auth/session class and force work on CLI contexts that don't need it.

2. **Storage: `$_SESSION['pending_coupon']`.** Persists across page loads for the length of the session. Applied to `ShoppingCart` immediately if a cart is active; applied on the next `add_item()` call otherwise (so coupons work even when users click a marketing link before adding items).

3. **Invalid codes fail silently.** Campaigns outlive coupons. A "coupon invalid" error on the homepage for a user clicking a stale Reddit link damages trust more than a silent miss. No session state written; logged for attribution so we can see traffic on expired codes and decide whether to reactivate.

4. **User-visible confirmation.** When a valid code is captured, store a flash message so pricing and cart pages can display a banner like *"Coupon `PH2026` will be applied at checkout."* Builds trust that the URL "worked."

5. **Coupon-only — no UTM in this helper.** UTM capture already exists in `SessionControl::save_visitor_event()`. Kept separate (see Part C).

### Implementation plan

**New file: `includes/CampaignCapture.php`** — static helper class.

Public methods:
- `CampaignCapture::capture()` — reads `$_GET['coupon']`, validates via `CouponCode::GetByColumn()` + `is_valid()`. On success: stores code in `$_SESSION['pending_coupon']`, sets flash message in `$_SESSION['pending_coupon_flash']`, attempts immediate application to existing `ShoppingCart` if one is active in session. On failure: no session writes; logs the attempt for attribution (see below).
- `CampaignCapture::apply_pending_to_cart(ShoppingCart $cart)` — called from `ShoppingCart::add_item()` (small hook) when `$_SESSION['pending_coupon']` is set. Calls the cart's existing `add_coupon()` method.
- `CampaignCapture::get_flash_message()` — returns string or null, used by views to render the banner. Clears on read.

**Modify `serve.php`:** Add the gated hook after session initialization, before route dispatch:
```php
if (isset($_GET['coupon'])) {
    require_once(PathHelper::getIncludePath('includes/CampaignCapture.php'));
    CampaignCapture::capture();
}
```
Zero overhead when no coupon param is present.

**Modify `includes/ShoppingCart.php`:** In `add_item()`, after adding the item, call `CampaignCapture::apply_pending_to_cart($this)`. Pending coupon key is cleared on successful application so it doesn't re-apply after manual removal.

**Modify `plugins/scrolldaddy/views/pricing.php` and `cart.php`:** Check `CampaignCapture::get_flash_message()` and render a small info banner if set.

**Attribution logging for invalid coupon attempts.** Reuse the existing visitor-events infrastructure — `SessionControl` already writes to `vse_visitor_events`. On both valid and invalid capture, write a `TYPE_COUPON_ATTEMPT` row (see Part D for the constant) with the attempted code in `vse_meta` — not in `vse_source`, which must stay reserved for UTM so Part E's channels report doesn't treat coupon codes as fake channels. Goal is to be able to query *"how many clicks on expired code X this week"* via `WHERE vse_type = TYPE_COUPON_ATTEMPT AND vse_meta = 'PH2026'`.

**No new data classes needed.** Uses existing `CouponCode` model and `ShoppingCart::add_coupon()`.

### Edge cases

- **Coupon already on cart.** `ShoppingCart::add_coupon()` uses `array_unique`, so re-application is a no-op.
- **Code not found or expired.** Silent. No session state written. Logged.
- **User clears cart or logs out.** Pending coupon should survive logout (lives on the PHP session, tied to browser, not user account). Clear on successful checkout.
- **Multiple `?coupon=` visits.** Last one wins (overwrites `$_SESSION['pending_coupon']`).
- **Non-stackable coupons.** `add_coupon()` / `CouponCode::is_valid()` already enforce this — no extra work.

### Security
`CouponCode::is_valid()` already enforces activation, time windows, and usage caps. No new rate-limiting needed — the attack surface is "guess a valid code," and the existing validation is authoritative.

---

## Part C — UTM Capture Fixes

### Context
UTM capture **already exists** in `SessionControl::save_visitor_event()` (`includes/SessionControl.php:399–418`) and runs on every public page view via `PublicPageBase::public_header_common()`. It parses `utm_source`, `utm_campaign`, `utm_medium`, `utm_content` (plus short aliases `vs`/`vc`/`vm`/`vt`) from the query string and writes them to `vse_visitor_events`. Per-request logging is done.

Two gaps remain that block useful attribution:

### Gap 1 — Bind-value bug in `save_visitor_event()`

`includes/SessionControl.php:442–443`:

```php
$q->bindValue(':vse_medium', $source, PDO::PARAM_STR);    // should be $medium
$q->bindValue(':vse_content', $campaign, PDO::PARAM_STR); // should be $content
```

Copy/paste error. `$medium` and `$content` are parsed but never persisted — the `vse_medium` column duplicates source, and `vse_content` duplicates campaign. Two-line fix.

### Gap 2 — No session-sticky UTM

UTM lives only on per-request rows. A user lands with `?utm_source=reddit`, browses three pages, signs up — the signup row has no UTM attached, and reconstructing it requires joining back through `vse_visitor_events` by visitor ID to find the earliest UTM'd event. Doable but fragile.

Fix: in `save_visitor_event()`, once UTM values are parsed, write them to `$_SESSION` on **first touch** (don't overwrite if already set, so original campaign attribution sticks through the session). Small addition:

```php
if ($source && empty($_SESSION['utm_source']))     $_SESSION['utm_source']   = $source;
if ($campaign && empty($_SESSION['utm_campaign'])) $_SESSION['utm_campaign'] = $campaign;
if ($medium && empty($_SESSION['utm_medium']))     $_SESSION['utm_medium']   = $medium;
if ($content && empty($_SESSION['utm_content']))   $_SESSION['utm_content']  = $content;
```

### Gap 3 — Conversion events need session fallback inside `save_visitor_event()`

Conversion events (PURCHASE, SIGNUP from Part D) fire from POST handlers like `/ajax/checkout_ajax.php` where `QUERY_STRING` is empty. Under today's code, those events stamp NULL for `vse_source`/`vse_campaign`/`vse_medium`/`vse_content` — defeating attribution for the events that matter most.

Fix inside `save_visitor_event()`: after the query-string parse, if the event is **not** a page view, fall back to session values. Page views stay landing-only by design (UTM describes the arrival event, not every subsequent navigation — see design note below).

```php
if ($type !== self::TYPE_PAGE_VIEW) {
    if (!$source   && !empty($_SESSION['utm_source']))   $source   = $_SESSION['utm_source'];
    if (!$campaign && !empty($_SESSION['utm_campaign'])) $campaign = $_SESSION['utm_campaign'];
    if (!$medium   && !empty($_SESSION['utm_medium']))   $medium   = $_SESSION['utm_medium'];
    if (!$content  && !empty($_SESSION['utm_content']))  $content  = $_SESSION['utm_content'];
}
```

### Design note: why not stamp UTM on every page view?

Stamping UTM on every page view within a session was considered and rejected. It's the same anti-pattern we rejected one level up when we chose not to stamp UTM onto `usr_users` / `ord_orders` rows — the event row that actually introduced the visitor is the canonical attribution record, and everything else is derivable. One rule for the table: **UTM is stamped when the event is a session-defining moment** (the landing page view, or a conversion). Middle-of-session page navigations don't get stamped because nothing new happened, attribution-wise.

This keeps `vse_visitor_events` normalized, avoids column duplication across rows that describe the same session, and still lets Part E's queries work cleanly: `COUNT(DISTINCT vse_visitor_id) WHERE vse_source = :source` is correct because every attributed visitor has at least one row (their landing) with that source.

### Scope in this spec
- Fix bind-value bug (Gap 1)
- Add first-touch session stickiness (Gap 2)
- Add session-UTM fallback for non-page-view events inside `save_visitor_event()` (Gap 3)
- **Not** in scope: stamping UTM onto `usr_users` / `ord_orders` rows, or stamping session UTM onto post-landing page views. Both deliberately dropped — the conversion event row carries the attribution and is all Part E's reporting needs.

---

## Part D — Named Conversion Events

### Problem
`vse_visitor_events` today only records page views (`vse_type = 1` = `TYPE_PAGE_VIEW`, plus `TYPE_COOKIE_CONSENT = 2`). The admin funnel UI at `/adm/admin_analytics_funnels.php` builds funnels by chaining page URLs — "% of visitors who saw `/pricing` then saw `/cart`." That's page flow, not conversion measurement. And the A/B testing framework (see [`ab_testing_framework.md`](ab_testing_framework.md)) has no reward signal to optimize against without real conversion events.

### Scope of changes

**Add `vse_type` constants** in `data/visitor_events_class.php`:
- `TYPE_CART_ADD = 3` — visitor added an item to the shopping cart
- `TYPE_CHECKOUT_START = 4` — visitor entered the checkout flow
- `TYPE_PURCHASE = 5` — order completed successfully (payment cleared)
- `TYPE_SIGNUP = 6` — new user account created
- `TYPE_LIST_SIGNUP = 7` — visitor subscribed to a mailing list (for content/publishing sites, this is often the primary conversion)
- `TYPE_COUPON_ATTEMPT = 8` — visitor arrived with a `?coupon=CODE` URL (may be valid or expired; see Part B). **Not a conversion** — a diagnostic event. Part E excludes this type from its reports.

**Add schema fields** to `data/visitor_events_class.php` `$field_specifications`:
- `vse_ref_type` — varchar(32), nullable — e.g., `'order'`, `'user'`, `'mailing_list'`
- `vse_ref_id` — int8, nullable — the primary key of the referenced entity
- `vse_meta` — varchar(255), nullable — free-form metadata for non-conversion diagnostic rows (used by `TYPE_COUPON_ATTEMPT` to store the attempted code without polluting the UTM columns)

`vse_ref_type` + `vse_ref_id` are a generic polymorphic pair rather than one-off `vse_order_id` / `vse_user_id` columns — keeps the table usable for future conversion types (subscription lifecycle, referral, etc.) without schema churn. `update_database` materializes all three from `$field_specifications`.

Populate rules:
- PURCHASE → `ref_type='order'`, `ref_id=ord_order_id`
- SIGNUP → `ref_type='user'`, `ref_id=usr_user_id`
- LIST_SIGNUP → `ref_type='mailing_list'`, `ref_id=mlt_mailing_list_id` (one event per list joined — multi-list signups fire multiple events)
- COUPON_ATTEMPT → `vse_meta=<attempted code>`; ref columns null
- Other types leave ref/meta null

**Record events at the five call sites:**

1. **CART_ADD** — in `ShoppingCart::add_item()`, after the item is pushed to `$this->items`, call `SessionControl::save_visitor_event(TYPE_CART_ADD)`. Same place as the new `CampaignCapture::apply_pending_to_cart()` hook from Part B.

2. **CHECKOUT_START** — in `views/cart.php` (the "Checkout" page that renders the payment form), immediately after the `empty($cart->items)` check passes and before the payment form renders. This fires when a user *sees* the payment form with items in cart — the natural funnel step between CART_ADD and PURCHASE, and the metric cart-abandonment rate is computed from. Guard against double-fire with a `$_SESSION['checkout_started']` flag:

   ```php
   if (empty($_SESSION['checkout_started'])) {
       $session->save_visitor_event(VisitorEvent::TYPE_CHECKOUT_START);
       $_SESSION['checkout_started'] = true;
   }
   ```

   Clear `$_SESSION['checkout_started']` in two places so a user who completes a purchase and starts a new cart gets a fresh CHECKOUT_START: (a) right after the PURCHASE event fires in `/ajax/checkout_ajax.php`, and (b) on cart emptied (in `ShoppingCart::clear()` or equivalent).

   Alternatives considered and rejected: firing inside `cart_logic.php` — slightly earlier but less precise about "user saw the form"; firing in `/ajax/checkout_ajax.php` when a Stripe session is created — strictly later in the flow, which shrinks the CHECKOUT_START→PURCHASE gap that we want to measure for abandonment.

3. **PURCHASE** — in `/ajax/checkout_ajax.php`, immediately after the order row is created and payment is confirmed. Stamp `vse_ref_type='order'` + `vse_ref_id=$order->key`. This is the most important event — it's the conversion signal for everything downstream (funnel reports, bandit rewards, revenue-per-channel).

4. **SIGNUP** — in the user registration flow (investigate: `logic/signup_logic.php` or wherever new user rows get inserted). Fire once per genuine new user creation, not for admin-created users. Stamp `vse_ref_type='user'` + `vse_ref_id=$user->key`.

5. **LIST_SIGNUP** — in `User::add_user_to_mailing_lists()` (`data/users_class.php:104`), after each successful registrant row is written. Fire once per list joined: a user subscribing to 3 lists in one submit produces 3 events, each stamped with `vse_ref_type='mailing_list'` + `vse_ref_id=$list_id`. Skip when the subscription already existed (idempotent re-subscribe shouldn't attribute).

**Extend the admin funnel UI:**

`/adm/admin_analytics_funnels.php` currently accepts page URLs as funnel steps. Add a step-type selector per step: "Page URL" or "Event Type." When "Event Type" is picked, the dropdown lists the `TYPE_*` constants. Query builds accordingly — match on `vse_page` or match on `vse_type`. Leave existing page-URL funnels working unchanged.

**Attribution JOIN becomes trivial.** Part C's Gap 3 fix means `save_visitor_event()` automatically falls back to `$_SESSION['utm_*']` when called with a non-page-view type — so conversion events stamp the first-touch UTM onto their own row without Part D's hook sites doing anything special. That means a single query answers *"how many Reddit signups last week":*

```sql
SELECT COUNT(*) FROM vse_visitor_events
WHERE vse_type = 6 AND vse_source = 'reddit'
  AND vse_timestamp > now() - interval '7 days';
```

### Non-goals for Part D
- Stamping UTM onto `usr_users` / `ord_orders` rows (dropped — conversion event row is the attribution record; see Part C scope note)
- Conversion events for subscription lifecycle (tier upgrade, renewal, cancel) — add later as needed
- Attribution reporting UI — split into Part E

---

## Part E — Attribution Reporting

### Goal
A single admin page that slices `vse_visitor_events` by UTM so marketing can answer the obvious questions without writing SQL each time:

- *Which channels are producing signups this month?*
- *Which campaigns convert to paid at the highest rate?*
- *What's the revenue-per-channel rollup?*
- *Where does each channel drop people in the funnel?*

This is platform-level work. The page lives in core `/adm/`, reads from core tables (`vse_visitor_events`, `ord_orders`), and serves any site that uses the existing UTM + conversion-event capture. ScrollDaddy is the first consumer.

### Prerequisites
- Part C (UTM capture correct + session-sticky)
- Part D (conversion events firing with UTM + `vse_ref_type`/`vse_ref_id` stamped)

Without D, there's nothing but page-view rows to group — the page will render empty for every section except "visits by channel."

### Existing patterns reused
- `adm/admin_analytics_stats.php` + `adm/logic/admin_analytics_stats_logic.php` — date-range form, Chart.js time-series line chart, top-items table. This is the template to follow (AdminPage + `process_logic()` + inline `<script>` Chart.js block). No new framework.
- `adm/admin_analytics_funnels.php` — step-by-step funnel with conversion-% per step. Part D already extends this for event-type steps; Part E reuses that query shape with `GROUP BY vse_source`.

### Page design

**File:** `/adm/admin_analytics_attribution.php`
**Logic:** `/adm/logic/admin_analytics_attribution_logic.php`
**Menu entry:** add under the existing `web-statistics` cluster as *"Attribution."*

**Filters (top form):**
- Start date / end date (same pattern as `admin_analytics_stats.php`)
- Optional `vse_source` text filter (exact match, blank = all)
- Optional `vse_campaign` text filter
- "Include test orders" checkbox (default off — exclude `ord_test_mode = true`)

**Section 1 — Channels overview table.** Group by `vse_source`. Columns:

| Source | Visits | Signups | List Signups | Cart-adds | Checkouts | Purchases | Revenue | Visit→Purchase |
|---|---|---|---|---|---|---|---|---|

- Visits count = `COUNT(DISTINCT vse_visitor_id)` WHERE `vse_type = TYPE_PAGE_VIEW` AND `vse_source = :source` for the date range. This counts distinct attributed visitors, not page views — semantically correct even though UTM is only stamped on landing rows (every reddit visitor has at least one landing row with `vse_source = 'reddit'`). Relies on Part C Gap 3 for conversion-row attribution; does not require stamping UTM on every page view.
- Conversion counts = COUNT(*) grouped by `vse_type` for the matching event rows.
- Revenue = SUM(`ord_total_cost`) from orders joined via `v.vse_ref_type='order' AND v.vse_ref_id=o.ord_order_id`, excluding refunds (`ord_refund_amount IS NULL OR = 0`).
- Visit→Purchase = purchases / visits, formatted as %. Handle div-by-zero.
- Rows sorted by revenue desc; trailing row for `NULL`/empty source (direct/organic).

**Section 2 — Time-series chart.** Stacked-area Chart.js chart, one series per top-N source (top 5 by visits), daily buckets, filtered by the date range. Reuses the `$xvals`/`$yvals` pattern from `admin_analytics_stats.php` but with per-source datasets.

**Section 3 — Funnel-by-channel.** For each top-N source, render a compact funnel: Visits → Signups → Cart-adds → Checkouts → Purchases, with the conversion % between steps. This is the existing Part D funnel query with `AND vse_source = ?` bound. Visualizes where specific channels leak.

**Section 4 — Campaign drilldown.** Below the channels table, a second table grouped by (`vse_source`, `vse_campaign`). Same column set. Lets you see that `reddit`/`nosurf_ama` performs differently from `reddit`/`selfhosted_thread`.

### Sample queries

**Signups by channel:**
```sql
SELECT COALESCE(vse_source, '(direct)') AS source, COUNT(*) AS signups
FROM vse_visitor_events
WHERE vse_type = :TYPE_SIGNUP
  AND vse_timestamp BETWEEN :start AND :end
GROUP BY source
ORDER BY signups DESC;
```

**Revenue by channel:**
```sql
SELECT COALESCE(v.vse_source, '(direct)') AS source,
       COUNT(o.ord_order_id) AS orders,
       SUM(o.ord_total_cost) AS revenue
FROM vse_visitor_events v
JOIN ord_orders o
  ON v.vse_ref_type = 'order' AND v.vse_ref_id = o.ord_order_id
WHERE v.vse_type = :TYPE_PURCHASE
  AND v.vse_timestamp BETWEEN :start AND :end
  AND (o.ord_refund_amount IS NULL OR o.ord_refund_amount = 0)
  AND (o.ord_test_mode = FALSE OR :include_test = TRUE)
GROUP BY source
ORDER BY revenue DESC;
```

**Per-channel funnel counts** — Part D funnel query with `AND vse_source = :source` bound per row.

### Design notes

- **Source normalization.** `Reddit`, `reddit`, `Reddit.com` become three rows otherwise. Lowercase in the query (`LOWER(vse_source)`). Don't mutate the stored value — the capture layer stays untouched; only the reporting query normalizes.
- **Null source handling.** Coalesce to `(direct)` in results so the row is always visible; don't silently drop.
- **Single-table grouping where possible.** Sections 1–3 (excluding revenue) all slice `vse_visitor_events` alone, no joins. Revenue is the only column that joins `ord_orders`, and only for the Purchases row.
- **Don't pre-aggregate.** Query per-request over the selected date range. `vse_visitor_events` is indexed on `vse_timestamp` (existing); add an index on `(vse_source, vse_type, vse_timestamp)` if query time becomes an issue at volume. Don't build materialized views until numbers force it.
- **Keep Chart.js 2.8.0 for parity** with the other analytics pages unless the whole admin is being upgraded.
- **Defensive type filters in every query.** `vse_visitor_events` is a grab-bag event log (page views + cookie consent + conversions + diagnostic events like `TYPE_COUPON_ATTEMPT`). Every Part E query must enumerate the `vse_type` values it counts — never a bare `COUNT(*)` against the table, never a `>= N` range filter. The conversion-types-only filter is `WHERE vse_type IN (TYPE_CART_ADD, TYPE_CHECKOUT_START, TYPE_PURCHASE, TYPE_SIGNUP, TYPE_LIST_SIGNUP)`. Page-view filter is `WHERE vse_type = TYPE_PAGE_VIEW`. When a new event type is added later, this convention prevents it from silently appearing in reports.

### Non-goals for Part E

- Multi-touch attribution (first-touch / last-touch / linear / time-decay / data-driven). Every section in Part E is implicitly *last-touch on the event row* — i.e., the UTM that was in session when the conversion fired. See [`FUTURE_attribution_models.md`](FUTURE_attribution_models.md) for the speculative upgrade path.
- Per-visitor journey drill-down (click a conversion → see the visitor's full event stream). Useful but its own feature.
- Cohort retention (users acquired in week N, still active in week N+k, grouped by channel). Requires a different query shape and some design thinking about "active."
- CAC / ROAS — requires ad-spend data from platforms we don't integrate with.
- Exporting to CSV / scheduled emailed reports. Add when a user asks.

### Implementation checklist

- [ ] Add `vse_ref_type` + `vse_ref_id` to `data/visitor_events_class.php` `$field_specifications` (Part D prereq; see Part D checklist for the hook wiring)
- [ ] Create `/adm/logic/admin_analytics_attribution_logic.php` with: filters parsing, channels-overview query, time-series query, per-channel funnel query, campaign drilldown query
- [ ] Create `/adm/admin_analytics_attribution.php` rendering: filter form, channels table, Chart.js time-series, funnel grid, campaign drilldown
- [ ] Add admin menu entry ("Attribution") under the Statistics cluster
- [ ] Source normalization via `LOWER(vse_source)` in queries; null → `'(direct)'`
- [ ] Exclude test orders from revenue by default; checkbox to include
- [ ] Smoke-test: with Part C + D wired, produce a purchase via `?utm_source=test`, confirm the row appears under `test` with correct revenue

---

## Documentation Updates

**Platform-level docs (applies to every site using the core views):**
- Add a new section to `docs/theme_integration_instructions.md` (or a fresh `docs/seo_metadata.md` if that's cleaner) documenting the standard `$hoptions` metadata keys every public view should populate: `title`, `meta_description`, `preview_image_url`, `og_type`, plus optional `og_title` / `og_description`. Include the canonical "Common pattern" snippet from Part A.2 so future entity views can copy/paste.
- Note that `PublicPageBase::global_includes_top()` is the canonical head emission — themes and plugins should not override it to re-emit tags.
- Document the `vse_ref_type` / `vse_ref_id` convention in a `docs/analytics.md` (or extend an existing one) so future conversion-event authors know to stamp the reference. Note the attribution reporting page at `/admin/admin_analytics_attribution` and the queries it runs.

**Scrolldaddy plugin docs (`docs/scrolldaddy_plugin.md`):**
- The `?coupon=` capture flow and the `CampaignCapture` helper
- First-touch UTM session stickiness and how conversion-time code can read `$_SESSION['utm_*']`
- The new conversion event type constants and where they fire
- Note that scrolldaddy's `PublicPage.php` no longer overrides head emission — it inherits the platform-level OG / Twitter Card / meta-description block from `PublicPageBase`. Per-page metadata comes from `$hoptions` populated by each view (Part A.2).

---

## Implementation Checklist

### Part A — SEO

**A.1 Platform-level additions (`includes/PublicPageBase.php::global_includes_top()`)**
- [ ] Emit `<meta name="description">` from `$options['meta_description']` (fallback to `site_description` setting); reuse the existing strip-tags + truncate logic
- [ ] Add optional `og_title` / `og_description` key support — fall through to `title` / `meta_description` when unset
- [ ] Emit Twitter Card tags (`twitter:card = summary_large_image`, plus title/description/image mirroring og values)
- [ ] Emit `<meta property="og:locale" content="en_US">`

**A.2 Populate `$hoptions` — scrolldaddy static views**
- [ ] `plugins/scrolldaddy/views/index.php` — homepage copy
- [ ] `plugins/scrolldaddy/views/pricing.php` — pricing copy

**A.2 Populate `$hoptions` — entity-driven core views**
- [ ] `views/post.php` — `title`/`meta_description`/`preview_image_url`/`og_type=article` from `Post`
- [ ] `views/event.php` — add `preview_image_url` from `$event->get_picture_link('og_image')`; keep `og_type=article`
- [ ] `views/product.php` — from `Product`; `preview_image_url` works after [`product_photo_parity.md`](product_photo_parity.md); `og_type=product`
- [ ] `views/page.php` (and `plugins/scrolldaddy/views/page.php`) — `pag_title` + stripped/truncated `pag_body` + `get_picture_link('og_image')`
- [ ] `views/location.php` — from `Location` (omit `og_type`, defaults to `website`)
- [ ] `views/video.php` — from `Video`; image falls through or uses embed thumbnail; `og_type=article`
- [ ] `views/list.php` — from `MailingList` (omit `og_type`)

**A.3 Scrolldaddy cleanup (`plugins/scrolldaddy/includes/PublicPage.php`)**
- [ ] Delete the `global_includes_top()` override (lines 77-100)
- [ ] Delete the hardcoded static OG block in the HTML head (lines 280-288)
- [ ] Delete the `<meta name="description">` line at 231 (now emitted by base)

**Verification**
- [ ] Smoke-test each entity view: load a representative instance, assert meta tags are populated, non-duplicate, and reflect entity data (curl + grep for `<meta` / `<link rel="canonical"`)
- [ ] Verify social preview via Facebook Sharing Debugger or Twitter Card Validator on at least one URL per entity type
- [ ] Confirm scrolldaddy pages render one meta description (not zero, not two) after A.3 cleanup

### Part B — Coupons
- [ ] Create `includes/CampaignCapture.php` with `capture()`, `apply_pending_to_cart()`, `get_flash_message()`
- [ ] Wire gated hook into `serve.php`: `if (isset($_GET['coupon'])) { require + CampaignCapture::capture(); }`
- [ ] Hook `apply_pending_to_cart()` into `ShoppingCart::add_item()`
- [ ] Add flash banner to `pricing.php` and `cart.php`
- [ ] Log invalid-coupon attempts to `vse_visitor_events` for attribution
- [ ] Test: valid code, expired code, invalid code, code-before-cart, code-then-checkout-clear, repeated `?coupon=` visits

### Part C — UTM
- [ ] Fix bind-value swap in `includes/SessionControl.php:442–443` (`$medium`/`$content`)
- [ ] Add first-touch session stickiness for `utm_source`, `utm_campaign`, `utm_medium`, `utm_content` in `save_visitor_event()`
- [ ] Add session fallback in `save_visitor_event()` for non-page-view event types (Gap 3)
- [ ] Test: land with `?utm_source=reddit`, browse to a second page with no UTM, confirm `$_SESSION['utm_source']` still reads `reddit`
- [ ] Test: fire a conversion event from a POST handler with no query-string UTM; confirm the written row has `vse_source=reddit` via the session fallback

### Part D — Conversion Events
- [ ] Add `TYPE_CART_ADD=3`, `TYPE_CHECKOUT_START=4`, `TYPE_PURCHASE=5`, `TYPE_SIGNUP=6`, `TYPE_LIST_SIGNUP=7`, `TYPE_COUPON_ATTEMPT=8` constants to `data/visitor_events_class.php`
- [ ] Add `vse_ref_type` varchar(32), `vse_ref_id` int8, `vse_meta` varchar(255) fields to `$field_specifications`
- [ ] Hook `CART_ADD` in `ShoppingCart::add_item()` (same site as Part B hook)
- [ ] Hook `CHECKOUT_START` in `views/cart.php` after the empty-items check passes, guarded by `$_SESSION['checkout_started']`; clear the flag on PURCHASE and on cart-emptied
- [ ] Hook `PURCHASE` in `/ajax/checkout_ajax.php` after successful order + payment confirmation; stamp `vse_ref_type='order'` + `vse_ref_id=$order->key`
- [ ] Hook `SIGNUP` in user registration flow (genuine new-user creation only); stamp `vse_ref_type='user'` + `vse_ref_id=$user->key`
- [ ] Hook `LIST_SIGNUP` in `User::add_user_to_mailing_lists()` (one event per list joined, skip idempotent re-subscribes); stamp `vse_ref_type='mailing_list'` + `vse_ref_id=$list_id`
- [ ] Extend `/adm/admin_analytics_funnels.php` with per-step "Page URL vs Event Type" selector
- [ ] Test: full cart → purchase flow produces four events in `vse_visitor_events` with UTM attributed (via Part C Gap 3) and PURCHASE row joinable to `ord_orders`

### Part E — Attribution Reporting
- [ ] `/adm/logic/admin_analytics_attribution_logic.php`: filters + four queries (channels overview, time-series, funnel-by-channel, campaign drilldown)
- [ ] `/adm/admin_analytics_attribution.php`: filter form, channels table, Chart.js time-series, per-channel funnel grid, campaign drilldown table
- [ ] Add "Attribution" menu entry in the Statistics cluster
- [ ] Source normalization (`LOWER(vse_source)`, null → `'(direct)'`)
- [ ] Test order exclusion with override checkbox
- [ ] Smoke-test with `?utm_source=test` round-trip through to a completed purchase

### Docs
- [ ] Add a platform-level SEO/$hoptions doc (new `docs/seo_metadata.md` or section in `docs/theme_integration_instructions.md`) covering the standard `$hoptions` keys and the canonical "Common pattern" snippet for entity views
- [ ] Update `docs/scrolldaddy_plugin.md` with CampaignCapture usage, session UTM reads, conversion event constants, and the PublicPage.php head-block changes

---

## Out of Scope
- Per-page OG images (hooks ship now; actual images can come later)
- Admin UI for managing campaign coupons (use existing coupon admin)
- Rich Schema.org / JSON-LD structured data
- Sitemap.xml generation
- Stamping UTM onto `usr_users` / `ord_orders` rows (event row is the attribution canon)
- Subscription-lifecycle conversion events (tier upgrade, renewal, cancel)
- Multi-touch attribution models — speculative; see [`FUTURE_attribution_models.md`](FUTURE_attribution_models.md)
- A/B testing framework itself — split into [`ab_testing_framework.md`](ab_testing_framework.md)
