<?php
/**
 * MailboxFleetShard - one relay box in the OPERATOR's shared relay fleet.
 *
 * (specs/mailbox_relay_shared_fleet.md § Fleet operations). A shard runs
 * exactly the self-hosted relay stack — the shard rows exist only on the
 * operator's own deployment, where the fleet service API assigns tenants to
 * shards and dispatches server_manager jobs against the shard's managed node.
 * server_manager itself never knows what a tenant is; this table is the
 * mailbox plugin's brain-side bookkeeping.
 *
 * Capacity is the blast-radius dial: a compromise is bounded to a shard's
 * tenant list, so shard size is policy, not architecture.
 *
 * @version 1.2 - retire(): the shard and its managed node leave together
 * @version 1.1 - mfs_provisioned_version (the operator's view of shard code age)
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxFleetShardException extends SystemBaseException {}

class MailboxFleetShard extends SystemBase {
	public static $prefix = 'mfs';
	public static $tablename = 'mfs_mailbox_fleet_shards';
	public static $pkey_column = 'mfs_mailbox_fleet_shard_id';

	protected static $foreign_key_actions = [
		// server_manager's table, named: a site without that plugin has no
		// model to find it by.
		'mfs_mgn_managed_node_id' => ['action' => 'null', 'source_table' => 'mgn_managed_nodes'],
	];

	// Slots reference the shard; retire() refuses a shard with live slots
	// (tenants must be migrated first), so no cascade here.

	public static $field_specifications = array(
		'mfs_mailbox_fleet_shard_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mfs_name'                   => array('type'=>'varchar(100)'),
		// The server_manager node the fleet jobs run against.
		'mfs_mgn_managed_node_id'    => array('type'=>'int8'),
		// The shard's mail hostname (its PTR / HELO identity) and public MX IP.
		// Tenant MX hostnames are operator-controlled A records resolving here.
		'mfs_hostname'               => array('type'=>'varchar(255)'),
		'mfs_public_ip'              => array('type'=>'varchar(64)'),
		// WireGuard listener tenants dial out to.
		// The identity pin tenants connect with (specs/relay_without_a_shell.md),
		// and the provider coordinates of the instance the operator's plane
		// created and can re-image: a shard is born from user-data like any relay.
		'mfs_identity_fingerprint'   => array('type'=>'varchar(64)'),
		'mfs_cloud_provider'         => array('type'=>'varchar(32)'),
		'mfs_cloud_instance_id'      => array('type'=>'varchar(50)'),
		'mfs_region'                 => array('type'=>'varchar(50)'),
		// How many tenants this shard accepts (the blast-radius dial).
		'mfs_capacity'               => array('type'=>'int4', 'is_nullable'=>false, 'default'=>25),
		// Inactive shards accept no new enrollments (draining before rebuild or
		// retirement); existing tenants keep running.
		'mfs_is_active'              => array('type'=>'bool', 'is_nullable'=>false, 'default'=>true),
		// The relay code version this shard last reported, stamped from a job's
		// RELAY_VERSION= marker. The operator is not a tenant of their own shards,
		// so there is no joinery-ping credential to ask with — the version arrives
		// through root SSH on the managed node instead. Empty reads as UNKNOWN, and
		// unknown must never render as up to date.
		'mfs_provisioned_version'    => array('type'=>'varchar(20)'),
		'mfs_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mfs_update_time'            => array('type'=>'timestamp(6)'),
		'mfs_delete_time'            => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		$this->set('mfs_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** Active (non-evicted, non-deleted) slot count on this shard. */
	public function slotCount(): int {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_slots_class.php'));
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT COUNT(*) FROM mft_mailbox_fleet_slots
			  WHERE mft_mfs_mailbox_fleet_shard_id = ? AND mft_delete_time IS NULL
			    AND mft_status NOT IN ('evicted', 'released')"
		);
		$stmt->execute(array($this->key));
		return intval($stmt->fetchColumn());
	}

	/**
	 * Retire this shard: its managed node is hidden from the dashboard (so
	 * nothing goes on probing or reporting on a server that is going away)
	 * and the shard row is soft-deleted, together. The server itself is the
	 * operator's to destroy; the node is hidden, not removed, so it can be
	 * restored from the dashboard.
	 *
	 * Refused while a tenant still holds a slot here (tenants move to another
	 * shard first) or while the shard's birth run is still in flight.
	 * Re-runnable: a node already hidden is left as it is.
	 *
	 * @return string[] what retiring it also did, in plain words
	 * @throws MailboxFleetShardException when it may not be retired
	 */
	public function retire(): array {
		if (!$this->key || $this->get('mfs_delete_time')) {
			throw new MailboxFleetShardException('This shard is already retired.');
		}
		$slots = $this->slotCount();
		if ($slots > 0) {
			throw new MailboxFleetShardException($slots . ' tenant' . ($slots === 1 ? ' still has a slot' : 's still have slots')
				. ' on this shard. Move them to another shard before retiring it.');
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));
		$runs = new MultiRelayCloudProvision(array('live' => true, 'deleted' => false,
			'rcl_mfs_mailbox_fleet_shard_id' => intval($this->key)));
		if ($runs->count() > 0) {
			throw new MailboxFleetShardException('This shard is still being born. Let the run finish, or cancel it in the Relay section, before retiring it.');
		}

		$notes = array();
		$node_id = intval($this->get('mfs_mgn_managed_node_id'));
		if ($node_id > 0 && class_exists('ManagedNode')) {
			$node = new ManagedNode($node_id, TRUE);
			if ($node->key && !$node->get('mgn_delete_time')) {
				$node->hide();
				$notes[] = 'Its node "' . $node->get('mgn_name') . '" was hidden from the dashboard, so it is no longer monitored.';
				$notes = array_merge($notes, $node->removal_notes());
			}
		}
		$this->soft_delete();
		$notes[] = 'Its server was not touched: destroy it in the cloud account, and remove its DNS records, when you are done with it.';
		return $notes;
	}
}

class MultiMailboxFleetShard extends SystemMultiBase {
	protected static $model_class = 'MailboxFleetShard';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['active'])) {
			$filters['mfs_is_active'] = $this->options['active'] ? "= true" : "= false";
		}

		if (isset($this->options['node_id'])) {
			$filters['mfs_mgn_managed_node_id'] = [intval($this->options['node_id']), PDO::PARAM_INT];
		}


		return $this->_get_resultsv2('mfs_mailbox_fleet_shards', $filters, $this->order_by, $only_count, $debug);
	}
}
