<?php
/**
 * IncidentSourceMonitoringBroken — plane:monitoring_broken: the node's uptime
 * check cannot conclude whether the site is up (incident_triage.md,
 * Types; WP3).
 *
 * Read from NodeMonitorHealth::evaluate(), the verdict the dashboard's
 * monitoring panel and the node page show: misconfigured (nothing to probe,
 * or every probe inconclusive) or stale (no conclusive check for several
 * intervals). Monitoring that cannot conclude is how a down site goes unseen,
 * so it is its own incident. A warning: nothing is known to be broken for
 * visitors.
 *
 * @version 1.0
 */
class IncidentSourceMonitoringBroken implements IncidentSource {

	const NAME = 'plane:monitoring_broken';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$h = NodeMonitorHealth::evaluate($node);
		if (empty($h['is_problem'])) {
			return null;
		}
		return array(
			'title'    => 'Uptime monitoring cannot tell whether the site is up',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'State' => (string)$h['label'],
				'Why'   => (string)$h['detail'],
				'Fix'   => 'The node page\'s connection settings hold the check type and the address it probes.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		$h = NodeMonitorHealth::evaluate($node);
		return $h['state'] === NodeMonitorHealth::STATE_DISABLED
			? 'Uptime monitoring for this node is off, so there is nothing to conclude.'
			: 'Uptime monitoring concludes again.';
	}
}
?>
