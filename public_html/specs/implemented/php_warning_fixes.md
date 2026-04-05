# PHP Warning, Notice & Exception Fixes

**Source:** Production log audit + `err_general_errors` DB query across all docker-prod containers on 2026-04-05: getjoinery, empoweredhealthtn, phillyzouk, joinerydemo, galactictribune, mapsofwisdom, jeremytunnell.

Security noise is excluded (path traversal bot attempts, honeypot triggers, CAPTCHA failures, spam detection working correctly).

---

## Part A: Core Platform Bugs (fix in source, redeploy to all sites)

### A1: Undefined array key "act_code" in login_logic.php ✅ Clear fix

**File:** `logic/login_logic.php:14`  
**Sites:** All  
**Frequency:** Very high — every `/login` page load

```php
// Fix: change line 14
if ($get_vars['act_code']) {
// to:
if (!empty($get_vars['act_code'])) {
```

---

### A2: Undefined variable $email in login views ✅ Clear fix

**Files:**
- `views/login.php:9` (base — galactictribune, joinerydemo, jeremytunnell, phillyzouk)
- `theme/canvas/views/login.php:15` (getjoinery, mapsofwisdom)

**Frequency:** High — every `/login` GET request

`$email` is returned in `$page_vars['email']` but not extracted before use. The tailwind theme already has the fix.

Add after `process_logic()` in both files:
```php
$email = $page_vars['email'] ?? null;
```

Also: `canvas/views/login.php` still uses the old LogicResult API (`$page_vars->redirect`, `$page_vars->data`) — update it to use `process_logic()` to match all other views.

---

### A3: Undefined variable $is_valid_page in views ✅ Clear fix

**Files confirmed:**
- `views/page.php:11`
- `theme/galactictribune/views/blog.php:12` — **20 hits**
- `theme/jeremytunnell/views/blog.php:31` — **2,119 hits** (fires on every page load on that site)

`$is_valid_page` is a global set by RouteHelper. When a view is served through a fallback path, it may be unset.

**Fix in all affected views:** change to:
```php
'is_valid_page' => $is_valid_page ?? false,
```

---

### A4: Undefined array keys in blog_logic.php ✅ Clear fix

**File:** `logic/blog_logic.php:31-32`  
**Sites:** All  
**Frequency:** High — every non-tag `/blog` load

```php
// Fix
$path = $_REQUEST['path'] ?? '';
$params = explode("/", $path);
if (!empty($params[1]) && !empty($params[2])) {
```

---

### A5: readfile() called on a directory in RouteHelper.php ✅ Clear fix

**File:** `includes/RouteHelper.php:163`  
**Sites:** getjoinery, galactictribune, mapsofwisdom  
**Error:** `readfile(): Read of 8192 bytes failed with errno=21 Is a directory`

`file_exists()` returns true for directories. Add `is_dir()` guard in `serveStaticFile()`:
```php
if (!file_exists($file_path) || is_dir($file_path)) {
    return false;
}
```

---

### A6: Array to string conversion — $\_SESSION interpolated in string ✅ Clear fix

**File:** `data/general_errors_class.php:72`  
**Sites:** All  
**Frequency:** Every exception log write

`$_SESSION` (an array) is interpolated inside a double-quoted string:
```php
// Fix: escape the dollar sign
"\r\n \r\n \$_SESSION: "
```

---

### A7: ShoppingCart.php — null billing_user + missing isset checks ✅ Clear fix

**File:** `includes/ShoppingCart.php:232, 244, 251`  
**Sites:** joinerydemo, mapsofwisdom

`$this->billing_user` is set to `NULL` by `determine_billing_user()` when `$clear_first` is true, then immediately accessed as an array on line 232.

```php
// Line 232 — guard null
if ($this->billing_user && $this->billing_user['billing_first_name'] && ...)

// Lines 244, 251 — use empty()
if (!empty($data['billing_email'])) { ...
} elseif (!empty($data['existing_billing_email'])) { ...
```

---

### A8: comments_class.php — logic bug + undefined key ✅ Clear fix

**File:** `data/comments_class.php:74, 77`  
**Sites:** galactictribune

This has both an undefined-key warning AND a logic bug — the comparison is inside `strlen()` instead of outside:
```php
// Current (WRONG — strlen receives boolean, not string)
if(strlen($data['email'] > 0)){

// Fix — correct parentheses AND add empty() check
if(!empty($data['email'])){
```
Apply same fix to line 77 for `$data['comment']`.

