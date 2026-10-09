<?php
/**
 * IncidentSourceCustomerBackups — plane:customer_backups: a customer of backup
 * storage has had no run finish here for two nights, or no backup it reported
 * verified for eight days (specs/storage_targets.md F1, F2). Raised on this
 * management node's own node, one incident naming every such customer;
 * cleared when none is left.
 *
 * Read from the runs the broker recorded (svr_shelf_runs): finished runs and
 * the verifies the site reported (shelf_verified_run). The rule is the one a
 * self-hosted site's Backups page applies to itself (BackupSafety::
 * site_warnings), at the shipped weekly verify interval. A site moved off
 * Managed (a node-linked row) is watched as the node it was.
 *
 * @version 1.0
 */
class IncidentSourceCustomerBackups implements IncidentSource {
	const NAME = 'plane:customer_backups';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node)) {
			return null;
		}
		return self::condition(self::gaps(time()));
	}

	public function cleared_text(ManagedNode $node): string {
		return 'Every customer of backup storage has a recent backup here and a recent verified one.';
	}

	/**
	 * Every active customer of backup storage with a gap: label => list of
	 * ['kind' => 'offsite'|'verify', 'since' => ?int].
	 */
	public static function gaps(int $now): array {
		$out = array();
		$rows = new MultiServiceTenant(array('service' => ServiceTenant::SERVICE_SHELF,
			'state' => ServiceTenant::STATE_ACTIVE, 'deleted' => false));
		$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT min(svr_create_time) AS first_run,
				max(CASE WHEN svr_state = 'finished' THEN svr_finish_time END) AS last_finished,
				max(svr_verified_time) AS last_verified
			FROM svr_shelf_runs WHERE svr_svt_service_tenant_id = ? AND svr_delete_time IS NULL");
		$unix = function ($t) {
			$u = ($t === null || $t === '') ? false : strtotime($t . ' UTC');
			return $u === false ? null : (int)$u;
		};
		foreach ($rows as $row) {
			if ($row->is_node_linked()) {
				continue;
			}
			$q->execute(array((int)$row->key));
			$r = $q->fetch(PDO::FETCH_ASSOC) ?: array();
			$gaps = BackupSafety::site_warnings($unix($r['first_run'] ?? null), $unix($r['last_finished'] ?? null),
				$unix($r['last_verified'] ?? null), BackupSafety::DEFAULT_VERIFY_EVERY_DAYS, $now);
			if ($gaps) {
				$label = trim((string)$row->get('svt_host')) ?: (trim((string)$row->get('svt_slug')) ?: ('customer #' . (int)$row->key));
				$out[$label] = $gaps;
			}
		}
		return $out;
	}

	/** The incident for these gaps, or null when there are none. Public and pure. */
	public static function condition(array $gaps): ?array {
		if (!$gaps) {
			return null;
		}
		$words = function (array $gap) {
			$when = $gap['since'] !== null ? 'since ' . gmdate('Y-m-d', (int)$gap['since']) : 'yet';
			return $gap['kind'] === 'offsite'
				? 'no backup has finished here ' . $when
				: 'no backup has passed verification ' . $when;
		};
		$detail = array();
		foreach ($gaps as $label => $list) {
			$detail[$label] = ucfirst(implode('; ', array_map($words, $list))) . '.';
		}
		if (count($gaps) === 1) {
			$label = array_key_first($gaps);
			$title = $label . '\'s backups: ' . implode('; ', array_map($words, $gaps[$label]));
		} else {
			$title = count($gaps) . ' customers\' backups are not landing or not verified';
		}
		return array('title' => $title, 'severity' => IncidentRecord::SEVERITY_WARNING, 'detail' => $detail);
	}
}
?>
