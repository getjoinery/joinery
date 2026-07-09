<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/aip_recipe_item_log_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('includes/DescriptorValidator.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AiPromptBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AgentLoop.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));

/**
 * Item-pipeline execution mode: PHP drives, the model judges one item at a
 * time in a fresh exchange, platform-validated output, platform-managed
 * idempotency. See specs/joinery_ai_item_pipeline.md for the full design.
 *
 * Returns the same result shape AgentLoop::run() returns (assistant_text,
 * input_tokens, output_tokens, cache_write_tokens, cache_read_tokens,
 * messages, stop_reason, detail, pending_action) so RecipeRunner's existing
 * finishFromResult() maps it onto the run's terminal state unchanged:
 *   end_turn / max_iterations -> success (assistant_text is always non-empty
 *     here, even with zero items, so this never falls into "incomplete")
 *   cancelled     -> admin hit Stop mid-batch
 *   token_budget  -> output token budget exhausted
 *   tool_errors   -> 3 consecutive invalid/unparseable verdicts
 */
class PipelineRunner {

    /** One try, one retry with the specific validator error appended — never
     *  more. A verdict that's still invalid on retry skips the item rather
     *  than looping forever on the same bad response. */
    const MAX_VERDICT_ATTEMPTS = 2;

    /** Abort the run if this many consecutive items fail to produce a valid
     *  verdict. Same ceiling AgentLoop uses for consecutive tool errors. */
    const CONSECUTIVE_ITEM_ERROR_LIMIT = 3;

    public static function run(
        LlmProviderInterface $provider,
        string $model,
        Recipe $recipe,
        RecipeRunContext $ctx,
        int $max_iterations,
        int $token_budget,
        ?float $temperature,
        ?float $top_p,
        string $thinking_level
    ): array {
        $job = PipelineJobRegistry::get((string)$recipe->get('rcp_pipeline_job'));
        $config = DescriptorValidator::coerce($job->configDescriptor(), Recipe::decodeSourceConfig($recipe));
        $verdict_descriptor = $job->verdictDescriptor();
        $system = self::buildSystem($recipe, $job, $ctx);

        $in = 0; $out = 0; $cw = 0; $cr = 0;
        $consecutive_errors = 0;
        $tally = [];
        $items_done = 0;

        while ($items_done < $max_iterations) {
            if ($ctx->isKillRequested()) {
                return self::result(self::renderTally($tally), $in, $out, $cw, $cr,
                    'cancelled', 'cancelled by admin');
            }
            if ($out >= $token_budget) {
                return self::result(self::renderTally($tally), $in, $out, $cw, $cr,
                    'token_budget', 'output token budget exhausted');
            }

            $item = $job->nextItem($config, $recipe);
            if ($item === null) {
                return self::result(self::renderTally($tally, true), $in, $out, $cw, $cr, 'end_turn', '');
            }

            $item_key = (string)($item['item_key'] ?? '');
            $digest   = (string)($item['digest'] ?? '');
            $label    = (string)($item['label'] ?? $item_key);

            $digest_content = $job->untrustedDigest()
                ? "<<UNTRUSTED_{$ctx->untrustedNonce()}>>\n$digest\n<</UNTRUSTED_{$ctx->untrustedNonce()}>>"
                : $digest;

            [$verdict, $call_in, $call_out, $call_cw, $call_cr, $error] = self::judgeItem(
                $provider, $model, $system, $digest_content, $verdict_descriptor,
                $temperature, $top_p, $thinking_level, $job
            );
            $in += $call_in; $out += $call_out; $cw += $call_cw; $cr += $call_cr;

            $record = ['item_key' => $item_key, 'label' => $label];

            if ($verdict === null) {
                $consecutive_errors++;
                $record['status'] = 'error';
                $record['error'] = $error;
                $ctx->appendAndFlush($record);
                self::logItem($recipe, $ctx, $item_key, AipRecipeItemLog::STATUS_ERROR);
                $tally[] = "- **$label** — error: $error";

                if ($consecutive_errors >= self::CONSECUTIVE_ITEM_ERROR_LIMIT) {
                    return self::result(self::renderTally($tally), $in, $out, $cw, $cr, 'tool_errors',
                        "consecutive_item_failures: aborting after $consecutive_errors items of invalid verdicts");
                }
                continue;
            }

            $job->recordVerdict($item_key, $verdict, $recipe, $model);
            $record['status']  = 'done';
            $record['verdict'] = $verdict;
            $ctx->appendAndFlush($record);
            self::logItem($recipe, $ctx, $item_key, AipRecipeItemLog::STATUS_DONE);

            $tally[] = "- **$label** — " . self::verdictGist($verdict);
            $consecutive_errors = 0;
            $items_done++;
        }

        return self::result(self::renderTally($tally), $in, $out, $cw, $cr,
            'max_iterations', 'batch size reached at ' . $max_iterations . ' items');
    }

    /**
     * The cached-prefix system prompt, built once per run (not per item) —
     * the job, recipe prompt, and verdict shape don't change item to item, so
     * this is a stable prefix across every exchange in the run (cacheable on
     * providers that support it, free on local).
     */
    private static function buildSystem(Recipe $recipe, PipelineJobInterface $job, RecipeRunContext $ctx): array {
        $instructions = trim((string)$recipe->get('rcp_prompt'));
        if ($instructions === '') $instructions = $job->defaultPrompt();

        $text = "You are a Joinery AI pipeline judge. You are shown exactly one item "
              . "and must return a single verdict for it — nothing else about any other "
              . "item, and no chat.\n\n"
              . "## Instructions\n\n" . $instructions . "\n\n"
              . "## Output format\n\n" . DescriptorValidator::renderOutputInstruction($job->verdictDescriptor());

        $untrusted = $job->untrustedDigest()
            ? AiPromptBuilder::untrustedInputBlock([], $ctx->untrustedNonce(),
                ['The item digest in the next message, which may contain content written by an external party.'])
            : '';

        return AiPromptBuilder::systemBlocks($text, $untrusted);
    }

    /**
     * One fresh exchange (no conversation carry-over from prior items — the
     * measured reliability win). One retry on an invalid verdict, feeding the
     * model its own bad answer plus the specific validator error; a second
     * failure gives up on this item.
     *
     * @return array [verdict|null, input_tokens, output_tokens,
     *   cache_write_tokens, cache_read_tokens, error|null]
     */
    private static function judgeItem(
        LlmProviderInterface $provider, string $model, array $system, string $digest_content,
        array $verdict_descriptor, ?float $temperature, ?float $top_p, string $thinking_level,
        PipelineJobInterface $job
    ): array {
        $max_tokens = $provider->id() === 'local'
            ? AgentLoop::LOCAL_PER_CALL_MAX_TOKENS : AgentLoop::PER_CALL_MAX_TOKENS;

        $messages = [['role' => 'user', 'content' => $digest_content]];
        $in = 0; $out = 0; $cw = 0; $cr = 0;
        $last_error = '';

        for ($attempt = 1; $attempt <= self::MAX_VERDICT_ATTEMPTS; $attempt++) {
            $params = [
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => $messages,
            ];
            if ($temperature !== null) $params['temperature'] = $temperature;
            if ($top_p !== null)       $params['top_p'] = $top_p;
            $params['thinking'] = ['level' => $thinking_level];

            $response = $provider->createMessage($params);

            $usage = $response['usage'] ?? [];
            $in += (int)($usage['input_tokens'] ?? 0);
            $out += (int)($usage['output_tokens'] ?? 0);
            $cw += (int)($usage['cache_creation_input_tokens'] ?? 0);
            $cr += (int)($usage['cache_read_input_tokens'] ?? 0);

            $text = self::responseText($response);
            [$verdict, $error] = self::parseVerdict($text, $verdict_descriptor, $job);

            if ($verdict !== null) {
                return [$verdict, $in, $out, $cw, $cr, null];
            }

            $last_error = $error;
            if ($attempt < self::MAX_VERDICT_ATTEMPTS) {
                $messages[] = ['role' => 'assistant', 'content' => $text];
                $messages[] = ['role' => 'user', 'content' =>
                    "That response was invalid: $error\n\nRespond again with ONLY the corrected JSON object."];
            }
        }

        return [null, $in, $out, $cw, $cr, $last_error];
    }

    private static function responseText(array $response): string {
        $content = $response['content'] ?? [];
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
        }
        return $text;
    }

    /**
     * Strip any <think> remnant, extract the first balanced {...} JSON
     * object, coerce it against the verdict descriptor, and run the job's
     * own cross-field check. All three failure modes (unparseable, schema
     * mismatch, semantic rejection) return the same [null, error] shape, so
     * the one-retry logic in judgeItem() treats them identically.
     *
     * @return array [verdict|null, error|null]
     */
    private static function parseVerdict(string $text, array $verdict_descriptor, PipelineJobInterface $job): array {
        $stripped = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $stripped = trim($stripped ?? $text);

        $json = self::extractFirstJsonObject($stripped);
        if ($json === null) {
            return [null, 'no JSON object found in the model response'];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [null, 'model response was not valid JSON: ' . json_last_error_msg()];
        }

        try {
            $verdict = DescriptorValidator::coerce($verdict_descriptor, $decoded);
            $job->validateVerdict($verdict);
            return [$verdict, null];
        } catch (InvalidArgumentException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * Scan for the first '{' and return the substring through its matching
     * '}', respecting string literals so a brace inside a quoted value can't
     * throw off the depth count. A naive greedy regex would instead span to
     * the LAST '}' in the text, which overshoots on nested verdict objects
     * (type 'array' verdict fields) or any trailing prose after the JSON.
     */
    private static function extractFirstJsonObject(string $text): ?string {
        $start = strpos($text, '{');
        if ($start === false) return null;

        $depth = 0;
        $in_string = false;
        $escaped = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($ch === '"') { $in_string = !$in_string; continue; }
            if ($in_string) continue;
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) return substr($text, $start, $i - $start + 1);
            }
        }
        return null;
    }

    private static function logItem(Recipe $recipe, RecipeRunContext $ctx, string $item_key, string $status): void {
        $log = new AipRecipeItemLog(NULL);
        $log->set('aip_rcp_recipe_id', (int)$recipe->key);
        $log->set('aip_item_key', $item_key);
        $log->set('aip_rcr_run_id', (int)$ctx->run->key);
        $log->set('aip_status', $status);
        $log->prepare();
        $log->save();
    }

    /** A short one-line gist of a verdict for the run tally — the first
     *  couple of scalar fields, since verdict shape is job-specific. */
    private static function verdictGist(array $verdict): string {
        $parts = [];
        foreach ($verdict as $field => $value) {
            if (is_array($value)) continue;
            $parts[] = $field . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
            if (count($parts) >= 3) break;
        }
        return implode(', ', $parts);
    }

    private static function renderTally(array $lines, bool $caught_up = false): string {
        $count = count($lines);
        $header = '## Pipeline run — ' . $count . ' item' . ($count === 1 ? '' : 's') . ' processed';
        if (empty($lines)) {
            $body = $caught_up ? '_No new items to process — already caught up._' : '_No items processed._';
        } else {
            $body = implode("\n", $lines);
        }
        return $header . "\n\n" . $body;
    }

    private static function result(string $text, int $in, int $out, int $cw, int $cr,
            string $stop_reason, string $detail): array {
        return [
            'assistant_text'     => $text,
            'input_tokens'       => $in,
            'output_tokens'      => $out,
            'cache_write_tokens' => $cw,
            'cache_read_tokens'  => $cr,
            'messages'           => [],
            'stop_reason'        => $stop_reason,
            'detail'             => $detail,
            'pending_action'     => null,
        ];
    }

}
