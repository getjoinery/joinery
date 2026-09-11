# Simplified Email Service Architecture - Test Mode Implementation

## Overview

This specification details a minimal refactoring to add test mode capability to the existing EmailTemplate system. With Phase 1 complete (SMTP working, service selection, fallback logic), the only missing piece for a complete email system is the ability to test without sending real emails. This approach achieves 95% of the desired functionality with less than 5% of the work required for a full architectural refactor.

## Current State Analysis

The existing EmailTemplate class (811 lines) successfully handles:
- ✅ Service selection (Mailgun vs SMTP)
- ✅ Automatic fallback when primary service fails
- ✅ Template processing and merging
- ✅ Debug logging
- ✅ Queue failed emails for retry

The only significant gap is local development/testing without real email delivery.

## Proposed Implementation

### 1. Add Test Service Method to EmailTemplate

**Purpose**: Create a test mode that captures emails without sending them, enabling local development and automated testing without email service credentials or accidental email delivery.

**Implementation**: Add a new method to the existing EmailTemplate class:

```php
// In EmailTemplate.php, add this new method:
private function sendViaTest() {
    try {
        // Build test email data structure
        $testEmail = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message_id' => 'test_' . uniqid(),
            'from' => $this->email_from,
            'from_name' => $this->email_from_name,
            'recipients' => $this->email_recipients,
            'subject' => $this->email_subject,
            'html_body' => $this->email_html,
            'text_body' => $this->email_text,
            'template_name' => $this->template_name ?? 'unknown',
            'service' => 'test',
            'test_mode' => true
        ];
        
        // Option A: Save to file (simplest)
        $testLogFile = $this->settings->get_setting('email_test_log_file') ?: '/tmp/test_emails.json';
        $logDir = dirname($testLogFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        file_put_contents(
            $testLogFile,
            json_encode($testEmail) . "\n",
            FILE_APPEND | LOCK_EX
        );
        
        // Option B: Save to database (better for UI access)
        if ($this->settings->get_setting('email_test_save_to_db') == '1') {
            $this->saveTestEmailToDatabase($testEmail);
        }
        
        // Log success
        $this->logEmailDebug(
            sprintf("TEST MODE: Email captured - Subject: %s, To: %s", 
                $this->email_subject,
                implode(', ', array_column($this->email_recipients, 'email'))
            ),
            'test'
        );
        
        // Optionally redirect to test address
        if ($testRecipient = $this->settings->get_setting('email_test_recipient')) {
            return $this->sendTestEmailToRealAddress($testRecipient, $testEmail);
        }
        
        return true;
        
    } catch (Exception $e) {
        $this->logEmailDebug("TEST MODE ERROR: " . $e->getMessage(), 'test');
        return false;
    }
}

// Optional: Save test emails to database for UI access
private function saveTestEmailToDatabase($testEmail) {
    try {
        // Could use debug_email_logs table or create a test_emails table
        PathHelper::requireOnce('data/debug_email_logs_class.php');
        
        $log = new DebugEmailLog(NULL);
        $log->set('del_timestamp', $testEmail['timestamp']);
        $log->set('del_subject', $testEmail['subject']);
        $log->set('del_recipient', implode(', ', array_column($testEmail['recipients'], 'email')));
        $log->set('del_service', 'test');
        $log->set('del_message', 'TEST MODE: Email captured');
        $log->set('del_status', 'test');
        $log->set('del_metadata', json_encode($testEmail));
        $log->save();
        
    } catch (Exception $e) {
        error_log("Failed to save test email to database: " . $e->getMessage());
    }
}

// Optional: Send to real test address with modified subject
private function sendTestEmailToRealAddress($testRecipient, $testEmail) {
    // Save current values
    $originalRecipients = $this->email_recipients;
    $originalSubject = $this->email_subject;
    
    try {
        // Modify for test sending
        $this->email_recipients = [
            ['email' => $testRecipient, 'name' => 'Test Recipient']
        ];
        
        // Show original recipients in subject
        $originalTo = implode(', ', array_column($originalRecipients, 'email'));
        $this->email_subject = "[TEST to: $originalTo] " . $originalSubject;
        
        // Send via real service (SMTP usually)
        $result = $this->sendViaSmtp();
        
        // Restore original values
        $this->email_recipients = $originalRecipients;
        $this->email_subject = $originalSubject;
        
        return $result;
        
    } catch (Exception $e) {
        // Restore original values
        $this->email_recipients = $originalRecipients;
        $this->email_subject = $originalSubject;
        
        $this->logEmailDebug("TEST MODE: Failed to send to test recipient: " . $e->getMessage(), 'test');
        return true; // Still return true since we captured the email
    }
}
```

### 2. Update sendWithService Method

**Purpose**: Integrate test mode into the existing service selection logic.

**Implementation**: Modify the existing sendWithService method:

