<?php
/** @joinery-test
 * name: api_action_feature_gate
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A switched-off feature has no API actions.
 *
 * serve.php gates a feature's pages with check_setting, but the API action face
 * never passes through serve.php. Before `requires_setting`, the only thing
 * stopping a disabled feature's action from running was whether its author
 * remembered to re-check the setting by hand — and for seven of the thirty
 * Drive actions, nobody had (specs/api_action_feature_gate.md).
 *
 * That is the shape of failure this suite exists to catch, so it tests the
 * mechanism *and* the coverage. The mechanism half drives
 * ApiLogicEndpoint::assertSettingEnabled() directly. The coverage half asserts
 * every drive_* action declares the gate, which is the check that fires the day
 * someone adds action thirty-one and forgets — the original bug, prevented
 * rather than re-found.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/api_action_feature_gate_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/**
 * Stand in for the real api_error(), which lives in apiv1.php and exits.
 * Defining it here lets the guard be called and observed instead of ending the
 * process. Declared before ApiLogicEndpoint loads so the class binds to it.
 */
if (!function_exists('api_error')) {
	function api_error($message, $error_type = 'TransactionError', $status_code = 400) {
		throw new RuntimeException($status_code . '|' . $error_type . '|' . $message);
	}
}

require_once(PathHelper::getIncludePath('includes/ApiLogicEndpoint.php'));

/** Expose the protected guard. */
class FeatureGateProbe extends ApiLogicEndpoint {
	public static function probe($meta, $label = 'test_action') {
		self::assertSettingEnabled($meta, $label);
	}
}

/**
 * Run the guard and report what it did: 'allowed', or the refusal parts.
 */
function gate($meta) {
	try {
		FeatureGateProbe::probe($meta);
		return array('allowed' => true);
	} catch (RuntimeException $e) {
		list($status, $type, $message) = explode('|', $e->getMessage(), 3);
		return array(
			'allowed' => false,
			'status'  => (int)$status,
			'type'    => $type,
			'message' => $message,
		);
	}
}

// A setting name nothing declares, so get_setting() is reliably falsy whatever
// this instance has configured.
$OFF = 'zz_feature_that_does_not_exist_active';

section('An undeclared gate lets everything through');

check(gate(array())['allowed'] === true,
	'A descriptor with no requires_setting is unaffected');
check(gate(array('requires_session' => true))['allowed'] === true,
	'Other descriptor keys do not accidentally gate');
check(gate(array('requires_setting' => null))['allowed'] === true,
	'An explicit null gate is treated as no gate');
check(gate(array('requires_setting' => ''))['allowed'] === true,
	'An empty-string gate is treated as no gate, not as a falsy setting');

section('A gate on an off setting refuses');

$refused = gate(array('requires_setting' => $OFF));
check($refused['allowed'] === false,
	'An action gated on an off setting is refused');
check($refused['status'] === 403,
	'Refusal is 403 — the action exists, the caller may not invoke it');
check($refused['status'] !== 404,
	'Not 404: calling it unknown sends a developer hunting a typo that is not there');
check($refused['status'] !== 422,
	'Not 422: nothing is wrong with the caller input');
check($refused['type'] === 'ActionError',
	'Refusal carries the ActionError type');
check(strpos($refused['message'], $OFF) !== false,
	'The message names the setting that is off');

section('A gate on an on setting allows');

// site_name is set on every instance the harness can boot, so a truthy gate is
// testable without writing a setting.
$settings = Globalvars::get_instance();
$on_setting = 'site_name';
check((bool)$settings->get_setting($on_setting) === true,
	'Precondition: ' . $on_setting . ' is truthy on this instance');
check(gate(array('requires_setting' => $on_setting))['allowed'] === true,
	'An action gated on an on setting runs');

section('Both dispatch chokepoints enforce the gate');

// Enforcement has to sit where every request resolves a descriptor. A new
// dispatch path that skips it would reopen the hole silently, so the wiring is
// asserted rather than assumed.
$endpoint_src = file_get_contents(PathHelper::getIncludePath('includes/ApiLogicEndpoint.php'));

foreach (array('resolveAction', 'resolveForm') as $chokepoint) {
	$start = strpos($endpoint_src, 'function ' . $chokepoint . '(');
	$body = $start === false ? '' : substr($endpoint_src, $start, 2500);
	check($start !== false && strpos($body, 'assertSettingEnabled') !== false,
		$chokepoint . '() calls assertSettingEnabled()');
}

check(strpos($endpoint_src, 'protected static function assertSettingEnabled') !== false,
	'The guard is a shared helper, not copy-pasted per face');

section('Discovery hides an action whose feature is off');

$apiv1_src = file_get_contents(PathHelper::getIncludePath('api/apiv1.php'));
$disc_at = strpos($apiv1_src, '$discover_actions = function');
$disc_body = $disc_at === false ? '' : substr($apiv1_src, $disc_at, 2000);

check($disc_at !== false && strpos($disc_body, 'requires_setting') !== false,
	'GET /api/v1/actions filters on requires_setting');
check(strpos($disc_body, 'use ($settings)') !== false,
	'The discovery closure captures $settings, so the filter can actually read one');

section('Every Drive action declares the gate');

$drive_files = glob(PathHelper::getIncludePath('logic') . '/drive_*_logic.php');
check(count($drive_files) >= 30,
	'Found the Drive logic files (' . count($drive_files) . ')');

$ungated = array();
foreach ($drive_files as $file) {
	$src = file_get_contents($file);
	// Only actions exposed to the API need the gate; an unexposed helper is
	// unreachable from the face this protects.
	if (strpos($src, '_logic_descriptor') === false) {
		continue;
	}
	if (!preg_match("/'requires_setting'\s*=>\s*'drive_active'/", $src)) {
		$ungated[] = basename($file);
	}
}
check(empty($ungated),
	'Every API-exposed Drive action is gated on drive_active',
	$ungated ? 'ungated: ' . implode(', ', $ungated) : '');

section('The seven that were open are closed');

// Named individually: these are the actions that were callable with Drive off,
// and a regression in any one of them is worth its own failing line.
$was_open = array(
	'drive_device_link_approve' => 'linked a computer and minted its credential',
	'drive_device_link_deny'    => 'refused a pending link',
	'drive_device_link_info'    => 'described the machine requesting a link',
	'drive_device_rename'       => 'renamed a linked device',
	'drive_device_revoke'       => 'unlinked a device and revoked its key',
	'drive_devices'             => 'listed the caller linked computers',
	'drive_vault_status'        => 'reported vault key material',
);

foreach ($was_open as $action => $what_it_did) {
	$path = PathHelper::getIncludePath('logic') . '/' . $action . '_logic.php';
	$src = file_exists($path) ? file_get_contents($path) : '';
	check($src !== '' && preg_match("/'requires_setting'\s*=>\s*'drive_active'/", $src) === 1,
		$action . ' is gated (with Drive off it ' . $what_it_did . ')');
}

harness_finish();
