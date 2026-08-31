<?php
/** @joinery-test
 * name: deliverability_report_ingest
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Deliverability report filing (specs/deliverability_report_ingest.md).
 *
 * The end-to-end ingest behavior the safe-tier suite cannot cover: a report
 * arriving at a hosted domain is FILED — dvr/dvs rows, transaction-log entry
 * — and never becomes a mailbox message (D3/D8); a provider retry dedups on
 * the report identity (D6); a report about an unhosted domain is discarded
 * (D9); an unreadable report keeps its raw for diagnosis (D6); ordinary mail
 * still stores; the D7 notice fires once for a new unaligned source, stays
 * silent for a known one, and fires once more on a sharp volume jump; and the
 * deferred Fortress path (D2's second plaintext moment) files at unlock and
 * removes its pending row.
 *
 * Run: php tests/run.php db --filter=deliverability_report_ingest
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/DeliverabilityReportIngest.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/deliverability_report_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/deliverability_report_source_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_log_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

$db = DbConnector::get_instance()->get_db_link();
$router = new InboundEmailRouter();

function dvi_domain(string $level = 'standard'): InboundEmailDomain {
	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'dvi-' . bin2hex(random_bytes(4)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_security_level', $level);
	$dom->set('ied_catch_all_mode', 'store');
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	return $dom;
}

/** An RFC 7489 aggregate XML about $domain from $org, one unaligned source. */
function dvi_xml(string $domain, string $org, string $report_id, string $ip, int $count): string {
	return '<?xml version="1.0" encoding="UTF-8" ?><feedback>'
		. '<report_metadata><org_name>' . $org . '</org_name>'
		. '<email>reports@' . $org . '</email>'
		. '<report_id>' . $report_id . '</report_id>'
		. '<date_range><begin>1787356800</begin><end>1787443199</end></date_range></report_metadata>'
		. '<policy_published><domain>' . $domain . '</domain><p>reject</p></policy_published>'
		. '<record><row><source_ip>' . $ip . '</source_ip><count>' . $count . '</count>'
		. '<policy_evaluated><disposition>reject</disposition><dkim>fail</dkim><spf>fail</spf></policy_evaluated></row>'
		. '<identifiers><header_from>' . $domain . '</header_from></identifiers>'
		. '<auth_results><spf><domain>elsewhere.example</domain><result>fail</result></spf></auth_results>'
		. '</record></feedback>';
}

/** A carrier email delivering $payload as a gzipped aggregate attachment. */
function dvi_carrier(string $domain, string $org, string $report_id, string $payload): string {
	$boundary = 'dvi_' . bin2hex(random_bytes(4));
	$name = $org . '!' . $domain . '!1787356800!1787443199.xml.gz';
	return "From: Reporter <noreply@" . $org . ">\r\n"
		. "To: <postmaster@" . $domain . ">\r\n"
		. "Subject: Report domain: " . $domain . " Submitter: " . $org . " Report-ID: " . $report_id . "\r\n"
		. "Message-ID: <" . bin2hex(random_bytes(6)) . "@" . $org . ">\r\n"
		. "Date: Sun, 24 Aug 2026 10:00:00 +0000\r\n"
		. "MIME-Version: 1.0\r\n"
		. "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n\r\n"
		. "--" . $boundary . "\r\nContent-Type: text/plain\r\n\r\nAttached.\r\n"
		. "--" . $boundary . "\r\n"
		. "Content-Type: application/gzip; name=\"" . $name . "\"\r\n"
		. "Content-Disposition: attachment; filename=\"" . $name . "\"\r\n"
		. "Content-Transfer-Encoding: base64\r\n\r\n"
		. chunk_split(base64_encode(gzencode($payload)), 76, "\r\n")
		. "--" . $boundary . "--\r\n";
}

function dvi_register_reports(PDO $db, int $domain_id): array {
	$q = $db->prepare("SELECT dvr_deliverability_report_id FROM dvr_deliverability_reports WHERE dvr_ied_inbound_email_domain_id = ?");
	$q->execute(array($domain_id));
	$ids = $q->fetchAll(PDO::FETCH_COLUMN, 0);
	foreach ($ids as $id) {
		$s = $db->prepare("SELECT dvs_deliverability_report_source_id FROM dvs_deliverability_report_sources WHERE dvs_dvr_deliverability_report_id = ?");
		$s->execute(array($id));
		foreach ($s->fetchAll(PDO::FETCH_COLUMN, 0) as $sid) {
			harness_register_row('dvs_deliverability_report_sources', 'dvs_deliverability_report_source_id', intval($sid));
		}
		harness_register_row('dvr_deliverability_reports', 'dvr_deliverability_report_id', intval($id));
	}
	return $ids;
}

