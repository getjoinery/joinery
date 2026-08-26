<?php
// IMPORTANT: Logic files MUST require PathHelper because they are not accessed
// through serve.php's front controller. They are directly included by view files,
// so they don't get automatic PathHelper loading.
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_user_logic(array $input): LogicResult {
	// Required includes (PathHelper is now available from the require above)
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	// Data class includes
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/log_form_errors_class.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
	// Orders, subscriptions and event registrations are rendered by plugin-owned
	// panels (AdminUserPanelRegistry) that load their own data — this page needs
	// no store/event class here and renders on a store-less, event-less install.
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/group_members_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));

	// Get singletons (NO require needed - these are always pre-loaded)
	$settings = Globalvars::get_instance();
	require_once(PathHelper::getComposerAutoloadPath());

	$session = SessionControl::get_instance();

	// Permission check
	$session->check_permission(5);
	$session->set_return();

	// Initialize page variables
	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session'] = $session;

	// Check if "show all" is enabled
	$show_all = isset($input['show_all']) && $input['show_all'] == '1';
	$list_limit = $show_all ? NULL : 10;

	// Get user
	$user = new User($input['usr_user_id'], TRUE);

	// Process actions
	$action = $input['action'] ?? $input['action'] ?? null;

	// Handle GET actions.
	// Intentional GET-action mutations — opt in to the GET-is-read-only tripwire.
	if(isset($input['action']) && $input['action'] == 'delete'){
		$user->assert_can_write($session);
		$user->soft_delete();
		return LogicResult::redirect('/admin/admin_users');
	}
	else if(isset($input['action']) && $input['action'] == 'undelete'){
		$user->assert_can_write($session);
		$user->undelete();
		return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
	}

	// Handle POST actions
	if(LibraryFunctions::isFormSubmission()){

		if($input['action'] == 'add_to_group'){
			//ADD THE USER TO A GROUP
			$group = new Group($input['grp_group_id'], TRUE);
			$groupmember = $group->add_member($user->key);
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}
		else if($input['action'] == 'remove_from_group'){
			$groupmember = new GroupMember($input['grm_group_member_id'], TRUE);
			$groupmember->remove();
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}

		// ---- Administrative second-factor reset (specs/admin_second_factor_management.md) ----
		// A superadmin's recovery lever for a user who lost a factor. Every one
		// of these is an action_button POST: the sanctioned tokenless
		// single-button form, gated by a fresh step-up rather than a token.
		else if(in_array($input['action'] ?? '', array('admin_remove_passkey', 'admin_disable_totp', 'admin_revoke_trusted_devices'), TRUE)){
			$gate = admin_user_second_factor_gate($session, $user);
			if ($gate) {
				return $gate;
			}
			$acting = new User($session->get_user_id(), TRUE);
			$back = '/admin/admin_user?usr_user_id=' . $user->key;

			if($input['action'] == 'admin_remove_passkey'){
				$credential_id = (int)($input['pkc_passkey_credential_id'] ?? 0);

				// The vault's unlocker floor vetoes a revocation that would
				// strand an encrypted vault, and cleans up the dead wrapping
				// afterwards. The floor is absolute even here.
				VaultUnlock::registerRevocationHooks();
				try {
					$service = new PasskeyService();
					$service->adminRevoke($credential_id, $user, $acting);
				}
				catch (PasskeyRevocationVetoException $e) {
					error_log('[ADMIN_2FA_RESET] action=admin_remove_passkey admin=' . (int)$acting->key
						. ' target=' . (int)$user->key . ' credential=' . $credential_id . ' result=vetoed');
					admin_user_second_factor_say($session,
						$e->getMessage() . ' This would permanently strand the user\'s encrypted data. There is no override.',
						FALSE);
					return LogicResult::redirect($back);
				}
				catch (Exception $e) {
					error_log('[ADMIN_2FA_RESET] action=admin_remove_passkey admin=' . (int)$acting->key
						. ' target=' . (int)$user->key . ' credential=' . $credential_id . ' result=error');
					admin_user_second_factor_say($session, $e->getMessage(), FALSE);
					return LogicResult::redirect($back);
				}

				// A credential event ends every unlock window this user holds
				// anywhere, and every trusted-device grant with it.
				VaultUnlock::lockAll($user->key);
				$user->rotate_second_factor_hmac_key();
				admin_user_second_factor_alert($user, 'A site administrator removed a passkey from your account.');
				admin_user_second_factor_say($session, 'Passkey removed.', TRUE);
				return LogicResult::redirect($back);
			}
			else if($input['action'] == 'admin_disable_totp'){
				if(!$user->has_totp_enabled()){
					admin_user_second_factor_say($session, 'This user does not have an authenticator app enabled.', FALSE);
					return LogicResult::redirect($back);
				}
				// No confirmation code is asked for - the user lost it, which is
				// the point. The acting admin's step-up stands in its place.
				$user->disable_totp();
				VaultUnlock::lockAll($user->key);
				error_log('[ADMIN_2FA_RESET] action=admin_disable_totp admin=' . (int)$acting->key
					. ' target=' . (int)$user->key . ' credential=- result=done');
				admin_user_second_factor_alert($user, 'A site administrator disabled two-factor authentication on your account.');
				admin_user_second_factor_say($session, 'Two-factor authentication disabled.', TRUE);
				return LogicResult::redirect($back);
			}
			else {
				// admin_revoke_trusted_devices - factors untouched, so no lockAll.
				$user->rotate_second_factor_hmac_key();
				error_log('[ADMIN_2FA_RESET] action=admin_revoke_trusted_devices admin=' . (int)$acting->key
					. ' target=' . (int)$user->key . ' credential=- result=done');
				admin_user_second_factor_alert($user, 'A site administrator signed out your trusted devices.');
				admin_user_second_factor_say($session, 'Trusted devices signed out.', TRUE);
				return LogicResult::redirect($back);
			}
		}

		// Plugin-contributed panels (orders, subscriptions, events) own their POST
		// actions — dispatch through the registry before falling through.
		require_once(PathHelper::getIncludePath('includes/AdminUserPanelRegistry.php'));
		$panel_result = AdminUserPanelRegistry::handlePost($user, $input);
		if ($panel_result) {
			return $panel_result;
		}
	}

	// Load phone numbers
	$phone_numbers = new MultiPhoneNumber(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$phone_numbers->load();
	$numphonerecords = $phone_numbers->count_all();

	// Load addresses
	$addresses = new MultiAddress(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$numaddressrecords = $addresses->count_all();
	$addresses->load();

	// Get database connection for custom queries
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	// Get total count of logins
	$sql_count = 'SELECT COUNT(*) as count FROM log_logins WHERE log_usr_user_id = ?';
	try{
		$q_count = $dblink->prepare($sql_count);
		$q_count->execute([$user->key]);
		$num_logins = $q_count->fetch(PDO::FETCH_OBJ)->count;
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	// Get logins with limit
	$sql = 'SELECT * FROM log_logins WHERE log_usr_user_id = ? ORDER BY log_login_time DESC';
	if (!$show_all) {
		$sql .= ' LIMIT 10';
	}

	try{
		$q = $dblink->prepare($sql);
		$count = $q->execute([$user->key]);
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}
	$logins = $q->fetchAll();

	$webDir = $settings->get_setting('webDir');

	// Build altlinks array
	$options = array();
	$options['altlinks'] = array();

	if(!$user->get('usr_delete_time')) {
		if($_SESSION['permission'] > 7){
			$options['altlinks']['Edit User'] = '/admin/admin_users_edit?usr_user_id='.$user->key;
			if(PluginHelper::isPluginActive('store') && $settings->get_setting('checkout_type')){
				$options['altlinks']['Payment Methods'] = '/plugins/store/admin/admin_user_payment_methods?usr_user_id='.$user->key;
			}
			if(!$user->get('usr_email_is_verified')){
				$options['altlinks']['Resend activation email'] = '/admin/admin_email_verify?usr_user_id='.$user->key;
			}
			$options['altlinks']['Send email to user'] = '/admin/admin_users_message?usr_user_id='.$user->key;

			$options['altlinks']['Change password'] = '/admin/admin_users_password_edit?usr_user_id='.$user->key;
			$options['altlinks']['Soft Delete'] = array('post' => '/admin/admin_user', 'hidden' => array('action' => 'delete', 'usr_user_id' => $user->key));

			if(!$user->get('usr_is_activated')) {
				$options['altlinks']['Activate User'] = '/admin/admin_activate?usr_user_id='.$user->key;
			}
			if ($_SESSION['permission'] == 10) {
				$options['altlinks']['Log in as user'] = '/admin/admin_user_login_as?usr_user_id='.$user->key;
			}
		}
	}
	else {
		$options['altlinks']['Undelete'] = '/admin/admin_users_undelete?usr_user_id='.$user->key;
	}
	if ($_SESSION['permission'] == 10) {
		$options['altlinks']['Permanent Delete'] = '/admin/admin_users_permanent_delete?usr_user_id='.$user->key;
	}

	// Build show all URL for card footers
	$show_all_url = !$show_all ? '/admin/admin_user?usr_user_id=' . $user->key . '&show_all=1' : null;

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $entry) {
			$dropdown_button .= AdminPage::renderActionEntry($label, $entry, 'dropdown-item');
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Get mailing list subscriptions
	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant($search_criteria);
	$user_lists->load();
	foreach ($user_lists as $user_list){
		$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
		$user_subscribed_list[] = $mailing_list->get('mlt_name');
	}

	// Get tier info
	$user_tier = SubscriptionTier::GetUserTier($user->key);

	// Get tier change history
	$tier_changes = new MultiChangeTracking([
		'cht_entity_type' => 'subscription_tier',
		'cht_usr_user_id' => $user->key
	], ['cht_change_time' => 'DESC'], $list_limit);

	// Get groups count
	$groups = Group::get_groups_for_member($user->key, 'user', false, 'objects');
	$num_groups = count($groups);

	// Count received emails
	$received_emails_count = new MultiEmailRecipient(
		array('user_id' => $user->key, 'sent' => TRUE),
		NULL,
		$list_limit,
		0);
	$num_received_emails = $received_emails_count->count_all();

	// Count sent emails
	$sent_emails_count = new MultiEmail(
		array('user_id' => $user->key),
		NULL,
		$list_limit,
		0);
	$num_sent_emails = $sent_emails_count->count_all();

	// ---- Security posture card (superadmin only) ----
	// specs/admin_second_factor_management.md § step 5. Left unset for anyone
	// below permission 10, and the view renders nothing.
	if (($_SESSION['permission'] ?? 0) == 10) {
		$page_vars['security'] = admin_user_security_facts($user, $session);
	}

	// Prepare all data for view
	$page_vars['user'] = $user;
	$page_vars['show_all'] = $show_all;
	$page_vars['list_limit'] = $list_limit;
	$page_vars['phone_numbers'] = $phone_numbers;
	$page_vars['numphonerecords'] = $numphonerecords;
	$page_vars['addresses'] = $addresses;
	$page_vars['numaddressrecords'] = $numaddressrecords;
	$page_vars['logins'] = $logins;
	$page_vars['num_logins'] = $num_logins;
	$page_vars['dropdown_button'] = $dropdown_button;
	$page_vars['show_all_url'] = $show_all_url;
	$page_vars['user_subscribed_list'] = $user_subscribed_list;
	$page_vars['user_tier'] = $user_tier;
	$page_vars['tier_changes'] = $tier_changes;
	$page_vars['groups'] = $groups;
	$page_vars['num_groups'] = $num_groups;
	$page_vars['num_received_emails'] = $num_received_emails;
	$page_vars['num_sent_emails'] = $num_sent_emails;

	// Return data for rendering
	return LogicResult::render($page_vars);
}

/**
 * Everything the Security card renders: the account's enrolled factors and the
 * posture facts that decide how loudly a removal must be confirmed
 * (specs/admin_second_factor_management.md § step 5).
 */
function admin_user_security_facts($user, $session) {
	$facts = array(
		'totp_enabled'          => $user->has_totp_enabled(),
		'totp_enabled_time'     => $user->get_local('usr_totp_enabled_time'),
		'backup_code_count'     => 0,
		'passkeys'              => array(),
		'is_self'               => (int)$session->get_user_id() === (int)$user->key,
		'fortress'              => false,
		'vault_count'           => 0,
		'unused_recovery_codes' => 0,
	);

	$codes = json_decode((string)($user->get('usr_totp_backup_codes') ?? '[]'), true);
	$facts['backup_code_count'] = is_array($codes) ? count($codes) : 0;

	$service = new PasskeyService();
	foreach ($service->listCredentials($user) as $passkey) {
		$facts['passkeys'][] = array(
			'id'               => (int)$passkey->key,
			'label'            => (string)$passkey->get('pkc_label'),
			'created'          => $passkey->get_local('pkc_created_time', 'M j, Y'),
			'last_used'        => $passkey->get_local('pkc_last_used_time', 'M j, Y'),
			'vault_capability' => $passkey->vault_capability(),
		);
	}

	// The mailbox plugin owns the Fortress level and may be inactive - same
	// availability guard SessionControl::must_enroll_2fa_for_fortress() uses.
	$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
	if (is_file($domain_class)) {
		require_once($domain_class);
		if (class_exists('InboundEmailDomain')) {
			try {
				$facts['fortress'] =
					InboundEmailDomain::maxSecurityLevelForUser((int)$user->key) === 'fortress';
			} catch (\Throwable $e) {
				$facts['fortress'] = false;
			}
		}
	}

	// Vault posture, counted the way the unlocker floor counts it: unused
	// recovery wrappings across every scope's vault the user holds.
	try {
		$vaults = new MultiUserEncryptionVault(array('user_id' => (int)$user->key));
		$facts['vault_count'] = $vaults->count();
		foreach ($vaults as $vault) {
			$wrappings = new MultiUserEncryptionWrapping(array('vault_id' => (int)$vault->key));
			foreach ($wrappings as $wrapping) {
				if ($wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_RECOVERY
						&& !$wrapping->get('uew_is_used')) {
					$facts['unused_recovery_codes']++;
				}
			}
		}
	} catch (\Throwable $e) {
		$facts['vault_count'] = 0;
		$facts['unused_recovery_codes'] = 0;
	}

	return $facts;
}

/**
 * The shared guard on every administrative second-factor action
 * (specs/admin_second_factor_management.md § step 4). Returns a LogicResult to
 * bubble up when the action must not proceed, or NULL to continue.
 *
 * The own-factor refusal is not redundant with the step-up:
 * require_recent_second_factor() is a deliberate no-op for an account with no
 * second factor, so without it an admin who enrolled nothing could strip
 * everyone else's factors with a stolen session cookie alone.
 */
function admin_user_second_factor_gate($session, $user) {
	$session->check_permission(10);

	$back = '/admin/admin_user?usr_user_id=' . $user->key;

	$acting = new User($session->get_user_id(), TRUE);
	if (!$session->user_has_second_factor($acting)) {
		admin_user_second_factor_say($session,
			'Enroll a second factor on your own account before resetting anyone else\'s.', FALSE);
		return LogicResult::redirect($back);
	}

	$stepup = $session->require_recent_second_factor($back);
	if ($stepup) {
		return $stepup;
	}

	if ($user->get('usr_delete_time')) {
		admin_user_second_factor_say($session,
			'This user is deleted. Undelete the account before changing its sign-in factors.', FALSE);
		return LogicResult::redirect($back);
	}

	return NULL;
}

/** Save a success/error message scoped to the admin user page. */
function admin_user_second_factor_say($session, $message, $ok) {
	$session->save_message(new DisplayMessage(
		$message,
		$ok ? 'Success' : 'Error',
		'/\/admin\/admin_user/',
		$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
}

/**
 * Tell the account holder what was done to their sign-in factors. Sent
 * unconditionally and best-effort: in the compromised-admin scenario this
 * email is the victim's only signal, so a delivery failure is logged and never
 * blocks the action.
 */
function admin_user_second_factor_alert($user, $what_happened) {
	try {
		$to = trim((string)$user->get('usr_email'));
		if ($to === '') {
			return;
		}
		$settings = Globalvars::get_instance();
		EmailSender::quickSend(
			$to,
			trim((string)$settings->get_setting('site_name') . ' security alert'),
			$what_happened . ' If you did not request this, contact us and change your password immediately.'
		);
	}
	catch (\Throwable $e) {
		error_log('admin_user_second_factor_alert: alert email failed for user ' . $user->key . ': ' . $e->getMessage());
	}
}
?>
