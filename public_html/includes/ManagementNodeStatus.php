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

	/** The managing node's URL, or '' when this machine is not managed. */
	public static function manager_url(): string {
		$state = self::join_state();
		return (is_array($state) && (($state['status'] ?? '') === 'connected'))
			? (string)($state['url'] ?? '')
			: '';
	}
}
