<?php
/** @joinery-test
 * name: drive_private_tier
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
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('includes/DriveSealed.php'));
require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
require_once(PathHelper::getIncludePath('includes/SealedFileContainer.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(__DIR__ . '/../../lib/vault_fixtures.php');

$dblink = DbConnector::get_instance()->get_db_link();

$made_files = array(); $made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) {
		$dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid));
	}
});

$owner = make_user('drvpriv_owner');
$friend = make_user('drvpriv_friend');

/**
 * A SERVER-custody vault (scope 'user' — the one Private Drive files seal to)
 * with a keypair this test holds, so it can open a window and read back exactly
 * what the product would read.
 */
$kp = (new SealedBox())->generateKeypair();
$ins = $dblink->prepare(
	"INSERT INTO uev_user_encryption_vaults (uev_usr_user_id, uev_scope, uev_custody, uev_public_key, uev_salt, uev_key_generation)
	 VALUES (?, 'user', 'server', ?, ?, 1) RETURNING uev_user_encryption_vault_id");
$ins->execute(array((int)$owner->key, $kp['public'], base64_encode(random_bytes(16))));
$vault_id = (int)$ins->fetchColumn();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', $vault_id);

$window_ok = vault_apcu_usable() && vault_ensure_session();

/** Open the owner's window for the in-process reads. */
function drvpriv_unlock($owner_id, $secret) {
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	VaultUnlock::open((int)$owner_id, $secret, 'user', array('idle' => null, 'absolute' => null));
}
function drvpriv_lock($owner_id) {
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	VaultUnlock::close((int)$owner_id, 'user');
}

$secret = $kp['secret']; // VaultUnlock holds the b64url form — SealedBox::openDek decodes it

/** A Private folder owned by $owner. */
function drvpriv_folder($owner_id, $level, &$made_folders, $parent = null) {
	$f = new Folder(NULL);
	$f->set('fol_usr_user_id', $owner_id);
	$f->set('fol_name', 'P_' . bin2hex(random_bytes(4)));
	$f->set('fol_protection_level', $level);
	if ($parent) { $f->set('fol_parent_folder_id', $parent); }
	$f->save(); $f->load();
	$made_folders[] = $f->key;
	return $f;
}

// ---------------------------------------------------------------------------
section('the ladder');

check(ProtectionLevel::rank('standard') < ProtectionLevel::rank('private'), 'private outranks standard');
check(ProtectionLevel::rank('private') < ProtectionLevel::rank('fortress'), 'fortress outranks private');
check(ProtectionLevel::normalize('nonsense') === 'standard', 'an unrecognized level reads as standard');
check(ProtectionLevel::normalize(null) === 'standard', 'a null level reads as standard');
check(ProtectionLevel::isAtLeast('fortress', 'private'), 'fortress satisfies a private floor');
check(!ProtectionLevel::isAtLeast('standard', 'private'), 'standard does not satisfy a private floor');

// ---------------------------------------------------------------------------
section('sealing a file at rest');

$plain = str_repeat('The quick brown fox. ', 900); // ~19 KB, one chunk
$src = tempnam(sys_get_temp_dir(), 'drvpriv_');
file_put_contents($src, $plain);

$pfolder = drvpriv_folder($owner->key, ProtectionLevel::PRIVATE_, $made_folders);

// Sealing uses only the PUBLIC key — this runs with no window open at all.
drvpriv_lock($owner->key);
$file = DriveSealed::createSealedFile($src, 'notes.txt', 'text/plain', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_fol_folder_id' => $pfolder->key));
$made_files[] = $file->key;
@unlink($src);

check($file->key > 0, 'a file was created with the vault locked — sealing needs only the public key');
check($file->is_sealed(), 'the file records the private level');
check(!$file->is_encrypted(), 'a sealed file is NOT is_encrypted() — that word stays Fortress-only');
check($file->get('fil_type') === 'text/plain', 'the type was sniffed from the plaintext, not the container');
check((int)$file->get('fil_plain_size_bytes') === strlen($plain), 'the plaintext size is recorded on the row');
check((string)$file->get('fil_sealed_key') !== '', 'the row carries a wrapped key');
check((int)$file->get('fil_sealed_owner_user_id') === (int)$owner->key, 'the wrapping records whose vault it belongs to');
check((int)$file->get('fil_key_generation') === 1, 'the wrapping records the vault key generation');

$on_disk = $file->get_filesystem_path('original');
$stored = file_get_contents($on_disk);
check(strpos($stored, 'The quick brown fox') === false, 'the plaintext is NOT on disk');
check(SealedFileContainer::looksSealed($on_disk), 'the stored bytes are a sealed container');
check($file->size_bytes() > strlen($plain), 'the blob measures ciphertext (quota is charged on that)');
check($file->plain_size_bytes() === strlen($plain), 'the member is shown plaintext bytes');
check($file->is_image() === false, 'a sealed file is not a server-decodable image');

$export = DriveHelper::file_export($file, null, false, null, false);
check(($export['protection_level'] ?? '') === 'private', 'the export names the level');
check(($export['encrypted'] ?? true) === false, 'the export does not claim client custody');
check(($export['syncable'] ?? true) === false, 'a sealed file is excluded from sync');
check((int)($export['size'] ?? 0) === strlen($plain), 'the export shows the plaintext size');
check(($export['name'] ?? '') === 'notes.txt', 'the name stays plaintext (P1)');

// ---------------------------------------------------------------------------
section('reading it back');

if (!$window_ok) {
	harness_skip('in-window reads', 'APCu is unavailable in this process — run through tests/run.php');
} else {
	drvpriv_lock($owner->key);
	$threw = false;
	try { DriveSealed::fileKey($file); } catch (VaultLockedException $e) { $threw = true; }
	check($threw, 'reading the key with the vault locked raises VaultLockedException');

	drvpriv_unlock($owner->key, $secret);
	SealedEgressGuard::reset();
	check(!SealedEgressGuard::isHot(), 'the process starts cold');

	$got = '';
	DriveSealed::openTo($file, function ($b) use (&$got) { $got .= $b; });
	check($got === $plain, 'in-window read returns the exact plaintext');
	check(SealedEgressGuard::isHot(), 'opening a sealed file arms the hot-turn rule');

	// The serve path's decryptor, exercised the way serve_from_path drives it.
	$streamer = new DriveSealedStream($file);
	$streamer->prepare($on_disk);
	check($streamer->plainSize($on_disk) === strlen($plain), 'the decryptor reports the plaintext length');
	$span = '';
	$streamer->stream($on_disk, function ($b) use (&$span) { $span .= $b; }, 100, 250);
	check($span === substr($plain, 100, 250), 'the decryptor answers a byte range against PLAINTEXT offsets');

	// A range that crosses a chunk seam, on a file big enough to have one.
	$big_plain = random_bytes(SealedFileContainer::CHUNK_BYTES + 5000);
	$big_src = tempnam(sys_get_temp_dir(), 'drvpriv_big_');
	file_put_contents($big_src, $big_plain);
	$big = DriveSealed::createSealedFile($big_src, 'big.bin', 'application/octet-stream', (int)$owner->key,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_fol_folder_id' => $pfolder->key));
	$made_files[] = $big->key;
	@unlink($big_src);

	$seam = '';
	$offset = SealedFileContainer::CHUNK_BYTES - 20;
	DriveSealed::openTo($big, function ($b) use (&$seam) { $seam .= $b; }, $offset, 60);
	check($seam === substr($big_plain, $offset, 60), 'a range across a chunk seam decrypts correctly');

	$tail = '';
	DriveSealed::openTo($big, function ($b) use (&$tail) { $tail .= $b; }, strlen($big_plain) - 100, 100);
	check($tail === substr($big_plain, -100), 'a range at the tail decrypts correctly');
	unset($big_plain);

	// Another user's window must not open this file.
	drvpriv_lock($owner->key);
	drvpriv_unlock($friend->key, $secret);
	$threw = false;
	try { DriveSealed::fileKey($file); } catch (VaultLockedException $e) { $threw = true; }
	check($threw, 'a different user\'s open window does not open the owner\'s file');
	drvpriv_lock($friend->key);
}

// ---------------------------------------------------------------------------
section('serving a sealed file while locked');

// The web process has no window of its own, so a signed URL must come back 423
// rather than ciphertext or a truncated body.
$signed = $file->mintSignedUrl('original', 300, 'long');
$url = (string)$signed;
if ($url === '') {
	harness_skip('signed-URL serve', 'no signed URL minted');
} else {
	$path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
	$res = api_request('GET', $path, array());
	check((int)$res['status'] === 423, 'a locked sealed file serves 423, not bytes (got ' . $res['status'] . ')');
	check(strpos((string)$res['body'], 'The quick brown fox') === false, 'no plaintext leaked in the 423 body');
}

// ---------------------------------------------------------------------------
section('raising and lowering');

$sfolder = drvpriv_folder($owner->key, ProtectionLevel::STANDARD, $made_folders);
$body = str_repeat('raise me please. ', 500);
$rfile = File::createFromBytes($body, 'raise.txt', 'text/plain', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$rfile->set('fil_fol_folder_id', $sfolder->key); $rfile->save(); $rfile->load();
$made_files[] = $rfile->key;
$blob_id_before = (int)$rfile->get('fil_fbb_file_blob_id');

check(!$rfile->is_sealed(), 'the file starts Standard');
$backlog = DriveSealed::transitionBacklog($sfolder->key, ProtectionLevel::PRIVATE_);
check($backlog['files'] === 1, 'the backlog counts the file that is not yet at the target level');

// Raising needs no window.
drvpriv_lock($owner->key);
$sealed_bytes = DriveSealed::sealExistingFile($rfile);
check($sealed_bytes === strlen($body), 'the raise reports the plaintext bytes it sealed');
$rfile = new File($rfile->key, true);
check($rfile->is_sealed(), 'the raised file is now Private');
check((int)$rfile->get('fil_plain_size_bytes') === strlen($body), 'the raise records the plaintext size');
check((string)$rfile->get('fil_sealed_key') !== '', 'the raise recorded a key wrapping');
$raised_disk = file_get_contents($rfile->get_filesystem_path('original'));
check(strpos($raised_disk, 'raise me please') === false, 'the plaintext is gone from disk after the raise');
check(SealedFileContainer::looksSealed($rfile->get_filesystem_path('original')), 'the raised bytes are a container');

check(DriveSealed::transitionBacklog($sfolder->key, ProtectionLevel::PRIVATE_)['files'] === 0,
	'the backlog is empty once the file is converted');
check(DriveSealed::sealExistingFile($rfile) === 0, 're-running the raise on a sealed file is a no-op (batches resume safely)');

if (!$window_ok) {
	harness_skip('lowering', 'APCu is unavailable in this process');
} else {
	drvpriv_lock($owner->key);
	$threw = false;
	try { DriveSealed::unsealExistingFile($rfile); } catch (VaultLockedException $e) { $threw = true; }
	check($threw, 'lowering refuses without the owner\'s window — decrypting needs the secret');

	drvpriv_unlock($owner->key, $secret);
	$restored = DriveSealed::unsealExistingFile($rfile);
	check($restored === strlen($body), 'the lower reports the plaintext bytes it restored');
	$rfile = new File($rfile->key, true);
	check(!$rfile->is_sealed() && $rfile->protection_level() === 'standard', 'the lowered file is Standard again');
	check(file_get_contents($rfile->get_filesystem_path('original')) === $body, 'the original bytes came back exactly');
	check((string)$rfile->get('fil_sealed_key') === '', 'the wrapping is dropped with the seal');
	check((int)$rfile->get('fil_key_generation') === 0, 'the generation resets so the rotation sweep skips it');
	drvpriv_lock($owner->key);
}

// ---------------------------------------------------------------------------
section('copy-on-write: a raise never rewrites bytes another file shares');

$shared_body = 'shared-' . bin2hex(random_bytes(16));
$twin_a = File::createFromBytes($shared_body, 'twin_a.txt', 'text/plain', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$twin_b = File::createFromBytes($shared_body, 'twin_b.txt', 'text/plain', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$made_files[] = $twin_a->key; $made_files[] = $twin_b->key;

$blob_a = (int)$twin_a->get('fil_fbb_file_blob_id');
$blob_b = (int)$twin_b->get('fil_fbb_file_blob_id');
if ($blob_a !== $blob_b) {
	harness_skip('copy-on-write raise', 'identical bytes did not dedup onto one blob');
} else {
	$twin_a->set('fil_fol_folder_id', $pfolder->key); $twin_a->save();
	DriveSealed::sealExistingFile($twin_a);
	$twin_a = new File($twin_a->key, true);
	$twin_b = new File($twin_b->key, true);
	check((int)$twin_a->get('fil_fbb_file_blob_id') !== $blob_a, 'the sealed twin was split onto its own blob');
	check((int)$twin_b->get('fil_fbb_file_blob_id') === $blob_b, 'the untouched twin kept the shared blob');
	check($twin_b->read_bytes() === $shared_body, 'the untouched twin still reads its plaintext');
	check(!$twin_b->is_sealed(), 'the untouched twin is still Standard');
}

// ---------------------------------------------------------------------------
section('rotation re-wraps exactly the generation being drained');

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
$callbacks = VaultUnlock::resealCallbacks();
check(count($callbacks) > 0, 'a reseal callback is registered (core consumer bootstrap loaded)');

$new_kp = (new SealedBox())->generateKeypair();
$before = (string)(new File($file->key, true))->get('fil_sealed_key');

// A file at a DIFFERENT generation must be left alone.
$dblink->prepare("UPDATE fil_files SET fil_key_generation = 7 WHERE fil_file_id = ?")->execute(array((int)$big->key ?? 0));
$other_before = (string)(new File((int)($big->key ?? 0), true))->get('fil_sealed_key');

foreach ($callbacks as $cb) {
	$cb((int)$owner->key, $secret, 1, $new_kp['public'], 2);
}

$after_row = new File($file->key, true);
check((string)$after_row->get('fil_sealed_key') !== $before, 'the wrapping was re-sealed');
check((int)$after_row->get('fil_key_generation') === 2, 'the generation moved to the new one');
check((string)(new File((int)($big->key ?? 0), true))->get('fil_sealed_key') === $other_before,
	'a file at another generation was left untouched');

// The re-wrapped key still opens the same bytes under the NEW secret.
if ($window_ok) {
	drvpriv_unlock($owner->key, $new_kp['secret']);
	$after_bytes = '';
	DriveSealed::openTo($after_row, function ($b) use (&$after_bytes) { $after_bytes .= $b; });
	check($after_bytes === $plain, 'the file opens under the rotated key — content was never re-encrypted');
	drvpriv_lock($owner->key);
}

// ---------------------------------------------------------------------------
section('what Private refuses');

$key = make_machine_key($owner->key, 'drvpriv-' . bin2hex(random_bytes(3)));
$H = key_headers($key['api_key']->get('apk_public_key'), $key['secret_key']);

// Put the owner on a tier that DOES grant share links, so the refusal below is
// the protection level talking and not the plan gate in front of it.
$grp_id = $dblink->query("SELECT sbt_grp_group_id FROM sbt_subscription_tiers
	WHERE (sbt_features->>'drive_share_links')::boolean = true AND sbt_delete_time IS NULL LIMIT 1")->fetchColumn();
if (!$grp_id) {
	harness_skip('public-link refusal', 'no tier grants drive_share_links');
} else {
	$ins2 = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
	$ins2->execute(array((int)$grp_id, (int)$owner->key));
	harness_register_row('grm_group_members', 'grm_group_member_id', $ins2->fetchColumn());

	// Proof the gate in front is open: a Standard file in the same account CAN
	// be linked. Without this the refusal below proves nothing.
	$linkable = File::createFromBytes('linkable-' . bin2hex(random_bytes(6)), 'linkable.txt', 'text/plain', (int)$owner->key,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$linkable->set('fil_fol_folder_id', $sfolder->key); $linkable->save();
	$made_files[] = $linkable->key;
	$ok_link = api_request('POST', '/api/v1/action/drive_link_create', $H, array(
		'entity_type' => 'file', 'entity_id' => (int)$linkable->key,
	));
	check(empty($ok_link['json']['error']), 'a Standard file can be linked (the plan gate is open)');

	$link = api_request('POST', '/api/v1/action/drive_link_create', $H, array(
		'entity_type' => 'file', 'entity_id' => (int)$file->key,
	));
	check(!empty($link['json']['error']), 'a public link on a Private file is refused');
	check(stripos((string)($link['json']['error'] ?? ''), 'private') !== false, 'the refusal names the level');
}

$linkf = api_request('POST', '/api/v1/action/drive_link_create', $H, array(
	'entity_type' => 'folder', 'entity_id' => (int)$pfolder->key,
));
check(!empty($linkf['json']['error']), 'a public link on a Private folder is refused');

$share = api_request('POST', '/api/v1/action/drive_share_sync', $H, array(
	'entity_type' => 'folder', 'entity_id' => (int)$pfolder->key,
	'grants' => array((string)$friend->key => 'viewer'),
));
check(!empty($share['json']['error']), 'sharing a Private folder with a member is refused (v1)');

$grants = (int)$dblink->query("SELECT COUNT(*) FROM fga_file_access_grants
	WHERE fga_entity_type='folder' AND fga_entity_id=" . (int)$pfolder->key)->fetchColumn();
check($grants === 0, 'the refused share created no grant');

// ---------------------------------------------------------------------------
section('a caller with no session is told, not handed dead links');

// An API key carries no session cookie, so it can never present the unlock
// window a sealed file's bytes need. Minting URLs for it would produce a listing
// where every Private thumbnail is broken and every download 423s.
// A sealed IMAGE, so the thumbnail assertion below is about a file that would
// otherwise carry a thumb_url — a broken tile is the most visible symptom of
// handing out links a sessionless caller cannot follow.
$tile_src = sys_get_temp_dir() . '/drvpriv_tile_' . bin2hex(random_bytes(4)) . '.png';
$tile_im = imagecreatetruecolor(64, 64);
imagefilledrectangle($tile_im, 0, 0, 63, 63, imagecolorallocate($tile_im, 200, 60, 40));
imagepng($tile_im, $tile_src);
$tile = DriveSealed::createSealedFile($tile_src, 'tile.png', 'image/png', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_fol_folder_id' => $pfolder->key));
$made_files[] = $tile->key;
@unlink($tile_src);

$listed = api_request('POST', '/api/v1/action/drive_list', $H, array('folder_id' => (int)$pfolder->key));
$rows = $listed['json']['data']['items'] ?? array();
$sealed_row = null;
foreach ($rows as $row) {
	if ((int)($row['id'] ?? 0) === (int)$file->key) { $sealed_row = $row; break; }
}
check($sealed_row !== null, 'the sealed file is listed for a key-authenticated caller');
if ($sealed_row !== null) {
	check(($sealed_row['requires_window'] ?? false) === true,
		'the export says the content needs an unlock window');
	check(($sealed_row['download_url'] ?? null) === null,
		'no download URL is minted for a caller that cannot carry a session');
	check(($sealed_row['name'] ?? '') === 'notes.txt' && (int)($sealed_row['size'] ?? 0) === strlen($plain),
		'the listing itself still works — names and sizes are plaintext at Private');
}

$tile_row = null;
foreach ($rows as $row) {
	if ((int)($row['id'] ?? 0) === (int)$tile->key) { $tile_row = $row; break; }
}
check($tile_row !== null && ($tile_row['is_image'] ?? false) === true,
	'the sealed image is listed as an image (its type is the real, sniffed one)');
if ($tile_row !== null) {
	check(!isset($tile_row['thumb_url']),
		'but no thumbnail URL is minted for it — a broken tile is worse than an honest one');
	check(($tile_row['requires_window'] ?? false) === true,
		'the client is told why instead');
}

// The same call still mints URLs for a Standard file: the omission is about the
// caller's ability to open sealed bytes, not a blanket rule.
$std_listed = api_request('POST', '/api/v1/action/drive_list', $H, array('folder_id' => (int)$sfolder->key));
$std_rows = $std_listed['json']['data']['items'] ?? array();
$std_has_url = false;
foreach ($std_rows as $row) {
	if (($row['protection_level'] ?? '') === 'standard' && !empty($row['download_url'])) { $std_has_url = true; break; }
}
check($std_has_url, 'a Standard file in the same listing still carries its download URL');

// ---------------------------------------------------------------------------
section('the folder lattice');

$nest = api_request('POST', '/api/v1/action/drive_folder_create', $H, array(
	'name' => 'Nest_' . bin2hex(random_bytes(3)), 'parent_id' => (int)$sfolder->key, 'protection_level' => 'private',
));
check(!empty($nest['json']['error']), 'a Private folder under a Standard parent is refused');

$inherit = api_request('POST', '/api/v1/action/drive_folder_create', $H, array(
	'name' => 'Inherit_' . bin2hex(random_bytes(3)), 'parent_id' => (int)$pfolder->key,
));
$inherit_id = (int)($inherit['json']['data']['folder']['id'] ?? 0);
if ($inherit_id) { $made_folders[] = $inherit_id; }
check($inherit_id > 0 && ($inherit['json']['data']['folder']['protection_level'] ?? '') === 'private',
	'a subfolder of a Private folder inherits the level');
check(($inherit['json']['data']['folder']['syncable'] ?? true) === false,
	'the folder export marks a Private tree as not synced');

$bogus = api_request('POST', '/api/v1/action/drive_folder_create', $H, array(
	'name' => 'Bogus_' . bin2hex(random_bytes(3)), 'protection_level' => 'guarded',
));
check(!empty($bogus['json']['error']), 'a level Drive does not offer is refused');

// ---------------------------------------------------------------------------
section('moves across the boundary');

$mv = api_request('POST', '/api/v1/action/drive_move', $H, array(
	'entity_type' => 'folder', 'entity_id' => (int)$pfolder->key, 'parent_id' => (int)$sfolder->key,
));
check(!empty($mv['json']['error']), 'a Private folder cannot move into a Standard one');
check(stripos((string)($mv['json']['error'] ?? ''), 'protection level') !== false,
	'the refusal points at the level change');

// ---------------------------------------------------------------------------
section('a refusal reads the folder\'s promise, not the file\'s bytes');

// A file that has not been converted yet, sitting in a folder that already
// promises Private — the state every level change passes through, for as long as
// the batches take. Its own column still says standard; the folder's does not.
$lagging = File::createFromBytes('lagging-' . bin2hex(random_bytes(6)), 'lagging.txt', 'text/plain', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$lagging->set('fil_fol_folder_id', $pfolder->key); $lagging->save(); $lagging->load();
$made_files[] = $lagging->key;

check(!$lagging->is_sealed(), 'the lagging file\'s own level is still Standard');
check(DriveHelper::effective_file_level($lagging) === ProtectionLevel::PRIVATE_,
	'its effective level is its folder\'s promise');

$lag_link = api_request('POST', '/api/v1/action/drive_link_create', $H, array(
	'entity_type' => 'file', 'entity_id' => (int)$lagging->key,
));
check(!empty($lag_link['json']['error']),
	'a public link on an unconverted file inside a Private folder is refused');

$lag_share = api_request('POST', '/api/v1/action/drive_share_sync', $H, array(
	'entity_type' => 'file', 'entity_id' => (int)$lagging->key,
	'grants' => array((string)$friend->key => 'viewer'),
));
check(!empty($lag_share['json']['error']), 'and so is a member grant on it');

// A move that would seal a file refuses while that file is still shared, rather
// than converting it and leaving a grantee holding permanent 423s.
$shared_std = File::createFromBytes('shared-move-' . bin2hex(random_bytes(6)), 'shared_move.txt', 'text/plain',
	(int)$owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$shared_std->set('fil_fol_folder_id', $sfolder->key); $shared_std->save();
$made_files[] = $shared_std->key;
$gins = $dblink->prepare("INSERT INTO fga_file_access_grants
	(fga_entity_type, fga_entity_id, fga_usr_user_id, fga_role, fga_granted_by_user_id)
	VALUES ('file', ?, ?, 'viewer', ?) RETURNING fga_file_access_grant_id");
$gins->execute(array((int)$shared_std->key, (int)$friend->key, (int)$owner->key));
harness_register_row('fga_file_access_grants', 'fga_file_access_grant_id', $gins->fetchColumn());

$mv_shared = api_request('POST', '/api/v1/action/drive_move', $H, array(
	'entity_type' => 'file', 'entity_id' => (int)$shared_std->key, 'parent_id' => (int)$pfolder->key,
));
check(!empty($mv_shared['json']['error']), 'moving a shared file into a Private folder is refused');
check(stripos((string)($mv_shared['json']['error'] ?? ''), 'member') !== false,
	'the refusal names the access that is in the way');
$still = new File((int)$shared_std->key, true);
check((int)$still->get('fil_fol_folder_id') === (int)$sfolder->key && !$still->is_sealed(),
	'the refused move left the file where it was, unsealed');

// ---------------------------------------------------------------------------
section('an interrupted raise resumes instead of double-sealing');

// The crash this reproduces: a pass writes the wrapping, swaps the bytes, and is
// killed before it can flip the level. What is on disk is a container; what the
// row says is Standard.
$rr = File::createFromBytes(str_repeat('resume-me. ', 400), 'resume.txt', 'text/plain', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$rr->set('fil_fol_folder_id', $pfolder->key); $rr->save(); $rr->load();
$made_files[] = $rr->key;
$rr_plain = $rr->read_bytes();
DriveSealed::sealExistingFile($rr);
$rr = new File($rr->key, true);
$rr_cipher_size = filesize($rr->get_filesystem_path('original'));

$dblink->prepare("UPDATE fil_files SET fil_protection_level = 'standard', fil_plain_size_bytes = NULL
	WHERE fil_file_id = ?")->execute(array((int)$rr->key));
$rr = new File($rr->key, true);
check(!$rr->is_sealed() && SealedFileContainer::looksSealed($rr->get_filesystem_path('original')),
	'the interrupted state is set up: a Standard row over sealed bytes');

$resumed = DriveSealed::sealExistingFile($rr);
$rr = new File($rr->key, true);
check($rr->is_sealed(), 'the resumed pass converges the row');
check($resumed === strlen($rr_plain) && (int)$rr->get('fil_plain_size_bytes') === strlen($rr_plain),
	'it recovers the plaintext size from the container, with no key needed');
check((int)filesize($rr->get_filesystem_path('original')) === (int)$rr_cipher_size,
	'the bytes were NOT sealed a second time');
if ($window_ok) {
	drvpriv_unlock($owner->key, $kp['secret']);
	$rr_back = '';
	DriveSealed::openTo($rr, function ($b) use (&$rr_back) { $rr_back .= $b; });
	check($rr_back === $rr_plain, 'and the file still opens to its original plaintext');
	drvpriv_lock($owner->key);
}

// The unrecoverable shape: sealed bytes whose wrapping never landed. Sealing
// again would bury them under a second key, so it refuses and leaves them alone.
$dblink->prepare("UPDATE fil_files SET fil_protection_level = 'standard', fil_content_sealed = false,
	fil_sealed_key = NULL, fil_plain_size_bytes = NULL WHERE fil_file_id = ?")->execute(array((int)$rr->key));
$orphan = new File($rr->key, true);
$threw_orphan = false;
try { DriveSealed::sealExistingFile($orphan); } catch (DriveSealedException $e) { $threw_orphan = true; }
check($threw_orphan, 'a container with no wrapping is refused, not re-sealed');
check((int)filesize($orphan->get_filesystem_path('original')) === (int)$rr_cipher_size,
	'and its bytes are left exactly as they were');

// ---------------------------------------------------------------------------
section('a truncated container is refused, not served short');

if (!$window_ok) {
	harness_skip('truncation', 'APCu is unavailable in this process');
} else {
	$tr = File::createFromBytes(str_repeat('truncate-me. ', 600), 'truncate.txt', 'text/plain', (int)$owner->key,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$tr->set('fil_fol_folder_id', $pfolder->key); $tr->save(); $tr->load();
	$made_files[] = $tr->key;
	DriveSealed::sealExistingFile($tr);
	$tr = new File($tr->key, true);

	drvpriv_unlock($owner->key, $kp['secret']);
	$whole = '';
	DriveSealed::openTo($tr, function ($b) use (&$whole) { $whole .= $b; });
	check(strlen($whole) === (int)$tr->get('fil_plain_size_bytes'), 'the intact container opens in full');

	// Lop off the trailing block. Every chunk that survives still authenticates —
	// the AEAD binds position, not count — so only the row catches this.
	$tpath = $tr->get_filesystem_path('original');
	$header = SealedFileContainer::readHeader($tpath);
	$fh = fopen($tpath, 'r+b');
	ftruncate($fh, $header['header_len']);
	fclose($fh);

	$threw_trunc = false;
	try {
		DriveSealed::openTo($tr, function ($b) { });
	} catch (DriveSealedException $e) {
		$threw_trunc = true;
	}
	check($threw_trunc, 'a truncated container is refused rather than opened short');
	drvpriv_lock($owner->key);
}

// ---------------------------------------------------------------------------
section('an image survives the round trip through Private');

$img_path = sys_get_temp_dir() . '/drvpriv_' . bin2hex(random_bytes(4)) . '.png';
$im = imagecreatetruecolor(240, 160);
imagefilledrectangle($im, 0, 0, 239, 159, imagecolorallocate($im, 12, 130, 200));
imagepng($im, $img_path);

$ifile = File::createFromBytes(file_get_contents($img_path), 'holiday.png', 'image/png', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$ifile->set('fil_fol_folder_id', $sfolder->key); $ifile->save(); $ifile->load();
$made_files[] = $ifile->key;
@unlink($img_path);

$thumb_key = DriveHelper::thumb_size_key();
if ($thumb_key === null) {
	harness_skip('image round trip', 'no thumbnail size is configured');
} else {
	$ifile->resize('all');
	$ifile = new File($ifile->key, true);

	DriveSealed::sealExistingFile($ifile);
	$ifile = new File($ifile->key, true);
	$iblob = new FileBlob((int)$ifile->get('fil_fbb_file_blob_id'), true);
	check((string)$iblob->get('fbb_mime_type') === 'application/octet-stream',
		'a sealed blob reports what its bytes are, so no pipeline tries to decode them');
	check((string)$iblob->get('fbb_encrypted_variant_key') === (string)$thumb_key,
		'the sealed thumbnail is recorded in the encrypted variant slot');
	check(SealedFileContainer::looksSealed($ifile->get_filesystem_path($thumb_key)),
		'and the thumbnail slot holds a container');

	if (!$window_ok) {
		harness_skip('image lower', 'APCu is unavailable in this process');
	} else {
		drvpriv_unlock($owner->key, $kp['secret']);
		DriveSealed::unsealExistingFile($ifile);
		drvpriv_lock($owner->key);

		$ifile = new File($ifile->key, true);
		$iblob = new FileBlob((int)$ifile->get('fil_fbb_file_blob_id'), true);
		check((string)$iblob->get('fbb_mime_type') === 'image/png',
			'the lowered blob has its real type back');
		check((string)$iblob->get('fbb_encrypted_variant_key') === '',
			'the encrypted variant slot is cleared');

		$tpath = $ifile->get_filesystem_path($thumb_key);
		check(!SealedFileContainer::looksSealed($tpath),
			'the thumbnail slot no longer holds a container — nothing serves ciphertext as an image');
		check(is_file($tpath) && @getimagesize($tpath) !== false,
			'the plaintext thumbnail was regenerated and decodes');
	}
}

harness_finish();
