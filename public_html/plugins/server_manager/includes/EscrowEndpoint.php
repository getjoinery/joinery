<?php
/**
 * EscrowEndpoint — the escrow holder's side of an encrypted data root
 * (specs/hosted_data_promise_disk_encryption.md D3, WP2): POST
 * /api/v1/agent/escrow/{enrol,confirm,unlock,report}, reached through
 * AgentChannelEndpoint's dispatch.
 *
 * WHO ASKS. A machine whose data root is encrypted, by its UNLOCK IDENTITY: an
 * Ed25519 key of its own, minted at create on its root disk, used for nothing
 * else. Every request is signed with it over the method, the path, the
 * identity, a timestamp, a nonce and the body's hash, under the domain
 * "joinery-unlock-v1", so no other signature the machine makes stands in for
 * one. The holder keys its escrow table by that identity alone, so it answers
 * a machine whether or not it is this plane's node, and a leave or re-pair
 * changes nothing (the getjoinery and dev pair hold each other's).
 *
 * ENROL. The machine asks, with its addresses, its break-glass box and a
 * report key (below). Where it also carries its node key's signature over the
 * identity, this plane's own node is vouching for it: the secret is minted and
 * handed over in this answer, once, over TLS, and never stored anywhere but
 * here, sealed. Without that, the request waits for a person to approve it on
 * this dashboard after comparing fingerprints (C-3); the machine asks again
 * until it is. The machine adds the secret as a key slot and CONFIRMs which.
 *
 * UNLOCK. At boot, from a recorded identity, from an address it may ask from,
 * and not inside 15 minutes of an answer it has not reported unlocked or shut
 * down since: answered with the secret (Tier 1). Every refusal is recorded with
 * the address it came from, for the holder's page.
 *
 * PREFLIGHT, before a planned reboot (D4): whether the boot that follows would
 * be answered. It changes nothing.
 *
 * REPORT, and the report key. The identity is on the plain root disk, so a
 * copy of the disk can sign. What changes the record (an address, unlocked,
 * shut down, a new box) must therefore also carry an HMAC under a key the
 * machine derived from its volume key and handed over at enrolment, while the
 * volume was open: X-Joinery-Unlock-Report-Mac. A copy of the disk, whose
 * volume is ciphertext, cannot make one, so it can neither move the address
 * check to its own address nor reset the one-answer rule.
 *
 * @version 1.1 - rekey (D8); Tier 2 (D3): the provider's activity log read before an unlock is answered,
 *                and before a preflight says it would be
 * @version 1.0
 */

class EscrowRefusal extends Exception {
	public $type;
	public $status;
	public function __construct(string $message, string $type, int $status) {
		parent::__construct($message);
		$this->type = $type;
		$this->status = $status;
	}
}

class EscrowEndpoint {
	const MAX_BODY = 65536;
	const MAX_CLOCK_SKEW_SECONDS = 300;
	const SIGNING_DOMAIN = 'joinery-unlock-v1';
	const ENROL_DOMAIN = 'joinery-escrow-enrol-v1';
	const PATH = '/api/v1/agent/escrow/';
	const ACTIONS = array('enrol', 'confirm', 'unlock', 'report', 'preflight', 'rekey');

	/** Handle one escrow request over HTTP. Always exits. */
	public static function dispatch(string $action): void {
		$raw = self::read_raw();
		$headers = array();
		foreach (array('Identity', 'Timestamp', 'Nonce', 'Signature', 'Report-Mac') as $h) {
			$headers[$h] = trim((string)($_SERVER['HTTP_X_JOINERY_UNLOCK_' . strtoupper(str_replace('-', '_', $h))] ?? ''));
		}
		$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
		$answer = self::handle($action, $raw, $headers, (string)SessionControl::get_client_ip(true), $host);
		if (isset($answer['error'])) {
			api_error($answer['error'], $answer['type'], $answer['code']);
		}
		api_success($answer['data']);
	}

