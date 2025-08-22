# Email System Refactoring Specification

## Executive Summary

The current email system has evolved organically, resulting in tightly coupled components. This specification outlines a phased refactoring approach, starting with immediate fixes to restore SMTP functionality and add service selection, then moving toward a comprehensive flexible, maintainable, and extensible email infrastructure.

## Current System Analysis

### Remaining Key Problems

1. **EmailTemplate.php** (811 lines) still handles:
   - Database template loading
   - Template merging (inner/outer/footer)
   - Variable substitution
   - Conditional processing
   - UTM tracking injection
   - Email sending logic
   - Service selection (Mailgun vs SMTP)

2. **Service Selection** is still scattered:
   - EmailTemplate checks for Mailgun settings
   - Falls back to SmtpMailer
   - No abstraction layer

3. **Dead Code Issue** (PHASE 1 PRIORITY):
   - **EmailTemplate's SMTP branch is dead code** - the `$this->mailer` property is never set to a truthy value
   - The check `if($this->mailer)` on line 680 always evaluates to false
   - **EmailTemplate only uses Mailgun** in practice, never SMTP
   - SMTP IS used elsewhere in the system:
     - `QueuedEmail` class uses SmtpMailer for retry logic
     - `Activation` class uses SmtpMailer directly
     - Test scripts use SmtpMailer

## Phase 1: Immediate Fixes (Priority Implementation)

### 1.1 Add Email Service Settings

Add these settings to the database immediately:

```sql
-- Core email service settings
INSERT INTO stg_settings (stg_name, stg_value, stg_description) VALUES
('email_service', 'mailgun', 'Active email service: mailgun or smtp'),
('email_fallback_service', 'smtp', 'Fallback service if primary fails: mailgun or smtp')
ON CONFLICT (stg_name) DO UPDATE 
SET stg_value = EXCLUDED.stg_value,
    stg_description = EXCLUDED.stg_description;

-- Note: SMTP settings already exist in system from previous migrations:
-- smtp_host - SMTP server hostname  
-- smtp_port - SMTP server port (default: 25)
-- smtp_hostname - SMTP HELO/EHLO hostname
-- smtp_username - SMTP authentication username
-- smtp_password - SMTP authentication password
-- Note: smtp_encryption is NOT in database, will use port to determine (465=SSL, 587=TLS, 25=none)
```

### 1.2 Fix Dead SMTP Code in EmailTemplate

The core issue: The `$this->mailer` property is never set, making the SMTP branch at line 680 unreachable. 

Solution: Modify the send() method to use the new service selection methods (defined in 1.3 and 1.4 below):

```php
// In EmailTemplate.php send() method, replace the beginning with:
function send($check_session=TRUE, $other_host=NULL) {
    $settings = Globalvars::get_instance();
    
    // NEW: Use service selection with fallback (see methods in 1.3 and 1.4)
    $service = $settings->get_setting('email_service') ?: 'mailgun';
    $fallback = $settings->get_setting('email_fallback_service') ?: 'smtp';
    
    $primary_result = $this->sendWithService($service);
    
    if (!$primary_result && $fallback && $fallback !== $service) {
        $this->logEmailDebug("Primary service $service failed, trying fallback $fallback");
        $fallback_result = $this->sendWithService($fallback);
        
        if ($fallback_result) {
            $this->logEmailDebug("Fallback service $fallback succeeded");
            return true;
        }
        
        // Both services failed - already queued by sendViaSmtp/sendViaMailgun
        $this->logEmailDebug("Both primary and fallback services failed, email queued for retry");
    }
    
    return $primary_result;
}

// Note: When both services fail, the email is automatically saved to equ_queued_emails 
// by the sendViaSmtp() and sendViaMailgun() methods (see 1.4 below).
// Future Phase 2 will implement automatic retry from the queue.
```

### 1.3 Add Service Initialization Methods

Add methods to initialize each email service:

