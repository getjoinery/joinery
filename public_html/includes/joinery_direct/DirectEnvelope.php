<?php
/**
 * DirectEnvelope and DirectPart - the typed objects a kind handler works with.
 *
 * Handlers see these, never wire bytes. The envelope exposes named accessors —
 * kind, protocol version, verified sender, recipient and its resolved mailbox,
 * and the manifest — and a part exposes its role, content type, whether it
 * arrived sealed, and a handle to its content. A handler never parses the wire
 * format, so the wire format can move (a new protocol version) without touching
 * any handler.
 *
 * `verifiedSenderDomain()` is the load-bearing one. It is the domain whose
 * capability record the instance signature actually verified against, not
 * whatever the envelope claimed — which is why a contact entry for
 * alice@example.com can only ever be satisfied by a message signed by
 * example.com's instance key, and a spoofed From cannot borrow someone else's
 * place in your contacts.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectEnvelope {

	/** @var array */
	private $data;

	private function __construct(array $data) {
		$this->data = $data;
	}

	/**
	 * @param array $data keys: kind, protocol_version, sender, recipient,
	 *        sender_domain (VERIFIED), key_id, nonce, timestamp, manifest,
	 *        key_generation, is_deferred, recipient_user_id, recipient_alias_id,
	 *        recipient_domain_id
	 */
	public static function fromVerified(array $data): DirectEnvelope {
		return new self($data);
	}

	public function kind(): string {
		return (string)($this->data['kind'] ?? DirectProtocol::KIND_MAIL);
	}

	public function protocolVersion(): int {
		return (int)($this->data['protocol_version'] ?? DirectProtocol::PROTOCOL_VERSION);
	}

	/** The sender address as the envelope stated it, lowercased. */
	public function sender(): string {
		return strtolower((string)($this->data['sender'] ?? ''));
	}

	/**
	 * The domain whose instance signature was verified. A gate must match on
	 * THIS, never on the sender address alone.
	 */
	public function verifiedSenderDomain(): string {
		return strtolower((string)($this->data['sender_domain'] ?? ''));
	}

	/**
	 * True when the sender address actually belongs to the domain that signed —
	 * the condition a gate needs before it can treat the address as identity.
	 */
	public function senderIsAligned(): bool {
		$domain = $this->verifiedSenderDomain();
		return $domain !== '' && DirectProtocol::domainOf($this->sender()) === $domain;
	}

	public function recipient(): string {
		return strtolower((string)($this->data['recipient'] ?? ''));
	}

	public function recipientDomain(): string {
		return DirectProtocol::domainOf($this->recipient());
	}

	/** The user whose consent decides this delivery, or 0 when none resolved. */
	public function recipientUserId(): int {
		return (int)($this->data['recipient_user_id'] ?? 0);
	}

	/** The mailbox (alias) the recipient address resolved to, or 0. */
	public function recipientAliasId(): int {
		return (int)($this->data['recipient_alias_id'] ?? 0);
	}

	public function recipientDomainId(): int {
		return (int)($this->data['recipient_domain_id'] ?? 0);
	}

	public function nonce(): string {
		return (string)($this->data['nonce'] ?? '');
	}

	public function keyId(): string {
		return (string)($this->data['key_id'] ?? '');
	}

	/** The signed timestamp, UTC 'Y-m-d H:i:s'. */
	public function timestamp(): string {
		return (string)($this->data['timestamp'] ?? gmdate('Y-m-d H:i:s'));
	}

	/** The vault key generation the parts were sealed to, 0 when unsealed. */
	public function keyGeneration(): int {
		return (int)($this->data['key_generation'] ?? 0);
	}

	/** True when this delivery was spooled and is being gated at unlock. */
	public function isDeferred(): bool {
		return !empty($this->data['is_deferred']);
	}

	/**
	 * The recipient's in-window vault secret, present only on the deferred path
	 * — which is the only moment a sealed part can be opened, and the reason a
	 * sealed delivery waits for an unlock rather than being ingested at receive.
	 */
	public function vaultSecretKey(): ?string {
		$secret = $this->data['vault_secret_key'] ?? null;
		return ($secret === null || $secret === '') ? null : (string)$secret;
	}

	/** The admitted manifest: one entry per part (role, content_type, filename, size). */
	public function manifest(): array {
		$manifest = $this->data['manifest'] ?? array();
		return is_array($manifest) ? $manifest : array();
	}

	/** The transport tag a kind records so its UI can say "delivered directly". */
	public function transport(): string {
		return 'joinery_direct';
	}

	/** The whole underlying map — for spooling, never for handler logic. */
	public function toArray(): array {
		return $this->data;
	}

	/** A copy with extra keys merged in (the deferred path re-hydrates this way). */
	public function with(array $extra): DirectEnvelope {
		return new self(array_merge($this->data, $extra));
	}
}

