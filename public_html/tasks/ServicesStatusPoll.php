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
 * @version 1.1 - the paid period's own row, from the notice window out (not for a date that renews on its
 *               own): the date or the grace end, and the two doors, the site's own setup and the offer on sale;
 *               a banner whose every date renews carries no date and a row saying they renew; past the grace
 *               end the row says they stopped (specs/services_phase3_selling_nudge.md §2.1)
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
		$grace_ends = '';
		$renews = false;
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
			// The banner carries one date: the nearest paid-through day that
			// does not renew on its own. One that renews advances before it
			// arrives, so it is no deadline.
			$paid = trim((string)($s['paid_until'] ?? ''));
			$renews = $renews || !empty($s['renews']);
			if ($paid !== '' && empty($s['renews']) && ($until === '' || $paid < $until)) {
				$until = $paid;
				$grace_ends = trim((string)($s['grace_ends'] ?? ''));
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
		$period = self::periodRow($until, $grace_ends, (int)($status['notice_days'] ?? 21),
			is_array($status['offer'] ?? null) ? $status['offer'] : null);
		if ($period !== null) {
			$rows[] = $period;
		} elseif ($renews && $until === '') {
			// Said in so many words, rather than left for the banner to infer
			// from a missing date (which a cleared date also produces).
			$rows[] = array('label' => 'getjoinery email and backups', 'used' => 'renew on their own with your subscription',
				'allowance' => '', 'percent' => 0, 'action_label' => '', 'action_url' => '');
		}
		self::put('hosted_plan_state', HostedPlanNotice::STATE_SERVICES);
		self::put('hosted_plan_until_time', $until);
		self::put('hosted_plan_notice', implode(' ', $notices));
		self::put('hosted_plan_allowances', json_encode($rows));
		self::put('hosted_plan_manage_url', $manage);
		return count($rows);
	}

	/**
	 * The paid period's row, from the notice window before the date and
	 * through grace, or null outside it. Its two doors: the site's own setup
	 * (its Email and Backups steps take an account of the owner's own), and
	 * the offer on sale, at the product's price.
	 */
	public static function periodRow(string $until, string $grace_ends, int $notice_days, ?array $offer, ?int $now = null): ?array {
		$until = trim($until);
		if ($until === '') {
			return null;
		}
		$now = $now ?? time();
		$stamp = strtotime($until . ' UTC');
		if ($stamp === false || $stamp - $now > max(0, $notice_days) * 86400) {
			return null;
		}
		$on = self::day($until);
		$grace_stamp = $grace_ends !== '' ? strtotime($grace_ends . ' UTC') : false;
		if ($grace_stamp !== false && $grace_stamp <= $now) {
			$used = 'stopped on ' . self::day($grace_ends) . ', after the date (' . $on . ') passed';
		} elseif ($stamp <= $now) {
			$ends = $grace_ends !== '' ? self::day($grace_ends) : '';
			$used = 'ended on ' . $on . ($ends !== '' ? '; email and backups stop on ' . $ends : '');
		} else {
			$used = 'paid through ' . $on;
		}
		$host = ServicesClient::host();
		$offer_label = '';
		$offer_url = '';
		if ($offer !== null && trim((string)($offer['price'] ?? '')) !== '') {
			$offer_label = 'keep going, ' . trim((string)$offer['price']);
			$offer_url = trim((string)($offer['url'] ?? ''));
		}
		return array(
			'label'        => 'getjoinery email and backups',
			'used'         => $used,
			'allowance'    => '',
			'percent'      => 0,
			'due'          => true,
			'urgent'       => $stamp <= $now,
			'action_label' => $host !== '' ? 'use your own accounts' : '',
			'action_url'   => $host !== '' ? 'https://' . $host . '/setup' : '',
			'second_label' => $offer_label,
			'second_url'   => $offer_url,
		);
	}

	/** "November 14", in the site's own time zone. */
	private static function day(string $utc): string {
		$local = LibraryFunctions::convert_time($utc, 'UTC', SessionControl::get_instance()->get_timezone(), 'F j');
		return $local ?: gmdate('F j', (int)strtotime($utc . ' UTC'));
	}

	private static function put(string $name, string $value): void {
		Setting::put($name, $value);
		Globalvars::get_instance()->forget_setting($name);
	}
}
