# Future Email System Refactoring Possibilities

## DNS/Domain Authentication Testing Consolidation

**Current State**: We have DNS/domain authentication testing duplicated across multiple locations with overlapping functionality and confusing user experience.

### Current Implementation Analysis:

#### 1. `/utils/email_setup_check.php` - Comprehensive Domain Checker
- **Purpose**: Independent tool for analyzing ANY domain's authentication setup
- **Features**:
  - Input form to check any domain (not just your configured domains)
  - Detailed SPF record analysis (mechanisms, services, issues, recommendations)
  - Comprehensive DKIM selector scanning (tests multiple common selectors)
  - DMARC policy analysis with detailed explanations and guidance
  - Quick vs comprehensive scan modes with different time limits
  - Visual results with color-coded status indicators
  - Recommendations and troubleshooting guidance
- **Use Case**: Deep-dive analysis for domain owners, troubleshooting, competitive analysis
- **Value**: High - sophisticated analysis not available elsewhere

#### 2. `/tests/email/suites/AuthenticationTests.php` - System Health Checks
- **Purpose**: Verify YOUR configured email domains are properly set up
- **Features**:
  - Automatic domain detection from Mailgun settings
  - Basic SPF/DKIM/DMARC record existence checks
  - Simple pass/fail results integrated into test suite
  - No configuration required - uses system settings
- **Use Case**: Quick health check as part of comprehensive email testing
- **Value**: Medium - but duplicates functionality

#### 3. `/tests/email/auth_analysis.php` - Real-World Testing
- **Purpose**: End-to-end authentication verification via actual email delivery
- **Features**:
  - Sends real emails and retrieves via IMAP
  - Analyzes actual authentication headers from receiving mail servers
  - Shows real-world SPF/DKIM/DMARC results (not just DNS record existence)
  - Tests complete email delivery chain authentication
- **Use Case**: Verify authentication works in practice, not just in theory
- **Value**: High - unique functionality that complements DNS testing

#### 4. Admin Settings Right Panel - Currently Missing
- **Current State**: No DNS/authentication status display in admin settings
- **Opportunity**: Perfect location for quick status overview

### Proposed Consolidation Strategy:

#### Phase 1: Admin Settings Integration
1. **Add DNS Authentication Status Panel** to `admin_settings.php` right sidebar:
   ```
   📋 Email Authentication Status
   ├── 🔍 Quick Status Check
   │   ├── SPF: ✅ mg.joinerytest.site (pass)
   │   ├── DKIM: ✅ mx._domainkey.mg.joinerytest.site (found) 
   │   └── DMARC: ⚠️ _dmarc.mg.joinerytest.site (policy: none)
   ├── 🔧 Issues Found: 1 warning
   │   └── "DMARC policy is 'none' - consider upgrading to 'quarantine'"
   ├── [📊 Detailed Analysis] ← Links to comprehensive tool
   └── [🔬 Test Real-World Results] ← Links to auth_analysis.php
   ```

2. **Auto-detect domains** from current email settings:
   - Primary domain from `defaultemail` setting
   - Mailgun domain from `mailgun_domain` setting
   - Show status for all configured domains

3. **Smart caching** - Cache DNS results for 15 minutes to avoid repeated lookups

#### Phase 2: Remove Duplication
1. **Remove** `AuthenticationTests.php` from test suite
2. **Update** main email testing interface to remove "Test DNS/Domain" button
3. **Redirect** DNS testing to admin settings panel + comprehensive tool

#### Phase 3: Enhanced Integration
1. **Contextual warnings** in admin settings:
   - Show DNS issues when configuring email settings
   - Highlight missing authentication when enabling email features
2. **Guided setup** - Step-by-step DNS record creation assistance
3. **Monitoring** - Optional periodic DNS checks with admin notifications

### Implementation Considerations:

#### Technical Requirements:
- **DNS caching system** to avoid rate limits and improve performance
- **Error handling** for DNS lookup failures and timeouts
- **Mobile-responsive** status display in admin settings
- **Permission checks** - ensure only admins can see DNS status
- **Configuration flags** to enable/disable DNS checking

