<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_users_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('/includes/Activation.php'));
	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('/data/mailing_list_registrants_class.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();

	// Load or create user
	// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
	if (isset($input['edit_primary_key_value'])) {
		// Verify POST user ID matches GET parameter to prevent cross-user form submission
		if (isset($input['usr_user_id']) && (int)$input['edit_primary_key_value'] !== (int)$input['usr_user_id']) {
			return LogicResult::error('User ID mismatch.');
		}
		$user = new User($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['usr_user_id'])) {
		$user = new User($input['usr_user_id'], TRUE);
	} else {
		return LogicResult::error('User ID is required for editing');
	}

	// Only a user above this one's level may change them (10 excepted): the edit
	// page holds their email, allowed IPs and second factor.
	if ($user->key) {
		$user->assert_can_write($session);
	}

	// Load mailing lists (needed for both display and processing)
	$search_criteria = array('deleted' => false, 'active' => true);
	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array('name'=>'ASC'));
	$mailing_lists->load();

	// Process POST actions
	if (LibraryFunctions::isFormSubmission()) {

		// Admin 2FA reset — clears the user's TOTP state so they can re-enroll.
		// Logged via ChangeTracking so the action is auditable.
		if (isset($input['action']) && $input['action'] === 'reset_2fa') {
			require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));
			$previous_enabled_time = $user->get('usr_totp_enabled_time');
			$user->disable_totp();
			ChangeTracking::logChange(
				'user', $user->key, $user->key,
				'usr_totp_enabled_time',
				$previous_enabled_time, null,
				'admin_2fa_reset',
				'user', $session->get_user_id(),
				$session->get_user_id()
			);
			return LogicResult::redirect('/admin/admin_users_edit?usr_user_id=' . $user->key);
		}

		$user->set('usr_first_name', trim($input['usr_first_name']));
		$user->set('usr_last_name', trim($input['usr_last_name']));
		$user->set('usr_password_recovery_disabled', (bool)$input['usr_password_recovery_disabled']);
		$user->set('usr_timezone', $input['usr_timezone']);
		$user->set('usr_nickname', trim($input['usr_nickname']));

		if($input['usr_organization_name']){
			$user->set('usr_organization_name', trim($input['usr_organization_name']));
		}

		// The username: blank removes it (the save refuses an account left with
		// neither a username nor an email).
		if (array_key_exists('usr_username', $input)) {
			$user->set('usr_username', trim((string)$input['usr_username']));
		}

		$new_email = strtolower(trim((string)($input['usr_email_new'] ?? '')));
		$current_email = strtolower((string)$user->get('usr_email'));
		if (isset($input['usr_email_new']) && $new_email === '' && $current_email !== '') {
			// A blank email removes it, for an account that signs in by username.
			$user->set('usr_email', NULL);
		}
		else if ($new_email !== '' && $new_email !== $current_email) {

			if (User::GetByEmail($new_email)) {
				return LogicResult::error('An account has already been registered with the email address '. htmlspecialchars($new_email) .'.');
			} else {
				if(!Access::permits(Access::staffLevel(), $session)){
					Activation::email_change_send($user->key, $new_email);
				}
				else{
					$user->set('usr_email', $new_email);
				}
			}
		}

		if(Access::permits(10, $session) && array_key_exists('usr_permission', $input)){
			// Only a level permissions.json names (docs/permissions.md); left out, it stays.
			$level = (string)$input['usr_permission'];
			if (!preg_match('/^\d+$/', $level) || !array_key_exists((int)$level, Access::levels())) {
				return LogicResult::error('Permission level ' . htmlspecialchars($level) . ' is not a level this site has.');
			}
			$user->set('usr_permission', (int)$level);
		}

		// Handle allowed IPs - convert newline-separated list to JSON array
		if (isset($input['usr_allowed_ips'])) {
			$allowed_ips_input = trim($input['usr_allowed_ips']);
			if (empty($allowed_ips_input)) {
				$user->set('usr_allowed_ips', null);
			} else {
				// Split by newlines, commas, or spaces, then clean up
				$ips = preg_split('/[\r\n,\s]+/', $allowed_ips_input, -1, PREG_SPLIT_NO_EMPTY);
				$ips = array_map('trim', $ips);
				$ips = array_filter($ips); // Remove empty entries
				$ips = array_values($ips); // Re-index array
				// A typo here is a silent lockout at the next sign-in, so a bad
				// entry refuses the whole save and names itself.
				foreach ($ips as $entry) {
					if ($problem = IpAddress::entryProblem($entry)) {
						return LogicResult::error('Allowed Login IPs: ' . $problem . '. Nothing was saved.');
					}
				}
				$user->set('usr_allowed_ips', json_encode($ips));
			}
		}

		try {
			$user->prepare();
			$user->save();
		} catch (DisplayableUserException $e) {
			return LogicResult::error($e->getMessage() . ' Nothing was saved.');
		}

		//HANDLE THE USERS'S MAILING LISTS
		$messages = $user->add_user_to_mailing_lists($input['new_list_subscribes']);

		//NOW REDIRECT
		return LogicResult::redirect("/admin/admin_user?usr_user_id=$user->key");
	}

	// Load user subscribed lists for display
	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant($search_criteria);
	$user_lists->load();

	foreach ($user_lists as $user_list){
		$user_subscribed_list[] = $user_list->get('mlr_mlt_mailing_list_id');
	}

	// Return page variables for rendering
	return LogicResult::render(array(
		'user' => $user,
		'mailing_lists' => $mailing_lists,
		'user_subscribed_list' => $user_subscribed_list,
		'session' => $session,
		'settings' => $settings,
	));
}

?>
