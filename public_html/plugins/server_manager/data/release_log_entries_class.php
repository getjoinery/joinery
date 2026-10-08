<?php
/**
 * ReleaseLogEntry — one release statement this site wrote to Sigstore's public
 * log, kept whether or not its release ever shipped (spec
 * release_transparency, D4, D-F).
 *
 * The log is append-only and public: once an entry is written it is there for
 * good, whatever happens to the publish that wrote it. A publish that fails
 * after logging deletes its release row so the work can be redone, so the row
 * cannot be the record of the entry. This is. A row is written the moment the
 * log's answer is verified, before anything else can fail, and never removed.
 *
 * It answers two questions. Was this version number ever logged? Then it is
 * never logged again: a version logged and not shipped is spent, and the next
 * publish takes the number after it, so one number never has two statements.
 * And is every entry under our key accounted for? An entry whose version has
 * no release row carrying the same statement was logged and never shipped;
 * this row, with its statement, is what explains it (O5's canary reads this).
 *
 * @version 1.1 - rle_seen_in_log_time: when ReleaseLogTail, reading the log, found this entry there (O5)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ReleaseLogEntryException extends SystemBaseException {}

class ReleaseLogEntry extends SystemBase {
	public static $prefix = 'rle';
	public static $tablename = 'rle_release_log_entries';
	public static $pkey_column = 'rle_release_log_entry_id';

	public static $test_fixture = array(
		'values'       => array('rle_version' => '0.0.1', 'rle_log_origin' => 'log.example.test', 'rle_log_index' => 1,
			'rle_statement' => '{}'),
		'update_field' => 'rle_version',
	);

	public static $field_specifications = array(
		'rle_release_log_entry_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The release version the statement names.
		'rle_version'    => array('type'=>'varchar(20)', 'is_nullable'=>false),
		// Where it is: the log's origin and the entry's index there.
		'rle_log_origin' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'unique_with'=>array('rle_log_index')),
		'rle_log_index'  => array('type'=>'int8', 'is_nullable'=>false),
		// The RELEASE_STATEMENT document as logged.
		'rle_statement'  => array('type'=>'text', 'is_nullable'=>false),
		// When ReleaseLogTail, reading the log in order, found this entry
		// there leaf for leaf. Empty until it has read that far.
		'rle_seen_in_log_time' => array('type'=>'timestamp(6)'),
		'rle_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/** Record a logged statement. Throws when it cannot: the caller must not go on without the record. */
	public static function record(string $version, string $statement_bytes): ReleaseLogEntry {
		$doc = json_decode($statement_bytes, true);
		$entry = is_array($doc) ? ($doc['entry'] ?? null) : null;
		if (!is_array($entry) || !isset($entry['log_origin'], $entry['log_index'])) {
			throw new ReleaseLogEntryException('a logged statement without its log entry cannot be recorded');
		}
		$row = new ReleaseLogEntry(NULL);
		$row->set('rle_version', $version);
		$row->set('rle_log_origin', (string)$entry['log_origin']);
		$row->set('rle_log_index', (int)$entry['log_index']);
		$row->set('rle_statement', $statement_bytes);
		$row->save();
		return $row;
	}

	/** Whether a statement for this version was ever logged here. */
	public static function versionLogged(string $version): bool {
		return count(new MultiReleaseLogEntry(array('rle_version' => $version))) > 0;
	}
}

class MultiReleaseLogEntry extends SystemMultiBase {
	protected static $model_class = 'ReleaseLogEntry';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['rle_version'])) {
			$filters['rle_version'] = array((string)$this->options['rle_version'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('rle_release_log_entries', $filters, $this->order_by, $only_count, $debug);
	}
}
