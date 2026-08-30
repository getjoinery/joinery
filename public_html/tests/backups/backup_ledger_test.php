<?php
/** @joinery-test
 * name: backup_ledger
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The upload ledger, and the two attacks it exists for.
 *
 * A restore over the agent channel fetches an archive from a bucket the
 * MANAGEMENT NODE owns, using a link the management node signed, and loads it as
 * root over live data. The person approving the restore approves a NAME and a
 * date — they never see the bytes. The ledger is the only thing on the machine
 * that can tell whether those bytes are the ones this machine uploaded under
 * that name, and it can only do that because it was written at upload time,
 * before the bytes were anywhere the management node could reach.
 *
 * Two attacks, and the second is the one that carries the whole design:
 *
 *   FORGERY — an artifact whose content is simply made up. Refused because its
 *   hash is not the recorded one.
 *
 *   REPLAY — this machine's OWN genuine month-old archive, served under a
 *   fresh-looking name. Sealing does not touch it: every signature verifies and
 *   every envelope opens, because it really is this machine's backup. Refused
 *   here because the name it is offered under has no record.
 *
 * The ledger's address is asserted too, because both requirements that picked it
 * are invisible from the code that reads it: it has to survive a container
 * rebuild (config/ is a volume, the rest of the filesystem is not) and it has to
 * survive a project restore (restore_project.sh drops the archive's copy).
 *
 * Run: php tests/backups/backup_ledger_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupLedger.php'));

/**
 * The real class with its directory pointed somewhere disposable.
 *
 * A subclass rather than a settable path: production has no way to move the
 * ledger, and a test that needed one would have created the very knob the design
 * refuses. dir() is the single seam, and everything else — the entries, the
 * hashing, the refusals — is the shipped code.
 */
class TestBackupLedger extends BackupLedger {
	public static $test_dir = '';
	public static function dir() { return self::$test_dir; }
}

$tmp = sys_get_temp_dir() . '/joinery_ledger_test_' . getmypid();
@mkdir($tmp, 0755, true);
TestBackupLedger::$test_dir = $tmp . '/backup-ledger';

harness_defer(function () use ($tmp) {
	foreach (glob($tmp . '/backup-ledger/*') ?: array() as $f) { @unlink($f); }
	@rmdir($tmp . '/backup-ledger');
	foreach (glob($tmp . '/*') ?: array() as $f) { @unlink($f); }
	@rmdir($tmp);
});

// A stand-in archive, and a second one with different bytes.
$archive = $tmp . '/db_2026-08-30.sql.gz.enc';
file_put_contents($archive, 'the real backup');
$imposter = $tmp . '/imposter';
file_put_contents($imposter, 'not the real backup');

// ── Where it lives ──────────────────────────────────────────────────────────
section('The ledger lives where it survives a rebuild and a restore');

$real_dir = BackupLedger::dir();
check(strpos($real_dir, '/config/backup-ledger') !== false,
	'the ledger is under the site config directory', $real_dir);
check(strpos($real_dir, '/public_html') === false,
	'the ledger is not inside the web tree', $real_dir);

// restore_project.sh has to drop the archive's copy, or the first restore
// overwrites the record that vouches for the second.
$restore_script = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/restore_project.sh';
$restore_src = is_file($restore_script) ? (string)file_get_contents($restore_script) : '';
check($restore_src !== '' && strpos($restore_src, 'config/backup-ledger') !== false,
	'a project restore keeps this machine\'s own ledger rather than the archive\'s',
	$restore_src === '' ? 'restore_project.sh not found' : '');

// ── Recording and matching ──────────────────────────────────────────────────
section('An archive this machine uploaded is recognised');

check(TestBackupLedger::record('manager', 'db_2026-08-30.sql.gz.enc', $archive, 'prefix/slug/manager/db.sql.gz.enc'),
	'an upload is recorded');

$entry = TestBackupLedger::lookup('manager', 'db_2026-08-30.sql.gz.enc');
check(is_array($entry) && $entry['sha256'] === hash_file('sha256', $archive),
	'the record holds the hash of the bytes that went up');
check(is_array($entry) && (int)$entry['bytes'] === filesize($archive),
	'and the size, which is what the free-space check reads before a transfer');

$v = TestBackupLedger::verify('manager', 'db_2026-08-30.sql.gz.enc', $archive);
check($v['ok'] === true, 'the archive verifies against its own record');

// ── Forgery ─────────────────────────────────────────────────────────────────
section('Bytes that are not the recorded ones are refused');

