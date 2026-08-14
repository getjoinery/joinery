<?php
/** @joinery-test
 * name: document_text
 * tier: safe
 * env: any
 * needs: []
 * timeout: 180
 */
/**
 * DocumentText — the core "what does this file say?" extractor.
 *
 * Every fixture is generated at run time by
 * tests/fixtures/documents/generate_fixtures.php, so the suite carries no
 * binary blobs and each fixture's tricky bit stays visible in source.
 *
 * The assertions that matter most are the security ones. An extractor is a
 * program that runs attacker-chosen input through a parser, so the checks here
 * are less about "did it read the docx" and more about what it refuses:
 *
 *   - an XML entity pointing at /etc/passwd must not put /etc/passwd in the
 *     output — and the source-level flag check is what keeps it that way,
 *     because a single LIBXML_NOENT anywhere silently undoes it,
 *   - a 61KB zip expanding to 60MB must be refused before it is read,
 *   - RTF metadata (title, author) and HTML script/style bodies must not leak
 *     into text that a reader will believe is the document's words,
 *   - the output must always be valid UTF-8, because it crosses a JSON
 *     boundary that fails outright on a malformed sequence.
 *
 * Run: php tests/unit/document_text_test.php
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../fixtures/documents/generate_fixtures.php');

$dir = sys_get_temp_dir() . '/joinery_docfixtures_' . getmypid();
docfix_generate($dir);
harness_defer(function () use ($dir) {
	foreach (glob($dir . '/*') as $f) { if (is_file($f)) @unlink($f); }
	@rmdir($dir);
});

/** Extract a fixture by name, declaring nothing about its type. */
$read = function (string $name, string $hint = '', int $max = 100000) use ($dir) {
	return DocumentText::extractPath($dir . '/' . $name, $hint, $max);
};

// ── Classification ───────────────────────────────────────────────────────────
section('Classification');
check(DocumentText::categoryForMime('application/pdf') === 'pdf', 'pdf routes to the pdf branch');
check(DocumentText::categoryForMime('APPLICATION/PDF; charset=binary') === 'pdf',
	'a MIME with case and parameters normalizes');
check(DocumentText::categoryForMime('text/x-log') === 'text',
	'an unlisted text/* type falls through to text');
check(DocumentText::categoryForMime('text/html') === 'html',
	'text/html keeps its own parser rather than falling through to text');
check(DocumentText::categoryForMime('application/octet-stream') === null,
	'octet-stream on its own names nothing readable');
check(!DocumentText::isExtractable('application/x-keepass'), 'an encrypted database is not extractable');
check(DocumentText::mimeForExtension('invoice.PDF') === 'application/pdf',
	'a filename resolves to a MIME, case-insensitively');
check(DocumentText::mimeForExtension('secrets.kdbx') === null, 'an unknown extension resolves to nothing');
check(DocumentText::bestMimeGuess('application/octet-stream', 'invoice.pdf') === 'application/pdf',
	'a lying declared type is overridden by the filename (the 71%-of-real-PDFs case)');
check(DocumentText::bestMimeGuess('text/plain', 'notes.pdf') === 'text/plain',
	'a declared type we understand is preferred over the extension');
check(DocumentText::canPreview('application/octet-stream', 'invoice.pdf'),
	'canPreview offers the button on the lying-sender case');
check(!DocumentText::canPreview('application/octet-stream', 'secrets.kdbx'),
	'canPreview withholds it from a type nothing here reads');

// ── Formats ──────────────────────────────────────────────────────────────────
section('Formats — the words come back');

$r = $read('sample.docx');
check($r['status'] === DocumentText::OK && $r['category'] === 'docx', 'docx extracts', json_encode($r['status']));
check(strpos($r['text'], 'Service Agreement') !== false, 'docx heading present');
check(strpos($r['text'], "Service Agreement\nThis agreement") !== false,
	'docx paragraphs are separated, not run together');
check(strpos($r['text'], 'Confidential Author') === false && strpos($r['text'], 'Hidden Doc Title') === false,
	'docx docProps metadata (author, title) stays out of the text');

$r = $read('sample.xlsx');
check($r['status'] === DocumentText::OK && $r['category'] === 'xlsx', 'xlsx extracts');
check(strpos($r['text'], 'Widget, large') !== false,
	'xlsx cell text resolves through sharedStrings (an index, not the text)');
