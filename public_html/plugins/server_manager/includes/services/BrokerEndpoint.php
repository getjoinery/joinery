<?php
/**
 * BrokerEndpoint — where a Managed node's backup run asks the management node
 * for signed links (specs/storage_targets.md WP5).
 *
 *   POST /api/v1/broker/begin    {run_id}                          where the run writes
 *   POST /api/v1/broker/sign     {run_id, name, operation, ...}    one write's link, or 'exists'
 *   POST /api/v1/broker/finish   {run_id, completed, chain}        the objects it completed, with hashes
 *   POST /api/v1/broker/abort    {run_id, cause}                   the run failed
 *
 * Dispatched by apiv1.php BEFORE key authentication, as the agent channel and
 * the relay birth channel are: the credential is the run's token, carried in
 * the X-Joinery-Broker-Token header, never an API key. NodeBroker::forToken()
 * gives one answer for every wrong token. Metered in its own bucket, per
 * address and sized for a fleet behind one address: a run signs one link per
 * object it stores, and a batch of part links per ten parts of a large one.
 *
 * @version 1.1 - finish and abort reach a run already closed, so a node asking again after a lost reply is
 *                answered; a space that cannot be reached is a refusal, not a server error
 * @version 1.0
 */

class BrokerEndpoint {

	const RATE_LIMIT_REQUESTS = 30000;
	const RATE_LIMIT_WINDOW   = 3600;

	/** The largest request body read: a finish names every object of a run. */
	const MAX_BODY_BYTES = 8388608;

	/**
	 * The HTTP shell. Always exits.
	 * @param string[] $url_segments ['api','v1','broker',<action>]
	 */
	public static function dispatchPreAuth(array $url_segments) {
		$action = strtolower($url_segments[3] ?? '');
		register_shutdown_function(function () use ($action) {
			$code = http_response_code();
			$code = is_int($code) ? $code : 200;
			RequestLogger::log('api_broker', substr(preg_replace('/[^a-z_]/', '', $action) ?: '(none)', 0, 40),
				$code < 400, ['status_code' => $code]);
		});
		if (!RequestLogger::check_rate_limit('api_broker', self::RATE_LIMIT_REQUESTS, self::RATE_LIMIT_WINDOW)) {
			api_error('Backup broker rate limit exceeded.', 'RateLimitError', 429);
		}
		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			api_error('The backup broker is called with POST.', 'ActionError', 405);
		}
		$raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
		if (strlen($raw) > self::MAX_BODY_BYTES) {
			api_error('That request is too large.', 'ActionError', 413);
		}
		$body = json_decode($raw, true);
		if (!is_array($body)) {
			api_error('The backup broker takes a JSON object.', 'ActionError', 400);
		}
		$result = self::handle($action, $body, self::headerValue(NodeBroker::TOKEN_HEADER));
		if ($result['status'] !== 200) {
			api_error($result['error'], $result['status'] === 403 ? 'AuthenticationError' : 'ActionError', $result['status']);
		}
		api_success($result['data']);
		exit;
	}

	/**
	 * One call, as a decision: ['status' => 200, 'data' => …] or
	 * ['status' => 4xx, 'error' => …]. Public so it is testable without HTTP.
	 */
	public static function handle(string $action, array $body, string $token): array {
		if (!in_array($action, array('begin', 'sign', 'finish', 'abort'), true)) {
			return array('status' => 404, 'error' => 'Unknown backup broker call.');
		}
		// finish and abort are answered for a closed run too: the node asks
		// again when the reply to the first was lost.
		$states = in_array($action, array('finish', 'abort'), true)
			? array(ShelfRun::STATE_OPEN, ShelfRun::STATE_FINISHED, ShelfRun::STATE_ABORTED)
			: array(ShelfRun::STATE_OPEN);
		$run = NodeBroker::forToken((int)($body['run_id'] ?? 0), $token, $states);
		if ($run === null) {
			return array('status' => 403, 'error' => 'No open run for this token.');
		}
		try {
			switch ($action) {
				case 'begin':
					$data = NodeBroker::begin($run);
					break;
				case 'sign':
					$data = NodeBroker::sign($run, (string)($body['name'] ?? ''), trim((string)($body['operation'] ?? '')), array(
						'bytes'     => (int)($body['bytes'] ?? 0),
						'upload_id' => (string)($body['upload_id'] ?? ''),
						'first'     => (int)($body['first'] ?? 1),
						'count'     => (int)($body['count'] ?? ShelfBroker::PARTS_PER_BATCH),
					));
					break;
				case 'finish':
					$completed = $body['completed'] ?? array();
					$data = NodeBroker::finish($run, is_array($completed) ? $completed : array(), (string)($body['chain'] ?? ''));
					break;
				default:
					$data = array('cancelled' => NodeBroker::abort($run, (string)($body['cause'] ?? '')));
			}
		} catch (ShelfBrokerException $e) {
			return array('status' => 409, 'error' => $e->getMessage());
		} catch (S3SignerException $e) {
			return array('status' => 409, 'error' => 'Backup storage could not sign that request: ' . $e->getMessage());
		} catch (ShelfRunException $e) {
			return array('status' => 409, 'error' => $e->getMessage());
		} catch (StorageSpaceException $e) {
			return array('status' => 409, 'error' => 'This run\'s backup storage cannot be reached: ' . $e->getMessage());
		}
		return array('status' => 200, 'data' => $data);
	}

	private static function headerValue(string $name): string {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		if (isset($_SERVER[$key])) {
			return (string)$_SERVER[$key];
		}
		foreach (function_exists('getallheaders') ? getallheaders() : array() as $h => $v) {
			if (strcasecmp($h, $name) === 0) {
				return (string)$v;
			}
		}
		return '';
	}
}
