<?php
/** @joinery-test
 * name: address_list_backfill
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * AddressListBackfill: rows stored before iem_to / iem_cc existed get their
 * To / Cc back from whatever source they still have.
 *
 *  - An unsealed stored-raw row is filled in plaintext from its raw.
 *  - A raw with neither header records '' in both columns, so the row leaves
 *    the candidate set and the reader stops deriving.
 *  - A 'remote' row is filled from a header-only IMAP fetch (stubbed); one
 *    ingestor per account for the batch, closed when the drain ends.
 *  - A remote row whose source no longer has the message stays unfilled and
 *    is stamped, so it is not retried every heartbeat.
 *  - A SEALED remote row: with no unlock window the drain stops, unstamps the
 *    row and fills nothing; in-window it seals both lists under the row's own
 *    DEK (ciphertext at rest, opens to the canonical form, wrapping untouched).
 *  - Rows that already have a retained header block, or already have lists,
 *    are not candidates.
 *
 * Run: php tests/run.php db --filter=address_list_backfill
 *
 * @version 1.3
 * @changelog 1.3 - the archive arm: linked rows fill from a kept archive; a discarded one is no source
 * @changelog 1.2 - progress() moves rows from waiting to filled
 * @changelog 1.1 - the stub answers the batched fetchHeaderTexts(); pins one call per chunk
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in CLI (apc.enable_cli=0) — the in-window half cannot be exercised');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('no session available for the unlock window');
	harness_finish();
}

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AddressListBackfill.php'));

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$crypto = new VaultCrypto();
$router = new InboundEmailRouter();
$suffix = bin2hex(random_bytes(4));

$CC_CANON = '"Ford, Tom" <tford@akamai.example>, "Beltran, Luis" <lubeltra@akamai.example>';

/** ImapIngestor that answers header fetches from a canned map instead of a server. */
class StubHeaderIngestor extends ImapIngestor {
	public static $headers_by_uid = array();
	public static $fetches = 0;   // batch calls, not rows: the drain must ask once per chunk
	public static $locators = 0;  // rows asked for across those calls
	public static $closed = 0;
	public function fetchHeaderTexts(string $folder, array $locators): array {
		self::$fetches++;
		self::$locators += count($locators);
		$out = array();
		foreach ($locators as $k => $loc) {
			$uid = intval($loc['uid']);
			$out[$k] = isset(self::$headers_by_uid[$uid])
				? array('ok' => true, 'headers' => self::$headers_by_uid[$uid])
				: array('ok' => false, 'message' => 'gone');
		}
		return $out;
	}
	public function close(): void { self::$closed++; }
}
AddressListBackfill::$ingestor_factory = function (InboundImapAccount $account) {
	return new StubHeaderIngestor($account);
};

// ---- Fixtures --------------------------------------------------------------
$user = make_user('AddrBackfill');
$uid = (int)$user->key;
$kp = $box->generateKeypair();

$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $uid);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

$plain_domain = new InboundEmailDomain(NULL);
$plain_domain->set('ied_domain', 'albf-p-' . $suffix . '.example');
$plain_domain->set('ied_owner_usr_user_id', $uid);
$plain_domain->set('ied_is_enabled', true);
$plain_domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$plain_domain->key);

$sealed_domain = new InboundEmailDomain(NULL);
$sealed_domain->set('ied_domain', 'albf-s-' . $suffix . '.example');
$sealed_domain->set('ied_owner_usr_user_id', $uid);
$sealed_domain->set('ied_is_protected_identity', true);
$sealed_domain->set('ied_security_level', 'private');
$sealed_domain->set('ied_is_enabled', true);
$sealed_domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$sealed_domain->key);

$mk_alias = function (InboundEmailDomain $d) use ($uid) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', (int)$d->key);
	$a->set('iea_alias', 'inbox');
	$a->set('iea_delivery_mode', 'store');
	$a->set('iea_is_enabled', true);
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', (int)$a->key);
	$g->set('ieg_usr_user_id', $uid);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
	return $a;
};
$plain_alias = $mk_alias($plain_domain);
$sealed_alias = $mk_alias($sealed_domain);
$plain_addr = 'inbox@' . $plain_domain->get('ied_domain');
$sealed_addr = 'inbox@' . $sealed_domain->get('ied_domain');

