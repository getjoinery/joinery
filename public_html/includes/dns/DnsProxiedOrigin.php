<?php
/**
 * DnsProxiedOrigin - a DNS host that also proxies: visitors reach its edge,
 * and the edge reaches the address the record names (Cloudflare's orange
 * cloud).
 *
 * Changing that address moves a site to another server within seconds,
 * because visitors only ever see the edge and no resolver cache is involved.
 * A site copy's switch-over uses it (specs/site_copy.md WP7a). It is the one
 * write that keeps a record proxied: a publish always writes DNS-only.
 *
 * A driver implementing this reports $proxied on every address record it
 * lists.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsProvider.php'));

interface DnsProxiedOrigin {

	/**
	 * Point a proxied A or AAAA record at another address, leaving everything
	 * else about it (the proxy, the TTL, the name) as it is.
	 *
	 * @param DnsRecord $live    A record as listRecords() returned it.
	 * @param string    $address The new address, of the record's own family.
	 * @throws DnsProviderException when the record is not a proxied address
	 *         record, the address is not of its family, or the write fails.
	 */
	public function setProxiedOrigin(string $zone, DnsRecord $live, string $address): void;
}
