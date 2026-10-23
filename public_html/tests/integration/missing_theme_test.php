<?php
/** @joinery-test
 * name: missing_theme
 * tier: db
 * env: any
 * needs: []
 */

/**
 * A site whose active theme's directory is not on disk still answers (B56,
 * from the final copy test's C8). A copy onto a host is built from the
 * release tree alone, and its restore brings the site's themes, but the key
 * page that opens the backup comes before the restore; and a theme folder can
 * be removed by hand. Its pages resolve through plugin and core paths, core's
 * own views and page class, as with no theme at all, and the request logs the
 * missing directory once.
 *
 * Each case runs in a child process that sets the theme in memory only, so
 * nothing on disk or in the database changes: a real request routed through
 * serve.php, as on a copy before its restore.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = PathHelper::getIncludePath('');
$child = harness_scratch_dir('missing_theme') . '/child_' . getmypid() . '.php';
file_put_contents($child, '<?php
list(, $log, $mode, $theme, $plugin, $uri) = $argv + array_fill(0, 6, "");
$_SERVER = array_merge($_SERVER, array("REQUEST_URI" => $uri !== "" ? $uri : "/", "HTTP_HOST" => "localhost",
	"REQUEST_METHOD" => "GET", "SCRIPT_NAME" => "/serve.php", "HTTPS" => "on", "SERVER_PORT" => 443, "REMOTE_ADDR" => "127.0.0.1"));
require_once(' . var_export($root . 'includes/PathHelper.php', true) . ');
ini_set("error_log", $log);
$g = Globalvars::get_instance();
$g->get_setting("theme_template");
if ($theme !== "") {
	$r = new ReflectionProperty(Globalvars::class, "settings");
	$r->setAccessible(true);
	$all = $r->getValue($g);
	$all["theme_template"] = $theme;
	if ($plugin !== "") { $all["active_theme_plugin"] = $plugin; }
	$r->setValue($g, $all);
}
if ($mode === "dir") {
	echo json_encode(array("dir" => PathHelper::getActiveThemeDirectory(), "again" => PathHelper::getActiveThemeDirectory(),
		"page" => PathHelper::getThemeFilePath("PublicPage.php", "includes")));
	exit;
}
register_shutdown_function(function () { fwrite(STDOUT, "\nSTATUS " . http_response_code() . "\n"); });
require ' . var_export($root . 'serve.php', true) . ';
');
harness_defer(function () use ($child) { @unlink($child); });

$run = function (string $mode, string $theme, string $plugin = '', string $uri = '/') use ($child) {
	$log = tempnam(sys_get_temp_dir(), 'mtl');
	$out = (string)shell_exec('php ' . escapeshellarg($child) . ' ' . escapeshellarg($log) . ' ' . escapeshellarg($mode) . ' '
		. escapeshellarg($theme) . ' ' . escapeshellarg($plugin) . ' ' . escapeshellarg($uri) . ' 2>/dev/null');
	$logged = (string)@file_get_contents($log);
	@unlink($log);
	return array($out, $logged);
};
$missing = 'harnessmissing' . bin2hex(random_bytes(3));

section('The active theme\'s directory is missing: core\'s own paths, logged once');

list($out, $log) = $run('dir', $missing);
$d = json_decode($out, true);
check(is_array($d) && $d['dir'] === null && $d['again'] === null, 'no theme directory is answered, and nothing is thrown', $out);
check(is_array($d) && substr((string)$d['page'], -strlen('includes/PublicPage.php')) === 'includes/PublicPage.php'
	&& strpos((string)$d['page'], '/theme/') === false, 'the page class is core\'s own', (string)($d['page'] ?? ''));
check(substr_count($log, "theme/{$missing} not found; pages resolve through core's own views") === 1,
	'the missing directory is logged once for the request, not per lookup', trim($log));

list($out, $log) = $run('dir', 'plugin', $missing);
$d = json_decode($out, true);
check(is_array($d) && $d['dir'] === null, 'a plugin theme whose plugin directory is missing resolves the same way', $out);

list($out, $log) = $run('dir', 'default');
$d = json_decode($out, true);
check(is_array($d) && $d['dir'] === 'theme/default' && $log === '', 'a theme that is on disk is the theme, and nothing is logged', $out);

section('A request routed through serve.php answers as with its own theme');

list($own) = $run('serve', '', '', '/login');
list($out, $log) = $run('serve', $missing, '', '/login');
preg_match('/STATUS (\d+)/', $own, $want);
preg_match('/STATUS (\d+)/', $out, $got);
check(!empty($got[1]) && $got[1] !== '500' && $got[1] === ($want[1] ?? ''),
	'the request is answered as the site answers it with its own theme, not failed', ($got[1] ?? 'no answer') . ' vs ' . ($want[1] ?? '?'));
check(strpos($log, 'directory not found. Please') === false, 'and no request fails on the missing directory', trim($log));

// The page a copy onto a host opens its backup with, before the restore brings
// the site's themes (the copy's tree is the release's alone).
list($own) = $run('serve', '', '', '/copy-key');
list($out, $log) = $run('serve', $missing, '', '/copy-key');
preg_match('/STATUS (\d+)/', $own, $want);
preg_match('/STATUS (\d+)/', $out, $got);
check(!empty($got[1]) && $got[1] !== '500' && $got[1] === ($want[1] ?? '') && strpos($log, 'directory not found. Please') === false,
	'the copy\'s key page is answered on a theme-less tree', ($got[1] ?? 'no answer') . ' vs ' . ($want[1] ?? '?'));

harness_finish();
