<?php
/** @joinery-test
 * name: backup_keys_report
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * Every backup key's kind, from the node to the fleet page: what
 * set_recovery_key.php prints after its RECOVERY_KEY= line
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
check(BackupRecoveryKey::key_kind(base64_encode(random_bytes(32))) === 'invalid', 'a 32-byte X25519 key is malformed');
check(BackupRecoveryKey::key_kind('') === 'none', 'nothing set');
check(BackupRecoveryKey::key_kind('not base64 !!') === 'invalid', 'not a key');

section('The machine\'s own key');
$dir = sys_get_temp_dir() . '/joinery-keys-report-' . getmypid();
@mkdir($dir . '/backups', 0700, true);
$path = $dir . '/backup_site_key';
file_put_contents($path, base64_encode(random_bytes(32)));
$r = BackupRecoveryKey::keys_report($path);
check($r === array('recovery_kind' => $r['recovery_kind'], 'site_kind' => 'xwing'), 'a seed is post-quantum, and only the two kinds are reported', json_encode($r));
file_put_contents($path, base64_encode(sodium_crypto_box_keypair()));
check(BackupRecoveryKey::keys_report($path)['site_kind'] === 'invalid', 'an X25519 keypair is malformed');
array_map('unlink', glob($dir . '/backup_site_key*'));
check(BackupRecoveryKey::keys_report($path)['site_kind'] === 'none', 'no key file: none');

section('The lines, read back by the management node');
$lines = BackupRecoveryKey::keys_report_lines(array('recovery_kind' => 'xwing', 'site_kind' => 'xwing'));
check($lines === array('RECOVERY_KEY_KIND=xwing', 'BACKUP_SITE_KEY=xwing'), 'two lines', json_encode($lines));
$output = "RECOVERY_KEY=already fpr=" . str_repeat('a', 64) . " proven=1\n" . implode("\n", $lines) . "\n";
check(JobResultProcessor::parse_backup_keys_tokens($output) === array('backup_recovery_kind' => 'xwing', 'backup_site_key' => 'xwing'),
	'parsed into the status blob\'s keys');
check(JobResultProcessor::parse_backup_keys_tokens("RECOVERY_KEY=already fpr=" . str_repeat('a', 64) . " proven=1\n") === array(),
	'a node on an older release reports none of them, so nothing is recorded as measured');
check(JobResultProcessor::parse_backup_keys_tokens("BACKUP_SITE_KEY=xwing retired=1 pub=AAAA\nRECOVERY_KEY_KIND=xwing; rm\n") === array(),
	'a line with anything after its fields is not taken');

section('The verdict the Targets page shows');
check(RecoveryKeyFleet::keys_verdict('xwing', 'xwing')['state'] === 'current', 'both post-quantum: current');
$none = RecoveryKeyFleet::keys_verdict('none', 'xwing');
check($none['state'] === 'attention' && strpos($none['summary'], 'recovery key') !== false, 'no recovery key needs attention', $none['summary']);
$unreadable = RecoveryKeyFleet::keys_verdict('xwing', 'unreadable');
check($unreadable['state'] === 'attention' && strpos($unreadable['summary'], 'unreadable') !== false, 'an unreadable site key needs attention', $unreadable['summary']);

@rmdir($dir . '/backups');
@rmdir($dir);
harness_finish();
