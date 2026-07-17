<?php
/** @joinery-test
 * name: drafts_fts_refold
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Compose maturity fix pack Fix 6 — a draft that morphs IN PLACE into its Sent row
 * keeps its message id, which is at-or-below the FTS high-water mark, so the plain
 * `id > since` fold never revisits it. The per-user refold queue (imi_refold_ids)
 * drives an explicit delete-and-reinsert so the sent message becomes searchable.
 *
 * Uses an unsealed owner: MailboxIndex reads content through the same get() hook for
 * sealed and never-sealed rows, so the refold mechanics are identical and the test
 * needs no vault/unlock window. persist() is a no-op without a vault (the /dev/shm
 * working copy is the ground truth the search reads).
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
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDrafts.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

if (!is_dir(MailboxIndex::SHM_DIR)) {
	// No tmpfs (unusual CI shape) — the index is /dev/shm-only, so skip cleanly.
	section('refold queue');
	check(true, 'skipped: ' . MailboxIndex::SHM_DIR . ' unavailable', 'no shm');
	harness_finish();
	return;
}

$db = DbConnector::get_instance()->get_db_link();

$owner = make_user('FtsRefold', 5);
$uid = (int)$owner->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'fts-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'fts');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias_id = (int)$alias->key;
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $alias_id);

$g = new InboundEmailMailboxGrant(NULL);
$g->set('ieg_iea_inbound_email_alias_id', $alias_id);
$g->set('ieg_usr_user_id', $uid);
$g->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);

$make_msg = function ($direction, $subject, $body) use ($domain, $alias_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', $direction);
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_recipient', 'fts@example.com');
	$m->set('iem_subject', $subject);
	$m->set('iem_body_plain', $body);
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', 'fts-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', (int)$m->key);
	return (int)$m->key;
};

$viewer = MailboxViewer::forUser($uid, 5);
$drafts = new MailboxDrafts($viewer);
$idx = new MailboxIndex();
$idx->wipe($uid); // start from a clean working copy

section('refold queue');

// M1 (indexed), then a DRAFT (excluded), then M2 with a higher id (indexed). Folding
// advances the watermark past the draft's id via M2.
$m1 = $make_msg('outbound', 'First', 'alpha uniquekwone');
$draft_id = intval($drafts->saveDraft(array('alias_id' => $alias_id, 'mode' => 'new',
	'to' => 'x@y.com', 'subject' => 'A draft', 'body_html' => '<p>draftbody kwdraft</p>'))['draft_id']);
harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $draft_id);
$m2 = $make_msg('inbound', 'Third', 'beta uniquekwtwo');

$idx->fold($uid, 'dummy-secret');
check($idx->search($uid, 'uniquekwone') === array($m1), 'first message is searchable after fold', json_encode($idx->search($uid, 'uniquekwone')));
check($idx->search($uid, 'kwdraft') === array(), 'the draft is NOT indexed');

$hw = intval(InboundMailboxSearchIndex::loadOrCreateForUser($uid)->get('imi_fts_high_water'));
check($hw >= $m2 && $hw > $draft_id, 'high-water advanced past the draft id', "hw=$hw draft=$draft_id m2=$m2");

// The draft morphs IN PLACE into a Sent row (same id), the way storeOutboundRow does.
InboundEmailMessage::updateColumns($draft_id, array(
	'iem_direction' => 'outbound', 'iem_draft_state' => null, 'iem_draft_author_user_id' => null,
	'iem_body_plain' => 'morphedbody kwmorph', 'iem_subject' => 'Now sent',
));

// Without the queue, a re-fold would never revisit id ≤ watermark. enqueueRefold (in
// MailboxSender) records it; here we set the same column the send path writes.
$bk = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
$bk->set('imi_refold_ids', json_encode(array($draft_id)));
$bk->save();

$idx->fold($uid, 'dummy-secret');
check($idx->search($uid, 'kwmorph') === array($draft_id), 'the morphed Sent row is searchable after the refold', json_encode($idx->search($uid, 'kwmorph')));

$after = InboundMailboxSearchIndex::loadOrCreateForUser($uid);
check($after->get('imi_refold_ids') === null, 'imi_refold_ids cleared after the refold cycle', json_encode($after->get('imi_refold_ids')));

// Exactly one FTS row for the morphed id (no duplicate from delete-and-reinsert).
$shm = new SQLite3($idx->shmPath($uid), SQLITE3_OPEN_READONLY);
$rows = intval($shm->querySingle('SELECT COUNT(*) FROM mailfts WHERE message_id = ' . $draft_id));
$shm->close();
check($rows === 1, 'exactly one FTS row for the morphed message id', "rows=$rows");

$idx->wipe($uid);
harness_finish();
