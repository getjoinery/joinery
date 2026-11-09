<?php
/**
 * PollHostingOrders - Provisioning pipeline scheduled task.
 *
 * Polls getjoinery for new paid hosting orders (answers to the configured
 * domain Question) and files a customer-cloud provision for each one that
 * hasn't been processed yet. Runs every cron tick (~15 min).
 *
 * Settings required (Server Manager plugin settings):
 *   server_manager_getjoinery_api_url
 *   server_manager_getjoinery_api_public_key
 *   server_manager_getjoinery_api_secret_key
 *   server_manager_provisioning_domain_question_id
 *
 * @version 1.8 - a paid order whose product is not a hosting product is named in the run's message, not an error
 *               on every run; a product that cannot be read stays an error (reviewer2 F2-7)
 * @version 1.7 - the shared-host fallthrough is retired: it created install_node jobs nothing could run (a keyless
 *               host holds no sealed root password). An order whose product is not customer_cloud is reported,
 *               and a Starter reference (2) is refused here: Starter is sold through the configure page
 * @version 1.6 - a failed fetch of the domain answers says why (GetJoineryApiClient::last_error, B21)
 * @version 1.5 - a hosted order's provision is born with its data root encrypted where data_root_encryption_offered
 *               is on (specs/hosted_data_promise_disk_encryption.md D1a)
 * @version 1.4 - a provisioned node is given its storage space on the target Where new backups go names, once saved
 * @version 1.3 - a provisioned node is given the target Where new backups go names
 * @version 1.2 - port allocation delegates to JobCommandBuilder::next_container_port (single allocator)
 * @version 1.1
 */

