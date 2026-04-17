# Specification: Remove Mailgun Version Setting

## Problem Statement

The `mailgun_version` setting provides a dropdown to choose between Mailgun PHP SDK "Version 2.X" and "Version 3.X". However:

1. **SDK v2.x syntax no longer works** - The installed library is `mailgun/mailgun-php v3.6.3`. The v2.x constructor `new Mailgun($apiKey)` throws a fatal error:
   ```
   PHP Fatal error: Mailgun\Mailgun::__construct(): Argument #1 ($configurator)
   must be of type Mailgun\HttpClient\HttpClientConfigurator, string given
   ```

2. **SDK v2.x was deprecated in 2018-2019** - When PHP 5 support was dropped and the SDK moved to PSR-18 HTTP client abstraction.

3. **The setting causes confusion** - Users may select "Version 2.X" thinking it's correct, causing silent email failures.

## Current Implementation

The `mailgun_version` setting is used in:

| File | Usage |
|------|-------|
| `includes/EmailSender.php:245,291` | Branches client initialization and send method |
| `adm/admin_settings_email.php:314-316,335,346` | Admin dropdown and validation branching |
| `tests/integration/mailgun_test.php:23,42` | Test file version branching |
| `utils/create_install_sql.php:619` | Default value set to `'2'` |
| `migrations/migrations.php:381-382` | Original migration that created the setting |

### Current Code Pattern (EmailSender.php)

```php
// Client initialization
if ($settings->get_setting('mailgun_version') == 1) {
    $mg = new Mailgun($apiKey);  // BROKEN - throws fatal error
} else {
    $mg = Mailgun::create($apiKey);  // Works
}

// Sending
if ($settings->get_setting('mailgun_version') == 1) {
    $result = $mg->sendMessage($domain, $email);  // v2.x syntax
} else {
    $result = $mg->messages()->send($domain, $email);  // v3.x syntax
}
```

## Proposed Changes

### 1. `includes/EmailSender.php`

Remove version conditionals, use only v3.x syntax:

```php
// Client initialization - AFTER
if ($settings->get_setting('mailgun_eu_api_link')) {
    $mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
} else {
    $mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
}

// Sending - AFTER
$result = $mg->messages()->send($domain, $email_to_send);
```

### 2. `adm/admin_settings_email.php`

- Remove the `mailgun_version` dropdown (lines 314-317)
- Remove `$mailgun_version` variable and version-based branching in validation (lines 335, 346-418)
- Use only v3.x validation code path

### 3. `tests/integration/mailgun_test.php`

Remove version conditionals, use only v3.x syntax.

### 4. `utils/create_install_sql.php`

Remove the `mailgun_version` default setting (line 619), or leave it for backwards compatibility.

### 5. `docs/email_system.md`

Update documentation to remove references to `mailgun_version` setting.

### 6. Database Setting

The `mailgun_version` setting can remain in `stg_settings` - it simply won't be read anymore. No migration needed to remove it.

## Files to Modify

1. `includes/EmailSender.php` - Remove version conditionals
2. `adm/admin_settings_email.php` - Remove dropdown and validation branching
3. `tests/integration/mailgun_test.php` - Remove version conditionals
4. `utils/create_install_sql.php` - Remove default setting
5. `docs/email_system.md` - Update documentation

## Files to NOT Modify

- `migrations/migrations.php` - Never remove or modify existing migrations
- `specs/implemented/*.md` - Historical documentation, leave as-is

## Testing

1. Verify Mailgun validation works on admin settings page
2. Send a test email via Mailgun to confirm sending works
3. Run `tests/integration/mailgun_test.php` to verify test passes

## Rollback

If issues arise, the `mailgun_version` setting still exists in the database. Code could be reverted and the setting would still be available. However, v2.x code path would still be broken since the library version (v3.6.3) doesn't support it.

## Notes

- This change has no effect on sites using SMTP as their email service
- The EU API link setting (`mailgun_eu_api_link`) remains unchanged and continues to work
- No user action required after deployment - any existing `mailgun_version` values are simply ignored
