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
 * @version 1.2
 */
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));

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

	/** Open a sealed per-item DEK with the in-window vault secret key (the
	 *  matching public key is derived from it — see SealedBox::openDek()). */
	public function openItemDek(string $sealed, string $secret_key): string {
		return $this->box->openDek($sealed, $secret_key);
	}

	/** Seal plaintext content under a (now-open) per-item DEK, bound to the consumer's AD. */
	public function sealField(string $plaintext, string $dek, string $ad): string {
		return $this->box->aeadEncrypt($plaintext, $dek, $ad);
	}

	/**
	 * Open a held-delivery blob: mail sealed IN TRANSIT to the owner's vault
	 * public key so the server could not read it before the owner appeared —
	 * never content that was ingested and stored under the sealed-at-rest
	 * promise. Opening one is first-time delivery arriving late, so it does
	 * NOT arm the hot-turn rule: the plaintext it yields is exactly what
	 * receive-time ingest holds, cold, for the same message on any server
	 * (docs/sealed_vault.md § The hot-turn rule).
	 *
	 * This is the ONLY sanctioned non-arming open of owner-keyed content, and
	 * tests/vault/sealed_read_paths_test.php pins that: a new direct SealedBox
	 * decrypt call anywhere in the tree fails the suite, so a second candidate
	 * has to argue its case against the criterion above in review rather than
	 * quietly joining. Reading anything STORED sealed goes through openField(),
	 * which arms.
	 */
	public function openHeldDeliveryBlob(string $sealed, string $secret_key): string {
		return $this->box->openDek($sealed, $secret_key);
	}

	/** Open content sealed by sealField(). Throws on tamper or an AD mismatch. */
	public function openField(string $blob, string $dek, string $ad): string {
		$plaintext = $this->box->aeadDecrypt($blob, $dek, $ad);
		// This is the one line every server-side read of sealed content passes
		// through — model columns, attachment bytes, raw messages, the search
		// index — so it is where the process becomes hot. From here on the
		// hot-turn rule governs what may be written and sent
		// (specs/implemented/sealed_content_egress.md, Layer 2).
		SealedEgressGuard::markHot($ad);
		return $plaintext;
	}
}
?>
