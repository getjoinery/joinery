<?php
/**
 * The gate: one test run at a time per box, a record of what each run tested,
 * and result reuse across runs.
 *
 * Several agents share one box and each runs the gate before checking in.
 * Nothing used to stop three of them from starting one in the same minute,
 * which is nine-plus PHP processes and three compilers on four cores: the box
 * went to a load of 10 to 12, every session became unresponsive, and on
 * 2026-10-05 it ran out of memory and lost every session. The overlap also
 * made the gate lie — most of the flaky reds on record happened only when two
 * runs overlapped.
 *
 * Three things live here, all used by tests/run.php and nothing else:
 *
 *   The lock. An exclusive flock() on {gate dir}/gate.lock, taken before a
 *   run's first suite and held until its last result. The kernel releases it
 *   when the holder dies, so a killed runner leaves no stale lock and no
 *   cleanup step. gate.holder.json beside it says who holds it; a reader
 *   checks the file against the lock before believing it.
 *
 *   The run record. {gate dir}/runs/{run_id}.json is written when a run
 *   starts — its tree id (the git tree of the working tree as it stood),
 *   tier, arguments, holder — and {run_id}.jsonl gains one line per result as
 *   it lands. A waiter reads the holder's .jsonl for progress; a later run
 *   reads every record for results it can reuse.
 *
 *   Reuse. A recorded result is valid for the current tree when the suite's
 *   reach is complete (the files its process loaded plus its covers globs),
 *   the result is a pass (a fail is re-proven first-hand: rerunning a red
 *   suite alone is how a flaky one is told from a real one), the debug
 *   environment matches, and none of the files that reach names differ
 *   between the record's tree and this one — one `git diff-tree` per record
 *   answers that for every suite at once. The decision reuses the --changed
 *   selection (coverage_select) so the two can never disagree about what a
 *   change reaches.
 *
 * The gate dir is {site root}/cache/tests. JOINERY_GATE_DIR relocates it when
 * the `debug` setting is on, which is how a test of the runner spawns runners
 * while the real gate is held; JOINERY_GATE_CUTOFF (same gating) lowers the
 * queue cutoff so such a test can exercise the wait.
 *
 * @version 1.0 - lock, holder, run records, reuse (specs/gate_lock_and_shared_results.md)
 */

require_once(__DIR__ . '/discovery.php'); // harness_rel()
require_once(__DIR__ . '/coverage.php');  // coverage_select(): the reuse decision is the --changed selection

/** A run selecting at most this many suites runs at once, beside whatever
 *  holds the gate: a --only, a narrow --filter, a --changed that reaches
 *  little. Ten is a guess at "finishes in seconds"; tune it from the holder
 *  log, not from here. */
const GATE_QUEUE_CUTOFF = 10;
/** How often a waiting runner reports the holder's progress. */
const GATE_WAIT_PROGRESS_S = 30;
/** How long a run record is kept. Git prunes unreachable tree objects after
 *  two weeks, so every record's tree outlives the record. */
const GATE_RECORD_TTL_S = 172800;
/** Exit code of --no-wait when the gate is held. */
const GATE_EXIT_HELD = 3;

/** Where the lock and the records live. Creates it when it can. */
function gate_dir($root, $debug_on) {
	$dir = dirname($root) . '/cache/tests';
	$env = getenv('JOINERY_GATE_DIR');
	if ($debug_on && is_string($env) && $env !== '') $dir = rtrim($env, '/');
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
	if (!is_dir($dir . '/runs')) @mkdir($dir . '/runs', 0755, true);
	return $dir;
}

/** The queue cutoff: suites selected at or below it never wait for the gate. */
function gate_cutoff($debug_on) {
	$env = getenv('JOINERY_GATE_CUTOFF');
	if ($debug_on && is_string($env) && $env !== '' && ctype_digit($env)) return (int)$env;
	return GATE_QUEUE_CUTOFF;
}

// ---------------------------------------------------------------------------
// Who is running
// ---------------------------------------------------------------------------

/**
 * The Claude session this process runs under, when the environment says.
 * The `work` launcher's registry (outside the repository) maps a session id
 * to the name the owner knows it by; the id alone is the fallback.
 */
function gate_session_identity() {
	$id = getenv('CLAUDE_CODE_SESSION_ID');
	$out = array('session_id' => is_string($id) && $id !== '' ? $id : null, 'session_name' => null);
	if ($out['session_id'] === null) return $out;
	$home = getenv('HOME');
	if (!is_string($home) || $home === '') {
		$pw = function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid()) : null;
		$home = is_array($pw) ? ($pw['dir'] ?? '') : '';
	}
	$raw = $home !== '' ? @file_get_contents($home . '/.claude/work/sessions.json') : false;
	$reg = $raw === false ? null : json_decode($raw, true);
	if (is_array($reg)) {
		foreach ($reg as $name => $entry) {
			if (is_array($entry) && ($entry['session_id'] ?? '') === $out['session_id']) {
				$out['session_name'] = (string)$name;
				break;
			}
		}
	}
	return $out;
}

