<?php
/**
 * DeliverabilityReportIngest — detects and files machine-generated
 * deliverability reports during inbound ingest
 * (specs/deliverability_report_ingest.md).
 *
 * Providers send reports about a domain's mail — DMARC aggregate XML, TLS-RPT
 * JSON, ARF feedback-loop complaints — to an ordinary address, because the
 * domain's published policy asked for them. This class runs at the moments the
 * inbound pipeline holds a message's plaintext:
 *
 *   - receive time: InboundEmailRouter::processEmail() before the alias
 *     branch (Postfix pipe + provider webhooks), and RelaySpoolConsumer for
 *     mail pulled from the hardened relay
 *   - deferred parse: InboundEmailRouter::parsePendingMessage(), where a
 *     Fortress relay message's content first exists (at the owner's unlock)
 *
 * A recognised report is FILED, never delivered (D3): its per-source lines
 * are written to dvs_deliverability_report_sources — the sender inventory —
 * and no mailbox message is created. Detection is by content, never by
 * address (D1): two of three signals (attachment filename shape, subject
 * shape, payload structure) must match, so an ordinary message carrying a
 * .zip is untouched while a misaddressed report is still caught.
 *
 * Report content is untrusted input from a stranger (D9), and nothing here
 * opens it: the bytes of a candidate part go to DeliverabilityReportParser
 * through DocumentText::parseWith(), which runs it in the parser jail
 * (specs/parser_jail.md) — libzip, zlib and libxml2 work as a user that holds
 * no key, no config and no database — and what comes back is the flat record
 * this class files. The ceilings, the DOCTYPE refusal and the one XML door
 * live there. A failure of any kind is recorded rather than ever aborting
 * ingest of the carrying message.
 *
 * @version 1.1 - the parse moves behind the jail; this class detects and files
 * @version 1.0
 */

class DeliverabilityReportIngest {

	/** Largest attachment payload we will consider a report (compressed). */
	const MAX_COMPRESSED_BYTES = DeliverabilityReportParser::MAX_COMPRESSED_BYTES;
	/** Decompression ceiling, enforced inside the jail. */
	const MAX_DECOMPRESSED_BYTES = DeliverabilityReportParser::MAX_DECOMPRESSED_BYTES;
	/** Cap on the raw carrier message kept for an unparseable report. */
	const RAW_KEEP_CAP_BYTES = 4194304;        // 4 MB

	/** D7 escalation: a known source is re-notified when a single report's
	 *  count reaches BOTH this floor and this multiple of its prior maximum. */
	const ESCALATION_FLOOR = 100;
	const ESCALATION_MULTIPLE = 10;

	/** Tests only: when set, D7 notices are handed to this callable
	 *  (fn($domain, array $notable)) instead of being emailed. */
	public static $notice_capture = null;

	/**
	 * Detect and, when recognised, file a report. The single entry point for
	 * every plaintext moment.
	 *
	 * Returns null when the message is NOT a deliverability report — the
	 * caller delivers it normally, untouched. Returns an outcome string when
	 * the message was consumed: 'filed', 'failed_kept', 'unparsed_kept',
	 * 'dedup', or 'discarded' (a report about a domain this platform does not
	 * host, D9). Never throws: any internal failure logs and returns null so
	 * the carrying message falls back to ordinary delivery.
	 *
	 * @param InboundEmailRouter $router  for MIME part enumeration
	 * @param string $raw_email          full raw message
	 * @param array  $parsed             parseEmail() output
	 * @param InboundEmailDomain $domain the ARRIVING hosted domain
	 * @param string $recipient          envelope recipient it arrived at
	 */
	public static function intercept($router, string $raw_email, array $parsed, $domain, string $recipient): ?string {
		try {
			$detection = self::detect($router, $raw_email, $parsed);
			if ($detection === null) {
				return null;
			}
			return self::file($detection, $raw_email, $parsed, $domain, $recipient);
		} catch (\Throwable $e) {
			// Never let report handling break mail delivery — fall back to
			// treating the message as ordinary mail.
			error_log('DeliverabilityReportIngest: intercept failed, delivering normally: ' . $e->getMessage());
			return null;
		}
	}

	// ── Detection (D1: two of three signals) ────────────────────────────

