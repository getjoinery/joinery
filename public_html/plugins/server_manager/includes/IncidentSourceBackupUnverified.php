<?php
/**
 * IncidentSourceBackupUnverified — plane:backup_unverified: a backup was taken but is not proven
 * whole: the run was suspiciously small, a stored backup is incomplete, or verification failed, is
 * stale or has never run (incident_triage.md, Types; WP3).
 *
 * Read from NodeMonitorHealth::fleet_backup_health() through
 * IncidentBackupVerdict, the judgement the dashboard's backup panel shows.
 *
 * @version 1.0
 */
class IncidentSourceBackupUnverified implements IncidentSource {

	const NAME = 'plane:backup_unverified';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		return IncidentBackupVerdict::incident($node, 'unverified', IncidentRecord::SEVERITY_WARNING);
	}

	public function cleared_text(ManagedNode $node): string {
		if (IncidentBackupVerdict::for_node($node) === null) {
			return 'This node is no longer backed up from here (its fleet backups were switched off, or it no longer hosts a site this management node backs up).';
		}
		return 'The newest backup is whole and verified restorable again.';
	}
}
?>
