<?php
/**
 * CsvReader - parse an uploaded CSV file into whole records.
 *
 * Records are read whole, so a quoted field that holds a line break (the Notes
 * column of a Google Contacts export, routinely) stays one field of one row.
 * Splitting the file into lines first and parsing each line breaks the row
 * there and shifts every column after it.
 *
 * Parsed as RFC 4180: the escape character is empty, so a backslash is an
 * ordinary character. PHP's default treats it as an escape, and a backslash in
 * a name then swallows the quote after it.
 *
 * Also accepted: a leading UTF-8 byte order mark, a UTF-16 file (known by its
 * byte order mark), a file that is not UTF-8 at all (read as Windows-1252, the
 * code page desktop Excel and Outlook save in),
 * and a file whose lines end in a lone carriage return (older Mac spreadsheets).
 * Every cell comes back as valid UTF-8. A cell CsvWriter defused
 * (apostrophes before = + - @, tab or carriage return) loses one apostrophe,
 * so its files read back as they were written.
 *
 * @version 1.0
 */

class CsvReader {

	/**
	 * Every record in the file, each a list of strings. Blank lines are skipped.
	 *
	 * @return array<int, string[]>
	 */
	public static function records(string $content): array {
		if (strncmp($content, "\xFF\xFE", 2) === 0) {
			$content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
		} elseif (strncmp($content, "\xFE\xFF", 2) === 0) {
			$content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
		} elseif (strncmp($content, CsvWriter::BOM, 3) === 0) {
			$content = substr($content, 3);
		}
		if (!mb_check_encoding($content, 'UTF-8')) {
			// Desktop Excel and Outlook save CSV in the Windows code page. Windows-1252
			// maps every byte, so what comes back is always UTF-8.
			$content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
		}
		if (strpos($content, "\n") === false) {
			$content = str_replace("\r", "\n", $content);
		}

		$fp = fopen('php://temp', 'r+');
		fwrite($fp, $content);
		rewind($fp);
		$records = array();
		while (($cells = fgetcsv($fp, 0, ',', '"', '')) !== false) {
			if ($cells === array(null)) {
				continue;
			}
			$records[] = array_map(array(__CLASS__, 'restore'), $cells);
		}
		fclose($fp);
		return $records;
	}

	/** Undo CsvWriter::defuse(). */
	private static function restore($cell): string {
		$cell = (string)$cell;
		$body = ltrim($cell, "'");
		if ($body !== $cell && $body !== '' && in_array($body[0], CsvWriter::FORMULA_STARTS, true)) {
			return substr($cell, 1);
		}
		return $cell;
	}
}
?>
