<?php
/**
 * ServicesStatusPoll - Scheduled Task
 *
 * The daily status poll for a site connected to getjoinery
 * (specs/services_phase2_platform.md §9): one status call over the
 * connected key, and the answer written into the five banner settings so
 * HostedPlanNotice renders it exactly as it renders a Managed site's pushed
 * values — under the `services` state: the date, the allowance rows with
 * the first door at 80%, the notice, the manage link, and no billing
 * sentence.
 *
 * Skips silently on a site that is not connected. A site with no service
 * rows (connected, never enrolled) clears the banner rather than leaving a
 * stale one. An unreachable operator leaves the banner as it was: one
 * missed day is not news.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ServicesStatusPoll implements ScheduledTaskInterface {

	public function run(array $config) {
		if (!ServicesClient::connected()) {
			return array('status' => 'skipped', 'message' => 'Not connected to getjoinery.');
		}
		try {
			$status = (new ServicesClient())->status();
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => 'getjoinery did not answer: ' . $e->getMessage());
		}
		$written = self::writeBanner($status);
		return array('status' => 'success', 'message' => $written === 0
			? 'Connected, no services held; the banner is clear.'
			: $written . ' service(s) on the banner.');
	}

	/**
	 * The five banner settings from a status answer. Returns how many
	 * services are on the banner (0 clears it).
	 */
	public static function writeBanner(array $status): int {
		$services = is_array($status['services'] ?? null) ? $status['services'] : array();
		$rows = array();
		$until = '';
		$notices = array();
		$manage = trim((string)($status['manage_url'] ?? ''));
		foreach ($services as $s) {
			if (!is_array($s) || in_array((string)($s['state'] ?? ''), array('unpaid', 'released'), true)) {
				continue;
			}
			$rows[] = array(
				'label'        => (string)($s['label'] ?? ''),
				'used'         => (string)($s['used_label'] ?? ''),
				'allowance'    => (string)($s['allowance_label'] ?? ''),
				'percent'      => (int)($s['percent'] ?? 0),
				// The first door and nothing else in this phase: the referral
				// link renders only at 80%, and only over https (HostedPlanNotice).
				'action_label' => (string)($s['action_label'] ?? ''),
				'action_url'   => (string)($s['action_url'] ?? ''),
			);
			// The banner carries one date: the nearest paid-through day.
			$paid = trim((string)($s['paid_until'] ?? ''));
			if ($paid !== '' && ($until === '' || $paid < $until)) {
				$until = $paid;
			}
			$notice = trim((string)($s['notice'] ?? ''));
			if ($notice !== '' && !in_array($notice, $notices, true)) {
				$notices[] = $notice;
			}
		}
		if (!$rows) {
			self::put('hosted_plan_state', '');
			self::put('hosted_plan_until_time', '');
			self::put('hosted_plan_notice', '');
			self::put('hosted_plan_allowances', '');
			self::put('hosted_plan_manage_url', '');
			return 0;
		}
		self::put('hosted_plan_state', HostedPlanNotice::STATE_SERVICES);
		self::put('hosted_plan_until_time', $until);
		self::put('hosted_plan_notice', implode(' ', $notices));
		self::put('hosted_plan_allowances', json_encode($rows));
		self::put('hosted_plan_manage_url', $manage);
		return count($rows);
	}

	private static function put(string $name, string $value): void {
		Setting::put($name, $value);
		Globalvars::get_instance()->forget_setting($name);
	}
}
