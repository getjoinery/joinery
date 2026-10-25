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
 * A suite run with the function trace on (tests/lib/function_trace.php, the
 * runner turns it on when xhprof is loaded) also reports the functions its
 * process ran. When a changed PHP file differs only inside function bodies
 * (coverage_function_change()), a suite that loaded it is selected only if it
 * ran one of the changed functions. Anything else about a file changing —
 * constants, properties, top-level code, a method added, removed or
 * re-signed — selects every suite that loaded it, as a file.
 *
 * Everything here is pure except the map I/O and the git readers, so
 * tests/unit/changed_selection_test.php exercises the selection logic with
 * fabricated maps and change lists.
 *
 * @version 1.4 - function-level reach: a suite records the functions it ran (xhprof), and a change
 *                inside function bodies selects only the suites that ran one of them
 *                (coverage_function_change(), the function sets under cache/test_fnsets/)
 * @version 1.3 - a PHP file whose change is only comments and layout selects nothing through what
 *                loads it: coverage_select() takes the set of such paths (coverage_code_unchanged())
 * @version 1.2 - tests/lib/gate.php (the lock, run records and reuse) is harness: a change runs everything
 * @version 1.1 - only the runner, the selection, the discovery and the harness run everything; a
 *                fixture library under tests/lib/ selects by reach like any other file
 * @version 1.0
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
		if (!empty($r['files'])) {
			$entry['files'] = array_values($r['files']);
			// A set describes the files beside it: a run that reported files but
			// no set (no trace) leaves the suite file level until one does.
			if (!empty($r['functions_set'])) $entry['functions_set'] = $r['functions_set'];
			else unset($entry['functions_set']);
		}
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

/**
 * The code of a PHP source with its comments and layout taken out: a hash of
 * its token stream minus comments, doc comments and whitespace. Two sources
 * with the same hash run the same way, so a change between them is prose.
 * Null for anything that is not PHP.
 */
function coverage_php_code_hash($src) {
	if (!is_string($src) || (strpos($src, '<?php') === false && strpos($src, '<?=') === false)) return null;
	$out = array();
	foreach (token_get_all($src) as $t) {
		if (!is_array($t)) { $out[] = $t; continue; }
		if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT || $t[0] === T_WHITESPACE) continue;
		// An open or close tag's own trailing whitespace is layout too.
		$out[] = ($t[0] === T_OPEN_TAG || $t[0] === T_CLOSE_TAG) ? token_name($t[0]) : $t[0] . ':' . $t[1];
	}
	return sha1(serialize($out));
}

/**
 * Read objects out of git in one process: $specs are anything `git cat-file`
 * names (a blob id, "<tree-ish>:<path>"). Returns [spec => content], with
 * null for one git does not have.
 */
