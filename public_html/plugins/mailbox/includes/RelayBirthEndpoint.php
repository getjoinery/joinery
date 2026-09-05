<?php
/**
 * RelayBirthEndpoint - the two things a relay says to its plane at birth.
 *
 * specs/relay_without_a_shell.md § Birth. A relay is born from first-boot
 * user-data that names this plane, a provisioning run and a one-time token. It
 * calls back twice, and this is where both calls land:
 *
 *   GET  /api/v1/relay/bundle?run=<id>&sha256=<hex>   the run's own copy of the
 *        support bundle, served only to a run in booting|provisioning that
 *        presents its live token and names the hash the plane put in the
 *        user-data. The relay checks the hash again on its side.
 *   POST /api/v1/relay/born                             the signed birth report.
 *        Believed only when the run token is live and unspent, the report's
 *        public_ip equals the address the provider gave AND the report arrived
 *        from it, its signature verifies against the identity key it carries,
 *        and a pinned ping to that address with the reported fingerprint
 *        answers. Then the relay row is written, the map pushed (the gate, not
 *        best effort) and the run marked done. RelayCloudProvisioner owns that
 *        completion; this file is the HTTP shell.
 *
 * Dispatched by apiv1.php BEFORE key authentication, as the agent channel is:
 * the credential is the run token plus a signature, never an API key, and it
 * exists only where the mailbox plugin is active. Metered in its own bucket.
 *
 * Refusals are deliberately short and identical in shape: the token is readable
 * by anything on the box during the boot window, so nothing here may teach a
 * holder of the token which check it failed beyond what its own request shows.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayProtocol.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));

class RelayBirthEndpoint {

	/** The channel's own rate-limit bucket: a relay boots once, so this is small. */
	const RATE_LIMIT_REQUESTS = 300;
	const RATE_LIMIT_WINDOW   = 3600;

	/**
	 * The HTTP shell. Always exits.
	 * @param string[] $url_segments the request path split on '/', ['api','v1','relay',<endpoint>]
	 */
	public static function dispatchPreAuth(array $url_segments) {
		$endpoint = strtolower($url_segments[3] ?? '');
		register_shutdown_function(function () use ($endpoint) {
			$code = http_response_code();
			$code = is_int($code) ? $code : 200;
			RequestLogger::log('api_relay', substr(preg_replace('/[^a-z0-9_]/', '', $endpoint) ?: '(none)', 0, 40),
				$code < 400, ['status_code' => $code]);
		});
		if (!RequestLogger::check_rate_limit('api_relay', self::RATE_LIMIT_REQUESTS, self::RATE_LIMIT_WINDOW)) {
			api_error('Relay channel rate limit exceeded.', 'RateLimitError', 429);
		}

		$token = self::headerValue(RelayProtocol::RUN_TOKEN_HEADER);
		$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
		$remote = (string)SessionControl::get_client_ip(true);

		switch ($endpoint) {
			case 'bundle':
				if ($method !== 'GET') {
					api_error('The bundle is fetched with GET.', 'ActionError', 405);
				}
				$result = self::processBundle((string)($_GET['run'] ?? ''), (string)($_GET['sha256'] ?? ''), $token);
				if ($result['status'] !== 200) {
					api_error($result['error'], 'AuthenticationError', $result['status']);
				}
				self::streamFile($result['path']);
				exit;

			case 'born':
				if ($method !== 'POST') {
					api_error('The birth report is posted.', 'ActionError', 405);
				}
				$raw = (string)file_get_contents('php://input');
				$result = self::processBorn($raw, $token, $remote);
				if ($result['status'] !== 200) {
					api_error($result['error'], 'AuthenticationError', $result['status']);
				}
				api_success($result['data'], 'Relay registered.');
				exit;
		}
		api_error('Unknown relay endpoint.', 'ActionError', 404);
	}

	/**
	 * Resolve the run a token names. Null when the run is not live, the token is
	 * not its live unspent token, or the id is not a run at all - one answer.
	 */
	public static function runForToken(string $run_id, string $token): ?RelayCloudProvision {
		if (!ctype_digit($run_id) || trim($token) === '') {
			return null;
		}
		try {
			$run = new RelayCloudProvision(intval($run_id), TRUE);
		} catch (\Throwable $e) {
			return null;
		}
		if ($run->key === null || !in_array((string)$run->get('rcp_status'), array('booting', 'provisioning'), true)) {
			return null;
		}
		if (!$run->runTokenMatches($token)) {
			return null;
		}
		return $run;
	}

	/**
	 * The bundle fetch, as a pure decision: {status, path} or {status, error}.
	 * The hash the relay names must be the run's recorded hash AND the hash of
	 * the copy on disk, so a copy that was replaced under the run is refused
	 * rather than served.
	 */
	public static function processBundle(string $run_id, string $sha256, string $token): array {
		$run = self::runForToken($run_id, $token);
		if ($run === null) {
			return array('status' => 403, 'error' => 'No live run for this token.');
		}
		$sha256 = strtolower(trim($sha256));
		$recorded = strtolower(trim((string)$run->get('rcp_bundle_sha256')));
		$path = $run->bundlePath();
		if ($sha256 === '' || $recorded === '' || !hash_equals($recorded, $sha256) || !is_file($path)
				|| !hash_equals($recorded, (string)hash_file('sha256', $path))) {
			return array('status' => 409, 'error' => 'The bundle named is not the bundle this run was created against.');
		}
		return array('status' => 200, 'path' => $path);
	}

	/**
	 * The birth report, as a pure decision, so a test can drive it without an
	 * HTTP request. $remote is the address the report arrived from.
	 * @return array{status:int, error?:string, data?:array}
	 */
	public static function processBorn(string $raw_body, string $token, string $remote): array {
		$body = json_decode($raw_body, true);
		if (!is_array($body) || !isset($body['report']) || !is_array($body['report']) || empty($body['signature'])) {
			return array('status' => 400, 'error' => 'Malformed birth report.');
		}
		$report = $body['report'];
		$run_id = (string)($report['run_id'] ?? '');
		$run = self::runForToken($run_id, $token);
		if ($run === null) {
			return array('status' => 403, 'error' => 'No live run for this token.');
		}

		// 1. The address. The report must NAME the address the provider gave
		//    and must ARRIVE from it: the token alone is not enough to be believed.
		$expected_ip = trim((string)$run->get('rcp_instance_ip'));
		$claimed_ip = trim((string)($report['public_ip'] ?? ''));
		if ($expected_ip === '' || $claimed_ip !== $expected_ip || trim($remote) !== $expected_ip) {
			error_log('RelayBirthEndpoint: run ' . $run->key . ' birth report refused - address mismatch (provider '
				. $expected_ip . ', report ' . $claimed_ip . ', from ' . $remote . ')');
			return array('status' => 403, 'error' => 'The report did not come from the relay this run created.');
		}

		// 2. The signature, against the identity key the report itself carries.
		//    Proves whoever posted holds the key whose pin they ask us to trust;
		//    the pinned ping below proves the machine at the address holds it too.
		$public_key = trim((string)($report['identity_public_key'] ?? ''));
		$fingerprint = trim((string)($report['identity_fingerprint'] ?? ''));
		$decoded_key = base64_decode($public_key, true);
		$decoded_pin = base64_decode($fingerprint, true);
		if ($decoded_key === false || strlen($decoded_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
				|| $decoded_pin === false || strlen($decoded_pin) !== 32) {
			return array('status' => 400, 'error' => 'Malformed relay identity.');
		}
		$signed = RelayProtocol::bornSigningBytes($report);
		if (!DirectSigningIdentity::verify($signed, (string)$body['signature'], $public_key)) {
			error_log('RelayBirthEndpoint: run ' . $run->key . ' birth report refused - bad signature');
			return array('status' => 403, 'error' => 'The birth report signature does not verify.');
		}
		// The pin must be the pin OF that key: the SPKI of an Ed25519 key is a
		// fixed 12-byte prefix plus the raw key, so it is computed here rather
		// than trusted.
		if (!hash_equals(self::spkiFingerprint($decoded_key), $fingerprint)) {
			return array('status' => 403, 'error' => 'The identity fingerprint is not the fingerprint of the identity key.');
		}

		// 3. + 4. The pinned ping, the row, the map push as the gate, reverse
		//    DNS, done. RelayCloudProvisioner owns the run and the row.
		try {
			$relay = (new RelayCloudProvisioner())->completeBirth($run, $report);
		} catch (RelayBirthRefused $e) {
			error_log('RelayBirthEndpoint: run ' . $run->key . ' birth refused - ' . $e->getMessage());
			return array('status' => 409, 'error' => $e->getMessage());
		}
		return array('status' => 200, 'data' => array(
			'relay_id' => intval($relay->key),
			'identity_fingerprint' => (string)$relay->get('mrl_identity_fingerprint'),
			'map_version' => intval($relay->get('mrl_map_version')),
		));
	}

	/** base64(SHA-256(SPKI DER)) of a raw Ed25519 public key - curl's pin. */
	public static function spkiFingerprint(string $raw_public_key): string {
		$spki = hex2bin('302a300506032b6570032100') . $raw_public_key;
		return base64_encode(hash('sha256', $spki, true));
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

	private static function streamFile(string $path): void {
		header('Content-Type: application/gzip');
		header('Content-Length: ' . filesize($path));
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		readfile($path);
	}
}
?>
