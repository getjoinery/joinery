<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function account_edit_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_numbers_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
	
	$page_vars = array();
	
	$page_vars['settings'] = Globalvars::get_instance();

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);	

	// Photo actions are buttons (POST): a link is a GET any site can make the
	// browser follow.
	if (LibraryFunctions::isFormSubmission() && ($input['action'] ?? '') == 'set_primary_photo') {
		$user = new User($session->get_user_id(), TRUE);
		$user->set_primary_photo((int)$input['photo_id']);

		$msgtxt = 'Your profile picture has been updated.';
		$message = new DisplayMessage($msgtxt, 'Photo updated', '/\/profile\/account_edit.*/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
		$session->save_message($message);
		return LogicResult::redirect('/profile/account_edit');
	}

	if (LibraryFunctions::isFormSubmission() && ($input['action'] ?? '') == 'clear_primary_photo') {
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
			// A name keeps every character a person typed (accents, spaces,
			// any script); only control characters and tags are taken out.
			$user->set('usr_first_name', trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$input['usr_first_name']))));
			$user->set('usr_last_name', trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$input['usr_last_name']))));
			$user->set('usr_timezone', preg_replace("/[^a-zA-Z\/_-]/", "", $input['usr_timezone']));
			// The form shows the nickname only when the site displays one.
			if ($page_vars['settings']->get_setting('nickname_display_as') && array_key_exists('usr_nickname', $input)) {
				$user->set('usr_nickname', mb_substr(trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$input['usr_nickname']))), 0, 32));
			}
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
		

		// The sign-in identifiers. A changed username, a removed email and a new
		// email are each a sensitive action (specs/mailbox_security_levels.md
		// § 5.5): re-confirm the second factor first. A new email takes effect
		// only when the link mailed to it is used (email_change).
		$new_email = strtolower(trim((string)($input['usr_email_new'] ?? '')));
		$current_email = strtolower((string)$user->get('usr_email'));
		$new_username = array_key_exists('usr_username', $input) ? trim((string)$input['usr_username']) : null;
		$username_changes = $new_username !== null && strtolower($new_username) !== strtolower((string)$user->get('usr_username'));
		$email_removed = isset($input['usr_email_new']) && $new_email === '' && $current_email !== '';
		$email_changes = $new_email !== '' && $new_email !== $current_email;

		if ($username_changes || $email_removed || $email_changes) {
			$stepup = $session->require_recent_second_factor('/profile/account_edit');
			if ($stepup !== null) {
				return $stepup;
			}
		}
		$identifier_error = null;
		if ($username_changes || $email_removed) {
			if ($username_changes) {
				$user->set('usr_username', $new_username);
			}
			if ($email_removed) {
				$user->set('usr_email', NULL);
			}
			try {
				$user->save();
			} catch (DisplayableUserException $e) {
				$identifier_error = $e->getMessage();
				$user = new User($user->key, TRUE);
			}
		}

		if ($identifier_error !== null) {
			$message = new DisplayMessage($identifier_error, 'Not saved', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
			$session->save_message($message);
		}
		else if($email_changes) {

			// Population-2 precondition (§ 7): making one of the user's OWN hosted
			// mailboxes the login email would send every future reset link into the
			// very inbox a locked-out user cannot reach. So it requires holding at
			// least one non-email reset path first: a passkey, TOTP, or a verified
			// external recovery address (reset links also land there). State the
			// locked-out floor now, not during the crisis.
			$new_email_addr = $new_email;
			$at = strrpos($new_email_addr, '@');
			$new_domain = $at !== false ? strtolower(substr($new_email_addr, $at + 1)) : '';
			$is_user_hosted = false;
			$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php');
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
			else if (User::GetByEmail($new_email)) {
				$msgtxt = 'An account has already been registered with the email address '. htmlspecialchars($new_email) .'.';
				$message = new DisplayMessage($msgtxt, 'Account already registered', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
				$session->save_message($message);

			}
			else {
				Activation::email_change_send($user->key, $new_email);

				$msgtxt = 'To complete your email change, please click the link we sent to '. htmlspecialchars($new_email) .'.';
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
	require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));
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
			'maxlength' => 32
		]);
	}

	// The sign-in identifiers. A change asks for your second factor again; a new
	// email takes effect when you use the link mailed to it.
	$formwriter->textinput('usr_email_new', 'Email', [
		'value'     => $user ? (string)$user->get('usr_email') : '',
		'maxlength' => 64,
		'helptext'  => 'Changing it sends a confirmation link to the new address. You may leave it blank if you have a username.',
	]);
	$formwriter->textinput('usr_username', 'Username', [
		'maxlength'  => 32,
		'validation' => ['pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/'],
		'helptext'   => 'Optional. Sign in with it instead of your email. 3 to 32 letters, digits, dots, dashes or underscores.',
	]);

	$formwriter->dropinput('usr_timezone', 'Your Time Zone', [
		'options' => Address::get_timezone_drop_array()
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
}

function account_edit_logic_descriptor(): array {
	return [
		'auth' => ['min_user_permission' => 0],
		'description'      => 'Update the current user\'s profile fields.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'usr_first_name' => ['type' => 'string', 'required' => true, 'label' => 'First name'],
			'usr_last_name' => ['type' => 'string', 'required' => true, 'label' => 'Last name'],
			'usr_timezone' => ['type' => 'string', 'required' => true, 'label' => 'Timezone'],
			'usr_email_new' => ['type' => 'string', 'required' => false, 'label' => 'Email address (blank removes it, for an account with a username)'],
			'usr_username' => ['type' => 'string', 'required' => false, 'label' => 'Username'],
		],
	];
}
?>
