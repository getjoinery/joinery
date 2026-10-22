<?php
/** @joinery-test
 * name: drive_folder_zip
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * PURPOSE: A Drive folder downloaded as a zip comes back as the same tree, with
 * the same names and bytes, to whoever may read it and nobody else
 * (specs/export_mail_contacts_calendar_drive.md WP4, docs/drive.md
 * § Downloading a folder).
 *
 *   - the round trip: nested folders, an empty folder, accented names, a 2 MB
 *     file read in chunks, a stored photo and a deflated text file; every byte
 *     and checksum compared through PHP's own zip reader
 *   - trashed files and trashed folders stay out
 *   - names that would not extract where they say are cleaned, and two
 *     siblings that differ only in case both survive
 *   - a file whose bytes are gone is left out and named in a note
 *   - the route over HTTP: the owner gets the zip, a grantee gets the same zip,
 *     a stranger 404, signed out 401, a Fortress folder 409, a locked Private
 *     folder 423
 *   - a Private folder in the owner's window: plaintext in the zip, the
 *     sealed bytes on disk untouched
 *
 * Run:  php tests/run.php db --only=tests/functional/drive/folder_zip_test.php
 *
 * @version 1.2 - the CLI session starts before any output, so the Private checks run every time
 * @version 1.1 - the note never takes a real file's name (R5)
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
// The in-window Private checks need a CLI session, and PHP starts one only
// before any output: started here, first, or a run that prints early would
// skip them.
if (session_id() === '') {
	@session_start();
}
require_once(__DIR__ . '/../api/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
VaultUnlock::loadConsumerBootstraps();
require_once(__DIR__ . '/../../lib/vault_fixtures.php');

$dblink = DbConnector::get_instance()->get_db_link();

$made_files = array(); $made_folders = array();
harness_defer(function () use (&$made_files, &$made_folders) {
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) {
		$dblink->prepare("DELETE FROM fga_file_access_grants WHERE fga_entity_type='folder' AND fga_entity_id=?")->execute(array((int)$fid));
		$dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid));
	}
});

$owner    = make_user('zipowner');
$viewer   = make_user('zipviewer');
$stranger = make_user('zipstranger');

function zt_folder($owner_id, $name, &$made, $parent = null, $level = 'standard') {
	$f = new Folder(NULL);
	$f->set('fol_usr_user_id', $owner_id);
	$f->set('fol_name', $name);
	$f->set('fol_protection_level', $level);
	if ($parent) { $f->set('fol_parent_folder_id', $parent); }
	$f->save(); $f->load();
	$made[] = $f->key;
	return $f;
}

function zt_file($owner_id, $folder_id, $title, $bytes, $mime, &$made) {
	$f = File::createFromBytes($bytes, $title, $mime, $owner_id, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$f->set('fil_fol_folder_id', $folder_id);
	$f->save();
	$made[] = $f->key;
	return $f;
}

/** Stream a plan into a temp file and open it with PHP's reader. */
function zt_zip(array $plan, &$path, &$result) {
	$path = tempnam(sys_get_temp_dir(), 'zt_');
	harness_defer(function () use ($path) { @unlink($path); });
	$fh = fopen($path, 'wb');
	$result = DriveZip::stream($plan, function ($b) use ($fh) { fwrite($fh, $b); });
	fclose($fh);
	$zip = new ZipArchive();
	return $zip->open($path, ZipArchive::CHECKCONS) === true ? $zip : null;
}

/** name => [crc, size] for every entry. */
function zt_index(ZipArchive $zip) {
	$out = array();
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$s = $zip->statIndex($i);
		$out[$s['name']] = array($s['crc'], $s['size']);
	}
	return $out;
}

function zt_crc($bytes) {
	return hexdec(hash('crc32b', $bytes));
}

$tag = bin2hex(random_bytes(3));

// ---------------------------------------------------------------------------
section('a Standard tree round trip');

$root = zt_folder($owner->key, 'Zip Root ' . $tag, $made_folders);
$sub  = zt_folder($owner->key, 'Sub', $made_folders, $root->key);
$deep = zt_folder($owner->key, 'Ångström', $made_folders, $sub->key);
$empty = zt_folder($owner->key, 'Empty', $made_folders, $root->key);
$gone = zt_folder($owner->key, 'Trashed folder', $made_folders, $root->key);

