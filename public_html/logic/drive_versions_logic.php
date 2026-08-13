<?php

/**
 * drive_versions — list a Drive file's saved versions (newest first). Read-only.
 * The head (current content) is not a version row; it is shown separately by the
 * UI.
 */

function drive_versions_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/file_versions_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$file_id = (int)($input['file_id'] ?? 0);
	$file = DriveHelper::load_file($file_id);
	if (!$file) {
		return LogicResult::error('File not found.');
	}
	if (!DriveHelper::can_read(DriveHelper::ENTITY_FILE, $file, $user_id, $session->get_permission())) {
		return LogicResult::error('You do not have access to that file.');
	}

	$versions = new MultiFileVersion(array('file_id' => $file_id), array('fvr_version_number' => 'DESC'));
	$versions->load();

	// One query for every version's content hash rather than a blob load per
	// row: a file with fifty versions is one round trip either way, and this is
	// on the path a sync client walks for every file it is reconciling.
	$hashes = array();
	$blob_ids = array();
	foreach ($versions as $v) {
		$blob_ids[] = (int)$v->get('fvr_fbb_file_blob_id');
	}
	if (!empty($blob_ids)) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$marks = implode(',', array_fill(0, count($blob_ids), '?'));
		$q = $dblink->prepare("SELECT fbb_file_blob_id, fbb_sha256 FROM fbb_file_blobs WHERE fbb_file_blob_id IN ($marks)");
		$q->execute($blob_ids);
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$hashes[(int)$row['fbb_file_blob_id']] = $row['fbb_sha256'];
		}
	}

	$out = array();
	foreach ($versions as $v) {
		$blob_id = (int)$v->get('fvr_fbb_file_blob_id');
		$out[] = array(
			'version_id'     => (int)$v->key,
			'version_number' => (int)$v->get('fvr_version_number'),
			'size'           => (int)$v->get('fvr_size_bytes'),
			'create_time'    => $v->get('fvr_create_time'),
			'saved_by'       => (int)$v->get('fvr_usr_user_id'),
			// The identity of what this version holds, matching the head's
			// content_sha256 in the same domain: plaintext bytes for a plaintext
			// file, ciphertext bytes for an encrypted one. Without it a client
			// can list a file's history but cannot tell which entry holds the
			// content it is looking for.
			'content_sha256' => isset($hashes[$blob_id]) ? $hashes[$blob_id] : null,
		);
	}

	return LogicResult::render(array('ok' => true, 'file_id' => $file_id, 'versions' => $out));
}

function drive_versions_logic_descriptor(): array {
	return array(
		'description'      => 'List a Drive file\'s saved versions, newest first. Each carries content_sha256 — the identity of the bytes that version holds, in the same domain as the head file export (plaintext for a plaintext file, ciphertext for an encrypted one) — so a client can tell which entry holds the content it is looking for.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'file_id' => array('type' => 'int', 'required' => true, 'label' => 'File id'),
		),
	);
}
?>
