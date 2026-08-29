<?php
/**
 * ImapConnectStash — a granted token held for one more step
 * (specs/mailbox_connect_flow.md § Failure and edge cases).
 *
 * Consent can succeed and provisioning still fail: the provider does not report
 * an address and the operator has not typed one yet, or the domain row refuses
 * to save. Dropping the token there costs the operator the whole sign-in for a
 * reason that has nothing to do with signing in, so it is held instead — long
 * enough for the wizard to ask one question and retry.
 *
 * Same shape OAuth2State gives the flow payload: server-side in the session,
 * single-use, expiring — so it exists only in the browser session that earned
 * it, and a callback arriving anywhere else simply finds nothing. The stored
 * token is SecretBox-encrypted on top of that, because a session file is a file
 * and a refresh token is a standing credential.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));

class ImapConnectStash {

	const SESSION_KEY = 'mailbox_imap_connect_stash';
	/** Ten minutes: one question and a retry, not a place tokens live. */
	const LIFETIME_SECONDS = 600;

	/**
	 * Hold a granted token plus the intent it was granted for. Replaces any
	 * previous stash — a second sign-in supersedes the first.
	 */
	public static function put(string $provider_key, array $intent, OAuth2Token $token): void {
		self::ensureSession();
		$_SESSION[self::SESSION_KEY] = array(
			'provider_key' => $provider_key,
			'intent'       => $intent,
			'token'        => (new SecretBox())->seal('session:mailbox_imap_connect_stash', json_encode(array(
				'access_token'  => $token->getAccessToken(),
				'refresh_token' => $token->getRefreshToken(),
				'expires_at'    => $token->getExpiresAt(),
				'scope'         => $token->getScope(),
				'token_type'    => $token->getTokenType(),
			))),
			'expires' => time() + self::LIFETIME_SECONDS,
		);
	}

	/**
	 * The held grant as ['provider_key' => string, 'intent' => array,
	 * 'token' => OAuth2Token], or null when there is none / it has expired.
	 * Reading does NOT consume it: the wizard reads it to know it is holding a
	 * connection, and consumes it only when provisioning succeeds.
	 */
	public static function peek(): ?array {
		self::ensureSession();
		$held = $_SESSION[self::SESSION_KEY] ?? null;
		if (!is_array($held) || intval($held['expires'] ?? 0) < time()) {
			self::clear();
			return null;
		}
		$opened = (new SecretBox())->open((string)$held['token']);
		if ($opened['value'] === null) {   // dead / unreadable stash
			self::clear();
			return null;
		}
		$decoded = json_decode($opened['value'], true);
		if (!is_array($decoded) || empty($decoded['access_token'])) {
			self::clear();
			return null;
		}
		return array(
			'provider_key' => (string)($held['provider_key'] ?? ''),
			'intent'       => is_array($held['intent'] ?? null) ? $held['intent'] : array(),
			'token'        => new OAuth2Token(
				(string)$decoded['access_token'],
				$decoded['refresh_token'] ?? null,
				$decoded['expires_at'] ?? null,
				(string)($decoded['scope'] ?? ''),
				(string)($decoded['token_type'] ?? 'Bearer')
			),
		);
	}

	/** Forget the held grant — after it has been used, or deliberately abandoned. */
	public static function clear(): void {
		self::ensureSession();
		unset($_SESSION[self::SESSION_KEY]);
	}

	private static function ensureSession(): void {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
	}
}
