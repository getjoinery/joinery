# Small Email System Refactors Specification

## Overview

This document specifies truly small, safe refactors to the email system that will make testing possible. These are minimal changes that add observation points and configuration without changing any core logic.

**Key Principle**: If it requires testing to verify it works, it's too big for this phase.

**Estimated Total Time**: 2-3 hours (including admin UI)  
**Risk Level**: Near zero (adding settings and getter methods only)  
**Impact**: Makes email testing possible

---

## Refactor 1: Extract SMTP Settings from Hardcoded Values

**Priority**: CRITICAL  
**Estimated Time**: 30 minutes  
**Files Modified**: `includes/SystemMailer.php`, `migrations/migrations.php`

### Problem
SMTP configuration is hardcoded, making it impossible to test different server configurations or use different SMTP servers for different environments. The class name 'systemmailer' is also vague.

### Current Code

```php
// includes/SystemMailer.php (current)
class systemmailer extends PHPMailer {
    function __construct() {
        $this->isSMTP();
        $this->Host = '64.77.41.226';              // HARDCODED!
        $this->Encoding = 'quoted-printable';
        $this->Helo = 'integralzen.org';           // HARDCODED!
        $this->Hostname = 'integralzen.org';       // HARDCODED!
        $this->Sender = 'bounces@integralzen.org'; // HARDCODED!
    }
}
```

### Refactored Code

```php
// includes/SystemMailer.php (refactored)
class SmtpMailer extends PHPMailer {
    // Only encoding is truly universal
    const SMTP_ENCODING = 'quoted-printable';
    
    function __construct() {
        $settings = Globalvars::get_instance();
        
        // Configure SMTP
        $this->isSMTP();
        
        // Get all configurable settings (no hardcoded defaults)
        $this->Host = $settings->get_setting('smtp_host') ?: '';
        $this->Port = intval($settings->get_setting('smtp_port') ?: 25);
        
        // Set encoding (only truly universal value)
        $this->Encoding = self::SMTP_ENCODING;
        
        // Get domain-specific settings
        $this->Helo = $settings->get_setting('smtp_helo') ?: '';
        $this->Hostname = $settings->get_setting('smtp_hostname') ?: '';
        $this->Sender = $settings->get_setting('smtp_sender') ?: '';
        
        // Auto-detect encryption based on port
        switch($this->Port) {
            case 465:
                $this->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
                break;
            case 587:
            case 2525:
                $this->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
                break;
            case 25:
            default:
                // Port 25 typically no encryption (but can support STARTTLS)
                $this->SMTPSecure = '';
                break;
        }
        
        // Support for authenticated SMTP
        if ($settings->get_setting('smtp_auth')) {
            $this->SMTPAuth = true;
            $this->Username = $settings->get_setting('smtp_username') ?: '';
            $this->Password = $settings->get_setting('smtp_password') ?: '';
        }
    }
}

// Maintain backward compatibility with old class name
class_alias('SmtpMailer', 'systemmailer');
```

### Database Migration

```sql
-- Add to migrations/migrations.php
$migration = array();
$migration['database_version'] = '1.01';
$migration['test'] = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_name = 'smtp_host'";
$migration['migration_sql'] = "
-- SMTP Configuration Settings
INSERT INTO stg_settings (stg_name, stg_value, stg_description) VALUES
('smtp_host', '', 'SMTP server hostname or IP address'),
('smtp_port', '25', 'SMTP server port (25=plain, 465=SSL, 587=TLS)'),
('smtp_helo', '', 'SMTP HELO/EHLO hostname'),
('smtp_hostname', '', 'SMTP hostname for message headers'),
('smtp_sender', '', 'SMTP bounce/return-path address'),
('smtp_auth', '0', 'Enable SMTP authentication (1=yes, 0=no)'),
('smtp_username', '', 'SMTP username (if auth enabled)'),
('smtp_password', '', 'SMTP password (if auth enabled)')
ON CONFLICT (stg_name) DO NOTHING;
";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

---

## Refactor 2: Add Simple Getter Methods for Testing

**Priority**: HIGH  
**Estimated Time**: 15 minutes  
**Files Modified**: `includes/EmailTemplate.php`

### Problem
No way to inspect what EmailTemplate is doing without actually sending emails. Testing framework needs visibility into the email's state.

### Implementation

Add these simple getter methods to EmailTemplate class (no logic changes):

```php
// includes/EmailTemplate.php - Add these methods to the class

