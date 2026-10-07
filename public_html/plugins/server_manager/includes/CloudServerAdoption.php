<?php
/**
 * CloudServerAdoption — record a running cloud server this management node
 * did not create, against the node that runs on it
 * (specs/test_cloud_account_and_prod_management.md WP9).
 *
 * Reverse DNS and an IP-swap switch-over find a node's server only through
 * its provision record, and only creating a server writes one. A node that
 * joined from another management node has none, so both refuse for it here.
 * Adoption finds the one server the node runs on, among the accounts this
 * management node holds (the operator token and each connected account), by
 * the node's addresses — the match MachineTransferWatch makes every day — and
 * writes an admin-origin provision in install mode 'adopted'.
 *
 * Nothing is installed and nothing on the server changes. An adopted server
 * is never one somebody bought, so it is never billed, put on a trial or
 * handed to a customer.
 *
 * @version 1.0
 */

class CloudServerAdoption {

	/**
	 * Why this node cannot adopt a server at all, from records alone (no
	 * provider is asked). Empty when it can.
	 */
	public static function refusals(ManagedNode $node): array {
		$why = array();
		if ($node->get('mgn_delete_time')) {
			$why[] = 'The node is removed.';
		}
		if (trim((string)$node->get('mgn_container_name')) !== '') {
			$why[] = 'The site runs in a container on a server it shares with other sites; that server is adopted by the server\'s own node.';
		}
		$existing = self::existing($node);
		if ($existing) {
			$why[] = 'This node already has a cloud-server record (provision #' . (int)$existing->key . ', instance '
				. $existing->get('cvp_instance_id') . ').';
		}
		return $why;
	}

