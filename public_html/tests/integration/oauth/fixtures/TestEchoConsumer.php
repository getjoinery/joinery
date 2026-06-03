<?php
/**
 * TestEchoConsumer - Stub OAuth2Consumer for the test suite (purpose:
 * 'test_echo'). onTokenGranted records the granted token to a temp-file sink and
 * returns a fixed same-site URL. Because the callback dispatches purely on
 * state.purpose, this drives the full callback path with no product code. Loaded
 * only by the test bootstrap — never placed in a live consumer directory.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));

class TestEchoConsumer implements OAuth2Consumer {

    const SUCCESS_URL = '/oauth/test-echo-done';

    public static function getPurpose(): string { return 'test_echo'; }

    public static function sinkPath(): string {
        return sys_get_temp_dir() . '/oauth_test_echo_sink.json';
    }

    /** Clear the sink before a test run. */
    public static function resetSink(): void {
        @unlink(self::sinkPath());
    }

    /** Read what the last onTokenGranted recorded, or null. */
    public static function lastRecord(): ?array {
        $path = self::sinkPath();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function onTokenGranted(OAuth2Token $token, array $payload): string {
        file_put_contents(self::sinkPath(), json_encode([
            'access_token'  => $token->getAccessToken(),
            'refresh_token' => $token->getRefreshToken(),
            'expires_at'    => $token->getExpiresAt(),
            'scope'         => $token->getScope(),
            'payload'       => $payload,
        ]));
        return self::SUCCESS_URL;
    }
}
