<?php
/**
 * MailTestProbe — one fixed test message from a hosted domain to the one probe
 * mailbox, so a receiver's SPF, DKIM and DMARC verdicts on real mail can be
 * read without a person reading headers (specs/agent_recipes_and_vocabulary.md,
 * 2026-11-14).
 *
 * Two ways to send, one message:
 *   - sendOrdinary(): an ordinary domain, through EmailSender as any
 *     transactional mail leaves. The mail_test_send agent word and the Setup
 *     page's Send test message button use it.
 *   - sendProtected(): a protected domain, whose mail leaves only through its
 *     owner's signed-in compose path. Only the Setup page's button, pressed in
 *     that session, uses it; no word reaches it.
 *
 * Everything but the domain is compiled here. The token in the subject is made
 * here and returned, so the message can be found at the receiver, where its
 * Authentication-Results header carries the verdicts.
 *
 * Every answer is an array: domain, sent, and either token/from/to/subject or
 * refused (a closed word) with any detail.
 *
 * The receiver is our inbound test node, a stopgap (owner, 2026-11-14): the
 * end state is an address on the site's own management node.
 *
 * @version 1.0.0
 */
class MailTestProbe {

	const PROBE_ADDRESS = 'test@dev.getjoinery.com';
	const FROM_LOCAL = 'joinery-mail-check';
	const SUBJECT_PREFIX = 'Joinery mail check ';
	const RATE_SECONDS = 600;

	/** The refusals an answer may carry. */
	const REFUSALS = array('not_a_hosted_domain', 'protected', 'not_protected', 'email_test_mode', 'email_dry_run',
		'too_soon', 'busy', 'no_rate_record', 'no_mailbox_here', 'locked');

	/** The fixed body. */
	public static function body(string $domain, string $token): string {
		return "This is an automatic test message from " . $domain . ".\n\n"
			. "It checks that mail sent as " . $domain . " passes SPF, DKIM and DMARC at a receiver. "
			. "Nothing needs doing with it.\n\nCheck " . $token . "\n";
	}

	/** The hosted, non-IMAP domain row for a name, or null. */
	private static function hosted(string $domain) {
		$model = InboundEmailDomain::GetByDomain($domain);
		return ($model && !$model->get('ied_is_imap_source')) ? $model : null;
	}

	/** Test mode or dry run would swallow the probe: the refusal word, or null. */
	private static function trapRefusal(): ?string {
		if (EmailSender::testModeTrap() !== null) {
			return 'email_test_mode';
		}
		if ((string)Globalvars::get_instance()->get_setting('email_dry_run') === '1') {
			return 'email_dry_run';
		}
		return null;
	}

