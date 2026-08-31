<?php
/** @joinery-test
 * name: sealed_serve_grant
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Sealed inline images and attachments serve through a mint-time decryption
 * grant (specs/bugfix_sealed_inline_images.md). This pins the contract:
 *
 *  - FileServeGrant: a token redeems only for its exact file + size key,
 *    within its TTL; anything else leaves no state.
 *  - With NO window (CLI has none, which is exactly the cookie-less iframe's
 *    situation), a sealed attachment opens through an activated grant — both
 *    sealed shapes — and throws VaultLockedException without one.
 *  - Minting is window-gated: with the window closed, resolveInlineImages()
 *    emits signed URLs with NO grant parameter (and none for plaintext files
 *    ever), so the 423 posture is unchanged wherever no grant could be minted.
 *  - Ingest-time adoption: inline image bytes handed to the stored half
 *    become a file-backed manifest row in the same transaction.
 *  - InlineImageBackfill: a stored-raw message's reference-backed inline
 *    image becomes file-backed on drain; an unresolvable part is stamped and
 *    not retried within the backoff window.
 *
 * Run: php tests/run.php db --filter=sealed_serve_grant
 *
 * @version 1.1 - the ingest-adopted message is registered for teardown
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
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/FileServeGrant.php'));
require_once(PathHelper::getIncludePath('includes/DriveSealed.php'));
require_once(PathHelper::getIncludePath('includes/SealedFileContainer.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InlineImageBackfill.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();
$crypto = new VaultCrypto();

// A tiny valid PNG (1x1), so MIME sniffing sees a real image.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

// ---- Fixtures ------------------------------------------------------------
$user = make_user('ServeGrant');
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

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'servegrant-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_owner_usr_user_id', $uid);
$domain->set('ied_is_protected_identity', true);
$domain->set('ied_security_level', 'private');
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'inbox');
$alias->set('iea_delivery_mode', 'store');
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
$alias_addr = 'inbox@' . $domain->get('ied_domain');

$grant_row = new InboundEmailMailboxGrant(NULL);
$grant_row->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
$grant_row->set('ieg_usr_user_id', $uid);
$grant_row->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant_row->key);

/** A stored sealed message; returns [id, dek]. */
$make_sealed_message = function (string $tag) use ($domain, $alias, $alias_addr, $vault) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', (int)$alias->key);
	$m->set('iem_recipient', $alias_addr);
	$m->set('iem_sender', 'sender@example.org');
	$m->set('iem_subject', 'with a picture');
	$m->set('iem_body_plain', 'body');
	$m->set('iem_body_html', '<p><img src="cid:' . $tag . '"></p>');
	$m->set('iem_thread_key', 'tk-' . $tag);
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	$dek = InboundEmailMessage::sealAndPersistContent((int)$m->key, $vault, 'sender@example.org',
		$alias_addr, 'with a picture', 'body', '<p><img src="cid:' . $tag . '"></p>');
	return array((int)$m->key, $dek);
};

$make_att_row = function (int $msg_id, string $cid, string $mime_part, ?int $fil_id, bool $is_sealed) {
	$att = InboundMessageAttachment::CreateEntry(array(
		'ima_iem_inbound_email_message_id' => $msg_id,
		'ima_filename'     => $cid . '.png',
		'ima_content_type' => 'image/png',
		'ima_size_bytes'   => 100,
		'ima_mime_part'    => $mime_part,
		'ima_content_id'   => $cid,
		'ima_is_inline'    => true,
		'ima_fil_file_id'  => $fil_id,
		'ima_is_sealed'    => $is_sealed,
	));
	return intval($att->key);
};

// ---- Grant store mechanics -------------------------------------------------
section('FileServeGrant mint/redeem');

FileServeGrant::deactivate();
$token = FileServeGrant::mint(101, 'original', FileServeGrant::SHAPE_FILE_KEY, 'k-101', 60);
check(is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token) === 1, 'mint returns a 32-hex token');

check(!FileServeGrant::redeemAndActivate(102, 'original', $token), 'a different file id refuses');
check(FileServeGrant::activeKey(101, FileServeGrant::SHAPE_FILE_KEY) === null, 'a refused redeem leaves no state');
check(!FileServeGrant::redeemAndActivate(101, 'thumbnail', $token), 'a different size key refuses');
check(!FileServeGrant::redeemAndActivate(101, 'original', 'zz' . substr($token, 2)), 'a wrong token refuses');
check(!FileServeGrant::redeemAndActivate(101, 'original', 'not-a-token'), 'a malformed token refuses');

