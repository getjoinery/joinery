<?php
/** @joinery-test
 * name: cloud_storage_live_b2
 * tier: live
 * env: prod-verify
 * needs: [b2]
 */
/**
 * COMPREHENSIVE integration test — real S3 driver + real bucket + real DB.
 *
 * Drives the actual stack end-to-end, closing the gaps the mock-driver suites
 * leave open:
 *   A. Driver round-trip (put/get/ranged get/head/delete/url/ping) against the
 *      bucket, over S3Signer.
 *   B. Full offload cycle through CloudOffloadEngine with NO injected store
 *      (current() resolves the real one) — forward records the store and key,
 *      and a pull-back reads each row's own store with the latch off.
 *   C. The Save check end-to-end, the privacy gate among its steps (real
 *      anonymous read, private bucket).
 *   D. Privacy gate FAIL pipeline (real anonymous 2xx → reject) via a stand-in.
 *   E. Image rows: multi-object (original + variants) push + pull through bucket.
 *   F. BlobStorageProfile's real variant enumeration (forward + reverse).
 *   G. Per-row advisory-lock SKIP (a held lock makes the engine skip the row).
 *   H. Time-budget bound (skipped — would need a 60s run or a prod seam).
 *   I. saveStore() re-proves and stores the current store unchanged; setEnabled round-trip.
 *   J. Declarative registry over an on-disk (un-activated) plugin — proves the
 *      deactivation-hole closure, cross-profile summation, that a file in
 *      another profile fixes the store's location, and that a profile
 *      answering public is refused.
 *
 * Bucket writes are scratch objects under a unique prefix; DB writes are in
 * dedicated temp tables or self-cleaned fixture rows; settings mutations are
 * snapshot-restored. Credentials are read from settings and never printed. The
 * real bucket is NEVER made public-readable.
 *
 * Run: php tests/integration/cloud_storage_live_b2_test.php
 *
 * @version 3.0 - the file store is a target row (specs/storage_targets.md WP6): its credentials come from the
 *                current store's row; the driver is S3Signer's and takes full keys (head, ranged get; no
 *                putMany); rows record their store and key; R1 replaces the binding guard
 * @version 2.2 - profiles answer lastErrorColumn(); the scratch tables carry last_error
 * @version 2.1 - one store: the factory's single binding, the Save check's steps, the registry's refusal
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();


function set_enabled_mem($value) {
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$arr = $ref->getValue($gv);
	$arr['cloud_storage_enabled'] = $value;
	$ref->setValue($gv, $arr);
	CloudStorageDriverFactory::reset();
}

$store_target = CloudStorageDriverFactory::currentTarget();
if ($store_target === null) {
	harness_skip('file store configured', 'no file store is set up; nothing to test');
	harness_finish();
}
$opts = CloudStorageDriverFactory::options($store_target) + ['prefix' => $store_target->prefix()];

$RUN    = bin2hex(random_bytes(4));
$NAME   = '_livetest_' . $RUN;                          // under the store's folder
$PREFIX = $store_target->prefix() . '/' . $NAME;        // the full key prefix
$BASE   = sys_get_temp_dir() . '/cloud_live_' . $RUN;
@mkdir($BASE . '/disk', 0777, true);
@mkdir($BASE . '/dl', 0777, true);
@mkdir($BASE . '/restore', 0777, true);

$driver        = CloudStorageDriverFactory::fromOptions($opts);
$settings      = Globalvars::get_instance();
$dblink        = DbConnector::get_instance()->get_db_link();

// Cleanup trackers.
$created_keys     = [];
$temp_tables      = [];
$created_file_ids = [];
$created_blob_ids = [];
$file_disk_paths  = [];
$temp_plugin_dir  = null;
$lock_conn        = null;
$lock_id          = null;
$enabled_snapshot = $settings->get_setting('cloud_storage_enabled');

/** Generic profile over a scratch table; items configurable for 1 or N objects. */
class LiveProfile implements StorageProfile {
	public $table; public $base; public $prefix; public $variants;
	/** @param string $p the relative folder its names go under, inside the store's */
	public function __construct($t, $b, $p, $variants = []) { $this->table = $t; $this->base = $b; $this->prefix = $p; $this->variants = $variants; }
	public function table(): string { return $this->table; }
	public function pkeyColumn(): string { return 'id'; }
	public function driverColumn(): string { return 'drv'; }
	public function failedCountColumn(): string { return 'failed'; }
	public function lastAttemptColumn(): string { return 'last_attempt'; }
	public function lastErrorColumn(): string   { return 'last_error'; }
	public function targetColumn(): string      { return 'target_id'; }
	public function remoteKeyColumn(): string   { return 'remote_key'; }
	public function visibility(): string { return 'private'; }
	public function eligibilityWhere(): string { return 'eligible = true'; }
	private function _row($id) { $db = DbConnector::get_instance()->get_db_link(); $q = $db->prepare("SELECT * FROM {$this->table} WHERE id=?"); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC); }
	public function rowExists(int $id): bool { return (bool)$this->_row($id); }
	public function isEligibleRow(int $id): bool {
		$r = $this->_row($id); if (!$r) return false;
		$local = ($r['drv'] === null || $r['drv'] === '' || $r['drv'] === 'local');
		return $local && ($r['eligible'] === true || $r['eligible'] === 't' || $r['eligible'] === '1');
	}
	private function _keys() { return array_merge(['original'], $this->variants); }
	public function itemsForRow(int $id): ?array {
		$out = [];
		foreach ($this->_keys() as $k) {
			$p = $this->base . '/disk/' . $id . '/' . $k;
			if ($k === 'original' && !file_exists($p)) return null;
			if (!file_exists($p)) continue;
			$out[] = ['local_path' => $p, 'name' => $this->prefix . '/row' . $id . '/' . $k, 'content_type' => 'text/plain'];
		}
		return $out;
	}
	/** Every object beside the recorded key of the original. */
	public function reverseItemsForRow(int $id): array {
		$r = $this->_row($id);
		$folder = substr((string)$r['remote_key'], 0, -strlen('original'));
		$out = [];
		foreach ($this->_keys() as $k) {
			$out[] = ['remote_key' => $folder . $k, 'name' => $this->prefix . '/row' . $id . '/' . $k, 'local_path' => $this->base . '/restore/' . $id . '/' . $k, 'content_type' => 'text/plain'];
		}
		return $out;
	}
}

