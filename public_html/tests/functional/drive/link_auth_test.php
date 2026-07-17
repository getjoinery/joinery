<?php
/** @joinery-test
 * name: drive_link_auth
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Drive share-link authorization — who may MINT and who may REVOKE a public link.
 *
 * The share-link model layer (mint/revoke/expiry/password) is exercised by
 * sharing_test.php; that suite always passes the owner id straight into
 * FileShareLink::mint(), so it never touches the gate that decides whether the
 * acting user is allowed to mint at all. This suite drives the two logic
 * functions through a session and proves that gate:
 *
 *   drive_link_create_logic  — owner only. A viewer-grantee (who can READ the
 *     file) and a total stranger are both refused; anonymous is refused; the
 *     owner succeeds. All three actors hold the drive_share_links tier feature,
 *     so a refusal can only be the ownership gate — not the tier gate.
 *   drive_link_revoke_logic  — the link's creator, or staff (perm >= 5). A
 *     stranger and a viewer-grantee are refused; the owner and an admin succeed.
 *
 * Plus one render-side non-exposure check: a folder link lists that folder's own
 * files but not a private file that lives elsewhere in the owner's Drive.
 *
 * Acting user is switched by writing $_SESSION directly — SessionControl reads it
 * live on every get_user_id()/get_permission() call, so the logic sees whichever
 * user this test is impersonating.
 *
 * Run: php tests/functional/drive/link_auth_test.php [base_url] [origin_ip]
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/http.php');
require_once(__DIR__ . '/../../lib/logic.php');
harness_http_boot($argv);
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('data/group_members_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));
require_once(PathHelper::getIncludePath('data/file_share_links_class.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));

const CREATE_LOGIC = 'logic/drive_link_create_logic.php';
const REVOKE_LOGIC = 'logic/drive_link_revoke_logic.php';

/** Impersonate a user for the next logic call. SessionControl reads this live. */
function az_act_as($user, $perm = null) {
	$_SESSION['loggedin']    = 1;
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['permission']  = ($perm === null) ? (int)$user->get('usr_permission') : (int)$perm;
}
/** Drop to an anonymous (signed-out) session. */
function az_act_anon() {
	unset($_SESSION['loggedin'], $_SESSION['usr_user_id'], $_SESSION['permission']);
}
function az_create($type, $id) {
	return harness_call_logic(CREATE_LOGIC, 'drive_link_create_logic',
		array('entity_type' => $type, 'entity_id' => (int)$id));
}
function az_revoke($link_id) {
	return harness_call_logic(REVOKE_LOGIC, 'drive_link_revoke_logic',
		array('link_id' => (int)$link_id));
}

$made_files = array(); $made_folders = array(); $made_links = array();
harness_defer(function () use (&$made_files, &$made_folders, &$made_links) {
	$db = DbConnector::get_instance()->get_db_link();
	foreach ($made_links as $id)   { $db->prepare('DELETE FROM fsl_file_share_links WHERE fsl_file_share_link_id=?')->execute(array((int)$id)); }
	foreach ($made_files as $fid)  { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) {
		$db->prepare("DELETE FROM fga_file_access_grants WHERE fga_entity_type='folder' AND fga_entity_id=?")->execute(array((int)$fid));
		$db->prepare('DELETE FROM fol_folders WHERE fol_folder_id=?')->execute(array((int)$fid));
	}
});

