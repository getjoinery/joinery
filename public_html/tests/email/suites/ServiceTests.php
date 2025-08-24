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
        $results['mailgun_sending'] = $this->testMailgunSending();
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
            // Create a test email using new EmailMessage + EmailSender system
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'SMTP-TEST-' . date('His'),
                'resend' => false,
            ]);
            
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'))
                   ->to($testRecipient, 'SMTP Test Recipient')
                   ->subject('SMTP Test Email - ' . date('Y-m-d H:i:s'));
            
            $sender = new EmailSender();
            $sendResult = $sender->send($message);
            
            return [
                'passed' => $sendResult,
                'message' => $sendResult ? "Successfully sent via SMTP to $testRecipient" : 'SMTP sending failed',
                'details' => [
                    'service_type' => 'smtp',
                    'test_recipient' => $testRecipient,
                    'smtp_host' => $settings->get_setting('smtp_host'),
                    'smtp_port' => $settings->get_setting('smtp_port'),
                    'smtp_auth' => $settings->get_setting('smtp_username') ? 'enabled' : 'disabled',
                    'send_result' => $sendResult,
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
    
    private function testMailgunSending(): array {
        $settings = Globalvars::get_instance();
        $testRecipient = $settings->get_setting('email_test_recipient');
        
        if (empty($testRecipient)) {
            return [
                'passed' => false,
                'message' => 'No test recipient configured',
            ];
        }
        
        // Check if Mailgun is configured
        $apiKey = $settings->get_setting('mailgun_api_key');
        $domain = $settings->get_setting('mailgun_domain');
        
        if (empty($apiKey) || empty($domain)) {
            return [
                'passed' => false,
                'message' => 'Mailgun not configured - cannot test sending',
                'details' => [
                    'has_api_key' => !empty($apiKey),
                    'has_domain' => !empty($domain),
                ]
            ];
        }
        
        try {
            // Create a test email using new EmailMessage + EmailSender system  
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'MAILGUN-TEST-' . date('His'),
                'resend' => false,
            ]);
            
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'))
                   ->to($testRecipient, 'Mailgun Test Recipient')
                   ->subject('Mailgun Test Email - ' . date('Y-m-d H:i:s'));
            
            $sender = new EmailSender();
            $sendResult = $sender->send($message);
            
            return [
                'passed' => $sendResult,
                'message' => $sendResult ? "Successfully sent via Mailgun to $testRecipient" : 'Mailgun sending failed',
                'details' => [
                    'service_type' => 'mailgun',
                    'test_recipient' => $testRecipient,
                    'mailgun_domain' => $domain,
                    'send_result' => $sendResult,
                ]
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Mailgun sending failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'test_recipient' => $testRecipient,
                ]
            ];
        }
    }
    
    private function testServiceDetection(): array {
        $settings = Globalvars::get_instance();
        
        // Test the new service selection system
        $currentService = $settings->get_setting('email_service') ?: 'mailgun';
        $fallbackService = $settings->get_setting('email_fallback_service') ?: 'smtp';
        
        // Test service validation using EmailSender (moved from EmailTemplate)
        $primaryValidation = EmailSender::validateService($currentService);
        $fallbackValidation = EmailSender::validateService($fallbackService);
        
        $passed = in_array($currentService, ['smtp', 'mailgun']) && 
                  in_array($fallbackService, ['smtp', 'mailgun']);
        
        return [
            'passed' => $passed,
            'message' => "Primary: $currentService, Fallback: $fallbackService",
            'details' => [
                'primary_service' => $currentService,
                'fallback_service' => $fallbackService,
                'primary_valid' => $primaryValidation['valid'],
                'fallback_valid' => $fallbackValidation['valid'],
                'primary_errors' => $primaryValidation['errors'],
                'fallback_errors' => $fallbackValidation['errors'],
            ]
        ];
    }
}