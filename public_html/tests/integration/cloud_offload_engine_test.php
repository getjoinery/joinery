<?php
/** @joinery-test
 * name: cloud_offload_engine
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Engine parity test — CloudOffloadEngine over a mock profile + mock driver.
 *
 * Drives the public batch entry points (syncBatch / reverseBatch) against an
 * ISOLATED scratch table and a temp-dir filesystem, so the destructive steps
 * (flip + unlink, pull + commit + bucket delete) are exercised without
 * touching real fil_files rows or real uploads. Covers: forward push → flip →
 * unlink; missing-on-disk → counter + stays local; the failure-count cap;
 * mid-flight ineligibility undo; reverse pull-back with inverse ordering; and
 * the self-deactivate-when-empty signal.
 *
 * Run: php tests/integration/cloud_offload_engine_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(__DIR__ . '/../lib/cloud_fixtures.php'); // RecordingMockDriver, ScratchTableProfile

$TABLE = 'cloud_offload_engine_test_rows';
$BASE  = sys_get_temp_dir() . '/cloud_engine_test_' . bin2hex(random_bytes(4));
mkdir($BASE . '/disk', 0777, true);
mkdir($BASE . '/restore', 0777, true);

$dblink = DbConnector::get_instance()->get_db_link();

function make_disk_file($base, $id) {
	$dir = $base . '/disk/' . $id;
	if (!is_dir($dir)) mkdir($dir, 0777, true);
	file_put_contents($dir . '/original', "local-bytes-$id\n");
}

// A scratch-table profile whose eligibility is a boolean `eligible` column.
$profile = new ScratchTableProfile($TABLE, $BASE, [
	'eligibility_where' => 'eligible = true',
	'is_eligible'       => function ($r) {
		return $r['eligible'] === true || $r['eligible'] === 't' || $r['eligible'] === '1';
	},
]);

try {
	$dblink->exec("DROP TABLE IF EXISTS $TABLE");
	$dblink->exec("CREATE TABLE $TABLE (
		id BIGSERIAL PRIMARY KEY,
		drv VARCHAR(32),
		failed INT DEFAULT 0,
		last_attempt TIMESTAMP,
		eligible BOOLEAN DEFAULT TRUE
	)");

	$ins = function($drv, $failed = 0, $eligible = true) use ($dblink, $TABLE) {
		$q = $dblink->prepare("INSERT INTO $TABLE (drv, failed, eligible) VALUES (?, ?, ?) RETURNING id");
		$q->execute([$drv, $failed, $eligible ? 't' : 'f']);
		return (int)$q->fetchColumn();
	};
	$drvflag = function($id) use ($dblink, $TABLE) {
		$q = $dblink->prepare("SELECT drv FROM $TABLE WHERE id = ?"); $q->execute([$id]); return $q->fetchColumn();
	};
	$failcount = function($id) use ($dblink, $TABLE) {
		$q = $dblink->prepare("SELECT failed FROM $TABLE WHERE id = ?"); $q->execute([$id]); return (int)$q->fetchColumn();
	};

	section('CloudOffloadEngine parity (mock profile + mock driver)');

	// --- FORWARD ---------------------------------------------------------
	$ok1  = $ins('local'); make_disk_file($BASE, $ok1);   // pushes
	$ok2  = $ins('local'); make_disk_file($BASE, $ok2);   // pushes
	$miss = $ins('local');                                // no disk file → fail
	$capd = $ins('local', CloudOffloadEngine::FAILED_COUNT_CAP); make_disk_file($BASE, $capd); // excluded
	$mid  = $ins('local'); make_disk_file($BASE, $mid);   // mid-flight undo

	$fwd = new RecordingMockDriver();
	$fwd->on_put = function($key) use ($dblink, $TABLE, $mid) {
		if ($key === $mid . '/original') {
			$dblink->prepare("UPDATE $TABLE SET eligible = false WHERE id = ?")->execute([$mid]);
		}
	};
	$res = CloudOffloadEngine::syncBatch($profile, $fwd);

	// status is 'error' here precisely because the batch contains one deliberate
	// missing-on-disk row (failed>0) — the same rule the original task applied.
	ok('forward: status error (one deliberate failure in batch)', $res['status'] === 'error');
	ok('forward: message reports pushed=2 failed=1', strpos($res['message'], 'pushed=2') !== false && strpos($res['message'], 'failed=1') !== false);
	ok('forward: ok rows flipped to cloud', $drvflag($ok1) === 'cloud' && $drvflag($ok2) === 'cloud');
	ok('forward: ok local bytes deleted', !file_exists("$BASE/disk/$ok1/original") && !file_exists("$BASE/disk/$ok2/original"));
	ok('forward: missing-on-disk stays local', $drvflag($miss) === 'local');
	ok('forward: missing-on-disk counter incremented', $failcount($miss) === 1);
	ok('forward: capped row never pushed (stays local)', $drvflag($capd) === 'local');
	ok('forward: capped local bytes untouched', file_exists("$BASE/disk/$capd/original"));
	ok('forward: mid-flight row undone (stays local)', $drvflag($mid) === 'local');
	ok('forward: mid-flight push was deleted (undo)', count(array_filter($fwd->ops('delete'), fn($c) => $c['key'] === $mid . '/original')) === 1);
	ok('forward: mid-flight local bytes NOT deleted', file_exists("$BASE/disk/$mid/original"));

	// --- REVERSE ---------------------------------------------------------
	// ok1/ok2 are now 'cloud'. Pull them back.
	$rev = new RecordingMockDriver();
	$rres = CloudOffloadEngine::reverseBatch($profile, $rev);
	ok('reverse: status success', $rres['status'] === 'success');
	ok('reverse: rows flipped to local', $drvflag($ok1) === 'local' && $drvflag($ok2) === 'local');
	ok('reverse: bytes restored to local_path', file_exists("$BASE/restore/$ok1/original") && file_exists("$BASE/restore/$ok2/original"));
	$ops = array_map(fn($c) => $c['op'], $rev->calls);
	$g = array_search('get', $ops, true); $d = array_search('delete', $ops, true);
	ok('reverse: get precedes bucket delete (inverse ordering)', $g !== false && $d !== false && $g < $d);

	// --- DEACTIVATE WHEN EMPTY ------------------------------------------
	$dblink->exec("UPDATE $TABLE SET drv = 'local' WHERE drv = 'cloud'");
	$empty = CloudOffloadEngine::reverseBatch($profile, new RecordingMockDriver());
	ok('reverse: zero cloud rows → deactivate signal', !empty($empty['deactivate']) && $empty['deactivate'] === true);

	// --- PARTIAL-PUSH FAILURE (multi-object row, a later object's PUT fails) --
	// A row with two objects (original + a 'thumb' variant); the driver fails the
	// variant. The engine must roll the whole row back: delete the original it
	// already pushed (no bucket orphan), leave the row 'local', and bump its
	// failure counter. This exercises the partial-push cleanup path the failure-
	// injection knob was built for — otherwise never triggered by a 1-object row.
	section('Partial-push failure rolls the row back (no orphan)');
	$multi_profile = new ScratchTableProfile($TABLE, $BASE, [
		'eligibility_where' => 'eligible = true',
		'is_eligible'       => function ($r) { return $r['eligible'] === true || $r['eligible'] === 't' || $r['eligible'] === '1'; },
		'variants'          => ['thumb'],
	]);
	$pf = $ins('local');
	make_disk_file($BASE, $pf);                                  // original
	file_put_contents("$BASE/disk/$pf/thumb", "thumb-bytes-$pf\n"); // variant
	$pdriver = new RecordingMockDriver();
	$pdriver->fail_keys = [$pf . '/thumb'];                      // the 2nd object PUT fails
	$sync_row = new ReflectionMethod('CloudOffloadEngine', '_sync_row');
	$pres = $sync_row->invoke(null, $multi_profile, $pf, $pdriver);
	ok('partial-push: row reported failed', $pres === 'failed');
	ok('partial-push: the already-pushed original was deleted (rollback)',
		count(array_filter($pdriver->ops('delete'), function ($c) use ($pf) { return $c['key'] === $pf . '/original'; })) === 1);
	ok('partial-push: every pushed object was cleaned up (no bucket orphan)',
		count($pdriver->ops('delete')) === count(array_filter($pdriver->ops('put'), function ($c) use ($pf) { return $c['key'] === $pf . '/original'; })));
	ok('partial-push: row stays local', $drvflag($pf) === 'local');
	ok('partial-push: failure counter incremented', $failcount($pf) === 1);
	ok('partial-push: local bytes NOT deleted', file_exists("$BASE/disk/$pf/original"));

} finally {
	$dblink->exec("DROP TABLE IF EXISTS $TABLE");
	// remove temp dir tree
	$rrmdir = function($dir) use (&$rrmdir) {
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $e) {
			if ($e === '.' || $e === '..') continue;
			$p = $dir . '/' . $e;
			is_dir($p) ? $rrmdir($p) : @unlink($p);
		}
		@rmdir($dir);
	};
	$rrmdir($BASE);
}

harness_finish();
