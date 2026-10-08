<?php
/**
 * FleetBackups — the fleet's view of a backup target.
 *
 * Core's TargetBackups knows how to read a bucket but deliberately does not know
 * who owns the folders it finds there; a standalone site owns one, a management
 * node owns dozens. This supplies the fleet answer from the storage spaces on
 * the target (specs/storage_targets.md §3): a folder is a node's or a
 * customer's space taking new backups (live), one kept while its backups age
 * out after its owner moved (draining), or a folder no space claims
 * (unclaimed), which can be adopted.
 *
 * @version 2.0 - folders are classified by the target's storage spaces, not by node slug; a folder no space
 *                claims is unclaimed and names the node that once had its slug
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));

class FleetBackups {

	/**
	 * The target's objects grouped by folder and classified by its spaces.
	 * Same return shape as TargetBackups::list_grouped(), each group also
	 * carrying 'owner' and 'space_id'.
	 */
	public static function list_grouped($target) {
		return TargetBackups::list_grouped($target, self::folder_map($target));
	}

	/**
	 * folder => ['status', 'owner', 'space_id', 'node_id'] for the target.
	 *
	 * A live space names its folder's owner. A folder no space claims is
	 * 'unclaimed'; when a node — deleted or not — has that slug, it is named,
	 * so the folder's likely owner is offered first for adoption. Soft-deleted
	 * nodes are in here on purpose: their backups are still in the bucket and
	 * still recoverable, and must not read as nobody's.
	 */
	public static function folder_map($target) {
		$map = [];
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query('SELECT mgn_managed_node_id, mgn_slug, mgn_name, mgn_delete_time FROM mgn_managed_nodes');
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$map[$row['mgn_slug']] = [
				'status'   => 'unclaimed',
				'owner'    => 'was ' . $row['mgn_name'] . ($row['mgn_delete_time'] !== null ? ' (deleted)' : ''),
				'space_id' => null,
				'node_id'  => (int)$row['mgn_managed_node_id'],
				'deleted'  => $row['mgn_delete_time'] !== null,
			];
		}
		$prefix = TargetBackups::base_prefix($target);
		foreach (StorageSpace::on_target((int)$target->key) as $space) {
			if ($space->prefix_part() . '/' !== $prefix) {
				continue;   // a space under another folder of this bucket is not this listing's
			}
			$map[$space->folder()] = [
				'status'   => $space->is_active() ? 'live' : 'draining',
				'owner'    => $space->owner_name(),
				'space_id' => (int)$space->key,
				'node_id'  => $space->owner_kind() === StorageSpace::OWNER_NODE ? $space->owner_id() : null,
				'deleted'  => false,
			];
		}
		return $map;
	}
}