	/**
	 * The two-of-three content test. Returns null (not a report) or:
	 *   ['kind' => DeliverabilityReport::KIND_*, 'payload' => ?string,
	 *    'payload_name' => ?string, 'parsed_payload' => ?array,
	 *    'parse_error' => ?string]
	 * where payload is the part's bytes when the jail recognised them as a
	 * report, parsed_payload is the flat extract the jail returned (null when
	 * the report would not parse), and parse_error says why when it did not.
	 */
	public static function detect($router, string $raw_email, array $parsed): ?array {
		$subject = self::headerString($parsed, 'subject');
		$content_type = self::headerString($parsed, 'content-type');

		// ARF (RFC 5965): the carrier's own content-type is definitive.
		if (preg_match('#multipart/report#i', $content_type)
				&& preg_match('#report-type\s*=\s*"?feedback-report#i', $content_type)) {
			return array('kind' => DeliverabilityReport::KIND_ARF,
				'payload' => null, 'payload_name' => null, 'parsed_payload' => null);
		}

		// Signal 1 — subject shape shared by DMARC aggregate (RFC 7489) and
		// TLS-RPT (RFC 8460): "Report Domain: <domain> Submitter: <org> …"
		$sig_subject = (bool)preg_match('/report\s+domain:\s*\S+.*submitter:/is', $subject);

		// Signal 2 — attachment filename shape: receiver!domain!begin!end[!id].{xml,json}[.gz] or .zip
		$candidates = self::candidateParts($router, $raw_email);
		$sig_name = false;
		foreach ($candidates as $c) {
			if ($c['name_match']) { $sig_name = true; break; }
		}

		// Signal 3 — payload structure. Extraction is attempted only when at
		// least one other signal is present, so ordinary mail carrying a zip
		// is never decompressed speculatively (acceptance 3).
		if (!$sig_subject && !$sig_name) {
			return null;
		}
		$payload = null; $payload_name = null; $parsed_payload = null; $parse_error = null; $kind = null;
		foreach ($candidates as $c) {
			$bytes = self::partBytes($c['part']);
			if ($bytes === null) { continue; }
			$answer = self::openInJail($bytes);
			if ($answer === null || $answer['kind'] === null) { continue; }
			$payload = $bytes;
			$payload_name = $c['name'];
			$parsed_payload = is_array($answer['extract']) ? $answer['extract'] : null;
			$parse_error = $answer['error'];
			$kind = self::kindFor($answer['kind']);
			break;
		}
		$sig_payload = ($payload !== null);

		$signals = ($sig_subject ? 1 : 0) + ($sig_name ? 1 : 0) + ($sig_payload ? 1 : 0);
		if ($signals < 2) {
			return null;
		}

		if ($kind === null) {
			// Detected as report mail (subject + filename) but the payload
			// would not open/classify — recorded, never silently dropped (D5).
			$kind = DeliverabilityReport::KIND_UNKNOWN;
		}
		return array('kind' => $kind, 'payload' => $payload,
			'payload_name' => $payload_name, 'parsed_payload' => $parsed_payload,
			'parse_error' => $parse_error);
	}

	/**
	 * Non-text MIME parts with their filename-shape verdicts, size-capped.
	 * MIME parse hazards are treated as "no candidates", not as errors.
	 */
	private static function candidateParts($router, string $raw_email): array {
		$out = array();
		try {
			$parts = $router->enumerateNonTextParts($raw_email);
		} catch (\Throwable $e) {
			return $out;
		}
		foreach ($parts as $part) {
			$name = (string)$part->getName();
			$out[] = array(
				'part' => $part,
				'name' => $name,
				'name_match' => (bool)preg_match(
					'/^[^!]+![^!]+!\d+!\d+(?:![^.!]+)?\.(?:(?:xml|json)(?:\.gz)?|zip)$/i', $name),
			);
		}
		return $out;
	}

	/**
	 * One MIME part's bytes, size-capped, or null. The cap is the only look
	 * this class takes at them: everything past it happens in the jail.
	 */
	private static function partBytes($part): ?string {
		try {
			$bytes = (string)$part->getContents();
		} catch (\Throwable $e) {
			return null;
		}
		if ($bytes === '' || strlen($bytes) > self::MAX_COMPRESSED_BYTES) {
			return null;
		}
		return $bytes;
	}

