<?php
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
 * @version 1.3.0 - inside the caller's transaction a row that cannot be written fails the write it belongs to
 * @version 1.2.0 - KIND_LEVEL_CHANGED: a folder tree's protection level changed
 * @version 1.1.0
 */
class FileChange extends SystemBase {
	public static $prefix = 'fch';
	public static $tablename = 'fch_file_changes';
	public static $access_class = 'personal';
	public static $pkey_column = 'fch_file_change_id';

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
	// A folder tree's protection level changed (drive_level_change). The files
	// inside convert afterwards without a row each: what a client downloads
	// from them is unchanged.
	const KIND_LEVEL_CHANGED = 'level_changed';

	public static $field_specifications = array(
		'fch_file_change_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fch_entity_type'        => array('type' => 'varchar(16)', 'is_nullable' => false, 'index_with' => array('fch_entity_id')),
		'fch_entity_id'          => array('type' => 'int8', 'is_nullable' => false),
		'fch_usr_user_id'        => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'index' => true),
		'fch_source_usr_user_id' => array('type' => 'int4', 'is_nullable' => true),
		// Who this row has to reach, when owning the entity is not what earns
		// them the right to hear about it, and where the entity is now may not
		// either: a share revoked, where the grant is gone by the time this is
		// written and the loser matches no clause in the feed; an item moved or
		// restored out of a member's share; a file key given to or taken from
		// one member. The feed hands the row to that member whatever else it
		// lets them see. Null on every other row, which is nearly all of them.
		'fch_audience_usr_user_id' => array('type' => 'int4', 'is_nullable' => true, 'index' => true),
		'fch_change_kind'        => array('type' => 'varchar(24)', 'is_nullable' => false, 'required' => true),
		'fch_create_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()', 'index' => true),
	);

	/**
	 * Append one change row. The single write helper every drive mutation calls.
	 * Best-effort for a mutation that has already committed: a failure to record
	 * its change never fails it, so it swallows and logs.
	 *
	 * Not inside the caller's transaction. There the row is part of the write
	 * (a move and the rows that tell who lost it, a key and the row that says
	 * it arrived), and a failed INSERT has already aborted the transaction:
	 * swallowed, the caller would carry on and its COMMIT would quietly roll
	 * back the mutation it then reports as done. So it is rethrown, and the
	 * write fails whole -- a mutation no sync client is ever told about is the
	 * hole this feed exists to close.
	 *
	 * `$audience_id` addresses the row to one user who would not otherwise see
	 * it. The owner stays in fch_usr_user_id either way, so a row never lies
	 * about who the entity belongs to.
	 *
	 * @return FileChange|null the recorded row (null on failure outside a transaction)
	 */
	public static function record($kind, $entity_type, $entity_id, $owner_id, $actor_id = null, $audience_id = null) {
		try {
			$change = new self(NULL);
			$change->set('fch_change_kind', $kind);
			$change->set('fch_entity_type', $entity_type);
			$change->set('fch_entity_id', (int)$entity_id);
			$change->set('fch_usr_user_id', (int)$owner_id);
			if ($actor_id !== null) {
				$change->set('fch_source_usr_user_id', (int)$actor_id);
			}
			if ($audience_id !== null) {
				$change->set('fch_audience_usr_user_id', (int)$audience_id);
			}
			// The feed records a mutation its caller already made; the row is the
			// file owner's, whoever acted.
			SystemBase::rule_checked_write(function () use ($change) { $change->save(); });
			return $change;
		} catch (Exception $e) {
			error_log('FileChange::record failed (' . $kind . ' ' . $entity_type . ':' . $entity_id . '): ' . $e->getMessage());
			if (DbConnector::get_instance()->get_db_link()->inTransaction()) {
				throw $e;
			}
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
