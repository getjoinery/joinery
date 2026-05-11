# Postmark Email Provider

## Goal

Add Postmark as a selectable email service provider. Implementation mirrors the SendGrid provider (see `specs/implemented/sendgrid_provider.md`) — single new class file in `includes/email_providers/`, settings declared in `settings.json`, no other core files touched.

## Background

Postmark is known for highest-tier transactional deliverability and clean separation between **transactional** and **broadcast** (marketing) sending. Each Postmark "Server" gets its own API token, and each Server has one or more **Message Streams** that classify outbound mail. Sending transactional mail on a broadcast stream (or vice-versa) is the most common Postmark misconfiguration.

SDK: `wildbit/postmark-php` (^4.0). Not currently in composer.

## Scope

**In scope**
- New file `includes/email_providers/PostmarkProvider.php`
- Composer add: `wildbit/postmark-php: ^4.0`
- Five settings in `settings.json`
- `testPostmarkSending()` in `tests/email/suites/ServiceTests.php`
- Postmark row in `docs/email_system.md` provider table

**Out of scope**
- Postmark inbound (separate webhook handler if ever needed)
- Suppressions API sync, server/template management

## Design

### Settings

| Key | Default | Field type | Purpose |
|---|---|---|---|
| `postmark_server_token` | `""` | `password` | Server-level API token. **Not** the account token — Postmark distinguishes them. |
| `postmark_message_stream` | `"outbound"` | `text` | Stream ID. Postmark's default transactional stream is named `outbound`. Broadcast streams have admin-chosen names. |
| `postmark_track_opens` | `"0"` | `dropdown` (`0`/`1`) | Per-message open tracking. Off by default — opt-in via setting. |
| `postmark_track_links` | `"None"` | `dropdown` | One of `None`, `HtmlAndText`, `HtmlOnly`, `TextOnly`. Default `None` matches "no link rewriting." |
| `postmark_verified_domain` | `""` | `text` | Display-only — Postmark validates From at send time. |

### Sending

```php
$client = new \Postmark\PostmarkClient($server_token);
$client->sendEmail(
    $from,                  // "Name <email>"
    implode(',', $to),      // comma-separated
    $subject,
    $htmlBody,
    $textBody,
    null,                   // tag
    true,                   // trackOpens (override per-message from settings)
    $replyTo,
    $ccList,
    $bccList,
    $headers,               // assoc array
    $attachments,           // [['Name' => ..., 'Content' => base64, 'ContentType' => ...]]
    $trackLinks,            // None/HtmlAndText/HtmlOnly/TextOnly
    null,                   // metadata
    $messageStream
);
```

Success = no exception. The SDK throws `PostmarkException` with a `postmarkApiErrorCode` on failure.

### Batch

Postmark has **native batch**: `PostmarkClient::sendEmailBatch()` — up to **500 messages per call**, each a distinct envelope (recipients don't see each other). This is the right primitive for `sendBatch()`:

```php
$batch = [];
foreach ($chunk as $email) {
    $batch[] = [
        'From' => $from,
        'To' => $email,
        'Subject' => $subject,
        'HtmlBody' => $html,
        'TextBody' => $text,
        'MessageStream' => $message_stream,
        // ... TrackOpens, TrackLinks, etc.
    ];
}
$responses = $client->sendEmailBatch($batch);
// Each response has ['ErrorCode' => 0, 'Message' => 'OK', 'To' => '...']
// Non-zero ErrorCode = that recipient failed
```

Walk `$responses`, collect any with non-zero `ErrorCode` into `failed_recipients`. This gives per-recipient failure tracking — better than Mailgun's all-or-nothing chunk failure.

### validateApiConnection

`PostmarkClient::getServer()` — returns the Server's name, color, settings:

```php
$server = $client->getServer();
// $server->name, $server->color, $server->smtpApiActivated,
//   $server->deliveryHookUrl, $server->bounceHookUrl, etc.
```

Details to display:
- Server Name (`$server->name`)
- Stream (configured value)
- Open Tracking (configured value)
- Link Tracking (configured value)
- Verified Domain (configured value, if set)

Error handling:
- HTTP 401 / `postmarkApiErrorCode === 10` → "Invalid Server Token (must be a Server token, not Account token)"
- HTTP 422 → "Postmark API error: " + message

## Edge Cases

- **Server token vs Account token.** Postmark has two kinds of tokens. Server tokens (per-Server) are what `sendEmail` requires. Account tokens are for account-level operations. Admins often paste the wrong one. The 401 error message should specifically call out this distinction.
- **Stream type mismatch.** Postmark rejects with `422 ErrorCode 1100` when sending on the wrong stream type ("This server doesn't have an outbound message stream named X"). Surface verbatim.
- **From address must be on a Sender Signature.** Postmark requires explicit per-address signature verification (or domain verification). The `postmark_verified_domain` setting is purely informational since Postmark validates server-side at send.
- **Composer autoload.** `PostmarkClient` is namespaced — file must `require_once(PathHelper::getComposerAutoloadPath())` at top.

## Implementation Steps

1. `composer require wildbit/postmark-php:^4.0`
2. Add 5 `postmark_*` rows to `settings.json`
3. Create `includes/email_providers/PostmarkProvider.php`, `chmod 666`
4. `php -l` + `validate_php_file.php`
5. PHP CLI smoke test: confirm class is discovered by `EmailSender::getAvailableServices()`
6. Add `testPostmarkSending()` paralleling `testSendGridSending()`
7. Update `docs/email_system.md` provider table

## File Changes Summary

| File | Action |
|---|---|
| `composer.json` | Modify — add `wildbit/postmark-php: ^4.0` |
| `settings.json` | Modify — add 5 `postmark_*` entries |
| `includes/email_providers/PostmarkProvider.php` | New |
| `tests/email/suites/ServiceTests.php` | Modify — add `testPostmarkSending()` |
| `docs/email_system.md` | Modify — add Postmark row |
