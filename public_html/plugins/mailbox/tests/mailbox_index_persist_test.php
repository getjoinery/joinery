<?php
/** @joinery-test
 * name: mailbox_index_persist
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The search index lives at ONE path per owner
 * (specs/mailbox_search_index_blob_leak.md).
 *
 * The invariant nothing asserted before: however many times an owner's index
 * is persisted, they end up with one file. The shape this replaced wrote a new
 * File per persist and deleted the previous one; on one node that delete failed
 * for ten weeks and 157 copies filled the disk
 * (incidents/2026-09-22-jeremytunnell-full-backup-enospc.md). A count is the
 * only thing that would have caught it, so a count is what this pins:
 *
 *  - N persists leave exactly {uid}.bin, no temp file, and no File row;
 *  - a temp file left by a dead persist is overwritten, not accumulated;
 *  - a bookkeeping row that still names a File is transitioned on the next
 *    persist: the path is written, the row stops naming it, the File goes;
 *  - a File delete that throws does not fail the persist, and says so in the
 *    log instead of vanishing;
 *  - deleting the owner takes their index file with them;
 *  - purgePersisted() removes the file and any temp file beside it;
 *  - the sweep removes an index nobody owns and a stale temp file, and leaves
 *    a live owner's alone;
 *  - the health check passes at zero and fails naming the count otherwise.
 *
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
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));

if (!is_dir(MailboxIndex::SHM_DIR)) {
	section('one path per owner');
	harness_skip('one path per owner', MailboxIndex::SHM_DIR . ' unavailable (no shm)');
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
$domain->set('ied_domain', 'persist-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'persist');
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
	$m->set('iem_recipient', 'persist@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', $body);
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', 'persist-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};

$idx = new MailboxIndex();
$key = function () use ($kp) { return vault_fixture_key($kp['secret']); };
$blob = $idx->blobPath($uid);
$tmp  = $idx->blobTmpPath($uid);

/** Every file this owner's name could possibly cover. */
$owner_files = function () use ($blob) {
	return array_map('basename', (array)glob($blob . '*'));
};
$index_file_rows = function ($user_id) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT COUNT(*) FROM fil_files WHERE fil_usr_user_id = ? AND fil_source = ?');
	$q->execute(array($user_id, File::SOURCE_MAILBOX_SEARCH_INDEX));
	return (int)$q->fetchColumn();
};

harness_defer(function () use ($uid) { MailboxIndex::removePersisted($uid); });
$idx->wipe($uid);
MailboxIndex::removePersisted($uid);

// ------------------------------------------------- the counting invariant

section('however many persists, one file');

$ids = array();
for ($i = 1; $i <= 6; $i++) {
	$ids[] = $make_msg('Note ' . $i, 'persistkw' . $i);
	$idx->fold($uid, $key());
}
check($idx->search($uid, 'persistkw6') === array($ids[5]), 'the last message folded is searchable');
check($owner_files() === array(basename($blob)),
	'six persists left exactly one file', implode(' ', $owner_files()));
check($index_file_rows($uid) === 0, 'and no File row was written', 'rows=' . $index_file_rows($uid));
check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fil_file_id')) === 0,
	'the bookkeeping row names no File');

// ------------------------------------------------- a dead persist temp file

section('a temp file from a dead persist is overwritten, not accumulated');

file_put_contents($tmp, 'half-written rubbish from a persist that died');
$ids[] = $make_msg('Seven', 'persistkw7');
$idx->fold($uid, $key());
check($owner_files() === array(basename($blob)),
	'the next persist took the temp name back and left one file', implode(' ', $owner_files()));
check($idx->search($uid, 'persistkw7') === array($ids[6]), 'and the index is intact');

// ------------------------------------------------- the transition

section('a bookkeeping row that still names a File is transitioned');

$legacy = File::createFromBytes('superseded index bytes', 'mailfts_' . $uid . '.bin',
	'application/octet-stream', $uid, array(
		'fil_private' => true,
		'fil_source'  => File::SOURCE_MAILBOX_SEARCH_INDEX,
	));
$legacy_id = (int)$legacy->key;
$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
$bk->set('imi_fil_file_id', $legacy_id);
$bk->save();
check($index_file_rows($uid) === 1, 'the owner has one File to transition off');

$ids[] = $make_msg('Eight', 'persistkw8');
$idx->fold($uid, $key());
check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fil_file_id')) === 0,
	'the row stopped naming the File');
$still = new File($legacy_id, TRUE);
check(!$still->key, 'and the File itself is gone', 'fil=' . $legacy_id);
check($index_file_rows($uid) === 0, 'nothing of the old shape is left', 'rows=' . $index_file_rows($uid));
check($owner_files() === array(basename($blob)), 'still one file', implode(' ', $owner_files()));

// ------------------------------------------------- a delete that will not run

section('a File delete that throws is logged, and the persist still succeeds');

