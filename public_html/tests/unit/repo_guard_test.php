<?php
/** @joinery-test
 * name: repo_guard
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * A test never changes the real repository (tests/lib/repo_guard.php).
 *
 * On 2026-10-14 a check added to release_commit_test reused its $repo for the
 * real checkout, and the test's fixture steps committed every session's
 * uncommitted work to main, pushed to a throwaway remote and pointed origin at
 * it. This suite stands up a throwaway "real" repository with the guard hooks
 * and shows what a test process can and cannot do to it, and what the
 * snapshot comparison says about what the hooks cannot see.
 *
 * This process is itself a test process (harness_boot() marked it), so a git
 * command it runs plainly is a test's; the owner's commands run with the mark
 * removed (env -u).
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tmp = sys_get_temp_dir() . '/repo_guard_test_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
mkdir($tmp, 0755, true);
harness_defer(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

/** Run git in $dir as a test (marked) or as the owner (unmarked); [exit, output]. */
function rg_git($dir, $cmd, $as_test = true) {
	$out = array();
	$exit = 0;
	exec(($as_test ? '' : 'env -u ' . REPO_GUARD_ENV . ' ') . 'git -C ' . escapeshellarg($dir) . ' ' . $cmd . ' 2>&1', $out, $exit);
	return array($exit, implode(' | ', $out));
}

$bare = $tmp . '/origin.git';
$real = $tmp . '/real';
exec('git init -q --bare ' . escapeshellarg($bare));
mkdir($real);
rg_git($real, 'init -q -b main', false);
rg_git($real, 'config user.email t@example.com', false);
rg_git($real, 'config user.name t', false);
file_put_contents($real . '/a.txt', "a\n");
rg_git($real, 'add a.txt', false);
rg_git($real, 'commit -qm one', false);
rg_git($real, 'remote add origin ' . escapeshellarg($bare), false);
rg_git($real, 'push -q -u origin main', false);

// ---------------------------------------------------------------------------
section('The hooks are put in place once, and a hook that is not ours is kept');

check(getenv(REPO_GUARD_ENV) === '1' && getenv(REPO_GUARD_SUITE_ENV) === 'repo_guard', 'harness_boot() marked this process with its suite name',
	var_export(getenv(REPO_GUARD_SUITE_ENV), true));
$first = repo_guard_install_hooks($real);
check(count($first['installed']) === 2 && !$first['failed'], 'both hooks installed', json_encode($first));
$again = repo_guard_install_hooks($real);
check($again['installed'] === array() && repo_guard_hooks_stand($real), 'a second install changes nothing; the hooks stand');
$other = $tmp . '/other';
mkdir($other);
rg_git($other, 'init -q -b main', false);
file_put_contents($other . '/.git/hooks/pre-push', "#!/bin/sh\nexit 0\n");
$kept = repo_guard_install_hooks($other);
check($kept['kept_foreign'] === array($other . '/.git/hooks/pre-push') && file_get_contents($other . '/.git/hooks/pre-push') === "#!/bin/sh\nexit 0\n",
	'someone else\'s pre-push is kept and named, not overwritten', json_encode($kept));

// ---------------------------------------------------------------------------
section('A test cannot move a branch or push; a fetch passes');

$before = repo_guard_snapshot($real);
file_put_contents($real . '/b.txt', "b\n");
rg_git($real, 'add b.txt');
list($e, $o) = rg_git($real, 'commit -qm two');
check($e !== 0 && strpos($o, 'test guard: refused') !== false && repo_guard_snapshot($real)['head'] === $before['head'],
	'a test\'s commit is refused and main stays where it was', $o);
list($e, $o) = rg_git($real, 'reset -q --soft HEAD');
check($e !== 0 && strpos($o, 'test guard: refused') !== false, 'a test\'s reset is refused', $o);
list($e, $o) = rg_git($real, 'push -q origin main');
check($e !== 0 && strpos($o, 'may not push') !== false, 'a test\'s push is refused', $o);
list($e, $o) = rg_git($real, 'fetch -q origin');
check($e === 0, 'a test\'s fetch passes: remote-tracking refs are not guarded', $o);
$log = (string)@file_get_contents(repo_guard_git_dir($real) . '/' . REPO_GUARD_LOG);
check(substr_count($log, 'suite=repo_guard') === 3 && strpos($log, 'pre-push') !== false,
	'each refusal is logged with the suite that tried it', $log);

// ---------------------------------------------------------------------------
section('What the hooks cannot see, the snapshot says');

rg_git($real, 'remote set-url origin /nowhere.git');
$changes = repo_guard_changes($real, $before, repo_guard_snapshot($real));
$fails = implode("\n", $changes['fail']);
check(strpos($fails, 'removed [url = ' . $bare . ']') !== false && strpos($fails, 'added [url = /nowhere.git]') !== false,
	'a changed remote fails, naming the line to put back', $fails);
check(strpos($fails, 'newly staged in the shared index (b.txt)') !== false, 'a file the test staged fails, named', $fails);
check(strpos($fails, 'the test guard refused 3 change(s)') !== false, 'and the refusals fail the test that tried them', $fails);
rg_git($real, 'remote set-url origin ' . escapeshellarg($bare), false);
rg_git($real, 'restore --staged b.txt', false);

// ---------------------------------------------------------------------------
section('The owner is never stopped, and a commit made elsewhere is only noted');

$mid = repo_guard_snapshot($real);
list($e1, $o1) = rg_git($real, 'add b.txt', false);
list($e2, $o2) = rg_git($real, 'commit -qm two', false);
list($e3, $o3) = rg_git($real, 'push -q origin main', false);
check($e1 === 0 && $e2 === 0 && $e3 === 0, 'outside a test, commit and push work as always', "$o1 $o2 $o3");
$changes = repo_guard_changes($real, $mid, repo_guard_snapshot($real));
check($changes['fail'] === array() && count($changes['note']) === 1 && strpos($changes['note'][0], 'commit made elsewhere') !== false,
	'a branch that moved while the hooks stood is a note, not a failure', json_encode($changes));

$hooks_dir = repo_guard_git_dir($real) . '/hooks';
$unguarded = repo_guard_snapshot($real);
unlink($hooks_dir . '/reference-transaction');
file_put_contents($real . '/c.txt', "c\n");
rg_git($real, 'add c.txt');
rg_git($real, 'commit -qm three');
$changes = repo_guard_changes($real, $unguarded, repo_guard_snapshot($real));
$fails = implode("\n", $changes['fail']);
check(strpos($fails, 'hooks were removed') !== false && strpos($fails, 'No guard hooks stood') !== false
	&& strpos($fails, 'git reset --mixed ' . substr($unguarded['head'], 0, 12)) !== false,
	'with no hook standing, a moved branch fails and says how to put main back', $fails);

// ---------------------------------------------------------------------------
section('The real checkout is the one guarded');

$site_root = dirname(PathHelper::getIncludePath(''));
check(repo_guard_root() === (is_dir($site_root . '/.git') ? $site_root : null), 'repo_guard_root() is this site root when it is a checkout, else nothing');
if (repo_guard_root() !== null) {
	check(repo_guard_hooks_stand(repo_guard_root()), 'and its guard hooks stand');
}

harness_finish();
