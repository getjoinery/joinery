<?php
/**
 * JoinAutoApproval — approve a join from a machine this plane provisioned, when
 * the plane can prove it is the machine it built (the auto_approve_provisioned_joins spec).
 *
 * A person comparing a key by eye is the wrong tool for machines the plane
 * built itself: it watched the install print each agent's key, it knows the
 * address and the names the agents join under, and the cloud provider can say
 * whether the instance is running there. A pending join is approved only when
 *
 *   1. its source address belongs to a provision that is installing or done,
 *   2. its claimed name is exactly that provision's site name or host-agent name,
 *   3. its key equals the one the install showed for that agent (one-use),
 *   4. the provider says the instance is running at that address, and
 *   5. nothing is already there (the site node has no agent; no host agent is paired).
 *
 * Checks 1-3 and 5 are database reads (verdict(), pure, no provider call) and run
 * first, so a stranger on the public join endpoint cannot make this plane call the
 * provider. Check 4 runs inside the existing approval paths. Everything fails
 * closed: any doubt leaves the request pending, and nothing here ever rejects.
 *
 * @version 1.0
 */
class JoinAutoApproval {

	const SETTING = 'server_manager_auto_approve_joins';

	/** A provision's agents join while it is installing, or after it is done. */
	const PROVISION_STATUSES = array('installing', 'done');

	public static function enabled(): bool {
		$v = Globalvars::get_instance()->get_setting(self::SETTING);
		return $v === null || $v === '' ? true : (bool)(int)$v;
	}

	/**
	 * The agents' keys an install printed, for one provision.
	 *
	 * Host agent: `Asked <url> to adopt this machine as "<name>".` then
	 * `Key fingerprint:  <16 hex>`, kept when <name> is the provision's host-agent
	 * name. Site agent: install.sh's `SITE_AGENT_KEY=<16 hex>` readback.
	 *
	 * @return array{site: ?string, host: ?string}
	 */
	public static function keys_from_install_output(string $output, $provision): array {
		$out = array('site' => null, 'host' => null);
		$output = str_replace("\r", '', preg_replace('/\x1b\[[0-9;]*m/', '', $output));

		$host_name = $provision->host_agent_name();
		if ($host_name !== null && preg_match_all(
				'/Asked\s+\S+\s+to adopt this machine as "([^"\n]+)"\.\s*\n+\s*Key fingerprint:\s+([0-9a-f]{16})\b/i',
				$output, $m, PREG_SET_ORDER)) {
			foreach ($m as $hit) {
				if ($hit[1] === $host_name) { $out['host'] = strtolower($hit[2]); } // the last one wins: a retry mints a new agent
			}
		}
		if (preg_match_all('/^SITE_AGENT_KEY=([0-9a-f]{16})\s*$/mi', $output, $m)) {
			$out['site'] = strtolower(end($m[1]));
		}
		return $out;
	}

	/**
	 * Record what a finished install showed. Only a provision's own site node
	 * counts; a retry replaces the keys, since it mints new agents.
	 */
	public static function record_from_install($node, string $output): void {
		if (!class_exists('CustomerCloudProvision') || !$node || !$node->key) {
			return;
		}
		$rows = new MultiCustomerCloudProvision(['node_id' => (int)$node->key, 'deleted' => false],
			['cvp_customer_cloud_provision_id' => 'DESC']);
		foreach ($rows as $provision) {
			$keys = self::keys_from_install_output($output, $provision);
			if ($keys['site'] === null && $keys['host'] === null) {
				return;
			}
			$provision->set('cvp_expected_site_key', $keys['site']);
			$provision->set('cvp_expected_host_key', $keys['host']);
			$provision->set('cvp_keys_captured_time', gmdate('Y-m-d H:i:s'));
			$provision->save();
			return;
		}
	}

