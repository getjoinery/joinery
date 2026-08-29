<?php
/** @joinery-test
 * name: sealed_secrets
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */

/**
 * End-to-end coverage for sealed-settings reconciliation.
 *
 * Exercises the declaration reader, the seal()/open() contract, the key canary,
 * the seeded registry, the reconciler (regenerable auto-heal vs operator flag),
 * and the import scrub — all against the copied test database, whose stg_settings
 * reference rows carry the real declared secret names.
 *
 * The registry table is created by update_database from the data class; if it is
 * not present the suite skips rather than fails, so it is safe to run before the
 * first schema sync.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();   // all writes below go to the copied test database

require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('includes/SealedSecretsDeclarations.php'));
require_once(PathHelper::getIncludePath('includes/SecretReconciler.php'));
require_once(PathHelper::getIncludePath('data/sealed_secret_registry_class.php'));
require_once(PathHelper::getIncludePath('utils/scrub_sealed_secrets.php'));

$dblink = DbConnector::get_instance()->get_db_link();

$have_table = $dblink->query("SELECT to_regclass('ssr_sealed_secret_registry') IS NOT NULL")->fetchColumn();
if (!$have_table) {
	section('Sealed secrets');
	harness_skip('registry table present', 'run update_database to create ssr_sealed_secret_registry');
	harness_finish();
	return;
}

$box = new SecretBox();
$DEAD_BLOB = 'v1.sodium.AAAA.BBBBBBBBBBBBBBBB';   // looks encrypted, will not decrypt

// A dead corrupt value planted into a singleton setting, restored on teardown.
$restore = array();
$plant_setting = function ($name, $value) use ($dblink, &$restore) {
	if (!array_key_exists($name, $restore)) {
		$q = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
		$q->execute(array($name));
		$restore[$name] = $q->fetchColumn();
	}
	$dblink->prepare("INSERT INTO stg_settings (stg_name, stg_value, stg_group_name, stg_create_time)
		VALUES (?, ?, 'general', NOW())
		ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value")->execute(array($name, $value));
};
harness_defer(function () use ($dblink, &$restore) {
	foreach ($restore as $name => $value) {
		if ($value === false) {
			$dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?")->execute(array($name));
		} else {
			$dblink->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?")->execute(array($value, $name));
		}
	}
});

// --- Declarations -------------------------------------------------------------
section('Declarations');
$all = SealedSecretsDeclarations::all();
check(count($all) > 0, 'sealed_secrets manifests declare at least one category', 'count=' . count($all));
check(count(SealedSecretsDeclarations::schemaErrors()) === 0, 'declarations are schema-clean',
	implode('; ', SealedSecretsDeclarations::schemaErrors()));
check(SealedSecretsDeclarations::isDeclared('file_signed_url_key'), 'a known core locator is declared');
check(!SealedSecretsDeclarations::isDeclared('not_a_real_locator'), 'an unknown locator is not declared');

// --- seal() teeth + open() contract ------------------------------------------
section('seal / open contract');
$sealed = $box->seal('file_signed_url_key', 'a-secret');
check(SecretBox::looksEncrypted($sealed), 'seal() of a registered locator returns a blob');
$threw = false;
try { $box->seal('not_a_real_locator', 'x'); } catch (\Throwable $e) { $threw = true; }
check($threw, 'seal() refuses an unregistered locator');

check($box->open($sealed)['state'] === SecretBox::OPEN_OK, 'open(sealed) -> ok');
check($box->open($sealed)['value'] === 'a-secret', 'open(sealed) returns the plaintext');
check($box->open(null)['state'] === SecretBox::OPEN_ABSENT, 'open(null) -> absent');
check($box->open('')['state'] === SecretBox::OPEN_ABSENT, 'open(empty) -> absent');
check($box->open('plain text value')['state'] === SecretBox::OPEN_PLAINTEXT, 'open(plaintext) -> plaintext');
check($box->open($DEAD_BLOB)['state'] === SecretBox::OPEN_DEAD, 'open(corrupt) -> dead');

// --- Key canary ---------------------------------------------------------------
section('Key canary');
$dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?")->execute(array(SecretBox::CANARY_SETTING));
$restore[SecretBox::CANARY_SETTING] = false;   // teardown removes it
check(SecretBox::canaryState() === SecretBox::OPEN_ABSENT, 'canary absent before minting');
SecretBox::provisionCanary();
check(SecretBox::canaryState() === SecretBox::OPEN_OK, 'canary opens after minting');

// --- Registry seed ------------------------------------------------------------
section('Registry seed');
$seed = SealedSecretRegistry::seed_from_manifests();
check($seed['seeded'] === count($all), 'seed_from_manifests mirrors every declared category', 'seeded=' . $seed['seeded']);
$row = new SealedSecretRegistry(null);
$found = new MultiSealedSecretRegistry(array(), array('ssr_locator' => 'ASC'));
$found->load();
check(count($found) >= count($all), 'registry table holds a row per category');

// --- Reconcile: regenerable auto-heals ---------------------------------------
section('Reconcile — regenerable heal');
$plant_setting('file_signed_url_key', $DEAD_BLOB);
$r1 = SecretReconciler::reconcile();
check($r1['healed'] >= 1, 'a dead regenerable secret is auto-healed', 'healed=' . $r1['healed']);
$q = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = 'file_signed_url_key'");
$q->execute();
$after = (string)$q->fetchColumn();
check($after !== '' && $after !== $DEAD_BLOB && $box->open($after)['state'] === SecretBox::OPEN_OK,
	'the healed signing key is freshly minted and readable');

// --- Reconcile: operator secret is flagged, never touched --------------------
section('Reconcile — operator flag');
$plant_setting('oauth_google_client_secret', $DEAD_BLOB);
$r2 = SecretReconciler::reconcile();
$verdict = SecretReconciler::attention_verdict();
check($verdict['operator'] >= 1, 'a dead operator secret is counted as needing attention',
	'operator=' . $verdict['operator']);
$q = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = 'oauth_google_client_secret'");
$q->execute();
check((string)$q->fetchColumn() === $DEAD_BLOB, 'the operator secret is left untouched (not auto-healed)');
$dead = SecretReconciler::dead_items();
$has_oauth = false;
foreach ($dead as $d) { if ($d['locator'] === 'oauth_google_client_secret') $has_oauth = true; }
check($has_oauth, 'dead_items lists the flagged operator secret');

// --- Import scrub -------------------------------------------------------------
section('Import scrub');
$plant_setting('oauth_google_client_secret', $DEAD_BLOB);   // re-plant (heal never runs on operator)
$scrub = scrub_sealed_secrets(false);
check($scrub['cleared'] >= 1, 'scrub clears at least the planted operator secret', 'cleared=' . $scrub['cleared']);
$q = $dblink->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = 'oauth_google_client_secret'");
$q->execute();
check((string)$q->fetchColumn() === '', 'the scrubbed operator secret is now empty (absent, not dead)');

harness_finish();
