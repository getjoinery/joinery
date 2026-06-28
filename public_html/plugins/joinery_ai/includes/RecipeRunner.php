<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelSchemaBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiPromptBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/TaintGate.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

/**
 * Bounded tool-use loop. Operates on a RecipeRun row that's already been
 * inserted (status=pending or running). The run ends with one of:
 *   success  — model returned end_turn; output captured
 *   timeout  — hit max_iterations or exhausted token budget
 *   failed   — exception or 3 consecutive tool errors
 *
 * Phase 2 scope: web_search only, no workspace, sync. Workspace, cost caps,
 * failure-email throttling, and async dispatch land in later phases.
 */
class RecipeRunner {

    /** Active provider for the current run — set in run(), read by
     *  recordTokens() for cost estimation. The runner is static and
     *  single-run-at-a-time, so a static member is sufficient. */
    private static $active_provider = null;

    public static function run(RecipeRun $run): void {
        try {
            $recipe = self::loadRecipe($run);
            $ctx = new RecipeRunContext($recipe, $run);

            // Pre-LLM checks (in order). All four fail fast before the first
            // inference call — together they cost zero tokens.
            //   1. Owner active   (lifecycle drift)
            //   2. Taint-gate     (model-class drift since save)
            //   3. Allow-list     (stale model/action references)
            //   4. Session setup  (actor identity for authenticate_write +
            //                       SessionControl-reading logic files)
            $owner_check = self::checkOwnerActive($recipe);
            if ($owner_check !== null) {
                self::finishStartupFailure($run, $recipe, $owner_check);
                return;
            }
            $taint_drift = self::checkTaintDrift($recipe);
            if ($taint_drift !== null) {
                self::finishStartupFailure($run, $recipe, $taint_drift);
                return;
            }
            $stale = self::checkAllowlistStaleness($recipe);
            if ($stale !== null) {
                self::finishStartupFailure($run, $recipe, $stale);
                return;
            }
            self::setupActorSession($recipe);

            // Cost protection — fires before any state mutation so a capped
            // run never gets a 'running' window. Skipped runs cost zero and
            // are immediately terminal.
            try {
                CostGuard::check($recipe);
            } catch (CapExceededException $e) {
                $run->set('rcr_status', RecipeRun::STATUS_SKIPPED);
                $run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
                $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
                $run->set('rcr_error', $e->getMessage());
                $run->save();
                return;
            }

            $run->set('rcr_status', RecipeRun::STATUS_RUNNING);
            $run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
            $run->set('rcr_workspace_before', (string)$recipe->get('rcp_workspace'));
            $run->save();

            // The recipe's pinned model selects the provider (claude-* →
            // Anthropic, else local). A recipe that pins no model follows the
            // global-default provider and that provider's default model.
            $model_pref = (string)$recipe->get('rcp_model');
            $provider = LlmProviderFactory::forModel($model_pref);
            self::$active_provider = $provider;
            $allowed_tools = self::resolveAllowedTools($recipe);
            $model = $model_pref !== '' ? $model_pref : $provider->defaultModel();
            $system = self::buildSystemPrompt($recipe, $ctx);
            $messages = [['role' => 'user', 'content' => 'Run the recipe now.']];
            $max_iterations = max(1, (int)$recipe->get('rcp_max_iterations'));
            $token_budget   = max(1000, (int)$recipe->get('rcp_max_tokens'));

            // Model controls: recipe row → plugin-setting default → floor, the same
            // resolution chat uses, so a scheduled run and a chat are steered alike.
            $settings    = Globalvars::get_instance();
            $temperature = AgentLoop::resolveFloat($recipe->get('rcp_temperature'),
                $settings->get_setting('joinery_ai_default_temperature'));
            $top_p       = AgentLoop::resolveFloat($recipe->get('rcp_top_p'),
                $settings->get_setting('joinery_ai_default_top_p'));
            $thinking    = AgentLoop::resolveThinkingLevel($recipe->get('rcp_thinking_level'),
                $settings->get_setting('joinery_ai_default_thinking_level'));

            $result = AgentLoop::run($provider, $model, $system, $messages,
                $allowed_tools, $ctx, $max_iterations, $token_budget,
                $temperature, $top_p, $thinking);

            self::finishFromResult($run, $recipe, $result, $max_iterations);

        } catch (LlmProviderException $e) {
            $code = LlmProviderException::classify($e);
            $run->set('rcr_status', RecipeRun::STATUS_FAILED);
            $run->set('rcr_error', "[$code] " . $e->getMessage());
            $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
            $run->save();
        } catch (Exception $e) {
            $run->set('rcr_status', RecipeRun::STATUS_FAILED);
            $run->set('rcr_error', $e->getMessage());
            $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
            $run->save();
        }
    }

