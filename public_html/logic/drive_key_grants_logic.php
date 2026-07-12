<?php

/**
 * drive_key_grants — fetch the calling user's own wrapped file keys for a set of
 * encrypted files. The browser calls this when it needs a file key it doesn't
 * already hold from a listing (e.g. opening a deep-linked file). Returns only the
 * caller's own key material (their FileKeyGrant rows) — never another user's.
 *
 * Input `file_ids`: a list of file ids. Response `keys`: { file_id:
 * wrapped_file_key } for the files the caller holds a key grant on.
 */

function drive_key_grants_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/file_key_grants_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$raw = isset($input['file_ids']) && is_array($input['file_ids']) ? $input['file_ids'] : array();
	$ids = array();
	foreach ($raw as $fid) {
		$fid = (int)$fid;
		if ($fid > 0) { $ids[] = $fid; }
	}

	$map = FileKeyGrant::wrapped_keys_for_user($ids, $user_id);
	// Normalize keys to strings for a stable JSON object shape.
	$keys = array();
	foreach ($map as $fid => $blob) {
		$keys[(string)$fid] = $blob;
	}

	return LogicResult::render(array('ok' => true, 'keys' => $keys));
}

function drive_key_grants_logic_descriptor(): array {
	return array(
		'description'      => 'Fetch the calling user\'s own wrapped file keys for encrypted Drive files. Body carries `file_ids` (a list); validated in the logic and passed through the boundary untouched.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(),
	);
}
?>
