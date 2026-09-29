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
 * And the relay pin (specs/client_custody_mail.md § R10, WP8):
 *  - the shared vector (fixtures/relay_pin_vector.json): PHP's pin MAC formula
 *    (MailboxRelayPin::pinMessage, HKDF 'sealed-vault:pin', HMAC-SHA256) gives
 *    the vector's MAC, the statement verifies under the relay identity over the
 *    prefixed bytes and fails when a byte changes, and mailbox_fortress.js
 *    carries the same values for its selfCheck;
 *  - relay_pin_set (setPin) refuses a mailbox that is not the caller's and
 *    anything not shaped like a pin; a first pin and a new MAC over the same
 *    identity change nothing trusted, another identity does (the step-up);
 *  - the proxy (sealTarget) hands the relay's body back byte for byte, with the
 *    stored pin and the relay identity; it answers only for the owner's
 *    Fortress mailbox under Seal at the relay;
 *  - the pins listing is what a rotation re-makes.
 *
 * Run: php tests/run.php test-db --filter=fortress_relay_pull
 *
 * @version 1.3 - carries the relay pin checks (fortress_relay_pin folded in)
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

/** A relay whose API is a stub answering one seal-target body. */
class FortressPinStubRelay extends MailboxRelay {
	public $answer;
	public $asked = array();
	public function withApi(callable $fn) {
		$client = (new ReflectionClass('FortressPinStubClient'))->newInstanceWithoutConstructor();
		$client->relay = $this;
		return $fn($client);
	}
}
class FortressPinStubClient extends RelayClient {
	public $relay;
	public function sealTarget(string $recipient): ?string {
		$this->relay->asked[] = $recipient;
		return $this->relay->answer;
	}
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
	// ------------------------------------------------------- the relay pin
	// (formerly fortress_relay_pin) — its own mailbox and a relay that is never
	// stored, ahead of the sections below that make relay rows.
	section('The shared vector');
	$pin_v = json_decode((string)file_get_contents(__DIR__ . '/fixtures/relay_pin_vector.json'), true);
	$pin_key = hash_hkdf('sha256', hex2bin($pin_v['vault_secret_hex']), 32, 'sealed-vault:pin', '');
	$pin_mac = base64_encode(hash_hmac('sha256', MailboxRelayPin::pinMessage(intval($pin_v['alias_id']), $pin_v['relay_identity_public_key']), $pin_key, true));
	check($pin_mac === $pin_v['pin_mac'], 'the pin MAC formula gives the vector\'s MAC');
	$pin_pub = base64_decode($pin_v['relay_identity_public_key']);
	$pin_sig = base64_decode($pin_v['signature']);
	check(sodium_crypto_sign_verify_detached($pin_sig, "joinery-relay:seal-target:v1\n" . $pin_v['statement'], $pin_pub),
		'the statement verifies under the relay identity over the prefixed bytes');
	check(!sodium_crypto_sign_verify_detached($pin_sig, "joinery-relay:seal-target:v1\n" . str_replace('"map_version":7', '"map_version":8', $pin_v['statement']), $pin_pub),
		'and fails when a byte changes');
	$pin_js = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_fortress.js'));
	check(strpos($pin_js, $pin_v['pin_mac']) !== false && strpos($pin_js, $pin_v['signature']) !== false && strpos($pin_js, $pin_v['vault_secret_hex']) !== false,
		'mailbox_fortress.js selfCheck carries the same vector');

	section('Fixtures');
	$pin_box = new SealedBox();
	$pin_pair = $pin_box->generateKeypair();
	$pin_mail_pub = base64_encode(SealedBox::b64url_decode($pin_pair['public']));
	$pin_owner = make_user('FrnOwner');
	$pin_owner_id = intval($pin_owner->key);
	vault_fixture_client_vault($pin_owner_id, $pin_mail_pub, InboundEmailMessage::SEAL_SCOPE_FORTRESS);
	$pin_stranger = make_user('FrnStranger');