check(strpos($r['text'], "Widget, large\t17") !== false, 'xlsx rows are tab-separated');
check(strpos($r['text'], 'Q3 Orders') !== false, 'xlsx sheets are labelled with their real name');

$r = $read('sample.pptx');
check($r['status'] === DocumentText::OK && $r['category'] === 'pptx', 'pptx extracts');
check(strpos($r['text'], 'Quarterly Review') !== false && strpos($r['text'], 'Supply chain') !== false,
	'pptx text from every slide');
check(strpos($r['text'], 'renewal deadline') !== false, 'pptx speaker notes are included');
check(strpos($r['text'], '[Slide 2]') !== false, 'pptx slides are labelled');

$r = $read('sample.odt');
check($r['status'] === DocumentText::OK && $r['category'] === 'odf', 'odt extracts');
check(strpos($r['text'], "Meeting Notes\nAttendees") !== false, 'odt heading and paragraph are separated');
check(strpos($r['text'], 'ship the preview feature') !== false, 'odt inline spans join into one sentence');

$r = $read('sample.epub');
check($r['status'] === DocumentText::OK && $r['category'] === 'epub', 'epub extracts');
check(strpos($r['text'], 'stormy night') !== false && strpos($r['text'], 'next morning') !== false,
	'epub reads every chapter');

$r = $read('sample.html');
check($r['status'] === DocumentText::OK && $r['category'] === 'html', 'html extracts');
check(strpos($r['text'], "Chapter One\n\nIt was a dark") !== false,
	'a heading does not abut the paragraph after it (the textContent defect)', $r['text']);
check(strpos($r['text'], "Item\tPrice") !== false, 'table cells are tab-separated');

$r = $read('sample.rtf');
check($r['status'] === DocumentText::OK && $r['category'] === 'rtf', 'rtf extracts');
check(strpos($r['text'], 'INV-2291') !== false, 'rtf body text survives');
check(strpos($r['text'], '£') !== false, 'rtf hex escapes decode');
check(strpos($r['text'], '—') !== false, 'rtf unicode escapes decode');
check(strpos($r['text'], 'Overdue notice') !== false, 'rtf text inside a colour group survives');

$r = $read('sample.xml');
check($r['status'] === DocumentText::OK && $r['category'] === 'xml', 'xml extracts');
check(strpos($r['text'], "Ada Lovelace\nAnalytical Engine") !== false,
	'xml values land on their own lines rather than running together');

$r = $read('sample.svg');
check($r['status'] === DocumentText::OK, 'svg extracts');
check(trim($r['text']) === 'Quarterly chart label', 'svg yields its text and no markup', $r['text']);

$r = $read('sample.eml');
check($r['status'] === DocumentText::OK && $r['category'] === 'eml', 'eml extracts');
check(strpos($r['text'], 'Subject: Engine delivery schedule') !== false, 'eml carries the headers a reader wants');
check(strpos($r['text'], 'ships on the fourteenth') !== false, 'eml carries the body');

$r = $read('sample.ics');
check($r['status'] === DocumentText::OK && $r['category'] === 'ics', 'ics extracts');
check(strpos($r['text'], 'Event: Quarterly planning call') !== false, 'ics reads as an event summary');
check(strpos($r['text'], 'Location: Room 4B') !== false, 'ics carries the location');
check(strpos($r['text'], 'Attendee: ada@example.com') !== false, 'ics strips the mailto: prefix');
check(strpos($r['text'], 'charles@example.com') !== false && strpos($r['text'], 'grace@example.com') !== false,
	'ics keeps every attendee, not just the last one on the invite', $r['text']);

foreach (array('sample.txt' => 'shipment', 'sample.md' => 'alignment bug',
		'sample.csv' => 'Gadget', 'sample.json' => 'INV-2291') as $file => $needle) {
	$r = $read($file);
	check($r['status'] === DocumentText::OK && $r['category'] === 'text' && strpos($r['text'], $needle) !== false,
		$file . ' reads as text', $r['status']);
}

