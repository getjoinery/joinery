<?php
/** @joinery-test
 * name: gate_lock
 * tier: safe
 * env: dev-only
 * needs: []
 * covers: [public_html/tests/run.php]
 * timeout: 120
 */
/**
 * The gate: one run at a time per box, run records, and result reuse
 * (specs/gate_lock_and_shared_results.md; tests/lib/gate.php).
 *
 * The decision logic is exercised directly; the lock, the wait, the records
 * and end-to-end reuse are exercised by spawning the real runner against its
 * own gate directory (JOINERY_GATE_DIR) on one cheap declared suite, so the
 * real gate this test runs under is never touched. dev-only because the
 * directory override is honoured only with `debug` on.
 */
require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/coverage.php');
require_once(__DIR__ . '/../lib/gate.php');
harness_boot();

$ROOT = dirname(__DIR__, 2);          // public_html
$SITE_ROOT = dirname($ROOT);
$CHEAP = 'tests/unit/changed_selection_test.php';
$gate_dir = rtrim(sys_get_temp_dir(), '/') . '/joinery-gate-test-' . getmypid();
@mkdir($gate_dir, 0755, true);
harness_defer(function () use ($gate_dir) {
	foreach (glob($gate_dir . '/runs/*') ?: array() as $f) @unlink($f);
	@rmdir($gate_dir . '/runs');
	foreach (glob($gate_dir . '/*') ?: array() as $f) @unlink($f);
	@rmdir($gate_dir);
});

/** Run the real runner synchronously against the test's gate dir. */
$runner = function ($flags, $cutoff = null) use ($ROOT, $gate_dir) {
	$env = 'JOINERY_GATE_DIR=' . escapeshellarg($gate_dir) . ($cutoff !== null ? ' JOINERY_GATE_CUTOFF=' . (int)$cutoff : '');
	$cmd = $env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/tests/run.php') . ' ' . $flags . ' 2>&1';
	exec($cmd, $lines, $rc);
	return array('exit' => $rc, 'out' => implode("\n", $lines));
};
/** A helper process that holds the gate lock for $seconds and writes a holder file. */
$hold = function ($seconds) use ($gate_dir) {
	$code = '$f=fopen($argv[1]."/gate.lock","c"); flock($f,LOCK_EX);'
		. 'file_put_contents($argv[1]."/gate.holder.json", json_encode(array("pid"=>getmypid(),"session_name"=>"gatetest-holder",'
		. '"tier"=>"db","args"=>array("--changed"),"selected"=>42,"started_ts"=>time(),"run_id"=>"none")));'
		. 'fwrite(STDOUT,"held\n"); fflush(STDOUT); sleep((int)$argv[2]);';
	// exec: proc_open runs a string through sh; without it the pid and the
	// kill below would be the shell's, and PHP would keep the lock.
	$proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($gate_dir) . ' ' . (int)$seconds,
		array(1 => array('pipe', 'w')), $pipes);
	fgets($pipes[1]); // "held": the lock is taken
	return array('proc' => $proc, 'pipe' => $pipes[1], 'pid' => proc_get_status($proc)['pid']);
};

// ---------------------------------------------------------------------------
section('What counts as a complete reach');
$php_pass = array('path' => 'tests/unit/x_test.php', 'status' => 'pass', 'files' => array('public_html/includes/A.php'));
check(gate_reach_complete($php_pass, array('tier' => 'safe', 'env' => 'any', 'needs' => array())), 'a PHP pass with files reported is complete');
check(!gate_reach_complete(array('path' => 'tests/unit/x_test.php', 'status' => 'fail', 'files' => array()), array('tier' => 'safe', 'env' => 'any', 'needs' => array())),
	'a PHP result with no files (a crash) is not');
check(!gate_reach_complete(array_merge($php_pass, array('status' => 'fail', 'note' => 'timed out after 10s (killed)')), array('tier' => 'safe', 'env' => 'any', 'needs' => array())),
	'a timeout is not');
