<?php
/**
 * API action: backup_download_start — build a whole-site backup to download.
 *
 * POST /api/v1/action/backup_download_start (browser session, superadmin,
 * after a step-up). No params.
 *
 * Starts BackupDownload's build in the background and answers the download's
 * state; the Backups page shows the Download button once it is ready. The
 * archive is sealed to this site's recovery key, so a site without a proven
 * one is refused, as is one whose disk cannot hold it.
 *
 * The whole site in one file is the most a session can take away, so it asks
 * for a fresh second factor (requires_stepup) as administration does.
 *
 * @version 1.0.0
 */

function backup_download_start_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Only a superadmin can download a backup of this site.');
	}
	if ($session->step_up_outstanding(null, 300)) {
		return LogicResult::error('Confirm it is you before building a backup to download.', array('requires_stepup' => true));
	}

	try {
		$state = BackupDownload::start((int)$session->get_user_id());
	} catch (BackupDownloadException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array(
		'status' => (string)$state['status'],
		'name'   => (string)$state['name'],
	));
}

function backup_download_start_logic_descriptor(): array {
	return [
		'description' => 'Build a whole-site backup, sealed to the recovery key, for a superadmin to download.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
			'min_user_permission'      => 10,
		],
		'input'       => [],
	];
}
