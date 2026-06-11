<?php
/**
 * Unit test for scan_url_validate_target() — the SSRF guard for the
 * dns_filtering scan_url action's server-side URL fetch. Every fetch
 * target (initial URL and each redirect hop) passes through it; any
 * unsafe target throws ScanUrlValidationException (fail closed).
 *
 * Runs offline: DNS is supplied through DnsResolver::setBackend().
 * Run:  php tests/unit/scan_url_validate_target_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/scan_url_logic.php'));

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
/** Returns true if calling $fn throws ScanUrlValidationException. */
function throws_unsafe(callable $fn) {
    try { $fn(); return false; }
    catch (ScanUrlValidationException $e) { return true; }
}

DnsResolver::setBackend(new FakeDnsBackend([
    'pub.example.com|' . DNS_A        => [['ip' => '93.184.216.34']],
    'pub.example.com|' . DNS_AAAA     => [],
    // Hostname resolving to a private address.
    'internal.example.com|' . DNS_A   => [['ip' => '10.0.0.5']],
    'internal.example.com|' . DNS_AAAA => [],
    // One public, one private — the private address must fail the whole URL.
    'mixed.example.com|' . DNS_A      => [['ip' => '93.184.216.34'], ['ip' => '10.0.0.5']],
    'mixed.example.com|' . DNS_AAAA   => [],
    // Public A, but AAAA is the IPv6 loopback.
    'sneaky6.example.com|' . DNS_A    => [['ip' => '93.184.216.34']],
    'sneaky6.example.com|' . DNS_AAAA => [['ipv6' => '::1']],
    // No records at all.
    'norecords.example.com|' . DNS_A    => [],
    'norecords.example.com|' . DNS_AAAA => [],
    // Resolver failure.
    'broken.example.com|' . DNS_A     => false,
    // Redirect-target host that resolves private (the redirect-hop case).
    'redirect-internal.example.com|' . DNS_A    => [['ip' => '192.168.1.10']],
    'redirect-internal.example.com|' . DNS_AAAA => [],
]));

// ── Accept: public hostname returns pin data ─────────────────────────────
$target = scan_url_validate_target('https://pub.example.com/page');
check('public host accepted', is_array($target));
check('public host: host returned', $target['host'] === 'pub.example.com');
check('public host: default https port', $target['port'] === 443);
check('public host: resolved IPs returned for pinning', $target['ips'] === ['93.184.216.34']);

$target = scan_url_validate_target('http://pub.example.com:8080/page');
check('explicit port preserved', $target['port'] === 8080);

// ── Accept: public IP literal (no pin needed — no DNS happened) ──────────
$target = scan_url_validate_target('https://93.184.216.34/');
check('public IPv4 literal accepted', is_array($target));
check('IP literal: no pin IPs', $target['ips'] === []);

// ── Reject: non-http(s) schemes ──────────────────────────────────────────
check('ftp scheme rejected', throws_unsafe(fn() => scan_url_validate_target('ftp://pub.example.com/')));
check('file scheme rejected', throws_unsafe(fn() => scan_url_validate_target('file:///etc/passwd')));
check('gopher scheme rejected', throws_unsafe(fn() => scan_url_validate_target('gopher://pub.example.com/')));
check('scheme-less URL rejected', throws_unsafe(fn() => scan_url_validate_target('pub.example.com/page')));

// ── Reject: loopback / private / link-local IPv4 literals ────────────────
check('127.0.0.1 rejected', throws_unsafe(fn() => scan_url_validate_target('http://127.0.0.1/')));
check('127/8 (non-.1) rejected', throws_unsafe(fn() => scan_url_validate_target('http://127.6.6.6/')));
check('10/8 rejected', throws_unsafe(fn() => scan_url_validate_target('http://10.1.2.3/')));
check('172.16/12 rejected', throws_unsafe(fn() => scan_url_validate_target('http://172.20.0.1/')));
check('192.168/16 rejected', throws_unsafe(fn() => scan_url_validate_target('http://192.168.1.1/')));
check('169.254/16 link-local rejected', throws_unsafe(fn() => scan_url_validate_target('http://169.254.169.254/')));

// ── Reject: loopback / private / link-local IPv6 literals ────────────────
check('[::1] loopback rejected', throws_unsafe(fn() => scan_url_validate_target('http://[::1]/')));
check('[fe80::1] link-local rejected', throws_unsafe(fn() => scan_url_validate_target('http://[fe80::1]/')));
check('[fd00::1] unique-local rejected', throws_unsafe(fn() => scan_url_validate_target('http://[fd00::1]/')));
check('[::ffff:10.0.0.5] v4-mapped rejected', throws_unsafe(fn() => scan_url_validate_target('http://[::ffff:10.0.0.5]/')));

// ── Reject: hostname resolving to a private address ─────────────────────
check('hostname → 10/8 rejected', throws_unsafe(fn() => scan_url_validate_target('https://internal.example.com/')));
check('hostname → mixed public+private rejected', throws_unsafe(fn() => scan_url_validate_target('https://mixed.example.com/')));
check('hostname → AAAA loopback rejected', throws_unsafe(fn() => scan_url_validate_target('https://sneaky6.example.com/')));

// ── Reject: resolution failure fails closed ──────────────────────────────
check('no DNS records rejected', throws_unsafe(fn() => scan_url_validate_target('https://norecords.example.com/')));
check('resolver failure rejected', throws_unsafe(fn() => scan_url_validate_target('https://broken.example.com/')));

// ── Redirect hop: each hop re-validates, so a hop to a private target ────
// throws even after a clean first hop (this is the loop's per-hop call).
$first_hop = scan_url_validate_target('https://pub.example.com/start');
check('redirect: first hop (public) accepted', is_array($first_hop));
check('redirect: hop to private-resolving host rejected',
    throws_unsafe(fn() => scan_url_validate_target('https://redirect-internal.example.com/landing')));
check('redirect: hop to IP-literal loopback rejected',
    throws_unsafe(fn() => scan_url_validate_target('http://127.0.0.1:8080/admin')));

// ── Summary ──────────────────────────────────────────────────────────────
echo "\n{$tests} tests, {$failures} failures\n";
exit($failures ? 1 : 0);
