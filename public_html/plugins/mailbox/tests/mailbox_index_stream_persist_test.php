<?php
/** @joinery-test
 * name: mailbox_index_stream_persist
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * MailboxIndex persistence in the streaming era
 * (specs/mailbox_search_index_streaming_seal.md § 3.3–3.4):
 *
 *  - persist() stores the sealed index as a v1.stream. file, path-to-path;
 *  - a fold that changed nothing performs no File write (the blob id holds),
 *    a fold with one new row rotates the blob;
 *  - wipe + ensureOpen restores from the stream blob WITHOUT a rebuild
 *    (proven by the blob id holding — a rebuild always re-persists);
 *  - a legacy v1.aead. whole-string blob is refused by restore, and the
 *    ensuing rebuild produces a searchable index persisted as stream-format.
 *
 * Uses an owner WITH a vault row (persist seals to uev_public_key) whose
 * message rows are unsealed — the index reads content through the same get()
 * hook either way, and what is under test here is the blob lifecycle.
 *
 * @version 1.1 - the format stamp refuses a blob of another shape before decrypting it
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_mailbox_search_index_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

if (!is_dir(MailboxIndex::SHM_DIR)) {
	section('stream persistence');
	harness_skip('stream persistence', MailboxIndex::SHM_DIR . ' unavailable (no shm)');
	harness_finish();
	return;
}

$box = new SealedBox();
$crypto = new VaultCrypto();

$owner = make_user('StreamPersist', 5);
$uid = (int)$owner->key;
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
$domain->set('ied_domain', 'stream-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'stream');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias_id = (int)$alias->key;
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $alias_id);

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
$grant->set('ieg_usr_user_id', $uid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

$make_msg = function ($subject, $body) use ($domain, $alias_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_recipient', 'stream@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', $body);
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', 'stream-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};

$blob_file_id = function () use ($uid) {
	return intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fil_file_id'));
};
$blob_path = function (int $fil_id) {
	$file = new File($fil_id, TRUE);
	$blob = $file->key ? $file->_blob() : null;
	return $blob ? $blob->filesystem_path('original') : '';
};

$idx = new MailboxIndex();
$idx->wipe($uid);

// -------------------------------------------------------- stream persist

section('the persisted blob is stream-format');

$m1 = $make_msg('First', 'alpha streamkwone');
$idx->fold($uid, $kp['secret']);

$fil_1 = $blob_file_id();
harness_register_model('File', $fil_1);
check($fil_1 > 0, 'the first fold persisted a blob (no blob existed yet)', 'fil=' . $fil_1);
$path_1 = $blob_path($fil_1);
check($path_1 !== '' && is_file($path_1), 'the blob is on local disk', $path_1);
check(SealedBox::isStreamFile($path_1), 'and is in the v1.stream. format');
check($idx->search($uid, 'streamkwone') === array($m1), 'the folded message is searchable');

// -------------------------------------------------------- dirty flag

section('a fold that changed nothing writes nothing');

$idx->fold($uid, $kp['secret']);
check($blob_file_id() === $fil_1, 'no new mail, no refolds — the blob id holds', 'fil=' . $blob_file_id());

$m2 = $make_msg('Second', 'beta streamkwtwo');
$idx->fold($uid, $kp['secret']);
$fil_2 = $blob_file_id();
harness_register_model('File', $fil_2);
check($fil_2 > 0 && $fil_2 !== $fil_1, 'one new row rotates the blob', "was $fil_1 now $fil_2");
check(SealedBox::isStreamFile($blob_path($fil_2)), 'the rotated blob is stream-format too');

// -------------------------------------------------------- restore, not rebuild

section('wipe + ensureOpen restores from the stream blob');

$idx->wipe($uid);
check(!is_file($idx->shmPath($uid)), 'the working copy is gone');
$idx->fold($uid, $kp['secret']);
check($idx->search($uid, 'streamkwtwo') === array($m2), 'search works again after the restore');
check($blob_file_id() === $fil_2,
	'the blob id held — restored, not rebuilt (a rebuild always re-persists), and nothing new meant no write',
	'fil=' . $blob_file_id());
// Restoring opened stored sealed content, so this process is now hot; return
// it to cold so the remaining fixture writes are not refused.
SealedEgressGuard::reset();

// -------------------------------------------------------- legacy blob

section('a legacy v1.aead. blob is refused and rebuilt as stream-format');

// Hand-build what the whole-string seal used to persist: the index bytes
// sealed as one v1.aead. text blob, stored as the bookkeeping's File.
$shm_bytes = file_get_contents($idx->shmPath($uid));
$dek = $crypto->newItemDek();
$legacy_blob = $crypto->sealField($shm_bytes, $dek, 'mail:ftsindex:' . $uid);
$legacy_file = File::createFromBytes($legacy_blob, 'mailfts_' . $uid . '.bin', 'application/octet-stream', $uid, array(
	'fil_private' => true,
	'fil_source'  => File::SOURCE_MAILBOX_SEARCH_INDEX,
));
harness_register_model('File', (int)$legacy_file->key);

$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
$bk->set('imi_fil_file_id', (int)$legacy_file->key);
$bk->set('imi_sealed_key', $crypto->sealItemDek($dek, $kp['public']));
$bk->save();

$idx->wipe($uid);
$idx->fold($uid, $kp['secret']);
check($idx->search($uid, 'streamkwone') === array($m1) && $idx->search($uid, 'streamkwtwo') === array($m2),
	'the rebuild produced a searchable index');
$fil_3 = $blob_file_id();
harness_register_model('File', $fil_3);
check($fil_3 > 0 && $fil_3 !== (int)$legacy_file->key,
	'the rebuild persisted a fresh blob in place of the legacy one', "legacy={$legacy_file->key} now=$fil_3");
check(SealedBox::isStreamFile($blob_path($fil_3)), 'and it is stream-format');
SealedEgressGuard::reset();

// -------------------------------------------------------- format stamp

section('a blob of another format is refused before it is decrypted');

check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_format')) === MailboxIndex::FORMAT,
	'persist stamps the current format on the bookkeeping row');
$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
$bk->set('imi_format', MailboxIndex::FORMAT - 1);
$bk->save();
$idx->wipe($uid);
$idx->fold($uid, $kp['secret']);
$fil_4 = $blob_file_id();
harness_register_model('File', $fil_4);
check($fil_4 > 0 && $fil_4 !== $fil_3, 'a mismatched stamp skips the restore and rebuilds', "before=$fil_3 now=$fil_4");
check($idx->search($uid, 'streamkwone') === array($m1), 'and the rebuilt index searches');
check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_format')) === MailboxIndex::FORMAT,
	'and the rebuild re-stamped the format');
SealedEgressGuard::reset();

$idx->wipe($uid);
harness_finish();
