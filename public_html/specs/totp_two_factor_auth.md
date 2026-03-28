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
'usr_totp_secret'       => array('type'=>'varchar(255)'),              // Base32 encoded secret
'usr_totp_enabled'      => array('type'=>'bool', 'default'=>false),    // Whether 2FA is active
'usr_totp_backup_codes' => array('type'=>'jsonb'),                     // Array of bcrypt-hashed single-use codes
'usr_totp_enabled_time' => array('type'=>'timestamp(6)'),              // When 2FA was enabled
'usr_totp_last_used_step' => array('type'=>'bigint'),                  // Last successful TOTP time step (for replay prevention)
```

**New settings (migration):**
| Setting | Default | Purpose |
|---------|---------|---------|
| `totp_require_admins` | `false` | Require 2FA for users with permission >= 5 |
| `totp_remember_device_days` | `0` | Days to trust a device (0 = always require TOTP). If > 0, a signed "trusted device" cookie skips the TOTP step |
| `totp_issuer_name` | Site name | Label shown in authenticator apps |

**New User methods (`data/users_class.php`):**
```php
function enable_totp($secret)        // Sets usr_totp_secret, usr_totp_enabled=true, usr_totp_enabled_time=now()
function disable_totp()              // Clears secret, sets enabled=false, clears backup codes
function verify_totp($code)          // Validates a 6-digit TOTP code against the stored secret (with +-1 window). Rejects codes from a time step <= usr_totp_last_used_step (replay prevention). Updates usr_totp_last_used_step on success.
function generate_backup_codes()     // Returns 10 plaintext codes, stores bcrypt hashes in usr_totp_backup_codes
function verify_backup_code($code)   // Checks against stored hashes, removes used code, returns true/false
function has_totp_enabled()          // Returns (bool) usr_totp_enabled
```

**Files to create/modify:**
| File | Action |
|------|--------|
| `composer.json` | Add `spomky-labs/otphp` and `chillerlan/php-qrcode` |
| `data/users_class.php` | Add 5 fields to `$field_specifications`, add 6 methods above |
| `migrations/migrations.php` | Add settings migration for `totp_require_admins`, `totp_remember_device_days`, `totp_issuer_name` |

### Phase 2: Login Flow Modification

**Modified login flow (login_logic.php):**

After step 4 (activation check) and before step 5 (`store_session_variables`), insert:

```
4b. If user has usr_totp_enabled = true:
    - Store user ID in $_SESSION['totp_pending_user_id']
    - Store remember-me preference in $_SESSION['totp_pending_remember']
    - Store return URL in $_SESSION['totp_pending_return']
    - Redirect to /login/verify-totp
```

The user is NOT logged in at this point — only `totp_pending_user_id` is in the session. `store_session_variables()` is only called after TOTP verification succeeds.

**TOTP verification endpoint (logic/verify_totp_logic.php):**
```
1. Check $_SESSION['totp_pending_user_id'] exists, else redirect to /login
2. Rate limit: RequestLogger::check_rate_limit('totp', 5, 900) — 5 attempts / 15 min
3. On POST:
   a. Load User by pending ID
   b. Try verify_totp($code) first
   c. If that fails, try verify_backup_code($code) — inform user a backup code was consumed
   d. If both fail, show error with remaining attempts
   e. If valid:
      - store_session_variables($user)
      - LoginClass::StoreUserLogin()
      - Set remember-me cookie if requested
      - Set trusted device cookie if totp_remember_device_days > 0
      - Clear totp_pending_* session vars
      - Redirect to return URL or /profile
```

**Remember Me cookie interaction:**
`SessionControl::get_user_from_cookie()` currently calls `store_session_variables()` directly. This needs modification:
- If the user being restored has `usr_totp_enabled = true` AND no valid trusted device cookie exists:
  - Set `totp_pending_user_id` in session instead of logging in
  - Redirect to `/login/verify-totp`
- If a valid trusted device cookie exists, allow the cookie login to proceed as-is

**Trusted device cookie (optional, controlled by `totp_remember_device_days`):**
- Cookie name: `totp_trusted`
- Value: `{user_id};{expiry_timestamp};{hmac_sha256(user_id + expiry + usr_totp_enabled_time, site_secret)}`
- Validated by recalculating HMAC — invalidated automatically if user disables/re-enables 2FA (because `usr_totp_enabled_time` changes)
- HttpOnly, Secure, SameSite=Lax

**Files to create/modify:**
| File | Action |
|------|--------|
| `logic/verify_totp_logic.php` | **New** — TOTP verification logic |
| `views/verify-totp.php` | **New** — TOTP entry form |
| `logic/login_logic.php` | Insert 2FA check after password verification |
| `includes/SessionControl.php` | Modify `get_user_from_cookie()` to check TOTP requirement |
| `serve.php` | Add route for `/login/verify-totp` if needed (may work via automatic view resolution) |

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
Add `'Security' => '/profile/security'` to the shared tab menu array in all profile logic files (account_edit_logic, password_edit_logic, address_edit_logic, phone_numbers_edit_logic, contact_preferences_logic).

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
- Read-only display: "2FA Status: Enabled (since {date})" or "Not enabled"
- "Reset 2FA" button — disables 2FA for the user (clears secret, backup codes, enabled flag). Used when a user loses their authenticator device and can't use backup codes.
- Confirmation dialog before resetting

**Admin settings (adm/admin_settings.php):**
Add a "Two-Factor Authentication" section with:
- `totp_require_admins` — checkbox: "Require 2FA for admin accounts (permission 5+)"
- `totp_remember_device_days` — number input: "Trust device for N days (0 = always require)"
- `totp_issuer_name` — text input: "App name shown in authenticator"

**Enforcing totp_require_admins:**
When this setting is enabled, `SessionControl::check_permission()` checks if the user has permission >= 5 and `usr_totp_enabled` is false. If so, redirect to `/profile/security` with a flash message: "Your administrator account requires two-factor authentication." No grace period — enforcement is immediate. Organizations can communicate the policy to staff before enabling the setting.

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
- TOTP verification: 5 attempts per 15 minutes (separate from login rate limit)
- After exhausting attempts, user must wait — no lockout of the entire account
- Backup code attempts count toward the same rate limit

**Backup code format:**
- 10 codes, each 8 alphanumeric characters (e.g., `A7K2-M9P4`)
- Stored as bcrypt hashes (same approach as passwords)
- Each code is single-use — removed from the array after successful verification
- User can regenerate all codes from the security page (invalidates previous set)

## Security Considerations

1. **TOTP window**: Allow +-1 time step (30 seconds each) to account for clock drift — this is the standard practice
2. **Replay prevention**: `usr_totp_last_used_step` stores the time step of the last accepted code. `verify_totp()` rejects any code from the same or earlier step, preventing reuse within the valid window
3. **Backup code hashing**: Use bcrypt, not plaintext — if the database is compromised, backup codes remain protected
4. **No SMS/email fallback**: TOTP-only (app-based) is more secure than SMS. Backup codes serve as the recovery mechanism.
6. **QR code generation**: Server-side SVG rendering — no external service call, no data leakage

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
| `data/users_class.php` | Add 5 TOTP fields, 6 methods |
| `migrations/migrations.php` | Add 3 TOTP settings |
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