$text  = str_repeat("A line of notes for the zip test.\n", 400) . $tag;
$photo = random_bytes(150000) . $tag;
$big   = random_bytes(2 * 1048576 + 333) . $tag;
$deep_bytes = "deep file ✓ " . $tag;

zt_file($owner->key, $root->key, 'notes.txt', $text, 'text/plain', $made_files);
zt_file($owner->key, $root->key, 'Photo.jpg', $photo, 'image/jpeg', $made_files);
zt_file($owner->key, $sub->key, 'big.bin', $big, 'application/octet-stream', $made_files);
zt_file($owner->key, $deep->key, 'Zoë — résumé.txt', $deep_bytes, 'text/plain', $made_files);
zt_file($owner->key, $root->key, 'Report.txt', 'upper ' . $tag, 'text/plain', $made_files);
zt_file($owner->key, $root->key, 'report.txt', 'lower ' . $tag, 'text/plain', $made_files);
$slashed = zt_file($owner->key, $root->key, 'plain name', 'slashed ' . $tag, 'text/plain', $made_files);
// A name the upload paths would not produce, set directly: the zip must still
// keep it inside its folder.
$dblink->prepare("UPDATE fil_files SET fil_title = ? WHERE fil_file_id = ?")->execute(array('../a/b.txt', $slashed->key));
$slashed = new File($slashed->key, true);

$trashed = zt_file($owner->key, $root->key, 'trashed.txt', 'trashed ' . $tag, 'text/plain', $made_files);
$trashed->set('fil_delete_time', gmdate('Y-m-d H:i:s')); $trashed->save();
zt_file($owner->key, $gone->key, 'inside trashed folder.txt', 'x ' . $tag, 'text/plain', $made_files);
$gone->set('fol_delete_time', gmdate('Y-m-d H:i:s')); $gone->save();

$plan = DriveZip::plan(new Folder($root->key, true));
check(DriveZip::filename($plan) === 'Zip Root ' . $tag . '.zip', 'the download is named for the folder');
$zip = zt_zip($plan, $zpath, $res);
check($zip !== null, 'PHP\'s zip reader opens the archive and finds it consistent');

