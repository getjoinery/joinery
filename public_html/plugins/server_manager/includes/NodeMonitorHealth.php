<?php
/**
 * NodeMonitorHealth — is this node's uptime monitoring actually working?
 *
 * Distinct from whether the node is up. A node can be perfectly healthy while
 * its monitoring is dead: an api check with no credentials, or a check type
 * with nothing to probe, produces neither an up nor a down result. Those nodes
 * look identical to never-checked ones, so the failure hides.
 *
 * Every surface that reports monitoring state uses this class, so the
 * dashboard, the node detail page and the uptime task cannot disagree.
 *
 * It also surfaces backup recovery problems (backup_recovery_problems), in the
 * same shape, so an unrecoverable-backup node is as visible as broken monitoring.
 *
 * @version 1.6 - surfaces a fleet trust root whose only offsite copy (this site's own whole-site
 *                backup) does not exist yet — the implicit guarantee that replaced the signing-key
 *                escrow record is made checkable
 * @version 1.5 - backup_recovery_problems reports the one thing that can still be wrong: recovery
 *                itself is not set up. Per-node key rows are gone — a backup seals its own key as
 *                it is made, so a node holds nothing that can go missing
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

class NodeMonitorHealth {

	/** Monitoring is off by choice. Not a problem. */
	const STATE_DISABLED = 'disabled';
	/** Configured, concluding, current. */
	const STATE_OK = 'ok';
	/** Enabled but can never conclude as configured — needs a human. */
	const STATE_MISCONFIGURED = 'misconfigured';
	/** Configured fine, but has not concluded in far too long. */
	const STATE_STALE = 'stale';
	/** Enabled and configured, simply not probed yet. */
	const STATE_PENDING = 'pending';

	/** A node is stale once it has not concluded in this many intervals. */
	const STALE_INTERVAL_MULTIPLE = 4;
	/** Floor for the stale window, for nodes with very short intervals. */
	const STALE_MINIMUM_SECONDS = 900;

	/**
	 * Evaluate one node.
	 *
	 * @return array{state:string, label:string, detail:string, is_problem:bool}
	 */
	public static function evaluate($node): array {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return self::result(self::STATE_DISABLED, 'Monitoring off',
				'Uptime monitoring is disabled for this node.', false);
		}

		// Can this check type reach anything at all, as configured?
		$target = self::describe_target($node);
		if ($target['problem'] !== '') {
			return self::result(self::STATE_MISCONFIGURED, 'Monitoring misconfigured',
				$target['problem'], true);
		}

		// A recorded reason the check could not conclude outranks staleness —
		// it is the specific cause, and staleness is only its symptom.
		$last_error = trim((string)$node->get('mgn_uptime_last_error'));
		if ($last_error !== '') {
			return self::result(self::STATE_MISCONFIGURED, 'Monitoring misconfigured',
				$last_error, true);
		}

		$conclusive = trim((string)$node->get('mgn_uptime_last_conclusive'));
		if ($conclusive === '') {
			// Never concluded. If it has been attempted, something is wrong;
			// if never attempted, it is simply waiting for the next tick.
			$attempted = trim((string)$node->get('mgn_uptime_last_check'));
			if ($attempted === '') {
				return self::result(self::STATE_PENDING, 'Not yet checked',
					'Waiting for the first uptime check.', false);
			}
			return self::result(self::STATE_MISCONFIGURED, 'Monitoring misconfigured',
				'Checks are running but have never concluded up or down.', true);
		}

		$age = time() - strtotime($conclusive . ' UTC');
		$window = self::stale_window($node);
		if ($age > $window) {
			return self::result(self::STATE_STALE, 'Monitoring stale',
				sprintf('No conclusive check for %s (expected every %s).',
					self::humanize($age), self::humanize(self::interval($node))), true);
		}

		return self::result(self::STATE_OK, 'Monitoring OK',
			sprintf('Last conclusive check %s ago.', self::humanize($age)), false);
	}

	/**
	 * What this node's check type needs in order to probe anything, and what
	 * is missing. Returns ['problem' => string] — empty problem means usable.
	 */
	public static function describe_target($node): array {
		$type = self::effective_check_type($node);

		if ($type === 'tcp_port') {
			$host = trim((string)$node->get('mgn_host'));
			$port = (int)$node->get('mgn_uptime_tcp_port');
			if ($host === '') {
				return ['problem' => 'TCP check selected but the node has no host address.'];
			}
			if ($port <= 0 || $port > 65535) {
				return ['problem' => 'TCP check selected but no valid port is set.'];
			}
			return ['problem' => ''];
		}

		if ($type === 'http_status') {
			$url = trim((string)$node->get('mgn_health_check_url'));
			if ($url === '') { $url = trim((string)$node->get('mgn_site_url')); }
			if ($url === '') {
				return ['problem' => 'HTTP check selected but the node has no site URL or health check URL.'];
			}
			return ['problem' => ''];
		}

		// api
		if (trim((string)$node->get('mgn_site_url')) === '') {
			return ['problem' => 'API check selected but the node has no site URL.'];
		}
		return ['problem' => ''];
	}

	/**
	 * Stored type, with the skip-Joinery override applied.
	 *
	 * The override exists because the api check needs a Joinery install to talk
	 * to, so a node flagged as non-Joinery can never satisfy it. It therefore
	 * redirects away from `api` only — an explicitly chosen http_status or
	 * tcp_port is a deliberate statement about how this node proves it is
	 * alive, and overriding that would silently break checks the operator
	 * configured on purpose (a mail relay has no web endpoint to fall back to).
	 */
	public static function effective_check_type($node): string {
		$stored = $node->get('mgn_uptime_check_type') ?: 'http_status';
		if ($stored === 'api' && $node->get('mgn_skip_joinery_checks')) {
			return 'http_status';
		}
		return $stored;
	}

	public static function interval($node): int {
		$i = (int)$node->get('mgn_uptime_interval_seconds');
		return $i > 0 ? $i : 300;
	}

	private static function stale_window($node): int {
		return max(self::STALE_MINIMUM_SECONDS,
			self::interval($node) * self::STALE_INTERVAL_MULTIPLE);
	}

	/**
	 * Evaluate every enabled node and return only those needing attention.
	 * Used by the dashboard to surface broken monitoring where it is seen.
	 */
	public static function problems(): array {
		$nodes = new MultiManagedNode(['deleted' => false], ['mgn_name' => 'ASC'], 1000, 0);
		$nodes->load();

		$problems = [];
		foreach ($nodes as $node) {
			$health = self::evaluate($node);
			if (!$health['is_problem']) { continue; }
			$problems[] = [
				'node'   => $node,
				'slug'   => $node->get('mgn_slug'),
				'name'   => $node->get('mgn_name'),
				'id'     => $node->key,
				'health' => $health,
			];
		}
		return $problems;
	}

	/**
	 * Backup recovery problems, in the same shape as problems() so the dashboard
	 * renders them identically. A backup you cannot restore is as silent as
	 * monitoring that cannot alert, so it is surfaced the same way.
	 *
	 * Two things can be wrong:
	 *   - recovery was never set up, in which case no node can take an encrypted
	 *     backup at all. Per-node key problems no longer exist — each backup
	 *     seals its own key to the recovery key as it is made, so a node holds
	 *     nothing that can go missing.
	 *   - the agent signing key (the fleet trust root) exists but this site has
	 *     never completed a whole-site backup. The key's only offsite copy is
	 *     inside this site's own encrypted project archive — that is the design,
	 *     replacing the old standalone recovery record — so until one such
	 *     backup is confirmed offsite, losing this machine loses the trust root
	 *     and every fleet agent must be re-keyed by hand.
	 *
	 * The state comes from BackupRecoveryKey::setup_state(), so the dashboard,
	 * the walkthrough, and the node Backups tab cannot disagree about what is
	 * outstanding.
	 */
	public static function backup_recovery_problems(): array {
		require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

		$setup = BackupRecoveryKey::setup_state();
		if (!$setup['is_ready']) {
			return [[
				'node'   => null,
				'slug'   => 'control-plane',
				'name'   => 'Control plane',
				'id'     => 0,
				'link'   => BackupRecoveryKey::SETUP_URL,
				'health' => self::result('recovery', 'Backup key recovery not set up',
					BackupRecoveryKey::outstanding_summary($setup)
					. ' Encrypted backups do not run until it is set up.', true),
			]];
		}

		$problems = [];
		if (is_file(PathHelper::getSiteRoot() . '/config/agent_signing_key')
			&& !self::has_offsite_project_backup()) {
			$problems[] = [
				'node'   => null,
				'slug'   => 'control-plane',
				'name'   => 'Control plane',
				'id'     => 0,
				'link'   => '/admin/admin_backups',
				'health' => self::result('recovery', 'Fleet trust root not yet backed up',
					'The agent signing key lives only in config/ on this machine, and its offsite copy '
					. 'is this site\'s own whole-site backup — which has never completed. Until one is '
					. 'confirmed offsite, losing this machine loses the fleet trust root.', true),
			];
		}
		return $problems;
	}

	/** Has this site ever confirmed a whole-site backup in the bucket? */
	private static function has_offsite_project_backup(): bool {
		try {
			require_once(PathHelper::getIncludePath('data/backup_history_class.php'));
			$rows = new MultiBackupHistory(
				array('type' => 'project', 'outcome' => 'success', 'offsite' => true, 'deleted' => false),
				array('bkh_start_time' => 'DESC'), 1, 0);
			$rows->load();
			foreach ($rows as $r) { return true; }
		} catch (\Throwable $e) {
			// An unreadable history must not paint a false problem row.
			error_log('NodeMonitorHealth: backup history check failed: ' . $e->getMessage());
			return true;
		}
		return false;
	}

	private static function result($state, $label, $detail, $is_problem): array {
		return ['state' => $state, 'label' => $label, 'detail' => $detail, 'is_problem' => $is_problem];
	}

	private static function humanize($seconds): string {
		$seconds = (int)$seconds;
		if ($seconds < 90)    return $seconds . 's';
		if ($seconds < 5400)  return round($seconds / 60) . ' min';
		if ($seconds < 172800) return round($seconds / 3600) . ' hr';
		return round($seconds / 86400) . ' days';
	}
}
