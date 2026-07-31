<?php
/** @joinery-test
 * name: mailbox_trash
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The Trash folder (specs/mailbox_trash_folder.md): discarded mail is visible in
 * exactly one view, only two actions can reach it, and a timed purge reclaims
 * everything it owns.
 *
 * Covers:
 *  - the Trash scope: a trashed thread leaves Inbox / All Mail / Spam and appears
 *    in Trash, per mailbox and never across a grant boundary
 *  - the mutation pin: read/star/archive/spam/label all refuse a trashed row, so
 *    a future refactor cannot quietly parameterise iem_delete_time IS NULL away
 *  - restore: the message returns with read, star, archive and label state intact
 *  - getThread: opens under the Trash scope, refused under the read scope
 *  - purge: the row, the attachment File and the refold queue entry
 *  - purge dates: computed from the retention setting, absent when it is 0
 *  - a sealed (Fortress-shaped) message purges with no unlock window
 *  - the retention rule: the declared policy, the window, and 0 = never purge
 *  - the search index holds trashed mail and the read scope decides (Change 2a),
 *    including the restore-then-search regression that change exists to fix
 *
 * Search visibility is asserted at both layers rather than through one path: the
 * sealed half against MailboxIndex directly (what the index contains), the
 * unsealed half through listThreads (what a scope returns). The intersection of
 * the two is the same code either way, so this covers the sealed mailbox without
 * needing an unlock window.
 *
 * Run: php plugins/mailbox/tests/mailbox_trash_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_mailbox_search_index_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

$db = DbConnector::get_instance()->get_db_link();

// Retention is read from the setting, so pin it for the whole run (in-memory only).
harness_set_setting_mem('mailbox_trash_retention_days', '30');

// ---- fixtures ------------------------------------------------------------
$owner = make_user('TrashOwner', 5);
$other = make_user('TrashOther', 5);
$owner_id = (int)$owner->key;
$other_id = (int)$other->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'trash-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->set('ied_owner_usr_user_id', $owner_id);
$domain->save();
$domain_id = (int)$domain->key;
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $domain_id);

$make_alias = function ($local) use ($domain_id) {
	$a = new InboundEmailAlias(NULL);
	$a->set('iea_ied_inbound_email_domain_id', $domain_id);
	$a->set('iea_alias', $local);
	$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$a->set('iea_is_enabled', true);
	$a->prepare();
	$a->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$a->key);
	return (int)$a->key;
};
$mine_alias  = $make_alias('mine');
$theirs_alias = $make_alias('theirs');

$grant = function ($alias_id, $user_id) {
	$g = new InboundEmailMailboxGrant(NULL);
	$g->set('ieg_iea_inbound_email_alias_id', $alias_id);
	$g->set('ieg_usr_user_id', $user_id);
	$g->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);
};
$grant($mine_alias, $owner_id);
$grant($theirs_alias, $other_id);

$make_msg = function ($alias_id, $thread_key, $subject, $body, $spam = false) use ($domain_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', $domain_id);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_recipient', 'mine@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', $body);
	$m->set('iem_body_html', '');
	$m->set('iem_thread_key', $thread_key);
	$m->set('iem_message_id_header', 'trash-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	if ($spam) { $m->set('iem_spam_verdict', InboundEmailMessage::SPAM_VERDICT_SPAM); }
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	return (int)$m->key;
};

$viewer = MailboxViewer::forUser($owner_id, 5);
$svc = new MailboxService($viewer);
$other_svc = new MailboxService(MailboxViewer::forUser($other_id, 5));

$keys = function ($result) {
	$out = array();
	foreach ($result['threads'] as $t) { $out[] = $t['thread_key']; }
	return $out;
};
$col = function ($id, $column) use ($db) {
	$q = $db->prepare("SELECT $column FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
	$q->execute(array($id));
	$v = $q->fetchColumn();
	return $v === false ? null : $v;
};
$is_true = function ($v) { return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1); };
$row_exists = function ($id) use ($db) {
	$q = $db->prepare('SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return (bool)$q->fetchColumn();
};

// ---- the trash scope -----------------------------------------------------
section('the Trash scope');

$m_keep  = $make_msg($mine_alias, '<keep@x>', 'Keep me', 'keepbody');
$m_trash = $make_msg($mine_alias, '<gone@x>', 'Throw me away', 'gonebody');
$m_spam  = $make_msg($mine_alias, '<spam@x>', 'Cheap pills', 'spambody', true);

check(count($keys($svc->listThreads($mine_alias, array('trash' => true)))) === 0,
	'Trash starts empty');

$svc->softDelete(array($m_trash));

$inbox = $keys($svc->listThreads($mine_alias, array('inbox' => true)));
check(in_array('<keep@x>', $inbox, true) && !in_array('<gone@x>', $inbox, true),
	'a trashed thread leaves the Inbox view', json_encode($inbox));
$all = $keys($svc->listThreads($mine_alias, array()));
check(!in_array('<gone@x>', $all, true), 'a trashed thread leaves All Mail', json_encode($all));
$spam_view = $keys($svc->listThreads($mine_alias, array('spam' => true)));
check(!in_array('<gone@x>', $spam_view, true), 'a trashed thread leaves the Spam view', json_encode($spam_view));

$trash = $keys($svc->listThreads($mine_alias, array('trash' => true)));
check($trash === array('<gone@x>'), 'the Trash view shows exactly the trashed thread', json_encode($trash));

// Spam-judged mail that gets trashed belongs to Trash, not to both.
$svc->softDelete(array($m_spam));
$trash = $keys($svc->listThreads($mine_alias, array('trash' => true)));
check(in_array('<spam@x>', $trash, true), 'Trash holds spam-judged mail too', json_encode($trash));
check(!in_array('<spam@x>', $keys($svc->listThreads($mine_alias, array('spam' => true))), true),
	'trashed spam has left the Spam view');
$svc->restoreFromTrash(array($m_spam));

// Across a grant boundary.
check(count($keys($other_svc->listThreads(null, array('trash' => true)))) === 0,
	'another mailbox holder sees none of it in their Trash');
check($other_svc->restoreFromTrash(array($m_trash)) === 0, 'they cannot restore it');
check($other_svc->purgeFromTrash(array($m_trash)) === 0, 'they cannot purge it');
check($row_exists($m_trash), 'the message survived both out-of-scope attempts');

// All-access sees it in the all-mail Trash, the way it sees the all-mail Spam.
$super = new MailboxService(MailboxViewer::forUser(0, 10));
check(in_array('<gone@x>', $keys($super->listThreads(null, array('trash' => true))), true),
	'a superadmin All mail Trash includes it');
check(count($keys($super->listThreads(MailboxService::UNMATCHED, array('trash' => true)))) === 0,
	'the Unmatched Trash holds no alias-matched mail');

// ---- the mutation pin ----------------------------------------------------
section('every other mutation refuses a trashed row');

$label = InboundEmailLabel::findOrCreate('TrashTestLabel-' . bin2hex(random_bytes(3)));
harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', (int)$label->key);

check($svc->markRead(array($m_trash), true) === 0, 'markRead refuses it');
check($svc->setStarred(array($m_trash), true) === 0, 'setStarred refuses it');
check($svc->setArchived(array($m_trash), true) === 0, 'setArchived refuses it');
check($svc->setSpamVerdict(array($m_trash), InboundEmailMessage::SPAM_VERDICT_SPAM) === 0,
	'setSpamVerdict refuses it');
check($svc->setMembership(array($m_trash), (int)$label->key, true) === 0, 'setMembership refuses it');
check($svc->softDelete(array($m_trash)) === 0, 'a second trash is a no-op');
check(!$is_true($col($m_trash, 'iem_is_read')) && !$is_true($col($m_trash, 'iem_is_starred')),
	'none of them left a mark on the row');

// ---- getThread -----------------------------------------------------------
section('getThread scope');
check(count($svc->getThread($mine_alias, '<gone@x>')) === 0,
	'the read scope cannot open a trashed conversation');
check(count($svc->getThread($mine_alias, '<gone@x>', true)) === 1,
	'the Trash scope opens it');
check(count($svc->messageIdsInThread($mine_alias, '<gone@x>')) === 0
	&& count($svc->messageIdsInThread($mine_alias, '<gone@x>', true)) === 1,
	'messageIdsInThread expands it under the Trash scope only');

// ---- restore -------------------------------------------------------------
section('restore');

// State to survive the round trip: read, starred, archived, and a label.
$m_state = $make_msg($mine_alias, '<state@x>', 'Stateful', 'statebody');
$svc->markRead(array($m_state), true);
$svc->setStarred(array($m_state), true);
$svc->setArchived(array($m_state), true);
$svc->setMembership(array($m_state), (int)$label->key, true);
$svc->softDelete(array($m_state));
check($svc->restoreFromTrash(array($m_state)) === 1, 'restore reports the row');
check($col($m_state, 'iem_delete_time') === null, 'the delete time is cleared');
check($is_true($col($m_state, 'iem_is_read')) && $is_true($col($m_state, 'iem_is_starred'))
	&& $is_true($col($m_state, 'iem_is_archived')),
	'read, star and archive state survived the round trip');
check(in_array((int)$label->key, $svc->threadFolderIds($mine_alias, '<state@x>'), true),
	'label membership survived the round trip');
check(in_array('<state@x>', $keys($svc->listThreads($mine_alias, array())), true),
	'the message is back in All Mail');
check(count($keys($svc->listThreads($mine_alias, array('trash' => true)))) === 1,
	'and out of Trash');

// ---- purge dates ---------------------------------------------------------
section('purge dates');

$rows = $svc->listThreads($mine_alias, array('trash' => true))['threads'];
$trashed_at = $col($m_trash, 'iem_delete_time');
$expected = LibraryFunctions::time_shift($trashed_at, '30 days', 'Y-m-d');
check(count($rows) === 1 && !empty($rows[0]['purge_time'])
	&& strpos((string)$rows[0]['purge_time'], $expected) === 0,
	'the Trash row carries a purge date 30 days after it was trashed',
	json_encode(array('got' => $rows[0]['purge_time'] ?? null, 'want' => $expected)));
check($svc->listThreads($mine_alias, array('trash' => true))['trash_retention_days'] === 30,
	'the view reports the window');
check(!isset($svc->listThreads($mine_alias, array())['trash_retention_days']),
	'no other view reports one');

harness_set_setting_mem('mailbox_trash_retention_days', '0');
$off = $svc->listThreads($mine_alias, array('trash' => true));
check($off['threads'][0]['purge_time'] === null && $off['trash_retention_days'] === 0,
	'retention 0 means no purge date at all');
harness_set_setting_mem('mailbox_trash_retention_days', '30');

// ---- purge reclaims ------------------------------------------------------
section('delete forever reclaims');

$m_att = $make_msg($mine_alias, '<att@x>', 'With a file', 'attbody');
$file = File::createFromBytes('trash test bytes', 'trash.txt', 'text/plain', $owner_id,
	array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
$file_id = (int)$file->key;
$db->prepare("INSERT INTO ima_inbound_message_attachments
	(ima_iem_inbound_email_message_id, ima_filename, ima_content_type, ima_size_bytes,
	 ima_is_inline, ima_fil_file_id)
	VALUES (?, ?, ?, ?, 'f', ?)")
	->execute(array($m_att, 'trash.txt', 'text/plain', 16, $file_id));

// An index row for the owner, so the purge has somewhere to queue the refold.
$bk = InboundMailboxSearchIndex::loadOrCreateForUser($owner_id);
harness_register_row('imi_inbound_mailbox_search_index', 'imi_inbound_mailbox_search_index_id', (int)$bk->key);
$bk->set('imi_refold_ids', null);
$bk->save();

$svc->softDelete(array($m_att));
check($svc->purgeFromTrash(array($m_att)) === 1, 'purge reports the message');
check(!$row_exists($m_att), 'the message row is gone');
$att_left = $db->prepare('SELECT COUNT(*) FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = ?');
$att_left->execute(array($m_att));
check((int)$att_left->fetchColumn() === 0, 'the attachment manifest row is gone');
$fil_left = $db->prepare('SELECT COUNT(*) FROM fil_files WHERE fil_file_id = ?');
$fil_left->execute(array($file_id));
check((int)$fil_left->fetchColumn() === 0, 'the attachment File is gone (bytes reclaimed)');
$queued = json_decode((string)InboundMailboxSearchIndex::loadOrCreateForUser($owner_id)->get('imi_refold_ids'), true);
check(is_array($queued) && in_array($m_att, array_map('intval', $queued), true),
	'the purged id was queued for refold', json_encode($queued));

// ---- a sealed mailbox purges locked -------------------------------------
section('a sealed message purges with the vault locked');

$box = new SealedBox();
$kp = $box->generateKeypair();
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $owner_id);
$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
$vault->set('uev_public_key', $kp['public']);
$vault->set('uev_salt', $box->generateSalt());
$vault->set('uev_key_generation', 1);
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

$m_sealed = $make_msg($mine_alias, '<sealed@x>', 'subject', 'plain');
InboundEmailMessage::sealAndPersistContent($m_sealed, $vault, 'sender@example.com',
	'mine@example.com', 'Sealed subject', 'sealed body text', '<p>sealed body text</p>');
check($is_true($col($m_sealed, 'iem_content_sealed')), 'the message is sealed at rest');
check(VaultUnlock::secretKey($owner_id) === null, 'no unlock window is open');

$svc->softDelete(array($m_sealed));
$purged_sealed = 0;
try {
	$purged_sealed = $svc->purgeFromTrash(array($m_sealed));
} catch (\Throwable $e) {
	check(false, 'purging a sealed message threw', $e->getMessage());
}
check($purged_sealed === 1 && !$row_exists($m_sealed),
	'a sealed message purges with no vault window — permanent_delete never reads plaintext');

// ---- the search index holds everything ----------------------------------
section('the index holds trashed mail; the scope decides');

if (!is_dir(MailboxIndex::SHM_DIR)) {
	harness_skip('index coverage', MailboxIndex::SHM_DIR . ' unavailable');
} else {
	$idx = new MailboxIndex();
	$idx->wipe($owner_id);
	InboundMailboxSearchIndex::loadOrCreateForUser($owner_id)->set('imi_fts_high_water', 0);

	// The regression Change 2a exists for: a message trashed BEFORE its first fold,
	// then restored. The watermark advances past every row the pass saw, so a fold
	// that skipped trashed rows could never come back for this one.
	$m_early = $make_msg($mine_alias, '<early@x>', 'Early', 'earlykeyword');
	$svc->softDelete(array($m_early));
	$m_later = $make_msg($mine_alias, '<later@x>', 'Later', 'laterkeyword');

	$idx->rebuild($owner_id, 'dummy-secret');
	check($idx->search($owner_id, 'laterkeyword') === array($m_later),
		'an ordinary message is indexed');
	check($idx->search($owner_id, 'earlykeyword') === array($m_early),
		'a message trashed before its first fold is indexed too',
		json_encode($idx->search($owner_id, 'earlykeyword')));

	$hw = (int)InboundMailboxSearchIndex::loadOrCreateForUser($owner_id)->get('imi_fts_high_water');
	check($hw >= $m_later, 'the watermark advanced past both', "hw=$hw later=$m_later");

	$svc->restoreFromTrash(array($m_early));
	$idx->fold($owner_id, 'dummy-secret');
	check($idx->search($owner_id, 'earlykeyword') === array($m_early),
		'restore-then-search finds it, with no index bookkeeping at all');

	// Read scope still decides what a search returns, on the Postgres path.
	$svc->softDelete(array($m_early));
	$found_inbox = $keys($svc->listThreads($mine_alias, array('q' => 'earlykeyword', 'inbox' => true)));
	check(!in_array('<early@x>', $found_inbox, true),
		'searching the Inbox never returns trashed mail', json_encode($found_inbox));
	$found_all = $keys($svc->listThreads($mine_alias, array('q' => 'earlykeyword')));
	check(!in_array('<early@x>', $found_all, true), 'nor does searching All Mail', json_encode($found_all));
	$found_trash = $keys($svc->listThreads($mine_alias, array('q' => 'earlykeyword', 'trash' => true)));
	check($found_trash === array('<early@x>'), 'searching inside Trash finds it', json_encode($found_trash));

	// Purge prunes because the row is gone, not because a flag says so.
	$svc->purgeFromTrash(array($m_early));
	$idx->fold($owner_id, 'dummy-secret');
	check($idx->search($owner_id, 'earlykeyword') === array(),
		'the purged message left the index at the next fold',
		json_encode($idx->search($owner_id, 'earlykeyword')));

	$idx->wipe($owner_id);
}

// ---- the retention rule --------------------------------------------------
// Trash purging is declared as InboundEmailMessage::$retention_policy and run by
// the platform's daily Retention Sweep. The window is the setting the mail
// reader already shows members as each message's purge date.
section('Trash retention rule');

$policy = InboundEmailMessage::$retention_policy;
check(($policy['window_setting'] ?? null) === 'mailbox_trash_retention_days',
	'the rule reads the same setting the reader shows members', json_encode($policy));
check(is_callable(array('InboundEmailMessage', $policy['purge_method'] ?? '')),
	'the declared purge method exists on the class');

$old_one = $make_msg($mine_alias, '<old1@x>', 'Old one', 'oldbody1');
$old_two = $make_msg($mine_alias, '<old2@x>', 'Old two', 'oldbody2');
$recent  = $make_msg($mine_alias, '<recent@x>', 'Recent', 'recentbody');
$svc->softDelete(array($old_one, $old_two, $recent));
$db->exec("UPDATE iem_inbound_email_messages
	SET iem_delete_time = now() - interval '40 days'
	WHERE iem_inbound_email_message_id IN ($old_one, $old_two)");

// A window of 0 means never purge. The sweep enforces that by not calling the
// rule at all, so the guarantee is tested where it lives.
harness_set_setting_mem('mailbox_trash_retention_days', '0');
require_once(PathHelper::getIncludePath('tasks/RetentionSweep.php'));
$sweep = new RetentionSweep();
$sweep->run(array());
check($row_exists($old_one) && $row_exists($old_two),
	'a window of 0 means never purge — the sweep skips the rule entirely');

harness_set_setting_mem('mailbox_trash_retention_days', '30');
$res = InboundEmailMessage::purgeExpiredTrash(30);
check(isset($res['removed']) && isset($res['message']),
	'the purge method returns the removed/message contract', json_encode($res));
check((int)$res['removed'] === 2, 'both past-window messages were counted', json_encode($res));
check(!$row_exists($old_one) && !$row_exists($old_two), 'both past-window messages are gone');
check($row_exists($recent), 'a message trashed today is untouched');

harness_finish();
