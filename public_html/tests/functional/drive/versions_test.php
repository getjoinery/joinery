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
	// No deployment is obliged to ship a tier that keeps versions, and skipping
	// left this whole suite dark on any that does not -- including dev, where
	// the version code is actually edited. The suite provisions the condition it
	// needs instead, and takes it away again on the way out.
	section('Setup');
	$suffix = bin2hex(random_bytes(4));
	$gq = $dblink->prepare("INSERT INTO grp_groups (grp_name, grp_category) VALUES (?, ?) RETURNING grp_group_id");
	$gq->execute(array('drive-versions-test-' . $suffix, 'subscription'));
	$grp_id = (int)$gq->fetchColumn();
	harness_register_row('grp_groups', 'grp_group_id', $grp_id);

	$tq = $dblink->prepare(
		"INSERT INTO sbt_subscription_tiers
		   (sbt_grp_group_id, sbt_tier_level, sbt_name, sbt_display_name, sbt_features, sbt_is_active)
		 VALUES (?, ?, ?, ?, ?::jsonb, TRUE) RETURNING sbt_subscription_tier_id");
	$tq->execute(array($grp_id, 900, 'drive-versions-test-' . $suffix, 'Drive Versions Test',
		json_encode(array('drive_versioning_depth' => 3))));
	harness_register_row('sbt_subscription_tiers', 'sbt_subscription_tier_id', (int)$tq->fetchColumn());
	check($grp_id > 0, 'provisioned a tier that keeps versions, since this deployment ships none');
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

// ---------------------------------------------------------------------------
section('a demotion reads the head it is demoting, not a caller stale copy');

// Two uploads finishing at once both used to read the head from whatever the
// CALLER had loaded, so both filed a version row against the SAME blob and the
// second silently overwrote the head the first had just installed -- leaving
// that content referenced by nothing. On the soak rig, which keeps versions,
// this left 1,432 files holding one blob in two version rows.
$race = File::createFromBytes('race-' . bin2hex(random_bytes(6)), 'race.txt', 'text/plain', $user->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$made_files[] = $race->key;
$race_head = (int)$race->get('fil_fbb_file_blob_id');

// The copy a slower writer is still holding while a faster one commits.
$stale = new File((int)$race->key, true);

$blob_x = make_blob('race-x-' . bin2hex(random_bytes(10)));
$blob_y = make_blob('race-y-' . bin2hex(random_bytes(10)));

FileVersion::save_new_content(new File((int)$race->key, true), $blob_x, $user->key);
check(head_blob($race->key) === (int)$blob_x->key, 'race: the first save installed X as the head');

FileVersion::save_new_content($stale, $blob_y, $user->key);
check(head_blob($race->key) === (int)$blob_y->key, 'race: the second save installed Y as the head');

$dupq = $dblink->prepare(
	"SELECT COUNT(*) FROM (
	    SELECT fvr_fbb_file_blob_id FROM fvr_file_versions
	     WHERE fvr_fil_file_id = ?
	     GROUP BY fvr_fbb_file_blob_id HAVING COUNT(*) > 1) d");
$dupq->execute(array((int)$race->key));
check((int)$dupq->fetchColumn() === 0, 'race: no blob was demoted twice');

$holdq = $dblink->prepare('SELECT COUNT(*) FROM fvr_file_versions WHERE fvr_fil_file_id = ? AND fvr_fbb_file_blob_id = ?');
$holdq->execute(array((int)$race->key, (int)$blob_x->key));
check((int)$holdq->fetchColumn() === 1, 'race: the head the second save overtook is held by a version row');

$holdq->execute(array((int)$race->key, $race_head));
check((int)$holdq->fetchColumn() === 1, 'race: and so is the head the first save overtook');

// ---------------------------------------------------------------------------
section('concurrent demotions of one file, for real');

// The section above reproduces the race single-threaded, which proves the head
// is read fresh but not that the row is actually held. This races real
// processes: N workers each ingest their own blob and demote the same file at
// the same instant. Without the hold they interleave between reading the head
// and repointing the file, and two of them demote the same blob while the head
// one of them installed is left referenced by nothing.
if (!function_exists('proc_open')) {
	harness_skip('proc_open disabled -- cannot spawn concurrent workers');
} else {
	$hot = File::createFromBytes('hot-' . bin2hex(random_bytes(6)), 'hot.txt', 'text/plain', $user->key,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$made_files[] = $hot->key;
	$first_head = (int)$hot->get('fil_fbb_file_blob_id');

	$worker_src = <<<'WORKER'
<?php
$root = '__ROOT__';
require_once($root . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/file_versions_class.php'));

$file_id = (int)($argv[1] ?? 0);
$user_id = (int)($argv[2] ?? 0);
$start   = (float)($argv[3] ?? 0);
$tag     = (string)($argv[4] ?? 'x');

// Ingested BEFORE the barrier so the race is over the demotion, not over
// hashing bytes. This is the blob an upload would arrive holding.
$tmp = tempnam(sys_get_temp_dir(), 'hotblob_');
file_put_contents($tmp, 'hot-' . $tag . '-' . str_repeat($tag, 64));
$blob = FileBlob::createFromPath($tmp, 'text/plain', true);

// Each worker holds its own copy of the file, read before the race -- exactly
// the stale copy a real request would be carrying.
$file = new File($file_id, TRUE);

while (microtime(true) < $start) { /* spin */ }

try {
	FileVersion::save_new_content($file, $blob, $user_id);
	fwrite(STDOUT, "RESULT:OK:" . (int)$blob->key . "
");
} catch (\Throwable $e) {
	fwrite(STDOUT, "RESULT:FAIL:" . (int)$blob->key . ":" . $e->getMessage() . "
");
}
WORKER;
	$worker_src = str_replace('__ROOT__', PathHelper::getRootDir(), $worker_src);
	$worker_path = tempnam(sys_get_temp_dir(), 'dvc_') . '.php';
	file_put_contents($worker_path, $worker_src);
	@chmod($worker_path, 0666);
	harness_defer(function () use ($worker_path) { @unlink($worker_path); });

	$N = 6;
	$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$env = $_ENV;
	$env['PATH'] = getenv('PATH');
	$start_at = microtime(true) + 2.0;
	$procs = array(); $pipes = array(); $out = array();
	for ($i = 0; $i < $N; $i++) {
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker_path)
			. ' ' . (int)$hot->key . ' ' . (int)$user->key
			. ' ' . sprintf('%.6f', $start_at) . ' ' . chr(97 + $i);
		$procs[$i] = proc_open($cmd, $descriptors, $pipes[$i], null, $env);
	}
	$blob_ids = array(); $ok_blobs = array(); $failed = array();
	for ($i = 0; $i < $N; $i++) {
		if (!is_resource($procs[$i])) { continue; }
		$out[$i] = stream_get_contents($pipes[$i][1]);
		fclose($pipes[$i][1]); fclose($pipes[$i][2]);
		proc_close($procs[$i]);
		if (preg_match('/RESULT:(OK|FAIL):(\d+):?(.*)/', (string)$out[$i], $m)) {
			$blob_ids[] = (int)$m[2];
			if ($m[1] === 'OK') { $ok_blobs[] = (int)$m[2]; }
			else { $failed[] = substr(trim($m[3]), 0, 70); }
		}
	}
	check(count($blob_ids) === $N, "race: all $N workers reported (" . count($blob_ids) . ')');
	// A refused demotion is a legitimate outcome under contention -- the caller
	// retries. What must never happen is silent corruption of the bookkeeping.
	echo '  (workers: ' . count($ok_blobs) . ' saved, ' . count($failed) . ' refused'
		. (count($failed) ? ': ' . implode(' | ', array_unique($failed)) : '') . ")\n";

	// Whatever order they landed in, the bookkeeping must be consistent: no blob
	// demoted twice, and every blob that was ever the head is either the head now
	// or held by exactly one version row.
	$dq = $dblink->prepare(
		"SELECT COUNT(*) FROM (
		    SELECT fvr_fbb_file_blob_id FROM fvr_file_versions
		     WHERE fvr_fil_file_id = ?
		     GROUP BY fvr_fbb_file_blob_id HAVING COUNT(*) > 1) d");
	$dq->execute(array((int)$hot->key));
	check((int)$dq->fetchColumn() === 0, 'race: no blob was demoted twice under real contention');

	// Not 'every blob is still a version' -- prune legitimately deletes past the
	// retained depth, and a refused worker's blob is the CALLER's to release.
	// The invariant that holds regardless: none of these blobs may end up live
	// with nothing referencing it, which is the shape a lost demotion leaves.
	$stranded = array();
	foreach (array_merge(array($first_head), $blob_ids) as $bid) {
		$sq = $dblink->prepare(
			'SELECT COUNT(*) FROM fbb_file_blobs b
			  WHERE b.fbb_file_blob_id = ? AND b.fbb_reference_count > 0
			    AND NOT EXISTS (SELECT 1 FROM fil_files f WHERE f.fil_fbb_file_blob_id = b.fbb_file_blob_id)
			    AND NOT EXISTS (SELECT 1 FROM fvr_file_versions v WHERE v.fvr_fbb_file_blob_id = b.fbb_file_blob_id)');
		$sq->execute(array($bid));
		if ((int)$sq->fetchColumn() > 0) { $stranded[] = $bid; }
	}
	// A blob whose worker was REFUSED never reached save_new_content's bookkeeping,
	// so it is the caller's to release and not this invariant's business.
	$stranded = array_values(array_diff($stranded, array_diff($blob_ids, $ok_blobs)));
	check(count($stranded) === 0, 'race: no blob that was demoted is left referenced by nothing'
		. (count($stranded) ? ' (stranded: ' . implode(',', $stranded) . ')' : ''));
}

harness_finish();
