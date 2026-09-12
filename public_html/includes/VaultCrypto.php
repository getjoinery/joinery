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
 * The three asymmetric opens take a VaultKey (docs/sealed_vault.md § The
 * unlock window) rather than secret bytes: the key object does the
 * crypto_box_seal open, this class does the framing, the memo and the
 * hot-turn accounting. The DEK memo keys on VaultKey::id(), so a row is still
 * unwrapped once per request however many times the window is fetched.
 *
 * @version 1.5 - openItemDek()/openBulkDelivery()/openHeldDeliveryBlob() take a
 *   VaultKey; openItemDeks() opens a whole batch in one VaultKey::unseal()
 *   call (one daemon round trip once the key lives there); the memo keys on
 *   the key's id instead of its bytes
 * @version 1.4
 */
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultKey.php'));

class VaultCrypto {

	const DEK_BYTES = 32;

	/** Cap on the memo below — see openItemDek(). */
	const DEK_MEMO_MAX = 2000;

	/** @var array<string,string> unwrapped DEKs, keyed by key id + blob. Process-lived. */
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
	public function openBulkDelivery(string $sealed, VaultKey $key): string {
		return $key->unseal(array($sealed))[0];
	}

	/** Open a sealed per-item DEK with the in-window vault key.
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
	 *  same key has exactly one answer, and rotation rewrites the blob, so a
	 *  rotated row cannot hit a stale entry. The cache keys on the key's id AND
	 *  the blob, so a blob never opens under a key that did not actually open
	 *  it — a wrong key still reaches unseal() and still throws. */
	public function openItemDek(string $sealed, VaultKey $key): string {
		return $this->openItemDeks(array($sealed), $key)[0];
	}

	/**
	 * Open a batch of sealed per-item DEKs in ONE VaultKey::unseal() call —
	 * the same memo as openItemDek(), filled from one round trip. A caller
	 * that already holds a set of rows (a thread list, a fold batch, a
	 * resealer, a deferred-work drain) uses this so the per-row opens that
	 * follow all hit the memo.
	 *
	 * @param string[] $sealed `v1.seal.` blobs under any keys
	 * @return string[] the DEKs under the same keys
	 * @throws RuntimeException when any blob is malformed or does not open
	 */
	public function openItemDeks(array $sealed, VaultKey $key): array {
		$out = array();
		$pending = array();
		$key_id = $key->id();
		foreach ($sealed as $slot => $blob) {
			$blob = (string)$blob;
			$ck = hash('sha256', $key_id . "\0" . $blob);
			if (isset(self::$dek_memo[$ck])) {
				$out[$slot] = self::$dek_memo[$ck];
				continue;
			}
			$pending[$slot] = array('ck' => $ck, 'raw' => SealedBox::unframeSeal($blob));
		}
		if ($pending) {
			$opened = $key->unseal(array_map(function ($p) { return $p['raw']; }, $pending));
			// Bounded so a bulk export cannot grow this without limit. Dropping the
			// whole map rather than evicting one entry keeps it simple: the reader
			// pages this exists for hold far fewer rows than the cap.
			if (count(self::$dek_memo) + count($opened) > self::DEK_MEMO_MAX) {
				self::$dek_memo = array();
			}
			foreach ($pending as $slot => $p) {
				self::$dek_memo[$p['ck']] = $opened[$slot];
				$out[$slot] = $opened[$slot];
			}
		}
		return $out;
	}

	/**
	 * Drop every memoized DEK. Called when a vault window closes
	 * (VaultUnlock::lock()) so keys unwrapped under a window cannot outlive it.
	 *
	 * Key rotation needs no call here: it rewraps each item under a new public
	 * key, so post-rotation reads present a different blob AND a different
	 * key id, and cannot collide with an entry cached under the old pair.
	 */
	public static function forgetItemDeks(): void {
		self::$dek_memo = array();
	}

	/** Seal plaintext content under a (now-open) per-item DEK, bound to the consumer's AD. */
	public function sealField(string $plaintext, string $dek, string $ad): string {
		return $this->box->aeadEncrypt($plaintext, $dek, $ad);
	}

	/**
	 * Seal a whole FILE under a per-item DEK, path to path, in memory bounded
	 * by a chunk — the streaming sibling of sealField() for content too large
	 * to ever hold as a string (the sealed mailbox search index). Same DEK,
	 * same AD discipline; only the shape of what the DEK encrypts changes
	 * (SealedBox::sealStreamFile, the `v1.stream.` format).
	 */
	public function sealFieldFile(string $src_path, string $dst_path, string $dek, string $ad): void {
		$this->box->sealStreamFile($src_path, $dst_path, $dek, $ad);
	}

	/**
	 * Open a file sealed by sealFieldFile(). Throws on tamper, truncation, or
	 * an AD mismatch. A streaming open of STORED sealed content is a sealed
	 * read like any other, so it arms the hot-turn rule exactly as openField()
	 * does — tests/vault/sealed_read_paths_test.php pins this method as the
	 * one sanctioned caller of SealedBox::openStreamFile.
	 */
	public function openFieldFile(string $src_path, string $dst_path, string $dek, string $ad): void {
		$this->box->openStreamFile($src_path, $dst_path, $dek, $ad);
		SealedEgressGuard::markHot($ad);
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
	public function openHeldDeliveryBlob(string $sealed, VaultKey $key): string {
		return $key->unseal(array(SealedBox::unframeSeal($sealed)))[0];
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
