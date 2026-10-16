<?php
/** @joinery-test
 * name: agent_machine_scripts_bundled
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [maintenance_scripts/install_tools/host_housekeeping.sh, maintenance_scripts/install_tools/install_site_engine.sh]
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

harness_finish();
