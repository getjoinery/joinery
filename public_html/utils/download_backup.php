<?php
/**
 * download_backup.php — bring one of this node's own backups back from the
 * management node's cloud storage, so it can be restored.
 *
 * The mirror of upload_backup.php, and it inherits that script's whole posture:
 * the caller names a backup, never a path; the node resolves the name inside
 * its own backup directory; the node derives the key file's name rather than
 * being told it; and nothing here deletes anything.
 *
 * WHY THIS EXISTS. Every node in the fleet deletes its local archive once it is
 * safely uploaded, which is the right thing for a small disk and means the
 * normal state of a machine is "my backups are all offsite". A restore
 * therefore has nothing to restore from until the artifact comes back. That is
 * this script, and it is why it comes before the approval machinery rather than
 * after: opening the authorization gate on its own would produce a restore that
 * is permitted and still restores nothing.
 *
 * THREE THINGS IT WILL NOT DO — all three enforced in BackupFetch, which the
 * chain-staging script shares:
 *
 *   - It will not accept a bucket credential, because it is not given one. What
 *     arrives is a pre-signed URL: one object, expiring, signed on the machine
 *     that owns the bucket.
 *   - It will not land an archive this machine has no record of making. The
 *     name is checked against the node-side upload ledger before a byte moves.
 *   - It will not fill the disk. The ledger records the size, so the free-space
 *     check happens before the transfer rather than after it.
 *
 * THE ARTIFACT LANDS 0600, root-owned because the agent that runs this is root.
 * On a container node the backup directory resolves inside the site tree, so a
 * file landing there with default permissions is readable by the web tier. The
 * restore scripts run as root and need nothing more.
 *
 * Configuration arrives as JSON on stdin, and only on stdin:
 *
 *   php utils/download_backup.php <<'EOF'
 *   {"url":"https://…signed…","filename":"db-….sql.gz.enc","profile":"manager",
 *    "envelope_url":"https://…signed…"}
 *   EOF
 *
 * An unrecognised key is REFUSED rather than ignored, the same rule
 * upload_backup.php states: a caller that believes it sent an instruction this
 * script silently dropped is the shape of every "it looked like it worked"
 * failure.
 *
 * Exits 0 on success, 1 on a transfer or integrity failure, 2 on a malformed
 * request.
 *
 * @version 1.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupFetch.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

/** Complain and stop. Never handed a URL — a signed URL is a bearer token. */
function download_backup_refuse($message, $code = 2) {
	fwrite(STDERR, 'DOWNLOAD_FAIL: ' . $message . "\n");
	echo "DOWNLOAD_RESULT=error\n";
	exit($code);
}

// ── Configuration ───────────────────────────────────────────────────────────

$raw = stream_get_contents(STDIN);
$config = json_decode((string)$raw, true);
unset($raw);
if (!is_array($config)) {
	download_backup_refuse('this run needs its configuration as JSON on stdin');
}

$required = array('url', 'filename', 'profile');
$accepted = array_merge($required, array('envelope_url'));
$unknown = array_diff(array_keys($config), $accepted);
if ($unknown) {
	sort($unknown);
	download_backup_refuse('configuration carries unrecognised key(s): ' . implode(', ', $unknown));
}
foreach ($required as $field) {
	if (!isset($config[$field]) || !is_string($config[$field]) || trim($config[$field]) === '') {
		download_backup_refuse("configuration is missing '{$field}'");
	}
}

$filename     = trim($config['filename']);
$url          = trim($config['url']);
$envelope_url = trim((string)($config['envelope_url'] ?? ''));
$profile      = BackupProfile::normalize((string)$config['profile']);
unset($config);

// A NAME, never a path — the same refusal upload_backup makes, and for the same
// reason: the only caller that would send one is a caller doing something other
// than naming a backup.
if ($filename !== basename($filename) || $filename === '.' || $filename === '..') {
	download_backup_refuse('the backup name is a path, and this script takes a name');
}
if (!BackupNaming::is_backup($filename)) {
	download_backup_refuse('that name is not a backup artifact');
}
if (!BackupFetch::is_signed_url($url)) {
	download_backup_refuse("'url' must be an https URL");
}
if ($envelope_url !== '' && !BackupFetch::is_signed_url($envelope_url)) {
	download_backup_refuse("'envelope_url' must be an https URL");
}

$dir = BackupProfile::output_dir($profile, BackupRunner::output_dir());
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
	download_backup_refuse('this node has no backup directory at ' . $dir . ' and could not make one');
}

// ── The key file first, when it was asked for ───────────────────────────────
// upload_backup's ordering, and the same reasoning: an envelope with no archive
// beside it is harmless, an archive with no envelope looks like a restore point
// and is not one. Fetching the small file first also means a missing or
// mismatched envelope costs seconds rather than a whole transfer.
$envelope_status = 'not_requested';
if ($envelope_url !== '') {
	// DERIVED, never named. The caller asks for "the key belonging to the
	// archive I named"; this side works out what it is called. Letting the
	// caller send a .keys.json filename would hand back the ability to name a
	// file, which is the one thing this vocabulary exists to take away.
	$envelope_name = BackupEnvelope::sidecar_name($filename);
	$got = BackupFetch::fetch_artifact($profile, $dir, $envelope_name, $envelope_name, $envelope_url);
	if (!$got['ok']) {
		fwrite(STDERR, 'DOWNLOAD_FAIL: could not bring back the key file: ' . $got['error'] . "\n");
		echo "DOWNLOAD_RESULT=error\n";
		echo "DOWNLOAD_ENVELOPE=failed\n";
		exit(1);
	}
	$envelope_status = 'downloaded';
}

// ── Then the archive ────────────────────────────────────────────────────────
$got = BackupFetch::fetch_artifact($profile, $dir, $filename, $filename, $url);
if (!$got['ok']) {
	fwrite(STDERR, 'DOWNLOAD_FAIL: ' . $got['error'] . "\n");
	echo "DOWNLOAD_RESULT=error\n";
	echo 'DOWNLOAD_ENVELOPE=' . $envelope_status . "\n";
	exit(1);
}

echo 'Brought back ' . $filename . ' (' . BackupFetch::human($got['bytes']) . ') into ' . $dir
	. ($envelope_status === 'downloaded' ? ', with its key file' : '') . "\n";
echo "DOWNLOAD_RESULT=ok\n";
echo 'DOWNLOAD_FILE=' . $filename . "\n";
echo 'DOWNLOAD_BYTES=' . (int)$got['bytes'] . "\n";
echo 'DOWNLOAD_SHA256=' . $got['sha256'] . "\n";
echo 'DOWNLOAD_ENVELOPE=' . $envelope_status . "\n";
exit(0);
