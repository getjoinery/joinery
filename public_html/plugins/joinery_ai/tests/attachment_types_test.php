<?php
/** @joinery-test
 * name: ai_attachment_types
 * tier: safe
 * env: any
 * needs: []
 * timeout: 120
 */
/**
 * What the chat accepts as an attachment, and what it refuses.
 *
 * The accepted set is deliberately narrower than what the core extractor can
 * read: an upload here becomes part of a model payload, so widening it is a
 * decision about what a model ingests, not about what a parser can manage.
 *
 * Two rules carry the weight:
 *
 *  1. DETECTION WINS. The filename is consulted only when the bytes sniff as a
 *     bare container — because docx, xlsx, pptx, odt and epub ARE zips and
 *     libmagic tells them apart by convention. That fallback can never reach a
 *     category that sends raw bytes to the model (image, native PDF), so a
 *     lying name cannot forge one.
 *
 *  2. THE EXTRACTOR HAS THE LAST WORD. It opens the bytes for real inside its
 *     sandbox, so if a file turns out to be something else — a plain zip
 *     renamed .docx — ingress refuses it after the fact.
 *
 * Run: php plugins/joinery_ai/tests/attachment_types_test.php
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/fixtures/documents/generate_fixtures.php');

$dir = sys_get_temp_dir() . '/joinery_ai_attach_fixtures_' . getmypid();
docfix_generate($dir);
harness_defer(function () use ($dir) {
	foreach (glob($dir . '/*') as $f) { if (is_file($f)) @unlink($f); }
	@rmdir($dir);
});

/** What ingress would decide for a file on disk, name included. */
$resolve = function (string $file) use ($dir) {
	$path = $dir . '/' . $file;
	$detected = (string)File::detect_mime_bytes(file_get_contents($path));
	$mime = AiAttachment::resolveUploadMime($detected, $file);
	return array(
		'detected' => $detected,
		'mime'     => $mime,
		'category' => AiAttachment::categoryForMime($mime),
	);
};

// ── The accepted set ─────────────────────────────────────────────────────────
section('The accepted set');

foreach (array('sample.docx', 'sample.xlsx', 'sample.pptx', 'sample.odt', 'sample.epub',
		'sample.rtf', 'sample.eml', 'sample.ics', 'sample.xml') as $file) {
	$r = $resolve($file);
	check($r['category'] === 'document', $file . ' is accepted as a document',
		$r['detected'] . ' -> ' . (string)$r['category']);
}

check(AiAttachment::categoryForMime('application/pdf') === 'pdf', 'a PDF is still its own category');
check(AiAttachment::categoryForMime('image/png') === 'image', 'an image is still its own category');
check(AiAttachment::categoryForMime('text/plain') === 'text', 'plain text is unchanged');
check(AiAttachment::categoryForMime('text/html') === 'html', 'HTML is unchanged');

// ── The refused set ──────────────────────────────────────────────────────────
section('The refused set');

$r = $resolve('sample.zip');
check($r['category'] === null, 'a zip is refused — a container is not something to feed a model',
	$r['detected']);
check(AiAttachment::categoryForMime('image/svg+xml') === null,
	'an SVG is refused: markup wearing an image name');
check(AiAttachment::categoryForMime('application/x-dosexec') === null, 'an executable is refused');
check(AiAttachment::categoryForMime('application/msword') === null,
	'legacy binary Word is refused — nothing here can read it');

$caps = array('vision' => true, 'document' => true);
$reject = AiAttachment::validateRaw('application/zip', 1024, AiAttachment::MODE_EXTRACT, $caps, 'stuff.zip');
check($reject !== null, 'the rejection is a user-facing sentence, not a silent drop');
check(strpos((string)$reject, 'OpenDocument') !== false,
	'and it names the kinds of file that would work', (string)$reject);

// ── Detection wins; the name only breaks a container tie ─────────────────────
section('Detection wins');

$r = $resolve('sample.docx');
check($r['detected'] === 'application/zip' || strpos($r['detected'], 'wordprocessingml') !== false,
	'a docx sniffs as either a zip or itself, depending on the libmagic build', $r['detected']);
check(strpos($r['mime'], 'wordprocessingml') !== false,
	'and either way it resolves to a docx', $r['mime']);

