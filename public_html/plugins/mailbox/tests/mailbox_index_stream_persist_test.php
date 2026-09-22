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
 *  - persist() seals the index to the owner's one path, path-to-path, in the
 *    v1.stream. format;
 *  - a fold that changed nothing rewrites nothing (the bytes hold), a fold
 *    with one new row rewrites them;
 *  - wipe + ensureOpen restores from that file WITHOUT a rebuild (proven by
 *    the bytes holding — a rebuild always re-persists);
 *  - a file at the path that is not this build's — wrong container format,
 *    wrong format stamp — is refused, and the ensuing rebuild leaves a
 *    searchable index sealed in stream format at the same path.
 *
 * Uses an owner WITH a vault row (persist seals to uev_public_key) whose
 * message rows are unsealed — the index reads content through the same get()
 * hook either way, and what is under test here is the blob lifecycle.
 *
 * @version 1.2 - the persisted index is one path per owner, not a File per persist
 *                (specs/mailbox_search_index_blob_leak.md)
 * @version 1.1 - the format stamp refuses a blob of another shape before decrypting it
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
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

$idx = new MailboxIndex();
$blob = $idx->blobPath($uid);
// A persist rewrites the file under a fresh DEK, so identical bytes mean the
// persist did not run — which is what "restored, not rebuilt" comes down to.
$blob_bytes = function () use ($blob) {
	clearstatcache(true, $blob);
	return is_file($blob) ? md5_file($blob) : '';
};
$index_file_rows = function () use ($uid) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT COUNT(*) FROM fil_files WHERE fil_usr_user_id = ? AND fil_source = ?');
	$q->execute(array($uid, File::SOURCE_MAILBOX_SEARCH_INDEX));
	return (int)$q->fetchColumn();
};

harness_defer(function () use ($uid) { MailboxIndex::removePersisted($uid); });
$idx->wipe($uid);
MailboxIndex::removePersisted($uid);

// -------------------------------------------------------- stream persist

section('the persisted blob is stream-format');

$m1 = $make_msg('First', 'alpha streamkwone');
$idx->fold($uid, vault_fixture_key($kp['secret']));

$bytes_1 = $blob_bytes();
check($bytes_1 !== '', 'the first fold persisted the index (none existed yet)', $blob);
check(SealedBox::isStreamFile($blob), 'and it is in the v1.stream. format');
check(sprintf('%04o', fileperms($blob) & 0777) === '0660', 'the persisted index is 0660',
	sprintf('%04o', fileperms($blob) & 0777));
check(!is_file($idx->blobTmpPath($uid)), 'and the temp name it was sealed under is gone');
check($index_file_rows() === 0, 'nothing was stored as a File', 'rows=' . $index_file_rows());
check($idx->search($uid, 'streamkwone') === array($m1), 'the folded message is searchable');

// -------------------------------------------------------- dirty flag

section('a fold that changed nothing writes nothing');

$idx->fold($uid, vault_fixture_key($kp['secret']));
check($blob_bytes() === $bytes_1, 'no new mail, no refolds — the persisted bytes hold');

$m2 = $make_msg('Second', 'beta streamkwtwo');
$idx->fold($uid, vault_fixture_key($kp['secret']));
$bytes_2 = $blob_bytes();
check($bytes_2 !== '' && $bytes_2 !== $bytes_1, 'one new row rewrites the persisted index');
check(SealedBox::isStreamFile($blob), 'the rewritten index is stream-format too');
check(count((array)glob($idx->blobPath($uid) . '*')) === 1,
	'and it is still the only file for this owner',
	implode(' ', array_map('basename', (array)glob($idx->blobPath($uid) . '*'))));

// -------------------------------------------------------- restore, not rebuild

section('wipe + ensureOpen restores from the stream blob');

$idx->wipe($uid);
check(!is_file($idx->shmPath($uid)), 'the working copy is gone');
$idx->fold($uid, vault_fixture_key($kp['secret']));
check($idx->search($uid, 'streamkwtwo') === array($m2), 'search works again after the restore');
check($blob_bytes() === $bytes_2,
	'the bytes held — restored, not rebuilt (a rebuild always re-persists), and nothing new meant no write');
// Restoring opened stored sealed content, so this process is now hot; return
// it to cold so the remaining fixture writes are not refused.
SealedEgressGuard::reset();

// -------------------------------------------------------- legacy blob

section('a file at the path that is not stream-format is refused and rebuilt');

// What the whole-string seal used to produce, written where the stream file
// belongs: refused by the container check before a key is ever applied.
$shm_bytes = file_get_contents($idx->shmPath($uid));
$dek = $crypto->newItemDek();
file_put_contents($blob, $crypto->sealField($shm_bytes, $dek, 'mail:ftsindex:' . $uid));
check(!SealedBox::isStreamFile($blob), 'the file at the path is not stream-format');

$idx->wipe($uid);
$idx->fold($uid, vault_fixture_key($kp['secret']));
check($idx->search($uid, 'streamkwone') === array($m1) && $idx->search($uid, 'streamkwtwo') === array($m2),
	'the rebuild produced a searchable index');
$bytes_3 = $blob_bytes();
check(SealedBox::isStreamFile($blob), 'the rebuild left a stream-format index at the same path');
check($index_file_rows() === 0, 'and still nothing is stored as a File', 'rows=' . $index_file_rows());
SealedEgressGuard::reset();

// -------------------------------------------------------- format stamp

section('a blob of another format is refused before it is decrypted');

check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_format')) === MailboxIndex::FORMAT,
	'persist stamps the current format on the bookkeeping row');
$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
$bk->set('imi_format', MailboxIndex::FORMAT - 1);
$bk->save();
$idx->wipe($uid);
$idx->fold($uid, vault_fixture_key($kp['secret']));
$bytes_4 = $blob_bytes();
check($bytes_4 !== '' && $bytes_4 !== $bytes_3, 'a mismatched stamp skips the restore and rebuilds');
check($idx->search($uid, 'streamkwone') === array($m1), 'and the rebuilt index searches');
check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_format')) === MailboxIndex::FORMAT,
	'and the rebuild re-stamped the format');
SealedEgressGuard::reset();

$idx->wipe($uid);
harness_finish();
