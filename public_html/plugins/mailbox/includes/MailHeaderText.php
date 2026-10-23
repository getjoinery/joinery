<?php
/**
 * MailHeaderText - a header value (a Subject, a display name) as readable UTF-8.
 *
 * A header carries text two ways, often both at once: RFC 2047 encoded words
 * (=?charset?B|Q?...?=), and, under RFC 6532 and 8BITMIME transport, plain
 * UTF-8 bytes. PHP's own decoders keep only the first: mb_decode_mimeheader()
 * turns every raw non-ASCII byte into "?", and iconv_mime_decode() drops them.
 * This decodes the encoded words and keeps everything else as it came:
 *
 *   - each encoded word in its own charset (RFC 2231 language tags dropped),
 *     with the space between two adjacent words removed (RFC 2047 § 6.2);
 *   - text outside the words kept as it is when it is UTF-8, and read as
 *     Windows-1252 when it is not (an old client's Latin-1 header), which maps
 *     every byte, so nothing is lost and the database never sees bytes that
 *     are not UTF-8;
 *   - folded lines unfolded.
 *
 * @version 1.0
 */

class MailHeaderText {

	const WORD = '/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/';

	/** The header value as UTF-8. */
	public static function decode(string $value): string {
		if ($value === '') {
			return '';
		}
		$value = preg_replace('/\r?\n(?=[ \t])/', '', $value);
		$out = '';
		$at = 0;
		$last_was_word = false;
		if (preg_match_all(self::WORD, $value, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			foreach ($mm as $m) {
				$between = substr($value, $at, $m[0][1] - $at);
				// Only whitespace between two encoded words is folding, not text.
				if (!($last_was_word && trim($between, " \t") === '')) {
					$out .= self::plain($between);
				}
				$out .= self::word($m[1][0], $m[2][0], $m[3][0], $m[0][0]);
				$at = $m[0][1] + strlen($m[0][0]);
				$last_was_word = true;
			}
		}
		return $out . self::plain(substr($value, $at));
	}

	/** Text outside the encoded words: UTF-8 kept, anything else read as Windows-1252. */
	private static function plain(string $text): string {
		if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}
		return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
	}

	/** One encoded word's text, or the word as written when it cannot be read. */
	private static function word(string $charset, string $encoding, string $text, string $whole): string {
		$charset = strtolower(preg_replace('/\*.*$/', '', $charset));
		if (strtoupper($encoding) === 'B') {
			$bytes = base64_decode($text, true);
			if ($bytes === false) {
				return $whole;
			}
		} else {
			$bytes = preg_replace_callback('/=([0-9A-Fa-f]{2})/', function ($h) { return chr(hexdec($h[1])); },
				str_replace('_', ' ', $text));
		}
		if ($charset === 'utf-8' || $charset === 'us-ascii') {
			return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
		}
		$converted = false;
		if (in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
			$converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
		}
		if ($converted === false || $converted === null) {
			$converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
		}
		if ($converted === false || !mb_check_encoding((string)$converted, 'UTF-8')) {
			return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
		}
		return (string)$converted;
	}
}
?>
