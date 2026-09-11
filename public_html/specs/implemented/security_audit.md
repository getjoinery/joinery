# Security Audit Report

**Date:** 2026-04-04
**Scope:** Full application — authentication, authorization, input handling, file uploads, payments, API, configuration, cryptography
**Platform:** PHP 8.x / PostgreSQL / Apache

---

## Executive Summary

The platform has a solid security foundation: CSRF tokens use `random_bytes()` with `hash_equals()`, login rate limiting is in place, all three webhook providers (Stripe, Mailgun, PayPal) verify signatures, file uploads use whitelist-based extension validation, and payment amounts are always server-authoritative. However, the audit identified **9 critical**, **8 high**, **14 medium**, and **4 low** severity findings that should be addressed.

The most urgent issues are: weak cryptographic token generation for password resets and activation codes, missing output escaping (XSS) across admin pages, SQL injection vectors in `SystemBase` and analytics, missing permission checks on admin analytics endpoints, and no session regeneration on login.

---

## Methodology

Audited areas:
1. Authentication & session management (SessionControl, login/register/password-reset logic, cookies)
2. SQL injection (SystemBase, SystemMultiBase, admin pages, AJAX, API, analytics)
3. Cross-site scripting (views, admin pages, FormWriter, JavaScript)
4. Access control & authorization (route permissions, admin pages, IDOR, object-level auth)
5. File upload security (UploadHandler, upload endpoints, storage)
6. Payment processing (Stripe, PayPal — checkout, webhooks, price integrity)
7. API & AJAX endpoint security (authentication, rate limiting, CSRF, data scoping)
8. Cryptographic practices (token generation, password hashing, random number generation)
9. Configuration & infrastructure (credentials, permissions, headers, debug settings)

---

## Critical Findings

### C1. ~~Weak Cryptographic Token Generation (Password Resets, Activation Codes)~~ **[FIXED]**

**Files:**
- `includes/LibraryFunctions.php:495` — `str_rand()` uses `md5(uniqid('', TRUE))`
- `includes/LibraryFunctions.php:499` — `random_string()` uses `mt_rand()`
- `includes/Activation.php:113` — calls `str_rand()` for activation/reset codes

**Issue:** `uniqid()` is time-based and predictable. `mt_rand()` is not cryptographically secure. Password reset tokens generated this way can be brute-forced or predicted.

**Impact:** Account takeover via predicted password reset tokens.

**Fix:**
```php
// In LibraryFunctions.php — replace str_rand():
static function str_rand($length = 32) {
    return substr(bin2hex(random_bytes(ceil($length / 2))), 0, $length);
}

// Replace random_string():
static function random_string($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $string = '';
    for ($i = 0; $i < $length; $i++) {
        $string .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $string;
}
```

---

### C2. XSS — Unescaped Output Across Admin Pages

**Files (non-exhaustive list):**
- `adm/admin_page_content.php:68` — `echo $page_content->get('pac_body');`
- `adm/admin_comment.php:85-97` — comment body, author name, post title
- `adm/admin_question_edit.php:182` — question option labels/values
- `adm/admin_users_undelete.php:74` — `display_name()`
- `adm/admin_contact_type.php:22,28,40,50,56` — contact type fields
- `adm/admin_api_key.php:78,84,93` — API key name, public key, owner name
- `adm/admin_order_refund.php:108,110,113` — product name, amounts
- `adm/admin_coupon_code.php` — coupon fields
- `adm/admin_conversation.php:80,85,89,154` — conversation data

**Pattern:** Any `echo $obj->get('field')` or `echo $obj->display_name()` without `htmlspecialchars()`.

**Impact:** Stored XSS. If an attacker can set their display name or create a comment with a script tag, it executes in admin sessions (permission 5-10), potentially allowing full site compromise.

**Fix:** Wrap all user-controlled output:
```php
echo htmlspecialchars($obj->get('field'), ENT_QUOTES, 'UTF-8');
```

Note: Fields intentionally containing HTML (like `pac_body` for page content) should be sanitized with a library like HTMLPurifier rather than escaped.

---

### C3. SQL Injection in SystemBase — check_for_duplicate()

**File:** `includes/SystemBase.php:339,354,362,377,466`

**Issue:** String concatenation in WHERE clauses:
```php
// Line 339
$whereclauses[] = $field . '=\''.$obj_to_check->get($field). '\' ';

// Line 466
$sql .= ' AND '.static::$pkey_column.' != '.$this->key;
```

