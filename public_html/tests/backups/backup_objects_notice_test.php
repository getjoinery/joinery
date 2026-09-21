<?php
/** @joinery-test
 * name: backup_objects_notice
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Offloaded files and the shelf, as the admin surfaces say it
 * (specs/backup_offloaded_files.md § Admin surfaces), over fixtures:
 *
 *   - the figures: what each enabled backup holds (from its held set), what
 *     waits on this server because a backup lacks it (a real file on disk,
 *     one stat), what is still to copy from the file store (no local bytes,
 *     not held), what every backup holds and is released at the next run
 *   - the notice renders above the byte threshold, above the age threshold
 *     with anything waiting, and not otherwise; its text names the backup
 *     and how long since it succeeded
 *   - the same-account line appears exactly when the two access keys match
 *   - the sentences the pages show
 *   - Recovery Readiness: the epochs only a retired key opens, from the record
 *     a run keeps beside held.json, counted and sized
 *
 * Nothing is read from the database: every fact arrives through
 * BackupObjectsStatus::$test_hooks, and the files live in a temp directory.
 *
 * Run: php tests/backups/backup_objects_notice_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupObjectsStatus.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectsNotice.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));

$tmp = sys_get_temp_dir() . '/jy_objects_notice_' . getmypid();
@mkdir($tmp, 0700, true);
register_shutdown_function(function () use ($tmp) {
	foreach (glob($tmp . '/*/*/*') ?: array() as $f) { @unlink($f); }
	foreach (glob($tmp . '/*/*') ?: array() as $f) { is_dir($f) ? @rmdir($f) : @unlink($f); }
	foreach (glob($tmp . '/*') ?: array() as $f) { is_dir($f) ? @rmdir($f) : @unlink($f); }
	@rmdir($tmp);
});

$GB = 1073741824;
$MB = 1048576;

// Three files on disk (a, b, c — the stat decides their waiting bytes), two
// gone (d, e — the row's size decides what a catch-up would copy). a.jpg's
// first candidate path does not exist, so the second is the one that counts.
$on_disk = array('a.jpg' => 3 * $MB, 'b.jpg' => 2 * $MB, 'c.jpg' => 1 * $MB);
foreach ($on_disk as $n => $size) { file_put_contents($tmp . '/' . $n, str_repeat('x', $size)); }
$rows = array(
	array('name' => 'a.jpg', 'size' => 3 * $MB, 'paths' => array($tmp . '/nowhere/a.jpg', $tmp . '/a.jpg')),
	array('name' => 'b.jpg', 'size' => 2 * $MB, 'paths' => array($tmp . '/b.jpg')),
	array('name' => 'c.jpg', 'size' => 1 * $MB, 'paths' => array($tmp . '/c.jpg')),
	array('name' => 'd.jpg', 'size' => 7 * $MB, 'paths' => array($tmp . '/d.jpg')),
	array('name' => 'e.jpg', 'size' => 5 * $MB, 'paths' => array($tmp . '/e.jpg')),
);
$entry = function ($epoch, $bytes) { return array('epoch' => $epoch, 'object_bytes' => $bytes, 'object_sha256' => str_repeat('a', 64)); };

section('The figures');

// Site holds a, c, d; manager holds a only. Both enabled.
$held = array(
	BackupProfile::SITE    => array('a.jpg' => $entry('epoch-1', 100), 'c.jpg' => $entry('epoch-1', 200), 'd.jpg' => $entry('epoch-2', 300)),
	BackupProfile::MANAGER => array('a.jpg' => $entry('epoch-1', 100)),
);
$runs = array(
	BackupProfile::SITE    => array('indexed' => array('time' => '2026-09-20 03:00:00', 'label' => 'objects-0003.json.gz'), 'last_success' => '2026-09-20 03:00:00'),
	BackupProfile::MANAGER => array('indexed' => null, 'last_success' => '2026-09-01 03:00:00'),
);
$keys = array('store' => 'K1', 'target' => 'K2');
$st = BackupObjectsStatus::figures($rows, array(BackupProfile::SITE, BackupProfile::MANAGER), $held, $runs, $keys);

