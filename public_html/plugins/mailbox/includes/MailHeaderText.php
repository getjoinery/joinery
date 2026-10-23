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
 * @version 1.2 - decode() and block() drop NUL bytes, which end a database value early (B66)
 * @version 1.1 - block(): a raw header block as UTF-8, line by line, for keeping as text (B66)
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
		// A NUL is valid UTF-8 but no text: the database driver ends a value at
		// the first one, so whatever followed it would be lost without a word.
		return str_replace("\0", '', $out . self::plain(substr($value, $at)));
	}

	/**
	 * A raw header block (or one header line) as UTF-8, for keeping as text:
	 * encoded words are left as written, each line that is UTF-8 is kept, and a
	 * line that is not is read as Windows-1252. Line by line, because one old
	 * client's Latin-1 name and another's UTF-8 subject meet in the same block.
	 * Line endings are kept and NUL bytes dropped. $cut says the caller shortened the block to a
	 * length cap: then a last line ending part way through a character loses
	 * the broken tail, not the whole line (uncut, those bytes are Latin-1).
	 */
	public static function block(string $value, bool $cut = false): string {
		$pieces = preg_split('/(\r\n|\n)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
		$last = count($pieces) - 1;
		foreach ($pieces as $i => $piece) {
			if ($piece === '' || $piece === "\r\n" || $piece === "\n" || mb_check_encoding($piece, 'UTF-8')) {
				continue;
			}
			if ($cut && $i === $last) {
				for ($k = 1; $k <= 3 && $k < strlen($piece); $k++) {
					$tail = substr($piece, -$k);
					if (ord($tail[0]) >= 0xC2 && mb_check_encoding(substr($piece, 0, -$k), 'UTF-8')
							&& strspn(substr($tail, 1), implode('', array_map('chr', range(0x80, 0xBF)))) === $k - 1) {
						$pieces[$i] = substr($piece, 0, -$k);
						continue 2;
					}
				}
			}
			$pieces[$i] = self::plain($piece);
		}
		return str_replace("\0", '', implode('', $pieces));
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
