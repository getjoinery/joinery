<?php
/** @joinery-test
 * name: list_dependencies
 * tier: safe
 * env: any
 * needs: []
 * timeout: 60
 */

/**
 * utils/list_dependencies.php, the dependency resolver every root moment reads
 * (Docker build, container start, install.sh site, upgrade.php). Pinned:
 *
 *   - --apt emits one "primary|fallback" pair per line and nothing else
 *   - a PHP extension maps to its versioned package with the unversioned fallback
 *   - a declared system package (composer.json extra.joinery-system-packages,
 *     plugin requires.packages) rides the same list as "name|name"
 *   - libjpeg-turbo-progs is declared by core, so djpeg reaches every node
 *     (specs/image_decode_memory.md WP3)
 *   - --extensions lists extension names only; a system package is not one
 *   - a package name that is not a plain apt name is dropped, not emitted
 *
 * @version 1.2 - a name ending in a dash is dropped (reviewer1 B2)
 * @version 1.1 - an extension name that is not a plain apt name is dropped (specs/shell_command_audit.md U3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$resolver = PathHelper::getBasePath() . '/utils/list_dependencies.php';
$php = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

section('--apt is a list of primary|fallback pairs');
exec('php ' . escapeshellarg($resolver) . ' --apt 2>/dev/null', $apt, $rc);
check($rc === 0 && count($apt) > 0, 'the resolver runs with no site booted', 'rc ' . $rc . ', ' . count($apt) . ' lines');
$bad = array_filter($apt, function ($l) { return !preg_match('/^[a-z0-9][a-z0-9+.-]*\|[a-z0-9][a-z0-9+.-]*$/', $l); });
check(count($bad) === 0, 'every line is two plain apt names joined by |', implode(', ', $bad));
check(in_array($php . '-sqlite3|php-sqlite3', $apt, true), 'a PHP extension maps to the versioned package with the unversioned fallback');
check(in_array('libjpeg-turbo-progs|libjpeg-turbo-progs', $apt, true), 'libjpeg-turbo-progs is declared by core and rides the same list as name|name');

section('--extensions stays extensions');
exec('php ' . escapeshellarg($resolver) . ' --extensions 2>/dev/null', $exts, $rc2);
check($rc2 === 0 && in_array('sqlite3', $exts, true), 'sqlite3 is listed as an extension');
check(!in_array('libjpeg-turbo-progs', $exts, true), 'a system package is not listed as an extension');

section('the declaration sources are read');
$composer = json_decode((string)file_get_contents(PathHelper::getBasePath() . '/composer.json'), true);
check(in_array('libjpeg-turbo-progs', $composer['extra']['joinery-system-packages'] ?? array(), true),
	'composer.json extra.joinery-system-packages carries libjpeg-turbo-progs');
// A scratch tree with one plugin declaring a package, and one declaring junk.
$dir = harness_scratch_dir('list_dependencies');
@mkdir($dir . '/utils', 0777, true);
@mkdir($dir . '/plugins/goodplug', 0777, true);
@mkdir($dir . '/plugins/badplug', 0777, true);
copy($resolver, $dir . '/utils/list_dependencies.php');
file_put_contents($dir . '/composer.json', json_encode(array('require' => array('ext-gd' => '*'), 'extra' => array('joinery-system-packages' => array('Zip')))));
file_put_contents($dir . '/plugins/goodplug/plugin.json', json_encode(array('requires' => array('packages' => array('jq')))));
file_put_contents($dir . '/plugins/badplug/plugin.json', json_encode(array('requires' => array(
	'packages' => array('rm -rf /', 'curl;x', ''),
	// An extension name is half of an apt package name the installer runs as root;
	// one that is not a plain name is dropped here, not left for apt to read as an
	// option (specs/shell_command_audit.md U3).
	'extensions' => array('a|-oAPT::X=1', '-oDPkg::Pre-Invoke::=id', 'x y', 'apache2-', 'intl'),
))));
exec('php ' . escapeshellarg($dir . '/utils/list_dependencies.php') . ' --apt 2>/dev/null', $scratch, $rc3);
check($rc3 === 0 && in_array('jq|jq', $scratch, true), 'a plugin requires.packages entry is emitted', implode(', ', $scratch));
check(in_array('zip|zip', $scratch, true), 'a core entry is lowercased and emitted');
check(count(preg_grep('/rm|curl|;| /', $scratch)) === 0, 'a name that is not a plain apt name is dropped, never emitted', implode(', ', $scratch));
check(in_array($php . '-gd|php-gd', $scratch, true), 'extensions are unaffected');
check(in_array($php . '-intl|php-intl', $scratch, true), 'a plain extension name declared by a plugin is emitted');
check(count(preg_grep('/\||-o|\s/', array_map(function ($l) { return substr_count($l, '|') > 1 ? $l : preg_replace('/^[^|]*\|/', '', $l); }, $scratch))) === 0,
	'an extension name that is not a plain apt name is dropped, so no line can start apt on an option', implode(', ', $scratch));
check(count(preg_grep('/^-|\|-/', $scratch)) === 0, 'no emitted package name begins with a dash');
check(count(preg_grep('/-$|-\|/', $scratch)) === 0, 'and none ends in one: NAME- is a remove to apt-get (reviewer1 B2)', implode(', ', $scratch));

harness_finish();
