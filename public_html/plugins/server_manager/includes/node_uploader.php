<?php
/**
 * node_uploader.php — self-contained dispatcher that runs on a managed node.
 *
 * Not meant to be invoked directly from the filesystem. `JobCommandBuilder`
 * composes the final script at job-build time by concatenating S3Signer.php
 * + this file + a credentials block, then heredoc-pipes the result to
 * `php -` on the node.
 *
 * Usage (after composition):
 *   php - upload <local_path> <remote_key>
 *   php - delete <remote_key>
 *
 * Exits 0 on success, non-zero on failure. Prints a one-line status to
 * stdout on success or stderr on failure.
 *
 * @version 1.0
 */

// At runtime, the following are prepended to this file's content by the
// command builder:
//   - The full body of S3Signer.php (so class S3Signer is defined)
//   - $creds = ['access_key' => ..., 'secret_key' => ..., 'region' => ..., 'endpoint' => ...];
//   - $bucket = '...';

$op = $argv[1] ?? '';

if ($op === 'upload') {
	$local = $argv[2] ?? '';
	$remote = $argv[3] ?? '';
	if ($local === '' || $remote === '' || !is_file($local)) {
		fwrite(STDERR, "UPLOAD_FAIL: invalid arguments or local file missing\n");
		exit(2);
	}
	try {
		$resp = S3Signer::put_file($creds, $bucket, '/' . ltrim($remote, '/'), $local);
	} catch (Exception $e) {
		fwrite(STDERR, "UPLOAD_FAIL: " . $e->getMessage() . "\n");
		exit(1);
	}
	if ($resp['status'] === 200) {
		echo "UPLOAD_OK " . filesize($local) . " bytes -> " . $remote . "\n";
		exit(0);
	}
	$err = S3Signer::extract_error($resp['body']) ?: ('HTTP ' . $resp['status']);
	fwrite(STDERR, "UPLOAD_FAIL: " . $err . "\n");
	exit(1);
}

if ($op === 'download') {
	$remote = $argv[2] ?? '';
	$local = $argv[3] ?? '';
	if ($remote === '' || $local === '') {
		fwrite(STDERR, "DOWNLOAD_FAIL: missing arguments\n");
		exit(2);
	}
	try {
		$resp = S3Signer::get($creds, $bucket, '/' . ltrim($remote, '/'));
	} catch (Exception $e) {
		fwrite(STDERR, "DOWNLOAD_FAIL: " . $e->getMessage() . "\n");
		exit(1);
	}
	if ($resp['status'] !== 200) {
		$err = S3Signer::extract_error($resp['body']) ?: ('HTTP ' . $resp['status']);
		fwrite(STDERR, "DOWNLOAD_FAIL: " . $err . "\n");
		exit(1);
	}
	if (file_put_contents($local, $resp['body']) === false) {
		fwrite(STDERR, "DOWNLOAD_FAIL: could not write " . $local . "\n");
		exit(1);
	}
	echo "DOWNLOAD_OK " . strlen($resp['body']) . " bytes -> " . $local . "\n";
	exit(0);
}

if ($op === 'delete') {
	$remote = $argv[2] ?? '';
	if ($remote === '') {
		fwrite(STDERR, "DELETE_FAIL: missing remote key\n");
		exit(2);
	}
	try {
		$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($remote, '/'));
	} catch (Exception $e) {
		fwrite(STDERR, "DELETE_FAIL: " . $e->getMessage() . "\n");
		exit(1);
	}
	if ($resp['status'] === 204 || $resp['status'] === 200) {
		echo "DELETE_OK " . $remote . "\n";
		exit(0);
	}
	$err = S3Signer::extract_error($resp['body']) ?: ('HTTP ' . $resp['status']);
	fwrite(STDERR, "DELETE_FAIL: " . $err . "\n");
	exit(1);
}

fwrite(STDERR, "USAGE: upload <local> <remote> | delete <remote>\n");
exit(2);
?>
