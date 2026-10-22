<?php
/**
 * SiteCopyAddressFacts - what still names a site's old server by its address
 * once a switch-over gives the site a new one
 * (specs/site_copy_anything_to_anything.md D1, the IP-bound list).
 *
 * A move by DNS, by hand, or through a proxy gives the site the copy's
 * address; anything else that names the old server's address keeps naming
 * it, and that address goes back to its provider when the old server is
 * deleted. The Copy tab lists them before the switch-over starts, and the
 * switch-over keeps the list it started with:
 *
 *   - the site's SPF record, read from public DNS: each ip4: and ip6: entry
 *     that covers one of the old server's addresses. Mail the site sends
 *     from the copy fails SPF until the record names the copy instead. The
 *     owner changes it, at the DNS host.
 *   - reverse DNS: on a server this management node made, it sets the copy's
 *     reverse DNS, its IPv4 and (where the site has an AAAA) its IPv6, once
 *     the public resolvers name the copy (a provider takes only a name that
 *     resolves to the address); on any other, the owner
 *     does, at its provider. A container's address is its host's, and so is
 *     its reverse DNS. Through a proxy the site's name resolves to the proxy,
 *     so no reverse DNS can name it.
 *   - allow-lists and firewalls elsewhere, which this page cannot see.
 *
 * An IP swap moves the address itself and keeps all of these, so it lists
 * nothing here (its IPv6 note is IpSwapMove::ipv6_note()).
 *
 * Read-only and credential-free; never throws. A resolver that cannot be
 * asked lists no SPF entry, and the list says so.
 *
 * @version 1.1 - reverse DNS names both of the copy's addresses (B33)
 * @version 1.0
 */
class SiteCopyAddressFacts {
	/** The TXT records read for each name in this request: one page lists several methods. */
	private static $txt = array();

	/**
	 * The facts for one way of moving the address, as sentences.
	 *
	 * @param ManagedNode      $source  the site's node, before the switch-over
	 * @param string           $domain  the site's name
	 * @param string           $method  a SiteCopyRunner::METHOD_*
	 * @param ManagedNode|null $copy    the copy's row, when it exists
	 * @param bool             $on_host the copy is a container on a host
	 * @return string[]
	 */
	public static function for_switch(ManagedNode $source, string $domain, string $method, ?ManagedNode $copy = null,
			bool $on_host = false): array {
		if (!in_array($method, array(SiteCopyRunner::METHOD_DNS, SiteCopyRunner::METHOD_MANUAL, SiteCopyRunner::METHOD_PROXIED), true)) {
			return array();
		}
		try {
			$by = ProxiedOriginMove::machine_addresses($source);
		} catch (Exception $e) {
			$by = array(4 => array(), 6 => array());
		}
		$old = array_merge($by[4], $by[6]);
		if (!$old) {
			return array();
		}
		$facts = array();
		$spf = self::spf_entries($domain, $old);
		if ($spf === null) {
			$facts[] = "{$domain}'s SPF record could not be read from public DNS: if it names " . implode(' or ', $old)
				. ', change that to the copy\'s address, or mail the site sends from the copy fails SPF.';
		}
		foreach ((array)$spf as $entry) {
			$facts[] = "{$domain}'s SPF record names {$entry}: mail the site sends from the copy fails SPF until the record "
				. 'names the copy\'s address instead. Change it at the DNS host.';
		}
		if ($method === SiteCopyRunner::METHOD_PROXIED) {
			// Nothing to do: the name resolves to the proxy on both sides.
		} elseif ($on_host) {
			$facts[] = 'Reverse DNS: the copy answers at its host\'s address, whose reverse DNS is the host\'s.';
		} elseif ($copy && self::made_here($copy)) {
			$facts[] = "Reverse DNS: this management node sets the copy's addresses to answer {$domain} once the public "
				. 'resolvers name the copy: its IPv4, and its IPv6 where the site has an AAAA record.';
		} else {
			$facts[] = "Reverse DNS: set the copy's addresses to answer {$domain} at its provider, once the public resolvers "
				. 'name the copy: its IPv4, and its IPv6 where the site has an AAAA record.';
		}
		$facts[] = 'Anything else that lets the old server in by its address (' . implode(', ', $old) . '), such as an '
			. 'allow-list at a registrar or a mail or API provider, or a firewall in front of another service, keeps naming '
			. 'the old server: change it to the copy\'s address.';
		return $facts;
	}

	/**
	 * Each ip4: or ip6: entry of the domain's SPF record that covers one of
	 * $addresses, as written. Null when no public resolver answered.
	 *
	 * @param string[] $addresses
	 * @return string[]|null
	 */
	public static function spf_entries(string $domain, array $addresses): ?array {
		$key = strtolower($domain);
		if (!array_key_exists($key, self::$txt)) {
			self::$txt[$key] = null;
			foreach (PublicResolvers::answers($domain, 'TXT') as $answer) {
				if ($answer['ok']) {
					self::$txt[$key] = $answer['values'];
					break;
				}
			}
		}
		$records = self::$txt[$key];
		if ($records === null) {
			return null;
		}
		$out = array();
		foreach ($records as $txt) {
			$terms = preg_split('/\s+/', trim((string)$txt));
			if (strtolower((string)array_shift($terms)) !== 'v=spf1') {
				continue;
			}
			foreach ($terms as $term) {
				if (!preg_match('/^[+?~-]?(ip4|ip6):(\S+)$/i', $term, $m)) {
					continue;
				}
				foreach ($addresses as $a) {
					if (self::covers($m[2], $a)) {
						$out[] = $term;
						break;
					}
				}
			}
		}
		return array_values(array_unique($out));
	}

	/** Does an address or CIDR block (an SPF entry's value) cover $address? */
	public static function covers(string $block, string $address): bool {
		$parts = explode('/', $block, 2);
		$net = @inet_pton($parts[0]);
		$ip = @inet_pton($address);
		if ($net === false || $ip === false || strlen($net) !== strlen($ip)) {
			return false;
		}
		$bits = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : strlen($ip) * 8;
		if ($bits > strlen($ip) * 8) {
			return false;
		}
		$whole = intdiv($bits, 8);
		if (substr($net, 0, $whole) !== substr($ip, 0, $whole)) {
			return false;
		}
		$rest = $bits % 8;
		if ($rest === 0) {
			return true;
		}
		$mask = (0xff << (8 - $rest)) & 0xff;
		return (ord($net[$whole]) & $mask) === (ord($ip[$whole]) & $mask);
	}

	/** Was this server made by this management node, so it can set its reverse DNS? */
	public static function made_here(ManagedNode $node): bool {
		$p = NodeReverseDns::provisionForNode($node);
		return $p !== null && ComputeDriverRegistry::known((string)$p->get('cvp_provider')) && !$p->is_transferred();
	}
}