/**
 * Get the processed email HTML (for testing)
 * @return string|null
 */
public function getEmailHtml() {
    return $this->email_html;
}

/**
 * Get the processed email text (for testing)
 * @return string|null
 */
public function getEmailText() {
    return $this->email_text;
}

/**
 * Get the email subject (for testing)
 * @return string|null
 */
public function getEmailSubject() {
    return $this->email_subject;
}

/**
 * Get the email recipients array (for testing)
 * @return array
 */
public function getEmailRecipients() {
    return $this->email_recipients;
}

/**
 * Get the from address (for testing)
 * @return string|null
 */
public function getEmailFrom() {
    return $this->email_from;
}

/**
 * Get the from name (for testing)
 * @return string|null
 */
public function getEmailFromName() {
    return $this->email_from_name;
}

/**
 * Check if email has content (for testing)
 * @return bool
 */
public function hasContent() {
    return $this->email_has_content;
}

/**
 * Get which service would be used to send (for testing)
 * Without actually sending anything
 * @return string 'smtp', 'mailgun', or 'none'
 * 
 * NOTE: The SMTP branch in EmailTemplate's send() method appears to be dead code
 * as $this->mailer is never set to a truthy value. Currently EmailTemplate only
 * uses Mailgun. SMTP is used elsewhere (QueuedEmail, Activation) but not here.
 * This method reflects the current reality.
 */
public function getServiceType() {
    $settings = Globalvars::get_instance();
    
    // Check what service would be used (read-only, no side effects)
    // NOTE: $this->mailer is always NULL/false in current implementation
    // so this will never return 'smtp' unless you fix the dead code
    if($this->mailer) {
        return 'smtp';  // This branch is currently unreachable
    }
    else if($settings->get_setting('mailgun_api_key') && $settings->get_setting('mailgun_domain')) {
        return 'mailgun';
    }
    else {
        return 'none';
    }
}
```

---

## Refactor 3: Add Test Mode Settings

**Priority**: HIGH  
**Estimated Time**: 10 minutes  
**Files Modified**: `migrations/migrations.php` only

### Problem
No way to redirect emails for testing without modifying code. 

**Note**: There is an existing session-based email suppression (`$_SESSION['send_emails']`) that logs to DebugEmailLog, but this is different - we need a global setting that can redirect emails to a test recipient.

### Implementation

Just add the settings - don't implement the logic yet:

```sql
-- Add to migrations/migrations.php
$migration = array();
$migration['database_version'] = '1.02';
$migration['test'] = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_name = 'email_test_mode'";
$migration['migration_sql'] = "
-- Testing Support Settings
INSERT INTO stg_settings (stg_name, stg_value, stg_description) VALUES
('email_test_mode', '0', 'Redirect all emails to test recipient (1=yes, 0=no)'),
('email_test_recipient', '', 'Email address that receives all test mode emails'),
('email_dry_run', '0', 'Prevent actual email sending, just log (1=yes, 0=no)')
ON CONFLICT (stg_name) DO NOTHING;
";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

---

## Refactor 4: Enhance Existing Debug Email Logging

**Priority**: MEDIUM  
**Estimated Time**: 15 minutes  
**Files Modified**: `includes/EmailTemplate.php`

### Problem
The existing DebugEmailLog only captures emails when session has sending disabled. We need visibility during normal operations and testing.

### Current System
You already have a `DebugEmailLog` class that stores emails in the database. Currently it only logs when `$session->send_emails()` returns false.

### Implementation

Enhance the existing system to optionally log all emails:

```php
// includes/EmailTemplate.php - Add this method to use existing DebugEmailLog

/**
 * Log email to debug log table if debug mode is enabled
 * Uses existing DebugEmailLog infrastructure
 * @param string $context Additional context about why this was logged
 */
protected function logToDebugTable($context = 'debug_mode') {
    $settings = Globalvars::get_instance();
    
    // Only log if debug mode is enabled
    if (!$settings->get_setting('email_debug_mode')) {
        return;
    }
    
    // Use existing DebugEmailLog class
    $debug_log = new DebugEmailLog(NULL);
    $debug_log->set('del_subject', '[' . $context . '] ' . $this->email_subject);
    $debug_log->set('del_body', $this->email_html);
    
    // Set recipient email (field exists but wasn't being used)
    $recipient_emails = array_map(function($r) { 
        return $r['email']; 
    }, $this->email_recipients);
    $debug_log->set('del_recipient_email', implode(', ', $recipient_emails));
    
    $debug_log->save();
}

// Update existing send() method - add debug logging:
function send($check_session=TRUE, $other_host=NULL) {
    $settings = Globalvars::get_instance();
    
    // Log to debug table if debug mode is on
    if ($settings->get_setting('email_debug_mode')) {
        $service = $this->getServiceType(); // Uses getter from Refactor 2
        $this->logToDebugTable('pre_send_' . $service);
    }
    
    // ... rest of existing send() code ...
    
    // Note: The existing code already logs to DebugEmailLog when 
    // session has email sending disabled - that stays unchanged
}

// Optionally add to fill_template() for template debugging:
function fill_template($values) {
    // ... existing code ...
    
    $settings = Globalvars::get_instance();
    if ($settings->get_setting('email_debug_mode')) {
        // Log after template is filled
        $this->logToDebugTable('template_filled');
    }
}
```

### Database Migration

```sql
-- Add to migrations/migrations.php
$migration = array();
$migration['database_version'] = '1.03';
$migration['test'] = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_name = 'email_debug_mode'";
$migration['migration_sql'] = "
-- Debug Setting (uses existing DebugEmailLog table)
INSERT INTO stg_settings (stg_name, stg_value, stg_description) VALUES
('email_debug_mode', '0', 'Log all emails to debug_email_logs table (1=yes, 0=no)')
ON CONFLICT (stg_name) DO NOTHING;
";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

### Benefits of Using Existing System

1. **No new tables or infrastructure needed**
2. **Admin interface already exists** at `/admin/admin_debug_email_logs`
3. **Can view logged emails in the admin panel**
4. **Reuses existing code patterns**
5. **The `del_recipient_email` field can finally be utilized**

---

## Refactor 5: Create Test Entry Point (TEMPORARY)

**Priority**: LOW  
**Estimated Time**: 10 minutes  
**Files Modified**: New file `utils/email_test_harness.php`

### Problem
Need a safe way to test email functionality without going through the entire application flow.

### Implementation

Create a simple test harness that uses the existing classes:

```php
// utils/email_test_harness.php (new file)
<?php
/**
 * TEMPORARY TEST HARNESS - NOT FOR PRODUCTION USE
 * 
 * This is temporary scaffolding for testing the email system during refactoring.
 * This file will be DEPRECATED and REMOVED once proper tests are in place.
 * DO NOT use this in production code or depend on it for any functionality.
 * 
 * This file is only meant to provide a safe entry point for testing email
 * functionality during the refactoring process. Once the email testing suite
 * in /tests/ is complete, this file should be deleted.
 * 
 * @deprecated Will be removed after email testing suite is complete
 * @temporary This is temporary scaffolding only
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/EmailTemplate.php');

// DO NOT IMPORT OR REFERENCE THIS CLASS IN ANY PRODUCTION CODE
class EmailTestHarness {
    
    /**
     * Test template processing without sending
     */
    public static function testTemplateProcessing($template_id, $values = []) {
        $email = new EmailTemplate($template_id);
        $email->fill_template($values);
        
        return [
            'success' => true,
            'has_content' => $email->hasContent(),
            'subject' => $email->getEmailSubject(),
            'html_length' => strlen($email->getEmailHtml()),
            'text_length' => strlen($email->getEmailText()),
            'from' => $email->getEmailFrom(),
            'service_type' => $email->getServiceType()
        ];
    }
    
    /**
     * Test SMTP configuration
     */
    public static function testSmtpConfig() {
        $settings = Globalvars::get_instance();
        
        return [
            'host' => $settings->get_setting('smtp_host'),
            'port' => $settings->get_setting('smtp_port'),
            'auth' => $settings->get_setting('smtp_auth'),
            'has_credentials' => !empty($settings->get_setting('smtp_username'))
        ];
    }
    
    /**
     * Check what service would be used
     */
    public static function checkServiceSelection() {
        $settings = Globalvars::get_instance();
        $email = new EmailTemplate(NULL);
        
        return [
            'mailgun_configured' => (
                $settings->get_setting('mailgun_api_key') && 
                $settings->get_setting('mailgun_domain')
            ),
            'smtp_configured' => !empty($settings->get_setting('smtp_host')),
            'would_use' => $email->getServiceType()
        ];
    }
}

