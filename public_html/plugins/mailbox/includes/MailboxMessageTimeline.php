<?php
/**
 * MailboxMessageTimeline - assemble everything recorded about one message into
 * one ordered list of events (specs/mailbox_message_timeline.md).
 *
 * Every event is {time, kind, title, detail, meta}. `time` is UTC 'Y-m-d H:i:s'
 * or null for a state that has no moment of its own (sealed, current labels);
 * timeless events sort after the arrival. `notes` carry the honest "no answer"
 * sentences — a connected account that handled delivery itself, a carrier with
 * no events API — so the panel never implies a fact it does not have.
 *
 * Reading order of truth: the message row first, the Received: chain from its
 * headers (in-window only on a protected mailbox), the routing-log rows that
 * name it, then the send-attempt rows that produced or answered it. For an
 * attempt through a carrier that can be asked (DeliveryEventSource) the
 * delivery status is refreshed here while it is still moving — unknown,
 * accepted, deferred — and at most every two minutes; delivered and failed are
 * settled and are asked again only on an explicit refresh.
 *
 * Nothing here reveals body content: hops name servers, attempts name
 * recipients (sealed with the mailbox), and a closed window drops exactly
 * those lines and sets locked:true beside the rest.
 *
 * @version 1.1 - a Fortress message shows routing events only (no header block)
 * @version 1.0
 */

class MailboxMessageTimeline {
	/** Ask the carrier again this soon after the last look, at most. */
	const DELIVERY_RECHECK_SECONDS = 120;

	/** @var InboundEmailMessage */
	private $message;
	/** @var bool the Check again button: ask the carrier even when the cache calls it settled */
	private $refresh;
	/** @var array */
	private $events = array();
	/** @var string[] */
	private $notes = array();
	/** @var bool a sealed field was needed and the window is closed */
	private $locked = false;

	public function __construct(InboundEmailMessage $message, bool $refresh = false) {
		$this->message = $message;
		$this->refresh = $refresh;
	}

	/** @return array{events: array, notes: string[], locked: bool} */
	public function build(): array {
		$m = $this->message;
		$direction = (string)$m->get('iem_direction') ?: 'inbound';

		if ($direction === 'inbound') {
			$this->inboundEvents();
		} elseif ($direction === 'draft') {
			$this->add((string)$m->get('iem_create_time'), 'draft', 'Draft saved', null);
		}
		$this->stateEvents();
		$this->attemptEvents($direction);

		if ($direction === 'outbound' && !$this->hasEventOfKind('sent')) {
			// A send from before attempts were recorded: the row is the only
			// evidence, and it is evidence of acceptance (the row is stored only
			// after the carrier took the message).
			$this->add((string)$m->get('iem_received_time'), 'sent', 'Sent as ' . $this->fromAddressPlain(),
				'sent before send records were kept — no carrier details are available');
		}

		$this->sort();
		return array('events' => array_values($this->events), 'notes' => array_values(array_unique($this->notes)), 'locked' => $this->locked);
	}

	// ── inbound ────────────────────────────────────────────────────────────

