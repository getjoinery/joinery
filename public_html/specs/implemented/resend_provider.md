# Resend Email Provider

## Goal

Add Resend as a selectable email service provider. Implementation mirrors the SendGrid provider (see `specs/implemented/sendgrid_provider.md`).

## Background

Resend (resend.com, 2023+) is the youngest transactional provider on this list but has rapidly become the dev-favorite for new projects — modern API, clean SDK, React-Email integration. It's the simplest of all the providers we're adding: one bearer token, one endpoint, no region selection.

SDK: `resend/resend-php` (^0.10+). Maintained by Resend directly.

## Scope

**In scope**
- New file `includes/email_providers/ResendProvider.php`
- Composer add: `resend/resend-php: ^0.10`
- Two settings in `settings.json`
- `testResendSending()` in `tests/email/suites/ServiceTests.php`
- Resend row in `docs/email_system.md` provider table

**Out of scope**
- Resend's React Email template rendering (we have our own template system)
- Resend Audiences API (mailing-list management — a separate `MailingListProvider` interface concern, not transactional)
- Resend domain management API (admins set up domains via Resend's web UI)

## Design

### Settings

| Key | Default | Field type | Purpose |
|---|---|---|---|
| `resend_api_key` | `""` | `password` | API key from resend.com → API Keys. Starts with `re_`. |
| `resend_verified_domain` | `""` | `text` | Display-only. Resend requires DNS-verified sender domains. |

That's it. No region (single global endpoint), no sandbox mode (Resend uses verified test addresses instead), no per-message tracking toggles (configured at domain level in Resend's dashboard).

### Sending

```php
$resend = Resend::client($api_key);
$resend->emails->send([
    'from' => $fromName ? "{$fromName} <{$from}>" : $from,
    'to' => $toArray,           // array of "Name <email>" or just "email"
    'cc' => $ccArray,
    'bcc' => $bccArray,
    'subject' => $subject,
    'html' => $html,
    'text' => $text,
    'reply_to' => $replyTo,
    'headers' => $headers,      // assoc array
    'attachments' => [
        ['filename' => 'foo.pdf', 'content' => base64_encode($bytes)],
    ],
]);
// Returns object with id; throws \Resend\Exceptions\ErrorException on failure
```

### Batch

Resend has a native batch endpoint: `$resend->batch->send([...])` — accepts an **array of up to 100 email objects per call**, each a fully independent envelope:

```php
$payload = [];
foreach ($chunk as $email) {
    $payload[] = [
        'from' => $from,
        'to' => [$email],
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
$resend->batch->send($payload);
```

Chunk size: 100. Like Mailgun's chunk-failure model — if a batch call throws, the whole chunk goes into `failed_recipients`.

### validateApiConnection

Resend doesn't expose a simple "account info" endpoint. Cheapest authenticated call: `$resend->apiKeys->list()` — returns the list of API keys for the account (HTTP 200 with at least your own key, or 401 if invalid).

```php
$keys = $resend->apiKeys->list();
$count = count($keys->data ?? []);
```

Details to display:
- API Keys on Account: `$count` (don't print the keys themselves)
- Verified Domain (configured value, if set)

Errors:
- HTTP 401 / `\Resend\Exceptions\ErrorException` with `validation_error` → "Invalid API key (must start with `re_`)"
- HTTP 403 → "API key lacks `api_keys:read` scope — generate a full-access key, or skip live validation"

Alternative if `apiKeys` ever requires elevated scope: `$resend->domains->list()` — same shape, surfaces the verified domains.

## Edge Cases

- **API key scope.** Resend supports restricted API keys (sending-only). A sending-only key will 403 on `apiKeys->list()`. If the live validation hits 403 but `validateConfiguration()` reports `valid: true`, render "Cannot validate — key may be sending-only" rather than red-failing.
- **From-domain verification.** Resend rejects unverified sender domains at send time with a clear error. No special handling needed.
- **Newer SDK.** Resend's SDK is at 0.x — minor versions can introduce breaking changes. Pin to a tested version when implementing; check the actual SDK at vendor time before finalizing the method calls in this spec.
- **Composer autoload.** Required at file top — namespaced SDK.

## Implementation Steps

1. `composer require resend/resend-php:^0.10` — bump constraint if a newer stable line exists at implementation time.
2. Add 2 `resend_*` rows to `settings.json`
3. Create `includes/email_providers/ResendProvider.php`, `chmod 666`
4. `php -l` + `validate_php_file.php`
5. Smoke test via PHP CLI
6. Add `testResendSending()` paralleling `testSendGridSending()`
7. Update `docs/email_system.md` provider table

## File Changes Summary

| File | Action |
|---|---|
| `composer.json` | Modify — add `resend/resend-php: ^0.10` |
| `settings.json` | Modify — add 2 `resend_*` entries |
| `includes/email_providers/ResendProvider.php` | New |
| `tests/email/suites/ServiceTests.php` | Modify — add `testResendSending()` |
| `docs/email_system.md` | Modify — add Resend row |
