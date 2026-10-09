<?php
/**
 * NodeBroker — a Managed node writes to the management node's backup storage
 * through signed links, never with a key (specs/storage_targets.md R4, WP5).
 *
 * When the node's agent claims a backup or re-upload job, the management
 * node opens a run in the node's active storage space and mints a token for
 * it (open()); the job carries the token, the run id and the broker's address
 * in the slot a bucket credential once filled. The node's run then calls the
 * broker (BrokerEndpoint) with that token:
 *
 *   begin    the run's space, base key and target, so the node knows where its
 *            chain is and which chain it may extend
 *   sign     a link for one write inside the run's base key — the broker's own
 *            rules: an open run, an active space, never a key a finished run
 *            completed (answered 'exists' instead), never a delete
 *   finish   the objects the run completed with their sizes and hashes; what
 *            else it signed is cancelled, and the run's ledger file is written
 *   abort    the run failed: everything it signed is cancelled
 *
 * The token is stored as its hash, lives as long as the job's claim budget
 * plus an hour, and names one run: it cannot open another, read anything, or
 * reach any other owner's space. An open run whose token has expired is
 * aborted by the fleet pass (abortExpired()).
 *
 * @version 1.2 - a finish asked again while the first is still working waits for it (ShelfBroker serialises a
 *                run's finish) and answers what it recorded
 * @version 1.1 - finish and abort are answered for a run already closed by them (a lost reply asked again):
 *                finish returns what was recorded, abort leaves a finished run alone; begin and sign stay
 *                open-only
 * @version 1.0
 */

class NodeBroker {

	/** The header a node's broker call carries its token in. */
	const TOKEN_HEADER = 'X-Joinery-Broker-Token';

	/** Margin on top of the job's claim budget before a run's token stops being accepted. */
	const TOKEN_MARGIN_SECONDS = 3600;

	/**
	 * Open a run for a node's job in $space, which must be the node's and
	 * active on an enabled target. Returns [ShelfRun, token]; the token is
	 * shown once, here.
	 */
	public static function open(ManagedNode $node, StorageSpace $space, string $kind, int $lifetime_seconds): array {
		if (!in_array($kind, array(ShelfRun::KIND_BACKUP, ShelfRun::KIND_UPLOAD), true)) {
			throw new ShelfBrokerException('There is no broker run of kind "' . $kind . '".');
		}
		if ($space->owner_kind() !== StorageSpace::OWNER_NODE || $space->owner_id() !== (int)$node->key) {
			throw new ShelfBrokerException('That backup storage is not node ' . $node->get('mgn_slug') . '\'s.');
		}
		self::writable($space);
		$token = bin2hex(random_bytes(32));
		$run = new ShelfRun(NULL);
		$run->set('svr_mgn_managed_node_id', (int)$node->key);
		$run->set('svr_sps_storage_space_id', (int)$space->key);
		$run->set('svr_kind', $kind);
		$run->set('svr_profile', BackupProfile::path_segment(BackupProfile::MANAGER));
		$run->set('svr_base_key', $space->base() . BackupProfile::path_segment(BackupProfile::MANAGER) . '/');
		$run->set('svr_state', ShelfRun::STATE_OPEN);
		$run->set('svr_token_hash', hash('sha256', $token));
		$run->set('svr_token_expires_time', gmdate('Y-m-d H:i:s', time() + max(60, $lifetime_seconds)));
		$run->save();
		return array($run, $token);
	}

	/**
	 * What a job carries in its credential slot: the broker's address, the
	 * run and its token, base64 of JSON — the shape the slot has always had,
	 * so the agent passes it through untouched.
	 */
	public static function slot_value(ShelfRun $run, string $token): string {
		return base64_encode(json_encode(array(
			'broker' => self::endpoint_url(),
			'run_id' => (int)$run->key,
			'token'  => $token,
		)));
	}

	/** The broker's address on this management node. */
	public static function endpoint_url(): string {
		$webdir = (string)Globalvars::get_instance()->get_setting('webDir');
		return 'https://' . preg_replace('#^https?://#', '', rtrim($webdir, '/')) . '/api/v1/broker';
	}

