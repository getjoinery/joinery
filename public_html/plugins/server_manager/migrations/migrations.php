<?php
/**
 * Server Manager plugin migrations.
 *
 * @version 1.0
 */
return [
	[
		'id' => 'sm_001_unique_indexes',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Unique index on node slug
			$dblink->exec("CREATE UNIQUE INDEX IF NOT EXISTS mgn_slug_unique ON mgn_managed_nodes (mgn_slug)");

			// Unique index on agent name (required for ON CONFLICT in heartbeat upsert)
			$dblink->exec("CREATE UNIQUE INDEX IF NOT EXISTS ahb_agent_name_unique ON ahb_agent_heartbeats (ahb_agent_name)");
		},
	],
	[
		'id' => 'sm_002_admin_menus',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Check if already exists
			$q = $dblink->prepare("SELECT COUNT(*) FROM amu_admin_menus WHERE amu_slug = ?");
			$q->execute(['server-manager']);
			if ($q->fetchColumn() > 0) return;

			// Create parent menu
			$q = $dblink->prepare("INSERT INTO amu_admin_menus (amu_menudisplay, amu_defaultpage, amu_parent_menu_id, amu_order, amu_min_permission, amu_icon, amu_slug) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING amu_admin_menu_id");
			$q->execute(['Server Manager', '', null, 14, 10, 'server', 'server-manager']);
			$parent_id = $q->fetchColumn();

			// Child menus
			$children = [
				['Dashboard',  '/admin/server_manager',            1, 'server-manager-dashboard'],
				['Nodes',      '/admin/server_manager/nodes',      2, 'server-manager-nodes'],
				['Backups',    '/admin/server_manager/backups',     3, 'server-manager-backups'],
				['Database',   '/admin/server_manager/database',    4, 'server-manager-database'],
				['Updates',    '/admin/server_manager/updates',     5, 'server-manager-updates'],
				['Jobs',       '/admin/server_manager/jobs',        6, 'server-manager-jobs'],
			];

			$q = $dblink->prepare("INSERT INTO amu_admin_menus (amu_menudisplay, amu_defaultpage, amu_parent_menu_id, amu_order, amu_min_permission, amu_slug) VALUES (?, ?, ?, ?, 10, ?)");
			foreach ($children as $child) {
				$q->execute([$child[0], $child[1], $parent_id, $child[2], $child[3]]);
			}
		},
	],
	[
		'id' => 'sm_003_consolidate_admin_menus',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Remove sidebar entries for pages now consolidated into node_detail tabs
			$remove_slugs = [
				'server-manager-nodes',
				'server-manager-backups',
				'server-manager-database',
				'server-manager-updates',
			];
			$placeholders = implode(',', array_fill(0, count($remove_slugs), '?'));
			$q = $dblink->prepare("DELETE FROM amu_admin_menus WHERE amu_slug IN ({$placeholders})");
			$q->execute($remove_slugs);
		},
	],
	[
		'id' => 'sm_004_destinations_menu',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Check if already exists
			$q = $dblink->prepare("SELECT COUNT(*) FROM amu_admin_menus WHERE amu_slug = ?");
			$q->execute(['server-manager-destinations']);
			if ($q->fetchColumn() > 0) return;

			// Get parent menu ID
			$q = $dblink->prepare("SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = ?");
			$q->execute(['server-manager']);
			$parent_id = $q->fetchColumn();
			if (!$parent_id) return;

			$q = $dblink->prepare("INSERT INTO amu_admin_menus (amu_menudisplay, amu_defaultpage, amu_parent_menu_id, amu_order, amu_min_permission, amu_slug) VALUES (?, ?, ?, ?, 10, ?)");
			$q->execute(['Destinations', '/admin/server_manager/destinations', $parent_id, 3, 'server-manager-destinations']);
		},
	],
	[
		'id' => 'sm_005_move_marketplace_menu',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Get server-manager parent menu ID
			$q = $dblink->prepare("SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = ?");
			$q->execute(['server-manager']);
			$parent_id = $q->fetchColumn();
			if (!$parent_id) return;

			// Move marketplace menu entry to server manager, update URL
			$q = $dblink->prepare("UPDATE amu_admin_menus SET amu_parent_menu_id = ?, amu_defaultpage = ?, amu_order = 4 WHERE amu_slug = ?");
			$q->execute([$parent_id, '/admin/server_manager/marketplace', 'system-marketplace']);
		},
	],
];
