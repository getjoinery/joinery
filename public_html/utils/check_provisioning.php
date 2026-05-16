<?php
/**
 * CLI provisioning checker — runs the same detection as the admin Plugins
 * page and prints which plugin dependencies are not working, with the fix
 * command where one is declared.
 *
 * Useful right after a deploy; install.sh / upgrade.php can call it to echo a
 * "plugins need setup" summary. Exits non-zero when anything is unmet or a
 * check is broken, so it can gate a script.
 *
 * Usage: php utils/check_provisioning.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI access only.\n";
    exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/PluginProvisioning.php'));

$results = PluginProvisioning::runChecks();

echo "Plugin Provisioning Checks\n";
echo "==========================\n\n";

$counts = ['verified' => 0, 'reachable' => 0, 'unmet' => 0, 'error' => 0];

if (empty($results)) {
    echo "No active plugin declares provisioners.\n";
    exit(0);
}

foreach ($results as $plugin => $provisioners) {
    echo $plugin . "\n";
    foreach ($provisioners as $r) {
        if (isset($counts[$r['state']])) {
            $counts[$r['state']]++;
        }
        printf("  [%-9s] %s\n", strtoupper($r['state']), $r['label']);
        if ($r['reason'] !== '') {
            echo "              Reason: " . $r['reason'] . "\n";
        }
        if (!empty($r['script_command'])) {
            echo "              Run:    " . $r['script_command'] . "\n";
        }
    }
    echo "\n";
}

printf(
    "Summary: %d verified, %d reachable, %d unmet, %d error\n",
    $counts['verified'], $counts['reachable'], $counts['unmet'], $counts['error']
);

exit(($counts['unmet'] > 0 || $counts['error'] > 0) ? 1 : 0);
