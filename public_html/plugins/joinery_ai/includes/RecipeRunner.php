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
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineRunner.php'));
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

    /**
     * One run is one unit of work: it reads one recipe's source and writes that
     * recipe's trace, tally, verdicts and notification. Several runs share a
     * process — an in-window drain slice works through everything one user has
     * pending — and they are independent of each other, so each gets its own
     * hot-turn state. Without that boundary the first protected run in a slice
     * would make every later run in it fail, including runs against mailboxes
     * with nothing to protect. See includes/SealedEgressGuard.php.
     */
    public static function run(RecipeRun $run, ?float $deadline = null): void {
        require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
        SealedEgressGuard::isolate(function () use ($run, $deadline) {
            self::runIsolated($run, $deadline);
        });
    }

    private static function runIsolated(RecipeRun $run, ?float $deadline = null): void {
        try {
            $recipe = self::loadRecipe($run);

            // A run that reads protected material IS protected material
            // (specs/implemented/sealed_content_egress.md § Layer 1). Decided here, before
            // the first content write of any kind — including the startup
            // failures below, whose messages can name what they looked at.
            self::sealRunIfSourceIsSealed($run, $recipe);

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
                $run->saveContent();
                return;
            }

            $run->set('rcr_status', RecipeRun::STATUS_RUNNING);
            $run->set('rcr_started_time', gmdate('Y-m-d H:i:s'));
            $run->set('rcr_workspace_before', (string)$recipe->get('rcp_workspace'));
            $run->saveContent();

            // Sink zero. Re-checked here and not only at save: a domain can
            // withdraw its cloud consent after the recipe was saved, and that
            // must stop the next run rather than let it keep shipping decrypted
            // mail to a vendor. Throws before any content is read.
            RecipeVaultScope::assertModelAllowed($recipe);

            // The recipe's pinned model selects the provider (claude-* →
            // Anthropic, else local). A recipe that pins no model follows the
            // global-default provider and that provider's default model.
            $model_pref = (string)$recipe->get('rcp_model');
            $provider = LlmProviderFactory::forModel($model_pref);
            self::$active_provider = $provider;
            $model = $model_pref !== '' ? $model_pref : $provider->defaultModel();
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

            if ((string)$recipe->get('rcp_mode') === Recipe::MODE_PIPELINE) {
                // PHP drives item selection; the model judges one item per
                // exchange. No tools, no workspace, no conversation carry-over
                // — see specs/joinery_ai_item_pipeline.md.
                $result = PipelineRunner::run($provider, $model, $recipe, $ctx,
                    $max_iterations, $token_budget, $temperature, $top_p, $thinking, $deadline);
            } else {
                $allowed_tools = self::resolveAllowedTools($recipe);
                $system = self::buildSystemPrompt($recipe, $ctx);
                $messages = [['role' => 'user', 'content' => 'Run the recipe now.']];

                $result = AgentLoop::run($provider, $model, $system, $messages,
                    $allowed_tools, $ctx, $max_iterations, $token_budget,
                    $temperature, $top_p, $thinking);
            }

            self::finishFromResult($run, $recipe, $result, $max_iterations);

        } catch (LlmProviderException $e) {
            $code = LlmProviderException::classify($e);
            $run->set('rcr_status', RecipeRun::STATUS_FAILED);
            $run->set('rcr_error', "[$code] " . $e->getMessage());
            $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
            $run->saveContent();
        } catch (Exception $e) {
            $run->set('rcr_status', RecipeRun::STATUS_FAILED);
            $run->set('rcr_error', $e->getMessage());
            $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
            $run->saveContent();
        }
    }

    /**
     * Map the shared AgentLoop result onto the recipe's terminal states.
     */
    private static function finishFromResult(RecipeRun $run, Recipe $recipe, array $result, int $max_iterations): void {
        $in = (int)$result['input_tokens'];
        $out = (int)$result['output_tokens'];
        $cw = (int)$result['cache_write_tokens'];
        $cr = (int)$result['cache_read_tokens'];

        switch ($result['stop_reason']) {
            case 'end_turn':
            case 'max_iterations':
            case 'deadline':
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
     * the recipe was last saved (agent mode), or a pipeline job that newly
     * declares untrustedDigest() (pipeline mode). Returns null on success, or
     * a specific error message if the gate is now triggered without
     * rcp_allow_tainted_writes.
     */
    private static function checkTaintDrift(Recipe $recipe): ?string {
        if ($recipe->get('rcp_allow_tainted_writes')) return null;

        if ((string)$recipe->get('rcp_mode') === Recipe::MODE_PIPELINE) {
            $job = PipelineJobRegistry::get((string)$recipe->get('rcp_pipeline_job'));
            $eval = TaintGate::evaluate([], [], '', $job !== null && $job->untrustedDigest());
        } else {
            $tools = self::decodeJsonArray($recipe->get('rcp_allowed_tools'));
            $models = self::decodeJsonArray($recipe->get('rcp_allowed_models'));
            $workspace = (string)$recipe->get('rcp_workspace');
            $eval = TaintGate::evaluate($tools, $models, $workspace);
        }
        if (!$eval['tainted_capable']) return null;

        return 'Stopped before the run began: ' . TaintGate::describeDrift($eval);
    }

    /**
     * Resolve allow-list entries against live registries. Returns null if
     * everything resolves, or an error message naming the missing entries.
     * In pipeline mode, the only allow-list entry is the pipeline job itself.
     */
    private static function checkAllowlistStaleness(Recipe $recipe): ?string {
        if ((string)$recipe->get('rcp_mode') === Recipe::MODE_PIPELINE) {
            $job_id = (string)$recipe->get('rcp_pipeline_job');
            if (PipelineJobRegistry::get($job_id) === null) {
                return "Recipe references a pipeline job that no longer exists: [$job_id]. "
                     . 'Edit the recipe to select a registered job.';
            }
            return null;
        }

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
        // An in-window slice runs inside the owner's own browser request
        // (specs/in_window_deferred_work.md § How they run). Installing the
        // synthetic actor there would overwrite the live session of the person
        // browsing. They are already the owner — there is nothing to set up.
        if ((int)$session->get_user_id() === $owner_id) return;
        $session->set_api_user($owner_id);
    }

    /**
     * Seal the run row when the recipe reads from a sealed source, so every
     * content column it later writes is encrypted to the recipe's owner.
     *
     * Only the owner's vault PUBLIC key is needed, so this works whether or not
     * a window is open, and a run whose window lapses mid-way keeps writing
     * sealed rather than falling back to plaintext.
     *
     * A recipe whose owner has no vault cannot have a sealed source in the first
     * place — the protection ceremony refuses to seal a domain with no owning
     * key — so the null return here is a no-op on ordinary recipes, not a
     * silent downgrade.
     */
    private static function sealRunIfSourceIsSealed(RecipeRun $run, Recipe $recipe): void {
        try {
            if (RecipeVaultScope::forRecipe($recipe) === null) {
                return; // ordinary recipe: nothing it reads is protected
            }
            require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
            $vault = UserEncryptionVault::loadForUser((int)$recipe->get('rcp_owner_user_id'));
            if ($vault === null) {
                return;
            }
            $run->sealToOwner($vault);
        } catch (Throwable $e) {
            // Never let this abort a run silently — but do say so, because an
            // unsealed run against a sealed source is exactly the leak.
            error_log('[joinery_ai] could not seal run ' . (int)$run->key . ': ' . $e->getMessage());
        }
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
        $run->saveContent();
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
        $run->saveContent();

        // Persist any set_workspace mutation back to the recipe row. Only on
        // success — failed/timeout runs leave the prior workspace untouched per
        // spec, so the LLM doesn't poison its own state on a half-run.
        if ($workspace_after !== (string)$run->contentOrNull('rcr_workspace_before')) {
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

        // Mail is an unencrypted channel. A sealed run's tally is exactly the
        // subjects and summaries the vault exists to keep off it, so the email
        // says the run finished and where to read it, and nothing more.
        if ($run->rowIsSealed()) {
            $text = "This recipe reads protected content, so its results are not sent by email.\n\n"
                  . "Open the run to read them while your vault is unlocked.";
        }

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
            // Content-free exactly when the tally was replaced by the pointer
            // above. A standard run's tally IS content, so if this process also
            // read protected mail the send is refused rather than quietly sent.
            (new EmailSender())->send($message, true, null,
                $run->rowIsSealed() ? EmailSender::EGRESS_CONTENT_FREE : '');
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
        $run->saveContent();
        self::sendFailureEmailIfNotThrottled($recipe, $run, 'timeout');
    }

    private static function finishFailed(RecipeRun $run, Recipe $recipe, string $why,
            int $in, int $out, int $cw, int $cr): void {
        $run->set('rcr_status', RecipeRun::STATUS_FAILED);
        $run->set('rcr_error', $why);
        self::recordTokens($run, $recipe, $in, $out, $cw, $cr);
        $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
        $run->saveContent();
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
        $run->saveContent();
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
        $run->saveContent();
    }

    /**
     * Throttle failure-notification emails per recipe. The last-sent time is a
     * column on the recipe row (rcp_last_failure_email_time). Default throttle
     * is 24h (joinery_ai_failure_email_throttle_seconds). The first failure
     * after a quiet window emails; subsequent failures within the window are
     * silent.
     *
     * It is a column and not a setting because the key would have to carry the
     * recipe id, and a name built at runtime can never appear in a manifest —
     * so every recipe that ever failed minted an undeclarable stg_settings row
     * and reddened the declared-settings backstop. Per-recipe state belongs on
     * the recipe.
     */
    /**
     * Has this recipe emailed its owner about a failure recently enough to stay
     * quiet now?
     *
     * Split out so the throttle decision is testable without sending mail — the
     * caller returns before the send, so the two are otherwise indistinguishable
     * from outside. A recipe that has never sent one is never throttled.
     */
    private static function failureEmailThrottled(Recipe $recipe, int $throttle_secs): bool {
        $last_sent = trim((string)$recipe->get('rcp_last_failure_email_time'));
        if ($last_sent === '') return false;
        $last = strtotime($last_sent . ' UTC');
        if (!$last) return false;   // unparseable stamp: notify rather than swallow
        return (time() - $last) < $throttle_secs;
    }

    private static function sendFailureEmailIfNotThrottled(Recipe $recipe, RecipeRun $run, string $kind): void {
        try {
            $settings = Globalvars::get_instance();
            $throttle_secs = (int)$settings->get_setting('joinery_ai_failure_email_throttle_seconds');
            if ($throttle_secs <= 0) $throttle_secs = 86400;

            if (self::failureEmailThrottled($recipe, $throttle_secs)) return;

            $owner_id = (int)$recipe->get('rcp_owner_user_id');
            if ($owner_id <= 0) return;

            require_once(PathHelper::getIncludePath('data/users_class.php'));
            require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
            require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

            $user = new User($owner_id, true);
            $to = $recipe->get('rcp_delivery_email') ?: $user->get('usr_email');
            if (!$to) return;

            // A provider or job error can echo the content that caused it, so on
            // a sealed run the detail stays behind the vault and the mail is a
            // pointer. Same rule as the success tally.
            $detail = $run->rowIsSealed()
                ? '(withheld — this recipe reads protected content; open the run to read the error)'
                : (string)$run->contentOrNull('rcr_error');

            $name = $recipe->get('rcp_name');
            $subject = "Joinery AI: recipe '$name' $kind";
            $body = "Recipe '$name' $kind on its most recent run.\n\n"
                  . "Error: " . $detail . "\n\n"
                  . "Run details: /admin/joinery_ai/run?rcr_run_id=" . (int)$run->key . "\n\n"
                  . "Further failure emails for this recipe will be suppressed for the next "
                  . round($throttle_secs / 3600, 1) . " hours.";

            // Same rule as the success email: content-free only because the
            // error detail was withheld a few lines above.
            (new EmailSender())->send(EmailMessage::create($to, $subject, $body), true, null,
                $run->rowIsSealed() ? EmailSender::EGRESS_CONTENT_FREE : '');

            // Record last-sent time. A targeted UPDATE rather than $recipe->save():
            // the recipe object here came from the runner and a full-row save
            // would write back every column it happens to be holding.
            $db = DbConnector::get_instance()->get_db_link();
            $q = $db->prepare(
                'UPDATE rcp_recipes SET rcp_last_failure_email_time = ? WHERE rcp_recipe_id = ?'
            );
            $q->execute([gmdate('Y-m-d H:i:s'), (int)$recipe->key]);
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
