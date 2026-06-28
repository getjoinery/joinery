<?php
// Joinery AI plugin settings — included from /admin/admin_settings
// $formwriter, $settings, and $session are already available.
?>

<p>API keys and runtime caps for scheduled LLM recipes. Settings starting with
<code>joinery_ai_</code> are owned by this plugin.</p>

<h4>LLM provider</h4>

<?php
$formwriter->dropinput('joinery_ai_llm_provider', 'LLM Provider', [
    'value' => $settings->get_setting('joinery_ai_llm_provider') ?: 'anthropic',
    'options' => [
        'anthropic' => 'Anthropic (cloud)',
        'fireworks' => 'Fireworks (cloud · no-train, private)',
        'local'     => 'Local / self-hosted (OpenAI-compatible)',
    ],
    'visibility_rules' => [
        'local' => ['show' => [
            'joinery_ai_local_base_url', 'joinery_ai_local_model',
            'joinery_ai_local_api_key', 'joinery_ai_local_timeout_seconds',
        ], 'hide' => ['joinery_ai_fireworks_base_url']],
        'fireworks' => ['show' => ['joinery_ai_fireworks_base_url'], 'hide' => [
            'joinery_ai_local_base_url', 'joinery_ai_local_model',
            'joinery_ai_local_api_key', 'joinery_ai_local_timeout_seconds',
        ]],
        'anthropic' => ['hide' => [
            'joinery_ai_local_base_url', 'joinery_ai_local_model',
            'joinery_ai_local_api_key', 'joinery_ai_local_timeout_seconds',
            'joinery_ai_fireworks_base_url',
        ]],
    ],
    'helptext' => 'Which backend drives recipes. The recipe model is reinterpreted '
                . 'by whichever provider is active.',
]);

$formwriter->textinput('joinery_ai_local_base_url', 'Local Base URL', [
    'value' => $settings->get_setting('joinery_ai_local_base_url'),
    'placeholder' => 'http://localhost:11434/v1',
    'helptext' => 'OpenAI-compatible endpoint (Ollama, llama.cpp, vLLM, LM Studio). '
                . 'Use the host URL if the server is not on this box.',
]);

$formwriter->textinput('joinery_ai_local_model', 'Local Model', [
    'value' => $settings->get_setting('joinery_ai_local_model'),
    'placeholder' => 'qwen3:14b',
    'helptext' => 'Model id served by the host. Must be set before the local provider runs.',
]);

$formwriter->passwordinput('joinery_ai_local_api_key', 'Local API Key', [
    'value' => $settings->get_setting('joinery_ai_local_api_key'),
    'placeholder' => '(optional)',
    'helptext' => 'Only for servers that require a key; Ollama ignores it.',
]);

$formwriter->numberinput('joinery_ai_local_timeout_seconds', 'Local Timeout (s)', [
    'value' => $settings->get_setting('joinery_ai_local_timeout_seconds'),
    'min' => 1,
    'helptext' => 'Per-call HTTP timeout. CPU-only local generation is slow — keep this high.',
]);

$formwriter->textinput('joinery_ai_fireworks_base_url', 'Fireworks Base URL', [
    'value' => $settings->get_setting('joinery_ai_fireworks_base_url'),
    'placeholder' => 'https://api.fireworks.ai/inference/v1',
    'helptext' => 'OpenAI-compatible Fireworks endpoint. The default is correct for the public API.',
]);
?>

<h4>API keys</h4>

<?php
$formwriter->passwordinput('joinery_ai_anthropic_api_key', 'Anthropic API Key', [
    'value' => $settings->get_setting('joinery_ai_anthropic_api_key'),
    'placeholder' => 'sk-ant-...',
    'helptext' => 'Required when the provider is Anthropic. Get one from console.anthropic.com.',
]);

$formwriter->passwordinput('joinery_ai_fireworks_api_key', 'Fireworks API Key', [
    'value' => $settings->get_setting('joinery_ai_fireworks_api_key'),
    'placeholder' => 'fw_...',
    'helptext' => 'Required to use Fireworks models. Get one from fireworks.ai. '
                . 'Fireworks does not train on open-model traffic.',
]);

$formwriter->passwordinput('joinery_ai_brave_search_api_key', 'Brave Search API Key', [
    'value' => $settings->get_setting('joinery_ai_brave_search_api_key'),
    'placeholder' => 'BSA...',
    'helptext' => 'Required only for recipes that use the web_search tool. '
                . 'Free tier (2,000 queries/month) at api.search.brave.com.',
]);

$formwriter->passwordinput('joinery_ai_market_data_api_key', 'Market Data API Key (Finnhub)', [
    'value' => $settings->get_setting('joinery_ai_market_data_api_key'),
    'placeholder' => 'Finnhub API key',
    'helptext' => 'Required only for recipes that use get_stock_data. '
                . 'Free tier at finnhub.io. (Tool not yet implemented — Phase 8.)',
]);
?>

<h4>Runtime caps and behavior</h4>

<?php
$formwriter->textinput('joinery_ai_default_model', 'Default Model for new recipes', [
    'value' => $settings->get_setting('joinery_ai_default_model'),
    'placeholder' => 'claude-haiku-4-5',
    'helptext' => 'Used when creating a new recipe. Each recipe can override.',
]);

$formwriter->numberinput('joinery_ai_global_monthly_token_cap', 'Global Monthly Token Cap', [
    'value' => $settings->get_setting('joinery_ai_global_monthly_token_cap'),
    'min' => 0,
    'helptext' => 'Hard ceiling across all recipes per calendar month. '
                . '(Enforcement lands in Phase 6.)',
]);

$formwriter->numberinput('joinery_ai_max_concurrent_workers', 'Max Concurrent Workers', [
    'value' => $settings->get_setting('joinery_ai_max_concurrent_workers'),
    'min' => 1,
    'helptext' => 'Limits how many recipe runs can be in-flight simultaneously. '
                . '(Used by the async dispatcher — Phase 5.)',
]);

$formwriter->numberinput('joinery_ai_workspace_max_chars', 'Workspace Size Cap (chars)', [
    'value' => $settings->get_setting('joinery_ai_workspace_max_chars'),
    'min' => 1000,
    'helptext' => 'Hard cap on the per-recipe workspace blob; set_workspace rejects '
                . 'oversize writes. (Workspace tools land in Phase 3.)',
]);

$formwriter->numberinput('joinery_ai_failure_email_throttle_seconds', 'Failure Email Throttle (s)', [
    'value' => $settings->get_setting('joinery_ai_failure_email_throttle_seconds'),
    'min' => 0,
    'helptext' => 'Minimum seconds between failure-notification emails per recipe. '
                . '(Used by the email-delivery layer — Phase 7.)',
]);
?>

<h4>Chat</h4>

<?php
$formwriter->textbox('joinery_ai_chat_system_prompt', 'Chat System Prompt', [
    'value' => $settings->get_setting('joinery_ai_chat_system_prompt'),
    'rows' => 6,
    'placeholder' => "You are Joinery AI, a helpful assistant for the administrator of this site.\nAnswer naturally and conversationally. Use Markdown when it helps.",
    'helptext' => 'Sets the chat assistant\'s voice. Leave blank to use the default. '
                . 'The date/time, tool rules, and safety instructions are always added '
                . 'automatically and cannot be removed here.',
]);
?>
