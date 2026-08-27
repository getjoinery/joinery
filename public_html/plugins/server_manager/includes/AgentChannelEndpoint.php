<?php
/**
 * AgentChannelEndpoint — the plane's side of the agent channel.
 *
 * A node's agent polls this plane outbound over HTTPS, claims one primitive
 * job at a time, and posts the result back (specs/agent_on_node_architecture.md
 * §3.1, component D).
 *
 * Six endpoints, all POST, all under /api/v1/agent/:
 *   join         node-initiated enrollment (Phase 1.5, decision A6): the node
 *                hands over the PUBLIC half of a keypair it generated and
 *                kept, plus a claimed name. NO secret exists in this exchange
 *                — a human approves the request after comparing the key
 *                fingerprint shown here against the node's own panel.
 *   join_status  the node asking where its request stands (keyed by its own
 *                public key — unauthenticated, because until approval there
 *                is no identity to authenticate)
 *   claim        signed; returns at most one job addressed to the signing node
 *   result       signed; the terminal report for one job
 *   leave        signed; the node ending the pairing from its own side — the
 *                same forgetting the plane-side Disconnect performs
 *
 * These deliberately do NOT live under /api/v1/management/*. That family runs
 * the other way — this plane calling in to a node's web tier — and §3.5.4 pins
 * it status-only with a test that fails on a new endpoint. Hanging claim/result
 * there would break that pin on the first day.
 *
 * WHO IS HOSTILE HERE: the node. A compromised node is the likeliest breach
 * anywhere in this system and it holds a pairing credential (§3.5.8), so
 * everything arriving here is attacker-controllable input: schema-validated,
 * size-capped, and never interpolated anywhere. In particular the result
 * endpoint NEVER accepts a pre-built envelope — it re-encodes the validated
 * data object itself, so a node cannot hand the plane a payload the plane will
 * store verbatim and later parse as its own.
 *
 * @version 1.6 - credential slots resolve at claim time: the job row keeps the placeholder, the
 *                credential exists only inside the signed hand-out response
 * @version 1.5 - quiet endpoint: a node says it is going quiet when its agent is switched off,
 *                so deliberate silence stops reading as breakage. Not a leave — the pairing stands
 * @version 1.4 - leave endpoint: the node ends the pairing from its own side (either side can
 *                disconnect); forgetAgent() is the one place both endings converge
 * @version 1.3 - a connected agent claims jobs unconditionally: the per-node routing flag is gone
 *                (hard cutover, owner-set) — approving the join is the routing decision
 * @version 1.2 - enrollment is a node-initiated join with no shared secret (Phase 1.5, A6):
 *                join + join_status replace pair, and the pairing-token machinery is deleted
 * @version 1.1 - the node's outcome is recorded verbatim in mjb_agent_outcome, so a refusal is
 *                countable without matching error-message text
 * @version 1.0
 */

class AgentChannelEndpoint {

	/**
	 * The raw request body, read exactly once.
	 *
	 * It is needed twice — decoded into a body, and hashed for the signature —
	 * and php://input being re-readable is a property of the request's content
	 * type rather than a guarantee. Reading it once removes the question.
	 */
	private static $raw_body = null;

	// ── Size caps ──
	//
	// Two limits, for opposite reasons, and merging them is the bug the pattern
	// exists to prevent. MAX_REQUEST_BODY defends THIS PLANE from a node that
	// will not stop typing. MAX_JOB_BODY bounds what the plane hands back, and
	// is deliberately smaller: a job is a name and a few validated parameters,
	// while a result carries collected output. The agent enforces the same two
	// numbers from its own side.
	const MAX_REQUEST_BODY = 262144;  // 256 KiB — node → plane
	const MAX_JOB_BODY     = 65536;   // 64 KiB — plane → node

	/** Log text kept from one result. Bounded by construction, like the params. */
	const MAX_LOG_BYTES = 131072;

	/** How far a signed request's clock may be from ours. */
	const MAX_CLOCK_SKEW_SECONDS = 300;

