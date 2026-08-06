<?php
/**
 * RecoveryKeyFleet — which recovery key each managed site holds for its OWN
 * backups.
 *
 * A site that backs itself up reads its own recovery key setting, so a site that
 * has never set one up runs no backups of its own. That is reported here and
 * nothing else: the slot's custodian is whoever administers the site, and a
 * control plane writing into it would hold the private half of a key the site
 * believes is its own.
 *
 * It is also not a gap in coverage. A control plane's backups of a site are the
 * manager profile, which carries its recovery key with each run — so a site with
 * an empty slot is still backed up here, under this control plane's key, and the
 * empty slot means only that the site takes no copies of its own.
 *
 * The answer comes from what the last status check recorded, not from reaching
 * out to every node when someone opens a page.
 *
 * @version 1.1 - reports only; the push is retired
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

	/**
	 * Whether this node's own backups are covered by a key SOMEBODY holds.
	 *
	 * Reported, never acted on. The key in that slot is for the site's own
	 * backups and its custodian is whoever administers the site; a control plane
	 * writing into it would hold the private half of a key the site believes is
	 * its own. A site with no key of its own is exercising a choice, and it is
	 * still covered by this control plane's backups either way — those carry
	 * their key with each run.
	 */
	public static function has_own_key(array $state): bool {
		return $state['state'] === 'has' || $state['state'] === 'different';
	}

	/** The short fingerprint every surface shows, matching the Backups page. */
	public static function short($fingerprint): string {
		return substr((string)$fingerprint, 0, 16);
	}

	private static function result(string $state, string $fingerprint, string $summary): array {
		return array('state' => $state, 'fingerprint' => $fingerprint, 'summary' => $summary);
	}
}
