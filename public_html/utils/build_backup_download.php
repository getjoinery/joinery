<?php
/**
 * build_backup_download.php — build the backup a superadmin asked to download.
 *
 * Download a backup on /admin/admin_backups starts this detached, because a
 * whole-site archive takes far longer than a web request lives. It builds what
 * the download's state.json names and writes the outcome back there, where the
 * page reads it (BackupDownload). Run by hand it does the same, and prints the
 * outcome:
 *
 *   php utils/build_backup_download.php
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
require_once(PathHelper::getIncludePath('includes/BackupDownload.php'));

$s = BackupDownload::build();
if ($s === null) {
	echo "No download is waiting to be built.\n";
	exit(0);
}
echo $s['status'] . ($s['status'] === 'ready'
	? ': ' . BackupDownload::download_name($s) . ' (' . BackupRunner::human((int)$s['download_bytes']) . ')'
	: ': ' . ($s['message'] ?? '')) . "\n";
exit($s['status'] === 'ready' ? 0 : 1);
