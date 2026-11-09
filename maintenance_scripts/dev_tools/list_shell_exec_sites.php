<?php
/**
 * list_shell_exec_sites.php — every place runtime PHP hands a string to a shell.
 *
 * Tokenizes each PHP file under the runtime directories (vendor, tests and
 * node_modules excluded) and prints one row per call to exec, shell_exec,
 * system, passthru, proc_open, popen or pcntl_exec. A method or static call of
 * the same name (PDO's ->exec()) is not a shell and is not listed, which is
 * why this count is far below a plain grep.
 *
 * Each row: file, line, sink, class, first argument (trimmed).
 *   CONST    the command is a literal; nothing at run time can change it
 *   ARGV     proc_open given an array: no shell parses it
 *   ESCAPED  every variable in it is escaped or cast where it is used:
 *            escapeshellarg()/escapeshellcmd(), array_map('escapeshellarg', ...),
 *            an (int)/(float) cast or intval()
 *   VAR      a variable reaches the command unescaped at this line (a command
 *            built earlier, an interpolation, or an escaped string handed to
 *            sh -c / bash -c / su -c, which parse it again); needs a reading
 *
 * The rule every call site follows is docs/shell_commands.md. The classifier is
 * also loaded by tests/unit/shell_call_sites_test.php, which fails on a new VAR
 * site; when included it only defines shell_exec_sites().
 *
 * Usage (from public_html):
 *   php ../maintenance_scripts/dev_tools/list_shell_exec_sites.php            tab-separated rows
 *   php ../maintenance_scripts/dev_tools/list_shell_exec_sites.php --summary  counts by class, sink and area
 *
 * @version 1.1 - ARGV and ESCAPED classes; the classifier is a function the test loads
 * @version 1.0 - first cut, for specs/shell_command_audit.md
 */

/**
 * Every shell call site in runtime PHP under $root (public_html).
 *
 * @return array<int, array{0:string,1:int,2:string,3:string,4:string}> file, line, sink, class, first argument
 */
function shell_exec_sites(string $root): array {
	$root = rtrim($root, "/");
	$dirs  = ['includes', 'adm', 'plugins', 'utils', 'logic', 'data', 'views', 'ajax', 'api', 'migrations', 'serve.php'];
	$sinks = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];

	$files = [];
	foreach ($dirs as $d) {
		$p = $root . '/' . $d;
		if (is_file($p)) { $files[] = $p; continue; }
		if (!is_dir($p)) continue;
		$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
		foreach ($walk as $f) {
			$s = (string)$f;
			if (substr($s, -4) !== '.php') continue;
			$rel = substr($s, strlen($root));   // excluded by where it sits in the tree, not by where the tree sits
			if (strpos($rel, '/vendor/') !== false || strpos($rel, '/tests/') !== false || strpos($rel, '/node_modules/') !== false) continue;
			$files[] = $s;
		}
	}
	sort($files);

	$rows = [];
	foreach ($files as $f) {
		$toks = array_values(array_filter(token_get_all((string)file_get_contents($f)),
			function ($t) { return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); }));
		$n = count($toks);
		for ($i = 0; $i < $n; $i++) {
			$t = $toks[$i];
			if (!is_array($t) || $t[0] !== T_STRING || !in_array(strtolower($t[1]), $sinks, true)) continue;

			// A function call: not preceded by -> :: function new, and followed by (
			if ($i > 0) {
				$pt = $toks[$i - 1];
				$ps = is_array($pt) ? $pt[0] : $pt;
				if (in_array($ps, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;
			}
			if ($i + 1 >= $n || $toks[$i + 1] !== '(') continue;

			// The first argument's tokens.
			$arg = [];
			$depth = 0;
			for ($j = $i + 1; $j < $n; $j++) {
				$s = is_array($toks[$j]) ? $toks[$j][1] : $toks[$j];
				if ($s === '(' || $s === '[' || $s === '{' || $s === '${') { $depth++; if ($depth === 1 && $s === '(') continue; }
				if ($s === ')' || $s === ']' || $s === '}') { $depth--; if ($depth === 0) break; }
				if ($depth === 1 && $s === ',') break;
				$arg[] = $toks[$j];
			}
			$text = preg_replace('/\s+/', ' ', trim(implode(' ', array_map(function ($x) { return is_array($x) ? $x[1] : $x; }, $arg))));
			$rows[] = [str_replace($root . '/', '', $f), $t[2], strtolower($t[1]), shell_exec_site_class(strtolower($t[1]), $arg), mb_substr($text, 0, 200)];
		}
	}
	return $rows;
}

