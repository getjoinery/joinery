<?php
/** @joinery-test
 * name: drive_openers
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The Drive opener/creator hook (DriveHelper::registerOpener / registerCreator)
 * and the in-place head replace an editing session's later saves use
 * (FileVersion::replace_head_content). A test opener stands in for a plugin:
 * Drive itself never names one.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/file_versions_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));

$made_files = array();
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) {
		// The user's own cleanup may have purged them already.
		$f = DriveHelper::load_file((int)$fid);
		if ($f) { $f->permanent_delete(); }
	}
});

$user = make_user('driveopeners');
$session = SessionControl::get_instance();
$session->set_api_user($user->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

function drive_file($title, $user_id, array $extra = array()) {
	global $made_files;
	$f = File::createFromBytes('bytes-' . bin2hex(random_bytes(8)), $title, 'application/octet-stream', $user_id,
		array_merge(array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE), $extra));
	$made_files[] = $f->key;
	return $f;
}
function opener_keys($export) {
	return array_map(function ($o) { return $o['key']; }, isset($export['open_with']) ? $export['open_with'] : array());
}

DriveHelper::resetOpenersForTest();
harness_defer(function () { DriveHelper::resetOpenersForTest(); });

// ---------------------------------------------------------------------------
section('registration and the extension match');

DriveHelper::registerOpener('test_editor', array(
	'label'      => 'Open in test editor',
	'extensions' => array('DOCX', '.xlsx'),
	'url'        => function ($file, $uid) {
		return (strpos((string)$file->get('fil_title'), 'locked') === false) ? '/test/edit?file=' . (int)$file->key : null;
	},
	'explain'    => function ($file, $uid) { return 'Locked files cannot be opened here.'; },
));

$docx = drive_file('Paper.docx', $user->key);
$txt  = drive_file('notes.txt', $user->key);
$x = DriveHelper::file_export($docx);
check(opener_keys($x) === array('test_editor'), 'a .docx gets the opener (extensions match case-blind, leading dot ignored)');
check(isset($x['open_with'][0]['url']) && $x['open_with'][0]['url'] === '/test/edit?file=' . (int)$docx->key, 'the entry carries the url the opener returned');
check(isset($x['open_with'][0]['label']) && $x['open_with'][0]['label'] === 'Open in test editor', 'the entry carries the label');
$xt = DriveHelper::file_export($txt);
check(!isset($xt['open_with']), 'a .txt file gets no open_with at all');

section('explain when url gives null');
$locked = drive_file('locked budget.xlsx', $user->key);
$xl = DriveHelper::file_export($locked);
check(isset($xl['open_with'][0]) && !isset($xl['open_with'][0]['url'])
	&& $xl['open_with'][0]['explain'] === 'Locked files cannot be opened here.', 'url null + explain gives {key,label,explain}');

DriveHelper::registerOpener('silent', array(
	'label' => 'Silent', 'extensions' => array('xlsx'),
	'url' => function () { return null; },
));
$xl2 = DriveHelper::file_export($locked);
check(opener_keys($xl2) === array('test_editor'), 'an opener with no url and no explanation is left out');

DriveHelper::registerOpener('throws', array(
	'label' => 'Throws', 'extensions' => array('docx'),
	'url' => function () { throw new RuntimeException('boom'); },
));
check(opener_keys(DriveHelper::file_export($docx)) === array('test_editor'), 'an opener that throws is skipped, not fatal');

// ---------------------------------------------------------------------------
section('absent in Trash, for other features\' files, and for sync clients');

$trashed = drive_file('Old.docx', $user->key);
$trashed->soft_delete();
$trashed = new File($trashed->key, true);
check(!isset(DriveHelper::file_export($trashed)['open_with']), 'a trashed file gets no openers');

$mail = drive_file('attachment.docx', $user->key, array('fil_source' => 'mailbox'));
check(!isset(DriveHelper::file_export($mail)['open_with']), 'a file another feature stored stays download-only');

check(!isset(DriveHelper::file_export($docx, null, null, null, false)['open_with']), 'the bulk sync export (no urls) carries no openers');

$prop = new ReflectionProperty('ApiAuth', 'current_api_key');
$prop->setAccessible(true);
$prop->setValue(null, array('apk_api_key_id' => -1));
try {
	check(!isset(DriveHelper::file_export($docx)['open_with']), 'an API-key caller (sync client, app) gets no openers');
} finally {
	$prop->setValue(null, null);
}
check(isset(DriveHelper::file_export($docx)['open_with']), 'back on a browser session the openers return');

// ---------------------------------------------------------------------------
section('create_options');

check(DriveHelper::create_options_for(null, $user->key) === array(), 'no creators registered: nothing to offer');

DriveHelper::registerCreator('test_doc', array(
	'label' => 'Document', 'action' => 'test/test_create', 'params' => array('kind' => 'document'),
));
DriveHelper::registerCreator('test_sheet', array(
	'label' => 'Spreadsheet', 'action' => 'test/test_create', 'params' => array('kind' => 'spreadsheet'),
	'available' => function ($folder, $uid) { return $folder === null ? true : 'Not inside folders.'; },
));
$opts = DriveHelper::create_options_for(null, $user->key);
check(count($opts) === 2 && $opts[0]['key'] === 'test_doc' && $opts[0]['action'] === 'test/test_create'
	&& (array)$opts[0]['params'] === array('kind' => 'document'), 'root: both creators, with action and params');

$folder = new Folder(NULL);
$folder->set('fol_usr_user_id', $user->key);
$folder->set('fol_name', 'openers-' . bin2hex(random_bytes(3)));
$folder->save();
harness_register_row('fol_folders', 'fol_folder_id', $folder->key);
$opts = DriveHelper::create_options_for($folder, $user->key);
check(count($opts) === 2 && isset($opts[1]['explain']) && !isset($opts[1]['action']), 'a creator that says no carries its reason and no action');

$list = harness_call_logic('logic/drive_list_logic.php', 'drive_list_logic', array('view' => 'mine'))->data;
check(isset($list['create_options']) && count($list['create_options']) === 2, 'the folder listing carries create_options');
$found = null;
foreach ($list['items'] ?? array() as $it) {
	if (($it['id'] ?? 0) === (int)$docx->key) { $found = $it; }
}
check($found !== null && isset($found['open_with']), 'the folder listing carries open_with on the .docx');
$list = harness_call_logic('logic/drive_list_logic.php', 'drive_list_logic', array('view' => 'trash'))->data;
check(($list['create_options'] ?? null) === array(), 'Trash offers nothing to create');

// ---------------------------------------------------------------------------
section('replace_head_content: a session\'s later saves replace its own head');

function blob_from($bytes) {
	$tmp = tempnam(sys_get_temp_dir(), 'drvopen_');
	file_put_contents($tmp, $bytes);
	return FileBlob::createFromPath($tmp, 'application/octet-stream', true);
}
function refcount($blob_id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT fbb_reference_count FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	$q->execute(array((int)$blob_id));
	$v = $q->fetchColumn();
	return $v === false ? null : (int)$v;
}
function versions_of($file_id) {
	return (new MultiFileVersion(array('file_id' => (int)$file_id)))->count_all();
}

$f = drive_file('Session.docx', $user->key);
$start = (int)$f->get('fil_fbb_file_blob_id');
$v_before = versions_of($f->key);

$b1 = blob_from('first save ' . bin2hex(random_bytes(10)));
FileVersion::save_new_content($f, $b1, $user->key);
$after_first = versions_of($f->key);

$b2 = blob_from('second save ' . bin2hex(random_bytes(10)));
$ok = FileVersion::replace_head_content($f, $b2, (int)$b1->key);
check($ok === true, 'replacing the head this session wrote succeeds');
$fresh = new File($f->key, true);
check((int)$fresh->get('fil_fbb_file_blob_id') === (int)$b2->key, 'the head is the new blob');
check(versions_of($f->key) === $after_first, 'no version was added');
FileBlob::flushDeferredReclaims();
check(refcount($b1->key) === null || refcount($b1->key) === 0, 'the replaced head\'s reference was given back');
check(refcount($b2->key) === 1, 'the new head holds exactly one reference');

$b3 = blob_from('stale save ' . bin2hex(random_bytes(10)));
$ok = FileVersion::replace_head_content($f, $b3, (int)$b1->key);
check($ok === false, 'a head that is not the one this session wrote is not replaced');
$fresh = new File($f->key, true);
check((int)$fresh->get('fil_fbb_file_blob_id') === (int)$b2->key, 'the head is left as it was');
check(refcount($b3->key) === 1, 'the refused blob still holds the caller\'s reference');
FileBlob::release($b3->key);

harness_finish();
