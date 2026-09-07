<?php
/**
 * MailboxAttentionNotice — "mail is not arriving", said on every admin page.
 *
 * A relay-fronted deployment's mail arrives only if this server can collect it
 * from the relay. When it cannot, the relay keeps accepting — senders are told
 * their mail was delivered — while nothing brings it here. Every surface that
 * knows this is one an operator has to open: the Setup tab, the reader's
 * per-mailbox banner, the task log. This notice is the one that comes to them,
 * rendered through the admin-header registry (AdminNotices) on every page.
 *
 * It reads STORED facts only, because it runs on every admin page load:
 *
 *   - mrl_pickup_alarm_time — the reconcile pass found pickup stopped and said
 *     so once (MailboxRelay::pickupTransition); cleared when pickup resumes.
 *   - mrl_last_health_failure — the last ping's failure class, stamped by every
 *     health poll (the reconcile pass and the Setup tab alike), '' when the
 *     last ping answered.
 *
 * Nothing here pings, resolves or probes. A relay that is fine renders ''.
 *
 * @version 1.0
 */
class MailboxAttentionNotice {
	const SETUP_URL = '/plugins/mailbox/admin/admin_mailbox_setup';

	/** The notice for this deployment's active relay, or '' when there is nothing to say. */
	public static function render(): string {
		if (!class_exists('MailboxRelay')) {
			return '';
		}
		$relay = MailboxRelay::active();
		if ($relay === null) {
			return '';
		}
		return self::forRelay($relay);
	}

	/**
	 * The notice for one relay row, from its stored facts. Public and pure so the
	 * wording can be tested against rows that were never saved.
	 */
	public static function forRelay(MailboxRelay $relay): string {
		$facts = self::facts($relay);
		if ($facts === null) {
			return '';
		}
		return '<div class="alert alert-danger mbx-attention-notice" role="alert">'
			. '<strong>' . htmlspecialchars($facts['headline'], ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($facts['body'], ENT_QUOTES, 'UTF-8')
			. ' <a href="' . self::SETUP_URL . '">Open mail setup</a>'
			. '</div>';
	}

	/**
	 * What to say, or null for nothing. The pickup alarm outranks a failed ping:
	 * "your mail is not arriving" is the consequence, "the relay cannot be
	 * reached" is the cause, and an operator wants the consequence first.
	 *
	 * @return array{headline:string, body:string}|null
	 */
	public static function facts(MailboxRelay $relay): ?array {
		if (!(bool)$relay->get('mrl_is_enabled')) {
			return null;
		}
		$name = trim((string)$relay->get('mrl_name'))
			?: (trim((string)$relay->get('mrl_mx_hostname')) ?: 'the relay');

		$alarm = trim((string)$relay->get('mrl_pickup_alarm_time'));
		if ($alarm !== '') {
			$last = trim((string)$relay->get('mrl_last_pull_time'));
			return array(
				'headline' => 'Mail is not arriving.',
				'body'     => 'This server has not been able to collect mail from ' . $name . ' since '
					. ($last !== '' ? $last . ' UTC' : 'it was set up')
					. '. Mail sent to you is waiting on the relay, and senders are told it was delivered.',
			);
		}

		$failure = trim((string)$relay->get('mrl_last_health_failure'));
		if ($failure !== '') {
			$why = class_exists('RelayClient') ? RelayClient::describeFailure($failure) : $failure;
			return array(
				'headline' => 'This server cannot reach ' . $name . '.',
				'body'     => rtrim($why, '.') . '. Until it can, mail sent to you waits on the relay.',
			);
		}
		return null;
	}
}
?>
