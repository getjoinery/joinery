<?php
/** @joinery-test
 * name: plugin_active_flag
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */

/**
 * Whether a plugin is switched on is plg_active, and nothing else.
 *
 * plg_status is a lifecycle note: it can say 'stale' (no longer in the upgrade
 * source's manifest) or 'error' while the plugin is switched on and running.
 * Read as "is it on", it silently drops running plugins: the host converger
 * stopped running the mailbox plugin's host installer on a box whose mailbox
 * read 'stale', so that box's Postfix never took a single installer change,
 * and the Composer check left those plugins' packages out.
 *
 * So no code asks plg_status whether a plugin is active — in SQL or in PHP,
 * directly or through a variable read from it.
 * Lifecycle code that reads the status as a status (install_bundle.php's
 * "inactive means installed and switched off") is not that question and is not
 * matched.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$public_html = rtrim(PathHelper::getIncludePath(''), '/');
$site_root = dirname($public_html);

$roots = array(
	$public_html . '/includes', $public_html . '/data', $public_html . '/logic', $public_html . '/utils',
	$public_html . '/adm', $public_html . '/ajax', $public_html . '/api', $public_html . '/views',
	$public_html . '/plugins', $site_root . '/maintenance_scripts',
);
$patterns = array(
	'SQL filter on the status'        => '/plg_status\s*(=|<>|!=)\s*(\'active\'|\?|:)/i',
	'PHP comparison with \'active\''  => '/get\(\s*[\'"]plg_status[\'"]\s*\)\s*\)?\s*(===|!==|==|!=)\s*[\'"]active[\'"]/',
	'PHP comparison, reversed'        => '/[\'"]active[\'"]\s*(===|!==|==|!=)\s*(\(string\))?\s*\$[a-z_>\-]+get\(\s*[\'"]plg_status/',
);

/** Variables a file assigns from plg_status: `$x = ...get('plg_status')...;` or `$x = $row['plg_status'];`. */
function status_variables(array $lines): array {
	$vars = array();
	foreach ($lines as $line) {
		if (preg_match('/\$(\w+)\s*=\s*[^;=]*(get\(\s*[\'"]plg_status[\'"]\s*\)|\[\s*[\'"]plg_status[\'"]\s*\])/', $line, $m)) {
			$vars[$m[1]] = true;
		}
	}
	return array_keys($vars);
}

/** Whether a line compares $var with 'active', either way round. */
function compares_with_active(string $line, string $var): bool {
	$v = preg_quote('$' . $var, '/');
	return preg_match('/' . $v . '\b\s*(===|!==|==|!=)\s*[\'"]active[\'"]/', $line) === 1
		|| preg_match('/[\'"]active[\'"]\s*(===|!==|==|!=)\s*' . $v . '\b/', $line) === 1;
}

$found = array();
$scanned = 0;
foreach ($roots as $root) {
	if (!is_dir($root)) { continue; }
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		$path = $file->getPathname();
		if (!preg_match('/\.(php|sh)$/', $path) || preg_match('#/(tests|vendor|node_modules)/#', $path)) { continue; }
		$scanned++;
		$lines = file($path);
		$status_vars = status_variables($lines);
		foreach ($lines as $n => $line) {
			if (preg_match('#^\s*(\#|//|\*|/\*)#', $line)) { continue; }   // a comment asks nothing
			foreach ($patterns as $what => $re) {
				if (preg_match($re, $line)) {
					$found[] = substr($path, strlen($site_root) + 1) . ':' . ($n + 1) . ' (' . $what . ')';
				}
			}
			foreach ($status_vars as $var) {
				if (compares_with_active($line, $var)) {
					$found[] = substr($path, strlen($site_root) + 1) . ':' . ($n + 1) . ' ($' . $var . ', read from plg_status)';
				}
			}
		}
	}
}

section('No code asks plg_status whether a plugin is on');
check($scanned > 500, 'the scan covered the tree', (string)$scanned . ' files');
check(count($found) === 0, 'every "is it active" question reads plg_active', implode('; ', $found));

section('The scan would catch the shapes it exists for');
$probe = array(
	'$q = $pdo->prepare("SELECT plg_name FROM plg_plugins WHERE plg_status = ?");',
	"WHERE plg_status = 'active'",
	"if (\$dep->get('plg_status') !== 'active') {",
	"if (\$row && (string)\$row->get('plg_status') === 'active') {",
);
foreach ($probe as $line) {
	$hit = false;
	foreach ($patterns as $re) { $hit = $hit || preg_match($re, $line) === 1; }
	check($hit, 'matched: ' . $line);
}
$two_step = array(
	"                    \$plugin_status = \$plugin['plugin'] ? \$plugin['plugin']->get('plg_status') : null;",
	"                    } elseif (\$plugin_status === 'active') {",
);
check(status_variables($two_step) === array('plugin_status') && compares_with_active($two_step[1], 'plugin_status'),
	'matched: a variable read from plg_status, compared with \'active\' on a later line');
check(!compares_with_active("if (\$plugin_status === 'error') {", 'plugin_status'), 'not matched: the same variable compared with another status');
$ok = array("\$status = (string)\$existing->get('plg_status');", "WHERE plg_active = 1", "if (\$status === 'inactive') {");
foreach ($ok as $line) {
	$hit = false;
	foreach ($patterns as $re) { $hit = $hit || preg_match($re, $line) === 1; }
	check(!$hit, 'not matched: ' . $line);
}

harness_finish();
