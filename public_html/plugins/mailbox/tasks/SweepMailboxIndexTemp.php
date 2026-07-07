<?php
/**
 * SweepMailboxIndexTemp - Scheduled Task
 *
 * Passive-close safety net for MailboxIndex's /dev/shm working copies
 * (specs/implemented/inbound_email_encryption_at_rest.md § 6.4). The wipe
 * callback (plugins/mailbox/includes/bootstrap.php) already deletes a user's
 * working copy on an explicit lock or credential event; this task catches
 * everything else that ends a window without firing that callback (APCu TTL
 * idle expiry, a php-fpm worker recycle) — worst case a working copy lingers
 * one cron interval before this sweeps it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class SweepMailboxIndexTemp implements ScheduledTaskInterface {

	public function run(array $config) {
		$files = glob('/dev/shm/mailfts_*.sqlite');
		if ($files === false || !count($files)) {
			return array('status' => 'success', 'message' => 'No mailbox index working copies found');
		}

		$swept = 0;
		foreach ($files as $path) {
			if (!preg_match('/mailfts_(\d+)\.sqlite$/', basename($path), $m)) {
				continue; // not one of ours — leave it alone
			}
			$user_id = (int)$m[1];
			if (VaultUnlock::hasAnyOpenWindow($user_id, UserEncryptionVault::SCOPE_USER)) {
				continue; // still in-window somewhere — not this task's to touch
			}
			if (@unlink($path)) {
				$swept++;
			}
		}

		if ($swept === 0) {
			return array('status' => 'success', 'message' => 'No orphaned mailbox index working copies to sweep');
		}
		return array('status' => 'success', 'message' => 'Swept ' . $swept . ' orphaned mailbox index working cop' . ($swept === 1 ? 'y' : 'ies'));
	}
}
?>
