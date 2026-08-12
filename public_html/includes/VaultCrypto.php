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
 * @version 1.3
 */
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));

class VaultCrypto {

	const DEK_BYTES = 32;

	/** Cap on the memo below — see openItemDek(). */
	const DEK_MEMO_MAX = 2000;

	/** @var array<string,string> unwrapped DEKs, keyed by secret+blob. Process-lived. */
	private static $dek_memo = array();

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

	/**
	 * Seal a BULK Joinery Direct part to the recipient's vault public key — raw
	 * bytes, no base64 wrapping (see SealedBox::sealBinary). A part is arbitrary
	 * payload of any size, so it must not pay the DEK format's 33% inflation and
	 * extra in-memory copy.
	 */
	public function sealBulkDelivery(string $bytes, string $public_key): string {
		return $this->box->sealBinary($bytes, $public_key);
	}

	/**
	 * Open a bulk held-delivery part sealed by sealBulkDelivery(). Non-arming for
	 * the same reason openHeldDeliveryBlob() is: it is a Direct delivery arriving
	 * late, the same plaintext receive-time ingest holds cold on a Standard box —
	 * NOT a read of content stored under the sealed-at-rest promise.
	 */
	public function openBulkDelivery(string $sealed, string $secret_key): string {
		return $this->box->openBinary($sealed, $secret_key);
	}

	/** Open a sealed per-item DEK with the in-window vault secret key (the
	 *  matching public key is derived from it — see SealedBox::openDek()).
	 *
	 *  Memoized for the life of the process, because a row's wrapped key is
	 *  opened once per SEALED COLUMN and always yields the same DEK. A mail row
	 *  carries five (sender, subject, body_plain, body_html, ai_summary) and a
	 *  chat row several more, so reading a 50-thread list ran ~240 identical
	 *  X25519 unseals to read ~58 rows. Unwrapping is per row now; opening
	 *  content stays per column, so openField() — where the hot-turn rule arms —
	 *  fires exactly as often as it did.
	 *
	 *  Safe to memoize because this is a pure function: the same blob under the
	 *  same secret has exactly one answer, and rotation rewrites the blob, so a
	 *  rotated row cannot hit a stale entry. The cache keys on both inputs, so a
	 *  blob never opens under a secret that did not actually open it — a wrong
	 *  secret still reaches openDek() and still throws. */
	public function openItemDek(string $sealed, string $secret_key): string {
		$ck = hash('sha256', $secret_key . "\0" . $sealed);
		if (isset(self::$dek_memo[$ck])) {
			return self::$dek_memo[$ck];
		}
		$dek = $this->box->openDek($sealed, $secret_key);
		// Bounded so a bulk export cannot grow this without limit. Dropping the
		// whole map rather than evicting one entry keeps it simple: the reader
		// pages this exists for hold far fewer rows than the cap.
		if (count(self::$dek_memo) >= self::DEK_MEMO_MAX) {
			self::$dek_memo = array();
		}
		self::$dek_memo[$ck] = $dek;
		return $dek;
	}

	/**
	 * Drop every memoized DEK. Called when a vault window closes
	 * (VaultUnlock::lock()) so keys unwrapped under a window cannot outlive it.
	 *
	 * Key rotation needs no call here: it rewraps each item under a new public
	 * key, so post-rotation reads present a different blob AND a different
	 * secret, and cannot collide with an entry cached under the old pair.
	 */
	public static function forgetItemDeks(): void {
		self::$dek_memo = array();
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