#### User Experience:
- **Progressive disclosure** - Basic status in settings, detailed analysis via link
- **Clear hierarchy** - Quick check → Detailed analysis → Real-world testing
- **Consistent terminology** across all DNS/auth tools
- **Help documentation** explaining the difference between DNS and delivery testing

#### Code Organization:
- **Shared DNS utility class** for common SPF/DKIM/DMARC checking logic
- **Consistent result format** across all authentication tools
- **Template reuse** for displaying DNS results consistently

### Files Affected:
- `adm/admin_settings.php` - Add DNS status panel
- `tests/email/suites/AuthenticationTests.php` - Remove (functionality moved)
- `tests/email/EmailTestRunner.php` - Remove runDomainTests() method
- `tests/email/index.php` - Remove "Test DNS/Domain" button
- `utils/email_setup_check.php` - Keep as comprehensive analysis tool
- `tests/email/auth_analysis.php` - Keep as real-world testing tool

### Expected Benefits:
1. **Reduced confusion** - Clear purpose for each tool
2. **Better user experience** - DNS status where users configure email
3. **Eliminated duplication** - No redundant testing code
4. **Improved performance** - Cached DNS results, smart checking
5. **Enhanced troubleshooting** - Contextual DNS guidance in settings

### Risk Assessment:
- **Low risk** - Consolidation improves rather than removes functionality
- **Backward compatibility** - Existing tools continue to work during transition
- **Testing required** - Ensure DNS checking doesn't slow down admin settings page
- **Documentation needs** - Update user guides and help text

### Future Enhancements:
- **Automated monitoring** - Periodic DNS checks with email alerts for issues
- **DNS record templates** - Generate exact DNS records for different email services
- **Integration with email services** - API checks with Mailgun, SendGrid, etc.
- **Performance optimization** - Background DNS checking, smart refresh intervals

## Email Log Table Consolidation

### Current State
We currently have three email-related logging tables:
1. **`del_debug_email_logs`** - General email debugging/logging
2. **`ers_recurring_email_logs`** - Logs for recurring/campaign emails  
3. **`erc_email_recipients`** - Individual recipient tracking for campaigns

### Proposed Consolidation: Merge Campaign Logs into Debug Logs

The most logical merge would be combining **`ers_recurring_email_logs`** into **`del_debug_email_logs`**.

#### Why This Makes Sense
1. **They're both logs** - Both track email sending events
2. **Similar purpose** - Both record what was sent, when, and to whom
3. **Debug logs is more general** - It could easily accommodate campaign-specific fields
4. **Campaign logs are a subset** - Recurring email logs are essentially debug logs with extra campaign metadata

#### Implementation Approach
Add columns to `del_debug_email_logs`:
```sql
ALTER TABLE del_debug_email_logs ADD COLUMN IF NOT EXISTS
    del_campaign_id INT REFERENCES cam_campaigns(cam_campaign_id),
    del_email_type VARCHAR(20), -- 'single', 'campaign', 'recurring', 'test', etc.
    del_batch_id VARCHAR(50),    -- For grouping bulk sends
    del_scheduled_time TIMESTAMP,
    del_sent_count INT DEFAULT 0;
```

#### Keep `erc_email_recipients` Separate
This table should remain independent because:
- It tracks individual recipient actions/status (opened, clicked, bounced)
- It has a many-to-one relationship with email sends
- It's more about recipient behavior than email logging
- Merging it would denormalize the data and create lots of duplicate log data

### Potential Downsides to Consider

#### 1. Data Migration Complexity
- Existing queries expecting `ers_recurring_email_logs` would break
- Need to migrate historical data carefully
- Risk of data loss if migration fails
- All code referencing the old table needs updating

#### 2. Performance Impact
- `del_debug_email_logs` becomes a much larger table (all emails vs just debug/campaign)
- Indexes become more critical for query performance
- Queries that previously hit a smaller specialized table now hit a larger general one
- May need partitioning strategy if volume is high

#### 3. Business Logic Coupling
Debug logging and campaign tracking serve different purposes:

**Debug logs:**
- Temporary, deletable
- For troubleshooting
- Developer-focused
- Can be purged regularly

