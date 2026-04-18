# Test Spec: ScrollDaddy Marketing Infrastructure

Smoke tests for the changes in [`scrolldaddy_marketing_infrastructure.md`](scrolldaddy_marketing_infrastructure.md). Goal is to catch obvious bugs — not exhaustive coverage. Run after `update_database` and the v102 migration have been applied.

Test site: `https://joinerytest.site`. Scrolldaddy production: `https://scrolldaddy.app` (docker-prod `scrolldaddy` container).

---

## 0. Prerequisites

- [ ] **Run `update_database`** from admin utilities. This materializes three new columns on `vse_visitor_events`: `vse_ref_type` varchar(32), `vse_ref_id` int8, `vse_meta` varchar(255). Also applies migration v102 (Attribution menu entry).
- [ ] Verify columns exist:
  ```sql
  \d vse_visitor_events
  -- should show vse_ref_type, vse_ref_id, vse_meta
  ```
- [ ] Verify menu entry exists:
  ```sql
  SELECT amu_menudisplay, amu_defaultpage FROM amu_admin_menus WHERE amu_slug = 'attribution';
  ```
- [ ] Have at least one active `CouponCode` row with `ccd_is_active = true` for testing Part B (note the code).

---

## 1. Part A — SEO metadata

The platform-level head block should render new tags on every public page, and entity views should populate per-page values.

### A.1 — Platform additions are emitted

On any public page (e.g. `/`):

- [ ] View-source shows exactly **one** `<meta name="description" ...>` (not zero, not two)
- [ ] View-source shows `<meta name="twitter:card" content="summary_large_image">`
- [ ] View-source shows `<meta name="twitter:title">` and `<meta name="twitter:description">`
- [ ] View-source shows `<meta property="og:locale" content="en_US">`
- [ ] View-source shows `<link rel="canonical">` pointing to the current URL

### A.2 — Entity views produce distinct copy

- [ ] Load a blog post (`/post/<slug>`): `<meta name="description">` matches `pst_short_description`
- [ ] Load a product (`/product/<slug>`): `og:type` is `product`; description matches `pro_short_description`
- [ ] Load a page (`/page/<slug>`): description is stripped/truncated `pag_body` (≤160 chars, no HTML)
- [ ] Load an event (`/event/<slug>`): `og:type` is `article`; description matches `evt_short_description`
- [ ] Load `scrolldaddy.app/` (or equivalent via container): title contains "Save Your Sanity Online"; `og_title` differs from `title` ("Take control of your browsing")

### A.3 — Scrolldaddy cleanup verified

On a scrolldaddy page (pricing is a good candidate):

- [ ] View-source shows **one** `<meta name="description">` (the hidden empty-description bug is fixed)
- [ ] Only **one** `<meta property="og:image">` in head (no duplicates from the deleted hardcoded block)
- [ ] `og:title`, `og:description`, `og:url` reflect the current page — not the hardcoded "Scrolldaddy - Take Control of Your Browsing"

### A.4 — Social preview validators (optional)

