<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * SyncDevice — a computer the user has linked to their Drive (sde_sync_devices).
 *
 * A session ApiKey on its own says "some client holds a credential"; it has a
 * label and nothing else. That is not enough to run a sync fleet: the user
 * needs to see which machines are linked, when each last checked in, and how
 * far behind it is, and needs one button that cuts a lost laptop off. This row
 * is that identity, created by the device-link ceremony and paired 1:1 with the
 * key the device authenticates with.
 *
 * It also holds the device's own public key, which is what makes encrypted
 * folders syncable: the browser seals the vault secret key to it at approval
 * time, and only that device can open the result.
 *
 * Soft delete means unlinked. Revoking a device also revokes its key — see
 * drive_device_revoke.
 *
 * @version 1.0.0
 */
class SyncDevice extends SystemBase {
	public static $prefix = 'sde';
	public static $tablename = 'sde_sync_devices';
	public static $pkey_column = 'sde_sync_device_id';

	// The device is meaningless without its owner or its credential; either one
	// going away permanently takes the device row with it.
	protected static $foreign_key_actions = array(
		'sde_usr_user_id'   => array('action' => 'permanent_delete'),
		'sde_apk_api_key_id' => array('action' => 'permanent_delete'),
	);

	const PLATFORM_MACOS   = 'macos';
	const PLATFORM_WINDOWS = 'windows';
	const PLATFORM_LINUX   = 'linux';

	public static $field_specifications = array(
		'sde_sync_device_id'  => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'sde_usr_user_id'     => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'index' => true),
		'sde_apk_api_key_id'  => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true),
		'sde_device_name'     => array('type' => 'varchar(64)', 'is_nullable' => false, 'required' => true),
		'sde_platform'        => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true),
		'sde_client_version'  => array('type' => 'varchar(32)', 'is_nullable' => true),
		// The device's X25519 public key (standard base64 of the raw 32 bytes).
		// The vault handoff target: the browser seals the drive vault secret key
		// to this during approval, and the device opens it with the private half
		// it never sent anywhere.
		'sde_device_pubkey'   => array('type' => 'text', 'is_nullable' => true),
		'sde_last_seen_time'  => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'sde_last_cursor'     => array('type' => 'int8', 'is_nullable' => true),
		'sde_create_time'     => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'sde_delete_time'     => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	/** The platforms a client may register as. */
	public static function platforms() {
		return array(self::PLATFORM_MACOS, self::PLATFORM_WINDOWS, self::PLATFORM_LINUX);
	}

	/** The live device that authenticates with this api key, or null. */
	public static function for_api_key($api_key_id) {
		$api_key_id = (int)$api_key_id;
		if ($api_key_id <= 0) {
			return null;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT sde_sync_device_id FROM sde_sync_devices
			  WHERE sde_apk_api_key_id = ? AND sde_delete_time IS NULL LIMIT 1");
		$q->execute(array($api_key_id));
		$id = $q->fetchColumn();
		return ($id === false) ? null : new self((int)$id, true);
	}

	/**
	 * Record that this device checked in, and how far through the change feed it
	 * has acknowledged.
	 *
	 * Throttled to once an hour like apk_last_used_time: a sync client polls
	 * constantly, and "synced 4 minutes ago" does not need minute precision at
	 * the cost of a write per poll. The cursor is written whenever it moves
	 * forward, because that one is the number the user actually reads when they
	 * are wondering why a device looks stuck.
	 */
	public function touch_seen($cursor = null) {
		$now = gmdate('Y-m-d H:i:s');
		$last = $this->get('sde_last_seen_time');
		$stale = ($last === null || $last === '' || $last < gmdate('Y-m-d H:i:s', time() - 3600));
		$cursor_moved = ($cursor !== null && (int)$cursor > (int)$this->get('sde_last_cursor'));

		if (!$stale && !$cursor_moved) {
			return;
		}
		// Written straight through rather than via save(): this fires on a read
		// action, and a check-in must never be able to fail the request it rode
		// in on or rewrite any other field of the row.
		$dblink = DbConnector::get_instance()->get_db_link();
		try {
			if ($cursor_moved) {
				$q = $dblink->prepare(
					"UPDATE sde_sync_devices SET sde_last_seen_time = ?, sde_last_cursor = ?
					  WHERE sde_sync_device_id = ?");
				$q->execute(array($now, (int)$cursor, (int)$this->key));
				$this->set('sde_last_cursor', (int)$cursor, false);
			} else {
				$q = $dblink->prepare(
					"UPDATE sde_sync_devices SET sde_last_seen_time = ? WHERE sde_sync_device_id = ?");
				$q->execute(array($now, (int)$this->key));
			}
			$this->set('sde_last_seen_time', $now, false);
		} catch (Exception $e) {
			error_log('SyncDevice::touch_seen failed for sde=' . $this->key . ': ' . $e->getMessage());
		}
	}

	/** The user-facing view of this device. */
	public function export() {
		return array(
			'id'             => (int)$this->key,
			'device_name'    => $this->get('sde_device_name'),
			'platform'       => $this->get('sde_platform'),
			'client_version' => $this->get('sde_client_version'),
			'last_seen_time' => $this->get('sde_last_seen_time'),
			'last_cursor'    => $this->get('sde_last_cursor') !== null ? (int)$this->get('sde_last_cursor') : null,
			'linked_time'    => $this->get('sde_create_time'),
			'has_vault_key'  => ($this->get('sde_device_pubkey') !== null && $this->get('sde_device_pubkey') !== ''),
		);
	}
}

class MultiSyncDevice extends SystemMultiBase {
	protected static $model_class = 'SyncDevice';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['sde_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['api_key_id'])) {
			$filters['sde_apk_api_key_id'] = array($this->options['api_key_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['platform'])) {
			$filters['sde_platform'] = array($this->options['platform'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('sde_sync_devices', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
