<?php
/** @joinery-test
 * name: custody_change
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 *
 * Moving a sealed row between custodies (specs/client_custody_mail.md § R8).
 *
 *  - The raise: SystemBase::convertRowToClientCustody() turns a server-sealed
 *    row into one only the browser opens, under the SAME DEK and the same AD,
 *    the DEK sealed to the client vault's key, in one UPDATE; it refuses a row
 *    not sealed, one already under a client-custody vault, a vault not client
 *    custody or not the owner's, and a scope the row's hook does not name.
 *  - The lowering: browserCustodyPage() lists only the rows whose hook moved,
 *    VaultCustodyChange pages them across the registered models with the
 *    target's public key, and acceptBrowserCustodyChange() stores a
 *    `v2.edgeseal.user.` key, refusing another user's row, a row the hook
 *    keeps, a key for the wrong vault and, with the window open, a key that
 *    does not open the row.
 *  - B12: a row in the browser's format under the server's key reads in the
 *    window, locks with it, is not offered to the browser, is re-sealed by a
 *    server rotation, and can be raised again.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!vault_apcu_usable() || !vault_ensure_session()) {
	harness_skip('APCu or a session is unavailable, so no unlock window can open');
	harness_finish();
}

/** A contact whose 'fortress' rows belong to the owner's drive vault. */
class CustodyChangeProbe extends MailboxContact {
	public static $seal_on_save = false;
	protected static function sealScopeForWrite(array $row): string {
		return (string)($row['imc_source'] ?? '') === 'fortress' ? 'drive' : 'user';
	}
}

/**
 * A probe whose ownership hook, which resealRows() calls between reading a row
 * and writing it, lets a test change the row at that moment: a concurrent
 * writer during a re-seal that runs for minutes.
 */
class CustodyChangeRaceProbe extends CustodyChangeProbe {
	public static $meanwhile = null;
	protected static function sealedOwnerUserIdFor(array $row): ?int {
		if (self::$meanwhile !== null) { (self::$meanwhile)($row); }
		return parent::sealedOwnerUserIdFor($row);
	}
}

function ccp_raw(int $id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT * FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

function ccp_throws(callable $fn): ?string {
	try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
	return null;
}

$db = DbConnector::get_instance()->get_db_link();
$box = new SealedBox();

// The owner's server vault (its secret held here) and their drive vault (the browser's keypair).
$owner = make_user('CustodyChange');
$owner_id = (int)$owner->key;
$keypair = $box->generateKeypair();
$server_secret = $keypair['secret'];
$server_vault = new UserEncryptionVault(NULL);
$server_vault->set('uev_usr_user_id', $owner_id);
$server_vault->set('uev_public_key', $keypair['public']);
$server_vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
$server_vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$server_vault->key);
$server_key = vault_fixture_key($server_secret);

$pair = $box->generateKeypair();
$pub = base64_encode(SealedBox::b64url_decode($pair['public']));
vault_fixture_client_vault($owner_id, $pub, 'drive');
$drive_vault = UserEncryptionVault::loadForUser($owner_id, 'drive');

$other = make_user('CustodyChangeOther');
$other_pair = $box->generateKeypair();
vault_fixture_client_vault((int)$other->key, base64_encode(SealedBox::b64url_decode($other_pair['public'])), 'drive');
$other_vault = UserEncryptionVault::loadForUser((int)$other->key, 'drive');

// A server-sealed contact: two sealed columns under one DEK.
$contact = new CustodyChangeProbe(NULL);
$contact->set('imc_usr_user_id', $owner_id);
$contact->set('imc_address', '');
$contact->set('imc_display_name', '');
$contact->set('imc_address_hash', hash('sha256', 'cc|' . random_bytes(8)));
$contact->set('imc_source', 'manual');
$contact->save();
$id = (int)$contact->key;
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', $id);
CustodyChangeProbe::sealColumns($id, $server_vault, array('imc_address' => 'robin@example.com', 'imc_display_name' => 'Robin Raised'));
$before = ccp_raw($id);
check(strpos((string)$before['imc_address'], 'v1.aead.') === 0 && strpos((string)$before['imc_sealed_key'], 'v2.edgeseal.') !== 0,
	'the row starts sealed to the server');

