<?php
/**
 * Server Manager plugin migrations.
 *
 * Admin menus are now managed declaratively via plugin.json adminMenu.
 * Menu migrations (sm_002 through sm_005) have been removed -- they are
 * already marked as applied in existing installations and are no longer needed.
 *
 * @version 1.2
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
		'id' => 'sm_002_managed_hosts_backfill',
		'version' => '1.2.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Group existing nodes by SSH tuple; create one host per unique combination.
			// Backfilled hosts default to mgh_provisioning_enabled=false so admins
			// explicitly opt each host in for automated provisioning.
			$q = $dblink->query("
				SELECT
					mgn_host,
					COALESCE(mgn_ssh_user, 'root') AS mgn_ssh_user,
					COALESCE(mgn_ssh_key_path, '')  AS mgn_ssh_key_path,
					COALESCE(mgn_ssh_port, 22)       AS mgn_ssh_port
				FROM mgn_managed_nodes
				WHERE mgn_delete_time IS NULL
				  AND mgn_mgh_host_id IS NULL
				GROUP BY
					mgn_host,
					COALESCE(mgn_ssh_user, 'root'),
					COALESCE(mgn_ssh_key_path, ''),
					COALESCE(mgn_ssh_port, 22)
			");

			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
				// Build a slug from the host address
				$base_slug = 'host-' . preg_replace('/[^a-z0-9]/', '-', strtolower($t['mgn_host']));
				$slug = $base_slug;
				$i = 1;
				while (true) {
					$exists = $dblink->prepare("SELECT COUNT(*) FROM mgh_managed_hosts WHERE mgh_slug = ?");
					$exists->execute([$slug]);
					if ($exists->fetchColumn() == 0) break;
					$slug = $base_slug . '-' . $i++;
				}

				$ins = $dblink->prepare("
					INSERT INTO mgh_managed_hosts
						(mgh_slug, mgh_name, mgh_host, mgh_ssh_user, mgh_ssh_key_path,
						 mgh_ssh_port, mgh_max_sites, mgh_provisioning_enabled, mgh_create_time)
					VALUES (?, ?, ?, ?, ?, ?, 50, false, now())
					RETURNING mgh_id
				");
				$ins->execute([
					$slug,
					$t['mgn_host'],
					$t['mgn_host'],
					$t['mgn_ssh_user'],
					$t['mgn_ssh_key_path'] !== '' ? $t['mgn_ssh_key_path'] : null,
					(int)$t['mgn_ssh_port'],
				]);
				$host_id = $ins->fetchColumn();

				// Assign all matching nodes to this host
				$upd = $dblink->prepare("
					UPDATE mgn_managed_nodes
					SET mgn_mgh_host_id = ?
					WHERE mgn_host = ?
					  AND COALESCE(mgn_ssh_user, 'root') = ?
					  AND COALESCE(mgn_ssh_key_path, '')  = ?
					  AND COALESCE(mgn_ssh_port, 22)       = ?
					  AND mgn_mgh_host_id IS NULL
				");
				$upd->execute([
					$host_id,
					$t['mgn_host'],
					$t['mgn_ssh_user'],
					$t['mgn_ssh_key_path'],
					(int)$t['mgn_ssh_port'],
				]);
			}

			// Index for provisioning poll dedup
			$dblink->exec("CREATE INDEX IF NOT EXISTS mjb_external_order_item_id_idx ON mjb_management_jobs (mjb_external_order_item_id)");
		},
	],
];