/**
 * One delivered part. The bytes are exactly what arrived: sealed by the SENDER
 * to the recipient's vault key when a key was discoverable, plaintext over TLS
 * when it was not.
 *
 * `open()` is the only way to plaintext, and it needs the recipient's in-window
 * vault secret — which is precisely why a sealed delivery cannot be ingested at
 * receive on a locked box and is spooled instead.
 */
class DirectPart {

	private $role;
	private $content_type;
	private $filename;
	private $content_id;
	private $is_inline;
	private $is_sealed;
	private $bytes;
	private $path;
	private $hash;

	public function __construct(array $spec) {
		$this->role         = (string)($spec['role'] ?? DirectProtocol::ROLE_ATTACHMENT);
		$this->content_type = (string)($spec['content_type'] ?? 'application/octet-stream');
		$this->filename     = isset($spec['filename']) && $spec['filename'] !== '' ? (string)$spec['filename'] : null;
		$this->content_id   = isset($spec['content_id']) && $spec['content_id'] !== '' ? (string)$spec['content_id'] : null;
		$this->is_inline    = !empty($spec['is_inline']);
		$this->is_sealed    = !empty($spec['is_sealed']);
		$this->bytes        = array_key_exists('bytes', $spec) ? (string)$spec['bytes'] : null;
		$this->path         = isset($spec['path']) && $spec['path'] !== '' ? (string)$spec['path'] : null;
		$this->hash         = (string)($spec['hash'] ?? '');
	}

	public function role(): string         { return $this->role; }
	public function contentType(): string  { return $this->content_type; }
	public function filename(): ?string    { return $this->filename; }
	public function contentId(): ?string   { return $this->content_id; }
	public function isInline(): bool       { return $this->is_inline; }
	public function isSealed(): bool       { return $this->is_sealed; }
	public function hash(): string         { return $this->hash; }
	public function path(): ?string        { return $this->path; }

	/** The delivered bytes as they arrived (still sealed when isSealed()). */
	public function raw(): string {
		if ($this->bytes !== null) {
			return $this->bytes;
		}
		if ($this->path !== null && is_readable($this->path)) {
			$raw = file_get_contents($this->path);
			return $raw === false ? '' : $raw;
		}
		return '';
	}

	public function size(): int {
		if ($this->bytes !== null) {
			return strlen($this->bytes);
		}
		if ($this->path !== null && is_file($this->path)) {
			return (int)filesize($this->path);
		}
		return 0;
	}

	/**
	 * The plaintext of this part.
	 *
	 * An unsealed part is already plaintext. A sealed one is opened with the
	 * recipient's vault secret key — which only exists inside an unlock window,
	 * which is the whole reason a sealed delivery waits for one.
	 */
	public function open(?string $vault_secret_key): string {
		if (!$this->is_sealed) {
			return $this->raw();
		}
		if ($vault_secret_key === null || $vault_secret_key === '') {
			throw new RuntimeException('A sealed Direct part cannot be opened without the recipient vault secret.');
		}
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		// The named non-arming open: this is mail (or another payload) held in
		// transit, and opening it is delivery arriving late — the same plaintext
		// receive-time ingest holds cold on a Standard box. It is not a read of
		// stored sealed content. A part is sealed raw (SealedBox::sealBinary), so
		// it opens with the matching bulk primitive, not the base64 DEK one.
		return (new VaultCrypto())->openBulkDelivery($this->raw(), $vault_secret_key);
	}

	/** A descriptor of this part for a manifest entry. */
	public function manifestEntry(): array {
		return array(
			'role'         => $this->role,
			'content_type' => $this->content_type,
			'filename'     => (string)$this->filename,
			'content_id'   => (string)$this->content_id,
			'is_inline'    => $this->is_inline,
			'size'         => $this->size(),
		);
	}
}