// ---------------------------------------------------------------------------
section('What the raise refuses');
// ---------------------------------------------------------------------------
$msg = ccp_throws(function () use ($id, $server_key, $drive_vault) {
	CustodyChangeProbe::convertRowToClientCustody($id, $server_key, $drive_vault);
});
check($msg !== null && strpos($msg, 'does not belong') !== false, 'a row its hook still keeps on the server', (string)$msg);

$db->prepare("UPDATE imc_mailbox_contacts SET imc_source = 'fortress' WHERE imc_mailbox_contact_id = ?")->execute(array($id));
$msg = ccp_throws(function () use ($id, $server_key, $other_vault) {
	CustodyChangeProbe::convertRowToClientCustody($id, $server_key, $other_vault);
});
check($msg !== null && strpos($msg, 'owner') !== false, 'another person\'s vault', (string)$msg);
$msg = ccp_throws(function () use ($id, $server_key, $server_vault) {
	CustodyChangeProbe::convertRowToClientCustody($id, $server_key, $server_vault);
});
check($msg !== null && strpos($msg, 'client-custody') !== false, 'a server-custody vault', (string)$msg);
check(ccp_raw($id)['imc_sealed_key'] === $before['imc_sealed_key'], 'and each refusal leaves the row as it was');

// ---------------------------------------------------------------------------
section('The raise');
// ---------------------------------------------------------------------------
$dek = CustodyChangeProbe::convertRowToClientCustody($id, $server_key, $drive_vault);
$after = ccp_raw($id);
check(strpos((string)$after['imc_sealed_key'], 'v2.edgeseal.drive.') === 0, 'the DEK is sealed to the drive vault in the browser\'s format');
$opened = $box->openEdge(substr((string)$after['imc_sealed_key'], strlen('v2.edgeseal.drive.')), $pair['secret'], $pub);
check($opened === $dek, 'the browser\'s key opens it, and it is the DEK the row always had');
check((int)$after['imc_key_generation'] === (int)$drive_vault->get('uev_key_generation')
	&& (int)$after['imc_sealed_owner_user_id'] === $owner_id, 'generation and owner are the drive vault\'s');
$ok = true;
foreach (array('imc_address' => 'robin@example.com', 'imc_display_name' => 'Robin Raised') as $col => $want) {
	$v = (string)$after[$col];
	if (strpos($v, 'v1.edge.') !== 0
			|| $box->aeadDecryptGcm(substr($v, strlen('v1.edge.')), $dek, CustodyChangeProbe::sealAd($id, $col)) !== $want) {
		$ok = false;
	}
}
check($ok, 'every sealed field is in the browser\'s format and opens under the same DEK and AD');
check(strpos(json_encode($after), 'Robin') === false, 'no plaintext in the row');

$msg = ccp_throws(function () use ($id, $server_key, $drive_vault) {
	CustodyChangeProbe::convertRowToClientCustody($id, $server_key, $drive_vault);
});
check($msg !== null && strpos($msg, 'already') !== false, 'a second raise is refused', (string)$msg);

$plain = new CustodyChangeProbe(NULL);
$plain->set('imc_usr_user_id', $owner_id);
$plain->set('imc_address', 'plain@example.com');
$plain->set('imc_display_name', 'Plain');
$plain->set('imc_address_hash', hash('sha256', 'cc|' . random_bytes(8)));
$plain->set('imc_source', 'fortress');
$plain->save();
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$plain->key);
$msg = ccp_throws(function () use ($plain, $server_key, $drive_vault) {
	CustodyChangeProbe::convertRowToClientCustody((int)$plain->key, $server_key, $drive_vault);
});
check($msg !== null && strpos($msg, 'not sealed') !== false, 'an unsealed row has nothing to move', (string)$msg);