$r = $read('sample.zip');
check($r['status'] === DocumentText::OK && $r['category'] === 'archive', 'a zip yields a manifest');
check(strpos($r['text'], 'invoices/march.pdf') !== false, 'the manifest names entries');
check(strpos($r['text'], '3 items') !== false, 'the manifest counts entries');
check(strpos($r['text'], 'Archive of quarterly invoices') === false,
	'nothing inside the archive is decompressed into the preview');

// ── Security ─────────────────────────────────────────────────────────────────
section('Security — what it refuses');

$r = $read('xxe.xml');
check(strpos($r['text'], 'root:') === false && strpos($r['text'], '/bin/') === false,
	'XXE: an entity pointing at /etc/passwd does not put /etc/passwd in the output',
	substr($r['text'], 0, 120));
check($r['status'] !== DocumentText::OK, 'XXE: the document is refused outright', $r['status']);

// The flags are the whole defence, and they are invisible at runtime when
// correct — so they are asserted at the source level too. One LIBXML_NOENT
// anywhere in either file silently turns the check above into a false pass.
$sources = array(
	'includes/DocumentText.php'       => file_get_contents(PathHelper::getIncludePath('includes/DocumentText.php')),
	'utils/extract_document_text.php' => file_get_contents(PathHelper::getIncludePath('utils/extract_document_text.php')),
);
foreach ($sources as $label => $src) {
	// The constant's own name appears in this file's comments, so match the
	// token as code would use it, not as prose mentions it.
	foreach (array('LIBXML_NOENT', 'LIBXML_DTDLOAD', 'LIBXML_PARSEHUGE') as $flag) {
		check(!preg_match('/^[^*\n]*\b' . $flag . '\b/m', $src),
			$label . ' passes no ' . $flag);
	}
}
check(substr_count($sources['includes/DocumentText.php'], 'LIBXML_NONET') >= 2,
	'every XML/HTML parse in DocumentText passes LIBXML_NONET');
check(preg_match_all('/->loadXML\s*\(/', $sources['includes/DocumentText.php']) === 1,
	'exactly ONE loadXML call exists — the hardened parse cannot drift into copies');
check(preg_match('/loadXML\s*\(/', $sources['utils/extract_document_text.php']) === 0,
	'the CLI script parses nothing itself — it dispatches to the one XML door');
check(strpos($sources['utils/extract_document_text.php'], "php_sapi_name() !== 'cli'") !== false,
	'the extractor refuses any non-CLI SAPI');

$r = $read('bomb.docx');
check($r['status'] === DocumentText::FAILED, 'zip bomb: a 61KB file expanding to 60MB is refused', $r['status']);
check(strpos((string)$r['detail'], 'too large') !== false, 'zip bomb: the refusal names the reason', (string)$r['detail']);

$r = $read('sample.rtf');
foreach (array('Secret Title' => 'the \\info title', 'Someone Private' => 'the \\info author',
		'Helvetica' => 'the font table', 'Riched20' => 'the generator string') as $needle => $what) {
	check(strpos($r['text'], $needle) === false, 'rtf: ' . $what . ' does not leak into the text');
}

// A skip-list word NESTED inside an already-skipped group (a \pict inside the
// font table) must not shrink the skip to its own depth — the regression leaked
// the rest of the font table into the text as body words.
$r = DocumentText::extractBytes(
	"{\\rtf1{\\fonttbl{\\f0 Helvetica{\\pict deadbeef}LEAKED}}BODY}", 'application/rtf');
check(strpos($r['text'], 'LEAKED') === false && strpos($r['text'], 'Helvetica') === false,
	'rtf: a \\pict nested in the font table does not end the font-table skip', $r['text']);
check(strpos($r['text'], 'BODY') !== false, 'rtf: body text after the nested skip still extracts', $r['text']);

// A \uN whose fallback is a \'hh hex escape — the form Word emits — is ONE
// fallback character; treating the backslash as a stopper rendered every
// non-ASCII character twice.
$r = DocumentText::extractBytes("{\\rtf1\\ansi\\uc1 a\\u8217\\'92b}", 'application/rtf');
check($r['text'] === "a\u{2019}b",
	'rtf: a hex-escape \\u fallback is consumed as the fallback, not rendered as a second character', $r['text']);

