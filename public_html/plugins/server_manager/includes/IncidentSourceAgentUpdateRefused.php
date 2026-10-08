<?php
/**
 * IncidentSourceAgentUpdateRefused - plane:agent_update_refused: the node's
 * agent refused the update on offer to it (spec release_transparency, O7).
 *
 * Read from mgn_agent_update_state, which the agent reports on every poll
 * (1.65.0 and later). Without it a refusal is seen only on the machine, as an
 * agent version that never moves. Three verdicts hold it open:
 *
 *  - unlogged: the binary carries the release signature but its release is
 *    not shown to be in the public log. That is the shape of a build the
 *    publisher was made to sign that nobody can see. Critical.
 *  - verify_failed: the artifact failed its checksum or signature. Damaged
 *    or tampered in transit or at rest. Critical.
 *  - version_rejected: the new agent failed to start here and was rolled
 *    back; the agent holds at its version until a newer release ships.
 *    Warning: the node works, it just stops taking agent updates.
 *
 * Each verdict holds until the release on offer changes, so the incident
 * clears when the agent installs or accepts a later release and says so.
 *
 * @version 1.0
 */
class IncidentSourceAgentUpdateRefused implements IncidentSource {

	const NAME = 'plane:agent_update_refused';

	const REFUSALS = array(
		'unlogged' => array(
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'title'    => 'The agent refused an update that is not in the public log',
			'means'    => 'The new agent binary carries our release signature, but its release is not shown to be in the public log, '
				. 'and this machine installs only releases it can see there. A release signed and not logged is what a publisher '
				. 'forced to sign a build in secret would produce.',
			'next'     => 'Check that the release on offer was published and logged (Server Manager > Publish Upgrade, and the release '
				. 'log). If it was, publish again; if nobody published it, treat the signing key as compromised.',
		),
		'verify_failed' => array(
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'title'    => 'The agent refused an update that failed verification',
			'means'    => 'The new agent binary failed its checksum or signature check, so it was damaged or altered after it was '
				. 'built. The agent keeps running its current version and will not retry this release.',
			'next'     => 'Publish a corrected release. If the files on the management node were not changed by a publish, find out what changed them.',
		),
		'version_rejected' => array(
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'title'    => 'The agent update failed to start and was rolled back',
			'means'    => 'The new agent version failed to start on this machine, so the agent went back to its previous version. '
				. 'It holds there until a newer release ships.',
			'next'     => 'Read the agent\'s log on the node for why the new version failed to start, fix it and publish a newer release.',
		),
	);

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return null;
		}
		$state = (string)$node->get('mgn_agent_update_state');
		if (!isset(self::REFUSALS[$state])) {
			return null;
		}
		$refusal = self::REFUSALS[$state];
		$offered = trim((string)$node->get('mgn_agent_update_offered'));
		$running = trim((string)$node->get('mgn_agent_version'));
		return array(
			'title'    => $refusal['title'],
			'severity' => $refusal['severity'],
			'detail'   => array(
				'Version refused' => $offered !== '' ? $offered : 'not named (the update manifest could not be read)',
				'Running'         => $running !== '' ? $running : 'not reported',
				'What it means'   => $refusal['means'],
				'What to do'      => $refusal['next'],
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return 'The node is no longer paired, so its agent no longer reports its updates here.';
		}
		$offered = trim((string)$node->get('mgn_agent_update_offered'));
		return $offered !== ''
			? "The agent no longer refuses the update on offer (v{$offered})."
			: 'The agent no longer refuses an update: none is on offer.';
	}
}
?>