$v = TestBackupLedger::verify('manager', 'db_2026-08-30.sql.gz.enc', $imposter);
check($v['ok'] === false, 'a different file under a recorded name is refused');
check(strpos($v['reason'], 'not bytes this machine uploaded') !== false,
	'and the refusal says the bytes are wrong, not that the file is missing', $v['reason']);

// ── Replay ──────────────────────────────────────────────────────────────────
section('A genuine archive offered under a different name is refused');

// This is the attack sealing does not touch: the bytes are real, this machine
// really made them, every signature verifies. The only thing wrong is the name
// it is being offered under — so the name is what is checked.
$v = TestBackupLedger::verify('manager', 'db_2026-08-31.sql.gz.enc', $archive);
check($v['ok'] === false, 'a real archive under a name that was never uploaded is refused');
check(strpos($v['reason'], 'no record of uploading') !== false,
	'and the refusal names the missing record', $v['reason']);

// ── Profiles are separate shelves ───────────────────────────────────────────
section('The two profiles keep separate records');

check(TestBackupLedger::lookup('site', 'db_2026-08-30.sql.gz.enc') === null,
	'a manager-profile upload is not a site-profile record');
$v = TestBackupLedger::verify('site', 'db_2026-08-30.sql.gz.enc', $archive);
check($v['ok'] === false, 'and it does not verify under the wrong profile');

// ── A machine with no ledger at all ─────────────────────────────────────────
section('No ledger means refuse, never means allow');

check(TestBackupLedger::exists('site') === false, 'no site ledger has been written');
check(strpos($v['reason'], 'no upload ledger') !== false,
	'and the refusal says the machine cannot confirm anything, rather than blaming the file',
	$v['reason']);

// ── Chain artifacts ─────────────────────────────────────────────────────────
section('Chain artifacts are keyed by chain and name');

$chain_artifact = $tmp . '/files-0001.tar.gz.enc';
file_put_contents($chain_artifact, 'incremental one');
check(TestBackupLedger::record('manager', 'chain-20260830_010203/files-0001.tar.gz.enc', $chain_artifact),
	'a chain artifact records under chain/name');
check(TestBackupLedger::verify('manager', 'chain-20260830_010203/files-0001.tar.gz.enc', $chain_artifact)['ok'],
	'and verifies under the same key');
check(TestBackupLedger::lookup('manager', 'files-0001.tar.gz.enc') === null,
	'the bare name is not the same record — two chains can hold a files-0001');

// ── Names that are not names ────────────────────────────────────────────────
section('A traversal is not a name');

foreach (array('../escape', 'chain-1/../../etc/passwd', '/absolute', '..', '') as $bad) {
	check(TestBackupLedger::lookup('manager', $bad) === null,
		'refuses to look up ' . ($bad === '' ? '(empty)' : $bad));
	check(TestBackupLedger::record('manager', $bad, $archive) === false,
		'refuses to record ' . ($bad === '' ? '(empty)' : $bad));
}

// ── A name that is legitimately rewritten ───────────────────────────────────
section('A rewritten name keeps the versions it has had');

// Only manifest.json exercises this. Chain artifacts are named per run and
// written once, so for them the recorded version is the only version. A chain's
// manifest is rewritten by EVERY run of that chain — that is what a growing
// chain is — and treating the newest as the only true one refused an
// already-approved restore whenever a backup landed during the approval window.
$manifest = $tmp . '/manifest.json';
file_put_contents($manifest, '{"runs":[0]}');
TestBackupLedger::record('manager', 'chain-20260830_010203/manifest.json', $manifest);
$staged_sha = hash_file('sha256', $manifest);

// The chain grows: the same name, new bytes.
file_put_contents($manifest, '{"runs":[0,1]}');
TestBackupLedger::record('manager', 'chain-20260830_010203/manifest.json', $manifest);

$v = TestBackupLedger::verify('manager', 'chain-20260830_010203/manifest.json', $manifest);
check($v['ok'] === true, 'the newest version verifies');

// And the one staged before the chain grew — the case that was failing.
file_put_contents($manifest, '{"runs":[0]}');
$v = TestBackupLedger::verify('manager', 'chain-20260830_010203/manifest.json', $manifest);
check($v['ok'] === true,
	'a version staged before the chain grew still verifies',
	'a scheduled backup landing during the approval window must not refuse an approved restore');
check(is_array($v['entry']) && $v['entry']['sha256'] === $staged_sha,
	'and verify() reports the version that MATCHED, not the newest',
	'the approval screen shows an archive\'s age, and it has to be the age of the bytes in front '
	. 'of the operator');

