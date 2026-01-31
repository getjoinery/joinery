# Composer Autoload Standardization

## Problem Statement

The codebase has inconsistent patterns for loading Composer's `autoload.php`. This causes failures when running CLI scripts from directories other than the web root, because the `composerAutoLoad` setting contains a relative path (`../vendor/`).

## Root Cause

The `composerAutoLoad` database setting is stored as a relative path:
```
../vendor/
```

When PHP scripts run from different directories (e.g., `/utils/`), the relative path resolves incorrectly.

## Proposed Solution

Add two helper methods to PathHelper that encapsulate composer path resolution:

```php
/**
 * Get the absolute path to Composer's vendor directory
 *
 * The composerAutoLoad setting is relative to public_html, but vendor/
 * lives at the site root. This method resolves the path correctly
 * regardless of the current working directory.
 *
 * @return string Absolute path to vendor directory (with trailing slash)
 */
public static function getComposerVendorPath() {
    $settings = Globalvars::get_instance();
    return self::getBasePath() . $settings->get_setting('composerAutoLoad');
}

/**
 * Get the absolute path to Composer's autoload.php
 *
 * @return string Absolute path to autoload.php
 */
public static function getComposerAutoloadPath() {
    return self::getComposerVendorPath() . 'autoload.php';
}
```

### Usage

Most files just need the autoload path:
```php
require_once(PathHelper::getComposerAutoloadPath());
```

ComposerValidator needs the vendor directory:
```php
$this->composerPath = PathHelper::getComposerVendorPath();
```

### For files that need file_exists check

```php
$autoload_path = PathHelper::getComposerAutoloadPath();
if (file_exists($autoload_path)) {
    require_once($autoload_path);
}
```

### Why helper functions?

The vendor directory is in a special location (site root, not public_html). Requiring developers to remember the correct incantation (`PathHelper::getBasePath() . $settings->get_setting('composerAutoLoad') . 'autoload.php'`) is error-prone. Helper functions encapsulate this knowledge.

## Files Requiring Changes

| File | Line Numbers | Notes |
|------|--------------|-------|
| `includes/ComposerValidator.php` | 14 | Use `getComposerVendorPath()` |
| `includes/StripeHelper.php` | 9-10 | |
| `includes/SmtpMailer.php` | 7-20 | Has validation, just needs path fix |
| `data/mailing_lists_class.php` | 19-25 | Partially fixed, needs cleanup |
| `logic/subscriptions_logic.php` | 24-25 | |
| `logic/profile_logic.php` | 24-25 | |
| `adm/logic/admin_user_logic.php` | 35-36 | |
| `adm/admin_settings_payments.php` | 164-167, 262-265 | |
| `adm/admin_settings_email.php` | 184-187, 340-343 | |
| `ajax/calendly_init.php` | 14-15 | |
| `utils/mailchimp_synchronize.php` | 13-14 | |
| `utils/email_send_test.php` | 21-23 | |
| `tests/email/legacy/email_send_test.php` | 42-44 | |
| `tests/email/auth_analysis.php` | 42-44 | |
| `tests/integration/mailgun_test.php` | 19-20 | |
| `tests/integration/phpmailer_test.php` | 10 | BUG: ignores setting entirely |
| `tests/integration/calendly_test.php` | 13 | BUG: ignores setting entirely |
| `plugins/controld/logic/profile_logic.php` | 22-23 | |

**Total: 18 files, 22 locations**

## Implementation Plan

1. Add `getComposerVendorPath()` and `getComposerAutoloadPath()` methods to PathHelper
2. Update ComposerValidator to use `getComposerVendorPath()`
3. Update remaining 17 files to use `getComposerAutoloadPath()`
4. Test CLI scripts (especially `fix_sequences.php`)
5. Test web-based functionality (Stripe payments, email, Mailchimp)

## Testing Checklist

- [ ] `php utils/fix_sequences.php --dry-run` works
- [ ] Stripe payments process correctly
- [ ] Email sending works (SmtpMailer)
- [ ] Mailchimp sync works
- [ ] Admin settings pages load without errors
- [ ] Calendly integration works