if ($zip) {
	$idx = zt_index($zip);
	$top = 'Zip Root ' . $tag;
	$expect = array(
		$top . '/' => '',
		$top . '/Empty/' => '',
		$top . '/Sub/' => '',
		$top . '/Sub/Ångström/' => '',
		$top . '/notes.txt' => $text,
		$top . '/Photo.jpg' => $photo,
		$top . '/Report.txt' => 'upper ' . $tag,
		$top . '/report (2).txt' => 'lower ' . $tag,
		$top . '/.._a_b.txt' => 'slashed ' . $tag,
		$top . '/Sub/big.bin' => $big,
		$top . '/Sub/Ångström/Zoë — résumé.txt' => $deep_bytes,
	);
	check(count($idx) === count($expect), 'the zip holds exactly the live tree (' . count($idx) . ' entries, ' . count($expect) . ' expected)');
	$missing = array_diff(array_keys($expect), array_keys($idx));
	check(!$missing, 'every folder and file is there under its own path' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
	$bad = array();
	foreach ($expect as $name => $bytes) {
		if (!isset($idx[$name]) || substr($name, -1) === '/') { continue; }
		if ($zip->getFromName($name) !== $bytes || $idx[$name][0] !== zt_crc($bytes) || $idx[$name][1] !== strlen($bytes)) {
			$bad[] = $name;
		}
	}
	check(!$bad, 'every file comes back byte for byte with the right checksum and size' . ($bad ? ' (wrong: ' . implode(', ', $bad) . ')' : ''));
	check(!isset($idx[$top . '/trashed.txt']), 'a trashed file stays out');
	check(!isset($idx[$top . '/Trashed folder/']) && !isset($idx[$top . '/Trashed folder/inside trashed folder.txt']),
		'a trashed folder stays out, with what is in it');
	check($zip->statName($top . '/notes.txt')['comp_method'] === ZipArchive::CM_DEFLATE
		&& $zip->statName($top . '/notes.txt')['comp_size'] < strlen($text), 'text is deflated');
	check($zip->statName($top . '/Photo.jpg')['comp_method'] === ZipArchive::CM_STORE, 'a photo is stored as it is');
	check($res['files'] === 7 && $res['folders'] === 4 && $res['missing'] === array(), 'the counts match what was written');
	$zip->close();

	if (trim((string)shell_exec('command -v unzip')) !== '') {
		$out = (string)shell_exec('unzip -tq ' . escapeshellarg($zpath) . ' 2>&1');
		check(strpos($out, 'No errors detected') !== false, 'unzip -t finds no errors');
	}
}

// ---------------------------------------------------------------------------
section('a file whose bytes are gone');

$lost = zt_file($owner->key, $empty->key, 'lost.txt', 'lost ' . bin2hex(random_bytes(16)), 'text/plain', $made_files);
@unlink($lost->get_filesystem_path('original'));
// A member's own file that happens to carry the note's name keeps it.
$namesake = zt_file($owner->key, $root->key, DriveZip::MISSING_NOTE, 'my own file ' . $tag, 'text/plain', $made_files);
$zip = zt_zip(DriveZip::plan(new Folder($root->key, true)), $zpath2, $res2);
check($zip !== null, 'the archive is still whole');
if ($zip) {
	$note = $zip->getFromName($top . '/Files not included (2).txt');
	check($zip->locateName($top . '/Empty/lost.txt') === false, 'the file with no bytes is left out');
	check($note !== false && strpos($note, $top . '/Empty/lost.txt') !== false, 'a note in the top folder names it');
	check($zip->getFromName($top . '/' . DriveZip::MISSING_NOTE) === 'my own file ' . $tag,
		'a real file already named like the note keeps its name; the note takes the next one');
	check($res2['missing'] === array($top . '/Empty/lost.txt'), 'stream() reports it');
	$zip->close();
}
$lost->set('fil_delete_time', gmdate('Y-m-d H:i:s')); $lost->save();
$namesake->set('fil_delete_time', gmdate('Y-m-d H:i:s')); $namesake->save();

// ---------------------------------------------------------------------------
section('the route');

$jar_owner = harness_jar_new('ztown');
$jar_viewer = harness_jar_new('ztview');
$jar_stranger = harness_jar_new('ztstr');
$owner_ok = harness_web_login($jar_owner, $owner->get('usr_email'), 'TestPassword_zipowner') !== null;
check($owner_ok, 'the owner signs in over the web');
FileAccessGrant::sync_for_entity('folder', $root->key, array($viewer->key => 'viewer'), $owner->key);
$viewer_ok = harness_web_login($jar_viewer, $viewer->get('usr_email'), 'TestPassword_zipviewer') !== null;
$stranger_ok = harness_web_login($jar_stranger, $stranger->get('usr_email'), 'TestPassword_zipstranger') !== null;

$url = '/drive/zip/' . (int)$root->key;
$got = harness_request('GET', $url, array('jar' => $jar_owner, 'accept' => null, 'timeout' => 120));
check($got['status'] === 200, 'the owner gets 200 (got ' . $got['status'] . ')');
check(stripos($got['content_type'], 'application/zip') === 0, 'as application/zip');
check(stripos($got['header_string'], 'filename*=UTF-8\'\'' . rawurlencode('Zip Root ' . $tag . '.zip')) !== false, 'named for the folder');
$tmp = tempnam(sys_get_temp_dir(), 'zt_http_');
harness_defer(function () use ($tmp) { @unlink($tmp); });
file_put_contents($tmp, $got['body']);
$zip = new ZipArchive();
$http_ok = $zip->open($tmp, ZipArchive::CHECKCONS) === true;
check($http_ok, 'the downloaded body is a whole zip');
if ($http_ok) {
	check($zip->getFromName($top . '/Sub/big.bin') === $big, 'with the 2 MB file intact');
	$zip->close();
}

$head = harness_request('HEAD', $url, array('jar' => $jar_owner, 'accept' => null));
check($head['status'] === 200 && $head['body'] === '', 'a HEAD answers 200 with no body');

if ($viewer_ok) {
	$v = harness_request('GET', $url, array('jar' => $jar_viewer, 'accept' => null, 'timeout' => 120));
	$vtmp = tempnam(sys_get_temp_dir(), 'zt_v_');
	harness_defer(function () use ($vtmp) { @unlink($vtmp); });
	file_put_contents($vtmp, $v['body']);
	$vz = new ZipArchive();
	$vok = $v['status'] === 200 && $vz->open($vtmp, ZipArchive::CHECKCONS) === true;
	check($vok, 'a member the folder is shared with gets the zip');
	if ($vok) {
		check($vz->getFromName($top . '/notes.txt') === $text, 'the same content the owner gets');
		$vz->close();
	}
	$vsub = harness_request('HEAD', '/drive/zip/' . (int)$sub->key, array('jar' => $jar_viewer, 'accept' => null));
	check($vsub['status'] === 200, 'and a subfolder of the share');
}
if ($stranger_ok) {
	$s = harness_request('HEAD', $url, array('jar' => $jar_stranger, 'accept' => null));
	check($s['status'] === 404, 'a member it is not shared with gets 404 (got ' . $s['status'] . ')');
}
$anon = harness_request('HEAD', $url, array('accept' => null));
check($anon['status'] === 401, 'signed out gets 401 (got ' . $anon['status'] . ')');
$gone_head = harness_request('HEAD', '/drive/zip/' . (int)$gone->key, array('jar' => $jar_owner, 'accept' => null));
check($gone_head['status'] === 404, 'a trashed folder gets 404');

$fort = zt_folder($owner->key, 'Fortress ' . $tag, $made_folders, null, ProtectionLevel::FORTRESS);
$f409 = harness_request('HEAD', '/drive/zip/' . (int)$fort->key, array('jar' => $jar_owner, 'accept' => null));
check($f409['status'] === 409, 'a Fortress folder gets 409: the browser zips it (got ' . $f409['status'] . ')');
$threw = false;
try { DriveZip::plan(new Folder($fort->key, true)); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'and plan() refuses it outright');

// ---------------------------------------------------------------------------
section('a Private folder');

$kp = vault_fixture_server_vault((int)$owner->key);
$window_ok = vault_apcu_usable() && vault_ensure_session();
$pfolder = zt_folder($owner->key, 'Private ' . $tag, $made_folders, null, ProtectionLevel::PRIVATE_);
$secret_text = 'Sealed contents ' . $tag . ' ' . str_repeat('quiet words ', 300);
$src = tempnam(sys_get_temp_dir(), 'zt_seal_');
file_put_contents($src, $secret_text);
$sealed = DriveSealed::createSealedFile($src, 'private notes.txt', 'text/plain', (int)$owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_fol_folder_id' => $pfolder->key));
$made_files[] = $sealed->key;
@unlink($src);
$on_disk = $sealed->get_filesystem_path('original');
$disk_before = hash_file('sha256', $on_disk);

$p423 = harness_request('HEAD', '/drive/zip/' . (int)$pfolder->key, array('jar' => $jar_owner, 'accept' => null));
check($p423['status'] === 423, 'the owner\'s web session with no window open gets 423 (got ' . $p423['status'] . ')');

if (!$window_ok) {
	harness_skip('in-window Private zip', 'APCu is unavailable in this process; run through tests/run.php');
} else {
	VaultUnlock::close((int)$owner->key, 'user');
	$threw = false;
	try { DriveZip::plan(new Folder($pfolder->key, true)); } catch (VaultLockedException $e) { $threw = true; }
	check($threw, 'plan() refuses a Private folder with the window shut');

	vault_fixture_open_window((int)$owner->key, $kp['secret'], 'user', array('idle' => null, 'absolute' => null));
	$pplan = DriveZip::plan(new Folder($pfolder->key, true));
	$pz = zt_zip($pplan, $ppath, $pres);
	check($pz !== null, 'in the window the Private folder zips');
	if ($pz) {
		check($pz->getFromName('Private ' . $tag . '/private notes.txt') === $secret_text, 'the zip holds the plaintext');
		$pz->close();
	}
	check(hash_file('sha256', $on_disk) === $disk_before, 'the sealed bytes on disk are untouched');
	check(strpos((string)file_get_contents($on_disk), 'Sealed contents') === false, 'and still sealed');
	VaultUnlock::close((int)$owner->key, 'user');
}

harness_finish();
