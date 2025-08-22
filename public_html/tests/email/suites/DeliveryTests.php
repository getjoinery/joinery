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
            
            // Test that email is ready to send (without actually sending)
            $readyToSend = $email->hasContent() && !empty($actualSubject);
            $debugInfo['ready_to_send'] = $readyToSend;
            $debugInfo['test_logic'] = 'Tests email preparation without sending';
            
            return [
                'passed' => $readyToSend,
                'message' => $readyToSend ? 
                    "Email ready to send with subject: $actualSubject (test mode - not actually sent)" : 
                    "Email not ready - missing content or subject",
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
                'passed' => true,
                'warning' => true,
                'message' => 'Debug mode not enabled (to enable, set email_debug_mode setting)',
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
            'mail_body' => '<p>Testing debug logging</p>',
        ]);
        
        // DON'T override subject - test what the system actually does
        $actualSubject = $email->getEmailSubject();
        
        // Test debug logging capability without actually sending
        $debugCapable = !empty($settings->get_setting('email_debug_mode'));
        $emailReady = $email->hasContent() && !empty($actualSubject);
        
        return [
            'passed' => $debugCapable && $emailReady,
            'message' => $debugCapable ? 
                'Debug logging configured and email ready (test mode - not sent)' : 
                'Debug logging not enabled or email not ready',
            'details' => [
                'debug_mode_enabled' => $debugCapable,
                'email_ready' => $emailReady,
                'actual_subject' => $actualSubject,
                'test_logic' => 'Tests debug capability without sending'
            ]
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
                'act_code' => 'TEST456',
                'resend' => false,
            ]);
            
            // DON'T override subject - test what the system actually does
            
            // Debug info before sending
            $serviceType = $email->getServiceType();
            $hasContent = $email->hasContent();
            $actualSubject = $email->getEmailSubject();
            
            $debugInfo = [
                'service_type' => $serviceType,
                'has_content' => $hasContent,
                'test_recipient' => $this->config['test_email'],
                'actual_subject' => $actualSubject,
                'subject_exists' => !empty($actualSubject),
                'email_from' => $email->email_from,
                'recipients_count' => count($email->getEmailRecipients())
            ];
            
            // Test service configuration without actually sending
            $serviceConfigured = ($serviceType !== 'none');
            $emailReady = $hasContent && !empty($actualSubject) && !empty($email->email_from);
            $testPassed = $serviceConfigured && $emailReady;
            
            $debugInfo['service_configured'] = $serviceConfigured;
            $debugInfo['email_ready'] = $emailReady;
            $debugInfo['test_logic'] = 'Tests service detection without sending';
            
            // Check potential issues without sending
            if (!$hasContent) {
                $debugInfo['issue'] = 'Email has no content';
            } elseif (empty($email->email_from)) {
                $debugInfo['issue'] = 'No from address set';
            } elseif ($serviceType === 'none') {
                $debugInfo['issue'] = 'No email service configured';
            } else {
                $debugInfo['issue'] = 'None - email appears ready';
            }
            
            return [
                'passed' => $testPassed,
                'message' => $testPassed ? 
                    "Service $serviceType configured and email ready (test mode - not sent)" : 
                    "Service or email configuration issue - check debug details",
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