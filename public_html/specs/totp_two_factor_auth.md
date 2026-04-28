# TOTP Two-Factor Authentication Spec

## Overview

Add optional TOTP-based two-factor authentication (2FA) to the Joinery platform. Users can enable 2FA via their profile settings using any standard authenticator app (Google Authenticator, Authy, 1Password, etc.). Admins can optionally require 2FA for admin-level accounts and can reset a user's 2FA if they lose access to their device.

## Current State

### Authentication flow (login_logic.php)
1. Rate limiting check (10 attempts / 15 min)
2. Email + password validation
3. IP restriction check (`usr_allowed_ips`)
4. Email activation check (if `activation_required_login` is enabled)
5. `store_session_variables()` — user is now logged in
6. Remember Me cookie (optional)
7. Redirect to return URL or `/profile`

### Relevant existing infrastructure
- **Rate limiting**: `RequestLogger::check_rate_limit()` — reusable for TOTP attempts
- **Session management**: `SessionControl::store_session_variables($user)` creates the logged-in session
- **Remember Me cookies**: `SessionControl::save_user_to_cookie()` / `get_user_from_cookie()` — currently auto-logs in without any 2FA check
- **IP restrictions**: Per-user JSONB field with CIDR/wildcard support
- **Profile tab menu**: Shared across account_edit, password_edit, address_edit, phone_numbers_edit, contact_preferences — each logic file defines the same array
- **Admin user edit**: `adm/admin_users_edit.php` — manages per-user security settings (IP restrictions, force password change, disable password recovery)
- **AJAX login**: Login supports both form POST and XMLHttpRequest with different error handling paths

### No existing 2FA code
No TOTP, 2FA, or authenticator-related code exists in the codebase.

## Implementation Plan

### Phase 1: Dependencies and Data Model

**Composer dependency:**
```
spomky-labs/otphp ^11.0    — TOTP/HOTP generation and validation
chillerlan/php-qrcode ^5.0 — QR code rendering (no external service needed)
```

`spomky-labs/otphp` is the most widely used PHP TOTP library. It generates secrets, validates codes, and produces `otpauth://` URIs. `chillerlan/php-qrcode` renders QR codes as SVG/PNG locally (no data sent to external services).

**New fields on User model (`data/users_class.php`):**
```php
'usr_totp_secret'         => array('type'=>'varchar(255)'),  // Base32 encoded secret (stored plaintext — see note below)
'usr_totp_backup_codes'   => array('type'=>'jsonb'),         // Array of Argon2id-hashed single-use codes
'usr_totp_enabled_time'   => array('type'=>'timestamp(6)'),  // When 2FA was enabled; NULL = not enabled (single source of truth)
'usr_totp_last_used_step' => array('type'=>'bigint'),        // Last successful TOTP time step (for replay prevention)
'usr_totp_hmac_key'       => array('type'=>'varchar(128)'),  // Per-user random key (hex) for trusted-device cookie HMAC; generated on enable_totp(), cleared on disable_totp()
```

**No separate `usr_totp_enabled` boolean.** Activation is determined by `usr_totp_enabled_time IS NOT NULL` — the same pattern used for soft delete (`usr_delete_time`). This keeps a single source of truth and avoids the boolean/timestamp drift bug class. The trusted-device cookie HMAC is keyed on `usr_totp_enabled_time`, so disable+re-enable produces a new time and invalidates outstanding cookies automatically.

**Per-user HMAC key (zero-setup).** The trusted-device cookie HMAC uses `usr_totp_hmac_key` — a 64-byte random key (hex-encoded, 128 chars) generated automatically inside `enable_totp()` via `bin2hex(random_bytes(64))`. This avoids any install-time secret management: there is no site-wide secret to provision or rotate, and a leaked single user's key only affects that user (recoverable by disable+re-enable). The user record must be loaded to validate the cookie anyway (to check 2FA still active, account not deleted, etc.), so reading the key from the same row adds no extra DB cost.