**Campaign logs:**
- Permanent business records
- For analytics and reporting
- Marketing-focused
- Must be retained for compliance/history

Mixing them means you can't easily:
- Purge old debug logs without checking if they're campaign records
- Have different retention policies
- Give different access permissions (developers vs marketing)
- Apply different backup strategies

#### 4. Code Refactoring Scope
- Every reference to `ers_recurring_email_logs` needs updating
- `MultiRecurringEmailLog` class and related code needs refactoring
- Risk of missing references in plugins or custom code
- Admin interfaces may need updates

#### 5. Feature Divergence Risk
- If campaign emails later need very different fields than debug logs, the table gets bloated
- Hard to add campaign-specific constraints or triggers
- Future features might be constrained by the merged structure

### Alternative Approaches

#### Option 1: Keep Separate but Standardize
- Maintain three separate tables
- Create a common interface or base class for consistency
- Use a shared service layer for common operations
- Benefits: Clean separation, easier to maintain different requirements
- Drawbacks: More tables to manage

#### Option 2: Create a Master Log Table with Type-Specific Extensions
```sql
-- Master table for all email events
CREATE TABLE eml_email_logs (
    eml_id SERIAL PRIMARY KEY,
    eml_type VARCHAR(20) NOT NULL, -- 'debug', 'campaign', 'transactional'
    eml_sent_time TIMESTAMP,
    eml_subject TEXT,
    eml_recipient_count INT,
    -- Common fields
);

-- Extension table for campaign-specific data
CREATE TABLE emc_email_campaign_data (
    emc_eml_id INT REFERENCES eml_email_logs(eml_id),
    emc_campaign_id INT,
    emc_scheduled_time TIMESTAMP,
    -- Campaign-specific fields
);
```

#### Option 3: Event Sourcing Pattern
- Store email events as immutable records
- Build different views/projections for different purposes
- More complex but highly flexible

### Recommendation

**Short term**: Keep the current three-table structure but enhance `del_debug_email_logs` as the primary logging table for the new refactored system.

**Long term**: Consider Option 2 (master table with extensions) during a major refactoring phase when you have time to:
- Properly migrate all data
- Update all dependent code
- Implement proper data retention policies
- Create appropriate indexes and partitioning

The biggest risk to avoid: Accidentally mixing operational debugging data with business-critical campaign records, which could lead to inadvertent deletion of important business data.

### Implementation Priority

This consolidation should be considered **AFTER** the main email refactoring is complete, as it's an optimization rather than a critical architectural fix. The current three-table structure works and doesn't block other improvements.

## Other Future Considerations

### 1. Email Template Versioning
- Track template changes over time
- Allow rollback to previous versions
- A/B testing different template versions

### 2. Email Queue Management
- Separate queue table for pending emails
- Better retry logic and failure handling
- Priority queuing for important emails

### 3. Bounce and Complaint Handling
- Automated bounce processing
- Complaint feedback loop integration
- Automatic unsubscribe management

### 4. Email Analytics Dashboard
- Open rates, click rates, bounce rates
- Campaign performance comparison
- Recipient engagement scoring

### 5. Multi-tenant Email Isolation
- Separate email configurations per tenant
- Isolated email logs and analytics
- Per-tenant sending limits and quotas

## Phase 2: Automatic Email Retry Mechanism

### Overview
Implement automatic retry for failed emails that are currently being saved to `equ_queued_emails` table but never retried.

### 2.1 Implement Automatic Retry from Queued Emails

**Current State**: Emails that fail are saved to `equ_queued_emails` table but there's no automatic retry mechanism.