function dvi_register_logs(PDO $db, int $domain_id): void {
	$q = $db->prepare("SELECT iel_inbound_email_log_id FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = ?");
	$q->execute(array($domain_id));
	foreach ($q->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
		harness_register_row('iel_inbound_email_logs', 'iel_inbound_email_log_id', intval($id));
	}
}

function dvi_message_count(PDO $db, int $domain_id): int {
	$q = $db->prepare("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = ?");
	$q->execute(array($domain_id));
	return (int)$q->fetchColumn();
}

$notices = array();
DeliverabilityReportIngest::$notice_capture = function ($domain, $notable) use (&$notices) {
	$notices[] = $notable;
};

section('A report arriving at a hosted domain is filed, not delivered');

$dom = dvi_domain();
$name = strtolower($dom->get('ied_domain'));
$raw1 = dvi_carrier($name, 'reporter.example', 'rpt-001', dvi_xml($name, 'reporter.example', 'rpt-001', '203.0.113.50', 4));

$exit = $router->processEmail($raw1, 'postmaster@' . $name);
check($exit === 0, 'ingest accepts the report', 'exit ' . var_export($exit, true));
check(dvi_message_count($db, intval($dom->key)) === 0,
	'D3/D8: no mailbox message exists — counters describe human mail');

$report_ids = dvi_register_reports($db, intval($dom->key));
check(count($report_ids) === 1, 'one report row filed', count($report_ids) . ' rows');
$rpt = new DeliverabilityReport(intval($report_ids[0]), TRUE);
check($rpt->get('dvr_parse_status') === DeliverabilityReport::PARSE_PARSED, 'parsed', $rpt->get('dvr_parse_status'));
check((string)$rpt->get('dvr_raw_report') === '', 'D6: a parsed report keeps no raw');
check($rpt->get('dvr_org_name') === 'reporter.example' && (int)$rpt->get('dvr_message_count') === 4,
	'reporter and counts recorded');

$srcs = new MultiDeliverabilityReportSource(array('report_id' => intval($report_ids[0])));
$src_rows = array();
foreach ($srcs as $s) { $src_rows[] = $s; }
check(count($src_rows) === 1 && $src_rows[0]->get('dvs_source_ip') === '203.0.113.50',
	'source row written with the reported IP');
check(!$src_rows[0]->get('dvs_aligned'), 'and it reads unaligned');

$q = $db->prepare("SELECT COUNT(*) FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = ? AND iel_status = ?");
$q->execute(array(intval($dom->key), InboundEmailLog::STATUS_REPORT_FILED));
check((int)$q->fetchColumn() === 1, 'the filing is visible in the transaction log');

section('D7: a new unaligned source is worth one email, once');

check(count($notices) === 1 && count($notices[0]) === 1 && $notices[0][0]['reason'] === 'new',
	'the first sighting produced exactly one notice', json_encode($notices));

$raw2 = dvi_carrier($name, 'reporter.example', 'rpt-002', dvi_xml($name, 'reporter.example', 'rpt-002', '203.0.113.50', 6));
$router->processEmail($raw2, 'postmaster@' . $name);
dvi_register_reports($db, intval($dom->key));
check(count($notices) === 1, 'the same source in a later report stays silent');

$raw3 = dvi_carrier($name, 'reporter.example', 'rpt-003', dvi_xml($name, 'reporter.example', 'rpt-003', '203.0.113.50', 500));
$router->processEmail($raw3, 'postmaster@' . $name);
dvi_register_reports($db, intval($dom->key));
check(count($notices) === 2 && $notices[1][0]['reason'] === 'escalation',
	'a sharp volume jump notifies once more', json_encode(count($notices)));

section('D6: a provider retry dedups on the report identity');

$exit = $router->processEmail($raw1, 'postmaster@' . $name);
check($exit === 0, 'the retry is accepted (the sender must not requeue)');
$q = $db->prepare("SELECT COUNT(*) FROM dvr_deliverability_reports WHERE dvr_ied_inbound_email_domain_id = ?");
$q->execute(array(intval($dom->key)));
$n = (int)$q->fetchColumn();
 check($n === 3, 'but no second row for the same report id', $n . ' rows');
check(dvi_message_count($db, intval($dom->key)) === 0, 'and still no mailbox message');

section('D9: a report about an unhosted domain is discarded');

$raw_foreign = dvi_carrier($name, 'reporter.example', 'rpt-foreign',
	dvi_xml('not-hosted-here.example', 'reporter.example', 'rpt-foreign', '203.0.113.60', 2));
$exit = $router->processEmail($raw_foreign, 'postmaster@' . $name);
check($exit === 0, 'accepted (never bounced back at the reporter)');
$q = $db->prepare("SELECT COUNT(*) FROM dvr_deliverability_reports WHERE dvr_report_id = 'rpt-foreign'");
$q->execute();
check((int)$q->fetchColumn() === 0, 'no rows recorded for a domain this platform does not host');
check(dvi_message_count($db, intval($dom->key)) === 0, 'and no message delivered');

