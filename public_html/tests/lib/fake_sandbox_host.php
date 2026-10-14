<?php
/**
 * A stand-in for the plugin host, for tests of the core side of the doors
 * before (and beside) the real host.
 *
 * Listens on a unix socket, speaks just enough HTTP to read one POST /call per
 * connection, appends each request body to a log file (one JSON per line),
 * and answers with a reply chosen from a JSON file: an object of export name
 * ("on_ui", "on_signal", "on_schedule") or "*" to the reply to send. A reply
 * may carry "sleep_ms" to answer late. It exits when the stop file appears
 * or after --max requests.
 *
 *   php tests/lib/fake_sandbox_host.php <socket> <replies.json> <requests.log> [--max=N]
 *
 * Start it with fake_sandbox_host_start() in tests/lib/sandbox.php.
 *
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$socket = $argv[1] ?? '';
$replies_file = $argv[2] ?? '';
$log_file = $argv[3] ?? '';
$max = 1000;
foreach (array_slice($argv, 4) as $arg) {
	if (preg_match('~^--max=(\d+)$~', $arg, $m)) {
		$max = (int)$m[1];
	}
}
if ($socket === '' || $replies_file === '' || $log_file === '') {
	fwrite(STDERR, "usage: fake_sandbox_host.php <socket> <replies.json> <requests.log> [--max=N]\n");
	exit(2);
}
@unlink($socket);
$server = stream_socket_server('unix://' . $socket, $errno, $errstr);
if (!$server) {
	fwrite(STDERR, "cannot listen on $socket: $errstr\n");
	exit(3);
}
@chmod($socket, 0666);
$stop_file = $socket . '.stop';

for ($served = 0; $served < $max; ) {
	if (file_exists($stop_file)) {
		break;
	}
	$conn = @stream_socket_accept($server, 1);
	if (!$conn) {
		continue;
	}
	$served++;
	stream_set_timeout($conn, 5);
	$head = '';
	while (($line = fgets($conn)) !== false) {
		$head .= $line;
		if ($line === "\r\n") {
			break;
		}
	}
	$length = preg_match('~Content-Length:\s*(\d+)~i', $head, $m) ? (int)$m[1] : 0;
	$body = '';
	while (strlen($body) < $length && !feof($conn)) {
		$body .= fread($conn, $length - strlen($body));
	}
	file_put_contents($log_file, str_replace("\n", ' ', $body) . "\n", FILE_APPEND);

	$request = json_decode($body, true);
	$replies = json_decode((string)@file_get_contents($replies_file), true) ?: array();
	$export = is_array($request) ? (string)($request['export'] ?? '') : '';
	$reply = $replies[$export] ?? ($replies['*'] ?? array('ok' => true, 'output' => null, 'calls' => array()));
	if (!empty($reply['sleep_ms'])) {
		usleep((int)$reply['sleep_ms'] * 1000);
	}
	unset($reply['sleep_ms']);
	$out = json_encode($reply);
	@fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($out)
		. "\r\nConnection: close\r\n\r\n" . $out);
	fclose($conn);
}
fclose($server);
@unlink($socket);
@unlink($stop_file);