check(!gate_reach_complete($php_pass, array('tier' => 'safe', 'env' => 'any', 'needs' => array('dev-web'))), 'a dev-web suite is not (Apache-side reach is unseen)');
check(!gate_reach_complete($php_pass, array('tier' => 'live', 'env' => 'dev-only', 'needs' => array())), 'a live suite is never reused');
check(!gate_reach_complete($php_pass, array('tier' => 'deploy', 'env' => 'any', 'needs' => array())), 'a deploy suite is never reused');
check(!gate_reach_complete($php_pass, array('tier' => 'db', 'env' => 'prod-verify', 'needs' => array())), 'a prod-verify suite is never reused');
check(gate_reach_complete(array('path' => 'tests/x_gate.sh', 'status' => 'pass', 'note' => 'shell gate (exit-code only)', 'files' => array()), array('tier' => 'safe', 'env' => 'any', 'needs' => array())),
	'a shell gate is complete: its covers is its whole declaration');
check(!gate_reach_complete(array('path' => 'tests/x_gate.sh', 'status' => 'fail', 'note' => 'timed out after 5s (killed)', 'files' => array()), array('tier' => 'safe', 'env' => 'any', 'needs' => array())),
	'a timed-out shell gate is not');

// ---------------------------------------------------------------------------
section('The reuse decision');
$meta = array('tier' => 'safe', 'env' => 'any', 'needs' => array(), 'covers' => array());
$rel = 'tests/unit/x_test.php';
$res = array('status' => 'pass', 'reach_complete' => true,
	'files' => array('public_html/tests/unit/x_test.php', 'public_html/includes/A.php'), 'covers' => array('public_html/data/x/**'));
check(gate_reuse_refusal($res, $meta, $rel, array(), true) === '', 'identical trees: reusable');
check(gate_reuse_refusal($res, $meta, $rel, array('public_html/includes/B.php'), true) === '', 'a change outside the reach: reusable');
$why = gate_reuse_refusal($res, $meta, $rel, array('public_html/includes/A.php'), true);
check($why !== '' && strpos($why, 'loads') === 0, 'a change to a loaded file refuses, naming it', $why);
$why = gate_reuse_refusal($res, $meta, $rel, array('public_html/tests/unit/x_test.php'), true);
check($why !== '', 'the suite\'s own file changing refuses', $why);
$why = gate_reuse_refusal($res, $meta, $rel, array('public_html/data/x/deep/file.json'), true);
check($why !== '' && strpos($why, 'covers') === 0, 'a change under a covers glob refuses', $why);
$why = gate_reuse_refusal($res, $meta, $rel, array('public_html/tests/lib/gate.php'), true);
check($why !== '' && strpos($why, 'harness') !== false, 'the harness changing refuses everything', $why);
$why = gate_reuse_refusal($res, $meta, $rel, array('public_html/tests/unit/fixtures/thing.bin'), true);
check($why !== '', 'a fixture in the suite\'s area changing refuses', $why);
check(gate_reuse_refusal($res, $meta, $rel, array('public_html/includes/A.php'), true, array('public_html/includes/A.php' => true)) === '',
	'a comment-only change to a loaded file: reusable');
$why = gate_reuse_refusal($res, $meta, $rel, array('public_html/tests/unit/x_test.php'), true, array('public_html/tests/unit/x_test.php' => true));
check($why !== '', "a comment-only change to the suite's own file still refuses: its header is a comment", $why);
$fres = $res + array('functions' => array('a::kept' => true));
check(gate_reuse_refusal($fres, $meta, $rel, array('public_html/includes/A.php'), true, array(), array('public_html/includes/A.php' => array('a::other' => true))) === '',
	'a body change to a function the suite never ran: reusable');
$why = gate_reuse_refusal($fres, $meta, $rel, array('public_html/includes/A.php'), true, array(), array('public_html/includes/A.php' => array('a::kept' => true)));
check(strpos($why, 'runs a::kept') === 0, 'a body change to a function it ran refuses, naming it', $why);
$why = gate_reuse_refusal($fres, $meta, $rel, array('public_html/includes/A.php'), true, array(), array('public_html/includes/A.php' => null));
check(strpos($why, 'loads') === 0, 'a whole-file change refuses', $why);
$why = gate_reuse_refusal($res, $meta, $rel, array('public_html/includes/A.php'), true, array(), array('public_html/includes/A.php' => array('a::other' => true)));
check(strpos($why, 'loads') === 0, 'a result with no function set is file level', $why);
check(gate_reuse_refusal($res, $meta, $rel, array(), false) !== '', 'a different debug environment refuses');
check(gate_reuse_refusal(array('status' => 'pass', 'reach_complete' => false, 'files' => array()), $meta, $rel, array(), true) !== '', 'an incomplete reach refuses');
check(gate_reuse_refusal(array_merge($res, array('status' => 'fail')), $meta, $rel, array(), true) !== '',
	'a recorded fail is never borrowed: a red suite is re-proven on this tree (and a rerun alone diagnoses flakiness)');

