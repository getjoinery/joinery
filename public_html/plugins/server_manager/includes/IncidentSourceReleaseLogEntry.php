<?php
/**
 * IncidentSourceReleaseLogEntry - plane:release_log_entry: the public log
 * holds an entry under one of our release statement keys that no publish here
 * wrote (spec release_transparency, O5).
 *
 * Read from what ReleaseLogTail found, checked against the release ledger
 * each time, so an entry the ledger has come to hold leaf for leaf stops
 * counting. Raised only on this management node's own node, and only where
 * this site logs releases. Always critical: a node installs any release whose
 * statement is logged under a key it holds, so such an entry is a release
 * nodes would install that nobody here published.
 *
 * It also holds when a log key a logged release installed on nodes is one
 * Sigstore's trusted root does not list for that log (D7): nodes trust a log
 * only we vouch for.
 *
 * @version 1.1 - a log key releases installed that Sigstore's trusted root does not list
 * @version 1.0
 */
class IncidentSourceReleaseLogEntry implements IncidentSource {

	const NAME = 'plane:release_log_entry';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node) || !ReleaseLogWatch::watches()) {
			return null;
		}
		$state = ReleaseLogTail::state();
		$unaccounted = ReleaseLogTail::unaccounted($state, ReleaseLogTail::ledger());
		$unknown = $state['root']['unknown'] ?? array();
		if (!$unaccounted && !$unknown) {
			return null;
		}
		if (!$unaccounted) {
			$keys = array_map(function ($k) { return "{$k['origin']} key {$k['fingerprint']}"; }, $unknown);
			return array(
				'title'    => 'A release installed a log key that Sigstore does not list',
				'severity' => IncidentRecord::SEVERITY_CRITICAL,
				'detail'   => array(
					'Keys'          => implode("\n", $keys),
					'What it means' => 'Nodes accept a release logged on a log whose key they hold. Sigstore\'s trusted root does not list '
						. 'this key for that log, so nodes trust a log only we vouch for.',
					'What to do'    => 'Compare the key file in release_keys/log/ with the key Sigstore publishes for that log. If they differ, '
						. 'replace it with Sigstore\'s and publish once, so nodes hold the right key.',
					'As of'         => gmdate('Y-m-d H:i', (int)$state['root']['checked_at']) . ' UTC',
				),
			);
		}
		$entries = array();
		foreach ($unaccounted as $u) {
			$entries[] = "{$u['origin']} entry {$u['index']}: {$u['why']} (found " . gmdate('Y-m-d H:i', (int)$u['found_at']) . ' UTC; '
				. 'https://' . $u['origin'] . '/api/v2/tile/entries/' . LogTileReader::tilePath(intdiv((int)$u['index'], LogTileReader::ENTRIES_PER_TILE))
				. ' holds it at position ' . ((int)$u['index'] % LogTileReader::ENTRIES_PER_TILE) . ')';
		}
		return array(
			'title'    => count($unaccounted) === 1
				? 'The public log holds a release entry signed with our key that no publish here wrote'
				: 'The public log holds ' . count($unaccounted) . ' release entries signed with our key that no publish here wrote',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'Entries'       => implode("\n", $entries),
				'What it means' => 'Someone signed a release statement with our statement key and logged it. Nodes install a release '
					. 'whose statement is logged under a key they hold, so this is a release they would install that this site did not publish.',
				'What to do'    => 'Find out who wrote it: every publish here records its entry in the release ledger the moment the log '
					. 'accepts it. If nobody here did, the statement key is compromised: stop publishing, and replace the key '
					. '(release_keys/statement/, spec release_transparency D5).',
			) + ($unknown ? array('Log keys Sigstore does not list' => implode("\n",
				array_map(function ($k) { return "{$k['origin']} key {$k['fingerprint']}"; }, $unknown))) : array()),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'Every entry under our release keys is accounted for by the release ledger, and Sigstore lists every log key releases installed.';
	}
}
?>