	private function inboundEvents(): void {
		$m = $this->message;
		$this->hopEvents();

		$received = (string)$m->get('iem_received_time');
		$account_id = intval($m->get('iem_iia_inbound_imap_account_id'));
		$account = $account_id > 0 ? new InboundImapAccount($account_id, TRUE) : null;
		$transport = (string)$m->get('iem_transport');

		if ($account && $account->key) {
			$label = $account->providerLabel();
			$this->add($received, 'arrived', 'Arrived at ' . $label, 'in your connected account');
			$folder = (string)$m->get('iem_imap_folder');
			$this->add((string)$m->get('iem_create_time'), 'fetched', 'Collected from ' . $label,
				$folder !== '' ? 'folder ' . $folder : null);
		} elseif ($transport === 'joinery_direct') {
			$this->add($received, 'arrived', 'Arrived over Joinery Direct',
				$m->get('iem_direct_verified') ? 'the sending instance was verified' : 'delivered directly, instance to instance');
		} else {
			$how = $this->arrivalRoute((string)$m->get('iem_auth_source'));
			$size = intval($m->get('iem_size_bytes'));
			$detail = trim($how . ($size > 0 ? ($how !== '' ? ', ' : '') . $this->humanBytes($size) : ''));
			$this->add($received, 'arrived', 'Arrived at ' . $this->aliasAddress(), $detail !== '' ? $detail : null);
		}

		if (intval($m->get('iem_mir_mail_import_run_id')) > 0) {
			$this->add((string)$m->get('iem_create_time'), 'fetched', 'Imported from a mail archive', null);
		}

		// Authentication, in the platform's own words for the verdict.
		$origin = ($account && $account->key) ? 'imap' : (intval($m->get('iem_mir_mail_import_run_id')) > 0 ? 'import' : null);
		$auth = InboundEmailMessage::authReadout((string)$m->get('iem_auth_source'), (string)$m->get('iem_spf_result'),
			(string)$m->get('iem_dkim_result'), (string)$m->get('iem_dmarc_result'), $origin);
		$parts = array();
		foreach (array('SPF' => 'iem_spf_result', 'DKIM' => 'iem_dkim_result', 'DMARC' => 'iem_dmarc_result') as $name => $col) {
			$v = strtolower(trim((string)$m->get($col)));
			if ($v !== '' && $v !== 'unverified') {
				$parts[] = $name . ' ' . $v;
			}
		}
		$detail = $parts ? implode(' · ', $parts) : $auth['detail'];
		if (!empty($auth['checked_by'])) {
			$detail .= ' — checked by ' . $auth['checked_by'];
		}
		$this->add($received, 'auth', 'Authentication: ' . $auth['headline'], $detail, array('state' => $auth['state']));

		// Spam disposition.
		$verdict = (string)$m->get('iem_spam_verdict');
		if ($verdict !== '') {
			$score = $m->get('iem_spam_score');
			$this->add($received, 'spam', $verdict === InboundEmailMessage::SPAM_VERDICT_SPAM ? 'Spam check: judged spam' : 'Spam check: not spam',
				($score !== null && $score !== '') ? 'score ' . rtrim(rtrim(number_format((float)$score, 2, '.', ''), '0'), '.') : null);
		}
		$learned = (string)$m->get('iem_learned_verdict');
		if ($learned !== '') {
			$this->add(null, 'spam', $learned === InboundEmailMessage::SPAM_VERDICT_SPAM ? 'You marked this as spam' : 'You marked this as not spam', null);
		}

		// AI safety scan.
		$danger = $m->get('iem_ai_danger_score');
		if ($danger !== null && $danger !== '') {
			$this->add((string)$m->get('iem_ai_scan_time') ?: null, 'ai_scan', 'Safety scan: danger ' . intval($danger) . '/10', null);
		}

		// Routing: the log rows that name this message, oldest first.
		$logs = new MultiInboundEmailLog(array('message_id' => intval($m->key)), array('iel_create_time' => 'ASC'));
		foreach ($logs as $log) {
			$this->routingEvent($log);
		}
	}

	/** The Received: chain — oldest hop first, so the first line is the sender's server. */
	private function hopEvents(): void {
		$headers = $this->headerBlock();
		if ($headers === null || $headers === '') {
			return;
		}
		$received = AuthenticationResults::extractHeaders($headers . "\n\n", 'Received');
		if (!$received) {
			return;
		}
		$received = array_reverse($received); // headers list newest first
		$count = count($received);
		foreach ($received as $i => $line) {
			$hop = self::parseReceived($line);
			$title = ($i === 0) ? 'Left the sender\'s server' : 'Passed through ' . ($hop['by'] ?: 'a relay');
			if ($i === $count - 1 && $count > 1) {
				$title = 'Received by ' . ($hop['by'] ?: 'the mail server');
			}
			$detail = trim(($hop['from'] !== '' ? 'from ' . $hop['from'] : '')
				. ($hop['by'] !== '' && $i === 0 ? ' by ' . $hop['by'] : '')
				. ($hop['with'] !== '' ? ' over ' . $hop['with'] : ''));
			$this->add($hop['time'], 'hop', $title, $detail !== '' ? $detail : null, array('order' => $i));
		}
	}

	/**
	 * The raw header block, readable right now: the stored headers, else the
	 * header block of a stored raw (inline/local/cloud — never a live IMAP
	 * fetch for a timeline). Null when there is none, or the window is closed.
	 */
	private function headerBlock(): ?string {
		$m = $this->message;
		// A Fortress message's headers are sealed to its owner's devices and no
		// raw is kept: its timeline is routing events only (specs/client_custody_mail.md § R7).
		if (InboundEmailMessage::isBrowserSealed($m)) {
			return null;
		}
		try {
			$headers = (string)$m->get('iem_raw_headers');
			if ($headers !== '') {
				return str_replace("\r\n", "\n", $headers);
			}
			$driver = (string)$m->get('iem_raw_storage_driver') ?: 'inline';
			if (in_array($driver, array('inline', 'local', 'cloud'), true)) {
				$raw = $m->getRawMessage();
				if ($raw !== null && $raw !== '') {
					$raw = str_replace("\r\n", "\n", $raw);
					$split = strpos($raw, "\n\n");
					return $split !== false ? substr($raw, 0, $split) : $raw;
				}
			}
		} catch (VaultLockedException $e) {
			$this->locked = true;
		} catch (Throwable $e) {
			error_log('MailboxMessageTimeline: could not read headers of message ' . $m->key . ': ' . $e->getMessage());
		}
		return null;
	}