---

### A9: PublicPageFalcon.php — undefined "vertical_menu" key ✅ Clear fix

**File:** `includes/PublicPageFalcon.php:534, 541`  
**Sites:** joinerydemo  
**Frequency:** 36 hits/window

```php
// Fix both lines
if(!empty($options['vertical_menu'])){
```

---

### A10: get_setting() throws exception for missing settings ✅ Already fixed in source — deployment issue only

**Error:** `Exception: Setting force_https does not exist.`  
**Sites:** joinerydemo — 50,380 errors (but last seen **2026-01-28** — already resolved)  
**Also:** mapsofwisdom (`Setting alternate_homepage` — 1 error, historical)

**Root cause (confirmed):** The current `Globalvars.php` already handles missing settings gracefully — it returns `''` and logs to `error_log` rather than throwing. The exceptions were thrown by an **older deployed version** of `Globalvars.php` on those sites.

The `force_https` setting now exists in joinerydemo's database (`force_https = 0`). These errors have already stopped.

**Fix:** No source code change needed. Redeploying current `Globalvars.php` to all sites eliminates any remaining risk. This is covered by the general redeploy in D1.

---

### A11: OFFSET must not be negative / string division in Pager ✅ Clear fix

**Errors:** `SQLSTATE[2201X]: OFFSET must not be negative` (mapsofwisdom: 198, jeremytunnell: 198) and `Unsupported operand types: string / int` (jeremytunnell: 17, from Pager constructor)

**Root cause (confirmed via stack trace):** Bots send SQL injection strings as `?offset=...`. `Pager::__construct()` reads offset directly from the URL (`$url_vars['offset']`), uses it in a division (`floor($this->offset / $this->numperpage)`) without casting, and the string also reaches the DB query as an invalid offset.

**Fix — two places:**

1. **`includes/Pager.php`** — cast offset after reading from URL (covers A17 too):
```php
// After the existing is_null check (~line 122):
$this->offset = max(0, (int)$this->offset);
```

2. **`logic/blog_logic.php`** and any other logic that passes offset to a Multi query — also clamp:
```php
$page_offset = max(0, (int)LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0, 'notrequired', '', 'safemode', 'int'));
```

---

### A12: Cannot load pst_posts object with no key ✅ Clear fix

**Error:** `Exception: Cannot load a pst_posts object with no key.`  
**Sites:** mapsofwisdom (757), jeremytunnell (3)

A post is being loaded with a null/empty key. In `post_logic.php` (and any logic that loads a Post by route param), validate the key before loading:
```php
if (empty($post_id)) {
    return LogicResult::error('Post not found');
}
```

---

### A13: strtolower() receives array value from bot requests ✅ Clear fix

**File:** `includes/StaticPageCache.php:505`  
**Sites:** getjoinery, galactictribune, others  
**Error:** `strtolower(): Argument #1 ($string) must be of type string, array given`

**Root cause:** Bots send array-valued query parameters (e.g., `?vars[0]=foo&vars[1]=bar`). In `shouldCache()`, line 505 calls `strtolower($value)` where `$value` is a query param that can be an array.

```php
// Fix line 505
$value_lower = strtolower(is_array($value) ? implode(',', $value) : (string)$value);
```

---

### A14: FormWriterV2Bootstrap::hidden() does not exist ✅ Clear fix (deployment issue)

**Error:** `Call to undefined method FormWriterV2Bootstrap::hidden()`  
**Sites:** galactictribune (19,375), jeremytunnell (3,014), empoweredhealthtn (34)

**Root cause (confirmed via stack trace):** The deployed `FormWriterV2Base::antispam_question_input()` internally calls `$this->hidden()`. The current codebase already uses `$this->hiddeninput()` instead. These sites are running an older version of `FormWriterV2Base.php`.

**Fix:** Redeploy current `FormWriterV2Base.php` to galactictribune, jeremytunnell, and empoweredhealthtn. No code change needed in the source repo.

---

### A15: set_validate() callers are old unmigrated code ✅ Clear fix

**Error:** `Call to undefined method FormWriter::set_validate()` (getjoinery: 7, mapsofwisdom: 9)  
**Also:** `FormWriterV2Bootstrap::set_validate()` (mapsofwisdom: 1, galactictribune: via cart.php)

