<?php
/**
 * EmailSecurityScanJob — the pipeline job's contract, against real throwaway
 * rows on an isolated test domain/alias (specs/joinery_ai_email_security_scan.md).
 *
 * Covers:
 *   - PipelineJobRegistry auto-discovers 'email_security_scan' from
 *     plugins/joinery_ai/pipeline_jobs/ (no manual injection).
 *   - configDescriptor() lists the test alias as an option.
 *   - validateConfig() rejects an owner with no mailbox grant, accepts one
 *     with a grant.
 *   - nextItem() picks the newest unread, non-spam, not-yet-logged message on the
 *     configured alias; excludes spam-verdict messages entirely.
 *   - validateVerdict() enforces the score/verdict band agreement.
 *   - recordVerdict() writes the three iem_ai_* fields on the right message,
 *     and refuses a message that isn't on the recipe's configured mailbox
 *     (the defense-in-depth check).
 *   - A full PipelineRunner::run() pass (scripted provider, real job, real
 *     digest) scores a real message end to end.
 *
 * Writes and permanently deletes throwaway rows (an isolated test domain +
 * alias, so nothing here can select real mail). Run:
 *   php tests/integration/email_security_scan_job_test.php
 *
 * @version 1.2
 */
/** @joinery-test
 * name: email_security_scan_job
 * tier: db
 * env: dev-only
 * needs: []
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(__DIR__ . '/../lib/llm_fixtures.php'); // ScriptedLlmProvider (+ LlmProviderInterface)
// Jobs are handed the run's model resolution so they can size a digest against
// the room they actually got. These tests exercise selection, not sizing.
$fake_resolution = fake_model_resolution();
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineRunner.php'));
require_once(PathHelper::getIncludePath('includes/DescriptorValidator.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/aip_recipe_item_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

/** Returns true if calling $fn throws InvalidArgumentException. */
function throws_invalid(callable $fn) {
    try { $fn(); return false; }
    catch (InvalidArgumentException $e) { return true; }
}

$db = DbConnector::get_instance()->get_db_link();
$owner_uid = (int)$db->query("SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10 AND usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetchColumn();
if ($owner_uid <= 0) {
    harness_skip('needs an active permission-10 admin to own the test recipe');
    harness_finish();
}

echo "EmailSecurityScanJob — pipeline job contract (owner_uid=$owner_uid)\n";

// --- Isolated test domain/alias — nothing here can select real mail --------
$suffix = gmdate('His') . '-' . mt_rand(1000, 9999);
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', "zztest-{$suffix}.example");
$domain->set('ied_is_enabled', true);
$domain->set('ied_reject_unmatched', true);
$domain->save();
// Crash-safe teardown registered at creation (LIFO runs children before parents).
harness_defer(function () use ($domain) { try { $domain->permanent_delete(); } catch (Throwable $e) {} });

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'zzscan');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
harness_defer(function () use ($alias) { try { $alias->permanent_delete(); } catch (Throwable $e) {} });

$address = 'zzscan@' . $domain->get('ied_domain');
echo "test alias: $address (id={$alias->key})\n\n";

// --- 1. Registry discovery ---------------------------------------------------
section("1. registry discovery");
$job = PipelineJobRegistry::get('email_security_scan');
ok('job resolves via the real filesystem scan (no manual injection)', $job instanceof EmailSecurityScanJob);
ok('label is non-empty', trim((string)$job->label()) !== '');

// --- 2. configDescriptor() lists the test alias -----------------------------
section("2. configDescriptor");
$descriptor = $job->configDescriptor();
$options = $descriptor['input']['mailbox_aliases']['options'] ?? [];
ok('test alias appears in the option list', array_key_exists($address, $options));

// --- 3. validateConfig(): grant required ------------------------------------
section("3. validateConfig");
$recipe = new Recipe(NULL);
$recipe->set('rcp_name', "email-security-scan-test-{$suffix}");
$recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
$recipe->set('rcp_pipeline_job', 'email_security_scan');
$recipe->set('rcp_owner_user_id', $owner_uid);
$recipe->set('rcp_source_config', ['mailbox_aliases' => [$address]]);
$recipe->set('rcp_max_iterations', 5);
$recipe->set('rcp_max_tokens', 5000);

