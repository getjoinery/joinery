# SendGrid Email Provider

## Goal

Add SendGrid as a selectable email service provider alongside Mailgun and SMTP. After this work, an admin can choose `SendGrid` from the email service / fallback dropdowns in `/adm/admin_settings_email.php`, enter an API key, and send mail through SendGrid's HTTP API — with native batch sending and live API validation, matching the behavior of `MailgunProvider`.

## Background

The email subsystem already abstracts providers behind `EmailServiceProvider` (see `includes/EmailServiceProvider.php`). Provider classes live in `includes/email_providers/` and are auto-discovered by `EmailSender::discoverProviders()` — no edits to `EmailSender.php`, `admin_settings_email.php`, or any switch statement are required to register a new provider. The existing implementations to model after are:

- `includes/email_providers/MailgunProvider.php` — HTTP-API provider with native batch (recipient-variables, chunks of 500) and `validateApiConnection()`
- `includes/email_providers/SmtpProvider.php` — non-batch provider that loops `send()` for batch

Per `specs/implemented/email_provider_abstraction.md` and `docs/email_system.md`, adding a provider should be a one-file change.

## Scope

**In scope**
- New file `includes/email_providers/SendGridProvider.php` implementing `EmailServiceProvider`
- New Composer dependency `sendgrid/sendgrid` (^8.1)
- Five new settings declared in `settings.json` (key, verified domain hint, region, sandbox-mode toggle, click-tracking toggle — see below)
- One new test method `testSendGridSending()` in `tests/email/suites/ServiceTests.php` (parallels `testMailgunSending()`)
- Update `docs/email_system.md` to list SendGrid in the provider table

