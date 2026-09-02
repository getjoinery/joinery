<?php
/** @joinery-test
 * name: drive_user_delete_purges_drive
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A member's Drive goes with them when they are permanently deleted.
 *
 * Folders and Drive files are private storage with no meaning apart from their
 * owner. Left to the generic foreign-key rules they were re-homed under the
 * USER_DELETED tombstone, where every deleted member's "Beta" and "Shared"
 * piled up until the per-owner sibling-name index collided and the user
 * delete itself failed with a unique violation — which is how the drive
 * folders suite started failing at teardown. Files from other sources (an
 * upload a surviving post references) are not Drive content and keep the
 * re-home rule; this test pins both halves.
 *
 * Run: php tests/run.php db --filter=drive_user_delete_purges_drive
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('logic/drive_folder_create_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$tombstone = (int)User::USER_DELETED;

harness_set_setting_mem('drive_active', '1');

$owner = make_user('drivepurge');
$owner_id = (int)$owner->key;
$session = SessionControl::get_instance();
$session->set_api_user($owner_id);
harness_defer(function () use ($session) { $session->clear_api_user(); });

// A non-Drive file we create is the one row that legitimately outlives the
// member (re-homed under the tombstone); remove it ourselves at the end.
$upload_id = null;
harness_defer(function () use (&$upload_id) {
	if ($upload_id) {
		$f = new File((int)$upload_id, true);
		if ($f->key) { $f->permanent_delete(); }
	}
});

// ---------------------------------------------------------------------------
section('A member with a Drive tree and one non-Drive upload');

$r = drive_folder_create_logic(array('name' => 'Beta', 'parent_id' => 0));
check($r instanceof LogicResult && $r->error === null, 'root folder Beta created', (string)($r->error ?? ''));
$beta_id = (int)$r->data['folder']['id'];
$r = drive_folder_create_logic(array('name' => 'Child', 'parent_id' => $beta_id));
check($r instanceof LogicResult && $r->error === null, 'subfolder Child created');
$child_id = (int)$r->data['folder']['id'];

$in_child = File::createFromBytes('purge-' . bin2hex(random_bytes(6)), 'inside.txt', 'text/plain', $owner_id,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_fol_folder_id' => $child_id));
$at_root = File::createFromBytes('purge-' . bin2hex(random_bytes(6)), 'notes.txt', 'text/plain', $owner_id,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE, 'fil_fol_folder_id' => null));
$upload = File::createFromBytes('purge-' . bin2hex(random_bytes(6)), 'avatar.txt', 'text/plain', $owner_id,
	array('fil_private' => true, 'fil_source' => 'upload', 'fil_fol_folder_id' => null));
$upload_id = (int)$upload->key;
check($in_child->key && $at_root->key && $upload->key, 'two Drive files and one upload created');

$blob_in_child = (int)$in_child->get('fil_fbb_file_blob_id');
$blob_at_root  = (int)$at_root->get('fil_fbb_file_blob_id');

$count = function ($sql, $params) use ($db) {
	$q = $db->prepare($sql);
	$q->execute($params);
	return (int)$q->fetchColumn();
};
$tomb_folders_before = $count("SELECT COUNT(*) FROM fol_folders WHERE fol_usr_user_id = ?", array($tombstone));
$tomb_drive_files_before = $count("SELECT COUNT(*) FROM fil_files WHERE fil_usr_user_id = ? AND fil_source = ?", array($tombstone, File::SOURCE_DRIVE));

// ---------------------------------------------------------------------------
section('Permanent delete takes the Drive with the member');

$session->clear_api_user();
$deleted = false;
$why = '';
try {
	$deleted = (bool)$owner->permanent_delete();
} catch (Throwable $e) {
	$why = $e->getMessage();
}
check($deleted, 'the member is permanently deleted without a unique violation', $why);

check($count("SELECT COUNT(*) FROM usr_users WHERE usr_user_id = ?", array($owner_id)) === 0, 'the user row is gone');
check($count("SELECT COUNT(*) FROM fol_folders WHERE fol_folder_id IN (?, ?)", array($beta_id, $child_id)) === 0,
	'both folders are gone — not re-homed anywhere');
check($count("SELECT COUNT(*) FROM fil_files WHERE fil_file_id IN (?, ?)", array((int)$in_child->key, (int)$at_root->key)) === 0,
	'both Drive files are gone — the one in a folder and the one at the root');
// Bytes are reclaimed by the deferred blob sweep; what the delete itself must
// do is let go of the reference, so nothing pins those blobs any more.
check($count("SELECT COUNT(*) FROM fbb_file_blobs WHERE fbb_file_blob_id IN (?, ?) AND fbb_reference_count > 0", array($blob_in_child, $blob_at_root)) === 0,
	'their blob references were released (no other file shared those blobs)');

check($count("SELECT COUNT(*) FROM fol_folders WHERE fol_usr_user_id = ?", array($tombstone)) === $tomb_folders_before,
	'the tombstone user gained no folders');
check($count("SELECT COUNT(*) FROM fil_files WHERE fil_usr_user_id = ? AND fil_source = ?", array($tombstone, File::SOURCE_DRIVE)) === $tomb_drive_files_before,
	'the tombstone user gained no Drive files');

// The upload is not Drive content: it is what the set_value rule is for.
check($count("SELECT COUNT(*) FROM fil_files WHERE fil_file_id = ? AND fil_usr_user_id = ?", array($upload_id, $tombstone)) === 1,
	'a non-Drive upload is re-homed under the tombstone, as before');

harness_finish();
