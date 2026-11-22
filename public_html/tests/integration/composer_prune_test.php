<?php
/** @joinery-test
 * name: composer_prune
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [includes/ComposerValidator.php, utils/composer_prune.php, maintenance_scripts/install_tools/_plugin_installers_start.sh]
 */
/**
 * ComposerValidator::pruneUnneededPackages() on a scratch vendor tree, never
 * the real one (specs/email_provider_sdk_removal.md WP8, B3).
 *
 * The keep set: the shipped lock alone. The site's vendor/ is core's; a plugin
 * ships its libraries in its own vendor/ (specs/plugin_own_vendor.md).
 * A prune stopped after any step before the swap leaves the live tree
 * loading and unchanged. Leftovers are cleaned up; a vendor/ an interrupted
 * swap left as vendor.prune-old is put back, by the prune and by the host
 * converger's tick. The swap: mv --exchange, a mv that refuses it, and a mv
 * that takes the flag but does a plain move (caught by the marker).
 *
 * Composer itself is stood in for (steps 3-4) by a seam that removes the
 * packages the lock does not hold and rewrites the autoloader, as composer
 * install does.
 *
 * Run: php tests/run.php --filter=composer_prune
 *
 * @version 1.3 - the keep set is the lock alone: the plugin cases go with the plugin composer tier
 *   (specs/plugin_own_vendor.md WP4)
 * @version 1.2 - the plugin list is read once per prune (reviewer2 B24)
 * @version 1.1 - a failed plugin query stops the prune; a stale marker; a fallback whose renames fail (reviewer2 B10, B19)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$ALL = array(
	'keep/a'   => array('keep/b' => '^1'),
	'keep/b'   => array('php' => '>=8'),
	'plug/p'   => array('plug/dep' => '^1', 'ext-json' => '*'),
	'plug/dep' => array(),
	'gone/x'   => array('gone/y' => '^1'),
	'gone/y'   => array(),
);
$LOCKED = array('keep/a', 'keep/b');

/** A scratch site holding vendor/ with $names installed, and a lock. */
function cp_make_site(array $names): string {
	global $ALL, $LOCKED;
	// Each case its own site: harness_scratch_dir() is one folder per run.
	static $n = 0;
	$site = harness_scratch_dir('composer_prune') . '/site' . (++$n);
	mkdir($site, 0755, true);
	cp_write_vendor($site . '/vendor', $names);
	$lock = array('packages' => array(), 'packages-dev' => array());
	foreach ($LOCKED as $n) { $lock['packages'][] = array('name' => $n, 'version' => '1.0.0'); }
	file_put_contents($site . '/composer.lock', json_encode($lock));
	return $site;
}

/** Write a vendor tree: one file per package, installed.json, an autoload.php requiring each. */
function cp_write_vendor(string $vendor, array $names): void {
	global $ALL;
	@mkdir($vendor . '/composer', 0755, true);
	$installed = array('packages' => array());
	$requires = "<?php\n";
	foreach ($names as $n) {
		@mkdir($vendor . '/' . $n, 0755, true);
		file_put_contents($vendor . '/' . $n . '/f.php', "<?php\n");
		$installed['packages'][] = array('name' => $n, 'version' => '1.0.0', 'require' => $ALL[$n]);
		$requires .= "require __DIR__ . '/" . $n . "/f.php';\n";
	}
	file_put_contents($vendor . '/composer/installed.json', json_encode($installed));
	file_put_contents($vendor . '/autoload.php', $requires);
}

/** What composer install does to the copy: drop what is unneeded, rewrite the autoloader. */
function cp_fake_composer(string $copy): bool {
	global $LOCKED;
	foreach (array('gone/x', 'gone/y', 'plug/p', 'plug/dep') as $n) {
		harness_remove_tree($copy . '/' . $n);
	}
	$keep = $LOCKED;
	cp_write_vendor($copy, $keep);
	return true;
}

function cp_validator(string $site, array $seams = array()): ComposerValidator {
	$v = new ComposerValidator($site . '/vendor/');
	$v->usePruneSeamsForTest($seams + array(
		'lock' => $site . '/composer.lock',
		'composer' => 'cp_fake_composer',
	));
	return $v;
}

/** A fingerprint of a tree: every path and its contents. */
function cp_fingerprint(string $dir): string {
	if (!is_dir($dir)) { return 'missing'; }
	$out = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$out[] = substr($f->getPathname(), strlen($dir)) . ':' . md5_file($f->getPathname());
	}
	sort($out);
	return md5(implode("\n", $out));
}

