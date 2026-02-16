<?php
/**
 * SendQueuedEmails - Scheduled Task
 *
 * Sends any bulk emails that are queued and past their scheduled date.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('data/emails_class.php'));

class SendQueuedEmails implements ScheduledTaskInterface {

	public function run(array $config) {
		// Check if there are any queued emails before doing work
		$queued = new MultiEmail(array('scheduleddate' => MultiEmail::SCHEDULED_PAST, 'status' => Email::EMAIL_QUEUED));
		$count = $queued->count_all();

		if ($count === 0) {
			return array('status' => 'success', 'message' => 'No queued emails to send');
		}

		define('SCHEDULED_TASK_CONTEXT', true);

		ob_start();
		require(PathHelper::getIncludePath('adm/admin_emails_send.php'));
		$output = ob_get_clean();

		return array('status' => 'success', 'message' => 'Processed ' . $count . ' queued email(s). ' . trim(strip_tags($output)));
	}
}
