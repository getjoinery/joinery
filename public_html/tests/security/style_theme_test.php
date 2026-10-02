<?php
/** @joinery-test
 * name: style_theme
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * What makes a theme a style theme (specs/style_themes.md WP1).
 *
 * A style theme installs with no unsigned warning, so the whole claim rests
 * on one method: ThemeHelper::styleThemeRefusal() says null for exactly a
 * package that holds nothing the platform can execute or render as a
 * document, and a reason naming the file for everything else. Each fixture
 * below is a way a package could reach past that which a loose screen would
 * wave through: PHP, script, a document, a dotfile, a stylesheet that
 * reports to another origin, a manifest that names no stylesheet.
 *
 * Fixture trees are built under the scratch directory and removed.
 *
 * Run: php tests/run.php --only=tests/security/style_theme_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$work = harness_scratch_dir('style_theme') . '/run-' . getmypid();
$rmtree = function ($p) use (&$rmtree) {
	foreach (glob(rtrim($p, '/') . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
		if (basename($f) === '.' || basename($f) === '..') continue;
		is_dir($f) && !is_link($f) ? $rmtree($f) : @unlink($f);
	}
	@rmdir($p);
};
$rmtree($work);
@mkdir($work, 0770, true);
harness_defer(function () use ($work, $rmtree) { $rmtree($work); });

$manifest = json_encode(array(
	'name' => 'look_fixture', 'display_name' => 'Look fixture', 'version' => '1.0.0',
	'styles' => array('assets/css/look.css'),
	'brand_tokens' => array('jy_color_primary' => '#0f6cbd'),
));
$good = array(
	'theme.json'               => $manifest,
	'assets/css/look.css'      => ".jy-ui { --jy-color-primary: #0f6cbd; }\n.logo { background: url(../img/logo.svg) }\n",
	'assets/css/print.css'     => "@import \"look.css\";\n@page { margin: 1cm }\n",
	'assets/img/logo.svg'      => "<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>\n",
	'assets/img/hero.png'      => "\x89PNG\r\n\x1a\n",
	'assets/fonts/Inter.woff2' => "wOF2",
	'README.md'                => "# A look\n",
	'RELEASE_MANIFEST'         => "sha256 ...\n",
	'RELEASE_MANIFEST.sig'     => "sig\n",
);

/** A theme tree from a files map. Returns its directory. */
$tree = function (string $label, array $files) use ($work): string {
	$dir = $work . '/' . $label;
	foreach ($files as $rel => $bytes) {
		@mkdir(dirname($dir . '/' . $rel), 0770, true);
		file_put_contents($dir . '/' . $rel, $bytes);
	}
	return $dir;
};

section('A package of stylesheets, fonts and images is a style theme');
$why = ThemeHelper::styleThemeRefusal($tree('good', $good));
check($why === null, 'theme.json, CSS, fonts, PNG, SVG, a README and the signing record: no refusal', (string)$why);
check(ThemeHelper::styleThemeRefusal($work . '/nowhere') !== null, 'a missing directory is not a style theme');

section('One file outside that set makes it a page theme, and the reason names the file');
$cases = array(
	'includes/PublicPage.php' => array("<?php class PublicPage {}\n", 'runs on the server'),
	'views/x.phtml'           => array("<?php echo 1; ?>\n", 'runs on the server'),
	'assets/js/app.js'        => array("alert(1)\n", 'runs in the browser'),
	'assets/js/app.mjs'       => array("export {}\n", 'runs in the browser'),
	'index.html'              => array("<html></html>\n", 'a document the browser would render'),
	'page.htm'                => array("<html></html>\n", 'a document the browser would render'),
	'sitemap.xml'             => array("<xml/>\n", 'a document the browser would render'),
	'.htaccess'               => array("Options +Indexes\n", 'starts with a dot'),
	'.well-known'             => array("a{}\n", 'starts with a dot'),   // written as .well-known/x.css; the directory is what is named
	'assets/data.json'        => array("{}\n", 'not a stylesheet, font or image'),
	'tool.sh'                 => array("#!/bin/sh\n", 'not a stylesheet, font or image'),
);
foreach ($cases as $rel => $pair) {
	list($bytes, $expected) = $pair;
	$label = 'c' . md5($rel);
	$path = ($rel === '.well-known') ? '.well-known/x.css' : $rel;
	$why = ThemeHelper::styleThemeRefusal($tree($label, array_replace($good, array($path => $bytes))));
	check($why !== null && strpos($why, $rel) === 0 && strpos($why, $expected) !== false,
		"$rel → page theme: $expected", (string)$why);
}

