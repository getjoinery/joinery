<?php
/**
 * IncidentSourceAgentBundleRefused - plane:agent_bundle_refused: a siteless
 * machine's agent refused the support bundle on offer to it (spec
 * release_transparency, WP7 review). The bundle is the signed tree of scripts a
 * relay or Docker host runs as root, and the agent holds it to the public log
 * as it holds its own binary. Read from mgn_agent_bundle_state, which the agent
 * reports on every poll (1.66.0 and later); without it a refusal is seen only
 * in the machine's own log, as scripts that never change. Two verdicts hold it
 * open, both critical:
 *  - unlogged: the bundle carries the release signature but the release's
 *    statement in the public log does not record it.
 *  - verify_failed: the bundle failed its signature or its file check.
 * A refusal holds until the management node offers different bytes, so the
 * incident clears when the agent installs a later bundle and says so.
 *
 * @version 1.0
 */
class IncidentSourceAgentBundleRefused implements IncidentSource {

	const NAME = 'plane:agent_bundle_refused';

	const REFUSALS = array(
		'unlogged' => array(
			'title' => 'The agent refused a support bundle that is not in the public log',
			'means' => 'The support bundle (the scripts this machine runs as root) carries our release signature, but the release '
				. 'statement in the public log does not record it, and this machine installs only what it can see there. A bundle '
				. 'signed and not logged is what a publisher forced to sign a build in secret would produce.',
			'next'  => 'Check that the release on offer was published and logged (Server Manager > Publish Upgrade, and the release '
				. 'log). If it was, publish again; if nobody published it, treat the signing key as compromised.',
		),
		'verify_failed' => array(
			'title' => 'The agent refused a support bundle that failed verification',
			'means' => 'The support bundle failed its signature or file check, so it was damaged or altered after it was built. '
				. 'The machine keeps the scripts it has and will not retry these bytes.',
			'next'  => 'Publish a corrected release. If the files on the management node were not changed by a publish, find out what changed them.',
		),
	);

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return null;
		}
		$refusal = self::REFUSALS[(string)$node->get('mgn_agent_bundle_state')] ?? null;
		if ($refusal === null) {
			return null;
		}
		$installed = trim((string)$node->get('mgn_agent_bundle_version'));
		return array(
			'title'    => $refusal['title'],
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'Bundle installed' => $installed !== '' ? $installed : 'none',
				'What it means'    => $refusal['means'],
				'What to do'       => $refusal['next'],
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (trim((string)$node->get('mgn_agent_public_key')) === '') {
			return 'The node is no longer paired, so its agent no longer reports its support bundle here.';
		}
		return 'The agent no longer refuses the support bundle on offer.';
	}
}
?>
