<?php
/**
 * The real repository is not a test's to change.
 *
 * Several tests run git: they make throwaway repositories under the temp
 * directory and commit, push and rewrite remotes there. Aimed at the real
 * checkout instead (one did, on 2026-10-14, through a reused variable), the
 * same steps commit every session's uncommitted work, move main and point
 * origin somewhere else. Two layers keep that from happening again:
 *
 * - PREVENT. Every test process carries JOINERY_TEST_RUN (and the suite's
 *   name in JOINERY_TEST_SUITE), set by the harness and the runner. Two git
 *   hooks installed in the real repository refuse a test's change there: a
 *   reference-transaction hook refuses moving a branch, a tag or HEAD (a
 *   fetch's remote-tracking refs pass), and a pre-push hook refuses a push.
 *   Each refusal is written to the repository's joinery-test-refusals.log.
 *   A throwaway repository has no such hooks, so tests work as before.
 *
 * - DETECT. What a hook cannot see (git config, the shared index) is
 *   compared: a snapshot when a test boots and again when it finishes, and
 *   one around a whole runner pass for the shell gates. A changed config, a
 *   newly staged file or a refusal in the log fails the test, naming what
 *   changed. A branch that moved while the hooks stood is someone's commit
 *   (the hooks refuse a test's), so it is only noted; without the hooks, it
 *   fails.
 *
 * A machine whose site root is not a git checkout (every node) has nothing
 * to guard: every function here is then a no-op.
 *
 * @version 1.0
 */

const REPO_GUARD_ENV        = 'JOINERY_TEST_RUN';
const REPO_GUARD_SUITE_ENV  = 'JOINERY_TEST_SUITE';
const REPO_GUARD_LOG        = 'joinery-test-refusals.log';
const REPO_GUARD_MARK       = 'joinery-test-guard';

/** The site root holding the real checkout, or null where there is none. */
function repo_guard_root() {
	$root = dirname(__DIR__, 3);
	return is_dir($root . '/.git') ? $root : null;
}

/** Mark this process, and everything it starts, as a test run. */
function repo_guard_mark($suite) {
	putenv(REPO_GUARD_ENV . '=1');
	if ($suite !== null && $suite !== '') {
		putenv(REPO_GUARD_SUITE_ENV . '=' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string)$suite));
	}
}

function repo_guard_git($root, array $args) {
	$cmd = 'git -c ' . escapeshellarg('safe.directory=' . $root) . ' -C ' . escapeshellarg($root);
	foreach ($args as $a) {
		$cmd .= ' ' . escapeshellarg($a);
	}
	$out = array();
	$exit = 0;
	exec($cmd . ' 2>/dev/null', $out, $exit);
	return array('exit' => (int)$exit, 'out' => $out);
}

/** The repository's git directory (absolute), or null. */
function repo_guard_git_dir($root) {
	$r = repo_guard_git($root, array('rev-parse', '--absolute-git-dir'));
	return ($r['exit'] === 0 && isset($r['out'][0]) && $r['out'][0] !== '') ? $r['out'][0] : null;
}

/** The two hooks, by name. Each says in its first lines what it is and where it comes from. */
function repo_guard_hooks() {
	$head = "#!/bin/sh\n"
		. '# ' . REPO_GUARD_MARK . ": installed by public_html/tests/lib/repo_guard.php, which rewrites it; edit it there.\n"
		. "# A test process (JOINERY_TEST_RUN, set by the test harness and runner) may not change this\n"
		. "# repository: tests run git in throwaway repositories under the temp directory.\n";
	$log = 'gd="${GIT_DIR:-$(git rev-parse --git-dir 2>/dev/null)}"; '
		. 'printf \'%s %s suite=%s %s\n\' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$hook" "${' . REPO_GUARD_SUITE_ENV . ':-?}" "$what" >> "$gd/' . REPO_GUARD_LOG . '" 2>/dev/null' . "\n";
	return array(
		'reference-transaction' => $head
			. "# Refuses moving a branch, a tag or HEAD; a fetch's remote-tracking refs pass.\n"
			. 'if [ -z "$' . REPO_GUARD_ENV . '" ] || [ "$1" != "prepared" ]; then cat >/dev/null; exit 0; fi' . "\n"
			. "what=''\n"
			. "while read -r old new ref; do\n"
			. "\tcase \"\$ref\" in refs/remotes/*) ;; *) what=\"\$what \$ref\" ;; esac\n"
			. "done\n"
			. "[ -z \"\$what\" ] && exit 0\n"
			. "hook=reference-transaction; what=\"refs:\$what\"\n"
			. $log
			. 'echo "test guard: refused, a test may not change${what#refs:} in this repository; run git in a throwaway repository under the temp directory" >&2' . "\n"
			. "exit 1\n",
		'pre-push' => $head
			. "# Refuses every push.\n"
			. 'if [ -z "$' . REPO_GUARD_ENV . '" ]; then exit 0; fi' . "\n"
			. "hook=pre-push; what=\"push to \$1 (\$2)\"\n"
			. $log
			. 'echo "test guard: refused, a test may not push from this repository; run git in a throwaway repository under the temp directory" >&2' . "\n"
			. "exit 1\n",
	);
}

/**
 * Put the hooks in place, or refresh them, in the real repository's hooks
 * directory (core.hooksPath honoured). A hook of the same name that is not
 * ours is left alone and named in the result.
 *
 * @return array{installed:string[], kept_foreign:string[], failed:string[]}
 */