	/**
	 * Hold the domain's rate marker for one send. Returns [handle, null] or
	 * [null, refusal answer]. The marker's time is the last send's.
	 */
	private static function takeRate(string $domain): array {
		$dir = PathHelper::getSiteRoot() . '/cache/mail_test_send';
		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			return array(null, array('domain' => $domain, 'sent' => false, 'refused' => 'no_rate_record'));
		}
		$marker = $dir . '/' . $domain;
		$lock = @fopen($marker, 'c');
		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
			return array(null, array('domain' => $domain, 'sent' => false, 'refused' => 'busy'));
		}
		clearstatcache(true, $marker);
		$age = time() - (int)@filemtime($marker);
		if ((int)@filesize($marker) > 0 && $age < self::RATE_SECONDS) {
			flock($lock, LOCK_UN);
			fclose($lock);
			return array(null, array('domain' => $domain, 'sent' => false, 'refused' => 'too_soon',
				'retry_after_seconds' => self::RATE_SECONDS - $age));
		}
		return array($lock, null);
	}

	/** Release the marker; a send that went stamps it. */
	private static function releaseRate($lock, bool $sent): void {
		if ($sent) {
			ftruncate($lock, 0);
			fwrite($lock, gmdate('Y-m-d\TH:i:s\Z') . "\n");
			fflush($lock);
			$meta = stream_get_meta_data($lock);
			@touch($meta['uri']);
		}
		flock($lock, LOCK_UN);
		fclose($lock);
	}

	/** A fresh token: 16 hex characters. */
	private static function token(): string {
		return bin2hex(random_bytes(8));
	}

	/**
	 * Send from an ordinary hosted domain through EmailSender. Refuses a
	 * domain this site does not host, a protected one, test mode or dry run,
	 * and a second send for the domain within RATE_SECONDS.
	 */
	public static function sendOrdinary(string $domain): array {
		$domain = strtolower(trim($domain));
		$model = self::hosted($domain);
		if (!$model) {
			return array('domain' => $domain, 'sent' => false, 'refused' => 'not_a_hosted_domain');
		}
		if ((bool)$model->get('ied_is_protected_identity') || MailIdentityGuard::isProtectedDomain($domain)) {
			return array('domain' => $domain, 'sent' => false, 'refused' => 'protected');
		}
		if (($trap = self::trapRefusal()) !== null) {
			return array('domain' => $domain, 'sent' => false, 'refused' => $trap);
		}
		list($lock, $refusal) = self::takeRate($domain);
		if ($refusal) {
			return $refusal;
		}
		$token = self::token();
		$from = self::FROM_LOCAL . '@' . $domain;
		$subject = self::SUBJECT_PREFIX . $token;
		$ok = false;
		$report = array();
		try {
			$message = EmailMessage::create(self::PROBE_ADDRESS, $subject, self::body($domain, $token));
			$message->to(self::PROBE_ADDRESS);
			$message->from($from);
			$sender = new EmailSender();
			$ok = (bool)$sender->send($message, false);
			$report = $sender->lastSendReport();
		} finally {
			self::releaseRate($lock, $ok);
		}
		return array(
			'domain'    => $domain,
			'sent'      => $ok,
			'token'     => $ok ? $token : null,
			'from'      => $from,
			'to'        => self::PROBE_ADDRESS,
			'subject'   => $ok ? $subject : null,
			'transport' => is_string($report['transport'] ?? null) ? substr($report['transport'], 0, 40) : null,
			'error'     => $ok ? null : 'the send did not go: ' . substr((string)($report['error'] ?? 'no reason given'), 0, 200),
			'sent_at'   => gmdate('Y-m-d\TH:i:s\Z'),
		);
	}

	/**
	 * Send from a protected domain through the signed-in compose path, from
	 * the first mailbox at that domain the session may compose from. Refuses a
	 * domain that is not protected (sendOrdinary is its path), a session with
	 * no mailbox there, a locked mailbox, test mode or dry run, and a second
	 * send within RATE_SECONDS.
	 */
	public static function sendProtected(SessionControl $session, string $domain): array {
		$domain = strtolower(trim($domain));
		$model = self::hosted($domain);
		if (!$model) {
			return array('domain' => $domain, 'sent' => false, 'refused' => 'not_a_hosted_domain');
		}
		if (!(bool)$model->get('ied_is_protected_identity')) {
			return array('domain' => $domain, 'sent' => false, 'refused' => 'not_protected');
		}
		if (($trap = self::trapRefusal()) !== null) {
			return array('domain' => $domain, 'sent' => false, 'refused' => $trap);
		}
		$viewer = MailboxViewer::fromSession($session);
		$alias_id = 0;
		$from = '';
		if ($viewer->canCompose()) {
			foreach ($viewer->accessibleAliasIds() as $id) {
				$alias = new InboundEmailAlias((int)$id, TRUE);
				if (!$alias->key || $alias->get('iea_delete_time')) {
					continue;
				}
				$address = strtolower($alias->get_full_address());
				if (substr($address, -strlen('@' . $domain)) === '@' . $domain) {
					$alias_id = (int)$alias->key;
					$from = $address;
					break;
				}
			}
		}
		if ($alias_id === 0) {
			return array('domain' => $domain, 'sent' => false, 'refused' => 'no_mailbox_here');
		}
		list($lock, $refusal) = self::takeRate($domain);
		if ($refusal) {
			return $refusal;
		}
		$token = self::token();
		$subject = self::SUBJECT_PREFIX . $token;
		$ok = false;
		try {
			(new MailboxSender($viewer))->send(array(
				'mode' => MailboxSender::MODE_NEW, 'alias_id' => $alias_id,
				'to' => self::PROBE_ADDRESS, 'cc' => '', 'bcc' => '',
				'subject' => $subject, 'body' => self::body($domain, $token), 'body_html' => '',
			));
			$ok = true;
		} catch (MailboxLockedException $e) {
			return array('domain' => $domain, 'sent' => false, 'refused' => 'locked');
		} catch (MailboxSenderException $e) {
			return array('domain' => $domain, 'sent' => false, 'from' => $from, 'to' => self::PROBE_ADDRESS,
				'error' => 'the send did not go: ' . substr($e->getMessage(), 0, 200));
		} finally {
			self::releaseRate($lock, $ok);
		}
		return array('domain' => $domain, 'sent' => true, 'token' => $token, 'from' => $from,
			'to' => self::PROBE_ADDRESS, 'subject' => $subject, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'));
	}

	/** One line a person reads about an answer. */
	public static function describe(array $a): string {
		if (!empty($a['sent'])) {
			return 'Test message sent from ' . $a['from'] . ' to ' . $a['to'] . ' with the subject "' . $a['subject']
				. '". Its receiver records the SPF, DKIM and DMARC verdicts in the message\'s Authentication-Results header.';
		}
		$why = array(
			'not_a_hosted_domain' => 'it is not a mail domain of this site',
			'protected'           => 'send protection is on, so it sends only from its owner\'s signed-in session',
			'not_protected'       => 'send protection is off for it',
			'email_test_mode'     => 'email test mode is on, which would send it to the test mailbox instead',
			'email_dry_run'       => 'email dry run is on',
			'too_soon'            => 'one was sent less than ten minutes ago',
			'busy'                => 'another test send for it is running',
			'no_rate_record'      => 'the site could not keep its record of when it last sent one',
			'no_mailbox_here'     => 'you have no mailbox at that domain to send from',
			'locked'              => 'your mail is locked: unlock it, then send again',
		);
		if (!empty($a['refused'])) {
			return 'No test message was sent from ' . $a['domain'] . ': ' . ($why[$a['refused']] ?? 'it was refused') . '.';
		}
		return 'The test message from ' . $a['domain'] . ' did not go: ' . ($a['error'] ?? 'no reason given') . '.';
	}
}