try {
	$run_id = substr(md5(uniqid('linkauth', true)), 0, 6);
	harness_set_setting_mem('drive_active', '1');

	$owner    = make_user('LaOwner' . $run_id);
	$viewer   = make_user('LaViewer' . $run_id);
	$stranger = make_user('LaStranger' . $run_id);
	$admin    = make_user('LaAdmin' . $run_id, 10);

	// A tier that grants share links, with owner+viewer+stranger all in it. With
	// the feature held by every non-owner actor, a mint refusal can only be the
	// ownership gate — the point of this suite.
	$tier = new SubscriptionTier(NULL);
	$tier->set('sbt_name', 'LinkAuth ' . $run_id);
	$tier->set('sbt_display_name', 'LinkAuth ' . $run_id);
	$tier->set('sbt_tier_level', 1);
	$tier->set('sbt_features', json_encode(array('drive_share_links' => true)));
	$tier->set('sbt_is_active', true);
	$tier->save();
	$tier->load();
	$group_id = (int)$tier->get('sbt_grp_group_id');
	$tier_id  = (int)$tier->key;
	harness_defer(function () use ($group_id, $tier_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('DELETE FROM grm_group_members WHERE grm_grp_group_id=?')->execute(array($group_id));
		$db->prepare('DELETE FROM sbt_subscription_tiers WHERE sbt_subscription_tier_id=?')->execute(array($tier_id));
		$db->prepare("DELETE FROM cht_change_tracking WHERE cht_entity_type='subscription_tier' AND cht_entity_id=?")->execute(array($tier_id));
		$db->prepare('DELETE FROM grp_groups WHERE grp_group_id=?')->execute(array($group_id));
	});
	foreach (array($owner, $viewer, $stranger) as $u) {
		$gm = new GroupMember(NULL);
		$gm->set('grm_grp_group_id', $group_id);
		$gm->set('grm_foreign_key_id', (int)$u->key);
		$gm->save();
	}
	SubscriptionTier::clearUserCache((int)$owner->key);
	SubscriptionTier::clearUserCache((int)$viewer->key);
	SubscriptionTier::clearUserCache((int)$stranger->key);

	// Owner's Drive: a private target file (viewer is a grantee on it); a folder
	// holding one file; and a private file that lives OUTSIDE that folder.
	$target = File::createFromBytes('target-' . $run_id, 'la_target_' . $run_id . '.txt', 'text/plain',
		$owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$made_files[] = $target->key;
	FileAccessGrant::sync_for_entity('file', $target->key, array($viewer->key => 'viewer'), $owner->key);

	$folder = new Folder(NULL);
	$folder->set('fol_usr_user_id', $owner->key);
	$folder->set('fol_name', 'LaFolder_' . $run_id);
	$folder->save();
	$folder->load();
	$made_folders[] = $folder->key;

	$inside = File::createFromBytes('inside-' . $run_id, 'la_inside_' . $run_id . '.txt', 'text/plain',
		$owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$inside->set('fil_fol_folder_id', $folder->key);
	$inside->save();
	$made_files[] = $inside->key;

	$outside = File::createFromBytes('outside-' . $run_id, 'la_outside_' . $run_id . '.txt', 'text/plain',
		$owner->key, array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$made_files[] = $outside->key;

	// -------------------------------------------------------------------------
	section('Minting a link is owner-only');

	az_act_as($owner);
	$r = az_create('file', $target->key);
	check(!$r->error && !empty($r->data['token']), 'owner mints a link to their own file',
		$r->error ?: 'no token');
	if (!empty($r->data['link_id'])) { $made_links[] = (int)$r->data['link_id']; }

	$r = az_create('folder', $folder->key);
	check(!$r->error && !empty($r->data['token']), 'owner mints a link to their own folder', $r->error ?: 'no token');
	$folder_token = !empty($r->data['token']) ? $r->data['token'] : null;
	if (!empty($r->data['link_id'])) { $made_links[] = (int)$r->data['link_id']; }

	az_act_as($viewer);
	$r = az_create('file', $target->key);
	check($r->error === 'Only the owner can create a share link.',
		'viewer-grantee is refused (ownership gate, not tier gate)', 'error: ' . var_export($r->error, true));
	if (!empty($r->data['link_id'])) { $made_links[] = (int)$r->data['link_id']; }

	az_act_as($stranger);
	$r = az_create('file', $target->key);
	check($r->error === 'Only the owner can create a share link.',
		'stranger is refused (ownership gate, not tier gate)', 'error: ' . var_export($r->error, true));
	if (!empty($r->data['link_id'])) { $made_links[] = (int)$r->data['link_id']; }

	az_act_anon();
	$r = az_create('file', $target->key);
	check($r->error === 'You must be signed in to use Drive.',
		'anonymous is refused', 'error: ' . var_export($r->error, true));

	az_act_as($owner);
	$r = az_create('file', 0);
	check($r->error === 'Item not found.', 'minting a link to a nonexistent item is refused',
		'error: ' . var_export($r->error, true));

	// -------------------------------------------------------------------------
	section('Revoking a link is creator-or-staff only');

	// Fresh owner-minted links for the revoke matrix (each revoke consumes one).
	$mk = function () use ($owner) {
		$m = FileShareLink::mint('file', 0, $owner->key); // entity id irrelevant to revoke authz
		return $m['link'];
	};

	$l1 = $mk(); $made_links[] = (int)$l1->key;
	az_act_as($stranger);
	$r = az_revoke($l1->key);
	check($r->error === 'You did not create this link.', 'stranger cannot revoke another user link',
		'error: ' . var_export($r->error, true));
	$l1->load();
	check(!$l1->get('fsl_revoked_time'), 'refused revoke left the link live', 'revoked_time set');

	az_act_as($viewer);
	$r = az_revoke($l1->key);
	check($r->error === 'You did not create this link.', 'viewer-grantee cannot revoke another user link',
		'error: ' . var_export($r->error, true));

	az_act_as($owner);
	$r = az_revoke($l1->key);
	check(!$r->error && !empty($r->data['ok']), 'owner revokes their own link', $r->error ?: 'no ok');
	$l1->load();
	check((bool)$l1->get('fsl_revoked_time'), 'owner revoke marked the link revoked', 'revoked_time missing');

	// Staff override: perm >= 5 may revoke a link they did not create (deliberate).
	$l2 = $mk(); $made_links[] = (int)$l2->key;
	az_act_as($admin, 10);
	$r = az_revoke($l2->key);
	check(!$r->error && !empty($r->data['ok']), 'admin (perm 10) may revoke a link they did not create', $r->error ?: 'no ok');

	az_act_as($owner);
	$r = az_revoke(0);
	check($r->error === 'Share link not found.', 'revoking a nonexistent link is refused',
		'error: ' . var_export($r->error, true));

	// -------------------------------------------------------------------------
	section('A folder link exposes only that folder, not private siblings elsewhere');

	if ($folder_token) {
		$page = harness_request('GET', '/s/' . $folder_token, array('accept' => null));
		check($page['status'] === 200 && strpos($page['raw'], 'la_inside_' . $run_id) !== false,
			'folder link lists its own file (positive control)', 'status ' . $page['status']);
		check(strpos($page['raw'], 'la_outside_' . $run_id) === false,
			'a private file outside the shared folder is not exposed by the link');
	} else {
		harness_skip('folder non-exposure', 'folder link was not minted');
		harness_skip('folder non-exposure sibling', 'folder link was not minted');
	}

} finally {
	az_act_anon();
	harness_teardown_data();
}

harness_finish();
