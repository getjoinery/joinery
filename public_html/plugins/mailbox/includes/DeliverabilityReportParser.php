<?php
/**
 * DeliverabilityReportParser — opens a deliverability report's bytes inside
 * the parser jail (specs/parser_jail.md) and answers with the flat record
 * DeliverabilityReportIngest files.
 *
 * A DMARC aggregate report is a zip or gzip a stranger sent, holding XML;
 * TLS-RPT is gzipped JSON. libzip, zlib and libxml2 open them, and this class
 * is where that happens: as the joinery-jail user, in a subprocess that holds
 * no key, no config and no database. The ingest, in the pool, hands over one
 * MIME part's bytes and reads back JSON:
 *
 *   {"kind": "dmarc_aggregate" | "tlsrpt" | null,
 *    "extract": {org_name, org_email, report_id, domain, begin, end, policy,
 *                message_count, sources: [...]} | null,
 *    "error": string | null}
 *
 * kind null means the bytes are not a report this class knows (or could not
 * be opened within the ceilings); kind set with extract null means a report
 * of that kind that would not parse, with the reason. The ingest maps kind to
 * DeliverabilityReport::KIND_* — that class is the pool's, not this one's.
 *
 * Report content is untrusted input (D9 of deliverability_report_ingest.md):
 * payloads are capped before and during decompression, XML with a DOCTYPE is
 * refused outright, and the parse goes through DocumentText::xmlDoc(), the
 * one XML door, which never resolves an entity.
 *
 * Runs only in the extraction subprocess; the pool never calls it directly.
 *
 * @version 1.0 - the parse half of DeliverabilityReportIngest 1.0, moved behind the jail
 */

class DeliverabilityReportParser implements SandboxParserInterface {

	/** Largest attachment payload considered a report (compressed). */
	const MAX_COMPRESSED_BYTES = 2097152;      // 2 MB
	/** Decompression ceiling — a compressed archive is an amplification vector. */
	const MAX_DECOMPRESSED_BYTES = 20971520;   // 20 MB

	const KIND_DMARC_AGGREGATE = 'dmarc_aggregate';
	const KIND_TLSRPT          = 'tlsrpt';

	public static function sandboxParse(string $bytes, array $options): string {
		if ($bytes === '' || strlen($bytes) > self::MAX_COMPRESSED_BYTES) {
			return self::answer(null, null, 'payload empty or over the compressed ceiling');
		}
		$payload = self::extractPayload($bytes);
		if ($payload === null) {
			return self::answer(null, null, 'payload not extractable within the size ceilings');
		}
		$structural = self::classifyPayload($payload);
		if ($structural === null) {
			return self::answer(null, null, 'payload is not a report structure this parser knows');
		}
		try {
			$extract = $structural['kind'] === self::KIND_DMARC_AGGREGATE
				? self::parseDmarcAggregate($structural['doc'])
				: self::parseTlsRpt($structural['doc']);
		} catch (\Throwable $e) {
			return self::answer($structural['kind'], null, $e->getMessage());
		}
		return self::answer($structural['kind'], $extract, null);
	}

	private static function answer(?string $kind, ?array $extract, ?string $error): string {
		$json = json_encode(array('kind' => $kind, 'extract' => $extract, 'error' => $error),
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
		return $json === false ? '{"kind":null,"extract":null,"error":"could not encode the answer"}' : $json;
	}

	// ── Opening the payload (D9 ceilings) ─────────────────────────────────

	/**
	 * Decompressed report document from one part's bytes, or null when not
	 * extractable within the ceilings. Handles zip (first entry), gzip, and
	 * uncompressed payloads, dispatching on magic bytes rather than the
	 * advertised content-type.
	 */
	private static function extractPayload(string $bytes): ?string {
		if (strncmp($bytes, "PK\x03\x04", 4) === 0) {
			return self::extractZip($bytes);
		}
		if (strncmp($bytes, "\x1f\x8b", 2) === 0) {
			return self::inflateCapped($bytes);
		}
		if (strlen($bytes) <= self::MAX_DECOMPRESSED_BYTES) {
			return $bytes;
		}
		return null;
	}

	/** Incremental gzip inflate with a hard output ceiling. */
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

	/**
	 * First entry of a zip archive, read through a stream with an output cap.
	 * ZipArchive opens files, so the bytes are staged in memory-backed tmpfs
	 * (0600 under the jail's umask) and unlinked as soon as the archive is
	 * open — the handle keeps reading.
	 */
	private static function extractZip(string $bytes): ?string {
		if (!class_exists('ZipArchive')) { return null; }
		if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) { return null; }
		$tmp = '/dev/shm/joinery_doctext_' . getmypid() . '_' . bin2hex(random_bytes(6));
		if (@file_put_contents($tmp, $bytes) === false) { return null; }
		@chmod($tmp, 0600);
		try {
			$zip = new ZipArchive();
			if ($zip->open($tmp) !== true) { return null; }
			@unlink($tmp);
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
			$doc = self::parseDocument($bytes);
			if ($doc !== null && $doc->documentElement !== null
					&& strtolower($doc->documentElement->localName) === 'feedback'
					&& $doc->getElementsByTagName('report_metadata')->length > 0) {
				return array('kind' => self::KIND_DMARC_AGGREGATE, 'doc' => $doc);
			}
			return null;
		}
		if ($trimmed[0] === '{') {
			$json = json_decode($bytes, true);
			if (is_array($json) && isset($json['organization-name']) && isset($json['policies'])) {
				return array('kind' => self::KIND_TLSRPT, 'doc' => $json);
			}
			return null;
		}
		return null;
	}

	/**
	 * A report's XML as a document, or null. A DOCTYPE is refused before the
	 * parser sees it (no report has one), and the parse itself is
	 * DocumentText::xmlDoc() — the one XML door, which passes LIBXML_NONET and
	 * never LIBXML_NOENT. Sandbox side only.
	 */
	public static function parseDocument(string $xml): ?DOMDocument {
		if (stripos($xml, '<!DOCTYPE') !== false) { return null; }
		try {
			return DocumentText::xmlDoc($xml);
		} catch (\Throwable $e) {
			return null;
		}
	}

	// ── Parsers (one per kind) ────────────────────────────────────────────

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
}
?>
