<?php
/** @joinery-test
 * name: email_auth_dns
 * tier: live
 * env: prod-verify
 * needs: [mailgun]
 * timeout: 120
 */
/**
 * Email-authentication DNS, against the REAL published records for the sending
 * domain (no mocking).
 *
 * DnsAuthChecker's parsing logic is unit-tested offline in dns_auth_checker_test
 * with a fake resolver. This test is the other half: it points the same checker
 * at the live DNS for the configured mailgun_domain and asserts the domain is
 * actually set up to send authenticated mail — a published SPF policy, a
 * resolvable DKIM selector, and a DMARC record. It is prod-verify: it reflects
 * whatever is really published, so it catches a domain whose auth records were
 * never set up or silently expired.
 *
 * Replaces the AuthenticationTests coverage of the retired tests/email/suites
 * framework. Read-only DNS; sends nothing.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));

$settings = Globalvars::get_instance();

section('sending identity is configured');
$default_email = (string)$settings->get_setting('defaultemail');
check($default_email !== '' && strpos($default_email, '@') !== false,
	'defaultemail is a well-formed address', 'got: ' . ($default_email ?: '(empty)'));
$from_domain = $default_email !== '' ? substr($default_email, strpos($default_email, '@') + 1) : '';
check($from_domain !== '', 'a sender domain can be extracted from defaultemail', 'domain: ' . $from_domain);

$mailgun_domain = trim((string)$settings->get_setting('mailgun_domain'));
if ($mailgun_domain === '') {
	harness_skip('mailgun_domain not configured — cannot check published email-auth DNS');
	harness_finish();
}
echo "checking published email-auth DNS for: $mailgun_domain\n";

section('SPF is published');
$spf = DnsAuthChecker::checkSPF($mailgun_domain);
check($spf['status'] !== 'fail', 'SPF lookup did not fail', 'status: ' . $spf['status']);
check(!empty($spf['record']) && stripos((string)$spf['record'], 'v=spf1') !== false,
	'a v=spf1 record is published', 'record: ' . ($spf['record'] ?: 'none'));

section('DKIM selector resolves');
$dkim = DnsAuthChecker::checkDKIM($mailgun_domain);
check($dkim['status'] === 'pass', 'a DKIM selector was found',
	'status: ' . $dkim['status'] . '; checked: ' . implode(',', $dkim['selectors_checked'] ?? array()));
check(!empty($dkim['selector']), 'the resolved DKIM selector is named', 'selector: ' . ($dkim['selector'] ?: 'none'));

section('DMARC record is present');
$dmarc = DnsAuthChecker::checkDMARC($mailgun_domain);
check($dmarc['status'] !== 'fail', 'a DMARC record is published (any policy)',
	'status: ' . $dmarc['status'] . '; policy: ' . ($dmarc['policy'] ?: 'none'));

harness_finish();
