<?php
/**
 * JoinedServers: a server its owner already runs, added to this management
 * node from their own account (walkthrough Q36).
 *
 * The join itself is the ordinary one: the owner enters this management
 * node's address on their site's Management Node page, and the site's agent
 * files a join request carrying its key. A join can only start from inside
 * the machine's own admin console, so nobody adds a server they do not run.
 * What this adds is whose it is, with nothing pasted: the site's page sends
 * the owner's browser here with the request's fingerprint, they sign in, and
 * the request is claimed by that account (claim()).
 *
 *   An account that pays us (pays_us()) has its claimed request approved at
 *   once. Any other waits on the dashboard for a person, shown with the
 *   account that claimed it.
 *
 * The node an approved claim becomes carries its owner
 * (mgn_owner_usr_user_id, set in AgentChannelEndpoint::approveJoin) and is
 * listed on that account's Your Sites, where the owner can stop our
 * management of it again (let_go()).
 *
 * @version 1.0
 */
class JoinedServers {

	/** Servers one account may have added at a time. */
	const LIMIT = 5;

	const STATE_APPROVED = 'approved';
	const STATE_WAITING = 'waiting';
	const STATE_NOT_FOUND = 'not_found';
	const STATE_TAKEN = 'taken';
	const STATE_LIMIT = 'limit';

	/**
	 * Does this account pay us: a subscription of theirs is current at this
	 * store, or they hold a business licence.
	 */
	public static function pays_us(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		if (class_exists('BusinessLicense') && BusinessLicense::held_by($user_id) !== array()) {
			return true;
		}
		if (!class_exists('MultiOrderItem')) {
			return false;
		}
		foreach (new MultiOrderItem(array('user_id' => $user_id)) as $item) {
			if ($item->get('odi_is_subscription') && (float)$item->get('odi_price') > 0
					&& in_array((string)$item->get('odi_subscription_status'), array('active', 'trialing'), true)
					&& !$item->get('odi_subscription_cancelled_time')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The address the owner is sent to on their own site, with this
	 * management node's address filled in, or '' when what they typed is not
	 * a site address. Only the host is kept: the path is ours.
	 */
	public static function site_connect_url(string $typed): string {
		$typed = trim($typed);
		if ($typed === '') {
			return '';
		}
		$host = parse_url(strpos($typed, '://') === false ? 'https://' . $typed : $typed, PHP_URL_HOST);
		$host = strtolower(trim((string)$host));
		if ($host === '' || !preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
			return '';
		}
		$own = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
		if ($host === strtolower((string)parse_url($own, PHP_URL_HOST))) {
			return '';
		}
		return 'https://' . $host . '/admin/admin_management_node?connect_to=' . rawurlencode($own);
	}

	/** The pending request carrying this fingerprint, or null. */
	public static function request_for(string $fingerprint): ?AgentJoinRequest {
		$fingerprint = strtolower(preg_replace('/[^0-9a-f]/i', '', $fingerprint));
		if (strlen($fingerprint) !== 16) {
			return null;
		}
		foreach (new MultiAgentJoinRequest(array('status' => AgentJoinRequest::STATUS_PENDING,
				'ajr_fingerprint' => $fingerprint, 'deleted' => false)) as $request) {
			if (!$request->is_expired()) {
				return $request;
			}
		}
		return null;
	}

	/**
	 * The signed-in owner claims the request their site just filed.
	 *
	 * @return array{state: string, node: ?ManagedNode, name: string}
	 */
	public static function claim(string $fingerprint, User $user): array {
		$request = self::request_for($fingerprint);
		if ($request === null) {
			return array('state' => self::STATE_NOT_FOUND, 'node' => null, 'name' => '');
		}
		$name = (string)$request->get('ajr_claimed_name');
		$claimed_by = (int)$request->get('ajr_usr_user_id');
		if ($claimed_by > 0 && $claimed_by !== (int)$user->key) {
			return array('state' => self::STATE_TAKEN, 'node' => null, 'name' => $name);
		}
		if (count(self::for_user((int)$user->key)) >= self::LIMIT) {
			return array('state' => self::STATE_LIMIT, 'node' => null, 'name' => $name);
		}
		if ($claimed_by === 0) {
			$request->set('ajr_usr_user_id', (int)$user->key);
			$request->save();
		}
		if (!self::pays_us((int)$user->key)) {
			return array('state' => self::STATE_WAITING, 'node' => null, 'name' => $name);
		}
		// adoptJoin refuses a machine this plane provisioned (its join is
		// checked against the provision); such a request waits for a person.
		try {
			$adopted = AgentChannelEndpoint::adoptJoin($request);
		} catch (Throwable $e) {
			error_log('JoinedServers: the claimed join #' . (int)$request->key . ' was not approved on its own: ' . $e->getMessage());
			return array('state' => self::STATE_WAITING, 'node' => null, 'name' => $name);
		}
		$request->set('ajr_decided_by', 'owner');
		$request->save();
		return array('state' => self::STATE_APPROVED, 'node' => $adopted['node'], 'name' => $name);
	}

	/**
	 * The servers this account added and we manage.
	 *
	 * @return ManagedNode[]
	 */
	public static function for_user(int $user_id): array {
		if ($user_id <= 0) {
			return array();
		}
		$out = array();
		foreach (new MultiManagedNode(array('mgn_owner_usr_user_id' => $user_id, 'deleted' => false)) as $node) {
			$out[] = $node;
		}
		return $out;
	}

	/** Names of the requests this account claimed that still wait for a person. */
	public static function waiting_for_user(int $user_id): array {
		$out = array();
		if ($user_id <= 0) {
			return $out;
		}
		foreach (new MultiAgentJoinRequest(array('status' => AgentJoinRequest::STATUS_PENDING,
				'ajr_usr_user_id' => $user_id, 'deleted' => false)) as $request) {
			if (!$request->is_expired()) {
				$out[] = (string)$request->get('ajr_claimed_name');
			}
		}
		return $out;
	}

	/**
	 * Its owner stops our management of a server they added: the agent is
	 * forgotten, checks and backups stop, and it leaves their page. The
	 * server is theirs and is not touched.
	 */
	public static function let_go(ManagedNode $node, int $user_id): void {
		if (!$node->key || $node->get('mgn_delete_time') || (int)$node->get('mgn_owner_usr_user_id') !== $user_id || $user_id <= 0) {
			throw new RuntimeException('That server is not one you added.');
		}
		InstanceTransferFinish::let_go_node($node);
		$node->load();
		$node->set('mgn_owner_usr_user_id', null);
		$node->save();
	}
}
