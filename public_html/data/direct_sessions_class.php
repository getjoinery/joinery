<?php
/**
 * DirectSession - the single-use state an `accept` opens between the two
 * requests of a Joinery Direct delivery.
 *
 * The preflight is answered before any content exists, so something has to
 * remember what was admitted: the manifest the receiver agreed to, the verified
 * sending identity, and the key generation it answered with. The content
 * transfer redeems that — once. Completion or terminal failure consumes the
 * session, expiry discards it along with any partial parts, and a transfer
 * against a consumed, expired, or unknown session is a request-level refusal.
 * That is what closes content-transfer replay, the way the nonce cache closes
 * preflight replay.
 *
 * The row holds envelope and manifest data only — nothing sealed, nothing
 * per-user — so it works while a vault is locked.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DirectSessionException extends SystemBaseException {}

class DirectSession extends SystemBase {
	public static $prefix = 'jds';

	// REST API: protocol-internal delivery state, never remotely readable.
	function authenticate_read($data) {
		throw new SystemAuthenticationError('Direct delivery sessions are not readable through the API.');
	}

	function authenticate_write($data) {
		throw new SystemAuthenticationError('Direct delivery sessions are not writable through the API.');
	}

	public static $tablename = 'jds_direct_sessions';
	public static $pkey_column = 'jds_direct_session_id';

	/** Open and awaiting its content transfer. */
	const STATE_OPEN     = 'open';
	/** Redeemed by a completed transfer, or burned by a terminal failure. */
	const STATE_CONSUMED = 'consumed';

	public static $field_specifications = array(
		'jds_direct_session_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		// Keyed by the preflight's envelope nonce — the same value the transfer
		// signature is bound to, so a transfer cannot be spliced onto another
		// preflight.
		'jds_nonce'          => array('type'=>'varchar(64)', 'is_nullable'=>false, 'unique'=>true),
		'jds_kind'           => array('type'=>'varchar(32)', 'is_nullable'=>false),
		'jds_protocol_version' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		// The VERIFIED sending identity, not anything the envelope merely claimed.
		'jds_sender_address' => array('type'=>'varchar(320)', 'is_nullable'=>false),
		'jds_sender_domain'  => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'jds_sender_key_id'  => array('type'=>'varchar(32)', 'is_nullable'=>false),
		'jds_recipient'      => array('type'=>'varchar(320)', 'is_nullable'=>false),
		// The admitted manifest, JSON. This is the transfer-time contract: a part
		// that exceeds its declared size or is not in this list aborts the delivery.
		'jds_manifest'       => array('type'=>'text', 'is_nullable'=>false),
		'jds_total_bytes'    => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		// The generation answered with, so the delivery can be tagged with the key
		// it was sealed to and an unopenable message told apart from a corrupt one.
		'jds_key_generation' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		// True when the accept was unconditional at a sealed tier and the gate is
		// deferred to unlock; false when the live gate already ran and passed.
		'jds_is_deferred'    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// True when the accept was unconditional at a sealed tier but the recipient
		// mailbox does not encrypt (a group alias, or an owner with no vault), so its
		// plaintext address book is gated at commit instead of at an unlock that will
		// never come. Mutually exclusive with jds_is_deferred.
		'jds_gate_at_commit' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// True when the answered key was a decoy: the transfer is taken, verified,
		// and discarded, and only its declared bytes are charged to the address cap.
		'jds_is_decoy'       => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'jds_state'          => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>self::STATE_OPEN),
		'jds_expires_time'   => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'index'=>true),
		'jds_create_time'    => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $timestamp_fields = array('jds_expires_time', 'jds_create_time');

	/**
	 * Redeem the session for $nonce, atomically. Returns the row on success and
	 * null when there is no live session — unknown nonce, already consumed, or
	 * expired are all one answer, because they are all one refusal on the wire.
	 *
	 * The UPDATE is the claim: two copies of a captured transfer cannot both
	 * flip one row from open to consumed.
	 */
	public static function redeem(string $nonce): ?DirectSession {
		$nonce = trim($nonce);
		if ($nonce === '') {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('UPDATE jds_direct_sessions
			   SET jds_state = ?
			 WHERE jds_nonce = ? AND jds_state = ? AND jds_expires_time >= now()
			 RETURNING jds_direct_session_id');
		$stmt->execute(array(self::STATE_CONSUMED, $nonce, self::STATE_OPEN));
		$id = $stmt->fetchColumn();
		if (!$id) {
			return null;
		}
		return new DirectSession(intval($id), TRUE);
	}

	/** The manifest as the array it was admitted as. */
	public function manifest(): array {
		$decoded = json_decode((string)$this->get('jds_manifest'), true);
		return is_array($decoded) ? $decoded : array();
	}

	/** Drop expired sessions. Their partial parts go with them. */
	public static function purgeExpired(): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('DELETE FROM jds_direct_sessions WHERE jds_expires_time < now()');
		$stmt->execute();
		return $stmt->rowCount();
	}
}

class MultiDirectSession extends SystemMultiBase {
	protected static $model_class = 'DirectSession';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['nonce'])) {
			$filters['jds_nonce'] = array((string)$this->options['nonce'], PDO::PARAM_STR);
		}
		if (isset($this->options['state'])) {
			$filters['jds_state'] = array((string)$this->options['state'], PDO::PARAM_STR);
		}
		if (isset($this->options['recipient'])) {
			$filters['jds_recipient'] = array(strtolower((string)$this->options['recipient']), PDO::PARAM_STR);
		}
		if (!empty($this->options['unexpired'])) {
			$filters['jds_expires_time'] = '>= now()';
		}

		return $this->_get_resultsv2('jds_direct_sessions', $filters, $this->order_by, $only_count, $debug);
	}
}
