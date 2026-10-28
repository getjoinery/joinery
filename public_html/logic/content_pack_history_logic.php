<?php
/**
 * API action: content_pack_history — the content packs checked and applied on
 * this site, newest first, each with what it wrote.
 *
 * POST /api/v1/action/content_pack_history (browser session, superadmin).
 *
 * See specs/content_pack_feature.md.
 *
 * @version 1.0
 */

function content_pack_history_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if (!Access::decide(10, $session)) {
		return LogicResult::error('Only a superadmin reads the content pack history.');
	}

	// Every column but the stored zip, which can be megabytes a row and is
	// read only when one upload is opened.
	$rows = DbConnector::get_instance()->get_db_link()->query(
		'SELECT cpa_content_pack_apply_id, cpa_pack_name, cpa_pack_version, cpa_source, cpa_status, cpa_usr_user_id,
			cpa_create_time, cpa_applied_time, cpa_file_sha256, cpa_result, cpa_error
		 FROM cpa_content_pack_applies ORDER BY cpa_content_pack_apply_id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
	$applies = array();
	foreach ($rows as $row) {
		$applies[] = array(
			'apply_id' => (int)$row['cpa_content_pack_apply_id'],
			'pack' => $row['cpa_pack_name'],
			'version' => $row['cpa_pack_version'],
			'source' => $row['cpa_source'],
			'status' => $row['cpa_status'],
			'user_id' => (int)$row['cpa_usr_user_id'],
			'checked' => LibraryFunctions::api_timestamp($row['cpa_create_time']),
			'applied' => LibraryFunctions::api_timestamp($row['cpa_applied_time']),
			'file_sha256' => $row['cpa_file_sha256'],
			'written' => json_decode((string)$row['cpa_result'], true) ?: array(),
			'error' => $row['cpa_error'],
		);
	}
	return LogicResult::render(array('applies' => $applies));
}

function content_pack_history_logic_descriptor(): array {
	return [
		'description' => 'List the content packs checked and applied on this site, with each apply\'s before and after values.',
		'mutates'     => false,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
			'min_user_permission'      => 10,
		],
		'input'       => [],
	];
}
