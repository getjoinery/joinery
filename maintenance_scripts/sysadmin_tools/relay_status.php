<?php
/**
 * relay_status.php - this site's view of its mail relay, as ONE JSON object on
 * stdout: the relay's last reported version and health, the map pushed to it,
 * pickup, the transport key's kind, and how much mail arrived lately.
 *
 * The relay_status observe word (agent 1.101.0). A relay has no agent and no
 * shell by design; this is the site's side of it, so a stuck relay can be told
 * apart from a stuck site without signing in to either. The agent runs this
 * file, verified against the release manifest, with no argv and no stdin.
 *
 * What it prints is RelayStatusReport::status() (the mailbox plugin): versions,
 * times, counts, booleans and short state words. Never an address, a domain, a
 * key or a secret. Where the mailbox plugin is not active it prints
 * {"mailbox":"inactive"}; with no enabled relay, {"mailbox":"active","relay":"none",...}.
 *
 * It reads and never writes: the map is not rebuilt, because building it can
 * mint a transport key. It lives in sysadmin_tools/, outside the web root; the
 * CLI check below is the second line.
 *
 * Exit 0 with the object; exit 1 with an ERROR: line when it could not read.
 *
 * Validate with `php -l` only - never the file validator, which executes the
 * file it is checking.
 *
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

if (count($argv) > 1) {
	fwrite(STDERR, "Usage: php relay_status.php (it takes no arguments)\n");
	exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');

try {
	// An inactive plugin's classes do not resolve, which is the answer here.
	if (!class_exists('RelayStatusReport')) {
		echo json_encode(array('mailbox' => 'inactive')) . "\n";
		exit(0);
	}
	echo json_encode(array('mailbox' => 'active') + RelayStatusReport::status()) . "\n";
} catch (\Throwable $e) {
	echo 'ERROR: the relay status could not be read: ' . get_class($e) . "\n";
	exit(1);
}
exit(0);
