<?php
/** @joinery-test
 * name: mailbox_message_actions
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Reader actions other plugins declare, and the one access rule they use.
 *
 *   - MailboxViewer::canReadMessage(): a grant on the message's mailbox reads it, no grant
 *     does not, a message with no mailbox is for an all-access viewer only;
 *   - MailboxMessageActions::openForAction() applies it, refuses a deleted message, a
 *     message with no mailbox, and a mailbox that seals its content;
 *   - the reader offers the declared buttons only for a Standard-level mailbox's message,
 *     and carries them in its mount data.
 *
 * Run: php tests/run.php test-db --filter=mailbox_message_actions
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

$db = DbConnector::get_instance()->get_db_link();
$suffix = getmypid() . '-' . random_int(1000, 9999);
harness_defer(function () use ($suffix) {
	mailbox_purge_domains('mma-%-' . $suffix . '.example', 'mma-%-' . $suffix . '@example.test');
});

$d = new InboundEmailDomain(NULL);
$d->set('ied_domain', 'mma-one-' . $suffix . '.example');
$d->set('ied_is_enabled', true);
$d->set('ied_catch_all_mode', 'store');
$d->save();
$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', intval($d->key));
$alias->set('iea_alias', 'box');
$alias->set('iea_delivery_mode', 'store');
$alias->save();

$member = mailbox_make_user('mma-m-' . $suffix . '@example.test', 5);
$other = mailbox_make_user('mma-o-' . $suffix . '@example.test', 5);
InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), array($member));

$router = new InboundEmailRouter();
$store = function (string $local) use ($router, $d, $db): InboundEmailMessage {
	$to = $local . '@' . $d->get('ied_domain');
	$router->processEmail("From: A <a@sender.example>\r\nTo: <" . $to . ">\r\nSubject: s-" . $local . "\r\nMessage-ID: <" . bin2hex(random_bytes(6)) . "@x.example>\r\n\r\nbody\r\n", $to,
		array('source' => 'mailgun', 'dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass'));
	$q = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_subject = ?");
	$q->execute(array('s-' . $local));
	return new InboundEmailMessage(intval($q->fetchColumn()), TRUE);
};
$boxed = $store('box');
$loose = $store('anyone');   // the catch-all stores it with no mailbox
check($boxed->key && intval($boxed->get('iem_iea_inbound_email_alias_id')) === intval($alias->key), 'a message in the mailbox');
check($loose->key && !$loose->get('iem_iea_inbound_email_alias_id'), 'and one that belongs to no mailbox');

section('canReadMessage(): the one rule');

$v_member = MailboxViewer::forUser($member, 5);
$v_other = MailboxViewer::forUser($other, 5);
$v_super = MailboxViewer::forUser($other, 10);
check($v_member->canReadMessage($boxed), 'a grant on the mailbox reads its messages');
check(!$v_other->canReadMessage($boxed), 'no grant does not');
check($v_super->canReadMessage($boxed), 'an all-access viewer does');
check(!$v_member->canReadMessage($loose) && !$v_other->canReadMessage($loose), 'a message with no mailbox is not for a granted viewer');
check($v_super->canReadMessage($loose), 'it is for an all-access viewer');

section('openForAction()');

$r = MailboxMessageActions::openForAction($v_member, intval($boxed->key));
check($r['message'] !== null && $r['error'] === null, 'a granted viewer opens a Standard-level message');
$r = MailboxMessageActions::openForAction($v_other, intval($boxed->key));
check($r['message'] === null && strpos((string)$r['error'], 'access') !== false, 'a viewer without the grant is refused', (string)$r['error']);
$r = MailboxMessageActions::openForAction($v_super, intval($loose->key));
check($r['message'] === null && $r['error'] !== null, 'a message with no mailbox is refused even to an all-access viewer');
$r = MailboxMessageActions::openForAction($v_member, 999999999);
check($r['message'] === null && strpos((string)$r['error'], 'no longer exists') !== false, 'a missing message is refused');
$db->exec("UPDATE iea_inbound_email_aliases SET iea_security_level = 'private' WHERE iea_inbound_email_alias_id = " . (int)$alias->key);
$r = MailboxMessageActions::openForAction($v_member, intval($boxed->key));
check($r['message'] === null && strpos((string)$r['error'], 'protected') !== false, 'a mailbox that seals its content is refused', (string)$r['error']);
$db->exec("UPDATE iea_inbound_email_aliases SET iea_security_level = NULL WHERE iea_inbound_email_alias_id = " . (int)$alias->key);
$db->exec("UPDATE iem_inbound_email_messages SET iem_delete_time = now() WHERE iem_inbound_email_message_id = " . (int)$boxed->key);
$r = MailboxMessageActions::openForAction($v_member, intval($boxed->key));
check($r['message'] === null && strpos((string)$r['error'], 'no longer exists') !== false, 'a deleted message is refused');

section('Declarations, and what the reader does with them');

$declared = MailboxMessageActions::declared();
$bad = array_filter($declared, function ($a) { return $a['label'] === '' || $a['url'][0] !== '/' || strpos($a['url'], '//') === 0; });
check(is_array($declared) && !$bad, 'every declared action has a label and a same-site path');

$js = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_reader.js'));
check(strpos($js, 'function messageActionsApply(m)') !== false && strpos($js, "box.security_level === 'standard'") !== false
	&& strpos($js, 'if (messageActionsApply(m))') !== false,
	'the reader offers them only on a Standard-level mailbox\'s message');
$mount = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_reader_mount.php'));
check(strpos($mount, "'messageActions'") !== false && strpos($mount, 'MailboxMessageActions::declared()') !== false,
	'and the mount carries the declared actions once per page');

harness_finish();