	/**
	 * The request itself, apart from HTTP: the action, the raw body, the five
	 * X-Joinery-Unlock-* headers (Identity, Timestamp, Nonce, Signature,
	 * Report-Mac), the caller's address as the platform reads it, and the name
	 * the request came to. Returns ['data' => ...] or ['error', 'type', 'code'].
	 */
	public static function handle(string $action, string $raw, array $headers, string $addr, string $host): array {
		try {
			if (!in_array($action, self::ACTIONS, true)) {
				throw new EscrowRefusal('Unknown escrow action.', 'ActionError', 404);
			}
			if (strlen($raw) > self::MAX_BODY) {
				throw new EscrowRefusal('That escrow request is larger than this holder accepts.', 'RequestTooLarge', 413);
			}
			$identity = self::verify($action, $raw, $headers);
			$body = json_decode($raw, true);
			if (!is_array($body) || ($body['action'] ?? '') !== $action) {
				throw new EscrowRefusal('That escrow request is not one this holder reads.', 'ValidationError', 400);
			}
			$mac = (string)($headers['Report-Mac'] ?? '');
			switch ($action) {
				case 'enrol':
					return array('data' => self::enrol($identity, $body, $addr, $host));
				case 'confirm':
					return array('data' => self::confirm($identity, $body, $addr, $raw, $mac));
				case 'unlock':
					return array('data' => self::unlock($identity, $body, $addr));
				case 'preflight':
					return array('data' => self::preflight($identity, $addr, $raw, $mac));
				case 'rekey':
					return array('data' => self::rekey($identity, $body, $addr, $raw, $mac));
				default:
					return array('data' => self::report($identity, $body, $addr, $raw, $mac));
			}
		} catch (EscrowRefusal $r) {
			return array('error' => $r->getMessage(), 'type' => $r->type, 'code' => $r->status);
		}
	}

	/** The body, under the cap; the HTTP layer's own refusal when it is over. */
	private static function read_raw(): string {
		$declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
		$raw = $declared > self::MAX_BODY ? '' : (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
		if ($declared > self::MAX_BODY || strlen($raw) > self::MAX_BODY) {
			api_error('That escrow request is larger than this holder accepts.', 'RequestTooLarge', 413);
		}
		return $raw;
	}

	/** The signing message, shared with the tests and the agent's escrowSigningMessage(). */
	public static function signing_message(string $action, string $identity, string $timestamp, string $nonce, string $body_sha256): string {
		return implode("\n", array(self::SIGNING_DOMAIN, 'POST', self::PATH . $action, $identity, $timestamp, $nonce, $body_sha256));
	}

	/** The unlock identity the request is signed by, or a 401 and exit. */
	private static function verify(string $action, string $raw, array $headers): string {
		$identity = (string)($headers['Identity'] ?? '');
		$timestamp = (string)($headers['Timestamp'] ?? '');
		$nonce = (string)($headers['Nonce'] ?? '');
		$signature = base64_decode((string)($headers['Signature'] ?? ''), true);
		$public = base64_decode($identity, true);
		if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
				|| !preg_match('/^\d{1,20}$/', $timestamp) || $nonce === '' || strlen($nonce) > 64
				|| $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			throw new EscrowRefusal('An escrow request is signed by its unlock identity.', 'AuthenticationError', 401);
		}
		if (abs(time() - (int)$timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
			throw new EscrowRefusal('That escrow request\'s clock is more than five minutes from this holder\'s.', 'AuthenticationError', 401);
		}
		$message = self::signing_message($action, $identity, $timestamp, $nonce, hash('sha256', $raw));
		if (!sodium_crypto_sign_verify_detached($signature, $message, $public)) {
			throw new EscrowRefusal('That escrow request did not verify against its unlock identity.', 'AuthenticationError', 401);
		}
		return $identity;
	}

	/** The report key's HMAC over the body is right: the sender has the volume open. */
	private static function report_mac_ok(DataRootEscrow $row, string $raw, string $mac_b64): bool {
		$key = $row->report_key();
		$mac = base64_decode($mac_b64, true);
		if ($key === null || $mac === false || strlen($mac) !== 32) {
			return false;
		}
		return hash_equals(hash_hmac('sha256', $raw, $key, true), $mac);
	}

	/**
	 * This plane's own node vouching for the identity: the node key's
	 * signature over it and this holder's URL, fresh. Returns the node, or null.
	 */
	public static function node_proof(string $identity, array $body, string $here): ?ManagedNode {
		$node_id = (int)($body['node_id'] ?? 0);
		$time = (string)($body['node_time'] ?? '');
		$holder = (string)($body['holder'] ?? '');
		$signature = base64_decode((string)($body['node_signature'] ?? ''), true);
		if ($node_id <= 0 || !preg_match('/^\d{1,20}$/', $time) || abs(time() - (int)$time) > self::MAX_CLOCK_SKEW_SECONDS
				|| $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $holder === '') {
			return null;
		}
		// The URL it signed is this holder's: the name this request came to.
		$host = strtolower((string)parse_url($holder, PHP_URL_HOST));
		if ($host === '' || $host !== $here) {
			return null;
		}
		$node = new ManagedNode($node_id, TRUE);
		if (!$node->key || $node->get('mgn_delete_time')) {
			return null;
		}
		$public = base64_decode((string)$node->get('mgn_agent_public_key'), true);
		if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			return null;
		}
		$message = self::ENROL_DOMAIN . "\n" . $identity . "\n" . rtrim($holder, '/') . "\n" . $time;
		return sodium_crypto_sign_verify_detached($signature, $message, $public) ? $node : null;
	}

