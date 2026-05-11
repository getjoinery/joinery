# Brevo (formerly Sendinblue) Email Provider

## Goal

Add Brevo as a selectable email service provider. Implementation mirrors the SendGrid provider (see `specs/implemented/sendgrid_provider.md`).

## Background

Brevo (the rebrand of Sendinblue, mid-2023) is a France-headquartered transactional + marketing platform with strong European market share and a generous free tier. The transactional email API is straightforward: bearer-token auth, JSON in, JSON out.

SDK: `getbrevo/brevo-php` (^2.0). The older `sendinblue/api-v3-sdk` still works against the same API but the package is renamed under Brevo's GitHub org — use the new one.

## Scope

**In scope**
- New file `includes/email_providers/BrevoProvider.php`
- Composer add: `getbrevo/brevo-php: ^2.0`
- Three settings in `settings.json`
- `testBrevoSending()` in `tests/email/suites/ServiceTests.php`
- Brevo row in `docs/email_system.md` provider table

**Out of scope**
- Brevo contact-list / segmentation API (marketing-side, separate from transactional)
- Brevo Inbound parsing
- Brevo SMS API (different product)

## Design

### Settings

| Key | Default | Field type | Purpose |
|---|---|---|---|
| `brevo_api_key` | `""` | `password` | v3 API key from Brevo dashboard → SMTP & API → API Keys. Starts with `xkeysib-`. |
| `brevo_verified_domain` | `""` | `text` | Display-only. Brevo requires sender verification (per-address or per-domain). |
| `brevo_sandbox_mode` | `"0"` | `dropdown` (`0`/`1`) | Brevo supports a sandbox header (`X-Sib-Sandbox: drop`) — request accepted but not delivered. Useful for staging. |

Brevo doesn't have data-residency region selection like SendGrid/Mailgun. Single endpoint: `https://api.brevo.com/v3`.

### Sending

The SDK exposes a `TransactionalEmailsApi`:

```php
$config = \Brevo\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', $api_key);
$api = new \Brevo\Client\Api\TransactionalEmailsApi(new GuzzleHttp\Client(), $config);

$sendEmail = new \Brevo\Client\Model\SendSmtpEmail();
$sendEmail->setSender(['name' => $fromName, 'email' => $from]);
$sendEmail->setTo($toArray);   // array of ['email' => ..., 'name' => ...]
$sendEmail->setSubject($subject);
$sendEmail->setHtmlContent($html);
$sendEmail->setTextContent($text);
$sendEmail->setReplyTo(['email' => $replyTo]);
$sendEmail->setHeaders(['X-Sib-Sandbox' => 'drop']);  // only if sandbox mode on

$result = $api->sendTransacEmail($sendEmail);
// Returns CreateSmtpEmail with messageId on success; throws ApiException on failure
```

### Batch

Brevo's transactional API accepts up to **1000 recipients per call** via the `to` array, but those recipients see each other in the `To:` header (shared envelope). For separate envelopes per recipient, use the `messageVersions` array — each entry has its own `to` and (optionally) own subject/body. Max 1000 versions per request.

Use `messageVersions` for `sendBatch()` to keep recipients separated:

```php
$versions = [];
foreach ($chunk as $email) {
    $versions[] = ['to' => [['email' => $email]]];
}
$sendEmail->setMessageVersions($versions);
// $sendEmail->to must still be set to the first recipient (Brevo SDK quirk)
$sendEmail->setTo([['email' => $chunk[0]]]);
```

Chunk size: 1000. On `ApiException` for a chunk, the whole chunk goes into `failed_recipients` (no per-version failure indication from the API).

### validateApiConnection

`GET /v3/account` via `AccountApi::getAccount()`:

```php
$account_api = new \Brevo\Client\Api\AccountApi($http, $config);
$account = $account_api->getAccount();
// $account->getEmail(), $account->getCompanyName(),
// $account->getPlan() (array of {type, credits, creditsType, ...})
```

Details to display:
- Account Email (`$account->getEmail()`)
- Company Name
- Plan Type (look for the first plan entry's `type` and `credits`)
- Verified Domain (configured value, if set)

Errors:
- HTTP 401 → "Invalid API key (must be a v3 key starting with `xkeysib-`)"
- HTTP 403 → "API key lacks transactional-email scope"

## Edge Cases

- **Key format.** Brevo also issues SMTP relay credentials separately from v3 API keys. Admins sometimes paste the SMTP password — that won't work against the v3 API. The 401 error message should call out the `xkeysib-` prefix.
- **Daily limit.** Free-tier accounts have a hard 300/day limit. Beyond that, sends return HTTP 402. Surface the error but don't try to special-case the limit — admins manage their plan.
- **Sender verification.** Brevo silently moves unverified-sender sends to a "to review" state instead of delivering. Document this in the helptext on `brevo_verified_domain`.
- **Composer autoload.** Required at file top — namespaced SDK.

## Implementation Steps

1. `composer require getbrevo/brevo-php:^2.0`
2. Add 3 `brevo_*` rows to `settings.json`
3. Create `includes/email_providers/BrevoProvider.php`, `chmod 666`
4. `php -l` + `validate_php_file.php`
5. Smoke test via PHP CLI
6. Add `testBrevoSending()` paralleling `testSendGridSending()`
7. Update `docs/email_system.md` provider table

## File Changes Summary

| File | Action |
|---|---|
| `composer.json` | Modify — add `getbrevo/brevo-php: ^2.0` |
| `settings.json` | Modify — add 3 `brevo_*` entries |
| `includes/email_providers/BrevoProvider.php` | New |
| `tests/email/suites/ServiceTests.php` | Modify — add `testBrevoSending()` |
| `docs/email_system.md` | Modify — add Brevo row |
