<?php
// tests/email/suites/DeliveryTests.php
class DeliveryTests {
    private array $config;
    private EmailTestRunner $runner;
    
    public function __construct(array $config, EmailTestRunner $runner = null) {
        $this->config = $config;
        $this->runner = $runner;
    }
    
    public function run(): array {
        $results = [];
        
        $results['test_mode_redirect'] = $this->testTestModeRedirect();
        $results['debug_logging'] = $this->testDebugLogging();
        $results['service_sending'] = $this->testServiceSending();
        
        return $results;
    }
    
    private function testTestModeRedirect(): array {
        try {
            // Use the working template pattern from TemplateTests
            $email = new EmailTemplate('activation_content');
            $settings = Globalvars::get_instance();
            $email->email_from = $settings->get_setting('defaultemail');
            $email->email_from_name = $settings->get_setting('defaultemailname');
            $email->add_recipient($this->config['test_email'], 'Test Recipient');
            
            $email->fill_template([
                'subject' => 'Test Mode Redirect Test',
                'act_code' => 'TEST123',
                'resend' => false,
            ]);
            
            // Debug info before sending
            $debugInfo = [
                'test_recipient' => $this->config['test_email'],
                'hasContent' => method_exists($email, 'hasContent') ? $email->hasContent() : 'Method not available',
                'email_subject' => method_exists($email, 'getEmailSubject') ? $email->getEmailSubject() : 'Method not available',
                'email_from' => $email->email_from,
                'email_from_name' => $email->email_from_name,
                'service_type' => method_exists($email, 'getServiceType') ? $email->getServiceType() : 'Method not available',
                'recipients_count' => method_exists($email, 'getEmailRecipients') ? count($email->getEmailRecipients()) : 'Method not available',
                'current_settings' => [
                    'email_test_mode' => $settings->get_setting('email_test_mode'),
                    'email_test_recipient' => $settings->get_setting('email_test_recipient'),
                    'email_debug_mode' => $settings->get_setting('email_debug_mode')
                ]
            ];
            
            // Send with debug mode enabled  
            $sent = $email->send(true);
            $debugInfo['send_result'] = $sent;
            
            if (!$sent) {
                // Try to get error details if available
                if (method_exists($email, 'getLastError')) {
                    $debugInfo['last_error'] = $email->getLastError();
                }
            }
            
            return [
                'passed' => $sent,
                'message' => $sent ? 'Email sent to test recipient: ' . $this->config['test_email'] : 'Email sending failed - check debug details',
                'details' => $debugInfo
            ];
            
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Test mode redirect failed with exception: ' . $e->getMessage(),
                'details' => [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine()
                ]
            ];
        }
    }
    
    private function testDebugLogging(): array {
        $settings = Globalvars::get_instance();
        $debugMode = $settings->get_setting('email_debug_mode');
        
        if (!$debugMode) {
            return [
                'passed' => false,
                'message' => 'Debug mode not enabled',
            ];
        }
        
        // Count existing debug logs
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        $countQuery = "SELECT COUNT(*) as count FROM debug_email_logs";
        $beforeCount = $dblink->query($countQuery)->fetch()['count'];
        
        // Send an email to trigger debug logging
        if ($this->runner) {
            $email = $this->runner->createTestEmail();
        } else {
            $email = new EmailTemplate('default_outer_template');
            $email->clear_recipients();
            $email->add_recipient($this->config['test_email'], 'Test Recipient');
        }
        
        $email->fill_template([
            'subject' => 'Debug Logging Test',
            'mail_body' => '<p>Testing debug logging</p>',
        ]);
        
        $email->send(false);
        
        // Check if new debug log was created
        $afterCount = $dblink->query($countQuery)->fetch()['count'];
        
        return [
            'passed' => $afterCount > $beforeCount,
            'message' => $afterCount > $beforeCount ? 'Debug logging working' : 'No debug log created',
            'logs_created' => $afterCount - $beforeCount,
        ];
    }
    
    private function testServiceSending(): array {
        try {
            // Use the working template pattern
            $email = new EmailTemplate('activation_content');
            $settings = Globalvars::get_instance();
            $email->email_from = $settings->get_setting('defaultemail');
            $email->email_from_name = $settings->get_setting('defaultemailname');
            $email->add_recipient($this->config['test_email'], 'Service Test Recipient');
            
            $email->fill_template([
                'subject' => 'Service Sending Test',
                'act_code' => 'TEST456',
                'resend' => false,
            ]);
            
            // Debug info before sending
            $serviceType = $email->getServiceType();
            $hasContent = $email->hasContent();
            
            $debugInfo = [
                'service_type' => $serviceType,
                'has_content' => $hasContent,
                'test_recipient' => $this->config['test_email'],
                'email_subject' => method_exists($email, 'getEmailSubject') ? $email->getEmailSubject() : 'Method not available',
                'email_from' => $email->email_from,
                'recipients_count' => method_exists($email, 'getEmailRecipients') ? count($email->getEmailRecipients()) : 'Method not available'
            ];
            
            // Try sending
            $sent = $email->send(true); // Enable debug mode
            $debugInfo['send_result'] = $sent;
            $debugInfo['send_attempted'] = true;
            
            // Try to get error info if failed
            if (!$sent) {
                if (method_exists($email, 'getLastError')) {
                    $debugInfo['last_error'] = $email->getLastError();
                }
                // Check common failure reasons
                if (!$hasContent) {
                    $debugInfo['failure_reason'] = 'Email has no content (hasContent returned false)';
                } elseif (empty($email->email_from)) {
                    $debugInfo['failure_reason'] = 'No from address set';
                } elseif ($serviceType === 'none') {
                    $debugInfo['failure_reason'] = 'No email service configured';
                } else {
                    $debugInfo['failure_reason'] = 'Unknown - check service configuration';
                }
            }
            
            return [
                'passed' => $sent,
                'message' => $sent ? 
                    "Successfully sent via $serviceType to " . $this->config['test_email'] : 
                    "Failed to send via $serviceType - check debug details",
                'details' => $debugInfo
            ];
            
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Service sending test failed with exception: ' . $e->getMessage(),
                'details' => [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine()
                ]
            ];
        }
    }
}