<?php
/**
 * OfficeEditorSession — one file's current editing span.
 *
 * A span starts when the editor loads the file (GetFile) and ends with the
 * save it makes as the last person leaves (X-COOL-WOPI-IsExitSave), or with the
 * next load. Everyone editing the file together shares the one span — the
 * editor saves with whichever participant's token it picks, so a span keyed on
 * a token would turn every change of saver into a new version.
 *
 * The span's first save demotes the head it found to a Drive version; its
 * later saves (autosaves every few minutes) replace the head it wrote, so an
 * afternoon of typing is one entry in Version history (spec §4.3).
 *
 *   oes_fbb_file_blob_id the head blob this span wrote last, null before its
 *                        first save; a later save replaces the head only while
 *                        it is still this blob
 *   oes_refusal          why the last save was refused (conflict, quota,
 *                        too_large, read_only), null once one lands: the
 *                        editor reports a refused save to its page exactly as
 *                        it reports one that landed, so the page asks here
 *
 * Rows are bookkeeping and go with their file.
 *
 * @version 0.1.0
 */

class OfficeEditorSessionException extends SystemBaseException {}

class OfficeEditorSession extends SystemBase {
	public static $prefix = 'oes';
	public static $tablename = 'oes_office_editor_sessions';
	public static $pkey_column = 'oes_office_editor_session_id';

	protected static $foreign_key_actions = array(
		'oes_fil_file_id' => array('action' => 'permanent_delete'),
		// The blob the span last wrote: gone, the span has written nothing that
		// is still the head, which is what a null says.
		'oes_fbb_file_blob_id' => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'oes_office_editor_session_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'oes_fil_file_id'      => array('type' => 'int8', 'is_nullable' => false, 'unique' => true),
		'oes_fbb_file_blob_id' => array('type' => 'int8', 'is_nullable' => true),
		'oes_start_time'       => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'oes_last_save_time'   => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'oes_refusal'          => array('type' => 'varchar(32)', 'is_nullable' => true),
		'oes_refusal_time'     => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	/** Rows are the plugin's own bookkeeping, never written through the API. */
	function authenticate_write($data) {
		throw new SystemAuthenticationError('Editing sessions are not writable.');
	}

	/** The editor loaded $file_id: a new span starts. */
	public static function begin($file_id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"INSERT INTO oes_office_editor_sessions (oes_fil_file_id, oes_fbb_file_blob_id, oes_start_time, oes_last_save_time)
			 VALUES (?, NULL, NOW(), NULL)
			 ON CONFLICT (oes_fil_file_id) DO UPDATE
				SET oes_fbb_file_blob_id = NULL, oes_start_time = NOW(), oes_last_save_time = NULL,
				    oes_refusal = NULL, oes_refusal_time = NULL");
		$q->execute(array((int)$file_id));
	}

	/**
	 * The span on $file_id: the head blob it wrote last (0 before its first save).
	 * Null when no span is open (the editor loaded the file before this plugin
	 * kept spans, or the last one ended with an exit save).
	 *
	 * @return array{head_blob_id:int}|null
	 */
	public static function current($file_id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT oes_fbb_file_blob_id FROM oes_office_editor_sessions WHERE oes_fil_file_id = ?');
		$q->execute(array((int)$file_id));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return null;
		}
		return array('head_blob_id' => (int)$row['oes_fbb_file_blob_id']);
	}

	/** The span wrote $blob_id as the head. */
	public static function recordSave($file_id, $blob_id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"INSERT INTO oes_office_editor_sessions (oes_fil_file_id, oes_fbb_file_blob_id, oes_last_save_time)
			 VALUES (?, ?, NOW())
			 ON CONFLICT (oes_fil_file_id) DO UPDATE
				SET oes_fbb_file_blob_id = EXCLUDED.oes_fbb_file_blob_id, oes_last_save_time = NOW(),
				    oes_refusal = NULL, oes_refusal_time = NULL");
		$q->execute(array((int)$file_id, (int)$blob_id));
	}

	/** A save of $file_id was refused, for $reason. */
	public static function recordRefusal($file_id, $reason) {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"INSERT INTO oes_office_editor_sessions (oes_fil_file_id, oes_refusal, oes_refusal_time)
			 VALUES (?, ?, NOW())
			 ON CONFLICT (oes_fil_file_id) DO UPDATE
				SET oes_refusal = EXCLUDED.oes_refusal, oes_refusal_time = NOW()");
		$q->execute(array((int)$file_id, (string)$reason));
	}

	/** Why the last save of $file_id was refused, or null when the last one landed. */
	public static function refusal($file_id) {
		return self::saveState($file_id)['refusal'];
	}

	/**
	 * The last save the site answered for $file_id: why it was refused (null
	 * when it landed), and a marker that changes with every answered save.
	 *
	 * @return array{refusal:?string, marker:string}
	 */
	public static function saveState($file_id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT oes_refusal, oes_last_save_time, oes_refusal_time FROM oes_office_editor_sessions WHERE oes_fil_file_id = ?');
		$q->execute(array((int)$file_id));
		$row = $q->fetch(PDO::FETCH_ASSOC) ?: array();
		$refusal = (string)($row['oes_refusal'] ?? '');
		return array(
			'refusal' => $refusal === '' ? null : $refusal,
			'marker'  => (string)($row['oes_last_save_time'] ?? '') . '|' . (string)($row['oes_refusal_time'] ?? ''),
		);
	}

	/** The last person left: the span is over, the next load starts another. */
	public static function end($file_id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM oes_office_editor_sessions WHERE oes_fil_file_id = ?');
		$q->execute(array((int)$file_id));
	}
}

class MultiOfficeEditorSession extends SystemMultiBase {
	protected static $model_class = 'OfficeEditorSession';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['file_id'])) {
			$filters['oes_fil_file_id'] = array($this->options['file_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('oes_office_editor_sessions', $filters, $this->order_by, $only_count, $debug);
	}
}
