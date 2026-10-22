<?php
/**
 * CsvWriter - RFC 4180 CSV records for files people open in a spreadsheet.
 *
 * Every cell is defused before it is written: a cell that starts with = + - @,
 * a tab or a carriage return is one a spreadsheet would run as a formula, so it
 * gains a leading apostrophe and opens as plain text. That matters because
 * exported cells often hold text a stranger chose, such as the display name on
 * an incoming message. A cell whose apostrophes already stand before one of
 * those gains one more, so CsvReader, which takes exactly one off again, reads
 * every file written here back as it was.
 *
 * Callers emit BOM first when the file is UTF-8, so Excel reads accents and
 * non-Latin names correctly.
 *
 * @version 1.0
 */

class CsvWriter {

	/** UTF-8 byte order mark: the start of a file Excel should read as UTF-8. */
	const BOM = "\xEF\xBB\xBF";

	/** First characters a spreadsheet treats as the start of a formula. */
	const FORMULA_STARTS = array('=', '+', '-', '@', "\t", "\r");

	/** One record, CRLF-terminated. A cell is quoted only when it has to be. */
	public static function line(array $cells): string {
		$out = array();
		foreach ($cells as $cell) {
			$cell = self::defuse((string)$cell);
			if (strpbrk($cell, ",\"\r\n") !== false) {
				$cell = '"' . str_replace('"', '""', $cell) . '"';
			}
			$out[] = $cell;
		}
		return implode(',', $out) . "\r\n";
	}

	/**
	 * The cell as plain text: a leading apostrophe when it would otherwise run as a
	 * formula, or when its own apostrophes stand before a formula character (so the
	 * one CsvReader takes off is never the cell's own).
	 */
	public static function defuse(string $cell): string {
		$body = ltrim($cell, "'");
		if ($body !== '' && in_array($body[0], self::FORMULA_STARTS, true)) {
			return "'" . $cell;
		}
		return $cell;
	}
}
?>