	/**
	 * Hand the bytes to DeliverabilityReportParser in the jail and decode its
	 * answer: ['kind' => ?string, 'extract' => ?array, 'error' => ?string].
	 * Null when the subprocess itself failed (logged) — treated as "not a
	 * payload", never as a reason to drop the carrying message.
	 */
	private static function openInJail(string $bytes): ?array {
		$r = DocumentText::parseWith('DeliverabilityReportParser', $bytes);
		if ($r['status'] !== DocumentText::OK) {
			error_log('DeliverabilityReportIngest: report parser failed: ' . (string)$r['detail']);
			return null;
		}
		$answer = json_decode($r['text'], true);
		if (!is_array($answer) || !array_key_exists('kind', $answer)) {
			return null;
		}
		return array(
			'kind'    => is_string($answer['kind']) ? $answer['kind'] : null,
			'extract' => isset($answer['extract']) && is_array($answer['extract']) ? $answer['extract'] : null,
			'error'   => isset($answer['error']) && is_string($answer['error']) ? $answer['error'] : null,
		);
	}

	/** The jail's kind name → this plugin's constant. */
	private static function kindFor(string $jail_kind): string {
		if ($jail_kind === DeliverabilityReportParser::KIND_DMARC_AGGREGATE) return DeliverabilityReport::KIND_DMARC_AGGREGATE;
		if ($jail_kind === DeliverabilityReportParser::KIND_TLSRPT) return DeliverabilityReport::KIND_TLSRPT;
		return DeliverabilityReport::KIND_UNKNOWN;
	}

	// ── Filing (D3, D4, D6, D9) ─────────────────────────────────────────

