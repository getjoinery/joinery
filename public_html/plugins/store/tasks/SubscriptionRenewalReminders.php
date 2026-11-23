<?php
/**
 * SubscriptionRenewalReminders - Scheduled Task
 *
 * Emails each subscriber whose subscription renews within the configured
 * number of days, once per renewal (SubscriptionEmails::remind_due()). Which
 * plans are reminded, or none, is the store setting store_renewal_reminders.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class SubscriptionRenewalReminders implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

	public function run(array $config) {
		if (SubscriptionEmails::reminder_mode() === '') {
			return array('status' => 'skipped', 'message' => 'Renewal reminders are switched off in the store settings.');
		}
		$result = SubscriptionEmails::remind_due($this->days($config));
		if ($result['sent'] === 0 && $result['failed'] === 0) {
			return array('status' => 'success', 'message' => 'No renewal is due a reminder.');
		}
		return array(
			'status'  => $result['failed'] > 0 ? 'error' : 'success',
			'message' => $result['sent'] . ' reminder(s) sent'
				. ($result['failed'] > 0 ? ', ' . $result['failed'] . ' could not be sent and are tried again next run' : '') . '.',
		);
	}

	public function dryRun(array $config) {
		if (SubscriptionEmails::reminder_mode() === '') {
			return array('status' => 'skipped', 'message' => 'Renewal reminders are switched off in the store settings.');
		}
		$due = SubscriptionEmails::remind_due($this->days($config), true)['due'];
		$html = '';
		foreach ($due as $row) {
			$html .= '<li>' . htmlspecialchars($row, ENT_QUOTES, 'UTF-8') . '</li>';
		}
		return array(
			'status'  => 'success',
			'message' => 'Would send ' . count($due) . ' reminder(s).',
			'html'    => $html === '' ? '' : '<ul>' . $html . '</ul>',
		);
	}

	private function days(array $config): int {
		$days = (int)($config['days_ahead'] ?? 0);
		return $days > 0 ? $days : SubscriptionEmails::REMINDER_DAYS;
	}
}