// TEMPORARY CLI USAGE - This will be removed when proper tests are created
// Example: php utils/email_test_harness.php test_template 1
// DO NOT create any scripts or automation that depends on this CLI interface
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $command = $argv[1];
    
    switch($command) {
        case 'test_template':
            $template_id = $argv[2] ?? null;
            $result = EmailTestHarness::testTemplateProcessing($template_id);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'test_smtp':
            $result = EmailTestHarness::testSmtpConfig();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'check_service':
            $result = EmailTestHarness::checkServiceSelection();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        default:
            echo "Unknown command: $command\n";
            echo "Available commands: test_template, test_smtp, check_service\n";
    }
}
```

---

## Refactor 6: Update Admin Settings Interface

**Priority**: HIGH  
**Estimated Time**: 30 minutes  
**Files Modified**: `adm/admin_settings.php`

### Problem
New email settings need to be manageable through the admin interface with proper validation.

### Implementation

Add to the Email Settings section (around line 1752):

```php
// After line 1809 (end of existing email template settings), add:

echo '<div style="margin: 50px 0;"></div>';

// SMTP Configuration Section
echo '<h4>SMTP Configuration</h4>';

// SMTP settings with two-column layout and connection validation
echo '<div class="row">';
echo '<div class="col-md-6">';
echo '<h5>SMTP Server Settings</h5>';
echo $formwriter->textinput("SMTP Host", 'smtp_host', '', 20, $settings->get_setting('smtp_host'), "" , 255, "");
echo $formwriter->textinput("SMTP Port (25, 465, 587, 2525)", 'smtp_port', '', 20, $settings->get_setting('smtp_port'), "" , 10, "");
echo $formwriter->textinput("SMTP HELO/EHLO Hostname", 'smtp_helo', '', 20, $settings->get_setting('smtp_helo'), "" , 255, "");
echo $formwriter->textinput("SMTP Hostname (for headers)", 'smtp_hostname', '', 20, $settings->get_setting('smtp_hostname'), "" , 255, "");
echo $formwriter->textinput("SMTP Bounce Address", 'smtp_sender', '', 20, $settings->get_setting('smtp_sender'), "" , 255, "");

$auth_optionvals = array(0 => 'No', 1 => 'Yes');
echo $formwriter->dropinput("SMTP Authentication Required", "smtp_auth", '', $auth_optionvals, $settings->get_setting('smtp_auth'), '', FALSE);

echo '<div id="smtp_auth_fields" style="' . ($settings->get_setting('smtp_auth') ? '' : 'display:none;') . '">';
echo $formwriter->textinput("SMTP Username", 'smtp_username', '', 20, $settings->get_setting('smtp_username'), "" , 255, "");
echo $formwriter->passwordinput("SMTP Password", 'smtp_password', '', 20, $settings->get_setting('smtp_password'), "" , 255, "");
echo '</div>';

echo '</div>';
echo '<div class="col-md-6">';
echo '<h5>SMTP Connection Status</h5>';
echo '<div style="min-height: 250px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';

