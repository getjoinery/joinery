# Email System Migration Specification

## Overview

This specification documents the migration from the old EmailTemplate monolithic system to the new three-class architecture (EmailMessage, EmailSender, EmailTemplate). All legacy EmailTemplate usage patterns need to be converted to use the new modern API.

## Migration Goals

1. **Complete deprecation removal**: Remove all deprecated methods from EmailTemplate class
2. **Convert all legacy usage**: Migrate 25+ files using old patterns to new architecture  
3. **Maintain functionality**: Ensure all existing email functionality works with new system
4. **Improve code quality**: Replace procedural patterns with clean fluent API

## Current Usage Inventory

### Files Using Legacy Patterns

**Core System Files:**
- `/includes/Activation.php` - 3 email sending functions
- `/data/users_class.php` - Welcome email sending
- `/data/mailing_lists_class.php` - Welcome email on subscription
- `/data/order_items_class.php` - Subscription cancellation notifications
- `/data/recurring_mailer_class.php` - Recurring email templates
- `/logic/post_logic.php` - Comment notifications
- `/logic/cart_charge_logic.php` - Order and subscription notifications

**Admin Interface Files:**
- `/adm/admin_users_message.php` - Bulk messaging system
- `/adm/admin_emails_send.php` - Email campaign sending
- `/adm/admin_email_recipients_modify.php` - Recipient management

**Test and Utility Files:**
- `/utils/scratch.php` - Test email sending
- `/utils/email_send_test.php` - Email service testing
- `/tests/integration/mailgun_test.php` - Mailgun integration tests
- `/ajax/email_preview_ajax.php` - Email preview functionality

### Deprecated Methods to Remove

**From EmailTemplate class:**
```php
public function add_recipient($recipient_email, $recipient_name = null)
public function clear_recipients() 
public function send($check_session = true, $other_host = null)
public $email_from          // Property
public $email_from_name     // Property
public $email_subject       // Property (keep but make private)
public $email_html          // Property (keep but make private)  
public $email_text          // Property (keep but make private)
```

**Legacy patterns:**
```php
// Pattern 1: CreateLegacyTemplate + fill_template + send
$email = EmailTemplate::CreateLegacyTemplate('template', $user);
$email->fill_template($values);
$email->send();

// Pattern 2: Property assignments + send
$email->email_subject = 'Subject';
$email->email_from = 'from@example.com';
$email->add_recipient('to@example.com');
$email->send();

// Pattern 3: Direct property access
$subject = $email->email_subject;
$html = $email->email_html;
```

## Migration Patterns

**🔧 Important Simplification**: EmailSender automatically sets the default from address (`defaultemail` and `defaultemailname` settings) if none is specified. This eliminates the need for explicit `->from()` calls in most migrations, saving includes and method calls throughout the codebase.

### Pattern 1: Simple Template-Based Sending

**BEFORE:**
```php
$email = EmailTemplate::CreateLegacyTemplate('activation_content', $user);
$email->fill_template([
    'act_code' => $activation_code,
    'resend' => false
]);
return $email->send();
```

**AFTER:**
```php
$success = EmailSender::sendTemplate('activation_content', 
    $user->get('usr_email'),
    [
        'act_code' => $activation_code,
        'resend' => false,
        'recipient' => $user->export_as_array()  // Add user data if template needs it
    ]
);
return $success;
```

### Pattern 2: Manual Recipient Management

**BEFORE:**
```php
$email = EmailTemplate::CreateLegacyTemplate('template', null);
$email->clear_recipients();
$email->add_recipient($user_email, $user_name);
$email->fill_template($values);
$email->send();
```

**AFTER:**
```php
$message = EmailMessage::fromTemplate('template', $values);
$message->to($user_email, $user_name);

$sender = new EmailSender();
$sender->send($message);
// Note: Default from address used automatically
```

### Pattern 3: Custom From Address

**BEFORE:**
```php
$email = EmailTemplate::CreateLegacyTemplate('template', $user);
$email->fill_template($values);
$email->email_from = 'custom@example.com';
$email->email_from_name = 'Custom Name';
$email->send();
```

**AFTER:**
```php
$message = EmailMessage::fromTemplate('template', array_merge($values, [
    'recipient' => $user->export_as_array()
]));
$message->from('custom@example.com', 'Custom Name')
        ->to($user->get('usr_email'), $user->get('usr_name'));

$sender = new EmailSender();
$sender->send($message);
```

### Pattern 4: Subject Override

**BEFORE:**
```php
$email = EmailTemplate::CreateLegacyTemplate('template', $user);
$email->fill_template($values);
$email->email_subject = 'Custom Subject';
$email->send();
```

