<?php
/**
 * IncidentSourceAgentSilent — plane:agent_silent: a paired node's agent has
 * not checked in for two hours (incident_triage.md, Types; WP3).
 *
 * Read from mgn_agent_last_poll. A node this plane is deleting
 * (mgn_agent_quiet_time after its last check-in) is silent on purpose and
 * opens nothing; nor does a node paired but never yet checked in.
 * Critical: nothing can be done on the node through its agent while it is
 * silent, and the agent's own repairs are not running.
 *
 * @version 1.1 - the deliberate silence is a node being deleted; no agent is switched off by its owner
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
		if ($last === '' || self::being_deleted($node)) {
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
		if (self::being_deleted($node)) {
			return 'The node is being deleted.';
		}
		return 'The agent checks in again.';
	}

	/** This plane started deleting the node after its last check-in. */
	private static function being_deleted(ManagedNode $node): bool {
		$quiet = trim((string)$node->get('mgn_agent_quiet_time'));
		$last = trim((string)$node->get('mgn_agent_last_poll'));
		return $quiet !== '' && ($last === '' || strtotime($last . ' UTC') <= strtotime($quiet . ' UTC'));
	}
}
?>
