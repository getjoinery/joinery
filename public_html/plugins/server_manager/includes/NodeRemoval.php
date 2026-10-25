<?php
/**
 * NodeRemoval — whether a node may be removed for good, and the removal
 * (spec node_hide_and_remove §4).
 *
 * Removing a node for good deletes every record it owns. What it must never do
 * is leave a machine of ours running with nothing watching it, so the guards
 * run in this order and each refusal says what to do:
 *
 *   1. No copy is under way: neither one of this site (being made, dormant,
 *      or in its switch-over) nor the one this node is the copy's row of.
 *      Removing either side leaves the other running alone, its server's
 *      provision still advancing. The copy is discarded, or its switch-over
 *      kept, on the site's Copy tab first.
 *   2. Its backup storage holds nothing: removing it would leave the backups
 *      unclaimed on the target.
 *   3. A site in a container on a server of ours: its host verified the
 *      container gone (Permanently Delete Site stamps mgn_site_removed_time),
 *      or no site was ever seen there. When its host has no node here (never
 *      paired, or removed for good, its machine with it), nothing can remove
 *      the container from here: the person types the host machine's address
 *      to say they deleted it, logged as [NODE_REMOVE_ATTEST].
 *   4. A machine of ours: the provider says the instance is gone, asked with
 *      the credential of the account the machine is in (its provision record
 *      names it). Where this management node does not hold that account (no
 *      record names one, or it was removed or its sign-in expired), the
 *      person types the instance ID, or the machine's address when no instance
 *      is recorded, to say they deleted it; that is logged as
 *      [NODE_REMOVE_ATTEST] before the delete, since the node's own record goes.
 *   5. A node not hosted by us has no machine guard: it keeps running, and is
 *      simply no longer managed.
 *
 * The platform never deletes an instance itself.
 *
 * @version 1.5 - a node with a copy under way, of it or as its copy's row, is refused until the copy is discarded or
 *               kept (copy_refusal, B116): the copy was left live with no source, its provision advancing every tick
 * @version 1.4 - the backups refusal names each target and folder holding them and the page where Delete all is, and
 *               says to hide the node first: Delete all is offered only once new backups stop going there
 * @version 1.3 - the provider is asked with the driver of the machine's own account's provider
 * @version 1.2 - a container site whose host has no node here (never paired, or removed for good) goes once the
 *                person confirms its host machine deleted by its address; it could only be hidden (B76)
 * @version 1.1 - the provider is asked with the machine's own account's credential, and only when that
 *                account is held (cloud_accounts_list)
 * @version 1.0
 */

class NodeRemoval {

	/** Not ours: nothing to wait for. */
	const MACHINE_NONE = 'none';
	/** A site in a container on a server of ours: its container must be gone. */
	const MACHINE_CONTAINER = 'container';
	/** A machine in an account of ours this management node holds: the provider is asked. */
	const MACHINE_PROVIDER = 'provider';
	/** A machine of ours in an account this management node cannot ask about: the person attests. */
	const MACHINE_ATTEST = 'attest';

	/**
	 * The credential of the account of ours the node's machine is in, or ''
	 * when no record names one or it is not held now (removed, expired).
	 */
	public static function account_token(ManagedNode $node): string {
		$account = HeldCloudAccounts::node_account($node);
		return ($account !== null && $account->usable()) ? $account->token() : '';
	}

	/** The account a node's machine is in, in words: the account's own name when a record names it. */
	public static function account_label(ManagedNode $node): string {
		$account = HeldCloudAccounts::node_account($node);
		return $account !== null ? $account->label() : CloudAccounts::hosted_at_label(CloudAccounts::hosted_at($node));
	}

