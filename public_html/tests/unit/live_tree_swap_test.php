<?php
/** @joinery-test
 * name: live_tree_swap
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * timeout: 120
 * covers: [public_html/includes/DeploymentHelper.php, public_html/utils/upgrade.php]
 */
/**
 * An upgrade swaps the staged tree in without public_html ever lacking serve.php.
 *
 * upgrade.php moved the live tree's contents out and the staged contents in
 * with two find-mv calls, and between them public_html had no serve.php: on
 * 0.8.523 a request landed in that gap on two of ten sites, a PHP fatal and a
 * 500 ('Failed opening required .../public_html/serve.php'). Every request is
 * rewritten to serve.php, so DeploymentHelper::swapLiveTree() (1.13) never
 * moves it out: a holding script takes its place by rename() and answers 503
 * while the rest moves, and the release's serve.php replaces it by rename().
 *
 * A second process watches serve.php for the whole swap, on a tree with a
 * thousand top-level entries. Checked: the watcher sees serve.php missing
 * during the old two-move swap (so it can see a gap) and never during
 * swapLiveTree; the holding script answers while the code moves, dated before
 * both real scripts (php-fpm's opcache tells a replaced file by its mtime to the
 * second, and one written in the same second as the release's serve.php went on
 * answering after the swap, proven against the dev pool); the result
 * is the release's tree live and the previous one in the backup, serve.php
 * included, with nothing left over; a run killed while holding keeps the real
 * serve.php, never the holding script; a failed backup move and a failed
 * deploy move each put the previous release back, its serve.php only once
 * the rest is back; a run killed after the old code left, then run again,
 * leaves a backup a rollback refuses rather than restoring nothing over the
 * site.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = sys_get_temp_dir() . '/live_tree_swap_' . getmypid();
register_shutdown_function(function () use ($root) {
	exec('chmod -R u+w ' . escapeshellarg($root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($root));
});

function lts_tree($dir, $serve, $tag, $count) {
	@mkdir($dir . '/includes', 0755, true);
	file_put_contents($dir . '/serve.php', $serve);
	file_put_contents($dir . '/includes/' . $tag . '.php', '<?php // ' . $tag);
	file_put_contents($dir . '/.' . $tag . '_dotfile', $tag);
	for ($i = 0; $i < $count; $i++) {
		file_put_contents($dir . '/' . $tag . '_' . $i . '.txt', $tag);
	}
}

function lts_fresh($root, $name, $count = 5) {
	$base = $root . '/' . $name;
	foreach (array('live', 'stage', 'backup') as $d) {
		@mkdir($base . '/' . $d, 0755, true);
	}
	lts_tree($base . '/live', "<?php echo 'OLD';\n", 'old', $count);
	lts_tree($base . '/stage', "<?php echo 'NEW';\n", 'new', $count);
	return $base;
}

/**
 * Watches $entry in a child process until $stop exists; returns [missing, seen
 * holding, holding script's mtime, times $old_serve answered with fewer than
 * $full entries in the tree].
 */
function lts_watch_start($entry, $stop, $report, $old_serve = null, $full = 0) {
	$pid = pcntl_fork();
	if ($pid === 0) {
		// A file read in the moment it is renamed away is expected here.
		set_error_handler(function () { return true; });
		$missing = 0;
		$holding = 0;
		$holding_time = null;
		$early = 0;
		while (!file_exists($stop)) {
			clearstatcache();
			if (!is_file($entry)) {
				$missing++;
			} elseif (DeploymentHelper::isHoldingScript($entry)) {
				$holding++;
				// The smallest seen: a stat may land just after the rename.
				clearstatcache();
				$t = @filemtime($entry);
				if ($t !== false && ($holding_time === null || $t < $holding_time)) {
					$holding_time = $t;
				}
			} elseif ($old_serve !== null && @file_get_contents($entry) === $old_serve
				&& count(@scandir(dirname($entry)) ?: array()) - 2 < $full) {
				$early++;
			}
		}
		file_put_contents($report, json_encode(array($missing, $holding, $holding_time, $early)));
		// Ends here: exit() would run the parent's shutdown functions (the
		// harness's result, this test's cleanup) in the child.
		posix_kill(getmypid(), SIGKILL);
	}
	usleep(50000);
	return $pid;
}

