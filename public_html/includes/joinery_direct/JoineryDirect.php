<?php
/**
 * JoineryDirect - the send side of the Joinery Direct channel. One call, every
 * kind.
 *
 *   $result = JoineryDirect::send($recipient_address, $kind, $parts, $options);
 *
 * The client owns the whole shared layer — capability lookup, the SSRF-guarded
 * connection, preflight, sealing, transfer — and returns a TYPED RESULT, never a
 * behavior. What a result MEANS belongs to the calling kind: mail's transport
 * adapter maps everything short of `delivered` to the SMTP path; the messenger
 * maps the same results to "not reachable". The SMTP fallback lives only in the
 * mail adapter, so no other kind's failure ever produces an SMTP send.
 *
 * Three rules the client holds to, each for a specific reason:
 *
 *   - **The recipient key is never cached.** Vault rotation is a re-wrap
 *     migration, so a message sealed to generation N and arriving after the
 *     vault moved to N+1 has nobody left who can open it. A cached key is a bet
 *     that the recipient will not rotate inside the TTL, and losing that bet
 *     delivers a permanently unreadable message — strictly worse than never
 *     sealing it. The key rides the `accept` that is about to carry the message,
 *     so it cannot be stale.
 *
 *   - **The SRV target is hostile input.** The recipient domain, and therefore
 *     the host and port, is chosen by whoever controls that domain. Every
 *     connection goes through SafeHttpClient: private/reserved addresses
 *     blocked, connection pinned to a validated public IP, port restricted to
 *     443 or >= 1024, TLS verified against the SRV hostname, redirects never
 *     followed.
 *
 *   - **Per-user permission is never cached and never queryable.** The sender
 *     reads only the per-DOMAIN capability record and lets the receiver answer
 *     live. If the sender could look up "am I allowed to send Direct to bob@you"
 *     that would be an oracle leaking the recipient's contact and block lists.
 *
 * @version 1.2
 * @changelog 1.2 - send() verifies the SENDER domain's own DNS publication before the wire — both halves of the handshake are checked, not just the recipient's
 */

