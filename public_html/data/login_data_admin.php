<?php
require_once( __DIR__ . '/../includes/Globalvars.php');	
require_once( __DIR__ . '/../data/users_class.php');

$session = SessionControl::get_instance();
$session->check_permission(9);

$_SESSION['test_mode'] = LibraryFunctions::fetch_variable('test_mode', 0, 0, '');
$_SESSION['send_emails'] = LibraryFunctions::fetch_variable('send_emails', 0, 0, '');
$return_url = LibraryFunctions::fetch_variable('return_url', NULL, 0, '');
$redirect = LibraryFunctions::fetch_variable('redirect', NULL, 0, '');
$login_original = LibraryFunctions::fetch_variable('login_original', NULL, 0, '');


if($login_original) {
	$session->store_session_variables(new User($session->get_initial_user_id(), TRUE));
} else {
	$usr_user_id = trim(LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'The new user id is required.'));
	$session->store_session_variables(new User($usr_user_id, TRUE), 'admin');
}

//NOW REDIRECT
if(!$return_url) {
	switch($redirect) {
		case 'myaccount':
			$return_url = '/profile';
			break;
		default:
			if ($session->get_return()) {
				$return_url = $session->get_return();
			} else {
				$return_url = '/';
			}
	}
}

LibraryFunctions::Redirect($return_url);

?>
