<?php
/** @joinery-test
 * name: mime_build_js
 * tier: db
 * env: dev-only
 * needs: [node]
 */
/**
 * PURPOSE: The browser's message builder (assets/mailbox_mime_build.js), which
 * rebuilds a Fortress message for a mailbox export, makes the same bytes as
 * the server's MessageRebuild for the same message — and the same mbox entry
 * as MailExport::mboxEntry.
 *
 * A corpus of stored messages (plain only; HTML only; both; an inline
 * picture; attachments with an empty one and a non-ASCII name; a forwarded
 * message whose insides are listed too; a row with no header block, a
 * non-ASCII subject and names, sent with Bcc) is built by MessageRebuild, then
 * handed to the real JS file under node as the opened fields and part bytes a
 * browser would have. Every message must match byte for byte.
 *
 * Run: php plugins/mailbox/tests/mime_build_js_test.php
 *
 * @version 1.2 - entries take the export header lines (B70)
 * @version 1.1 - raw mbox entries, a bare CR before From included
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	foreach ($raw_expected as $i => $e) {
	check(($got['__raw'][$i] ?? '') === $e, 'raw case ' . ($i + 1) . ': the same mbox entry (From-escaping by line starts)',
		json_encode(base64_decode($got['__raw'][$i] ?? '')) . ' vs ' . json_encode(base64_decode($e)));
}

harness_finish();
}
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

$db = DbConnector::get_instance()->get_db_link();
$router = new InboundEmailRouter();
$suffix = bin2hex(random_bytes(4));
$domain_name = 'mime-build-' . $suffix . '.example';
mailbox_purge_domains('mime-build-%');
harness_defer(function () { mailbox_purge_domains('mime-build-%'); });

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->save();
$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'box');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$address = 'box@' . $domain_name;

/** A raw message from a body tree: [type, body] leaves, or [multipart subtype, children]. */
function mb_part(array $p): string {
	if (isset($p['multipart'])) {
		$b = '=_mb_' . md5(serialize($p));
		$out = 'Content-Type: multipart/' . $p['multipart'] . '; boundary="' . $b . "\"\r\n\r\n";
		foreach ($p['parts'] as $child) {
			$out .= '--' . $b . "\r\n" . mb_part($child) . "\r\n";
		}
		return $out . '--' . $b . "--\r\n";
	}
	$h = 'Content-Type: ' . $p['type'] . (isset($p['name']) ? '; name="' . $p['name'] . '"' : '; charset=UTF-8') . "\r\n";
	if (isset($p['name'])) {
		$h .= 'Content-Disposition: ' . (isset($p['cid']) ? 'inline' : 'attachment') . '; filename="' . $p['name'] . "\"\r\n";
	}
	if (isset($p['cid'])) {
		$h .= 'Content-ID: <' . $p['cid'] . ">\r\n";
	}
	if (!empty($p['raw'])) {
		return $h . "\r\n" . $p['body'];
	}
	return $h . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($p['body']));
}
function mb_raw(string $from, string $to, string $subject, string $mid, array $body, string $extra = ''): string {
	return 'From: ' . $from . "\r\nTo: " . $to . "\r\nSubject: " . $subject . "\r\nMessage-ID: <" . $mid . ">\r\n"
		. "Date: Tue, 06 Oct 2026 08:00:00 +0000\r\n" . $extra . "MIME-Version: 1.0\r\n" . mb_part($body);
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$plain = array('type' => 'text/plain', 'body' => "Grüße — a line = with an equals\nand a long one " . str_repeat('x', 120) . " \n");
$html = array('type' => 'text/html', 'body' => '<p>Hello <b>there</b> é</p>');
$inner = mb_raw('Old <old@x.example>', 'You <you@x.example>', 'Inner', 'inner-' . $suffix . '@x.example',
	array('type' => 'text/plain', 'body' => 'The forwarded one'));
$corpus = array(
	'plain only'     => array('type' => 'text/plain', 'body' => "Just text.\nTwo lines."),
	'html only'      => $html,
	'plain and html' => array('multipart' => 'alternative', 'parts' => array($plain, $html)),
	'inline picture' => array('multipart' => 'related', 'parts' => array(
		array('type' => 'text/html', 'body' => '<p>See <img src="cid:dot@x"></p>'),
		array('type' => 'image/png', 'name' => 'dot.png', 'body' => $png, 'cid' => 'dot@x'))),
	'attachments'    => array('multipart' => 'mixed', 'parts' => array($plain,
		array('type' => 'application/octet-stream', 'name' => 'blob.bin', 'body' => random_bytes(5000)),
		array('type' => 'text/plain', 'name' => 'empty.txt', 'body' => ''),
		array('type' => 'application/pdf', 'name' => '=?UTF-8?B?' . base64_encode('résumé (1).pdf') . '?=', 'body' => random_bytes(70000)))),
	'forwarded'      => array('multipart' => 'mixed', 'parts' => array(
		array('type' => 'text/plain', 'body' => 'See below'),
		array('type' => 'message/rfc822', 'name' => 'fwd.eml', 'body' => $inner, 'raw' => true))),
);

$ids = array();
foreach ($corpus as $label => $body) {
	$raw = mb_raw('Ann <ann@sender.example>', $address, 'Corpus ' . $label, 'mb-' . md5($label) . '-' . $suffix . '@sender.example', $body);
	$res = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $address);
	$ids[$label] = (int)$res['message']->key;
}
// A row with no header block, sent with a Bcc: headers made from its fields.
$raw = mb_raw('=?UTF-8?B?' . base64_encode('Zoë Ünïcode') . '?= <zoe@' . $domain_name . '>', '"Bo, Jr." <bo@x.example>, cy@x.example',
	'=?UTF-8?B?' . base64_encode('Héllo wörld — a subject long enough to fold across encoded words') . '?=',
	'synth-' . $suffix . '@' . $domain_name, array('type' => 'text/plain', 'body' => 'Synthesized'), "Cc: Dee <dee@x.example>\r\n");