// ---------------------------------------------------------------------------
section('The tree id');
$tree = gate_tree_id($SITE_ROOT);
check(is_string($tree) && preg_match('/^[0-9a-f]{40,64}$/', (string)$tree) === 1, 'the working tree has a tree id', (string)$tree);
exec('git -C ' . escapeshellarg($SITE_ROOT) . ' cat-file -t ' . escapeshellarg((string)$tree) . ' 2>/dev/null', $t, $rc);
check($rc === 0 && trim(implode('', $t)) === 'tree', 'git calls it a tree');
check(gate_changed_between($SITE_ROOT, $tree, $tree) === array(), 'a tree against itself changes nothing');
check(gate_changed_between($SITE_ROOT, $tree, str_repeat('0', 40)) === null, 'a tree git does not have answers null (never reusable)');
check(gate_tree_id($ROOT) === null, 'a directory that is not the repository top level has no tree id (reach paths would not line up)');
// A second tree differing from the working tree by one added file, built in
// a temporary index so the shared index and the working tree are untouched.
$probe_rel = 'public_html/zz_gate_probe_' . getmypid() . '.php';
$git = 'git -C ' . escapeshellarg($SITE_ROOT);
exec($git . ' rev-parse --git-dir', $gd); $git_dir = trim(implode('', $gd));
if ($git_dir[0] !== '/') $git_dir = $SITE_ROOT . '/' . $git_dir;
$tmp_index = tempnam(sys_get_temp_dir(), 'gate-test-index-');
copy($git_dir . '/index', $tmp_index);
$envi = 'GIT_INDEX_FILE=' . escapeshellarg($tmp_index) . ' ';
exec($envi . $git . ' add -A 2>&1');
exec('echo "<?php // probe" | ' . $git . ' hash-object -w --stdin', $bl); $blob = trim(implode('', $bl));
exec($envi . $git . ' update-index --add --cacheinfo 100644,' . $blob . ',' . escapeshellarg($probe_rel) . ' 2>&1', $ui, $rc_ui);
exec($envi . $git . ' write-tree', $wt); $tree2 = trim(implode('', $wt));
// Two more: the probe with only its comment changed, and with its code changed.
$probe_tree = function ($content) use ($git, $envi, $probe_rel) {
	exec('printf %s ' . escapeshellarg($content) . ' | ' . $git . ' hash-object -w --stdin', $b);
	exec($envi . $git . ' update-index --add --cacheinfo 100644,' . trim(implode('', $b)) . ',' . escapeshellarg($probe_rel) . ' 2>&1');
	exec($envi . $git . ' write-tree', $t);
	return trim(implode('', $t));
};
$tree3 = $probe_tree("<?php\n/* a different comment */\n");
$tree4 = $probe_tree("<?php echo 1;\n");
$tree5 = $probe_tree("<?php class P { function a() { return 1; } function b() { return 1; } }\n");
$tree6 = $probe_tree("<?php class P { function a() { return 2; } function b() { return 1; } }\n");
$tree7 = $probe_tree("<?php class P { const X = 1; function a() { return 2; } function b() { return 1; } }\n");
@unlink($tmp_index);
$diff = gate_changed_between($SITE_ROOT, $tree2, $tree, $cu);
check($rc_ui === 0 && $diff === array($probe_rel), 'a tree with one extra file differs by exactly that path', json_encode($diff));
check($cu === array(), 'an added file is never a comment-only change');
$diff = gate_changed_between($SITE_ROOT, $tree2, $tree3, $cu);
check($diff === array($probe_rel) && isset($cu[$probe_rel]), 'a PHP file whose comment changed differs, and is named comment-only', json_encode($cu));
$diff = gate_changed_between($SITE_ROOT, $tree2, $tree4, $cu);
check($diff === array($probe_rel) && $cu === array(), 'a PHP file whose code changed is not', json_encode($cu));
$diff = gate_changed_between($SITE_ROOT, $tree5, $tree6, $cu, $fc);
check($diff === array($probe_rel) && ($fc[$probe_rel] ?? null) === array('p::a' => true), 'a method body change between trees names the method', json_encode($fc));
$diff = gate_changed_between($SITE_ROOT, $tree6, $tree7, $cu, $fc);
check(array_key_exists($probe_rel, $fc) && $fc[$probe_rel] === null, 'a new constant between trees is a whole-file change', json_encode($fc));
$diff = gate_changed_between($SITE_ROOT, $tree2, $tree5, $cu, $fc);
check(array_key_exists($probe_rel, $fc) && $fc[$probe_rel] === null, 'and so is a file that turned into something else', json_encode($fc));