function coverage_git_objects($repo_root, array $specs) {
	$out = array_fill_keys($specs, null);
	if (!$specs) return $out;
	$proc = proc_open(array('git', '-C', $repo_root, 'cat-file', '--batch'),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	if (!is_resource($proc)) return $out;
	// One request, one answer: git never blocks on a full stdout while we write.
	foreach ($specs as $spec) {
		if (strpbrk($spec, "\r\n") !== false) continue;
		fwrite($pipes[0], $spec . "\n");
		fflush($pipes[0]);
		$head = fgets($pipes[1]);
		if ($head === false) break;
		// "<id> <type> <size>" then the object; "<spec> missing" has no body.
		if (!preg_match('/^[0-9a-f]{40,64} ([a-z]+) (\d+)$/', rtrim($head, "\n"), $m)) continue;
		$size = (int)$m[2];
		$body = '';
		while (strlen($body) < $size && ($chunk = fread($pipes[1], $size - strlen($body))) !== false && $chunk !== '') $body .= $chunk;
		fgets($pipes[1]); // the newline after the object
		if ($m[1] === 'blob') $out[$spec] = $body;
	}
	fclose($pipes[0]);
	fclose($pipes[1]);
	proc_close($proc);
	return $out;
}

/**
 * Which of $pairs changed only comments and layout: $pairs is
 * [path => [old content, new content]] (null where a side does not exist).
 * Returns [path => true]. Only PHP counts — a comment in anything else may
 * be what something reads.
 */
function coverage_code_unchanged(array $pairs) {
	$same = array();
	foreach ($pairs as $path => $p) {
		if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') continue;
		if ($p[0] === null || $p[1] === null) continue;
		$a = coverage_php_code_hash($p[0]);
		if ($a !== null && $a === coverage_php_code_hash($p[1])) $same[$path] = true;
	}
	return $same;
}

/**
 * The comment-only edits in a --changed set: each changed PHP file in the
 * working tree against HEAD (and against $ref too, when one is given — it
 * has to be prose against both to be prose). Returns [path => true].
 */
function coverage_changed_code_unchanged($repo_root, array $changed, $ref = '') {
	$php = array_values(array_filter($changed, function ($p) {
		return strtolower(pathinfo($p, PATHINFO_EXTENSION)) === 'php';
	}));
	if (!$php) return array();
	$bases = $ref !== '' ? array('HEAD', $ref) : array('HEAD');
	$same = null;
	foreach ($bases as $base) {
		$specs = array();
		foreach ($php as $p) $specs[$p] = $base . ':' . $p;
		$objs = coverage_git_objects($repo_root, array_values($specs));
		$pairs = array();
		foreach ($specs as $p => $spec) {
			$now = @file_get_contents($repo_root . '/' . $p);
			$pairs[$p] = array($objs[$spec], $now === false ? null : $now);
		}
		$these = coverage_code_unchanged($pairs);
		$same = $same === null ? $these : array_intersect_key($same, $these);
	}
	return $same;
}

// ---------------------------------------------------------------------------
// Function-level reach
// ---------------------------------------------------------------------------

/**
 * Split a PHP source into what can change on its own and what cannot. Returns
 * null when it does not parse, else:
 *   'outside' => hash of everything that is not a function or method body:
 *                top-level code, class headers, constants, properties,
 *                attributes, closures at file scope, and every global
 *                function's signature;
 *   'methods' => [key => [signature hash, body hash]] for each method, where
 *                key is 'class::name' in lower case (a trait's or an
 *                anonymous class's methods are '*::name': the trace reports
 *                them under the class that uses them, so only the name can
 *                match);
 *   'functions' => [name => body hash] for each global function.
 * Comments and whitespace are never part of any hash. A closure, arrow
 * function or anonymous class inside a body belongs to that body.
 */
function coverage_php_regions($src) {
	try {
		$toks = token_get_all($src, TOKEN_PARSE);
	} catch (\Throwable $e) {
		return null;
	}
	$n = count($toks);
	$skip = array(T_COMMENT => 1, T_DOC_COMMENT => 1, T_WHITESPACE => 1);
	$tx = function ($t) {
		if (!is_array($t)) return $t;
		return ($t[0] === T_OPEN_TAG || $t[0] === T_CLOSE_TAG) ? token_name($t[0]) : $t[0] . ':' . $t[1];
	};
	$next = function ($i) use ($toks, $n, $skip) {
		for (; $i < $n; $i++) {
			if (!(is_array($toks[$i]) && isset($skip[$toks[$i][0]]))) return $i;
		}
		return $n;
	};
	$opens = function ($t) {
		return $t === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES));
	};
	$modifiers = array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL);
	if (defined('T_READONLY')) $modifiers[] = T_READONLY;
	$outside = array();
	$methods = array();
	$functions = array();
	$depth = 0;
	$classes = array();   // [key prefix, depth its body opened at]
	$pending = null;      // a class declaration seen, its body not yet opened
	$prev = null;         // the last significant token
	for ($i = 0; $i < $n; $i++) {
		$t = $toks[$i];
		if (is_array($t) && isset($skip[$t[0]])) continue;
		$id = is_array($t) ? $t[0] : null;
		if (in_array($id, array(T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM), true)
				&& !(is_array($prev) && $prev[0] === T_DOUBLE_COLON)) {
			$j = $next($i + 1);
			$named = is_array($toks[$j] ?? null) && $toks[$j][0] === T_STRING;
			$pending = ($id === T_TRAIT || !$named) ? '*' : strtolower($toks[$j][1]);
		}
		if ($opens($t)) {
			$depth++;
			if ($t === '{' && $pending !== null) { $classes[] = array($pending, $depth); $pending = null; }
		} elseif ($t === '}') {
			if ($classes && end($classes)[1] === $depth) array_pop($classes);
			$depth--;
		}
		// `use function Ns\f;` imports a name; it declares nothing.
		if ($id === T_FUNCTION && !(is_array($prev) && $prev[0] === T_USE)) {
			$j = $next($i + 1);
			if (($toks[$j] ?? null) === '&') $j = $next($j + 1);
			// A named function has its name here; a closure has '('.
			if (is_array($toks[$j] ?? null)) {
				$name = strtolower($toks[$j][1]);
				$in_class = $classes && end($classes)[1] === $depth;
				$sig = array();
				if ($in_class) {
					// The modifiers already went to outside; they are the signature's.
					while ($outside && preg_match('/^(\d+):/', end($outside), $mm) && in_array((int)$mm[1], $modifiers, true)) {
						array_unshift($sig, array_pop($outside));
					}
				}
				$k = $j;
				for ($paren = 0; $k < $n; $k++) {
					$u = $toks[$k];
					if ($u === '(') $paren++;
					elseif ($u === ')') $paren--;
					elseif ($paren === 0 && ($u === '{' || $u === ';')) break;
				}
				for ($m = $i; $m < $k; $m++) {
					if (!(is_array($toks[$m]) && isset($skip[$toks[$m][0]]))) $sig[] = $tx($toks[$m]);
				}
				$body = array();
				if (($toks[$k] ?? null) === '{') {
					for ($d = 0, $e = $k; $e < $n; $e++) {
						$u = $toks[$e];
						if ($opens($u)) $d++;
						elseif ($u === '}') $d--;
						if (!(is_array($u) && isset($skip[$u[0]]))) $body[] = $tx($u);
						if ($d === 0) break;
					}
					$i = $e;
				} else {
					$i = $k;   // an abstract or interface method: a signature only
				}
				if ($in_class) {
					$methods[end($classes)[0] . '::' . $name] = array(sha1(serialize($sig)), sha1(serialize($body)));
				} else {
					foreach ($sig as $st) $outside[] = $st;
					$outside[] = '{body}';
					$functions[$name] = sha1(serialize($body));
				}
				$prev = $toks[$i];
				continue;
			}
		}
		$outside[] = $tx($t);
		$prev = $t;
	}
	return array('outside' => sha1(serialize($outside)), 'methods' => $methods, 'functions' => $functions);
}

