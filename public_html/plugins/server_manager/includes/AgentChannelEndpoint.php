<?php
/**
 * AgentChannelEndpoint — the plane's side of the agent channel.
 *
 * A node's agent polls this plane outbound over HTTPS, claims one primitive
 * job at a time, and posts the result back (specs/agent_on_node_architecture.md
 * §3.1, component D).
 *
 * Seven endpoints, all POST, all under /api/v1/agent/:
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
 *   artifact     signed; the bytes of the agent's next binary, and of the
 *                signed support bundle. A DELIVERY ROUTE, NOT A TRUST CHANGE —
 *                see handle_artifact()
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
 * @version 1.11 - a join records the client address the plane can vouch for (SessionControl::get_client_ip
 *                 for auth: the Cloudflare header only from a verified edge), so a join from a
 *                 provisioned machine can be matched to its instance and checked with the provider
 * @version 1.10 - the channel fills the api_agent bucket it is checked against: one request-log
 *                 row per request at shutdown, carrying the outcome, for everything except a
 *                 successful claim (the steady-state poll). The bucket had no writer, so the
 *                 limit in apiv1.php could never fire
 * @version 1.9 - a claim carries the node's own vocabulary and bundle version, and the plane
 *                records both. A version number is a GUESS about what a node can do; the node's
 *                own list is not (the first apply_update rollout guessed, and nine agents refused)
 * @version 1.8 - artifact endpoint: a machine with no site tree fetches its agent binary and its
 *                signed support bundle over the channel it already polls. The plane serves bytes
 *                it cannot sign — verification stays in the agent, against its baked-in key
 * @version 1.7 - a claim carries the agent's version and the plane records it, so the column
 *                tracks what is running instead of what first paired; and a completed result is
 *                folded into the node's columns on arrival rather than at the next page view
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

	/**
	 * The vocabulary a node may report on a claim. Generous against the real
	 * number (a few dozen names of a few dozen characters) and bounded anyway:
	 * everything a node sends is attacker-controllable, including a list whose
	 * only job is to be informative.
	 */
	const MAX_VOCABULARY_BYTES = 4096;
	const MAX_VOCABULARY_NAMES = 200;

	/**
	 * What the artifact endpoint may be asked for. A flat, closed set — a node
	 * names one of these and nothing else, so nothing it sends is ever resolved
	 * as a path on this plane.
	 */
	const ARTIFACT_KINDS = ['agent_manifest', 'agent_binary', 'bundle_manifest', 'bundle_body'];

	/** Chunk size for streaming an artifact out. Bounds this plane's memory, not the transfer. */
	const ARTIFACT_CHUNK_BYTES = 262144;

	/** How far a signed request's clock may be from ours. */
	const MAX_CLOCK_SKEW_SECONDS = 300;

	/** Suggested poll cadence. The agent clamps it to its own compiled range. */
	const SUGGESTED_POLL_INTERVAL = 15;

	/**
	 * Dispatch a /api/v1/agent/* request. Always exits.
	 */
	public static function dispatchPreAuth($url_segments) {
		$endpoint = strtolower($url_segments[3] ?? '');

		// Meter the channel. apiv1.php refuses when the api_agent bucket is
		// over its limit, and this is what fills the bucket — recorded at
		// shutdown so the one row per request carries the outcome, whichever
		// api_error()/api_success() exit ended it. The healthy poll is the one
		// request left out: see meterOutcome().
		register_shutdown_function(function () use ($endpoint) {
			$code = http_response_code();
			$code = is_int($code) ? $code : 200;
			if (!self::meterOutcome($endpoint, $code)) {
				return;
			}
			$action = preg_replace('/[^a-z0-9_]/', '', $endpoint);
			RequestLogger::log('api_agent', substr($action !== '' ? $action : '(none)', 0, 40),
				$code < 400, ['status_code' => $code]);
		});

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			api_error('The agent channel accepts POST only.', 'ActionError', 405);
		}

		// Resolve the endpoint BEFORE reading a body. An unknown path is a 404
		// about the path, not a complaint about whatever was sent to it.
		if (!in_array($endpoint, ['join', 'join_status', 'claim', 'result', 'leave', 'quiet', 'artifact'], true)) {
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
			case 'artifact':
				self::handle_artifact($body);
				break;
		}
		exit;
	}

	/**
	 * Does this request count toward the api_agent bucket?
	 *
	 * Everything on the channel counts — joins, results, artifact fetches,
	 * unknown paths, refused signatures — except a claim that succeeded. A
	 * fleet polls on a seconds cadence (SUGGESTED_POLL_INTERVAL), which on a
	 * modest fleet is tens of thousands of requests a day against a few
	 * hundred of everything else, and check_rate_limit() counts rows with a
	 * query. Writing the steady-state poll would swamp the table that every
	 * other rate limit on the platform reads. A claim that FAILED is not
	 * steady state — an unsigned or mis-signed flood looks exactly like that —
	 * so it counts.
	 */
	public static function meterOutcome($endpoint, $status_code) {
		return !($endpoint === 'claim' && (int)$status_code < 400);
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
		// The address approval compares against the provider's record of the
		// machine, so it has to be one this plane can vouch for: behind
		// Cloudflare REMOTE_ADDR is an edge, and the client header is trusted
		// only when the TCP peer is a verified edge (the for-auth mode).
		$request->set('ajr_source_ip', substr((string)SessionControl::get_client_ip(true), 0, 64));
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

		// If the joining machine is a host waiting for its own agent, name it
		// as that host's node now — host-scope routing (decommission_site,
		// certificates, container install) has nothing to address until this
		// link is set, and doing it at approval means no separate manual step.
		// The caller surfaces the returned host so the operator sees it happened.
		return ManagedHost::link_host_node($node);
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

		$in = self::validate($body, [
			'node_id'        => ['type' => 'int', 'required' => true],
			'agent_version'  => ['type' => 'string', 'max' => 20],
			// The node's own account of what it can do. A comma-separated list
			// rather than a JSON array on purpose: it validates under the
			// rules this endpoint already applies to every string — a pattern
			// and a length — and it stores in the same shape it is compared
			// against, so nothing here needs a second encoding to be read back.
			'primitives'     => ['type' => 'string', 'max' => self::MAX_VOCABULARY_BYTES,
			                     'pattern' => '/^[a-z0-9_,]*$/'],
			'bundle_version' => ['type' => 'string', 'max' => 32, 'pattern' => '/^[a-z0-9]*$/'],
		]);
		if ((int)$in['node_id'] !== (int)$node->key) {
			api_error('The signed identity and the stated node do not match.', 'AuthenticationError', 401);
		}

		// A poll IS the heartbeat: it is the only liveness signal this plane can
		// see for itself, and it costs nothing to record here (§3.1).
		$node->set('mgn_agent_last_poll', gmdate('Y-m-d H:i:s'));

		// And the version, because this is the only moment the node speaks for
		// itself about what it is running. It used to be recorded once, at
		// approval, and never again — so an agent that had self-updated several
		// times still read as the version it first paired with. The column was
		// confidently wrong during exactly the operation that consults it: a
		// rollout. Optional on the wire so an older agent still polls fine; it
		// simply keeps reporting nothing and the stale value stands until it
		// updates itself.
		$reported = trim((string)($in['agent_version'] ?? ''));
		if ($reported !== '' && $reported !== (string)$node->get('mgn_agent_version')) {
			$node->set('mgn_agent_version', $reported);
		}

		// And what it can DO, which is a different fact from what it is
		// running. The plane used to derive one from the other, and derivation
		// is a guess: the first apply_update rollout read nine version numbers,
		// inferred a vocabulary that did not exist on any of those agents, and
		// collected nine refusals. Only the node knows what its binary compiled
		// in, so only the node says it.
		//
		// Absent is meaningful and is left alone: an agent at 1.10.0 or earlier
		// never reports, and its empty column is what keeps
		// PRIMITIVE_MIN_AGENT_VERSION a live fallback instead of dead code.
		if (array_key_exists('primitives', $in)) {
			$vocabulary = self::normalised_vocabulary($in['primitives']);
			if ($vocabulary !== (string)$node->get('mgn_agent_primitives')) {
				$node->set('mgn_agent_primitives', $vocabulary);
			}
		}
		if (array_key_exists('bundle_version', $in)
			&& (string)$in['bundle_version'] !== (string)$node->get('mgn_agent_bundle_version')) {
			$node->set('mgn_agent_bundle_version', (string)$in['bundle_version']);
		}
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

		// Fold the result into the node's own columns NOW, rather than leaving it
		// for whoever next opens a page.
		//
		// Every other caller of the processor is a page view or a scheduled pass,
		// so a completed job sat unprocessed until someone looked — and the node
		// columns it feeds (mgn_joinery_version, mgn_last_status_data, the SSL
		// state) went on reporting the previous answer. Eighteen check_status jobs
		// completed over one night's rollout while the dashboard still showed the
		// version from three releases earlier, which is the worst possible moment
		// for it to be wrong.
		//
		// Never fatal: the node has done its part and been told so. A plane-side
		// folding error is this plane's problem to log, not a reason to make the
		// node believe its result was rejected and send it again.
		if ($job->get('mjb_status') === 'completed') {
			try {
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
				if (in_array((string)$job->get('mjb_job_type'), JobResultProcessor::processable_types(), true)) {
					JobResultProcessor::process($job);
				}
			} catch (Throwable $e) {
				error_log('AgentChannelEndpoint: could not process result for job '
					. $job->key . ': ' . $e->getMessage());
			}
		}

		api_success(['recorded' => true], '', 200);
	}

	/**
	 * Reduce a reported vocabulary to names this plane will store.
	 *
	 * Every name is re-validated against the same shape the agent's own
	 * registry enforces, duplicates are dropped and the list is sorted, so what
	 * lands in the column is a canonical set rather than whatever arrived. That
	 * matters twice: it is compared against the stored value on every poll (a
	 * re-ordered list must not read as a change), and it is what has_primitive()
	 * consults, so a name that could not be a primitive must never get in.
	 */
	public static function normalised_vocabulary($reported) {
		$names = [];
		foreach (explode(',', (string)$reported) as $name) {
			$name = trim($name);
			if ($name === '' || !preg_match('/^[a-z][a-z0-9_]{2,39}$/', $name)) {
				continue;
			}
			$names[$name] = true;
			if (count($names) >= self::MAX_VOCABULARY_NAMES) {
				break;
			}
		}
		ksort($names);
		return implode(',', array_keys($names));
	}

	// ==================================================================
	// Artifact
	// ==================================================================

	/**
	 * Hand a machine the bytes of its next agent binary, or of the signed
	 * support bundle.
	 *
	 * WHY THIS IS NOT A TRUST CHANGE, and it is worth saying plainly because
	 * "the agent downloads its own binary from the management node" reads
	 * alarming until you notice what this plane cannot do: IT CANNOT SIGN ONE.
	 * The release key is not here. The agent verifies every artifact against an
	 * Ed25519 public key compiled into its own binary at build time, and the
	 * support bundle against the signature the bundle itself carries — neither
	 * check makes a network call, and neither can be satisfied by anything this
	 * plane produces. So a compromised plane serving a hostile binary gets a
	 * refusal and a recorded rejection, exactly as it would from a hostile file
	 * dropped in a node's own agent_dist. This endpoint is a DELIVERY ROUTE for
	 * bytes that were always verified on arrival.
	 *
	 * What it exists for: a machine with no Joinery site — a mail relay, a
	 * Docker host — never receives a platform release, so nothing ever delivers
	 * an artifact to it. Before this it held whatever version it was installed
	 * with, for ever, and could run no script primitive at all.
	 *
	 * WHO IS HOSTILE: still the node (§3.5.8). It names a KIND from a closed set
	 * and, for a binary, an ARCHITECTURE matched against a pattern — never a
	 * file, never a path, never a version. This plane resolves what to send from
	 * its own manifest, so there is no shape in which a node's request selects a
	 * file of its choosing. Body fetches are metered on their own bucket
	 * because they are the expensive ones, and each is recorded: a read is
	 * loud (§3.5.6).
	 */
	private static function handle_artifact($body) {
		$node = self::authenticate_node('/api/v1/agent/artifact', self::body_hash());

		$in = self::validate($body, [
			'node_id'  => ['type' => 'int', 'required' => true],
			'kind'     => ['type' => 'string', 'required' => true, 'max' => 32],
			// The architecture this build runs on. Matched, not interpolated —
			// it selects a manifest entry and never reaches the filesystem.
			'platform' => ['type' => 'string', 'max' => 32, 'pattern' => '/^linux-[a-z0-9]{3,12}$/'],
		]);
		if ((int)$in['node_id'] !== (int)$node->key) {
			api_error('The signed identity and the stated node do not match.', 'AuthenticationError', 401);
		}

		$kind = (string)$in['kind'];
		if (!in_array($kind, self::ARTIFACT_KINDS, true)) {
			api_error('Unknown artifact kind.', 'ValidationError', 400);
		}

		$dist_dir = PathHelper::getIncludePath('agent_dist');

		switch ($kind) {
			case 'agent_manifest':
				self::serve_agent_manifest($dist_dir);
				break;
			case 'agent_binary':
				self::meter_artifact_body($node, $kind);
				self::serve_agent_binary($dist_dir, (string)($in['platform'] ?? ''));
				break;
			case 'bundle_manifest':
				self::serve_bundle_manifest($dist_dir);
				break;
			case 'bundle_body':
				self::meter_artifact_body($node, $kind);
				self::serve_bundle_body($dist_dir);
				break;
		}
	}

	/**
	 * Meter and record one body fetch.
	 *
	 * The manifest fetches are cheap and happen on a minutes clock; the bodies
	 * are megabytes and should happen about once per release. So the bucket is
	 * on the bodies, sized for a fleet of machines behind one address rather
	 * than for one machine — RequestLogger counts per IP, and a NAT'd fleet
	 * shares an address.
	 *
	 * The row is written whether or not the fetch is allowed, which is what
	 * makes the bucket fill at all and what makes abnormal volume visible after
	 * the fact rather than only in the moment.
	 */
	private static function meter_artifact_body($node, $kind) {
		RequestLogger::log('api_agent_artifact', $kind . ' node ' . (int)$node->key, true);

		$settings = Globalvars::get_instance();
		$limit  = (int)($settings->get_setting('api_agent_artifact_rate_limit_requests') ?: 240);
		$window = (int)($settings->get_setting('api_agent_artifact_rate_limit_window') ?: 3600);
		if (!RequestLogger::check_rate_limit('api_agent_artifact', $limit, $window)) {
			api_error('Artifact fetches from this address are over their limit. The agent will retry.',
				'RateLimitError', 429);
		}
	}

	/**
	 * The agent's dist manifest, served as the publisher's own bytes.
	 *
	 * As a STRING rather than a decoded object, so what the agent hashes is
	 * exactly what was published. The agent will not retry a manifest that
	 * failed verification until it changes, and "changed" has to mean the
	 * publisher's bytes changed rather than a re-encoding of them did.
	 */
	private static function serve_agent_manifest($dist_dir) {
		$raw = @file_get_contents($dist_dir . '/manifest.json');
		if ($raw === false) {
			// This plane has published no agent. Not an error: it is what a
			// plane that has never run a publish looks like, and the agent
			// treats it the same way it treats an absent directory.
			api_success(['manifest' => ''], '', 200);
		}
		if (strlen($raw) > self::MAX_JOB_BODY) {
			api_error('This plane\'s agent manifest is larger than an agent will read.', 'ActionError', 500);
		}
		api_success(['manifest' => $raw], '', 200);
	}

	/**
	 * The binary for one architecture.
	 *
	 * The node named an architecture; THIS PLANE names the file, out of its own
	 * manifest. That ordering is the whole of the path safety here — a node
	 * that could name a file could name any file, and no amount of validating
	 * the name it sent would be as good as never accepting one.
	 */
	private static function serve_agent_binary($dist_dir, $platform) {
		if ($platform === '') {
			api_error('An agent binary request must name the architecture it is for.', 'ValidationError', 400);
		}
		$manifest = AgentDistPublisher::readManifest($dist_dir);
		if (!$manifest || empty($manifest['binaries'][$platform]['file'])) {
			api_error('This plane has no agent artifact for ' . htmlspecialchars($platform, ENT_QUOTES) . '.',
				'ActionError', 404);
		}
		$file = (string)$manifest['binaries'][$platform]['file'];
		// Our own data, checked anyway: a manifest is written by a publish, and
		// a publish is code. A file name that is not a plain file name here
		// would be a bug that this endpoint turned into an arbitrary read.
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $file)) {
			api_error('This plane\'s agent manifest names an unusable artifact file.', 'ActionError', 500);
		}
		self::stream_artifact($dist_dir . '/' . $file, $file);
	}

	/**
	 * What support bundle this plane has, if any. Small, so it travels in the
	 * ordinary envelope.
	 *
	 * The sha256 here is for SKIPPING A DOWNLOAD, and nothing else. It is not
	 * what makes the bundle trustworthy — the signature inside it is, verified
	 * against a key this plane does not hold. A plane that lies about this hash
	 * costs a machine one wasted transfer; a plane that tampers with the bundle
	 * gets a refusal.
	 */
	private static function serve_bundle_manifest($dist_dir) {
		$info = SupportBundlePublisher::info($dist_dir);
		if (!$info || empty($info['sha256'])
			|| !file_exists($dist_dir . '/' . SupportBundlePublisher::BUNDLE_NAME)) {
			api_success(['available' => false], '', 200);
		}
		api_success([
			'available' => true,
			'version'   => (string)($info['version'] ?? ''),
			'sha256'    => (string)$info['sha256'],
			'bytes'     => (int)($info['bytes'] ?? 0),
		], '', 200);
	}

	private static function serve_bundle_body($dist_dir) {
		$path = $dist_dir . '/' . SupportBundlePublisher::BUNDLE_NAME;
		if (!file_exists($path)) {
			api_error('This plane ships no support bundle.', 'ActionError', 404);
		}
		self::stream_artifact($path, SupportBundlePublisher::BUNDLE_NAME);
	}

	/**
	 * Send a file as bytes, in chunks, and stop.
	 *
	 * In CHUNKS because the alternative is holding a whole agent binary in this
	 * process's memory to hand it to one node — a plane serving a fleet through
	 * a rollout would be doing that several times over, for no reason. Output
	 * buffers are torn down first so the bytes actually leave rather than
	 * accumulating in one.
	 *
	 * This is the one response on the channel that is not a JSON envelope, and
	 * the agent reads it with a different reader for that reason. Errors still
	 * are envelopes, so a refusal reaches the agent in the shape it expects.
	 */
	private static function stream_artifact($path, $download_name) {
		$size = @filesize($path);
		$handle = @fopen($path, 'rb');
		if ($handle === false || $size === false) {
			api_error('That artifact could not be read on this plane.', 'ActionError', 500);
		}

		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		header('Content-Type: application/octet-stream');
		header('Content-Length: ' . $size);
		header('Content-Disposition: attachment; filename="' . $download_name . '"');
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: no-store');

		while (!feof($handle)) {
			$chunk = fread($handle, self::ARTIFACT_CHUNK_BYTES);
			if ($chunk === false) {
				break;
			}
			echo $chunk;
			flush();
		}
		fclose($handle);
		exit;
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
