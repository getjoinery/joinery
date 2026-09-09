<?php
/** @joinery-test
 * name: mailbox_index_working_copy_mode
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The /dev/shm working copy is private (specs/vault_exposure_quick_fixes.md Q1).
 *
 * /dev/shm is a 1777 tmpfs every local account can list, and the working
 * copy holds the owner's whole decrypted vocabulary for the life of the
 * unlock window. Both ways a copy comes to exist — rebuild() from the sealed
 * rows, and restoreFromBlob() streaming the sealed blob open — must leave it
 * 0600. Without the fix SQLite creates the file 0644 (its own default mode,
 * the umask only ever narrows it) and the stream open creates its temp file
 * under the umask; the umask is opened to 0 here so the second path cannot
 * pass by luck either.
 *
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
	section('working copy mode');
	harness_skip('working copy mode', MailboxIndex::SHM_DIR . ' unavailable (no shm)');
	harness_finish();
	return;
}

$old_umask = umask(0);

$box = new SealedBox();
$owner = make_user('ShmMode', 5);
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
$domain->set('ied_domain', 'shmmode-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'shmmode');
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

$m = new InboundEmailMessage(NULL);
$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
$m->set('iem_iea_inbound_email_alias_id', $alias_id);
$m->set('iem_direction', 'inbound');
$m->set('iem_sender', 'sender@example.com');
$m->set('iem_recipient', 'shmmode@example.com');
$m->set('iem_subject', 'Mode');
$m->set('iem_body_plain', 'shmmodekw');
$m->set('iem_body_html', '');
$m->set('iem_message_id_header', 'shmmode-' . bin2hex(random_bytes(8)) . '@example.com');
$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
$m->save();
harness_register_model('InboundEmailMessage', (int)$m->key);
$mid = (int)$m->key;

$blob_file_id = function () use ($uid) {
	return intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fil_file_id'));
};
$mode_of = function (string $path) {
	clearstatcache(true, $path);
	return is_file($path) ? sprintf('%04o', fileperms($path) & 0777) : 'missing';
};

$idx = new MailboxIndex();
$path = $idx->shmPath($uid);
$idx->wipe($uid);

// ------------------------------------------------------------- rebuild

section('rebuild() creates the working copy 0600');

$idx->fold($uid, $kp['secret']);   // no blob yet: ensureOpen() rebuilds
$fil_1 = $blob_file_id();
harness_register_model('File', $fil_1);
check($fil_1 > 0, 'the first fold rebuilt and persisted (no blob existed)', 'fil=' . $fil_1);
check($idx->search($uid, 'shmmodekw') === array($mid), 'the rebuilt copy searches');
check($mode_of($path) === '0600', 'the rebuilt working copy is 0600 ', $mode_of($path));

// ------------------------------------------------------------- restore

section('restoreFromBlob() leaves the working copy 0600');

$idx->wipe($uid);
check(!is_file($path), 'the working copy is gone');
$idx->fold($uid, $kp['secret']);   // blob exists: ensureOpen() restores
check($blob_file_id() === $fil_1, 'the blob id held, so this copy came from a restore, not a rebuild', 'fil=' . $blob_file_id());
check($idx->search($uid, 'shmmodekw') === array($mid), 'the restored copy searches');
check($mode_of($path) === '0600', 'the restored working copy is 0600 ', $mode_of($path));
SealedEgressGuard::reset();

$idx->wipe($uid);
umask($old_umask);
harness_finish();
