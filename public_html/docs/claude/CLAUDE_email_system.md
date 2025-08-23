# Email System Documentation

## Overview

The email system has been refactored into three focused classes that provide clear separation of concerns:

- **EmailMessage**: Fluent API for composing email messages
- **EmailTemplate**: Template processing only (conditionals, variables)  
- **EmailSender**: All sending logic with service selection and fallback

## Architecture

### EmailMessage Class (NEW)

A clean, fluent API for email composition:

```php
// Create from template
$message = EmailMessage::fromTemplate('activation_content', [
    'act_code' => 'ABC123',
    'resend' => false
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

### EmailSender Class (NEW)

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
    ['name' => 'John']
);

// Batch send
$recipients = ['user1@example.com', 'user2@example.com'];
$results = $sender->sendBatch($message, $recipients);
```

**Service Selection:**
- Primary service: `email_service` setting (mailgun/smtp)
- Fallback service: `email_fallback_service` setting
- Automatic fallback if primary fails
- Queue failed emails for retry

### EmailTemplate Class (REFACTORED)

Now focused solely on template processing:

```php
// DEPRECATED - Don't use constructor directly
// Use EmailTemplate::CreateLegacyTemplate() instead
$template = EmailTemplate::CreateLegacyTemplate('activation_content', $user);
$template->fill_template([
    'act_code' => 'ABC123',
    'resend' => false
]);

// Get processed content
$subject = $template->getSubject();
$html = $template->getHtml();
$text = $template->getText();
```

## New Development Patterns

### Modern Approach (Recommended)

```php
// For new code - use EmailMessage + EmailSender
$message = EmailMessage::fromTemplate('welcome_email', [
    'user_name' => $user->get('usr_name'),
    'activation_code' => $code
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
        'user_name' => $user->get('usr_name')
    ]
);
```

## Template System

### Template Processing (Unchanged)

Templates still work exactly as before with full conditional and variable support:

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

### Variable Syntax (Unchanged)

- **Variables**: `*variable_name*`
- **Object access**: `*recipient->usr_first_name*`  
- **Pipe qualifiers**: `*date|Y-m-d*`
- **UTM tracking**: `*email_vars*`

### Conditional Syntax (Unchanged)

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

### Subject Processing (Unchanged)

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
mailgun_version = 2  // Use Mailgun SDK v2+
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

## Backwards Compatibility

### Existing Code Support

**All existing EmailTemplate usage continues to work:**

```php
// OLD CODE - Still works fine
$email = new EmailTemplate('activation_content', $user);
$email->fill_template(['act_code' => $code]);
$email->send();  // Still works - uses EmailSender internally

// OLD STATIC METHOD - Still works  
EmailTemplate::send_email(
    'template_name',
    $user->get('usr_email'),
    ['variable' => 'value']
);
```

**Migration is optional** - the old API is fully maintained.

### Constructor Changes

**BREAKING CHANGE**: Direct constructor usage is deprecated:

```php
// DEPRECATED - Will show error
$email = new EmailTemplate('template_name', $user);

// CORRECT - Use factory method
$email = EmailTemplate::CreateLegacyTemplate('template_name', $user);
```

This prevents accidental direct usage while maintaining all functionality.

## Migration Guide

### When to Migrate

**Migrate to new system when:**
- Building new email functionality
- Code needs batch sending
- Complex sending logic required
- Better error handling needed

**Keep old system when:**
- Existing code works fine
- Simple template-based emails
- No time for refactoring

### Migration Examples

**OLD: Simple template send**
```php
$email = new EmailTemplate('welcome', $user);
$email->fill_template(['code' => $activation_code]);
$success = $email->send();
```

**NEW: Using EmailMessage**
```php
$message = EmailMessage::fromTemplate('welcome', [
    'code' => $activation_code,
    'recipient' => $user->export_as_array()
]);
$message->to($user->get('usr_email'), $user->get('usr_name'));

$sender = new EmailSender();
$success = $sender->send($message);
```

**NEW: Using convenience method**
```php
$success = EmailSender::sendTemplate('welcome', 
    $user->get('usr_email'),
    ['code' => $activation_code, 'recipient' => $user->export_as_array()]
);
```

### Batch Operations

**OLD: Manual loop**
```php
$users = new MultiUser(['usr_active' => 1]);
$users->load();

foreach ($users as $user) {
    $email = new EmailTemplate('newsletter', $user);
    $email->fill_template(['content' => $newsletter_content]);
    $email->send();
}
```

**NEW: Batch sending**
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
$results = $sender->sendBatch($message, $recipients);
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
    'variable' => 'value'
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

Full access to existing template variables:

```php
// All existing variables still work
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

## Summary

The refactored email system provides:

- **✅ Full backwards compatibility** - existing code works unchanged
- **✅ Modern fluent API** - clean, readable new code patterns  
- **✅ Separation of concerns** - template processing vs sending logic
- **✅ Service reliability** - automatic fallback and retry
- **✅ Better testing** - comprehensive test suite and debug tools
- **✅ Maintained performance** - same template processing engine
- **✅ Migration flexibility** - migrate at your own pace

The old EmailTemplate API remains fully supported, while the new EmailMessage/EmailSender classes provide modern patterns for new development.