<?php
/** @joinery-test
 * name: backup_tree_swap
 * tier: safe
 * env: any
 * needs: []
 * timeout: 120
 */
/**
 * A chain never spans a swap of the code tree.
 *
 * An upgrade deploys by moving every directory in public_html out and the
 * staged ones in. The new directories can reuse inode numbers the snapshot
 * recorded for other paths, and GNU tar's next incremental then records
 * directory renames no extraction can apply ("Cannot rename ... Directory not
 * empty"), so every restore point after the upgrade is lost. The files engine
 * records which tree its snapshot describes and starts over from a full when
 * the tree changed. Proved here with real tar against a scratch tree shaped
 * like a site: the swap is done the way utils/upgrade.php does it.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$engine = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/backup_files.sh';
$w = harness_scratch_dir('backup_tree_swap') . '/run-' . getmypid();
@mkdir($w, 0700, true);
harness_defer(function () use ($w) { exec('rm -rf ' . escapeshellarg($w)); });

$site = $w . '/site';
$snar = $w . '/.site.snar';

function tree_make($dir, $tag) {
	@mkdir($dir . '/plugins/event_manager/views', 0755, true);
	@mkdir($dir . '/includes', 0755, true);
	file_put_contents($dir . '/plugins/event_manager/views/a.php', $tag . "\n");
	file_put_contents($dir . '/includes/b.php', $tag . "\n");
	file_put_contents($dir . '/VERSION', $tag . "\n");
}

/** One engine run: returns [level, archive path]. */
function engine_run($engine, $site, $snar, $out_dir, $name) {
	$cmd = 'bash ' . escapeshellarg($engine) . ' site --project-dir ' . escapeshellarg($site)
		. ' --output-dir ' . escapeshellarg($out_dir) . ' --name ' . escapeshellarg($name)
		. ' --snar ' . escapeshellarg($snar) . ' --plaintext 2>/dev/null';
	$lines = array(); $rc = 0;
	exec($cmd, $lines, $rc);
	$level = null; $archive = '';
	foreach ($lines as $l) {
		if (strpos($l, 'LEVEL=') === 0)   { $level = (int)substr($l, 6); }
		if (strpos($l, 'ARCHIVE=') === 0) { $archive = substr($l, 8); }
	}
	return array($rc === 0 ? $level : null, $archive);
}

/** Replay archives in order the way restore_chain.sh does; returns tar's status. */
function chain_extract(array $archives, $into) {
	@mkdir($into, 0700, true);
	foreach ($archives as $a) {
		$rc = 0; $o = array();
		exec('tar --incremental --warning=no-timestamp -xzf ' . escapeshellarg($a) . ' -C ' . escapeshellarg($into) . ' 2>&1', $o, $rc);
		if ($rc !== 0) { return $rc; }
	}
	return 0;
}

function trees_equal($a, $b) {
	$rc = 0; $o = array();
	exec('diff -r ' . escapeshellarg($a) . ' ' . escapeshellarg($b) . ' 2>&1', $o, $rc);
	return $rc === 0;
}

tree_make($site . '/public_html', 'v1');
tree_make($site . '/public_html_last', 'v0');

// ── An unchanged tree extends the chain ─────────────────────────────────────
section('An unchanged tree gets an incremental');

list($l0, $a0) = engine_run($engine, $site, $snar, $w, 'files-0000');
check($l0 === 0, 'the first run is a full', var_export($l0, true));
check(is_file($snar . '.tree'), 'the run records which tree its snapshot describes');

file_put_contents($site . '/public_html/includes/b.php', "v1 edited\n");
list($l1, $a1) = engine_run($engine, $site, $snar, $w, 'files-0001');
check($l1 === 1, 'an ordinary edit extends the chain', var_export($l1, true));
check(chain_extract(array($a0, $a1), $w . '/out1') === 0, 'the chain extracts');
check(trees_equal($site, $w . '/out1/site'), 'the extracted chain is the tree as it stands');

// ── A swap starts a new chain ───────────────────────────────────────────────
section('A swapped tree starts a new chain');

$snar_before_swap = $w . '/snar-before-swap';
copy($snar, $snar_before_swap);

// utils/upgrade.php's deploy: the backup area is cleared, the staged tree is
// laid down, the live children move out and the staged children move in.
$stage = $site . '/uploads/upgrades/public_html';
exec('find ' . escapeshellarg($site . '/public_html_last') . ' -mindepth 1 -delete');
tree_make($stage, 'v2');
exec('find ' . escapeshellarg($site . '/public_html') . ' -mindepth 1 -maxdepth 1 -exec mv -t '
	. escapeshellarg($site . '/public_html_last') . ' {} +');
exec('find ' . escapeshellarg($stage) . ' -mindepth 1 -maxdepth 1 -exec mv -t '
	. escapeshellarg($site . '/public_html') . ' {} +');
exec('rm -rf ' . escapeshellarg($site . '/uploads'));

list($l2, $a2) = engine_run($engine, $site, $snar, $w, 'files-0002');
check($l2 === 0, 'the run after a swap is a full, not an incremental across the swap', var_export($l2, true));
check(chain_extract(array($a2), $w . '/out2') === 0, 'the new chain extracts');
check(trees_equal($site, $w . '/out2/site'), 'the new chain is the swapped tree exactly');

list($l3, $a3) = engine_run($engine, $site, $snar, $w, 'files-0003');
check($l3 === 1, 'the new chain is extended by the next run', var_export($l3, true));
check(chain_extract(array($a2, $a3), $w . '/out3') === 0, 'the new chain extracts with its incremental');

// What the engine refuses to do: an incremental across the swap, from the
// snapshot the old chain carried. Whether tar can extract it depends on which
// inodes the filesystem handed back, so it is reported, not asserted.
$raw = $w . '/across-swap.tar.gz';
exec('tar --listed-incremental=' . escapeshellarg($snar_before_swap) . ' -czf ' . escapeshellarg($raw)
	. ' -C ' . escapeshellarg($w) . ' site 2>/dev/null');
$across = chain_extract(array($a0, $a1, $raw), $w . '/out-across');
echo '  (an incremental across this swap ' . ($across === 0 ? 'happened to extract' : 'failed to extract, tar exit ' . $across)
	. ' — the case the new chain avoids)' . "\n";

// ── A snapshot with no record of its tree ───────────────────────────────────
section('A snapshot with no tree record starts a new chain');

unlink($snar . '.tree');
list($l4, ) = engine_run($engine, $site, $snar, $w, 'files-0004');
check($l4 === 0, 'a chain from before the record rolls to a full', var_export($l4, true));

// ── The decision ────────────────────────────────────────────────────────────
section('The chain decision');

require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
$m = BackupChain::add_run(BackupChain::start('chain-20260901_000000', 'site', array('recipients' => array())),
	0, 0, array());
check(BackupChain::should_start_new($m, true, 7, 30, '2026-09-02 00:00:00', null, false) === 'tree_changed',
	'a changed tree ends the chain');
check(BackupChain::should_start_new($m, true, 7, 30, '2026-09-02 00:00:00', null, true) === '',
	'an unchanged tree extends it');
$started = BackupChain::start('chain-20260902_000000', 'site', array('recipients' => array()), 'tree_changed');
check(($started['started_because'] ?? '') === 'tree_changed', 'the manifest says why its chain started');

harness_finish();