	private static function note_request(DataRootEscrow $row, string $addr): void {
		$row->set('dre_last_request_time', gmdate('Y-m-d H:i:s'));
		$row->set('dre_last_request_address', substr($addr, 0, 64));
	}

	private static function enrol(string $identity, array $body, string $addr, string $host): array {
		$report_key = base64_decode((string)($body['report_key'] ?? ''), true);
		if ($report_key === false || strlen($report_key) !== 32) {
			throw new EscrowRefusal('An enrolment carries the machine\'s report key.', 'ValidationError', 400);
		}
		$node = self::node_proof($identity, $body, $host);
		$row = DataRootEscrow::find_by_identity($identity);
		if (!$row) {
			if (!$node && count(new MultiDataRootEscrow(array('status' => 'pending'))) >= DataRootEscrow::MAX_PENDING) {
				throw new EscrowRefusal('Too many enrolments are waiting for approval on this holder. Try again later.', 'RateLimitError', 429);
			}
			$row = new DataRootEscrow(NULL);
			$row->set('dre_identity_key', $identity);
			$row->set('dre_status', 'pending');
			$row->set('dre_requested_time', gmdate('Y-m-d H:i:s'));
		}
		$status = (string)$row->get('dre_status');
		if ($status === 'retired') {
			throw new EscrowRefusal('This machine\'s enrolment here was removed. Enrol it again from a new unlock identity.', 'PermissionError', 403);
		}
		if ($status === 'enrolled' && !$node) {
			return array('status' => 'refused', 'reason' => 'This machine is already enrolled here (key slot '
				. (int)$row->get('dre_slot') . '). Remove its enrolment on the holder\'s page to enrol it again.');
		}
		// What it says of itself is taken while it waits for approval (the
		// person approving sees it) or with its node vouching. Once approved,
		// it changes nothing, and it must ask from where it said it would.
		self::note_request($row, $addr);
		if ($node || $status !== 'approved') {
			$row->set('dre_hostname', substr(trim((string)($body['hostname'] ?? '')), 0, 255));
			$row->set_stated_addresses((array)($body['addresses'] ?? array()));
			$row->note_seen_address($addr);
			$row->note_box((string)($body['box'] ?? ''));
		}
		if (!$node && $status === 'known') {
			// Seen in a host report, asking now without its node's proof: a
			// person approves it (C-3).
			$row->set('dre_status', 'pending');
			$row->set('dre_requested_time', gmdate('Y-m-d H:i:s'));
		}
		if ($node) {
			$row->set('dre_mgn_managed_node_id', $node->key);
			if ($status !== 'approved') {
				$row->set('dre_status', 'approved');
				$row->set('dre_approved_time', gmdate('Y-m-d H:i:s'));
				$row->set('dre_secret', null);
			}
		}
		if ((string)$row->get('dre_status') !== 'approved') {
			$row->save();
			return array('status' => 'pending', 'fingerprint' => $row->fingerprint());
		}
		// Approved (by its node's key, or by a person): handed over in this
		// answer. A second ask before the slot is confirmed gets the same
		// secret, from where it asked before or with its node's proof.
		if (!$node && !$row->address_allowed($addr)) {
			$row->refuse('An enrolment was asked from ' . $addr . ', which this machine has not stated.', $addr);
			$row->save();
			return array('status' => 'refused', 'reason' => 'Asked from an address this machine has not stated.');
		}
		$secret = $row->secret();
		if ($secret === null) {
			$secret = $row->mint_secret();
		}
		$row->set_report_key($report_key);
		$row->save();
		return array('status' => 'enrolled', 'secret' => base64_encode($secret), 'fingerprint' => $row->fingerprint());
	}

