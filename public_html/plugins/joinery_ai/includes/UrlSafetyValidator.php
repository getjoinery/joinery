<?php
/**
 * SSRF guard for URLs the LLM asks the runner to fetch.
 *
 * Every fetch_url-style tool MUST run a candidate URL through
 * UrlSafetyValidator::check() before any network is touched. The
 * validator throws UnsafeUrlException on rejection — callers should
 * let the exception surface as a tool error so the LLM sees it.
 *
 * Defenses (in order):
 *   1. Scheme allowlist (http/https only)
 *   2. Port allowlist (80, 443 only)
 *   3. Hostname-literal rejection (localhost, etc.)
 *   4. DNS resolution to ALL A/AAAA records, with each address
 *      checked against private/loopback/link-local/reserved ranges.
 *      Catches DNS rebinding attacks where one record is public and
 *      the other points inward (e.g. cloud metadata 169.254.169.254).
 *
 * Redirect handling is the *caller's* responsibility — Guzzle should
 * be configured to re-validate every redirect target through this
 * checker. See WebSearchTool / FetchUrlTool for the canonical setup.
 */

class UnsafeUrlException extends Exception {}

class UrlSafetyValidator {

    /** @var string[] */
    private static $allowed_schemes = ['http', 'https'];

    /** @var int[] */
    private static $allowed_ports = [80, 443];

    /** @var string[] hostnames rejected outright before DNS lookup. */
    private static $blocked_hostnames = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
    ];

    /**
     * Throws UnsafeUrlException if $url should not be fetched.
     * Otherwise returns silently.
     */
    public static function check(string $url): void {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException('URL is malformed or missing scheme/host: ' . $url);
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::$allowed_schemes, true)) {
            throw new UnsafeUrlException("Scheme '$scheme' is not allowed (only http/https).");
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::$allowed_ports, true)) {
            throw new UnsafeUrlException("Port $port is not allowed (only 80, 443).");
        }

        $host = strtolower($parts['host']);
        if (in_array($host, self::$blocked_hostnames, true)) {
            throw new UnsafeUrlException("Hostname '$host' is blocked.");
        }

        // If the host is an IP literal, validate it directly. Otherwise
        // resolve all A and AAAA records and validate each. parse_url
        // wraps IPv6 hosts in brackets, e.g. "[::1]"; strip them before
        // the IP-literal check.
        $host_for_ip = trim($host, '[]');
        if (filter_var($host_for_ip, FILTER_VALIDATE_IP)) {
            self::checkIp($host_for_ip);
            return;
        }

        $ips = self::resolveAll($host);
        if (empty($ips)) {
            throw new UnsafeUrlException("Hostname '$host' does not resolve.");
        }
        foreach ($ips as $ip) {
            self::checkIp($ip);
        }
    }

    /**
     * Throws if $ip is in a blocked range. Public method so callers can
     * re-validate redirect targets that arrive as resolved IPs.
     */
    public static function checkIp(string $ip): void {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new UnsafeUrlException("Not a valid IP: $ip");
        }

        // PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE rejects
        // RFC1918 and reserved blocks for both v4 and v6 in one call.
        if (!filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new UnsafeUrlException("IP '$ip' is in a private, loopback, or reserved range.");
        }

        // Belt-and-suspenders: explicit CIDR checks for IPv4 ranges PHP's
        // filter sometimes misses (notably 100.64/10 CGNAT, link-local
        // 169.254/16 — important for AWS/GCP/Azure metadata endpoints).
        if (strpos($ip, ':') === false) {
            $ranges = [
                '0.0.0.0/8',
                '10.0.0.0/8',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '100.64.0.0/10',
                '224.0.0.0/4',
                '240.0.0.0/4',
            ];
            foreach ($ranges as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    throw new UnsafeUrlException("IP '$ip' falls in blocked range $cidr.");
                }
            }
        }
    }

    /**
     * Resolve hostname to all IPv4 + IPv6 addresses.
     */
    private static function resolveAll(string $host): array {
        $ips = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $v6_records = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6_records)) {
            foreach ($v6_records as $rec) {
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private static function ipInCidr(string $ip, string $cidr): bool {
        list($subnet, $bits) = explode('/', $cidr);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if ($ip_long === false || $subnet_long === false) return false;
        $mask = $bits == 0 ? 0 : (-1 << (32 - (int)$bits));
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

}
