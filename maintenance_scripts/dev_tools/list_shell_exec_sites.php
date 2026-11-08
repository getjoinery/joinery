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
 * Each row: file, line, sink, CONST|VAR, first argument (trimmed).
 *   CONST  the command is a literal; nothing at run time can change it
 *   VAR    a variable or interpolation reaches the command; needs a reading
 *
 * Usage (from public_html):
 *   php ../maintenance_scripts/dev_tools/list_shell_exec_sites.php            tab-separated rows
 *   php ../maintenance_scripts/dev_tools/list_shell_exec_sites.php --summary  counts by class, sink and area
 *
 * @version 1.0 - first cut, for specs/shell_command_audit.md
 */

$root = getcwd();
if (!is_file($root . '/serve.php')) {
	fwrite(STDERR, "Run from public_html (serve.php not found in " . $root . ")\n");
	exit(2);
}
$summary = in_array('--summary', $argv, true);

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
		if (strpos($s, '/vendor/') !== false || strpos($s, '/tests/') !== false || strpos($s, '/node_modules/') !== false) continue;
		$files[] = $s;
	}
}
sort($files);

$rows = [];
foreach ($files as $f) {
	$toks = token_get_all((string)file_get_contents($f));
	$n = count($toks);
	for ($i = 0; $i < $n; $i++) {
		$t = $toks[$i];
		if (!is_array($t) || $t[0] !== T_STRING || !in_array(strtolower($t[1]), $sinks, true)) continue;

		// A function call: not preceded by -> :: function new, and followed by (
		$prev = $i - 1;
		while ($prev >= 0 && is_array($toks[$prev]) && $toks[$prev][0] === T_WHITESPACE) $prev--;
		if ($prev >= 0) {
			$pt = $toks[$prev];
			$ps = is_array($pt) ? $pt[0] : $pt;
			if (in_array($ps, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;
		}
		$next = $i + 1;
		while ($next < $n && is_array($toks[$next]) && $toks[$next][0] === T_WHITESPACE) $next++;
		if ($next >= $n || $toks[$next] !== '(') continue;

		// First argument: variables or interpolation anywhere in it make it VAR
		$depth = 0; $arg = ''; $var = false;
		for ($j = $next; $j < $n; $j++) {
			$x = $toks[$j];
			$s = is_array($x) ? $x[1] : $x;
			if ($s === '(') { $depth++; if ($depth === 1) continue; }
			if ($s === ')') { $depth--; if ($depth === 0) break; }
			if ($depth === 1 && $s === ',') break;
			if (is_array($x) && in_array($x[0], [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ENCAPSED_AND_WHITESPACE], true)) $var = true;
			if ($s === '$') $var = true;
			$arg .= $s;
		}
		$arg = preg_replace('/\s+/', ' ', trim($arg));
		$rows[] = [str_replace($root . '/', '', $f), $t[2], strtolower($t[1]), $var ? 'VAR' : 'CONST', mb_substr($arg, 0, 200)];
	}
}

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
