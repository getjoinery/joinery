# Email Template Testing Fixes Specification

## Problem Statement

The current email testing suite in `/tests/email/` is not accurately testing the system's actual email subject handling behavior. Tests are overriding subjects directly instead of testing how the production system handles them, potentially masking bugs.

## Key Issues Identified

1. **Direct Subject Override**: Tests set `$email->email_subject` directly after calling `fill_template()`, bypassing the system's subject extraction logic
2. **Incorrect Assumptions**: Tests pass 'subject' to `fill_template()` expecting it to set the subject, but this only works if the template uses `*subject*`
3. **No Template Subject Testing**: Tests don't verify that templates with "subject:" lines work correctly
4. **Missing Edge Cases**: No tests for templates without subjects, malformed subjects, or subject priority

## Solution Overview

Create comprehensive tests that verify the actual system behavior without overriding or "fixing" anything. If the system is broken, the tests should fail.

## Implementation Details

### 1. Fix TemplateTests.php

#### BEFORE (Current Code)
```php
private function testBasicProcessing(): array {
    try {
        $email = new EmailTemplate('activation_content');
        $settings = Globalvars::get_instance();
        $email->email_from = $settings->get_setting('defaultemail');
        $email->email_from_name = $settings->get_setting('defaultemailname');
        $email->add_recipient('test@example.com', 'Test User');
        
        $values = [
            'subject' => 'Test Subject',  // This doesn't actually set the subject!
            'act_code' => 'TEST123',
            'resend' => false,
        ];
        
        $email->fill_template($values);
        // ... rest of test
    }
}
```

#### AFTER (Fixed Code)
```php
private function testBasicProcessing(): array {
    try {
        // Test with a template that we know has a subject line
        $email = new EmailTemplate('activation_content');
        $settings = Globalvars::get_instance();
        $email->email_from = $settings->get_setting('defaultemail');
        $email->email_from_name = $settings->get_setting('defaultemailname');
        $email->add_recipient('test@example.com', 'Test User');
        
        // Don't pass 'subject' unless the template uses *subject*
        $values = [
            'act_code' => 'TEST123',
            'resend' => false,
        ];
        
        $email->fill_template($values);
        
        // Check if the template extracted a subject
        $subject = $email->getEmailSubject();
        $hasSubject = !empty($subject);
        
        // Get debug info
        $debugInfo = [
            'hasContent_result' => $email->hasContent(),
            'subject_extracted' => $subject,
            'subject_exists' => $hasSubject,
            'email_html_length' => strlen($email->getEmailHtml()),
            'template_first_line' => $this->getFirstLineOfTemplate('activation_content'),
        ];
        
        return [
            'passed' => $email->hasContent() && $hasSubject,
            'message' => $hasSubject ? 
                "Template processing successful with subject: $subject" : 
                "Template processing completed but no subject found",
            'details' => $debugInfo
        ];
    } catch (Exception $e) {
        return [
            'passed' => false,
            'message' => 'Template processing failed: ' . $e->getMessage(),
        ];
    }
}

// New helper method to check template content
private function getFirstLineOfTemplate($templateName): ?string {
    try {
        $templates = new MultiEmailTemplateStore(['email_template_name' => $templateName]);
        $templates->load();
        if ($templates->count_all() > 0) {
            $template = $templates->get(0);
            $body = $template->get('emt_body');
            $lines = preg_split('/[\r\n]/', $body, 2, PREG_SPLIT_NO_EMPTY);
            return $lines[0] ?? null;
        }
    } catch (Exception $e) {
        return 'Error loading template: ' . $e->getMessage();
    }
    return null;
}
```

### 2. Add New Subject-Specific Tests

#### NEW TEST: testSubjectExtraction()
```php
private function testSubjectExtraction(): array {
    $results = [];
    
    // Test 1: Template with subject line
    $email1 = new EmailTemplate('blank_template');
    $email1->email_from = 'test@example.com';
    $email1->add_recipient('test@example.com', 'Test');
    
    // Create a template with subject in first line
    $testTemplate = "subject: Test Subject Line\n<p>Body content</p>";
    // Would need to inject this as inner_template for testing
    
    $email1->fill_template(['body' => 'test']);
    $results['template_subject'] = [
        'subject' => $email1->getEmailSubject(),
        'has_subject' => !empty($email1->getEmailSubject()),
    ];
    
    // Test 2: Direct subject assignment
    $email2 = new EmailTemplate('blank_template');
    $email2->email_from = 'test@example.com';
    $email2->add_recipient('test@example.com', 'Test');
    $email2->fill_template(['body' => 'test']);
    $email2->email_subject = 'Direct Subject';
    
    $results['direct_subject'] = [
        'subject' => $email2->getEmailSubject(),
        'has_subject' => !empty($email2->getEmailSubject()),
    ];
    
    // Test 3: Template variable method
    $email3 = new EmailTemplate('blank_template');
    $email3->email_from = 'test@example.com';
    $email3->add_recipient('test@example.com', 'Test');
    
    // This only works if template contains *subject*
    $email3->fill_template([
        'subject' => 'Variable Subject',
        'body' => 'subject: *subject*\n<p>Body</p>'
    ]);
    
    $results['variable_subject'] = [
        'subject' => $email3->getEmailSubject(),
        'has_subject' => !empty($email3->getEmailSubject()),
    ];
    
    $allPassed = $results['direct_subject']['has_subject'] && 
                 ($results['template_subject']['has_subject'] || 
                  $results['variable_subject']['has_subject']);
    
    return [
        'passed' => $allPassed,
        'message' => 'Subject extraction methods tested',
        'details' => $results
    ];
}
```

