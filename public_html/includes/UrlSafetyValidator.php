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
     * closed. PHP's filter flags in checkIp() cover the private and reserved
     * ranges they know; these tables are what they do not reliably catch. The
     * addresses each guard must refuse, this one and the Go guards in the
     * sandboxed plugin host and the relay alike, are listed once in
     * tests/fixtures/outbound_address_cases.json, which all three tests read.
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
        '192.0.0.0/24',    // IETF protocol assignments
        '192.0.2.0/24',    // documentation (TEST-NET-1)
        '198.18.0.0/15',   // benchmarking, sometimes an internal network
        '198.51.100.0/24', // documentation (TEST-NET-2)
        '203.0.113.0/24',  // documentation (TEST-NET-3)
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // reserved / future
    ];

    /**
     * Blocked IPv6 ranges PHP's filter flags let through. Loopback, link-local
     * and unique-local addresses are refused by the flags; an IPv6 address
     * that carries an IPv4 one is judged by its bytes first (ipv6Embedded()).
     *
     * @var string[]
     */
    private static $blocked_ipv6_cidrs = [
        'ff00::/8',        // multicast
        '100::/64',        // discard-only
        '2001::/32',       // Teredo: the client's address is hidden in it
        '2001:2::/48',     // benchmarking
        '2001:db8::/32',   // documentation
        'fec0::/10',       // site-local (deprecated)
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

        // An IPv6 address is judged by its bytes, never by how it is written
        // (::127.0.0.1 and ::7f00:1 are one address). One that carries an IPv4
        // address is judged as that address, or refused where it hides it.
        if (strpos($ip, ':') !== false) {
            $embedded = self::ipv6Embedded((string)inet_pton($ip));
            if ($embedded === false) {
                throw new UnsafeUrlException("IP '$ip' carries an IPv4 address in a form that is never reached.");
            }
            if ($embedded !== null) {
                try {
                    self::checkIp($embedded);
                } catch (UnsafeUrlException $e) {
                    throw new UnsafeUrlException("IP '$ip' carries $embedded: " . $e->getMessage());
                }
                return;
            }
        }

        // PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE rejects
        // RFC1918 and reserved blocks for both v4 and v6 in one call — this is
        // what covers IPv6 loopback/link-local/unique-local (an address that
        // carries an IPv4 one was judged above, by ipv6Embedded()).
        if (!filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new UnsafeUrlException("IP '$ip' is in a private, loopback, or reserved range.");
        }

        // Belt-and-suspenders: explicit CIDR checks for ranges PHP's filter
        // misses (notably 0.0.0.0/8, 100.64/10 CGNAT, NAT64). The single
        // authoritative range tables are $blocked_ipv4_cidrs and $blocked_ipv6_cidrs.
        if (strpos($ip, ':') === false) {
            foreach (self::$blocked_ipv4_cidrs as $cidr) {
                if (IpAddress::inPrefix($ip, $cidr)) {
                    throw new UnsafeUrlException("IP '$ip' falls in blocked range $cidr.");
                }
            }
        } else {
            foreach (self::$blocked_ipv6_cidrs as $cidr) {
                if (IpAddress::inPrefix($ip, $cidr)) {
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

    /**
     * The IPv4 address a 16-byte IPv6 address carries, when it is one that
     * reaches that address (IPv4-mapped ::ffff:0:0/96, NAT64 64:ff9b::/96,
     * 6to4 2002::/16); false for one refused outright (IPv4-compatible ::/96,
     * IPv4-translated ::ffff:0:0:0/96, the rest of 64:ff9b::/32, the local-use
     * 64:ff9b:1::/48 among it); null for an ordinary IPv6 address. The Go
     * guards in the sandboxed plugin host and the relay judge the same way.
     *
     * @return string|false|null
     */
    private static function ipv6Embedded(string $bin) {
        if (strlen($bin) !== 16) {
            return false;
        }
        $v4 = function (int $at) use ($bin) {
            return implode('.', array_map('ord', str_split(substr($bin, $at, 4))));
        };
        $zero = function (int $from, int $length) use ($bin) {
            return substr($bin, $from, $length) === str_repeat("\0", $length);
        };
        if ($zero(0, 12)) {
            return false;
        }
        if ($zero(0, 10) && substr($bin, 10, 2) === "\xff\xff") {
            return $v4(12);
        }
        if ($zero(0, 8) && substr($bin, 8, 4) === "\xff\xff\0\0") {
            return false;
        }
        if (substr($bin, 0, 4) === "\x00\x64\xff\x9b") {
            return $zero(4, 8) ? $v4(12) : false;
        }
        if (substr($bin, 0, 2) === "\x20\x02") {
            return $v4(2);
        }
        return null;
    }

}
