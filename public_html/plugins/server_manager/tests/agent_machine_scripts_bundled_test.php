<?php
/** @joinery-test
 * name: agent_machine_scripts_bundled
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [maintenance_scripts/install_tools/host_housekeeping.sh, maintenance_scripts/install_tools/install_site_engine.sh, maintenance_scripts/install_tools/install.sh, maintenance_scripts/sysadmin_tools/setup_ssl.sh, maintenance_scripts/sysadmin_tools/arm_ssl_retry.sh, maintenance_scripts/sysadmin_tools/recreate_site.sh, maintenance_scripts/install_tools/site_refresh.sh]
 */
/**
 * Every script a host word runs ships in the support bundle.
 *
 * A machine with no site of its own (a container host) runs a script word's
 * file out of the agent's support bundle, verified against the bundle's own
 * manifest; a script the bundle does not carry makes the word refuse on every
 * host, which nothing shows until a job is sent there. This reads each
 * Register(Primitive{...}) in the agent's primitives that says Machine: true
 * and names a ScriptPath, resolves the path, and checks it against
 * SupportBundlePublisher::declaredContents(). Read from the agent checkout
 * named by server_manager_agent_source_path, as agent_engine_rule reads it; a
 * box with no agent source skips.
 *
 * Host housekeeping runs out of the same bundle on such a machine, so every
 * file host_housekeeping.sh and the engine installer it runs name beside
 * themselves ships too (site_container_engine B32: the engine installer and
 * its filter were left out, and no host converged its engine after install).
 *
 * A bundled script that sources install.sh runs install.sh's functions there,
 * so every file the functions it reaches use beside install.sh ships too
 * (B109: setup_ssl.sh's certificate retry armer was left out).
 * Run: php plugins/server_manager/tests/agent_machine_scripts_bundled_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SupportBundlePublisher.php'));

/**
 * Each Register(Primitive{ ... }) block in a Go source, braces matched outside
 * string literals.
 */
function primitive_blocks(string $go): array {
	$blocks = array();
	$offset = 0;
	while (($start = strpos($go, 'Register(Primitive{', $offset)) !== false) {
		$i = $start + strlen('Register(Primitive');
		$depth = 0;
		$len = strlen($go);
		for (; $i < $len; $i++) {
			$c = $go[$i];
			if ($c === '"' || $c === '`') {
				$close = $c;
				for ($i++; $i < $len && $go[$i] !== $close; $i++) {
					if ($close === '"' && $go[$i] === '\\') { $i++; }
				}
				continue;
			}
			if ($c === '{') { $depth++; }
			if ($c === '}') { $depth--; if ($depth === 0) { break; } }
		}
		$blocks[] = substr($go, $start, $i - $start + 1);
		$offset = $i + 1;
	}
	return $blocks;
}

section('Every host word\'s script ships in the support bundle');

$src = AgentDistPublisher::sourcePath();
$dir = $src ? rtrim($src, '/') . '/primitives' : '';
if ($dir === '' || !is_dir($dir)) {
	harness_skip('the agent\'s primitives are read from its source checkout', 'no agent source on this box');
} else {
	$consts = array();
	$machine = array();
	foreach (glob($dir . '/*.go') as $file) {
		if (substr($file, -8) === '_test.go') { continue; }
		$go = (string)file_get_contents($file);
		if (preg_match_all('/^\s*(?:const\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"([^"]+)"/m', $go, $m, PREG_SET_ORDER)) {
			foreach ($m as $c) { $consts[$c[1]] = $c[2]; }
		}
		foreach (primitive_blocks($go) as $block) {
			if (!preg_match('/\bMachine:\s*true\b/', $block)) { continue; }
			if (!preg_match('/\bName:\s*"([a-z_]+)"/', $block, $nm)) { continue; }
			if (!preg_match('/\bScriptPath:\s*("([^"]+)"|[A-Za-z_][A-Za-z0-9_]*)/', $block, $sp)) { continue; }
			$machine[$nm[1]] = $sp[1];
		}
	}
	check(count($machine) >= 20, 'the host words that run a script are found', count($machine) . ' found');
	check(isset($machine['site_quiet_host']), 'site_quiet_host among them');

	$bundled = SupportBundlePublisher::declaredContents();
	foreach ($machine as $word => $ref) {
		$path = ($ref[0] === '"') ? trim($ref, '"') : ($consts[$ref] ?? '');
		check($path !== '', "{$word}: its ScriptPath resolves", $ref);
		if ($path !== '') {
			check(in_array($path, $bundled, true), "{$word}: {$path} is in the support bundle",
				'add it to SupportBundlePublisher::$contents, with what it sources');
		}
	}
}

