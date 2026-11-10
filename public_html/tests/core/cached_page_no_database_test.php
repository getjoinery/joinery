<?php
/** @joinery-test
 * name: cached_page_no_database
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A page served from the page cache never touches the database
 * (specs/request_path_speedups.md WP1).
 *
 * The command line starts no session, and the session is where a cached
 * request loads its first core classes, so this serves serve.php from a
 * local web process: a public page is requested once to store it, then
 * again, and the request the cache answers must have run no statement and
 * opened no database link. The page cache is pointed at a scratch
 * directory, so the site's own cache is neither read nor written.
 *
 * It also pins the rule that makes this possible: the class autoloader
 * takes a core class to the theme chain only when some theme or plugin
 * ships a file of that name, and a theme's PublicPage is still the one
 * that loads.
 *
 * Run: php tests/run.php --only=tests/core/cached_page_no_database_test.php
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// ── The autoloader's rule ───────────────────────────────────────────────────
section('Which core classes go to the theme chain');

$overridable = new ReflectionMethod('ClassAutoloader', 'overridable');
$names = $overridable->invoke(null);
check(is_array($names), 'the overridable names are an array');
check(!isset($names['PageProbe']) && !isset($names['SessionControl']) && !isset($names['StaticPageCache']),
	'a core class no theme or plugin ships is not taken to the theme chain');

$expected = array();
foreach (array('theme', 'plugins') as $kind) {
	foreach (glob(PathHelper::getIncludePath($kind) . '/*/includes/*.php') ?: array() as $file) {
		$name = basename($file, '.php');
		if (is_file(PathHelper::getIncludePath('includes/' . $name . '.php'))) {
			$expected[$name] = true;
		}
	}
}
ksort($expected);
$got = $names;
ksort($got);
check($got === $expected, 'the names are exactly the core includes/ files a theme or plugin also ships',
	'got ' . json_encode(array_keys($got)) . ', expected ' . json_encode(array_keys($expected)));

$theme_dir = PathHelper::getActiveThemeDirectory();
$theme_page = $theme_dir ? PathHelper::getIncludePath($theme_dir . '/includes/PublicPage.php') : '';
if ($theme_page !== '' && is_file($theme_page)) {
	check(isset($names['PublicPage']), 'PublicPage, which the active theme ships, is taken to the theme chain');
	if (!class_exists('PublicPage', false)) {
		class_exists('PublicPage');
	}
	$loaded = (new ReflectionClass('PublicPage'))->getFileName();
	check(realpath($loaded) === realpath($theme_page), "the active theme's PublicPage is the one loaded", (string)$loaded);
} else {
	harness_skip('the active theme ships no PublicPage of its own');
}

// ── A cached page over a real web session ───────────────────────────────────
section('A cached page runs no statement and opens no link');

$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = 0;
if ($sock) {
	$name = (string)stream_socket_get_name($sock, false);
	fclose($sock);
	$port = (int)substr($name, strrpos($name, ':') + 1);
}
$dir = harness_scratch_dir('cached_page');
@mkdir($dir . '/cache', 0700, true);
@mkdir($dir . '/sessions', 0700, true);
$log = $dir . '/requests.log';
$router = $dir . '/router.php';
file_put_contents($router, '<?php
$root = getenv("CPT_ROOT");
chdir($root);
$path = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$_GET["__route"] = ltrim($path, "/");
$_REQUEST["__route"] = $_GET["__route"];
require_once($root . "/includes/PathHelper.php");
require_once($root . "/includes/Globalvars.php");
StaticPageCache::setCacheDirForTests(getenv("CPT_CACHE"));
register_shutdown_function(function () {
	$connected = class_exists("DbConnector", false)
		&& (new ReflectionProperty("DbConnector", "instance"))->getValue() !== null;
	$hit = false;
	foreach (headers_list() as $header) {
		if (stripos($header, "X-Cache: HIT") === 0) { $hit = true; }
	}
	file_put_contents(getenv("CPT_LOG"), json_encode(array(
		"uri" => $_SERVER["REQUEST_URI"],
		"hit" => $hit,
		"session" => session_status() === PHP_SESSION_ACTIVE || session_id() !== "",
		"connected" => $connected,
		"statements" => class_exists("GuardedPdo", false) ? GuardedPdo::$statements : 0,
	)) . "\n", FILE_APPEND);
});
require($root . "/serve.php");
');

$proc = null;
if ($port) {
	$env = array_merge(getenv(), array(
		'CPT_ROOT' => PathHelper::getIncludePath(''),
		'CPT_CACHE' => $dir . '/cache',
		'CPT_LOG' => $log,
	));
	$proc = proc_open(array('php', '-d', 'session.save_path=' . $dir . '/sessions', '-S', '127.0.0.1:' . $port, $router),
		array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, null, $env);
	$up = false;
	for ($i = 0; $i < 100 && is_resource($proc); $i++) {
		$s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
		if ($s) { fclose($s); $up = true; break; }
		usleep(50000);
	}
	if (!$up && is_resource($proc)) { proc_terminate($proc); proc_close($proc); $proc = null; }
}
harness_defer(function () use (&$proc, $dir) {
	if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
	foreach (array('cache', 'sessions', '') as $sub) {
		foreach (glob(rtrim($dir . '/' . $sub, '/') . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
			if (is_file($f)) { @unlink($f); }
		}
		@rmdir(rtrim($dir . '/' . $sub, '/'));
	}
});

if (!is_resource($proc)) {
	harness_skip('no local web process could start');
	harness_finish();
	exit;
}

$jar = $dir . '/cookies.txt';
$get = function (string $path) use ($port, $jar, $log) {
	$ch = curl_init('http://127.0.0.1:' . $port . $path);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_COOKIEJAR => $jar,
		CURLOPT_COOKIEFILE => $jar,
		CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
	));
	curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	$lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
	$last = $lines ? json_decode((string)end($lines), true) : null;
	return array('status' => $status, 'log' => is_array($last) ? $last : array());
};

// The first public page the cache will hold. One in a hundred cached
// requests rebuilds the page instead of serving it, so each is asked for a
// few times and the first the cache answers is the one examined.
$served = null;
$tried = array();
foreach (array('/pricing', '/', '/blog', '/events', '/login') as $candidate) {
	$first = $get($candidate);
	if ($first['status'] !== 200) {
		$tried[] = $candidate . ' answered ' . $first['status'];
		continue;
	}
	for ($i = 0; $i < 4; $i++) {
		$again = $get($candidate);
		if (!empty($again['log']['hit'])) {
			$served = array('path' => $candidate, 'log' => $again['log']);
			break 2;
		}
	}
	$tried[] = $candidate . ' was never served from the cache';
}

if ($served === null) {
	harness_skip('this site has no public page the cache holds (' . implode('; ', $tried) . ')');
} else {
	$entry = $served['log'];
	check(!empty($entry['session']), $served['path'] . ': the cached request ran with a real session');
	check(($entry['statements'] ?? -1) === 0, $served['path'] . ': the cached request ran no statement',
		'statements: ' . json_encode($entry['statements'] ?? null));
	check(($entry['connected'] ?? true) === false, $served['path'] . ': the cached request opened no database link');
}

harness_finish();
