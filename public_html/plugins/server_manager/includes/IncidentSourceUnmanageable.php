<?php
/**
 * IncidentSourceUnmanageable — plane:unmanageable: the node's agent cannot
 * verify the scripts it would run as root, so it refuses every script
 * primitive (incident_triage.md, Types; WP3).
 *
 * Read from mgn_script_trust, which NodeMonitorHealth::note_script_trust()
 * keeps from the agent's refusals and reports. Two causes with two different
 * answers, both critical: the manifest cannot be verified (nothing will run;
 * upgrades and backups included), or a file does not match its signed release
 * (find out why before anything else). Clears when a later job verifies.
 *
 * @version 1.2 - cleared_text() covers an edit in this site's own checkout as well as a commit
 * @version 1.1 - cleared_text() says when the file turned out to be this site's own unpublished commit
 * @version 1.0
 */
class IncidentSourceUnmanageable implements IncidentSource {

	const NAME = 'plane:unmanageable';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$state = (string)$node->get('mgn_script_trust');
		if ($state !== 'untrusted_manifest' && $state !== 'untrusted_file') {
			return null;
		}
		$health = NodeMonitorHealth::script_trust_health($node);
		$detail = array('What it means' => (string)$health['detail']);
		$since = trim((string)$node->get('mgn_script_trust_since'));
		if ($since !== '') {
			$detail['Since'] = $since . ' UTC';
		}
		return array(
			'title'    => $state === 'untrusted_file' ? 'A file on this node does not match its release' : 'This node can no longer be managed',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => $detail,
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if ((string)$node->get('mgn_script_trust') === 'unpublished_file') {
			return 'The file was changed in this site\'s own checkout after its last publish, not through the site; the next publish re-signs it.';
		}
		return 'A later job verified the node\'s scripts against their signed release.';
	}
}
?>
