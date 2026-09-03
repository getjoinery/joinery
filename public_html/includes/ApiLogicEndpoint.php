<?php
/**
 * API Logic Endpoint
 *
 * Dispatches the two HTTP faces of an action's logic function, both opted in by
 * the same metadata companion, {action}_logic_descriptor() (rich metadata
 * including a typed `input` schema):
 *
 *   - Action  — POST /api/v1/action/{name}: runs {action}_logic() and returns
 *               the translated LogicResult. When the metadata declares an
 *               `input` schema, the request body is coerced and validated
 *               against it (DescriptorValidator) before the logic runs —
 *               a hard failure is a 422 ValidationError and the logic never
 *               executes. The logic file's own validation remains the backstop.
 *   - Form    — GET  /api/v1/form/{name}:  builds {action}_logic_form() through
 *               FormWriterV2JSON and returns the JSON form definition. Exposed
 *               iff a metadata companion and _logic_form() both exist.
 *
 * Both faces share one dispatch skeleton: a sessionless request (register,
 * password_reset_1/2) is handled before key authentication — a first-launch
 * client has no credentials yet — while a sessioned request requires key auth
 * and runs under session simulation as the acting user. The two differ only in
 * how a path resolves (POST _logic vs GET _logic_form), the default capability
 * (write vs read), and the terminal step (run logic vs build form).
 *
 * Dispatched from api/apiv1.php in two phases per face: dispatch*PreAuth() before
 * the key-header requirement, dispatch*Authenticated() after $api_user resolves.
 * Uses the api_error()/api_success()/api_translate_logic_result() helpers and
 * api_resolve_logic_path() defined in apiv1.php (single-segment names are core
 * actions via the theme chain; {plugin}/{action} names resolve to a plugin's
 * logic directory).
 *
 * @version 1.5.1
 * @changelog 1.5.1 - Header and boundary-validation comments describe the
 *   descriptor as the only metadata companion (specs/logic_api_descriptor_migration.md).
 * @changelog 1.5.0 - Feature gating (specs/api_action_feature_gate.md): a
 *   descriptor's requires_setting names a setting that must be on for the
 *   action to exist. Enforced in resolveAction()/resolveForm() — before auth
 *   and before the logic runs — as a 403. Closes the gap where serve.php's
 *   check_setting gated a feature's pages while its API actions stayed live.
 * @changelog 1.4.0 - Sealed idempotency cache (specs/implemented/sealed_content_egress.md
 *   § resolved decision 6): a response produced while the process held sealed
 *   content is stored encrypted to that owner — or not at all when more than
 *   one owner was involved. Replay outside the owner's unlock window answers
 *   409 "response not retained" while the key row still suppresses duplicates.
 * @changelog 1.3.0 - Idempotency resolves before boundary validation: a key
 *   reused with a different body is a 409 even when the new body would not
 *   validate (the retry mismatch outranks the validation detail). Claiming
 *   still happens only after validation passes — an invalid request never
 *   claims a key.
 * @changelog 1.2.0 - Descriptor consumption (specs/implemented/descriptor_rest_api_core.md):
 *   metadata resolves from _logic_descriptor() first, _logic_api()
 *   as fallback; a descriptor-declared input schema is validated at the
 *   boundary via DescriptorValidator before the logic runs (422 on failure).
 * @changelog 1.1.0 - Idempotent writes (specs/implemented/api_contract_and_idempotency.md
 *   § Change 2): an authenticated action request carrying an Idempotency-Key
 *   header executes once — a retry with the same key and body replays the
 *   stored response; the same key with a different body or action is a 409.
 *   Sessionless actions and requests without the header are unchanged.
 * @changelog 1.0.0 - Merge of the former ApiActionEndpoint and ApiFormEndpoint:
 *   one class for an action's POST (execute) and GET (form definition) faces,
 *   sharing the dispatch skeleton, requires_session resolution, and ApiAuth.
 */

class ApiLogicEndpoint {

	/**
	 * Whether a request runs under a simulated session. Declared at the top
	 * level of the descriptor; sessioned unless the descriptor says otherwise.
	 * Shared by both the action and form faces.
	 */
	protected static function requiresSession($meta) {
		return $meta['requires_session'] ?? true;
	}