// ---------------------------------------------------------------------------
section('The lowering: what the walk lists');
// ---------------------------------------------------------------------------
$crypto = new VaultCrypto();
$server_pub_b64 = base64_encode(SealedBox::b64url_decode((string)$server_vault->get('uev_public_key')));
$page = CustodyChangeProbe::browserCustodyPage($owner_id, 'drive', 0, 50);
check(!in_array($id, array_column($page['rows'], 'id'), true) && $page['done'],
	'a row its hook still keeps in the drive vault is not listed');
$msg = ccp_throws(function () use ($crypto, $dek, $server_pub_b64, $owner_id, $id) {
	CustodyChangeProbe::acceptBrowserCustodyChange($owner_id, $id, $crypto->sealItemDekToBrowserKey($dek, $server_pub_b64, 'user'));
});
check($msg !== null && strpos($msg, 'still belongs') !== false, 'and a key for it is refused', (string)$msg);

$db->prepare("UPDATE imc_mailbox_contacts SET imc_source = 'manual' WHERE imc_mailbox_contact_id = ?")->execute(array($id));
$page = CustodyChangeProbe::browserCustodyPage($owner_id, 'drive', 0, 50);
$listed = array_values(array_filter($page['rows'], function ($r) use ($id) { return $r['id'] === $id; }));
check(count($listed) === 1 && $listed[0]['target_scope'] === 'user' && $listed[0]['sealed_dek'] === $after['imc_sealed_key'],
	'once the hook names the user vault, the row is listed with its key and its target');
check(CustodyChangeProbe::browserCustodyBacklog($owner_id, 'drive') === null, 'and the generic model does not count (it would read every row)');
check(CustodyChangeProbe::browserCustodyPage((int)$other->key, 'drive', 0, 50)['rows'] === array(), 'another user\'s walk does not see it');

VaultUnlock::clientReseal('drive', array(CustodyChangeProbe::class));
$walk = VaultCustodyChange::page($owner_id, 'drive', '', 0, 100);
$found = null;
while (true) {
	foreach ($walk['rows'] as $r) { if ($r['model'] === CustodyChangeProbe::class && $r['id'] === $id) { $found = $r; } }
	if ($found || !$walk['next']) { break; }
	$walk = VaultCustodyChange::page($owner_id, 'drive', $walk['next']['model'], $walk['next']['after_id'], 100);
}
check($found !== null && $found['target_public_key'] === $server_pub_b64,
	'VaultCustodyChange pages it with the server vault\'s key in standard base64');

// ---------------------------------------------------------------------------
section('The lowering: what acceptance refuses');
// ---------------------------------------------------------------------------
$good = $crypto->sealItemDekToBrowserKey($dek, $server_pub_b64, 'user');
$msg = ccp_throws(function () use ($good, $other, $id) {
	CustodyChangeProbe::acceptBrowserCustodyChange((int)$other->key, $id, $good);
});
check($msg !== null && strpos($msg, 'not yours') !== false, 'another user\'s row', (string)$msg);
$msg = ccp_throws(function () use ($crypto, $dek, $pub, $owner_id, $id) {
	CustodyChangeProbe::acceptBrowserCustodyChange($owner_id, $id, $crypto->sealItemDekToBrowserKey($dek, $pub, 'drive'));
});
check($msg !== null && strpos($msg, 'sealed to another') !== false, 'a key sealed to another vault than the hook names', (string)$msg);
$msg = ccp_throws(function () use ($owner_id) {
	VaultCustodyChange::accept($owner_id, 'drive', array(array('model' => 'User', 'id' => 1, 'sealed_dek' => 'x')));
});
check($msg !== null && strpos($msg, 'Nothing named') !== false, 'a model not kept in the vault', (string)$msg);

vault_fixture_open_window($owner_id, $server_secret);
$wrong = $crypto->sealItemDekToBrowserKey(random_bytes(32), $server_pub_b64, 'user');
$msg = ccp_throws(function () use ($wrong, $owner_id, $id) {
	CustodyChangeProbe::acceptBrowserCustodyChange($owner_id, $id, $wrong);
});
check($msg !== null, 'with the window open, a key that does not open the row\'s content', (string)$msg);
check(ccp_raw($id)['imc_sealed_key'] === $after['imc_sealed_key'], 'and each refusal leaves the row as it was');

