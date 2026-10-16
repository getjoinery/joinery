<?php

/**
 * Base exception for every LLM provider. Both surfaces catch this type (never a
 * provider-specific subclass) so error handling is provider-agnostic.
 *
 * AnthropicException extends this for backward compatibility with any in-plugin
 * references; new providers throw LlmProviderException directly.
 *
 * A provider's HTTP failure carries the status as the exception code and reads
 * "<Provider> 4xx: <error type>: <the provider's message>" (or 5xx).
 *
 * @version 1.1 - classify() reads the HTTP status and the provider's error type,
 *   with codes for a key without permission, an unknown model, an account out of
 *   credit and a request too large; operatorMessage() adds the provider's own
 *   words where they say what to change
 */
class LlmProviderException extends Exception {

    /**
     * The codes whose message carries the provider's own words: each is a
     * refusal about this install's key, account, model or request, and the
     * provider's sentence names the exact thing to change (a workspace header,
     * a model id, a credit balance). Network, timeout, rate-limit and server
     * failures keep the friendly text alone.
     */
    const CODES_WITH_PROVIDER_WORDS = [
        'api_auth_failed', 'api_permission_denied', 'api_model_not_found',
        'api_billing', 'api_request_too_large', 'api_request_invalid',
    ];

    /** The longest stretch of the provider's message put on a row. */
    const PROVIDER_WORDS_MAX = 300;

    /**
     * Classify a provider failure into a stable code. Shared by RecipeRunner
     * (records [code] on the run) and the chat endpoints (maps to a user-facing
     * message) so both surfaces read failures the same way. Codes:
     * api_not_configured, api_network_error, api_no_response, api_auth_failed,
     * api_permission_denied, api_model_not_found, api_billing,
     * api_quota_exceeded (rate limited), api_request_too_large,
     * api_request_invalid, api_server_error.
     */
    public static function classify(Throwable $e): string {
        $msg = strtolower($e->getMessage());
        $status = (int)$e->getCode();
        $has = function (array $needles) use ($msg): bool {
            foreach ($needles as $n) {
                if (strpos($msg, $n) !== false) return true;
            }
            return false;
        };

        // Nothing was ever sent: the provider's required setting is empty, so the
        // factory refused to build it. A distinct code because the remedy is to
        // configure a setting, not to retry — every provider phrases this as
        // "<setting> is empty" (see LlmProviderFactory). Checked before the 4xx
        // branch: this failure has no HTTP status to key on.
        if (strpos($msg, 'is empty') !== false) {
            return 'api_not_configured';
        }
        // Local provider: connection refused to the configured base URL.
        if (strpos($msg, 'not reachable') !== false) {
            return 'api_network_error';
        }
        // Local provider: connected, but the model did not begin streaming within
        // the first-token bound (cold model load / overloaded host).
        if (strpos($msg, 'did not start responding') !== false) {
            return 'api_no_response';
        }
        if (strpos($msg, '4xx') !== false || ($status >= 400 && $status < 500)) {
            // Out of credit or over a spending limit. Anthropic answers a 400
            // invalid_request_error ("Your credit balance is too low"), OpenAI-style
            // hosts a 429 insufficient_quota or a 402; ahead of the status checks
            // because those statuses mean something else on their own.
            if ($status === 402 || $has(['credit balance', 'insufficient_quota', 'billing', 'spend limit', 'spending limit'])) {
                return 'api_billing';
            }
            // The key itself: Anthropic's authentication_error ("invalid x-api-key"),
            // OpenAI-style "incorrect api key" / "invalid api key" / unauthorized.
            if ($status === 401 || $has(['authentication_error', 'invalid x-api-key', 'incorrect api key',
                    'invalid api key', 'unauthorized'])) {
                return 'api_auth_failed';
            }
            // A good key that may not do this: Anthropic's permission_error, a 403.
            if ($status === 403 || $has(['permission_error', 'forbidden'])) {
                return 'api_permission_denied';
            }
            if ($status === 404 || $has(['not_found_error', 'model not found', 'model_not_found'])
                    || ($has(['model']) && $has(['does not exist']))) {
                return 'api_model_not_found';
            }
            if ($status === 413 || $has(['request_too_large', 'prompt is too long', 'context length', 'context_length',
                    'maximum context', 'too many tokens'])) {
                return 'api_request_too_large';
            }
            if ($status === 429 || $has(['rate_limit', 'rate limit', 'too many requests'])) {
                return 'api_quota_exceeded';
            }
            return 'api_request_invalid';
        }
        if (strpos($msg, '5xx') !== false || strpos($msg, 'overloaded') !== false || $status >= 500) {
            return 'api_server_error';
        }
        if (strpos($msg, 'transport') !== false || strpos($msg, 'curl') !== false
            || strpos($msg, 'network') !== false || strpos($msg, 'timeout') !== false
            || strpos($msg, 'connection') !== false) {
            return 'api_network_error';
        }
        return 'api_server_error';
    }