#### Implementation: QueueProcessor Class
```php
// NEW FILE: /includes/Email/QueueProcessor.php
<?php
namespace Joinery\Email;

class QueueProcessor {
    private $settings;
    private $max_retries;
    private $retry_delay;
    
    public function __construct() {
        $this->settings = Globalvars::get_instance();
        $this->max_retries = intval($this->settings->get_setting('email_max_retries') ?: 3);
        $this->retry_delay = intval($this->settings->get_setting('email_retry_delay') ?: 300);
    }
    
    /**
     * Process all queued emails that are ready for retry
     * @return array Results of processing
     */
    public function processQueue(): array {
        $results = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'permanently_failed' => 0
        ];
        
        // Load queued emails that are ready for retry
        $queued = new MultiQueuedEmail([
            'equ_status' => QueuedEmail::NORMAL_MAILER_ERROR,
            'equ_retry_count' => '< ' . $this->max_retries,
            'equ_next_retry_time' => '<= NOW()'
        ], ['equ_id' => 'ASC'], 100);
        
        if ($queued->count_all() === 0) {
            return $results;
        }
        
        $queued->load();
        
        foreach ($queued as $queuedEmail) {
            $results['processed']++;
            
            // Recreate the email from queued data
            $email = $this->recreateEmailFromQueued($queuedEmail);
            
            if (!$email) {
                $results['failed']++;
                continue;
            }
            
            // Attempt to send
            $sent = $email->send(false); // false = don't check session
            
            if ($sent) {
                // Mark as sent
                $queuedEmail->set('equ_status', QueuedEmail::SENT);
                $queuedEmail->set('equ_sent_time', date('Y-m-d H:i:s'));
                $queuedEmail->save();
                $results['sent']++;
            } else {
                // Increment retry count
                $retry_count = intval($queuedEmail->get('equ_retry_count')) + 1;
                $queuedEmail->set('equ_retry_count', $retry_count);
                
                if ($retry_count >= $this->max_retries) {
                    // Mark as permanently failed
                    $queuedEmail->set('equ_status', QueuedEmail::PERMANENT_FAILURE);
                    $results['permanently_failed']++;
                } else {
                    // Schedule next retry
                    $next_retry = date('Y-m-d H:i:s', time() + $this->retry_delay);
                    $queuedEmail->set('equ_next_retry_time', $next_retry);
                    $results['failed']++;
                }
                
                $queuedEmail->save();
            }
        }
        
        return $results;
    }
    
    /**
     * Recreate EmailTemplate from queued email data
     */
    private function recreateEmailFromQueued($queuedEmail): ?EmailTemplate {
        try {
            // Create new email instance
            $email = new EmailTemplate('blank_template');
            
            // Restore email properties from JSON data
            $email_data = json_decode($queuedEmail->get('equ_email_data'), true);
            
            if (!$email_data) {
                return null;
            }
            
            // Set basic properties
            $email->email_from = $email_data['from'] ?? '';
            $email->email_from_name = $email_data['from_name'] ?? '';
            $email->email_subject = $email_data['subject'] ?? '';
            $email->email_html = $email_data['html'] ?? '';
            $email->email_text = $email_data['text'] ?? '';
            
            // Add recipients
            if (isset($email_data['recipients']) && is_array($email_data['recipients'])) {
                foreach ($email_data['recipients'] as $recipient) {
                    $email->add_recipient($recipient['email'], $recipient['name'] ?? '');
                }
            }
            
            return $email;
            
        } catch (Exception $e) {
            $this->logError("Failed to recreate email from queue: " . $e->getMessage());
            return null;
        }
    }
    
    private function logError($message) {
        error_log("[QueueProcessor] " . $message);
    }
}
```

#### Cron Job for Processing
```php
// NEW FILE: /cron/process_email_queue.php
<?php
/**
 * Cron job to process queued emails
 * Run every 5 minutes: */5 * * * * php /path/to/cron/process_email_queue.php
 */

require_once('../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/Email/QueueProcessor.php');

use Joinery\Email\QueueProcessor;

$processor = new QueueProcessor();
$results = $processor->processQueue();

// Log results
$message = sprintf(
    "Email Queue Processed: %d processed, %d sent, %d failed, %d permanently failed",
    $results['processed'],
    $results['sent'],
    $results['failed'],
    $results['permanently_failed']
);

error_log($message);
echo $message . "\n";
```

