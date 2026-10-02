<?php
/** @joinery-test
 * name: theme_tail
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The theme tail: one emit point for the applied look (specs/style_themes.md WP3).
 *
 * Every page class writes its own stylesheet link and calls
 * render_theme_tail() right after it, so the head of every page reads, in
 * order: the kit, the plugin stylesheets, the brand tokens, the page theme's
 * stylesheet, the look's stylesheets, and nothing after them. This renders
 * the head of each page class with a fixture look applied and pins that
 * order, then renders again with no look and pins that nothing of the look
 * remains. It also pins that no page class emits the retired Custom CSS
 * setting.
 *
 * A fixture style theme is written under theme/ for the run and removed,
 * and its row is created and deleted; theme_look is restored. The core page
 * classes render in this process; each theme's own PublicPage class
 * declares the same class name, so those render one per child process.
 *
 * Run: php tests/run.php --only=tests/core/theme_tail_test.php
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

const TTT_FIXTURE = 'zzlookfixture';
$theme_root = PathHelper::getAbsolutePath('theme');
$fixture_dir = $theme_root . '/' . TTT_FIXTURE;
$rmtree = function ($p) use (&$rmtree) {
	foreach (glob(rtrim($p, '/') . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
		if (basename($f) === '.' || basename($f) === '..') continue;
		is_dir($f) && !is_link($f) ? $rmtree($f) : @unlink($f);
	}
	@rmdir($p);
};

section('Fixture: a style theme on disk with a row');
$previous_look = (string)get_setting_raw('theme_look');
$rmtree($fixture_dir);
@mkdir($fixture_dir . '/assets/css', 0755, true);
file_put_contents($fixture_dir . '/theme.json', json_encode(array(
	'name' => TTT_FIXTURE, 'display_name' => 'Look fixture', 'version' => '1.0.0',
	'styles' => array('assets/css/look.css', 'assets/css/print.css'),
	'brand_tokens' => array('jy_color_primary' => '#123456'),
), JSON_PRETTY_PRINT));
file_put_contents($fixture_dir . '/assets/css/look.css', ".jy-ui { --jy-radius: 2px }\n");
file_put_contents($fixture_dir . '/assets/css/print.css', "@page { margin: 1cm }\n");
check(ThemeHelper::styleThemeRefusal($fixture_dir) === null, 'the fixture is a style theme');

$stale = Theme::get_by_theme_name(TTT_FIXTURE);
if ($stale) { $stale->permanent_delete(); }
$row = new Theme(null);
$row->set('thm_name', TTT_FIXTURE);
$row->set('thm_display_name', 'Look fixture');
$row->set('thm_version', '1.0.0');
$row->set('thm_kind', 'style');
$row->set('thm_status', 'installed');
$row->save();
check($row->key > 0, 'the fixture row exists');

harness_defer(function () use ($row, $fixture_dir, $rmtree, $previous_look) {
	Setting::put('theme_look', $previous_look);
	try { $row->permanent_delete(); } catch (Throwable $e) {}
	$rmtree($fixture_dir);
	ThemeHelper::forget(TTT_FIXTURE);
});

/**
 * The stylesheet links in a page head, in order, as [href, data-look|null].
 */
function ttt_links(string $html): array {
	$head = substr($html, 0, strpos($html, '</head>') ?: strlen($html));
	preg_match_all('~<link[^>]*rel="stylesheet"[^>]*>~i', $head, $m);
	$out = array();
	foreach ($m[0] as $tag) {
		preg_match('~href="([^"]*)"~', $tag, $h);
		preg_match('~data-look="([^"]*)"~', $tag, $l);
		$out[] = array($h[1] ?? '', $l[1] ?? null);
	}
	return $out;
}

/** Index of the first link whose href starts with $prefix, or -1. */
function ttt_index(array $links, string $prefix): int {
	foreach ($links as $i => $pair) {
		if (strpos($pair[0], $prefix) === 0) return $i;
	}
	return -1;
}

/**
 * Render a core page class's head in this process. The harness has already
 * printed, so the response headers a page sends raise "headers already
 * sent" warnings here; they are the CLI's, not the page's, and are muted
 * for the render only.
 */
function ttt_render_core(string $class): string {
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
	require_once(PathHelper::getIncludePath('includes/' . $class . '.php'));
	$level = error_reporting(E_ALL & ~E_WARNING);
	ob_start();
	try {
		$page = new $class();
		$page->public_header(array('title' => 'theme tail', 'header_only' => true));
	} catch (Throwable $e) {
		ob_end_clean();
		error_reporting($level);
		return 'RENDER FAILED: ' . $e->getMessage();
	}
	error_reporting($level);
	return (string)ob_get_clean();
}

