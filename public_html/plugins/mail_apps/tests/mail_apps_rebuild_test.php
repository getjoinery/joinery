<?php
/** @joinery-test
 * name: mail_apps_rebuild
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Whole messages for mail programs (specs/mail_apps_plugin.md § Whole
 * messages): a stored original is served as stored, and a lean record is
 * rebuilt — the SAME bytes every time, in this process and in another, with
 * the size told first matching the bytes served.
 *
 * Covers a corpus of manifest shapes: plain only; HTML only; plain and HTML;
 * HTML with an inline image; attachments (binary, empty, a non-ASCII name);
 * all of those at once. For each:
 *  - header() and bytes() agree on the size, and the size is fixed in
 *    mam_client_size at first telling
 *  - two builds are byte-identical, and a build in a separate PHP process is
 *    too (sha256)
 *  - the rebuilt MIME parses back to the same bodies and attachment bytes
 *  - CRLF throughout, no line over 998 octets, the retained headers kept and
 *    their MIME headers replaced
 * And the pure pieces: quoted-printable round-trips with lines under 77
 * characters, base64Length() matches base64Lines() for every length class.
 *
 * Run: php -d apc.enable_cli=1 plugins/mail_apps/tests/mail_apps_rebuild_test.php
 *
 * @version 1.1 - the encoders and kindOf() are the mailbox plugin's MessageRebuild
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('MailAppRebuild')) {
	harness_skip('the mail_apps plugin is not active here', 'activate it, then run this suite');
	harness_finish();
}
require_once(__DIR__ . '/lib/mail_apps_fixtures.php');

section('The pure pieces');
foreach (array(0, 1, 2, 3, 56, 57, 58, 113, 114, 1000, 65536) as $n) {
	$bytes = $n ? random_bytes($n) : '';
	check(strlen(MessageRebuild::base64Lines($bytes)) === MessageRebuild::base64Length($n), "base64 length for $n bytes");
}
$text = "Grüße — 100% sure = yes\n" . str_repeat('long line ', 30) . " \n\ttab\n.\nend ";
$qp = MessageRebuild::qp($text);
$long = array_filter(explode("\r\n", $qp), function ($l) { return strlen($l) > 76; });
check(!$long, 'No quoted-printable line is over 76 characters');
check(quoted_printable_decode($qp) === str_replace("\n", "\r\n", $text), 'Quoted-printable round-trips');
check(MessageRebuild::qp($text) === $qp, 'And encodes the same way twice');

$user = make_user('MailAppsRebuild', 0);
$uid = (int)$user->key;
$domain = mafx_domain('rebuild');
$alias = mafx_alias($domain, 'reader', $uid);
$address = strtolower($alias->get_full_address());
harness_defer(function () { SessionControl::get_instance()->clear_api_user(); });

$png = harness_unique_png(); // its own bytes: a fixed picture dedups onto the box's leftovers
$corpus = array(
	'plain only'      => mafx_raw($address, 'Plain', "Just text.\nTwo lines."),
	'html only'       => mafx_raw($address, 'HTML', '', array(), '<p>Only <b>HTML</b></p>'),
	'plain and html'  => mafx_raw($address, 'Both', 'Plain side', array(), '<p>HTML side</p>'),
	'inline image'    => mafx_raw($address, 'Inline', 'See picture', array(
		array('type' => 'image/png', 'name' => 'dot.png', 'body' => $png, 'cid' => 'dot@x')), '<p><img src="cid:dot@x"></p>'),
	'attachments'     => mafx_raw($address, 'Files', 'Attached', array(
		array('type' => 'application/octet-stream', 'name' => 'blob.bin', 'body' => random_bytes(5000)),
		array('type' => 'text/plain', 'name' => 'empty.txt', 'body' => ''),
		array('type' => 'application/pdf', 'name' => 'résumé.pdf', 'body' => random_bytes(70000)))),
	'everything'      => mafx_raw($address, '=?UTF-8?B?' . base64_encode('Ünïcode subject') . '?=', 'All of it',
		array(array('type' => 'image/png', 'name' => 'dot.png', 'body' => $png, 'cid' => 'dot2@x'),
		      array('type' => 'application/zip', 'name' => 'a.zip', 'body' => random_bytes(3000))),
		'<p>All <img src="cid:dot2@x"></p>'),
);

$ctx = mafx_context($uid, $address);
$db = DbConnector::get_instance()->get_db_link();
foreach ($corpus as $label => $raw) {
	section('Rebuilt: ' . $label);
	$id = mafx_deliver($alias, $domain, $raw);
	$msg = new InboundEmailMessage($id, TRUE);
	check(MessageRebuild::kindOf($msg) === 'rebuilt', 'Stored as a lean record, so it is rebuilt');

	$head = MailAppRebuild::header($ctx, $id);
	$one = MailAppRebuild::bytes($ctx, $id);
	$two = MailAppRebuild::bytes($ctx, $id);
	check($head['ok'] && $one['ok'], 'Header and bytes are served');
	check($one['bytes'] === $two['bytes'], 'Two builds are byte-identical');
	check($head['size'] === strlen($one['bytes']), 'The size told is the size served', $head['size'] . ' vs ' . strlen($one['bytes']));
	check((int)$db->query("SELECT mam_client_size FROM mam_mail_app_messages WHERE mam_iem_inbound_email_message_id = $id")->fetchColumn()
		=== strlen($one['bytes']), 'The size is fixed at first telling');
	check(strpos($one['bytes'], $head['header']) === 0, 'The header block served is the head of the bytes');

	$script = '$_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "' . PathHelper::getIncludePath('includes/PathHelper.php') . '";'
		. 'SessionControl::get_instance()->set_api_user(' . $uid . ', null);'
		. '$c = MailAppContext::forMailbox("' . $address . '"); $r = MailAppRebuild::bytes($c, ' . $id . ');'
		. 'echo hash("sha256", $r["bytes"] ?? "");';
	$other = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>/dev/null'));
	check($other === hash('sha256', $one['bytes']), 'Another process builds the same bytes', $other);

	$bytes = $one['bytes'];
	check(strpos(str_replace("\r\n", '', $bytes), "\n") === false && strpos(str_replace("\r\n", '', $bytes), "\r") === false,
		'CRLF throughout');
	check(max(array_map('strlen', explode("\r\n", $bytes))) <= 998, 'No line over 998 octets');
	check(strpos($bytes, 'Message-ID: <mafx-') !== false, 'The retained Message-ID is kept');
	check(substr_count(strtolower($head['header']), "\r\nmime-version:") + (stripos($head['header'], 'mime-version:') === 0 ? 1 : 0) === 1,
		'Exactly one MIME-Version header');

	$parsed = MimeParse::parseMessage($bytes);
	$orig = MimeParse::parseMessage($raw);
	$texts = function ($mime) {
		$out = array();
		foreach ($mime->partIterator() as $p) {
			$type = strtolower((string)$p->getType());
			if (($type === 'text/plain' || $type === 'text/html') && !$p->getName()) {
				$out[$type] = trim(str_replace("\r\n", "\n", (string)$p->getContents()));
			}
		}
		return $out;
	};
	$parts = function ($mime) {
		$out = array();
		foreach ($mime->partIterator() as $p) {
			if ($p->getPrimaryType() !== 'multipart' && $p->getName()) {
				$out[(string)$p->getName()] = hash('sha256', (string)$p->getContents());
			}
		}
		ksort($out);
		return $out;
	};
	$want_texts = $texts($orig);
	foreach ($want_texts as $type => $body) {
		if ($body === '') {
			continue; // an empty body part has nothing to carry; the rebuild leaves it out
		}
		check(($texts($parsed)[$type] ?? null) === $body, "The $type body comes back the same");
	}
	check($parts($parsed) === $parts($orig), 'Every attachment comes back byte for byte', json_encode(array_keys($parts($parsed))));
}

section('A stored original is served as stored');
$id = mafx_deliver($alias, $domain, mafx_raw($address, 'Kept whole', 'original'));
$raw = "From: <a@sender.example>\r\nTo: <$address>\r\nSubject: Kept\r\nMessage-ID: <kept-" . bin2hex(random_bytes(4)) . "@sender.example>\r\n\r\nexact bytes\r\n";
InboundEmailMessage::updateColumns($id, array('iem_raw_message' => $raw, 'iem_raw_storage_driver' => 'inline'));
$msg = new InboundEmailMessage($id, TRUE);
check(MessageRebuild::kindOf($msg) === 'stored', 'A row with its original is "stored"');
$served = MailAppRebuild::bytes($ctx, $id);
check($served['ok'] && $served['bytes'] === $raw, 'The original bytes are served unchanged');

harness_finish();
