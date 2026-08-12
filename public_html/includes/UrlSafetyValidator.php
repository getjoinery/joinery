<?php
/**
 * SSRF guard for any server-side URL fetch — the single place the platform
 * decides whether a URL is safe to reach out to.
 *
 * Two subsystems fetch URLs on the server's behalf and must both pass every
 * candidate URL (and every redirect hop) through this guard before any network
 * is touched: the joinery_ai fetch_url tool and the dns_filtering scan_url
 * action. Keeping one validator — and, critically, one IP-range table — is what
 * stops the two from drifting: an earlier split let 0.0.0.0/8 (which routes to
 * loopback on Linux) through one guard while the other blocked it.
 *
 * Defenses (in order):
 *   1. Scheme allowlist (http/https only)
 *   2. Port policy (default: 80, 443 only; callers may opt into any port)
 *   3. Hostname-literal rejection (localhost, etc.)
 *   4. DNS resolution to ALL A/AAAA records, with each address checked against
 *      private/loopback/link-local/reserved ranges. Catches DNS rebinding where
 *      one record is public and another points inward (e.g. cloud metadata
 *      169.254.169.254).
 *
 * On success checkAndResolve() returns the resolved IPs so the caller can pin
 * the connection to those exact addresses (CURLOPT_RESOLVE) instead of letting
 * the HTTP client re-resolve the hostname — that pinning is what actually closes
 * the resolve->fetch DNS-rebinding window. Redirect handling is the caller's
 * responsibility: walk redirects manually and re-run this guard on every hop.
 *
 * Most callers should NOT wire this up by hand: SafeHttpClient wraps this
 * validator, does the pinning and the redirect walk, and is the default safe
 * outbound path (specs/safe_http_client.md). Reach for SafeHttpClient first;
 * touch this validator directly only in the two canonical setups that predate it
 * (FetchUrlTool and scan_url_logic).
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class UnsafeUrlException extends Exception {}

class UrlSafetyValidator {

    /** @var string[] */
    private static $allowed_schemes = ['http', 'https'];

    /** @var int[] default port allowlist; callers may override via opts. */
    private static $default_allowed_ports = [80, 443];

    /** @var string[] hostnames rejected outright before DNS lookup. */
    private static $blocked_hostnames = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
    ];

    /**
     * The single authoritative table of blocked IPv4 ranges. Every guard in the
     * platform consults this one list — this is the divergence that T13/T21
     * closed. IPv6 private/reserved ranges are covered by PHP's filter flags in
     * checkIp(); the explicit CIDR list here is the belt-and-suspenders layer for
     * IPv4 blocks PHP's FILTER_FLAG_NO_RES_RANGE does not reliably catch.
     *
     * @var string[]
     */
    private static $blocked_ipv4_cidrs = [
        '0.0.0.0/8',       // "this host" — routes to loopback on Linux
        '10.0.0.0/8',      // RFC1918 private
        '127.0.0.0/8',     // loopback
        '169.254.0.0/16',  // link-local (incl. cloud metadata 169.254.169.254)
        '172.16.0.0/12',   // RFC1918 private
        '192.168.0.0/16',  // RFC1918 private
        '100.64.0.0/10',   // CGNAT (RFC 6598)
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // reserved / future
    ];

    /**
     * Validate $url and, on success, return the data needed to pin the
     * connection to an already-validated IP.
     *
     * @param string $url
     * @param array  $opts  Recognized keys:
     *                       - allowed_ports: int[]|null — ports to permit. Defaults
     *                         to [80, 443]. Pass null to allow any port (the
     *                         scan_url page scanner legitimately hits dev ports).
     * @return array{host:string,port:int,ips:string[]}  `ips` is empty when the
     *         URL host is an IP literal — no DNS happens, nothing to pin.
     * @throws UnsafeUrlException if $url should not be fetched.
     */
    public static function checkAndResolve(string $url, array $opts = []): array {
        $allowed_ports = array_key_exists('allowed_ports', $opts)
            ? $opts['allowed_ports']
            : self::$default_allowed_ports;

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException('URL is malformed or missing scheme/host: ' . $url);
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::$allowed_schemes, true)) {
            throw new UnsafeUrlException("Scheme '$scheme' is not allowed (only http/https).");
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($allowed_ports !== null && !in_array($port, $allowed_ports, true)) {
            throw new UnsafeUrlException("Port $port is not allowed.");
        }

        $host = strtolower($parts['host']);
        if (in_array($host, self::$blocked_hostnames, true)) {
            throw new UnsafeUrlException("Hostname '$host' is blocked.");
        }

        // If the host is an IP literal, validate it directly. No DNS lookup
        // happens, so there is no rebinding window and nothing to pin.
        // parse_url wraps IPv6 hosts in brackets, e.g. "[::1]"; strip them.
        $host_for_ip = trim($host, '[]');
        if (filter_var($host_for_ip, FILTER_VALIDATE_IP)) {
            self::checkIp($host_for_ip);
            return ['host' => $host, 'port' => $port, 'ips' => []];
        }

        // Hostname: resolve once, validate every A/AAAA address, and return
        // that exact set for the caller to pin the connection to.
        $ips = self::resolveAll($host);
        if (empty($ips)) {
            throw new UnsafeUrlException("Hostname '$host' does not resolve.");
        }
        foreach ($ips as $ip) {
            self::checkIp($ip);
        }
        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /**
     * Throws UnsafeUrlException if $url should not be fetched; returns
     * silently otherwise.
     *
     * Thin wrapper over checkAndResolve() for callers that do not pin the
     * connection. Without pinning, DNS rebinding between this check and the
     * actual fetch is NOT closed — prefer checkAndResolve() fed into
     * CURLOPT_RESOLVE.
     */
    public static function check(string $url, array $opts = []): void {
        self::checkAndResolve($url, $opts);
    }

    /**
     * Throws if $ip is in a blocked range. Public so callers can re-validate
     * redirect targets that arrive as resolved IPs.
     */
    public static function checkIp(string $ip): void {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new UnsafeUrlException("Not a valid IP: $ip");
        }

        // PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE rejects
        // RFC1918 and reserved blocks for both v4 and v6 in one call — this is
        // what covers IPv6 loopback/link-local/unique-local/v4-mapped.
        if (!filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new UnsafeUrlException("IP '$ip' is in a private, loopback, or reserved range.");
        }

        // Belt-and-suspenders: explicit CIDR checks for IPv4 ranges PHP's filter
        // misses (notably 0.0.0.0/8, 100.64/10 CGNAT). The single authoritative
        // range table lives in $blocked_ipv4_cidrs.
        if (strpos($ip, ':') === false) {
            foreach (self::$blocked_ipv4_cidrs as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    throw new UnsafeUrlException("IP '$ip' falls in blocked range $cidr.");
                }
            }
        }
    }

    /**
     * Resolve hostname to all IPv4 + IPv6 addresses. A resolver failure means
     * the host's addresses cannot be enumerated — fail closed and refuse.
     */
    private static function resolveAll(string $host): array {
        try {
            return DnsResolver::resolveHostIps($host);
        } catch (DnsLookupException $e) {
            throw new UnsafeUrlException("Could not resolve '$host' — refusing to fetch.");
        }
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