	/**
	 * One Received: header → from/by/with hosts and the UTC time.
	 * "from A (a.example [1.2.3.4]) by B with ESMTPS id X for <u@d>; Tue, 16 Sep 2026 14:02:11 +0000"
	 * Tolerant: a clause that is missing is '', a date that does not parse is null.
	 */
	public static function parseReceived(string $line): array {
		$time = null;
		$semi = strrpos($line, ';');
		$clauses = $line;
		if ($semi !== false) {
			$stamp = trim(substr($line, $semi + 1));
			$stamp = preg_replace('/\s*\([^)]*\)\s*$/', '', $stamp); // trailing "(UTC)" comments
			// The day name is informational (RFC 5322) and servers get it wrong;
			// strtotime treats a wrong one as "the next such day" and moves the date.
			$stamp = preg_replace('/^[A-Za-z]{3},?\s*/', '', $stamp);
			$ts = $stamp !== '' ? strtotime($stamp) : false;
			if ($ts !== false) {
				$time = gmdate('Y-m-d H:i:s', $ts);
			}
			$clauses = substr($line, 0, $semi);
		}
		$grab = function (string $word) use ($clauses): string {
			return preg_match('/(?:^|\s)' . $word . '\s+([^\s(;]+)/i', $clauses, $mm) ? trim($mm[1], '[]<>') : '';
		};
		return array('from' => $grab('from'), 'by' => $grab('by'), 'with' => $grab('with'), 'time' => $time);
	}

	/** How a hosted-alias message got here, from where its verdict came. */
	private function arrivalRoute(string $auth_source): string {
		switch (strtolower(trim($auth_source))) {
			case 'milter': return 'over SMTP to this mail server';
			case 'relay':  return 'through your mail relay';
			case 'mailgun': case 'sendgrid': case 'ses':
				$labels = EmailSender::getAvailableServices();
				return 'via ' . ($labels[strtolower(trim($auth_source))] ?? $auth_source);
			default: return '';
		}
	}

	private function routingEvent(InboundEmailLog $log): void {
		$status = (string)$log->get('iel_status');
		$dest = trim((string)$log->get('iel_destinations'));
		$error = trim((string)$log->get('iel_error_message'));
		$alias = $this->aliasAddress();
		switch ($status) {
			case InboundEmailLog::STATUS_STORED:
				$title = 'Routed to ' . $alias . ' — stored';
				$detail = $dest !== '' ? $dest : null; // "duplicate (Message-ID already stored)"
				break;
			case InboundEmailLog::STATUS_FORWARDED:
				$title = 'Forwarded on';
				$detail = $dest !== '' ? 'to ' . str_replace(',', ', ', $dest) : null;
				break;
			case InboundEmailLog::STATUS_SPAM_HELD:
				$title = 'Held as spam — not forwarded';
				$detail = null;
				break;
			case InboundEmailLog::STATUS_FILTERED:
				$title = 'Filters applied';
				$detail = $dest !== '' ? $dest : null;
				break;
			case InboundEmailLog::STATUS_RATE_LIMITED:
				$title = 'Forward held back — rate limit reached';
				$detail = null;
				break;
			case InboundEmailLog::STATUS_REJECTED:
				$title = 'Forward refused';
				$detail = $error !== '' ? $error : null;
				break;
			case InboundEmailLog::STATUS_STORE_CAPPED:
				$title = 'Delivery deferred — volume cap';
				$detail = $error !== '' ? $error : null;
				break;
			case InboundEmailLog::STATUS_REPORT_FILED:
				$title = 'Filed as a deliverability report';
				$detail = $dest !== '' ? $dest : null;
				break;
			case InboundEmailLog::STATUS_ERROR:
				$title = 'Routing problem';
				$detail = $error !== '' ? $error : ($dest !== '' ? $dest : null);
				break;
			default:
				$title = 'Routing: ' . str_replace('_', ' ', $status);
				$detail = $error !== '' ? $error : ($dest !== '' ? $dest : null);
		}
		$this->add((string)$log->get('iel_create_time'), $status === InboundEmailLog::STATUS_FORWARDED ? 'forwarded' : 'routed',
			$title, $detail, array('status' => $status));
	}

