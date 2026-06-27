<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/AnthropicProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));

/**
 * Builds an LLM provider. The model id is authoritative: a model implies its
 * vendor (claude-* → Anthropic, anything else → the local OpenAI-compatible
 * host), so a recipe pinned to a model always runs on that model's provider
 * regardless of the global setting. The global setting
 * (joinery_ai_llm_provider) is only the default for callers that have no model
 * to route by — e.g. a recipe that pins no model and follows the default.
 */
class LlmProviderFactory {

    /**
     * Provider for a specific model id. Empty model → the global-default
     * provider (build()). A claude-* id → Anthropic; any other non-empty id is
     * assumed served by the local host.
     *
     * @throws LlmProviderException if the resolved provider's required setting is empty.
     */
    public static function forModel(string $model): LlmProviderInterface {
        $model = trim($model);
        if ($model === '') {
            return self::build();
        }
        return preg_match('/^claude/i', $model) ? self::anthropic() : self::local();
    }

    /**
     * The global-default provider, from joinery_ai_llm_provider. Used when
     * there is no model to route by.
     *
     * @throws LlmProviderException if the active provider's required setting is empty.
     */
    public static function build(): LlmProviderInterface {
        $provider = Globalvars::get_instance()->get_setting('joinery_ai_llm_provider') ?: 'anthropic';
        return $provider === 'local' ? self::local() : self::anthropic();
    }

    /**
     * Every selectable model across all configured providers, as
     * [model_id => label] — for the recipe-edit dropdown. Since the model now
     * picks the provider, the dropdown offers models from every provider that
     * is configured (Anthropic if a key is set, the local host if a local
     * model is set), not just the global-default one. A provider with no
     * configuration contributes nothing.
     */
    public static function allModels(): array {
        $settings = Globalvars::get_instance();
        $out = [];
        if ((string)$settings->get_setting('joinery_ai_anthropic_api_key') !== '') {
            foreach (self::anthropic()->models() as $id => $label) {
                $out[$id] = $label;
            }
        }
        if ((string)$settings->get_setting('joinery_ai_local_model') !== '') {
            foreach (self::local()->models() as $id => $label) {
                $out[$id] = $label;
            }
        }
        return $out;
    }

    private static function anthropic(): LlmProviderInterface {
        $key = (string)Globalvars::get_instance()->get_setting('joinery_ai_anthropic_api_key');
        return new AnthropicProvider($key);
    }

    private static function local(): LlmProviderInterface {
        $settings = Globalvars::get_instance();
        $base  = $settings->get_setting('joinery_ai_local_base_url') ?: 'http://localhost:11434/v1';
        $model = (string)$settings->get_setting('joinery_ai_local_model');
        $key   = (string)$settings->get_setting('joinery_ai_local_api_key');
        $timeout = (int)$settings->get_setting('joinery_ai_local_timeout_seconds') ?: 300;
        if ($model === '') {
            throw new LlmProviderException(
                'A non-Anthropic model is in use but joinery_ai_local_model is empty. '
                . 'Set it to the model id served by your OpenAI-compatible host.'
            );
        }
        return new OpenAiCompatibleProvider($base, $model, $key, $timeout);
    }

}