**Impact:** If any user-controlled data reaches `check_for_duplicate()` via model fields, SQL injection is possible.

**Fix:** Convert to parameterized queries:
```php
$whereclauses[] = $field . ' = ?';
$params[] = $obj_to_check->get($field);
// ...
$q = $dblink->prepare($sql);
$q->execute($params);
```

---

### C4. SQL Injection in Analytics Funnels

**File:** `adm/logic/admin_analytics_funnels_logic.php:52,64,78,92,106`

**Issue:** Page name variables interpolated directly into SQL:
```php
WHERE vse_page = '$page_1'
```

**Impact:** If analytics page names come from user input or can be manipulated, SQL injection is possible.

**Fix:** Use parameterized queries with `:page_1` bound parameter.

---

### C5. ~~Missing Permission Checks on Admin Analytics Pages~~ **[FIXED]**

**Files:**
- `adm/logic/admin_analytics_funnels_logic.php`
- `adm/logic/admin_analytics_stats_logic.php`
- `adm/logic/admin_analytics_users_logic.php`

**Issue:** These admin logic files do not call `$session->check_permission()`. If the routing system does not enforce admin-level permissions on `/admin/*` routes (and it does not — see H3), these pages may be accessible without authentication.

**Impact:** Unauthenticated access to analytics data.

**Fix:** Add `$session->check_permission(5);` (or higher) at the start of each function.

---

### C6. ~~No Session Regeneration on Login~~ **[FIXED]**

**File:** `includes/SessionControl.php` — `store_session_variables()` method

**Issue:** After successful authentication, the session ID is not regenerated. The same session ID that existed before login continues to be used.

**Impact:** Session fixation — an attacker who can set a victim's session ID (via URL, cookie injection, or subdomain control) retains access after the victim logs in.

**Fix:** Add `session_regenerate_id(true);` at the start of `store_session_variables()`.

---

### C7. ~~SQL Injection in Dead Code (Activation.php)~~ **[FIXED]**

**File:** `includes/Activation.php:115-116`

```php
$sql = "SELECT COUNT(1) as count FROM act_activation_codes WHERE act_code = '$act_code'";
```

**Issue:** Direct string interpolation. Although this appears to be dead code (a proper parameterized query follows below it), it should be removed.

**Fix:** Delete the dead code block.

---

### C8. Hardcoded Database Credentials

**File:** `/var/www/html/joinerytest/config/Globalvars_site.php:28-34`

**Issue:** Database username, password, and names are hardcoded in plaintext. File permissions are 755 (world-readable).

**Impact:** Any file read vulnerability or server compromise exposes database credentials.

**Fix:**
1. Move to environment variables: `$this->settings['dbpassword'] = getenv('DB_PASSWORD');`
2. `chmod 640 /var/www/html/joinerytest/config/Globalvars_site.php`
3. `chown root:www-data /var/www/html/joinerytest/config/Globalvars_site.php`

---

### C9. ~~No PHP Execution Prevention in Uploads Directory~~ **[FIXED]**

**Location:** `/var/www/html/joinerytest/uploads/`

**Issue:** No `.htaccess` file to disable PHP execution. While the upload handler validates extensions (whitelist), a bypass could allow execution of uploaded PHP.

**Fix:** Create `/var/www/html/joinerytest/uploads/.htaccess`:
```apache
<FilesMatch "\.php$">
    Deny from all
</FilesMatch>
php_flag engine off
```

---

## High Severity Findings

### H1. ~~Weak Password Minimum — 5 Characters~~ **[FIXED]**

**File:** `data/users_class.php:567`

**Issue:** `strlen($password) < 5` — no complexity requirements.

**Fix:** Increase minimum to 12. Consider requiring mixed character classes.

---

### H2. Remember-Me Cookie Uses Custom Encoding

**File:** `includes/SessionControl.php:179-191`

**Issue:** Cookie contains encoded (not encrypted) user ID, expiration, and an SHA1 HMAC with a hardcoded secret. IP binding is implemented but disabled (commented out). Cookie lifetime is 365 days.

**Fix:**
1. Generate a random token: `bin2hex(random_bytes(32))`
2. Store the token hash in the database with expiration and user ID
3. On cookie presentation, look up the token hash and verify it hasn't expired
4. Reduce lifetime to 30 days
5. Re-enable IP binding

---

### H3. ~~No Route-Level Permission Enforcement for /admin/*~~ **[FIXED]**

**File:** `serve.php` — route definitions

