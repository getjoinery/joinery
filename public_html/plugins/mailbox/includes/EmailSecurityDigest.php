<?php
/**
 * EmailSecurityDigest - deterministic PHP reduction of one inbound email to
 * the bounded evidence an AI security-scan checklist prompt needs.
 *
 * The LLM never sees raw MIME. Real phishing samples run 40 KB+ of
 * base64/invisible-character padding — enough to blow a small model's
 * effective attention — so this builder produces a fixed-section plain-text
 * digest instead: decoded headers, the stored authentication verdicts, every
 * extracted URL (with its visible anchor text when it differs — the classic
 * "link text says paypal.com, href goes elsewhere" signal survives
 * tag-stripping), a per-domain count summary so a small model never has to
 * aggregate twenty raw URLs itself, and the decoded body, each size-capped.
 * Whitespace/invisible padding is collapsed to a single space and the removed
 * count is annotated, turning the obfuscation itself into citable evidence
 * rather than context filler.
 *
 * Pure function of the message; no LLM concepts in this class. Consumed by
 * plugins/joinery_ai/pipeline_jobs/EmailSecurityScanJob.php. The digest
 * format and size caps are corpus-validated per
 * specs/joinery_ai_email_security_scan_eval.md — do not reword the section
 * headers, and any format change requires a full re-score against the
 * labelled corpus.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AuthenticationResults.php'));

class EmailSecurityDigest {

	const SUBJECT_CAP_CHARS = 1024;
	const BODY_CAP_CHARS    = 4096;
	const URL_CAP           = 20;
	const DOMAIN_CAP        = 15;
	const ANCHOR_TEXT_CAP_CHARS = 120;
	const WHITESPACE_ANNOTATE_THRESHOLD = 200;

	/** Runs of 4+ spaces, ideographic spaces, or zero-width/invisible code
	 *  points collapse to one space. "Runs (>3)" per spec == at least 4. */
	const WHITESPACE_RUN_PATTERN = '/[ \t\x{00A0}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{3000}]{4,}/u';

	public static function build(InboundEmailMessage $msg): string {
		$raw = $msg->getRawMessage();

		$from_raw       = $raw !== null ? self::extractHeader($raw, 'from') : (string)$msg->get('iem_sender');
		$reply_to_raw   = $raw !== null ? self::extractHeader($raw, 'reply-to') : null;
		$return_path_raw = $raw !== null ? self::extractHeader($raw, 'return-path') : null;
		$to_raw         = $raw !== null ? self::extractHeader($raw, 'to') : (string)$msg->get('iem_recipient');
		$date_raw       = $raw !== null ? self::extractHeader($raw, 'date') : (string)$msg->get('iem_received_time');
		$subject_raw    = $raw !== null ? self::extractHeader($raw, 'subject') : (string)$msg->get('iem_subject');

		$from       = self::decodeHeaderValue((string)$from_raw);
		$reply_to   = $reply_to_raw !== null && trim($reply_to_raw) !== '' ? self::decodeHeaderValue($reply_to_raw) : '(none)';
		$return_path = $return_path_raw !== null && trim($return_path_raw) !== '' ? trim($return_path_raw, "<> \t") : '(none)';
		$to         = self::decodeHeaderValue((string)$to_raw);
		$date       = trim((string)$date_raw) !== '' ? trim((string)$date_raw) : '(unknown)';

		$spf   = (string)($msg->get('iem_spf_result') ?: 'unverified');
		$dkim  = (string)($msg->get('iem_dkim_result') ?: 'unverified');
		$dmarc = (string)($msg->get('iem_dmarc_result') ?: 'unverified');
		$dkim_domain = self::dkimSigningDomain($raw);

		$subject_decoded = self::decodeHeaderValue((string)$subject_raw);
		[$subject_collapsed, $subject_removed] = self::collapseWhitespace($subject_decoded);
		[$subject_text, $subject_total] = self::capSize($subject_collapsed, self::SUBJECT_CAP_CHARS);

		$body_plain = (string)$msg->get('iem_body_plain');
		$body_html  = (string)$msg->get('iem_body_html');
		[$body_source, $body_label] = self::selectBody($body_plain, $body_html);
		[$body_collapsed, $body_removed] = self::collapseWhitespace($body_source);
		[$body_text, $body_total] = self::capSize($body_collapsed, self::BODY_CAP_CHARS);

		$urls = self::extractUrls($body_html, $body_plain);

		$lines = [];
		$lines[] = '=== EMAIL DIGEST ===';
		$lines[] = 'FROM: ' . $from;
		$lines[] = 'REPLY-TO: ' . $reply_to;
		$lines[] = 'RETURN-PATH: ' . $return_path;
		$lines[] = 'TO: ' . $to;
		$lines[] = 'DATE: ' . $date;
		$lines[] = 'AUTHENTICATION: spf=' . $spf . ' dkim=' . $dkim
			. ' (d=' . ($dkim_domain !== '' ? $dkim_domain : 'none') . ') dmarc=' . $dmarc;
		$lines[] = '';
		$lines[] = 'SUBJECT (decoded' . self::annotation($subject_removed) . '):';
		$lines[] = $subject_text;
		$lines[] = '';
		$lines[] = 'URLS FOUND (' . count($urls) . '):';
		if (empty($urls)) {
			$lines[] = '(none found)';
		} else {
			$domain_summary = self::domainSummary($urls);
			if ($domain_summary !== '') {
				$lines[] = 'DOMAINS: ' . $domain_summary;
			}
			$shown = array_slice($urls, 0, self::URL_CAP);
			foreach ($shown as $i => $entry) {
				$line = ($i + 1) . '. ' . $entry['url'];
				if ($entry['text'] !== '' && $entry['text'] !== $entry['url']) {
					$line .= ' — link text: "' . $entry['text'] . '"';
				}
				$lines[] = $line;
			}
			$remaining = count($urls) - count($shown);
			if ($remaining > 0) {
				$lines[] = '(+' . $remaining . ' more)';
			}
		}
		$lines[] = '';
		$lines[] = 'BODY (' . $body_label . ', decoded' . self::annotation($body_removed) . '):';
		$lines[] = $body_text;

		return implode("\n", $lines);
	}

	/** "; preprocessor removed N invisible/whitespace characters" once N exceeds
	 *  the threshold, else empty — the mechanical fact becomes citable evidence
	 *  only when it's a meaningful amount of padding, not incidental spacing. */
	private static function annotation(int $removed): string {
		if ($removed <= self::WHITESPACE_ANNOTATE_THRESHOLD) return '';
		return '; preprocessor removed ' . $removed . ' invisible/whitespace characters';
	}

	/**
	 * Prefer the decoded text/plain body; fall back to tag-stripped
	 * text/html. Both are already MIME-decoded at ingest time by
	 * InboundEmailRouter::extractBodies() / ImapIngestor, so this is a
	 * selection between two already-decoded columns, not a re-parse.
	 *
	 * @return array{0:string,1:string} [body text, source label for the BODY header]
	 */
	private static function selectBody(string $plain, string $html): array {
		if (trim($plain) !== '') {
			return [$plain, 'text/plain'];
		}
		if (trim($html) !== '') {
			$text = strip_tags($html);
			$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			return [$text, 'text/html tag-stripped'];
		}
		return ['(no body text)', 'text/plain'];
	}

	/**
	 * Collapse runs of whitespace/invisible characters to one space.
	 *
	 * @return array{0:string,1:int} [collapsed text, count of characters removed]
	 */
	private static function collapseWhitespace(string $text): array {
		$before = mb_strlen($text, 'UTF-8');
		$collapsed = preg_replace(self::WHITESPACE_RUN_PATTERN, ' ', $text);
		if ($collapsed === null) $collapsed = $text; // regex engine failure — degrade to uncollapsed, never fatal
		$after = mb_strlen($collapsed, 'UTF-8');
		return [$collapsed, max(0, $before - $after)];
	}

	/**
	 * @return array{0:string,1:int} [capped text (with a truncation marker
	 *   appended when it was cut), total character count before truncation]
	 */
	private static function capSize(string $text, int $cap_chars): array {
		$total = mb_strlen($text, 'UTF-8');
		if ($total <= $cap_chars) {
			return [$text, $total];
		}
		$cut = mb_substr($text, 0, $cap_chars, 'UTF-8');
		return [$cut . "\n[truncated, $total characters total]", $total];
	}

	/**
	 * Distinct URLs from both body parts — href attributes (html) first in
	 * document order, then bare http(s):// tokens in the visible text — deduped
	 * preserving first-occurrence order. Anchor hrefs also capture their
	 * visible link text (the text/href mismatch is the classic deception
	 * signal, and tag-stripping the body would otherwise destroy it). The cap
	 * and "(+N more)" marker are applied by the caller so this always returns
	 * the true total.
	 *
	 * @return array<array{url:string,text:string}>
	 */
	private static function extractUrls(string $html, string $plain): array {
		$found = []; // url => visible link text ('' when none); insertion order = first occurrence

		if (trim($html) !== '') {
			if (preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
				foreach ($m as $match) {
					$url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
					self::addUrl($found, $url, self::anchorText($match[2]));
				}
			}
			// hrefs outside <a> pairs (area/base tags, malformed markup)
			if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $m2)) {
				foreach ($m2[1] as $href) {
					self::addUrl($found, html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '');
				}
			}
		}

		$visible_text = trim($plain . ' ' . strip_tags($html));
		if (preg_match_all('/\bhttps?:\/\/[^\s"\'<>]+/i', $visible_text, $m3)) {
			foreach ($m3[0] as $url) {
				self::addUrl($found, rtrim($url, ".,;:!?)]}'\""), '');
			}
		}

		$out = [];
		foreach ($found as $url => $text) {
			$out[] = ['url' => (string)$url, 'text' => $text];
		}
		return $out;
	}

	/** Dedup by URL keeping first-occurrence order; a later occurrence may
	 *  fill in link text the first lacked, never overwrite it. */
	private static function addUrl(array &$found, string $url, string $text): void {
		$url = trim($url);
		if ($url === '') return;
		if (!array_key_exists($url, $found)) {
			$found[$url] = $text;
		} elseif ($found[$url] === '' && $text !== '') {
			$found[$url] = $text;
		}
	}

	/** Anchor inner HTML -> single-line visible text, size-capped. */
	private static function anchorText(string $inner): string {
		$text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));
		if (mb_strlen($text, 'UTF-8') > self::ANCHOR_TEXT_CAP_CHARS) {
			$text = mb_substr($text, 0, self::ANCHOR_TEXT_CAP_CHARS, 'UTF-8') . '…';
		}
		return $text;
	}

	/**
	 * "host (count), host (count), ..." over the deduped URL list, most
	 * frequent first (ties keep first-occurrence order), capped at DOMAIN_CAP
	 * hosts with a "+N more domains" marker. Empty when no URL yields a host
	 * (e.g. all mailto:).
	 *
	 * @param array<array{url:string,text:string}> $urls
	 */
	private static function domainSummary(array $urls): string {
		$counts = [];
		foreach ($urls as $entry) {
			$host = strtolower((string)parse_url($entry['url'], PHP_URL_HOST));
			if ($host === '') continue;
			$counts[$host] = ($counts[$host] ?? 0) + 1;
		}
		if (empty($counts)) return '';
		arsort($counts);

		$parts = [];
		foreach (array_slice($counts, 0, self::DOMAIN_CAP, true) as $host => $n) {
			$parts[] = $host . ' (' . $n . ')';
		}
		$extra = count($counts) - self::DOMAIN_CAP;
		if ($extra > 0) {
			$parts[] = '+' . $extra . ' more domains';
		}
		return implode(', ', $parts);
	}

	/**
	 * RFC 2047 decode (encoded-word headers), skipped entirely when the value
	 * carries no "=?...?=" marker. iconv_mime_decode's handling of the
	 * non-encoded portions assumes a single-byte charset, which corrupts a
	 * header that's already raw UTF-8 (the common case on modern 8BITMIME
	 * transport, and how iem_subject is already stored) — so plain text is
	 * passed through untouched rather than risk mangling it.
	 */
	private static function decodeHeaderValue(string $value): string {
		if ($value === '') return '';
		if (strpos($value, '=?') === false) return $value;
		$decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		return $decoded !== false ? $decoded : $value;
	}

	/**
	 * The DKIM signing domain (d=) via AuthenticationResults, trusting only a
	 * line stamped by our own mail host. Empty when there's no raw message,
	 * no configured hostname, or no trusted line.
	 */
	private static function dkimSigningDomain(?string $raw): string {
		if ($raw === null) return '';
		try {
			$settings = Globalvars::get_instance();
			$authserv_id = (string)$settings->get_setting('mailbox_mail_hostname');
			$parsed = AuthenticationResults::fromMessage($raw, $authserv_id);
			return $parsed !== null ? (string)($parsed->dkimDomain() ?? '') : '';
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * First occurrence of a header's unfolded value from raw RFC822 source, or
	 * null if absent. Case-insensitive on the header name; stops at the
	 * header/body boundary (first blank line). Same unfolding approach as
	 * AuthenticationResults::extractHeaders(), generalized to any header name.
	 */
	private static function extractHeader(string $raw_message, string $header_name): ?string {
		$normalized = str_replace("\r\n", "\n", $raw_message);
		$split_pos = strpos($normalized, "\n\n");
		$header_block = ($split_pos !== false) ? substr($normalized, 0, $split_pos) : $normalized;

		$header_name = strtolower($header_name);
		$collecting = false;
		$current = '';

		foreach (explode("\n", $header_block) as $line) {
			if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
				if ($collecting) $current .= ' ' . trim($line);
				continue;
			}
			if ($collecting) {
				return trim($current);
			}
			$colon = strpos($line, ':');
			if ($colon === false) continue;
			if (strtolower(trim(substr($line, 0, $colon))) === $header_name) {
				$collecting = true;
				$current = trim(substr($line, $colon + 1));
			}
		}
		return $collecting ? trim($current) : null;
	}

}
