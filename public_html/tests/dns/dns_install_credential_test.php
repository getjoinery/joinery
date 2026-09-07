<?php
/** @joinery-test
 * name: dns_install_credential
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * The credential an installer keeps for the setup wizard's one DNS publish
 * (DnsInstallCredential): sealed on store, readable back with its driver,
 * gone after consume, and refused for a driver that takes no pasted
 * credential. The setting and its sealed-secret registration are declared,
 * which is what lets SecretBox seal it at all.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
require_once(PathHelper::getIncludePath('includes/SealedSecretsDeclarations.php'));

// Never disturb a credential a real install may have left here.
$pre_existing = DnsInstallCredential::stored();
if ($pre_existing !== null) {
	check(true, 'skipped: a real install credential is present', 'not touching it');
	harness_finish();
}

section('The setting and its sealed-secret kind are declared');
check(SettingsDeclarations::isDeclared(DnsInstallCredential::SETTING), 'setting declared in settings.json');
check(SealedSecretsDeclarations::isDeclared(DnsInstallCredential::SETTING), 'locator declared under sealed_secrets');

section('Store, read back, consume');
check(DnsInstallCredential::stored() === null, 'nothing stored to begin with');
DnsInstallCredential::store('linode', array('access_token' => 'test-token-123', 'ignored' => 'x'));
$got = DnsInstallCredential::stored();
check($got !== null && $got['driver'] === 'linode', 'stored credential names its driver');
check($got !== null && ($got['credential']['access_token'] ?? '') === 'test-token-123', 'the declared field comes back');
check($got !== null && !isset($got['credential']['ignored']), 'undeclared fields are dropped');

$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
$q->execute(array(DnsInstallCredential::SETTING));
$raw = (string)$q->fetchColumn();
check($raw !== '' && strpos($raw, 'test-token-123') === false, 'the row holds a sealed blob, not the token');

DnsInstallCredential::consume();
check(DnsInstallCredential::stored() === null, 'consume deletes it');
DnsInstallCredential::consume();
check(DnsInstallCredential::stored() === null, 'consume is idempotent');

section('Refusals');
$threw = false;
try { DnsInstallCredential::store('no-such-driver', array('access_token' => 'x')); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'an unknown driver is refused');
$threw = false;
try { DnsInstallCredential::store('linode', array('nothing' => 'x')); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'a credential with none of the driver\'s fields is refused');
check(DnsInstallCredential::stored() === null, 'nothing left behind by a refusal');

harness_finish();
