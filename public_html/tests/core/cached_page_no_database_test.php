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
 * It also checks the security headers survive the cache: the page the cache
 * answers carries the same Content-Security-Policy, X-Frame-Options,
 * nosniff and the rest as the page it was built from
 * (specs/strict_script_policy.md WP0), and an entry saved before headers
 * were recorded is built afresh rather than served bare.
 *
 * Run: php tests/run.php --only=tests/core/cached_page_no_database_test.php
 *
 * @version 1.5 - the cache carries no nonce (WP10): the nonce swap checks are replaced by one that none is saved or served
 * @version 1.4 - the URL in the cached page's comment cannot close it
 * @version 1.3 - a page already holding the nonce placeholder is never cached
 * @version 1.2 - a page built with a CspNonce is saved with a placeholder and served with a fresh one
 * @version 1.1 - a cached page carries the security headers of the page it was built from
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

// ── An entry saved without its headers ──────────────────────────────────────
section('An entry with no recorded headers is built, never served bare');

$legacy_dir = harness_scratch_dir('cached_page_legacy');
StaticPageCache::setCacheDirForTests($legacy_dir);
StaticPageCache::createCache('/legacy-probe', array(), '<html><head></head><body>x</body></html>',
	array('headers' => array('X-Frame-Options: SAMEORIGIN'), 'https_redirect' => false));
$meta = StaticPageCache::getCacheMetadata('/legacy-probe', array());
check(is_array($meta) && $meta['headers'] === array('X-Frame-Options: SAMEORIGIN'),
	'createCache() records the transport headers it is handed in the index entry');
$index_file = rtrim($legacy_dir, '/') . '/index.json';
$index = json_decode((string)file_get_contents($index_file), true);
foreach ($index as $hash => $entry) {
	if (is_array($entry) && ($entry['url'] ?? '') === '/legacy-probe') {
		unset($index[$hash]['headers']);
	}
}
file_put_contents($index_file, json_encode($index));
StaticPageCache::setCacheDirForTests($legacy_dir); // forget the index read above
$file = StaticPageCache::checkCache('/legacy-probe', array());
check(is_string($file) && $file !== 'nostatic', 'the old-style entry is still found by checkCache()');
ob_start();
$served_legacy = is_string($file) ? StaticPageCache::serve($file, '/legacy-probe', array()) : true;
$printed = ob_get_clean();
check($served_legacy === false && $printed === '', 'serve() refuses it and prints nothing');
check(StaticPageCache::checkCache('/legacy-probe', array()) === false, 'and drops it, so the build that follows saves it again');

section('A cached page carries no nonce, saved or served');
// The strict script policy names no nonce (specs/strict_script_policy.md
// WP10), so nothing writes one: the page as saved and as served has none, and
// neither do the headers recorded with it.
$html = '<html><head></head><body><script src="/assets/js/x.js"></script></body></html>';
StaticPageCache::createCache('/cache-csp-probe', array(), $html, array(
	'headers' => array("Content-Security-Policy-Report-Only: script-src 'self'"),
	'https_redirect' => false,
));
$saved_meta = StaticPageCache::getCacheMetadata('/cache-csp-probe', array());
$saved_file = StaticPageCache::checkCache('/cache-csp-probe', array());
$saved_body = is_string($saved_file) ? (string)file_get_contents($saved_file) : '';
check($saved_body !== '' && stripos($saved_body, 'nonce') === false
	&& stripos(implode("\n", $saved_meta['headers'] ?? array()), 'nonce') === false,
	'the saved page and its recorded headers name no nonce');
ob_start();
$served = is_string($saved_file) && StaticPageCache::serve($saved_file, '/cache-csp-probe', array());
$printed = (string)ob_get_clean();
check($served && stripos($printed, 'nonce') === false, 'and the page goes out as it was saved, with none');
check(!class_exists('CspNonce'), 'the nonce class is gone');
$evil_path = '/x--><script>steal()</script><!--';
StaticPageCache::createCache($evil_path, array(), '<html><head></head><body>ok</body></html>',
	array('headers' => array(), 'https_redirect' => false));
$evil_file = StaticPageCache::checkCache($evil_path, array());
$evil_body = is_string($evil_file) ? (string)file_get_contents($evil_file) : '';
check($evil_body !== '' && strpos($evil_body, '<script') === false && substr_count($evil_body, '-->') === 1,
	'a path holding --> cannot close the cached page\'s comment');
StaticPageCache::setCacheDirForTests(null);
foreach (glob(rtrim($legacy_dir, '/') . '/*') ?: array() as $f) { @unlink($f); }
@rmdir($legacy_dir);

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
	$raw = (string)curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$head = substr($raw, 0, (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE));
	$security = array();
	foreach (preg_split('/\r?\n/', $head) as $line) {
		$name = strtolower(trim((string)strtok($line, ':')));
		if (in_array($name, StaticPageCache::TRANSPORT_HEADERS, true)) {
			$security[] = trim($line);
		}
	}
	sort($security);
	$lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
	$last = $lines ? json_decode((string)end($lines), true) : null;
	return array('status' => $status, 'log' => is_array($last) ? $last : array(), 'security' => $security);
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
			$served = array('path' => $candidate, 'log' => $again['log'],
				'built' => $first['security'], 'cached' => $again['security']);
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

	section('A cached page carries the security headers of the page it was built from');
	check($served['built'] !== array(), $served['path'] . ': the built page sent security headers to compare against',
		'none of ' . implode(', ', StaticPageCache::TRANSPORT_HEADERS));
	check($served['cached'] === $served['built'], $served['path'] . ': the cached page sends exactly the same ones',
		'built: ' . json_encode($served['built']) . ' cached: ' . json_encode($served['cached']));
}

harness_finish();