$prepare_failed_without_grant = false;
try { $recipe->prepare(); } catch (RecipeException $e) { $prepare_failed_without_grant = true; }
ok('save fails: owner has no mailbox grant yet', $prepare_failed_without_grant);

// Grant the owner access, then the same recipe validates.
$pre_existing_grant = new MultiInboundEmailMailboxGrant(['alias_id' => (int)$alias->key, 'user_id' => $owner_uid]);
$pre_existing_grant->load();
$grant_created = false;
if (count($pre_existing_grant) === 0) {
    $grant = new InboundEmailMailboxGrant(NULL);
    $grant->set('ieg_iea_inbound_email_alias_id', (int)$alias->key);
    $grant->set('ieg_usr_user_id', $owner_uid);
    $grant->save();
    harness_defer(function () use ($grant) { try { $grant->permanent_delete(); } catch (Throwable $e) {} });
    $grant_created = true;
} else {
    $grant = $pre_existing_grant->current();
}

$prepare_ok = true;
try { $recipe->prepare(); } catch (RecipeException $e) { $prepare_ok = false; }
ok('save succeeds once the owner holds a grant', $prepare_ok);
$recipe->save();
$recipe_cleanup_id = (int)$recipe->key;
harness_defer(function () use ($recipe_cleanup_id) {
    $runs = new MultiRecipeRun(['recipe_id' => $recipe_cleanup_id]);
    $runs->load();
    foreach ($runs as $r) { $r->permanent_delete(); }
    $logs = new MultiAipRecipeItemLog(['recipe_id' => $recipe_cleanup_id]);
    $logs->load();
    foreach ($logs as $l) { $l->permanent_delete(); }
    $rr = new Recipe($recipe_cleanup_id, true);
    if ($rr->key) { $rr->permanent_delete(); }
});

// --- 4. nextItem(): newest unread, non-spam, not-yet-logged, on the configured alias
section("4. nextItem");
function make_message(int $domain_id, int $alias_id, string $subject, string $body,
        ?string $spam_verdict, string $received_offset_minutes) {
    $msg = new InboundEmailMessage(NULL);
    $msg->set('iem_ied_inbound_email_domain_id', $domain_id);
    $msg->set('iem_iea_inbound_email_alias_id', $alias_id);
    $msg->set('iem_sender', 'sender@example.com');
    $msg->set('iem_recipient', 'zzscan@zztest.example');
    $msg->set('iem_subject', $subject);
    $msg->set('iem_body_plain', $body);
    $msg->set('iem_body_html', '');
    $msg->set('iem_message_id_header', 'zztest-' . bin2hex(random_bytes(8)) . '@example.com');
    $msg->set('iem_direction', 'inbound');
    $msg->set('iem_spf_result', 'pass');
    $msg->set('iem_dkim_result', 'pass');
    $msg->set('iem_dmarc_result', 'pass');
    if ($spam_verdict !== null) $msg->set('iem_spam_verdict', $spam_verdict);
    $msg->set('iem_received_time', gmdate('Y-m-d H:i:s', strtotime("$received_offset_minutes minutes")));
    $msg->save();
    harness_defer(function () use ($msg) { try { $msg->permanent_delete(); } catch (Throwable $e) {} });
    return $msg;
}

$msg_oldest = make_message((int)$domain->key, (int)$alias->key, 'Oldest — benign', 'Just a normal note.', null, '-30');
$msg_spam   = make_message((int)$domain->key, (int)$alias->key, 'Spam — excluded', 'Buy now!!!', 'spam', '-25');
$msg_newer  = make_message((int)$domain->key, (int)$alias->key, 'Newer — phishy', 'Click http://evil.example/login now.', null, '-10');

$config = DescriptorValidator::coerce($job->configDescriptor(), Recipe::decodeSourceConfig($recipe));
$item1 = $job->nextItem($config, $recipe, $fake_resolution);
// Newest first (specs/in_window_deferred_work.md § New mail goes first): fresh
// mail is judged ahead of a backlog, not behind it.
ok('nextItem returns the newest non-spam message first', $item1 !== null && $item1['item_key'] === (string)$msg_newer->key);
ok('label carries the subject', ($item1['label'] ?? '') === 'Newer — phishy');
ok('digest carries the fixed digest header', strpos($item1['digest'] ?? '', '=== EMAIL DIGEST ===') !== false);