**TOTP secret stored plaintext (decision).** `usr_totp_secret` is not encrypted at rest. Encrypting would protect users in the narrow case where the DB leaks but the encryption key doesn't — but Argon2id-hashed passwords already mean an attacker with only the DB must crack a password before a plaintext TOTP secret becomes useful. The marginal benefit (helping users with weak/reused/breached passwords) doesn't justify the operational cost of key management, key-loss recovery, and backup-restore key drift.

**New settings (declarative — `public_html/settings.json`):**
| Setting | Default | Purpose |
|---------|---------|---------|
| `totp_require_admins` | `"0"` | Require 2FA for users with permission >= 5 |
| `totp_remember_device_days` | `"0"` | Days to trust a device (0 = always require TOTP). If > 0, a signed "trusted device" cookie skips the TOTP step |
| `totp_issuer_name` | `""` (falls back to site name at read time) | Label shown in authenticator apps |

Settings are seeded automatically by `update_database` from `settings.json` — no migration needed.

**New User methods (`data/users_class.php`):**
```php
function enable_totp($secret)        // Sets usr_totp_secret, usr_totp_enabled_time=now(), generates fresh usr_totp_hmac_key, saves
function disable_totp()              // Clears usr_totp_secret, usr_totp_enabled_time, usr_totp_backup_codes, usr_totp_last_used_step, usr_totp_hmac_key
function verify_totp($code)          // Validates a 6-digit TOTP code against the stored secret (with +-1 window). Rejects codes from a time step <= usr_totp_last_used_step (replay prevention). Updates usr_totp_last_used_step on success.
function generate_backup_codes()     // Returns 10 plaintext codes, stores Argon2id hashes in usr_totp_backup_codes (uses User::GeneratePassword for consistency with password hashing)
function verify_backup_code($code)   // Strips dashes/whitespace, checks against stored hashes, removes used code, returns true/false
function has_totp_enabled()          // Returns !empty($this->get('usr_totp_enabled_time'))
```

**Files to create/modify:**
| File | Action |
|------|--------|
| `composer.json` | Add `spomky-labs/otphp` and `chillerlan/php-qrcode` |
| `data/users_class.php` | Add 5 fields to `$field_specifications`, add 6 methods above |
| `settings.json` | Add `totp_require_admins`, `totp_remember_device_days`, `totp_issuer_name` |

### Phase 2: Login Flow Modification

**Modified login flow (login_logic.php):**

After step 4 (activation check) and before step 5 (`store_session_variables`), insert:

```
4b. If $user->has_totp_enabled():
    - session_regenerate_id(true) — prevents session fixation on the pending state
    - Store user ID in $_SESSION['totp_pending_user_id']
    - Store remember-me preference in $_SESSION['totp_pending_remember']
    - Store return URL in $_SESSION['totp_pending_return']
    - Store expiry timestamp in $_SESSION['totp_pending_expires'] (now + 600 seconds)
    - Redirect to /verify-totp
```

The user is NOT logged in at this point — only `totp_pending_user_id` is in the session. `store_session_variables()` is only called after TOTP verification succeeds (which itself calls `session_regenerate_id(true)` again — see `SessionControl.php:991`).

**Pending-state TTL:** If a user enters their password but never completes TOTP, the session vars would otherwise live indefinitely. `verify_totp_logic.php` checks `$_SESSION['totp_pending_expires']` first and clears all `totp_pending_*` vars + redirects to `/login` if expired. 10 minutes is generous for an authenticator-app workflow.

**TOTP verification endpoint (logic/verify_totp_logic.php):**
```
1. Check $_SESSION['totp_pending_user_id'] exists, else redirect to /login
2. Check $_SESSION['totp_pending_expires'] >= time(), else clear totp_pending_* and redirect to /login
3. Rate limit: RequestLogger::check_rate_limit('totp', 5, 300) — 5 attempts / 5 min
4. On POST:
   a. Load User by pending ID
   b. Strip dashes/spaces from submitted code, then:
      - If code is exactly 6 digits → try verify_totp($code)
      - Else if code is 8 alphanumeric → try verify_backup_code($code) and inform user a backup code was consumed
      - Else → show invalid-format error
   c. If verification fails, show error with remaining attempts
   d. If valid:
      - store_session_variables($user)
      - LoginClass::StoreUserLogin()
      - Set remember-me cookie if requested
      - Set trusted device cookie if totp_remember_device_days > 0
      - Clear totp_pending_* session vars
      - Redirect to return URL or /profile
```

