<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * RecoveryVerification — the verification ledger behind the Recovery Readiness
 * page (rcv_recovery_verifications). One row per verification attempt of a
 * must-save item (recovery key ceremony, vault-code dry run, console-access
 * attestation).
 *
 * The ledger stores pass/fail and when — NEVER the secret, and never anything
 * derived from it. Staleness ("last proven 200 days ago") is computed from the
 * newest passed row per item, per user for per-user items.
 *
 * Rows are append-only history: verifying again inserts, nothing updates.
 *
 * @version 1.0.0
 */
class RecoveryVerification extends SystemBase {
	public static $prefix = 'rcv';
	public static $tablename = 'rcv_recovery_verifications';
	public static $pkey_column = 'rcv_recovery_verification_id';

	const METHOD_CEREMONY = 'ceremony';
	const METHOD_DRY_RUN  = 'dry_run';
	const METHOD_ATTESTED = 'attested';

	public static $api_readable = false;
	public static $api_writable = false;

	protected static $foreign_key_actions = array(
		'rcv_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'rcv_recovery_verification_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'rcv_item_key'    => array('type' => 'varchar(100)', 'is_nullable' => false, 'required' => true),
		'rcv_usr_user_id' => array('type' => 'int4', 'is_nullable' => false, 'required' => true),
		'rcv_method'      => array('type' => 'varchar(20)', 'is_nullable' => false, 'required' => true),
		'rcv_passed'      => array('type' => 'boolean', 'is_nullable' => false, 'default' => false),
		'rcv_verify_time' => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	public function prepare() {
		$method = (string)$this->get('rcv_method');
		if (!in_array($method, array(self::METHOD_CEREMONY, self::METHOD_DRY_RUN, self::METHOD_ATTESTED), true)) {
			throw new Exception('Unknown verification method: ' . $method);
		}
		return parent::prepare();
	}

	/** Append a ledger row. Returns the saved model. */
	public static function record($item_key, $method, $user_id, $passed) {
		$row = new self(NULL);
		$row->set('rcv_item_key', (string)$item_key);
		$row->set('rcv_method', (string)$method);
		$row->set('rcv_usr_user_id', (int)$user_id);
		$row->set('rcv_passed', (bool)$passed);
		$row->set('rcv_verify_time', gmdate('Y-m-d H:i:s'));
		$row->prepare();
		$row->save();
		return $row;
	}

	/**
	 * Newest PASSED verification time per item key, as [item_key => ISO time].
	 * $user_id scopes per-user items (vault codes belong to a person; the
	 * recovery-key ceremony proves a platform fact, but recording who verified
	 * costs nothing and the newest row wins either way, so the lookup is
	 * uniform: per-user when a user id is given for that key set).
	 */
	public static function latest_passed(array $item_keys, $user_id = null) {
		if (!count($item_keys)) {
			return array();
		}
		$db = DbConnector::get_instance()->get_db_link();
		$placeholders = implode(',', array_fill(0, count($item_keys), '?'));
		$sql = 'SELECT rcv_item_key, MAX(rcv_verify_time) AS latest
		          FROM rcv_recovery_verifications
		         WHERE rcv_passed = true AND rcv_item_key IN (' . $placeholders . ')';
		$params = array_values(array_map('strval', $item_keys));
		if ($user_id !== null) {
			$sql .= ' AND rcv_usr_user_id = ?';
			$params[] = (int)$user_id;
		}
		$sql .= ' GROUP BY rcv_item_key';
		$q = $db->prepare($sql);
		$q->execute($params);
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[$row['rcv_item_key']] = $row['latest'];
		}
		return $out;
	}
}

class MultiRecoveryVerification extends SystemMultiBase {
	protected static $model_class = 'RecoveryVerification';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['item_key'])) {
			$filters['rcv_item_key'] = array($this->options['item_key'], PDO::PARAM_STR);
		}
		if (isset($this->options['user_id'])) {
			$filters['rcv_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['method'])) {
			$filters['rcv_method'] = array($this->options['method'], PDO::PARAM_STR);
		}
		if (isset($this->options['passed'])) {
			$filters['rcv_passed'] = array($this->options['passed'] ? 'true' : 'false', PDO::PARAM_BOOL);
		}

		return $this->_get_resultsv2('rcv_recovery_verifications', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