// An executable named .docx: detection does not land on a container, so the
// name is never consulted.
file_put_contents($dir . '/fake.docx', "MZ\x90\x00" . str_repeat("\x00", 512));
$r = $resolve('fake.docx');
check($r['category'] === null, 'an executable named .docx is refused outright', $r['detected']);

// The two categories that send raw bytes to a model are unreachable by name.
check(AiAttachment::resolveUploadMime('application/octet-stream', 'photo.png') === 'application/octet-stream',
	'a name can never make an image block');
check(AiAttachment::resolveUploadMime('application/octet-stream', 'statement.pdf') === 'application/octet-stream',
	'a name can never make a native PDF block');
check(strpos(AiAttachment::resolveUploadMime('application/octet-stream', 'contract.docx'), 'wordprocessingml') !== false,
	'but it can choose which text extractor gets a look');
check(AiAttachment::resolveUploadMime('text/plain', 'notes.docx') === 'text/plain',
	'a type detection recognized is never second-guessed by the name');

// ── The extractor has the last word ──────────────────────────────────────────
section('The extractor has the last word');

copy($dir . '/sample.zip', $dir . '/liar.docx');
$r = $resolve('liar.docx');
check($r['category'] === 'document', 'a zip renamed .docx gets past the door on its name', $r['mime']);
$extract = AiAttachment::extractPath($dir . '/liar.docx', $r['mime']);
check(($extract['category'] ?? null) === 'archive',
	'but the sandbox opens it and reports what it really is',
	json_encode($extract['category'] ?? null));
check(AiAttachment::categoryForCoreCategory($extract['category'] ?? null) === null,
	'which maps to nothing the chat accepts — the ingress check refuses it');

$honest = AiAttachment::extractPath($dir . '/sample.docx', $resolve('sample.docx')['mime']);
check(AiAttachment::categoryForCoreCategory($honest['category'] ?? null) === 'document',
	'a real docx agrees with what ingress accepted', json_encode($honest['category'] ?? null));
check($honest['status'] === AiAttachment::EXTRACT_OK && strpos($honest['text'], 'Service Agreement') !== false,
	'and its text comes back');

foreach (array('text' => 'sample.txt', 'html' => 'sample.html') as $expected => $file) {
	$e = AiAttachment::extractPath($dir . '/' . $file, $resolve($file)['mime']);
	check(AiAttachment::categoryForCoreCategory($e['category'] ?? null) === $expected,
		$file . ' agrees too — the check does not fire on honest files',
		json_encode($e['category'] ?? null));
}

// ── Caps and stored vocabulary ───────────────────────────────────────────────
section('Caps and stored vocabulary');

check(AiAttachment::maxBytesForCategory('document') === AiAttachment::documentMaxBytes(),
	'documents have their own byte cap');
check(AiAttachment::documentMaxBytes() > AiAttachment::textMaxBytes(),
	'and it is larger than the text cap — a document is mostly markup around the words');

$reject = AiAttachment::validateRaw(
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	AiAttachment::documentMaxBytes() + 1, AiAttachment::MODE_EXTRACT, $caps, 'huge.docx');
check($reject !== null && strpos($reject, 'document') !== false,
	'an oversize document is refused by name and size', (string)$reject);

// The four stored statuses are the whole vocabulary of aia_extract_status.
// The core extractor knows more outcomes than that; they must collapse, or a
// value nothing reads lands in the column.
check(AiAttachment::EXTRACT_OK === 'ok' && AiAttachment::EXTRACT_EMPTY === 'empty'
	&& AiAttachment::EXTRACT_FAILED === 'failed' && AiAttachment::EXTRACT_SKIPPED === 'skipped',
	'the stored status values are unchanged');
$stored = array(AiAttachment::EXTRACT_OK, AiAttachment::EXTRACT_EMPTY,
	AiAttachment::EXTRACT_FAILED, AiAttachment::EXTRACT_SKIPPED);
$zip_result = AiAttachment::extractPath($dir . '/sample.zip', 'application/zip');
check(in_array($zip_result['status'], $stored, true),
	'an unaccepted type still yields a storable status', $zip_result['status']);

harness_finish();
