<?php
/**
 * ManagementNodeStatus — is this machine managed by a management node?
 *
 * The agent maintains the join state (agent_join_state); the web tier only ever
 * reads it. Its status is 'connected' exactly when a management node has this
 * machine's key and the join is live — the same fact the Management Node page
 * renders as "This site is managed by X". Any page that must behave differently
 * on a managed machine asks here rather than re-parsing the setting, so the
 * answer is defined in one place.
 *
 * "Managed" is deliberately the join being connected, not merely that an agent
 * is installed or switched on: a machine can run an agent while it stands alone,
 * and a machine can be mid-request or rejected without being managed by anyone.
 *
 * @version 1.0
 */
class ManagementNodeStatus {

	/** The decoded join state the agent maintains, or null if there is none. */
	public static function join_state(): ?array {
		$raw = Globalvars::get_instance()->get_setting('agent_join_state');
		$state = json_decode((string)$raw, true);
		return is_array($state) ? $state : null;
	}

	/** Is a management node currently responsible for this machine? */
	public static function is_managed(): bool {
		$state = self::join_state();
		return is_array($state) && (($state['status'] ?? '') === 'connected');
	}

	/**
	 * Does this site's management node take its backups? A managed site's
	 * usually does. One installed with manager_backups = none has no backup
	 * storage there (a site on its buyer's own cloud account, bought without
	 * backups), so its pages offer the owner the ordinary choice of where
	 * backups go. The installer's word is overtaken by evidence: a manager
	 * run that has landed here, or a backups service the site now rents.
	 */
	public static function backs_up_this_site(): bool {
		if (!self::is_managed()) {
			return false;
		}
		if ((string)Globalvars::get_instance()->get_setting('manager_backups', false, true) !== 'none') {
			return true;
		}
		try {
			require_once(PathHelper::getIncludePath('data/backup_history_class.php'));
			if (BackupHistory::manager_coverage() !== null) {
				return true;
			}
		} catch (\Throwable $e) {
			// No history to read is no evidence.
		}
		foreach (HostedPlanNotice::allowances() as $row) {
			if ((string)($row['service'] ?? '') === 'shelf') {
				return true;
			}
		}
		return false;
	}

	/** The managing node's URL, or '' when this machine is not managed. */
	public static function manager_url(): string {
		$state = self::join_state();
		return (is_array($state) && (($state['status'] ?? '') === 'connected'))
			? (string)($state['url'] ?? '')
			: '';
	}
}
