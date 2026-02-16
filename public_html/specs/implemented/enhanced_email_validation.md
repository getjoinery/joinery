# Enhanced Email Validation with DNS MX Checking

## Overview

Enhance the existing `email` validation rule in SystemBase's `field_specifications` validation system to also perform DNS MX record checking. When an email passes the current `filter_var(FILTER_VALIDATE_EMAIL)` check, we additionally verify that the domain has valid MX (or at minimum A) DNS records, confirming the domain can actually receive email.

This requires no new classes, files, or validation rule types — just extending the existing `case 'email':` block in `SystemBase::prepare()`.

## Design Principle: Fail-Open

**If the DNS check itself fails (network issue, timeout, DNS server down, firewall), the email MUST still pass validation.** The goal is to catch clearly invalid emails while never producing false negatives on legitimate addresses. A DNS infrastructure problem should never prevent a real user from signing up or updating their profile.

## Current State

### Existing Email Validation (`SystemBase.php:1031`)
```php
case 'email':
    if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
        if (!filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
            $is_valid = false;
            $error_message = $custom_messages['email'] ?? "Field '$field_name' must be a valid email address.";
        }
    }
    break;
```

This only checks format — it accepts `user@nonexistent-domain-xyz.com` as valid.

### Existing Usage
The User model already uses this rule:
```php
'usr_email' => array('type'=>'varchar(64)', 'required'=>true, 'validation' => array('email' => true)),
```

No other fields in the codebase currently use `'email' => true`, so the User model is the only consumer.

### Also Exists: `LibraryFunctions::IsValidEmail()`
A separate regex-based check at `LibraryFunctions.php:338` used in `User::prepare()` directly. This is independent of the `field_specifications` validation system and should also be updated to include the MX check for consistency.

## Edge Cases and Mitigations

### DNS Lookup Failures (Critical)
**Problem:** PHP's `checkdnsrr()` returns `false` for both "no records exist" AND "DNS lookup failed" — there's no way to tell them apart. A network hiccup would incorrectly reject valid emails.

**Solution:** Use `dns_get_record()` instead, which returns:
- **`false`** — lookup failed (network error, timeout, etc.) → **pass the email**
- **Empty array `[]`** — lookup succeeded, no records found → **reject the email**

This lets us distinguish infrastructure failures from genuinely missing records.

### Other Edge Cases
| Edge Case | Risk | Mitigation |
|-----------|------|------------|
| DNS server timeout / network issue | Would reject valid email | Fail-open: `dns_get_record()` returns `false` on failure → pass |
| Brand new domain, DNS not fully propagated | Might reject valid email | Low risk — MX records propagate quickly; fail-open covers temporary gaps |
| Server behind restrictive firewall blocking DNS | Would reject all emails | Fail-open: lookup failure → pass |
| Subdomain email (user@sub.company.com) | Might not have own MX | A record fallback covers this; parent MX inheritance works at SMTP level |
| Internationalized domain names (IDN) | `dns_get_record()` may need punycode | `filter_var(FILTER_VALIDATE_EMAIL)` already rejects non-ASCII domains, so this won't reach the DNS check |
| `dns_get_record()` emits PHP warning | Noisy error log | Suppress with `@` operator since we handle the failure case explicitly |

## Proposed Changes

### 1. Enhance `case 'email':` in `SystemBase::prepare()`

**File:** `includes/SystemBase.php`

After the existing `filter_var` check passes, add a DNS MX record lookup on the domain portion of the email:

```php
case 'email':
    if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
        // Step 1: Format validation (existing)
        if (!filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
            $is_valid = false;
            $error_message = $custom_messages['email'] ?? "Field '$field_name' must be a valid email address.";
        }
        // Step 2: DNS MX record check (new, fail-open)
        else {
            $domain = substr($field_value, strrpos($field_value, '@') + 1);
            $mx_records = @dns_get_record($domain, DNS_MX);
            if ($mx_records === false) {
                // DNS lookup failed — pass the email (fail-open)
            } elseif (empty($mx_records)) {
                // No MX records — check for A record fallback (RFC 5321)
                $a_records = @dns_get_record($domain, DNS_A);
                if ($a_records === false) {
                    // DNS lookup failed — pass the email (fail-open)
                } elseif (empty($a_records)) {
                    // Lookup succeeded, definitively no MX or A records
                    $is_valid = false;
                    $error_message = $custom_messages['email_mx'] ?? "The email domain '$domain' does not appear to accept email.";
                }
            }
        }
    }
    break;
```

