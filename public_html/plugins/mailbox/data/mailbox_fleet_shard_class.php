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
 * @version 1.1 - mfs_provisioned_version (the operator's view of shard code age)
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxFleetShardException extends SystemBaseException {}

class MailboxFleetShard extends SystemBase {
	public static $prefix = 'mfs';
	public static $tablename = 'mfs_mailbox_fleet_shards';
	public static $pkey_column = 'mfs_mailbox_fleet_shard_id';

	protected static $foreign_key_actions = [
		'mfs_mgn_managed_node_id' => ['action' => 'null'],
	];

	// Slots reference the shard; deleting a shard with live slots is refused at
	// the logic layer (tenants must be migrated first), so no cascade here.

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
		'mfs_wg_endpoint'            => array('type'=>'varchar(255)'),
		'mfs_wg_public_key'          => array('type'=>'varchar(255)'),
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
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_slot_class.php'));
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT COUNT(*) FROM mft_mailbox_fleet_slots
			  WHERE mft_mfs_shard_id = ? AND mft_delete_time IS NULL
			    AND mft_status NOT IN ('evicted', 'released')"
		);
		$stmt->execute(array($this->key));
		return intval($stmt->fetchColumn());
	}

	public function hasCapacity(): bool {
		return $this->slotCount() < intval($this->get('mfs_capacity'));
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
