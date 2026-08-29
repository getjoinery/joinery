<?php
/** @joinery-test
 * name: restore_database_envelope
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * restore_database.sh opens an envelope-sealed archive with this machine's own
 * key, and does it for real — this file runs the script.
 *
 * WHY IT IS RUN RATHER THAN READ. The restore engine's key resolution used to
 * be supplied from outside: the management node unsealed <archive>.keys.json with
 * the node's backup_site_key and passed the result as --key-file. A node
 * restoring on its own behalf over the agent channel has no such helper, so
 * without a fallback inside the script an envelope-sealed archive is restorable
 * over SSH and not otherwise — and the archives that matter most are
 * envelope-sealed. The fallback (restore_database.sh 3.4) is therefore
 * load-bearing, and "it parses and no existing caller reaches it" is exactly
 * the kind of confidence that ships a branch which has never worked.
 *
 * THE POSITIVE CASE IS ARRANGED SO ONLY THE NEW BRANCH CAN PASS IT. The script
 * is run with HOME pointing at an empty directory and no $BACKUP_ENCRYPTION_KEY,
 * so every other key source is absent. If the sidecar is not opened, there is
 * nothing else for the restore to succeed with.
 *
 * THE OTHER TWO CASES ARE THE GUARDRAILS, and they matter as much:
 *
 *   - An explicit --key-file still wins. The fallback must fire ONLY when no
 *     key was named, because every existing caller names one and that path had
 *     to stay unchanged. Proven with a wrong key: the restore fails rather than
 *     quietly recovering the right key from the sidecar.
 *   - A sidecar sealed to somebody else falls through with a warning and does
 *     not become a false success. An archive sealed to a different machine is a
 *     restore that must not happen, and the failure must say so.
 *
 * Every case also asserts the target database was left alone. restore_database
 * verifies before it destroys, so a key failure must never reach the schema
 * drop — a sentinel table planted beforehand is the check.
 *
 * The script is exercised from a COPY in a throwaway tree, because it locates
 * the site key relative to its own path ({tree}/config/backup_site_key). That
 * makes the tree the test's to arrange, and keeps this test's keys away from
 * the real site's.
 *
 * Run: php tests/backups/restore_database_envelope_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$settings = Globalvars::get_instance();
$db_user  = (string)$settings->get_setting('dbusername');
$db_pass  = (string)$settings->get_setting('dbpassword');
$live_db  = (string)$settings->get_setting('dbname');

// ── The throwaway tree ────────────────────────────────────────────────────────

// Deliberately NOT named jy_restore_*: restore_database.sh sweeps that
// prefix in /tmp for stale staging, and a test tree living inside another
// mechanism's cleanup namespace is a race waiting for a slow run.
$tmp = sys_get_temp_dir() . '/jy_rde_tree_' . bin2hex(random_bytes(4));
$tools = $tmp . '/tree/maintenance_scripts/sysadmin_tools';
$conf  = $tmp . '/tree/config';
$home  = $tmp . '/home';          // deliberately empty: no standing backup key
$backups = $tmp . '/backups';

foreach ([$tools, $conf, $home, $backups] as $dir) {
	if (!mkdir($dir, 0777, true)) { throw new Exception("could not create {$dir}"); }
}

function rde_rrmdir($dir) {
	if (!is_dir($dir)) { return; }
	foreach (scandir($dir) ?: [] as $f) {
		if ($f === '.' || $f === '..') { continue; }
		$p = $dir . '/' . $f;
		is_dir($p) ? rde_rrmdir($p) : @unlink($p);
	}
	@rmdir($dir);
}
harness_defer(function () use ($tmp) { rde_rrmdir($tmp); });

$src = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
foreach (['restore_database.sh', 'backup_envelope.php'] as $f) {
	if (!copy($src . '/' . $f, $tools . '/' . $f)) {
		throw new Exception("could not copy {$f} into the test tree");
	}
	chmod($tools . '/' . $f, 0755);
}
$engine = $tools . '/restore_database.sh';

// ── A scratch database, dropped again whatever happens ────────────────────────

$scratch = 'jy_rde_' . bin2hex(random_bytes(4));

/** Run psql against $db and return [exit_code, stdout]. */
function rde_psql($sql, $db, $user, $pass) {
	$cmd = sprintf('PGPASSWORD=%s psql -U %s -d %s -tAc %s 2>&1',
		escapeshellarg($pass), escapeshellarg($user), escapeshellarg($db), escapeshellarg($sql));
	$out = []; $rc = 0;
	exec($cmd, $out, $rc);
	return [$rc, trim(implode("\n", $out))];
}

