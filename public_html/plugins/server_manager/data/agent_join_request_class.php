<?php
/**
 * AgentJoinRequest - a node's request to join this management node.
 *
 * Enrollment is node-initiated and shares no secret (spec A6 / Phase 1.5): the
 * node's root agent generates its Ed25519 keypair and sends only the public
 * half plus a claimed name. The row holds that request while a human decides.
 * Approval is what binds the public key to a managed-node record — nothing in
 * this table grants anything by itself.
 *
 * Everything in a pending row is attacker-supplied (anyone who can reach the
 * join endpoint can claim any name), which is why the approve UI leads with the
 * key fingerprint and tells the operator to compare it against what the node's
 * own panel shows.
 *
 * @version 1.1 - ajr_mgn_node_id deletion action declared: a deleted node clears the pointer and
 *                keeps the introduction record; undeclared it registered as prevent
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class AgentJoinRequest extends SystemBase {
	public static $prefix = 'ajr';
	public static $tablename = 'ajr_agent_join_requests';
	public static $pkey_column = 'ajr_id';

	/** A pending request older than this is expired, not approvable. */
	const TTL_SECONDS = 3600;

	/** Ceiling on simultaneously pending rows — bounds what an unauthenticated endpoint can accumulate. */
	const MAX_PENDING = 20;

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	/**
	 * Deleting the node an approved request was bound to clears the pointer and
	 * keeps the row. The request is the record of an introduction that happened
	 * — who asked, from where, with which key, and that a person approved it —
	 * and that record outliving the node is the point of keeping it. Undeclared,
	 * this registered as 'prevent' and a node with a join request in its history
	 * could not be deleted at all.
	 */
	public static $foreign_key_actions = array(
		'ajr_mgn_node_id' => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'ajr_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ajr_claimed_name'  => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'ajr_public_key'    => array('type'=>'varchar(64)', 'is_nullable'=>false, 'unique'=>true),
		'ajr_fingerprint'   => array('type'=>'varchar(16)', 'is_nullable'=>false),
		'ajr_source_ip'     => array('type'=>'varchar(64)'),
		'ajr_agent_version' => array('type'=>'varchar(20)'),
		'ajr_status'        => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'pending', 'allowed_values'=>array('pending', 'approved', 'rejected')),
		'ajr_mgn_node_id'   => array('type'=>'int8'),
		'ajr_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ajr_update_time'   => array('type'=>'timestamp(6)'),
		'ajr_delete_time'   => array('type'=>'timestamp(6)'),
	);

	/**
	 * The short fingerprint both panels display: first 16 hex characters of
	 * SHA-256 over the RAW public key bytes. The agent computes the identical
	 * value; the contract is pinned in agent_channel_test.php and the agent's
	 * join tests, so a drift on either side fails a suite before it strands a
	 * fleet at mismatched fingerprints.
	 */
	public static function fingerprint(string $raw_public_key): string {
		return substr(hash('sha256', $raw_public_key), 0, 16);
	}

	/** Fingerprint grouped for eyes: 'ab12 cd34 ef56 7890'. */
	public static function display_fingerprint(string $fingerprint): string {
		return trim(chunk_split($fingerprint, 4, ' '));
	}

	public function is_expired(): bool {
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::TTL_SECONDS);
		return (string)$this->get('ajr_create_time') < $cutoff;
	}

	/** Live pending requests: pending, not expired, not deleted. Newest first. */
	public static function pending(): array {
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::TTL_SECONDS);
		$rows = new MultiAgentJoinRequest(
			['status' => self::STATUS_PENDING, 'created_after' => $cutoff, 'deleted' => FALSE],
			['ajr_create_time' => 'DESC']
		);
		$out = [];
		foreach ($rows as $row) {
			$out[] = $row;
		}
		return $out;
	}

	/** Count of live pending requests, for the intake ceiling. */
	public static function pending_count(): int {
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::TTL_SECONDS);
		$rows = new MultiAgentJoinRequest(
			['status' => self::STATUS_PENDING, 'created_after' => $cutoff, 'deleted' => FALSE]
		);
		return count($rows);
	}

	/** The row holding this public key, expired or not, or null. */
	public static function find_by_public_key(string $public_key_b64) {
		$rows = new MultiAgentJoinRequest(['public_key' => $public_key_b64, 'deleted' => FALSE], [], 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}
}

class MultiAgentJoinRequest extends SystemMultiBase {
	protected static $model_class = 'AgentJoinRequest';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['status'])) {
			$filters['ajr_status'] = [$this->options['status'], PDO::PARAM_STR];
		}
		if (isset($this->options['public_key'])) {
			$filters['ajr_public_key'] = [$this->options['public_key'], PDO::PARAM_STR];
		}
		if (isset($this->options['created_after'])) {
			$filters['ajr_create_time'] = '>= ' . DbConnector::get_instance()->get_db_link()
				->quote($this->options['created_after']);
		}

		return $this->_get_resultsv2('ajr_agent_join_requests', $filters, $this->order_by, $only_count, $debug);
	}
}