check(FileServeGrant::redeemAndActivate(101, 'original', $token), 'the exact file + size key redeems');
check(FileServeGrant::activeKey(101, FileServeGrant::SHAPE_FILE_KEY) === 'k-101', 'the key is readable after redeem');
check(FileServeGrant::activeKey(101, FileServeGrant::SHAPE_MESSAGE_DEK) === null, '…only under its own shape');
check(FileServeGrant::activeKey(102, FileServeGrant::SHAPE_FILE_KEY) === null, '…and only for its own file');
FileServeGrant::deactivate();

$short = FileServeGrant::mint(103, 'original', FileServeGrant::SHAPE_FILE_KEY, 'k-103', 1);
sleep(2);
check(!FileServeGrant::redeemAndActivate(103, 'original', $short), 'an expired token refuses');

// ---- Sealed serve through a grant, with no window --------------------------
section('A grant decrypts what a closed window cannot');

// Self-sealed container shape.
list($msg_a, $dek_a) = $make_sealed_message('selfsealed');
$tmp = tempnam(sys_get_temp_dir(), 'sg-test-');
file_put_contents($tmp, $png);
$sealed_file = DriveSealed::createSealedFile($tmp, 'selfsealed.png', 'image/png', $uid,
	array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
@unlink($tmp);
harness_register_model('File', (int)$sealed_file->key);
$att_a = $make_att_row($msg_a, 'selfsealed', '2', (int)$sealed_file->key, false);

$container_bytes = file_get_contents($sealed_file->get_filesystem_path('original'));
check(is_string($container_bytes) && $container_bytes !== '' && $container_bytes !== $png,
	'the stored bytes are a sealed container, not the image');

$msg_obj = new InboundEmailMessage($msg_a, TRUE);
$att_obj = new InboundMessageAttachment($att_a, TRUE);

FileServeGrant::deactivate();
$threw = false;
try {
	InboundEmailMessage::openSealedAttachment($msg_obj, $att_obj, $container_bytes, $sealed_file);
} catch (VaultLockedException $e) {
	$threw = true;
}
check($threw, 'no window and no grant: the self-sealed shape is locked (the 423 path)');

$fk = $crypto->openItemDek((string)$sealed_file->get('fil_sealed_key'), $kp['secret']);
$tok = FileServeGrant::mint((int)$sealed_file->key, 'original', FileServeGrant::SHAPE_FILE_KEY, $fk, 60);
check(FileServeGrant::redeemAndActivate((int)$sealed_file->key, 'original', $tok), 'the file-key grant redeems');
// isolate(): opening sealed content arms the hot-turn rule, and this test
// still has fixture rows to write after these opens — same containment the
// deferred drains use.
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
$opened = SealedEgressGuard::isolate(function () use ($msg_obj, $att_obj, $container_bytes, $sealed_file) {
	return InboundEmailMessage::openSealedAttachment($msg_obj, $att_obj, $container_bytes, $sealed_file);
});
check($opened === $png, 'the self-sealed container opens through the grant — no window involved');
FileServeGrant::deactivate();

// Message-DEK shape: a plaintext-flagged File whose bytes are an AEAD blob
// under the owning message's DEK.
list($msg_b, $dek_b) = $make_sealed_message('msgdek');
$tmp = tempnam(sys_get_temp_dir(), 'sg-test-');
file_put_contents($tmp, 'placeholder');
$dek_file = File::createFromUpload($tmp, 'msgdek.png', 'image/png', $uid,
	array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
if (is_file($tmp)) { @unlink($tmp); }
harness_register_model('File', (int)$dek_file->key);
$att_b = $make_att_row($msg_b, 'msgdek', '2', (int)$dek_file->key, true);

$aead_bytes = $crypto->sealField($png, $dek_b, InboundEmailMessage::attachmentAd($msg_b, '2'));
$msg_obj_b = new InboundEmailMessage($msg_b, TRUE);
$att_obj_b = new InboundMessageAttachment($att_b, TRUE);

$threw = false;
try {
	InboundEmailMessage::openSealedAttachment($msg_obj_b, $att_obj_b, $aead_bytes, $dek_file);
} catch (VaultLockedException $e) {
	$threw = true;
}
check($threw, 'no window and no grant: the message-DEK shape is locked too');

$tok = FileServeGrant::mint((int)$dek_file->key, 'original', FileServeGrant::SHAPE_MESSAGE_DEK, $dek_b, 60);
check(FileServeGrant::redeemAndActivate((int)$dek_file->key, 'original', $tok), 'the message-DEK grant redeems');
$opened = SealedEgressGuard::isolate(function () use ($msg_obj_b, $att_obj_b, $aead_bytes, $dek_file) {
	return InboundEmailMessage::openSealedAttachment($msg_obj_b, $att_obj_b, $aead_bytes, $dek_file);
});
check($opened === $png, 'the message-DEK blob opens through the grant');
FileServeGrant::deactivate();

// ---- Minting is window-gated ------------------------------------------------
section('No window, no grant parameter');

$resolved = MailboxService::resolveInlineImages(array(
	array('id' => $msg_a, 'body_html' => '<p><img src="cid:selfsealed"></p>'),
));
$html = (string)$resolved[0]['body_html'];
check(strpos($html, 'cid:selfsealed') === false, 'the cid still rewrites to a signed URL');
check(strpos($html, 'expires=') !== false && strpos($html, 'sig=') !== false, 'the URL is signed');
check(strpos($html, 'grant=') === false,
	'…but carries NO grant with the window closed — the 423 posture is unchanged');

// ---- Ingest-time adoption ----------------------------------------------------
section('Ingest adopts inline image bytes into a File');

$account = new InboundImapAccount(NULL);
$account->set('iia_label', 'ServeGrant');
$account->set('iia_provider_key', 'imap_generic');
$account->set('iia_imap_host', 'imap.test');
$account->set('iia_iea_inbound_email_alias_id', (int)$alias->key);
$account->set('iia_username', 'me@servegrant-source.example');
$account->set('iia_is_enabled', true);
$account->prepare();
$account->save();
harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$account->key);
$folder_all = InboundImapFolder::upsert((int)$account->key, '[Gmail]/All Mail', InboundImapFolder::ROLE_ALL, true);

// The slices of Horde objects ingestOneStored reads.
$envelope = new class {
	public $from = 'someone@example.org';
	public $to = array();
	public $cc = array();
	public $subject = 'inline picture';
	public $message_id = '';
	public $date = null;
};
$envelope->message_id = '<ingest-adopt-' . bin2hex(random_bytes(4)) . '@x.example>';
$fetch_data = new class($envelope) {
	private $env;
	public function __construct($env) { $this->env = $env; }
	public function getEnvelope() { return $this->env; }
	public function getSize() { return 4096; }
};
$inline_part = new class {
	public function getContentId() { return '<ingestinline@x.example>'; }
	public function getDisposition() { return 'inline'; }
	public function getName() { return 'pic.png'; }
	public function getType() { return 'image/png'; }
	public function getPrimaryType() { return 'image'; }
	public function getBytes() { return 100; }
	public function getMimeId() { return '2'; }
};

$router = new InboundEmailRouter();
$ingestor = new ImapIngestor(new InboundImapAccount((int)$account->key, TRUE));
$m = new ReflectionMethod(ImapIngestor::class, 'ingestOneStored');
$m->setAccessible(true);
$res = $m->invoke($ingestor, $folder_all, 900, $fetch_data, $router,
	new InboundEmailAlias((int)$alias->key, TRUE), new InboundEmailDomain((int)$domain->key, TRUE),
	strtolower($alias_addr), 7, false,
	array($inline_part), 'body text', '<p><img src="cid:ingestinline@x.example"></p>', array(),
	array('2' => $png));

// Through the model, so the manifest row and the File the adoption just sealed
// go with it — ingest built them, and only the model's own delete reclaims them.
if (!empty($res['message_id'])) {
	harness_register_model('InboundEmailMessage', (int)$res['message_id']);
}
check(!$res['dedup'] && $res['message_id'] > 0, 'the message stored fresh');
$stmt = $db->prepare('SELECT ima_inbound_message_attachment_id, ima_fil_file_id, ima_size_bytes
	FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ?');
$stmt->execute(array($res['message_id']));
$manifest = $stmt->fetch(PDO::FETCH_ASSOC);
check($manifest && $manifest['ima_fil_file_id'] !== null,
	'the inline image part is file-backed straight from ingest');
check(intval($manifest['ima_size_bytes']) === strlen($png), 'the row records the decoded size');
$stmt = $db->prepare('SELECT fil_content_sealed, fil_sealed_owner_user_id FROM fil_files WHERE fil_file_id = ?');
$stmt->execute(array(intval($manifest['ima_fil_file_id'])));
$fil = $stmt->fetch(PDO::FETCH_ASSOC);
check(($fil['fil_content_sealed'] === true || $fil['fil_content_sealed'] === 't')
		&& intval($fil['fil_sealed_owner_user_id']) === $uid,
	'the adopted File is sealed to the message owner (the message is sealed)');

// ---- Backfill ----------------------------------------------------------------
section('Backfill adopts from a stored raw');

// An UNSEALED stored-raw message (CLI has no window, so only the unsealed path
// can run here; the sealed path uses the same getter behind the window).
$boundary = 'bnd' . bin2hex(random_bytes(4));
$raw = "MIME-Version: 1.0\r\n"
	. "Content-Type: multipart/related; boundary=\"$boundary\"\r\n\r\n"
	. "--$boundary\r\n"
	. "Content-Type: text/html; charset=utf-8\r\n\r\n"
	. "<p><img src=\"cid:rawinline\"></p>\r\n"
	. "--$boundary\r\n"
	. "Content-Type: image/png; name=\"raw.png\"\r\n"
	. "Content-ID: <rawinline>\r\n"
	. "Content-Disposition: inline; filename=\"raw.png\"\r\n"
	. "Content-Transfer-Encoding: base64\r\n\r\n"
	. chunk_split(base64_encode($png))
	. "--$boundary--\r\n";

$m2 = new InboundEmailMessage(NULL);
$m2->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
$m2->set('iem_iea_inbound_email_alias_id', (int)$alias->key);
$m2->set('iem_recipient', $alias_addr);
$m2->set('iem_sender', 'someone@example.org');
$m2->set('iem_subject', 'raw with picture');
$m2->set('iem_body_plain', 'body');
$m2->set('iem_body_html', '<p><img src="cid:rawinline"></p>');
$m2->set('iem_raw_message', $raw);
$m2->set('iem_raw_storage_driver', 'inline');
$m2->set('iem_sealed_owner_user_id', $uid); // owner without sealing: the backfill scopes by owner
$m2->save();
$raw_msg_id = (int)$m2->key;
harness_register_model('InboundEmailMessage', $raw_msg_id);
$att_raw = $make_att_row($raw_msg_id, 'rawinline', '2', null, false);
// And one part the raw does not contain — the failure/backoff case.
$att_gone = $make_att_row($raw_msg_id, 'gone', '9', null, false);
$db->exec("UPDATE ima_inbound_message_attachments SET ima_content_type = 'image/png'
	WHERE ima_inbound_message_attachment_id IN ($att_raw, $att_gone)");

check(InlineImageBackfill::hasWork($uid), 'hasWork sees the reference-backed inline images');
$done = InlineImageBackfill::drainForUser($uid, '');
check($done === 1, 'exactly the resolvable part adopted (got ' . $done . ')');

$stmt = $db->prepare('SELECT ima_fil_file_id FROM ima_inbound_message_attachments WHERE ima_inbound_message_attachment_id = ?');
$stmt->execute(array($att_raw));
$fil_id = $stmt->fetchColumn();
check($fil_id !== null && intval($fil_id) > 0, 'the raw-backed part is file-backed now');
if ($fil_id) {
	harness_register_model('File', intval($fil_id));
	$adopted = new File(intval($fil_id), TRUE);
	$adopted_bytes = file_get_contents($adopted->get_filesystem_path('original'));
	check($adopted_bytes === $png, 'the adopted bytes are the image (unsealed message → plain File)');
}

$stmt = $db->prepare('SELECT ima_fil_file_id, ima_adopt_attempt_time FROM ima_inbound_message_attachments
	WHERE ima_inbound_message_attachment_id = ?');
$stmt->execute(array($att_gone));
$gone = $stmt->fetch(PDO::FETCH_ASSOC);
check($gone['ima_fil_file_id'] === null && $gone['ima_adopt_attempt_time'] !== null,
	'the unresolvable part stays reference-backed and is stamped');
check(!InlineImageBackfill::hasWork($uid),
	'…and the stamp holds it out of the predicate — no per-heartbeat retry thrash');

harness_finish();