```php
// Add new methods to EmailTemplate.php
private function initializeSmtp($settings) {
    PathHelper::requireOnce('includes/SmtpMailer.php');
    $this->mailer = new SmtpMailer();
    
    // Configure SMTP settings
    $this->mailer->isSMTP();
    $this->mailer->Host = $settings->get_setting('smtp_host');
    $port = intval($settings->get_setting('smtp_port') ?: 25);
    $this->mailer->Port = $port;
    
    // Set SMTP authentication if credentials provided
    if ($settings->get_setting('smtp_username') && $settings->get_setting('smtp_password')) {
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $settings->get_setting('smtp_username');
        $this->mailer->Password = $settings->get_setting('smtp_password');
    } else {
        $this->mailer->SMTPAuth = false;
    }
    
    // Determine encryption based on port (matching SmtpMailer.php logic)
    if ($port == 465) {
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
    } else if ($port == 587 || $port == 2525) {
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
    } else {
        $this->mailer->SMTPSecure = ''; // No encryption
    }
    
    // Set HELO/EHLO hostname if configured
    if ($settings->get_setting('smtp_hostname')) {
        $this->mailer->Hostname = $settings->get_setting('smtp_hostname');
    }
    
    // Set email properties
    $this->mailer->From = $this->email_from;
    $this->mailer->FromName = $this->email_from_name;
    $this->mailer->Subject = $this->email_subject;
    
    // Add recipients
    foreach ($this->email_recipients as $recipient) {
        $this->mailer->addAddress($recipient['email'], $recipient['name']);
    }
    
    return true;
}

private function initializeMailgun($settings) {
    // Check if Mailgun is configured
    if (empty($settings->get_setting('mailgun_domain')) || 
        empty($settings->get_setting('mailgun_api_key'))) {
        return false;
    }
    // Mailgun will be used via existing code path
    $this->mailer = null; // Ensure Mailgun path is taken
    return true;
}
```

### 1.4 Add Service Sending Methods

Add method to send with specific service:

```php
// Add new method to EmailTemplate.php
private function sendWithService($service) {
    $settings = Globalvars::get_instance();
    
    switch($service) {
        case 'smtp':
            if (!$this->initializeSmtp($settings)) {
                return false;
            }
            // Use existing SMTP sending code (around line 680)
            return $this->sendViaSmtp();
            
        case 'mailgun':
            if (!$this->initializeMailgun($settings)) {
                return false;
            }
            // Use existing Mailgun sending code
            return $this->sendViaMailgun();
            
        default:
            $this->logEmailDebug("Unknown email service: $service");
            return false;
    }
}

// Extract existing SMTP sending logic into its own method
private function sendViaSmtp() {
    // Set email body content
    $this->mailer->isHTML(true);
    $this->mailer->Body = $this->email_html;
    $this->mailer->AltBody = $this->email_text;
    
    // Send the email
    if (!$this->mailer->send()) {
        $this->logEmailDebug("SMTP send failed: " . $this->mailer->ErrorInfo, 'smtp');
        // Save to queue for retry
        $this->save_email_as_queued(NULL, QueuedEmail::NORMAL_MAILER_ERROR);
        return false;
    }
    
    $this->logEmailDebug("Email sent successfully via SMTP", 'smtp');
    return true;
}

// Extract existing Mailgun sending logic into its own method  
private function sendViaMailgun() {
    $settings = Globalvars::get_instance();
    
    // Initialize Mailgun client
    if($settings->get_setting('mailgun_version') == 1){
        if($settings->get_setting('mailgun_eu_api_link')){
            $mg = new Mailgun($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
        }
        else{
            $mg = new Mailgun($settings->get_setting('mailgun_api_key'));
        }
    }
    else{
        if($settings->get_setting('mailgun_eu_api_link')){
            $mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
        }
        else{
            $mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
        }
    }
    
    $domain = $settings->get_setting('mailgun_domain');	
    
    // Build email array
    $email_to_send = array(
        'from'=>$this->email_from_name .'<'. $this->email_from . '>',
        'subject' => $this->email_subject,
    );
        
    if($this->email_html){
        $email_to_send['html'] = $this->email_html;
        $email_to_send['text'] = $this->email_text;
    }
    else{
        $email_to_send['text'] = $this->email_text;
    }

    // Send in batches of 500 recipients
    $sending_groups = array_chunk($this->email_recipients, 500, true);
    $all_sent = true;

    foreach ($sending_groups as $sending_group){
        $mailgun_recipients = array();	
        $recipient_variables = array();
        
        foreach($sending_group as $recipient){
            $mailgun_recipients[] = $recipient['name'] . '<' . $recipient['email'] . '>';		
            $recipient_variables[$recipient['email']] = array('name'=>$recipient['name']);
        }
        $email_to_send['to'] = implode(',', $mailgun_recipients);							
        $email_to_send['recipient-variables'] = json_encode($recipient_variables);			
        
        try{
            if($settings->get_setting('mailgun_version') == 1){
                $result = $mg->sendMessage($domain, $email_to_send);
            }
            else{
                $result = $mg->messages()->send($domain, $email_to_send);
            }
            $this->logEmailDebug("Email batch sent successfully via Mailgun", 'mailgun');
        }
        catch (Exception $e) {
            $this->logEmailDebug("Mailgun send failed: " . $e->getMessage(), 'mailgun');
            // Save to queue for retry
            $this->save_email_as_queued(NULL, QueuedEmail::NORMAL_MAILER_ERROR);
            $all_sent = false;
        }
    }
    
    return $all_sent;
}
```

