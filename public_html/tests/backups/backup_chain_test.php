<?php
/** @joinery-test
 * name: backup_chain_manifest
 * tier: safe
 * parallel: true
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
// The scheduled tick runs a few seconds earlier in the minute than the run that
// stamped `created`; seven days must still mean seven days, not eight.
$ticked = $fresh;
$ticked['created'] = '2026-09-15T04:00:21Z';
check(BackupChain::should_start_new($ticked, true, 7, 30, '2026-09-22 04:00:09') === 'age',
	'a chain created at 04:00:21 rolls on the 04:00:09 tick seven days later',
	var_export(BackupChain::should_start_new($ticked, true, 7, 30, '2026-09-22 04:00:09'), true));
check(BackupChain::should_start_new($ticked, true, 7, 30, '2026-09-21 04:00:09') === '',
	'the same chain continues on the tick one day earlier');
check(BackupChain::should_start_new($fresh, true, 0, 30, '2030-01-01 00:00:00') === '',
	'an interval of 0 means never roll on age');

// A chain has one data key, sealed at chain start — a rotated recovery key can
// only take effect in a NEW chain, so a recipient mismatch ends the current one.
$sealed = $fresh;
$sealed['envelope'] = array('recipients' => array(
	array('kind' => 'recovery', 'fingerprint' => str_repeat('0', 64)),
	array('kind' => 'site',     'fingerprint' => str_repeat('1', 64)),
));
check(BackupChain::should_start_new($sealed, true, 7, 30, '2026-08-03 00:00:00', str_repeat('0', 64)) === '',
	'a chain sealed to the current recovery key continues');
check(BackupChain::should_start_new($sealed, true, 7, 30, '2026-08-03 00:00:00', str_repeat('f', 64)) === 'recovery_rotated',
	'a rotated recovery key ends the chain',
	var_export(BackupChain::should_start_new($sealed, true, 7, 30, '2026-08-03 00:00:00', str_repeat('f', 64)), true));
check(BackupChain::should_start_new($sealed, true, 7, 30, '2026-08-03 00:00:00', null) === '',
	'no fingerprint passed means the recipient rule is not applied');
check(BackupChain::should_start_new($fresh, true, 7, 30, '2026-08-03 00:00:00', str_repeat('f', 64)) === 'recovery_rotated',
	'a manifest recording no recovery recipient does not match any current key');
check(BackupChain::should_start_new($sealed, false, 7, 30, '2026-08-03 00:00:00', str_repeat('f', 64)) === 'snar_lost',
	'a lost snapshot still outranks the recipient rule (the reason names the first cause)');

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
check(array_keys($plan['trees']) === array('files') && $plan['version'] === 1, 'a version-1 chain plans one tree kind, files');
check(count($plan['trees']['files']) === 4, 'restoring the newest run applies every archive', count($plan['trees']['files']));
check($plan['trees']['files'][0]['name'] === 'files-0000.tar.gz.enc', 'starting with the full');
check($plan['trees']['files'][3]['name'] === 'files-0003.tar.gz.enc', 'ending with the newest increment');
check($plan['database']['kind'] === 'db' && $plan['database']['artifacts'][0]['name'] === 'db-0003.sql.gz.enc',
	'and the database of the run being restored');

$mid = BackupChain::restore_plan($chain, 1);
check(count($mid['trees']['files']) === 2, 'restoring an earlier run applies only up to it', count($mid['trees']['files']));
check($mid['database']['artifacts'][0]['name'] === 'db-0001.sql.gz.enc', 'with that run\'s database, not the newest');
check(array_map(function ($i) { return $i['entry']['name']; }, BackupChain::plan_artifacts($mid))
	=== array('files-0000.tar.gz.enc', 'files-0001.tar.gz.enc', 'db-0001.sql.gz.enc'),
	'plan_artifacts() is the whole plan in restore order');

// Order is what makes deletions replay correctly; assert it explicitly.
$names = array_map(function ($f) { return $f['name']; }, $plan['trees']['files']);
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

// ── Version 2: every kind carries its own level ─────────────────────────────
section('Version 2: per-kind levels and plans');

$a2 = function ($kind, $seq, $level = null, $extra = array()) {
	$e = array('name' => BackupChain::artifact_name($kind, $seq), 'bytes' => 100 + $seq, 'sha256' => str_repeat('a', 64));
	if ($level !== null) { $e['level'] = $level; }
	return $e + $extra;
};
check(BackupChain::artifact_name('code', 4) === 'code-0004.tar.gz.enc' && BackupChain::artifact_name('data', 4) === 'data-0004.tar.gz.enc'
	&& BackupChain::artifact_name('pgdata', 4) === 'pgdata-0004.tar.gz.enc', 'code, data and pgdata are named like the files archive');

$v2 = BackupChain::start('chain-20260926_040000', 'site', array('recipients' => array()), 'no_chain', 2);
check($v2['version'] === 2, 'a chain can be started at version 2');
$threw = '';
try { BackupChain::start('chain-x', 'site', array(), '', 3); } catch (BackupChainException $e) { $threw = $e->getMessage(); }
check($threw !== '', 'there is no version 3 to start');

// Run 0: everything full. Run 1: increments. Run 2: an upgrade re-bases the
// code only. Run 3: increments again. The database is a dump until run 2, when
// the node reached PostgreSQL 17 and its first physical backup is a full.
$v2 = BackupChain::add_run($v2, 0, 0, array('data' => $a2('data', 0, 0), 'code' => $a2('code', 0, 0), 'db' => $a2('db', 0)));
$v2 = BackupChain::add_run($v2, 1, 0, array('data' => $a2('data', 1, 1), 'code' => $a2('code', 1, 1), 'db' => $a2('db', 1)));
$v2 = BackupChain::add_run($v2, 2, 0, array('data' => $a2('data', 2, 1), 'code' => $a2('code', 2, 0, array('rebased_because' => 'tree_changed')),
	'pgdata' => $a2('pgdata', 2, 0, array('raw_bytes' => 9000, 'pg' => array('major' => 18)))));
$v2 = BackupChain::add_run($v2, 3, 0, array('data' => $a2('data', 3, 1), 'code' => $a2('code', 3, 1), 'pgdata' => $a2('pgdata', 3, 1, array('raw_bytes' => 800)),
	'meta' => $a2('meta', 3), 'objects' => $a2('objects', 3)));

check($v2['runs'][0]['level'] === 0 && $v2['runs'][1]['level'] === 1 && $v2['runs'][2]['level'] === 1,
	'a run is level 0 only when every kind in it is; the $level argument is not read at version 2');
check(($v2['runs'][2]['artifacts']['code']['rebased_because'] ?? '') === 'tree_changed'
	&& ($v2['runs'][2]['artifacts']['pgdata']['raw_bytes'] ?? 0) === 9000 && ($v2['runs'][2]['artifacts']['pgdata']['pg']['major'] ?? 0) === 18,
	'an artifact keeps why it started over, and a physical backup its raw size and server');
check(BackupChain::kind_level($v2, $v2['runs'][2], 'code') === 0 && BackupChain::kind_level($v2, $v2['runs'][2], 'data') === 1
	&& BackupChain::kind_level($v2, $v2['runs'][1], 'db') === 0, 'kind_level reads each kind\'s own level; a dump is always 0');

$p3 = BackupChain::restore_plan($v2, 3);
$nm = function ($list) { return array_map(function ($a) { return $a['name']; }, $list); };
check(array_keys($p3['trees']) === array('data', 'code'), 'a version-2 chain plans data, then code');
check($nm($p3['trees']['data']) === array('data-0000.tar.gz.enc', 'data-0001.tar.gz.enc', 'data-0002.tar.gz.enc', 'data-0003.tar.gz.enc'),
	'the data goes back to the chain\'s full, across the upgrade', implode(',', $nm($p3['trees']['data'])));
check($nm($p3['trees']['code']) === array('code-0002.tar.gz.enc', 'code-0003.tar.gz.enc'),
	'the code goes back only to its re-base at the upgrade', implode(',', $nm($p3['trees']['code'])));
check($p3['database']['kind'] === 'pgdata' && $nm($p3['database']['artifacts']) === array('pgdata-0002.tar.gz.enc', 'pgdata-0003.tar.gz.enc'),
	'the physical database goes back to its own first full');
$p1 = BackupChain::restore_plan($v2, 1);
check($nm($p1['trees']['code']) === array('code-0000.tar.gz.enc', 'code-0001.tar.gz.enc') && $p1['database']['kind'] === 'db'
	&& $nm($p1['database']['artifacts']) === array('db-0001.sql.gz.enc'), 'a run before the re-base plans from the chain\'s full, with that run\'s dump');
check(array_map(function ($i) { return $i['kind']; }, BackupChain::plan_artifacts($p3))
	=== array('data', 'data', 'data', 'data', 'code', 'code', 'pgdata', 'pgdata', 'meta', 'objects'), 'restore order: data, code, database, meta, objects');

$threw = '';
try { BackupChain::add_run($v2, 4, 0, array('data' => $a2('data', 4))); } catch (BackupChainException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'level') !== false, 'a version-2 artifact of a kind that increments must say its level', $threw);

$gap = $v2; unset($gap['runs'][1]['artifacts']['data']);
$threw = '';
try { BackupChain::restore_plan($gap, 3); } catch (BackupChainException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'Run 1 has no data artifact') !== false, 'a kind missing from a run it spans is refused, naming the run', $threw);
$headless2 = $v2; $headless2['runs'][0]['artifacts']['code']['level'] = 1;
$threw = '';
try { BackupChain::restore_plan($headless2, 1); } catch (BackupChainException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'no full code backup') !== false, 'a kind with no full at or before the run is refused', $threw);

check(BackupChain::decode(BackupChain::encode($v2))['version'] === 2, 'a version-2 manifest decodes');
$runner_exp = BackupRunner::expected_bytes($v2, 0);
check($runner_exp === (100 + 0) + (100 + 2), 'expected_bytes for a full sums each tree kind\'s newest level 0', (string)$runner_exp);
check(BackupRunner::expected_bytes($v2, 1) === 103 + 103, 'and for an incremental each kind\'s newest');
check(BackupRunner::expected_bytes($chain, 0) === 10, 'a version-1 chain reads its files full as before');
check(BackupStaging::wanted($p3) === $nm(array_map(function ($i) { return $i['entry']; }, BackupChain::plan_artifacts($p3))),
	'staging fetches exactly the plan\'s artifacts, in restore order');
$set3 = 0;
foreach (BackupChain::plan_artifacts($p3) as $i) { $set3 += (int)$i['entry']['bytes']; }
check(BackupVerifier::disk_needed($v2, 3, BackupVerifier::LEVEL_READ) === $set3, 'reading needs room for the staged set');
check(BackupVerifier::disk_needed($v2, 3, BackupVerifier::LEVEL_REHEARSE) === $set3 + (100 + 102) * 2 + (9000 + 800) + 9000,
	'a rehearsal adds each tree kind\'s full twice, and a physical database\'s raw artifacts plus its combined copy',
	(string)BackupVerifier::disk_needed($v2, 3, BackupVerifier::LEVEL_REHEARSE));
check(BackupVerifier::disk_needed($chain, 3, BackupVerifier::LEVEL_REHEARSE) === (10 + 11 + 12 + 13 + 5) + 10 * 2 + 5 * 3,
	'a version-1 chain\'s rehearsal room is the full twice and the dump three times, as before');

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

$keys = BackupChain::object_keys($chain, 'joinery-backups', 'mysite', BackupProfile::SITE);
check(in_array('joinery-backups/mysite/site/chain-20260801_000000/manifest.json', $keys, true),
	'the manifest is among the chain objects');
$mgr_keys = BackupChain::object_keys($chain, 'joinery-backups', 'mysite', BackupProfile::MANAGER);
check(in_array('joinery-backups/mysite/manager/chain-20260801_000000/manifest.json', $mgr_keys, true),
	'the same chain under the manager profile addresses a different backup storage');
check(count(array_intersect($keys, $mgr_keys)) === 0,
	'and the two profiles share no object key at all');
check(count($keys) === 1 + (4 * 2), 'every artifact of every run is listed for deletion', (string)count($keys));
check(BackupChain::bytes($chain) === (10 + 11 + 12 + 13) + (5 * 4),
	'chain size totals every artifact', (string)BackupChain::bytes($chain));

harness_finish();