```php
// In EmailTemplate.php, update the existing method:
public function sendWithService($service) {
    switch(strtolower($service)) {
        case 'test':
            return $this->sendViaTest();
            
        case 'smtp':
            if (!$this->initializeSmtp($this->settings)) {
                $this->logEmailDebug("Failed to initialize SMTP", 'smtp');
                return false;
            }
            return $this->sendViaSmtp();
            
        case 'mailgun':
            if (!$this->initializeMailgun($this->settings)) {
                $this->logEmailDebug("Failed to initialize Mailgun", 'mailgun');
                return false;
            }
            return $this->sendViaMailgun();
            
        default:
            $this->logEmailDebug("Unknown email service: $service", 'error');
            return false;
    }
}
```

### 3. Add Service Validation Method

**Purpose**: Provide a simple way to check if email services are properly configured.

**Implementation**: Add a validation method to EmailTemplate:

```php
// In EmailTemplate.php, add:
public function validateServiceConfiguration($service = null) {
    if (!$service) {
        $service = $this->settings->get_setting('email_service') ?: 'mailgun';
    }
    
    $result = [
        'service' => $service,
        'valid' => false,
        'errors' => [],
        'warnings' => []
    ];
    
    switch(strtolower($service)) {
        case 'test':
            $result['valid'] = true;
            $logFile = $this->settings->get_setting('email_test_log_file') ?: '/tmp/test_emails.json';
            $logDir = dirname($logFile);
            
            if (!is_writable($logDir)) {
                $result['warnings'][] = "Test log directory not writable: $logDir";
            }
            
            if ($testRecipient = $this->settings->get_setting('email_test_recipient')) {
                $result['info'][] = "Test emails will be sent to: $testRecipient";
            }
            break;
            
        case 'smtp':
            $host = $this->settings->get_setting('smtp_host');
            $port = $this->settings->get_setting('smtp_port');
            
            if (empty($host)) {
                $result['errors'][] = 'SMTP host not configured';
            }
            if (empty($port)) {
                $result['errors'][] = 'SMTP port not configured';
            }
            
            if (empty($result['errors'])) {
                // Try to connect
                $fp = @fsockopen($host, $port, $errno, $errstr, 5);
                if ($fp) {
                    fclose($fp);
                    $result['valid'] = true;
                } else {
                    $result['errors'][] = "Cannot connect to SMTP server: $errstr";
                }
            }
            break;
            
        case 'mailgun':
            if (empty($this->settings->get_setting('mailgun_api_key'))) {
                $result['errors'][] = 'Mailgun API key not configured';
            }
            if (empty($this->settings->get_setting('mailgun_domain'))) {
                $result['errors'][] = 'Mailgun domain not configured';
            }
            
            $result['valid'] = empty($result['errors']);
            break;
            
        default:
            $result['errors'][] = "Unknown service: $service";
    }
    
    return $result;
}
```

### 4. Add Configuration Settings

**Purpose**: Allow test mode to be configured through system settings.

**Database Migration**:

```php
// In migrations/migrations.php
$migration = array();
$migration['database_version'] = '0.54';
$migration['test'] = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_setting = 'email_test_recipient'";
$migration['migration_sql'] = "
    -- Add test mode settings
    INSERT INTO stg_settings (stg_setting, stg_value, stg_description) VALUES
    ('email_test_log_file', '/tmp/test_emails.json', 'File path for test email logging'),
    ('email_test_recipient', '', 'Email address to receive all test emails (optional)'),
    ('email_test_save_to_db', '0', 'Save test emails to database (1=yes, 0=no)')
    ON CONFLICT (stg_setting) DO NOTHING;
";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

### 5. Create Test Email Viewer (Optional)

**Purpose**: Provide a simple UI to view captured test emails.

**Implementation**: Create a basic viewer page:

```php
// New file: /tests/email/test_viewer.php
<?php
require_once('../../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');

$settings = Globalvars::get_instance();
$logFile = $settings->get_setting('email_test_log_file') ?: '/tmp/test_emails.json';

