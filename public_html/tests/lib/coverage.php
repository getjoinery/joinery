<?php
/**
 * The coverage map and the --changed selection — how the runner knows which
 * suites a change can reach.
 *
 * A PHP suite reaches code by LOADING it, so its result contract carries the
 * repo files its process included (harness_loaded_repo_files()). The runner
 * folds those into one JSON map, refreshed every time a suite runs. A suite
 * that reaches files without loading them — data it reads, scripts it runs, a
 * shell gate's whole subject — declares them with a `covers:` header of
 * repo-relative globs. Selection then intersects a change (git status) with
 * each suite's recorded reach; a suite the map has never seen simply runs.
 *
 * Everything here is pure except the map I/O and the git readers, so
 * tests/unit/changed_selection_test.php exercises the selection logic with
 * fabricated maps and change lists.
 */

/** Where the map lives: beside class_map.php, per box, never committed. */
function coverage_map_path($root) {
	return dirname($root) . '/cache/test_coverage_map.json';
}

/** ['<repo-relative suite path>' => {name, recorded_at, files, covers}] */
function coverage_map_load($root) {
	$raw = @file_get_contents(coverage_map_path($root));
	$map = $raw === false ? null : json_decode($raw, true);
	return is_array($map) ? $map : array();
}

/**
 * Fold finished results into the map, atomically (temp + rename, the
 * class_map.php pattern). A result with no recorded files (a shell gate, a
 * crash, a timeout) never wipes a previous run's file list — files are the
 * suite's reach, and a crash says nothing about reach.
 */
function coverage_map_update(array $results, $root) {
	if (!$results) return;
	$dir = dirname(coverage_map_path($root));
	if (!is_dir($dir)) return; // no cache dir on this install — the map is an optimization
	$map = coverage_map_load($root);
	$now = gmdate('Y-m-d\TH:i:s\Z');
	foreach ($results as $r) {
		if (empty($r['path'])) continue;
		$key = 'public_html/' . ltrim($r['path'], '/');
		$entry = isset($map[$key]) && is_array($map[$key]) ? $map[$key] : array();
		$entry['name'] = $r['name'] ?? ($entry['name'] ?? '');
		$entry['recorded_at'] = $now;
		if (!empty($r['files'])) $entry['files'] = array_values($r['files']);
		$entry['covers'] = array_values($r['covers'] ?? array());
		$map[$key] = $entry;
	}
	$tmp = @tempnam($dir, 'coverage-');
	if ($tmp === false) return;
	if (@file_put_contents($tmp, json_encode($map)) !== false) {
		@rename($tmp, coverage_map_path($root));
		@chmod(coverage_map_path($root), 0666);
	} else {
		@unlink($tmp);
	}
}

/**
 * The changed set: repo-relative paths from git status (staged, unstaged,
 * untracked), plus `git diff --name-only <ref>` when a ref is given. Returns
 * null when git is unusable here — the caller refuses rather than guessing.
 */
function coverage_changed_files($repo_root, $ref = '') {
	$git = 'git -C ' . escapeshellarg($repo_root);
	exec($git . ' rev-parse --is-inside-work-tree 2>/dev/null', $probe, $rc);
	if ($rc !== 0 || trim(implode('', $probe)) !== 'true') return null;
	$paths = array();
	exec($git . ' status --porcelain=v1 --untracked-files=all 2>/dev/null', $lines, $rc);
	if ($rc !== 0) return null;
	foreach ($lines as $line) {
		$p = substr($line, 3);
		// A rename is "old -> new"; both sides are changes.
		if (strpos($p, ' -> ') !== false) {
			list($a, $b) = explode(' -> ', $p, 2);
			$paths[] = trim($a, '" ');
			$paths[] = trim($b, '" ');
		} else {
			$paths[] = trim($p, '" ');
		}
	}
	if ($ref !== '') {
		exec($git . ' diff --name-only ' . escapeshellarg($ref) . ' 2>/dev/null', $dlines, $rc);
		if ($rc !== 0) return null; // a bad ref must refuse, not silently narrow
		foreach ($dlines as $p) { $paths[] = trim($p); }
	}
	return array_values(array_unique(array_filter($paths, 'strlen')));
}

