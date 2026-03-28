# Codebase Cleanup: Immediate Fixes

## Overview

These are straightforward fixes where the problem and solution are clear. Each can be implemented without further discussion or investigation. They are grouped by type, not priority — work through them in whatever order makes sense.

---

## Warning Fixes (High-Frequency Log Noise)

### Fix `$tz` Undefined in `views/event.php`

**Problem:** `$tz` is defined on line 73 inside `if ($is_virtual_event)` but referenced on lines 256 and 278 for all events. Non-virtual events with sessions trigger ~1,754 warnings.

**Fix:** Move the `$tz` assignment to before the conditional block so it is always defined:
```php
$tz = $evt->get('evt_timezone') ?: 'America/New_York';
```

**Files to modify:**
- `views/event.php`

---

### Fix Currency Symbol Case Mismatch

**Problem:** `Product::$currency_symbols` has lowercase keys (`'usd'`, `'eur'`) but the `site_currency` setting stores `"USD"` (uppercase). Every product page triggers an undefined array key warning (~271 occurrences across `products_class.php`, `product_logic.php`, `products_logic.php`, `pricing_logic.php`).

**Fix:** Use `strtolower()` when looking up the currency symbol:
```php
$symbol = self::$currency_symbols[strtolower($settings->get_setting('site_currency'))];
```

**Files to modify:**
- `data/products_class.php` (lines 205, 626)
- `logic/product_logic.php` (line 67)
- `logic/products_logic.php` (line 93)
- `logic/pricing_logic.php` (line 73)

---

### Fix `PublicPageBase.php` Missing `isset()` Guards

**Problem:** Line 653 accesses `$options['is_valid_page']` and line 655 accesses `$_SESSION['permission']` without checking existence. Fires ~512 times (every anonymous page view).

**Fix:** Add null coalescing operators:
```php
// Line 653
($options['is_valid_page'] ?? false)

// Line 655
if(!($_SESSION['permission'] ?? 0) || ($_SESSION['permission'] ?? 0) == 0)
```

**Files to modify:**
- `includes/PublicPageBase.php`

---

### Fix Undefined `$limit` in `events_class.php`

**Problem:** `get_next_session()` uses `$limit` on line 249 but never defines it (~71 occurrences).

**Fix:** The method retrieves the next single session, so `$limit` should be `1`. Define it before use.

**Files to modify:**
- `data/events_class.php`

---

### Fix Missing `isset()` Guards in Logic Files and Views

**Problem:** Multiple files access array keys without existence checks, causing warnings on every page load:
- `product_logic.php:42` — `$get_vars['product_version_id']` (~80 occurrences)
- `products_logic.php:25-61` — `numperpage`, `sort`, `sdirection`, `searchterm`, `subscriptions` (~55 occurrences)
- `Pager.php:42` — `$url_pieces['query']` (~13 occurrences)
- `events.php:41,60` — `$page_vars['type']` (~30 occurrences)
- `event_waiting_list.php:28` — `$page_vars['display_message']` (~10 occurrences)

**Fix:** Add `isset()`, `!empty()`, or `??` guards on each access.

**Files to modify:**
- `logic/product_logic.php`
- `logic/products_logic.php`
- `includes/Pager.php`
- `views/events.php`
- `views/event_waiting_list.php`

---

### Fix `$product_id` Not in View Scope

**Problem:** `views/product.php` line 93 uses `$product_id` which is set in `product_logic.php` but not extracted into view scope (~84 occurrences).

**Fix:** Either pass `$product_id` through `$page_vars` in the logic file, or use `$page_vars['product']->key` in the view.

**Files to modify:**
- `logic/product_logic.php` or `views/product.php`

---

## Production Bug Fixes (Docker-Prod)

### Widen `vse_source` Column on `vse_visitor_events`

**Problem:** The `vse_source` field is `varchar(20)` but receives JSON strings like `{"a":1,"m":1,"v":"1"}` that exceed 20 characters. This breaks cookie consent recording on empoweredhealthtn (69 errors) and phillyzouk (67 errors). The error originates in `ConsentHelper.php` line 396.

**Fix:** Update the `$field_specifications` for `vse_source` in the visitor events data class to use a wider column (e.g., `varchar(100)` or `text`). The `update_database` system will apply the schema change automatically.

**Files to modify:**
- The data class file for `vse_visitor_events` (find via grep for `vse_visitor_events`)

**Verification:** Run `update_database` on affected sites. Confirm consent saves without error.

---

### Fix phillyzouk Invalid Datetime from Bot URLs

**Problem:** Bots hit event URLs with garbage date values like `/event/event-name/non-existent-2136082052`. The `_get_materialized_instance_for_date()` method in `events_class.php` line 1128 passes this unsanitized input directly to PostgreSQL as a date parameter, causing SQL errors (100 occurrences).

**Fix:** Add input validation in `_get_materialized_instance_for_date()` to verify the date string is a valid date format before passing it to the database query. Return null or a 404 for invalid dates.

**Files to modify:**
- `data/events_class.php` — `_get_materialized_instance_for_date()` method

---

### Run `update_database` on phillyzouk

**Problem:** Code references `evt_recurrence_type` column which does not exist in the phillyzouk database. The column is defined in the data class but hasn't been created yet.

