<?php
/**
 * AuthoritativeDns - ask a zone's own nameservers, with no cache in between.
 *
 * The system resolver is a cache. Right after the platform publishes a record
 * it can keep answering the old value for the record's TTL, or "no such
 * record" for the zone's negative lifetime (1800 s at Cloudflare) when the name
 * was looked up before it existed — and every page that checks DNS looks a
 * record up before it is published. A check read through that cache says a
 * finished domain is broken, and nothing the operator does will change it.
 *
 * The zone's own nameservers are what every receiving server's resolver will
 * be told, and they change the moment a publish returns. DnsResolver asks
 * here first for names under a zone registered with
 * DnsResolver::preferAuthoritative(); anything this class cannot answer
 * cleanly comes back null, and the caller falls back to the system resolver.
 *
 * Answers cleanly means: an authoritative (AA) reply, not truncated, NOERROR or
 * NXDOMAIN, and no CNAME standing in for the asked type (a CNAME's target may
 * sit in another zone, which only a recursive resolver follows). A referral to
 * a delegated subzone carries no AA bit, so it falls back too.
 *
 * The wire format is built and read here; PublicResolvers does the same for
 * DNS-over-HTTPS but reads only A, AAAA and TXT.
 *
 * @version 1.1 - lookupAll(): every nameserver's own answer, for a caller that must know all of them serve a record
 * @version 1.0
 */

class AuthoritativeDns {

	/** Seconds to wait for one nameserver. Two are tried before falling back. */
	const TIMEOUT_SECONDS = 2;

	/** The EDNS0 payload size advertised, so a 2048-bit DKIM record is not truncated over UDP. */
	const EDNS_PAYLOAD = 1232;

	const TYPE_NUMBERS = array(
		DNS_A     => 1,
		DNS_NS    => 2,
		DNS_CNAME => 5,
		DNS_MX    => 15,
		DNS_TXT   => 16,
		DNS_AAAA  => 28,
		DNS_SRV   => 33,
	);

	/**
	 * Sends one query: fn(string $ip, string $packet): ?string, the raw reply or
	 * null. Null sends it over UDP. A variable only so a test can stand in for
	 * the nameservers; nothing in production sets it.
	 *
	 * @var callable|null
	 */
	public static $sender = null;

	/** zone => nameserver IPs ([] when none could be found: that zone falls back). */
	private static $zone_ips = array();

	/** Zones whose nameservers stopped answering in this request: not asked again. */
	private static $dead_zones = array();

	/**
	 * Records for $name of $type (a DNS_* constant), shaped as dns_get_record
	 * returns them, asked of $zone's own nameservers. Null when this cannot
	 * answer cleanly; the caller then asks the system resolver.
	 */
	public static function lookup(string $name, int $type, string $zone): ?array {
		$qtype = self::TYPE_NUMBERS[$type] ?? 0;
		$name = rtrim(strtolower(trim($name)), '.');
		$zone = rtrim(strtolower(trim($zone)), '.');
		if ($qtype === 0 || $name === '' || $zone === '' || isset(self::$dead_zones[$zone])) {
			return null;
		}
		$packet = self::query($name, $qtype);
		if ($packet === null) {
			return null;
		}
		$ips = self::nameserverIps($zone);
		if (empty($ips)) {
			return null;
		}
		foreach (array_slice($ips, 0, 2) as $ip) {
			$reply = self::$sender ? (self::$sender)($ip, $packet) : self::send($ip, $packet);
			if ($reply === null) {
				continue;
			}
			// A reply that answered but not cleanly (no AA, truncated, a CNAME)
			// would read the same from the other nameserver: fall back now.
			return self::records($reply, $name, $qtype);
		}
		self::$dead_zones[$zone] = true;
		error_log('AuthoritativeDns: no nameserver of ' . $zone . ' answered; using the system resolver');
		return null;
	}

	/**
	 * Every nameserver's answer for $name, as ip => records (dns_get_record's
	 * shape), or ip => null for one that did not answer cleanly. [] when the
	 * zone's nameservers cannot be found.
	 *
	 * For a caller that must know a record is served everywhere, not only by
	 * the first server to reply: nameservers that update one after another
	 * disagree for a while, and a receiver asking the slow one caches the
	 * miss. A missed reply here marks nothing dead, so a caller can poll; $skip
	 * names addresses the caller already knows are silent.
	 */
	public static function lookupAll(string $name, int $type, string $zone, array $skip = array()): array {
		$qtype = self::TYPE_NUMBERS[$type] ?? 0;
		$name = rtrim(strtolower(trim($name)), '.');
		$zone = rtrim(strtolower(trim($zone)), '.');
		if ($qtype === 0 || $name === '' || $zone === '') {
			return array();
		}
		$packet = self::query($name, $qtype);
		if ($packet === null) {
			return array();
		}
		$out = array();
		foreach (array_diff(self::nameserverIps($zone), $skip) as $ip) {
			$reply = self::$sender ? (self::$sender)($ip, $packet) : self::send($ip, $packet);
			$out[$ip] = $reply === null ? null : self::records($reply, $name, $qtype);
		}
		return $out;
	}

