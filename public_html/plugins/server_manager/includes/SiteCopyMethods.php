<?php
/**
 * SiteCopyMethods - every way a copy's address could move, known before the
 * copy is made (specs/site_copy_anything_to_anything.md D6).
 *
 * The ways, in the order the Copy tab offers them:
 *
 *   proxied_origin  the proxy in front of the site forwards to the copy: the
 *                   domain's DNS is at a host that proxies (Cloudflare).
 *   ip_swap         the two servers swap their public addresses at the
 *                   provider: both made here, at a provider that swaps, in one
 *                   account and one region; never a shared host's address.
 *   dns             the site's own A and AAAA records change to the copy's
 *                   addresses, with a token for its zone typed at each press:
 *                   the domain's DNS is at a host this management node has a
 *                   driver for.
 *   manual          the owner changes the records at their DNS host; always.
 *
 * Read-only, from this management node's records and the domain's live
 * nameservers. The DNS host and the provider are asked on each press; what
 * is decided here is only whether a way could work at all, so the tab says it
 * before an hour's copy rather than after.
 *
 * The same answer for an existing copy (SiteCopyRunner::switch_methods) uses
 * the servers on record instead of the target chosen.
 *
 * @version 1.0
 */

class SiteCopyMethods {

	/** A server this management node creates, in one of the accounts it holds. */
	const TARGET_MADE = 'made';
	/** A server the owner brings, from anywhere. */
	const TARGET_BROUGHT = 'brought';
	/** A container on an engine host. */
	const TARGET_HOST = 'host';

	/**
	 * Which DNS driver serves a domain: fn(string $domain): string, a driver key
	 * or ''. Null reads the live nameservers. A variable only so a test can
	 * stand in for DNS; nothing in production sets it.
	 *
	 * @var callable|null
	 */
	public static $dns_host = null;

	/**
	 * The ways the address could move for a copy of $source onto $target:
	 * [method => reasons it cannot, empty when it can].
	 *
	 * @param array $target kind (TARGET_*); for TARGET_MADE also account
	 *                      ('oca:<id>' or 'cca:<id>'), provider and region
	 */
	public static function for_target(ManagedNode $source, array $target): array {
		$domain = SiteCopyRunner::site_domain($source);
		$host_key = $domain !== '' ? self::dns_host_key($domain) : '';
		$host_class = $host_key !== '' ? DnsDriverRegistry::get($host_key) : null;
		return array(
			SiteCopyRunner::METHOD_PROXIED => self::proxy_refusals($domain, $host_class),
			SiteCopyRunner::METHOD_IP_SWAP => self::swap_refusals($source, $target),
			SiteCopyRunner::METHOD_DNS     => self::dns_refusals($domain, $host_class),
			SiteCopyRunner::METHOD_MANUAL  => array(),
		);
	}

	/** The DNS driver key for a domain's live nameservers, or ''. */
	public static function dns_host_key(string $domain): string {
		if (self::$dns_host) {
			return (string)(self::$dns_host)($domain);
		}
		return (string)DnsDriverRegistry::identifyHost(DnsPublishBox::liveNameservers($domain));
	}

	private static function proxy_refusals(string $domain, ?string $host_class): array {
		if ($domain === '') {
			return array('The site has no https address on record.');
		}
		if (!$host_class || !is_subclass_of($host_class, 'DnsProxiedOrigin')) {
			return array($domain . '\'s DNS is not at a host that proxies (Cloudflare).');
		}
		return array();
	}

	private static function dns_refusals(string $domain, ?string $host_class): array {
		if ($domain === '') {
			return array('The site has no https address on record.');
		}
		if (!$host_class || $host_class::credentialMode() !== DnsProvider::CREDENTIAL_API) {
			return array($domain . '\'s DNS is at a host this management node has no driver for, so its records are changed by '
				. 'hand: switch manually.');
		}
		return array();
	}