/** CONST, ARGV, ESCAPED or VAR for one call's first-argument tokens (no whitespace or comments). */
function shell_exec_site_class(string $sink, array $arg): string {
	$first = $arg[0] ?? null;
	if ($sink === 'proc_open' && ($first === '[' || (is_array($first) && $first[0] === T_ARRAY))) return 'ARGV';

	// A shell started on a string (sh -c, bash -c, su -c) parses that string
	// again, so escaping it once for the outer shell is no escaping at all.
	$reparse = false;
	foreach ($arg as $x) {
		if (is_array($x) && in_array($x[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
			&& preg_match('/\b(?:ba|da|z)?sh\s+(?:-[a-z]+\s+)*-[a-z]*c\b|\bsu\b.*\s-c\b/', $x[1])) {
			$reparse = true;
		}
	}

	$escapers = ['escapeshellarg', 'escapeshellcmd', 'intval', 'floatval'];
	$any = false;
	$n = count($arg);
	for ($k = 0; $k < $n; $k++) {
		$x = $arg[$k];
		$id = is_array($x) ? $x[0] : $x;
		// An escaping call: everything up to its closing paren is escaped.
		$name = is_array($x) && in_array($x[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) ? strtolower(ltrim($x[1], '\\')) : '';
		$opens = ($arg[$k + 1] ?? null) === '(';
		$maps_escape = $name === 'array_map' && $opens && is_array($arg[$k + 2] ?? null)
			&& $arg[$k + 2][0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($arg[$k + 2][1], '\'"'), ['escapeshellarg', 'escapeshellcmd'], true);
		if ($opens && (in_array($name, $escapers, true) || $maps_escape)) {
			$any = true;
			$depth = 0;
			for ($k = $k + 1; $k < $n; $k++) {
				if ($arg[$k] === '(') $depth++;
				if ($arg[$k] === ')') { $depth--; if ($depth === 0) break; }
			}
			continue;
		}
		// A numeric cast covers the operand that follows: a variable and its -> / :: / [...] chain.
		if (in_array($id, [T_INT_CAST, T_DOUBLE_CAST], true)) {
			$any = true;
			$k++;
			if (($arg[$k] ?? null) === '(') {
				$depth = 0;
				for (; $k < $n; $k++) {
					if ($arg[$k] === '(') $depth++;
					if ($arg[$k] === ')') { $depth--; if ($depth === 0) break; }
				}
				continue;
			}
			while ($k + 1 < $n) {
				$nx = $arg[$k + 1];
				$nid = is_array($nx) ? $nx[0] : $nx;
				if (in_array($nid, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) { $k += 2; continue; }
				if ($nx === '[') {
					$depth = 0;
					for ($k = $k + 1; $k < $n; $k++) {
						if ($arg[$k] === '[') $depth++;
						if ($arg[$k] === ']') { $depth--; if ($depth === 0) break; }
					}
					continue;
				}
				break;
			}
			continue;
		}
		if (in_array($id, [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ENCAPSED_AND_WHITESPACE], true) || $x === '$') {
			return 'VAR';
		}
	}
	if ($any && $reparse) return 'VAR';
	return $any ? 'ESCAPED' : 'CONST';
}

// Included (by the test): define the functions and stop.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
	return;
}

$root = getcwd();
if (!is_file($root . '/serve.php')) {
	fwrite(STDERR, "Run from public_html (serve.php not found in " . $root . ")\n");
	exit(2);
}
$summary = in_array('--summary', $argv, true);
$rows = shell_exec_sites($root);

if (!$summary) {
	$out = fopen('php://stdout', 'w');
	foreach ($rows as $r) fputcsv($out, $r, "\t", '"', '');
	exit(0);
}

$byClass = []; $bySink = []; $byArea = []; $byFile = [];
foreach ($rows as $r) {
	$byClass[$r[3]] = ($byClass[$r[3]] ?? 0) + 1;
	$bySink[$r[2]]  = ($bySink[$r[2]] ?? 0) + 1;
	if ($r[3] !== 'VAR') continue;
	$parts = explode('/', $r[0]);
	$area  = $parts[0] === 'plugins' ? 'plugins/' . ($parts[1] ?? '') : $parts[0];
	$byArea[$area] = ($byArea[$area] ?? 0) + 1;
	$byFile[$r[0]] = ($byFile[$r[0]] ?? 0) + 1;
}
arsort($bySink); arsort($byArea); arsort($byFile);
echo count($rows), " call sites in ", count(array_unique(array_column($rows, 0))), " files\n\n";
echo "By class:\n"; foreach ($byClass as $k => $v) printf("  %5d %s\n", $v, $k);
echo "\nBy sink:\n"; foreach ($bySink as $k => $v) printf("  %5d %s\n", $v, $k);
echo "\nVAR sites by area:\n"; foreach ($byArea as $k => $v) printf("  %5d %s\n", $v, $k);
echo "\nVAR sites by file (", count($byFile), " files):\n"; foreach ($byFile as $k => $v) printf("  %5d %s\n", $v, $k);