	/**
	 * The node run a token names, in one of $states, or null — one answer for
	 * every way a token can be wrong, so a holder learns nothing about which
	 * check failed. Open by default: only finish and abort are answered for a
	 * run that is already closed, so a reply lost on the way back is asked
	 * again and answered the same.
	 */
	public static function forToken(int $run_id, string $token, array $states = array(ShelfRun::STATE_OPEN)): ?ShelfRun {
		if ($run_id <= 0 || trim($token) === '') {
			return null;
		}
		$run = new ShelfRun($run_id, TRUE);
		if (!$run->key || !$run->isNodeRun() || $run->get('svr_delete_time')
				|| !in_array((string)$run->get('svr_state'), $states, true) || !$run->tokenMatches($token)) {
			return null;
		}
		return $run;
	}

	/** Where the run writes: its space, base key, bucket and target. */
	public static function begin(ShelfRun $run): array {
		$space = $run->space();
		$target = self::writable($space);
		return array(
			'run_id'      => (int)$run->key,
			'space_id'    => (int)$space->key,
			'base_key'    => (string)$run->get('svr_base_key'),
			'bucket'      => (string)$target->get('bkt_bucket'),
			'target_name' => (string)$target->get('bkt_name'),
			'kind'        => (string)$run->get('svr_kind'),
			'expires_at'  => (string)$run->get('svr_token_expires_time'),
		);
	}

	/** One write's link, or the 'exists' answer for a key already completed. */
	public static function sign(ShelfRun $run, string $name, string $operation, array $args = array()): array {
		self::writable($run->space());
		try {
			return ShelfBroker::signWrite($run, $name, $operation, $args);
		} catch (ShelfBrokerExistsException $e) {
			return $e->answer();
		}
	}

	/**
	 * The run is done: what it completed, with sizes and hashes, and the chain
	 * it extended. Asked again for a run already finished — its reply was lost
	 * on the way back — it answers what was recorded, and changes nothing.
	 */
	public static function finish(ShelfRun $run, array $completed, string $chain = ''): array {
		$state = (string)$run->get('svr_state');
		if ($state === ShelfRun::STATE_OPEN) {
			try {
				return ShelfBroker::completeRun($run, $completed, $chain);
			} catch (ShelfBrokerException $e) {
				// A finish that waited on another for this run finds it closed.
				$run = new ShelfRun((int)$run->key, TRUE);
				$state = (string)$run->get('svr_state');
				if ($state === ShelfRun::STATE_OPEN) {
					throw $e;
				}
			}
		}
		if ($state === ShelfRun::STATE_FINISHED) {
			$done = count(new MultiShelfObject(array('run_id' => (int)$run->key, 'completed' => true, 'deleted' => false)));
			return array('run_id' => (int)$run->key, 'completed' => $done, 'cancelled' => 0, 'unhashed' => 0, 'already' => true);
		}
		throw new ShelfBrokerException('This run was aborted, so it cannot be finished: ' . (string)$run->get('svr_cause'));
	}

	/**
	 * The run failed on the node: nothing it signed is kept. A run already
	 * closed is left as it is — an abort never undoes a finish.
	 */
	public static function abort(ShelfRun $run, string $cause): int {
		if ((string)$run->get('svr_state') !== ShelfRun::STATE_OPEN) {
			return 0;
		}
		$cause = trim($cause) !== '' ? mb_substr(trim($cause), 0, 1000) : 'The node reported the run failed.';
		return ShelfBroker::abortRun($run, $cause);
	}

	/** Abort every open node run whose token has expired. The number aborted. */
	public static function abortExpired(): int {
		$aborted = 0;
		$runs = new MultiShelfRun(array('state' => ShelfRun::STATE_OPEN, 'token_expired' => true, 'deleted' => false));
		foreach ($runs as $run) {
			if (!$run->isNodeRun()) {
				continue;
			}
			ShelfBroker::abortRun($run, 'The node never finished this run before its token expired; '
				. 'the management node cancelled what it had signed.');
			$aborted++;
		}
		return $aborted;
	}

	/** The target of a space that takes writes, or a refusal saying why it does not. */
	private static function writable(StorageSpace $space): BackupTarget {
		if (!$space->is_active()) {
			throw new ShelfBrokerException('These backups were moved to another target; nothing more is stored in '
				. $space->describe() . '. The next run goes to the new one.');
		}
		$target = $space->target();
		if ($target === null || !$target->get('bkt_enabled') || $target->get('bkt_delete_time')) {
			throw new ShelfBrokerException('The backup target holding ' . $space->describe()
				. ' is switched off or deleted, so it takes no backups.');
		}
		return $target;
	}
}