### 1.5 Enhance Debug Logging

Update the existing `logEmailDebug()` method to track service used:

```php
// In EmailTemplate.php, enhance debug logging
private function logEmailDebug($message, $service = null) {
    if ($this->debug_mode) {
        $settings = Globalvars::get_instance();
        $service = $service ?: $settings->get_setting('email_service') ?: 'unknown';
        
        // Build recipient list
        $recipients = [];
        foreach ($this->recipients as $recipient) {
            $recipients[] = $recipient['email'];
        }
        
        // Create log entry
        $debug_log = new DebugEmailLog(NULL);
        $debug_log->set('del_timestamp', date('Y-m-d H:i:s'));
        $debug_log->set('del_subject', $this->email_subject);
        $debug_log->set('del_recipient', implode(', ', $recipients));
        $debug_log->set('del_service', $service);  // NEW: Track which service
        $debug_log->set('del_message', $message);
        $debug_log->set('del_status', strpos($message, 'failed') !== false ? 'failed' : 'success');
        $debug_log->save();
    }
}
```

### 1.6 Add Service Validation

Add method to validate email service configuration:

```php
// Add to EmailTemplate.php
public function validateServiceConfiguration($service = null) {
    $settings = Globalvars::get_instance();
    $service = $service ?: $settings->get_setting('email_service') ?: 'mailgun';
    
    $errors = [];
    
    switch($service) {
        case 'mailgun':
            if (empty($settings->get_setting('mailgun_domain'))) {
                $errors[] = 'Mailgun domain not configured';
            }
            if (empty($settings->get_setting('mailgun_api_key'))) {
                $errors[] = 'Mailgun API key not configured';
            }
            break;
            
        case 'smtp':
            if (empty($settings->get_setting('smtp_host'))) {
                $errors[] = 'SMTP host not configured';
            }
            if (empty($settings->get_setting('smtp_username'))) {
                $errors[] = 'SMTP username not configured';
            }
            if (empty($settings->get_setting('smtp_password'))) {
                $errors[] = 'SMTP password not configured';
            }
            break;
            
        default:
            $errors[] = "Unknown email service: $service";
    }
    
    return [
        'valid' => empty($errors),
        'service' => $service,
        'errors' => $errors
    ];
}
```

### 1.7 Admin UI Service Indicator

Add service status indicator right before "Mailgun Settings" section:

