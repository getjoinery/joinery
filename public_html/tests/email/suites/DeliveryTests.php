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
        // Create a test email that always goes to our test recipient
        if ($this->runner) {
            $email = $this->runner->createTestEmail();
        } else {
            $email = new EmailTemplate('default_outer_template');
            $email->clear_recipients();
            $email->add_recipient($this->config['test_email'], 'Test Recipient');
        }
        
        $email->fill_template([
            'subject' => 'Redirect Test',
            'mail_body' => '<p>This should be redirected to test recipient</p>',
        ]);
        
        // Send with hardcoded test mode (always safe)
        $sent = $email->send(false);
        
        return [
            'passed' => $sent,
            'message' => $sent ? 'Email sent to test recipient: ' . $this->config['test_email'] : 'Email sending failed',
            'test_recipient' => $this->config['test_email'],
        ];
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
        if ($this->runner) {
            $email = $this->runner->createTestEmail();
        } else {
            $email = new EmailTemplate('default_outer_template');
            $email->clear_recipients();
            $email->add_recipient($this->config['test_email'], 'Test Recipient');
        }
        
        $email->fill_template([
            'subject' => 'Service Sending Test',
            'mail_body' => '<p>Testing service sending</p>',
        ]);
        
        $serviceType = $email->getServiceType();
        $sent = $email->send(false);
        
        return [
            'passed' => $sent,
            'message' => $sent ? "Successfully sent via $serviceType to " . $this->config['test_email'] : "Failed to send via $serviceType",
            'service_used' => $serviceType,
        ];
    }
}