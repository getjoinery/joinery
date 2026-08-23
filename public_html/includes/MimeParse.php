<?php
/**
 * MimeParse - parse a raw MIME message without ever hanging on it.
 *
 * A message's bytes come from the outside world, and some of them are hostile
 * to the parser by accident: a 2011 newsletter that quotes its own MIME
 * boundary inside an HTML comment sends Horde_Mime_Part::_findBoundary() into
 * an infinite loop — strpos() finds the mid-line occurrence, the
 * beginning-of-line check rejects it, and the loop retries the same offset
 * forever. In a web request the execution limit ends it; in a cron worker
 * there is no limit, so one such message wedges the worker for good (observed
 * 2026-08-23: an archive import pinned a CPU for eight hours on a 7 KB
 * message).
 *
 * So the platform never calls Horde_Mime_Part::parseMessage() directly. It
 * calls parseMessage() here, which scans the raw text first for the one shape
 * known to hang the parser — a declared boundary occurring anywhere mid-line —
 * and refuses it with a MimeParseHazardException instead of parsing. Every
 * call site already treats a parse failure as "fall back or skip" (the legacy
 * body splitter, raw-storage fallback, a null part), so a refusal costs a
 * degraded parse of one pathological message, never a hung process.
 *
 * The scan is a superset of the parser's own boundary walk (it checks every
 * declared boundary against the whole text, where the parser only walks each
 * boundary within its own part), so it can refuse a message the parser would
 * have survived — the safe direction to be wrong in.
 *
 * @version 1.0
 */

class MimeParseHazardException extends RuntimeException {
}

class MimeParse {

	/**
	 * Parse a raw RFC822 message into a Horde_Mime_Part tree, refusing input
	 * that would hang the parser.
	 *
	 * @throws MimeParseHazardException  when the message contains a boundary
	 *                                   shape the parser cannot survive
	 * @throws Horde_Mime_Exception      whatever the parser itself throws
	 */
	public static function parseMessage(string $raw): Horde_Mime_Part {
		require_once(PathHelper::getComposerAutoloadPath());

		$boundary = self::hangingBoundary($raw);
		if ($boundary !== null) {
			throw new MimeParseHazardException(
				'MIME boundary appears mid-line in the message body'
				. ' (boundary "' . substr($boundary, 0, 80) . '"), which hangs the parser.');
		}

		return Horde_Mime_Part::parseMessage($raw);
	}

	/**
	 * The first declared boundary that also occurs somewhere mid-line, or NULL
	 * when the message is safe to parse.
	 *
	 * Mirrors the condition in Horde_Mime_Part::_findBoundary() exactly: an
	 * occurrence of "--{boundary}" at a position other than the start of the
	 * text whose preceding byte is not LF is the shape its scan can never
	 * advance past.
	 */
	public static function hangingBoundary(string $raw): ?string {
		if (!preg_match_all('/boundary\s*=\s*(?:"([^"]*)"|([^\s;]+))/i', $raw, $m)) {
			return null;
		}
		$boundaries = array();
		foreach (array_merge($m[1], $m[2]) as $candidate) {
			if ($candidate !== '') {
				$boundaries[$candidate] = true;
			}
		}
		foreach (array_keys($boundaries) as $boundary) {
			$search = '--' . $boundary;
			$pos = 0;
			while (($pos = strpos($raw, $search, $pos)) !== false) {
				if ($pos !== 0 && $raw[$pos - 1] !== "\n") {
					return $boundary;
				}
				$pos++;
			}
		}
		return null;
	}
}
?>