require_once(PathHelper::getIncludePath('includes/SafeHttpClient.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectCapability.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

/**
 * What a send did. Four outcomes and no behavior — the caller's kind policy
 * decides what each one means.
 */
class DirectSendResult {

	/** Accepted and transferred. */
	const DELIVERED     = 'delivered';
	/** The receiver answered `declined`: this recipient does not accept this kind from this sender. */
	const DECLINED      = 'declined';
	/** The recipient domain publishes no capability record. */
	const NO_CAPABILITY = 'no_capability';
	/**
	 * The caller required sealing and the recipient published no key to seal
	 * to. Refused between preflight and transfer, so no content byte crossed
	 * the wire. Final for as long as the far side has no vault — retrying asks
	 * the same instance the same question.
	 */
	const NO_SEALING    = 'no_sealing';
	/** Connection, timeout, or verification failure at either step. */
	const FAILED        = 'failed';

	/** @var string one of the constants above */
	public $status;
	/** @var bool whether the parts were sealed to the recipient's key */
	public $sealed = false;
	/** @var int the key generation sealed to; 0 when unsealed */
	public $key_generation = 0;
	/** @var string diagnostic detail for the operator surface; never shown to a sender's user */
	public $detail = '';

	public function __construct(string $status, array $extra = array()) {
		$this->status = $status;
		$this->sealed = !empty($extra['sealed']);
		$this->key_generation = (int)($extra['key_generation'] ?? 0);
		$this->detail = (string)($extra['detail'] ?? '');
	}

	public function delivered(): bool { return $this->status === self::DELIVERED; }
}

class JoineryDirect {

	/**
	 * @var callable|null fn(): ?object — an egress transport with
	 *      post(string $url, string $body, string $content_type): ?array
	 *
	 * A relay-fronted deployment hides its box's address, so its Direct requests
	 * must LEAVE from the relay rather than from the box: if the box opened the
	 * connection, the recipient's instance would see the address the relay
	 * exists to conceal. The box still signs — the relay only transports.
	 *
	 * Registered by the mailbox plugin at bootstrap so core never names a plugin
	 * symbol. With nothing registered, or with no relay fronting this
	 * deployment, requests go out from the box directly, which is the ordinary
	 * case.
	 */
	private static $egress_resolver = null;

	/** Register the relay egress resolver. Called once, from the plugin bootstrap. */
	public static function registerEgress(callable $fn): void {
		self::$egress_resolver = $fn;
	}

	/** The egress transport for this deployment, or null to send from the box. */
	private static function egress() {
		if (self::$egress_resolver === null) {
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			VaultUnlock::loadConsumerBootstraps();
		}
		if (self::$egress_resolver === null) {
			return null;
		}
		try {
			return call_user_func(self::$egress_resolver);
		} catch (\Throwable $e) {
			error_log('JoineryDirect: egress resolver failed: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Send one payload to one recipient.
	 *
	 * @param string $recipient_address user@domain — the pipe addresses PEOPLE,
	 *        not machines. Machine-to-machine traffic belongs on FleetClient.
	 * @param string $kind              'mail', 'chat', or any registered kind.
	 * @param array  $parts             part descriptors: role, content_type,
	 *        filename/content_id for attachments, and content as either `bytes`
	 *        or a `path` — so a large attachment never has to sit in memory as a
	 *        string just to be sent.
	 * @param array  $options           'timeout' overrides the request timeout;
	 *        'sender' names the From address when it is not the site default;
	 *        'require_sealed' refuses (NO_SEALING) instead of transferring when
	 *        the preflight returns no recipient key — for callers whose policy
	 *        forbids the opportunistic plaintext-over-TLS fallback.
	 */
	public static function send(string $recipient_address, string $kind, array $parts, array $options = array()): DirectSendResult {
		$recipient_address = strtolower(trim($recipient_address));
		$kind = strtolower(trim($kind)) ?: DirectProtocol::KIND_MAIL;

		if (!DirectSettings::enabled()) {
			return new DirectSendResult(DirectSendResult::NO_CAPABILITY, array('detail' => 'Direct is off on this deployment.'));
		}
		$recipient_domain = DirectProtocol::domainOf($recipient_address);
		if ($recipient_domain === '' || empty($parts)) {
			return new DirectSendResult(DirectSendResult::FAILED, array('detail' => 'Malformed recipient or empty payload.'));
		}

		$sender = strtolower(trim((string)($options['sender'] ?? '')));
		$sender_domain = DirectProtocol::domainOf($sender);
		if ($sender_domain === '' || !DirectSigningIdentity::hasIdentity($sender_domain)) {
			// Nothing to sign with is not a failure of the recipient's — this
			// deployment simply cannot speak Direct as this identity.
			return new DirectSendResult(DirectSendResult::NO_CAPABILITY,
				array('detail' => 'No Direct signing identity for ' . ($sender_domain ?: 'the sender')));
		}

		// A same-instance address never needs the wire. The loopback path exists
		// for the test estate; real same-instance mail is delivered locally.
		if (!empty($options['loopback'])) {
			return self::sendLoopback($recipient_address, $sender, $kind, $parts, $options);
		}

		// Our own half of the DNS handshake. The recipient verifies our
		// signature against the key OUR domain publishes, so a send from an
		// unpublished domain can only be refused on the far end — checked here
		// with the same lookup and cache, aimed at ourselves, instead of
		// trusting the operator to have published before enabling.
		if (DirectCapability::publicKeyFor($sender_domain, DirectSigningIdentity::keyIdFor($sender_domain)) === null) {
			return new DirectSendResult(DirectSendResult::NO_CAPABILITY,
				array('detail' => 'The Joinery Direct DNS records for ' . $sender_domain . ' are not published.'));
		}

		// 1. Discovery. No record → no_capability, and the caller falls back.
		$capability = DirectCapability::lookup($recipient_domain);
		if ($capability === null) {
			return new DirectSendResult(DirectSendResult::NO_CAPABILITY,
				array('detail' => 'No capability record for ' . $recipient_domain));
		}

		$endpoint = 'https://' . $capability['host'] . ':' . $capability['port'] . DirectProtocol::ENDPOINT_PATH;

		// One transport for all three steps, chosen once. A relay-fronted
		// deployment sends through the relay so the recipient never sees the
		// box's address; everyone else connects directly through the SSRF-safe
		// client. The steps below cannot tell which they got, which is what
		// keeps the Fortress path from being a second implementation.
		$transport = self::egress();
		$client = null;
		if ($transport === null) {
			$client = new SafeHttpClient(array(
				'allowed_ports'      => SafeHttpClient::directPortPolicy(),
				'allow_redirects'    => false,
				'connect_timeout'    => DirectSettings::connectTimeout(),
				'timeout'            => (int)($options['timeout'] ?? DirectSettings::requestTimeout()),
				'max_response_bytes' => 65536,
				'user_agent'         => 'Joinery/Direct',
			));
		}
		$post = function (string $url, string $body, string $content_type) use ($client, $transport): ?array {
			if ($transport !== null) {
				return $transport->post($url, $body, $content_type);
			}
			try {
				$response = $client->post($url, $body, array('Content-Type' => $content_type));
			} catch (\Throwable $e) {
				return null;
			}
			return array('status' => $response->status, 'body' => $response->body);
		};

		try {
			$descriptors = self::normalizeParts($parts);
		} catch (\Throwable $e) {
			return self::failed($e->getMessage());
		}
		$manifest = array();
		foreach ($descriptors as $descriptor) {
			$manifest[] = $descriptor['manifest'];
		}

		// 2. Preflight: envelope and manifest only, no content. A retry after any
		//    failure is a newly signed envelope with its own timestamp and nonce.
		$envelope = array(
			'protocol_version' => DirectProtocol::PROTOCOL_VERSION,
			'kind'      => $kind,
			'sender'    => $sender,
			'recipient' => $recipient_address,
			// The key id is part of what the signature covers, so it is resolved
			// before the bytes to sign are built rather than after.
			'key_id'    => DirectSigningIdentity::keyIdFor($sender_domain),
			'nonce'     => DirectProtocol::newNonce(),
			'timestamp' => gmdate('Y-m-d H:i:s'),
		);

		try {
			$signed = DirectSigningIdentity::sign($sender_domain,
				DirectProtocol::preflightSigningBytes($envelope, $manifest));
		} catch (\Throwable $e) {
			return self::failed('preflight signing failed: ' . $e->getMessage());
		}

		// A refusal from the recipient, a WAF, a proxy and a dead host are all
		// indistinguishable here and all mean the same thing, so every failure
		// mode converges on one behaviour instead of a decision tree.
		$response = $post($endpoint . '?step=preflight',
			json_encode(array('envelope' => $envelope, 'manifest' => $manifest, 'signature' => $signed['signature'])),
			'application/json');
		if ($response === null) {
			return self::failed('preflight transport failed');
		}
		$answer = json_decode((string)$response['body'], true);
		if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($answer)) {
			return self::failed('preflight refused with HTTP ' . $response['status']);
		}
		if (($answer['answer'] ?? '') === DirectProtocol::ANSWER_DECLINED) {
			return new DirectSendResult(DirectSendResult::DECLINED);
		}
		if (($answer['answer'] ?? '') !== DirectProtocol::ANSWER_ACCEPT) {
			return self::failed('preflight answered nothing usable');
		}

		// 3. Seal to the key that just arrived — never to a cached one.
		$recipient_key = (string)($answer['key'] ?? '');
		$key_generation = (int)($answer['key_generation'] ?? 0);
		$sealed = ($recipient_key !== '');
		if (!$sealed && !empty($options['require_sealed'])) {
			return new DirectSendResult(DirectSendResult::NO_SEALING,
				array('detail' => $recipient_domain . ' published no key to seal to'));
		}

		// 4. Transfer each part as its own request, then commit with a signature
		//    over the ordered hashes of the SEALED bytes, bound to the nonce.
		$hashes = array();
		try {
			foreach ($descriptors as $index => $descriptor) {
				$bytes = self::materialize($descriptor);
				if ($sealed) {
					$bytes = self::seal($bytes, $recipient_key);
				}
				$hashes[] = DirectProtocol::hashBytes($bytes);

				$part_response = $post(
					$endpoint . '?step=part&nonce=' . urlencode($envelope['nonce']) . '&index=' . $index,
					$bytes, 'application/octet-stream');
				unset($bytes);
				if ($part_response === null) {
					return self::failed('part ' . $index . ' transport failed');
				}
				if ($part_response['status'] < 200 || $part_response['status'] >= 300) {
					return self::failed('part ' . $index . ' refused with HTTP ' . $part_response['status']);
				}
			}
		} catch (\Throwable $e) {
			return self::failed('part transfer: ' . $e->getMessage());
		}

		try {
			$commit_signature = DirectSigningIdentity::sign($sender_domain,
				DirectProtocol::transferSigningBytes($envelope['nonce'], $hashes));
		} catch (\Throwable $e) {
			return self::failed('transfer signing failed: ' . $e->getMessage());
		}

		$commit = $post($endpoint . '?step=commit',
			json_encode(array(
				'nonce'          => $envelope['nonce'],
				'hashes'         => $hashes,
				'sealed'         => $sealed,
				'key_generation' => $key_generation,
				'signature'      => $commit_signature['signature'],
			)), 'application/json');
		if ($commit === null) {
			return self::failed('commit transport failed');
		}
		if ($commit['status'] < 200 || $commit['status'] >= 300) {
			return self::failed('commit refused with HTTP ' . $commit['status']);
		}

		return new DirectSendResult(DirectSendResult::DELIVERED, array(
			'sealed'         => $sealed,
			'key_generation' => $sealed ? $key_generation : 0,
		));
	}

	/**
	 * Deliver to an address served by THIS instance, running the full receive
	 * framework locally — registry lookup, session, gate, hash verification,
	 * ingest — with no DNS record and no network.
	 *
	 * This is a test-tier tool, not a delivery path: real same-instance mail
	 * never needs Direct. It exists so a plugin's handler is exercisable from
	 * plugins/{plugin}/tests/ like any other code, and so the same handler code
	 * that runs here runs unmodified on the real wire.
	 */
	private static function sendLoopback(string $recipient, string $sender, string $kind, array $parts, array $options): DirectSendResult {
		require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectReceiver.php'));

		$sender_domain = DirectProtocol::domainOf($sender);
		try {
			$descriptors = self::normalizeParts($parts);
		} catch (\Throwable $e) {
			return self::failed($e->getMessage());
		}
		$manifest = array();
		foreach ($descriptors as $descriptor) {
			$manifest[] = $descriptor['manifest'];
		}

		$envelope = array(
			'protocol_version' => DirectProtocol::PROTOCOL_VERSION,
			'kind'      => $kind,
			'sender'    => $sender,
			'recipient' => $recipient,
			'key_id'    => '',
			'nonce'     => DirectProtocol::newNonce(),
			'timestamp' => gmdate('Y-m-d H:i:s'),
		);

		$receiver = new DirectReceiver();
		$answer = $receiver->preflight($envelope, $manifest, array('verified_domain' => $sender_domain));
		if ($answer['answer'] === DirectProtocol::ANSWER_DECLINED) {
			return new DirectSendResult(DirectSendResult::DECLINED);
		}
		if ($answer['answer'] !== DirectProtocol::ANSWER_ACCEPT) {
			return self::failed('loopback preflight: ' . (string)($answer['error'] ?? 'refused'));
		}

		$recipient_key = (string)($answer['key'] ?? '');
		$sealed = ($recipient_key !== '');
		if (!$sealed && !empty($options['require_sealed'])) {
			return new DirectSendResult(DirectSendResult::NO_SEALING,
				array('detail' => 'loopback recipient published no key to seal to'));
		}
		$hashes = array();
		foreach ($descriptors as $index => $descriptor) {
			$bytes = self::materialize($descriptor);
			if ($sealed) {
				$bytes = self::seal($bytes, $recipient_key);
			}
			$hashes[] = DirectProtocol::hashBytes($bytes);
			if (!$receiver->acceptPart($envelope['nonce'], $index, $bytes)) {
				return self::failed('loopback part ' . $index . ' refused');
			}
		}

		$committed = $receiver->commit($envelope['nonce'], $hashes, $sealed,
			(int)($answer['key_generation'] ?? 0), array('verified_domain' => $sender_domain));
		if (!$committed) {
			return self::failed('loopback commit refused');
		}

		return new DirectSendResult(DirectSendResult::DELIVERED, array(
			'sealed'         => $sealed,
			'key_generation' => $sealed ? (int)($answer['key_generation'] ?? 0) : 0,
		));
	}

	/**
	 * Turn caller descriptors into a normalized list plus their manifest
	 * entries.
	 *
	 * A payload past this instance's own caps is refused here rather than at the
	 * far end: the two ends run the same default caps, so spending a round trip
	 * to be told what we already know costs the recipient a preflight for
	 * nothing.
	 *
	 * @throws RuntimeException when the payload cannot be sent at all
	 */
	private static function normalizeParts(array $parts): array {
		if (count($parts) > DirectSettings::maxParts()) {
			throw new RuntimeException('Payload declares ' . count($parts) . ' parts, over the '
				. DirectSettings::maxParts() . '-part cap.');
		}
		$out = array();
		$total = 0;
		foreach (array_values($parts) as $part) {
			$role = (string)($part['role'] ?? DirectProtocol::ROLE_ATTACHMENT);
			if (!in_array($role, DirectProtocol::ROLES, true)) {
				$role = DirectProtocol::ROLE_ATTACHMENT;
			}
			$path  = isset($part['path']) && $part['path'] !== '' ? (string)$part['path'] : null;
			$bytes = array_key_exists('bytes', $part) ? (string)$part['bytes'] : null;
			$size  = $path !== null ? (int)@filesize($path) : strlen((string)$bytes);

			if ($size > DirectSettings::maxBytesPerPart()) {
				throw new RuntimeException('Part is ' . $size . ' bytes, over the per-part cap.');
			}
			$total += $size;
			if ($total > DirectSettings::maxTotalBytes()) {
				throw new RuntimeException('Payload is over the total-bytes cap.');
			}

			$out[] = array(
				'path'  => $path,
				'bytes' => $bytes,
				'manifest' => array(
					'role'         => $role,
					'content_type' => (string)($part['content_type'] ?? 'application/octet-stream'),
					'filename'     => (string)($part['filename'] ?? ''),
					'content_id'   => (string)($part['content_id'] ?? ''),
					'is_inline'    => !empty($part['is_inline']),
					'size'         => $size,
				),
			);
		}
		return $out;
	}

	/** One part's bytes, read from wherever the caller put them. */
	private static function materialize(array $descriptor): string {
		if ($descriptor['bytes'] !== null) {
			return $descriptor['bytes'];
		}
		$raw = ($descriptor['path'] !== null) ? @file_get_contents($descriptor['path']) : false;
		if ($raw === false) {
			throw new RuntimeException('Cannot read part content.');
		}
		return $raw;
	}

	/**
	 * Seal one part to the recipient's vault public key.
	 *
	 * crypto_box_seal is a one-shot primitive, so peak memory scales with the
	 * LARGEST SINGLE PART, never the whole message — the ceiling the per-part
	 * size cap already enforces. Sealing each part separately to the same key is
	 * also what keeps the message's structure intact through encryption, where
	 * PGP/MIME would flatten the whole tree into one opaque object.
	 */
	private static function seal(string $bytes, string $recipient_public_key): string {
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		// Raw binary, not the base64 DEK form — a part is bulk payload of any size
		// and must not carry a third of its bytes again in encoding overhead.
		return (new VaultCrypto())->sealBulkDelivery($bytes, $recipient_public_key);
	}

	/** Every failure looks the same to the caller; the detail is for the operator. */
	private static function failed(string $detail): DirectSendResult {
		RequestLogger::log(DirectProtocol::LOG_FEATURE, 'send_failed', false, array('note' => $detail));
		return new DirectSendResult(DirectSendResult::FAILED, array('detail' => $detail));
	}
}
