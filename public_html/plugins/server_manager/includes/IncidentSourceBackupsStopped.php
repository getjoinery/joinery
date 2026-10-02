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
 * @version 1.0
 */
class IncidentSourceBackupsStopped implements IncidentSource {

	const NAME = 'plane:backups_stopped';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		return IncidentBackupVerdict::incident($node, 'stopped', IncidentRecord::SEVERITY_CRITICAL);
	}

	public function cleared_text(ManagedNode $node): string {
		if (IncidentBackupVerdict::for_node($node) === null) {
			return 'This node is no longer backed up from here (its fleet backups were switched off, or it no longer hosts a site this management node backs up).';
		}
		return 'Backups from here are happening again.';
	}
}
?>