function cp_loads(string $vendor): bool {
	$rc = 0; $o = array();
	exec('php -r ' . escapeshellarg('require $argv[1];') . ' -- ' . escapeshellarg($vendor . '/autoload.php') . ' 2>&1', $o, $rc);
	return $rc === 0;
}

function cp_pruned_right(string $site): bool {
	$v = $site . '/vendor';
	return is_file($v . '/keep/a/f.php') && is_file($v . '/keep/b/f.php') && !file_exists($v . '/plug/p')
		&& !file_exists($v . '/gone/x') && !file_exists($v . '/.prune-new')
		&& !file_exists($site . '/vendor.prune-new') && !file_exists($site . '/vendor.prune-old')
		&& cp_loads($v);
}

$everything = array_keys($ALL);

section('The keep set');

$site = cp_make_site($everything);
$v = cp_validator($site);
check($v->unneededPackages() === array('gone/x', 'gone/y', 'plug/dep', 'plug/p'),
	'unneeded = installed minus the lock, a package a plugin once put in the shared vendor/ included',
	json_encode($v->unneededPackages()));

$clean = cp_make_site(array('keep/a', 'keep/b'));
$calls = 0;
$vc = cp_validator($clean, array('composer' => function () use (&$calls) { $calls++; return true; }));
check($vc->pruneUnneededPackages() === true && $calls === 0 && !file_exists($clean . '/vendor.prune-new'),
	'nothing unneeded: returns at once, no copy, no composer');

section('A prune stopped before the swap leaves vendor/ as it was');

foreach (array(1, 2, 3, 4, 5) as $step) {
	$site = cp_make_site($everything);
	$before = cp_fingerprint($site . '/vendor');
	cp_validator($site, array('stop_after' => $step))->pruneUnneededPackages();
	check(cp_fingerprint($site . '/vendor') === $before && cp_loads($site . '/vendor'),
		'stopped after step ' . $step . ': the live tree is unchanged and loads');
}

$site = cp_make_site($everything);
$before = cp_fingerprint($site . '/vendor');
$vf = cp_validator($site, array('composer' => function () { return false; }));
check($vf->pruneUnneededPackages() === false && cp_fingerprint($site . '/vendor') === $before
	&& !file_exists($site . '/vendor.prune-new'),
	'a failed install into the copy leaves vendor/ untouched and removes the copy');

$site = cp_make_site($everything);
$before = cp_fingerprint($site . '/vendor');
$vb = cp_validator($site, array('composer' => function (string $copy) {
	file_put_contents($copy . '/autoload.php', "<?php\nthrow new Exception('broken');\n");
	return true;
}));
check($vb->pruneUnneededPackages() === false && cp_fingerprint($site . '/vendor') === $before,
	'a copy that does not load is never swapped in');

section('Leftovers and recovery');

$site = cp_make_site($everything);
cp_validator($site, array('stop_after' => 2))->pruneUnneededPackages();
check(is_dir($site . '/vendor.prune-new'), 'a prune stopped after the copy leaves vendor.prune-new behind');
$vn = cp_validator($site);
check($vn->pruneUnneededPackages() === true && cp_pruned_right($site),
	'the next run removes the leftover and completes', implode(' | ', $vn->getPruneLog()));

$site = cp_make_site($everything);
rename($site . '/vendor', $site . '/vendor.prune-old');
$vo = cp_validator($site);
check($vo->pruneUnneededPackages() === true && cp_pruned_right($site),
	'vendor/ missing with vendor.prune-old present: the prune renames it back first, then prunes', implode(' | ', $vo->getPruneLog()));

// The converger's tick, run as the script holds it.
$script = file_get_contents(dirname(PathHelper::getBasePath()) . '/maintenance_scripts/install_tools/_plugin_installers_start.sh');
$start = strpos($script, '# --- A composer prune that stopped mid-swap');
$end = strpos($script, '# --- The agent runs wherever it is installed', (int)$start);
check($start !== false && $end !== false, 'the converger carries the interrupted-prune check');
if ($start !== false && $end !== false) {
	$block = substr($script, $start, $end - $start);
	$site = cp_make_site($everything);
	rename($site . '/vendor', $site . '/vendor.prune-old');
	$sh = $site . '/tick.sh';
	file_put_contents($sh, "MACHINE=0\nSITE_ROOT=" . escapeshellarg($site) . "\nSITENAME=t\n" . $block);
	$o = array(); $rc = 0;
	exec('bash ' . escapeshellarg($sh) . ' 2>&1', $o, $rc);
	check(is_dir($site . '/vendor') && !file_exists($site . '/vendor.prune-old') && cp_loads($site . '/vendor'),
		'the converger tick puts vendor.prune-old back', implode(' ', $o));
	exec('bash ' . escapeshellarg($sh) . ' 2>&1', $o2, $rc2);
	check($rc2 === 0 && is_dir($site . '/vendor'), 'a tick with vendor/ in place does nothing');
}