**AFTER:**
```php
$message = EmailMessage::fromTemplate('template', array_merge($values, [
    'recipient' => $user->export_as_array()
]));
$message->subject('Custom Subject')
        ->to($user->get('usr_email'), $user->get('usr_name'));

$sender = new EmailSender();
$sender->send($message);
// Note: Default from address used automatically
```

### Pattern 5: Batch Sending to Multiple Recipients

**BEFORE:**
```php
foreach ($users as $user) {
    $email = EmailTemplate::CreateLegacyTemplate('newsletter', $user);
    $email->fill_template(['content' => $newsletter_content]);
    $email->send();
}
```

**AFTER:**
```php
$message = EmailMessage::fromTemplate('newsletter', [
    'content' => $newsletter_content
]);

$recipients = [];
foreach ($users as $user) {
    $recipients[] = $user->get('usr_email');
}

$sender = new EmailSender();
$results = $sender->sendBatch($message, $recipients);
```

### Pattern 6: Direct Property Access

**For Email Sending:**
```php
// BEFORE:
$email = EmailTemplate::CreateLegacyTemplate('template', $user);
$email->fill_template($values);
$subject = $email->email_subject;
$html_content = $email->email_html;
$text_content = $email->email_text;

// AFTER: Use EmailMessage for sending
$message = EmailMessage::fromTemplate('template', array_merge($values, [
    'recipient' => $user->export_as_array()
]));
$subject = $message->getSubject();
$html_content = $message->getHtmlBody();
$text_content = $message->getTextBody();
```

**For Preview/Testing (No Sending):**
```php
// BEFORE:
$email_template = EmailTemplate::CreateLegacyTemplate('template', $user);
$email_template->fill_template($values);
echo $email_template->email_html;

// AFTER: EmailTemplate is perfect for this use case
$email_template = EmailTemplate::CreateLegacyTemplate('template', $user);
$email_template->fill_template($values);
echo $email_template->getHtml();
// Note: This is exactly what EmailTemplate should be used for - pure template processing
```

## Specific File Migrations

### 1. includes/Activation.php

**Current Issues:**
- Uses CreateLegacyTemplate + send pattern
- Direct property assignment for from address
- Manual recipient clearing

**Migration Required:**

```php
// BEFORE: email_activate_send function
$activation_email = EmailTemplate::CreateLegacyTemplate('activation_content', $user);
$activation_email->fill_template([
    'resend' => $resend,
    'act_code' => $act_code,
]);
return $activation_email->send();

// AFTER: 
$success = EmailSender::sendTemplate('activation_content',
    $user->get('usr_email'),
    [
        'resend' => $resend,
        'act_code' => $act_code,
        'recipient' => $user->export_as_array()
    ]
);
return $success;
```

```php
// BEFORE: email_forgotpw_send function  
$activation_email = EmailTemplate::CreateLegacyTemplate('forgotpw_content', $user);
$activation_email->fill_template([
    'act_code' => $act_code,
    'web_dir' => LibraryFunctions::get_absolute_url(''),
]);
$activation_email->email_from = $settings->get_setting('defaultemail');
$activation_email->email_from_name = $settings->get_setting('defaultemailname');
$activation_email->add_recipient($user->get('usr_email'));
$activation_email->send();

// AFTER:
$message = EmailMessage::fromTemplate('forgotpw_content', [
    'act_code' => $act_code,
    'web_dir' => LibraryFunctions::get_absolute_url(''),
    'recipient' => $user->export_as_array()
]);
$message->to($user->get('usr_email'), $user->get('usr_name'));

$sender = new EmailSender();
$sender->send($message);
// Note: EmailSender automatically uses default from address if none specified
```

```php
// BEFORE: email_change_send function
$activation_email = EmailTemplate::CreateLegacyTemplate('email_change_content', $user);
$activation_email->fill_template([
    'act_code' => $act_code,
    'new_email' => $new_email,
    'web_dir' => LibraryFunctions::get_absolute_url(''),
]);
$activation_email->mailer->clearAllRecipients();
$activation_email->mailer->addAddress($new_email);
$activation_email->send();

// AFTER:
$message = EmailMessage::fromTemplate('email_change_content', [
    'act_code' => $act_code,
    'new_email' => $new_email,
    'web_dir' => LibraryFunctions::get_absolute_url(''),
    'recipient' => $user->export_as_array()
]);
$message->to($new_email); // Send to new email, not user's current email

$sender = new EmailSender();
$sender->send($message);
```

### 2. data/users_class.php

**Current Usage:**
```php
$welcome_email = EmailTemplate::CreateLegacyTemplate('new_account_content', $user);
$welcome_email->fill_template($email_fill);
$welcome_email->send();
```

**Migration:**
```php
EmailSender::sendTemplate('new_account_content',
    $user->get('usr_email'),
    array_merge($email_fill, ['recipient' => $user->export_as_array()])
);
```

### 3. adm/admin_users_message.php

