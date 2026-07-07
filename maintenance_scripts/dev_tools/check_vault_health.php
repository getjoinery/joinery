#!/usr/bin/env php
<?php
/**
 * Sealed Vault host-hardening check (CLI). Prints VaultHealth::runAll() and
 * exits non-zero when anything is 'unmet'. See includes/VaultHealth.php.
 *
 * Usage: php check_vault_health.php
 */

$bootstrap_path = __DIR__ . '/../../public_html/includes/PathHelper.php';
if (!file_exists($bootstrap_path)) {
	fwrite(STDERR, "ERROR: Cannot find PathHelper.php at: $bootstrap_path\n");
	exit(2);
}
require_once($bootstrap_path);
require_once(PathHelper::getIncludePath('includes/VaultHealth.php'));

$results = VaultHealth::runAll();
$exit_code = 0;

foreach ($results as $result) {
	$badge = ['verified' => 'OK', 'unmet' => 'FAIL', 'unknown' => '??'][$result['state']] ?? '??';
	echo "[$badge] {$result['label']}\n";
	if ($result['reason'] !== '') {
		echo "       {$result['reason']}\n";
	}
	if ($result['state'] === 'unmet') {
		$exit_code = 1;
	}
}

exit($exit_code);
?>
