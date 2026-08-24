<?php
/** @joinery-test
 * name: deliverability_report
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Deliverability report detection and parsing (specs/deliverability_report_ingest.md).
 *
 * Pure-content tests — no DB rows: the D1 two-of-three detector against
 * synthesized carrier messages (including a REAL Google aggregate report zip
 * captured from dev.getjoinery.com), the RFC 7489 XML parser against the real
 * Google dialect and a Microsoft-dialect fixture, the TLS-RPT JSON parser, the
 * ARF parser, and the D9 safety rules: DOCTYPE-carrying XML refused,
 * compression bombs capped, ordinary mail with a zip attachment untouched.
 *
 * Filing, dedup, discard of unhosted-domain reports and the deferred Fortress
 * path are the db-tier suite (deliverability_report_ingest_test).
 *
 * Run: php plugins/mailbox/tests/deliverability_report_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DeliverabilityReportIngest.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/deliverability_report_class.php'));

$FIX = __DIR__ . '/fixtures/deliverability/';
$router = new InboundEmailRouter();

/** A carrier email with an optional single attachment. */
function dv_carrier(string $subject, ?string $filename, ?string $payload,
		string $ct = 'application/zip'): string {
	$boundary = 'dvtest_' . bin2hex(random_bytes(4));
	$eml = "From: Reporter <noreply-dmarc-support@bigmail.example>\r\n"
		. "To: <postmaster@reports.example>\r\n"
		. "Subject: " . $subject . "\r\n"
		. "Message-ID: <" . bin2hex(random_bytes(6)) . "@bigmail.example>\r\n"
		. "Date: Sun, 24 Aug 2026 10:00:00 +0000\r\n"
		. "MIME-Version: 1.0\r\n"
		. "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n\r\n"
		. "--" . $boundary . "\r\n"
		. "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
		. "Report attached.\r\n";
	if ($filename !== null && $payload !== null) {
		$eml .= "--" . $boundary . "\r\n"
			. "Content-Type: " . $ct . "; name=\"" . $filename . "\"\r\n"
			. "Content-Disposition: attachment; filename=\"" . $filename . "\"\r\n"
			. "Content-Transfer-Encoding: base64\r\n\r\n"
			. chunk_split(base64_encode($payload), 76, "\r\n");
	}
	$eml .= "--" . $boundary . "--\r\n";
	return $eml;
}

function dv_detect(InboundEmailRouter $router, string $raw): ?array {
	return DeliverabilityReportIngest::detect($router, $raw, $router->parseEmail($raw));
}

$google_zip = file_get_contents($FIX . 'google_aggregate.zip');
$google_xml = file_get_contents($FIX . 'google_aggregate.xml');
$ms_xml     = file_get_contents($FIX . 'microsoft_aggregate.xml');
$tlsrpt     = file_get_contents($FIX . 'tlsrpt.json');
$arf        = file_get_contents($FIX . 'arf_complaint.eml');
$google_subject = 'Report domain: dev.getjoinery.com Submitter: google.com Report-ID: 14651549524214893979';
$google_name = 'google.com!dev.getjoinery.com!1787356800!1787443199.zip';

section('D1: two of three signals detect a report');

$d = dv_detect($router, dv_carrier($google_subject, $google_name, $google_zip));
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_DMARC_AGGREGATE,
	'a real Google aggregate report (subject + filename + payload) is detected',
	$d === null ? 'not detected' : $d['kind']);
check($d !== null && $d['parsed_payload'] instanceof DOMDocument,
	'and its zip payload was extracted and structurally parsed');

$d = dv_detect($router, dv_carrier('Your weekly stats', $google_name, $google_zip));
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_DMARC_AGGREGATE,
	'filename + payload alone suffice (a reporter with a nonstandard subject)');

$d = dv_detect($router, dv_carrier($google_subject, 'report.zip', $google_zip));
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_DMARC_AGGREGATE,
	'subject + payload alone suffice (a reporter with a nonstandard filename)');

$d = dv_detect($router, dv_carrier($google_subject, $google_name, "PK\x03\x04garbage-not-a-real-zip"));
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_UNKNOWN,
	'subject + filename with an unreadable payload detect as an UNKNOWN report kind (D5: counted, not dropped)',
	$d === null ? 'not detected' : $d['kind']);

section('Acceptance 3: ordinary mail is untouched');

$d = dv_detect($router, dv_carrier('Holiday photos', 'photos.zip', $google_zip));
check($d === null, 'an ordinary message carrying a zip is not a report (one signal is never enough)');

$d = dv_detect($router, dv_carrier($google_subject, null, null));
check($d === null, 'a bare message whose subject merely looks reportish is not a report');

$d = dv_detect($router, "From: a@b.example\r\nTo: c@d.example\r\nSubject: hi\r\n\r\nplain body\r\n");
check($d === null, 'plain mail with no attachments is not a report');

section('Kind resolution: TLS-RPT and ARF');

$d = dv_detect($router, dv_carrier(
	'Report Domain: reports.example Submitter: google.com Report-ID: <2026.08.19@google.com>',
	'google.com!reports.example!1787788800!1787875199.json.gz',
	gzencode($tlsrpt), 'application/tlsrpt+gzip'));
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_TLSRPT,
	'a gzipped TLS-RPT attachment resolves to the tlsrpt kind', $d === null ? 'not detected' : $d['kind']);

