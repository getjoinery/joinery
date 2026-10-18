<?php
/**
 * PublicResolvers - what the big public resolvers answer for a name now.
 *
 * The system resolver is one cache, often this machine's own; a record that
 * moved is seen by the world when the resolvers most visitors use agree. This
 * asks three of them over DNS-over-HTTPS (JSON), each its own cache, and says
 * what each answered and for how long it will keep the answer.
 *
 * Read-only and credential-free. A resolver that cannot be asked answers
 * nothing, which is never read as agreement.
 *
 * @version 1.0
 */

class PublicResolvers {

	/** The resolvers asked: name => DNS-over-HTTPS JSON endpoint. */
	const RESOLVERS = array(
		'Cloudflare' => 'https://cloudflare-dns.com/dns-query',
		'Google'     => 'https://dns.google/resolve',
		'Quad9'      => 'https://dns.quad9.net:5053/dns-query',
	);

	const TYPE_NUMBERS = array('A' => 1, 'AAAA' => 28);

	/**
	 * Asks one URL: fn(string $url): ?array, the decoded JSON or null on any
	 * failure. Null asks over HTTPS with curl. A variable only so a test can
	 * stand in for the resolvers; nothing in production sets it.
	 *
	 * @var callable|null
	 */
	public static $asker = null;

	/**
	 * Each resolver's answer for one name and type (A or AAAA):
	 * [resolver => ['ok' => bool, 'values' => string[], 'ttl' => int]].
	 */
	public static function answers(string $name, string $type): array {
		$out = array();
		$want = self::TYPE_NUMBERS[$type] ?? 0;
		foreach (self::RESOLVERS as $resolver => $endpoint) {
			$url = $endpoint . '?name=' . rawurlencode($name) . '&type=' . rawurlencode($type);
			$json = self::$asker ? (self::$asker)($url) : self::ask($url);
			if (!is_array($json) || (int)($json['Status'] ?? -1) !== 0 && (int)($json['Status'] ?? -1) !== 3) {
				$out[$resolver] = array('ok' => false, 'values' => array(), 'ttl' => 0);
				continue;
			}
			$values = array();
			$ttl = 0;
			foreach ((array)($json['Answer'] ?? array()) as $a) {
				if ((int)($a['type'] ?? 0) === $want && isset($a['data'])) {
					$values[] = strtolower(trim((string)$a['data']));
					$ttl = max($ttl, (int)($a['TTL'] ?? 0));
				}
			}
			$out[$resolver] = array('ok' => true, 'values' => $values, 'ttl' => $ttl);
		}
		return $out;
	}

	/**
	 * Does every resolver answer exactly $expected for the name (as addresses,
	 * in any order)? False while any one cannot be asked.
	 *
	 * @param string[] $expected
	 */
	public static function agree(string $name, string $type, array $expected): bool {
		$want = self::packed_set($expected);
		foreach (self::answers($name, $type) as $answer) {
			if (!$answer['ok'] || self::packed_set($answer['values']) !== $want) {
				return false;
			}
		}
		return true;
	}

	/** Addresses as a sorted set of their packed forms, so spellings compare equal. */
	private static function packed_set(array $addresses): array {
		$out = array();
		foreach ($addresses as $a) {
			$p = @inet_pton(trim((string)$a));
			if ($p !== false) {
				$out[] = bin2hex($p);
			}
		}
		$out = array_values(array_unique($out));
		sort($out);
		return $out;
	}

	private static function ask(string $url): ?array {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_HTTPHEADER     => array('Accept: application/dns-json'),
		));
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($body === false || $status !== 200) {
			return null;
		}
		$json = json_decode((string)$body, true);
		return is_array($json) ? $json : null;
	}
}
