<?php
/**
 * admin_provisioning_setup_logic - Guided activation for the hosting
 * provisioning pipeline.
 *
 * POST actions delegate to ProvisioningSetup (and, for the Hosting tab's cloud
 * accounts, OperatorCloudAccount) and redirect back to the tab they came from
 * with a session message; GET renders the live status of every checklist item.
 *
 * The page is tabs (TABS): Setup, Hosting, Hosted tier, Shop, Domains, Cleanup.
 *
 * @version 2.1 - an account added, or a key replaced, is said as done, and a scope its token lacks (or a check
 *   that failed) beside it as a warning, not the whole as an error (B25)
 * @version 2.0 - tabs; the Hosting tab's cloud accounts: add by API key or Connect with Linode, check, replace a key,
 *   make default, remove (cloud_accounts_list WP4); the hosted card no longer takes a token; the cleanup preview
 *   covers every test account
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

/** The page's tabs: key => label. */
const SMPS_TABS = array(
	'setup'   => 'Setup',
	'hosting' => 'Hosting',
	'hosted'  => 'Hosted tier',
	'shop'    => 'Shop',
	'domains' => 'Domains',
	'cleanup' => 'Cleanup',
);

/** Which tab each action is on, so a save returns to it. */
const SMPS_ACTION_TABS = array(
	'setup_api' => 'setup', 'activate_tasks' => 'setup', 'save_email' => 'setup',
	'add_account_key' => 'hosting', 'connect_account' => 'hosting', 'reconnect_account' => 'hosting',
	'check_account' => 'hosting', 'replace_account_key' => 'hosting', 'default_account' => 'hosting',
	'remove_account' => 'hosting', 'save_cloud' => 'hosting',
	'save_hosted' => 'hosted',
	'create_question' => 'shop',
	'save_domains' => 'domains',
);

/** The cloud-account actions: each takes the page's CSRF token. */
const SMPS_ACCOUNT_ACTIONS = array('add_account_key', 'connect_account', 'reconnect_account', 'check_account',
	'replace_account_key', 'default_account', 'remove_account');

