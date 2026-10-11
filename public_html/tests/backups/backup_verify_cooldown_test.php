<?php
/** @joinery-test
 * name: backup_verify_cooldown
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The site's own scheduled verify keeps its distance from any other verify.
 *
 * A management node's verify and the site's read different backups from the same
 * storage; each alone stays under a provider's inbound-traffic alert, two in a row
 * do not. Every verify that read stamps the machine; the site's scheduled one
 * waits COOLDOWN_SECONDS after the stamp.
 *
 * Run: php tests/backups/backup_verify_cooldown_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tasks/BackupVerify.php'));

section('A finished verify holds the site schedule off');

$stamp = rtrim(BackupRunner::output_dir(), '/') . '/.jy_last_verify';
$had   = file_exists($stamp) ? filemtime($stamp) : null;
harness_defer(function () use ($stamp, $had) {
	if ($had === null) { @unlink($stamp); } else { @touch($stamp, $had); }
});

@unlink($stamp);
check(BackupVerifyLauncher::cooldown_left() === 0, 'no stamp: the site may verify');

BackupVerifyLauncher::note_verified();
$left = BackupVerifyLauncher::cooldown_left();
check($left > BackupVerifyLauncher::COOLDOWN_SECONDS - 60 && $left <= BackupVerifyLauncher::COOLDOWN_SECONDS,
	'just after a verify: it waits nearly the whole cooldown');
check(BackupVerifyLauncher::cooldown_left(time() + BackupVerifyLauncher::COOLDOWN_SECONDS + 1) === 0,
	'once the cooldown has passed: it may verify');

$r = (new BackupVerify())->run(array());
check(($r['status'] ?? '') === 'skipped', 'the scheduled task skips while the cooldown runs');

harness_finish();
