<?php
/**
 * ServicesNudge — the reminder emails to a self-hosted site's owner about the
 * getjoinery services it rents (specs/services_phase3_selling_nudge.md §2.3).
 *
 * Each says what happened in one sentence, names the two ways to keep going in
 * this order — the owner's own account first, then paying getjoinery at what
 * is on sale — and says that the site keeps working. Nothing else. At most,
 * per site:
 *
 *   warn / full   one at 80% and one at 100% of an allowance, per service per
 *                 month. The second door is not named here: there is no bigger
 *                 plan to sell.
 *   due           one when the paid-through date comes inside the notice
 *                 window (server_manager_services_notice_days).
 *   ended         one when the date has passed (grace has started).
 *   stopped       one when the grace ran out and the services stopped.
 *
 * The three date emails are never sent for a date that renews on its own (a
 * subscription not cancelled): it advances before it arrives. A quiet
 * customer whose payments go through gets none.
 *
 * Each event has a key (event, service, and the month or the date it is
 * about), written on every row of the site once sent (svt_nudged), so a pass
 * that runs every minute sends each once. A Managed site moved to its own
 * account (a node-linked row) is told by ServiceTenantWatch's own two emails.
 *
 * @version 1.1 - a site paid for and never switched on is owed the date reminders too (reviewer2 B3)
 * @version 1.0
 */
class ServicesNudge {

	const TEMPLATE = 'services_nudge';

	/** Send every reminder owed across these rows. Returns how many were sent. */
	public static function sendDue($rows, string $now): int {
		$sites = array();
		foreach ($rows as $row) {
			if ($row->is_node_linked() || (int)$row->get('svt_apk_api_key_id') <= 0) {
				continue;
			}
			// Paid but never switched on (unpaid with a date: the owner did not
			// press Save or Store after buying) is still owed the date ones.
			$state = (string)$row->get('svt_state');
			$paid_idle = $state === ServiceTenant::STATE_UNPAID && trim((string)$row->get('svt_paid_until')) !== '';
			if ($state !== ServiceTenant::STATE_ACTIVE && $state !== ServiceTenant::STATE_SUSPENDED && !$paid_idle) {
				continue;
			}
			$sites[(int)$row->get('svt_apk_api_key_id')][] = $row;
		}
		$sent = 0;
		foreach ($sites as $site_rows) {
			foreach (self::owed($site_rows, $now) as $event) {
				if (self::send($site_rows, $event)) {
					$sent++;
				}
			}
		}
		return $sent;
	}

	/**
	 * The events owed for one site's rows and not yet sent, each as
	 * {key, subject, sentence, doors (html), keeps (sentence)}.
	 */
	public static function owed(array $rows, string $now): array {
		$events = array();
		$host = trim((string)$rows[0]->get('svt_host')) ?: 'your site';
		$month = substr($now, 0, 7);

		foreach ($rows as $row) {
			if ((string)$row->get('svt_state') !== ServiceTenant::STATE_ACTIVE) {
				continue;
			}
			$s = JoineryServices::statusOf($row);
			$kind = $s['percent'] >= 100 ? 'full' : ($s['percent'] >= JoineryServices::WARN_PERCENT ? 'warn' : '');
			if ($kind !== '') {
				$events[] = self::allowanceEvent($row, $s, $kind, $host, $month);
			}
		}

		$date = self::dateEvent($rows, $host, $now);
		if ($date !== null) {
			$events[] = $date;
		}

		$sent = self::sentKeys($rows);
		return array_values(array_filter($events, function ($e) use ($sent) {
			return !isset($sent[$e['key']]);
		}));
	}

	private static function allowanceEvent(ServiceTenant $row, array $s, string $kind, string $host, string $month): array {
		$service = (string)$row->get('svt_service');
		$usage = $s['used_label'] . ' of ' . $s['allowance_label'];
		if ($service === ServiceTenant::SERVICE_MAIL) {
			$subject = $kind === 'full'
				? 'Email from ' . $host . ' through getjoinery is paused until the 1st'
				: 'Email from ' . $host . ' is at ' . JoineryServices::WARN_PERCENT . '% of this month\'s allowance';
			$sentence = $kind === 'full'
				? $host . ' has sent ' . $usage . ' emails through getjoinery this month, so sending through us is paused until the 1st.'
				: $host . ' has sent ' . $usage . ' emails through getjoinery this month.';
			$keeps = $kind === 'full'
				? 'The site itself keeps working, and mail through getjoinery starts again on the 1st.'
				: 'If it reaches ' . $s['allowance_label'] . ', sending through getjoinery pauses until the 1st; the site keeps working.';
			$own = self::link(JoineryServices::referralUrl($service), 'open your own SMTP2GO account')
				. ' and switch to it in your site\'s Email setup step';
		} else {
			$subject = $kind === 'full'
				? 'Offsite backups for ' . $host . ' have paused: backup storage is full'
				: 'Backups for ' . $host . ' are at ' . JoineryServices::WARN_PERCENT . '% of their storage';
			$sentence = 'Backups for ' . $host . ' use ' . $usage . ' of getjoinery backup storage'
				. ($kind === 'full' ? ', so new offsite backups have paused.' : '.');
			$keeps = 'Nothing already stored is deleted, and the backups on the site itself continue.';
			$own = self::link(JoineryServices::referralUrl($service), 'open a storage account of your own')
				. ' and switch to it in your site\'s Backups setup step';
		}
		return array(
			'key'      => $kind . ':' . $service . ':' . $month,
			'subject'  => $subject,
			'sentence' => $sentence,
			'doors'    => '<p>To keep going: ' . $own . '.</p>',
			'keeps'    => $keeps,
		);
	}

