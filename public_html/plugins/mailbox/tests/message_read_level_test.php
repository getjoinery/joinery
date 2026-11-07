<?php
/** @joinery-test
 * name: message_read_level
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A stored message's read level is the mailbox reader's all-access level
 * (mail_access_hardening WP0).
 *
 * InboundEmailMessage is a personal-class model, which would read at 6, but
 * the reader lets an account without a grant open a mailbox only at 10
 * (MailboxViewer::isAllAccess()). The model declares the same level as a
 * read exception so every consumer of access_level('read'), the AI chat's
 * owner scoping among them, agrees with the reader. Writes keep the class's
 * level: this is a read fence, not a change to who may file or edit mail.
 *
 * Run: php plugins/mailbox/tests/message_read_level_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!PluginHelper::isPluginActive('mailbox')) { harness_skip('mailbox plugin inactive'); harness_finish(); }

section('the read level is the reader\'s all-access level');
check(InboundEmailMessage::access_level('read') === 10,
	'InboundEmailMessage reads at 10', 'got ' . InboundEmailMessage::access_level('read'));
check(InboundEmailMessage::$access_class === 'personal',
	'the class stays personal (writes, deletes)');
check(InboundEmailMessage::access_level('write') === Access::level('personal:write'),
	'the write level is still the personal class\'s', 'got ' . InboundEmailMessage::access_level('write'));

section('the two rules agree');
$viewer_src = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
check(preg_match('/function isAllAccess\(\)[^}]*Access::allows\(10,/s', $viewer_src) === 1,
	'MailboxViewer::isAllAccess() is level 10, the level the model declares');

harness_finish();
