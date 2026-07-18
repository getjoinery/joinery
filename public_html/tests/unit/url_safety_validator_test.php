<?php
/** @joinery-test
 * name: url_safety_validator
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Unit test for UrlSafetyValidator — the platform's single SSRF guard for
 * server-side URL fetches. Both subsystems that fetch URLs on the server's
 * behalf (the joinery_ai fetch_url tool and the dns_filtering scan_url action)
 * route through this one validator, so this one test covers both. It asserts:
 *   - the return/pin contract (host/port/ips for CURLOPT_RESOLVE)
 *   - the port policy in both modes (default 80/443, and any-port for scan_url)
 *   - scheme and blocked-hostname gates
 *   - the single authoritative IPv4 range table (incl. the 0.0.0.0/CGNAT/
 *     multicast/reserved blocks that a former split let diverge)
 *   - IPv6 loopback/link-local/unique-local/v4-mapped rejection
 *   - hostname resolution + DNS-rebinding (any private address in the set fails)
 *   - fail-closed on resolver failure / no records
 *   - encoded-IP and userinfo obfuscation
 *   - redirect-hop re-validation
 *
 * Runs offline: DNS is supplied through DnsResolver::setBackend().
 * Run:  php tests/unit/url_safety_validator_test.php
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/UrlSafetyValidator.php'));
require_once(__DIR__ . '/../lib/dns_fixtures.php'); // FakeDnsBackend

/** Returns true if calling $fn throws UnsafeUrlException. */
function throws_unsafe(callable $fn) {
    try { $fn(); return false; }
    catch (UnsafeUrlException $e) { return true; }
}

/** Any-port option — the mode the scan_url page scanner uses. */
$ANY_PORT = ['allowed_ports' => null];

DnsResolver::setBackend(new FakeDnsBackend([
    'pub.example.com|' . DNS_A         => [['ip' => '93.184.216.34']],
    'pub.example.com|' . DNS_AAAA      => [],
    // Hostname resolving to a private address.
    'internal.example.com|' . DNS_A    => [['ip' => '10.0.0.5']],
    'internal.example.com|' . DNS_AAAA => [],
    // One public, one private — the private address must fail the whole URL.
    'mixed.example.com|' . DNS_A       => [['ip' => '93.184.216.34'], ['ip' => '10.0.0.5']],
    'mixed.example.com|' . DNS_AAAA    => [],
    // Public A, but AAAA is the IPv6 loopback (rebinding across families).
    'sneaky6.example.com|' . DNS_A     => [['ip' => '93.184.216.34']],
    'sneaky6.example.com|' . DNS_AAAA  => [['ipv6' => '::1']],
    // No records at all.
    'norecords.example.com|' . DNS_A    => [],
    'norecords.example.com|' . DNS_AAAA => [],
    // Resolver failure.
    'broken.example.com|' . DNS_A       => false,
    // Redirect-target host that resolves private (the redirect-hop case).
    'redirect-internal.example.com|' . DNS_A    => [['ip' => '192.168.1.10']],
    'redirect-internal.example.com|' . DNS_AAAA => [],
]));

// ── Return/pin contract ──────────────────────────────────────────────────
section('Return contract and connection-pin data');
$pin = UrlSafetyValidator::checkAndResolve('https://pub.example.com/path');
ok('public host: host returned lowercased', $pin['host'] === 'pub.example.com');
ok('public host: default https port', $pin['port'] === 443);
ok('public host: resolved IPs returned for pinning', $pin['ips'] === ['93.184.216.34']);
$pin_http = UrlSafetyValidator::checkAndResolve('http://pub.example.com/');
ok('default http port', $pin_http['port'] === 80);
$lit = UrlSafetyValidator::checkAndResolve('https://93.184.216.34/');
ok('public IPv4 literal: allowed, ips empty (nothing to pin)', $lit['ips'] === []);