$res = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $address);
$ids['no header block, sent'] = (int)$res['message']->key;
$db->prepare("UPDATE iem_inbound_email_messages SET iem_raw_headers = NULL, iem_direction = 'outbound', iem_bcc = 'Hidden <hid@x.example>'
	WHERE iem_inbound_email_message_id = ?")->execute(array($ids['no header block, sent']));

// What the browser would hold for each, and what the server builds.
$inputs = array();
$expected = array();
foreach ($ids as $label => $id) {
	$msg = new InboundEmailMessage($id, TRUE);
	$parts = array();
	foreach (new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true),
			array('ima_inbound_message_attachment_id' => 'ASC')) as $att) {
		$got = mailbox_retrieve_attachment_bytes($att, $msg);
		$parts[] = array('content_type' => (string)$att->get('ima_content_type'), 'filename' => (string)$att->get('ima_filename'),
			'content_id' => (string)$att->get('ima_content_id'), 'inline' => (bool)$att->get('ima_is_inline'),
			'mime_part' => (string)$att->get('ima_mime_part'), 'bytes' => base64_encode((string)$got['content']));
	}
	$inputs[$label] = array('id' => $id, 'raw_headers' => (string)$msg->get('iem_raw_headers'), 'sender' => (string)$msg->get('iem_sender'),
		'to' => (string)$msg->get('iem_to'), 'cc' => (string)$msg->get('iem_cc'), 'bcc' => (string)$msg->get('iem_bcc'),
		'recipient' => (string)$msg->get('iem_recipient'), 'direction' => (string)$msg->get('iem_direction'),
		'subject' => (string)$msg->get('iem_subject'), 'received_time' => (string)$msg->get('iem_received_time'),
		'message_id_header' => (string)$msg->get('iem_message_id_header'), 'body_plain' => (string)$msg->get('iem_body_plain'),
		'body_html' => (string)$msg->get('iem_body_html'), 'parts' => $parts);
	$bytes = MessageRebuild::build($msg, true)['bytes'];
	$time = strtotime((string)$msg->get('iem_received_time') . ' UTC');
	$headers = MailExport::exportHeaders('inbox', array('Bills, 2026'), false, true);
	$expected[$label] = array('bytes' => base64_encode($bytes),
		'entry' => base64_encode(MailExport::mboxEntry(MessageRebuild::marked($bytes), $headers,
			MailExport::envelopeOf((string)$msg->get('iem_sender')), $time)),
		'headers' => $headers, 'time' => $time);
}

