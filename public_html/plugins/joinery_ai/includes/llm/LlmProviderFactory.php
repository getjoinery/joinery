<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/AnthropicProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/FireworksProvider.php'));

/**
 * Builds the transport for one endpoint.
 *
 * This class used to answer "which provider does this model name belong to?"
 * by sniffing the string — /^claude/ meant Anthropic, an accounts/fireworks/
 * prefix meant Fireworks, anything else was assumed local. That guess was also
 * the platform's definition of "cloud", so an id nobody recognised was
 * classified as safe by default.
 *
 * The shipped catalog answers it instead: a model id belongs to exactly one
 * endpoint, and AiModelResolver settles which endpoint a piece of work runs on
 * BEFORE anything is dispatched. All that is left here is turning an endpoint
 * key into a wire client — which dialect, which URL, which key.
 *
 * See specs/joinery_ai_model_capability_resolution.md §1, §5.
 */
class LlmProviderFactory {

    /**
     * The transport for one catalog endpoint.
     *
     * @throws LlmProviderException when the endpoint is unknown, or its
     *         required setting is empty
     */
    public static function forEndpoint(string $endpoint_key): LlmProviderInterface {
        $endpoint = AiEndpointRegistry::endpoint($endpoint_key);
        if ($endpoint === null) {
            throw new LlmProviderException(
                'No AI endpoint named "' . $endpoint_key . '" is declared in the model catalog.');
        }

        $settings = Globalvars::get_instance();
        $base_url = AiEndpointRegistry::baseUrl($endpoint_key);
        $api_key  = AiEndpointRegistry::apiKey($endpoint_key);

        $key_setting = $endpoint['api_key_setting'] ?? null;
        if ($key_setting !== null && $api_key === '') {
            throw new LlmProviderException(
                'The ' . (string)$endpoint['label'] . ' endpoint is in use but ' . $key_setting
                . ' is empty. Set it on the Joinery AI settings page.');
        }

        if ((string)($endpoint['dialect'] ?? 'openai') === 'anthropic') {
            return new AnthropicProvider($api_key, $base_url);
        }

        // Every OpenAI-dialect endpoint rides the same wire translation.
        // Fireworks subclasses it only for its own diagnostics and timeouts.
        $timeout = (int)$settings->get_setting('joinery_ai_local_timeout_seconds') ?: 300;
        if ($endpoint_key === 'fireworks') {
            return new FireworksProvider($api_key, $base_url, FireworksProvider::DEFAULT_MODEL, 120);
        }
        if ($base_url === '') {
            throw new LlmProviderException(
                'The ' . (string)$endpoint['label'] . ' endpoint has no base URL configured.');
        }
        // The per-request model always names itself, so the constructor's model
        // argument is only a fallback; pass the endpoint's first served id.
        $first = (string)(array_key_first(AiEndpointRegistry::modelsFor($endpoint_key)) ?? '');
        return new OpenAiCompatibleProvider($base_url, $first, $api_key, $timeout);
    }

    /**
     * Every model this install can send to right now, as [model_id => label] —
     * for the chat model picker and the recipe pin.
     *
     * An endpoint with no key contributes nothing, which is what makes clearing
     * a key the supported way to take one out of play.
     */
    public static function allModels(): array {
        try {
            $out = [];
            foreach (AiEndpointRegistry::catalog() as $id => $entry) {
                if (!empty($entry['retired'])) continue;
                $out[$id] = (string)$entry['label'];
            }
            return $out;
        } catch (AiCatalogException $e) {
            return [];
        }
    }

    /**
     * Attachment capabilities for a model id, from the catalog.
     *
     * An id no endpoint serves returns both false, so ingress refuses the
     * upload rather than guessing capable — the file-upload spec's fail-loud
     * rule, now answered from one place instead of per provider.
     */
    public static function capabilitiesForModel(string $model): array {
        try {
            $entry = AiEndpointRegistry::model(trim($model));
        } catch (AiCatalogException $e) {
            $entry = null;
        }
        if ($entry === null) return ['vision' => false, 'document' => false];
        return [
            'vision'   => (bool)($entry['attachments']['vision'] ?? false),
            'document' => (bool)($entry['attachments']['document'] ?? false),
        ];
    }
}