function lts_watch_stop($pid, $stop, $report) {
	touch($stop);
	pcntl_waitpid($pid, $status);
	return json_decode((string)@file_get_contents($report), true) ?: array(-1, -1, null, -1);
}

function lts_read($path) {
	return is_file($path) ? trim((string)file_get_contents($path)) : '(none)';
}

if (!function_exists('pcntl_fork')) {
	harness_skip('pcntl is not available to the CLI here');
	harness_finish();
}

section('The watcher sees the gap the old two-move swap left');

$b = lts_fresh($root, 'old_swap', 1000);
$pid = lts_watch_start("$b/live/serve.php", "$b/stop", "$b/report");
exec(sprintf('find %s -mindepth 1 -maxdepth 1 -exec mv -t %s {} +', escapeshellarg("$b/live"), escapeshellarg("$b/backup")));
exec(sprintf('find %s -mindepth 1 -maxdepth 1 -exec mv -t %s {} +', escapeshellarg("$b/stage"), escapeshellarg("$b/live")));
list($missing_old) = lts_watch_stop($pid, "$b/stop", "$b/report");
check($missing_old > 0, 'serve.php is missing for a moment in the old swap', "missing $missing_old times");

section('swapLiveTree never leaves public_html without serve.php');

$b = lts_fresh($root, 'swap', 1000);
$real_times = array(filemtime("$b/live/serve.php"), filemtime("$b/stage/serve.php"));
$pid = lts_watch_start("$b/live/serve.php", "$b/stop", "$b/report");
$swap = DeploymentHelper::swapLiveTree("$b/live", "$b/stage", "$b/backup", 1);
list($missing, $holding, $holding_time) = lts_watch_stop($pid, "$b/stop", "$b/report");
check($swap['swapped'] === true && $swap['error'] === null, 'the swap completes', json_encode($swap));
check($missing === 0, 'serve.php is there throughout', "missing $missing times");
check($holding > 0, 'the holding script answers while the code moves', "seen $holding times");
// opcache tells a replaced file by its mtime to the second: one shared with
// the release's serve.php kept the holding script answering after the swap.
check($holding_time !== null && $holding_time < min($real_times), 'the holding script is dated before both real scripts',
	json_encode(array('holding' => $holding_time, 'real' => $real_times)));
check(lts_read("$b/live/serve.php") === "<?php echo 'NEW';", 'the release\'s serve.php is live', lts_read("$b/live/serve.php"));
check(is_file("$b/live/includes/new.php") && !file_exists("$b/live/includes/old.php") && is_file("$b/live/.new_dotfile"),
	'the release\'s tree is live, dotfiles too');
check(lts_read("$b/backup/serve.php") === "<?php echo 'OLD';", 'the previous serve.php is in the backup', lts_read("$b/backup/serve.php"));
check(is_file("$b/backup/includes/old.php") && is_file("$b/backup/.old_dotfile") && is_file("$b/backup/old_999.txt"),
	'the previous tree is in the backup');
$left = array_merge(glob("$b/live/.serve.php.*") ?: array(), glob("$b/backup/.serve.php.*") ?: array());
check($left === array(), 'nothing of the hold is left behind', implode(', ', $left));
check(count(scandir("$b/stage")) === 2, 'staging is empty');

section('The holding script');

$holding_file = "$root/holding.php";
file_put_contents($holding_file, DeploymentHelper::holdingScript());
exec('php -l ' . escapeshellarg($holding_file) . ' 2>&1', $lint, $lint_exit);
check($lint_exit === 0, 'it is valid PHP', implode(' ', $lint));
$answer = (string)shell_exec('php ' . escapeshellarg($holding_file) . ' 2>&1');
check(strpos($answer, 'being updated') !== false && strpos($answer, 'refresh') !== false, 'it says the site is being updated, and reloads', $answer);
$src = DeploymentHelper::holdingScript();
check(strpos($src, 'http_response_code(503)') !== false && strpos($src, 'Retry-After') !== false, 'it answers 503 with Retry-After');
check(!preg_match('/\b(require|include)(_once)?\b/', $src), 'it includes nothing');

