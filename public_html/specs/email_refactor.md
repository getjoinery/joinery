# Email System Refactoring Specification

## Executive Summary

The current email system has evolved organically, resulting in tightly coupled components. This specification outlines a comprehensive refactoring to create a flexible, maintainable, and extensible email infrastructure.

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

3. **Dead Code Issue** (NOT YET FIXED):
   - **EmailTemplate's SMTP branch is dead code** - the `$this->mailer` property is never set to a truthy value
   - The check `if($this->mailer)` on line 680 always evaluates to false
   - **EmailTemplate only uses Mailgun** in practice, never SMTP
   - SMTP IS used elsewhere in the system:
     - `QueuedEmail` class uses SmtpMailer for retry logic
     - `Activation` class uses SmtpMailer directly
     - Test scripts use SmtpMailer

## Immediate Fix Required

### Restoring SMTP Functionality in EmailTemplate

The dead SMTP code needs to be fixed to enable service selection:

```php
// Option 1: Add a method to enable SMTP
public function useSmtp($enable = true) {
    $this->mailer = $enable;
}

// Option 2: Check a setting to determine service
function send($check_session=TRUE, $other_host=NULL) {
    $settings = Globalvars::get_instance();
    
    // Determine which service to use
    $service = $settings->get_setting('email_service') ?: 'auto';
    
    if ($service === 'smtp' || ($service === 'auto' && !$this->hasMailgunConfig())) {
        $this->mailer = true;  // Enable SMTP
    }
    
    // Rest of existing code...
}

// Option 3: Use the $other_host parameter as SMTP indicator
if($other_host !== NULL || $settings->get_setting('use_smtp')) {
    $this->mailer = true;
}
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

### Still Needed Settings

```sql
-- Core email configuration
INSERT INTO stg_settings (stg_name, stg_value, stg_description) VALUES
('email_service', 'mailgun', 'Active email service: mailgun, smtp, sendgrid, aws_ses'),
('email_fallback_service', 'smtp', 'Fallback service if primary fails'),
('email_max_retries', '3', 'Maximum retry attempts for failed sends'),
('email_retry_delay', '300', 'Seconds between retry attempts'),

-- Service-specific limits
('email_rate_limit', '100', 'Maximum emails per minute'),
('email_batch_size', '1000', 'Maximum recipients per batch'),

-- Tracking configuration
('email_tracking_enabled', '1', 'Enable email tracking'),
('email_tracking_domain', '', 'Custom tracking domain');
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

### Phase 1: Foundation (Days 1-3)
- Create directory structure
- Implement core interfaces
- Create EmailMessage model
- Set up autoloading

### Phase 2: Services (Days 4-7)
- Extract Mailgun logic to MailgunService
- Use existing SmtpMailer
- **Fix dead SMTP code** - Enable SMTP selection in EmailTemplate
- Implement TestService for development
- Add service validation

### Phase 3: Template Engine (Days 8-10)
- Extract template processing from EmailTemplate
- Create TemplateLoader for database templates
- Implement TrackingInjector
- Add template caching

### Phase 4: Integration (Days 11-14)
- Create EmailServiceFactory with Service Registry pattern
- Implement EmailSender
- Update database settings
- Create migration scripts

### Phase 5: Legacy Wrapper (Days 15-17)
- Modify EmailTemplate to use new system
- Maintain backward compatibility
- Add deprecation notices
- Update documentation

### Phase 6: Testing & Deployment (Days 18-21)
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

1. **Fix Dead SMTP Code** - Restore SMTP functionality in EmailTemplate
2. **Begin Phase 1** - Create directory structure and interfaces
3. **Leverage Existing Work** - Use SmtpMailer and test infrastructure
4. **Consider Service Registry** - Implement dynamic service registration