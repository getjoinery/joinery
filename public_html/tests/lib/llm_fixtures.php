<?php
/**
 * Shared LLM-provider test doubles for the joinery_ai / recipe / pipeline suites.
 *
 * Every fake speaks LlmProviderInterface. The interface is wide (streaming,
 * cost, model catalog, reachability, capabilities), but a test only ever cares
 * about the response content — so FakeLlmProvider carries every boilerplate
 * method once, and a test supplies only the behavior that matters:
 *
 *   - ScriptedLlmProvider: hand it a list of responses; each turn returns the
 *     next one and streams its text blocks through the delta sink. Entries may
 *     be a full canonical response array (['content'=>[...], 'stop_reason'=>...,
 *     'usage'=>...]) or the ['text'=>..., 'usage'=>...] shorthand. Covers both
 *     the verdict/judge shape and the streamed-activity shape.
 *   - Bespoke behavior (a cancel/abort dance, a tool_use turn): extend
 *     FakeLlmProvider and override createMessageStreamed() — you inherit the
 *     eight boilerplate methods for free.
 *
 * `$calls` counts createMessageStreamed invocations (both paths route through it).
 */

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));

if (!class_exists('FakeLlmProvider')) {

/**
 * Base fake: implements every LlmProviderInterface method with a harmless
 * default. Subclasses implement only createMessageStreamed().
 */
abstract class FakeLlmProvider implements LlmProviderInterface {

    /** @var int number of createMessageStreamed() calls (the blocking path routes through it too). */
    public $calls = 0;

    abstract public function createMessageStreamed(array $params, callable $onTextDelta, ?callable $shouldAbort = null): array;

    /** Blocking convenience: the streamed path with a no-op sink. */
    public function createMessage(array $params): array {
        return $this->createMessageStreamed($params, static function (string $d): void {});
    }

    public function estimateCost(string $model, array $usage): float { return 0.0; }
    public function models(): array { return []; }
    public function defaultModel(): string { return 'fake/test-model'; }
    public function id(): string { return 'fake'; }
    public function isPrivate(): bool { return true; }   // an in-memory fake is always private/reachable
    public function reachabilityProbe(): ?string { return null; }
    public function modelCapabilities(string $model): array { return ['vision' => false, 'document' => false]; }

    /** Build a canonical end_turn response carrying one text block. */
    public static function textResponse(string $text, ?array $usage = null): array {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $text]],
            'usage'       => $usage ?? [
                'input_tokens' => 10, 'output_tokens' => 10,
                'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            ],
        ];
    }
}

/**
 * Returns a scripted list of responses, one per turn, streaming each response's
 * text blocks through the delta sink (as a real streaming provider does). When
 * the script runs out, it returns an empty-JSON end_turn response.
 */
class ScriptedLlmProvider extends FakeLlmProvider {

    /** @var array */
    private $responses;

    public function __construct(array $responses) { $this->responses = $responses; }

    public function createMessageStreamed(array $params, callable $onTextDelta, ?callable $shouldAbort = null): array {
        $this->calls++;
        $resp = $this->normalize(array_shift($this->responses));
        foreach (($resp['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && ($block['text'] ?? '') !== '') {
                $onTextDelta($block['text']);
            }
        }
        return $resp;
    }

    /** Accept a full canonical response, the ['text'=>...] shorthand, or nothing. */
    private function normalize($resp): array {
        if ($resp === null) {
            return self::textResponse('{}');
        }
        if (!isset($resp['content'])) {
            return self::textResponse($resp['text'] ?? '{}', $resp['usage'] ?? null);
        }
        return $resp;
    }
}

} // class_exists guard
