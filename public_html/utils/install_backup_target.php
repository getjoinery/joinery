#!/usr/bin/php
<?php
/**
 * install_backup_target.php — point a fresh site's backups at a bucket the
 * deployer named on the deploy form.
 *
 * The setup wizard's Backups step has two halves: where backups go, and the
 * recovery key that opens them. The first half is a bucket and a key pair,
 * which a first-boot installer can hold on the deployer's behalf; the second
 * is a secret shown once to a human and is never done here. This script does
 * exactly what the wizard's "Save and test" does — creates the target, fills a
 * Backblaze credential's region and endpoint from Backblaze's own answer,
 * tests the connection, and makes a first target the scheduled one — so the
 * wizard finds that half already done.
 *
 * A target whose test fails is removed again rather than left in place: the
 * wizard would otherwise show a bucket as "Set" with no way to correct it from
 * that screen. The failure is reported and the wizard asks fresh.
 *
 * Inputs arrive as environment variables, never on argv — a secret on argv is
 * visible to every process on the box:
 *
 *   JOINERY_BACKUP_BUCKET     required — the bucket name
 *   JOINERY_BACKUP_KEY_ID     required — access key id / Backblaze keyID
 *   JOINERY_BACKUP_KEY        required — secret key / Backblaze applicationKey
 *   JOINERY_BACKUP_PROVIDER   optional — b2 (default), or any provider in
 *                             StorageProvider's catalogue (s3, linode, r2, …)
 *   JOINERY_BACKUP_REGION     optional — needed where the provider asks for
 *                             it; Backblaze is asked for its own
 *   JOINERY_BACKUP_ENDPOINT   optional — derived from the region when the
 *                             provider names its endpoint that way
 *
 * Prints INSTALL_BACKUP_TARGET=ok or =error as the first line, then key=value
 * lines (reason= on error). Exits 0 on success, 2 on unusable input, 1 when
 * the bucket could not be reached or the write failed. A site that already
 * has a scheduled target is left alone and reports ok with already=1.
 *
 * @version 1.1 - the providers, and how each names its endpoint, are StorageProvider's catalogue
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

function install_backup_target_fail(string $reason, int $code): void {
	echo "INSTALL_BACKUP_TARGET=error\n";
	echo 'reason=' . str_replace(array("\r", "\n"), ' ', $reason) . "\n";
	exit($code);
}

$provider = strtolower(trim((string)getenv('JOINERY_BACKUP_PROVIDER')));
if ($provider === '') {
	$provider = 'b2';
}
$bucket   = trim((string)getenv('JOINERY_BACKUP_BUCKET'));
$key_id   = trim((string)getenv('JOINERY_BACKUP_KEY_ID'));
$key      = trim((string)getenv('JOINERY_BACKUP_KEY'));
$region   = trim((string)getenv('JOINERY_BACKUP_REGION'));
$endpoint = trim((string)getenv('JOINERY_BACKUP_ENDPOINT'));

if (!StorageProvider::known($provider)) {
	install_backup_target_fail("'$provider' is not a backup provider this site knows (" . implode(', ', StorageProvider::slugs()) . ').', 2);
}
if ($bucket === '' || $key_id === '' || $key === '') {
	install_backup_target_fail('JOINERY_BACKUP_BUCKET, JOINERY_BACKUP_KEY_ID and JOINERY_BACKUP_KEY are all required.', 2);
}
// What the provider asks for must be here. The rest is filled from the catalogue
// by BackupTarget::complete_credentials; Backblaze is asked for its own.
foreach (StorageProvider::asks($provider) as $asked) {
	if (($asked === 'region' && $region === '') || ($asked === 'endpoint' && $endpoint === '')) {
		install_backup_target_fail('JOINERY_BACKUP_' . strtoupper($asked) . ' is required for ' . StorageProvider::label($provider) . '.', 2);
	}
}

$settings = Globalvars::get_instance();

// A scheduled target already exists: this is a re-run, or an install that
// was already configured by hand. Nothing to do, and nothing to disturb.
$scheduled_id = (int)$settings->get_setting('backup_target_id');
if ($scheduled_id > 0) {
	$scheduled = new BackupTarget($scheduled_id, TRUE);
	if ($scheduled->key && !$scheduled->get('bkt_delete_time')) {
		echo "INSTALL_BACKUP_TARGET=ok\n";
		echo "already=1\n";
		echo 'provider=' . $scheduled->get('bkt_provider') . "\n";
		echo 'bucket=' . $scheduled->get('bkt_bucket') . "\n";
		exit(0);
	}
}

try {
	$completed = BackupTarget::complete_credentials($provider, array(
		'access_key' => $key_id,
		'secret_key' => $key,
		'region'     => $region,
		'endpoint'   => $endpoint,
	));
	$target = new BackupTarget(NULL);
	$target->set('bkt_name', 'Backups');
	$target->set('bkt_provider', $provider);
	$target->set('bkt_bucket', $bucket);
	$target->set('bkt_path_prefix', 'joinery-backups');
	$target->set('bkt_credentials', $completed['creds']);
	$target->set('bkt_enabled', true);
	$target->save();
} catch (Throwable $e) {
	install_backup_target_fail('Could not save the target: ' . $e->getMessage(), 1);
}

// The same probe the wizard's "Save and test" runs. A bucket that cannot be
// listed is not a place backups can go, and a target that fails is removed
// so the wizard asks for one afresh instead of showing this one as set.
$test = TargetTester::test($target);
if (empty($test['success'])) {
	try {
		$target->soft_delete();
	} catch (Throwable $e) {
		// The failure being reported is the test's; a cleanup miss is logged.
		error_log('install_backup_target: could not remove the failed target ' . (int)$target->key . ': ' . $e->getMessage());
	}
	$reason = (string)($test['message'] ?? 'the connection test failed');
	if ($completed['note'] !== '') {
		$reason = $completed['note'] . ' ' . $reason;
	}
	install_backup_target_fail($reason, 1);
}

// One-go, as the wizard does it: a first target becomes the scheduled target,
// and nightly runs switch themselves on once the recovery key is proven — the
// key is the human half, so nothing activates here yet.
try {
	Setting::put('backup_target_id', (string)(int)$target->key);
	BackupNightly::maybe_activate();
} catch (Throwable $e) {
	install_backup_target_fail('The target was saved and tested but could not be made the scheduled one: ' . $e->getMessage(), 1);
}

echo "INSTALL_BACKUP_TARGET=ok\n";
echo 'target_id=' . (int)$target->key . "\n";
echo 'provider=' . $provider . "\n";
echo 'bucket=' . $bucket . "\n";
exit(0);