	/**
	 * The one date event owed for the site, or null: the nearest date among
	 * its rows that do not renew on their own.
	 */
	private static function dateEvent(array $rows, string $host, string $now): ?array {
		$until = '';
		$stopped = '';
		$grace_ends = '';
		foreach ($rows as $row) {
			$paid = trim((string)$row->get('svt_paid_until'));
			if ($paid === '' || JoineryServicesFulfillment::row_renews($row)) {
				continue;
			}
			if ($until === '' || $paid < $until) {
				$until = $paid;
				$grace_ends = (string)ServiceTenantLadder::graceEnds($row, 'svt_lapse_time', JoineryServices::graceDays());
			}
			if ((string)$row->get('svt_state') === ServiceTenant::STATE_SUSPENDED && $row->get('svt_revoked_time') !== null) {
				$stopped = substr((string)$row->get('svt_revoked_time'), 0, 10);
			}
		}
		if ($until === '') {
			return null;
		}
		$day = substr($until, 0, 10);
		$on = gmdate('F j', strtotime($until . ' UTC'));
		$doors = self::dateDoors($host);
		if ($stopped !== '') {
			return array(
				'key'      => 'stopped:' . $stopped,
				'subject'  => 'getjoinery email and backups for ' . $host . ' have stopped',
				'sentence' => 'getjoinery\'s email and backup storage for ' . $host . ' stopped today: the paid-through date ('
					. $on . ') passed and the grace period ran out. Backups already stored with us are kept '
					. ServiceTenant::RETENTION_DAYS . ' days and then deleted.',
				'doors'    => $doors,
				'keeps'    => 'The site itself keeps working, and its own local backups continue.',
			);
		}
		if ($until <= $now) {
			$ends = $grace_ends !== '' ? gmdate('F j', strtotime($grace_ends . ' UTC')) : '';
			return array(
				'key'      => 'ended:' . $day,
				'subject'  => 'getjoinery email and backups for ' . $host . ' need paying',
				'sentence' => 'getjoinery\'s email and backup storage for ' . $host . ' were paid through ' . $on
					. ($ends !== '' ? '; they keep working until ' . $ends . ' and then stop.' : ' and will stop soon.'),
				'doors'    => $doors,
				'keeps'    => 'The site itself keeps working whatever you choose.',
			);
		}
		if (strtotime($until . ' UTC') - strtotime($now . ' UTC') <= JoineryServices::noticeDays() * 86400) {
			return array(
				'key'      => 'due:' . $day,
				'subject'  => 'getjoinery email and backups for ' . $host . ' are paid through ' . $on,
				'sentence' => 'getjoinery\'s email and backup storage for ' . $host . ' are paid through ' . $on . '.',
				'doors'    => $doors,
				'keeps'    => 'The site itself keeps working whatever you choose.',
			);
		}
		return null;
	}

	/** The two doors for a date: own accounts through the site's setup, or the offer. */
	private static function dateDoors(string $host): string {
		$own = self::link('https://' . $host . '/setup', 'use your own email and storage accounts')
			. ' (your site\'s Email and Backups setup steps)';
		$offer = JoineryServices::offer();
		$out = '<p>Two ways to keep going: ' . $own;
		if ($offer !== null) {
			$out .= ', or ' . self::link((string)$offer['url'], 'keep going with getjoinery, ' . $offer['price']);
		}
		return $out . '.</p>';
	}

	private static function link(string $url, string $text): string {
		if (strpos($url, 'https://') !== 0) {
			return htmlspecialchars($text);
		}
		return '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($text) . '</a>';
	}

	/** Event keys already sent for any row of the site. */
	public static function sentKeys(array $rows): array {
		$out = array();
		foreach ($rows as $row) {
			$raw = json_decode((string)$row->get('svt_nudged'), true);
			if (is_array($raw)) {
				$out += $raw;
			}
		}
		return $out;
	}

	/** Send one event to the account holder and record it on every row. */
	private static function send(array $rows, array $event): bool {
		$to = '';
		$name = 'there';
		$recipient = array();
		try {
			$user = new User((int)$rows[0]->get('svt_usr_user_id'), TRUE);
			if ($user->key) {
				$to = trim((string)$user->get('usr_email'));
				$name = trim((string)$user->get('usr_first_name')) ?: 'there';
				$recipient = $user->export_as_array();
			}
		} catch (\Throwable $e) {
		}
		if ($to === '') {
			return false;
		}
		try {
			EmailSender::sendTemplate(self::TEMPLATE, $to, array(
				'recipient' => $recipient,
				'name'      => $name,
				'sentence'  => htmlspecialchars($event['sentence']),
				'doors'     => $event['doors'],
				'keeps'     => htmlspecialchars($event['keeps']),
			), $event['subject']);
		} catch (\Throwable $e) {
			// Not recorded, so the next pass tries again.
			error_log('ServicesNudge: ' . $event['key'] . ' to ' . $to . ' was not sent: ' . $e->getMessage());
			return false;
		}
		$stamp = gmdate('Y-m-d H:i:s');
		foreach ($rows as $row) {
			$raw = json_decode((string)$row->get('svt_nudged'), true);
			$raw = is_array($raw) ? $raw : array();
			$raw[$event['key']] = $stamp;
			// Only the last few months matter; the oldest go.
			if (count($raw) > 24) {
				asort($raw);
				$raw = array_slice($raw, -24, null, true);
			}
			$row->set('svt_nudged', json_encode($raw));
			$row->save();
		}
		return true;
	}
}
