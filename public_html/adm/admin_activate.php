<?php

require_once(PathHelper::getIncludePath('includes/Activation.php'));

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(9);

$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL,1,'user id');

$user = new User($usr_user_id, TRUE);

$act_code = Activation::getTempCode($user->key, '30 day', 2, NULL, NULL);

if (!$activated_user = Activation::ActivateUser($act_code)) {
	require_once(__DIR__ . '/../includes/Exceptions/SystemException.php');
	throw new SystemException('unable to activate user');
}

LibraryFunctions::Redirect('/admin/admin_user?usr_user_id='.$user->key);

?>
