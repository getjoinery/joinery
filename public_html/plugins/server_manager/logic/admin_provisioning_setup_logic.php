<?php
/**
 * admin_provisioning_setup_logic - Guided activation for the hosting
 * provisioning pipeline.
 *
 * POST actions delegate to ProvisioningSetup and redirect back with a
 * session message; GET renders the live status of every checklist item.
 *
 * @version 1.1 - the domain-registrar credentials card
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));

function admin_provisioning_setup_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_url = '/admin/server_manager/provisioning_setup';
	$page_regex = '/\/admin\/server_manager\/provisioning_setup/';

	$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($input['action'] ?? '') : '';
	if ($action !== '') {
		$message = null;
		$error = null;
		try {
			if ($action === 'setup_api') {
				$result = ProvisioningSetup::setupApiCredentials(!empty($input['rotate']));
				$message = $result['message'];
			} elseif ($action === 'create_question') {
				$result = ProvisioningSetup::ensureDomainQuestion();
				$message = $result['message'];
			} elseif ($action === 'activate_tasks') {
				$result = ProvisioningSetup::activateTasks();
				$message = $result['message'];
			} elseif ($action === 'save_email') {
				ProvisioningSetup::writeSetting('server_manager_provisioning_welcome_from_email',
					trim($input['welcome_from_email'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_provisioning_welcome_from_name',
					trim($input['welcome_from_name'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_provisioning_admin_alert_email',
					trim($input['admin_alert_email'] ?? ''));
				$message = 'Email settings saved.';
			} elseif ($action === 'generate_ssh_key') {
				$result = ProvisioningSetup::ensureSshKey();
				if ($result['ok']) {
					$message = $result['message'];
				} else {
					$error = $result['message'];
				}
			} elseif ($action === 'save_cloud') {
				$ssh_key_path = trim($input['ssh_key_path'] ?? '');
				ProvisioningSetup::writeSetting('server_manager_customer_cloud_ssh_key_path', $ssh_key_path);
				ProvisioningSetup::writeSetting('server_manager_linode_referral_url',
					trim($input['referral_url'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_customer_cloud_region',
					trim($input['region'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_customer_cloud_type',
					trim($input['instance_type'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_customer_cloud_image',
					trim($input['image'] ?? ''));
				$message = 'Customer-cloud settings saved.';
				if ($ssh_key_path !== '' && !file_exists($ssh_key_path . '.pub')) {
					$message .= ' Warning: ' . $ssh_key_path . '.pub not found — it is required on created instances.';
				}
			} elseif ($action === 'save_domains') {
				ProvisioningSetup::writeSetting('server_manager_namecheap_api_user',
					trim($input['ncp_api_user'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_namecheap_client_ip',
					trim($input['ncp_client_ip'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_namecheap_sandbox',
					!empty($input['ncp_sandbox']) ? '1' : '');
				ProvisioningSetup::writeSetting('server_manager_domain_tlds',
					trim($input['domain_tlds'] ?? '') ?: 'com net org');
				// A blank key field means "leave the stored key alone" — the
				// field never shows the key, so blank cannot mean "erase it"
				// without erasing it every time the rest of the card is saved.
				$key = trim($input['ncp_api_key'] ?? '');
				if ($key !== '') {
					ProvisioningSetup::writeSecret('server_manager_namecheap_api_key', $key);
				}
				$message = 'Domain registrar settings saved.';
			} else {
				$error = 'Unknown action.';
			}
		} catch (Exception $e) {
			$error = $e->getMessage();
		}

		if ($message) {
			$session->save_message(new DisplayMessage($message, 'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		if ($error) {
			$session->save_message(new DisplayMessage($error, 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect($page_url);
	}

	return LogicResult::render(array(
		'status' => ProvisioningSetup::status(),
	));
}