/** "public-html-41 (pid 1234)" / "session cc69936b (pid 1234)" / "pid 1234". */
function gate_holder_describe(array $h) {
	$pid = isset($h['pid']) ? 'pid ' . (int)$h['pid'] : 'pid ?';
	if (!empty($h['session_name'])) return $h['session_name'] . ' (' . $pid . ')';
	if (!empty($h['session_id'])) return 'session ' . substr($h['session_id'], 0, 8) . ' (' . $pid . ')';
	return $pid;
}

// ---------------------------------------------------------------------------
// The lock and the holder file
// ---------------------------------------------------------------------------

/** The lock file handle; the lock itself is taken with gate_try_lock(). */
function gate_lock_open($dir) {
	$fh = @fopen($dir . '/gate.lock', 'c');
	return $fh === false ? null : $fh;
}

/** Non-blocking exclusive lock. True when this process now holds the gate. */
function gate_try_lock($fh) {
	return $fh !== null && @flock($fh, LOCK_EX | LOCK_NB);
}

function gate_unlock($fh) {
	if ($fh !== null) { @flock($fh, LOCK_UN); @fclose($fh); }
}

/** Written the moment the lock is taken; removed when it is released. */
function gate_holder_write($dir, array $info) {
	$tmp = @tempnam($dir, 'holder-');
	if ($tmp === false) return;
	if (@file_put_contents($tmp, json_encode($info)) !== false) {
		@rename($tmp, $dir . '/gate.holder.json');
	} else {
		@unlink($tmp);
	}
}

function gate_holder_clear($dir) {
	@unlink($dir . '/gate.holder.json');
}

/**
 * The current holder, or null when nobody holds the gate. The file can
 * outlive a holder that was killed, so it is believed only while the lock
 * cannot be taken: a lock this probe CAN take is a lock nobody holds, and is
 * released at once.
 */
function gate_holder_read($dir) {
	$fh = gate_lock_open($dir);
	if ($fh === null) return null;
	if (@flock($fh, LOCK_EX | LOCK_NB)) { gate_unlock($fh); return null; }
	@fclose($fh);
	$raw = @file_get_contents($dir . '/gate.holder.json');
	$h = $raw === false ? null : json_decode($raw, true);
	if (!is_array($h)) return array('pid' => null, 'unknown' => true);
	$h['progress'] = gate_record_progress($dir, $h['run_id'] ?? '');
	return $h;
}

/** Results landed so far in a run: ['done' => n, 'failed' => n]. */
function gate_record_progress($dir, $run_id) {
	$out = array('done' => 0, 'failed' => 0);
	if ($run_id === '') return $out;
	$fh = @fopen($dir . '/runs/' . $run_id . '.jsonl', 'rb');
	if ($fh === false) return $out;
	while (($line = fgets($fh)) !== false) {
		if (trim($line) === '') continue;
		$out['done']++;
		if (strpos($line, '"status":"fail"') !== false) $out['failed']++;
	}
	fclose($fh);
	return $out;
}

/** "db --changed, 212/470 suites done, 0 failed, held 4m10s". */
function gate_holder_summary(array $h) {
	$args = trim(($h['tier'] ?? '') . ' ' . implode(' ', $h['args'] ?? array()));
	$p = $h['progress'] ?? array('done' => 0, 'failed' => 0);
	$held = isset($h['started_ts']) ? gate_fmt_duration(time() - (int)$h['started_ts']) : '?';
	return $args . ', ' . (int)$p['done'] . '/' . (int)($h['selected'] ?? 0) . ' suites done, '
		. (int)$p['failed'] . ' failed, held ' . $held;
}

function gate_fmt_duration($s) {
	$s = max(0, (int)$s);
	return $s >= 60 ? intdiv($s, 60) . 'm' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . 's' : $s . 's';
}

// ---------------------------------------------------------------------------
// The tree id: what this run tests
// ---------------------------------------------------------------------------

/**
 * The git tree id of the working tree as it stands: every tracked and
 * untracked, non-ignored file under the repository root. Made with `git add
 * -A` into a temporary copy of the index and `git write-tree`, so the shared
 * index and any per-session prepared-commit index are never touched; copying
 * the index first means only changed files are re-hashed (about 0.1 s warm).
 * The tree object stays in the repository, unreachable, which is what lets a
 * later run diff against it.
 *
 * Null when there is no repository here, when the repository's top level is
 * not the site root (reach paths are site-relative, so the diff would not
 * line up), or when git fails — and null means "no reuse", never a guess.
 */