	private static function confirm(string $identity, array $body, string $addr, string $raw, string $mac): array {
		$row = DataRootEscrow::find_by_identity($identity);
		if (!$row || (string)$row->get('dre_status') !== 'approved') {
			throw new EscrowRefusal('No enrolment of this machine is waiting to be confirmed here.', 'ActionError', 409);
		}
		if (!self::report_mac_ok($row, $raw, $mac)) {
			throw new EscrowRefusal('A confirmation is made with the data root open: its report key\'s MAC did not verify.', 'AuthenticationError', 401);
		}
		$slot = $body['slot'] ?? null;
		if (!is_int($slot) || $slot < 0 || $slot > 31) {
			throw new EscrowRefusal('A confirmation names the key slot (0-31).', 'ValidationError', 400);
		}
		$row->set('dre_slot', $slot);
		$row->set('dre_status', 'enrolled');
		$row->set('dre_enrolled_time', gmdate('Y-m-d H:i:s'));
		$row->set('dre_refusal', null);
		$row->note_seen_address($addr);
		self::note_request($row, $addr);
		$row->save();
		return array('status' => 'enrolled', 'slot' => $slot);
	}

	/**
	 * A machine whose volume key changed (a leaked recovery key, D8): the
	 * re-encryption took every other key slot away, the escrow slot with it.
	 * It proves it held the old volume key (its report key's MAC, the old
	 * one) and states the new report key; a NEW secret is minted and handed
	 * over, and the slot it fills is confirmed as an enrolment's is. Never the
	 * old secret: the old report key is derivable by whoever holds the leaked
	 * recovery key and an old copy of the disk, and a race they won would hand
	 * them a secret no slot of the machine's holds, and fail the machine's
	 * own ask loudly.
	 */
	private static function rekey(string $identity, array $body, string $addr, string $raw, string $mac): array {
		$row = DataRootEscrow::find_by_identity($identity);
		if (!$row || (string)$row->get('dre_status') !== 'enrolled') {
			throw new EscrowRefusal('This holder holds no enrolled key of this machine to change.', 'ActionError', 409);
		}
		if (!self::report_mac_ok($row, $raw, $mac)) {
			throw new EscrowRefusal('A new volume key is stated with the old one\'s report key; its MAC did not verify.', 'AuthenticationError', 401);
		}
		if (!$row->address_allowed($addr)) {
			$row->refuse('A changed volume key was stated from ' . $addr . ', which this machine has not stated.', $addr);
			$row->save();
			throw new EscrowRefusal('Asked from an address this machine has not stated.', 'PermissionError', 403);
		}
		$report_key = base64_decode((string)($body['report_key'] ?? ''), true);
		if ($report_key === false || strlen($report_key) !== 32) {
			throw new EscrowRefusal('A changed volume key states its new report key.', 'ValidationError', 400);
		}
		self::note_request($row, $addr);
		$secret = $row->mint_secret();
		$row->set_report_key($report_key);
		$row->note_box((string)($body['box'] ?? ''));
		$row->set('dre_status', 'approved');
		$row->set('dre_slot', null);
		$row->save();
		return array('status' => 'enrolled', 'secret' => base64_encode($secret), 'fingerprint' => $row->fingerprint());
	}

	private static function unlock(string $identity, array $body, string $addr): array {
		$row = DataRootEscrow::find_by_identity($identity);
		if (!$row || (string)$row->get('dre_status') === 'retired') {
			throw new EscrowRefusal('This holder does not hold this machine\'s key.', 'PermissionError', 403);
		}
		self::note_request($row, $addr);
		$boot = substr(preg_replace('/[^a-f0-9-]/', '', strtolower((string)($body['boot_id'] ?? ''))), 0, 64);
		if (!$row->address_allowed($addr)) {
			self::refuse_unlock($row, $addr, 'Asked from ' . $addr . ', which this machine has not stated (a copy of its disk elsewhere, or a new address it has not reported).');
		}
		// A person's answer from the holder's unlock page (D2b) goes first: it
		// is the one way in for a machine with no slot here.
		$shared = $row->pending_shared();
		if ($shared !== null) {
			$row->set_pending_shared(null);
			self::answered($row, $boot, $addr);
			$row->save();
			return array('shared' => base64_encode($shared));
		}
		if ((string)$row->get('dre_status') !== 'enrolled') {
			self::refuse_unlock($row, $addr, 'This machine is not enrolled here yet; it opens by its recovery key, or from the holder\'s page.');
		}
		if ($row->answered_recently()) {
			self::refuse_unlock($row, $addr, 'Answered at ' . $row->get('dre_answered_time') . ' UTC, from '
				. $row->get('dre_answered_address') . ', and no unlock reported since: a second boot inside 15 minutes is refused.');
		}
		// Tier 2 (D3): what its provider's activity log says happened to it
		// since it last opened, read before the secret is handed over.
		$tier2 = EscrowTier2::refusal($row, $addr);
		if ($tier2 !== null) {
			self::refuse_unlock($row, $addr, $tier2);
		}
		$secret = $row->secret();
		if ($secret === null) {
			self::refuse_unlock($row, $addr, 'This holder cannot open its sealed copy of the escrow secret.', 500);
		}
		self::answered($row, $boot, $addr);
		$row->save();
		return array('secret' => base64_encode($secret), 'slot' => (int)$row->get('dre_slot'));
	}