// Log the newest as done, then the spam-verdict message must still be
// skipped entirely (never selected) and the older message comes next.
$log = new AipRecipeItemLog(NULL);
$log->set('aip_rcp_recipe_id', (int)$recipe->key);
$log->set('aip_item_key', (string)$msg_newer->key);
$log->set('aip_status', AipRecipeItemLog::STATUS_DONE);
$log->prepare();
$log->save();

$item2 = $job->nextItem($config, $recipe, $fake_resolution);
ok('spam-verdict message is skipped; the older message is next', $item2 !== null && $item2['item_key'] === (string)$msg_oldest->key);

// Read mail is never judged: a summary helps you decide whether to open
// something, and a danger score is no use once you have.
// Targeted update: a full save() on a message row runs the alias-side
// bookkeeping this fixture has no need of.
InboundEmailMessage::updateColumns((int)$msg_oldest->key, ['iem_is_read' => true]);
ok('a message marked read drops out of selection', $job->nextItem($config, $recipe, $fake_resolution) === null);
ok('hasWork agrees with nextItem when nothing is left',
    $job->hasWork($config, $recipe) === false);
InboundEmailMessage::updateColumns((int)$msg_oldest->key, ['iem_is_read' => false]);
ok('and comes back when it is unread again', $job->hasWork($config, $recipe) === true);

// --- 5. validateVerdict(): score/verdict band agreement ---------------------
section("5. validateVerdict");
ok('mismatched band (score 9, verdict safe) is rejected',
    throws_invalid(fn() => $job->validateVerdict(['score' => 9, 'verdict' => 'safe'])));
$band_ok = true;
try { $job->validateVerdict(['score' => 9, 'verdict' => 'dangerous']); } catch (InvalidArgumentException $e) { $band_ok = false; }
ok('agreeing band (score 9, verdict dangerous) passes', $band_ok);

// The 4|5 boundary is the one the reader hangs its badge on: 0-4 is green and
// silent in the thread list, 5-6 is the amber caution band.
$edge_ok = true;
try {
    $job->validateVerdict(['score' => 4, 'verdict' => 'safe']);
    $job->validateVerdict(['score' => 5, 'verdict' => 'caution']);
    $job->validateVerdict(['score' => 6, 'verdict' => 'caution']);
} catch (InvalidArgumentException $e) { $edge_ok = false; }
ok('the 4|5 boundary holds (4=safe, 5-6=caution)', $edge_ok);
ok('score 5 is not safe', throws_invalid(fn() => $job->validateVerdict(['score' => 5, 'verdict' => 'safe'])));
ok('score 4 is not caution', throws_invalid(fn() => $job->validateVerdict(['score' => 4, 'verdict' => 'caution'])));

// --- 6. recordVerdict(): writes the fields; refuses a wrong-mailbox message -
section("6. recordVerdict");
// recordVerdict() authenticates against the current session, normally set
// to the recipe owner by RecipeRunner::setupActorSession() before the job
// ever runs. Replicate that here since this test calls the job directly.
SessionControl::get_instance()->set_api_user($owner_uid);
$verdict = [
    'score' => 8, 'verdict' => 'dangerous',
    'red_flags' => [['check' => 'C', 'finding' => 'Link points to evil.example, not the claimed brand.']],
    'summary' => 'This looks like a phishing attempt. Do not click the link.',
];
$job->recordVerdict((string)$msg_newer->key, $verdict, $recipe, 'fake/test-model');
$msg_newer->load();
ok('iem_ai_danger_score written', (int)$msg_newer->get('iem_ai_danger_score') === 8);
$scan = $msg_newer->get('iem_ai_scan');
if (is_string($scan)) $scan = json_decode($scan, true);
ok('iem_ai_scan carries the verdict/summary/model/recipe_id', is_array($scan)
    && ($scan['verdict'] ?? null) === 'dangerous'
    && ($scan['model'] ?? null) === 'fake/test-model'
    && ($scan['recipe_id'] ?? null) === (int)$recipe->key);
ok('iem_ai_scan_time stamped', trim((string)$msg_newer->get('iem_ai_scan_time')) !== '');

