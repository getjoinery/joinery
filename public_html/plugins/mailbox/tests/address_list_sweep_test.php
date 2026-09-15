<?php
/** @joinery-test
 * name: address_list_sweep
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * AddressListSweep (TEMPORARY, specs/mailbox_to_cc_lists.md § 5a): rows with
 * no copy of their headers on this server get their To / Cc read back from
 * the connected IMAP account in bulk, by Message-ID.
 *
 *  - The walk covers a sparse folder in UID windows, doubling over proven-empty
 *    ranges, and never skips a UID it has not fetched.
 *  - An unsealed lean row is filled in plaintext without an unlock window; a
 *    sealed one stops the turn (locked), leaves its window unadvanced, and is
 *    filled under the row's own DEK once the window is open.
 *  - Trash / Junk are walked beside the \All folder; a message the account
 *    holds that we do not is simply passed over.
 *  - Done = every folder at its UIDNEXT; hasWork() then answers false and the
 *    cursor survives on the account row.
 *
 * Run: php tests/run.php db --filter=address_list_sweep
 *
 * @version 1.1
 * @changelog 1.1 - a failed turn backs the account off; the marker clears on a landed window
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
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AddressListSweep.php'));

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$crypto = new VaultCrypto();
$router = new InboundEmailRouter();
$suffix = bin2hex(random_bytes(4));

$CC_CANON = '"Ford, Tom" <tford@akamai.example>, "Beltran, Luis" <lubeltra@akamai.example>';

/** A sweep that answers from canned folders instead of a server. */
class StubSweep extends AddressListSweep {
	/** folder => ['uidvalidity'=>int, 'uidnext'=>int, 'headers'=>[uid => header block]] */
	public static $folders = array();
	/** every window asked for, in order: [folder, from, to] */
	public static $windows = array();
	/** when set, status() throws with it — a server that will not answer */
	public static $refuse = null;
	protected function status(string $folder): array {
		if (self::$refuse !== null) {
			throw new RuntimeException(self::$refuse);
		}
		$f = self::$folders[$folder] ?? array('uidvalidity' => 1, 'uidnext' => 1);
		return array($f['uidvalidity'], $f['uidnext']);
	}
	protected function fetchHeaders(string $folder, int $from, int $to): array {
		self::$windows[] = array($folder, $from, $to);
		$out = array();
		foreach ((self::$folders[$folder]['headers'] ?? array()) as $uid => $block) {
			if ($uid >= $from && $uid <= $to) {
				$out[$uid] = $block;
			}
		}
		return $out;
	}
}

// ---- Fixtures --------------------------------------------------------------
$user = make_user('AddrSweep');
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
$plain_domain->set('ied_domain', 'alsw-p-' . $suffix . '.example');
$plain_domain->set('ied_owner_usr_user_id', $uid);
$plain_domain->set('ied_is_enabled', true);
$plain_domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$plain_domain->key);

$sealed_domain = new InboundEmailDomain(NULL);
$sealed_domain->set('ied_domain', 'alsw-s-' . $suffix . '.example');
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

// The connected account sits on the plain mailbox; a Gmail-shaped folder set.
$account = new InboundImapAccount(NULL);
$account->set('iia_label', 'AddrSweep');
$account->set('iia_provider_key', 'imap_generic');
$account->set('iia_imap_host', 'imap.test');
$account->set('iia_iea_inbound_email_alias_id', (int)$plain_alias->key);
$account->set('iia_username', $plain_addr);
$account->set('iia_is_enabled', true);
$account->prepare();
$account->save();
harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$account->key);
$acc_id = (int)$account->key;
foreach (array(
	array('INBOX', InboundImapFolder::ROLE_INBOX),
	array('[Gmail]/All Mail', InboundImapFolder::ROLE_ALL),
	array('[Gmail]/Trash', InboundImapFolder::ROLE_TRASH),
	array('[Gmail]/Sent Mail', InboundImapFolder::ROLE_SENT),
) as $fd) {
	$f = InboundImapFolder::upsert($acc_id, $fd[0], $fd[1], true);
	harness_register_row('iif_inbound_imap_folders', 'iif_inbound_imap_folder_id', (int)$f->key);
}

$header_block = function (string $token, string $recipient) {
	return implode("\r\n", array(
		'From: "Hartleb, Zak" <zhartleb@akamai.example>',
		'To: "' . $recipient . '" <' . $recipient . '>',
		'CC: "Ford, Tom" <tford@akamai.example>,',
		"\t" . '"Beltran, Luis" <lubeltra@akamai.example>',   // folded, as the wire has it
		'Subject: Sweep ' . $token,
		'Message-ID: <' . $token . '@example.com>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'',
	));
};

