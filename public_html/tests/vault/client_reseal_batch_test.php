<?php
/** @joinery-test
 * name: client_reseal_batch
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 180
 *
 * Rotating a client-custody vault's key (docs/sealed_vault.md § Rotating a
 * client-custody key). The browser does the crypto; the server keeps the
 * books, and the books are what this pins: the pending generation leaves the
 * key in use and its unlockers alone, the walk lists exactly the caller's rows
 * still on the old key across every registered model, a re-seal writes only a
 * row the caller owns and only to its own scope, the commit is refused while
 * anything is left and then retires the old wrappings and takes the scope off
 * linked devices, material sealed during the rotation goes to the new key, and
 * there is no way to discard a pending key. This test plays the browser, so it
 * holds both secrets.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));
require_once(PathHelper::getIncludePath('logic/drive_key_grants_reseal_logic.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

/** A contact whose 'fortress' rows seal to the owner's drive vault. */
class ClientResealContactProbe extends MailboxContact {
	public static $seal_on_save = true;
	protected static function sealScopeForWrite(array $row): string {
		return (string)($row['imc_source'] ?? '') === 'fortress' ? 'drive' : 'user';
	}
}

/** A second model on another table, so the walk crosses models. */
class ClientResealIdempotencyProbe extends ApiIdempotencyKey {
	protected static function sealScopeForWrite(array $row): string {
		return 'drive';
	}
}

function crb_throws(callable $fn): ?Throwable {
	try { $fn(); } catch (Throwable $e) { return $e; }
	return null;
}

$box = new SealedBox();
$crypto = new VaultCrypto();
$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();

$owner = make_user('ClientReseal');
$owner_id = (int)$owner->key;
$other = make_user('ClientResealOther');

// The browser's keypairs: the one in use, and the one the rotation makes.
$old_pair = $box->generateKeypair();
$old_pub = base64_encode(SealedBox::b64url_decode($old_pair['public']));
$new_pair = $box->generateKeypair();
$new_pub = base64_encode(SealedBox::b64url_decode($new_pair['public']));
$vault_id = vault_fixture_client_vault($owner_id, $old_pub, 'drive');
$db->prepare("INSERT INTO uew_user_encryption_wrappings (uew_uev_user_encryption_vault_id, uew_unlocker_type, uew_wrapped_secret_key, uew_salt, uew_key_generation)
	VALUES (?, 'passphrase', 'old-passphrase-wrapping', 'salt', 1), (?, 'recovery', 'old-recovery-wrapping', 'salt', 1)")
	->execute(array($vault_id, $vault_id));
$other_pair = $box->generateKeypair();
vault_fixture_client_vault((int)$other->key, base64_encode(SealedBox::b64url_decode($other_pair['public'])), 'drive');

VaultUnlock::clientReseal('drive', array('ClientResealContactProbe', 'ClientResealIdempotencyProbe'));

// Rows sealed to the drive vault: three contacts (sealed by the server, the
// browser format) and two idempotency rows; plus rows that must never be listed.
$contact_ids = array();
foreach (array('a', 'b', 'c') as $n) {
	$c = new ClientResealContactProbe(NULL);
	$c->set('imc_usr_user_id', $owner_id);
	$c->set('imc_address', $n . '@example.com');
	$c->set('imc_display_name', 'Person ' . strtoupper($n));
	$c->set('imc_address_hash', hash('sha256', $n . '|' . random_bytes(8)));
	$c->set('imc_source', 'fortress');
	$c->save();
	harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$c->key);
	$contact_ids[] = (int)$c->key;
}
$insert_aik = $db->prepare("INSERT INTO aik_api_idempotency_keys (aik_key_hash, aik_credential_scope, aik_action, aik_body_hash,
	aik_content_sealed, aik_sealed_key, aik_sealed_owner_user_id, aik_key_generation) VALUES (?, ?, 'test', ?, true, ?, ?, 1)
	RETURNING aik_api_idempotency_key_id");
$aik_ids = array();
foreach (array($owner_id, $owner_id, (int)$other->key) as $uid) {
	$pub = ($uid === $owner_id) ? $old_pub : base64_encode(SealedBox::b64url_decode($other_pair['public']));
	$insert_aik->execute(array(bin2hex(random_bytes(32)), 'user:' . $uid, bin2hex(random_bytes(32)),
		$crypto->sealItemDekToBrowserKey(random_bytes(32), $pub, 'drive'), $uid));
	$id = (int)$insert_aik->fetchColumn();
	harness_register_row('aik_api_idempotency_keys', 'aik_api_idempotency_key_id', $id);
	$aik_ids[] = $id;
}
$others_aik = array_pop($aik_ids);

