<?php
/** @joinery-test
 * name: sealed_consumer_declaration
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 180
 *
 * The obligations a vault consumer declares, and what happens when it does not
 * keep them.
 *
 * The one that matters is `reseals`. A consumer holding sealed content with no
 * re-seal callback loses that content the first time the member rotates their
 * key — silently, permanently, with no error anywhere. This suite is what makes
 * that impossible to ship: the census below fails the build for any in-tree
 * model whose consumer has not declared the obligation, and the rotation guard
 * refuses at runtime for anything out of tree.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));

/** Capture whatever the code under test writes to error_log while $fn runs. */
function declaration_capture_log(callable $fn): string {
	$path = tempnam(sys_get_temp_dir(), 'vaultlog');
	$previous = ini_get('error_log');
	ini_set('error_log', $path);
	try {
		$fn();
	} finally {
		ini_set('error_log', $previous === false ? '' : $previous);
	}
	$contents = (string)@file_get_contents($path);
	@unlink($path);
	return $contents;
}

// ---------------------------------------------------------------------------
section('Census: every sealed model in the tree belongs to a declared resealer');
// ---------------------------------------------------------------------------
// $sealed_fields is a filesystem fact, so this is checkable rather than trusted.
// A model that declares sealed content while its consumer declares no reseal
// obligation is exactly the shape that loses member data on rotation.
$root = PathHelper::getIncludePath('');
$model_files = array_merge(
	glob(rtrim($root, '/') . '/data/*_class.php') ?: array(),
	glob(rtrim($root, '/') . '/plugins/*/data/*_class.php') ?: array()
);

$sealed_models = array();
foreach ($model_files as $file) {
	$source = (string)file_get_contents($file);
	if (!preg_match('/\$sealed_fields\s*=\s*array\s*\(\s*[\'"]/', $source)) {
		continue;   // no declaration, or an empty one
	}
	// \b matters: without it "extends SystemBaseException" matches too, and the
	// census names a file's exception class instead of its model.
	preg_match('/class\s+(\w+)\s+extends\s+SystemBase\b/', $source, $class_match);
	$sealed_models[] = array(
		'file'   => $file,
		'class'  => $class_match[1] ?? basename($file),
		'plugin' => preg_match('#/plugins/([^/]+)/data/#', $file, $m) ? $m[1] : '',
	);
}
check(count($sealed_models) >= 8, 'the census found the tree\'s sealed models (' . count($sealed_models) . ' of them)');

$declarations = VaultConsumers::allDeclarations();

// Where a core model's sealing is owned is not always core. A model can live in
// core because several consumers share its rows while exactly one package seals
// them — Message is the case: conversations are core, and the messenger plugin
// is what protects them. So the census reads EVERY resealing consumer's
// bootstrap, plugin or core, and asks whether any of them takes the model on.
// Narrowing this to core consumers would force a plugin's obligation to be
// declared in core, which is the opposite of how consumers are meant to work.
$resealer_source = '';
foreach ($declarations as $declaration) {
	if ($declaration['reseals'] && is_file($declaration['path'])) {
		$resealer_source .= (string)file_get_contents($declaration['path']);
	}
}

foreach ($sealed_models as $model) {
	if ($model['plugin'] !== '') {
		$declaration = $declarations[$model['plugin']] ?? null;
		check($declaration !== null && $declaration['reseals'] === true,
			$model['class'] . ' is covered by plugin "' . $model['plugin'] . '" declaring reseals: true');
		continue;
	}
	// A core model has no plugin of its own to declare for it, so some consumer
	// that does declare the obligation has to name it.
	check(strpos($resealer_source, $model['class']) !== false,
		$model['class'] . ' is named by a consumer declaring reseals: true');
}

// ---------------------------------------------------------------------------
section('The in-tree consumers register what they declared');
// ---------------------------------------------------------------------------
VaultUnlock::loadConsumerBootstraps();
$unmet = VaultConsumers::unmetObligations();
check($unmet === array(),
	'no active consumer declares an obligation it did not register: ' . json_encode($unmet));

$counts = VaultConsumers::registrationCounts();
check(($counts['mailbox']['reseals'] ?? 0) >= 1, 'the mailbox reseal callback attributes to the mailbox consumer');
check(($counts['mailbox']['caches'] ?? 0) >= 1, 'so does its window-wipe callback');
check(($counts['drive_sealed']['reseals'] ?? 0) >= 1, 'a CORE consumer attributes the same way a plugin does');
check(($counts['api_idempotency']['reseals'] ?? 0) >= 1,
	'the API idempotency store re-seals its cached bodies, so a rotation no longer strands them');

// ---------------------------------------------------------------------------
section('A bootstrap included outside the loader is detected and logged');
// ---------------------------------------------------------------------------
// Attribution is only correct while bootstraps load through the loader. Every
// consumer file is already included by now (the call above), so re-running the
// loader reproduces exactly the violation this check exists to name.
$log = declaration_capture_log(function () {
	VaultUnlock::resetForTests();
	VaultConsumers::resetForTests();
	VaultUnlock::loadConsumerBootstraps();
});
check(strpos($log, 'already included outside') !== false,
	'the loader names the real cause rather than letting it surface later as an unmet obligation');

