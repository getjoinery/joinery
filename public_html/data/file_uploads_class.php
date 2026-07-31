<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * FileUpload — pending-upload state for the resumable chunk protocol
 * (fup_file_uploads).
 *
 * drive_upload_init creates one and returns a raw token (only its SHA-256 is
 * stored). The chunk transport (PUT /api/v1/drive_upload/{token}) appends bytes
 * to a scratch part-file outside the web root; drive_upload_complete ingests the
 * assembled bytes into a File (or a new FileVersion) and deletes the row. Stale
 * rows + part-files are swept by DrivePurgeStaleUploads.
 *
 * @version 1.1.0
 */
class FileUpload extends SystemBase {
	public static $prefix = 'fup';
	public static $tablename = 'fup_file_uploads';
	public static $pkey_column = 'fup_file_upload_id';

	public static $permanent_delete_actions = array();

	protected static $foreign_key_actions = array(
		'fup_usr_user_id'   => array('action' => 'cascade'),
		'fup_fol_folder_id' => array('action' => 'null'),
		'fup_fil_file_id'   => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'fup_file_upload_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fup_token_sha256'   => array('type' => 'varchar(64)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'fup_usr_user_id'    => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'index' => true),
		'fup_fol_folder_id'  => array('type' => 'int8', 'is_nullable' => true),
		'fup_fil_file_id'    => array('type' => 'int8', 'is_nullable' => true),
		// What this upload is FOR (specs/chunked_upload_purposes.md). 'drive' is the
		// default so every existing row and caller stays correct; anything else is a
		// name registered in UploadPurposeRegistry, which supplies the policy. Recorded
		// at init so an upload cannot be opened as one kind and completed as another.
		'fup_purpose'        => array('type' => 'varchar(64)', 'is_nullable' => false, 'default' => 'drive'),
		'fup_display_name'   => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		'fup_mime_type'      => array('type' => 'varchar(128)', 'is_nullable' => true),
		'fup_expected_bytes' => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'fup_expected_sha256'=> array('type' => 'varchar(64)', 'is_nullable' => true),
		// Client-declared content modification time, settled at init and carried
		// onto the file at complete (see fil_content_modified_time). Plaintext
		// Drive uploads only.
		'fup_content_modified_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'fup_received_bytes' => array('type' => 'int8', 'is_nullable' => false, 'default' => 0, 'zero_on_create' => true),
		'fup_update_time'    => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'fup_create_time'    => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/** Directory (outside the web root) where scratch .part files live. */
	public static function scratch_dir() {
		$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/storage/drive_uploads';
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		return $dir;
	}

	/** Absolute path of this upload's scratch part-file. */
	public function part_path() {
		return self::scratch_dir() . '/' . (int)$this->key . '.part';
	}

	/** Load a pending upload by its raw token, or null. */
	public static function load_by_token($raw_token) {
		if (!is_string($raw_token) || $raw_token === '') {
			return null;
		}
		$hash = hash('sha256', $raw_token);
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT fup_file_upload_id FROM fup_file_uploads WHERE fup_token_sha256 = ? LIMIT 1");
		$q->execute(array($hash));
		$id = $q->fetchColumn();
		if ($id === false) {
			return null;
		}
		return new self((int)$id, true);
	}

	/** Delete the row and its scratch part-file. */
	public function discard() {
		$part = $this->part_path();
		if (is_file($part)) {
			@unlink($part);
		}
		$this->permanent_delete();
	}
}

class MultiFileUpload extends SystemMultiBase {
	protected static $model_class = 'FileUpload';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['fup_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['idle_before'])) {
			// literal timestamp string, safe (produced server-side).
			$filters['fup_update_time'] = "< " . DbConnector::get_instance()->get_db_link()->quote($this->options['idle_before']);
		}

		return $this->_get_resultsv2('fup_file_uploads', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
