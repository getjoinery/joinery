<?php
/**
 * TestOAuthProvider - Stub OAuth2Provider for the test suite (key: 'test').
 *
 * Endpoints point at a self-hosted mock OAuth2 server (overridable via the
 * oauth_test_authorize_endpoint / oauth_test_token_endpoint settings) so Layer 2
 * can run the genuine consent loop with no Google/Azure. Credentials are fixed
 * test values so isConfigured() is true without any DB setup, letting Layer 1
 * exercise beginConsent against a Guzzle MockHandler. Loaded only by the test
 * bootstrap — never placed in includes/oauth/providers/.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));

class TestOAuthProvider implements OAuth2Provider {

    const DEFAULT_BASE = 'http://localhost:8080';

    public static function getKey(): string { return 'test'; }
    public static function getLabel(): string { return 'Test (mock)'; }

    public static function getAuthorizeEndpoint(): string {
        $settings = Globalvars::get_instance();
        $v = trim((string)$settings->get_setting('oauth_test_authorize_endpoint', false, true));
        return $v !== '' ? $v : self::DEFAULT_BASE . '/authorize';
    }

    public static function getTokenEndpoint(): string {
        $settings = Globalvars::get_instance();
        $v = trim((string)$settings->get_setting('oauth_test_token_endpoint', false, true));
        return $v !== '' ? $v : self::DEFAULT_BASE . '/token';
    }

    public static function getClientId(): string { return 'test-client-id'; }
    public static function getClientSecret(): string { return 'test-client-secret'; }
    public static function isConfigured(): bool { return true; }

    public static function extraAuthorizeParams(array $scopes): array {
        return ['access_type' => 'offline'];
    }
}