    /**
     * The message to put on a failed row. A misconfiguration is reported in the
     * provider's own words: those messages are written here in the plugin, name a
     * setting rather than a value, and tell the operator exactly what to fill in.
     * A refusal about the key, account, model or request gets the friendly text
     * plus what the provider said, since that sentence names the fix ("this API
     * key is not scoped to a workspace", "your credit balance is too low").
     * Everything else keeps the friendly text; the full message still reaches the
     * worker log via the callers.
     */
    public static function operatorMessage(Throwable $e): string {
        $code = self::classify($e);
        if ($code === 'api_not_configured') {
            return $e->getMessage();
        }
        $friendly = self::friendlyMessage($code);
        if (in_array($code, self::CODES_WITH_PROVIDER_WORDS, true)) {
            $said = self::providerWords($e->getMessage());
            if ($said !== null) {
                return $friendly . ' ' . $said['provider'] . ' said: “' . $said['words'] . '”';
            }
        }
        return $friendly;
    }

    /**
     * The provider's name and its own sentence from a "<Provider> 4xx: ..." message,
     * without the error-type prefix ("invalid_request_error: "); null when the
     * message has no such shape.
     */
    public static function providerWords(string $message): ?array {
        if (!preg_match('/^\s*(.+?)(?:\s+API)?\s+[45]xx(?:\s*\(\d+\))?:\s*(.+)$/s', $message, $m)) {
            return null;
        }
        $words = trim(preg_replace('/^[a-z_]+_error:\s*/', '', trim($m[2])));
        if ($words === '') {
            return null;
        }
        if (mb_strlen($words) > self::PROVIDER_WORDS_MAX) {
            $words = rtrim(mb_substr($words, 0, self::PROVIDER_WORDS_MAX)) . '…';
        }
        return array('provider' => trim($m[1]), 'words' => $words);
    }

    /** A user-facing message for a classify() code (for the chat surface). */
    public static function friendlyMessage(string $code): string {
        switch ($code) {
            case 'api_not_configured':
                return 'The AI provider is not configured — a required API key or model setting is empty. Set it on the Joinery AI settings page.';
            case 'api_network_error':
                return 'Could not reach the AI provider. Check the provider settings (or that the local model server is running) and try again.';
            case 'api_no_response':
                return 'The model didn’t start responding in time — it may be loading a large model or under heavy load. Try again in a moment.';
            case 'api_auth_failed':
                return 'The AI provider did not accept the API key. Check the key on the Joinery AI settings page.';
            case 'api_permission_denied':
                return 'The AI provider accepted the API key but does not allow it to do this; the model may not be enabled for the key’s account or workspace.';
            case 'api_model_not_found':
                return 'The AI provider does not offer this model; it may be retired or misspelled. Pick a different model.';
            case 'api_billing':
                return 'The AI provider account is out of credit or over its spending limit. Add credit or raise the limit with the provider.';
            case 'api_quota_exceeded':
                return 'The AI provider is rate-limiting this key. Wait a moment and try again.';
            case 'api_request_too_large':
                return 'This conversation is too long for the model. Start a new chat, attach less, or pick a model with a larger context.';
            case 'api_request_invalid':
                return 'The AI provider refused the request.';
            case 'api_server_error':
            default:
                return 'The AI provider returned an error. Try again in a moment.';
        }
    }
}
