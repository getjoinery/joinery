<?php
/**
 * PublicResolvers - what the big public resolvers answer for a name now.
 *
 * The system resolver is one cache, often this machine's own; a record that
 * moved is seen by the world when the resolvers most visitors use agree. This
 * asks three of them over DNS-over-HTTPS, each its own cache, and says what
 * each answered and for how long it will keep the answer.
 *
 * Every resolver is asked the same way: RFC 8484 wire format, GET on port
 * 443, the one form all three answer (Quad9's JSON endpoint is on a port an
 * outbound firewall may close).
 *
 * Read-only and credential-free. A resolver that cannot be asked answers
 * nothing, which is never read as agreement.
 *
 * @version 1.1 - every resolver asked in RFC 8484 wire format on 443 (Quad9's JSON port 5053 is unreachable from
 *                some networks); agree() is gone, its one caller judges the answers itself
 * @version 1.0
 */

class PublicResolvers {

	/** The resolvers asked: name => RFC 8484 DNS-over-HTTPS endpoint. */
	const RESOLVERS = array(
		'Cloudflare' => 'https://cloudflare-dns.com/dns-query',
		'Google'     => 'https://dns.google/dns-query',
		'Quad9'      => 'https://dns.quad9.net/dns-query',
	);

	const TYPE_NUMBERS = array('A' => 1, 'AAAA' => 28);

	/**
	 * Asks one resolver: fn(string $resolver, string $name, string $type): ?array,
	 * the answer as ['rcode' => int, 'answers' => [['type' => int, 'data' => string,
	 * 'ttl' => int]]], or null on any failure. Null asks over HTTPS with curl. A
	 * variable only so a test can stand in for the resolvers; nothing in
	 * production sets it.
	 *
	 * @var callable|null
	 */
	public static $asker = null;

	/**
	 * Each resolver's answer for one name and type (A or AAAA):
	 * [resolver => ['ok' => bool, 'values' => string[], 'ttl' => int]].
	 * NXDOMAIN is an answer (no values); any other failure is not ok.
	 */
	public static function answers(string $name, string $type): array {
		$out = array();
		$want = self::TYPE_NUMBERS[$type] ?? 0;
		foreach (array_keys(self::RESOLVERS) as $resolver) {
			$r = self::$asker ? (self::$asker)($resolver, $name, $type) : self::ask($resolver, $name, $want);
			if (!is_array($r) || !in_array((int)($r['rcode'] ?? -1), array(0, 3), true)) {
				$out[$resolver] = array('ok' => false, 'values' => array(), 'ttl' => 0);
				continue;
			}
			$values = array();
			$ttl = 0;
			foreach ((array)($r['answers'] ?? array()) as $a) {
				if ((int)($a['type'] ?? 0) === $want && isset($a['data'])) {
					$values[] = strtolower(trim((string)$a['data']));
					$ttl = max($ttl, (int)($a['ttl'] ?? 0));
				}
			}
			$out[$resolver] = array('ok' => true, 'values' => $values, 'ttl' => $ttl);
		}
		return $out;
	}

	/**
	 * A DNS query in wire format (RFC 1035 4.1): id 0 as RFC 8484 asks, recursion
	 * desired, one question. Null for a name that cannot be one.
	 */
	public static function query(string $name, int $type): ?string {
		$name = rtrim(strtolower(trim($name)), '.');
		if ($name === '' || strlen($name) > 253) {
			return null;
		}
		$q = pack('nnnnnn', 0, 0x0100, 1, 0, 0, 0);
		foreach (explode('.', $name) as $label) {
			if ($label === '' || strlen($label) > 63) {
				return null;
			}
			$q .= chr(strlen($label)) . $label;
		}
		return $q . "\0" . pack('nn', $type, 1);
	}

	/**
	 * A wire-format response read: its rcode and each answer record's type,
	 * TTL and, for A and AAAA, its address. Null for a message that does not
	 * read as one.
	 */
	public static function parse(string $msg): ?array {
		if (strlen($msg) < 12) {
			return null;
		}
		$h = unpack('nid/nflags/nqd/nan/nns/nar', substr($msg, 0, 12));
		$pos = 12;
		for ($i = 0; $i < $h['qd']; $i++) {
			$pos = self::skip_name($msg, $pos);
			if ($pos === null || $pos + 4 > strlen($msg)) {
				return null;
			}
			$pos += 4;
		}
		$answers = array();
		for ($i = 0; $i < $h['an']; $i++) {
			$pos = self::skip_name($msg, $pos);
			if ($pos === null || $pos + 10 > strlen($msg)) {
				return null;
			}
			$rr = unpack('ntype/nclass/Nttl/nlen', substr($msg, $pos, 10));
			$pos += 10;
			if ($pos + $rr['len'] > strlen($msg)) {
				return null;
			}
			$rdata = substr($msg, $pos, $rr['len']);
			$pos += $rr['len'];
			$data = null;
			if (($rr['type'] === 1 && $rr['len'] === 4) || ($rr['type'] === 28 && $rr['len'] === 16)) {
				$data = inet_ntop($rdata);
			}
			$answers[] = array('type' => $rr['type'], 'ttl' => $rr['ttl'] & 0x7fffffff, 'data' => $data);
		}
		return array('rcode' => $h['flags'] & 0x0f, 'answers' => array_values(array_filter($answers, function ($a) {
			return $a['data'] !== null;
		})));
	}

	/** The offset past the name at $pos (labels, or a pointer that ends it), or null. */
	private static function skip_name(string $msg, int $pos): ?int {
		$n = strlen($msg);
		while ($pos < $n) {
			$len = ord($msg[$pos]);
			if ($len === 0) {
				return $pos + 1;
			}
			if (($len & 0xc0) === 0xc0) {
				return $pos + 2 <= $n ? $pos + 2 : null;
			}
			if ($len & 0xc0) {
				return null;
			}
			$pos += 1 + $len;
		}
		return null;
	}

	private static function ask(string $resolver, string $name, int $type): ?array {
		$q = self::query($name, $type);
		if ($q === null) {
			return null;
		}
		$url = self::RESOLVERS[$resolver] . '?dns=' . rtrim(strtr(base64_encode($q), '+/', '-_'), '=');
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_HTTPHEADER     => array('Accept: application/dns-message'),
		));
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($body === false || $status !== 200) {
			return null;
		}
		return self::parse((string)$body);
	}
}
