<?php
// Joinery AI plugin settings — included from /admin/admin_settings
// $formwriter, $settings, and $session are already available.
?>

<p>API keys and runtime caps for scheduled LLM recipes. Settings starting with
<code>joinery_ai_</code> are owned by this plugin.</p>

<h4>API keys</h4>

<?php
$formwriter->passwordinput('joinery_ai_anthropic_api_key', 'Anthropic API Key', [
    'value' => $settings->get_setting('joinery_ai_anthropic_api_key'),
    'placeholder' => 'sk-ant-...',
    'helptext' => 'Required for any recipe to run. Get one from console.anthropic.com.',
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