- [ ] Run one scrolldaddy URL through the [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/) — card renders with correct title/description/image
- [ ] Run one URL through [Twitter Card Validator](https://cards-dev.twitter.com/validator) — summary_large_image card renders

---

## 2. Part B — `?coupon=CODE` auto-apply

Use the valid coupon code from prerequisite 0. Replace `VALIDCODE` below.

### B.1 — Valid code captures + flash shown

- [ ] Clear session (new incognito window). Visit `https://joinerytest.site/?coupon=VALIDCODE`
- [ ] Navigate to `/cart` — flash banner reads "Coupon **VALIDCODE** will be applied at checkout."
- [ ] Add any product to cart from `/products`. Cart total reflects the discount.
- [ ] Query: `SELECT vse_type, vse_meta FROM vse_visitor_events WHERE vse_visitor_id = '<session uniqid>' ORDER BY vse_timestamp DESC LIMIT 5;` — one row with `vse_type = 8` (TYPE_COUPON_ATTEMPT) and `vse_meta = 'validcode'` (lowercased)

### B.2 — Invalid code fails silently

- [ ] Clear session. Visit `https://joinerytest.site/?coupon=BOGUS-NONEXISTENT-XYZ`
- [ ] No error message renders
- [ ] `/cart` flash is **not** shown
- [ ] Query confirms `TYPE_COUPON_ATTEMPT` row with `vse_meta = 'bogus-nonexistent-xyz'` was still logged for diagnostics

### B.3 — Reapply behavior

- [ ] Clear session. Visit `/?coupon=VALIDCODE`. Add to cart, manually remove the coupon from cart. Reload `/cart` — coupon does **not** re-apply (pending key was cleared on successful application)

---

## 3. Part C — UTM capture

### C.1 — First-touch session stickiness

- [ ] Clear session. Visit `/?utm_source=reddit&utm_campaign=test_c1`
- [ ] Navigate to `/products` (no UTM in URL). Dump `$_SESSION['utm_*']` (or use admin/browser DevTools):
  - `$_SESSION['utm_source']` = `reddit`
  - `$_SESSION['utm_campaign']` = `test_c1`
- [ ] Visit `/?utm_source=google` — `$_SESSION['utm_source']` **still reads `reddit`** (first-touch preserved)

### C.2 — Bind-value fix

- [ ] Clear session. Visit `/?utm_source=src_c2&utm_medium=med_c2&utm_content=cnt_c2&utm_campaign=cmp_c2`
- [ ] Query: `SELECT vse_source, vse_medium, vse_content, vse_campaign FROM vse_visitor_events WHERE vse_visitor_id = '<uniqid>' ORDER BY vse_timestamp DESC LIMIT 1;`
  - `vse_source = 'src_c2'`, `vse_medium = 'med_c2'`, `vse_content = 'cnt_c2'`, `vse_campaign = 'cmp_c2'` — **each column holds its own value** (the old bug copied source → medium and campaign → content)

### C.3 — Session fallback for conversion events

Covered by the end-to-end test in section 6 — we confirm there that a `PURCHASE` row fired from a POST handler still stamps the landing UTM.

---

## 4. Part D — Named conversion events

Each conversion event writes a row to `vse_visitor_events` with the correct `vse_type` and (where applicable) `vse_ref_type` / `vse_ref_id`.

### D.1 — CART_ADD

- [ ] Clear session. Add any product to cart.
- [ ] Query: one row with `vse_type = 3` (TYPE_CART_ADD) for this visitor exists.

### D.2 — CHECKOUT_START

- [ ] With items in cart, visit `/cart`.
- [ ] Query: one row with `vse_type = 4`. Reload `/cart` — **no second row** (the `$_SESSION['checkout_started']` guard is working).

### D.3 — PURCHASE

- [ ] Complete a checkout (use Stripe test mode or a free-with-100%-off coupon).
- [ ] Query: one row with `vse_type = 5`, `vse_ref_type = 'order'`, `vse_ref_id` matching the new order's `ord_order_id`.
- [ ] Empty the cart or start fresh; add item; visit `/cart` again — `CHECKOUT_START` row **does** fire (flag was cleared on PURCHASE and on `clear_cart()`).

### D.4 — SIGNUP

- [ ] Register a genuinely new user via `/register`.
- [ ] Query: one row with `vse_type = 6`, `vse_ref_type = 'user'`, `vse_ref_id = <new user id>`.
- [ ] Register a **second** time with the same email — **no additional** `TYPE_SIGNUP` row (CreateCompleteNew detects the existing user via `GetByEmail`).

### D.5 — LIST_SIGNUP

- [ ] Subscribe a user to a mailing list via `/list/<slug>`.
- [ ] Query: one row with `vse_type = 7`, `vse_ref_type = 'mailing_list'`, `vse_ref_id = <list id>`.
- [ ] Re-submit subscription (already member) — **no duplicate** row (idempotent re-subscribe is skipped).

### D.6 — Admin funnel UI with event steps

- [ ] Visit `/admin/admin_analytics_funnels`.
- [ ] For each step row: the "Step N type" dropdown has **Page URL** and **Event Type** options; switching to "Event Type" repopulates the value dropdown with `Page View / Cart Add / Checkout Start / Purchase / Signup / List Signup`.
- [ ] Build a funnel like: `Step 1 = Page URL /`, `Step 2 = Event Type Cart Add`, `Step 3 = Event Type Purchase`. Submit. Table renders without SQL errors.

---

## 5. Part E — Attribution reporting

### E.1 — Page renders

- [ ] Visit `/admin/admin_analytics_attribution`. Page loads with breadcrumb "Statistics → Attribution".
- [ ] Filter form has: start/end date, source, campaign, and "Include test orders" checkbox.
- [ ] Submit with default date range (last 30 days). No PHP warnings in `tail /var/www/html/joinerytest/logs/error.log` after the request.

### E.2 — Channels table

- [ ] The Channels table renders with a row per source. `NULL` / missing sources coalesce to `(direct)`.
- [ ] "Visit→Purchase" column shows `%` or `-` (never divide-by-zero errors).
- [ ] If test-mode orders exist in the date range, they are **excluded** from the Revenue column by default. Check "Include test orders" and resubmit — Revenue now reflects them.

### E.3 — Time-series chart

- [ ] The Chart.js line chart renders under "Visits over time — top sources".
- [ ] Up to 5 series are shown, each labeled with a source name.
- [ ] X-axis is dates in the selected range.

### E.4 — Campaign drilldown

- [ ] Campaign drilldown table renders below the chart, grouped by (source, campaign). Campaigns with no label coalesce to `(none)`.

### E.5 — Defensive type filters

- [ ] No `vse_type >= N` range filters anywhere in `/adm/logic/admin_analytics_attribution_logic.php`. Every query has an explicit `vse_type = :type_X` or `vse_type IN (...)` clause. (Code review — already known green; listed for completeness.)

---

## 6. End-to-end smoke test

Drives the whole pipeline at once. The goal is to verify that UTM + coupon + conversion events all compose.

- [ ] Clear session (incognito window).
- [ ] Visit `https://joinerytest.site/?utm_source=e2e_test&utm_campaign=launch&coupon=VALIDCODE`
- [ ] Navigate to a product, add to cart.
- [ ] Complete checkout (use Stripe test card or a 100%-off valid coupon).
- [ ] Query:
  ```sql
  SELECT vse_type, vse_source, vse_campaign, vse_ref_type, vse_ref_id, vse_meta
  FROM vse_visitor_events
  WHERE vse_visitor_id = '<the uniqid>'
  ORDER BY vse_timestamp ASC;
  ```
- [ ] Expected sequence (approximately):
  - `TYPE_PAGE_VIEW (1)` with `vse_source = 'e2e_test'`, `vse_campaign = 'launch'`
  - `TYPE_COUPON_ATTEMPT (8)` with `vse_meta = 'validcode'`
  - One or more `TYPE_PAGE_VIEW (1)` rows with **NULL** `vse_source` (page views are landing-only, not stamped on every nav — this is by design)
  - `TYPE_CART_ADD (3)` with `vse_source = 'e2e_test'` (session fallback kicks in for conversions)
  - `TYPE_CHECKOUT_START (4)` with `vse_source = 'e2e_test'`
  - `TYPE_PURCHASE (5)` with `vse_source = 'e2e_test'`, `vse_ref_type = 'order'`, `vse_ref_id = <new order id>`
- [ ] Visit `/admin/admin_analytics_attribution`. Set date range to include today. Filter `source = e2e_test`.
  - Channels table shows the `e2e_test` row with non-zero visits, cart-adds, checkouts, purchases, and revenue.
  - Campaign drilldown shows the `(e2e_test, launch)` row.

---

## 7. Product photo parity

Covers the entity photo work in [`implemented/product_photo_parity.md`](implemented/product_photo_parity.md). Only platform-level checks here — verifying Product now matches the other entities and that drift fixes didn't break anything.

### 7.1 — Schema + migration

- [ ] `\d pro_products` shows `pro_fil_file_id int4` (nullable).
- [ ] `SELECT mig_success FROM mig_migrations WHERE mig_version = '103' ORDER BY mig_migration_id DESC LIMIT 1;` → `t` (backfill migration applied).

### 7.2 — Admin UI + upload round-trip

- [ ] Load `/admin/admin_product_edit?pro_product_id=<any product>`. "Product Photos" card renders under the form; "No photos yet" empty state shows when the product has none.
- [ ] Upload two photos via the card's "Upload" button. Both thumbnails render in the grid. Toolbar action "Set as primary" is available on each.
- [ ] After upload, check the DB: `SELECT eph_entity_type, eph_sort_order FROM eph_entity_photos WHERE eph_entity_id = <product id> ORDER BY eph_sort_order ASC;` → two rows with `eph_entity_type = 'product'`.
- [ ] The product's `pro_fil_file_id` is populated (auto-set on first upload via the AJAX `$entity_class_map` path).
- [ ] Set the second photo as primary — page reloads, `pro_fil_file_id` updates. `Product::get_primary_photo()` returns the matching EntityPhoto.

### 7.3 — Permanent delete cleans up EntityPhotos

- [ ] Permanently delete the test product from the admin UI.
- [ ] Query: `SELECT count(*) FROM eph_entity_photos WHERE eph_entity_id = <deleted product id> AND eph_entity_type = 'product';` → 0. No orphan EntityPhoto rows.

### 7.4 — Drift fixes didn't regress other entities

- [ ] Load a public Location detail page (e.g. `/location/<slug>`) that has a primary photo. View-source: `<img>` URL for the primary photo still renders (confirms `get_picture_link()` default change from `'content'` → `'original'` was a no-op at runtime).
- [ ] Same for a MailingList detail page (`/list/<slug>`).
- [ ] Load a Page with a primary photo (`/page/<slug>`). `og:image` in head is populated (confirms new `Page::get_primary_photo()` works alongside the existing SEO metadata pipeline from Part A).

---

## 8. Log sanity check

- [ ] After the above tests, `tail -n 200 /var/www/html/joinerytest/logs/error.log | grep -iE 'Fatal|PDO|attribution|campaign|entity_photo'` returns nothing alarming (PDO syntax errors in any of the new queries, uncaught exceptions from `SessionControl::capture_marketing_coupon()` or the entity photo AJAX handler, etc.). Known-noise lines from pre-existing code are fine.
