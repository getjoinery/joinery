<?php
/**
 * RecoveryKeyFleet — which of the managed sites are holding the control plane's
 * backup recovery key.
 *
 * A site that backs itself up on a schedule reads its OWN recovery key setting,
 * so a site that has never been given one makes no encrypted backups at all.
 * The control plane fills those empty slots automatically, but the operator
 * still has to be able to see the answer — in particular for the one case the
 * push deliberately walks away from: a site already holding a key the control
 * plane did not put there, which is left exactly as it is because archives
 * already on its shelf open only with the private half of THAT key.
 *
 * The answer comes from what the last status check recorded, not from reaching
 * out to every node when someone opens a page.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

class RecoveryKeyFleet {

	/**
	 * Where one node stands.
	 *
	 * state:
	 *   n/a       - hosts no Joinery site (a DNS box, a relay): nothing to set
	 *   unknown   - no status check has looked yet
	 *   missing   - the slot is empty, or holds a value that is not a key
	 *   has       - holding the control plane's key
	 *   different - holding somebody else's key; left alone
	 *
	 * @return array{state:string, fingerprint:string, summary:string}
	 */
	public static function node_state($node): array {
		if (!$node->get('mgn_web_root') || $node->get('mgn_skip_joinery_checks')) {
			return self::result('n/a', '', 'Hosts no Joinery site, so it needs no recovery key.');
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
				'No recovery key, so its own scheduled backups cannot be encrypted and will not run.');
		}
		if (self::manager_fingerprint() !== '' && hash_equals(self::manager_fingerprint(), $fpr)) {
			return self::result($state === 'proven' ? 'has' : 'missing', $fpr,
				$state === 'proven'
					? 'Has the recovery key.'
					: 'Has the recovery key but it is not verified there yet.');
		}
		return self::result('different', $fpr,
			'Holding a different recovery key. Left alone — backups made here may open only with '
			. 'the private half of that one.');
	}

	/** The control plane's own recovery key fingerprint, or '' if it has none. */
	public static function manager_fingerprint(): string {
		static $fpr = null;
		if ($fpr === null) {
			$report = BackupRecoveryKey::key_report();
			$fpr = ($report['state'] === 'proven') ? $report['fingerprint'] : '';
		}
		return $fpr;
	}

	/** Whether this node can be pushed to at all (the button is offered or not). */
	public static function is_pushable(array $state): bool {
		return self::manager_fingerprint() !== ''
			&& ($state['state'] === 'missing' || $state['state'] === 'unknown');
	}

	/** The short fingerprint every surface shows, matching the Backups page. */
	public static function short($fingerprint): string {
		return substr((string)$fingerprint, 0, 16);
	}

	private static function result(string $state, string $fingerprint, string $summary): array {
		return array('state' => $state, 'fingerprint' => $fingerprint, 'summary' => $summary);
	}
}
