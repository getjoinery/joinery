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
            'joinery_ai_local_first_token_timeout_seconds',
        ], 'hide' => ['joinery_ai_fireworks_base_url']],
        'fireworks' => ['show' => ['joinery_ai_fireworks_base_url'], 'hide' => [
            'joinery_ai_local_base_url', 'joinery_ai_local_model',
            'joinery_ai_local_api_key', 'joinery_ai_local_timeout_seconds',
            'joinery_ai_local_first_token_timeout_seconds',
        ]],
        'anthropic' => ['hide' => [
            'joinery_ai_local_base_url', 'joinery_ai_local_model',
            'joinery_ai_local_api_key', 'joinery_ai_local_timeout_seconds',
            'joinery_ai_local_first_token_timeout_seconds',
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
    'helptext' => 'Model id(s) served by the host. Must be set before the local provider runs. '
                . 'Comma-separate multiple ids (e.g. "qwen3.5:9b-nvfp4, qwen3:0.6b") to offer them '
                . 'all in the chat model dropdown — the first is the default.',
]);

$formwriter->passwordinput('joinery_ai_local_api_key', 'Local API Key', [
    'value' => $settings->get_setting('joinery_ai_local_api_key'),
    'placeholder' => '(optional)',
    'helptext' => 'Only for servers that require a key; Ollama ignores it.',
]);

$formwriter->numberinput('joinery_ai_local_timeout_seconds', 'Local Timeout (s)', [
    'value' => $settings->get_setting('joinery_ai_local_timeout_seconds'),
    'min' => 1,
    'helptext' => 'Per-call HTTP timeout — the max quiet gap between tokens once the model is '
                . 'generating. CPU-only local generation is slow — keep this high.',
]);

$formwriter->numberinput('joinery_ai_local_first_token_timeout_seconds', 'Local First-Token Timeout (s)', [
    'value' => $settings->get_setting('joinery_ai_local_first_token_timeout_seconds'),
    'min' => 1,
    'helptext' => 'How long to wait for the model to *start* responding before failing the turn. '
                . 'Bounds a cold model load or an overloaded host so a stalled request fails fast '
                . 'instead of waiting out the full per-call timeout above.',
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

$formwriter->dropinput('joinery_ai_memory_default_on', 'Memory on by default for new chats', [
    'value' => $settings->get_setting('joinery_ai_memory_default_on') === '1' ? '1' : '0',
    'options' => ['1' => 'Yes', '0' => 'No'],
    'helptext' => 'New chats start with the Memory capability enabled; each chat can '
                . 'toggle it off. Memories are managed under Joinery AI → Memory.',
]);

$formwriter->numberinput('joinery_ai_memory_context_max_entries', 'Memory index cap (entries)', [
    'value' => $settings->get_setting('joinery_ai_memory_context_max_entries'),
    'min' => 1,
    'helptext' => 'Most personal memories listed (titles only) in each turn\'s memory index. '
                . 'Shared memories are always listed and don\'t count against this.',
]);

$formwriter->numberinput('joinery_ai_memory_prefetch_max', 'Memory pre-retrieval cap (count)', [
    'value' => $settings->get_setting('joinery_ai_memory_prefetch_max'),
    'min' => 1,
    'helptext' => 'Most memory bodies auto-opened per turn when their words match the message.',
]);

$formwriter->numberinput('joinery_ai_memory_prefetch_max_chars', 'Memory pre-retrieval cap (chars)', [
    'value' => $settings->get_setting('joinery_ai_memory_prefetch_max_chars'),
    'min' => 100,
    'helptext' => 'Total character budget for those auto-opened bodies; overflow is truncated '
                . 'with a marker the assistant can follow up on with recall.',
]);

$formwriter->dropinput('joinery_ai_default_chat_level', 'Default privacy for new chats', [
    'value' => $settings->get_setting('joinery_ai_default_chat_level') ?: 'standard',
    'options' => [
        'standard' => 'Standard — the server manages the chat for you',
        'private'  => 'Private — only you can read the stored chat (unlock required)',
        'fortress' => 'Fortress — chat content never leaves your hardware (local model only)',
    ],
    'helptext' => 'Applied to every new chat; each chat can override it. Private and '
                . 'Fortress seal the stored chat under your vault, so they need a set-up '
                . 'vault to unlock — a chat falls back to Standard when you have none. '
                . 'Fortress additionally pins a local model, so nothing is sent to a cloud '
                . 'AI provider: chats send message text to your configured provider; '
                . 'Fortress keeps that on the box.',
]);
?>
