# Modern Cookie System Specification

## Overview

Update the cookie implementation in `SessionControl.php` to use modern, secure cookie practices while maintaining backward compatibility with PHP 7.x and 8.x.

## Problem Statement

The current cookie implementation in `SessionControl.php` has several issues:

1. **Missing cookie path** - `setcookie()` calls don't specify path, causing cookies to be restricted to the current directory instead of the entire site
2. **No SameSite attribute** - Modern browsers default to `Lax`, but explicit setting is required for consistent cross-browser behavior
3. **No Secure flag** - Cookies may be rejected or exposed on HTTPS sites
4. **No HttpOnly flag** - Cookies are accessible to JavaScript, creating XSS vulnerability

**Note:** The PHP session `gc_maxlifetime` (24 minutes) is a server configuration default and is not the root cause. The "remember me" cookie is designed to restore sessions after they expire - but it fails due to the missing path parameter.

## Goals

- Fix immediate logout issues caused by cookie path problems
- Implement modern cookie security attributes
- Maintain compatibility with PHP 7.3+ and all PHP 8.x versions
- Preserve existing "remember me" functionality
- No breaking changes to existing valid cookies (graceful migration)

## Technical Approach

### PHP Version Compatibility

PHP 7.3+ introduced the `$options` array syntax for `setcookie()`. For maximum compatibility, we'll use a helper function that:
- Uses the modern array syntax on PHP 7.3+
- Falls back to the legacy parameter syntax on older versions (if needed)

```php
// PHP 7.3+ syntax (preferred)
setcookie('name', 'value', [
    'expires' => time() + 3600,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Legacy syntax (PHP < 7.3) - parameters only, no samesite support
setcookie('name', 'value', time() + 3600, '/', '', true, true);
```

Since PHP 7.3 is the minimum for SameSite support and PHP 7.2 is EOL, we'll target PHP 7.3+ as minimum but gracefully degrade if older version detected.

## Implementation Details

### 1. Create Cookie Helper Method

Add a new private method to `SessionControl` class:

**File:** `includes/SessionControl.php`

```php
/**
 * Set a cookie with modern security attributes
 * Compatible with PHP 7.3+ (uses options array for SameSite support)
 *
 * @param string $name Cookie name
 * @param string $value Cookie value
 * @param int $expires Expiration timestamp
 * @param bool $httponly Whether cookie is HTTP only (default true)
 * @param string $samesite SameSite attribute: 'Strict', 'Lax', or 'None' (default 'Lax')
 * @return bool Success
 */
private function set_secure_cookie($name, $value, $expires, $httponly = true, $samesite = 'Lax') {
    $secure = $this->is_secure_connection();

    // SameSite=None requires Secure flag
    if ($samesite === 'None' && !$secure) {
        $samesite = 'Lax';
    }

    // PHP 7.3+ supports options array with samesite
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        return setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite
        ]);
    }

    // Fallback for PHP < 7.3 (no SameSite support)
    return setcookie($name, $value, $expires, '/', '', $secure, $httponly);
}

/**
 * Delete a cookie by setting expiration in the past
 *
 * @param string $name Cookie name
 * @return bool Success
 */
private function delete_cookie($name) {
    return $this->set_secure_cookie($name, '', time() - 3600, true, 'Lax');
}

/**
 * Determine if current connection is secure (HTTPS)
 *
 * @return bool
 */
private function is_secure_connection() {
    // Direct HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    // Behind load balancer/proxy
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }
    // Forwarded header (RFC 7239)
    if (!empty($_SERVER['HTTP_FORWARDED']) && preg_match('/proto=https/i', $_SERVER['HTTP_FORWARDED'])) {
        return true;
    }
    // Common port check
    if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        return true;
    }
    return false;
}
```

### 2. Update save_user_to_cookie()

**Current code (lines 110-124):**
```php
if ($this->get_user_id()) {
    $expire_time = time() + (365 * 24 * 60 * 60);
    setcookie(
        'tt',
        implode(';',
            array(
                LibraryFunctions::Encode($this->get_user_id(), 'user_id'),
                LibraryFunctions::Encode($expire_time, 'expiration_date'),
                sha1(
                    $this->get_user_id() . $expire_time .
                    'Ifz4lU5Bmwmbi17f2W4CW1I3XKrJmrWmc19bDAUBMNqyPVDEBfvBLUHQqxCk261')
                )),
        $expire_time);
}
```

