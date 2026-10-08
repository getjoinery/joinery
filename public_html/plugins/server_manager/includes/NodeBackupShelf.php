<?php
/**
 * NodeBackupShelf — the acts a plane performs on one node's fleet backups in
 * backup storage: pause them over an allowance, switch them off or back on for
 * a lapsed service, and empty the node's prefix.
 *
 * Two callers act on the same thing for two reasons. HostedTrialWatch does it
 * for a Managed site under its hosting plan; ServiceTenantWatch does it for a
 * node-linked Services row — a Managed site that moved to its customer's own
 * cloud account and kept its backups with us
 * (specs/managed_to_self_hosted_transfer.md §5a). Both write the node's fleet
 * backups in the node's storage spaces with a per-run key; neither goes
 * through the broker. So the acts live here once.
 *
 * NOTHING HERE DELETES A BACKUP FOR BEING OVER AN ALLOWANCE. Over the cap,
 * backups stop being extended; the existing ones are the customer's. Emptying
 * the prefix is a separate act, called only when a keep-period promised to the
 * customer has run out.
 *
 * Every switch-off carries a marker inside the stored policy, which
 * FleetBackupPolicy ignores (it copies only keys it recognises). The marker is
 * what lets a switch-on undo exactly what this did and never an operator's own
 * deliberate off.
 *
 * @version 1.2 - prune() empties what this management node took in each of the node's storage spaces
 *                ({space}/manager/), never a site's own backups beside them (S22)
 * @version 1.1 - prune() is quiet about a target it cannot reach and throws on a listing that fails, as the
 *                hosted watch did before the lift
 * @version 1.0 - lifted from HostedTrialWatch
 */
class NodeBackupShelf {

	/** The marker of a pause for being over the allowance. */
	const PAUSED_FOR_SHELF = 'paused_for_shelf';

	/** The marker of a switch-off because the Services subscription lapsed. */
	const SUSPENDED_FOR_SERVICES = 'suspended_for_services';

	/** The bytes the node's backups occupy, as the retention pass last measured them. */
	public static function bytes($node): int {
		return (int)$node->get('mgn_backup_shelf_bytes');
	}

	/** Did this pause the node's backups for being over the allowance? */
	public static function paused_for_shelf($node): bool {
		return self::marked($node, self::PAUSED_FOR_SHELF);
	}

	/** Did this switch the node's backups off for a lapsed Services subscription? */
	public static function suspended_for_services($node): bool {
		return self::marked($node, self::SUSPENDED_FOR_SERVICES);
	}

	private static function marked($node, string $marker): bool {
		$stored = $node->get('mgn_backup_policy');
		if (is_string($stored)) { $stored = json_decode($stored, true); }
		return is_array($stored) && !empty($stored[$marker]);
	}

	/**
	 * Over the allowance: pause. Back under it after a pause this put there:
	 * resume. Returns 'paused', 'resumed' or ''. Saves the node when it acts.
	 *
	 * A node whose backups are already off — by a person, or for a lapsed
	 * subscription — is not paused on top: there is nothing running to pause,
	 * and a later resume must not switch on what somebody else switched off.
	 */
	public static function apply_allowance($node, int $allowance_bytes): string {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
		$used = self::bytes($node);
		if ($used >= $allowance_bytes && FleetBackupPolicy::stored_mode($node) !== 'off') {
			$node->set('mgn_backup_policy', json_encode(array('enabled' => false, self::PAUSED_FOR_SHELF => true)));
			$node->save();
			return 'paused';
		}
		if ($used < $allowance_bytes && self::paused_for_shelf($node)) {
			$node->set('mgn_backup_policy', null);
			$node->save();
			return 'resumed';
		}
		return '';
	}

	/** The Services subscription lapsed past its grace: the node's fleet backups stop. Saves. */
	public static function suspend($node): void {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
		if (FleetBackupPolicy::stored_mode($node) === 'off' && !self::paused_for_shelf($node)) {
			return;   // already off, by a person or by an earlier suspend
		}
		$node->set('mgn_backup_policy', json_encode(array('enabled' => false, self::SUSPENDED_FOR_SERVICES => true)));
		$node->save();
	}

	/** Paid again: back on, if what turned it off was this. Saves when it acts. */
	public static function reactivate($node): void {
		if (self::suspended_for_services($node)) {
			$node->set('mgn_backup_policy', null);
			$node->save();
		}
	}

	/**
	 * The node's backups in backup storage, gone: in every storage space it
	 * has, everything this management node took ({space}/manager/), and
	 * nothing else. A site that backs itself up to the same bucket and folder
	 * keeps its own {space}/site/ backups: those are not ours to empty
	 * (specs/storage_targets.md S22). Spaces the node had been moved away from
	 * are retired once emptied; the active one stays, empty, for the node's
	 * next backup.
	 *
	 * Returns how many objects were deleted, or null when the node has no
	 * space to empty — the caller tries again later, quietly. A space that
	 * cannot be reached or listed THROWS: that is a fault somebody should see,
	 * and nothing is recorded as pruned.
	 *
	 * Emptying is not trimming: retention's prune() keeps at least one chain on
	 * purpose, so this is done here, explicitly, with the plane's own credential.
	 */
	public static function prune($node): ?int {
		$spaces = StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$node->key);
		if (!$spaces) {
			return null;
		}
		$deleted = 0;
		foreach ($spaces as $space) {
			try {
				list($target, $creds, $bucket) = $space->reach();
			} catch (StorageSpaceException $e) {
				throw new RuntimeException('backup storage cannot be reached: ' . $e->getMessage());
			}
			$base = $space->base() . BackupProfile::path_segment(BackupProfile::MANAGER) . '/';
			$objects = S3Signer::list($creds, $bucket, $base);
			if (!is_array($objects)) {
				throw new RuntimeException('backup storage could not be listed under ' . $base);
			}
			foreach ($objects as $object) {
				$key = is_array($object) ? (string)($object['key'] ?? '') : (string)$object;
				if ($key === '' || strpos($key, $base) !== 0) { continue; }
				$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
				$status = (int)($resp['status'] ?? 0);
				if (($status >= 200 && $status < 300) || $status === 404) {
					$deleted++;
				}
			}
			if ($space->is_draining()) {
				$space->retire();
			}
		}
		$node->set('mgn_backup_shelf_bytes', 0);
		$node->set('mgn_backup_shelf_checked_time', gmdate('Y-m-d H:i:s'));
		$node->save();
		return $deleted;
	}
}
