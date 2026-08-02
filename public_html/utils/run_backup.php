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
 * Concurrency is handled by the runner itself: a run that finds another in
 * progress reports itself skipped rather than racing it.
 *
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
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

$result = BackupRunner::run();

echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] '
	. ($result['status'] ?? '?') . ': ' . ($result['message'] ?? '') . "\n";
exit((($result['status'] ?? '') === 'error') ? 1 : 0);
