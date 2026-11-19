<?php
/**
 * Find variables read where they may never have been set, in a given set of
 * PHP files, with Psalm.
 *
 * PHP only warns about such a read when the path that skips the assignment
 * actually runs, so a site meets it long after the edit that caused it (an
 * event page on one site read a session count set only in the other branch of
 * an if). validate_php_file.php checks names, not paths through the code, so
 * it never sees these. Psalm follows every path.
 *
 * Two things keep its answer usable here:
 *
 * - Psalm stops checking variables after any include it cannot resolve to a
 *   file, because that file might have set them. Every include here is
 *   PathHelper::getIncludePath(...), so it would check almost nothing. The
 *   files are analyzed as copies in which each include whose target is not a
 *   plain string is replaced by null, line for line, so a finding's line is
 *   the original's. A variable that only an included file sets is reported
 *   as undefined; that is rare, and naming it before the include is clearer
 *   anyway.
 * - Only the findings that mean a read of something never set are kept:
 *   UndefinedVariable (inside a function), PossiblyUndefinedVariable and
 *   PossiblyUndefinedGlobalVariable. UndefinedGlobalVariable is dropped: a
 *   view or admin page runs inside the router's scope, which sets variables
 *   such as $is_valid_page that Psalm cannot see. Superglobals are dropped,
 *   and so is a finding on the line Psalm first saw the variable: an array
 *   built by assignment ($page_vars['x'] = ...), which PHP allows.
 *
 * Psalm is not part of the platform. It is found at $JOINERY_PSALM, or under
 * ~/tools/psalm (composer require --dev vimeo/psalm there).
 *
 * @version 1.0
 */

/** The Psalm executable, or null where none is installed. */
function undefvar_psalm_bin(): ?string {
	$candidates = array();
	$env = trim((string)getenv('JOINERY_PSALM'));
	if ($env !== '') { $candidates[] = $env; }
	$home = trim((string)getenv('HOME'));
	if ($home !== '') { $candidates[] = $home . '/tools/psalm/vendor/bin/psalm'; }
	$candidates[] = '/home/user1/tools/psalm/vendor/bin/psalm';
	foreach ($candidates as $bin) {
		if (is_file($bin) && is_executable($bin)) { return $bin; }
	}
	return null;
}

/**
 * Psalm's cache directory for this check (about 1 GB), owned by the running
 * user, since Psalm touches its own cache files: in that user's home, or for a
 * user whose home is not writable (the web server's), a directory of its own
 * under the site's test cache. Never the temp directory, which is RAM here.
 */
function undefvar_cache_dir(): string {
	$home = trim((string)getenv('HOME'));
	if ($home !== '' && is_writable($home)) {
		$dir = $home . '/.cache/joinery_undefined_variables';
	} else {
		$dir = harness_shared_dir('undefined_variables') . '/uid' . (int)posix_geteuid();
	}
	if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
	return $dir;
}

/**
 * The repo-relative PHP files a check covers out of a changed set: existing
 * files under public_html/ or maintenance_scripts/, not vendored code, specs
 * or the test estate (whose suites share one global scope by design).
 */
function undefvar_files_to_check(array $changed, string $repo_root): array {
	$out = array();
	foreach ($changed as $rel) {
		$rel = (string)$rel;
		if (substr($rel, -4) !== '.php' || !is_file($repo_root . '/' . $rel)) { continue; }
		if (!preg_match('#^(public_html|maintenance_scripts)/#', $rel)) { continue; }
		if (preg_match('#(^|/)(vendor|node_modules|specs|tests)/#', $rel)) { continue; }
		$out[] = $rel;
	}
	sort($out);
	return array_values(array_unique($out));
}

/**
 * The source with every include/require whose target is not one plain string
 * literal replaced by null, keeping each line where it was.
 */
