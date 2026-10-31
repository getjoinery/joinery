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
 * flips the key to proven. The challenge is an X-Wing v2 seal under the
 * browser proof's own scope (BackupRecoveryKey::BROWSER_INFO), opened here
 * with SealedBox::openV2, so a drift in the challenge layout fails in this file.
 *
 * It carries the consequence too: the key proven here is what every backup of
 * this machine seals to, a management node's copies included, and withdrawing the
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

/** Open a browser_challenge blob: a v2 seal under the browser scope. False when the seed does not open it. */
function rof_open_browser_challenge($challenge_b64, $seed) {
	try {
		return SealedBox::openV2(base64_decode($challenge_b64, true), $seed, BackupRecoveryKey::BROWSER_INFO);
	} catch (RuntimeException $e) {
		return false;
	}
}

rof_put_setting('backup_recovery_public_key', '');
rof_put_setting('backup_recovery_public_key_proven_fpr', '');

$priv_raw = random_bytes(32);   // an X-Wing seed
$pub_raw  = (new XWing())->publicKey($priv_raw);

// ── Save ────────────────────────────────────────────────────────────────
section('Saving stores the key unproven');

BackupRecoveryKey::set_public_key(base64_encode($pub_raw));
$state = BackupRecoveryKey::setup_state();
check($state['state'] === 'unproven', 'stored key is unproven', $state['state']);
check(BackupRecoveryKey::is_ready() === false, 'nothing may be sealed to it yet');

$stored_pub = BackupRecoveryKey::parse_public_key();
check($stored_pub === $pub_raw, 'what was stored is byte-identical to what was sent');

rof_put_setting('backup_recovery_public_key', '');
$message = '';
try { BackupRecoveryKey::set_public_key(base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()))); }
catch (BackupRecoveryKeyException $e) { $message = $e->getMessage(); }
check(strpos($message, 'old X25519') !== false, 'an old 32-byte X25519 key is refused, naming it as old', $message);
BackupRecoveryKey::set_public_key(base64_encode($pub_raw));

// ── The challenge round trip ────────────────────────────────────────────
section('The challenge opens only with the matching private key');

$challenge = BackupRecoveryKey::browser_challenge();

$bad = rof_open_browser_challenge($challenge, random_bytes(32));
check($bad === false, 'a different private key does not open it');

$cli_challenge = BackupRecoveryKey::possession_challenge();
check(rof_open_browser_challenge($cli_challenge, $priv_raw) === false
		&& SealedBox::openV2(base64_decode($cli_challenge), $priv_raw, BackupRecoveryKey::CLI_INFO) === BackupRecoveryKey::expected_proof_string(),
	'the CLI challenge opens under its own scope only, never as the browser one');
$as_dek = null;
try { $as_dek = SealedBox::openV2(base64_decode($cli_challenge), $priv_raw); } catch (RuntimeException $e) { $as_dek = false; }
check($as_dek === false, 'nor as a key seal (dek): a page cannot pass a backup\'s data key off as the challenge');

// The command the page prints, run as the operator runs it.
$kf = tempnam(sys_get_temp_dir(), 'rof');
file_put_contents($kf, base64_encode($priv_raw));
$cmd = BackupRecoveryKey::cli_unseal_command($kf);
$printed = trim((string)shell_exec($cmd . ' 2>/dev/null'));
@unlink($kf);
check($printed === BackupRecoveryKey::expected_proof_string(), 'the printed command, with the key file, prints the proof sentence', $printed);

$recovered = rof_open_browser_challenge($challenge, $priv_raw);
check($recovered === BackupRecoveryKey::expected_proof_string(),
	'the right key recovers the exact proof sentence');

// ── Prove ───────────────────────────────────────────────────────────────
section('Only the recovered sentence proves possession');

$threw = false;
try { BackupRecoveryKey::record_possession_proof('not the sentence'); } catch (BackupRecoveryKeyException $e) { $threw = true; }
check($threw, 'a wrong proof is refused');
check(BackupRecoveryKey::is_ready() === false, 'and the key stays unproven');

