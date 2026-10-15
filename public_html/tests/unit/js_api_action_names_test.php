<?php
/** @joinery-test
 * name: js_api_action_names
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Every API action a page's script names by a literal is a real, exposed
 * action, by the rule the API itself resolves names with
 * (api_resolve_logic_path): a bare name is a core logic file,
 * logic/{name}_logic.php or a theme's override of it; a plugin/name pair is
 * plugins/{plugin}/logic/{name}_logic.php; and either is exposed only with a
 * {name}_logic_descriptor().
 *
 * The Drive protection dialog asked for drive/level_batch, a plugin's shape for
 * a core action, so every level change ended in "Unknown action" and its files
 * were converted only later, by the vault's deferred work. Nothing looked: the
 * name is a string in a script. Two shapes are read: a direct call
 * (joineryApi.post('name'), api.post('name'), and get) and a progress-loop
 * config (assets/js/ceremony-batch.js), an action followed by its payload. A
 * name a script builds at run time ('boards/' + name) is not a literal and is
 * not read.
 *
 * Runs offline, no DB: it reads files and never includes them.
 * Run: php tests/unit/js_api_action_names_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = PathHelper::getIncludePath('');

/** Every script and page source a browser runs, by path under the web root. */
function action_name_sources(string $root): array {
	$out = array();
	foreach (array('assets', 'views', 'adm', 'theme', 'plugins') as $top) {
		if (!is_dir($root . $top)) {
			continue;
		}
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $top, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			$path = $file->getPathname();
			if (!preg_match('/\.(js|php)$/', $path) || preg_match('#/(tests|vendor|node_modules)/#', $path)
				|| preg_match('/\.min\.js$/', $path)) {
				continue;
			}
			$out[] = substr($path, strlen($root));
		}
	}
	sort($out);
	return $out;
}

/** The literal action names one source sends: name => first line it is named on. */
function action_names_in(string $text): array {
	$found = array();
	$shapes = array(
		// joineryApi.post('name' / api.get("name"
		'/\b(?:joineryApi|api)\.(?:post|get)\(\s*[\'"]([a-z0-9_]+(?:\/[a-z0-9_]+)?)[\'"]/',
		// a progress-loop config: action: 'name', then payload (JS or PHP)
		'/[\'"]?action[\'"]?\s*(?::|=>)\s*[\'"]([a-z0-9_]+(?:\/[a-z0-9_]+)?)[\'"]\s*,\s*[\'"]?payload[\'"]?\s*(?::|=>)/',
	);
	foreach ($shapes as $re) {
		if (preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[1] as $hit) {
				$name = $hit[0];
				if (!isset($found[$name])) {
					$found[$name] = substr_count(substr($text, 0, $hit[1]), "\n") + 1;
				}
			}
		}
	}
	return $found;
}

/** Why a name does not resolve, or null when it does. */
function action_name_problem(string $root, string $name): ?string {
	if (strpos($name, '/') !== false) {
		list($plugin, $action) = explode('/', $name, 2);
		$file = 'plugins/' . $plugin . '/logic/' . $action . '_logic.php';
		$candidates = array($file);
	} else {
		$action = $name;
		$candidates = array('logic/' . $action . '_logic.php');
		foreach (glob($root . 'theme/*/logic/' . $action . '_logic.php') ?: array() as $t) {
			$candidates[] = substr($t, strlen($root));
		}
	}
	foreach ($candidates as $c) {
		if (is_file($root . $c)) {
			if (preg_match('/function\s+' . preg_quote($action, '/') . '_logic_descriptor\s*\(/', file_get_contents($root . $c))) {
				return null;
			}
			return $c . ' has no ' . $action . '_logic_descriptor(), so the API does not expose it';
		}
	}
	return 'no ' . implode(' or ', $candidates) . ' (the API answers Unknown action)';
}

section('The rule reads a known name each way');
check(action_name_problem($root, 'drive_level_batch') === null, 'drive_level_batch resolves (core)');
check(action_name_problem($root, 'drive/level_batch') !== null, 'drive/level_batch does not (no drive plugin)');
check(action_name_problem($root, 'mailbox/seal_batch') === null, 'mailbox/seal_batch resolves (plugin)');
$probe = action_names_in("x.setAttribute('data-ceremony-batch', JSON.stringify({\n\taction: 'drive_level_batch',\n\tpayload: { folder_id: 1 }\n}));\napi.post('drive_list', {});");
check(isset($probe['drive_level_batch'], $probe['drive_list']), 'both shapes are read', json_encode($probe));

section('Every literal action name a page sends resolves');
$named = 0;
$bad = array();
foreach (action_name_sources($root) as $rel) {
	foreach (action_names_in(file_get_contents($root . $rel)) as $name => $line) {
		$named++;
		$why = action_name_problem($root, $name);
		if ($why !== null) {
			$bad[] = $rel . ':' . $line . ' names ' . $name . ': ' . $why;
		}
	}
}
check($named > 50, 'the scan found the pages\' action names (' . $named . ')');
check(empty($bad), 'every name resolves', implode("\n", $bad));

harness_finish();