	/** Suggested poll cadence. The agent clamps it to its own compiled range. */
	const SUGGESTED_POLL_INTERVAL = 15;

	/**
	 * Dispatch a /api/v1/agent/* request. Always exits.
	 */
	public static function dispatchPreAuth($url_segments) {
		$endpoint = strtolower($url_segments[3] ?? '');

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			api_error('The agent channel accepts POST only.', 'ActionError', 405);
		}

		// Resolve the endpoint BEFORE reading a body. An unknown path is a 404
		// about the path, not a complaint about whatever was sent to it.
		if (!in_array($endpoint, ['join', 'join_status', 'claim', 'result', 'leave', 'quiet'], true)) {
			api_error('Unknown agent endpoint.', 'ActionError', 404);
		}

		$body = self::read_body();

		switch ($endpoint) {
			case 'join':
				self::handle_join($body);
				break;
			case 'join_status':
				self::handle_join_status($body);
				break;
			case 'claim':
				self::handle_claim($body);
				break;
			case 'result':
				self::handle_result($body);
				break;
			case 'leave':
				self::handle_leave($body);
				break;
			case 'quiet':
				self::handle_quiet($body);
				break;
		}
		exit;
	}

	// ==================================================================
	// Request reading and schema validation
	// ==================================================================

	/**
	 * Read and decode the request body under the inbound cap.
	 *
	 * Content-Length is checked BEFORE php://input is touched, so a node
	 * claiming to send a gigabyte is refused without the plane reading one.
	 */
	private static function read_body() {
		$declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
		if ($declared > self::MAX_REQUEST_BODY) {
			api_error('That request is larger than this plane accepts from an agent (limit '
				. self::MAX_REQUEST_BODY . ' bytes). Nothing was read.', 'RequestTooLarge', 413);
		}

		$raw = file_get_contents('php://input');
		self::$raw_body = (string)$raw;

		// A body over PHP's own post_max_size is DISCARDED before any code runs,
		// while Content-Length still reports what was sent. Left alone, the
		// request fails schema validation on whichever field is checked first
		// and the sender is told a field is missing that they did supply.
		if ($declared > 0 && ($raw === '' || $raw === false)) {
			api_error('That request was too large for this server to accept (limit '
				. ini_get('post_max_size') . '). Nothing was received.', 'RequestTooLarge', 413);
		}

		if (strlen((string)$raw) > self::MAX_REQUEST_BODY) {
			api_error('That request is larger than this plane accepts from an agent.',
				'RequestTooLarge', 413);
		}

		$decoded = json_decode((string)$raw, true);
		// PHP decodes both {} and [] to the same empty array, so an empty
		// object cannot be told from an empty list and must be accepted as the
		// object it almost certainly is. A NON-empty list is unambiguous, and
		// is refused.
		if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
			api_error('The request body must be a JSON object.', 'ValidationError', 400);
		}
		return $decoded;
	}

	/**
	 * Validate a body against a field spec, strictly.
	 *
	 * Unknown keys are refused rather than ignored — the same rule the node
	 * applies to job parameters, for the same reason: a key one side believes
	 * is in effect and the other silently drops is how "it looked like it
	 * worked" happens.
	 *
	 * @param array $spec field => ['type'=>'string|int|object|bool', 'required'=>bool, 'max'=>int]
	 */
	private static function validate(array $body, array $spec) {
		$refusal = self::validation_error($body, $spec);
		if ($refusal !== null) {
			api_error($refusal, 'ValidationError', 400);
		}
		return self::coerced($body, $spec);
	}

	/**
	 * The validation itself, as a pure function: the refusal message, or null
	 * when the body is acceptable.
	 *
	 * Separate from validate() so the boundary can be exercised directly by a
	 * test. api_error() exits the process, and a rule that can only be checked
	 * by making a real HTTP request is a rule most of whose cases never get
	 * checked at all.
	 */
	public static function validation_error(array $body, array $spec) {
		foreach (array_keys($body) as $key) {
			if (!isset($spec[$key])) {
				return 'The request carries an undeclared field: ' . self::safe_label($key);
			}
		}

		foreach ($spec as $field => $rules) {
			$present = array_key_exists($field, $body) && $body[$field] !== null;
			if (!$present) {
				if (!empty($rules['required'])) {
					return 'The request is missing a required field: ' . $field;
				}
				continue;
			}
			$value = $body[$field];

			switch ($rules['type']) {
				case 'string':
					if (!is_string($value)) {
						return "Field '{$field}' must be a string.";
					}
					$max = $rules['max'] ?? 255;
					if (strlen($value) > $max) {
						return "Field '{$field}' is longer than the {$max}-byte limit.";
					}
					if (!empty($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
						return "Field '{$field}' is not in the accepted form.";
					}
					break;
				case 'int':
					if (!is_int($value)) {
						return "Field '{$field}' must be a whole number.";
					}
					break;
				case 'bool':
					if (!is_bool($value)) {
						return "Field '{$field}' must be true or false.";
					}
					break;
				case 'object':
					// An empty array is PHP's rendering of {} as well as of [],
					// so it passes as the empty object it almost certainly is.
					if (!is_array($value) || ($value !== [] && array_is_list($value))) {
						return "Field '{$field}' must be a JSON object.";
					}
					break;
			}
		}
		return null;
	}

	/** The declared fields the body actually supplied. Only ever called after validation_error(). */
	private static function coerced(array $body, array $spec) {
		$clean = [];
		foreach ($spec as $field => $rules) {
			if (array_key_exists($field, $body) && $body[$field] !== null) {
				$clean[$field] = $body[$field];
			}
		}
		return $clean;
	}

	/** Render an attacker-supplied key safely into an error message. */
	private static function safe_label($key) {
		$key = substr((string)$key, 0, 64);
		return preg_replace('/[^A-Za-z0-9_.\-]/', '?', $key);
	}

	// ==================================================================
	// Enrollment: node-initiated join (Phase 1.5, decision A6)
	// ==================================================================

	/**
	 * Take in a join request. Unauthenticated by nature — the whole point is
	 * that no credential exists yet — so everything here is attacker-supplied
	 * and nothing here grants anything: a pending row only becomes an identity
	 * when a superadmin approves it after comparing fingerprints across the
	 * two panels. The endpoint is bounded three ways: the agent-channel rate
	 * bucket, the request TTL, and a hard ceiling on simultaneously pending
	 * rows.
	 *
	 * Repeating a request with the same public key is idempotent — the agent
	 * retries while it waits, and each retry finds its own row.
	 */
	private static function handle_join($body) {
		$in = self::validate($body, [
			'claimed_name'     => ['type' => 'string', 'required' => true, 'max' => 255],
			'agent_public_key' => ['type' => 'string', 'required' => true, 'max' => 64],
			'agent_version'    => ['type' => 'string', 'max' => 20],
		]);

		$public_key = base64_decode($in['agent_public_key'], true);
		if ($public_key === false || strlen($public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			api_error('The agent public key is not a valid Ed25519 public key.', 'ValidationError', 400);
		}

		$existing = AgentJoinRequest::find_by_public_key($in['agent_public_key']);
		if ($existing) {
			if ($existing->get('ajr_status') === AgentJoinRequest::STATUS_PENDING && $existing->is_expired()) {
				// A retry renews its own expired request — same key, same row,
				// fresh clock. The human never sees two rows for one node.
				$existing->set('ajr_create_time', gmdate('Y-m-d H:i:s'));
				$existing->set('ajr_claimed_name', $in['claimed_name']);
				$existing->save();
			}
			api_success(self::join_status_payload($existing), '', 200);
		}

		if (AgentJoinRequest::pending_count() >= AgentJoinRequest::MAX_PENDING) {
			api_error('This management node already has the maximum number of join requests waiting. '
				. 'Approve or reject some before sending another.', 'ActionError', 429);
		}

		$request = new AgentJoinRequest();
		$request->set('ajr_claimed_name', $in['claimed_name']);
		$request->set('ajr_public_key', $in['agent_public_key']);
		$request->set('ajr_fingerprint', AgentJoinRequest::fingerprint($public_key));
		$request->set('ajr_source_ip', substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64));
		$request->set('ajr_agent_version', (string)($in['agent_version'] ?? ''));
		$request->set('ajr_status', AgentJoinRequest::STATUS_PENDING);
		$request->save();

		api_success(self::join_status_payload($request), '', 200);
	}

	/**
	 * Where does my request stand? Keyed by the requesting agent's own public
	 * key — the one thing only it plausibly knows in full, and a value that
	 * grants nothing. The answer is deliberately small: a status, and on
	 * approval the node identity the agent needs to start polling.
	 */
	private static function handle_join_status($body) {
		$in = self::validate($body, [
			'agent_public_key' => ['type' => 'string', 'required' => true, 'max' => 64],
		]);

		$request = AgentJoinRequest::find_by_public_key($in['agent_public_key']);
		if (!$request) {
			api_success(['status' => 'unknown'], '', 200);
		}
		api_success(self::join_status_payload($request), '', 200);
	}

	/** The join/join_status response body for one request row. */
	private static function join_status_payload($request) {
		$status = (string)$request->get('ajr_status');
		if ($status === AgentJoinRequest::STATUS_PENDING && $request->is_expired()) {
			$status = 'expired';
		}
		$payload = [
			'status'      => $status,
			'fingerprint' => (string)$request->get('ajr_fingerprint'),
		];
		if ($status === AgentJoinRequest::STATUS_APPROVED) {
			$node_id = (int)$request->get('ajr_mgn_node_id');
			$payload['node_id']       = $node_id;
			$payload['node_slug']     = '';
			$payload['poll_interval'] = self::SUGGESTED_POLL_INTERVAL;
			if ($node_id > 0) {
				try {
					$node = new ManagedNode($node_id, TRUE);
					$payload['node_slug'] = (string)$node->get('mgn_slug');
				} catch (Exception $e) {
					// The node row went away after approval; the id still stands.
				}
			}
		}
		return $payload;
	}

	/**
	 * Bind an approved request's public key to a node. Called by the node
	 * detail action after the human has compared fingerprints — this is the
	 * moment enrollment actually happens.
	 */
	public static function approveJoin($request, $node) {
		$node->set('mgn_agent_public_key', (string)$request->get('ajr_public_key'));
		$node->set('mgn_agent_paired_time', gmdate('Y-m-d H:i:s'));
		$node->set('mgn_agent_last_poll', null);
		if ($request->get('ajr_agent_version')) {
			$node->set('mgn_agent_version', (string)$request->get('ajr_agent_version'));
		}
		$node->save();

		$request->set('ajr_status', AgentJoinRequest::STATUS_APPROVED);
		$request->set('ajr_mgn_node_id', (int)$node->key);
		$request->save();
	}

	// ==================================================================
	// Signed-request authentication
	// ==================================================================

	/**
	 * Resolve and verify the signing node, or exit 401.
	 *
	 * What comes back is the node the SIGNATURE proves, and that is the only
	 * identity anything downstream uses. A node_id in the body exists solely to
	 * be compared against this one; job selection never reads it, so a claim is
	 * structurally unable to return another node's work.
	 */
	private static function authenticate_node($path, $raw_body_hash) {
		$headers = [];
		foreach (getallheaders() as $name => $value) {
			$headers[str_replace('-', '_', strtolower($name))] = $value;
		}

		$node_id   = (int)($headers['x_joinery_agent_node'] ?? 0);
		$timestamp = (string)($headers['x_joinery_agent_timestamp'] ?? '');
		$nonce     = (string)($headers['x_joinery_agent_nonce'] ?? '');
		$signature = base64_decode((string)($headers['x_joinery_agent_signature'] ?? ''), true);

		if ($node_id <= 0 || $timestamp === '' || $nonce === '' || $signature === false) {
			api_error('This endpoint requires a signed agent request.', 'AuthenticationError', 401);
		}
		if (strlen($nonce) > 64 || strlen($timestamp) > 20) {
			api_error('Malformed signature headers.', 'AuthenticationError', 401);
		}
		if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			api_error('Malformed request signature.', 'AuthenticationError', 401);
		}

		// A clock bound is what stands in for a nonce store: a captured request
		// is replayable only inside this window, and both endpoints are no-ops
		// on replay — a re-claim takes this node's own next job, and a repeated
		// result lands on a job that is no longer running and is refused below.
		if (!ctype_digit($timestamp)
			|| abs(time() - (int)$timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
			api_error('That request is too far from this plane\'s clock. Check the node\'s time.',
				'AuthenticationError', 401);
		}

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) {
			api_error('Unknown node.', 'AuthenticationError', 401);
		}
		if ($node->get('mgn_delete_time')) {
			api_error('Unknown node.', 'AuthenticationError', 401);
		}

		$stored_key = (string)$node->get('mgn_agent_public_key');
		if ($stored_key === '') {
			api_error('This node has not paired an agent with this plane.', 'AuthenticationError', 401);
		}
		$public_key = base64_decode($stored_key, true);
		if ($public_key === false || strlen($public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			api_error('This node\'s stored agent key is unusable. Re-pair the agent.',
				'AuthenticationError', 401);
		}

		$message = "joinery-agent-v1\nPOST\n{$path}\n{$node_id}\n{$timestamp}\n{$nonce}\n{$raw_body_hash}";
		if (!sodium_crypto_sign_verify_detached($signature, $message, $public_key)) {
			api_error('That request did not verify against this node\'s agent key.',
				'AuthenticationError', 401);
		}

		return $node;
	}

	/** The body hash the node signed. Recomputed from exactly what arrived. */
	private static function body_hash() {
		return hash('sha256', (string)self::$raw_body);
	}

	// ==================================================================
	// Claim
	// ==================================================================

	private static function handle_claim($body) {
		$node = self::authenticate_node('/api/v1/agent/claim', self::body_hash());

		$in = self::validate($body, ['node_id' => ['type' => 'int', 'required' => true]]);
		if ((int)$in['node_id'] !== (int)$node->key) {
			api_error('The signed identity and the stated node do not match.', 'AuthenticationError', 401);
		}

		// A poll IS the heartbeat: it is the only liveness signal this plane can
		// see for itself, and it costs nothing to record here (§3.1).
		$node->set('mgn_agent_last_poll', gmdate('Y-m-d H:i:s'));
		$node->save();

		// A claim that never came back would otherwise hold this node's
		// concurrency lock forever. Swept on every poll — scoped to this node,
		// because the lock this poll cares about is this node's — so an agent
		// that crashed heals the moment it comes back. The fleet-wide sweep on
		// the scheduled pass is what covers an agent that never returns.
		ManagementJob::requeueStaleClaims((int)$node->key);

		$job = self::claim_next_job_for((int)$node->key);
		if (!$job) {
			api_success(['job' => null], '', 200);
		}

		$commands = $job->get('mjb_commands');
		if (is_string($commands)) {
			$commands = json_decode($commands, true);
		}

		// Credential slots resolve HERE, at hand-out — not at build. The job
		// row at rest carries only the placeholder (__SM_CREDS_<id>__), the
		// same property the SSH executor's in-memory substitution gives it;
		// the real credential exists only inside this signed HTTPS response.
		// A slot that cannot be resolved fails the job visibly — a placeholder
		// must never reach a node, where it would be refused as a malformed
		// credential and read as a node-side fault.
		$params = $commands['params'] ?? null;
		try {
			$params = self::resolve_credential_slots($params);
		} catch (\Throwable $e) {
			$job->set('mjb_status', 'failed');
			$job->set('mjb_error_message',
				'Credential slot could not be resolved at dispatch: ' . $e->getMessage());
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->save();
			api_success(['job' => null], '', 200);
		}

		$payload = [
			'job_id'    => (int)$job->key,
			'node_id'   => (int)$node->key,
			'primitive' => (string)($commands['primitive'] ?? ''),
			'params'    => self::params_as_object($params),
			'issued_at' => gmdate('c'),
		];

		// The plane must not hand a node something the node will refuse for
		// size — that would be a job that dies quietly on arrival.
		$encoded = json_encode(['api_version' => '1.0', 'data' => ['job' => $payload]]);
		if (strlen($encoded) > self::MAX_JOB_BODY) {
			$job->set('mjb_status', 'failed');
			$job->set('mjb_error_message',
				'This job is larger than the ' . self::MAX_JOB_BODY . '-byte limit an agent will read, '
				. 'so it was never dispatched.');
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->save();
			api_success(['job' => null], '', 200);
		}

		api_success(['job' => $payload], '', 200);
	}

	/**
	 * Render a job's params as a JSON OBJECT, always.
	 *
	 * PHP decodes {} into an empty array and re-encodes an empty array as [],
	 * so a parameterless job would arrive at the node as a JSON list where its
	 * validator expects an object — and be refused for being the wrong shape
	 * rather than run. The asymmetry is PHP's, so it is corrected on PHP's side
	 * of the wire rather than by teaching the node to accept two shapes.
	 */
	/**
	 * Replace credential placeholder values in a primitive job's params with
	 * base64(json(credentials)) for the named backup target — the same shape
	 * the agent's SSH-path resolver splices (creds.go), produced from the same
	 * stored slots. The slot the token names is the only slot consulted: a job
	 * built against __SM_NODE_CREDS_ never falls back to the main credential,
	 * so an emptied slot fails visibly rather than running with a more
	 * powerful credential than intended.
	 */
	private static function resolve_credential_slots($params) {
		if (!is_array($params)) {
			return $params;
		}
		foreach ($params as $key => $value) {
			if (!is_string($value) || !preg_match('/^__SM_(NODE_)?CREDS_(\d+)__$/', $value, $m)) {
				continue;
			}
			$target = new BackupTarget((int)$m[2], TRUE);
			if (!$target->key) {
				throw new Exception('backup target ' . (int)$m[2] . ' does not exist');
			}
			$creds = ($m[1] === 'NODE_') ? $target->get_node_credentials() : $target->get_credentials();
			if (empty($creds)) {
				throw new Exception('backup target "' . $target->get('bkt_name')
					. '" has no credentials in the slot this job names');
			}
			$params[$key] = base64_encode(json_encode($creds));
		}
		return $params;
	}

	private static function params_as_object($params) {
		if (!is_array($params) || $params === []) {
			return new stdClass();
		}
		if (array_is_list($params)) {
			// A list is not a parameter object at all; a job carrying one is
			// malformed, and sending {} is the honest rendering of "no usable
			// parameters" — the node then refuses anything the primitive
			// actually required.
			return new stdClass();
		}
		return (object)$params;
	}

	/**
	 * Claim the oldest pending primitive job for THIS node.
	 *
	 * The node id is the one the signature proved. Selection honours the same
	 * per-node concurrency rule the local job source uses: one job at a time.
	 */
	private static function claim_next_job_for($node_id) {
		$db = DbConnector::get_instance()->get_db_link();

		$running = $db->prepare(
			"SELECT count(*) FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_status = 'running' AND mjb_delete_time IS NULL"
		);
		$running->execute([$node_id]);
		if ((int)$running->fetchColumn() > 0) {
			return null;
		}

		$q = $db->prepare(
			"SELECT mjb_id FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ?
			   AND mjb_status = 'pending'
			   AND mjb_delete_time IS NULL
			   AND jsonb_exists(mjb_commands, 'primitive')
			 ORDER BY mjb_id ASC LIMIT 1"
		);
		$q->execute([$node_id]);
		$job_id = $q->fetchColumn();
		if (!$job_id) {
			return null;
		}

		// Atomic claim: the WHERE clause is the lock. A second poller loses the
		// race and is told there is nothing, which is true for it.
		$claim = $db->prepare(
			"UPDATE mjb_management_jobs
			 SET mjb_status = 'running',
			     mjb_started_time = now(),
			     mjb_claim_attempts = COALESCE(mjb_claim_attempts, 0) + 1,
			     mjb_update_time = now()
			 WHERE mjb_id = ? AND mjb_status = 'pending' AND mjb_mgn_node_id = ?"
		);
		$claim->execute([$job_id, $node_id]);
		if ($claim->rowCount() === 0) {
			return null;
		}

		return new ManagementJob($job_id, TRUE);
	}

	// ==================================================================
	// Result
	// ==================================================================

	private static function handle_result($body) {
		$node = self::authenticate_node('/api/v1/agent/result', self::body_hash());

		$in = self::validate($body, [
			'node_id'         => ['type' => 'int',    'required' => true],
			'job_id'          => ['type' => 'int',    'required' => true],
			'status'          => ['type' => 'string', 'required' => true, 'max' => 16],
			'data'            => ['type' => 'object'],
			'log'             => ['type' => 'string', 'max' => self::MAX_LOG_BYTES],
			'log_truncated'   => ['type' => 'bool'],
			'log_total_bytes' => ['type' => 'int'],
			'refusal_reason'  => ['type' => 'string', 'max' => 2048],
		]);

		if ((int)$in['node_id'] !== (int)$node->key) {
			api_error('The signed identity and the stated node do not match.', 'AuthenticationError', 401);
		}
		if (!in_array($in['status'], ManagementJob::AGENT_OUTCOMES, true)) {
			api_error('Result status must be completed, failed or refused.', 'ValidationError', 400);
		}

		$job = self::load_running_job((int)$in['job_id'], (int)$node->key);

		// The envelope is CONSTRUCTED here, by re-encoding the object that just
		// passed validation. The node never supplies a string this plane stores
		// verbatim and later parses as its own — the only shape that reaches
		// mjb_output is one this file built.
		$output = '';
		if (!empty($in['data'])) {
			$output .= json_encode(['api_version' => '1.0', 'data' => $in['data']]) . "\n";
		}
		if (!empty($in['log'])) {
			$output .= "\n=== Agent log ===\n" . SmSecretRedactor::redact(substr($in['log'], 0, self::MAX_LOG_BYTES)) . "\n";
		}
		if (!empty($in['log_truncated'])) {
			$output .= '[Log truncated by the agent — ' . (int)($in['log_total_bytes'] ?? 0)
				. " bytes were produced.]\n";
		}

		$job->set('mjb_output', $output);
		$job->set('mjb_current_step', 1);
		$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
		// What the node actually said, kept verbatim and separately from
		// mjb_status. A refusal is a terminal failure to every dashboard, and
		// also a distinct fact worth counting on its own.
		$job->set('mjb_agent_outcome', $in['status']);

		if ($in['status'] === 'completed') {
			$job->set('mjb_status', 'completed');
		} else {
			// A refusal is a decision the node made, not a fault, and it is
			// recorded as such in the message. It shares the 'failed' status
			// because every consumer of a terminal job already understands that
			// one, and inventing a fourth status would quietly change the
			// meaning of every status filter on the dashboard.
			$job->set('mjb_status', 'failed');
			$reason = trim((string)($in['refusal_reason'] ?? ''));
			$job->set('mjb_error_message', $in['status'] === 'refused'
				? 'Refused by the node: ' . ($reason !== '' ? $reason : 'no reason given')
				: ($reason !== '' ? $reason : 'The primitive failed on the node.'));
		}
		$job->save();

		api_success(['recorded' => true], '', 200);
	}

	// ==================================================================
	// Leave
	// ==================================================================

	/**
	 * The node ending the pairing from its own side. Signed — only the key
	 * holder can say goodbye, or anyone could disconnect anyone — and the
	 * effect is exactly what the plane-side Disconnect does. Leaving is
	 * unilateral: an agent that cannot reach this endpoint deletes its
	 * identity anyway, and this plane just sees it go silent until someone
	 * disconnects the node here too.
	 */
	private static function handle_leave($body) {
		$node = self::authenticate_node('/api/v1/agent/leave', self::body_hash());

		$in = self::validate($body, ['node_id' => ['type' => 'int', 'required' => true]]);
		if ((int)$in['node_id'] !== (int)$node->key) {
			api_error('The signed identity and the stated node do not match.', 'AuthenticationError', 401);
		}

		self::forgetAgent($node);
		api_success(['left' => true], '', 200);
	}

	/**
	 * Forget a node's agent: the pairing ends and the node's work routes over
	 * the API and SSH again. The one place both endings converge — the
	 * plane-side Disconnect action and the node-initiated leave do exactly
	 * this, so they cannot drift apart.
	 */
	/**
	 * A node saying it is about to stop, because an operator switched its agent
	 * off. Not a leave: the pairing stands, the key stays, and the node is
	 * expected back — this only stops silence from being read as breakage.
	 *
	 * Without it a switched-off node and a dead one look identical from here:
	 * both simply stop polling. The dashboard needs to tell an operator which
	 * one they are looking at, and only the node knows.
	 *
	 * The acknowledgement matters as much as the record. The agent stops whether
	 * or not this endpoint answers, but it keeps trying inside a bounded window
	 * and replays later if it never lands — so what it needs back is a definite
	 * "recorded", not merely a response from something at this address.
	 */
	private static function handle_quiet($body) {
		$node = self::authenticate_node('/api/v1/agent/quiet', self::body_hash());
		$in = self::validate($body, [
			'node_id'    => ['type' => 'int', 'required' => true],
			'quiet_time' => ['type' => 'string', 'max' => 32],
		]);
		if ((int)$in['node_id'] !== (int)$node->key) {
			api_error('The signed identity and the stated node do not match.', 'AuthenticationError', 401);
		}

		// The node's own clock, because the node is the one that knows when it
		// stopped — a replayed goodbye can arrive long after the fact, and
		// stamping it on arrival would date the silence to the wrong moment.
		// Bounded to something sane so a bad clock cannot park a node's status
		// in the future forever.
		$quiet_time = trim((string)($in['quiet_time'] ?? ''));
		$now = gmdate('Y-m-d H:i:s');
		if ($quiet_time === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $quiet_time)
			|| $quiet_time > $now) {
			$quiet_time = $now;
		}

		$node->set('mgn_agent_quiet_time', $quiet_time);
		$node->save();

		api_success(['acknowledged' => true, 'quiet_time' => $quiet_time], '', 200);
	}

	public static function forgetAgent($node) {
		$node->set('mgn_agent_public_key', null);
		$node->set('mgn_agent_paired_time', null);
		$node->save();
	}

	/** Load a job that is genuinely this node's, genuinely a primitive job, and genuinely running. */
	private static function load_running_job($job_id, $node_id) {
		try {
			$job = new ManagementJob($job_id, TRUE);
		} catch (Exception $e) {
			api_error('No such job.', 'ActionError', 404);
		}
		if ((int)$job->get('mjb_mgn_node_id') !== $node_id || $job->get('mjb_delete_time')) {
			api_error('No such job.', 'ActionError', 404);
		}
		if (!$job->isPrimitiveJob()) {
			api_error('That job is not an agent primitive job.', 'ActionError', 409);
		}
		if ($job->get('mjb_status') !== 'running') {
			// A replayed result lands here: the job is already terminal, so
			// there is nothing to write and nothing to corrupt.
			api_error('That job is not currently claimed by this node.', 'ActionError', 409);
		}
		return $job;
	}
}
