# Amazon SES Email Provider

## Goal

Add Amazon SES (Simple Email Service) as a selectable email service provider. Implementation mirrors the SendGrid provider — single new class file in `includes/email_providers/`, settings declared in `settings.json`, no other core files touched.

## Background

The provider abstraction is documented in `specs/implemented/email_provider_abstraction.md` and `specs/implemented/sendgrid_provider.md`. SES is the highest-volume transactional sender in the market, and `aws/aws-sdk-php: ^3.300` is **already in `composer.json`** — zero new dependencies required.

Use the SESv2 API (`Aws\SesV2\SesV2Client`), not the legacy v1 (`Aws\Ses\SesClient`). SESv2 is the AWS-recommended path for new integrations and has clearer support for configuration sets, message tagging, and the `SendEmail` API shape.

## Scope

**In scope**
- New file `includes/email_providers/SesProvider.php` implementing `EmailServiceProvider`
- Five settings declared in `settings.json`
- One new test method `testSesSending()` in `tests/email/suites/ServiceTests.php`
- Update `docs/email_system.md` "Built-in Providers" table

**Out of scope**
- Inbound mail via SES (separate concern, separate webhook handler)
- Bounce/complaint handling via SNS topics (operational, not provider-class concern)
- SES Configuration Set management UI (admin creates the set in AWS, just pastes the name here)
- Moving an account out of SES sandbox — that's an AWS support request, not something we can automate

## Design

### Settings

| Key | Default | Field type | Purpose |
|---|---|---|---|
| `ses_access_key_id` | `""` | `text` | IAM access key. Empty string when using instance/role credentials (EC2/ECS/Lambda). |
| `ses_secret_access_key` | `""` | `password` | IAM secret key. Empty when using role credentials. |
| `ses_region` | `"us-east-1"` | `dropdown` | AWS region. Must match where the sending domain is verified. SES is region-scoped — a domain verified in `us-east-1` doesn't exist in `us-west-2`. |
| `ses_configuration_set` | `""` | `text` | Optional. Name of an SES Configuration Set for engagement tracking, custom event publishing, IP pool selection. Leave blank to send without one. |
| `ses_verified_domain` | `""` | `text` | Display-only — shown in admin validation panel. SES rejects unverified senders at send time. |

Region dropdown options (the ones with significant SES presence):
- `us-east-1` (N. Virginia)
- `us-east-2` (Ohio)
- `us-west-1` (N. California)
- `us-west-2` (Oregon)
- `eu-west-1` (Ireland)
- `eu-west-2` (London)
- `eu-central-1` (Frankfurt)
- `ap-southeast-1` (Singapore)
- `ap-southeast-2` (Sydney)
- `ap-northeast-1` (Tokyo)

### Credentials

Two modes, picked automatically:

1. **Static credentials** — `ses_access_key_id` + `ses_secret_access_key` both set. Pass them to `SesV2Client` via the `credentials` config key.
2. **Instance/role credentials** — both blank. The AWS SDK auto-discovers credentials via the standard provider chain (EC2 instance metadata, ECS task role, env vars, `~/.aws/credentials`). This is the right path for production deployments on AWS.

Both modes work without admin-page changes. Document the second mode in the helptext on `ses_access_key_id`.

### Sending

`send(EmailMessage)` uses `SesV2Client::sendEmail()`:

```php
$ses->sendEmail([
    'FromEmailAddress' => $message->getFromName()
        ? "{$message->getFromName()} <{$message->getFrom()}>"
        : $message->getFrom(),
    'Destination' => [
        'ToAddresses' => $to_addresses,   // array of "Name <email>" strings
        'CcAddresses' => $cc_addresses,
        'BccAddresses' => $bcc_addresses,
    ],
    'ReplyToAddresses' => $message->getReplyTo() ? [$message->getReplyTo()] : [],
    'Content' => [
        'Simple' => [
            'Subject' => ['Data' => $message->getSubject(), 'Charset' => 'UTF-8'],
            'Body' => [
                'Html' => $html ? ['Data' => $html, 'Charset' => 'UTF-8'] : null,
                'Text' => $text ? ['Data' => $text, 'Charset' => 'UTF-8'] : null,
            ],
        ],
    ],
    'ConfigurationSetName' => $config_set ?: null,  // omit if empty
]);
```

Success = no exception thrown (the SDK throws `AwsException` on non-2xx).

### Batch

**SES has no native multi-recipient batch API for non-templated mail.** The closest options:

1. **Per-recipient loop** with `SendEmail` — correct semantics (each recipient gets their own envelope, no cross-visibility) but expensive at scale.
2. **`SendBulkEmail` with a stored SES template** — requires creating and managing SES templates server-side. Out of scope; would couple us to SES's template system.

Use option 1. Implement `sendBatch()` as a loop over `SendEmail` with one recipient per call. This matches `SmtpProvider`'s `sendBatch()` shape:

```php
public function sendBatch(EmailMessage $message, array $recipients): array {
    $failed = [];
    foreach ($recipients as $email) {
        // Build a single-recipient EmailMessage and call $this->send()
        // (same pattern as SmtpProvider::sendBatch — see lines 204-249)
        if (!$this->send($per_recipient_message)) {
            $failed[] = $email;
        }
    }
    return ['success' => empty($failed), 'failed_recipients' => $failed];
}
```

Note in the spec docs: SES batch is per-recipient, so it's slower than Mailgun/SendGrid/Brevo for large lists. For sites doing high-volume bulk, recommend Mailgun or SendGrid as the primary and SES as fallback.

### validateApiConnection

Hit `GetAccount` — returns the account's sending enablement and quota:

```php
$result = $ses->getAccount();
// Returns: DedicatedIpAutoWarmupEnabled, EnforcementStatus, ProductionAccessEnabled,
//          SendingEnabled, SendQuota { Max24HourSend, MaxSendRate, SentLast24Hours }
```

Render in details panel:
- Region (from config)
- Production Access: `Yes` / `No (Sandbox)` based on `ProductionAccessEnabled`
- Sending Enabled: `Yes` / `No` based on `SendingEnabled`
- 24h Quota: `{SentLast24Hours} / {Max24HourSend}`
- Max Send Rate: `{MaxSendRate}/sec`
- Verified Domain (from config, if set)

Error handling:
- `InvalidClientTokenId` / `SignatureDoesNotMatch` → "Invalid AWS credentials"
- `AccessDenied` → "IAM permissions insufficient — need `ses:GetAccount` and `ses:SendEmail`"
- `Endpoint*` errors → "Region not reachable — check `ses_region`"

If `ses_access_key_id` is blank, surface "Using instance/role credentials" in the details panel rather than treating it as "not configured."

## Edge Cases

- **Sandbox mode.** New SES accounts can only send to verified addresses until AWS grants production access (a support ticket). The `validateApiConnection()` should surface this state clearly — admins waiting on AWS need to know.
- **Region mismatch.** Sending domain verified in `us-east-1` but `ses_region` set to `eu-west-1` returns `MessageRejected: Email address is not verified`. The error message from SES is clear; just don't swallow it.
- **Configuration set not found.** Setting `ses_configuration_set` to a name that doesn't exist returns `ConfigurationSetDoesNotExist`. Surface that error verbatim.
- **`From` header format.** SES `FromEmailAddress` must be RFC-5322 — `"Name <email@x>"` or just `email@x`. Don't double-quote names with commas; pass them through as-is and let the SDK handle escaping.
- **No autoload `require` needed.** Unlike SendGrid, the AWS SDK is loaded transitively elsewhere (StripeHelper, others use Composer autoload too). But to be safe, `SesProvider.php` should still `require_once(PathHelper::getComposerAutoloadPath())` at file top — matches the MailgunProvider/SendGridProvider pattern.

## Implementation Steps

1. Verify `aws/aws-sdk-php` is installed: `php -r 'require "../vendor/autoload.php"; echo class_exists("Aws\\SesV2\\SesV2Client") ? "OK" : "MISSING";'` from `public_html/`. Should print `OK`.
2. Add 5 `ses_*` rows to `settings.json` (alphabetically near other email settings).
3. Create `includes/email_providers/SesProvider.php`. `chmod 666` after creation.
4. Validate: `php -l` + `validate_php_file.php`.
5. Smoke test via PHP CLI that the class loads and `getKey()`/`getLabel()`/`getSettingsFields()` return correctly, and `EmailSender::getAvailableServices()` includes `ses`.
6. Add `testSesSending()` to `tests/email/suites/ServiceTests.php` mirroring `testSendGridSending()`: skip if `ses_access_key_id` is blank AND no instance role is detectable; otherwise temporarily flip `email_service` to `ses`, send, restore.
7. Update `docs/email_system.md` "Built-in Providers" table with a SES row.

## File Changes Summary

| File | Action |
|---|---|
| `settings.json` | Modify — add 5 `ses_*` entries |
| `includes/email_providers/SesProvider.php` | New |
| `tests/email/suites/ServiceTests.php` | Modify — add `testSesSending()` |
| `docs/email_system.md` | Modify — add SES row |