	// ── state that is true now ─────────────────────────────────────────────

	private function stateEvents(): void {
		$m = $this->message;
		if (self::truthy($m->get('iem_content_sealed'))) {
			$this->add(null, 'sealed', 'Sealed into the mailbox owner\'s vault', null);
		}
		$read = (string)$m->get('iem_read_time');
		if ($read !== '' && (string)$m->get('iem_direction') === 'inbound') {
			$this->add($read, 'opened', 'Opened', null);
		}
		$labels = $this->currentLabels();
		if ($labels) {
			$this->add(null, 'label', 'Labelled ' . implode(', ', $labels), null);
		}
		if (self::truthy($m->get('iem_is_starred'))) {
			$this->add(null, 'label', 'Starred', null);
		}
		if (self::truthy($m->get('iem_is_archived'))) {
			$this->add(null, 'label', 'Archived', null);
		}
		$deleted = (string)$m->get('iem_delete_time');
		if ($deleted !== '') {
			$this->add($deleted, 'label', 'Moved to trash', null);
		}
	}

	private function currentLabels(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT ilb_name FROM ilm_inbound_label_members
			JOIN ilb_inbound_email_labels ON ilb_inbound_email_label_id = ilm_ilb_inbound_email_label_id
			WHERE ilm_iem_inbound_email_message_id = ? AND ilm_present_local = true AND ilb_delete_time IS NULL
			ORDER BY ilb_name');
		$stmt->execute(array(intval($this->message->key)));
		return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array();
	}

	// ── sends ──────────────────────────────────────────────────────────────

	private function attemptEvents(string $direction): void {
		$attempts = new MultiMailboxSendAttempt(array('about_message_id' => intval($this->message->key)),
			array('mst_create_time' => 'ASC'));
		foreach ($attempts as $attempt) {
			$this->attemptEvent($attempt);
		}
	}

	private function attemptEvent(MailboxSendAttempt $a): void {
		$time = (string)$a->get('mst_create_time');
		$kind = (string)$a->get('mst_kind');
		$outcome = (string)$a->get('mst_outcome');
		$transport = (string)$a->get('mst_transport');
		$label = (string)$a->get('mst_transport_label') ?: ($transport !== '' ? $transport : 'the mail service');
		$message_id = (string)$a->get('mst_message_id_header');

		// Recipients and the error are the sealed pair; a closed window hides
		// exactly those and says so.
		$hidden = false;
		$recipients = array();
		$error = '';
		try {
			foreach ($a->json('mst_recipients') as $r) {
				$recipients[] = (string)($r['email'] ?? '');
			}
			$error = trim((string)$a->get('mst_error'));
		} catch (VaultLockedException $e) {
			$hidden = true;
			$this->locked = true;
		}
		$recipients = array_values(array_filter($recipients));
		$to = $hidden ? 'recipients hidden — unlock to show' : ($recipients ? 'to ' . implode(', ', $recipients) : '');

		if ($outcome === MailboxSendAttempt::OUTCOME_FAILED) {
			$this->add($time, 'attempt_failed', $kind === MailboxSendAttempt::KIND_FORWARD ? 'Forward failed' : 'Send attempt failed',
				trim('via ' . $label . ($hidden ? ' — error hidden until you unlock' : ($error !== '' ? ' — ' . $error : ''))),
				array('attempt_id' => intval($a->key)));
			return;
		}

		$direct = $a->json('mst_direct_delivered');
		if ($transport === 'joinery_direct' || ($direct && count($direct) >= max(1, count($recipients)))) {
			$this->add($time, 'delivery', 'Delivered over Joinery Direct',
				($hidden ? '' : 'to ' . implode(', ', $direct ?: $recipients) . ' — ') . 'the receiving instance confirmed receipt',
				array('attempt_id' => intval($a->key), 'status' => 'delivered'));
			return;
		}

		$title = $kind === MailboxSendAttempt::KIND_FORWARD ? 'Forwarded' : 'Sent as ' . (string)$a->get('mst_from_address');
		$detail = trim(implode(' · ', array_filter(array($to, 'via ' . $label, $message_id !== '' ? 'Message-ID ' . $message_id : ''))));
		if ($outcome === MailboxSendAttempt::OUTCOME_PARTIAL) {
			$detail .= ' · some recipients could not be sent' . ($error !== '' && !$hidden ? ' — ' . $error : '');
		}
		$this->add($time, 'sent', $title, $detail, array('attempt_id' => intval($a->key)));

		if ($direct) {
			$this->add($time, 'delivery', 'Delivered over Joinery Direct',
				'to ' . implode(', ', $direct) . ' — the receiving instance confirmed receipt',
				array('attempt_id' => intval($a->key), 'status' => 'delivered'));
		}

		$receipt = $a->json('mst_receipt');
		if (!empty($receipt['response']) || !empty($receipt['id'])) {
			$detail = trim((string)($receipt['response'] ?? ''));
			if (!empty($receipt['id']) && strpos($detail, (string)$receipt['id']) === false) {
				$detail = trim($detail . ($detail !== '' ? ' · ' : '') . 'id ' . $receipt['id']);
			}
			$this->add($time, 'receipt', $label . ' accepted the message', $detail !== '' ? $detail : null,
				array('attempt_id' => intval($a->key)));
		}

		switch ((string)$a->get('mst_sent_copy_filed')) {
			case MailboxSendAttempt::SENT_COPY_APPENDED:
				$this->add($time, 'sent_copy', 'Copy filed in Sent', null); break;
			case MailboxSendAttempt::SENT_COPY_PROVIDER:
				$this->add($time, 'sent_copy', 'Copy filed in Sent by ' . $label, null); break;
			case MailboxSendAttempt::SENT_COPY_APPEND_FAILED:
				$this->add($time, 'sent_copy', 'Copy could not be filed in Sent', 'the message was sent; only the Sent-folder copy failed'); break;
		}

		$this->deliveryEvents($a, $label, $transport, $recipients);
	}