// A Drive file key grant, and a linked computer holding drive and passwords.
$file = File::createFromBytes('crb-' . bin2hex(random_bytes(4)), 'crb.txt', 'text/plain', $owner_id,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
harness_defer(function () use ($file) { $f = new File((int)$file->key, true); if ($f->key) { $f->permanent_delete(); } });
$grant_blob = $box->sealEdge(random_bytes(32), $old_pub);
FileKeyGrant::put((int)$file->key, $owner_id, $grant_blob);
$device_key = ApiKey::CreateSessionKey($owner_id, 'Rotation Box')['api_key'];
harness_register_row('apk_api_keys', 'apk_api_key_id', (int)$device_key->key);
$device = new SyncDevice(NULL);
$device->set('sde_usr_user_id', $owner_id);
$device->set('sde_apk_api_key_id', (int)$device_key->key);
$device->set('sde_device_name', 'Rotation Box');
$device->set('sde_platform', SyncDevice::PLATFORM_LINUX);
$device->set('sde_device_pubkey', base64_encode(random_bytes(32)));
$device->set('sde_vault_scopes', 'drive,passwords');
$device->save();
harness_register_row('sde_sync_devices', 'sde_sync_device_id', (int)$device->key);

$new_wrappings = array(
	array('unlocker_type' => 'passphrase', 'wrapped_secret_key' => 'new-passphrase-wrapping', 'salt' => 'salt'),
	array('unlocker_type' => 'recovery', 'wrapped_secret_key' => 'new-recovery-wrapping', 'salt' => 'salt'),
);

// ---------------------------------------------------------------------------
section('begin stores a pending generation and touches nothing in use');
// ---------------------------------------------------------------------------
check(crb_throws(function () use ($owner_id, $new_pub) {
	VaultClientRotation::begin($owner_id, 'drive', $new_pub, array(array('unlocker_type' => 'recovery', 'wrapped_secret_key' => 'x')));
}) !== null, 'a new key with no passkey or passphrase to open it is refused');

$began = VaultClientRotation::begin($owner_id, 'drive', $new_pub, $new_wrappings);
check(($began['pending_key_generation'] ?? null) === 2, 'the pending generation is the next one');
$st = VaultClientCustody::statusPayload($owner_id, 'drive');
check($st['public_key'] === $old_pub && $st['key_generation'] === 1, 'the key in use is unchanged');
check(count($st['wrappings']) === 2 && count($st['pending_wrappings']) === 2,
	'the status lists the key in use\'s unlockers, and the pending ones apart');
check($st['pending_public_key'] === $new_pub, 'and names the pending public key');
check(crb_throws(function () use ($owner_id, $new_pub, $new_wrappings) {
	VaultClientRotation::begin($owner_id, 'drive', $new_pub, $new_wrappings);
}) !== null, 'a second begin is refused while one is pending');
check(crb_throws(function () use ($owner_id) {
	VaultClientCustody::assertNoPendingRotation(VaultClientCustody::loadVault($owner_id, 'drive'));
}) !== null, 'changing the old key\'s unlockers is refused meanwhile');

// ---------------------------------------------------------------------------
section('a pending key is never discarded');
// ---------------------------------------------------------------------------
// Keys a consumer's hook moves (Drive grants, the password store key) carry
// no generation, so the server cannot tell that nothing moved; discarding the
// pending key could strand them. The only way out of a rotation is through it.
check(!method_exists('VaultClientRotation', 'abandon')
	&& !file_exists(PathHelper::getIncludePath('logic/vault_client_rotate_abandon_logic.php')),
	'there is no abandon, in the class or as an action');

// ---------------------------------------------------------------------------
section('material sealed during the rotation goes to the new key');
// ---------------------------------------------------------------------------
$vault_now = VaultClientCustody::loadVault($owner_id, 'drive');
check($vault_now->sealingPublicKey() === $new_pub && $vault_now->sealingKeyGeneration() === 2,
	'while pending, the vault seals to the pending key and its generation');
$late = new ClientResealContactProbe(NULL);
$late->set('imc_usr_user_id', $owner_id);
$late->set('imc_address', 'late@example.com');
$late->set('imc_display_name', 'Late Arrival');
$late->set('imc_address_hash', hash('sha256', 'late|' . random_bytes(8)));
$late->set('imc_source', 'fortress');
$late->save();
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$late->key);
$late_raw = $db->query('SELECT imc_sealed_key, imc_key_generation FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ' . (int)$late->key)->fetch(PDO::FETCH_ASSOC);
check((int)$late_raw['imc_key_generation'] === 2, 'a row the server seals now is stamped with the pending generation');
check($box->openEdge(substr($late_raw['imc_sealed_key'], strlen('v1.edgeseal.drive.')), $new_pair['secret'], $new_pub) !== '',
	'and its key opens with the new secret');

