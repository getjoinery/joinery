<?php
// tests/email/suites/AuthenticationTests.php
require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));

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
        $mailgunDomain = $settings->get_setting('mailgun_domain');

        if (!$mailgunDomain) {
            return [
                'passed' => false,
                'message' => 'Cannot test SPF without Mailgun domain setting',
            ];
        }

        $check = DnsAuthChecker::checkSPF($mailgunDomain);

        return [
            'passed' => $check['status'] !== 'fail',
            'message' => $check['status'] !== 'fail' ? 'SPF record found' : 'No SPF record found',
            'details' => [
                'domain' => $mailgunDomain,
                'spf_record' => $check['record'] ?: 'None found',
                'status' => $check['status'],
                'detail' => $check['detail'],
            ]
        ];
    }

    private function testDKIMConfiguration(): array {
        $settings = Globalvars::get_instance();
        $mailgunDomain = $settings->get_setting('mailgun_domain');

        if (!$mailgunDomain) {
            return [
                'passed' => false,
                'message' => 'Cannot test DKIM without Mailgun domain setting',
            ];
        }

        $check = DnsAuthChecker::checkDKIM($mailgunDomain);

        return [
            'passed' => $check['status'] === 'pass',
            'message' => $check['status'] === 'pass' ? 'DKIM record found (selector: ' . $check['selector'] . ')' : 'No DKIM records found',
            'details' => [
                'domain' => $mailgunDomain,
                'checked_selectors' => $check['selectors_checked'],
                'found_selector' => $check['selector'] ?: 'None',
            ]
        ];
    }

    private function testDMARCPolicy(): array {
        $settings = Globalvars::get_instance();
        $mailgunDomain = $settings->get_setting('mailgun_domain');

        if (!$mailgunDomain) {
            return [
                'passed' => false,
                'message' => 'Cannot test DMARC without Mailgun domain setting',
            ];
        }

        $check = DnsAuthChecker::checkDMARC($mailgunDomain);
        $policy = $check['policy'] ?: 'none';

        return [
            'passed' => $check['status'] !== 'fail',
            'message' => $check['status'] !== 'fail'
                ? 'DMARC policy found: ' . $policy . ($policy === 'none' ? ' (monitoring mode, no enforcement)' : '')
                : 'No DMARC policy found',
            'details' => [
                'domain' => $mailgunDomain,
                'dmarc_domain' => '_dmarc.' . $mailgunDomain,
                'dmarc_record' => $check['record'] ?: 'None found',
                'policy' => $policy,
                'status' => $check['status'],
            ]
        ];
    }
}