**Fix:** Run `update_database` from the admin utilities page on phillyzouk. No code change needed.

---

## Security Fixes

### Convert SQL Concatenation to Prepared Statements

**Problem:** Several files concatenate variables directly into SQL strings instead of using prepared statements.

**Locations and fixes:**

| File | Lines | Variables at Risk |
|------|-------|-------------------|
| `utils/upload_csv_step2.php` | 175, 178 | `$promoid`, `$emailid` from `$_GET` |
| `data/surveys_class.php` | 46, 58 | `$this->key` |
| `adm/logic/admin_user_logic.php` | 166, 177 | `$user->key` |
| `adm/logic/admin_yearly_report_donations_logic.php` | 45 | `$user->usr_user_id`, dates |
| `data/address_class.php` | 807-823 | Address filter values |

**Fix:** Convert each to use PDO prepared statements with parameter binding. Example:
```php
// Before (VULNERABLE)
$sql = "SELECT * FROM log_logins WHERE log_usr_user_id=" . $user->key;

// After (SAFE)
$sql = "SELECT * FROM log_logins WHERE log_usr_user_id = ?";
$q = $dblink->prepare($sql);
$q->execute([$user->key]);
```

Also remove the `addslashes()` usage in `upload_csv_step2.php` line 148 and replace with proper prepared statements.

---

## File Cleanup

### Delete All `.bak` Files

**Problem:** 70+ `.bak` backup files are scattered across the codebase. These are superseded by current versions and tracked in git history.

**Files to delete (complete list):**

**Root/Core:**
- `serve.php.bak`

**Admin:**
- `adm/admin_component_edit.php.bak`
- `adm/admin_event_edit.php.bak`
- `adm/admin_event.php.bak`
- `adm/admin_events.php.bak`
- `adm/admin_static_cache_bak.php` (note: `.php` extension — this is routable!)
- `adm/logic/admin_event_edit_logic.php.bak`
- `adm/logic/admin_event_logic.php.bak`
- `adm/logic/admin_events_logic.php.bak`

**AJAX:**
- `ajax/entity_photos_ajax.php.bak`

**Data:**
- `data/components_class.php.bak`
- `data/entity_photos_class.php.bak`
- `data/events_class.php.bak`
- `data/files_class.php.bak`
- `data/locations_class.php.bak`
- `data/mailing_lists_class.php.bak`
- `data/page_contents_class.php.bak`
- `data/users_class.php.bak`

**Includes:**
- `includes/ComponentRenderer.php.bak`
- `includes/DatabaseUpdater.php.bak`
- `includes/PhotoHelper.php.bak`
- `includes/ThemeManager.php.bak`

**Logic:**
- `logic/event_logic.php.bak`
- `logic/events_logic.php.bak`

**Migrations:**
- `migrations/migrations.php.bak`

**Utils:**
- `utils/component_preview.php.bak`

**Views and Components:**
- `views/components/cta_banner.json.bak`
- `views/components/custom_html.json.bak`
- `views/components/custom_html.php.bak`
- `views/components/feature_grid.json.bak`
- `views/components/hero_static.json.bak`
- `views/components/page_title.json.bak`
- `views/event.php.bak`
- `views/events.php.bak`
- `views/profile/account_edit.php.bak`

**Theme files:**
- All `theme/empoweredhealth/views/components/*.json.bak` files (5 files)
- All `theme/linka-reference/views/components/*.json.bak` files (9 files)
- `theme/phillyzouk/logic/index_logic.php.bak`
- `theme/phillyzouk/views/index.php.bak`

All recoverable from git history.

---

### Delete Orphaned Test File

**File to delete:**
- `test_dropdown_format.php` (in public_html root) — orphaned FormWriter test script, not routed, not referenced

---

### Remove Large Commented-Out Code Blocks

**Files to clean:**
- `adm/admin_event.php` (lines 646-723) — ~77 lines of commented-out HTML/table code for contact emails display
- `adm/admin_stripe_orders.php` (lines 60-76) — ~17 lines

Only remove clearly dead commented-out code blocks. Do not remove documentation comments.

---

### Fix `.php` Extension in URLs

**Problem:** `tests/email/index.php` has links using `.php` extensions which break with the routing system.

**Lines to fix:**
- Line 167: `href="/admin/admin_settings.php#email-settings"` → `href="/admin/admin_settings#email-settings"`
- Line 170: `href="/admin/admin_debug_email_logs.php"` → `href="/admin/admin_debug_email_logs"`
- Line 182: `href="/utils/email_setup_check.php"` → `href="/utils/email_setup_check"`
- Lines 173, 185: Leave as-is (direct file access in tests directory)

**Files to modify:**
- `tests/email/index.php`

---

### Fix Switch Case Syntax

**Problem:** `data/admin_analytics_activitybydate_data.php` uses semicolons instead of colons in switch case statements (lines 14, 17, 20, 23). PHP tolerates this but it is syntactically incorrect.

**Fix:** Change `case 1;` to `case 1:` etc.

**Files to modify:**
- `data/admin_analytics_activitybydate_data.php`

---

## Implementation Notes

- All PHP files modified must pass `php -l` syntax validation and `validate_php_file.php` method validation
- Version numbers in modified files should be incremented where applicable
- Production fixes (vse_source, phillyzouk items) need deployment to docker-prod after verification on joinerytest