### 2.2 Database Migration for Retry Support
```php
// In migrations/migrations.php
$migration = array();
$migration['database_version'] = '0.55';
$migration['test'] = "SELECT COUNT(*) as count FROM information_schema.columns 
                      WHERE table_name = 'equ_queued_emails' 
                      AND column_name = 'equ_retry_count'";
$migration['migration_sql'] = "
    -- Add retry tracking columns to queued emails
    ALTER TABLE equ_queued_emails 
    ADD COLUMN IF NOT EXISTS equ_retry_count INTEGER DEFAULT 0,
    ADD COLUMN IF NOT EXISTS equ_next_retry_time TIMESTAMP,
    ADD COLUMN IF NOT EXISTS equ_last_error TEXT,
    ADD COLUMN IF NOT EXISTS equ_sent_time TIMESTAMP;
    
    -- Add status constants if not exists
    ALTER TABLE equ_queued_emails 
    ALTER COLUMN equ_status TYPE VARCHAR(50);
    
    -- Create index for efficient queue processing
    CREATE INDEX IF NOT EXISTS idx_equ_retry_status 
    ON equ_queued_emails(equ_status, equ_next_retry_time) 
    WHERE equ_status IN ('error', 'pending');
    
    -- Add retry settings
    INSERT INTO stg_settings (stg_setting, stg_value, stg_description) VALUES
    ('email_max_retries', '3', 'Maximum retry attempts for failed emails'),
    ('email_retry_delay', '300', 'Seconds between retry attempts')
    ON CONFLICT (stg_setting) DO NOTHING;
";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

### 2.3 Enhanced Queue Management

#### Enhanced save_email_as_queued Method
```php
// Enhanced EmailTemplate.php method
function save_email_as_queued($user_id, $status = QueuedEmail::NOT_SENT, $error = null) {
    $settings = Globalvars::get_instance();
    $retry_delay = intval($settings->get_setting('email_retry_delay') ?: 300);
    
    // Enhanced email data with metadata
    $email_data = array(
        'from' => $this->email_from,
        'from_name' => $this->email_from_name,
        'subject' => $this->email_subject,
        'html' => $this->email_html,
        'text' => $this->email_text,
        'recipients' => $this->email_recipients,
        'metadata' => [
            'template_name' => $this->template_name,
            'created_time' => date('Y-m-d H:i:s'),
            'service_attempted' => $this->getServiceType(),
            'original_error' => $error
        ]
    );
    
    $queued = new QueuedEmail(NULL);
    $queued->set('equ_usr_user_id', $user_id);
    $queued->set('equ_email_data', json_encode($email_data));
    $queued->set('equ_status', $status);
    $queued->set('equ_retry_count', 0);
    $queued->set('equ_next_retry_time', date('Y-m-d H:i:s', time() + $retry_delay));
    
    if ($error) {
        $queued->set('equ_last_error', $error);
    }
    
    $queued->save();
    
    // Log to debug if enabled
    $this->logEmailDebug(
        "Email queued for retry: " . ($error ?: 'No specific error'),
        $this->getServiceType()
    );
}

// Update sendViaSmtp to pass error message
private function sendViaSmtp() {
    $this->mailer->isHTML(true);
    $this->mailer->Body = $this->email_html;
    $this->mailer->AltBody = $this->email_text;
    
    if (!$this->mailer->send()) {
        $error = $this->mailer->ErrorInfo;
        $this->logEmailDebug("SMTP send failed: " . $error, 'smtp');
        // Pass error message to queued email
        $this->save_email_as_queued(NULL, QueuedEmail::NORMAL_MAILER_ERROR, $error);
        return false;
    }
    
    $this->logEmailDebug("Email sent successfully via SMTP", 'smtp');
    return true;
}
```

### Success Metrics for Phase 2
- [ ] Automatic retry mechanism processes queued emails every 5 minutes
- [ ] Failed emails retry up to 3 times with exponential backoff
- [ ] Queue processor handles 1000+ emails without memory issues
- [ ] Retry success rate > 80% for temporary failures

### Implementation Checklist for Phase 2
- [ ] Create QueueProcessor class
- [ ] Add retry columns to database
- [ ] Create cron job for queue processing
- [ ] Enhance save_email_as_queued with retry metadata
- [ ] Test retry mechanism with forced failures
- [ ] Monitor retry success rates

### Testing Strategy for Phase 2
```php
// /tests/email/suites/QueueProcessorTests.php
class QueueProcessorTests {
    public function testQueueProcessor() {
        $processor = new QueueProcessor();
        
        // Add a test email to queue
        $this->addTestEmailToQueue();
        
        // Process queue
        $results = $processor->processQueue();
        
        assert($results['processed'] > 0);
        // Further assertions based on results
    }
    
