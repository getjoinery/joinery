<?php
/** @joinery-test
 * name: mime_parse
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * MimeParse — the guarded MIME parse and the hazard scan behind it.
 *
 * The hazard is real and was hit live (2026-08-23): a 2011 newsletter carried
 * its own boundary inside an HTML comment mid-line, and
 * Horde_Mime_Part::_findBoundary() retried the same strpos() offset forever,
 * pinning the import cron worker at 100% CPU for eight hours. These checks pin
 * the guard: the hang shape is refused with MimeParseHazardException, and
 * ordinary messages — including the Outlook style whose boundary itself starts
 * with dashes — parse exactly as before.
 *
 * Also pinned here: DocumentText::toUtf8() (the ladder the mailbox ingest
 * paths delegate to) never throws on a sender-declared charset PHP does not
 * recognise. Twenty messages of the same live import failed on exactly that.
 *
 * Run: php tests/run.php safe --filter=mime_parse
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('The hazard scan');

$boundary = 'BOUND_abc123';
$safe = "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n"
	. "--$boundary\r\nContent-Type: text/plain\r\n\r\nhello\r\n--$boundary--\r\n";
check(MimeParse::hangingBoundary($safe) === null,
	'A well-formed multipart message is not flagged');

$hostile = "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n"
	. "--$boundary\r\nContent-Type: text/html\r\n\r\n"
	. "<!--$boundary-->\r\n"     // the boundary mid-line, exactly the live shape
	. "\r\n--$boundary--\r\n";
check(MimeParse::hangingBoundary($hostile) === $boundary,
	'A boundary quoted mid-line in the body is flagged, and named');

$dashy = "Content-Type: multipart/mixed; boundary=\"----=_Part_1\"\r\n\r\n"
	. "------=_Part_1\r\nContent-Type: text/plain\r\n\r\nhi\r\n------=_Part_1--\r\n";
check(MimeParse::hangingBoundary($dashy) === null,
	'An Outlook-style boundary that itself starts with dashes is not a false positive');

$unquoted = "Content-Type: multipart/mixed; boundary=$boundary\r\n\r\n"
	. "x --$boundary y\r\n--$boundary--\r\n";
check(MimeParse::hangingBoundary($unquoted) === $boundary,
	'The scan reads an unquoted boundary declaration too');

check(MimeParse::hangingBoundary("Subject: hi\r\n\r\nno multipart here") === null,
	'A message with no boundary declaration is never flagged');

section('The guarded parse');

$parsed = MimeParse::parseMessage($safe);
check($parsed instanceof Horde_Mime_Part,
	'A safe message parses into a Horde part tree');

$refused = false;
try {
	MimeParse::parseMessage($hostile);
} catch (MimeParseHazardException $e) {
	$refused = (strpos($e->getMessage(), $boundary) !== false);
}
check($refused,
	'The hang shape is refused with MimeParseHazardException naming the boundary');

section('The body walk falls back instead of failing');

$router = new InboundEmailRouter();
$bodies = $router->extractBodies($hostile, $router->parseEmail($hostile));
check(strpos($bodies['html'], '<!--') !== false || $bodies['plain'] !== '' || $bodies['html'] !== '',
	'extractBodies still produces a body for the refused message (legacy splitter)');

section('toUtf8 survives every charset a sender can declare');

foreach (array('KS_C_5601-1987', 'WINDOWS-1256', 'WINDOWS-1257', 'ISO_8859-1',
		'TIS-620', 'WINDOWS-874', 'CHARSET=US-ASCII', 'unknown-8bit', '') as $charset) {
	$threw = false;
	$out = null;
	try {
		$out = DocumentText::toUtf8("caf\xe9 test", $charset);
	} catch (Throwable $e) {
		$threw = true;
	}
	check(!$threw && is_string($out) && $out !== '' && mb_check_encoding($out, 'UTF-8'),
		'Declared charset "' . ($charset === '' ? '(none)' : $charset) . '" converts without throwing');
}

harness_finish();
?>
