<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * FileChange — the append-only Drive change feed (fch_file_changes).
 *
 * Every drive mutation records one row via FileChange::record(); the primary key
 * IS the sync cursor (monotonic, gap-tolerant). A sync client polls
 * drive_changes with its last cursor and replays everything after it. Rows are
 * never updated or soft-deleted; a purge task trims rows past the retention
 * window.
 *
 * @version 1.0.0
 */
class FileChange extends SystemBase {
	public static $prefix = 'fch';
	public static $tablename = 'fch_file_changes';
	public static $pkey_column = 'fch_file_change_id';

	public static $permanent_delete_actions = array();

	// Owner is a real user reference; when the user is deleted their change rows
	// go with them. fch_source_usr_user_id (the actor) is a non-standard name and
	// registers no rule — an actor leaving does not rewrite history.
	protected static $foreign_key_actions = array(
		'fch_usr_user_id' => array('action' => 'cascade'),
	);

	// Retention: the change feed is a replay log for sync clients, not history.
	// Once a row is older than any client's plausible catch-up window it is
	// noise. 0 in the setting means never purge.
	public static $retention_policy = array(
		'label'          => 'Drive change feed',
		'age_column'     => 'fch_create_time',
		'age_unit'       => 'days',
		'window_setting' => 'drive_change_feed_retention_days',
	);

	const KIND_CREATED       = 'created';
	const KIND_CONTENT       = 'content';
	const KIND_RENAMED       = 'renamed';
	const KIND_MOVED         = 'moved';
	const KIND_TRASHED       = 'trashed';
	const KIND_RESTORED      = 'restored';
	const KIND_DELETED       = 'deleted';
	const KIND_GRANT_CHANGED = 'grant_changed';

	public static $field_specifications = array(
		'fch_file_change_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fch_entity_type'        => array('type' => 'varchar(16)', 'is_nullable' => false, 'index_with' => array('fch_entity_id')),
		'fch_entity_id'          => array('type' => 'int8', 'is_nullable' => false),
		'fch_usr_user_id'        => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'index' => true),
		'fch_source_usr_user_id' => array('type' => 'int4', 'is_nullable' => true),
		'fch_change_kind'        => array('type' => 'varchar(24)', 'is_nullable' => false, 'required' => true),
		'fch_create_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()', 'index' => true),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/**
	 * Append one change row. The single write helper every drive mutation calls.
	 * Best-effort by design: a failure to record a change never fails the
	 * mutation that already succeeded, so it swallows and logs.
	 *
	 * @return FileChange|null the recorded row (null on failure)
	 */
	public static function record($kind, $entity_type, $entity_id, $owner_id, $actor_id = null) {
		try {
			$change = new self(NULL);
			$change->set('fch_change_kind', $kind);
			$change->set('fch_entity_type', $entity_type);
			$change->set('fch_entity_id', (int)$entity_id);
			$change->set('fch_usr_user_id', (int)$owner_id);
			if ($actor_id !== null) {
				$change->set('fch_source_usr_user_id', (int)$actor_id);
			}
			$change->save();
			return $change;
		} catch (Exception $e) {
			error_log('FileChange::record failed (' . $kind . ' ' . $entity_type . ':' . $entity_id . '): ' . $e->getMessage());
			return null;
		}
	}
}

class MultiFileChange extends SystemMultiBase {
	protected static $model_class = 'FileChange';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['fch_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['after_cursor'])) {
			// int-cast keeps this literal safe; the runner has no bound '>' form.
			$filters['fch_file_change_id'] = '> ' . (int)$this->options['after_cursor'];
		}
		if (isset($this->options['entity_type'])) {
			$filters['fch_entity_type'] = array($this->options['entity_type'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('fch_file_changes', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
