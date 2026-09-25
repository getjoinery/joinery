<?php
/**
 * VaultClientResume - the server's half of keeping a browser-held vault open
 * across a reload of the same tab (specs/client_custody_mail.md § R4a).
 *
 * A client-custody vault's secret lives in the page's memory, so a reload used
 * to drop it and ask for the passkey again. When a scope opens, the browser
 * wraps its secret under a key derived from two random halves: one it keeps in
 * the tab's sessionStorage beside the wrapped secret, one it hands here. A
 * reload asks for this half back, rebuilds the key, and unwraps.
 *
 * Neither half opens anything alone. This half lives in the PHP session, so it
 * is readable only with this session's cookie, and it dies when the session
 * does: sign-out, expiry, or an explicit drop when the vault is locked in the
 * browser. The tab's half dies when the tab closes. The server never sees the
 * tab's half or the wrapped secret, so holding this one gives it nothing it
 * can open.
 *
 * Each tab keeps its own half under a random id it makes (`tab`), so two tabs
 * of one session never overwrite each other; a session holds at most
 * MAX_PER_SCOPE halves per scope, oldest dropped first.
 *
 * Never log a share. The API logs no request bodies; keep it that way here.
 *
 * @version 1.1 - a half kept before the account's last recovery-code use is refused
 * @version 1.0
 */

class VaultClientResumeException extends Exception {}

class VaultClientResume {

	/** Where the halves live in $_SESSION, keyed by scope. */
	const SESSION_KEY = 'jy_vault_client_resume';

	/** A share is 32 random bytes, sent as standard base64. */
	const SHARE_BYTES = 32;

	/** Halves kept per scope in one session (one per open tab, in practice). */
	const MAX_PER_SCOPE = 8;

	/** A tab id: 16 to 64 lowercase hex characters the browser makes. */
	private static function assertTab(string $tab): void {
		if (!preg_match('/^[0-9a-f]{16,64}$/', $tab)) {
			throw new VaultClientResumeException('A resume needs the tab\'s id.');
		}
	}

	/**
	 * Keep $share_b64 for $scope in this session, replacing any earlier one.
	 * The scope must be client custody and the user must hold its vault.
	 *
	 * @throws VaultClientResumeException
	 */
	public static function put(int $user_id, string $scope, string $tab, string $share_b64): void {
		self::assertHeldScope($user_id, $scope);
		self::assertTab($tab);
		$raw = base64_decode($share_b64, true);
		if ($raw === false || strlen($raw) !== self::SHARE_BYTES) {
			throw new VaultClientResumeException('A resume share is 32 bytes.');
		}
		self::assertSession();
		$kept = $_SESSION[self::SESSION_KEY][$scope] ?? array();
		unset($kept[$tab]);
		$kept[$tab] = array(
			'user_id' => $user_id,
			'share'   => base64_encode($raw),
			'set'     => time(),
		);
		while (count($kept) > self::MAX_PER_SCOPE) {
			array_shift($kept);   // insertion order: the oldest first
		}
		$_SESSION[self::SESSION_KEY][$scope] = $kept;
	}

	/**
	 * This session's share for $scope, with the vault's public keys (current,
	 * and pending during a rotation) so the browser can tell a wrapped secret
	 * that a rotation has retired. Null when there is none, or it belongs to
	 * another user (a session that changed hands through login-as).
	 *
	 * @return array{share:string, public_key:string, pending_public_key:?string}|null
	 * @throws VaultClientResumeException
	 */
	public static function get(int $user_id, string $scope, string $tab): ?array {
		$vault = self::assertHeldScope($user_id, $scope);
		self::assertTab($tab);
		$entry = $_SESSION[self::SESSION_KEY][$scope][$tab] ?? null;
		if (!is_array($entry) || intval($entry['user_id'] ?? 0) !== $user_id || (string)($entry['share'] ?? '') === '') {
			self::drop($scope, $tab);
			return null;
		}
		// A recovery code used since this half was kept ends it, in every
		// session: a used code is a possible theft (specs/one_vault_experience.md
		// § R6, review B6). The stamp lives on the vault rows, so it reaches
		// sessions this request cannot see.
		$recovered = UserEncryptionVault::lastRecoveryTime($user_id);
		if ($recovered !== null && intval($entry['set'] ?? 0) < strtotime($recovered . ' UTC')) {
			self::drop($scope, $tab);
			return null;
		}
		$pending = $vault->get('uev_pending_key_generation') !== null ? (string)$vault->get('uev_pending_public_key') : '';
		return array(
			'share'              => (string)$entry['share'],
			'public_key'         => (string)$vault->get('uev_public_key'),
			'pending_public_key' => $pending !== '' ? $pending : null,
		);
	}

	/**
	 * Forget a tab's share for $scope; every tab's for the scope with a null
	 * tab; every scope's with a null scope.
	 */
	public static function drop(?string $scope = null, ?string $tab = null): void {
		if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION[self::SESSION_KEY])) {
			return;
		}
		if ($scope === null) {
			unset($_SESSION[self::SESSION_KEY]);
			return;
		}
		if ($tab === null) {
			unset($_SESSION[self::SESSION_KEY][$scope]);
			return;
		}
		unset($_SESSION[self::SESSION_KEY][$scope][$tab]);
	}

	/** The scope's vault row, refusing a scope that is not client custody or not held. */
	private static function assertHeldScope(int $user_id, string $scope): UserEncryptionVault {
		if ($user_id <= 0) {
			throw new VaultClientResumeException('Sign in first.');
		}
		try {
			$vault = VaultClientCustody::loadVault($user_id, $scope);
		} catch (VaultClientCustodyException $e) {
			throw new VaultClientResumeException($e->getMessage());
		}
		if ($vault === null) {
			throw new VaultClientResumeException('You have no ' . strtolower(VaultScopes::labelFor($scope)) . '.');
		}
		return $vault;
	}

	private static function assertSession(): void {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			throw new VaultClientResumeException('No session to keep the share in.');
		}
	}
}