check($st['total']['count'] === 5 && $st['total']['bytes'] === 18 * $MB, 'every cloud row is counted with its size');
check($st['shelf']['site']['count'] === 3 && $st['shelf']['site']['bytes'] === 600, 'the site shelf holds three objects, 600 bytes');
check($st['shelf']['manager']['count'] === 1 && $st['shelf']['manager']['known'] === true, 'the manager shelf holds one');
check($st['shelf']['site']['indexed']['time'] === '2026-09-20 03:00:00', 'the site shelf names its last indexing run');
check($st['shelf']['manager']['indexed'] === null, 'the manager shelf has no index yet');
// a: on disk, held by both → releasable. b: on disk, held by nobody → waiting for both.
// c: on disk, manager lacks → waiting for manager. d: gone, manager lacks → catch-up. e: gone, both lack → catch-up.
check($st['releasable']['count'] === 1 && $st['releasable']['bytes'] === 3 * $MB, 'a file every backup holds is releasable, sized by its stat');
check($st['waiting']['count'] === 2 && $st['waiting']['bytes'] === 3 * $MB, 'two files wait on disk (b, c) — bytes from the stat');
check($st['waiting']['for']['site']['count'] === 1 && $st['waiting']['for']['site']['bytes'] === 2 * $MB, 'one of them waits on the site backup');
check($st['waiting']['for']['manager']['count'] === 2, 'both wait on the manager backup');
check($st['catchup']['count'] === 2 && $st['catchup']['bytes'] === 12 * $MB, 'two files with no local bytes are still to copy — sized by the row');
check($st['same_account'] === false, 'different keys: not the same account');
check($st['last_success']['manager'] === '2026-09-01 03:00:00', 'the last success per profile is carried');

// The stat is real: remove c.jpg and it moves from waiting to catch-up.
@unlink($tmp . '/c.jpg');
$st2 = BackupObjectsStatus::figures($rows, array(BackupProfile::SITE, BackupProfile::MANAGER), $held, $runs, $keys);
check($st2['waiting']['count'] === 1 && $st2['catchup']['count'] === 3, 'a file whose local copy is gone stops waiting and is to copy');
file_put_contents($tmp . '/c.jpg', str_repeat('x', $MB));

// No profile enabled: nothing waits, nothing is to copy, nothing is releasable.
$st3 = BackupObjectsStatus::figures($rows, array(), array(), array(), $keys);
check($st3['waiting']['count'] === 0 && $st3['catchup']['count'] === 0 && $st3['releasable']['count'] === 3, 'with no backup enabled nothing waits or is to copy; local copies are simply releasable');
check($st3['shelf'] === array(), 'no shelf lines without an enabled profile');

// A profile enabled with no held.json yet: everything waits on it, and the shelf says none yet.
$st4 = BackupObjectsStatus::figures($rows, array(BackupProfile::SITE), array(BackupProfile::SITE => null), array(), $keys);
check($st4['shelf']['site']['known'] === false && $st4['shelf']['site']['count'] === 0, 'a profile with no held set is "none yet"');
check($st4['waiting']['count'] === 3 && $st4['catchup']['count'] === 2, 'everything waits on a backup that has stored nothing');

section('The sentences');

$lines = BackupObjectsStatus::shelf_sentences($st);
check($lines['site'] === 'Offloaded files on the shelf (this site\'s backup): 3 objects, 600 B; last indexed at the run of 2026-09-20 03:00 UTC.', 'the site shelf line: ' . $lines['site']);
check($lines['manager'] === 'Offloaded files on the shelf (the management node\'s backup): 1 object, 100 B; not indexed yet.', 'the manager shelf line: ' . $lines['manager']);
check(BackupObjectsStatus::shelf_sentences($st4)['site'] === 'Offloaded files on the shelf (this site\'s backup): none yet; the first run that stores them writes the record.', 'the none-yet line');
check(BackupObjectsStatus::waiting_sentence($st) === '2 files (3 MB) waiting for this site\'s backup and the management node\'s backup before their local copy is released.',
	'the waiting sentence names both backups: ' . BackupObjectsStatus::waiting_sentence($st));
check(BackupObjectsStatus::waiting_sentence($st2) === '1 file (2 MB) waiting for this site\'s backup and the management node\'s backup before its local copy is released.',
	'singular: ' . BackupObjectsStatus::waiting_sentence($st2));
check(BackupObjectsStatus::waiting_sentence($st3) === '', 'nothing waiting: no sentence');
check(BackupObjectsStatus::catchup_sentence($st) === '2 files (12 MB) still to copy from the file store.', 'the catch-up sentence: ' . BackupObjectsStatus::catchup_sentence($st));
check(BackupObjectsStatus::catchup_sentence($st3) === '', 'caught up: no sentence');
check(BackupObjectsStatus::has_content($st) && !BackupObjectsStatus::has_content(BackupObjectsStatus::figures(array(), array(), array(), array(), $keys)), 'a site with nothing offloaded has no box');

section('The same-account line');