function undefvar_strip_dynamic_includes(string $src): string {
	$tokens = token_get_all($src);
	$n = count($tokens);
	$out = '';
	$includes = array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE);
	for ($i = 0; $i < $n; $i++) {
		$tok = $tokens[$i];
		if (!is_array($tok) || !in_array($tok[0], $includes, true)) {
			$out .= is_array($tok) ? $tok[1] : $tok;
			continue;
		}
		// The include's expression runs to the ; or , that ends it, or to the
		// ) that closes an enclosing call.
		$expr = '';
		$depth = 0;
		$parts = 0;
		$literal = true;
		for ($j = $i + 1; $j < $n; $j++) {
			$x = $tokens[$j];
			if (!is_array($x)) {
				if ($x === '(') { $depth++; }
				elseif ($x === ')') { if ($depth === 0) { break; } $depth--; }
				elseif (($x === ';' || $x === ',') && $depth === 0) { break; }
				else { $literal = false; }
			} elseif (!in_array($x[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
				$parts++;
				if ($x[0] !== T_CONSTANT_ENCAPSED_STRING) { $literal = false; }
			}
			$expr .= is_array($x) ? $x[1] : $x;
		}
		if ($literal && $parts === 1) {
			$out .= $tok[1];
			continue;
		}
		$out .= 'null' . str_repeat("\n", substr_count($expr, "\n"));
		$i = $j - 1;
	}
	return $out;
}

/**
 * Check $files (each label => the file's path; the label, normally its
 * repo-relative path, is what findings name) and return the findings, each
 * array('file', 'line', 'type', 'variable', 'message'). Throws when Psalm could
 * not run. $work is a directory this call may fill; $cache keeps Psalm's cache
 * between runs. Classes resolve from the tree at $repo_root.
 */
function undefvar_check(array $files, string $repo_root, string $work, string $cache): array {
	if (!$files) { return array(); }
	$bin = undefvar_psalm_bin();
	if ($bin === null) { throw new RuntimeException('Psalm is not installed (set JOINERY_PSALM, or install it under ~/tools/psalm)'); }

	$copies = array();   // copy path => label
	$n = 0;
	foreach ($files as $label => $source) {
		// Numbered, so two labels never share a copy, and kept under the
		// label's own name so a finding reads naturally while debugging.
		$copy = $work . '/tree/' . (++$n) . '/' . basename((string)$label);
		if (!is_dir(dirname($copy)) && !@mkdir(dirname($copy), 0770, true) && !is_dir(dirname($copy))) {
			throw new RuntimeException('could not create ' . dirname($copy));
		}
		file_put_contents($copy, undefvar_strip_dynamic_includes((string)file_get_contents($source)));
		$copies[realpath($copy)] = (string)$label;
	}

	// Classes resolve from the real tree: core and every plugin's data/ and
	// includes/. Without them Psalm abandons a function at its first unknown
	// class and checks nothing after it.
	$web = $repo_root . '/public_html';
	$extra = array($web . '/includes', $web . '/data');
	foreach (array_merge(glob($web . '/plugins/*/data', GLOB_ONLYDIR) ?: array(),
			glob($web . '/plugins/*/includes', GLOB_ONLYDIR) ?: array()) as $dir) {
		$extra[] = $dir;
	}
	$x = function ($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1); };
	$xml = '<?xml version="1.0"?>' . "\n"
		. '<psalm errorLevel="1" findUnusedCode="false" findUnusedBaselineEntry="false" phpVersion="8.5"'
		. ' allFunctionsGlobal="true" resolveFromConfigFile="false" cacheDirectory="' . $x($cache) . '">' . "\n<projectFiles>\n";
	foreach (array_keys($copies) as $copy) { $xml .= '<file name="' . $x($copy) . '"/>' . "\n"; }
	$xml .= "</projectFiles>\n<extraFiles>\n";
	foreach ($extra as $dir) { $xml .= '<directory name="' . $x($dir) . '"/>' . "\n"; }
	$xml .= "</extraFiles>\n</psalm>\n";
	file_put_contents($work . '/psalm.xml', $xml);

	$report = $work . '/report.json';
	@unlink($report);
	$cmd = 'nice ' . escapeshellarg($bin) . ' -c ' . escapeshellarg($work . '/psalm.xml')
		. ' --threads=2 --no-progress --monochrome --output-format=json --report=' . escapeshellarg($report) . ' 2>&1';
	$output = array();
	exec($cmd, $output, $rc);
	// Psalm exits 2 when it found issues, which at errorLevel 1 is always.
	$issues = is_file($report) ? json_decode((string)file_get_contents($report), true) : null;
	if (!is_array($issues) || ($rc !== 0 && $rc !== 2)) {
		$said = array_values(array_filter($output, function ($l) { return $l !== '' && $l[0] !== '['; }));
		throw new RuntimeException('Psalm did not finish (exit ' . $rc . '): ' . mb_substr(implode(' ', array_slice($said, 0, 4)), 0, 600));
	}

	return undefvar_findings($issues, function ($path) use ($copies) {
		$real = realpath($path) ?: $path;
		return $copies[$real] ?? null;
	});
}

/**
 * The findings kept out of Psalm's JSON issues, each array('file', 'line',
 * 'type', 'variable', 'message'). $repo_path maps an issue's file path to the
 * repo-relative file it stands for, or null for one that is not being checked.
 *
 * Besides superglobals, a finding on the very line Psalm first saw the
 * variable is dropped: that is an array built by assignment
 * ($page_vars['x'] = ...), which PHP allows without a warning.
 */
function undefvar_findings(array $issues, callable $repo_path): array {
	$keep = array('UndefinedVariable', 'PossiblyUndefinedVariable', 'PossiblyUndefinedGlobalVariable');
	$findings = array();
	foreach ($issues as $issue) {
		$type = (string)($issue['type'] ?? '');
		if (!in_array($type, $keep, true)) { continue; }
		$file = $repo_path((string)($issue['file_path'] ?? ''));
		if ($file === null) { continue; }
		$message = (string)($issue['message'] ?? '');
		$line = (int)($issue['line_from'] ?? 0);
		if (preg_match('/\$(_[A-Z]+|GLOBALS)\b/', $message)) { continue; }
		if (preg_match('/first seen on line (\d+)/', $message, $m) && (int)$m[1] === $line) { continue; }
		$variable = preg_match('/\$(\w+)/', $message, $m) ? '$' . $m[1] : '?';
		$findings[] = array('file' => $file, 'line' => $line, 'type' => $type, 'variable' => $variable, 'message' => $message);
	}
	usort($findings, function ($a, $b) { return array($a['file'], $a['line']) <=> array($b['file'], $b['line']); });
	return $findings;
}