// ── Port policy ──────────────────────────────────────────────────────────
section('Port policy: default 80/443, any-port opt-in');
ok('default policy rejects :8080',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://pub.example.com:8080/')));
$any = UrlSafetyValidator::checkAndResolve('http://pub.example.com:8080/page', $ANY_PORT);
ok('any-port mode permits :8080 and preserves the port', $any['port'] === 8080);
// any-port relaxes ONLY the port — every other defense still holds.
ok('any-port mode still rejects a private target',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://127.0.0.1:8080/admin', $ANY_PORT)));

// ── Scheme gate ──────────────────────────────────────────────────────────
section('Scheme allowlist');
ok('ftp scheme rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('ftp://pub.example.com/')));
ok('file scheme rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('file:///etc/passwd')));
ok('gopher scheme rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('gopher://pub.example.com/')));
ok('scheme-less URL rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('pub.example.com/page')));

// ── Blocked hostname literals (rejected before DNS) ──────────────────────
section('Blocked hostname literals');
ok('localhost rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://localhost/')));
ok('localhost.localdomain rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://localhost.localdomain/')));

// ── IPv4 literal ranges — the single authoritative table ─────────────────
section('IPv4 literal range rejections');
ok('127.0.0.1 loopback rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://127.0.0.1/')));
ok('127/8 (non-.1) rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://127.6.6.6/')));
ok('10/8 rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://10.1.2.3/')));
ok('172.16/12 rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://172.20.0.1/')));
ok('192.168/16 rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://192.168.1.1/')));
ok('169.254.169.254 cloud metadata rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://169.254.169.254/')));
ok('0.0.0.0 rejected (routes to loopback on Linux)', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://0.0.0.0/')));
ok('100.64/10 CGNAT rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://100.64.0.1/')));
ok('224/4 multicast rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://224.0.0.1/')));
ok('240/4 reserved rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://240.0.0.1/')));
// Boundary: the address just below CGNAT is public and must still be allowed.
ok('100.63.255.255 (just below CGNAT) accepted', is_array(UrlSafetyValidator::checkAndResolve('http://100.63.255.255/')));

// ── IPv6 literal ranges ──────────────────────────────────────────────────
section('IPv6 literal range rejections');
ok('[::1] loopback rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://[::1]/')));
ok('[fe80::1] link-local rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://[fe80::1]/')));
ok('[fd00::1] unique-local rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://[fd00::1]/')));
ok('[::ffff:10.0.0.5] v4-mapped private rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://[::ffff:10.0.0.5]/')));
ok('[::ffff:169.254.169.254] v4-mapped metadata rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://[::ffff:169.254.169.254]/')));
ok('[2001:4860:4860::8888] public IPv6 accepted', is_array(UrlSafetyValidator::checkAndResolve('http://[2001:4860:4860::8888]/')));

// ── Hostname resolution / DNS rebinding ──────────────────────────────────
section('Hostname resolution and rebinding');
ok('hostname → 10/8 rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('https://internal.example.com/')));
ok('hostname → mixed public+private rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('https://mixed.example.com/')));
ok('hostname → AAAA loopback rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('https://sneaky6.example.com/')));

// ── Fail-closed ──────────────────────────────────────────────────────────
section('Fail-closed on resolution problems');
ok('no DNS records rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('https://norecords.example.com/')));
ok('resolver failure rejected', throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('https://broken.example.com/')));

// ── Encoded-IP and userinfo obfuscation ──────────────────────────────────
section('Encoded-IP and userinfo obfuscation');
// userinfo with a private host: the host IS the loopback literal → rejected.
ok('userinfo hiding a loopback host rejected',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://expected@127.0.0.1/')));
// A loopback string in the userinfo but a public host is genuinely a public
// fetch — the real target is pub.example.com, so it is allowed.
ok('loopback-looking userinfo with a public host is allowed',
    is_array(UrlSafetyValidator::checkAndResolve('http://127.0.0.1@pub.example.com/')));
// Decimal / octal / hex forms of 127.0.0.1 are not valid IP literals to
// parse_url, so they are treated as hostnames and fail closed (no A record).
ok('decimal-encoded IP host fails closed',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://2130706433/')));
ok('octal-encoded IP host fails closed',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://0177.0.0.1/')));
ok('hex-encoded IP host fails closed',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://0x7f.0.0.1/')));

// ── checkIp() and check() surfaces ───────────────────────────────────────
section('checkIp and check wrappers');
ok('checkIp rejects a private IP',
    throws_unsafe(fn() => UrlSafetyValidator::checkIp('10.0.0.5')));
$ip_ok = true;
try { UrlSafetyValidator::checkIp('93.184.216.34'); } catch (UnsafeUrlException $e) { $ip_ok = false; }
ok('checkIp passes a public IP silently', $ip_ok);
$chk_ok = true;
try { UrlSafetyValidator::check('https://pub.example.com/'); } catch (UnsafeUrlException $e) { $chk_ok = false; }
ok('check() passes a safe URL silently', $chk_ok);
ok('check() honors the default port policy',
    throws_unsafe(fn() => UrlSafetyValidator::check('http://pub.example.com:8080/')));

// ── Redirect-hop re-validation ───────────────────────────────────────────
section('Redirect-hop re-validation');
ok('redirect: first hop (public) accepted',
    is_array(UrlSafetyValidator::checkAndResolve('https://pub.example.com/start')));
ok('redirect: hop to a private-resolving host rejected',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('https://redirect-internal.example.com/landing')));
ok('redirect: hop to an IP-literal loopback rejected',
    throws_unsafe(fn() => UrlSafetyValidator::checkAndResolve('http://127.0.0.1/admin')));

DnsResolver::clearBackend();

harness_finish();
