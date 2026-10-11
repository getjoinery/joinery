<?php
/** @joinery-test
 * name: mail_apps_private
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A Private mailbox in a mail program (specs/mail_apps_plugin.md § Locked
 * behaviour), through the window an app credential borrows.
 *
 * Covers:
 *  - locked: sealed mail that arrives is NOT numbered (nothing new appears);
 *    one lock notice is numbered in INBOX instead, its bytes fixed
 *  - locked: a fetch of sealed mail answers 'locked', never a stand-in
 *  - locked: metadata changes (read, star, archive) still apply
 *  - a website unlock arms the credential's window; the next reconcile numbers
 *    the waiting mail and expunges the notice; the bytes are the real message
 *  - a deleted notice stays deleted until the lock lifts
 *  - checks count as activity: countCheckAsActivity() keeps the slot alive
 *
 * Run: php -d apc.enable_cli=1 plugins/mail_apps/tests/mail_apps_private_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('MailAppContext')) {
	harness_skip('the mail_apps plugin is not active here', 'activate it, then run this suite');
	harness_finish();
}
require_once(__DIR__ . '/lib/mail_apps_fixtures.php');
harness_set_setting_mem('mail_apps_enabled', '1'); // the purpose's switch (AppAccess requiresSetting)
if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run with php -d apc.enable_cli=1');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();
$user = make_user('MailAppsPrivate', 0);
$uid = (int)$user->key;
$kp = vault_fixture_server_vault($uid);
$domain = mafx_domain('private');
$alias = mafx_alias($domain, 'sealed', $uid, InboundEmailDomain::LEVEL_PRIVATE);
$address = strtolower($alias->get_full_address());
$minted = ApiKey::CreateAppPassword($uid, 'mail', 'Test laptop');
harness_register_model('ApiKey', (int)$minted['api_key']->key);
$key_id = (int)$minted['api_key']->key;
harness_defer(function () use ($uid) {
	VaultUnlock::leaveCredentialWindowForTests();
	VaultUnlock::lockAll($uid);
	SessionControl::get_instance()->clear_api_user();
});

// The mail program's request: its credential's borrowed window.
$as_app = function () use ($uid, $key_id, $address) {
	VaultUnlock::useCredentialWindow($key_id, $uid);
	return mafx_context($uid, $address);
};

$sealed1 = mafx_deliver($alias, $domain, mafx_raw($address, 'Secret one', 'sealed body one'));
check((bool)$db->query("SELECT iem_content_sealed FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $sealed1")->fetchColumn(),
	'Mail to the Private mailbox is stored sealed');

section('Locked: nothing sealed is numbered; one notice is');
$ctx = $as_app();
check($ctx->seals && !$ctx->windowOpen(), 'The mailbox seals and the window is shut');
$inbox = MailAppFolders::byKey($ctx, 'inbox');
$state = MailAppFolders::reconcile($ctx, $inbox);
$live = mafx_live($ctx, 'inbox');
check(!in_array($sealed1, $live, true), 'The sealed message has no number');
check(count($live) === 1 && reset($live) === null, 'One lock notice is numbered instead', json_encode($live));
check($state['locked'] && $state['waiting'], 'The folder says it is locked with mail waiting');
$notice_uid = (int)key($live);
$rows = MailAppFolders::liveRows($ctx, $inbox, array($notice_uid));
$notice = MailAppRebuild::lockNotice($ctx, $notice_uid, $rows[$notice_uid]['created']);
check(strpos($notice, 'Your mailbox is locked') !== false && strpos($notice, $ctx->unlockUrl()) !== false,
	'The notice says it is locked and where to unlock', substr($notice, 0, 300));
check($notice === MailAppRebuild::lockNotice($ctx, $notice_uid, $rows[$notice_uid]['created']), 'Its bytes never change');

section('Locked: a fetch is refused, never a stand-in');
$head = MailAppRebuild::header($ctx, $sealed1);
$body = MailAppRebuild::bytes($ctx, $sealed1);
check(!$head['ok'] && $head['error'] === 'locked', 'The header is refused as locked');
check(!$body['ok'] && $body['error'] === 'locked' && !isset($body['bytes']), 'The bytes are refused as locked, with nothing in their place');

section('Locked: metadata changes still apply');
$ctx->service->markRead(array($sealed1), true);
$ctx->service->setStarred(array($sealed1), true);
check((bool)$db->query("SELECT iem_is_read AND iem_is_starred FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = $sealed1")->fetchColumn(),
	'Read and star apply while locked');
$ctx->service->markRead(array($sealed1), false);

section('A deleted notice stays deleted until the lock lifts');
MailAppMutations::store($ctx, $inbox, array($notice_uid), 'add', array('\\Deleted'));
MailAppMutations::expunge($ctx, $inbox);
MailAppFolders::reconcile($ctx, $inbox);
check(mafx_live($ctx, 'inbox') === array(), 'No new notice while the lock holds');

section('Unlocking on the website lets the waiting mail in');
VaultUnlock::leaveCredentialWindowForTests();
vault_fixture_open_window($uid, $kp['secret'], 'user', array('idle' => null, 'absolute' => 3600), VaultAudit::VIA_PASSKEY);
$ctx = $as_app();
check($ctx->windowOpen(), 'The credential\'s borrowed window is open');
$state = MailAppFolders::reconcile($ctx, $inbox);
$live = mafx_live($ctx, 'inbox');
check(in_array($sealed1, $live, true), 'The waiting message is numbered now');
check(!in_array(null, $live, true), 'No notice is live');
$body = MailAppRebuild::bytes($ctx, $sealed1);
check($body['ok'] && strpos($body['bytes'], 'sealed body one') !== false, 'Its bytes are the real message');
$ctx->countCheckAsActivity();
check(VaultUnlock::isOpen($uid), 'A check keeps the window open');

section('Locking again: a fresh notice for new waiting mail');
VaultUnlock::leaveCredentialWindowForTests();
VaultUnlock::lockBorrowed($uid);
$sealed2 = mafx_deliver($alias, $domain, mafx_raw($address, 'Secret two', 'sealed body two'));
$ctx = $as_app();
MailAppFolders::reconcile($ctx, $inbox);
$live = mafx_live($ctx, 'inbox');
check(in_array($sealed1, $live, true), 'Mail already numbered stays visible');
check(!in_array($sealed2, $live, true), 'The new sealed message waits');
check(in_array(null, $live, true), 'A fresh notice is numbered (the old dismissal ended with the lock)');
$nums = array_keys($live);
check(max($nums) > $notice_uid, 'The fresh notice has a new, higher number');

harness_finish();
