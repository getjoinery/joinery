<?php
/** @joinery-test
 * name: joinery_ai_pipeline_runner
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Item-pipeline mode — PipelineRunner's per-item loop end-to-end
 * (specs/joinery_ai_item_pipeline.md).
 *
 * Drives PipelineRunner::run() with a scripted fake provider and an
 * in-process fixture job (injected straight into PipelineJobRegistry's
 * static cache, bypassing the pipeline_jobs/ filesystem scan), against real
 * throwaway Recipe / RecipeRun / AipRecipeItemLog rows, asserting:
 *   - happy path: each item gets a valid verdict on the first exchange,
 *     recorded via the job, logged 'done', loop ends on nextItem() === null
 *   - invalid-verdict retry: a malformed first response gets exactly one
 *     retry with the specific validator error; a valid second response
 *     still counts as a normal success (no error logged)
 *   - skip-on-error: a verdict that's still invalid after the retry logs the
 *     item as 'error' and moves on — the item is excluded from reselection
 *   - 3 consecutive item errors abort the run (stop_reason 'tool_errors')
 *     without attempting a 4th item
 *   - kill switch: rcr_kill_requested set before run() short-circuits with
 *     zero provider calls
 *   - budget stop: a token budget exhausted after one item halts the run
 *     with stop_reason 'token_budget'
 *
 * Writes and permanently deletes throwaway rows. Run:
 *   php tests/integration/joinery_ai_pipeline_runner_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(__DIR__ . '/../lib/llm_fixtures.php'); // ScriptedLlmProvider (+ LlmProviderInterface)
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineRunner.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/aip_recipe_item_log_class.php'));

/** In-memory job: a fixed item list, real idempotency via the actual
 *  aip_recipe_item_log table (so the exclusion wiring is genuinely exercised,
 *  not assumed). Verdicts land in a static array instead of a real model. */
class FixtureJudgeJob implements PipelineJobInterface {
    public static $items = [];     // [[item_key, digest, label], ...]
    public static $recorded = [];  // item_key => verdict

    public function id(): string { return 'test_fixture_job'; }
    public function label(): string { return 'Test fixture job'; }
    public function configDescriptor(): array { return ['input' => []]; }
    public function validateConfig(array $config, Recipe $recipe): void {}
    public function untrustedDigest(): bool { return false; }

    /** This fixture's items are plain in-memory strings — no vault needed. */
    public function requiresVaultScope(array $config): ?string { return null; }

    /** No sealed source, so nothing to protect from a cloud model. */
    public function cloudProcessingAllowed(array $config): bool { return true; }

    public function hasWork(array $config, Recipe $recipe): bool {
        return $this->nextItem($config, $recipe) !== null;
    }

    public function countWork(array $config, Recipe $recipe): int {
        return $this->hasWork($config, $recipe) ? 1 : 0;
    }

    public function nextItem(array $config, Recipe $recipe): ?array {
        $db = DbConnector::get_instance()->get_db_link();
        foreach (self::$items as $item) {
            $sql = "SELECT 1 WHERE " . MultiAipRecipeItemLog::notExistsClause(':item_key');
            $q = $db->prepare($sql);
            $q->execute(['aip_recipe_id' => (int)$recipe->key, 'item_key' => $item['item_key']]);
            if ($q->fetchColumn()) return $item;
        }
        return null;
    }

    public function verdictDescriptor(): array {
        return ['input' => [
            'verdict' => ['type' => 'string', 'required' => true, 'enum' => ['keep', 'flag']],
        ]];
    }

    public function validateVerdict(array $verdict): void {}

    public function defaultPrompt(): string { return 'Judge the item. Respond keep or flag.'; }

    public function recordVerdict(string $item_key, array $verdict, Recipe $recipe, string $model): void {
        self::$recorded[$item_key] = $verdict;
    }
}

