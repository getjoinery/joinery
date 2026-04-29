<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AnthropicClient.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/CostGuard.php'));

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

    /** Max output tokens per individual API call. The run-wide budget is
     *  enforced separately via $recipe->rcp_max_tokens. */
    const PER_CALL_MAX_TOKENS = 4096;

    /** Hard wall-clock timeout per run (sync mode only). Phase 5 raises this
     *  via async workers. */
    const WALL_CLOCK_SECONDS = 90;

    /** Abort the run if this many consecutive tool calls return is_error. */
    const CONSECUTIVE_TOOL_ERROR_LIMIT = 3;

    public static function run(RecipeRun $run): void {
        $started = microtime(true);

        try {
            $recipe = self::loadRecipe($run);
            $ctx = new RecipeRunContext($recipe, $run);

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

            $client = self::buildClient();
            $allowed_tools = self::resolveAllowedTools($recipe);
            $tool_schemas = RecipeToolRegistry::schemasFor($allowed_tools);
            $unknown = RecipeToolRegistry::unknown($allowed_tools);
            foreach ($unknown as $name) {
                $ctx->appendToolCall([
                    'name' => $name,
                    'note' => 'tool not found in registry; ignored',
                    'is_error' => true,
                ]);
            }

            $system = self::buildSystemPrompt($recipe, $ctx);
            $messages = [['role' => 'user', 'content' => 'Run the recipe now.']];

            $max_iterations = max(1, (int)$recipe->get('rcp_max_iterations'));
            $token_budget   = max(1000, (int)$recipe->get('rcp_max_tokens'));
            $tokens_input = 0;
            $tokens_output = 0;
            $tokens_cache_write = 0;
            $tokens_cache_read = 0;
            $consecutive_tool_errors = 0;
            $final_text = '';

            for ($iter = 0; $iter < $max_iterations; $iter++) {
                if (microtime(true) - $started > self::WALL_CLOCK_SECONDS) {
                    self::finishTimeout($run, $recipe, 'wall-clock timeout',
                        $tokens_input, $tokens_output, $tokens_cache_write, $tokens_cache_read);
                    return;
                }
                if ($tokens_input + $tokens_output >= $token_budget) {
                    self::finishTimeout($run, $recipe, 'token budget exhausted',
                        $tokens_input, $tokens_output, $tokens_cache_write, $tokens_cache_read);
                    return;
                }

                $params = [
                    'model'      => $recipe->get('rcp_model') ?: 'claude-haiku-4-5',
                    'max_tokens' => self::PER_CALL_MAX_TOKENS,
                    'system'     => $system,
                    'messages'   => $messages,
                ];
                if (!empty($tool_schemas)) {
                    $params['tools'] = $tool_schemas;
                }

                $response = $client->createMessage($params);

                $usage = $response['usage'] ?? [];
                $tokens_input        += (int)($usage['input_tokens'] ?? 0);
                $tokens_output       += (int)($usage['output_tokens'] ?? 0);
                $tokens_cache_write  += (int)($usage['cache_creation_input_tokens'] ?? 0);
                $tokens_cache_read   += (int)($usage['cache_read_input_tokens'] ?? 0);

                $stop_reason = $response['stop_reason'] ?? '';
                $content     = $response['content'] ?? [];

                $tool_uses = [];
                $iter_text = '';
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $iter_text .= ($block['text'] ?? '');
                    } elseif (($block['type'] ?? '') === 'tool_use') {
                        $tool_uses[] = $block;
                    }
                }

                if ($stop_reason === 'end_turn' || empty($tool_uses)) {
                    $final_text = $iter_text;
                    break;
                }

                if ($stop_reason === 'refusal') {
                    self::finishFailed($run, $recipe,
                        'Model refused: ' . ($iter_text ?: '(no message)'),
                        $tokens_input, $tokens_output, $tokens_cache_write, $tokens_cache_read);
                    return;
                }

                // Append assistant turn verbatim, then build a user turn of
                // tool_result blocks for the next iteration.
                $messages[] = ['role' => 'assistant', 'content' => $content];

                $tool_result_blocks = [];
                $iter_had_error = false;
                foreach ($tool_uses as $tu) {
                    $result_block = self::executeToolUse($tu, $ctx);
                    $tool_result_blocks[] = $result_block;
                    if (!empty($result_block['is_error'])) $iter_had_error = true;
                }

                if ($iter_had_error) {
                    $consecutive_tool_errors++;
                    if ($consecutive_tool_errors >= self::CONSECUTIVE_TOOL_ERROR_LIMIT) {
                        self::finishFailed($run, $recipe,
                            'consecutive_tool_failures: aborting after ' . $consecutive_tool_errors
                                . ' iterations of tool errors',
                            $tokens_input, $tokens_output, $tokens_cache_write, $tokens_cache_read);
                        return;
                    }
                } else {
                    $consecutive_tool_errors = 0;
                }

                $messages[] = ['role' => 'user', 'content' => $tool_result_blocks];
            }

            if ($final_text === '') {
                self::finishTimeout($run, $recipe, 'max_iterations reached without end_turn',
                    $tokens_input, $tokens_output, $tokens_cache_write, $tokens_cache_read);
                return;
            }

            self::finishSuccess($run, $recipe, $final_text,
                $tokens_input, $tokens_output, $tokens_cache_write, $tokens_cache_read);

        } catch (Exception $e) {
            $run->set('rcr_status', RecipeRun::STATUS_FAILED);
            $run->set('rcr_error', $e->getMessage());
            $run->set('rcr_completed_time', gmdate('Y-m-d H:i:s'));
            $run->save();
        }
    }

    // --- helpers ---

    private static function loadRecipe(RecipeRun $run): Recipe {
        $rid = (int)$run->get('rcr_rcp_recipe_id');
        if ($rid <= 0) throw new Exception('RecipeRun has no recipe id.');
        $r = new Recipe($rid, true);
        if (!$r->key) throw new Exception("Recipe $rid not found.");
        return $r;
    }

    private static function buildClient(): AnthropicClient {
        $settings = Globalvars::get_instance();
        $key = $settings->get_setting('joinery_ai_anthropic_api_key');
        return new AnthropicClient($key);
    }

    private static function resolveAllowedTools(Recipe $recipe): array {
        $tools = $recipe->get('rcp_allowed_tools');
        if (is_string($tools)) {
            $decoded = json_decode($tools, true);
            $tools = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($tools)) $tools = [];
        return array_values(array_filter(array_map('strval', $tools), 'strlen'));
    }

    /**
     * System prompt as an array of text blocks. cache_control on the last
     * block caches both `tools` and `system` together (render order is
     * tools → system → messages).
     */
    private static function buildSystemPrompt(Recipe $recipe, RecipeRunContext $ctx): array {
        $today_local = LibraryFunctions::convert_time(
            gmdate('Y-m-d H:i:s'), 'UTC', $ctx->owner_timezone, 'l, F j, Y g:i A T'
        );

        $preamble = "You are a Joinery AI recipe runner. You execute scheduled tasks "
                  . "by calling the tools available to you and producing a final text "
                  . "report. Do not chat — produce the report. Use Markdown for formatting.\n\n"
                  . "Current date/time (owner timezone): $today_local\n"
                  . "Recipe name: " . $recipe->get('rcp_name') . "\n";

        $instructions = "## Recipe instructions\n\n" . $recipe->get('rcp_prompt');

        return [
            [
                'type' => 'text',
                'text' => $preamble . "\n" . $instructions,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
    }

    private static function executeToolUse(array $tool_use, RecipeRunContext $ctx): array {
        $name = $tool_use['name'] ?? '';
        $id   = $tool_use['id']   ?? '';
        $input = $tool_use['input'] ?? [];
        $started = microtime(true);

        $tool = RecipeToolRegistry::get($name);
        if ($tool === null) {
            $msg = "Tool '$name' is not available to this recipe.";
            $ctx->appendToolCall([
                'name' => $name, 'input' => $input, 'is_error' => true,
                'output' => $msg, 'duration_ms' => 0,
            ]);
            return ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $msg, 'is_error' => true];
        }

        try {
            $result = $tool->execute(is_array($input) ? $input : [], $ctx);
        } catch (Exception $e) {
            $msg = get_class($e) . ': ' . $e->getMessage();
            $ctx->appendToolCall([
                'name' => $name, 'input' => $input, 'is_error' => true,
                'output' => $msg, 'duration_ms' => (int)((microtime(true) - $started) * 1000),
            ]);
            return ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $msg, 'is_error' => true];
        }

        $is_error = false;
        $content = '';
        if (is_array($result)) {
            $is_error = !empty($result['is_error']);
            $content  = (string)($result['content'] ?? '');
        } else {
            $content = (string)$result;
        }

        $ctx->appendToolCall([
            'name' => $name, 'input' => $input, 'is_error' => $is_error,
            'output' => $content, 'duration_ms' => (int)((microtime(true) - $started) * 1000),
        ]);

        $block = ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $content];
        if ($is_error) $block['is_error'] = true;
        return $block;
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
        $run->set('rcr_cost_estimate', AnthropicClient::estimateCost(
            (string)$recipe->get('rcp_model'),
            ['input_tokens' => $in, 'output_tokens' => $out,
             'cache_creation_input_tokens' => $cw, 'cache_read_input_tokens' => $cr]
        ));
    }

}