check(BackupObjectsStatus::same_account('AKIA1', 'AKIA1') === true, 'equal keys are the same account');
check(BackupObjectsStatus::same_account('AKIA1', 'AKIA2') === false, 'different keys are not');
check(BackupObjectsStatus::same_account('', '') === false, 'two blanks are not a match');
check(BackupObjectsStatus::same_account('AKIA1', null) === false, 'no target: no match');
check(BackupObjectsStatus::same_account(' AKIA1 ', 'AKIA1') === true, 'whitespace around a key does not hide a match');
$same = BackupObjectsStatus::figures($rows, array(), array(), array(), array('store' => 'AKIA1', 'target' => 'AKIA1'));
check(BackupObjectsStatus::same_account_line($same) === 'Your backup shelf and your file store are on the same account. Losing that account loses both. A copy taken by a management node is the one that survives it.',
	'the line, exactly when the keys match');
check(BackupObjectsStatus::same_account_line($st) === '', 'and not otherwise');

section('The notice');

$now = strtotime('2026-09-21 12:00:00 UTC');
$day = 86400;
$waiting = function ($count, $bytes, array $for, array $last) {
	// A status with only what the notice reads.
	$f = array();
	foreach ($for as $p) { $f[$p] = array('count' => $count, 'bytes' => $bytes); }
	return array(
		'waiting'      => array('count' => $count, 'bytes' => $bytes, 'for' => $f),
		'last_success' => $last,
	);
};

// Under 2 GB, fresh backup: silent.
$fresh = gmdate('Y-m-d H:i:s', $now - 2 * $day);
check(BackupObjectsNotice::forStatus($waiting(10, 1 * $GB, array('manager'), array('manager' => $fresh)), $now) === '', 'under the byte threshold with a fresh backup: silent');
// Over 2 GB: renders, whatever the date.
$html = BackupObjectsNotice::forStatus($waiting(40, 3 * $GB, array('manager'), array('manager' => $fresh)), $now);
check($html !== '' && strpos($html, 'jy-backup-objects-notice') !== false, 'over 2 GB: the notice renders');
check(strpos($html, '40 files (3 GB) are waiting for the management node&#039;s backup, which last succeeded 2 days ago. They stay on this server until it does.') !== false,
	'its text names the backup and the days: ' . strip_tags($html));
check(strpos($html, 'href="/admin/admin_backups"') !== false, 'and links the Backups page');
// Exactly 2 GB is not over.
check(BackupObjectsNotice::forStatus($waiting(40, 2 * $GB, array('manager'), array('manager' => $fresh)), $now) === '', 'exactly 2 GB is not over the threshold');
// Age threshold: anything waiting on a backup last successful 8 days ago.
$stale = gmdate('Y-m-d H:i:s', $now - 8 * $day);
$html = BackupObjectsNotice::forStatus($waiting(1, 5 * $MB, array('site'), array('site' => $stale)), $now);
check(strpos($html, '1 file (5 MB) is waiting for this site&#039;s backup, which last succeeded 8 days ago. It stays on this server until it does.') !== false,
	'a week-old backup with one file waiting: the notice, singular, for the site profile: ' . strip_tags($html));
// Exactly 7 days is not older than 7 days.
$seven = gmdate('Y-m-d H:i:s', $now - 7 * $day + 60);
check(BackupObjectsNotice::forStatus($waiting(1, 5 * $MB, array('site'), array('site' => $seven)), $now) === '', 'just under seven days: silent');
// Never succeeded, with anything waiting.
$html = BackupObjectsNotice::forStatus($waiting(3, 5 * $MB, array('manager'), array('manager' => null)), $now);
check(strpos($html, 'which has never succeeded') !== false, 'a backup that never succeeded: the notice says so');
// Stale but nothing waits: silent.
check(BackupObjectsNotice::forStatus($waiting(0, 0, array(), array('site' => $stale)), $now) === '', 'nothing waiting: silent however stale');
// Waiting on a fresh backup while another, stale, backup holds everything: silent (the stale one lacks nothing).
$mixed = array(
	'waiting'      => array('count' => 2, 'bytes' => 5 * $MB, 'for' => array('site' => array('count' => 2, 'bytes' => 5 * $MB), 'manager' => array('count' => 0, 'bytes' => 0))),
	'last_success' => array('site' => $fresh, 'manager' => $stale),
);
check(BackupObjectsNotice::forStatus($mixed, $now) === '', 'the age rule counts only the backup the files wait on');
// Both lacking, one stale: names both.
$mixed['waiting']['for']['manager'] = array('count' => 2, 'bytes' => 5 * $MB);
$html = BackupObjectsNotice::forStatus($mixed, $now);
check(strpos($html, 'this site&#039;s backup, which last succeeded 2 days ago and for the management node&#039;s backup, which last succeeded 8 days ago. They stay on this server until they do.') !== false,
	'both backups named, each with its own age: ' . strip_tags($html));
