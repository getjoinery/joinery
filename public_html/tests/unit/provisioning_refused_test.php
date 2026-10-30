<?php
/** @joinery-test
 * name: provisioning_refused
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [public_html/plugins/**plugin.json]
 */
/**
 * What an unmet setup check offers as its fix (PluginProvisioning::runOne).
 * A check the plugin's own host installer fixes declares
 * `fixed_by_installer`, and the Plugins page offers Run setup again; no check
 * names a script to type (plugin_installer_from_the_browser.md D3).
 * Covers:
 *
 *  - ProvisioningCheckRefused is a subclass of ProvisioningCheckFailed, so
 *    every existing catch still treats it as unmet.
 *  - A plain failure declared fixed_by_installer is offered the installer; a
 *    refusal (cannot be met on this site) is offered nothing, and keeps its
 *    reason. Neither carries a script or a command.
 *  - A declaration still carrying `script` is an error naming
 *    fixed_by_installer; fixed_by_installer on a plugin with no
 *    host_installer is an error.
 *
 * Run: php tests/run.php safe --filter=provisioning_refused
 *
 * @version 1.1 - fixed_by_installer replaces the script and its sudo command
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

class ProvisioningRefusedTestChecks {
	public static function failed() { throw new ProvisioningCheckFailed('Not installed on this server.'); }
	public static function refused() { throw new ProvisioningCheckRefused('Not installed: this site runs in a container'); }
}

function provisioning_refused_run($method, $plugin = 'office_editor', array $extra = array('fixed_by_installer' => true)) {
	$run = new ReflectionMethod('PluginProvisioning', 'runOne');
	$run->setAccessible(true);
	return $run->invoke(null, $plugin, array_merge(array(
		'key' => 'editor_installed',
		'label' => 'Document editor installed on this server',
		'check' => array('type' => 'code', 'call' => 'ProvisioningRefusedTestChecks::' . $method),
	), $extra));
}

section('The exception');
check(is_subclass_of('ProvisioningCheckRefused', 'ProvisioningCheckFailed'),
	'a refusal is a ProvisioningCheckFailed, so every existing catch treats it as unmet');

section('The fix');
$failed = provisioning_refused_run('failed');
check($failed['state'] === 'unmet', 'a plain failure is unmet');
check(($failed['fixed_by_installer'] ?? null) === true, 'a plain failure the installer fixes is offered Run setup again');
check(!isset($failed['script_command']) && !isset($failed['script']), 'no check is offered a command to type');

$plain = provisioning_refused_run('failed', 'office_editor', array());
check($plain['state'] === 'unmet' && !isset($plain['fixed_by_installer']),
	'a failure not declared fixed_by_installer is offered nothing: it is configuration fixed on a page');

$refused = provisioning_refused_run('refused');
check($refused['state'] === 'unmet', 'a refusal is unmet');
check($refused['reason'] === 'Not installed: this site runs in a container', 'a refusal keeps its reason');
check(!isset($refused['fixed_by_installer']) && !isset($refused['script_command']), 'a refusal is offered no fix');
check(!isset($refused['refused']), 'the refusal marker stays inside the check, not in the result');

section('Declarations the page cannot honour');
$scripted = provisioning_refused_run('failed', 'office_editor', array('script' => 'provisioning/install_office_editor.sh'));
check($scripted['state'] === 'error' && strpos($scripted['reason'], 'fixed_by_installer') !== false,
	'a declaration still carrying script is an error that names fixed_by_installer');
check(PluginProvisioning::hasHostInstaller('office_editor') && !PluginProvisioning::hasHostInstaller('bookings'),
	'hasHostInstaller reads the manifest (office_editor has one, bookings has none)');
$orphan = provisioning_refused_run('failed', 'bookings');
check($orphan['state'] === 'error' && strpos($orphan['reason'], 'host_installer') !== false,
	'fixed_by_installer on a plugin with no host_installer is an error: the button would have nothing to run');

section('Shipped manifests');
foreach (glob(PathHelper::getAbsolutePath('plugins') . '/*/plugin.json') as $manifest_path) {
	$m = json_decode((string)file_get_contents($manifest_path), true);
	$name = basename(dirname($manifest_path));
	foreach ((array)($m['provisioners'] ?? array()) as $p) {
		check(!array_key_exists('script', (array)$p), "{$name}: {$p['key']} declares no script");
		if (!empty($p['fixed_by_installer'])) {
			check(!empty($m['host_installer']), "{$name}: {$p['key']} is fixed_by_installer and the plugin has a host_installer");
		}
	}
}

harness_finish();