// recordVerdict() only ever writes the scan fields — logging the item as
// processed is PipelineRunner's job, normally done in the same loop
// iteration right after recordVerdict() returns.
//
// msg_newer was already logged in step 4 (it is the newest, so it was the
// first item the queue handed out). Logging the remaining unread message here
// leaves the recipe genuinely caught up, which is the precondition step 7
// asserts on.
$log2 = new AipRecipeItemLog(NULL);
$log2->set('aip_rcp_recipe_id', (int)$recipe->key);
$log2->set('aip_item_key', (string)$msg_oldest->key);
$log2->set('aip_status', AipRecipeItemLog::STATUS_DONE);
$log2->prepare();
$log2->save();

// A message on a different alias must be refused (defense in depth).
$other_domain = new InboundEmailDomain(NULL);
$other_domain->set('ied_domain', "zztest-other-{$suffix}.example");
$other_domain->save();
harness_defer(function () use ($other_domain) { try { $other_domain->permanent_delete(); } catch (Throwable $e) {} });
$other_alias = new InboundEmailAlias(NULL);
$other_alias->set('iea_ied_inbound_email_domain_id', (int)$other_domain->key);
$other_alias->set('iea_alias', 'zzother');
$other_alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$other_alias->prepare();
$other_alias->save();
harness_defer(function () use ($other_alias) { try { $other_alias->permanent_delete(); } catch (Throwable $e) {} });
$msg_elsewhere = make_message((int)$other_domain->key, (int)$other_alias->key, 'Elsewhere', 'body', null, '-5');

ok('recordVerdict refuses a message outside the configured mailbox', throws_invalid(
    fn() => $job->recordVerdict((string)$msg_elsewhere->key, $verdict, $recipe, 'fake/test-model')
));

// --- 7. Full PipelineRunner::run() pass, real job, real digest --------------
section("7. full pipeline run");
$run = new RecipeRun(NULL);
$run->set('rcr_rcp_recipe_id', (int)$recipe->key);
$run->set('rcr_status', RecipeRun::STATUS_RUNNING);
$run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
$run->save();
$ctx = new RecipeRunContext($recipe, $run);

$provider = new ScriptedLlmProvider([
    // msg_newer is already logged (step 6 recorded it) — the only remaining
    // item is msg_spam, which nextItem() must never surface, so end_turn
    // (caught up) with zero provider calls is the only correct outcome.
]);
$result = PipelineRunner::run($provider->resolution('fake/test-model'), $recipe, $ctx, 5, 5000, null, null);
ok('run ends caught-up with zero calls (only remaining row is spam-verdict)',
    $result['stop_reason'] === 'end_turn' && $provider->calls === 0);

// A fresh recipe/run against a brand-new benign+phish pair, actually judged.
$msg_a = make_message((int)$domain->key, (int)$alias->key, 'Newsletter', 'Thanks for subscribing.', null, '-2');
$msg_b = make_message((int)$domain->key, (int)$alias->key, 'Account alert', 'Sign in at http://evil.example/login now or lose access.', null, '-1');

$run2 = new RecipeRun(NULL);
$run2->set('rcr_rcp_recipe_id', (int)$recipe->key);
$run2->set('rcr_status', RecipeRun::STATUS_RUNNING);
$run2->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
$run2->save();
$ctx2 = new RecipeRunContext($recipe, $run2);

// Scripted in the order the queue hands items out: NEWEST first, so the
// account alert (msg_b, -1 min) is judged before the newsletter (msg_a, -2 min).
$provider2 = new ScriptedLlmProvider([
    ['text' => '{"score": 8, "verdict": "dangerous", "red_flags": [{"check":"D","finding":"Demands immediate sign-in."}], "summary": "Likely phishing."}'],
    ['text' => '{"score": 1, "verdict": "safe", "red_flags": [], "summary": "Ordinary newsletter."}'],
]);
$result2 = PipelineRunner::run($provider2->resolution('fake/test-model'), $recipe, $ctx2, 5, 5000, null, null);
ok('second run also ends caught-up', $result2['stop_reason'] === 'end_turn');
ok('both items judged (2 provider calls)', $provider2->calls === 2);

$msg_a->load(); $msg_b->load();
ok('newsletter scored 1 (safe)', (int)$msg_a->get('iem_ai_danger_score') === 1);
ok('account-alert scored 8 (dangerous)', (int)$msg_b->get('iem_ai_danger_score') === 8);

// Cleanup runs via the harness_defer calls registered at each fixture's creation
// (LIFO — children before parents), so a mid-suite failure still reclaims every
// throwaway domain, alias, grant, message, recipe, run, and log.

harness_finish();
