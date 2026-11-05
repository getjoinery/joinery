<?php
/** @joinery-test
 * name: ram_store
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * RamStore: shared memory that cannot hold an object
 * (strategy/object_free_window_store.md WP1).
 *
 *  - values round-trip as JSON, keys are filenames from a fixed charset, and
 *    keys() lists names without opening an entry;
 *  - expiry is the file's mtime: an expired read is a miss and removes the
 *    file, touch() extends without a new inode, and never revives or creates;
 *  - a value that is not JSON (a planted serialized object) reads as a miss
 *    and is deleted; an object is refused at set();
 *  - every delete overwrites the entry with zeros first (seen through a hard
 *    link to the same inode);
 *  - writers racing on one key never show a reader half a file;
 *  - the directory must be tmpfs, a real directory, ours, and 0700;
 *  - lifetime-bound entries live in a generation: another generation does
 *    not see them, a plain entry outlives the change, the sweep removes the
 *    generation of an ended process (zeroed) and keeps a live one, and a CLI
 *    process never names a web generation;
 *  - under the harness the store is the run's own directory, never the site's.
 *
 * Run: php tests/unit/ram_store_test.php
 *
 * @version 1.2 - the sweep removes this user's old /dev/shm/vault_window_* markers
 * @version 1.1 - reviewer2 B1-B8: a set() zeroes the value it replaces, a half-zeroed read is a quiet
 *                miss, a value too deep is refused, a generation whose start reads 0 is judged by its
 *                pid alone, and the CLI health row checks every user's directory for the site
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$euid = posix_geteuid();
$dir = RamStore::directory();

/** The file behind a key, for checks that look underneath the store. */
function rs_path($key, $sub = null) {
	return RamStore::directory() . ($sub !== null ? '/' . $sub : '') . '/' . $key;
}

section('The harness names the directory');
check(strpos($dir, '/dev/shm/joinery-' . $euid . '-test-') === 0, 'the store is the run\'s own /dev/shm directory', $dir);
check($dir !== RamStore::defaultDirectory(), 'it is not the site\'s directory', RamStore::defaultDirectory());
$st = RamStore::status();
check($st['ok'] === true, 'the run\'s directory passes the store\'s checks', (string)$st['reason']);
check(sprintf('%04o', fileperms($dir) & 0777) === '0700' && fileowner($dir) === $euid, 'it was made 0700 and is ours');
check(RamStore::mountType($dir) === 'tmpfs', '/dev/shm is tmpfs here');

section('Round trip');
$values = array(
	'int' => 42, 'float' => 1.0, 'string' => 'héllo', 'null' => null, 'false' => false,
	'list' => array(1, 2, 3), 'map' => array('a' => array('b' => 'c'), 'n' => 7),
);
$all = true;
foreach ($values as $name => $v) {
	$ok = null;
	$all = $all && RamStore::set('rt:' . $name, $v, 60) && RamStore::get('rt:' . $name, $ok) === $v && $ok === true;
}
check($all, 'scalars, null, false, lists and maps read back identically, ok true');
$ok = null;
check(RamStore::get('rt:missing', $ok) === null && $ok === false, 'a missing key is null with ok false');
check(RamStore::exists('rt:int') && !RamStore::exists('rt:missing'), 'exists() tells live from missing');
check(trim(file_get_contents(rs_path('rt:map'))) === '{"a":{"b":"c"},"n":7}', 'the file holds the JSON value only');
check(sprintf('%04o', fileperms(rs_path('rt:map')) & 0777) === '0600', 'an entry is 0600');

$k = RamStore::keys('rt:');
sort($k);
$want = array_map(function ($n) { return 'rt:' . $n; }, array_keys($values));
sort($want);
check($k === $want, 'keys() lists the names under a prefix', json_encode($k));
@mkdir($dir . '/gen-000000000000-1-1-c', 0700);
file_put_contents($dir . '/.tmp-deadbeef', 'x');
touch($dir . '/.tmp-deadbeef', time() + 100);
check(RamStore::keys('') === array_values(array_filter(RamStore::keys(''), function ($n) { return $n[0] !== '.' && strpos($n, 'gen-') !== 0; })),
	'keys() never lists a temp file or a generation directory');
@unlink($dir . '/.tmp-deadbeef');
@rmdir($dir . '/gen-000000000000-1-1-c');
check(RamStore::delete('rt:int') && !file_exists(rs_path('rt:int')) && !RamStore::exists('rt:int'), 'delete() removes the entry');