section('Every file host housekeeping and the engine installer use beside themselves ships in the bundle (B32)');
// Read as the bundle runs them: "${SCRIPT_DIR}/<path>" relative to
// install_tools/, in code (comment lines dropped), the tree root itself apart.
$hk_bundled = SupportBundlePublisher::declaredContents();
$hk_root = dirname(PathHelper::getRootDir());
foreach (array('host_housekeeping.sh', 'install_site_engine.sh') as $hk_script) {
	$hk_rel = 'maintenance_scripts/install_tools/' . $hk_script;
	check(in_array($hk_rel, $hk_bundled, true), "{$hk_script} is in the support bundle");
	$hk_code = preg_replace('/^\s*#.*$/m', '', (string)file_get_contents($hk_root . '/' . $hk_rel));
	preg_match_all('#\$\{?SCRIPT_DIR\}?/([A-Za-z0-9_./-]+)#', $hk_code, $hk_m);
	$hk_paths = array();
	foreach (array_unique($hk_m[1]) as $hk_ref) {
		$hk_parts = array();
		foreach (explode('/', 'maintenance_scripts/install_tools/' . $hk_ref) as $hk_part) {
			if ($hk_part === '..') { array_pop($hk_parts); } elseif ($hk_part !== '' && $hk_part !== '.') { $hk_parts[] = $hk_part; }
		}
		$hk_path = implode('/', $hk_parts);
		if ($hk_path === '' || $hk_path === 'maintenance_scripts' || $hk_path === $hk_rel) { continue; }
		$hk_paths[] = $hk_path;
		check(in_array($hk_path, $hk_bundled, true), "{$hk_script}: {$hk_path} is in the support bundle",
			'add it to SupportBundlePublisher::$contents');
	}
	check(count($hk_paths) >= 5, "{$hk_script}: the files it uses beside it are found", implode(', ', $hk_paths));
}
check(strpos((string)file_get_contents($hk_root . '/maintenance_scripts/install_tools/host_housekeeping.sh'), 'bash "${SIE}" converge') !== false,
	'host housekeeping converges the engine on a host that has it');

