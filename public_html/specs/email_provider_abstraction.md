# Email Provider Abstraction

## Goal

Refactor the email sending system so that adding a new email provider (e.g., SendGrid, AWS SES, Postmark) requires only creating a single new class file — with no modifications to `EmailSender.php` or other core code. The current Mailgun and SMTP providers must continue to work identically.

## Current State

The email system has a clean `EmailMessage` → `EmailSender` pipeline. `EmailMessage` and `EmailTemplate` are already provider-agnostic. The coupling to Mailgun lives in a few specific places:

1. **`includes/EmailSender.php`** — `use Mailgun\Mailgun` import, `sendViaMailgun()` method, switch statements in `sendWithService()` and `validateServiceConfiguration()` that must be edited to add providers
2. **`adm/admin_settings_email.php`** — hardcoded provider dropdown (`'mailgun' => 'Mailgun', 'smtp' => 'SMTP'`), provider-specific settings fields and API validation UI
3. **`ajax/mailgun_inbound_webhook.php`** — Mailgun-specific inbound webhook (separate concern, addressed in Phase 3)

**Out of scope:** `EmailMessage`, `EmailTemplate`, `SmtpMailer`, email forwarding plugin, test files, utility scripts. These are already clean or are test/diagnostic code that reasonably references a specific provider.

## Design

### EmailServiceProvider Interface

Create `includes/EmailServiceProvider.php`:

```php
interface EmailServiceProvider {
    /**
     * Return the provider's unique key (e.g., 'mailgun', 'smtp', 'sendgrid').
     * This is the value stored in the email_service / email_fallback_service settings.
     */
    public static function getKey(): string;

    /**
     * Return a human-readable label for admin UI (e.g., 'Mailgun', 'SMTP').
     */
    public static function getLabel(): string;

    /**
     * Return an array of setting keys this provider requires.
     * Each entry: ['key' => 'setting_name', 'label' => 'Human Label', 'type' => 'text|password', 'helptext' => '...']
     * Used by the admin settings page to dynamically render fields.
     */
    public static function getSettingsFields(): array;

    /**
     * Validate that this provider's required settings are configured.
     * Returns ['valid' => bool, 'errors' => string[]]
     */
    public static function validateConfiguration(): array;

    /**
     * Send an EmailMessage. Returns true on success, false on failure.
     * Should log errors via error_log() and optionally via the debug logger.
     * Must NOT queue failed emails — the caller (EmailSender) handles that.
     */
    public function send(EmailMessage $message): bool;

    /**
     * Send to multiple recipients efficiently (batch).
     * Default implementation can loop over send(), but providers like Mailgun
     * can override to use native batch APIs.
     *
     * Returns an array:
     *   'success' => bool (true only if ALL recipients succeeded)
     *   'failed_recipients' => string[] (email addresses that failed)
     *
     * The failed_recipients list is critical for fallback: EmailSender uses it
     * to pass only the unsent recipients to the fallback provider, avoiding
     * double-sends to recipients that already received the email.
     */
    public function sendBatch(EmailMessage $message, array $recipients): array;
}
```

### Provider Registry

`EmailSender` discovers providers automatically via a static registry pattern. No configuration file or manual registration needed.

```php
// In EmailSender.php
private static $providers = null;

private static function discoverProviders(): array {
    if (self::$providers !== null) {
        return self::$providers;
    }
    
    self::$providers = [];
    $provider_dir = PathHelper::getIncludePath('includes/email_providers/');
    
    foreach (glob($provider_dir . '*Provider.php') as $file) {
        require_once($file);
        $class = basename($file, '.php');
        if (class_exists($class) && in_array('EmailServiceProvider', class_implements($class))) {
            $key = $class::getKey();
            self::$providers[$key] = $class;
        }
    }
    
    return self::$providers;
}
```

### Provider Directory

All providers live in `includes/email_providers/`:

```
includes/email_providers/
  MailgunProvider.php
  SmtpProvider.php
```

### Admin Settings Integration

`getSettingsFields()` returns metadata that the admin settings page uses to render fields dynamically. Each provider also has an optional `validateApiConnection()` method for the "Run Validation" feature:

```php
// Optional — providers can implement this for the admin API validation panel
public static function validateApiConnection(): array;
// Returns ['success' => bool, 'details' => string, 'extra' => [...]]
```

The admin page iterates over discovered providers to build the service dropdown and render settings sections, replacing the current hardcoded approach.

## Implementation

### Phase 1: Baseline Tests (COMPLETE)

