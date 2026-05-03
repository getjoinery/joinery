<?php
/**
 * PollHostingOrders - Provisioning pipeline scheduled task.
 *
 * Polls getjoinery for new paid hosting orders (answers to the configured
 * domain Question) and kicks off an install_node job for each one that
 * hasn't been processed yet. Runs every cron tick (~15 min).
 *
 * Settings required (Server Manager plugin settings):
 *   server_manager_getjoinery_api_url
 *   server_manager_getjoinery_api_public_key
 *   server_manager_getjoinery_api_secret_key
 *   server_manager_provisioning_domain_question_id
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class PollHostingOrders implements ScheduledTaskInterface {

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/GetJoineryApiClient.php'));

		$settings    = Globalvars::get_instance();
		$api_url     = $settings->get_setting('server_manager_getjoinery_api_url');
		$public_key  = $settings->get_setting('server_manager_getjoinery_api_public_key');
		$secret_key  = $settings->get_setting('server_manager_getjoinery_api_secret_key');
		$question_id = (int)$settings->get_setting('server_manager_provisioning_domain_question_id');

		if (!$api_url || !$public_key || !$secret_key || !$question_id) {
			return [
				'status'  => 'skipped',
				'message' => 'Provisioning not configured — set getjoinery API credentials and provisioning_domain_question_id in Server Manager plugin settings.',
			];
		}

		$client = new GetJoineryApiClient($api_url, $public_key, $secret_key);

		// Fetch all answers to the domain question (up to 200 per poll)
		$requirements = $client->get('OrderItemRequirements', [
			'oir_qst_question_id' => $question_id,
			'numperpage'          => 200,
		]);

		if (!is_array($requirements)) {
			return ['status' => 'error', 'message' => 'Failed to fetch OrderItemRequirements from getjoinery — check API credentials.'];
		}

		// Build set of already-handled order_item_ids (any job status, not deleted)
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query(
			"SELECT DISTINCT mjb_external_order_item_id " .
			"FROM mjb_management_jobs " .
			"WHERE mjb_external_order_item_id IS NOT NULL AND mjb_delete_time IS NULL"
		);
		$handled = array_map('intval', array_column($q->fetchAll(PDO::FETCH_ASSOC), 'mjb_external_order_item_id'));

		$started = 0;
		$skipped = 0;
		$errors  = [];

		foreach ($requirements as $req) {
			$order_item_id = (int)($req['oir_odi_order_item_id'] ?? 0);
			$domain        = trim($req['oir_answer'] ?? '');

			if (!$order_item_id || !$domain) { $skipped++; continue; }
			if (in_array($order_item_id, $handled, true)) { continue; }

			// Confirm the order is paid and get the buyer's user ID
			$order_item = $client->get('OrderItem/' . $order_item_id);
			if (!is_array($order_item) || ($order_item['odi_status'] ?? '') !== 'paid') {
				$skipped++;
				continue;
			}
			$user_id = (int)($order_item['odi_usr_user_id'] ?? 0);

			// Fetch buyer's email and display name for the welcome email
			$admin_email = '';
			$user_name   = 'Customer';
			if ($user_id) {
				$user = $client->get('User/' . $user_id);
				if (is_array($user)) {
					$admin_email = $user['usr_email'] ?? '';
					$first = trim($user['usr_first_name'] ?? '');
					$last  = trim($user['usr_last_name'] ?? '');
					if ($first || $last) $user_name = trim($first . ' ' . $last);
				}
			}

			// Sanitize domain to a URL-safe slug
			$slug = strtolower($domain);
			$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
			$slug = preg_replace('/-+/', '-', $slug);
			$slug = trim($slug, '-');

			if (!$slug) {
				$errors[] = "Order #{$order_item_id}: could not derive slug from domain '{$domain}'.";
				continue;
			}

			// Check for an existing non-failed node with this slug
			// (two customers buying the same domain — needs manual resolution)
			$existing_multi = new MultiManagedNode(['slug' => $slug, 'deleted' => false]);
			$existing_multi->load();
			$existing_failed = null;
			foreach ($existing_multi as $ex) {
				if ($ex->get('mgn_install_state') !== 'install_failed') {
					$errors[] = "Order #{$order_item_id}: domain '{$domain}' is already provisioned (slug: {$slug}) — manual resolution required.";
					continue 2;
				}
				$existing_failed = $ex;
			}

			// Pick the least-loaded provisioning-enabled host
			$host = ManagedHost::pick_for_provisioning();
			if (!$host) {
				$errors[] = "Order #{$order_item_id}: no provisioning host with available capacity — add a host or raise mgh_max_sites.";
				continue;
			}

			// Pick next available Docker host port for this host (floor 8080)
			$port_q = $db->prepare(
				"SELECT MAX(mgn_port) FROM mgn_managed_nodes WHERE mgn_mgh_host_id = ? AND mgn_delete_time IS NULL"
			);
			$port_q->execute([$host->key]);
			$max_port = (int)$port_q->fetchColumn();
			$port = max(8080, $max_port + 1);

			// Create or reuse a failed node record
			if ($existing_failed) {
				$node = $existing_failed;
				$node->set('mgn_host',         $host->get('mgh_host'));
				$node->set('mgn_ssh_user',     $host->get('mgh_ssh_user'));
				$node->set('mgn_ssh_key_path', $host->get('mgh_ssh_key_path'));
				$node->set('mgn_ssh_port',     $host->get('mgh_ssh_port'));
				$node->set('mgn_mgh_host_id',  $host->key);
				$node->set('mgn_install_state', 'installing');
				$node->set('mgn_ssl_state',    'pending');
				$node->set('mgn_port',         $port);
				$node->save();
			} else {
				$node = new ManagedNode(NULL);
				$node->set('mgn_name',         $domain);
				$node->set('mgn_slug',         $slug);
				$node->set('mgn_host',         $host->get('mgh_host'));
				$node->set('mgn_ssh_user',     $host->get('mgh_ssh_user'));
				$node->set('mgn_ssh_key_path', $host->get('mgh_ssh_key_path'));
				$node->set('mgn_ssh_port',     $host->get('mgh_ssh_port'));
				$node->set('mgn_site_url',     'https://' . $domain);
				$node->set('mgn_mgh_host_id',  $host->key);
				$node->set('mgn_install_state', 'installing');
				$node->set('mgn_ssl_state',    'pending');
				$node->set('mgn_port',         $port);
				$node->set('mgn_enabled',      true);
				$node->prepare();
				$node->save();
				$node->load();
			}

			// Build install_node job steps
			$job_params = [
				'mode'        => 'fresh',
				'sitename'    => $slug,
				'domain'      => $domain,
				'docker_mode' => 'docker',
				'port'        => $port,
				'admin_email' => $admin_email,
				'user_name'   => $user_name,
			];

			try {
				$steps = JobCommandBuilder::build_install_node($node, $job_params);
			} catch (Exception $e) {
				$errors[] = "Order #{$order_item_id}: failed to build install steps — " . $e->getMessage();
				$node->set('mgn_install_state', 'install_failed');
				$node->save();
				continue;
			}

			$job = ManagementJob::createJob($node->key, 'install_node', $steps, $job_params, null);
			$job->set('mjb_external_order_item_id', $order_item_id);
			$job->save();

			$handled[] = $order_item_id; // Prevent double-processing within this cycle
			$started++;
		}

		$msg = "Polled getjoinery: {$started} provision(s) started, {$skipped} skipped (unpaid or empty domain).";
		if ($errors) {
			$msg .= ' ' . count($errors) . ' error(s): ' . implode('; ', array_slice($errors, 0, 3));
			if (count($errors) > 3) $msg .= ' …';
			return ['status' => 'error', 'message' => $msg];
		}
		return ['status' => 'success', 'message' => $msg];
	}
}
?>