/** A lean pre-columns plaintext row: no raw, no headers, no locator — only its Message-ID. */
$lean_row = function (string $token) use ($plain_domain, $plain_alias, $plain_addr) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$plain_domain->key);
	$m->set('iem_iea_inbound_email_alias_id', (int)$plain_alias->key);
	$m->set('iem_recipient', $plain_addr);
	$m->set('iem_sender', 'zhartleb@akamai.example');
	$m->set('iem_subject', 'Sweep ' . $token);
	$m->set('iem_body_plain', 'Body of ' . $token . '.');
	$m->set('iem_message_id_header', '<' . $token . '@example.com>');
	$m->set('iem_thread_key', '<' . $token . '@example.com>');
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};
/** A sealed row without lists (storeExtracted on the sealing mailbox, no headers known). */
$sealed_row = function (string $token) use ($router, $sealed_domain, $sealed_alias, $sealed_addr, $acc_id) {
	$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
	$res = $router->storeExtracted(array(
		'sender'            => 'zhartleb@akamai.example',
		'subject'           => 'Sweep ' . $token,
		'body_plain'        => 'Body of ' . $token . '.',
		'body_html'         => '',
		'message_id_header' => '<' . $token . '@example.com>',
		'headers'           => array(),
		'size_bytes'        => 100,
		'imap_account_id'   => $acc_id,
		'imap_uid'          => 2900,
		'imap_uidvalidity'  => 7,
		'imap_folder'       => '[Gmail]/All Mail',
		'received_time'     => gmdate('Y-m-d H:i:s'),
	), $sealed_alias, $sealed_domain, $sealed_addr, $auth);
	$id = (int)$res['message']->key;
	harness_register_model('InboundEmailMessage', $id);
	return $id;
};
$row = function (int $id) use ($db) {
	$stmt = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$stmt->execute(array($id));
	return $stmt->fetch(PDO::FETCH_ASSOC);
};
$state = function () use ($db, $acc_id) {
	$stmt = $db->prepare('SELECT iia_lists_sweep_state FROM iia_inbound_imap_accounts WHERE iia_inbound_imap_account_id = ?');
	$stmt->execute(array($acc_id));
	return json_decode((string)$stmt->fetchColumn(), true);
};

$m_plain  = $lean_row('alsw-plain-' . $suffix);
$m_trash  = $lean_row('alsw-trash-' . $suffix);
$m_sealed = $sealed_row('alsw-sealed-' . $suffix);
$sr = $row($m_sealed);
check(in_array($sr['iem_content_sealed'], array('t', true, '1', 1), true) && !empty($sr['iem_sealed_key']),
	'the sealing mailbox sealed its row (precondition)');
$wrapping_before = $sr['iem_sealed_key'];

// A sparse All Mail: UIDNEXT 3001, live mail at 5, 2900 (ours) and 2950 (not ours).
StubSweep::$folders = array(
	'[Gmail]/All Mail' => array('uidvalidity' => 7, 'uidnext' => 3001, 'headers' => array(
		5    => $header_block('alsw-plain-' . $suffix, $plain_addr),
		2900 => $header_block('alsw-sealed-' . $suffix, $sealed_addr),
		2950 => $header_block('alsw-stranger-' . $suffix, 'someone@else.example'),
	)),
	'[Gmail]/Trash' => array('uidvalidity' => 3, 'uidnext' => 10, 'headers' => array(
		3 => $header_block('alsw-trash-' . $suffix, $plain_addr),
	)),
);

// ---- A failing server ------------------------------------------------------
section('A turn that fails sits the account out; the card says why');

check(StubSweep::hasWork($uid), 'hasWork sees rows without lists and an account to ask');
StubSweep::$refuse = 'imap.test refused the connection';
$filled = StubSweep::drainForUser($uid, vault_fixture_dummy_key());
check($filled === 0 && !StubSweep::hasWork($uid),
	'after a failed turn the account is not offered again (no tight loop on a chained drain)');
$st = $state();
check(!empty($st['failed_at']) && strpos((string)$st['error'], 'refused') !== false, 'the failure is recorded on the account', json_encode($st));
$lines = AddressListSweep::describe();
$mine = array_values(array_filter($lines, function ($l) { return strpos($l, 'AddrSweep') === 0; }));
check(count($mine) === 1 && strpos($mine[0], 'last turn failed') !== false, 'the card line names the failure', json_encode($mine));
StubSweep::$refuse = null;
// Expire the backoff by hand: the sweep reads the marker from the row.
$st['failed_at'] = gmdate('Y-m-d H:i:s', time() - AddressListSweep::FAILURE_BACKOFF_SECONDS - 5);
InboundImapAccount::updateColumns($acc_id, array('iia_lists_sweep_state' => json_encode($st)));
check(StubSweep::hasWork($uid), 'once the backoff has passed the account is offered again');

// ---- Without a window ------------------------------------------------------
section('Unsealed rows fill with no window; a sealed one stops the turn, unadvanced');

