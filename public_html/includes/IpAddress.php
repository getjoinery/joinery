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
 * One definition of "the same network" lives here too: network() is the unit
 * a limit counts (an IPv4 address, an IPv6 /64), inPrefix() is the one
 * prefix match, and matchesEntry() reads an allowlist entry, so every check
 * that asks those questions gets the same answer.
 *
 * @version 1.1
 * @changelog 1.1 - family(), network(), inPrefix(), sharePrefix(),
 *   matchesEntry(), entryProblem().
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

	/** 4 for an IPv4 address (an IPv4-mapped IPv6 included), 6 for IPv6, 0 for anything else. */
	public static function family(string $ip): int {
		$bin = self::binary($ip);
		return $bin === null ? 0 : (strlen($bin) === 4 ? 4 : 6);
	}

	/**
	 * The unit a limit counts: what one subscriber holds. An IPv4 address is
	 * its own unit; an IPv6 address's unit is its /64, because one subscriber
	 * holds a whole /64 and can move through it at will. Canonical text
	 * ("203.0.113.7", "2001:db8:1:2::/64"); "unknown" for anything that is
	 * not an address.
	 */
	public static function network(string $ip): string {
		$bin = self::binary($ip);
		if ($bin === null) {
			return 'unknown';
		}
		if (strlen($bin) === 4) {
			return inet_ntop($bin);
		}
		return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
	}

	/**
	 * Is $ip inside $cidr ("198.51.100.0/24", "2001:db8::/52")? Bit-exact for
	 * any prefix length, either family, any spelling. A prefix written
	 * IPv4-mapped (::ffff:198.51.100.0/120) is read as the IPv4 prefix it is.
	 * A bare address is an exact match. An address of the other family, or a
	 * malformed prefix, is not a match.
	 */
	public static function inPrefix(string $ip, string $cidr): bool {
		$bin = self::binary($ip);
		$prefix = self::parsePrefix($cidr);
		if ($bin === null || $prefix === null || strlen($bin) !== strlen($prefix[0])) {
			return false;
		}
		return self::leadingBitsEqual($bin, $prefix[0], $prefix[1]);
	}

	/**
	 * Do $a and $b share their first bits: $v4_bits for two IPv4 addresses,
	 * $v6_bits for two IPv6? Null when they are of different families or
	 * either is not an address: nothing relates a machine's IPv4 address to
	 * its IPv6 one, so the question has no answer.
	 */
	public static function sharePrefix(string $a, string $b, int $v4_bits, int $v6_bits): ?bool {
		$x = self::binary($a);
		$y = self::binary($b);
		if ($x === null || $y === null || strlen($x) !== strlen($y)) {
			return null;
		}
		return self::leadingBitsEqual($x, $y, strlen($x) === 4 ? $v4_bits : $v6_bits);
	}

	/**
	 * Does $ip match one allowlist entry? An entry is an address in any
	 * spelling, a prefix of either family ("2001:db8:abcd::/48"), or the
	 * IPv4 wildcard form ("104.23.*", "104.23.253.*", "104.2*"). An entry of
	 * the other family is not a match, with one exception: an entry of stars
	 * alone ("*", "*.*.*.*") admits every address of either family, as it
	 * always has.
	 */
	public static function matchesEntry(string $ip, string $entry): bool {
		$entry = trim($entry);
		if ($entry === '' || self::binary($ip) === null) {
			return false;
		}
		if (strpos($entry, '*') !== false) {
			if (!self::isWildcard($entry)) {
				return false;
			}
			if (trim($entry, '*.') === '') {
				return true;
			}
			if (self::family($ip) !== 4) {
				return false;
			}
			$dotted = inet_ntop(self::binary($ip));
			$pattern = '/^' . str_replace(array('.', '*'), array('\.', '[0-9.]*'), $entry) . '$/';
			return preg_match($pattern, $dotted) === 1;
		}
		if (strpos($entry, '/') !== false) {
			return self::inPrefix($ip, $entry);
		}
		return self::same($ip, $entry);
	}

	/**
	 * Why an allowlist entry is not one matchesEntry() can use, in words for
	 * the person who typed it, or null when it is fine. Stricter than
	 * matching: brackets, a zone id and a prefix length out of range are
	 * refused here, so a typo is caught at save instead of being a silent
	 * lockout at the next sign-in.
	 */
	public static function entryProblem(string $entry): ?string {
		$entry = trim($entry);
		if ($entry === '') {
			return 'an empty entry';
		}
		if (strpbrk($entry, '[]') !== false) {
			return "\"$entry\": write the address without brackets";
		}
		if (strpos($entry, '%') !== false) {
			return "\"$entry\": a zone id (the part after %) cannot be matched; leave it off";
		}
		if (strpos($entry, '*') !== false) {
			return self::isWildcard($entry) ? null
				: "\"$entry\": a wildcard is written like 104.23.* (IPv4 only)";
		}
		if (strpos($entry, '/') !== false) {
			list($address, $length) = explode('/', $entry, 2);
			$bin = @inet_pton($address);
			if ($bin === false) {
				return "\"$entry\": \"$address\" is not an IP address";
			}
			if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
				return "\"$entry\": write an IPv4 prefix in IPv4 form, like 192.0.2.0/24";
			}
			$max = strlen($bin) === 4 ? 32 : 128;
			if (!preg_match('/^\d{1,3}$/', $length) || (int)$length > $max) {
				return "\"$entry\": the prefix length must be a number from 0 to $max";
			}
			return null;
		}
		return @inet_pton($entry) === false ? "\"$entry\" is not an IP address" : null;
	}

	/** [binary network, prefix length] for "address/length" or a bare address; null when malformed. */
	private static function parsePrefix(string $cidr): ?array {
		$cidr = trim($cidr);
		$parts = explode('/', $cidr, 2);
		$raw = @inet_pton(trim($parts[0], " []"));
		if ($raw === false) {
			return null;
		}
		$max = strlen($raw) * 8;
		if (count($parts) === 1) {
			$bits = $max;
		} elseif (preg_match('/^\d{1,3}$/', $parts[1]) && (int)$parts[1] <= $max) {
			$bits = (int)$parts[1];
		} else {
			return null;
		}
		// An IPv4-mapped prefix (::ffff:0:0/96 and longer) is the IPv4 prefix it covers.
		if (strlen($raw) === 16 && $bits >= 96 && substr($raw, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			return array(substr($raw, 12), $bits - 96);
		}
		return array($raw, $bits);
	}

	/** Are the first $bits bits of two binary addresses of the same length equal? */
	private static function leadingBitsEqual(string $a, string $b, int $bits): bool {
		$bits = max(0, min($bits, strlen($a) * 8));
		$bytes = intdiv($bits, 8);
		if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
			return false;
		}
		$rest = $bits % 8;
		if ($rest === 0) {
			return true;
		}
		$mask = (0xFF << (8 - $rest)) & 0xFF;
		return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
	}

	/**
	 * An IPv4 wildcard entry: up to four dot-separated parts, each a number up
	 * to 255, "*", or digits followed by "*" ("2*" stands for 2, 20-29 and
	 * 200-255, as the wildcard form always has).
	 */
	private static function isWildcard(string $entry): bool {
		$parts = explode('.', $entry);
		if (count($parts) > 4) {
			return false;
		}
		foreach ($parts as $part) {
			if (preg_match('/^\d{0,2}\*$/', $part)) {
				continue;
			}
			if (!(preg_match('/^\d{1,3}$/', $part) && (int)$part <= 255)) {
				return false;
			}
		}
		return true;
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
