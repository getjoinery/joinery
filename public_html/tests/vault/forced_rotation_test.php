<?php
/** @joinery-test
 * name: forced_rotation
 * tier: test-db
 * env: any
 * needs: [test-db]
 *
 * The forced rotation to X-Wing keys (specs/post_quantum_sealing.md R7, WP7),
 * server side:
 *   - a vault key on the old X25519 format does not open: VaultUnlock::arm()
 *     refuses it (VaultRotationOwedException, naming the rotation), except for
 *     a recovery or a re-enrolment, the two opens that lead to the rotation;
 *     a new key arms as before;
 *   - the passkey unlock answers rotation_required with the Security page link;
 *   - which vaults owe a rotation, per owner and site-wide by scope; the root
 *     never counts (its key seals nothing);
 *   - the relay transport key: an old one is replaced by an X-Wing one only
 *     once the relay reports 3.16, its secret kept to open what is already in
 *     the spool, by the blob's version; and upgrade.php refuses before a relay
 *     older than 3.16, ahead of anything it stages.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
harness_test_mode();

if (!vault_apcu_usable() || !vault_ensure_session()) {
	harness_skip('APCu or a session is unavailable, so no unlock window can open');
	harness_finish();
}

$box = new SealedBox();
$user = make_user('fr_owner', 0);
$uid = (int)$user->key;

// ── A vault key in the old format does not open ─────────────────────────
section('An old X25519 vault key does not open; a new one does');

$old_secret = SealedBox::b64url(random_bytes(32));
$old_key = vault_fixture_key($old_secret, SealedBox::V1);
foreach (array(VaultAudit::VIA_PASSKEY, VaultAudit::VIA_PASSPHRASE, VaultAudit::VIA_REENROLL, VaultAudit::VIA_UNKNOWN) as $via) {
	$threw = null;
	try { VaultUnlock::arm($uid, $old_key, UserEncryptionVault::SCOPE_USER, null, $via); }
	catch (VaultRotationOwedException $e) { $threw = $e; }
	check($threw !== null && strpos($threw->getMessage(), 'Rotate Vault Key') !== false,
		"an unlock by {$via} refuses it and names the rotation", $threw ? $threw->getMessage() : 'armed');
}
check(!VaultUnlock::isOpen($uid), 'and nothing was armed');
$threw = null;
try { VaultUnlock::arm($uid, $old_key, UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_RECOVERY); }
catch (Throwable $e) { $threw = $e; }
check($threw === null && VaultUnlock::isOpen($uid), 'a recovery still opens it: it is the way back to a passkey, and so to the rotation',
	$threw ? $threw->getMessage() : '');
VaultUnlock::lockAll($uid);
$new = $box->generateKeypair();
$threw = null;
try { VaultUnlock::arm($uid, vault_fixture_key($new['secret']), UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_PASSKEY); }
catch (Throwable $e) { $threw = $e; }
check($threw === null && VaultUnlock::isOpen($uid), 'an X-Wing key arms as before', $threw ? $threw->getMessage() : '');
VaultUnlock::lockAll($uid);

$logic = (string)file_get_contents(PathHelper::getIncludePath('logic/vault_unlock_passkey_logic.php'))
	. (string)file_get_contents(PathHelper::getIncludePath('logic/vault_unlock_passphrase_logic.php'));
check(substr_count($logic, "'rotation_required' => true, 'rotate_url' => VaultRotationOwedException::ROTATE_URL") === 2,
	'the passkey and passphrase unlocks answer rotation_required with the Security page link');

// ── Which vaults owe a rotation ─────────────────────────────────────────
section('The vaults that owe a rotation');

$db = DbConnector::get_instance()->get_db_link();
$mk_vault = function (string $scope, string $public) use ($uid) {
	$v = new UserEncryptionVault(NULL);
	$v->set('uev_usr_user_id', $uid);
	$v->set('uev_scope', $scope);
	$v->set('uev_public_key', $public);
	$v->set('uev_salt', base64_encode(random_bytes(16)));
	$v->set('uev_key_generation', 1);
	$v->save();
	harness_register_model('UserEncryptionVault', $v->key);
	return $v;
};
$before = UserEncryptionVault::oldKeyCounts();
$old_user = $mk_vault(UserEncryptionVault::SCOPE_USER, SealedBox::b64url(random_bytes(32)));
$old_mail = $mk_vault('mail', base64_encode(random_bytes(32)));
$old_root = $mk_vault('root', base64_encode(random_bytes(32)));
$new_drive = $mk_vault('drive', base64_encode((new XWing())->publicKey(random_bytes(32))));

check($old_user->keyIsOld() && $old_mail->keyIsOld() && !$new_drive->keyIsOld(),
	'a 32-byte key owes a rotation, in either base64 form; an X-Wing key does not');
check($old_root->keyIsOld(), 'the root does too: its public key gives up its secret, which derives every content vault\'s key');
$scopes = UserEncryptionVault::oldKeyScopesFor($uid);
$named = array_keys($scopes);
sort($named);
check($named === array('mail', 'root', UserEncryptionVault::SCOPE_USER), 'the owner\'s Security page names the account vault, the mail vault and the root',
	json_encode($scopes));
$after = UserEncryptionVault::oldKeyCounts();
check((int)($after[UserEncryptionVault::SCOPE_USER] ?? 0) === (int)($before[UserEncryptionVault::SCOPE_USER] ?? 0) + 1
	&& (int)($after['mail'] ?? 0) === (int)($before['mail'] ?? 0) + 1 && (int)($after['root'] ?? 0) === (int)($before['root'] ?? 0) + 1,
	'Recovery Readiness counts them by scope, the root too', json_encode($after));

// ── The relay transport key ─────────────────────────────────────────────
section('The relay transport key moves to X-Wing once the relay can take it');

if (!class_exists('MailboxRelay')) {
	harness_skip('the mailbox plugin is not active');
} else {
	$old = sodium_crypto_box_keypair();
	$relay = new MailboxRelay(NULL);
	$relay->set('mrl_name', 'fr-relay-' . bin2hex(random_bytes(3)));
	$relay->set('mrl_transport_public_key', SealedBox::b64url(sodium_crypto_box_publickey($old)));
	$relay->set('mrl_transport_secret_sealed', (new SecretBox())->seal('mrl_mailbox_relays.mrl_transport_secret_sealed',
		SealedBox::b64url(sodium_crypto_box_secretkey($old))));
	$relay->set('mrl_transport_key_generation', 1);
	$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.15')));
	$relay->set('mrl_last_health_time', gmdate('Y-m-d H:i:s'));
	$relay->save();
	harness_register_model('MailboxRelay', $relay->key);

	// What a relay sealed to the old key before the replacement reached it: a v1 seal.
	$v1_blob = 'v1.seal.' . SealedBox::b64url(sodium_crypto_box_seal('mail that arrived before the upgrade', sodium_crypto_box_publickey($old)));

	$kept = $relay->ensureTransportKeypair();
	check($kept === SealedBox::b64url(sodium_crypto_box_publickey($old)) && $relay->transportKeyIsOld(),
		'behind a relay older than 3.16 the old key stays: that relay rejects an X-Wing one, and the mail would stop');

	$relay->set('mrl_last_health_json', json_encode(array('state' => 'ok', 'provisioned' => '3.16')));
	$relay->save();
	$new_pub = $relay->ensureTransportKeypair();
	check(strlen((string)SealedBox::b64url_decode($new_pub)) === XWing::PUBLIC_KEY_BYTES && !$relay->transportKeyIsOld()
		&& (int)$relay->get('mrl_transport_key_generation') === 2,
		'behind a 3.16 relay it is replaced by an X-Wing key, a new generation');
	check($relay->ensureTransportKeypair() === $new_pub, 'and once replaced it stays');

	$v2_blob = $box->sealDek('mail after the upgrade', $new_pub);
	[$v1, $raw1] = SealedBox::unframeSeal($v1_blob);
	[$v2, $raw2] = SealedBox::unframeSeal($v2_blob);
	check($box->openDek($v1_blob, (string)$relay->transportSecretFor($v1)) === 'mail that arrived before the upgrade',
		'mail already in the spool, sealed to the old key, opens with the retired secret');
	check($box->openDek($v2_blob, (string)$relay->transportSecretFor($v2)) === 'mail after the upgrade',
		'new mail opens with the new one');
	// The old secret is forgotten only once the relay installed a map pushed
	// after the swap and a pull begun after that push drained the spool.
	$replaced = strtotime((string)$relay->get('mrl_transport_replaced_time') . ' UTC');
	check($replaced !== false && !$relay->retiredTransportSecretMayGo(time() + 60),
		'not while no map pushed after the swap has been installed: the relay may still seal to the old key');
	$relay->set('mrl_last_push_time', gmdate('Y-m-d H:i:s', $replaced + 5));
	check(!$relay->retiredTransportSecretMayGo($replaced + 5), 'not by a pull that began before that push');
	check($relay->retiredTransportSecretMayGo($replaced + 10), 'by a drained pull begun after it');
	$relay->set('mrl_transport_secret_retired_sealed', null);
	$relay->save();
	check($relay->transportSecretFor(SealedBox::V1) === null, 'and then the old secret is gone');
}

// ── The root moves with the account vault ───────────────────────────────
section('A rotation moves an old root to a new key, and every content vault with it');

VaultUnlock::loadConsumerBootstraps();
$fx = vault_fixture_vault('FrRoot');
$fuid = (int)$fx['user']->key;
$root = VaultClientCustody::loadVault($fuid, 'root');
// A Drive vault that opens through the root, made while the root was new.
$drive_secret = random_bytes(32);
$drive = VaultClientCustody::createVault($fuid, 'drive', array(
	'public_key' => base64_encode((new XWing())->publicKey($drive_secret)), 'salt' => base64_encode(random_bytes(16)),
	'wrappings' => array(array('unlocker_type' => 'root', 'wrapped_secret_key' =>
		vault_fixture_wrap($drive_secret, VaultUnlockerKdf::scopeKek($fx['root_secret'], 'drive'), 'vault:drive:root')))));
harness_register_model('UserEncryptionVault', $drive->key);
// Then the root as one from before the upgrade: an X25519 public key.
$root->set('uev_public_key', base64_encode(random_bytes(32)));
$root->save();
$gen_before = (int)$root->get('uev_key_generation');

// Nothing new is wrapped under the old root first (reviewer2 WP7 B8): a
// content vault's rotation and a new content vault both wait for it.
$threw = '';
try {
	VaultClientRotation::begin($fuid, 'drive', base64_encode((new XWing())->publicKey(random_bytes(32))),
		array(array('unlocker_type' => 'root', 'wrapped_secret_key' => base64_encode(random_bytes(60)))));
} catch (Throwable $e) { $threw = $e->getMessage(); }
check($threw === VaultClientCustody::ROOT_FIRST, 'a content vault\'s new key waits for the root to move', $threw);
$threw = '';
try {
	VaultClientCustody::createVault($fuid, 'passwords', array('public_key' => base64_encode((new XWing())->publicKey(random_bytes(32))),
		'salt' => base64_encode(random_bytes(16)),
		'wrappings' => array(array('unlocker_type' => 'root', 'wrapped_secret_key' => base64_encode(random_bytes(60))))));
} catch (Throwable $e) { $threw = $e->getMessage(); }
check($threw === VaultClientCustody::ROOT_FIRST, 'and so does a new content vault', $threw);
$threw = '';
try {
	// What vault_client_add_wrapping writes: a `root` wrapping on a content vault.
	VaultClientCustody::persistWrappings($fuid, $drive,
		array(array('unlocker_type' => 'root', 'wrapped_secret_key' => base64_encode(random_bytes(60)))));
} catch (Throwable $e) { $threw = $e->getMessage(); }
check($threw === VaultClientCustody::ROOT_FIRST, 'and so does any `root` wrapping added to one (reviewer2 WP7 B10)', $threw);

$rotate = function (array $root_unlockers, string $root_secret) use ($fx) {
	$set = vault_fixture_code_set($fx['root_salt'], 5);
	$root_unlockers['recovery'] = vault_fixture_root_recovery($fx['root_salt'], $set, $root_secret);
	return (new VaultCeremonies())->rotate($fx['user'], new UserEncryptionVault((int)$fx['vault']->key, TRUE),
		(int)$fx['passkey']->key, 'Vault Test Passkey', $fx['kek'], '', $set['set'], $root_unlockers, false);
};
$threw = '';
try { $rotate(array(), $fx['root_secret']); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'root key has to move') !== false, 'an account rotation that leaves the old root behind is refused', $threw);
check((string)VaultClientCustody::loadVault($fuid, 'root')->get('uev_public_key') === (string)$root->get('uev_public_key'),
	'and changes nothing');

$new_root_secret = random_bytes(32);
$new_root_public = base64_encode((new XWing())->publicKey($new_root_secret));
$rotated = null;
try {
	$rotated = $rotate(array('key' => array(
		'public_key' => $new_root_public,
		'passkey'    => array(array('unlocker_type' => 'passkey', 'credential_id' => (string)$fx['passkey']->get('pkc_credential_id'),
			'wrapped_secret_key' => vault_fixture_wrap($new_root_secret, random_bytes(32), 'vault:root:passkey:' . $fx['passkey']->get('pkc_credential_id')))),
		'content'    => array('drive' => vault_fixture_wrap($drive_secret, VaultUnlockerKdf::scopeKek($new_root_secret, 'drive'), 'vault:drive:root')),
	)), $new_root_secret);
} catch (Throwable $e) { check(false, 'the rotation with the root completes', $e->getMessage()); }
$root_after = VaultClientCustody::loadVault($fuid, 'root');
check(is_array($rotated) && (string)$root_after->get('uev_public_key') === $new_root_public
	&& (int)$root_after->get('uev_key_generation') === $gen_before + 1 && !$root_after->keyIsOld(),
	'the root is on its new X-Wing key, a new generation');
$live = array();
foreach (vault_live_wrappings((int)$root_after->key) as $w) { $live[] = (string)$w->get('uew_unlocker_type'); }
sort($live);
check($live === array_merge(array('passkey'), array_fill(0, 5, 'recovery')),
	'its wrappings are the new ones only: the rotating passkey and the new codes', json_encode($live));
$drive_root = null;
foreach (vault_live_wrappings((int)$drive->key) as $w) {
	if ($w->get('uew_unlocker_type') === 'root') { $drive_root = (string)$w->get('uew_wrapped_secret_key'); }
}
check($drive_root !== null && vault_fixture_unwrap($drive_root, VaultUnlockerKdf::scopeKek($new_root_secret, 'drive'), 'vault:drive:root') === $drive_secret
	&& vault_fixture_unwrap($drive_root, VaultUnlockerKdf::scopeKek($fx['root_secret'], 'drive'), 'vault:drive:root') === null,
	'the Drive vault opens through the new root, and no longer through the old one');

section('An upgrade refuses before a relay older than 3.16');
$upgrade = (string)file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
$call = strpos($upgrade, "\t\tupgrade_relay_ready();");
check($call !== false && $call < strpos($upgrade, "out_step('Deploying Upgrade')")
	&& strpos($upgrade, "version_compare(\$running, '3.16', '<')") !== false,
	'upgrade.php refuses before anything is deployed when a relay is older than 3.16, or reports no version');
check(RelayVersion::SEALS_POST_QUANTUM === '3.16' || !class_exists('RelayVersion'), 'the same version RelayVersion names');

harness_finish();
