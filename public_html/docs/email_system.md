# Email System Documentation

## Overview

The email system consists of three focused classes that provide clear separation of concerns:

- **EmailMessage**: Fluent API for composing email messages
- **EmailTemplate**: Template processing (conditionals, variables)
- **EmailSender**: All sending logic with service selection and fallback

**Inbound email forwarding** is handled by the Email Forwarding plugin — see [Email Forwarding Plugin](email_forwarding_plugin.md) for setup, admin usage, and server configuration.

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

## Email Service Provider Interface

The email system uses a provider abstraction so that new email services can be added without modifying core code.

### Architecture

- **`EmailServiceProvider`** — interface in `includes/EmailServiceProvider.php` that all providers implement
- **Provider classes** — live in `includes/email_providers/` (e.g., `MailgunProvider.php`, `SmtpProvider.php`)
- **Auto-discovery** — `EmailSender` scans `includes/email_providers/` for classes implementing the interface; no manual registration needed

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