<?php
/** @joinery-test
 * name: mail_export
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 600
 */
/**
 * PURPOSE: A mailbox comes out as a zip of mbox files and goes back in whole
 * (export_mail_contacts_calendar_drive.md § WP1).
 *
 *  - Folders: each message in exactly one of Inbox, Archive, Sent, Spam, Trash;
 *    drafts left out; a label exported on its own.
 *  - The build: many small slices and several pieces make the same mbox as one
 *    slice; nothing is recorded before its piece is on disk; a run deleted
 *    between slices stops with a cancel and leaves nothing behind.
 *  - The zip: one mbox per folder, mboxrd separators and From-escaping,
 *    X-Gmail-Labels with the view, quoted labels, Unread and Starred; a stored
 *    original travels byte for byte; a rebuilt message is marked; a message
 *    that cannot be read is named in Messages not included.txt.
 *  - Round trip (P2): the zip imports into an empty mailbox through our own
 *    importer with the same messages, folders, labels, read and starred
 *    state, bodies and attachment bytes.
 *  - Private (P3): no build with the window shut; built in it, every piece is
 *    sealed (no plaintext under storage/), and the note names no subject.
 *  - Fortress: a browser-sealed row goes to the browser as ciphertext.
 *  - Asking (P5): a mailbox the caller cannot read is refused; a fresh second
 *    factor is required; a protected mailbox only by its owner; asking twice
 *    returns the same build; delete and the retention sweep take the pieces.
 *  - The download page renders over HTTP; the endpoint refuses a build that
 *    is not ready, and anyone but the owner.
 *
 * Run: php -d apc.enable_cli=1 plugins/mailbox/tests/mail_export_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/../../../tests/lib/http.php');
$has_session = vault_ensure_session(); // before any output: the Private build opens a window

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
foreach (array('mail_export_start', 'mail_export_list', 'mail_export_delete', 'mail_export_fortress_page') as $action) {
	require_once(PathHelper::getIncludePath('plugins/mailbox/logic/' . $action . '_logic.php'));
}

$db = DbConnector::get_instance()->get_db_link();
$router = new InboundEmailRouter();
// Pieces go under a folder of this test's own: storage/ belongs to the web server.
MailExportRun::$root_for_tests = sys_get_temp_dir() . '/mail_export_test_' . bin2hex(random_bytes(4));
harness_defer(function () {
	foreach (glob(MailExportRun::$root_for_tests . '/*', GLOB_ONLYDIR) ?: array() as $d) { MailExportRun::removeDir($d); }
	@rmdir(MailExportRun::$root_for_tests);
});
$suffix = bin2hex(random_bytes(4));
$domain_name = 'mail-export-' . $suffix . '.example';
mailbox_purge_domains('mail-export-%');
$made_runs = array();
/** Delete a row through its model at teardown, if this test has not already. */
function mx_drop_later(string $class, int $id): void {
	harness_defer(function () use ($class, $id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM ' . $class::$tablename
			. ' WHERE ' . $class::$pkey_column . ' = ?');
		$q->execute(array($id));
		if ($q->fetchColumn()) {
			(new $class($id, TRUE))->permanent_delete();
		}
	});
}
/** Is this export run still a row? */
function mx_run_exists(int $id): bool {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM mxr_mail_export_runs WHERE mxr_mail_export_run_id = ?');
	$q->execute(array($id));
	return (bool)$q->fetchColumn();
}
harness_defer(function () use (&$made_runs) {
	foreach ($made_runs as $id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM mxr_mail_export_runs WHERE mxr_mail_export_run_id = ?');
		$q->execute(array($id));
		if ($q->fetchColumn()) {
			(new MailExportRun($id, TRUE))->permanent_delete();
		}
	}
});

// ── Fixtures ────────────────────────────────────────────────────────────────

$owner = make_user('MailExport' . $suffix, 5);
$uid = (int)$owner->key;
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_owner_usr_user_id', $uid);
$domain->set('ied_is_enabled', true);
$domain->save();
mx_drop_later('InboundEmailDomain', (int)$domain->key);
harness_defer(function () { mailbox_purge_domains('mail-export-%'); });

$mk_alias = function (string $local, InboundEmailDomain $d, int $holder) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$d->key);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	$a->load();
	mx_drop_later('InboundEmailAlias', (int)$a->key);
	// The grant goes with its mailbox (a cascade): a protected mailbox refuses
	// to lose its only member while it stands.
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', (int)$a->key);
	$g->set('ieg_usr_user_id', $holder);
	$g->save();
	return $a;
};
$alias = $mk_alias('box', $domain, $uid);
$address = 'box@' . $domain_name;

