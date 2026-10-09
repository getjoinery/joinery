<?php
/**
 * NodeRemoval — whether a node may be removed for good, and the removal
 * (spec node_hide_and_remove §4).
 *
 * Removing a node for good deletes every record it owns. What it must never do
 * is leave a machine of ours running with nothing watching it, so the guards
 * run in this order and each refusal says what to do:
 *
 *   1. Its backup storage holds nothing: removing it would leave the backups
 *      unclaimed on the target.
 *   2. A site in a container on a server of ours: its host verified the
 *      container gone (Permanently Delete Site stamps mgn_site_removed_time),
 *      or no site was ever seen there.
 *   3. A machine of ours: the provider says the instance is gone. Where this
 *      management node holds no token for the machine's account (a management
 *      node holds one: dev the test account, getjoinery the main one), the
 *      person types the instance ID, or the machine's address when no instance
 *      is recorded, to say they deleted it; that is logged as
 *      [NODE_REMOVE_ATTEST] before the delete, since the node's own record goes.
 *   4. A node not hosted by us has no machine guard: it keeps running, and is
 *      simply no longer managed.
 *
 * The platform never deletes an instance itself.
 *
 * @version 1.0
 */

class NodeRemoval {

	/** Not ours: nothing to wait for. */
	const MACHINE_NONE = 'none';
	/** A site in a container on a server of ours: its container must be gone. */
	const MACHINE_CONTAINER = 'container';
	/** A machine of ours on the account this management node holds the token for: the provider is asked. */
	const MACHINE_PROVIDER = 'provider';
	/** A machine of ours on an account this management node cannot ask about: the person attests. */
	const MACHINE_ATTEST = 'attest';

	/**
	 * Which machine guard applies. $plane_token is the operator token, read
	 * from settings when null.
	 */
	public static function machine_kind(ManagedNode $node, ?string $plane_token = null): string {
		$hosted_at = CloudAccounts::hosted_at($node);
		if (!in_array($hosted_at, array(CloudAccounts::MAIN, CloudAccounts::TEST), true)) {
			return self::MACHINE_NONE;
		}
		if (trim((string)$node->get('mgn_container_name')) !== '') {
			return self::MACHINE_CONTAINER;
		}
		$plane_token = $plane_token ?? ProvisionCustomerCloud::operator_compute_token();
		return ($plane_token !== '' && $hosted_at === CloudAccounts::plane_account())
			? self::MACHINE_PROVIDER : self::MACHINE_ATTEST;
	}

	/**
	 * Whether a live site was ever seen on this node: a status check, a read
	 * version or an uptime result. A node with none (an install that never
	 * stood a site up) has nothing on its host to tear down.
	 */
	public static function site_ever_confirmed($node): bool {
		return (bool)($node->get('mgn_last_status_check') || $node->get('mgn_joinery_version')
			|| $node->get('mgn_uptime_last_status'));
	}

	/** Guard 2, from records alone. */
	public static function container_refusal(ManagedNode $node): string {
		if ($node->get('mgn_site_removed_time') || !self::site_ever_confirmed($node)) {
			return '';
		}
		return 'Its site may still be running in its container on '
			. CloudAccounts::hosted_at_label(CloudAccounts::hosted_at($node))
			. '. Use Permanently Delete Site first; once its host verifies the container gone, this can go.';
	}

	/** The cloud instance on record for the node (its newest provisioning record that names one, removed ones included), or ''. */
	public static function instance_id(ManagedNode $node): string {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT cvp_instance_id FROM cvp_customer_cloud_provisions
			WHERE cvp_mgn_managed_node_id = ? AND COALESCE(cvp_instance_id, '') <> ''
			ORDER BY cvp_customer_cloud_provision_id DESC LIMIT 1");
		$q->execute(array((int)$node->key));
		return trim((string)$q->fetchColumn());
	}

