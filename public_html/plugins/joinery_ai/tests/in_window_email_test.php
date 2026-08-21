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
 *  - the deferred-work consumer reports and drains that work, only when the
 *    recipe's Runs setting says it is due, and adopts a Run Now row that no
 *    worker could ever have claimed — including on a Manually-only recipe;
 *  - a worker's run never satisfies the sealed side of a mixed clock recipe,
 *    so cron claiming every fire point cannot starve the sealed subset.
 *
 * Run: php tests/run.php db --filter=in_window_email
 *
 * @version 1.3
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(__DIR__ . '/../../../tests/lib/llm_fixtures.php');
// Jobs are handed the run's model resolution so they can size a digest against the
// room they actually got. These tests exercise selection, not sizing.
$fake_resolution = fake_model_resolution();

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
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSchedule.php'));
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
	$recipe->set('rcp_source_config', json_encode(array('mailbox_aliases' => array($address))));
	$recipe->set('rcp_owner_user_id', $owner_id);
	$recipe->set('rcp_enabled', true);
	// Arrival, not a clock: a mail recipe's Runs setting is "as mail arrives",
	// which is what makes pendingForOwner's answer track hasWork() the way the
	// sections below assert (specs/recipe_run_scheduling.md).
	$recipe->set('rcp_schedule_frequency', RecipeSchedule::FREQ_ARRIVAL);
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
	$owner_vault = iw_vault($owner_id, SealedBox::b64url(sodium_crypto_box_publickey($kp)));

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
	$config = array('mailbox_aliases' => array($address));

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
	check($job->requiresVaultScope(array('mailbox_aliases' => array($std_address))) === null,
		'a standard mailbox declares no scope');
	check(!RecipeVaultScope::requiresWindow($std_recipe),
		'so an ordinary recipe keeps its schedule');

	// Built HERE, asserted at the very end ('a mixed clock recipe keeps its
	// sealed side'): its two-address source_config is longer than the sealed-
	// egress guard's free-text allowance, so the row has to exist before this
	// process opens any sealed mail. It stays on Manually only until that
	// section, so no intermediate pendingForOwner assertion sees it.
	$mixed = iw_recipe($owner_id, 'email_triage', $address);
	$mixed->set('rcp_source_config',
		json_encode(array('mailbox_aliases' => array($address, $std_address))));
	$mixed->set('rcp_schedule_frequency', RecipeSchedule::FREQ_DAILY);
	$mixed->set('rcp_schedule_time', '00:00:01');
	$mixed->set('rcp_enabled', false);
	$mixed->save();
	$mixed->load();

	// -----------------------------------------------------------------------
	section('sealed mail is invisible while locked');

	check(VaultUnlock::secretKey($owner_id) === null, 'precondition: the vault is locked');
	check($job->nextItem($config, $recipe, $fake_resolution) === null,
		'nextItem finds nothing while locked, rather than reading ciphertext');
	check($job->hasWork($config, $recipe) === false, 'and hasWork agrees');
	check(RecipeVaultScope::hasWork($owner_id) === false,
		'the deferred-work predicate reports nothing to do while locked');

	// -----------------------------------------------------------------------
	section('and visible while unlocked, newest first');

	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	check(VaultUnlock::isOpen($owner_id), 'precondition: the window is open');

	$item = $job->nextItem($config, $recipe, $fake_resolution);
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
	$item = $job->nextItem($config, $recipe, $fake_resolution);
	check($item !== null && $item['item_key'] === (string)$older,
		'a read message drops out and the older one is next');

	InboundEmailMessage::updateColumns($older, array('iem_pending_parse' => true));
	check($job->nextItem($config, $recipe, $fake_resolution) === null,
		'an unparsed message is never judged on its empty content');
	InboundEmailMessage::updateColumns($older, array('iem_pending_parse' => false));
	check($job->nextItem($config, $recipe, $fake_resolution) !== null, 'and returns once it is parsed');

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
		'score' => 42, 'verdict' => 'caution',
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
	check(is_array($decoded) && ($decoded['verdict'] ?? '') === 'caution',
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
	section('sealing is a SystemBase primitive, not per-model crypto (Layer 0)');

	// Exercised through MailboxContact, which carries the four convention
	// columns and no crypto code of its own — so what passes here is the base
	// implementation every future sealed model inherits, not a mailbox routine.
	function iw_contact(int $user_id): MailboxContact {
		$c = new MailboxContact(NULL);
		$c->set('imc_usr_user_id', $user_id);
		$c->set('imc_address_hash', hash('sha256', 'iw-' . bin2hex(random_bytes(8))));
		$c->set('imc_address', '');
		$c->set('imc_display_name', '');
		$c->save();
		$c->load();
		harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', intval($c->key));
		return $c;
	}

	$contact = iw_contact($owner_id);
	MailboxContact::sealColumns(intval($contact->key), $owner_vault, array(
		'imc_address'      => 'sealed.person@example.test',
		'imc_display_name' => 'Sealed Person',
	));

	$raw_contact = $db->prepare('SELECT * FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?');
	$raw_contact->execute(array($contact->key));
	$crow = $raw_contact->fetch(PDO::FETCH_ASSOC);
	check(strpos((string)$crow['imc_address'], 'v1.aead.') === 0
		&& strpos((string)$crow['imc_display_name'], 'v1.aead.') === 0,
		'sealColumns() wrote ciphertext to both content columns');
	check(!empty($crow['imc_sealed_key']) && intval($crow['imc_sealed_owner_user_id']) === $owner_id,
		'and recorded the wrapped DEK plus the owner it was wrapped to');

	$reload = new MailboxContact(intval($contact->key), TRUE);
	check((string)$reload->get('imc_address') === 'sealed.person@example.test',
		'the generic get() hook opens it again in-window');
	check((string)MailboxContact::decryptSealedFieldStatic('imc_display_name', $crow['imc_display_name'], $crow)
		=== 'Sealed Person',
		'and the raw-row hook agrees, so the two read paths cannot drift');

	// Same save() guarantee as mail, reached with zero mailbox-specific code.
	$reload->set('imc_use_count', 7);
	$reload->save();
	$raw_contact->execute(array($contact->key));
	$after = $raw_contact->fetch(PDO::FETCH_ASSOC);
	check($after['imc_address'] === $crow['imc_address'],
		'save() leaves the sealed columns byte-identical here too');
	check(intval($after['imc_use_count']) === 7, 'while the unsealed column it changed persists');

	// The AD binds a value to its row AND its column, so a ciphertext lifted
	// from another row is not merely wrong — it will not open at all.
	$other = iw_contact($owner_id);
	MailboxContact::sealColumns(intval($other->key), $owner_vault,
		array('imc_address' => 'someone.else@example.test'));
	$splice = $db->prepare('UPDATE imc_mailbox_contacts SET imc_address = ?
		WHERE imc_mailbox_contact_id = ?');
	$splice->execute(array($crow['imc_address'], intval($other->key)));
	$spliced = false;
	try {
		(new MailboxContact(intval($other->key), TRUE))->get('imc_address');
	} catch (Throwable $e) {
		$spliced = true;
	}
	check($spliced, 'a ciphertext moved to another row refuses to open');

	// Sealing a column nothing decrypts would write data no reader can recover,
	// so the writer refuses rather than accepting it.
	$refused_col = false;
	$refused_msg = '';
	try {
		MailboxContact::sealColumns(intval($contact->key), $owner_vault,
			array('imc_source' => MailboxContact::SOURCE_MANUAL));
	} catch (Throwable $e) {
		$refused_col = true;
		$refused_msg = $e->getMessage();
	}
	check($refused_col && strpos($refused_msg, 'imc_source') !== false,
		'sealColumns() refuses a column that is not in $sealed_fields', $refused_msg);

	VaultUnlock::lockAll($owner_id);
	$locked_read = false;
	try {
		(new MailboxContact(intval($contact->key), TRUE))->get('imc_address');
	} catch (VaultLockedException $e) {
		$locked_read = true;
	}
	check($locked_read, 'and with the vault locked the value is unreadable, not returned as ciphertext');
	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));

	// -----------------------------------------------------------------------
	section('raising a domain seals what earlier AI runs already wrote');

	// The gap this closes: a message triaged while its domain was Standard
	// carries derived content — an AI summary of the body, a scan whose red
	// flags quote it — in plaintext. Raising the domain sets the sealed flag on
	// that row. Seal an enumerated list of columns and those two are left
	// behind: readable in the clear on a domain whose whole point is that it is
	// not, AND unreadable to the reader, which now tries to AEAD-open plaintext.
	$late = iw_domain(InboundEmailDomain::LEVEL_STANDARD, true);
	$late_alias = iw_alias(intval($late->key), 'late', $owner_id);
	$late_address = 'late@' . $late->get('ied_domain');
	$late_msg = iw_message(intval($late->key), intval($late_alias->key),
		'Standard-era subject', 'Standard-era body.', $late_address, '-10');

	$ai_summary = 'A summary written while the domain was still Standard.';
	$ai_scan = json_encode(array('verdict' => 'caution',
		'red_flags' => array(array('finding' => 'quotes the body: Standard-era body.'))));
	// Both values are literals a few lines up, not anything this process
	// decrypted, so building the fixture is its own unit of work. Without the
	// boundary the hot-turn rule would refuse them purely because an earlier
	// section of this file read sealed mail — which is the rule working, and
	// exactly why RecipeRunner brackets each run the same way.
	SealedEgressGuard::isolate(function () use ($late_msg, $ai_summary, $ai_scan) {
		InboundEmailMessage::updateColumns($late_msg, array(
			'iem_ai_summary' => $ai_summary,
			'iem_ai_scan'    => $ai_scan,
		));
	});

	$late->set('ied_security_level', InboundEmailDomain::LEVEL_FORTRESS);
	$late->save();
	$late_sealed = mailbox_protection_seal_batch($late, 200);
	check($late_sealed['sealed'] === 1 && $late_sealed['remaining'] === 0,
		'the raise seals the pre-existing message', json_encode($late_sealed));

	$late_row = $db->prepare('SELECT * FROM iem_inbound_email_messages
		WHERE iem_inbound_email_message_id = ?');
	$late_row->execute(array($late_msg));
	$late_stored = $late_row->fetch(PDO::FETCH_ASSOC);
	check(strpos((string)$late_stored['iem_ai_summary'], 'v1.aead.') === 0,
		'the AI summary written before the raise is sealed, not left in the clear');
	check(strpos((string)$late_stored['iem_ai_scan'], 'v1.aead.') === 0,
		'and so is the scan, whose red flags quote the body');
	check(strpos((string)$late_stored['iem_ai_summary'], 'Standard') === false
		&& strpos((string)$late_stored['iem_ai_scan'], 'Standard-era body') === false,
		'no plaintext of either survives on the row');

	// And the reader still works — an enumerated seal list leaves these two
	// opening plaintext as if it were an AEAD blob, which throws.
	$late_read = new InboundEmailMessage($late_msg, TRUE);
	$read_ok = true;
	$read_error = '';
	try {
		$got_summary = (string)$late_read->get('iem_ai_summary');
		$got_scan    = (string)$late_read->get('iem_ai_scan');
	} catch (Throwable $e) {
		$read_ok = false;
		$read_error = get_class($e) . ': ' . $e->getMessage();
	}
	check($read_ok, 'the raised row still reads without throwing', $read_error);
	check($read_ok && $got_summary === $ai_summary && $got_scan === $ai_scan,
		'and both columns round-trip byte-for-byte');

	// -----------------------------------------------------------------------
	section('a run that reads protected mail is itself protected (Layer 1)');

	function iw_run(Recipe $recipe): RecipeRun {
		$run = new RecipeRun(NULL);
		$run->set('rcr_rcp_recipe_id', intval($recipe->key));
		$run->set('rcr_status', RecipeRun::STATUS_PENDING);
		$run->set('rcr_trigger', RecipeRun::TRIGGER_WINDOW);
		$run->save();
		$run->load();
		harness_register_row('rcr_recipe_runs', 'rcr_run_id', intval($run->key));
		return $run;
	}

	// What the runner writes for one item of a Fortress mailbox: the subject as
	// the label, and a model summary of the body. Both came out of the vault.
	$leaky_subject = 'Older note';
	$leaky_summary = 'A description of the encrypted body, written by the model.';
	$trace = json_encode(array(array(
		'item_key' => (string)$older, 'status' => 'done', 'label' => $leaky_subject,
		'verdict'  => array('label' => 'none', 'summary' => $leaky_summary),
	)));

	$sealed_run = iw_run($recipe);
	check($sealed_run->rowIsSealed() === false, 'a fresh run row starts unsealed');
	$sealed_run->sealToOwner($owner_vault);
	check($sealed_run->rowIsSealed() === true,
		'and seals once the runner sees the recipe reads a protected source');

	$sealed_run->writeContent(array(
		'rcr_output'     => "1 item\n- $leaky_subject: none",
		'rcr_tool_calls' => $trace,
		'rcr_error'      => '',
	));

	// Regression: the runner saves STATUS changes on the same instance that
	// sealed the row (seal at run start, then status → running). sealColumns()
	// wrote the key wrapping straight to the database, so this instance's
	// $data still holds NULL/0 for the seal metadata — save() must skip those
	// columns on a sealed row, or the wrapped DEK is destroyed while the seal
	// flag stays true and everything sealed above becomes permanently
	// unreadable. Every check below this line also re-proves decryptability
	// AFTER a save, because they read from fresh instances.
	$sealed_run->set('rcr_status', RecipeRun::STATUS_RUNNING);
	$sealed_run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
	$sealed_run->saveContent();
	$wrap_check = $db->prepare(
		'SELECT rcr_sealed_key, rcr_sealed_owner_user_id FROM rcr_recipe_runs WHERE rcr_run_id = ?');
	$wrap_check->execute(array($sealed_run->key));
	$wrap_row = $wrap_check->fetch(PDO::FETCH_ASSOC);
	check(!empty($wrap_row['rcr_sealed_key'])
		&& intval($wrap_row['rcr_sealed_owner_user_id']) === $owner_id,
		'a status save on the sealing instance leaves the DEK wrapping intact');

	// The estate assertion: EVERY column of the row, not a list someone has to
	// remember to update. A content column added later is covered by this
	// without anyone touching the test.
	$run_row = $db->prepare('SELECT * FROM rcr_recipe_runs WHERE rcr_run_id = ?');
	$run_row->execute(array($sealed_run->key));
	$stored = $run_row->fetch(PDO::FETCH_ASSOC);
	$leaked = array();
	foreach ($stored as $col => $value) {
		if (!is_string($value) || $value === '') continue;
		if (strpos($value, $leaky_subject) !== false || strpos($value, $leaky_summary) !== false) {
			$leaked[] = $col;
		}
	}
	check(count($leaked) === 0,
		'no column of the run row holds the subject or the summary in the clear',
		implode(', ', $leaked));
	check(strpos((string)$stored['rcr_output'], 'v1.aead.') === 0
		&& strpos((string)$stored['rcr_tool_calls'], 'v1.aead.') === 0,
		'both are stored as ciphertext instead');
	check(intval($stored['rcr_input_tokens']) === 0 && $stored['rcr_status'] !== null,
		'while the columns history renders from stay readable');

	// In-window the operator sees everything, from a freshly loaded row — not
	// from the writer's in-memory copy.
	$reloaded = new RecipeRun(intval($sealed_run->key), TRUE);
	check(strpos((string)$reloaded->contentOrNull('rcr_output'), $leaky_subject) !== false,
		'in-window the run reads back with its detail intact');
	$items = $reloaded->toolCalls();
	check(count($items) === 1 && ($items[0]['label'] ?? '') === $leaky_subject,
		'and the per-item trace decodes to an array, not a blob');

	// Out of window, history must still render: what the run DID is visible,
	// what it READ is not, and nothing throws.
	VaultUnlock::lockAll($owner_id);
	$locked_run = new RecipeRun(intval($sealed_run->key), TRUE);
	$rendered = true;
	$render_error = '';
	try {
		$out   = $locked_run->contentOrNull('rcr_output');
		$calls = $locked_run->toolCalls();
	} catch (Throwable $e) {
		$rendered = false;
		$render_error = get_class($e) . ': ' . $e->getMessage();
	}
	check($rendered, 'run history renders with the vault locked', $render_error);
	check($rendered && $out === null && $calls === array(),
		'showing no content rather than ciphertext or a placeholder string');
	check((string)$locked_run->get('rcr_status') === RecipeRun::STATUS_RUNNING,
		'while the status is still readable, because it is not content');
	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));

	// The suppression is conditional. A standard mailbox has nothing to protect
	// and real value in the detail, so its runs stay plaintext and searchable.
	$plain_run = iw_run($std_recipe);
	// Its own unit of work — see the fixture note in the raise section above.
	SealedEgressGuard::isolate(function () use ($plain_run, $leaky_subject) {
		$plain_run->writeContent(array('rcr_output' => "1 item\n- $leaky_subject: none"));
	});
	$run_row->execute(array($plain_run->key));
	$plain_stored = $run_row->fetch(PDO::FETCH_ASSOC);
	check(strpos((string)$plain_stored['rcr_output'], $leaky_subject) !== false,
		'a standard binding still records subjects, so this is not a blanket loss of detail');
	check(empty($plain_stored['rcr_content_sealed']), 'and its run row is not sealed');

	// -----------------------------------------------------------------------
	section('the catch-up purge clears runs recorded before sealing existed');

	// Runs recorded before run rows could seal hold subjects and summaries in
	// the clear. RunContentPurge is the catch-up, and it runs from the plugin
	// SYNC HOOK, not a core migration: migrations execute hundreds of lines
	// before PluginManager::sync() adds the sealing columns, so a migration
	// touching rcr_content_sealed runs against a table that has not got it yet.
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RunContentPurge.php'));

	$legacy = iw_run($recipe);              // $recipe reads the Fortress mailbox
	// Reconstructing a pre-sealing row is a fixture, not a derivation, so it
	// gets its own unit of work — the rule would otherwise refuse the very
	// state this section exists to purge.
	SealedEgressGuard::isolate(function () use ($legacy, $leaky_subject, $trace) {
		RecipeRun::updateColumns(intval($legacy->key), array(
			'rcr_output'     => "1 item\n- $leaky_subject: none",
			'rcr_tool_calls' => $trace,
			'rcr_input_tokens' => 4321,
		));
	});
	$run_row->execute(array($legacy->key));
	$before_purge = $run_row->fetch(PDO::FETCH_ASSOC);
	check(strpos((string)$before_purge['rcr_output'], $leaky_subject) !== false,
		'the pre-sealing run holds the subject in the clear, as those rows do');

	$purge_messages = RunContentPurge::run();
	$run_row->execute(array($legacy->key));
	$after_purge = $run_row->fetch(PDO::FETCH_ASSOC);
	check($after_purge['rcr_output'] === null && $after_purge['rcr_tool_calls'] === null,
		'the purge clears the content columns');
	check(intval($after_purge['rcr_input_tokens']) === 4321
		&& (string)$after_purge['rcr_status'] === RecipeRun::STATUS_PENDING,
		'and leaves the counts and status history renders from');
	check(count($purge_messages) === 1 && strpos($purge_messages[0], 'cleared plaintext') !== false,
		'and says how many rows it touched', json_encode($purge_messages));

	// Idempotent, because it runs on EVERY sync — not just once.
	check(RunContentPurge::run() === array(),
		'a second pass finds nothing and reports nothing');

	// A standard-binding run is not its business.
	$plain_keep = iw_run($std_recipe);
	RecipeRun::updateColumns(intval($plain_keep->key),
		array('rcr_output' => "1 item\n- $leaky_subject: none"));
	RunContentPurge::run();
	$run_row->execute(array($plain_keep->key));
	$kept = $run_row->fetch(PDO::FETCH_ASSOC);
	check(strpos((string)$kept['rcr_output'], $leaky_subject) !== false,
		'a standard-binding run keeps its detail');

	// The platform's own message about a run is not content, and is written by
	// actors who hold no key at all — cron's reaper, another admin's session.
	check(in_array('rcr_status_note', RecipeRun::$sealed_fields, true) === false,
		'the platform status note is deliberately not a sealed column');
	RecipeRun::updateColumns(intval($sealed_run->key),
		array('rcr_status_note' => 'reaper: worker process did not complete'));
	$note_row = new RecipeRun(intval($sealed_run->key), TRUE);
	check((string)$note_row->get('rcr_status_note') === 'reaper: worker process did not complete',
		'so cron can still say why a protected run died');

	// -----------------------------------------------------------------------
	section('sink zero: sealed mail does not reach a cloud model without consent');

	// This precedes every storage sink — the plaintext leaves over HTTPS, not
	// into a column, so no storage-side guard can see it.
	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

	// Make the cloud endpoint REACHABLE for this section. A pin to an endpoint
	// with no key is an availability fact, not a consent violation — it falls
	// back to the requirement and runs local, which is safe but tests nothing.
	// The case worth proving is a cloud model this install genuinely could use.
	$saved_anthropic = Globalvars::get_instance()->get_setting('joinery_ai_anthropic_api_key');
	harness_set_setting_mem('joinery_ai_anthropic_api_key', 'sink-zero-fixture-key');
	AiEndpointRegistry::clearCache();
	harness_defer(function () use ($saved_anthropic) {
		harness_set_setting_mem('joinery_ai_anthropic_api_key', $saved_anthropic);
		AiEndpointRegistry::clearCache();
	});

	check(AiEndpointRegistry::trustForModel('claude-haiku-4-5') === 'cloud',
		'a Claude model is classified cloud by the shipped catalog');
	check(AiEndpointRegistry::trustForModel('qwen3.6:35b-a3b-nvfp4') === 'local',
		'a model the operator serves themselves is classified local');
	check(AiEndpointRegistry::trustForModel('nothing-serves-this') === null,
		'and an id no endpoint serves is classified as nothing at all');

	// An UNPINNED recipe is the important case, and the easy one to get wrong.
	// Its model id says nothing, so the answer has to come from what the
	// requirement would actually resolve to. Shipped templates seed unpinned,
	// so they are the rows on this path.
	MailboxAliasConfig::clearPostureCache();
	check(MailboxAliasConfig::aiProcessingConsent($address) === InboundEmailDomain::CONSENT_LOCAL,
		'the sealed domain has not consented to its mail travelling');
	check(MailboxAliasConfig::aiProcessingConsent($std_address) === InboundEmailDomain::CONSENT_CLOUD,
		'while a standard domain has nothing to withhold');

	$unpinned = iw_recipe($owner_id, 'email_triage', $address);
	$unpinned->set('rcp_model', '');
	check(RecipeVaultScope::consentTrustFloor($unpinned) === AiModelRequirement::TRUST_LOCAL,
		'an unpinned sealed-source recipe is floored to local by its domain, not left open');
	check(RecipeVaultScope::requirementFor($unpinned)->trustFloor() === AiModelRequirement::TRUST_LOCAL,
		'and that floor is folded into the requirement, so nothing off-box is even a candidate');

	// A pin the domain does not permit is refused, naming the model and the
	// setting that would allow it.
	$recipe->set('rcp_model', 'claude-haiku-4-5');
	$refused_cloud = false;
	$cloud_message = '';
	try {
		RecipeVaultScope::resolveForRecipe($recipe);
	} catch (LlmProviderException $e) {
		$refused_cloud = true;
		$cloud_message = $e->getMessage();
	}
	check($refused_cloud, 'a sealed-source recipe pinned to a cloud model is refused');
	check(stripos($cloud_message, 'encrypted at rest') !== false,
		'and the refusal explains that the mail is sealed', $cloud_message);

	// A local model on the same sealed recipe is fine.
	$recipe->set('rcp_model', 'qwen3.6:35b-a3b-nvfp4');
	$local_ok = true;
	$local_why = '';
	try {
		$local_res = RecipeVaultScope::resolveForRecipe($recipe);
		check($local_res->isLocal(), 'and it resolves onto the local endpoint');
	} catch (LlmProviderException $e) {
		$local_ok = false;
		$local_why = $e->getMessage();
	}
	check($local_ok, 'the same recipe on a local model is allowed', $local_why);

	// Three-valued consent: the distinction a boolean could not express.
	$fortress->set('ied_ai_processing_consent', InboundEmailDomain::CONSENT_TRUSTED);
	$fortress->save();
	MailboxAliasConfig::clearPostureCache();
	check(RecipeVaultScope::consentTrustFloor($recipe) === AiModelRequirement::TRUST_TRUSTED,
		'a domain consenting to trusted processing floors the recipe at trusted');
	$recipe->set('rcp_model', 'claude-haiku-4-5');
	$trusted_refuses_cloud = false;
	try {
		RecipeVaultScope::resolveForRecipe($recipe);
	} catch (LlmProviderException $e) {
		$trusted_refuses_cloud = true;
	}
	check($trusted_refuses_cloud, 'and still refuses a cloud model');

	// Granting full consent lets the cloud model through...
	$fortress->set('ied_ai_processing_consent', InboundEmailDomain::CONSENT_CLOUD);
	$fortress->save();
	MailboxAliasConfig::clearPostureCache();
	check(RecipeVaultScope::consentTrustFloor($recipe) === AiModelRequirement::TRUST_ANY,
		'full consent imposes no floor at all');

	// ...and withdrawing it stops the recipe again, without the recipe changing.
	// This is the whole point of re-checking at run start rather than only at
	// save: the recipe row is identical either side of this.
	$fortress->set('ied_ai_processing_consent', InboundEmailDomain::CONSENT_LOCAL);
	$fortress->save();
	MailboxAliasConfig::clearPostureCache();
	$stopped_again = false;
	try {
		RecipeVaultScope::resolveForRecipe($recipe);
	} catch (LlmProviderException $e) {
		$stopped_again = true;
	}
	check($stopped_again,
		'withdrawing consent stops an already-saved recipe, with the recipe untouched');

	// A standard-mailbox recipe is never gated by this.
	$std_recipe->set('rcp_model', 'claude-haiku-4-5');
	check(RecipeVaultScope::consentTrustFloor($std_recipe) === null,
		'and an ordinary mailbox recipe imposes no consent floor at all');

	// A pin to an endpoint this install cannot reach is an AVAILABILITY fact,
	// not a consent violation: the requirement is still enough to run on, so it
	// falls back and lands somewhere the domain permits.
	harness_set_setting_mem('joinery_ai_anthropic_api_key', '');
	AiEndpointRegistry::clearCache();
	$fortress->set('ied_ai_processing_consent', InboundEmailDomain::CONSENT_LOCAL);
	$fortress->save();
	MailboxAliasConfig::clearPostureCache();
	$recipe->set('rcp_model', 'claude-haiku-4-5');
	$fell_back = null;
	try {
		$fell_back = RecipeVaultScope::resolveForRecipe($recipe);
	} catch (LlmProviderException $e) {}
	check($fell_back !== null && $fell_back->isLocal(),
		'an unreachable cloud pin falls back to the requirement and stays local',
		$fell_back === null ? 'refused' : $fell_back->modelId());
	check($fell_back !== null && $fell_back->substitutionNote() !== '',
		'and the substitution is recorded rather than silent');
	harness_set_setting_mem('joinery_ai_anthropic_api_key', 'sink-zero-fixture-key');
	AiEndpointRegistry::clearCache();

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
		$job->validateConfig(array('mailbox_aliases' => array($closed_address)),
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
		$job->validateConfig(array('mailbox_aliases' => array($closed_address)),
			iw_recipe($owner_id, 'email_triage', $closed_address));
	} catch (InvalidArgumentException $e) {
		$accepted = false;
		$message = $e->getMessage();
	}
	check($accepted, 'and accepted once the domain opts in', $message);

	// -----------------------------------------------------------------------
	section('the drain has a due gate, and adopts a stranded Run Now');

	// Everything above proves the drain can READ sealed mail. This proves it
	// only runs when the recipe's own Runs setting says so, and that a run a
	// PERSON asked for is never left waiting for a worker that cannot exist
	// (specs/recipe_run_scheduling.md § 2.5 and § 2.6).
	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
	check(VaultUnlock::isOpen($owner_id), 'precondition: the window is open');

	// Quiet baseline: every sealed message the earlier sections created is
	// read, so no arrival-scheduled recipe of this owner has anything to do.
	InboundEmailMessage::updateColumns($older, array('iem_is_read' => true));
	InboundEmailMessage::updateColumns($newer, array('iem_is_read' => true));
	InboundEmailMessage::updateColumns($late_msg, array('iem_is_read' => true));

	// The pending row the spawner refused back in 'cron refuses an in-window
	// recipe' is still sitting there — and under the adoption rule that row is
	// now exactly what makes its recipe pending. Assert that, then retire it so
	// the rest of this section starts from a quiet estate.
	check(in_array((int)$recipe->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'a pending row no worker can claim keeps its recipe pending, mail or no mail');
	// Retire every leftover pending row this suite built as a fixture — the
	// spawner-refusal row above and the run-sealing rows further down were made
	// to be INSPECTED, never dispatched, and under the adoption rule each one
	// now keeps its recipe pending. Cancelling them gives this section a quiet
	// estate to reason about.
	$db->query("UPDATE rcr_recipe_runs SET rcr_status = '" . RecipeRun::STATUS_CANCELLED . "'
		WHERE rcr_status = '" . RecipeRun::STATUS_PENDING . "'
		  AND rcr_rcp_recipe_id IN (SELECT rcp_recipe_id FROM rcp_recipes
		                            WHERE rcp_owner_user_id = " . intval($owner_id) . ")");

	check(count(RecipeVaultScope::pendingForOwner($owner_id)) === 0,
		'with nothing arrived, the work predicate is quiet — the heartbeat stops asking for drains',
		json_encode(array_map(fn($r) => (string)$r->get('rcp_name'),
			RecipeVaultScope::pendingForOwner($owner_id))));

	// An ARRIVAL recipe is due exactly when sealed mail is waiting — today's
	// behaviour, now opted into rather than imposed on every sealed recipe.
	InboundEmailMessage::updateColumns($newer, array('iem_is_read' => false));
	$arrival_ids = array_map(fn($r) => (int)$r->key, RecipeVaultScope::pendingForOwner($owner_id));
	check(in_array((int)$recipe->key, $arrival_ids, true),
		'unread sealed mail makes an arrival-scheduled recipe pending');
	InboundEmailMessage::updateColumns($newer, array('iem_is_read' => true));
	check(!in_array((int)$recipe->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'and it goes quiet again once nothing is unread');

	// A CLOCK-scheduled sealed recipe is due on its fire point instead, whether
	// or not it will find anything: an empty run costs one row per period, and
	// the alternative is a schedule that silently never fires.
	$clock = iw_recipe($owner_id, 'email_triage', $address);
	$clock->set('rcp_schedule_frequency', RecipeSchedule::FREQ_DAILY);
	$clock->set('rcp_schedule_time', '00:00:01');
	$clock->save();
	$clock->load();
	check(RecipeVaultScope::cronRunnable($clock) === false,
		'precondition: the clock recipe is fully sealed, so no worker can ever run it');
	check(in_array((int)$clock->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'past its fire point with nothing run since, it is pending even with no mail waiting');

	// One run since the fire point suppresses it until the next period — which
	// is what stops a due-but-empty recipe draining on every beat.
	$met = new RecipeRun(NULL);
	$met->set('rcr_rcp_recipe_id', intval($clock->key));
	$met->set('rcr_status', RecipeRun::STATUS_SUCCESS);
	$met->set('rcr_trigger', RecipeRun::TRIGGER_WINDOW);
	$met->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
	$met->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
	$met->save();
	$met->load();
	harness_register_row('rcr_recipe_runs', 'rcr_run_id', intval($met->key));
	check(!in_array((int)$clock->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'a run at or after the fire point suppresses it until the next period');

	// Now the stranded Run Now. Before adoption this row sat pending forever:
	// the spawner refuses a fully-sealed recipe, the drain skipped the recipe
	// because a pending row counted as an active run, and the pending reaper
	// deliberately leaves in-window rows alone.
	$manual = new RecipeRun(NULL);
	$manual->set('rcr_rcp_recipe_id', intval($clock->key));
	$manual->set('rcr_status', RecipeRun::STATUS_PENDING);
	$manual->set('rcr_trigger', RecipeRun::TRIGGER_MANUAL);
	$manual->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
	$manual->save();
	$manual->load();
	harness_register_row('rcr_recipe_runs', 'rcr_run_id', intval($manual->key));

	check(RecipeWorkerSpawner::spawnIfUnderCap($manual) === false,
		'no worker will take the Run Now row');
	$pending_now = RecipeVaultScope::pendingForOwner($owner_id);
	check(in_array((int)$clock->key, array_map(fn($r) => (int)$r->key, $pending_now), true),
		'but the pending row makes the recipe pending regardless of the due gate — '
		. 'a person pressed the button');
	check(count($pending_now) === 1,
		'and it is the only thing waiting, so the drain below runs exactly this row',
		json_encode(array_map(fn($r) => (string)$r->get('rcp_name'), $pending_now)));

	// Drive the drain with the kill flag set, so the run is claimed, executed
	// and finished without a single model call. What is under test is which ROW
	// the drain picked up, not what the model said.
	RecipeRun::updateColumns(intval($manual->key), array('rcr_kill_requested' => true));
	$runs_before = intval(DbConnector::get_instance()->get_db_link()
		->query('SELECT count(*) FROM rcr_recipe_runs WHERE rcr_rcp_recipe_id = '
			. intval($clock->key) . ' AND rcr_delete_time IS NULL')->fetchColumn());

	$executed = RecipeVaultScope::drain($owner_id, $secret, microtime(true) + 30);
	check($executed === 1, 'the drain executes exactly one run', (string)$executed);

	$adopted = new RecipeRun(intval($manual->key), TRUE);
	check((string)$adopted->get('rcr_status') !== RecipeRun::STATUS_PENDING,
		'the Run Now row reached a terminal state instead of waiting forever',
		(string)$adopted->get('rcr_status'));
	check((string)$adopted->get('rcr_trigger') === RecipeRun::TRIGGER_MANUAL,
		'and is still recorded as the manual run it was, not rewritten as a window run');

	$runs_after = intval(DbConnector::get_instance()->get_db_link()
		->query('SELECT count(*) FROM rcr_recipe_runs WHERE rcr_rcp_recipe_id = '
			. intval($clock->key) . ' AND rcr_delete_time IS NULL')->fetchColumn());
	check($runs_after === $runs_before,
		'the drain ADOPTED the row rather than queueing a second one behind it',
		"$runs_before -> $runs_after");

	// -----------------------------------------------------------------------
	section('Manually only still honours Run Now on a sealed recipe');

	// The new-recipe default. Run Now is the ONE trigger Manually only keeps,
	// and on a fully sealed recipe this drain is the only executor that
	// trigger has — so the pending row must reach it even though every
	// automatic path filters on rcp_enabled.
	$manual_only = iw_recipe($owner_id, 'email_triage', $address);
	$manual_only->set('rcp_enabled', false);
	$manual_only->save();
	$manual_only->load();

	check(!in_array((int)$manual_only->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'idle, a Manually-only recipe owes the drain nothing');

	$mo_run = new RecipeRun(NULL);
	$mo_run->set('rcr_rcp_recipe_id', intval($manual_only->key));
	$mo_run->set('rcr_status', RecipeRun::STATUS_PENDING);
	$mo_run->set('rcr_trigger', RecipeRun::TRIGGER_MANUAL);
	$mo_run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
	$mo_run->save();
	$mo_run->load();
	harness_register_row('rcr_recipe_runs', 'rcr_run_id', intval($mo_run->key));

	check(RecipeWorkerSpawner::spawnIfUnderCap($mo_run) === false,
		'no worker will take its Run Now row');
	check(in_array((int)$manual_only->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'but the row makes even a Manually-only recipe pending — a person pressed the button');

	RecipeRun::updateColumns(intval($mo_run->key), array('rcr_kill_requested' => true));
	check(RecipeVaultScope::drain($owner_id, $secret, microtime(true) + 30) === 1,
		'the drain executes it');
	$mo_done = new RecipeRun(intval($mo_run->key), TRUE);
	check((string)$mo_done->get('rcr_status') !== RecipeRun::STATUS_PENDING,
		'to a terminal state instead of waiting forever', (string)$mo_done->get('rcr_status'));
	check(count(RecipeVaultScope::pendingForOwner($owner_id)) === 0,
		'and the estate is quiet again');

	// -----------------------------------------------------------------------
	section('a mixed clock recipe keeps its sealed side');

	// One sealed address, one standard address, on a clock (the $mixed fixture
	// from the top of the file, switched on with one short column write — its
	// long source_config could only be saved before this process opened sealed
	// mail). Cron fires within a tick of every fire point and its worker reads
	// only the standard side, so if a worker run satisfied the sealed posture
	// too, the drain would never find this recipe due and its sealed mail
	// would simply never be read. Only a window run — which reads the whole
	// binding — satisfies the sealed side (RecipeSchedule::satisfyingTriggers).
	Recipe::updateColumns(intval($mixed->key), array('rcp_enabled' => true));
	$mixed = new Recipe(intval($mixed->key), TRUE);

	check(RecipeVaultScope::requiresWindow($mixed) && RecipeVaultScope::cronRunnable($mixed),
		'precondition: the binding is MIXED — a worker can run it, but not all of it');

	// The worker's run: what cron produces within a tick of the fire point.
	$worker_run = new RecipeRun(NULL);
	$worker_run->set('rcr_rcp_recipe_id', intval($mixed->key));
	$worker_run->set('rcr_status', RecipeRun::STATUS_SUCCESS);
	$worker_run->set('rcr_trigger', RecipeRun::TRIGGER_SCHEDULE);
	$worker_run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
	$worker_run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
	$worker_run->save();
	$worker_run->load();
	harness_register_row('rcr_recipe_runs', 'rcr_run_id', intval($worker_run->key));

	check(RecipeSchedule::isDue($mixed, PipelineJobInterface::POSTURE_STANDARD,
			gmdate('Y-m-d H:i:s')) === false,
		'a worker run satisfies cron');
	check(in_array((int)$mixed->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'but NOT the sealed side — the recipe is still due in the window');

	// A window run reads the whole binding, so it satisfies both sides.
	$window_run = new RecipeRun(NULL);
	$window_run->set('rcr_rcp_recipe_id', intval($mixed->key));
	$window_run->set('rcr_status', RecipeRun::STATUS_SUCCESS);
	$window_run->set('rcr_trigger', RecipeRun::TRIGGER_WINDOW);
	$window_run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
	$window_run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
	$window_run->save();
	$window_run->load();
	harness_register_row('rcr_recipe_runs', 'rcr_run_id', intval($window_run->key));

	check(!in_array((int)$mixed->key, array_map(fn($r) => (int)$r->key,
			RecipeVaultScope::pendingForOwner($owner_id)), true),
		'a window run satisfies the sealed side');
	check(RecipeSchedule::isDue($mixed, PipelineJobInterface::POSTURE_STANDARD,
			gmdate('Y-m-d H:i:s')) === false,
		'and cron stays satisfied too');

	VaultUnlock::lockAll($owner_id);

} finally {
	// Cleanup only — never harness_finish(). harness_finish() exit()s, so calling
	// it here would swallow an in-flight exception and report PASS on however
	// many checks happened to complete. Left outside, an exception propagates
	// uncaught and the harness shutdown reporter records the failure.
	// tests/estate/harness_contract_test.php enforces this shape.
	VaultUnlock::lockAll($owner_id ?? 0);
}

// harness_register_row/harness_defer reclaim every fixture row (LIFO), so a
// mid-suite failure still leaves no throwaway domain, alias, grant, message,
// recipe, run, or vault behind — harness_finish() and the crash net both run it.
harness_finish();
?>
