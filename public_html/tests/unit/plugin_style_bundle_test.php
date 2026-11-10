<?php
/** @joinery-test
 * name: plugin_style_bundle
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The one stylesheet built from every active plugin's declared styles
 * (specs/request_path_speedups.md WP8), over fixture sheets in a scratch
 * directory:
 *
 *  - sheets are joined in declared order, each part headed by a comment
 *    naming its file;
 *  - a sheet whose meaning joining would change is left out and stays
 *    linked on its own: a relative url(), an @import, an @charset, an
 *    unclosed comment, a file that is not .css, a file outside its plugin's
 *    folder; an absolute or data: url() is joined;
 *  - the bundle's name changes when a sheet's modification time or size
 *    does, and when the set of sheets does;
 *  - a bundle already built is read from its record, not built again;
 *  - with nowhere to build, nothing is bundled.
 *
 * Run: php tests/run.php --only=tests/unit/plugin_style_bundle_test.php
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$base = harness_scratch_dir('plugin_style_bundle');
$plugin = $base . '/plugins/zzfixture';
$other = $base . '/plugins/zzother';
$built = $base . '/built';
@mkdir($plugin . '/assets', 0700, true);
@mkdir($other, 0700, true);
harness_defer(function () use ($base) {
	PluginStyleBundle::useDirectory(null);
	$rm = function ($p) use (&$rm) {
		foreach (glob(rtrim($p, '/') . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
			if (in_array(basename($f), array('.', '..'), true)) { continue; }
			is_dir($f) && !is_link($f) ? $rm($f) : @unlink($f);
		}
		@chmod($p, 0700);
		@rmdir($p);
	};
	$rm($base);
});

$old = time() - 3600;
$sheet = function (string $rel, string $css, ?string $root = null) use ($plugin, $old) {
	$full = $plugin . '/' . $rel;
	file_put_contents($full, $css);
	touch($full, $old);
	return array(
		'path'  => 'plugins/zzfixture/' . $rel,
		'full'  => $full,
		'root'  => $root ?? $plugin . '/',
		'mtime' => (int)filemtime($full),
		'size'  => (int)filesize($full),
	);
};

$plain    = $sheet('assets/plain.css', ".zz-plain { color: red; }\n");
$relative = $sheet('assets/relative.css', ".zz-rel { background: url('img/x.png'); }\n");
$import   = $sheet('assets/import.css', "@import url(/assets/css/base.css);\n.zz-imp { color: blue; }\n");
$charset  = $sheet('assets/charset.css', "@charset \"utf-8\";\n.zz-cs { color: blue; }\n");
$absolute = $sheet('assets/absolute.css', ".zz-abs { background: url(/assets/x.png), url(\"data:image/png;base64,AAAA\"), url( https://example.com/y.png ), url(#frag); }\n");
$unclosed = $sheet('assets/unclosed.css', ".zz-open { color: red; } /* never closed\n");
$not_css  = $sheet('assets/notes.txt', ".zz-txt { color: red; }\n");
$outside  = $sheet('assets/outside.css', ".zz-out { color: red; }\n", $other . '/');
$sheets = array($plain, $relative, $import, $charset, $absolute, $unclosed, $not_css, $outside);

PluginStyleBundle::useDirectory($built);

section('What is joined and what stays on its own');
$plan = PluginStyleBundle::plan($sheets);
check(preg_match('/^[a-f0-9]{32}$/', $plan['hash']) === 1, 'the bundle is named by a hash');
check($plan['bundled'] === array($plain['path'], $absolute['path']),
	'a plain sheet and one with only absolute, data: and fragment url()s are joined; the rest are not',
	json_encode($plan['bundled']));
$css = (string)@file_get_contents($built . '/' . $plan['hash'] . '.css');
$first = strpos($css, '/* plugins/zzfixture/assets/plain.css */');
$second = strpos($css, '/* plugins/zzfixture/assets/absolute.css */');
check($first === 0 && $second !== false && $second > $first, 'each part is headed by a comment naming its file, in declared order');
check(strpos($css, '.zz-plain { color: red; }') !== false && strpos($css, '.zz-abs') !== false, 'the bundle holds the joined sheets\' rules');
foreach (array('.zz-rel', '.zz-imp', '.zz-cs', '.zz-open', '.zz-txt', '.zz-out') as $left_out) {
	check(strpos($css, $left_out) === false, 'left out of the bundle: ' . $left_out);
}
$record = json_decode((string)@file_get_contents($built . '/' . $plan['hash'] . '.json'), true);
check(is_array($record) && ($record['bundled'] ?? null) === $plan['bundled'], 'a record beside the bundle names what it holds');

section('A built bundle is read, not built again');
file_put_contents($built . '/' . $plan['hash'] . '.css', "/* marked */\n");
$again = PluginStyleBundle::plan($sheets);
check($again === $plan, 'the same sheets give the same plan');
check(file_get_contents($built . '/' . $plan['hash'] . '.css') === "/* marked */\n", 'and the bundle file is left as it was');

section('The name follows the sheets');
touch($plain['full'], $old + 60);
clearstatcache();
$touched = $plain;
$touched['mtime'] = (int)filemtime($plain['full']);
$changed = PluginStyleBundle::plan(array($touched, $absolute));
$two = PluginStyleBundle::plan(array($plain, $absolute));
check($changed['hash'] !== $two['hash'], 'a sheet with a new modification time gives a new name');
$one = PluginStyleBundle::plan(array($plain));
check($one['hash'] !== $two['hash'] && $one['bundled'] === array($plain['path']), 'a different set of sheets gives a new name');
$none = PluginStyleBundle::plan(array($relative));
check($none['bundled'] === array() && !is_file($built . '/' . $none['hash'] . '.css'),
	'when no sheet can be joined there is no bundle file, and the record says so');

section('Nowhere to build');
PluginStyleBundle::useDirectory($base . '/plugins/zzfixture/assets/plain.css/under_a_file');
$nowhere = PluginStyleBundle::plan(array($plain, $absolute));
check($nowhere['bundled'] === array(), 'with no directory to write, nothing is bundled and every sheet is linked on its own');

harness_finish();
