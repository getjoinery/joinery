<?php
/** @joinery-test
 * name: script_modes
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Git records every file in the trees the host converger keeps at the mode
 * the converger gives it: a .sh at 755 and anything else at 644, under
 * public_html/, maintenance_scripts/ and vendor/ (_plugin_installers_start.sh,
 * the ownership walk). A file recorded otherwise reads as edited on every box
 * after each converge, and dev's follow_main.sh stopped pulling over 29
 * scripts committed at 644.
 *
 * Reads the last commit, so a file fixed in the working tree passes once it
 * is committed. Skips, saying so, where the site is not a git checkout.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('Every file under the converged trees is recorded at the converger\'s mode');

$root = PathHelper::getSiteRoot();
if (!is_dir($root . '/.git')) {
	check(true, 'skipped: this site is not a git checkout (nothing to read)');
	harness_finish();
	return;
}
$out = array();
$rc = 0;
exec('git -C ' . escapeshellarg($root) . ' ls-tree -r HEAD -- public_html maintenance_scripts vendor 2>&1', $out, $rc);
check($rc === 0 && count($out) > 0, 'git lists the last commit\'s files', implode(' | ', array_slice($out, 0, 3)));
$wrong = array();
foreach ($out as $line) {
	// "<mode> <type> <hash>\t<path>"
	if (!preg_match('/^(\d{6}) blob [0-9a-f]+\t(.+)$/', $line, $m) || $m[1] === '120000') {
		continue;   // a link or a submodule: the walk leaves those alone
	}
	$want = substr($m[2], -3) === '.sh' ? '100755' : '100644';
	if ($m[1] !== $want) {
		$wrong[] = $m[2] . ' (' . $m[1] . ', not ' . $want . ')';
	}
}
check(count($wrong) === 0, 'every .sh is 755 and every other file 644', implode(', ', array_slice($wrong, 0, 10))
	. (count($wrong) > 10 ? ' and ' . (count($wrong) - 10) . ' more' : ''));

harness_finish();
?>
