<?php
/**
 * ForwardConfirmation - nothing is forwarded to an address until the person
 * at it agrees (specs/relay_receive_only_forwarding.md, rule 4).
 *
 * A new forwarding destination receives one message naming the address whose
 * mail would be forwarded, with a link to a page that asks them to confirm.
 * Until they do, every forward path leaves that destination out: the alias
 * forward, the catch-all forward and a filter's "Forward to". The rows live in
 * InboundForwardDestination.
 *
 * A request goes out when an operator saves a forwarding destination (the
 * mailbox and domain editors call requestMissing*, and send it there and
 * then), and, for a destination that reached the forward path some other
 * way, the first time mail would have been forwarded to it. That one is put
 * on the email queue (SendQueuedEmails) rather than sent: mail delivery never
 * waits on a send. Each request is throttled per destination, and a
 * destination is never asked again unless an operator presses Resend.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_forward_destinations_class.php'));

class ForwardConfirmation {

	const OUTCOME_SENT      = 'sent';
	const OUTCOME_CONFIRMED = 'confirmed';
	const OUTCOME_THROTTLED = 'throttled';
	const OUTCOME_FAILED    = 'failed';

	/**
	 * Split destinations into those that may receive forwards and those that may
	 * not yet. A destination with no row at all is asked now (once), so mail
	 * cannot pile up for an address that was never told.
	 *
	 * @return array{confirmed: string[], unconfirmed: string[]}
	 */
	static function partition(int $domain_id, ?int $alias_id, array $destinations): array {
		$out = array('confirmed' => array(), 'unconfirmed' => array());
		foreach ($destinations as $dest) {
			$dest = strtolower(trim((string)$dest));
			if ($dest === '') {
				continue;
			}
			$row = InboundForwardDestination::find($domain_id, $alias_id, $dest);
			if ($row && $row->is_confirmed()) {
				$out['confirmed'][] = $dest;
				continue;
			}
			if (!$row) {
				self::request($domain_id, $alias_id, $dest, true);
			}
			$out['unconfirmed'][] = $dest;
		}
		return $out;
	}

	/** The confirmation state of one destination: 'confirmed', 'pending' (asked), or null when never asked. */
	static function status(int $domain_id, ?int $alias_id, string $destination): ?string {
		$row = InboundForwardDestination::find($domain_id, $alias_id, $destination);
		if (!$row) {
			return null;
		}
		if (!$row->is_confirmed() && !$row->get('ifd_request_sent_time')) {
			return null;
		}
		return (string)$row->get('ifd_status');
	}

	/** Ask every destination of a forwarding mailbox that has never been asked. */
	static function requestMissingForAlias(InboundEmailAlias $alias): void {
		if (!$alias->key || !$alias->get('iea_is_enabled') || !$alias->mode_forwards() || !$alias->forwarding_offered()) {
			return;
		}
		$domain_id = intval($alias->get('iea_ied_inbound_email_domain_id'));
		foreach ($alias->get_destinations_array() as $dest) {
			if (!InboundForwardDestination::find($domain_id, intval($alias->key), $dest)) {
				self::request($domain_id, intval($alias->key), $dest);
			}
		}
	}

	/** Ask a domain's catch-all forwarding address if it has never been asked. */
	static function requestMissingForCatchAll(InboundEmailDomain $domain): void {
		$address = strtolower(trim((string)$domain->get('ied_catch_all_address')));
		if (!$domain->key || $address === '' || !$domain->forwarding_offered()
				|| (string)$domain->get('ied_catch_all_mode') === InboundEmailDomain::CATCHALL_STORE) {
			return;
		}
		if (!InboundForwardDestination::find(intval($domain->key), null, $address)) {
			self::request(intval($domain->key), null, $address);
		}
	}

	/**
	 * Send (or re-send) the confirmation request for one destination. A
	 * confirmed destination is left alone; a request inside the resend interval
	 * is not repeated. Never throws: a failed send is logged and reported.
	 */
	static function request(int $domain_id, ?int $alias_id, string $destination, bool $queue = false): string {
		$destination = strtolower(trim($destination));
		if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
			return self::OUTCOME_FAILED;
		}
		try {
			$row = InboundForwardDestination::find($domain_id, $alias_id, $destination);
			if ($row && $row->is_confirmed()) {
				return self::OUTCOME_CONFIRMED;
			}
			if ($row && $row->get('ifd_request_sent_time')
					&& (time() - strtotime($row->get('ifd_request_sent_time') . ' UTC')) < InboundForwardDestination::RESEND_INTERVAL_SECONDS) {
				return self::OUTCOME_THROTTLED;
			}
			if (!$row) {
				$row = new InboundForwardDestination(NULL);
				$row->set('ifd_ied_inbound_email_domain_id', $domain_id);
				$row->set('ifd_iea_inbound_email_alias_id', $alias_id ?: null);
				$row->set('ifd_destination', $destination);
				$row->set('ifd_status', InboundForwardDestination::STATUS_PENDING);
			}
			// The row exists before the send, so a send that fails leaves it
			// pending and never asked: the editor offers Send request, and the
			// mail path does not try again on every message.
			$token = bin2hex(random_bytes(32));
			$row->set('ifd_token_hash', hash('sha256', $token));
			$row->save();

			if ($queue) {
				self::queueRequest($row, $token);
			} else {
				self::sendRequest($row, $token);
			}
			$row->set('ifd_request_sent_time', gmdate('Y-m-d H:i:s'));
			$row->set('ifd_request_count', intval($row->get('ifd_request_count')) + 1);
			$row->save();
			return self::OUTCOME_SENT;
		} catch (\Throwable $e) {
			error_log('ForwardConfirmation: request to ' . $destination . ' failed: ' . $e->getMessage());
			return self::OUTCOME_FAILED;
		}
	}

	/** The page a confirmation link opens. */
	static function confirmUrl(string $token): string {
		return LibraryFunctions::get_absolute_url('/mail/forward-confirm?t=' . rawurlencode($token));
	}

	/** Mark the destination a token names as confirmed. Returns the row, or null for an unknown token. */
	static function confirm(string $token): ?InboundForwardDestination {
		$row = InboundForwardDestination::GetByToken($token);
		if (!$row) {
			return null;
		}
		if (!$row->is_confirmed()) {
			$row->set('ifd_status', InboundForwardDestination::STATUS_CONFIRMED);
			$row->set('ifd_confirmed_time', gmdate('Y-m-d H:i:s'));
			$row->save();
		}
		return $row;
	}

	/** @return array{subject:string, text:string, html:string} */
	private static function requestContent(InboundForwardDestination $row, string $token): array {
		$source = $row->source_label();
		$url = self::confirmUrl($token);
		$settings = Globalvars::get_instance();
		$site = trim((string)$settings->get_setting('site_name')) ?: (string)$settings->get_setting('webDir');

		$text = "Someone at " . $site . " has asked for mail sent to " . $source
			. " to be forwarded to this address.\n\n"
			. "Nothing will be forwarded unless you agree. To agree, open this link and press Confirm:\n\n"
			. $url . "\n\n"
			. "If you did not expect this, ignore this message and nothing will be forwarded.\n";
		$html = '<p>Someone at ' . htmlspecialchars($site) . ' has asked for mail sent to <strong>'
			. htmlspecialchars($source) . '</strong> to be forwarded to this address.</p>'
			. '<p>Nothing will be forwarded unless you agree. To agree, open this link and press Confirm:</p>'
			. '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></p>'
			. '<p>If you did not expect this, ignore this message and nothing will be forwarded.</p>';

		return array('subject' => 'Confirm forwarding of mail for ' . $source, 'text' => $text, 'html' => $html);
	}

	private static function sendRequest(InboundForwardDestination $row, string $token): void {
		require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		$c = self::requestContent($row, $token);
		// No explicit From: EmailSender stamps the platform default, the identity
		// the site's email service is verified for. The request goes through the
		// site's email service like every forward it unlocks.
		$message = new EmailMessage();
		$message->to((string)$row->get('ifd_destination'))
			->subject($c['subject'])
			->text($c['text'])
			->html($c['html']);
		(new EmailSender())->send($message);
	}

	/** Put the request on the email queue, which the SendQueuedEmails task sends. */
	private static function queueRequest(InboundForwardDestination $row, string $token): void {
		require_once(PathHelper::getIncludePath('data/queued_emails_class.php'));
		$settings = Globalvars::get_instance();
		$c = self::requestContent($row, $token);
		$queued = new QueuedEmail(NULL);
		$queued->set('equ_from', (string)$settings->get_setting('defaultemail'));
		$queued->set('equ_from_name', (string)($settings->get_setting('defaultemailname') ?: 'Inbound Email'));
		$queued->set('equ_to', (string)$row->get('ifd_destination'));
		$queued->set('equ_to_name', (string)$row->get('ifd_destination'));
		$queued->set('equ_subject', mb_substr($c['subject'], 0, 128));
		$queued->set('equ_body', $c['html']);
		$queued->set('equ_status', QueuedEmail::READY_TO_SEND);
		$queued->set('equ_retry_count', 0);
		$queued->save();
	}
}