section('D6: an unreadable report keeps its original for diagnosis');

$raw_broken = dvi_carrier($name, 'reporter.example', 'rpt-broken', '<feedback><report_metadata>truncated');
$exit = $router->processEmail($raw_broken, 'postmaster@' . $name);
check($exit === 0, 'accepted');
$new_ids = dvi_register_reports($db, intval($dom->key));
$broken = null;
foreach ($new_ids as $id) {
	$r = new DeliverabilityReport(intval($id), TRUE);
	if ($r->get('dvr_parse_status') !== DeliverabilityReport::PARSE_PARSED) { $broken = $r; }
}
check($broken !== null, 'an unparseable report row exists');
check($broken !== null && strlen((string)$broken->get('dvr_raw_report')) > 0,
	'and it kept the raw carrier message');
check(dvi_message_count($db, intval($dom->key)) === 0, 'without becoming a mailbox message');

section('Acceptance 3: ordinary mail still stores');

$boundary = 'ord_' . bin2hex(random_bytes(4));
$ordinary = "From: Someone <someone@elsewhere.example>\r\n"
	. "To: <postmaster@" . $name . ">\r\n"
	. "Subject: Holiday photos\r\n"
	. "Message-ID: <ordinary-" . bin2hex(random_bytes(6)) . "@elsewhere.example>\r\n"
	. "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n\r\n"
	. "--" . $boundary . "\r\nContent-Type: text/plain\r\n\r\nSee attached.\r\n"
	. "--" . $boundary . "\r\nContent-Type: application/zip; name=\"photos.zip\"\r\n"
	. "Content-Disposition: attachment; filename=\"photos.zip\"\r\n"
	. "Content-Transfer-Encoding: base64\r\n\r\n"
	. chunk_split(base64_encode(file_get_contents(__DIR__ . '/fixtures/deliverability/google_aggregate.zip')), 76, "\r\n")
	. "--" . $boundary . "--\r\n";
$exit = $router->processEmail($ordinary, 'postmaster@' . $name);
check($exit === 0, 'stored without error');
check(dvi_message_count($db, intval($dom->key)) === 1,
	'a human message carrying a zip lands in the mailbox untouched');
$q = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = ?");
$q->execute(array(intval($dom->key)));
foreach ($q->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
	harness_register_model('InboundEmailMessage', intval($id));
	$a = $db->prepare("SELECT ima_inbound_message_attachment_id, ima_fil_file_id FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ?");
	$a->execute(array(intval($id)));
	foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $att) {
		harness_register_row('ima_inbound_message_attachments', 'ima_inbound_message_attachment_id', intval($att['ima_inbound_message_attachment_id']));
		if ($att['ima_fil_file_id']) {
			harness_register_model('File', intval($att['ima_fil_file_id']));
		}
	}
}

section('D2: the deferred Fortress path files at unlock');

$fdom = dvi_domain('fortress');
$fname = strtolower($fdom->get('ied_domain'));
$kp = (new SealedBox())->generateKeypair();
$fraw = dvi_carrier($fname, 'reporter.example', 'rpt-fortress', dvi_xml($fname, 'reporter.example', 'rpt-fortress', '203.0.113.70', 9));
$sealed = (new SealedBox())->sealDek($fraw, $kp['public']);

$result = $router->storeRelayPending(
	array('recipient' => 'postmaster@' . $fname, 'message_id' => '<fortress-' . bin2hex(random_bytes(6)) . '@reporter.example>',
		'size' => strlen($fraw), 'received_utc' => gmdate('Y-m-d H:i:s'), 'spool_id' => 'dvi-' . bin2hex(random_bytes(8))),
	$sealed, $fdom, null, User::USER_SYSTEM);
check($result['message'] !== null, 'the pending row stored');
$pending_id = intval($result['message']->key);
harness_register_model('InboundEmailMessage', $pending_id);

$msg = new InboundEmailMessage($pending_id, TRUE);
$done = $router->parsePendingMessage($msg, $kp['secret']);
check($done === true, 'deferred parse reports the row handled');

$freports = dvi_register_reports($db, intval($fdom->key));
check(count($freports) === 1, 'the report filed for the Fortress domain — the case a post-hoc parser cannot serve');
$q = $db->prepare("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
$q->execute(array($pending_id));
check((int)$q->fetchColumn() === 0, 'and the pending message row is gone');

dvi_register_logs($db, intval($dom->key));
dvi_register_logs($db, intval($fdom->key));
DeliverabilityReportIngest::$notice_capture = null;

harness_finish();
