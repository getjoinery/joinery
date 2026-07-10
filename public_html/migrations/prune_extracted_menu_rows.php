<?php
/**
 * Migration: prune the admin/profile menu rows for the store and event_manager
 * extractions.
 *
 * Core menu seeding is insert-only (overwrite=false, prune=false), so removing
 * these entries from admin_menus.json / the imperative profile seeds leaves the
 * old amu_admin_menus rows behind as dead links on installs where the owning
 * plugin never activates. This one-time prune removes them. Children are deleted
 * before parents (amu_parent_menu_id FK). On installs where the store or
 * event_manager plugin activates, PluginManager::syncMenus re-seeds each
 * plugin's entries fresh — under the renamed profile slugs (store-orders,
 * store-subscriptions, event-manager-events, event-manager-event-sessions),
 * since plugin sync rejects plugin-declared core-* slugs.
 *
 * Idempotent: a plain DELETE with an IN-list; re-running removes nothing new.
 */
function prune_extracted_menu_rows() {
	$dblink = DbConnector::get_instance()->get_db_link();

	// Leaf/child slugs first, then parents, then the profile-menu slugs.
	$child_slugs = array(
		'orders-list', 'stripe-payments', 'shadow-sessions',
		'products-list', 'product-groups', 'coupon-codes',
		'events-list', 'event-bundles', 'event-types', 'locations',
	);
	$parent_slugs = array('orders', 'products', 'events');
	$profile_slugs = array(
		'core-orders', 'core-subscriptions', 'core-events', 'core-event-sessions',
	);

	$delete = function(array $slugs) use ($dblink) {
		if (empty($slugs)) return 0;
		$in = implode(',', array_fill(0, count($slugs), '?'));
		$stmt = $dblink->prepare("DELETE FROM amu_admin_menus WHERE amu_slug IN ($in)");
		$stmt->execute($slugs);
		return $stmt->rowCount();
	};

	$removed = 0;
	$removed += $delete($child_slugs);
	$removed += $delete($parent_slugs);
	$removed += $delete($profile_slugs);

	echo "Pruned {$removed} extracted store/event menu row(s)\n";
	return true;
}
?>
