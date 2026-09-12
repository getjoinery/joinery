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
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/VaultKey.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

final class PoolVaultKey implements VaultKey {

	/** @var string base64url X25519 secret */
	private $secret;

	/** @var string base64url X25519 public, derived from the secret */
	private $public;

	/** @var string hex digest, see id() */
	private $id;

	private function __construct(string $secret_b64) {
		$raw = SealedBox::b64url_decode($secret_b64);
		if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
			throw new RuntimeException('PoolVaultKey: malformed secret key.');
		}
		$this->secret = $secret_b64;
		$this->public = SealedBox::b64url(sodium_crypto_box_publickey_from_secretkey($raw));
		// One-way, and salted with a fixed label so the digest is never a bare
		// hash of the key material. Deterministic on purpose: every fetch of
		// the same window yields the same id, which is what lets the DEK memo
		// hit across fetches.
		$this->id = hash('sha256', 'joinery:vault-key:' . $raw);
		sodium_memzero($raw);
	}

	/**
	 * The daemon's `open`, done locally. With an unlocker (`wrapped`, `kek`,
	 * `ad` — the row's AEAD wrapping, the KEK the presented credential
	 * derived, and UserEncryptionWrapping::adFor() of that row) the secret is
	 * unwrapped; with null a fresh keypair is minted. Either way the secret is
	 * then wrapped under every entry of $wrap_under (`kek`, `ad`) and those
	 * wrappings are handed back beside the key. This is the ONLY place a
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
			$key = new self($keypair['secret']);
			sodium_memzero($keypair['secret']);
		} else {
			$secret = $box->unwrapKey((string)$unlocker['wrapped'], (string)$unlocker['kek'], (string)$unlocker['ad']);
			$key = new self($secret);
			sodium_memzero($secret);
		}
		$wrappings = array();
		foreach ($wrap_under as $slot => $entry) {
			$wrappings[$slot] = $box->wrapKey($key->secret, (string)$entry['kek'], (string)$entry['ad']);
		}
		return array('key' => $key, 'wrappings' => $wrappings);
	}

	public function id(): string {
		return $this->id;
	}

	public function publicKey(): string {
		return $this->public;
	}

	public function unseal(array $sealed): array {
		$box = new SealedBox();
		$out = array();
		foreach ($sealed as $slot => $ciphertext) {
			$out[$slot] = $box->openBinary((string)$ciphertext, $this->secret);
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
		apcu_store($apcu_key, $this->secret, $ttl_seconds);
	}

	/** A dump shows the id, never the bytes. */
	public function __debugInfo(): array {
		return array('id' => $this->id, 'public' => $this->public);
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