// Read test emails
$testEmails = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $email = json_decode($line, true);
        if ($email) {
            $testEmails[] = $email;
        }
    }
    // Show most recent first
    $testEmails = array_reverse($testEmails);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Email Viewer</title>
    <style>
        body { font-family: monospace; margin: 20px; }
        .email { border: 1px solid #ccc; margin: 10px 0; padding: 10px; }
        .email-header { background: #f0f0f0; padding: 5px; margin: -10px -10px 10px -10px; }
        .email-body { margin-top: 10px; }
        .controls { margin-bottom: 20px; }
        button { padding: 5px 10px; }
    </style>
</head>
<body>
    <h1>Test Email Viewer</h1>
    
    <div class="controls">
        <button onclick="clearEmails()">Clear All</button>
        <button onclick="location.reload()">Refresh</button>
        <span><?php echo count($testEmails); ?> test emails</span>
    </div>
    
    <?php foreach ($testEmails as $email): ?>
    <div class="email">
        <div class="email-header">
            <strong><?php echo htmlspecialchars($email['timestamp']); ?></strong> - 
            <?php echo htmlspecialchars($email['subject']); ?>
        </div>
        <div>
            <strong>To:</strong> 
            <?php 
            $recipients = array_map(function($r) { 
                return $r['email']; 
            }, $email['recipients']);
            echo htmlspecialchars(implode(', ', $recipients)); 
            ?>
        </div>
        <div>
            <strong>From:</strong> 
            <?php echo htmlspecialchars($email['from']); ?>
            <?php if ($email['from_name']): ?>
                (<?php echo htmlspecialchars($email['from_name']); ?>)
            <?php endif; ?>
        </div>
        <div class="email-body">
            <details>
                <summary>HTML Body</summary>
                <iframe srcdoc="<?php echo htmlspecialchars($email['html_body']); ?>" 
                        style="width: 100%; height: 300px; border: 1px solid #ccc;"></iframe>
            </details>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($testEmails)): ?>
    <p>No test emails captured yet.</p>
    <?php endif; ?>
    
    <script>
    function clearEmails() {
        if (confirm('Clear all test emails?')) {
            fetch('test_viewer.php?action=clear', {method: 'POST'})
                .then(() => location.reload());
        }
    }
    </script>
</body>
</html>

<?php
// Handle clear action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['action'] === 'clear') {
    file_put_contents($logFile, '');
    exit;
}
?>
```

## Usage Examples

### Local Development

```php
// In development environment, set in Globalvars_site.php:
$settings->set_setting('email_service', 'test');
$settings->set_setting('email_test_log_file', '/tmp/dev_emails.json');

// All emails will be captured instead of sent
$email = new EmailTemplate('activation_content');
$email->add_recipient('user@example.com');
$email->fill_template(['act_code' => 'ABC123']);
$email->send(); // Goes to test file, not real email
```

### Automated Testing

```php
// In test suite
class EmailTests {
    public function setUp() {
        $this->settings->set_setting('email_service', 'test');
        $this->testFile = '/tmp/test_' . uniqid() . '.json';
        $this->settings->set_setting('email_test_log_file', $this->testFile);
    }
    
    public function testActivationEmail() {
        // Send email
        $email = new EmailTemplate('activation_content');
        $email->add_recipient('test@example.com');
        $email->fill_template(['act_code' => 'TEST123']);
        $result = $email->send();
        
        // Verify it was captured
        $this->assertTrue($result);
        
        // Check captured email
        $captured = json_decode(file_get_contents($this->testFile), true);
        $this->assertEquals('test@example.com', $captured['recipients'][0]['email']);
        $this->assertStringContainsString('TEST123', $captured['html_body']);
    }
}
```

### Development with Real Email Preview

```php
// Set up to send all test emails to developer
$settings->set_setting('email_service', 'test');
$settings->set_setting('email_test_recipient', 'developer@company.com');

// Now all emails go to developer with original recipient in subject
$email->send(); // Sends to developer@company.com with subject: "[TEST to: user@example.com] Original Subject"
```

## Benefits of This Approach

1. **Minimal Code Changes**: Add ~150 lines to existing EmailTemplate class
2. **Zero Breaking Changes**: Existing code continues to work exactly as before
3. **Immediate Value**: Developers can start using test mode immediately
4. **Easy Testing**: Automated tests can verify email content without sending
5. **Simple Configuration**: Just change one setting to enable test mode
6. **Preserves Investment**: All Phase 1 work remains valuable and active

## Implementation Timeline

- **Hour 1**: Add sendViaTest() method
- **Hour 2**: Update sendWithService() and add validation
- **Hour 3**: Add database migration and test viewer
- **Hour 4**: Testing and documentation

Total: **4 hours of development**

## Comparison with Full Architecture

| Aspect | This Approach | Full Architecture |
|--------|--------------|-------------------|
| Implementation Time | 4 hours | 2-3 weeks |
| Lines of Code | ~150 | ~2000+ |
| Files Changed | 1-2 | 15+ |
| Testing Required | Minimal | Extensive |
| Risk of Bugs | Very Low | Moderate |
| Backward Compatibility | 100% | Requires migration |
| Achieves Core Goals | 95% | 100% |

## Migration Path

If you later decide you need the full architecture:

1. This test mode can remain as-is
2. The validation method provides a foundation for service health checks
3. The test viewer could be enhanced into a full email management UI
4. No work is wasted - this becomes the TestService in the full architecture

## Success Criteria

- [ ] Test mode captures emails without sending
- [ ] Developers can work without email credentials
- [ ] Automated tests can verify email content
- [ ] Optional redirect to test recipient works
- [ ] No impact on production email sending
- [ ] Validation method correctly reports service status

## Conclusion

This minimal approach solves the last remaining gap in your email system (local development/testing) with minimal risk and effort. It preserves all your Phase 1 improvements while adding the one critical missing feature. The 4-hour investment provides 95% of the value of a full architectural refactor that would take weeks to implement and stabilize.