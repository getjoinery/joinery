<?php
/** @joinery-test
 * name: chat_mail_read_level
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A staff chat below the mailbox reader's all-access level is owner-scoped on
 * mail (mail_access_hardening WP0, B1).
 *
 * ChatTurnContext::ownerScopedReads() asks the model's read level. With
 * InboundEmailMessage declaring 10, a level 6 to 9 chat with data access on
 * reads only the messages it owns (its sealed owner, its grant), the same
 * fence the reader puts in front of such an account; a level 10 chat reads
 * across users as it always has; a member chat is owner-scoped on everything.
 *
 * Run: php tests/run.php --filter=chat_mail_read_level
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!PluginHelper::isPluginActive('joinery_ai')) { harness_skip('joinery_ai plugin inactive'); harness_finish(); }
if (!PluginHelper::isPluginActive('mailbox')) { harness_skip('mailbox plugin inactive'); harness_finish(); }

$tag = strtoupper(LibraryFunctions::random_string(6));
$staff = make_user($tag . 'S6', 6);
$senior = make_user($tag . 'S9', 9);
$admin = make_user($tag . 'AD', 10);
$member = make_user($tag . 'ME', 0);

$conversation_for = function (User $owner): AiConversation {
	$c = new AiConversation(NULL);
	$c->set('aic_owner_user_id', (int)$owner->key);
	$c->set('aic_security_level', AiConversation::LEVEL_STANDARD);
	$c->set('aic_data_access', true);
	$c->save();
	$c->load();
	harness_register_row('aic_conversations', 'aic_conversation_id', (int)$c->key);
	return $c;
};

section('a staff chat is owner-scoped on mail');
$ctx6 = new ChatTurnContext($conversation_for($staff), (int)$staff->key);
check($ctx6->ownerScopedReads('InboundEmailMessage') === true,
	'a level 6 chat reads only the mail it owns');
check($ctx6->ownerScopedReads('Address') === false,
	'the same chat still reads a personal-class model across users (the fence is mail\'s)');
$ctx9 = new ChatTurnContext($conversation_for($senior), (int)$senior->key);
check($ctx9->ownerScopedReads('InboundEmailMessage') === true,
	'a level 9 chat too');

section('a superadmin chat and a member chat are as before');
$ctx10 = new ChatTurnContext($conversation_for($admin), (int)$admin->key);
check($ctx10->ownerScopedReads('InboundEmailMessage') === false,
	'a level 10 chat reads mail across users');
$ctx0 = new ChatTurnContext($conversation_for($member), (int)$member->key);
check($ctx0->ownerScopedReads('InboundEmailMessage') === true && $ctx0->ownerScopedReads('Address') === true,
	'a member chat is owner-scoped on everything');

section('the scoped model stays in the chat\'s model list');
check(in_array('InboundEmailMessage', $ctx6->allowedModels(), true),
	'a level 6 chat still lists InboundEmailMessage, scoped, when its ownership is declared',
	implode(',', array_filter($ctx6->allowedModels(), function ($m) { return strpos($m, 'Email') !== false; })));

harness_finish();
