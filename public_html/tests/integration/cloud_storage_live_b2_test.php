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
 *   A. Driver round-trip (put/get/delete/putMany/url/ping) against the bucket.
 *   B. Full offload cycle through CloudOffloadEngine with NO injected driver
 *      (forVisibility resolves the real driver) — forward + a pull-back that
 *      exercises the reverse-driver FALLBACK (store "disabled").
 *   C. Privacy gate DENY path end-to-end (real anonymous read, private bucket).
 *   D. Privacy gate FAIL pipeline (real anonymous 2xx → reject) via a stand-in.
 *   E. Image rows: multi-object (original + variants) push + pull through bucket.
 *   F. BlobStorageProfile's real variant enumeration (forward + reverse).
 *   G. Per-row advisory-lock SKIP (a held lock makes the engine skip the row).
 *   H. Time-budget bound (skipped — would need a 60s run or a prod seam).
 *   I. persistSettings + setEnabled round-trip (guard-1 inside, latch, restore).
 *   J. Declarative registry + multi-profile guard-1 over an on-disk (un-activated)
 *      plugin — proves the deactivation-hole closure and cross-profile summation.
 *
 * Bucket writes are scratch objects under a unique prefix; DB writes are in
 * dedicated temp tables or self-cleaned fixture rows; settings mutations are
 * snapshot-restored. Credentials are read from settings and never printed. The
 * real bucket is NEVER made public-readable.
 *
 * Run: php tests/integration/cloud_storage_live_b2_test.php
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfileRegistry.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/BlobStorageProfile.php'));

function set_enabled_mem($value) {
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$arr = $ref->getValue($gv);
	$arr['cloud_storage_enabled'] = $value;
	$ref->setValue($gv, $arr);
	CloudStorageDriverFactory::reset();
}

$opts = CloudStorageDriverFactory::bindingFor('public');
if (empty($opts['bucket']) || empty($opts['access_key'])) {
	harness_skip('bucket configured', 'no bucket configured; nothing to test');
	harness_finish();
}

$RUN    = bin2hex(random_bytes(4));
$PREFIX = '_livetest_' . $RUN;
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
	public function __construct($t, $b, $p, $variants = []) { $this->table = $t; $this->base = $b; $this->prefix = $p; $this->variants = $variants; }
	public function table(): string { return $this->table; }
	public function pkeyColumn(): string { return 'id'; }
	public function driverColumn(): string { return 'drv'; }
	public function failedCountColumn(): string { return 'failed'; }
	public function lastAttemptColumn(): string { return 'last_attempt'; }
	public function visibility(): string { return 'public'; }
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
			$out[] = ['local_path' => $p, 'remote_key' => $this->prefix . '/row' . $id . '/' . $k, 'content_type' => 'text/plain'];
		}
		return $out;
	}
	public function reverseItemsForRow(int $id): array {
		$out = [];
		foreach ($this->_keys() as $k) {
			$out[] = ['remote_key' => $this->prefix . '/row' . $id . '/' . $k, 'local_path' => $this->base . '/restore/' . $id . '/' . $k, 'content_type' => 'text/plain'];
		}
		return $out;
	}
}

/** No-op driver for the lock-skip test: records puts, never touches a bucket. */
class NoopDriver implements CloudStorageDriver {
	public $puts = [];
	public function putMany(array $items): array { $o = []; foreach ($items as $i) { $this->puts[] = $i['remote_key']; $o[$i['remote_key']] = true; } return $o; }
	public function put(string $l, string $k, string $c): void { $this->puts[] = $k; }
	public function get(string $k, string $l): void { if (!is_dir(dirname($l))) mkdir(dirname($l), 0777, true); file_put_contents($l, "x"); }
	public function get_range(string $k, string $l, int $s, int $e): void { if (!is_dir(dirname($l))) mkdir(dirname($l), 0777, true); file_put_contents($l, substr("x", $s, $e - $s + 1)); }
	public function delete(string $k): void {}
	public function url(string $k): string { return 'noop://' . $k; }
	public function ping(): array { return ['ok' => true, 'message' => 'noop']; }
}

