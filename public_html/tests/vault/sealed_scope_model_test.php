<?php
/** @joinery-test
 * name: sealed_scope_model
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 *
 * Custody chosen per row: one model, server-custody rows and client-custody
 * rows side by side (docs/sealed_vault.md § Client-custody scopes).
 *
 * A model names a row's scope with sealScopeForWrite(). A client scope's secret
 * lives only in the browser, so the properties this suite pins are the ones
 * that keep that true on the server: a server write seals to the browser's
 * format and never reads it back; the API export is the one place ciphertext
 * leaves, under names the credential floor does not strip; ciphertext enters
 * only through acceptBrowserSealed(); save() will not store a sealed value as
 * though it were plaintext; and a server update cannot orphan a sealed field
 * under a key nobody records.
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
	harness_skip('APCu/session unavailable, so no unlock window can be held in CLI');
	harness_finish();
}

/**
 * A consumer with custody per row: a contact whose source is 'fortress' seals
 * to the owner's client-custody `drive` scope, every other row to the server
 * scope. MailboxContact's AD is a legacy literal (`contact:{id}:{field}`), so
 * this also proves the export hands the browser the model's own AD rule.
 */
class SealedScopeProbe extends MailboxContact {
	public static $seal_on_save = true;
	protected static function sealScopeForWrite(array $row): string {
		return (string)($row['imc_source'] ?? '') === 'fortress' ? 'drive' : 'user';
	}
}

/** Per-row custody plus a plaintext policy: a row named 'Keep Plain' is never sealed. */
class SealedScopePolicyProbe extends SealedScopeProbe {
	protected static function shouldSeal(array $row): bool {
		return (string)($row['imc_display_name'] ?? '') !== 'Keep Plain';
	}
}

/** A misdeclared consumer: names a scope nothing registers. */
class SealedScopeTypoProbe extends MailboxContact {
	public static $seal_on_save = true;
	protected static function sealScopeForWrite(array $row): string {
		return 'drvie';
	}
}

function ssm_raw(int $id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT * FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

function ssm_flag($value): bool {
	if (is_bool($value)) { return $value; }
	return in_array(strtolower((string)$value), array('t', 'true', '1', 'yes'), true);
}

function ssm_new(string $class, int $user_id, string $address, string $name, string $source) {
	$row = new $class(NULL);
	$row->set('imc_usr_user_id', $user_id);
	$row->set('imc_address', $address);
	$row->set('imc_display_name', $name);
	$row->set('imc_address_hash', hash('sha256', $address . '|' . $user_id . '|' . random_bytes(8)));
	$row->set('imc_source', $source);
	$row->save();
	harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$row->key);
	return $row;
}

/** Run $fn and return the exception it threw, or null. */
function ssm_throws(callable $fn): ?Throwable {
	try { $fn(); } catch (Throwable $e) { return $e; }
	return null;
}

/** What the browser does with a row from the API: open the DEK, then each field under its AD. */
function ssm_browser_open(array $api_row, string $secret_b64url, string $public_b64, int $id, string $field): string {
	$box = new SealedBox();
	$scope = VaultCrypto::parseEdgeScope($api_row['sealed_dek']);
	$dek = $box->openEdge(substr($api_row['sealed_dek'], strlen('v1.edgeseal.' . $scope . '.')), $secret_b64url, $public_b64);
	$blob = substr((string)$api_row[$field], strlen('v1.edge.'));
	return $box->aeadDecryptGcm($blob, $dek, $api_row['sealed_ad_prefix'] . $id . ':' . $field);
}

$box = new SealedBox();
$crypto = new VaultCrypto();

// The owner holds both a server vault (scope `user`, window openable here) and
// a client vault (scope `drive`, a keypair "the browser" minted — this test
// plays the browser, so it keeps the secret).
$owner = make_user('SealedScope');
$owner_id = (int)$owner->key;

$server_pair = $box->generateKeypair();
$server_vault = new UserEncryptionVault(NULL);
$server_vault->set('uev_usr_user_id', $owner_id);
$server_vault->set('uev_public_key', $server_pair['public']);
$server_vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
$server_vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$server_vault->key);
vault_fixture_open_window($owner_id, $server_pair['secret'], UserEncryptionVault::SCOPE_USER,
	array('idle' => null, 'absolute' => null));

