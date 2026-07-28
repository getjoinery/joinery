<?php
/**
 * MboxSplitter - find the message boundaries in an mbox without loading it.
 *
 * mbox is one file holding every message end to end, separated by a line starting
 * "From " in the first column. Gmail Takeout, Thunderbird and Apple Mail all
 * export this way, and the files run to tens of gigabytes — so the splitter never
 * holds more than a line at a time and reports each message as an offset and a
 * length. The importer seeks straight to a message when it needs the bytes.
 *
 * Two details keep it honest on real files:
 *
 * A "From " line only separates messages when a blank line came before it (or it
 * is the very start of the file). Without that rule, body text beginning "From "
 * would split a message in half.
 *
 * The format escapes body lines that would otherwise look like separators by
 * prefixing ">", so `>From ` on the way out means `From ` on the way back in.
 * Reading undoes exactly one level, which is why a line that genuinely began
 * ">From " survives the round trip.
 *
 * Splitting is resumable at any message boundary: it stops at a deadline or a
 * message count and hands back the offset to start from next time.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReader.php'));

class MboxSplitter {

	/** Header lines captured per message. Real headers never approach this. */
	const MAX_HEADER_LINES = 400;

	/**
	 * Walk messages from $startOffset, calling $emit for each.
	 *
	 * $emit receives ['offset'=>int, 'length'=>int, 'headers'=>array].
	 *
	 * Returns ['next' => ?int, 'count' => int]: 'next' is the offset to resume
	 * from, or null when the stream is exhausted.
	 *
	 * @param resource $stream Readable, seekable stream positioned anywhere.
	 */
	public static function split($stream, int $startOffset, callable $emit, float $deadline, int $maxMessages): array {
		fseek($stream, $startOffset);

		$pos = $startOffset;
		$messageStart = null;
		$headerLines = array();
		$inHeaders = true;
		// The start of the region we are reading counts as "after a blank line", so
		// the first separator is recognised whether we opened the file or resumed
		// at a boundary.
		$previousBlank = true;
		$count = 0;

		while (($line = fgets($stream)) !== false) {
			$lineStart = $pos;
			$pos += strlen($line);
			$blank = (trim($line) === '');

			if ($previousBlank && strncmp($line, 'From ', 5) === 0) {
				if ($messageStart !== null) {
					$emit(array(
						'offset'  => $messageStart,
						'length'  => $lineStart - $messageStart,
						'headers' => MailArchiveReader::parseHeaders(implode("\n", $headerLines)),
					));
					$count++;
					// A separator is the only safe place to stop, and we are standing
					// on one: resume by re-reading this very line.
					if ($count >= $maxMessages || microtime(true) >= $deadline) {
						return array('next' => $lineStart, 'count' => $count);
					}
				}
				$messageStart = $pos; // content begins after the separator line
				$headerLines = array();
				$inHeaders = true;
				$previousBlank = false;
				continue;
			}

			if ($messageStart !== null && $inHeaders) {
				if ($blank) {
					$inHeaders = false;
				} elseif (count($headerLines) < self::MAX_HEADER_LINES) {
					$headerLines[] = rtrim($line, "\r\n");
				}
			}

			$previousBlank = $blank;
		}

		// End of file: the last message runs to here.
		if ($messageStart !== null && $pos > $messageStart) {
			$emit(array(
				'offset'  => $messageStart,
				'length'  => $pos - $messageStart,
				'headers' => MailArchiveReader::parseHeaders(implode("\n", $headerLines)),
			));
			$count++;
		}

		return array('next' => null, 'count' => $count);
	}

	/**
	 * The RFC822 bytes of one message, with mbox's From-escaping undone.
	 *
	 * @param resource $stream Readable, seekable stream.
	 */
	public static function readMessage($stream, int $offset, int $length): string {
		fseek($stream, $offset);
		$raw = '';
		$remaining = $length;
		while ($remaining > 0 && !feof($stream)) {
			$chunk = fread($stream, min(1048576, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$raw .= $chunk;
			$remaining -= strlen($chunk);
		}
		return MailArchiveReader::unescapeMboxFrom(rtrim($raw, "\r\n") . "\n");
	}
}
?>