$filled = StubSweep::drainForUser($uid, vault_fixture_dummy_key());
check($filled === 1, 'the plain row in All Mail was filled (got ' . $filled . ')');
$r = $row($m_plain);
check($r['iem_to'] === $plain_addr && $r['iem_cc'] === $CC_CANON && $r['iem_lists_attempt_time'] !== null,
	'…plaintext, canonical (folded Cc unfolded), and stamped so the card counts it', json_encode(array($r['iem_to'], $r['iem_cc'])));
$r = $row($m_sealed);
check($r['iem_to'] === null && $r['iem_cc'] === null && $r['iem_lists_attempt_time'] === null,
	'the sealed row is untouched and not stamped');
$r = $row($m_trash);
check($r['iem_to'] === null, 'Trash was not reached: the locked row stopped the turn');
$st = $state();
$all = $st['folders']['[Gmail]/All Mail'] ?? array();
check(empty($st['done']) && empty($all['done']) && intval($all['next'] ?? 0) <= 2900,
	'the cursor stopped before the window that held the sealed row', json_encode($st));
check(empty($st['failed_at']), 'a window that landed cleared the failure marker');
$first_pass = StubSweep::$windows;
check(count($first_pass) >= 2 && $first_pass[0] === array('[Gmail]/All Mail', 1, 500),
	'the walk began at UID 1 with the base span', json_encode($first_pass));
$spans = array_map(function ($w) { return $w[2] - $w[1] + 1; }, $first_pass);
check(max($spans) > AddressListSweep::BASE_SPAN, 'and doubled its span over the empty desert', json_encode($spans));

// ---- In-window -------------------------------------------------------------
section('In-window the sealed row seals under its own DEK; the walk finishes and stays finished');

$key = vault_fixture_open_window($uid, $kp['secret']);
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });
$filled = StubSweep::drainForUser($uid, $key);
check($filled === 2, 'the sealed row and the Trash row were filled (got ' . $filled . ')');
$r = $row($m_sealed);
check(strpos((string)$r['iem_to'], 'v1.aead.') === 0 && strpos((string)$r['iem_cc'], 'v1.aead.') === 0,
	'both sealed lists are ciphertext at rest');
check($r['iem_sealed_key'] === $wrapping_before, 'the row key wrapping is untouched (same DEK reused)');
$dek = $crypto->openItemDek((string)$r['iem_sealed_key'], vault_fixture_key($kp['secret']));
check($crypto->openField((string)$r['iem_cc'], $dek, InboundEmailMessage::sealAd($m_sealed, 'iem_cc')) === $CC_CANON,
	'the sealed Cc opens under the row DEK to the canonical list');
$r = $row($m_trash);
check($r['iem_to'] === $plain_addr && $r['iem_cc'] === $CC_CANON, 'the Trash row was filled from the Trash walk');

// Every UID of every folder was fetched at least once, and nothing past UIDNEXT.
$covered = array();
foreach (StubSweep::$windows as $w) {
	for ($u = $w[1]; $u <= $w[2]; $u++) { $covered[$w[0]][$u] = true; }
}
$gaps = 0;
foreach (StubSweep::$folders as $name => $f) {
	for ($u = 1; $u < $f['uidnext']; $u++) {
		if (empty($covered[$name][$u])) { $gaps++; }
	}
}
$over = 0;
foreach (StubSweep::$windows as $w) {
	if ($w[2] >= StubSweep::$folders[$w[0]]['uidnext']) { $over++; }
}
check($gaps === 0 && $over === 0, 'no UID below UIDNEXT was skipped and none past it was asked for',
	'gaps=' . $gaps . ' over=' . $over . ' windows=' . json_encode(StubSweep::$windows));
$sent_asked = count(array_filter(StubSweep::$windows, function ($w) { return $w[0] === '[Gmail]/Sent Mail' || $w[0] === 'INBOX'; }));
check($sent_asked === 0, 'with a \\All folder present, INBOX and Sent are not walked (All Mail holds them)');

$st = $state();
check(!empty($st['done']) && !empty($st['folders']['[Gmail]/All Mail']['done']) && !empty($st['folders']['[Gmail]/Trash']['done']),
	'the account is marked done, every folder at its UIDNEXT', json_encode($st));
check(intval($st['seen']) === 4 && intval($st['filled']) === 3, 'the state counts 4 messages read, 3 rows recovered', json_encode($st));
check(!StubSweep::hasWork($uid), 'hasWork is false once the sweep is done');
$before = count(StubSweep::$windows);
StubSweep::drainForUser($uid, $key);
check(count(StubSweep::$windows) === $before, 'a further drain asks the server nothing');
$lines = AddressListSweep::describe();
$mine = array_values(array_filter($lines, function ($l) { return strpos($l, 'AddrSweep') === 0; }));
check(count($mine) === 1 && strpos($mine[0], 'finished') !== false && strpos($mine[0], '3 rows recovered') !== false,
	'the card line says finished with the counts', json_encode($mine));

harness_finish();