list($rc, $out) = rde_psql("CREATE DATABASE {$scratch}", $live_db, $db_user, $db_pass);
if ($rc !== 0) {
	// Not a failure of the thing under test: say so plainly and stop, rather
	// than reporting a restore failure that is really a permissions problem.
	check(false, 'a scratch database can be created for the restore to land in', $out);
	harness_finish();
	return;
}
harness_defer(function () use ($scratch, $live_db, $db_user, $db_pass) {
	rde_psql("DROP DATABASE IF EXISTS {$scratch}", $live_db, $db_user, $db_pass);
});

/** Plant the sentinel that proves a failed restore never reached the schema drop. */
function rde_plant_sentinel($scratch, $user, $pass) {
	rde_psql('DROP TABLE IF EXISTS rde_untouched; CREATE TABLE rde_untouched (id int)',
		$scratch, $user, $pass);
}
function rde_sentinel_survives($scratch, $user, $pass) {
	list($rc, $out) = rde_psql(
		"SELECT count(*) FROM information_schema.tables WHERE table_name = 'rde_untouched'",
		$scratch, $user, $pass);
	return $rc === 0 && $out === '1';
}

// ── The archive, its data key, and the envelope beside it ─────────────────────

// A site keypair in the tree the copied script will look in. Written in the
// format BackupEnvelope writes: base64 of the sodium keypair.
$site_keypair = sodium_crypto_box_keypair();
file_put_contents($conf . '/backup_site_key', base64_encode($site_keypair));
chmod($conf . '/backup_site_key', 0640);
$site_pub = sodium_crypto_box_publickey($site_keypair);

// Somebody else's key, for the archive this machine must NOT be able to open.
$stranger_pub = sodium_crypto_box_publickey(sodium_crypto_box_keypair());

// The data key, minted the way a real backup mints one: base64 text, because
// openssl consumes it as a PBKDF2 passphrase on a pipe.
$data_key = base64_encode(random_bytes(32));

$dump = "CREATE TABLE rde_probe (id integer);\nINSERT INTO rde_probe VALUES (42);\n";
$plain = $backups . '/rde_probe.sql';
file_put_contents($plain, $dump);

$gz = $backups . '/rde_probe.sql.gz';
exec(sprintf('gzip -c %s > %s', escapeshellarg($plain), escapeshellarg($gz)), $o, $rc);
if ($rc !== 0) { throw new Exception('could not gzip the test dump'); }

/** Encrypt $in to $out under $key, with the key on stdin (never argv). */
function rde_encrypt($in, $out, $key) {
	$cmd = sprintf('openssl enc -aes-256-cbc -pbkdf2 -pass stdin -in %s -out %s',
		escapeshellarg($in), escapeshellarg($out));
	$pipes = [];
	$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($proc)) { throw new Exception('could not start openssl'); }
	fwrite($pipes[0], $key . "\n");
	fclose($pipes[0]);
	stream_get_contents($pipes[1]); fclose($pipes[1]);
	stream_get_contents($pipes[2]); fclose($pipes[2]);
	return proc_close($proc) === 0;
}

$archive = $backups . '/rde_probe.sql.gz.enc';
if (!rde_encrypt($gz, $archive, $data_key)) {
	throw new Exception('could not encrypt the test archive');
}

/** Write an envelope beside $archive sealing $data_key to $pub. */
function rde_write_sidecar($archive, $data_key, $pub) {
	$envelope = BackupEnvelope::build($data_key, basename($archive),
		[['kind' => 'site', 'pub' => $pub]]);
	BackupEnvelope::write_sidecar($archive . '.keys.json', $envelope);
	@chmod($archive . '.keys.json', 0644);
}

/**
 * Run the copied engine with a controlled environment and return
 * [marker, stderr]. HOME is an empty directory and BACKUP_ENCRYPTION_KEY is
 * unset, so the only key sources that can exist are the ones each case sets up.
 */
function rde_restore($engine, $scratch, $archive, $home, $db_user, $db_pass, array $extra = []) {
	$cmd = 'bash ' . escapeshellarg($engine) . ' ' . escapeshellarg($scratch) . ' '
		. escapeshellarg($archive) . ' --non-interactive --db-user ' . escapeshellarg($db_user);
	foreach ($extra as $arg) { $cmd .= ' ' . escapeshellarg($arg); }

	$env = [
		'PATH'       => getenv('PATH'),
		'HOME'       => $home,
		'PGPASSWORD' => $db_pass,
	];
	$pipes = [];
	$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
	if (!is_resource($proc)) { throw new Exception('could not start the restore engine'); }
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
	proc_close($proc);

	// stdout carries exactly one marker; everything informational is on stderr.
	return [trim($stdout), $stderr];
}