	/**
	 * Refuse an action whose feature is switched off.
	 *
	 * serve.php gates a feature's pages with check_setting; the API face never
	 * passes through serve.php, so without this an action of a disabled feature
	 * stays callable — which is what a descriptor's requires_setting declares
	 * away (specs/api_action_feature_gate.md).
	 *
	 * Called from resolveAction() and resolveForm(), the two points every API
	 * request resolves a descriptor, so it cannot be reached around. Exits via
	 * api_error() when the setting is off.
	 *
	 * 403, not 404: the action exists, and calling it unknown sends a developer
	 * hunting a typo that is not there.
	 */
	protected static function assertSettingEnabled($meta, $action_label) {
		$setting = $meta['requires_setting'] ?? null;
		if ($setting === null || $setting === '') {
			return;
		}

		if (!Globalvars::get_instance()->get_setting($setting)) {
			api_error($action_label . ' is unavailable: the ' . $setting
				. ' setting is off on this instance.', 'ActionError', 403);
		}
	}

	/**
	 * Resolve an action's metadata array from its companion function,
	 * {action}_logic_descriptor(). Returns null when the action has none — that
	 * is what "not exposed to the API" means. Shared by both faces.
	 */
	protected static function resolveMeta($action_name) {
		if (function_exists($action_name . '_logic_descriptor')) {
			return call_user_func($action_name . '_logic_descriptor');
		}
		return null;
	}

	// ====================================================================
	// Action face — POST /api/v1/action/{name}
	// ====================================================================

	/**
	 * Resolve an action path to its metadata and logic function, or exit with
	 * an API error.
	 *
	 * @param array $url_segments ['api','v1','action','{name}'] or
	 *                            ['api','v1','action','{plugin}','{action}']
	 * @return array [$action_label, $meta, $logic_function]
	 */
	protected static function resolveAction($url_segments) {
		if (strtolower($_SERVER['REQUEST_METHOD']) !== 'post') {
			api_error('Actions must use POST method', 'ActionError', 405);
		}

		list($action_label, $action_name) = api_resolve_logic_path($url_segments, 'action');

		// Check for opt-in: the logic file must define a metadata companion,
		// {action_name}_logic_descriptor().
		$logic_function = $action_name . '_logic';
		$meta = self::resolveMeta($action_name);

		if ($meta === null) {
			// The file resolved, so the action exists on this deployment — it
			// just is not exposed. Saying "unknown" here sends the caller
			// looking for a typo that is not there.
			if (function_exists($logic_function)) {
				api_error($action_label . ' exists but is not exposed to the API — add '
					. $action_name . '_logic_descriptor() to its logic file.',
					'ActionError', 404);
			}
			api_error('Unknown action: ' . $action_label, 'ActionError', 404);
		}

		if (!function_exists($logic_function)) {
			api_error('Action is misconfigured: ' . $action_label, 'ActionError', 500);
		}

		self::assertSettingEnabled($meta, $action_label);

		return array($action_label, $meta, $logic_function);
	}

	/**
	 * Pre-authentication dispatch. Executes sessionless actions and exits;
	 * returns for sessioned actions so key authentication continues, after
	 * which dispatchActionAuthenticated() handles the request.
	 */
	public static function dispatchActionPreAuth($url_segments) {
		list($action_label, $meta, $logic_function) = self::resolveAction($url_segments);

		if (self::requiresSession($meta)) {
			return;
		}

		self::executeAction($action_label, $meta, $logic_function, NULL);
	}

