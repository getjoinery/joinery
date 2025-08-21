<?php
// tests/email/suites/AuthenticationTests.php
class AuthenticationTests {
    private array $config;
    private $runner;
    
    public function __construct(array $config, $runner = null) {
        $this->config = $config;
        $this->runner = $runner;
    }
    
    public function run(): array {
        $results = [];
        
        $results['domain_settings'] = $this->testDomainSettings();
        $results['spf_record'] = $this->testSPFRecord();
        $results['dkim_config'] = $this->testDKIMConfiguration();
        $results['dmarc_policy'] = $this->testDMARCPolicy();
        
        return $results;
    }
    
    private function testDomainSettings(): array {
        $settings = Globalvars::get_instance();
        $defaultEmail = $settings->get_setting('defaultemail');
        
        if (!$defaultEmail || strpos($defaultEmail, '@') === false) {
            return [
                'passed' => false,
                'message' => 'Default email not configured properly',
                'details' => ['defaultemail' => $defaultEmail ?: 'Not set']
            ];
        }
        
        $domain = substr($defaultEmail, strpos($defaultEmail, '@') + 1);
        
        return [
            'passed' => !empty($domain),
            'message' => $domain ? "Email domain: $domain" : 'Could not extract domain',
            'details' => [
                'defaultemail' => $defaultEmail,
                'domain' => $domain,
            ]
        ];
    }
    
    private function testSPFRecord(): array {
        $settings = Globalvars::get_instance();
        $defaultEmail = $settings->get_setting('defaultemail');
        
        if (!$defaultEmail || strpos($defaultEmail, '@') === false) {
            return [
                'passed' => false,
                'message' => 'Cannot test SPF without valid default email',
            ];
        }
        
        $domain = substr($defaultEmail, strpos($defaultEmail, '@') + 1);
        
        // Simple DNS TXT record check for SPF
        $txtRecords = @dns_get_record($domain, DNS_TXT);
        $spfFound = false;
        $spfRecord = '';
        
        if ($txtRecords) {
            foreach ($txtRecords as $record) {
                if (isset($record['txt']) && strpos($record['txt'], 'v=spf1') === 0) {
                    $spfFound = true;
                    $spfRecord = $record['txt'];
                    break;
                }
            }
        }
        
        return [
            'passed' => $spfFound,
            'message' => $spfFound ? 'SPF record found' : 'No SPF record found',
            'details' => [
                'domain' => $domain,
                'spf_record' => $spfRecord ?: 'None found',
                'dns_lookup_success' => $txtRecords !== false,
            ]
        ];
    }
    
    private function testDKIMConfiguration(): array {
        $settings = Globalvars::get_instance();
        $defaultEmail = $settings->get_setting('defaultemail');
        
        if (!$defaultEmail || strpos($defaultEmail, '@') === false) {
            return [
                'passed' => false,
                'message' => 'Cannot test DKIM without valid default email',
            ];
        }
        
        $domain = substr($defaultEmail, strpos($defaultEmail, '@') + 1);
        
        // Check common DKIM selectors
        $commonSelectors = ['default', 'mail', 'dkim', 'key1', 'selector1'];
        $dkimFound = false;
        $foundSelector = '';
        
        foreach ($commonSelectors as $selector) {
            $dkimDomain = "$selector._domainkey.$domain";
            $txtRecords = @dns_get_record($dkimDomain, DNS_TXT);
            
            if ($txtRecords) {
                foreach ($txtRecords as $record) {
                    if (isset($record['txt']) && (strpos($record['txt'], 'v=DKIM1') !== false || strpos($record['txt'], 'k=rsa') !== false)) {
                        $dkimFound = true;
                        $foundSelector = $selector;
                        break 2;
                    }
                }
            }
        }
        
        return [
            'passed' => $dkimFound,
            'message' => $dkimFound ? "DKIM record found (selector: $foundSelector)" : 'No DKIM records found',
            'details' => [
                'domain' => $domain,
                'checked_selectors' => $commonSelectors,
                'found_selector' => $foundSelector ?: 'None',
            ]
        ];
    }
    
    private function testDMARCPolicy(): array {
        $settings = Globalvars::get_instance();
        $defaultEmail = $settings->get_setting('defaultemail');
        
        if (!$defaultEmail || strpos($defaultEmail, '@') === false) {
            return [
                'passed' => false,
                'message' => 'Cannot test DMARC without valid default email',
            ];
        }
        
        $domain = substr($defaultEmail, strpos($defaultEmail, '@') + 1);
        $dmarcDomain = "_dmarc.$domain";
        
        $txtRecords = @dns_get_record($dmarcDomain, DNS_TXT);
        $dmarcFound = false;
        $dmarcRecord = '';
        $policy = 'none';
        
        if ($txtRecords) {
            foreach ($txtRecords as $record) {
                if (isset($record['txt']) && strpos($record['txt'], 'v=DMARC1') === 0) {
                    $dmarcFound = true;
                    $dmarcRecord = $record['txt'];
                    
                    // Extract policy
                    if (preg_match('/p=([^;]+)/', $dmarcRecord, $matches)) {
                        $policy = trim($matches[1]);
                    }
                    break;
                }
            }
        }
        
        return [
            'passed' => $dmarcFound,
            'message' => $dmarcFound ? "DMARC policy found: $policy" : 'No DMARC policy found',
            'details' => [
                'domain' => $domain,
                'dmarc_domain' => $dmarcDomain,
                'dmarc_record' => $dmarcRecord ?: 'None found',
                'policy' => $policy,
                'dns_lookup_success' => $txtRecords !== false,
            ]
        ];
    }
}