$client_pair = $box->generateKeypair();
$client_public = base64_encode(SealedBox::b64url_decode($client_pair['public']));   // the browser stores standard base64
vault_fixture_client_vault($owner_id, $client_public, 'drive');

// ---------------------------------------------------------------------------
section('A server write to a client-scope row seals in the browser format');
// ---------------------------------------------------------------------------
$fort = ssm_new('SealedScopeProbe', $owner_id, 'fort@example.com', 'Fortress Friend', 'fortress');
$fid = (int)$fort->key;
$raw = ssm_raw($fid);
check(strpos((string)$raw['imc_sealed_key'], 'v1.edgeseal.drive.') === 0, 'the key is sealed to the drive scope, framed v1.edgeseal.drive.');
check(strpos((string)$raw['imc_address'], 'v1.edge.') === 0 && strpos((string)$raw['imc_display_name'], 'v1.edge.') === 0,
	'every sealed field is framed v1.edge.');
check(strpos((string)$raw['imc_address'], 'fort@example.com') === false, 'and no plaintext survives in the column');
check(ssm_flag($raw['imc_content_sealed']) && (int)$raw['imc_sealed_owner_user_id'] === $owner_id,
	'the row is flagged sealed and records its owner');
check((int)$raw['imc_key_generation'] === 1, "stamped with the client vault's generation");

$reloaded = new SealedScopeProbe($fid, TRUE);
$e = ssm_throws(function () use ($reloaded) { $reloaded->get('imc_address'); });
check($e instanceof VaultSealedForBrowserException, 'get() on it throws VaultSealedForBrowserException, with the server window OPEN');
$e = ssm_throws(function () use ($raw) { SealedScopeProbe::decryptSealedFieldStatic('imc_address', $raw['imc_address'], $raw); });
check($e instanceof VaultSealedForBrowserException, 'so does the raw-row read the AI surface uses');
check($reloaded->get('imc_use_count') !== null, 'an ordinary column still reads normally');

// ---------------------------------------------------------------------------
section('The API export is the one emitter of the ciphertext');
// ---------------------------------------------------------------------------
$api = $reloaded->export_for_api();
check(($api['sealed_scope'] ?? null) === 'drive', 'the export names the scope');
check(($api['sealed_dek'] ?? null) === (string)$raw['imc_sealed_key'], 'it carries the sealed DEK as sealed_dek');
check(($api['sealed_ad_prefix'] ?? null) === 'contact:', "it carries the model's own AD prefix (a legacy literal here)");
check(!array_key_exists('imc_sealed_key', $api), 'the key column itself stays behind the credential floor');
check(($api['imc_address'] ?? null) === (string)$raw['imc_address'], 'sealed fields export exactly as stored');
check((int)($api['key'] ?? 0) === $fid, 'the row id travels with it');
check(ssm_browser_open($api, $client_pair['secret'], $client_public, $fid, 'imc_address') === 'fort@example.com'
	&& ssm_browser_open($api, $client_pair['secret'], $client_public, $fid, 'imc_display_name') === 'Fortress Friend',
	'the browser opens every field from the export alone');
check(!array_key_exists('content_locked', $api), 'it is not the locked shape: nothing waits on a window');

// ---------------------------------------------------------------------------
section('save() never stores a sealed value, and never orphans a field');
// ---------------------------------------------------------------------------
$poke = new SealedScopeProbe($fid, TRUE);
$poke->set('imc_display_name', (string)$raw['imc_display_name']);
$e = ssm_throws(function () use ($poke) { $poke->save(); });
check($e !== null && strpos($e->getMessage(), 'acceptBrowserSealed') !== false,
	'a v1.edge. value through save() is refused, pointing at acceptBrowserSealed');

$partial = new SealedScopeProbe($fid, TRUE);
$partial->set('imc_display_name', 'Only One Field');
$e = ssm_throws(function () use ($partial) { $partial->save(); });
check($e instanceof VaultSealedForBrowserException, 'a partial server update of a client-sealed row is refused');
check((string)ssm_raw($fid)['imc_sealed_key'] === (string)$raw['imc_sealed_key'], 'and changes nothing');

$full = new SealedScopeProbe($fid, TRUE);
$full->set('imc_address', 'fort2@example.com');
$full->set('imc_display_name', 'Fortress Renamed');
$full->save();
$raw2 = ssm_raw($fid);
check((string)$raw2['imc_sealed_key'] !== (string)$raw['imc_sealed_key'] && strpos((string)$raw2['imc_sealed_key'], 'v1.edgeseal.drive.') === 0,
	'a full server update re-seals every field under a NEW DEK');