	$pin_domain = new InboundEmailDomain(NULL);
	$pin_domain->set('ied_domain', 'harnesstest-frn-' . bin2hex(random_bytes(4)) . '.example');
	$pin_domain->set('ied_is_enabled', true);
	$pin_domain->set('ied_owner_usr_user_id', $pin_owner_id);
	$pin_domain->save();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($pin_domain->key));
	$pin_domain->set_security_level(InboundEmailDomain::LEVEL_FORTRESS);
	$pin_domain->set('ied_relay_seals_to_owner', true);
	$pin_domain->save();

	$pin_alias = new InboundEmailAlias(NULL);
	$pin_alias->set('iea_ied_inbound_email_domain_id', intval($pin_domain->key));
	$pin_alias->set('iea_alias', 'harnesstest_pin');
	$pin_alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$pin_alias->set('iea_destinations', '');
	$pin_alias->set('iea_is_enabled', true);
	$pin_alias->save();
	$pin_alias_id = intval($pin_alias->key);
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $pin_alias_id);
	InboundEmailMailboxGrant::sync_for_alias($pin_alias_id, array($pin_owner_id));
	harness_defer(function () use ($db, $pin_alias_id) {
		$db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = ?')->execute(array($pin_alias_id));
	});
	check(true, 'a Fortress mailbox under Seal at the relay, one owner with a mail vault');

	$pin_relay = new FortressPinStubRelay(NULL);
	$pin_relay->set('mrl_identity_public_key', $pin_v['relay_identity_public_key']);
	$pin_relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.2')));
	$pin_relay->answer = '{"statement":"{\"a\":1}","signature":"c2ln"}' . "\n";

	$pin_refused = function (callable $pin_fn): bool {
		try { $pin_fn(); return false; } catch (MailboxRelayPinException $e) { return true; }
	};

	section('relay_pin_set');
	$pin_identity = $pin_v['relay_identity_public_key'];
	$pin_a_mac = base64_encode(random_bytes(32));
	check($pin_refused(function () use ($pin_stranger, $pin_alias_id, $pin_identity, $pin_a_mac) {
		MailboxRelayPin::setPin(intval($pin_stranger->key), $pin_alias_id, $pin_identity, $pin_a_mac);
	}), 'a mailbox that is not the caller\'s is refused');
	check($pin_refused(function () use ($pin_owner_id, $pin_alias_id, $pin_a_mac) {
		MailboxRelayPin::setPin($pin_owner_id, $pin_alias_id, base64_encode('short'), $pin_a_mac);
	}) && $pin_refused(function () use ($pin_owner_id, $pin_alias_id, $pin_identity) {
		MailboxRelayPin::setPin($pin_owner_id, $pin_alias_id, $pin_identity, 'not base64 !');
	}), 'something not shaped like a pin is refused');
	check(!MailboxRelayPin::changesIdentity($pin_owner_id, $pin_alias_id, $pin_identity), 'a first pin trusts nothing new (first use)');
	MailboxRelayPin::setPin($pin_owner_id, $pin_alias_id, $pin_identity, $pin_a_mac);
	$pin_stored = json_decode((string)(new InboundEmailAlias($pin_alias_id, TRUE))->get('iea_relay_identity_pin'), true);
	check($pin_stored === array('relay_identity_public_key' => $pin_identity, 'mac' => $pin_a_mac), 'the pin is stored as sent');
	check(!MailboxRelayPin::changesIdentity($pin_owner_id, $pin_alias_id, $pin_identity), 'a new MAC over the same relay needs no step-up (a rotation)');
	$pin_other_identity = base64_encode(random_bytes(32));
	check(MailboxRelayPin::changesIdentity($pin_owner_id, $pin_alias_id, $pin_other_identity), 'another relay identity does');

	section('relay_seal_target: the relay\'s body, unchanged');
	$pin_answer = MailboxRelayPin::sealTarget($pin_owner_id, $pin_alias_id, $pin_relay);
	check($pin_answer['relay_answer'] === $pin_relay->answer, 'the relay\'s body comes back byte for byte');
	check($pin_relay->asked === array('harnesstest_pin@' . $pin_domain->get('ied_domain')), 'asked about this mailbox\'s address');
	check($pin_answer['relay_identity_public_key'] === $pin_identity && $pin_answer['pin'] === $pin_stored, 'with the relay identity and the stored pin');
	check($pin_refused(function () use ($pin_stranger, $pin_alias_id, $pin_relay) {
		MailboxRelayPin::sealTarget(intval($pin_stranger->key), $pin_alias_id, $pin_relay);
	}), 'not for someone else\'s mailbox');
	$pin_relay->answer = null;
	check($pin_refused(function () use ($pin_owner_id, $pin_alias_id, $pin_relay) {
		MailboxRelayPin::sealTarget($pin_owner_id, $pin_alias_id, $pin_relay);
	}), 'a relay that does not know the mailbox is an error, not an empty answer');
	check(count(MailboxRelayPin::mailboxesToCheck($pin_owner_id, $pin_relay)) === 1, 'the owner\'s page checks this mailbox');

	$pin_domain->set('ied_relay_seals_to_owner', false);
	$pin_domain->save();
	$pin_relay->answer = '{}';
	check($pin_refused(function () use ($pin_owner_id, $pin_alias_id, $pin_relay) {
		MailboxRelayPin::sealTarget($pin_owner_id, $pin_alias_id, $pin_relay);
	}) && MailboxRelayPin::mailboxesToCheck($pin_owner_id, $pin_relay) === array(), 'with Seal at the relay off there is nothing to check');

	section('The rotation re-makes every pin');
	check(MailboxRelayPin::pins($pin_owner_id) === array(array('alias_id' => $pin_alias_id, 'relay_identity_public_key' => $pin_identity, 'mac' => $pin_a_mac)),
		'relay_pins lists the owner\'s pin');
	check(MailboxRelayPin::pins(intval($pin_stranger->key)) === array(), 'and nobody else\'s');
	check(VaultUnlock::clientResealsFor('mail')['scripts'] === array('plugins/mailbox/assets/mailbox-reseal.js'),
		'the mail rotation page loads mailbox-reseal.js');

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