**Issue:** The `/admin/*` route does not specify `min_permission`. Protection depends entirely on each admin page individually calling `check_permission()`. If any page forgets (see C5), it's exposed.

**Fix:** Add `min_permission` to the admin route:
```php
'/admin/*' => ['view' => 'adm/{path}', 'min_permission' => 5],
```

---

### H4. ~~No Rate Limiting on Password Reset Token Submission~~ **[FIXED]**

**File:** `logic/password_reset_2_logic.php`

**Issue:** No rate limiting when validating password reset codes. Combined with weak token generation (C1), this enables brute-force attacks.

**Fix:** Add `RequestLogger::check_rate_limit('password_reset_complete', 5, 900)`.

---

### H5. Admin Login-As: No Validation or Audit Logging

**File:** `adm/logic/admin_user_login_as_logic.php:4-19`

**Issues:**
1. No check that the target user is active/not deleted
2. No audit logging of who impersonated whom
3. No session regeneration
4. No impersonation time limit
5. No preservation of original admin ID

**Fix:**
```php
$session->check_permission(10);
$user = new User($usr_user_id, TRUE);
if (!$user->key || $user->get('usr_delete_time')) {
    return LogicResult::error('User is not available');
}
// Log impersonation
EventLog::log('admin_impersonation', $session->get_user_id(), $usr_user_id);
$_SESSION['original_admin_user_id'] = $session->get_user_id();
session_regenerate_id(true);
$_SESSION['usr_user_id'] = $usr_user_id;
$_SESSION['permission'] = $user->get('usr_permission');
```

---

### H6. Command Injection in admin_test_database.php

**File:** `adm/admin_test_database.php:169,172,176,187`

**Issue:** `exec()` calls with unsanitized database names:
```php
exec("dropdb -U {$db_user} --if-exists {$test_db} 2>&1", ...);
```

**Fix:** Use `escapeshellarg()` on all interpolated values, or use PDO's PostgreSQL functions instead of `exec()`.

---

### H7. Unauthenticated File Enumeration via validate_file_ajax.php

**File:** `ajax/validate_file_ajax.php`

**Issue:** Accepts file paths via GET and checks `file_exists()` / `is_readable()` without authentication. The `logo_link` parameter requires a leading `/` but can still probe paths like `/etc/passwd`.

**Fix:** Add authentication check, or restrict to a whitelist of expected paths.

---

### H8. Privilege Escalation Risk in Admin User Edit

**File:** `adm/logic/admin_users_edit_logic.php:61-63`

**Issue:** When editing a user, the permission change relies on `$_SESSION['permission'] == 10` but does not verify the target user ID in POST matches the expected user. A crafted form submission could target a different user.

**Fix:** Verify `edit_primary_key_value` matches the user loaded via GET parameter.

---

## Medium Severity Findings

### M1. ~~User Enumeration via Password Reset~~ **[FIXED]**

**File:** `logic/password_reset_1_logic.php:46-55`

Different messages for existing ("Email sent") vs non-existing ("Could not find that email") users.

**Fix:** Always return "If this email exists, you will receive a reset link."

---

### M2. User Enumeration via Email Check AJAX

**File:** `ajax/email_check_ajax.php`

Publicly accessible, no rate limiting, returns whether email exists.

**Fix:** Add rate limiting, or only make this endpoint available when a registration form is active in the session.

---

### M3. Session Cookie Parameters Not Explicitly Set

**File:** `includes/SessionControl.php:57`

`session_start()` is called without prior `session_set_cookie_params()`. PHP defaults may not include HttpOnly or SameSite on the session cookie itself (distinct from manual cookies which are properly configured).

**Fix:** Call `session_set_cookie_params()` before `session_start()`.

---

### M4. Session IP Change Detection Too Permissive (/16)

**File:** `includes/SessionControl.php:803-811`

Only checks first 2 octets. IPv6 is ignored entirely (returns false = no change detected).

**Fix:** Check first 3 octets (/24) for IPv4. Add IPv6 prefix comparison.

---

### M5. Email Verification Not Enforced Before Account Access

**File:** `logic/register_logic.php:110`

Accounts are created and immediately usable. The setting `activation_required_login` exists but may not be enabled.

**Fix:** Ensure `activation_required_login` is enabled, or defer account creation until verification.

---

### M6. Missing CSRF on AJAX Endpoints

**Files:** Most `ajax/*.php` files

State-changing AJAX endpoints (checkout, conversations, theme switch) do not validate CSRF tokens.