$dir = sys_get_temp_dir() . '/mbjs_' . bin2hex(random_bytes(4));
mkdir($dir);
harness_defer(function () use ($dir) {
	foreach (glob($dir . '/*') as $f) { @unlink($f); }
	@rmdir($dir);
});
// Raw messages straight into the mbox entry: a bare CR before "From " is not a
// line start for the server (PCRE /m), so the browser must not escape it either.
$raw_cases = array("Subject: cr\r\n\r\nline one\rFrom here\r\nFrom a real line\r\n>From quoted\r\n",
	"Subject: lf\n\nFrom lf start\n>>From deep\nno newline at the end");
$raw_expected = array();
foreach ($raw_cases as $i => $r) {
	$raw_expected[$i] = base64_encode(MailExport::mboxEntry($r, MailExport::exportHeaders('inbox', array(), true, false), 'a@b.example', 1792700000));
}
file_put_contents($dir . '/in.json', json_encode(array('inputs' => $inputs, 'expected' => $expected,
	'raw_headers' => MailExport::exportHeaders('inbox', array(), true, false),
	'raw' => array_map('base64_encode', $raw_cases))));
$driver = <<<'JS'
const fs = require('fs');
const B = require(process.argv[2]);
const data = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
(async () => {
  const out = {};
  for (const label in data.inputs) {
    const m = data.inputs[label];
    m.parts = m.parts.map(p => Object.assign({}, p, { bytes: new Uint8Array(Buffer.from(p.bytes, 'base64')) }));
    const bytes = await B.build(m);
    const e = data.expected[label];
    const entry = B.mboxEntry(B.marked(bytes), e.headers, B.envelopeOf(m.sender), e.time);
    out[label] = { bytes: Buffer.from(bytes).toString('base64'), entry: Buffer.from(entry).toString('base64') };
  }
  out.__raw = data.raw.map(r => Buffer.from(B.mboxEntry(new Uint8Array(Buffer.from(r, 'base64')), data.raw_headers, 'a@b.example', 1792700000)).toString('base64'));
  fs.writeFileSync(process.argv[4], JSON.stringify(out));
})().catch(e => { console.error(e && e.stack || e); process.exit(1); });
JS;
file_put_contents($dir . '/driver.js', $driver);
$js = PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_mime_build.js');
exec('node ' . escapeshellarg($dir . '/driver.js') . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($dir . '/in.json') . ' '
	. escapeshellarg($dir . '/out.json') . ' 2>&1', $lines, $rc);
section('The corpus covers what it claims');
check(count($inputs['attachments']['parts']) === 3 && count($inputs['inline picture']['parts']) === 1
	&& count($inputs['forwarded']['parts']) >= 2, 'the attachment, inline and forwarded messages carry their parts',
	json_encode(array_map(function ($i) { return count($i['parts']); }, $inputs)));
$synth = base64_decode($expected['no header block, sent']['bytes']);
check($inputs['no header block, sent']['raw_headers'] === '' && strpos($synth, "\r\nBcc: ") !== false
	&& strpos($synth, '=?UTF-8?B?') !== false, 'the last message is built from its fields: Bcc, encoded words', substr($synth, 0, 700));
$fwd = base64_decode($expected['forwarded']['bytes']);
check(substr_count($fwd, 'Content-Disposition: attachment') === 1, 'a forwarded message travels whole, its insides not again beside it');

section('The browser builds what the server builds');
check($rc === 0, 'node ran the builder', implode("\n", $lines));
$got = json_decode((string)@file_get_contents($dir . '/out.json'), true) ?: array();
foreach ($expected as $label => $e) {
	$a = base64_decode($got[$label]['bytes'] ?? '');
	$b = base64_decode($e['bytes']);
	$at = strspn($a ^ $b, "\0");
	check($a === $b, $label . ': the same message, byte for byte', 'first difference at ' . $at . ': '
		. json_encode(substr($b, max(0, $at - 30), 60)) . ' vs ' . json_encode(substr($a, max(0, $at - 30), 60)));
	check(base64_decode($got[$label]['entry'] ?? '') === base64_decode($e['entry']), $label . ': and the same mbox entry');
}

foreach ($raw_expected as $i => $e) {
	check(($got['__raw'][$i] ?? '') === $e, 'raw case ' . ($i + 1) . ': the same mbox entry (From-escaping by line starts)',
		json_encode(base64_decode($got['__raw'][$i] ?? '')) . ' vs ' . json_encode(base64_decode($e)));
}

harness_finish();
