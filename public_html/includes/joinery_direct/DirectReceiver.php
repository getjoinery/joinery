<?php
/**
 * DirectReceiver - the receive framework: everything that happens identically
 * for every kind, so a handler is left with only a decision and a store.
 *
 * The wire discipline this class enforces is the whole security argument of the
 * channel, and it holds for every kind because handlers cannot touch the wire:
 *
 *   - **Exactly two gate answers exist**, `accept` and `declined`. A refusal for
 *     any other reason — bad signature, stale timestamp, replayed nonce,
 *     unimplemented protocol version, unserved kind, oversized manifest, full
 *     spool — is a REQUEST-LEVEL refusal, an HTTP status in its own
 *     indistinguishable bucket. That separation is what stops the protocol from
 *     becoming an oracle for the recipient's contact or block list.
 *
 *   - **The sealed tiers accept unconditionally**, locked or unlocked, and
 *     return a key for every address, real or not. A Private box that answered
 *     live while unlocked and deferred while locked would turn its own answer
 *     into the lock-state oracle the design exists to close, so Private and
 *     Fortress share one posture and differ only in topology.
 *
 *   - **Nothing is ever bounced.** A rejection at unlock is a local filing
 *     decision. Returning mail to forged senders is backscatter, and delivery
 *     feedback is how attackers enumerate valid addresses and probe filters.
 *
 * A delivery is two requests. The preflight is answered before any content
 * exists, and the answer opens a single-use delivery session holding the
 * admitted manifest. Parts then arrive one request each — so no single request
 * carries the whole message, and the ceiling is the largest part rather than the
 * sum — and the commit redeems the session once, verifying the sender's
 * signature over the ordered hashes of the SEALED bytes before anything is
 * ingested.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectCapability.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectHandler.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectRecipients.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectDecoyKeys.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectContactGate.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSpoolService.php'));
require_once(PathHelper::getIncludePath('data/direct_nonces_class.php'));
require_once(PathHelper::getIncludePath('data/direct_sessions_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_parts_class.php'));
require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

class DirectReceiver {

	/**
	 * Answer a preflight.
	 *
	 * @param array $context 'verified_domain' short-circuits signature
	 *        verification for the loopback path, which has no wire to
	 *        authenticate; every other caller leaves it unset.
	 * @return array{answer:string,key?:string,key_generation?:int,session_ttl?:int,error?:string,status?:int}
	 *         `answer` is 'accept', 'declined', or 'refused' — the last being the
	 *         request-level bucket, which the endpoint renders as an HTTP status.
	 */
	public function preflight(array $envelope, array $manifest, array $context = array()): array {
		if (!DirectSettings::enabled()) {
			return self::refuse(404, 'Direct is not served here');
		}

		// Protocol version, before anything else reads a field whose meaning it
		// defines. A version we do not implement is refused cleanly and the
		// sender's behaviour on it is identical to any other failure.
		$version = (int)($envelope['protocol_version'] ?? 0);
		if (!in_array($version, DirectProtocol::SUPPORTED_VERSIONS, true)) {
			return self::refuse(400, 'Unsupported protocol version');
		}

		$kind = strtolower(trim(DirectProtocol::kindOrDefault($envelope['kind'] ?? '')));
		$sender = strtolower(trim((string)($envelope['sender'] ?? '')));
		$recipient = strtolower(trim((string)($envelope['recipient'] ?? '')));
		$nonce = (string)($envelope['nonce'] ?? '');
		$key_id = (string)($envelope['key_id'] ?? '');
		$timestamp = (string)($envelope['timestamp'] ?? '');
		$sender_domain = DirectProtocol::domainOf($sender);

		// The nonce is a hex token of at most 64 chars (the replay column's width),
		// exactly as the relay's claimNonce enforces. A non-hex or oversized nonce
		// is not one a Joinery sender mints, and letting it through would let the DB
		// truncate two distinct nonces to the same row — refuse it here so PHP and
		// the Go relay reject the identical set.
		if ($sender_domain === '' || $recipient === '' || $nonce === ''
				|| strlen($nonce) > 64 || !ctype_xdigit($nonce)) {
			return self::refuse(400, 'Malformed envelope');
		}

		// 1. Verify the instance signature. Stateless crypto — no vault needed, so
		//    this runs at receive even on a locked box, which is what lets
		//    authentication happen now and authorization defer to unlock.
		$verified_domain = (string)($context['verified_domain'] ?? '');
		if ($verified_domain === '') {
			// Peer-keyed limit FIRST: the sender domain is attacker-chosen and
			// resolving it is outbound DNS driven by unauthenticated input.
			$public_key = DirectCapability::publicKeyFor($sender_domain, $key_id, true);
			if ($public_key === null) {
				return self::refuse(403, 'No capability record or key id for the sending domain');
			}
			$signature = (string)($context['signature'] ?? '');
			$signed_bytes = DirectProtocol::preflightSigningBytes($envelope, $manifest);
			if (!DirectSigningIdentity::verify($signed_bytes, $signature, $public_key)) {
				return self::refuse(403, 'Invalid instance signature');
			}
			$verified_domain = $sender_domain;
		}

		// 2. Per-instance rate limit, on the identity the signature established —
		//    so one instance cannot flood this box no matter which of its
		//    addresses it aims at. Not a blocked-sender lookup: an individual
		//    sender is never dropped early, because that would need a gate-time
		//    block index readable while locked.
		if (!self::withinInstanceRate($verified_domain)) {
			return self::refuse(429, 'Too many preflights from this instance');
		}
		RequestLogger::log(DirectProtocol::LOG_FEATURE, 'preflight:' . $verified_domain, true);

		// 3. Freshness and replay, both inside what the signature covers.
		$age_error = self::freshnessError($timestamp);
		if ($age_error !== null) {
			return self::refuse(400, $age_error);
		}
		if (!DirectNonce::claim($nonce)) {
			return self::refuse(409, 'Replayed nonce');
		}

		// 4. Manifest bounds. Instance configuration, applied identically to every
		//    recipient, every kind and every tier — so refusing on them discloses
		//    nothing about the recipient.
		$bounds_error = self::manifestBoundsError($manifest);
		if ($bounds_error !== null) {
			return self::refuse(413, $bounds_error);
		}

		// 5. Kind dispatch. A kind this instance does not serve — including one
		//    whose plugin is deactivated — refuses before any handler code runs.
		if (!DirectKinds::isServed($kind)) {
			return self::refuse(404, 'Kind not served here');
		}

		// 6. Recipient resolution. "We do not host this domain" is a fact about
		//    the deployment, not about a recipient, so it is request-level.
		$resolved = DirectRecipients::resolve($recipient);
		if ($resolved === null) {
			return self::refuse(404, 'Recipient domain is not hosted here');
		}

		$declared_bytes = self::declaredBytes($manifest);
		$sealed_tier = $resolved['seals_content'];

		if ($sealed_tier) {
			// Every sealed-tier accept — real or decoy — leans on the decoy secret
			// being derivable, because the sender must not be able to tell a decoy
			// accept from a real one. If a stored secret is present but unreadable,
			// refuse the WHOLE tier uniformly here rather than accepting reals while
			// the nonexistent branch alone errors deriving its decoy — that error
			// would itself be the existence tell.
			try {
				DirectDecoyKeys::warm();
			} catch (\Throwable $e) {
				error_log('DirectReceiver: sealed-tier decoy secret unavailable: ' . $e->getMessage());
				return self::refuse(503, 'Temporarily unable to accept');
			}

			// The spool caps are the byte-denominated bound on accept-before-judgment.
			// Safe to signal — instance-level state, the same answer for every
			// address — and the per-address cap charges decoy deliveries too, so a
			// full spool refuses identically whether the address exists or not.
			$cap_error = DirectSpoolService::capRefusal($resolved, $recipient, $declared_bytes);
			if ($cap_error !== null) {
				return self::refuse(507, $cap_error);
			}
		}

		// 7. Disposition. The gate runs live only at Standard. At the sealed tiers
		//    the framework accepts unconditionally so the wire answer signals
		//    neither existence nor encryption status; WHEN the gate then runs is a
		//    per-mailbox, server-side decision the sender cannot observe:
		//      - an ENCRYPTED mailbox defers to the owner's unlock (its contact
		//        list is sealed and unreadable until then);
		//      - an UNENCRYPTED mailbox (a group alias, or an owner with no vault)
		//        has a plaintext book, so it is gated at COMMIT — never held for an
		//        unlock that will not come.
		$is_decoy = false;
		$defer = false;
		$gate_at_commit = false;
		if (!$sealed_tier) {
			if (!$resolved['exists'] || !$this->runGate($kind, $envelope, $verified_domain, $resolved)) {
				// Stranger, removed contact, blocked sender, and an address that
				// does not exist are ONE answer. A block removes the contact, so a
				// blocked sender already fails the contact check — there is no
				// separate block branch and no gate-time block lookup.
				return array('answer' => DirectProtocol::ANSWER_DECLINED);
			}
			// No key at Standard: a Standard mailbox is server-readable end to end,
			// so the parts cross under TLS and land plaintext, which is the state
			// this mailbox stores them in anyway. Ingest runs live at commit.
			$key = '';
			$key_generation = 0;
		} elseif (!$resolved['exists']) {
			// An address that does not exist gets a key too, or a real key coming
			// back would tell a prober which addresses are real. It is
			// indistinguishable from a real one; the sender seals to it, the
			// delivery arrives, and nobody can ever open it — which costs nothing,
			// because mail to a nonexistent address was going nowhere anyway.
			$key = DirectDecoyKeys::publicKeyFor($recipient);
			$key_generation = DirectDecoyKeys::DECOY_GENERATION;
			$is_decoy = true;
		} elseif ($resolved['vault_public_key'] !== null) {
			// An ENCRYPTED mailbox: seal to its vault and defer the gate to that
			// owner's unlock, the only moment its sealed contact list is readable.
			$key = (string)$resolved['vault_public_key'];
			$key_generation = (int)$resolved['key_generation'];
			$defer = true;
		} else {
			// An UNENCRYPTED mailbox on a sealing domain — a group alias with no
			// single vault, or an owner who holds none. Its address book is
			// plaintext, so there is nothing to wait for: it accepts uniformly like
			// every sealed-tier address (the parts cross under TLS unsealed) and its
			// gate runs at commit against the readable book, never deferred to an
			// unlock that will never come. Handing back a decoy instead would
			// conceal one more bit but permanently destroy real mail; the residual
			// is a keyless accept, the same posture opportunistic sealing takes
			// anywhere the recipient's key is not discoverable.
			$key = '';
			$key_generation = 0;
			$gate_at_commit = true;
		}

		// 8. Accept: open the single-use delivery session and its staging row.
		$ttl = DirectSettings::sessionTtlSeconds();
		try {
			$session = new DirectSession(NULL);
			$session->set('jds_nonce', $nonce);
			$session->set('jds_kind', $kind);
			$session->set('jds_protocol_version', $version);
			$session->set('jds_sender_address', $sender);
			$session->set('jds_sender_domain', $verified_domain);
			$session->set('jds_sender_key_id', $key_id);
			$session->set('jds_recipient', $recipient);
			$session->set('jds_manifest', json_encode(DirectProtocol::canonicalManifest($manifest)));
			$session->set('jds_total_bytes', $declared_bytes);
			$session->set('jds_key_generation', $key_generation);
			$session->set('jds_is_deferred', $defer);
			$session->set('jds_gate_at_commit', $gate_at_commit);
			$session->set('jds_is_decoy', $is_decoy);
			$session->set('jds_state', DirectSession::STATE_OPEN);
			$session->set('jds_expires_time', gmdate('Y-m-d H:i:s', time() + $ttl));
			$session->save();

			DirectSpoolService::openStaging($session, $resolved);
		} catch (\Throwable $e) {
			error_log('DirectReceiver: could not open a delivery session: ' . $e->getMessage());
			return self::refuse(503, 'Temporary failure opening a delivery session');
		}

		$answer = array(
			'answer'      => DirectProtocol::ANSWER_ACCEPT,
			'session_ttl' => $ttl,
		);
		if ($key !== '') {
			$answer['key'] = $key;
			$answer['key_generation'] = $key_generation;
		}
		return $answer;
	}

	/**
	 * Take one part of an accepted delivery.
	 *
	 * The receiver ALWAYS takes the full transfer and never signals, per part,
	 * that it already holds the content. Skip-if-held would be an oracle pointed
	 * at the recipient: a sender watching which parts get skipped learns exactly
	 * which files that recipient possesses, and can probe for a specific one by
	 * offering it.
	 */
	public function acceptPart(string $nonce, int $index, string $bytes): bool {
		$session = self::liveSession($nonce);
		if ($session === null) {
			return false;
		}
		$manifest = $session->manifest();
		if (!isset($manifest[$index])) {
			return false; // not in the admitted manifest
		}
		// The admitted manifest is the transfer-time contract: a delivered part
		// that exceeds its declared size aborts the delivery.
		//
		// The manifest declares PLAINTEXT sizes — it is written before the
		// recipient's key exists — so when this receiver offered a key the
		// ceiling is the sealed size of that declaration, not the declaration
		// itself. Without that allowance every honest sealed delivery would be
		// aborted for arriving exactly as it was asked to.
		// A non-zero key generation on the session is exactly "this receiver
		// offered a key", which is the same thing as "the sender was asked to
		// seal" — a real vault reports 1 or more and a decoy reports 1, while a
		// tier that offers no key leaves it 0.
		$ceiling = (int)$manifest[$index]['size'];
		if ((int)$session->get('jds_key_generation') > 0) {
			$ceiling = DirectProtocol::sealedSizeCeiling($ceiling);
		}
		if (strlen($bytes) > $ceiling) {
			self::burn($session);
			return false;
		}
		try {
			DirectSpoolService::storePart($session, $index, $manifest[$index], $bytes);
		} catch (\Throwable $e) {
			error_log('DirectReceiver: could not store part ' . $index . ': ' . $e->getMessage());
			self::burn($session);
			return false;
		}
		return true;
	}

	/**
	 * Redeem the session: verify the sender's signature over the ordered hashes
	 * of the sealed bytes, check every delivered part against them, and then
	 * either ingest (Standard, gate already passed) or leave the delivery held
	 * for the recipient's next unlock (Private/Fortress).
	 *
	 * Hashing the CIPHERTEXT is what makes this checkable without unsealing, so
	 * a locked box rejects a substituted part at receive rather than discovering
	 * it at unlock — which matters precisely because the relay, the untrusted
	 * machine, is the one forwarding those bytes.
	 */
	public function commit(string $nonce, array $hashes, bool $sealed, int $key_generation, array $context = array()): bool {
		// Redeeming is the claim: a captured transfer replayed against a consumed,
		// expired or unknown session is refused here.
		$session = DirectSession::redeem($nonce);
		if ($session === null) {
			return false;
		}

		$verified_domain = (string)($context['verified_domain'] ?? '');
		if ($verified_domain === '') {
			$public_key = DirectCapability::publicKeyFor(
				(string)$session->get('jds_sender_domain'), (string)$session->get('jds_sender_key_id'), true);
			$signature = (string)($context['signature'] ?? '');
			if ($public_key === null || !DirectSigningIdentity::verify(
					DirectProtocol::transferSigningBytes($nonce, $hashes), $signature, $public_key)) {
				DirectSpoolService::discard($session);
				return false;
			}
		} elseif ($verified_domain !== (string)$session->get('jds_sender_domain')) {
			DirectSpoolService::discard($session);
			return false;
		}

		$manifest = $session->manifest();
		if (count($hashes) !== count($manifest)) {
			DirectSpoolService::discard($session);
			return false;
		}

		// Every part, every hash. A mismatch rejects the ENTIRE message — nothing
		// partial is kept and the verified-direct mark is never applied — because
		// the parts arrive under an anonymous seal anyone holding the recipient's
		// public key could construct, so without this an in-path element could
		// substitute wholesale and the receiver would decrypt attacker-chosen
		// bytes cleanly and stamp them verified.
		if (!DirectSpoolService::verifyHashes($session, $hashes)) {
			DirectSpoolService::discard($session);
			return false;
		}

		try {
			DirectSpoolService::finish($session, $sealed, $key_generation);
		} catch (\Throwable $e) {
			error_log('DirectReceiver: commit failed for nonce ' . $nonce . ': ' . $e->getMessage());
			DirectSpoolService::discard($session);
			return false;
		}
		return true;
	}

	// ── the gate ─────────────────────────────────────────────────────────────

	/**
	 * Run the kind's authorization. A kind declaring `"gate": "contacts"` gets
	 * the framework's canned contact gate and never sees a handler `gate` call
	 * at all — the best-reviewed gate is the easiest one to reach for.
	 */
	private function runGate(string $kind, array $envelope, string $verified_domain, array $resolved): bool {
		$typed = DirectEnvelope::fromVerified(array(
			'kind'              => $kind,
			'protocol_version'  => (int)($envelope['protocol_version'] ?? DirectProtocol::PROTOCOL_VERSION),
			'sender'            => strtolower((string)($envelope['sender'] ?? '')),
			'sender_domain'     => $verified_domain,
			'recipient'         => strtolower((string)($envelope['recipient'] ?? '')),
			'key_id'            => (string)($envelope['key_id'] ?? ''),
			'nonce'             => (string)($envelope['nonce'] ?? ''),
			'timestamp'         => (string)($envelope['timestamp'] ?? ''),
			'recipient_user_id' => $resolved['user_id'],
			'recipient_alias_id'=> $resolved['alias_id'],
			'recipient_domain_id' => $resolved['domain_id'],
		));

		if (DirectKinds::usesContactGate($kind)) {
			return DirectContactGate::allows($typed);
		}
		$handler = DirectKinds::handler($kind);
		if ($handler === null) {
			return false;
		}
		try {
			return $handler->gate($typed);
		} catch (\Throwable $e) {
			// A handler that throws declines. It never gets to produce a third
			// answer, and a broken kind must not become a distinguishable one.
			error_log('DirectReceiver: gate for kind ' . $kind . ' threw: ' . $e->getMessage());
			return false;
		}
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	/** The open, unexpired session for a nonce, or null. */
	private static function liveSession(string $nonce): ?DirectSession {
		$multi = new MultiDirectSession(array(
			'nonce' => $nonce, 'state' => DirectSession::STATE_OPEN, 'unexpired' => true));
		$multi->load();
		foreach ($multi as $session) {
			return $session;
		}
		return null;
	}

	/** A terminal failure consumes the session, exactly as completion does. */
	private static function burn(DirectSession $session): void {
		try {
			$session->set('jds_state', DirectSession::STATE_CONSUMED);
			$session->save();
			DirectSpoolService::discard($session);
		} catch (\Throwable $e) {
			error_log('DirectReceiver: could not burn session ' . $session->key . ': ' . $e->getMessage());
		}
	}

	/** Null when the timestamp is inside the window; the reason otherwise. */
	private static function freshnessError(string $timestamp): ?string {
		$parsed = strtotime($timestamp . ' UTC');
		if ($parsed === false) {
			return 'Unparseable timestamp';
		}
		$age = time() - $parsed;
		if ($age > DirectProtocol::MAX_AGE_SECONDS) {
			return 'Stale envelope';
		}
		if ($age < -DirectProtocol::MAX_FUTURE_SECONDS) {
			return 'Envelope timestamped in the future';
		}
		return null;
	}

	/** Null when the manifest is inside the declared caps; the reason otherwise. */
	private static function manifestBoundsError(array $manifest): ?string {
		if (empty($manifest)) {
			return 'Empty manifest';
		}
		if (count($manifest) > DirectSettings::maxParts()) {
			return 'Too many parts';
		}
		$total = 0;
		foreach ($manifest as $part) {
			$size = (int)($part['size'] ?? 0);
			if ($size < 0 || $size > DirectSettings::maxBytesPerPart()) {
				return 'Part exceeds the per-part byte cap';
			}
			$total += $size;
		}
		if ($total > DirectSettings::maxTotalBytes()) {
			return 'Message exceeds the total byte cap';
		}
		return null;
	}

	private static function declaredBytes(array $manifest): int {
		$total = 0;
		foreach ($manifest as $part) {
			$total += max(0, (int)($part['size'] ?? 0));
		}
		return $total;
	}

	/**
	 * Preflights from one verified sending instance inside the rolling window.
	 *
	 * The platform's existing sliding-window idiom, counted over Direct's own
	 * request log exactly as the mailbox forwarding limiters count the inbound
	 * email log — not a new engine.
	 */
	private static function withinInstanceRate(string $sender_domain): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare("SELECT COUNT(*) FROM rql_request_logs
			WHERE rql_feature = ? AND rql_action = ?
			  AND rql_create_time > now() - (interval '1 second' * ?)");
		$stmt->execute(array(DirectProtocol::LOG_FEATURE, 'preflight:' . $sender_domain,
			DirectSettings::preflightWindowSeconds()));
		return intval($stmt->fetchColumn()) < DirectSettings::preflightLimit();
	}

	/**
	 * A request-level refusal: the indistinguishable bucket, never one of the
	 * gate's two answers. Counted so the operator can see it — silence is a wire
	 * posture toward senders, never toward the operator.
	 */
	private static function refuse(int $status, string $reason): array {
		RequestLogger::log(DirectProtocol::LOG_FEATURE, 'refused', false,
			array('status_code' => $status, 'error_type' => 'request_refused', 'note' => $reason));
		return array('answer' => 'refused', 'status' => $status, 'error' => $reason);
	}
}
