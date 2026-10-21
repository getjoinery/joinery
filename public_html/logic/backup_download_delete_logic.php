<?php
/**
 * API action: backup_download_delete — delete the backup built for download.
 *
 * POST /api/v1/action/backup_download_delete (browser session, superadmin).
 * No params. Removes the download, ready or failed, from this server before
 * its day is up; refused while it is still being built.
 *
 * @version 1.0.0
 */

function backup_download_delete_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Only a superadmin can delete the backup built for download.');
	}

	try {
		BackupDownload::discard();
	} catch (BackupDownloadException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array('deleted' => true));
}

function backup_download_delete_logic_descriptor(): array {
	return [
		'description' => 'Delete the whole-site backup built for download from this server.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
			'min_user_permission'      => 10,
		],
		'input'       => [],
	];
}
