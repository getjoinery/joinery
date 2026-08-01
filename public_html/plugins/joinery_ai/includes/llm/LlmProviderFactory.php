<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/AnthropicProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/FireworksProvider.php'));

/**
 * Builds an LLM provider. The model id is authoritative: a model implies its
 * vendor (claude-* → Anthropic, accounts/fireworks/* → Fireworks, anything else
 * → the local OpenAI-compatible host), so a recipe pinned to a model always runs
 * on that model's provider regardless of the global setting. The global setting
 * (joinery_ai_llm_provider) is only the default for callers that have no model
 * to route by — e.g. a recipe that pins no model and follows the default.
 */
class LlmProviderFactory {

    /**
     * Provider for a specific model id. Empty model → the global-default
     * provider (build()). A claude-* id → Anthropic; an accounts/fireworks/* id →
     * Fireworks; any other non-empty id is assumed served by the local host.
     *
     * @throws LlmProviderException if the resolved provider's required setting is empty.
     */
    public static function forModel(string $model): LlmProviderInterface {
        $model = trim($model);
        if ($model === '') {
            return self::build();
        }
        if (preg_match('/^claude/i', $model)) return self::anthropic();
        if (FireworksProvider::owns($model)) return self::fireworks();
        return self::local();
    }

    /**
     * Provider for a conversation, enforcing the Fortress contract
     * (specs/joinery_ai_chat_encryption.md § Phase 5): a Fortress chat's content
     * never leaves the box, so its turn MUST run on the local model — any cloud
     * model (claude-* / Fireworks) is rejected here, the single choke point every
     * turn passes through. Standard/Private route by the model id as usual.
     *
     * @throws LlmProviderException on a Fortress chat pinned to a cloud model, or
     *         when the resolved provider's required setting is empty.
     */
    public static function forConversation(AiConversation $conversation): LlmProviderInterface {
        $model = trim((string)$conversation->get('aic_model'));
        if ((string)$conversation->get('aic_security_level') === AiConversation::LEVEL_FORTRESS) {
            if (self::isCloudModel($model)) {
                throw new LlmProviderException(
                    'This is a Fortress chat — its content never leaves your hardware, so it can only run '
                    . 'on a local model. The selected model “' . $model . '” is a cloud model. Switch to a '
                    . 'local model in the chat settings, or a configured local model must be available.'
                );
            }
            return self::local();   // pins to the local host (uses joinery_ai_local_model)
        }
        return self::forModel($model);
    }

    /**
     * Does this model run somewhere other than the operator's own hardware?
     *
     * The one definition of "cloud" — the Fortress chat pin and the sealed-mail
     * recipe gate both ask here, so they cannot disagree about a model. Note
     * this is NOT LlmProviderInterface::isPrivate(), which is about a provider's
     * training policy and is true for Fireworks: a vendor promising not to train
     * on your data is still a vendor holding your plaintext. For sealing, the
     * only question is whether the bytes leave the box.
     */
    public static function isCloudModel(string $model): bool {
        $model = trim($model);
        if ($model === '') return false;
        return (bool)preg_match('/^claude/i', $model) || FireworksProvider::owns($model);
    }

    /**
     * The global-default provider, from joinery_ai_llm_provider. Used when
     * there is no model to route by.
     *
     * @throws LlmProviderException if the active provider's required setting is empty.
     */
    public static function build(): LlmProviderInterface {
        $provider = Globalvars::get_instance()->get_setting('joinery_ai_llm_provider') ?: 'anthropic';
        if ($provider === 'local')     return self::local();
        if ($provider === 'fireworks') return self::fireworks();
        return self::anthropic();
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
        if ((string)$settings->get_setting('joinery_ai_fireworks_api_key') !== '') {
            foreach (self::fireworks()->models() as $id => $label) {
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

    /**
     * Attachment capabilities (['vision'=>bool,'document'=>bool]) for a model id,
     * resolved through its provider. An empty or unroutable id — or a provider
     * whose required setting is unset — returns both false, so ingress refuses
     * the upload rather than guessing capable.
     */
    public static function capabilitiesForModel(string $model): array {
        try {
            return self::forModel($model)->modelCapabilities(trim($model));
        } catch (Throwable $e) {
            return ['vision' => false, 'document' => false];
        }
    }

    private static function anthropic(): LlmProviderInterface {
        $key = (string)Globalvars::get_instance()->get_setting('joinery_ai_anthropic_api_key');
        if ($key === '') {
            throw new LlmProviderException(
                'An Anthropic model is in use but joinery_ai_anthropic_api_key is empty. '
                . 'Set it on the Joinery AI settings page.'
            );
        }
        return new AnthropicProvider($key);
    }

    private static function fireworks(): LlmProviderInterface {
        $settings = Globalvars::get_instance();
        $key  = (string)$settings->get_setting('joinery_ai_fireworks_api_key');
        $base = $settings->get_setting('joinery_ai_fireworks_base_url') ?: FireworksProvider::DEFAULT_BASE_URL;
        if ($key === '') {
            throw new LlmProviderException(
                'A Fireworks model is in use but joinery_ai_fireworks_api_key is empty. '
                . 'Set it on the Joinery AI settings page.'
            );
        }
        return new FireworksProvider($key, $base);
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
