<?php
/** @joinery-test
 * name: harness_scratch_dir
 * tier: safe
 * env: any
 * needs: []
 * covers: [public_html/tests/lib/harness.php]
 */
/**
 * A suite's scratch directory is its run's own (B113).
 *
 * harness_scratch_dir(label) was one folder per label, so two runs of one
 * suite at once (two sessions, or a direct run beside the runner) wiped each
 * other's fixtures mid-run: installer_contract failed 35 and 9 checks for two
 * sessions at 23:40 on 10-24, and passed 957/957 alone. What this pins:
 *
 *  - the folder is cache/tests/<label>/<run_token>, the same on every call in
 *    a run, and another process gets another one;
 *  - a folder a dead run left (its run lock released) is swept by the next run
 *    that asks for the label; a live run's is never touched; one from before
 *    the lock is swept only once nothing has been written to it for a day;
 *  - a folder removed during the run (a suite's own cleanup, or teardown) is
 *    made again when it is asked for again;
 *  - the run locks themselves stay in the one folder every run shares.
 *
 * Run: php tests/unit/harness_scratch_dir_test.php
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$h = &$GLOBALS['__harness'];
$root = PathHelper::getSiteRoot() . '/cache/tests';

section('The folder is this run\'s own');
$a = harness_scratch_dir('b113_scratch');
check($a === $root . '/b113_scratch/' . $h['run_token'] && is_dir($a), 'it is cache/tests/<label>/<run_token>', $a);
check(harness_scratch_dir('b113_scratch') === $a, 'and the same on every call in the run');
// Another process, booting the harness as any suite does, gets its own.
$child = $a . '/child.php';
file_put_contents($child, "<?php\nrequire " . var_export(PathHelper::getSiteRoot() . '/public_html/tests/lib/harness.php', true)
	. ";\necho harness_scratch_dir('b113_scratch');\n");
$other = trim((string)shell_exec('php ' . escapeshellarg($child) . ' 2>/dev/null'));
check($other !== '' && $other !== $a && dirname($other) === dirname($a), 'another run of the same suite gets another folder beside it', $other);
if ($other !== '' && $other !== $a && dirname($other) === dirname($a)) { harness_remove_tree($other); }   // the child ran no teardown

section('Folders other runs left are swept only when nobody owns them');
$base = harness_shared_dir('b113_sweep');
$live_dir = harness_shared_dir('live');
$make = function ($token, $mtime = null) use ($base) {
	$d = $base . '/' . $token;
	@mkdir($d, 0770, true);
	file_put_contents($d . '/fixture', 'x');
	if ($mtime !== null) { touch($d, $mtime); }
	return $d;
};
$dead = bin2hex(random_bytes(4));
touch($live_dir . '/' . $dead);                     // its lock file is there, nobody holds it
$alive = bin2hex(random_bytes(4));
$lock = fopen($live_dir . '/' . $alive, 'c');
flock($lock, LOCK_EX | LOCK_NB);                     // held: a live run
$old_unknown = bin2hex(random_bytes(4));
$new_unknown = bin2hex(random_bytes(4));
$d_dead = $make($dead);
$d_alive = $make($alive, time() - 3 * 86400);
$d_old = $make($old_unknown, time() - 2 * 86400);
$d_new = $make($new_unknown);
$not_a_run = $base . '/not-a-run';
@mkdir($not_a_run);
harness_scratch_dir('b113_sweep');                   // the first ask for the label sweeps
check(!is_dir($d_dead), 'a dead run\'s folder is swept');
check(is_dir($d_alive), 'a live run\'s folder is kept, however old');
check(!is_dir($d_old), 'a folder with no run lock and nothing written for a day is swept');
check(is_dir($d_new), 'one with no run lock written today is kept');
check(is_dir($not_a_run), 'a folder not named for a run is left alone');
flock($lock, LOCK_UN);
fclose($lock);
foreach (array($d_alive, $d_new, $not_a_run) as $d) { harness_remove_tree($d); }
@unlink($live_dir . '/' . $dead);
@unlink($live_dir . '/' . $alive);

section('A folder removed during the run comes back when asked for');
harness_remove_tree($a);
check(!is_dir($a), 'removed by the suite\'s own cleanup');
check(harness_scratch_dir('b113_scratch') === $a && is_dir($a), 'and made again on the next ask');

section('The run locks stay in the folder every run shares');
check(is_file($live_dir . '/' . $h['run_token']), 'this run\'s lock is at cache/tests/live/<run_token>', $live_dir);
check(harness_run_token_state($h['run_token']) === 'live', 'and reads as live');

harness_finish();
