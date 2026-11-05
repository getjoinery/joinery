<?php
/**
 * FileServeGrant — a short-lived, server-held decryption grant riding a signed
 * file URL (specs/bugfix_sealed_inline_images.md).
 *
 * A signed /uploads URL (docs/file_signed_urls.md) authorizes the FETCH on its
 * own, which is what lets mail bodies render inside a sandbox="" iframe whose
 * opaque origin sends no cookies. But a SEALED file also needs DECRYPTING, and
 * the unlock window is keyed to the browser session — a cookie-less
 * request can never present it, so a sealed inline image served that way was a
 * structural 423. This class closes the gap the way the signed URL itself
 * works: minting is the authorization statement. Code that is already
 * in-window when it mints a signed URL for a sealed file ALSO stashes that
 * file's content key server-side under a random token, and the serve path
 * redeems the token cookie-less.
 *
 * Custody: the key material lives in RamStore under LIFETIME, beside the
 * unlock windows themselves (the same custody class, ended by the same PHP-FPM
 * restart), base64-encoded since the store holds JSON. It is never written to
 * the database and never appears in the URL — the token is a lookup handle.
 * Where the store refuses the write, no grant is minted and the URL serves 423. The
 * grant is scoped to ONE file + ONE size key, lives exactly as long as the
 * signed URL beside it, and is multi-use within that lifetime because a
 * browser may fetch an <img> several times.
 *
 * Two hard rules:
 *  - A grant is consulted only AFTER the URL signature verified. It can never
 *    widen who may fetch bytes — only whether sealed bytes decrypt.
 *  - Minting resolves the key through the consumer's own in-window path, so a
 *    closed window mints nothing and the URL serves 423 exactly as before.
 *    Client-custody content has no server-resolvable key and can never gain a
 *    grant.
 *
 * @version 1.2 - grants live in RamStore (LIFETIME), not APCu; no per-process fallback: a store that
 *                refuses mints nothing (strategy/object_free_window_store.md WP3)
 * @version 1.1 - grants are read through ApcuStore::fetch() (unseal_daemon.md C4)
 * @version 1.0
 */

class FileServeGrant {

	/** Content key of a self-sealed container File (DriveSealed::fileKey). */
	const SHAPE_FILE_KEY = 'file_key';
	/** Per-message DEK for an attachment sealed under the owning message
	 *  (InboundEmailMessage::openSealedAttachment's legacy shape). */
	const SHAPE_MESSAGE_DEK = 'message_dek';

	/** Ceiling on a caller-supplied TTL; a grant is transport, never storage. */
	const TTL_MAX = 86400;

	/** @var array|null The one grant this request redeemed (file_id, size_key, shape, key, expires). */
	private static $active = null;

	/**
	 * Stash $key for one file + size key and return the URL token, or null when
	 * the grant cannot be stored. Callers mint ONLY from code that resolved
	 * $key through the owner's open window — that resolution, not this call,
	 * is the authorization.
	 */
	public static function mint(int $file_id, string $size_key, string $shape, string $key, int $ttl_seconds): ?string {
		if ($file_id <= 0 || $key === '' || $ttl_seconds <= 0) {
			return null;
		}
		$ttl_seconds = min((int)$ttl_seconds, self::TTL_MAX);
		$token = bin2hex(random_bytes(16));
		$value = array(
			'file_id'  => $file_id,
			'size_key' => $size_key,
			'shape'    => $shape,
			'key'      => base64_encode($key),
			// Expiry travels in the value too, so the check never rests on the
			// store's own clock alone.
			'expires'  => time() + $ttl_seconds,
		);
		if (!RamStore::set(self::storeKey($token), $value, $ttl_seconds, RamStore::LIFETIME)) {
			return null;
		}
		return $token;
	}

	/**
	 * Redeem a token for this request. True only for a live token minted for
	 * exactly this file + size key; the material is then readable through
	 * activeKey() for the rest of the request. False redeems leave no state —
	 * the serve path falls through to the vault-window behavior it always had.
	 *
	 * One grant per request: the /uploads route serves one file and redeems at
	 * most once.
	 */
	public static function redeemAndActivate(int $file_id, string $size_key, $token): bool {
		if (!is_string($token) || !preg_match('/^[0-9a-f]{32}$/', $token)) {
			return false;
		}
		$value = RamStore::get(self::storeKey($token), $ok, RamStore::LIFETIME);
		if (!$ok || !is_array($value) || intval($value['expires'] ?? 0) < time()) {
			return false;
		}
		if (intval($value['file_id'] ?? 0) !== $file_id || (string)($value['size_key'] ?? '') !== $size_key) {
			return false;
		}
		$key = base64_decode((string)($value['key'] ?? ''), true);
		if ($key === false || $key === '') {
			return false;
		}
		$value['key'] = $key;
		self::$active = $value;
		return true;
	}

	/**
	 * The redeemed key for this file, or null. Consulted by the sealed serve
	 * paths (DriveSealedStream::prepare, InboundEmailMessage::
	 * openSealedAttachment) BEFORE they reach for the vault window, so a
	 * granted request decrypts and everything else behaves exactly as before.
	 */
	public static function activeKey(int $file_id, string $shape): ?string {
		if (self::$active === null) {
			return null;
		}
		if (intval(self::$active['file_id']) !== $file_id || (string)self::$active['shape'] !== $shape) {
			return null;
		}
		if (intval(self::$active['expires']) < time()) {
			return null;
		}
		return (string)self::$active['key'];
	}

	/** Drop this request's redeemed grant (tests; long-running processes). */
	public static function deactivate(): void {
		self::$active = null;
	}

	private static function storeKey(string $token): string {
		return 'fsg.' . $token;
	}
}