function gate_tree_id($site_root) {
	$git = 'git -C ' . escapeshellarg($site_root);
	exec($git . ' rev-parse --show-toplevel 2>/dev/null', $top, $rc);
	if ($rc !== 0 || realpath(trim(implode('', $top))) !== realpath($site_root)) return null;
	exec($git . ' rev-parse --git-dir 2>/dev/null', $gd, $rc);
	if ($rc !== 0) return null;
	$git_dir = trim(implode('', $gd));
	if ($git_dir[0] !== '/') $git_dir = $site_root . '/' . $git_dir;
	$tmp = @tempnam(sys_get_temp_dir(), 'joinery-gate-index-');
	if ($tmp === false) return null;
	if (is_file($git_dir . '/index') && !@copy($git_dir . '/index', $tmp)) { @unlink($tmp); return null; }
	$env = 'GIT_INDEX_FILE=' . escapeshellarg($tmp) . ' ';
	exec($env . $git . ' add -A 2>/dev/null', $o1, $rc1);
	exec($env . $git . ' write-tree 2>/dev/null', $o2, $rc2);
	@unlink($tmp);
	$tree = trim(implode('', $o2));
	if ($rc1 !== 0 || $rc2 !== 0 || !preg_match('/^[0-9a-f]{40,64}$/', $tree)) return null;
	return $tree;
}

/**
 * Site-relative paths that differ between two trees, or null when git cannot
 * say (a tree that no longer exists). Null means "not reusable".
 */
function gate_changed_between($site_root, $tree_a, $tree_b) {
	if ($tree_a === $tree_b) return array();
	$git = 'git -C ' . escapeshellarg($site_root);
	exec($git . ' diff-tree -r --name-only ' . escapeshellarg($tree_a) . ' ' . escapeshellarg($tree_b) . ' 2>/dev/null', $lines, $rc);
	if ($rc !== 0) return null;
	return array_values(array_filter(array_map('trim', $lines), 'strlen'));
}

// ---------------------------------------------------------------------------
// Run records
// ---------------------------------------------------------------------------

/** Start a record. Returns the run id. */
function gate_record_start($dir, array $run) {
	$id = gmdate('Ymd\THis\Z') . '-' . (int)($run['pid'] ?? getmypid());
	$run['run_id'] = $id;
	$run['finished'] = null;
	@file_put_contents($dir . '/runs/' . $id . '.json', json_encode($run));
	@file_put_contents($dir . '/runs/' . $id . '.jsonl', '');
	return $id;
}

/** One result, as it lands. Append-only, one line, so a reader mid-write
 *  sees whole lines or nothing. */
function gate_record_append($dir, $id, array $result) {
	@file_put_contents($dir . '/runs/' . $id . '.jsonl', json_encode($result) . "\n", FILE_APPEND | LOCK_EX);
}

/** Rewrite the .json with the aggregate. */
function gate_record_finish($dir, $id, array $extra) {
	$path = $dir . '/runs/' . $id . '.json';
	$raw = @file_get_contents($path);
	$run = $raw === false ? array() : json_decode($raw, true);
	if (!is_array($run)) $run = array();
	$run = array_merge($run, $extra);
	$run['finished'] = gmdate('Y-m-d\TH:i:s\Z');
	@file_put_contents($path, json_encode($run));
}

/**
 * Every record on the box, newest first, each with its results keyed by
 * suite path (repo-relative under public_html, as the result carries it).
 * Includes an unfinished record — a run in progress, or one that was killed —
 * because the results that landed are as good as any.
 */
function gate_records_load($dir, $except_id = '') {
	$out = array();
	foreach (glob($dir . '/runs/*.json') ?: array() as $f) {
		$id = basename($f, '.json');
		if ($id === $except_id) continue;
		$run = json_decode((string)@file_get_contents($f), true);
		if (!is_array($run) || empty($run['tree'])) continue;
		$run['run_id'] = $id;
		$run['results'] = array();
		$fh = @fopen($dir . '/runs/' . $id . '.jsonl', 'rb');
		if ($fh !== false) {
			while (($line = fgets($fh)) !== false) {
				$r = json_decode($line, true);
				// A borrowed result points at the run that produced it; the
				// record holds what THIS run ran.
				if (!is_array($r) || empty($r['path']) || !empty($r['reused_from'])) continue;
				$run['results'][$r['path']] = $r;
			}
			fclose($fh);
		}
		$out[] = $run;
	}
	usort($out, function ($a, $b) { return strcmp($b['run_id'], $a['run_id']); });
	return $out;
}