$shell = new ClientResealContactProbe(NULL);
$shell->set('imc_usr_user_id', $owner_id);
$shell->set('imc_address_hash', hash('sha256', 'shell|' . random_bytes(8)));
$shell->set('imc_source', 'fortress');
$shell->save();
$sid = (int)$shell->key;
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', $sid);
$bdek = random_bytes(32);
$bfields = array('imc_address' => $crypto->sealFieldForBrowser('b@example.com', $bdek, ClientResealContactProbe::sealAd($sid, 'imc_address')));
check(crb_throws(function () use ($sid, $bdek, $bfields, $crypto) {
	$stranger = base64_encode(random_bytes(32));
	ClientResealContactProbe::acceptBrowserSealed($sid, $crypto->sealItemDekToBrowserKey($bdek, $stranger, 'drive'), $bfields, $stranger);
}) !== null, 'a browser write naming a key the vault does not have is refused');
ClientResealContactProbe::acceptBrowserSealed($sid, $crypto->sealItemDekToBrowserKey($bdek, $new_pub, 'drive'), $bfields, $new_pub);
check((int)$db->query('SELECT imc_key_generation FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ' . $sid)->fetchColumn() === 2,
	'a browser write naming the pending key is stamped with the pending generation');
check(VaultClientRotation::resealPage($owner_id, 'drive', '', 0, 200)['remaining'] === 5,
	'and neither is listed for re-sealing: both are on the new key already');

// ---------------------------------------------------------------------------
section('the walk lists exactly the caller\'s rows on the old key, across models');
// ---------------------------------------------------------------------------
$listed = array();
$cursor = array('model' => '', 'after_id' => 0);
$pages = 0;
do {
	$page = VaultClientRotation::resealPage($owner_id, 'drive', $cursor['model'], $cursor['after_id'], 2);
	foreach ($page['rows'] as $r) { $listed[] = $r; }
	$cursor = $page['next'];
	$pages++;
} while ($cursor !== null && $pages < 20);
$by_model = array();
foreach ($listed as $r) { $by_model[$r['model']][] = $r['id']; }
check(($by_model['ClientResealContactProbe'] ?? array()) === $contact_ids, 'every contact on the old key, in order');
check(($by_model['ClientResealIdempotencyProbe'] ?? array()) === $aik_ids, 'and every idempotency row, from the second model');
check(!in_array($others_aik, $by_model['ClientResealIdempotencyProbe'] ?? array(), true), 'another member\'s row is never listed');
check($pages >= 3, 'pages of two walk the set', $pages . ' pages');
check(VaultClientRotation::resealPage($owner_id, 'drive', '', 0, 200)['remaining'] === 5, 'the page counts what is left');

// A scope name may hold '_', which SQL LIKE reads as any one character. A row
// sealed to a sibling scope whose name differs only there must not be listed.
$insert_aik->execute(array(bin2hex(random_bytes(32)), 'user:' . $owner_id, bin2hex(random_bytes(32)),
	'v1.edgeseal.acmeXnotes.' . base64_encode(random_bytes(80)), $owner_id));
harness_register_row('aik_api_idempotency_keys', 'aik_api_idempotency_key_id', (int)$insert_aik->fetchColumn());
$insert_aik->execute(array(bin2hex(random_bytes(32)), 'user:' . $owner_id, bin2hex(random_bytes(32)),
	'v1.edgeseal.acme_notes.' . base64_encode(random_bytes(80)), $owner_id));
$acme_id = (int)$insert_aik->fetchColumn();
harness_register_row('aik_api_idempotency_keys', 'aik_api_idempotency_key_id', $acme_id);
$acme_page = ClientResealIdempotencyProbe::browserResealPage($owner_id, 'acme_notes', 1, 0, 50);
check(array_map(function ($r) { return $r['id']; }, $acme_page['rows']) === array($acme_id) && $acme_page['remaining'] === 1,
	'an underscore in a scope name is matched literally, not as a wildcard');

