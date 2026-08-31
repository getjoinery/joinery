<?php
/** @joinery-test
 * name: message_original
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * "Show original" coverage (specs/mailbox_show_original_coverage.md): where the
 * original of a message comes from, and what push ingest retains to answer it.
 *
 *  - rawHeaderBlock(): the wire header block byte-for-byte (CRLF and LF), the
 *    no-blank-line shape, and the 64 KB cap.
 *  - Plaintext push ingest stores the block in iem_raw_headers; the lean record
 *    (no raw) then resolves as a labeled reconstruction (headers + blank line +
 *    decoded plain body) and the thread payload says original_source 'headers'.
 *  - Sealed push ingest seals the block under the message DEK like the body;
 *    the ciphertext opens with the row's DEK + sealAd, and with no unlock
 *    window (CLI) the resolver answers locked, never plaintext.
 *  - A reference-backed row resolves 'imap' through fetchFullRaw (stubbed) and
 *    reports original_source 'imap' while its account lives, 'none' after the
 *    account is gone.
 *  - A stored inline raw resolves 'stored' and wins over the header block. The
 *    .eml endpoint serves only kinds 'stored'/'imap'; kind 'reconstructed' is
 *    its refusal signal.
 *
 * Run: php tests/run.php db --filter=message_original
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('mailbox plugin inactive');
	harness_finish();
}
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/message_export.php'));

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$crypto = new VaultCrypto();
$router = new InboundEmailRouter();
$suffix = bin2hex(random_bytes(4));

/** ImapIngestor that returns a canned RFC822 instead of talking to a server. */
class StubOriginalIngestor extends ImapIngestor {
	private $raw;
	public $closed = false;
	public function __construct(InboundImapAccount $account, string $raw) {
		parent::__construct($account);
		$this->raw = $raw;
	}
	public function fetchFullRaw(int $uid, ?int $uidvalidity, string $folder, ?string $messageId): array {
		return array('ok' => true, 'raw' => $this->raw);
	}
	public function close(): void { $this->closed = true; }
}

// ---- Fixtures --------------------------------------------------------------
$user = make_user('MsgOrig');
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

// A plaintext (Standard) domain and a sealing (Private) one, each with one alias.
$plain_domain = new InboundEmailDomain(NULL);
$plain_domain->set('ied_domain', 'msgorig-p-' . $suffix . '.example');
$plain_domain->set('ied_owner_usr_user_id', $uid);
$plain_domain->set('ied_is_enabled', true);
$plain_domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$plain_domain->key);

$sealed_domain = new InboundEmailDomain(NULL);
$sealed_domain->set('ied_domain', 'msgorig-s-' . $suffix . '.example');
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

$account = new InboundImapAccount(NULL);
$account->set('iia_label', 'MsgOrig');
$account->set('iia_provider_key', 'imap_generic');
$account->set('iia_imap_host', 'imap.test');
$account->set('iia_iea_inbound_email_alias_id', (int)$plain_alias->key);
$account->set('iia_username', 'inbox@' . $plain_domain->get('ied_domain'));
$account->set('iia_is_enabled', true);
$account->prepare();
$account->save();
harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$account->key);

$raw_email = function (string $token, string $recipient) {
	return implode("\r\n", array(
		'Received: from mx.example ([192.0.2.1]) by test.example; Mon, 25 Aug 2026 12:00:00 +0000',
		'From: Sender <sender@example.com>',
		'To: ' . $recipient,
		'Subject: Original ' . $token,
		'Message-ID: <' . $token . '@example.com>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'',
		'Body of ' . $token . '.',
		'',
	));
};

$reload = function (int $id) { return new InboundEmailMessage($id, TRUE); };
$svc = new MailboxService(MailboxViewer::forUser($uid, 5));
$thread_of = function (InboundEmailAlias $a, InboundEmailMessage $m) use ($svc) {
	return $svc->getThread((int)$a->key, (string)$m->get('iem_thread_key'));
};

// ---- rawHeaderBlock --------------------------------------------------------
section('rawHeaderBlock: the wire header block, byte-for-byte');

$crlf_headers = "From: a@x.example\r\nSubject: t\r\nContent-Type: text/plain; charset=UTF-8";
check($router->rawHeaderBlock($crlf_headers . "\r\n\r\nbody") === $crlf_headers,
	'CRLF message splits at the first blank line, line endings kept');
check($router->rawHeaderBlock("From: a@x.example\nSubject: t\n\nbody") === "From: a@x.example\nSubject: t",
	'LF message splits the same way');
check($router->rawHeaderBlock("From: a@x.example\nSubject: t") === "From: a@x.example\nSubject: t",
	'a message with no blank line is all header block');
check(strlen($router->rawHeaderBlock('X-Big: ' . str_repeat('a', 200000) . "\r\n\r\nbody")) === 65536,
	'the block is capped at 64 KB');

// ---- Plaintext push ingest -------------------------------------------------
section('Plaintext lean record: headers retained, reconstruction served');

$token = 'orig-plain-' . $suffix;
$plain_addr = 'inbox@' . $plain_domain->get('ied_domain');
$raw = $raw_email($token, $plain_addr);
$res = $router->storeMessage($raw, $router->parseEmail($raw), $plain_alias, $plain_domain, $plain_addr);
$plain_id = (int)$res['message']->key;
harness_register_model('InboundEmailMessage', $plain_id);

$m = $reload($plain_id);
check((string)$m->get('iem_raw_headers') === $router->rawHeaderBlock($raw),
	'iem_raw_headers holds the exact wire header block');
check($m->getRawMessage() === null, 'the lean record retains no raw (precondition)');

$resolved = mailbox_resolve_original($m);
check($resolved['ok'] && $resolved['kind'] === 'reconstructed',
	'no raw + headers resolves as a reconstruction');
