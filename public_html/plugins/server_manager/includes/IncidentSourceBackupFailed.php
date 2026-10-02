<?php
/**
 * IncidentSourceBackupFailed — plane:backup_failed: the last backup this management node ran of
 * the node failed (incident_triage.md, Types; WP3).
 *
 * Read from NodeMonitorHealth::fleet_backup_health() through
 * IncidentBackupVerdict, the judgement the dashboard's backup panel shows.
 *
 * @version 1.0
 */
class IncidentSourceBackupFailed implements IncidentSource {

	const NAME = 'plane:backup_failed';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		return IncidentBackupVerdict::incident($node, 'failed', IncidentRecord::SEVERITY_CRITICAL);
	}

	public function cleared_text(ManagedNode $node): string {
		if (IncidentBackupVerdict::for_node($node) === null) {
			return 'This node is no longer backed up from here (its fleet backups were switched off, or it no longer hosts a site this management node backs up).';
		}
		return 'A later backup run succeeds.';
	}
}
?>