section('Every file a bundled script reaches through install.sh ships in the bundle (B109)');
// A bundled script that sources install.sh runs install.sh's functions out of
// the bundle, so every file those functions use beside install.sh ships too
// (B109: setup_ssl.sh's certificate retry is armed by arm_ssl_retry.sh, which
// the bundle left out, and no container host ever retried a certificate).
// Read as bash runs it: the part of install.sh a sourcing script loads (up to
// its sourced-mode return), split into its functions; from each sourcing
// script, the functions it calls, then theirs in turn. A call is a name in
// command position (a line's start, after ; & | ( ) { ! $( or a backtick, after
// then/do/else/if/elif/while/until/time/exec, past any VAR=value prefix), or a
// bare word handed to trap, eval, command, nice, nohup, timeout, env or xargs.
// A callback held in a variable ("$cb") cannot be resolved this way and is not
// followed. A file named only in a message (a print_*, echo or printf line) is
// advice to an operator, not a use; a real use on such a line is missed.
$si_bundled = SupportBundlePublisher::declaredContents();
$si_root = dirname(PathHelper::getRootDir());
$si_rel = 'maintenance_scripts/install_tools/install.sh';
$si_lines = file($si_root . '/' . $si_rel, FILE_IGNORE_NEW_LINES);
$si_end = count($si_lines);
foreach ($si_lines as $si_i => $si_line) {
	if (strpos($si_line, 'if [ "${BASH_SOURCE[0]}" != "${0}" ]; then') === 0) { $si_end = $si_i; break; }
}
check($si_end < count($si_lines), 'install.sh\'s sourced-mode return is found', 'line ' . ($si_end + 1));
$si_funcs = array();
$si_top = '';
$si_cur = null;
for ($si_i = 0; $si_i < $si_end; $si_i++) {
	$si_line = $si_lines[$si_i];
	if ($si_cur === null && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\(\)\s*\{(.*)$/', $si_line, $si_m)) {
		$si_funcs[$si_m[1]] = '';
		if (preg_match('/^(.*)\}\s*$/', $si_m[2], $si_one)) { $si_funcs[$si_m[1]] = $si_one[1] . "\n"; } else { $si_cur = $si_m[1]; }
		continue;
	}
	if ($si_cur !== null && preg_match('/^\}\s*(#.*)?$/', $si_line)) { $si_cur = null; continue; }
	if (preg_match('/^\s*#/', $si_line)) { continue; }
	if ($si_cur !== null) { $si_funcs[$si_cur] .= $si_line . "\n"; } else { $si_top .= $si_line . "\n"; }
}
check(count($si_funcs) >= 50 && isset($si_funcs['provision_origin_cert'], $si_funcs['install_ssl_retry_timer']),
	'install.sh\'s functions are found', count($si_funcs) . ' found');
// A function whose closing line was not recognised swallows the ones after it,
// whose calls are then never followed: a definition inside a body is that.
$si_swallowers = array_keys(array_filter($si_funcs, function ($body) {
	return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\(\)\s*\{/m', $body) === 1;
}));
check($si_swallowers === array(), 'no function of install.sh swallowed the next', implode(', ', $si_swallowers));

// The files a piece of install.sh uses beside itself, as tree paths.
$si_files = function (string $code): array {
	$code = preg_replace('/^\s*(print_[a-z]+|echo|printf)\b.*$/m', '', $code);
	preg_match_all('#\$\{SCRIPT_DIR(?::-\$\{BASH_SOURCE%/\*\})?\}/([A-Za-z0-9_./-]+)|\$SCRIPT_DIR/([A-Za-z0-9_./-]+)#', $code, $m);
	$paths = array();
	foreach (array_filter(array_merge($m[1], $m[2])) as $ref) {
		$parts = array();
		foreach (explode('/', 'maintenance_scripts/install_tools/' . $ref) as $part) {
			if ($part === '..') { array_pop($parts); } elseif ($part !== '' && $part !== '.') { $parts[] = $part; }
		}
		$paths[] = implode('/', $parts);
	}
	return array_unique($paths);
};
// The install.sh functions a piece of bash calls (the forms above).
$si_calls = function (string $code) use ($si_funcs): array {
	$called = array();
	foreach (array_keys($si_funcs) as $name) {
		$q = preg_quote($name, '/');
		if (preg_match('/(?:^|[;&|(){!`]|\$\(|\b(?:then|do|else|if|elif|while|until|time|exec))[ \t]*'
				. '(?:[A-Za-z_][A-Za-z0-9_]*=\S*[ \t]+)*' . $q . '(?![A-Za-z0-9_(])/m', $code)
			|| preg_match('/\b(?:trap|eval|command|nice|nohup|timeout|env|xargs)\b[^\n;|&]*?[\s\'"]' . $q . '(?![A-Za-z0-9_])/', $code)) {
			$called[] = $name;
		}
	}
	return $called;
};

// The walk's own call forms, on a function install.sh has.
$si_probe = function (string $snippet) use ($si_calls): bool {
	return in_array('provision_origin_cert', $si_calls($snippet), true);
};
foreach (array(
	"case \"\$x\" in\n    a) provision_origin_cert ;;\nesac" => 'in a one-line case arm',
	"if false; then :\nelif provision_origin_cert; then :; fi" => 'after elif',
	'QUIET=1 DOMAIN=x provision_origin_cert "$d"' => 'behind VAR=value assignments',
	'trap provision_origin_cert EXIT' => 'handed to trap',
	"trap 'provision_origin_cert' EXIT" => 'handed to trap quoted',
	'timeout 60 provision_origin_cert "$d"' => 'handed to timeout',
	'x=`provision_origin_cert "$d"`' => 'in backticks',
) as $si_snippet => $si_form) {
	check($si_probe($si_snippet), "a call {$si_form} is followed");
}
check(!$si_probe('print_info "run provision_origin_cert by hand"') && !$si_probe("awk '/^provision_origin_cert\\(\\) \\{/' f"),
	'a name in a message or a pattern is not a call');

$si_sourcers = array();
$si_reached = array();
foreach ($si_bundled as $si_script) {
	if (substr($si_script, -3) !== '.sh' || $si_script === $si_rel) { continue; }
	$si_code = preg_replace('/^\s*#.*$/m', '', (string)file_get_contents($si_root . '/' . $si_script));
	if (!preg_match('/^\s*(\.|source)\s+"(\$\{?INSTALL_SH\}?|[^"]*install\.sh)"/m', $si_code)) { continue; }
	$si_name = basename($si_script);
	$si_seen = array();
	$si_queue = $si_calls($si_code);
	while ($si_queue) {
		$si_f = array_shift($si_queue);
		if (isset($si_seen[$si_f])) { continue; }
		$si_seen[$si_f] = true;
		foreach ($si_calls($si_funcs[$si_f]) as $si_g) { if (!isset($si_seen[$si_g])) { $si_queue[] = $si_g; } }
	}
	$si_used = array_fill_keys($si_files($si_top), 'install.sh as it loads');
	foreach (array_keys($si_seen) as $si_f) {
		foreach ($si_files($si_funcs[$si_f]) as $si_path) { $si_used[$si_path] = $si_used[$si_path] ?? $si_f . '()'; }
	}
	$si_sourcers[$si_name] = array_keys($si_used);
	$si_reached[$si_name] = array_keys($si_seen);
	foreach ($si_used as $si_path => $si_by) {
		check(in_array($si_path, $si_bundled, true), "{$si_name}: {$si_path} is in the support bundle",
			"used by {$si_by}; add it to SupportBundlePublisher::\$contents");
	}
}
check(isset($si_sourcers['setup_ssl.sh'], $si_sourcers['recreate_site.sh']), 'the bundled scripts that source install.sh are found',
	implode(', ', array_keys($si_sourcers)));
check(in_array('maintenance_scripts/sysadmin_tools/arm_ssl_retry.sh', $si_sourcers['setup_ssl.sh'] ?? array(), true),
	'setup_ssl.sh reaches the certificate retry\'s armer through install.sh');
$si_server_setup = array_keys(array_filter($si_reached, function ($fs) { return in_array('do_server_setup', $fs, true); }));
check($si_server_setup === array(), 'no bundled script reaches do_server_setup, which site_refresh.sh only hashes',
	implode(', ', $si_server_setup));

harness_finish();
