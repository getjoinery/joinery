<?php
/** @joinery-test
 * name: provisioning_refused
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The cannot-be-met-here grade (ProvisioningCheckRefused): a check that
 * throws it is unmet, like any failed check, but is offered no fix command,
 * since the provisioner's script would only refuse again. Covers:
 *
 *  - ProvisioningCheckRefused is a subclass of ProvisioningCheckFailed, so
 *    every existing catch still treats it as unmet.
 *  - PluginProvisioning: a plain failure with a script gets its command; a
 *    refusal with the same script gets none, and keeps its reason.
 *
 * Run: php tests/run.php safe --filter=provisioning_refused
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

class ProvisioningRefusedTestChecks {
	public static function failed() { throw new ProvisioningCheckFailed('Not installed on this server.'); }
	public static function refused() { throw new ProvisioningCheckRefused('Not installed: this site runs in a container'); }
}

function provisioning_refused_run($method) {
	$run = new ReflectionMethod('PluginProvisioning', 'runOne');
	$run->setAccessible(true);
	return $run->invoke(null, 'office_editor', array(
		'key' => 'editor_installed',
		'label' => 'Document editor installed on this server',
		'check' => array('type' => 'code', 'call' => 'ProvisioningRefusedTestChecks::' . $method),
		'script' => 'provisioning/install_office_editor.sh',
	));
}

section('The exception');
check(is_subclass_of('ProvisioningCheckRefused', 'ProvisioningCheckFailed'),
	'a refusal is a ProvisioningCheckFailed, so every existing catch treats it as unmet');

section('The fix command');
$failed = provisioning_refused_run('failed');
check($failed['state'] === 'unmet', 'a plain failure is unmet');
check(isset($failed['script_command']) && str_ends_with($failed['script_command'], 'plugins/office_editor/provisioning/install_office_editor.sh'),
	'a plain failure with a script is offered the script as its fix command');

$refused = provisioning_refused_run('refused');
check($refused['state'] === 'unmet', 'a refusal is unmet');
check($refused['reason'] === 'Not installed: this site runs in a container', 'a refusal keeps its reason');
check(!isset($refused['script_command']) && !isset($refused['script']), 'a refusal is offered no fix command');
check(!isset($refused['refused']), 'the refusal marker stays inside the check, not in the result');

harness_finish();
