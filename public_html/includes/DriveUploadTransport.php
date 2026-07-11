<?php
require_once(__DIR__ . '/PathHelper.php');

/**
 * DriveUploadTransport — the raw-body chunk endpoint for Drive uploads
 * (PUT/GET /api/v1/drive_upload/{token}). The inbound twin of the management
 * backups/fetch streamer: it reads its own body and writes its own response,
 * then exits.
 *
 * Sequential chunks only. The client sends Content-Range: bytes <start>-<end>/
 * <total>; <start> must equal the server's received_bytes, else HTTP 409 with
 * {received_bytes} so the client resumes from the right offset (no sparse-range
 * bookkeeping — resume = ask status, continue).
 */
class DriveUploadTransport {

	const READ_CHUNK = 262144; // 256 KiB stream buffer
	const APPEND_LOCK_CLASS = 42001; // pg advisory lock namespace for chunk appends

	/** @var array request-log context (method, user_id) for the outcome log row */
	private static $log_context = null;

	public static function dispatch(array $url_segments, array $auth_data, $method, $api_entry = null) {
		require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));

		$method = strtoupper($method);
		$acting_user = (int)($auth_data['current_user_id'] ?? 0);
		self::$log_context = array('method' => $method, 'user_id' => $acting_user);

		$token = isset($url_segments[3]) ? (string)$url_segments[3] : '';

		if ($acting_user <= 0) {
			self::fail('Authentication required', 'AuthenticationError', 401);
		}

		$up = FileUpload::load_by_token($token);
		if (!$up || (int)$up->get('fup_usr_user_id') !== $acting_user) {
			self::fail('Upload not found', 'NotFound', 404);
		}

		if ($method === 'GET') {
			self::ok(array(
				'received_bytes' => (int)$up->get('fup_received_bytes'),
				'expected_bytes' => (int)$up->get('fup_expected_bytes'),
			));
		}
		if ($method !== 'PUT') {
			self::fail('Method not allowed', 'ActionError', 405);
		}

		// Parse Content-Range: bytes <start>-<end>/<total>
		$range = $_SERVER['HTTP_CONTENT_RANGE'] ?? '';
		if (!preg_match('/bytes\s+(\d+)-(\d+)\/(\d+|\*)/i', $range, $m)) {
			self::fail('A Content-Range header is required.', 'ActionError', 400);
		}
		$start = (int)$m[1];

		// Serialize appends per upload: two concurrent PUTs for the same token
		// must not both pass the offset check and interleave writes. The advisory
		// lock is session-scoped and every response path exits the request (the
		// connection close releases it). The offset is re-read UNDER the lock —
		// the pre-lock value may be stale by the time we hold it.
		$lockq = DbConnector::get_instance()->get_db_link()->prepare("SELECT pg_advisory_lock(?, ?)");
		$lockq->execute(array(self::APPEND_LOCK_CLASS, (int)$up->key));
		$up->load();
		$received = (int)$up->get('fup_received_bytes');
		$expected = (int)$up->get('fup_expected_bytes');

		// Sequential only: the client must continue from exactly where we are.
		if ($start !== $received) {
			self::conflict($received);
		}

		// Append the raw body, starting from the known-good offset (truncating any
		// partial tail left by a prior failed write).
		$part = $up->part_path();
		$dir = dirname($part);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		$out = @fopen($part, 'c+b');
		if ($out === false) {
			self::fail('Could not open upload storage.', 'TransactionError', 500);
		}
		@ftruncate($out, $received);
		@fseek($out, $received);

		$in = @fopen('php://input', 'rb');
		if ($in === false) {
			@fclose($out);
			self::fail('Could not read request body.', 'TransactionError', 400);
		}

		$written = 0;
		while (!feof($in)) {
			$buf = fread($in, self::READ_CHUNK);
			if ($buf === false || $buf === '') {
				break;
			}
			// Never let a chunk push the file past the declared total.
			if ($received + $written + strlen($buf) > $expected) {
				$buf = substr($buf, 0, $expected - ($received + $written));
			}
			if ($buf === '') {
				break;
			}
			$n = fwrite($out, $buf);
			if ($n === false) {
				break;
			}
			$written += $n;
			if ($received + $written >= $expected) {
				break;
			}
		}
		@fflush($out);
		@fclose($out);
		@fclose($in);

		$new_received = $received + $written;
		$up->set('fup_received_bytes', $new_received);
		$up->set('fup_update_time', gmdate('Y-m-d H:i:s'));
		$up->save();

		self::ok(array(
			'received_bytes' => $new_received,
			'expected_bytes' => $expected,
		));
	}

	// ---- response helpers (always exit) -----------------------------------

	/**
	 * Write the request's OUTCOME to the api_upload log bucket. Called by every
	 * response helper before it emits and exits — a failed chunk must not be
	 * recorded as a success. One row per request either way, so the rate-limit
	 * count is unaffected by outcome.
	 */
	private static function log_outcome($success, $status) {
		if (self::$log_context === null) {
			return;
		}
		require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
		RequestLogger::log('api_upload', 'drive_upload/' . strtolower((string)self::$log_context['method']), (bool)$success, array(
			'user_id'     => (int)self::$log_context['user_id'],
			'status_code' => (int)$status,
		));
		self::$log_context = null; // one row per request
	}

	private static function ok(array $data) {
		self::log_outcome(true, 200);
		if (function_exists('api_success')) {
			api_success($data);
		}
		self::emit(array('api_version' => '1.0', 'success_message' => '', 'data' => $data), 200);
	}

	private static function conflict($received_bytes) {
		self::log_outcome(false, 409);
		if (function_exists('api_error')) {
			// carry received_bytes so the client can resume from the right offset
			header('Content-Type: application/json');
			http_response_code(409);
			echo json_encode(array(
				'api_version' => '1.0',
				'errortype'   => 'ActionError',
				'error'       => 'Chunk offset does not match the server; resume from received_bytes.',
				'data'        => array('received_bytes' => (int)$received_bytes),
			));
			exit;
		}
		self::emit(array(
			'api_version' => '1.0',
			'errortype'   => 'ActionError',
			'error'       => 'Chunk offset mismatch.',
			'data'        => array('received_bytes' => (int)$received_bytes),
		), 409);
	}

	private static function fail($message, $type, $status) {
		self::log_outcome(false, $status);
		if (function_exists('api_error')) {
			api_error($message, $type, $status);
		}
		self::emit(array('api_version' => '1.0', 'errortype' => $type, 'error' => $message, 'data' => array()), $status);
	}

	private static function emit(array $payload, $status) {
		if (!headers_sent()) {
			header('Content-Type: application/json');
			http_response_code($status);
		}
		echo json_encode($payload);
		exit;
	}
}
?>
