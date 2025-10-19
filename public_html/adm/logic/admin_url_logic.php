<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/urls_class.php'));

function admin_url_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$url = new Url($get_vars['url_url_id'], TRUE);

	if($get_vars['action'] == 'soft_delete'){
		$url->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$url->soft_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_urls");
	}
	if($get_vars['action'] == 'undelete'){
		$url->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$url->undelete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_urls");
	}
	if($get_vars['action'] == 'permanent_delete'){
		$url->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$url->permanent_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_urls");
	}

	$page_vars = array(
		'session' => $session,
		'url' => $url,
	);

	return LogicResult::render($page_vars);
}
