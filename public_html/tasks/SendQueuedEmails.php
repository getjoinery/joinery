<?php
/**
 * SendQueuedEmails - Scheduled Task
 *
 * Sends any bulk emails that are queued and past their scheduled date.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class SendQueuedEmails implements ScheduledTaskInterface {

	public function run(array $config) {
		define('SCHEDULED_TASK_CONTEXT', true);

		ob_start();
		require(PathHelper::getIncludePath('adm/admin_emails_send.php'));
		$output = ob_get_clean();

		return array('status' => 'success', 'message' => strip_tags($output));
	}
}
