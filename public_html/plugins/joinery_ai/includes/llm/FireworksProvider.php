<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));

use GuzzleHttp\Client;

/**
 * Fireworks AI provider.
 *
 * Fireworks exposes an OpenAI-compatible /chat/completions endpoint, so this
 * rides OpenAiCompatibleProvider's wire translation and only supplies the
 * vendor-specific surface: reasoning_effort in place of qwen's /think token,
 * and Fireworks-flavoured diagnostics.
 *
 * Which models Fireworks serves, what they cost and how far they are trusted
 * are declared in plugins/joinery_ai/ai_endpoints.json, which ships with a
 * release — so re-pricing or re-grading the fleet is a file edit and a publish
 * rather than a tour of production databases. Fireworks is graded `trusted`
 * there: the text leaves your hardware, but to a named vendor under a
 * contractual no-train commitment on open-model traffic.
 * See specs/joinery_ai_fireworks_provider.md.
 */
class FireworksProvider extends OpenAiCompatibleProvider {

    const DEFAULT_BASE_URL = 'https://api.fireworks.ai/inference/v1';
    const DEFAULT_MODEL    = 'accounts/fireworks/models/gpt-oss-120b';

    /** True when a model id belongs to Fireworks (namespaced account path). */
    public static function owns(string $model): bool {
        return strncmp($model, 'accounts/fireworks/', 19) === 0;
    }

    /**
     * @param string      $api_key  Fireworks key, sent as a Bearer token
     * @param string      $base_url defaults to the public Fireworks endpoint
     * @param string      $model    model to drive; defaults to the value tier
     * @param int         $timeout  per-call read timeout (remote, fast)
     * @param Client|null $http     injectable for tests
     */
    public function __construct(string $api_key, string $base_url = self::DEFAULT_BASE_URL,
            string $model = self::DEFAULT_MODEL, int $timeout = 120, ?Client $http = null) {
        if ($api_key === '') {
            throw new LlmProviderException(
                'Fireworks API key is empty. Configure joinery_ai_fireworks_api_key.'
            );
        }
        if ($base_url === '') $base_url = self::DEFAULT_BASE_URL;
        if ($model === '')    $model = self::DEFAULT_MODEL;
        parent::__construct($base_url, $model, $api_key, $timeout, $http);
    }

    public function id(): string {
        return 'fireworks';
    }

    /** Remote cloud host — skip the local-style pre-flight probe; the real call handles transport errors. */
    public function reachabilityProbe(): ?string {
        return null;
    }

    /** A cloud API's first token is prompt — no tighter first-token phase; the per-call timeout governs. */
    protected function firstTokenTimeoutSeconds(): int {
        return 0;
    }

    /** Fireworks models are real OpenAI chat models — no qwen /think token. */
    protected function systemThinkingSuffix(array $directive): string {
        return '';
    }

    /**
     * Put the resolver's directive on Fireworks' reasoning_effort knob.
     *
     * Which models can reason, and which cannot be made to stop, used to be two
     * tables of model names here. The catalog declares that now
     * (`thinking: none|optional|always`) and the resolver has already turned it
     * into a decision, so this only translates: thinking off sends "none",
     * anything else sends the resolved effort. An always-on model never
     * receives "none" because the resolver never produces it for one.
     */
    protected function applyReasoning(array &$request, array $directive): void {
        $request['reasoning_effort'] = empty($directive['enabled'])
            ? 'none' : (string)($directive['effort'] ?: 'low');
    }

    protected function providerLabel(): string {
        return 'Fireworks';
    }

    protected function unreachableMessage(): string {
        return "Fireworks API not reachable at {$this->base_url} (connection error)";
    }
}
