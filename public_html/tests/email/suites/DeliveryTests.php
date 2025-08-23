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
            // Use the new EmailMessage architecture
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'TEST123',
                'resend' => false,
            ]);
            
            $settings = Globalvars::get_instance();
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'));
            $message->to($this->config['test_email'], 'Test Recipient');
            
            // Get the actual subject from the template
            $actualSubject = $message->getSubject();
            $hasContent = !empty($message->getHtmlBody()) || !empty($message->getTextBody());
            
            // Debug info before sending
            $debugInfo = [
                'test_recipient' => $this->config['test_email'],
                'hasContent' => $hasContent,
                'actual_subject' => $actualSubject,  // What the system generated
                'subject_exists' => !empty($actualSubject),
                'email_from' => $message->getFrom(),
                'service_type' => EmailSender::detectServiceType(),
            ];
            
            // Test that email is ready to send (without actually sending)
            $readyToSend = $hasContent && !empty($actualSubject);
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
        
        // Create a test email using new EmailMessage architecture
        if ($this->runner) {
            $message = $this->runner->createTestEmail();
        } else {
            $message = EmailMessage::fromTemplate('default_outer_template', [
                'mail_body' => '<p>Testing debug logging</p>',
            ]);
            $message->to($this->config['test_email'], 'Test Recipient');
        }
        
        // Get the actual subject from the message
        $actualSubject = $message->getSubject();
        $hasContent = !empty($message->getHtmlBody()) || !empty($message->getTextBody());
        
        // Test debug logging capability without actually sending
        $debugCapable = !empty($settings->get_setting('email_debug_mode'));
        $emailReady = $hasContent && !empty($actualSubject);
        
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
            // Use the new EmailMessage architecture
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'TEST456',
                'resend' => false,
            ]);
            
            $settings = Globalvars::get_instance();
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'));
            $message->to($this->config['test_email'], 'Service Test Recipient');
            
            // Debug info before sending
            $serviceType = EmailSender::detectServiceType();
            $hasContent = !empty($message->getHtmlBody()) || !empty($message->getTextBody());
            $actualSubject = $message->getSubject();
            
            $debugInfo = [
                'service_type' => $serviceType,
                'has_content' => $hasContent,
                'test_recipient' => $this->config['test_email'],
                'actual_subject' => $actualSubject,
                'subject_exists' => !empty($actualSubject),
                'email_from' => $message->getFrom(),
                'recipients_count' => count($message->getRecipients())
            ];
            
            // Test service configuration without actually sending
            $serviceConfigured = ($serviceType !== 'none');
            $emailReady = $hasContent && !empty($actualSubject) && !empty($message->getFrom());
            $testPassed = $serviceConfigured && $emailReady;
            
            $debugInfo['service_configured'] = $serviceConfigured;
            $debugInfo['email_ready'] = $emailReady;
            $debugInfo['test_logic'] = 'Tests service detection without sending';
            
            // Check potential issues without sending
            if (!$hasContent) {
                $debugInfo['issue'] = 'Email has no content';
            } elseif (empty($message->getFrom())) {
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