section('Keys are filenames from a fixed charset');
$refused = true;
foreach (array('', '../x', 'a/b', '.hidden', 'gen-1', ':lead', str_repeat('a', 201), "nul\0x", 'sp ace') as $bad) {
	$refused = $refused && !RamStore::set($bad, 1, 60) && RamStore::get($bad) === null;
}
check($refused, 'a slash, a leading dot or colon, gen-, an empty or overlong name, NUL and space are refused');
$platform = array('vaultmeta:abcDEF123:42:user', 'vault:~apk1a2b:7:app', 'fsg.0123abcd', 'messenger:typing:9',
	'joinery_admin_page_level:' . md5('x'));
$accepted = true;
foreach ($platform as $key) {
	$accepted = $accepted && RamStore::set($key, 'v', 60) && RamStore::get($key) === 'v' && RamStore::delete($key);
}
check($accepted, 'every key shape the platform uses is accepted');

section('Only JSON goes in and comes out');
check(!RamStore::set('obj', new stdClass(), 60) && !file_exists(rs_path('obj')), 'an object is refused at set()');
check(!RamStore::set('obj', array('deep' => array(new ArrayObject())), 60), 'an object nested in an array is refused');
check(!RamStore::set('bin', random_bytes(32) . "\xff", 60), 'a binary string is refused (callers base64 it)');
$deep = 'leaf';
for ($i = 0; $i < RamStore::DEPTH + 1; $i++) {
	$deep = array($deep);
}
check(!RamStore::set('deep', $deep, 60) && !file_exists(rs_path('deep')), 'a value nested deeper than DEPTH is refused at set()');
$deep = 'leaf';
for ($i = 0; $i < RamStore::DEPTH; $i++) {
	$deep = array($deep);
}
check(RamStore::set('deep', $deep, 60) && RamStore::get('deep') === $deep, 'one at exactly DEPTH stores and reads back');
file_put_contents(rs_path('planted'), 'O:8:"stdClass":0:{}');
touch(rs_path('planted'), time() + 100);
$ok = null;
check(RamStore::get('planted', $ok) === null && $ok === false && !file_exists(rs_path('planted')),
	'a planted serialized object reads as a miss and is deleted');
file_put_contents(rs_path('zeros'), str_repeat("\0", 16));
touch(rs_path('zeros'), time() + 100);
check(RamStore::get('zeros', $ok) === null && $ok === false, 'an entry being zeroed reads as a miss');
file_put_contents(rs_path('half'), "\0\0\0lo\"}");
touch(rs_path('half'), time() + 100);
check(RamStore::get('half', $ok) === null && $ok === false && file_exists(rs_path('half')),
	'a half-zeroed entry is a quiet miss, left to the delete already under way');

section('Expiry is the mtime');
RamStore::set('exp', 'v', 60);
$m = filemtime(rs_path('exp'));
check($m >= time() + 59 && $m <= time() + 61, 'set() puts the mtime ttl seconds ahead');
$ino = fileinode(rs_path('exp'));
check(RamStore::touch('exp', 500), 'touch() extends a live entry');
clearstatcache();
check(fileinode(rs_path('exp')) === $ino && filemtime(rs_path('exp')) >= time() + 499, 'touch() moves the mtime, same inode');
touch(rs_path('exp'), time() - 1);
check(!RamStore::exists('exp') && !RamStore::touch('exp', 60), 'an expired entry does not exist and touch() never revives it');
check(RamStore::get('exp', $ok) === null && $ok === false && !file_exists(rs_path('exp')), 'an expired read is a miss and removes the file');
check(!RamStore::touch('never', 60) && !file_exists(rs_path('never')), 'touch() on a missing key creates nothing');
RamStore::set('exp2', 'v', 60);
touch(rs_path('exp2'), time() - 1);
check(!in_array('exp2', RamStore::keys('exp'), true), 'keys() leaves out an expired entry');

section('Deletes overwrite with zeros first');
RamStore::set('secret', array('k' => base64_encode(random_bytes(32))), 60);
$size = filesize(rs_path('secret'));
link(rs_path('secret'), $dir . '/.probe');
RamStore::delete('secret');
check(!file_exists(rs_path('secret')) && file_get_contents($dir . '/.probe') === str_repeat("\0", $size),
	'delete() zeroed the inode before unlinking it');
