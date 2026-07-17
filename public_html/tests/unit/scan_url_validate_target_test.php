<?php
/** @joinery-test
 * name: scan_url_validate_target
 * tier: safe
 * env: any
 * needs: []
 */
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

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
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
ok('public host accepted', is_array($target));
ok('public host: host returned', $target['host'] === 'pub.example.com');
ok('public host: default https port', $target['port'] === 443);
ok('public host: resolved IPs returned for pinning', $target['ips'] === ['93.184.216.34']);

$target = scan_url_validate_target('http://pub.example.com:8080/page');
ok('explicit port preserved', $target['port'] === 8080);

// ── Accept: public IP literal (no pin needed — no DNS happened) ──────────
$target = scan_url_validate_target('https://93.184.216.34/');
ok('public IPv4 literal accepted', is_array($target));
ok('IP literal: no pin IPs', $target['ips'] === []);

// ── Reject: non-http(s) schemes ──────────────────────────────────────────
ok('ftp scheme rejected', throws_unsafe(fn() => scan_url_validate_target('ftp://pub.example.com/')));
ok('file scheme rejected', throws_unsafe(fn() => scan_url_validate_target('file:///etc/passwd')));
ok('gopher scheme rejected', throws_unsafe(fn() => scan_url_validate_target('gopher://pub.example.com/')));
ok('scheme-less URL rejected', throws_unsafe(fn() => scan_url_validate_target('pub.example.com/page')));

// ── Reject: loopback / private / link-local IPv4 literals ────────────────
ok('127.0.0.1 rejected', throws_unsafe(fn() => scan_url_validate_target('http://127.0.0.1/')));
ok('127/8 (non-.1) rejected', throws_unsafe(fn() => scan_url_validate_target('http://127.6.6.6/')));
ok('10/8 rejected', throws_unsafe(fn() => scan_url_validate_target('http://10.1.2.3/')));
ok('172.16/12 rejected', throws_unsafe(fn() => scan_url_validate_target('http://172.20.0.1/')));
ok('192.168/16 rejected', throws_unsafe(fn() => scan_url_validate_target('http://192.168.1.1/')));
ok('169.254/16 link-local rejected', throws_unsafe(fn() => scan_url_validate_target('http://169.254.169.254/')));
// These four ranges were UNblocked here while the sibling UrlSafetyValidator
// blocked them — a real SSRF hole (0.0.0.0 reaches loopback on Linux). Pin them.
ok('0.0.0.0 rejected', throws_unsafe(fn() => scan_url_validate_target('http://0.0.0.0/')));
ok('100.64/10 CGNAT rejected', throws_unsafe(fn() => scan_url_validate_target('http://100.64.0.1/')));
ok('224/4 multicast rejected', throws_unsafe(fn() => scan_url_validate_target('http://224.0.0.1/')));
ok('240/4 reserved rejected', throws_unsafe(fn() => scan_url_validate_target('http://240.0.0.1/')));
// Boundary: the address just below CGNAT is public and must still be allowed.
ok('100.63.255.255 (just below CGNAT) accepted', is_array(scan_url_validate_target('http://100.63.255.255/')));

// ── Reject: loopback / private / link-local IPv6 literals ────────────────
ok('[::1] loopback rejected', throws_unsafe(fn() => scan_url_validate_target('http://[::1]/')));
ok('[fe80::1] link-local rejected', throws_unsafe(fn() => scan_url_validate_target('http://[fe80::1]/')));
ok('[fd00::1] unique-local rejected', throws_unsafe(fn() => scan_url_validate_target('http://[fd00::1]/')));
ok('[::ffff:10.0.0.5] v4-mapped rejected', throws_unsafe(fn() => scan_url_validate_target('http://[::ffff:10.0.0.5]/')));

// ── Reject: hostname resolving to a private address ─────────────────────
ok('hostname → 10/8 rejected', throws_unsafe(fn() => scan_url_validate_target('https://internal.example.com/')));
ok('hostname → mixed public+private rejected', throws_unsafe(fn() => scan_url_validate_target('https://mixed.example.com/')));
ok('hostname → AAAA loopback rejected', throws_unsafe(fn() => scan_url_validate_target('https://sneaky6.example.com/')));

// ── Reject: resolution failure fails closed ──────────────────────────────
ok('no DNS records rejected', throws_unsafe(fn() => scan_url_validate_target('https://norecords.example.com/')));
ok('resolver failure rejected', throws_unsafe(fn() => scan_url_validate_target('https://broken.example.com/')));

// ── Redirect hop: each hop re-validates, so a hop to a private target ────
// throws even after a clean first hop (this is the loop's per-hop call).
$first_hop = scan_url_validate_target('https://pub.example.com/start');
ok('redirect: first hop (public) accepted', is_array($first_hop));
ok('redirect: hop to private-resolving host rejected',
    throws_unsafe(fn() => scan_url_validate_target('https://redirect-internal.example.com/landing')));
ok('redirect: hop to IP-literal loopback rejected',
    throws_unsafe(fn() => scan_url_validate_target('http://127.0.0.1:8080/admin')));

harness_finish();
