<?php
/**
 * admin_provisioning_setup_logic - Guided activation for the hosting
 * provisioning pipeline.
 *
 * POST actions delegate to ProvisioningSetup and redirect back with a
 * session message; GET renders the live status of every checklist item.
 *
 * @version 1.9 - saving the hosted card also keeps the token's account company (CloudAccounts)
 * @version 1.8 - the test-account cleanup card: its task, last run, scopes, and a preview on request
 *   (test_cloud_account_and_prod_management WP3)
 * @version 1.7 - saving the hosted card records the operator token's scopes and names any it lacks
 * @version 1.6 - saving the hosted card reads the operator token's account name (server_manager_operator_cloud_account)
 * @version 1.5 - credentials go through FormWriterV2Base::process_secretinput(); the promotion code's
 *   remove box is gone (Reset and save blank removes it)
 * @version 1.4 - the master-key field arrives as hosted_smtp2go_master_key (the view's field name; smtp2go_api_key is core's)
 * @version 1.3 - the hosted card saves the SMTP2GO sandbox-users switch
 * @version 1.2 - the registrar promotion code is saved (and cleared) with the registrar card
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

	// A stored credential is a locked field: not posted, it is kept; after
	// Reset, blank removes it and text replaces it.
	$save_secret = function (string $field, string $setting) use ($input) {
		list($what, $value) = FormWriterV2Base::process_secretinput($input, $field,
			trim(ProvisioningSetup::readSecret($setting)) !== '');
		if ($what === FormWriterV2Base::SECRET_CLEAR) {
			ProvisioningSetup::writeSecret($setting, '');
		} elseif ($what === FormWriterV2Base::SECRET_SET) {
			ProvisioningSetup::writeSecret($setting, $value);
		}
	};

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
				$save_secret('ncp_api_key', 'server_manager_namecheap_api_key');
				$save_secret('ncp_promotion_code', 'server_manager_namecheap_promotion_code');
				$message = 'Domain registrar settings saved.';
			} elseif ($action === 'save_hosted') {
				ProvisioningSetup::writeSetting('server_manager_smtp2go_sandbox_users',
					!empty($input['smtp2go_sandbox_users']) ? '1' : '');
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
				$save_secret('operator_cloud_token', 'server_manager_operator_cloud_token');
				// The account's own name, so pages offering it say which account it is.
				$account_name = '';
				$account_company = '';
				$operator_token = trim(ProvisioningSetup::readSecret('server_manager_operator_cloud_token'));
				if ($operator_token !== '') {
					try {
						$driver = new LinodeComputeDriver($operator_token);
						$account_name = mb_substr($driver->accountName(), 0, 255);
						// Which dashboard tab the boxes this plane creates belong to (CloudAccounts).
						try { $account_company = mb_substr($driver->accountCompany(), 0, 255); } catch (Exception $e) { /* needs account:read_only; read as main */ }
					} catch (Exception $e) {
						$error = 'Hosted tier settings saved, but the operator token could not read its account\'s name: '
							. $e->getMessage();
					}
				}
				ProvisioningSetup::writeSetting('server_manager_operator_cloud_account', $account_name);
				ProvisioningSetup::writeSetting(CloudAccounts::COMPANY_SETTING, $account_company);
				// What the token may do, so a missing scope is named now and not
				// at the first job it is refused for.
				try {
					$missing = ProvisioningSetup::recordOperatorTokenScopes();
					if ($missing && !$error) {
						$lines = array();
						foreach ($missing as $scope => $purpose) {
							$lines[] = $scope . ' (' . $purpose . ')';
						}
						$error = 'Hosted tier settings saved, but the operator token lacks '
							. implode('; ', $lines) . '. Make a Linode token with these scopes added and enter it here.';
					}
				} catch (Exception $e) {
					$error = $error ?: 'Hosted tier settings saved, but the operator token\'s scopes could not be read: '
						. $e->getMessage();
				}
				$save_secret('hosted_smtp2go_master_key', 'server_manager_smtp2go_api_key');
				$save_secret('smtp2go_webhook_secret', 'server_manager_smtp2go_webhook_secret');
				$message = $error ? null : 'Hosted tier settings saved.';
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

	// The test-account cleanup's preview asks the provider, so only on request.
	$cleanup_preview = null;
	if (!empty($input['cleanup_preview'])) {
		$driver = TestCloudCleanup::driver();
		if ($driver === null) {
			$cleanup_preview = array('error' => 'No operator cloud token is set on this site.');
		} else {
			try {
				$cleanup_preview = TestCloudCleanup::plan($driver,
					MachineTransferWatch::node_addresses(array('MachineTransferWatch', 'resolve')),
					time(), TestCloudCleanup::max_age_hours());
			} catch (CloudComputeException $e) {
				$cleanup_preview = array('error' => 'The account could not be listed: ' . $e->getMessage());
			}
		}
	}

	return LogicResult::render(array(
		'status' => ProvisioningSetup::status(),
		'cleanup' => array(
			'task'           => TestCloudCleanup::taskRow(),
			'state'          => TestCloudCleanup::state(),
			'max_age_hours'  => TestCloudCleanup::max_age_hours(),
			'missing_scopes' => TestCloudCleanup::missing_scopes(),
			'preview'        => $cleanup_preview,
		),
	));
}