**Remember Me cookie interaction:**
The current cookie system stores a sha256-hashed token in the per-user `usr_remember_tokens` JSONB array (multi-device support, 90-day expiry, pruned on each save). On a successful cookie validation, `SessionControl::get_user_from_cookie()` calls `store_session_variables()` directly (`includes/SessionControl.php:612`). This needs modification:
- After a token matches and the user is loaded, if `$user->has_totp_enabled()` AND no valid trusted device cookie exists:
  - `session_regenerate_id(true)` — same fixation defense as the password path
  - Set `totp_pending_user_id` and `totp_pending_expires` in session instead of logging in (do NOT call `store_session_variables()` or `LoginClass::StoreUserLogin()` yet)
  - Redirect to `/verify-totp`
- If a valid trusted device cookie exists, allow the cookie login to proceed as-is
- Do NOT delete the remember-me cookie at this point — it should remain valid so a successful TOTP completes the auto-login

**Trusted device cookie (optional, controlled by `totp_remember_device_days`):**
- Cookie name: `totp_trusted`
- Value: `{user_id};{expiry_timestamp};{hmac_sha256(user_id + expiry + usr_totp_enabled_time, usr_totp_hmac_key)}`
- HMAC key is the per-user `usr_totp_hmac_key` — see "Per-user HMAC key" note in Phase 1. No site-wide secret to manage.
- Validated by loading the user (already required to check 2FA-still-active) and recalculating HMAC. Invalidated automatically if the user disables/re-enables 2FA (because both `usr_totp_enabled_time` AND `usr_totp_hmac_key` rotate — belt-and-suspenders).
- Set/cleared from inside `SessionControl` so it can reuse the existing private `set_secure_cookie()` helper (same HttpOnly/Secure/SameSite=Lax flags used for the `tt` remember-me cookie)
- **Revocation:** the only way to invalidate outstanding trusted-device cookies is to disable then re-enable 2FA from `/profile/security`, which rotates both `usr_totp_enabled_time` and `usr_totp_hmac_key` and breaks the HMAC for all existing cookies. There is no per-device revocation UI — surface this on the security page so users know how to recover from a stolen device.

**Files to create/modify:**
| File | Action |
|------|--------|
| `logic/verify_totp_logic.php` | **New** — TOTP verification logic |
| `views/verify-totp.php` | **New** — TOTP entry form |
| `logic/login_logic.php` | Insert 2FA check after password verification |
| `includes/SessionControl.php` | Modify `get_user_from_cookie()` to check TOTP requirement |
| `serve.php` | No route needed — `/verify-totp` resolves automatically to `views/verify-totp.php` |

### Phase 3: 2FA Setup UI (Profile)

**New profile page: `/profile/security`**

This page sits in the existing profile tab menu alongside Edit Account, Change Password, etc. It shows:

**When 2FA is NOT enabled:**
- Status indicator: "Two-factor authentication is not enabled"
- "Enable Two-Factor Authentication" button

**Enable flow (multi-step on same page):**
1. User clicks "Enable 2FA"
2. Page generates a TOTP secret, stores it in `$_SESSION['totp_setup_secret']`, and displays:
   - QR code (SVG rendered server-side by chillerlan/php-qrcode)
   - Manual entry key (Base32 secret displayed as text)
   - `otpauth://totp/{issuer}:{email}?secret={secret}&issuer={issuer}` URI
   - The secret is NOT stored in the database or in a hidden form field at this stage
3. User scans QR code with authenticator app
4. User enters the current 6-digit code to confirm setup (validated against `$_SESSION['totp_setup_secret']`)
5. If code validates:
   - Secret is moved from session to the user record
   - `$_SESSION['totp_setup_secret']` is cleared
   - 10 backup codes are generated and displayed ONCE
   - User is instructed to save backup codes securely
   - 2FA is now active
   - If the user navigates away before completing step 4, the session secret is abandoned — no partial state is saved to the database

