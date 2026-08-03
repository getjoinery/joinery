<?php
/** @joinery-test
 * name: backup_target_encryption
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * BackupTarget — credentials encrypted at rest.
 *
 * The property under test: cloud-target credentials are sealed with SecretBox
 * before they hit the jsonb column, get_credentials() transparently unseals
 * them, and a legacy plaintext credential object still reads back (and seals on
 * the next save). The raw stored value never holds a plaintext secret.
 *
 * A throwaway target row is created and permanently removed in cleanup; real
 * configured targets are untouched.
 *
 * Run: php plugins/server_manager/tests/backup_target_encryption_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

$db = DbConnector::get_instance()->get_db_link();

// Is a secret_box_key configured on this deployment? Without one, encryption
// falls back to plaintext by design — the round-trip must still hold.
$has_secretbox = true;
try { new SecretBox(); } catch (\Throwable $e) { $has_secretbox = false; }

/** Read the raw jsonb column, bypassing the model's decryption. */
function raw_creds($db, $id) {
	$stmt = $db->prepare('SELECT bkt_credentials FROM bkt_backup_targets WHERE bkt_id = ?');
	$stmt->execute(array($id));
	return (string)$stmt->fetchColumn();
}

$SECRET = 'sk_live_9Z8y7x_topsecret_value';
$created_id = null;

try {

	// -----------------------------------------------------------------------
	section('save seals credentials; get_credentials unseals');
	// -----------------------------------------------------------------------

	$plain = array(
		'access_key' => 'AKIA_PUBLIC',
		'secret_key' => $SECRET,
		'region'     => 'us-west-002',
		'endpoint'   => 'https://s3.us-west-002.backblazeb2.com',
	);

	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'zz-encryption-test-target');
	$t->set('bkt_provider', 's3');
	$t->set('bkt_bucket', 'zz-test-bucket');
	$t->set('bkt_credentials', json_encode($plain));
	$t->save();
	$t->load();
	$created_id = (int)$t->key;

	check($created_id > 0, 'target row created');

	// Reload fresh from the DB and round-trip the credentials.
	$reloaded = new BackupTarget($created_id, TRUE);
	$got = $reloaded->get_credentials();
	check($got['secret_key'] === $SECRET, 'secret_key round-trips through get_credentials');
	check($got['access_key'] === 'AKIA_PUBLIC', 'access_key round-trips');
	check($got['region'] === 'us-west-002', 'region round-trips');

	$raw = raw_creds($db, $created_id);
	check(strpos($raw, $SECRET) === false, 'raw stored value holds no plaintext secret');

	if ($has_secretbox) {
		$decoded = json_decode($raw, true);
		check(is_array($decoded) && isset($decoded['enc']), 'raw value is the sealed {enc:...} shape');
		check(SecretBox::looksEncrypted($decoded['enc'] ?? ''), 'sealed blob is a SecretBox ciphertext');
	} else {
		harness_skip('raw value is the sealed {enc:...} shape',
			'no secret_box_key on this install — plaintext fallback (round-trip verified above)');
	}

	// -----------------------------------------------------------------------
	section('re-saving an already-sealed row does not double-seal');
	// -----------------------------------------------------------------------

	$reloaded->set('bkt_bucket', 'zz-test-bucket-2');
	$reloaded->save();
	$again = new BackupTarget($created_id, TRUE);
	check($again->get_credentials()['secret_key'] === $SECRET, 'creds still unseal after an unrelated re-save');
	if ($has_secretbox) {
		$decoded = json_decode(raw_creds($db, $created_id), true);
		check(isset($decoded['enc']) && !isset($decoded['secret_key']),
			'still a single seal layer (no nested enc, no exposed plaintext)');
	} else {
		harness_skip('still a single seal layer (no nested enc, no exposed plaintext)',
			'no secret_box_key on this install');
	}

	// -----------------------------------------------------------------------
	section('legacy plaintext row migrates on next save');
	// -----------------------------------------------------------------------

	// Simulate a pre-encryption row: write plaintext creds straight to the column.
	$legacy = json_encode(array('access_key' => 'LEG_PUB', 'secret_key' => 'legacy_secret_42'));
	$db->prepare('UPDATE bkt_backup_targets SET bkt_credentials = ?::jsonb WHERE bkt_id = ?')
	   ->execute(array($legacy, $created_id));

	$legacy_read = new BackupTarget($created_id, TRUE);
	check($legacy_read->get_credentials()['secret_key'] === 'legacy_secret_42',
		'legacy plaintext creds read back unchanged');

	$legacy_read->save();
	$raw_after = raw_creds($db, $created_id);
	if ($has_secretbox) {
		check(strpos($raw_after, 'legacy_secret_42') === false, 'legacy secret sealed at rest after migration save');
	} else {
		check(strpos($raw_after, 'legacy_secret_42') !== false,
			'without a secret_box_key the legacy value stays plaintext by design');
	}
	$migrated = new BackupTarget($created_id, TRUE);
	check($migrated->get_credentials()['secret_key'] === 'legacy_secret_42',
		'creds still readable after migration save');

	section('an undecryptable sealed value fails loud');

	// A sealed blob that cannot be opened (tampered, or the secret_box_key is
	// gone) must throw — returning [] would surface later as a baffling
	// "missing access_key" job failure while the real cause stays invisible.
	$broken = new BackupTarget($created_id, TRUE);
	$broken->set('bkt_credentials', json_encode(array('enc' => 'v1.sodium.dGFtcGVyZWQtbm90LXJlYWwtY2lwaGVydGV4dA')));
	$broken->save(); // looks_sealed → save leaves it alone
	$broken_read = new BackupTarget($created_id, TRUE);
	$threw = false;
	try { $broken_read->get_credentials(); }
	catch (BackupTargetException $e) { $threw = true; }
	check($threw, 'get_credentials throws on an unopenable sealed value (never a silent [])');

} finally {
	if ($created_id) {
		try {
			$doomed = new BackupTarget($created_id, TRUE);
			if ($doomed->key) { $doomed->permanent_delete(); }
		} catch (\Throwable $e) {
			$db->prepare('DELETE FROM bkt_backup_targets WHERE bkt_id = ?')->execute(array($created_id));
		}
	}
}

harness_finish();
