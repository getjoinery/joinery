<?php
// tests/email/email_pattern_test.php
// Comprehensive email pattern test - sends one email for each pattern found in the codebase

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));  
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/emails_class.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));

class EmailPatternTest {
    private array $config;
    private $patterns = [];
    private $results = [];
    private bool $silent;
    
    public function __construct(array $config, bool $silent = false) {
        $this->config = $config;
        $this->silent = $silent;
        $this->loadPatterns();
    }
    
    private function loadPatterns() {
        // Pattern 1: Static template names with EmailSender::sendTemplate
        // Copied from: data/users_class.php:886 (new_account_content)
        $this->patterns[] = [
            'name' => 'new_account_content_pattern',
            'source' => 'data/users_class.php:886',
            'method' => 'sendTemplate',
            'template' => 'new_account_content',
            'variables' => [
                'usr_name' => 'Test User',
                'usr_email' => $this->config['test_email'],
                'act_code' => 'TEST123',
                'resend' => false,
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User',
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 2: Static template with EmailSender::sendTemplate
        // Copied from: includes/Activation.php:64 (activation_content)  
        $this->patterns[] = [
            'name' => 'activation_content_pattern',
            'source' => 'includes/Activation.php:64',
            'method' => 'sendTemplate',
            'template' => 'activation_content',
            'variables' => [
                'act_code' => 'PATTERN_TEST_' . date('His'),
                'resend' => false,
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User', 
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 3: Password reset template
        // Copied from: includes/Activation.php:85 (forgotpw_content)
        $this->patterns[] = [
            'name' => 'forgotpw_content_pattern',
            'source' => 'includes/Activation.php:85',
            'method' => 'sendTemplate', 
            'template' => 'forgotpw_content',
            'variables' => [
                'act_code' => 'RESET_' . date('His'),
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User',
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 4: EmailMessage::fromTemplate with email change
        // Copied from: includes/Activation.php:107 (email_change_content) 
        $this->patterns[] = [
            'name' => 'email_change_content_pattern',
            'source' => 'includes/Activation.php:107',
            'method' => 'fromTemplate',
            'template' => 'email_change_content',
            'variables' => [
                'act_code' => 'EMAIL_CHANGE_' . date('His'),
                'new_email' => 'newemail@example.com',
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User',
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 5: Mailing list subscribe template  
        // Copied from: data/mailing_lists_class.php:190 (mailing_list_subscribe)
        // Create mock mailing list object to match production code exactly
        $mockMailingList = new stdClass();
        $mockMailingList->key = 1;
        $mockMailingList->mlt_name = 'Test Mailing List';
        
        $this->patterns[] = [
            'name' => 'mailing_list_subscribe_pattern',
            'source' => 'data/mailing_lists_class.php:190',
            'method' => 'sendTemplate',
            'template' => 'mailing_list_subscribe', 
            'variables' => [
                'subject' => 'Welcome to our mailing list',
                'mailing_list' => $mockMailingList,
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User',
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 6: Event bundle email
        // Copied from: logic/cart_charge_logic.php:457 (event_bundle_content)
        // Exact copy of: $final_fill = array_merge($default_fill, $email_fill);
        $this->patterns[] = [
            'name' => 'event_bundle_content_pattern',
            'source' => 'logic/cart_charge_logic.php:457',
            'method' => 'sendTemplate',
            'template' => 'event_bundle_content',
            'variables' => [
                // From $default_fill
                'user_id' => 1,
                // From $email_fill  
                'purchase_amount' => 99.99,
                'event_list' => 'Test Event 1<br>Test Event 2<br>Test Event 3',
                // Added by final_fill
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User', 
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 7: Subscription receipt (with typo preserved from source)
        // Copied from: logic/cart_charge_logic.php:470 (subscription_reciept)  
        // Note: This code is commented out in production, but pattern preserved
        $this->patterns[] = [
            'name' => 'subscription_reciept_pattern', 
            'source' => 'logic/cart_charge_logic.php:470',
            'method' => 'sendTemplate',
            'template' => 'subscription_reciept',
            'variables' => [
                // From $default_fill
                'user_id' => 1,
                // From $email_fill
                'purchase_amount' => 29.99,
                // Added by final_fill
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User',
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 8: Blank template for custom content
        // Copied from: tests/integration/mailgun_test.php:19 (blank_template)
        $this->patterns[] = [
            'name' => 'blank_template_pattern',
            'source' => 'tests/integration/mailgun_test.php:19',
            'method' => 'fromTemplate',
            'template' => 'blank_template',
            'variables' => [
                'subject' => 'Pattern Test: Blank Template',
                'body' => '<p>This is a test of the blank template pattern.</p>',
                'content' => '<p>Template pattern testing content.</p>'
            ]
        ];
        
        // Pattern 9: Default outer template - REMOVED
        // Note: This pattern was removed because outer templates don't have subject lines
        // and are not meant to be used as standalone emails. The original code in 
        // DeliveryTests.php:98 is actually incorrect test code, not a production pattern.
        
        // Pattern 9: Dynamic template from settings (individual_email_inner_template)
        // Copied from: data/order_items_class.php:263 (dynamic template loading)
        $settings = Globalvars::get_instance();
        $individual_template = $settings->get_setting('individual_email_inner_template') ?: 'blank_template';
        $this->patterns[] = [
            'name' => 'individual_email_inner_template_pattern',
            'source' => 'data/order_items_class.php:263',
            'method' => 'sendTemplate',
            'template' => $individual_template,
            'variables' => [
                'subject' => 'Pattern Test: Individual Email Inner Template',
                'mail_body' => '<p>Test content for individual email inner template pattern.</p>',
                'body' => 'This is a test of the individual email inner template dynamic pattern.',
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User',
                    'usr_email' => $this->config['test_email']
                ]
            ]
        ];
        
        // Pattern 10: Dynamic template from email record (eml_message_template_html field)
        // Copied from: adm/admin_emails_send.php:62 (template from email record)
        $this->patterns[] = [
            'name' => 'email_record_template_pattern',
            'source' => 'adm/admin_emails_send.php:62',
            'method' => 'fromTemplate',
            'template' => 'blank_template', // Fallback since we don't have actual email record
            'variables' => [
                'subject' => 'Pattern Test: Email Record Template',
                'preview_text' => 'Testing email record template pattern',
                'mail_body' => '<p>This tests the pattern of loading template names from email records.</p>',
                'utm_source' => 'pattern_test',
                'utm_medium' => 'email',
                'utm_campaign' => 'template_testing',
                'utm_content' => urlencode('Pattern Test: Email Record Template')
            ]
        ];
        
        // Pattern 11: Recurring mailer template pattern  
        // Copied from: data/recurring_mailer_class.php:200 (recurring email template)
        $this->patterns[] = [
            'name' => 'recurring_mailer_template_pattern',
            'source' => 'data/recurring_mailer_class.php:200',
            'method' => 'fromTemplate',
            'template' => 'blank_template', // Using blank_template as fallback for main_template
            'variables' => [
                'subject' => 'Pattern Test: Recurring Mailer Template',
                'template_name' => 'main_template',
                'user_name' => 'Test User',
                'recipient' => [
                    'usr_user_id' => 1,
                    'usr_name' => 'Test User',
                    'usr_email' => $this->config['test_email'],
                    'default_address' => [
                        'addr_city' => 'Test City',
                        'addr_state' => 'TS'
                    ]
                ],
                'friend_reviews' => 0,
                'web_dir' => 'https://example.com'
            ]
        ];
    }
    
    public function run(): array {
        $this->results = [];
        
        foreach ($this->patterns as $pattern) {
            if (!$this->silent) {
                echo "Testing pattern: {$pattern['name']} (from {$pattern['source']})...\n";
            }
            
            try {
                $result = $this->testPattern($pattern);
                $this->results[$pattern['name']] = $result;
                
                if (!$this->silent) {
                    if ($result['success']) {
                        echo "✓ SUCCESS: {$pattern['name']}\n";
                    } else {
                        echo "✗ FAILED: {$pattern['name']} - {$result['error']}\n";
                    }
                }
                
            } catch (Exception $e) {
                $this->results[$pattern['name']] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'pattern' => $pattern['name'],
                    'source' => $pattern['source']
                ];
                if (!$this->silent) {
                    echo "✗ EXCEPTION: {$pattern['name']} - {$e->getMessage()}\n";
                }
            }
            
            if (!$this->silent) {
                echo "\n";
            }
        }
        
        return $this->results;
    }
    
    private function testPattern(array $pattern): array {
        $success = false;
        $error = null;
        $details = [];
        
        try {
            if ($pattern['method'] === 'sendTemplate') {
                // Pattern: EmailSender::sendTemplate()
                $success = EmailSender::sendTemplate(
                    $pattern['template'],
                    $this->config['test_email'],
                    $pattern['variables']
                );
                
                $details['method'] = 'EmailSender::sendTemplate';
                $details['template'] = $pattern['template'];
                $details['variables_count'] = count($pattern['variables']);
                
            } elseif ($pattern['method'] === 'fromTemplate') {
                // Pattern: EmailMessage::fromTemplate() + manual send
                $message = EmailMessage::fromTemplate($pattern['template'], $pattern['variables']);
                $message->to($this->config['test_email'], 'Pattern Test User');
                
                $sender = new EmailSender();
                $success = $sender->send($message);
                
                $details['method'] = 'EmailMessage::fromTemplate + EmailSender::send';
                $details['template'] = $pattern['template'];
                $details['variables_count'] = count($pattern['variables']);
                $details['subject'] = $message->getSubject();
                $details['has_html'] = !empty($message->getHtmlBody());
                $details['has_text'] = !empty($message->getTextBody());
            }
            
        } catch (Exception $e) {
            $success = false;
            $error = $e->getMessage();
        }
        
        return [
            'success' => $success,
            'error' => $error,
            'details' => $details,
            'pattern' => $pattern['name'],
            'source' => $pattern['source'],
            'template' => $pattern['template'],
            'method' => $pattern['method']
        ];
    }
    
    public function getResults(): array {
        return $this->results;
    }
    
    public function getSummary(): array {
        $total = count($this->results);
        $successful = array_filter($this->results, fn($r) => $r['success'] === true);
        $failed = array_filter($this->results, fn($r) => $r['success'] === false);
        
        return [
            'total_patterns' => $total,
            'successful' => count($successful),
            'failed' => count($failed),
            'success_rate' => $total > 0 ? round((count($successful) / $total) * 100, 2) : 0,
            'patterns_tested' => array_keys($this->results)
        ];
    }
}

// Run the test if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "Email Pattern Test - Testing all email code patterns found in codebase\n";
    echo "====================================================================\n\n";
    
    // Configuration
    $config = [
        'test_email' => 'test@example.com' // Change this to your test email
    ];
    
    // Allow override from command line
    if (isset($argv[1])) {
        $config['test_email'] = $argv[1];
    }
    
    echo "Test email address: {$config['test_email']}\n\n";
    
    try {
        $tester = new EmailPatternTest($config);
        $results = $tester->run();
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        
        $summary = $tester->getSummary();
        echo "Total patterns tested: {$summary['total_patterns']}\n";
        echo "Successful: {$summary['successful']}\n";
        echo "Failed: {$summary['failed']}\n";
        echo "Success rate: {$summary['success_rate']}%\n";
        
        if ($summary['failed'] > 0) {
            echo "\nFailed patterns:\n";
            foreach ($results as $result) {
                if (!$result['success']) {
                    echo "- {$result['pattern']} ({$result['source']}): {$result['error']}\n";
                }
            }
        }
        
    } catch (Exception $e) {
        echo "ERROR: {$e->getMessage()}\n";
        exit(1);
    }
}

?>