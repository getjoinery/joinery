<?php
/**
 * IpAddress - IP addresses compared as addresses, IPv4 and IPv6 alike.
 *
 * Every check that records, compares, allowlists or looks for an IP address
 * handles both families (project rule, 2026-09-29): a dual-stack machine
 * reaches a dual-stack server over IPv6, so the address a request arrives from
 * is often not the IPv4 on record. And an IPv6 address has many spellings —
 * case, where a run of zeros is folded, a /128 on the end, an IPv4 address
 * written IPv4-mapped (::ffff:a.b.c.d) — so text comparison misses matches.
 *
 * @version 1.0
 */

class IpAddress {

	/**
	 * The address in its canonical binary form (4 bytes for IPv4, 16 for IPv6,
	 * an IPv4-mapped IPv6 folded to its IPv4), or null for anything that is not
	 * an address. A trailing /prefix is ignored.
	 */
	public static function binary(string $ip): ?string {
		$ip = trim(explode('/', trim($ip), 2)[0]);
		$ip = trim($ip, '[]');
		if ($ip === '') {
			return null;
		}
		$bin = @inet_pton($ip);
		if ($bin === false || $bin === null) {
			return null;
		}
		if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			$bin = substr($bin, 12);
		}
		return $bin;
	}

	/** Are these the same address, in whatever spelling? False if either is not an address. */
	public static function same(string $a, string $b): bool {
		$x = self::binary($a);
		$y = self::binary($b);
		return $x !== null && $y !== null && hash_equals($x, $y);
	}

	/** Is $ip among $candidates (as addresses)? */
	public static function in(string $ip, array $candidates): bool {
		foreach ($candidates as $candidate) {
			if (self::same($ip, (string)$candidate)) {
				return true;
			}
		}
		return false;
	}

	/** Is this a public address — not private, loopback, link-local, unique-local or otherwise reserved? */
	public static function isPublic(string $ip): bool {
		$ip = trim(explode('/', trim($ip), 2)[0], " []");
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	/**
	 * Every IPv6-shaped token in a line of text (a header, a log line), so a
	 * caller can compare each as an address. Tokens that are not valid
	 * addresses are left out.
	 *
	 * @return string[]
	 */
	public static function ipv6Tokens(string $text): array {
		$out = array();
		if (preg_match_all('/(?<![0-9A-Fa-f:.])(?:[0-9A-Fa-f]{0,4}:){2,7}(?:[0-9A-Fa-f]{0,4}|\d{1,3}(?:\.\d{1,3}){3})(?![0-9A-Fa-f:])/', $text, $m)) {
			foreach ($m[0] as $token) {
				if (self::binary($token) !== null) {
					$out[] = $token;
				}
			}
		}
		return $out;
	}

	/**
	 * This machine's own public IPv6 address, as the kernel would pick it to
	 * reach the internet, or '' when it has none. No packet is sent: a UDP
	 * "connect" only chooses the route and the source address.
	 */
	public static function detectPublicIpv6(): string {
		$sock = @stream_socket_client('udp://[2001:4860:4860::8888]:53', $errno, $errstr, 1);
		if (!$sock) {
			return '';
		}
		$name = (string)@stream_socket_get_name($sock, false);
		@fclose($sock);
		// "[addr]:port" or "addr:port"
		if (preg_match('/^\[([^\]]+)\]:\d+$/', $name, $m)) {
			$ip = $m[1];
		} else {
			$ip = (strrpos($name, ':') !== false) ? substr($name, 0, strrpos($name, ':')) : '';
		}
		return (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && self::isPublic($ip)) ? $ip : '';
	}
}
?>
