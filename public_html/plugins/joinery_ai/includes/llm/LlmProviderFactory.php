<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/AnthropicProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));

/**
 * Builds the active LLM provider from settings. Provider is a global setting
 * (joinery_ai_llm_provider), not per-recipe — the recipe's rcp_model is
 * reinterpreted by whichever provider is active.
 */
class LlmProviderFactory {

    /**
     * @throws LlmProviderException if the active provider's required setting is empty.
     */
    public static function build(): LlmProviderInterface {
        $settings = Globalvars::get_instance();
        $provider = $settings->get_setting('joinery_ai_llm_provider') ?: 'anthropic';

        if ($provider === 'local') {
            $base  = $settings->get_setting('joinery_ai_local_base_url') ?: 'http://localhost:11434/v1';
            $model = (string)$settings->get_setting('joinery_ai_local_model');
            $key   = (string)$settings->get_setting('joinery_ai_local_api_key');
            $timeout = (int)$settings->get_setting('joinery_ai_local_timeout_seconds') ?: 300;
            if ($model === '') {
                throw new LlmProviderException(
                    'Local LLM provider is selected but joinery_ai_local_model is empty. '
                    . 'Set it to the model id served by your OpenAI-compatible host.'
                );
            }
            return new OpenAiCompatibleProvider($base, $model, $key, $timeout);
        }

        // Default: Anthropic.
        $key = (string)$settings->get_setting('joinery_ai_anthropic_api_key');
        return new AnthropicProvider($key);
    }

}