New tests added to `tests/email/suites/ServiceTests.php` that validate current behavior before refactoring. All 11/11 tests pass.

**New test methods:**

| Test | What it validates |
|---|---|
| `testServiceValidation()` | `validateService()` returns correct structure for mailgun, smtp, and unknown providers |
| `testServiceFallback()` | Bogus Mailgun API key → email sends via SMTP fallback |
| `testBatchSending()` | `sendBatch()` sends successfully, returns correct result format |
| `testBatchFallback()` | Batch with bogus primary → sends via SMTP fallback per-recipient |
| `testQueueOnTotalFailure()` | Both providers broken → email queued in `equ_queued_emails` for retry |

**Helper methods added:** `readSettingFromDb()`, `writeSetting()`, `snapshotSettings()`, `restoreSettings()` — allow tests to temporarily override settings via DB + Globalvars cache reflection, with guaranteed cleanup.

**Pre-existing bug found:** `queueFailedEmail()` in `EmailSender` passes `$recipient['name']` directly to `QueuedEmail::set('equ_to_name')`. When the recipient has no name (null), this fails because `equ_to_name` is a required field. Emails without recipient names silently fail to queue. The `queueForRetry()` method handles this correctly with `?? ''` but the field is still required, so empty string also fails. This should be fixed separately (make `equ_to_name` not required, or default to empty string).