$account = new InboundImapAccount(NULL);
$account->set('iia_label', 'AddrBackfill');
$account->set('iia_provider_key', 'imap_generic');
$account->set('iia_imap_host', 'imap.test');
$account->set('iia_iea_inbound_email_alias_id', (int)$plain_alias->key);
$account->set('iia_username', $plain_addr);
$account->set('iia_is_enabled', true);
$account->prepare();
$account->save();
harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$account->key);

$raw_email = function (string $token, string $recipient, bool $with_lists = true) {
	$lines = array(
		'From: "Hartleb, Zak" <zhartleb@akamai.example>',
	);
	if ($with_lists) {
		$lines[] = 'To: "' . $recipient . '" <' . $recipient . '>';
		$lines[] = 'CC: "Ford, Tom" <tford@akamai.example>, "Beltran, Luis" <lubeltra@akamai.example>';
	}
	$lines = array_merge($lines, array(
		'Subject: Backfill ' . $token,
		'Message-ID: <' . $token . '@example.com>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'',
		'Body of ' . $token . '.',
		'',
	));
	return implode("\r\n", $lines);
};

/** A pre-columns plaintext row holding its raw inline, owned by $uid. */
$legacy_raw_row = function (string $token, string $raw) use ($plain_domain, $plain_alias, $plain_addr, $uid, $db) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$plain_domain->key);
	$m->set('iem_iea_inbound_email_alias_id', (int)$plain_alias->key);
	$m->set('iem_recipient', $plain_addr);
	$m->set('iem_sender', 'zhartleb@akamai.example');
	$m->set('iem_subject', 'Backfill ' . $token);
	$m->set('iem_body_plain', 'Body of ' . $token . '.');
	$m->set('iem_message_id_header', '<' . $token . '@example.com>');
	$m->set('iem_thread_key', '<' . $token . '@example.com>');
	$m->set('iem_raw_message', $raw);
	$m->set('iem_raw_storage_driver', 'inline');
	// No owner recorded, as a Standard mailbox's rows have none: the grant qualifies it.
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};
$row = function (int $id) use ($db) {
	$stmt = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$stmt->execute(array($id));
	return $stmt->fetch(PDO::FETCH_ASSOC);
};
$candidate_ids = function () use ($db, $uid) {
	// Which of this owner's rows the drain would pick, via hasWork's own predicate.
	$ref = new ReflectionMethod('AddressListBackfill', 'candidateWhere');
	$ref->setAccessible(true);
	$stmt = $db->prepare('SELECT m.iem_inbound_email_message_id FROM iem_inbound_email_messages m WHERE '
		. $ref->invoke(null) . ' ORDER BY 1');
	$stmt->execute(array($uid, $uid));
	return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

// ---- Unsealed stored-raw rows ---------------------------------------------
section('Unsealed stored-raw rows fill in plaintext from their raw');

$m_raw = $legacy_raw_row('albf-raw-' . $suffix, $raw_email('albf-raw-' . $suffix, $plain_addr));
$m_none = $legacy_raw_row('albf-none-' . $suffix, $raw_email('albf-none-' . $suffix, $plain_addr, false));
// A row that already has its lists, and one with a retained header block: not candidates.
$m_has = $legacy_raw_row('albf-has-' . $suffix, $raw_email('albf-has-' . $suffix, $plain_addr));
InboundEmailMessage::updateColumns($m_has, array('iem_to' => $plain_addr));
$m_hdr = $legacy_raw_row('albf-hdr-' . $suffix, $raw_email('albf-hdr-' . $suffix, $plain_addr));
InboundEmailMessage::updateColumns($m_hdr, array('iem_raw_headers' => 'To: x@y.example'));

check(AddressListBackfill::hasWork($uid), 'hasWork sees rows without lists that still hold a raw');
check($candidate_ids() === array($m_raw, $m_none),
	'a row with lists and a row with a retained header block are not candidates', json_encode($candidate_ids()));

$p_before = AddressListBackfill::progress();
$done = AddressListBackfill::drainForUser($uid, vault_fixture_dummy_key());
check($done === 2, 'both stored-raw rows were filled (got ' . $done . ')');
$p_after = AddressListBackfill::progress();
check(($p_before['waiting'][''] ?? 0) - ($p_after['waiting'][''] ?? 0) === 2
	&& $p_after['filled'] - $p_before['filled'] === 2,
	'progress(): the two rows moved from waiting (unsealed, key \'\') to filled',
	json_encode(array($p_before, $p_after)));
$r = $row($m_raw);
check($r['iem_to'] === $plain_addr && $r['iem_cc'] === $CC_CANON,
	'the row with headers holds both lists in plaintext, canonical form', json_encode(array($r['iem_to'], $r['iem_cc'])));
$r = $row($m_none);
check($r['iem_to'] === '' && $r['iem_cc'] === '',
	"a raw with neither header records '' in both columns");
check(!AddressListBackfill::hasWork($uid), '…and nothing is left to do');

$svc = new MailboxService(MailboxViewer::forUser($uid, 5));
$thread = $svc->getThread((int)$plain_alias->key, (string)(new InboundEmailMessage($m_none, TRUE))->get('iem_thread_key'));
check(count($thread) === 1 && $thread[0]['to'] === '' && $thread[0]['cc'] === '',
	"the reader shows '' for a captured-empty row and does not re-derive");

// ---- Remote rows -----------------------------------------------------------
section('Remote rows fill from a header-only IMAP fetch; a gone message is stamped');

$mk_remote = function (string $token, int $imap_uid, InboundEmailDomain $d, InboundEmailAlias $a, string $addr)
		use ($router, $account, $db, $raw_email) {
	$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
	$res = $router->storeExtracted(array(
		'sender'            => 'zhartleb@akamai.example',
		'subject'           => 'Backfill ' . $token,
		'body_plain'        => 'Body of ' . $token . '.',
		'body_html'         => '',
		'message_id_header' => '<' . $token . '@example.com>',
		'headers'           => array(), // no To/Cc known at store time
		'size_bytes'        => 100,
		'imap_account_id'   => (int)$account->key,
		'imap_uid'          => $imap_uid,
		'imap_uidvalidity'  => 1,
		'imap_folder'       => 'INBOX',
		'received_time'     => gmdate('Y-m-d H:i:s'),
	), $a, $d, $addr, $auth);
	$id = (int)$res['message']->key;
	harness_register_model('InboundEmailMessage', $id);
	// storeExtracted with no headers wrote NULL lists — exactly the pre-columns shape.
	return $id;
};

$m_remote = $mk_remote('albf-remote-' . $suffix, 101, $plain_domain, $plain_alias, $plain_addr);
$m_gone = $mk_remote('albf-gone-' . $suffix, 102, $plain_domain, $plain_alias, $plain_addr);
StubHeaderIngestor::$headers_by_uid = array(101 => $router->rawHeaderBlock($raw_email('albf-remote-' . $suffix, $plain_addr)));
StubHeaderIngestor::$fetches = 0;
StubHeaderIngestor::$locators = 0;
StubHeaderIngestor::$closed = 0;

check($candidate_ids() === array($m_remote, $m_gone), 'both remote rows are candidates', json_encode($candidate_ids()));
$done = AddressListBackfill::drainForUser($uid, vault_fixture_dummy_key());
check($done === 1, 'exactly the resolvable remote row was filled (got ' . $done . ')');
check(StubHeaderIngestor::$fetches === 1 && StubHeaderIngestor::$locators === 2 && StubHeaderIngestor::$closed === 1,
	'one ingestor answered both rows in ONE batched fetch and was closed once',
	StubHeaderIngestor::$fetches . ' fetches / ' . StubHeaderIngestor::$locators . ' rows / ' . StubHeaderIngestor::$closed . ' closed');
$r = $row($m_remote);
check($r['iem_to'] === $plain_addr && $r['iem_cc'] === $CC_CANON, 'the remote row holds both lists');
$r = $row($m_gone);
check($r['iem_to'] === null && $r['iem_cc'] === null && $r['iem_lists_attempt_time'] !== null,
	'the gone row stays unfilled and is stamped');
check(!AddressListBackfill::hasWork($uid), '…and the stamp holds it out of the predicate — no per-heartbeat retry');

// ---- Archive rows (§ 5b) ---------------------------------------------------
section('A lean row linked from an import entry fills from the run\'s archive; a discarded archive is no source');

// Two lean rows: no raw, no headers, no locator — the import-before-columns shape.
$lean_row = function (string $token) use ($plain_domain, $plain_alias, $plain_addr) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$plain_domain->key);
	$m->set('iem_iea_inbound_email_alias_id', (int)$plain_alias->key);
	$m->set('iem_recipient', $plain_addr);
	$m->set('iem_sender', 'zhartleb@akamai.example');
	$m->set('iem_subject', 'Backfill ' . $token);
	$m->set('iem_body_plain', 'Body of ' . $token . '.');
	$m->set('iem_message_id_header', '<' . $token . '@example.com>');
	$m->set('iem_thread_key', '<' . $token . '@example.com>');
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};
$m_arc1 = $lean_row('albf-arc1-' . $suffix);
$m_arc2 = $lean_row('albf-arc2-' . $suffix);
check(!AddressListBackfill::hasWork($uid), 'lean rows with no archive behind them are not candidates');

