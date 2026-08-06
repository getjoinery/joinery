<?php
/** @joinery-test
 * name: drive_versions
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/file_versions_class.php'));
require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

/** Ingest arbitrary bytes as a fresh private blob (refcount 1). */
function make_blob($bytes) {
	$tmp = tempnam(sys_get_temp_dir(), 'drvver_');
	file_put_contents($tmp, $bytes);
	return FileBlob::createFromPath($tmp, 'application/octet-stream', true);
}
function version_count($file_id) {
	$m = new MultiFileVersion(array('file_id' => $file_id));
	return $m->count_all();
}
function head_blob($file_id) {
	$f = new File((int)$file_id, true);
	return (int)$f->get('fil_fbb_file_blob_id');
}

$made_files = array();
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) {
		$f = new File((int)$fid, true);
		if ($f->key) { $f->permanent_delete(); }
	}
});

// Enroll the user in a tier with a nonzero versioning depth.
$dblink = DbConnector::get_instance()->get_db_link();
$grp_id = $dblink->query("SELECT sbt_grp_group_id FROM sbt_subscription_tiers WHERE (sbt_features->>'drive_versioning_depth')::int > 0 AND sbt_delete_time IS NULL LIMIT 1")->fetchColumn();
if (!$grp_id) {
	section('Setup');
	harness_skip('a tier with drive_versioning_depth > 0 exists', 'configure a test tier to run the versions suite');
	harness_finish();
}

$user = make_user('driveversions');
$ins = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?) RETURNING grm_group_member_id");
$ins->execute(array((int)$grp_id, (int)$user->key));
harness_register_row('grm_group_members', 'grm_group_member_id', $ins->fetchColumn());

$depth = (int)SubscriptionTier::getUserFeature($user->key, 'drive_versioning_depth', 0);
check($depth > 0, 'test user has a versioning depth of ' . $depth);

$session = SessionControl::get_instance();
$session->set_api_user($user->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

// ---------------------------------------------------------------------------
section('version create + usage recompute');

$file = File::createFromBytes('v1-' . bin2hex(random_bytes(6)), 'doc.txt', 'text/plain', $user->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$made_files[] = $file->key;
$b1 = (int)$file->get('fil_fbb_file_blob_id');
DriveUsage::recompute($user->key);

$b2 = make_blob('v2-' . bin2hex(random_bytes(20)));
FileVersion::save_new_content($file, $b2, $user->key);
DriveUsage::recompute($user->key);
check(head_blob($file->key) === (int)$b2->key, 'head is the new blob after save_new_content');
check(version_count($file->key) === 1, 'one version row exists (the old head)');

$b3 = make_blob('v3-' . bin2hex(random_bytes(30)));
FileVersion::save_new_content($file, $b3, $user->key);
DriveUsage::recompute($user->key);
check(head_blob($file->key) === (int)$b3->key, 'head advanced to v3');
check(version_count($file->key) === 2, 'two version rows exist');

// Usage equals the recomputed sum of head + version blob sizes.
$usage = DriveUsage::for_user($user->key);
$expected = (int)$dblink->query("SELECT COALESCE(SUM(b.fbb_size_bytes),0) FROM fil_files f JOIN fbb_file_blobs b ON b.fbb_file_blob_id=f.fil_fbb_file_blob_id WHERE f.fil_usr_user_id=" . (int)$user->key)->fetchColumn()
          + (int)$dblink->query("SELECT COALESCE(SUM(fvr_size_bytes),0) FROM fvr_file_versions WHERE fvr_usr_user_id=" . (int)$user->key)->fetchColumn();
check((int)$usage->get('dru_bytes_used') === $expected, 'usage = head + versions bytes (' . $expected . ')');

// ---------------------------------------------------------------------------
section('the listing says what each version holds');

// Without a content hash a client can list a file's history and still not know
// which entry holds the bytes it is looking for. The sync client's no-loss check
// asks exactly that question of every superseded version, so an export carrying
// only sizes and timestamps makes a whole class of data loss unfalsifiable.
require_once(PathHelper::getIncludePath('logic/drive_versions_logic.php'));
$listing = drive_versions_logic(array('file_id' => (int)$file->key));
$rows = $listing->data['versions'] ?? array();
check(count($rows) === 2, 'the listing returns both versions');

$listed_hashes = array();
foreach ($rows as $row) {
	check(array_key_exists('content_sha256', $row), 'version ' . $row['version_number'] . ' reports a content hash');
	$listed_hashes[] = $row['content_sha256'];
}
$actual_hashes = $dblink->query(
	"SELECT b.fbb_sha256 FROM fvr_file_versions v JOIN fbb_file_blobs b ON b.fbb_file_blob_id = v.fvr_fbb_file_blob_id
	  WHERE v.fvr_fil_file_id = " . (int)$file->key . " ORDER BY v.fvr_version_number DESC"
)->fetchAll(PDO::FETCH_COLUMN);
check($listed_hashes === $actual_hashes, 'the hashes are the ones the versions actually point at, newest first');
check(count(array_unique(array_filter($listed_hashes))) === 2, 'the two versions hold different content');

// ---------------------------------------------------------------------------
section('version restore');

// Restore the oldest version (blob b1). The current head (b3) demotes to a version.
$oldest = null;
$vs = new MultiFileVersion(array('file_id' => $file->key), array('fvr_version_number' => 'ASC'));
$vs->load();
foreach ($vs as $v) { if ((int)$v->get('fvr_fbb_file_blob_id') === $b1) { $oldest = $v; break; } }
check($oldest !== null, 'found the version holding the original blob');

FileVersion::restore_version($file, $oldest, $user->key);
DriveUsage::recompute($user->key);
check(head_blob($file->key) === $b1, 'restore promoted the original blob to head');
check(version_count($file->key) === 2, 'still two versions after restore (head demoted, restored consumed)');

// ---------------------------------------------------------------------------
section('prune to versioning depth');

// Push well past the retained depth; prune must cap the version count.
for ($i = 0; $i < $depth + 3; $i++) {
	$nb = make_blob('bump-' . $i . '-' . bin2hex(random_bytes(10)));
	FileVersion::save_new_content($file, $nb, $user->key);
}
DriveUsage::recompute($user->key);
check(version_count($file->key) === $depth, 'version count is capped at the depth (' . $depth . ')');

// The head + exactly $depth versions should be all that bills against usage.
$blob_refs = (int)$dblink->query("SELECT COUNT(*) FROM fvr_file_versions WHERE fvr_fil_file_id=" . (int)$file->key)->fetchColumn();
check($blob_refs === $depth, 'exactly ' . $depth . ' version rows remain');

harness_finish();