**Root cause confirmed via `specs/implemented/formwriter_v2_migration.md`:**

> *Dead Code Removal: Removed all `set_validate($rules)` calls — these were vestigial remnants of the old jQuery Validate integration. V2 derives validation from model field specifications automatically. Verification confirms zero remaining `->set_validate(` calls in active code.*

`set_validate()` was intentionally removed. The callers are old code that was never migrated to FormWriterV2. `end_form()` already outputs validation JS automatically — calling `set_validate()` separately is not needed.

**Fix:** Remove the `set_validate()` call and any associated `$validation_rules` array-building from each caller. Also remove any `$validation_rules` variables that were only passed to `set_validate()`. For each form field that needs validation, embed the rules in the field definition using the V2 options array instead (see `docs/validation.md`).

**Confirmed callers to fix:** `theme/canvas/views/cart.php:161` and any remaining callers found by searching for `->set_validate(`.

**Also:** Update the outdated examples in `docs/validation.md` that still show `set_validate()` as the current API.

---

### A16: OFFSET negative — "The variable offset is not an integer" ✅ Same fix as A11

**Error:** `Exception: The variable offset is not an integer.`  
**Sites:** galactictribune (41)

Same root cause as A11 — caught earlier by input validation rather than reaching the DB. The Pager.php cast in A11 eliminates this too.

---

### A17: Unsupported operand types: string / int ✅ Same fix as A11

**Sites:** jeremytunnell (17)

**Root cause confirmed:** Stack trace shows `blog.php → Pager->__construct()` with a SQL injection string as the offset value. This is the exact same issue as A11 — the Pager.php cast handles it.

---

### A18: Undefined constant "DESC" — ~~RESOLVED, historical only~~

**Sites:** jeremytunnell (2 errors, dated **2024-06-29 and 2024-06-30**)

Nearly 2 years old. Already fixed in a subsequent deployment. No action needed.

---

### A19: D7 — Memory exhaustion serving canvas theme archive ✅ Clear fix

**Error:** `Allowed memory size of 134217728 bytes exhausted (tried to allocate 65028096 bytes)`  
**URL:** `/utils/publish_theme?download=canvas`  
**File:** `utils/publish_theme.php`

The canvas theme archive is ~62MB. PHP's output buffering (`ob_start()` called in the bootstrap) captures the entire `readfile()` output in memory before sending, exhausting the 128MB limit.

**Fix:** Flush and disable output buffering before serving binary downloads:
```php
// Add before the readfile() call in publish_theme.php
while (ob_get_level()) {
    ob_end_clean();
}
readfile($archive_path);
```

---

## Part B: Site-Specific View Bugs

### B1: Undefined variables $post / $page in theme views ✅ Clear fix

**Files:**
- `theme/galactictribune/views/post.php:7`
- `theme/jeremytunnell/views/post.php:8` — **75 hits**
- `theme/empoweredhealth/views/post.php:9`
- `theme/empoweredhealth/views/page.php:8`

These pass `$post`/`$page` to logic functions expecting them from the router, using the old LogicResult API. Migrate to `process_logic()` and add null-safe defaults:
```php
$page_vars = process_logic(post_logic($_GET, $_POST, $post ?? null));
$page_vars = process_logic(page_logic($_GET, $_POST, $page ?? null, $params ?? []));
```

---

### B2: jeremytunnell — $menu_data out of scope (most frequent PHP warning) ✅ Clear fix

**File:** `theme/jeremytunnell/includes/PublicPage.php:332, 335`  
**Frequency:** **3,165 warnings** — fires on every page load

`$menu_data` is assigned in one method at line 68 but used in a different scope at lines 332/335. Store it as a class property:

In `public_header()`, change:
```php
$menu_data = $this->get_menu_data();
```
to:
```php
$this->menu_data = $this->get_menu_data();
```

Then change lines 332/335 to use `$this->menu_data['main_menu']`.

---

### B3: jeremytunnell — Undefined "description" key in PublicPage ✅ Clear fix

**File:** `theme/jeremytunnell/includes/PublicPage.php:75, 77`  
**Frequency:** **3,035 warnings** — every page load

```php
// Fix lines 75/77
<meta name="description" content="<?php echo $options['description'] ?? ''; ?>">
```