	/**
	 * What would happen to this pending request, without calling the provider.
	 *
	 * @return array{provision: mixed, agent: ?string, eligible: bool, key_mismatch: bool, reason: string}
	 */
	public static function verdict($request): array {
		$v = array('provision' => null, 'agent' => null, 'eligible' => false, 'key_mismatch' => false, 'reason' => '');

		if ($request->get('ajr_status') !== AgentJoinRequest::STATUS_PENDING || $request->is_expired()) {
			$v['reason'] = 'not pending';
			return $v;
		}
		$provision = class_exists('CustomerCloudProvision')
			? CustomerCloudProvision::for_machine_address((string)$request->get('ajr_source_ip')) : null;
		if (!$provision) {
			$v['reason'] = 'no provision of this plane has this address';
			return $v;
		}
		$v['provision'] = $provision;
		if (!in_array((string)$provision->get('cvp_status'), self::PROVISION_STATUSES, true)) {
			$v['reason'] = "the provision is '" . $provision->get('cvp_status') . "', not installing or done";
			return $v;
		}

		$claimed = trim((string)$request->get('ajr_claimed_name'));
		$host_name = $provision->host_agent_name();
		if ($host_name !== null && $claimed === $host_name) {
			$agent = 'host';
		} elseif ($claimed !== '' && $claimed === $provision->site_agent_name()) {
			$agent = 'site';
		} else {
			$v['reason'] = 'the name is not one this provision\'s agents join as';
			return $v;
		}
		$v['agent'] = $agent;

		$expected = strtolower(trim((string)$provision->get($agent === 'host' ? 'cvp_expected_host_key' : 'cvp_expected_site_key')));
		if ($expected === '') {
			$v['reason'] = 'no key was recorded from this provision\'s install';
			return $v;
		}
		if (strtolower(trim((string)$request->get('ajr_fingerprint'))) !== $expected) {
			$v['key_mismatch'] = true;
			$v['reason'] = 'the key does not match the one the install showed';
			return $v;
		}

		if ($agent === 'site') {
			$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
			$node = $node_id ? new ManagedNode($node_id, TRUE) : null;
			if (!$node || !$node->key || $node->get('mgn_delete_time')) {
				$v['reason'] = 'the provision has no site node yet';
				return $v;
			}
			if (trim((string)$node->get('mgn_agent_public_key')) !== '') {
				$v['reason'] = 'the site node already has an agent';
				return $v;
			}
		} else {
			foreach (ProvisionCustomerCloud::machine_node_ids($provision) as $id) {
				if ($id === (int)$provision->get('cvp_mgn_managed_node_id')) { continue; }
				$existing = new ManagedNode($id, TRUE);
				if ($existing->key && !$existing->get('mgn_delete_time') && trim((string)$existing->get('mgn_agent_public_key')) !== '') {
					$v['reason'] = 'a host agent is already paired on this machine';
					return $v;
				}
			}
		}

		$v['eligible'] = true;
		return $v;
	}

	/**
	 * Approve every pending request that is eligible. Returns how many were approved.
	 * A failure on one request (provider down, refused) leaves it pending and never
	 * stops the others.
	 */
	public static function run(): int {
		if (!self::enabled()) {
			return 0;
		}
		$approved = 0;
		foreach (AgentJoinRequest::pending() as $request) {
			try {
				$v = self::verdict($request);
				if (!$v['eligible']) {
					continue;
				}
				$provision = $v['provision'];
				if ($v['agent'] === 'site') {
					AgentChannelEndpoint::approveProvisionSiteJoin($request);
				} else {
					AgentChannelEndpoint::adoptJoin($request);
				}
				// Consume the key: a second request with the same key must not approve again.
				$provision = new CustomerCloudProvision((int)$provision->key, TRUE);
				$provision->set($v['agent'] === 'host' ? 'cvp_expected_host_key' : 'cvp_expected_site_key', null);
				$provision->save();
				$request = new AgentJoinRequest((int)$request->key, TRUE);
				$request->set('ajr_decided_by', 'auto');
				$request->save();
				error_log('JOIN AUTO-APPROVED: ' . $v['agent'] . ' agent "' . $request->get('ajr_claimed_name') . '" key '
					. $request->get('ajr_fingerprint') . ' from ' . $request->get('ajr_source_ip')
					. ' for provision #' . (int)$provision->key . ' (' . $provision->get('cvp_domain') . ')');
				$approved++;
			} catch (Throwable $e) {
				// Left pending, for a person. Said once in the log so a stuck one is findable.
				error_log('JoinAutoApproval: request #' . (int)$request->key . ' left pending: ' . $e->getMessage());
			}
		}
		return $approved;
	}
}
