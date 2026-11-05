<?php
/**
 * UnsealClient - the PHP end of the unseal daemon's socket
 * (specs/unseal_daemon.md § The operations, the wire in
 * maintenance_scripts/install_tools/joinery_unseal/README.md).
 *
 *   frame = u32be(len(header)) ‖ JSON header ‖ u32be(len(body)) ‖ body
 *
 * where the body is the request's byte parts concatenated and header.parts
 * lists their lengths. One connection per client object, reused for every
 * trip it makes; a connect timeout, and a read deadline on every reply.
 *
 * This is the transport and status(), which the health check and the admin
 * notice use. The window operations (open, unwrap, open_previous, close) are
 * WP4's, through DaemonVaultKey; call() is what they build on.
 *
 * @version 1.0
 */
class UnsealClient {

	/** Nothing answered at the socket (no daemon, or no way to reach it). */
	const ERR_CONNECT = 'connect';
	/** The connection broke or the reply was out of frame. */
	const ERR_TRANSPORT = 'transport';

	/** @var string */
	private $socket;
	/** @var float */
	private $timeout;
	/** @var resource|null */
	private $conn = null;

	public function __construct(string $socket, float $timeout = 3.0) {
		$this->socket = $socket;
		$this->timeout = $timeout;
	}

	public function __destruct() {
		$this->disconnect();
	}

	public function disconnect(): void {
		if (is_resource($this->conn)) {
			fclose($this->conn);
		}
		$this->conn = null;
	}

	/**
	 * One request. Returns ['fields' => array, 'parts' => string[]]. A daemon's
	 * refusal is fields['ok'] === false with fields['error'] its code; a socket
	 * that does not answer is the same shape with error 'connect' or
	 * 'transport', so a caller reads one thing.
	 *
	 * @param array    $fields the header (op and its fields); parts is filled in here
	 * @param string[] $parts  the byte parts, in the order the operation names them
	 */
	public function call(array $fields, array $parts = array()): array {
		$parts = array_values($parts);
		if ($parts) {
			$fields['parts'] = array_map('strlen', $parts);
		}
		$header = json_encode($fields);
		$body = implode('', $parts);
		$frame = pack('N', strlen($header)) . $header . pack('N', strlen($body)) . $body;
		if (function_exists('sodium_memzero')) {
			sodium_memzero($body);
		}

		if (!is_resource($this->conn)) {
			$errno = 0;
			$errstr = '';
			$conn = @stream_socket_client('unix://' . $this->socket, $errno, $errstr, $this->timeout);
			if (!$conn) {
				return self::failure(self::ERR_CONNECT, $errstr !== '' ? $errstr : 'no daemon at ' . $this->socket);
			}
			$this->conn = $conn;
		}
		$secs = (int)$this->timeout;
		stream_set_timeout($this->conn, $secs, (int)(($this->timeout - $secs) * 1e6));

		$sent = $this->write($frame);
		if (function_exists('sodium_memzero')) {
			sodium_memzero($frame);
		}
		if (!$sent) {
			$this->disconnect();
			return self::failure(self::ERR_TRANSPORT, 'the connection closed while sending');
		}
		$hl = $this->read(4);
		$hdr = $hl === null ? null : $this->read(unpack('N', $hl)[1]);
		$bl = $hdr === null ? null : $this->read(4);
		$blen = $bl === null ? 0 : unpack('N', $bl)[1];
		$rbody = ($bl === null) ? null : ($blen > 0 ? $this->read($blen) : '');
		$out = $rbody === null ? null : json_decode((string)$hdr, true);
		if (!is_array($out)) {
			$this->disconnect();
			return self::failure(self::ERR_TRANSPORT, 'the reply was out of frame');
		}
		$reply = array('fields' => $out, 'parts' => array());
		$at = 0;
		foreach (($out['parts'] ?? array()) as $n) {
			$reply['parts'][] = substr($rbody, $at, (int)$n);
			$at += (int)$n;
		}
		return $reply;
	}

	/**
	 * The daemon's own status: daemon (version), windows, uptime, held,
	 * memory_locked. Or, with a user id, whether that user holds any window.
	 */
	public function status(?int $user_id = null): array {
		$fields = array('op' => 'status');
		if ($user_id !== null) {
			$fields['user'] = $user_id;
		}
		return $this->call($fields);
	}

	private static function failure(string $code, string $message): array {
		return array('fields' => array('ok' => false, 'error' => $code, 'message' => $message), 'parts' => array());
	}

	private function write(string $bytes): bool {
		$len = strlen($bytes);
		for ($at = 0; $at < $len; $at += $n) {
			$n = @fwrite($this->conn, substr($bytes, $at, 1 << 16));
			if (!$n) {
				return false;
			}
		}
		return true;
	}

	private function read(int $n): ?string {
		$out = '';
		while (strlen($out) < $n) {
			$chunk = @fread($this->conn, $n - strlen($out));
			if ($chunk === false || $chunk === '') {
				return null;
			}
			$out .= $chunk;
		}
		return $out;
	}
}
?>