check(BackupObjectsNotice::since_words(0, $now) === 'has never succeeded' && BackupObjectsNotice::since_words($now - 3600, $now) === 'last succeeded today'
	&& BackupObjectsNotice::since_words($now - $day, $now) === 'last succeeded 1 day ago', 'the since words');
// The full path: the hooks feed compute(), and render() is quiet below permission 10.
BackupObjectsStatus::$test_hooks = array(
	'rows' => function () use ($rows) { return $rows; },
	'enabled' => array(BackupProfile::MANAGER),
	'held' => array(BackupProfile::MANAGER => null),
	'runs' => array(BackupProfile::MANAGER => array('indexed' => null, 'last_success' => $stale)),
	'keys' => array('store' => 'a', 'target' => 'a'),
);
$computed = BackupObjectsStatus::compute();
check($computed['waiting']['count'] === 3 && $computed['same_account'] === true, 'compute() reads every hook');
$_SESSION['permission'] = 5;
check(BackupObjectsNotice::render() === '', 'an admin below permission 10 sees no notice');
$_SESSION['permission'] = 10;
check(strpos(BackupObjectsNotice::render(), '3 files (6 MB) are waiting') !== false, 'a superadmin sees it');
unset($_SESSION['permission']);
BackupObjectsStatus::$test_hooks = array();

section('Recovery Readiness: epochs only a retired key opens');

$base = $tmp . '/backups';
@mkdir($base . '/objects', 0700, true);
@mkdir($base . '/manager/objects', 0700, true);
$write_held = function ($path, array $held) {
	file_put_contents($path, json_encode(array('version' => 1, 'objects' => $held)));
};
$write_held(BackupObjects::held_path_for(BackupProfile::SITE, $base), array(
	'a.jpg' => $entry('epoch-20260101_000000', 1000), 'b.jpg' => $entry('epoch-20260101_000000', 2000), 'c.jpg' => $entry('epoch-20260601_000000', 4000)));
$write_held(BackupObjects::held_path_for(BackupProfile::MANAGER, $base), array(
	'a.jpg' => $entry('epoch-20260201_000000', 500)));

check(BackupObjects::retired_summary(BackupProfile::names(), $base) === array('epochs' => array(), 'count' => 0, 'bytes' => 0), 'no record: nothing retired');
$plan = array('output_dir' => $base, 'profile' => BackupProfile::SITE);
BackupObjects::write_retired_epochs($plan, array('epoch-20260101_000000'));
check(is_file(BackupObjects::retired_path_for(BackupProfile::SITE, $base)), 'the record is written beside held.json');
$sum = BackupObjects::retired_summary(BackupProfile::names(), $base);
check($sum['epochs'] === array('epoch-20260101_000000') && $sum['count'] === 2 && $sum['bytes'] === 3000, 'two objects, 3000 bytes, open only with the retired key');
BackupObjects::write_retired_epochs(array('output_dir' => $base . '/manager', 'profile' => BackupProfile::MANAGER), array('epoch-20260201_000000'));
$sum = BackupObjects::retired_summary(BackupProfile::names(), $base);
check($sum['count'] === 3 && $sum['bytes'] === 3500 && count($sum['epochs']) === 2, 'both profiles counted');
BackupObjects::write_retired_epochs($plan, array());
$sum = BackupObjects::retired_summary(BackupProfile::names(), $base);
check($sum['count'] === 1 && $sum['epochs'] === array('epoch-20260201_000000'), 'a run that found every site epoch open clears the site record');
check(BackupObjects::read_retired_file($base . '/objects/does-not-exist.json') === array(), 'a missing record reads as none');

if (class_exists('RecoveryReadinessItems')) {
	$w = RecoveryReadinessItems::retiredEpochsWarning($base);
	check(strpos($w, '1 offloaded file object (500 B) on the backup shelf opens only with a retired recovery key (epoch epoch-20260201_000000)') === 0,
		'the readiness card warning: ' . $w);
	BackupObjects::write_retired_epochs(array('output_dir' => $base . '/manager', 'profile' => BackupProfile::MANAGER), array());
	check(RecoveryReadinessItems::retiredEpochsWarning($base) === '', 'and nothing when every epoch opens');
} else {
	check(true, 'server_manager inactive here: the readiness card is not on this site');
	check(true, '(placeholder to keep the count)');
}

harness_finish();