**Current Issues:**
- Complex bulk messaging with manual recipient management
- Direct property assignments
- Multiple template configurations

**Key Migration Areas:**

```php
// BEFORE: Event registrant messaging
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, NULL, $email_outer_template, $email_footer_template);
// ... complex recipient setup ...
$result = $email->send();

// AFTER: Use new batch sending or individual message approach
$message = EmailMessage::fromTemplate($email_inner_template, [
    'subject' => $_POST['eml_subject'],
    'body' => $_POST['eml_message_html'],
    'utm_campaign' => $mailing_list_string,
    'utm_content' => urlencode($_POST['eml_subject'])
]);

foreach ($event_registrants as $registrant) {
    $individual_message = clone $message;
    $individual_message->to($registrant->get('usr_email'), $registrant->display_name());
    
    $sender = new EmailSender();
    $result = $sender->send($individual_message);
    // ... status tracking ...
}
```

### 4. adm/admin_emails_send.php

**Current Issues:**
- Mass email campaign sending
- Direct property assignments for subject and from
- Complex recipient tracking

**Migration Required:**
```php
// BEFORE: Campaign sending loop
$email_template = EmailTemplate::CreateLegacyTemplate($email->get('eml_message_template_html'), $user);
$email_template->fill_template([
    'subject' => $email->get('eml_subject'),
    'body' => $email->get('eml_message_html'),
    // ... more template values
]);
$email_template->email_subject = $email->get('eml_subject');
$email_template->email_from = $email->get('eml_from');
$email_template->email_from_name = $email->get('eml_from_name');
$result = $email_template->send(TRUE);

// AFTER: Individual message sending
$message = EmailMessage::fromTemplate($email->get('eml_message_template_html'), [
    'subject' => $email->get('eml_subject'),
    'body' => $email->get('eml_message_html'),
    'preview_text' => $email->get('eml_preview_text'),
    'utm_medium' => 'email',
    'utm_campaign' => $mailing_list_string,
    'utm_content' => urlencode($email->get('eml_subject')),
    'mailing_list_id' => $mailing_list_id,
    'recipient' => $user->export_as_array()
]);

$message->subject($email->get('eml_subject'))
        ->to($user->get('usr_email'), $user->display_name());

// Only set custom from if different from defaults
if ($email->get('eml_from') != $settings->get_setting('defaultemail')) {
    $message->from($email->get('eml_from'), $email->get('eml_from_name'));
}

$sender = new EmailSender();
$result = $sender->send($message);
```

### 5. ajax/email_preview_ajax.php

**Current Usage:** Pure template processing for previews (no email sending)

```php
$email_template = EmailTemplate::CreateLegacyTemplate($email->get('eml_message_template_html'), $recipient);
$email_template->fill_template([
    'subject' => 'COPY: '.$email->get('eml_subject'),
    'body' => $email->get('eml_message_html'),
    // ... more values
]);
$email_template->email_subject = $email->get('eml_subject');
$email_template->email_from = $email->get('eml_from_address');
$email_template->email_from_name = $email->get('eml_from_name');

echo $email_template->email_html;
```

**Migration:** Minimal change - this is exactly what EmailTemplate should do!

```php
$email_template = EmailTemplate::CreateLegacyTemplate($email->get('eml_message_template_html'), $recipient);
$email_template->fill_template([
    'subject' => 'COPY: '.$email->get('eml_subject'),
    'body' => $email->get('eml_message_html'),
    // ... more values
]);
// Remove property assignments - not needed for preview
// $email_template->email_subject = $email->get('eml_subject');
// $email_template->email_from = $email->get('eml_from_address'); 
// $email_template->email_from_name = $email->get('eml_from_name');

echo $email_template->getHtml();  // Use getter instead of direct property
```

**Note:** This preview system demonstrates perfect separation of concerns - EmailTemplate handles pure template processing while EmailSender handles sending. No architectural changes needed, just property access cleanup.

### 6. logic/post_logic.php & logic/cart_charge_logic.php

**Current Pattern:**
```php
$email = EmailTemplate::CreateLegacyTemplate($email_inner_template, $notify_user);
$email->fill_template([
    'subject' => 'Notification Subject',
    'body' => $notification_body
]);
$email->send();
```

**Migration:**
```php
EmailSender::sendTemplate($email_inner_template,
    $notify_user->get('usr_email'),
    [
        'subject' => 'Notification Subject',
        'body' => $notification_body,
        'recipient' => $notify_user->export_as_array()
    ]
);
```

## Deprecated Method Removal

### Methods to Remove from EmailTemplate

```php
// Remove these public methods completely:
public function add_recipient($recipient_email, $recipient_name = null)
public function clear_recipients()
public function send($check_session = true, $other_host = null)

// Remove these public properties:
public $email_from
public $email_from_name

// Make these properties private (keep for internal template processing):
private $email_subject
private $email_html  
private $email_text
```

