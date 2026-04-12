<?php
/**
 * Server Manager plugin migrations.
 *
 * Admin menus are now managed declaratively via plugin.json adminMenu.
 * Menu migrations (sm_002 through sm_005) have been removed -- they are
 * already marked as applied in existing installations and are no longer needed.
 *
 * @version 1.1
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
];
