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