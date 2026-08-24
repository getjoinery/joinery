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
 * Report content is untrusted input from a stranger (D9): payloads are
 * size-capped before and during decompression, XML is parsed with DOCTYPE
 * refused and external entities never resolved, and a failure of any kind is
 * recorded rather than ever aborting ingest of the carrying message.
 *
 * @version 1.0
 */

class DeliverabilityReportIngest {

	/** Largest attachment payload we will consider a report (compressed). */
	const MAX_COMPRESSED_BYTES = 2097152;      // 2 MB
	/** Decompression ceiling — a compressed archive is an amplification vector. */
	const MAX_DECOMPRESSED_BYTES = 20971520;   // 20 MB
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
	 *    'payload_name' => ?string, 'parsed_payload' => mixed]
	 * where payload is the decompressed report document when one was
	 * extractable and parsed_payload is its structural parse (DOMDocument for
	 * XML, array for JSON, null when unreadable).
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
		$payload = null; $payload_name = null; $parsed_payload = null; $kind = null;
		foreach ($candidates as $c) {
			$bytes = self::extractPayload($c['part']);
			if ($bytes === null) { continue; }
			$structural = self::classifyPayload($bytes);
			if ($structural !== null) {
				$payload = $bytes;
				$payload_name = $c['name'];
				$parsed_payload = $structural['doc'];
				$kind = $structural['kind'];
				break;
			}
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
			'payload_name' => $payload_name, 'parsed_payload' => $parsed_payload);
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
	 * Decompressed report document from one MIME part, or null when the part
	 * is not extractable within the D9 ceilings. Handles zip (first entry),
	 * gzip, and uncompressed payloads, dispatching on magic bytes rather than
	 * the advertised content-type.
	 */
	private static function extractPayload($part): ?string {
		try {
			$bytes = (string)$part->getContents();
		} catch (\Throwable $e) {
			return null;
		}
		if ($bytes === '' || strlen($bytes) > self::MAX_COMPRESSED_BYTES) {
			return null;
		}

		// zip
		if (strncmp($bytes, "PK\x03\x04", 4) === 0) {
			return self::extractZip($bytes);
		}
		// gzip
		if (strncmp($bytes, "\x1f\x8b", 2) === 0) {
			return self::inflateCapped($bytes);
		}
		// already a document
		if (strlen($bytes) <= self::MAX_DECOMPRESSED_BYTES) {
			return $bytes;
		}
		return null;
	}

	/** Incremental gzip inflate with a hard output ceiling (D9). */
	private static function inflateCapped(string $bytes): ?string {
		$ctx = @inflate_init(ZLIB_ENCODING_GZIP);
		if ($ctx === false) { return null; }
		$out = '';
		foreach (str_split($bytes, 65536) as $chunk) {
			$piece = @inflate_add($ctx, $chunk);
			if ($piece === false) { return null; }
			$out .= $piece;
			if (strlen($out) > self::MAX_DECOMPRESSED_BYTES) { return null; }
		}
		$tail = @inflate_add($ctx, '', ZLIB_FINISH);
		if ($tail !== false) { $out .= $tail; }
		if ($out === '' || strlen($out) > self::MAX_DECOMPRESSED_BYTES) { return null; }
		return $out;
	}

	/** First entry of a zip archive, read through a stream with an output cap (D9). */
	private static function extractZip(string $bytes): ?string {
		if (!class_exists('ZipArchive')) { return null; }
		$tmp = tempnam(sys_get_temp_dir(), 'dvr');
		if ($tmp === false) { return null; }
		try {
			file_put_contents($tmp, $bytes);
			$zip = new ZipArchive();
			if ($zip->open($tmp) !== true) { return null; }
			try {
				if ($zip->numFiles < 1) { return null; }
				$stream = $zip->getStream((string)$zip->getNameIndex(0));
				if ($stream === false) { return null; }
				$out = '';
				while (!feof($stream)) {
					$chunk = fread($stream, 65536);
					if ($chunk === false) { break; }
					$out .= $chunk;
					if (strlen($out) > self::MAX_DECOMPRESSED_BYTES) { fclose($stream); return null; }
				}
				fclose($stream);
				return $out !== '' ? $out : null;
			} finally {
				$zip->close();
			}
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * Structural classification of a decompressed payload:
	 * DMARC aggregate XML → ['kind' => …, 'doc' => DOMDocument];
	 * TLS-RPT JSON → ['kind' => …, 'doc' => array]; anything else → null.
	 */
	private static function classifyPayload(string $bytes): ?array {
		$trimmed = ltrim($bytes);
		if ($trimmed === '') { return null; }

		if ($trimmed[0] === '<') {
			$doc = self::parseXmlSafely($bytes);
			if ($doc !== null && strtolower($doc->documentElement->localName) === 'feedback'
					&& $doc->getElementsByTagName('report_metadata')->length > 0) {
				return array('kind' => DeliverabilityReport::KIND_DMARC_AGGREGATE, 'doc' => $doc);
			}
			return null;
		}
		if ($trimmed[0] === '{') {
			$json = json_decode($bytes, true);
			if (is_array($json) && isset($json['organization-name']) && isset($json['policies'])) {
				return array('kind' => DeliverabilityReport::KIND_TLSRPT, 'doc' => $json);
			}
			return null;
		}
		return null;
	}

	/** XML parse with DOCTYPE refused and external entities never resolved (D9). */
	private static function parseXmlSafely(string $xml): ?DOMDocument {
		if (stripos($xml, '<!DOCTYPE') !== false) { return null; }
		$doc = new DOMDocument();
		$prior = libxml_use_internal_errors(true);
		try {
			// No LIBXML_NOENT (entities stay unexpanded), LIBXML_NONET (no fetches)
			if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) || $doc->documentElement === null) {
				return null;
			}
			return $doc;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($prior);
		}
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

		if ($kind === DeliverabilityReport::KIND_DMARC_AGGREGATE) {
			try {
				$extract = self::parseDmarcAggregate($detection['parsed_payload']);
			} catch (\Throwable $e) {
				$parse_error = $e->getMessage();
			}
		} elseif ($kind === DeliverabilityReport::KIND_TLSRPT) {
			try {
				$extract = self::parseTlsRpt($detection['parsed_payload']);
			} catch (\Throwable $e) {
				$parse_error = $e->getMessage();
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

	/**
	 * RFC 7489 aggregate XML → the common extract shape:
	 * ['org_name','org_email','report_id','domain','begin','end','policy',
	 *  'message_count','sources' => [['ip','count','disposition','dkim','spf',
	 *  'aligned','header_from','envelope_from','auth_detail'], …]]
	 */
	public static function parseDmarcAggregate(DOMDocument $doc): array {
		$meta = $doc->getElementsByTagName('report_metadata')->item(0);
		if ($meta === null) { throw new RuntimeException('missing report_metadata'); }
		$policy_el = $doc->getElementsByTagName('policy_published')->item(0);

		$domain = $policy_el !== null ? self::childText($policy_el, 'domain') : '';
		if ($domain === '') { throw new RuntimeException('missing policy_published domain'); }

		$begin = null; $end = null;
		$range = $meta->getElementsByTagName('date_range')->item(0);
		if ($range !== null) {
			$b = (int)self::childText($range, 'begin');
			$e = (int)self::childText($range, 'end');
			if ($b > 0) { $begin = gmdate('Y-m-d H:i:s', $b); }
			if ($e > 0) { $end = gmdate('Y-m-d H:i:s', $e); }
		}

		$policy = array();
		if ($policy_el !== null) {
			foreach (array('domain', 'adkim', 'aspf', 'p', 'sp', 'pct', 'np') as $k) {
				$v = self::childText($policy_el, $k);
				if ($v !== '') { $policy[$k] = $v; }
			}
		}

		$sources = array(); $message_count = 0;
		foreach ($doc->getElementsByTagName('record') as $record) {
			$rowEl = self::firstChildNamed($record, 'row');
			if ($rowEl === null) { continue; }
			$ip = self::childText($rowEl, 'source_ip');
			if ($ip === '') { continue; }
			$count = max(1, (int)self::childText($rowEl, 'count'));
			$dkim = null; $spf = null; $disposition = '';
			$pe = self::firstChildNamed($rowEl, 'policy_evaluated');
			if ($pe !== null) {
				$disposition = self::childText($pe, 'disposition');
				$dkim = self::childText($pe, 'dkim') ?: null;
				$spf  = self::childText($pe, 'spf') ?: null;
			}
			$header_from = ''; $envelope_from = '';
			$ids = self::firstChildNamed($record, 'identifiers');
			if ($ids !== null) {
				$header_from = self::childText($ids, 'header_from');
				$envelope_from = self::childText($ids, 'envelope_from');
			}
			$auth_detail = array();
			$ar = self::firstChildNamed($record, 'auth_results');
			if ($ar !== null) {
				foreach ($ar->childNodes as $child) {
					if (!($child instanceof DOMElement)) { continue; }
					$entry = array();
					foreach ($child->childNodes as $f) {
						if ($f instanceof DOMElement) { $entry[$f->localName] = trim($f->textContent); }
					}
					$auth_detail[] = array('method' => $child->localName) + $entry;
				}
			}
			$sources[] = array(
				'ip' => $ip, 'count' => $count, 'disposition' => $disposition,
				'dkim' => $dkim, 'spf' => $spf,
				'aligned' => ($dkim === 'pass' || $spf === 'pass'),
				'header_from' => $header_from, 'envelope_from' => $envelope_from,
				'auth_detail' => $auth_detail,
			);
			$message_count += $count;
		}
		if (count($sources) === 0) { throw new RuntimeException('report contains no records'); }

		return array(
			'org_name'  => self::childText($meta, 'org_name'),
			'org_email' => self::childText($meta, 'email'),
			'report_id' => self::childText($meta, 'report_id'),
			'domain'    => strtolower($domain),
			'begin'     => $begin, 'end' => $end,
			'policy'    => $policy,
			'message_count' => $message_count,
			'sources'   => $sources,
		);
	}

	/**
	 * RFC 8460 TLS-RPT JSON. Failure details become source rows carrying the
	 * failure result-type as their disposition; TLS failures are not
	 * alignment failures, so rows stay aligned=true and never trip the D7
	 * forgery notice.
	 */
	public static function parseTlsRpt(array $json): array {
		$policies = $json['policies'] ?? array();
		if (!is_array($policies) || count($policies) === 0) {
			throw new RuntimeException('TLS-RPT report has no policies');
		}
		$domain = '';
		$sources = array(); $message_count = 0;
		foreach ($policies as $p) {
			if ($domain === '' && isset($p['policy']['policy-domain'])) {
				$domain = strtolower((string)$p['policy']['policy-domain']);
			}
			$summary = $p['summary'] ?? array();
			$message_count += (int)($summary['total-successful-session-count'] ?? 0)
				+ (int)($summary['total-failure-session-count'] ?? 0);
			foreach (($p['failure-details'] ?? array()) as $f) {
				$ip = (string)($f['sending-mta-ip'] ?? '');
				if ($ip === '') { continue; }
				$sources[] = array(
					'ip' => $ip,
					'count' => max(1, (int)($f['failed-session-count'] ?? 1)),
					'disposition' => 'tls:' . (string)($f['result-type'] ?? 'failure'),
					'dkim' => null, 'spf' => null, 'aligned' => true,
					'header_from' => '', 'envelope_from' => '',
					'auth_detail' => array(),
				);
			}
		}
		if ($domain === '') { throw new RuntimeException('TLS-RPT report names no policy-domain'); }

		$begin = null; $end = null;
		if (isset($json['date-range']['start-datetime'])) {
			$t = strtotime((string)$json['date-range']['start-datetime']);
			if ($t) { $begin = gmdate('Y-m-d H:i:s', $t); }
		}
		if (isset($json['date-range']['end-datetime'])) {
			$t = strtotime((string)$json['date-range']['end-datetime']);
			if ($t) { $end = gmdate('Y-m-d H:i:s', $t); }
		}

		return array(
			'org_name'  => (string)($json['organization-name'] ?? ''),
			'org_email' => (string)($json['contact-info'] ?? ''),
			'report_id' => (string)($json['report-id'] ?? ''),
			'domain'    => $domain,
			'begin'     => $begin, 'end' => $end,
			'policy'    => array(),
			'message_count' => $message_count,
			'sources'   => $sources,
		);
	}

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

	private static function childText(DOMNode $el, string $name): string {
		foreach ($el->childNodes as $child) {
			if ($child instanceof DOMElement && strtolower($child->localName) === $name) {
				return trim($child->textContent);
			}
		}
		return '';
	}

	private static function firstChildNamed(DOMNode $el, string $name): ?DOMElement {
		foreach ($el->childNodes as $child) {
			if ($child instanceof DOMElement && strtolower($child->localName) === $name) {
				return $child;
			}
		}
		return null;
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