// ---------------------------------------------------------------------------
section('caches: true with no onWipe logs, and never blocks a lock');
// ---------------------------------------------------------------------------
// Deliberately asymmetric with reseals. The only moment a missing wipe callback
// is observable is window close, and refusing to close the window would leave
// the vault OPEN — a live unlocked vault traded for a stale plaintext file.
$log = declaration_capture_log(function () {
	VaultUnlock::resetForTests();
	VaultConsumers::resetForTests();
	VaultConsumers::setPluginDeclarationsForTests(array(
		'forgetful' => array(
			'declaration' => array('caches' => true),
			'active'      => true,
			'bootstrap'   => 'includes/nothing.php',
		),
	));
	VaultUnlock::loadConsumerBootstraps();
});
check(strpos($log, 'forgetful') !== false && strpos($log, 'caches: true') !== false,
	'the consumer is named in the log');

$locked_cleanly = true;
try {
	VaultUnlock::lock(99999999, 'no-such-session');
} catch (Throwable $e) {
	$locked_cleanly = false;
}
check($locked_cleanly, 'and the lock still closes — a missing wipe callback must never keep a vault open');

VaultUnlock::resetForTests();
VaultConsumers::resetForTests();

// ---------------------------------------------------------------------------
section('reseals: true with no callback refuses the rotation, before the mint');
// ---------------------------------------------------------------------------
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$fx = vault_fixture_vault('Decl');
$vault_id = (int)$fx['vault']->key;
$generation_before = (int)(new UserEncryptionVault($vault_id, TRUE))->get('uev_key_generation');
$wrappings_before  = count(vault_live_wrappings($vault_id));

$rotate_with = function (array $plugin_declarations) use ($fx, $vault_id) {
	VaultUnlock::resetForTests();
	VaultConsumers::resetForTests();
	VaultConsumers::setPluginDeclarationsForTests($plugin_declarations);
	$vault = new UserEncryptionVault($vault_id, TRUE);
	try {
		(new VaultCeremonies())->rotate($fx['user'], $vault, (int)$fx['passkey']->key,
			'Vault Test Passkey', $fx['kek'], '', false);
		return null;
	} catch (VaultCeremonyException $e) {
		return $e->getMessage();
	}
};

$message = $rotate_with(array(
	'hoarder' => array(
		'declaration' => array('reseals' => true),
		'active'      => true,
		'bootstrap'   => 'includes/nothing.php',
	),
));
check($message !== null, 'the rotation is refused');
check($message !== null && strpos($message, 'hoarder') !== false, 'and the refusal names the consumer');

$after = new UserEncryptionVault($vault_id, TRUE);
check((int)$after->get('uev_key_generation') === $generation_before,
	'no new generation was minted — the refusal lands before anything is changed');
check(count(vault_live_wrappings($vault_id)) === $wrappings_before,
	'and every unlocker the member had still works');

// ---------------------------------------------------------------------------
section('An installed-but-INACTIVE resealer refuses the rotation too');
// ---------------------------------------------------------------------------
// Deactivating a plugin removes its callbacks but not its sealed rows, so
// rotating past it is the same permanent loss. Its plugin.json is still on
// disk, which is the only reason this is checkable at all.
$message = $rotate_with(array(
	'sleeper' => array(
		'declaration' => array('reseals' => true),
		'active'      => false,
		'bootstrap'   => 'includes/nothing.php',
	),
));
check($message !== null && strpos($message, 'sleeper') !== false,
	'a deactivated consumer holding sealed content blocks the rotation');
check($message !== null && stripos($message, 'switch it back on') !== false,
	'and the member is told the fix, in terms of the feature rather than the plugin machinery');

$after = new UserEncryptionVault($vault_id, TRUE);
check((int)$after->get('uev_key_generation') === $generation_before, 'again, nothing was minted');

// ---------------------------------------------------------------------------
section('A plugin NEVER activated on this instance does not block the rotation');
// ---------------------------------------------------------------------------
// Every deployment ships every plugin's code, so an on-disk declaration alone
// must not hold members' rotations hostage to a feature they never switched on:
// with no activation there are no sealed rows to protect. Activation HISTORY
// draws the line — the sleeper case above stays refused.
VaultUnlock::resetForTests();
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'ghost' => array(
		'declaration' => array('reseals' => true),
		'active'      => false,
		'bootstrap'   => 'includes/nothing.php',
	),
));
VaultConsumers::setPluginEverActivatedForTests(array());   // 'ghost' has no activation history

// The core consumers' bootstrap files are already included in this process, so
// the loader cannot re-run them to attribute their registrations. Satisfy their
// reseal obligations by hand — this section is about the GHOST's obligation.
foreach (VaultConsumers::allDeclarations() as $core_name => $core_declaration) {
	if ($core_declaration['plugin'] === '' && $core_declaration['reseals']) {
		VaultConsumers::beginLoading($core_name);
		VaultUnlock::onReseal(function () {});
		VaultConsumers::endLoading();
	}
}

$vault = new UserEncryptionVault($vault_id, TRUE);
$rotated = null;
try {
	$rotated = (new VaultCeremonies())->rotate($fx['user'], $vault, (int)$fx['passkey']->key,
		'Vault Test Passkey', $fx['kek'], '', false);
} catch (VaultCeremonyException $e) {
	check(false, 'the rotation must not be refused for a never-activated plugin: ' . $e->getMessage());
}
check(is_array($rotated) && !empty($rotated['rotated']), 'the rotation completes');
$after = new UserEncryptionVault($vault_id, TRUE);
check((int)$after->get('uev_key_generation') > $generation_before,
	'and a new generation was minted — the guard stood aside for a consumer that owns nothing');

VaultUnlock::resetForTests();
VaultConsumers::resetForTests();

harness_finish();
?>
