<?php
/** @joinery-test
 * name: backup_staging_recovery
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [includes/BackupStaging.php, utils/stage_chain.php]
 */
/**
 * A Prepare of a chain this machine did not make (B20).
 *
 * After a site moves to a new server, its backups from before the move are
 * sealed to the old server's key, which never left it, and to the site's
 * recovery key. Prepare stages such a chain for the recovery key rather than
 * refusing it; the restore's approval opens it.
 *
 *   - a chain this machine's key opens is staged with its key (KEY_SITE);
 *   - one it does not open, with a recovery box, is staged without one, and a
 *     key file already in the workspace is removed (KEY_RECOVERY);
 *   - one with no recovery box is refused, as before;
 *   - recovery_box() reads the box and its fingerprint from the envelope;
 *   - fetch_manifest() records a matched manifest's artifacts before any is
 *     fetched, so a handed-over chain's files are checked as they arrive.
 *
 * Needs this machine's own backup key, which it reads and never mints: the
 * checks that open with it are skipped where there is none.
 *
 * Run: php tests/backups/backup_staging_recovery_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$work = sys_get_temp_dir() . '/backup_staging_recovery_' . getmypid();
harness_defer(function() use ($work) { exec('rm -rf ' . escapeshellarg($work)); });
BackupStaging::prepare_workspace($work);

$recovery = random_bytes(32);   // X-Wing seeds
$recovery_pub = (new XWing())->publicKey($recovery);
$other_server = random_bytes(32);
$other_server_pub = (new XWing())->publicKey($other_server);
$data_key = base64_encode(random_bytes(32));
$envelope = function (array $recipients) use ($data_key) {
	return BackupEnvelope::build($data_key, 'chain-20261001_000000', $recipients);
};
$manifest_of = function (array $envelope) {
	return array('chain_id' => 'chain-20261001_000000', 'envelope' => $envelope, 'runs' => array());
};

section('The recovery box is read from the envelope');

$theirs = $manifest_of($envelope(array(
	array('kind' => 'recovery', 'pub' => $recovery_pub),
	array('kind' => 'site', 'pub' => $other_server_pub),
)));
$box = BackupStaging::recovery_box($theirs);
check(is_array($box) && $box['fingerprint'] === hash('sha256', $recovery_pub)
		&& ($box['alg'] ?? '') === BackupEnvelope::ALG_XWING
		&& SealedBox::openV2(base64_decode($box['sealed']), $recovery) === $data_key,
	'the box sealed to the recovery key, with that key\'s fingerprint');
check(BackupStaging::recovery_box($manifest_of($envelope(array(array('kind' => 'site', 'pub' => $recovery_pub))))) === null,
	'an envelope with no recovery recipient has none');
check(BackupStaging::recovery_box(array('chain_id' => 'x')) === null, 'nor does a manifest with no envelope');

section('Staging the key: this machine\'s own, or the recovery key\'s');

$have_site_key = is_file(BackupEnvelope::site_key_path()) && is_readable(BackupEnvelope::site_key_path());
if (!$have_site_key) {
	check(true, 'SKIP: this machine has no readable backup key, so nothing can be opened with it');
} else {
	$ours = $manifest_of($envelope(array(
		array('kind' => 'recovery', 'pub' => $recovery_pub),
		array('kind' => 'site', 'pub' => BackupEnvelope::site_public_key()),
	)));
	check(BackupStaging::stage_key($ours, $work) === BackupStaging::KEY_SITE
			&& file_get_contents($work . '/' . BackupStaging::KEY_NAME) === $data_key,
		'a chain this machine made is staged with its key, recovered here');

	check(BackupStaging::stage_key($theirs, $work) === BackupStaging::KEY_RECOVERY,
		'a chain another server made is staged for the recovery key');
	check(!file_exists($work . '/' . BackupStaging::KEY_NAME),
		'and the key file left in the workspace is removed: the restore\'s key comes from one of the two');

	$threw = null;
	try {
		BackupStaging::stage_key($manifest_of($envelope(array(array('kind' => 'site', 'pub' => $other_server_pub)))), $work);
	} catch (BackupStagingException $e) { $threw = $e; }
	check($threw !== null && strpos($threw->getMessage(), 'different machine') !== false,
		'a chain sealed to no recovery key is refused, as before', $threw ? $threw->getMessage() : 'no refusal');
}

section('A matched manifest\'s artifacts are recorded before any is fetched');

$src = (string)file_get_contents(PathHelper::getIncludePath('includes/BackupStaging.php'));
$f = strpos($src, 'public static function fetch_manifest(');
$fetch = $f === false ? false : strpos($src, 'BackupFetch::fetch_artifact(', $f);
$record = $f === false ? false : strpos($src, 'BackupLedger::record_manifest_artifacts(', $f);
$end = $f === false ? false : strpos($src, 'public static function read_manifest(', $f);
check($fetch !== false && $record !== false && $end !== false && $fetch < $record && $record < $end,
	'fetch_manifest records them once the manifest\'s bytes have matched the ledger');
$stage = (string)file_get_contents(PathHelper::getIncludePath('utils/stage_chain.php'));
check(strpos($stage, 'BackupStaging::stage_key(') !== false && strpos($stage, 'write_chain_key(') === false
		&& strpos($stage, "'STAGE_KEY='") !== false,
	'Prepare stages the key with stage_key and says which it took (STAGE_KEY)');

harness_finish();
