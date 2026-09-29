<?php
/** @joinery-test
 * name: fortress_relay_pull
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * Relay-sealed Fortress mail (specs/client_custody_mail.md § R9, WP7).
 *
 * A relay that fronts a Fortress mailbox seals each message in the browser's
 * format to the owner's mail key. The spool entry here is made in PHP with a
 * keypair only the test holds, in the exact shape the Go sealer writes
 * (edge_seal_test.go pins those bytes to the shared vector):
 *
 *  - the pull stores it PENDING: the relay's DEK is the row's key, the body is
 *    iem_relay_sealed_raw, nothing is readable; a second pull dedups;
 *  - B35: a client entry that is not the browser's format is held, never stored;
 *  - B40/B42: one sealed to a key no vault holding the mailbox has is stored
 *    marked unopenable (generation 0), never handed to a device; one sealed to
 *    another grantee's key is theirs;
 *  - DeferredIngest leaves it alone; MailboxFortressParse::next() hands it out;
 *  - fortress_parse_store (storeParts) accepts fields and a part under that DEK,
 *    answers a different DEK `stale` and writes nothing (B44), refuses another
 *    person, classifies spam from the posted headers, clears pending and stamps
 *    the search time; a second post on a parsed row is a no-op;
 *  - B45, B46: a waiting row is not on the lowering walk, is still counted and
 *    named on the lowering receipt, and parses after the mailbox left Fortress;
 *  - the map names the mail key only under the add-on, to a relay that
 *    reports 3.1 (B35, B38); the add-on with Fortress is refused only for an
 *    older relay;
 *  - a mail rotation does not commit while the relay has not taken the map,
 *    or no pull has drained its listing since it did (B33, B41).
 *
 * Run: php tests/run.php test-db --filter=fortress_relay_pull
 *
 * @version 1.2 - B46: the waiting row is counted after a lowering
 * @version 1.1 - the review of 2026-09-28 (B38, B40-B42, B44, B45)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$pair = $box->generateKeypair();
$pub = base64_encode(SealedBox::b64url_decode($pair['public']));
$stage = sys_get_temp_dir() . '/frp-' . bin2hex(random_bytes(4));
@mkdir($stage, 0700, true);

$file_ids = array();
harness_defer(function () use (&$file_ids, $stage) {
	foreach (array_unique($file_ids) as $fid) {
		try { $f = new File(intval($fid), TRUE); if ($f->key) { $f->permanent_delete(); } } catch (\Throwable $e) {}
	}
	foreach ((array)glob($stage . '/*') as $f) { @unlink($f); }
	@rmdir($stage);
});

$row_of = function (int $id) use ($db): array {
	$q = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
};
$is_true = function ($v): bool { return $v === true || $v === 't' || $v === 1 || $v === '1'; };

/** A spool pair as the Go sealer writes it for key_kind=client. Returns [seal, meta, spool_id, dek]. */
$client_entry = function (string $recipient, string $raw, array $over = array()) use ($box, $pub, $stage): array {
	$spool_id = gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(6));
	$dek = random_bytes(32);
	$meta = array_merge(array(
		'spool_id' => $spool_id, 'recipient' => $recipient, 'envelope_sender' => 'alice@example.com',
		'message_id' => '<' . $spool_id . '@example.com>', 'in_reply_to' => '', 'references' => '',
		'date' => '', 'size' => strlen($raw), 'authentication_results' => array(),
		'key_kind' => 'client', 'public_key' => $pub, 'map_version' => 3,
		'received_utc' => gmdate('Y-m-d\TH:i:s\Z'),
		'sealed_dek' => 'v1.edgeseal.mail.' . $box->sealEdge($dek, $pub),
		'key_scope' => 'mail', 'key_generation' => 1,
	), $over);
	$body = $over['__body'] ?? ('v1.edge.' . $box->aeadEncryptGcm($raw, $dek, 'mail:relay:' . $spool_id));
	unset($meta['__body']);
	file_put_contents($stage . '/' . $spool_id . '.seal', $body);
	file_put_contents($stage . '/' . $spool_id . '.meta', json_encode($meta));
	return array($stage . '/' . $spool_id . '.seal', $stage . '/' . $spool_id . '.meta', $spool_id, $dek);
};