// The real failure: something refuses the delete. On node 176 it was a
// deletion rule the engine could not evaluate; here it is a rule that says no
// on purpose (esf_event_session_files holds files against deletion). Either
// way persistOrThrow() must keep going and must not swallow it.
$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare("SELECT COUNT(*) FROM del_deletion_rules
                    WHERE del_source_table = 'fil_files' AND del_action = 'prevent'
                      AND del_target_table = 'esf_event_session_files'");
$q->execute();
if ((int)$q->fetchColumn() === 0) {
	harness_skip('a File delete that throws is logged',
		'no prevent rule on fil_files here to refuse the delete with');
} else {
	$held = File::createFromBytes('an index File something holds', 'mailfts_' . $uid . '.bin',
		'application/octet-stream', $uid, array(
			'fil_private' => true,
			'fil_source'  => File::SOURCE_MAILBOX_SEARCH_INDEX,
		));
	$held_id = (int)$held->key;
	$db->prepare('INSERT INTO esf_event_session_files (esf_fil_file_id) VALUES (?)')->execute(array($held_id));
	harness_defer(function () use ($held_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('DELETE FROM esf_event_session_files WHERE esf_fil_file_id = ?')->execute(array($held_id));
		$f = new File($held_id, TRUE);
		if ($f->key) { $f->permanent_delete(); }
	});

	$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
	$bk->set('imi_fil_file_id', $held_id);
	$bk->save();

	$ids[] = $make_msg('Nine', 'persistkw9');
	$log  = tempnam(sys_get_temp_dir(), 'mailfts_log_');
	$prev = ini_get('error_log');
	ini_set('error_log', $log);
	$threw = '';
	try { $idx->fold($uid, $key()); }
	catch (Throwable $e) { $threw = $e->getMessage(); }
	ini_set('error_log', $prev);
	$logged = trim((string)@file_get_contents($log));
	@unlink($log);

	check($threw === '', 'the persist did not fail over a delete it could not do', $threw);
	check($idx->search($uid, 'persistkw9') === array($ids[8]), 'and it carried the new message');
	check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fil_file_id')) === 0,
		'the row stopped naming the File even though the File survived');
	$still_held = new File($held_id, TRUE);
	check((bool)$still_held->key, 'the File is still there — it is now a stray', 'fil=' . $held_id);
	check(strpos($logged, (string)$held_id) !== false && stripos($logged, 'stray') !== false,
		'and the log says so, naming the id (ten weeks of silence is what this replaces)',
		$logged === '' ? '(nothing was logged)' : $logged);
}

// ------------------------------------------------- purge

section('purgePersisted() removes the file and anything beside it');

file_put_contents($tmp, 'left over');
$idx->purgePersisted($uid);
check($owner_files() === array(), 'the file and its temp name are both gone', implode(' ', $owner_files()));
check(intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fts_high_water')) === 0,
	'and the high-water mark reset, so the next unlock rebuilds');

$idx->fold($uid, $key());
check(is_file($blob), 'the next fold persisted again');

// ------------------------------------------------- the sweep

section('the sweep takes what nobody owns and leaves what somebody does');

$dir = MailboxIndex::blobDir();
$orphan_uid = 2147483001;                        // no bookkeeping row can name it
$orphan = $dir . '/' . $orphan_uid . '.bin';
file_put_contents($orphan, 'an index whose owner is gone');
$stale = $blob . '.tmp';
file_put_contents($stale, 'a persist that died two hours ago');
touch($stale, time() - 7200);
$fresh_tmp = $dir . '/' . $orphan_uid . '.bin.tmp';
file_put_contents($fresh_tmp, 'a persist that may still be running');

$dry = InboundMailboxSearchIndex::sweepPersistedIndexes(true);
check($dry['removed'] === 2, 'the dry run counts both strays and removes nothing', 'removed=' . $dry['removed']);
check(is_file($orphan) && is_file($stale), 'both are still there after the dry run');

$swept = InboundMailboxSearchIndex::sweepPersistedIndexes();
check($swept['removed'] === 2, 'the sweep removed both', 'removed=' . $swept['removed']);
check(!is_file($orphan), 'the ownerless index is gone');
check(!is_file($stale), 'the stale temp file is gone');
check(is_file($fresh_tmp), 'a temp file young enough to be a live persist is left alone');
check(is_file($blob), 'and the live owner index is untouched');
@unlink($fresh_tmp);

// ------------------------------------------------- the health check

section('the health check counts what the sweep would take');

// The check is deliberately whole-node: one file per owner, nothing else,
// anywhere. A node that has not yet reclaimed the copies the old shape left
// fails it for that reason, which is the check doing its job — so the pass
// case is asserted only where the node is already clean.
$baseline = '';
try { InboundEmailHealth::checkSearchIndexStorage(); }
catch (Throwable $e) { $baseline = $e->getMessage(); }
if ($baseline === '') {
	check(true, 'with one file per owner and no File rows, the check passes');
} else {
	harness_skip('with one file per owner and no File rows, the check passes',
		'this node still holds copies from the old shape: ' . $baseline);
}

file_put_contents($orphan, 'back again');
$failed = '';
try { InboundEmailHealth::checkSearchIndexStorage(); }
catch (Throwable $e) { $failed = $e->getMessage(); }
check(strpos($failed, basename($orphan)) !== false,
	'a stray is named, not merely counted', $failed);
check(strpos($baseline, basename($orphan)) === false,
	'and it was not named before it existed', $baseline);
@unlink($orphan);

// ------------------------------------------------- deleting the owner

section('deleting the owner takes their index with them');

$idx->fold($uid, $key());
check(is_file($blob), 'the owner has a persisted index to lose');
$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
$bk->permanent_delete();
check(!is_file($blob), 'deleting the bookkeeping row removed the file', implode(' ', $owner_files()));

SealedEgressGuard::reset();
$idx->wipe($uid);
harness_finish();
