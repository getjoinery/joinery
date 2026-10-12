<?php
/** @joinery-test
 * name: mail_apps_folders
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Mail-app folders (specs/mail_apps_plugin.md § Folders, § Message numbers,
 * § How Thunderbird learns what changed), on a Standard mailbox.
 *
 * Covers:
 *  - the folder list: the fixed folders with their special uses, a label
 *    folder, and a label named like a fixed folder moved under Labels/
 *  - numbers only rise: archive then unarchive, a label added late, trash
 *    then restore — each re-entry is numbered at or above the folder's next
 *    number, and no number is ever handed out twice
 *  - changes are found by comparison: a read/star change made through mailbox
 *    directly (as the web reader does), while no program is connected, is
 *    reported by the next sync since the old change number
 *  - what a program's actions do: STORE flags to mailbox and \Answered to the
 *    plugin's row; MOVE to Trash trashes; MOVE into a label from Inbox labels
 *    and archives; EXPUNGE in a label takes the label off and leaves the
 *    message; EXPUNGE in Trash deletes for good
 *  - APPEND to Inbox stores the message and numbers it
 *  - UID sets: ranges, *, reversed ranges
 *
 * Run: php -d apc.enable_cli=1 plugins/mail_apps/tests/mail_apps_folders_test.php
 *
 * @version 1.1 - APPEND to INBOX answers its number (APPENDUID)
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('MailAppContext')) {
	harness_skip('the mail_apps plugin is not active here', 'activate it, then run this suite');
	harness_finish();
}
require_once(__DIR__ . '/lib/mail_apps_fixtures.php');
harness_set_setting_mem('mail_apps_enabled', '1'); // the purpose's switch (AppAccess requiresSetting)

$db = DbConnector::get_instance()->get_db_link();
$user = make_user('MailAppsFolders', 0);
$uid = (int)$user->key;
$domain = mafx_domain();
$alias = mafx_alias($domain, 'reader', $uid);
$address = strtolower($alias->get_full_address());
harness_defer(function () { SessionControl::get_instance()->clear_api_user(); });

$m1 = mafx_deliver($alias, $domain, mafx_raw($address, 'First', 'one'));
$m2 = mafx_deliver($alias, $domain, mafx_raw($address, 'Second', 'two'));
$m3 = mafx_deliver($alias, $domain, mafx_raw($address, 'Third', 'three'));

$ctx = mafx_context($uid, $address);
$inbox = MailAppFolders::byKey($ctx, 'inbox');
$archive = MailAppFolders::byKey($ctx, 'archive');
$trash = MailAppFolders::byKey($ctx, 'trash');

section('The folder list');
$names = array_column(MailAppFolders::listFor($ctx), 'special_use', 'name');
foreach (array('INBOX' => null, 'Sent' => '\\Sent', 'Drafts' => '\\Drafts', 'Archive' => '\\Archive',
		'Spam' => '\\Junk', 'Trash' => '\\Trash') as $name => $use) {
	check(array_key_exists($name, $names) && $names[$name] === $use, "$name is listed" . ($use ? " as $use" : ''));
}
check(!array_key_exists('All Mail', $names) && MailAppFolders::byKey($ctx, 'all') === null,
	'There is no All Mail (a program would store every message twice)');
$label = $ctx->service->createFolder($ctx->alias_id, 'Projects ' . bin2hex(random_bytes(3)));
harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', (int)$label['id']);
$collide = $ctx->service->createFolder($ctx->alias_id, 'Archive');
if ($collide) {
	harness_register_row('ilb_inbound_email_labels', 'ilb_inbound_email_label_id', (int)$collide['id']);
}
$names = array_column(MailAppFolders::listFor($ctx), 'key', 'name');
check(($names[$label['name']] ?? '') === 'label:' . $label['id'], 'A label is a folder of its own');
check($collide && ($names['Labels/Archive'] ?? '') === 'label:' . $collide['id'], 'A label named Archive is listed as Labels/Archive');
check(MailAppFolders::byName($ctx, 'inbox')['key'] === 'inbox', 'INBOX matches in any case');
$label_folder = MailAppFolders::byKey($ctx, 'label:' . $label['id']);

section('Numbering in arrival order');
$state = MailAppFolders::reconcile($ctx, $inbox);
check($state['exists'] === 3 && $state['numbered'] === 3, 'Three messages numbered', json_encode($state));
check(array_values(mafx_live($ctx, 'inbox')) === array($m1, $m2, $m3), 'In arrival order');
check($state['uidnext'] === max(array_keys(mafx_live($ctx, 'inbox'))) + 1, 'UIDNEXT is one past the highest');
$validity = $state['uidvalidity'];
check(MailAppFolders::reconcile($ctx, $inbox)['numbered'] === 0, 'A second reconcile numbers nothing');

section('Numbers only rise');
$before = MailAppFolders::reconcile($ctx, $inbox)['uidnext'];
$ctx->service->setArchived(array($m1), true);
$state = MailAppFolders::reconcile($ctx, $inbox);
check(!in_array($m1, mafx_live($ctx, 'inbox'), true), 'Archiving takes it out of INBOX');
check(count($state['expunged']) === 1, 'Its INBOX number is expunged');
MailAppFolders::reconcile($ctx, $archive);
check(in_array($m1, mafx_live($ctx, 'archive'), true), 'And numbers it in Archive');
$ctx->service->setArchived(array($m1), false);
MailAppFolders::reconcile($ctx, $inbox);
$back = array_search($m1, mafx_live($ctx, 'inbox'), true);
check($back !== false && $back >= $before, 'Back in INBOX it gets a new number, at or above the old UIDNEXT', "uid $back, uidnext was $before");
$all = mafx_all_uids($ctx, 'inbox');
check(count($all) === count(array_unique($all)), 'No INBOX number was handed out twice');

$before = MailAppFolders::reconcile($ctx, $label_folder)['uidnext'];
$ctx->service->setMembership(array($m2), (int)$label['id'], true);
MailAppFolders::reconcile($ctx, $label_folder);
$late = array_search($m2, mafx_live($ctx, $label_folder['key']), true);
check($late !== false && $late >= $before, 'A label added late numbers the message in the label folder');

$before = MailAppFolders::reconcile($ctx, $inbox)['uidnext'];
$ctx->service->softDelete(array($m3));
MailAppFolders::reconcile($ctx, $inbox);
check(!in_array($m3, mafx_live($ctx, 'inbox'), true), 'Trash takes it out of INBOX');
$ctx->service->restoreFromTrash(array($m3));
MailAppFolders::reconcile($ctx, $inbox);
$restored = array_search($m3, mafx_live($ctx, 'inbox'), true);
check($restored !== false && $restored >= $before, 'Restored, it is numbered again above the old UIDNEXT');
check(MailAppFolders::reconcile($ctx, $inbox)['uidvalidity'] === $validity, 'UIDVALIDITY never changed');

section('Changes are found by comparison');
$mod = MailAppFolders::reconcile($ctx, $inbox)['highest_modseq'];
$ctx->service->markRead(array($m2), true);
$ctx->service->setStarred(array($m2), true);
$state = MailAppFolders::reconcile($ctx, $inbox);
check($state['highest_modseq'] > $mod, 'The folder\'s change number rose');
$changed = MailAppFolders::rows($ctx, $inbox, $mod)['messages'];
$row = $changed ? $changed[0] : array();
check(count($changed) === 1 && in_array('\\Seen', $row['flags'], true) && in_array('\\Flagged', $row['flags'], true),
	'Only the changed message is reported since the old number, with \\Seen and \\Flagged', json_encode($changed));
check(MailAppFolders::rows($ctx, $inbox, $state['highest_modseq'])['messages'] === array(), 'Nothing since the new number');

section('What a program\'s actions do');
$uid_of = function ($folder, $mid) use ($ctx) { return array_search($mid, mafx_live($ctx, $folder['key']), true); };
MailAppMutations::store($ctx, $inbox, array($uid_of($inbox, $m3)), 'add', array('\\Flagged', '\\Answered'));
check($db->query("SELECT iem_is_starred FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $m3")->fetchColumn(),
	'STORE \\Flagged stars it in mailbox');
check($db->query("SELECT mam_is_answered FROM mam_mail_app_messages WHERE mam_iem_inbound_email_message_id = $m3")->fetchColumn(),
	'STORE \\Answered is kept on the plugin\'s row');
MailAppMutations::store($ctx, $inbox, array($uid_of($inbox, $m3)), 'remove', array('\\Flagged'));
check(!$db->query("SELECT iem_is_starred FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $m3")->fetchColumn(),
	'STORE -FLAGS \\Flagged unstars it');

$placed = MailAppMutations::place($ctx, $inbox, array($uid_of($inbox, $m1)), $label_folder, true);
check(in_array($m1, mafx_live($ctx, $label_folder['key']), true), 'MOVE into a label adds the label');
check(!in_array($m1, mafx_live($ctx, 'inbox'), true), 'and, from INBOX, archives it');
check(count($placed['map']) === 1, 'The destination number comes back for COPYUID');

$m2_label_uid = $uid_of($label_folder, $m2);
MailAppMutations::store($ctx, $label_folder, array($m2_label_uid), 'add', array('\\Deleted'));
$gone = MailAppMutations::expunge($ctx, $label_folder);
check($gone === array($m2_label_uid), 'EXPUNGE in a label expunges that number');
check(!InboundLabelMember::isMember($m2, (int)$label['id']), 'It takes the label off');
check(!$db->query("SELECT iem_delete_time FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $m2")->fetchColumn(),
	'and leaves the message where it was');

MailAppMutations::place($ctx, $inbox, array($uid_of($inbox, $m2)), $trash, true);
check((bool)$db->query("SELECT iem_delete_time FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $m2")->fetchColumn(),
	'MOVE to Trash trashes it');
MailAppFolders::reconcile($ctx, $trash);
$t = $uid_of($trash, $m2);
MailAppMutations::store($ctx, $trash, array($t), 'add', array('\\Deleted'));
MailAppMutations::expunge($ctx, $trash);
check(!$db->query("SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $m2")->fetchColumn(),
	'EXPUNGE in Trash deletes it for good');

section('Junk is a move to Spam, never a keyword');
$m4 = mafx_deliver($alias, $domain, mafx_raw($address, 'Maybe spam', 'buy now'));
MailAppFolders::reconcile($ctx, $inbox);
MailAppMutations::store($ctx, $inbox, array($uid_of($inbox, $m4)), 'add', array('$Junk'));
$verdict = function () use ($db, $m4) {
	return $db->query("SELECT iem_spam_verdict FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $m4")->fetchColumn();
};
check($verdict() !== 'spam', 'STORE $Junk sets no verdict (a program\'s own classifier sets it on mail nobody touched)');
$spam = MailAppFolders::byKey($ctx, 'spam');
MailAppMutations::place($ctx, $inbox, array($uid_of($inbox, $m4)), $spam, true);
check($verdict() === 'spam', 'A move to Spam marks it spam, as the reader\'s button does');
MailAppFolders::reconcile($ctx, $spam);
MailAppMutations::place($ctx, $spam, array($uid_of($spam, $m4)), $inbox, true);
check($verdict() === 'ham', 'A move out of Spam marks it not spam');

section('APPEND to INBOX');
$before = mafx_live($ctx, 'inbox');
$appended = MailAppMutations::append($ctx, $inbox, mafx_raw($address, 'Appended', 'from a program'), array('\\Seen'), null);
$new = array_diff_key(mafx_live($ctx, 'inbox'), $before);
$app_mid = $new ? reset($new) : null;
if ($app_mid) {
	harness_register_model('InboundEmailMessage', $app_mid);
}
check(count($new) === 1 && $app_mid !== null, 'It is stored and numbered');
check($app_mid !== null && $appended['uid'] === array_search($app_mid, mafx_live($ctx, 'inbox'), true),
	'It answers the number the message has in the Inbox (APPENDUID)', json_encode($appended));
check($app_mid && $db->query("SELECT iem_is_read FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $app_mid")->fetchColumn(),
	'With the \\Seen flag it was appended with');

section('APPEND to a label');
$before = mafx_live($ctx, $label_folder['key']);
MailAppMutations::append($ctx, $label_folder, mafx_raw($address, 'Into the label', 'filed'), array(), null);
$new = array_diff_key(mafx_live($ctx, $label_folder['key']), $before);
$lab_mid = $new ? reset($new) : null;
if ($lab_mid) { harness_register_model('InboundEmailMessage', $lab_mid); }
check($lab_mid !== null && InboundLabelMember::isMember($lab_mid, (int)$label['id']), 'It is stored and carries the label');
check($lab_mid && (bool)$db->query("SELECT iem_is_archived FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $lab_mid")->fetchColumn(),
	'and is out of the Inbox, as a move into a label is');

section('UID sets');
$live = array_keys(mafx_live($ctx, 'inbox'));
check(MailAppAction::uidSet($ctx, $inbox, '1:*') === $live, '1:* is every live number');
check(MailAppAction::uidSet($ctx, $inbox, '*') === array(max($live)), '* is the highest');
check(MailAppAction::uidSet($ctx, $inbox, '*:1') === $live, 'A range may be written either way round');
$threw = false;
try { MailAppAction::uidSet($ctx, $inbox, '1,,x'); } catch (MailAppRefusal $e) { $threw = $e->imap_code === 'CLIENTBUG'; }
check($threw, 'A malformed set is refused');

section('Another member cannot open this mailbox');
$other = make_user('MailAppsStranger', 0);
$refused = false;
try { mafx_context((int)$other->key, $address); } catch (MailAppRefusal $e) { $refused = $e->imap_code === 'AUTHORIZATIONFAILED'; }
check($refused, 'Without a grant, the mailbox is refused');

harness_finish();