// Setup's proof is the readiness ceremony's own check, so it is recorded as
// one: a key proven at setup is never shown as "never proven" (B177).
$rof_owner = make_user('rof_owner', 10);
$rof_last = function () use ($db) {
	return (int)$db->query("SELECT coalesce(max(rcv_recovery_verification_id), 0) FROM rcv_recovery_verifications")->fetchColumn();
};
$rof_before = $rof_last();
$threw = false;
try { BackupRecoveryKey::prove_for('not the sentence', (int)$rof_owner->key); } catch (BackupRecoveryKeyException $e) { $threw = true; }
check($threw && $rof_last() === $rof_before, 'a wrong proof at setup records no ceremony');

BackupRecoveryKey::prove_for($recovered, (int)$rof_owner->key);
check(BackupRecoveryKey::is_ready() === true, 'the recovered sentence flips the key to proven');
$rof_row = new RecoveryVerification($rof_last(), TRUE);
harness_register_model('RecoveryVerification', $rof_row->key);
check($rof_row->key > $rof_before && $rof_row->get('rcv_item_key') === 'backup_recovery_key'
	&& $rof_row->get('rcv_method') === RecoveryVerification::METHOD_CEREMONY && $rof_row->get('rcv_passed')
	&& (int)$rof_row->get('rcv_usr_user_id') === (int)$rof_owner->key,
	'and it is recorded as a passed recovery-key ceremony, by the person who proved it');
check(isset(RecoveryVerification::latest_passed(array('backup_recovery_key'))['backup_recovery_key']),
	'so the Backups page reads it as last proven');

// ── What the proven key is for ──────────────────────────────────────────
section('Every backup taken here seals to the key proven here');

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

/** A management node's backup of this site: a shelf, a credential, no key. */
function rof_manager_config() {
	return array('profile' => 'manager', 'manager' => array(
		'bucket'      => 'a-bucket',
		'credentials' => array('key_id' => 'x', 'application_key' => 'y'),
		'slug'        => 'demo',
	));
}

$plan = BackupRunner::plan(rof_manager_config());
check($plan['recovery_fpr'] === hash('sha256', $pub_raw),
	'a management node\'s copy of this site seals to THIS site\'s proven key, not to one it supplied',
	$plan['recovery_fpr']);
check($plan['recipients'][0]['kind'] === 'recovery'
	&& $plan['recipients'][0]['pub'] === $pub_raw,
	'the recovery recipient is the key this site holds');

// ── Only a deliberate rotation replaces a proven key ────────────────────
section('Replacing a proven key is refused unless marked as rotation');

$pub_b  = (new XWing())->publicKey(random_bytes(32));

$message = '';
try { BackupRecoveryKey::set_public_key(base64_encode($pub_b)); }
catch (Throwable $e) { $message = $e->getMessage(); }
check(strpos($message, 'rotation') !== false && strpos($message, 'Rotate key') !== false,
	'pasting over a proven key is refused and names the rotation action', $message);
check(BackupRecoveryKey::parse_public_key() === $pub_raw,
	'the proven key is untouched by the refused attempt');

BackupRecoveryKey::set_public_key(base64_encode($pub_b), true);
check(BackupRecoveryKey::parse_public_key() === $pub_b,
	'a rotation marked as such stores the new key');
check(BackupRecoveryKey::setup_state()['state'] === 'unproven',
	'and stores it UNPROVEN — nothing seals to the new key until the same ceremony proves it',
	BackupRecoveryKey::setup_state()['state']);

// Restore the proven original for the sections below.
rof_put_setting('backup_recovery_public_key', base64_encode($pub_raw));
rof_put_setting('backup_recovery_public_key_proven_fpr', hash('sha256', $pub_raw));

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
check(strpos($message, 'No management node can supply this') !== false,
	'and says the fix is here, not at whoever asked for the backup', $message);

rof_put_setting('backup_recovery_public_key', '');
$message = '';
try { BackupRunner::plan(rof_manager_config()); }
catch (Throwable $e) { $message = $e->getMessage(); }
check(strpos($message, 'no proven recovery key') !== false,
	'and so does no key at all — never a quiet unencrypted copy in somebody else\'s backup storage', $message);

// ── Cleanup ─────────────────────────────────────────────────────────────
rof_put_setting('backup_recovery_public_key', '');
rof_put_setting('backup_recovery_public_key_proven_fpr', '');

harness_finish();
