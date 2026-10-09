<?php
/**
 * Server Manager plugin migrations.
 *
 * Admin menus are now managed declaratively via plugin.json adminMenu.
 * Menu migrations (sm_002 through sm_005) have been removed -- they are
 * already marked as applied in existing installations and are no longer needed.
 *
 * @version 1.10 - sm_017 moves the fleet verify interval, and node policies, still at 30 days to the weekly default
 * @version 1.9 - sm_016 marks the boxes a test-account plane made since the token swap as test boxes
 * @version 1.8 - sm_013 moves incidents marked Looking to New (Looking is no longer a triage)
 * @version 1.7 - sm_011 drops the fleet-wide backup cap setting; sm_012 cancels the unfinished jobs of nodes
 *                removed before ManagedNode 1.36
 * @version 1.6 - sm_010 ends the retired Clone's rows (site_copy.md WP9): from_backup provisions, and the
 *                cvp_clone_key_sealed and cvp_backup_source columns
 * @version 1.5 - sm_009 seeds the editable emails of a server handover to the customer's own Linode account
 *                and of the Services rows it leaves behind
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
					VALUES (?, ?, ?, ?, ?, ?, NULL, false, now())
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
	[
		// The customer emails of handing a Managed site's server to the
		// customer's own Linode account (specs/managed_to_self_hosted_transfer.md
		// §6), and the two the Services rows it leaves behind send. Seeded once
		// and editable on the email templates page: an existing row of the
		// same name is never overwritten. None of them ever carries the
		// transfer code — they link to the signed-in page that shows it.
		'id' => 'sm_009_instance_transfer_email_templates',
		'version' => '1.30.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$templates = array(
				array('instance_transfer_invite', 'Your site can move to your own Linode account',
					'<p>Hi *buyer_name*,</p>
<p>Your site <strong>*domain*</strong> runs on its own server in our Linode account. It can now move into a Linode account of your own: the same server, still running, with the same address. Nothing is copied or rebuilt, and nothing about your domain changes.</p>
<p>From then on Linode bills you for the server{monthly_cost} (*monthly_cost* at list price){end}, and we stop billing you for hosting. Your email and backups keep working through us, already paid for until the date your hosting was paid to.</p>
<p><strong>To get ready:</strong></p>
<ol>
<li>Open a Linode account if you do not have one (<a href="*linode_signup_url*">sign up here</a>) and add a payment card to it.</li>
<li>When it is ready, open <a href="*sites_url*">your sites</a> and press <em>I\'m ready — get my transfer code</em>.</li>
</ol>
<p>The page shows the code and the three steps to accept it in Linode\'s Cloud Manager.</p>'),
				array('instance_transfer_code_ready', 'Your server transfer code is ready',
					'<p>Hi *buyer_name*,</p>
<p>The transfer code for <strong>*domain*</strong> is ready. It works until *code_expiry*.</p>
<p>For your safety the code is not in this email: anyone holding it could take the server. <a href="*sites_url*">Open your sites</a> while signed in to see it, with the steps to accept it in Linode\'s Cloud Manager.</p>'),
				array('instance_transfer_code_expired', 'Your server transfer code expired',
					'<p>Hi *buyer_name*,</p>
<p>The transfer code for <strong>*domain*</strong> ran out before it was accepted. Nothing has changed: your site is still with us, as it was.</p>
<p>Whenever you are ready, <a href="*sites_url*">get a new code</a>.</p>'),
				array('instance_transfer_failed', 'Moving your server: we are looking into it',
					'<p>Hi *buyer_name*,</p>
<p>Linode could not finish moving the server for <strong>*domain*</strong> into your account. Your site is still running, with us, as it was.</p>
<p>We have been told and are looking into it. The most common cause is a server in your Linode account already named <code>*instance_label*</code>; if you have one, renaming it helps. We will email you when a new code is ready.</p>'),
				array('instance_transfer_done', 'Your server is now in your own Linode account',
					'<p>Hi *buyer_name*,</p>
<p>The server for <strong>*domain*</strong> is now in your own Linode account. Linode bills you for it from here{monthly_cost} (*monthly_cost* at list price){end}{linode_backups}, including the Linode Backups that moved with it{end}. We have cancelled your hosting subscription.</p>
{was_shut_down}<p><strong>It moved powered off.</strong> Boot it in Cloud Manager to bring the site back.</p>{end}
<p><strong>What is yours now</strong></p>
<ul>
<li><strong>Root access.</strong> The server takes no password over SSH and holds no key of ours. To get in, reset the root password in Cloud Manager, sign in through the Lish console, and add your own SSH key.</li>
<li><strong>Reverse DNS</strong> for its address is set in your own Cloud Manager now.</li>
<li><strong>Email and backups</strong> carry on through us as a services subscription{paid_until}, paid until *paid_until*{end}. You can see them on <a href="*sites_url*/services">your connected sites</a>.</li>
<li>We still look after the site — updates, backups and alerts — until you tell us to stop, from <a href="*sites_url*">your sites</a>.</li>
</ul>
{dns_records}<p><strong>DNS records we hold for you</strong></p>
<pre>*dns_records*</pre>{end}'),
				array('services_moved_grace', 'Email and backups for your site need paying',
					'<p>Hi *buyer_name*,</p>
<p>The paid-through date for the *services* on <strong>*domain*</strong> has passed. Everything keeps working{grace_ends} until *grace_ends*{end}; after that it stops.</p>
<p>See where it stands on <a href="*manage_url*">your connected sites</a>.</p>'),
				array('services_moved_suspended', 'Email and backups for your site have stopped',
					'<p>Hi *buyer_name*,</p>
<p>The *services* on <strong>*domain*</strong> stopped today: the paid-through date passed and the grace period ran out.</p>
<p>Backups already stored with us are kept *retention_days* days and then deleted. Renew before then and it all carries on in place. See <a href="*manage_url*">your connected sites</a>.</p>'),
			);
			$insert = $dblink->prepare("INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
				SELECT ?, 2, ?, ?, now(), now()
				WHERE NOT EXISTS (SELECT 1 FROM emt_email_templates WHERE emt_name = ?)");
			foreach ($templates as $t) {
				$insert->execute(array($t[0], $t[1], $t[2], $t[0]));
			}
		},
	],
	[
		// Clone is retired (specs/site_copy.md WP9): a site on a new server is
		// a site copy. A from_backup provision still working has no pipeline
		// left to finish it, so it fails, saying so; every from_backup row is
		// then recorded as the install it was nearest to (fresh), since the
		// mode no longer exists for the row to be saved with. The sealed clone
		// key and the backup-source column go: nothing reads them, and a key
		// left at rest is a secret with no purpose.
		'id' => 'sm_010_clone_retired',
		'version' => '1.30.1',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			if (!$dblink->query("SELECT to_regclass('cvp_customer_cloud_provisions') IS NOT NULL")->fetchColumn()) {
				return;   // the table does not exist here: nothing was ever cloned
			}
			$cols = $dblink->query("SELECT column_name FROM information_schema.columns
				WHERE table_name = 'cvp_customer_cloud_provisions'")->fetchAll(PDO::FETCH_COLUMN);
			if (in_array('cvp_install_mode', $cols, true) && in_array('cvp_status', $cols, true)) {
				$fail = $dblink->prepare("UPDATE cvp_customer_cloud_provisions
					SET cvp_status = 'failed', cvp_error = ?
					WHERE cvp_install_mode = 'from_backup'
					  AND cvp_status IN ('pending_connect', 'ready', 'booting', 'installing')");
				$fail->execute(array('Cloning is retired and this clone never finished. Copy the source site from its node\'s Copy tab.'));
				$dblink->exec("UPDATE cvp_customer_cloud_provisions SET cvp_install_mode = 'fresh'
					WHERE cvp_install_mode = 'from_backup'");
			}
			foreach (array('cvp_clone_key_sealed', 'cvp_backup_source') as $col) {
				if (in_array($col, $cols, true)) {
					$dblink->exec("ALTER TABLE cvp_customer_cloud_provisions DROP COLUMN {$col}");
				}
			}
		},
	],

	[
		// One machine takes one backup at a time (FleetBackupPolicy 1.7): the
		// fleet-wide cap is gone from plugin.json, and its seeded row would
		// read as an undeclared orphan. Remove it.
		'id' => 'sm_011_drop_fleet_backup_max_concurrent_setting',
		'version' => '1.30.6',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$stmt = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name = ?");
			$stmt->execute(['server_manager_fleet_backup_max_concurrent']);
		},
	],

	[
		// Removing a node cancels its unfinished jobs (ManagedNode 1.36). A
		// node removed before that left them open for good: its agent is
		// refused once the node is gone, so a pending job was never claimed
		// and a running one never reported. Every job is addressed to a node
		// and only that node's agent runs it, so the ones taken are the open
		// jobs whose node is missing, removed, or no longer named (the
		// deletion rule nulls it).
		'id' => 'sm_012_withdraw_jobs_of_removed_nodes',
		'version' => '1.30.6',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$tables = $dblink->query("SELECT to_regclass('mjb_management_jobs') IS NOT NULL
				AND to_regclass('mgn_managed_nodes') IS NOT NULL")->fetchColumn();
			if (!$tables) {
				return;   // a fresh install: no job has ever outlived its node here
			}
			$dblink->exec("
				UPDATE mjb_management_jobs j
				SET mjb_status = 'cancelled',
				    mjb_error_message = 'The node was removed from the dashboard before this job finished.',
				    mjb_completed_time = now(),
				    mjb_update_time = now()
				WHERE j.mjb_status IN ('queued', 'pending', 'running')
				  AND j.mjb_delete_time IS NULL
				  AND NOT EXISTS (SELECT 1 FROM mgn_managed_nodes n
				                  WHERE n.mgn_managed_node_id = j.mjb_mgn_managed_node_id
				                    AND n.mgn_delete_time IS NULL)
			");
		},
	],
	[
		// Looking read the same as New (both need a person) and is no longer
		// offered, so an incident marked Looking becomes New. The triage
		// event that set it stays on its timeline.
		'id' => 'sm_013_looking_incidents_to_new',
		'version' => '1.30.8',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$ready = $dblink->query("SELECT to_regclass('inc_incident_records') IS NOT NULL
				AND EXISTS (SELECT 1 FROM information_schema.columns
				            WHERE table_name = 'inc_incident_records' AND column_name = 'inc_triage')")->fetchColumn();
			if (!$ready) {
				return;   // no incident table, or one from before triage: nothing is marked Looking
			}
			$dblink->exec("UPDATE inc_incident_records SET inc_triage = 'new' WHERE inc_triage = 'looking'");
		},
	],
	[
		// Every choice of backup target is explicit (specs/storage_targets.md
		// R6). Two paths fell back to "the one enabled target": a node that
		// named none, and backup storage for customers with its setting blank.
		// Where new backups go takes over the customers' setting, and each node
		// the fallback was serving is given that target by name, so nothing
		// changes where any backup goes today.
		'id' => 'sm_014_backup_targets_named_not_inferred',
		'version' => '1.30.31',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$ready = $dblink->query("SELECT to_regclass('bkt_backup_targets') IS NOT NULL
				AND to_regclass('mgn_managed_nodes') IS NOT NULL")->fetchColumn();
			if (!$ready) {
				return;   // a fresh install: nothing was ever inferred
			}
			$enabled = $dblink->query("SELECT bkt_backup_target_id FROM bkt_backup_targets
				WHERE bkt_enabled AND bkt_delete_time IS NULL")->fetchAll(PDO::FETCH_COLUMN);
			$sole = count($enabled) === 1 ? (int)$enabled[0] : 0;

			$old = $dblink->query("SELECT stg_value FROM stg_settings
				WHERE stg_name = 'server_manager_services_shelf_target_id' LIMIT 1")->fetchColumn();
			$chosen = ((int)$old > 0 && in_array((string)(int)$old, array_map('strval', $enabled), true)) ? (int)$old : $sole;

			$current = $dblink->query("SELECT stg_value FROM stg_settings
				WHERE stg_name = 'server_manager_backup_target_id' LIMIT 1")->fetchColumn();
			if ($chosen > 0 && (int)$current === 0) {
				if ($current === false) {
					$q = $dblink->prepare("INSERT INTO stg_settings (stg_name, stg_value, stg_create_time, stg_update_time, stg_group_name)
						VALUES ('server_manager_backup_target_id', ?, now(), now(), 'services')");
				} else {
					$q = $dblink->prepare("UPDATE stg_settings SET stg_value = ?, stg_update_time = now()
						WHERE stg_name = 'server_manager_backup_target_id'");
				}
				$q->execute(array((string)$chosen));
			}
			$dblink->exec("DELETE FROM stg_settings WHERE stg_name = 'server_manager_services_shelf_target_id'");

			// The node column is gone from a deployment built after storage
			// spaces (sm_015 reads it once, where it exists).
			$has_column = (bool)$dblink->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns
				WHERE table_name = 'mgn_managed_nodes' AND column_name = 'mgn_bkt_backup_target_id')")->fetchColumn();
			if ($sole > 0 && $has_column) {
				$q = $dblink->prepare("UPDATE mgn_managed_nodes SET mgn_bkt_backup_target_id = ?
					WHERE mgn_bkt_backup_target_id IS NULL AND mgn_delete_time IS NULL");
				$q->execute(array($sole));
			}
		},
	],
	[
		// Storage spaces (specs/storage_targets.md WP4): every stored backup on
		// this management node is in one owner's folder on one target, and every
		// reader reaches it through that record. This writes the records for
		// what exists today; no bytes move. Each working node with a target
		// gets an active space for {folder}/{slug}/ there; each customer of
		// backup storage gets one for {folder}/t{id}/ on the target Where new
		// backups go names; every ledger row and run joins its customer's
		// space. A folder that would overlap another owner's is left unclaimed
		// and said in the log: the target page offers it for adoption.
		'id' => 'sm_015_storage_spaces',
		'version' => '1.30.32',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$ready = $dblink->query("SELECT to_regclass('sps_storage_spaces') IS NOT NULL
				AND to_regclass('bkt_backup_targets') IS NOT NULL
				AND to_regclass('mgn_managed_nodes') IS NOT NULL")->fetchColumn();
			if (!$ready) {
				return;   // a fresh install: nothing stored yet
			}

			// The folder in its one stored form.
			foreach ($dblink->query("SELECT bkt_backup_target_id, bkt_path_prefix FROM bkt_backup_targets")->fetchAll(PDO::FETCH_ASSOC) as $t) {
				$normal = BackupTarget::normalise_prefix((string)$t['bkt_path_prefix']);
				if ($normal !== (string)$t['bkt_path_prefix']) {
					$dblink->prepare("UPDATE bkt_backup_targets SET bkt_path_prefix = ? WHERE bkt_backup_target_id = ?")
						->execute(array($normal, (int)$t['bkt_backup_target_id']));
				}
			}
			$prefix_of = array();
			foreach ($dblink->query("SELECT bkt_backup_target_id, bkt_path_prefix FROM bkt_backup_targets
					WHERE bkt_delete_time IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $t) {
				$prefix_of[(int)$t['bkt_backup_target_id']] = (string)$t['bkt_path_prefix'];
			}

			$open = function (int $target_id, string $folder, string $column, int $owner_id) use ($dblink, $prefix_of) {
				if (!isset($prefix_of[$target_id]) || !preg_match('/^[A-Za-z0-9_-]+$/', $folder)) {
					return;
				}
				$base = $prefix_of[$target_id] . '/' . $folder . '/';
				$q = $dblink->prepare("SELECT sps_base_key, sps_mgn_managed_node_id, sps_svt_service_tenant_id
					FROM sps_storage_spaces WHERE sps_bkt_backup_target_id = ? AND sps_state IN ('active', 'draining')");
				$q->execute(array($target_id));
				foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $other) {
					$mine = (int)($other[$column] ?? 0) === $owner_id;
					if ($other['sps_base_key'] === $base && $mine) {
						return;   // already there: run twice, write once
					}
					if (strpos($base, $other['sps_base_key']) === 0 || strpos($other['sps_base_key'], $base) === 0) {
						error_log('sm_015: ' . $base . ' on target ' . $target_id . ' overlaps ' . $other['sps_base_key']
							. '; left unclaimed for adoption.');
						return;
					}
				}
				$dblink->prepare("INSERT INTO sps_storage_spaces (sps_bkt_backup_target_id, sps_base_key, $column, sps_state,
						sps_opened_time, sps_update_time) VALUES (?, ?, ?, 'active', now(), now())")
					->execute(array($target_id, $base, $owner_id));
			};

			$has_column = (bool)$dblink->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns
				WHERE table_name = 'mgn_managed_nodes' AND column_name = 'mgn_bkt_backup_target_id')")->fetchColumn();
			if ($has_column) {
				$nodes = $dblink->query("SELECT mgn_managed_node_id, mgn_slug, mgn_bkt_backup_target_id FROM mgn_managed_nodes
					WHERE mgn_delete_time IS NULL AND mgn_bkt_backup_target_id IS NOT NULL
					  AND COALESCE(mgn_install_state, '') <> 'copy'
					ORDER BY mgn_managed_node_id")->fetchAll(PDO::FETCH_ASSOC);
				foreach ($nodes as $n) {
					$open((int)$n['mgn_bkt_backup_target_id'], trim((string)$n['mgn_slug']), 'sps_mgn_managed_node_id',
						(int)$n['mgn_managed_node_id']);
				}
			} elseif ((int)$dblink->query("SELECT count(*) FROM mgn_managed_nodes WHERE mgn_delete_time IS NULL")->fetchColumn() > 0) {
				// Nothing says where these nodes backed up: none is given a space,
				// so none is backed up from here until it is moved to a target.
				error_log('sm_015: mgn_managed_nodes has no mgn_bkt_backup_target_id column, so no node was given a '
					. 'storage space. Move each node to a backup target on its Backups tab.');
			}

			$tenants_ready = $dblink->query("SELECT to_regclass('svt_service_tenants') IS NOT NULL")->fetchColumn();
			$shelf = (int)$dblink->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'server_manager_backup_target_id' LIMIT 1")->fetchColumn();
			if ($tenants_ready && $shelf > 0) {
				$rows = $dblink->query("SELECT svt_service_tenant_id, svt_slug FROM svt_service_tenants
					WHERE svt_service = 'shelf' AND svt_mgn_managed_node_id IS NULL AND svt_delete_time IS NULL
					  AND svt_state <> 'unpaid' ORDER BY svt_service_tenant_id")->fetchAll(PDO::FETCH_ASSOC);
				foreach ($rows as $r) {
					$open($shelf, trim((string)$r['svt_slug']), 'sps_svt_service_tenant_id', (int)$r['svt_service_tenant_id']);
				}
				foreach (array('svo_shelf_objects' => array('svo', 'svo_key'), 'svr_shelf_runs' => array('svr', 'svr_base_key')) as $table => $c) {
					if (!$dblink->query("SELECT to_regclass('$table') IS NOT NULL")->fetchColumn()) {
						continue;
					}
					list($p, $key) = $c;
					$dblink->exec("UPDATE $table SET {$p}_sps_storage_space_id = s.sps_storage_space_id
						FROM sps_storage_spaces s
						WHERE {$p}_sps_storage_space_id IS NULL AND s.sps_svt_service_tenant_id = {$p}_svt_service_tenant_id
						  AND s.sps_state IN ('active', 'draining')
						  AND left($key, length(s.sps_base_key)) = s.sps_base_key");
				}
			}
		},
	],
	[
		'id' => 'sm_016_cloud_account_backfill',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			// The columns come from the data classes; this runs after update_database added them.
			foreach (array('mgh_managed_hosts' => 'mgh_cloud_account', 'mgn_managed_nodes' => 'mgn_cloud_account') as $table => $column) {
				$has = (bool)$dblink->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns
					WHERE table_name = '$table' AND column_name = '$column')")->fetchColumn();
				if (!$has) {
					error_log('sm_016: ' . $table . ' has no ' . $column . ' column yet; no box was marked.');
					return;
				}
			}
			// This plane's own account, read from the provider once when the setting was never saved.
			$company = trim((string)Globalvars::get_instance()->get_setting(CloudAccounts::COMPANY_SETTING));
			$token = ProvisionCustomerCloud::operator_compute_token();
			if ($company === '' && $token !== '') {
				try {
					$company = trim((new LinodeComputeDriver($token))->accountCompany());
					ProvisioningSetup::writeSetting(CloudAccounts::COMPANY_SETTING, $company);
				} catch (Throwable $e) {
					error_log('sm_016: the operator token could not read its account company (' . $e->getMessage() . '); no box was marked.');
					return;
				}
			}
			$marked = CloudAccounts::backfill($dblink, $company);
			error_log('sm_016: ' . $marked . ' box(es) marked as test-account boxes.');
		},
	],
	[
		// A node's newest backup is verified weekly (specs/storage_targets.md
		// F1): retention keeps the newest verified chain and everything newer,
		// and a node with nothing verified for eight days is an incident. The
		// fleet setting and each node's saved policy still at the old shipped
		// 30 days move to 7; any other choice stays.
		'id' => 'sm_017_verify_weekly',
		'version' => '1.30.36',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("UPDATE stg_settings SET stg_value = '7'
				WHERE stg_name = 'server_manager_fleet_backup_verify_every_days' AND stg_value = '30'");
			$ready = $dblink->query("SELECT to_regclass('mgn_managed_nodes') IS NOT NULL AND EXISTS (SELECT 1
				FROM information_schema.columns WHERE table_name = 'mgn_managed_nodes' AND column_name = 'mgn_backup_policy')")->fetchColumn();
			if (!$ready) {
				return;
			}
			$moved = 0;
			$update = $dblink->prepare("UPDATE mgn_managed_nodes SET mgn_backup_policy = ? WHERE mgn_managed_node_id = ?");
			foreach ($dblink->query("SELECT mgn_managed_node_id, mgn_backup_policy FROM mgn_managed_nodes
					WHERE mgn_backup_policy IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $n) {
				$policy = json_decode((string)$n['mgn_backup_policy'], true);
				if (!is_array($policy) || !isset($policy['verify_every_days']) || (int)$policy['verify_every_days'] !== 30) {
					continue;
				}
				$policy['verify_every_days'] = 7;
				$update->execute(array(json_encode($policy), (int)$n['mgn_managed_node_id']));
				$moved++;
			}
			error_log('sm_017: ' . $moved . ' node backup polic' . ($moved === 1 ? 'y' : 'ies') . ' moved to weekly verification.');
		},
	],
];
