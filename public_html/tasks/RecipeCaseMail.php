<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * RecipeCaseMail — one plain-text mail per recipe per day to the superadmins
 * while this host's agent has an open case (specs/agent_tier1_recipes.md,
 * settled Q3 "Unpaired"; the burn-in's "A case is untrusted input").
 *
 * Reads the rendered cases the agent wrote outward (RecipeCaseNotice::records)
 * and the log of when each recipe was last mailed (the recipe_case_mail_log
 * setting). An open case whose recipe was mailed less than a day ago is left
 * to the notice, which stays live throughout. A closed case, or one delivered
 * to a management node (its card and notice show it there), sends nothing and
 * clears its recipe from the log, so the next local case mails at once.
 *
 * The body is plain text with no link, because the file it renders can be
 * forged by the web user: a forged case is a lie to the admin and one mail,
 * nothing anyone acts on by clicking.
 *
 * @version 1.0
 */
class RecipeCaseMail implements ScheduledTaskInterface {

	const ONCE_PER = 86400;

	public function run(array $config) {
		$site = (string)Globalvars::get_instance()->get_setting('site_name');
		$result = self::mail(RecipeCaseNotice::records(), self::log(), time(), self::superadmin_addresses(),
			$site !== '' ? $site : 'this site',
			function (string $to, string $subject, string $body): void {
				EmailSender::quickSend($to, $subject, $body);
			});
		self::save_log($result['log']);
		return array('status' => 'success', 'message' => $result['message']);
	}

	/**
	 * The pass over one set of records: which get mailed, which are skipped,
	 * what the log becomes. Pure over its inputs — the records, the log, the
	 * clock, the recipients, the sender — so the rule can be tested without a
	 * file or a mail. Returns ['log' => the new log, 'sent' => n, 'message'].
	 */
	public static function mail(array $records, array $log, int $now, array $recipients, string $site, callable $send): array {
		$sent = 0;
		$open = 0;
		foreach ($records as $recipe => $rec) {
			// A paired node's case rides the poll to the management node, whose
			// card and notice show it; the mail is the unpaired path only.
			if (($rec['status'] ?? '') !== 'open' || ($rec['delivery'] ?? '') === RecipeCaseNotice::DELIVERY_PLANE) {
				unset($log[$recipe]);
				continue;
			}
			$open++;
			if (isset($log[$recipe]) && ($now - (int)$log[$recipe]) < self::ONCE_PER) {
				continue;
			}
			if (count($recipients) === 0) {
				continue;
			}
			$body = RecipeCaseNotice::mail_body($rec, $site);
			$subject = '[' . RecipeCaseNotice::text($site, 60) . '] ' . RecipeCaseNotice::text($recipe, 40)
				. ' recipe gave up: case #' . (int)($rec['id'] ?? 0) . ' open';
			$subject = str_replace(array("\r", "\n"), ' ', $subject);
			$delivered = false;
			foreach ($recipients as $to) {
				try {
					$send($to, $subject, $body);
					$delivered = true;
				} catch (Throwable $e) {
					error_log('RecipeCaseMail: send to a superadmin failed: ' . $e->getMessage());
				}
			}
			if ($delivered) {
				$log[$recipe] = $now;
				$sent++;
			}
		}
		return array('log' => $log, 'sent' => $sent, 'message' => $open === 0
			? 'No open local case'
			: $open . ' open local case(s), ' . $sent . ' mail(s) sent');
	}

	/** Every live superadmin's address. */
	public static function superadmin_addresses(): array {
		$out = array();
		foreach (new MultiUser(array('permission_range' => array(10, 1000), 'deleted' => false, 'not_system_users' => true)) as $user) {
			$email = trim((string)$user->get('usr_email'));
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$out[] = $email;
			}
		}
		return $out;
	}

	private static function log(): array {
		$raw = (string)Globalvars::get_instance()->get_setting('recipe_case_mail_log');
		$log = $raw !== '' ? json_decode($raw, true) : array();
		return is_array($log) ? $log : array();
	}

	private static function save_log(array $log): void {
		try {
			Setting::put('recipe_case_mail_log', count($log) ? json_encode($log) : '');
		} catch (Throwable $e) {
			error_log('RecipeCaseMail: could not record the mail log: ' . $e->getMessage());
		}
	}
}
