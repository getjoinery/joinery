<?php
/** @joinery-test
 * name: direct_key_custody
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [plugins/mailbox/data/inbound_email_domains_class.php, includes/joinery_direct/DirectIdentity.php, data/direct_identities_class.php]
 */
/**
 * A domain's Joinery Direct signing key follows the domain's protection level
 * (spec security_weak_spots_2026_11 WP1).
 *
 * Custody is chosen when a key is minted: sealed to the owner's vault when the
 * domain seals content and its owner holds a vault, box-held otherwise. A key
 * minted while the domain was Standard stayed box-held after a raise to
 * Private, so a break-in could sign in the domain's name with nobody signed
 * in. The key now moves in place, keeping its key id so DNS stays right: at
 * once on a raise, and at the holder's next open window on a lowering.
 *
 * Run: php tests/run.php db --only=plugins/mailbox/tests/direct_key_custody_test.php
 *
 * @version 1.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
$has_session = vault_ensure_session();

function dkc_domain(int $owner_id): InboundEmailDomain {
	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', 'dkc-' . bin2hex(random_bytes(4)) . '.example');
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_catch_all_mode', 'store');
	$dom->set('ied_owner_usr_user_id', $owner_id);
	$dom->set_security_level(InboundEmailDomain::LEVEL_STANDARD);
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	return $dom;
}

/** The active row, read fresh. */
function dkc_active(string $domain): ?DirectIdentity {
	return DirectIdentity::activeFor($domain);
}

/** Who holds the active key: the owner id when vault-held, 0 when box-held. */
function dkc_custody(string $domain): ?int {
	$active = dkc_active($domain);
	if ($active === null) {
		return null;
	}
	return ((string)$active->get('jdi_sealed_secret_key') !== '') ? intval($active->get('jdi_owner_usr_user_id')) : 0;
}

/** Does the active key still sign, and verify under its published public half? */
function dkc_signs(string $domain): bool {
	$signed = DirectSigningIdentity::sign($domain, 'dkc-probe');
	$public = base64_decode((string)dkc_active($domain)->get('jdi_public_key'));
	return sodium_crypto_sign_verify_detached(base64_decode($signed['signature']), 'dkc-probe', $public);
}

$owner = make_user('DirectCustody');
$owner_id = (int)$owner->key;
$owner_vault = vault_fixture_server_vault($owner_id);

// ---------------------------------------------------------------------------
section('Raising a domain to Private seals its key to the owner, same key id');
// ---------------------------------------------------------------------------
$dom = dkc_domain($owner_id);
$name = (string)$dom->get('ied_domain');
$first = DirectSigningIdentity::ensureFor($name);
$key_id = (string)$first->get('jdi_key_id');
check(dkc_custody($name) === 0, 'a Standard domain\'s key is box-held');

$dom->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
$dom->save();
$active = dkc_active($name);
check(dkc_custody($name) === $owner_id, 'after the raise, the active key is sealed to the owner');
check((string)$active->get('jdi_secret_key') === '', 'and the box-held copy is erased, so nobody can sign while the owner is away');
check((string)$active->get('jdi_key_id') === $key_id && intval($active->key) === intval($first->key),
	'it is the same key, so the published TXT record is still right');
check(count(DirectIdentity::publishableFor($name)) === 1, 'and there is no second key to publish');

// ---------------------------------------------------------------------------
section('A save that does not change custody leaves the key alone');
// ---------------------------------------------------------------------------
$sealed_before = (string)$active->get('jdi_sealed_secret_key');
$dom->set('ied_catch_all_mode', 'store');
$dom->save();
check((string)dkc_active($name)->get('jdi_sealed_secret_key') === $sealed_before, 'saving a Private domain again changes nothing');
check(DirectSigningIdentity::ensureCustody($name) === null, 'ensureCustody() reports nothing to move');

// ---------------------------------------------------------------------------
section('Lowering moves the key back to the box at the owner\'s next open window');
// ---------------------------------------------------------------------------
$dom->set_security_level(InboundEmailDomain::LEVEL_STANDARD);
$dom->save();
check(dkc_custody($name) === $owner_id, 'with no window open, the key stays sealed: only the owner can open it');
if ($has_session) {
	vault_fixture_open_window($owner_id, $owner_vault['secret']);
	check(dkc_signs($name), 'the owner, signed in, sends: the key signs');
	check(dkc_custody($name) === 0, 'and that send moved it to the box, so sends work with the owner signed out');
	check((string)dkc_active($name)->get('jdi_key_id') === $key_id, 'still the same key id');
	VaultUnlock::lockAll($owner_id);
	check(dkc_signs($name), 'with every window closed, the box-held key still signs');
} else {
	harness_skip('no session, so no unlock window can open');
}
$dom->permanent_delete();