    private function addTestEmailToQueue() {
        $email_data = [
            'from' => 'test@example.com',
            'from_name' => 'Test Sender',
            'subject' => 'Test Queued Email',
            'html' => '<p>Test content</p>',
            'text' => 'Test content',
            'recipients' => [
                ['email' => 'recipient@example.com', 'name' => 'Test Recipient']
            ]
        ];
        
        $queued = new QueuedEmail(NULL);
        $queued->set('equ_email_data', json_encode($email_data));
        $queued->set('equ_status', QueuedEmail::NORMAL_MAILER_ERROR);
        $queued->set('equ_retry_count', 0);
        $queued->set('equ_next_retry_time', date('Y-m-d H:i:s'));
        $queued->save();
    }
}
```

### Notes on Phase 2
- Focuses on reliability through automatic retry
- Maintains backward compatibility with existing queue system
- Can be implemented independently of Phase 3 architecture changes
- Provides immediate value for handling transient email failures

## Template Engine Separation

### Overview

The EmailTemplate class currently handles both template processing (loading, merging, variable substitution, conditionals) and email sending. Separating these concerns would create a reusable template engine that could be used for other purposes (PDFs, SMS, print views) while making the email system cleaner and more testable.

### Current State Analysis

The EmailTemplate class (811 lines) contains approximately:
- ~400 lines of template processing logic
- ~300 lines of email sending logic  
- ~100 lines of utility/setup code

Template operations currently embedded in EmailTemplate:
- Loading templates from database
- Merging inner/outer/footer templates
- Variable substitution (`*variable*` → value)
- Conditional processing (`*if:condition*` blocks)
- Subject line extraction from template
- UTM tracking injection
- Link tracking for campaigns

### Proposed Separation

#### New Class: EmailTemplateProcessor

```php
// NEW FILE: /includes/EmailTemplateProcessor.php
class EmailTemplateProcessor {
    private $settings;
    private $template_name;
    private $inner_template;
    private $outer_template;
    private $footer_template;
    private $processed_html;
    private $processed_text;
    private $extracted_subject;
    private $template_values = [];
    
    public function __construct($template_name = null) {
        $this->settings = Globalvars::get_instance();
        if ($template_name) {
            $this->loadTemplate($template_name);
        }
    }
    
    /**
     * Load a template from the database
     */
    public function loadTemplate($template_name) {
        $this->template_name = $template_name;
        
        // Load inner template
        $templates = new MultiEmailTemplateStore(['email_template_name' => $template_name]);
        $templates->load();
        
        if ($templates->count_all() > 0) {
            $template = $templates->get(0);
            $this->inner_template = $template->get('emt_body');
            
            // Check for outer template reference
            if ($outer_id = $template->get('emt_outer_template_id')) {
                $this->loadOuterTemplate($outer_id);
            }
            
            // Check for footer template
            if ($footer_name = $template->get('emt_footer_template')) {
                $this->loadFooterTemplate($footer_name);
            }
        }
        
        return $this;
    }
    
    /**
     * Process the template with given values
     */
    public function process($values = []) {
        $this->template_values = array_merge($this->template_values, $values);
        
        // Start with inner template
        $content = $this->inner_template;
        
        // Process conditionals first
        $content = $this->processConditionals($content, $this->template_values);
        
        // Extract subject if present
        $this->extracted_subject = $this->extractSubject($content);
        if ($this->extracted_subject !== null) {
            // Remove subject line from content
            $content = preg_replace('/^subject:\s*.*?\n/i', '', $content, 1);
        }
        
        // Substitute variables
        $content = $this->substituteVariables($content, $this->template_values);
        
        // Merge with outer template if exists
        if ($this->outer_template) {
            $outer = $this->substituteVariables($this->outer_template, $this->template_values);
            $content = str_replace('*inner_template*', $content, $outer);
        }
        
        // Add footer if exists
        if ($this->footer_template) {
            $footer = $this->substituteVariables($this->footer_template, $this->template_values);
            $content = str_replace('*footer*', $footer, $content);
        }
        
        $this->processed_html = $content;
        $this->processed_text = $this->generateTextVersion($content);
        
        return $this;
    }
    
