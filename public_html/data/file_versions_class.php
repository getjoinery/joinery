<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * FileVersion — prior content of a Drive file (fvr_file_versions).
 *
 * Each row pins one historical blob for a file. Saving new content demotes the
 * current head blob to a version and repoints the file at the freshly ingested
 * blob; restoring swaps the roles back. Blob reference counts are code-managed
 * here (the blob-layer contract): a version row IS a reference to its blob, so
 * demotion transfers the head's reference to the new version (no count change),
 * and pruning / permanent delete release it.
 *
 * @version 1.0.0
 */
class FileVersion extends SystemBase {
	public static $prefix = 'fvr';
	public static $tablename = 'fvr_file_versions';
	public static $pkey_column = 'fvr_file_version_id';

	public static $permanent_delete_actions = array();

	// When the file is permanently deleted, each version is permanent-deleted too
	// (so its blob reference is released, below). The saver reference is audit
	// only — anonymize it rather than cascade-deleting history.
	protected static $foreign_key_actions = array(
		'fvr_fil_file_id' => array('action' => 'permanent_delete'),
		'fvr_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	public static $field_specifications = array(
		'fvr_file_version_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fvr_fil_file_id'     => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true),
		'fvr_fbb_file_blob_id'=> array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'fvr_version_number'  => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'unique_with' => array('fvr_fil_file_id')),
		'fvr_usr_user_id'     => array('type' => 'int4', 'is_nullable' => false, 'required' => true),
		'fvr_size_bytes'      => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'fvr_create_time'     => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/**
	 * Release this version's blob reference, then delete the row. The blob↔version
	 * relationship is code-managed (five-segment FK, no auto rule), so it must be
	 * released explicitly on any permanent delete.
	 */
	public function permanent_delete($debug = false) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		$blob_id = (int)$this->get('fvr_fbb_file_blob_id');
		if ($blob_id > 0) {
			FileBlob::release($blob_id);
		}
		return parent::permanent_delete($debug);
	}

	// ------------------------------------------------------------------
	// Version lifecycle (transactional, per the ContentVersion house pattern)
	// ------------------------------------------------------------------

	/**
	 * Save new content: demote the file's current head blob to a version and
	 * repoint the file at $new_blob (already ingested; its refcount already
	 * counts the new head reference). Then prune to the owner's retained depth.
	 */
	public static function save_new_content($file, $new_blob, $user_id) {
		$head_blob_id = (int)$file->get('fil_fbb_file_blob_id');
		$head_size = self::_blob_size($head_blob_id);

		DbConnector::BeginTransaction();
		try {
			$v = new self(NULL);
			$v->set('fvr_fil_file_id', $file->key);
			$v->set('fvr_fbb_file_blob_id', $head_blob_id);
			$v->set('fvr_version_number', self::_next_version_number($file->key));
			$v->set('fvr_usr_user_id', (int)$user_id);
			$v->set('fvr_size_bytes', $head_size);
			$v->save();

			// The version now holds the head blob's reference; the file adopts the
			// new blob (which already counts this reference). No retain/release: the
			// reference is transferred, so the blob refcounts stay correct.
			$file->set('fil_fbb_file_blob_id', (int)$new_blob->key);
			$file->save();

			DbConnector::Commit();
		} catch (Exception $e) {
			DbConnector::Rollback();
			throw $e;
		}

		self::prune($file, (int)$file->get('fil_usr_user_id'));
	}

	/**
	 * Restore $version's content as the file's head. The current head is demoted
	 * to a fresh version; the restored blob is promoted to head and the restored
	 * version row is consumed.
	 */
	public static function restore_version($file, $version, $user_id) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		$head_blob_id = (int)$file->get('fil_fbb_file_blob_id');
		$head_size = self::_blob_size($head_blob_id);
		$restored_blob_id = (int)$version->get('fvr_fbb_file_blob_id');

		DbConnector::BeginTransaction();
		try {
			// Demote the current head to a new version (takes over head's reference).
			$nv = new self(NULL);
			$nv->set('fvr_fil_file_id', $file->key);
			$nv->set('fvr_fbb_file_blob_id', $head_blob_id);
			$nv->set('fvr_version_number', self::_next_version_number($file->key));
			$nv->set('fvr_usr_user_id', (int)$user_id);
			$nv->set('fvr_size_bytes', $head_size);
			$nv->save();

			// Promote the restored blob to head: the file gains a reference to it,
			// then the consumed version row releases its own — net-neutral, but it
			// keeps the blob alive across the row deletion.
			FileBlob::retain($restored_blob_id);
			$file->set('fil_fbb_file_blob_id', $restored_blob_id);
			$file->save();
			FileBlob::release($restored_blob_id);
			self::_delete_row((int)$version->key);

			DbConnector::Commit();
		} catch (Exception $e) {
			DbConnector::Rollback();
			throw $e;
		}

		self::prune($file, (int)$file->get('fil_usr_user_id'));
	}

	/**
	 * Prune to the owner's retained depth (drive_versioning_depth, default 0):
	 * keep the newest N versions, release+delete the rest (MAX-cap style).
	 */
	public static function prune($file, $owner_id) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
		$depth = (int)SubscriptionTier::getUserFeature($owner_id, 'drive_versioning_depth', 0);
		if ($depth < 0) {
			$depth = 0;
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT fvr_file_version_id, fvr_fbb_file_blob_id
			   FROM fvr_file_versions
			  WHERE fvr_fil_file_id = ?
			  ORDER BY fvr_version_number DESC");
		$q->execute(array((int)$file->key));
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$i = 0;
		foreach ($rows as $row) {
			if ($i < $depth) { $i++; continue; }
			FileBlob::release((int)$row['fvr_fbb_file_blob_id']);
			self::_delete_row((int)$row['fvr_file_version_id']);
		}
	}

	private static function _blob_size($blob_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT fbb_size_bytes FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
		$q->execute(array((int)$blob_id));
		return (int)$q->fetchColumn();
	}

	private static function _next_version_number($file_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT COALESCE(MAX(fvr_version_number), 0) + 1 FROM fvr_file_versions WHERE fvr_fil_file_id = ?");
		$q->execute(array((int)$file_id));
		return (int)$q->fetchColumn();
	}

	private static function _delete_row($version_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("DELETE FROM fvr_file_versions WHERE fvr_file_version_id = ?");
		$q->execute(array((int)$version_id));
	}
}

class MultiFileVersion extends SystemMultiBase {
	protected static $model_class = 'FileVersion';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['file_id'])) {
			$filters['fvr_fil_file_id'] = array($this->options['file_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('fvr_file_versions', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
