<?php
/**
 * package_fix_auto.php - one run of the automatic package fix runner
 * (PackageFixAuto, specs/package_fixes_automatic.md WP9).
 *
 *   php public_html/plugins/server_manager/includes/package_fix_auto.php
 *
 * Root, from joinery-package-fix-auto.timer (security_check.sh install), every
 * ten minutes: a build needs the build root and writes static_files/, which
 * the web user cannot. One run at a time: a second one started while a build
 * is still going (an hour for PHP with its tests) exits at once.
 *
 * Prints the run's message and always exits 0: a run that stopped says so in
 * its message, on the Fleet Security page and, where a person is needed, by
 * email. A failed unit would open a failed-service incident on this box,
 * which the soak reads as a test machine failing.
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}
require_once(__DIR__ . '/../../../includes/PathHelper.php');

$lock_dir = '/var/lib/joinery-package-fixes-build';
if (!is_dir($lock_dir) && !@mkdir($lock_dir, 0755, true)) {
	echo "package fix runner: STOPPED - cannot create {$lock_dir}\n";
	exit(0);
}
$lock = fopen($lock_dir . '/.auto.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
	echo "package fix runner: another run is still going\n";
	exit(0);
}
// The Server Manager plugin is off on this site: its classes do not resolve.
if (!class_exists('PackageFixAuto')) {
	echo "package fix runner: the Server Manager plugin is not active on this site\n";
	exit(0);
}
try {
	$r = PackageFixAuto::run();
	echo 'package fix runner: ', ($r['status'] === 'error' ? 'STOPPED - ' : ''), $r['message'], "\n";
} catch (\Throwable $e) {
	echo 'package fix runner: STOPPED - ', $e->getMessage(), "\n";
}
exit(0);