```php
// In admin_settings.php, find this line (around line 1507):
// echo '<h5>Mailgun Settings</h5>';

// Add BEFORE that line:
// Email Service Status Section
echo '<div class="row">';
echo '<div class="col-md-12">';
echo '<h5>Email Service Status</h5>';

$current_service = $settings->get_setting('email_service') ?: 'mailgun';
$fallback_service = $settings->get_setting('email_fallback_service') ?: 'smtp';

// Quick validation check
PathHelper::requireOnce('includes/EmailTemplate.php');
$email = new EmailTemplate('default_outer_template');
$primary_validation = $email->validateServiceConfiguration($current_service);
$fallback_validation = $email->validateServiceConfiguration($fallback_service);

echo '<div class="alert alert-info">';
echo '<strong>Primary Service:</strong> ';
if ($primary_validation['valid']) {
    echo '<span class="text-success">✓ ' . ucfirst($current_service) . ' configured</span>';
} else {
    echo '<span class="text-danger">✗ ' . ucfirst($current_service) . ' - ' . implode(', ', $primary_validation['errors']) . '</span>';
}
echo '<br/>';
echo '<strong>Fallback Service:</strong> ';
if ($fallback_validation['valid']) {
    echo '<span class="text-success">✓ ' . ucfirst($fallback_service) . ' configured</span>';
} else {
    echo '<span class="text-warning">⚠ ' . ucfirst($fallback_service) . ' - ' . implode(', ', $fallback_validation['errors']) . '</span>';
}
echo '</div>';
echo '</div>';
echo '</div>';

// Then continue with existing code:
// echo '<div class="row">';
// echo '<div class="col-md-6">';
// echo '<h5>Mailgun Settings</h5>';
```

## Proposed Architecture

### Clean Architecture Design

```
Proposed Email Flow:
┌──────────────┐     ┌──────────────┐     ┌───────────────────┐
│ Application  │────>│ EmailSender  │────>│ ServiceInterface  │
└──────────────┘     └──────────────┘     └───────────────────┘
                            │                       │
                     ┌──────────────┐       ┌──────┴──────┐
                     │ Template     │       │  Concrete   │
                     │ Engine       │       │  Services   │
                     └──────────────┘       └─────────────┘
```

### Directory Structure

```
/includes/Email/
├── Contracts/
│   ├── EmailServiceInterface.php
│   └── TemplateEngineInterface.php
├── Services/
│   ├── MailgunService.php
│   ├── SMTPService.php
│   ├── SendGridService.php
│   └── TestService.php
├── Templates/
│   ├── TemplateEngine.php
│   ├── TemplateLoader.php
│   └── TrackingInjector.php
├── Models/
│   ├── EmailMessage.php
│   └── EmailRecipient.php
├── EmailServiceFactory.php
├── EmailSender.php
└── EmailException.php
```

## Implementation Details

### 1. Email Service Interface

```php
<?php
namespace Joinery\Email\Contracts;

interface EmailServiceInterface {
    public function send(EmailMessage $message): bool;
    public function sendBatch(array $messages): array;
    public function validateConfiguration(): array;
    public function getCapabilities(): array;
    public function getLastError(): ?array;
}
```

### 2. Email Message Model

```php
<?php
namespace Joinery\Email\Models;

class EmailMessage {
    public string $from;
    public string $fromName;
    public array $recipients = [];
    public array $cc = [];
    public array $bcc = [];
    public string $replyTo;
    public string $subject;
    public string $htmlBody;
    public string $textBody;
    public array $attachments = [];
    public array $headers = [];
    public array $tags = [];
    public array $metadata = [];
    public ?string $messageId = null;
    public ?string $campaignId = null;
    
    public function addRecipient(string $email, string $name = ''): self;
    public function addAttachment(string $path, string $name = ''): self;
    public function addHeader(string $name, string $value): self;
    public function validate(): array;
    public function toArray(): array;
}
```

### 3. Service Registry Pattern

```php
<?php
namespace Joinery\Email;

class EmailServiceRegistry {
    private static array $providers = [];
    private static array $configMappers = [];
    
    public static function register(
        string $name, 
        string $className,
        callable $configMapper = null
    ): void {
        self::$providers[$name] = $className;
        
        if ($configMapper) {
            self::$configMappers[$name] = $configMapper;
        } else {
            // Default config mapper - looks for {service}_* settings
            self::$configMappers[$name] = function($settings) use ($name) {
                $config = [];
                $prefix = $name . '_';
                
                // Auto-discover settings with service prefix
                foreach ($settings->getAllSettings() as $key => $value) {
                    if (strpos($key, $prefix) === 0) {
                        $configKey = substr($key, strlen($prefix));
                        $config[$configKey] = $value;
                    }
                }
                
                return $config;
            };
        }
    }
    
    public static function create(string $name): EmailServiceInterface {
        if (!isset(self::$providers[$name])) {
            throw new EmailException("Email service '$name' not registered");
        }
        
        $className = self::$providers[$name];
        $settings = Globalvars::get_instance();
        
        // Get configuration using registered mapper
        $config = (self::$configMappers[$name])($settings);
        
        return new $className($config);
    }
}
```

