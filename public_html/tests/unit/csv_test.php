<?php
/** @joinery-test
 * name: csv
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * CsvWriter and CsvReader: the CSV a member downloads opens safely in a
 * spreadsheet, and what we write reads back as it was.
 *
 *  - a cell that would run as a formula (= + - @ tab CR) opens as text, and
 *    reads back without the apostrophe that defused it; a cell whose own
 *    apostrophes stand before one reads back with them
 *  - commas, quotes and line breaks are quoted; a record ends in CRLF
 *  - a quoted line break stays inside its field and its row (contacts B2)
 *  - a backslash is an ordinary character, not an escape
 *  - a byte order mark, blank lines and lone-CR line endings are accepted
 *  - a UTF-16 file is read by its byte order mark; a file that is not UTF-8 is
 *    read as Windows-1252, so every cell is UTF-8
 *
 * Run: php tests/run.php --only=tests/unit/csv_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('Writing');
check(CsvWriter::line(array('a', 'b c', '')) === "a,b c,\r\n", 'plain cells are written bare, the record ends in CRLF');
check(CsvWriter::line(array('Smith, Jo', 'say "hi"', "two\nlines")) === "\"Smith, Jo\",\"say \"\"hi\"\"\",\"two\nlines\"\r\n",
	'a comma, a quote or a line break is quoted, and quotes are doubled');
foreach (array('=1+1', '+44 20', '-2', '@SUM(A1)', "\tx", "\rx") as $cell) {
	check(CsvWriter::defuse($cell) === "'" . $cell, 'a cell starting ' . json_encode($cell[0]) . ' gains an apostrophe');
}
check(CsvWriter::defuse('Alice') === 'Alice' && CsvWriter::defuse('') === '' && CsvWriter::defuse('a=b') === 'a=b'
	&& CsvWriter::defuse("'quoted") === "'quoted" && CsvWriter::defuse("'") === "'",
	'other cells are left alone, a leading apostrophe before plain text included');
check(CsvWriter::defuse("'=x") === "''=x" && CsvWriter::defuse("''-1") === "'''-1",
	'apostrophes already before a formula character gain one more');
check(CsvWriter::line(array('=HYPERLINK("x")')) === "\"'=HYPERLINK(\"\"x\"\")\"\r\n", 'line() defuses every cell');

section('Reading');
$rows = array(
	array('Name', 'Notes', 'E-mail 1 - Value'),
	array('=cmd|calc', "line one\nline two\r\nline three", 'a@example.com'),
	array('Back\\slash "quoted"', '', 'b@example.com'),
	array('-5', '@me', '+1 555'),
	array("'=x", "''+1", "'plain"),
);
$file = CsvWriter::BOM;
foreach ($rows as $r) {
	$file .= CsvWriter::line($r);
}
check(CsvReader::records($file) === $rows, 'what CsvWriter writes reads back exactly, defused cells and quoted line breaks included');

$google = "Name,Notes,E-mail 1 - Value\n"
	. "Ann,\"met at the fair\nlikes tea\",ann@example.com\n"
	. "\n"
	. "Bo,,bo@example.com\n";
check(CsvReader::records($google) === array(
	array('Name', 'Notes', 'E-mail 1 - Value'),
	array('Ann', "met at the fair\nlikes tea", 'ann@example.com'),
	array('Bo', '', 'bo@example.com'),
), 'a quoted line break stays inside its row, and a blank line is skipped');

check(CsvReader::records("a,\"b\\\",c\n") === array(array('a', 'b\\', 'c')),
	'a backslash does not escape the quote after it: a field ending in one closes at its quote');
check(CsvReader::records("x,y\ry,z\r") === array(array('x', 'y'), array('y', 'z')), 'lone carriage returns end lines in an old Mac file');
check(CsvReader::records('') === array(), 'an empty file has no records');
check(CsvReader::records("Jos\xE9,Caf\xE9 \x96 Bar\r\n") === array(array('José', 'Café – Bar')),
	'a file that is not UTF-8 is read as Windows-1252 (desktop Excel and Outlook)');
check(CsvReader::records("José,Zoë\n") === array(array('José', 'Zoë')), 'and a UTF-8 file is left as it is');
check(CsvReader::records("\xFF\xFE" . mb_convert_encoding("Name,E-mail\r\nZoë,z@x.example\r\n", 'UTF-16LE', 'UTF-8')) === array(array('Name', 'E-mail'), array('Zoë', 'z@x.example'))
	&& CsvReader::records("\xFE\xFF" . mb_convert_encoding("Zoë,漢字\n", 'UTF-16BE', 'UTF-8')) === array(array('Zoë', '漢字')),
	'a UTF-16 file, little- or big-endian, is read by its byte order mark');
check(CsvReader::records("'plain,'=x,''=x\n") === array(array("'plain", '=x', "'=x")),
	'one apostrophe is taken off, and only where apostrophes stand before a formula character');

harness_finish();
