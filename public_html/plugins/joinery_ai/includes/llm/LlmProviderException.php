<?php

/**
 * Base exception for every LLM provider. Both surfaces catch this type (never a
 * provider-specific subclass) so error handling is provider-agnostic.
 *
 * AnthropicException extends this for backward compatibility with any in-plugin
 * references; new providers throw LlmProviderException directly.
 */
class LlmProviderException extends Exception {

    /**
     * Classify a provider failure into a stable code from its message. Shared by
     * RecipeRunner (records [code] on the run) and the chat endpoints (maps to a
     * user-facing message) so both surfaces read failures the same way. Codes:
     * api_network_error, api_auth_failed, api_quota_exceeded,
     * api_request_invalid, api_server_error.
     */
    public static function classify(Throwable $e): string {
        $msg = strtolower($e->getMessage());

        // Local provider: connection refused to the configured base URL.
        if (strpos($msg, 'not reachable') !== false) {
            return 'api_network_error';
        }
        // Local provider: connected, but the model did not begin streaming within
        // the first-token bound (cold model load / overloaded host).
        if (strpos($msg, 'did not start responding') !== false) {
            return 'api_no_response';
        }
        if (strpos($msg, '4xx') !== false) {
            // Auth: Anthropic says "authentication_error / invalid x-api-key";
            // Fireworks/OpenAI-style say "unauthorized" / "the api key ... invalid".
            if (strpos($msg, 'authentication') !== false || strpos($msg, 'auth_error') !== false
                || strpos($msg, 'invalid x-api-key') !== false || strpos($msg, 'unauthorized') !== false
                || strpos($msg, 'api key') !== false || strpos($msg, '401') !== false
                || strpos($msg, '403') !== false) {
                return 'api_auth_failed';
            }
            // Quota / rate limit: "rate_limit" (Anthropic) and "rate limit" / "too
            // many requests" (OpenAI-style), plus quota/credit exhaustion.
            if (strpos($msg, 'quota') !== false || strpos($msg, 'rate_limit') !== false
                || strpos($msg, 'rate limit') !== false || strpos($msg, 'too many requests') !== false
                || strpos($msg, 'insufficient') !== false
                || strpos($msg, '429') !== false || strpos($msg, '402') !== false) {
                return 'api_quota_exceeded';
            }
            return 'api_request_invalid';
        }
        if (strpos($msg, '5xx') !== false || strpos($msg, 'overloaded') !== false) {
            return 'api_server_error';
        }
        if (strpos($msg, 'transport') !== false || strpos($msg, 'curl') !== false
            || strpos($msg, 'network') !== false || strpos($msg, 'timeout') !== false
            || strpos($msg, 'connection') !== false) {
            return 'api_network_error';
        }
        return 'api_server_error';
    }

    /** A user-facing message for a classify() code (for the chat surface). */
    public static function friendlyMessage(string $code): string {
        switch ($code) {
            case 'api_network_error':
                return 'Could not reach the AI provider. Check the provider settings (or that the local model server is running) and try again.';
            case 'api_no_response':
                return 'The model didn’t start responding in time — it may be loading a large model or under heavy load. Try again in a moment.';
            case 'api_auth_failed':
                return 'The AI provider rejected the credentials. Check the API key in settings.';
            case 'api_quota_exceeded':
                return 'The AI provider is rate-limited or out of quota. Wait a moment and try again.';
            case 'api_request_invalid':
                return 'The AI provider rejected the request. Try again or pick a different model.';
            case 'api_server_error':
            default:
                return 'The AI provider returned an error. Try again in a moment.';
        }
    }
}