/**
 * What a change to one PHP file means for the suites that loaded it. Null
 * means "the whole file": it was added or removed, does not parse, changed
 * outside its function bodies, or added, removed or re-signed a method. A
 * method can be found without being called — `method_exists($profile,
 * 'backupRow')` and its kind probe for optional hooks by name — so a method
 * appearing or disappearing is never local; a changed signature can break a
 * subclass at load time, in a suite that never calls it. Otherwise returns
 * [key => true] for the functions and methods whose bodies changed, keys as
 * coverage_php_regions() makes them.
 */
function coverage_function_change($old, $new) {
	if ($old === null || $new === null) return null;
	return coverage_regions_change(coverage_php_regions($old), coverage_php_regions($new));
}

/** coverage_function_change() over two coverage_php_regions() results. */
function coverage_regions_change($a, $b) {
	if ($a === null || $b === null || $a['outside'] !== $b['outside']) return null;
	if (array_keys($a['methods']) != array_keys($b['methods'])) return null;
	$changed = array();
	foreach ($b['methods'] as $k => $h) {
		if ($a['methods'][$k][0] !== $h[0]) return null;
		if ($a['methods'][$k][1] !== $h[1]) $changed[$k] = true;
	}
	foreach ($b['functions'] as $k => $h) {
		if (($a['functions'][$k] ?? null) !== $h) $changed[$k] = true;
	}
	return $changed;
}

/**
 * A traced function name as a region key: lower case, namespace dropped
 * ('Ns\Cls::m' -> 'cls::m', 'Ns\f' -> 'f'). A short-name collision can only
 * widen a run.
 */
