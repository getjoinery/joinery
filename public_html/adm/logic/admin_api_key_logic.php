<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

function admin_api_key_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$api_key = new ApiKey($get_vars['apk_api_key_id'], TRUE);

	if($get_vars['action'] == 'soft_delete'){
		$api_key->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$api_key->soft_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_api_keys");
	}
	if($get_vars['action'] == 'undelete'){
		$api_key->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$api_key->undelete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_api_keys");
	}
	if($get_vars['action'] == 'permanent_delete'){
		$api_key->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$api_key->permanent_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_api_keys");
	}

	$owner = new User($api_key->get('apk_usr_user_id'), TRUE);
	$now = LibraryFunctions::get_current_time_obj('UTC');

	$page_vars = array(
		'session' => $session,
		'api_key' => $api_key,
		'owner' => $owner,
		'now' => $now,
	);

	return LogicResult::render($page_vars);
}
