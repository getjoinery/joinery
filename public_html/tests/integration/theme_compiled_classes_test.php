<?php
/** @joinery-test
 * name: theme_compiled_classes
 * tier: safe
 * env: any
 * needs: []
 * covers: [theme/**]
 */
/**
 * A theme that ships compiled utility CSS (Tailwind's output, which only
 * holds the classes its build found) defines every utility class its views
 * use (specs/fleet_security_patching.md WP10). On 2026-10-21 the
 * xandyliberato.com home page video collapsed because its compiled CSS lacked
 * h-80; nothing had checked.
 *
 * For each theme whose CSS carries Tailwind's own variables (--tw-), every
 * class attribute in its PHP and HTML files is read, PHP inside an attribute
 * dropped, and each class shaped like a utility (a known utility root, with
 * any variant prefixes) must appear as a selector in one of the theme's own
 * CSS files, escaped as Tailwind escapes it and ending there. A class that is not a utility
 * (a script hook, a theme's own name) is the theme's business and not read.
 *
 * Run: php tests/integration/theme_compiled_classes_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** A class shaped like a utility: variant prefixes, then a known root. */
function tcc_is_utility(string $class): bool {
	$base = preg_replace('/^((sm|md|lg|xl|2xl|hover|focus|focus-within|focus-visible|active|visited|disabled|group-hover|peer-hover|first|last|odd|even|dark|motion-safe|motion-reduce|print):)+/', '', $class);
	$base = ltrim($base, '!');
	$base = preg_replace('/^-/', '', $base);
	return (bool)preg_match('/^(container|flex|grid|block|inline|inline-block|inline-flex|inline-grid|hidden|table|contents|static|fixed|absolute|relative|sticky|visible|invisible|truncate|italic|not-italic|underline|no-underline|uppercase|lowercase|capitalize|antialiased|sr-only|grow|shrink|transform|transition|isolate'
		. '|border|shadow|rounded|ring|outline|divide-x|divide-y|flow-root|prose|resize'
		. '|(flex|origin|grid-flow|prose|m|mx|my|mt|mr|mb|ml|p|px|py|pt|pr|pb|pl|w|h|min-w|min-h|max-w|max-h|size|text|bg|border|border-[trblxy]|rounded|rounded-[trbl]{1,2}|gap|gap-x|gap-y|space-x|space-y|col-span|row-span|col-start|col-end|grid-cols|grid-rows|items|justify|content|self|place-items|place-content|order|z|top|right|bottom|left|inset|inset-x|inset-y|leading|tracking|font|shadow|opacity|ring|ring-offset|outline|transition|duration|ease|delay|translate-x|translate-y|scale|scale-x|scale-y|rotate|skew-x|skew-y|cursor|select|overflow|overflow-x|overflow-y|object|aspect|line-clamp|whitespace|break|list|align|float|clear|box|divide|divide-x|divide-y|fill|stroke|decoration|indent|columns|backdrop|blur|brightness|pointer-events|resize|appearance|placeholder|from|via|to|basis|grow|shrink|auto-cols|auto-rows|underline-offset)-.+)$/', $base);
}

/** The selector Tailwind writes for a class: special characters escaped. */
function tcc_selector(string $class): string {
	return '.' . preg_replace('/([:\/.\[\]%#!,@()&+*=])/', '\\\\$1', $class);
}

/** Whether the CSS defines the class: its selector, ending there (.bg-indigo-50 is not .bg-indigo-500). */
function tcc_defined(string $css, string $class): bool {
	return (bool)preg_match('/' . preg_quote(tcc_selector($class), '/') . '(?![A-Za-z0-9_\\-\\\\])/', $css);
}

$checked = 0;
foreach (glob(PathHelper::getIncludePath('theme') . '/*', GLOB_ONLYDIR) as $dir) {
	$css = '';
	$compiled = false;
	foreach (glob($dir . '/assets/css/*.css') ?: array() as $f) {
		$c = (string)file_get_contents($f);
		$css .= "\n" . $c;
		if (strpos($c, '--tw-') !== false) { $compiled = true; }
	}
	if (!$compiled) { continue; }
	$checked++;
	$theme = basename($dir);
	$used = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if (!preg_match('/\.(php|html)$/', $f->getFilename())) { continue; }
		$src = (string)file_get_contents($f->getPathname());
		preg_match_all('/\bclass\s*=\s*"([^"]*)"|\bclass\s*=\s*\'([^\']*)\'/', $src, $m, PREG_SET_ORDER);
		foreach ($m as $match) {
			$attr = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
			// PHP inside the attribute decides at run time; only the literal classes are read.
			$attr = preg_replace('/<\?(php|=).*?\?>/s', ' ', $attr);
			foreach (preg_split('/\s+/', $attr) as $class) {
				if ($class === '' || !tcc_is_utility($class)) { continue; }
				$used[$class] = $used[$class] ?? substr($f->getPathname(), strlen($dir) + 1);
			}
		}
	}
	$missing = array();
	foreach ($used as $class => $file) {
		if (!tcc_defined($css, $class)) {
			$missing[] = $class . ' (' . $file . ')';
		}
	}
	check(!$missing, $theme . ': every utility class its views use (' . count($used) . ') is in its compiled CSS',
		$missing ? implode(', ', $missing) : '');
}
check($checked > 0, 'at least one theme ships compiled utility CSS, so the check read something');

harness_finish();