**Out of scope**
- Inbound webhook handling (SendGrid Inbound Parse). The existing Mailgun inbound webhook stays as-is. If we later need SendGrid inbound, that's a separate spec.
- Mailing list / suppression sync (SendGrid's recipient/suppression API). Outbound transactional only.
- Migrating any existing site from Mailgun → SendGrid. This spec adds the option; switching is just a settings change.
- Modifying `EmailSender.php`, `admin_settings_email.php`, or any other core file. The abstraction guarantees we don't need to.

## Design

### Class skeleton — `includes/email_providers/SendGridProvider.php`

```php
<?php
/**
 * SendGridProvider - SendGrid email service provider
 *
 * Implements EmailServiceProvider using the SendGrid PHP SDK (v8.x) over the v3 HTTP API.
 * Supports batch sending via the personalizations[] array (up to 1000 per request).
 */

require_once(PathHelper::getComposerAutoloadPath());

class SendGridProvider implements EmailServiceProvider {
    public static function getKey(): string { return 'sendgrid'; }
    public static function getLabel(): string { return 'SendGrid'; }
    public static function getSettingsFields(): array { /* see Settings */ }
    public static function validateConfiguration(): array { /* see Validation */ }
    public static function validateApiConnection(): array { /* see Validation */ }
    public function send(EmailMessage $message): bool { /* see Sending */ }
    public function sendBatch(EmailMessage $message, array $recipients): array { /* see Batch */ }
}
```

### Settings

Five settings, declared in `settings.json` so they seed automatically (no migration needed):

| Setting key | Default | Field type | Purpose |
|---|---|---|---|
| `sendgrid_api_key` | `""` | `password` | Bearer token used in `Authorization: Bearer …` |
| `sendgrid_verified_domain` | `""` | `text` | Sender domain (display/help only — SendGrid validates the From at send time; this is shown in the admin validation details so the admin can confirm what was configured) |
| `sendgrid_region` | `"global"` | `dropdown` (`global`, `eu`) | Selects `api.sendgrid.com` vs `api.eu.sendgrid.com` (the SDK supports a custom host via `setHost()`) |
| `sendgrid_sandbox_mode` | `"0"` | `dropdown` (`0`=Off, `1`=On) | When On, SendGrid validates the request but does not deliver. Useful for staging/test sites. |
| `sendgrid_click_tracking` | `"0"` | `dropdown` (`0`=Off, `1`=On) | Whether to enable SendGrid's click tracking on the message. Default Off — click tracking rewrites every link through `*.sendgrid.net`, which can hurt deliverability for plain transactional mail and is rarely wanted by default. Admins can opt in. |

`getSettingsFields()` returns these in the same shape the admin page consumes (mirroring `MailgunProvider`):

```php
public static function getSettingsFields(): array {
    return [
        ['key' => 'sendgrid_api_key',          'label' => 'SendGrid API Key', 'type' => 'password'],
        ['key' => 'sendgrid_verified_domain',  'label' => 'Verified Sender Domain (Example: mail.example.com)', 'type' => 'text',
         'helptext' => 'For display only — SendGrid validates the From at send time. Must be a domain you have verified in SendGrid.'],
        ['key' => 'sendgrid_region', 'label' => 'Region', 'type' => 'dropdown',
         'options' => ['global' => 'Global (api.sendgrid.com)', 'eu' => 'EU (api.eu.sendgrid.com)']],
        ['key' => 'sendgrid_sandbox_mode', 'label' => 'Sandbox Mode (no real delivery)', 'type' => 'dropdown',
         'options' => [0 => 'Off', 1 => 'On']],
        ['key' => 'sendgrid_click_tracking', 'label' => 'Click Tracking (default Off)', 'type' => 'dropdown',
         'options' => [0 => 'Off', 1 => 'On']],
    ];
}
```

### Validation

`validateConfiguration()` — pure settings check, no network call:

```php
public static function validateConfiguration(): array {
    $settings = Globalvars::get_instance();
    $errors = [];
    if (empty($settings->get_setting('sendgrid_api_key'))) {
        $errors[] = 'SendGrid API key not configured';
    }
    return ['valid' => empty($errors), 'errors' => $errors];
}
```

`validateApiConnection()` — live HTTP check, used by the admin "Run Validation" panel. The cheapest authenticated endpoint that returns useful metadata is `GET /v3/user/account` (returns plan name/reputation). Implementation outline:

1. If `sendgrid_api_key` is empty → return `['success' => false, 'label' => 'Not Configured', 'details' => [], 'error' => 'Enter API key to validate connection']`
2. Instantiate `\SendGrid($api_key)`, call `setHost('https://api.eu.sendgrid.com')` if region is `eu`
3. Call `client->user()->account()->get()` (or equivalent low-cost endpoint)
4. On 200 → return `success: true`, `label: 'API Key Valid'`, `details: ['Region' => ..., 'Account Type' => $body->type ?? 'unknown', 'Reputation' => $body->reputation ?? 'n/a', 'Verified Domain' => $configured_domain]`
5. On 401 → `success: false`, `label: 'API Key Rejected'`, `error: 'Authentication failed (401). Check API key.'`
6. On any other exception → `success: false`, `label: 'API Connection Failed'`, `error: $e->getMessage()`

Return shape must match the one documented in `email_provider_abstraction.md` (`success`, `label`, `details`, `error`) so the admin page renders it uniformly.

### Sending

`send(EmailMessage $message)` — single message, possibly multiple recipients:

- Build a `\SendGrid\Mail\Mail` object
- `setFrom($message->getFrom(), $message->getFromName())`
- `setSubject($message->getSubject())`
- One personalization per recipient (so we get per-recipient delivery, mirrors what Mailgun's recipient-variables give us):
  ```php
  foreach ($message->getRecipients() as $r) {
      $personalization = new \SendGrid\Mail\Personalization();
      $personalization->addTo(new \SendGrid\Mail\To($r['email'], $r['name'] ?? null));
      $mail->addPersonalization($personalization);
  }
  ```
- CC/BCC: add to the first personalization (SendGrid applies CC/BCC per personalization, not globally)
- Body: prefer HTML if present, otherwise text. SendGrid accepts both:
  ```php
  if ($message->getHtmlBody()) { $mail->addContent('text/html', $message->getHtmlBody()); }
  if ($message->getTextBody()) { $mail->addContent('text/plain', $message->getTextBody()); }
  ```
- Reply-to, custom headers, attachments — map from `EmailMessage` getters (parallels what `SmtpProvider` does)
- If `sendgrid_sandbox_mode === '1'`, attach a `MailSettings` with `SandBoxMode` enabled
- If `sendgrid_click_tracking === '0'`, attach `TrackingSettings` with click tracking disabled
- Call `$sg->send($mail)`; success = HTTP status in 200–299
- On any exception or non-2xx, `error_log("[SendGridProvider] Send failed: " . …)` and return false

### Batch

SendGrid's native batch is "one HTTP request, many personalizations" — up to 1000 per request. The chunk-of-500 pattern from MailgunProvider translates directly; we just bump the chunk size to 1000.

```php
public function sendBatch(EmailMessage $message, array $recipients): array {
    $chunks = array_chunk($recipients, 1000);
    $failed = [];
    foreach ($chunks as $chunk) {
        $mail = $this->buildMailFromMessage($message); // private helper reused from send()
        foreach ($chunk as $email) {
            $p = new \SendGrid\Mail\Personalization();
            $p->addTo(new \SendGrid\Mail\To($email, $email));
            $mail->addPersonalization($p);
        }
        try {
            $resp = $sg->send($mail);
            if ($resp->statusCode() < 200 || $resp->statusCode() >= 300) {
                error_log("[SendGridProvider] Batch chunk failed: HTTP " . $resp->statusCode() . " " . $resp->body());
                $failed = array_merge($failed, $chunk);
            }
        } catch (\Exception $e) {
            error_log("[SendGridProvider] Batch chunk failed: " . $e->getMessage());
            $failed = array_merge($failed, $chunk);
        }
    }
    return ['success' => empty($failed), 'failed_recipients' => $failed];
}
```

`failed_recipients` must contain just the chunks that actually failed — never the whole batch when only one chunk errored. This is the same partial-failure contract MailgunProvider already satisfies, and is what makes `EmailSender::sendBatch()` fallback safe (it won't double-send to recipients that already received via the primary).

## Implementation Steps

1. **Add SDK to composer**
   - In `public_html/composer.json`, add `"sendgrid/sendgrid": "^8.1"` to `require`
   - Run `composer install` (vendor dir is `/home/user1/vendor/`)
   - Verify autoload finds `\SendGrid\Mail\Mail`

2. **Declare settings in `settings.json`**
   - Add the five `sendgrid_*` rows from the table above
   - These seed into `stg_settings` automatically — no migration

3. **Create `includes/email_providers/SendGridProvider.php`**
   - Follow the skeleton above
   - Set file perms `chmod 666` after creation (per CLAUDE.md)

4. **Run validators**
   - `php -l includes/email_providers/SendGridProvider.php`
   - `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php includes/email_providers/SendGridProvider.php`

5. **Update `docs/email_system.md`**
   - Add a row for SendGrid alongside Mailgun/SMTP in the provider table

6. **Add `testSendGridSending()` to `tests/email/suites/ServiceTests.php`**
   - Parallels `testMailgunSending()`: if `sendgrid_api_key` is configured, send a real test email; otherwise skip with `'message' => 'SendGrid not configured, skipping'`
   - No new helper methods needed — uses the existing test scaffolding

## Verification

After implementation:

1. **Provider discovery** — visit `/adm/admin_settings_email.php`. Confirm `SendGrid` appears in both the primary and fallback service dropdowns. Confirm the SendGrid settings section renders all five fields.
2. **Configuration validation** — leave API key blank, run validation → expect "Not Configured". Enter a bogus key → expect "API Key Rejected". Enter a real key → expect "API Key Valid" with account details populated.
3. **Single send** — set `email_service = sendgrid`, send a test email from `/tests/email/`, confirm receipt.
4. **Batch send** — run `testBatchSending()` against SendGrid as the active service. Confirm `success: true`.
5. **Fallback** — set primary to SendGrid with a bogus API key, fallback to a working Mailgun/SMTP. Send a test → confirm it arrives via the fallback (existing `testServiceFallback()` covers the mechanism; just point it at SendGrid temporarily).
6. **Sandbox mode** — flip `sendgrid_sandbox_mode = 1`, send a test → expect HTTP 200 from SendGrid but no actual delivery to the inbox.

## Edge Cases & Notes

- **Region mismatch.** If the account is provisioned in the EU but `sendgrid_region` is left at `global`, SendGrid returns 401-ish errors that look like auth failures. `validateApiConnection()` should mention "If your account is EU-region, set Region to EU" in the error string when the response looks like an auth failure but the key is non-empty.
- **From-domain verification.** Unlike Mailgun (which 404s an unknown domain at send time with a clear error), SendGrid silently rejects unverified senders post-send via the SendGrid activity log — no immediate API error. Documenting the `sendgrid_verified_domain` field in the admin UI is the user-facing mitigation; we don't try to enforce this in code.
- **Composer autoload at file top.** `MailgunProvider` requires `PathHelper::getComposerAutoloadPath()` at file top because the SDK is namespaced. `SendGridProvider` must do the same. `SmtpProvider` doesn't need it because PHPMailer is loaded transitively via `SmtpMailer.php`.
- **No changes to `EmailSender.php` or `admin_settings_email.php`.** If implementation requires touching either, that's a bug in the abstraction — fix the abstraction instead of adding a special case.
- **API-key handling.** The API key goes into `stg_settings.sendgrid_api_key`. Never log or echo the key value in `validateApiConnection()` details or anywhere else (per CLAUDE.md secret-handling rules) — show the configured-vs-not state, not the value.

## File Changes Summary

| File | Action |
|---|---|
| `composer.json` | Modify — add `sendgrid/sendgrid: ^8.1` |
| `settings.json` | Modify — add 5 `sendgrid_*` entries |
| `includes/email_providers/SendGridProvider.php` | New — implements `EmailServiceProvider` |
| `tests/email/suites/ServiceTests.php` | Modify — add `testSendGridSending()` |
| `docs/email_system.md` | Modify — add SendGrid row to provider table |
