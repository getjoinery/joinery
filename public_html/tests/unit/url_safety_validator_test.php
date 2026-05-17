<?php
/**
 * Unit test for UrlSafetyValidator::checkAndResolve() — the SSRF guard that
 * also returns validated IPs for connection pinning (the DNS-rebinding fix).
 *
 * Runs offline: DNS is supplied through DnsResolver::setBackend().
 * Run:  php tests/unit/url_safety_validator_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/UrlSafetyValidator.php'));

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
/** Returns true if calling $fn throws UnsafeUrlException. */
function throws_unsafe(callable $fn) {
    try { $fn(); return false; }
    catch (UnsafeUrlException $e) { return true; }
}

DnsResolver::setBackend(new FakeDnsBackend([
    'pub.example.com|' . DNS_A     => [['ip' => '93.184.216.34']],
    'pub.example.com|' . DNS_AAAA  => [],
    // One public, one private — the private address must fail the whole URL.
    'mixed.example.com|' . DNS_A   => [['ip' => '93.184.216.34'], ['ip' => '10.0.0.5']],
    'mixed.example.com|' . DNS_AAAA => [],
    // Resolver failure.
    'broken.example.com|' . DNS_A  => false,
]));

// ── checkAndResolve returns validated IPs for a public host ──────────────
$pin = UrlSafetyValidator::checkAndResolve('https://pub.example.com/path');
check('public host: returns host/port/ips',
      $pin['host'] === 'pub.example.com' && $pin['port'] === 443 && $pin['ips'] === ['93.184.216.34']);

// ── a private IP anywhere in the resolved set fails the URL ──────────────
check('host resolving to a private IP is rejected',
      throws_unsafe(function () { UrlSafetyValidator::checkAndResolve('http://mixed.example.com/'); }));

// ── IP literals: validated directly, no pin set (no DNS happens) ─────────
$lit = UrlSafetyValidator::checkAndResolve('https://93.184.216.34/');
check('public IP literal: allowed, ips empty (nothing to pin)', $lit['ips'] === []);
check('private IP literal is rejected',
      throws_unsafe(function () { UrlSafetyValidator::checkAndResolve('http://127.0.0.1/'); }));

// ── scheme / port / hostname gates ───────────────────────────────────────
check('non-http scheme is rejected',
      throws_unsafe(function () { UrlSafetyValidator::checkAndResolve('ftp://pub.example.com/'); }));
check('non-allowed port is rejected',
      throws_unsafe(function () { UrlSafetyValidator::checkAndResolve('http://pub.example.com:8080/'); }));
check('blocked hostname (localhost) is rejected',
      throws_unsafe(function () { UrlSafetyValidator::checkAndResolve('http://localhost/'); }));

// ── fail-closed behaviour ────────────────────────────────────────────────
check('resolver failure fails closed (rejected)',
      throws_unsafe(function () { UrlSafetyValidator::checkAndResolve('http://broken.example.com/'); }));
check('host with no records fails closed (rejected)',
      throws_unsafe(function () { UrlSafetyValidator::checkAndResolve('http://norecords.example.com/'); }));

// ── check() still works as the void-returning wrapper ────────────────────
$ok = true;
try { UrlSafetyValidator::check('https://pub.example.com/'); }
catch (UnsafeUrlException $e) { $ok = false; }
check('check() wrapper passes a safe URL silently', $ok);

DnsResolver::clearBackend();

echo "\n$tests run, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
