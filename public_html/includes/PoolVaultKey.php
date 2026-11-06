<?php
/**
 * PoolVaultKey - the VaultKey held in PHP's own memory
 * (specs/unseal_daemon.md § The PHP seam: "PoolVaultKey holds the bytes in
 * PHP and does the same operations locally with SealedBox").
 *
 * This is the path the safe-tier tests run on, and the path on a box where
 * the unseal daemon was never installed. The secret bytes live inside this
 * object and in the session's window slot (a RamStore entry bound to the
 * PHP-FPM master's lifetime), and nowhere else: there is no
 * getter, __debugInfo() shows the id only, and the object refuses to be
 * serialized. tests/vault/sealed_read_paths_test.php pins SealedBox's
 * asymmetric-open, key-wrap and keypair primitives to this file, so the raw
 * bytes are unreachable outside it by test rather than by convention.
 *
 * The four things it can do map onto the daemon's four operations:
 * open() is `open` (unwrap-or-mint, then wrap under the presented list),
 * unseal() is `unwrap`, and the two slot methods are what `close`/`status`
 * become when the window store is RamStore rather than a process — VaultUnlock
 * is their only caller.
 *
 * The key is an X-Wing seed. The form wrapped under an unlocker and stored in
 * the window slot is `v2.` + base64url(seed); anything else (an untagged
 * X25519 secret included) is refused as malformed.
 *
 * @version 1.6 - open() takes a reveal list (chain KEKs, the previous-key scope then dek) and
 *   answers `revealed`; openPrevious(); slotValue()/fromSlotValue() so VaultUnlock reads a slot once
 * @version 1.5 - the window slot is a RamStore entry (strategy/object_free_window_store.md): JSON
 *   cannot hold an object, so nothing planted in the slot runs when it is read; storeInSlot()
 *   reports a store that refused
 * @version 1.4 - the pool-held key is read through ApcuStore::fetch() (unseal_daemon.md C4)
 * @version 1.3 - X-Wing only: the untagged (X25519) form is refused; wrappedForm() takes no version
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

	/** @var string base64url X-Wing seed */
	private $secret;

	/** @var string base64url public key, derived from the seed */
	private $public;

	/** @var string hex digest, see id() */
	private $id;

	/** @param string $stored a secret in its wrapped/slot form (see the class comment) */
	private function __construct(string $stored) {
		if (strncmp($stored, self::V2_TAG, strlen(self::V2_TAG)) !== 0) {
			throw new RuntimeException('PoolVaultKey: malformed secret key.');
		}
		$secret_b64 = substr($stored, strlen(self::V2_TAG));
		$raw = SealedBox::b64url_decode($secret_b64);
		if ($raw === false || strlen($raw) !== 32) {
			throw new RuntimeException('PoolVaultKey: malformed secret key.');
		}
		$this->secret = $secret_b64;
		$this->public = SealedBox::publicKeyFor($secret_b64);
		// One-way, and salted with a fixed label so the digest is never a bare
		// hash of the key material. Deterministic on purpose: every fetch of
		// the same window yields the same id, which is what lets the DEK memo
		// hit across fetches.
		$this->id = hash('sha256', 'joinery:vault-key:v2:' . $raw);
		sodium_memzero($raw);
	}

	/** The secret in its wrapped/slot form. */
	private function stored(): string {
		return self::wrappedForm($this->secret);
	}

	/**
	 * A base64url seed in the form a wrapping holds: `v2.` + the seed. For
	 * code that builds a wrapping around a seed it minted itself (a test
	 * fixture); open() makes every wrapping the platform stores.
	 */
	public static function wrappedForm(string $secret_b64): string {
		return self::V2_TAG . $secret_b64;
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
	 * $reveal is a list of sealed chain KEKs (`v2.seal.` blobs), only with an
	 * unlocker: each comes back under `revealed` as ['kek' => ?string,
	 * 'scope' => 'previous-key'|'dek'|''], the daemon's reveal list.
	 *
	 * @return array{key: PoolVaultKey, wrappings: string[], revealed: array} wrappings under the
	 *   same keys as $wrap_under, in order
	 * @throws RuntimeException when the unlocker does not open the wrapping
	 */
	public static function open(?array $unlocker, array $wrap_under = array(), array $reveal = array()): array {
		if ($reveal && $unlocker === null) {
			throw new RuntimeException('PoolVaultKey: a chain KEK is revealed only to a presented unlocker.');
		}
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
		$revealed = array();
		foreach ($reveal as $slot => $sealed) {
			$revealed[$slot] = $key->openChainKek((string)$sealed);
		}
		return array('key' => $key, 'wrappings' => $wrappings, 'revealed' => $revealed);
	}

	/**
	 * A sealed chain KEK opened with this key: its own scope first, then the
	 * content scope a chain from before the split was sealed under, saying
	 * which (the daemon's openChainKEK). ['kek' => null, 'scope' => ''] when
	 * neither opens it.
	 */
	private function openChainKek(string $sealed): array {
		try {
			[, $raw] = SealedBox::unframeSeal($sealed);
		} catch (RuntimeException $e) {
			return array('kek' => null, 'scope' => '');
		}
		$seed = SealedBox::b64url_decode($this->secret);
		try {
			foreach (array(SealedBox::SCOPE_PREVIOUS_KEY, SealedBox::SCOPE_DEK) as $scope) {
				try {
					return array('kek' => SealedBox::openV2($raw, $seed, $scope), 'scope' => $scope);
				} catch (RuntimeException $e) {
					continue;
				}
			}
		} finally {
			sodium_memzero($seed);
		}
		return array('kek' => null, 'scope' => '');
	}

	public function openPrevious(string $sealed_kek, string $wrapped, string $ad): VaultKey {
		$opened = $this->openChainKek($sealed_kek);
		if ($opened['kek'] === null) {
			throw new RuntimeException('PoolVaultKey: the chain entry is not sealed to this key.');
		}
		try {
			return self::open(array('wrapped' => $wrapped, 'kek' => $opened['kek'], 'ad' => $ad))['key'];
		} finally {
			sodium_memzero($opened['kek']);
		}
	}

	public function id(): string {
		return $this->id;
	}

	public function publicKey(): string {
		return $this->public;
	}

	public function version(): int {
		return SealedBox::V2;
	}

	public function unseal(array $sealed): array {
		$box = new SealedBox();
		$out = array();
		foreach ($sealed as $slot => $ciphertext) {
			$out[$slot] = $box->openBinary((string)$ciphertext, $this->secret);
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

	// ---- The window slot. VaultUnlock is the only caller of these two. ----

	/**
	 * The key a session's window slot holds, or null when the slot is empty.
	 * A slot holding anything but a key in its slot form reads as empty.
	 */
	public static function fromSlot(string $slot): ?PoolVaultKey {
		$value = RamStore::get($slot, $success, RamStore::LIFETIME);
		if (!$success) {
			return null;
		}
		$key = is_string($value) ? self::fromSlotValue($value) : null;
		if ($key === null) {
			RamStore::delete($slot, RamStore::LIFETIME);
		}
		return $key;
	}

	/** The key a slot value holds, or null when it is not a pool key in its slot form. */
	public static function fromSlotValue(string $value): ?PoolVaultKey {
		try {
			return new self($value);
		} catch (RuntimeException $e) {
			return null;
		}
	}

	/** The slot value: the secret in its slot form. */
	public function slotValue(): string {
		return $this->stored();
	}

	/**
	 * Put this key into a session's window slot for $ttl_seconds. Written once
	 * per window: extending it is RamStore::touch(), which rewrites nothing.
	 * False when the store refused (no store on this host): the window is not open.
	 */
	public function storeInSlot(string $slot, int $ttl_seconds): bool {
		return RamStore::set($slot, $this->stored(), $ttl_seconds, RamStore::LIFETIME);
	}

	/** A dump shows the id, never the bytes. */
	public function __debugInfo(): array {
		return array('id' => $this->id, 'version' => SealedBox::V2, 'public' => $this->public);
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