/** No-op driver for the lock-skip test: records puts, never touches a bucket. */
class NoopDriver implements CloudStorageDriver {
	public $puts = [];
	public function put(string $l, string $k, string $c): void { $this->puts[] = $k; }
	public function get(string $k, string $l): void { if (!is_dir(dirname($l))) mkdir(dirname($l), 0777, true); file_put_contents($l, "x"); }
	public function get_range(string $k, string $l, int $s, int $e): void { if (!is_dir(dirname($l))) mkdir(dirname($l), 0777, true); file_put_contents($l, substr("x", $s, $e - $s + 1)); }
	public function delete(string $k): void {}
	public function url(string $k): string { return 'noop://' . $k; }
	public function ping(): array { return ['ok' => true, 'message' => 'noop']; }
	public function head(string $k): ?array { return null; }
}

$drvflag = function($table, $id) use ($dblink) { $q = $dblink->prepare("SELECT drv FROM $table WHERE id=?"); $q->execute([$id]); return $q->fetchColumn(); };

try {
	section('A. Real driver round-trip');
	ok('ping ok', $driver->ping()['ok'] === true);
	$local_a = $BASE . '/a.txt'; file_put_contents($local_a, "live-a-$RUN\n");
	$key_a = $PREFIX . '/a.txt';
	$driver->put($local_a, $key_a, 'text/plain'); $created_keys[] = $key_a;
	$dl_a = $BASE . '/dl/a.txt'; $driver->get($key_a, $dl_a);
	ok('put then get returns identical bytes', file_get_contents($dl_a) === "live-a-$RUN\n");
	ok('url() includes bucket + full key', strpos($driver->url($key_a), $opts['bucket']) !== false && strpos($driver->url($key_a), $key_a) !== false);
	$head = $driver->head($key_a);
	ok('head: the size and the MD5 the provider reports', $head !== null && $head['size'] === strlen("live-a-$RUN\n") && $head['etag'] === md5("live-a-$RUN\n"), json_encode($head));
	ok('head of an absent key is null', $driver->head($PREFIX . '/never-written.txt') === null);
	$span = $BASE . '/dl/a.span'; $driver->get_range($key_a, $span, 5, 8);
	ok('a ranged get brings only the span', file_get_contents($span) === substr("live-a-$RUN\n", 5, 4));
	$driver->delete($key_a);
	$threw = false; try { $driver->get($key_a, $BASE . '/dl/gone.txt'); } catch (Exception $e) { $threw = true; }
	ok('get after delete fails (object gone)', $threw);
	$created_keys = array_values(array_diff($created_keys, [$key_a]));

	section('B. Full offload cycle through the engine + real driver');
	$TABLE = 'cloud_live_rows_' . $RUN; $temp_tables[] = $TABLE;
	$dblink->exec("CREATE TABLE $TABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP, last_error VARCHAR(255), target_id BIGINT, remote_key VARCHAR(1024), eligible BOOLEAN DEFAULT TRUE)");
	$ids = [];
	for ($i = 0; $i < 2; $i++) {
		$id = (int)$dblink->query("INSERT INTO $TABLE (drv) VALUES ('local') RETURNING id")->fetchColumn(); $ids[] = $id;
		@mkdir("$BASE/disk/$id", 0777, true); file_put_contents("$BASE/disk/$id/original", "row-$id-$RUN\n");
		$created_keys[] = "$PREFIX/row$id/original";
	}
	$profile = new LiveProfile($TABLE, $BASE, $NAME);
	set_enabled_mem('1');
	$fwd = CloudOffloadEngine::syncBatch($profile);
	ok('forward: status success', $fwd['status'] === 'success');
	ok('forward: both rows flipped to cloud', $drvflag($TABLE, $ids[0]) === 'cloud' && $drvflag($TABLE, $ids[1]) === 'cloud');
	$rec = $dblink->query("SELECT target_id, remote_key FROM $TABLE WHERE id = " . (int)$ids[0])->fetch(PDO::FETCH_ASSOC);
	ok('forward: the row records the store and its key under the store\'s folder', (int)$rec['target_id'] === (int)$store_target->key
		&& $rec['remote_key'] === "$PREFIX/row{$ids[0]}/original", json_encode($rec));
	ok('forward: local bytes deleted after flip', !file_exists("$BASE/disk/{$ids[0]}/original"));
	$probe = $BASE . '/dl/row0.txt'; $driver->get("$PREFIX/row{$ids[0]}/original", $probe);
	ok('forward: object readable from bucket', file_get_contents($probe) === "row-{$ids[0]}-$RUN\n");
	set_enabled_mem('0');
	ok('precondition: no store for new offloads when disabled', CloudStorageDriverFactory::current() === null);
	$rev = CloudOffloadEngine::reverseBatch($profile);
	ok('reverse (latch off): status success', $rev['status'] === 'success');
	ok('reverse (latch off): rows flipped back to local', $drvflag($TABLE, $ids[0]) === 'local' && $drvflag($TABLE, $ids[1]) === 'local');
	ok('reverse (latch off): bytes pulled back from the store the rows named', file_exists("$BASE/restore/{$ids[0]}/original") && file_get_contents("$BASE/restore/{$ids[0]}/original") === "row-{$ids[0]}-$RUN\n");

	section('C. The Save check end-to-end (real anonymous read)');
	$gate = CloudStorageLifecycle::testConnection($opts);
	$vstep = null; foreach ($gate['steps'] as $s) { if ($s['label'] === CloudStorageLifecycle::STEP_PRIVATE) $vstep = $s; }
	ok('gate: overall ok (bucket is private)', $gate['ok'] === true);
	ok('gate: anonymous read DENIED ⇒ pass', $vstep && $vstep['status'] === 'pass');
	$labels = array_map(fn($s) => $s['label'], $gate['steps']);
	ok('gate: own bucket, reach, write, private, delete', array_slice($labels, -4) === ['Reach', 'Write', 'Private', 'Delete'] && $labels[0] === 'Its own bucket');

	section('D. Privacy gate FAIL pipeline (real anonymous 2xx stand-in)');
	$status = BucketCheck::anonymous_status('https://www.google.com/generate_204');
	if ($status === 0) { harness_skip('FAIL pipeline', 'no outbound network to the 2xx stand-in URL'); }
	else {
		ok('real anonymous fetch parses a 2xx status', $status >= 200 && $status < 300);
		ok('a 2xx anonymous read ⇒ gate FAILS', CloudStorageLifecycle::privacyVerdict($status)['pass'] === false);
	}

	section('E. Image rows: multi-object (original + variants) through the bucket');
	$ITABLE = 'cloud_live_img_' . $RUN; $temp_tables[] = $ITABLE;
	$dblink->exec("CREATE TABLE $ITABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP, last_error VARCHAR(255), target_id BIGINT, remote_key VARCHAR(1024), eligible BOOLEAN DEFAULT TRUE)");
	$iid = (int)$dblink->query("INSERT INTO $ITABLE (drv) VALUES ('local') RETURNING id")->fetchColumn();
	@mkdir("$BASE/disk/$iid", 0777, true);
	foreach (['original', 'avatar', 'content'] as $k) { file_put_contents("$BASE/disk/$iid/$k", "img-$iid-$k\n"); $created_keys[] = "$PREFIX/row$iid/$k"; }
	$iprofile = new LiveProfile($ITABLE, $BASE, $NAME, ['avatar', 'content']);
	set_enabled_mem('1');
	$ifwd = CloudOffloadEngine::syncBatch($iprofile);
	ok('image: status success', $ifwd['status'] === 'success');
	ok('image: row flipped to cloud', $drvflag($ITABLE, $iid) === 'cloud');
	$all_in_bucket = true;
	foreach (['original', 'avatar', 'content'] as $k) { try { $driver->get("$PREFIX/row$iid/$k", "$BASE/dl/img_$k"); } catch (Exception $e) { $all_in_bucket = false; } }
	ok('image: original + both variants present in bucket', $all_in_bucket);
	set_enabled_mem('0');
	CloudOffloadEngine::reverseBatch($iprofile);
	ok('image: all 3 objects pulled back to local', file_exists("$BASE/restore/$iid/original") && file_exists("$BASE/restore/$iid/avatar") && file_exists("$BASE/restore/$iid/content"));

	section('F. BlobStorageProfile real variant enumeration');
	$upload_dir = $settings->get_setting('upload_dir');
	$fast_dir   = dirname($upload_dir) . '/static_files/uploads';
	$fname = '_varprofiletest_' . $RUN . '.png';
	$vb = new FileBlob(NULL);
	$vb->set('fbb_stored_name', $fname); $vb->set('fbb_size_bytes', 10);
	$vb->set('fbb_mime_type', 'image/png'); $vb->set('fbb_is_private', true);
	$vb->set('fbb_reference_count', 1); $vb->set('fbb_storage_driver', 'local');
	$vb->save(); $created_blob_ids[] = $vb->key;
	// A private blob's bytes live in the restricted dir. Place original + 2 of the
	// 5 variants on disk (leave 'hero' absent), keyed on the stored name.
	$paths = [$upload_dir . '/' . $fname, $upload_dir . '/avatar/' . $fname, $upload_dir . '/content/' . $fname];
	foreach ($paths as $p) { if (!is_dir(dirname($p))) @mkdir(dirname($p), 0777, true); file_put_contents($p, "png-bytes\n"); $file_disk_paths[] = $p; }
	$fp = new BlobStorageProfile();
	$fwd_items = $fp->itemsForRow((int)$vb->key);
	$fwd_keys  = array_map(fn($i) => $i['name'], $fwd_items);
	ok('BlobProfile.itemsForRow: original + present variants only', in_array($fname, $fwd_keys) && in_array("avatar/$fname", $fwd_keys) && in_array("content/$fname", $fwd_keys));
	ok('BlobProfile.itemsForRow: absent variant excluded', !in_array("hero/$fname", $fwd_keys));
	// Offloaded, the blob records the key of its original; every size sits beside it.
	$dblink->prepare("UPDATE fbb_file_blobs SET fbb_storage_driver = 'cloud', fbb_remote_key = ? WHERE fbb_file_blob_id = ?")
		->execute(["$NAME/$fname", (int)$vb->key]);
	$rev_items = $fp->reverseItemsForRow((int)$vb->key);
	$rev_by_key = []; foreach ($rev_items as $i) { $rev_by_key[$i['remote_key']] = $i['local_path']; }
	ok('BlobProfile.reverseItemsForRow: original + every size, beside the recorded key', count($rev_items) === count($vb->variant_size_keys()) + 1);
	ok('BlobProfile.reverseItemsForRow: a private blob comes home to the restricted dir w/ size subpath', ($rev_by_key["$NAME/avatar/$fname"] ?? '') === "$upload_dir/avatar/$fname" && ($rev_by_key["$NAME/$fname"] ?? '') === "$upload_dir/$fname");

	section('G. Per-row advisory-lock SKIP');
	$GTABLE = 'cloud_live_lock_' . $RUN; $temp_tables[] = $GTABLE;
	$dblink->exec("CREATE TABLE $GTABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP, last_error VARCHAR(255), target_id BIGINT, remote_key VARCHAR(1024), eligible BOOLEAN DEFAULT TRUE)");
	$gid = (int)$dblink->query("INSERT INTO $GTABLE (drv) VALUES ('local') RETURNING id")->fetchColumn();
	@mkdir("$BASE/disk/$gid", 0777, true); file_put_contents("$BASE/disk/$gid/original", "lock-$gid\n");
	$gprofile = new LiveProfile($GTABLE, $BASE, $NAME);
	// Hold the row's advisory lock on a SEPARATE session (same-session locks are reentrant).
	$lock_conn = new PDO('pgsql:host=localhost port=5432 dbname=' . $settings->get_setting('dbname') . ' user=' . $settings->get_setting('dbusername') . ' password=' . $settings->get_setting('dbpassword'));
	$lock_id = $gid;
	$lk = $lock_conn->prepare("SELECT pg_try_advisory_lock(:k1, :k2) AS got"); $lk->execute([':k1' => CloudOffloadEngine::lockSpace($GTABLE), ':k2' => $gid]);
	$got = $lk->fetch(PDO::FETCH_ASSOC);
	ok('precondition: lock acquired on a separate session', !empty($got['got']));
	$noop = new NoopDriver();
	$noop_store = new CloudFileStore((int)$store_target->key, $store_target->prefix(), $noop);
	$gres = CloudOffloadEngine::syncBatch($gprofile, $noop_store);
	ok('lock held ⇒ row skipped (stays local)', $drvflag($GTABLE, $gid) === 'local');
	ok('lock held ⇒ nothing pushed', count($noop->puts) === 0);
	ok('lock held ⇒ message reports skipped', strpos($gres['message'], 'skipped=1') !== false);
	// Release and re-run: now it proceeds.
	$lock_conn->prepare("SELECT pg_advisory_unlock(:k1, :k2)")->execute([':k1' => CloudOffloadEngine::lockSpace($GTABLE), ':k2' => $gid]);
	$lock_id = null;
	$gres2 = CloudOffloadEngine::syncBatch($gprofile, $noop_store);
	ok('lock released ⇒ row now pushed (cloud)', $drvflag($GTABLE, $gid) === 'cloud' && count($noop->puts) === 1);

	section('H. Time-budget bound');
	harness_skip('TIME_BUDGET_SECONDS break', 'would require a 60s run or a production testability seam; verified by code review only');

	section('I. saveStore() of the current store, unchanged; setEnabled round-trip (snapshot-restore)');
	$read_enabled = function() use ($dblink) { $q = $dblink->query("SELECT stg_value FROM stg_settings WHERE stg_name='cloud_storage_enabled'"); return (string)$q->fetchColumn(); };
	$same = new BackupTarget($store_target->key, TRUE);
	$saved = CloudStorageLifecycle::saveStore($same, [
		'bkt_backup_target_id' => (int)$store_target->key, 'bkt_name' => $store_target->get('bkt_name'),
		'bkt_provider' => $store_target->get('bkt_provider'), 'bkt_bucket' => $store_target->get('bkt_bucket'),
		'bkt_path_prefix' => $store_target->prefix(), 'access_key' => $opts['access_key'],
		'region' => $opts['region'], 'endpoint' => $opts['endpoint'],
	], false);
	ok('saveStore(the same store, its key kept): the check passes and it is stored', $saved['ok'] === true, json_encode($saved['test_results']['steps'] ?? $saved['message']));
	CloudStorageLifecycle::setEnabled(true);
	ok('setEnabled(true) wrote 1', $read_enabled() === '1');
	CloudStorageLifecycle::setEnabled(false);
	ok('setEnabled(false) wrote 0', $read_enabled() === '0');

	section('J. Declarative registry, and a file in any profile fixes the store (on-disk, un-activated plugin)');
	$pname = '_tmpstoragetest_' . $RUN;
	$temp_plugin_dir = PathHelper::getIncludePath('plugins/' . $pname);
	$cls = 'TmpStoreProfile_' . $RUN;
	$JTABLE = 'cloud_live_reg_' . $RUN; $temp_tables[] = $JTABLE;
	@mkdir($temp_plugin_dir . '/includes', 0777, true);
	file_put_contents($temp_plugin_dir . '/plugin.json', json_encode(['name' => $pname, 'version' => '1.0.0', 'storage_profiles' => [$cls]]));
	$class_src = "<?php\nclass $cls implements StorageProfile {\n"
		. "  public function table(): string { return '$JTABLE'; }\n"
		. "  public function pkeyColumn(): string { return 'id'; }\n"
		. "  public function driverColumn(): string { return 'drv'; }\n"
		. "  public function failedCountColumn(): string { return 'failed'; }\n"
		. "  public function lastAttemptColumn(): string { return 'last_attempt'; }\n"
		. "  public function lastErrorColumn(): string { return 'last_error'; }\n"
		. "  public function targetColumn(): string { return 'target_id'; }\n"
		. "  public function remoteKeyColumn(): string { return 'remote_key'; }\n"
		. "  public function visibility(): string { return 'private'; }\n"
		. "  public function eligibilityWhere(): string { return ''; }\n"
		. "  public function rowExists(int \$id): bool { return false; }\n"
		. "  public function isEligibleRow(int \$id): bool { return false; }\n"
		. "  public function itemsForRow(int \$id): ?array { return null; }\n"
		. "  public function reverseItemsForRow(int \$id): array { return []; }\n}\n";
	file_put_contents($temp_plugin_dir . '/includes/' . $cls . '.php', $class_src);
	@chmod($temp_plugin_dir, 0777); @chmod($temp_plugin_dir . '/includes', 0777);
	@chmod($temp_plugin_dir . '/plugin.json', 0666); @chmod($temp_plugin_dir . '/includes/' . $cls . '.php', 0666);

	$dblink->exec("CREATE TABLE $JTABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP, last_error VARCHAR(255), target_id BIGINT, remote_key VARCHAR(1024))");

	StorageProfileRegistry::reset();
	$classes = array_map('get_class', StorageProfileRegistry::all());
	ok('registry sees on-disk profile from an UN-activated plugin (deactivation hole closed)', in_array($cls, $classes));
	ok('registry holds it beside the core profile', in_array('BlobStorageProfile', $classes));
	$pub_cls = 'TmpPublicProfile_' . $RUN;
	file_put_contents($temp_plugin_dir . '/includes/' . $pub_cls . '.php', str_replace([$cls, "'private'"], [$pub_cls, "'public'"], $class_src));
	@chmod($temp_plugin_dir . '/includes/' . $pub_cls . '.php', 0666);
	file_put_contents($temp_plugin_dir . '/plugin.json', json_encode(['name' => $pname, 'version' => '1.0.0', 'storage_profiles' => [$cls, $pub_cls]]));
	StorageProfileRegistry::reset();
	$classes = array_map('get_class', StorageProfileRegistry::all());
	ok('registry refuses a declared profile that answers public', !in_array($pub_cls, $classes) && in_array($cls, $classes));

	$baseline = CloudStorageLifecycle::cloudRowCount((int)$store_target->key);
	$dblink->exec("INSERT INTO $JTABLE (drv, target_id, remote_key) VALUES ('cloud', " . (int)$store_target->key . ", 'x')");
	$after = CloudStorageLifecycle::cloudRowCount((int)$store_target->key);
	ok('cloudRowCount sums across profiles (incl. the temp table)', $after === $baseline + 1);
	ok('a file in ANOTHER profile fixes the store\'s location too', strpos((new BackupTarget($store_target->key, TRUE))->location_refusal(), 'cannot change') !== false);

} finally {
	// Settings restore.
	try {
		$d = $dblink->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'cloud_storage_enabled'");
		$d->execute([$enabled_snapshot]);
	} catch (Exception $e) {}
	set_enabled_mem($enabled_snapshot);
	// Release a still-held advisory lock.
	if ($lock_conn && $lock_id !== null) { try { $lock_conn->prepare("SELECT pg_advisory_unlock(:k1, :k2)")->execute([':k1' => CloudOffloadEngine::lockSpace($GTABLE), ':k2' => $lock_id]); } catch (Exception $e) {} }
	$lock_conn = null;
	// Bucket objects.
	foreach (array_unique($created_keys) as $k) { try { $driver->delete($k); } catch (Exception $e) {} }
	// Temp tables.
	foreach ($temp_tables as $t) { try { $dblink->exec("DROP TABLE IF EXISTS $t"); } catch (Exception $e) {} }
	// Fixture File rows + their disk files.
	foreach ($file_disk_paths as $p) { if (is_file($p)) @unlink($p); }
	foreach ($created_file_ids as $id) { try { $dblink->prepare("DELETE FROM fil_files WHERE fil_file_id = ?")->execute([$id]); } catch (Exception $e) {} }
	foreach ($created_blob_ids as $id) { try { $dblink->prepare("DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?")->execute([$id]); } catch (Exception $e) {} }
	// Temp plugin dir.
	$rrm = function($d) use (&$rrm) { if (!is_dir($d)) return; foreach (scandir($d) as $e) { if ($e === '.' || $e === '..') continue; $p = "$d/$e"; is_dir($p) ? $rrm($p) : @unlink($p); } @rmdir($d); };
	if ($temp_plugin_dir) $rrm($temp_plugin_dir);
	StorageProfileRegistry::reset();
	$rrm($BASE);
}

harness_finish();
