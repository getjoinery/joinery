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
			} elseif ($action === 'save_cloud') {
				ProvisioningSetup::writeSetting('server_manager_linode_referral_url',
					trim($input['referral_url'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_customer_cloud_region',
					trim($input['region'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_customer_cloud_type',
					trim($input['instance_type'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_customer_cloud_image',
					trim($input['image'] ?? ''));
				$message = 'Customer-cloud settings saved.';
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
			} elseif ($action === 'save_hosted') {
				ProvisioningSetup::writeSetting('server_manager_hosted_send_allowance',
					(string)max(0, (int)($input['send_allowance'] ?? 0)));
				ProvisioningSetup::writeSetting('server_manager_hosted_shelf_allowance_gb',
					(string)max(0, (int)($input['shelf_allowance_gb'] ?? 0)));
				ProvisioningSetup::writeSetting('server_manager_hosted_trial_days',
					(string)max(0, (int)($input['trial_days'] ?? 0)));
				ProvisioningSetup::writeSetting('server_manager_hosted_grace_days',
					(string)max(0, (int)($input['grace_days'] ?? 0)));
				ProvisioningSetup::writeSetting('server_manager_hosted_shelf_days',
					(string)max(0, (int)($input['shelf_days'] ?? 0)));
				ProvisioningSetup::writeSetting('server_manager_hosted_manage_url',
					trim($input['manage_url'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_smtp2go_referral_url',
					trim($input['smtp2go_referral_url'] ?? ''));
				ProvisioningSetup::writeSetting('server_manager_storage_referral_url',
					trim($input['storage_referral_url'] ?? ''));
				// Blank means "leave the stored credential alone" — these fields
				// never show a value, so blank cannot mean "erase it" without
				// erasing it every time the rest of the card is saved.
				foreach (array(
					'operator_cloud_token'   => 'server_manager_operator_cloud_token',
					'smtp2go_api_key'        => 'server_manager_smtp2go_api_key',
					'smtp2go_webhook_secret' => 'server_manager_smtp2go_webhook_secret',
				) as $field => $setting) {
					$value = trim($input[$field] ?? '');
					if ($value !== '') {
						ProvisioningSetup::writeSecret($setting, $value);
					}
				}
				$message = 'Hosted tier settings saved.';
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
