<?php
// tests/email/EmailTestRunner.php
require_once(__DIR__ . '/../../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/EmailTemplate.php');

class EmailTestRunner {
    private array $config;
    private array $results = [];
    private $originalSettings = [];
    
    public function __construct(array $config = []) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    public function runAllTests(): array {
        $this->enableTestMode();
        
        $testSuites = [
            'service' => new ServiceTests($this->config, $this),
            'template' => new TemplateTests($this->config, $this),
            'delivery' => new DeliveryTests($this->config, $this),
            'authentication' => new AuthenticationTests($this->config, $this),
        ];
        
        foreach ($testSuites as $name => $suite) {
            $this->results[$name] = $suite->run();
        }
        
        $this->restoreSettings();
        return $this->results;
    }
    
    public function runMailgunTests(): array {
        $this->enableTestMode();
        
        $testSuites = [
            'service' => new ServiceTests($this->config, $this),
            'template' => new TemplateTests($this->config, $this),
            'delivery' => new DeliveryTests($this->config, $this),
        ];
        
        // Run service, template, and delivery tests - exclude authentication/DNS tests
        foreach ($testSuites as $name => $suite) {
            $allResults = $suite->run();
            
            if ($name === 'service') {
                // For service tests, exclude SMTP sending test
                unset($allResults['smtp_sending']);
            }
            
            $this->results[$name] = $allResults;
        }
        
        $this->restoreSettings();
        return $this->results;
    }
    
    public function runSmtpTests(): array {
        $this->enableTestMode();
        
        $testSuites = [
            'service' => new ServiceTests($this->config, $this),
            'template' => new TemplateTests($this->config, $this),
        ];
        
        // Run limited test suites focused on SMTP functionality
        foreach ($testSuites as $name => $suite) {
            $allResults = $suite->run();
            
            if ($name === 'service') {
                // For service tests, only include SMTP-related tests
                $smtpResults = [];
                if (isset($allResults['smtp_config'])) $smtpResults['smtp_config'] = $allResults['smtp_config'];
                if (isset($allResults['smtp_connection'])) $smtpResults['smtp_connection'] = $allResults['smtp_connection'];
                if (isset($allResults['smtp_sending'])) $smtpResults['smtp_sending'] = $allResults['smtp_sending'];
                if (isset($allResults['service_detection'])) $smtpResults['service_detection'] = $allResults['service_detection'];
                $allResults = $smtpResults;
            }
            
            $this->results[$name] = $allResults;
        }
        
        $this->restoreSettings();
        return $this->results;
    }
    
    public function runDomainTests(): array {
        $this->enableTestMode();
        
        $testSuites = [
            'authentication' => new AuthenticationTests($this->config, $this),
        ];
        
        // Run only authentication/DNS tests
        foreach ($testSuites as $name => $suite) {
            $this->results[$name] = $suite->run();
        }
        
        $this->restoreSettings();
        return $this->results;
    }
    
    private function enableTestMode() {
        // Test mode is handled by overriding recipients in individual test methods
        // No global settings changes needed
        $this->testModeEnabled = true;
    }
    
    private function restoreSettings() {
        // No settings to restore since we don't modify global settings
        $this->testModeEnabled = false;
    }
    
    public function createTestEmail($template = 'default_outer_template') {
        $email = new EmailTemplate($template);
        // Always use our test recipient instead of any other recipients
        $email->clear_recipients();
        $email->add_recipient($this->config['test_email'], 'Test Recipient');
        return $email;
    }
    
    private function getDefaultConfig(): array {
        $settings = Globalvars::get_instance();
        $defaultEmail = $settings->get_setting('defaultemail');
        $domain = 'example.com';
        
        // Extract domain from default email if available
        if ($defaultEmail && strpos($defaultEmail, '@') !== false) {
            $domain = substr($defaultEmail, strpos($defaultEmail, '@') + 1);
        }
        
        return [
            'test_email' => $settings->get_setting('email_test_recipient') ?: 'emailtest@' . $domain,
            'test_smtp' => [
                'host' => $settings->get_setting('smtp_host') ?: '',
                'port' => $settings->get_setting('smtp_port') ?: '587',
                'username' => $settings->get_setting('smtp_username') ?: '',
                'password' => $settings->get_setting('smtp_password') ?: '',
            ],
            'test_domains' => [
                'primary' => $domain,
                'secondary' => $domain, // Could be expanded if needed
            ],
            'features' => [
                'test_smtp_connection' => true,
                'test_authentication' => true,
                'test_debug_logging' => true,
                'test_template_processing' => true,
            ]
        ];
    }
}