### 3. Fix DeliveryTests.php

#### BEFORE (Current Code)
```php
private function testTestModeRedirect(): array {
    try {
        $email = new EmailTemplate('activation_content');
        // ... setup ...
        
        $email->fill_template([
            'act_code' => 'TEST123',
            'resend' => false,
        ]);
        
        // Override subject to identify this test
        $email->email_subject = 'Test Mode Redirect Test - ' . date('Y-m-d H:i:s');
        
        // ... rest of test
    }
}
```

#### AFTER (Fixed Code)
```php
private function testTestModeRedirect(): array {
    try {
        $email = new EmailTemplate('activation_content');
        $settings = Globalvars::get_instance();
        $email->email_from = $settings->get_setting('defaultemail');
        $email->email_from_name = $settings->get_setting('defaultemailname');
        $email->add_recipient($this->config['test_email'], 'Test Recipient');
        
        $email->fill_template([
            'act_code' => 'TEST123',
            'resend' => false,
        ]);
        
        // DON'T override subject - test what the system actually does
        // If we need to identify the test, use a different approach
        
        // Get the actual subject from the template
        $actualSubject = $email->getEmailSubject();
        
        // Debug info before sending
        $debugInfo = [
            'test_recipient' => $this->config['test_email'],
            'hasContent' => $email->hasContent(),
            'actual_subject' => $actualSubject,  // What the system generated
            'subject_exists' => !empty($actualSubject),
            'email_from' => $email->email_from,
            'service_type' => $email->getServiceType(),
        ];
        
        // Only send if we have content AND a subject (as production would require)
        if ($email->hasContent() && !empty($actualSubject)) {
            $sent = $email->send(true);
            $debugInfo['send_result'] = $sent;
        } else {
            $sent = false;
            $debugInfo['send_skipped'] = 'No content or subject';
        }
        
        return [
            'passed' => $sent,
            'message' => $sent ? 
                "Email sent with system-generated subject: $actualSubject" : 
                "Email not sent - check debug details",
            'details' => $debugInfo
        ];
        
    } catch (Exception $e) {
        return [
            'passed' => false,
            'message' => 'Test failed with exception: ' . $e->getMessage(),
        ];
    }
}
```

### 4. Create Test Template Fixture

Create a known test template to ensure consistent testing:

#### NEW: Create test template migration
```php
// In a new migration or test setup
$testTemplate = new EmailTemplateStore(NULL);
$testTemplate->set('emt_name', 'test_template_with_subject');
$testTemplate->set('emt_type', 2); // Inner template
$testTemplate->set('emt_body', 'subject: Test Email - *test_id*
<h1>Test Email</h1>
<p>This is a test email with ID: *test_id*</p>
<p>Timestamp: *timestamp*</p>');
$testTemplate->save();
```

### 5. Add Subject Priority Test

#### NEW TEST: testSubjectPriority()
```php
private function testSubjectPriority(): array {
    // Test that direct assignment overrides template subject
    $email = new EmailTemplate('test_template_with_subject');
    $settings = Globalvars::get_instance();
    $email->email_from = $settings->get_setting('defaultemail');
    $email->add_recipient('test@example.com', 'Test');
    
    $email->fill_template([
        'test_id' => 'ABC123',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    
    // Get the template-extracted subject
    $templateSubject = $email->getEmailSubject();
    
    // Now override it
    $overrideSubject = 'Override Subject - ' . uniqid();
    $email->email_subject = $overrideSubject;
    
    // Verify override worked
    $finalSubject = $email->getEmailSubject();
    
    return [
        'passed' => $finalSubject === $overrideSubject,
        'message' => 'Subject priority test',
        'details' => [
            'template_subject' => $templateSubject,
            'override_subject' => $overrideSubject,
            'final_subject' => $finalSubject,
            'override_worked' => $finalSubject === $overrideSubject,
        ]
    ];
}
```

## Testing Strategy

### Test Coverage Required

1. **Template with "subject:" line** - Verify extraction works
2. **Template without subject** - Verify no subject extracted
3. **Direct subject assignment** - Verify override works
4. **Template variable method** - Verify *subject* substitution
5. **Subject priority** - Verify direct assignment overrides template
6. **Empty subject handling** - Verify system behavior with no subject
7. **Malformed subjects** - Test edge cases like "Subject:" with no content

### Success Criteria

- All tests pass WITHOUT modifying system behavior
- Tests accurately reflect production email sending
- No direct subject overrides unless testing that specific feature
- Template subject extraction is primary test focus
- Edge cases are covered

## Implementation Steps

1. **Review existing templates** - Check which templates have "subject:" lines
2. **Create test fixtures** - Add known test templates with predictable subjects
3. **Update TemplateTests** - Remove subject overrides, test actual extraction
4. **Update DeliveryTests** - Remove subject overrides, test actual sending
5. **Add new test methods** - Implement subject-specific tests
6. **Run full test suite** - Ensure no regressions
7. **Document findings** - Update test documentation with results