<?php
/**
 * SendCampaigns - Scheduled Task
 *
 * Sends queued campaigns through CampaignSender::send_due().
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class SendCampaigns implements ScheduledTaskInterface {

	public function run(array $config) {
		$totals = CampaignSender::send_due();
		if ($totals['held'] > 0) {
			return array('status' => 'skipped', 'message' => $totals['held'] . ' campaign(s) held: ' . CampaignRules::REFUSAL);
		}
		if ($totals['emails'] === 0) {
			return array('status' => 'success', 'message' => 'No queued campaigns', 'run_chain' => false);
		}
		return array('status' => 'success', 'message' => $totals['emails'] . ' campaign(s): ' . $totals['sent'] . ' sent, '
			. $totals['failed'] . ' failed, ' . $totals['skipped'] . ' no longer subscribed');
	}
}