// ---------------------------------------------------------------------------
section('With no mailbox to ask, a vault-held key is left where it is');
// ---------------------------------------------------------------------------
$dom = dkc_domain($owner_id);
$name = (string)$dom->get('ied_domain');
DirectSigningIdentity::ensureFor($name);
$dom->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
$dom->save();
$dom->set_security_level(InboundEmailDomain::LEVEL_STANDARD);
$dom->save();
$resolver = new ReflectionProperty('DirectSigningIdentity', 'vault_owner_resolver');
$saved_resolver = $resolver->getValue();
$resolver->setValue(null, null);
try {
	$r = DirectSigningIdentity::ensureCustody($name, random_bytes(64));
} finally {
	$resolver->setValue(null, $saved_resolver);
}
check($r === null && dkc_custody($name) === $owner_id,
	'"could not ask" is not read as "box custody wanted"');

// ---------------------------------------------------------------------------
section('A key held by a former owner is replaced, not left waiting');
// ---------------------------------------------------------------------------
// The old owner will not be back to open it, so waiting for their window
// would leave every send falling back to SMTP for good.
$heir = make_user('DirectCustodyHeir');
vault_fixture_server_vault((int)$heir->key);
$old_id = intval(dkc_active($name)->key);
$dom->set('ied_owner_usr_user_id', (int)$heir->key);
$dom->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
$dom->save();
check(intval(dkc_active($name)->key) !== $old_id, 'a new key is minted');
check(dkc_custody($name) === (int)$heir->key, 'sealed to the new owner');
$dom->permanent_delete();

// ---------------------------------------------------------------------------
section('An owner with no vault keeps a box-held key at Private');
// ---------------------------------------------------------------------------
$plain = make_user('DirectCustodyNoVault');
$dom = dkc_domain((int)$plain->key);
$name = (string)$dom->get('ied_domain');
DirectSigningIdentity::ensureFor($name);
$dom->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
$dom->save();
check(dkc_custody($name) === 0, 'nothing to seal to, so the key stays box-held');

// ---------------------------------------------------------------------------
section('An owner who sets up a vault later is caught by ensureFor()');
// ---------------------------------------------------------------------------
vault_fixture_server_vault((int)$plain->key);
DirectSigningIdentity::ensureFor($name);
check(dkc_custody($name) === (int)$plain->key, 'the setup check\'s next ensureFor() seals the key to the new vault');

// ---------------------------------------------------------------------------
section('A key sealed to a vault that is gone is replaced');
// ---------------------------------------------------------------------------
// Deleting a user deletes their vault and the foreign key empties the key's
// owner: nothing can open it again, so only a new key helps.
$old_id = intval(dkc_active($name)->key);
$db = DbConnector::get_instance()->get_db_link();
$db->prepare('UPDATE jdi_direct_identities SET jdi_owner_usr_user_id = NULL WHERE jdi_direct_identity_id = ?')
	->execute(array($old_id));
$db->prepare('UPDATE ied_inbound_email_domains SET ied_owner_usr_user_id = NULL WHERE ied_inbound_email_domain_id = ?')
	->execute(array(intval($dom->key)));
$moved = DirectSigningIdentity::ensureCustody($name);
check($moved !== null && intval($moved->key) !== $old_id, 'a new key is minted');
check(dkc_custody($name) === 0, 'in box custody, since the domain names no owner now');
$old = new DirectIdentity($old_id, TRUE);
check(!$old->get('jdi_is_active') && (string)$old->get('jdi_retire_time') !== '', 'the dead key is retired');
check((string)$old->get('jdi_secret_key') === '' && (string)$old->get('jdi_sealed_secret_key') === '',
	'and a retired key keeps no secret, so it can never sign again');
$dom->permanent_delete();

// ---------------------------------------------------------------------------
section('A retired key leaves the DNS plan after its grace period');
// ---------------------------------------------------------------------------
$dom = dkc_domain($owner_id);
$name = (string)$dom->get('ied_domain');
$first = DirectSigningIdentity::ensureFor($name);
DirectSigningIdentity::rotate($name);
check(count(DirectIdentity::publishableFor($name)) === 2, 'just after a rotation both keys are published');
$db->prepare("UPDATE jdi_direct_identities SET jdi_retire_time = now() - interval '8 days' WHERE jdi_direct_identity_id = ?")
	->execute(array(intval($first->key)));
check(count(DirectIdentity::publishableFor($name)) === 1, 'a week later only the active key is');

// ---------------------------------------------------------------------------
section('A domain this deployment is not authoritative for is left alone');
// ---------------------------------------------------------------------------
$db->prepare('UPDATE ied_inbound_email_domains SET ied_is_enabled = false WHERE ied_inbound_email_domain_id = ?')
	->execute(array(intval($dom->key)));
$threw = null;
try {
	$r = DirectSigningIdentity::ensureCustody($name);
} catch (Throwable $e) {
	$threw = $e->getMessage();
}
check($threw === null && $r === null, 'ensureCustody() returns nothing to do rather than throwing', (string)$threw);
$dom->permanent_delete();

// ---------------------------------------------------------------------------
section('A domain with no key is not given one by a save');
// ---------------------------------------------------------------------------
$dom = dkc_domain($owner_id);
$name = (string)$dom->get('ied_domain');
$dom->set_security_level(InboundEmailDomain::LEVEL_PRIVATE);
$dom->save();
check(dkc_active($name) === null, 'raising a domain with no Direct key mints none');
$dom->permanent_delete();

harness_finish();
