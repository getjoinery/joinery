<?php
/**
 * RecoveryKeyFleet — which recovery key each managed node holds, and therefore
 * whether that node can be backed up at all.
 *
 * Every backup a node makes seals to the recovery key that node holds and has
 * proven, read on the node. That is true of the copies this management node takes
 * as much as the copies the node takes for itself: nothing supplies a key from
 * here, because sealing to a public key always appears to succeed, so a key sent
 * over a wire would let whoever sent it decide who can open a node's database
 * and mail with nothing anywhere looking wrong.
 *
 * The consequence this class exists to report: a node with no proven key of its
 * own takes no backups, for anybody. It is not a shrug and not a preference —
 * it is an un-backed-up node, and the fix is on the node, at its own Backups
 * page, by whoever administers it. This management node cannot do it from here and
 * must not be able to.
 *
 * Whose key it is does not matter and is not compared. A node holding a key this
 * management node has never seen is a node whose operator holds their own recovery
 * key — the intended arrangement, not a discrepancy.
 *
 * The answer comes from what the last status check recorded, not from reaching
 * out to every node when someone opens a page.
 *
 * @version 2.0 - a node's own proven key is what every backup seals to, so this reports coverage
 *                rather than key distribution: no comparison against the management node's key, and
 *                "no proven key" is an un-backed-up node rather than a note
 * @version 1.1 - reports only; the push is retired
 * @version 1.0
 */

class RecoveryKeyFleet {

	/**
	 * Where one node stands.
	 *
	 * state:
	 *   n/a       - hosts no Joinery site (a DNS box, a relay): nothing to back up
	 *   unknown   - no status check has looked yet
	 *   missing   - the slot is empty, or holds a value that is not a key
	 *   unproven  - a key is set but possession was never proven there
	 *   proven    - a key is set and proven: this node can be backed up
	 *
	 * @return array{state:string, fingerprint:string, summary:string}
	 */
	public static function node_state($node): array {
		if (!$node->get('mgn_web_root') || $node->get('mgn_skip_joinery_checks')) {
			return self::result('n/a', '', 'Hosts no Joinery site, so there is nothing to back up.');
		}

		$status = json_decode((string)$node->get('mgn_last_status_data'), true);
		$state  = is_array($status) ? (string)($status['backup_recovery_state'] ?? '') : '';
		$fpr    = (string)$node->get('mgn_backup_recovery_fpr');

		if ($state === '') {
			return self::result('unknown', $fpr,
				'Not known yet — run a status check and this fills in.');
		}
		if ($state === 'unconfigured' || $state === 'invalid' || $fpr === '') {
			return self::result('missing', '',
				'No recovery key on the node, so nothing it backs up could be encrypted — '
				. 'no backup of it runs, including the ones taken from here.');
		}
		if ($state === 'unproven') {
			return self::result('unproven', $fpr,
				'A recovery key is set on the node but possession of it was never proven there, so '
				. 'backups still refuse to run — a mistyped key seals happily and opens never.');
		}
		return self::result('proven', $fpr,
			'Holds a verified recovery key of its own. Every backup of this node seals to it.');
	}

	/**
	 * Whether this node can be backed up: it holds a key, and somebody has
	 * demonstrated they hold the private half.
	 *
	 * Proof is the whole test. An unproven key is indistinguishable from a
	 * mistyped one until a restore is attempted, which is the one moment the
	 * answer cannot be acted on.
	 */
	public static function has_own_key(array $state): bool {
		return $state['state'] === 'proven';
	}

	/**
	 * One line naming what is outstanding and where it is fixed, so the node
	 * detail page, the fleet dashboard and the targets page say the same thing
	 * rather than three near-misses.
	 */
	public static function blocker_summary(array $state): string {
		switch ($state['state']) {
			case 'missing':
				return 'This node has no backup recovery key, so no backup of it can be encrypted and '
					. 'none will run. Its administrator sets one up on the node\'s own Backups page — '
					. 'this management node cannot supply one, by design, because a key sent from here '
					. 'would be a key this management node could open every backup with.';
			case 'unproven':
				return 'This node has a backup recovery key that nobody has proven possession of, so '
					. 'backups still refuse to run. Its administrator opens the verification challenge '
					. 'on the node\'s own Backups page.';
			case 'unknown':
				return 'Whether this node holds a backup recovery key is not known yet. Run a status '
					. 'check against it; until then no backup of it will be dispatched.';
			default:
				return '';
		}
	}

	/** The short fingerprint every surface shows, matching the Backups page. */
	public static function short($fingerprint): string {
		return substr((string)$fingerprint, 0, 16);
	}

	private static function result(string $state, string $fingerprint, string $summary): array {
		return array('state' => $state, 'fingerprint' => $fingerprint, 'summary' => $summary);
	}
}
