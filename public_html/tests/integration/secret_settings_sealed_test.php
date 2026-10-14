<?php
/** @joinery-test
 * name: secret_settings_sealed
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * Every setting declared `secret: true` is stored sealed (specs/plugin_tiers.md,
 * What already exists: Secret settings).
 *
 * What is pinned here, against a sandboxed fixture plugin's settings (so no
 * real setting is touched):
 *  - each secret setting is a sealed secret: an implicit operator declaration
 *    unless a manifest declares it otherwise, which keeps its own kind; a
 *    setting that is not secret is not one;
 *  - Setting::put() and a model save seal a secret, never twice, and never a
 *    setting that is not secret;
 *  - Globalvars::get_setting() reads a secret as what it holds, and one that
 *    can no longer be opened as no value; a value merely shaped like a sealed
 *    one, in a setting that is not secret, is read as it is;
 *  - a settings save compares a secret by what it holds, so re-typing it
 *    writes nothing, and a dead one is not taken for a stored value;
 *  - the registry the reconciler walks lists them.
 *
 * Run: php tests/run.php db --filter=secret_settings_sealed
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/sandbox.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$raw = function (string $name) use ($db) {
	$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	$v = $q->fetchColumn();
	return $v === false ? null : (string)$v;
};
$read = function (string $name) {
	Globalvars::get_instance()->forget_setting($name);
	return Globalvars::get_instance()->get_setting($name, false, true);
};

try {
	section('Which settings are sealed secrets');
	$smtp = SealedSecretsDeclarations::get('smtp_password');
	check(($smtp['kind'] ?? '') === 'operator' && !empty($smtp['_implicit']), 'A secret setting no manifest declares is an implicit operator secret', json_encode($smtp));
	$services = SealedSecretsDeclarations::get('services_api_secret_key');
	check(($services['kind'] ?? '') === 'regenerable-breaks-things' && empty($services['_implicit']), 'one a manifest declares keeps its own kind');
	check(SealedSecretsDeclarations::get('site_name') === null, 'and a setting that is not secret is not one');
	check(!SettingsDeclarations::isSecret('joinery_direct_decoy_secret') && SealedSecretsDeclarations::get('joinery_direct_decoy_secret') !== null,
		'A sealed value its consumer opens itself is not a secret setting');

	$p = sandbox_fixture_plugin(array('settings' => array(
		array('name' => 'token', 'label' => 'Token', 'type' => 'text', 'secret' => true),
		array('name' => 'channel', 'label' => 'Channel', 'type' => 'text'),
	)));
	PluginManager::getInstance()->install($p['name'], false);
	sandbox_approve($p['name']);
	PluginManager::getInstance()->activate($p['name']);
	SandboxPlugins::reset();
	$token = SandboxManifest::settingName($p['name'], 'token');
	$channel = SandboxManifest::settingName($p['name'], 'channel');
	harness_defer(function () use ($db, $token) {
		$db->prepare('DELETE FROM ssr_sealed_secret_registry WHERE ssr_locator = ?')->execute(array($token));
	});
	check((SealedSecretsDeclarations::get($token)['kind'] ?? '') === 'operator', "An installed sandboxed plugin's secret setting is one too");

	section('Written sealed');
	Setting::put($token, 'tok-123');
	$stored = $raw($token);
	check(SecretBox::looksEncrypted((string)$stored), 'Setting::put() stores a secret sealed');
	check($read($token) === 'tok-123', 'and it reads back as what was written');
	Setting::put($token, $stored);
	check($raw($token) === $stored, 'A value already sealed is stored as it is, never sealed twice');
	Setting::put($channel, 'general');
	check($raw($channel) === 'general', 'A setting that is not secret is stored as written');
	$rows = new MultiSetting(array('setting_name' => $token));
	foreach ($rows as $row) {
		$row->set('stg_value', 'tok-456');
		$row->save();
	}
	check(SecretBox::looksEncrypted((string)$raw($token)) && $read($token) === 'tok-456', 'A model save seals it too');

	section('Read as what it holds');
	$dead = 'v1.sodium.' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=') . '.'
		. rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
	set_setting_raw($token, $dead);
	check($read($token) === null, 'A secret that can no longer be opened reads as no value, never its ciphertext');
	set_setting_raw($channel, $dead);
	check($read($channel) === $dead, 'A sealed-looking value in a setting that is not secret is read as it is');

	section('A settings save compares what it holds');
	$write = SettingsWriter::write(array($token => 'tok-789'), array('page' => 'secret_settings_sealed_test', 'source' => $p['name']));
	check(in_array($token, $write['written'] ?? array(), true) && $read($token) === 'tok-789' && SecretBox::looksEncrypted((string)$raw($token)),
		'A settings save writes a new secret, sealed', json_encode($write));
	$before = $raw($token);
	$write = SettingsWriter::write(array($token => 'tok-789'), array('page' => 'secret_settings_sealed_test', 'source' => $p['name']));
	check($raw($token) === $before && !in_array($token, $write['written'] ?? array(), true), 'Re-typing a secret as it is writes nothing', json_encode($write));
	set_setting_raw($token, $dead);
	$write = SettingsWriter::write(array($token => ''), array('page' => 'secret_settings_sealed_test', 'source' => $p['name']));
	check($raw($token) === $dead && empty($write['cleared_secrets']), 'A dead secret is not taken for a stored one: blank leaves it for the reconciler to report', json_encode($write));

	section('The registry the reconciler walks');
	SealedSecretRegistry::seed_from_manifests();
	$q = $db->prepare('SELECT ssr_kind FROM ssr_sealed_secret_registry WHERE ssr_locator = ?');
	$q->execute(array('smtp_password'));
	check($q->fetchColumn() === 'operator', 'A secret setting is seeded into the registry');
} catch (Throwable $e) {
	check(false, 'No exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

harness_finish();