/** A raw message: plain and HTML, attachments, an inline picture. */
function mx_raw(string $from, string $to, string $subject, string $mid, string $plain, string $html = '', array $parts = array()): string {
	$b1 = '=_mx_alt_' . md5($mid);
	$b2 = '=_mx_mix_' . md5($mid);
	$head = array('From: ' . $from, 'To: ' . $to, 'Subject: ' . $subject, 'Message-ID: <' . $mid . '>',
		'Date: Mon, 05 Oct 2026 10:00:00 +0000', 'MIME-Version: 1.0');
	$alt = '--' . $b1 . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $plain . "\r\n"
		. ($html !== '' ? '--' . $b1 . "\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n" : '')
		. '--' . $b1 . "--\r\n";
	if (!$parts) {
		return implode("\r\n", $head) . "\r\nContent-Type: multipart/alternative; boundary=\"" . $b1 . "\"\r\n\r\n" . $alt;
	}
	$body = '--' . $b2 . "\r\nContent-Type: multipart/alternative; boundary=\"" . $b1 . "\"\r\n\r\n" . $alt;
	foreach ($parts as $p) {
		$body .= '--' . $b2 . "\r\nContent-Type: " . $p['type'] . '; name="' . $p['name'] . "\"\r\nContent-Disposition: "
			. (!empty($p['cid']) ? 'inline' : 'attachment') . '; filename="' . $p['name'] . "\"\r\nContent-Transfer-Encoding: base64\r\n"
			. (!empty($p['cid']) ? 'Content-ID: <' . $p['cid'] . ">\r\n" : '') . "\r\n" . chunk_split(base64_encode($p['body'])) . "\r\n";
	}
	$body .= '--' . $b2 . "--\r\n";
	return implode("\r\n", $head) . "\r\nContent-Type: multipart/mixed; boundary=\"" . $b2 . "\"\r\n\r\n" . $body;
}

$deliver = function (string $raw, InboundEmailAlias $a, InboundEmailDomain $d, string $to) use ($router) {
	$res = $router->storeMessage($raw, $router->parseEmail($raw), $a, $d, $to);
	$id = (int)$res['message']->key;
	mx_drop_later('InboundEmailMessage', $id);
	return $id;
};

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$blob = random_bytes(9000);
$msgs = array();
$msgs['inbox'] = $deliver(mx_raw('Ann Sender <ann@sender.example>', $address, 'Inbox note ' . $suffix, 'inbox-' . $suffix . '@sender.example',
	"Dear box,\nFrom the start this line looks like a separator.\n>From a quoted one too.\nBye", '<p>Hello <img src="cid:dot@x"></p>',
	array(array('type' => 'application/octet-stream', 'name' => 'blob.bin', 'body' => $blob),
	      array('type' => 'image/png', 'name' => 'dot.png', 'body' => $png, 'cid' => 'dot@x'))), $alias, $domain, $address);
$msgs['archive'] = $deliver(mx_raw('Bo <bo@sender.example>', $address, 'Archived ' . $suffix, 'archive-' . $suffix . '@sender.example', 'Kept away'),
	$alias, $domain, $address);
$msgs['sent'] = $deliver(mx_raw($address, 'Cy <cy@elsewhere.example>', 'Sent by me ' . $suffix, 'sent-' . $suffix . '@' . $domain_name, 'I wrote this'),
	$alias, $domain, $address);
$msgs['spam'] = $deliver(mx_raw('Spammer <spam@junk.example>', $address, 'Win ' . $suffix, 'spam-' . $suffix . '@junk.example', 'Buy now'),
	$alias, $domain, $address);
$msgs['trash'] = $deliver(mx_raw('Dee <dee@sender.example>', $address, 'Binned ' . $suffix, 'trash-' . $suffix . '@sender.example', 'Throw away'),
	$alias, $domain, $address);
$msgs['draft'] = $deliver(mx_raw($address, 'Eve <eve@elsewhere.example>', 'Draft ' . $suffix, 'draft-' . $suffix . '@' . $domain_name, 'Unsent'),
	$alias, $domain, $address);
$stored_raw = mx_raw('Fay <fay@sender.example>', $address, 'Stored whole ' . $suffix, 'stored-' . $suffix . '@sender.example', 'The original bytes');
$msgs['stored'] = $deliver($stored_raw, $alias, $domain, $address);
$msgs['sealed_other'] = $deliver(mx_raw('Gus <gus@sender.example>', $address, 'Not mine ' . $suffix, 'other-' . $suffix . '@sender.example', 'Sealed elsewhere'),
	$alias, $domain, $address);

$upd = function (int $id, array $cols) { InboundEmailMessage::updateColumns($id, $cols); };
$upd($msgs['inbox'], array('iem_is_read' => false, 'iem_is_starred' => true));
$upd($msgs['archive'], array('iem_is_archived' => true, 'iem_is_read' => true));
$upd($msgs['sent'], array('iem_direction' => 'outbound', 'iem_is_read' => true));
$upd($msgs['spam'], array('iem_spam_verdict' => 'spam', 'iem_is_read' => true));
$upd($msgs['trash'], array('iem_delete_time' => gmdate('Y-m-d H:i:s'), 'iem_is_read' => true));
$upd($msgs['draft'], array('iem_direction' => 'draft'));
$upd($msgs['stored'], array('iem_is_read' => true));
$db->prepare('UPDATE iem_inbound_email_messages SET iem_raw_message = ? WHERE iem_inbound_email_message_id = ?')
	->execute(array($stored_raw, $msgs['stored']));
