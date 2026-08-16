<?php
/**
 * DirectSpoolService - where a delivery's parts live between the preflight and
 * the moment a kind stores them.
 *
 * One staging store serves both tiers, on purpose. At Standard the row is opened
 * at accept, filled as parts arrive, ingested at commit, and marked done in the
 * same request. At Private and Fortress the identical row is left HELD after
 * commit, because the contact list is sealed and the gate cannot run until the
 * recipient's next unlock. Having one path means the byte accounting has one
 * home, and it is what lets a decoy delivery — whose parts are discarded — still
 * accrue its declared bytes against the address cap, so a full spool refuses
 * identically whether the address exists or not.
 *
 * The caps are byte-denominated because counts alone do not bound storage:
 * accept-before-judgment means a flood's only real spend is disk held sealed
 * until unlock, so that is what gets bounded. A per-domain cap bounds the whole
 * spool; a per-address cap beneath it stops one flooded recipient consuming the
 * domain's budget. Both are absolute recipient-side bounds, so no number of
 * cheap sending domains raises the ceiling the way Sybil multiplies a
 * per-instance rate limit. A cap refusal costs a legitimate sender only the
 * downgrade — mail falls back to SMTP and arrives anyway. A silent local drop
 * was considered and rejected: it could lose a legitimate contact's sealed mail,
 * where a request-level refusal loses nothing.
 *
 * @version 1.2
 * @changelog 1.1 - a kind's ingest may throw DirectDeferIngest at commit; the
 *   delivery is HELD with its parts and drained at the recipient's next unlock.
 * @changelog 1.2 - gateFor() judges the kind's declared recipient requirement
 *   (fresh resolution) before the contact/handler gate, so the deferred sites
 *   apply the same precondition the Standard preflight does.
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectContactGate.php'));
require_once(PathHelper::getIncludePath('data/direct_sessions_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_parts_class.php'));

class DirectSpoolService {

	/**
	 * Null when this delivery fits under both caps; the refusal reason otherwise.
	 *
	 * @param array $resolved the recipient facts from DirectRecipients::resolve()
	 */
	public static function capRefusal(array $resolved, string $recipient, int $declared_bytes): ?string {
		$domain = DirectProtocol::domainOf($recipient);

		$domain_cap = DirectSettings::spoolDomainCapBytes();
		if ($domain_cap > 0 && DirectSpool::bytesForDomain($domain) + $declared_bytes > $domain_cap) {
			return 'Direct spool is full for this domain';
		}
		$address_cap = DirectSettings::spoolAddressCapBytes();
		if ($address_cap > 0 && DirectSpool::bytesForAddress($recipient) + $declared_bytes > $address_cap) {
			return 'Direct spool is full for this address';
		}
		return null;
	}

	/**
	 * Open the staging row for an accepted delivery. Its declared byte weight is
	 * charged from this moment, not at commit — an in-flight transfer is real
	 * disk, and a cap that only looked at what had already landed would let a
	 * flood of concurrent transfers slip past it.
	 */
	public static function openStaging(DirectSession $session, array $resolved): DirectSpool {
		$row = new DirectSpool(NULL);
		$row->set('jdp_kind', (string)$session->get('jds_kind'));
		$row->set('jdp_nonce', (string)$session->get('jds_nonce'));
		$row->set('jdp_protocol_version', intval($session->get('jds_protocol_version')));
		$row->set('jdp_sender_address', (string)$session->get('jds_sender_address'));
		$row->set('jdp_sender_domain', (string)$session->get('jds_sender_domain'));
		$row->set('jdp_recipient', (string)$session->get('jds_recipient'));
		$row->set('jdp_domain', DirectProtocol::domainOf((string)$session->get('jds_recipient')));
		$row->set('jdp_usr_user_id', $resolved['user_id'] > 0 ? $resolved['user_id'] : null);
		$row->set('jdp_recipient_alias_id', ($resolved['alias_id'] ?? 0) > 0 ? (int)$resolved['alias_id'] : null);
		$row->set('jdp_recipient_domain_id', ($resolved['domain_id'] ?? 0) > 0 ? (int)$resolved['domain_id'] : null);
		$row->set('jdp_manifest', (string)$session->get('jds_manifest'));
		$row->set('jdp_key_generation', intval($session->get('jds_key_generation')));
		$row->set('jdp_bytes', intval($session->get('jds_total_bytes')));
		$row->set('jdp_state', DirectSpool::STATE_STAGING);

		// Charge and verify as ONE critical section. capRefusal at preflight is a
		// fast pre-check, but it READS the spool sum while openStaging WRITES to it
		// in a separate step, so a burst of concurrent accepts could each read a
		// pre-charge sum and all slip past the cap. A per-domain advisory lock
		// serializes the accepts for one domain: each sees every prior charge, so
		// the sum this row is judged against is authoritative. On the rare overshoot
		// the charge is rolled back and the caller refuses (the sender falls back),
		// never leaving an over-cap row behind.
		$domain = (string)$row->get('jdp_domain');
		$recipient = (string)$session->get('jds_recipient');
		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}
		try {
			$lock = $db->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
			$lock->execute(array('joinery_direct_spool:' . $domain));
			$row->save();

			// The row's own bytes are now inside these sums, so the test is on the
			// post-charge total, not total + declared.
			$domain_cap  = DirectSettings::spoolDomainCapBytes();
			$address_cap = DirectSettings::spoolAddressCapBytes();
			if (($domain_cap > 0 && DirectSpool::bytesForDomain($domain) > $domain_cap)
					|| ($address_cap > 0 && DirectSpool::bytesForAddress($recipient) > $address_cap)) {
				throw new RuntimeException('Joinery Direct spool cap exceeded for ' . $domain . ' on charge');
			}
			if ($owns_tx) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
		return $row;
	}

	/** Persist one delivered part against its staging row. */
	public static function storePart(DirectSession $session, int $index, array $manifest_entry, string $bytes): void {
		$spool = self::spoolFor($session);
		if ($spool === null) {
			throw new RuntimeException('No staging row for nonce ' . $session->get('jds_nonce'));
		}

		$part = new DirectSpoolPart(NULL);
		$part->set('jda_jdp_direct_spool_id', intval($spool->key));
		$part->set('jda_sequence', $index);
		$part->set('jda_role', (string)($manifest_entry['role'] ?? DirectProtocol::ROLE_ATTACHMENT));
		$part->set('jda_content_type', (string)($manifest_entry['content_type'] ?? 'application/octet-stream'));
		$part->set('jda_filename', ($manifest_entry['filename'] ?? '') !== '' ? (string)$manifest_entry['filename'] : null);
		$part->set('jda_content_id', ($manifest_entry['content_id'] ?? '') !== '' ? (string)$manifest_entry['content_id'] : null);
		$part->set('jda_is_inline', !empty($manifest_entry['is_inline']));
		$part->set('jda_is_sealed', false); // set at commit, when the sender says
		$part->set('jda_bytes', strlen($bytes));
		$part->set('jda_hash', DirectProtocol::hashBytes($bytes));
		$part->save();

		self::writeBytes(intval($part->key), $bytes, (string)($manifest_entry['content_type'] ?? ''),
			(string)($manifest_entry['filename'] ?? ''), intval($spool->get('jdp_usr_user_id')));
	}

	/**
	 * Every delivered part's hash matches the sender's signed list, in order.
	 *
	 * Compared with hash_equals so a mismatch cannot be located byte by byte,
	 * and compared for ALL parts rather than short-circuiting per part, because
	 * the receiver must never behave differently for one part than another based
	 * on its hash.
	 */
	public static function verifyHashes(DirectSession $session, array $hashes): bool {
		$spool = self::spoolFor($session);
		if ($spool === null) {
			return false;
		}
		$parts = DirectSpoolPart::forSpool(intval($spool->key));
		if (count($parts) !== count($hashes)) {
			return false;
		}
		$ok = true;
		foreach (array_values($parts) as $index => $part) {
			if (!hash_equals((string)$hashes[$index], (string)$part->get('jda_hash'))) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Complete a verified delivery.
	 *
	 * At Standard the gate already ran and passed, so the kind ingests now and
	 * the row is done. At the sealed tiers the row stays HELD: the gate has not
	 * run and cannot until the recipient unlocks. A decoy delivery keeps its byte
	 * charge on a HELD row too — its useless part storage is released, but the
	 * charge stays and is reclaimed on the same held-retention schedule as a real
	 * delivery whose recipient never unlocks, so a full spool refuses identically
	 * for a real and a made-up address.
	 */
	public static function finish(DirectSession $session, bool $sealed, int $key_generation): void {
		$spool = self::spoolFor($session);
		if ($spool === null) {
			throw new RuntimeException('No staging row to finish for nonce ' . $session->get('jds_nonce'));
		}

		if ($sealed) {
			$db = DbConnector::get_instance()->get_db_link();
			$db->prepare('UPDATE jda_direct_spool_parts SET jda_is_sealed = true WHERE jda_jdp_direct_spool_id = ?')
				->execute(array(intval($spool->key)));
		}
		if ($key_generation > 0) {
			$spool->set('jdp_key_generation', $key_generation);
			$spool->save();
		}

		if ($session->get('jds_is_decoy')) {
			// Nobody could ever open a decoy, so its sealed-to-decoy part storage is
			// released — but its declared bytes must keep counting against the caps
			// for the same window a real held delivery occupies. A decoy that
			// uncharged at commit would let a nonexistent address never fill a spool
			// while a real one does, turning the 507 into an existence oracle. It is
			// left HELD with the charge intact; its null owner means no unlock ever
			// drains it, so only the ordinary held-retention sweep reclaims it —
			// exactly as it would a real delivery to a recipient who never unlocks.
			self::releaseParts($spool);
			$spool->set('jdp_state', DirectSpool::STATE_HELD);
			$spool->save();
			return;
		}

		if ($session->get('jds_is_deferred')) {
			$spool->set('jdp_state', DirectSpool::STATE_HELD);
			$spool->save();
			return;
		}

		// Live disposition, ingested now. The gate outcome is either the one the
		// Standard live gate already reached at preflight (nothing left to decide),
		// or — for an unencrypted mailbox on a sealing domain, which accepted
		// uniformly on the wire without gating — the contact check run now against
		// its plaintext address book, the deferred gate without the wait.
		$accepted = $session->get('jds_gate_at_commit') ? self::gateFor($spool) : true;
		try {
			self::ingest($spool, $accepted, null);
		} catch (DirectDeferIngest $e) {
			// The kind's store is sealed and nobody present can open it — a
			// conversation raised to Private on a Standard-posture mailbox, say.
			// Held with its parts and drained at the recipient's next unlock;
			// the wire answer is unchanged, so lock state never leaks.
			$spool->set('jdp_state', DirectSpool::STATE_HELD);
			$spool->save();
			return;
		}
		$spool->set('jdp_state', DirectSpool::STATE_DONE);
		$spool->set('jdp_drained_time', gmdate('Y-m-d H:i:s'));
		$spool->save();
		self::dropParts($spool);
	}

	/**
	 * Hand a delivery to its kind's ingest.
	 *
	 * Runs only after every sealed-byte hash has been verified. On the deferred
	 * path it runs at unlock for EVERY held delivery, carrying the deferred
	 * gate's outcome — because the sender was already answered `accept`, a
	 * deferred decline is a local disposition, not a drop.
	 */
	public static function ingest(DirectSpool $spool, bool $gate_accepted, ?string $vault_secret_key): void {
		$kind = (string)$spool->get('jdp_kind');
		$handler = DirectKinds::handler($kind);
		if ($handler === null) {
			// A kind whose plugin went away between accept and now. HELD, not
			// errored — and nothing is returned to the sender in either case.
			throw new RuntimeException('No handler for kind ' . $kind);
		}

		$envelope = self::envelopeFor($spool, $vault_secret_key);

		$parts = array();
		foreach (DirectSpoolPart::forSpool(intval($spool->key)) as $stored) {
			$parts[] = new DirectPart(array(
				'role'         => (string)$stored->get('jda_role'),
				'content_type' => (string)$stored->get('jda_content_type'),
				'filename'     => (string)$stored->get('jda_filename'),
				'content_id'   => (string)$stored->get('jda_content_id'),
				'is_inline'    => (bool)$stored->get('jda_is_inline'),
				'is_sealed'    => (bool)$stored->get('jda_is_sealed'),
				'bytes'        => $stored->bytes(),
				'hash'         => (string)$stored->get('jda_hash'),
			));
		}

		$handler->ingest($envelope, $parts, $gate_accepted);
	}

	/**
	 * The typed envelope for a staged delivery, rebuilt from the row so the
	 * recipient identity resolved at accept reaches both the gate and ingest
	 * unchanged. A non-null secret marks the deferred path and lets a sealed part
	 * be opened; null is the live/commit path, where the parts are already
	 * plaintext.
	 */
	private static function envelopeFor(DirectSpool $spool, ?string $vault_secret_key): DirectEnvelope {
		return DirectEnvelope::fromVerified(array(
			'kind'              => (string)$spool->get('jdp_kind'),
			'protocol_version'  => intval($spool->get('jdp_protocol_version')),
			'sender'            => (string)$spool->get('jdp_sender_address'),
			'sender_domain'     => (string)$spool->get('jdp_sender_domain'),
			'recipient'         => (string)$spool->get('jdp_recipient'),
			'recipient_user_id' => intval($spool->get('jdp_usr_user_id')),
			'recipient_alias_id'  => intval($spool->get('jdp_recipient_alias_id')),
			'recipient_domain_id' => intval($spool->get('jdp_recipient_domain_id')),
			'timestamp'         => (string)$spool->get('jdp_received_time'),
			'manifest'          => $spool->manifest(),
			'key_generation'    => intval($spool->get('jdp_key_generation')),
			'is_deferred'       => $vault_secret_key !== null,
			'vault_secret_key'  => $vault_secret_key,
		));
	}

	/**
	 * Run a staged delivery's authorization gate — the canned contact gate or the
	 * kind's own — against the row's recipient identity. The same decision whether
	 * it runs at commit (an unencrypted mailbox, plaintext book) or at unlock (a
	 * sealed mailbox drained by DirectSpoolDrain); only the moment differs.
	 *
	 * The kind's declared recipient requirement is judged here first, against a
	 * FRESH resolution — the same moment-of-disposition reading the contact gate
	 * itself takes. It could not run at accept: a sealed-tier preflight answers
	 * identically for every address, so "this kind cannot land here" has to be a
	 * local disposition, and it rides the gate's own decline (`gate_accepted`
	 * false into ingest — mail files the message, chat discards it) rather than
	 * inventing a third outcome. A recipient that stopped resolving since accept
	 * declines the same way.
	 */
	public static function gateFor(DirectSpool $spool): bool {
		$kind = (string)$spool->get('jdp_kind');
		$resolved = DirectRecipients::resolve((string)$spool->get('jdp_recipient'));
		if ($resolved === null || !$resolved['exists'] || !DirectKinds::recipientAcceptable($kind, $resolved)) {
			return false;
		}
		$envelope = self::envelopeFor($spool, null);
		if (DirectKinds::usesContactGate($kind)) {
			return DirectContactGate::allows($envelope);
		}
		$handler = DirectKinds::handler($kind);
		if ($handler === null) {
			return false;
		}
		try {
			return $handler->gate($envelope);
		} catch (\Throwable $e) {
			error_log('DirectSpoolService: gate for kind ' . $kind . ' threw: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Store one part of a delivery the RELAY already verified.
	 *
	 * Separate from storePart() because there is no session here: at Fortress
	 * the wire terminated at the relay, so the box receives a complete,
	 * hash-verified delivery rather than a transfer in progress. The bytes and
	 * the hash the relay recorded are carried through unchanged, so the part
	 * reads identically to one this box took itself.
	 */
	public static function storeRelayedPart(int $spool_id, int $index, array $manifest_entry,
			string $bytes, bool $sealed, string $hash, int $owner_id): void {
		$part = new DirectSpoolPart(NULL);
		$part->set('jda_jdp_direct_spool_id', $spool_id);
		$part->set('jda_sequence', $index);
		$part->set('jda_role', (string)($manifest_entry['role'] ?? DirectProtocol::ROLE_ATTACHMENT));
		$part->set('jda_content_type', (string)($manifest_entry['content_type'] ?? 'application/octet-stream'));
		$part->set('jda_filename', ($manifest_entry['filename'] ?? '') !== '' ? (string)$manifest_entry['filename'] : null);
		$part->set('jda_content_id', ($manifest_entry['content_id'] ?? '') !== '' ? (string)$manifest_entry['content_id'] : null);
		$part->set('jda_is_inline', !empty($manifest_entry['is_inline']));
		$part->set('jda_is_sealed', $sealed);
		$part->set('jda_bytes', strlen($bytes));
		$part->set('jda_hash', $hash !== '' ? $hash : DirectProtocol::hashBytes($bytes));
		$part->save();

		self::writeBytes(intval($part->key), $bytes,
			(string)($manifest_entry['content_type'] ?? ''),
			(string)($manifest_entry['filename'] ?? ''), $owner_id);
	}

	/** Throw the whole staging row away. Nothing is signalled to the sender. */
	public static function discard(DirectSession $session): void {
		try {
			$spool = self::spoolFor($session);
			if ($spool !== null) {
				$spool->permanent_delete();
			}
		} catch (\Throwable $e) {
			error_log('DirectSpoolService: could not discard spool for nonce '
				. $session->get('jds_nonce') . ': ' . $e->getMessage());
		}
	}

	/**
	 * Release a drained delivery's part storage AND its byte charge; the row itself
	 * stays for audit. Used once a delivery is done and its bytes should stop
	 * counting against the caps.
	 */
	public static function dropParts(DirectSpool $spool): void {
		self::releaseParts($spool);
		$spool->set('jdp_bytes', 0);
		$spool->save();
	}

	/**
	 * Delete a delivery's part rows and file bytes but KEEP the row's byte charge.
	 * A decoy uses this: its content is useless and worth reclaiming, but its charge
	 * must go on counting against the caps so a nonexistent address fills a spool
	 * exactly as a real one does.
	 */
	public static function releaseParts(DirectSpool $spool): void {
		foreach (DirectSpoolPart::forSpool(intval($spool->key)) as $part) {
			try {
				$part->permanent_delete();
			} catch (\Throwable $e) {
				error_log('DirectSpoolService: could not drop part ' . $part->key . ': ' . $e->getMessage());
			}
		}
	}

	private static function spoolFor(DirectSession $session): ?DirectSpool {
		$multi = new MultiDirectSpool(array('nonce' => (string)$session->get('jds_nonce')));
		$multi->load();
		foreach ($multi as $row) {
			return $row;
		}
		return null;
	}

	/**
	 * Put one part's bytes wherever they belong: on the row when small, in the
	 * private file store when large, so a 40MB attachment is not a 40MB column.
	 */
	private static function writeBytes(int $part_id, string $bytes, string $content_type, string $filename, int $owner_id): void {
		$db = DbConnector::get_instance()->get_db_link();

		if (strlen($bytes) <= DirectSpoolPart::INLINE_MAX_BYTES) {
			// Base64 for the row, raw for the file store. See DirectSpoolPart's
			// note: the inflation is confined to small staged parts and never
			// reaches the wire.
			$stmt = $db->prepare('UPDATE jda_direct_spool_parts SET jda_content = ? WHERE jda_direct_spool_part_id = ?');
			$stmt->execute(array(base64_encode($bytes), $part_id));
			return;
		}

		require_once(PathHelper::getIncludePath('data/files_class.php'));
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$file = File::createFromBytes(
			$bytes,
			$filename !== '' ? $filename : 'direct-part',
			$content_type !== '' ? $content_type : 'application/octet-stream',
			$owner_id > 0 ? $owner_id : User::USER_SYSTEM,
			array('fil_private' => true)
		);
		$stmt = $db->prepare('UPDATE jda_direct_spool_parts SET jda_fil_file_id = ? WHERE jda_direct_spool_part_id = ?');
		$stmt->execute(array(intval($file->key), $part_id));
	}
}
