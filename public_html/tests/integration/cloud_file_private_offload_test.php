<?php
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

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

$pass = 0; $fail = 0;
function ok($label, $cond) {
	global $pass, $fail;
	if ($cond) { echo "PASS: $label\n"; $pass++; }
	else       { echo "FAIL: $label\n"; $fail++; }
}

$TABLE = 'cloud_file_private_test_rows';
$dblink = DbConnector::get_instance()->get_db_link();

/** Mock driver: records ops; get() writes bytes so reverse placement succeeds. */
class PrivMockDriver implements CloudStorageDriver {
	public $calls = [];
	public function putMany(array $items): array {
		$out = [];
		foreach ($items as $it) { $this->calls[] = ['op'=>'put','key'=>$it['remote_key']]; $out[$it['remote_key']] = true; }
		return $out;
	}
	public function put(string $l, string $k, string $c): void { $this->calls[] = ['op'=>'put','key'=>$k]; }
	public function get(string $k, string $l): void {
		$this->calls[] = ['op'=>'get','key'=>$k];
		if (!is_dir(dirname($l))) mkdir(dirname($l), 0777, true);
		file_put_contents($l, "bytes:$k\n");
	}
	public function delete(string $k): void { $this->calls[] = ['op'=>'delete','key'=>$k]; }
	public function url(string $k): string { return 'https://mock-bucket.example/' . $k; }
	public function ping(): array { return ['ok'=>true,'message'=>'mock']; }
}

/**
 * Two mock profiles over the SAME scratch table, distinguished by a `kind`
 * column ('pub' | 'priv'), each owning its slice via reverseEligibilityWhere().
 * This mirrors how FileStorageProfile / FilePrivateStorageProfile share fil_files.
 */
class PartProfile implements StorageProfile {
	public $table; public $base; public $own;
	public function __construct($table, $base, $own) { $this->table = $table; $this->base = $base; $this->own = $own; }
	public function table(): string { return $this->table; }
	public function pkeyColumn(): string { return 'id'; }
	public function driverColumn(): string { return 'drv'; }
	public function failedCountColumn(): string { return 'failed'; }
	public function lastAttemptColumn(): string { return 'last_attempt'; }
	public function visibility(): string { return $this->own === 'priv' ? 'private' : 'public'; }
	public function eligibilityWhere(): string { return "kind = '{$this->own}'"; }
	public function reverseEligibilityWhere(): string { return "kind = '{$this->own}'"; }
	private function _row($id) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT * FROM {$this->table} WHERE id = ?"); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC);
	}
	public function rowExists(int $id): bool { return (bool)$this->_row($id); }
	public function isEligibleRow(int $id): bool {
		$r = $this->_row($id); if (!$r) return false;
		$local = ($r['drv'] === null || $r['drv'] === '' || $r['drv'] === 'local');
		return $local && $r['kind'] === $this->own;
	}
	public function itemsForRow(int $id): ?array {
		$path = $this->base . '/disk/' . $id . '/original';
		if (!file_exists($path)) return null;
		return [['local_path'=>$path,'remote_key'=>$id.'/original','content_type'=>'application/octet-stream']];
	}
	public function reverseItemsForRow(int $id): array {
		return [['remote_key'=>$id.'/original','local_path'=>$this->base.'/restore/'.$id.'/original','content_type'=>'application/octet-stream']];
	}
}

$BASE = sys_get_temp_dir() . '/cloud_file_priv_' . bin2hex(random_bytes(4));
mkdir($BASE . '/disk', 0777, true);

try {
	echo "=== A. Reverse ownership-gate partition (shared table) ===\n";
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

	$privProfile = new PartProfile($TABLE, $BASE, 'priv');
	$pubProfile  = new PartProfile($TABLE, $BASE, 'pub');

	// Drain the PRIVATE store only.
	$rev = new PrivMockDriver();
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
	$empty = CloudOffloadEngine::reverseBatch($privProfile, new PrivMockDriver());
	ok('private drain: empty ⇒ deactivate signal (per-store)', !empty($empty['deactivate']));
	$pubres = CloudOffloadEngine::reverseBatch($pubProfile, new PrivMockDriver());
	ok('public drain: still had the public row (no premature deactivate)', empty($pubres['deactivate']));
	ok('public drain: public row now local', $drvflag($pub1) === 'local');

	echo "\n=== B. File::get_url() never emits a bucket URL for a private cloud file ===\n";
	// Restricted (min_permission) cloud file → must return the local /uploads path.
	$priv_file = new File(NULL);
	$priv_file->set('fil_name', 'secret-doc.pdf', false);
	$priv_file->set('fil_type', 'application/pdf', false);
	$priv_file->set('fil_storage_driver', 'cloud', false);
	$priv_file->set('fil_min_permission', 5, false);
	$url = $priv_file->get_url('original', 'short');
	ok('private cloud get_url: not a bucket URL', strpos($url, 'http') !== 0 && strpos($url, 'mock-bucket') === false);
	ok('private cloud get_url: routes through /uploads or upload_web_dir', strpos($url, 'secret-doc.pdf') !== false);
	ok('private cloud is_public() == false', $priv_file->is_public() === false);

	// A file with no restrictions is public → is_public() true (would take the
	// bucket-URL branch when a driver is configured).
	$pub_file = new File(NULL);
	$pub_file->set('fil_name', 'open.png', false);
	$pub_file->set('fil_storage_driver', 'cloud', false);
	ok('unrestricted file is_public() == true', $pub_file->is_public() === true);

} finally {
	$dblink->exec("DROP TABLE IF EXISTS $TABLE");
	$rrmdir = function($dir) use (&$rrmdir) {
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $e) { if ($e==='.'||$e==='..') continue; $p="$dir/$e"; is_dir($p)?$rrmdir($p):@unlink($p); }
		@rmdir($dir);
	};
	$rrmdir($BASE);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
