<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));

use GuzzleHttp\Client;

/**
 * Fireworks AI provider.
 *
 * Fireworks exposes an OpenAI-compatible /chat/completions endpoint, so this
 * rides OpenAiCompatibleProvider's wire translation and only supplies the
 * vendor-specific surface: a curated model catalog with pricing, reasoning_effort
 * in place of qwen's /think token, a private classification (Fireworks does not
 * train on open-model traffic), and Fireworks-flavoured diagnostics.
 *
 * Privacy: a vetted no-train remote, not on-device. isPrivate() is true so the
 * chat UI does not warn for it — but genuinely identifying data still belongs on
 * the local provider. See specs/joinery_ai_fireworks_provider.md.
 *
 * Pricing and model IDs move frequently — verify against the live Fireworks
 * catalog (fireworks.ai) before relying on cost figures.
 */
class FireworksProvider extends OpenAiCompatibleProvider {

    const DEFAULT_BASE_URL = 'https://api.fireworks.ai/inference/v1';
    const DEFAULT_MODEL    = 'accounts/fireworks/models/gpt-oss-120b';

    /**
     * USD per 1,000,000 tokens. Cached input is billed at 50% of the input rate
     * (handled in estimateCost). Verified against fireworks.ai 2026-06-28; the
     * catalog moves, so re-check when adding models.
     *
     * Three tiers, no coding bias: cheap-but-good, cheapish reasoning+tools, and
     * a Sonnet-class top end for well under Sonnet's price.
     */
    const COST_PER_MTOKEN = [
        'accounts/fireworks/models/gpt-oss-120b' => ['input' => 0.15, 'output' => 0.60],
        'accounts/fireworks/models/qwen3p7-plus' => ['input' => 0.40, 'output' => 1.60],
        'accounts/fireworks/models/glm-5p2'      => ['input' => 1.40, 'output' => 4.40],
    ];

    /** Models offered to the model dropdown. */
    const MODELS = [
        'accounts/fireworks/models/gpt-oss-120b' => 'gpt-oss-120B (Fireworks · cheap · $0.15/$0.60 per Mtok)',
        'accounts/fireworks/models/qwen3p7-plus' => 'Qwen 3.7 Plus (Fireworks · reasoning + tools · $0.40/$1.60 per Mtok)',
        'accounts/fireworks/models/glm-5p2'      => 'GLM 5.2 (Fireworks · Sonnet-class · $1.40/$4.40 per Mtok)',
    ];

    /** Models that accept the reasoning_effort knob (all three are reasoning-capable). */
    const REASONING_MODELS = [
        'accounts/fireworks/models/gpt-oss-120b' => true,
        'accounts/fireworks/models/qwen3p7-plus' => true,
        'accounts/fireworks/models/glm-5p2'      => true,
    ];

    /**
     * Always-on reasoning models that reject reasoning_effort="none" — thinking
     * cannot be disabled, so `off` maps to the lowest effort ("low") instead.
     */
    const REASONING_NO_OFF = [
        'accounts/fireworks/models/gpt-oss-120b' => true,
    ];

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

    /** Vetted no-train remote — classified private; suppresses the chat warning. */
    public function isPrivate(): bool {
        return true;
    }

    /**
     * The curated Fireworks catalog is text-only here: no image or native-PDF
     * ingest. Uploads to a Fireworks chat are refused at ingress with the
     * "switch to a Claude model" message. If a vision-capable Fireworks model is
     * added later, flip its entry here.
     */
    public function modelCapabilities(string $model): array {
        return ['vision' => false, 'document' => false];
    }

    public function models(): array {
        return self::MODELS;
    }

    public function defaultModel(): string {
        return self::DEFAULT_MODEL;
    }

    /** Fireworks models are real OpenAI chat models — no qwen /think token. */
    protected function systemThinkingSuffix(string $level): string {
        return '';
    }

    /**
     * Map the canonical thinking level to Fireworks' reasoning_effort knob. The
     * Thinking control thus drives all our reasoning models: low/medium/high scale
     * it, and `off` disables thinking ("none") — except on always-on models that
     * reject "none", where `off` falls back to the lowest effort ("low"). Skipped
     * for any non-reasoning model so we never send an unsupported parameter.
     */
    protected function applyReasoning(array &$request, string $level): void {
        $model = (string)($request['model'] ?? '');
        if (empty(self::REASONING_MODELS[$model])) return;
        if ($level === '' || $level === 'off') {
            $request['reasoning_effort'] = !empty(self::REASONING_NO_OFF[$model]) ? 'low' : 'none';
        } else {
            $request['reasoning_effort'] = $level;
        }
    }

    protected function providerLabel(): string {
        return 'Fireworks';
    }

    protected function unreachableMessage(): string {
        return "Fireworks API not reachable at {$this->base_url} (connection error)";
    }

    /**
     * USD from a canonical usage block. OpenAI-style usage counts cached tokens
     * inside input_tokens, so the uncached remainder bills at the full input rate
     * and the cached portion at half.
     */
    public function estimateCost(string $model, array $usage): float {
        $rates = self::COST_PER_MTOKEN[$model] ?? null;
        if (!$rates) return 0.0;

        $input    = (int)($usage['input_tokens'] ?? 0);
        $output   = (int)($usage['output_tokens'] ?? 0);
        $cached   = (int)($usage['cache_read_input_tokens'] ?? 0);
        $uncached = max(0, $input - $cached);

        return (
            $uncached * $rates['input']
          + $cached   * $rates['input'] * 0.5
          + $output   * $rates['output']
        ) / 1000000.0;
    }
}
