<?php
/**
 * Remove installed composer packages the shipped code no longer needs.
 *
 * Run by utils/upgrade.php as root, after its last rollback point. The work
 * is ComposerValidator::pruneUnneededPackages(): copy vendor/, install into
 * the copy, swap it in, so vendor/ never stops loading.
 *
 * Exit codes: 0 nothing to remove, 10 packages removed or vendor/ put back
 * after an interrupted prune (the caller shows the log and reloads web PHP),
 * 1 the prune stopped (the log says where vendor/ stands).
 *
 * @version 1.1 - a run that only put vendor/ back exits 10 too (reviewer2 B19c)
 * @version 1.0 - first version (specs/email_provider_sdk_removal.md WP8)
 */
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	die('This script can only be run from the command line.');
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

$validator = new ComposerValidator();
$ok = $validator->pruneUnneededPackages();
$log = $validator->getPruneLog();
foreach ($log as $line) {
	echo $line . "\n";
}
if (!$ok) {
	exit(1);
}
$removed = false;
foreach ($log as $line) {
	// A run that only put vendor/ back changed it too: the caller shows the
	// log and reloads PHP.
	if ((strpos($line, 'Removed ') === 0 && strpos($line, 'package(s)') !== false)
			|| strpos($line, 'Put back vendor/') === 0) {
		$removed = true;
	}
}
exit($removed ? 10 : 0);
