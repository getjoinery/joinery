<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

$session = SessionControl::get_instance();
$session->check_permission(9);

$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL,1,'user id');

$user = new User($usr_user_id, TRUE);

$act_code = Activation::getTempCode($user->key, '30 day', 2, NULL, NULL);

if (!$activated_user = Activation::ActivateUser($act_code)) {
	$errorhandler = new ErrorHandler();
	$errorhandler->handle_general_error('unable to activate user');	
}

LibraryFunctions::Redirect('/admin/admin_user?usr_user_id='.$user->key);

?>