unlink($dir . '/.probe');
RamStore::set('secret2', 'abcdef', 60);
link(rs_path('secret2'), $dir . '/.probe');
touch(rs_path('secret2'), time() - 1);
RamStore::get('secret2');
check(file_get_contents($dir . '/.probe') === str_repeat("\0", 8), 'an expired read zeroes before it unlinks');
unlink($dir . '/.probe');

RamStore::set('replaced', array('k' => 'first-secret-value'), 60);
$size = filesize(rs_path('replaced'));
$held = fopen(rs_path('replaced'), 'rb');
RamStore::set('replaced', array('k' => 'second'), 60);
check(stream_get_contents($held) === str_repeat("\0", $size) && RamStore::get('replaced') === array('k' => 'second'),
	'a set() over a key zeroes the value it replaced');
// A reader that opened the old file reads zeros: it retries and finds the new one.
check(RamStore::get('replaced', $ok) === array('k' => 'second') && $ok, 'a read after a replace reads the new value');
fclose($held);
RamStore::set('linked', 'kept', 60);
link(rs_path('linked'), $dir . '/.probe');
RamStore::set('linked', 'new', 60);
check(file_get_contents($dir . '/.probe') === '"kept"', 'but never a replaced file still linked elsewhere');
unlink($dir . '/.probe');

section('Writers racing on one key');
$scratch = harness_scratch_dir('ram_store');
$child = $scratch . '/writer.php';
file_put_contents($child, '<?php require ' . var_export(PathHelper::getIncludePath('includes/RamStore.php'), true) . ';'
	. ' RamStore::useDirectory($argv[1]);'
	. ' for ($j = 0; $j < 400; $j++) { RamStore::set("race", array("w" => (int)$argv[2], "n" => $j, "pad" => str_repeat("x", 4000)), 60); }');
RamStore::set('race', array('w' => -1, 'n' => -1, 'pad' => str_repeat('x', 4000)), 60);
$procs = array();
for ($i = 0; $i < 4; $i++) {
	$procs[] = proc_open(array(PHP_BINARY, $child, $dir, (string)$i), array(), $pipes);
}
$reads = 0; $bad = 0; $misses = 0;
$deadline = microtime(true) + 20;
do {
	$running = false;
	foreach ($procs as $p) {
		$running = $running || proc_get_status($p)['running'];
	}
	$v = RamStore::get('race', $ok);
	$reads++;
	if (!$ok) {
		$misses++;
	} elseif (!is_array($v) || strlen($v['pad'] ?? '') !== 4000) {
		$bad++;
	}
} while ($running && microtime(true) < $deadline);
foreach ($procs as $p) {
	proc_close($p);
}
$v = RamStore::get('race', $ok);
check($bad === 0 && $misses === 0 && $reads > 10, "every read during four racing writers was a whole value ($reads reads)", "bad=$bad misses=$misses");
check($ok && $v['n'] === 399, 'the last write stands');
check(glob($dir . '/.tmp-*') === array(), 'no temp file is left behind');

section('The directory must be ours, private, and in RAM');
$parent = $dir . '/probes';
mkdir($parent, 0700);
mkdir($parent . '/good', 0700);
check(RamStore::checkDirectory($parent . '/good') === null, 'a 0700 directory of ours on tmpfs passes');
mkdir($parent . '/wide', 0700);
chmod($parent . '/wide', 0755);
check(strpos((string)RamStore::checkDirectory($parent . '/wide'), 'mode 0755') !== false, 'mode 0755 is refused');
symlink($parent . '/good', $parent . '/link');
check(strpos((string)RamStore::checkDirectory($parent . '/link'), 'symbolic link') !== false, 'a symlink is refused');
check(strpos((string)RamStore::checkDirectory($parent . '/good', $euid + 1), 'owned by uid ' . $euid) !== false,
	'a directory owned by another account is refused');
touch($parent . '/plain');
check(strpos((string)RamStore::checkDirectory($parent . '/plain'), 'not a directory') !== false, 'a file is refused');
$mounts = $scratch . '/mounts';
file_put_contents($mounts, "/dev/sda1 / ext4 rw 0 0\ntmpfs /dev/shm tmpfs rw 0 0\n/dev/sdb1 /dev/shm/disk ext4 rw 0 0\n");
check(RamStore::mountType('/dev/shm/x', $mounts) === 'tmpfs' && RamStore::mountType('/dev/shm/disk/x', $mounts) === 'ext4'
	&& RamStore::mountType('/dev/shmx', $mounts) === 'ext4', 'the deepest mount holding a path decides its type');