section('A stylesheet that names another origin is refused; relative, same-origin and data: pass');
$css_cases = array(
	'a { background: url(../img/x.png) }'                       => null,
	'a { background: url("/theme/look/assets/img/x.png") }'     => null,
	"a { background: url('fonts/a.woff2') format('woff2') }"    => null,
	'a { background: url(data:image/png;base64,iVBORw0KGgo=) }' => null,
	'@import "print.css";'                                       => null,
	'@import url(print.css);'                                    => null,
	'/* url(https://commented.example/px.gif) */ a { }'          => null,
	'a { background: url(https://evil.example/px.gif) }'        => 'url() names another origin',
	'a { background: url("http://evil.example/px.gif") }'       => 'url() names another origin',
	'a { background: url(//evil.example/px.gif) }'              => 'url() names another origin',
	'@import "https://evil.example/a.css";'                      => '@import names another origin',
	"@import 'https://evil.example/a.css' screen;"               => '@import names another origin',
	'@import url(https://evil.example/a.css);'                   => 'url() names another origin',
	'@font-face { src: url(HTTPS://evil.example/f.woff2) }'      => 'url() names another origin',
);
foreach ($css_cases as $css => $expected) {
	$why = ThemeHelper::stylesheetRefusal($css);
	if ($expected === null) {
		check($why === null, 'passes: ' . $css, (string)$why);
	} else {
		check($why !== null && strpos($why, $expected) === 0, 'refused: ' . $css, (string)$why);
	}
}
$why = ThemeHelper::styleThemeRefusal($tree('beacon', array_replace($good, array(
	'assets/css/look.css' => "body { background: url(https://evil.example/px.gif) }\n"))));
check($why !== null && strpos($why, 'assets/css/look.css: url() names another origin') === 0,
	'and in a tree the refusal names the stylesheet', (string)$why);

section('The manifest must list the stylesheets to emit');
$no_styles = $good;
$no_styles['theme.json'] = json_encode(array('name' => 'look_fixture', 'version' => '1.0.0'));
$why = ThemeHelper::styleThemeRefusal($tree('nostyles', $no_styles));
check($why !== null && strpos($why, 'names no styles') !== false, 'no styles list: a page theme, with the sentence', (string)$why);

$empty_styles = $good;
$empty_styles['theme.json'] = json_encode(array('name' => 'look_fixture', 'version' => '1.0.0', 'styles' => array()));
$why = ThemeHelper::styleThemeRefusal($tree('emptystyles', $empty_styles));
check($why !== null && strpos($why, 'names no styles') !== false, 'an empty styles list is the same', (string)$why);

$missing = $good;
$missing['theme.json'] = json_encode(array('name' => 'look_fixture', 'version' => '1.0.0', 'styles' => array('assets/css/nope.css')));
$why = ThemeHelper::styleThemeRefusal($tree('missingstyle', $missing));
check($why !== null && strpos($why, 'not in the theme') !== false, 'styles naming a file that is not there', (string)$why);

$escape = $good;
$escape['theme.json'] = json_encode(array('name' => 'look_fixture', 'version' => '1.0.0', 'styles' => array('../../index.php')));
$why = ThemeHelper::styleThemeRefusal($tree('escape', $escape));
check($why !== null && strpos($why, 'outside the theme') !== false, 'styles naming a path outside the theme', (string)$why);

$not_css = $good;
$not_css['theme.json'] = json_encode(array('name' => 'look_fixture', 'version' => '1.0.0', 'styles' => array('assets/img/logo.svg')));
$why = ThemeHelper::styleThemeRefusal($tree('notcss', $not_css));
check($why !== null && strpos($why, 'not a stylesheet') !== false, 'styles naming something that is not a stylesheet', (string)$why);

$bad_json = $good;
$bad_json['theme.json'] = "{ not json";
$why = ThemeHelper::styleThemeRefusal($tree('badjson', $bad_json));
check($why !== null && strpos($why, 'not valid JSON') !== false, 'an unreadable manifest', (string)$why);

section('Every theme shipped in this tree is a page theme');
foreach (glob(PathHelper::getAbsolutePath('theme') . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
	$why = ThemeHelper::styleThemeRefusal($dir);
	check($why !== null, basename($dir) . ' registers as page', (string)$why);
}

harness_finish();
?>
