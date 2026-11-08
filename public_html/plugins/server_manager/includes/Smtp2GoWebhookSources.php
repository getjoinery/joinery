<?php
/**
 * Where SMTP2GO's webhook deliveries may come from (/ajax/smtp2go_webhook).
 *
 * SMTP2GO does not sign its webhooks, so a delivery is accepted only from a
 * known sending address and with the deployment's basic-auth secret. The
 * addresses are two sets:
 *
 *  - **SMTP2GO's own**, resolved from the name it publishes,
 *    webhooks.smtp2go.com, rather than written here: moving them is its
 *    business, not a setting somebody here has to remember.
 *  - **Svix's**, the delivery service SMTP2GO sends its webhooks through
 *    (https://docs.svix.com/receiving/source-ips, US and EU regions; the
 *    Australia and Canada regions are private ones). Svix publishes no DNS name
 *    for them, so they are written here. Every Svix delivery carries
 *    sender-9YMgn in its User-Agent, which is how a refused one in the log is
 *    recognised if this list goes stale.
 *
 * When DNS gives no answer the whole list is empty, and the caller refuses: for
 * an unsigned webhook the address is half of the entire check, and an
 * allowlist that fell open under DNS failure would be worth nothing on exactly
 * the day it mattered. The list is cached only for the life of the request: a
 * file cache under the system temp directory would be written by the web user,
 * so any local account could pre-seed it, and the resolver in front of this
 * already caches the lookup.
 *
 * @version 1.0
 */
class Smtp2GoWebhookSources {

	/** The name SMTP2GO publishes its webhook addresses under. */
	const PUBLISHED_NAME = 'webhooks.smtp2go.com';

	/** Svix's sending addresses and ranges, IPv4 and IPv6. */
	public static function svixSources(): array {
		return array(
			'44.228.126.217', '50.112.21.217', '52.24.126.164', '54.148.139.208', '2600:1f24:64:8000::/56',
			'52.215.16.239', '54.216.8.72', '63.33.109.123', '2a05:d028:17:8000::/56',
		);
	}

	/**
	 * Every address a delivery may come from; [] when SMTP2GO's name does not
	 * resolve, which the caller treats as a refusal.
	 */
	public static function allowed(): array {
		static $cached = null;
		if ($cached !== null) {
			return $cached;
		}
		$out = array();
		foreach ((array)@dns_get_record(self::PUBLISHED_NAME, DNS_A) as $record) {
			if (!empty($record['ip'])) { $out[] = (string)$record['ip']; }
		}
		foreach ((array)@dns_get_record(self::PUBLISHED_NAME, DNS_AAAA) as $record) {
			if (!empty($record['ipv6'])) { $out[] = (string)$record['ipv6']; }
		}
		if (!$out) {
			return $cached = array();
		}
		return $cached = array_values(array_unique(array_merge($out, self::svixSources())));
	}

	/**
	 * Whether $ip is one of $allowed: single addresses or CIDR ranges, IPv4 or
	 * IPv6, compared as addresses (inet_pton), never as text. An IPv4 address a
	 * dual-stack socket reports as ::ffff:a.b.c.d counts as that IPv4.
	 */
	public static function matches(string $ip, array $allowed): bool {
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return false;
		}
		if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			$packed = substr($packed, 12);
		}
		foreach ($allowed as $entry) {
			$entry = (string)$entry;
			$bits = null;
			if (strpos($entry, '/') !== false) {
				[$entry, $bits] = explode('/', $entry, 2);
				$bits = (int)$bits;
			}
			$net = @inet_pton($entry);
			if ($net === false || strlen($net) !== strlen($packed)) {
				continue;
			}
			if ($bits === null) {
				if ($net === $packed) { return true; }
				continue;
			}
			if ($bits < 0 || $bits > strlen($net) * 8) {
				continue;
			}
			$whole = intdiv($bits, 8);
			$rest = $bits % 8;
			if (substr($net, 0, $whole) !== substr($packed, 0, $whole)) {
				continue;
			}
			if ($rest === 0) {
				return true;
			}
			$mask = (0xFF << (8 - $rest)) & 0xFF;
			if ((ord($net[$whole]) & $mask) === (ord($packed[$whole]) & $mask)) {
				return true;
			}
		}
		return false;
	}
}
