<?php
/**
 * IncidentSourceBackupsStopped — plane:backups_stopped: backups from here are not happening: the
 * node has no verified recovery key, has never been backed up, its backups are not landing in
 * storage, or the last success is older than its schedule allows (incident_triage.md, Types;
 * WP3).
 *
 * Read from NodeMonitorHealth::fleet_backup_health() through
 * IncidentBackupVerdict, the judgement the dashboard's backup panel shows.
 *
 * A node younger than NEW_NODE_GRACE_SECONDS that holds no recovery key yet
 * opens nothing: only its own administrator can set one up, and a new site
 * has not had the chance. The dashboard's backup panel says so from the first
 * pass; the incident waits.
 *
 * @version 1.2 - the grace is two days: long enough for a new owner's first sign-in, short enough
 *                that an owner who skips the key is followed up within the week (easy_signup_completion WP3)
 * @version 1.1 - a node's first seven days without a recovery key open no incident
 * @version 1.0
 */
class IncidentSourceBackupsStopped implements IncidentSource {

	const NAME = 'plane:backups_stopped';

	public function name(): string {
		return self::NAME;
	}

	/** How long a new node may go without a recovery key before this opens. */
	const NEW_NODE_GRACE_SECONDS = 2 * 86400;

	public function evaluate(ManagedNode $node): ?array {
		$v = IncidentBackupVerdict::for_node($node);
		if ($v !== null && ($v['reason'] ?? '') === NodeMonitorHealth::NO_RECOVERY_KEY && self::is_new($node)) {
			return null;
		}
		return IncidentBackupVerdict::incident($node, 'stopped', IncidentRecord::SEVERITY_CRITICAL);
	}

	/** Whether the node is still inside its first NEW_NODE_GRACE_SECONDS. */
	public static function is_new(ManagedNode $node): bool {
		$born = strtotime((string)$node->get('mgn_create_time') . ' UTC');
		return $born !== false && time() - $born < self::NEW_NODE_GRACE_SECONDS;
	}

	public function cleared_text(ManagedNode $node): string {
		if (IncidentBackupVerdict::for_node($node) === null) {
			return 'This node is no longer backed up from here (its fleet backups were switched off, or it no longer hosts a site this management node backs up).';
		}
		return 'Backups from here are happening again.';
	}
}
?>
