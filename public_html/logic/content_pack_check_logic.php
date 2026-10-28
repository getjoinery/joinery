<?php
/**
 * API action: content_pack_check — read an uploaded content pack and say what
 * applying it would do.
 *
 * POST /api/v1/action/content_pack_check (browser session, superadmin),
 * multipart with the zip as the file field `pack`. The admin page
 * (/admin/admin_content_packs) calls the same function with its own upload.
 *
 * Validates the pack, keeps it in a new apply record (status 'checked') and
 * answers that record's id and the plan. Writes nothing else. A refused pack
 * is answered with the reason and leaves no record.
 *
 * See specs/content_pack_feature.md.
 *
 * @version 1.0
 */

function content_pack_check_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if (!Access::decide(10, $session)) {
		return LogicResult::error('Only a superadmin checks a content pack.');
	}

	$file = $_FILES['pack'] ?? null;
	$tmp = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';
	if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
		return LogicResult::error('Choose a content pack (a .zip file) to check.');
	}
	if ((int)$file['size'] > ContentPack::MAX_ZIP_BYTES) {
		return LogicResult::error('A content pack is at most 2 MB.');
	}

	return content_pack_check_bytes((string)file_get_contents($tmp), $session);
}

/**
 * Read a pack's zip bytes, keep them in a new apply record and plan it. The
 * upload's own handling is content_pack_check_logic()'s; this is the rest,
 * which tests call directly.
 */
function content_pack_check_bytes(string $bytes, $session): LogicResult {
	try {
		$pack = ContentPack::read($bytes);
		$plan = $pack->plan();
	} catch (ContentPackRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	$apply = new ContentPackApply(NULL);
	$apply->set('cpa_usr_user_id', (int)$session->get_user_id());
	$apply->set('cpa_pack_name', $pack->name());
	$apply->set('cpa_pack_version', $pack->version());
	$apply->set('cpa_file_sha256', hash('sha256', $bytes));
	$apply->set('cpa_file', base64_encode($bytes));
	$apply->set('cpa_status', ContentPackApply::STATUS_CHECKED);
	$apply->save();
	$apply->load();

	return LogicResult::render(array(
		'apply_id' => (int)$apply->key,
		'plan' => $plan,
	));
}

function content_pack_check_logic_descriptor(): array {
	return [
		'description' => 'Check an uploaded content pack (multipart file field "pack") and return what applying it would change. Writes only the record of the upload.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
			'min_user_permission'      => 10,
		],
		'input'       => [],
	];
}