// ---------------------------------------------------------------------------
section('A run leaves a record');
$r1 = $runner('--only=' . $CHEAP);
check($r1['exit'] === 0, 'a one-suite run passes', $r1['out']);
check(preg_match('/^Run record: (\S+) \(below the cutoff, no lock\)/m', $r1['out'], $m) === 1, 'it names its record and that it took no lock');
$id1 = $m[1] ?? '';
$rec1 = json_decode((string)@file_get_contents($gate_dir . '/runs/' . $id1 . '.json'), true);
check(is_array($rec1) && ($rec1['tree'] ?? '') === $tree, 'the record carries the tree id of the working tree');
check(!empty($rec1['finished']) && ($rec1['totals']['tests'] ?? 0) === 1, 'the record is finished with totals');
$lines = array_values(array_filter(explode("\n", (string)@file_get_contents($gate_dir . '/runs/' . $id1 . '.jsonl')), 'strlen'));
$line1 = count($lines) === 1 ? json_decode($lines[0], true) : null;
check(is_array($line1) && $line1['path'] === $CHEAP && !empty($line1['reach_complete']) && count($line1['files']) > 3,
	'one result line, reach complete, with the files the suite loaded');

section('Reuse across runs');
$r2 = $runner('--only=' . $CHEAP);
check($r2['exit'] === 0 && strpos($r2['out'], 'Reused: 1 of 1 results from 1 earlier run (' . $id1 . ')') !== false,
	'the same tree reuses the result and names the run', $r2['out']);
check(strpos($r2['out'], '[reused: ') !== false, 'the result line says it was borrowed');
$r3 = $runner('--only=' . $CHEAP . ' --fresh');
check($r3['exit'] === 0 && strpos($r3['out'], 'Reused:') === false, '--fresh borrows nothing');
$r3j = $runner('--only=' . $CHEAP . ' --json');
$j = json_decode($r3j['out'], true);
check(is_array($j) && ($j['reuse']['reused'] ?? 0) === 1 && !empty($j['results'][0]['reused_from']['run_id']),
	'the JSON contract carries the reuse block and reused_from per result');

// A record whose tree differs by a file the suite loaded must not be reused;
// one whose tree differs only by a file it never loaded may be.
foreach (glob($gate_dir . '/runs/*') as $f) @unlink($f);
$newer = gmdate('Ymd\THis\Z', time() + 60) . '-1';
$rec = $rec1; $rec['tree'] = $tree2; $rec['run_id'] = $newer;
file_put_contents($gate_dir . '/runs/' . $newer . '.json', json_encode($rec));
$lr = $line1; $lr['files'][] = $probe_rel;
file_put_contents($gate_dir . '/runs/' . $newer . '.jsonl', json_encode($lr) . "\n");
$r4 = $runner('--only=' . $CHEAP);
check($r4['exit'] === 0 && strpos($r4['out'], 'Reused:') === false, 'a record whose tree changed a loaded file is not reused');
foreach (glob($gate_dir . '/runs/*') as $f) @unlink($f);
$newer2 = gmdate('Ymd\THis\Z', time() + 120) . '-2';
$rec['run_id'] = $newer2;
file_put_contents($gate_dir . '/runs/' . $newer2 . '.json', json_encode($rec));
file_put_contents($gate_dir . '/runs/' . $newer2 . '.jsonl', json_encode($line1) . "\n");
$r5 = $runner('--only=' . $CHEAP);
check($r5['exit'] === 0 && strpos($r5['out'], 'from 1 earlier run (' . $newer2 . ')') !== false,
	'a record whose tree changed only an unloaded file is reused', $r5['out']);
