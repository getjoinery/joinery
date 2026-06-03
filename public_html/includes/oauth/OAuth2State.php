<?php
/**
 * OAuth2State - Session-stored, single-use CSRF + dispatch carrier.
 *
 * Built on the same mechanism as the platform's CSRF tokens (FormWriterV2Base):
 * a single-use, expiring entry held server-side in $_SESSION. The opaque
 * `state` query param is just an unguessable random nonce; all flow data lives
 * in the session entry it keys, never in the browser. No HMAC and no signing
 * are needed — the value never carries client-tamperable data; the session
 * itself is the trust anchor.
 *
 * Session binding is intrinsic: the entry exists only in the session that
 * issued it, so a callback arriving in any other session simply finds no match.
 * Expired entries are pruned on the next issue(), exactly how the CSRF store
 * self-cleans. There is no DB table and no scheduled sweep.
 *
 * NOTE: depends on the session cookie staying SameSite=Lax (the SessionControl
 * default). The provider redirect to /oauth_callback is a cross-site top-level
 * GET; Strict would withhold the cookie, start a fresh session, find no entry,
 * and fail every flow.
 *
 * @version 1.0
 */
class OAuth2State {

    const SESSION_KEY = 'oauth_flows';
    const NONCE_LENGTH = 64;
    const LIFETIME_SECONDS = 600; // 10 minutes

    /**
     * Generate a nonce, store the flow under it, prune expired entries, and
     * return the nonce to use as the `state` query param.
     *
     * @param array  $payload Consumer's opaque data (e.g. ['account_id' => N]).
     */
    public static function issue(
        string $provider,
        string $purpose,
        array $scopes,
        array $payload,
        string $returnUrl
    ): string {
        self::ensureSession();
        self::prune();

        $nonce = LibraryFunctions::str_rand(self::NONCE_LENGTH);

        $_SESSION[self::SESSION_KEY][$nonce] = [
            'provider'  => $provider,
            'purpose'   => $purpose,
            'scopes'    => array_values($scopes),
            'payload'   => $payload,
            'returnUrl' => $returnUrl,
            'expires'   => time() + self::LIFETIME_SECONDS,
        ];

        return $nonce;
    }

    /**
     * Look up and consume a flow by its state nonce. Returns the decoded flow
     * array on success, or null if the nonce is absent (forged, replayed, or
     * from another session) or expired. On success the entry is unset
     * (single-use).
     */
    public static function validate(string $state): ?array {
        if ($state === '' || empty($_SESSION[self::SESSION_KEY][$state])) {
            return null;
        }

        $flow = $_SESSION[self::SESSION_KEY][$state];

        // Single-use: consume regardless of expiry outcome.
        unset($_SESSION[self::SESSION_KEY][$state]);

        if (!isset($flow['expires']) || $flow['expires'] < time()) {
            return null;
        }

        return $flow;
    }

    /** Drop expired flow entries. Called on every issue(), like the CSRF store. */
    private static function prune(): void {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return;
        }
        $now = time();
        foreach ($_SESSION[self::SESSION_KEY] as $nonce => $flow) {
            if (!isset($flow['expires']) || $flow['expires'] < $now) {
                unset($_SESSION[self::SESSION_KEY][$nonce]);
            }
        }
    }

    private static function ensureSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // SessionControl normally has the session open by this point; guard
            // for direct/test contexts.
            @session_start();
        }
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
    }
}
