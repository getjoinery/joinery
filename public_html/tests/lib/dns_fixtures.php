<?php
/**
 * Shared DNS test double for offline SSRF / DNS-auth / resolver tests.
 *
 * DnsResolver is the one raw-DNS chokepoint (includes/DnsResolver.php) and it
 * accepts an injected backend via DnsResolver::setBackend(). This fake is that
 * backend: it answers getRecords($name, $type) from a fixed map so a test can
 * assert resolver behavior without touching the network.
 *
 * Usage:
 *   require_once(__DIR__ . '/../lib/dns_fixtures.php');   // path from tests/<area>/
 *   DnsResolver::setBackend(new FakeDnsBackend([
 *       'pub.example.com|' . DNS_A    => [['ip' => '93.184.216.34']],
 *       'pub.example.com|' . DNS_AAAA => [],
 *       'broken.example.com|' . DNS_A => false,   // false => a resolver FAILURE
 *   ]));
 *   // ... exercise code under test ...
 *   DnsResolver::clearBackend();
 *
 * Record-value conventions match dns_get_record()'s shape and DnsResolver's
 * error/empty contract:
 *   - an array of records is returned as-is (A rows use 'ip', AAAA rows 'ipv6')
 *   - [] means "no such record" (empty, not an error)
 *   - false means the lookup itself FAILED -> DnsResolver throws DnsLookupException
 *   - a key that is absent entirely is treated as [] (no such record)
 */

if (!class_exists('FakeDnsBackend', false)) {
    class FakeDnsBackend {
        /** @var array map of "name|type" => records array, or false for a lookup failure */
        private $data;
        public function __construct(array $data) { $this->data = $data; }
        public function getRecords($name, $type) {
            $key = $name . '|' . $type;
            return array_key_exists($key, $this->data) ? $this->data[$key] : [];
        }
    }
}
