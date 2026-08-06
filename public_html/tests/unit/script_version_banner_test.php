<?php
/** @joinery-test
 * name: script_version_banner
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A maintenance script tells you the version it actually is.
 *
 * Every one of these scripts carries a #VERSION log at the top, and several
 * print a version banner in their help. When the banner is its own copy of the
 * number, it stops being updated the first time someone bumps the header --
 * install.sh said 2.7 in --help while the file was 2.41, and backup_database.sh
 * said 3.0 while the file was 3.4.
 *
 * That is worse than printing nothing. An operator diagnosing a failed install
 * on a machine we cannot see reads --help, concludes they are on a release from
 * long ago, and starts looking for a fix that is already in the file in front of
 * them. So no banner is allowed to state a literal: it has to be derived from
 * the header, and the derivation has to be the one that works.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/script_version_banner_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$site_root = dirname(PathHelper::getRootDir());

// Every shell script an operator can run by hand, wherever it lives.
$scripts = array();
foreach (array('install_tools', 'sysadmin_tools', 'dev_tools') as $dir) {
    $found = glob($site_root . '/maintenance_scripts/' . $dir . '/*.sh');
    if (is_array($found)) {
        $scripts = array_merge($scripts, $found);
    }
}
sort($scripts);

check(count($scripts) > 5,
    'the maintenance script tree is where the test expects it',
    count($scripts) . ' scripts');


section('No banner carries its own copy of the number');

// The shape being banned: a printed string ending in v1.23. Matching on the
// printed text rather than on any one script keeps a new tool from
// reintroducing this without anyone noticing.
$literal_banners = array();
foreach ($scripts as $path) {
    $src = file_get_contents($path);
    if ($src === false) {
        continue;
    }
    foreach (explode("\n", $src) as $n => $line) {
        if (strpos(ltrim($line), '#') === 0) {
            continue;   // the version log itself, which is the source of truth
        }
        if (preg_match('/echo\s+"[^"]*\sv\d+\.\d+/', $line)) {
            $literal_banners[] = basename($path) . ':' . ($n + 1) . ' ' . trim($line);
        }
    }
}
check(count($literal_banners) === 0,
    'no maintenance script prints a hardcoded version',
    implode(' | ', $literal_banners));


section('The scripts that do print one derive it correctly');

// A derived banner can still be wrong: the derivation is a sed over the running
// file, so a header written in a different shape yields an empty string and the
// fallback prints the word unknown. Reproduce the sed here and compare against
// the header read independently.
$printing = 0;
foreach ($scripts as $path) {
    $src  = file_get_contents($path);
    $name = basename($path);

    if (!preg_match('/echo\s+"[^"]*\sv\$\{([A-Z_]+)\}"/', $src, $banner)) {
        continue;   // no version banner at all, which is fine
    }
    $printing++;
    $var = $banner[1];

    // The assignment has to read the file it is in, not a path that could go
    // stale, and has to take the newest entry rather than whichever sorts first.
    check(preg_match('/^' . $var . '="\$\(sed .*BASH_SOURCE\[0\]\}".*head -1\)"$/m', $src) === 1,
        $name . ' derives its version from its own header, newest entry first');
    check(preg_match('/^\[ -n "\$' . $var . '" \] \|\| ' . $var . '="unknown"$/m', $src) === 1,
        $name . ' falls back to a word rather than printing v with nothing after it');

    // Run the same extraction the script runs, and read the header directly.
    // If these disagree the banner is derived and still wrong. Two header
    // conventions are in use across the tree (#VERSION 2.42 and # Version: 1.3.1);
    // which one a script uses is its business, agreeing with itself is not.
    preg_match('/^' . $var . '="\$\(sed -n \'([^\']+)\'/m', $src, $expr);
    $sed = isset($expr[1]) ? $expr[1] : '';
    $derived = '';
    if ($sed !== '') {
        $out = array();
        exec('sed -n ' . escapeshellarg($sed) . ' ' . escapeshellarg($path) . ' 2>/dev/null | head -1',
            $out);
        $derived = isset($out[0]) ? trim($out[0]) : '';
    }
    preg_match('/^# ?(?:VERSION|Version):? ([0-9][0-9.]*)/m', $src, $header);
    $stated = isset($header[1]) ? $header[1] : '';

    check($stated !== '' && $derived === $stated,
        $name . ' resolves to the version at the top of the file (' . $stated . ')',
        'derived ' . var_export($derived, true));
}

check($printing >= 5,
    'every script known to print a version is still doing so',
    $printing . ' found');

harness_finish();
