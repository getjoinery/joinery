<?php
/**
 * Setting get/set helper for the iOS client gate runner (phase2_gate.sh).
 * Thin CLI over the API test harness's raw setting helpers, with the same
 * dev-only guard the API suites use.
 *
 * Usage:
 *   php setting_ctl.php get <name>
 *   php setting_ctl.php set <name> <value>
 *   php setting_ctl.php set_min_version <client_app> <version>   (merges into api_min_client_versions JSON)
 *
 * @version 1.0.0
 */

require_once('/var/www/html/joinerytest/public_html/tests/functional/api/api_test_harness.php');
harness_require_debug_mode();

$cmd = $argv[1] ?? '';
$name = $argv[2] ?? '';

switch ($cmd) {
	case 'get':
		$value = get_setting_raw($name);
		echo $value === null ? '' : $value;
		echo "\n";
		break;

	case 'set':
		if (!isset($argv[3])) { fwrite(STDERR, "set requires a value\n"); exit(1); }
		set_setting_raw($name, $argv[3]);
		break;

	case 'set_min_version':
		// $name is the client_app here; $argv[3] the version to require.
		if (!isset($argv[3])) { fwrite(STDERR, "set_min_version requires a version\n"); exit(1); }
		$raw = get_setting_raw('api_min_client_versions');
		$map = json_decode($raw ?: '{}', true);
		if (!is_array($map)) $map = array();
		$map[$name] = $argv[3];
		set_setting_raw('api_min_client_versions', json_encode($map));
		break;

	default:
		fwrite(STDERR, "unknown command '$cmd'\n");
		exit(1);
}
?>