**When 2FA IS enabled:**
- Status indicator: "Two-factor authentication is enabled" with enabled date
- "Regenerate Backup Codes" button — generates new set of 10, displays them once, replaces old set
- "Disable Two-Factor Authentication" button — requires current TOTP code or password to confirm

**Tab menu update:**
Add `'Security' => '/profile/security'` to the `$page_vars['tab_menus']` array in all profile logic files. The current array (verbatim, from `account_edit_logic.php`) is:
```php
$page_vars['tab_menus'] = array(
    'Edit Account' => '/profile/account_edit',
    'Change Password' => '/profile/password_edit',
    'Edit Address' => '/profile/address_edit',
    'Edit Phone Number' => '/profile/phone_numbers_edit',
    'Change Contact Preferences' => '/profile/contact_preferences',
);
```
Append the Security entry to each of: `account_edit_logic.php`, `password_edit_logic.php`, `address_edit_logic.php`, `phone_numbers_edit_logic.php`, `contact_preferences_logic.php`.

**Files to create/modify:**
| File | Action |
|------|--------|
| `logic/security_logic.php` | **New** — 2FA setup/disable/backup code logic |
| `views/profile/security.php` | **New** — Security settings page with QR code display |
| `logic/account_edit_logic.php` | Add 'Security' to tab_menus array |
| `logic/password_edit_logic.php` | Add 'Security' to tab_menus array |
| `logic/address_edit_logic.php` | Add 'Security' to tab_menus array |
| `logic/phone_numbers_edit_logic.php` | Add 'Security' to tab_menus array |
| `logic/contact_preferences_logic.php` | Add 'Security' to tab_menus array |

### Phase 4: Admin Controls

**Admin user edit (adm/admin_users_edit.php):**
In the existing security section (near IP restrictions), add:
- Read-only display: "2FA Status: Enabled (since {date})" — derived from `usr_totp_enabled_time` — or "Not enabled" when the field is NULL
- "Reset 2FA" button — calls `$user->disable_totp()` to clear the secret, backup codes, enabled-time, and last-used-step. Used when a user loses their authenticator device and can't use backup codes.
- Confirmation dialog before resetting
- **Audit log:** record the reset via `ChangeTracking::logChange()` (the same mechanism used for tier changes — see `logic/change_tier_logic.php:416,453`):
  ```php
  ChangeTracking::logChange(
      'user', $target_user_id, $target_user_id,
      'usr_totp_enabled_time',
      $previous_enabled_time, null,
      'admin_2fa_reset',
      'user', $session->get_user_id(),
      $session->get_user_id()
  );
  ```
  The `cht_changed_by_usr_user_id` field captures which admin performed the reset; the change reason `admin_2fa_reset` distinguishes it from a user-initiated disable.

**Admin settings (adm/admin_settings.php):**
Add a "Two-Factor Authentication" section with:
- `totp_require_admins` — checkbox: "Require 2FA for admin accounts (permission 5+)"
- `totp_remember_device_days` — number input: "Trust device for N days (0 = always require)"
- `totp_issuer_name` — text input: "App name shown in authenticator"

**Enforcing totp_require_admins:**
When this setting is enabled, `SessionControl::check_permission()` checks if the user has permission >= 5 and `usr_totp_enabled_time IS NULL`. If so, redirect to `/profile/security` with a flash message: "Your administrator account requires two-factor authentication."

This follows the existing `must_change_password()` gate pattern in `check_permission()` (`includes/SessionControl.php:947`) — the redirect happens after the permission check passes but before the page renders, and exempts `/profile/security` itself plus `/logout` from the redirect to avoid a loop. No grace period — enforcement is immediate. Organizations can communicate the policy to staff before enabling the setting.

**Files to modify:**
| File | Action |
|------|--------|
| `adm/admin_users_edit.php` | Add 2FA status display and reset button |
| `adm/logic/admin_users_edit_logic.php` | Handle 2FA reset POST action |
| `adm/admin_settings.php` | Add TOTP settings section |

