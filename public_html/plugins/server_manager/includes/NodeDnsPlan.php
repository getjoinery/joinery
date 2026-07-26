<?php
/**
 * NodeDnsPlan - the DNS a managed node needs to exist on the internet.
 *
 * A node's site domain has to resolve to the node before anything else works:
 * the SSL gate waits on it, and until it is published the node is a server
 * nobody can reach. That record has always been an owner action typed into
 * somebody's DNS dashboard; expressing it as a plan lets the shared publish box
 * write it with a diff in front of the operator instead
 * (specs/dns_record_management.md).
 *
 * Attended, not zero-touch: the ephemeral-only credential means cloud node birth
 * still has a human at the publish step. What it no longer has is a copy-paste
 * step.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));

class NodeDnsPlan {

	/**
	 * The plan for one node, or null when there is nothing to publish — no site
	 * domain, or no address to point it at.
	 */
	public static function forNode($node): ?DnsRecordPlan {
		$domain = self::siteDomain($node);
		$ip = self::publicIp($node);
		if ($domain === '' || $ip === '') {
			return null;
		}

		$plan = new DnsRecordPlan($domain, 'server_manager');
		$plan->addRecord(
			filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A',
			$domain,
			$ip,
			null,
			null,
			'Points ' . $domain . ' at this node. Certificate issuance waits on this record.'
		);
		return $plan;
	}

	/** The host part of the node's site URL, lowercase and without a port. */
	public static function siteDomain($node): string {
		$url = trim((string)$node->get('mgn_site_url'));
		if ($url === '') {
			return '';
		}
		if (strpos($url, '://') === false) {
			$url = 'https://' . $url;
		}
		$host = parse_url($url, PHP_URL_HOST);
		return $host ? strtolower($host) : '';
	}

	/**
	 * The node's public address: the one its cloud provision recorded when it
	 * was born, else the connection host when that is itself an address. A
	 * hostname in mgn_host is deliberately not resolved — publishing a record
	 * derived from a lookup of the name being published is circular.
	 */
	public static function publicIp($node): string {
		$provision = NodeReverseDns::provisionForNode($node);
		if ($provision) {
			$ip = trim((string)$provision->get('cvp_instance_ip'));
			if ($ip !== '') {
				return $ip;
			}
		}
		$host = trim((string)$node->get('mgn_host'));
		return filter_var($host, FILTER_VALIDATE_IP) ? $host : '';
	}
}