/** Render a theme's own PublicPage head in a child process (each declares class PublicPage). */
function ttt_render_theme(string $theme): string {
	$script = '<?php $_SERVER["REQUEST_URI"] = "/"; $_SERVER["HTTP_HOST"] = "localhost";'
		. 'require_once(' . var_export(PathHelper::getAbsolutePath('includes/PathHelper.php'), true) . ');'
		. 'require_once(PathHelper::getIncludePath("theme/' . $theme . '/includes/PublicPage.php"));'
		. 'ob_start(); $p = new PublicPage(); $p->public_header(array("title" => "theme tail", "header_only" => true));'
		. 'echo ob_get_clean();';
	$file = harness_scratch_dir('theme_tail') . '/render_' . $theme . '_' . getmypid() . '.php';
	file_put_contents($file, $script);
	$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($file) . ' 2>/dev/null');
	@unlink($file);
	return $out;
}

$core_classes = array('PublicPage', 'PublicPageJoinerySystem', 'PublicPageTailwindHTML5');
// The Falcon base renders only with the falcon theme's vendor assets on disk.
if (is_dir($theme_root . '/falcon')) {
	$core_classes[] = 'PublicPageFalcon';
}
$theme_classes = array();
foreach (array('jeremytunnell-html5', 'getjoinery', 'phillyzouk-html5', 'scrolldaddy', 'galactictribune-html5') as $theme) {
	if (is_file($theme_root . '/' . $theme . '/includes/PublicPage.php')) {
		$theme_classes[] = $theme;
	}
}

section('With the look applied: kit, plugins, brand tokens, the theme stylesheet, then the look, then nothing');
Setting::put('theme_look', TTT_FIXTURE);
$renders = array();
foreach ($core_classes as $class) { $renders[$class] = ttt_render_core($class); }
foreach ($theme_classes as $theme) { $renders['theme ' . $theme] = ttt_render_theme($theme); }

foreach ($renders as $label => $html) {
	$links = ttt_links($html);
	$look_links = array_values(array_filter($links, function ($p) { return $p[1] === TTT_FIXTURE; }));
	check(strpos($html, '</head>') !== false, "$label: rendered a head", substr($html, 0, 120));
	check(count($look_links) === 2
		&& strpos($look_links[0][0], '/theme/' . TTT_FIXTURE . '/assets/css/look.css?v=') === 0
		&& strpos($look_links[1][0], '/theme/' . TTT_FIXTURE . '/assets/css/print.css?v=') === 0,
		"$label: both look stylesheets, in manifest order, cache-busted", json_encode($look_links));
	$first_look = ttt_index($links, '/theme/' . TTT_FIXTURE . '/');
	$last = count($links) - 1;
	check($first_look >= 0 && $links[$last][1] === TTT_FIXTURE, "$label: the look's stylesheets are the last links in the head",
		json_encode(array_map(function ($p) { return $p[0]; }, $links)));
	// Every non-look stylesheet precedes the look: the kit, the plugins and
	// the page theme's own.
	$after = array_filter(array_slice($links, $first_look), function ($p) { return $p[1] !== TTT_FIXTURE; });
	check($after === array(), "$label: nothing but the look follows the look", json_encode(array_values($after)));
	$kit = ttt_index($links, '/assets/css/joinery-styles.css');
	$plugin = ttt_index($links, '/plugins/');
	check($kit === -1 || $kit < $first_look, "$label: the kit precedes the look");
	check($plugin === -1 || $plugin < $first_look, "$label: plugin stylesheets precede the look");
	check(($kit === -1 || $plugin === -1) || $kit < $plugin, "$label: the kit precedes plugin stylesheets");
	check(preg_match('~<style id="jy-brand-tokens">:root \{[^}]*--jy-color-primary: #123456;~', $html) === 1,
		"$label: the look's brand token is emitted above the page theme's");
	check(substr_count($html, 'data-look="' . TTT_FIXTURE . '"') === 2, "$label: the tail is written once");
}

section('With no look: nothing of it remains');
Setting::put('theme_look', '');
foreach ($core_classes as $class) {
	$html = ttt_render_core($class);
	check(strpos($html, '/theme/' . TTT_FIXTURE . '/') === false && strpos($html, 'data-look=') === false,
		"$class: no look link", (string)substr_count($html, TTT_FIXTURE));
	check(strpos($html, '#123456') === false, "$class: no look brand token");
}
foreach ($theme_classes as $theme) {
	$html = ttt_render_theme($theme);
	check(strpos($html, '</head>') !== false && strpos($html, 'data-look=') === false, "theme $theme: no look link");
}

section('No page class emits the retired Custom CSS setting');
$sources = array_merge(
	glob(PathHelper::getAbsolutePath('includes') . '/PublicPage*.php') ?: array(),
	glob(PathHelper::getAbsolutePath('theme') . '/*/includes/PublicPage.php') ?: array()
);
foreach ($sources as $file) {
	$src = (string)file_get_contents($file);
	check(strpos($src, 'custom_css') === false, basename(dirname(dirname($file))) . '/' . basename($file) . ' does not read custom_css');
}
$declared = json_decode((string)file_get_contents(PathHelper::getAbsolutePath('settings.json')), true);
$names = array();
foreach (($declared['settings'] ?? array()) as $d) { $names[] = $d['name'] ?? ''; }
check(!in_array('custom_css', $names, true), 'settings.json does not declare custom_css');
check(in_array('theme_look', $names, true), 'settings.json declares theme_look');

harness_finish();
?>
