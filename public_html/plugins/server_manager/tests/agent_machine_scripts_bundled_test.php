<?php
/** @joinery-test
 * name: agent_machine_scripts_bundled
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
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

harness_finish();
