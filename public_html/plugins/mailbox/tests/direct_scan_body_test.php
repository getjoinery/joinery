<?php
/** @joinery-test
 * name: direct_scan_body
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * What the spam scanner actually sees for a Direct message.
 *
 * A Direct message never became a MIME document, so one is synthesized for the
 * scan. If that synthesis keeps only the plain body when one exists, spam hidden
 * in the HTML — link farms, hidden text, tracking URLs — rides in unseen behind
 * an innocuous plain part. The synthesized message must therefore carry BOTH
 * bodies, exactly as the scanner would receive them off the wire.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

function scan_raw(string $plain, string $html): string {
	$router = new InboundEmailRouter();
	$m = new ReflectionMethod('InboundEmailRouter', 'synthesizeRawForScan');
	$m->setAccessible(true);
	return $m->invoke($router,
		array('sender' => 'a@b.test', 'recipient' => 'c@d.test', 'subject' => 'S'), $plain, $html);
}

$PLAIN_MARK = 'PLAINBODYMARKER';
$HTML_MARK  = 'HTMLBODYMARKER';

// ---------------------------------------------------------------------------
section('Both bodies reach the scanner');
// ---------------------------------------------------------------------------

$raw = scan_raw('Hi there ' . $PLAIN_MARK,
	'<html><body><a href="http://spam.example/' . $HTML_MARK . '">click</a></body></html>');
check(strpos($raw, $PLAIN_MARK) !== false, 'the plain body is present');
check(strpos($raw, $HTML_MARK) !== false,
	'and so is the HTML body — spam hidden in HTML behind a benign plain part is no longer invisible');
check(stripos($raw, 'multipart/alternative') !== false,
	'the two are presented as multipart/alternative, as a real client would send them');
check(strpos($raw, '<a href') !== false,
	'the HTML keeps its structure rather than being flattened, so the scanner reads its links');

// ---------------------------------------------------------------------------
section('A single body is presented as itself');
// ---------------------------------------------------------------------------

$html_only = scan_raw('', '<p>' . $HTML_MARK . '</p>');
check(strpos($html_only, $HTML_MARK) !== false && stripos($html_only, 'text/html') !== false,
	'an HTML-only message is scanned as HTML, not stripped to nothing');

$plain_only = scan_raw($PLAIN_MARK, '');
check(strpos($plain_only, $PLAIN_MARK) !== false && stripos($plain_only, 'text/plain') !== false,
	'a plain-only message is scanned as plain text');

harness_finish();
