<?php
/**
 * DnsResolver - the single place the platform performs raw DNS lookups.
 *
 * Every DNS lookup in core and plugins should funnel through here. The class
 * is intentionally static and policy-free: it normalises result shapes and
 * distinguishes "no such record" (an empty array) from "the lookup failed"
 * (a thrown DnsLookupException). It takes NO stance on fail-open vs
 * fail-closed — each caller catches DnsLookupException and applies its own
 * policy. See specs/implemented/dns_functionality_consolidation.md.
 *
 * Testability: setBackend() swaps the raw-DNS layer for a test double. The
 * seam sits at the bottom of the stack, so one setBackend() call also makes
 * DnsAuthChecker and every other consumer testable. Production code never
 * touches it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/DnsLookupException.php'));

class DnsResolver {

    /**
     * Optional test double. When set, raw lookups go to
     * $backend->getRecords($name, $type) instead of dns_get_record(). The
     * double returns the same shape as dns_get_record: an array of record
     * arrays, or false to simulate a resolver failure.
     */
    private static $backend = null;

    /** Install a test double for the raw-DNS layer. Tests only. */
    public static function setBackend($backend) {
        self::$backend = $backend;
    }

    /** Remove any test double, restoring real DNS. Call in test teardown. */
    public static function clearBackend() {
        self::$backend = null;
    }

    /**
     * MX records for a domain, lowest priority number first.
     *
     * @param string $domain
     * @return array<int,array{host:string,pri:int}>
     * @throws DnsLookupException on resolver failure.
     */
    public static function getMx($domain) {
        $mx = [];
        foreach (self::rawLookup($domain, DNS_MX) as $r) {
            if (isset($r['target'])) {
                $mx[] = ['host' => $r['target'], 'pri' => (int)($r['pri'] ?? 0)];
            }
        }
        usort($mx, function ($a, $b) { return $a['pri'] <=> $b['pri']; });
        return $mx;
    }

    /**
     * IPv4 addresses for a host.
     *
     * @param string $host
     * @return string[]
     * @throws DnsLookupException on resolver failure.
     */
    public static function getA($host) {
        $ips = [];
        foreach (self::rawLookup($host, DNS_A) as $r) {
            if (!empty($r['ip'])) { $ips[] = $r['ip']; }
        }
        return $ips;
    }

    /**
     * IPv6 addresses for a host.
     *
     * @param string $host
     * @return string[]
     * @throws DnsLookupException on resolver failure.
     */
    public static function getAaaa($host) {
        $ips = [];
        foreach (self::rawLookup($host, DNS_AAAA) as $r) {
            if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
        }
        return $ips;
    }

    /**
     * TXT record strings for a name.
     *
     * @param string $name
     * @return string[]
     * @throws DnsLookupException on resolver failure.
     */
    public static function getTxt($name) {
        $txt = [];
        foreach (self::rawLookup($name, DNS_TXT) as $r) {
            if (isset($r['txt'])) { $txt[] = $r['txt']; }
        }
        return $txt;
    }

    /**
     * The CNAME target for a name, or null if there is none.
     *
     * @param string $name
     * @return string|null
     * @throws DnsLookupException on resolver failure.
     */
    public static function getCname($name) {
        foreach (self::rawLookup($name, DNS_CNAME) as $r) {
            if (!empty($r['target'])) { return $r['target']; }
        }
        return null;
    }

    /**
     * Every IP a host resolves to — all A and all AAAA records combined and
     * de-duplicated. The lookup primitive for SSRF guards: a caller resolves
     * once and classifies every address. Note this does NOT close DNS
     * rebinding — the eventual connection must still be pinned to a validated
     * IP. See the spec's §4.4 / §8.5.
     *
     * @param string $host
     * @return string[]
     * @throws DnsLookupException if either the A or the AAAA lookup fails.
     */
    public static function resolveHostIps($host) {
        $ips = array_merge(self::getA($host), self::getAaaa($host));
        return array_values(array_unique($ips));
    }

    /**
     * Whether a domain looks able to receive email: an MX record, or (per
     * RFC 5321) an A record as implicit fallback.
     *
     * Fail-open — a resolver failure returns true, so a transient DNS error
     * never rejects an otherwise valid address. Returns false only when the
     * lookups succeeded and the domain definitively has neither MX nor A.
     *
     * @param string $domain
     * @return bool
     */
    public static function domainAcceptsMail($domain) {
        try {
            if (!empty(self::getMx($domain))) {
                return true;
            }
            return !empty(self::getA($domain));
        } catch (DnsLookupException $e) {
            return true; // fail-open
        }
    }

    /**
     * The one raw-DNS chokepoint. Returns dns_get_record's array (possibly
     * empty, meaning "no such record"); throws DnsLookupException when the
     * lookup itself failed.
     *
     * @param string $name
     * @param int $type One of the DNS_* constants.
     * @return array
     * @throws DnsLookupException
     */
    private static function rawLookup($name, $type) {
        if (self::$backend !== null) {
            $result = self::$backend->getRecords($name, $type);
        } else {
            $result = @dns_get_record($name, $type);
        }
        if ($result === false) {
            throw new DnsLookupException('DNS lookup failed for ' . $name);
        }
        return is_array($result) ? $result : [];
    }
}
