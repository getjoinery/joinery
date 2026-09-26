<?php
/**
 * Server Manager plugin migrations.
 *
 * Admin menus are now managed declaratively via plugin.json adminMenu.
 * Menu migrations (sm_002 through sm_005) have been removed -- they are
 * already marked as applied in existing installations and are no longer needed.
 *
 * @version 1.4 - sm_008 removes the provisioning records and hosted trials of nodes removed before
 *                ManagedNode 1.27
 * @version 1.3 - sm_007 drops the customer-cloud SSH key setting (keyless provisioning)
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
				  AND mgn_mgh_managed_host_id IS NULL
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
					RETURNING mgh_managed_host_id
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
					SET mgn_mgh_managed_host_id = ?
					WHERE mgn_host = ?
					  AND COALESCE(mgn_ssh_user, 'root') = ?
					  AND COALESCE(mgn_ssh_key_path, '')  = ?
					  AND COALESCE(mgn_ssh_port, 22)       = ?
					  AND mgn_mgh_managed_host_id IS NULL
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

	[
		// The placement FK (mgn_mgh_managed_host_id) is the only sibling identity —
		// port allocation and host-scope routing read nothing else. sm_002
		// filtered to LIVE rows, so soft-deleted rows kept a NULL FK and their
		// port reservations were invisible to an FK-keyed allocator. Assign
		// every remaining NULL-FK row, deleted included, to the oldest live
		// host row for its address. Rows whose address matches no host row are
		// left alone: they are machines with no placement record, and minting
		// one is a decision made where a node is created, not in a sweep.
		'id' => 'sm_006_host_fk_covers_deleted_rows',
		'version' => '1.3.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("
				UPDATE mgn_managed_nodes n
				SET mgn_mgh_managed_host_id = (
					SELECT h.mgh_managed_host_id FROM mgh_managed_hosts h
					WHERE h.mgh_host = n.mgn_host AND h.mgh_delete_time IS NULL
					ORDER BY h.mgh_managed_host_id ASC LIMIT 1
				)
				WHERE n.mgn_mgh_managed_host_id IS NULL
				  AND EXISTS (
					SELECT 1 FROM mgh_managed_hosts h2
					WHERE h2.mgh_host = n.mgn_host AND h2.mgh_delete_time IS NULL
				  )
			");
		},
	],

	[
		// Keyless provisioning removed the customer-cloud SSH key setting: a
		// machine we create receives no key of ours. The declaration is gone
		// from plugin.json, but the seeded stg_settings row persists (seeding
		// never deletes), so it reads as an undeclared orphan. Remove it.
		'id' => 'sm_007_drop_customer_cloud_ssh_key_setting',
		'version' => '1.4.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$stmt = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?");
			$stmt->execute(['server_manager_customer_cloud_ssh_key_path']);
		},
	],

	[
		// Removing a node releases its site's records (ManagedNode 1.27): its
		// provisioning record and hosted trial go with it. A node removed
		// before that left both live, and the provisioning task kept working
		// on them for a site nobody tracks. Remove them the same way. Such a
		// site's domain is parked by the domain stage on its next run.
		'id' => 'sm_008_release_removed_nodes_provisions',
		'version' => '1.26.6',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$tables = $dblink->query("SELECT to_regclass('cvp_customer_cloud_provisions') IS NOT NULL
				AND to_regclass('htr_hosted_trials') IS NOT NULL
				AND to_regclass('mgn_managed_nodes') IS NOT NULL")->fetchColumn();
			if (!$tables) {
				return;   // a fresh install: no provision has ever outlived its node here
			}
			$dblink->exec("
				UPDATE htr_hosted_trials t SET htr_delete_time = now()
				FROM cvp_customer_cloud_provisions p
				JOIN mgn_managed_nodes n ON n.mgn_managed_node_id = p.cvp_mgn_managed_node_id
				WHERE t.htr_cvp_customer_cloud_provision_id = p.cvp_customer_cloud_provision_id
				  AND t.htr_delete_time IS NULL AND p.cvp_delete_time IS NULL
				  AND n.mgn_delete_time IS NOT NULL
			");
			$dblink->exec("
				UPDATE cvp_customer_cloud_provisions p SET cvp_delete_time = now()
				FROM mgn_managed_nodes n
				WHERE n.mgn_managed_node_id = p.cvp_mgn_managed_node_id
				  AND p.cvp_delete_time IS NULL AND n.mgn_delete_time IS NOT NULL
			");
		},
	],
];