// ---------------------------------------------------------------------------
section('The lowering: acceptance, and the row reads on the server (B12)');
// ---------------------------------------------------------------------------
check(VaultCustodyChange::accept($owner_id, 'drive', array(array('model' => CustodyChangeProbe::class, 'id' => $id, 'sealed_dek' => $good))) === 1,
	'the browser\'s key is stored');
$lowered = ccp_raw($id);
check($lowered['imc_sealed_key'] === $good && strpos((string)$lowered['imc_address'], 'v1.edge.') === 0,
	'the key is v2.edgeseal.user., the fields are untouched');
check((int)$lowered['imc_key_generation'] === (int)$server_vault->get('uev_key_generation'), 'on the server vault\'s generation');
check(VaultCrypto::clientCustodyScope($good) === null && VaultCrypto::clientCustodyScope((string)$after['imc_sealed_key']) === 'drive',
	'clientCustodyScope: the user scope is the server\'s, the drive scope the browser\'s');
check(CustodyChangeProbe::browserCustodyPage($owner_id, 'drive', 0, 50)['rows'] === array()
	|| !in_array($id, array_column(CustodyChangeProbe::browserCustodyPage($owner_id, 'drive', 0, 50)['rows'], 'id'), true),
	'the walk no longer lists it');

$read = new CustodyChangeProbe($id, TRUE);
check($read->get('imc_address') === 'robin@example.com' && $read->get('imc_display_name') === 'Robin Raised',
	'in the window, the model reads the row');
$api = $read->export_for_api();
check(!array_key_exists('sealed_dek', $api) && !array_key_exists('sealed_scope', $api) && ($api['imc_address'] ?? '') === 'robin@example.com',
	'the API export is the ordinary one: nothing is offered to the browser');

VaultUnlock::lockAll($owner_id);
VaultCrypto::forgetItemDeks();
$locked = null;
try { (new CustodyChangeProbe($id, TRUE))->get('imc_address'); } catch (Throwable $e) { $locked = get_class($e); }
check($locked === 'VaultLockedException', 'with the window closed it is locked, not the browser\'s', (string)$locked);

// A server rotation re-seals it; the generic reseal leaves a row under a client-custody vault alone.
$next_pair = $box->generateKeypair();
$next_pub = $next_pair['public'];
$old_gen = (int)$server_vault->get('uev_key_generation');
$res = CustodyChangeProbe::resealRows($owner_id, $server_key, $old_gen, $next_pub, $old_gen + 1);
check($res['failed'] === 0 && strpos((string)ccp_raw($id)['imc_sealed_key'], 'v2.seal.') === 0,
	'a server rotation re-seals the lowered row\'s key', json_encode($res));
$next_key = vault_fixture_key($next_pair['secret']);
// The rotation's flip runs in its own request, not in this one that read the
// row: an X-Wing public key is long enough for the egress guard to weigh.
SealedEgressGuard::isolate(function () use ($db, $next_pub, $old_gen, $server_vault) {
	$db->prepare('UPDATE uev_user_encryption_vaults SET uev_public_key = ?, uev_key_generation = ? WHERE uev_user_encryption_vault_id = ?')
		->execute(array($next_pub, $old_gen + 1, (int)$server_vault->key));
});
$server_vault = new UserEncryptionVault((int)$server_vault->key, TRUE);

// And it can be raised again (a v1.edge. field under a server key re-seals like any other).
$db->prepare("UPDATE imc_mailbox_contacts SET imc_source = 'fortress' WHERE imc_mailbox_contact_id = ?")->execute(array($id));
$dek2 = CustodyChangeProbe::convertRowToClientCustody($id, $next_key, $drive_vault);
check($dek2 === $dek && strpos((string)ccp_raw($id)['imc_sealed_key'], 'v2.edgeseal.drive.') === 0, 'the row can be raised again, same DEK');

