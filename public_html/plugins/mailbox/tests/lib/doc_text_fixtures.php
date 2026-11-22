<?php
/**
 * Writes the document fixtures doc_text.mjs reads, and what the SERVER's reader
 * says each one holds.
 *
 * The browser's reader (assets/mailbox_doc_text.js) follows DocumentText's
 * office and text readers line for line; the PHP is the reference. This builds
 * one file per shape into fixtures/doc_text/ and records
 * DocumentText::extractBytes()'s answer for each in expected.json, plus
 * DocumentText::canPreview() for every extension the browser reader takes or
 * refuses in can_preview.json. doc_text.mjs then requires the browser's answer
 * to equal the server's, file by file.
 *
 * Run by `node plugins/mailbox/tests/doc_text.mjs --write`; directly:
 *   php plugins/mailbox/tests/lib/doc_text_fixtures.php
 *
 * The poi-*.doc files are real Word 97-2003 documents (and a Word 95 one,
 * two password-protected ones and two fuzzer cases) from Apache POI's test
 * data (test-data/document, Apache License 2.0); they are copied in, not
 * built, because nothing here writes the format. Their expected text is the
 * server's answer like every other file's.
 *
 * @version 1.1.0 - Word 97-2003 files from Apache POI's test data; can_preview.json covers .doc
 * @version 1.0.0
 */

require_once(dirname(__DIR__, 4) . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

/** Characters returned per file, the endpoint's own cap. */
const DTF_MAX_CHARS = 200000;
/** A fixed time on every member, so a rebuild writes the same bytes. */
const DTF_MTIME = 1798761600;

function dtf_zip(string $path, array $members): void {
	@unlink($path);
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::CREATE) !== true) {
		throw new RuntimeException('could not create ' . $path);
	}
	foreach ($members as $name => $content) {
		$zip->addFromString($name, $content);
		$zip->setMtimeName($name, DTF_MTIME);
		// An OpenDocument's first member is its type, stored, as writers do.
		if ($name === 'mimetype') $zip->setCompressionName($name, ZipArchive::CM_STORE);
	}
	$zip->close();
}

