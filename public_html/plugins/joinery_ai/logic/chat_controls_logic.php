<?php
/**
 * Joinery AI — chat control metadata (API action).
 * POST /api/v1/action/joinery_ai/chat_controls
 *
 * The model catalog and the resolved default control values, so a native
 * settings sheet can populate its model picker and show inherited defaults as
 * placeholders. Global (not per-conversation); the per-chat stored values ride
 * back on chat_thread's `conversation.controls`.
 */
function chat_controls_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatRunner.php'));

    $session = SessionControl::get_instance();
    if (!(int)$session->get_user_id()) return LogicResult::error('Sign in required.');

    $settings = Globalvars::get_instance();

    $models = [];
    foreach (LlmProviderFactory::allModels() as $id => $label) {
        $private = false;
        try {
            $private = (bool)LlmProviderFactory::forModel((string)$id)->isPrivate();
        } catch (Throwable $e) {
            // Unknown provider — treat as non-private (the safe, warn-by-default side).
        }
        $models[] = ['id' => (string)$id, 'label' => (string)$label, 'private' => $private];
    }

    $brave_key_set = (string)$settings->get_setting('joinery_ai_brave_search_api_key') !== '';

    return LogicResult::render([
        'models'               => $models,
        'web_search_available' => $brave_key_set,
        'defaults'             => [
            'model'          => ChatRunner::defaultModel(),
            'thinking_level' => (string)$settings->get_setting('joinery_ai_default_thinking_level') ?: 'off',
            'temperature'    => (string)$settings->get_setting('joinery_ai_default_temperature'),
            'top_p'          => (string)$settings->get_setting('joinery_ai_default_top_p'),
            'max_tokens'     => (string)$settings->get_setting('joinery_ai_chat_max_tokens'),
            'web_search'     => $brave_key_set
                && (string)$settings->get_setting('joinery_ai_default_web_search') === '1',
        ],
    ]);
}

function chat_controls_logic_api() {
    return ['requires_session' => true,
            'description' => 'AI chat control metadata: the model catalog and the default control values.'];
}