	/** Forget what this request learnt about nameservers. Tests only. */
	public static function reset(): void {
		self::$zone_ips = array();
		self::$dead_zones = array();
		self::$sender = null;
	}

	/** Pre-set a zone's nameserver addresses, skipping the NS lookup. Tests only. */
	public static function setNameservers(string $zone, array $ips): void {
		self::$zone_ips[rtrim(strtolower($zone), '.')] = $ips;
	}

	/**
	 * A query in wire format: random id, no recursion asked (the server is
	 * authoritative), one question, and an EDNS0 OPT record. Null for a name
	 * that cannot be one.
	 */
	public static function query(string $name, int $qtype, ?int $id = null): ?string {
		$name = rtrim(strtolower(trim($name)), '.');
		if ($name === '' || strlen($name) > 253) {
			return null;
		}
		$id = $id ?? random_int(0, 0xffff);
		$q = pack('nnnnnn', $id, 0x0000, 1, 0, 0, 1);
		foreach (explode('.', $name) as $label) {
			if ($label === '' || strlen($label) > 63) {
				return null;
			}
			$q .= chr(strlen($label)) . $label;
		}
		$q .= "\0" . pack('nn', $qtype, 1);
		// OPT pseudo-record: root name, type 41, class = payload size, no flags, no data.
		$q .= "\0" . pack('nnNn', 41, self::EDNS_PAYLOAD, 0, 0);
		return $q;
	}

	/**
	 * A reply read into dns_get_record's shape for $name and $qtype, or null when
	 * it is not a clean authoritative answer.
	 */
	public static function records(string $msg, string $name, int $qtype): ?array {
		if (strlen($msg) < 12) {
			return null;
		}
		$h = unpack('nid/nflags/nqd/nan/nns/nar', substr($msg, 0, 12));
		$flags = $h['flags'];
		if (!($flags & 0x8000) || ($flags & 0x0200) || !($flags & 0x0400)) {
			return null;   // not a response, truncated, or not authoritative
		}
		$rcode = $flags & 0x0f;
		if ($rcode === 3) {
			return array();   // NXDOMAIN, from the zone itself
		}
		if ($rcode !== 0) {
			return null;
		}
		$pos = 12;
		for ($i = 0; $i < $h['qd']; $i++) {
			$read = self::readName($msg, $pos);
			if ($read === null || $read[1] + 4 > strlen($msg)) {
				return null;
			}
			$pos = $read[1] + 4;
		}
		$out = array();
		$name = rtrim(strtolower($name), '.');
		for ($i = 0; $i < $h['an']; $i++) {
			$read = self::readName($msg, $pos);
			if ($read === null || $read[1] + 10 > strlen($msg)) {
				return null;
			}
			list($owner, $pos) = $read;
			$rr = unpack('ntype/nclass/Nttl/nlen', substr($msg, $pos, 10));
			$pos += 10;
			if ($pos + $rr['len'] > strlen($msg)) {
				return null;
			}
			$start = $pos;
			$pos += $rr['len'];
			if ($rr['type'] === 5 && $qtype !== 5) {
				return null;   // a CNAME stands in: only a recursive resolver follows it
			}
			if ($rr['type'] !== $qtype || strtolower($owner) !== $name) {
				continue;
			}
			$record = self::shape($msg, $start, $rr['len'], $rr['type'], $owner, $rr['ttl'] & 0x7fffffff);
			if ($record === null) {
				return null;
			}
			$out[] = $record;
		}
		return $out;
	}