// The archive: an mbox of exactly those two messages, kept by a finished run.
$mbox = "From zhartleb@akamai.example Mon Sep 15 12:00:00 2026\r\n" . $raw_email('albf-arc1-' . $suffix, $plain_addr)
	. "\r\nFrom zhartleb@akamai.example Mon Sep 15 12:01:00 2026\r\n" . $raw_email('albf-arc2-' . $suffix, $plain_addr) . "\r\n";
$file = File::createFromBytes($mbox, 'albf-' . $suffix . '.mbox', 'application/octet-stream', $uid,
	array('fil_private' => true, 'fil_source' => File::SOURCE_MAIL_IMPORT_ARCHIVE));
harness_defer(function () use ($file) { try { $file->permanent_delete(); } catch (\Throwable $e) {} });
$run = new MailImportRun(NULL);
$run->set('mir_iea_inbound_email_alias_id', (int)$plain_alias->key);
$run->set('mir_usr_user_id', $uid);
$run->set('mir_fil_file_id', (int)$file->key);
$run->set('mir_source_name', 'albf-' . $suffix . '.mbox');
$run->set('mir_state', MailImportRun::STATE_QUEUED);
$run->set('mir_own_addresses', $plain_addr);
$run->prepare();
$run->save();
$run->load();
harness_register_row('mir_mail_import_runs', 'mir_mail_import_run_id', (int)$run->key);
$importer = new MailArchiveImporter($run);
$scan = $importer->scanBatch(microtime(true) + 20, 100);
$entries = $db->prepare('SELECT mie_mail_import_entry_id, mie_locator FROM mie_mail_import_entries WHERE mie_mir_mail_import_run_id = ? ORDER BY mie_ordinal');
$entries->execute(array((int)$run->key));
$entries = $entries->fetchAll(PDO::FETCH_ASSOC);
check(!empty($scan['done']) && count($entries) === 2, 'the scan indexed both messages', json_encode(array($scan, count($entries))));
foreach ($entries as $e) {
	harness_register_row('mie_mail_import_entries', 'mie_mail_import_entry_id', (int)$e['mie_mail_import_entry_id']);
}
// What a re-import into the same mailbox does: every message dedups and the
// entry is linked to the existing row.
MailImportEntry::recordOutcome((int)$entries[0]['mie_mail_import_entry_id'], MailImportEntry::STATE_DEDUP, 'test', $m_arc1);
MailImportEntry::recordOutcome((int)$entries[1]['mie_mail_import_entry_id'], MailImportEntry::STATE_DEDUP, 'test', $m_arc2);
$run->moveTo(MailImportRun::STATE_DONE);
$importer->cleanup(); // the finished import cleared its working area; the drain rebuilds what it needs