$api2 = (new SealedScopeProbe($fid, TRUE))->export_for_api();
check(ssm_browser_open($api2, $client_pair['secret'], $client_public, $fid, 'imc_address') === 'fort2@example.com'
	&& ssm_browser_open($api2, $client_pair['secret'], $client_public, $fid, 'imc_display_name') === 'Fortress Renamed',
	'and both fields open under it');

$bump = new SealedScopeProbe($fid, TRUE);
$bump->set('imc_use_count', 9);
$bump->save();
$raw3 = ssm_raw($fid);
check((int)$raw3['imc_use_count'] === 9 && (string)$raw3['imc_sealed_key'] === (string)$raw2['imc_sealed_key'],
	'an ordinary column saves on a client-sealed row without touching the seal');

// ---------------------------------------------------------------------------
section('acceptBrowserSealed: the two-step browser write');
// ---------------------------------------------------------------------------
// Step one: the row exists with its sealed columns empty.
$shell = new SealedScopeProbe(NULL);
$shell->set('imc_usr_user_id', $owner_id);
$shell->set('imc_address_hash', hash('sha256', 'shell|' . random_bytes(8)));
$shell->set('imc_source', 'fortress');
$shell->save();
$sid = (int)$shell->key;
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', $sid);
check(!ssm_flag(ssm_raw($sid)['imc_content_sealed']), 'step one: a row with empty sealed columns is not sealed');

// Step two: the "browser" seals with the row id in hand and posts ciphertext.
$dek = random_bytes(32);
$sealed_dek = $crypto->sealItemDekToBrowserKey($dek, $client_public, 'drive');
$fields = array(
	'imc_address'      => $crypto->sealFieldForBrowser('browser@example.com', $dek, SealedScopeProbe::sealAd($sid, 'imc_address')),
	'imc_display_name' => $crypto->sealFieldForBrowser('Browser Written', $dek, SealedScopeProbe::sealAd($sid, 'imc_display_name')),
);

$refusals = array(
	'a DEK sealed to another scope' => array($crypto->sealItemDekToBrowserKey($dek, $client_public, 'passwords'), $fields),
	'a server-format DEK' => array($crypto->sealItemDek($dek, $server_pair['public']), $fields),
	'a field that is not declared sealed' => array($sealed_dek, $fields + array('imc_source' => 'v1.edge.AAAA')),
	'a field that is not v1.edge. ciphertext' => array($sealed_dek, array('imc_address' => 'browser@example.com') + $fields),
	'a post that leaves a populated field behind' => array($sealed_dek, array('imc_address' => $fields['imc_address'])),
);
foreach ($refusals as $what => $args) {
	// The last case needs a populated field to leave behind; give the shell one.
	if ($what === 'a post that leaves a populated field behind') {
		DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE imc_mailbox_contacts SET imc_display_name = ? WHERE imc_mailbox_contact_id = ?')->execute(array('Plain Name', $sid));
	}
	$e = ssm_throws(function () use ($sid, $args) { SealedScopeProbe::acceptBrowserSealed($sid, $args[0], $args[1]); });
	check($e instanceof RuntimeException, 'refused: ' . $what, $e ? $e->getMessage() : 'accepted');
}
check(!ssm_flag(ssm_raw($sid)['imc_content_sealed']), 'and no refusal wrote anything');

SealedScopeProbe::acceptBrowserSealed($sid, $sealed_dek, $fields);
$raw_s = ssm_raw($sid);
check((string)$raw_s['imc_sealed_key'] === $sealed_dek && (string)$raw_s['imc_address'] === $fields['imc_address']
	&& (string)$raw_s['imc_display_name'] === $fields['imc_display_name'], 'the ciphertext is stored verbatim');
check(ssm_flag($raw_s['imc_content_sealed']) && (int)$raw_s['imc_sealed_owner_user_id'] === $owner_id && (int)$raw_s['imc_key_generation'] === 1,
	'with the flag, the owner and the generation written in the same statement');
$e = ssm_throws(function () use ($sid) { (new SealedScopeProbe($sid, TRUE))->get('imc_address'); });
check($e instanceof VaultSealedForBrowserException, 'the row reads as sealed for the browser');
$api_s = (new SealedScopeProbe($sid, TRUE))->export_for_api();
check(ssm_browser_open($api_s, $client_pair['secret'], $client_public, $sid, 'imc_display_name') === 'Browser Written',
	'and the browser opens what it posted');