$other_row = new CustodyChangeProbe(NULL);
$other_row->set('imc_usr_user_id', $owner_id);
$other_row->set('imc_address', '');
$other_row->set('imc_display_name', '');
$other_row->set('imc_address_hash', hash('sha256', 'cc|' . random_bytes(8)));
$other_row->set('imc_source', 'fortress');
$other_row->save();
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$other_row->key);
CustodyChangeProbe::sealColumns((int)$other_row->key, $drive_vault, array('imc_address' => 'x@example.com'));
$res = CustodyChangeProbe::resealRows($owner_id, $next_key, (int)$drive_vault->get('uev_key_generation'), $next_pub, 99);
check($res['failed'] === 0 && strpos((string)ccp_raw((int)$other_row->key)['imc_sealed_key'], 'v2.edgeseal.drive.') === 0,
	'a server rotation leaves a row under a client-custody vault to the browser', json_encode($res));

// A row re-sealed by someone else while the pass runs keeps what they wrote:
// the pass's write is a compare-and-swap on the key and generation it read.
$race_row = new CustodyChangeProbe(NULL);
$race_row->set('imc_usr_user_id', $owner_id);
$race_row->set('imc_address', '');
$race_row->set('imc_display_name', '');
$race_row->set('imc_address_hash', hash('sha256', 'cc|' . random_bytes(8)));
$race_row->set('imc_source', 'manual');
$race_row->save();
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$race_row->key);
CustodyChangeProbe::sealColumns((int)$race_row->key, $server_vault, array('imc_address' => 'race@example.com'));
$race_gen = (int)$server_vault->get('uev_key_generation');
$third_pub = $box->generateKeypair()['public'];
$theirs = (new VaultCrypto())->sealItemDek(random_bytes(32), $third_pub);
CustodyChangeRaceProbe::$meanwhile = function (array $row) use ($db, $race_row, $theirs, $race_gen) {
	if ((int)$row['imc_mailbox_contact_id'] !== (int)$race_row->key) { return; }
	$db->prepare('UPDATE imc_mailbox_contacts SET imc_sealed_key = ?, imc_key_generation = ? WHERE imc_mailbox_contact_id = ?')
		->execute(array($theirs, $race_gen + 1, (int)$race_row->key));
};
$res = CustodyChangeRaceProbe::resealRows($owner_id, $next_key, $race_gen, $third_pub, $race_gen + 1);
CustodyChangeRaceProbe::$meanwhile = null;
check($res['failed'] === 0 && $res['skipped'] >= 1, 'a row changed mid-pass is skipped, not failed', json_encode($res));
check((string)ccp_raw((int)$race_row->key)['imc_sealed_key'] === $theirs,
	'and the concurrent writer\'s key stands: the stale one is not written back over it');

// A row a rotation's re-seal has not reached yet reads as "not yet", not as
// a broken row: the window holds the new key while the re-seal runs.
$waiting = new CustodyChangeProbe(NULL);
$waiting->set('imc_usr_user_id', $owner_id);
$waiting->set('imc_address', '');
$waiting->set('imc_display_name', '');
$waiting->set('imc_address_hash', hash('sha256', 'cc|' . random_bytes(8)));
$waiting->set('imc_source', 'manual');
$waiting->save();
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$waiting->key);
$waiting_vault = new UserEncryptionVault((int)$server_vault->key, TRUE);
CustodyChangeProbe::sealColumns((int)$waiting->key, $waiting_vault, array('imc_address' => 'waiting@example.com'));
$waiting_gen = (int)$waiting_vault->get('uev_key_generation');
$rotated_pair = $box->generateKeypair();
SealedEgressGuard::isolate(function () use ($db, $rotated_pair, $waiting_gen, $server_vault) {
	$db->prepare('UPDATE uev_user_encryption_vaults SET uev_public_key = ?, uev_key_generation = ? WHERE uev_user_encryption_vault_id = ?')
		->execute(array($rotated_pair['public'], $waiting_gen + 1, (int)$server_vault->key));
});
VaultCrypto::forgetItemDeks();
vault_fixture_open_window($owner_id, $rotated_pair['secret']);
$said = null;
try { (new CustodyChangeProbe((int)$waiting->key, TRUE))->get('imc_address'); } catch (Throwable $e) { $said = $e; }
check($said !== null && !($said instanceof VaultLockedException),
	'with no re-seal running, a row on an older generation that nothing opens is an error, not a wait', $said ? get_class($said) : 'read');