**Updated code:**
```php
if ($this->get_user_id()) {
    $expire_time = time() + (365 * 24 * 60 * 60);
    $cookie_value = implode(';', array(
        LibraryFunctions::Encode($this->get_user_id(), 'user_id'),
        LibraryFunctions::Encode($expire_time, 'expiration_date'),
        sha1(
            $this->get_user_id() . $expire_time .
            'Ifz4lU5Bmwmbi17f2W4CW1I3XKrJmrWmc19bDAUBMNqyPVDEBfvBLUHQqxCk261')
    ));
    $this->set_secure_cookie('tt', $cookie_value, $expire_time, true, 'Lax');
}
```

### 3. Update All Cookie Deletion Calls

Replace all instances of `setcookie('tt', '', time() - 3600);` with `$this->delete_cookie('tt');`

**Locations to update:**
- Line 406: `setcookie('tt', '', time() - 3600);` in get_user_from_cookie() (invalid cookie - 4 parts)
- Line 416: `setcookie('tt', '', time() - 3600);` in get_user_from_cookie() (invalid user)
- Line 434: `setcookie('tt', '', time() - 3600);` in get_user_from_cookie() (user not allowed)
- Line 450: `setcookie('tt', '', time() - 3600);` in get_user_from_cookie() (medium security - expired/invalid)
- Line 460: `setcookie('tt', '', time() - 3600);` in get_user_from_cookie() (invalid user)
- Line 478: `setcookie('tt', '', time() - 3600);` in get_user_from_cookie() (user not allowed)
- Line 484: `setcookie('tt', '', time() - 3600);` in get_user_from_cookie() (invalid cookie format)
- Line 506: `setcookie(session_name(), '', time()-42000, '/');` in logout() (session cookie)
- Line 510: `setcookie('tt', '', time() - 3600);` in logout()

### 4. Update Session Cookie Deletion in logout()

**Current code (line 505-506):**
```php
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-42000, '/');
}
```

**Updated code:**
```php
if (isset($_COOKIE[session_name()])) {
    $this->delete_cookie(session_name());
}
```

## Migration Strategy

### Backward Compatibility with Existing Cookies

The cookie format (`tt` cookie value structure) remains unchanged. Existing valid cookies will continue to work because:

1. Cookie reading logic in `get_user_from_cookie()` is unchanged
2. Only the cookie attributes (path, secure, etc.) are being updated
3. New cookies will have proper attributes; old cookies will be replaced on next login

### Testing Checklist

1. **Fresh login test**
   - [ ] Log in with "Remember Me" checked
   - [ ] Close browser completely
   - [ ] Reopen browser and verify still logged in
   - [ ] Check cookie in browser dev tools: should have `path=/`

2. **Cross-path navigation test**
   - [ ] Log in at `/login`
   - [ ] Navigate to `/profile`, `/admin/`, `/events`
   - [ ] Verify session persists across all paths

3. **Session restore test** (the main bug fix)
   - [ ] Log in with "Remember Me" checked
   - [ ] Wait 30+ minutes for PHP session to expire
   - [ ] Return to site - should still be logged in (cookie restores session)

4. **Logout test**
   - [ ] Log in with "Remember Me"
   - [ ] Log out
   - [ ] Close and reopen browser
   - [ ] Verify NOT logged in (cookie was properly deleted)

5. **HTTPS/Secure flag test**
   - [ ] On HTTPS site, verify cookie has `Secure` flag in browser dev tools
   - [ ] On HTTP site (dev only), verify cookie works without Secure flag

6. **PHP version compatibility** (if multiple environments available)
   - [ ] Test on PHP 7.3
   - [ ] Test on PHP 8.x

## Files to Modify

| File | Changes |
|------|---------|
| `includes/SessionControl.php` | Add helper methods, update all setcookie calls |

## Security Considerations

1. **SameSite=Lax** - Prevents CSRF on state-changing requests while allowing normal navigation
2. **HttpOnly** - Prevents XSS attacks from stealing session cookies
3. **Secure flag** - Prevents cookie transmission over unencrypted connections (HTTPS only)
4. **Path=/** - Ensures cookie is sent for all site paths (fixes current bug)
5. **Strict mode** - Prevents session fixation attacks

## Rollback Plan

If issues arise:
1. The cookie helper methods can be reverted to use simple `setcookie()` calls
2. Session configuration can be removed/commented out
3. No database changes required - purely code changes

## Future Considerations

1. **Token rotation** - Consider rotating the remember-me token periodically
2. **Device tracking** - Could add device fingerprinting for additional security
3. **Concurrent session limits** - Could limit number of active sessions per user
4. **Session invalidation** - Password change should invalidate all remember-me tokens

## Version

- Spec Version: 1.0
- Created: 2025-12-28
- Target PHP: 7.3+, 8.x
