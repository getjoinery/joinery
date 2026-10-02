<?php
/**
 * IncidentSourceAgentSilent — plane:agent_silent: a paired node's agent has
 * not checked in for two hours (incident_triage.md, Types; WP3).
 *
 * Read from mgn_agent_last_poll. An agent its owner switched off
 * (mgn_agent_quiet_time after its last check-in) is a decision, not an
 * outage, and opens nothing; nor does a node paired but never yet checked in.
 * Critical: nothing can be done on the node through its agent while it is
 * silent, and the agent's own repairs are not running.
 *
 * @version 1.0
 */
class IncidentSourceAgentSilent implements IncidentSource {

	const NAME = 'plane:agent_silent';

	const SILENT_AFTER = 7200;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return null;
		}
		$last = trim((string)$node->get('mgn_agent_last_poll'));
		if ($last === '' || self::switched_off($node)) {
			return null;
		}
		$ts = strtotime($last . ' UTC');
		if ($ts === false || time() - $ts < self::SILENT_AFTER) {
			return null;
		}
		return array(
			'title'    => 'The agent stopped checking in',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'Last check-in' => $last . ' UTC',
				'What it means' => 'Jobs for this node wait, and the agent\'s own checks and repairs are not reporting. '
					. 'The machine may be down, the agent stopped, or the network between them broken.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return 'The node is no longer paired, so its agent is no longer expected to check in.';
		}
		if (self::switched_off($node)) {
			return 'Its owner switched the agent off.';
		}
		return 'The agent checks in again.';
	}

	/** The owner switched the agent off after its last check-in. */
	private static function switched_off(ManagedNode $node): bool {
		$quiet = trim((string)$node->get('mgn_agent_quiet_time'));
		$last = trim((string)$node->get('mgn_agent_last_poll'));
		return $quiet !== '' && ($last === '' || strtotime($last . ' UTC') <= strtotime($quiet . ' UTC'));
	}
}
?>
