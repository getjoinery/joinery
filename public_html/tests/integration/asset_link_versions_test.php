<?php
/** @joinery-test
 * name: asset_link_versions
 * tier: safe
 * env: any
 * needs: []
 * covers: [theme/**, includes/PublicPage*.php, views/**, adm/**, plugins/**]
 */
/**
 * Every link to one of our own stylesheets or scripts carries a version
 * (?v=, the file's time), so a changed file reaches visitors at once. A site
 * behind Cloudflare is served a cached copy of an unversioned URL for up to 12
 * hours: on 2026-10-22 galactictribune.net kept serving the stylesheet from
 * before 0.8.520 for hours after the release had put the new one in place.
 *
 * Read: each PHP file in the code that renders pages. A link is an src or
 * href naming a local .css or .js file, written out (/assets/js/x.js) or
 * echoed from PathHelper::getThemeFilePath(). A vendor file under
 * /assets/vendor/ names its version in its folder and is not read.
 *
 * Run: php tests/integration/asset_link_versions_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// PublicPageFalcon renders the falcon theme, which this tree does not carry.
$skip = array('includes/PublicPageFalcon.php');

$root = rtrim(PathHelper::getIncludePath(''), '/') . '/';
$files = array();
foreach (array('includes', 'views', 'adm', 'theme', 'plugins') as $top) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $top, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$rel = substr($f->getPathname(), strlen($root));
		if (substr($rel, -4) !== '.php' || preg_match('#(^|/)(tests|vendor|node_modules)/#', $rel) || in_array($rel, $skip, true)) {
			continue;
		}
		$files[] = $rel;
	}
}

$bare = array();
foreach ($files as $rel) {
	foreach (file($root . $rel) as $n => $line) {
		$written = preg_match('#(src|href)=\\\\?"/(?!/|assets/vendor/)[^"?<]*\.(css|js)\\\\?"#', $line);
		$echoed = preg_match('#(src|href)="<\?php echo PathHelper::getThemeFilePath\(\'[^\']+\.(css|js)\'#', $line)
			&& strpos($line, '?v=') === false;
		if ($written || $echoed) {
			$bare[] = $rel . ':' . ($n + 1) . ' ' . trim($line);
		}
	}
}
check(count($files) > 500, 'read the page code (' . count($files) . ' files)');
check(!$bare, 'every link to a local stylesheet or script carries a version', implode("\n", $bare));

harness_finish();
