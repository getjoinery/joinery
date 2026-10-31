<?php
/**
 * VaultKey - a vault secret key a consumer can USE but never READ
 * (specs/unseal_daemon.md § The PHP seam, docs/sealed_vault.md § The unlock
 * window).
 *
 * VaultUnlock::secretKey() hands one of these out while a member's window is
 * open. Everything a consumer does with a vault key is one of two things: open
 * a per-item DEK that was sealed to the key's public half (through
 * VaultCrypto::openItemDek(), which calls unseal() here), or learn the public
 * half to seal something new. Neither needs the secret bytes in the caller's
 * hands, so the object has no getter for them, and the type is what makes the
 * daemon (WP2-WP4 of the spec) a drop-in: a key held in the pool
 * (PoolVaultKey) and a key held by the unseal daemon behind a handle answer
 * the same three calls.
 *
 * There is deliberately no wrap method (spec B1). Wrapping the secret under a
 * new unlocker happens only inside VaultUnlock::open()/openKey(), in the same
 * request that presented a real unlocker for it — an object that could wrap
 * itself under any KEK would be a key-export door for anything resident in
 * the pool during a window.
 *
 * @version 1.2 - version(): 1 (X25519, opened only until the holder rotates)
 *   or 2 (X-Wing); unseal() opens raw boxes of the key's own version
 * @version 1.1 - unsealEdge(): the browser-format open, for a DEK a browser
 *   sealed to this key (vault-crypto.js, `v1.edgeseal.`)
 * @version 1.0
 */
interface VaultKey {

	/**
	 * A stable, non-secret identity for this key within the process. Two
	 * objects for the same key have the same id, so VaultCrypto's per-item DEK
	 * memo keys on it and a row is still unwrapped once per request however
	 * many times the window is fetched.
	 */
	public function id(): string;

	/** The public half, base64url — what new items seal to and what a rotation re-seals to. */
	public function publicKey(): string;

	/**
	 * SealedBox::V1 (an X25519 key, which nothing seals to any more) or
	 * SealedBox::V2 (X-Wing). A raw box carries no frame, so this is the version
	 * unseal() opens; a caller holding a framed blob checks the two agree
	 * (specs/post_quantum_sealing.md R2).
	 */
	public function version(): int;

	/**
	 * Open raw sealed boxes (SealedBox::sealBinary()'s form for a v2 key, a
	 * crypto_box_seal for a v1 key) sealed to this key's public half — the
	 * daemon's `unwrap` operation. Always a list, usually of one: a page of
	 * rows or a fold batch is one call.
	 *
	 * @param string[] $sealed raw ciphertexts (any `v1.seal.`/`v2.seal.` framing already
	 *   stripped — see SealedBox::unframeSeal()), keyed however the caller likes
	 * @return string[] the plaintexts under the same keys, in the same order
	 * @throws RuntimeException when any one of them does not open (tampered,
	 *   or sealed to another key); nothing is returned partially
	 */
	public function unseal(array $sealed): array;

	/**
	 * Open browser-format ciphertexts sealed to this key's public half — the
	 * v2 seal, or vault-crypto.js's v1 X25519 ECIES (SealedBox openEdge). Same contract
	 * as unseal(): a list in, the plaintexts under the same keys out, nothing
	 * returned partially.
	 *
	 * @param string[] $sealed base64 blobs, the `v1.edgeseal.{scope}.`/`v2.edgeseal.{scope}.` framing
	 *   already stripped (VaultCrypto does that)
	 * @return string[]
	 * @throws RuntimeException when any one of them does not open
	 */
	public function unsealEdge(array $sealed): array;
}
?>