    /**
     * Map the shared AgentLoop result onto the recipe's terminal states.
     * The recipe context never returns a pending_action (it doesn't require
     * confirmation), so that branch is defensive only.
     */
    private static function finishFromResult(RecipeRun $run, Recipe $recipe, array $result, int $max_iterations): void {
        $in = (int)$result['input_tokens'];
        $out = (int)$result['output_tokens'];
        $cw = (int)$result['cache_write_tokens'];
        $cr = (int)$result['cache_read_tokens'];

        switch ($result['stop_reason']) {
            case 'end_turn':
            case 'max_iterations':
                if ($result['assistant_text'] !== '') {
                    self::finishSuccess($run, $recipe, $result['assistant_text'], $in, $out, $cw, $cr);
                } else {
                    self::finishIncomplete($run, $recipe,
                        'iteration budget exhausted at ' . $max_iterations . ' iterations',
                        $in, $out, $cw, $cr);
                }
                break;
            case 'cancelled':
                self::finishCancelled($run, $recipe, $in, $out, $cw, $cr);
                break;
            case 'wall_clock':
                self::finishTimeout($run, $recipe, 'wall-clock timeout', $in, $out, $cw, $cr);
                break;
            case 'token_budget':
                self::finishTimeout($run, $recipe, 'token budget exhausted', $in, $out, $cw, $cr);
                break;
            case 'refusal':
            case 'tool_errors':
                self::finishFailed($run, $recipe, $result['detail'], $in, $out, $cw, $cr);
                break;
            default:
                self::finishFailed($run, $recipe,
                    'unexpected loop stop: ' . $result['stop_reason'], $in, $out, $cw, $cr);
                break;
        }
    }

    // --- run-start lifecycle / drift checks ---

    /**
     * Owner active? Returns null on success, or a string error message
     * naming the specific failure (deleted, soft-deleted, demoted).
     * Catches the configuration drift that would otherwise let writes
     * happen as a stale admin.
     */
    private static function checkOwnerActive(Recipe $recipe): ?string {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        if ($owner_id <= 0) {
            return 'Recipe has no owner. Edit the recipe to set an owner before running.';
        }
        $user = new User($owner_id, true);
        if (!$user->key) {
            return "Recipe owner (user $owner_id) does not exist. Transfer ownership "
                 . "to an active permission-10 admin before re-running.";
        }
        if ($user->get('usr_delete_time')) {
            return "Recipe owner (user $owner_id) has been deleted. Transfer ownership "
                 . "to an active permission-10 admin before re-running.";
        }
        if ((int)$user->get('usr_permission') < 10) {
            return "Recipe owner (user $owner_id) is no longer a permission-10 admin. "
                 . "Transfer ownership to an active admin before re-running.";
        }
        return null;
    }

    /**
     * Re-evaluate the taint gate at run-start to catch drift since save —
     * specifically, models that newly declared $ai_untrusted_fields after
     * the recipe was last saved. Returns null on success, or a specific
     * error message if the gate is now triggered without rcp_allow_tainted_writes.
     */
    private static function checkTaintDrift(Recipe $recipe): ?string {
        if ($recipe->get('rcp_allow_tainted_writes')) return null;

        $tools = self::decodeJsonArray($recipe->get('rcp_allowed_tools'));
        $models = self::decodeJsonArray($recipe->get('rcp_allowed_models'));
        $workspace = (string)$recipe->get('rcp_workspace');

        $eval = TaintGate::evaluate($tools, $models, $workspace);
        if (!$eval['tainted_capable']) return null;

        return 'Taint gate triggered at run start: ' . TaintGate::describeDrift($eval);
    }

    /**
     * Resolve allow-list entries against live registries. Returns null if
     * everything resolves, or an error message naming the missing entries.
     */
    private static function checkAllowlistStaleness(Recipe $recipe): ?string {
        $models = self::decodeJsonArray($recipe->get('rcp_allowed_models'));
        $actions = self::decodeJsonArray($recipe->get('rcp_allowed_actions'));

        $missing_models = [];
        if (!empty($models)) {
            $registry = ModelRegistry::all();
            foreach ($models as $name) {
                if (!is_string($name)) continue;
                if (!isset($registry[$name])) $missing_models[] = $name;
            }
        }

        $missing_actions = [];
        if (!empty($actions)) {
            $action_registry = ActionRegistry::all();
            foreach ($actions as $name) {
                if (!is_string($name)) continue;
                if (!isset($action_registry[$name])) $missing_actions[] = $name;
            }
        }

        $errs = [];
        if (!empty($missing_models)) {
            $errs[] = 'Recipe references models that no longer exist: ['
                   . implode(', ', $missing_models) . '].';
        }
        if (!empty($missing_actions)) {
            $errs[] = 'Recipe references actions that no longer exist: ['
                   . implode(', ', $missing_actions) . '].';
        }
        if (empty($errs)) return null;
        return implode(' ', $errs) . ' Edit the recipe to remove these entries.';
    }