	/**
	 * Before a planned reboot (D4): would this holder answer the boot that
	 * follows? Asked by the running machine, with the report key's MAC, a
	 * minute before it reboots; it changes nothing.
	 */
	private static function preflight(string $identity, string $addr, string $raw, string $mac): array {
		$row = DataRootEscrow::find_by_identity($identity);
		if (!$row || (string)$row->get('dre_status') !== 'enrolled') {
			throw new EscrowRefusal('This holder does not hold this machine\'s key.', 'PermissionError', 403);
		}
		if (!self::report_mac_ok($row, $raw, $mac)) {
			throw new EscrowRefusal('A preflight is made with the data root open: its report key\'s MAC did not verify.', 'AuthenticationError', 401);
		}
		if (!$row->address_allowed($addr)) {
			throw new EscrowRefusal('Asked from ' . $addr . ', which this machine has not stated: its boot would be refused.', 'PermissionError', 403);
		}
		$tier2 = EscrowTier2::refusal($row, $addr, function () {});
		if ($tier2 !== null) {
			$row->save();
			throw new EscrowRefusal('Its boot would be refused: ' . $tier2, 'PermissionError', 403);
		}
		if ($row->secret() === null) {
			throw new EscrowRefusal('This holder cannot open its sealed copy of the escrow secret: its boot would not be answered.', 'PermissionError', 500);
		}
		return array('status' => 'ready', 'slot' => (int)$row->get('dre_slot'));
	}

	private static function answered(DataRootEscrow $row, string $boot, string $addr): void {
		$row->set('dre_answered_time', gmdate('Y-m-d H:i:s'));
		$row->set('dre_answered_boot_id', $boot);
		$row->set('dre_answered_address', substr($addr, 0, 64));
		$row->set('dre_refusal', null);
	}

	private static function refuse_unlock(DataRootEscrow $row, string $addr, string $reason, int $code = 403): void {
		$row->refuse($reason, $addr);
		$row->save();
		error_log('escrow: refused ' . $row->fingerprint() . ' (' . $row->get('dre_hostname') . '): ' . $reason);
		throw new EscrowRefusal($reason, 'PermissionError', $code);
	}

	private static function report(string $identity, array $body, string $addr, string $raw, string $mac): array {
		$row = DataRootEscrow::find_by_identity($identity);
		if (!$row || (string)$row->get('dre_status') === 'retired') {
			throw new EscrowRefusal('This holder does not hold this machine\'s key.', 'PermissionError', 403);
		}
		self::note_request($row, $addr);
		if (!self::report_mac_ok($row, $raw, $mac)) {
			$row->save();
			throw new EscrowRefusal('A report is made with the data root open: its report key\'s MAC did not verify.', 'AuthenticationError', 401);
		}
		$state = (string)($body['state'] ?? '');
		$now = gmdate('Y-m-d H:i:s');
		switch ($state) {
			case 'unlocked':
				$row->set('dre_unlocked_time', $now);
				break;
			case 'shutdown':
				$row->set('dre_shutdown_time', $now);
				break;
			case 'addresses':
				break;
			default:
				throw new EscrowRefusal('A report says unlocked, shutdown or addresses.', 'ValidationError', 400);
		}
		if (isset($body['addresses'])) {
			$row->set_stated_addresses((array)$body['addresses']);
		}
		$row->note_seen_address($addr);
		$row->note_box((string)($body['box'] ?? ''));
		$row->save();
		return array('status' => 'recorded');
	}
}