$d = dv_detect($router, $arf);
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_ARF,
	'multipart/report; report-type=feedback-report resolves to the arf kind');

section('RFC 7489 parser: the real Google dialect');

$doc = new DOMDocument();
$doc->loadXML($google_xml);
$g = DeliverabilityReportIngest::parseDmarcAggregate($doc);
check($g['org_name'] === 'google.com', 'reporter org extracted', $g['org_name']);
check($g['report_id'] === '14651549524214893979', 'report id extracted', $g['report_id']);
check($g['domain'] === 'dev.getjoinery.com', 'reported domain extracted', $g['domain']);
check(count($g['sources']) === 1, 'one source row', count($g['sources']) . ' rows');
check($g['sources'][0]['ip'] === '143.55.232.12' && $g['sources'][0]['count'] === 20,
	'source IP and count correct', $g['sources'][0]['ip'] . '/' . $g['sources'][0]['count']);
check($g['sources'][0]['aligned'] === true, 'passing policy_evaluated reads as aligned');
check($g['message_count'] === 20, 'message count summed', (string)$g['message_count']);
check($g['begin'] === gmdate('Y-m-d H:i:s', 1787356800), 'window begin converted to UTC timestamp', (string)$g['begin']);
check(!empty($g['policy']) && $g['policy']['p'] === 'none', 'published policy captured');
check(count($g['sources'][0]['auth_detail']) === 3, 'auth_results detail preserved (2 dkim + 1 spf)',
	count($g['sources'][0]['auth_detail']) . ' entries');

section('RFC 7489 parser: the Microsoft dialect');

$doc = new DOMDocument();
$doc->loadXML($ms_xml);
$m = DeliverabilityReportIngest::parseDmarcAggregate($doc);
check($m['org_name'] === 'Enterprise Outlook', 'reporter org extracted', $m['org_name']);
check(count($m['sources']) === 2, 'both records extracted', count($m['sources']) . ' rows');
$bad = null; $good = null;
foreach ($m['sources'] as $s) {
	if ($s['ip'] === '203.0.113.7') { $bad = $s; }
	if ($s['ip'] === '198.51.100.22') { $good = $s; }
}
check($bad !== null && $bad['aligned'] === false && $bad['disposition'] === 'reject',
	'the failing source reads unaligned with its reject disposition');
check($good !== null && $good['aligned'] === true, 'the passing source reads aligned');
check($m['message_count'] === 17, 'message count summed across records', (string)$m['message_count']);

section('TLS-RPT parser');

$t = DeliverabilityReportIngest::parseTlsRpt(json_decode($tlsrpt, true));
check($t['org_name'] === 'Google Inc.', 'reporter org extracted', $t['org_name']);
check($t['domain'] === 'reports.example', 'policy domain extracted', $t['domain']);
check(count($t['sources']) === 1 && $t['sources'][0]['ip'] === '192.0.2.88'
	&& $t['sources'][0]['count'] === 2,
	'failure details become source rows');
check($t['sources'][0]['aligned'] === true && $t['sources'][0]['disposition'] === 'tls:certificate-expired',
	'a TLS failure is not an alignment failure; the failure type rides the disposition');
check($t['message_count'] === 44, 'session totals summed', (string)$t['message_count']);

section('ARF parser');

$router2 = new InboundEmailRouter();
$parsed_arf = $router2->parseEmail($arf);
$a = DeliverabilityReportIngest::parseArf($arf, $parsed_arf, null);
check($a['domain'] === 'reports.example', 'Reported-Domain wins', $a['domain']);
check(count($a['sources']) === 1 && $a['sources'][0]['ip'] === '198.51.100.30',
	'Source-IP becomes the source row');
check($a['sources'][0]['disposition'] === 'complaint:abuse', 'feedback type rides the disposition');
check($a['sources'][0]['aligned'] === true, 'a complaint is about mail the domain really sent — aligned');
check($a['report_id'] === '<arf-fixture-1@bigmail.example>', 'carrier Message-ID is the report id');

section('D9: report content is untrusted input');

$xxe = "<?xml version=\"1.0\"?><!DOCTYPE feedback [<!ENTITY x SYSTEM \"file:///etc/passwd\">]>"
	. "<feedback><report_metadata><org_name>&x;</org_name></report_metadata></feedback>";
$d = dv_detect($router, dv_carrier($google_subject, $google_name, gzencode($xxe), 'application/gzip'));
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_UNKNOWN,
	'DOCTYPE-carrying XML is refused at classification; the message still detects (2 signals) as unknown',
	$d === null ? 'not detected' : $d['kind']);

$bomb = gzencode(str_repeat('A', DeliverabilityReportIngest::MAX_DECOMPRESSED_BYTES + 1048576));
check(strlen($bomb) < DeliverabilityReportIngest::MAX_COMPRESSED_BYTES,
	'(the bomb fixture is small compressed: ' . strlen($bomb) . ' bytes)');
$d = dv_detect($router, dv_carrier($google_subject, $google_name, $bomb, 'application/gzip'));
check($d !== null && $d['kind'] === DeliverabilityReport::KIND_UNKNOWN && $d['payload'] === null,
	'a compression bomb is capped during inflate and never becomes a payload');

harness_finish();