	/** What the carrier can say about arrival — cached on the attempt, refreshed while it moves. */
	private function deliveryEvents(MailboxSendAttempt $a, string $label, string $transport, array $recipients): void {
		if ($transport === 'connected_account') {
			$this->notes[] = $label . ' handled delivery — no delivery status is available here.';
			return;
		}
		if ($transport === '') {
			return;
		}
		$provider = EmailSender::providerFor($transport);
		if (!($provider instanceof DeliveryEventSource)) {
			$this->notes[] = 'Delivery status is not available from ' . $label . '.';
			return;
		}

		$status = (string)$a->get('mst_delivery_status') ?: DeliveryEventSource::DELIVERY_UNKNOWN;
		$checked = (string)$a->get('mst_delivery_checked_time');
		$settled = in_array($status, array(DeliveryEventSource::DELIVERY_DELIVERED, DeliveryEventSource::DELIVERY_FAILED), true);
		$stale = $checked === '' || (time() - strtotime($checked . ' UTC')) > self::DELIVERY_RECHECK_SECONDS;
		$reachable = true;
		if ($this->refresh || (!$settled && $stale)) {
			$from = (string)$a->get('mst_from_address');
			$at = strrpos($from, '@');
			$domain = $at !== false ? substr($from, $at + 1) : '';
			$answer = null;
			try {
				$answer = $provider->deliveryEvents((string)$a->get('mst_message_id_header'), $domain);
			} catch (Throwable $e) {
				error_log('MailboxMessageTimeline: delivery lookup failed: ' . $e->getMessage());
			}
			if ($answer === null) {
				$reachable = false;
			} else {
				MailboxSendAttempt::cacheDelivery(intval($a->key), (string)$answer['status'], $answer['events']);
				$status = (string)$answer['status'];
				$checked = gmdate('Y-m-d H:i:s');
				$a->set('mst_delivery_detail', json_encode($answer['events']));
			}
		}

		$events = $a->json('mst_delivery_detail');
		$meta = array('attempt_id' => intval($a->key), 'status' => $status, 'checked_time' => $checked !== '' ? $checked : null,
			'refreshable' => true, 'carrier' => $label);
		if (!$reachable) {
			$this->notes[] = 'Could not reach ' . $label . ' just now; showing what it said last time.';
		}
		if (!$events) {
			$this->add(null, 'delivery', $label . ' has no delivery events for this message',
				$checked !== '' ? 'it keeps them for a limited time' : null, $meta);
			return;
		}
		// The carrier's own "accepted" repeats the receipt line above; show it only
		// while it is the whole story (nothing later has happened yet).
		$beyond_accepted = count(array_filter($events, function ($e) { return ($e['event'] ?? '') !== 'accepted'; })) > 0;
		if ($beyond_accepted) {
			$events = array_values(array_filter($events, function ($e) { return ($e['event'] ?? '') !== 'accepted'; }));
		}
		$last = count($events) - 1;
		foreach ($events as $i => $e) {
			$who = (string)($e['recipient'] ?? '');
			$where = $who !== '' ? (strpos($who, '@') !== false ? substr($who, strpos($who, '@') + 1) : $who) : '';
			$detail = trim((string)($e['detail'] ?? ''));
			switch ((string)($e['event'] ?? '')) {
				case 'delivered': $title = 'Delivered' . ($where !== '' ? ' to ' . $where : ''); break;
				case 'failed':    $title = 'Delivery failed' . ($who !== '' ? ' for ' . $who : ''); break;
				case 'deferred':  $title = 'Delivery delayed' . ($where !== '' ? ' at ' . $where : '') . ' — ' . $label . ' is retrying'; break;
				case 'accepted':  $title = $label . ' queued it' . ($who !== '' ? ' for ' . $who : ''); break;
				default:          $title = ucfirst((string)($e['event'] ?? 'event')) . ($who !== '' ? ' — ' . $who : '');
			}
			$this->add((string)($e['time'] ?? '') ?: null, 'delivery', $title,
				$detail !== '' ? $detail . ' (from ' . $label . ')' : 'from ' . $label,
				array_merge($meta, array('event' => (string)($e['event'] ?? ''), 'checked_time' => $i === $last ? $meta['checked_time'] : null)));
		}
	}

