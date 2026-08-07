<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * DriveUsage — one row per user, the recomputed byte total that backs the quota
 * gate and the storage meter (dru_drive_usage).
 *
 * Recompute, never increment: dru_bytes_used is a SUM over the user's files
 * (and, once versioning lands, their versions), not a running counter — there
 * is no incrementing-counter precedent in the codebase and a SUM cannot drift.
 * Each logical file bills its full size even when its bytes are deduped onto a
 * shared blob: dedup saves disk, not quota.
 *
 * Scope: only Drive files (fil_source='drive') and their versions bill Drive
 * quota, and version bytes bill the file's owner, not whoever saved them.
 *
 * @version 1.1.0
 */
class DriveUsage extends SystemBase {
	public static $prefix = 'dru';
	public static $tablename = 'dru_drive_usage';
	public static $pkey_column = 'dru_drive_usage_id';

	protected static $foreign_key_actions = array(
		'dru_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'dru_drive_usage_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'dru_usr_user_id'    => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'dru_bytes_used'     => array('type' => 'int8', 'is_nullable' => false, 'default' => 0, 'zero_on_create' => true),
		'dru_update_time'    => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/**
	 * Load-or-create the usage row for a user (the NotificationPreference::get_for
	 * idiom). Never returns null.
	 */
	public static function for_user($user_id) {
		$user_id = (int)$user_id;
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT dru_drive_usage_id FROM dru_drive_usage WHERE dru_usr_user_id = ? LIMIT 1");
		$q->execute(array($user_id));
		$id = $q->fetchColumn();
		if ($id !== false) {
			return new self((int)$id, true);
		}
		$usage = new self(NULL);
		$usage->set('dru_usr_user_id', $user_id);
		$usage->set('dru_bytes_used', 0);
		$usage->save();
		$usage->load();
		return $usage;
	}

	/**
	 * Current recorded byte total for a user WITHOUT creating a row — safe to call
	 * from a read/GET path (the meter on the /drive page). Returns 0 when no row
	 * exists yet; the row is materialized lazily by the first recompute after a
	 * mutation.
	 */
	public static function current_bytes($user_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT dru_bytes_used FROM dru_drive_usage WHERE dru_usr_user_id = ? LIMIT 1");
		$q->execute(array((int)$user_id));
		$v = $q->fetchColumn();
		return $v === false ? 0 : (int)$v;
	}

	/**
	 * Recompute a user's total from their files and file versions and persist it.
	 * Runs the SUM(s) inside whatever transaction the caller already holds (it
	 * opens none of its own), so it composes with upload_complete / new-version /
	 * restore / permanent-delete flows. Returns the fresh byte total.
	 */
	public static function recompute($user_id) {
		$user_id = (int)$user_id;
		$dblink = DbConnector::get_instance()->get_db_link();

		// Head blobs: every live or trashed Drive file the user owns (trash counts
		// until purged; permanently deleted files are already gone from fil_files).
		// Only fil_source='drive' rows bill Drive quota — an avatar or mail
		// attachment never counts against Drive storage.
		$q = $dblink->prepare(
			"SELECT COALESCE(SUM(b.fbb_size_bytes), 0)
			   FROM fil_files f
			   JOIN fbb_file_blobs b ON b.fbb_file_blob_id = f.fil_fbb_file_blob_id
			  WHERE f.fil_usr_user_id = ? AND f.fil_source = 'drive'");
		$q->execute(array($user_id));
		$total = (int)$q->fetchColumn();

		// Version bytes bill the FILE's owner (fvr_usr_user_id records who saved
		// the version — audit only, an editor may have saved it). The table only
		// exists once the versioning phase has shipped; guard so a fresh install
		// still recomputes.
		if (self::_table_exists('fvr_file_versions')) {
			$qv = $dblink->prepare(
				"SELECT COALESCE(SUM(v.fvr_size_bytes), 0)
				   FROM fvr_file_versions v
				   JOIN fil_files f ON f.fil_file_id = v.fvr_fil_file_id
				  WHERE f.fil_usr_user_id = ? AND f.fil_source = 'drive'");
			$qv->execute(array($user_id));
			$total += (int)$qv->fetchColumn();
		}

		$usage = self::for_user($user_id);
		$usage->set('dru_bytes_used', $total);
		$usage->set('dru_update_time', gmdate('Y-m-d H:i:s'));
		$usage->save();
		return $total;
	}

	private static function _table_exists($table) {
		$dblink = DbConnector::get_instance()->get_db_link();
		try {
			$q = $dblink->prepare("SELECT to_regclass(?)");
			$q->execute(array($table));
			return $q->fetchColumn() !== null;
		} catch (PDOException $e) {
			return false;
		}
	}
}

class MultiDriveUsage extends SystemMultiBase {
	protected static $model_class = 'DriveUsage';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['dru_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('dru_drive_usage', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
