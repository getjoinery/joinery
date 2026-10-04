<?php
/**
 * Server Manager plugin migrations.
 *
 * Admin menus are now managed declaratively via plugin.json adminMenu.
 * Menu migrations (sm_002 through sm_005) have been removed -- they are
 * already marked as applied in existing installations and are no longer needed.
 *
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
];