section('A run killed while holding keeps the real serve.php');

$b = lts_fresh($root, 'killed');
file_put_contents("$b/live/serve.php.tmp", DeploymentHelper::holdingScript());
link("$b/live/serve.php", "$b/live/" . DeploymentHelper::HELD_ENTRY_SCRIPT);
rename("$b/live/serve.php.tmp", "$b/live/serve.php");
$swap = DeploymentHelper::swapLiveTree("$b/live", "$b/stage", "$b/backup", 0);
check($swap['swapped'] === true, 'the next run swaps', json_encode($swap));
check(lts_read("$b/backup/serve.php") === "<?php echo 'OLD';", 'the backup holds the real serve.php, not the holding script', lts_read("$b/backup/serve.php"));

section('A failed backup move puts serve.php back');

$b = lts_fresh($root, 'backup_fails');
$swap = DeploymentHelper::swapLiveTree("$b/live", "$b/stage", "$b/no_such_backup", 0);
check($swap['swapped'] === false && $swap['step'] === 'backup', 'it reports the backup step', json_encode($swap));
check(lts_read("$b/live/serve.php") === "<?php echo 'OLD';" && is_file("$b/live/includes/old.php"), 'the live tree is as it was', lts_read("$b/live/serve.php"));
check(!file_exists("$b/live/" . DeploymentHelper::HELD_ENTRY_SCRIPT), 'nothing of the hold is left behind');

section('A failed deploy move puts the previous release back');

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	harness_skip('root moves a read-only directory anyway, so the failure cannot be made here');
} else {
	$b = lts_fresh($root, 'deploy_fails', 1000);
	$full = count(scandir("$b/live")) - 2;
	// Moving a directory to another parent needs write permission on it.
	mkdir("$b/stage/stuck", 0755);
	chmod("$b/stage/stuck", 0555);
	$pid = lts_watch_start("$b/live/serve.php", "$b/stop", "$b/report", "<?php echo 'OLD';\n", $full);
	$swap = DeploymentHelper::swapLiveTree("$b/live", "$b/stage", "$b/backup", 1);
	list($missing_r, , , $early) = lts_watch_stop($pid, "$b/stop", "$b/report");
	chmod("$b/stage/stuck", 0755);
	check($swap['swapped'] === false && $swap['step'] === 'deploy', 'it reports the deploy step', json_encode($swap));
	check($swap['restored'] === true, 'it says the previous release is back', json_encode($swap));
	check(lts_read("$b/live/serve.php") === "<?php echo 'OLD';" && is_file("$b/live/includes/old.php")
		&& !file_exists("$b/live/includes/new.php") && !file_exists("$b/live/new_0.txt"),
		'the previous release is live again, with nothing of the new one', lts_read("$b/live/serve.php"));
	check(count(scandir("$b/backup")) === 2, 'the backup is empty again');
	check($missing_r === 0, 'serve.php is there throughout the restore', "missing $missing_r times");
	check($early === 0, 'the previous serve.php answers only once its whole tree is back', "early $early times");
}

section('A run killed after the old code left, then run again, keeps a rollback from restoring nothing');

// Killed after live moved to the backup: live holds only the holding script,
// and the re-run cleared the backup (upgrade.php clears it before the swap).
$b = lts_fresh($root, 'killed_late');
exec('find ' . escapeshellarg("$b/live") . ' -mindepth 1 -delete');
file_put_contents("$b/live/serve.php", DeploymentHelper::holdingScript());
$swap = DeploymentHelper::swapLiveTree("$b/live", "$b/stage", "$b/backup", 0);
check($swap['swapped'] === true && lts_read("$b/live/serve.php") === "<?php echo 'NEW';", 'the release still goes live', json_encode($swap));
check(!DeploymentHelper::backupIsRelease("$b/backup"), 'the backup is not taken for a release (performRollback refuses it)');
$b2 = lts_fresh($root, 'release_backup');
check(DeploymentHelper::backupIsRelease("$b2/live"), 'a real tree is one');

harness_finish();
