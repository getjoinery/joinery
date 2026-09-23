<?php
/**
 * InboundImapIngestFailure - one source message that would not import.
 *
 * The ingest walk holds a folder's cursor just below a message that failed, so the
 * next poll retries it — right for a passing fault (the server hiccupped, a
 * concurrent store collided). But a message that fails the same way every time
 * would hold the cursor there for ever, and since each poll only walks one window
 * past the cursor, nothing further up the folder would ever be reached.
 *
 * So each failure is counted here, per (folder, UIDVALIDITY, UID). After
 * MAX_ATTEMPTS polls the message is marked skipped: the cursor moves past it, the
 * account status says how many messages could not be imported, and the Accounts
 * page offers a Retry that re-imports exactly the skipped ones.
 *
 * See specs/implemented/imap_client_hardening.md F3.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundImapIngestFailureException extends SystemBaseException {}

class InboundImapIngestFailure extends SystemBase {
	public static $prefix = 'ifl';
	public static $tablename = 'ifl_inbound_imap_ingest_failures';
	public static $pkey_column = 'ifl_inbound_imap_ingest_failure_id';

	/** Polls a message may fail on before the walk moves past it. */
	const MAX_ATTEMPTS = 3;

	protected static $foreign_key_actions = array(
		'ifl_iif_inbound_imap_folder_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'ifl_inbound_imap_ingest_failure_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ifl_iif_inbound_imap_folder_id'     => array('type'=>'int8', 'is_nullable'=>false,
			'unique_with'=>array('ifl_uidvalidity', 'ifl_uid')),
		'ifl_uidvalidity'                    => array('type'=>'int8', 'is_nullable'=>false),
		'ifl_uid'                            => array('type'=>'int8', 'is_nullable'=>false),
		'ifl_attempts'                       => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		'ifl_reason'                         => array('type'=>'varchar(500)'),
		// Set once the walk has given up and moved past the message.
		'ifl_skipped_time'                   => array('type'=>'timestamp(6)'),
		'ifl_create_time'                    => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'ifl_update_time'                    => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Count one failed attempt at (folder, uidvalidity, uid). Returns TRUE when the
	 * message has now failed MAX_ATTEMPTS times and is marked skipped — the caller
	 * then lets the cursor move past it. One statement, so a crash between the
	 * count and the mark cannot leave a message counted out but not skipped.
	 */
	static function recordAttempt(int $folderId, int $uidvalidity, int $uid, string $reason): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"INSERT INTO ifl_inbound_imap_ingest_failures
				(ifl_iif_inbound_imap_folder_id, ifl_uidvalidity, ifl_uid, ifl_attempts, ifl_reason,
				 ifl_skipped_time, ifl_update_time)
			 VALUES (?, ?, ?, 1, ?, CASE WHEN 1 >= ? THEN now() END, now())
			 ON CONFLICT (ifl_iif_inbound_imap_folder_id, ifl_uidvalidity, ifl_uid) DO UPDATE
			 SET ifl_attempts = ifl_inbound_imap_ingest_failures.ifl_attempts + 1,
				 ifl_reason = EXCLUDED.ifl_reason,
				 ifl_update_time = now(),
				 ifl_skipped_time = COALESCE(ifl_inbound_imap_ingest_failures.ifl_skipped_time,
					CASE WHEN ifl_inbound_imap_ingest_failures.ifl_attempts + 1 >= ? THEN now() END)
			 RETURNING ifl_skipped_time IS NOT NULL");
		$stmt->execute(array($folderId, $uidvalidity, $uid, mb_strcut($reason, 0, 500, 'UTF-8'),
			self::MAX_ATTEMPTS, self::MAX_ATTEMPTS));
		return (bool)$stmt->fetchColumn();
	}

	/** A message that imported after all: forget its failures. */
	static function clear(int $folderId, int $uidvalidity, int $uid): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'DELETE FROM ifl_inbound_imap_ingest_failures
			 WHERE ifl_iif_inbound_imap_folder_id = ? AND ifl_uidvalidity = ? AND ifl_uid = ?');
		$stmt->execute(array($folderId, $uidvalidity, $uid));
	}

	/** How many messages of this feed the walk has given up on. */
	static function skippedCountForAccount(int $accountId): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT count(*) FROM ifl_inbound_imap_ingest_failures f
			 JOIN iif_inbound_imap_folders d ON d.iif_inbound_imap_folder_id = f.ifl_iif_inbound_imap_folder_id
			 WHERE d.iif_iia_inbound_imap_account_id = ? AND f.ifl_skipped_time IS NOT NULL');
		$stmt->execute(array($accountId));
		return intval($stmt->fetchColumn());
	}
}

class MultiInboundImapIngestFailure extends SystemMultiBase {
	protected static $model_class = 'InboundImapIngestFailure';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['folder_id'])) {
			$filters['ifl_iif_inbound_imap_folder_id'] = array($this->options['folder_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['skipped'])) {
			$filters['ifl_skipped_time'] = $this->options['skipped'] ? 'IS NOT NULL' : 'IS NULL';
		}

		return $this->_get_resultsv2('ifl_inbound_imap_ingest_failures', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