    /**
     * Extract subject line from template
     */
    private function extractSubject($content) {
        if (preg_match('/^subject:\s*(.*?)$/im', $content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
    
    /**
     * Process conditional blocks
     */
    private function processConditionals($content, $values) {
        // Process *if:variable* blocks
        $pattern = '/\*if:(\w+)\*(.*?)\*endif:\1\*/s';
        
        $content = preg_replace_callback($pattern, function($matches) use ($values) {
            $variable = $matches[1];
            $block_content = $matches[2];
            
            // Check if variable exists and is truthy
            if (isset($values[$variable]) && $values[$variable]) {
                return $block_content;
            }
            
            return '';
        }, $content);
        
        // Process *ifnot:variable* blocks
        $pattern = '/\*ifnot:(\w+)\*(.*?)\*endifnot:\1\*/s';
        
        $content = preg_replace_callback($pattern, function($matches) use ($values) {
            $variable = $matches[1];
            $block_content = $matches[2];
            
            // Check if variable doesn't exist or is falsy
            if (!isset($values[$variable]) || !$values[$variable]) {
                return $block_content;
            }
            
            return '';
        }, $content);
        
        return $content;
    }
    
    /**
     * Substitute variables in template
     */
    private function substituteVariables($content, $values) {
        foreach ($values as $key => $value) {
            // Handle array values (like for loops)
            if (is_array($value)) {
                $content = $this->processArrayVariable($content, $key, $value);
            } else {
                // Simple substitution
                $content = str_replace('*' . $key . '*', $value, $content);
            }
        }
        
        // Remove any remaining undefined variables
        $content = preg_replace('/\*\w+\*/', '', $content);
        
        return $content;
    }
    
    /**
     * Process array variables for loops
     */
    private function processArrayVariable($content, $key, $array) {
        // Look for *foreach:key* blocks
        $pattern = '/\*foreach:' . preg_quote($key, '/') . '\*(.*?)\*endforeach:' . preg_quote($key, '/') . '\*/s';
        
        $content = preg_replace_callback($pattern, function($matches) use ($array) {
            $loop_content = $matches[1];
            $output = '';
            
            foreach ($array as $item) {
                $item_content = $loop_content;
                
                if (is_array($item)) {
                    // Substitute item properties
                    foreach ($item as $prop => $value) {
                        $item_content = str_replace('*item.' . $prop . '*', $value, $item_content);
                    }
                } else {
                    // Simple item substitution
                    $item_content = str_replace('*item*', $item, $item_content);
                }
                
                $output .= $item_content;
            }
            
            return $output;
        }, $content);
        
        return $content;
    }
    
    /**
     * Generate text version from HTML
     */
    private function generateTextVersion($html) {
        // Remove HTML tags
        $text = strip_tags($html);
        
        // Convert entities
        $text = html_entity_decode($text);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Add UTM tracking to links
     */
    public function addUTMTracking($source, $medium = 'email', $campaign = null) {
        if (!$this->processed_html) {
            return $this;
        }
        
        $utm_params = http_build_query([
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign ?: $this->template_name
        ]);
        
        // Add UTM to all links
        $this->processed_html = preg_replace_callback(
            '/<a\s+([^>]*href=["\']?)([^"\'\s>]+)([^>]*)>/i',
            function($matches) use ($utm_params) {
                $url = $matches[2];
                
                // Skip mailto and tel links
                if (strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0) {
                    return $matches[0];
                }
                
                // Add UTM parameters
                $separator = (strpos($url, '?') !== false) ? '&' : '?';
                $new_url = $url . $separator . $utm_params;
                
                return '<a ' . $matches[1] . $new_url . $matches[3] . '>';
            },
            $this->processed_html
        );
        
        return $this;
    }
    
    /**
     * Get processed HTML
     */
    public function getHtml() {
        return $this->processed_html;
    }
    
    /**
     * Get processed text
     */
    public function getText() {
        return $this->processed_text;
    }
    
    /**
     * Get extracted subject
     */
    public function getSubject() {
        return $this->extracted_subject;
    }
    
    /**
     * Set a template variable
     */
    public function setVariable($key, $value) {
        $this->template_values[$key] = $value;
        return $this;
    }
    
    /**
     * Set multiple template variables
     */
    public function setVariables($values) {
        $this->template_values = array_merge($this->template_values, $values);
        return $this;
    }
}
```

#### Updated EmailTemplate Class

```php
// EmailTemplate.php - After separation
class EmailTemplate {
    private $processor;
    private $email_from;
    private $email_from_name;
    private $email_recipients = [];
    private $email_subject;
    private $email_html;
    private $email_text;
    
    public function __construct($template_name = null) {
        $this->settings = Globalvars::get_instance();
        
        if ($template_name) {
            $this->processor = new EmailTemplateProcessor($template_name);
        }
        
        // Set default from
        $this->email_from = $this->settings->get_setting('defaultemail');
        $this->email_from_name = $this->settings->get_setting('defaultemailname');
    }
    
    /**
     * Fill template with values
     */
    public function fill_template($values) {
        if (!$this->processor) {
            throw new Exception('No template loaded');
        }
        
        // Process template
        $this->processor->process($values);
        
        // Get results
        $this->email_html = $this->processor->getHtml();
        $this->email_text = $this->processor->getText();
        
        // Get subject if not already set
        if (!$this->email_subject) {
            $this->email_subject = $this->processor->getSubject();
        }
        
        return $this;
    }
    
    // All the sending methods remain here
    // sendWithService(), sendViaSmtp(), sendViaMailgun(), etc.
}
```

### Benefits of Separation

1. **Reusability**: Template processor can be used for:
   - PDF generation (invoices, reports)
   - SMS messages
   - Print views
   - In-app notifications
   - Export formats

2. **Testability**: Can test template processing without email infrastructure:
   ```php
   $processor = new EmailTemplateProcessor('welcome_email');
   $processor->process(['username' => 'John']);
   assert($processor->getSubject() == 'Welcome John!');
   ```

3. **Maintainability**: 
   - Template logic in one place
   - Email sending logic in another
   - Easier to debug template issues
   - Cleaner class responsibilities

4. **Performance**: Template processing can be cached independently of sending

5. **Future Flexibility**: Could swap template engines (Twig, Blade) without changing email code

### Implementation Steps

1. **Create EmailTemplateProcessor class** (~2 days)
   - Extract all template methods from EmailTemplate
   - Ensure backward compatibility
   - Add unit tests for template processing

2. **Refactor EmailTemplate** (~1 day)
   - Remove template processing methods
   - Use EmailTemplateProcessor internally
   - Maintain existing public API

3. **Testing** (~1 day)
   - Verify all existing email templates work
   - Test conditional processing
   - Test variable substitution
   - Test UTM tracking

4. **Documentation** (~2 hours)
   - Document new template processor API
   - Add examples for non-email usage
   - Update developer guides

**Total estimated time: 4-5 days**

### Comparison with Full Service Architecture

| Aspect | Template Separation | Full Service Architecture |
|--------|-------------------|-------------------------|
| Value Added | High - Enables template reuse | Medium - Cleaner architecture |
| Implementation Time | 4-5 days | 2-3 weeks |
| Risk | Low - Internal refactor | Medium - Changes sending logic |
| Testing Required | Template tests only | Full integration testing |
| Business Impact | New capabilities (PDFs, etc.) | Technical improvement only |

### Recommendation Priority

If you're going to do any refactoring beyond the test mode (Option 2):

1. **First**: Add test mode (4 hours) - Solves immediate need
2. **Second**: Separate template engine (4-5 days) - Adds business value
3. **Third**: Full service architecture (2-3 weeks) - Nice to have

The template separation provides more tangible benefits than the service architecture for most applications.