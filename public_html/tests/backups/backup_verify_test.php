<?php
/** @joinery-test
 * name: backup_verify
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Backup verification: the parts that decide things, asserted directly.
 *
 * The end-to-end proof (real tar, real openssl, a real throwaway database) is
 * backup_verify_gate.sh. What is here is the logic around it that a wrong
 * answer would quietly break:
 *
 *   * the contract a verify prints and the management node reads back must
 *     round-trip — a counter lost in translation is a card that lies
 *   * the disk arithmetic decides whether a node downloads gigabytes onto a
 *     disk that cannot hold them
 *   * the artifact plan for run N is what a restore would apply, in order
 *   * the local sweep must take a verify directory a killed process left, and
 *     must leave one a verify is still using
 *   * the history row is the authority for "verified", so the stamp has to land
 *     on the right run
 *
 * Run: php tests/backups/backup_verify_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

// A synthetic chain: a full, two incrementals, a dump and metadata each run.
$manifest = array(
	'version'  => 1,
	'chain_id' => 'chain-20260901_040000',
	'slug'     => 'testsite',
	'created'  => '2026-09-01T04:00:00Z',
	'updated'  => '2026-09-03T04:00:00Z',
	'envelope' => array('version' => 1, 'artifact' => 'chain', 'recipients' => array()),
	'runs'     => array(
		array('seq' => 0, 'level' => 0, 'time' => '2026-09-01T04:00:00Z', 'artifacts' => array(
			'files' => array('name' => 'files-0000.tar.gz.enc', 'bytes' => 1000, 'sha256' => str_repeat('a', 64)),
			'db'    => array('name' => 'db-0000.sql.gz.enc',    'bytes' => 100,  'sha256' => str_repeat('b', 64)),
			'meta'  => array('name' => 'meta-0000.tar.gz.enc',  'bytes' => 10,   'sha256' => str_repeat('c', 64)),
		)),
		array('seq' => 1, 'level' => 1, 'time' => '2026-09-02T04:00:00Z', 'artifacts' => array(
			'files' => array('name' => 'files-0001.tar.gz.enc', 'bytes' => 200, 'sha256' => str_repeat('d', 64)),
			'db'    => array('name' => 'db-0001.sql.gz.enc',    'bytes' => 110, 'sha256' => str_repeat('e', 64)),
			'meta'  => array('name' => 'meta-0001.tar.gz.enc',  'bytes' => 11,  'sha256' => str_repeat('f', 64)),
		)),
		array('seq' => 2, 'level' => 1, 'time' => '2026-09-03T04:00:00Z', 'artifacts' => array(
			'files' => array('name' => 'files-0002.tar.gz.enc', 'bytes' => 300, 'sha256' => str_repeat('1', 64)),
			'db'    => array('name' => 'db-0002.sql.gz.enc',    'bytes' => 120, 'sha256' => str_repeat('2', 64)),
			'meta'  => array('name' => 'meta-0002.tar.gz.enc',  'bytes' => 12,  'sha256' => str_repeat('3', 64)),
		)),
	),
);

// ── The contract ────────────────────────────────────────────────────────────
section('The contract round-trips');

$pass = array(
	'result' => 'pass', 'level' => 3, 'run' => 'chain-20260901_040000/2', 'run_time' => '2026-09-03 04:00:00',
	'artifacts' => 5, 'bytes' => 1632, 'files' => 1842, 'tables' => 214,
	'rows' => array('usr_users' => 12, 'vev_visitor_events' => 90210, 'eml_email_log' => 4001),
	'duration' => 187, 'reason' => '',
);
$text = BackupVerifier::format_contract($pass);
check(preg_match('/^VERIFY_RESULT=pass$/m', $text) === 1, 'a pass prints its result line');
check(strpos($text, 'VERIFY_REASON=') === false, 'and no reason line — a pass has none');
check(preg_match('/^VERIFY_ROWS=usr_users:12,vev_visitor_events:90210,eml_email_log:4001$/m', $text) === 1,
	'row counts are one line, table:count pairs', $text);
$back = BackupVerifier::parse_contract($text);
foreach (array('result', 'level', 'run', 'run_time', 'artifacts', 'bytes', 'files', 'tables', 'duration') as $k) {
	check(($back[$k] ?? null) === $pass[$k], "'$k' survives the round trip", var_export($back[$k] ?? null, true));
}
check($back['rows'] === $pass['rows'], 'the row counts come back as a map with integer counts');
check(!array_key_exists('reason', $back), 'no reason was printed, so none is read');
check(strpos($text, "\n") !== false && substr_count(trim($text), "\n") === 11,
	'one key per line, nothing else', $text);

$fail = array('result' => 'fail', 'level' => 2, 'run' => 'chain-20260901_040000/2', 'run_time' => '2026-09-03 04:00:00',
	'artifacts' => 1, 'bytes' => 1000, 'files' => 40, 'duration' => 3,
	'reason' => "files-0001.tar.gz.enc does not match its recorded hash.\nSecond line.");
$text = BackupVerifier::format_contract($fail);
check(preg_match('/^VERIFY_REASON=files-0001\.tar\.gz\.enc does not match its recorded hash\. Second line\.$/m', $text) === 1,
	'a failure reason is folded onto one line', $text);
check(strpos($text, 'VERIFY_TABLES') === false, 'level 2 prints no table line');
$back = BackupVerifier::parse_contract($text);
check($back['result'] === 'fail' && strpos($back['reason'], 'recorded hash') !== false, 'a fail reads back as a fail with its reason');

$skip = array('result' => 'skipped', 'level' => 2, 'run' => 'chain-20260901_040000/2', 'run_time' => '2026-09-03 04:00:00',
	'reason' => 'disk', 'needs_bytes' => 2600000000, 'free_bytes' => 900000000);
$text = BackupVerifier::format_contract($skip);
check(preg_match('/^VERIFY_NEEDS_BYTES=2600000000$/m', $text) === 1 && preg_match('/^VERIFY_FREE_BYTES=900000000$/m', $text) === 1,
	'a disk skip prints both numbers', $text);
$back = BackupVerifier::parse_contract($text);
check($back['result'] === 'skipped' && $back['reason'] === 'disk' && $back['needs_bytes'] === 2600000000 && $back['free_bytes'] === 900000000,
	'and they read back as integers');
$back = BackupVerifier::parse_contract(BackupVerifier::format_contract(array_merge($skip, array('reason' => 'createdb'))));
check(!isset($back['needs_bytes']), 'a createdb skip carries no disk numbers');

$back = BackupVerifier::parse_contract("some job transcript\nnothing useful\n");
check($back['result'] === 'fail' && strpos($back['reason'], 'no result') !== false,
	'a transcript with no VERIFY_RESULT line reads as a failure, never as a pass');
$back = BackupVerifier::parse_contract("VERIFY_RESULT=maybe\nVERIFY_LEVEL=2\n");
check($back['result'] === 'fail', 'and so does an unknown result word');

// ── Plain words ─────────────────────────────────────────────────────────────
section('The result in plain words');

$words = BackupVerifier::describe(array('result' => 'pass', 'level' => 2, 'run_time' => '2026-09-13 04:45:20',
	'artifacts' => 3, 'bytes' => 751829197, 'files' => 1842));
check($words === 'Opened and read the backup of 2026-09-13 04:45 UTC: 3 archives, 717 MB, 1,842 files.',
	'a level 2 pass says what was opened, when, and how much', $words);
$words = BackupVerifier::describe($pass);
check(strpos($words, 'Rehearsed a restore of the backup of 2026-09-03 04:00 UTC') === 0
	&& strpos($words, '214 tables') !== false && strpos($words, '12 users') !== false,
	'a level 3 pass says rehearsed, with tables and users', $words);
$words = BackupVerifier::describe($skip);
check($words === 'Could not verify the backup of 2026-09-03 04:00 UTC: needs 2.4 GB free, has 858.3 MB.',
	'a disk skip says both numbers', $words);
$words = BackupVerifier::describe($fail);
check(strpos($words, 'failed: files-0001.tar.gz.enc') !== false, 'a failure carries the reason', $words);
foreach (array(BackupVerifier::describe($pass), BackupVerifier::describe($fail), BackupVerifier::describe($skip)) as $w) {
	check(stripos($w, 'chain') === false && stripos($w, 'seq') === false && stripos($w, 'restore point') === false,
		'nobody reads "chain", "seq" or "restore point"', $w);
}
check(BackupVerifier::level_name(1) === 'checked in backup storage' && BackupVerifier::level_name(2) === 'opened and read'
	&& BackupVerifier::level_name(3) === 'rehearsed' && BackupVerifier::level_name(4) === '',
	'the three levels have their page names and nothing else does');

// ── Offloaded files ─────────────────────────────────────────────────────────
section('Offloaded files: the contract, the words, the sample and the link maps');

$with_objects = array_merge($pass, array('objects' => 1204, 'object_bytes' => 3435973836, 'objects_sampled' => 20));
$text = BackupVerifier::format_contract($with_objects);
check(preg_match('/^VERIFY_OBJECTS=1204$/m', $text) === 1 && preg_match('/^VERIFY_OBJECT_BYTES=3435973836$/m', $text) === 1
	&& preg_match('/^VERIFY_OBJECTS_SAMPLED=20$/m', $text) === 1, 'a rehearsal prints the three object lines', $text);
$back = BackupVerifier::parse_contract($text);
check($back['objects'] === 1204 && $back['object_bytes'] === 3435973836 && $back['objects_sampled'] === 20,
	'and they read back as integers');
$text = BackupVerifier::format_contract(array_merge($fail, array('objects' => 7, 'object_bytes' => 700, 'objects_sampled' => 3)));
check(preg_match('/^VERIFY_OBJECTS=7$/m', $text) === 1 && strpos($text, 'VERIFY_OBJECTS_SAMPLED') === false,
	'level 2 prints the count and bytes and never a sample line', $text);
check(preg_match('/^VERIFY_OBJECTS=0$/m', BackupVerifier::format_contract($pass)) === 1,
	'a result with no offloaded files says 0, so the plane never reads an absent line as unknown');
$words = BackupVerifier::describe($with_objects);
check(strpos($words, '1,204 offloaded files (3.2 GB), 20 of them opened') !== false, 'a rehearsal says how many were proven and how many opened', $words);
$words = BackupVerifier::describe(array('result' => 'pass', 'level' => 2, 'run_time' => '2026-09-13 04:45:20',
	'artifacts' => 4, 'bytes' => 751829197, 'files' => 1842, 'objects' => 1, 'object_bytes' => 2048));
check(strpos($words, '1 offloaded file (2 KB).') !== false && strpos($words, 'opened') === false,
	'level 2 names the count and size only', $words);
check(strpos(BackupVerifier::describe($pass), 'offloaded') === false, 'a run with none says nothing about them');

// The sample: the largest few, then a random draw from the rest; only names
// a link can carry.
$index = array('version' => 1, 'objects' => array());
for ($i = 1; $i <= 40; $i++) {
	$index['objects'][] = array('name' => sprintf('o%02d.bin', $i), 'epoch' => 'epoch-20260901_000000',
		'object_bytes' => $i * 1000, 'object_sha256' => str_repeat('a', 64), 'stored' => true);
}
$index['objects'][] = array('name' => 'waiting.bin', 'epoch' => '', 'object_bytes' => 0, 'object_sha256' => '', 'stored' => false);
$index['objects'][] = array('name' => '.hidden.bin', 'epoch' => 'epoch-20260901_000000', 'object_bytes' => 999999, 'object_sha256' => str_repeat('b', 64), 'stored' => true);
$sample = BackupVerifier::sample_objects($index);
check(count($sample) === 20 && count(array_unique($sample)) === 20, 'twenty distinct names', json_encode($sample));
check(array_slice($sample, 0, 5) === array('o40.bin', 'o39.bin', 'o38.bin', 'o37.bin', 'o36.bin'), 'the five largest come first, largest first', json_encode(array_slice($sample, 0, 5)));
check(!in_array('waiting.bin', $sample, true) && !in_array('.hidden.bin', $sample, true),
	'nothing unstored, and nothing a link could not be keyed by (a leading dot)');
$rest = array_slice($sample, 5);
$in_rest = count(array_filter($rest, function ($n) { return preg_match('/^o(0[1-9]|[12]\d|3[0-5])\.bin$/', $n); }));
check($in_rest === 15, 'fifteen more, all drawn from the rest', json_encode($rest));
$small = array('version' => 1, 'objects' => array_slice($index['objects'], 0, 3));
check(count(BackupVerifier::sample_objects($small)) === 3, 'a store smaller than the sample is sampled whole');
check(BackupVerifier::sample_objects(array('version' => 1, 'objects' => array())) === array(), 'an empty index samples nothing');
check(BackupVerifier::sample_bytes($index, array('o40.bin', 'o01.bin')) === 41000 + 40000,
	'the sample\'s disk need is every ciphertext plus one plaintext of the largest', BackupVerifier::sample_bytes($index, array('o40.bin', 'o01.bin')));
check(BackupVerifier::disk_needed($manifest, 2, 3, 81000) === 1632 + 2000 + 360 + 81000, 'and the disk check takes it');
check(BackupVerifier::disk_needed($manifest, 2, 3, 81000, true) === 2000 + 360 + 81000,
	'once the set is staged, the second check counts the sample and the rehearsal, not the set again');
$again = BackupVerifier::disk_check($manifest, 2, 3, '/nonexistent', 2000 + 360 + 81000 - 1, 81000, true);
check(is_array($again) && $again['needs_bytes'] === 2000 + 360 + 81000, 'and skips with that number');

// The link maps a request carries: names or epoch ids as keys, https values,
// bounded — or the request is malformed.
$refused = function ($value, $what, $pattern = BackupStaging::LINK_NAME_PATTERN, $max = BackupStaging::MAX_OBJECT_LINKS) {
	try { BackupStaging::link_map($value, $what, $pattern, $max); return ''; }
	catch (BackupStagingException $e) { return $e->getCode() === BackupStagingException::MALFORMED ? $e->getMessage() : 'wrong code'; }
};
check(BackupStaging::link_map(null, 'object_urls') === array(), 'absent is an empty map');
check(BackupStaging::link_map(array('beach.jpg' => 'https://x.invalid/b?sig=1'), 'object_urls') === array('beach.jpg' => 'https://x.invalid/b?sig=1'), 'a well-formed map passes');
check($refused('not a map', 'object_urls') !== '', 'a string is refused');
check(strpos($refused(array('../beach.jpg' => 'https://x.invalid/b'), 'object_urls'), 'not a name') !== false, 'a path key is refused');
check($refused(array('.hidden' => 'https://x.invalid/b'), 'object_urls') !== '', 'a hidden-file key is refused');
check(strpos($refused(array('beach.jpg' => 'http://x.invalid/b'), 'object_urls'), 'https') !== false, 'a plain-http link is refused');
check($refused(array('chain-20260901_000000' => 'https://x.invalid/e'), 'epoch_envelope_urls', BackupStaging::EPOCH_ID_PATTERN) !== ''
	&& BackupStaging::link_map(array('epoch-20260901_000000' => 'https://x.invalid/e'), 'epoch_envelope_urls', BackupStaging::EPOCH_ID_PATTERN) !== array(),
	'an envelope map is keyed by epoch ids only');
$many = array(); for ($i = 0; $i < 151; $i++) { $many['o' . $i] = 'https://x.invalid/o'; }
check(strpos($refused($many, 'object_urls'), 'at most 150') !== false, 'more than a page of links is refused');

// The script refuses a sample on a level that opens none, before it locks or fetches.
$req = json_encode(array('chain_id' => 'chain-20260901_040000', 'profile' => 'site', 'level' => 2,
	'manifest_url' => 'https://x.invalid/m?sig=1', 'artifact_urls' => array('files-0000.tar.gz.enc' => 'https://x.invalid/f?sig=1'),
	'object_urls' => array('beach.jpg' => 'https://x.invalid/b?sig=1')));
$desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$proc = proc_open('php ' . escapeshellarg(PathHelper::getIncludePath('utils/verify_backup.php')), $desc, $pipes);
fwrite($pipes[0], $req); fclose($pipes[0]);
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($proc);
check($rc === 2 && strpos($out, 'VERIFY_RESULT=fail') !== false && strpos($err, 'level 3') !== false,
	'object links on a level-2 request are a malformed request (exit 2), named', $err);

// ── Disk arithmetic ─────────────────────────────────────────────────────────
section('Disk needed before anything is downloaded');

// Run 2 depends on files 0, 1 and 2 (1500), its own dump (120) and meta (12).
check(BackupVerifier::disk_needed($manifest, 2, 2) === 1632, 'level 2 needs the set a restore of the run depends on',
	BackupVerifier::disk_needed($manifest, 2, 2));
check(BackupVerifier::disk_needed($manifest, null, 2) === 1632, 'the newest run is the default');
check(BackupVerifier::disk_needed($manifest, 0, 2) === 1110, 'run 0 needs only its own three artifacts');
// Level 3 adds twice the full's files archive and three times the dump.
check(BackupVerifier::disk_needed($manifest, 2, 3) === 1632 + 2000 + 360,
	'level 3 adds the replayed tree (2x the full) and the loaded database (3x the dump)',
	BackupVerifier::disk_needed($manifest, 2, 3));

check(BackupVerifier::disk_check($manifest, 2, 2, '/nonexistent', 1632) === null, 'exactly enough room is enough');
$short = BackupVerifier::disk_check($manifest, 2, 2, '/nonexistent', 1631);
check(is_array($short) && $short['result'] === 'skipped' && $short['reason'] === 'disk'
	&& $short['needs_bytes'] === 1632 && $short['free_bytes'] === 1631,
	'one byte short is a skip with both numbers, not a failure');
check($short['run'] === 'chain-20260901_040000/2' && $short['run_time'] === '2026-09-03 04:00:00',
	'and it still names the run it would have verified');
check(BackupVerifier::disk_check($manifest, 2, 2, '/nonexistent', false) === null,
	'unknowable free space does not refuse — BackupFetch checks again per artifact');

// ── The artifact plan ───────────────────────────────────────────────────────
section('The artifacts a verify of run N stages');

$plan = BackupStaging::plan($manifest, 1);
check(BackupStaging::wanted($plan) === array('files-0000.tar.gz.enc', 'files-0001.tar.gz.enc', 'db-0001.sql.gz.enc', 'meta-0001.tar.gz.enc'),
	'run 1: the full, the incremental, then run 1\'s dump and meta — not run 0\'s dump, not run 2\'s anything',
	implode(',', BackupStaging::wanted($plan)));
$plan = BackupStaging::plan($manifest, null);
check($plan['seq'] === 2 && count(BackupStaging::wanted($plan)) === 5, 'the default is the newest run, with every files archive beneath it');
$threw = null;
try { BackupStaging::plan($manifest, 7); } catch (BackupStagingException $e) { $threw = $e; }
check($threw !== null && $threw->getCode() === BackupStagingException::FAILED && strpos($threw->getMessage(), 'no run 7') !== false,
	'a run the chain does not have is refused with BackupChain\'s own words');

// The links are a listing of backup storage: a name the manifest needs with no
// link is an artifact backup storage no longer holds, and fails as `gone`
// (spec § Retention interplay) before anything is fetched.
$plan = BackupStaging::plan($manifest, 1);
$threw = null;
try {
	BackupStaging::fetch_artifacts('manager', sys_get_temp_dir() . '/verify-no-such-dir', 'chain-20260901_040000',
		BackupStaging::wanted($plan), array('db-0001.sql.gz.enc' => 'https://x.invalid/d?sig=1'), 1);
} catch (BackupStagingException $e) { $threw = $e; }
check($threw !== null && $threw->getCode() === BackupStagingException::FAILED
	&& strpos($threw->getMessage(), 'gone: files-0000.tar.gz.enc is no longer in backup storage') === 0
	&& strpos($threw->getMessage(), 'run 1 needs it') !== false,
	'an artifact the manifest needs with no link is gone, by name, as a failure (not a malformed request)',
	$threw ? $threw->getMessage() : 'no exception');

// The request shape is shared with stage_chain, plus 'level'.
$req = BackupStaging::parse_request(array('chain_id' => 'chain-20260901_040000', 'profile' => 'manager',
	'manifest_url' => 'https://x/m', 'artifact_urls' => array('files-0000.tar.gz.enc' => 'https://x/a'), 'level' => 3), array('level'));
check($req['level'] === 3 && $req['seq'] === null && $req['profile'] === 'manager', 'a verify request carries its level');
$threw = null;
try {
	BackupStaging::parse_request(array('chain_id' => 'chain-1', 'profile' => 'manager', 'manifest_url' => 'https://x/m',
		'artifact_urls' => array('a' => 'https://x/a'), 'level' => 2));
} catch (BackupStagingException $e) { $threw = $e; }
check($threw !== null && $threw->getCode() === BackupStagingException::MALFORMED && strpos($threw->getMessage(), 'level') !== false,
	'stage_chain\'s own request shape does not accept a level — the extra keys are per script');
$threw = null;
try {
	BackupStaging::parse_request(array('chain_id' => 'chain-1', 'profile' => 'manager', 'manifest_url' => 'https://x/m',
		'artifact_urls' => array('a' => 'https://x/a'), 'recovery_private_key' => 'nope'), array('level'));
} catch (BackupStagingException $e) { $threw = $e; }
check($threw !== null && $threw->getCode() === BackupStagingException::MALFORMED,
	'a key on the wire is refused as an unrecognised key, exit 2');

// ── The local sweep ─────────────────────────────────────────────────────────
section('A verify directory a killed process left is swept; a live one is not');

$work = harness_scratch_dir('backup_verify_test');
$profile_out = $work . '/manager';
@mkdir($profile_out, 0700, true);
harness_defer(function () use ($work) { BackupVerifier::remove_tree($work); });

$old = $profile_out . '/' . BackupVerifier::WORK_PREFIX . '12345';
@mkdir($old . '/scratch/site/sub', 0700, true);
file_put_contents($old . '/files-0000.tar.gz.enc', 'x');
file_put_contents($old . '/chain.key', 'a-recovered-data-key');
file_put_contents($old . '/scratch/site/sub/f.txt', 'replayed');
@chmod($old . '/scratch/site/sub', 0500);   // a read-only directory the replay carried
touch($old, time() - (2 * 86400));

$fresh = $profile_out . '/' . BackupVerifier::WORK_PREFIX . '12346';
@mkdir($fresh, 0700, true);
file_put_contents($fresh . '/files-0000.tar.gz.enc', 'x');

// keep_local is the backup retention window; the verify sweep does not use it,
// but sweep_local as a whole returns early at 0, so the plan carries a window.
BackupRunner::sweep_local(array('output_dir' => $profile_out, 'base_dir' => $work, 'keep_local' => 7));
check(!is_dir($old), 'a two-day-old verify directory is removed, read-only subdirectory and all');
check(!file_exists($old . '/chain.key'), 'the recovered chain key went with it');
check(is_dir($fresh) && file_exists($fresh . '/files-0000.tar.gz.enc'), 'a fresh one is left alone — a verify is running in it');

$hours_old = $profile_out . '/' . BackupVerifier::WORK_PREFIX . '12347';
@mkdir($hours_old, 0700, true);
touch($hours_old, time() - (20 * 3600));
BackupRunner::sweep_local(array('output_dir' => $profile_out, 'base_dir' => $work, 'keep_local' => 7));
check(is_dir($hours_old), 'twenty hours is inside the day a verify may legitimately take');

// ── The history stamp ───────────────────────────────────────────────────────
section('The stamp lands on the run that was verified');

$slug = 'verifytest-' . substr(bin2hex(random_bytes(3)), 0, 6);
$mk = function ($seq, $profile) use ($slug) {
	$row = new BackupHistory(NULL);
	$row->set('bkh_type', 'project');
	$row->set('bkh_slug', $slug);
	$row->set('bkh_profile', $profile);
	$row->set('bkh_outcome', 'success');
	$row->set('bkh_chain_id', 'chain-20260901_040000');
	$row->set('bkh_chain_seq', $seq);
	$row->set('bkh_start_time', '2026-09-0' . ($seq + 1) . ' 04:00:00');
	$row->set('bkh_finish_time', '2026-09-0' . ($seq + 1) . ' 04:05:00');
	$row->set('bkh_upload_time', '2026-09-0' . ($seq + 1) . ' 04:05:00');
	$row->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $row->key);
	return $row;
};
$r0 = $mk(0, 'manager');
$r1 = $mk(1, 'manager');
$r1_site = $mk(1, 'site');

// The stamp the script makes, through the same collection filter it uses.
$rows = new MultiBackupHistory(array('chain_id' => 'chain-20260901_040000', 'bkh_chain_seq' => 1,
	'profile' => 'manager', 'deleted' => false, 'slug' => $slug), array('bkh_start_time' => 'DESC'), 1, 0);
$stamped = 0;
foreach ($rows as $row) {
	$row->set('bkh_verify_time', '2026-09-13 10:00:00');
	$row->set('bkh_verify_level', 2);
	$row->set('bkh_verify_outcome', 'pass');
	$row->set('bkh_verify_message', 'Opened and read the backup of 2026-09-02 04:00 UTC: 4 archives, 1.3 KB, 40 files.');
	$row->save();
	$stamped++;
}
check($stamped === 1, 'exactly one row matches chain, run and profile');

$again = new BackupHistory($r1->key, TRUE);
check($again->get('bkh_verify_outcome') === 'pass' && (int)$again->get('bkh_verify_level') === 2
	&& strpos((string)$again->get('bkh_verify_time'), '2026-09-13 10:00:00') === 0,
	'run 1 of the manager profile carries the stamp');
check((new BackupHistory($r0->key, TRUE))->get('bkh_verify_time') === null, 'run 0 does not');
check((new BackupHistory($r1_site->key, TRUE))->get('bkh_verify_time') === null,
	'and neither does the site profile\'s run 1 — the same run number under the other party');

$verified = new MultiBackupHistory(array('slug' => $slug, 'verified' => true, 'deleted' => false));
check(count($verified) === 1, "the 'verified' filter selects it", count($verified));
$unverified = new MultiBackupHistory(array('slug' => $slug, 'verified' => false, 'deleted' => false));
check(count($unverified) === 2, 'and its complement the other two', count($unverified));

$threw = null;
try {
	$bad = new BackupHistory($r0->key, TRUE);
	$bad->set('bkh_verify_outcome', 'skipped');
	$bad->save();
} catch (\Throwable $e) { $threw = $e; }
check($threw !== null, 'a skip is not an outcome the row accepts — nothing was proven either way');

// The stamp the script actually makes, through the engine's own function:
// a pass or a failure in full; a skip or a refusal as the message only, so a
// verify a person started leaves a trace whatever became of it.
section('A skip or a refusal leaves its reason on the run, beside the last real result');

$n = BackupVerifier::stamp_history(array('result' => 'fail', 'level' => 2, 'run_time' => '2026-09-01 04:00:00',
	'reason' => 'files-0000.tar.gz.enc does not match its recorded hash'), 'chain-20260901_040000', 0, 'manager');
$row0 = new BackupHistory($r0->key, TRUE);
check($n === 1 && $row0->get('bkh_verify_outcome') === 'fail' && (int)$row0->get('bkh_verify_level') === 2
	&& $row0->get('bkh_verify_time') !== null
	&& strpos((string)$row0->get('bkh_verify_message'), 'Verification of the backup of 2026-09-01 04:00 UTC failed: files-0000') === 0,
	'a failure stamps time, level, outcome and message on the run', (string)$row0->get('bkh_verify_message'));
check((new BackupHistory($r1->key, TRUE))->get('bkh_verify_outcome') === 'pass', 'and leaves the other run\'s pass alone');

$n = BackupVerifier::stamp_history(array('result' => 'skipped', 'level' => 2, 'reason' => 'disk',
	'needs_bytes' => 2576980378, 'free_bytes' => 900000000), 'chain-20260901_040000', 1, 'manager');
$row1 = new BackupHistory($r1->key, TRUE);
check($n === 1 && $row1->get('bkh_verify_outcome') === 'pass' && strpos((string)$row1->get('bkh_verify_time'), '2026-09-13 10:00:00') === 0,
	'a skip leaves the pass and its time standing');
check($row1->get('bkh_verify_message') === 'Could not verify the backup of 2026-09-02 04:00 UTC: needs 2.4 GB free, has 858.3 MB.',
	'and puts its reason in the message, dating the backup from the row when the skip came before the manifest was read',
	(string)$row1->get('bkh_verify_message'));
check(BackupVerifier::is_attempt_message((string)$row1->get('bkh_verify_message')), 'which reads as an attempt, not a result');
check(!BackupVerifier::is_attempt_message((string)$row0->get('bkh_verify_message')), 'a failure does not');

$n = BackupVerifier::note_history('chain-20260901_040000', 1, 'site', "'level' must be 2 (open and read) or 3 (rehearse a restore)");
$row1s = new BackupHistory($r1_site->key, TRUE);
check($n === 1 && $row1s->get('bkh_verify_time') === null && $row1s->get('bkh_verify_outcome') === null,
	'a refusal on a never-verified run stamps no time and no outcome');
check($row1s->get('bkh_verify_message') === "Could not verify the backup of 2026-09-02 04:00 UTC: 'level' must be 2 (open and read) or 3 (rehearse a restore)",
	'only the reason, dated from the run', (string)$row1s->get('bkh_verify_message'));

$attempted = new MultiBackupHistory(array('slug' => $slug, 'verify_attempted' => true, 'deleted' => false));
check(count($attempted) === 2, "the 'verify_attempted' filter selects both", count($attempted));
check(BackupVerifier::stamp_history(array('result' => 'pass', 'level' => 2), 'chain-20260901_040000', 7, 'manager') === 0,
	'a run this machine has no row for stamps nothing');

// ── A chatty child ──────────────────────────────────────────────────────────
section('A child that floods stderr does not hang the verify');

// restore_database.sh tags every command on stderr, and a pipe holds 64 KB.
// Reading stdout to its end before touching stderr blocks for ever once the
// child fills the pipe nobody is reading; a rehearsal then sat until the
// agent's timeout killed it, and the kill skipped the shutdown handler that
// drops the throwaway database. Run in a child PHP under `timeout`, so a
// regression fails here instead of hanging the suite.
$scratch = harness_scratch_dir('verify-chatty');
$probe = $scratch . '/chatty.php';
file_put_contents($probe, '<?php'
	. ' require_once(' . var_export(PathHelper::getIncludePath('includes/PathHelper.php'), true) . ');'
	. ' require_once(PathHelper::getIncludePath("includes/BackupVerifier.php"));'
	. ' $r = BackupVerifier::run_command("yes eeeeeeeee | head -c 300000 >&2; echo RESTORE_OK", array());'
	. ' echo json_encode(array("rc" => $r["rc"], "marker" => (int)preg_match("/^RESTORE_OK$/m", $r["output"]), "err" => substr_count($r["output"], "e")));' . "\n");
$cmd = 'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1';
$started = microtime(true);
$out = (string)shell_exec($cmd);
$took = microtime(true) - $started;
$r = json_decode(trim($out), true);
check(is_array($r), 'the child came back within 30 s (a two-pipe read deadlocks against 300 KB of stderr)',
	'took ' . round($took, 1) . ' s: ' . substr(trim($out), 0, 200));
check(is_array($r) && $r['rc'] === 0 && $r['marker'] === 1, 'its stdout marker was read', json_encode($r));
check(is_array($r) && $r['err'] >= 250000, 'and its stderr is in the output to the end', json_encode($r));

harness_finish();
