<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ToolContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RiskHeuristic.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));

/**
 * Bounded tool-use loop shared by every AI surface. Given a provider, a
 * system prompt, the conversation so far, and a tool allow-list, it drives
 * build-params → createMessage → dispatch tool calls → feed results back, up
 * to max_iterations or until the per-turn token budget is spent.
 *
 * Surface-specific behavior is reached through the context, never baked in:
 *   - shouldContinue(): per-iteration guard (recipe = kill-flag + wall clock;
 *     chat = per-turn timeout). Returns {stop_reason, detail} to halt or null.
 *   - requiresConfirmation(): when true, a mutating call that RiskHeuristic
 *     classes CONFIRM halts the turn with a pending_action instead of running
 *     — the interactive confirmation boundary. False for recipes, so the hook
 *     is inert and the loop behaves exactly as the recipe runner did.
 *   - beginToolCall()/finishToolCall(): durable per-call audit.
 *
 * Returns an array:
 *   assistant_text, input_tokens, output_tokens, cache_write_tokens,
 *   cache_read_tokens, messages (updated), stop_reason, detail, pending_action.
 * stop_reason is one of: end_turn, max_iterations, token_budget, refusal,
 *   tool_errors, pending_action, or any reason the context's shouldContinue()
 *   returns (recipe: cancelled, wall_clock).
 */
class AgentLoop {

    /** Max output tokens per individual API call. The turn-wide budget is
     *  enforced separately via the $token_budget argument. */
    const PER_CALL_MAX_TOKENS = 4096;

    /** Abort the turn if this many consecutive iterations return a tool error. */
    const CONSECUTIVE_TOOL_ERROR_LIMIT = 3;

    public static function run(
        LlmProviderInterface $provider,
        string $model,
        array $system,
        array $messages,
        array $allowed_tools,
        ToolContext $context,
        int $max_iterations,
        int $token_budget
    ): array {
        $tool_schemas = RecipeToolRegistry::schemasFor($allowed_tools);
        foreach (RecipeToolRegistry::unknown($allowed_tools) as $unknown_name) {
            $context->appendToolCall([
                'name'     => $unknown_name,
                'note'     => 'tool not found in registry; ignored',
                'is_error' => true,
            ]);
        }

        $in = 0; $out = 0; $cw = 0; $cr = 0;
        $consecutive_tool_errors = 0;
        $assistant_text = '';
        $stop_reason = 'max_iterations';
        $detail = 'iteration budget exhausted at ' . $max_iterations . ' iterations';
        $pending_action = null;

        for ($iter = 0; $iter < $max_iterations; $iter++) {
            // Surface-specific continuation guard (recipe: kill flag, then
            // wall clock). Token budget is shared, so it lives here.
            $halt = $context->shouldContinue();
            if ($halt !== null) {
                $stop_reason = $halt['stop_reason'];
                $detail = $halt['detail'] ?? '';
                break;
            }
            // Budget caps *generated* (output) tokens per turn — its documented
            // meaning ("max output tokens per turn"). Counting input here would
            // conflate the budget with the size of the (cached) system prompt:
            // a large model-schema catalog would exhaust the budget before the
            // model could reply at all. Input/total cost is bounded separately
            // by the per-call cap and the monthly CostGuard ceilings.
            if ($out >= $token_budget) {
                $stop_reason = 'token_budget';
                $detail = 'output token budget exhausted';
                break;
            }

            $params = [
                'model'      => $model,
                'max_tokens' => self::PER_CALL_MAX_TOKENS,
                'system'     => $system,
                'messages'   => $messages,
            ];
            if (!empty($tool_schemas)) {
                $params['tools'] = $tool_schemas;
            }

            // One provider call path: always stream. The context's emitText is
            // the sink — chat forwards it to the live partial-row writer; recipes
            // no-op it, so this is transparent to the autonomous surface.
            $response = $provider->createMessageStreamed($params, [$context, 'emitText']);

            $usage = $response['usage'] ?? [];
            $in += (int)($usage['input_tokens'] ?? 0);
            $out += (int)($usage['output_tokens'] ?? 0);
            $cw += (int)($usage['cache_creation_input_tokens'] ?? 0);
            $cr += (int)($usage['cache_read_input_tokens'] ?? 0);

            $api_stop = $response['stop_reason'] ?? '';
            $content  = $response['content'] ?? [];

            $tool_uses = [];
            $iter_text = '';
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $iter_text .= ($block['text'] ?? '');
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $tool_uses[] = $block;
                }
            }

            if ($api_stop === 'end_turn' || empty($tool_uses)) {
                $assistant_text = $iter_text;
                $stop_reason = 'end_turn';
                $detail = '';
                break;
            }

            if ($api_stop === 'refusal') {
                $assistant_text = $iter_text;
                $stop_reason = 'refusal';
                $detail = 'Model refused: ' . ($iter_text ?: '(no message)');
                break;
            }

            // Append assistant turn, then build a user turn of tool_result
            // blocks for the next iteration. Normalize tool_use.input so an
            // empty {} from the API doesn't round-trip as a JSON [] (PHP
            // assoc-decode collapses {} into [] which json_encode emits as
            // an array — Anthropic then rejects with "Input should be an
            // object").
            $messages[] = ['role' => 'assistant', 'content' => self::normalizeAssistantContent($content)];

