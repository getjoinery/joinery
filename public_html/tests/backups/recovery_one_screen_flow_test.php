<?php
/** @joinery-test
 * name: recovery_one_screen_flow
 * tier: test-db
 * env: any
 * needs: [test-db]
 */
/**
 * The one-screen recovery setup, server side: the sequence the panel's JS
 * drives through backup_recovery_save / backup_recovery_prove. The key is
 * stored unproven, the challenge is sealed to what was STORED, only the
 * matching private key opens it, and the recovered sentence — nothing else —
 * flips the key to proven. PHP stands in for WebCrypto here with the same
 * X25519 + HKDF-SHA256 + AES-256-GCM steps, so a drift in the challenge
 * layout fails in this file rather than in every operator's browser.
 *
 * It carries the consequence too: the key proven here is what every backup of
 * this machine seals to, a control plane's copies included, and withdrawing the
 * proof stops backups rather than downgrading them.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();

function rof_put_setting($name, $value) {
	global $db;
	$q = $db->prepare('UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?');
	$q->execute(array($value, $name));
	if ($q->rowCount() === 0) {
		$q = $db->prepare('INSERT INTO stg_settings (stg_name, stg_value, stg_group_name) VALUES (?, ?, ?)');
		$q->execute(array($name, $value, 'backups'));
	}
}

/** Open a browser_challenge blob exactly as recovery-readiness.js does. */
function rof_open_browser_challenge($challenge_b64, $priv_raw, $pub_raw) {
	$blob   = base64_decode($challenge_b64, true);
	$eph_pk = substr($blob, 0, 32);
	$iv     = substr($blob, 32, 12);
	$ct     = substr($blob, 44, strlen($blob) - 44 - 16);
	$tag    = substr($blob, -16);

	$shared  = sodium_crypto_scalarmult($priv_raw, $eph_pk);
	$aes_key = hash_hkdf('sha256', $shared, 32, BackupRecoveryKey::BROWSER_INFO . $eph_pk . $pub_raw, '');
	return openssl_decrypt($ct, 'aes-256-gcm', $aes_key, OPENSSL_RAW_DATA, $iv, $tag);
}

rof_put_setting('backup_recovery_public_key', '');
rof_put_setting('backup_recovery_public_key_proven_fpr', '');

$keypair  = sodium_crypto_box_keypair();
$priv_raw = sodium_crypto_box_secretkey($keypair);
$pub_raw  = sodium_crypto_box_publickey($keypair);

// ── Save ────────────────────────────────────────────────────────────────
section('Saving stores the key unproven');

BackupRecoveryKey::set_public_key(base64_encode($pub_raw));
$state = BackupRecoveryKey::setup_state();
check($state['state'] === 'unproven', 'stored key is unproven', $state['state']);
check(BackupRecoveryKey::is_ready() === false, 'nothing may be sealed to it yet');

$stored_pub = BackupRecoveryKey::parse_public_key();
check($stored_pub === $pub_raw, 'what was stored is byte-identical to what was sent');

// ── The challenge round trip ────────────────────────────────────────────
section('The challenge opens only with the matching private key');

$challenge = BackupRecoveryKey::browser_challenge();

$wrong = sodium_crypto_box_keypair();
$bad = rof_open_browser_challenge($challenge, sodium_crypto_box_secretkey($wrong), $pub_raw);
check($bad === false, 'a different private key does not open it');

$recovered = rof_open_browser_challenge($challenge, $priv_raw, $pub_raw);
check($recovered === BackupRecoveryKey::expected_proof_string(),
	'the right key recovers the exact proof sentence');

// ── Prove ───────────────────────────────────────────────────────────────
section('Only the recovered sentence proves possession');

$threw = false;
try { BackupRecoveryKey::record_possession_proof('not the sentence'); } catch (BackupRecoveryKeyException $e) { $threw = true; }
check($threw, 'a wrong proof is refused');
check(BackupRecoveryKey::is_ready() === false, 'and the key stays unproven');

BackupRecoveryKey::record_possession_proof($recovered);
check(BackupRecoveryKey::is_ready() === true, 'the recovered sentence flips the key to proven');

// ── What the proven key is for ──────────────────────────────────────────
section('Every backup taken here seals to the key proven here');

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

/** A control plane's backup of this site: a shelf, a credential, no key. */
function rof_manager_config() {
	return array('profile' => 'manager', 'manager' => array(
		'bucket'      => 'a-bucket',
		'credentials' => array('key_id' => 'x', 'application_key' => 'y'),
		'slug'        => 'demo',
	));
}

$plan = BackupRunner::plan(rof_manager_config());
check($plan['recovery_fpr'] === hash('sha256', $pub_raw),
	'a control plane\'s copy of this site seals to THIS site\'s proven key, not to one it supplied',
	$plan['recovery_fpr']);
check($plan['recipients'][0]['kind'] === 'recovery'
	&& $plan['recipients'][0]['pub'] === $pub_raw,
	'the recovery recipient is the key this site holds');

// ── No proven key means no backup, for anybody ──────────────────────────
section('A site with no proven key refuses to back up rather than downgrading');

// Unproven is the dangerous state, not merely the unfinished one: the value
// looks like a key and seals like a key, and only the ceremony distinguishes it
// from one nobody can open. So the key stays configured here and only its proof
// is withdrawn.
rof_put_setting('backup_recovery_public_key_proven_fpr', '');

$message = '';
try { BackupRunner::plan(rof_manager_config()); }
catch (Throwable $e) { $message = $e->getMessage(); }
check(strpos($message, 'no proven recovery key') !== false,
	'an unproven key refuses the run and names the reason', $message);
check(strpos($message, 'No control plane can supply this') !== false,
	'and says the fix is here, not at whoever asked for the backup', $message);

rof_put_setting('backup_recovery_public_key', '');
$message = '';
try { BackupRunner::plan(rof_manager_config()); }
catch (Throwable $e) { $message = $e->getMessage(); }
check(strpos($message, 'no proven recovery key') !== false,
	'and so does no key at all — never a quiet unencrypted copy on somebody else\'s shelf', $message);

// ── Cleanup ─────────────────────────────────────────────────────────────
rof_put_setting('backup_recovery_public_key', '');
rof_put_setting('backup_recovery_public_key_proven_fpr', '');

harness_finish();