$sidecar_used = 'recovered from';

section('An explicit --key-file still decides, and the fallback does not fire');

// The guardrail the whole change rests on: every existing caller passes
// --key-file, so if the sidecar could override one, this round would have
// changed the live SSH restore path it promised not to touch.
rde_write_sidecar($archive, $data_key, $site_pub);
rde_plant_sentinel($scratch, $db_user, $db_pass);

$wrong_key_file = $tmp . '/wrong.key';
file_put_contents($wrong_key_file, base64_encode(random_bytes(32)) . "\n");
chmod($wrong_key_file, 0600);

list($marker, $stderr) = rde_restore($engine, $scratch, $archive, $home, $db_user, $db_pass,
	['--key-file', $wrong_key_file]);
check($marker === 'DECRYPT_FAILED',
	'a named key that cannot open the archive fails the restore',
	"marker was '{$marker}' — if it succeeded, the sidecar overrode an explicit --key-file, "
	. 'which is the one behaviour the SSH restore path depends on not changing');
check(strpos($stderr, $sidecar_used) === false,
	'the sidecar is not consulted when a key was named',
	'the engine reported recovering the key from the envelope despite being given one');
check(rde_sentinel_survives($scratch, $db_user, $db_pass),
	'the database is untouched after a key failure',
	'verify-before-destroy means a key failure must never reach the schema drop');

section('A sidecar sealed to another machine falls through — it is not a success');

rde_write_sidecar($archive, $data_key, $stranger_pub);
rde_plant_sentinel($scratch, $db_user, $db_pass);

list($marker, $stderr) = rde_restore($engine, $scratch, $archive, $home, $db_user, $db_pass);
check($marker === 'BACKUP_KEY_MISSING',
	'an archive this machine cannot open is refused',
	"marker was '{$marker}'. With HOME empty and no \$BACKUP_ENCRYPTION_KEY there is no other key "
	. 'source, so anything but a refusal means a key came from somewhere unaccounted for');
check(strpos($stderr, 'did not open with this machine') !== false,
	'and it says why, rather than reporting a missing key',
	'an archive sealed to a different machine is a different problem from having no key at all, '
	. "and the operator has to be able to tell them apart. stderr was:\n" . $stderr);
check(rde_sentinel_survives($scratch, $db_user, $db_pass),
	'the database is untouched after a sidecar that would not open',
	'verify-before-destroy means a key failure must never reach the schema drop');

section('An envelope sealed to this machine restores with no key named at all');

// The case the fallback exists for, and the case a node performs on its own
// behalf: no --key-file, no standing key, nothing but the envelope beside the
// archive and this tree's own site key.
rde_write_sidecar($archive, $data_key, $site_pub);
rde_plant_sentinel($scratch, $db_user, $db_pass);

list($marker, $stderr) = rde_restore($engine, $scratch, $archive, $home, $db_user, $db_pass);
check($marker === 'RESTORE_OK',
	'the restore succeeds from the envelope alone',
	"marker was '{$marker}'. HOME is an empty directory and \$BACKUP_ENCRYPTION_KEY is unset, so "
	. "the sidecar is the only key that exists. stderr was:\n" . $stderr);
check(strpos($stderr, $sidecar_used) !== false,
	'and it says the key came from the envelope',
	'the restore worked without naming where the key came from, which would leave the branch '
	. 'unproven even on a pass');

list($rc, $out) = rde_psql('SELECT count(*) FROM rde_probe', $scratch, $db_user, $db_pass);
check($rc === 0 && $out === '1',
	'the restored database holds what the archive carried',
	'a marker of RESTORE_OK over an empty schema would be the worst possible pass');

check(!rde_sentinel_survives($scratch, $db_user, $db_pass),
	'and the schema it replaced is gone',
	'restore_database replaces a schema; a sentinel that survived it means the load ran somewhere '
	. 'other than where the test was looking');

section('Nothing is left holding a usable key');

$leftovers = glob('/tmp/jy_restore_*key*') ?: [];
$mine = array_filter($leftovers, function ($p) { return filemtime($p) >= (time() - 300); });
check(empty($mine),
	'the unsealed archive key does not outlive the restore',
	'left behind: ' . implode(', ', $mine)
	. ' — a key recovered from an envelope is as good as the archive, and a stranded copy of it '
	. 'is a copy nobody knows exists');

harness_finish();
