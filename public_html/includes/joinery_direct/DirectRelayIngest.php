<?php
/**
 * DirectRelayIngest - a delivery the relay accepted, landing on the box.
 *
 * At Fortress the wire terminates at the relay, so the box never sees a
 * preflight. What it sees is a container the relay wrote to the tenant's spool
 * and the pull brought across: the signed envelope, the sender's own signatures,
 * and the parts exactly as they arrived — sealed by the sender, so nothing between
 * the two endpoints ever held plaintext.
 *
 * The box does not take the relay's word for who signed. The relay is untrusted
 * with content, so its edge verification is not load-bearing here: the sender's
 * preflight and transfer signatures travel in the container, and this class
 * RE-VERIFIES them against the sender domain's own DNS-published key before it
 * stores anything — the box, not the relay, establishes the verified sender. A
 * compromised relay holds no signing key, so it can neither forge that identity
 * nor swap a part without breaking a signature it cannot reproduce. Authentication
 * runs here (stateless crypto, no vault); authorization still defers to the
 * recipient's unlock, by joining the path that already exists: the delivery is
 * written into the Direct spool exactly as a locally-accepted sealed-tier delivery
 * is, and the ordinary unlock drain gates it and hands it to the kind's ingest, so
 * the no-bounce, held-plugin and decline-is-a-local-disposition rules live in one
 * place.
 *
 * @version 1.1 - relayed delivery to an unencrypted mailbox is gated at commit and
 *                ingested, not held for an unlock that never comes; recipient
 *                alias/domain identity now rides the row for the gate
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectRecipients.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSpoolService.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectCapability.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_parts_class.php'));

class DirectRelayIngest {

	/**
	 * Store one relayed delivery.
	 *
	 * Returns the pull consumer's own vocabulary so the caller needs no special
	 * case: 'pending' (held for the recipient's unlock — the normal answer),
	 * 'dedup' (already stored, a re-pull), 'hold' (recoverable: the recipient or
	 * the kind is not resolvable yet, so leave it on the relay), or 'unroutable'
	 * (genuinely undeliverable, ack and drop with a loud log).
	 */
	public static function store(array $container): string {
		$recipient = strtolower(trim((string)($container['recipient'] ?? '')));
		$kind = strtolower(trim((string)($container['kind'] ?? DirectProtocol::KIND_MAIL)));
		$nonce = (string)($container['nonce'] ?? '');
		if ($recipient === '' || $nonce === '') {
			return 'unroutable';
		}

		// A re-pull of an un-acked-but-stored delivery is a no-op, keyed on the
		// nonce the relay recorded — the same value that made the delivery
		// single-use on the wire.
		$existing = new MultiDirectSpool(array('nonce' => $nonce));
		$existing->load();
		if (count($existing) > 0) {
			return 'dedup';
		}

		// A kind this box no longer serves is HELD, not dropped: the plugin may
		// come back, and nothing is ever returned to the sender either way.
		if (!DirectKinds::isServed($kind)) {
			return 'hold';
		}

		$resolved = DirectRecipients::resolve($recipient);
		if ($resolved === null || !$resolved['exists']) {
			// The domain is not configured here yet, or the mailbox has gone. Both
			// are recoverable — leave it on the relay rather than losing it.
			return 'hold';
		}

		// Re-authenticate independently of the relay. The sender domain is DERIVED
		// from the signed envelope, never read from the relay's assertion, and both
		// of the sender's signatures are checked against that domain's own key.
		$sender = strtolower(trim((string)($container['sender'] ?? '')));
		$sender_domain = DirectProtocol::domainOf($sender);
		$key_id = (string)($container['key_id'] ?? '');
		$parts = is_array($container['parts'] ?? null) ? $container['parts'] : array();

		$verified = self::verifySender($container, $sender, $sender_domain, $key_id, $kind, $recipient, $nonce, $parts);
		if ($verified === 'hold') {
			// The sender's key could not be resolved right now — likely transient
			// DNS. Recoverable, so it stays on the relay for the next pull.
			return 'hold';
		}
		if ($verified !== 'ok') {
			// A signature that does not verify is not a delivery this box will store,
			// no matter which relay handed it over. Dropped with a loud log.
			error_log('DirectRelayIngest: relayed delivery failed signature re-verification'
				. ' (nonce ' . $nonce . ', sender ' . $sender_domain . '): ' . $verified);
			return 'unroutable';
		}

		// The manifest as the sender SIGNED it, not as the relay re-described the
		// parts — the size a part declared (plaintext) is what the signature covers.
		$manifest = self::signedManifest($container);
		if (empty($manifest)) {
			return 'unroutable';
		}

		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}
		try {
			$spool = new DirectSpool(NULL);
			$spool->set('jdp_kind', $kind);
			$spool->set('jdp_nonce', $nonce);
			$spool->set('jdp_protocol_version', (int)($container['protocol_version'] ?? DirectProtocol::PROTOCOL_VERSION));
			$spool->set('jdp_sender_address', $sender);
			// The domain THIS BOX just re-verified the sender's signature against —
			// derived from the signed envelope, not taken from the relay's claim. A
			// gate matches on this, which is what stops a spoofed From — or a
			// compromised relay — borrowing someone's place in a contact list.
			$spool->set('jdp_sender_domain', $sender_domain);
			$spool->set('jdp_recipient', $recipient);
			$spool->set('jdp_domain', DirectProtocol::domainOf($recipient));
			$spool->set('jdp_usr_user_id', $resolved['user_id'] > 0 ? $resolved['user_id'] : null);
			// The recipient identity resolved above must ride the row, or the gate —
			// whether it runs now (unencrypted) or at unlock (encrypted, via the
			// drain) — would resolve against no alias and file to Unmatched. The
			// local path carries this from openStaging; the relay path has to too.
			$spool->set('jdp_recipient_alias_id', ($resolved['alias_id'] ?? 0) > 0 ? (int)$resolved['alias_id'] : null);
			$spool->set('jdp_recipient_domain_id', ($resolved['domain_id'] ?? 0) > 0 ? (int)$resolved['domain_id'] : null);
			$spool->set('jdp_manifest', json_encode($manifest));
			$spool->set('jdp_key_generation', (int)($container['key_generation'] ?? 1));
			$spool->set('jdp_bytes', self::totalBytes($manifest));
			// HELD, not staging: the transfer is already complete and verified.
			// It is waiting on one thing only — the recipient's unlock.
			$spool->set('jdp_state', DirectSpool::STATE_HELD);
			$spool->set('jdp_received_time', self::receivedTime($container));
			$spool->save();

			$sealed = !empty($container['sealed']);
			foreach (array_values($parts) as $index => $part) {
				$bytes = base64_decode((string)($part['content'] ?? ''), true);
				if ($bytes === false) {
					throw new RuntimeException('part ' . $index . ' is not decodable');
				}
				DirectSpoolService::storeRelayedPart(intval($spool->key), $index, $manifest[$index],
					$bytes, $sealed, (string)($part['hash'] ?? ''),
					$resolved['user_id'] > 0 ? (int)$resolved['user_id'] : 0);
			}

			// Disposition, per-mailbox, mirroring the local sealed-tier path (A2). An
			// ENCRYPTED mailbox stays HELD (set above): its contact book is sealed, so
			// the gate can only run when the owner unlocks, and the ordinary drain
			// does it. An UNENCRYPTED mailbox — a group alias or a vaultless owner —
			// has a plaintext book AND its parts crossed unsealed, so there is nothing
			// to wait for: gate it NOW against that book and ingest, exactly as the
			// local commit path does. Without this a relayed delivery to an
			// unencrypted mailbox would be held for an unlock that never comes.
			if ($resolved['vault_public_key'] === null) {
				$accepted = DirectSpoolService::gateFor($spool);
				DirectSpoolService::ingest($spool, $accepted, null);
				$spool->set('jdp_state', DirectSpool::STATE_DONE);
				$spool->set('jdp_drained_time', gmdate('Y-m-d H:i:s'));
				$spool->save();
				DirectSpoolService::dropParts($spool);
			}

			if ($owns_tx) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			// Un-acked, so the next pull retries. Nothing partial survives.
			throw $e;
		}

		return 'pending';
	}

	/**
	 * Re-verify a relayed container's sender against the sender domain's own
	 * DNS-published key. Returns 'ok', 'hold' (the key could not be resolved —
	 * transient, retry on the next pull), or a reason string (verification failed —
	 * drop with a loud log). The box establishes the sender here; the relay's word
	 * is never trusted.
	 */
	private static function verifySender(array $container, string $sender, string $sender_domain,
			string $key_id, string $kind, string $recipient, string $nonce, array $parts): string {
		if ($sender === '' || $sender_domain === '') {
			return 'missing or malformed sender';
		}
		$public_key = DirectCapability::publicKeyFor($sender_domain, $key_id);
		if ($public_key === null) {
			return 'hold'; // no key for the sender domain right now — recoverable
		}

		$signed_manifest = self::signedManifest($container);
		if (empty($signed_manifest) || count($signed_manifest) !== count($parts)) {
			return 'signed manifest does not match the delivered parts';
		}

		$envelope = array(
			'protocol_version' => (int)($container['protocol_version'] ?? DirectProtocol::PROTOCOL_VERSION),
			'kind'      => $kind,
			'sender'    => $sender,
			'recipient' => $recipient,
			'key_id'    => $key_id,
			'nonce'     => $nonce,
			'timestamp' => (string)($container['timestamp'] ?? ''),
		);
		if (!DirectSigningIdentity::verify(
				DirectProtocol::preflightSigningBytes($envelope, $signed_manifest),
				(string)($container['preflight_signature'] ?? ''), $public_key)) {
			return 'preflight signature did not verify';
		}

		$hashes = self::orderedHashes($parts);
		if (!DirectSigningIdentity::verify(
				DirectProtocol::transferSigningBytes($nonce, $hashes),
				(string)($container['transfer_signature'] ?? ''), $public_key)) {
			return 'transfer signature did not verify';
		}

		// The signed hashes are the sender's; confirm the delivered bytes actually
		// hash to them, so a relay cannot pair authentic hashes with swapped content.
		foreach (array_values($parts) as $i => $part) {
			$bytes = base64_decode((string)($part['content'] ?? ''), true);
			if ($bytes === false || DirectProtocol::hashBytes($bytes) !== (string)($part['hash'] ?? '')) {
				return 'part ' . $i . ' content does not match its signed hash';
			}
		}
		return 'ok';
	}

	/** The manifest as the sender signed it, canonicalized the one way both ends agree on. */
	private static function signedManifest(array $container): array {
		$raw = is_array($container['signed_manifest'] ?? null) ? $container['signed_manifest'] : array();
		return DirectProtocol::canonicalManifest($raw);
	}

	/** The ordered per-part hashes the transfer signature covers. */
	private static function orderedHashes(array $parts): array {
		$hashes = array();
		foreach (array_values($parts) as $part) {
			$hashes[] = (string)($part['hash'] ?? '');
		}
		return $hashes;
	}

	private static function totalBytes(array $manifest): int {
		$total = 0;
		foreach ($manifest as $entry) {
			$total += max(0, (int)($entry['size'] ?? 0));
		}
		return $total;
	}

	/** The relay's receive time, so a held delivery sorts by when it ARRIVED. */
	private static function receivedTime(array $container): string {
		$stamp = strtotime((string)($container['received_utc'] ?? ''));
		return $stamp !== false ? gmdate('Y-m-d H:i:s', $stamp) : gmdate('Y-m-d H:i:s');
	}
}