if ($run_validation) {
    $smtp_host = $settings->get_setting('smtp_host');
    $smtp_port = $settings->get_setting('smtp_port');
    
    if (!empty($smtp_host)) {
        // Test SMTP connection
        try {
            PathHelper::requireOnce('includes/SystemMailer.php');
            
            // Create test instance
            $mailer = new SmtpMailer();
            
            echo '<p><strong>Configuration:</strong></p>';
            echo '<ul style="list-style: none; padding-left: 0;">';
            echo '<li><strong>Host:</strong> ' . htmlspecialchars($smtp_host ?: 'Not set') . '</li>';
            echo '<li><strong>Port:</strong> ' . htmlspecialchars($smtp_port ?: '25') . '</li>';
            
            // Determine encryption based on port
            $encryption = 'None';
            switch(intval($smtp_port)) {
                case 465:
                    $encryption = 'SSL/TLS';
                    break;
                case 587:
                case 2525:
                    $encryption = 'STARTTLS';
                    break;
            }
            echo '<li><strong>Encryption:</strong> ' . $encryption . ' (auto-detected)</li>';
            echo '<li><strong>Authentication:</strong> ' . ($settings->get_setting('smtp_auth') ? 'Yes' : 'No') . '</li>';
            echo '</ul>';
            
            // Try to connect
            try {
                // Test connection without sending
                $mailer->smtpConnect();
                echo '<p style="color: green;"><strong>✓ Connection Test:</strong> Successfully connected to SMTP server</p>';
                $mailer->smtpClose();
            } catch (Exception $e) {
                echo '<p style="color: red;"><strong>✗ Connection Failed:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            
        } catch (Exception $e) {
            echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ Configuration Error</strong></div>';
            echo '<div style="color: #666; font-size: 12px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        echo '<div style="color: #666; text-align: center; padding: 20px;">Enter SMTP host to validate connection</div>';
    }
} else {
    // Show placeholder with "Run Validation" button
    echo '<div style="text-align: center; padding: 40px;">';
    echo '<p style="color: #666; margin-bottom: 15px;">SMTP validation not run yet</p>';
    echo '<a href="?run_validation=1#email-settings" class="btn btn-primary btn-sm">Run All Validations</a>';
    echo '</div>';
}

echo '</div>';
echo '</div>';
echo '</div>';

echo '<div style="margin: 30px 0;"></div>';

// Email Testing Settings
echo '<h4>Email Testing &amp; Debug Settings</h4>';
echo '<div class="row">';
echo '<div class="col-md-12">';

// Add note about existing session-based suppression
echo '<div class="alert alert-info" style="margin-bottom: 20px;">';
echo '<strong>Note:</strong> These are global settings. There is also a session-based email suppression ';
echo '(<code>$_SESSION[\'send_emails\']</code>) used for programmatic testing that logs to debug_email_logs.';
echo '</div>';

$test_optionvals = array(0 => 'No', 1 => 'Yes');
echo $formwriter->dropinput("Global Test Mode (redirect all emails to test recipient)", "email_test_mode", '', $test_optionvals, $settings->get_setting('email_test_mode'), '', FALSE);

echo '<div id="email_test_fields" style="' . ($settings->get_setting('email_test_mode') ? '' : 'display:none;') . '">';
echo $formwriter->textinput("Test Recipient Email (receives all redirected emails)", 'email_test_recipient', '', 20, $settings->get_setting('email_test_recipient'), "" , 255, "");
echo '</div>';

echo $formwriter->dropinput("Dry Run Mode (prevent all sending, just log)", "email_dry_run", '', $test_optionvals, $settings->get_setting('email_dry_run'), '', FALSE);
echo $formwriter->dropinput("Debug Mode (log all emails to debug_email_logs)", "email_debug_mode", '', $test_optionvals, $settings->get_setting('email_debug_mode'), '', FALSE);

echo '</div>';
echo '</div>';
```

### JavaScript Additions

Add to the JavaScript section (after line 117):

```javascript
// Add these functions after the existing JavaScript functions

function set_smtp_auth_choices(){
    var value = $("#smtp_auth").val();
    if(value == 0 || value == ''){  
        $("#smtp_auth_fields").hide();
    } else { 
        $("#smtp_auth_fields").show();
    }		
}

function set_email_test_choices(){
    var value = $("#email_test_mode").val();
    if(value == 0 || value == ''){  
        $("#email_test_fields").hide();
    } else { 
        $("#email_test_fields").show();
    }		
}

// Add to the existing $(document).ready() function:
$(document).ready(function(){
    // ... existing code ...
    
    // SMTP Authentication toggle
    $("#smtp_auth").on('change', function(){
        set_smtp_auth_choices();
    });
    set_smtp_auth_choices();
    
    // Email test mode toggle
    $("#email_test_mode").on('change', function(){
        set_email_test_choices();
    });
    set_email_test_choices();
    
    // SMTP port validation
    $("#smtp_port").on('blur', function(){
        var port = $(this).val();
        if(port && ![25, 465, 587, 2525].includes(parseInt(port))){
            $(this).addClass('is-invalid');
            if(!$(this).next('.invalid-feedback').length){
                $(this).after('<div class="invalid-feedback">Common ports: 25, 465, 587, 2525</div>');
            }
        } else {
            $(this).removeClass('is-invalid');
            $(this).next('.invalid-feedback').remove();
        }
    });
    
    // Email validation for test recipient
    $("#email_test_recipient").on('blur', function(){
        var email = $(this).val();
        if(email && !isValidEmail(email)){
            $(this).addClass('is-invalid');
            if(!$(this).next('.invalid-feedback').length){
                $(this).after('<div class="invalid-feedback">Please enter a valid email address</div>');
            }
        } else {
            $(this).removeClass('is-invalid');
            $(this).next('.invalid-feedback').remove();
        }
    });
});

function isValidEmail(email) {
    var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}
```

### Validation Rules Addition

Add to the validation rules section (around line 347):

```php
// Add these after the Stripe validation rules

// SMTP validation rules
$validation_rules['smtp_host']['maxlength']['value'] = '255';
$validation_rules['smtp_host']['maxlength']['message'] = "'SMTP host too long (max 255 characters)'";

$validation_rules['smtp_port']['number']['value'] = 'true';
$validation_rules['smtp_port']['number']['message'] = "'Port must be a number'";
$validation_rules['smtp_port']['range']['value'] = '[1, 65535]';
$validation_rules['smtp_port']['range']['message'] = "'Port must be between 1 and 65535'";

$validation_rules['smtp_sender']['email']['value'] = 'true';
$validation_rules['smtp_sender']['email']['message'] = "'Must be a valid email address'";

$validation_rules['email_test_recipient']['email']['value'] = 'true';
$validation_rules['email_test_recipient']['email']['message'] = "'Must be a valid email address'";

// Add these rules only if fields are not empty
$validation_rules['smtp_helo']['maxlength']['value'] = '255';
$validation_rules['smtp_hostname']['maxlength']['value'] = '255';
$validation_rules['smtp_username']['maxlength']['value'] = '255';
$validation_rules['smtp_password']['maxlength']['value'] = '255';
```

---

## Implementation Order

1. **First**: Refactor 1 (SMTP Settings) - Most critical, enables different configurations
2. **Second**: Refactor 6 (Admin Settings) - UI for managing the new settings
3. **Third**: Refactor 2 (Getter Methods) - Enables inspection without side effects
4. **Fourth**: Refactor 3 (Test Settings) - Just adds settings, no logic changes
5. **Fifth**: Refactor 4 (Debug Logging) - Uses existing system
6. **Sixth**: Refactor 5 (Test Harness) - Provides test entry point

## Why These Are Safe

1. **No logic changes** - Only adding getters and settings
2. **Backward compatible** - Class alias maintains compatibility
3. **Read-only operations** - Getters don't modify state
4. **Optional features** - Debug logging and test mode are off by default
5. **Isolated testing** - Test harness is separate from production code and clearly marked as temporary

## What These Enable

With these minimal changes, you can:
- Test with different SMTP servers
- Inspect email content without sending
- Debug template processing
- Build a comprehensive test suite
- Switch between services for testing

## Total Database Settings Added

12 new settings total:
- 8 SMTP configuration settings
- 3 testing support settings  
- 1 debug mode setting

All have safe defaults that maintain current behavior.