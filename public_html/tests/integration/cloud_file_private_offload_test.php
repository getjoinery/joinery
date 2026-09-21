<?php
/** @joinery-test
 * name: cloud_file_private_offload
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Private-file offload test — the private store's rules at the file layer
 * (specs/cloud_storage_private_only.md).
 *
 *   A. Reverse ownership-gate partition. A profile whose table also holds rows
 *      that are not its own must drain ONLY the cloud rows that are. Exercised
 *      with two mock profiles over one scratch table, partitioned by an
 *      optional reverseEligibilityWhere() the engine probes via
 *      method_exists() — proving one profile's drain leaves the other's cloud
 *      rows alone and the empty-signal is per-profile.
 *
 *   B. File::get_url() never emits a bucket URL: a private cloud file's URL is
 *      the local /uploads/* path, which serve.php gate-streams, and a public
 *      file's URL is a local one. Enforced at the model.
 *
 *   C. A private cloud blob made public is local before its record says
 *      public: flipVisibility() pulls the bytes home (through the store's
 *      driver) and commits driver = local and is_private = false together.
 *
 * Run: php tests/integration/cloud_file_private_offload_test.php
 *
 * @version 2.0 - one private store: get_url() is always local; the flip-to-public invariant
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(__DIR__ . '/../lib/cloud_fixtures.php'); // RecordingMockDriver, InMemoryBlobDriver, ScratchTableProfile

$TABLE = 'cloud_file_private_test_rows';
$dblink = DbConnector::get_instance()->get_db_link();

/**
 * Two scratch-table profiles over the SAME table, distinguished by a `kind`
 * column ('pub' | 'priv'), each owning its slice via reverseEligibilityWhere().
 * This mirrors how BlobStorageProfile owns only the private rows of fbb_file_blobs.
 */
function part_profile(string $table, string $base, string $own): ScratchTableProfile {
	return new ScratchTableProfile($table, $base, [
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

	section('B. File::get_url() never emits a bucket URL');
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

	// A file with no restrictions is public; its URL is a local one whatever
	// the store's state — a public file is a local file.
	harness_set_setting_mem('cloud_storage_enabled', '1');
	harness_set_setting_mem('cloud_storage_endpoint', 's3.example.com');
	harness_set_setting_mem('cloud_storage_bucket', 'some-bucket');
	harness_set_setting_mem('cloud_storage_access_key', 'k');
	harness_set_setting_mem('cloud_storage_secret_key', 's');
	CloudStorageDriverFactory::reset();
	$pub_file = new File(NULL);
	$pub_file->set('fil_name', 'open.png', false);
	ok('unrestricted file is_public() == true', $pub_file->is_public() === true);
	$purl = $pub_file->get_url('original', 'short');
	ok('public file get_url: a local /uploads URL, with a store configured and enabled', strpos($purl, 'http') !== 0 && strpos($purl, 'some-bucket') === false && strpos($purl, 'open.png') !== false, $purl);

	section('C. A private cloud blob made public is local before its record says public');
	// The store's driver is the in-memory double, injected into the factory's
	// cache: the bytes live there under the blob's keys, as in a real bucket.
	$mock = new InMemoryBlobDriver();
	$flip_name = '_fliptest_' . bin2hex(random_bytes(4)) . '.bin';
	$flip_blob = new FileBlob(NULL);
	$flip_blob->set('fbb_stored_name', $flip_name);
	$flip_blob->set('fbb_size_bytes', 12);
	$flip_blob->set('fbb_mime_type', 'application/octet-stream');
	$flip_blob->set('fbb_is_private', true);
	$flip_blob->set('fbb_reference_count', 1);
	$flip_blob->set('fbb_storage_driver', 'cloud');
	$flip_blob->save();
	$blob_fixture_ids[] = $flip_blob->key;
	$mock->objects[$flip_blob->remote_key_for('original')] = "secret bytes\n";
	$cache = new ReflectionProperty('CloudStorageDriverFactory', 'cached');
	$cache->setAccessible(true);
	$cache->setValue(null, $mock);
	$fast_dir = dirname(Globalvars::get_instance()->get_setting('upload_dir')) . '/static_files/uploads';
	$home = $fast_dir . '/' . $flip_name;
	try {
		$flip_blob->flipVisibility(false);
		$after = new FileBlob($flip_blob->key, true);
		ok('flip to public: the record reads local', $after->get('fbb_storage_driver') === 'local');
		ok('flip to public: the record reads public', $after->is_private_bool() === false);
		ok('flip to public: the bytes are on this server, in the fast-serve dir', is_file($home) && file_get_contents($home) === "secret bytes\n");
		ok('flip to public: the bucket no longer holds the object', !array_key_exists($flip_blob->remote_key_for('original'), $mock->objects));
	} finally {
		@unlink($home);
		CloudStorageDriverFactory::reset();
	}

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
