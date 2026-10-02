<?php
/**
 * IncidentSourceFailedUnits — plane:failed_units: the node's latest host
 * report names failed systemd units (incident_triage.md, Types; WP3).
 *
 * Read from mgn_last_host_report through the same sanitiser the Host card
 * uses; unit names are node text, stored as evidence and escaped where shown.
 * Clears when a later report names none.
 *
 * @version 1.0
 */
class IncidentSourceFailedUnits implements IncidentSource {

	const NAME = 'plane:failed_units';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$units = self::units($node);
		if (count($units) === 0) {
			return null;
		}
		return array(
			'title'    => count($units) === 1 ? 'A service on the machine failed' : count($units) . ' services on the machine failed',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Failed units' => implode(', ', $units),
				'Where'        => 'The Health box on the node page shows each one, with Why? and Clear where the agent offers them.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The latest host report names no failed unit.';
	}

	/** The failed units the stored report names, as strings. */
	private static function units(ManagedNode $node): array {
		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report)) {
			return array();
		}
		$units = JobResultProcessor::sanitise_host_report($report)['failed_units'];
		return is_array($units) ? array_values(array_map('strval', $units)) : array();
	}
}
?>
