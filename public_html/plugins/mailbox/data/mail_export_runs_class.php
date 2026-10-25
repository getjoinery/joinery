<?php
/**
 * MailExportRun - one download of a whole mailbox, or one folder of it, as a
 * zip of mbox files.
 *
 * The counterpart to MailImportRun. A member asks for it on
 * /profile/mailbox/export; what happens next follows the mailbox's level
 * (docs/protection_levels.md):
 *
 *   standard   the RunMailExports task builds it in bounded passes, from cron;
 *   private    the vault's deferred work builds it in the owner's window, and
 *              every piece it writes is sealed to the owner's vault;
 *   fortress   the owner's browser builds it from ciphertext. The run only
 *              records the request (and the second factor it was given), and
 *              lets the browser page through the mailbox's rows.
 *
 *   queued -> building -> ready          (standard, private)
 *   browser                              (fortress: nothing is stored here)
 *   failed                               (any; the reason in mxr_error)
 *
 * What a build writes is one or more pieces per folder under
 * {site root}/storage/mail_exports/{run id}/ — plain mbox for a Standard run,
 * SealedFileContainer pieces for a Private one, under the run's own key
 * (mxr_sealed_key, wrapped to the owner's vault). The zip is put together as
 * it downloads (MailExport::streamZip), so the disk holds the mail once.
 *
 * A ready run is kept for export_retention_days and then the retention sweep
 * deletes it with its pieces; so is a run that never finished, counted from
 * when it was asked for.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailExportRun extends SystemBase {
	public static $prefix = 'mxr';
	public static $tablename = 'mxr_mail_export_runs';
	public static $access_class = 'personal';
	public static $pkey_column = 'mxr_mail_export_run_id';

	public static $retention_policy = array(
		'label'          => 'Mail exports',
		'purge_method'   => 'purgeExpired',
		'window_setting' => 'export_retention_days',
	);

	const STATE_QUEUED   = 'queued';
	const STATE_BUILDING = 'building';
	const STATE_READY    = 'ready';
	const STATE_BROWSER  = 'browser';
	const STATE_FAILED   = 'failed';

	/** The states a build picks up and pushes forward. */
	const ACTIVE_STATES = array(self::STATE_QUEUED, self::STATE_BUILDING);

	const LEVEL_STANDARD = 'standard';
	const LEVEL_PRIVATE  = 'private';
	const LEVEL_FORTRESS = 'fortress';

	/** Runs the sweep deletes per pass. */
	const PURGE_MAX_PER_RUN = 200;

	protected static $foreign_key_actions = array(
		// The mailbox goes, and so does every copy of its mail made here.
		'mxr_iea_inbound_email_alias_id' => array('action' => 'permanent_delete'),
		// An export is the person's own copy; it leaves with them.
		'mxr_usr_user_id'                => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'mxr_mail_export_run_id'         => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mxr_iea_inbound_email_alias_id' => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true),
		// Who asked: the only person who can download it.
		'mxr_usr_user_id'                => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true),
		// NULL for the whole mailbox; else one folder key (MailExport::folderKeys()).
		'mxr_folder'                     => array('type'=>'varchar(40)'),
		'mxr_level'                      => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'standard'),
		'mxr_state'                      => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'queued', 'index'=>true),
		// Messages the plan found, messages written, messages that could not be.
		'mxr_total'                      => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mxr_done'                       => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		'mxr_left_out'                   => array('type'=>'int4', 'default'=>0, 'is_nullable'=>false),
		// One line per message left out, written into the zip as its note.
		'mxr_left_out_notes'             => array('type'=>'text'),
		// Plaintext bytes written so far.
		'mxr_bytes'                      => array('type'=>'int8', 'default'=>0, 'is_nullable'=>false),
		// Where the build got to: {folder, after, piece}. Nothing outside MailExport reads it.
		'mxr_cursor'                     => array('type'=>'jsonb'),
		'mxr_error'                      => array('type'=>'text'),
		// Claim stamp: two passes never build the same run.
		'mxr_claim_time'                 => array('type'=>'timestamp(6)'),
		// A Private run's key, wrapped to the owner's vault (blob-only sealing:
		// no column of this row holds ciphertext, the pieces on disk do).
		'mxr_content_sealed'             => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'mxr_sealed_key'                 => array('type'=>'text', 'is_nullable'=>true),
		'mxr_key_generation'             => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'mxr_sealed_owner_user_id'       => array('type'=>'int8', 'is_nullable'=>true),
		'mxr_create_time'                => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mxr_finish_time'                => array('type'=>'timestamp(6)'),
		// When the person was told it is ready (MailExport::announceReady).
		'mxr_announce_time'              => array('type'=>'timestamp(6)'),
		'mxr_update_time'                => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('mxr_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** Where this run's pieces live. */
	function dir(): string {
		return self::rootDir() . '/' . intval($this->key);
	}

	/**
	 * Where a test keeps its pieces instead: a test process may run as another
	 * user than the web server, and a folder it made under storage/ would then
	 * be one the server cannot write into.
	 */
	public static $root_for_tests = null;

	/** Every run's pieces live under here. */
	static function rootDir(): string {
		if (self::$root_for_tests !== null) {
			return rtrim(self::$root_for_tests, '/');
		}
		return rtrim(PathHelper::getSiteRoot(), '/') . '/storage/mail_exports';
	}

	function isActive(): bool {
		return in_array((string)$this->get('mxr_state'), self::ACTIVE_STATES, true);
	}

	/** Progress as a 0-100 integer. */
	function percent(): int {
		$total = intval($this->get('mxr_total'));
		if ($total <= 0) {
			return $this->get('mxr_state') === self::STATE_READY ? 100 : 0;
		}
		return min(100, intval(floor((intval($this->get('mxr_done')) + intval($this->get('mxr_left_out'))) * 100 / $total)));
	}

	/** Where the build got to, as MailExport left it (never null). */
	function cursor(): array {
		$value = $this->get('mxr_cursor');
		if (is_array($value)) {
			return $value;
		}
		$decoded = json_decode((string)$value, true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Write specific columns and mirror them onto this copy. Every write to a
	 * live run goes through here rather than save(): a pass advances the
	 * counters underneath any copy held for more than an instant.
	 */
	function writeColumns(array $columns): void {
		if (!$columns || !$this->key) {
			return;
		}
		$columns['mxr_update_time'] = gmdate('Y-m-d H:i:s');
		$sets = array();
		$params = array();
		foreach ($columns as $col => $value) {
			$sets[] = $col . ' = ?';
			$params[] = is_array($value) ? json_encode($value) : $value;
		}
		$params[] = intval($this->key);
		DbConnector::get_instance()->get_db_link()->prepare('UPDATE mxr_mail_export_runs SET '
			. implode(', ', $sets) . ' WHERE mxr_mail_export_run_id = ?')->execute($params);
		foreach ($columns as $col => $value) {
			$this->set_as_stored($col, is_array($value) ? json_encode($value) : $value);
		}
	}

	/** Move to a new state, stamping the finish time where it ends. */
	function moveTo(string $state, ?string $error = null): void {
		$columns = array('mxr_state' => $state);
		if ($state === self::STATE_READY || $state === self::STATE_FAILED) {
			$columns['mxr_finish_time'] = gmdate('Y-m-d H:i:s');
		}
		if ($error !== null) {
			$columns['mxr_error'] = $error;
		}
		$this->writeColumns($columns);
	}

	/**
	 * The run's key for a Private run, unwrapped in the owner's window.
	 *
	 * @throws VaultLockedException the owner's window is closed
	 */
	function exportKey(): string {
		return $this->existingRowDek(intval($this->get('mxr_sealed_owner_user_id') ?: $this->get('mxr_usr_user_id')));
	}

	/** The row and its pieces on disk. */
	function permanent_delete($debug = false) {
		if ($this->key) {
			self::removeDir($this->dir());
		}
		return parent::permanent_delete($debug);
	}

	/** Delete a run directory and what is in it (pieces only; never recursive). */
	static function removeDir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (glob($dir . '/*') ?: array() as $piece) {
			if (is_file($piece)) {
				@unlink($piece);
			}
		}
		@rmdir($dir);
	}

	/**
	 * Retention: a finished run past the window, and a run that never finished
	 * counted from when it was asked for. 0 in the setting means never.
	 */
	static function purgeExpired($days) {
		$cutoff = gmdate('Y-m-d H:i:s', time() - max(0, intval($days)) * 86400);
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT mxr_mail_export_run_id FROM mxr_mail_export_runs
			WHERE COALESCE(mxr_finish_time, mxr_create_time) < ?
			ORDER BY mxr_mail_export_run_id ASC LIMIT ' . self::PURGE_MAX_PER_RUN);
		$stmt->execute(array($cutoff));
		$removed = 0;
		$failed = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			try {
				$run = new self(intval($id), TRUE);
				if ($run->key) {
					$run->permanent_delete();
					$removed++;
				}
			} catch (Throwable $e) {
				$failed++;
				error_log('MailExportRun::purgeExpired: run ' . $id . ' failed: ' . $e->getMessage());
			}
		}

		// A directory with no run behind it (a run row removed some other way).
		$dirs = 0;
		foreach (glob(self::rootDir() . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
			$id = intval(basename($dir));
			if ($id <= 0 || (string)$id !== basename($dir)) {
				continue;
			}
			$q = $db->prepare('SELECT 1 FROM mxr_mail_export_runs WHERE mxr_mail_export_run_id = ?');
			$q->execute(array($id));
			if (!$q->fetchColumn()) {
				self::removeDir($dir);
				$dirs++;
			}
		}
		$message = sprintf('%d export(s) removed, %d orphaned director%s, %d failure(s)',
			$removed, $dirs, $dirs === 1 ? 'y' : 'ies', $failed);
		return array('removed' => $removed + $dirs, 'message' => $message);
	}
}

class MultiMailExportRun extends SystemMultiBase {
	protected static $model_class = 'MailExportRun';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['states'])) {
			$quoted = array();
			foreach ((array)$this->options['states'] as $s) {
				$quoted[] = "'" . preg_replace('/[^a-z_]/', '', (string)$s) . "'";
			}
			$filters['mxr_state'] = empty($quoted) ? "IN ('')" : 'IN (' . implode(',', $quoted) . ')';
		}
		return $this->_get_resultsv2('mxr_mail_export_runs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