function dtf_build(string $dir): array {
	if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
		throw new RuntimeException('could not create ' . $dir);
	}
	$W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
	$S = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
	$P = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
		. 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
	$decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n";

	// ---- Word: paragraphs, runs split mid-word, a table, a footnote, a header
	//      and footer, entities, character references, CDATA-free text with
	//      preserved spaces, a self-closed paragraph and a comment.
	$p = function ($text) { return '<w:p><w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>'; };
	dtf_zip("$dir/report.docx", array(
		'[Content_Types].xml' => $decl . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
		'word/document.xml' => $decl . "<w:document $W>\n  <w:body>\n"
			. '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>Quarterly </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>Re</w:t></w:r><w:r><w:t>port</w:t></w:r></w:p>'
			. "\n<!-- a writer's comment -->\n"
			. $p('Smith &amp; Jones &lt;legal&gt; agreed on &#8220;terms&#8221; &#x2014; caf&#233;.')
			. '<w:p/>'
			. '<w:tbl><w:tr><w:tc>' . $p('Item') . '</w:tc><w:tc>' . $p('Cost') . '</w:tc></w:tr>'
			. '<w:tr><w:tc>' . $p('Widgets') . '</w:tc><w:tc>' . $p('1,250.00') . '</w:tc></w:tr></w:tbl>'
			. '<w:p><w:r><w:t>See the note</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r><w:r><w:t>.</w:t></w:r></w:p>'
			. "\n  </w:body>\n</w:document>",
		'word/footnotes.xml' => $decl . "<w:footnotes $W><w:footnote w:id=\"1\">" . $p('Figures are unaudited.') . '</w:footnote></w:footnotes>',
		'word/header1.xml'   => $decl . "<w:hdr $W>" . $p('Confidential draft') . '</w:hdr>',
		'word/footer10.xml'  => $decl . "<w:ftr $W>" . $p('Page footer ten') . '</w:ftr>',
		'word/footer2.xml'   => $decl . "<w:ftr $W>" . $p('Page footer two') . '</w:ftr>',
		'docProps/core.xml'  => $decl . '<cp:coreProperties xmlns:cp="x"><dc:title xmlns:dc="y">Hidden title</dc:title></cp:coreProperties>',
	));

	// ---- Excel: two sheets, shared strings (one rich, one with phonetic text),
	//      an inline string, numbers, an empty row, a self-closed cell, an
	//      out-of-range index, a sheet name with an entity.
	dtf_zip("$dir/budget.xlsx", array(
		'[Content_Types].xml' => $decl . '<Types/>',
		'xl/workbook.xml' => $decl . "<workbook $S><sheets>"
			. '<sheet name="Budget &amp; Plan" sheetId="1"/><sheet name="Notes" sheetId="2"/></sheets></workbook>',
		'xl/sharedStrings.xml' => $decl . "<sst $S count=\"5\" uniqueCount=\"5\">"
			. '<si><t>Line</t></si><si><t>Amount</t></si>'
			. '<si><r><rPr><b/></rPr><t>Rent, </t></r><r><t xml:space="preserve">office </t></r></si>'
			. '<si><t>Travel</t><rPh><t>tora</t></rPh></si>'
			. '<si/></sst>',
		'xl/worksheets/sheet1.xml' => $decl . "<worksheet $S>\n<sheetData>\n"
			. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>' . "\n"
			. '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>1800</v></c></row>' . "\n"
			. '<row r="3"/>' . "\n"
			. '<row r="4"><c r="A4"/><c r="B4" t="s"><v>4</v></c></row>' . "\n"
			. '<row r="5"><c r="A5" t="s"><v>3</v></c><c r="B5"><f>SUM(B1:B2)</f><v>412.5</v></c></row>' . "\n"
			. '<row r="6"><c r="A6" t="inlineStr"><is><t>Inline &amp; typed</t></is></c><c r="B6" t="s"><v>99</v></c><c r="C6" t="b"><v>1</v></c></row>' . "\n"
			. "</sheetData>\n</worksheet>",
		'xl/worksheets/sheet2.xml' => $decl . "<worksheet $S><sheetData>"
			. '<row r="1"><c r="A1" t="inlineStr"><is><r><t>Approved </t></r><r><t>by the board</t></r></is></c></row>'
			. '</sheetData></worksheet>',
	));

	// ---- PowerPoint: three slides; notes on the first, a note that is only the
	//      slide number on the second, none on the third.
	$slide = function ($lines) use ($decl, $P) {
		$body = '';
		foreach ($lines as $l) $body .= '<a:p><a:r><a:t>' . $l . '</a:t></a:r></a:p>';
		return $decl . "<p:sld $P><p:cSld><p:spTree><p:sp><p:txBody>" . $body
			. '</p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
	};
	$notes = function ($lines) use ($decl, $P) {
		$body = '';
		foreach ($lines as $l) $body .= '<a:p><a:r><a:t>' . $l . '</a:t></a:r></a:p>';
		return $decl . "<p:notes $P><p:cSld><p:spTree><p:sp><p:txBody>" . $body
			. '</p:txBody></p:sp></p:spTree></p:cSld></p:notes>';
	};
	dtf_zip("$dir/deck.pptx", array(
		'ppt/presentation.xml' => $decl . "<p:presentation $P/>",
		'ppt/slides/slide1.xml' => $slide(array('Roadmap', 'Ship the reader')),
		'ppt/slides/slide2.xml' => $slide(array('Risks')),
		'ppt/slides/slide10.xml' => $slide(array('Appendix &amp; sources')),
		'ppt/notesSlides/notesSlide1.xml' => $notes(array('Mention the launch date.', '1')),
		'ppt/notesSlides/notesSlide2.xml' => $notes(array('2')),
	));

	// ---- OpenDocument text, sheet and slides.
	$office = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
		. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
		. 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
		. 'xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"';
	dtf_zip("$dir/minutes.odt", array(
		'mimetype' => 'application/vnd.oasis.opendocument.text',
		'content.xml' => $decl . "<office:document-content $office><office:body><office:text>"
			. '<text:h text:outline-level="1">Minutes</text:h>'
			. '<text:p>Present: Ada, <text:span>Grace</text:span></text:p>'
			. '<table:table><table:table-row><table:table-cell><text:p>Vote</text:p></table:table-cell>'
			. '<table:table-cell><text:p>Carried</text:p></table:table-cell></table:table-row></table:table>'
			. '<text:p>Next meeting<text:line-break/>in March.</text:p>'
			. '</office:text></office:body></office:document-content>',
	));
	dtf_zip("$dir/ledger.ods", array(
		'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
		'content.xml' => $decl . "<office:document-content $office><office:body><office:spreadsheet>"
			. '<table:table table:name="Sheet1">'
			. '<table:table-row><table:table-cell><text:p>Month</text:p></table:table-cell><table:table-cell><text:p>Spend</text:p></table:table-cell></table:table-row>'
			. '<table:table-row><table:table-cell><text:p>January</text:p></table:table-cell><table:table-cell office:value="12"><text:p>12</text:p></table:table-cell></table:table-row>'
			. '<table:table-row><table:table-cell/><table:table-cell/></table:table-row>'
			. '</table:table></office:spreadsheet></office:body></office:document-content>',
	));
	dtf_zip("$dir/talk.odp", array(
		'mimetype' => 'application/vnd.oasis.opendocument.presentation',
		'content.xml' => $decl . "<office:document-content $office><office:body><office:presentation>"
			. '<draw:page draw:name="One"><draw:frame><draw:text-box><text:p>Welcome</text:p><text:p>Agenda</text:p></draw:text-box></draw:frame></draw:page>'
			. '</office:presentation></office:body></office:document-content>',
	));

	// ---- Text: UTF-8 with a byte-order mark, windows-1252, CRLF, blank runs,
	//      control bytes, and a CSV.
	file_put_contents("$dir/notes.txt", "\xEF\xBB\xBFFirst line\r\nSecond line\r\n\r\n\r\n\r\nAfter a gap \xE2\x9C\x93\x01\x07 done\n  ");
	file_put_contents("$dir/legacy.txt", "Caf\xE9 \x93quoted\x94 \x96 dash \x80 euro\nr\xE9sum\xE9\n");
	file_put_contents("$dir/data.csv", "name,amount\nRent,1800\n\"Travel, misc\",412.50\n");
	file_put_contents("$dir/readme.md", "# Title\n\nSome *markdown* text.\n");

	$expected = array();
	foreach (glob("$dir/*") as $f) {
		$base = basename($f);
		if (substr($base, -5) === '.json') continue;
		// The hint the endpoint passes: a declared octet-stream, and the name.
		$r = DocumentText::extractBytes((string)file_get_contents($f),
			DocumentText::bestMimeGuess('application/octet-stream', $base), DTF_MAX_CHARS);
		$expected[$base] = array('status' => $r['status'], 'category' => $r['category'], 'text' => $r['text']);
	}
	ksort($expected);
	file_put_contents("$dir/expected.json", json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

	// The preview button: what the server would offer, for every extension
	// the browser reader takes or leaves to the server, declared and not.
	$exts = array('docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'pdf', 'rtf', 'html', 'htm', 'eml', 'ics',
		'epub', 'zip', 'xml', 'svg', 'png', 'doc', 'xls', 'bin');
	foreach (DocumentText::EXTENSION_MIME as $ext => $mime) {
		if (DocumentText::categoryForMime($mime) === 'text') $exts[] = $ext;
	}
	$can = array();
	foreach (array_unique($exts) as $ext) {
		$can[$ext] = array(
			'category'  => DocumentText::categoryForMime(DocumentText::bestMimeGuess('application/octet-stream', 'file.' . $ext)),
			'preview'   => DocumentText::canPreview('application/octet-stream', 'file.' . $ext),
		);
	}
	ksort($can);
	file_put_contents("$dir/can_preview.json", json_encode($can, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	return $expected;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
	$dir = $argv[1] ?? dirname(__DIR__) . '/fixtures/doc_text';
	foreach (dtf_build($dir) as $name => $r) {
		printf("%-14s %-6s %-5s %d chars\n", $name, $r['status'], (string)$r['category'], mb_strlen($r['text']));
	}
}
