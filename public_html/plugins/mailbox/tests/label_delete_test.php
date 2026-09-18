<?php
/** @joinery-test
 * name: label_delete
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Deleting a custom label from the reader's Labels panel.
 *
 * A label is one shared name across the site, so deleting it has to reach every
 * message that carries it and every feed binding that mirrors it — and reach
 * NOTHING else. The things worth pinning:
 *
 *  1. The messages stay. Deleting a label is not deleting mail: every message
 *     that carried the label is still there, only its membership row is gone.
 *  2. The label leaves every mailbox. The switcher no longer lists it, and the
 *     ilb_ row is soft-deleted.
 *  3. A feed binding is unbound and untracked, so the next sync neither
 *     re-materializes memberships nor re-mints the label from the folder name.
 *     A folder still pending its remote CREATE is never created.
 *  4. The name is free again: the same name afterwards makes a fresh label.
 *  5. Scope: a viewer with no grant on the mailbox is refused; a filter that
 *     applies the label keeps working (the label is simply skipped).
 *  6. The API action (delete_label) needs no message targets, and refuses an
 *     unknown or already-deleted label.
 *
 * Sessions are simulated with SessionControl::set_api_user, so the logic runs
 * exactly as it would behind /api/v1.
 *
 * Run: php plugins/mailbox/tests/label_delete_test.php  (schema synced).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/thread_action_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();
$suffix = bin2hex(random_bytes(3));

// ── Fixtures ─────────────────────────────────────────────────────────────────
$granted = make_user('LblGranted', 1);
$other   = make_user('LblOther', 1);
$granted_uid = (int)$granted->key;
$other_uid   = (int)$other->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'lbl-' . $suffix . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$mk_alias = function ($name) use ($domain) {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
	$alias->set('iea_alias', $name);
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_is_enabled', true);
	$alias->prepare();
	$alias->save();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
	return (int)$alias->key;
};
$local_alias = $mk_alias('local');   // no feed: labels are pure membership
$feed_alias  = $mk_alias('feed');    // an IMAP feed: labels are bound to folders

foreach (array($local_alias, $feed_alias) as $aid) {
	$grant = new InboundEmailMailboxGrant(NULL);
	$grant->set('ieg_iea_inbound_email_alias_id', $aid);
	$grant->set('ieg_usr_user_id', $granted_uid);
	$grant->save();
	harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);
}

$account = new InboundImapAccount(NULL);
$account->set('iia_label', 'LblFeed ' . $suffix);
$account->set('iia_provider_key', 'imap_generic');
$account->set('iia_imap_host', 'imap.test');
$account->set('iia_iea_inbound_email_alias_id', $feed_alias);
$account->set('iia_username', 'harnesstest_lbl@lbl-' . $suffix . '.example');
$account->set('iia_is_enabled', true);
$account->set('iia_sync_mode', 'both');
$account->set('iia_folders_exclusive', false);
$account->save();
harness_register_row('iia_inbound_imap_accounts', 'iia_inbound_imap_account_id', (int)$account->key);

$mk_message = function ($alias_id, $subject) use ($domain) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_direction', 'inbound');
	$m->set('iem_sender', 'sender@example.test');
	$m->set('iem_recipient', 'inbox@x');
	$m->set('iem_subject', $subject);
	$mid = '<lbl-' . bin2hex(random_bytes(4)) . '@x>';
	$m->set('iem_message_id_header', $mid);
	$m->set('iem_thread_key', $mid);
	$m->save();
	harness_register_model('InboundEmailMessage', (int)$m->key);
	return (int)$m->key;
};

$label_live = function ($id) use ($db) {
	$stmt = $db->prepare('SELECT ilb_delete_time FROM ilb_inbound_email_labels WHERE ilb_inbound_email_label_id = ?');
	$stmt->execute(array($id));
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row === false ? null : ($row['ilb_delete_time'] === null);
};
$member_count = function ($label_id) use ($db) {
	$stmt = $db->prepare('SELECT COUNT(*) FROM ilm_inbound_label_members WHERE ilm_ilb_inbound_email_label_id = ?');
	$stmt->execute(array($label_id));
	return (int)$stmt->fetchColumn();
};
$message_exists = function ($id) use ($db) {
	$stmt = $db->prepare('SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ? AND iem_delete_time IS NULL');
	$stmt->execute(array($id));
	return (bool)$stmt->fetchColumn();
};
$switcher_lists = function ($service, $alias_id, $label_id) {
	foreach ($service->listMailboxes()['mailboxes'] as $m) {
		if ((int)$m['alias_id'] !== $alias_id) { continue; }
		foreach ($m['folders'] as $f) {
			if ((int)$f['id'] === $label_id) { return true; }
		}
	}
	return false;
};

$session->set_api_user($granted_uid);
$service = new MailboxService(MailboxViewer::fromSession($session));

// ── 1 + 2: a local label — messages stay, the label leaves every mailbox ──────
section('A local label is deleted: memberships gone, messages kept, gone from the rail');

$name = 'Receipts ' . $suffix;
$m1 = $mk_message($local_alias, 'one');
$m2 = $mk_message($local_alias, 'two');
$made = $service->createFolder($local_alias, $name);
check($made !== null && $made['name'] === $name, 'the label is created through the reader\'s own path');
$label_id = (int)$made['id'];
harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', $label_id);
$service->setMembership(array($m1, $m2), $label_id, true);
check($member_count($label_id) === 2, 'both messages carry the label');
check($switcher_lists($service, $local_alias, $label_id), 'the switcher lists the label');

// The list row says what the thread carries (label_ids), agreeing with what
// the thread endpoint reports (folders) — the selection's Labels panel reads
// the former, the open thread's the latter.
$row_labels = function ($service, $alias_id, $thread_key) {
	foreach ($service->listThreads($alias_id, array(), 1, 50)['threads'] as $t) {
		if ($t['thread_key'] === $thread_key) { return $t['label_ids']; }
	}
	return null;
};
$stmt = $db->prepare('SELECT iem_thread_key FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
$stmt->execute(array($m1));
$m1_key = (string)$stmt->fetchColumn();
check($row_labels($service, $local_alias, $m1_key) === array($label_id),
	'the list row carries the label id', json_encode($row_labels($service, $local_alias, $m1_key)));
check($service->threadFolderIds($local_alias, $m1_key) === array($label_id),
	'and the thread endpoint reports the same label');
$service->setMembership(array($m1), $label_id, false);
check($row_labels($service, $local_alias, $m1_key) === array(), 'taking the label off empties the row\'s label_ids');
$service->setMembership(array($m1), $label_id, true);

$deleted = $service->deleteLabel($local_alias, $label_id);
check(is_array($deleted) && $deleted['name'] === $name, 'deleteLabel returns the label\'s name', json_encode($deleted));
check(is_array($deleted) && (int)$deleted['messages'] === 2, 'and how many messages carried it');
check($label_live($label_id) === false, 'the ilb_ row is soft-deleted');
check($member_count($label_id) === 0, 'every membership row is gone');
check($message_exists($m1) && $message_exists($m2), 'the messages themselves are untouched');
check(!$switcher_lists($service, $local_alias, $label_id), 'the switcher no longer lists the label');
check($service->deleteLabel($local_alias, $label_id) === null, 'deleting it again is refused (already deleted)');

// ── 4: the name is free again ────────────────────────────────────────────────
section('The same name afterwards makes a fresh label');
$again = $service->createFolder($local_alias, $name);
check($again !== null && (int)$again['id'] !== $label_id, 'a new ilb_ row, not the deleted one', json_encode($again));
if ($again !== null) {
	harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', (int)$again['id']);
	check($member_count((int)$again['id']) === 0, 'and it starts with no members');
}

// ── 3: a feed binding is unbound and untracked ───────────────────────────────
section('A label bound to a feed folder: the binding is unbound and untracked');

$fname = 'Work ' . $suffix;
$bound = $service->createFolder($feed_alias, $fname);
check($bound !== null, 'the label is created on the feed mailbox');
$bound_id = (int)$bound['id'];
harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', $bound_id);
$folders = new MultiInboundImapFolder(array('label_id' => $bound_id));
$folders->load();
check(count($folders) === 1, 'one folder binds it');
$folder = new InboundImapFolder($folders->get(0)->key, TRUE);
harness_register_row('iif_inbound_imap_folders', 'iif_inbound_imap_folder_id', (int)$folder->key);
check((bool)$folder->get('iif_is_tracked') && (bool)$folder->get('iif_pending_remote_create'),
	'the binding is tracked and pending its remote CREATE');
$m3 = $mk_message($feed_alias, 'three');
$service->setMembership(array($m3), $bound_id, true);
check($member_count($bound_id) === 1, 'a feed message carries the label');

$deleted = $service->deleteLabel($feed_alias, $bound_id);
check(is_array($deleted), 'the bound label is deleted');
$folder = new InboundImapFolder((int)$folder->key, TRUE);
check($folder->key && !(bool)$folder->get('iif_is_tracked'), 'the folder is untracked');
check($folder->get('iif_ilb_inbound_email_label_id') === null || intval($folder->get('iif_ilb_inbound_email_label_id')) === 0,
	'and unbound from the label', var_export($folder->get('iif_ilb_inbound_email_label_id'), true));
check(!(bool)$folder->get('iif_pending_remote_create'), 'a never-created remote folder is no longer pending');
check($member_count($bound_id) === 0 && $message_exists($m3), 'the membership is gone and the message stays');
check(!$switcher_lists($service, $feed_alias, $bound_id), 'the feed mailbox no longer lists it');

// The folder still exists (untracked) on the account; rediscovery keeps it that
// way, so mailboxFolderInfo must not re-mint the label from its name.
$rediscovered = InboundImapFolder::upsert((int)$account->key, $fname, null, true);
check((int)$rediscovered->key === (int)$folder->key && !(bool)$rediscovered->get('iif_is_tracked'),
	'rediscovery of the folder keeps it untracked');
check(!$switcher_lists($service, $feed_alias, $bound_id) && InboundEmailLabel::getByName($fname) === null,
	'and nothing re-mints the label from the folder name');

// ── 5: scope ─────────────────────────────────────────────────────────────────
section('Scope: an ungranted viewer is refused');
$scoped = 'Private ' . $suffix;
$made = $service->createFolder($local_alias, $scoped);
$scoped_id = (int)$made['id'];
harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', $scoped_id);

$session->set_api_user($other_uid);
$stranger = new MailboxService(MailboxViewer::fromSession($session));
check($stranger->deleteLabel($local_alias, $scoped_id) === null, 'no grant on the mailbox: refused');
check($label_live($scoped_id) === true, 'and the label is still live');

// ── 6: the API action ────────────────────────────────────────────────────────
section('The delete_label action');
$r = process_logic_result(thread_action_logic(array(
	'action' => 'delete_label', 'alias_id' => (string)$local_alias, 'folder_id' => (string)$scoped_id)));
check($r['ok'] === false, 'the ungranted viewer is refused through the action too', json_encode($r));

$session->set_api_user($granted_uid);
$r = process_logic_result(thread_action_logic(array(
	'action' => 'delete_label', 'alias_id' => (string)$local_alias, 'folder_id' => (string)$scoped_id)));
check($r['ok'] === true && isset($r['data']['label']) && $r['data']['label']['name'] === $scoped,
	'the granted viewer deletes it with no message targets', json_encode($r));
check($label_live($scoped_id) === false, 'and the row is soft-deleted');

$r = process_logic_result(thread_action_logic(array(
	'action' => 'delete_label', 'alias_id' => (string)$local_alias, 'folder_id' => '999999999')));
check($r['ok'] === false, 'an unknown label is refused', json_encode($r));

harness_finish();

/** Flatten a LogicResult into ok/data/error for the checks above. */
function process_logic_result(LogicResult $r): array {
	return array(
		'ok'    => empty($r->error),
		'data'  => is_array($r->data) ? $r->data : array(),
		'error' => $r->error,
	);
}
?>
