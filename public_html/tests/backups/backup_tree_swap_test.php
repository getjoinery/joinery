<?php
/** @joinery-test
 * name: backup_tree_swap
 * tier: safe
 * env: any
 * needs: []
 * timeout: 120
 */
/**
 * No incremental ever spans a swap of the code tree, and a swap re-bases the
 * code alone.
 *
 * An upgrade deploys by moving every directory in public_html out and the
 * staged ones in. The new directories can reuse inode numbers the snapshot
 * recorded for other paths, and GNU tar's next incremental then records
 * directory renames no extraction can apply ("Cannot rename ... Directory not
 * empty"), so every restore point after the upgrade is lost. The files engine
 * records which tree its snapshot describes and starts that archive over from
 * a full when the tree changed. A chain archives code and data apart
 * (--part), so the swap re-bases the code while the data keeps incrementing.
 * Proved here with real tar against a scratch tree shaped like a site: the
 * swap is done the way utils/upgrade.php does it.
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

/**
 * One engine run: returns [level, archive path]. $part '' archives the whole
 * site. A failed run's stderr is kept in $GLOBALS['engine_errors'] and shown
 * by the checks, so a failure says why rather than only that it happened.
 */
function engine_run($engine, $site, $snar, $out_dir, $name, $part = '') {
	$err = tempnam(sys_get_temp_dir(), 'tsw');
	$cmd = 'bash ' . escapeshellarg($engine) . ' site --project-dir ' . escapeshellarg($site)
		. ' --output-dir ' . escapeshellarg($out_dir) . ' --name ' . escapeshellarg($name)
		. ' --snar ' . escapeshellarg($snar) . ' --plaintext'
		. ($part !== '' ? ' --part ' . escapeshellarg($part) : '') . ' 2>' . escapeshellarg($err);
	$lines = array(); $rc = 0;
	exec($cmd, $lines, $rc);
	if ($rc !== 0) {
		$GLOBALS['engine_errors'][] = $name . ' (exit ' . $rc . '): ' . trim((string)@file_get_contents($err));
	}
	@unlink($err);
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

/** The same for a code archive, which is rooted at public_html and lands in the site directory. */
function code_extract(array $archives, $site_dir) {
	@mkdir($site_dir, 0700, true);
	foreach ($archives as $a) {
		$rc = 0; $o = array();
		exec('tar --incremental --warning=no-timestamp -xzf ' . escapeshellarg($a) . ' -C ' . escapeshellarg($site_dir) . ' 2>&1', $o, $rc);
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

// ── A swap starts the archive over ──────────────────────────────────────────
section('A swapped tree starts the whole-site archive over');

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
section('A snapshot with no tree record starts over');

unlink($snar . '.tree');
list($l4, ) = engine_run($engine, $site, $snar, $w, 'files-0004');
check($l4 === 0, 'a snapshot from before the record rolls to a full', var_export($l4, true));

// ── Code and data apart: a swap re-bases the code alone ─────────────────────
section('A swap re-bases the code; the data keeps incrementing');

$p = $w . '/parts';
$ps = $p . '/site';
tree_make($ps . '/public_html', 'v1');
@mkdir($ps . '/uploads/photos', 0755, true);
file_put_contents($ps . '/uploads/photos/a.jpg', "photo a\n");
@mkdir($ps . '/config', 0755, true);
file_put_contents($ps . '/config/site.conf', "conf\n");
$ds = $p . '/.site.data.snar';
$cs = $p . '/.site.code.snar';

list($d0, $da0) = engine_run($engine, $ps, $ds, $p, 'data-0000', 'data');
list($c0, $ca0) = engine_run($engine, $ps, $cs, $p, 'code-0000', 'code');
check($d0 === 0 && $c0 === 0, 'the first run is a full of both parts');

// The upgrade's swap, as utils/upgrade.php does it, and a new upload beside it.
$pstage = $ps . '/uploads/upgrades/public_html';
tree_make($pstage, 'v2');
@mkdir($ps . '/public_html_last', 0755, true);
exec('find ' . escapeshellarg($ps . '/public_html') . ' -mindepth 1 -maxdepth 1 -exec mv -t '
	. escapeshellarg($ps . '/public_html_last') . ' {} +');
exec('find ' . escapeshellarg($pstage) . ' -mindepth 1 -maxdepth 1 -exec mv -t '
	. escapeshellarg($ps . '/public_html') . ' {} +');
exec('rm -rf ' . escapeshellarg($ps . '/uploads/upgrades') . ' ' . escapeshellarg($ps . '/public_html_last'));
file_put_contents($ps . '/uploads/photos/b.jpg', "photo b\n");

list($d1, $da1) = engine_run($engine, $ps, $ds, $p, 'data-0001', 'data');
list($c1, $ca1) = engine_run($engine, $ps, $cs, $p, 'code-0001', 'code');
check($d1 === 1, 'the data increments across the upgrade', var_export($d1, true) . ' ' . implode(' | ', $GLOBALS['engine_errors'] ?? array()));
check($c1 === 0, 'the code starts over at the upgrade', var_export($c1, true));
check(filesize($da1) < filesize($da0) + 200, 'and the data increment carries only what changed, not the whole upload tree again');

list($d2, $da2) = engine_run($engine, $ps, $ds, $p, 'data-0002', 'data');
list($c2, $ca2) = engine_run($engine, $ps, $cs, $p, 'code-0002', 'code');
check($d2 === 1 && $c2 === 1, 'the next run increments both', implode(' | ', $GLOBALS['engine_errors'] ?? array()));

$restored = $p . '/restored';
check(chain_extract(array($da0, $da1, $da2), $restored) === 0 && code_extract(array($ca1, $ca2), $restored . '/site') === 0,
	'the data from its full and the code from its re-base extract');
check(trees_equal($ps, $restored . '/site'), 'into exactly the tree as it stands');

// A vendor reinstall or a wiped cache is not a new tree.
@mkdir($ps . '/vendor', 0755, true);
list($d3, ) = engine_run($engine, $ps, $ds, $p, 'data-0003', 'data');
exec('rm -rf ' . escapeshellarg($ps . '/vendor')); @mkdir($ps . '/vendor', 0755, true);
list($d4, ) = engine_run($engine, $ps, $ds, $p, 'data-0004', 'data');
check($d4 === 1, 'a directory the data archive leaves out can be recreated without re-basing the data', var_export($d4, true) . ' ' . implode(' | ', $GLOBALS['engine_errors'] ?? array()));
// A top-level data directory swapped by hand is.
exec('mv ' . escapeshellarg($ps . '/config') . ' ' . escapeshellarg($ps . '/config.old') . ' && mkdir '
	. escapeshellarg($ps . '/config') . ' && cp -a ' . escapeshellarg($ps . '/config.old') . '/. ' . escapeshellarg($ps . '/config')
	. ' && rm -rf ' . escapeshellarg($ps . '/config.old'));
list($d5, ) = engine_run($engine, $ps, $ds, $p, 'data-0005', 'data');
check($d5 === 0, 'a top-level data directory laid down again re-bases the data', var_export($d5, true));

// ── The decision ────────────────────────────────────────────────────────────
section('The chain decision');

require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
$m2 = BackupChain::add_run(BackupChain::start('chain-20260901_000000', 'site', array('recipients' => array()), '', 2),
	0, 0, array());
check(BackupChain::should_start_new($m2, true, 7, 30, '2026-09-02 00:00:00', null, 2) === '',
	'a version-2 chain continues under a runner that writes version 2 — a swapped tree does not end it');
$m1 = BackupChain::add_run(BackupChain::start('chain-20260901_000000', 'site', array('recipients' => array())),
	0, 0, array());
check(BackupChain::should_start_new($m1, false, 7, 30, '2026-09-02 00:00:00', null, 2) === 'layout_split',
	'a version-1 chain ends with layout_split, named ahead of the snapshots its layout never had');
$started = BackupChain::start('chain-20260902_000000', 'site', array('recipients' => array()), 'layout_split', 2);
check(($started['started_because'] ?? '') === 'layout_split', 'the manifest says why its chain started');

harness_finish();
