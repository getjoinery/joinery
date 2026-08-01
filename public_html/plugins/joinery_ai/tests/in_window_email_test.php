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
	section('recording a verdict does not destroy the sealed row');

	// This path had never once run. save() rebuilds every column from get(),
	// which DECRYPTS, so the first sealed message ever triaged had its sender,
	// subject and bodies written back as plaintext with iem_content_sealed still
	// true — a leak and a corruption at once, and every later read then threw
	// 'malformed AEAD blob'. These checks are the regression.
	// recordVerdict() runs as the browsing owner during an in-window drain, and
	// a recipe owner is always an admin, so the fixture session has to say so —
	// InboundEmailMessage::authenticate_write() needs permission 5 or better.
	$_SESSION['loggedin']    = 1;
	$_SESSION['usr_user_id'] = $owner_id;
	$_SESSION['permission']  = 10;

	InboundEmailMessage::updateColumns($older, array('iem_is_read' => false));
	$raw_before = $db->prepare("SELECT iem_subject, iem_sender, iem_body_plain, iem_content_sealed
		FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
	$raw_before->execute(array($older));
	$before = $raw_before->fetch(PDO::FETCH_ASSOC);

	$summary = 'A sealed summary long enough to have overflowed the old varchar column, '
		. 'which is exactly why this assertion exists at all.';
	$job->recordVerdict((string)$older, array('label' => 'none', 'summary' => $summary),
		$recipe, 'test-model');

	$raw_after = $db->prepare("SELECT iem_subject, iem_sender, iem_body_plain, iem_content_sealed,
		iem_ai_summary FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
	$raw_after->execute(array($older));
	$after = $raw_after->fetch(PDO::FETCH_ASSOC);

	check($after['iem_subject'] === $before['iem_subject']
		&& $after['iem_sender'] === $before['iem_sender']
		&& $after['iem_body_plain'] === $before['iem_body_plain'],
		'the sealed columns are untouched — not rewritten as plaintext');
	check(strpos((string)$after['iem_subject'], 'v1.aead.') === 0,
		'and are still ciphertext', substr((string)$after['iem_subject'], 0, 24));
	check(!empty($after['iem_content_sealed']) && $after['iem_content_sealed'] !== 'f',
		'the row is still marked sealed');
	check(strpos((string)$after['iem_ai_summary'], 'v1.aead.') === 0,
		'the summary itself is stored sealed, not in the clear',
		substr((string)$after['iem_ai_summary'], 0, 24));

	$reread = new InboundEmailMessage($older, TRUE);
	check((string)$reread->get('iem_ai_summary') === $summary,
		'and reads back through the sealed reader intact');
	check((string)$reread->get('iem_subject') === 'Older note',
		'the message subject still opens — no malformed AEAD blob');

	// The security scan blob is the same story: red_flags quote the body by
	// prompt design, so it seals with the row while the score stays clear for
	// sorting.
	$scan_job = PipelineJobRegistry::get('email_security_scan');
	$scan_recipe = iw_recipe($owner_id, 'email_security_scan', $address);
	$scan_job->recordVerdict((string)$older, array(
		'score' => 42, 'verdict' => 'suspicious',
		'red_flags' => array(array('finding' => 'quotes the body verbatim here')),
		'summary' => 'A scan summary.',
	), $scan_recipe, 'test-model');

	$raw_scan = $db->prepare("SELECT iem_ai_scan, iem_ai_danger_score
		FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
	$raw_scan->execute(array($older));
	$scan_row = $raw_scan->fetch(PDO::FETCH_ASSOC);
	check(strpos((string)$scan_row['iem_ai_scan'], 'v1.aead.') === 0,
		'the scan blob is sealed at rest', substr((string)$scan_row['iem_ai_scan'], 0, 24));
	check((int)$scan_row['iem_ai_danger_score'] === 42,
		'while the danger score stays clear so the inbox can sort on it');

	$reread = new InboundEmailMessage($older, TRUE);
	$decoded = json_decode((string)$reread->get('iem_ai_scan'), true);
	check(is_array($decoded) && ($decoded['verdict'] ?? '') === 'suspicious',
		'and decodes back to the verdict through the sealed reader');

	// -----------------------------------------------------------------------
	section('save() itself is sealed-safe (Layer 0)');

	// The consumer-side fix above stops the two email jobs corrupting a sealed
	// row. This is the platform-side one: save() must be harmless on a sealed row
	// no matter who calls it, so the next consumer cannot repeat the mistake.
	$before_save = $db->prepare("SELECT iem_subject, iem_sender, iem_body_plain, iem_ai_summary
		FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
	$before_save->execute(array($older));
	$pre = $before_save->fetch(PDO::FETCH_ASSOC);

	$sealed_msg = new InboundEmailMessage($older, TRUE);
	check($sealed_msg->rowIsSealed() === true, 'the row reports itself sealed');
	$sealed_msg->set('iem_is_read', true);           // an ordinary metadata edit
	$sealed_msg->save();

	$before_save->execute(array($older));
	$post = $before_save->fetch(PDO::FETCH_ASSOC);
	check($post == $pre,
		'a plain save() leaves every sealed column byte-identical');
	check(strpos((string)$post['iem_subject'], 'v1.aead.') === 0,
		'they are still ciphertext, not decrypted plaintext');

	$reread = new InboundEmailMessage($older, TRUE);
	check((string)$reread->get('iem_subject') === 'Older note',
		'and the row still opens afterwards');
	check((bool)$reread->get('iem_is_read') === true,
		'while the unsealed column the caller actually changed did save');

	// The same call with the vault locked must not throw: get() on a sealed
	// column would, and save() no longer reads them at all.
	VaultUnlock::lockAll($owner_id);
	$locked_ok = true;
	try {
		$locked_msg = new InboundEmailMessage($older, TRUE);
		$locked_msg->set('iem_is_read', false);
		$locked_msg->save();
	} catch (Throwable $e) {
		$locked_ok = false;
		$cloud_message = get_class($e) . ': ' . $e->getMessage();
	}
	check($locked_ok, 'and saving a sealed row while the vault is LOCKED does not throw',
		$locked_ok ? '' : $cloud_message);
	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));

	// -----------------------------------------------------------------------
	section('sink zero: sealed mail does not reach a cloud model without consent');

	// This precedes every storage sink — the plaintext leaves over HTTPS, not
	// into a column, so no storage-side guard can see it.
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
	check(LlmProviderFactory::isCloudModel('claude-haiku-4-5') === true,
		'a Claude model counts as cloud');
	check(LlmProviderFactory::isCloudModel('qwen3.6:35b-a3b-nvfp4') === false,
		'a local model does not');

	MailboxAliasConfig::clearPostureCache();
	check(MailboxAliasConfig::aiCloudAllowed($address) === false,
		'the sealed domain has not consented to cloud processing');
	check(MailboxAliasConfig::aiCloudAllowed($std_address) === true,
		'while a standard domain has nothing to withhold');

	$recipe->set('rcp_model', 'claude-haiku-4-5');
	$refused_cloud = false;
	$cloud_message = '';
	try {
		RecipeVaultScope::assertModelAllowed($recipe);
	} catch (LlmProviderException $e) {
		$refused_cloud = true;
		$cloud_message = $e->getMessage();
	}
	check($refused_cloud, 'a sealed-source recipe pinned to a cloud model is refused');
	check(strpos($cloud_message, 'claude-haiku-4-5') !== false
		&& stripos($cloud_message, 'cloud AI models') !== false,
		'and the refusal names the model and the setting that would allow it', $cloud_message);

	// A local model on the same sealed recipe is fine.
	$recipe->set('rcp_model', 'qwen3.6:35b-a3b-nvfp4');
	$local_ok = true;
	try {
		RecipeVaultScope::assertModelAllowed($recipe);
	} catch (LlmProviderException $e) {
		$local_ok = false;
	}
	check($local_ok, 'the same recipe on a local model is allowed');

	// Granting consent lets the cloud model through...
	$fortress->set('ied_ai_cloud_enabled', true);
	$fortress->save();
	MailboxAliasConfig::clearPostureCache();
	$recipe->set('rcp_model', 'claude-haiku-4-5');
	$allowed_now = true;
	try {
		RecipeVaultScope::assertModelAllowed($recipe);
	} catch (LlmProviderException $e) {
		$allowed_now = false;
	}
	check($allowed_now, 'granting the domain cloud consent allows it');

	// ...and withdrawing it stops the recipe again, without the recipe changing.
	// This is the whole point of re-checking at run start rather than only at
	// save: the recipe row is identical either side of this.
	$fortress->set('ied_ai_cloud_enabled', false);
	$fortress->save();
	MailboxAliasConfig::clearPostureCache();
	$stopped_again = false;
	try {
		RecipeVaultScope::assertModelAllowed($recipe);
	} catch (LlmProviderException $e) {
		$stopped_again = true;
	}
	check($stopped_again,
		'withdrawing consent stops an already-saved recipe, with the recipe untouched');

	// A standard-mailbox recipe is never gated by this.
	$std_recipe->set('rcp_model', 'claude-haiku-4-5');
	$std_ok = true;
	try {
		RecipeVaultScope::assertModelAllowed($std_recipe);
	} catch (LlmProviderException $e) {
		$std_ok = false;
	}
	check($std_ok, 'and an ordinary mailbox recipe is untouched by the gate');

	$recipe->set('rcp_model', '');

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

} catch (Throwable $e) {
	// Without this, an exception mid-suite is silent: harness_finish() runs from
	// the finally and exit()s before the throw can surface, so the run reports
	// PASS on however many checks happened to complete. A shrinking suite looks
	// identical to a passing one — which is exactly how the sealed write path
	// stayed broken. Fail loudly instead.
	check(false, 'the suite ran to completion without throwing',
		get_class($e) . ': ' . $e->getMessage()
		. ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
	// harness_register_row/harness_defer reclaim every fixture row (LIFO), so a
	// mid-suite failure still leaves no throwaway domain, alias, grant, message,
	// recipe, run, or vault behind.
	harness_finish();
}
?>