function coverage_function_key($name) {
	$name = strtolower($name);
	if (($p = strpos($name, '::')) !== false) {
		$cls = substr($name, 0, $p);
		if (($q = strrpos($cls, '\\')) !== false) $cls = substr($cls, $q + 1);
		return $cls . '::' . substr($name, $p + 2);
	}
	if (($q = strrpos($name, '\\')) !== false) $name = substr($name, $q + 1);
	return $name;
}

/**
 * Did a suite that ran $functions ([key => true]) run any of $changed (from
 * coverage_function_change())? Returns the first such key, or ''. A '*::m'
 * key matches a method of that name in any class.
 */
function coverage_ran_any(array $changed, array $functions) {
	$names = null;
	foreach ($changed as $k => $_) {
		if (strpos($k, '*::') === 0) {
			if ($names === null) {
				$names = array();
				foreach ($functions as $f => $__) {
					if (($p = strpos($f, '::')) !== false) $names[substr($f, $p + 2)] = true;
				}
			}
			if (isset($names[substr($k, 3)])) return $k;
		} elseif (isset($functions[$k])) {
			return $k;
		}
	}
	return '';
}

/** Where the function sets live: beside the coverage map, per box. */
function coverage_fnset_dir($root) {
	return dirname($root) . '/cache/test_fnsets';
}

/**
 * Store a suite's traced function names, keyed by content: sets repeat from
 * run to run, and a record line or map entry carries only the id. Returns
 * the id, or '' when it could not be stored (the result is then file level).
 */
function coverage_fnset_put($root, array $names) {
	$keys = array();
	foreach ($names as $nm) $keys[coverage_function_key((string)$nm)] = true;
	$keys = array_keys($keys);
	sort($keys);
	$json = json_encode($keys);
	$id = sha1($json);
	$dir = coverage_fnset_dir($root);
	if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return '';
	$path = $dir . '/' . $id . '.json';
	if (is_file($path)) { @touch($path); return $id; }
	$tmp = @tempnam($dir, 'fnset-');
	if ($tmp === false) return '';
	if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) { @unlink($tmp); return ''; }
	@chmod($path, 0666);
	return $id;
}

/** A stored set as [key => true], or null when it cannot be read. */
function coverage_fnset_get($root, $id) {
	static $cache = array();
	if (!is_string($id) || !preg_match('/^[0-9a-f]{40}$/', $id)) return null;
	if (!array_key_exists($id, $cache)) {
		$keys = json_decode((string)@file_get_contents(coverage_fnset_dir($root) . '/' . $id . '.json'), true);
		$cache[$id] = is_array($keys) ? array_fill_keys($keys, true) : null;
	}
	return $cache[$id];
}

/**
 * Sets older than $ttl seconds that the coverage map does not name go. A
 * record whose set is gone falls back to file level, never to a skip.
 */
function coverage_fnsets_prune($root, $ttl) {
	$keep = array();
	foreach (coverage_map_load($root) as $entry) {
		if (!empty($entry['functions_set'])) $keep[$entry['functions_set']] = true;
	}
	$cutoff = time() - $ttl;
	foreach (glob(coverage_fnset_dir($root) . '/*.json') ?: array() as $f) {
		if (isset($keep[basename($f, '.json')]) || @filemtime($f) >= $cutoff) continue;
		@unlink($f);
	}
}

/**
 * Give each map entry that needs it its function set ('functions'): only the
 * entries whose reach holds a file with a function-level change, since
 * reading every set costs more than the selection.
 */
function coverage_map_with_functions(array $map, array $fn_changes, $root) {
	$local = array_filter($fn_changes, 'is_array');
	if (!$local) return $map;
	foreach ($map as $k => $entry) {
		if (empty($entry['functions_set']) || empty($entry['files'])) continue;
		if (!array_intersect_key($local, array_flip($entry['files']))) continue;
		$set = coverage_fnset_get($root, $entry['functions_set']);
		if ($set !== null) $map[$k]['functions'] = $set;
	}
	return $map;
}

/**
 * The function-level changes in a --changed set: each changed PHP file whose
 * code differs, the working tree against HEAD (and against $ref too, when
 * given — local only if local against both). Returns [path => [key => true]
 * | null], null meaning the whole file.
 */