	/** The node's live provision with an instance, or null. */
	public static function existing(ManagedNode $node): ?CustomerCloudProvision {
		$rows = new MultiCustomerCloudProvision(array('node_id' => (int)$node->key, 'deleted' => false),
			array('cvp_customer_cloud_provision_id' => 'DESC'));
		foreach ($rows as $row) {
			if (trim((string)$row->get('cvp_instance_id')) !== '') {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The one server the node runs on:
	 *   ok        bool
	 *   reason    string  why not, when not ok
	 *   account   string  'operator' or 'cca:<id>'
	 *   instance  array   the provider's listing entry (id, label, region, ipv4_public, ipv6, created)
	 *
	 * A match by the node's own host beats one by what its agent reported at
	 * its join (a host name behind a proxy resolves to the proxy). More than
	 * one server at the strongest match is refused, naming each: adopting the
	 * wrong server would point reverse DNS and an address swap at it.
	 *
	 * @param array|null    $accounts [key => CloudMachineTransfer driver], or null for the ones this plane holds
	 * @param callable|null $resolve  host name => addresses (tests pass their own)
	 */
	public static function find(ManagedNode $node, ?array $accounts = null, ?callable $resolve = null): array {
		$refusals = self::refusals($node);
		if ($refusals) {
			return self::no(implode(' ', $refusals));
		}
		$problems = array();
		if ($accounts === null) {
			list($accounts, $problems) = MachineTransferWatch::accounts();
		}
		$all = MachineTransferWatch::node_addresses($resolve ?? array('MachineTransferWatch', 'resolve'));
		if (!isset($all[(int)$node->key])) {
			return self::no('The node is not live.');
		}
		$mine = array((int)$node->key => $all[(int)$node->key]);

		$found = array(MachineTransferWatch::MATCH_HOST => array(), MachineTransferWatch::MATCH_JOIN => array());
		foreach ($accounts as $key => $driver) {
			if (!$driver instanceof CloudMachineTransfer) {
				continue;
			}
			try {
				$instances = $driver->listInstances();
			} catch (Exception $e) {
				$problems[] = 'Account ' . $key . ': its servers could not be listed (' . $e->getMessage() . ').';
				continue;
			}
			foreach ($instances as $instance) {
				$strength = MachineTransferWatch::nodes_on($instance, $mine)[(int)$node->key] ?? 0;
				if ($strength) {
					$found[$strength][] = array('account' => (string)$key, 'instance' => $instance);
				}
			}
		}

		foreach (array(MachineTransferWatch::MATCH_HOST, MachineTransferWatch::MATCH_JOIN) as $strength) {
			if (count($found[$strength]) === 1) {
				return array('ok' => true, 'reason' => '') + $found[$strength][0];
			}
			if (count($found[$strength]) > 1) {
				return self::no('More than one server matches this node\'s address: '
					. implode('; ', array_map(array(__CLASS__, 'describe'), $found[$strength]))
					. '. None is adopted rather than a guess.');
			}
		}
		return self::no('No server on the cloud accounts this management node holds has this node\'s address'
			. ($accounts ? '' : ' (it holds none: set the operator cloud token on Provisioning Setup, or connect an account)')
			. '.' . ($problems ? ' ' . implode(' ', $problems) : ''));
	}

	/**
	 * Find the node's server again and record it. $expect_instance is the
	 * server the operator was shown, if any: a different answer now is
	 * refused rather than adopted unseen.
	 *
	 * @throws CloudServerAdoptionException with the reason, when nothing is adopted
	 */
	public static function adopt(ManagedNode $node, int $user_id, string $expect_instance = '',
			?array $accounts = null, ?callable $resolve = null): CustomerCloudProvision {
		$match = self::find($node, $accounts, $resolve);
		if (!$match['ok']) {
			throw new CloudServerAdoptionException($match['reason']);
		}
		$instance = $match['instance'];
		$instance_id = (string)$instance['id'];
		if ($expect_instance !== '' && $expect_instance !== $instance_id) {
			throw new CloudServerAdoptionException('The server found now (' . self::describe($match)
				. ') is not the one shown (instance ' . $expect_instance . '). Nothing was adopted; reload the page and look again.');
		}
		$ipv4 = (string)(((array)($instance['ipv4_public'] ?? array()))[0] ?? '');
		$ipv6 = CustomerCloudProvision::normalize_address((string)($instance['ipv6'] ?? ''));
		foreach (array_filter(array($ipv4, $ipv6)) as $address) {
			$held = CustomerCloudProvision::for_machine_address($address);
			if ($held && (int)$held->get('cvp_mgn_managed_node_id') !== (int)$node->key) {
				throw new CloudServerAdoptionException('Provision #' . (int)$held->key . ' already records the server at '
					. $address . ', for node #' . (int)$held->get('cvp_mgn_managed_node_id') . '. Nothing was adopted.');
			}
		}

		$account = $match['account'];
		$provision = new CustomerCloudProvision(NULL);
		if ($account === 'operator') {
			$provision->set('cvp_hosting_mode', 'operator');
		} elseif (preg_match('/^cca:(\d+)$/', $account, $m)) {
			$provision->set('cvp_hosting_mode', 'customer');
			$provision->set('cvp_cca_customer_cloud_account_id', (int)$m[1]);
		} else {
			throw new CloudServerAdoptionException('The server was found on account "' . $account
				. '", which is neither the operator token nor a connected account. Nothing was adopted.');
		}
		$domain = (string)(parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST) ?: $node->get('mgn_host'));
		$provision->set('cvp_origin', 'admin');
		$provision->set('cvp_usr_user_id', $user_id);
		$provision->set('cvp_domain', mb_substr($domain, 0, 255));
		$provision->set('cvp_slug', mb_substr((string)$node->get('mgn_slug'), 0, 50));
		$provision->set('cvp_status', 'done');
		// The plane holds Linode accounts only (MachineTransferWatch::accounts).
		$provision->set('cvp_provider', 'linode');
		$provision->set('cvp_instance_id', $instance_id);
		$provision->set('cvp_instance_ip', $ipv4);
		$provision->set('cvp_instance_ipv6', $ipv6 !== '' ? $ipv6 : null);
		$provision->set('cvp_region', (string)($instance['region'] ?? ''));
		$provision->set('cvp_docker_mode', 'bare-metal');
		$provision->set('cvp_install_mode', 'adopted');
		$provision->set('cvp_mgn_managed_node_id', (int)$node->key);
		$provision->save();
		$provision->load();
		return $provision;
	}

	/** "label (instance id, region, account)" for a match. */
	public static function describe(array $match): string {
		$i = $match['instance'];
		return ($i['label'] ?? '?') . ' (instance ' . ($i['id'] ?? '?') . ', ' . ($i['region'] ?? '?') . ', '
			. self::account_name((string)$match['account']) . ')';
	}

	/** Plain words for an account key. */
	public static function account_name(string $key): string {
		return $key === 'operator' ? 'the operator account'
			: (preg_match('/^cca:(\d+)$/', $key, $m) ? 'connected account #' . $m[1] : $key);
	}

	private static function no(string $reason): array {
		return array('ok' => false, 'reason' => $reason, 'account' => '', 'instance' => array());
	}
}

class CloudServerAdoptionException extends Exception {}
