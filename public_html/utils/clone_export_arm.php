#!/usr/bin/php
<?php
/**
 * clone_export_arm.php — arm or disarm this site's clone export.
 *
 * A new machine installed from this site pulls the database, uploads, themes
 * and plugins over HTTPS from utils/clone_export, inside its own install. That
 * endpoint is off until clone_export_key holds a value; the value is the bearer
 * token the target presents, and the password the dump is encrypted under. A
 * management node arms this site for the length of one provision by handing it
 * a key, and disarms it afterwards by handing it an empty one. Nothing opens a
 * shell here: the source of a clone is reached by its web address.
 *
 * This script is invoked by the agent's clone_export_arm primitive, which
 * verifies it against the signed release manifest before running it.
 *
 * THE SETTING NAME IS HERE, NOT ON THE WIRE. What arrives is one VALUE; which
 * setting it lands in is decided below, on this machine, in a file covered by
 * the release manifest. A generic write-a-setting path would hand whatever is
 * on the other end of the channel the entire stg_settings table.
 *
 * The value arrives as one JSON object on stdin:
 *
 *   php utils/clone_export_arm.php <<'EOF'
 *   {"export_key":"0123456789abcdef0123456789abcdef"}
 *   EOF
 *
 * An EMPTY key disarms. The caller converges on desired state: what it stops
 * sending is cleared.
 *
 * Writes go through Setting::put, which refuses a name that is not declared in
 * settings.json — a typo fails loudly here instead of minting a row nothing
 * reads. clone_export_key is declared `managed`, so it is kept off this site's
 * own settings page: the management node and a database operator are its only
 * authors, which is what keeps a permission-8 admin from turning on a full-site
 * export from a browser.
 *
 * Prints one line — CLONE_EXPORT_ARM=armed, =disarmed or =error — and exits 0
 * on success, 2 on unusable input, 1 on a write that failed.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

/**
 * The one setting this script may write. This line is the security boundary:
 * changing it changes what a management node can reach on every node in the
 * fleet, and belongs in a reviewed commit, not a parameter.
 */
$export_setting = 'clone_export_key';

$raw = stream_get_contents(STDIN);
$supplied = json_decode((string)$raw, true);
if (!is_array($supplied)) {
	fwrite(STDERR, "CLONE_EXPORT_ARM=error\nThis script takes its value as a JSON object on stdin.\n");
	exit(2);
}

$key = trim((string)($supplied['export_key'] ?? ''));
// The agent's parameter spec already refuses this shape, and so does this
// script: a key is a bearer token and an encryption password, nothing that
// could read as SQL, a path or a shell word belongs in it.
if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $key)) {
	fwrite(STDERR, "CLONE_EXPORT_ARM=error\nThe export key is not in a shape this site accepts.\n");
	exit(2);
}

try {
	Setting::put($export_setting, $key);
} catch (Throwable $e) {
	fwrite(STDERR, "CLONE_EXPORT_ARM=error\n" . $export_setting . ': ' . $e->getMessage() . "\n");
	exit(1);
}

echo 'CLONE_EXPORT_ARM=' . ($key === '' ? 'disarmed' : 'armed') . "\n";
exit(0);
