---
title: Remember-Me Token Replacement (H2)
status: planned
priority: high
---

# Remember-Me Token Replacement

## Problem

The current remember-me implementation stores a cookie containing an encoded user ID, encoded expiration, and a SHA1 HMAC with a hardcoded secret:

```
encoded_user_id ; encoded_expiration ; sha1(user_id + expiration + HARDCODED_SECRET)
```

Issues:
- **Hardcoded secret in source code** — anyone with code access can forge cookies for any user
- **SHA1** — cryptographically broken since 2017
- **No revocation** — stolen cookies cannot be invalidated individually
- **User ID in cookie** — unnecessary information exposure

## Solution

Store per-device tokens as a JSON array on the users table. Each token is a cryptographically random value; only a hash of it is stored server-side.

## No Backwards Compatibility Required

All active remember-me sessions will be invalidated on deploy. Users will simply need to log in again and re-check "Remember Me". No migration logic needed.

## Database Schema

Add one new JSON field to the `User` data class:

```php
'usr_remember_tokens' => ['type' => 'json', 'is_nullable' => true],
```

The system will auto-create the column. Each entry in the JSON array:

```json
[
  {
    "hash": "<sha256 of random token>",
    "expires": 1234567890,
    "created": 1234567890
  }
]
```

## Cookie Format

The cookie `tt` stores only the raw random token — nothing else:

```
<64 hex chars of random token>
```

No user ID, no expiration, no HMAC. The server looks up the hash to find the user.

## Implementation

### 1. Add field to User data class (`data/users_class.php`)

```php
public static $json_vars = array('usr_remember_tokens'); // add to existing array
public static $field_specifications = array(
    // ... existing fields ...
    'usr_remember_tokens' => ['type' => 'json', 'is_nullable' => true],
);
```

### 2. Rewrite `save_user_to_cookie()` in `SessionControl.php`

```php
public function save_user_to_cookie() {
    if (!$this->get_user_id()) return;

    $user = new User($this->get_user_id(), TRUE);

    // Generate cryptographically secure random token
    $raw_token = bin2hex(random_bytes(32)); // 64 hex chars
    $token_hash = hash('sha256', $raw_token);
    $expires = time() + (90 * 24 * 60 * 60); // 90 days

    // Load existing tokens, prune expired ones
    $tokens = $user->get('usr_remember_tokens') ?? [];
    $tokens = array_values(array_filter($tokens, fn($t) => $t['expires'] > time()));

    // Append new token
    $tokens[] = [
        'hash'    => $token_hash,
        'expires' => $expires,
        'created' => time(),
    ];

    $user->set('usr_remember_tokens', $tokens);
    $user->save();

    $this->set_secure_cookie('tt', $raw_token, $expires);
}
```

### 3. Rewrite `get_user_from_cookie()` in `SessionControl.php`

```php
public function get_user_from_cookie() {
    if (empty($_COOKIE['tt'])) return false;

    $raw_token = $_COOKIE['tt'];

    // Basic format validation — must be 64 hex chars
    if (!preg_match('/^[0-9a-f]{64}$/', $raw_token)) {
        $this->delete_cookie('tt');
        return false;
    }

    $token_hash = hash('sha256', $raw_token);

    // Find the user with this token hash
    // Use DbConnector since we need to search inside JSON
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
    $sql = "SELECT usr_user_id FROM usr_users
            WHERE usr_remember_tokens IS NOT NULL
            AND usr_remember_tokens::jsonb @> :token_search::jsonb
            AND usr_delete_time IS NULL";
    $q = $dblink->prepare($sql);
    $q->bindValue(':token_search', json_encode([['hash' => $token_hash]]), PDO::PARAM_STR);
    $q->execute();
    $row = $q->fetch(PDO::FETCH_OBJ);

    if (!$row) {
        $this->delete_cookie('tt');
        return false;
    }

    // Load user and verify token expiration
    try {
        $user = new User($row->usr_user_id, TRUE);
    } catch (Exception $e) {
        $this->delete_cookie('tt');
        return false;
    }

    $tokens = $user->get('usr_remember_tokens') ?? [];
    $matched = null;
    foreach ($tokens as $token) {
        if ($token['hash'] === $token_hash) {
            $matched = $token;
            break;
        }
    }

    if (!$matched || $matched['expires'] < time()) {
        $this->delete_cookie('tt');
        return false;
    }

    // Valid — log the user in
    $this->store_session_variables($user);
    LoginClass::StoreUserLogin($user->key, LoginClass::LOGIN_COOKIE);
    return true;
}
```

### 4. Update logout in `SessionControl.php`

On logout, remove only the current device's token from the JSON array:

```php
// In the logout method, before session_destroy():
if (!empty($_COOKIE['tt'])) {
    $raw_token = $_COOKIE['tt'];
    if (preg_match('/^[0-9a-f]{64}$/', $raw_token) && $this->get_user_id()) {
        $token_hash = hash('sha256', $raw_token);
        $user = new User($this->get_user_id(), TRUE);
        $tokens = $user->get('usr_remember_tokens') ?? [];
        $tokens = array_values(array_filter($tokens, fn($t) => $t['hash'] !== $token_hash));
        $user->set('usr_remember_tokens', $tokens);
        $user->save();
    }
}
$this->delete_cookie('tt');
```

## Deploy Notes

- No migration needed — the `usr_remember_tokens` column will be auto-created as NULL for all users
- All existing `tt` cookies will fail validation (wrong format) and be silently deleted
- Users will be logged out on their next visit and prompted to log in again

## Security Properties

| Property | Before | After |
|----------|--------|-------|
| Secret storage | Hardcoded in source | None needed |
| Token algorithm | SHA1 HMAC | SHA256 of random_bytes(32) |
| Cookie contents | Encoded user ID + expiration | Opaque random token only |
| Revocation | Not possible | Per-device (remove from JSON array) |
| Logout all devices | Not possible | Clear usr_remember_tokens array |
| Token expiry | 365 days | 90 days |
| Concurrent devices | Single token | Multiple (one entry per device) |
