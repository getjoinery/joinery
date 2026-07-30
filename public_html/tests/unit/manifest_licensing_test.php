<?php
/** @joinery-test
 * name: manifest_licensing
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Licensing surface of the plugin manifests and license files.
 *
 * The open-core model rests on facts that live in files, not code: the core
 * license is PolyForm Shield with the plugin/theme exception intact, every
 * first-party plugin carries its own LICENSE.md that agrees with its
 * manifest's license field, the two commercial plugins (and only they)
 * declare requires_entitlement, no plugin declares is_system anymore, and
 * the maturity status field only ever holds a known value. Each of those is
 * a one-line regression if a manifest edit drops it, so this suite pins them.
 *
 * The unknown-status rejection is exercised through PluginManager's real
 * validatePlugin() against a throwaway plugin directory, cleaned at exit.
 *
 * Run: php tests/unit/manifest_licensing_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

$STATUS_ENUM = array('experimental', 'beta', 'stable', 'deprecated');
$COMMERCIAL_LICENSE = 'Joinery-Commercial';
$SHIELD_LICENSE = 'PolyForm-Shield-1.0.0';

// ---------------------------------------------------------------------------
section('Core license');
// ---------------------------------------------------------------------------

$core_license_path = PathHelper::getAbsolutePath('LICENSE.md');
if (!file_exists($core_license_path)) {
	$core_license_path = PathHelper::getSiteRoot() . '/LICENSE.md';
}
check(file_exists($core_license_path), 'core LICENSE.md exists', $core_license_path);
$core_license = file_exists($core_license_path) ? file_get_contents($core_license_path) : '';

check(strpos($core_license, 'PolyForm Shield License 1.0.0') !== false,
	'core license is PolyForm Shield 1.0.0');
check(strpos($core_license, 'Noncommercial') === false,
	'no leftover Noncommercial text in the core license');
check(strpos($core_license, '## Plugin and Theme Exception') !== false,
	'plugin and theme exception carried over');
check(strpos($core_license, 'Required Notice: Copyright Joinery') !== false,
	'Required Notice line carried over');

// ---------------------------------------------------------------------------
section('Plugin manifests and license files');
// ---------------------------------------------------------------------------

$plugin_dir = PathHelper::getAbsolutePath('plugins');
$manifests = array();
foreach (glob($plugin_dir . '/*/plugin.json') as $json_file) {
	$name = basename(dirname($json_file));
	$data = json_decode(file_get_contents($json_file), true);
	check(is_array($data), "$name: plugin.json parses", json_last_error_msg());
	if (is_array($data)) {
		$manifests[$name] = $data;
	}
}
check(count($manifests) >= 9, 'found the first-party plugin set', count($manifests) . ' manifests');

$entitled = array();
foreach ($manifests as $name => $manifest) {
	$license = $manifest['license'] ?? null;
	check(is_string($license) && $license !== '', "$name: manifest declares a license", var_export($license, true));

	$license_file = $plugin_dir . '/' . $name . '/LICENSE.md';
	check(file_exists($license_file), "$name: LICENSE.md present");
	$license_text = file_exists($license_file) ? file_get_contents($license_file) : '';

	if ($license === $SHIELD_LICENSE) {
		check(strpos($license_text, 'PolyForm Shield License 1.0.0') !== false,
			"$name: LICENSE.md agrees with manifest (Shield)");
	} elseif ($license === $COMMERCIAL_LICENSE) {
		check(strpos($license_text, 'Joinery Commercial Plugin License') !== false,
			"$name: LICENSE.md agrees with manifest (commercial)");
		check(strpos($license_text, 'one production instance') !== false,
			"$name: commercial license states the one-instance grant");
	} else {
		check(false, "$name: license value is a known license", (string)$license);
	}

	// No plugin is a system plugin anymore — files must never arrive as a
	// side effect of a core upgrade.
	check(empty($manifest['is_system']), "$name: does not declare is_system");

	if (array_key_exists('status', $manifest)) {
		check(in_array($manifest['status'], $STATUS_ENUM, true),
			"$name: status value is in the enum", (string)$manifest['status']);
	}

	if (!empty($manifest['requires_entitlement'])) {
		$entitled[] = $name;
		check($license === $COMMERCIAL_LICENSE,
			"$name: entitlement implies the commercial license", (string)$license);
	}
}

sort($entitled);
check($entitled === array('server_manager', 'store'),
	'exactly store and server_manager require entitlement', implode(',', $entitled));

check(($manifests['mailbox']['status'] ?? null) === 'beta', 'mailbox is labeled beta');
check(($manifests['vault']['status'] ?? null) === 'experimental', 'vault is labeled experimental');
check(!array_key_exists('status', $manifests['event_manager'] ?? array()),
	'event_manager carries no maturity badge');

// ---------------------------------------------------------------------------
section('Unknown status is a manifest validation error');
// ---------------------------------------------------------------------------

$tmp_name = 'zz_lictest_' . getmypid();
$tmp_dir = $plugin_dir . '/' . $tmp_name;
mkdir($tmp_dir, 0777, true);
harness_defer(function () use ($tmp_dir) {
	@unlink($tmp_dir . '/plugin.json');
	@rmdir($tmp_dir);
});

$manager = new PluginManager();
$validate = new ReflectionMethod('PluginManager', 'validatePlugin');
$validate->setAccessible(true);

file_put_contents($tmp_dir . '/plugin.json', json_encode(array(
	'name' => 'Licensing Test Fixture', 'version' => '0.0.1', 'status' => 'alpha-ish',
)));
$result = $validate->invoke($manager, $tmp_name);
check($result['valid'] === false, 'unknown status value rejected');
check((bool)preg_grep('/status/i', $result['errors']), 'rejection names the status field',
	implode('; ', $result['errors']));

file_put_contents($tmp_dir . '/plugin.json', json_encode(array(
	'name' => 'Licensing Test Fixture', 'version' => '0.0.1', 'status' => 'beta',
)));
$result = $validate->invoke($manager, $tmp_name);
check($result['valid'] === true, 'known status value accepted', implode('; ', $result['errors']));

harness_finish();
