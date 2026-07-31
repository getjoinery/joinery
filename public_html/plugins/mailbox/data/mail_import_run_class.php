<?php
/**
 * MailImportRun - one attempt at bringing an existing mail archive into a mailbox.
 *
 * The counterpart to an IMAP feed: a feed pulls from a live account forever, a run
 * reads a dead archive once. The user picks a source file, says which mailbox it
 * goes into and which addresses were theirs, chooses what to bring, and the run
 * grinds through it in batches.
 *
 * The state machine is the whole contract, and nothing advances except through the
 * RunMailImports task:
 *
 *   queued -> scanning -> scanned -> importing -> done
 *
 * `scanned` is a genuine stop: the scan has written one mie_ entry per message it
 * found but no mail at all, and the run waits there until the user confirms what
 * they want. `failed` is reachable from any state and carries its reason in
 * mir_error; `undone` follows a reversal and keeps its entries, so the report of
 * what happened survives the undo.
 *
 * Counters are advanced by the importer as it goes, so mir_processed against
 * mir_total_entries is a live progress bar with no extra bookkeeping. They are
 * also the reconciliation tripwire: stored + dedup + failed + skipped must equal
 * processed, and a shortfall means a message went missing without a reason.
 *
 * See specs/mail_archive_import.md.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailImportRunException extends SystemBaseException {}

class MailImportRun extends SystemBase {
	public static $prefix = 'mir';
	public static $tablename = 'mir_mail_import_runs';
	public static $pkey_column = 'mir_mail_import_run_id';

	const STATE_QUEUED    = 'queued';
	const STATE_SCANNING  = 'scanning';
	const STATE_SCANNED   = 'scanned';
	const STATE_IMPORTING = 'importing';
	const STATE_DONE      = 'done';
	const STATE_FAILED    = 'failed';
	const STATE_UNDONE    = 'undone';

	/** The states the task is allowed to pick up and push forward. */
	const ACTIVE_STATES = array(self::STATE_QUEUED, self::STATE_SCANNING, self::STATE_IMPORTING);

	/**
	 * The states that mean a run is genuinely UNDERWAY, which is what the
	 * concurrency cap counts. A queued run is not consuming anything — counting it
	 * would mean that once more runs are waiting than the cap allows, none of them
	 * could ever start.
	 */
	const IN_FLIGHT_STATES = array(self::STATE_SCANNING, self::STATE_IMPORTING);

	protected static $foreign_key_actions = array(
		// Deleting the mailbox takes its imported mail with it, so the runs that
		// brought that mail in go too — there is nothing left for them to report on.
		'mir_iea_inbound_email_alias_id' => array('action' => 'cascade'),
		// The person leaving does not erase what was imported into a mailbox other
		// people may still hold. Same treatment a deleted user's files get.
		'mir_usr_user_id'                => array('action' => 'set_value', 'value' => User::USER_DELETED),
		// NOT cascade (the default): tidying away the source archive must not delete
		// the record of the import that read it. The file is disposable once the run
		// is done; the report is not.
		'mir_fil_file_id'                => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'mir_mail_import_run_id'         => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The mailbox the mail is filed into. Independent of the address recorded on
		// each message (iem_recipient), which stays the honest delivery address.
		'mir_iea_inbound_email_alias_id' => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true),
		'mir_usr_user_id'                => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true),
		// The archive. A Drive-picked file belongs to the user and is never touched;
		// an uploaded one is the importer's to clean up when the run is dismissed.
		// Nullable so a finished run survives its source being tidied away.
		'mir_fil_file_id'                => array('type'=>'int8'),
		'mir_source_name'                => array('type'=>'varchar(500)'),
		// The reader key that claimed the file — mbox, eml_dir, eml, zip, tar.
		'mir_format'                     => array('type'=>'varchar(40)'),
		'mir_state'                      => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'queued', 'index'=>true),
		// Newline-separated, as the user declared them. Drives sent-vs-received and
		// which address each message is recorded as having been delivered to.
		'mir_own_addresses'              => array('type'=>'text'),
		// {"folders": [...], "include_spam": bool, "include_trash": bool} — what the
		// user ticked on the scanned screen.
		'mir_selection'                  => array('type'=>'jsonb'),
		// Where the scan got to. The shape belongs to the reader that wrote it — a
		// byte offset for an mbox, a member index for a container — and nothing
		// outside the reader interprets it. This is what lets a 50GB archive be
		// walked across as many task passes as it takes.
		'mir_scan_state'                 => array('type'=>'jsonb'),
		'mir_total_entries'              => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mir_processed'                  => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mir_stored'                     => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mir_dedup'                      => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mir_failed'                     => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mir_skipped'                    => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mir_bytes_total'                => array('type'=>'int8'),
		'mir_error'                      => array('type'=>'text'),
		// Working area for a format that cannot be read in place (gzipped tar is
		// sequential-access only). Held for the life of the run, removed on finish.
		'mir_work_dir'                   => array('type'=>'varchar(500)'),
		// Claim stamp — the atomic conditional UPDATE the task uses to make sure two
		// cron passes never work the same run.
		'mir_claim_time'                 => array('type'=>'timestamp(6)'),
		'mir_create_time'                => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mir_start_time'                 => array('type'=>'timestamp(6)'),
		'mir_finish_time'                => array('type'=>'timestamp(6)'),
		'mir_update_time'                => array('type'=>'timestamp(6)'),
		'mir_delete_time'                => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	function prepare() {
		$this->set('mir_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** The declared addresses, lowercased and de-duplicated. */
	function ownAddresses(): array {
		return self::parseAddressList((string)$this->get('mir_own_addresses'));
	}

	/**
	 * Split a user-typed address list into clean lowercase addresses. Accepts the
	 * newline-separated form the field stores plus commas and semicolons, because
	 * people paste from wherever they had the list.
	 */
	static function parseAddressList(string $raw): array {
		$parts = preg_split('/[\s,;]+/', $raw) ?: array();
		$out = array();
		foreach ($parts as $p) {
			$p = strtolower(trim($p, " \t\n\r\0\x0B<>\"'"));
			if ($p !== '' && strpos($p, '@') !== false) {
				$out[$p] = true;
			}
		}
		return array_keys($out);
	}

	/** The user's selection, as an array (never null). */
	function selection(): array {
		return self::decodeJson($this->get('mir_selection'));
	}

	/** Where the scan got to, as the reader left it (never null). */
	function scanState(): array {
		return self::decodeJson($this->get('mir_scan_state'));
	}

	/** A jsonb column read back as an array, whether the driver decoded it or not. */
	private static function decodeJson($value): array {
		if (is_array($value)) {
			return $value;
		}
		$decoded = json_decode((string)$value, true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Move the run to a new state, stamping the matching timestamp.
	 *
	 * A TARGETED update, not a full model save. The counters on this row are
	 * advanced by addCounts() as the import goes, so a save() would write back
	 * whatever this in-memory copy was holding when it was loaded and silently
	 * undo a batch's worth of progress. Writing only the columns a state change
	 * owns makes that impossible rather than merely unlikely.
	 */
	function moveTo(string $state, ?string $error = null): void {
		$columns = array('mir_state' => $state, 'mir_update_time' => gmdate('Y-m-d H:i:s'));

		if ($state === self::STATE_SCANNING && !$this->get('mir_start_time')) {
			$columns['mir_start_time'] = gmdate('Y-m-d H:i:s');
		}
		if (in_array($state, array(self::STATE_DONE, self::STATE_FAILED, self::STATE_UNDONE), true)) {
			$columns['mir_finish_time'] = gmdate('Y-m-d H:i:s');
		}
		if ($error !== null) {
			$columns['mir_error'] = $error;
		}

		$this->writeColumns($columns);
	}

	/**
	 * Write specific columns and mirror them onto this copy.
	 *
	 * Every write to a live run goes through here rather than save(), for the
	 * reason moveTo() gives: the counters advance underneath any model instance
	 * that has been held for more than an instant, and a full save would roll them
	 * back without anything noticing.
	 */
	function writeColumns(array $columns): void {
		if (!$columns || !$this->key) {
			return;
		}
		$columns['mir_update_time'] = gmdate('Y-m-d H:i:s');

		$sets = array();
		$params = array();
		foreach ($columns as $col => $value) {
			$sets[] = $col . ' = ?';
			$params[] = $value;
		}
		$params[] = intval($this->key);

		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('UPDATE mir_mail_import_runs SET ' . implode(', ', $sets)
			. ' WHERE mir_mail_import_run_id = ?')->execute($params);

		// Keep this copy consistent with the row, so a caller that reads back
		// immediately sees what it just asked for.
		foreach ($columns as $col => $value) {
			$this->set($col, $value);
		}
	}

	/** True while the task still has work to do on this run. */
	function isActive(): bool {
		return in_array((string)$this->get('mir_state'), self::ACTIVE_STATES, true);
	}

	/** Progress as a 0-100 integer; 0 before the scan has counted anything. */
	function percent(): int {
		$total = intval($this->get('mir_total_entries'));
		if ($total <= 0) {
			return 0;
		}
		return min(100, intval(round(intval($this->get('mir_processed')) * 100 / $total)));
	}

	/**
	 * Add to the run's counters with one atomic UPDATE. The importer calls this per
	 * batch rather than read-modify-writing the model, so a concurrent reader of the
	 * progress bar can never see counters that disagree with each other.
	 */
	static function addCounts(int $runId, array $deltas): void {
		$allowed = array('mir_processed', 'mir_stored', 'mir_dedup', 'mir_failed', 'mir_skipped');
		$sets = array();
		$params = array();
		foreach ($allowed as $col) {
			if (!empty($deltas[$col])) {
				$sets[] = $col . ' = ' . $col . ' + ?';
				$params[] = intval($deltas[$col]);
			}
		}
		if (!$sets) {
			return;
		}
		$sets[] = 'mir_update_time = now()';
		$params[] = $runId;
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('UPDATE mir_mail_import_runs SET ' . implode(', ', $sets)
			. ' WHERE mir_mail_import_run_id = ?')->execute($params);
	}

	/** States where the run is over and its archive is no longer working material. */
	const FINISHED_STATES = array(self::STATE_DONE, self::STATE_FAILED, self::STATE_UNDONE);

	/**
	 * Every state in which a run is still going on — the complement of
	 * FINISHED_STATES. Wider than ACTIVE_STATES, because `scanned` is a run that is
	 * still going even though nothing is moving: it has stopped to ask the user a
	 * question, and it resumes the moment they answer. That is why the one-import-
	 * at-a-time rule counts it.
	 */
	const UNFINISHED_STATES = array(
		self::STATE_QUEUED, self::STATE_SCANNING, self::STATE_SCANNED, self::STATE_IMPORTING);

	/** True when the run has finished, however it finished. */
	function isFinished(): bool {
		return in_array((string)$this->get('mir_state'), self::FINISHED_STATES, true);
	}

	/**
	 * Runs whose archive can be discarded: finished at least $days ago and still
	 * holding a file.
	 *
	 * The grace period is the point. Deleting an archive the moment a run completes
	 * would be tidier and wrong — undoing an import and running it again is a
	 * normal thing to do, and it needs the same bytes. The window is how long that
	 * remains possible.
	 *
	 * @return int[] run ids
	 */
	static function finishedBefore(int $days): array {
		$db = DbConnector::get_instance()->get_db_link();
		$in = "'" . implode("','", self::FINISHED_STATES) . "'";
		$stmt = $db->prepare(
			"SELECT mir_mail_import_run_id FROM mir_mail_import_runs
			 WHERE mir_state IN ($in)
			   AND mir_fil_file_id IS NOT NULL
			   AND mir_finish_time IS NOT NULL
			   AND mir_finish_time < now() - (INTERVAL '1 day' * ?)
			 ORDER BY mir_mail_import_run_id");
		$stmt->execute(array(max(0, $days)));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
	}

	/**
	 * How many runs are actually underway deployment-wide. The concurrency cap
	 * reads this so one enthusiastic user with a 50GB archive cannot starve the
	 * mail stack — while runs merely waiting their turn stay free to start.
	 */
	static function inFlightCount(): int {
		$db = DbConnector::get_instance()->get_db_link();
		$in = "'" . implode("','", self::IN_FLIGHT_STATES) . "'";
		$stmt = $db->query('SELECT COUNT(*) FROM mir_mail_import_runs
			WHERE mir_state IN (' . $in . ') AND mir_delete_time IS NULL');
		return intval($stmt->fetchColumn());
	}
}

class MultiMailImportRun extends SystemMultiBase {
	protected static $model_class = 'MailImportRun';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['alias_id'])) {
			$filters['mir_iea_inbound_email_alias_id'] = array($this->options['alias_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['alias_ids'])) {
			$ids = array_map('intval', (array)$this->options['alias_ids']);
			$filters['mir_iea_inbound_email_alias_id'] = empty($ids)
				? 'IN (NULL)' : 'IN (' . implode(',', $ids) . ')';
		}

		if (isset($this->options['user_id'])) {
			$filters['mir_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['state'])) {
			$filters['mir_state'] = array($this->options['state'], PDO::PARAM_STR);
		}

		if (isset($this->options['states'])) {
			$states = (array)$this->options['states'];
			$quoted = array();
			foreach ($states as $s) {
				$quoted[] = "'" . preg_replace('/[^a-z_]/', '', (string)$s) . "'";
			}
			$filters['mir_state'] = empty($quoted) ? "IN ('')" : 'IN (' . implode(',', $quoted) . ')';
		}

		if (isset($this->options['file_id'])) {
			$filters['mir_fil_file_id'] = array($this->options['file_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['deleted'])) {
			$filters['mir_delete_time'] = $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL';
		}

		return $this->_get_resultsv2('mir_mail_import_runs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
