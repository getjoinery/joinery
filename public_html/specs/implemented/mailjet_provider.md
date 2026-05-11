# Mailjet Email Provider

## Goal

Add Mailjet as a selectable email service provider. Implementation mirrors the SendGrid provider (see `specs/implemented/sendgrid_provider.md`).

## Background

Mailjet is a France-based (now Sinch-owned) transactional + marketing provider with strong European presence and GDPR-aligned defaults. Auth uses a **two-part credential** (API key + API secret) — unlike SendGrid/Brevo/Resend which use a single bearer token, more like AWS SES.

SDK: `mailjet/mailjet-apiv3-php` (^1.5). The SDK is a thin v3 wrapper.

## Scope

**In scope**
- New file `includes/email_providers/MailjetProvider.php`
- Composer add: `mailjet/mailjet-apiv3-php: ^1.5`
- Four settings in `settings.json`
- `testMailjetSending()` in `tests/email/suites/ServiceTests.php`
- Mailjet row in `docs/email_system.md` provider table

**Out of scope**
- Mailjet Contact / Contactslist API (marketing-side)
- Mailjet template management
- SMS (separate product)

## Design

### Settings

| Key | Default | Field type | Purpose |
|---|---|---|---|
| `mailjet_api_key` | `""` | `text` | Public part of the credential pair. Visible in Mailjet dashboard → Account → API Keys. |
| `mailjet_api_secret` | `""` | `password` | Secret part of the credential pair. Hidden in Mailjet's UI after first issue. |
| `mailjet_sandbox_mode` | `"0"` | `dropdown` (`0`/`1`) | Mailjet's `SandboxMode` flag — request validated and accepted but not delivered. |
| `mailjet_verified_domain` | `""` | `text` | Display-only. |

No region selection — Mailjet has one production endpoint (`api.mailjet.com`).

### Sending

Mailjet's v3.1 API (the "Send API v3.1") uses a `Messages` array, each entry a separate envelope:

```php
$mj = new \Mailjet\Client($api_key, $api_secret, true, ['version' => 'v3.1']);
$body = [
    'Messages' => [
        [
            'From' => ['Email' => $from, 'Name' => $fromName],
            'To' => array_map(fn($r) => ['Email' => $r['email'], 'Name' => $r['name']], $recipients),
            'Cc' => $ccArray,
            'Bcc' => $bccArray,
            'Subject' => $subject,
            'HTMLPart' => $html,
            'TextPart' => $text,
            'ReplyTo' => ['Email' => $replyTo],
            'Headers' => $headers,
            'Attachments' => [
                ['ContentType' => 'application/pdf', 'Filename' => 'foo.pdf', 'Base64Content' => base64_encode($bytes)],
            ],
        ],
    ],
    'SandboxMode' => $sandbox_mode === '1',  // top-level, applies to all messages
];
$response = $mj->post(\Mailjet\Resources::$Email, ['body' => $body]);
$response->success(); // boolean
```

### Batch

Mailjet's v3.1 `Send` accepts up to **50 messages per call**, each its own envelope. Use this for `sendBatch()`:

```php
$messages = [];
foreach ($chunk as $email) {
    $messages[] = [
        'From' => ['Email' => $from, 'Name' => $fromName],
        'To' => [['Email' => $email]],
        'Subject' => $subject,
        'HTMLPart' => $html,
        'TextPart' => $text,
    ];
}
$body = ['Messages' => $messages, 'SandboxMode' => $sandbox];
$response = $mj->post(\Mailjet\Resources::$Email, ['body' => $body]);
```

Chunk size: 50. Mailjet returns a per-message result array in the response body. Walk `$response->getData()['Messages']`, collect any entries with `Status !== 'success'` into `failed_recipients`. This gives per-recipient failure tracking.

### validateApiConnection

`GET /v3/REST/myprofile` — returns the account profile:

```php
$response = $mj->get(\Mailjet\Resources::$Myprofile);
if ($response->success()) {
    $profile = $response->getData()[0] ?? null;
    // $profile has: Email, ContactPhone, CompanyName, Country, etc.
}
```

Details to display:
- Account Email
- Company Name
- Country
- Verified Domain (configured value, if set)

Errors:
- HTTP 401 → "Invalid API key + secret combination"
- HTTP 403 → "Credentials valid but lack required scope"

## Edge Cases

- **Two-part credential.** The most common misconfiguration is pasting both halves into one field. Layout `mailjet_api_key` and `mailjet_api_secret` adjacent in the settings panel, with clear labels. The 401 message should remind admins both halves are required.
- **Per-message status in batch response.** Unlike Resend/Brevo/Mailgun (where a failed chunk fails as a unit), Mailjet returns 200 OK with per-message `Status: error` entries on partial failure. Treat per-message status as authoritative for `failed_recipients`.
- **v3 vs v3.1 API.** Use v3.1 (`'version' => 'v3.1'`) — the older v3 has a different request shape and is deprecated for new code.
- **Composer autoload.** Required at file top.

## Implementation Steps

1. `composer require mailjet/mailjet-apiv3-php:^1.5`
2. Add 4 `mailjet_*` rows to `settings.json`
3. Create `includes/email_providers/MailjetProvider.php`, `chmod 666`
4. `php -l` + `validate_php_file.php`
5. Smoke test via PHP CLI
6. Add `testMailjetSending()` paralleling `testSendGridSending()`
7. Update `docs/email_system.md` provider table

## File Changes Summary

| File | Action |
|---|---|
| `composer.json` | Modify — add `mailjet/mailjet-apiv3-php: ^1.5` |
| `settings.json` | Modify — add 4 `mailjet_*` entries |
| `includes/email_providers/MailjetProvider.php` | New |
| `tests/email/suites/ServiceTests.php` | Modify — add `testMailjetSending()` |
| `docs/email_system.md` | Modify — add Mailjet row |
