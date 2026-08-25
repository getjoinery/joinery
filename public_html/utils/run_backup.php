<?php
/**
 * run_backup.php — run one backup, now, in its own process.
 *
 * The Run now button on /admin/admin_backups launches this detached, because a
 * backup can run far longer than any web request survives. Everything worth
 * knowing lands on the bkh_backup_history row the run writes — the page's
 * Recent backups list is the progress report. The output here is only for
 * someone running it by hand.
 *
 * With no arguments it runs the SITE profile: this site's own backup, from its
 * own settings, sealed to its own recovery key.
 *
 *   php utils/run_backup.php
 *
 * With --profile=manager it runs a control plane's backup of this site. Where the
 * archive goes arrives with the run — the bucket and a write-only credential, as
 * JSON **on stdin** — and leaves with the process.
 *
 *   php utils/run_backup.php --profile=manager <<'EOF'
 *   {"bucket":"...","credentials_b64":"..."}
 *   EOF
 *
 * What OPENS the archive is never supplied. Both profiles seal to this site's
 * own proven recovery key, read from this site's settings, and a manager run
 * that arrives carrying key material is refused rather than obeyed. A site with
 * no proven key of its own takes no backups for anybody and says so.
 *
 * Stdin rather than an argument on purpose. Anything in argv is visible to every
 * user on the box for the life of the process, and one of these fields is a
 * bucket credential.
 *
 * Concurrency is handled by the runner itself: a run that finds another in
 * progress — either profile — reports itself skipped rather than racing it.
 *
 * @version 1.2 - a manager-profile run no longer accepts a recovery key on stdin; encryption is
 *                pinned to this site's own proven key and a supplied one is refused
 * @version 1.1 - --profile, manager-profile config read from stdin, and the machine-readable
 *                BACKUP_RESULT / BACKUP_TIME contract lines for a control plane parsing the output
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
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

$profile = BackupProfile::SITE;
foreach (array_slice($argv, 1) as $arg) {
	if (strpos($arg, '--profile=') === 0) {
		$profile = substr($arg, strlen('--profile='));
	}
}

try {
	$profile = BackupProfile::normalize($profile);
} catch (BackupProfileException $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(2);
}

$config = array('profile' => $profile);

if ($profile === BackupProfile::MANAGER) {
	$raw = stream_get_contents(STDIN);
	$supplied = json_decode((string)$raw, true);
	if (!is_array($supplied)) {
		fwrite(STDERR, "A manager-profile run needs its configuration as JSON on stdin.\n");
		exit(2);
	}

	// The credential arrives base64-encoded so it survives being carried through
	// a shell heredoc without quoting surprises, and so the only place it is ever
	// readable is this process's memory.
	if (isset($supplied['credentials_b64'])) {
		$decoded = base64_decode((string)$supplied['credentials_b64'], true);
		$supplied['credentials'] = ($decoded === false) ? null : json_decode($decoded, true);
		unset($supplied['credentials_b64']);
	}

	$config['manager'] = $supplied;
}

$started = gmdate('Y-m-d H:i:s');
$result = BackupRunner::run($config);

echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $profile . ' '
	. ($result['status'] ?? '?') . ': ' . ($result['message'] ?? '') . "\n";

// Machine-readable lines for a control plane parsing the step output. The
// human line above is not a contract; these are. The time is when this run
// STARTED, matching what the history rows record — so a control plane stamping
// it holds the same value a later status check would copy from history, and
// "when was this node last backed up" means one thing however it was learned.
echo 'BACKUP_RESULT=' . ($result['status'] ?? 'error') . "\n";
echo 'BACKUP_TIME=' . $started . "\n";

exit((($result['status'] ?? '') === 'error') ? 1 : 0);