	/**
	 * Parse the detected report and persist it. Returns the outcome string
	 * for intercept(). The carrying message is never stored as mail.
	 */
	private static function file(array $detection, string $raw_email, array $parsed, $arriving_domain, string $recipient): string {
		$kind = $detection['kind'];
		$extract = null;
		$parse_error = null;

		if ($kind === DeliverabilityReport::KIND_DMARC_AGGREGATE || $kind === DeliverabilityReport::KIND_TLSRPT) {
			// Parsed in the jail at detection; here it is either the record or
			// the reason there is none.
			$extract = is_array($detection['parsed_payload'] ?? null) ? $detection['parsed_payload'] : null;
			if ($extract === null) {
				$parse_error = (string)($detection['parse_error'] ?? 'report did not parse');
			}
		} elseif ($kind === DeliverabilityReport::KIND_ARF) {
			try {
				$extract = self::parseArf($raw_email, $parsed, $arriving_domain);
			} catch (\Throwable $e) {
				$parse_error = $e->getMessage();
			}
		} else {
			$parse_error = 'No parser for this report kind';
		}

		// Which hosted domain does this concern? A parsed report names its
		// domain; an unparsed one is attributed to the domain it arrived at.
		$reported_domain_name = $extract !== null ? $extract['domain'] : '';
		$domain = $arriving_domain;
		if ($reported_domain_name !== '' && strcasecmp($reported_domain_name, (string)$arriving_domain->get('ied_domain')) !== 0) {
			$hosted = InboundEmailDomain::GetByDomain(strtolower($reported_domain_name));
			if (!$hosted) {
				// D9: the reporter's word grants nothing. A report about a
				// domain this platform does not host is discarded — recorded
				// in the transaction log, no rows, no delivery.
				self::logOutcome($parsed, $recipient, $arriving_domain,
					'discarded report: ' . $kind . ' for unhosted domain ' . $reported_domain_name);
				return 'discarded';
			}
			$domain = $hosted;
		}

		$status = ($extract !== null) ? DeliverabilityReport::PARSE_PARSED
			: ($kind === DeliverabilityReport::KIND_UNKNOWN ? DeliverabilityReport::PARSE_UNPARSED
				: DeliverabilityReport::PARSE_FAILED);

		$report_id = $extract !== null && $extract['report_id'] !== ''
			? $extract['report_id'] : hash('sha256', $raw_email);

		$row = new DeliverabilityReport(NULL);
		$row->set('dvr_ied_inbound_email_domain_id', $domain->key);
		$row->set('dvr_kind', $kind);
		$row->set('dvr_org_name', $extract !== null ? substr($extract['org_name'], 0, 255) : '');
		$row->set('dvr_org_email', $extract !== null ? substr($extract['org_email'], 0, 255) : '');
		$row->set('dvr_report_id', substr($report_id, 0, 255));
		$row->set('dvr_domain', $extract !== null ? substr($extract['domain'], 0, 255)
			: (string)$arriving_domain->get('ied_domain'));
		$row->set('dvr_recipient', substr($recipient, 0, 500));
		$row->set('dvr_parse_status', $status);
		if ($extract !== null) {
			if ($extract['begin'] !== null) { $row->set('dvr_begin_time', $extract['begin']); }
			if ($extract['end'] !== null)   { $row->set('dvr_end_time', $extract['end']); }
			if (!empty($extract['policy'])) { $row->set('dvr_policy_published', json_encode($extract['policy'])); }
			$row->set('dvr_source_count', count($extract['sources']));
			$row->set('dvr_message_count', $extract['message_count']);
		} else {
			$row->set('dvr_parse_error', substr((string)$parse_error, 0, 4000));
			// D6: the original is the only way to learn why it failed — keep it.
			$row->set('dvr_raw_report', substr($raw_email, 0, self::RAW_KEEP_CAP_BYTES));
		}

		// D7 first-sighting check runs BEFORE this report's rows exist, so the
		// insert can't shadow its own novelty test.
		$notify = ($extract !== null && $kind === DeliverabilityReport::KIND_DMARC_AGGREGATE)
			? self::assessNewSources($domain, $extract['sources']) : array();

		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) { $db->beginTransaction(); }
		try {
			$row->save();
			if ($extract !== null) {
				foreach ($extract['sources'] as $s) {
					$src = new DeliverabilityReportSource(NULL);
					$src->set('dvs_dvr_deliverability_report_id', $row->key);
					$src->set('dvs_ied_inbound_email_domain_id', $domain->key);
					$src->set('dvs_source_ip', substr($s['ip'], 0, 45));
					$src->set('dvs_count', max(1, (int)$s['count']));
					$src->set('dvs_disposition', substr((string)$s['disposition'], 0, 40));
					if ($s['dkim'] !== null) { $src->set('dvs_dkim_result', substr($s['dkim'], 0, 16)); }
					if ($s['spf'] !== null)  { $src->set('dvs_spf_result', substr($s['spf'], 0, 16)); }
					$src->set('dvs_aligned', $s['aligned']);
					if ($s['header_from'] !== '')   { $src->set('dvs_header_from', substr($s['header_from'], 0, 255)); }
					if ($s['envelope_from'] !== '') { $src->set('dvs_envelope_from', substr($s['envelope_from'], 0, 255)); }
					if (!empty($s['auth_detail'])) { $src->set('dvs_auth_detail', json_encode($s['auth_detail'])); }
					if ($extract['end'] !== null) { $src->set('dvs_end_time', $extract['end']); }
					$src->save();
				}
			}
			if ($owns_tx) { $db->commit(); }
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) { $db->rollBack(); }
			if (self::isUniqueViolation($e)) {
				// Provider retry of a report already filed — consumed, no rows.
				self::logOutcome($parsed, $recipient, $domain, 'duplicate report: ' . $kind . ' ' . $report_id);
				return 'dedup';
			}
			throw $e;
		}

		self::logOutcome($parsed, $recipient, $domain,
			$kind . ' from ' . ($extract !== null ? $extract['org_name'] : 'unknown reporter')
			. ' (' . $status . ($extract !== null ? ', ' . count($extract['sources']) . ' sources' : '') . ')');

		if (!empty($notify)) {
			self::sendNewSourceNotice($domain, $notify);
		}

		if ($extract === null) {
			return $status === DeliverabilityReport::PARSE_UNPARSED ? 'unparsed_kept' : 'failed_kept';
		}
		return 'filed';
	}

	// ── Parsers (one per kind, D5) ──────────────────────────────────────
	// DMARC aggregate and TLS-RPT parse in the jail (DeliverabilityReportParser).
	// ARF is header-style fields in the carrier's own body: a regular
	// expression over text, no C parser, so it stays here.

	/**
	 * RFC 5965 ARF feedback loop (DMARC forensic mail arrives in the same
	 * shape). One source row per complaint: a recipient at a large provider
	 * marked the domain's mail as spam. A complaint is about mail the domain
	 * really sent, so rows stay aligned=true.
	 */
	public static function parseArf(string $raw_email, array $parsed, $arriving_domain): array {
		// The machine-readable part is message/feedback-report: header-style
		// fields in its body. Find it in the raw by its content-type.
		if (!preg_match('#content-type:\s*message/feedback-report.*?\n\n(.*?)(\n--|\z)#is',
				str_replace("\r\n", "\n", $raw_email), $m)) {
			throw new RuntimeException('no message/feedback-report part');
		}
		$fields = array();
		foreach (explode("\n", $m[1]) as $line) {
			if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $fm)) {
				$fields[strtolower($fm[1])] = trim($fm[2]);
			}
		}
		$ip = (string)($fields['source-ip'] ?? '');
		if ($ip === '') { throw new RuntimeException('feedback-report names no Source-IP'); }
		$domain = strtolower((string)($fields['reported-domain'] ?? ''));
		if ($domain === '') { $domain = strtolower((string)$arriving_domain->get('ied_domain')); }

		$arrival = null;
		if (!empty($fields['arrival-date'])) {
			$t = strtotime($fields['arrival-date']);
			if ($t) { $arrival = gmdate('Y-m-d H:i:s', $t); }
		}
		$message_id = self::headerString($parsed, 'message-id');

		return array(
			'org_name'  => (string)($fields['user-agent'] ?? ''),
			'org_email' => '',
			'report_id' => $message_id !== '' ? $message_id : '',
			'domain'    => $domain,
			'begin'     => $arrival, 'end' => $arrival,
			'policy'    => array(),
			'message_count' => 1,
			'sources'   => array(array(
				'ip' => $ip, 'count' => 1,
				'disposition' => 'complaint:' . (string)($fields['feedback-type'] ?? 'abuse'),
				'dkim' => null, 'spf' => null, 'aligned' => true,
				'header_from' => (string)($fields['original-mail-from'] ?? ''),
				'envelope_from' => (string)($fields['original-mail-from'] ?? ''),
				'auth_detail' => array_intersect_key($fields,
					array_flip(array('feedback-type', 'user-agent', 'authentication-results'))),
			)),
		);
	}

	// ── D7: a new unaligned source is worth one email, once ─────────────

	/**
	 * Which of this report's unaligned sources deserve the one notice — run
	 * BEFORE the report's own rows are inserted. A source never seen for this
	 * domain is 'new'; a known one whose single-report volume jumped past the
	 * escalation thresholds is 'escalation'; everything else stays silent.
	 *
	 * @return array of ['ip','count','header_from','reason' => 'new'|'escalation']
	 */
	private static function assessNewSources($domain, array $sources): array {
		$notify = array();
		$seen_ips = array();
		foreach ($sources as $s) {
			if ($s['aligned'] || isset($seen_ips[$s['ip']])) { continue; }
			$seen_ips[$s['ip']] = true;
			$prior = new MultiDeliverabilityReportSource(array(
				'domain_id' => intval($domain->key), 'source_ip' => $s['ip'],
			));
			$prior_max = 0; $prior_exists = false;
			foreach ($prior as $p) {
				$prior_exists = true;
				$prior_max = max($prior_max, (int)$p->get('dvs_count'));
			}
			if (!$prior_exists) {
				$notify[] = array('ip' => $s['ip'], 'count' => $s['count'],
					'header_from' => $s['header_from'], 'reason' => 'new');
			} elseif ($s['count'] >= self::ESCALATION_FLOOR
					&& $s['count'] >= self::ESCALATION_MULTIPLE * max(1, $prior_max)) {
				$notify[] = array('ip' => $s['ip'], 'count' => $s['count'],
					'header_from' => $s['header_from'], 'reason' => 'escalation');
			}
		}
		return $notify;
	}

	/**
	 * The one email (D7). Batched per report: a report naming several new
	 * sources costs one message listing them, not several. Best-effort — a
	 * send failure logs and never unwinds the filing.
	 */
	private static function sendNewSourceNotice($domain, array $notable): void {
		if (self::$notice_capture !== null) {
			call_user_func(self::$notice_capture, $domain, $notable);
			return;
		}
		try {
			$settings = Globalvars::get_instance();
			$from = trim((string)$settings->get_setting('defaultemail'));
			$blocker = EmailSender::transactionalSendBlocker($from);
			if ($blocker !== null) {
				error_log('DeliverabilityReportIngest: new-source notice suppressed — ' . $blocker);
				return;
			}
			$to = '';
			$owner_id = intval($domain->get('ied_owner_usr_user_id'));
			if ($owner_id > 0) {
				try {
					$owner = new User($owner_id, TRUE);
					$to = trim((string)$owner->get('usr_email'));
				} catch (\Throwable $e) { /* fall through to site contacts */ }
			}
			if ($to === '') { $to = trim((string)$settings->get_setting('contact_email')); }
			if ($to === '') { $to = $from; }
			if ($to === '') { return; }

			$domain_name = (string)$domain->get('ied_domain');
			$lines = array();
			foreach ($notable as $n) {
				$lines[] = ($n['reason'] === 'new'
						? '- New unauthorised sender: '
						: '- Known sender, sharp volume increase: ')
					. $n['ip'] . ' (' . $n['count'] . ' message' . ($n['count'] == 1 ? '' : 's')
					. ($n['header_from'] !== '' ? ', claiming to be ' . $n['header_from'] : '') . ')';
			}
			$body = "A mail provider's DMARC report named "
				. (count($notable) == 1 ? 'a sender' : count($notable) . ' senders')
				. " sending as " . $domain_name . " without authorisation:\n\n"
				. implode("\n", $lines) . "\n\n"
				. "Each is either a forgery or a system of yours sending unaligned mail. "
				. "Sources already known are not repeated — the full inventory is in the "
				. "mailbox admin's reports view:\n"
				. LibraryFunctions::get_absolute_url(
					'/plugins/mailbox/admin/admin_mailbox_reports?domain_id=' . intval($domain->key)) . "\n";

			$msg = new EmailMessage();
			$msg->from($from)
				->to($to)
				->subject('New sender detected for ' . $domain_name)
				->text($body);
			(new EmailSender())->send($msg);
		} catch (\Throwable $e) {
			error_log('DeliverabilityReportIngest: new-source notice failed: ' . $e->getMessage());
		}
	}

	// ── Small shared helpers ────────────────────────────────────────────

	/** Record the consumed message in the inbound transaction log. */
	private static function logOutcome(array $parsed, string $recipient, $domain, string $note): void {
		try {
			InboundEmailLog::CreateEntry(
				(string)($parsed['from'] ?? ''), $recipient,
				(string)($parsed['subject'] ?? ''), $note,
				InboundEmailLog::STATUS_REPORT_FILED, null, null,
				$domain ? intval($domain->key) : null);
		} catch (\Throwable $e) {
			error_log('DeliverabilityReportIngest: could not log outcome: ' . $e->getMessage());
		}
	}

	private static function headerString(array $parsed, string $name): string {
		$v = $parsed['headers'][$name] ?? ($parsed[$name] ?? '');
		if (is_array($v)) { $v = $v[0] ?? ''; }
		return (string)$v;
	}

	private static function isUniqueViolation(\Throwable $e): bool {
		if ($e instanceof PDOException && (string)$e->getCode() === '23505') { return true; }
		$prev = $e->getPrevious();
		if ($prev instanceof PDOException && (string)$prev->getCode() === '23505') { return true; }
		// SystemBase::save() pre-validates uniqueness and throws
		// DisplayableUserException — "Duplicate value for …" (single column) or
		// "Duplicate combination for …" (unique_with) — the same pairing
		// storeMessage recognises. A concurrent insert trips the DB constraint
		// (23505) instead; all of them mean the report is already filed.
		return stripos($e->getMessage(), 'duplicate key value') !== false
			|| stripos($e->getMessage(), 'Duplicate value for') !== false
			|| stripos($e->getMessage(), 'Duplicate combination for') !== false
			|| stripos($e->getMessage(), 'already exists') !== false;
	}
}
?>
