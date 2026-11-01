<?php
/** @joinery-test
 * name: rotation_waiting_mail
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Mail waiting to be parsed through a vault key rotation, and recovery from a
 * retired key.
 *
 * The relay seals a Seal-at-the-relay message WHOLE to its owner's account
 * vault key, and the site keeps it unparsed (iem_pending_parse,
 * iem_relay_sealed_raw) until the owner's window opens. It records no key
 * generation, so a rotation's per-generation re-seal never touched it, and a
 * rotation stranded it (jeremytunnell message 104888, 2026-11-01):
 *
 *  - a rotation re-seals a waiting message to the new key (mailbox bootstrap 1.29);
 *  - a rotation keeps the keys it retires under the new key (VaultPreviousKeys),
 *    and the chain follows the vault through the next rotation;
 *  - one sealed to the old key AFTER the rotation retired it (the relay had not
 *    switched) is moved to the current key by the parse pass, with nothing
 *    from its owner (DeferredIngest::moveToCurrentKey);
 *  - a key retired before the chain existed joins it at a passkey unlock
 *    (VaultPreviousKeys::adoptRetired).
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!extension_loaded('sodium') || !class_exists('InboundEmailMessage')) {
	harness_skip('sodium or the Mailbox plugin is unavailable');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();
$crypto = new VaultCrypto();
$ceremonies = new VaultCeremonies();

$fx = vault_fixture_vault('Waiting', '', 5);
$user = $fx['user'];
$uid = (int)$user->key;
$vault_id = (int)$fx['vault']->key;
$kek = $fx['kek'];
$credential_id = (int)$fx['passkey']->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'harnesstest-waiting-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_owner_usr_user_id', $uid);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$secret_for_generation = function (int $gen, bool $retired = false) use ($vault_id, $credential_id, $kek): ?VaultKey {
	$opts = array('vault_id' => $vault_id);
	if ($retired) { $opts['deleted'] = true; }
	foreach (new MultiUserEncryptionWrapping($opts) as $w) {
		if ($w->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY) { continue; }
		if ((int)$w->get('uew_pkc_passkey_credential_id') !== $credential_id) { continue; }
		if ((int)$w->get('uew_key_generation') !== $gen) { continue; }
		return VaultUnlock::openKey(0, $w->unlocker($kek))['key'];
	}
	return null;
};

// A message the relay sealed whole to $public_key, waiting to be parsed.
$waiting = function (string $raw, string $public_key) use ($db, $crypto, $domain, $uid): int {
	$stmt = $db->prepare("INSERT INTO iem_inbound_email_messages (iem_ied_inbound_email_domain_id, iem_recipient, iem_sender,
		iem_subject, iem_pending_parse, iem_relay_sealed_raw, iem_sealed_owner_user_id)
		VALUES (?, 'someone@example.com', '', '', true, ?, ?) RETURNING iem_inbound_email_message_id");
	$stmt->execute(array((int)$domain->key, $crypto->sealItemDek($raw, $public_key), $uid));
	$id = (int)$stmt->fetchColumn();
	harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $id);
	return $id;
};
$blob = function (int $id) use ($db): string {
	$q = $db->prepare('SELECT iem_relay_sealed_raw FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return (string)$q->fetchColumn();
};
$opens = function (int $id, ?VaultKey $key) use ($crypto, $blob): ?string {
	if ($key === null) { return null; }
	try { return $crypto->openHeldDeliveryBlob($blob($id), $key); } catch (Throwable $e) { return null; }
};
$rotate = function () use ($ceremonies, $user, $vault_id, $credential_id, $kek, $fx): array {
	SealedEgressGuard::reset();
	$set = vault_fixture_code_set($fx['root_salt'], 5);
	$twins = vault_fixture_root_recovery($fx['root_salt'], $set, $fx['root_secret']);
	return $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, 'label', $kek, '',
		$set['set'], array('recovery' => $twins), false);
};

section('A rotation moves a waiting message to the new key');
$v = new UserEncryptionVault($vault_id, TRUE);
$gen1 = (int)$v->get('uev_key_generation');
$raw1 = "From: a@example.com\r\nSubject: waiting\r\n\r\nheld while locked";
$m1 = $waiting($raw1, (string)$v->get('uev_public_key'));
$r = $rotate();
$gen2 = (int)$r['key_generation'];
check($gen2 === $gen1 + 1, 'the rotation went through');
check($opens($m1, $secret_for_generation($gen2)) === $raw1, 'the waiting message opens with the new key');
check($opens($m1, $secret_for_generation($gen1, true)) === null, 'and no longer with the old one');

section('A rotation keeps the keys it retires, reachable through the current key');
$chain = VaultPreviousKeys::entries(new UserEncryptionVault($vault_id, TRUE));
check(array_column($chain, 'generation') === array($gen1), 'after one rotation the chain holds the retired generation', json_encode(array_column($chain, 'generation')));
$retired_public = (string)(new UserEncryptionVault($vault_id, TRUE))->get('uev_public_key');
$r = $rotate();
$gen3 = (int)$r['key_generation'];
$v3 = new UserEncryptionVault($vault_id, TRUE);
$current = $secret_for_generation($gen3);
$previous = VaultPreviousKeys::open($v3, $current);
check(array_keys($previous) === array($gen2, $gen1), 'after the next, both, newest first, each opened through the current key', json_encode(array_keys($previous)));
check($previous[$gen1]->publicKey() === $secret_for_generation($gen1, true)->publicKey()
	&& $previous[$gen2]->publicKey() === $retired_public, 'and each is the key it names');
check(strpos((string)$v3->get('uev_previous_keys'), $retired_public) === false, 'no public or secret key is stored in the clear');

section('A message sealed to a retired key is moved to the current key, with nothing from its owner');
$raw2 = "From: b@example.com\r\nSubject: late\r\n\r\nsealed by a relay that had not switched";
$m2 = $waiting($raw2, $retired_public);
check($opens($m2, $current) === null, 'the current key does not open it as it arrived');
$move = new ReflectionMethod('DeferredIngest', 'moveToCurrentKey');
check($move->invoke(null, new InboundEmailMessage($m2, TRUE), $uid, $current) === true, 'the parse pass moves it');
check($opens($m2, $current) === $raw2, 'and it opens with the current key, ready to parse');
check($move->invoke(null, new InboundEmailMessage($m2, TRUE), $uid, $current) === false, 'a second pass finds nothing to move');

section('A key retired before the chain existed joins it at a passkey unlock');
$db->prepare('UPDATE uev_user_encryption_vaults SET uev_previous_keys = NULL WHERE uev_user_encryption_vault_id = ?')->execute(array($vault_id));
$bare = new UserEncryptionVault($vault_id, TRUE);
check(VaultPreviousKeys::open($bare, $current) === array(), 'with the chain gone, nothing is reachable');
$added = VaultPreviousKeys::adoptRetired($bare, $current, $credential_id, $kek);
sort($added);
check($added === array($gen1, $gen2), 'the unlocking passkey opens both retired generations, and they are kept', json_encode($added));
check(array_keys(VaultPreviousKeys::open(new UserEncryptionVault($vault_id, TRUE), $current)) === array($gen2, $gen1), 'reachable again');
check(VaultPreviousKeys::adoptRetired(new UserEncryptionVault($vault_id, TRUE), $current, $credential_id, $kek) === array(),
	'the next unlock adds nothing');
check(VaultPreviousKeys::adoptRetired(new UserEncryptionVault($vault_id, TRUE), $current, $credential_id + 999999, $kek) === array(),
	'another passkey with no retired wrapping adds nothing');

section('An unlock that loaded the vault before a rotation does not write over its chain (reviewer2 H1)');
$stale = new UserEncryptionVault($vault_id, TRUE);
$db->prepare('UPDATE uev_user_encryption_vaults SET uev_previous_keys = NULL WHERE uev_user_encryption_vault_id = ?')->execute(array($vault_id));
$stale_bare = new UserEncryptionVault($vault_id, TRUE);
$r = $rotate();
$gen4 = (int)$r['key_generation'];
$after_rotation = (string)(new UserEncryptionVault($vault_id, TRUE))->get('uev_previous_keys');
check($after_rotation !== '', 'the rotation wrote its chain');
check(VaultPreviousKeys::adoptRetired($stale_bare, $current, $credential_id, $kek) === array(),
	'an unlock holding the vault from before the rotation adds nothing');
check((string)(new UserEncryptionVault($vault_id, TRUE))->get('uev_previous_keys') === $after_rotation,
	'and the rotation\'s chain is as it wrote it');
check(array_keys(VaultPreviousKeys::open(new UserEncryptionVault($vault_id, TRUE), $secret_for_generation($gen4)))
	=== array($gen3), 'the new key reaches the generation it retired', json_encode(array_keys(VaultPreviousKeys::open(new UserEncryptionVault($vault_id, TRUE), $secret_for_generation($gen4)))));

harness_finish();
