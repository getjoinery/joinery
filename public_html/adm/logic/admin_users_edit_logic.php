<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_users_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('/includes/Activation.php'));
	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('/data/mailing_list_registrants_class.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Load or create user
	// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
	if (isset($post_vars['edit_primary_key_value'])) {
		$user = new User($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['usr_user_id'])) {
		$user = new User($get_vars['usr_user_id'], TRUE);
	} else {
		return LogicResult::error('User ID is required for editing');
	}

	// Load mailing lists (needed for both display and processing)
	$search_criteria = array('deleted' => false, 'active' => true);
	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array('name'=>'ASC'));
	$mailing_lists->load();

	// Process POST actions
	if ($post_vars) {

		$user->set('usr_calendly_uri', trim($post_vars['usr_calendly_uri']));
		$user->set('usr_first_name', trim($post_vars['usr_first_name']));
		$user->set('usr_last_name', trim($post_vars['usr_last_name']));
		$user->set('usr_password_recovery_disabled', (bool)$post_vars['usr_password_recovery_disabled']);
		$user->set('usr_timezone', $post_vars['usr_timezone']);
		$user->set('usr_nickname', trim($post_vars['usr_nickname']));

		if($post_vars['usr_organization_name']){
			$user->set('usr_organization_name', trim($post_vars['usr_organization_name']));
		}

		if(isset($post_vars['usr_email_new']) && $post_vars['usr_email_new'] != $user->get('usr_email')) {

			if (User::GetByEmail(trim($post_vars['usr_email_new']))) {
				return LogicResult::error('An account has already been registered with the email address '. htmlspecialchars($post_vars['usr_email_new']) .'.');
			} else {
				if($_SESSION['permission'] == 0){
					Activation::email_change_send($user->key, trim($post_vars['usr_email_new']));
				}
				else{
					$user->set('usr_email', trim($post_vars['usr_email_new']));
				}
			}
		}

		if($_SESSION['permission'] == 10){
			$user->set('usr_permission', $post_vars['usr_permission']);
		}

		// Handle allowed IPs - convert newline-separated list to JSON array
		if (isset($post_vars['usr_allowed_ips'])) {
			$allowed_ips_input = trim($post_vars['usr_allowed_ips']);
			if (empty($allowed_ips_input)) {
				$user->set('usr_allowed_ips', null);
			} else {
				// Split by newlines, commas, or spaces, then clean up
				$ips = preg_split('/[\r\n,\s]+/', $allowed_ips_input, -1, PREG_SPLIT_NO_EMPTY);
				$ips = array_map('trim', $ips);
				$ips = array_filter($ips); // Remove empty entries
				$ips = array_values($ips); // Re-index array
				$user->set('usr_allowed_ips', json_encode($ips));
			}
		}

		$user->prepare();
		$user->save();

		//HANDLE THE USERS'S MAILING LISTS
		$messages = $user->add_user_to_mailing_lists($post_vars['new_list_subscribes']);

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