// ---------------------------------------------------------------------------
section('a re-seal writes only the caller\'s row, only to its own scope');
// ---------------------------------------------------------------------------
$reseal = function (array $r) use ($box, $crypto, $old_pair, $old_pub, $new_pub) {
	$scope = VaultCrypto::parseEdgeScope($r['sealed_dek']);
	$dek = $box->openEdge(substr($r['sealed_dek'], strlen('v1.edgeseal.' . $scope . '.')), $old_pair['secret'], $old_pub);
	return array('model' => $r['model'], 'id' => $r['id'], 'sealed_dek' => $crypto->sealItemDekToBrowserKey($dek, $new_pub, 'drive'));
};
$first = $reseal($listed[0]);
check(crb_throws(function () use ($owner_id, $first, $others_aik) {
	VaultClientRotation::resealRows($owner_id, array(array('model' => 'ClientResealIdempotencyProbe', 'id' => $others_aik, 'sealed_dek' => $first['sealed_dek'])));
}) !== null, 'a row the caller does not own is refused');
check(crb_throws(function () use ($owner_id, $first, $crypto, $new_pub) {
	VaultClientRotation::resealRows($owner_id, array(array('model' => $first['model'], 'id' => $first['id'],
		'sealed_dek' => $crypto->sealItemDekToBrowserKey(random_bytes(32), $new_pub, 'passwords'))));
}) !== null, 'a key sealed for another scope is refused');
check(crb_throws(function () use ($owner_id, $first) {
	VaultClientRotation::resealRows($owner_id, array(array('model' => 'User', 'id' => $first['id'], 'sealed_dek' => $first['sealed_dek'])));
}) !== null, 'a model nothing registered for the scope is refused');

check(VaultClientRotation::resealRows($owner_id, array($first)) === 1, 'the caller\'s own row is written');
check(crb_throws(function () use ($owner_id) { VaultClientRotation::commit($owner_id, 'drive'); }) !== null,
	'the commit is refused while rows are left on the old key');

VaultClientRotation::resealRows($owner_id, array_map($reseal, array_slice($listed, 1)));
check(VaultClientRotation::resealPage($owner_id, 'drive', '', 0, 200)['rows'] === array(), 'the walk ends: nothing left on the old key');
check(VaultClientRotation::resealRows($owner_id, array($first)) === 1, 'writing a moved row again is harmless, so a walk can resume');

// ---------------------------------------------------------------------------
section('Drive\'s grants move through their own action');
// ---------------------------------------------------------------------------
$session->set_api_user($owner_id);
$list = drive_key_grants_reseal_logic(array('mode' => 'list'));
check(!$list->error && count($list->data['grants']) === 1 && $list->data['grants'][0]['wrapped_file_key'] === $grant_blob,
	'the member\'s own grants are listed', (string)$list->error);
$new_grant = $box->sealEdge($box->openEdge($grant_blob, $old_pair['secret'], $old_pub), $new_pub);
$write = drive_key_grants_reseal_logic(array('mode' => 'write', 'keys' => array((int)$file->key => $new_grant)));
check(!$write->error && $write->data['written'] === 1, 'and written back re-sealed', (string)$write->error);
$session->set_api_user((int)$other->key);
check(drive_key_grants_reseal_logic(array('mode' => 'list'))->error !== null, 'with no rotation pending there is nothing to do');
$session->set_api_user($owner_id);

// ---------------------------------------------------------------------------
section('the commit makes the new key the key');
// ---------------------------------------------------------------------------
$done = VaultClientRotation::commit($owner_id, 'drive');
check(($done['key_generation'] ?? null) === 2, 'the vault is on generation 2');
$st = VaultClientCustody::statusPayload($owner_id, 'drive');
check($st['public_key'] === $new_pub && $st['pending_key_generation'] === null, 'the pending key is the key');
check(array_map(function ($w) { return $w['wrapped_secret_key']; }, $st['wrappings']) === array('new-passphrase-wrapping', 'new-recovery-wrapping'),
	'the old wrappings retired; the new ones unlock it');
$dev = new SyncDevice((int)$device->key, true);
check($dev->vault_scopes() === array('passwords'), 'the linked computer no longer holds the drive key');

$reread = new ClientResealContactProbe($contact_ids[1], true);
$api = $reread->export_for_api();
$dek = $box->openEdge(substr($api['sealed_dek'], strlen('v1.edgeseal.drive.')), $new_pair['secret'], $new_pub);
check($box->aeadDecryptGcm(substr($api['imc_display_name'], strlen('v1.edge.')), $dek, $api['sealed_ad_prefix'] . $contact_ids[1] . ':imc_display_name') === 'Person B',
	'a moved row opens with the new key and reads the same');
check($box->openEdge(FileKeyGrant::wrapped_key_for((int)$file->key, $owner_id), $new_pair['secret'], $new_pub) !== '',
	'and so does the Drive file key');

$session->clear_api_user();
harness_finish();
?>