check($candidate_ids() === array($m_arc1, $m_arc2), 'linked from a run that kept its archive, both are candidates', json_encode($candidate_ids()));
$p = AddressListBackfill::progress();
check(($p['waiting'][''] ?? 0) >= 2, 'the card counts them as waiting, not unrecoverable', json_encode($p));
$done = AddressListBackfill::drainForUser($uid, vault_fixture_dummy_key());
check($done === 2, 'both filled from the archive (got ' . $done . ')');
$r = $row($m_arc1);
check($r['iem_to'] === $plain_addr && $r['iem_cc'] === $CC_CANON && $r['iem_lists_attempt_time'] !== null,
	'the first holds both lists, canonical, and is stamped', json_encode(array($r['iem_to'], $r['iem_cc'])));
$r = $row($m_arc2);
check($r['iem_cc'] === $CC_CANON, 'so does the second');
check(!AddressListBackfill::hasWork($uid), 'nothing left from the archive');

// A third lean row linked to the run, after the archive is discarded: no source.
$m_arc3 = $lean_row('albf-arc3-' . $suffix);
MailImportEntry::recordOutcome((int)$entries[0]['mie_mail_import_entry_id'], MailImportEntry::STATE_DEDUP, 'test', $m_arc3);
check($candidate_ids() === array($m_arc3), 'linked while the archive is kept: a candidate');
$run->writeColumns(array('mir_fil_file_id' => null));
check($candidate_ids() === array(), 'the archive discarded: no source, not a candidate', json_encode($candidate_ids()));
$p = AddressListBackfill::progress();
check($p['unrecoverable'] >= 1, 'and the card counts it as having no copy', json_encode($p));