$r = $read('sample.html');
check(strpos($r['text'], 'alert(1)') === false, 'html: script bodies are removed, not flattened into text');
check(strpos($r['text'], 'color:red') === false, 'html: style bodies are removed');
check(strpos($r['text'], 'Ignore me') === false, 'html: the head (and its title) is removed');

$r = $read('sample.epub');
check(strpos($r['text'], 'alert(1)') === false && strpos($r['text'], 'color:red') === false,
	'epub: chapter scripts and styles are removed');

// ── Contract ─────────────────────────────────────────────────────────────────
section('Contract');

$r = DocumentText::extractPath($dir . '/nope-does-not-exist.pdf', 'application/pdf');
check($r['status'] === DocumentText::FAILED, 'an unreadable path fails rather than throwing');

$r = DocumentText::extractBytes(random_bytes(4096), 'application/octet-stream');
check($r['status'] === DocumentText::SKIPPED, 'unrecognizable bytes are skipped, not failed', $r['status']);

$r = $read('latin1.txt');
check(mb_check_encoding($r['text'], 'UTF-8'), 'a Latin-1 file comes back as valid UTF-8');
check(json_encode(array('t' => $r['text'])) !== false, 'the extracted text survives json_encode');
check(strpos($r['text'], 'Café') !== false, 'the Latin-1 accents are preserved, not stripped', $r['text']);

$r = $read('sample.docx', '', 40);
check(DocumentText::wasTruncated($r['text']), 'text over the ceiling is truncated and says so');
check(mb_check_encoding($r['text'], 'UTF-8'), 'truncation never splits a UTF-8 sequence');
check(mb_strlen($r['text']) <= 40 + mb_strlen(DocumentText::TRUNCATION_MARKER),
	'truncation respects the character ceiling');

$fromPath = $read('sample.docx');
$fromBytes = DocumentText::extractBytes(file_get_contents($dir . '/sample.docx'), '', 100000);
check($fromPath['text'] === $fromBytes['text'], 'extractPath and extractBytes agree on the same document');
check($fromPath['category'] === $fromBytes['category'], 'and on its category');

check(strpos(DocumentText::toUtf8("Caf\xE9 line", 'unknown-8bit'), 'Café') === 0,
	'a nonsense sender-declared charset falls back to detection rather than failing the extraction');

// A killed child runs none of its own cleanup, so the parent sweeps stale
// staging around every extraction. Simulate what a SIGKILLed child leaves:
$stale = '/dev/shm/joinery_doctext_staletest';
if (@file_put_contents($stale, 'x') !== false) {
	@touch($stale, time() - 120);
	DocumentText::extractBytes("plain words\n", 'text/plain');
	check(!file_exists($stale), 'a staged file left by a killed child is swept by the next extraction');
	@unlink($stale);
}

check(count(glob('/dev/shm/joinery_doctext_*')) === 0,
	'container staging leaves nothing behind in /dev/shm');
check(count(glob(sys_get_temp_dir() . '/joinery_doctext_*')) === 0,
	'and nothing in the system temp dir');

// A big input handed to a child that will refuse it must come back promptly.
// The regression this guards: a parent that writes all of stdin before reading
// deadlocks against a child blocked on a full output pipe, and `timeout` turns
// that into a guaranteed 20-second stall on every bad file.
$t0 = microtime(true);
$r = DocumentText::extractBytes(random_bytes(12 * 1024 * 1024), 'application/octet-stream');
$elapsed = microtime(true) - $t0;
check($elapsed < 10, 'a large refused input returns promptly (no stdin/stdout deadlock)',
	round($elapsed, 2) . 's');

// Container disambiguation: a docx that sniffs as a plain zip must still be a
// docx by the time a parser sees it.
check(DocumentText::sniff(file_get_contents($dir . '/sample.docx')) !== '',
	'sniff() answers for a real file');
$refined = DocumentText::refineContainerMime($dir . '/sample.docx', 'application/zip');
check(DocumentText::categoryForMime($refined) === 'docx',
	'a docx sniffed as application/zip is resolved by its members', $refined);
$refined = DocumentText::refineContainerMime($dir . '/sample.zip', 'application/zip');
check(DocumentText::categoryForMime($refined) === 'archive',
	'a plain zip stays a plain zip', $refined);

harness_finish();