	/**
	 * The machine's public addresses, IPv4 and IPv6, as text: its host
	 * (resolved when it is a name), what its provisioning records hold, and
	 * what it reported at its newest approved join.
	 *
	 * @param callable|null $resolve host name => addresses (tests pass their own)
	 * @return string[]
	 */
	public static function addresses(ManagedNode $node, ?callable $resolve = null): array {
		$resolve = $resolve ?? array('MachineTransferWatch', 'resolve');
		$candidates = array();
		$host = trim((string)$node->get('mgn_host'));
		if ($host !== '') {
			$candidates = IpAddress::binary($host) !== null ? array($host) : (array)$resolve($host);
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT cvp_instance_ip, cvp_instance_ipv6 FROM cvp_customer_cloud_provisions
			WHERE cvp_mgn_managed_node_id = ? ORDER BY cvp_customer_cloud_provision_id DESC");
		$q->execute(array((int)$node->key));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$candidates[] = (string)$row['cvp_instance_ip'];
			$candidates[] = (string)$row['cvp_instance_ipv6'];
		}
		foreach (new MultiAgentJoinRequest(array('ajr_mgn_managed_node_id' => (int)$node->key,
				'status' => AgentJoinRequest::STATUS_APPROVED, 'deleted' => false),
				array('ajr_agent_join_request_id' => 'DESC'), 1) as $join) {
			$candidates = array_merge($candidates, $join->addresses());
		}
		$out = array();
		foreach ($candidates as $a) {
			$a = trim((string)$a);
			$b = IpAddress::binary($a);
			if ($b !== null && !isset($out[$b]) && IpAddress::isPublic($a)) {
				$out[$b] = $a;
			}
		}
		return array_values($out);
	}

	/** What the person types to attest the machine is deleted: its instance ID, else its first address; '' when neither is known. */
	public static function attest_text(ManagedNode $node, ?callable $resolve = null): string {
		$id = self::instance_id($node);
		if ($id !== '') {
			return $id;
		}
		return self::addresses($node, $resolve)[0] ?? '';
	}

	/** Whether what was typed names this machine: its instance ID, or (with none on record) one of its addresses, compared as addresses. */
	public static function attest_matches(ManagedNode $node, string $typed, ?callable $resolve = null): bool {
		$typed = trim($typed);
		if ($typed === '') {
			return false;
		}
		$id = self::instance_id($node);
		if ($id !== '') {
			return hash_equals($id, $typed);
		}
		return IpAddress::binary($typed) !== null && IpAddress::in($typed, self::addresses($node, $resolve));
	}

	/**
	 * Guard 3 with a token: '' when the provider says the machine is gone.
	 * By instance ID when one is on record, else by the node's addresses in
	 * the account's listing. Any answer but "not there" refuses.
	 */
	public static function provider_refusal(ManagedNode $node, $driver, ?callable $resolve = null): string {
		$account = CloudAccounts::hosted_at_label(CloudAccounts::hosted_at($node));
		$id = self::instance_id($node);
		if ($id !== '') {
			try {
				$instance = $driver->getInstance($id);
			} catch (CloudComputeException $e) {
				if ((int)$e->getCode() === 404) {
					return '';
				}
				return 'Could not ask ' . $account . ' whether instance ' . $id . ' is gone (' . $e->getMessage()
					. '). Nothing was removed; try again.';
			} catch (Throwable $e) {
				return 'Could not ask ' . $account . ' whether instance ' . $id . ' is gone (' . $e->getMessage()
					. '). Nothing was removed; try again.';
			}
			return 'Instance ' . $id . ' (' . ($instance['label'] ?? '?') . ') still exists at ' . $account
				. '. Delete it at the provider first; the platform never deletes a machine itself.';
		}

		$mine = self::addresses($node, $resolve);
		if (!$mine) {
			return 'No instance is on record for this machine and none of its addresses is known, so '
				. $account . ' cannot be asked whether it is gone. Set its Host to the machine\'s IP address in Connection Settings.';
		}
		try {
			$instances = $driver->listInstances();
		} catch (Throwable $e) {
			return 'Could not list the servers at ' . $account . ' (' . $e->getMessage() . '). Nothing was removed; try again.';
		}
		foreach ($instances as $instance) {
			$theirs = array_merge((array)($instance['ipv4_public'] ?? array()), array((string)($instance['ipv6'] ?? '')));
			foreach ($theirs as $a) {
				if ((string)$a !== '' && IpAddress::in((string)$a, $mine)) {
					return 'Instance ' . ($instance['id'] ?? '?') . ' (' . ($instance['label'] ?? '?') . ') at ' . $account
						. ' still has this machine\'s address ' . $a . '. Delete it at the provider first; the platform never deletes a machine itself.';
				}
			}
		}
		return '';
	}

