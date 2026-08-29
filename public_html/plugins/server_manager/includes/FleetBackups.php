<?php
/**
 * FleetBackups — the fleet's view of a backup target.
 *
 * Core's TargetBackups knows how to read a bucket but deliberately does not know
 * who owns the slugs it finds there; a standalone site owns one, a management node
 * owns dozens. This supplies the fleet answer, from the managed node table, so a
 * decommissioned site's backups are labelled as such rather than looking orphaned.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));

class FleetBackups {

	/**
	 * The target's objects grouped by slug and classified against the fleet.
	 * Same return shape as TargetBackups::list_grouped().
	 */
	public static function list_grouped($target) {
		return TargetBackups::list_grouped($target, self::slug_status_map());
	}

	/**
	 * Map slug => ['node_id','deleted'] across ALL nodes, soft-deleted included.
	 *
	 * Soft-deleted nodes have to be in here, not filtered out: their backups are
	 * still in the bucket and still recoverable, and reporting them as orphaned
	 * would invite someone to delete the one copy of a decommissioned site.
	 */
	public static function slug_status_map() {
		$map = [];
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query('SELECT mgn_id, mgn_slug, mgn_delete_time FROM mgn_managed_nodes');
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$map[$row['mgn_slug']] = [
				'node_id' => (int)$row['mgn_id'],
				'deleted' => $row['mgn_delete_time'] !== null,
			];
		}
		return $map;
	}
}
