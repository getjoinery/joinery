<?php
/** @joinery-test
 * name: plugin_bootstrap
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 *
 * The plugin bootstrap key: every plugin gets a load point, not just vault
 * consumers. A plugin declares a top-level `bootstrap` in plugin.json;
 * PluginBootstraps loads it once per request, attributed, in consumer order —
 * and a plugin with no vaultConsumer block can register an upload purpose, a
 * File hook, a policy callable, without owing the vault anything.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/PluginBootstraps.php'));
require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));

// The fixture bootstraps stand in for plugins/{name}/includes/bootstrap.php;
// the seam's 'path' override points them into the test tree.
$probe_bootstrap          = PathHelper::getIncludePath('tests/vault/fixtures/probe_bootstrap.php');
$probe_consumer_bootstrap = PathHelper::getIncludePath('tests/vault/fixtures/probe_consumer_bootstrap.php');

// ---------------------------------------------------------------------------
section('A bootstrap-only plugin loads and can register an upload purpose');
// ---------------------------------------------------------------------------
VaultUnlock::resetForTests();
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'probe' => array(
		'declaration' => null,
		'active'      => true,
		'bootstrap'   => 'includes/bootstrap.php',
		'path'        => $probe_bootstrap,
	),
	'probe_consumer' => array(
		'declaration' => array('order' => 30, 'reseals' => true),
		'active'      => true,
		'bootstrap'   => 'includes/bootstrap.php',
		'path'        => $probe_consumer_bootstrap,
	),
));

$registered = VaultConsumers::registered();
check(isset($registered['probe']), 'the bootstrap-only plugin is a registered load point');
check($registered['probe']['reseals'] === false && $registered['probe']['caches'] === false,
	'with no vault obligations — consumership was never the price of a load point');
check($registered['probe']['order'] === VaultConsumers::DEFAULT_ORDER,
	'and the default order, after every consumer that declared one');

PluginBootstraps::load();

$spec = UploadPurposeRegistry::get('harness_probe_purpose');
check($spec !== null, 'its bootstrap ran and the upload purpose is registered');
check(($spec['label'] ?? '') === 'harness probe upload', 'with the spec the bootstrap declared');

$unmet = VaultConsumers::unmetObligations();
check(!isset($unmet['probe']), 'the bootstrap-only plugin owes the vault nothing');

// ---------------------------------------------------------------------------
section('A consumer attributes correctly with the bootstrap in its plugin.json top level');
// ---------------------------------------------------------------------------
$counts = VaultConsumers::registrationCounts();
check(($counts['probe_consumer']['reseals'] ?? 0) >= 1,
	'the consumer fixture\'s onReseal attributed to it through the loader');
check(!isset($unmet['probe_consumer']), 'so its declared obligation reads as met');

// ---------------------------------------------------------------------------
section('A declared-but-missing bootstrap is skipped, logged, and fails the caps closed');
// ---------------------------------------------------------------------------
VaultUnlock::resetForTests();
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'ghostly' => array(
		'declaration' => array('reseals' => true),
		'active'      => true,
		'bootstrap'   => 'includes/nothing.php',
	),
));

PluginBootstraps::load();
check(in_array('ghostly', PluginBootstraps::notLoaded(), true),
	'the consumer whose bootstrap never ran is recorded');
$unmet = VaultConsumers::unmetObligations();
check(isset($unmet['ghostly']), 'its reseal obligation reads unmet, so a rotation would refuse');

$caps = VaultUnlock::capsForUser(1);
check($caps['idle'] === VaultUnlock::FORTRESS_IDLE_CAP_SECONDS
		&& $caps['absolute'] === VaultUnlock::FORTRESS_ABSOLUTE_CAP_SECONDS,
	'and unlock windows fail closed to the Fortress caps while it is missing');

// ---------------------------------------------------------------------------
section('A vaultConsumer without a top-level bootstrap keeps its obligations');
// ---------------------------------------------------------------------------
// The fail-safe direction: a resealer that lost its load point must refuse
// rotation, not vanish from the guard.
VaultUnlock::resetForTests();
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'keyless' => array('declaration' => array('reseals' => true), 'active' => true),
));
PluginBootstraps::load();
check(in_array('keyless', PluginBootstraps::notLoaded(), true),
	'a consumer with no bootstrap key at all is recorded as not loaded');
$unmet = VaultConsumers::unmetObligations();
check(isset($unmet['keyless']), 'and its reseal obligation still stands');

VaultUnlock::resetForTests();
VaultConsumers::resetForTests();

harness_finish();
?>
