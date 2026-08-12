<?php
/**
 * DirectNonce - the Joinery Direct replay cache.
 *
 * Every preflight carries a fresh timestamp and a random per-delivery nonce
 * inside what the instance signature covers. A nonce already seen is a replay
 * and the request is refused before the kind's gate ever runs, so the refusal
 * discloses nothing about the recipient — only a replayer, who already holds the
 * captured message, can trigger it.
 *
 * Rows expire ten minutes after they are recorded, deliberately longer than the
 * five-minute freshness window: a replay old enough to have aged out of this
 * cache is already too stale to pass the freshness check, so the two compose
 * with no gap between them.
 *
 * The table holds opaque nonces and expiries — nothing per-user, nothing sealed
 * — which is what lets a locked Fortress box deduplicate without unlocking.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DirectNonceException extends SystemBaseException {}

class DirectNonce extends SystemBase {
	public static $prefix = 'jdn';

	// REST API: protocol-internal replay state, never remotely readable.
	function authenticate_read($data) {
		throw new SystemAuthenticationError('Direct replay state is not readable through the API.');
	}

	function authenticate_write($data) {
		throw new SystemAuthenticationError('Direct replay state is not writable through the API.');
	}

	public static $tablename = 'jdn_direct_nonces';
	public static $pkey_column = 'jdn_direct_nonce_id';

	public static $field_specifications = array(
		'jdn_direct_nonce_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		// The uniqueness of this column IS the replay defence — a concurrent
		// duplicate loses at the database, not at a read-then-write race.
		'jdn_nonce'       => array('type'=>'varchar(64)', 'is_nullable'=>false, 'unique'=>true),
		'jdn_expires_time'=> array('type'=>'timestamp(6)', 'is_nullable'=>false, 'index'=>true),
		'jdn_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $timestamp_fields = array('jdn_expires_time', 'jdn_create_time');

	/** How long a nonce is remembered. Longer than the freshness window, on purpose. */
	const TTL_SECONDS = 600;

	/**
	 * Record $nonce as seen. Returns false when it was already recorded — the
	 * caller refuses the request at that point.
	 *
	 * The insert is the test: two concurrent copies of one captured preflight
	 * cannot both win a unique constraint, where a SELECT-then-INSERT could let
	 * both through.
	 */
	public static function claim(string $nonce): bool {
		$nonce = trim($nonce);
		if ($nonce === '' || strlen($nonce) > 64) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		try {
			$stmt = $db->prepare('INSERT INTO jdn_direct_nonces (jdn_nonce, jdn_expires_time, jdn_create_time)
				VALUES (?, now() + (interval \'1 second\' * ?), now())
				ON CONFLICT (jdn_nonce) DO NOTHING');
			$stmt->execute(array($nonce, self::TTL_SECONDS));
			return $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			error_log('DirectNonce::claim failed: ' . $e->getMessage());
			return false; // fail closed — an unrecordable nonce is treated as a replay
		}
	}

	/** Drop expired rows. Called from the Direct sweep; cheap and indexed. */
	public static function purgeExpired(): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('DELETE FROM jdn_direct_nonces WHERE jdn_expires_time < now()');
		$stmt->execute();
		return $stmt->rowCount();
	}
}

class MultiDirectNonce extends SystemMultiBase {
	protected static $model_class = 'DirectNonce';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['nonce'])) {
			$filters['jdn_nonce'] = array((string)$this->options['nonce'], PDO::PARAM_STR);
		}
		if (!empty($this->options['unexpired'])) {
			$filters['jdn_expires_time'] = '>= now()';
		}

		return $this->_get_resultsv2('jdn_direct_nonces', $filters, $this->order_by, $only_count, $debug);
	}
}