---

### B4: jeremytunnell — $new_comment undefined in post view ✅ Clear fix

**File:** `theme/jeremytunnell/views/post.php:87`  
**Frequency:** 221 warnings

After extracting `$page_vars`, add:
```php
$new_comment = $page_vars['new_comment'] ?? null;
```

---

### B5: events_logic.php — Undefined array keys "searchterm" and "u" ✅ Clear fix

**File:** `logic/events_logic.php:15-16`  
**Sites:** phillyzouk, others using this logic

```php
$searchterm = $get_vars['searchterm'] ?? '';
$user_id = $get_vars['u'] ?? null;
```

---

### B6: phillyzouk event.php — Undefined array key "location_picture" ✅ Clear fix

**File:** `views/event.php:179`

```php
<?php if (!empty($page_vars['location_picture'])): ?>
```

---

### B7: list_logic.php — messages/member_of_list not always set ✅ Clear fix

**File:** `logic/list_logic.php` / `views/list.php:7-8`  
**Sites:** phillyzouk

`$page_vars['messages']` only set during POST; `$page_vars['member_of_list']` only set when logged in.

In `list_logic.php`, initialize both before any conditionals:
```php
$page_vars['messages'] = [];      // before the POST block
$page_vars['member_of_list'] = false;   // always set, then override if logged in
if ($session->get_user_id()) {
    $page_vars['member_of_list'] = $mailing_list->is_user_in_list($session->get_user_id());
}
```

---

### B8: canvas views/cart.php — $currency_code undefined ✅ Clear fix

**File:** `theme/canvas/views/cart.php:16`  
**Sites:** mapsofwisdom

```php
$currency_code = $page_vars['currency_code'] ?? '';
```

---

### B9: views/password-reset-1.php — Undefined "message" key ✅ Clear fix

**File:** `views/password-reset-1.php:22`  
**Sites:** empoweredhealthtn

```php
<?php if (!empty($page_vars['message'])): ?>
```

---

## Part C: Deployment / Configuration Issues (fix per-site, no code change)

### C1: joinerydemo — Setting "force_https" missing — ~~RESOLVED, historical only~~

**Last seen:** 2026-01-28. Setting `force_https = 0` now exists in joinerydemo's database. No action needed.

---

### C2: joinerydemo — Theme "sassa" not found — ~~RESOLVED, historical only~~

**Last seen:** 2026-01-29. The `theme_template` setting in joinerydemo is now `falcon`. The 'sassa' errors stopped after the setting was corrected. No action needed.

---

### C3: galactictribune — jQuery 3.4.1 missing (2,559 errors) ✅ Clear fix

`assets/js/jquery-3.4.1.min.js` is referenced in the galactictribune theme but not present. Either add the file to the theme's assets or update the template to reference a version that exists.

---

### C4: galactictribune — includes/PublicPage.php and points_class.php missing ✅ Clear fix (redeploy)

**Errors:** `File not found: includes/PublicPage.php` (27), `Failed opening .../data/points_class.php` (15)

Files expected by galactictribune's deployed code are missing. Do a full redeploy to galactictribune.

---

### C5: galactictribune/jeremytunnell — Composer autoload.php not found ✅ Clear fix

**Error:** `Composer autoload.php not found at: /home/user1/vendor/autoload.php`  
**Sites:** galactictribune (66), jeremytunnell (8)

The `composerAutoLoad` setting points to `/home/user1/vendor/` but Composer dependencies aren't installed at that path on those containers. Run `composer install` in `/home/user1/` on each affected container, or update the setting to the correct path.

---

### C6: jeremytunnell — ValidationException class file missing (242 errors) ✅ Clear fix (redeploy)

**Error:** `Failed opening required '.../includes/Exceptions/ValidationException.php'`

The `includes/Exceptions/` directory is missing from jeremytunnell's deployment. Redeploy to include it.

---

### C7: jeremytunnell — evt_recurrence_type column missing (5 errors) ✅ Clear fix

**Error:** `SQLSTATE[42703]: Undefined column: evt_recurrence_type does not exist`

The events table on jeremytunnell's database predates the recurring events feature. Run `update_database` on that container to sync the schema.

---

### C8: joinerydemo/mapsofwisdom — mig_migrations table doesn't exist ✅ Clear fix

