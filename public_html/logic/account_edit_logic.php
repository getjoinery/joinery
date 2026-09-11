<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function account_edit_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
	
	$page_vars = array();
	
	$page_vars['settings'] = Globalvars::get_instance();

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);	

	// Photo management actions — may be triggered via GET links; these are
	// intentional mutations, so opt in to the GET-is-read-only tripwire.
	if (isset($input['action']) && $input['action'] == 'set_primary_photo') {
		$user = new User($session->get_user_id(), TRUE);
		$user->set_primary_photo((int)$input['photo_id']);

		$msgtxt = 'Your profile picture has been updated.';
		$message = new DisplayMessage($msgtxt, 'Photo updated', '/\/profile\/account_edit.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/account_edit');
	}

	if (isset($input['action']) && $input['action'] == 'clear_primary_photo') {
		$user = new User($session->get_user_id(), TRUE);
		$user->clear_primary_photo();

		$msgtxt = 'Your profile picture has been removed.';
		$message = new DisplayMessage($msgtxt, 'Photo removed', '/\/profile\/account_edit.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/account_edit');
	}

	// Only run the account-save handler on an actual form POST (the account form is
	// POST). Guarding on `if(!empty($input))` ran the save on any GET carrying a
	// param, blanking fields set from undefined $input keys. See isFormSubmission().
	if (LibraryFunctions::isFormSubmission()) {

		//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
		if($session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
			$user->set('usr_first_name', preg_replace("/[^a-zA-Z'-]/", "", $input['usr_first_name']));
			$user->set('usr_last_name', preg_replace("/[^a-zA-Z'-]/", "", $input['usr_last_name']));
			$user->set('usr_timezone', preg_replace("/[^a-zA-Z\/_-]/", "", $input['usr_timezone']));
			$user->save();
		}
		else if(!$user = User::GetByEmail($input['usr_email'])){
			$data = array(
				'usr_first_name' => $input['usr_first_name'],
				'usr_last_name' => $input['usr_last_name'],
				'usr_email' => $input['usr_email'],
				'usr_nickname' => $input['usr_nickname'],
				'usr_timezone' => $input['usr_timezone'],
				'password' => $input['usr_password'],
				'send_emails' => false
			);
			$user = User::CreateNew($data);	
		}

		$session->set_timezone($user->get('usr_timezone'));
		

		if(isset($input['usr_email_new']) && $input['usr_email_new'] != $user->get('usr_email')) {

			// Changing the account (login) email is a sensitive action
			// (specs/mailbox_security_levels.md § 5.5): re-confirm the second factor.
			$stepup = $session->require_recent_second_factor('/profile/account_edit');
			if ($stepup !== null) {
				return $stepup;
			}

			// Population-2 precondition (§ 7): making one of the user's OWN hosted
			// mailboxes the login email would send every future reset link into the
			// very inbox a locked-out user cannot reach. So it requires holding at
			// least one non-email reset path first: a passkey, TOTP, or a verified
			// external recovery address (reset links also land there). State the
			// locked-out floor now, not during the crisis.
			$new_email_addr = trim($input['usr_email_new']);
			$at = strrpos($new_email_addr, '@');
			$new_domain = $at !== false ? strtolower(substr($new_email_addr, $at + 1)) : '';
			$is_user_hosted = false;
			$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
			if ($new_domain !== '' && is_file($domain_class)) {
				require_once($domain_class);
				if (class_exists('InboundEmailDomain')) {
					// Owned OR grant-reached — a grant-reached mailbox is just as
					// circular (the reset link still lands where the user is locked out).
					try {
						$is_user_hosted = in_array($new_domain,
							InboundEmailDomain::userHostedDomainNames((int)$user->key), true);
					} catch (\Throwable $e) {
						// Plugin files ship everywhere but its tables exist only where
						// it has been activated; a missing table means no hosted domains.
						$is_user_hosted = false;
					}
				}
			}
			$has_reset_path = $session->user_has_second_factor($user)
				|| $user->has_verified_recovery_email();
			$precondition_ok = !($is_user_hosted && !$has_reset_path);

			if (!$precondition_ok) {
				$msgtxt = 'Before using a hosted address as your login email, set up a passkey, an authenticator app, or a verified recovery address. '
					. 'Otherwise a forgotten password would send the reset link into the very inbox you would be locked out of.';
				$message = new DisplayMessage($msgtxt, 'Set up a recovery method first', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);
			}
			else if (User::GetByEmail(trim($input['usr_email_new']))) {
				$msgtxt = 'An account has already been registered with the email address '. htmlspecialchars($input['usr_email_new']) .'.';
				$message = new DisplayMessage($msgtxt, 'Account already registered', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);

			}
			else {
				Activation::email_change_send($user->key, trim($input['usr_email_new']));

				$msgtxt = 'To complete your email change, please click the activation link that we sent you at '. htmlspecialchars($input['usr_email_new']) .'.';
				$message = new DisplayMessage($msgtxt, 'Activate your email', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);	
			}
		} 
		else {
			$msgtxt = 'Your account has been updated.';
			$message = new DisplayMessage($msgtxt, 'Account updated', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);		
		}
		
	} 


	
	$page_vars['user'] = $user;
	$page_vars['user_photos'] = $user->get_photos();
	return LogicResult::render($page_vars);
}

/**
 * Form builder — single source for the web account form and the JSON form
 * definition (GET /api/v1/form/account_edit).
 */
function account_edit_logic_form($formwriter, $user = null, $input = []) {
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	$settings = Globalvars::get_instance();

	if ($user) {
		$formwriter->set_model($user);
	}

	$formwriter->textinput('usr_first_name', 'First Name', [
		'maxlength' => 255
	]);
	$formwriter->textinput('usr_last_name', 'Last Name', [
		'maxlength' => 255
	]);

	$nickname_display = $settings->get_setting('nickname_display_as');
	if ($nickname_display) {
		$formwriter->textinput('usr_nickname', $nickname_display, [
			'maxlength' => 255
		]);
	}

	$formwriter->dropinput('usr_timezone', 'Your Time Zone', [
		'options' => Address::get_timezone_drop_array()
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
}

function account_edit_logic_descriptor(): array {
	return [
		'description'      => 'Update the current user\'s profile fields.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'usr_first_name' => ['type' => 'string', 'required' => true, 'label' => 'First name'],
			'usr_last_name' => ['type' => 'string', 'required' => true, 'label' => 'Last name'],
			'usr_timezone' => ['type' => 'string', 'required' => true, 'label' => 'Timezone'],
			'usr_email_new' => ['type' => 'email', 'required' => false, 'label' => 'New email address'],
		],
	];
}
?>
