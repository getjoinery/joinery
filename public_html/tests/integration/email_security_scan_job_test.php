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
 *   - nextItem() picks the oldest non-spam, not-yet-logged message on the
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
 * @version 1.1
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
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
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

class FakeVerdictProvider implements LlmProviderInterface {
    private $responses;
    public $calls = 0;
    public function __construct(array $responses) { $this->responses = $responses; }
    public function createMessageStreamed(array $params, callable $onTextDelta): array {
        return $this->createMessage($params);
    }
    public function createMessage(array $params): array {
        $this->calls++;
        $next = array_shift($this->responses) ?? ['text' => '{}'];
        return [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $next['text']]],
            'usage' => $next['usage'] ?? [
                'input_tokens' => 10, 'output_tokens' => 10,
                'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            ],
        ];
    }
    public function estimateCost(string $model, array $usage): float { return 0.0; }
    public function models(): array { return []; }
    public function defaultModel(): string { return 'fake/test-model'; }
    public function id(): string { return 'fake'; }
    public function isPrivate(): bool { return true; }
    public function modelCapabilities(string $model): array { return ['vision' => false, 'document' => false]; }
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

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'zzscan');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();

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
$options = $descriptor['input']['mailbox_alias']['options'] ?? [];
ok('test alias appears in the option list', array_key_exists($address, $options));

// --- 3. validateConfig(): grant required ------------------------------------
section("3. validateConfig");
$recipe = new Recipe(NULL);
$recipe->set('rcp_name', "email-security-scan-test-{$suffix}");
$recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
$recipe->set('rcp_pipeline_job', 'email_security_scan');
$recipe->set('rcp_owner_user_id', $owner_uid);
$recipe->set('rcp_source_config', ['mailbox_alias' => $address]);
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
    $grant_created = true;
} else {
    $grant = $pre_existing_grant->current();
}

$prepare_ok = true;
try { $recipe->prepare(); } catch (RecipeException $e) { $prepare_ok = false; }
ok('save succeeds once the owner holds a grant', $prepare_ok);
$recipe->save();

// --- 4. nextItem(): oldest non-spam, not-yet-logged, on the configured alias
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
    return $msg;
}

$msg_oldest = make_message((int)$domain->key, (int)$alias->key, 'Oldest — benign', 'Just a normal note.', null, '-30');
$msg_spam   = make_message((int)$domain->key, (int)$alias->key, 'Spam — excluded', 'Buy now!!!', 'spam', '-25');
$msg_newer  = make_message((int)$domain->key, (int)$alias->key, 'Newer — phishy', 'Click http://evil.example/login now.', null, '-10');

$config = DescriptorValidator::coerce($job->configDescriptor(), Recipe::decodeSourceConfig($recipe));
$item1 = $job->nextItem($config, $recipe);
ok('nextItem returns the oldest non-spam message first', $item1 !== null && $item1['item_key'] === (string)$msg_oldest->key);
ok('label carries the subject', ($item1['label'] ?? '') === 'Oldest — benign');
ok('digest carries the fixed digest header', strpos($item1['digest'] ?? '', '=== EMAIL DIGEST ===') !== false);

// Log the oldest as done, then the spam-verdict message must still be
// skipped entirely (never selected) and the newer message comes next.
$log = new AipRecipeItemLog(NULL);
$log->set('aip_rcp_recipe_id', (int)$recipe->key);
$log->set('aip_item_key', (string)$msg_oldest->key);
$log->set('aip_status', AipRecipeItemLog::STATUS_DONE);
$log->prepare();
$log->save();

$item2 = $job->nextItem($config, $recipe);
ok('spam-verdict message is skipped; the newer message is next', $item2 !== null && $item2['item_key'] === (string)$msg_newer->key);

// --- 5. validateVerdict(): score/verdict band agreement ---------------------
section("5. validateVerdict");
ok('mismatched band (score 9, verdict safe) is rejected',
    throws_invalid(fn() => $job->validateVerdict(['score' => 9, 'verdict' => 'safe'])));
$band_ok = true;
try { $job->validateVerdict(['score' => 9, 'verdict' => 'dangerous']); } catch (InvalidArgumentException $e) { $band_ok = false; }
ok('agreeing band (score 9, verdict dangerous) passes', $band_ok);

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
// iteration right after recordVerdict() returns. Replicate that pairing
// here since this test called recordVerdict() directly.
$log2 = new AipRecipeItemLog(NULL);
$log2->set('aip_rcp_recipe_id', (int)$recipe->key);
$log2->set('aip_item_key', (string)$msg_newer->key);
$log2->set('aip_status', AipRecipeItemLog::STATUS_DONE);
$log2->prepare();
$log2->save();

// A message on a different alias must be refused (defense in depth).
$other_domain = new InboundEmailDomain(NULL);
$other_domain->set('ied_domain', "zztest-other-{$suffix}.example");
$other_domain->save();
$other_alias = new InboundEmailAlias(NULL);
$other_alias->set('iea_ied_inbound_email_domain_id', (int)$other_domain->key);
$other_alias->set('iea_alias', 'zzother');
$other_alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$other_alias->prepare();
$other_alias->save();
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

$provider = new FakeVerdictProvider([
    // msg_newer is already logged (step 6 recorded it) — the only remaining
    // item is msg_spam, which nextItem() must never surface, so end_turn
    // (caught up) with zero provider calls is the only correct outcome.
]);
$result = PipelineRunner::run($provider, 'fake/test-model', $recipe, $ctx, 5, 5000, null, null, 'off');
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

$provider2 = new FakeVerdictProvider([
    ['text' => '{"score": 1, "verdict": "safe", "red_flags": [], "summary": "Ordinary newsletter."}'],
    ['text' => '{"score": 8, "verdict": "dangerous", "red_flags": [{"check":"D","finding":"Demands immediate sign-in."}], "summary": "Likely phishing."}'],
]);
$result2 = PipelineRunner::run($provider2, 'fake/test-model', $recipe, $ctx2, 5, 5000, null, null, 'off');
ok('second run also ends caught-up', $result2['stop_reason'] === 'end_turn');
ok('both items judged (2 provider calls)', $provider2->calls === 2);

$msg_a->load(); $msg_b->load();
ok('newsletter scored 1 (safe)', (int)$msg_a->get('iem_ai_danger_score') === 1);
ok('account-alert scored 8 (dangerous)', (int)$msg_b->get('iem_ai_danger_score') === 8);

// --- cleanup -----------------------------------------------------------------
echo "\ncleaning up...\n";
foreach ([$msg_oldest, $msg_spam, $msg_newer, $msg_elsewhere, $msg_a, $msg_b] as $m) {
    try { $m->permanent_delete(); } catch (Throwable $e) { echo "  (cleanup) message {$m->key}: {$e->getMessage()}\n"; }
}
try { $other_alias->permanent_delete(); } catch (Throwable $e) {}
try { $other_domain->permanent_delete(); } catch (Throwable $e) {}
if ($grant_created) {
    try { $grant->permanent_delete(); } catch (Throwable $e) {}
}
try { $alias->permanent_delete(); } catch (Throwable $e) {}
try { $domain->permanent_delete(); } catch (Throwable $e) {}

$rid = (int)$recipe->key;
$runs = new MultiRecipeRun(['recipe_id' => $rid]);
$runs->load();
foreach ($runs as $r) { $r->permanent_delete(); }
$logs = new MultiAipRecipeItemLog(['recipe_id' => $rid]);
$logs->load();
foreach ($logs as $l) { $l->permanent_delete(); }
$recipe->permanent_delete();

harness_finish();
