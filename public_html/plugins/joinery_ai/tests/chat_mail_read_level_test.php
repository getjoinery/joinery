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
 * @version 1.2 - a recipe is owner-scoped on mail (Q5)
 * @version 1.1 - a level-10 chat reads mail across users only with the reader's oversight confirmed (WP3, B13)
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

// ---------------------------------------------------------------------------
// WP3, B13: a level-10 chat reads mail across users only with the oversight the
// mail reader asks: a second factor on the account and a fresh step-up marker
// on this browser session. The query is refused before any row is fetched.
section('a superadmin chat reads across users only with oversight confirmed');
$db = DbConnector::get_instance()->get_db_link();
$sid = 'cmrl-' . $tag;
$query_mail = function (ChatTurnContext $ctx) {
	try {
		ModelQueryExecutor::query('InboundEmailMessage', array(), array(), 1, array('iem_inbound_email_message_id'), $ctx);
		return 'read';
	} catch (InvalidArgumentException $e) {
		return $e->getMessage();
	}
};
$no_factor = new ChatTurnContext($conversation_for($admin), (int)$admin->key, 0, $sid);
check($no_factor->mailOversightViewer()->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_SECOND_FACTOR,
	'with no second factor on the account the chat\'s viewer is not all-access', $no_factor->mailOversightViewer()->oversightState());
$answer = $query_mail($no_factor);
check(strpos($answer, 'second factor') !== false && strpos($answer, 'limited to your own mail') !== false,
	'and a read across users is refused, naming the second factor', $answer);

$db->prepare("UPDATE usr_users SET usr_totp_secret = 'cmrl-test-secret', usr_totp_enabled_time = now() WHERE usr_user_id = ?")->execute(array((int)$admin->key));
$no_marker = new ChatTurnContext($conversation_for($admin), (int)$admin->key, 0, $sid);
check($no_marker->mailOversightViewer()->oversightState() === MailboxViewer::OVERSIGHT_NEEDS_STEPUP,
	'with a factor but no fresh step-up on this session: needs a step-up', $no_marker->mailOversightViewer()->oversightState());
$answer = $query_mail($no_marker);
check(strpos($answer, 'open the mail reader') !== false,
	'and the refusal sends the person to the reader to confirm', $answer);
check($query_mail(new ChatTurnContext($conversation_for($admin), (int)$admin->key)) !== 'read',
	'a turn with no browser session at all is refused too');

$marker = new PasskeyCeremony(NULL);
$marker->set('pks_session_id', $sid);
$marker->set('pks_kind', 'stepup');
$marker->set('pks_purpose', 'stepup_verified');
$marker->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + 3600));
$marker->save();
harness_register_row('pks_passkey_ceremonies', 'pks_passkey_ceremony_id', (int)$marker->key);
$confirmed = new ChatTurnContext($conversation_for($admin), (int)$admin->key, 0, $sid);
check($confirmed->mailOversightViewer()->isAllAccess(),
	'a fresh step-up marker on the session makes the chat\'s viewer all-access', $confirmed->mailOversightViewer()->oversightState());
check($query_mail($confirmed) === 'read', 'and the read across users goes through', $query_mail($confirmed));

$staff_scoped = $query_mail(new ChatTurnContext($conversation_for($staff), (int)$staff->key, 0, $sid));
check($staff_scoped === 'read', 'an owner-scoped staff read asks no oversight (it is the person\'s own mail)', $staff_scoped);

// ---------------------------------------------------------------------------
// Q5: a recipe runs in no browser session, so it can never confirm oversight;
// it reads its owner's own mail and nothing across users, whoever owns it.
section('a recipe is owner-scoped on mail, even a superadmin\'s');
$recipe = new Recipe(NULL);
$recipe->set('rcp_owner_user_id', (int)$admin->key);
$recipe->set('rcp_allowed_models', json_encode(array('InboundEmailMessage')));
$recipe_ctx = new RecipeRunContext($recipe, new RecipeRun(NULL));
check($recipe_ctx->ownerScopedReads('InboundEmailMessage') === true,
	'a level 10 owner\'s recipe reads only the mail the owner holds');
check($recipe_ctx->ownerScopedReads('Address') === false,
	'the same recipe still reads other models across users, as before');
$recipe_read = (function () use ($recipe_ctx) {
	try {
		ModelQueryExecutor::query('InboundEmailMessage', array(), array(), 1, array('iem_inbound_email_message_id'), $recipe_ctx);
		return 'read';
	} catch (InvalidArgumentException $e) {
		return $e->getMessage();
	}
})();
check($recipe_read === 'read', 'and its mail query runs, scoped to the owner, with no oversight asked', $recipe_read);

harness_finish();
