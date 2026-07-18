<?php
/** @joinery-test
 * name: cloud_file_private_offload
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Private-File offload test — the pieces file_private_storage.md adds on top of
 * the unified offload layer.
 *
 *   A. Reverse ownership-gate partition. When two stores share one table (the
 *      public and private File profiles both live on fil_files), each store's
 *      reverse/drain must touch ONLY the cloud rows physically in its bucket.
 *      Exercised here with two mock profiles over one scratch table, partitioned
 *      by an optional reverseEligibilityWhere() the engine probes via
 *      method_exists() — proving a private drain leaves public cloud rows alone
 *      and the empty-signal is per-store.
 *
 *   B. File::get_url() never emits a bucket URL for a restricted (private) cloud
 *      file — it returns the local /uploads/* path, which serve.php gate-streams.
 *      The "never url()" rule, enforced at the model.
 *
 * Run: php tests/integration/cloud_file_private_offload_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(__DIR__ . '/../lib/cloud_fixtures.php'); // RecordingMockDriver, ScratchTableProfile

$TABLE = 'cloud_file_private_test_rows';
$dblink = DbConnector::get_instance()->get_db_link();

/**
 * Two scratch-table profiles over the SAME table, distinguished by a `kind`
 * column ('pub' | 'priv'), each owning its slice via reverseEligibilityWhere().
 * This mirrors how BlobStorageProfile / BlobPrivateStorageProfile share fbb_file_blobs.
 */
function part_profile(string $table, string $base, string $own): ScratchTableProfile {
	return new ScratchTableProfile($table, $base, [
		'visibility'                => $own === 'priv' ? 'private' : 'public',
		'eligibility_where'         => "kind = '{$own}'",
		'reverse_eligibility_where' => "kind = '{$own}'",
		'is_eligible'               => function ($r) use ($own) { return $r['kind'] === $own; },
	]);
}

$BASE = sys_get_temp_dir() . '/cloud_file_priv_' . bin2hex(random_bytes(4));
mkdir($BASE . '/disk', 0777, true);
$blob_fixture_ids = array();

try {
	section('A. Reverse ownership-gate partition (shared table)');
	$dblink->exec("DROP TABLE IF EXISTS $TABLE");
	$dblink->exec("CREATE TABLE $TABLE (
		id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0,
		last_attempt TIMESTAMP, kind VARCHAR(8))");
	$ins = function($drv, $kind) use ($dblink, $TABLE) {
		$q = $dblink->prepare("INSERT INTO $TABLE (drv, kind) VALUES (?, ?) RETURNING id");
		$q->execute([$drv, $kind]); return (int)$q->fetchColumn();
	};
	$drvflag = function($id) use ($dblink, $TABLE) {
		$q = $dblink->prepare("SELECT drv FROM $TABLE WHERE id = ?"); $q->execute([$id]); return $q->fetchColumn();
	};

	// Three cloud rows already in their buckets: two private, one public.
	$priv1 = $ins('cloud', 'priv');
	$priv2 = $ins('cloud', 'priv');
	$pub1  = $ins('cloud', 'pub');

	$privProfile = part_profile($TABLE, $BASE, 'priv');
	$pubProfile  = part_profile($TABLE, $BASE, 'pub');

	// Drain the PRIVATE store only.
	$rev = new RecordingMockDriver();
	$res = CloudOffloadEngine::reverseBatch($privProfile, $rev);
	ok('private drain: status success', $res['status'] === 'success');
	ok('private drain: priv rows flipped to local', $drvflag($priv1) === 'local' && $drvflag($priv2) === 'local');
	ok('private drain: PUBLIC cloud row untouched', $drvflag($pub1) === 'cloud');
	$pulled_keys = array_column(array_filter($rev->calls, fn($c) => $c['op'] === 'get'), 'key');
	ok('private drain: only priv keys pulled', in_array($priv1.'/original',$pulled_keys,true)
		&& in_array($priv2.'/original',$pulled_keys,true)
		&& !in_array($pub1.'/original',$pulled_keys,true));

	// With private drained but a public cloud row remaining, the private reverse
	// now reports empty (deactivate) while the public store still has work.
	$empty = CloudOffloadEngine::reverseBatch($privProfile, new RecordingMockDriver());
	ok('private drain: empty ⇒ deactivate signal (per-store)', !empty($empty['deactivate']));
	$pubres = CloudOffloadEngine::reverseBatch($pubProfile, new RecordingMockDriver());
	ok('public drain: still had the public row (no premature deactivate)', empty($pubres['deactivate']));
	ok('public drain: public row now local', $drvflag($pub1) === 'local');

	section('B. File::get_url() never emits a bucket URL for a private cloud file');
	// Restricted (min_permission) file over a PRIVATE cloud blob → get_url must
	// return the local /uploads path, never the bucket URL (the "never url()" rule).
	$secret_name = 'secret-doc_' . bin2hex(random_bytes(4)) . '.pdf';
	$priv_blob = new FileBlob(NULL);
	$priv_blob->set('fbb_stored_name', $secret_name);
	$priv_blob->set('fbb_size_bytes', 100);
	$priv_blob->set('fbb_mime_type', 'application/pdf');
	$priv_blob->set('fbb_is_private', true);
	$priv_blob->set('fbb_reference_count', 1);
	$priv_blob->set('fbb_storage_driver', 'cloud');
	$priv_blob->save();
	$blob_fixture_ids[] = $priv_blob->key;

	$priv_file = new File(NULL);
	$priv_file->set('fil_name', $secret_name, false);
	$priv_file->set('fil_type', 'application/pdf', false);
	$priv_file->set('fil_min_permission', 5, false);
	$priv_file->set('fil_fbb_file_blob_id', $priv_blob->key, false);
	ok('private cloud storage_driver == cloud (via blob)', $priv_file->storage_driver() === 'cloud');
	$url = $priv_file->get_url('original', 'short');
	ok('private cloud get_url: not a bucket URL', strpos($url, 'http') !== 0 && strpos($url, 'mock-bucket') === false);
	ok('private cloud get_url: routes through /uploads or upload_web_dir', strpos($url, $secret_name) !== false);
	ok('private cloud is_public() == false', $priv_file->is_public() === false);

	// A file with no restrictions is public → is_public() true (would take the
	// bucket-URL branch when a driver is configured).
	$pub_file = new File(NULL);
	$pub_file->set('fil_name', 'open.png', false);
	ok('unrestricted file is_public() == true', $pub_file->is_public() === true);

} finally {
	foreach ($blob_fixture_ids as $bid) {
		$dblink->prepare("DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?")->execute([$bid]);
	}
	$dblink->exec("DROP TABLE IF EXISTS $TABLE");
	$rrmdir = function($dir) use (&$rrmdir) {
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $e) { if ($e==='.'||$e==='..') continue; $p="$dir/$e"; is_dir($p)?$rrmdir($p):@unlink($p); }
		@rmdir($dir);
	};
	$rrmdir($BASE);
}

harness_finish();
