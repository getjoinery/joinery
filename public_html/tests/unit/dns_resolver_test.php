<?php
/** @joinery-test
 * name: dns_resolver
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Unit test for DnsResolver.
 *
 * Runs with no real network: a fake backend is installed via
 * DnsResolver::setBackend(). Demonstrates the test seam the consolidation
 * exists to provide. Run:  php tests/unit/dns_resolver_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(__DIR__ . '/../lib/dns_fixtures.php'); // FakeDnsBackend

// ── Fixture ──────────────────────────────────────────────────────────────
DnsResolver::setBackend(new FakeDnsBackend([
    'example.com|' . DNS_MX => [
        ['target' => 'mx2.example.com', 'pri' => 20],
        ['target' => 'mx1.example.com', 'pri' => 10],
    ],
    'example.com|' . DNS_TXT => [
        ['txt' => 'v=spf1 include:_spf.example.com -all'],
        ['txt' => 'unrelated=value'],
    ],
    'example.com|' . DNS_A    => [['ip' => '93.184.216.34']],
    'example.com|' . DNS_AAAA => [['ipv6' => '2606:2800:220:1:248:1893:25c8:1946']],
    'host.example.com|' . DNS_A    => [['ip' => '1.2.3.4'], ['ip' => '1.2.3.4'], ['ip' => '5.6.7.8']],
    'host.example.com|' . DNS_AAAA => [['ipv6' => '::1']],
    'alias.example.com|' . DNS_CNAME => [['target' => 'real.example.com']],
    // A-only domain (no MX): RFC 5321 implicit MX fallback.
    'aonly.example.com|' . DNS_A => [['ip' => '10.0.0.1']],
    // Simulated resolver failure.
    'broken.example.com|' . DNS_MX => false,
    'broken.example.com|' . DNS_A  => false,
]));

// ── getMx: normalized shape + priority sort ──────────────────────────────
$mx = DnsResolver::getMx('example.com');
ok('getMx returns 2 records', count($mx) === 2);
ok('getMx sorts by priority', $mx[0]['host'] === 'mx1.example.com' && $mx[0]['pri'] === 10);
ok('getMx normalizes target to host key', isset($mx[1]['host']) && $mx[1]['host'] === 'mx2.example.com');

// ── getTxt ───────────────────────────────────────────────────────────────
$txt = DnsResolver::getTxt('example.com');
ok('getTxt returns plain strings', $txt === ['v=spf1 include:_spf.example.com -all', 'unrelated=value']);

// ── getA / getAaaa ───────────────────────────────────────────────────────
ok('getA returns ip strings', DnsResolver::getA('example.com') === ['93.184.216.34']);
ok('getAaaa returns ipv6 strings', DnsResolver::getAaaa('example.com') === ['2606:2800:220:1:248:1893:25c8:1946']);

// ── resolveHostIps: merge A + AAAA, de-duplicate ─────────────────────────
$ips = DnsResolver::resolveHostIps('host.example.com');
ok('resolveHostIps merges A + AAAA and de-dupes', $ips === ['1.2.3.4', '5.6.7.8', '::1']);

// ── getCname ─────────────────────────────────────────────────────────────
ok('getCname returns target', DnsResolver::getCname('alias.example.com') === 'real.example.com');
ok('getCname returns null when absent', DnsResolver::getCname('example.com') === null);

// ── error vs empty ───────────────────────────────────────────────────────
ok('no record returns empty array', DnsResolver::getMx('nosuchdomain.example.com') === []);
$threw = false;
try { DnsResolver::getMx('broken.example.com'); }
catch (DnsLookupException $e) { $threw = true; }
ok('resolver failure throws DnsLookupException', $threw);

// ── domainAcceptsMail: fail-open semantics ───────────────────────────────
ok('domainAcceptsMail true with MX', DnsResolver::domainAcceptsMail('example.com') === true);
ok('domainAcceptsMail true with A fallback (no MX)', DnsResolver::domainAcceptsMail('aonly.example.com') === true);
ok('domainAcceptsMail false when no MX and no A', DnsResolver::domainAcceptsMail('nosuchdomain.example.com') === false);
ok('domainAcceptsMail fails open on resolver failure', DnsResolver::domainAcceptsMail('broken.example.com') === true);

DnsResolver::clearBackend();

harness_finish();
