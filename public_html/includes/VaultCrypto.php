<?php
/**
 * VaultCrypto - the generic per-item DEK dance every Sealed Vault consumer
 * uses to seal its own content (docs/sealed_vault.md).
 *
 * A consumer never seals content directly to the vault keypair. It generates
 * one random per-item DEK, seals the DEK to the vault's public key (cheap,
 * works offline, no size limit on what can later be encrypted under it), and
 * seals the actual content under the DEK with AEAD. Standard envelope
 * encryption — thin over SealedBox, whose job is only the two primitives
 * (crypto_box_seal, AEAD). VaultCrypto's only job is naming that pattern once
 * so every consumer does the same dance the same way.
 *
 * The additional data (AD) passed to sealField()/openField() is entirely the
 * CONSUMER's concern: a stable per-item row-binding string such as
 * `mail:{message_id}:body_plain` or `chat:{message_id}:body`. Binding the AD
 * to the row's own identity means a ciphertext can never be spliced onto a
 * different row and decrypt successfully — VaultCrypto enforces nothing about
 * the AD's shape, it just always requires one.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

class VaultCrypto {

	const DEK_BYTES = 32;

	/** @var SealedBox */
	private $box;

	public function __construct() {
		$this->box = new SealedBox();
	}

	/** A fresh random 32-byte per-item data-encryption key. */
	public function newItemDek(): string {
		return random_bytes(self::DEK_BYTES);
	}

	/** Seal a per-item DEK to the vault's public key — stored on the consumer's own row. */
	public function sealItemDek(string $dek, string $public_key): string {
		return $this->box->sealDek($dek, $public_key);
	}

	/** Open a sealed per-item DEK with the in-window vault secret key. */
	public function openItemDek(string $sealed, string $public_key, string $secret_key): string {
		return $this->box->openDek($sealed, $public_key, $secret_key);
	}

	/** Seal plaintext content under a (now-open) per-item DEK, bound to the consumer's AD. */
	public function sealField(string $plaintext, string $dek, string $ad): string {
		return $this->box->aeadEncrypt($plaintext, $dek, $ad);
	}

	/** Open content sealed by sealField(). Throws on tamper or an AD mismatch. */
	public function openField(string $blob, string $dek, string $ad): string {
		return $this->box->aeadDecrypt($blob, $dek, $ad);
	}
}
?>