/** Does one covers-glob match a repo path? ** crosses slashes, * does not. */
function coverage_glob_match($glob, $path) {
	$rx = preg_quote($glob, '#');
	$rx = str_replace('\\*\\*', '(?:.*)', $rx);
	$rx = str_replace('\\*', '[^/]*', $rx);
	$rx = str_replace('\\?', '[^/]', $rx);
	return (bool)preg_match('#^' . $rx . '(/.*)?$#', $path);
}

/**
 * Pure selection. $tests: [{path (repo-relative, public_html/...), meta}];
 * $map: coverage_map_load(); $changed: repo-relative changed paths.
 * Returns ['run_all' => reason|'', 'selected' => [path => reason],
 *          'uncovered' => [path, ...]].
 */
function coverage_select($tests, $map, $changed) {
	$core_dirs = array('public_html/includes/', 'public_html/data/', 'public_html/logic/',
		'public_html/views/', 'public_html/adm/', 'public_html/api/', 'public_html/ajax/',
		'public_html/theme/');
	// The harness or the runner changing invalidates every recorded reach.
	foreach ($changed as $c) {
		if (strpos($c, 'public_html/tests/lib/') === 0 || $c === 'public_html/tests/run.php') {
			return array('run_all' => 'the test harness itself changed (' . $c . ')',
				'selected' => array(), 'uncovered' => array());
		}
	}
	$selected = array();
	$matched_files = array(); // changed path => true, once anything reaches it
	foreach ($tests as $t) {
		$path = $t['path'];
		$meta = $t['meta'];
		$entry = $map[$path] ?? null;
		$reason = '';
		if ($entry === null) {
			$reason = 'never recorded here — unknown means run';
		}
		$reach = $entry === null ? array() : array_flip($entry['files'] ?? array());
		$covers = array_merge($meta['covers'] ?? array(), $entry['covers'] ?? array());
		$is_web = in_array('dev-web', $meta['needs'] ?? array(), true);
		$plugin_prefix = preg_match('#^(public_html/plugins/[^/]+/)#', $path, $pm) ? $pm[1] : '';
		foreach ($changed as $c) {
			$hit = '';
			if ($c === $path) {
				$hit = 'its own file changed';
			} elseif (isset($reach[$c])) {
				$hit = 'loads ' . $c;
			} elseif (strpos($c, '/fixtures/') !== false) {
				$fx_parent = substr($c, 0, strpos($c, '/fixtures/') + 1);
				if ($fx_parent === 'public_html/tests/' || strpos($path, $fx_parent) === 0) {
					$hit = 'shares fixtures with ' . $c;
				}
			}
			if ($hit === '') {
				foreach ($covers as $g) {
					if (coverage_glob_match($g, $c)) { $hit = 'covers ' . $c . ' (' . $g . ')'; break; }
				}
			}
			if ($hit === '' && $is_web) {
				// A dev-web suite exercises Apache-side code its own process
				// never loads; a core or own-plugin change must run it.
				foreach ($core_dirs as $d) {
					if (strpos($c, $d) === 0) { $hit = 'drives the web server and core changed (' . $c . ')'; break; }
				}
				if ($hit === '' && $c === 'public_html/serve.php') $hit = 'drives the web server and serve.php changed';
				if ($hit === '' && $plugin_prefix !== '' && strpos($c, $plugin_prefix) === 0) {
					$hit = 'drives the web server and its plugin changed (' . $c . ')';
				}
			}
			if ($hit !== '') {
				$matched_files[$c] = true;
				if ($reason === '') $reason = $hit;
			}
		}
		if ($reason !== '') $selected[$path] = $reason;
	}
	// Changed code nothing reaches is a coverage gap worth naming; prose is not.
	$uncovered = array();
	foreach ($changed as $c) {
		if (isset($matched_files[$c])) continue;
		if (preg_match('#\.md$#', $c)) continue;
		if (strpos($c, 'public_html/specs/') === 0 || strpos($c, 'public_html/docs/') === 0
			|| strpos($c, 'docs/') === 0) continue;
		$uncovered[] = $c;
	}
	return array('run_all' => '', 'selected' => $selected, 'uncovered' => $uncovered);
}
