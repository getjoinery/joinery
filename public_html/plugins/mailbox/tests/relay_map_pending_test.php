<?php
/** @joinery-test
 * name: relay_map_pending
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The relay alias map's pending grade (ProvisioningCheckPending): a differing
 * map is a WAIT while the reconcile task is alive and succeeding, and a
 * FAILURE only when the task will not converge it. Covers:
 *
 *  - relayMapConverging(): the pure grading rule, driven with an injected
 *    clock — recent success, stale success, error status, never ran.
 *  - ProvisioningCheckPending is a subclass of ProvisioningCheckFailed, so
 *    every existing catch still treats pending as unmet (never as healthy).
 *  - The relay card grades: a pending dot renders WARN, a failing dot FAIL,
 *    and pending never outranks a real failure.
 *
 * Run: php tests/run.php safe --filter=relay_map_pending
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/ProvisioningCheckFailed.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));

section('relayMapConverging grading rule');
$now = '2026-08-08 15:30:00';
check(InboundEmailHealth::relayMapConverging('2026-08-08 15:28:00', 'success', $now) === true,
	'a success two minutes ago is converging');
check(InboundEmailHealth::relayMapConverging('2026-08-08 15:15:00', 'success', $now) === true,
	'a success at the edge of the window (15 min) still counts');
check(InboundEmailHealth::relayMapConverging('2026-08-08 15:14:59', 'success', $now) === false,
	'a success older than the window is not converging — the task has missed ticks');
check(InboundEmailHealth::relayMapConverging('2026-08-08 15:29:00', 'error', $now) === false,
	'a recent ERROR run is never converging — the push machinery is the fault');
check(InboundEmailHealth::relayMapConverging(null, null, $now) === false,
	'a task that never ran is not converging');
check(InboundEmailHealth::relayMapConverging('', 'success', $now) === false,
	'an empty run time is not converging');
check(InboundEmailHealth::relayMapConverging('2026-08-08 15:28:00.123456', 'success', $now) === true,
	'fractional-second timestamps compare on their first 19 characters');

section('Pending stays unmet for every existing catch');
$pending = new ProvisioningCheckPending('queued');
check($pending instanceof ProvisioningCheckFailed,
	'ProvisioningCheckPending IS a ProvisioningCheckFailed — nothing reads pending as healthy');
$caught = 'none';
try {
	throw new ProvisioningCheckPending('queued');
} catch (ProvisioningCheckFailed $e) {
	$caught = 'failed-catch';
}
check($caught === 'failed-catch', 'an existing catch of ProvisioningCheckFailed still catches it');

section('The relay card grades pending as WARN, never over a failure');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
// The grading lives in relay_admin's $side closure; assert its inputs and
// outputs through the source contract — the closure reads dot['pending'] and
// maps waiting-without-failing to WARN. Source assertion keeps this safe-tier
// (building real dots needs an active relay).
$src = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));
check(strpos($src, "catch (ProvisioningCheckPending ") !== false,
	'the health battery catches the pending grade separately');
check(strpos($src, "'pending' => \$pending") !== false,
	'the battery reports pending on the dot');
check(!empty($src) && preg_match('/if \(!empty\(\$waiting\)\) \{\s*\n\s*return \$row\(\$id, \'Relay\', InboundEmailSetupCheck::WARN/', $src) === 1,
	'waiting-without-failing renders the relay card as WARN');
$fail_pos = strpos($src, "if (!empty(\$failing)) {");
$warn_pos = strpos($src, "if (!empty(\$waiting)) {");
check($fail_pos !== false && $warn_pos !== false && $fail_pos < $warn_pos,
	'a real failure is graded before pending — pending never masks a broken check');

harness_finish();
