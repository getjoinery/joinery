<?php
/**
 * upload_backup.php — put one backup file that is already on this node into the
 * management node's cloud storage target, in its own process.
 *
 * The file is here and the archive can be many gigabytes, so the transfer runs
 * here. Routing it through the management node would drag the whole thing down and
 * push it back up again for no reason.
 *
 * WHAT THIS SCRIPT WILL NOT DO, and why the list is the point:
 *
 *   - It cannot upload an arbitrary file. It is given a NAME, never a path, and
 *     it resolves that name inside BackupNaming::BACKUP_DIR itself. A name with
 *     a separator in it, or one that is not a recognised backup artifact, is
 *     refused.
 *
 *     That one is worth stating plainly, because it is the clearest thing this
 *     whole migration buys. The absolute path used to arrive from the control
 *     plane, so a plane that had been compromised could name ANY file on ANY
 *     node — the config with its database password, a private key, a user's
 *     mail — and have the node upload it to a bucket the attacker controlled.
 *     Read-anything-from-every-node, wearing a backup job's clothes. Here the
 *     caller cannot express a path at all, so there is nothing to abuse.
 *   - It cannot delete anything. Not the local copy after a successful upload,
 *     not a cloud object. There is no code here that removes a file, so
 *     "an operator asking for an offsite copy did not ask for the file to
 *     disappear" is a property of the program rather than a flag someone passes
 *     correctly.
 *   - It cannot name the key file either. `include_envelope` is a BOOLEAN, and
 *     the envelope's name is derived here from the archive that was named. The
 *     caller can ask for the key that belongs to this backup; it cannot ask for
 *     a file of its choosing that happens to end in .keys.json.
 *
 * WHY AN ENCRYPTED BACKUP IS TWO FILES. The archive is sealed with a data key
 * minted for that archive alone, and the only copy of that key is the .keys.json
 * envelope written beside it — the database records that a run was encrypted and
 * which recovery key it used, never the sealed key itself. An archive that
 * travels offsite without its envelope cannot be opened by anyone, the holder of
 * the recovery key included, and it sits in the listing looking exactly like
 * protection. So when the envelope is asked for, it goes FIRST: an envelope with
 * no archive is harmless — a sealed key with no ciphertext to open — while an
 * archive with no envelope is the failure this pairing exists to prevent. There
 * is no ordering of these two uploads where a partial failure is invisible, but
 * there is one where a partial failure is harmless.
 *   - It cannot be told where in the bucket to write. It composes the object key
 *     from the prefix, the slug and the file's own name.
 *
 * Configuration arrives as JSON **on stdin**, and only on stdin:
 *
 *   php utils/upload_backup.php <<'EOF'
 *   {"bucket":"...","path_prefix":"...","slug":"...","filename":"...",
 *    "credentials_b64":"...","include_envelope":true}
 *   EOF
 *
 * Stdin rather than arguments on purpose, and the same reason run_backup.php
 * gives: one of these fields is a bucket credential, and anything in argv is
 * visible to every user on the box for the life of the process. This script
 * takes no arguments at all, so `ps` on a node discloses nothing about the job
 * beyond the fact that an upload is running.
 *
 * An unrecognised key on stdin is REFUSED rather than ignored. A caller that
 * believes it sent an instruction this script silently dropped is the shape of
 * every "it looked like it worked" failure; a newer caller against an older
 * copy of this file should fail loudly on the first run instead.
 *
 * Exits 0 on success, 1 on a transfer failure, 2 on a malformed request.
 *
 * @version 1.2 - resolves the backup directory from the node's own configured working
 *                 directory and the named profile, instead of assuming the BACKUP_DIR
 *                 constant. On a node using the computed default, the constant names a
 *                 directory that does not exist, so no backup was ever found.
 * @version 1.1 - include_envelope: an encrypted archive's .keys.json travels with it.
 *                Without it a re-uploaded encrypted backup is an offsite copy nobody
 *                can open, and nothing said so.
 * @version 1.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

// Bootstrap the application
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));

/**
 * Put one local file at one object key. Returns
 * ['ok'=>bool, 'attempts'=>int, 'error'=>string]; the retry log is written to
 * stderr as it is read, because a transfer that only succeeded on its third
 * attempt is a working upload AND a sick link, and the green tells you one.
 */