// The archive section runs BEFORE any sealed content is opened: the scan writes
// its state (a long JSON) to the run row, which a hot process is refused.
// ---- Sealed rows -----------------------------------------------------------
section('A sealed row: locked stops and unstamps; in-window seals under the row DEK');

$m_sealed = $mk_remote('albf-sealed-' . $suffix, 103, $sealed_domain, $sealed_alias, $sealed_addr);
$sr = $row($m_sealed);
check(in_array($sr['iem_content_sealed'], array('t', true, '1', 1), true) && !empty($sr['iem_sealed_key']),
	'the sealing mailbox sealed the remote row (precondition)');
$wrapping_before = $sr['iem_sealed_key'];
StubHeaderIngestor::$headers_by_uid[103] = $router->rawHeaderBlock($raw_email('albf-sealed-' . $suffix, $sealed_addr));

check($candidate_ids() === array($m_sealed), 'the sealed row is the one candidate', json_encode($candidate_ids()));
$done = AddressListBackfill::drainForUser($uid, vault_fixture_dummy_key());
check($done === 0, 'with no unlock window nothing is filled (got ' . $done . ')');
$r = $row($m_sealed);
check($r['iem_to'] === null && $r['iem_cc'] === null && $r['iem_lists_attempt_time'] === null,
	'…the row is untouched and NOT stamped, so the next in-window drain takes it', json_encode($r['iem_lists_attempt_time']));

$key = vault_fixture_open_window($uid, $kp['secret']);
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });
$done = AddressListBackfill::drainForUser($uid, $key);
check($done === 1, 'in-window the sealed row is filled (got ' . $done . ')');
$r = $row($m_sealed);
check(strpos((string)$r['iem_to'], 'v1.aead.') === 0 && strpos((string)$r['iem_cc'], 'v1.aead.') === 0,
	'both lists are ciphertext at rest');
check($r['iem_sealed_key'] === $wrapping_before, 'the row key wrapping is untouched (same DEK reused)');
$dek = $crypto->openItemDek((string)$r['iem_sealed_key'], vault_fixture_key($kp['secret']));
check($crypto->openField((string)$r['iem_cc'], $dek, InboundEmailMessage::sealAd($m_sealed, 'iem_cc')) === $CC_CANON,
	'the sealed Cc opens under the row DEK to the canonical list');
$thread = $svc->getThread((int)$sealed_alias->key, (string)(new InboundEmailMessage($m_sealed, TRUE))->get('iem_thread_key'));
check(count($thread) === 1 && $thread[0]['cc'] === $CC_CANON, 'and the reader shows it in-window');
check(!AddressListBackfill::hasWork($uid), 'nothing left for this owner');

harness_finish();