$other = make_user('MailExportOther' . $suffix, 5);
$db->prepare('UPDATE iem_inbound_email_messages SET iem_content_sealed = true, iem_sealed_owner_user_id = ?, iem_is_read = true
	WHERE iem_inbound_email_message_id = ?')->execute(array((int)$other->key, $msgs['sealed_other']));

$bills = InboundEmailLabel::findOrCreate((int)$alias->key, 'Bills, 2026');
$family = InboundEmailLabel::findOrCreate((int)$alias->key, 'Family');
InboundLabelMember::apply($msgs['inbox'], (int)$bills->key);
InboundLabelMember::apply($msgs['inbox'], (int)$family->key);
InboundLabelMember::apply($msgs['archive'], (int)$family->key);

$new_run = function (int $alias_id, int $user_id, string $level, ?string $folder = null) use (&$made_runs) {
	$run = new MailExportRun(NULL);
	$run->set('mxr_iea_inbound_email_alias_id', $alias_id);
	$run->set('mxr_usr_user_id', $user_id);
	$run->set('mxr_folder', $folder);
	$run->set('mxr_level', $level);
	$run->set('mxr_state', $level === MailExportRun::LEVEL_FORTRESS ? MailExportRun::STATE_BROWSER : MailExportRun::STATE_QUEUED);
	$run->save();
	$run->load();
	$made_runs[] = (int)$run->key;
	return $run;
};
$build_all = function (MailExportRun $run, float $slice = 30.0, int $max = 500) {
	for ($i = 0; $i < $max; $i++) {
		$r = MailExport::build($run, microtime(true) + $slice);
		$run->load();
		if ($r['finished'] || $r['locked']) {
			return $r;
		}
	}
	return array('finished' => false, 'locked' => false);
};
$folder_bytes = function (MailExportRun $run, ?string $fk = null) {
	$out = array();
	foreach (MailExport::pieces($run) as $f => $paths) {
		$out[$f] = '';
		foreach ($paths as $p) {
			$out[$f] .= substr($p, -5) === '.jsfc' ? SealedFileContainer::openString($p, $fk) : file_get_contents($p);
		}
	}
	return $out;
};
$zip_to_file = function (MailExportRun $run, ?string $fk = null) {
	$path = sys_get_temp_dir() . '/mxt_' . bin2hex(random_bytes(4)) . '.zip';
	$fh = fopen($path, 'wb');
	MailExport::streamZip($run, function ($b) use ($fh) { fwrite($fh, $b); }, $fk);
	fclose($fh);
	harness_defer(function () use ($path) { @unlink($path); });
	return $path;
};

// ── Folders ─────────────────────────────────────────────────────────────────
section('Folders: each message once, drafts left out, a label on its own');

$aid = (int)$alias->key;
$in = function (string $key) use ($db, $aid) {
	return array_map('intval', $db->query('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE '
		. MailExport::folderSql($key, $aid) . ' ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN));
};
check($in('inbox') === array($msgs['inbox'], $msgs['stored'], $msgs['sealed_other']), 'Inbox holds the received, unarchived mail', json_encode($in('inbox')));
check($in('archive') === array($msgs['archive']), 'Archive holds the archived mail');
check($in('sent') === array($msgs['sent']), 'Sent holds what the member sent');
check($in('spam') === array($msgs['spam']), 'Spam holds what was judged spam');
check($in('trash') === array($msgs['trash']), 'Trash holds what was discarded');
$every = array_merge($in('inbox'), $in('archive'), $in('sent'), $in('spam'), $in('trash'));
check(count($every) === count(array_unique($every)) && !in_array($msgs['draft'], $every, true),
	'no message is in two folders, and the draft is in none');
check(MailExport::count($aid, array_keys(MailExport::FOLDERS)) === 7, 'the whole mailbox counts seven messages');
check($in('label:' . (int)$family->key) === array($msgs['inbox'], $msgs['archive']), 'a label folder holds its messages wherever they sit');
check(MailExport::validFolder($aid, 'label:' . (int)$bills->key) && !MailExport::validFolder($aid, 'label:999999999')
	&& !MailExport::validFolder($aid, 'drafts'), 'only this mailbox\'s views and labels are folders');

// ── The build ───────────────────────────────────────────────────────────────
section('The build: slices and pieces make the same mbox as one pass');

$one = $new_run($aid, $uid, MailExportRun::LEVEL_STANDARD);
$r = $build_all($one);
check($r['finished'] && (string)$one->get('mxr_state') === MailExportRun::STATE_READY, 'one long slice finishes the run');
check((int)$one->get('mxr_total') === 7 && (int)$one->get('mxr_done') === 6 && (int)$one->get('mxr_left_out') === 1,
	'six messages written, one left out, of seven', json_encode(array($one->get('mxr_total'), $one->get('mxr_done'), $one->get('mxr_left_out'))));

MailExport::$piece_bytes = 1500;
$many = $new_run($aid, $uid, MailExportRun::LEVEL_STANDARD);
$slices = 0;
for ($i = 0; $i < 400; $i++) {
	$slices++;
	$r = MailExport::build($many, microtime(true) + 0.004);
	$many->load();
	if ($r['finished']) { break; }
}
MailExport::$piece_bytes = 64 * 1024 * 1024;
$pieces = 0;
foreach (MailExport::pieces($many) as $paths) { $pieces += count($paths); }
check($r['finished'] && $slices > 1 && $pieces > 5, 'a run cut into many slices and small pieces finishes',
	$slices . ' slices, ' . $pieces . ' pieces');
check($folder_bytes($many) === $folder_bytes($one), 'and its mbox files are byte for byte the one-pass ones');
check(!glob($many->dir() . '/*.part'), 'no half-written piece is left behind');

// ── Cancel ──────────────────────────────────────────────────────────────────
section('Cancel: a run deleted while it is being built stops quietly');

MailExport::$piece_bytes = 1500;
$gone = $new_run($aid, $uid, MailExportRun::LEVEL_STANDARD);
MailExport::build($gone, microtime(true) + 0.004);
$gone->load();
$stale = new MailExportRun((int)$gone->key, TRUE);
(new MailExportRun((int)$gone->key, TRUE))->permanent_delete(); // the member's Delete, between two slices
$cancelled = false;
try {
	MailExport::build($stale, microtime(true) + 5);
} catch (MailExportCancelled $e) {
	$cancelled = true;
}
MailExport::$piece_bytes = 64 * 1024 * 1024;
check($cancelled, 'the next slice stops with a cancel, not a failure');
check(!is_dir($stale->dir()) && !mx_run_exists((int)$stale->key), 'and leaves no folder and no row behind');

// ── The zip ─────────────────────────────────────────────────────────────────
section('The zip: one mbox per folder, labels and state in each message');

$zip_path = $zip_to_file($one);
$zip = new ZipArchive();
check($zip->open($zip_path) === true, 'the download is a zip PHP opens');
$names = array();
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
check($names === array('Inbox.mbox', 'Archive.mbox', 'Sent.mbox', 'Spam.mbox', 'Trash.mbox', MailExport::NOTE_NAME),
	'Inbox, Archive, Sent, Spam, Trash and the note', json_encode($names));
$inbox = (string)$zip->getFromName('Inbox.mbox');
$note = (string)$zip->getFromName(MailExport::NOTE_NAME);
$zip->close();

$split = function (string $mbox) {
	$h = fopen('php://temp', 'w+b');
	fwrite($h, $mbox);
	$found = array();
	MboxSplitter::split($h, 0, function ($m) use (&$found, $h) {
		$found[] = MboxSplitter::readMessage($h, $m['offset'], $m['length']);
	}, microtime(true) + 30, 1000);
	fclose($h);
	return $found;
};
$inbox_msgs = $split($inbox);
check(count($inbox_msgs) === 2, 'Inbox.mbox holds its two readable messages', (string)count($inbox_msgs));
check(strpos($inbox, "\nFrom ann@sender.example ") !== false || strpos($inbox, 'From ann@sender.example ') === 0,
	'each message starts with a From separator naming its sender');
check(strpos($inbox, "\n>From the start this line") !== false && strpos($inbox, "\n>>From a quoted one too") !== false,
	'a body line starting From (or >From) gets one more >');
$first = $inbox_msgs[0];
check(strpos($first, "From the start this line") !== false, 'and reading it back undoes exactly that');
check(strpos($first, 'X-Gmail-Labels: Inbox,"Bills, 2026",Family,Unread,Starred') === 0,
	'X-Gmail-Labels: the view, the labels (quoted where needed), Unread and Starred', substr($first, 0, 90));
check(strpos($first, "\r\nX-Joinery-Rebuilt: yes\r\n") !== false || strpos($first, "\nX-Joinery-Rebuilt: yes") !== false,
	'a message kept without its original is rebuilt, and says so');
$stored_out = $inbox_msgs[1];
$stored_body = preg_replace('/^X-Gmail-Labels: [^\r\n]*\r?\n/', '', $stored_out);
check(rtrim($stored_body, "\r\n") === rtrim($stored_raw, "\r\n") && strpos($stored_out, 'X-Joinery-Rebuilt') === false,
	'a stored original travels byte for byte, unmarked');
check(strpos($note, '1 message was left out') === 0 && strpos($note, 'Inbox: message ' . $msgs['sealed_other'] . ' (') !== false
	&& strpos($note, 'sealed to another person\'s vault') !== false && strpos($note, 'Not mine') === false,
	'the note names the message left out and why, never a sealed subject', $note);

// ── Round trip ──────────────────────────────────────────────────────────────
section('Round trip: the zip imports back whole');

$before = array();
foreach ($msgs as $k => $id) {
	if ($k === 'draft' || $k === 'sealed_other') { continue; }
	$m = new InboundEmailMessage($id, TRUE);
	$atts = array();
	foreach (new MultiInboundMessageAttachment(array('message_id' => $id), array('ima_inbound_message_attachment_id' => 'ASC')) as $a) {
		$got = mailbox_retrieve_attachment_bytes($a, $m);
		$atts[(string)$a->get('ima_filename')] = md5((string)$got['content']);
	}
	ksort($atts);
	$labels = MailExport::labelNamesFor(array($id))[$id] ?? array();
	sort($labels);
	$before[trim((string)$m->get('iem_message_id_header'), '<>')] = array(
		'subject' => (string)$m->get('iem_subject'),
		'plain' => trim(str_replace("\r\n", "\n", (string)$m->get('iem_body_plain'))),
		'read' => (bool)$m->get('iem_is_read'), 'starred' => (bool)$m->get('iem_is_starred'),
		'view' => MailExport::viewOf($m), 'labels' => $labels, 'atts' => $atts,
	);
}
// The originals go, so the import is into an empty place, not a dedup.
foreach ($msgs as $id) {
	$m = new InboundEmailMessage($id, TRUE);
	if ($m->key) { $m->permanent_delete(); }
}
$restore = $mk_alias('restored', $domain, $uid);
$file = File::createFromBytes(file_get_contents($zip_path), 'export-' . $suffix . '.zip', 'application/zip', User::USER_SYSTEM,
	array('fil_private' => true, 'fil_source' => MailImportService::FILE_SOURCE));
harness_register_model('File', (int)$file->key);
$irun = new MailImportRun(NULL);
$irun->set('mir_iea_inbound_email_alias_id', (int)$restore->key);
$irun->set('mir_usr_user_id', $uid);
$irun->set('mir_fil_file_id', (int)$file->key);
$irun->set('mir_source_name', 'export.zip');
$irun->set('mir_state', MailImportRun::STATE_QUEUED);
$irun->set('mir_own_addresses', $address);
$irun->prepare();
$irun->save();
$irun->load();
harness_register_model('MailImportRun', (int)$irun->key);
$importer = new MailArchiveImporter($irun);
for ($i = 0; $i < 20; $i++) { if (!empty($importer->scanBatch(microtime(true) + 20, 5000)['done'])) { break; } }
$importer->applySelection(array('*'), true, true);
for ($i = 0; $i < 20; $i++) { if (!empty($importer->importBatch(50)['exhausted'])) { break; } }
$irun->load();
check((int)$irun->get('mir_stored') === 6 && (int)$irun->get('mir_failed') === 0,
	'all six exported messages import', json_encode(array($irun->get('mir_stored'), $irun->get('mir_dedup'), $irun->get('mir_failed'), $irun->get('mir_error'))));
$after = array();
foreach ($db->query('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = '
		. (int)$restore->key)->fetchAll(PDO::FETCH_COLUMN) as $id) {
	mx_drop_later('InboundEmailMessage', (int)$id);
	$m = new InboundEmailMessage((int)$id, TRUE);
	$atts = array();
	foreach (new MultiInboundMessageAttachment(array('message_id' => (int)$id), array('ima_inbound_message_attachment_id' => 'ASC')) as $a) {
		$got = mailbox_retrieve_attachment_bytes($a, $m);
		$atts[(string)$a->get('ima_filename')] = md5((string)$got['content']);
	}
	ksort($atts);
	$labels = MailExport::labelNamesFor(array((int)$id))[(int)$id] ?? array();
	sort($labels);
	$after[trim((string)$m->get('iem_message_id_header'), '<>')] = array(
		'subject' => (string)$m->get('iem_subject'),
		'plain' => trim(str_replace("\r\n", "\n", (string)$m->get('iem_body_plain'))),
		'read' => (bool)$m->get('iem_is_read'), 'starred' => (bool)$m->get('iem_is_starred'),
		'view' => MailExport::viewOf($m), 'labels' => $labels, 'atts' => $atts,
	);
}
ksort($before);
ksort($after);
$spam_key = 'spam-' . $suffix . '@junk.example';
$spam_recorded = isset($after[$spam_key]) && $after[$spam_key]['view'] === 'spam';
if (!$spam_recorded && isset($after[$spam_key])) {
	// The import feeds a source's spam verdict through this site's own spam
	// setting, which may record none; the message itself must still be there.
	$after[$spam_key]['view'] = 'spam';
}
foreach ($before as $k => $v) {
	check(isset($after[$k]) && $after[$k] === $v, 'came back the same: ' . $v['subject'],
		json_encode(array('before' => $v, 'after' => $after[$k] ?? null)));
}
check(array_keys($after) === array_keys($before), 'and nothing else came back');

// ── Private ─────────────────────────────────────────────────────────────────
section('Private: built only in the window, sealed at rest');

if (!($has_session && vault_apcu_usable())) {
	harness_skip('Private export', 'needs a session and APCu on this CLI (the build opens a window)');
} else {
	$pv = make_user('MailExportPriv' . $suffix, 5);
	$pid = (int)$pv->key;
	$pkp = vault_fixture_server_vault($pid);
	harness_defer(function () use ($pid) { VaultUnlock::lockAll($pid); });
	$pdomain = new InboundEmailDomain(NULL);
	$pdomain->set('ied_domain', 'priv.' . $domain_name);
	$pdomain->set('ied_owner_usr_user_id', $pid);
	$pdomain->set('ied_is_enabled', true);
	$pdomain->set('ied_is_protected_identity', true);
	$pdomain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$pdomain->save();
	mx_drop_later('InboundEmailDomain', (int)$pdomain->key);
	$palias = $mk_alias('me', $pdomain, $pid);
	$paddr = 'me@priv.' . $domain_name;
	$token = 'privatetoken' . $suffix;
	$p1 = $deliver(mx_raw('Hal <hal@sender.example>', $paddr, 'Secret ' . $token, 'p1-' . $suffix . '@sender.example', 'Body ' . $token),
		$palias, $pdomain, $paddr);
	$p2 = $deliver(mx_raw('Ida <ida@sender.example>', $paddr, 'Other secret ' . $token, 'p2-' . $suffix . '@sender.example', 'More ' . $token),
		$palias, $pdomain, $paddr);
	$row = $db->query('SELECT iem_content_sealed FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ' . $p1)->fetchColumn();
	check(in_array($row, array(true, 't', 1, '1'), true), 'the Private mailbox sealed its mail (precondition)');
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_owner_user_id = ? WHERE iem_inbound_email_message_id = ?')
		->execute(array($uid, $p2)); // sealed to someone else: left out

	$prun = $new_run((int)$palias->key, $pid, MailExportRun::LEVEL_PRIVATE);
	MailExportRun::recordSealedKey((int)$prun->key, UserEncryptionVault::loadForUser($pid, UserEncryptionVault::SCOPE_USER));
	$prun->load();
	$r = MailExport::build($prun, microtime(true) + 30);
	$prun->load();
	check($r['locked'] && !$r['finished'] && !MailExport::pieces($prun) && (string)$prun->get('mxr_state') === MailExportRun::STATE_QUEUED,
		'with the window shut nothing is built, and the run waits');

	vault_fixture_open_window($pid, $pkp['secret']);
	$r = $build_all($prun);
	check($r['finished'] && (int)$prun->get('mxr_done') === 1 && (int)$prun->get('mxr_left_out') === 1,
		'in the window it finishes: one written, one left out', json_encode($r));
	$plain_found = false;
	$all_sealed = true;
	foreach (MailExport::pieces($prun) as $paths) {
		foreach ($paths as $p) {
			$all_sealed = $all_sealed && substr($p, -5) === '.jsfc' && SealedFileContainer::looksSealed($p);
			$plain_found = $plain_found || strpos(file_get_contents($p), $token) !== false;
		}
	}
	check($all_sealed && !$plain_found, 'every piece on disk is sealed, and none holds the mail in the clear');
	check(strpos((string)$prun->get('mxr_left_out_notes'), $token) === false && strpos((string)$prun->get('mxr_left_out_notes'), 'Inbox: message ') === 0,
		'the stored note names the left-out message without its subject', (string)$prun->get('mxr_left_out_notes'));
	$pzip = $zip_to_file($prun, $prun->exportKey());
	$z = new ZipArchive();
	$z->open($pzip);
	$pinbox = (string)$z->getFromName('Inbox.mbox');
	$z->close();
	check(strpos($pinbox, 'Body ' . $token) !== false && strpos($pinbox, 'More ' . $token) === false,
		'opened in the window, the download holds the owner\'s message');
	VaultUnlock::lockAll($pid);
	$threw = false;
	try { $prun->exportKey(); } catch (VaultLockedException $e) { $threw = true; }
	check($threw, 'with the window shut again, the download\'s key does not open');

	// The ready email goes from cron, not from the window's request.
	check($prun->get('mxr_announce_time') === null, 'the build itself tells nobody');
	SealedEgressGuard::reset();
	$queued_before = (int)$db->query('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_usr_user_id = ' . $pid)->fetchColumn();
	MailExport::announceReady();
	$prun->load();
	$queued_after = (int)$db->query('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_usr_user_id = ' . $pid)->fetchColumn();
	check($prun->get('mxr_announce_time') !== null && $queued_after === $queued_before + 1,
		'the task tells its owner once it is ready', $queued_before . ' -> ' . $queued_after);
	MailExport::announceReady();
	check((int)$db->query('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_usr_user_id = ' . $pid)->fetchColumn() === $queued_after,
		'and only once');
}

// ── Fortress ────────────────────────────────────────────────────────────────
section('Fortress: the browser gets ciphertext, the server opens nothing');
// A request of its own: the Private build above opened sealed mail in this process.
SealedEgressGuard::reset();

$fu = make_user('MailExportFort' . $suffix, 5);
$fid = (int)$fu->key;
$fdomain = new InboundEmailDomain(NULL);
$fdomain->set('ied_domain', 'fort.' . $domain_name);
$fdomain->set('ied_owner_usr_user_id', $fid);
$fdomain->set('ied_is_enabled', true);
$fdomain->save();
mx_drop_later('InboundEmailDomain', (int)$fdomain->key);
$falias = $mk_alias('vault', $fdomain, $fid);
$faddr = 'vault@fort.' . $domain_name;
$f_plain = $deliver(mx_raw('Jo <jo@sender.example>', $faddr, 'Not yet moved ' . $suffix, 'f1-' . $suffix . '@sender.example', 'Still readable'),
	$falias, $fdomain, $faddr);
$f_sealed = $deliver(mx_raw('Kim <kim@sender.example>', $faddr, 'Moved ' . $suffix, 'f2-' . $suffix . '@sender.example', 'Device only'),
	$falias, $fdomain, $faddr);
// Mark the second row end-to-end: its key sealed to a browser-held mail key.
$box = new SealedBox();
$browser_kp = $box->generateKeypair();
$browser_public = base64_encode(SealedBox::b64url_decode($browser_kp['public'])); // the browser stores standard base64
vault_fixture_client_vault($fid, $browser_public, 'mail');
$sealed_dek = (new VaultCrypto())->sealItemDekToBrowserKey(random_bytes(32), $browser_public, 'mail');
$db->prepare("UPDATE iem_inbound_email_messages SET iem_sealed_key = ?, iem_content_sealed = true, iem_sealed_owner_user_id = ?,
	iem_subject = 'v1.edge.ciphertext', iem_body_plain = 'v1.edge.ciphertext' WHERE iem_inbound_email_message_id = ?")
	->execute(array($sealed_dek, $fid, $f_sealed));
check(InboundEmailMessage::isBrowserSealed(new InboundEmailMessage($f_sealed, TRUE)), 'the row reads as end-to-end (precondition)');
$frun = $new_run((int)$falias->key, $fid, MailExportRun::LEVEL_FORTRESS);
$page = MailExport::fortressPage($frun, MailboxViewer::forUser($fid, 5), 0, 0);
$by_id = array();
foreach ($page['messages'] as $item) { $by_id[(int)$item['id']] = $item; }
check(isset($by_id[$f_sealed]['message']['sealed']) && $by_id[$f_sealed]['message']['subject'] === ''
	&& $by_id[$f_sealed]['message']['sealed']['iem_subject'] === 'v1.edge.ciphertext',
	'the end-to-end row comes as the thread payload has it: ciphertext, nothing opened');
$server_entry = isset($by_id[$f_plain]['entry']) ? base64_decode($by_id[$f_plain]['entry']) : '';
check(strpos($server_entry, 'Still readable') !== false && strpos($server_entry, 'X-Gmail-Labels: Inbox,Unread') !== false,
	'a row the server can still read comes as its mbox entry');
check(isset($page['bytes']) && $page['folders'][0]['name'] === 'Inbox', 'the first page names the folders and about how big the whole is');

// ── The page and the download, over HTTP ────────────────────────────────────
section('The page and the download, over HTTP');

$jar = harness_jar_new('mxt');
harness_web_login($jar, $owner->get('usr_email'), 'TestPassword_MailExport' . $suffix);
$page_resp = harness_request('GET', '/profile/mailbox/export?mailbox=' . $aid, array('jar' => $jar, 'accept' => 'text/html'));
check($page_resp['status'] === 200 && strpos($page_resp['body'], 'Download a mailbox') !== false
	&& strpos($page_resp['body'], 'mail-export-form') !== false, 'the page renders with its form', (string)$page_resp['status']);
// The zip itself streams from the web server's own storage, which this test
// does not write to (its pieces are in a folder of its own); the walk on dev
// downloads a real one. Here: the endpoint's refusals.
$waiting = $new_run($aid, $uid, MailExportRun::LEVEL_STANDARD);
$dl = harness_request('GET', '/profile/mailbox/export_download?run_id=' . (int)$waiting->key, array('jar' => $jar, 'accept' => 'text/html'));
check(substr((string)$dl['body'], 0, 2) !== 'PK' && strpos((string)$dl['body'], 'not ready yet') !== false,
	'a download not built yet is refused, with why', (string)$dl['status']);
$ojar = harness_jar_new('mxto');
harness_web_login($ojar, $other->get('usr_email'), 'TestPassword_MailExportOther' . $suffix);
$theirs = harness_request('GET', '/profile/mailbox/export_download?run_id=' . (int)$one->key, array('jar' => $ojar, 'accept' => 'text/html'));
check(substr((string)$theirs['body'], 0, 2) !== 'PK' && strpos((string)$theirs['body'], 'not yours') !== false,
	'anyone else is told it is not theirs');

// ── Asking ──────────────────────────────────────────────────────────────────
section('Asking: what the caller may read, behind a fresh second factor');

if (!$has_session || session_id() === '') {
	harness_skip('asking', 'no session could be started on the CLI');
} else {
	$sid = session_id();
	$clear = function () use ($sid) {
		DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?')->execute(array($sid));
	};
	harness_defer($clear);
	$as = function ($user) {
		$_SESSION['usr_user_id'] = (int)$user->key;
		$_SESSION['loggedin'] = true;
		$_SESSION['permission'] = (int)$user->get('usr_permission');
	};
	$as($other);
	$r = mail_export_start_logic(array('alias_id' => $aid));
	check($r->error !== null && strpos((string)$r->error, 'Not authorized') !== false, 'a mailbox the caller holds no grant on is refused');

	$owner->enable_totp('JBSWY3DPEHPK3PXP');
	$owner->save();
	$clear();
	$as($owner);
	$r = mail_export_start_logic(array('alias_id' => $aid));
	check($r->error !== null && !empty($r->data['requires_stepup']), 'without a fresh second factor: refused with requires_stepup');
	SessionControl::get_instance()->stamp_second_factor();
	$r = mail_export_start_logic(array('alias_id' => $aid, 'folder' => 'drafts'));
	check($r->error !== null, 'a folder that is not one of the mailbox\'s is refused');
	$r = mail_export_start_logic(array('alias_id' => $aid, 'folder' => 'archive'));
	check($r->error === null && $r->data['level'] === 'standard' && $r->data['state'] === 'queued', 'confirmed: a Standard build is queued', (string)$r->error);
	$made_runs[] = (int)$r->data['run_id'];
	$again = mail_export_start_logic(array('alias_id' => $aid, 'folder' => 'archive'));
	check($again->error === null && (int)$again->data['run_id'] === (int)$r->data['run_id'], 'asking again returns the same build');

	$list = mail_export_list_logic(array());
	$ids = array_map(function ($e) { return (int)$e['run_id']; }, $list->data['exports']);
	check(in_array((int)$r->data['run_id'], $ids, true), 'the list shows it');

	if (isset($palias)) {
		$super = make_user('MailExportSuper' . $suffix, 10);
		$clear();
		$as($super);
		SessionControl::get_instance()->stamp_second_factor();
		$r2 = mail_export_start_logic(array('alias_id' => (int)$palias->key));
		check($r2->error !== null && strpos((string)$r2->error, 'only its owner') !== false,
			'a protected mailbox is refused to anyone but its owner, a superadmin included', (string)$r2->error);
		$r3 = mail_export_start_logic(array('alias_id' => $aid));
		check($r3->error === null && $r3->data['level'] === 'standard', 'a superadmin may download a Standard mailbox they hold no grant on');
		if ($r3->error === null) { $made_runs[] = (int)$r3->data['run_id']; }
	}

	$as($owner);
	$run = new MailExportRun((int)$r->data['run_id'], TRUE);
	@mkdir($run->dir(), 0755, true);
	file_put_contents($run->dir() . '/00-00000.mbox', 'x');
	$as($other);
	$d = mail_export_delete_logic(array('run_id' => (int)$run->key));
	check($d->error !== null && is_dir($run->dir()), 'another person cannot delete it');
	$as($owner);
	$d = mail_export_delete_logic(array('run_id' => (int)$run->key));
	check($d->error === null && !is_dir($run->dir()) && !mx_run_exists((int)$run->key), 'its owner can, pieces and all');
	$clear();
}

// ── Retention ───────────────────────────────────────────────────────────────
section('Retention: an old download goes, with its pieces');

$old = $new_run($aid, $uid, MailExportRun::LEVEL_STANDARD);
@mkdir($old->dir(), 0755, true);
file_put_contents($old->dir() . '/00-00000.mbox', 'old');
$old->writeColumns(array('mxr_state' => MailExportRun::STATE_READY, 'mxr_finish_time' => gmdate('Y-m-d H:i:s', time() - 9 * 86400)));
$orphan = MailExportRun::rootDir() . '/' . (9000000000 + random_int(1, 999999));
@mkdir($orphan, 0755, true);
file_put_contents($orphan . '/00-00000.mbox', 'orphan');
$fresh_dir = $one->dir();
$res = MailExportRun::purgeExpired(7);
check(!mx_run_exists((int)$old->key) && !is_dir($old->dir()), 'a download past the window is deleted with its pieces');
check(!is_dir($orphan), 'a directory with no run behind it is cleared');
check(is_dir($fresh_dir) && mx_run_exists((int)$one->key), 'a fresh one stays', json_encode($res));

harness_finish();