	/**
	 * Post-authentication dispatch for sessioned actions. Always exits.
	 */
	public static function dispatchActionAuthenticated($url_segments, $api_entry, $api_user) {
		list($action_label, $meta, $logic_function) = self::resolveAction($url_segments);

		// Authorization: actions are POST (mutating), so they require the write
		// capability by default — equivalent to the historical apk_permission < 2
		// gate. A descriptor may override via its ['auth'] block. A null user
		// permission signals the anonymous browser-session principal —
		// authorize() denies it 401 unless the contract declares allow_guest.
		require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));
		$auth = ($meta['auth'] ?? []) + ['capability' => ApiAuth::CAP_WRITE];
		ApiAuth::authorize($auth, $api_entry,
			$api_user ? $api_user->get('usr_permission') : null, 'Action');

		// Sessionless actions were executed pre-auth; anything reaching here
		// with requires_session=false would be a dispatch-order bug, so run it
		// the same way regardless. An anonymous principal reaches here with
		// $api_user null: the action runs without session simulation and the
		// logic sees the visitor's natural (not-logged-in) session — which is
		// where the state guest actions operate on (e.g. the cart) lives.
		$acting_user = self::requiresSession($meta) ? $api_user : NULL;

		self::executeAction($action_label, $meta, $logic_function, $acting_user, $api_entry);
	}

	/**
	 * Run the logic function and send the translated result. Always exits.
	 *
	 * @param string $action_label Full action name for logs ('{plugin}/{action}' or '{action}')
	 * @param array $meta Metadata from the action's companion function
	 * @param string $logic_function
	 * @param User|null $api_user Acting user for session simulation (null = sessionless)
	 * @param ApiKey|null $api_entry Presented key, when one was (browser sessions: null)
	 */
	protected static function executeAction($action_label, $meta, $logic_function, $api_user, $api_entry = NULL) {
		$user_id = $api_user ? $api_user->key : NULL;
		$raw_input = file_get_contents('php://input');

		// Build parameters from JSON request body or form data
		$get_params = $_GET;
		$json_params = json_decode($raw_input, true);
		$post_params = is_array($json_params) ? $json_params : $_POST;

		// A request body over post_max_size is DISCARDED by PHP before any code
		// runs: $_POST, $_FILES and php://input all arrive empty while
		// Content-Length still reports what was sent. Left alone, the request then
		// fails schema validation on whichever field happens to be checked first,
		// and the caller is told a field is missing that they did in fact supply —
		// which sends them looking in exactly the wrong place. Every action taking a
		// file upload can hit this, so it is caught once, here.
		$content_length = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
		if ($content_length > 0 && empty($_POST) && empty($_FILES) && $raw_input === '') {
			$limit = ini_get('post_max_size');
			RequestLogger::log('api', 'action ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => 413,
				'error_type' => 'RequestTooLarge',
				'note' => 'Content-Length ' . $content_length . ' exceeds post_max_size ' . $limit
			]);
			api_error('That request was too large for this server to accept (limit ' . $limit
				. '). Nothing was received, so nothing was changed.', 'RequestTooLarge', 413);
		}

		// Idempotency, phase 1 (docs/api.md § Contract): resolve the key against
		// stored outcomes FIRST — a replay or conflict exits here, before
		// validation, session simulation, and any side effect. A key reused with
		// a different body is a 409 even when the new body would not validate:
		// the retry mismatch is the more important thing to tell the caller.
		// Returns the resolution context, or null when the request carries no
		// Idempotency-Key (or no credential to scope it to).
		$idem_ctx = self::idempotencyResolve($action_label, $api_user, $api_entry, $raw_input);

		// Boundary validation: when the descriptor declares an input schema,
		// coerce and validate
		// the request against it — an invalid request exits 422 without
		// claiming an Idempotency-Key or simulating a session. Coerced values
		// (typed, defaults applied) overlay the raw ones; fields the schema
		// doesn't declare pass through untouched, so a partial schema never
		// strips input the logic reads. The logic file's own validation
		// remains the backstop.
		if (!empty($meta['input']) && is_array($meta['input'])) {
			require_once(PathHelper::getIncludePath('includes/DescriptorValidator.php'));
			try {
				$coerced = DescriptorValidator::coerce(['input' => $meta['input']],
					array_merge($get_params, $post_params));
			} catch (InvalidArgumentException $e) {
				RequestLogger::log('api', 'action ' . $action_label, false, [
					'user_id' => $user_id,
					'status_code' => 422,
					'error_type' => 'ValidationError',
					'note' => $e->getMessage()
				]);
				api_error($e->getMessage(), 'ValidationError', 422);
			}
			$post_params = array_merge($post_params, $coerced);
		}

		// Idempotency, phase 2: the request is valid, so claim the key now
		// (insert the in-flight row). Returns the row to finalize after the
		// action runs, or null when no key is in play.
		$idem_record = $idem_ctx ? self::idempotencyClaim($idem_ctx, $action_label, $user_id) : null;

		// auth.session_write: this action mutates $_SESSION state (e.g. the
		// cart) and runs on the browser credential, which released the session
		// lock right after reading identity (ApiAuth::authenticateBrowserSession)
		// — re-open so logic-layer writes persist to the store at shutdown.
		// Per-action opt-in, so ordinary actions keep the early lock release.
		// Key-authenticated requests are session-free and unaffected. Re-open
		// BEFORE session simulation: reopening re-reads the store and would
		// otherwise clobber the simulated values.
		$session = SessionControl::get_instance();
		if (!empty($meta['auth']['session_write']) && $api_entry === NULL
			&& !empty($_COOKIE[session_name()])) {
			$session->reopen();
		}

		// Set up session simulation if needed
		if ($api_user) {
			$session->set_api_user($api_user->key, $api_entry ? $api_entry->key : null);
		}

		// Populate $_POST from JSON body so logic files can use !empty($_POST)
		// to detect submission consistently across browser POSTs and JSON API calls.
		$_POST = $post_params;

		// Call the logic function
		require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
		try {
			$result = call_user_func($logic_function, array_merge($get_params, $post_params));
		} catch (Exception $e) {
			RequestLogger::log('api', 'action ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => 422,
				'error_type' => 'ActionError',
				'note' => $e->getMessage()
			]);
			$result = LogicResult::error($e->getMessage());
		} finally {
			// Clean up session simulation. Must be finally, not post-try: with
			// auth.session_write the session is open through logic execution,
			// so an uncaught PHP Error escaping here would otherwise persist
			// the simulated values (permission, api_key_id) into the caller's
			// real web session at shutdown. The Error still propagates (500);
			// finally only guarantees the restore happens first.
			if ($api_user) {
				$session->clear_api_user();
			}
		}

		// Translate LogicResult to API response
		$translated = api_translate_logic_result($result, $action_label);
		$response_ms = isset($GLOBALS['api_start_time'])
			? round((microtime(true) - $GLOBALS['api_start_time']) * 1000) : NULL;

		if ($translated['status_code'] >= 400) {
			RequestLogger::log('api', 'action ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => $translated['status_code'],
				'error_type' => $translated['response']['errortype'] ?? 'ActionError',
				'response_ms' => $response_ms,
				'note' => $translated['response']['error'] ?? ''
			]);
		} else {
			RequestLogger::log('api', 'action ' . $action_label, true, [
				'user_id' => $user_id,
				'status_code' => $translated['status_code'],
				'response_ms' => $response_ms
			]);
		}

		$response_json = json_encode($translated['response']);

		// Store the outcome (success or 422 alike) so a retry with the same
		// Idempotency-Key replays instead of re-executing.
		if ($idem_record !== null) {
			self::idempotencyFinalize($idem_record, $translated['status_code'], $response_json);
		}

		header("Content-Type: application/json");
		http_response_code($translated['status_code']);
		echo $response_json . PHP_EOL;
		exit;
	}

	// ====================================================================
	// Idempotent writes (specs/implemented/api_contract_and_idempotency.md § Change 2)
	// ====================================================================

	/**
	 * The Idempotency-Key header value, or '' when absent. Read directly from
	 * getallheaders() with the same normalization apiv1.php applies (HTTP
	 * header names are case-insensitive; CGI transports collapse - and _).
	 */
	protected static function idempotencyKeyHeader() {
		foreach (getallheaders() as $name => $value) {
			if (str_replace('-', '_', strtolower($name)) === 'idempotency_key') {
				return trim((string) $value);
			}
		}
		return '';
	}

	/**
	 * Resolve an Idempotency-Key request against the stored outcomes — the
	 * read-only phase, run BEFORE boundary validation. Four possible results:
	 *
	 *   - no header, or no credential to scope to (sessionless) → null; the
	 *     action runs exactly as it always has.
	 *   - first sighting of the key → return the context for
	 *     idempotencyClaim() (nothing persisted yet — an invalid request must
	 *     never claim a key).
	 *   - key already finalized with the same action + body → replay the
	 *     stored response verbatim and EXIT.
	 *   - key seen with a different action or body, or the original is still
	 *     in flight → 409 ActionError and EXIT. An in-flight row older than
	 *     5 minutes is treated as an abandoned original (the request died
	 *     before storing its outcome) and is taken over instead of blocking
	 *     the client until the purge.
	 */
	protected static function idempotencyResolve($action_label, $api_user, $api_entry, $raw_input) {
		$raw_key = self::idempotencyKeyHeader();
		if ($raw_key === '') {
			return null;
		}

		require_once(PathHelper::getIncludePath('data/api_idempotency_keys_class.php'));
		$scope = ApiIdempotencyKey::credential_scope($api_entry, $api_user);
		if ($scope === null) {
			return null;
		}

		$user_id = $api_user ? $api_user->key : NULL;
		$key_hash = hash('sha256', $raw_key);
		$body_hash = hash('sha256', (string) $raw_input);
		$ctx = array('key_hash' => $key_hash, 'scope' => $scope, 'body_hash' => $body_hash, 'row' => null);

		$row = ApiIdempotencyKey::find($key_hash, $scope);
		if ($row === null) {
			return $ctx; // fresh key: claim after validation
		}
		$ctx['row'] = self::idempotencyResolveExisting($row, $action_label, $user_id, $body_hash);
		return $ctx;
	}

	/**
	 * Claim the key — the write phase, run after boundary validation passes.
	 * Claiming is insert-first against the (key_hash, credential_scope)
	 * unique pair, so two concurrent originals cannot both execute — the
	 * loser of the race re-reads the winner's row and resolves against it
	 * (which replays or 409s exactly as if the row had existed at resolve
	 * time). Returns the row for idempotencyFinalize().
	 */
	protected static function idempotencyClaim($ctx, $action_label, $user_id) {
		if ($ctx['row'] !== null) {
			return $ctx['row']; // abandoned original taken over at resolve time
		}
		$row = new ApiIdempotencyKey(NULL);
		$row->set('aik_key_hash', $ctx['key_hash']);
		$row->set('aik_credential_scope', $ctx['scope']);
		$row->set('aik_action', $action_label);
		$row->set('aik_body_hash', $ctx['body_hash']);
		try {
			$row->save();
			return $row;
		} catch (Exception $e) {
			// Lost the unique race — a concurrent original claimed the key.
			$row = ApiIdempotencyKey::find($ctx['key_hash'], $ctx['scope']);
			if ($row === null) {
				throw $e; // not the race: a genuine storage failure
			}
		}
		return self::idempotencyResolveExisting($row, $action_label, $user_id, $ctx['body_hash']);
	}

	/**
	 * Resolve against an existing row: conflict → 409 EXIT, finalized same
	 * request → replay EXIT, fresh in-flight original → 409 EXIT, abandoned
	 * in-flight original → returned for takeover.
	 */
	protected static function idempotencyResolveExisting($row, $action_label, $user_id, $body_hash) {
		if ($row->get('aik_action') !== $action_label || $row->get('aik_body_hash') !== $body_hash) {
			RequestLogger::log('api', 'action ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => 409,
				'error_type' => 'ActionError',
				'note' => 'Idempotency-Key reused with a different request'
			]);
			// The marker matters as much as the message. A client that retried a
			// request whose body it cannot reproduce byte for byte is refused
			// identically on every attempt, and without something to read it
			// keeps trying for as long as it is running.
			api_error(
				'This Idempotency-Key was already used with a different request',
				'ActionError',
				409,
				array('reason' => 'idempotency_key_reused')
			);
		}

		$stored_status = $row->get('aik_response_status');
		if ($stored_status === null || $stored_status === '') {
			$stale_cutoff = LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-5 minutes', 'Y-m-d H:i:s');
			if ($row->get('aik_create_time') > $stale_cutoff) {
				RequestLogger::log('api', 'action ' . $action_label, false, [
					'user_id' => $user_id,
					'status_code' => 409,
					'error_type' => 'ActionError',
					'note' => 'Idempotency-Key original still in progress'
				]);
				api_error('The original request with this Idempotency-Key is still in progress', 'ActionError', 409);
			}
			return $row; // abandoned original: take over and execute
		}

		// Replay the stored outcome verbatim — unless the body was sealed to a
		// vault that is not open right now, or was never retained because the
		// original request read more than one person's protected content. Either
		// way the key has still done its real job: the action does not run twice.
		// The client is told to re-issue with a new key rather than handed an
		// empty success it might read as the original response.
		$stored_status = (int) $stored_status;
		$stored_body = self::idempotencyReplayBody($row);
		if ($stored_body === null) {
			RequestLogger::log('api', 'action ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => 409,
				'error_type' => 'ActionError',
				'note' => 'idempotent replay: response not retained'
			]);
			api_error('The original response for this Idempotency-Key is not retained: it was '
				. 'sealed to a vault that is not open right now, or was never stored. '
				. 'The action was NOT repeated. Query the resource for the outcome, retry while '
				. 'the protecting vault is unlocked, or re-issue the request with a new '
				. 'Idempotency-Key to run it again.', 'ActionError', 409);
		}

		RequestLogger::log('api', 'action ' . $action_label, $stored_status < 400, [
			'user_id' => $user_id,
			'status_code' => $stored_status,
			'note' => 'idempotent replay'
		]);
		header("Content-Type: application/json");
		http_response_code($stored_status);
		echo $stored_body . PHP_EOL;
		exit;
	}

	/**
	 * The stored response body a replay may return, or null when there is
	 * nothing to hand back: the body was sealed to a vault that is not open
	 * right now, or was never stored (a hot original that involved more than
	 * one owner). Null means the replay answers "not retained" — the key row
	 * itself still suppresses the duplicate either way.
	 */
	protected static function idempotencyReplayBody($row): ?string {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
		try {
			$body = $row->get('aik_response_body');
		} catch (VaultLockedException $e) {
			return null;
		}
		return ($body === null || $body === '') ? null : (string) $body;
	}

	/**
	 * Store the outcome on the claimed row. A storage failure must not cost
	 * the client its response (the action has already run), so it is logged
	 * and swallowed — the key simply loses replay protection.
	 */
	protected static function idempotencyFinalize($idem_record, $status_code, $response_json) {
		try {
			require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
			$owner_id = SealedEgressGuard::isHot() ? SealedEgressGuard::ownerUserId() : 0;

			// Cold request: the body holds nothing the vault protects, so cache it
			// as it always was.
			if ($owner_id === 0) {
				$idem_record->set('aik_response_status', (int) $status_code);
				$idem_record->set('aik_response_body', $response_json);
				$idem_record->save();
				return;
			}

			// Hot request: the status is stored either way, because that is what
			// makes a retry safe. The body is stored only if it can be sealed —
			// and it cannot when the request read more than one owner's content,
			// since there is then no single person it belongs to.
			$idem_record->set('aik_response_status', (int) $status_code);
			$idem_record->save();
			$vault = null;
			if ($owner_id !== null) {
				require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
				$vault = UserEncryptionVault::loadForUser((int) $owner_id);
			}
			if ($vault === null || !$vault->key) {
				// The outcome is recorded, the body is not. Say so: a retry that
				// gets "not retained" is otherwise hard to account for.
				error_log('ApiLogicEndpoint: idempotency body not retained for key '
					. (int) $idem_record->key . ' — request opened sealed content ('
					. implode(', ', SealedEgressGuard::sources()) . ') and '
					. ($owner_id === null ? 'more than one owner was involved'
					                      : 'user ' . (int) $owner_id . ' has no vault to seal it to') . '.');
				return;
			}
			ApiIdempotencyKey::sealColumns((int) $idem_record->key, $vault,
				array('aik_response_body' => (string) $response_json));
		} catch (Exception $e) {
			error_log('ApiLogicEndpoint: failed to store idempotency outcome: ' . $e->getMessage());
		}
	}

	// ====================================================================
	// Form face — GET /api/v1/form/{name}
	// ====================================================================

	/**
	 * Resolve a form path to its metadata and builder, or exit with an API
	 * error. Both companion functions must exist for the form to be exposed.
	 *
	 * @param array $url_segments ['api','v1','form','{name}'] or
	 *                            ['api','v1','form','{plugin}','{action}']
	 * @return array [$action_label, $action_name, $meta, $form_function]
	 */
	protected static function resolveForm($url_segments) {
		if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
			api_error('Form definitions must use GET method', 'ActionError', 405);
		}

		list($action_label, $action_name) = api_resolve_logic_path($url_segments, 'form');

		$form_function = $action_name . '_logic_form';
		$meta = self::resolveMeta($action_name);

		// Same distinction the action face draws: a form builder that is present
		// but undeclared is a missing descriptor, not a wrong URL.
		if ($meta === null && function_exists($form_function)) {
			api_error($action_label . ' has a form builder but is not exposed to the API — add '
				. $action_name . '_logic_descriptor() to its logic file.',
				'ActionError', 404);
		}

		if ($meta === null || !function_exists($form_function)) {
			api_error('Unknown form: ' . $action_label, 'ActionError', 404);
		}

		self::assertSettingEnabled($meta, $action_label);

		return array($action_label, $action_name, $meta, $form_function);
	}

	/**
	 * Pre-authentication dispatch. Serves sessionless forms and exits; returns
	 * for sessioned forms so key authentication continues, after which
	 * dispatchFormAuthenticated() handles the request.
	 */
	public static function dispatchFormPreAuth($url_segments) {
		list($action_label, $action_name, $meta, $form_function) = self::resolveForm($url_segments);

		if (self::requiresSession($meta)) {
			return;
		}

		self::serveForm($action_label, $action_name, $form_function, null, null);
	}

	/**
	 * Post-authentication dispatch for sessioned forms. Always exits.
	 */
	public static function dispatchFormAuthenticated($url_segments, $api_entry, $api_user) {
		list($action_label, $action_name, $meta, $form_function) = self::resolveForm($url_segments);

		// Fetching a definition is a read — permission 2 is write-only. Default
		// to the read capability (equivalent to the prior apk_permission == 2
		// gate); a descriptor may override via its ['auth'] block.
		require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));
		$auth = ($meta['auth'] ?? []) + ['capability' => ApiAuth::CAP_READ];
		ApiAuth::authorize($auth, $api_entry, $api_user->get('usr_permission'), 'Form');

		// Session simulation so the builder sees the acting user's timezone
		// and session context, exactly as the action face provides
		$session = SessionControl::get_instance();
		$session->set_api_user($api_user->key, $api_entry ? $api_entry->key : null);

		self::serveForm($action_label, $action_name, $form_function, $api_user, $api_user->key);
	}

	/**
	 * Build the definition and send it. Always exits.
	 *
	 * @param string $action_label Full name for logs ('{plugin}/{action}' or '{action}')
	 * @param string $action_name Bare action name; used as the form id
	 * @param string $form_function Builder function name
	 * @param User|null $user Acting user for prefill (null for sessionless)
	 * @param int|null $user_id For request logging
	 */
	protected static function serveForm($action_label, $action_name, $form_function, $user, $user_id) {
		require_once(PathHelper::getIncludePath('includes/FormWriterV2JSON.php'));

		$formwriter = new FormWriterV2JSON($action_name);

		try {
			call_user_func($form_function, $formwriter, $user, $_GET);
			$definition = $formwriter->getDefinition();
		} catch (Exception $e) {
			if ($user_id) {
				SessionControl::get_instance()->clear_api_user();
			}
			RequestLogger::log('api', 'form ' . $action_label, false, [
				'user_id' => $user_id,
				'status_code' => 500,
				'error_type' => 'ActionError',
				'note' => $e->getMessage()
			]);
			api_error('Unable to build form definition (' . $e->getMessage() . ')', 'ActionError', 500);
		}

		if ($user_id) {
			SessionControl::get_instance()->clear_api_user();
		}

		RequestLogger::log('api', 'form ' . $action_label, true, [
			'user_id' => $user_id,
			'status_code' => 200
		]);

		api_success($definition, 'Form definition for \'' . $action_label . '\'');
	}
}

?>
