<?php
/** @joinery-test
 * name: backup_fetch
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What a node is allowed to accept from a link the management node signed.
 *
 * A restore over the agent channel is the one operation where a node is told
 * "here is a URL, write what comes back to your disk, then load it as root". The
 * node owns three answers the management node does not get to give:
 *
 *   HOW MUCH may arrive. The ledger records the exact size at upload time, so a
 *   response with no Content-Length — or one that lies about it — is bounded by
 *   what this machine knows the object weighs. Without that, a compromised plane
 *   can stream a node's disk to zero for the length of the transfer deadline,
 *   which is an hour.
 *
 *   WHO MAY READ IT while it lands. On a container node the backup directory is
 *   inside the site tree, so a file created with the default umask is readable
 *   by the web tier for the whole transfer. Tightening it afterwards is too
 *   late: a descriptor opened during the gap stays open.
 *
 *   WHAT COMES BACK when it fails. The plane picks the URL and reads the job
 *   transcript, so an error body copied into the transcript makes the node a way
 *   to read error responses from any https endpoint it can reach — the inside of
 *   its own network included.
 *
 * Run: php tests/backups/backup_fetch_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupFetch.php'));

$tmp = sys_get_temp_dir() . '/joinery_fetch_test_' . getmypid();
@mkdir($tmp, 0755, true);
harness_defer(function () use ($tmp) {
	foreach (glob($tmp . '/*') ?: array() as $f) { @unlink($f); }
	foreach (glob($tmp . '/.*') ?: array() as $f) { if (is_file($f)) { @unlink($f); } }
	@rmdir($tmp);
});

// ── The link ────────────────────────────────────────────────────────────────
section('Only a signed https link is followed');

check(BackupFetch::is_signed_url('https://bucket.example.com/x?X-Amz-Signature=abc'),
	'an https link is acceptable');
check(!BackupFetch::is_signed_url('http://bucket.example.com/x'),
	'plaintext is not — the signature is a bearer token and http hands it to the network');
check(!BackupFetch::is_signed_url('file:///etc/passwd'),
	'and neither is a scheme that never leaves the machine');

$sink = $tmp . '/never-written';
$got = BackupFetch::fetch('http://example.com/x', $sink);
check($got['ok'] === false, 'a fetch of an unsigned link refuses');
check(!file_exists($sink), 'and writes nothing');

// ── The permissions ─────────────────────────────────────────────────────────
section('A download is created private, not made private afterwards');

// The property, under the worst umask a process could have inherited. This is
// the one that cannot be checked after the fact: the window it closes is the
// length of the transfer, and by the time the file is on disk complete, the
// chmod has already run.
$before = umask(0000);
$path = $tmp . '/landing.tar.gz.enc';
$fh = BackupFetch::open_private_sink($path);
umask($before);
check($fh !== false, 'the sink opens');
if ($fh) { fwrite($fh, 'bytes'); fclose($fh); }
clearstatcache();
$mode = fileperms($path) & 0777;
check($mode === 0600,
	'it is 0600 from the first byte, even under umask 0000',
	'mode ' . decoct($mode));

// A stale file with loose permissions must not survive: fopen on something that
// already exists keeps the mode it already has.
chmod($path, 0666);
clearstatcache();
$fh = BackupFetch::open_private_sink($path);
if ($fh) { fclose($fh); }
clearstatcache();
$mode = fileperms($path) & 0777;
check($mode === 0600,
	'a stale world-readable file at the same path does not lend it its permissions',
	'mode ' . decoct($mode));

// ── The ceiling ─────────────────────────────────────────────────────────────
section('Nothing arrives larger than the ledger says it should');

check(BackupFetch::size_ceiling(1000) === 1000 + BackupFetch::SIZE_SLACK_BYTES,
	'the ceiling is the recorded size plus a fixed slack');
check(BackupFetch::size_ceiling(0) === 0,
	'no recorded size means no ceiling — there is nothing to enforce against');
check(BackupFetch::SIZE_SLACK_BYTES <= 4 * 1048576,
	'the slack is a small constant, not a percentage: a percentage of a 40GB archive '
	. 'is gigabytes of room to fill a disk with',
	BackupFetch::human(BackupFetch::SIZE_SLACK_BYTES));

// The ceiling is enforced two ways, and only the second one survives a response
// that declines to say how big it is. Read off the source, because the
// difference between them is invisible from the outside without a server that
// lies — and it is exactly the case a compromised plane controls.
$src = (string)file_get_contents(PathHelper::getIncludePath('includes/BackupFetch.php'));
check(strpos($src, 'CURLOPT_MAXFILESIZE') !== false,
	'a response that advertises too large a body is refused before it starts');
check(strpos($src, 'CURLOPT_PROGRESSFUNCTION') !== false,
	'and one that advertises nothing is stopped as it goes past — chunked is the case that matters');
check(strpos($src, 'CURLOPT_FOLLOWLOCATION, false') !== false,
	'a redirect is not followed: it would take the fetch somewhere the signature does not name');

// ── The failure message ─────────────────────────────────────────────────────
section('A failed download reports its status and not its body');

check(strpos($src, 'the response body is not repeated here') !== false,
	'the HTTP failure path says so out loud');
check(!preg_match('/file_get_contents\(\s*\$sink_path/', $src),
	'and nothing reads the failed response back to put it in the transcript');

// ── The order of the pre-flight ─────────────────────────────────────────────
section('Refusals happen before bytes move, not after');

// A name this machine has no record of uploading is refused with nothing
// fetched. The ordering is the point: the ledger is consulted first, so a plane
// cannot spend a node's disk on something it was never going to be allowed to
// use.
$dir = $tmp . '/backups';
@mkdir($dir, 0700, true);
$got = BackupFetch::fetch_artifact('site', $dir, 'nothing_this_machine_made.sql.gz.enc',
	'nothing_this_machine_made.sql.gz.enc', 'https://bucket.example.com/x?sig=abc');
check($got['ok'] === false, 'an unrecorded name is refused');
check(count(glob($dir . '/*') ?: array()) === 0 && count(glob($dir . '/.*.part') ?: array()) === 0,
	'and no file — not even a partial one — was created for it');
check(strpos($got['error'], 'https://') === false,
	'the signed link is never quoted back into the transcript', $got['error']);

harness_finish();