foreach (glob($gate_dir . '/runs/*') as $f) @unlink($f);

section('Old records are pruned');
$old = '20200101T000000Z-1';
file_put_contents($gate_dir . '/runs/' . $old . '.json', '{}');
file_put_contents($gate_dir . '/runs/' . $old . '.jsonl', '');
touch($gate_dir . '/runs/' . $old . '.json', time() - GATE_RECORD_TTL_S - 60);
gate_prune($gate_dir);
check(!file_exists($gate_dir . '/runs/' . $old . '.json') && !file_exists($gate_dir . '/runs/' . $old . '.jsonl'), 'a record past the TTL goes, both files');

// ---------------------------------------------------------------------------
section('One gate at a time');
$h = $hold(20);
$holder = gate_holder_read($gate_dir);
check(is_array($holder) && ($holder['session_name'] ?? '') === 'gatetest-holder', 'the holder is readable while the lock is held');
$nw = $runner('--only=' . $CHEAP . ' --fresh --no-wait', 0);
check($nw['exit'] === GATE_EXIT_HELD && strpos($nw['out'], 'Gate held by gatetest-holder') !== false,
	'--no-wait exits ' . GATE_EXIT_HELD . ' at once, naming the holder', $nw['out']);
$below = $runner('--only=' . $CHEAP . ' --fresh');
check($below['exit'] === 0 && strpos($below['out'], 'below the cutoff, no lock') !== false,
	'a run below the cutoff runs at once beside the holder');

// A run above the cutoff waits: start it, watch it wait, free the gate by
// killing the holder outright, and see it proceed.
$out = tmpfile();
$cmd = 'exec env JOINERY_GATE_DIR=' . escapeshellarg($gate_dir) . ' JOINERY_GATE_CUTOFF=0 ' . escapeshellarg(PHP_BINARY) . ' '
	. escapeshellarg($ROOT . '/tests/run.php') . ' --only=' . $CHEAP . ' --fresh';
$wp = proc_open($cmd, array(1 => $out, 2 => $out), $wpipes);
$wpid = proc_get_status($wp)['pid'];
$waiting = false;
for ($i = 0; $i < 100; $i++) {
	usleep(100000);
	clearstatcache();
	rewind($out);
	if (strpos((string)stream_get_contents($out), 'Gate held by gatetest-holder') !== false) { $waiting = true; break; }
}
check($waiting, 'a run above the cutoff waits, naming the holder');
$stat = @file_get_contents('/proc/' . $wpid . '/stat');
$nice = $stat !== false ? (int)explode(' ', substr($stat, strrpos($stat, ')') + 2))[16] : null;
check($nice !== null && $nice >= 10, 'the waiting runner already runs at nice 10 or lower priority', (string)$nice);
check(proc_get_status($wp)['running'], 'it is still waiting while the holder lives');
proc_terminate($h['proc'], 9); // SIGKILL: no teardown, the kernel frees the lock
fclose($h['pipe']); proc_close($h['proc']);
$t0 = microtime(true);
$exit = null;
for ($i = 0; $i < 300; $i++) {
	$st = proc_get_status($wp);
	if (!$st['running']) { $exit = $st['exitcode']; break; }
	usleep(100000);
}
$waited = microtime(true) - $t0;
rewind($out); $wout = stream_get_contents($out); fclose($out);
proc_close($wp);
check($exit === 0 && strpos($wout, 'Gate acquired after') !== false, 'a killed holder frees the gate and the waiter runs', $wout);
check($waited < 10, 'it proceeded within seconds of the kill', round($waited, 1) . 's');
check(file_exists($gate_dir . '/gate.holder.json') === false, 'the waiter cleared the holder file when it finished');
check(gate_holder_read($gate_dir) === null, 'nobody holds the gate now');
// A stale holder file (a killed holder's) is not believed.
file_put_contents($gate_dir . '/gate.holder.json', json_encode(array('pid' => 1, 'session_name' => 'ghost')));
check(gate_holder_read($gate_dir) === null, 'a holder file with no lock behind it is not believed');
@unlink($gate_dir . '/gate.holder.json');

harness_finish();
