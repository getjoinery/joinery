<?php
/** @joinery-test
 * name: backup_keys_report
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * Every backup key's kind, from the node to the fleet page: what
 * set_recovery_key.php 2.2 prints after its RECOVERY_KEY= line
 * (BackupRecoveryKey::keys_report_lines()), what the management node reads back
 * (JobResultProcessor::parse_backup_keys_tokens()), and the verdict the
 * Targets page shows (RecoveryKeyFleet::keys_verdict()). Only names and
 * counts travel, never key material.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('A recovery key\'s kind');
$xwing_pub = base64_encode((new XWing())->publicKey(random_bytes(32)));
check(BackupRecoveryKey::key_kind($xwing_pub) === 'xwing', 'a 1216-byte key is post-quantum');
check(BackupRecoveryKey::key_kind(base64_encode(random_bytes(32))) === 'x25519', 'a 32-byte key is the old kind, not invalid');
check(BackupRecoveryKey::key_kind('') === 'none', 'nothing set');
check(BackupRecoveryKey::key_kind('not base64 !!') === 'invalid', 'not a key');

section('The machine\'s own key, and the retired ones kept beside it');
$dir = sys_get_temp_dir() . '/joinery-keys-report-' . getmypid();
@mkdir($dir . '/backups', 0700, true);
$path = $dir . '/backup_site_key';
file_put_contents($path, base64_encode(random_bytes(32)));
file_put_contents($path . '.retired', base64_encode(sodium_crypto_box_keypair()));
$r = BackupRecoveryKey::keys_report($path, $dir . '/backups');
check($r['site_kind'] === 'xwing' && $r['site_retired'] === 1, 'a seed with one retired keypair kept', json_encode($r));
check($r['old_epochs'] === null, 'no backup run has looked for old epochs: unknown, not zero');
file_put_contents($path, base64_encode(sodium_crypto_box_keypair()));
check(BackupRecoveryKey::keys_report($path, $dir . '/backups')['site_kind'] === 'x25519', 'a keypair not yet moved aside is the old kind');
array_map('unlink', glob($dir . '/backup_site_key*'));
check(BackupRecoveryKey::keys_report($path, $dir . '/backups')['site_kind'] === 'none', 'no key file: none');

section('The lines, read back by the management node');
$lines = BackupRecoveryKey::keys_report_lines(array('recovery_kind' => 'xwing', 'site_kind' => 'xwing', 'site_retired' => 1, 'old_epochs' => 0));
check($lines === array('RECOVERY_KEY_KIND=xwing', 'BACKUP_SITE_KEY=xwing retired=1', 'BACKUP_OLD_EPOCHS=0'), 'three lines', json_encode($lines));
$output = "RECOVERY_KEY=already fpr=" . str_repeat('a', 64) . " proven=1\n" . implode("\n", $lines) . "\n";
check(JobResultProcessor::parse_backup_keys_tokens($output) === array('backup_recovery_kind' => 'xwing',
		'backup_site_key' => 'xwing', 'backup_site_key_retired' => 1, 'backup_old_epochs' => 0),
	'parsed into the status blob\'s keys');
$unknown = implode("\n", BackupRecoveryKey::keys_report_lines(array('recovery_kind' => 'x25519', 'site_kind' => 'none', 'site_retired' => 0, 'old_epochs' => null)));
$parsed = JobResultProcessor::parse_backup_keys_tokens($unknown);
check(array_key_exists('backup_old_epochs', $parsed) && $parsed['backup_old_epochs'] === null && $parsed['backup_recovery_kind'] === 'x25519',
	'unknown old epochs read as null; the old kind survives the trip', json_encode($parsed));
check(JobResultProcessor::parse_backup_keys_tokens("RECOVERY_KEY=already fpr=" . str_repeat('a', 64) . " proven=1\n") === array(),
	'a node on an older release reports none of them, so nothing is recorded as measured');
check(JobResultProcessor::parse_backup_keys_tokens("BACKUP_SITE_KEY=xwing retired=1 pub=AAAA\nRECOVERY_KEY_KIND=xwing; rm\n") === array(),
	'a line with anything after its fields is not taken');

section('The verdict the Targets page shows');
check(RecoveryKeyFleet::keys_verdict('xwing', 'xwing', 1, 0)['state'] === 'current', 'all post-quantum and no old epochs: current');
check(RecoveryKeyFleet::keys_verdict('xwing', 'xwing', 0, null)['state'] === 'pending', 'no run has looked since: pending');
$old = RecoveryKeyFleet::keys_verdict('x25519', 'xwing', 0, 0);
check($old['state'] === 'attention' && strpos($old['summary'], 'rotated') !== false, 'an old recovery key needs attention, and says where it is rotated', $old['summary']);
$epochs = RecoveryKeyFleet::keys_verdict('xwing', 'xwing', 1, 2);
check($epochs['state'] === 'attention' && strpos($epochs['summary'], '2 epochs') !== false, 'old epochs need attention, with their count', $epochs['summary']);
check(RecoveryKeyFleet::keys_verdict('xwing', 'x25519', 0, 0)['state'] === 'attention', 'an old site key needs attention');

@rmdir($dir . '/backups');
@rmdir($dir);
harness_finish();