// What it must NOT accept: bytes that were never recorded under that name.
file_put_contents($manifest, '{"runs":[0,1,2],"forged":true}');
$v = TestBackupLedger::verify('manager', 'chain-20260830_010203/manifest.json', $manifest);
check($v['ok'] === false, 'a version this machine never uploaded is still refused');

// The history is bounded — an unbounded list on a long-lived name is a file
// that grows without anyone deciding it should.
for ($i = 0; $i < 20; $i++) {
	file_put_contents($manifest, '{"runs":[' . $i . ']}');
	TestBackupLedger::record('manager', 'chain-20260830_010203/manifest.json', $manifest);
}
$entry = TestBackupLedger::lookup('manager', 'chain-20260830_010203/manifest.json');
check(count($entry['previous'] ?? array()) <= TestBackupLedger::MAX_PREVIOUS,
	'the kept history is bounded',
	'kept ' . count($entry['previous'] ?? array()));

// A single-version artifact carries no history at all — this costs the rest of
// the ledger nothing.
$plain = TestBackupLedger::lookup('manager', 'db_2026-08-30.sql.gz.enc');
check(!isset($plain['previous']),
	'a name written once carries no history');

// ── Survives being rewritten ────────────────────────────────────────────────
section('Recording again keeps what was already there');

check(TestBackupLedger::record('manager', 'db_2026-08-31.sql.gz.enc', $imposter),
	'a second upload is recorded');
check(TestBackupLedger::lookup('manager', 'db_2026-08-30.sql.gz.enc') !== null,
	'the first record is still there — a ledger that forgot would refuse good archives');
check(TestBackupLedger::verify('manager', 'db_2026-08-31.sql.gz.enc', $imposter)['ok'],
	'and the second verifies on its own bytes');

// ── A ledger anything can write is not evidence ─────────────────────────────
section('A ledger the group or the world can write is refused, not believed');

// The ledger is the only thing between an approved NAME and arbitrary bytes. If
// something other than its owner can rewrite it, the check does not weaken — it
// inverts: whoever rewrote it decides what this machine believes it uploaded,
// and verify() then reports success. So both sides refuse. The agent has refused
// since the primitive shipped (primitives/ledger.go, untrustedLedgerError); this
// is the same refusal on the side that does the downloading, so it arrives
// before the bytes move rather than after.
check(BackupLedger::untrusted('manager') === '' || !is_dir(BackupLedger::dir()),
	'a correctly-permissioned ledger is trusted');

$ledger_file = TestBackupLedger::path('manager');
$was = fileperms($ledger_file) & 0777;

chmod($ledger_file, 0666);
clearstatcache();
$why = TestBackupLedger::untrusted('manager');
check($why !== '', 'a world-writable ledger file is refused');
check(strpos($why, 'permissions') !== false, 'and the refusal says how to fix it', $why);
check(TestBackupLedger::verify('manager', 'db_2026-08-30.sql.gz.enc', $archive)['ok'] === false,
	'verify() refuses rather than confirming an archive against a record anything could have written');

chmod($ledger_file, 0620);
clearstatcache();
check(TestBackupLedger::untrusted('manager') !== '',
	'group-writable is refused too — the web tier is usually in a group, not the world');

chmod($ledger_file, $was);
clearstatcache();
check(TestBackupLedger::untrusted('manager') === '',
	'and putting the permissions back restores the record\'s standing');
check(TestBackupLedger::verify('manager', 'db_2026-08-30.sql.gz.enc', $archive)['ok'],
	'a good archive verifies again');

$dir_was = fileperms(TestBackupLedger::dir()) & 0777;
chmod(TestBackupLedger::dir(), 0777);
clearstatcache();
check(TestBackupLedger::untrusted('manager') !== '',
	'a writable DIRECTORY is refused too — the file can simply be replaced');
chmod(TestBackupLedger::dir(), $dir_was);
clearstatcache();

// The two sides have to agree on the rule, or a download succeeds and the
// restore it was for refuses. Asserted against the agent's own source.
$ledger_go = '/home/user1/joinery-agent/primitives/ledger.go';
$go_src = is_file($ledger_go) ? (string)file_get_contents($ledger_go) : '';
check($go_src === '' || strpos($go_src, '0o022') !== false,
	'the agent tests the same bits (group or other write), so the two sides cannot disagree',
	$go_src === '' ? 'agent source not present on this machine' : '');

harness_finish();