class PollHostingOrders {

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_accounts_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/GetJoineryApiClient.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));

		$settings    = Globalvars::get_instance();
		$api_url     = $settings->get_setting('server_manager_getjoinery_api_url');
		$public_key  = $settings->get_setting('server_manager_getjoinery_api_public_key');
		$secret_key  = ProvisioningSetup::readApiSecret();
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
			return ['status' => 'error', 'message' => 'Failed to fetch OrderItemRequirements from getjoinery: ' . ($client->last_error !== '' ? $client->last_error : 'no answer') . '.'];
		}

		// Build set of already-handled order_item_ids (any job status, not deleted)
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->query(
			"SELECT DISTINCT mjb_external_order_item_id " .
			"FROM mjb_management_jobs " .
			"WHERE mjb_external_order_item_id IS NOT NULL AND mjb_delete_time IS NULL"
		);
		$handled = array_map('intval', array_column($q->fetchAll(PDO::FETCH_ASSOC), 'mjb_external_order_item_id'));

		// Customer-cloud orders are handled the moment their provision row exists
		// (any status) — the provisioning task owns them from there.
		$q = $db->query(
			"SELECT cvp_external_order_item_id FROM cvp_customer_cloud_provisions WHERE cvp_delete_time IS NULL"
		);
		foreach (array_column($q->fetchAll(PDO::FETCH_ASSOC), 'cvp_external_order_item_id') as $cvp_order_id) {
			$handled[] = (int)$cvp_order_id;
		}

		$started = 0;
		$skipped = 0;
		$errors  = [];
		// Paid orders whose product nothing here builds: named in the run's
		// message, but not an error on every run, since only a person changing
		// the product (or refunding the order) can change them.
		$unbuildable = [];

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

			// The product declares its provider. Only 'customer_cloud' orders are
			// fulfilled here: they become a provision row worked by
			// ProvisionCustomerCloud.
			$fulfillment = '';
			$ref = 0;
			$product_read = false;
			// Which account the server is born on is the PRODUCT's decision,
			// carried in the same reference the fulfillment picker stored.
			$hosting_mode = 'customer';
			$product_id  = (int)($order_item['odi_pro_product_id'] ?? 0);
			if ($product_id) {
				$product = $client->get('Product/' . $product_id);
				if (is_array($product)) {
					$product_read = true;
					$fulfillment = (string)($product['pro_fulfillment_provider'] ?? '');
					$ref = (int)($product['pro_fulfillment_ref'] ?? 0);
					$hosting_mode = ($ref === 1) ? 'operator' : 'customer';
				}
			}

			if ($fulfillment === 'customer_cloud' && $ref === 2) {
				$errors[] = "Order #{$order_item_id}: a Starter order cannot be fulfilled from a domain answer; Starter is sold through the configure page.";
				continue;
			}

			if ($fulfillment === 'customer_cloud') {
				if (!$user_id) {
					$errors[] = "Order #{$order_item_id}: customer-cloud order has no buyer user id — cannot start the Connect flow.";
					continue;
				}

				$provision = new CustomerCloudProvision(NULL);
				$provision->set('cvp_external_order_item_id', $order_item_id);
				$provision->set('cvp_usr_user_id', $user_id);
				$provision->set('cvp_domain',      $domain);
				$provision->set('cvp_slug',        $slug);
				$provision->set('cvp_hosting_mode', $hosting_mode);
				$provision->set('cvp_buyer_email', $admin_email);
				$provision->set('cvp_buyer_name',  $user_name);
				// A hosted birth's data root is encrypted by us, where this
				// management node offers it (one layer, never two).
				if (CustomerCloudProvision::born_encrypted_by_default($hosting_mode)) {
					$provision->set('cvp_data_root_encrypted', true);
				}

				if ($hosting_mode === 'operator') {
					// Hosted: nothing to connect and nobody to ask. The instance
					// is created in the operator's default cloud account on the next tick.
					$provision->set('cvp_status', 'ready');
					$provision->set('cvp_mail_state', 'pending');
				} elseif (($account = CustomerCloudAccount::get_for_user($user_id, 'linode')) !== null
						&& $account->get('cca_status') === 'active') {
					// The buyer already granted access — skip the Connect wait.
					$provision->set('cvp_cca_customer_cloud_account_id', $account->key);
					$provision->set('cvp_status', 'ready');
				} else {
					$provision->set('cvp_status', 'pending_connect');
					$this->send_connect_email($client, $admin_email, $user_name, $domain);
				}
				$provision->save();

				$handled[] = $order_item_id;
				$started++;
				continue;
			}

			// No shared-host fallthrough: a site placed in a container on one of
			// our hosts is a Starter order, sold and installed through the
			// configure page and its host's agent.
			if (!$product_read) {
				$errors[] = "Order #{$order_item_id}: its product could not be read from the store" . ($client->last_error !== '' ? ' (' . $client->last_error . ')' : '') . '.';
			} else {
				$unbuildable[] = "#{$order_item_id}";
			}
		}

		$msg = "Polled getjoinery: {$started} provision(s) started, {$skipped} skipped (unpaid or empty domain).";
		if ($unbuildable) {
			$msg .= ' ' . count($unbuildable) . ' paid order(s) answer the domain question with a product that is not a hosting product, so nothing builds them: '
				. implode(', ', array_slice($unbuildable, 0, 5)) . (count($unbuildable) > 5 ? ' …' : '') . '.';
		}
		if ($errors) {
			$msg .= ' ' . count($errors) . ' error(s): ' . implode('; ', array_slice($errors, 0, 3));
			if (count($errors) > 3) $msg .= ' …';
			return ['status' => 'error', 'message' => $msg];
		}
		return ['status' => 'success', 'message' => $msg];
	}

	/**
	 * Email the buyer their Connect link — a customer-cloud order can't
	 * proceed until they grant access to their cloud account. Queued through
	 * the getjoinery API like the welcome email; best-effort.
	 */
	private function send_connect_email($client, $to_email, $to_name, $domain) {
		if (!$to_email) return;

		$settings   = Globalvars::get_instance();
		$from_email = $settings->get_setting('server_manager_provisioning_welcome_from_email') ?: 'support@getjoinery.com';
		$from_name  = $settings->get_setting('server_manager_provisioning_welcome_from_name')  ?: 'Get Joinery Support';

		$connect_url = LibraryFunctions::get_absolute_url('/profile/server_manager/connect_cloud');
		$name = htmlspecialchars($to_name);
		$dom  = htmlspecialchars($domain);
		$url  = htmlspecialchars($connect_url);

		$body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333">
<h2 style="color:#1a1a1a">One step left: connect your server account</h2>
<p>Hi {$name},</p>
<p>Thanks for your order! Your site <strong>{$dom}</strong> will run on a server in your own Linode account, billed directly to you by Linode — we never mark up your hosting.</p>
<p>To set it up, connect your Linode account (or create one — new accounts get a \$100 credit):</p>
<p style="text-align:center"><a href="{$url}" style="display:inline-block;background:#02b159;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-weight:bold">Connect your account</a></p>
<p>As soon as you connect, your server is created and your site installs automatically — you'll get another email when it's ready.</p>
<p style="color:#666;font-size:.9em">Questions? Reply to this email or contact support@getjoinery.com.</p>
<p>— The Get Joinery Team</p>
</body></html>
HTML;

		$client->post('QueuedEmail', [
			'equ_from'      => $from_email,
			'equ_from_name' => $from_name,
			'equ_to'        => $to_email,
			'equ_to_name'   => $to_name,
			'equ_subject'   => 'Connect your account to finish setting up ' . $domain,
			'equ_body'      => $body,
			'equ_status'    => 2, // READY_TO_SEND
		]);
	}
}
?>
