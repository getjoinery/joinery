<?php
/**
 * MailImportEntry - one row per message the scan found in an archive.
 *
 * This table IS the index the scan leaves behind, and it is what makes "any size"
 * work. The scan walks the archive exactly once and writes a narrow row per
 * message; from then on nothing re-parses the container to find out what is in it.
 * Everything else falls out of that:
 *
 *   - exact preview counts, per folder, before a single message is stored
 *   - resume, because the work query is simply mie_state = 'pending'
 *   - per-entry failure reasons instead of one number for the whole run
 *   - retry of just the entries that failed
 *
 * mie_locator is how to find the bytes again, and its meaning is the reader's:
 * a path inside the container for file-per-message formats, 'offset:length' for
 * mbox. Nothing outside the reader interprets it.
 *
 * A 500,000-message archive means 500,000 rows here. That is unremarkable for
 * Postgres, and the scan writes them in bulk (insertBatch) rather than one model
 * save at a time, which is the difference between minutes and hours.
 *
 * See specs/mail_archive_import.md.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailImportEntryException extends SystemBaseException {}

class MailImportEntry extends SystemBase {
	public static $prefix = 'mie';
	public static $tablename = 'mie_mail_import_entries';
	public static $pkey_column = 'mie_mail_import_entry_id';

	const STATE_PENDING = 'pending';
	const STATE_STORED  = 'stored';
	const STATE_DEDUP   = 'dedup';
	const STATE_SKIPPED = 'skipped';
	const STATE_FAILED  = 'failed';

	// What the source filed the message under, which is what the Spam/Trash
	// default exclusion keys on. Anything not recognisably spam or trash is normal.
	const CLASS_NORMAL = 'normal';
	const CLASS_SPAM   = 'spam';
	const CLASS_TRASH  = 'trash';

	protected static $foreign_key_actions = array(
		'mie_mir_mail_import_run_id' => array('action' => 'cascade'),
		// NOT cascade (the default): an entry is the RECORD of what happened to one
		// message, and it has to outlive the message itself. Undo deletes every row
		// the run created, and if that took the entries with it the run would end up
		// reporting on an import it no longer had any evidence of.
		'mie_iem_inbound_email_message_id' => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'mie_mail_import_entry_id'   => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mie_mir_mail_import_run_id' => array('type'=>'int8', 'is_nullable'=>false),
		// Reader-private: a path inside the container, or 'offset:length' for mbox.
		'mie_locator'                => array('type'=>'varchar(1000)', 'is_nullable'=>false),
		'mie_ordinal'                => array('type'=>'int4'),
		'mie_source_folder'          => array('type'=>'varchar(255)'),
		'mie_labels'                 => array('type'=>'text'),
		'mie_direction'              => array('type'=>'varchar(10)', 'default'=>'inbound'),
		'mie_class'                  => array('type'=>'varchar(20)', 'default'=>'normal'),
		'mie_is_read'                => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'mie_is_starred'             => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'mie_state'                  => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending'),
		'mie_reason'                 => array('type'=>'text'),
		'mie_iem_inbound_email_message_id' => array('type'=>'int8'),
		'mie_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/**
	 * The import's work query is "the next N pending entries of this run", so the
	 * index that serves it is (run, state) — and it serves the per-folder preview
	 * counts too.
	 */
	public static $index_specifications = array(
		array('columns' => array('mie_mir_mail_import_run_id', 'mie_state')),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/** The entry's extra labels as an array (never null). */
	function labels(): array {
		$raw = trim((string)$this->get('mie_labels'));
		if ($raw === '') {
			return array();
		}
		return array_values(array_filter(array_map('trim', explode("\n", $raw)), 'strlen'));
	}

	/**
	 * Bulk-insert scanned entries. One multi-row INSERT per call instead of a model
	 * save per message: a large archive scan is otherwise dominated by round trips,
	 * and the scan writes nothing a model hook needs to see.
	 *
	 * $rows are assoc arrays using the un-prefixed keys the scanner emits:
	 *   locator (required), ordinal, source_folder, labels[], direction, class,
	 *   is_read, is_starred.
	 */
	static function insertBatch(int $runId, array $rows): int {
		if (!$rows) {
			return 0;
		}
		$cols = array('mie_mir_mail_import_run_id', 'mie_locator', 'mie_ordinal', 'mie_source_folder',
			'mie_labels', 'mie_direction', 'mie_class', 'mie_is_read', 'mie_is_starred', 'mie_state');
		$placeholder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

		$values = array();
		$params = array();
		foreach ($rows as $r) {
			$labels = $r['labels'] ?? array();
			$params[] = $runId;
			$params[] = substr((string)$r['locator'], 0, 1000);
			$params[] = isset($r['ordinal']) ? intval($r['ordinal']) : null;
			$params[] = isset($r['source_folder']) ? substr((string)$r['source_folder'], 0, 255) : null;
			$params[] = is_array($labels) ? implode("\n", $labels) : (string)$labels;
			$params[] = (string)($r['direction'] ?? 'inbound');
			$params[] = (string)($r['class'] ?? self::CLASS_NORMAL);
			$params[] = !empty($r['is_read']) ? 't' : 'f';
			$params[] = !empty($r['is_starred']) ? 't' : 'f';
			$params[] = self::STATE_PENDING;
			$values[] = $placeholder;
		}

		$db = DbConnector::get_instance()->get_db_link();
		$sql = 'INSERT INTO mie_mail_import_entries (' . implode(',', $cols) . ') VALUES '
			. implode(',', $values);
		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		return count($rows);
	}

	/**
	 * Record what happened to one entry. A targeted UPDATE rather than a model save:
	 * the row is written once per message across the whole import, and the import
	 * loop is the hot path.
	 */
	static function recordOutcome(int $entryId, string $state, ?string $reason = null, ?int $messageId = null): void {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('UPDATE mie_mail_import_entries
			SET mie_state = ?, mie_reason = ?, mie_iem_inbound_email_message_id = ?
			WHERE mie_mail_import_entry_id = ?')
			->execute(array($state, $reason, $messageId, $entryId));
	}

	/**
	 * Counts by (source folder, class) for a run — what the scanned screen shows.
	 * Straight aggregation rather than loading half a million models.
	 *
	 * @return array<int,array{folder:string,class:string,count:int}>
	 */
	static function folderCounts(int $runId): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare("SELECT COALESCE(mie_source_folder, '') AS folder, mie_class AS cls, COUNT(*) AS cnt
			FROM mie_mail_import_entries
			WHERE mie_mir_mail_import_run_id = ?
			GROUP BY 1, 2 ORDER BY 3 DESC");
		$stmt->execute(array($runId));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[] = array(
				'folder' => (string)$row['folder'],
				'class'  => (string)$row['cls'],
				'count'  => intval($row['cnt']),
			);
		}
		return $out;
	}

	/** Counts by state for a run — the run report. */
	static function stateCounts(int $runId): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT mie_state, COUNT(*) AS cnt FROM mie_mail_import_entries
			WHERE mie_mir_mail_import_run_id = ? GROUP BY 1');
		$stmt->execute(array($runId));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[(string)$row['mie_state']] = intval($row['cnt']);
		}
		return $out;
	}

	/**
	 * Mark everything the user did not tick as skipped, in one statement, and return
	 * how many were skipped. $keepFolders is the selected folder list; spam/trash
	 * classes are governed by their own flags because a message can sit in a
	 * selected folder and still be spam.
	 */
	static function applySelection(int $runId, array $keepFolders, bool $includeSpam, bool $includeTrash): int {
		$db = DbConnector::get_instance()->get_db_link();

		$where = array('mie_mir_mail_import_run_id = ?', "mie_state = 'pending'");
		$params = array($runId);

		$clauses = array();
		if ($keepFolders !== array('*')) {
			$in = implode(',', array_fill(0, max(1, count($keepFolders)), '?'));
			if ($keepFolders) {
				$clauses[] = "COALESCE(mie_source_folder, '') NOT IN ($in)";
				foreach ($keepFolders as $f) { $params[] = (string)$f; }
			} else {
				$clauses[] = '1 = 1'; // nothing selected: skip everything
			}
		}
		if (!$includeSpam) {
			$clauses[] = "mie_class = '" . self::CLASS_SPAM . "'";
		}
		if (!$includeTrash) {
			$clauses[] = "mie_class = '" . self::CLASS_TRASH . "'";
		}
		if (!$clauses) {
			return 0;
		}
		$where[] = '(' . implode(' OR ', $clauses) . ')';

		$sql = "UPDATE mie_mail_import_entries
			SET mie_state = '" . self::STATE_SKIPPED . "', mie_reason = 'Not selected for import.'
			WHERE " . implode(' AND ', $where);
		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		return $stmt->rowCount();
	}
}

class MultiMailImportEntry extends SystemMultiBase {
	protected static $model_class = 'MailImportEntry';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['run_id'])) {
			$filters['mie_mir_mail_import_run_id'] = array($this->options['run_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['state'])) {
			$filters['mie_state'] = array($this->options['state'], PDO::PARAM_STR);
		}

		if (isset($this->options['class'])) {
			$filters['mie_class'] = array($this->options['class'], PDO::PARAM_STR);
		}

		if (isset($this->options['source_folder'])) {
			$filters['mie_source_folder'] = array($this->options['source_folder'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('mie_mail_import_entries', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