	// ── helpers ────────────────────────────────────────────────────────────

	private function add(?string $time, string $kind, string $title, ?string $detail, array $meta = array()): void {
		$time = ($time === null || trim($time) === '') ? null : substr(trim($time), 0, 19);
		$this->events[] = array('time' => $time, 'kind' => $kind, 'title' => $title,
			'detail' => ($detail === null || $detail === '') ? null : $detail, 'meta' => $meta ?: null);
	}

	private function hasEventOfKind(string $kind): bool {
		foreach ($this->events as $e) {
			if ($e['kind'] === $kind) return true;
		}
		return false;
	}

	/**
	 * Timed events by time; hops keep header order among equal times; timeless
	 * state lines sit after the arrival (or, absent one, at the end).
	 */
	private function sort(): void {
		$arrival = null;
		foreach ($this->events as $e) {
			if ($e['kind'] === 'arrived' && $e['time'] !== null) { $arrival = $e['time']; break; }
		}
		$last_timed = null;
		foreach ($this->events as $e) {
			if ($e['time'] !== null && ($last_timed === null || $e['time'] > $last_timed)) $last_timed = $e['time'];
		}
		$anchor = $arrival ?: $last_timed ?: '0000-00-00 00:00:00';
		$i = 0;
		foreach ($this->events as &$e) {
			$e['_seq'] = $i++;
			$e['_key'] = $e['time'] ?? $anchor . '~'; // '~' sorts after any digit: right after the anchor's own events
		}
		unset($e);
		usort($this->events, function ($x, $y) {
			return strcmp($x['_key'], $y['_key']) ?: ($x['_seq'] <=> $y['_seq']);
		});
		foreach ($this->events as &$e) {
			unset($e['_seq'], $e['_key']);
		}
		unset($e);
	}

	private function aliasAddress(): string {
		$alias_id = intval($this->message->get('iem_iea_inbound_email_alias_id'));
		if ($alias_id > 0) {
			$alias = new InboundEmailAlias($alias_id, TRUE);
			if ($alias->key) {
				return strtolower($alias->get_full_address());
			}
		}
		$domain_id = intval($this->message->get('iem_ied_inbound_email_domain_id'));
		if ($domain_id > 0) {
			$domain = new InboundEmailDomain($domain_id, TRUE);
			if ($domain->key) {
				return 'catch-all on ' . $domain->get('ied_domain');
			}
		}
		return 'this mailbox';
	}

	/** The outbound row's From, without opening sealed content: it is the alias. */
	private function fromAddressPlain(): string {
		return $this->aliasAddress();
	}

	private function humanBytes(int $n): string {
		if ($n >= 1048576) return round($n / 1048576, 1) . ' MB';
		if ($n >= 1024) return round($n / 1024) . ' KB';
		return $n . ' bytes';
	}

	private static function truthy($v): bool {
		return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
	}
}
?>