// A re-seal running elsewhere (another connection holds the member's drain lock).
$gs = Globalvars::get_instance();
$drain = new PDO('pgsql:host=localhost port=5432 dbname=' . $gs->get_setting('dbname'), $gs->get_setting('dbusername'), $gs->get_setting('dbpassword'));
$drain->prepare('SELECT pg_advisory_lock(?, ?)')->execute(array(VaultCeremonies::DRAIN_LOCK_NAMESPACE, $owner_id));
VaultCrypto::forgetItemDeks();
$said = null;
try { (new CustodyChangeProbe((int)$waiting->key, TRUE))->get('imc_address'); } catch (Throwable $e) { $said = $e; }
$drain->prepare('SELECT pg_advisory_unlock(?, ?)')->execute(array(VaultCeremonies::DRAIN_LOCK_NAMESPACE, $owner_id));
$drain = null;
check($said instanceof VaultLockedException && strpos($said->getMessage(), 're-secured') !== false,
	'while a re-seal runs, it waits instead of failing', $said ? get_class($said) . ': ' . $said->getMessage() : 'read');
$db->prepare('UPDATE imc_mailbox_contacts SET imc_key_generation = ? WHERE imc_mailbox_contact_id = ?')->execute(array($waiting_gen + 1, (int)$waiting->key));
VaultCrypto::forgetItemDeks();
$said = null;
try { (new CustodyChangeProbe((int)$waiting->key, TRUE))->get('imc_address'); } catch (Throwable $e) { $said = $e; }
check($said !== null && !($said instanceof VaultLockedException),
	'a row on the current generation that does not open is still an error, not a wait', $said ? get_class($said) : 'read');

// The same row, once the vault keeps the key it was sealed to (a rotation's
// chain): the read answers through that key and moves the row to the current one.
$db->prepare('UPDATE imc_mailbox_contacts SET imc_key_generation = ? WHERE imc_mailbox_contact_id = ?')->execute(array($waiting_gen, (int)$waiting->key));
$chain_kek = random_bytes(32);
$chain_ad = 'custody-change-chain';
// A rotation keeps the chain in its own request, which has read no content.
SealedEgressGuard::isolate(function () use ($server_vault, $waiting_gen, $next_pair, $chain_kek, $chain_ad) {
	VaultPreviousKeys::add(new UserEncryptionVault((int)$server_vault->key, TRUE), array($waiting_gen => array(
		'wrapped' => (new SealedBox())->wrapKey(PoolVaultKey::wrappedForm($next_pair['secret']), $chain_kek, $chain_ad),
		'kek' => $chain_kek, 'ad' => $chain_ad)));
});
VaultCrypto::forgetItemDeks();
$read_back = null;
try { $read_back = (new CustodyChangeProbe((int)$waiting->key, TRUE))->get('imc_address'); } catch (Throwable $e) { $read_back = get_class($e) . ': ' . $e->getMessage(); }
check($read_back === 'waiting@example.com', 'a row on a retired key reads through the vault\'s kept key', (string)$read_back);
$healed = ccp_raw((int)$waiting->key);
check((int)$healed['imc_key_generation'] === $waiting_gen + 1
	&& vault_fixture_key($rotated_pair['secret']) !== null
	&& strlen((new VaultCrypto())->openItemDek((string)$healed['imc_sealed_key'], vault_fixture_key($rotated_pair['secret']))) === 32,
	'and the read moved it to the current key and generation');

VaultUnlock::resetClientResealsForTests();
harness_finish();