### Update Constructor Error Message

```php
public function __construct($inner_template, $outer_template, $footer) {
    if ($outer_template instanceof User || is_object($outer_template)) {
        throw new EmailTemplateError(
            'EmailTemplate constructor no longer accepts User objects. ' .
            'Use EmailMessage::fromTemplate() and EmailSender for new code. ' .
            'For legacy compatibility during migration, use EmailTemplate::CreateLegacyTemplate()'
        );
    }
    // ... rest of constructor
}
```

### Remove CreateLegacyTemplate After Migration

Once all files are migrated, remove the `CreateLegacyTemplate` method entirely:

```php
// DELETE this entire method after migration is complete
public static function CreateLegacyTemplate($inner_template, $recipient_user = null, $outer_template = null, $footer = null)
```

## Testing Strategy

### Test Each Migration

1. **Unit Tests**: Create test for each migration pattern
2. **Integration Tests**: Test actual email sending with new patterns  
3. **Regression Tests**: Ensure all existing functionality works
4. **Performance Tests**: Verify batch sending performs well

### Migration Verification Checklist

For each migrated file:
- [ ] All `CreateLegacyTemplate` calls removed
- [ ] All `->send()` calls converted to EmailSender
- [ ] All direct property access converted to getters
- [ ] All `add_recipient`/`clear_recipients` converted to EmailMessage API
- [ ] User data properly passed via 'recipient' key when needed
- [ ] Subject overrides converted to `$message->subject()`
- [ ] From address overrides converted to `$message->from()`
- [ ] Templates variables properly preserved
- [ ] Error handling maintained
- [ ] Existing functionality verified working

## Implementation Approach

**All migrations will be completed simultaneously** to ensure system consistency and avoid partial migration states.

### Files to Migrate (Complete List)

**Core System Files:**
1. `includes/Activation.php` - User registration/password reset flows
2. `data/users_class.php` - Welcome emails  
3. `data/mailing_lists_class.php` - Subscription emails
4. `data/order_items_class.php` - Subscription management
5. `data/recurring_mailer_class.php` - Recurring email templates

**Business Logic Files:**
6. `logic/post_logic.php` - Comment notifications
7. `logic/cart_charge_logic.php` - Order processing emails

**Admin Interface Files:**
8. `adm/admin_users_message.php` - Bulk messaging (most complex)
9. `adm/admin_emails_send.php` - Campaign sending 
10. `adm/admin_email_recipients_modify.php` - Recipient management

**Utility and Test Files:**
11. `utils/scratch.php` - Test email sending
12. `utils/email_send_test.php` - Email service testing
13. `tests/integration/mailgun_test.php` - Mailgun integration tests
14. `ajax/email_preview_ajax.php` - Email preview functionality (minimal change - property access only)

**Immediate Cleanup:**
15. Remove deprecated methods from EmailTemplate class
16. Remove CreateLegacyTemplate method  
17. Update all documentation and examples

## Error Handling Improvements

### Old Pattern Error Handling
```php
$email = EmailTemplate::CreateLegacyTemplate('template', $user);
$email->fill_template($values);
$result = $email->send();
if (!$result) {
    // Limited error information
    error_log('Email send failed');
}
```

### New Pattern Error Handling  
```php
try {
    $message = EmailMessage::fromTemplate('template', array_merge($values, [
        'recipient' => $user->export_as_array()
    ]));
    $message->to($user->get('usr_email'));
    
    $sender = new EmailSender();
    $success = $sender->send($message);
    
    if (!$success) {
        // Email was queued for retry due to service failure
        error_log('Email queued for retry - service temporary failure');
    }
} catch (EmailTemplateError $e) {
    // Template-specific error
    error_log('Template error: ' . $e->getMessage());
} catch (Exception $e) {
    // Other errors (validation, service configuration, etc.)
    error_log('Email error: ' . $e->getMessage());
}
```

## Benefits After Migration

1. **Cleaner Code**: Fluent API replaces procedural patterns
2. **Better Error Handling**: Specific exceptions for different error types
3. **Improved Testing**: Clean separation makes unit testing easier
4. **Enhanced Reliability**: Service fallback and retry queuing
5. **Future Extensibility**: New features can be added to focused classes
6. **Reduced Complexity**: EmailTemplate focused only on template processing

## Post-Migration Validation

After completing all migrations:

1. **Functional Testing**: Test all email flows end-to-end
2. **Performance Testing**: Verify batch sending performance  
3. **Error Condition Testing**: Test service failures and fallbacks
4. **Documentation Update**: Update all code examples and documentation
5. **Code Review**: Review all migrated code for consistency and best practices

This migration will result in a much cleaner, more maintainable email system while preserving all existing functionality.