// Inject the fixture job directly into the registry's cache — bypasses the
// plugins/*/pipeline_jobs/ filesystem scan so no throwaway file is needed.
$registry_ref = new ReflectionClass('PipelineJobRegistry');
$jobs_prop = $registry_ref->getProperty('jobs');
$jobs_prop->setAccessible(true);
$jobs_prop->setValue(null, ['test_fixture_job' => 'FixtureJudgeJob']);

$db = DbConnector::get_instance()->get_db_link();
$owner_uid = (int)$db->query("SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10 AND usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetchColumn();
if ($owner_uid <= 0) {
    harness_skip('recipe owner', 'need at least one active permission-10 admin to own the test recipe');
    harness_finish();
}

echo "PipelineRunner — item pipeline loop\n";
echo "owner_uid=$owner_uid\n\n";

/** Build a fresh throwaway Recipe + running RecipeRun bound to the fixture job. */
function make_recipe_and_run(int $owner_uid, int $max_iterations, int $token_budget): array {
    $recipe = new Recipe(NULL);
    $recipe->set('rcp_name', 'pipeline-fixture-test ' . gmdate('His') . '-' . mt_rand(1000, 9999));
    $recipe->set('rcp_mode', Recipe::MODE_PIPELINE);
    $recipe->set('rcp_pipeline_job', 'test_fixture_job');
    $recipe->set('rcp_owner_user_id', $owner_uid);
    $recipe->set('rcp_max_iterations', $max_iterations);
    $recipe->set('rcp_max_tokens', $token_budget);
    $recipe->prepare();
    $recipe->save();

    // Crash-safe teardown registered at creation (LIFO): drop the recipe's logs
    // and runs, then the recipe itself — even if a later assertion throws.
    $rid = (int)$recipe->key;
    harness_defer(function () use ($rid) {
        $logs = new MultiAipRecipeItemLog(['recipe_id' => $rid]);
        $logs->load();
        foreach ($logs as $log) { $log->permanent_delete(); }
        $runs = new MultiRecipeRun(['recipe_id' => $rid]);
        $runs->load();
        foreach ($runs as $run) { $run->permanent_delete(); }
        $r = new Recipe($rid, true);
        if ($r->key) { $r->permanent_delete(); }
    });

    $run = new RecipeRun(NULL);
    $run->set('rcr_rcp_recipe_id', (int)$recipe->key);
    $run->set('rcr_status', RecipeRun::STATUS_RUNNING);
    $run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
    $run->save();

    $ctx = new RecipeRunContext($recipe, $run);
    return [$recipe, $run, $ctx];
}

// --- 1. Happy path: two items, each valid on the first try -----------------
section('1. happy path');
FixtureJudgeJob::$items = [
    ['item_key' => 'a1', 'digest' => 'item A', 'label' => 'Item A'],
    ['item_key' => 'a2', 'digest' => 'item B', 'label' => 'Item B'],
];
FixtureJudgeJob::$recorded = [];
[$recipe1, $run1, $ctx1] = make_recipe_and_run($owner_uid, 5, 5000);
$provider1 = new ScriptedLlmProvider([
    ['text' => '{"verdict": "keep"}'],
    ['text' => '{"verdict": "flag"}'],
]);
$result1 = PipelineRunner::run($provider1, 'fake/test-model', $recipe1, $ctx1, 5, 5000, null, null, 'off');
ok('stop_reason is end_turn (caught up)', $result1['stop_reason'] === 'end_turn');
ok('both items recorded', FixtureJudgeJob::$recorded === ['a1' => ['verdict' => 'keep'], 'a2' => ['verdict' => 'flag']]);
ok('provider called exactly twice', $provider1->calls === 2);
$log_count1 = (new MultiAipRecipeItemLog(['recipe_id' => (int)$recipe1->key]))->count_all();
ok('2 log rows written', $log_count1 === 2);

// --- 2. Invalid verdict, then a valid retry ---------------------------------
section('2. invalid-verdict retry');
FixtureJudgeJob::$items = [['item_key' => 'b1', 'digest' => 'item B1', 'label' => 'Item B1']];
FixtureJudgeJob::$recorded = [];
[$recipe2, $run2, $ctx2] = make_recipe_and_run($owner_uid, 5, 5000);
$provider2 = new ScriptedLlmProvider([
    ['text' => 'not json at all'],
    ['text' => '{"verdict": "keep"}'],
]);
$result2 = PipelineRunner::run($provider2, 'fake/test-model', $recipe2, $ctx2, 5, 5000, null, null, 'off');
ok('retry succeeded: item recorded', FixtureJudgeJob::$recorded === ['b1' => ['verdict' => 'keep']]);
ok('exactly one retry (2 calls) for the single item', $provider2->calls === 2);
$logged2 = new MultiAipRecipeItemLog(['recipe_id' => (int)$recipe2->key]);
$logged2->load();
$status2 = [];
foreach ($logged2 as $row) { $status2[] = $row->get('aip_status'); }
ok('item logged done (retry recovered)', $status2 === ['done']);

// --- 3. Still invalid after the retry: skip, log 'error', keep going -------
section('3. skip-on-error');
FixtureJudgeJob::$items = [
    ['item_key' => 'c1', 'digest' => 'item C1', 'label' => 'Item C1'],
    ['item_key' => 'c2', 'digest' => 'item C2', 'label' => 'Item C2'],
];
FixtureJudgeJob::$recorded = [];
[$recipe3, $run3, $ctx3] = make_recipe_and_run($owner_uid, 5, 5000);
$provider3 = new ScriptedLlmProvider([
    ['text' => 'still not json'],   // c1 attempt 1
    ['text' => 'still not json'],   // c1 attempt 2 (retry) — gives up
    ['text' => '{"verdict": "keep"}'], // c2 attempt 1 — succeeds
]);
$result3 = PipelineRunner::run($provider3, 'fake/test-model', $recipe3, $ctx3, 5, 5000, null, null, 'off');
ok('c1 never recorded (invalid verdict)', !isset(FixtureJudgeJob::$recorded['c1']));
ok('c2 recorded after c1 was skipped', FixtureJudgeJob::$recorded === ['c2' => ['verdict' => 'keep']]);
ok('run reached caught-up end (end_turn), not aborted', $result3['stop_reason'] === 'end_turn');
$logged3 = new MultiAipRecipeItemLog(['recipe_id' => (int)$recipe3->key], ['aip_item_key' => 'ASC']);
$logged3->load();
$rows3 = [];
foreach ($logged3 as $row) { $rows3[$row->get('aip_item_key')] = $row->get('aip_status'); }
ok('c1 logged error, c2 logged done', $rows3 === ['c1' => 'error', 'c2' => 'done']);

// --- 3b. Three consecutive item errors abort the run ------------------------
section('3b. three consecutive item errors abort the run');
FixtureJudgeJob::$items = [
    ['item_key' => 'd1', 'digest' => 'd1', 'label' => 'D1'],
    ['item_key' => 'd2', 'digest' => 'd2', 'label' => 'D2'],
    ['item_key' => 'd3', 'digest' => 'd3', 'label' => 'D3'],
    ['item_key' => 'd4', 'digest' => 'd4', 'label' => 'D4'],
];
FixtureJudgeJob::$recorded = [];
[$recipe4, $run4, $ctx4] = make_recipe_and_run($owner_uid, 5, 5000);
$provider4 = new ScriptedLlmProvider(array_fill(0, 8, ['text' => 'never valid json']));
$result4 = PipelineRunner::run($provider4, 'fake/test-model', $recipe4, $ctx4, 5, 5000, null, null, 'off');
ok('stop_reason is tool_errors', $result4['stop_reason'] === 'tool_errors');
ok('exactly 3 items attempted before abort (6 calls: 2 per item)', $provider4->calls === 6);
$logged4 = (new MultiAipRecipeItemLog(['recipe_id' => (int)$recipe4->key]))->count_all();
ok('3 items logged (d4 never attempted)', $logged4 === 3);

// --- 4. Kill switch: no provider calls at all -------------------------------
section('4. kill switch');
FixtureJudgeJob::$items = [['item_key' => 'e1', 'digest' => 'e1', 'label' => 'E1']];
[$recipe5, $run5, $ctx5] = make_recipe_and_run($owner_uid, 5, 5000);
$q = $db->prepare("UPDATE rcr_recipe_runs SET rcr_kill_requested = TRUE WHERE rcr_run_id = ?");
$q->execute([(int)$run5->key]);
$provider5 = new ScriptedLlmProvider([['text' => '{"verdict": "keep"}']]);
$result5 = PipelineRunner::run($provider5, 'fake/test-model', $recipe5, $ctx5, 5, 5000, null, null, 'off');
ok('stop_reason is cancelled', $result5['stop_reason'] === 'cancelled');
ok('provider never called', $provider5->calls === 0);
ok('nothing logged', (new MultiAipRecipeItemLog(['recipe_id' => (int)$recipe5->key]))->count_all() === 0);

// --- 5. Budget stop: one item processes, then the budget halts the run -----
section('5. budget stop');
FixtureJudgeJob::$items = [
    ['item_key' => 'f1', 'digest' => 'f1', 'label' => 'F1'],
    ['item_key' => 'f2', 'digest' => 'f2', 'label' => 'F2'],
];
FixtureJudgeJob::$recorded = [];
[$recipe6, $run6, $ctx6] = make_recipe_and_run($owner_uid, 5, 50);
$provider6 = new ScriptedLlmProvider([
    ['text' => '{"verdict": "keep"}', 'usage' => ['input_tokens' => 5, 'output_tokens' => 50,
        'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]],
    ['text' => '{"verdict": "keep"}'],
]);
$result6 = PipelineRunner::run($provider6, 'fake/test-model', $recipe6, $ctx6, 5, 50, null, null, 'off');
ok('stop_reason is token_budget', $result6['stop_reason'] === 'token_budget');
ok('exactly one item processed before the budget halted the run', $provider6->calls === 1);
ok('f1 recorded, f2 never reached', FixtureJudgeJob::$recorded === ['f1' => ['verdict' => 'keep']]);

// -------------------------------------------------------------------------
// Failure-email throttle. The last-sent time is a column on the recipe, not a
// stg_settings row keyed by recipe id — a runtime-built setting name can never
// be declared, so every failing recipe used to leave an undeclarable row behind.
// The decision is split out from the send so it can be checked without mail.

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunner.php'));
$throttled = new ReflectionMethod('RecipeRunner', 'failureEmailThrottled');
$throttled->setAccessible(true);

[$recipe7, $run7, $ctx7] = make_recipe_and_run($owner_uid, 5, 50);

ok('a recipe that has never notified is not throttled',
    $throttled->invoke(null, $recipe7, 86400) === false);

$recipe7->set('rcp_last_failure_email_time', gmdate('Y-m-d H:i:s'));
ok('one that just notified is throttled',
    $throttled->invoke(null, $recipe7, 86400) === true);

$recipe7->set('rcp_last_failure_email_time', gmdate('Y-m-d H:i:s', time() - 90000));
ok('and is free again once the window has passed',
    $throttled->invoke(null, $recipe7, 86400) === false);

$recipe7->set('rcp_last_failure_email_time', 'not a timestamp');
ok('an unparseable stamp notifies rather than silently suppressing',
    $throttled->invoke(null, $recipe7, 86400) === false);

$db_thr = DbConnector::get_instance()->get_db_link();
$q_thr = $db_thr->query("SELECT count(*) FROM stg_settings
    WHERE stg_name LIKE 'joinery_ai_last_failure_email_recipe_%'");
ok('no per-recipe throttle rows are left in stg_settings', (int)$q_thr->fetchColumn() === 0);

// Cleanup runs via the per-recipe harness_defer registered in make_recipe_and_run()
// — crash-safe (LIFO), so a mid-suite failure still reclaims every throwaway row.

harness_finish();