check(strpos((string)$resolved['raw'], 'Received: from mx.example') === 0,
	'the reconstruction starts with the wire headers');
check(strpos((string)$resolved['raw'], "\r\n\r\nBody of " . $token) !== false,
	'…followed by a blank line and the decoded plain body');

$thread = $thread_of($plain_alias, $m);
check(count($thread) === 1 && $thread[0]['original_source'] === 'headers',
	'thread payload reports original_source headers');

// A row from before header retention: no headers, no raw → 'none'.
$db->prepare('UPDATE iem_inbound_email_messages SET iem_raw_headers = NULL
	WHERE iem_inbound_email_message_id = ?')->execute(array($plain_id));
$legacy = $reload($plain_id);
$resolved = mailbox_resolve_original($legacy);
check(!$resolved['ok'] && !$resolved['locked'], 'a legacy row (no headers, no raw) resolves to an honest nothing');
$thread = $thread_of($plain_alias, $legacy);
check($thread[0]['original_source'] === 'none', 'and reports original_source none');

// A stored inline raw wins over everything.
$db->prepare('UPDATE iem_inbound_email_messages SET iem_raw_message = ?
	WHERE iem_inbound_email_message_id = ?')->execute(array($raw, $plain_id));
$resolved = mailbox_resolve_original($reload($plain_id));
check($resolved['ok'] && $resolved['kind'] === 'stored' && $resolved['raw'] === $raw,
	'a stored inline raw resolves as the stored original');
$thread = $thread_of($plain_alias, $reload($plain_id));
check($thread[0]['original_source'] === 'stored', 'and reports original_source stored');

// ---- Sealed push ingest ----------------------------------------------------
section('Sealed lean record: the header block seals like the body');

$token = 'orig-sealed-' . $suffix;
$sealed_addr = 'inbox@' . $sealed_domain->get('ied_domain');
$raw = $raw_email($token, $sealed_addr);
$res = $router->storeMessage($raw, $router->parseEmail($raw), $sealed_alias, $sealed_domain, $sealed_addr);
$sealed_id = (int)$res['message']->key;
harness_register_model('InboundEmailMessage', $sealed_id);

$row = $db->query('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ' . $sealed_id)
	->fetch(PDO::FETCH_ASSOC);
check(in_array($row['iem_content_sealed'], array('t', true, '1', 1), true),
	'the sealing mailbox sealed the row (precondition)');
check(strpos((string)$row['iem_raw_headers'], 'v1.aead.') === 0,
	'iem_raw_headers holds ciphertext, never plaintext');

$dek = $crypto->openItemDek((string)$row['iem_sealed_key'], $kp['secret']);
$opened = $crypto->openField((string)$row['iem_raw_headers'], $dek, InboundEmailMessage::sealAd($sealed_id, 'iem_raw_headers'));
check($opened === $router->rawHeaderBlock($raw),
	'the sealed block opens with the row DEK to the exact wire headers');

// CLI holds no unlock window, so the resolver must answer locked — not throw,
// and never hand back ciphertext.
$resolved = mailbox_resolve_original($reload($sealed_id));
check(!$resolved['ok'] && $resolved['locked'], 'with no unlock window the resolver answers locked');
$thread = $svc->getThread((int)$sealed_alias->key, (string)$reload($sealed_id)->get('iem_thread_key'));
check($thread[0]['original_source'] === 'headers',
	'original_source still says headers — the vault, not the menu, gates the read');

// ---- Reference-backed rows -------------------------------------------------
section('Reference-backed rows: the original is fetched from the source');

$token = 'orig-remote-' . $suffix;
$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
$res = $router->storeExtracted(array(
	'sender'            => 'sender@example.com',
	'subject'           => 'Original ' . $token,
	'body_plain'        => 'Body of ' . $token . '.',
	'body_html'         => '',
	'message_id_header' => '<' . $token . '@example.com>',
	'size_bytes'        => 2048,
	'imap_account_id'   => (int)$account->key,
	'imap_uid'          => 42,
	'imap_uidvalidity'  => 1,
	'imap_folder'       => 'INBOX',
), $plain_alias, $plain_domain, $plain_addr, $auth);
$remote_id = (int)$res['message']->key;
harness_register_model('InboundEmailMessage', $remote_id);

$m = $reload($remote_id);
check((string)$m->get('iem_raw_storage_driver') === 'remote', 'the row is reference-backed (precondition)');

$remote_raw = $raw_email($token, $plain_addr);
$stub = new StubOriginalIngestor(new InboundImapAccount((int)$account->key, TRUE), $remote_raw);
$resolved = mailbox_resolve_original($m, $stub);
check($resolved['ok'] && $resolved['kind'] === 'imap' && $resolved['raw'] === $remote_raw,
	'the resolver fetches the true original from the source mailbox');
check($m->getRawMessage() === null && (string)$reload($remote_id)->get('iem_raw_storage_driver') === 'remote',
	'the fetch is pass-through — nothing was persisted');

$thread = $thread_of($plain_alias, $m);
check($thread[0]['original_source'] === 'imap', 'thread payload reports original_source imap');

// With the account reference gone there is nothing to fetch from.
$db->prepare('UPDATE iem_inbound_email_messages SET iem_iia_inbound_imap_account_id = NULL
	WHERE iem_inbound_email_message_id = ?')->execute(array($remote_id));
$resolved = mailbox_resolve_original($reload($remote_id));
check(!$resolved['ok'] && !$resolved['locked'] && $resolved['reason'] !== null,
	'a remote row with no account resolves to an honest failure');
$thread = $thread_of($plain_alias, $reload($remote_id));
check($thread[0]['original_source'] === 'none', 'and reports original_source none');

harness_finish();