function upload_backup_put($creds, $bucket, $key, $path) {
	try {
		$resp = S3Signer::put_file($creds, $bucket, '/' . $key, $path);
	} catch (Exception $e) {
		// S3Signer's messages name the endpoint and the failure, never the key.
		return array('ok' => false, 'attempts' => 1, 'error' => $e->getMessage());
	}
	foreach (($resp['retry_log'] ?? array()) as $line) {
		fwrite(STDERR, 'RETRY: ' . $line . "\n");
	}
	$attempts = (int)($resp['attempts'] ?? 1);
	if (($resp['status'] ?? 0) !== 200) {
		$error = S3Signer::extract_error($resp['body'] ?? '') ?: ('HTTP ' . (int)($resp['status'] ?? 0));
		return array('ok' => false, 'attempts' => $attempts,
			'error' => $error . ' after ' . $attempts . ' attempt(s)');
	}
	return array('ok' => true, 'attempts' => $attempts, 'error' => '');
}

/** Complain and stop. Never handed anything read from the configuration. */
function upload_backup_refuse($message, $code = 2) {
	fwrite(STDERR, 'UPLOAD_FAIL: ' . $message . "\n");
	echo "UPLOAD_RESULT=error\n";
	exit($code);
}

$raw = stream_get_contents(STDIN);
$config = json_decode((string)$raw, true);
unset($raw);
if (!is_array($config)) {
	upload_backup_refuse('this run needs its configuration as JSON on stdin');
}

// The complete vocabulary. The five strings are required, because there is no
// sensible default for any of them — a missing bucket is a mistake, not a
// preference. include_envelope is optional and defaults to false, so a caller
// that does not know about it behaves exactly as it always did.
$required = array('bucket', 'path_prefix', 'slug', 'filename', 'credentials_b64');
// 'profile' is accepted but NOT required, for the same reason include_envelope
// is optional: during a rollout the agent already running on the node predates
// it and sends no such key. Absent means the site profile, which is what the
// only directory this script used to look in actually was.
$accepted = array_merge($required, array('include_envelope', 'profile'));

$unknown = array_diff(array_keys($config), $accepted);
if ($unknown) {
	sort($unknown);
	upload_backup_refuse('configuration carries unrecognised key(s): ' . implode(', ', $unknown));
}
foreach ($required as $field) {
	if (!isset($config[$field]) || !is_string($config[$field]) || trim($config[$field]) === '') {
		upload_backup_refuse("configuration is missing '{$field}'");
	}
}
if (isset($config['include_envelope']) && !is_bool($config['include_envelope'])) {
	upload_backup_refuse("'include_envelope' must be true or false");
}
$include_envelope = !empty($config['include_envelope']);

$bucket   = trim($config['bucket']);
$prefix   = trim($config['path_prefix'], '/');
$slug     = trim($config['slug']);
$filename = trim($config['filename']);

// The slug and the prefix become path segments in someone else's bucket. A
// traversal or a separator in either would write over another node's backups.
if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
	upload_backup_refuse('the node slug is not a usable path segment');
}
if ($prefix === '' || !preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $prefix)) {
	upload_backup_refuse('the bucket path prefix is not a usable path');
}

// A NAME, never a path. basename() is not used to sanitise here — a name that
// needed sanitising is a name this script refuses, because the only caller that
// would send one is a caller doing something other than naming a backup.
if ($filename !== basename($filename) || $filename === '.' || $filename === '..') {
	upload_backup_refuse('the backup name is a path, and this script takes a name');
}
if (!BackupNaming::is_backup($filename)) {
	upload_backup_refuse('that name is not a backup artifact');
}

// WHERE the node keeps this profile's backups — resolved HERE, by the node,
// from its own configured working directory. BackupNaming::BACKUP_DIR is only
// the last-resort constant; the real base is the backup_output_dir setting or
// {siteRoot}/backups, and each profile gets its own directory beneath it. The
// agent used to assume the constant, so on a node using the computed default it
// looked in a directory that did not exist and reported no backups at all.
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