function repo_guard_install_hooks($root) {
	$res = array('installed' => array(), 'kept_foreign' => array(), 'failed' => array());
	$r = repo_guard_git($root, array('rev-parse', '--git-path', 'hooks'));
	if ($r['exit'] !== 0 || !isset($r['out'][0])) {
		$res['failed'][] = 'hooks directory unknown';
		return $res;
	}
	$dir = $r['out'][0][0] === '/' ? $r['out'][0] : $root . '/' . $r['out'][0];
	if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
		$res['failed'][] = $dir;
		return $res;
	}
	foreach (repo_guard_hooks() as $name => $body) {
		$path = $dir . '/' . $name;
		$have = @file_get_contents($path);
		if ($have === $body && is_executable($path)) {
			continue;
		}
		if ($have !== false && strpos($have, REPO_GUARD_MARK) === false) {
			$res['kept_foreign'][] = $path;
			continue;
		}
		$tmp = $path . '.' . getmypid() . '.new';
		if (@file_put_contents($tmp, $body) === false || !@chmod($tmp, 0755) || !@rename($tmp, $path)) {
			@unlink($tmp);
			$res['failed'][] = $path;
			continue;
		}
		$res['installed'][] = $path;
	}
	return $res;
}

/** Whether both hooks stand, ours and executable. */
function repo_guard_hooks_stand($root) {
	$r = repo_guard_git($root, array('rev-parse', '--git-path', 'hooks'));
	if ($r['exit'] !== 0 || !isset($r['out'][0])) {
		return false;
	}
	$dir = $r['out'][0][0] === '/' ? $r['out'][0] : $root . '/' . $r['out'][0];
	foreach (repo_guard_hooks() as $name => $body) {
		if (@file_get_contents($dir . '/' . $name) !== $body || !is_executable($dir . '/' . $name)) {
			return false;
		}
	}
	return true;
}

/** What a test could change and a hook does not see, plus the refs and the refusal log. */
function repo_guard_snapshot($root) {
	$gd = repo_guard_git_dir($root);
	if ($gd === null) {
		return null;
	}
	$config = array();
	foreach (array('config', 'config.worktree') as $f) {
		$config[$f] = is_file($gd . '/' . $f) ? (string)@file_get_contents($gd . '/' . $f) : null;
	}
	$refs = repo_guard_git($root, array('for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads', 'refs/tags'))['out'];
	$head = repo_guard_git($root, array('rev-parse', 'HEAD'))['out'][0] ?? '';
	$staged = repo_guard_git($root, array('diff', '--cached', '--no-renames', '--name-only'))['out'];
	$log = $gd . '/' . REPO_GUARD_LOG;
	return array(
		'git_dir' => $gd,
		'config' => $config,
		'head' => $head,
		'refs' => $refs,
		'staged' => $staged,
		'log_bytes' => is_file($log) ? (int)filesize($log) : 0,
		'hooks' => repo_guard_hooks_stand($root),
		'time' => time(),
	);
}

/**
 * What changed between two snapshots: 'fail' lines (a test did this, or no
 * hook stood to stop it) and 'note' lines (a branch moved under the hooks,
 * which is someone's commit). Each line says what to put back.
 *
 * @return array{fail:string[], note:string[]}
 */
function repo_guard_changes($root, $before, $after) {
	$out = array('fail' => array(), 'note' => array());
	if (!is_array($before) || !is_array($after)) {
		return $out;
	}
	foreach ($before['config'] as $f => $was) {
		$now = $after['config'][$f] ?? null;
		if ($was === $now) {
			continue;
		}
		$was_l = $was === null ? array() : explode("\n", trim($was));
		$now_l = $now === null ? array() : explode("\n", trim($now));
		$gone = array_values(array_diff($was_l, $now_l));
		$new = array_values(array_diff($now_l, $was_l));
		$out['fail'][] = ".git/{$f} changed: removed [" . implode(' | ', array_map('trim', $gone)) . '], added ['
			. implode(' | ', array_map('trim', $new)) . ']; put the removed lines back';
	}
	$staged_new = array_values(array_diff($after['staged'], $before['staged']));
	if ($staged_new) {
		$out['fail'][] = count($staged_new) . ' file(s) newly staged in the shared index (' . implode(', ', array_slice($staged_new, 0, 5))
			. (count($staged_new) > 5 ? ', ...' : '') . "); unstage them with git restore --staged, the files themselves are untouched";
	}
	if ($after['log_bytes'] > $before['log_bytes']) {
		$lines = @file($after['git_dir'] . '/' . REPO_GUARD_LOG, FILE_IGNORE_NEW_LINES) ?: array();
		$new_lines = array();
		$seen = 0;
		foreach ($lines as $l) {
			$seen += strlen($l) + 1;
			if ($seen > $before['log_bytes']) {
				$new_lines[] = $l;
			}
		}
		$out['fail'][] = 'the test guard refused ' . count($new_lines) . ' change(s): ' . implode(' / ', array_slice($new_lines, -3));
	}
	if ($before['hooks'] && !$after['hooks']) {
		$out['fail'][] = 'the test guard hooks were removed or changed; tests/run.php puts them back';
	}
	if ($before['head'] !== $after['head'] || $before['refs'] !== $after['refs']) {
		$moved = 'HEAD ' . substr($before['head'], 0, 12) . ' -> ' . substr($after['head'], 0, 12);
		$reflog = repo_guard_git($root, array('reflog', '-n', '5', '--date=iso', '--format=%gd %gs'))['out'];
		$line = 'refs moved during the test (' . $moved . '); latest reflog: ' . implode(' / ', $reflog);
		if ($before['hooks'] && $after['hooks']) {
			$out['note'][] = $line . '. The hooks refuse a test\'s ref change, so this is a commit made elsewhere';
		} else {
			$out['fail'][] = $line . '. No guard hooks stood, so a test may have done it: if these are not real commits, git reset --mixed '
				. substr($before['head'], 0, 12) . ' puts main back and keeps every file';
		}
	}
	return $out;
}
