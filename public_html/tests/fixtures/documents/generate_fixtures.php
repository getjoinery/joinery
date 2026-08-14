<?php
/**
 * Generate the document fixture set used by tests/unit/document_text_test.php.
 *
 * Built rather than checked in, so the suite carries no binary blobs and every
 * fixture's tricky bit is visible in source: the xlsx sharedStrings
 * indirection, the RTF \info group whose leak was a real defect, the XML that
 * tries to read /etc/passwd, and the 61KB zip that expands to 60MB.
 *
 * Usage: php tests/fixtures/documents/generate_fixtures.php [output_dir]
 * Idempotent — re-running overwrites.
 *
 * @version 1.0.0
 */

function docfix_generate(string $dir): array {
	if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
		throw new RuntimeException('could not create fixture directory: ' . $dir);
	}

	// ---- RTF: font/colour tables, an \info group carrying title + author, a
	//      \'hh hex escape, a \uN unicode escape, a nested coloured group.
	//      Everything a regex-based stripper leaks.
	$rtf = "{\\rtf1\\ansi\\ansicpg1252\\deff0\n"
		 . "{\\fonttbl{\\f0\\fswiss\\fcharset0 Helvetica;}{\\f1\\froman Times;}}\n"
		 . "{\\colortbl;\\red255\\green0\\blue0;\\red0\\green0\\blue255;}\n"
		 . "{\\*\\generator Riched20 10.0.19041;}\n"
		 . "{\\info{\\title Secret Title}{\\author Someone Private}}\n"
		 . "\\viewkind4\\uc1\\pard\\f0\\fs24 Invoice \\b INV-2291\\b0\\par\n"
		 . "Amount due: \\'a3 1,250.00 \\u8212 ? net 30\\par\n"
		 . "{\\cf1 Overdue notice} follows on the next page.\\par\n"
		 . "\\pard\\tab Line with a tab.\\par\n"
		 . "}\n";
	file_put_contents("$dir/sample.rtf", $rtf);

	// ---- DOCX
	$docx_body = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
		. '<w:p><w:r><w:t>Service Agreement</w:t></w:r></w:p>'
		. '<w:p><w:r><w:t>This agreement is between Acme Ltd and the Client.</w:t></w:r></w:p>'
		. '<w:p><w:r><w:t>Term: twelve months from the Effective Date.</w:t></w:r></w:p>'
		. '</w:body></w:document>';
	docfix_zip("$dir/sample.docx", array(
		'[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
		'word/document.xml'   => $docx_body,
		'docProps/core.xml'   => '<?xml version="1.0"?><cp:coreProperties '
			. 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
			. 'xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<dc:creator>Confidential Author</dc:creator><dc:title>Hidden Doc Title</dc:title>'
			. '</cp:coreProperties>',
	));

	// ---- XLSX: cell text is an INDEX into sharedStrings.xml, not the text.
	$shared = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">'
		. '<si><t>Product</t></si><si><t>Qty</t></si><si><t>Widget, large</t></si>'
		. '<si><t>Total</t></si><si><t>Notes &amp; terms</t></si></sst>';
	$sheet = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
		. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
		. '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>17</v></c></row>'
		. '<row r="3"><c r="A3" t="s"><v>3</v></c><c r="B3"><v>1250.5</v></c></row>'
		. '<row r="4"><c r="A4" t="s"><v>4</v></c></row>'
		. '</sheetData></worksheet>';
	docfix_zip("$dir/sample.xlsx", array(
		'[Content_Types].xml'        => '<?xml version="1.0"?><Types/>',
		'xl/workbook.xml'            => '<?xml version="1.0"?><workbook '
			. 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheets><sheet name="Q3 Orders" sheetId="1"/></sheets></workbook>',
		'xl/sharedStrings.xml'       => $shared,
		'xl/worksheets/sheet1.xml'   => $sheet,
	));

	// ---- PPTX: text lives in a:t runs, speaker notes in a parallel part.
	$slide = function ($title, $body) {
		return '<?xml version="1.0"?><p:sld '
			. 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
			. 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree>'
			. '<p:sp><p:txBody><a:p><a:r><a:t>' . $title . '</a:t></a:r></a:p></p:txBody></p:sp>'
			. '<p:sp><p:txBody><a:p><a:r><a:t>' . $body . '</a:t></a:r></a:p></p:txBody></p:sp>'
			. '</p:spTree></p:cSld></p:sld>';
	};
	docfix_zip("$dir/sample.pptx", array(
		'ppt/presentation.xml'            => '<?xml version="1.0"?><presentation/>',
		'ppt/slides/slide1.xml'           => $slide('Quarterly Review', 'Revenue up 12 percent'),
		'ppt/slides/slide2.xml'           => $slide('Risks', 'Supply chain and hiring'),
		'ppt/notesSlides/notesSlide1.xml' => '<?xml version="1.0"?><p:notes '
			. 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" '
			. 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
			. '<a:p><a:r><a:t>Remember to mention the renewal deadline.</a:t></a:r></a:p></p:notes>',
	));

	// ---- ODT
	docfix_zip("$dir/sample.odt", array(
		'mimetype'    => 'application/vnd.oasis.opendocument.text',
		'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
			. 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text>'
			. '<text:h text:outline-level="1">Meeting Notes</text:h>'
			. '<text:p>Attendees: Alice, Bob</text:p>'
			. '<text:p>Action: ship the <text:span>preview</text:span> feature.</text:p>'
			. '</office:text></office:body></office:document-content>',
	));

	// ---- EPUB: XHTML chapters carrying a <script> and a <style> that must not
	//      survive into the text.
	$chapter = function ($title, $body) {
		return '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml"><head>'
			. '<title>' . $title . '</title><style>.x{color:red}</style></head><body>'
			. '<h1>' . $title . '</h1><p>' . $body . '</p><script>alert(1)</script></body></html>';
	};
	docfix_zip("$dir/sample.epub", array(
		'mimetype'              => 'application/epub+zip',
		'META-INF/container.xml'=> '<?xml version="1.0"?><container/>',
		'OEBPS/ch1.xhtml'       => $chapter('Chapter One', 'It was a dark and stormy night.'),
		'OEBPS/ch2.xhtml'       => $chapter('Chapter Two', 'The next morning was clear.'),
	));

	// ---- Plain HTML, with the block-boundary trap: a heading immediately
	//      followed by a paragraph.
	file_put_contents("$dir/sample.html",
		"<html><head><title>Ignore me</title><style>body{color:red}</style></head><body>"
		. "<h1>Chapter One</h1><p>It was a dark and stormy night.</p>"
		. "<table><tr><td>Item</td><td>Price</td></tr><tr><td>Widget</td><td>12.00</td></tr></table>"
		. "<script>alert(1)</script></body></html>");

	// ---- XML / SVG
	file_put_contents("$dir/sample.xml",
		"<?xml version=\"1.0\"?>\n<order><customer>Ada Lovelace</customer>"
		. "<item>Analytical Engine</item><total>9900.00</total></order>\n");
	file_put_contents("$dir/sample.svg",
		'<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="200" height="60">'
		. '<text x="10" y="30">Quarterly chart label</text></svg>');

	// ---- XXE probe: an XML document that TRIES to read /etc/passwd.
	file_put_contents("$dir/xxe.xml",
		"<?xml version=\"1.0\"?>\n<!DOCTYPE r [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n"
		. "<r>start &xxe; end</r>\n");

	// ---- Text family
	file_put_contents("$dir/sample.txt", "Plain notes.\nSecond line about the shipment.\n");
	file_put_contents("$dir/sample.md", "# Release notes\n\nFixed the widget alignment bug.\n");
	file_put_contents("$dir/sample.csv", "product,qty,price\nWidget,17,12.00\nGadget,3,45.50\n");
	file_put_contents("$dir/sample.json", "{\"invoice\": \"INV-2291\", \"total\": 1250.5}\n");
	// Latin-1: the JSON-boundary trap. Raw, this is not valid UTF-8.
	file_put_contents("$dir/latin1.txt",
		"Caf\xE9 r\xE9sum\xE9 na\xEFve \xA31,250\nSecond line.\n");

	// ---- ICS
	file_put_contents("$dir/sample.ics",
		"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Joinery//Test//EN\r\n"
		. "BEGIN:VEVENT\r\nUID:abc-123\r\nDTSTART:20260901T150000Z\r\nDTEND:20260901T160000Z\r\n"
		. "SUMMARY:Quarterly planning call\r\nLOCATION:Room 4B\r\n"
		// ATTENDEE repeats per person — a real invite names several, and keeping
		// only the last one is a regression the test asserts against.
		. "ORGANIZER:mailto:chair@example.com\r\nATTENDEE:mailto:ada@example.com\r\n"
		. "ATTENDEE:mailto:charles@example.com\r\nATTENDEE:mailto:grace@example.com\r\n"
		. "DESCRIPTION:Bring the Q3 numbers.\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

	// ---- EML (a forwarded message)
	file_put_contents("$dir/sample.eml",
		"From: Ada Lovelace <ada@example.com>\r\n"
		. "To: Charles Babbage <charles@example.com>\r\n"
		. "Subject: Engine delivery schedule\r\n"
		. "Date: Mon, 01 Sep 2026 09:00:00 +0000\r\n"
		. "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
		. "The engine ships on the fourteenth.\r\nPlease confirm the loading dock.\r\n");

	// ---- ZIP archive (manifest preview, nothing decompressed)
	docfix_zip("$dir/sample.zip", array(
		'invoices/march.pdf' => str_repeat('x', 2048),
		'invoices/april.pdf' => str_repeat('y', 4096),
		'readme.txt'         => "Archive of quarterly invoices.\n",
	));

	// ---- Zip bomb: 61KB expanding to 60MB inside one member.
	docfix_zip("$dir/bomb.docx", array(
		'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:t>'
			. str_repeat('A', 60 * 1024 * 1024) . '</w:t></w:p></w:body></w:document>',
	));

	$made = array();
	foreach (glob("$dir/*") as $f) {
		if (is_file($f)) {
			@chmod($f, 0666);
			$made[basename($f)] = filesize($f);
		}
	}
	return $made;
}

/** Write a zip from a name => content map. */
function docfix_zip(string $path, array $members): void {
	@unlink($path);
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::CREATE) !== true) {
		throw new RuntimeException('could not create ' . $path);
	}
	foreach ($members as $name => $content) {
		$zip->addFromString($name, $content);
	}
	$zip->close();
}

// Run directly (not when required by the test).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
	$dir = $argv[1] ?? __DIR__;
	foreach (docfix_generate($dir) as $name => $size) {
		printf("%-24s %10d bytes\n", $name, $size);
	}
}