$drvflag = function($table, $id) use ($dblink) { $q = $dblink->prepare("SELECT drv FROM $table WHERE id=?"); $q->execute([$id]); return $q->fetchColumn(); };

try {
	section('A. Real driver round-trip');
	ok('ping (HeadBucket) ok', $driver->ping()['ok'] === true);
	$local_a = $BASE . '/a.txt'; file_put_contents($local_a, "live-a-$RUN\n");
	$key_a = $PREFIX . '/a.txt';
	$driver->put($local_a, $key_a, 'text/plain'); $created_keys[] = $key_a;
	$dl_a = $BASE . '/dl/a.txt'; $driver->get($key_a, $dl_a);
	ok('put then get returns identical bytes', file_get_contents($dl_a) === "live-a-$RUN\n");
	ok('url() includes bucket + prefixed key', strpos($driver->url($key_a), $opts['bucket']) !== false && strpos($driver->url($key_a), 'a.txt') !== false);
	$local_b = $BASE . '/b.txt'; file_put_contents($local_b, "live-b\n");
	$local_c = $BASE . '/c.txt'; file_put_contents($local_c, "live-c\n");
	$key_b = $PREFIX . '/b.txt'; $key_c = $PREFIX . '/c.txt';
	$res = $driver->putMany([
		['local_path' => $local_b, 'remote_key' => $key_b, 'content_type' => 'text/plain'],
		['local_path' => $local_c, 'remote_key' => $key_c, 'content_type' => 'text/plain'],
	]);
	$created_keys[] = $key_b; $created_keys[] = $key_c;
	ok('putMany reports both objects succeeded', ($res[$key_b] ?? null) === true && ($res[$key_c] ?? null) === true);
	$driver->delete($key_a);
	$threw = false; try { $driver->get($key_a, $BASE . '/dl/gone.txt'); } catch (Exception $e) { $threw = true; }
	ok('get after delete fails (object gone)', $threw);
	$created_keys = array_values(array_diff($created_keys, [$key_a]));

	section('B. Full offload cycle through the engine + real driver');
	$TABLE = 'cloud_live_rows_' . $RUN; $temp_tables[] = $TABLE;
	$dblink->exec("CREATE TABLE $TABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP, eligible BOOLEAN DEFAULT TRUE)");
	$ids = [];
	for ($i = 0; $i < 2; $i++) {
		$id = (int)$dblink->query("INSERT INTO $TABLE (drv) VALUES ('local') RETURNING id")->fetchColumn(); $ids[] = $id;
		@mkdir("$BASE/disk/$id", 0777, true); file_put_contents("$BASE/disk/$id/original", "row-$id-$RUN\n");
		$created_keys[] = "$PREFIX/row$id/original";
	}
	$profile = new LiveProfile($TABLE, $BASE, $PREFIX);
	set_enabled_mem('1');
	$fwd = CloudOffloadEngine::syncBatch($profile);
	ok('forward: status success', $fwd['status'] === 'success');
	ok('forward: both rows flipped to cloud', $drvflag($TABLE, $ids[0]) === 'cloud' && $drvflag($TABLE, $ids[1]) === 'cloud');
	ok('forward: local bytes deleted after flip', !file_exists("$BASE/disk/{$ids[0]}/original"));
	$probe = $BASE . '/dl/row0.txt'; $driver->get("$PREFIX/row{$ids[0]}/original", $probe);
	ok('forward: object readable from bucket', file_get_contents($probe) === "row-{$ids[0]}-$RUN\n");
	set_enabled_mem('');
	ok('precondition: forVisibility(public) null when disabled', CloudStorageDriverFactory::forVisibility('public') === null);
	$rev = CloudOffloadEngine::reverseBatch($profile);
	ok('reverse(fallback): status success', $rev['status'] === 'success');
	ok('reverse(fallback): rows flipped back to local', $drvflag($TABLE, $ids[0]) === 'local' && $drvflag($TABLE, $ids[1]) === 'local');
	ok('reverse(fallback): bytes pulled back from bucket', file_exists("$BASE/restore/{$ids[0]}/original") && file_get_contents("$BASE/restore/{$ids[0]}/original") === "row-{$ids[0]}-$RUN\n");

	section('C. Privacy gate DENY path end-to-end (real anonymous read)');
	$gate = CloudStorageLifecycle::testConnection($opts, 'private');
	$vstep = null; foreach ($gate['steps'] as $s) { if ($s['label'] === 'Verify NOT publicly readable') $vstep = $s; }
	ok('gate: overall ok (bucket is private)', $gate['ok'] === true);
	ok('gate: anonymous read DENIED ⇒ pass', $vstep && $vstep['status'] === 'pass');

	section('D. Privacy gate FAIL pipeline (real anonymous 2xx stand-in)');
	$anon = new ReflectionMethod('CloudStorageLifecycle', '_anonymous_status');
	$status = $anon->invoke(null, 'https://www.google.com/generate_204');
	if ($status === 0) { harness_skip('FAIL pipeline', 'no outbound network to the 2xx stand-in URL'); }
	else {
		ok('real anonymous fetch parses a 2xx status', $status >= 200 && $status < 300);
		ok('a 2xx anonymous read ⇒ gate FAILS', CloudStorageLifecycle::privacyVerdict($status)['pass'] === false);
	}

	section('E. Image rows: multi-object (original + variants) through the bucket');
	$ITABLE = 'cloud_live_img_' . $RUN; $temp_tables[] = $ITABLE;
	$dblink->exec("CREATE TABLE $ITABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP, eligible BOOLEAN DEFAULT TRUE)");
	$iid = (int)$dblink->query("INSERT INTO $ITABLE (drv) VALUES ('local') RETURNING id")->fetchColumn();
	@mkdir("$BASE/disk/$iid", 0777, true);
	foreach (['original', 'avatar', 'content'] as $k) { file_put_contents("$BASE/disk/$iid/$k", "img-$iid-$k\n"); $created_keys[] = "$PREFIX/row$iid/$k"; }
	$iprofile = new LiveProfile($ITABLE, $BASE, $PREFIX, ['avatar', 'content']);
	set_enabled_mem('1');
	$ifwd = CloudOffloadEngine::syncBatch($iprofile);
	ok('image: status success', $ifwd['status'] === 'success');
	ok('image: row flipped to cloud', $drvflag($ITABLE, $iid) === 'cloud');
	$all_in_bucket = true;
	foreach (['original', 'avatar', 'content'] as $k) { try { $driver->get("$PREFIX/row$iid/$k", "$BASE/dl/img_$k"); } catch (Exception $e) { $all_in_bucket = false; } }
	ok('image: original + both variants present in bucket', $all_in_bucket);
	set_enabled_mem('');
	CloudOffloadEngine::reverseBatch($iprofile);
	ok('image: all 3 objects pulled back to local', file_exists("$BASE/restore/$iid/original") && file_exists("$BASE/restore/$iid/avatar") && file_exists("$BASE/restore/$iid/content"));

	section('F. BlobStorageProfile real variant enumeration');
	$upload_dir = $settings->get_setting('upload_dir');
	$fast_dir   = dirname($upload_dir) . '/static_files/uploads';
	$fname = '_varprofiletest_' . $RUN . '.png';
	$vb = new FileBlob(NULL);
	$vb->set('fbb_stored_name', $fname); $vb->set('fbb_size_bytes', 10);
	$vb->set('fbb_mime_type', 'image/png'); $vb->set('fbb_is_private', false);
	$vb->set('fbb_reference_count', 1); $vb->set('fbb_storage_driver', 'local');
	$vb->save(); $created_blob_ids[] = $vb->key;
	// A public blob's bytes live in the fast-serve dir. Place original + 2 of the
	// 5 variants on disk (leave 'hero' absent), keyed on the stored name.
	$paths = [$fast_dir . '/' . $fname, $fast_dir . '/avatar/' . $fname, $fast_dir . '/content/' . $fname];
	foreach ($paths as $p) { if (!is_dir(dirname($p))) @mkdir(dirname($p), 0777, true); file_put_contents($p, "png-bytes\n"); $file_disk_paths[] = $p; }
	$fp = new BlobStorageProfile();
	$fwd_items = $fp->itemsForRow((int)$vb->key);
	$fwd_keys  = array_map(fn($i) => $i['remote_key'], $fwd_items);
	ok('BlobProfile.itemsForRow: original + present variants only', in_array($fname, $fwd_keys) && in_array("avatar/$fname", $fwd_keys) && in_array("content/$fname", $fwd_keys));
	ok('BlobProfile.itemsForRow: absent variant excluded', !in_array("hero/$fname", $fwd_keys));
	$rev_items = $fp->reverseItemsForRow((int)$vb->key);
	$rev_by_key = []; foreach ($rev_items as $i) { $rev_by_key[$i['remote_key']] = $i['local_path']; }
	ok('BlobProfile.reverseItemsForRow: enumerates original + all 5 sizes from scheme', count($rev_items) === 6);
	ok('BlobProfile.reverseItemsForRow: public placement to fast dir w/ size subpath', ($rev_by_key["avatar/$fname"] ?? '') === "$fast_dir/avatar/$fname" && ($rev_by_key[$fname] ?? '') === "$fast_dir/$fname");

	section('G. Per-row advisory-lock SKIP');
	$GTABLE = 'cloud_live_lock_' . $RUN; $temp_tables[] = $GTABLE;
	$dblink->exec("CREATE TABLE $GTABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP, eligible BOOLEAN DEFAULT TRUE)");
	$gid = (int)$dblink->query("INSERT INTO $GTABLE (drv) VALUES ('local') RETURNING id")->fetchColumn();
	@mkdir("$BASE/disk/$gid", 0777, true); file_put_contents("$BASE/disk/$gid/original", "lock-$gid\n");
	$gprofile = new LiveProfile($GTABLE, $BASE, $PREFIX);
	// Hold the row's advisory lock on a SEPARATE session (same-session locks are reentrant).
	$lock_conn = new PDO('pgsql:host=localhost port=5432 dbname=' . $settings->get_setting('dbname') . ' user=' . $settings->get_setting('dbusername') . ' password=' . $settings->get_setting('dbpassword'));
	$lock_id = $gid;
	$lk = $lock_conn->prepare("SELECT pg_try_advisory_lock(:k1, :k2) AS got"); $lk->execute([':k1' => CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE, ':k2' => $gid]);
	$got = $lk->fetch(PDO::FETCH_ASSOC);
	ok('precondition: lock acquired on a separate session', !empty($got['got']));
	$noop = new NoopDriver();
	$gres = CloudOffloadEngine::syncBatch($gprofile, $noop);
	ok('lock held ⇒ row skipped (stays local)', $drvflag($GTABLE, $gid) === 'local');
	ok('lock held ⇒ nothing pushed', count($noop->puts) === 0);
	ok('lock held ⇒ message reports skipped', strpos($gres['message'], 'skipped=1') !== false);
	// Release and re-run: now it proceeds.
	$lock_conn->prepare("SELECT pg_advisory_unlock(:k1, :k2)")->execute([':k1' => CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE, ':k2' => $gid]);
	$lock_id = null;
	$gres2 = CloudOffloadEngine::syncBatch($gprofile, $noop);
	ok('lock released ⇒ row now pushed (cloud)', $drvflag($GTABLE, $gid) === 'cloud' && count($noop->puts) === 1);

	section('H. Time-budget bound');
	harness_skip('TIME_BUDGET_SECONDS break', 'would require a 60s run or a production testability seam; verified by code review only');

	section('I. persistSettings + setEnabled round-trip (public, snapshot-restore)');
	// Read straight from the DB: persistSettings writes the row but (correctly)
	// does not refresh the in-memory singleton — the admin flow redirects.
	$read_enabled = function() use ($dblink) { $q = $dblink->query("SELECT stg_value FROM stg_settings WHERE stg_name='cloud_storage_enabled'"); return (string)$q->fetchColumn(); };
	$persist = CloudStorageLifecycle::persistSettings($opts, 'public', null);
	ok('persistSettings(public, same binding): ok', $persist['ok'] === true);
	ok('persistSettings latched cloud_storage_enabled=1', $read_enabled() === '1');
	CloudStorageLifecycle::setEnabled('public', false, null);
	ok('setEnabled(public,false) wrote 0', $read_enabled() === '0');

	section('J. Declarative registry + multi-profile guard-1 (on-disk, un-activated plugin)');
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
		. "  public function visibility(): string { return 'public'; }\n"
		. "  public function eligibilityWhere(): string { return ''; }\n"
		. "  public function rowExists(int \$id): bool { return false; }\n"
		. "  public function isEligibleRow(int \$id): bool { return false; }\n"
		. "  public function itemsForRow(int \$id): ?array { return null; }\n"
		. "  public function reverseItemsForRow(int \$id): array { return []; }\n}\n";
	file_put_contents($temp_plugin_dir . '/includes/' . $cls . '.php', $class_src);
	@chmod($temp_plugin_dir, 0777); @chmod($temp_plugin_dir . '/includes', 0777);
	@chmod($temp_plugin_dir . '/plugin.json', 0666); @chmod($temp_plugin_dir . '/includes/' . $cls . '.php', 0666);

	$dblink->exec("CREATE TABLE $JTABLE (id BIGSERIAL PRIMARY KEY, drv VARCHAR(32), failed INT DEFAULT 0, last_attempt TIMESTAMP)");

	StorageProfileRegistry::reset();
	$classes = array_map('get_class', StorageProfileRegistry::all());
	ok('registry sees on-disk profile from an UN-activated plugin (deactivation hole closed)', in_array($cls, $classes));
	$pub_classes = array_map('get_class', StorageProfileRegistry::forVisibility('public'));
	ok('registry groups it under its declared visibility', in_array($cls, $pub_classes) && in_array('BlobStorageProfile', $pub_classes));

	$baseline = CloudStorageLifecycle::cloudRowCount('public');
	$dblink->exec("INSERT INTO $JTABLE (drv) VALUES ('cloud')");
	$after = CloudStorageLifecycle::cloudRowCount('public');
	ok('cloudRowCount sums across profiles (incl. the temp table)', $after === $baseline + 1);
	$g1 = CloudStorageLifecycle::assertBindingMutable(['endpoint' => $opts['endpoint'], 'bucket' => 'a-different-bucket-' . $RUN], 'public');
	ok('guard-1 rejects bucket change while ANOTHER profile holds a cloud row', $g1['ok'] === false);

} finally {
	// Settings restore.
	try {
		$d = $dblink->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'cloud_storage_enabled'");
		$d->execute([$enabled_snapshot]);
	} catch (Exception $e) {}
	set_enabled_mem($enabled_snapshot);
	// Release a still-held advisory lock.
	if ($lock_conn && $lock_id !== null) { try { $lock_conn->prepare("SELECT pg_advisory_unlock(:k1, :k2)")->execute([':k1' => CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE, ':k2' => $lock_id]); } catch (Exception $e) {} }
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