**Post-refactor test updates needed:**
- `testBatchSending()` and `testBatchFallback()` — update assertions for the new `sendBatch()` return format (`['success' => bool, 'failed_recipients' => []]` instead of `[$email => bool]`)
- Add `testProviderDiscovery()` — validate `getAvailableServices()` and `getProviderSettings()` (methods don't exist yet)
- Update `testServiceDetection()` — replace hardcoded service list check with `getAvailableServices()`

### Phase 2: Interface and Provider Classes

**2a. Create `includes/EmailServiceProvider.php`**

Define the interface exactly as shown in the Design section above.

**2b. Create `includes/email_providers/MailgunProvider.php`**

Extract from `EmailSender::sendViaMailgun()`:

- Move the `use Mailgun\Mailgun` import here
- Move the Mailgun client initialization, email preparation, and batch sending logic
- Implement `getKey()` → `'mailgun'`
- Implement `getLabel()` → `'Mailgun'`
- Implement `getSettingsFields()` returning entries for `mailgun_api_key`, `mailgun_domain`, `mailgun_eu_api_link`
- Implement `validateConfiguration()` — check that `mailgun_api_key` and `mailgun_domain` are non-empty
- Implement `send()` — the current `sendViaMailgun()` logic
- Implement `sendBatch()` — the current batch logic (chunk recipients into groups of 500, use Mailgun recipient-variables)
- Implement `validateApiConnection()` — move the Mailgun domain-check logic currently in `admin_settings_email.php`

**2c. Create `includes/email_providers/SmtpProvider.php`**

Extract from `EmailSender::sendViaSMTP()`:

- Move the PHPMailer/SmtpMailer usage here
- Implement `getKey()` → `'smtp'`
- Implement `getLabel()` → `'SMTP'`
- Implement `getSettingsFields()` returning entries for `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_encryption`, `smtp_auth`, `smtp_helo`, `smtp_hostname`, `smtp_sender`
- Implement `validateConfiguration()` — check that `smtp_host` is non-empty
- Implement `send()` — the current `sendViaSMTP()` logic
- `sendBatch()` — use default loop-over-send implementation

### Phase 3: Refactor EmailSender

**3a. Add provider discovery**

Add `discoverProviders()` and `getProvider($key)` methods as described in the Design section.

**3b. Replace switch statements**

Replace `sendWithService()`:
```php
private function sendWithService($service, EmailMessage $message) {
    $provider = self::getProvider($service);
    if (!$provider) {
        $this->logEmailDebug("Unknown email service: $service");
        return false;
    }
    return $provider->send($message);
}
```

Replace `validateServiceConfiguration()`:
```php
public function validateServiceConfiguration($service = null) {
    $service = $service ?: $this->settings->get_setting('email_service') ?: 'mailgun';
    $provider_class = self::getProviderClass($service);
    if (!$provider_class) {
        return ['valid' => false, 'service' => $service, 'errors' => ["Unknown email service: $service"]];
    }
    $result = $provider_class::validateConfiguration();
    $result['service'] = $service;
    return $result;
}
```

Replace `getServiceType()` similarly.

**3c. Remove extracted code**

- Remove `sendViaMailgun()` and `sendViaSMTP()` methods
- Remove `use Mailgun\Mailgun` import
- Remove provider-specific validation logic from `validateServiceConfiguration()`

**3d. Update `sendBatch()`**

The current `sendBatch()` in EmailSender loops and calls `send()` per recipient. Refactor it to use native batch APIs when available, with fallback for failed recipients:

```php
public function sendBatch(EmailMessage $message, array $recipients) {
    $settings = Globalvars::get_instance();
    $service = $settings->get_setting('email_service') ?: 'mailgun';
    $fallback = $settings->get_setting('email_fallback_service') ?: '';

    $provider = self::getProvider($service);
    if (!$provider) {
        $this->logEmailDebug("Unknown email service for batch: $service");
        return ['success' => false, 'failed_recipients' => $recipients];
    }

    // Try primary provider's native batch
    $result = $provider->sendBatch($message, $recipients);

    if ($result['success']) {
        return $result;
    }

    // If some recipients failed and there's a fallback, try those
    $failed = $result['failed_recipients'];
    if (!empty($failed) && $fallback && $fallback !== $service) {
        $fallback_provider = self::getProvider($fallback);
        if ($fallback_provider) {
            $this->logEmailDebug("Batch: " . count($failed) . " recipients failed via $service, trying $fallback");
            $fallback_result = $fallback_provider->sendBatch($message, $failed);

            if ($fallback_result['success']) {
                return ['success' => true, 'failed_recipients' => []];
            }

            // Queue whatever is still left
            $still_failed = $fallback_result['failed_recipients'];
            foreach ($still_failed as $email) {
                $individual = clone $message;
                $individual->to($email);
                $this->queueForRetry($individual);
            }
            return ['success' => false, 'failed_recipients' => $still_failed];
        }
    }

    // No fallback — queue all failures
    foreach ($failed as $email) {
        $individual = clone $message;
        $individual->to($email);
        $this->queueForRetry($individual);
    }
    return $result;
}
```

**Partial failure handling:** Providers that send in chunks (e.g., Mailgun's 500-recipient batches) must track which chunks succeeded. If chunk 1 succeeds but chunk 2 fails, only chunk 2's recipients appear in `failed_recipients`. This prevents double-sends when the fallback provider picks up the remainder.

**Return type change:** `sendBatch()` now returns `array` instead of the old per-recipient `$results` map. Any calling code using `sendBatch()` needs to be updated to handle the new format. Check for callers before implementing.

**3e. Add public helper for admin UI**

```php
/**
 * Return all discovered providers as ['key' => 'Label'] for dropdowns.
 */
public static function getAvailableServices(): array {
    $services = [];
    foreach (self::discoverProviders() as $key => $class) {
        $services[$key] = $class::getLabel();
    }
    return $services;
}

/**
 * Return settings fields for a specific provider.
 */
public static function getProviderSettings($key): array {
    $providers = self::discoverProviders();
    if (!isset($providers[$key])) return [];
    return $providers[$key]::getSettingsFields();
}
```

### Phase 4: Admin Settings Page

**4a. Dynamic service dropdown**

Replace the hardcoded `$service_optionvals` array:
```php
// Before: $service_optionvals = array('mailgun' => 'Mailgun', 'smtp' => 'SMTP');
// After:
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
$service_optionvals = EmailSender::getAvailableServices();
```

**4b. Dynamic provider settings sections**

Replace the hardcoded Mailgun and SMTP settings sections with a loop over providers:
```php
$providers = EmailSender::discoverProviders();
foreach ($providers as $key => $class) {
    // Render section header using $class::getLabel()
    // Render fields using $class::getSettingsFields()
    // Render validation panel using $class::validateApiConnection() if method exists
}
```

Each provider's settings section should be rendered in a consistent two-column layout (settings on left, API status on right) matching the current pattern.

**4c. Dynamic API validation**

Replace the inline Mailgun SDK validation code with calls to `$class::validateApiConnection()`. Providers that don't implement this optional method simply show "No API validation available."

### Phase 5: Inbound Webhook Abstraction (Optional / Future)

The inbound webhook (`ajax/mailgun_inbound_webhook.php`) is a separate concern from outbound sending. It only applies to sites using Mailgun for inbound email routing.

If a future provider also needs inbound handling:
- Create `includes/InboundWebhookHandler.php` interface with `validateSignature()` and `extractEmail()` methods
- Create provider-specific implementations
- Route inbound webhooks through a dispatcher

This is **not required** for the initial refactor — the Mailgun inbound webhook can remain as-is since inbound routing is a Mailgun-specific feature independent of which provider sends outbound email.

## Edge Cases

### Composer Autoload

`MailgunProvider` needs the Mailgun SDK via Composer. Currently, `SmtpMailer.php` loads composer autoload and `EmailSender` requires `SmtpMailer`. Once Mailgun logic moves to its own file, `MailgunProvider.php` must call `require_once(PathHelper::getComposerAutoloadPath())` itself before using the SDK. Providers that don't need Composer dependencies (like SmtpProvider, which gets PHPMailer via SmtpMailer.php) skip this.

### Settings Row Creation

Provider settings (e.g., `mailgun_api_key`, `smtp_host`) must exist as rows in `stg_settings` before they can be saved from the admin page. Existing providers already have their settings rows from prior migrations. New providers will need a migration to insert their settings rows, OR the admin page save logic should auto-create missing setting rows on save. The simpler approach: document that new providers should include a migration adding their settings rows. This matches the existing pattern and avoids adding auto-creation logic.

### validateApiConnection Return Format

Providers implementing the optional `validateApiConnection()` must return a consistent structure so the admin page can render results uniformly:

```php
return [
    'success' => bool,           // Overall pass/fail
    'label' => string,           // e.g., "API Key Valid" or "Connection Failed"
    'details' => [               // Key-value pairs to display
        'Domain' => 'mg.example.com',
        'Status' => 'active',
    ],
    'error' => string|null       // Error message if success is false
];
```

### sendBatch Return Type Change

The public `sendBatch()` method currently returns `array` keyed by recipient email → bool. The refactored version returns `['success' => bool, 'failed_recipients' => string[]]`. As of this writing, `sendBatch()` has no callers in the codebase, so the return type change is zero-risk. Verify this still holds true at implementation time with a grep for `sendBatch`.

## File Changes Summary

| File | Action |
|---|---|
| `tests/email/suites/ServiceTests.php` | **Modified (Phase 1)** — 5 new test methods + 4 helper methods |
| `includes/EmailServiceProvider.php` | **New** — interface definition |
| `includes/email_providers/MailgunProvider.php` | **New** — extracted from EmailSender |
| `includes/email_providers/SmtpProvider.php` | **New** — extracted from EmailSender |
| `includes/EmailSender.php` | **Modify** — replace switch statements with provider registry, remove provider-specific methods |
| `adm/admin_settings_email.php` | **Modify** — replace hardcoded provider UI with dynamic rendering |
| `docs/email_system.md` | **Update** — document provider interface and how to add new providers |

## Testing

### Existing Test Coverage

The email test suite at `/tests/email/` already covers:

- **SMTP and Mailgun sending** — `ServiceTests::testSMTPSending()` and `testMailgunSending()` do real sends via `EmailSender::send()`. These will validate that the extracted providers still send correctly.
- **Service config validation** — `ServiceTests::testMailgunConfiguration()` and `testSMTPConfiguration()` check settings exist. These will continue to work since they read settings directly.
- **Service detection** — `ServiceTests::testServiceDetection()` calls `EmailSender::validateService()` and checks primary/fallback values. This will validate that provider discovery returns the same results.
- **Template + send patterns** — `email_pattern_test.php` tests 11 real-world sending patterns via `sendTemplate()` and `send()`. These are end-to-end regression tests.

These existing tests need no modifications — they test the public API (`EmailSender::send()`, `validateService()`, `detectServiceType()`) which doesn't change.

### New Tests to Add

Add these to `tests/email/suites/ServiceTests.php`, following the existing pattern (each method returns `['passed' => bool, 'message' => string, 'details' => array]`). Register them in the `run()` method.

**`testProviderDiscovery()`**

Calls `EmailSender::getAvailableServices()` and validates:
- Returns an array with at least `'mailgun'` and `'smtp'` keys
- Each key maps to a non-empty label string
- Calling `EmailSender::getProviderSettings('mailgun')` returns a non-empty array of field definitions
- Calling `EmailSender::getProviderSettings('smtp')` returns a non-empty array of field definitions
- Calling `EmailSender::getProviderSettings('nonexistent')` returns an empty array

**`testServiceFallback()`**

Tests that the fallback mechanism works when the primary provider fails:
1. Read current `email_service` and `email_fallback_service` settings (to restore later)
2. Temporarily set `email_service` to a value that will fail validation (e.g., set `mailgun_api_key` to a bogus value if Mailgun is primary, or just set `email_service` to `'smtp'` and `email_fallback_service` to `'mailgun'` with known-good Mailgun config)
3. Actually: the simplest approach — temporarily swap primary and fallback so the current fallback becomes primary. Send an email. If it arrives, both providers work and the selection logic is intact. Then swap back.
4. The real fallback test: set `email_service` to `'mailgun'`, temporarily blank out `mailgun_api_key`, set `email_fallback_service` to `'smtp'`. Call `$sender->send($message)`. Verify it returns true (sent via SMTP fallback).
5. Restore all original settings.

Note: This test modifies settings temporarily. Use the `$originalSettings` / `restoreSettings()` pattern already in `EmailTestRunner` to ensure cleanup even on failure.

**`testBatchSending()`**

Tests `EmailSender::sendBatch()`:
1. Create an `EmailMessage` with a test subject
2. Call `$sender->sendBatch($message, [$testRecipient1, $testRecipient2])` with two copies of the test email address (or two different test addresses if available)
3. Verify result has `'success'` and `'failed_recipients'` keys
4. Verify `'success'` is true and `'failed_recipients'` is empty

**`testBatchFallback()`**

Tests that batch sending falls back on primary failure:
1. Temporarily configure primary to fail (bogus Mailgun API key) with valid SMTP fallback
2. Call `$sender->sendBatch($message, [$testRecipient])`
3. Verify result `'success'` is true (sent via fallback)
4. Restore settings

**`testQueueOnTotalFailure()`**

Tests that emails are queued when both providers fail:
1. Temporarily set both `email_service` and `email_fallback_service` to providers with invalid credentials (e.g., blank out both `mailgun_api_key` and `smtp_host`)
2. Record the current max `equ_id` in `equ_queued_emails`
3. Call `$sender->send($message)` — expect it to return false
4. Query `equ_queued_emails` for rows with `equ_id` greater than the recorded max
5. Verify at least one new row exists with the test recipient and subject
6. Clean up: delete the test row(s), restore settings

**`testServiceValidation()`**

Tests `validateServiceConfiguration()` via the provider interface:
1. Call `EmailSender::validateService('mailgun')` — verify it returns `['valid' => bool, 'errors' => array, 'service' => 'mailgun']`
2. Call `EmailSender::validateService('smtp')` — same structure check
3. Call `EmailSender::validateService('nonexistent_provider')` — verify `valid` is false and errors mention unknown service

### Update `ServiceTests::run()`

Register all new tests:

```php
public function run(): array {
    $results = [];
    
    // Existing tests
    $results['smtp_config'] = $this->testSMTPConfiguration();
    $results['smtp_connection'] = $this->testSMTPConnection();
    $results['smtp_sending'] = $this->testSMTPSending();
    $results['mailgun_config'] = $this->testMailgunConfiguration();
    $results['mailgun_sending'] = $this->testMailgunSending();
    $results['service_detection'] = $this->testServiceDetection();
    
    // New provider abstraction tests
    $results['provider_discovery'] = $this->testProviderDiscovery();
    $results['service_fallback'] = $this->testServiceFallback();
    $results['batch_sending'] = $this->testBatchSending();
    $results['batch_fallback'] = $this->testBatchFallback();
    $results['queue_on_failure'] = $this->testQueueOnTotalFailure();
    $results['service_validation'] = $this->testServiceValidation();
    
    return $results;
}
```

### Update `ServiceTests::testServiceDetection()`

The existing test hardcodes the valid service list:

```php
// Current (hardcoded):
$passed = in_array($currentService, ['smtp', 'mailgun']) && 
          in_array($fallbackService, ['smtp', 'mailgun']);

// Updated (uses provider discovery):
$available = EmailSender::getAvailableServices();
$passed = isset($available[$currentService]) && isset($available[$fallbackService]);
```

### Manual Verification

In addition to automated tests, manually verify via browser:
1. **Admin settings page** — all provider settings render correctly, validation works, service dropdown populates dynamically
2. **Email test runner** (`/tests/email/`) — run all tests, confirm all pass
3. **Email pattern test** (`/tests/email/email_pattern_test.php`) — run all 11 patterns, confirm sends work

## Adding a New Provider (Post-Refactor)

After this refactor, adding a new provider (e.g., SendGrid) requires exactly one step:

1. Create `includes/email_providers/SendGridProvider.php` implementing `EmailServiceProvider`

That's it. The provider appears in admin settings, its configuration fields render automatically, validation works, and it's selectable as primary or fallback service. No other files need to be touched.