    /**
     * Initialize SessionControl with the recipe owner's identity. Logic
     * files use SessionControl::get_instance() to read user identity /
     * permission, the same way they do under HTTP request handling.
     *
     * Acknowledged as pragmatic-not-optimal — the singleton mutation works
     * because each run is a single PHP worker process, but it carries the
     * coupling of all global-state designs. See spec § Acknowledged as
     * pragmatic, not optimal.
     */
    private static function setupActorSession(Recipe $recipe): void {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        if ($owner_id <= 0) return;
        $session = SessionControl::get_instance();
        $session->set_api_user($owner_id);
    }

    /**
     * Common terminal path for run-start lifecycle / drift failures. Marks
     * the run as failed with a specific error before any LLM call has
     * happened — costs zero tokens.
     */
    private static function finishStartupFailure(RecipeRun $run, Recipe $recipe, string $reason): void {
        $run->set('rcr_status', RecipeRun::STATUS_FAILED);
        $run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
        $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
        $run->set('rcr_error', $reason);
        $run->save();
        self::sendFailureEmailIfNotThrottled($recipe, $run, 'failed');
    }

    private static function decodeJsonArray($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Map a provider error to a stable error code so the admin can tell config
     * from infrastructure. Providers throw LlmProviderException with the
     * upstream message embedded; we string-match on the body because the
     * upstream codes aren't surfaced as a separate field. The substrings keyed
     * here ('4xx', 'timeout', 'connection', ...) are emitted by every provider,
     * so this works regardless of which one is active.
     */
    // --- helpers ---

    private static function loadRecipe(RecipeRun $run): Recipe {
        $rid = (int)$run->get('rcr_rcp_recipe_id');
        if ($rid <= 0) throw new Exception('RecipeRun has no recipe id.');
        $r = new Recipe($rid, true);
        if (!$r->key) throw new Exception("Recipe $rid not found.");
        return $r;
    }

    private static function resolveAllowedTools(Recipe $recipe): array {
        $tools = $recipe->get('rcp_allowed_tools');
        if (is_string($tools)) {
            $decoded = json_decode($tools, true);
            $tools = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($tools)) $tools = [];
        $tools = array_values(array_filter(array_map('strval', $tools), 'strlen'));

        // query_model and describe_models are implied by the model allowlist,
        // not chosen as tool checkboxes. Strip any stale entries, then add them
        // back iff the recipe has at least one allowed model. Without models,
        // exposing them would just produce tools that error on every call.
        $tools = array_values(array_filter($tools, fn($t) => $t !== 'query_model' && $t !== 'describe_models'));

        $models = $recipe->get('rcp_allowed_models');
        if (is_string($models)) {
            $decoded = json_decode($models, true);
            $models = is_array($decoded) ? $decoded : [];
        }
        if (is_array($models) && !empty($models)) {
            $tools[] = 'query_model';
            $tools[] = 'describe_models';
        }

        return $tools;
    }

    /**
     * System prompt as an array of text blocks. cache_control on the last
     * block caches both `tools` and `system` together (render order is
     * tools → system → messages).
     *
     * The model-schema block (one section per entry in rcp_allowed_models)
     * lives in this same cached prefix. Schemas are static for the lifetime
     * of the recipe, so repeated runs hit the prompt cache and pay near-zero
     * input tokens for the catalog.
     */
    private static function buildSystemPrompt(Recipe $recipe, RecipeRunContext $ctx): array {
        $today_local = LibraryFunctions::convert_time(
            gmdate('Y-m-d H:i:s'), 'UTC', $ctx->owner_timezone, 'l, F j, Y g:i A T'
        );

        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        $preamble = "You are a Joinery AI recipe runner. You execute scheduled tasks "
                  . "by calling the tools available to you and producing a final text "
                  . "report. Do not chat — produce the report. Use Markdown for formatting.\n\n"
                  . "Current date/time (owner timezone): $today_local\n"
                  . "Recipe name: " . $recipe->get('rcp_name') . "\n"
                  . "Recipe owner user_id: $owner_id (permission 10, admin reach)\n"
                  . "Use this user_id when a write tool needs an owner / created_by / "
                  . "updated_by column set.\n";

        $models_block = self::buildModelsBlock($recipe);

        $instructions = "## Recipe instructions\n\n" . $recipe->get('rcp_prompt');

        $text = $preamble . "\n";
        if ($models_block !== '') $text .= $models_block . "\n";
        $text .= $instructions;

        // Workspace carry-over is structurally untrusted even though the recipe
        // wrote it: a tainted value read on run N can be copied into the
        // workspace and influence run N+1's prompt. Declare it as an extra
        // untrusted source alongside any untrusted model fields.
        $extra_sources = [];
        if (trim((string)$recipe->get('rcp_workspace')) !== '') {
            $extra_sources[] = 'The persistent workspace, which carries LLM-curated '
                . 'state between runs and may have been influenced by content read '
                . 'in prior runs.';
        }
        $allowed = self::decodeJsonArray($recipe->get('rcp_allowed_models'));
        $untrusted = AiPromptBuilder::untrustedInputBlock($allowed, $ctx->untrustedNonce(), $extra_sources);

        return AiPromptBuilder::systemBlocks($text, $untrusted);
    }

    /**
     * Render the allowed-models catalog (names + descriptions). Full field
     * schemas are fetched on demand via describe_models, so this stays small
     * regardless of how many fields the models have. '' if the recipe has no
     * allowed models — query_model / describe_models are then withheld.
     */
    private static function buildModelsBlock(Recipe $recipe): string {
        $allowed = self::decodeJsonArray($recipe->get('rcp_allowed_models'));
        return AiPromptBuilder::modelCatalogBlock($allowed);
    }

    private static function finishSuccess(RecipeRun $run, Recipe $recipe, string $text,
            int $in, int $out, int $cw, int $cr): void {
        $run->set('rcr_status', RecipeRun::STATUS_SUCCESS);
        $run->set('rcr_output', $text);
        self::recordTokens($run, $recipe, $in, $out, $cw, $cr);
        $workspace_after = (string)$recipe->get('rcp_workspace');
        $run->set('rcr_workspace_after', $workspace_after);
        $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
        $run->save();

        // Persist any set_workspace mutation back to the recipe row. Only on
        // success — failed/timeout runs leave the prior workspace untouched per
        // spec, so the LLM doesn't poison its own state on a half-run.
        if ($workspace_after !== (string)$run->get('rcr_workspace_before')) {
            $recipe->set('rcp_update_time', gmdate('Y-m-d H:i:s'));
            $recipe->save();
        }

        self::sendSuccessEmailIfConfigured($recipe, $run, $text);
    }

    /**
     * Email the run's output to rcp_delivery_email if set. The dashboard
     * render is always available; this email is opt-in per recipe to avoid
     * inbox noise for recipes the user only wants to glance at via dashboard.
     */
    private static function sendSuccessEmailIfConfigured(Recipe $recipe, RecipeRun $run, string $text): void {
        $to = trim((string)$recipe->get('rcp_delivery_email'));
        if ($to === '') return;

        try {
            require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
            require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
            require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));

            $name = $recipe->get('rcp_name');
            $html_body = '<h2>' . htmlspecialchars($name) . '</h2>'
                       . MarkdownRenderer::render($text)
                       . '<hr><p style="font-size:0.85em;color:#666;">'
                       . 'Generated by Joinery AI. '
                       . '<a href="/admin/joinery_ai/run?rcr_run_id=' . (int)$run->key . '">View run details</a>'
                       . '</p>';

            $message = EmailMessage::create($to, "[Joinery AI] $name", $text)
                                   ->html($html_body);
            (new EmailSender())->send($message);
        } catch (Exception $e) {
            error_log('[joinery_ai] success email send failed: ' . $e->getMessage());
        }
    }

    private static function finishTimeout(RecipeRun $run, Recipe $recipe, string $why,
            int $in, int $out, int $cw, int $cr): void {
        $run->set('rcr_status', RecipeRun::STATUS_TIMEOUT);
        $run->set('rcr_error', $why);
        self::recordTokens($run, $recipe, $in, $out, $cw, $cr);
        $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
        $run->save();
        self::sendFailureEmailIfNotThrottled($recipe, $run, 'timeout');
    }

    private static function finishFailed(RecipeRun $run, Recipe $recipe, string $why,
            int $in, int $out, int $cw, int $cr): void {
        $run->set('rcr_status', RecipeRun::STATUS_FAILED);
        $run->set('rcr_error', $why);
        self::recordTokens($run, $recipe, $in, $out, $cw, $cr);
        $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
        $run->save();
        self::sendFailureEmailIfNotThrottled($recipe, $run, 'failed');
    }

    /**
     * Iteration budget exhausted — the LLM was still emitting tool calls
     * when the cap fired. Distinct from success (didn't conclude) and
     * failed (no error occurred — the system worked as configured).
     * Distinguishing this state matters for diagnosis.
     */
    private static function finishIncomplete(RecipeRun $run, Recipe $recipe, string $why,
            int $in, int $out, int $cw, int $cr): void {
        $run->set('rcr_status', RecipeRun::STATUS_INCOMPLETE);
        $run->set('rcr_error', $why);
        self::recordTokens($run, $recipe, $in, $out, $cw, $cr);
        $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
        $run->save();
        self::sendFailureEmailIfNotThrottled($recipe, $run, 'incomplete');
    }

    /**
     * Mid-run cancellation — admin clicked Stop. Marks run as cancelled
     * and exits cleanly. No failure email — cancellation is intentional.
     */
    private static function finishCancelled(RecipeRun $run, Recipe $recipe,
            int $in, int $out, int $cw, int $cr): void {
        $run->set('rcr_status', RecipeRun::STATUS_CANCELLED);
        $run->set('rcr_error', 'cancelled by admin');
        self::recordTokens($run, $recipe, $in, $out, $cw, $cr);
        $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
        $run->save();
    }

    /**
     * Throttle failure-notification emails per recipe. The throttle is a
     * stg_settings row keyed to the recipe ID storing the last-sent UTC
     * timestamp. Default throttle is 24h (joinery_ai_failure_email_throttle_seconds).
     * The first failure after a quiet window emails; subsequent failures
     * within the window are silent.
     */
    private static function sendFailureEmailIfNotThrottled(Recipe $recipe, RecipeRun $run, string $kind): void {
        try {
            $settings = Globalvars::get_instance();
            $throttle_secs = (int)$settings->get_setting('joinery_ai_failure_email_throttle_seconds');
            if ($throttle_secs <= 0) $throttle_secs = 86400;

            $key = 'joinery_ai_last_failure_email_recipe_' . (int)$recipe->key;
            $last = (int)$settings->get_setting($key);
            if ($last && (time() - $last) < $throttle_secs) return;

            $owner_id = (int)$recipe->get('rcp_owner_user_id');
            if ($owner_id <= 0) return;

            require_once(PathHelper::getIncludePath('data/users_class.php'));
            require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
            require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

            $user = new User($owner_id, true);
            $to = $recipe->get('rcp_delivery_email') ?: $user->get('usr_email');
            if (!$to) return;

            $name = $recipe->get('rcp_name');
            $subject = "Joinery AI: recipe '$name' $kind";
            $body = "Recipe '$name' $kind on its most recent run.\n\n"
                  . "Error: " . $run->get('rcr_error') . "\n\n"
                  . "Run details: /admin/joinery_ai/run?rcr_run_id=" . (int)$run->key . "\n\n"
                  . "Further failure emails for this recipe will be suppressed for the next "
                  . round($throttle_secs / 3600, 1) . " hours.";

            (new EmailSender())->send(EmailMessage::create($to, $subject, $body));

            // Record last-sent time. Using direct SQL to avoid the
            // round-trip cost of a Setting model load.
            $db = DbConnector::get_instance()->get_db_link();
            $q = $db->prepare(
                "INSERT INTO stg_settings (stg_name, stg_value, stg_create_time)
                 VALUES (?, ?, NOW() AT TIME ZONE 'UTC')
                 ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value"
            );
            $q->execute([$key, (string)time()]);
        } catch (Exception $e) {
            error_log('[joinery_ai] failure email send failed: ' . $e->getMessage());
        }
    }

    private static function recordTokens(RecipeRun $run, Recipe $recipe,
            int $in, int $out, int $cw, int $cr): void {
        $run->set('rcr_input_tokens', $in);
        $run->set('rcr_output_tokens', $out);
        // Cost is estimated by the active provider (local providers return 0.0).
        // recordTokens is only reached after the provider is built; guard
        // defensively so a null never fatals the run finalizer.
        $cost = 0.0;
        if (self::$active_provider !== null) {
            $cost = self::$active_provider->estimateCost(
                (string)$recipe->get('rcp_model'),
                ['input_tokens' => $in, 'output_tokens' => $out,
                 'cache_creation_input_tokens' => $cw, 'cache_read_input_tokens' => $cr]
            );
        }
        $run->set('rcr_cost_estimate', $cost);
    }

}
