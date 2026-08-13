<?php
/** @joinery-test
 * name: vault_window_caps
 * tier: db
 * env: dev-only
 * needs: []
 *
 * How long an unlock window lasts. Every server-custody consumer shares one
 * window, so its length is a fold of every consumer's opinion — and the fold is
 * STRICTEST-WINS, because one window cannot honor two lengths and the member who
 * set the tight one meant it.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/PluginBootstraps.php'));

/** Start from a clean registry with no real consumer bootstraps loaded. */
function caps_reset(): void {
	VaultUnlock::resetForTests();
	// Mark the bootstraps loaded so capsForUser() folds only what this test
	// registers; the real consumers have their own coverage.
	$reflect = new ReflectionProperty('PluginBootstraps', 'loaded');
	$reflect->setAccessible(true);
	$reflect->setValue(null, true);
}

// ---------------------------------------------------------------------------
section('No providers means no caps');
// ---------------------------------------------------------------------------
caps_reset();
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === null && $caps['absolute'] === null,
	'an instance whose consumers have no window policy gets an uncapped window');

// ---------------------------------------------------------------------------
section('Strictest wins, per field');
// ---------------------------------------------------------------------------
caps_reset();
VaultUnlock::onWindowCaps(function (int $user_id): array {
	return array('idle' => 7200, 'absolute' => 86400);
});
VaultUnlock::onWindowCaps(function (int $user_id): array {
	return array('idle' => 1800, 'absolute' => 604800);
});
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === 1800, 'the tightest idle cap wins');
check($caps['absolute'] === 86400, 'the tightest absolute cap wins — folded per field, not per provider');

// ---------------------------------------------------------------------------
section('A null contribution is an abstention, not a cap of zero');
// ---------------------------------------------------------------------------
caps_reset();
VaultUnlock::onWindowCaps(function (int $user_id): array {
	return array('idle' => null, 'absolute' => 604800);   // the Private shape
});
VaultUnlock::onWindowCaps(function (int $user_id): array {
	return array('idle' => null, 'absolute' => null);      // no opinion at all
});
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === null, 'a consumer with no idle opinion does not impose one');
check($caps['absolute'] === 604800, 'and its absolute opinion still counts');

// ---------------------------------------------------------------------------
section('A provider that throws contributes its declared fail-closed caps');
// ---------------------------------------------------------------------------
caps_reset();
VaultUnlock::onWindowCaps(
	function (int $user_id): array {
		throw new RuntimeException('level lookup exploded');
	},
	array('idle' => 7200, 'absolute' => 86400)
);
VaultUnlock::onWindowCaps(function (int $user_id): array {
	return array('idle' => null, 'absolute' => null);
});
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === 7200 && $caps['absolute'] === 86400,
	'failing to resolve a policy tightens the window rather than removing the cap');

caps_reset();
VaultUnlock::onWindowCaps(function (int $user_id): array {
	throw new RuntimeException('exploded, and declared nothing');
});
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === VaultUnlock::FORTRESS_IDLE_CAP_SECONDS
		&& $caps['absolute'] === VaultUnlock::FORTRESS_ABSOLUTE_CAP_SECONDS,
	'a provider that never declared its failure mode fails closed to the Fortress caps — '
	. 'abstaining on error must be said explicitly, never defaulted into');

// ---------------------------------------------------------------------------
section('A declared bootstrap missing on disk fails the whole fold closed');
// ---------------------------------------------------------------------------
// The consumer that never loaded may have been the one carrying the strictest
// policy; its absence must tighten the window, not widen it.
caps_reset();
$reflect = new ReflectionProperty('PluginBootstraps', 'not_loaded');
$reflect->setAccessible(true);
$reflect->setValue(null, array('mailbox'));
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === VaultUnlock::FORTRESS_IDLE_CAP_SECONDS
		&& $caps['absolute'] === VaultUnlock::FORTRESS_ABSOLUTE_CAP_SECONDS,
	'an unloadable consumer bootstrap arms the Fortress caps instead of an uncapped window');

caps_reset();
$reflect->setValue(null, array('mailbox'));
VaultUnlock::onWindowCaps(function (int $user_id): array {
	return array('idle' => 900, 'absolute' => null);
});
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === 900 && $caps['absolute'] === VaultUnlock::FORTRESS_ABSOLUTE_CAP_SECONDS,
	'a surviving provider can still tighten further — the fail-closed floor folds like any other opinion');

// ---------------------------------------------------------------------------
section('One broken provider does not take the others with it');
// ---------------------------------------------------------------------------
caps_reset();
VaultUnlock::onWindowCaps(function (int $user_id) {
	throw new RuntimeException('broken');
}, array());
VaultUnlock::onWindowCaps(function (int $user_id): array {
	return array('idle' => 900, 'absolute' => null);
});
$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === 900, 'a working provider is still folded after a broken one');

VaultUnlock::resetForTests();
harness_finish();
?>