### Phase 5: Edge Cases and Hardening

**Password reset flow:**
- Password reset via email link should NOT require TOTP (user has already proved identity via email access)
- After password reset, if 2FA was enabled, it remains enabled — the user will need to enter a TOTP code on their next login
- No changes needed to password reset flow

**Account activation flow:**
- Activation links that auto-log-in the user (the `usr_password` is NULL path in login_logic.php lines 50-59) should skip TOTP since the user is being activated for the first time
- Activation links for email verification (user already has a password) redirect to a confirmation page, not auto-login — no TOTP concern

**Session security:**
- IP change detection (already implemented in SessionControl) continues to work independently of 2FA
- If a major IP change forces logout, user must re-enter password AND TOTP on next login

**Rate limiting:**
- TOTP verification: 5 attempts per 5 minutes (separate from login rate limit)
- After exhausting attempts, user must wait — no lockout of the entire account
- Backup code attempts count toward the same rate limit

**Backup code format:**
- 10 codes, each 8 alphanumeric characters
- **Display format:** `XXXX-XXXX` (with hyphen for readability) — e.g., `A7K2-M9P4`
- **Acceptance:** `verify_backup_code()` strips all dashes and whitespace from the submitted value before comparison, so users can paste with or without the hyphen and the format is still uniquely distinguishable from a 6-digit TOTP code
- Stored as Argon2id hashes via `User::GeneratePassword()` — matches the platform's current password hashing standard (commit `a168d3a5`, "Security audit Phase 4: Argon2id"). The hash is computed against the canonical (dash-stripped) form
- Each code is single-use — removed from the array after successful verification
- User can regenerate all codes from the security page (invalidates previous set)

## Security Considerations

1. **TOTP window**: Allow +-1 time step (30 seconds each) to account for clock drift — this is the standard practice
2. **Replay prevention**: `usr_totp_last_used_step` stores the time step of the last accepted code. `verify_totp()` rejects any code from the same or earlier step, preventing reuse within the valid window
3. **Backup code hashing**: Use Argon2id (via `User::GeneratePassword()`) — same standard as user passwords. If the database is compromised, backup codes remain protected.
4. **No SMS/email fallback**: TOTP-only (app-based) is more secure than SMS. Backup codes serve as the recovery mechanism.
5. **QR code generation**: Server-side SVG rendering — no external service call, no data leakage

## Files Summary

### New Files
| File | Purpose |
|------|--------|
| `logic/verify_totp_logic.php` | TOTP verification during login |
| `views/verify-totp.php` | TOTP code entry page |
| `logic/security_logic.php` | Profile 2FA setup/management logic |
| `views/profile/security.php` | Profile security settings page |

### Modified Files
| File | Changes |
|------|---------|
| `composer.json` | Add otphp and php-qrcode dependencies |
| `data/users_class.php` | Add 4 TOTP fields, 6 methods |
| `settings.json` | Add 3 TOTP settings (declarative — `update_database` seeds them) |
| `logic/login_logic.php` | Insert 2FA check between password verification and session creation |
| `includes/SessionControl.php` | Modify cookie-based login to respect 2FA |
| `logic/account_edit_logic.php` | Add Security tab to menu |
| `logic/password_edit_logic.php` | Add Security tab to menu |
| `logic/address_edit_logic.php` | Add Security tab to menu |
| `logic/phone_numbers_edit_logic.php` | Add Security tab to menu |
| `logic/contact_preferences_logic.php` | Add Security tab to menu |
| `adm/admin_users_edit.php` | Add 2FA status display and reset |
| `adm/logic/admin_users_edit_logic.php` | Handle 2FA reset action |
| `adm/admin_settings.php` | Add TOTP settings section |

## Implementation Order

Phases 1-3 are the core feature. Phase 4 adds admin controls. Phase 5 hardens the implementation.

Phase 1 and 2 can be developed together since the login flow change depends on the data model. Phase 3 (profile UI) can be developed in parallel once the User model methods exist. Phase 4 and 5 are independent of each other.