	/**
	 * Which machine guard applies. $account_token is the credential of the
	 * machine's own account ('' when it is not held); looked up when null.
	 */
	public static function machine_kind(ManagedNode $node, ?string $account_token = null): string {
		$hosted_at = CloudAccounts::hosted_at($node);
		if (!in_array($hosted_at, array(CloudAccounts::MAIN, CloudAccounts::TEST), true)) {
			return self::MACHINE_NONE;
		}
		if (trim((string)$node->get('mgn_container_name')) !== '') {
			return self::MACHINE_CONTAINER;
		}
		$account_token = $account_token ?? self::account_token($node);
		return $account_token !== '' ? self::MACHINE_PROVIDER : self::MACHINE_ATTEST;
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
	public static function container_refusal(ManagedNode $node, ?callable $resolve = null): string {
		if ($node->get('mgn_site_removed_time') || !self::site_ever_confirmed($node)) {
			return '';
		}
		$on = 'Its site may still be running in its container on ' . self::account_label($node) . '. ';
		$server = self::host_server($node);
		if ($server !== null) {
			return $on . ($server->get('mgn_delete_time')
					? 'Its host\'s node (' . $server->get('mgn_name') . ') is hidden: restore it and use Permanently Delete Site; once its host verifies the container gone, this can go. '
					: 'Use Permanently Delete Site first; once its host verifies the container gone, this can go. ')
				. 'If its host machine is gone, remove the host\'s own node permanently first; this then asks you to confirm the machine deleted.';
		}
		return $on . 'Its host has no node here to remove the container (never paired, or removed), so this goes once you confirm '
			. 'you deleted the host machine, by its IP address.'
			. (self::host_addresses($node, $resolve) ? '' : ' No address of the host machine is on record to confirm it by: set the host\'s address on its host record.');
	}

	/** The host record a container site is placed on, or null. */
	private static function placement(ManagedNode $node): ?ManagedHost {
		$id = (int)$node->get('mgn_mgh_managed_host_id');
		if (!$id) {
			return null;
		}
		$host = new ManagedHost($id, TRUE);
		return ($host->key && !$host->get('mgh_delete_time')) ? $host : null;
	}

	/**
	 * The host's own node for a container site, hidden ones included, or null
	 * when its host has none here: never paired, or removed for good.
	 */
	public static function host_server(ManagedNode $node): ?ManagedNode {
		$host = self::placement($node);
		$id = $host ? (int)$host->get('mgh_mgn_managed_node_id') : 0;
		if (!$id || $id === (int)$node->key) {
			return null;
		}
		$server = new ManagedNode($id, TRUE);
		return $server->key ? $server : null;
	}

	/**
	 * The public addresses of the machine a container site ran on: its host
	 * record's address and the site's own records (its Host, provisioning
	 * records and the join its agent made, all from that machine).
	 *
	 * @return string[]
	 */
	public static function host_addresses(ManagedNode $node, ?callable $resolve = null): array {
		$resolve = $resolve ?? array('MachineTransferWatch', 'resolve');
		$host = self::placement($node);
		$candidates = array();
		$at = $host ? trim((string)$host->get('mgh_host')) : '';
		if ($at !== '') {
			$candidates = IpAddress::binary($at) !== null ? array($at) : (array)$resolve($at);
		}
		$out = array();
		foreach (array_merge($candidates, self::addresses($node, $resolve)) as $a) {
			$a = trim((string)$a);
			$b = IpAddress::binary($a);
			if ($b !== null && !isset($out[$b]) && IpAddress::isPublic($a)) {
				$out[$b] = $a;
			}
		}
		return array_values($out);
	}

	/**
	 * What the person types to remove a container site whose host has no node
	 * here: the host machine's first address. '' when guard 2 passes on its
	 * own, when the host has a node (it removes the container), or when no
	 * address is known.
	 */
	public static function host_attest_text(ManagedNode $node, ?callable $resolve = null): string {
		if (self::container_refusal($node, $resolve) === '' || self::host_server($node) !== null) {
			return '';
		}
		return self::host_addresses($node, $resolve)[0] ?? '';
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
		$account = self::account_label($node);
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
	/**
	 * The refusal for a node whose backups are still stored, from
	 * StorageSpace::owner_object_count(): where each lot is and the page whose
	 * Stored Backups panel deletes them. That panel offers Delete all only for
	 * a folder new backups no longer go to, and hiding the node is what stops
	 * them, so a node still shown is told to hide first.
	 */
	public static function backups_message(array $bk, bool $hidden): string {
		$where = array();
		foreach ($bk['where'] ?? array() as $w) {
			$where[] = $w['count'] . ' in folder ' . $w['folder'] . ' on "' . $w['target'] . '" (/admin/server_manager/targets?bkt_backup_target_id='
				. (int)$w['target_id'] . ', Stored Backups, Delete all for ' . $w['folder'] . ')';
		}
		$first = $hidden ? '' : 'Hide it from the dashboard first, which stops new backups going there; then delete them. ';
		return 'This site still has ' . $bk['count'] . ' offsite backup file' . ($bk['count'] === 1 ? '' : 's')
			. ($where ? ': ' . implode('; ', $where) : '') . '. ' . $first . 'Remove it once they are gone.';
	}

	/** The copy under way that keeps this node, or ''. */
	public static function copy_refusal(ManagedNode $node): string {
		$copy = SiteCopy::live_for_source((int)$node->key);
		if ($copy) {
			return 'This site has a copy under way (' . $copy->status_label() . '). Discard it, or keep its switch-over, '
				. 'on this site\'s Copy tab first; then remove it.';
		}
		$copy = SiteCopy::live_for_copy_node((int)$node->key);
		if ($copy) {
			$source = (int)$copy->get('scp_source_node_id') > 0 ? new ManagedNode((int)$copy->get('scp_source_node_id'), TRUE) : null;
			$name = $source && $source->key ? '\'' . $source->get('mgn_name') . '\'' : 'its site';
			return 'This is the copy of ' . $name . ', under way (' . $copy->status_label() . '). Discard it, or keep its '
				. 'switch-over, on ' . ($source && $source->key ? 'that site\'s' : 'the site\'s') . ' Copy tab first.';
		}
		return '';
	}

	public static function backups_refusal(ManagedNode $node): string {
		try {
			$bk = StorageSpace::owner_object_count(StorageSpace::OWNER_NODE, (int)$node->key);
		} catch (Throwable $e) {
			return 'Could not check for its backups (' . $e->getMessage() . '). Resolve that before removing it.';
		}
		if ($bk['count'] > 0) {
			return self::backups_message($bk, (bool)$node->get('mgn_delete_time'));
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
	 * @param object|null   $driver         the provider for the machine's account (tests pass their own)
	 * @param string|null   $account_token  the machine's own account's credential, looked up when null
	 */
	public static function refusal(ManagedNode $node, string $typed_attest = '', $driver = null,
			?callable $resolve = null, ?string $account_token = null): string {
		$why = self::copy_refusal($node);
		if ($why !== '') {
			return $why;
		}
		$why = self::backups_refusal($node);
		if ($why !== '') {
			return $why;
		}
		$account_token = $account_token ?? self::account_token($node);
		switch (self::machine_kind($node, $account_token)) {
			case self::MACHINE_CONTAINER:
				$why = self::container_refusal($node, $resolve);
				if ($why === '' || self::host_attest_text($node, $resolve) === '') {
					return $why;
				}
				$typed = trim($typed_attest);
				if ($typed === '' || IpAddress::binary($typed) === null || !IpAddress::in($typed, self::host_addresses($node, $resolve))) {
					return 'Type the IP address of its host machine to confirm you deleted it at ' . self::account_label($node)
						. '. Nothing was removed.';
				}
				return '';
			case self::MACHINE_PROVIDER:
				if ($driver === null) {
					$account = HeldCloudAccounts::node_account($node);
					$driver = OperatorCloudAccount::driver_for($account_token, $account ? $account->provider() : 'linode');
				}
				return self::provider_refusal($node, $driver, $resolve);
			case self::MACHINE_ATTEST:
				$account = self::account_label($node);
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
			?callable $resolve = null, ?string $account_token = null): array {
		$account_token = $account_token ?? self::account_token($node);
		$why = self::refusal($node, $typed_attest, $driver, $resolve, $account_token);
		if ($why !== '') {
			throw new DisplayableUserException($why);
		}
		$kind = self::machine_kind($node, $account_token);
		if ($kind === self::MACHINE_CONTAINER && self::host_attest_text($node, $resolve) !== '') {
			error_log('[NODE_REMOVE_ATTEST] user ' . $user_id . ' attested the host machine of container site "' . $node->get('mgn_name')
				. '" (' . $node->get('mgn_slug') . ', #' . (int)$node->key . ') deleted at '
				. self::account_label($node) . '; typed "' . trim($typed_attest) . '"');
		}
		if ($kind === self::MACHINE_ATTEST) {
			error_log('[NODE_REMOVE_ATTEST] user ' . $user_id . ' attested the machine of node "' . $node->get('mgn_name')
				. '" (' . $node->get('mgn_slug') . ', #' . (int)$node->key . ') deleted at '
				. self::account_label($node) . '; typed "' . trim($typed_attest) . '"');
		}
		return $node->remove_permanently();
	}
}