$profile = BackupProfile::normalize((string)($config['profile'] ?? BackupProfile::SITE));
$backup_dir = BackupProfile::output_dir($profile, BackupRunner::output_dir());

$local_path = $backup_dir . '/' . $filename;
if (!is_file($local_path)) {
	upload_backup_refuse('no such backup on this node: ' . $filename);
}

$creds = json_decode((string)base64_decode($config['credentials_b64'], true), true);
if (!is_array($creds)) {
	upload_backup_refuse('the storage credential is not readable');
}
// Nothing below may reach for the raw form again, and neither may an error
// handler or a shutdown function.
unset($config);

$remote_key = $prefix . '/' . $slug . '/' . $filename;
$size = filesize($local_path);

// ── The key first, when it was asked for ────────────────────────────────────
// An envelope with no archive beside it is harmless: a sealed key with no
// ciphertext to open. An archive with no envelope is unrecoverable and looks
// like a good backup. So if one of the two is going to be up there alone after
// a partial failure, it must be the harmless one.
$envelope_status = 'not_requested';
$envelope_key = '';
if ($include_envelope) {
	$envelope_name = BackupEnvelope::sidecar_name($filename);
	$envelope_path = $backup_dir . '/' . $envelope_name;

	if (!is_file($envelope_path)) {
		// Not fatal, deliberately. Whatever asked for this has already decided
		// that a copy of the bytes is worth having; what it must not do is
		// believe the copy is restorable. Saying so is this script's whole job
		// here.
		$envelope_status = 'absent';
		fwrite(STDERR, 'ENVELOPE_ABSENT: ' . $envelope_name
			. " is not on this node, so the uploaded archive cannot be decrypted from the cloud copy alone\n");
	} else {
		$envelope_key = $prefix . '/' . $slug . '/' . $envelope_name;
		$put = upload_backup_put($creds, $bucket, $envelope_key, $envelope_path);
		if (!$put['ok']) {
			// Stop before the archive. Uploading it now would produce exactly
			// the unopenable copy this pairing exists to prevent.
			fwrite(STDERR, 'UPLOAD_FAIL: could not upload the key file: ' . $put['error'] . "\n");
			echo "UPLOAD_RESULT=error\n";
			echo "UPLOAD_ENVELOPE=failed\n";
			exit(1);
		}
		$envelope_status = 'uploaded';
	}
}

// ── Then the archive ────────────────────────────────────────────────────────
$put = upload_backup_put($creds, $bucket, $remote_key, $local_path);
if (!$put['ok']) {
	fwrite(STDERR, 'UPLOAD_FAIL: ' . $put['error'] . "\n");
	echo "UPLOAD_RESULT=error\n";
	echo 'UPLOAD_ATTEMPTS=' . (int)$put['attempts'] . "\n";
	echo 'UPLOAD_ENVELOPE=' . $envelope_status . "\n";
	exit(1);
}
$attempts = (int)$put['attempts'];

// The human line is not a contract; the ones below it are.
echo 'Uploaded ' . $filename . ' (' . $size . ' bytes) to ' . $remote_key
	. ($attempts > 1 ? ' on attempt ' . $attempts : '')
	. ($envelope_status === 'uploaded' ? ', with its key file' : '') . "\n";
if ($envelope_status === 'absent') {
	echo "This archive is encrypted and its key file was not on this node, so the "
		. "offsite copy cannot be restored from on its own.\n";
}
echo "UPLOAD_RESULT=ok\n";
echo 'UPLOAD_KEY=' . $remote_key . "\n";
echo 'UPLOAD_BYTES=' . $size . "\n";
echo 'UPLOAD_ATTEMPTS=' . $attempts . "\n";
echo 'UPLOAD_ENVELOPE=' . $envelope_status . "\n";
if ($envelope_key !== '' && $envelope_status === 'uploaded') {
	echo 'ENVELOPE_KEY=' . $envelope_key . "\n";
}
exit(0);