check(strpos((string)RamStore::checkDirectory('/dev/shm/disk/x', null, $mounts), 'on ext4, not tmpfs') !== false, 'a directory not on tmpfs is refused');

$disk = $scratch . '/store';
RamStore::useDirectory($disk);
$st = RamStore::status();
check(RamStore::mountType($disk) !== 'tmpfs' ? (!$st['ok'] && strpos($st['reason'], 'not tmpfs') !== false && !RamStore::set('x', 1, 60) && !is_dir($disk)) : true,
	'a store directory on disk is refused: no store, nothing made', (string)$st['reason']);
RamStore::useDirectory($parent . '/wide');
check(!RamStore::set('x', 1, 60) && !file_exists($parent . '/wide/x') && RamStore::get('x') === null, 'a refused directory stores and reads nothing');
RamStore::useDirectory($dir);
harness_remove_ram_store($parent);

section('Lifetime-bound entries');
$mine = RamStore::generation();
check(preg_match('/^gen-[0-9a-f]{12}-' . getmypid() . '-\d+-c$/', $mine) === 1, 'a CLI process\'s generation is named for itself', $mine);
$boot = substr(str_replace('-', '', trim(file_get_contents('/proc/sys/kernel/random/boot_id'))), 0, 12);
$master = posix_getppid();
$web = 'gen-' . $boot . '-' . $master . '-' . RamStore::procStartTime($master) . '-1793800000';
check($mine !== $web, 'it never names a web generation');

RamStore::set('life', 'L', 60, RamStore::LIFETIME);
RamStore::set('plain', 'P', 60);
check(RamStore::get('life', $ok, RamStore::LIFETIME) === 'L' && is_file($dir . '/' . $mine . '/life'), 'a lifetime entry lives in this process\'s generation');
check(RamStore::get('life') === null, 'it is not a plain entry');
check(RamStore::keys('lif', RamStore::LIFETIME) === array('life') && RamStore::keys('lif') === array(), 'keys() lists each kind apart');

RamStore::$generation_override = $web;
RamStore::set('wlife', 'W', 60, RamStore::LIFETIME);
check(RamStore::get('life', $ok, RamStore::LIFETIME) === null, 'another generation does not see it');
check(RamStore::get('plain') === 'P', 'a plain entry outlives the change of generation');
RamStore::$generation_override = null;
check(RamStore::get('wlife', $ok, RamStore::LIFETIME) === null, 'the CLI finds nothing of a web generation');

// A process that has ended: a child that exits at once.
$dead_pid = (int)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo getmypid();'));
$dead = 'gen-' . $boot . '-' . $dead_pid . '-12345-c';
$old_boot = 'gen-ffffffffffff-' . getmypid() . '-' . RamStore::procStartTime(getmypid()) . '-c';
$reused = 'gen-' . $boot . '-' . getmypid() . '-1-c'; // our pid, another start time: a reused pid
$dead0 = 'gen-' . $boot . '-' . $dead_pid . '-0-c'; // a start its process could not read
$live0 = 'gen-' . $boot . '-' . getmypid() . '-0-c';
foreach (array($dead, $old_boot, $reused, $dead0, $live0) as $g) {
	mkdir($dir . '/' . $g, 0700);
	file_put_contents($dir . '/' . $g . '/vault:s:1:user', '"key-bytes"');
	touch($dir . '/' . $g . '/vault:s:1:user', time() + 600);
}
link($dir . '/' . $dead . '/vault:s:1:user', $dir . '/.probe');
touch($dir . '/.sweep', time() - 3600);
RamStore::sweep();
check(!is_dir($dir . '/' . $dead) && !is_dir($dir . '/' . $old_boot) && !is_dir($dir . '/' . $reused),
	'the sweep removes the generation of an ended process, a past boot, and a reused pid');
check(!is_dir($dir . '/' . $dead0) && is_dir($dir . '/' . $live0), 'a generation whose start reads 0 is judged by whether its pid is alive');
check(file_get_contents($dir . '/.probe') === str_repeat("\0", 11), 'and zeroes its live entries before removing them');
unlink($dir . '/.probe');
check(is_dir($dir . '/' . $mine) && RamStore::get('life', $ok, RamStore::LIFETIME) === 'L', 'this process\'s generation stays');
check(is_dir($dir . '/' . $web) && RamStore::get('plain') === 'P', 'a live master\'s generation stays, seen from the CLI, and plain entries stay');

