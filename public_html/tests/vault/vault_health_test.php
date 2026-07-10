<?php
/** @joinery-test
 * name: vault_health
 * tier: safe
 * env: any
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/VaultHealth.php'));

section('Report shape');
$checks = VaultHealth::runAll();
check(count($checks) === 3, 'runAll reports the three host facts');
$valid_states = ['verified', 'unmet', 'unknown'];
$all_valid = true;
$has_fields = true;
foreach ($checks as $c) {
	if (!in_array($c['state'] ?? '', $valid_states, true)) { $all_valid = false; }
	if (!isset($c['key'], $c['label'], $c['reason'])) { $has_fields = false; }
}
check($all_valid, 'every check reports verified, unmet, or unknown - never a bare pass');
check($has_fields, 'every check carries key, label, and reason');

section('Never a false pass');
// An unverifiable fact must be unknown, not verified: every unmet/unknown
// check must explain itself.
$silent = 0;
foreach ($checks as $c) {
	if ($c['state'] !== 'verified' && trim((string)$c['reason']) === '') { $silent++; }
}
check($silent === 0, 'no unmet/unknown check is silent about why');

harness_finish();
?>
