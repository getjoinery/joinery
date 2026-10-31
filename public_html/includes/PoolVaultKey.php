<?php
/**
 * PoolVaultKey - the VaultKey held in PHP's own memory
 * (specs/unseal_daemon.md § The PHP seam: "PoolVaultKey holds the bytes in
 * PHP and does the same operations locally with SealedBox").
 *
 * This is the path the safe-tier tests run on, and the path on a box where
 * the unseal daemon was never installed. The secret bytes live inside this
 * object and in the session's APCu window slot, and nowhere else: there is no
 * getter, __debugInfo() shows the id only, and the object refuses to be
 * serialized. tests/vault/sealed_read_paths_test.php pins SealedBox's
 * asymmetric-open, key-wrap and keypair primitives to this file, so the raw
 * bytes are unreachable outside it by test rather than by convention.
 *
 * The four things it can do map onto the daemon's four operations:
 * open() is `open` (unwrap-or-mint, then wrap under the presented list),
 * unseal() is `unwrap`, and the two slot methods are what `close`/`status`
 * become when the window store is APCu rather than a process — VaultUnlock is
 * their only caller.
 *
 * A key is v1 (an X25519 secret, opened only, for the rotation off it) or v2
 * (an X-Wing seed; every key minted). Both are 32 bytes, so the version cannot
 * be read off the secret: it travels with it. The form wrapped under an
 * unlocker and stored in the window slot is `v2.` + base64url(seed) for a v2
 * key and the bare base64url secret for a v1 key, which is every wrapping and
 * slot written before v2 existed (specs/post_quantum_sealing.md R2).
 *
 * @version 1.2 - v2 keys: open() mints an X-Wing seed; the wrapped and slot
 *   forms carry the version; version(); unseal() opens with it
 * @version 1.1 - unsealEdge() through SealedBox::openEdge()
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/VaultKey.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

final class PoolVaultKey implements VaultKey {

	/** The prefix that marks a v2 secret in its wrapped and slot forms. */
	const V2_TAG = 'v2.';

	/** @var string base64url secret: an X25519 key (v1) or an X-Wing seed (v2) */
	private $secret;

	/** @var int SealedBox::V1 or SealedBox::V2 */
	private $version;

	/** @var string base64url public key, derived from the secret for its version */
	private $public;

	/** @var string hex digest, see id() */
	private $id;

	/** @param string $stored a secret in its wrapped/slot form (see the class comment) */
	private function __construct(string $stored) {
		if (strncmp($stored, self::V2_TAG, strlen(self::V2_TAG)) === 0) {
			$version = SealedBox::V2;
			$secret_b64 = substr($stored, strlen(self::V2_TAG));
		} else {
			$version = SealedBox::V1;
			$secret_b64 = $stored;
		}
		$raw = SealedBox::b64url_decode($secret_b64);
		if ($raw === false || strlen($raw) !== 32) {
			throw new RuntimeException('PoolVaultKey: malformed secret key.');
		}
		$this->secret = $secret_b64;
		$this->version = $version;
		$this->public = SealedBox::publicKeyFor($secret_b64, $version);
		// One-way, and salted with a fixed label so the digest is never a bare
		// hash of the key material. Deterministic on purpose: every fetch of
		// the same window yields the same id, which is what lets the DEK memo
		// hit across fetches.
		$this->id = hash('sha256', 'joinery:vault-key:' . ($version === SealedBox::V2 ? 'v2:' : '') . $raw);
		sodium_memzero($raw);
	}

	/** The secret in its wrapped/slot form: tagged when v2. */
	private function stored(): string {
		return self::wrappedForm($this->secret, $this->version);
	}

	/**
	 * A base64url secret of a known version in the form a wrapping holds:
	 * `v2.` + the seed for v2, the bare secret for v1. For code that builds a
	 * wrapping around a secret it minted itself (a test fixture); open() makes
	 * every wrapping the platform stores.
	 */
	public static function wrappedForm(string $secret_b64, int $version): string {
		return $version === SealedBox::V2 ? self::V2_TAG . $secret_b64 : $secret_b64;
	}

	/**
	 * The daemon's `open`, done locally. With an unlocker (`wrapped`, `kek`,
	 * `ad` — the row's AEAD wrapping, the KEK the presented credential
	 * derived, and UserEncryptionWrapping::adFor() of that row) the secret is
	 * unwrapped; with null a fresh keypair is minted. Either way the secret is
	 * then wrapped under every entry of $wrap_under (`kek`, `ad`) and those
	 * wrappings are handed back beside the key. A minted key is v2; an
	 * unwrapped one is whatever version its wrapping says. This is the ONLY place a
	 * wrapping is produced (spec B1): the caller presented a real unlocker, or
	 * is first-time setup minting the key.
	 *
	 * @param ?array $unlocker  ['wrapped' => string, 'kek' => string, 'ad' => string], or null to mint
	 * @param array  $wrap_under list of ['kek' => string, 'ad' => string]
	 * @return array{key: PoolVaultKey, wrappings: string[]} wrappings under the
	 *   same keys as $wrap_under, in order
	 * @throws RuntimeException when the unlocker does not open the wrapping
	 */
	public static function open(?array $unlocker, array $wrap_under = array()): array {
		$box = new SealedBox();
		if ($unlocker === null) {
			$keypair = $box->generateKeypair();
			$key = new self(self::V2_TAG . $keypair['secret']);
			sodium_memzero($keypair['secret']);
		} else {
			$secret = $box->unwrapKey((string)$unlocker['wrapped'], (string)$unlocker['kek'], (string)$unlocker['ad']);
			$key = new self($secret);
			sodium_memzero($secret);
		}
		$wrappings = array();
		foreach ($wrap_under as $slot => $entry) {
			$wrappings[$slot] = $box->wrapKey($key->stored(), (string)$entry['kek'], (string)$entry['ad']);
		}
		return array('key' => $key, 'wrappings' => $wrappings);
	}

	public function id(): string {
		return $this->id;
	}

	public function publicKey(): string {
		return $this->public;
	}

	public function version(): int {
		return $this->version;
	}

	public function unseal(array $sealed): array {
		$box = new SealedBox();
		$out = array();
		foreach ($sealed as $slot => $ciphertext) {
			$out[$slot] = $box->openBinary((string)$ciphertext, $this->secret, $this->version);
		}
		return $out;
	}

	public function unsealEdge(array $sealed): array {
		$box = new SealedBox();
		$out = array();
		foreach ($sealed as $slot => $blob) {
			$out[$slot] = $box->openEdge((string)$blob, $this->secret, $this->public);
		}
		return $out;
	}

	// ---- The APCu window slot. VaultUnlock is the only caller of these two. ----

	/** The key a session's window slot holds, or null when the slot is empty. */
	public static function fromSlot(string $apcu_key): ?PoolVaultKey {
		$value = apcu_fetch($apcu_key, $success);
		if (!$success || !is_string($value) || $value === '') {
			return null;
		}
		return new self($value);
	}

	/** Put (or re-put, extending the TTL) this key into a session's window slot. */
	public function storeInSlot(string $apcu_key, int $ttl_seconds): void {
		apcu_store($apcu_key, $this->stored(), $ttl_seconds);
	}

	/** A dump shows the id, never the bytes. */
	public function __debugInfo(): array {
		return array('id' => $this->id, 'version' => $this->version, 'public' => $this->public);
	}

	/** A key is never a value to persist or hand across a boundary. */
	public function __serialize(): array {
		throw new RuntimeException('PoolVaultKey cannot be serialized.');
	}

	public function __unserialize(array $data): void {
		throw new RuntimeException('PoolVaultKey cannot be unserialized.');
	}

	public function __destruct() {
		if (is_string($this->secret)) {
			sodium_memzero($this->secret);
		}
	}
}
?>