// Seen from a web worker: its own master after a reload has a new OPcache segment.
$me = getmypid();
$start = RamStore::procStartTime($me);
RamStore::$generation_override = 'gen-' . $boot . '-' . $me . '-' . $start . '-222';
foreach (array('-111', '-c') as $tail) {
	@mkdir($dir . '/gen-' . $boot . '-' . $me . '-' . $start . $tail, 0700); // -c is this process's own
}
RamStore::sweep();
check(!is_dir($dir . '/gen-' . $boot . '-' . $me . '-' . $start . '-111'), 'a worker\'s sweep removes its own master\'s generation from before a reload');
check(is_dir($dir . '/gen-' . $boot . '-' . $me . '-' . $start . '-c'), 'and leaves a live CLI process\'s generation alone');
// A worker that could not read its master's stat names start 0; a reload's leftover does too.
RamStore::$generation_override = 'gen-' . $boot . '-' . $me . '-0-444';
@mkdir($dir . '/gen-' . $boot . '-' . $me . '-0-333', 0700);
RamStore::sweep();
check(!is_dir($dir . '/gen-' . $boot . '-' . $me . '-0-333'), 'with start 0 on both, the segment still decides a reload');
check(RamStore::lifetimeProblem() === null, 'the CLI has no lifetime problem to report');
RamStore::$generation_override = null;

RamStore::set('gone', 'v', 60);
touch(rs_path('gone'), time() - 5);
touch($dir . '/.sweep', time() - 3600);
file_put_contents($dir . '/.tmp-fresh', 'x');
RamStore::sweep();
check(!file_exists(rs_path('gone')) && file_exists($dir . '/.tmp-fresh'), 'the sweep removes an expired entry nobody read and leaves a write in progress');
check(filemtime($dir . '/.sweep') >= time() - 1, 'it stamps its marker');

section('The sweep removes the old open-window markers this user left in /dev/shm');
$shm = $dir . '/fake-shm';
@mkdir($shm, 0700);
file_put_contents($shm . '/vault_window_42_user', '');
file_put_contents($shm . '/vault_window_42_drive', '');
file_put_contents($shm . '/vault_window_notes.txt', 'kept');
file_put_contents($shm . '/other_file', 'kept');
mkdir($shm . '/vault_window_7_user');
$sweep_markers = (new ReflectionClass('RamStore'))->getMethod('sweepOldMarkers');
check($sweep_markers->invoke(null, $shm) === 2 && !file_exists($shm . '/vault_window_42_user') && !file_exists($shm . '/vault_window_42_drive'),
	'both of this user\'s markers are removed');
check(file_exists($shm . '/vault_window_notes.txt') && file_exists($shm . '/other_file') && is_dir($shm . '/vault_window_7_user'),
	'a name that is not a marker, another file and a directory are left alone');
rmdir($shm . '/vault_window_7_user');
array_map('unlink', glob($shm . '/*'));
rmdir($shm);

section('The CLI health row checks the site\'s directories');
$h = new ReflectionClass('RamStore');
$m = $h->getMethod('siteHash');
$site_hash = $m->invoke(null);
$foreign = '/dev/shm/joinery-' . ($euid + 1) . '-' . $site_hash; // claims another uid, but is ours
@mkdir($foreign, 0700);
check(isset(RamStore::siteDirectories()[$foreign]), 'siteDirectories() finds a directory under the site\'s name');
$row = VaultHealth::checkRamStore();
check($row['state'] === 'unmet' && strpos($row['reason'], 'owned by uid ' . $euid . ', not ' . ($euid + 1)) !== false,
	'a directory the web user\'s name points at, owned by someone else, is unmet', $row['reason']);
rmdir($foreign);
$row = VaultHealth::checkRamStore();
check($row['state'] === 'verified', 'with only good directories (or none) the row is verified', $row['reason']);

section('Reading a process\'s start time');
$proc = $scratch . '/proc';
mkdir($proc . '/77', 0700, true);
file_put_contents($proc . '/77/stat', '77 (php-fpm: pool (www) x) S 1 77 77 0 -1 4194560 100 0 0 0 0 0 0 0 20 0 1 0 987654 0 0');
check(RamStore::procStartTime(77, $proc) === '987654', 'field 22, after a command name holding spaces and parentheses');
check(RamStore::procStartTime(78, $proc) === null && RamStore::procStartTime(0, $proc) === null, 'no process, no start time');
check(RamStore::procStartTime(getmypid()) !== null, 'this process\'s own start time reads');

harness_finish();
