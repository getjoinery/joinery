<?php
/**
 * Unit test for DnsAuthChecker.
 *
 * Runs with no real network: a fake backend is installed via
 * DnsResolver::setBackend(). This is the deterministic counterpart to the
 * live-DNS integration test in tests/email/suites/AuthenticationTests.php —
 * it makes DnsAuthChecker's SPF/DKIM/DMARC logic testable for the first time.
 * Run:  php tests/unit/dns_auth_checker_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));

/**
 * Fake raw-DNS layer. getRecords() mirrors dns_get_record(): an array of
 * record arrays, or false to simulate a resolver failure.
 */
class FakeDnsBackend {
    private $data;
    public function __construct(array $data) { $this->data = $data; }
    public function getRecords($name, $type) {
        $key = $name . '|' . $type;
        return array_key_exists($key, $this->data) ? $this->data[$key] : [];
    }
}

$tests = 0;
$failures = 0;
function check($label, $condition) {
    global $tests, $failures;
    $tests++;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$condition) { $GLOBALS['failures']++; }
}

// A DKIM TXT value with a public key long enough to satisfy isDKIMRecord().
$dkim_txt = 'v=DKIM1; k=rsa; p=' . str_repeat('A', 200);

DnsResolver::setBackend(new FakeDnsBackend([
    // ── SPF ──
    'pass.example.com|' . DNS_TXT      => [['txt' => 'v=spf1 include:_spf.example.com -all']],
    'weak.example.com|' . DNS_TXT      => [['txt' => 'v=spf1 +all']],
    'neutral.example.com|' . DNS_TXT   => [['txt' => 'v=spf1 ?all']],
    'multi.example.com|' . DNS_TXT     => [['txt' => 'v=spf1 -all'], ['txt' => 'v=spf1 ~all']],
    'notxt.example.com|' . DNS_TXT     => [],
    'hastxt.example.com|' . DNS_TXT    => [['txt' => 'google-site-verification=abc']],
    'broken.example.com|' . DNS_TXT    => false, // simulated resolver failure

    // ── DKIM ──
    'mail._domainkey.dkimok.example.com|' . DNS_TXT      => [['txt' => $dkim_txt]],
    'mail._domainkey.dkimcname.example.com|' . DNS_CNAME => [['target' => 'k.dkimhost.example.net']],
    'k.dkimhost.example.net|' . DNS_TXT                  => [['txt' => $dkim_txt]],

    // ── DMARC ──
    '_dmarc.reject.example.com|' . DNS_TXT      => [['txt' => 'v=DMARC1; p=reject; rua=mailto:r@example.com']],
    '_dmarc.monitor.example.com|' . DNS_TXT     => [['txt' => 'v=DMARC1; p=none']],
]));

// ── checkSPF ─────────────────────────────────────────────────────────────
check('checkSPF pass on -all',        DnsAuthChecker::checkSPF('pass.example.com')['status'] === 'pass');
check('checkSPF warn on +all',        DnsAuthChecker::checkSPF('weak.example.com')['status'] === 'warn');
check('checkSPF warn on ?all',        DnsAuthChecker::checkSPF('neutral.example.com')['status'] === 'warn');
check('checkSPF warn on multiple',    DnsAuthChecker::checkSPF('multi.example.com')['status'] === 'warn');
check('checkSPF fail when no TXT',    DnsAuthChecker::checkSPF('notxt.example.com')['status'] === 'fail');
check('checkSPF fail when TXT but no SPF', DnsAuthChecker::checkSPF('hastxt.example.com')['detail'] === 'No SPF record found');
check('checkSPF fail-open: resolver failure reads as no record',
      DnsAuthChecker::checkSPF('broken.example.com')['status'] === 'fail');

// ── checkDKIM ────────────────────────────────────────────────────────────
$dkim_ok = DnsAuthChecker::checkDKIM('dkimok.example.com', ['mail']);
check('checkDKIM pass via TXT',        $dkim_ok['status'] === 'pass' && $dkim_ok['selector'] === 'mail');
$dkim_cname = DnsAuthChecker::checkDKIM('dkimcname.example.com', ['mail']);
check('checkDKIM pass via CNAME',      $dkim_cname['status'] === 'pass');
check('checkDKIM fail when absent',    DnsAuthChecker::checkDKIM('dkimnone.example.com', ['mail'])['status'] === 'fail');

// ── checkDMARC ───────────────────────────────────────────────────────────
check('checkDMARC pass on p=reject',   DnsAuthChecker::checkDMARC('reject.example.com')['status'] === 'pass');
$dmarc_mon = DnsAuthChecker::checkDMARC('monitor.example.com');
check('checkDMARC warn on p=none',     $dmarc_mon['status'] === 'warn' && $dmarc_mon['policy'] === 'none');
check('checkDMARC fail when absent',   DnsAuthChecker::checkDMARC('dmarcmissing.example.com')['status'] === 'fail');

// ── quickCheck rolls all three up ────────────────────────────────────────
$quick = DnsAuthChecker::quickCheck('pass.example.com', ['mail']);
check('quickCheck returns spf/dkim/dmarc keys',
      isset($quick['spf'], $quick['dkim'], $quick['dmarc']));

DnsResolver::clearBackend();

echo "\n$tests run, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