	/**
	 * Why the two servers could not swap addresses, read before the copy's
	 * server exists: the target must be a server made here, at the source's
	 * provider, in its account and region. What only the provider can say
	 * (one address each, its firewalls) is checked on the press.
	 */
	private static function swap_refusals(ManagedNode $source, array $target): array {
		$kind = (string)($target['kind'] ?? '');
		if ($kind === self::TARGET_HOST) {
			return array('The copy is a container on a shared host, whose address is every one of its sites\'; it is never swapped.');
		}
		if (trim((string)$source->get('mgn_container_name')) !== '') {
			return array('The site runs in a container on a server it shares with other sites; that server\'s address '
				. 'is every one of theirs, so it is never swapped.');
		}
		if ($kind !== self::TARGET_MADE) {
			return array('An address swap needs both servers to have been created by this management node; the copy\'s is brought.');
		}
		$sp = IpSwapMove::provision_of($source);
		if (!$sp) {
			return array('An address swap needs both servers to have been created by this management node; the site\'s server was not.');
		}
		$why = array();
		$provider = (string)($target['provider'] ?? '');
		if ((string)$sp->get('cvp_provider') !== $provider) {
			$why[] = 'The site\'s server is at ' . ComputeDriverRegistry::label((string)$sp->get('cvp_provider')) . ' and the copy\'s '
				. 'would be at ' . ComputeDriverRegistry::label($provider) . '; an address moves only within one provider.';
		} elseif (!ComputeDriverRegistry::supports($provider, 'CloudAddressSwap')) {
			$why[] = ComputeDriverRegistry::label($provider) . ' addresses are not swapped by this management node.';
		}
		if ($sp->is_transferred()) {
			$why[] = 'The site\'s server was handed to its customer\'s own account.';
		}
		if (IpSwapMove::account_of($sp) !== (string)($target['account'] ?? '')) {
			$why[] = 'The copy would be in another cloud account than the site\'s server (' . HeldCloudAccounts::name(IpSwapMove::account_of($sp))
				. '); an address moves only between servers of one account.';
		}
		$region = strtolower(trim((string)($target['region'] ?? '')));
		if ($region !== strtolower(trim((string)$sp->get('cvp_region')))) {
			$why[] = 'The site\'s server is in ' . $sp->get('cvp_region') . ' and the copy would be in ' . ($region !== '' ? $region : 'another region')
				. '; an address moves only within one region.';
		}
		return $why;
	}

	/**
	 * The target an existing copy went to, as for_target() takes it.
	 */
	public static function target_of(SiteCopy $copy): array {
		if ($copy->on_host()) {
			return array('kind' => self::TARGET_HOST);
		}
		$node = SiteCopyRunner::copy_row($copy, true);
		$p = ($node ? IpSwapMove::provision_of($node) : null) ?? SiteCopyRunner::provision($copy);
		if ($p) {
			return array('kind' => self::TARGET_MADE, 'account' => IpSwapMove::account_of($p),
				'provider' => (string)$p->get('cvp_provider'), 'region' => (string)$p->get('cvp_region'));
		}
		return array('kind' => self::TARGET_BROUGHT);
	}

	/** Plain words for each way, for the tab. */
	public static function label(string $method, string $swap_provider = ''): string {
		switch ($method) {
			case SiteCopyRunner::METHOD_PROXIED:
				return 'through the proxy in front of the site (Cloudflare)';
			case SiteCopyRunner::METHOD_IP_SWAP:
				return 'by swapping the two servers\' IP addresses' . ($swap_provider !== '' ? ' at ' . ComputeDriverRegistry::label($swap_provider) : '');
			case SiteCopyRunner::METHOD_DNS:
				return 'by changing the site\'s DNS records, with a token for its zone';
			case SiteCopyRunner::METHOD_MANUAL:
				return 'by your own change to the site\'s DNS';
		}
		return $method;
	}
}
