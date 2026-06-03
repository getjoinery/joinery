<?php
/**
 * OAuth2Client test (Layer 1, no network) - exchangeCode, refresh, ensureFresh,
 * non-2xx handling, and beginConsent URL assembly, all against Guzzle's
 * MockHandler with the test fixture provider.
 *
 * Run: php tests/integration/oauth/oauth2_client_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Exception.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
require_once(__DIR__ . '/fixtures/TestOAuthProvider.php');

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class OAuth2ClientTest {
    private $pass = 0;
    private $fail = 0;

    private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
    private function ok($cond, $label) {
        if ($cond) { $this->pass++; $this->out('  PASS: ' . $label); }
        else { $this->fail++; $this->out('  FAIL: ' . $label); }
    }

    /** Build an OAuth2Client whose Guzzle returns the queued responses in order. */
    private function clientWith(array $responses): OAuth2Client {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        return new OAuth2Client(new Client(['handler' => $stack]));
    }

    private function jsonResponse($status, array $body): Response {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    function run() {
        $this->out('=== OAuth2Client tests ===');
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

        // Make the test provider discoverable.
        OAuth2ProviderRegistry::reset();
        $provider = TestOAuthProvider::class;
        $this->ok(OAuth2ProviderRegistry::get('test') === $provider, 'test provider discovered by registry');

        // exchangeCode parses access/refresh/expiry/scope.
        $client = $this->clientWith([
            $this->jsonResponse(200, [
                'access_token'  => 'at-1',
                'refresh_token' => 'rt-1',
                'expires_in'    => 3600,
                'scope'         => 'mail.read',
                'token_type'    => 'Bearer',
            ]),
        ]);
        $token = $client->exchangeCode($provider, 'the-code', 'http://localhost/oauth_callback');
        $this->ok($token->getAccessToken() === 'at-1', 'exchangeCode parses access_token');
        $this->ok($token->getRefreshToken() === 'rt-1', 'exchangeCode parses refresh_token');
        $this->ok($token->getScope() === 'mail.read', 'exchangeCode parses scope');
        $this->ok(!$token->isExpired(), 'fresh token not expired');

        // refresh: response omits refresh_token; ensureFresh must preserve the old one.
        $expired = new OAuth2Token('old-at', 'rt-keep', gmdate('Y-m-d H:i:s', time() - 10), 'mail.read');
        $this->ok($expired->isExpired(), 'token past expiry reports expired');
        $client = $this->clientWith([
            $this->jsonResponse(200, ['access_token' => 'at-2', 'expires_in' => 3600]),
        ]);
        $fresh = $client->ensureFresh($provider, $expired);
        $this->ok($fresh->getAccessToken() === 'at-2', 'ensureFresh swaps in the new access token');
        $this->ok($fresh->getRefreshToken() === 'rt-keep', 'ensureFresh preserves prior refresh token when omitted');

        // ensureFresh on a still-valid token does no HTTP (empty mock queue).
        $valid = new OAuth2Token('still-good', 'rt', gmdate('Y-m-d H:i:s', time() + 3600), '');
        $client = $this->clientWith([]); // any request would throw "queue empty"
        $same = $client->ensureFresh($provider, $valid);
        $this->ok($same->getAccessToken() === 'still-good', 'ensureFresh skips refresh within validity (no HTTP)');

        // Non-2xx token response raises OAuth2Exception.
        $client = $this->clientWith([
            $this->jsonResponse(400, ['error' => 'invalid_grant', 'error_description' => 'bad code']),
        ]);
        $threw = false;
        try { $client->exchangeCode($provider, 'bad', 'http://localhost/oauth_callback'); }
        catch (OAuth2Exception $e) { $threw = true; }
        $this->ok($threw, 'non-2xx token response throws OAuth2Exception');

        // A 200 with no access_token also throws.
        $client = $this->clientWith([$this->jsonResponse(200, ['nope' => 1])]);
        $threw = false;
        try { $client->exchangeCode($provider, 'x', 'http://localhost/oauth_callback'); }
        catch (OAuth2Exception $e) { $threw = true; }
        $this->ok($threw, 'token response without access_token throws');

        // beginConsent builds a correct authorize URL and issues state.
        $client = $this->clientWith([]);
        $url = $client->beginConsent('test', ['mail.read', 'offline_access'], 'test_echo', ['account_id' => 7], '/back');
        $this->ok(strpos($url, $provider::getAuthorizeEndpoint()) === 0, 'consent URL starts with authorize endpoint');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->ok(($q['response_type'] ?? '') === 'code', 'consent URL response_type=code');
        $this->ok(($q['client_id'] ?? '') === 'test-client-id', 'consent URL carries client_id');
        $this->ok(($q['scope'] ?? '') === 'mail.read offline_access', 'consent URL space-joined scopes');
        $this->ok(($q['access_type'] ?? '') === 'offline', 'consent URL merges provider extra params');
        $this->ok(!empty($q['state']) && strlen($q['state']) >= 32, 'consent URL carries an unguessable state nonce');
        $this->ok(($q['redirect_uri'] ?? '') === OAuth2Client::redirectUri(), 'consent URL redirect_uri matches canonical');

        $this->out('');
        $this->out('Results: ' . $this->pass . ' passed, ' . $this->fail . ' failed');
        return $this->fail === 0;
    }
}

$t = new OAuth2ClientTest();
exit($t->run() ? 0 : 1);
