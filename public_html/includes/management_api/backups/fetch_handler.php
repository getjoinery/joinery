<?php
/**
 * GET /api/v1/management/backups/fetch?path=/backups/...
 *
 * Streams a backup file back to the caller as an octet-stream. Path MUST be
 * under /backups/; anything else is rejected to prevent arbitrary-file-read.
 *
 * This is a streaming handler: it writes bytes directly to the response and
 * returns null so the router does not wrap the output in api_success.
 */

function backups_fetch_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'Stream a backup file from /backups/ to the caller. Query param: path.',
	];
}

function backups_fetch_handler($request) {
	$raw_path = trim((string)($request['query']['path'] ?? ''));
	if ($raw_path === '') {
		api_error('Missing required query parameter: path', 'TransactionError', 400);
	}

	// Resolve symlinks etc., then enforce the /backups/ prefix on the real path.
	$real = @realpath($raw_path);
	if ($real === false || !is_file($real)) {
		api_error('Backup file not found', 'TransactionError', 404);
	}
	if (strpos($real, '/backups/') !== 0) {
		api_error('Path must be under /backups/', 'TransactionError', 400);
	}

	// Only whitelisted extensions — matches the backup-script output shape.
	$basename = basename($real);
	if (!preg_match('/\.(?:sql\.gz(?:\.enc)?|tar\.gz)$/', $basename)) {
		api_error('Unsupported backup file type', 'TransactionError', 400);
	}

	$size = @filesize($real);
	$fh   = @fopen($real, 'rb');
	if (!$fh) {
		api_error('Unable to open backup file', 'TransactionError', 500);
	}

	// Clear any output buffering / compression that would break the stream.
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	if (function_exists('apache_setenv')) {
		@apache_setenv('no-gzip', '1');
	}
	@ini_set('zlib.output_compression', 'Off');

	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . $basename . '"');
	if ($size !== false) {
		header('Content-Length: ' . $size);
	}
	header('X-Accel-Buffering: no');
	http_response_code(200);

	$chunk = 1024 * 64;
	while (!feof($fh)) {
		$data = fread($fh, $chunk);
		if ($data === false) break;
		echo $data;
		@ob_flush();
		flush();
	}
	fclose($fh);

	// Returning null tells the router "I handled the response".
	return null;
}
?>
