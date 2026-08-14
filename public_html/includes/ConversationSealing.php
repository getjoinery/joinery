<?php
/**
 * ConversationSealing — the bytes half of a protected conversation.
 *
 * Message bodies are columns and seal through the ordinary sealed-field path.
 * A photo or a file is not a column: its bytes live in the File system, and
 * sealing them means rewriting the stored blob as a SealedFileContainer under
 * the same conversation key the bodies use. This is the one place that knows
 * how to do that, and the one place that knows how to open it again.
 *
 * The key is never wrapped onto the file row. A conversation has many readers,
 * so its key is wrapped once per participant in ckg_conversation_key_grants and
 * resolved from there — which also means a key rotation touches grants only,
 * and no attachment is ever rewritten.
 *
 * The decrypt hook itself is registered by the messenger plugin's bootstrap,
 * because a deployment without the messenger has no conversation attachments to
 * open. The mechanics live here, in core, alongside the rest of the messaging
 * data layer.
 *
 * @version 1.0.0
 */


class ConversationSealingException extends RuntimeException {}

class ConversationSealing {

	/**
	 * Rewrite a stored file's bytes as a sealed container under a conversation
	 * key, and mark the file Private so every read goes through the decrypt
	 * path.
	 *
	 * Idempotent: a file already sealed is left alone, which is what lets the
	 * raise ceremony be re-run after an interruption.
	 *
	 * @param File   $file the stored attachment
	 * @param string $dek  raw conversation key bytes
	 */
	public static function sealAttachment(File $file, string $dek): void {
		if ($file->is_sealed()) {
			return;
		}

		$plain = $file->read_bytes();
		if ($plain === false || $plain === null) {
			throw new ConversationSealingException(
				'Attachment ' . $file->key . ' has no readable bytes to seal.');
		}

		$plain_size = strlen($plain);
		$container = SealedFileContainer::sealBytes($plain, $dek);

		// The size the member is shown is the plaintext one; the blob now
		// measures the container, which is larger and means nothing to a reader.
		$file->set('fil_protection_level', ProtectionLevel::PRIVATE_);
		$file->set('fil_plain_size_bytes', $plain_size);
		$file->save();

		$file->replace_bytes($container);
	}

	/**
	 * The key that opens one attachment: the key of the conversation it was
	 * sent in, resolved through the grants of whoever is present.
	 *
	 * A messenger attachment is gated on its conversation
	 * (fil_access_provider / fil_access_ref), so the file itself says which
	 * conversation to ask. Null means locked.
	 */
	public static function attachmentKey(File $file): ?string {

		$conversation_id = (int)$file->get('fil_access_ref');
		if ($conversation_id <= 0) {
			return null;
		}
		return ConversationKeyGrant::openConversationKey($conversation_id);
	}
}

/**
 * The streaming decryptor for one sealed conversation attachment.
 *
 * Streaming rather than whole-bytes so a Range request is answered against
 * plaintext offsets — a browser scrubbing a video or an image loader asking for
 * the header alone reads the chunks it needs, not the whole file.
 */
class ConversationAttachmentStream implements FileStreamingDecryptor {

	private $file;
	private $key = null;

	public function __construct(File $file) {
		$this->file = $file;
	}

	public function prepare(string $path): void {
		// Resolved before any response header is written, so a closed window is
		// a clean 423 rather than a truncated body.
		$this->key = ConversationSealing::attachmentKey($this->file);
		if ($this->key === null) {
			throw new VaultLockedException();
		}
	}

	public function plainSize(string $path): int {
		return SealedFileContainer::plainSize($path);
	}

	public function stream(string $path, callable $sink, int $offset = 0, ?int $length = null): int {
		if ($this->key === null) {
			$this->prepare($path);
		}
		return SealedFileContainer::openRange($path, $this->key, $sink, $offset, $length);
	}
}
