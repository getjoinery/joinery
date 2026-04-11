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
];