// The browser path asks the row's policy too, as the server path does.
$plain_shell = new SealedScopePolicyProbe(NULL);
$plain_shell->set('imc_usr_user_id', $owner_id);
$plain_shell->set('imc_address_hash', hash('sha256', 'plainshell|' . random_bytes(8)));
$plain_shell->set('imc_source', 'fortress');
$plain_shell->set('imc_display_name', 'Keep Plain');
$plain_shell->save();
$pid = (int)$plain_shell->key;
harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', $pid);
$pdek = random_bytes(32);
$e = ssm_throws(function () use ($pid, $pdek, $crypto, $client_public) {
	SealedScopePolicyProbe::acceptBrowserSealed($pid, $crypto->sealItemDekToBrowserKey($pdek, $client_public, 'drive'), array(
		'imc_display_name' => $crypto->sealFieldForBrowser('Keep Plain', $pdek, SealedScopePolicyProbe::sealAd($pid, 'imc_display_name')),
	));
});
check($e instanceof RuntimeException && strpos($e->getMessage(), 'shouldSeal') !== false,
	'a row whose policy keeps it plaintext refuses browser ciphertext', $e ? $e->getMessage() : 'accepted');
check(!ssm_flag(ssm_raw($pid)['imc_content_sealed']), 'and stays unsealed');

$srv_row = ssm_new('SealedScopeProbe', $owner_id, 'srv@example.com', 'Server Row', 'manual');
$e = ssm_throws(function () use ($srv_row, $sealed_dek, $fields) { SealedScopeProbe::acceptBrowserSealed((int)$srv_row->key, $sealed_dek, $fields); });
check($e instanceof RuntimeException, 'a row whose scope is the server scope refuses browser ciphertext');

// ---------------------------------------------------------------------------
section('A server-scope row on the same model is exactly server custody');
// ---------------------------------------------------------------------------
$raw_srv = ssm_raw((int)$srv_row->key);
check(strpos((string)$raw_srv['imc_sealed_key'], 'v1.seal.') === 0 && strpos((string)$raw_srv['imc_address'], 'v1.aead.') === 0,
	'it seals to libsodium formats');
$srv = new SealedScopeProbe((int)$srv_row->key, TRUE);
check($srv->get('imc_address') === 'srv@example.com', 'reads back in-window');
$edit = new SealedScopeProbe((int)$srv_row->key, TRUE);
$edit->set('imc_display_name', 'Server Renamed');
$edit->save();
$after = new SealedScopeProbe((int)$srv_row->key, TRUE);
check((string)ssm_raw((int)$srv_row->key)['imc_sealed_key'] === (string)$raw_srv['imc_sealed_key']
	&& $after->get('imc_display_name') === 'Server Renamed' && $after->get('imc_address') === 'srv@example.com',
	'a partial update reuses the DEK and leaves the other field readable');
$api_srv = $after->export_for_api();
check(!array_key_exists('sealed_scope', $api_srv) && ($api_srv['imc_address'] ?? null) === 'srv@example.com',
	'its export is plaintext in-window, with no browser keys');
VaultUnlock::lockAll($owner_id);
$e = ssm_throws(function () use ($srv_row) { (new SealedScopeProbe((int)$srv_row->key, TRUE))->get('imc_address'); });
check($e instanceof VaultLockedException, 'and with the window closed it is locked, not sealed-for-browser');

// ---------------------------------------------------------------------------
section('Scope resolution');
// ---------------------------------------------------------------------------
$stranger = make_user('SealedScopeNoClient');
$no_client = ssm_new('SealedScopeProbe', (int)$stranger->key, 'plain@example.com', 'Plain', 'fortress');
check((string)ssm_raw((int)$no_client->key)['imc_address'] === 'plain@example.com',
	'a client-scope row whose owner has no vault of that scope is stored in the clear');

$e = ssm_throws(function () use ($owner_id) { ssm_new('SealedScopeTypoProbe', $owner_id, 'typo@example.com', 'Typo', 'manual'); });
check($e !== null && strpos($e->getMessage(), 'drvie') !== false, 'an unregistered scope fails loudly on first use, naming it');

harness_finish();
?>