	/** One answer's rdata in dns_get_record's keys, or null when it does not read. */
	private static function shape(string $msg, int $start, int $len, int $type, string $owner, int $ttl): ?array {
		$rdata = substr($msg, $start, $len);
		$base = array('host' => $owner, 'class' => 'IN', 'ttl' => $ttl);
		switch ($type) {
			case 1:
				return $len === 4 ? $base + array('type' => 'A', 'ip' => inet_ntop($rdata)) : null;
			case 28:
				return $len === 16 ? $base + array('type' => 'AAAA', 'ipv6' => inet_ntop($rdata)) : null;
			case 16:
				$entries = array();
				$p = 0;
				while ($p < $len) {
					$l = ord($rdata[$p]);
					if ($p + 1 + $l > $len) {
						return null;
					}
					$entries[] = substr($rdata, $p + 1, $l);
					$p += 1 + $l;
				}
				return $base + array('type' => 'TXT', 'txt' => implode('', $entries), 'entries' => $entries);
			case 15:
				if ($len < 3) { return null; }
				$target = self::readName($msg, $start + 2);
				return $target === null ? null
					: $base + array('type' => 'MX', 'pri' => unpack('n', substr($rdata, 0, 2))[1], 'target' => $target[0]);
			case 33:
				if ($len < 7) { return null; }
				$v = unpack('npri/nweight/nport', substr($rdata, 0, 6));
				$target = self::readName($msg, $start + 6);
				return $target === null ? null
					: $base + array('type' => 'SRV', 'pri' => $v['pri'], 'weight' => $v['weight'],
						'port' => $v['port'], 'target' => $target[0]);
			case 2:
			case 5:
				$target = self::readName($msg, $start);
				return $target === null ? null
					: $base + array('type' => $type === 2 ? 'NS' : 'CNAME', 'target' => $target[0]);
		}
		return null;
	}

	/**
	 * The name at $pos, following compression pointers, as [name, offset past it
	 * in the original position], or null when it does not read.
	 */
	private static function readName(string $msg, int $pos): ?array {
		$labels = array();
		$n = strlen($msg);
		$end = null;
		$jumps = 0;
		while (true) {
			if ($pos >= $n) {
				return null;
			}
			$len = ord($msg[$pos]);
			if ($len === 0) {
				$pos++;
				break;
			}
			if (($len & 0xc0) === 0xc0) {
				if ($pos + 1 >= $n || ++$jumps > 20) {
					return null;
				}
				if ($end === null) {
					$end = $pos + 2;
				}
				$pos = (($len & 0x3f) << 8) | ord($msg[$pos + 1]);
				continue;
			}
			if ($len & 0xc0) {
				return null;
			}
			if ($pos + 1 + $len > $n) {
				return null;
			}
			$labels[] = substr($msg, $pos + 1, $len);
			$pos += 1 + $len;
		}
		return array(strtolower(implode('.', $labels)), $end ?? $pos);
	}

	/** The zone's nameserver addresses, IPv4 first, found once per request through the system resolver. */
	private static function nameserverIps(string $zone): array {
		if (array_key_exists($zone, self::$zone_ips)) {
			return self::$zone_ips[$zone];
		}
		$ips = array();
		$v6 = array();
		// A hosted domain may be a name inside a larger zone (dev.example.com in
		// example.com): walk up to the first name that has nameservers.
		$ns = array();
		$labels = explode('.', $zone);
		while (count($labels) >= 2 && empty($ns)) {
			$found = @dns_get_record(implode('.', $labels), DNS_NS);
			$ns = is_array($found) ? $found : array();
			array_shift($labels);
		}
		foreach ($ns as $r) {
			$host = (string)($r['target'] ?? '');
			if ($host === '') { continue; }
			foreach ((array)@dns_get_record($host, DNS_A) as $a) {
				if (!empty($a['ip'])) { $ips[] = $a['ip']; }
			}
			foreach ((array)@dns_get_record($host, DNS_AAAA) as $a) {
				if (!empty($a['ipv6'])) { $v6[] = $a['ipv6']; }
			}
		}
		self::$zone_ips[$zone] = array_values(array_unique(array_merge($ips, $v6)));
		return self::$zone_ips[$zone];
	}

	/** One UDP round trip; null on any failure or a reply whose id does not match. */
	private static function send(string $ip, string $packet): ?string {
		$host = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
		$errno = 0;
		$errstr = '';
		$sock = @stream_socket_client('udp://' . $host . ':53', $errno, $errstr, self::TIMEOUT_SECONDS);
		if ($sock === false) {
			return null;
		}
		stream_set_timeout($sock, self::TIMEOUT_SECONDS);
		$reply = null;
		if (@fwrite($sock, $packet) === strlen($packet)) {
			$data = @fread($sock, 65535);
			if (is_string($data) && strlen($data) >= 12 && substr($data, 0, 2) === substr($packet, 0, 2)) {
				$reply = $data;
			}
		}
		fclose($sock);
		return $reply;
	}
}
