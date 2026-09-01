<?php
/** @joinery-test
 * name: mailbox_index_incremental_fold
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * MailboxIndex incremental folding (specs/mailbox_search_incremental_fold.md):
 *
 *  - a fold cut off by its deadline reports the truth (incomplete, backlog
 *    counted) and leaves the high-water mark where the work actually stopped;
 *  - folding is delete-then-insert, so re-folding a range collapses duplicate
 *    rows (the damage pre-checkpoint builds left behind) instead of adding to
 *    them;
 *  - the fold lock: a caller finding it held touches nothing and still
 *    searches what is indexed;
 *  - the persisted blob records the mark it covers, and a restore resets the
 *    live mark to it — a mark that ran ahead of the blob (checkpointed fold,
 *    no persist) must not leave the gap permanently unindexed;
 *  - a pending-parse row folds as a no-op and enters the index via the refold
 *    queue once the pending state clears;
 *  - hasBacklog(): false with no bookkeeping row (nobody searched), true with
 *    rows above the mark, false once caught up.
 *
 * Uses an owner WITH a vault row (persist seals to uev_public_key) whose
 * message rows are unsealed — the index reads content through the same get()
 * hook either way; under test here is the fold lifecycle, not sealing.
 *
 * @version 1.1 - stale postings under a rowid are observed through search, since a contentless table stores no rows to count
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
	section('incremental fold');
	harness_skip('incremental fold', MailboxIndex::SHM_DIR . ' unavailable (no shm)');
	harness_finish();
	return;
}

$box = new SealedBox();
$crypto = new VaultCrypto();

$owner = make_user('IncrementalFold', 5);
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
$domain->set('ied_domain', 'incfold-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'incfold');
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

$make_msg = function ($subject, $body, $pending = false) use ($domain, $alias_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_recipient', 'incfold@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', $body);
	$m->set('iem_body_html', '');
	$m->set('iem_pending_parse', $pending);
	$m->set('iem_message_id_header', 'incfold-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};

$mark = function () use ($uid) {
	return intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fts_high_water'));
};
$set_mark = function (int $value) use ($uid) {
	$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
	$bk->set('imi_fts_high_water', $value);
	$bk->save();
};
$shm_rows = function (?int $id = null) use ($uid) {
	$s = new SQLite3((new MailboxIndex())->shmPath($uid), SQLITE3_OPEN_READONLY);
	$sql = $id === null
		? 'SELECT count(*) FROM mailfts'
		: 'SELECT count(*) FROM mailfts WHERE rowid = ' . intval($id);
	$n = (int)$s->querySingle($sql);
	$s->close();
	return $n;
};

$idx = new MailboxIndex();
$idx->wipe($uid);

// ------------------------------------------------- hasBacklog before any search

section('hasBacklog is false until a first search creates the bookkeeping row');

$m1 = $make_msg('First', 'alpha incfoldkwone');
$m2 = $make_msg('Second', 'beta incfoldkwtwo');
$m3 = $make_msg('Third', 'gamma incfoldkwthree');
check(MailboxIndex::hasBacklog($uid) === false,
	'messages exist, but no bookkeeping row — no fold work is owed');

// ------------------------------------------------- deadline honesty

section('a fold cut off by its deadline reports the truth');

$r = $idx->fold($uid, $kp['secret'], microtime(true) - 1);
check($r['complete'] === false, 'an already-passed deadline reports incomplete');
check($r['remaining'] === 3 && $r['total'] === 3, 'and counts the whole backlog', json_encode($r));
check($mark() === 0, 'the mark did not move past work that was not done');
check(MailboxIndex::hasBacklog($uid) === true, 'hasBacklog now sees the owed fold');

$r = $idx->fold($uid, $kp['secret']);
check($r['complete'] === true && $r['remaining'] === 0 && $r['total'] === 3,
	'an unbounded fold completes and says so', json_encode($r));
check($r['folded'] === 3, 'all three messages folded', 'folded=' . $r['folded']);
check($mark() === $m3, 'the mark sits at the newest folded id');
check($idx->search($uid, 'incfoldkwtwo') === array($m2), 'search finds folded mail');
check(MailboxIndex::hasBacklog($uid) === false, 'no backlog once caught up');

// ------------------------------------------------- idempotency heals duplicates

section('re-folding a range replaces stale postings instead of adding to them');

// The damage a pre-checkpoint build left behind: the same id indexed twice.
// A contentless FTS5 table does not police rowid uniqueness, so the second
// insert lands as extra postings under m1's rowid — its stale words match m1.
$s = new SQLite3($idx->shmPath($uid));
$s->exec("INSERT INTO mailfts (rowid, content) VALUES ($m1, 'stalekw duplicate copy')");
$s->close();
check($idx->search($uid, 'stalekw') === array($m1), 'fixture: stale postings match m1');

// Delete-then-insert removes EVERY posting for the rowid (contentless_delete),
// so a re-fold of a range the mark forgot replaces the row rather than
// stacking a third copy on it.
$set_mark(0); // as if an interrupted build never got its checkpoint
$r = $idx->fold($uid, $kp['secret']);
check($r['complete'] === true, 'the re-fold completes');
check($idx->search($uid, 'stalekw') === array(), 'the stale postings are gone');
check($idx->search($uid, 'incfoldkwone') === array($m1), 'and m1 is still found by its real content');
check($shm_rows() === 3, 'one row per message overall', 'rows=' . $shm_rows());

// ------------------------------------------------- the fold lock

section('a fold finding the lock held touches nothing and still searches');

$lock_path = MailboxIndex::SHM_DIR . '/mailfts_' . $uid . '.lock';
$holder = fopen($lock_path, 'c');
flock($holder, LOCK_EX);
$m4 = $make_msg('Fourth', 'delta incfoldkwfour');
$r = $idx->fold($uid, $kp['secret'], microtime(true) + 30);
check($r['folded'] === 0 && $r['complete'] === false && $r['remaining'] === 1,
	'the locked-out fold did no work and reported the backlog', json_encode($r));
check($idx->search($uid, 'incfoldkwone') === array($m1),
	'search still answers from what is already indexed');
flock($holder, LOCK_UN);
fclose($holder);
$r = $idx->fold($uid, $kp['secret']);
check($r['complete'] === true && $idx->search($uid, 'incfoldkwfour') === array($m4),
	'with the lock released the fold catches up');

// ------------------------------------------------- blob coverage

section('a restore resets the mark to what the blob covers');

// The complete fold above persisted a blob covering m4. Simulate a fold that
// checkpointed the mark further but was killed before its persist: two new
// messages, mark pushed past them by hand, working copy wiped (window close).
$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
check(intval($bk->get('imi_blob_high_water')) === $m4, 'the blob records its coverage',
	'blob_mark=' . $bk->get('imi_blob_high_water'));
$m5 = $make_msg('Fifth', 'epsilon incfoldkwfive');
$m6 = $make_msg('Sixth', 'zeta incfoldkwsix');
$set_mark($m6);
$idx->wipe($uid);

$r = $idx->fold($uid, $kp['secret']);
check($r['complete'] === true, 'the post-restore fold completes');
check($idx->search($uid, 'incfoldkwfive') === array($m5) && $idx->search($uid, 'incfoldkwsix') === array($m6),
	'the gap between blob-time and the stale mark was re-folded, not skipped');
// Restoring opened stored sealed content, so this process is now hot; return
// it to cold so the remaining fixture writes are not refused.
SealedEgressGuard::reset();

// ------------------------------------------------- pending-parse rows

section('a pending-parse row folds as a no-op and refolds after parse');

$m7 = $make_msg('Pending', 'eta incfoldkwseven', true);
$r = $idx->fold($uid, $kp['secret']);
check($r['complete'] === true && $mark() === $m7, 'the mark advanced past the pending row');
check($idx->search($uid, 'incfoldkwseven') === array(), 'its non-existent content was not indexed');

// What parsePendingMessage does when the content fields land.
InboundEmailMessage::updateColumns($m7, array('iem_pending_parse' => false));
MailboxIndex::enqueueRefold($alias_id, $m7);
check(MailboxIndex::hasBacklog($uid) === true, 'the queued refold counts as owed work');
$r = $idx->fold($uid, $kp['secret']);
check($idx->search($uid, 'incfoldkwseven') === array($m7), 'the parsed content entered the index');
check(MailboxIndex::hasBacklog($uid) === false, 'and the queue drained');

$idx->wipe($uid);
harness_finish();
