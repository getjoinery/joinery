<?php
/**
 * OAuth2State + generic-callback control-flow test (no network).
 *
 * Covers: state round-trip; rejection of expired, replayed (single-use), and
 * unknown/foreign-session nonces; same-site path validation; and the callback's
 * three branches — neutral error on forged state, cancel-redirect on denied
 * consent (incl. unsafe-returnUrl refusal), and success dispatch to a stub
 * consumer with a mocked code exchange.
 *
 * Run: php tests/integration/oauth/oauth2_state_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2State.php'));
require_once(PathHelper::getIncludePath('logic/oauth_callback_logic.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ConsumerRegistry.php'));
require_once(__DIR__ . '/fixtures/TestOAuthProvider.php');
require_once(__DIR__ . '/fixtures/TestEchoConsumer.php');

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class OAuth2StateTest {
    private $pass = 0;
    private $fail = 0;

    private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
    private function ok($cond, $label) {
        if ($cond) { $this->pass++; $this->out('  PASS: ' . $label); }
        else { $this->fail++; $this->out('  FAIL: ' . $label); }
    }

    private function mockClient(array $responses): OAuth2Client {
        $mock = new MockHandler($responses);
        return new OAuth2Client(new Client(['handler' => HandlerStack::create($mock)]));
    }

    function run() {
        $this->out('=== OAuth2State + callback tests ===');
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $_SESSION['oauth_flows'] = [];

        // ---- State round-trip + single-use ----
        $nonce = OAuth2State::issue('google', 'inbound_imap', ['mail.read'], ['account_id' => 5], '/back');
        $flow = OAuth2State::validate($nonce);
        $this->ok($flow !== null && $flow['provider'] === 'google', 'valid state round-trips provider');
        $this->ok($flow['purpose'] === 'inbound_imap' && $flow['payload']['account_id'] === 5, 'round-trips purpose + payload');
        $this->ok(OAuth2State::validate($nonce) === null, 'state is single-use (replay rejected)');

        // ---- Unknown / foreign nonce ----
        $this->ok(OAuth2State::validate('nonexistent-nonce') === null, 'unknown/foreign nonce rejected');
        $this->ok(OAuth2State::validate('') === null, 'empty state rejected');

        // ---- Expired entry ----
        $_SESSION['oauth_flows']['expired'] = [
            'provider' => 'google', 'purpose' => 'x', 'scopes' => [], 'payload' => [],
            'returnUrl' => '/back', 'expires' => time() - 1,
        ];
        $this->ok(OAuth2State::validate('expired') === null, 'expired state rejected');

        // ---- Same-site path validation ----
        $this->ok(oauth_callback_safe_path('/profile/settings') === '/profile/settings', 'same-site path accepted');
        $this->ok(oauth_callback_safe_path('/p?x=1&y=2') === '/p?x=1&y=2', 'same-site path with query accepted');
        $this->ok(oauth_callback_safe_path('https://evil.com/x') === null, 'absolute URL rejected');
        $this->ok(oauth_callback_safe_path('//evil.com') === null, 'protocol-relative URL rejected');
        $this->ok(oauth_callback_safe_path('') === null, 'empty path rejected');

        // ---- Callback: forged state renders neutral error, redirects nowhere ----
        $r = oauth_callback_logic(['state' => 'forged', 'code' => 'whatever']);
        $this->ok($r->redirect === null && !empty($r->data['oauth_error']), 'forged state -> neutral error, no redirect');

        // ---- Callback: denied consent (error param, no code) -> returnUrl?oauth=cancelled ----
        $n = OAuth2State::issue('google', 'test_echo', [], [], '/imap/edit?id=3');
        $r = oauth_callback_logic(['state' => $n, 'error' => 'access_denied']);
        $this->ok($r->redirect === '/imap/edit?id=3&oauth=cancelled', 'denied consent -> cancel redirect with existing query');

        // ---- Callback: denied with unsafe returnUrl -> neutral error ----
        $n = OAuth2State::issue('google', 'test_echo', [], [], 'https://evil.com/x');
        $r = oauth_callback_logic(['state' => $n, 'error' => 'access_denied']);
        $this->ok($r->redirect === null && !empty($r->data['oauth_error']), 'denied with off-site returnUrl -> neutral error');

        // ---- Callback: success path dispatches to the stub consumer ----
        OAuth2ProviderRegistry::reset();
        OAuth2ConsumerRegistry::reset();
        TestEchoConsumer::resetSink();

        $n = OAuth2State::issue('test', 'test_echo', ['mail.read'], ['account_id' => 9], '/back');
        $client = $this->mockClient([
            new Response(200, ['Content-Type' => 'application/json'],
                json_encode(['access_token' => 'echo-at', 'refresh_token' => 'echo-rt', 'expires_in' => 3600, 'scope' => 'mail.read'])),
        ]);
        $r = oauth_callback_logic(['state' => $n, 'code' => 'auth-code'], $client);
        $this->ok($r->redirect === TestEchoConsumer::SUCCESS_URL, 'success -> redirect to consumer success URL');

        $record = TestEchoConsumer::lastRecord();
        $this->ok($record !== null && $record['access_token'] === 'echo-at', 'consumer received the granted access token');
        $this->ok($record !== null && ($record['payload']['account_id'] ?? null) === 9, 'consumer received the flow payload');

        $this->out('');
        $this->out('Results: ' . $this->pass . ' passed, ' . $this->fail . ' failed');
        return $this->fail === 0;
    }
}

$t = new OAuth2StateTest();
exit($t->run() ? 0 : 1);
