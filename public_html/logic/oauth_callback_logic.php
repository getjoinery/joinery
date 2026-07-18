<?php
/**
 * oauth_callback_logic - Generic OAuth2 redirect handler.
 *
 * Knows nothing about any feature; dispatches purely on the validated flow's
 * `purpose` through OAuth2ConsumerRegistry. Handler order:
 *   1. Validate state first, always. A forged/expired/foreign-session state has
 *      no flow to trust, so it renders a neutral error page (logged server-side)
 *      and redirects nowhere it was told.
 *   2. With a valid flow, branch on the provider response: if `error` is present
 *      or `code` is absent (user denied / provider error), skip token exchange
 *      and redirect to the flow's returnUrl with ?oauth=cancelled.
 *   3. Otherwise exchange the code, dispatch to the consumer, and redirect to the
 *      URL onTokenGranted returns (the success destination).
 *
 * Every redirect target — returnUrl and the consumer's success URL — is
 * validated to be a same-site path before redirecting (open-redirect safety).
 * No token or secret ever appears in an error message or a URL.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2State.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ConsumerRegistry.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Exception.php'));

/**
 * Return $url if it is a safe same-site path (leading single slash, no scheme,
 * host, or backslash), otherwise null.
 */
function oauth_callback_safe_path($url): ?string {
    if (!is_string($url) || $url === '' || $url[0] !== '/') {
        return null;
    }
    if (isset($url[1]) && $url[1] === '/') {
        return null; // protocol-relative //evil.com
    }
    if (strpos($url, "\\") !== false) {
        return null;
    }
    return $url;
}

/** Append a query param to a same-site path, respecting any existing query. */
function oauth_callback_append_param(string $path, string $key, string $value): string {
    $sep = (strpos($path, '?') === false) ? '?' : '&';
    return $path . $sep . urlencode($key) . '=' . urlencode($value);
}

function oauth_callback_logic(array $input, ?OAuth2Client $client = null): LogicResult {
    $neutral_error = LogicResult::render(['oauth_error' => true]);

    $state = isset($input['state']) ? (string)$input['state'] : '';

    // 1. Validate (and consume) state first, always.
    $flow = OAuth2State::validate($state);
    if ($flow === null) {
        error_log('OAuth2 callback: missing/expired/foreign state rejected before token exchange.');
        return $neutral_error;
    }

    $returnUrl = oauth_callback_safe_path($flow['returnUrl'] ?? '');

    // 2. Denied consent or provider error: no code → return to the cancel URL.
    $has_error = isset($input['error']) && $input['error'] !== '';
    $code = isset($input['code']) ? (string)$input['code'] : '';
    if ($has_error || $code === '') {
        if ($returnUrl === null) {
            error_log('OAuth2 callback: cancelled flow had an unsafe returnUrl; not redirecting.');
            return $neutral_error;
        }
        return LogicResult::redirect(oauth_callback_append_param($returnUrl, 'oauth', 'cancelled'));
    }

    // 3. Exchange the code and dispatch to the consumer.
    $providerClass = OAuth2ProviderRegistry::get((string)$flow['provider']);
    if ($providerClass === null) {
        error_log('OAuth2 callback: unknown provider "' . $flow['provider'] . '" on a valid flow.');
        return $neutral_error;
    }

    $consumer = OAuth2ConsumerRegistry::get((string)$flow['purpose']);
    if ($consumer === null) {
        error_log('OAuth2 callback: no consumer registered for purpose "' . $flow['purpose'] . '".');
        return $neutral_error;
    }

    try {
        $client = $client ?: new OAuth2Client();
        $token = $client->exchangeCode($providerClass, $code, OAuth2Client::redirectUri());
        // The provider redirects back with a GET; persisting the granted token
        // is this request's entire purpose. Opt in to the GET-mutation guard
        // for the consumer dispatch (same pattern as JobResultProcessor).
        SystemBase::$allow_get_mutation = true;
        try {
            $success = $consumer->onTokenGranted($token, is_array($flow['payload']) ? $flow['payload'] : []);
        } finally {
            SystemBase::$allow_get_mutation = false;
        }
    } catch (OAuth2Exception $e) {
        error_log('OAuth2 callback: ' . $e->getMessage());
        return $neutral_error;
    } catch (Throwable $e) {
        error_log('OAuth2 callback: unexpected failure dispatching token: ' . $e->getMessage());
        return $neutral_error;
    }

    $successUrl = oauth_callback_safe_path($success);
    if ($successUrl === null) {
        error_log('OAuth2 callback: consumer "' . $flow['purpose'] . '" returned an unsafe success URL.');
        return $neutral_error;
    }

    return LogicResult::redirect($successUrl);
}
