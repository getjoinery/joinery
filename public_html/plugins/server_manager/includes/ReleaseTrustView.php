<?php
/**
 * ReleaseTrustView - the release keys a node trusts beside the ones this
 * management node expects it to (specs/release_key_rotation.md R6).
 *
 * A node's status check names, for its site and its agent, the release and
 * statement keys in the trusted set and the authority over them, each by the
 * first 12 hex characters of its sha256 (JobResultProcessor::
 * status_release_trust). A site container is a node of its own, with an agent
 * of its own, and reports the same way. This compares a reported set with
 * the expected one, so a rotation's checks ("every node shows N's set") are
 * read off the fleet, and a node holding a key no release of ours introduced
 * stands out.
 *
 * What is expected: on the box that publishes, the keys its repository lists
 * now (release_keys/, and release_keys/authority), which is what the next
 * release installs; on any other management node, the set it holds itself,
 * which its own last upgrade recorded.
 *
 * @version 1.0
 */
class ReleaseTrustView {

	/** @var array|null expected(), once per request */
	private static $expected = false;

	/**
	 * The set a node should report: {release: [name], statement: [name],
	 * authority: {release, statement}, source: sentence}, or null when this
	 * management node has none to compare with.
	 */
	public static function expected(): ?array {
		if (self::$expected !== false) {
			return self::$expected;
		}
		$site = PathHelper::getSiteRoot();
		$names = function (array $b64s) {
			$out = array();
			foreach ($b64s as $b64) {
				$raw = base64_decode((string)$b64, true);
				if ($raw !== false && $raw !== '') { $out[] = PackageSignature::keyId($raw); }
			}
			$out = array_values(array_unique($out));
			sort($out);
			return $out;
		};
		$expected = null;
		if (is_file($site . '/config/agent_signing_key')) {
			try {
				$lists = AgentDistPublisher::repoKeyLists($site);
				$authority = AgentDistPublisher::repoAuthority($site);
				$expected = array(
					'release'   => $names($lists['release_keys']),
					'statement' => $names($lists['statement_keys']),
					'authority' => array('release' => $names($authority['release_keys']), 'statement' => $names($authority['statement_keys'])),
					'source'    => 'the keys this repository lists, which the next release installs',
				);
			} catch (Exception $e) {
				$expected = null;
			}
		} else {
			$record = PackageSignature::readTrustRecord($site);
			if ($record !== null) {
				$expected = array(
					'release'   => $names((array)($record['release_keys'] ?? array())),
					'statement' => $names((array)($record['statement_keys'] ?? array())),
					'authority' => array('release' => $names((array)($record['authority']['release_keys'] ?? array())),
						'statement' => $names((array)($record['authority']['statement_keys'] ?? array()))),
					'source'    => 'the set this management node holds' . (!empty($record['set_by']) ? ', set by ' . $record['set_by'] : ''),
				);
			}
		}
		return self::$expected = $expected;
	}

	/** Forget expected(), for tests. */
	public static function reset(): void {
		self::$expected = false;
	}

	/**
	 * How a reported set (JobResultProcessor::reported_trust()'s shape, or null)
	 * stands against the expected one: 'ours' (the same keys and authority),
	 * 'differs', or 'unknown' (nothing reported, or nothing to compare with).
	 */
	public static function standing(?array $trust): string {
		$expected = self::expected();
		if ($trust === null || $expected === null) {
			return 'unknown';
		}
		$authority = is_array($trust['authority'] ?? null) ? $trust['authority']
			: array('release' => $trust['release'] ?? array(), 'statement' => $trust['statement'] ?? array());
		$same = ($trust['release'] ?? null) === $expected['release'] && ($trust['statement'] ?? null) === $expected['statement']
			&& ($authority['release'] ?? null) === $expected['authority']['release']
			&& ($authority['statement'] ?? null) === $expected['authority']['statement'];
		return $same ? 'ours' : 'differs';
	}

	/** A set in one line: "release a, b · statement c · authority a / c (set by 0.9.4)". */
	public static function line(?array $trust): string {
		if ($trust === null) {
			return 'none reported';
		}
		$out = 'release ' . (implode(', ', $trust['release'] ?? array()) ?: 'none')
			. ' · statement ' . (implode(', ', $trust['statement'] ?? array()) ?: 'none');
		if (is_array($trust['authority'] ?? null)) {
			$out .= ' · authority ' . (implode(', ', $trust['authority']['release']) ?: 'none') . ' / '
				. (implode(', ', $trust['authority']['statement']) ?: 'none');
		} else {
			$out .= ' · authority: the whole set';
		}
		if (!empty($trust['set_by'])) {
			$out .= ' (set by ' . $trust['set_by'] . ')';
		} elseif (empty($trust['recorded'])) {
			$out .= ' (no release has recorded a set here yet)';
		}
		return $out;
	}

	/**
	 * The sets a node's status data (mgn_last_status_data, raw or decoded) names,
	 * labelled: [[label, trust], ...] for its site and its agent, each that it
	 * reported. Empty for a node whose agent reports none.
	 */
	public static function reported($status): array {
		if (is_string($status)) {
			$status = json_decode($status, true);
		}
		$trust = is_array($status) ? JobResultProcessor::status_release_trust($status['release_trust'] ?? null) : null;
		$out = array();
		foreach (array('site' => 'Site', 'agent' => 'Agent') as $key => $label) {
			if ($trust !== null && $trust[$key] !== null) {
				$out[] = array($label, $trust[$key]);
			}
		}
		return $out;
	}

	/** The first agent whose status check reports the sets. */
	const REPORTING_AGENT = '1.81.0';

	/**
	 * What a node's dashboard row says of its release keys: 'differs' (a set
	 * it reports is not the expected one), 'unreported' (its agent reports
	 * the sets, and its status data holds none, or none for the site it
	 * says it hosts, so a rotation's "every node shows" cannot be read off
	 * it), or null. An older agent reports none, and its row already says it
	 * is behind.
	 */
	public static function attention($status, ?string $agent_version): ?string {
		$rows = self::reported($status);
		$labels = array();
		foreach ($rows as list($label, $trust)) {
			if (self::standing($trust) === 'differs') {
				return 'differs';
			}
			$labels[] = $label;
		}
		if (is_string($status)) {
			$status = json_decode($status, true);
		}
		$hosts_site = is_array($status) && is_string($status['web_root'] ?? null) && $status['web_root'] !== '';
		$missing = !in_array('Agent', $labels, true) || ($hosts_site && !in_array('Site', $labels, true));
		if ($missing && $agent_version !== null && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $agent_version)
				&& version_compare($agent_version, self::REPORTING_AGENT, '>=')) {
			return 'unreported';
		}
		return null;
	}
}