	/**
	 * Guard 1: '' when the node's backup storage holds nothing. Fails safe: a
	 * space that cannot be listed refuses.
	 */
	public static function backups_refusal(ManagedNode $node): string {
		try {
			$bk = StorageSpace::owner_object_count(StorageSpace::OWNER_NODE, (int)$node->key);
		} catch (Throwable $e) {
			return 'Could not check for its backups (' . $e->getMessage() . '). Resolve that before removing it.';
		}
		if ($bk['count'] > 0) {
			return 'This site still has ' . $bk['count'] . ' offsite backup' . ($bk['count'] === 1 ? '' : 's')
				. '. Delete them from the backup target\'s Stored Backups panel before removing it.';
		}
		if (!empty($bk['unchecked'])) {
			return 'Its backups could not be checked on: ' . implode(', ', $bk['unchecked'])
				. '. Resolve those targets before removing it.';
		}
		return '';
	}

	/**
	 * Every guard, in order: '' when the node may go. $typed_attest is what
	 * the person typed when the machine guard is an attestation.
	 *
	 * @param object|null   $driver       the provider for the plane's account (tests pass their own)
	 * @param string|null   $plane_token  the operator token, read from settings when null
	 */
	public static function refusal(ManagedNode $node, string $typed_attest = '', $driver = null,
			?callable $resolve = null, ?string $plane_token = null): string {
		$why = self::backups_refusal($node);
		if ($why !== '') {
			return $why;
		}
		$plane_token = $plane_token ?? ProvisionCustomerCloud::operator_compute_token();
		switch (self::machine_kind($node, $plane_token)) {
			case self::MACHINE_CONTAINER:
				return self::container_refusal($node);
			case self::MACHINE_PROVIDER:
				return self::provider_refusal($node, $driver ?? new LinodeComputeDriver($plane_token), $resolve);
			case self::MACHINE_ATTEST:
				$account = CloudAccounts::hosted_at_label(CloudAccounts::hosted_at($node));
				if (self::attest_text($node, $resolve) === '') {
					return 'No instance or address is on record for this machine, so there is nothing to confirm it by. '
						. 'Set its Host to the machine\'s IP address in Connection Settings.';
				}
				if (!self::attest_matches($node, $typed_attest, $resolve)) {
					return 'Type the machine\'s ' . (self::instance_id($node) !== '' ? 'instance ID' : 'IP address')
						. ' to confirm you deleted it at ' . $account . '. Nothing was removed.';
				}
				return '';
		}
		return '';
	}

	/**
	 * Remove the node for good once every guard passes. Throws
	 * DisplayableUserException with the refusal otherwise.
	 *
	 * @return string[] what releasing a live node's site records did
	 */
	public static function remove(ManagedNode $node, string $typed_attest, int $user_id, $driver = null,
			?callable $resolve = null, ?string $plane_token = null): array {
		$plane_token = $plane_token ?? ProvisionCustomerCloud::operator_compute_token();
		$why = self::refusal($node, $typed_attest, $driver, $resolve, $plane_token);
		if ($why !== '') {
			throw new DisplayableUserException($why);
		}
		if (self::machine_kind($node, $plane_token) === self::MACHINE_ATTEST) {
			error_log('[NODE_REMOVE_ATTEST] user ' . $user_id . ' attested the machine of node "' . $node->get('mgn_name')
				. '" (' . $node->get('mgn_slug') . ', #' . (int)$node->key . ') deleted at '
				. CloudAccounts::hosted_at_label(CloudAccounts::hosted_at($node)) . '; typed "' . trim($typed_attest) . '"');
		}
		return $node->remove_permanently();
	}
}