            // Dispatch the batch. Read-only and inline-verdict calls run in
            // order; the first CONFIRM-verdict mutating call (interactive
            // contexts only) halts the turn with a pending action, and the
            // rest of the batch is discarded — the model re-proposes them
            // after the user resolves the confirmation. For recipes the hook
            // is inert (requiresConfirmation() is false), so every call runs.
            $tool_result_blocks = [];
            $iter_had_error = false;
            $halted_for_confirm = false;
            foreach ($tool_uses as $tu) {
                if ($context->requiresConfirmation()
                        && RiskHeuristic::isMutating($tu)
                        && RiskHeuristic::classify($tu, $context) === RiskHeuristic::CONFIRM) {
                    $pending_action = [
                        'tool'        => $tu['name'] ?? '',
                        'tool_use_id' => $tu['id'] ?? '',
                        'input'       => $tu['input'] ?? [],
                        'description' => self::describePending($tu),
                    ];
                    $stop_reason = 'pending_action';
                    $detail = 'awaiting user confirmation';
                    $halted_for_confirm = true;
                    break;
                }
                $result_block = self::executeToolUse($tu, $context);
                $tool_result_blocks[] = $result_block;
                if (!empty($result_block['is_error'])) $iter_had_error = true;
            }

            if ($halted_for_confirm) break;

            if ($iter_had_error) {
                $consecutive_tool_errors++;
                if ($consecutive_tool_errors >= self::CONSECUTIVE_TOOL_ERROR_LIMIT) {
                    $stop_reason = 'tool_errors';
                    $detail = 'consecutive_tool_failures: aborting after ' . $consecutive_tool_errors
                            . ' iterations of tool errors';
                    break;
                }
            } else {
                $consecutive_tool_errors = 0;
            }

            $messages[] = ['role' => 'user', 'content' => $tool_result_blocks];
        }

        return [
            'assistant_text'     => $assistant_text,
            'input_tokens'       => $in,
            'output_tokens'      => $out,
            'cache_write_tokens' => $cw,
            'cache_read_tokens'  => $cr,
            'messages'           => $messages,
            'stop_reason'        => $stop_reason,
            'detail'             => $detail,
            'pending_action'     => $pending_action,
        ];
    }

    /**
     * Execute a single tool call that an interactive surface has approved
     * out-of-band (the chat confirmation flow), through the same audited path
     * the loop uses. Returns the tool_result block (its tool_use_id echoes
     * $tool_use['id']) for the caller to feed back into a resumed run.
     */
    public static function executeApproved(array $tool_use, ToolContext $ctx): array {
        return self::executeToolUse($tool_use, $ctx);
    }

    /** Plain-language summary of a held call, for the confirmation card. */
    public static function describePending(array $tool_use): string {
        $name = $tool_use['name'] ?? '';
        $input = isset($tool_use['input']) && is_array($tool_use['input']) ? $tool_use['input'] : [];
        if ($name === 'invoke_action') {
            return 'Run action "' . (string)($input['name'] ?? '?') . '"';
        }
        $verbs = ['create_model' => 'Create', 'update_model' => 'Update', 'delete_model' => 'Delete'];
        if (isset($verbs[$name])) {
            return $verbs[$name] . ' ' . (string)($input['model'] ?? '?');
        }
        return 'Run ' . $name;
    }

    private static function normalizeAssistantContent(array $content): array {
        foreach ($content as &$block) {
            if (($block['type'] ?? '') === 'tool_use') {
                if (!isset($block['input']) || (is_array($block['input']) && empty($block['input']))) {
                    $block['input'] = new stdClass();
                }
            }
        }
        return $content;
    }

    private static function executeToolUse(array $tool_use, ToolContext $ctx): array {
        $name = $tool_use['name'] ?? '';
        $id   = $tool_use['id']   ?? '';
        $input = $tool_use['input'] ?? [];
        $started = microtime(true);

        // Record a "started but not completed" entry before the call (the
        // context flushes it to the DB so the dispatcher reaper can name the
        // last call that started if the run hangs). The completion fields are
        // filled in and handed back through finishToolCall() once the call
        // returns.
        $entry = [
            'name'           => $name,
            'input'          => $input,
            'started_time'   => gmdate('Y-m-d H:i:s.u'),
            'completed_time' => null,
            'is_error'       => false,
            'output'         => null,
            'duration_ms'    => null,
        ];
        $ctx->beginToolCall($entry);

        $finish = function (string $content, bool $is_error) use (&$entry, $ctx, $started): void {
            $entry['completed_time'] = gmdate('Y-m-d H:i:s.u');
            $entry['is_error']       = $is_error;
            $entry['output']         = $content;
            $entry['duration_ms']    = (int)((microtime(true) - $started) * 1000);
            $ctx->finishToolCall($entry);
        };

        $tool = RecipeToolRegistry::get($name);
        if ($tool === null) {
            $msg = "Tool '$name' is not available to this recipe.";
            $finish($msg, true);
            return ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $msg, 'is_error' => true];
        }

        try {
            $result = $tool->execute(is_array($input) ? $input : [], $ctx);
        } catch (Exception $e) {
            $msg = get_class($e) . ': ' . $e->getMessage();
            $finish($msg, true);
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

        $finish($content, $is_error);

        $block = ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $content];
        if ($is_error) $block['is_error'] = true;
        return $block;
    }

}