**Fix:** Include CSRF tokens in AJAX requests and validate server-side.

---

### M7. PayPal Webhook Verification Skipped When Config Missing

**File:** `ajax/paypal_subscription_webhook.php:49`

```php
if ($webhook_id && !$paypal->verify_webhook_signature($headers, $body))
```

If `$webhook_id` is empty, signature verification is skipped entirely.

**Fix:** If webhook ID is not configured, reject the request with 403.

---

### M8. ~~Security Headers Disabled~~ **[FIXED]**

**File:** `includes/PublicPageBase.php:63-68`

`Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options` are all commented out.

**Fix:** Uncomment and configure:
```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

---

### M9. Debug Settings in Production

**File:** `utils/update_database.php:80-81` — `display_errors = 1`

Also: `debug` and `show_errors` settings may be enabled.

**Fix:** Ensure all production environments have `display_errors = Off` in php.ini. Gate debug output behind settings checks.

---

### M10. Config File Permissions Too Permissive

**File:** `/var/www/html/joinerytest/config/Globalvars_site.php` — 755

**Fix:** `chmod 640`, owned by `root:www-data`.

---

### M11. Uploads Directory World-Writable

**Location:** `/var/www/html/joinerytest/uploads/` — 777

**Fix:** `chmod 755` with `www-data:www-data` ownership.

---

### M12. Diagnostics Endpoint with Hardcoded Password

**File:** `utils/diagnostics.php:10`

```php
if($_REQUEST['password'] != 'setupinfo')
```

**Fix:** Remove the endpoint, or gate it behind `check_permission(10)`.

---

### M13. No Explicit Session Timeout

**File:** `includes/SessionControl.php`

No idle or absolute session timeout is configured. PHP defaults apply.

**Fix:** Implement idle timeout (30 min) and absolute timeout (8 hours) in SessionControl.

---

### M14. Test Routes in Production Routing

**File:** `serve.php:133`

```php
'/tests/*' => ['view' => 'tests/{path}', 'min_permission' => 10],
```

Protected by permission 10, but test infrastructure should not be routable in production.

**Fix:** Remove or gate behind a `debug` setting check.

---

## Low Severity Findings

### L1. bcrypt Instead of Argon2id

**File:** `data/users_class.php:575` — `PASSWORD_BCRYPT`

Acceptable but Argon2id is superior for GPU resistance. Migrate when convenient.

---

### L2. Legacy PasswordHash Fallback

**File:** `data/users_class.php:578-590`

Falls back to phpass PasswordHash library for legacy hashes. These should be re-hashed on next login.

---

### L3. Debug SQL Output When Debug Enabled

**File:** `includes/SystemBase.php:885,895,906,934,948`

`echo "DELETE FROM ..."` when `$debug=true`. Should use error_log instead.

---

### L4. API Default Rate Limit Permissive

**File:** `api/apiv1.php:121-132` — 1000 requests/hour default.

Consider reducing or adding per-endpoint limits.

---

## Positive Findings (Strengths)

These areas are well-implemented and should be maintained:

| Area | Detail |
|------|--------|
| **CSRF tokens** | `random_bytes(32)`, per-form, expiring, `hash_equals()` comparison, single-use |
| **Login rate limiting** | 10 attempts / 15 min per IP via `RequestLogger::check_rate_limit()` |
| **Generic login errors** | "Your username or password was incorrect" — no user enumeration |
| **Stripe webhooks** | Signature verification via `Stripe\Webhook::constructEvent()`, idempotency via `WebhookLog::isDuplicate()` |
| **Mailgun webhooks** | HMAC-SHA256 with `hash_equals()` |
| **PayPal webhooks** | Server-to-server verification via `/v1/notifications/verify-webhook-signature` (when configured) |
| **Cookie security** | Secure, HttpOnly, SameSite=Lax on manual cookies |
| **File upload validation** | Whitelist extensions, `basename()` for path traversal, dot replacement for double extensions |
| **Server-side pricing** | Cart prices fetched from database at checkout; not from client input |
| **Stripe key validation** | Prefix checks (`sk_`/`pk_`), swapped key detection, partial key logging |
| **API security** | HTTPS enforcement, security headers, CORS, rate limiting, IP restrictions, key expiration |
| **Password hashing** | bcrypt via `password_hash()` with `password_verify()` (constant-time) |
| **Clone export** | HTTPS + bearer token + timing-safe comparison + rate limiting + IP logging |

---

## Remediation Roadmap

### Phase 1 — Immediate (This Week) -- **ALL DONE**

| # | Finding | Effort | Status |
|---|---------|--------|--------|
| C1 | Replace `str_rand()` / `random_string()` with `random_bytes()` | Small | **FIXED** |
| C6 | Add `session_regenerate_id(true)` on login | Small | **FIXED** |
| C7 | Delete dead SQL injection code in Activation.php | Trivial | **FIXED** |
| C9 | Add `.htaccess` to uploads directory | Trivial | **FIXED** |
| H1 | Increase password minimum to 12 | Small | **FIXED** |
| H3 | Add `min_permission` to `/admin/*` route | Small | **FIXED** |
| C5 | Add `check_permission()` to analytics logic files | Small | **FIXED** |
| H4 | Add rate limiting to password reset step 2 | Small | **FIXED** |
| M1 | Fix user enumeration in password reset | Small | **FIXED** |
| M8 | Enable security headers (HSTS, X-Frame-Options, etc.) | Small | **FIXED** |

### Phase 2 — Short-Term (This Month)

| # | Finding | Effort | Risk Reduced |
|---|---------|--------|-------------|
| C2 | Audit and fix XSS across admin pages | Medium | Stored XSS / admin session compromise |
| C3 | Parameterize `check_for_duplicate()` in SystemBase | Medium | SQL injection |
| C4 | Parameterize analytics queries | Small | SQL injection |
| H2 | Replace remember-me with DB-backed random tokens | Medium | Session hijacking |
| H5 | Fix login-as: validation, audit logging, session regen | Medium | Impersonation abuse |
| H6 | Use `escapeshellarg()` in test database exec calls | Small | Command injection |
| H7 | Add auth to validate_file_ajax.php | Small | Information disclosure |
| M1-M2 | Fix user enumeration in password reset + email check | Small | User enumeration |
| M8 | Enable security headers | Small | Clickjacking, MIME sniffing |

### Phase 3 — Medium-Term (Next Quarter)

| # | Finding | Effort | Risk Reduced |
|---|---------|--------|-------------|
| C8 | Move credentials to environment variables | Medium | Credential exposure |
| M3 | Set session cookie parameters explicitly | Small | Session cookie security |
| M5 | Enforce email verification before account access | Medium | Spam accounts |
| M6 | Add CSRF to AJAX endpoints | Medium | CSRF on state changes |
| M7 | Make PayPal webhook verification mandatory | Small | Fake webhook processing |
| M9-M12 | Fix debug settings, permissions, diagnostics | Small | Information disclosure |
| M13 | Implement session timeouts | Medium | Stale session abuse |
| H8 | Add target user verification in admin user edit | Small | Privilege escalation |

### Phase 4 — Long-Term

| # | Finding | Effort |
|---|---------|--------|
| L1 | Migrate to Argon2id | Small (add password_needs_rehash check) |
| L2 | Re-hash legacy passwords on login | Small |
| M14 | Remove test routes from production | Small |
| — | Implement Content-Security-Policy | Medium (requires JS/CSS audit) |
| — | Add automated security scanning (SAST) | Medium |

---

## Appendix: Files Referenced

### Critical files requiring changes:
- `includes/LibraryFunctions.php` — token generation (C1)
- `includes/SystemBase.php` — SQL injection in check_for_duplicate (C3)
- `includes/SessionControl.php` — session regen, remember-me, timeouts (C6, H2, M3, M13)
- `includes/Activation.php` — dead code SQL injection (C7)
- `includes/PublicPageBase.php` — security headers (M8)
- `adm/logic/admin_analytics_*_logic.php` — missing permission checks (C5)
- `adm/logic/admin_user_login_as_logic.php` — audit logging, validation (H5)
- `adm/admin_test_database.php` — command injection (H6)
- `ajax/validate_file_ajax.php` — auth required (H7)
- `logic/password_reset_1_logic.php` — user enumeration (M1)
- `logic/password_reset_2_logic.php` — rate limiting (H4)
- `data/users_class.php` — password minimum (H1)
- `serve.php` — admin route protection (H3)

### Admin pages requiring XSS fixes (C2):
- `adm/admin_page_content.php`
- `adm/admin_comment.php`
- `adm/admin_question_edit.php`
- `adm/admin_users_undelete.php`
- `adm/admin_contact_type.php`
- `adm/admin_api_key.php`
- `adm/admin_order_refund.php`
- `adm/admin_coupon_code.php`
- `adm/admin_conversation.php`
- (Additional admin pages should be audited — this list is not exhaustive)
