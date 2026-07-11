<?php
/**
 * EmailAttachmentDigest - deterministic ATTACHMENTS section for one inbound
 * email, appended by a consuming job after EmailSecurityDigest::build().
 *
 * A sibling to EmailSecurityDigest, not a change to it: that class's format
 * is corpus-validated for the security scan job (its own header says any
 * format change requires a full re-score), so it stays untouched. Jobs that
 * want attachment evidence append this builder's output themselves.
 *
 * Lists every non-inline attachment's metadata (filename, content type,
 * size) and, for file-backed parts only, the readable text of text/plain
 * bodies and text/calendar (.ics) invites — parsed with IcsImporter and
 * rendered as a deterministic ICS EVENT block so a scheduling job can read
 * an invite's own title/start/end/timezone instead of inferring them from
 * prose. Binary extraction (PDF text, OCR, Office docs) is out of scope.
 *
 * Never throws — an unreadable or malformed part degrades to a bracketed
 * marker after its metadata line and the loop moves on to the next part.
 *
 * specs/joinery_ai_email_attachments.md
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
require_once(PathHelper::getIncludePath('includes/calendar/IcsImporter.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));

class EmailAttachmentDigest {

	const MAX_PARTS           = 10;   // manifest rows listed
	const FILENAME_CAP_CHARS  = 120;
	const TEXT_PER_PART_CHARS = 2000; // text/plain or rendered ICS, per part
	const TEXT_TOTAL_CHARS    = 4000; // all attachment text combined

	/** Same collapsing idea as EmailSecurityDigest::WHITESPACE_RUN_PATTERN. */
	const WHITESPACE_RUN_PATTERN = '/[ \t\x{00A0}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{3000}]{4,}/u';

	/** The ATTACHMENTS digest section for one message, or '' when the
	 *  message has no non-inline attachments. Never throws. */
	public static function build(InboundEmailMessage $msg): string {
		$attachments = new MultiInboundMessageAttachment([
			'message_id' => (int)$msg->key,
			'is_inline'  => false,
		]);
		$attachments->load();

		$total = count($attachments);
		if ($total === 0) {
			return '';
		}

		$lines = [];
		$lines[] = 'ATTACHMENTS (' . $total . '):';

		$shown = 0;
		$text_used = 0;
		foreach ($attachments as $att) {
			if ($shown >= self::MAX_PARTS) {
				break;
			}
			$shown++;
			$lines[] = $shown . '. ' . self::metadataLine($att);

			$text_block = self::readTextBlock($msg, $att, $text_used);
			if ($text_block !== '') {
				$lines[] = $text_block;
			}
		}

		$remaining = $total - $shown;
		if ($remaining > 0) {
			$lines[] = '(+' . $remaining . ' more attachments)';
		}

		return implode("\n", $lines);
	}

	/** "invoice.pdf — application/pdf, 48211 bytes" */
	private static function metadataLine(InboundMessageAttachment $att): string {
		$filename = trim((string)($att->get('ima_filename') ?: ''));
		$filename = self::collapseWhitespace($filename);
		if ($filename === '') {
			$filename = '(unnamed)';
		} elseif (mb_strlen($filename, 'UTF-8') > self::FILENAME_CAP_CHARS) {
			$filename = mb_substr($filename, 0, self::FILENAME_CAP_CHARS, 'UTF-8');
		}

		$content_type = trim((string)($att->get('ima_content_type') ?: ''));
		if ($content_type === '') {
			$content_type = 'application/octet-stream';
		}

		$size = (int)$att->get('ima_size_bytes');

		return $filename . ' — ' . $content_type . ', ' . $size . ' bytes';
	}

	/**
	 * The text block for one part (an ICS render, collapsed text/plain
	 * bytes, or a bracketed failure marker), or '' when the part is not
	 * file-backed or is neither text/calendar nor text/plain.
	 */
	private static function readTextBlock(InboundEmailMessage $msg, InboundMessageAttachment $att, int &$text_used): string {
		if ($att->get('ima_fil_file_id') === null) {
			// Section-pointer or IMAP ('remote') part — an unattended job
			// never does on-demand IMAP fetches. Metadata line only.
			return '';
		}

		try {
			$file_id = (int)$att->get('ima_fil_file_id');
			$file = new File($file_id, TRUE);
			// A real row has a key; a load() that found no row leaves $file->key
			// falsy (SystemBase never resets it on a failed load).
			if (!$file->key || $file->get('fil_delete_time')) {
				throw new RuntimeException('attachment file is no longer available');
			}
			$bytes = $file->read_bytes('original');
			if ($bytes === null || $bytes === '') {
				throw new RuntimeException('attachment bytes could not be read');
			}
			// read_bytes() returns raw on-disk bytes, bypassing File's decrypt
			// hook — open explicitly. Should be unreachable: nextItem() already
			// excludes sealed messages and attachments seal only when their
			// message does, but this is a defensive catch, not an assumption.
			$bytes = InboundEmailMessage::openSealedAttachment($msg, $att, $bytes);
		} catch (Throwable $e) {
			return '[content unreadable]';
		}

		$content_type = (string)($att->get('ima_content_type') ?: '');
		$filename = (string)($att->get('ima_filename') ?: '');

		if (self::looksLikeIcs($content_type, $filename, $bytes)) {
			try {
				return self::renderIcsEvents($bytes, $text_used);
			} catch (Throwable $e) {
				return '[calendar attachment could not be parsed]';
			}
		}

		if (self::isTextPlain($content_type)) {
			return self::capText($bytes, $text_used);
		}

		return '';
	}

	private static function looksLikeIcs(string $content_type, string $filename, string $bytes): bool {
		if (stripos(trim($content_type), 'text/calendar') === 0) {
			return true;
		}
		if (preg_match('/\.ics$/i', $filename)) {
			$probe = ltrim($bytes, "\xEF\xBB\xBF");
			if (strpos($probe, 'BEGIN:VCALENDAR') === 0) {
				return true;
			}
		}
		return false;
	}

	private static function isTextPlain(string $content_type): bool {
		return stripos(trim($content_type), 'text/plain') === 0;
	}

	/**
	 * Parse with IcsImporter::parse() and render each VEVENT as a
	 * deterministic block. Throws (caller renders the malformed marker)
	 * when parsing finds no VEVENT at all.
	 */
	private static function renderIcsEvents(string $bytes, int &$text_used): string {
		$parsed = IcsImporter::parse($bytes);
		$events = $parsed['events'] ?? [];
		if (empty($events)) {
			throw new RuntimeException('no VEVENT found in calendar attachment');
		}

		$blocks = [];
		foreach ($events as $event) {
			$blocks[] = self::renderOneEvent($event['props'] ?? []);
		}

		return self::capText(implode("\n", $blocks), $text_used);
	}

	/**
	 * ICS EVENT: <SUMMARY>
	 *   start: <DTSTART value + tz as parsed>   end: <DTEND or duration-derived>
	 *   location: <LOCATION or (none)>   organizer: <ORGANIZER or (none)>
	 */
	private static function renderOneEvent(array $props): string {
		$summary = isset($props['SUMMARY']['value']) ? trim((string)$props['SUMMARY']['value']) : '';
		if ($summary === '') {
			$summary = '(untitled event)';
		}

		$start = isset($props['DTSTART']) ? self::formatIcsStart($props['DTSTART']) : '(none)';
		$end = self::formatIcsEnd($props);

		$location = isset($props['LOCATION']['value']) ? trim((string)$props['LOCATION']['value']) : '';
		if ($location === '') {
			$location = '(none)';
		}
		$organizer = isset($props['ORGANIZER']['value']) ? trim((string)$props['ORGANIZER']['value']) : '';
		if ($organizer === '') {
			$organizer = '(none)';
		}

		return "ICS EVENT: $summary\n"
			. "  start: $start   end: $end\n"
			. "  location: $location   organizer: $organizer";
	}

	/** DTSTART rendered with its timezone (TZID param, or UTC for a 'Z' value). */
	private static function formatIcsStart(array $prop): string {
		$parsed = self::parseIcsDt((string)($prop['value'] ?? ''));
		if ($parsed === null) {
			return trim((string)($prop['value'] ?? '')) ?: '(none)';
		}
		$display = $parsed['is_date'] ? $parsed['date'] : $parsed['date'] . ' ' . $parsed['time'];
		if ($parsed['is_utc']) {
			return $display . ' UTC';
		}
		$tzid = (string)($prop['params']['TZID'] ?? '');
		return $tzid !== '' ? $display . ' ' . $tzid : $display;
	}

	/** DTEND value, or a DURATION-derived end when DTEND is absent. No tz suffix
	 *  (the start line already states it; the event is a single time zone). */
	private static function formatIcsEnd(array $props): string {
		if (isset($props['DTEND'])) {
			$parsed = self::parseIcsDt((string)($props['DTEND']['value'] ?? ''));
			if ($parsed === null) {
				return trim((string)($props['DTEND']['value'] ?? '')) ?: '(none)';
			}
			return $parsed['is_date'] ? $parsed['date'] : $parsed['date'] . ' ' . $parsed['time'];
		}

		if (isset($props['DURATION']) && isset($props['DTSTART'])) {
			$start = self::parseIcsDt((string)($props['DTSTART']['value'] ?? ''));
			$secs = self::durationToSeconds((string)($props['DURATION']['value'] ?? ''));
			if ($start !== null && !$start['is_date'] && $secs !== null) {
				$start_str = $start['date'] . ' ' . $start['time'];
				return LibraryFunctions::time_shift($start_str, $secs . ' seconds', 'Y-m-d H:i:s');
			}
		}

		return '(none)';
	}

	/** Parse a basic iCal DATE or DATE-TIME value (mirrors IcsImporter's
	 *  private parser; duplicated here since that one isn't exposed). */
	private static function parseIcsDt(string $value): ?array {
		$value = trim($value);
		$is_utc = false;
		if ($value !== '' && substr($value, -1) === 'Z') {
			$is_utc = true;
			$value = substr($value, 0, -1);
		}
		if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
			return ['date' => "$m[1]-$m[2]-$m[3]", 'time' => '00:00:00', 'is_utc' => $is_utc, 'is_date' => true];
		}
		if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
			return ['date' => "$m[1]-$m[2]-$m[3]", 'time' => "$m[4]:$m[5]:$m[6]", 'is_utc' => $is_utc, 'is_date' => false];
		}
		return null;
	}

	/** ISO 8601 duration -> seconds (mirrors IcsImporter's private parser). */
	private static function durationToSeconds(string $d): ?int {
		if (!preg_match('/^([+-]?)P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($d), $m)) {
			return null;
		}
		$sign = ($m[1] === '-') ? -1 : 1;
		$secs = ((int)($m[2] ?? 0) * 7 + (int)($m[3] ?? 0)) * 86400
			+ (int)($m[4] ?? 0) * 3600
			+ (int)($m[5] ?? 0) * 60
			+ (int)($m[6] ?? 0);
		return $sign * $secs;
	}

	private static function collapseWhitespace(string $text): string {
		$collapsed = preg_replace(self::WHITESPACE_RUN_PATTERN, ' ', $text);
		return $collapsed === null ? $text : $collapsed;
	}

	/**
	 * Collapse whitespace and cap $text against both the per-part cap and
	 * the remaining combined-total budget, appending the same
	 * "[truncated, N characters total]" marker style the body cap uses.
	 * Returns '' once the total budget is already spent.
	 */
	private static function capText(string $text, int &$text_used): string {
		$text = self::collapseWhitespace($text);
		$len = mb_strlen($text, 'UTF-8');

		$remaining_total = max(0, self::TEXT_TOTAL_CHARS - $text_used);
		$cap = min(self::TEXT_PER_PART_CHARS, $remaining_total);
		if ($cap <= 0) {
			return '';
		}

		if ($len <= $cap) {
			$text_used += $len;
			return $text;
		}

		$cut = mb_substr($text, 0, $cap, 'UTF-8');
		$text_used += $cap;
		return $cut . "\n[truncated, $len characters total]";
	}

}
