<?php
/**
 * PrunePendingSignups - Scheduled Task
 *
 * Deletes mailing list requests (MailingListPending) older than a
 * confirmation link's lifetime.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PrunePendingSignups implements ScheduledTaskInterface {

	public function run(array $config) {
		$q = DbConnector::get_instance()->get_db_link()->prepare("DELETE FROM mlp_mailing_list_pending
			WHERE mlp_request_time < now() - (? * interval '1 day')");
		$q->execute(array(MailingListPending::LIFETIME_DAYS));
		$n = $q->rowCount();
		return array('status' => 'success', 'message' => $n . ' expired request(s) deleted', 'run_chain' => $n > 0);
	}
}
