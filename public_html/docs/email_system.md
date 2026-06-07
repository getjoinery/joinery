# Email System Documentation

## Overview

The email system consists of three focused classes that provide clear separation of concerns:

- **EmailMessage**: Fluent API for composing email messages
- **EmailTemplate**: Template processing (conditionals, variables)
- **EmailSender**: All sending logic with service selection and fallback

**Inbound email** is handled by the Inbound Email plugin — see [Inbound Email Plugin](/plugins/inbound_email/docs/overview.md) for setup, admin usage, and server configuration. Its guided **Setup** tab verifies MX, SPF, DKIM, and forward-confirmed reverse DNS (PTR) for each inbound domain, using `DnsResolver` (including `DnsResolver::getPtr()` for reverse lookups). Locally-stored mail is read through a Gmail-style **Mailbox Reader** with a **grant-based mailbox model** — each address is its own mailbox, shareable among several staff users, with read/star state shared per mailbox; see [Mailbox Reader](/plugins/inbound_email/docs/overview.md#mailbox-reader).

**Inbound transports.** Mail can arrive three ways: a self-hosted **Postfix** MX→pipe, a **webhook** provider (Mailgun), or by **IMAP poll** of an existing mailbox (Gmail, Microsoft 365, Yahoo, iCloud, Fastmail, any IMAP host). The first two are *push*; IMAP is *pull* — a scheduled task polls the mailbox and ingests new mail. Combined with the generic **SMTP** outbound provider, IMAP-in gives a complete **bring-your-own-mailbox** pairing (SMTP out + IMAP in on the same account) for low-volume users with no self-hosted MX. See [Receiving by IMAP poll](/plugins/inbound_email/docs/overview.md#receiving-by-imap-poll).

## Architecture

### EmailMessage Class

A clean, fluent API for email composition:

```php
// Create from template
$message = EmailMessage::fromTemplate('activation_content', [
    'act_code' => 'ABC123',
    'resend' => false,
    'recipient' => $user->export_as_array()
]);
$message->from('admin@example.com', 'Admin')
        ->to('user@example.com', 'John Doe')
        ->subject('Activate Your Account');

// Create manually
$message = EmailMessage::create('user@example.com', 'Subject', 'Body content')
                       ->from('admin@example.com');
```

**Key Methods:**
- `fromTemplate($name, $values)` - Create from database template
- `create($to, $subject, $body)` - Create simple message
- `from($email, $name)` - Set sender
- `to($email, $name)` - Add recipient
- `cc($email, $name)` - Add CC recipient
- `bcc($email, $name)` - Add BCC recipient
- `subject($subject)` - Set subject
- `html($content)` - Set HTML body
- `text($content)` - Set plain text body
- `attachment($path, $name)` - Add attachment
- `header($name, $value)` - Add custom header

### EmailSender Class

Handles all sending operations with service selection:

```php
// Send a message
$sender = new EmailSender();
$result = $sender->send($message);

// Quick send (uses default template if HTML detected)
$result = EmailSender::quickSend(
    'user@example.com', 
    'Subject', 
    '<p>HTML content</p>'
);

// Send from template
$result = EmailSender::sendTemplate(
    'welcome_email', 
    'user@example.com',
    ['name' => 'John', 'recipient' => $user->export_as_array()]
);

// Batch send (uses provider's native batch API when available)
$recipients = ['user1@example.com', 'user2@example.com'];
$result = $sender->sendBatch($message, $recipients);
// Returns: ['success' => bool, 'failed_recipients' => string[]]
```

**Service Selection:**
- Primary service: `email_service` setting (mailgun/smtp)
- Fallback service: `email_fallback_service` setting
- Automatic fallback if primary fails
- Queue failed emails for retry

### EmailTemplate Class

Focused on template processing:

```php
// Direct template processing (rarely needed - use EmailMessage instead)
$template = new EmailTemplate('activation_content');
$template->fill_template([
    'act_code' => 'ABC123',
    'resend' => false,
    'recipient' => $user->export_as_array()
]);

// Get processed content
$subject = $template->getSubject();
$html = $template->getHtml();
$text = $template->getText();
```

## Development Patterns

### Recommended Approach

```php
// For new code - use EmailMessage + EmailSender
$message = EmailMessage::fromTemplate('welcome_email', [
    'user_name' => $user->get('usr_name'),
    'activation_code' => $code,
    'recipient' => $user->export_as_array()
]);

$message->from('noreply@example.com', 'Example Site')
        ->to($user->get('usr_email'), $user->get('usr_name'));

$sender = new EmailSender();
$success = $sender->send($message);
```

### Quick Send for Simple Cases

```php
// For simple emails
$success = EmailSender::quickSend(
    $user->get('usr_email'),
    'Welcome to our site!',
    '<h1>Welcome!</h1><p>Thanks for joining us.</p>'
);
```

### Template-based Sending

```php
// When you just need to send a template
$success = EmailSender::sendTemplate(
    'password_reset',
    $user->get('usr_email'),
    [
        'reset_link' => $reset_url,
        'user_name' => $user->get('usr_name'),
        'recipient' => $user->export_as_array()
    ]
);
```

## Template System

### Template Processing

Templates support full conditional and variable processing:

**Template Structure:**
```
subject:Welcome to *company_name*, *recipient->usr_first_name*!

{~resend}
<h1>Welcome!</h1>
<p>Thanks for signing up on *company_name*! Please click this link to verify:</p>
{end}

{resend}
<p>Please click the following link to verify your email address:</p>
{end}

<p><a href="*web_dir*/activate?code=*act_code*">Activate Account</a></p>
```

### Variable Syntax

- **Variables**: `*variable_name*`
- **Object access**: `*recipient->usr_first_name*`  
- **Pipe qualifiers**: `*date|Y-m-d*`
- **UTM tracking**: `*email_vars*`

### Conditional Syntax

**Basic conditionals:**
```
{variable_name}
Content if variable is truthy
{end}

{~variable_name}
Content if variable is falsy (NOT)
{end}
```

**Complex conditionals:**
```
{recipient->usr_level >= 5}
<p>Admin content</p>
{end}

{template_name == "welcome"}
<p>Welcome-specific content</p>
{end}
```

**Variable operations:**
```
{condition}
[counter=1]
[email_type="notification"]  
Content here
{end}
```

### Iteration Syntax

Loop over an array with `{loop array_path as item_name} ... {end}`:

```
{loop line_items as line}
- *line->product_name* x*line->quantity*
{end}
```

The `array_path` follows the same dot/arrow resolution as variables (e.g.
`order->items` reaches `$values['order']['items']`). Inside the loop body
the loop variable is in scope as a regular value: `*item_name*`,
`*item_name->property*`, and conditionals like `{item_name->is_gift}` all
work.

**Nesting:** loops nest with each other and with conditionals in any order.
Each iteration runs the full `loops -> conditionals -> variables` pipeline
on its body, so an inner loop sees the outer loop's iteration variable,
and a conditional inside a loop sees the loop variable.

```
{loop groups as group}
*group->name*:
{loop group->members as m}
- *m->name* {m->is_admin}(admin){end}
{end}
{end}
```

**Edge cases (lenient):** missing keys, non-array values, and empty arrays
all render the loop body zero times with no error.

**Caveats**

- `_expand_loops` runs *before* conditionals, so a loop cannot reference
  a variable set inside a `[var="..."]` operation block — by the time
  conditionals execute, the loop has already expanded.
- The `{loop ... }` directive must not contain `}` inside it.
- Templates without any `{loop ` marker bypass the loop pre-pass entirely;
  rendering behaviour is unchanged from pre-2026 templates.

### Subject Processing

Three ways to set subject (priority order):

1. **Direct assignment** (highest priority):
   ```php
   $message->subject('Custom Subject');
   ```

2. **Template subject line**:
   ```
   subject:Welcome to *company_name*!
   <p>Email body...</p>
   ```

3. **Template variable**:
   ```
   subject:*subject*
   <p>Email body...</p>
   ```

## Service Configuration

### Email Services

**Mailgun Configuration:**
```php
// Settings
mailgun_api_key = "key-abc123..."
mailgun_domain = "mg.example.com"
mailgun_eu_api_link = "https://api.eu.mailgun.net"  // EU endpoint (optional)
```

**SMTP Configuration:**
```php
// Settings  
smtp_host = "smtp.example.com"
smtp_port = 587
smtp_username = "user@example.com"
smtp_password = "password"
smtp_encryption = "tls"  // or "ssl"
```

**Service Selection:**
```php
// Primary service
email_service = "mailgun"  // or "smtp"

// Fallback service  
email_fallback_service = "smtp"  // or "mailgun"

// Default template for HTML emails
default_email_template = "default_outer_template"
```

### Debug and Testing

**Debug Mode:**
```php
email_debug_mode = "1"  // Enable debug logging to debug_email_logs table
```

**Test Mode:**
```php
email_test_mode = "1"         // Redirect all emails to test address
email_test_redirect = "test@example.com"
```

## Testing and Debugging

### Email Testing System

**Web Interface:**
- URL: `/tests/email/`
- Admin link: Admin Panel → Email Tools → Email System Testing

**Test Types:**
- **ServiceTests**: SMTP/Mailgun configuration validation
- **TemplateTests**: Template processing and variable replacement
- **DeliveryTests**: End-to-end sending simulation (test mode)

### Debug Tools

**Debug Logging:**
```php
// Enable in settings
email_debug_mode = "1"

// View logs
SELECT * FROM debug_email_logs ORDER BY del_timestamp DESC;
```

**Service Validation:**
```php
// Check service configuration
$validation = EmailSender::validateService('mailgun');
if (!$validation['valid']) {
    foreach ($validation['errors'] as $error) {
        echo "Error: $error\n";
    }
}
```

**Template Testing:**
```php
// Test template without sending
$message = EmailMessage::fromTemplate('test_template', [
    'variable' => 'value',
    'recipient' => $user->export_as_array()
]);

echo "Subject: " . $message->getSubject() . "\n";
echo "HTML Length: " . strlen($message->getHtmlBody()) . "\n";
echo "Ready to send: " . ($message->getSubject() ? 'Yes' : 'No') . "\n";
```

## Advanced Features

### Service Fallback

Automatic failover between email services:

```php
// If Mailgun fails, automatically tries SMTP
$sender = new EmailSender();
$success = $sender->send($message);

// Check what actually happened
if ($success) {
    // Email sent successfully (primary or fallback)
} else {
    // Both services failed - email queued for retry
}
```

### Failed Email Queue

Failed emails are automatically queued:

```php
// Failed emails go to queued_email table
// Can be retried later with queue processing script
```

### Custom Headers and Attachments

```php
$message = EmailMessage::create('user@example.com', 'Subject', 'Body')
    ->header('X-Custom-Header', 'value')
    ->attachment('/path/to/file.pdf', 'document.pdf')
    ->replyTo('support@example.com');
```

### Template Variable Integration

Full access to template variables:

```php
// All template variables work
$message = EmailMessage::fromTemplate('template', [
    'recipient' => $user->export_as_array(),  // User data
    'act_code' => $activation_code,           // Custom variables
    'utm_source' => 'newsletter'              // Tracking
]);

// Template can use:
// *recipient->usr_first_name*
// *act_code*
// *web_dir*
// *email_vars* (includes UTM tracking)
```

### Batch Operations

```php
$message = EmailMessage::fromTemplate('newsletter', [
    'content' => $newsletter_content
]);

$recipients = [];
$users = new MultiUser(['usr_active' => 1]);
$users->load();
foreach ($users as $user) {
    $recipients[] = $user->get('usr_email');
}

$sender = new EmailSender();
$result = $sender->sendBatch($message, $recipients);
// $result['success'] — true if all recipients succeeded
// $result['failed_recipients'] — array of email addresses that failed
// Failed recipients are automatically retried via the fallback provider,
// then queued for later retry if both providers fail.
```

## Error Handling

### Exception Types

- **EmailTemplateError**: Template parsing/processing errors
- **Exception**: General email sending errors (service failures, validation)

### Error Handling Patterns

```php
try {
    $message = EmailMessage::fromTemplate('template_name', $values);
    $sender = new EmailSender();
    $success = $sender->send($message);
    
    if (!$success) {
        // Email queued for retry
        error_log("Email queued due to service failure");
    }
} catch (EmailTemplateError $e) {
    // Template issue
    error_log("Template error: " . $e->getMessage());
} catch (Exception $e) {
    // Other issues
    error_log("Email error: " . $e->getMessage());
}
```

## Important Notes

### Variable Requirements

**Always include recipient data** when using templates:
```php
// CORRECT - includes recipient data
$success = EmailSender::sendTemplate('welcome', 
    $user->get('usr_email'),
    [
        'activation_code' => $code,
        'recipient' => $user->export_as_array()  // Required for templates
    ]
);

// MISSING - may cause template variable errors
$success = EmailSender::sendTemplate('welcome', 
    $user->get('usr_email'),
    ['activation_code' => $code]  // Missing recipient data
);
```

### Default Variables

The system automatically provides:
- `template_name` - Derived from template filename
- `web_dir` - Site base URL
- `email_vars` - UTM tracking parameters
- UTM defaults - `utm_source=email`, `utm_medium=email`, etc.

**Don't pass these manually** - they're provided automatically.

### Receipt Templates

The receipt system (specs/receipts_refactor.md) uses two database-stored templates:

| Template name | Purpose | Recipient |
|---|---|---|
| `purchase_receipt_default` | Default order receipt + per-registrant activation. One template, two render modes via `{is_billing}`. | Billing user always; per-registrant for event/bundle gift recipients. |
| `purchase_receipt_product_default` | Per-product opt-in email. Sent at most once per (product, order). Falls back here when a product has `pro_after_purchase_message` or `pro_emt_receipt_template_id` set. | Billing user. |

A product can override `purchase_receipt_product_default` with any other template by setting `pro_emt_receipt_template_id`. If the override points at a missing or soft-deleted template the helper `_resolve_receipt_template()` falls back to the default — never crashes.

**Variables passed to `purchase_receipt_default`:**

| Variable | Notes |
|---|---|
| `recipient` | Recipient's user data (billing user or registrant) |
| `is_billing` | True when sending to billing user; drives the price column and totals block |
| `order` | Order data |
| `order_total` | Used only when `is_billing` |
| `currency_symbol` | |
| `line_items` | Array — one entry per relevant line. Iterated via `{loop line_items as line}` |
| `coupon_codes_used` | Only when `is_billing` and at least one coupon applied |

Each `line_items` entry: `product_name`, `quantity`, `outcome` (`event`/`bundle`/`subscription`/`digital`/`plain`), `is_gift_to` (set on gift lines for billing user), plus outcome-specific fields (`event_name`, `event_list`, `digital_link`, `act_code`, `event_registrant_id`, `subscription_active`). Gift lines for the billing user deliberately omit `act_code` and `event_registrant_id` so the activation token doesn't leak to the buyer.

**Variables passed to `purchase_receipt_product_default`:** `recipient` (billing user), `product_name`, `after_purchase_message` (HTML, may be empty), `order_item`, `order`. There is no `is_gift` variable — per-product custom email always targets the billing user, so admins author one voice.

### Service Selection

- Default from/sender addresses are used automatically
- Only set custom `from()` when different from defaults
- Service fallback happens automatically on failures
- Failed emails are queued for later retry

## Summary

The email system provides:

- **✅ Modern fluent API** - clean, readable code patterns  
- **✅ Separation of concerns** - template processing vs sending logic
- **✅ Service reliability** - automatic fallback and retry
- **✅ Better testing** - comprehensive test suite and debug tools
- **✅ Maintained performance** - same template processing engine
- **✅ Template compatibility** - all existing templates work unchanged

Use EmailMessage + EmailSender for all email development. Direct EmailTemplate usage is only for specialized template processing needs.

## Two Send Modes & SmtpConfig

Outbound mail flows in exactly **two modes over one set of plumbing**:

- **Structured send** — `EmailSender::send(EmailMessage, $queue, ?$transport)` builds a MIME
  body from `from`/`to`/`subject`/`html|text` and lets the provider stamp our identity. This is
  the path for all transactional and composed mail.
- **Raw relay** — `provider->relayRawMessage($raw_mime, $envelope_sender, $destinations)` ships
  pre-formed bytes with an explicit `MAIL FROM`, preserving SPF/DKIM/SRS alignment to the original
  sender. This is the path for inbound forwarding. See [Raw-MIME relay](#raw-mime-relay-optional-capability).

Both modes share one SMTP construction model: `SmtpConfig` + a single `SmtpMailer`.

### SmtpConfig

`SmtpConfig` (`includes/SmtpConfig.php`) is a value object describing how to open an SMTP
transport — `{host, port, encryption, authMode, credential}` — with two auth modes: `password`
(username/password) and `xoauth2` (an OAuth access token via PHPMailer's XOAUTH2 token-provider
interface). `encryption` is `'ssl'` (implicit TLS / SMTPS), `'tls'` (STARTTLS), `'none'`, or
`null` (auto-detect from port). Three factories cover the three credential sources:

| Factory | Source | Used by |
|---|---|---|
| `SmtpConfig::fromSettings()` | Global `smtp_*` settings | The system SMTP provider (`new SmtpProvider()`), default for `new SmtpMailer()` |
| `SmtpConfig::fromConnectedAccount($account)` | A connected `InboundImapAccount` — PRESETS SMTP coordinates + the stored OAuth token (`xoauth2`) or app password (`password`) | The connected-account provider and per-mailbox transport |
| `SmtpConfig::fromForwardingSettings()` | `inbound_email_forwarding_smtp_*`, falling back to base `smtp_*` | Inbound forwarding's SMTP fallback |

`SmtpMailer` takes an optional `SmtpConfig` (default `fromSettings()`), so `new SmtpMailer()`
reads global `smtp_*` with password auth unchanged. The single `EmailMessage`→PHPMailer mapping
lives in `SmtpMailer::applyMessage(EmailMessage)`, so every structured SMTP send is "configure a
mailer from an `SmtpConfig`, `applyMessage`, send." `SmtpProvider` takes the same optional
`SmtpConfig`, so the one provider class is the SMTP transport whether configured globally, per
account, or for forwarding.

### XOAUTH2 (OAuth SMTP)

OAuth accounts (Gmail, Microsoft 365) send via **SMTP with XOAUTH2** — no provider REST API and no
second send path. `XOAuth2TokenProvider` (`includes/XOAuth2TokenProvider.php`) implements
PHPMailer's `OAuthTokenProvider`, sourcing a live access token from OAuth2 Core
(`OAuth2Client::ensureFresh()`) and persisting a refreshed token back onto the account — the same
shared grant inbound IMAP polling uses. A refresh failure flags `iia_needs_reauth`, so one
**Reconnect** fixes both inbound and outbound.

- **Google** — the `https://mail.google.com/` IMAP scope already authorizes SMTP send; no re-consent.
- **Microsoft** — needs `https://outlook.office365.com/SMTP.Send` alongside the IMAP scope; the
  connect flow requests both, so connecting (or reconnecting) an account grants both directions.
  M365 tenants may disable SMTP AUTH org-wide — a send rejected for auth surfaces an actionable
  warning to use Mailgun/SES or have the tenant admin enable SMTP AUTH.
- **Password providers** (Yahoo/iCloud/Fastmail) — the same app password sends; only host/port differ.

### Connected account as the system provider

Selecting **Connected Email Account** as the active `email_service` sends all site mail through a
connected account (chosen via the `connected_account_id` setting). `ConnectedMailboxProvider` is
pure UX over the SMTP path: it forces `From` to the account address (consumer/provider SMTP
rewrites it anyway — the accepted single-identity trade-off) and delegates to a `SmtpProvider`
configured with `SmtpConfig::fromConnectedAccount()`. It proactively refuses to send for an
account that lacks send authorization (e.g. a Microsoft account connected before SMTP.Send was
granted), reporting "Reconnect to allow sending."

### Injected transport (one pipeline, no bypass)

`EmailSender::send()` accepts an optional third argument, `?EmailServiceProvider $transport`:

- `$transport === null` (default) — select the provider by the `email_service` setting, with the
  `email_fallback_service` fallback (unchanged).
- `$transport` provided — send through it directly. **Fallback is skipped** (you cannot fall back a
  "send as this mailbox" to a different identity), but validation, the retry-queue, and debug
  logging are kept.

This is how "send **as** a specific mailbox" stays on the one pipeline. `resolveOutboundTransport($mailbox)`
(`includes/OutboundTransport.php`) returns a configured transport for a mailbox — an `SmtpProvider`
built from `SmtpConfig::fromConnectedAccount()` for an IMAP-source mailbox, or the platform's active
provider for a hosted alias (`alias@our-domain`, which a connected account cannot send as) — plus the
`From` identity and a `filesSent` flag (whether the provider's SMTP auto-files the Sent copy, a PRESETS
capability; when false, two-way sync APPENDs the copy). The caller sends via
`$sender->send($msg, true, $result->transport)`.

### Limits & migration

Per-provider send limits are the signal to move to an ESP. On a rate-limit/quota response the
connected-account path records a visible status ("<provider> is rate-limiting send — consider a
dedicated provider") and the message uses the existing retry queue. Migrating is one setting:
connect Mailgun/SES and change the active provider — no message-path changes. Bulk volume stays an
ESP job; sending a list through a connected account is allowed but warned against.

### Forwarding through a connected account

A connected account is **not** a transparent `RawMessageRelay` — its SMTP rewrites the envelope
sender and `From`. Inbound forwarding through one is allowed (the message goes out as the connected
address, with the original sender preserved in `Reply-To` / `X-Original-From`), but a relay-class
provider (SMTP host / Mailgun / SES) is preferred automatically when configured, since only it
keeps SPF/DKIM/SRS aligned to the original sender. Inbound forwarding's SMTP fallback routes through
`SmtpProvider::relayRawMessage()` configured with `SmtpConfig::fromForwardingSettings()`, so the raw
SMTP transaction lives in one place shared with all other SMTP relaying.

> **One account, both directions.** A single connected `InboundImapAccount` serves inbound (the
> IMAP feed) and outbound (the same `SmtpMailer`), with a shared OAuth grant and a shared
> `iia_needs_reauth` health flag. See [Inbound Email Plugin](../plugins/inbound_email/docs/overview.md#receiving-by-imap-poll).

## Email Authentication Checks (DnsAuthChecker)

`includes/DnsAuthChecker.php` is the one place to check whether a domain
**publishes** SPF, DKIM, and DMARC records. Use it — do not hand-roll
`dns_get_record()` TXT parsing. `adm/admin_settings_email.php` and the
`utils/email_setup_check.php` deep-dive tool both build on it, and the
`inbound_email` plugin's domain status badges do too.

> **Record presence ≠ message verification.** `DnsAuthChecker` is a DNS
> *record* check — it inspects domains **we control** for a sane outbound/setup
> config. It is **not** verification of an inbound message's connecting IP
> against a record, and must never be repurposed for inbound verdicts. The app
> **no longer computes inbound SPF/DKIM/DMARC at all** (it once hand-rolled a
> DKIM verifier that false-failed legitimate mail — removed). Per-inbound-message
> verdicts come from the message's `Authentication-Results` header, stamped by
> the verifying MTA (opendkim-verify + opendmarc) and read by the inbound_email
> plugin's `AuthenticationResults`/`InboundEmailRouter` — never from
> `DnsAuthChecker`. See `plugins/inbound_email/docs/overview.md` →
> *Inbound authentication*.

```php
require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));

$spf = DnsAuthChecker::checkSPF('example.com');   // ['status'=>'pass|warn|fail', 'detail'=>…, 'record'=>…]
$all = DnsAuthChecker::quickCheck('example.com'); // ['spf'=>…, 'dkim'=>…, 'dmarc'=>…]
```

Its lookups go through `DnsResolver` (the platform's single raw-DNS
chokepoint — see [Validation › DNS Lookups](/docs/validation.md#dns-lookups-dnsresolver)),
so a resolver failure is handled cleanly and the checks are unit-testable via
`DnsResolver::setBackend()`. `DnsAuthChecker`'s own public static API is
unchanged by that — callers and the `EmailAuthChecker` subclass are unaffected.

## Email Service Provider Interface

The email system uses a provider abstraction so that new email services can be added without modifying core code.

### Architecture

- **`EmailServiceProvider`** — interface in `includes/EmailServiceProvider.php` that all outbound providers implement
- **`InboundEmailProvider`** — sibling interface in `includes/InboundEmailProvider.php` for inbound transports (Postfix, Mailgun webhook, etc.). A single provider class may implement both interfaces; the Inbound Email plugin discovers inbound providers via `InboundProviderRegistry`. See [Inbound Email Plugin](../plugins/inbound_email/docs/overview.md#inbound-providers) for the inbound side.
- **`RawMessageRelay`** — optional capability interface, declared alongside `EmailServiceProvider` in `includes/EmailServiceProvider.php`. A provider opts in to relay raw MIME with a chosen envelope sender; used by inbound-email forwarding. See [Raw-MIME relay](#raw-mime-relay-optional-capability) below.
- **Provider classes** — live in `includes/email_providers/` (e.g., `MailgunProvider.php`, `SmtpProvider.php`, `SendGridProvider.php`)
- **Auto-discovery** — `EmailSender` scans `includes/email_providers/` for classes implementing `EmailServiceProvider`; `InboundProviderRegistry` walks the same directory for classes implementing `InboundEmailProvider`. No manual registration needed in either case.

### Built-in Providers

| Key | Label | Batch | Live API check | Notes |
|---|---|---|---|---|
| `mailgun` | Mailgun | Native (recipient-variables, 500/chunk) | Yes (domain show) | EU region supported via `mailgun_eu_api_link` |
| `smtp` | SMTP | Per-recipient loop via PHPMailer | Yes (connect + auth) | Generic SMTP, works with any provider that supports it |
| `sendgrid` | SendGrid | Native (personalizations, 1000/chunk) | Yes (`/v3/user/account`) | Global or EU region via `sendgrid_region`; supports sandbox mode and per-message click-tracking toggle |
| `ses` | Amazon SES | Per-recipient `SendEmail` loop (no native non-templated batch) | Yes (`GetAccount`) | AWS region selectable; static keys or IAM role auto-discovery; optional Configuration Set for engagement tracking |
| `postmark` | Postmark | Native (`sendEmailBatch`, 500/chunk, per-recipient failure status) | Yes (`getServer`) | Server token (not Account token); message stream selection (transactional vs broadcast); per-message open and link tracking |
| `brevo` | Brevo | Native (`messageVersions`, 1000/chunk) | Yes (`/v3/account`) | Single global endpoint; supports sandbox mode via `X-Sib-Sandbox` header |
| `resend` | Resend | Native (`batch->send`, 100/chunk) | Yes (`apiKeys->list`) | Simplest config — single bearer token. Restricted/sending-only keys validate as "API Key Valid (Restricted)" |
| `mailjet` | Mailjet | Native v3.1 Send API (50 messages/chunk, per-message status) | Yes (`/v3/REST/myprofile`) | Two-part credential (key + secret); supports sandbox mode |
| `connected_account` | Connected Email Account | Per-recipient loop | — | Sends all site mail through a connected IMAP account's SMTP (Gmail/M365 XOAUTH2, Yahoo/iCloud/Fastmail app password). Forces `From` to that address. See [Two send modes](#two-send-modes--smtpconfig) |

### Adding a New Provider

Create a single file in `includes/email_providers/` implementing `EmailServiceProvider`:

```php
class SendGridProvider implements EmailServiceProvider {
    public static function getKey(): string { return 'sendgrid'; }
    public static function getLabel(): string { return 'SendGrid'; }
    public static function getSettingsFields(): array { /* ... */ }
    public static function validateConfiguration(): array { /* ... */ }
    public function send(EmailMessage $message): bool { /* ... */ }
    public function sendBatch(EmailMessage $message, array $recipients): array { /* ... */ }
}
```

The provider automatically appears in the admin email settings dropdown and its configuration fields render dynamically. No other files need modification.

### Interface Methods

| Method | Purpose |
|---|---|
| `getKey()` | Unique key stored in settings (e.g., `'mailgun'`) |
| `getLabel()` | Human-readable name for admin UI |
| `getSettingsFields()` | Array of setting field definitions for admin rendering |
| `validateConfiguration()` | Check required settings are present; returns `['valid' => bool, 'errors' => []]` |
| `send(EmailMessage)` | Send a single message; return success/failure |
| `sendBatch(EmailMessage, array)` | Send to multiple recipients; returns `['success' => bool, 'failed_recipients' => []]`. Providers can optimize (e.g., Mailgun batch API) |
| `validateApiConnection()` | (Optional) Live API check for admin validation panel |

### Raw-MIME relay (optional capability)

`RawMessageRelay` is an opt-in capability interface declared next to
`EmailServiceProvider` in `includes/EmailServiceProvider.php` (no separate
file — it is in scope wherever providers are resolved). A provider implements
it **in addition to** `EmailServiceProvider` when it can relay an
already-formed RFC 5322 message byte-for-byte to chosen envelope recipients
with an explicit envelope sender (Return-Path / `MAIL FROM`):

```php
class MailgunProvider implements EmailServiceProvider, InboundEmailProvider, RawMessageRelay {
    // ...
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
        // relay $raw_mime as-is to $destinations; returns ['dest@x' => bool]
    }
}
```

**What it is for.** The normal `send()` path rebuilds a message from
`from`/`to`/`subject`/`html|text` and exposes no envelope-sender field, so it
cannot relay original MIME faithfully or set a custom Return-Path. Inbound-email
forwarding needs both, so it uses `RawMessageRelay` when the active outbound
provider implements it, reusing that provider's existing credential.

**Which providers implement it.**

| Provider | Raw-MIME path | Envelope sender |
|---|---|---|
| `mailgun` | `messages.mime` (SDK `sendMime`) | Mailgun owns bounces; best-effort sender |
| `smtp` | Native raw SMTP | Full `MAIL FROM` control |
| `ses` | SESv2 `sendEmail` with `Content.Raw.Data` | SES owns bounces; verified MAIL FROM domain |

The structured-only providers (`postmark`, `sendgrid`, `brevo`, `mailjet`,
`resend`) deliberately **do not** implement it — they expose no faithful
raw-MIME relay. A provider without the capability is detected via
`instanceof RawMessageRelay` and the caller falls back to an SMTP relay, so
forwarding never regresses. See
[Inbound Email — Forwarding relay](../plugins/inbound_email/docs/overview.md#forwarding-relay)
for how the inbound plugin resolves the relay path and handles SRS per path.