### 2. Update `LibraryFunctions::IsValidEmail()`

**File:** `includes/LibraryFunctions.php`

Add the same DNS check with the same fail-open approach:

```php
static function IsValidEmail($email) {
    if (preg_match('/^[A-Z0-9._%+\\-\\#!$%&\'*\/=?^_`{}|~]+@[A-Z0-9.-]+\.[A-Z]{2,10}$/i', $email) === 0) {
        return false;
    }
    // DNS MX check (fail-open: if lookup fails, still return true)
    $domain = substr($email, strrpos($email, '@') + 1);
    $mx_records = @dns_get_record($domain, DNS_MX);
    if (is_array($mx_records) && empty($mx_records)) {
        // No MX — check A record fallback
        $a_records = @dns_get_record($domain, DNS_A);
        if (is_array($a_records) && empty($a_records)) {
            return false;
        }
    }
    return true;
}
```

### 3. Fix Missing `usr_email_bounce_unverify_time` Field

**File:** `data/users_class.php`

The method `email_unverify_bouncing_user()` at line ~676 sets `usr_email_bounce_unverify_time` but this field is not defined in `$field_specifications`. Add it:

```php
'usr_email_bounce_unverify_time' => array('type'=>'timestamp(6)'),
```

This is a pre-existing bug unrelated to this feature but discovered during investigation. It should be fixed in the same pass since it's in the same file and email-related.

## What This Catches

| Scenario | Current | After |
|----------|---------|-------|
| `user@gmail.com` | Pass | Pass |
| `not-an-email` | Fail | Fail |
| `user@nonexistent-domain-xyz.com` | Pass | **Fail** |
| `user@expired-company.com` (domain expired, no DNS) | Pass | **Fail** |
| `user@example.com` (has A record but no MX) | Pass | Pass (A record fallback) |
| `user@valid-domain.com` but DNS server is down | Pass | Pass (fail-open) |

## What This Does NOT Catch

- Valid domain but non-existent mailbox (e.g., `fake123@gmail.com`) — requires SMTP or API verification
- Disposable email domains (e.g., `user@mailinator.com`) — domain has valid MX records
- Full mailbox or disabled accounts — requires SMTP probing or API

These would require paid services (Mailgun validation, ZeroBounce, etc.) and are out of scope.

## Performance Considerations

- `dns_get_record()` is a network call but typically resolves in 10-50ms with DNS caching
- Only runs when saving a record with an email field that has `'email' => true` validation
- DNS results are cached by the OS/resolver for the TTL period, so repeated checks for the same domain are fast
- No impact on page loads — only fires during `prepare()`/`save()` operations

## Testing

1. Run `php -l` on both modified files
2. Run `validate_php_file.php` on both modified files
3. Verify existing user registration/profile update still works for valid emails
4. Verify a clearly fake domain is rejected on user profile save
5. Verify a DNS failure scenario does NOT reject the email (can test by temporarily pointing to a bad DNS resolver, or just confirm the logic)
6. Check error logs after testing for any unexpected failures

## Files Modified

1. `includes/SystemBase.php` — Enhance `case 'email':` validation block
2. `includes/LibraryFunctions.php` — Enhance `IsValidEmail()` method
3. `data/users_class.php` — Add missing `usr_email_bounce_unverify_time` field spec

## Backfill Utility (Optional Future Work)

A small utility script could be added later to scan existing users and flag those with invalid domains. This would be a simple loop calling `LibraryFunctions::IsValidEmail()` on each user's email and reporting results. Not included in this spec since the primary value is preventing bad emails going forward.
