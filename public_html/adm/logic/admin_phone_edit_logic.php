<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_phone_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$phn_phone_number_id = LibraryFunctions::fetch_variable('phn_phone_number_id', NULL, 0, '');

	$phone_number = NULL;
	if($phn_phone_number_id){
		$phone_number = new PhoneNumber($phn_phone_number_id, TRUE);
		$user_id = $phone_number->get('phn_usr_user_id');
	}
	else{
		$user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must pass a user id');
	}

	if($post_vars){

		$phone_number = PhoneNumber::CreateFromForm($post_vars, $user_id, $phone_number, FALSE);

		//NOW REDIRECT
		return LogicResult::redirect('/admin/admin_user?usr_user_id='. $user_id );
	}

	$page_vars = array(
		'phone_number' => $phone_number,
		'user_id' => $user_id,
		'phn_phone_number_id' => $phn_phone_number_id,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