/** Records older than the TTL go, .json and .jsonl together. */
function gate_prune($dir) {
	$cutoff = time() - GATE_RECORD_TTL_S;
	foreach (glob($dir . '/runs/*.json') ?: array() as $f) {
		if (@filemtime($f) >= $cutoff) continue;
		@unlink($f);
		@unlink(substr($f, 0, -5) . '.jsonl');
	}
}

// ---------------------------------------------------------------------------
// Reuse
// ---------------------------------------------------------------------------

/**
 * Is a result a complete statement of what it depends on? True for a PHP
 * suite whose process reported its files and for a shell gate (its covers
 * is its whole declaration by rule). False for a crash or timeout (no files
 * reported), for a suite driving the web server (Apache-side reach is not in
 * its include list), and for the live, prod-verify and deploy suites, which
 * are never reused.
 */
function gate_reach_complete(array $r, array $meta) {
	if (!in_array($r['status'] ?? '', array('pass', 'fail'), true)) return false;
	if (isset($r['note']) && strpos($r['note'], 'timed out') === 0) return false;
	if (isset($r['note']) && strpos($r['note'], 'lane worker exited') === 0) return false;
	if (in_array($meta['tier'] ?? '', array('live', 'deploy'), true)) return false;
	if (($meta['env'] ?? '') === 'prod-verify') return false;
	if (in_array('dev-web', $meta['needs'] ?? array(), true)) return false;
	$is_sh = strtolower(pathinfo($r['path'] ?? '', PATHINFO_EXTENSION)) === 'sh';
	if ($is_sh) return !isset($r['note']) || strpos($r['note'], 'shell gate') === 0;
	return !empty($r['files']);
}

/**
 * Pure: may a recorded result stand in for running this suite on a tree
 * that differs from the record's by $changed? $meta is the suite's header
 * as discovered now; $rel is its path under public_html. Returns '' when
 * reusable, else the reason it is not.
 */
function gate_reuse_refusal(array $result, array $meta, $rel, array $changed, $debug_env_matches) {
	if (empty($result['reach_complete'])) return 'reach not complete';
	if (($result['status'] ?? '') === 'fail') return 'a fail is never borrowed; it is re-proven here';
	if (($result['status'] ?? '') !== 'pass') return 'not a pass';
	if (!$debug_env_matches) return 'different debug environment';
	$key = 'public_html/' . ltrim($rel, '/');
	$entry = array('files' => array_values($result['files'] ?? array()), 'covers' => array_values($result['covers'] ?? array()));
	$sel = coverage_select(array(array('path' => $key, 'meta' => $meta)), array($key => $entry), $changed);
	if ($sel['run_all'] !== '') return $sel['run_all'];
	if (isset($sel['selected'][$key])) return $sel['selected'][$key];
	return '';
}

/**
 * Decide reuse for a selection. $to_run: discovered records [{path, meta}];
 * $records: gate_records_load(); $my_tree: gate_tree_id(). Returns
 * [rel path => result with reused_from]. Every record is diffed at most once,
 * and only while a selected suite is still undecided. $why collects, for each
 * suite NOT borrowed, the refusal from the newest record that had it, so the
 * summary can say what blocked reuse (usually one widely loaded file).
 */
function gate_reuse_plan(array $to_run, array $records, $my_tree, $site_root, $root, $debug_on, &$why = null) {
	$reused = array();
	if (!is_array($why)) $why = array();
	if ($my_tree === null || !$records) return $reused;
	$pending = array();
	foreach ($to_run as $d) $pending[harness_rel($d['path'], $root)] = $d['meta'];
	foreach ($records as $run) {
		if (!$pending) break;
		$wanted = array_intersect_key($run['results'], $pending);
		if (!$wanted) continue;
		$changed = gate_changed_between($site_root, $run['tree'], $my_tree);
		if ($changed === null) continue;
		$debug_matches = (bool)($run['debug_env'] ?? false) === (bool)$debug_on;
		foreach ($wanted as $rel => $r) {
			$refusal = gate_reuse_refusal($r, $pending[$rel], $rel, $changed, $debug_matches);
			if ($refusal !== '') { if (!isset($why[$rel])) $why[$rel] = $refusal; continue; }
			unset($why[$rel]);
			$r['reused_from'] = array(
				'run_id' => $run['run_id'], 'started' => $run['started'] ?? '',
				'holder' => gate_holder_describe($run),
			);
			$reused[$rel] = $r;
			unset($pending[$rel]);
		}
	}
	return $reused;
}