try {
	// ---------------------------------------------------------------- fixtures
	$owner = make_user('FrpOwner');
	$owner_id = intval($owner->key);
	vault_fixture_client_vault($owner_id, $pub, InboundEmailMessage::SEAL_SCOPE_FORTRESS);
	$other = make_user('FrpOther');

	$domain_name = 'harnesstest-frp-' . bin2hex(random_bytes(4)) . '.example';
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', $domain_name);
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_owner_usr_user_id', $owner_id);
	$domain->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));
	$domain->set_security_level(InboundEmailDomain::LEVEL_FORTRESS);
	$domain->set('ied_relay_seals_to_owner', true);
	$domain->save();
	$domain = new InboundEmailDomain(intval($domain->key), TRUE);

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
	$alias->set('iea_alias', 'harnesstest_box');
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias = new InboundEmailAlias(intval($alias->key), TRUE);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), array($owner_id));
	harness_defer(function () use ($db, $alias) {
		$db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = ?')
			->execute(array(intval($alias->key)));
		$db->prepare('DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN
			(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?)')
			->execute(array(intval($alias->key)));
		$db->prepare('DELETE FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?')
			->execute(array(intval($alias->key)));
	});
	$address = 'harnesstest_box@' . $domain_name;

	$consumer = new RelaySpoolConsumer(new MailboxRelay(NULL));
	$ingest = new ReflectionMethod(RelaySpoolConsumer::class, 'ingestOne');
	$pull = function (array $entry) use ($consumer, $ingest): string {
		return (string)$ingest->invoke($consumer, $entry[0], $entry[1], $entry[2]);
	};

	$pdf = '%PDF-1.4 relay report ' . bin2hex(random_bytes(8));
	$raw = "From: Alice <alice@example.com>\r\nTo: " . $address . "\r\nSubject: Relay sealed\r\n"
		. "X-Spam: yes\r\nX-Spam-Score: 9.5\r\nMIME-Version: 1.0\r\n"
		. "Content-Type: multipart/mixed; boundary=b1\r\n\r\n--b1\r\nContent-Type: text/plain\r\n\r\n"
		. "The body only the owner reads.\r\n--b1\r\nContent-Type: application/pdf; name=r.pdf\r\n"
		. "Content-Transfer-Encoding: base64\r\n\r\n" . base64_encode($pdf) . "\r\n--b1--\r\n";

	// ---------------------------------------------------------------- the pull
	section('The pull stores a client entry pending, under the relay\'s key');
	$entry = $client_entry($address, $raw);
	check($pull($entry) === 'pending', 'a client entry is stored pending');
	$q = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_relay_spool_id = ?');
	$q->execute(array($entry[2]));
	$id = intval($q->fetchColumn());
	$row = $row_of($id);
	$meta = json_decode(file_get_contents($entry[1]), true);
	check($id > 0 && $is_true($row['iem_pending_parse']) && $is_true($row['iem_content_sealed']),
		'the row is pending and marked sealed');
	check($row['iem_sealed_key'] === $meta['sealed_dek'] && intval($row['iem_key_generation']) === 1
		&& intval($row['iem_sealed_owner_user_id']) === $owner_id, 'its key is the relay\'s DEK, generation 1, the owner\'s');
	check($row['iem_relay_sealed_raw'] === trim(file_get_contents($entry[0])), 'the body is the relay\'s ciphertext');
	$plain_cols = array();
	foreach (InboundEmailMessage::$sealed_fields as $col) {
		if ($col !== 'iem_recipient' && (string)($row[$col] ?? '') !== '') { $plain_cols[] = $col; }
	}
	check($plain_cols === array(), 'no content column holds anything yet', implode(',', $plain_cols));
	check($pull($entry) === 'dedup', 'a second pull of the same entry dedups');

	section('B35: what is not the browser\'s format is held');
	$count_rows = function () use ($db, $alias): int {
		return intval($db->query('SELECT count(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ' . intval($alias->key))->fetchColumn());
	};
	$before = $count_rows();
	check($pull($client_entry($address, $raw, array('__body' => 'v1.seal.' . str_repeat('A', 80)))) === 'hold',
		'a server-format body under a client label is held');
	check($pull($client_entry($address, $raw, array('sealed_dek' => 'v1.edgeseal.user.' . $box->sealEdge(random_bytes(32), $pub)))) === 'hold',
		'a DEK framed for another scope is held');
	check($count_rows() === $before, 'and neither stored a row');

	section('B40, B42: a key nobody holding the mailbox has is stored, marked; another grantee\'s is theirs');
	$by_spool = function (string $spool_id) use ($db): array {
		$q = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_relay_spool_id = ?');
		$q->execute(array($spool_id));
		return $q->fetch(PDO::FETCH_ASSOC) ?: array();
	};
	$stranger = base64_encode(SealedBox::b64url_decode($box->generateKeypair()['public']));
	$e = $client_entry($address, $raw, array('public_key' => $stranger));
	check($pull($e) === 'pending', 'a key no vault has: stored (and acked), not held on the relay');
	$r = $by_spool($e[2]);
	check(intval($r['iem_key_generation']) === InboundEmailMessage::RELAY_UNOPENABLE_GENERATION
		&& intval($r['iem_sealed_owner_user_id']) === $owner_id, 'under the owner, marked unopenable (generation 0)');
	$unopenable_id = intval($r['iem_inbound_email_message_id']);

	$other_pair = $box->generateKeypair();
	$other_pub = base64_encode(SealedBox::b64url_decode($other_pair['public']));
	vault_fixture_client_vault(intval($other->key), $other_pub, InboundEmailMessage::SEAL_SCOPE_FORTRESS);
	$e = $client_entry($address, $raw, array('public_key' => $other_pub));
	check($pull($e) === 'pending' && intval($by_spool($e[2])['iem_key_generation']) === 0,
		'a key whose vault\'s owner does not hold the mailbox is unopenable too, not theirs');
	$db->prepare('INSERT INTO ieg_inbound_email_mailbox_grants (ieg_iea_inbound_email_alias_id, ieg_usr_user_id) VALUES (?, ?)')
		->execute(array(intval($alias->key), intval($other->key)));
	$e = $client_entry($address, $raw, array('public_key' => $other_pub));
	check($pull($e) === 'pending', 'shared with them, it is stored');
	$r = $by_spool($e[2]);
	check(intval($r['iem_sealed_owner_user_id']) === intval($other->key) && intval($r['iem_key_generation']) === 1,
		'as theirs, at their generation');
	$db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = ? AND ieg_usr_user_id = ?')
		->execute(array(intval($alias->key), intval($other->key)));
	$db->prepare('DELETE FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?')
		->execute(array(intval($r['iem_inbound_email_message_id'])));

	section('Only the owner\'s browser parses it');
	check(!DeferredIngest::hasWork($owner_id), 'DeferredIngest has no work in it');
	check(MailboxFortressParse::pendingCount($owner_id) === 1, 'one message waits for the owner\'s browser (the unopenable ones do not)');
	$next = MailboxFortressParse::next($owner_id);
	check($next['remaining'] === 1 && $next['item']['id'] === $id && $next['item']['raw_ad'] === 'mail:relay:' . $entry[2],
		'next() hands out the row with its AD');
	check(MailboxFortressParse::next($owner_id, array($id))['item'] === null, 'a row this device skipped is not handed out again');

	// The browser's half, with the test's secret.
	$dek = $box->openEdge(substr($next['item']['sealed_dek'], strlen('v1.edgeseal.mail.')), $pair['secret'], $pub);
	check($dek === $entry[3], 'the DEK opens with the owner\'s secret');
	$opened = $box->aeadDecryptGcm(substr($next['item']['sealed_raw'], 8), $dek, $next['item']['raw_ad']);
	check($opened === $raw, 'and the body opens to the message');

	$prefix = $next['item']['sealed_ad_prefix'] . $id;
	$seal = function (string $v, string $col) use ($box, $dek, $prefix): string {
		return 'v1.edge.' . $box->aeadEncryptGcm($v, $dek, $prefix . ':' . $col);
	};
	$search = InboundEmailMessage::searchTextFor(array('sender' => 'Alice <alice@example.com>', 'subject' => 'Relay sealed',
		'filenames' => array('r.pdf'), 'body_plain' => 'The body only the owner reads.'));
	$fields = array(
		'iem_sender' => $seal('Alice <alice@example.com>', 'iem_sender'),
		'iem_subject' => $seal('Relay sealed', 'iem_subject'),
		'iem_body_plain' => $seal('The body only the owner reads.', 'iem_body_plain'),
		'iem_body_html' => '',
		'iem_snippet' => $seal('The body only the owner reads.', 'iem_snippet'),
		'iem_search_text' => $seal($search, 'iem_search_text'),
		'iem_attachment_manifest' => $seal(json_encode(array(array('mime_part' => '2', 'filename' => 'r.pdf',
			'content_type' => 'application/pdf', 'content_id' => '', 'inline' => false, 'size' => strlen($pdf)))), 'iem_attachment_manifest'),
	);
	$part = array('mime_part' => '2', 'size' => strlen($pdf), 'inline' => false,
		'bytes' => 'v1.edge.' . $box->aeadEncryptGcm($pdf, $dek, $prefix . ':att:2'));
	$params = array('id' => $id, 'sealed_dek' => $next['item']['sealed_dek'], 'fields' => $fields,
		'spam_headers' => array('x_spam' => 'yes', 'x_spam_score' => '9.5'));

	$refused = function (callable $fn): bool {
		try { $fn(); return false; } catch (MailboxFortressParseException $e) { return true; }
	};
	check(MailboxFortressParse::storeParts($owner_id, array_merge($params,
			array('sealed_dek' => 'v1.edgeseal.mail.' . $box->sealEdge(random_bytes(32), $pub))), array($part))
		=== array('id' => $id, 'stored' => false, 'stale' => true), 'fields under another key are answered stale (B44)');
	check($refused(function () use ($owner_id, $params, $unopenable_id) {
		MailboxFortressParse::storeParts($owner_id, array_merge($params, array('id' => $unopenable_id)), array());
	}), 'an unopenable row takes no parse');
	check($refused(function () use ($other, $params, $part) {
		MailboxFortressParse::storeParts(intval($other->key), $params, array($part));
	}), 'another person\'s post is refused');
	check($refused(function () use ($owner_id, $params, $part, $raw) {
		MailboxFortressParse::storeParts($owner_id, $params, array(array_merge($part, array('size' => strlen($raw) + 1,
			'bytes' => 'v1.edge.' . str_repeat('A', 8 + 4 * intval(ceil((strlen($raw) + 29) / 3)) - 8)))));
	}), 'parts larger than the message they came out of are refused');
	check($refused(function () use ($owner_id, $params) {
		MailboxFortressParse::storeParts($owner_id, array_merge($params,
			array('fields' => array('iem_subject' => 'plain words'))), array());
	}), 'a plaintext field is refused');
	check($is_true($row_of($id)['iem_pending_parse']), 'after every refusal the row is still pending');

	section('B45: a waiting row is not on the lowering walk, and parses after the mailbox left Fortress');
	$domain->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
	$domain->save();
	InboundEmailMessage::forgetSealScopes();
	check(MailboxFortressLevel::loweringBacklogCount($owner_id, intval($domain->key)) === 0,
		'the lowering count leaves the waiting row out');
	check(InboundEmailMessage::browserCustodyPage($owner_id, 'mail', 0, 50)['rows'] === array(),
		'and the lowering walk does not list it');
	check(MailboxFortressParse::pendingCount($owner_id, intval($domain->key)) === 1,
		'but it is still counted as waiting to be opened, on this domain (B46)');
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
	$receipt = mailbox_fortress_receipt_render($domain, array('is_fortress' => false, 'lower_backlog' => 0, 'waiting' => 1));
	check(strpos($receipt, 'waiting to be opened on your device') !== false && strpos($receipt, 'Every message is back') === false,
		'and the lowering receipt says so instead of reading done');

	section('fortress_parse_store stores the parse once');
	$files_before = intval($db->query('SELECT count(*) FROM fil_files')->fetchColumn());
	$stored = MailboxFortressParse::storeParts($owner_id, $params, array($part));
	check($stored === array('id' => $id, 'stored' => true), 'the owner\'s post is stored');
	$row = $row_of($id);
	check(!$is_true($row['iem_pending_parse']) && $row['iem_relay_sealed_raw'] === null, 'pending cleared, the relay body dropped');
	check($row['iem_sealed_key'] === $meta['sealed_dek'], 'the key is unchanged');
	check($box->aeadDecryptGcm(substr($row['iem_subject'], 8), $dek, $prefix . ':iem_subject') === 'Relay sealed'
		&& $box->aeadDecryptGcm(substr($row['iem_body_plain'], 8), $dek, $prefix . ':iem_body_plain') === 'The body only the owner reads.',
		'the fields open under the row\'s DEK');
	check($row['iem_search_written_time'] !== null, 'the search time is stamped with the search text');
	$spam_on = (bool)Globalvars::get_instance()->get_setting('mailbox_spam_filtering_enabled');
	check($spam_on ? $row['iem_spam_verdict'] === InboundEmailMessage::SPAM_VERDICT_SPAM : $row['iem_spam_verdict'] === null,
		'the posted X-Spam header decides the verdict', 'filtering ' . ($spam_on ? 'on' : 'off') . ', verdict ' . var_export($row['iem_spam_verdict'], true));
	check(abs(floatval($row['iem_spam_score']) - 9.5) < 0.001 || !$spam_on, 'and its score is kept');
	$a = $db->prepare('SELECT * FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ?');
	$a->execute(array($id));
	$atts = $a->fetchAll(PDO::FETCH_ASSOC);
	check(count($atts) === 1 && $atts[0]['ima_mime_part'] === '2' && $atts[0]['ima_filename'] === ''
		&& $is_true($atts[0]['ima_is_sealed']), 'one sealed attachment row, nothing readable on it');
	if ($atts) {
		$file_ids[] = intval($atts[0]['ima_fil_file_id']);
		$f = new File(intval($atts[0]['ima_fil_file_id']), TRUE);
		check($box->aeadDecryptGcm(substr($f->read_bytes(), 8), $dek, $prefix . ':att:2') === $pdf,
			'the part\'s File opens to the attachment under the part\'s AD');
	}
	check(MailboxFortressParse::storeParts($owner_id, $params, array()) === array('id' => $id, 'stored' => false),
		'a second post on a parsed row changes nothing');
	check(MailboxFortressParse::pendingCount($owner_id) === 0, 'nothing waits any more');
	check(intval($db->query('SELECT count(*) FROM fil_files')->fetchColumn()) === $files_before + 1,
		'one File for the one part, none left over');
	check(MailboxFortressLevel::loweringBacklogCount($owner_id, intval($domain->key)) === 1,
		'parsed, it joins the lowering walk');
	$domain->set_security_level(InboundEmailDomain::LEVEL_FORTRESS);
	$domain->save();
	$domain = new InboundEmailDomain(intval($domain->key), TRUE);
	InboundEmailMessage::forgetSealScopes();

	// ---------------------------------------------------------------- the map
	section('B35: the map names the mail key only to a relay that can seal for a browser');
	$relay_at = function (string $version): MailboxRelay {
		$r = new MailboxRelay(NULL);
		$r->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => $version)));
		return $r;
	};
	check(!RelayVersion::sealsForBrowsers($relay_at('3.1')) && RelayVersion::sealsForBrowsers($relay_at('3.2'))
		&& RelayVersion::sealsForBrowsers($relay_at('3.10')) && !RelayVersion::sealsForBrowsers($relay_at('')),
		'3.2 and later can; 3.1 and a relay that has not said cannot');
	$exporter = (new ReflectionClass('RelayMapExporter'))->newInstanceWithoutConstructor();
	$relay_prop = new ReflectionProperty('RelayMapExporter', 'relay');
	$target = new ReflectionMethod('RelayMapExporter', 'clientSealTarget');
	$alias = new InboundEmailAlias(intval($alias->key), TRUE);
	$relay_prop->setValue($exporter, $relay_at('3.2'));
	check($target->invoke($exporter, $alias, $domain) === array($pub, 1), 'a 3.2 relay is given the mail key and its generation');
	$relay_prop->setValue($exporter, $relay_at('3.1'));
	check($target->invoke($exporter, $alias, $domain) === null, 'a 3.1 relay is not');
	$relay_prop->setValue($exporter, $relay_at('3.2'));
	$off = new InboundEmailDomain(intval($domain->key), TRUE);
	$off->set('ied_relay_seals_to_owner', false);
	check($target->invoke($exporter, $alias, $off) === null,
		'with Seal at the relay off, Fortress mail takes the transport key and is sealed on arrival (B38)');

	// ---------------------------------------------------------------- B33
	section('B33: a mail rotation waits for the relay to switch keys');
	$relay = new MailboxRelay(NULL);
	$relay->set('mrl_name', 'harnesstest relay');
	$relay->set('mrl_is_enabled', true);
	$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.2')));
	$relay->save();
	harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', intval($relay->key));
	$active = MailboxRelay::active();
	if ($active === null || intval($active->key) !== intval($relay->key)) {
		harness_skip('another relay row is the active one here');
	} else {
		require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_domains_logic.php'));
		$relay_refusal = function () use ($domain, $owner_id) {
			return (string)admin_mailbox_domains_fortress_refusal($domain, $owner_id, true);
		};
		check(strpos($relay_refusal(), 'older version') === false, 'the add-on with Fortress is allowed on a 3.2 relay');
		check(VaultUnlock::clientRotationCommitRefusal($owner_id, 'mail') !== null, 'a relay that never took this map refuses the commit');
		$hash = RelayMapSync::contentHash((new RelayMapExporter(new MailboxRelay(intval($relay->key), TRUE)))->build());
		$relay = new MailboxRelay(intval($relay->key), TRUE);
		$relay->set('mrl_map_content_hash', $hash);
		$relay->set('mrl_last_push_time', gmdate('Y-m-d H:i:s', time() - 60));
		$relay->set('mrl_last_pull_time', gmdate('Y-m-d H:i:s'));
		$relay->set('mrl_last_pull_drained_time', gmdate('Y-m-d H:i:s', time() - 120));
		$relay->save();
		check(VaultUnlock::clientRotationCommitRefusal($owner_id, 'mail') !== null,
			'a pull since the push that did not drain the listing is not enough (B41)');
		$relay->set('mrl_last_pull_drained_time', gmdate('Y-m-d H:i:s'));
		$relay->save();
		check(VaultUnlock::clientRotationCommitRefusal($owner_id, 'mail') === null, 'map taken and a drained pull since: the commit may go');
		$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.1')));
		$relay->set('mrl_map_content_hash', 'stale');
		$relay->save();
		check(strpos($relay_refusal(), 'older version') !== false, 'on a 3.1 relay the add-on with Fortress is refused');
		check(VaultUnlock::clientRotationCommitRefusal($owner_id, 'mail') === null,
			'a relay that seals Fortress mail to the transport key never holds a commit up');
	}
} catch (\Throwable $e) {
	check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
