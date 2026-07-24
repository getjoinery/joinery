<?php
/**
 * BackupKeyEscrow — a sealed copy of a node's backup encryption key (or the
 * agent signing key), recoverable only with the offline recovery private key.
 *
 * Each row holds a `sodium_crypto_box_seal` of the raw key string, sealed to the
 * recovery PUBLIC key in the server_manager_escrow_public_key setting. The
 * control plane can create these blobs but can never open them — only the
 * private key, kept in the operator's password manager, can (see
 * maintenance_scripts/sysadmin_tools/escrow_keypair.php). A stolen bucket or a
 * dumped control-plane database therefore yields only sealed blobs.
 *
 * APPEND-ONLY. There is no update or delete surface anywhere: key rotation adds
 * a new row so archives encrypted under a previous key stay recoverable. The
 * rows also survive a node's soft-delete (they are the recovery record for that
 * node's archives still in the bucket); a hard node delete nulls the node
 * pointer via the FK but keeps the row.
 *
 * bke_kind:
 *   backup         — a node's ~/.joinery_backup_key (bke_mgn_node_id set)
 *   agent_signing  — the platform agent signing secret (bke_mgn_node_id NULL)
 * bke_source: generated (minted for the node) | migrated (escrowed an existing
 *   node key) | rotated (replacement key).
 *
 * @version 1.2 - matching_for_node: "is this key escrowed" searches every row, not only the newest
 * @version 1.1 - $test_fixture so the generic ModelTester satisfies the escrow validation
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class BackupKeyEscrowException extends SystemBaseException {}

class BackupKeyEscrow extends SystemBase {
	public static $prefix = 'bke';
	public static $tablename = 'bke_backup_key_escrow';
	public static $pkey_column = 'bke_escrow_id';

	const KINDS   = ['backup', 'agent_signing'];
	const SOURCES = ['generated', 'migrated', 'rotated'];

	// save() rejects a fingerprint that is not 64-hex and an empty sealed blob,
	// so the generic ModelTester data ('a' / 'test text') can't be used. Use the
	// agent_signing kind, whose node pointer is legitimately NULL — that keeps the
	// base row valid and lets the generic null-acceptance check for
	// bke_mgn_node_id pass (a backup-kind row requires a node). The backup kind is
	// covered by backup_key_escrow_test.
	public static $test_fixture = array(
		'values'       => array(
			'bke_key_fingerprint' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
			'bke_sealed_blob'     => 'c2VhbGVkLXRlc3QtYmxvYg==',
			'bke_kind'            => 'agent_signing',
		),
		'update_field' => 'bke_sealed_blob',
	);

	public static $field_specifications = array(
		'bke_escrow_id'      => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// Nullable: agent_signing rows have no node, and a hard node delete nulls
		// this pointer while keeping the recovery record.
		'bke_mgn_node_id'    => array('type'=>'int8',
			'foreign_key'=>array('table'=>'mgn_managed_nodes', 'column'=>'mgn_id', 'on_delete'=>'SET NULL')),
		'bke_key_fingerprint'=> array('type'=>'varchar(64)', 'is_nullable'=>false),
		'bke_sealed_blob'    => array('type'=>'text', 'is_nullable'=>false),
		'bke_kind'           => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'backup',
			'allowed_values'=>array('backup', 'agent_signing')),
		'bke_source'         => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'generated',
			'allowed_values'=>array('generated', 'migrated', 'rotated')),
		'bke_create_time'    => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function prepare() {
		$this->validate_escrow();
	}

	// prepare() is not guaranteed to run before save(), so the invariants live
	// here too (house rule).
	function save($debug = false) {
		$this->validate_escrow();
		return parent::save($debug);
	}

	private function validate_escrow() {
		$fpr = (string)$this->get('bke_key_fingerprint');
		if (!preg_match('/^[0-9a-f]{64}$/', $fpr)) {
			throw new BackupKeyEscrowException('Escrow requires a 64-hex sha256 key fingerprint.');
		}
		if (trim((string)$this->get('bke_sealed_blob')) === '') {
			throw new BackupKeyEscrowException('Escrow requires a non-empty sealed blob.');
		}
		$kind = (string)($this->get('bke_kind') ?: 'backup');
		if (!in_array($kind, self::KINDS, true)) {
			throw new BackupKeyEscrowException('Invalid escrow kind: ' . $kind);
		}
		$source = (string)($this->get('bke_source') ?: 'generated');
		if (!in_array($source, self::SOURCES, true)) {
			throw new BackupKeyEscrowException('Invalid escrow source: ' . $source);
		}
		$node_id = $this->get('bke_mgn_node_id');
		if ($kind === 'backup' && empty($node_id)) {
			throw new BackupKeyEscrowException('A backup-key escrow row must reference a node.');
		}
		if ($kind === 'agent_signing' && !empty($node_id)) {
			throw new BackupKeyEscrowException('An agent-signing escrow row must not reference a node.');
		}
	}
}

class MultiBackupKeyEscrow extends SystemMultiBase {
	protected static $model_class = 'BackupKeyEscrow';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['node_id'])) {
			$filters['bke_mgn_node_id'] = [$this->options['node_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['kind'])) {
			$filters['bke_kind'] = [$this->options['kind'], PDO::PARAM_STR];
		}
		if (isset($this->options['source'])) {
			$filters['bke_source'] = [$this->options['source'], PDO::PARAM_STR];
		}
		if (isset($this->options['fingerprint'])) {
			$filters['bke_key_fingerprint'] = [$this->options['fingerprint'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('bke_backup_key_escrow', $filters, $this->order_by, $only_count, $debug);
	}

	/**
	 * Newest escrow row for a node (or agent-signing when $node_id is null), or
	 * null if none. Used to compare a node's current key fingerprint and to find
	 * the blob to replicate/recover.
	 */
	/**
	 * Newest escrow row holding a SPECIFIC key fingerprint for a node, or null.
	 * "Is this key escrowed?" must search every row, not just the newest — a
	 * node legitimately runs an older escrowed key after a restore, and that
	 * key is still recoverable.
	 */
	public static function matching_for_node($node_id, $fingerprint, $kind = 'backup') {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare("SELECT bke_escrow_id FROM bke_backup_key_escrow
			WHERE bke_mgn_node_id = ? AND bke_kind = ? AND bke_key_fingerprint = ?
			ORDER BY bke_escrow_id DESC LIMIT 1");
		$stmt->execute([$node_id, $kind, $fingerprint]);
		$id = $stmt->fetchColumn();
		return $id ? new BackupKeyEscrow(intval($id), true) : null;
	}

	public static function newest_for_node($node_id, $kind = 'backup') {
		$db = DbConnector::get_instance()->get_db_link();
		if ($node_id === null) {
			$stmt = $db->prepare("SELECT bke_escrow_id FROM bke_backup_key_escrow
				WHERE bke_mgn_node_id IS NULL AND bke_kind = ?
				ORDER BY bke_escrow_id DESC LIMIT 1");
			$stmt->execute([$kind]);
		} else {
			$stmt = $db->prepare("SELECT bke_escrow_id FROM bke_backup_key_escrow
				WHERE bke_mgn_node_id = ? AND bke_kind = ?
				ORDER BY bke_escrow_id DESC LIMIT 1");
			$stmt->execute([$node_id, $kind]);
		}
		$id = $stmt->fetchColumn();
		return $id ? new BackupKeyEscrow(intval($id), true) : null;
	}
}
?>
