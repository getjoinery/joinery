<?php
/** @joinery-test
 * name: cloud_store_inventory
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The daily file-store check (specs/backup_offloaded_files.md § Verification,
 * "The file store is checked too"), over fixtures:
 *
 *   - a pass HEADs every cloud row and names the ones the bucket cannot serve
 *     — absent, or held at the wrong size — and finishes with a record the
 *     pages read
 *   - it is due once a day, continued while in progress, idle in between
 *   - a pass is taken a slice per tick, leaving a cursor, and a row that
 *     recovers between slices leaves the list
 *   - a bucket that does not answer its ping stalls the tick and names nothing
 *     missing; a HEAD that says absent is asked twice; a visibility with no
 *     store is counted as unchecked
 *   - the summary counts how many of the missing a backup shelf holds, and the
 *     sentence reads the way the pages say it
 *   - a Bring them back's names leave the missing list; the panel renders the
 *     three sources without a notice
 *
 * Nothing is written: the record lives in the test hook, the rows and the
 * bucket are fixtures.
 *
 * Run: php tests/cloud_storage/store_inventory_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/cloud_fixtures.php');

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventoryPanel.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectRestoreLauncher.php'));

// ── Fixtures ────────────────────────────────────────────────────────────────
// Seven cloud rows: five public, two private. The public bucket lacks b.jpg,
// holds e.bin at the wrong size; the private bucket has both of its files.
$rows = array();
$mk = function ($id, $name, $vis, $size) use (&$rows) {
	$rows[] = array('id' => $id, 'name' => $name, 'remote_key' => $name, 'visibility' => $vis, 'size' => $size);
};
$mk(10, 'a.jpg', 'public', 11); $mk(20, 'b.jpg', 'public', 22); $mk(30, 'c.pdf', 'public', 33);
$mk(40, 'p1.eml', 'private', 44); $mk(50, 'd.png', 'public', 55); $mk(60, 'p2.eml', 'private', 66); $mk(70, 'e.bin', 'public', 77);

$public = new InMemoryBlobDriver();
$public->objects = array('a.jpg' => str_repeat('a', 11), 'c.pdf' => str_repeat('c', 33), 'd.png' => str_repeat('d', 55), 'e.bin' => 'short');
$private = new InMemoryBlobDriver();
$private->objects = array('p1.eml' => str_repeat('p', 44), 'p2.eml' => str_repeat('q', 66));
$drivers = array('public' => $public, 'private' => $private);

CloudStoreInventory::$test_hooks = array(
	'record' => array(),
	'rows'   => function ($after, $limit) use (&$rows) {
		$out = array();
		foreach ($rows as $r) { if ($r['id'] > $after) { $out[] = $r; } }
		return array_slice($out, 0, $limit);
	},
	'driver' => function ($vis) use (&$drivers) { return $drivers[$vis] ?? null; },
);
harness_defer(function () { CloudStoreInventory::$test_hooks = array('record' => array()); });
$record = function () { return CloudStoreInventory::read(); };

// ─────────────────────────────────────────────────────────────────────────────
section('A full pass names what the bucket cannot serve');

check(CloudStoreInventory::due(CloudStoreInventory::blank_record(), time()), 'a site never checked is due');
$r = CloudStoreInventory::tick('2026-09-21 03:00:00');
check($r['status'] === 'finished' && $r['checked'] === 7, 'one tick with room checks all seven and finishes', json_encode($r));
check(strpos($r['message'], '7 offloaded files checked, 2 missing') !== false, 'the tick line says how many and how many missing', $r['message']);
$rec = $record();
check($rec['pass'] === null && is_array($rec['last']), 'the pass is over; the record holds it as the last');
check(array_keys($rec['last']['missing']) === array('b.jpg', 'e.bin'), 'b.jpg (absent) and e.bin (wrong size) are missing', json_encode(array_keys($rec['last']['missing'])));
check($rec['last']['missing']['b.jpg']['reason'] === CloudStoreInventory::REASON_ABSENT
	&& $rec['last']['missing']['e.bin']['reason'] === CloudStoreInventory::REASON_SIZE
	&& $rec['last']['missing']['b.jpg']['id'] === 20 && $rec['last']['missing']['b.jpg']['visibility'] === 'public',
	'each carries its reason, id and visibility', json_encode($rec['last']['missing']));
check($rec['last']['checked'] === 7 && $rec['last']['unchecked'] === 0 && $rec['last']['finished'] === '2026-09-21 03:00:00', 'counts and the time', json_encode($rec['last']));
check(strpos(json_encode($rec), 'secret') === false && strpos(json_encode($rec), 'key') === false, 'the record carries names and counts only');

// ─────────────────────────────────────────────────────────────────────────────
section('Once a day');

$r = CloudStoreInventory::tick('2026-09-21 15:00:00');
check($r['status'] === 'idle' && $r['message'] === '', 'twelve hours later nothing runs and the tick has no line', json_encode($r));
check(!CloudStoreInventory::due($record(), strtotime('2026-09-22 02:59:59 UTC')), 'not due a second before the day is up');
check(CloudStoreInventory::due($record(), strtotime('2026-09-22 03:00:00 UTC')), 'due when it is');

// ─────────────────────────────────────────────────────────────────────────────
section('A pass in slices, with a cursor');

$public->objects['b.jpg'] = str_repeat('b', 22);   // b.jpg is back; e.bin still short
$r = CloudStoreInventory::tick('2026-09-22 03:00:00', 60, 3);
check($r['status'] === 'running' && $r['checked'] === 3, 'a tick that may check three checks three and leaves a cursor', json_encode($r));
$rec = $record();
check(is_array($rec['pass']) && $rec['pass']['cursor'] === 30 && $rec['pass']['checked'] === 3 && $rec['pass']['missing'] === array(),
	'the cursor is the last id checked; b.jpg is no longer missing', json_encode($rec['pass']));
check(is_array($rec['last']) && array_keys($rec['last']['missing']) === array('b.jpg', 'e.bin'), 'the last completed pass still stands while this one runs');
$s = CloudStoreInventory::summary($rec, array());
check($s['running'] && $s['running_checked'] === 3 && $s['missing_count'] === 2 && $s['checked_at'] === '2026-09-21 03:00:00',
	'the summary shows the last pass and that a new one is running', json_encode($s));
check(CloudStoreInventory::due($rec, strtotime('2026-09-22 03:01:00 UTC')), 'a pass in progress is always continued');
$r = CloudStoreInventory::tick('2026-09-22 03:01:00', 60, 3);
check($r['status'] === 'running' && $record()['pass']['cursor'] === 60, 'the next tick continues from the cursor', json_encode($r));
$r = CloudStoreInventory::tick('2026-09-22 03:02:00', 60, 3);
check($r['status'] === 'finished' && $r['checked'] === 1, 'the last slice finishes the pass', json_encode($r));
$rec = $record();
check(array_keys($rec['last']['missing']) === array('e.bin') && $rec['last']['checked'] === 7 && $rec['last']['started'] === '2026-09-22 03:00:00',
	'the finished pass names only e.bin, and remembers when it started', json_encode($rec['last']));

// A zero budget checks nothing and leaves the cursor where it is.
$rec['last']['finished'] = '2026-09-20 00:00:00'; CloudStoreInventory::write($rec);
$r = CloudStoreInventory::tick('2026-09-22 04:00:00', 0);
check($r['status'] === 'running' && $r['checked'] === 0 && $record()['pass']['cursor'] === 0, 'a tick with no budget starts the pass and checks nothing', json_encode($r));

// ─────────────────────────────────────────────────────────────────────────────
section('A bucket that will not answer names nothing missing');

$mute = new class extends InMemoryBlobDriver {
	public function ping(): array { return array('ok' => false, 'message' => 'timed out'); }
};
$drivers['public'] = $mute;
$r = CloudStoreInventory::tick('2026-09-22 04:01:00', 60);
check($r['status'] === 'waiting' && strpos($r['message'], 'public file store did not answer') !== false, 'the tick waits and says which store', json_encode($r));
$rec = $record();
check(is_array($rec['pass']) && $rec['pass']['cursor'] === 0 && $rec['pass']['missing'] === array(), 'nothing was checked and nothing called missing', json_encode($rec['pass']));
$drivers['public'] = $public;

// Absent is asked twice before it counts.
$flaky = new class extends InMemoryBlobDriver {
	public $asked = array();
	public function head(string $remote_key): ?array {
		$this->asked[$remote_key] = ($this->asked[$remote_key] ?? 0) + 1;
		if ($this->asked[$remote_key] === 1) { return null; }   // the first answer is always "no"
		return parent::head($remote_key);
	}
};
$flaky->objects = $public->objects;
$drivers['public'] = $flaky;
$r = CloudStoreInventory::tick('2026-09-22 04:02:00', 60);
check($r['status'] === 'finished', 'the pass finishes', json_encode($r));
$rec = $record();
check(array_keys($rec['last']['missing']) === array('e.bin') && $flaky->asked['a.jpg'] === 2 && $flaky->asked['e.bin'] === 2,
	'a first "absent" is asked again; only the file the bucket really lacks or holds wrong is missing', json_encode($flaky->asked));
$drivers['public'] = $public;

// A visibility with no store configured cannot be checked, and is counted.
$drivers['private'] = null;
$rec = $record(); $rec['last']['finished'] = '2026-09-20 00:00:00'; CloudStoreInventory::write($rec);
$r = CloudStoreInventory::tick('2026-09-22 05:00:00', 60);
$rec = $record();
check($r['status'] === 'finished' && $rec['last']['checked'] === 5 && $rec['last']['unchecked'] === 2, 'two private rows are unchecked, five checked', json_encode($rec['last']));
check(strpos($r['message'], '2 not checked (no store configured for them)') !== false, 'and the line says so', $r['message']);
$drivers['private'] = $private;

// ─────────────────────────────────────────────────────────────────────────────
section('The summary and the sentence');

$rec = $record();
$rec['last']['missing'] = array(
	'b.jpg' => array('id' => 20, 'visibility' => 'public', 'size' => 22, 'reason' => 'absent'),
	'e.bin' => array('id' => 70, 'visibility' => 'public', 'size' => 77, 'reason' => 'size'),
	'p2.eml' => array('id' => 60, 'visibility' => 'private', 'size' => 66, 'reason' => 'absent'),
);
$held = array('site' => array('b.jpg' => array('epoch' => 'epoch-20260901_000000'), 'a.jpg' => array()), 'manager' => array('p2.eml' => array()));
$s = CloudStoreInventory::summary($rec, $held);
check($s['missing_count'] === 3 && $s['held'] === 2, 'three missing, two of them on a shelf (either profile counts)', json_encode($s));
check(CloudStoreInventory::sentence($s) === '3 offloaded files are missing from the file store; the backup holds 2 of them.', 'the sentence', CloudStoreInventory::sentence($s));
$s = CloudStoreInventory::summary($rec, array('site' => $rec['last']['missing']));
check(CloudStoreInventory::sentence($s) === '3 offloaded files are missing from the file store; the backup holds all of them.', 'all held', CloudStoreInventory::sentence($s));
$s = CloudStoreInventory::summary($rec, array('site' => null));
check($s['held'] === null && CloudStoreInventory::sentence($s) === '3 offloaded files are missing from the file store; whether the backup holds them is known after its next run.',
	'a profile with no held set cannot say yet', CloudStoreInventory::sentence($s));
$s = CloudStoreInventory::summary($rec, array('site' => null, 'manager' => array('e.bin' => array())));
check($s['held'] === 1, 'one profile that can say is enough to count', json_encode($s['held']));
$s = CloudStoreInventory::summary($rec, array());
check($s['held'] === 0 && CloudStoreInventory::sentence($s) === '3 offloaded files are missing from the file store; no backup holds any of them.', 'no enabled profile: no backup holds them', CloudStoreInventory::sentence($s));
$one = $rec; $one['last']['missing'] = array('b.jpg' => $rec['last']['missing']['b.jpg']);
check(CloudStoreInventory::sentence(CloudStoreInventory::summary($one, $held)) === '1 offloaded file is missing from the file store; the backup holds it.', 'singular');
check(CloudStoreInventory::sentence(CloudStoreInventory::summary($one, array())) === '1 offloaded file is missing from the file store; no backup holds it.', 'singular, none');
$none = $rec; $none['last']['missing'] = array();
check(CloudStoreInventory::sentence(CloudStoreInventory::summary($none, $held)) === '', 'nothing missing, no sentence');

// ─────────────────────────────────────────────────────────────────────────────
section('A Bring them back leaves its mark and clears what it brought home');

CloudStoreInventory::write($rec);
CloudStoreInventory::note_bring_back(array('started' => '2026-09-22 06:00:00', 'finished' => null, 'mode' => 'missing', 'restored' => 0, 'by' => 'this site'));
$s = CloudStoreInventory::summary($record(), $held);
check(is_array($s['bring_back']) && $s['bring_back']['finished'] === null, 'a running bring back is on the record');
$words = BackupObjectRestoreLauncher::describe_last($s['bring_back']);
check(strpos($words, 'Bringing offloaded files back now') === 0, 'and reads as running', $words);
CloudStoreInventory::forget_missing(array('b.jpg', 'e.bin'));
CloudStoreInventory::note_bring_back(array('finished' => '2026-09-22 06:05:00', 'result' => 'ok', 'restored' => 2, 'bytes' => 99, 'kept' => 0, 'skipped' => 0));
$s = CloudStoreInventory::summary($record(), $held);
check(array_keys($s['missing']) === array('p2.eml') && $s['missing_count'] === 1, 'the two it brought home are no longer missing; the third still is', json_encode(array_keys($s['missing'])));
$words = BackupObjectRestoreLauncher::describe_last($s['bring_back']);
check(strpos($words, 'Last brought back 2026-09-22 06:05 UTC: Brought 2 offloaded files home (99 B)') === 0, 'the last bring back in words', $words);
check(BackupObjectRestoreLauncher::describe_last(null) === '' && BackupObjectRestoreLauncher::describe_last(array()) === '', 'nothing to say when there has never been one');
$failed = array('started' => '2026-09-22 07:00:00', 'finished' => '2026-09-22 07:01:00', 'result' => 'fail', 'reason' => 'the shelf did not answer', 'by' => 'this site');
check(strpos(BackupObjectRestoreLauncher::describe_last($failed), 'Could not bring the offloaded files home: the shelf did not answer') !== false, 'a failure says why');

// One that started and never reported: running for STALE_SECONDS, dead after.
$live = array('started' => gmdate('Y-m-d H:i:s', time() - 600), 'finished' => null, 'restored' => 3);
check(BackupObjectRestoreLauncher::in_progress($live), 'started ten minutes ago and not reported: running');
$dead = array('started' => gmdate('Y-m-d H:i:s', time() - BackupObjectRestoreLauncher::STALE_SECONDS - 1), 'finished' => null, 'restored' => 3);
check(!BackupObjectRestoreLauncher::in_progress($dead), 'started longer ago than STALE_SECONDS and never reported: not running');
check(!BackupObjectRestoreLauncher::in_progress(array('started' => '2026-09-22 06:00:00', 'finished' => '2026-09-22 06:05:00')) && !BackupObjectRestoreLauncher::in_progress(null),
	'a reported run, or none, is not running');
$words = BackupObjectRestoreLauncher::describe_last($dead);
check(strpos($words, 'never reported back') !== false && strpos($words, '3 home by then') !== false && strpos($words, 'run it again') !== false,
	'a run that never reported is said to have died, with what it had done', $words);

// ─────────────────────────────────────────────────────────────────────────────
section('The panel, for each kind of site');

$html = CloudStoreInventoryPanel::render($s, CloudStoreInventoryPanel::SOURCE_SITE, '/admin/admin_backups');
check(strpos($html, '1 offloaded file is missing from the file store; the backup holds it.') !== false, 'the sentence is in the panel');
check(strpos($html, 'name="action" value="bring_back_objects"') !== false && strpos($html, '>Bring them back<') !== false
	&& strpos($html, 'action="/admin/admin_backups"') !== false, 'a site with its own shelf gets the button, posting to the page it is on');
check(strpos($html, 'Checked 5 offloaded files in the file store 2026-09-22 05:00 UTC') !== false, 'when it last looked');
$html = CloudStoreInventoryPanel::render($s, CloudStoreInventoryPanel::SOURCE_MANAGER, '/admin/admin_backups', 'https://manager.example');
check(strpos($html, 'bring_back_objects') === false && strpos($html, 'https://manager.example') !== false
	&& strpos($html, 'on its Backups tab') !== false, 'a managed site is told the management node runs it, by URL, with no button');
$html = CloudStoreInventoryPanel::render($s, CloudStoreInventoryPanel::SOURCE_NONE, '/admin/admin_backups');
check(strpos($html, 'bring_back_objects') === false && strpos($html, 'No backup of this site holds offloaded files') !== false, 'a site with no shelf is told there is nothing to bring them back from');
$running = $s; $running['bring_back'] = array('started' => gmdate('Y-m-d H:i:s', time() - 60), 'finished' => null, 'restored' => 1, 'by' => 'this site');
$html = CloudStoreInventoryPanel::render($running, CloudStoreInventoryPanel::SOURCE_SITE, '/admin/admin_backups');
check(strpos($html, 'bring_back_objects') === false && strpos($html, '1 home so far') !== false, 'no second button while one is running');
$died = $s; $died['bring_back'] = $dead;
$html = CloudStoreInventoryPanel::render($died, CloudStoreInventoryPanel::SOURCE_SITE, '/admin/admin_backups');
check(strpos($html, 'bring_back_objects') !== false && strpos($html, 'never reported back') !== false, 'the button is back once a run that never reported is taken as dead');
$clean = CloudStoreInventory::summary($none, $held);
$html = CloudStoreInventoryPanel::render($clean, CloudStoreInventoryPanel::SOURCE_SITE, '/admin/admin_backups');
check(strpos($html, 'All present.') !== false && strpos($html, 'alert-warning') === false, 'nothing missing: all present, no warning');
check(!CloudStoreInventoryPanel::has_content(CloudStoreInventory::summary(CloudStoreInventory::blank_record(), array())), 'a site never checked has no panel');
check(CloudStoreInventoryPanel::has_content($clean), 'a checked site has one');

harness_finish();
