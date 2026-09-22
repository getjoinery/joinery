<?php
/** @joinery-test
 * name: backup_objects
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The object store's rules, each pure and asserted directly
 * (specs/backup_offloaded_files.md):
 *
 *   - the PHP cipher and `openssl enc` open each other's output, at every
 *     awkward length, and a wrong key is refused
 *   - epoch decisions: none, kept, recovery rotated, site key cannot open
 *   - backup storage picture from a listing; the held set from an index
 *   - the index: every cloud blob, stored or not, ciphertext facts only
 *   - the exclude list names the original and every variant, in both
 *     placements, and nothing outside the project
 *   - the release rule across zero, one and two enabled profiles, and a
 *     profile with no held.json at all
 *   - the `objects` kind through BackupChain, BackupStaging and the verifier's
 *     disk arithmetic; the standalone index name beside its archive
 *
 * The run itself is backup_objects_run_test.php (db); the tick is
 * tests/cloud_storage/offload_release_test.php (db).
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));

$work = harness_scratch_dir('backup_objects');
foreach (glob($work . '/*') ?: array() as $p) { if (is_file($p)) { @unlink($p); } }

// ── Cipher ──────────────────────────────────────────────────────────────────
section('The PHP cipher and openssl enc open each other\'s output');

$key = base64_encode(random_bytes(32));
file_put_contents($work . '/key', $key);
@chmod($work . '/key', 0600);
$openssl_ok = (exec('command -v openssl') !== '');

foreach (array(0, 15, 16, 17, 4096, 1048576, 1048583) as $n) {
	$plain = $work . '/plain';
	file_put_contents($plain, $n ? random_bytes($n) : '');
	$enc = $work . '/obj.enc';
	$r = BackupObjects::encrypt_file($plain, $enc, $key);
	check(is_file($enc) && substr(file_get_contents($enc, false, null, 0, 8), 0, 8) === 'Salted__',
		$n . ' bytes: the object starts with the Salted__ header');
	check((int)$r['bytes'] === (int)filesize($enc) && $r['sha256'] === hash_file('sha256', $enc),
		$n . ' bytes: encrypt_file() reports the ciphertext\'s size and hash', $r['bytes'] . '/' . filesize($enc));
	$expected = 16 + (intdiv($n, 16) + 1) * 16;
	check((int)$r['bytes'] === $expected, $n . ' bytes: PKCS7 length is header + padded blocks', $r['bytes'] . ' vs ' . $expected);

	$back = $work . '/back';
	$m = BackupObjects::decrypt_file($enc, $back, $key);
	check($m === $n && md5_file($back) === md5_file($plain), $n . ' bytes: decrypt_file() gives the plaintext back');

	if ($openssl_ok) {
		$out = array(); $rc = 0;
		exec('openssl enc -d -aes-256-cbc -pbkdf2 -pass file:' . escapeshellarg($work . '/key')
			. ' -in ' . escapeshellarg($enc) . ' -out ' . escapeshellarg($work . '/ossl') . ' 2>&1', $out, $rc);
		check($rc === 0 && md5_file($work . '/ossl') === md5_file($plain),
			$n . ' bytes: `openssl enc -d -aes-256-cbc -pbkdf2` opens what PHP wrote', implode(' ', $out));

		$out = array(); $rc = 0;
		exec('openssl enc -aes-256-cbc -salt -pbkdf2 -pass file:' . escapeshellarg($work . '/key')
			. ' -in ' . escapeshellarg($plain) . ' -out ' . escapeshellarg($work . '/ossl.enc') . ' 2>&1', $out, $rc);
		$m2 = BackupObjects::decrypt_file($work . '/ossl.enc', $work . '/back2', $key);
		check($rc === 0 && $m2 === $n && md5_file($work . '/back2') === md5_file($plain),
			$n . ' bytes: PHP opens what `openssl enc` wrote');
	} else {
		harness_skip($n . ' bytes: openssl round trip', 'no openssl binary');
	}
}

// CBC has no integrity check: a wrong key fails the final block's padding
// about 255 times in 256, and on the rest decrypts to garbage with valid
// padding. So the property is not "always throws" — it is that a wrong key
// never hands back the plaintext, and a refusal leaves no file behind. The
// size and hash checks in the restore and the verifier catch the 1 in 256.
$threw = '';
try { BackupObjects::decrypt_file($work . '/obj.enc', $work . '/x', 'not-the-key'); }
catch (BackupObjectsException $e) { $threw = $e->getMessage(); }
check($threw !== '' ? !is_file($work . '/x') : md5_file($work . '/x') !== md5_file($work . '/plain'),
	'a wrong key never yields the plaintext, and a refusal leaves no output', $threw);

file_put_contents($work . '/trunc.enc', substr(file_get_contents($work . '/obj.enc'), 0, -5));
$threw = '';
try { BackupObjects::decrypt_file($work . '/trunc.enc', $work . '/x', $key); }
catch (BackupObjectsException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'truncated') !== false, 'a truncated object is named as such', $threw);

file_put_contents($work . '/nothdr.enc', random_bytes(64));
$threw = '';
try { BackupObjects::decrypt_file($work . '/nothdr.enc', $work . '/x', $key); }
catch (BackupObjectsException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'Salted__') !== false, 'a file without the header is not an object', $threw);

// ── Epochs ──────────────────────────────────────────────────────────────────
section('Epoch decisions');

check(preg_match('/^epoch-\d{8}_\d{6}$/', BackupObjects::epoch_id()) === 1, 'an epoch id is epoch-YYYYMMDD_HHMMSS', BackupObjects::epoch_id());
check(BackupObjects::epoch_id('2026-09-01 00:00:00') === 'epoch-20260901_000000', 'and reads the UTC time it was given');

$fpr_a = str_repeat('a', 64);
$fpr_b = str_repeat('b', 64);
$stored = array('id' => 'epoch-20260901_000000', 'envelope' => array(
	'recipients' => array(array('kind' => 'recovery', 'fingerprint' => $fpr_a), array('kind' => 'site', 'fingerprint' => 'x'))));

check(BackupObjects::epoch_decision(null, $fpr_a, false) === 'none', 'no epoch yet → none');
check(BackupObjects::epoch_decision(array('id' => 'e', 'envelope' => array()), $fpr_a, true) === 'none', 'a record without recipients is no epoch');
check(BackupObjects::epoch_decision($stored, $fpr_a, true) === '', 'same recovery key, site key opens → kept');
check(BackupObjects::epoch_decision($stored, $fpr_b, true) === 'recovery_rotated', 'a different recovery fingerprint → recovery_rotated');
check(BackupObjects::epoch_decision($stored, $fpr_a, false) === 'envelope_unopenable', 'the site key cannot open it → envelope_unopenable');
check(BackupObjects::epoch_decision($stored, '', false) === 'envelope_unopenable', 'with no current fingerprint the rotation test is skipped, not failed');

// ── Shelf picture and held set ─────────────────────────────────────────────
section('Backup storage picture from a listing');

$prefix = 'joinery-backups/site-a/site/objects/';
$listing = array(
	array('key' => $prefix . 'epoch-20260901_000000/envelope.json', 'size' => 900, 'last_modified' => '2026-09-01T00:00:00.000Z'),
	array('key' => $prefix . 'epoch-20260901_000000/beach.jpg.enc', 'size' => 4194352, 'last_modified' => '2026-09-02T14:15:00.000Z'),
	array('key' => $prefix . 'epoch-20260915_000000/dune.png.enc', 'size' => 1200, 'last_modified' => '2026-09-16T00:00:00.000Z'),
	array('key' => $prefix . 'epoch-20260915_000000/envelope.json', 'size' => 900, 'last_modified' => ''),
	array('key' => $prefix . 'tmp/stray.enc', 'size' => 1, 'last_modified' => ''),
	array('key' => $prefix . 'epoch-20260915_000000/notes.txt', 'size' => 1, 'last_modified' => ''),
	array('key' => 'joinery-backups/site-a/site/chain-20260901_030000/files-0000.tar.gz.enc', 'size' => 5, 'last_modified' => ''),
);
$shelf = BackupObjects::parse_listing($listing, $prefix);
check(array_keys($shelf['objects']) === array('beach.jpg', 'dune.png'), 'objects are keyed by bare name', json_encode(array_keys($shelf['objects'])));
check($shelf['objects']['beach.jpg']['epoch'] === 'epoch-20260901_000000' && (int)$shelf['objects']['beach.jpg']['object_bytes'] === 4194352,
	'each carries its epoch and encrypted size');
check(array_keys($shelf['envelopes']) === array('epoch-20260901_000000', 'epoch-20260915_000000'), 'envelopes are listed by epoch');
check(!isset($shelf['objects']['stray']) && !isset($shelf['objects']['notes.txt']), 'anything not objects/{epoch}/{name}.enc is ignored');

$index = array('version' => 1, 'objects' => array(
	array('name' => 'beach.jpg', 'epoch' => 'epoch-20260901_000000', 'object_bytes' => 4194352, 'object_sha256' => str_repeat('1', 64), 'stored' => true),
	array('name' => 'waiting.jpg', 'epoch' => '', 'object_bytes' => 0, 'object_sha256' => '', 'stored' => false),
));
$held = BackupObjects::held_from_index($index);
check(array_keys($held) === array('beach.jpg'), 'held_from_index() keeps only what the index marks stored', json_encode(array_keys($held)));
check($held['beach.jpg']['object_sha256'] === str_repeat('1', 64), 'with its hash');
check(BackupObjects::held_from_index(null) === array(), 'no index means nothing held');

// ── Index ───────────────────────────────────────────────────────────────────
section('The index says what was live and what backup storage held');

$plan = array('profile' => 'site', 'output_dir' => $work . '/site');
$objects = array(
	array('id' => 1, 'name' => 'beach.jpg', 'original' => '/x/beach.jpg', 'paths' => array()),
	array('id' => 2, 'name' => 'new.jpg', 'original' => '/x/new.jpg', 'paths' => array()),
	array('id' => 3, 'name' => 'waiting.jpg', 'original' => '/x/waiting.jpg', 'paths' => array()),
);
$stored = array('new.jpg' => array('epoch' => 'epoch-20260915_000000', 'object_bytes' => 1200, 'object_sha256' => str_repeat('2', 64)));
$idx = BackupObjects::build_index($plan, 'chain-20260920_030000/3', $objects, $held, $stored);
check((int)$idx['version'] === 1 && $idx['profile'] === 'site' && $idx['run'] === 'chain-20260920_030000/3', 'version, profile and run label');
check(count($idx['objects']) === 3, 'every cloud blob has an entry, stored or not');
$by = array(); foreach ($idx['objects'] as $e) { $by[$e['name']] = $e; }
check($by['beach.jpg']['stored'] === true && $by['beach.jpg']['object_sha256'] === str_repeat('1', 64), 'a held object: stored, with the held hash');
check($by['new.jpg']['stored'] === true && $by['new.jpg']['epoch'] === 'epoch-20260915_000000', 'an object stored this run: stored, in this run\'s epoch');
check($by['waiting.jpg']['stored'] === false && $by['waiting.jpg']['epoch'] === '' && $by['waiting.jpg']['object_sha256'] === '', 'a waiting object: not stored, no epoch, no hash');
check($idx['epochs'] === array('epoch-20260901_000000', 'epoch-20260915_000000'), 'the epochs the entries name, sorted', json_encode($idx['epochs']));
$keys = array_keys($by['beach.jpg']);
sort($keys);
check($keys === array('epoch', 'name', 'object_bytes', 'object_sha256', 'stored'), 'an entry carries ciphertext facts only — no plaintext size, hash or MIME type', json_encode($keys));

@mkdir($work . '/site', 0700, true);
$art = BackupObjects::write_index($idx, $work . '/site/objects-0003.json.gz');
check($art['kind'] === 'objects' && $art['name'] === 'objects-0003.json.gz' && $art['bytes'] === filesize($work . '/site/objects-0003.json.gz')
	&& $art['sha256'] === hash_file('sha256', $work . '/site/objects-0003.json.gz'), 'write_index() describes the artifact for the manifest');
$read = BackupObjects::read_index_file($work . '/site/objects-0003.json.gz');
check($read['objects'] === $idx['objects'] && $read['epochs'] === $idx['epochs'], 'and read_index_file() reads it back');
file_put_contents($work . '/site/bad.json.gz', 'not gzip');
$threw = '';
try { BackupObjects::read_index_file($work . '/site/bad.json.gz'); } catch (BackupObjectsException $e) { $threw = $e->getMessage(); }
check($threw !== '', 'a file that is not a gzipped index is refused', $threw);
check(BackupObjects::index_entries($read) === BackupObjects::held_from_index($read), 'index_entries() is the held set of an index');

// ── Exclude list ────────────────────────────────────────────────────────────
section('The exclude list names original and every variant, in both placements');

$root = '/var/www/html/site';
$objs = array(array('id' => 1, 'name' => 'beach.jpg', 'original' => $root . '/static_files/uploads/beach.jpg', 'paths' => array(
	$root . '/static_files/uploads/beach.jpg',
	$root . '/static_files/uploads/thumb/beach.jpg',
	$root . '/static_files/uploads/medium/beach.jpg',
	$root . '/uploads/beach.jpg',
	$root . '/uploads/thumb/beach.jpg',
	$root . '/uploads/medium/beach.jpg',
	'/elsewhere/beach.jpg',
)));
$lines = BackupObjects::exclude_lines($objs, $root);
check($lines === array('static_files/uploads/beach.jpg', 'static_files/uploads/thumb/beach.jpg', 'static_files/uploads/medium/beach.jpg',
	'uploads/beach.jpg', 'uploads/thumb/beach.jpg', 'uploads/medium/beach.jpg'),
	'paths relative to the project, original and variants, fast and restricted; a path outside the project is left out', json_encode($lines));
check(BackupObjects::exclude_lines(array(), $root) === array(), 'no objects, no lines');
$ex = BackupObjects::write_exclude_file($plan, $objs, $root);
check($ex !== '' && is_file($ex) && file_get_contents($ex) === implode("\n", $lines) . "\n", 'write_exclude_file() writes one path per line');
@unlink($ex);
check(BackupObjects::write_exclude_file($plan, array(), $root) === '', 'and writes nothing when there is nothing to exclude');

// ── Release rule ────────────────────────────────────────────────────────────
section('Local bytes go only once every enabled backup storage holds the object');

$site_holds = array('beach.jpg' => array('epoch' => 'e', 'object_bytes' => 1, 'object_sha256' => 'x'));
$mgr_holds  = array('beach.jpg' => array('epoch' => 'e', 'object_bytes' => 1, 'object_sha256' => 'x'), 'other.jpg' => array());
check(BackupObjects::may_release(array(), array(), 'beach.jpg') === true, 'no profile enabled → release at offload, as today');
check(BackupObjects::may_release(array('site' => $site_holds), array('site'), 'beach.jpg') === true, 'site only, site holds it → release');
check(BackupObjects::may_release(array('site' => $site_holds), array('site'), 'new.jpg') === false, 'site only, site lacks it → hold');
check(BackupObjects::may_release(array('site' => $site_holds, 'manager' => $mgr_holds), array('site', 'manager'), 'beach.jpg') === true, 'both enabled, both hold → release');
check(BackupObjects::may_release(array('site' => $site_holds, 'manager' => array()), array('site', 'manager'), 'beach.jpg') === false, 'both enabled, manager lacks it → hold');
check(BackupObjects::may_release(array('site' => $site_holds, 'manager' => null), array('site', 'manager'), 'beach.jpg') === false, 'a profile with no held.json holds everything');
check(BackupObjects::may_release(array('manager' => $mgr_holds), array('manager'), 'other.jpg') === true, 'manager only, manager holds → release (the site profile\'s set is not consulted)');
check(BackupObjects::may_release(array(), array('manager'), 'beach.jpg') === false, 'an enabled profile missing from the sets holds');

// held.json round trip, and the tick's add under the run's lock
$p2 = array('profile' => 'manager', 'output_dir' => $work . '/manager');
check(BackupObjects::read_held($p2) === null, 'no held.json → null, not an empty set');
BackupObjects::write_held($p2, $site_holds);
check(BackupObjects::read_held($p2) === $site_holds, 'write_held()/read_held() round trip');
BackupObjects::held_add($p2, 'tick.jpg', array('epoch' => 'e2', 'bytes' => 77, 'sha256' => str_repeat('3', 64)));
$after = BackupObjects::read_held($p2);
check(isset($after['beach.jpg']) && $after['tick.jpg'] === array('epoch' => 'e2', 'object_bytes' => 77, 'object_sha256' => str_repeat('3', 64)),
	'held_add() adds one entry and keeps the rest', json_encode($after));
check(BackupObjects::held_path_for('manager', $work) === $work . '/manager/objects/held.json'
	&& BackupObjects::held_path_for('site', $work) === $work . '/objects/held.json', 'held_path_for() follows the profile\'s output directory');
$sets = BackupObjects::held_sets(array('site', 'manager'), $work);
check($sets['site'] === null && isset($sets['manager']['tick.jpg']), 'held_sets() reads each enabled profile\'s file, null where there is none');

// A run rewrites the file from its own picture of backup storage, but the tick
// keeps storing while the run is going: an entry the file gained since the
// run read it survives the rewrite; one the run saw and no longer holds goes.
$seen = array_keys($after);
BackupObjects::held_add($p2, 'during.jpg', array('epoch' => 'e2', 'bytes' => 5, 'sha256' => str_repeat('4', 64)));
BackupObjects::write_held($p2, array('kept.jpg' => $site_holds['beach.jpg']), $seen);
$rewritten = BackupObjects::read_held($p2);
check(array_keys($rewritten) === array('during.jpg', 'kept.jpg'),
	'write_held() keeps what the tick added since the run read the file and drops what the run saw and no longer holds', json_encode(array_keys($rewritten)));
BackupObjects::write_held($p2, $after, array_keys($rewritten));
check(BackupObjects::read_held($p2) === $after, 'and a rewrite that saw everything is exact');

// release_waiting over real files
@mkdir($work . '/tree/static_files/uploads/thumb', 0700, true);
file_put_contents($work . '/tree/static_files/uploads/tick.jpg', 'a');
file_put_contents($work . '/tree/static_files/uploads/thumb/tick.jpg', 'b');
file_put_contents($work . '/tree/static_files/uploads/wait.jpg', 'c');
$objs2 = array(
	array('id' => 1, 'name' => 'tick.jpg', 'original' => $work . '/tree/static_files/uploads/tick.jpg',
		'paths' => array($work . '/tree/static_files/uploads/tick.jpg', $work . '/tree/static_files/uploads/thumb/tick.jpg')),
	array('id' => 2, 'name' => 'wait.jpg', 'original' => $work . '/tree/static_files/uploads/wait.jpg',
		'paths' => array($work . '/tree/static_files/uploads/wait.jpg')),
	array('id' => 3, 'name' => 'gone.jpg', 'original' => $work . '/tree/static_files/uploads/gone.jpg',
		'paths' => array($work . '/tree/static_files/uploads/gone.jpg')),
);
$released = BackupObjects::release_waiting($objs2, array('manager'), $work);
check($released === 1, 'release_waiting() releases the one blob the manager holds', (string)$released);
check(!is_file($work . '/tree/static_files/uploads/tick.jpg') && !is_file($work . '/tree/static_files/uploads/thumb/tick.jpg'), 'original and variant are both gone');
check(is_file($work . '/tree/static_files/uploads/wait.jpg'), 'the blob no backup storage holds keeps its bytes');
check(BackupObjects::release_waiting($objs2, array(), $work) === 1, 'with no profile enabled everything with bytes is released');

// ── The objects kind through the chain, staging and the verifier ────────────
section('The objects kind through chain, staging and verifier');

check(in_array('objects', BackupChain::KINDS, true), 'objects is a chain artifact kind');
check(BackupChain::artifact_name('objects', 3) === 'objects-0003.json.gz', 'the index is objects-0003.json.gz', BackupChain::artifact_name('objects', 3));
check(BackupChain::artifact_name('objects', 3, false) === 'objects-0003.json.gz', 'and is never .enc — it is plain, like the manifest');
check(BackupChain::artifact_name('files', 3) === 'files-0003.tar.gz.enc' && BackupChain::artifact_name('db', 3) === 'db-0003.sql.gz.enc', 'the other kinds are unchanged');

$m = BackupChain::start('chain-20260920_030000', 'site-a', array('version' => 1, 'recipients' => array()));
$m = BackupChain::add_run($m, 0, 0, array(
	'files'   => array('name' => 'files-0000.tar.gz.enc', 'bytes' => 10, 'sha256' => 'f'),
	'db'      => array('name' => 'db-0000.sql.gz.enc', 'bytes' => 5, 'sha256' => 'd'),
	'objects' => array('name' => 'objects-0000.json.gz', 'bytes' => 3, 'sha256' => 'o', 'path' => '/ignored'),
));
$rp = BackupChain::restore_plan($m, 0);
check($rp['objects'] === array('name' => 'objects-0000.json.gz', 'bytes' => 3, 'sha256' => 'o'), 'restore_plan() returns the run\'s index', json_encode($rp['objects']));
check(BackupStaging::wanted($rp) === array('files-0000.tar.gz.enc', 'db-0000.sql.gz.enc', 'objects-0000.json.gz'), 'BackupStaging::wanted() lists it after the dump', json_encode(BackupStaging::wanted($rp)));
$m2 = BackupChain::add_run($m, 1, 1, array('files' => array('name' => 'files-0001.tar.gz.enc', 'bytes' => 1, 'sha256' => 'g')));
$rp1 = BackupChain::restore_plan($m2, 1);
check($rp1['objects'] === null, 'a run without an index says so');
check(in_array('joinery-backups/site-a/site/chain-20260920_030000/objects-0000.json.gz', BackupChain::object_keys($m2, 'joinery-backups', 'site-a', 'site'), true),
	'object_keys() deletes the index with its chain');
check(BackupVerifier::disk_needed($m, 0, BackupVerifier::LEVEL_READ) === 18, 'disk_needed() counts the index with the set', (string)BackupVerifier::disk_needed($m, 0, BackupVerifier::LEVEL_READ));

section('A standalone index is named beside its archive');
check(BackupNaming::index_for_archive('site-20260920_031500.tar.gz.enc') === 'site-20260920_031500.objects.json.gz', 'index_for_archive() keeps the stamp and drops the archive suffix');
check(BackupNaming::index_for_archive('site-20260920_031500.tar.gz') === 'site-20260920_031500.objects.json.gz', 'plaintext archives too');
check(BackupNaming::is_index('site-20260920_031500.objects.json.gz') && !BackupNaming::is_index('site-20260920_031500.tar.gz.enc'), 'is_index() recognises it');
check(BackupNaming::archive_stem_for_index('site-20260920_031500.objects.json.gz') === 'site-20260920_031500', 'archive_stem_for_index() maps it back');
check(!BackupNaming::is_backup('site-20260920_031500.objects.json.gz') && BackupNaming::restore_type('site-20260920_031500.objects.json.gz') === '',
	'an index is not a backup artifact and gets no restore button');

section('The store budget is a constant on the runner');
check(BackupRunner::OBJECT_STORE_BUDGET_BYTES === 2147483648 && BackupRunner::OBJECT_STORE_BUDGET_SECONDS === 1200, '2 GB or 20 minutes');

exec('rm -rf ' . escapeshellarg($work . '/site') . ' ' . escapeshellarg($work . '/manager') . ' ' . escapeshellarg($work . '/tree'));
foreach (glob($work . '/*') ?: array() as $p) { if (is_file($p)) { @unlink($p); } }

harness_finish();