section('The swap');

$site = cp_make_site($everything);
$vm = cp_validator($site);
check($vm->pruneUnneededPackages() === true && cp_pruned_right($site),
	'a full prune with this machine\'s mv leaves exactly the keep set, loading', implode(' | ', $vm->getPruneLog()));
check(in_array('Swapped in the pruned copy (mv --exchange)', $vm->getPruneLog(), true),
	'this machine\'s mv exchanges the two trees atomically', implode(' | ', $vm->getPruneLog()));

$site = cp_make_site($everything);
$refuse = function (string $cmd): int {
	if (strpos($cmd, '--exchange') !== false) { return 1; }
	$o = array(); $rc = 0; exec($cmd . ' 2>&1', $o, $rc); return $rc;
};
$vr = cp_validator($site, array('mv' => $refuse));
check($vr->pruneUnneededPackages() === true && cp_pruned_right($site),
	'a mv that refuses --exchange: the two renames run', implode(' | ', $vr->getPruneLog()));
check(in_array('Swapped in the pruned copy (two renames)', $vr->getPruneLog(), true), 'the log says which swap ran');

$site = cp_make_site($everything);
$plain = function (string $cmd): int {
	if (strpos($cmd, '--exchange') !== false) {
		// Takes the flag, does a plain move: the copy lands inside vendor/.
		$cmd = str_replace('mv --exchange -T --', 'mv --', $cmd);
	}
	$o = array(); $rc = 0; exec($cmd . ' 2>&1', $o, $rc); return $rc;
};
$vp = cp_validator($site, array('mv' => $plain));
check($vp->pruneUnneededPackages() === true && cp_pruned_right($site) && !file_exists($site . '/vendor/vendor.prune-new'),
	'a mv that takes --exchange but moves plainly: caught by the marker, undone, then the two renames');
check(in_array('mv --exchange did a plain move; moved the copy back out', $vp->getPruneLog(), true),
	'the log says the plain move was caught');

section('Review findings (reviewer2 B19)');

// B19b: a marker an earlier crashed run left in the live vendor/ never passes for this run's.
$site = cp_make_site($everything);
file_put_contents($site . '/vendor/.prune-new', 'pruned copy' . "\n");
$fail_all = function (string $cmd): int { return 1; };
$vs = cp_validator($site, array('mv' => $fail_all));
check($vs->pruneUnneededPackages() === false && is_file($site . '/vendor/gone/x/f.php'),
	'a stale marker in vendor/ and a swap that does nothing: reported as failed, not as swapped',
	implode(' | ', $vs->getPruneLog()));
check(!file_exists($site . '/vendor/.prune-new'), 'step 1 removes a stale marker from vendor/');

// B19a: every rename of the fallback fails after vendor/ moved aside.
$site = cp_make_site($everything);
$half = function (string $cmd) use ($site): int {
	if (strpos($cmd, '--exchange') !== false) { return 1; }
	// The first rename happens, then nothing more does.
	rename($site . '/vendor', $site . '/vendor.prune-old');
	return 1;
};
$vh = cp_validator($site, array('mv' => $half));
check($vh->pruneUnneededPackages() === false && is_dir($site . '/vendor') && cp_loads($site . '/vendor')
	&& !file_exists($site . '/vendor.prune-old'),
	'the fallback leaves vendor/ missing: the prune renames vendor.prune-old back itself',
	implode(' | ', $vh->getPruneLog()));

section('validate() warns, never errors');

$src = file_get_contents(PathHelper::getIncludePath('includes/ComposerValidator.php'));
check(strpos($src, '$this->warnings[] = "Packages not needed by this code: "') !== false
	&& strpos($src, '$this->errors[] = "Packages not needed') === false,
	'unneeded packages are a warning in validate()');

harness_finish();
