<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * DriveUsage — what a member's files weigh: the number behind the storage
 * meter and the quota gate (dru_drive_usage).
 *
 * Everything the member owns counts: a Drive upload, a mail attachment, a
 * photo, a chat upload — every live or trashed file with their id on it, plus
 * the prior versions of their files. The one exclusion is a file the system
 * made for its own use (File::internal_sources(), the sealed search index):
 * the member cannot see it or free it, so it cannot be theirs to pay for.
 * Each logical file bills its full size even when its bytes are deduped onto a
 * shared blob: dedup saves disk, not quota. Version bytes bill the FILE's
 * owner, not whoever saved the version.
 *
 * The total is a SUM computed when asked (sum_for_user), never a running
 * counter — files arrive through every subsystem, most of which know nothing
 * about Drive, and a SUM cannot drift. recompute() persists that SUM to the row,
 * which Drive's own mutations keep fresh; the meter and the gates read the live
 * SUM, so an attachment that arrived a second ago already counts.
 *
 * @version 1.2.0
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
	 * What a member's files weigh right now — the live SUM, no row read or
	 * written, so it is safe on a GET (the meter) and always current.
	 */
	public static function current_bytes($user_id) {
		return self::sum_for_user($user_id);
	}

	/**
	 * The SUM itself: head blobs of every live or trashed file the member owns
	 * (trash counts until purged; a permanently deleted file is already gone
	 * from fil_files), minus the system's own internal sources, plus the prior
	 * versions of their files. Runs inside whatever transaction the caller
	 * holds (it opens none), so the quota gate can read it under its lock.
	 */
	public static function sum_for_user($user_id) {
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		$user_id = (int)$user_id;
		$dblink = DbConnector::get_instance()->get_db_link();

		// NULL-source rows (legacy files) are the member's too, and NOT IN never
		// matches NULL, so they need the explicit OR.
		$internal = File::internal_sources();
		$not_internal = '';
		$params = array($user_id);
		if (!empty($internal)) {
			$not_internal = ' AND (f.fil_source IS NULL OR f.fil_source NOT IN (' . implode(',', array_fill(0, count($internal), '?')) . '))';
			$params = array_merge($params, $internal);
		}

		$q = $dblink->prepare(
			"SELECT COALESCE(SUM(b.fbb_size_bytes), 0)
			   FROM fil_files f
			   JOIN fbb_file_blobs b ON b.fbb_file_blob_id = f.fil_fbb_file_blob_id
			  WHERE f.fil_usr_user_id = ?" . $not_internal);
		$q->execute($params);
		$total = (int)$q->fetchColumn();

		// Version bytes bill the FILE's owner (fvr_usr_user_id records who saved
		// the version — audit only, an editor may have saved it). The table only
		// exists once the versioning phase has shipped; guard so a fresh install
		// still sums.
		if (self::_table_exists('fvr_file_versions')) {
			$qv = $dblink->prepare(
				"SELECT COALESCE(SUM(v.fvr_size_bytes), 0)
				   FROM fvr_file_versions v
				   JOIN fil_files f ON f.fil_file_id = v.fvr_fil_file_id
				  WHERE f.fil_usr_user_id = ?");
			$qv->execute(array($user_id));
			$total += (int)$qv->fetchColumn();
		}
		return $total;
	}

	/**
	 * Recompute a user's total and persist it to their row. Drive's own
	 * mutations call this so the row stays a faithful record; the gates and the
	 * meter read sum_for_user() directly. Returns the fresh byte total.
	 */
	public static function recompute($user_id) {
		$user_id = (int)$user_id;
		$total = self::sum_for_user($user_id);
		$usage = self::for_user($user_id);
		$usage->set('dru_bytes_used', $total);
		$usage->set('dru_update_time', gmdate('Y-m-d H:i:s'));
		$usage->save();
		return $total;
	}

	/**
	 * What every member's files weigh together, as stored — the admin widget on
	 * the Drive page. Counted over BLOBS, not files, so a deduped byte is
	 * counted once: this is disk, not quota. Split by where the bytes live,
	 * because a blob offloaded to the cloud bucket takes no room on this server.
	 *
	 * @return array{bytes_total:int, bytes_local:int, bytes_cloud:int, files:int}
	 */
	public static function site_totals() {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->query(
			"SELECT COALESCE(SUM(fbb_size_bytes), 0) AS total,
			        COALESCE(SUM(CASE WHEN fbb_storage_driver = 'cloud' THEN fbb_size_bytes ELSE 0 END), 0) AS cloud
			   FROM fbb_file_blobs
			  WHERE fbb_reference_count > 0");
		$row = $q->fetch(PDO::FETCH_ASSOC);
		$qf = $dblink->query("SELECT COUNT(*) FROM fil_files WHERE fil_delete_time IS NULL");
		return array(
			'bytes_total' => (int)$row['total'],
			'bytes_local' => (int)$row['total'] - (int)$row['cloud'],
			'bytes_cloud' => (int)$row['cloud'],
			'files'       => (int)$qf->fetchColumn(),
		);
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
