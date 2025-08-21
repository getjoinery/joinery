<?php
// tests/email/suites/ServiceTests.php
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