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

	/** The X25519 public half, base64url — what new items seal to and what a rotation re-seals to. */
	public function publicKey(): string;

	/**
	 * Open crypto_box_seal ciphertexts sealed to this key's public half — the
	 * daemon's `unwrap` operation. Always a list, usually of one: a page of
	 * rows or a fold batch is one call.
	 *
	 * @param string[] $sealed raw ciphertexts (any `v1.seal.` framing already
	 *   stripped — see SealedBox::unframeSeal()), keyed however the caller likes
	 * @return string[] the plaintexts under the same keys, in the same order
	 * @throws RuntimeException when any one of them does not open (tampered,
	 *   or sealed to another key); nothing is returned partially
	 */
	public function unseal(array $sealed): array;
}
?>
