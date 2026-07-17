<?php
/** @joinery-test
 * name: drive_sharing
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../api/api_test_harness.php');
api_test_boot($argv);

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
require_once(PathHelper::getIncludePath('data/file_share_links_class.php'));

/** Stub session for is_viewable(). */
class ShareTestSession {
	public $uid; public $perm;
	function __construct($u, $p = 0) { $this->uid = $u; $this->perm = $p; }
	function get_user_id() { return $this->uid; }
	function get_permission() { return $this->perm; }
}

/** Anonymous fetch of the public share page (no auth). Returns ['status','raw']. */
function share_fetch($path, $post = null) {
	return harness_request($post === null ? 'GET' : 'POST', $path, array(
		'body'   => $post,
		'encode' => 'form',
		'accept' => null,
	));
}

$made_files = array(); $made_folders = array(); $made_links = array();
harness_defer(function () use (&$made_files, &$made_folders, &$made_links) {
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach ($made_links as $id) { $dblink->prepare("DELETE FROM fsl_file_share_links WHERE fsl_file_share_link_id=?")->execute(array((int)$id)); }
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) { $dblink->prepare("DELETE FROM fga_file_access_grants WHERE fga_entity_type='folder' AND fga_entity_id=?")->execute(array((int)$fid)); $dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid)); }
});

$owner    = make_user('shareowner');
$viewer   = make_user('shareviewer');
$editor   = make_user('shareeditor');
$outsider = make_user('shareoutsider');

// Tree: F / Sub / (nested file); plus file G directly in F.
$F = new Folder(NULL); $F->set('fol_usr_user_id', $owner->key); $F->set('fol_name', 'ShareRoot_' . bin2hex(random_bytes(3))); $F->save(); $F->load();
$made_folders[] = $F->key;
$Sub = new Folder(NULL); $Sub->set('fol_usr_user_id', $owner->key); $Sub->set('fol_parent_folder_id', $F->key); $Sub->set('fol_name', 'Sub'); $Sub->save(); $Sub->load();
$made_folders[] = $Sub->key;

