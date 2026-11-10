<?php
/**
 * MailingListSyncTask - Scheduled Task
 *
 * Runs MailingListSync once a day. Skipped while no provider is configured.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class MailingListSyncTask implements ScheduledTaskInterface {

	public function run(array $config) {
		if (!MailingListService::getProvider()) {
			return array('status' => 'skipped', 'message' => 'No mailing list provider is configured.');
		}
		$result = MailingListSync::run(false);
		return array('status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']);
	}
}
