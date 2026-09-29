<?php
/** @joinery-test
 * name: mailbox_index_container
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * MailboxIndex persistence (specs/mailbox_search_index_streaming_seal.md § 3.3–3.4):
 *
 *  - persist() seals the index to the owner's one path, path-to-path, as a
 *    SealedFileContainer (VaultCrypto::sealFieldFile);
 *  - a fold that changed nothing rewrites nothing (the bytes hold), a fold
 *    with one new row rewrites them;
 *  - wipe + ensureOpen restores from that file WITHOUT a rebuild (proven by
 *    the bytes holding — a rebuild always re-persists);
 *  - a file at the path that is not this build's — wrong container format,
 *    wrong format stamp — is refused, and the ensuing rebuild leaves a
 *    searchable index sealed as a container at the same path;
 *  - openFieldFile() refuses a container sealed under another AD or tampered
 *    with, and leaves no plaintext behind when it does; what it opens is 0600.
 *
 * Uses an owner WITH a vault row (persist seals to uev_public_key) whose
 * message rows are unsealed — the index reads content through the same get()
 * hook either way, and what is under test here is the blob lifecycle.
 *
 * @version 1.3 - the persisted index is a SealedFileContainer; the v1.stream. format is retired
 * @version 1.2 - the persisted index is one path per owner, not a File per persist
 *                (specs/implemented/mailbox_search_index_blob_leak.md)
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
	section('index persistence');
	harness_skip('index persistence', MailboxIndex::SHM_DIR . ' unavailable (no shm)');
	harness_finish();
	return;
}

$box = new SealedBox();
$crypto = new VaultCrypto();

$owner = make_user('IndexPersist', 5);
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

section('the persisted blob is a sealed container');

$m1 = $make_msg('First', 'alpha streamkwone');
$idx->fold($uid, vault_fixture_key($kp['secret']));

$bytes_1 = $blob_bytes();
check($bytes_1 !== '', 'the first fold persisted the index (none existed yet)', $blob);
check(SealedFileContainer::looksSealed($blob), 'and it is a sealed container');
check(SealedFileContainer::readHeader($blob)['content_id'] === 'mail:ftsindex:' . $uid,
	'bound to the index\'s AD as its content id');
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
check(SealedFileContainer::looksSealed($blob), 'the rewritten index is a container too');
check(count((array)glob($idx->blobPath($uid) . '*')) === 1,
	'and it is still the only file for this owner',
	implode(' ', array_map('basename', (array)glob($idx->blobPath($uid) . '*'))));

// -------------------------------------------------------- restore, not rebuild

section('wipe + ensureOpen restores from the sealed blob');

$idx->wipe($uid);
check(!is_file($idx->shmPath($uid)), 'the working copy is gone');
$idx->fold($uid, vault_fixture_key($kp['secret']));
check($idx->search($uid, 'streamkwtwo') === array($m2), 'search works again after the restore');
check(sprintf('%04o', fileperms($idx->shmPath($uid)) & 0777) === '0600', 'the restored working copy is 0600');
check($blob_bytes() === $bytes_2,
	'the bytes held — restored, not rebuilt (a rebuild always re-persists), and nothing new meant no write');
// Restoring opened stored sealed content, so this process is now hot; return
// it to cold so the remaining fixture writes are not refused.
SealedEgressGuard::reset();

// -------------------------------------------------------- legacy blob

section('a file at the path that is not a container is refused and rebuilt');

// A blob in the retired stream format (its magic, then bytes), written where
// the container belongs: refused by the container check before a key is ever
// applied. So is the whole-string seal that preceded it.
file_put_contents($blob, 'v1.stream.' . random_bytes(256));
check(!SealedFileContainer::looksSealed($blob), 'a retired-format file at the path is not a container');

$idx->wipe($uid);
$idx->fold($uid, vault_fixture_key($kp['secret']));
check($idx->search($uid, 'streamkwone') === array($m1) && $idx->search($uid, 'streamkwtwo') === array($m2),
	'the rebuild produced a searchable index');
$bytes_3 = $blob_bytes();
check(SealedFileContainer::looksSealed($blob), 'the rebuild left a sealed container at the same path');
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

// -------------------------------------------------------- the file doors

section('openFieldFile refuses another AD or a tampered container, leaving no plaintext');

$scratch = harness_scratch_dir('index_persist');
$plain = $scratch . '/plain.bin';
file_put_contents($plain, str_repeat('index bytes ', 5000));
$sealed = $scratch . '/sealed.bin';
$fdek = $crypto->newItemDek();
SealedEgressGuard::reset();
$crypto->sealFieldFile($plain, $sealed, $fdek, 'mail:ftsindex:' . $uid);
check(count(glob($scratch . '/sealed.bin.*')) === 0, 'sealing leaves no temp file behind');
check(!SealedEgressGuard::isHot(), 'sealing a file does not make the process hot');
$opened = $scratch . '/opened.bin';
$crypto->openFieldFile($sealed, $opened, $fdek, 'mail:ftsindex:' . $uid);
check(file_get_contents($opened) === file_get_contents($plain), 'the right key and AD open it exactly');
check(SealedEgressGuard::isHot(), 'opening stored sealed content makes the process hot (the hot-turn rule)');
check(sprintf('%04o', fileperms($opened) & 0777) === '0600', 'what it opens is 0600');
SealedEgressGuard::reset();

$wrong_ad = $scratch . '/wrong_ad.bin';
$refused = false;
try { $crypto->openFieldFile($sealed, $wrong_ad, $fdek, 'mail:ftsindex:' . ($uid + 1)); } catch (Throwable $e) { $refused = true; }
check($refused && !file_exists($wrong_ad) && count(glob($wrong_ad . '*')) === 0,
	'another owner\'s AD is refused before anything is written');

$tampered = $scratch . '/tampered.bin';
$bytes = file_get_contents($sealed);
$bytes[strlen($bytes) - 5] = chr(ord($bytes[strlen($bytes) - 5]) ^ 1);
file_put_contents($tampered, $bytes);
$bad_out = $scratch . '/tampered_out.bin';
$refused = false;
try { $crypto->openFieldFile($tampered, $bad_out, $fdek, 'mail:ftsindex:' . $uid); } catch (Throwable $e) { $refused = true; }
check($refused && !file_exists($bad_out) && count(glob($bad_out . '*')) === 0,
	'a tampered container is refused and leaves no partial plaintext');
SealedEgressGuard::reset();

// -------------------------------------------------------- the sweep

section('The sweep takes a restore temp a fatal error left in /dev/shm');

$dead = MailboxIndex::SHM_DIR . '/mailfts_' . $uid . '.sqlite.opening.' . bin2hex(random_bytes(6));
file_put_contents($dead, 'plaintext a dead restore left behind');
touch($dead, time() - InboundMailboxSearchIndex::DEAD_RESTORE_SECONDS - 60);
$side = MailboxIndex::SHM_DIR . '/mailfts_' . $uid . '.sqlite-journal';
file_put_contents($side, 'journal');
harness_defer(function () use ($dead, $side) { @unlink($dead); @unlink($side); });
InboundMailboxSearchIndex::sweepWorkingCopies();
check(!file_exists($dead), 'an old .opening. temp is removed');
check(!file_exists($side), 'and so is SQLite\'s journal beside a copy whose window is closed');

$idx->wipe($uid);
harness_finish();