$nested = File::createFromBytes('nested-' . bin2hex(random_bytes(6)), 'nested.txt', 'text/plain', $owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$nested->set('fil_fol_folder_id', $Sub->key); $nested->save();
$made_files[] = $nested->key;

$G = File::createFromBytes('gee-' . bin2hex(random_bytes(6)), 'g.txt', 'text/plain', $owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$G->set('fil_fol_folder_id', $F->key); $G->save();
$made_files[] = $G->key;

// ---------------------------------------------------------------------------
section('grant sync: add / remove / role change');

FileAccessGrant::sync_for_entity('file', $G->key, array($viewer->key => 'viewer'), $owner->key);
check(FileAccessGrant::role_for('file', $G->key, $viewer->key) === 'viewer', 'viewer grant added');

FileAccessGrant::sync_for_entity('file', $G->key, array($viewer->key => 'viewer', $editor->key => 'editor'), $owner->key);
check(FileAccessGrant::role_for('file', $G->key, $editor->key) === 'editor', 'editor grant added');
check(count(FileAccessGrant::user_ids_for_entity('file', $G->key)) === 2, 'two grantees now');

FileAccessGrant::sync_for_entity('file', $G->key, array($viewer->key => 'editor'), $owner->key);
check(FileAccessGrant::role_for('file', $G->key, $viewer->key) === 'editor', 'role changed viewer -> editor');
check(FileAccessGrant::role_for('file', $G->key, $editor->key) === null, 'grantee not in the new set was removed');

// Reset to a clean matrix: viewer=viewer, editor=editor on G.
FileAccessGrant::sync_for_entity('file', $G->key, array($viewer->key => 'viewer', $editor->key => 'editor'), $owner->key);

// ---------------------------------------------------------------------------
section('is_viewable() matrix (private file G)');

check($G->is_viewable(new ShareTestSession($owner->key)) === true, 'owner may view');
check($G->is_viewable(new ShareTestSession($viewer->key)) === true, 'viewer grant may view');
check($G->is_viewable(new ShareTestSession($editor->key)) === true, 'editor grant may view');
check($G->is_viewable(new ShareTestSession($outsider->key)) === false, 'outsider may not view');
check($G->is_viewable(new ShareTestSession($outsider->key, 10)) === true, 'admin (perm 10) may view');

// ---------------------------------------------------------------------------
section('viewer vs editor write enforcement');

check(DriveHelper::can_read('file', $G, $viewer->key) === true, 'viewer can read');
check(DriveHelper::can_write('file', $G, $viewer->key) === false, 'viewer cannot write');
check(DriveHelper::can_write('file', $G, $editor->key) === true, 'editor can write');
check(DriveHelper::can_write('file', $G, $outsider->key) === false, 'outsider cannot write');

// ---------------------------------------------------------------------------
section('ancestor-folder grant reaches a nested file');

// Grant viewer on the ROOT folder F; the nested file (F/Sub/nested) becomes viewable.
FileAccessGrant::sync_for_entity('folder', $F->key, array($viewer->key => 'viewer', $editor->key => 'editor'), $owner->key);
check($nested->is_viewable(new ShareTestSession($viewer->key)) === true, 'ancestor viewer grant reaches nested file');
check(DriveHelper::can_write('file', $nested, $editor->key) === true, 'ancestor editor grant grants write on nested file');
check($nested->is_viewable(new ShareTestSession($outsider->key)) === false, 'no ancestor grant -> nested file not viewable');
// Shared-with-me listing surfaces the shared folder for the viewer.
check(in_array((int)$F->key, FileAccessGrant::entity_ids_for_user($viewer->key, 'folder'), true), 'shared folder appears in entity_ids_for_user');

// ---------------------------------------------------------------------------
section('share link lifecycle (mint -> anonymous fetch -> password -> expiry -> revoke)');

$m = FileShareLink::mint('file', $G->key, $owner->key);
$made_links[] = $m['link']->key;
$r = share_fetch('/s/' . $m['token']);
check($r['status'] === 200 && stripos($r['raw'], 'Download') !== false, 'anonymous fetch of a live file link shows a download', 'status ' . $r['status']);

// password-protected link
$mp = FileShareLink::mint('file', $G->key, $owner->key, null, 'letmein');
$made_links[] = $mp['link']->key;
$rp = share_fetch('/s/' . $mp['token']);
check(stripos($rp['raw'], 'Password') !== false, 'password link prompts for a password');
$rwrong = share_fetch('/s/' . $mp['token'], array('drv_link_password' => 'nope'));
check(stripos($rwrong['raw'], 'Incorrect') !== false, 'wrong password is rejected');
$rright = share_fetch('/s/' . $mp['token'], array('drv_link_password' => 'letmein'));
check(stripos($rright['raw'], 'Download') !== false, 'correct password reveals the content');

// expired link
$past = gmdate('Y-m-d H:i:s', time() - 3600);
$me = FileShareLink::mint('file', $G->key, $owner->key, $past);
$made_links[] = $me['link']->key;
check($me['link']->is_live() === false, 'expired link is not live');
$rexp = share_fetch('/s/' . $me['token']);
check(stripos($rexp['raw'], 'no longer available') !== false, 'anonymous fetch of an expired link is refused');

// revoked link
$mr = FileShareLink::mint('file', $G->key, $owner->key);
$made_links[] = $mr['link']->key;
$mr['link']->revoke();
check($mr['link']->is_live() === false, 'revoked link is not live');
$rrev = share_fetch('/s/' . $mr['token']);
check(stripos($rrev['raw'], 'no longer available') !== false, 'anonymous fetch of a revoked link is refused');

// folder share link renders a listing
$mf = FileShareLink::mint('folder', $F->key, $owner->key);
$made_links[] = $mf['link']->key;
$rf = share_fetch('/s/' . $mf['token']);
check($rf['status'] === 200 && stripos($rf['raw'], 'g.txt') !== false, 'folder share link lists its files');

harness_finish();
