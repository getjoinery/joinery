<?php
/** @joinery-test
 * name: drive_encryption
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../api/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
require_once(PathHelper::getIncludePath('data/file_key_grants_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(__DIR__ . '/../../lib/vault_fixtures.php'); // vault_fixture_client_vault()

$dblink = DbConnector::get_instance()->get_db_link();

$made_files = array(); $made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) { $dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid)); }
});

$owner  = make_user('drvenc_owner');
$friend = make_user('drvenc_friend');
$owner_pk  = base64_encode(random_bytes(32));
$friend_pk = base64_encode(random_bytes(32));
// Client-custody drive vaults so drive_public_keys can resolve each user's key.
vault_fixture_client_vault((int)$owner->key, $owner_pk, 'drive');
vault_fixture_client_vault((int)$friend->key, $friend_pk, 'drive');

// ---------------------------------------------------------------------------
section('encrypted folder + file model layer');

$vault = new Folder(NULL);
$vault->set('fol_usr_user_id', $owner->key);
$vault->set('fol_name', 'Vault_' . bin2hex(random_bytes(3)));
$vault->set('fol_protection_level', ProtectionLevel::FORTRESS);
$vault->save(); $vault->load();
$made_folders[] = $vault->key;
check(DriveHelper::folder_is_encrypted($vault), 'folder_is_encrypted true for a vault folder');
check(!empty(DriveHelper::folder_export($vault)['encrypted']), 'folder_export marks encrypted');

$plain_folder = new Folder(NULL);
$plain_folder->set('fol_usr_user_id', $owner->key);
$plain_folder->set('fol_name', 'Plain_' . bin2hex(random_bytes(3)));
$plain_folder->save(); $plain_folder->load();
$made_folders[] = $plain_folder->key;
check(!DriveHelper::folder_is_encrypted($plain_folder), 'folder_is_encrypted false for a plain folder');

$enc_meta = base64_encode(random_bytes(60)); // opaque metadata blob
$efile = File::createFromBytes('ciphertext-' . bin2hex(random_bytes(8)), 'enc-opaqueid', 'application/octet-stream', $owner->key, array(
	'fil_private' => true,
	'fil_source'  => File::SOURCE_DRIVE,
	'fil_protection_level' => ProtectionLevel::FORTRESS,
	'fil_encrypted_metadata' => $enc_meta,
));
$efile->set('fil_fol_folder_id', $vault->key); $efile->save();
$made_files[] = $efile->key;
$efile = new File($efile->key, true);

check($efile->is_encrypted(), 'is_encrypted() true');
check($efile->is_image() === false, 'is_image() false for an encrypted file (skip-list)');
check($efile->resize('all') === false, 'resize() is a no-op for an encrypted file (skip-list)');
check($efile->get('fil_encrypted_metadata') === $enc_meta, 'encrypted metadata stored verbatim');

// ---------------------------------------------------------------------------
section('FileKeyGrant round trips');

$wk_owner = base64_encode(random_bytes(80));
FileKeyGrant::put($efile->key, $owner->key, $wk_owner);
check(FileKeyGrant::wrapped_key_for($efile->key, $owner->key) === $wk_owner, 'owner wrapped key stored + read back');
check(FileKeyGrant::wrapped_key_for($efile->key, $friend->key) === null, 'friend holds no key yet');

$map = FileKeyGrant::wrapped_keys_for_user(array($efile->key), $owner->key);
check(isset($map[$efile->key]) && $map[$efile->key] === $wk_owner, 'wrapped_keys_for_user batch read');

// file_export exposes the encrypted payload + the caller's wrapped key
$export = DriveHelper::file_export($efile, null, false, $wk_owner);
check(!empty($export['encrypted']), 'file_export flags encrypted');
check($export['encrypted_metadata'] === $enc_meta, 'file_export carries encrypted_metadata');
check($export['wrapped_file_key'] === $wk_owner, 'file_export carries the wrapped file key');
check($export['is_image'] === false, 'file_export never treats an encrypted file as an image');

// sync: add friend, keep owner, then revoke friend — owner is never dropped
$wk_friend = base64_encode(random_bytes(80));
$newly = FileKeyGrant::sync_for_file($efile->key, array($owner->key => $wk_owner, $friend->key => $wk_friend), $owner->key);
check(in_array((int)$friend->key, array_map('intval', $newly), true), 'sync reports friend as newly granted');
check(FileKeyGrant::wrapped_key_for($efile->key, $friend->key) === $wk_friend, 'friend key present after sync');
check(count(FileKeyGrant::user_ids_for_file($efile->key)) === 2, 'two key grants after sync');

FileKeyGrant::sync_for_file($efile->key, array(), $owner->key); // revoke everyone in the set...
check(FileKeyGrant::wrapped_key_for($efile->key, $friend->key) === null, 'friend key revoked by empty sync');
check(FileKeyGrant::wrapped_key_for($efile->key, $owner->key) === $wk_owner, 'owner key preserved through an empty sync');

// cascade: deleting the file removes its key grants
$doomed = File::createFromBytes('cx-' . bin2hex(random_bytes(6)), 'enc-doomed', 'application/octet-stream', $owner->key, array(
	'fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_protection_level' => ProtectionLevel::FORTRESS,
));
FileKeyGrant::put($doomed->key, $owner->key, base64_encode(random_bytes(40)));
$doomed_id = $doomed->key;
$doomed->permanent_delete();
$cnt = (int)$dblink->query("SELECT COUNT(*) FROM fkg_file_key_grants WHERE fkg_fil_file_id=" . (int)$doomed_id)->fetchColumn();
check($cnt === 0, 'key grants cascade-deleted with the file');

// ---------------------------------------------------------------------------
section('API: drive_public_keys / drive_key_grants_sync / drive_key_grants');

$key = make_machine_key($owner->key, 'drvenc-' . bin2hex(random_bytes(3)));
$H = key_headers($key['api_key']->get('apk_public_key'), $key['secret_key']);

$pk = api_request('POST', '/api/v1/action/drive_public_keys', $H, array(
	'identifiers' => array((string)$owner->key, (string)$friend->key, 'nobody-' . bin2hex(random_bytes(4)) . '@example.com'),
));
check($pk['status'] === 200, 'drive_public_keys returns 200');
$keys = $pk['json']['data']['keys'] ?? array();
$byUser = array();
foreach ($keys as $k) { if (!empty($k['user_id'])) $byUser[(int)$k['user_id']] = $k['public_key']; }
check(($byUser[$owner->key] ?? null) === $owner_pk, 'owner public key resolved');
check(($byUser[$friend->key] ?? null) === $friend_pk, 'friend public key resolved');
$null_count = 0; foreach ($keys as $k) { if ($k['public_key'] === null) $null_count++; }
check($null_count === 1, 'unknown identifier resolves to a null key (no vault)');

$wk_friend2 = base64_encode(random_bytes(80));
$sync = api_request('POST', '/api/v1/action/drive_key_grants_sync', $H, array(
	'file_keys' => array((string)$efile->key => array((string)$friend->key => $wk_friend2)),
));
check($sync['status'] === 200 && ($sync['json']['data']['synced'] ?? 0) === 1, 'drive_key_grants_sync synced one file');
check(FileKeyGrant::wrapped_key_for($efile->key, $friend->key) === $wk_friend2, 'friend key set via API');
check(FileKeyGrant::wrapped_key_for($efile->key, $owner->key) === $wk_owner, 'owner key preserved via API sync');

$kg = api_request('POST', '/api/v1/action/drive_key_grants', $H, array('file_ids' => array($efile->key)));
check($kg['status'] === 200, 'drive_key_grants returns 200');
check(($kg['json']['data']['keys'][(string)$efile->key] ?? null) === $wk_owner, 'drive_key_grants returns the caller\'s own wrapped key');

// friend only ever sees their OWN key, never the owner's
$fkey = make_machine_key($friend->key, 'drvencf-' . bin2hex(random_bytes(3)));
$FH = key_headers($fkey['api_key']->get('apk_public_key'), $fkey['secret_key']);
$kgf = api_request('POST', '/api/v1/action/drive_key_grants', $FH, array('file_ids' => array($efile->key)));
check(($kgf['json']['data']['keys'][(string)$efile->key] ?? null) === $wk_friend2, 'friend sees their own key');

// a non-owner cannot sync key grants (owner-only)
$sync_bad = api_request('POST', '/api/v1/action/drive_key_grants_sync', $FH, array(
	'file_keys' => array((string)$efile->key => array((string)$friend->key => base64_encode(random_bytes(40)))),
));
$skipped = $sync_bad['json']['data']['skipped'] ?? array();
check(in_array((int)$efile->key, array_map('intval', $skipped), true), 'non-owner key-grant sync is skipped');

// ---------------------------------------------------------------------------
section('API: move encryption-boundary guard');

// encrypted file cannot move to the plaintext root
$mv1 = api_request('POST', '/api/v1/action/drive_move', $H, array(
	'entity_type' => 'file', 'entity_id' => $efile->key, 'parent_id' => 0,
));
check(!empty($mv1['json']['error']), 'encrypted file rejected moving to plaintext root');
check((int)(new File($efile->key, true))->get('fil_fol_folder_id') === (int)$vault->key, 'encrypted file stayed put');

// plaintext file cannot move into the vault
$pfile = File::createFromBytes('plain-' . bin2hex(random_bytes(6)), 'plain.txt', 'text/plain', $owner->key, array(
	'fil_private' => true, 'fil_source' => File::SOURCE_DRIVE,
));
$pfile->set('fil_fol_folder_id', $plain_folder->key); $pfile->save();
$made_files[] = $pfile->key;
$mv2 = api_request('POST', '/api/v1/action/drive_move', $H, array(
	'entity_type' => 'file', 'entity_id' => $pfile->key, 'parent_id' => $vault->key,
));
check(!empty($mv2['json']['error']), 'plaintext file rejected moving into a vault');

// ---------------------------------------------------------------------------
section('API: rename — encrypted files rename via their metadata');

// A plaintext name on an encrypted file would leak the secret name — refused.
$rn1 = api_request('POST', '/api/v1/action/drive_rename', $H, array(
	'entity_type' => 'file', 'entity_id' => $efile->key, 'name' => 'Leaky Tax Return.pdf',
));
check(!empty($rn1['json']['error']), 'plaintext rename of an encrypted file refused');
check((new File($efile->key, true))->get('fil_title') === 'enc-opaqueid', 'fil_title untouched by refused rename');

// The real path: the browser re-encrypts the metadata (same FK) and submits it.
$renamed_meta = base64_encode(random_bytes(64));
$rn2 = api_request('POST', '/api/v1/action/drive_rename', $H, array(
	'entity_type' => 'file', 'entity_id' => $efile->key, 'encrypted_metadata' => $renamed_meta,
));
check($rn2['status'] === 200 && empty($rn2['json']['error']), 'encrypted-metadata rename accepted');
$efile = new File($efile->key, true);
check($efile->get('fil_encrypted_metadata') === $renamed_meta, 'fil_encrypted_metadata updated by rename');
check($efile->get('fil_title') === 'enc-opaqueid', 'fil_title still the opaque value after rename');

// Plaintext files still rename by name (and require one).
$rn3 = api_request('POST', '/api/v1/action/drive_rename', $H, array(
	'entity_type' => 'file', 'entity_id' => $pfile->key, 'name' => 'renamed-plain.txt',
));
check($rn3['status'] === 200 && (new File($pfile->key, true))->get('fil_title') === 'renamed-plain.txt', 'plaintext rename still works');
$rn4 = api_request('POST', '/api/v1/action/drive_rename', $H, array(
	'entity_type' => 'file', 'entity_id' => $pfile->key,
));
check(!empty($rn4['json']['error']), 'plaintext rename without a name refused');

// ---------------------------------------------------------------------------
section('API: vault topology — a vault is a top-level tree');

// Encrypted folder under a plaintext parent: refused (matches the move rule).
$tp1 = api_request('POST', '/api/v1/action/drive_folder_create', $H, array(
	'name' => 'NestVault_' . bin2hex(random_bytes(3)), 'parent_id' => $plain_folder->key, 'encrypted' => true,
));
check(!empty($tp1['json']['error']), 'encrypted folder under a plaintext parent refused');

// Under a vault parent, encryption is inherited even without the flag.
$tp2 = api_request('POST', '/api/v1/action/drive_folder_create', $H, array(
	'name' => 'SubVault_' . bin2hex(random_bytes(3)), 'parent_id' => $vault->key,
));
$tp2_id = (int)($tp2['json']['data']['folder']['id'] ?? 0);
if ($tp2_id) { $made_folders[] = $tp2_id; }
check($tp2_id > 0 && !empty($tp2['json']['data']['folder']['encrypted']), 'subfolder of a vault inherits encrypted');

// At the root the opt-in works (already proven by the upload leg's folder).
$tp3 = api_request('POST', '/api/v1/action/drive_folder_create', $H, array(
	'name' => 'RootVault_' . bin2hex(random_bytes(3)), 'encrypted' => true,
));
$tp3_id = (int)($tp3['json']['data']['folder']['id'] ?? 0);
if ($tp3_id) { $made_folders[] = $tp3_id; }
check($tp3_id > 0 && !empty($tp3['json']['data']['folder']['encrypted']), 'encrypted folder at the root accepted');

// ---------------------------------------------------------------------------
section('API: drive_list offset paging');

$page_folder = new Folder(NULL);
$page_folder->set('fol_usr_user_id', $owner->key);
$page_folder->set('fol_name', 'Page_' . bin2hex(random_bytes(3)));
$page_folder->save(); $page_folder->load();
$made_folders[] = $page_folder->key;
foreach (array('a.txt', 'b.txt', 'c.txt') as $pn) {
	$pf = File::createFromBytes('pg-' . $pn . bin2hex(random_bytes(4)), $pn, 'text/plain', $owner->key, array(
		'fil_private' => true, 'fil_source' => File::SOURCE_DRIVE,
	));
	$pf->set('fil_fol_folder_id', $page_folder->key); $pf->save();
	$made_files[] = $pf->key;
}
$pg0 = api_request('POST', '/api/v1/action/drive_list', $H, array('view' => 'mine', 'folder_id' => $page_folder->key));
$pg1 = api_request('POST', '/api/v1/action/drive_list', $H, array('view' => 'mine', 'folder_id' => $page_folder->key, 'offset' => 1));
$pg0_items = $pg0['json']['data']['items'] ?? array();
$pg1_items = $pg1['json']['data']['items'] ?? array();
check(count($pg0_items) === 3 && count($pg1_items) === 2, 'offset=1 skips exactly one child');
check(($pg1_items[0]['name'] ?? '') === ($pg0_items[1]['name'] ?? '-') , 'offset preserves the deterministic ordering');
$pg9 = api_request('POST', '/api/v1/action/drive_list', $H, array('view' => 'mine', 'folder_id' => $page_folder->key, 'offset' => 9));
check(count($pg9['json']['data']['items'] ?? array('x')) === 0 && empty($pg9['json']['data']['truncated']), 'offset past the end returns empty, not truncated');

// ---------------------------------------------------------------------------
section('API: end-to-end encrypted upload pipeline (init → chunk → complete)');

$grp_id = $dblink->query("SELECT sbt_grp_group_id FROM sbt_subscription_tiers WHERE (sbt_features->>'drive_storage_bytes')::bigint > 0 AND sbt_delete_time IS NULL LIMIT 1")->fetchColumn();
if (!$grp_id) {
	harness_skip('encrypted upload leg', 'no drive-quota tier configured');
} else {
	$ins = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
	$ins->execute(array((int)$grp_id, (int)$owner->key));
	harness_register_row('grm_group_members', 'grm_group_member_id', $ins->fetchColumn());

	// Encrypted vault folder via the action (proves the descriptor path).
	$mkfolder = api_request('POST', '/api/v1/action/drive_folder_create', $H, array('name' => 'EncUp_' . bin2hex(random_bytes(3)), 'encrypted' => true));
	$efolder_id = (int)($mkfolder['json']['data']['folder']['id'] ?? 0);
	if ($efolder_id) { $made_folders[] = $efolder_id; }
	check($efolder_id > 0 && !empty($mkfolder['json']['data']['folder']['encrypted']), 'drive_folder_create makes an encrypted folder');

	// Fake ciphertext (the server never interprets it). Opaque payloads mirror
	// what the browser would send.
	$cipher = random_bytes(4096);
	$meta_blob = base64_encode(random_bytes(48));
	$wrapped_up = base64_encode(random_bytes(80));
	$thumb_b64 = base64_encode(random_bytes(200));

	$init = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
		'name' => 'enc-' . bin2hex(random_bytes(8)), 'size_bytes' => strlen($cipher),
		'mime_type' => 'application/octet-stream', 'folder_id' => $efolder_id,
	));
	$token = $init['json']['data']['upload_token'] ?? '';
	check($token !== '' && empty($init['json']['data']['deduped']), 'encrypted upload opened (no dedup short-circuit)');

	$put = harness_put_chunk('/api/v1/drive_upload/' . $token, $H, 'bytes 0-' . (strlen($cipher) - 1) . '/' . strlen($cipher), $cipher);
	check($put['status'] === 200, 'ciphertext chunk accepted');

	$complete = api_request('POST', '/api/v1/action/drive_upload_complete', $H, array(
		'upload_token' => $token,
		'encrypted_metadata' => $meta_blob,
		'wrapped_file_keys' => array((string)$owner->key => $wrapped_up),
		'encrypted_thumbnail' => $thumb_b64,
	));
	$new_file = $complete['json']['data']['file'] ?? null;
	$new_id = (int)($new_file['id'] ?? 0);
	if ($new_id) { $made_files[] = $new_id; }
	check($new_id > 0 && !empty($new_file['encrypted']), 'upload_complete created an encrypted file');
	check(($new_file['encrypted_metadata'] ?? null) === $meta_blob, 'metadata returned in the file export');
	check(($new_file['wrapped_file_key'] ?? null) === $wrapped_up, 'wrapped file key returned in the file export');

	$stored = new File($new_id, true);
	check($stored->is_encrypted(), 'stored file inherits the folder protection level');
	check($stored->get('fil_encrypted_metadata') === $meta_blob, 'stored metadata matches');
	check(FileKeyGrant::wrapped_key_for($new_id, $owner->key) === $wrapped_up, 'owner FileKeyGrant created at complete');

	// The encrypted thumbnail landed in the blob thumb variant slot (when a thumb
	// size is configured); otherwise the pipeline correctly stores none.
	$thumb_key = DriveHelper::thumb_size_key();
	if ($thumb_key !== null) {
		$blob = new FileBlob((int)$stored->get('fil_fbb_file_blob_id'), true);
		$variant = $blob->read_bytes($thumb_key);
		check($variant !== null && base64_encode($variant) === $thumb_b64, 'encrypted thumbnail stored in the thumb variant slot');
	} else {
		harness_skip('encrypted thumbnail stored in the thumb variant slot',
			'no image size configured on this install');
	}

	// -----------------------------------------------------------------------
	section('blob variant inventory (encrypted thumbnail lifecycle)');

	if ($thumb_key !== null) {
		$blob = new FileBlob((int)$stored->get('fil_fbb_file_blob_id'), true);
		check((string)$blob->get('fbb_encrypted_variant_key') === (string)$thumb_key, 'store_encrypted_variant recorded its size key on the row');
		check(in_array($thumb_key, $blob->variant_size_keys(), true), 'variant_size_keys surfaces the encrypted thumb slot');
		require_once(PathHelper::getIncludePath('includes/cloud_storage/BlobStorageProfile.php'));
		$profile = new BlobStorageProfile();
		$listed = array();
		foreach ((array)$profile->itemsForRow((int)$blob->key) as $item) { $listed[] = $item['remote_key']; }
		check(in_array($blob->remote_key_for($thumb_key), $listed, true), 'offload enumerator lists the encrypted thumb variant');
	} else {
		harness_skip('blob variant inventory (encrypted thumbnail lifecycle)',
			'no image size configured on this install');
	}

	// -----------------------------------------------------------------------
	section('API: wrapped_file_keys validation (reader-set sealing)');

	/** Open an upload and push one full chunk; returns the token ('' on failure). */
	function enc_open_upload(array $key_headers, $folder_id, $cipher, $name = null) {
		$init = api_request('POST', '/api/v1/action/drive_upload_init', $key_headers, array(
			'name' => $name !== null ? $name : ('enc-' . bin2hex(random_bytes(8))),
			'size_bytes' => strlen($cipher), 'mime_type' => 'application/octet-stream', 'folder_id' => $folder_id,
		));
		$token = $init['json']['data']['upload_token'] ?? '';
		if ($token === '') { return ''; }
		$put = harness_put_chunk('/api/v1/drive_upload/' . $token, $key_headers, 'bytes 0-' . (strlen($cipher) - 1) . '/' . strlen($cipher), $cipher);
		return ($put['status'] === 200) ? $token : '';
	}

	// Missing the folder owner's entry → refused (a vault file the owner can
	// never read must not be creatable).
	$stranger = make_user('drvenc_stranger');
	$t1 = enc_open_upload($H, $efolder_id, random_bytes(512));
	$c1 = api_request('POST', '/api/v1/action/drive_upload_complete', $H, array(
		'upload_token' => $t1, 'encrypted_metadata' => base64_encode(random_bytes(40)),
		'wrapped_file_keys' => array((string)$friend->key => base64_encode(random_bytes(80))),
	));
	check(!empty($c1['json']['error']), 'wrapped_file_keys without the owner entry refused');

	// An entry for a user with no access to the destination → refused.
	$t2 = enc_open_upload($H, $efolder_id, random_bytes(512));
	$c2 = api_request('POST', '/api/v1/action/drive_upload_complete', $H, array(
		'upload_token' => $t2, 'encrypted_metadata' => base64_encode(random_bytes(40)),
		'wrapped_file_keys' => array(
			(string)$owner->key    => base64_encode(random_bytes(80)),
			(string)$stranger->key => base64_encode(random_bytes(80)),
		),
	));
	check(!empty($c2['json']['error']), 'wrapped key for a user without access refused');

	// The legacy singular param no longer creates a readable file.
	$t3 = enc_open_upload($H, $efolder_id, random_bytes(512));
	$c3 = api_request('POST', '/api/v1/action/drive_upload_complete', $H, array(
		'upload_token' => $t3, 'encrypted_metadata' => base64_encode(random_bytes(40)),
		'wrapped_file_key' => base64_encode(random_bytes(80)),
	));
	check(!empty($c3['json']['error']), 'legacy singular wrapped_file_key refused for a new file');

	// -----------------------------------------------------------------------
	section('API: grantee upload into a shared vault (both readers get keys)');

	require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
	FileAccessGrant::sync_for_entity(DriveHelper::ENTITY_FOLDER, $efolder_id, array($friend->key => 'editor'), $owner->key);

	// The reader-set mode resolves owner + grantee for anyone with write access.
	$pkf = api_request('POST', '/api/v1/action/drive_public_keys', $FH, array('folder_id' => $efolder_id));
	$reader_keys = array();
	foreach (($pkf['json']['data']['keys'] ?? array()) as $k) { if (!empty($k['user_id'])) $reader_keys[(int)$k['user_id']] = $k['public_key']; }
	check(($reader_keys[$owner->key] ?? null) === $owner_pk && ($reader_keys[$friend->key] ?? null) === $friend_pk, 'drive_public_keys folder mode returns the full reader set');
	$skey = make_machine_key($stranger->key, 'drvencs-' . bin2hex(random_bytes(3)));
	$SH = key_headers($skey['api_key']->get('apk_public_key'), $skey['secret_key']);
	$pkx = api_request('POST', '/api/v1/action/drive_public_keys', $SH, array('folder_id' => $efolder_id));
	check(!empty($pkx['json']['error']), 'folder mode refused without write access');

	// Friend (editor) uploads into the owner's vault, sealing to both readers.
	$wk_up_owner  = base64_encode(random_bytes(80));
	$wk_up_friend = base64_encode(random_bytes(80));
	$t4 = enc_open_upload($FH, $efolder_id, random_bytes(768));
	$c4 = api_request('POST', '/api/v1/action/drive_upload_complete', $FH, array(
		'upload_token' => $t4, 'encrypted_metadata' => base64_encode(random_bytes(40)),
		'wrapped_file_keys' => array((string)$owner->key => $wk_up_owner, (string)$friend->key => $wk_up_friend),
	));
	$gfile = $c4['json']['data']['file'] ?? null;
	$gfile_id = (int)($gfile['id'] ?? 0);
	if ($gfile_id) { $made_files[] = $gfile_id; }
	check($gfile_id > 0 && !empty($gfile['encrypted']), 'grantee upload into a shared vault succeeds');
	check(FileKeyGrant::wrapped_key_for($gfile_id, $owner->key) === $wk_up_owner, 'owner holds a key they can open');
	check(FileKeyGrant::wrapped_key_for($gfile_id, $friend->key) === $wk_up_friend, 'uploader holds their own key');
	check(($gfile['wrapped_file_key'] ?? null) === $wk_up_friend, 'export returns the UPLOADER\'s wrapped key');
	check((int)(new File($gfile_id, true))->get('fil_usr_user_id') === (int)$owner->key, 'the file belongs to the folder owner (single-owner tree)');

	// -----------------------------------------------------------------------
	section('API: encrypted new-version uploads reuse the file key');

	// Any wrapped-key payload on a version upload means a fresh FK → refused.
	$vinit = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
		'file_id' => $new_id, 'size_bytes' => 600, 'mime_type' => 'application/octet-stream',
	));
	$vtoken = $vinit['json']['data']['upload_token'] ?? '';
	harness_put_chunk('/api/v1/drive_upload/' . $vtoken, $H, 'bytes 0-599/600', random_bytes(600));
	$vc1 = api_request('POST', '/api/v1/action/drive_upload_complete', $H, array(
		'upload_token' => $vtoken, 'encrypted_metadata' => base64_encode(random_bytes(40)),
		'wrapped_file_keys' => array((string)$owner->key => base64_encode(random_bytes(80))),
	));
	check(!empty($vc1['json']['error']), 'version upload with a wrapped-key payload refused');

	// An uploader with write access but NO key grant cannot have reused the FK.
	// The friend has editor reach on $new_id's folder but no grant on the file.
	$vinit2 = api_request('POST', '/api/v1/action/drive_upload_init', $FH, array(
		'file_id' => $new_id, 'size_bytes' => 600, 'mime_type' => 'application/octet-stream',
	));
	$vtoken2 = $vinit2['json']['data']['upload_token'] ?? '';
	harness_put_chunk('/api/v1/drive_upload/' . $vtoken2, $FH, 'bytes 0-599/600', random_bytes(600));
	$vc2 = api_request('POST', '/api/v1/action/drive_upload_complete', $FH, array(
		'upload_token' => $vtoken2, 'encrypted_metadata' => base64_encode(random_bytes(40)),
	));
	check(!empty($vc2['json']['error']), 'version upload by a user with no key grant refused');

	// The owner (key-grant holder), no key payload: accepted; grants untouched.
	$v2meta = base64_encode(random_bytes(52));
	$vinit3 = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
		'file_id' => $new_id, 'size_bytes' => 600, 'mime_type' => 'application/octet-stream',
	));
	$vtoken3 = $vinit3['json']['data']['upload_token'] ?? '';
	harness_put_chunk('/api/v1/drive_upload/' . $vtoken3, $H, 'bytes 0-599/600', random_bytes(600));
	$vc3 = api_request('POST', '/api/v1/action/drive_upload_complete', $H, array(
		'upload_token' => $vtoken3, 'encrypted_metadata' => $v2meta,
	));
	check($vc3['status'] === 200 && empty($vc3['json']['error']), 'key-grant holder version upload accepted');
	check((new File($new_id, true))->get('fil_encrypted_metadata') === $v2meta, 'head metadata follows the new version');
	check(FileKeyGrant::wrapped_key_for($new_id, $owner->key) === $wrapped_up, 'the original key grant is untouched by the new version');

	// -----------------------------------------------------------------------
	section('API: per-file size cap — ciphertext ceiling for vault uploads');

	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	$max_file = (int)SubscriptionTier::getUserFeature($owner->key, 'drive_max_file_bytes', 0);
	$quota_b  = (int)SubscriptionTier::getUserFeature($owner->key, 'drive_storage_bytes', 0);
	require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
	$used_now = DriveUsage::recompute($owner->key);
	$ceiling  = DriveHelper::encrypted_size_ceiling($max_file);
	check($ceiling > $max_file && $ceiling === $max_file + 32 * max(1, (int)ceil($max_file / (4 * 1024 * 1024))), 'encrypted_size_ceiling adds exactly 32 bytes per 4 MiB chunk');
	if ($max_file > 0 && $used_now + $ceiling <= $quota_b) {
		// Just over the plaintext cap: refused for a plaintext destination…
		$sz = $max_file + 1;
		$si1 = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
			'name' => 'big.bin', 'size_bytes' => $sz, 'folder_id' => $plain_folder->key,
		));
		check(!empty($si1['json']['error']), 'plaintext destination still enforces the raw cap');
		// …but accepted into a vault (it is ciphertext overhead, not more content).
		$si2 = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
			'name' => 'enc-bigone', 'size_bytes' => $sz, 'folder_id' => $efolder_id,
		));
		$si2_token = $si2['json']['data']['upload_token'] ?? '';
		check($si2_token !== '', 'vault destination allows the ciphertext overhead');
		if ($si2_token !== '') {
			$dblink->prepare("DELETE FROM fup_file_uploads WHERE fup_token_sha256 = ?")->execute(array(hash('sha256', $si2_token)));
		}
		// Past the ceiling is past the ceiling.
		$si3 = api_request('POST', '/api/v1/action/drive_upload_init', $H, array(
			'name' => 'enc-toobig', 'size_bytes' => $ceiling + 1, 'folder_id' => $efolder_id,
		));
		check(!empty($si3['json']['error']), 'vault destination refuses past the ciphertext ceiling');
	} else {
		harness_skip('vault destination size-cap API leg',
			'tier cap exceeds remaining quota on this install');
	}

	// Clear the share grant so teardown leaves no orphan rows.
	FileAccessGrant::sync_for_entity(DriveHelper::ENTITY_FOLDER, $efolder_id, array(), $owner->key);
}

harness_finish();
?>
