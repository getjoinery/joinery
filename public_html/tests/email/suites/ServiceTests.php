<?php
// tests/email/suites/ServiceTests.php

require_once(__DIR__ . '/../../../includes/SmtpMailer.php');

class ServiceTests {
    private array $config;
    private $runner;
    
    public function __construct(array $config, $runner = null) {
        $this->config = $config;
        $this->runner = $runner;
    }
    
    public function run(): array {
        $results = [];
        
        $results['smtp_config'] = $this->testSMTPConfiguration();
        $results['smtp_connection'] = $this->testSMTPConnection();
        $results['smtp_sending'] = $this->testSMTPSending();
        $results['mailgun_config'] = $this->testMailgunConfiguration();
        $results['service_detection'] = $this->testServiceDetection();
        
        return $results;
    }
    
    private function testSMTPConfiguration(): array {
        $settings = Globalvars::get_instance();
        
        // Test if SMTP settings are configured
        $host = $settings->get_setting('smtp_host');
        $port = $settings->get_setting('smtp_port');
        
        return [
            'passed' => !empty($host) && !empty($port),
            'message' => empty($host) ? 'SMTP host not configured' : 'SMTP configuration found',
            'details' => [
                'host' => $host ?: 'Not set',
                'port' => $port ?: 'Not set',
                'auth' => $settings->get_setting('smtp_auth') ? 'Enabled' : 'Disabled',
            ]
        ];
    }
    
    private function testSMTPConnection(): array {
        $settings = Globalvars::get_instance();
        $host = $settings->get_setting('smtp_host');
        $port = intval($settings->get_setting('smtp_port') ?: 25);
        
        if (empty($host)) {
            return ['passed' => false, 'message' => 'No SMTP host configured'];
        }
        
        // Test connection using socket
        $connection = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$connection) {
            return [
                'passed' => false,
                'message' => "SMTP connection failed: $errstr ($errno)",
            ];
        }
        
        fclose($connection);
        return [
            'passed' => true,
            'message' => "Successfully connected to $host:$port",
        ];
    }
    
    private function testSMTPSending(): array {
        $settings = Globalvars::get_instance();
        $testRecipient = $this->config['test_email'] ?? $settings->get_setting('email_test_recipient');
        
        if (!$testRecipient) {
            return [
                'passed' => false,
                'message' => 'No test recipient configured',
            ];
        }
        
        try {
            // Create a test email using SMTP specifically
            $email = new EmailTemplate('activation_content');
            $email->email_from = $settings->get_setting('defaultemail');
            $email->email_from_name = $settings->get_setting('defaultemailname');
            $email->add_recipient($testRecipient, 'SMTP Test Recipient');
            
            $email->fill_template([
                'act_code' => 'SMTP-TEST-' . date('His'),
                'resend' => false,
            ]);
            
            // Initialize the mailer manually since it's normally done in send()
            $email->mailer = new SmtpMailer();
            
            // Force SMTP sending
            $originalServiceType = $email->getServiceType();
            $email->mailer->isSMTP();
            
            // Configure SMTP settings
            $email->mailer->Host = $settings->get_setting('smtp_host');
            $email->mailer->Port = $settings->get_setting('smtp_port');
            $email->mailer->SMTPAuth = $settings->get_setting('smtp_auth');
            if ($email->mailer->SMTPAuth) {
                $email->mailer->Username = $settings->get_setting('smtp_username');
                $email->mailer->Password = $settings->get_setting('smtp_password');
            }
            
            // Set the subject manually since we're bypassing normal EmailTemplate processing
            $email->mailer->Subject = $email->getEmailSubject() ?: 'SMTP Test Email';
            
            $sendResult = $email->send();
            
            return [
                'passed' => $sendResult,
                'message' => $sendResult ? "Successfully sent via SMTP to $testRecipient" : 'SMTP sending failed',
                'details' => [
                    'service_type' => 'smtp',
                    'test_recipient' => $testRecipient,
                    'smtp_host' => $settings->get_setting('smtp_host'),
                    'smtp_port' => $settings->get_setting('smtp_port'),
                    'smtp_auth' => $settings->get_setting('smtp_auth') ? 'enabled' : 'disabled',
                    'send_result' => $sendResult,
                    'original_service_type' => $originalServiceType,
                ]
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'SMTP sending failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'smtp_host' => $settings->get_setting('smtp_host'),
                ]
            ];
        }
    }
    
    private function testMailgunConfiguration(): array {
        $settings = Globalvars::get_instance();
        $apiKey = $settings->get_setting('mailgun_api_key');
        $domain = $settings->get_setting('mailgun_domain');
        
        return [
            'passed' => !empty($apiKey) && !empty($domain),
            'message' => (!empty($apiKey) && !empty($domain)) ? 'Mailgun configured' : 'Mailgun not configured',
            'details' => [
                'has_api_key' => !empty($apiKey),
                'has_domain' => !empty($domain),
                'domain' => $domain ?: 'Not set',
            ]
        ];
    }
    
    private function testServiceDetection(): array {
        // Use the new getServiceType() method from our refactoring
        $email = new EmailTemplate(NULL);
        $serviceType = $email->getServiceType();
        
        return [
            'passed' => in_array($serviceType, ['smtp', 'mailgun']),
            'message' => "Detected service: $serviceType",
            'service' => $serviceType,
        ];
    }
}