function admin_provisioning_setup_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_url = '/admin/server_manager/provisioning_setup';
	$page_regex = '/\/admin\/server_manager\/provisioning_setup/';

	$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($input['action'] ?? '') : '';
	$tab = (string)($input['tab'] ?? '');
	if (!isset(SMPS_TABS[$tab])) {
		$tab = 'setup';
	}

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
		// Done, with something that still needs a person: said beside the
		// success, never as an error.
		$warning = null;
		$error = null;
		$return_tab = SMPS_ACTION_TABS[$action] ?? $tab;
		$account_of = function () use ($input): OperatorCloudAccount {
			$account = new OperatorCloudAccount((int)($input['oca_id'] ?? 0), TRUE);
			if (!$account->key || $account->removed()) {
				throw new OperatorCloudAccountException('That cloud account is not held here.');
			}
			return $account;
		};
		try {
			if (in_array($action, SMPS_ACCOUNT_ACTIONS, true) && !SmAdminCsrf::valid()) {
				throw new OperatorCloudAccountException('The form expired. Reload the page and try again.');
			}
			if ($action === 'add_account_key') {
				$account = OperatorCloudAccount::add_key((string)($input['cloud_api_token'] ?? ''), (int)$session->get_user_id());
				$message = 'Added ' . $account->label() . '.';
				if (OperatorCloudAccount::default_account() === null && $account->default_refusal() === '') {
					$account->make_default();
					$message .= ' It is the default: hosted orders go to it.';
				}
				$missing = $account->missing_scopes();
				if ($missing) {
					$warning = 'Its token lacks ' . implode(', ', array_keys($missing))
						. '; what needs them will be refused. Replace the key with one that has them.';
				}
			} elseif ($action === 'connect_account' || $action === 'reconnect_account') {
				$account_id = 0;
				if ($action === 'reconnect_account') {
					$account = $account_of();
					if ((string)$account->get('oca_method') !== OperatorCloudAccount::METHOD_CONNECT) {
						throw new OperatorCloudAccountException('That account was added by API key; replace its key instead.');
					}
					$account_id = (int)$account->key;
				}
				$consent_url = (new OAuth2Client())->beginConsent('linode', OperatorCloudAccount::CONNECT_SCOPES,
					'operator_cloud', array('user_id' => (int)$session->get_user_id(), 'account_id' => $account_id),
					$page_url . '?tab=hosting');
				return LogicResult::redirect($consent_url);
			} elseif ($action === 'check_account') {
				$account = $account_of();
				$why = $account->check();
				if ($why !== '') {
					$error = $why;
				} else {
					$message = 'Checked ' . $account->label() . ' with the provider.';
				}
			} elseif ($action === 'replace_account_key') {
				$account = $account_of();
				if ((string)$account->get('oca_method') !== OperatorCloudAccount::METHOD_KEY) {
					throw new OperatorCloudAccountException('That account was added by a Linode sign-in; reconnect it instead.');
				}
				$token = trim((string)($input['cloud_api_token'] ?? ''));
				if ($token === '') {
					throw new OperatorCloudAccountException('Paste the new API token.');
				}
				// The same account, never another: every server that names this entry would follow it.
				try {
					$name = OperatorCloudAccount::driver_for($token)->accountName();
				} catch (CloudComputeException $e) {
					throw new OperatorCloudAccountException('The provider did not accept that token (' . $e->getMessage() . '). Nothing was changed.');
				}
				$was = trim((string)$account->get('oca_account_name'));
				if ($was !== '' && trim($name) !== $was) {
					throw new OperatorCloudAccountException('That token is for ' . $name . ', not ' . $was
						. '. A replacement key must be for the same account; add a different account as its own entry.');
				}
				$account->store_credential($token);
				$account->set('oca_status', OperatorCloudAccount::STATUS_ACTIVE);
				$account->save();
				$message = 'Replaced the key for ' . $account->label() . '.';
				$why = $account->check();
				if ($why !== '') {
					$warning = $why;
				}
			} elseif ($action === 'default_account') {
				$account = $account_of();
				$account->make_default();
				$message = $account->label() . ' is the default: hosted orders go to it.';
			} elseif ($action === 'remove_account') {
				$account = $account_of();
				$account->remove();
				$message = 'Removed ' . $account->label() . '. Servers in it are no longer reached from here; removing one asks you '
					. 'to confirm it is gone.';
			} elseif ($action === 'setup_api') {
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
				$save_secret('hosted_smtp2go_master_key', 'server_manager_smtp2go_api_key');
				$save_secret('smtp2go_webhook_secret', 'server_manager_smtp2go_webhook_secret');
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
		if ($warning) {
			$session->save_message(new DisplayMessage($warning, 'Warning', $page_regex,
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		if ($error) {
			$session->save_message(new DisplayMessage($error, 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect($page_url . '?tab=' . $return_tab);
	}

	// What came back from a Linode sign-in.
	if (!empty($input['connected'])) {
		$connected = new OperatorCloudAccount((int)$input['connected'], TRUE);
		if ($connected->key) {
			$why = $connected->unusable_reason();
			$session->save_message(new DisplayMessage($why === '' ? 'Connected ' . $connected->label()
				. ' until ' . $connected->get_local('oca_token_expires', 'g:i a T') . '.' : $why,
				$why === '' ? 'Success' : 'Error', $page_regex,
				$why === '' ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect($page_url . '?tab=hosting');
	}
	if (!empty($input['connect_error'])) {
		$why = array(
			'permission'    => 'Only a superadmin adds a cloud account here.',
			'gone'          => 'That cloud account was removed while you signed in. Nothing was saved.',
			'other_account' => 'You signed in to a different Linode account than the one being reconnected. Nothing was saved; '
				. 'connect it as its own entry instead.',
		)[(string)$input['connect_error']] ?? 'The Linode sign-in did not finish.';
		$session->save_message(new DisplayMessage($why, 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect($page_url . '?tab=hosting');
	}

	// The test-account cleanup's preview asks the provider, so only on request:
	// one plan per test account, each behind its own safety catch.
	$cleanup_preview = null;
	if ($tab === 'cleanup' && !empty($input['cleanup_preview'])) {
		$cleanup_preview = array();
		$nodes = MachineTransferWatch::node_addresses(array('MachineTransferWatch', 'resolve'));
		foreach (TestCloudCleanup::accounts() as $account) {
			try {
				$plan = TestCloudCleanup::plan($account->driver(), $nodes, time(), TestCloudCleanup::max_age_hours());
			} catch (CloudComputeException $e) {
				$plan = array('error' => 'The account could not be listed: ' . $e->getMessage());
			}
			$cleanup_preview[] = array('name' => $account->label()) + $plan;
		}
	}

	$test_accounts = array();
	foreach (TestCloudCleanup::accounts() as $account) {
		$test_accounts[] = array('name' => $account->label(), 'missing_scopes' => TestCloudCleanup::missing_scopes($account));
	}

	// The Hosting tab's lists: ours with what each can do, and customers' (read-only).
	$ours = array();
	foreach (OperatorCloudAccount::held() as $account) {
		$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT count(*) FROM cvp_customer_cloud_provisions
			WHERE cvp_oca_operator_cloud_account_id = ? AND cvp_delete_time IS NULL AND cvp_hosting_mode = 'operator'
			  AND COALESCE(cvp_instance_id, '') <> ''");
		$q->execute(array((int)$account->key));
		$used = (int)$q->fetchColumn();
		$ours[] = array(
			'account'        => $account,
			'problem'        => $account->unusable_reason(),
			'missing_scopes' => $account->missing_scopes(),
			'default_refusal' => $account->default_refusal(),
			'servers'        => $used,
		);
	}
	$customers = array();
	foreach (new MultiCustomerCloudAccount(array('deleted' => false), array('cca_customer_cloud_account_id' => 'ASC')) as $ca) {
		$ca_user = new User($ca->get('cca_usr_user_id'), TRUE);
		$customers[] = array(
			'name'      => trim((string)$ca->get('cca_account_name')),
			'provider'  => ucfirst((string)$ca->get('cca_provider')),
			'who'       => $ca_user->key ? trim($ca_user->get('usr_first_name') . ' ' . $ca_user->get('usr_last_name')) : 'user #' . $ca->get('cca_usr_user_id'),
			'user_id'   => (int)$ca->get('cca_usr_user_id'),
			'connected' => $ca->get_local(trim((string)$ca->get('cca_update_time')) !== '' ? 'cca_update_time' : 'cca_create_time', 'M j, Y g:i a T'),
			'state'     => $ca->get('cca_status') !== 'active' ? 'Needs reconnecting (' . $ca->get('cca_status') . ')'
				: (CustomerCloudAccount::grant_expired($ca) ? 'Expired' : 'Connected'),
		);
	}

	return LogicResult::render(array(
		'tab'    => $tab,
		'tabs'   => SMPS_TABS,
		'status' => ProvisioningSetup::status(),
		'accounts' => array(
			'ours'      => $ours,
			'customers' => $customers,
		),
		'cleanup' => array(
			'task'           => TestCloudCleanup::taskRow(),
			'state'          => TestCloudCleanup::state(),
			'max_age_hours'  => TestCloudCleanup::max_age_hours(),
			'accounts'       => $test_accounts,
			'preview'        => $cleanup_preview,
		),
	));
}
