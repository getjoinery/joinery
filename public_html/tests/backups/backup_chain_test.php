<?php
/** @joinery-test
 * name: backup_chain_manifest
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The chain manifest and the rules around it.
 *
 * The end-to-end behaviour is covered by backup_chain_gate.sh with real tar.
 * What is here is the logic that decides things which cannot be un-decided:
 * when a chain ends, what order a restore applies archives in, and whether an
 * artifact is trustworthy. Each is pure, so each is asserted directly rather
 * than inferred from a round trip.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupChain.php'));

// ── Naming ──────────────────────────────────────────────────────────────────
section('Artifact naming');

check(BackupChain::artifact_name('files', 0) === 'files-0000.tar.gz.enc',
	'the full is files-0000', BackupChain::artifact_name('files', 0));
check(BackupChain::artifact_name('files', 12) === 'files-0012.tar.gz.enc',
	'sequence is zero-padded so a listing sorts correctly',
	BackupChain::artifact_name('files', 12));
check(BackupChain::artifact_name('db', 3) === 'db-0003.sql.gz.enc',
	'the database dump is named for its run', BackupChain::artifact_name('db', 3));
check(BackupChain::artifact_name('files', 0, false) === 'files-0000.tar.gz',
	'plaintext drops the .enc');

$threw = false;
try { BackupChain::artifact_name('nonsense', 0); }
catch (BackupChainException $e) { $threw = true; }
check($threw, 'an unknown artifact kind is refused rather than guessed at');

// Padding must hold past 4 digits rather than silently colliding.
check(BackupChain::artifact_name('files', 12345) === 'files-12345.tar.gz.enc',
	'a sequence past the padding width still produces a unique name',
	BackupChain::artifact_name('files', 12345));

// ── When a chain ends ───────────────────────────────────────────────────────
section('When a new chain starts');

$fresh = BackupChain::start('chain-20260801_000000', 'site', array('recipients' => array()));
$fresh = BackupChain::add_run($fresh, 0, 0, array(
	'files' => array('name' => 'files-0000.tar.gz.enc', 'bytes' => 100, 'sha256' => str_repeat('a', 64)),
));
$fresh['created'] = '2026-08-01T00:00:00Z';

check(BackupChain::should_start_new(null, true) === 'no_chain',
	'nothing to extend means a new chain');
check(BackupChain::should_start_new(array('runs' => array()), true) === 'no_chain',
	'a chain with no runs is not extendable');

// The safe degradation: without the snapshot tar cannot produce a valid
// incremental, so the run must become a full rather than a broken increment.
check(BackupChain::should_start_new($fresh, false) === 'snar_lost',
	'a lost snapshot starts a new chain');

check(BackupChain::should_start_new($fresh, true, 7, 30, '2026-08-03 00:00:00') === '',
	'inside the interval the chain continues',
	var_export(BackupChain::should_start_new($fresh, true, 7, 30, '2026-08-03 00:00:00'), true));
check(BackupChain::should_start_new($fresh, true, 7, 30, '2026-08-08 00:00:01') === 'age',
	'past the interval a new full is taken');
check(BackupChain::should_start_new($fresh, true, 0, 30, '2030-01-01 00:00:00') === '',
	'an interval of 0 means never roll on age');

$long = $fresh;
for ($i = 1; $i <= 31; $i++) {
	$long = BackupChain::add_run($long, $i, 1, array(
		'files' => array('name' => "files-000{$i}.tar.gz.enc", 'bytes' => 10, 'sha256' => str_repeat('b', 64)),
	));
}
check(BackupChain::should_start_new($long, true, 0, 30, '2026-08-02 00:00:00') === 'length',
	'too many incrementals on one full starts a new chain');

// Snapshot loss outranks everything: no other reason matters if tar cannot
// produce a valid incremental at all.
check(BackupChain::should_start_new($long, false, 0, 30, '2026-08-02 00:00:00') === 'snar_lost',
	'snapshot loss is reported ahead of length');

// ── Restore order ───────────────────────────────────────────────────────────
section('Restore order and completeness');

$chain = BackupChain::start('chain-20260801_000000', 'site', array('recipients' => array()));
for ($i = 0; $i <= 3; $i++) {
	$chain = BackupChain::add_run($chain, $i, $i === 0 ? 0 : 1, array(
		'files' => array('name' => "files-000{$i}.tar.gz.enc", 'bytes' => 10 + $i, 'sha256' => str_repeat((string)$i, 64)),
		'db'    => array('name' => "db-000{$i}.sql.gz.enc", 'bytes' => 5, 'sha256' => str_repeat('d', 64)),
	));
}

$plan = BackupChain::restore_plan($chain);
check(count($plan['files']) === 4, 'restoring the newest run applies every archive', count($plan['files']));
check($plan['files'][0]['name'] === 'files-0000.tar.gz.enc', 'starting with the full');
check($plan['files'][3]['name'] === 'files-0003.tar.gz.enc', 'ending with the newest increment');
check($plan['db']['name'] === 'db-0003.sql.gz.enc', 'and the database of the run being restored');

$mid = BackupChain::restore_plan($chain, 1);
check(count($mid['files']) === 2, 'restoring an earlier run applies only up to it', count($mid['files']));
check($mid['db']['name'] === 'db-0001.sql.gz.enc', 'with that run\'s database, not the newest');

// Order is what makes deletions replay correctly; assert it explicitly.
$names = array_map(function ($f) { return $f['name']; }, $plan['files']);
$sorted = $names;
sort($sorted);
check($names === $sorted, 'archives are listed in application order', implode(',', $names));

$threw = false;
try { BackupChain::restore_plan($chain, 99); }
catch (BackupChainException $e) { $threw = true; }
check($threw, 'asking for a run the chain does not have is refused');

// A chain that does not begin with a full cannot be restored at all — better to
// say so than to apply increments onto whatever happens to be on disk.
$headless = BackupChain::start('chain-x', 'site', array('recipients' => array()));
$headless = BackupChain::add_run($headless, 0, 1, array(
	'files' => array('name' => 'files-0000.tar.gz.enc', 'bytes' => 1, 'sha256' => str_repeat('e', 64)),
));
$threw = false;
try { BackupChain::restore_plan($headless); }
catch (BackupChainException $e) { $threw = true; }
check($threw, 'a chain not starting with a full is refused');

// ── Artifact verification ───────────────────────────────────────────────────
section('Artifacts are checked before use');

$tmp = sys_get_temp_dir() . '/jy_chain_' . getmypid() . '.bin';
file_put_contents($tmp, 'the archive bytes');
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

$good = array('bytes' => filesize($tmp), 'sha256' => hash_file('sha256', $tmp));
check(BackupChain::verify_artifact($tmp, $good) === true, 'a matching artifact verifies');

$threw = false;
try { BackupChain::verify_artifact($tmp, array('bytes' => 999999, 'sha256' => $good['sha256'])); }
catch (BackupChainException $e) { $threw = true; }
check($threw, 'a size mismatch is refused (the truncated-download case)');

$threw = false;
try { BackupChain::verify_artifact($tmp, array('bytes' => $good['bytes'], 'sha256' => str_repeat('0', 64))); }
catch (BackupChainException $e) { $threw = true; }
check($threw, 'a hash mismatch is refused (the damaged-or-swapped case)');

$threw = false;
try { BackupChain::verify_artifact($tmp . '.nope', $good); }
catch (BackupChainException $e) { $threw = true; }
check($threw, 'a missing artifact is refused');

// ── Encoding and keys ───────────────────────────────────────────────────────
section('Manifest storage');

$decoded = BackupChain::decode(BackupChain::encode($chain));
check($decoded['chain_id'] === $chain['chain_id'], 'encode/decode preserves the chain');
check(count($decoded['runs']) === 4, 'and its runs');

$bad = $chain; $bad['version'] = 99;
$threw = false;
try { BackupChain::decode(json_encode($bad)); }
catch (BackupChainException $e) { $threw = true; }
check($threw, 'a manifest from a newer format is refused, not half-read');

$keys = BackupChain::object_keys($chain, 'joinery-backups', 'site');
check(in_array('joinery-backups/site/chain-20260801_000000/manifest.json', $keys, true),
	'the manifest is among the chain objects');
check(count($keys) === 1 + (4 * 2), 'every artifact of every run is listed for deletion', (string)count($keys));
check(BackupChain::bytes($chain) === (10 + 11 + 12 + 13) + (5 * 4),
	'chain size totals every artifact', (string)BackupChain::bytes($chain));

harness_finish();