## Database Schema Changes

### Future Settings (Not Phase 1)

These settings would be useful in later phases but aren't needed immediately:

```sql
-- Future enhancement settings (Phase 3+)
-- ('email_max_retries', '3', 'Maximum retry attempts for failed sends'),
-- ('email_retry_delay', '300', 'Seconds between retry attempts'),
-- ('email_rate_limit', '100', 'Maximum emails per minute'),
-- ('email_batch_size', '1000', 'Maximum recipients per batch'),
-- ('email_tracking_enabled', '1', 'Enable email tracking'),
-- ('email_tracking_domain', '', 'Custom tracking domain');
```

### Enhance Existing Debug Email Logs

Instead of creating a new table, enhance `del_debug_email_logs` to track service information:

```sql
-- Add service tracking columns to existing debug_email_logs table
ALTER TABLE del_debug_email_logs 
ADD COLUMN IF NOT EXISTS del_service VARCHAR(50),
ADD COLUMN IF NOT EXISTS del_message_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS del_status VARCHAR(20),
ADD COLUMN IF NOT EXISTS del_error_message TEXT,
ADD COLUMN IF NOT EXISTS del_metadata JSONB;

-- Add index for service queries
CREATE INDEX IF NOT EXISTS idx_del_service_status 
ON del_debug_email_logs(del_service, del_status);
```

This consolidates logging into one table that already exists and is already being used for email debugging.

## Migration Plan

### Phase 1: Immediate Fixes (Days 1-2) - CURRENT PRIORITY
- Add `email_service` and `email_fallback_service` settings to database
- Fix dead SMTP code in EmailTemplate.php
- Implement minimal service selection without major refactoring
- Add fallback service logic
- Enhance debug logging to track which service was used
- Add service validation method to EmailTemplate
- Add admin UI indicator for active email service
- Test with existing `/tests/email/` framework
- Ensure backward compatibility

### Phase 2: Foundation (Days 3-5)
- Implement automatic retry from equ_queued_emails table
- Create directory structure
- Implement core interfaces
- Create EmailMessage model
- Set up autoloading

### Phase 3: Services (Days 6-9)
- Extract Mailgun logic to MailgunService
- Wrap existing SmtpMailer in SMTPService
- Implement TestService for development
- Add service validation

### Phase 4: Template Engine (Days 10-12)
- Extract template processing from EmailTemplate
- Create TemplateLoader for database templates
- Implement TrackingInjector
- Add template caching

### Phase 5: Integration (Days 13-16)
- Create EmailServiceFactory with Service Registry pattern
- Implement EmailSender
- Update database settings
- Create migration scripts

### Phase 6: Legacy Wrapper (Days 17-19)
- Modify EmailTemplate to use new system
- Maintain backward compatibility
- Add deprecation notices
- Update documentation

### Phase 7: Testing & Deployment (Days 20-22)
- Integration testing using `/tests/email/`
- Performance benchmarking
- Staged rollout

## Success Metrics

- **Reliability**: 99.9% email delivery success rate
- **Performance**: 50% reduction in bulk send time
- **Flexibility**: New service integration in < 1 day
- **Maintainability**: 70% reduction in email-related bugs
- **Developer Experience**: Clear documentation and examples

## Next Steps

1. **Execute Phase 1 Immediately** - Add settings and fix SMTP functionality
2. **Test Service Selection** - Verify mailgun/smtp switching works
3. **Implement Fallback Logic** - Ensure service failover works correctly
4. **Validate with Test Suite** - Use `/tests/email/` to verify all changes
5. **Then proceed to Phase 2** - Begin foundation architecture work