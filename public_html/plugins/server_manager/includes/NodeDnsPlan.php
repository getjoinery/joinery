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
 * @version 1.1 - the site's name gets an A record for the machine's IPv4 and an AAAA record for
 *                its IPv6, each where the machine has one (the IPv4-and-IPv6 rule). A site on
 *                a shared host has no provision of its own, so it takes its host's addresses
 *                (specs/multi_tenant_docker_hosts.md S23).
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));

class NodeDnsPlan {

	/**
	 * The plan for one node, or null when there is nothing to publish — no site
	 * domain, or no address to point it at.
	 */
	public static function forNode($node): ?DnsRecordPlan {
		$domain = self::siteDomain($node);
		$addresses = self::publicAddresses($node);
		if ($domain === '' || ($addresses['A'] === '' && $addresses['AAAA'] === '')) {
			return null;
		}

		$plan = new DnsRecordPlan($domain, 'server_manager');
		self::addAddressRecords($plan, $domain, $addresses,
			'Points ' . $domain . ' at this node. Certificate issuance waits on this record.');
		return $plan;
	}

	/**
	 * An A record for the IPv4 and an AAAA record for the IPv6, each where
	 * there is one. A dual-stack visitor reaches the site over either, so a
	 * name with only one of them leaves the other family unanswered.
	 *
	 * @param array{A:string,AAAA:string} $addresses as publicAddresses() returns them
	 */
	public static function addAddressRecords(DnsRecordPlan $plan, string $name, array $addresses, string $note): DnsRecordPlan {
		foreach (['A', 'AAAA'] as $type) {
			if (($addresses[$type] ?? '') !== '') {
				$plan->addRecord($type, $name, $addresses[$type], null, null, $note);
			}
		}
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
	 * The node's public address: its IPv4 where it has one, else its IPv6, or
	 * '' with neither. See publicAddresses().
	 */
	public static function publicIp($node): string {
		$addresses = self::publicAddresses($node);
		return $addresses['A'] !== '' ? $addresses['A'] : $addresses['AAAA'];
	}

	/**
	 * The machine's public addresses, one of each family: what its cloud
	 * provision recorded when it was born, else the connection host where that
	 * is itself an address. A site on a shared host has no provision of its
	 * own, so its host's node's provision answers for it. A hostname in
	 * mgn_host is deliberately not resolved — publishing a record derived from
	 * a lookup of the name being published is circular.
	 *
	 * @return array{A:string,AAAA:string} each '' where there is none
	 */
	public static function publicAddresses($node): array {
		$out = ['A' => '', 'AAAA' => ''];
		$candidates = [];
		$provision = NodeReverseDns::provisionForNode($node);
		if (!$provision && (int)$node->get('mgn_mgh_managed_host_id') > 0) {
			$host = new ManagedHost((int)$node->get('mgn_mgh_managed_host_id'), TRUE);
			$host_node = $host->key ? $host->host_node() : null;
			if ($host_node) {
				$provision = NodeReverseDns::provisionForNode($host_node);
			}
		}
		if ($provision) {
			$candidates[] = (string)$provision->get('cvp_instance_ip');
			$candidates[] = (string)$provision->get('cvp_instance_ipv6');
		}
		$candidates[] = (string)$node->get('mgn_host');
		foreach ($candidates as $a) {
			$a = CustomerCloudProvision::normalize_address(preg_replace('#/\d+$#', '', trim($a)));
			if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
				$out['A'] = $out['A'] !== '' ? $out['A'] : $a;
			} elseif (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
				$out['AAAA'] = $out['AAAA'] !== '' ? $out['AAAA'] : $a;
			}
		}
		return $out;
	}
}