function coverage_changed_function_changes($repo_root, array $changed, array $code_unchanged, $ref = '') {
	$php = array();
	foreach ($changed as $p) {
		if (strtolower(pathinfo($p, PATHINFO_EXTENSION)) === 'php' && !isset($code_unchanged[$p])) $php[] = $p;
	}
	if (!$php) return array();
	$out = array();
	foreach ($ref !== '' ? array('HEAD', $ref) : array('HEAD') as $base) {
		$specs = array();
		foreach ($php as $p) $specs[$p] = $base . ':' . $p;
		$objs = coverage_git_objects($repo_root, array_values($specs));
		foreach ($specs as $p => $spec) {
			if (array_key_exists($p, $out) && $out[$p] === null) continue;
			$now = @file_get_contents($repo_root . '/' . $p);
			$ch = coverage_function_change($objs[$spec], $now === false ? null : $now);
			$out[$p] = ($ch === null) ? null : (($out[$p] ?? array()) + $ch);
		}
	}
	return $out;
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
 * $map: coverage_map_load(); $changed: repo-relative changed paths;
 * $code_unchanged: [path => true] for the changed PHP files whose change is
 * only comments and layout. Such a file selects nothing through the code that
 * loads or runs it (a suite's reach, the web server, the harness). It still
 * selects where its text is the subject: the suite's own file (its header is
 * a comment), a fixture, a `covers:` glob.
 * $fn_changes: [path => [key => true] | null] from coverage_function_change()
 * for changed PHP files. A loaded file whose change is function level selects
 * the suite only when its entry's 'functions' (a resolved set, see
 * coverage_map_with_functions()) holds one of the changed keys; a file with no
 * answer, a whole-file answer, or a suite with no set selects as a file.
 * Returns ['run_all' => reason|'', 'selected' => [path => reason],
 *          'uncovered' => [path, ...]].
 */
function coverage_select($tests, $map, $changed, $code_unchanged = array(), $fn_changes = array()) {
	$core_dirs = array('public_html/includes/', 'public_html/data/', 'public_html/logic/',
		'public_html/views/', 'public_html/adm/', 'public_html/api/', 'public_html/ajax/',
		'public_html/theme/');
	// The runner, the selection, the discovery, the gate, the function trace
	// and the harness changing invalidates every recorded reach (and every
	// recorded function set): no suite loads the first three, so
	// reach cannot see them, and the harness is under every suite and every
	// shell gate's subject. Anything else under tests/lib/ — a fixture
	// library, the HTTP or logic helpers — is loaded by the suites that use it
	// and recorded in their reach, so it selects like any other file.
	$harness = array('public_html/tests/run.php', 'public_html/tests/lib/harness.php',
		'public_html/tests/lib/coverage.php', 'public_html/tests/lib/discovery.php',
		'public_html/tests/lib/gate.php', 'public_html/tests/lib/function_trace.php');
	foreach ($changed as $c) {
		if (in_array($c, $harness, true) && !isset($code_unchanged[$c])) {
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
			} elseif (isset($reach[$c]) && !isset($code_unchanged[$c])) {
				$matched_files[$c] = true; // reached, whether or not this suite ran the change
				$local = $fn_changes[$c] ?? null;
				if (!is_array($local) || !isset($entry['functions']) || !is_array($entry['functions'])) {
					$hit = 'loads ' . $c;
				} elseif (($ran = coverage_ran_any($local, $entry['functions'])) !== '') {
					$hit = 'runs ' . $ran . ' (' . $c . ')';
				}
			}
			if ($hit === '' && strpos($c, '/fixtures/') !== false) {
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
			if ($hit === '' && $is_web && !isset($code_unchanged[$c])) {
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
		if (isset($matched_files[$c]) || isset($code_unchanged[$c])) continue;
		if (preg_match('#\.md$#', $c)) continue;
		if (strpos($c, 'public_html/specs/') === 0 || strpos($c, 'public_html/docs/') === 0
			|| strpos($c, 'docs/') === 0) continue;
		$uncovered[] = $c;
	}
	return array('run_all' => '', 'selected' => $selected, 'uncovered' => $uncovered);
}