The migrations infrastructure hasn't been initialized on these containers. Run initial migration setup (create the `mig_migrations` table) on each.

---

## Part D: Real Errors Requiring Investigation

### D1: PublicPage::get_logo() — method not found (689 total exceptions) ✅ Clear fix (redeploy)

**Sites:** empoweredhealthtn (193), phillyzouk (249), galactictribune (78), jeremytunnell (169)

`get_logo()` **exists** in the current codebase (`PublicPage.php:15`, `PublicPageBase.php:707`). These sites are running an older deployed version where the method hadn't been added yet.

**Fix:** Redeploy current code to all four sites.

---

### D2: vse_visitor_events — varchar truncation (120 total DB errors) ✅ Clear fix

**Error:** `Database INSERT failed on table 'vse_visitor_events' - SQLSTATE[22001]: String data, right truncated`  
**Sites:** empoweredhealthtn (82), phillyzouk (38)

All `varchar(255)` columns in `vse_visitor_events` (`vse_page`, `vse_referrer`, `vse_source`, etc.). Modern URLs with long UTM parameters frequently exceed 255 chars.

**Fix:** Change the affected columns to `varchar(1024)` or `text` in `VisitorEvent`'s `$field_specifications`, then redeploy (the schema update will apply automatically).

---

### D3: phillyzouk — "non-existent" passed as a date to recurring event query ✅ Clear fix

**Error:** `SQLSTATE[22007]: Invalid datetime format: invalid input syntax for type date: "non-existent"`  
**URL:** `/event/brazilian-zouk-wednesdays/non-existent-544661700`

The URL includes `non-existent` as the date segment for a recurring event instance, which flows directly into `_get_materialized_instance_for_date()` and is inserted into a DB date column without validation.

**Fix:** In `event_logic.php` (or in `_get_materialized_instance_for_date()` in `events_class.php`), validate the date string before using it:
```php
if (!strtotime($instance_date)) {
    return LogicResult::error('Invalid event date', 404);
}
```

---

### D5: Cannot access private property Globalvars::$settings ✅ Clear fix (redeploy)

**Error:** `Exception: Cannot access private property Globalvars::$settings`  
**Site:** getjoinery (4 errors)  
**URL:** `/utils/clone_export?action=database`

The stack trace points to `clone_export.php:111` calling `handle_database_export()`. In the current code, `handle_database_export()` uses `$settings->get_setting('dbname')` etc. correctly. The error suggests an older deployed version of `clone_export.php` that accesses `$settings->settings` directly (the private property).

**Fix:** Redeploy current `clone_export.php` to getjoinery.

---

### D6: SQL syntax error in MultiPage sitemap query ✅ Clear fix (redeploy)

**Error:** `SQLSTATE[42601]: Syntax error near "LENGTH" ... SELECT * FROM pag_`  
**Site:** joinerydemo (3 errors)  
**URL:** `/sitemap`

The `MultiPage::getMultiResults()` in joinerydemo's deployed `pages_class.php` generates a query with a `LENGTH` function that has a syntax error. The current codebase does not have this query. 

**Fix:** Redeploy current `pages_class.php` to joinerydemo.

---

## Summary: All Items Resolved

All issues have a clear fix. No outstanding decisions required.

---

## Implementation Priority

| Priority | Item | Impact |
|----------|------|--------|
| 1 | A14 + D1 (redeploy to galactictribune, jeremytunnell, empoweredhealthtn, phillyzouk) | Fixes 23,000+ FormWriter errors + 689 get_logo errors at once |
| 2 | B2 + B3 (jeremytunnell $menu_data + description) | 6,200+ warnings/day — fires every page load |
| 3 | A1–A9, A11, A13, A15, A19 (core code fixes) | High frequency, small targeted fixes |
| 4 | B1, B4–B9 (site-specific view fixes) | Per-view fixes |
| 5 | A19 (publish_theme memory) | Prevents theme downloads from working |
| 6 | D2 (vse_visitor_events truncation) | Silent data loss on visitor tracking |
| 7 | D3 (recurring event "non-existent" date) | DB errors on phillyzouk |
| 8 | C3–C8 (per-site config/deployment) | Per-site fixes |
| 9 | D5, D6 (redeploy specific files) | Targeted redeploy fixes |
| — | A10, A18, C1, C2 | Already resolved — historical errors only |
