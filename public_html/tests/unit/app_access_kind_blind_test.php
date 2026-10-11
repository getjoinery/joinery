<?php
/** @joinery-test
 * name: app_access_kind_blind
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Keeping browser sign-in open (specs/mail_apps_plugin.md § Keeping browser
 * sign-in open): every rule about an app credential is written against its
 * purpose and its id, never against HOW it signs in. Today the one kind is the
 * app password; browser sign-in (OAuth) would be a second kind in the same
 * table, and must change no code outside core sign-in and the gateway.
 *
 * Mechanically: the app password kind — ApiKey::TYPE_APP_PASSWORD, or its
 * stored value as a quoted literal — may be named only by ApiKey, ApiAuth and
 * the logic that mints and revokes app credentials. VaultUnlock, the
 * mail_apps plugin, and every action descriptor stay blind to it. A new file
 * that names it fails here, which is the point: route the decision through
 * the purpose instead.
 *
 * Run: php tests/unit/app_access_kind_blind_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = rtrim(PathHelper::getIncludePath(''), '/');

// The files allowed to name the kind, repo-relative to public_html.
$allowed = array(
	'data/api_keys_class.php',
	'includes/ApiAuth.php',
	'logic/app_password_create_logic.php',
	'logic/app_credential_revoke_logic.php',
	'tests/unit/app_access_test.php',
	'tests/unit/app_access_kind_blind_test.php',
);

$pattern = '/TYPE_APP_PASSWORD|[\'"]app_password[\'"]/';

$scan = array();
foreach (array('includes', 'data', 'logic', 'adm', 'views', 'api', 'assets/js', 'plugins') as $dir) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		$path = $file->getPathname();
		if (!preg_match('/\.(php|js)$/', $path) || strpos($path, '/vendor/') !== false
				|| strpos($path, '/node_modules/') !== false || strpos($path, '/tests/') !== false) {
			continue;
		}
		$scan[] = $path;
	}
}

section('Only core sign-in names the app password kind');
$offenders = array();
foreach ($scan as $path) {
	$rel = ltrim(substr($path, strlen($root)), '/');
	if (in_array($rel, $allowed, true)) {
		continue;
	}
	$src = @file_get_contents($path);
	if ($src !== false && preg_match($pattern, $src)) {
		$offenders[] = $rel;
	}
}
check(count($scan) > 100, 'The scan read the tree', count($scan) . ' files');
check($offenders === array(), 'No file outside ApiKey, ApiAuth and the mint/revoke logic names the kind',
	implode(', ', $offenders));

section('The places the spec names are blind');
foreach (array('includes/VaultUnlock.php', 'plugins/mail_apps') as $rel) {
	$paths = is_dir($root . '/' . $rel)
		? array_filter($scan, function ($p) use ($root, $rel) { return strpos($p, $root . '/' . $rel . '/') === 0; })
		: array($root . '/' . $rel);
	$hit = array();
	foreach ($paths as $p) {
		if (preg_match($pattern, (string)@file_get_contents($p))) {
			$hit[] = basename($p);
		}
	}
	check($hit === array(), "$rel never names it", implode(', ', $hit));
}

section('Every action descriptor declares a purpose, never a kind');
$descriptor_kinds = array();
foreach ($scan as $path) {
	if (!preg_match('#/logic/[^/]+_logic\.php$#', $path)) {
		continue;
	}
	$src = (string)@file_get_contents($path);
	if (preg_match('/_logic_descriptor\s*\(/', $src) && preg_match($pattern, $src)
			&& !in_array(ltrim(substr($path, strlen($root)), '/'), $allowed, true)) {
		$descriptor_kinds[] = ltrim(substr($path, strlen($root)), '/');
	}
}
check($descriptor_kinds === array(), 'No action decides on a key\'s kind', implode(', ', $descriptor_kinds));

harness_finish();
