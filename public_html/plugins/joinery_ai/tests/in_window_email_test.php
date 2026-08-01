<?php
/** @joinery-test
 * name: in_window_email
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The AI email jobs against a real sealed domain
 * (specs/in_window_deferred_work.md § Feature 2).
 *
 * Everything here turns on one fact: mail on a Fortress domain is encrypted to
 * the owner, so it is readable only while their unlock window is open. What the
 * test pins down:
 *
 *  - a sealed binding declares a vault scope; a standard one declares none, so
 *    ordinary recipes keep running on their schedule;
 *  - sealed mail is invisible to the job while locked and visible while
 *    unlocked — the fail-closed property that protects the cron path;
 *  - the dispatcher and the spawner both refuse an in-window recipe, because a
 *    command-line worker can never hold a window;
 *  - saving a recipe against a sealed domain is refused until that domain has
 *    opted in to AI processing;
 *  - selection rules: unread only, parsed only, newest first;
 *  - the deferred-work consumer reports and drains that work.
 *
 * Run: php tests/run.php db --filter=in_window_email
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxAliasConfig.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/EmailJobCandidates.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeWorkerSpawner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/tasks/RecipeDispatcher.php'));

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run manually: php -d apc.enable_cli=1 plugins/joinery_ai/tests/in_window_email_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

function iw_vault(int $user_id, string $public_b64): UserEncryptionVault {
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', $user_id);
	$vault->set('uev_public_key', $public_b64);
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($vault->key));
	return $vault;
}

function iw_domain(string $level, bool $ai_ok): InboundEmailDomain {
	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'iw-' . bin2hex(random_bytes(4)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_security_level', $level);
	$dom->set('ied_ai_processing_enabled', $ai_ok);
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	return $dom;
}

function iw_alias(int $domain_id, string $local, int $holder_id): InboundEmailAlias {
	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', 'store');
	$alias->set('iea_destinations', '');
	$alias->set('iea_is_enabled', true);
	$alias->save();
	$alias->load();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', intval($alias->key));
	InboundEmailMailboxGrant::sync_for_alias($alias->key, array($holder_id));
	harness_defer(function () use ($alias) {
		InboundEmailMailboxGrant::sync_for_alias($alias->key, array());
	});
	return $alias;
}

function iw_message(int $domain_id, int $alias_id, string $subject, string $body,
		string $recipient, string $offset_minutes): int {
	$msg = new InboundEmailMessage(NULL);
	$msg->set('iem_ied_inbound_email_domain_id', $domain_id);
	$msg->set('iem_iea_inbound_email_alias_id', $alias_id);
	$msg->set('iem_direction', 'inbound');
	$msg->set('iem_sender', 'sender@elsewhere.example');
	$msg->set('iem_recipient', $recipient);
	$msg->set('iem_subject', $subject);
	$msg->set('iem_body_plain', $body);
	$msg->set('iem_message_id_header', 'iw-' . bin2hex(random_bytes(8)) . '@example.com');
	$msg->set('iem_received_time', gmdate('Y-m-d H:i:s', strtotime("$offset_minutes minutes")));
	$msg->save();
	$msg->load();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', intval($msg->key));
	return intval($msg->key);
}

function iw_recipe(int $owner_id, string $job_id, string $address): Recipe {
	$recipe = new Recipe(NULL);
	$recipe->set('rcp_name', 'iw test ' . bin2hex(random_bytes(3)));
	$recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
	$recipe->set('rcp_pipeline_job', $job_id);
	$recipe->set('rcp_source_config', json_encode(array('mailbox_alias' => $address)));
	$recipe->set('rcp_owner_user_id', $owner_id);
	$recipe->set('rcp_enabled', true);
	$recipe->set('rcp_schedule_frequency', 'hourly');
	$recipe->set('rcp_allow_tainted_writes', true);
	$recipe->save();
	$recipe->load();
	harness_register_row('rcp_recipes', 'rcp_recipe_id', intval($recipe->key));
	return $recipe;
}

try {

	// -----------------------------------------------------------------------
	section('fixtures: a Fortress domain with sealed mail');

	$owner = make_user('IwOwner');
	$owner_id = intval($owner->key);
	$kp = sodium_crypto_box_keypair();
	$secret = SealedBox::b64url(sodium_crypto_box_secretkey($kp));
	iw_vault($owner_id, SealedBox::b64url(sodium_crypto_box_publickey($kp)));

	$fortress = iw_domain(InboundEmailDomain::LEVEL_FORTRESS, true);
	$alias = iw_alias(intval($fortress->key), 'me', $owner_id);
	$address = 'me@' . $fortress->get('ied_domain');

	$older = iw_message(intval($fortress->key), intval($alias->key),
		'Older note', 'An ordinary older message.', $address, '-30');
	$newer = iw_message(intval($fortress->key), intval($alias->key),
		'Newer note', 'An ordinary newer message.', $address, '-5');

	$sealed = mailbox_protection_seal_batch($fortress, 200);
	check($sealed['sealed'] === 2 && $sealed['remaining'] === 0,
		'both messages seal at rest', json_encode($sealed));

	MailboxAliasConfig::clearPostureCache();
	check(MailboxAliasConfig::isSealedAtRest($address), 'the address reads as sealed at rest');
	check(MailboxAliasConfig::aiProcessingAllowed($address), 'and its domain has opted in to AI');

	$recipe = iw_recipe($owner_id, 'email_triage', $address);
	$job = PipelineJobRegistry::get('email_triage');
	$config = array('mailbox_alias' => $address);

	// -----------------------------------------------------------------------
	section('a sealed binding needs a window; a standard one does not');

	VaultUnlock::lockAll($owner_id);
	check($job->requiresVaultScope($config) === UserEncryptionVault::SCOPE_USER,
		'the job declares the user scope for a sealed mailbox');
	check(RecipeVaultScope::requiresWindow($recipe), 'so the recipe requires a window');

	$standard = iw_domain(InboundEmailDomain::LEVEL_STANDARD, false);
	$std_alias = iw_alias(intval($standard->key), 'plain', $owner_id);
	$std_address = 'plain@' . $standard->get('ied_domain');
	$std_recipe = iw_recipe($owner_id, 'email_triage', $std_address);
	MailboxAliasConfig::clearPostureCache();
	check($job->requiresVaultScope(array('mailbox_alias' => $std_address)) === null,
		'a standard mailbox declares no scope');
	check(!RecipeVaultScope::requiresWindow($std_recipe),
		'so an ordinary recipe keeps its schedule');

	// -----------------------------------------------------------------------
	section('sealed mail is invisible while locked');

	check(VaultUnlock::secretKey($owner_id) === null, 'precondition: the vault is locked');
	check($job->nextItem($config, $recipe) === null,
		'nextItem finds nothing while locked, rather than reading ciphertext');
	check($job->hasWork($config, $recipe) === false, 'and hasWork agrees');
	check(RecipeVaultScope::hasWork($owner_id) === false,
		'the deferred-work predicate reports nothing to do while locked');

	// -----------------------------------------------------------------------
	section('and visible while unlocked, newest first');

	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	check(VaultUnlock::isOpen($owner_id), 'precondition: the window is open');

	$item = $job->nextItem($config, $recipe);
	check($item !== null && $item['item_key'] === (string)$newer,
		'the newest unread sealed message is the next item');
	check($item !== null && strpos((string)$item['digest'], 'Newer note') !== false,
		'and its digest carries the decrypted subject');
	check($job->hasWork($config, $recipe) === true, 'hasWork agrees while unlocked');
	check(RecipeVaultScope::hasWork($owner_id) === true,
		'the deferred-work predicate now reports work');

	// -----------------------------------------------------------------------
	section('read and unparsed mail are both out of scope');

	InboundEmailMessage::updateColumns($newer, array('iem_is_read' => true));
	$item = $job->nextItem($config, $recipe);
	check($item !== null && $item['item_key'] === (string)$older,
		'a read message drops out and the older one is next');

	InboundEmailMessage::updateColumns($older, array('iem_pending_parse' => true));
	check($job->nextItem($config, $recipe) === null,
		'an unparsed message is never judged on its empty content');
	InboundEmailMessage::updateColumns($older, array('iem_pending_parse' => false));
	check($job->nextItem($config, $recipe) !== null, 'and returns once it is parsed');

	// -----------------------------------------------------------------------
	section('cron refuses an in-window recipe');

	$run = new RecipeRun(NULL);
	$run->set('rcr_rcp_recipe_id', intval($recipe->key));
	$run->set('rcr_status', RecipeRun::STATUS_PENDING);
	// Explicit: rcr_trigger defaults to 'schedule', and the assertion below
	// counts schedule-triggered rows to prove the dispatcher queued none.
	$run->set('rcr_trigger', RecipeRun::TRIGGER_MANUAL);
	$run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
	$run->save();
	$run->load();
	harness_register_row('rcr_recipe_runs', 'rcr_run_id', intval($run->key));

	check(RecipeWorkerSpawner::spawnIfUnderCap($run) === false,
		'the spawner refuses to start a worker for it');
	check((string)(new RecipeRun(intval($run->key), TRUE))->get('rcr_status') === RecipeRun::STATUS_PENDING,
		'and leaves the row untouched rather than failing it');

	$dispatcher = new RecipeDispatcher();
	$result = $dispatcher->run(array());
	check(($result['status'] ?? '') === 'success', 'the dispatcher tick still succeeds', json_encode($result));

	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT count(*) FROM rcr_recipe_runs
		WHERE rcr_rcp_recipe_id = ? AND rcr_trigger = ? AND rcr_delete_time IS NULL");
	$q->execute(array(intval($recipe->key), RecipeRun::TRIGGER_SCHEDULE));
	check((int)$q->fetchColumn() === 0,
		'and never queues a scheduled run for an in-window recipe');

	// -----------------------------------------------------------------------
	section('consent is required to bind a recipe to a sealed mailbox');

	$closed = iw_domain(InboundEmailDomain::LEVEL_FORTRESS, false);
	$closed_alias = iw_alias(intval($closed->key), 'shut', $owner_id);
	$closed_address = 'shut@' . $closed->get('ied_domain');
	MailboxAliasConfig::clearPostureCache();

	$refused = false;
	$message = '';
	try {
		$job->validateConfig(array('mailbox_alias' => $closed_address),
			iw_recipe($owner_id, 'email_triage', $closed_address));
	} catch (InvalidArgumentException $e) {
		$refused = true;
		$message = $e->getMessage();
	}
	check($refused, 'saving against a sealed domain without consent is refused');
	check(strpos($message, $closed->get('ied_domain')) !== false,
		'and the refusal names the domain', $message);

	// The same mailbox is accepted once the domain opts in.
	$closed->set('ied_ai_processing_enabled', true);
	$closed->save();
	MailboxAliasConfig::clearPostureCache();
	$accepted = true;
	try {
		$job->validateConfig(array('mailbox_alias' => $closed_address),
			iw_recipe($owner_id, 'email_triage', $closed_address));
	} catch (InvalidArgumentException $e) {
		$accepted = false;
		$message = $e->getMessage();
	}
	check($accepted, 'and accepted once the domain opts in', $message);

	VaultUnlock::lockAll($owner_id);

} finally {
	// harness_register_row/harness_defer reclaim every fixture row (LIFO), so a
	// mid-suite failure still leaves no throwaway domain, alias, grant, message,
	// recipe, run, or vault behind.
	harness_finish();
}
?>
